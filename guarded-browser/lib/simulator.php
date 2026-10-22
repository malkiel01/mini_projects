<?php
/**
 * סימולטור הרשאות (אפיון, שלב 12 — ב): ההורה מזין אתר, אפליקציה ושעה — ומקבל את ההכרעה ואת הכלל שקבע אותה.
 *
 *   האתר מוכרע במנוע האמיתי (evaluate) על המדיניות השמורה, בשעה שנבחרה ובאזור הזמן של הילד — לא בהעתק של
 *   הלוגיקה. אין כאן שום כתיבה: לא ליומן, לא להתרעות, לא לזמן שנצבר.
 *   האפליקציה — לפי ברירת המחדל, הכלל עליה, היתר זמני ו"תמיד פתוח". שעות ומכסות לא חלות על אפליקציות (ראו
 *   docs/enforcement-audit.md), והתשובה אומרת זאת.
 */

declare(strict_types=1);

require_once __DIR__ . '/policy.php';
require_once __DIR__ . '/appcontrol.php';
require_once __DIR__ . '/apprequests.php';

/** מה בהגדרות ההורה קבע — לפי קוד ההכרעה. */
const SIM_WHERE = [
    'rule_allow' => '🌐 אתרים — כלל "מותר"', 'rule_deny' => '🌐 אתרים — כלל "חסום"',
    'category_allow' => '🔐 גישה — קטגוריה מותרת', 'category_deny' => '🔐 גישה — קטגוריה חסומה',
    'not_listed' => '🔐 גישה — ברירת המחדל "רק מה שאישרתי"', 'posture_allow' => '🔐 גישה — ברירת המחדל "הכול מותר"',
    'type_blocked' => '🔐 גישה — סוג תוכן חסום', 'ad_blocked' => '🔐 גישה — חסימת פרסומות',
    'outside_window' => '⏰ זמנים — שעות הגלישה', 'day_blocked' => '⏰ זמנים — ימים',
    'quota_spent' => '⏰ זמנים — מכסה יומית', 'session_over' => '⏰ זמנים — רצף מרבי',
    'suspended' => '📋 סיכום — הילד מושהה', 'pending' => 'החשבון ממתין לאישור', 'inactive' => 'החשבון אינו פעיל',
    'released' => 'המנוי הסתיים — אין הגבלות', 'bad_url' => 'הכתובת אינה תקינה',
];

/**
 * אתר. ‏$time — "HH:MM" (ריק — עכשיו); ‏$day — 0 (ראשון) עד 6, או -1 להיום; ‏$usedMin — דקות שכבר נוצלו היום
 * (-1 — מה שנוצל באמת).
 */
function simulateSite(array $child, string $url, string $time = '', int $day = -1, int $usedMin = -1): array {
    $cid = (int) $child['id'];
    $policy = policyFor($cid);
    $tz = (string) $policy['timezone'];
    $now = nowIn($tz);
    if ($day >= 0 && $day <= 6) $now = $now->modify((($day - (int) $now->format('w')) + 7) % 7 . ' days');
    if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m)) $now = $now->setTime((int) $m[1], (int) $m[2]);
    $used = $usedMin >= 0 ? $usedMin * 60 : usedTodaySeconds($cid, $tz);

    $url = trim($url);
    if ($url !== '' && !preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url)) $url = 'https://' . $url;
    $set = ruleSetFor($cid);
    // ‏account_status/expires — כמו שהמכשיר רואה (userByToken מצרף אותם); כאן מהשורות עצמן.
    $acc = one('SELECT status, expires_at FROM accounts WHERE id = ?', [(int) $child['account_id']]);
    $user = $child + ['account_status' => $acc['status'] ?? 'active', 'account_expires_at' => $acc['expires_at'] ?? ''];
    $d = evaluate($user, $policy, $set, $now, ['url' => $url, 'main_frame' => true, 'used_today' => $used]);

    $rule = '';
    if (in_array($d['code'], ['rule_allow', 'rule_deny'], true) && ($u = normalizeUrl($url))) {
        foreach ($set['rules'] as $r) {
            if (($r['enabled'] ?? 1) && ruleMatches($r, $u, true) && (($r['action'] === 'deny') === ($d['code'] === 'rule_deny'))) {
                $rule = $r['pattern'] . ($r['label'] ? " ({$r['label']})" : '') . ($r['expires_at'] ?? '' ? ' — זמני עד ' . $r['expires_at'] : '');
                break;
            }
        }
    }
    $where = SIM_WHERE[$d['code']] ?? (str_starts_with($d['code'], 'yt_') ? '▶️ יוטיוב' : $d['code']);
    return ['allow' => $d['allow'], 'code' => $d['code'], 'reason' => $d['reason'], 'where' => $where, 'rule' => $rule,
            'at' => $now->format('Y-m-d H:i'), 'day' => (int) $now->format('w'), 'used_min' => intdiv($used, 60)];
}

