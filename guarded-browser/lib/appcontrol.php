<?php
/**
 * ניהול אפליקציות המכשיר (SPEC.md, שלב 11).
 *
 *   זמין רק לחשבון שהמפתח נתן לו רמת "אפליקציות" או "מכשיר" (accountAppsAllowed). אחרת מגדלור
 *   הוא דפדפן בלבד, ולא נוגע בשאר האפליקציות.
 *
 *   המכשיר מדווח אילו אפליקציות (עם מסך פתיחה) מותקנות (appsReport). ההורה מכריע לכל אחת —
 *   מותרת / חסומה — ובוחר ברירת מחדל (apps_posture): allow_all (הכול פתוח חוץ מהחסומות) או
 *   deny_all (רק המאושרות). האכיפה עצמה במכשיר (שירות נגישות / בעל מכשיר); כאן רק המדיניות.
 *
 *   מה *לעולם לא* נחסם מוכרע במכשיר (מגדלור עצמו, המסך הראשי, חייגן) — כדי לא לנעול את הטלפון.
 *
 *   אפליקציה חדשה (בעל המערכת, 2026-10-22): ההורה קובע אם אפליקציה שתותקן מעכשיו פתוחה או חסומה — בלי קשר
 *   לברירת המחדל של מה שכבר מותקן (new_apps; ריק — כמו ברירת המחדל). במכשיר — מיד, גם בלי רשת: מה שלא
 *   ברשימה שההורה ראה (known) ולא היה מותקן כשההגדרה הגיעה (AppPolicy). כשהמכשיר מדווח עליה — נרשם עליה
 *   כלל, וההורה רואה אותה ברשימה כבר מוכרעת, ויכול לשנות.
 *
 *   הסתרה (בעל המערכת, 2026-10-22): הכרעה שלישית — 'hide'. האפליקציה נעלמת מהטלפון לגמרי (אין אייקון, לא ברשימת
 *   האפליקציות בהגדרות), והנתונים שלה נשמרים; ביטול ההסתרה מחזיר אותה כמו שהייתה. רק ב"בעל מכשיר"
 *   (setApplicationHidden) — בלעדיו אנדרואיד לא מאפשר, והיא נחסמת כרגיל. לכן היא גם ברשימת החסומות.
 */

declare(strict_types=1);

require_once __DIR__ . '/children.php';
require_once __DIR__ . '/appversion.php';
require_once __DIR__ . '/apptime.php';
require_once __DIR__ . '/chrome.php';
require_once __DIR__ . '/market.php';

const APP_POSTURES = ['allow_all', 'deny_all'];
const APP_ACTIONS = ['allow', 'block', 'hide'];
const NEW_APPS = ['', 'allow', 'block'];

/**
 * מסך ההתקנה של אנדרואיד — לעולם לא נחסם: דרכו מגדלור מתקין את העדכון של עצמו. חסום (במפורש, או ב"רק מה
 * שאישרתי") — מסך האישור נסגר מיד, וב"בעל מכשיר" אף מושעה, והעדכון לא מסתיים לעולם (באג, 2026-10-20).
 * נשלח למכשיר ברשימת המותרות, כך שגם גרסה שכבר מותקנת מקבלת את התיקון בלי עדכון.
 */
const UPDATE_PACKAGES = ['com.android.packageinstaller', 'com.google.android.packageinstaller',
                         'com.samsung.android.packageinstaller'];

/**
 * מסכי הבית והמקלדות הנפוצים — לעולם לא נחסמים. באג (2026-10-20): ב"רק מה שאישרתי" לחיצה על "בית" פתחה
 * את מסך החסימה, כי הגרסה שבמכשיר לא זיהתה את מסך הבית. נשלחים ברשימת המותרות, כך שגם גרסה שכבר
 * מותקנת מתוקנת בלי עדכון; מהגרסה הבאה האפליקציה מזהה בעצמה כל מסך בית ומקלדת (AppPolicy.scan).
 */
const SHELL_PACKAGES = [
    'com.sec.android.app.launcher', 'com.google.android.apps.nexuslauncher', 'com.android.launcher',
    'com.android.launcher3', 'com.miui.home', 'com.mi.android.globallauncher', 'com.huawei.android.launcher',
    'com.oppo.launcher', 'com.android.launcher.oppo', 'net.oneplus.launcher', 'com.motorola.launcher3',
    'com.teslacoilsw.launcher', 'com.microsoft.launcher',
    'com.samsung.android.honeyboard', 'com.sec.android.inputmethod', 'com.google.android.inputmethod.latin',
    'com.touchtype.swiftkey', 'com.android.inputmethod.latin',
];

