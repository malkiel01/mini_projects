<?php
/**
 * בדיקת הגנה יזומה (אפיון, שלב 12 — ג): ההורה מבקש, והמכשיר בודק *את עצמו* — בלי לגלוש לשום אתר ובלי לפתוח
 * שום אפליקציה, כך שאין בה סיכון לילד:
 *
 *   ‏web      — כתובות בדיקה (כלל חסימה, כלל היתר, כתובת שאינה ברשימה, סוג תוכן חסום). המכשיר מכריע כל אחת
 *              במנוע שלו — זה שהדפדפן משתמש בו — וההכרעה מושווית לזו שהשרת חישב כאן. הכתובות לא נטענות.
 *   ‏apps     — עד 5 אפליקציות מותקנות שאמורות להיות חסומות: האם מגדלור חוסם אותן, ובמה — השעיה במערכת
 *              (isPackageSuspended, נקרא מהמערכת עצמה) או נגישות.
 *   ‏settings — הגדרות המכשיר נקראות בחזרה מהמערכת (Enforcer.readBack) — בלי להחיל דבר.
 *   ‏device   — "בעל מכשיר", נגישות, הגנה מהסרה, זמן מאז הסנכרון.
 *
 * הפסיקה כאן, בשרת — מהנתונים הגולמיים שהמכשיר שלח (לא מ"עבר" שהוא הכריז). הבקשה משנה את policyRev, וכך כל
 * מסלול סנכרון במכשיר מושך אותה.
 */

declare(strict_types=1);

require_once __DIR__ . '/simulator.php';
require_once __DIR__ . '/devicesettings.php';
require_once __DIR__ . '/enforce.php';

/** כתובת שאינה קיימת (‎.invalid — RFC 2606) — לא נטענת לעולם, ואינה ברשימה של אף אחד. */
const SELFTEST_UNLISTED = 'https://migdalor-selftest.invalid/';

/** הכרעות שתלויות בשעה ובזמן שנוצל — לא משווים אותן (השרת והמכשיר מכריעים ברגעים שונים). */
const SELFTEST_TIME_CODES = ['outside_window', 'day_blocked', 'quota_spent', 'session_over'];

/** סנכרון ישן מזה — אזהרה: המכשיר אוכף מדיניות שמורה. */
const SELFTEST_SYNC_WARN_SEC = 6 * 3600;

/** ההורה ביקש בדיקה: לכל מכשירי הילד. מוחזר מספר המכשירים. */
function selftestRequest(int $childId): int {
    $id = gmdate('Y-m-d\TH:i:s\Z') . '-' . bin2hex(random_bytes(3));
    return q('UPDATE devices SET selftest_req = ? WHERE user_id = ?', [$id, $childId])->rowCount();
}

/**
 * מה המכשיר בודק — רק כשיש לו בקשה שעוד לא ענה עליה; אחרת null.
 * ‏$user — כמו ב-policyPayload (עם device_row_id).
 */
function selftestFor(array $user): ?array {
    $dev = one('SELECT selftest_req, selftest_done FROM devices WHERE id = ?', [(int) ($user['device_row_id'] ?? 0)]);
    if (!$dev || $dev['selftest_req'] === '' || $dev['selftest_req'] === $dev['selftest_done']) return null;
    return ['id' => $dev['selftest_req'], 'web' => selftestCases($user), 'apps' => selftestApps($user)];
}

/** כתובות הבדיקה, כל אחת עם ההכרעה שהשרת מצפה לה. */
function selftestCases(array $child): array {
    $cid = (int) $child['id'];
    $urls = [];
    $set = ruleSetFor($cid);
    foreach (['deny' => 'כלל "חסום"', 'allow' => 'כלל "מותר"'] as $action => $what) {
        foreach ($set['rules'] as $r) {
            if (($r['enabled'] ?? 1) && $r['action'] === $action && ($u = selftestRuleUrl((string) $r['pattern']))) {
                $urls[] = [$u, $what . ': ' . ($r['label'] ?: $r['pattern'])];
                break;
            }
        }
    }
    $urls[] = [SELFTEST_UNLISTED, 'אתר שאינו ברשימה'];
    $types = array_values(array_filter(array_map('trim', explode(',', (string) (policyFor($cid)['blocked_types'] ?? '')))));
    if ($types && ($ext = contentTypeCatalog()[$types[0]]['ext'][0] ?? '')) {
        $urls[] = [SELFTEST_UNLISTED . "file.$ext", 'סוג תוכן חסום: ' . (contentTypeCatalog()[$types[0]]['label'] ?? $types[0])];
    }
    $out = [];
    foreach ($urls as [$url, $what]) {
        // הזמן שנוצל — 0: בודקים את הכללים, לא את המכסה (השעון נבדק בנפרד, ‏SELFTEST_TIME_CODES).
        $d = simulateSite($child, $url, '', -1, 0);
        $out[] = ['url' => $url, 'what' => $what, 'allow' => $d['allow'], 'code' => $d['code']];
    }
    return $out;
}

