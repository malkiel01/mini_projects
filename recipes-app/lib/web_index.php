<?php
/**
 * חיפוש מתכונים ברשת — באינדקס משלנו, של אתרים שהמפתח בחר.
 *
 * למה אינדקס משלנו ולא גוגל: מנוע חיפוש הוא אינדקס שנבנה מראש, ואת זה של
 * כל הרשת אי אפשר לבנות בשרת אחד, ולגרד את התוצאות של גוגל אסור. אבל כל
 * אתר מתכונים מפרסם sitemap — רשימה של כל המתכונים שלו, עם השם בכתובת
 * (בעברית, ב-WordPress). קריאה שלה היא בקשה מנומסת אחת לכל חלק.
 *
 * המחזור:
 *   1. המפתח מוסיף אתר (דף הבית או ה-sitemap). המערכת מוצאת את ה-sitemap
 *      (ב-robots.txt, או בכתובות המקובלות), ובוחרת את החלקים של המתכונים
 *      (recipe-sitemap1.xml, wp-sitemap-posts-foody_recipe-1.xml…).
 *   2. העדכון רץ ברקע (index-worker.php), חלק אחד בכל פעם, עם מרווח בין
 *      הבאות ובכבוד ל-robots.txt ולחסימות (importFetch).
 *   3. פעם בשבוע — שוב. חלק שתאריך העדכון שלו (lastmod) לא השתנה, לא מובא.
 *      מתכון שנעלם מהאתר — נמחק מהאינדקס.
 *   4. חיפוש: לפי גזעי המילים בשם (pantryStem) — "עוגות גבינה" מוצא "עוגת גבינה".
 *   5. פתיחה: הדף מובא, מחולץ, והשלבים מנוסחים מחדש בבינה. נשמר במטמון שבוע
 *      לכולם — פתיחה שנייה לא פונה לאתר ולא עולה כסף.
 *   6. שמירה: המתכון נשמר אצל המשתמש, פרטי, עם קרדיט וקישור למקור.
 *
 * מה לא: תמונות לא מועתקות (קישור בלבד), המשתמש רואה שם, אתר וקישור בתוצאות —
 * כמו מנוע חיפוש. ולמשתמש רגיל יש מכסה יומית של פתיחות (WEB_DAILY_LIMIT).
 */

declare(strict_types=1);

require_once __DIR__ . '/scout_job.php';
require_once __DIR__ . '/pantry.php';

const INDEX_REFRESH_DAYS   = 7;
const INDEX_MAX_SITEMAPS   = 40;    // חלקים לאתר — מעבר לזה זה כבר לא אתר מתכונים רגיל
const INDEX_RETRY_HOURS    = 6;     // אחרי כשל — מנסים שוב בעוד
const INDEX_WORKER_BUDGET  = 200;
const WEB_CACHE_DAYS       = 7;
const WEB_LOCAL_MAX        = 10;   // מתכונים מהאפליקציה בראש החיפוש ברשת
const INDEX_TITLE_GAP      = 20;    // שניות בין שתי הבאות של שם מאותו אתר — לאט, כי זה דף שלם ולא רשימה
if (!defined('WEB_DAILY_LIMIT')) define('WEB_DAILY_LIMIT', 20);   // פתיחות חדשות ליום למשתמש רגיל

// חלקי sitemap שאינם מתכונים — דפים, תגיות, כותבים, רכיבים, מוצרים
const INDEX_SKIP_SITEMAP = '~(page|category|categories|tag|author|user|attachment|product|media|image|video|ingredient|accessor|technique|filter|taxonom|poll|local|news|wpml)~i';
// כתובות שאינן מתכון בודד
const INDEX_SKIP_URL = '~/(category|tag|author|page|feed|search|cart|login|wp-|[a-z_]*ingredient|[a-z_]*accessory|[a-z_]*technique|[a-z_]*filter)(/|$)~i';

// ─────────────────────────────────────────────────────────────
// אתרים
// ─────────────────────────────────────────────────────────────

function indexSites(): array {
    $rows = db()->query('SELECT * FROM index_sites ORDER BY name')->fetchAll();
    $now = time();
    $counts = [];
    foreach (db()->query("SELECT site_id, SUM(needs_title = 1 AND skip = 0) pending, SUM(skip) skipped, SUM(skip = 0 AND title != '' AND needs_title = 0) ready,
                                  SUM(skip = 0 AND image IS NOT NULL AND image != '') images,
                                  SUM(skip = 0 AND needs_title = 0 AND image IS NULL) images_pending FROM index_entries GROUP BY site_id")->fetchAll() as $c) {
        $counts[(int) $c['site_id']] = ['pending' => (int) $c['pending'], 'skipped' => (int) $c['skipped'], 'ready' => (int) $c['ready'], 'images' => (int) $c['images'], 'images_pending' => (int) $c['images_pending']];
    }
    return array_map(function ($r) use ($now, $counts) {
        $st = $r['crawl_state'] ? (json_decode($r['crawl_state'], true) ?: []) : null;
        return [
            'id' => (int) $r['id'], 'host' => $r['host'], 'name' => $r['name'], 'start_url' => $r['start_url'],
            'enabled' => (bool) $r['enabled'], 'added_at' => $r['added_at'], 'last_crawl_at' => $r['last_crawl_at'],
            'last_error' => $r['last_error'], 'entries_n' => $counts[(int) $r['id']]['ready'] ?? 0,   // בחיפוש עכשיו, עם שם
            'next_in' => max(0, (int) $r['next_crawl_at'] - $now),
            'crawling' => $st !== null,
            'progress' => $st ? ['done' => (int) ($st['done'] ?? 0), 'total' => (int) ($st['total'] ?? 0)] : null,
            'titles_pending' => $counts[(int) $r['id']]['pending'] ?? 0,
            'not_recipes'    => $counts[(int) $r['id']]['skipped'] ?? 0,
            'with_image'     => $counts[(int) $r['id']]['images'] ?? 0,
            'images_pending' => $counts[(int) $r['id']]['images_pending'] ?? 0,
        ];
    }, $rows);
}

function indexSitesStatus(array $developer): array {
    requireDeveloper($developer);
    $total = (int) db()->query("SELECT COUNT(*) FROM index_entries e JOIN index_sites s ON s.id = e.site_id WHERE s.enabled = 1 AND e.skip = 0 AND e.title != ''")->fetchColumn();
    return ['sites' => indexSites(), 'total' => $total, 'refresh_days' => INDEX_REFRESH_DAYS, 'title_gap' => INDEX_TITLE_GAP];
}

function indexAddSite(string $url, string $name, array $developer): array {
    requireDeveloper($developer);
    $url = trim($url);
    if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $url)) $url = 'https://' . $url;   // "carine.co.il" → https://carine.co.il
    importCheckUrl($url);
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $name = mb_substr(trim($name), 0, 60) ?: preg_replace('/^www\./', '', $host);
    $st = db()->prepare('SELECT id FROM index_sites WHERE host = ?');
    $st->execute([$host]);
    if ($st->fetchColumn()) throw new AppError("האתר $host כבר באינדקס");
    db()->prepare('INSERT INTO index_sites (host, name, start_url, added_at, next_crawl_at) VALUES (?,?,?,?,0)')
        ->execute([$host, $name, $url, nowIso()]);
    indexRememberBase();
    logEvent('info', 'index-site-add', $host, ['host' => $host], $developer);
    indexKick(true);   // פעולה ידנית — מעירים תמיד
    return indexSitesStatus($developer);
}