/** מגדלור עצמה — לא מופיעה אצל ההורה כאפליקציה שאפשר לחסום (באפליקציה היא ממילא לא נחסמת לעולם). */
const SELF_PACKAGE = 'com.mbeplus.guardedbrowser';

/** חבילות שתמיד פתוחות, וההסבר שההורה רואה לידן. */
function alwaysOpen(string $pkg): string {
    if ($pkg === SELF_PACKAGE) return 'זו מגדלור עצמה';
    if (in_array($pkg, SHELL_PACKAGES, true)) return 'מסך הבית או המקלדת';
    return in_array($pkg, UPDATE_PACKAGES, true) ? 'דרכו מגדלור מתעדכן' : '';
}

/** ניהול אפליקציות פתוח לחשבון? רק ברמת apps / device. */
function accountAppsAllowed(int $accountId): bool {
    return in_array((string) (accountRow($accountId)['level_max'] ?? 'browser'), ['apps', 'device'], true);
}

/** ‏package name תקין של אנדרואיד. */
function cleanPackage(string $p): string {
    $p = trim($p);
    return preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/i', $p) && strlen($p) <= 120 ? $p : '';
}

/* ── דיווח מהמכשיר ─────────────────────────────────────────────── */

/**
 * המכשיר מדווח את האפליקציות שמותקנות אצל הילד. איחוד בין המכשירים של אותו ילד:
 * חבילה שנראתה באחד מהם — ההורה יכול להכריע עליה. מוחזר כמה נקלטו.
 */
function appsReport(int $childId, array $apps, int $deviceRowId = 0): int {
    $n = 0;
    $now = nowIso();
    // אפליקציה חדשה — רק מדיווח של מכשיר שכבר דיווח פעם (הדיווח הראשון הוא מה שהיה מותקן), ורק כשההחלטה
    // של ההורה שונה מברירת המחדל (אחרת אין מה לרשום).
    $reported = $deviceRowId > 0 && (int) (one('SELECT apps_reported FROM devices WHERE id = ? AND user_id = ?', [$deviceRowId, $childId])['apps_reported'] ?? 0);
    $newAct = appNewDefault($childId);
    $mark = $reported && $newAct !== (appPosture($childId) === 'deny_all' ? 'block' : 'allow') ? $newAct : '';
    $known = $mark ? array_flip(array_column(all('SELECT package FROM device_apps WHERE child_id = ?', [$childId]), 'package')) : [];
    $rules = $mark ? appRules($childId) : [];
    db()->beginTransaction();
    foreach ($apps as $a) {
        $pkg = cleanPackage((string) ($a['p'] ?? ''));
        if ($pkg === '') continue;
        if ($mark && !isset($known[$pkg]) && !isset($rules[$pkg]) && alwaysOpen($pkg) === '') {
            q('INSERT INTO app_rules (child_id, package, action, created_at) VALUES (?,?,?,?)', [$childId, $pkg, $mark, $now]);
        }
        upsert('device_apps', ['child_id' => $childId, 'package' => $pkg],
               ['label' => mb_substr((string) ($a['n'] ?? ''), 0, 100), 'is_system' => !empty($a['s']) ? 1 : 0, 'last_seen' => $now]);
        $n++;
    }
    if ($deviceRowId > 0) q('UPDATE devices SET apps_reported = 1 WHERE id = ? AND user_id = ?', [$deviceRowId, $childId]);
    db()->commit();
    return $n;
}

/* ── המדיניות ──────────────────────────────────────────────────── */

function appPosture(int $childId): string {
    $p = (string) (policyFor($childId)['apps_posture'] ?? 'allow_all');
    return in_array($p, APP_POSTURES, true) ? $p : 'allow_all';
}

/** אפליקציה שתותקן מעכשיו: allow / block — ההחלטה של ההורה, ובלעדיה כמו ברירת המחדל. */
function appNewDefault(int $childId): string {
    $v = (string) (policyFor($childId)['new_apps'] ?? '');
    if (in_array($v, ['allow', 'block'], true)) return $v;
    return appPosture($childId) === 'deny_all' ? 'block' : 'allow';
}