/** אפליקציה (שם חבילה). */
function simulateApp(array $child, string $pkg): array {
    $cid = (int) $child['id'];
    $pkg = cleanPackage($pkg);
    $note = 'שעות ומכסת הגלישה חלות על מגדלור בלבד; לאפליקציות — "זמן מסך" לכל אפליקציה';
    if ($pkg === '') return ['allow' => false, 'where' => '', 'reason' => 'שם חבילה לא תקין', 'note' => ''];
    if (!accountAppsAllowed((int) $child['account_id'])) {
        return ['allow' => true, 'where' => 'ניהול אפליקציות לא פעיל בחשבון', 'reason' => 'מגדלור לא חוסם אפליקציות בחשבון הזה', 'note' => ''];
    }
    $label = (string) (one('SELECT label FROM device_apps WHERE child_id = ? AND package = ?', [$cid, $pkg])['label'] ?? '') ?: $pkg;
    if ($why = alwaysOpen($pkg)) return ['allow' => true, 'label' => $label, 'where' => 'תמיד פתוח', 'reason' => $why, 'note' => $note];
    foreach (appTempGrants($cid) as $g) {
        if ($g['package'] === $pkg && $g['ends_at'] !== '') {
            return ['allow' => true, 'label' => $label, 'where' => '🙋 בקשות — היתר זמני', 'reason' => 'פתוחה עד ' . $g['ends_at'], 'note' => $note];
        }
    }
    $posture = appPosture($cid);
    $action = appRules($cid)[$pkg] ?? '';
    $where = $action !== '' ? '📲 אפליקציות — כלל על האפליקציה' : '📲 אפליקציות — ברירת המחדל';
    $reason = match (true) {
        $action === 'allow' => 'אישרתם את האפליקציה הזו',
        $action === 'block' => 'חסמתם את האפליקציה הזו',
        $posture === 'deny_all' => 'ברירת המחדל: רק אפליקציות שאישרתם',
        default => 'ברירת המחדל: הכול פתוח חוץ ממה שחסמתם',
    };
    $allow = !appBlocked($posture, $action);
    // זמן מסך (lib/apptime.php): מגבלה יומית, לפי מה שהמכשיר דיווח היום.
    $limit = appLimits($cid)[$pkg] ?? 0;
    if ($allow && $limit > 0) {
        $used = intdiv(appUsageToday($cid)[$pkg] ?? 0, 60);
        if ($used >= $limit) return ['allow' => false, 'label' => $label, 'where' => '📲 אפליקציות — זמן מסך',
            'reason' => "נגמר הזמן להיום ($limit דק׳)", 'note' => 'לפי הדיווח האחרון מהמכשיר'];
        $reason .= " · עוד " . ($limit - $used) . " דק׳ היום (מתוך $limit)";
    }
    return ['allow' => $allow, 'label' => $label, 'where' => $where, 'reason' => $reason, 'note' => $note];
}
