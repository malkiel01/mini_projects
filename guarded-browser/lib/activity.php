<?php
/**
 * פעילות ודוחות להורה (SPEC.md, סעיף 10 · שלב 7).
 *
 *   ההורה בוחר לכל ילד כמה לראות:
 *     full    — כל כתובת, מתי, וכמה זמן (רק אם החשבון כולל reports_full)
 *     summary — לפי אתר ויום: זמן וכניסות. הכתובות עצמן לא נשמרות
 *     off     — רק זמן המסך היומי (הוא נדרש למכסה)
 *   ניסיונות חסימה נשמרים תמיד — זה מה שההורה צריך לדעת בכל רמה.
 *
 *   מה שלא נבחר — לא נשמר בכלל, ולא רק מוסתר. ירידה ברמה מוחקת את מה שכבר נשמר.
 *   שמירה: 90 יום ליומן, שנה לסיכומים.
 *
 *   הכתיבה כאן (activityNav, activityBeat) נקראת מה-API של המכשיר; הקריאה — מאפליקציית ההורה.
 */

declare(strict_types=1);

require_once __DIR__ . '/children.php';

const ACTIVITY_KEEP_DAYS = 90;
const ACTIVITY_SUMMARY_DAYS = 365;

/** מה החסימה אומרת להורה — בקצרה, לשורה ברשימה. */
const BLOCK_LABELS = [
    'not_listed' => 'לא ברשימה', 'category_deny' => 'קטגוריה חסומה', 'rule_deny' => 'חסמתם',
    'type_blocked' => 'סוג תוכן חסום', 'quota_spent' => 'נגמר הזמן', 'outside_window' => 'מחוץ לשעות',
    'day_blocked' => 'יום חסום', 'session_over' => 'ישיבה ארוכה מדי', 'yt_off' => 'יוטיוב כבוי',
    'yt_not_approved' => 'יוטיוב — לא מאושר', 'yt_item_denied' => 'יוטיוב — חסמתם',
    'yt_no_search' => 'חיפוש ביוטיוב', 'yt_no_browse' => 'דף הבית של יוטיוב', 'yt_no_shorts' => 'Shorts',
    'bad_url' => 'כתובת לא תקינה',
];

function reportLevel(int $childId): string {
    $l = (string) (policyFor($childId)['report_level'] ?? 'summary');
    return in_array($l, REPORT_LEVELS, true) ? $l : 'summary';
}

/** הרמות שההורה יכול לבחור — "מלא" רק בחשבון עם reports_full. */
function reportLevelsFor(int $accountId): array {
    $mods = moduleList((string) (accountRow($accountId)['modules'] ?? ''));
    return in_array('reports_full', $mods, true) ? REPORT_LEVELS : ['summary', 'off'];
}

/**
 * החלפת רמה. ירידה מוחקת את מה שכבר נשמר מעבר לרמה החדשה: מי שבחר "מסוכם"
 * לא אמור למצוא אחר כך היסטוריה מלאה בשרת. מוחזרת שגיאה, או ''.
 */
function reportLevelSave(int $accountId, int $childId, string $level): string {
    if (!in_array($level, reportLevelsFor($accountId), true)) {
        return $level === 'full' ? 'דיווח מלא אינו כלול בחשבון. אפשר לבקש ממנהל המערכת.' : 'רמה לא מוכרת';
    }
    q('UPDATE policies SET report_level = ?, updated_at = ? WHERE user_id = ?', [$level, nowIso(), $childId]);
    if ($level !== 'full') q("DELETE FROM audit WHERE user_id = ? AND kind = 'nav' AND allowed = 1", [$childId]);
    if ($level === 'off')  q('DELETE FROM site_usage WHERE user_id = ?', [$childId]);
    return '';
}

/* ── כתיבה (מהמכשיר) ───────────────────────────────────────────── */

function hostOfUrl(string $url): string {
    return (string) (normalizeUrl($url)['host'] ?? '');
}

/**
 * ניווט שהשרת הכריע עליו. חסימה — תמיד, עם הכתובת המלאה. מותר — לפי הרמה:
 * full: שורה ביומן; summary: רק +1 כניסה לאתר; off: כלום.
 */
function activityNav(int $childId, string $url, array $d, string $tz): void {
    if (!$d['allow']) { audit($childId, 'nav', false, $url, $d['code']); return; }
    $level = reportLevel($childId);
    if ($level === 'off') return;
    if ($level === 'full') audit($childId, 'nav', true, $url, $d['code']);
    $host = hostOfUrl($url);
    if ($host === '') return;
    $day = todayIn($tz);
    q('INSERT OR IGNORE INTO site_usage (user_id, day, host) VALUES (?, ?, ?)', [$childId, $day, $host]);
    q('UPDATE site_usage SET visits = visits + 1 WHERE user_id = ? AND day = ? AND host = ?', [$childId, $day, $host]);
}

