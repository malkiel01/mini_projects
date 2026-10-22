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
// ‏ok — גרסאות עד 2026-10-22: "הקריאה לא זרקה", בלי קריאה חוזרת. מוצג כ"הוחל", לא כ"אומת".
const ENFORCE_SETTING_RESULTS = ['ok', 'fail', 'no_owner', 'verified', 'applied', 'unsupported', 'needs_device', 'blocked_by_apps'];

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
    /** ‏$cap — הכישלון נובע מיכולת חסרה במכשיר (נגישות / "בעל מכשיר"), לא מהחלה שנכשלה. */
    $add = function (string $key, string $label, string $need, string $status, string $hint = '', bool $cap = false) use (&$rows) {
        $rows[] = ['key' => $key, 'label' => $label, 'need' => $need, 'status' => $status, 'hint' => $hint, 'cap' => $cap];
    };
    /** תוצאה לכל מכשיר: דוח עדכני → $fn(report); אחרת unknown. */
    $per = function (callable $fn) use ($reports): array {
        return array_map(fn($d) => $d['state'] === 'fresh' ? $fn($d['report'], $d) : 'unknown', $reports);
    };

    // מגדלור עצמו אוכף — אבל "נאכף" רק אחרי שהמכשיר דיווח שהחיל את המדיניות *הנוכחית* (2026-10-22).
    // קודם: "נאכף" ברגע שהיה מכשיר מחובר — הצלחה על סמך כוונה.
    $appRes = $per(fn() => 'ok');
    $appSt = enforceAggregate($appRes);
    $appHint = $appSt === 'unknown' ? 'המכשיר עוד לא דיווח שהחיל את ההגדרות האחרונות' : '';
    $add('time', 'זמנים ומכסה יומית', 'app', $appSt, $appHint);
    $add('web', 'אתרים, קטגוריות ויוטיוב (בתוך מגדלור)', 'app', $appSt, $appHint);

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
             : ($st === 'unknown' ? 'המכשיר עוד לא דיווח' : ($caps['owner'] >= $caps['total'] ? '' : 'נאכף דרך נגישות (רך). "בעל מכשיר" הופך את זה להרמטי.')),
             !$failedPkgs);
    } elseif ($appsAllowed) {
        $add('apps', 'חסימת אפליקציות אחרות', 'access', 'na', 'אין כרגע אפליקציות חסומות');
    }

    // הרמטי — "בעל מכשיר" כפי שהמכשיר מדווח (isDeviceOwnerApp), לא כפי שנשמר פעם.
    $ownerRes = $per(fn($rep) => !empty($rep['owner']) ? 'ok' : 'fail');
    $ownerStatus = enforceAggregate($ownerRes);
    $ownerHint = $ownerStatus === 'full' ? '' : ($ownerStatus === 'unknown' ? 'המכשיר עוד לא דיווח' : 'דורש "בעל מכשיר"');
    $add('uninstall', 'הגנה מהסרת מגדלור', 'owner', $ownerStatus, $ownerHint, true);
    $add('tamper', 'מניעת איפוס ועקיפה', 'owner', $ownerStatus, $ownerHint, true);

    // הגדרות המכשיר — כל הגדרה שההורה חסם הוחלה בפועל.
    $blocked = array_keys(array_filter(deviceSettings($childId)));
    if ($blocked) {
        $bad = [];
        $res = $per(function ($rep) use ($blocked, &$bad) {
            if (empty($rep['owner'])) return 'fail';
            $r = 'ok';
            foreach ($blocked as $k) if (!in_array($rep['settings'][$k] ?? '', ['ok', 'applied', 'verified'], true)) { $bad[DEVICE_SETTINGS[$k][0]] = true; $r = 'fail'; }
            return $r;
        });
        $st = enforceAggregate($res);
        $add('device', 'הגדרות המכשיר', 'owner', $st,
             $bad ? 'לא הוחלו: ' . implode(', ', array_keys($bad))
             : ($st === 'unknown' ? 'המכשיר עוד לא דיווח' : ($st === 'full' ? '' : 'דורש הקמה מלאה ("בעל מכשיר")')),
             !$bad);
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

    // אותו אוצר מצבים כמו בהגדרות המכשיר (SETTING_STATES): נשמר / ממתין / התקבל / הוחל / נכשל / נדרשת פעולה.
    $rev = policyRev($childId);
    $pulled = (int) (one('SELECT COUNT(*) n FROM devices WHERE user_id = ? AND policy_rev = ?', [$childId, $rev])['n'] ?? 0);
    foreach ($rows as &$row) {
        $row['state'] = match (true) {
            $row['status'] === 'na'      => 'na',
            $caps['total'] === 0         => 'saved',
            $row['status'] === 'full'    => 'applied',
            $row['status'] === 'unknown' => $pulled >= $caps['total'] ? 'received' : 'pending',
            $row['cap']                  => 'needs_device',
            default                      => 'failed',
        };
        $row['state_label'] = SETTING_STATES[$row['state']] ?? '';
        unset($row['cap']);
    }
    unset($row);

    return ['caps' => $caps, 'rows' => array_values($rows), 'summary' => $summary,
            'owner_devices' => $caps['owner'], 'total_devices' => $caps['total'],
            // מתי כל מכשיר דיווח לאחרונה, ובאיזה מצב — כדי שדוח ישן לא ייראה כאימות עדכני.
            'devices' => array_map(fn($d) => ['name' => $d['name'], 'reported_at' => $d['reported_at'], 'state' => $d['state']], $reports)];
}