/**
 * שורה מהרשימה → [כתובת, שם]. הכתובת היא המילה שנראית כמו דומיין או קישור;
 * כל השאר — השם. כך כל הצורות עובדות: "www.10dakot.co.il 10 דקות",
 * "10 דקות — www.10dakot.co.il", "קרין גורן | https://www.carine.co.il/".
 */
function indexParseSiteLine(string $line): ?array {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) return null;
    if (!preg_match('~(?:https?://)?(?:(?:[a-z0-9-]+\.)+[a-z]{2,}|\d{1,3}(?:\.\d{1,3}){3})(?::\d+)?(?:/[^\s|,]*)?~i', $line, $m)) return ['', $line];
    $name = trim(str_replace($m[0], ' ', $line));
    $name = trim(preg_replace('/^[\s|,:;–—\-]+|[\s|,:;–—\-]+$/u', '', $name) ?? $name);
    return [$m[0], mb_substr(preg_replace('/\s+/u', ' ', $name) ?? $name, 0, 60)];
}

/**
 * הוספה של כמה אתרים בבת אחת — שורה לכל אתר, עם שם בעברית אם רוצים. אתר שכבר
 * באינדקס ונכתב לו שם — השם מתעדכן. מחזיר תוצאה לכל שורה, ומעיר את העובד פעם אחת.
 */
