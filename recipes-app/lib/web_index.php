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
    return array_map(function ($r) use ($now) {
        $st = $r['crawl_state'] ? (json_decode($r['crawl_state'], true) ?: []) : null;
        return [
            'id' => (int) $r['id'], 'host' => $r['host'], 'name' => $r['name'], 'start_url' => $r['start_url'],
            'enabled' => (bool) $r['enabled'], 'added_at' => $r['added_at'], 'last_crawl_at' => $r['last_crawl_at'],
            'last_error' => $r['last_error'], 'entries_n' => (int) $r['entries_n'],
            'next_in' => max(0, (int) $r['next_crawl_at'] - $now),
            'crawling' => $st !== null,
            'progress' => $st ? ['done' => (int) ($st['done'] ?? 0), 'total' => (int) ($st['total'] ?? 0)] : null,
        ];
    }, $rows);
}

function indexSitesStatus(array $developer): array {
    requireDeveloper($developer);
    $total = (int) db()->query('SELECT COUNT(*) FROM index_entries e JOIN index_sites s ON s.id = e.site_id WHERE s.enabled = 1')->fetchColumn();
    return ['sites' => indexSites(), 'total' => $total, 'refresh_days' => INDEX_REFRESH_DAYS];
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
    $out = [];
    if (preg_match_all('~<url>(.*?)</url>~is', $xml, $m)) {
        foreach ($m[1] as $block) {
            if (!preg_match('~<loc>\s*([^<\s]+)\s*</loc>~i', $block, $l)) continue;
            $lm = preg_match('~<lastmod>\s*([^<\s]+)\s*</lastmod>~i', $block, $x) ? $x[1] : null;
            $out[] = [html_entity_decode($l[1]), $lm];
        }
    }
    return $out;
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
        foreach ($m[1] as $u) if (strtolower((string) parse_url($u, PHP_URL_HOST)) === $site['host']) $cands[] = $u;
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
            $n = (int) db()->query("SELECT COUNT(*) FROM index_entries WHERE site_id = $id")->fetchColumn();
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
            $up = $pdo->prepare('INSERT INTO index_entries (site_id, sitemap_id, url, title, stems, lastmod, seen_at) VALUES (?,?,?,?,?,?,?)
                                 ON CONFLICT(url) DO UPDATE SET sitemap_id = excluded.sitemap_id, lastmod = excluded.lastmod, seen_at = excluded.seen_at,
                                   title = CASE WHEN index_entries.title_fixed THEN index_entries.title ELSE excluded.title END,
                                   stems = CASE WHEN index_entries.title_fixed THEN index_entries.stems ELSE excluded.stems END');
            $n = 0;
            foreach (indexParseUrlset($xml) as [$u, $lm]) {
                if (strtolower((string) parse_url($u, PHP_URL_HOST)) !== $site['host']) continue;
                if (!scoutCleanUrl($u) || preg_match(INDEX_SKIP_URL, urldecode((string) parse_url($u, PHP_URL_PATH)))) continue;
                $title = indexTitleFromUrl($u);
                if ($title === '') continue;   // בלי שם אין מה לחפש
                $up->execute([$id, $smId, $u, $title, indexStems($title), $lm, (int) $state['started']]);
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

/** גוף העובד: צעדים עד שנגמר התקציב, ואז שרשור. $once — צעד אחד (בדיקות). */
function indexWorkerRun(bool $once = false): string {
    $lock = fopen(scoutStateDir() . '/index.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return 'busy';
    $t0 = time();
    $last = 'idle';
    try {
        while (time() - $t0 < INDEX_WORKER_BUDGET) {
            $site = indexDueSite();
            if (!$site) { $last = 'idle'; break; }
            try { $last = indexCrawlStep($site); }
            catch (Throwable $e) {
                error_log('recipes-app index worker: ' . $e->getMessage());
                logEvent('warn', 'index-crawl-retry', get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 200), ['host' => $site['host']]);
                indexSaveState((int) $site['id'], null, ['next_crawl_at' => time() + 3600, 'last_error' => 'תקלה זמנית — ננסה שוב בעוד שעה']);
                $last = 'retry';
            }
            if ($once) break;
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    if (!$once && $last !== 'idle' && indexDueSite()) indexKick(true);
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
    if (!$j['base_url'] || !function_exists('curl_init')) return;
    $ch = curl_init(rtrim($j['base_url'], '/') . '/index-worker.php?key=' . $j['worker_key']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 1500, CURLOPT_CONNECTTIMEOUT_MS => 1500,
        CURLOPT_NOSIGNAL => true, CURLOPT_NOPROXY => '127.0.0.1,localhost', CURLOPT_USERAGENT => 'recipes-app/index-worker',
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/** שומר-סף: נקרא מ-api.php. יש אתר שהגיע זמנו — מעירים (לכל היותר פעם בשתי דקות). */
function indexWatchdog(): void {
    try { if (indexDueSite()) indexKick(); }
    catch (Throwable $e) { error_log('recipes-app index watchdog: ' . $e->getMessage()); }
}

// ─────────────────────────────────────────────────────────────
// חיפוש
// ─────────────────────────────────────────────────────────────

function indexSearch(string $q, int $offset = 0): array {
    $q = trim(mb_substr($q, 0, 100));
    $stems = [];
    foreach (preg_split('/\s+/u', normalizeText($q)) ?: [] as $w) {
        if (mb_strlen($w) < 2 || in_array($w, ['של', 'עם', 'בלי', 'ללא', 'או', 'מתכון', 'מתכונים'], true)) continue;
        $stems[] = pantryStem($w);
    }
    $sites = (int) db()->query('SELECT COUNT(*) FROM index_sites WHERE enabled = 1')->fetchColumn();
    $all = (int) db()->query('SELECT COUNT(*) FROM index_entries e JOIN index_sites s ON s.id = e.site_id WHERE s.enabled = 1')->fetchColumn();
    if (!$stems) return ['results' => [], 'total' => 0, 'indexed' => $all, 'sites' => $sites];

    $where = ['s.enabled = 1'];
    $params = [];
    foreach (array_unique($stems) as $s) { $where[] = 'e.stems LIKE ?'; $params[] = '% ' . str_replace(['%', '_'], ['\\%', '\\_'], $s) . '%'; }
    $w = implode(' AND ', $where);
    $count = db()->prepare("SELECT COUNT(*) FROM index_entries e JOIN index_sites s ON s.id = e.site_id WHERE $w");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    // קודם שם שמכיל את הביטוי כמו שהוקלד, אחר כך שמות קצרים (מדויקים יותר)
    $st = db()->prepare("SELECT e.id, e.title, e.url, s.name AS site, s.host FROM index_entries e JOIN index_sites s ON s.id = e.site_id
                         WHERE $w ORDER BY (e.title LIKE ?) DESC, length(e.title), e.id DESC LIMIT 30 OFFSET " . max(0, $offset));
    $st->execute([...$params, '%' . $q . '%']);
    return ['results' => array_map(fn($r) => ['id' => (int) $r['id'], 'title' => $r['title'], 'url' => $r['url'], 'site' => $r['site'], 'host' => $r['host']],
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
 * פתיחת תוצאה: מהמטמון, או הבאה + חילוץ + ניסוח מחדש. מחזיר את הטיוטה
 * כפי שהיא תישמר. מתכון שלא חולץ — שגיאה עם הקישור למקור.
 */
function webOpen(int $id, array $user): array {
    $e = webEntry($id);
    $st = db()->prepare('SELECT * FROM web_cache WHERE url = ? AND created_at > ?');
    $st->execute([$e['url'], time() - WEB_CACHE_DAYS * 86400]);
    $c = $st->fetch();
    $st->closeCursor();
    if ($c) {
        $draft = json_decode($c['draft'], true);
        return ['draft' => $draft, 'rewritten' => (bool) $c['rewritten'], 'cached' => true, 'opens_left' => webOpensLeft($user),
                'entry' => ['id' => (int) $e['id'], 'url' => $e['url'], 'site' => $e['site']]];
    }

    $left = webOpensLeft($user);
    if ($left === 0) throw new AppError('הגעת למכסת הפתיחות של היום (' . WEB_DAILY_LIMIT . '). מתכונים שכבר נפתחו — עדיין נפתחים. אפשר גם לפתוח באתר המקור.', 429);
    if (!scoutRobotsAllowed($e['url'])) throw new AppError('האתר לא מאפשר ייבוא אוטומטי של הדף הזה. אפשר לפתוח אותו באתר המקור.', 403);
    scoutThrottle($e['url']);
    $draft = importPreview($e['url'], 'web-open', $user, []);

    // ניסוח מחדש: העקרונות נשארים, הנוסח שלנו
    $rewritten = false;
    $note = null;
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
    unset($draft['import_log_id']);

    // השם האמיתי מהדף — טוב מהשם מהכתובת. ונשאר גם בעדכונים הבאים.
    if ($draft['title'] !== '' && $draft['title'] !== $e['title']) {
        db()->prepare('UPDATE index_entries SET title = ?, stems = ?, title_fixed = 1 WHERE id = ?')
            ->execute([mb_substr($draft['title'], 0, 140), indexStems($draft['title'] . ' ' . $e['title']), (int) $e['id']]);
    }
    db()->prepare('INSERT INTO web_cache (url, draft, rewritten, created_at) VALUES (?,?,?,?)
                   ON CONFLICT(url) DO UPDATE SET draft = excluded.draft, rewritten = excluded.rewritten, created_at = excluded.created_at')
        ->execute([$e['url'], json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $rewritten ? 1 : 0, time()]);
    if (random_int(1, 50) === 1) db()->prepare('DELETE FROM web_cache WHERE created_at < ?')->execute([time() - WEB_CACHE_DAYS * 86400]);

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
