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
require_once __DIR__ . '/devicesettings.php';

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

/* ── דוח האכיפה מהמכשיר (סקירה, 2026-10-20) ─────────────────────────────
 *
 * עד עכשיו המצב הוסק מביטי הרשאות בלבד: "יש נגישות" = "נאכף", וחסימת משחק נחשבה חסימת דפדפנים.
 * עכשיו כל מכשיר מדווח אחרי כל החלה (Enforcer.kt → api do=enforce_report): מה הוחל ומה נכשל, אילו
 * דפדפנים יש בו ואם הם חסומים, ומאיזו גרסת מדיניות (rev). המצב להורה נגזר מהדוח:
 *   נשמר בשרת → נשלח → התקבל → הוחל → אומת/נכשל. בלי דוח, או דוח ישן — "לא דווח", לא "נאכף".
 */

/** דוח שלא חודש יותר מזה — אינו אימות עדכני. */
const ENFORCE_STALE_SEC = 24 * 3600;
const ENFORCE_SETTING_RESULTS = ['ok', 'fail', 'no_owner'];

/** שמירת דוח מהמכשיר, אחרי ניקוי (הדוח מגיע מהמכשיר — לא סומכים על הצורה שלו). */
function enforceReportSave(int $deviceRowId, array $r): void {
    $settings = [];
    foreach ((array) ($r['settings'] ?? []) as $k => $v) {
        if (isset(DEVICE_SETTINGS[$k]) && in_array($v, ENFORCE_SETTING_RESULTS, true)) $settings[$k] = $v;
    }
    $failed = array_values(array_filter(array_map(fn($p) => cleanPackage((string) $p),
                                                  array_slice((array) ($r['suspend_failed'] ?? []), 0, 200))));
    $browsers = [];
    foreach (array_slice((array) ($r['browsers'] ?? []), 0, 50) as $b) {
        $pkg = cleanPackage((string) ($b['p'] ?? ''));
        if ($pkg !== '') $browsers[] = ['p' => $pkg, 'n' => mb_substr((string) ($b['n'] ?? ''), 0, 60), 'blocked' => !empty($b['blocked'])];
    }
    $clean = ['rev' => mb_substr((string) ($r['rev'] ?? ''), 0, 64), 'owner' => !empty($r['owner']),
              'access' => !empty($r['access']), 'settings' => $settings, 'suspend_failed' => $failed,
              'browsers' => $browsers];
    q('UPDATE devices SET enforce_report = ?, enforce_at = ? WHERE id = ?',
      [json_encode($clean, JSON_UNESCAPED_UNICODE), nowIso(), $deviceRowId]);
}

/**
 * הדוחות של מכשירי הילד, עם מצב לכל אחד: fresh (על המדיניות הנוכחית, מהיממה האחרונה), pending
 * (עוד לא הוחלה המדיניות הנוכחית), stale (ישן), none (המכשיר לא דיווח — גרסה ישנה).
 */
function enforceReports(int $childId, ?int $now = null): array {
    $now ??= time();
    $rev = policyRev($childId);
    $out = [];
    foreach (all('SELECT id, device_name, perms, enforce_report, enforce_at FROM devices WHERE user_id = ?', [$childId]) as $d) {
        $rep = json_decode((string) $d['enforce_report'], true);
        $at = (string) $d['enforce_at'];
        $state = !is_array($rep) ? 'none'
            : ($now - (int) strtotime($at) > ENFORCE_STALE_SEC ? 'stale'
            : (($rep['rev'] ?? '') !== $rev ? 'pending' : 'fresh'));
        $out[] = ['id' => (int) $d['id'], 'name' => (string) $d['device_name'], 'perms' => (int) $d['perms'],
                  'report' => is_array($rep) ? $rep : null, 'reported_at' => $at, 'state' => $state];
    }
    return $out;
}

/**
 * מצב שורה מתוך תוצאה לכל מכשיר: ok / fail / unknown. כולם ok — full; חלק — partial; אף אחד — none;
 * אין כישלון אבל יש מכשיר שלא אומת — unknown (לא "נאכף").
 */
function enforceAggregate(array $results): string {
    if (!$results) return 'none';
    $ok = count(array_filter($results, fn($r) => $r === 'ok'));
    $unknown = count(array_filter($results, fn($r) => $r === 'unknown'));
    if ($ok === count($results)) return 'full';
    if ($ok === 0 && $unknown === 0) return 'none';
    if ($unknown > 0 && $ok + $unknown === count($results)) return 'unknown';
    return 'partial';
}

/**
 * הטבלה: לכל פרמטר פעיל — מה אוכף אותו, ומה מצבו לפי הדוחות מהמכשירים. מחזירה שורות מוכנות לתצוגה,
 * וסיכום (full / needs_access / needs_owner / unverified / no_device).
 */