function appsSaveNewApps(int $childId, string $v): string {
    if (!in_array($v, NEW_APPS, true)) return 'בחירה לא מוכרת';
    q('UPDATE policies SET new_apps = ?, updated_at = ? WHERE user_id = ?', [$v, nowIso(), $childId]);
    return '';
}

/** מפה package → allow|block|hide. */
function appRules(int $childId): array {
    $out = [];
    foreach (all('SELECT package, action FROM app_rules WHERE child_id = ?', [$childId]) as $r) {
        $out[$r['package']] = in_array($r['action'], APP_ACTIONS, true) ? $r['action'] : 'block';
    }
    return $out;
}

/**
 * מה שהמכשיר צריך כדי לאכוף: האם לנהל, ברירת המחדל, והרשימות. ‏manage=false → מגדלור
 * לא ייגע בשאר האפליקציות (דפדפן בלבד).
 */
function appsPayload(array $user): array {
    $childId = (int) $user['id'];
    if (!accountAppsAllowed((int) $user['account_id'])) return ['manage' => false];
    $rules = appRules($childId);
    // מה שההורה שחרר במרקט — מותר, גם כשאפליקציה חדשה נחסמת וגם ב"רק מה שאישרתי" (lib/market.php).
    $released = marketReleased($childId);
    foreach ($released as $pkg) if (($rules[$pkg] ?? '') !== 'allow') $rules[$pkg] = 'allow';
    $never = array_merge(UPDATE_PACKAGES, SHELL_PACKAGES, [SELF_PACKAGE]);
    $hidden = array_values(array_diff(array_keys(array_filter($rules, fn($a) => $a === 'hide')), $never));
    return [
        'manage'  => true,
        // זמן מסך: {package: דקות ליום} — נאכף במכשיר (AppTimeMath). אובייקט גם כשריק.
        'limits'  => (object) appLimits($childId),
        'posture' => appPosture($childId),
        // אפליקציה חדשה (AppPolicy): מה שלא ב-known — לפי new_apps, מיד, עד שהשרת רושם עליה כלל.
        'new_apps' => appNewDefault($childId),
        'known'   => array_column(all('SELECT package FROM device_apps WHERE child_id = ? ORDER BY package', [$childId]), 'package'),
        // מוסתרת היא גם חסומה: גרסה ישנה, או מכשיר בלי "בעל מכשיר", חוסמים אותה כרגיל.
        'blocked' => array_values(array_diff(array_keys(array_filter($rules, fn($a) => $a !== 'allow')), $never)),
        'hidden'  => $hidden,
        // כרום מנוהל (lib/chrome.php) — נאכף רק ב"בעל מכשיר".
        'chrome'  => chromePolicy($childId),
        // המרקט (רובריקת "📲 אפליקציות"): מה ששוחרר, עם שם ואייקון.
        'market'  => marketPayload($childId),
        'allowed' => array_values(array_unique(array_merge(array_keys(array_filter($rules, fn($a) => $a === 'allow')), UPDATE_PACKAGES, SHELL_PACKAGES))),
    ];
}

/* ── עריכה (מההורה) ────────────────────────────────────────────── */

function appsSavePosture(int $childId, string $posture): string {
    if (!in_array($posture, APP_POSTURES, true)) return 'ברירת מחדל לא מוכרת';
    q('UPDATE policies SET apps_posture = ?, updated_at = ? WHERE user_id = ?', [$posture, nowIso(), $childId]);
    return '';
}