/**
 * פעימה עם הכתובת שעל המסך: השניות נזקפות לאתר, וב"מלא" — גם לכתובת האחרונה
 * שנפתחה באותו אתר (כך "כמה זמן" נמדד מהמסך, לא מוערך מהפער בין ניווטים).
 */
function activityBeat(int $childId, string $url, int $seconds, string $tz): void {
    if ($seconds <= 0) return;
    $level = reportLevel($childId);
    $host = hostOfUrl($url);
    if ($level === 'off' || $host === '') return;
    $day = todayIn($tz);
    q('INSERT OR IGNORE INTO site_usage (user_id, day, host) VALUES (?, ?, ?)', [$childId, $day, $host]);
    q('UPDATE site_usage SET seconds = seconds + ? WHERE user_id = ? AND day = ? AND host = ?', [$seconds, $childId, $day, $host]);
    if ($level !== 'full') return;
    $last = one("SELECT id, url FROM audit WHERE user_id = ? AND kind = 'nav' AND allowed = 1 ORDER BY id DESC LIMIT 1", [$childId]);
    if ($last && hostOfUrl((string) $last['url']) === $host) {
        q('UPDATE audit SET seconds = seconds + ? WHERE id = ?', [$seconds, (int) $last['id']]);
    }
}

/** ניקוי לפי תקופות השמירה. זול — רץ מדי פעם, לא בכל בקשה. */
function activityCleanup(): void {
    $cut = gmdate('Y-m-d\TH:i:s\Z', time() - ACTIVITY_KEEP_DAYS * 86400);
    q('DELETE FROM audit WHERE at < ?', [$cut]);
    $day = gmdate('Y-m-d', time() - ACTIVITY_SUMMARY_DAYS * 86400);
    q('DELETE FROM site_usage WHERE day < ?', [$day]);
    q('DELETE FROM usage WHERE day < ?', [$day]);
}

/* ── קריאה (להורה) ─────────────────────────────────────────────── */

/**
 * הלשונית "פעילות": זמן לכל יום, האתרים המובילים, החסימות, וההיסטוריה (ב"מלא").
 * ‏$days — 7 או 30. הימים לפי אזור הזמן של הילד, וכל יום מופיע — גם יום ריק.
 */
function activityReport(int $accountId, int $childId, int $days = 7): array {
    $days = $days === 30 ? 30 : 7;
    $tz = (string) policyFor($childId)['timezone'];
    $level = reportLevel($childId);
    $today = new DateTimeImmutable('today', zoneOf($tz));
    $from = $today->modify('-' . ($days - 1) . ' days')->format('Y-m-d');

    $byDay = [];
    foreach (all('SELECT day, seconds FROM usage WHERE user_id = ? AND day >= ?', [$childId, $from]) as $r) {
        $byDay[$r['day']] = (int) $r['seconds'];
    }
    $daily = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = $today->modify("-$i days")->format('Y-m-d');
        $daily[] = ['day' => $d, 'seconds' => $byDay[$d] ?? 0];
    }

    $sites = $level === 'off' ? [] : array_map(fn($r) => [
        'host' => $r['host'], 'seconds' => (int) $r['s'], 'visits' => (int) $r['v'],
    ], all('SELECT host, SUM(seconds) s, SUM(visits) v FROM site_usage WHERE user_id = ? AND day >= ?
            GROUP BY host ORDER BY s DESC, v DESC LIMIT 30', [$childId, $from]));

    // הגבול לפי UTC של תחילת היום הראשון באזור הזמן של הילד.
    $since = (new DateTimeImmutable($from, zoneOf($tz)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    $blocks = array_map(fn($r) => [
        'at' => $r['at'], 'url' => $r['url'], 'host' => hostOfUrl((string) $r['url']) ?: $r['url'],
        'reason' => BLOCK_LABELS[$r['code']] ?? 'נחסם',
    ], all("SELECT at, url, code FROM audit WHERE user_id = ? AND kind IN ('nav','check') AND allowed = 0 AND at >= ?
            ORDER BY at DESC, id DESC LIMIT 60", [$childId, $since]));
    $blockedCount = (int) one("SELECT COUNT(*) n FROM audit WHERE user_id = ? AND kind IN ('nav','check') AND allowed = 0 AND at >= ?",
                              [$childId, $since])['n'];

    $history = $level !== 'full' ? [] : array_map(fn($r) => [
        'at' => $r['at'], 'url' => $r['url'], 'seconds' => (int) $r['seconds'],
    ], all("SELECT at, url, seconds FROM audit WHERE user_id = ? AND kind = 'nav' AND allowed = 1 AND at >= ?
            ORDER BY at DESC, id DESC LIMIT 300", [$childId, $since]));

    return [
        'level' => $level, 'levels' => reportLevelsFor($accountId), 'days' => $days, 'timezone' => $tz,
        'daily' => $daily, 'total_sec' => array_sum(array_column($daily, 'seconds')),
        'sites' => $sites, 'blocks' => $blocks, 'blocked_count' => $blockedCount, 'history' => $history,
    ];
}