function enforcementState(int $accountId, int $childId): array {
    $caps = enforceDeviceCaps($childId);
    $reports = enforceReports($childId);
    $appsAllowed = accountAppsAllowed($accountId);
    $hasBlockRule = count(array_filter(appRules($childId), fn($a) => $a === 'block')) > 0;
    $hasBlockedApps = $appsAllowed && ($hasBlockRule || appPosture($childId) === 'deny_all');

    $rows = [];
    $add = function (string $key, string $label, string $need, string $status, string $hint = '') use (&$rows) {
        $rows[] = ['key' => $key, 'label' => $label, 'need' => $need, 'status' => $status, 'hint' => $hint];
    };
    /** תוצאה לכל מכשיר: דוח עדכני → $fn(report); אחרת unknown. */
    $per = function (callable $fn) use ($reports): array {
        return array_map(fn($d) => $d['state'] === 'fresh' ? $fn($d['report'], $d) : 'unknown', $reports);
    };

    // מגדלור עצמו — בכל מכשיר שבו הוא מותקן ומחובר.
    $appFull = enforceStatus($caps['app'], $caps['total']);
    $add('time', 'זמנים ומכסה יומית', 'app', $appFull);
    $add('web', 'אתרים, קטגוריות ויוטיוב (בתוך מגדלור)', 'app', $appFull);

    // דפדפנים אחרים — לפי מה שהמכשיר מצא ודיווח, לא לפי "יש כלל חסימה כלשהו".
    if ($appsAllowed) {
        $open = [];
        $res = $per(function ($rep) use (&$open) {
            $o = array_filter($rep['browsers'] ?? [], fn($b) => empty($b['blocked']));
            foreach ($o as $b) $open[$b['n'] ?: $b['p']] = true;
            return $o ? 'fail' : 'ok';
        });
        $st = enforceAggregate($res);
        $add('web_other', 'חסימת דפדפנים אחרים', 'access', $st,
             $open ? 'פתוחים: ' . implode(', ', array_keys($open)) . ' — חוסמים אותם בלשונית "אפליקציות"'
                   : ($st === 'unknown' ? 'המכשיר עוד לא דיווח' : ''));
    }

    // חסימת אפליקציות אחרות — נגישות פעילה בפועל (לא רק ביט), והשעיות שלא נכשלו.
    if ($appsAllowed && $hasBlockedApps) {
        $failedPkgs = [];
        $res = $per(function ($rep) use (&$failedPkgs) {
            foreach ($rep['suspend_failed'] ?? [] as $p) $failedPkgs[$p] = true;
            if (!empty($rep['suspend_failed'])) return 'fail';
            return !empty($rep['access']) || !empty($rep['owner']) ? 'ok' : 'fail';
        });
        $st = enforceAggregate($res);
        $add('apps', 'חסימת אפליקציות אחרות', 'access', $st,
             $failedPkgs ? 'ההשעיה נכשלה: ' . implode(', ', array_keys($failedPkgs)) . '. מגדלור ינסה שוב בסנכרון הבא.'
             : ($st === 'unknown' ? 'המכשיר עוד לא דיווח' : ($caps['owner'] >= $caps['total'] ? '' : 'נאכף דרך נגישות (רך). "בעל מכשיר" הופך את זה להרמטי.')));
    } elseif ($appsAllowed) {
        $add('apps', 'חסימת אפליקציות אחרות', 'access', 'na', 'אין כרגע אפליקציות חסומות');
    }

    // הרמטי — "בעל מכשיר" כפי שהמכשיר מדווח (isDeviceOwnerApp), לא כפי שנשמר פעם.
    $ownerRes = $per(fn($rep) => !empty($rep['owner']) ? 'ok' : 'fail');
    $ownerStatus = enforceAggregate($ownerRes);
    $ownerHint = $ownerStatus === 'full' ? '' : ($ownerStatus === 'unknown' ? 'המכשיר עוד לא דיווח' : 'דורש "בעל מכשיר"');
    $add('uninstall', 'הגנה מהסרת מגדלור', 'owner', $ownerStatus, $ownerHint);
    $add('tamper', 'מניעת איפוס ועקיפה', 'owner', $ownerStatus, $ownerHint);

    // הגדרות המכשיר — כל הגדרה שההורה חסם הוחלה בפועל.
    $blocked = array_keys(array_filter(deviceSettings($childId)));
    if ($blocked) {
        $bad = [];
        $res = $per(function ($rep) use ($blocked, &$bad) {
            if (empty($rep['owner'])) return 'fail';
            $r = 'ok';
            foreach ($blocked as $k) if (($rep['settings'][$k] ?? '') !== 'ok') { $bad[DEVICE_SETTINGS[$k][0]] = true; $r = 'fail'; }
            return $r;
        });
        $st = enforceAggregate($res);
        $add('device', 'הגדרות המכשיר', 'owner', $st,
             $bad ? 'לא הוחלו: ' . implode(', ', array_keys($bad))
             : ($st === 'unknown' ? 'המכשיר עוד לא דיווח' : ($st === 'full' ? '' : 'דורש הקמה מלאה ("בעל מכשיר")')));
    } else {
        $add('device', 'הגדרות המכשיר', 'owner', 'na');
    }

    // סיכום: מה הכי "חלש" שדורש השלמה.
    $active = array_filter($rows, fn($r) => $r['status'] !== 'na');
    $summary = 'full';
    foreach ($active as $r) {
        if ($r['status'] === 'full') continue;
        if ($r['status'] === 'unknown') { if ($summary === 'full') $summary = 'unverified'; continue; }
        if ($r['need'] === 'owner') { $summary = 'needs_owner'; }
        elseif ($summary !== 'needs_owner') { $summary = 'needs_access'; }
    }
    if ($caps['total'] === 0) $summary = 'no_device';

    return ['caps' => $caps, 'rows' => array_values($rows), 'summary' => $summary,
            'owner_devices' => $caps['owner'], 'total_devices' => $caps['total'],
            // מתי כל מכשיר דיווח לאחרונה, ובאיזה מצב — כדי שדוח ישן לא ייראה כאימות עדכני.
            'devices' => array_map(fn($d) => ['name' => $d['name'], 'reported_at' => $d['reported_at'], 'state' => $d['state']], $reports)];
}
