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
 */

declare(strict_types=1);

require_once __DIR__ . '/children.php';
require_once __DIR__ . '/appversion.php';

const APP_POSTURES = ['allow_all', 'deny_all'];

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
function appsReport(int $childId, array $apps): int {
    $n = 0;
    $now = nowIso();
    db()->beginTransaction();
    foreach ($apps as $a) {
        $pkg = cleanPackage((string) ($a['p'] ?? ''));
        if ($pkg === '') continue;
        upsert('device_apps', ['child_id' => $childId, 'package' => $pkg],
               ['label' => mb_substr((string) ($a['n'] ?? ''), 0, 100), 'is_system' => !empty($a['s']) ? 1 : 0, 'last_seen' => $now]);
        $n++;
    }
    db()->commit();
    return $n;
}

/* ── המדיניות ──────────────────────────────────────────────────── */

function appPosture(int $childId): string {
    $p = (string) (policyFor($childId)['apps_posture'] ?? 'allow_all');
    return in_array($p, APP_POSTURES, true) ? $p : 'allow_all';
}

/** מפה package → allow|block. */
function appRules(int $childId): array {
    $out = [];
    foreach (all('SELECT package, action FROM app_rules WHERE child_id = ?', [$childId]) as $r) {
        $out[$r['package']] = $r['action'] === 'allow' ? 'allow' : 'block';
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
    return [
        'manage'  => true,
        'posture' => appPosture($childId),
        'blocked' => array_values(array_diff(array_keys(array_filter($rules, fn($a) => $a === 'block')), UPDATE_PACKAGES, SHELL_PACKAGES, [SELF_PACKAGE])),
        'allowed' => array_values(array_unique(array_merge(array_keys(array_filter($rules, fn($a) => $a === 'allow')), UPDATE_PACKAGES, SHELL_PACKAGES))),
    ];
}

/* ── עריכה (מההורה) ────────────────────────────────────────────── */

function appsSavePosture(int $childId, string $posture): string {
    if (!in_array($posture, APP_POSTURES, true)) return 'ברירת מחדל לא מוכרת';
    q('UPDATE policies SET apps_posture = ?, updated_at = ? WHERE user_id = ?', [$posture, nowIso(), $childId]);
    return '';
}

/** הכרעה לחבילה: allow / block / '' (מחיקת הכלל — חוזר לברירת המחדל). */
function appsSaveRule(int $childId, string $pkg, string $action): string {
    $pkg = cleanPackage($pkg);
    if ($pkg === '') return 'שם חבילה לא תקין';
    if ($why = alwaysOpen($pkg)) return "תמיד פתוח — $why";
    if ($action !== '' && !in_array($action, ['allow', 'block'], true)) return 'פעולה לא מוכרת';
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
    $apps = [];
    foreach (all('SELECT package, label, is_system FROM device_apps WHERE child_id = ? ORDER BY is_system, LOWER(label), package', [$childId]) as $a) {
        $pkg = $a['package'];
        $why = alwaysOpen($pkg);                             // מגדלור ומסך ההתקנה — תמיד פתוחים
        $locked = $why !== '';
        $apps[] = ['package' => $pkg, 'label' => $a['label'] ?: $pkg, 'system' => (bool) $a['is_system'],
                   'action' => $locked ? 'allow' : ($rules[$pkg] ?? ''), 'locked' => $locked, 'why' => $why,
                   'blocked' => !$locked && appBlocked($posture, $rules[$pkg] ?? '')];
        unset($rules[$pkg]);
    }
    // כללים ל"רפאים" — חבילות שכבר לא מותקנות אך נשמר עליהן כלל.
    $orphans = array_map(fn($p, $a) => ['package' => $p, 'action' => $a], array_keys($rules), array_values($rules));
    // מכשירים שחסר בהם שירות הנגישות — בלעדיו החסימה של אפליקציות לא נאכפת.
    $accessMissing = count(array_filter(all('SELECT perms FROM devices WHERE user_id = ?', [$childId]),
        fn($d) => !((int) $d['perms'] & PERM_ACCESS)));
    $devices = (int) one('SELECT COUNT(*) n FROM devices WHERE user_id = ?', [$childId])['n'];
    return ['manage' => true, 'posture' => $posture, 'apps' => $apps, 'orphan_rules' => $orphans,
            'blocked_count' => count(array_filter($apps, fn($x) => $x['blocked'])),
            'access_missing' => $accessMissing, 'devices' => $devices];
}

/** האם חבילה חסומה בפועל, לפי ברירת המחדל וההכרעה. */
function appBlocked(string $posture, string $action): bool {
    if ($action === 'allow') return false;
    if ($action === 'block') return true;
    return $posture === 'deny_all';
}