function indexAddSites(string $text, array $developer): array {
    requireDeveloper($developer);
    $results = [];
    $added = 0;
    foreach (array_slice(preg_split('/\r?\n/', $text) ?: [], 0, 100) as $line) {
        $p = indexParseSiteLine($line);
        if ($p === null) continue;
        [$url, $name] = $p;
        $r = ['line' => mb_substr(trim($line), 0, 120), 'name' => $name];
        if ($url === '') { $results[] = $r + ['status' => 'error', 'message' => 'לא מצאתי כתובת בשורה']; continue; }
        try {
            $u = preg_match('~^[a-z][a-z0-9+.-]*://~i', $url) ? $url : 'https://' . $url;
            importCheckUrl($u);
            $host = strtolower((string) parse_url($u, PHP_URL_HOST));
            $r['host'] = $host;
            $st = db()->prepare('SELECT id, name FROM index_sites WHERE host = ? OR host = ? OR host = ?');
            $st->execute([$host, 'www.' . preg_replace('/^www\./', '', $host), preg_replace('/^www\./', '', $host)]);
            $ex = $st->fetch();
            $st->closeCursor();
            if ($ex) {
                if ($name !== '' && $name !== $ex['name']) {
                    db()->prepare('UPDATE index_sites SET name = ? WHERE id = ?')->execute([$name, (int) $ex['id']]);
                    $results[] = $r + ['status' => 'renamed', 'message' => 'כבר באינדקס — השם עודכן'];
                } else $results[] = $r + ['status' => 'exists', 'message' => 'כבר באינדקס'];
                continue;
            }
            db()->prepare('INSERT INTO index_sites (host, name, start_url, added_at, next_crawl_at) VALUES (?,?,?,?,0)')
                ->execute([$host, $name !== '' ? $name : preg_replace('/^www\./', '', $host), $u, nowIso()]);
            $added++;
            $results[] = $r + ['status' => 'added', 'message' => 'נוסף'];
        } catch (AppError $e) {
            $results[] = $r + ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    if (!$results) throw new AppError('הרשימה ריקה — כתובת בכל שורה, ואפשר שם בעברית לידה');
    if ($added) {
        indexRememberBase();
        logEvent('info', 'index-site-add', "נוספו $added אתרים", ['count' => $added], $developer);
        indexKick(true);
    }
    return indexSitesStatus($developer) + ['results' => $results];
}

function indexRemoveSite(int $id, array $developer): array {
    requireDeveloper($developer);
    db()->prepare('DELETE FROM index_sites WHERE id = ?')->execute([$id]);   // CASCADE: החלקים והמתכונים
    logEvent('info', 'index-site-remove', '', ['id' => $id], $developer);
    return indexSitesStatus($developer);
}

function indexToggleSite(int $id, bool $on, array $developer): array {
    requireDeveloper($developer);
    db()->prepare('UPDATE index_sites SET enabled = ? WHERE id = ?')->execute([$on ? 1 : 0, $id]);
    if ($on) indexKick(true);
    return indexSitesStatus($developer);
}

function indexRefreshNow(int $id, array $developer): array {
    requireDeveloper($developer);
    db()->prepare('UPDATE index_sites SET next_crawl_at = 0, last_error = NULL WHERE id = ?')->execute([$id]);
    indexRememberBase();
    indexKick(true);   // פעולה ידנית — מעירים תמיד
    return indexSitesStatus($developer);
}

// ─────────────────────────────────────────────────────────────
// sitemap: גילוי, בחירת חלקים, קריאה
// ─────────────────────────────────────────────────────────────

/** אותו אתר — עם www או בלי (gad-dairy רשום כ-www, וה-sitemap שלו בלי). */
function indexSameHost(string $url, string $host): bool {
    $strip = fn(string $h) => preg_replace('/^www\./', '', strtolower($h));
    return $strip((string) parse_url($url, PHP_URL_HOST)) === $strip($host);
}

/** <sitemapindex> → [[url, lastmod]] ; <urlset> → null. */
function indexParseSitemapIndex(string $xml): ?array {
    if (stripos($xml, '<sitemapindex') === false) return null;
    $out = [];
    if (preg_match_all('~<sitemap>(.*?)</sitemap>~is', $xml, $m)) {
        foreach ($m[1] as $block) {
            if (!preg_match('~<loc>\s*([^<\s]+)\s*</loc>~i', $block, $l)) continue;
            $lm = preg_match('~<lastmod>\s*([^<\s]+)\s*</lastmod>~i', $block, $x) ? $x[1] : null;
            $out[] = [html_entity_decode($l[1]), $lm];
        }
    }
    return $out;
}

/** <urlset> → [[url, lastmod]]. */
function indexParseUrlset(string $xml): array {
    $rows = [];
    $seen = [];   // כמה דפים מצביעים על כל תמונה
    if (preg_match_all('~<url>(.*?)</url>~is', $xml, $m)) {
        foreach ($m[1] as $block) {
            if (!preg_match('~<loc>\s*([^<\s]+)\s*</loc>~i', $block, $l)) continue;
            $lm = preg_match('~<lastmod>\s*([^<\s]+)\s*</lastmod>~i', $block, $x) ? $x[1] : null;
            $imgs = [];
            if (preg_match_all('~<image:loc>\s*(?:<!\[CDATA\[)?\s*([^<\]\s]+)~i', $block, $im)) {
                foreach ($im[1] as $u) if ($u = indexImageUrl(html_entity_decode($u))) $imgs[$u] = true;
            }
            foreach (array_keys($imgs) as $u) $seen[$u] = ($seen[$u] ?? 0) + 1;
            $rows[] = [html_entity_decode($l[1]), $lm, array_keys($imgs)];
        }
    }
    // התמונה של המתכון: הראשונה שאינה משותפת לכמה דפים (אייקון, באנר, "הערה למתכון")
    return array_map(function ($r) use ($seen) {
        foreach ($r[2] as $u) if ($seen[$u] <= 2) return [$r[0], $r[1], $u];
        return [$r[0], $r[1], null];
    }, $rows);
}

/** קישור לתמונה שמותר לשמור: http/https מלא, לא ארוך מדי. אחרת null. */
function indexImageUrl(string $u): ?string {
    $u = trim($u);
    return preg_match('~^https?://[^\s<>"\']{4,490}$~i', $u) ? $u : null;
}

/**
 * מאינדקס של sitemaps — החלקים שכנראה מכילים מתכונים. יש חלקים עם "recipe"
 * בשם (רוב אתרי המתכונים) — רק הם. אין — כולם חוץ מדפים, תגיות, כותבים וכו'
 * (בלוג שהמתכונים בו הם פוסטים).
 */
function indexPickSitemaps(array $children): array {
    $recipe = array_values(array_filter($children, fn($c) => preg_match('~recipe|recette|rezept|%d7%9e%d7%aa%d7%9b%d7%95%d7%9f|מתכון~i', basename((string) parse_url($c[0], PHP_URL_PATH)))));
    $pick = $recipe ?: array_values(array_filter($children, fn($c) => !preg_match(INDEX_SKIP_SITEMAP, basename((string) parse_url($c[0], PHP_URL_PATH)))));
    return array_slice($pick, 0, INDEX_MAX_SITEMAPS);
}

/** שם מתכון מהכתובת: הסלאג, מפוענח, בלי מקפים ובלי "-2" בסוף. '' כשאין שם שמיש. */
function indexTitleFromUrl(string $url): string {
    $slug = basename(rtrim(urldecode((string) parse_url($url, PHP_URL_PATH)), '/'));
    $slug = preg_replace('/\.(html?|php|aspx?)$/i', '', $slug) ?? $slug;
    if ($slug === '' || str_starts_with($slug, '__trashed') || preg_match('/^[\d\-_]+$/', $slug)) return '';
    $t = trim(preg_replace('/[-_]+/', ' ', $slug) ?? $slug);
    $t = trim(preg_replace('/\s+\d{1,3}$/', '', $t) ?? $t);   // "עוגת שוקולד 2"
    return preg_match_all('/\p{L}/u', $t) >= 3 ? mb_substr($t, 0, 140) : '';
}

function indexHasHebrew(string $t): bool {
    return preg_match_all('/\p{Hebrew}/u', $t) >= 2;
}

/**
 * שם מתכון מהדף → [שם להצגה, הטקסט המלא לחיפוש].
 *
 * דפים מוסיפים לשם סיומת של האתר ("… - עוגיו.נט", "… | ניקי ב") וזנב לגוגל
 * ("לבבות עוף עם בצל - הרוטב מעלף הכי מתאים ליד חלת השבת"). להצגה — החלק
 * הראשון, עד המפריד הראשון, כשיש בו לפחות שתי מילים. לחיפוש — הכול חוץ משם
 * האתר, כך שגם מילה מהזנב מוצאת את המתכון.
 */
function indexCleanTitle(string $t, array $siteNames = []): array {
    $t = importText($t);
    $t = trim(preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', '', $t) ?? $t);   // אימוג'י
    $parts = preg_split('/\s+[|–—\-:]\s+|\s*\|\s*/u', $t) ?: [$t];
    // חלק הוא "שם האתר" רק כשהוא קצר — כמעט רק השם. "קרין גורן מכינה עוגת יומולדת" אינו
    // שם האתר רק כי המוציא לאור הוא "קרין גורן" (זה קרה, והמתכון סומן "לא מתכון").
    $isSite = function (string $p) use ($siteNames): bool {
        $p = trim($p);
        if ($p === '') return true;
        if (str_word_count_u2($p) <= 3 && preg_match('/\.(net|com|co\.il|org)\b|www\./iu', $p)) return true;   // "עוגיו.נט", "nikib.co.il"
        foreach ($siteNames as $n) {
            $n = trim($n);
            if ($n === '') continue;
            if ((str_contains($p, $n) && mb_strlen($p) <= mb_strlen($n) + 6) || (mb_strlen($p) >= 2 && str_contains($n, $p))) return true;
        }
        return false;
    };
    // החלק הראשון הוא השם — לא נזרק, אלא אם יש אחריו משהו אחר
    $kept = [];
    foreach ($parts as $i => $p) if (!$isSite($p)) $kept[] = $p;
    if (!$kept && $parts) $kept = [$parts[0]];
    $parts = $kept;
    if (!$parts) return ['', ''];
    $full = trim(implode(' ', $parts));
    $show = trim($parts[0]);
    if (str_word_count_u2($show) < 2 && count($parts) > 1) $show = trim($parts[0] . ' ' . $parts[1]);   // "שוקופאי - אפוי" → שניהם
    $show = preg_replace('/^(מתכון\s+ל|מתכון:\s*)/u', '', $show) ?? $show;
    // "פשטידת פטריות חלבית מתכון פגז לאירוח" — "מתכון" באמצע פותח את הזנב לגוגל
    if (preg_match('/^(\S+(?:\s+\S+){1,})\s+מתכון(\s|$)/u', $show, $m) && str_word_count_u2($m[1]) >= 2) $show = $m[1];
    $show = trim(preg_replace('/[!?.,]+$/u', '', $show) ?? $show);
    return [mb_substr($show, 0, 120), mb_substr($full, 0, 300)];
}

/** גזעים לחיפוש — " גזע גזע " (רווח בהתחלה ובסוף, כדי לחפש לפי תחילת מילה). */
function indexStems(string $title): string {
    $out = [];
    foreach (preg_split('/\s+/u', normalizeText($title)) ?: [] as $w) {
        if ($w === '' || mb_strlen($w) < 2) continue;
        $out[] = pantryStem($w);
    }
    return ' ' . implode(' ', array_unique($out)) . ' ';
}

function indexFetchXml(string $url): string {
    if (!scoutRobotsAllowed($url)) throw new AppError('robots.txt של האתר אוסר את ' . $url);
    scoutThrottle($url);
    [$body] = importFetch($url);
    if (str_starts_with($body, "\x1f\x8b")) $body = (string) @gzdecode($body);   // sitemap.xml.gz
    if (!preg_match('~<(urlset|sitemapindex)\b~i', substr($body, 0, 4000))) throw new AppError('הכתובת אינה sitemap: ' . $url);
    return $body;
}

/**
 * מוצא את ה-sitemap של אתר: הכתובת שהודבקה (אם היא sitemap), robots.txt
 * (רק sitemap באותו אתר — carine מפנה ל-foody.co.il, וזה אתר אחר), ואז
 * הכתובות המקובלות. מחזיר את התור: [[url, lastmod]] של חלקי המתכונים.
 */
function indexDiscover(array $site): array {
    $base = 'https://' . $site['host'];
    if (preg_match('~^http://~i', $site['start_url'])) $base = 'http://' . parse_url($site['start_url'], PHP_URL_HOST) . (($p = parse_url($site['start_url'], PHP_URL_PORT)) ? ":$p" : '');
    $cands = [];
    $path = (string) parse_url($site['start_url'], PHP_URL_PATH);
    if ($path !== '' && $path !== '/') $cands[] = $site['start_url'];
    // robots.txt כבר במטמון מ-scoutRobotsAllowed
    scoutRobotsAllowed($base . '/');
    $robots = (string) @file_get_contents(scoutStateDir() . '/robots-' . md5($site['host']) . '.txt');
    if (preg_match_all('~^\s*sitemap:\s*(\S+)~im', $robots, $m)) {
        foreach ($m[1] as $u) if (indexSameHost($u, $site['host'])) $cands[] = $u;
    }
    foreach (['/sitemap_index.xml', '/wp-sitemap.xml', '/sitemap.xml'] as $p) $cands[] = $base . $p;

    $lastErr = null;
    foreach (array_unique($cands) as $u) {
        try { $xml = indexFetchXml($u); }
        catch (AppError $e) { if ($e->status === 429) throw $e; $lastErr = $e; continue; }
        $children = indexParseSitemapIndex($xml);
        if ($children === null) return [[$u, null]];   // urlset אחד — הוא עצמו
        $pick = indexPickSitemaps($children);
        if ($pick) return $pick;
    }
    throw new AppError('לא מצאתי sitemap של מתכונים באתר הזה' . ($lastErr ? ' (' . $lastErr->getMessage() . ')' : '') .
                       '. אפשר להדביק את כתובת ה-sitemap עצמה.');
}

// ─────────────────────────────────────────────────────────────
// עדכון האינדקס — צעד אחד בכל פעם, ממשיך בין תהליכים
// ─────────────────────────────────────────────────────────────

function indexSaveState(int $siteId, ?array $state, array $extra = []): void {
    $f = ['crawl_state' => $state === null ? null : json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)] + $extra;
    $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($f)));
    db()->prepare("UPDATE index_sites SET $sets WHERE id = ?")->execute([...array_values($f), $siteId]);
}