/* ── מצב הביצוע של כל הגדרה (2026-10-22) ─────────────────────────────────────────────────
 * "אין להציג הצלחה על סמך שמירה בשרת בלבד": לכל הגדרת מכשיר — חסומה או מותרת — המצב בפועל, לפי מה
 * שהמכשיר משך (devices.policy_rev) ומה שדיווח שקרא בחזרה מהמערכת (enforce_report.settings).
 */

/** מצבים, מהגרוע לטוב — כשיש כמה מכשירים מוצג הגרוע. */
const SETTING_STATES = [
    'failed'       => 'נכשל',
    'needs_action' => 'נדרשת פעולה',
    'needs_device' => 'נדרשת פעולה במכשיר',
    'unsupported'  => 'לא נתמך',
    'saved'        => 'נשמר בשרת',
    'pending'      => 'ממתין למכשיר',
    'received'     => 'התקבל במכשיר',
    'applied'      => 'הוחל',
    'verified'     => 'אומת',
];

/** תוצאה אחת מהמכשיר → [מצב, הסבר]. */
function settingStateOf(string $key, bool $blocked, string $result, array $settings): array {
    return match ($result) {
        'verified'        => ['verified', ''],
        'ok', 'applied'   => ['applied', 'הקריאה למערכת הצליחה; המכשיר לא יכול היה לבדוק את התוצאה'],
        'no_owner'        => ['needs_device', 'דורש הקמה מלאה ("בעל מכשיר")'],
        'unsupported'     => ['unsupported', 'גרסת האנדרואיד במכשיר לא מאפשרת את ההגדרה הזו'],
        'needs_device'    => ['needs_device', 'מותר. בכל התקנה אנדרואיד מבקש לאשר במכשיר את האפליקציה שממנה מתקינים'
                                               . (($settings['install_apps'] ?? false) ? ' — אבל "התקנת אפליקציות" חסומה, ולכן גם זה חסום' : '')],
        'blocked_by_apps' => ['needs_action', 'מותר, אבל Google Play חסומה בלשונית "אפליקציות" — פותחים אותה שם'],
        'fail'            => ['failed', $blocked ? 'המכשיר לא הצליח לחסום' : 'המכשיר לא הצליח לפתוח — נשארה הגבלה'],
        default           => ['received', 'המכשיר לא דיווח על ההגדרה הזו (גרסה ישנה)'],
    };
}

/**
 * לכל הגדרה: ['state', 'label', 'hint', 'at', 'devices' => [{name, state, hint}]]. ‏$now — לבדיקות.
 */
function deviceSettingsStatus(int $childId, ?int $now = null): array {
    $now ??= time();
    $rev = policyRev($childId);
    $settings = deviceSettings($childId);
    $devices = all('SELECT id, device_name, policy_rev, enforce_report, enforce_at FROM devices WHERE user_id = ?', [$childId]);
    $rank = array_flip(array_keys(SETTING_STATES));
    $out = [];
    foreach ($settings as $key => $blocked) {
        $per = [];
        foreach ($devices as $d) {
            $rep = json_decode((string) $d['enforce_report'], true);
            $fresh = is_array($rep) && ($rep['rev'] ?? '') === $rev && $now - (int) strtotime((string) $d['enforce_at']) <= ENFORCE_STALE_SEC;
            if ($fresh) [$st, $hint] = settingStateOf($key, $blocked, (string) ($rep['settings'][$key] ?? ''), $settings);
            elseif ((string) $d['policy_rev'] === $rev) [$st, $hint] = ['received', 'המכשיר קיבל את ההגדרה ועוד לא דיווח שהחיל אותה'];
            else [$st, $hint] = ['pending', 'המכשיר עוד לא משך את ההגדרה (כבוי, או בלי אינטרנט)'];
            $per[] = ['name' => (string) $d['device_name'], 'state' => $st, 'hint' => $hint,
                      'at' => $fresh ? (string) $d['enforce_at'] : ''];
        }
        if (!$per) {
            $state = 'saved'; $hint = 'אין מכשיר מחובר';
        } else {
            usort($per, fn($a, $b) => $rank[$a['state']] <=> $rank[$b['state']]);
            $state = $per[0]['state'];
            $hint = $per[0]['hint'] . (count($per) > 1 ? " ({$per[0]['name']})" : '');
        }
        // "אומת לאחרונה" — רק כשכל המכשירים דיווחו: הדיווח הישן מביניהם.
        $ats = array_filter(array_column($per, 'at'));
        $out[$key] = ['state' => $state, 'label' => SETTING_STATES[$state], 'hint' => $hint,
                      'at' => $per && count($ats) === count($per) ? min($ats) : '', 'devices' => $per];
    }
    return $out;
}

