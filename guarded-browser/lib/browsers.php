<?php
/**
 * מסך "🌐 דפדפנים" אצל ההורה (בעל המערכת, 2026-10-24): אילו דפדפנים יש במכשירים של הילד, ומה כל אחד מקבל.
 *
 *   הכללים (קטגוריות ואתרים) הם של הילד, אחד לכל הדפדפנים שאפשר לסנן:
 *     🗼 מגדלור — מסנן מלא: כל ההגדרות, בכל מכשיר.
 *     כרום — "מנוהל" (lib/chrome.php): אותן רשימות אתרים וקטגוריות, חיפוש בטוח, בלי גלישה בסתר — רק ב"בעל מכשיר".
 *     כל דפדפן אחר — אין לאנדרואיד דרך לסנן בתוכו; אפשר רק להתיר או לחסום אותו כאפליקציה.
 *   הרשימה נבנית מהדוח של המכשיר (EnforceReport.browsers) — רק מה שבאמת מותקן.
 */

declare(strict_types=1);

require_once __DIR__ . '/appcontrol.php';
require_once __DIR__ . '/chrome.php';

/** [{package, name, kind: migdalor|chrome|other, action: ''|allow|block|hide, blocked, managed, devices: [שם]}] */
function browsersFor(int $accountId, int $childId): array {
    $out = [SELF_PACKAGE => ['package' => SELF_PACKAGE, 'name' => 'מגדלור', 'kind' => 'migdalor', 'action' => 'allow',
                             'blocked' => false, 'managed' => true, 'devices' => []]];
    $apps = accountAppsAllowed($accountId);
    if (!$apps) return ['apps' => false, 'browsers' => array_values($out), 'reported' => false];
    $rules = appRules($childId);
    $posture = appPosture($childId);
    $managed = chromeManaged($childId);
    $reported = false;
    foreach (all('SELECT device_name, enforce_report FROM devices WHERE user_id = ?', [$childId]) as $d) {
        $rep = json_decode((string) $d['enforce_report'], true);
        if (!is_array($rep) || !isset($rep['browsers'])) continue;
        $reported = true;
        foreach ((array) $rep['browsers'] as $b) {
            $pkg = cleanPackage((string) ($b['p'] ?? ''));
            if ($pkg === '' || $pkg === SELF_PACKAGE) continue;
            $chrome = in_array($pkg, CHROME_PACKAGES, true);
            $action = (string) ($rules[$pkg] ?? '');
            $out[$pkg] ??= ['package' => $pkg, 'name' => mb_substr((string) ($b['n'] ?? $pkg), 0, 60) ?: $pkg,
                            'kind' => $chrome ? 'chrome' : 'other', 'action' => $action,
                            'blocked' => appBlocked($posture, $action), 'managed' => $chrome && $managed, 'devices' => []];
            $out[$pkg]['devices'][] = (string) $d['device_name'];
        }
    }
    // הסדר: מגדלור, כרום, השאר לפי שם.
    $list = array_values($out);
    usort($list, fn($a, $b) => [array_search($a['kind'], ['migdalor', 'chrome', 'other'], true), $a['name']]
                             <=> [array_search($b['kind'], ['migdalor', 'chrome', 'other'], true), $b['name']]);
    return ['apps' => true, 'browsers' => $list, 'reported' => $reported, 'chrome' => chromeStatus($childId)];
}
