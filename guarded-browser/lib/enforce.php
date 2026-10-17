<?php
/**
 * מצב האכיפה — "מה באמת נאכף" (SPEC.md, שלב 11).
 *
 *   כל פרמטר שההורה מגדיר ממופה למנגנון שבאמת אוכף אותו, ולרמת היכולת שהמכשיר צריך בשבילו:
 *
 *     app      — כל מכשיר של מגדלור אוכף (זמן, אתרים, יוטיוב בתוך הדפדפן).
 *     access   — דורש שירות נגישות (חסימת אפליקציות אחרות, חסימת דפדפנים אחרים).
 *     owner    — דורש "בעל מכשיר" (הגנה מהסרה, מניעת איפוס, קיוסק הרמטי).
 *
 *   לכל פרמטר מחושב מצב אמיתי לפי היכולות של *כל* המכשירים של הילד:
 *     full    — נאכף בכל המכשירים.
 *     partial — נאכף בחלקם (חלק מהמכשירים חסרי היכולת).
 *     none    — אף מכשיר לא יכול לאכוף; צריך הרשאה/רמה.
 *     na      — לא רלוונטי להגדרות הנוכחיות (למשל חסימת אפליקציות כשאין מה לחסום).
 *
 *   כך ההורה רואה לא "מה הגדרתי" אלא "מה מוגן באמת", וגם מה חסר כדי להשלים.
 */

declare(strict_types=1);

require_once __DIR__ . '/appcontrol.php';
require_once __DIR__ . '/appversion.php';

/** דורש לפחות מכשיר אחד מחובר כדי לאכוף בכלל. */
function enforceDeviceCaps(int $childId): array {
    $rows = all('SELECT perms FROM devices WHERE user_id = ?', [$childId]);
    $total = count($rows);
    $cap = fn(int $bit) => count(array_filter($rows, fn($d) => (int) $d['perms'] & $bit));
    return [
        'total'  => $total,
        'app'    => $total,                 // עצם קיום מגדלור במכשיר
        'access' => $cap(PERM_ACCESS),
        'owner'  => $cap(PERM_OWNER),
        // חסימת אפליקציות אפשרית בנגישות *או* בבעלות.
        'block'  => count(array_filter($rows, fn($d) => (int) $d['perms'] & (PERM_ACCESS | PERM_OWNER))),
    ];
}

/** מצב אכיפה לפרמטר: כמה מכשירים אוכפים מתוך הכמה שצריך. */
function enforceStatus(int $have, int $total): string {
    if ($total === 0) return 'none';
    if ($have >= $total) return 'full';
    return $have > 0 ? 'partial' : 'none';
}

/**
 * הטבלה: לכל פרמטר פעיל — מה אוכף אותו, ומה מצבו. מחזירה שורות מוכנות לתצוגה,
 * וסיכום (full / needs_access / needs_owner).
 */
function enforcementState(int $accountId, int $childId): array {
    $caps = enforceDeviceCaps($childId);
    $policy = policyFor($childId);
    $appsAllowed = accountAppsAllowed($accountId);
    // "מנהלים אפליקציות בפועל" = יש כלל חסימה כלשהו, או ברירת מחדל deny_all — גם אם המכשיר
    // עוד לא דיווח את האפליקציה עצמה.
    $hasBlockRule = count(array_filter(appRules($childId), fn($a) => $a === 'block')) > 0;
    $hasBlockedApps = $appsAllowed && ($hasBlockRule || appPosture($childId) === 'deny_all');

    $rows = [];
    $add = function (string $key, string $label, string $need, string $status, string $hint = '') use (&$rows) {
        $rows[] = ['key' => $key, 'label' => $label, 'need' => $need, 'status' => $status, 'hint' => $hint];
    };

    // תמיד נאכפים ע"י מגדלור עצמו, בכל מכשיר.
    $appFull = enforceStatus($caps['app'], $caps['total']);
    $add('time', 'זמנים ומכסה יומית', 'app', $appFull);
    $add('web', 'אתרים, קטגוריות ויוטיוב (בתוך מגדלור)', 'app', $appFull);

    // סינון הרשת *מחוץ* למגדלור: ילד יכול לפתוח דפדפן אחר אם לא חוסמים אותו.
    // רלוונטי תמיד (גם בקיוסק — קיוסק מגביל רק את מגדלור, לא אפליקציות אחרות).
    if ($appsAllowed) {
        $add('web_other', 'חסימת דפדפנים אחרים', 'access',
            $hasBlockedApps ? enforceStatus($caps['block'], $caps['total']) : 'none',
            'חוסמים את שאר הדפדפנים בלשונית "אפליקציות"');
    }

    // חסימת אפליקציות אחרות.
    if ($appsAllowed && $hasBlockedApps) {
        // נאכף אם יש נגישות או בעלות; בעלות = הרמטי, נגישות = רך.
        $add('apps', 'חסימת אפליקציות אחרות', 'access', enforceStatus($caps['block'], $caps['total']),
            $caps['owner'] >= $caps['total'] ? '' : 'נאכף דרך נגישות (רך). "בעל מכשיר" הופך את זה להרמטי.');
    } elseif ($appsAllowed) {
        $add('apps', 'חסימת אפליקציות אחרות', 'access', 'na', 'אין כרגע אפליקציות חסומות');
    }

    // דברים הרמטיים — רק "בעל מכשיר".
    $ownerStatus = enforceStatus($caps['owner'], $caps['total']);
    $add('uninstall', 'הגנה מהסרת מגדלור', 'owner', $ownerStatus, $caps['owner'] >= $caps['total'] ? '' : 'דורש "בעל מכשיר"');
    $add('tamper', 'מניעת איפוס ועקיפה', 'owner', $ownerStatus, $caps['owner'] >= $caps['total'] ? '' : 'דורש "בעל מכשיר"');

    // סיכום: מה הכי "חלש" שדורש השלמה.
    $active = array_filter($rows, fn($r) => $r['status'] !== 'na');
    $summary = 'full';
    foreach ($active as $r) {
        if ($r['status'] === 'full') continue;
        if ($r['need'] === 'owner') { $summary = 'needs_owner'; }
        elseif ($summary !== 'needs_owner') { $summary = 'needs_access'; }
    }
    if ($caps['total'] === 0) $summary = 'no_device';

    return ['caps' => $caps, 'rows' => array_values($rows), 'summary' => $summary,
            'owner_devices' => $caps['owner'], 'total_devices' => $caps['total']];
}