/** כתובת שכלל תופס: התבנית עצמה, עם https אם חסר. כללים שאינם כתובת (תבנית עם *) — מדלגים. */
function selftestRuleUrl(string $pattern): string {
    $p = trim($pattern);
    if ($p === '' || str_contains($p, '*') || str_contains($p, ' ')) return '';
    if (!preg_match('#^[a-z][a-z0-9+.\-]*://#i', $p)) $p = 'https://' . ltrim($p, '/');
    return normalizeUrl($p) ? $p : '';
}

/** עד 5 אפליקציות מותקנות (שהמכשיר דיווח עליהן) שאמורות להיות חסומות עכשיו. */
function selftestApps(array $child): array {
    if (!accountAppsAllowed((int) $child['account_id'])) return [];
    $out = [];
    foreach (all('SELECT package, label FROM device_apps WHERE child_id = ? ORDER BY is_system, label', [(int) $child['id']]) as $a) {
        if (simulateApp($child, $a['package'])['allow'] ?? true) continue;
        $out[] = ['p' => $a['package'], 'n' => $a['label'] ?: $a['package']];
        if (count($out) >= 5) break;
    }
    return $out;
}

/**
 * תוצאות מהמכשיר. נשמרות רק לבקשה הפתוחה שלו — תשובה ישנה או בלי בקשה נדחית. מוחזרת שגיאה, או ''.
 */
function selftestSave(int $deviceRowId, array $r): string {
    $dev = one('SELECT selftest_req FROM devices WHERE id = ?', [$deviceRowId]);
    if (!$dev || $dev['selftest_req'] === '' || (string) ($r['id'] ?? '') !== $dev['selftest_req']) return 'אין בקשת בדיקה פתוחה';
    $clean = [
        'owner'     => !empty($r['owner']),
        'access'    => !empty($r['access']),
        'uninstall' => isset($r['uninstall']) && $r['uninstall'] !== null ? (bool) $r['uninstall'] : null,
        'sync_ago'  => max(-1, (int) ($r['sync_ago_sec'] ?? -1)),
        'web'       => [],
        'apps'      => [],
        'settings'  => [],
        'version'   => (int) ($r['version'] ?? 0),
    ];
    foreach (array_slice((array) ($r['web'] ?? []), 0, 10) as $w) {
        if (!is_array($w)) continue;
        $clean['web'][] = ['url' => mb_substr((string) ($w['url'] ?? ''), 0, 300), 'what' => mb_substr((string) ($w['what'] ?? ''), 0, 120),
                           'want_allow' => !empty($w['want_allow']), 'want_code' => (string) ($w['want_code'] ?? ''),
                           'got_allow' => !empty($w['got_allow']), 'got_code' => (string) ($w['got_code'] ?? '')];
    }
    foreach (array_slice((array) ($r['apps'] ?? []), 0, 10) as $a) {
        if (!is_array($a) || ($p = cleanPackage((string) ($a['p'] ?? ''))) === '') continue;
        $clean['apps'][] = ['p' => $p, 'n' => mb_substr((string) ($a['n'] ?? ''), 0, 80),
                            'state' => in_array($a['state'] ?? '', ['suspended', 'access', 'open', 'missing'], true) ? $a['state'] : 'open'];
    }
    foreach ((array) ($r['settings'] ?? []) as $k => $v) {
        if (isset(DEVICE_SETTINGS[$k]) && in_array($v, ENFORCE_SETTING_RESULTS, true)) $clean['settings'][$k] = $v;
    }
    q('UPDATE devices SET selftest_done = ?, selftest_result = ?, selftest_at = ? WHERE id = ?',
      [$dev['selftest_req'], json_encode($clean, JSON_UNESCAPED_UNICODE), nowIso(), $deviceRowId]);
    return '';
}

/**
 * הפסיקה על תוצאה אחת: [{area, label, state: ok|fail|warn, detail}], מהגרוע לטוב.
 * ‏$settings — בחירת ההורה ({מפתח: חסום?}).
 */