/** הכרעה לחבילה: allow / block / hide / '' (מחיקת הכלל — חוזר לברירת המחדל). */
function appsSaveRule(int $childId, string $pkg, string $action): string {
    $pkg = cleanPackage($pkg);
    if ($pkg === '') return 'שם חבילה לא תקין';
    if ($why = alwaysOpen($pkg)) return "תמיד פתוח — $why";
    if ($action !== '' && !in_array($action, APP_ACTIONS, true)) return 'פעולה לא מוכרת';
    // ההגדרות — לא מוסתרות: דרכן מגדלור עצמו פותח מסכי הקמה ועדכון (AppPolicy.openSettings). חסימה — כן.
    if ($action === 'hide' && $pkg === 'com.android.settings') return 'את ההגדרות אפשר לחסום, לא להסתיר — מגדלור צריך אותן להקמה ולעדכון';
    // החלטה חדשה של ההורה על האפליקציה גוברת על היתר זמני פעיל עליה (lib/apprequests.php) — הוא מבוטל.
    q("UPDATE access_requests SET apply_state = 'revoked' WHERE child_id = ? AND kind = 'app' AND package = ?
       AND status = 'approved' AND scope = 'temporary' AND apply_state IN ('pending', 'applied')", [$childId, $pkg]);
    if ($action === '') { q('DELETE FROM app_rules WHERE child_id = ? AND package = ?', [$childId, $pkg]); return ''; }
    upsert('app_rules', ['child_id' => $childId, 'package' => $pkg], ['action' => $action], ['created_at' => nowIso()]);
    return '';
}

/* ── תצוגה (להורה) ─────────────────────────────────────────────── */

/**
 * לרשימת האפליקציות אצל ההורה: כל אפליקציה שהמכשיר דיווח, עם ההכרעה הנוכחית (או ברירת המחדל),
 * ובנוסף כללים על אפליקציות שכבר לא דווחו (הוסרו) כדי שאפשר יהיה לנקות.
 */
function appsList(int $accountId, int $childId): array {
    if (!accountAppsAllowed($accountId)) return ['manage' => false];
    $posture = appPosture($childId);
    $rules = appRules($childId);
    $released = array_flip(marketReleased($childId));
    $limits = appLimits($childId);
    $usage = appUsageToday($childId);
    $apps = [];
    foreach (all('SELECT package, label, is_system FROM device_apps WHERE child_id = ? ORDER BY is_system, LOWER(label), package', [$childId]) as $a) {
        $pkg = $a['package'];
        $why = alwaysOpen($pkg);                             // מגדלור ומסך ההתקנה — תמיד פתוחים
        $locked = $why !== '';
        $apps[] = ['package' => $pkg, 'label' => $a['label'] ?: $pkg, 'system' => (bool) $a['is_system'],
                   'action' => $locked ? 'allow' : ($rules[$pkg] ?? ''), 'locked' => $locked, 'why' => $why,
                   // שוחררה במרקט — מותרת, מה שלא יהיה הכלל.
                   'blocked' => !$locked && !isset($released[$pkg]) && appBlocked($posture, $rules[$pkg] ?? ''),
                   'released' => isset($released[$pkg]),
                   // זמן מסך (lib/apptime.php): המגבלה, וכמה שימשה היום לפי דיווח המכשיר.
                   'limit_min' => $limits[$pkg] ?? 0, 'used_sec' => $usage[$pkg] ?? 0];
        unset($rules[$pkg]);
    }
    // כללים ל"רפאים" — חבילות שכבר לא מותקנות אך נשמר עליהן כלל.
    $orphans = array_map(fn($p, $a) => ['package' => $p, 'action' => $a], array_keys($rules), array_values($rules));
    // מכשירים שחסר בהם שירות הנגישות — בלעדיו החסימה של אפליקציות לא נאכפת.
    $accessMissing = count(array_filter(all('SELECT perms FROM devices WHERE user_id = ?', [$childId]),
        fn($d) => !((int) $d['perms'] & PERM_ACCESS)));
    $devices = (int) one('SELECT COUNT(*) n FROM devices WHERE user_id = ?', [$childId])['n'];
    // הסתרה דורשת "בעל מכשיר" — מכשירים בלעדיו רק חוסמים את המוסתרות (האייקון נשאר).
    $ownerMissing = count(array_filter(all('SELECT perms FROM devices WHERE user_id = ?', [$childId]),
        fn($d) => !((int) $d['perms'] & PERM_OWNER)));
    return ['manage' => true, 'posture' => $posture, 'new_apps' => appNewDefault($childId), 'apps' => $apps, 'orphan_rules' => $orphans,
            'blocked_count' => count(array_filter($apps, fn($x) => $x['blocked'])),
            'hidden_count' => count(array_filter($apps, fn($x) => $x['action'] === 'hide')), 'owner_missing' => $ownerMissing,
            'chrome' => chromeStatus($childId),
            'access_missing' => $accessMissing, 'devices' => $devices];
}

/** האם חבילה חסומה בפועל, לפי ברירת המחדל וההכרעה. */
function appBlocked(string $posture, string $action): bool {
    if ($action === 'allow') return false;
    if ($action === 'block' || $action === 'hide') return true;
    return $posture === 'deny_all';
}
