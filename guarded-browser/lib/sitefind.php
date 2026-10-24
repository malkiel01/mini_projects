<?php
/**
 * פתיחת אתר ספציפי — אצל ההורה (בעל המערכת, 2026-10-24).
 *
 *   ההורה מחפש אתר — בחיפוש של מגדלור (Brave, אותו מפתח של חיפוש הילדים) או בכתובת — ורואה לכל תוצאה:
 *   תחת אילו קטגוריות היא יושבת ומה מצבן אצל הילד (מותרת / חסומה / בלי הגדרה), ומה הילד מקבל *עכשיו* — מהמנוע
 *   האמיתי (simulateSite), לא מהעתק. "פתיחה" מוסיפה כלל היתר, והוא גובר על קטגוריה חסומה (matchUrlRules לפני
 *   matchCategories) — כך אתר נפתח בלי לפתוח את כל הקטגוריה שלו.
 *
 *   חיפוש בגוגל — אין לגוגל API פתוח (Custom Search נסגר לחדשים), ולכן הוא נפתח אצל ההורה בלשונית, ומשם מדביקים
 *   את הכתובת לכאן.
 */

declare(strict_types=1);

require_once __DIR__ . '/simulator.php';
require_once __DIR__ . '/search.php';
require_once __DIR__ . '/catalog.php';

const SITEFIND_MAX = 10;

/** האם הטקסט הוא כתובת (או שם שרת) ולא מילות חיפוש. */
function siteLooksLikeUrl(string $q): bool {
    $q = trim($q);
    if ($q === '' || preg_match('/\s/u', $q)) return false;
    if (preg_match('#^https?://#i', $q)) return true;
    return (bool) preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+(\/.*)?$/i', $q);
}

/** כרטיס לתוצאה אחת: השרת, הקטגוריות ומצבן אצל הילד, ומה הילד מקבל עכשיו. */
function siteCard(array $child, string $url, string $title = '', string $snippet = ''): ?array {
    if (!preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url)) $url = 'https://' . $url;
    $u = normalizeUrl($url);
    if (!$u || !preg_match('/^[a-z0-9.-]+\.[a-z0-9-]+$/i', $u['host'])) return null;
    $host = preg_replace('/^www\./', '', $u['host']);
    $catRules = categoryRulesFor((int) $child['id']);
    $cats = [];
    foreach (categoriesOfHost($host, domainMap()) as $c) {
        $cats[] = ['key' => $c, 'label' => categoryLabel($c), 'icon' => categoryIcon($c), 'state' => (string) ($catRules[$c] ?? '')];
    }
    $sim = simulateSite($child, 'https://' . $host . '/');
    // כלל קיים על השרת הזה (פעיל) — "פתוח באישור שלכם" / "חסום על ידכם".
    $rule = '';
    foreach (rulesFor((int) $child['id']) as $r) {
        if (!(int) ($r['enabled'] ?? 1)) continue;
        $p = normalizeUrl((string) $r['pattern']);
        if ($p && hostMatches($host, preg_replace('/^www\./', '', $p['host']))) { $rule = (string) $r['action']; break; }
    }
    return ['url' => 'https://' . $host . '/', 'page' => $url, 'host' => $host, 'title' => mb_substr($title, 0, 120),
            'snippet' => mb_substr($snippet, 0, 200), 'categories' => $cats,
            'allow' => (bool) $sim['allow'], 'where' => (string) $sim['where'], 'reason' => (string) $sim['reason'], 'rule' => $rule];
}

/**
 * מחזיר ['engine' => url|migdalor|none, 'results' => [...], 'note' => '', 'google' => קישור לחיפוש בגוגל].
 * תוצאות מאותו שרת — פעם אחת (ההיתר הוא לשרת).
 */
function siteFind(array $child, string $q, ?callable $braveFetch = null): array {
    $q = trim(mb_substr($q, 0, 200));
    $google = 'https://www.google.com/search?' . http_build_query(['q' => $q]);
    if ($q === '') return ['engine' => 'none', 'results' => [], 'note' => 'כתבו שם של אתר או כתובת', 'google' => $google];
    if (siteLooksLikeUrl($q)) {
        $c = siteCard($child, $q);
        return ['engine' => 'url', 'results' => $c ? [$c] : [], 'note' => $c ? '' : 'הכתובת אינה תקינה', 'google' => $google];
    }
    $rows = braveSearch($q, $braveFetch);
    if ($rows === null) {
        return ['engine' => 'none', 'results' => [], 'google' => $google,
                'note' => 'החיפוש של מגדלור לא זמין כרגע. חפשו בגוגל, העתיקו את הכתובת והדביקו אותה כאן.'];
    }
    $out = []; $seen = [];
    foreach ($rows as $r) {
        $c = siteCard($child, (string) $r['url'], (string) $r['title'], (string) $r['body']);
        if (!$c || isset($seen[$c['host']])) continue;
        $seen[$c['host']] = true;
        $out[] = $c;
        if (count($out) >= SITEFIND_MAX) break;
    }
    return ['engine' => 'migdalor', 'results' => $out, 'note' => $out ? '' : 'לא נמצא. נסו מילים אחרות, או חפשו בגוגל.', 'google' => $google];
}