function selftestVerdict(array $res, array $settings): array {
    $out = [];
    $add = function (string $area, string $label, string $state, string $detail = '') use (&$out) {
        $out[] = compact('area', 'label', 'state', 'detail');
    };

    $add('device', 'בעל מכשיר', $res['owner'] ? 'ok' : 'warn',
         $res['owner'] ? '' : 'בלי "בעל מכשיר" הגדרות המכשיר לא נאכפות, ואפליקציות נחסמות רק בנגישות');
    $add('device', 'שירות הנגישות', $res['access'] ? 'ok' : ($res['owner'] ? 'warn' : 'fail'),
         $res['access'] ? '' : ($res['owner'] ? 'אפליקציות חסומות מושעות במערכת, אבל דפדפנים אחרים ומסכי הגדרות לא נבדקים'
                                               : 'בלי נגישות ובלי "בעל מכשיר" — רק הגלישה בתוך מגדלור מוגנת'));
    if ($res['owner']) {
        $add('device', 'הגנה מהסרת מגדלור', $res['uninstall'] === true ? 'ok' : 'fail',
             $res['uninstall'] === true ? '' : ($res['uninstall'] === false ? 'אפשר להסיר את מגדלור' : 'לא ניתן היה לבדוק'));
    }
    if ($res['sync_ago'] >= 0) {
        $h = intdiv($res['sync_ago'], 3600);
        $add('device', 'סנכרון אחרון', $res['sync_ago'] > SELFTEST_SYNC_WARN_SEC ? 'warn' : 'ok',
             $res['sync_ago'] > SELFTEST_SYNC_WARN_SEC ? "לפני $h שעות — המכשיר אוכף מדיניות שמורה" : '');
    }

    foreach ($res['web'] as $w) {
        $time = in_array($w['got_code'], SELFTEST_TIME_CODES, true) || in_array($w['want_code'], SELFTEST_TIME_CODES, true);
        $same = $w['want_allow'] === $w['got_allow'];
        $state = $same ? 'ok' : ($time ? 'warn' : 'fail');
        $verb = fn(bool $a) => $a ? 'מותר' : 'חסום';
        $detail = $same ? $verb($w['got_allow']) . ($w['got_code'] !== $w['want_code'] && !$time ? " (סיבה אחרת: {$w['got_code']})" : '')
                        : 'השרת: ' . $verb($w['want_allow']) . ' · המכשיר: ' . $verb($w['got_allow']) . " ({$w['got_code']})"
                          . ($time ? ' — שעות או מכסה; לא נחשב כשל' : '');
        $add('web', $w['what'] ?: $w['url'], $state, $detail);
    }

    foreach ($res['apps'] as $a) {
        [$state, $detail] = match ($a['state']) {
            'suspended' => ['ok', 'מושעית במערכת'],
            'access'    => ['ok', 'נחסמת בנגישות (מסך חסימה)'],
            'missing'   => ['warn', 'כבר לא מותקנת'],
            default     => ['fail', 'לא נחסמת במכשיר'],
        };
        $add('apps', $a['n'] ?: $a['p'], $state, $detail);
    }

    foreach ($res['settings'] as $k => $v) {
        $blocked = (bool) ($settings[$k] ?? false);
        [$st, $hint] = settingStateOf($k, $blocked, $v, $settings);
        $state = match ($st) { 'verified', 'needs_device' => 'ok', 'failed' => 'fail', default => 'warn' };
        $add('settings', DEVICE_SETTINGS[$k][0] ?? $k, $state,
             ($blocked ? 'חסום' : 'מותר') . ($st === 'verified' ? ' — נקרא מהמערכת ותואם' : ($hint ? " — $hint" : '')));
    }

    $rank = ['fail' => 0, 'warn' => 1, 'ok' => 2];
    usort($out, fn($a, $b) => $rank[$a['state']] <=> $rank[$b['state']]);
    return $out;
}

/** לכל מכשיר של הילד: מתי התבקש, מתי ענה, והפסיקה. */
function selftestReport(int $childId): array {
    $settings = deviceSettings($childId);
    $out = [];
    foreach (all('SELECT id, device_name, selftest_req, selftest_done, selftest_result, selftest_at FROM devices WHERE user_id = ? ORDER BY id',
                 [$childId]) as $d) {
        $res = json_decode((string) $d['selftest_result'], true);
        $checks = is_array($res) ? selftestVerdict($res, $settings) : [];
        $count = fn(string $s) => count(array_filter($checks, fn($c) => $c['state'] === $s));
        $out[] = [
            'device'    => $d['device_name'] ?: 'מכשיר',
            'requested' => substr((string) $d['selftest_req'], 0, 20),
            'waiting'   => $d['selftest_req'] !== '' && $d['selftest_req'] !== $d['selftest_done'],
            'at'        => (string) $d['selftest_at'],
            'checks'    => $checks,
            'fail'      => $count('fail'), 'warn' => $count('warn'), 'ok' => $count('ok'),
        ];
    }
    return $out;
}
