<?php
/**
 * כרום מנוהל (בעל המערכת, 2026-10-22): אכיפה גם בתוך Google Chrome — לא רק בדפדפן של מגדלור.
 *
 *   כש"בעל מכשיר", מגדלור מעביר לכרום מדיניות ארגונית רשמית (managed configuration —
 *   ‏DevicePolicyManager.setApplicationRestrictions), כמו שארגונים מנהלים את כרום של העובדים:
 *     ‏URLBlocklist / URLAllowlist — מתוך כללי האתרים, הקטגוריות ויוטיוב של הילד.
 *     ‏ForceGoogleSafeSearch, ‏SafeSitesFilterBehavior (סינון תוכן למבוגרים של גוגל), ‏ForceYouTubeRestrict.
 *     ‏IncognitoModeAvailability=1 — בלי גלישה בסתר.
 *   לשוניות מותאמות (Custom Tabs) שאפליקציות אחרות פותחות בכרום — באותה מדיניות.
 *
 *   מה *לא* עובר לכרום (נשאר רק בדפדפן של מגדלור): זמנים ומכסה (בכרום — זמן מסך לאפליקציה), סוגי תוכן, חסימת
 *   פרסומות, ורשימות הערוצים והסרטונים של יוטיוב. לכן יוטיוב בכרום פתוח רק כשביוטיוב של הילד "הכול מותר";
 *   אחרת הוא חסום בכרום, וצופים בו דרך מגדלור — אין להחליש חסימה בגלל דפדפן אחר.
 *
 *   איסור גובר על היתר כמו במנוע (matchUrlRules): היתר שחל על שרת שיש עליו איסור לא נשלח, כי בכרום היתר
 *   ספציפי יותר גובר על חסימה.
 *
 *   כרום מנוהל אינו פותח את כרום: אם הוא חסום ברשימת האפליקציות — הוא נשאר חסום.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/policy.php';
require_once __DIR__ . '/platforms.php';

/** החבילות של כרום (יציב, בטא, מפתחים, קנרי) — כולן מקבלות את אותה מדיניות. */
const CHROME_PACKAGES = ['com.android.chrome', 'com.chrome.beta', 'com.chrome.dev', 'com.chrome.canary'];
/** כרום מקבל עד 1000 כתובות לכל רשימה. */
const CHROME_MAX_URLS = 1000;
const CHROME_YOUTUBE = ['youtube.com', 'youtu.be', 'youtube-nocookie.com'];

function chromeManaged(int $childId): bool {
    return (int) (policyFor($childId)['chrome_managed'] ?? 0) === 1;
}

function chromeManagedSave(int $childId, bool $on): void {
    q('UPDATE policies SET chrome_managed = ?, updated_at = ? WHERE user_id = ?', [$on ? 1 : 0, nowIso(), $childId]);
}

/**
 * כלל של מגדלור ← מסנן כתובת של כרום. ‏exact — שרת ונתיב (כרום: התחלת נתיב); ‏domain — השרת ותתי-השרתים.
 * ריק — כלל שאינו יעד גלישה (משאב של משימה) או שאינו תקין.
 */
function chromeFilter(array $rule): string {
    $scope = (string) ($rule['scope'] ?? 'domain');
    if ($scope === 'resource') return '';
    $u = normalizeUrl((string) $rule['pattern']);
    if (!$u || $u['host'] === '') return '';
    if ($scope !== 'exact' || $u['path'] === '' || $u['path'] === '/') return $u['host'];
    return $u['host'] . $u['path'];
}

/** האם השרת של המסנן נופל תחת אחד השרתים. */
function chromeUnder(string $filter, array $hosts): bool {
    $host = explode('/', $filter, 2)[0];
    foreach ($hosts as $h) if (hostMatches($host, $h)) return true;
    return false;
}

/**
 * המדיניות לכרום, או ['managed' => false]. ‏$set — לבדיקות (ברירת מחדל: ruleSetFor).
 */
function chromePolicy(int $childId, ?array $set = null): array {
    if (!chromeManaged($childId)) return ['managed' => false];
    $policy = policyFor($childId);
    $set ??= ruleSetFor($childId);
    $denyAll = ($policy['posture'] ?? POSTURE_DENY) === POSTURE_DENY;
    $deny = $allow = [];
    foreach ($set['rules'] as $r) {
        if (!(int) ($r['enabled'] ?? 1)) continue;
        $f = chromeFilter($r);
        if ($f === '') continue;
        if ($r['action'] === 'deny') $deny[$f] = true; else $allow[$f] = true;
    }
    // יוטיוב: רשימות הערוצים לא עוברות לכרום — לכן פתוח שם רק כשהכול מותר.
    $yt = (string) ($set['platforms'][PLATFORM_YOUTUBE]['mode'] ?? 'off');
    if ($yt !== 'full') foreach (CHROME_YOUTUBE as $h) $deny[$h] = true;
    // איסור מפורש (ויוטיוב) גובר על כל היתר — גם על היתר ספציפי יותר, שבכרום היה גובר עליו.
    $hard = array_map(fn($f) => explode('/', $f, 2)[0], array_keys($deny));
    // קטגוריות: איסור — לחסימה (ב"הכול חסום" מיותר: ‏* חוסם); היתר בלי איסור — להיתר. היתר מפורש על אתר
    // בקטגוריה אסורה נשאר מותר, כמו במנוע (כללי הכתובות לפני הקטגוריות).
    foreach ($set['domain_map'] as $domain => $cats) {
        $acts = array_map(fn($c) => $set['categories'][$c] ?? '', (array) $cats);
        if (in_array('deny', $acts, true)) { if (!$denyAll) $deny[(string) $domain] = true; }
        elseif (in_array('allow', $acts, true)) $allow[(string) $domain] = true;
    }
    $allow = array_values(array_filter(array_keys($allow), fn($f) => !chromeUnder($f, $hard)));
    $block = array_keys($deny);
    if ($denyAll) array_unshift($block, '*');
    sort($allow);

    return [
        'managed'   => true,
        'packages'  => CHROME_PACKAGES,
        'block'     => array_slice($block, 0, CHROME_MAX_URLS),
        'allow'     => array_slice($allow, 0, CHROME_MAX_URLS),
        'truncated' => count($block) > CHROME_MAX_URLS || count($allow) > CHROME_MAX_URLS,
        'youtube'   => $yt === 'full',
    ];
}

/** מה המכשיר מדווח על כרום (EnforceReport): אומת / נכשל / אין "בעל מכשיר" / כרום לא מותקן. */
const CHROME_RESULTS = ['verified', 'fail', 'no_owner', 'not_installed'];

/** להורה: האם מנוהל, ומה כל מכשיר דיווח (מהדוח האחרון). */
function chromeStatus(int $childId): array {
    $devices = [];
    foreach (all('SELECT device_name, perms, enforce_report FROM devices WHERE user_id = ?', [$childId]) as $d) {
        $rep = json_decode((string) $d['enforce_report'], true);
        $devices[] = ['name' => (string) $d['device_name'], 'owner' => (bool) ((int) $d['perms'] & 32),
                      'result' => is_array($rep) ? (string) ($rep['chrome'] ?? '') : ''];
    }
    $p = chromePolicy($childId);
    return ['managed' => $p['managed'], 'devices' => $devices, 'truncated' => $p['truncated'] ?? false,
            'youtube' => $p['youtube'] ?? false];
}