/**
 * צעד אחד באתר אחד: התחלה (גילוי ה-sitemap) או חלק אחד מהתור, או סיום.
 * מחזיר 'started' / 'sitemap' / 'skipped' / 'finished' / 'blocked' / 'failed'.
 */
function indexCrawlStep(array $site): string {
    $id = (int) $site['id'];
    $state = $site['crawl_state'] ? (json_decode($site['crawl_state'], true) ?: null) : null;
    try {
        if ($state === null) {
            $queue = indexDiscover($site);
            indexSaveState($id, ['queue' => $queue, 'started' => time(), 'done' => 0, 'total' => count($queue), 'failed' => 0]);
            return 'started';
        }
        if (!$state['queue']) {
            // סיום: מה שלא נראה בעדכון הזה — הוסר מהאתר. רק אם כל החלקים הצליחו.
            if (!$state['failed']) {
                db()->prepare('DELETE FROM index_entries WHERE site_id = ? AND seen_at < ?')->execute([$id, (int) $state['started']]);
            }
            $n = (int) db()->query("SELECT COUNT(*) FROM index_entries WHERE site_id = $id AND skip = 0")->fetchColumn();
            indexSaveState($id, null, ['entries_n' => $n, 'last_crawl_at' => nowIso(),
                'next_crawl_at' => time() + INDEX_REFRESH_DAYS * 86400,
                'last_error' => $state['failed'] ? $state['failed'] . ' חלקים נכשלו — מה שהיה בהם נשאר מהעדכון הקודם' : null]);
            logEvent('info', 'index-crawl', "{$site['host']}: $n מתכונים", ['host' => $site['host'], 'entries' => $n, 'failed' => (int) $state['failed']]);
            return 'finished';
        }

        [$url, $lastmod] = array_shift($state['queue']);
        $pdo = db();
        $st = $pdo->prepare('SELECT id, lastmod, entries_n FROM index_sitemaps WHERE site_id = ? AND url = ?');
        $st->execute([$id, $url]);
        $known = $st->fetch();
        $st->closeCursor();
        $state['done']++;

        // לא השתנה מאז העדכון הקודם — לא מביאים; המתכונים שלו "נראו"
        if ($known && $lastmod !== null && $known['lastmod'] === $lastmod && (int) $known['entries_n'] > 0) {
            $pdo->prepare('UPDATE index_entries SET seen_at = ? WHERE sitemap_id = ?')->execute([(int) $state['started'], (int) $known['id']]);
            indexSaveState($id, $state);
            return 'skipped';
        }

        try {
            $xml = indexFetchXml($url);
        } catch (AppError $e) {
            if ($e->status === 429) { array_unshift($state['queue'], [$url, $lastmod]); $state['done']--; throw $e; }
            $state['failed']++;
            // החלק נכשל — המתכונים שלו נשארים כמו שהיו
            if ($known) $pdo->prepare('UPDATE index_entries SET seen_at = ? WHERE sitemap_id = ?')->execute([(int) $state['started'], (int) $known['id']]);
            indexSaveState($id, $state, ['last_error' => $e->getMessage()]);
            return 'failed';
        }
        $nested = indexParseSitemapIndex($xml);
        if ($nested !== null) {   // אינדקס בתוך אינדקס — מוסיפים לתור
            $state['queue'] = array_merge($state['queue'], array_slice(indexPickSitemaps($nested), 0, INDEX_MAX_SITEMAPS));
            $state['total'] += count($nested);
            indexSaveState($id, $state);
            return 'sitemap';
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO index_sitemaps (site_id, url, lastmod, fetched_at) VALUES (?,?,?,?)
                           ON CONFLICT(site_id, url) DO UPDATE SET lastmod = excluded.lastmod, fetched_at = excluded.fetched_at')
                ->execute([$id, $url, $lastmod, nowIso()]);
            $smId = (int) $pdo->query('SELECT id FROM index_sitemaps WHERE site_id = ' . $id . ' AND url = ' . $pdo->quote($url))->fetchColumn();
            // שם מהדף (title_fixed) לא נדרס; "ממתין לשם" נשאר כך עד שהשם יובא; "לא מתכון" (skip) נשאר מסומן
            // תמונה: מה-sitemap כשיש; אחרת נשארת זו שנשמרה (מהדף, בשם או בפתיחה)
            $up = $pdo->prepare('INSERT INTO index_entries (site_id, sitemap_id, url, title, stems, lastmod, seen_at, needs_title, image) VALUES (?,?,?,?,?,?,?,?,?)
                                 ON CONFLICT(url) DO UPDATE SET sitemap_id = excluded.sitemap_id, lastmod = excluded.lastmod, seen_at = excluded.seen_at,
                                   image = COALESCE(excluded.image, index_entries.image),
                                   title = CASE WHEN index_entries.title_fixed THEN index_entries.title ELSE excluded.title END,
                                   stems = CASE WHEN index_entries.title_fixed THEN index_entries.stems ELSE excluded.stems END,
                                   needs_title = CASE WHEN index_entries.title_fixed OR index_entries.skip THEN 0 ELSE excluded.needs_title END');
            $n = 0;
            foreach (indexParseUrlset($xml) as [$u, $lm, $img]) {
                if (!indexSameHost($u, $site['host'])) continue;
                if (!scoutCleanUrl($u) || preg_match(INDEX_SKIP_URL, urldecode((string) parse_url($u, PHP_URL_PATH)))) continue;
                $title = indexTitleFromUrl($u);
                // בלי שם עברי בכתובת (ניקי ב׳: /main-course/41767/, אוגיו: apple_dessert) — השם יובא מהדף,
                // ברקע. עד אז: השם האנגלי מהכתובת אם יש (נמצא בחיפוש באנגלית), אחרת לא בחיפוש.
                $needs = !indexHasHebrew($title);
                $up->execute([$id, $smId, $u, $title, indexStems($title), $lm, (int) $state['started'], $needs ? 1 : 0, $img]);
                $n++;
            }
            $pdo->prepare('UPDATE index_sitemaps SET entries_n = ? WHERE id = ?')->execute([$n, $smId]);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        indexSaveState($id, $state);
        return 'sitemap';
    } catch (AppError $e) {
        if ($e->status === 429) {
            $until = importHostBlockedUntil($site['host']) ?? (time() + IMPORT_BLOCK_COOLDOWN);
            indexSaveState($id, $state, ['next_crawl_at' => $until + 60, 'last_error' => $e->getMessage()]);
            return 'blocked';
        }
        // כשל בהתחלה (אין sitemap): לא נשאר באמצע — מנסים שוב בעוד כמה שעות
        indexSaveState($id, null, ['next_crawl_at' => time() + INDEX_RETRY_HOURS * 3600, 'last_error' => $e->getMessage()]);
        return 'failed';
    }
}

/** האתר הבא שהגיע זמנו (או שעדכונו באמצע). */
function indexDueSite(): ?array {
    $st = db()->prepare('SELECT * FROM index_sites WHERE enabled = 1 AND next_crawl_at <= ? ORDER BY (crawl_state IS NULL), next_crawl_at LIMIT 1');
    $st->execute([time()]);
    $r = $st->fetch();
    $st->closeCursor();
    return $r ?: null;
}

// ─────────────────────────────────────────────────────────────
// שמות מהדף — למתכונים שבכתובת שלהם אין שם עברי
// ─────────────────────────────────────────────────────────────

function indexTitleNextFile(string $host): string { return scoutStateDir() . '/title-next-' . md5(strtolower($host)); }

/**
 * אתרים שיש בהם מתכונים שממתינים לשם או לתמונה (image IS NULL — לא נבדק; '' — נבדק ואין),
 * עם הזמן שבו מותר להביא את הדף הבא.
 */
function indexTitleHosts(): array {
    $rows = db()->query('SELECT s.host, s.name, COUNT(*) n FROM index_entries e JOIN index_sites s ON s.id = e.site_id
                         WHERE s.enabled = 1 AND s.crawl_state IS NULL AND e.skip = 0 AND (e.needs_title = 1 OR e.image IS NULL) GROUP BY s.id')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $ready = (int) @file_get_contents(indexTitleNextFile($r['host']));
        if ($blocked = importHostBlockedUntil($r['host'])) $ready = max($ready, $blocked);
        $out[] = ['host' => $r['host'], 'name' => $r['name'], 'pending' => (int) $r['n'], 'ready_at' => $ready];
    }
    return $out;
}

/**
 * צעד אחד: מתכון אחד שממתין לשם, מהאתר שתורו הגיע. מביא את הדף, ומחלץ
 * כמו בייבוא: יש מתכון — השם שלו; אין מתכון בדף — "לא מתכון" (skip), ולא
 * יחזור. מחזיר 'titled' / 'not-recipe' / 'failed' / 'blocked' / 'wait:N' / 'none'.
 */
function indexTitleStep(): string {
    $hosts = indexTitleHosts();
    if (!$hosts) return 'none';
    usort($hosts, fn($a, $b) => $a['ready_at'] <=> $b['ready_at']);
    $h = $hosts[0];
    $now = time();
    if ($h['ready_at'] > $now) return 'wait:' . ($h['ready_at'] - $now);

    // קודם שמות (בלי שם המתכון לא בחיפוש), אחר כך תמונות
    $st = db()->prepare('SELECT e.id, e.url, e.title, e.image, e.needs_title FROM index_entries e JOIN index_sites s ON s.id = e.site_id
                         WHERE s.host = ? AND e.skip = 0 AND (e.needs_title = 1 OR e.image IS NULL)
                         ORDER BY e.needs_title DESC, e.lastmod DESC, e.id DESC LIMIT 1');
    $st->execute([$h['host']]);
    $e = $st->fetch();
    $st->closeCursor();
    if (!$e) return 'none';
    $local = in_array($h['host'], ['127.0.0.1', 'localhost'], true);
    @file_put_contents(indexTitleNextFile($h['host']), (string) ($now + ($local ? 0 : INDEX_TITLE_GAP + random_int(0, 10))));

    $done = function (array $f) use ($e) {
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($f)));
        db()->prepare("UPDATE index_entries SET $sets WHERE id = ?")->execute([...array_values($f), (int) $e['id']]);
    };
    if (!$e['needs_title']) return indexImageStep($e, $done);
    try {
        if (!scoutRobotsAllowed($e['url'])) { $done(['needs_title' => 0, 'skip' => 1]); return 'not-recipe'; }
        scoutThrottle($e['url']);
        [$html, $final] = importFetch($e['url'], true);   // במטמון רבע שעה — פתיחה מיד אחר כך לא תביא שוב
        $doc = importDom($html);
        try { $raw = importParseDoc($doc, $final); }
        catch (AppError $ex) { $done(['needs_title' => 0, 'skip' => 1]); return 'not-recipe'; }   // אין מתכון בדף
        [$title, $full] = indexCleanTitle((string) ($raw['title'] ?? ''), [$h['name'], (string) ($raw['publisher'] ?? '')]);
        if (!indexHasHebrew($title) && preg_match_all('/\p{L}/u', $title) < 3) { $done(['needs_title' => 0, 'skip' => 1]); return 'not-recipe'; }
        // הגזעים: השם המלא מהדף, ועוד השם מהכתובת אם היה (כך "apple dessert" עדיין נמצא גם באנגלית)
        $f = ['title' => $title, 'stems' => indexStems($full . ' ' . $e['title']), 'title_fixed' => 1, 'needs_title' => 0];
        if ($e['image'] === null) $f['image'] = indexImageUrl((string) ($raw['images'][0] ?? '')) ?? '';   // הדף כבר כאן; '' — אין, לא לחזור
        $done($f);
        return 'titled';
    } catch (AppError $ex) {
        if ($ex->status === 429) return 'blocked';            // האתר חסם — השעה נשמרה; ממתינים
        if (preg_match('/שגיאה (404|410)/u', $ex->getMessage())) { db()->prepare('DELETE FROM index_entries WHERE id = ?')->execute([(int) $e['id']]); return 'failed'; }
        return 'failed';                                      // תקלה זמנית — ננסה שוב בתורו הבא
    }
}

/**
 * תמונה למתכון שיש לו שם אבל ב-sitemap לא הייתה תמונה (10 דקות, פודיס, קרין):
 * מביאים את הדף ולוקחים את og:image. בכל מקרה מסמנים — קישור, או '' (נבדק ואין;
 * גם בתקלה, כדי לא להיתקע על אותו דף). הדף לא נמחק כאן: מה שהוסר מהאתר יוצא
 * מה-sitemap בעדכון השבועי.
 */
function indexImageStep(array $e, callable $done): string {
    try {
        if (!scoutRobotsAllowed($e['url'])) { $done(['image' => '']); return 'no-image'; }
        scoutThrottle($e['url']);
        [$html, $final] = importFetch($e['url'], true);
        $img = indexPageImage($html, $final);
        $done(['image' => $img ?? '']);
        return $img ? 'imaged' : 'no-image';
    } catch (AppError $ex) {
        if ($ex->status === 429) return 'blocked';
        $done(['image' => '']);
        return 'failed';
    }
}

/** og:image (או twitter:image) מתוך HTML, כקישור מלא. null כשאין. */
function indexPageImage(string $html, string $base): ?string {
    $head = substr($html, 0, 200000);
    foreach (['og:image:secure_url', 'og:image', 'twitter:image'] as $p) {
        $q = preg_quote($p, '~');
        if (preg_match('~<meta\b[^>]*?(?:property|name)\s*=\s*["\']' . $q . '["\'][^>]*?content\s*=\s*["\']([^"\']+)~i', $head, $m)
            || preg_match('~<meta\b[^>]*?content\s*=\s*["\']([^"\']+)["\'][^>]*?(?:property|name)\s*=\s*["\']' . $q . '["\']~i', $head, $m)) {
            if ($u = indexImageUrl(importAbsolute(html_entity_decode($m[1]), $base))) return $u;
        }
    }
    return null;
}

/** יש עבודה לעובד עכשיו: אתר שהגיע זמנו, או שם/תמונה שאפשר להביא. */
function indexWorkReady(): bool {
    if (indexDueSite()) return true;
    foreach (indexTitleHosts() as $h) if ($h['ready_at'] <= time()) return true;
    return false;
}

/** גוף העובד: צעדים עד שנגמר התקציב, ואז שרשור. $once — צעד אחד (בדיקות). */
function indexWorkerRun(bool $once = false): string {
    $lock = fopen(scoutStateDir() . '/index.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return 'busy';
    $t0 = time();
    $last = 'idle';
    try {
        while (time() - $t0 < INDEX_WORKER_BUDGET) {
            $site = indexDueSite();
            if ($site) {
                // קודם עדכון האינדקס — הוא מה שמכניס מתכונים לחיפוש
                try { $last = indexCrawlStep($site); }
                catch (Throwable $e) {
                    error_log('recipes-app index worker: ' . $e->getMessage());
                    logEvent('warn', 'index-crawl-retry', get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 200), ['host' => $site['host']]);
                    indexSaveState((int) $site['id'], null, ['next_crawl_at' => time() + 3600, 'last_error' => 'תקלה זמנית — ננסה שוב בעוד שעה']);
                    $last = 'retry';
                }
                if ($once) break;
                continue;
            }
            // אחר כך שמות מהדף — לאט, אתר אחר אתר
            try { $last = indexTitleStep(); }
            catch (Throwable $e) { error_log('recipes-app index titles: ' . $e->getMessage()); $last = 'retry'; sleep(5); }
            if ($last === 'none' || $once) break;
            if (str_starts_with($last, 'wait:')) {
                $w = (int) substr($last, 5);
                if ($w > INDEX_WORKER_BUDGET - (time() - $t0) - 5) break;   // ההמתנה ארוכה — שומר-הסף יעיר כשיגיע הזמן
                sleep(max(1, $w));
            }
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    if (!$once && $last !== 'none' && indexWorkReady()) indexKick(true);
    return $last;
}

// ─────────────────────────────────────────────────────────────
// הערת העובד
// ─────────────────────────────────────────────────────────────

/** הכתובת של האפליקציה, לעובדים — נשמרת בשורת העבודה של הסורק. */
function indexRememberBase(): void {
    if ($base = scoutJobBaseUrl()) { scoutJobRow(); scoutJobUpdate(['base_url' => $base]); }
}

/** מעיר את עובד האינדקס. $force — גם אם העירו לפני רגע (השרשרת עצמה). */
function indexKick(bool $force = false): void {
    $mark = scoutStateDir() . '/index-kick';
    if (!$force && is_file($mark) && filemtime($mark) > time() - 120) return;   // לא יותר מפעם בשתי דקות
    @touch($mark);
    $j = scoutJobRow();
    if (!$j['base_url']) return;
    workerKick(rtrim($j['base_url'], '/') . '/index-worker.php?key=' . $j['worker_key'], 'recipes-app/index-worker');
}

/** שומר-סף: נקרא מ-api.php. יש אתר שהגיע זמנו — מעירים (לכל היותר פעם בשתי דקות). */
function indexWatchdog(): void {
    try { if (indexWorkReady()) indexKick(); }
    catch (Throwable $e) { error_log('recipes-app index watchdog: ' . $e->getMessage()); }
}

// ─────────────────────────────────────────────────────────────
// חיפוש
// ─────────────────────────────────────────────────────────────

/**
 * חיפוש ברשת. סדר התוצאות:
 *   1. מתכונים מתוך האפליקציה (שלי וציבוריים) — 'local', רק בעמוד הראשון.
 *   2. מהאינדקס: מה שמשתמשים שלנו הורידו (שמרו/ייבאו) — יותר משתמשים קודם.
 *   3. אחר כך מה שנפתח יותר אצלנו, ואז התאמה: הביטוי המדויק בשם, שם קצר.
 * דירוג מהאתרים עצמם לא נלקח: רק אחד מעשרה מפרסם, ושם הוא זהה בכל המתכונים.
 * $user — למתכונים מהאפליקציה; null (בדיקות) — בלי.
 */
function indexSearch(string $q, int $offset = 0, ?array $user = null): array {
    $q = trim(mb_substr($q, 0, 100));
    $stems = [];
    foreach (preg_split('/\s+/u', normalizeText($q)) ?: [] as $w) {
        if (mb_strlen($w) < 2 || in_array($w, ['של', 'עם', 'בלי', 'ללא', 'או', 'מתכון', 'מתכונים'], true)) continue;
        $stems[] = pantryStem($w);
    }
    $sites = (int) db()->query('SELECT COUNT(*) FROM index_sites WHERE enabled = 1')->fetchColumn();
    $all = (int) db()->query("SELECT COUNT(*) FROM index_entries e JOIN index_sites s ON s.id = e.site_id WHERE s.enabled = 1 AND e.skip = 0 AND e.title != ''")->fetchColumn();
    if (!$stems) return ['local' => [], 'results' => [], 'total' => 0, 'indexed' => $all, 'sites' => $sites];

    // גם בעמודים הבאים — כדי שההחרגה שלמטה תהיה זהה וההיסט לא יזוז
    $local = $user ? array_slice(searchRecipes($user, $q), 0, WEB_LOCAL_MAX) : [];
    $where = ['s.enabled = 1', 'e.skip = 0', "e.title != ''"];
    $params = [];
    // מה שכבר מוצג מהאפליקציה (מתכון שיובא מאותו דף) — לא פעמיים
    $mine = array_values(array_filter(array_column($local, 'source_url')));
    if ($mine) { $where[] = 'e.url NOT IN (' . implode(',', array_fill(0, count($mine), '?')) . ')'; array_push($params, ...$mine); }
    foreach (array_unique($stems) as $s) { $where[] = 'e.stems LIKE ?'; $params[] = '% ' . str_replace(['%', '_'], ['\\%', '\\_'], $s) . '%'; }
    $w = implode(' AND ', $where);
    $count = db()->prepare("SELECT COUNT(*) FROM index_entries e JOIN index_sites s ON s.id = e.site_id WHERE $w");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    // הורדות: כמה משתמשים שונים שמרו או ייבאו את הדף (מתכון שנמחק — כבר לא נספר)
    $st = db()->prepare("SELECT e.id, e.title, e.url, e.image, e.opens, s.name AS site, s.host, COALESCE(sv.n, 0) AS saves
                           FROM index_entries e JOIN index_sites s ON s.id = e.site_id
                           LEFT JOIN (SELECT source_url, COUNT(DISTINCT owner_id) n FROM recipes WHERE source_url IS NOT NULL GROUP BY source_url) sv ON sv.source_url = e.url
                         WHERE $w ORDER BY saves DESC, e.opens DESC, (e.title LIKE ?) DESC, length(e.title), e.id DESC LIMIT 30 OFFSET " . max(0, $offset));
    $st->execute([...$params, '%' . $q . '%']);
    return ['local' => $offset === 0 ? $local : [],
            'results' => array_map(fn($r) => ['id' => (int) $r['id'], 'title' => $r['title'], 'url' => $r['url'], 'site' => $r['site'], 'host' => $r['host'],
                                              'image' => $r['image'] ?: null, 'saves' => (int) $r['saves']],
                                   $st->fetchAll()),
            'total' => $total, 'indexed' => $all, 'sites' => $sites];
}

// ─────────────────────────────────────────────────────────────
// פתיחה ושמירה
// ─────────────────────────────────────────────────────────────

function webEntry(int $id): array {
    $st = db()->prepare('SELECT e.*, s.name AS site, s.enabled FROM index_entries e JOIN index_sites s ON s.id = e.site_id WHERE e.id = ?');
    $st->execute([$id]);
    $e = $st->fetch();
    $st->closeCursor();
    if (!$e || !$e['enabled']) throw new AppError('המתכון אינו באינדקס', 404);
    return $e;
}

/** כמה פתיחות חדשות (לא מהמטמון) נשארו למשתמש היום, לפי שעון ישראל. null — בלי הגבלה. */
function webOpensLeft(array $user): ?int {
    if (isDeveloper($user)) return null;
    $midnight = (new DateTimeImmutable('today', new DateTimeZone('Asia/Jerusalem')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    $st = db()->prepare("SELECT COUNT(*) FROM import_log WHERE user_id = ? AND kind = 'web-open' AND ok = 1 AND at >= ?");
    $st->execute([(int) $user['id'], $midnight]);
    return max(0, WEB_DAILY_LIMIT - (int) $st->fetchColumn());
}

/**
 * ניסוח מחדש של שלבי הטיוטה — העקרונות נשארים, הנוסח שלנו. משנה את הטיוטה
 * במקום; מסמן source_rewritten, rewrite_note ומתי ניסינו. מחזיר אם נוסח.
 */
function webRewriteDraft(array &$draft, ?array $user): bool {
    $rewritten = false;
    $note = null;
    $draft['rewrite_tried_at'] = time();
    if (aiAvailable()) {
        try {
            $secs = array_map(fn($s) => ['name' => $s['name'], 'steps' => array_column($s['steps'], 'text')], $draft['sections']);
            if (array_sum(array_map(fn($s) => count($s['steps']), $secs)) > 0) {
                $res = aiRewriteSteps($secs, $draft['title'], $user);
                if (!$res['too_close']) {
                    foreach ($res['sections'] as $i => $s) if (isset($draft['sections'][$i])) $draft['sections'][$i]['steps'] = array_map(fn($t) => ['text' => $t], $s['steps']);
                    $rewritten = true;
                } else $note = 'הניסוח יצא קרוב מדי למקור';
            }
        } catch (AppError $ex) { $note = $ex->getMessage(); }
    } else $note = 'ניסוח מחדש בבינה אינו מופעל';
    $draft['source_rewritten'] = $rewritten;
    $draft['rewrite_note'] = $note;
    return $rewritten;
}

/**
 * פתיחת תוצאה: מהמטמון, או הבאה + חילוץ + ניסוח מחדש. מחזיר את הטיוטה
 * כפי שהיא תישמר. מתכון שלא חולץ — שגיאה עם הקישור למקור.
 */
function webOpen(int $id, array $user): array {
    $e = webEntry($id);
    db()->prepare('UPDATE index_entries SET opens = opens + 1 WHERE id = ?')->execute([(int) $e['id']]);   // לסדר בחיפוש
    $st = db()->prepare('SELECT * FROM web_cache WHERE url = ? AND created_at > ?');
    $st->execute([$e['url'], time() - WEB_CACHE_DAYS * 86400]);
    $c = $st->fetch();
    $st->closeCursor();
    if ($c) {
        $draft = json_decode($c['draft'], true);
        $rewritten = (bool) $c['rewritten'];
        // לא נוסח (הבינה נכשלה — למשל נגמר הקרדיט): מנסים שוב, לכל היותר פעם בשעה, על
        // העותק שבמטמון — בלי להביא שוב מהאתר. או שהמפתח ניסח ידנית (rewriteApply).
        if (!$rewritten && aiAvailable() && time() - (int) ($draft['rewrite_tried_at'] ?? $c['created_at']) > 3600) {
            $rewritten = webRewriteDraft($draft, $user);
            db()->prepare('UPDATE web_cache SET draft = ?, rewritten = ? WHERE url = ?')
                ->execute([json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $rewritten ? 1 : 0, $e['url']]);
        }
        return ['draft' => $draft, 'rewritten' => $rewritten, 'cached' => true, 'opens_left' => webOpensLeft($user),
                'entry' => ['id' => (int) $e['id'], 'url' => $e['url'], 'site' => $e['site']]];
    }

    $left = webOpensLeft($user);
    if ($left === 0) throw new AppError('הגעת למכסת הפתיחות של היום (' . WEB_DAILY_LIMIT . '). מתכונים שכבר נפתחו — עדיין נפתחים. אפשר גם לפתוח באתר המקור.', 429);
    if (!scoutRobotsAllowed($e['url'])) throw new AppError('האתר לא מאפשר ייבוא אוטומטי של הדף הזה. אפשר לפתוח אותו באתר המקור.', 403);
    scoutThrottle($e['url']);
    $draft = importPreview($e['url'], 'web-open', $user, []);

    $rewritten = webRewriteDraft($draft, $user);
    unset($draft['import_log_id']);

    // השם האמיתי מהדף — טוב מהשם מהכתובת. ונשאר גם בעדכונים הבאים.
    if ($draft['title'] !== '' && $draft['title'] !== $e['title']) {
        db()->prepare('UPDATE index_entries SET title = ?, stems = ?, title_fixed = 1, needs_title = 0 WHERE id = ?')
            ->execute([mb_substr($draft['title'], 0, 140), indexStems($draft['title'] . ' ' . $e['title']), (int) $e['id']]);
    }
    // התמונה — לתוצאות החיפוש הבאות (מתכון שב-sitemap שלו אין תמונות: פודיס, קרין)
    if (!$e['image'] && ($img = indexImageUrl((string) ($draft['pending_media']['images'][0] ?? '')))) {   // NULL או ''
        db()->prepare('UPDATE index_entries SET image = ? WHERE id = ?')->execute([$img, (int) $e['id']]);
    }
    db()->prepare('INSERT INTO web_cache (url, draft, rewritten, created_at) VALUES (?,?,?,?)
                   ON CONFLICT(url) DO UPDATE SET draft = excluded.draft, rewritten = excluded.rewritten, created_at = excluded.created_at')
        ->execute([$e['url'], json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $rewritten ? 1 : 0, time()]);
    // ניקוי: מנוסח — אחרי שבוע; לא מנוסח — אחרי חודש (מחכה לניסוח ידני, rewrite_queue)
    if (random_int(1, 50) === 1) db()->prepare('DELETE FROM web_cache WHERE (rewritten = 1 AND created_at < ?) OR created_at < ?')
        ->execute([time() - WEB_CACHE_DAYS * 86400, time() - 30 * 86400]);

    return ['draft' => $draft, 'rewritten' => $rewritten, 'cached' => false, 'opens_left' => webOpensLeft($user),
            'entry' => ['id' => (int) $e['id'], 'url' => $e['url'], 'site' => $e['site']]];
}

/**
 * שמירה אצל המשתמש — בדיוק מה שהוצג (מהמטמון; השרת לא סומך על הדפדפן).
 * כבר שמר את אותו מקור — מחזיר את הקיים, לא יוצר כפילות.
 */
function webSave(int $id, array $user): array {
    $e = webEntry($id);
    $st = db()->prepare('SELECT id FROM recipes WHERE owner_id = ? AND source_url = ? LIMIT 1');
    $st->execute([(int) $user['id'], $e['url']]);
    $existing = $st->fetchColumn();
    $st->closeCursor();
    if ($existing) return ['recipe_id' => (int) $existing, 'existing' => true];

    $open = webOpen($id, $user);
    $d = $open['draft'];
    // המקור של הטיוטה הוא הכתובת הסופית (אחרי הפניות); הכפילות נבדקת גם מולה
    if (($d['source_url'] ?? '') !== $e['url']) {
        $st = db()->prepare('SELECT id FROM recipes WHERE owner_id = ? AND source_url = ? LIMIT 1');
        $st->execute([(int) $user['id'], (string) $d['source_url']]);
        $existing = $st->fetchColumn();
        $st->closeCursor();
        if ($existing) return ['recipe_id' => (int) $existing, 'existing' => true];
    }
    $d['visibility'] = 'private';
    $rid = saveRecipe($d, $user);
    importSaveSnapshot($rid, $d['snapshot'], $user);
    foreach ($d['pending_media']['images'] ?? [] as $u) { try { storeLink($rid, $user, $u, 'image'); } catch (Throwable $ex) {} }
    foreach ($d['pending_media']['videos'] ?? [] as $u) { try { storeLink($rid, $user, $u, 'video'); } catch (Throwable $ex) {} }
    importLogSaved($e['url'], $rid, $d, $user, 'web-save');
    return ['recipe_id' => $rid, 'existing' => false];
}
