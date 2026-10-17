<?php
/**
 * חיפוש לילד (SPEC.md, סעיף 7 · שלב 8).
 *
 *   ‏deny_all — **אינדקס משלנו**: לכל אתר שאושר — הדף הראשי ודפים מ-sitemap; לכל ערוץ / פלייליסט /
 *   סרטון ביוטיוב שאושר — הסרטונים מפיד ה-RSS החינמי של יוטיוב. חיפוש טקסט מלא (FTS5), ואם אין —
 *   ‏LIKE. האינדקס משותף לכל הילדים; מה שכל ילד רואה מסונן אצלו.
 *
 *   ‏allow_all — **Brave Search** (המפתח נשמר בקונסולת המפתח), safesearch=strict.
 *
 *   בשניהם: כל תוצאה עוברת את מנוע ההרשאות של הילד. חסומה — לא מופיעה בכלל. סרטון יוטיוב שלא ידוע
 *   של מי הוא — נחסם (בלי לפנות ליוטיוב באמצע חיפוש).
 *
 *   הסריקה רצה בחלקים קטנים אחרי תשובות (searchTick), לא בקרון: אין קרון בשרת המשותף.
 */

declare(strict_types=1);

require_once __DIR__ . '/children.php';
require_once __DIR__ . '/platforms.php';

const SEARCH_SITE_PAGES = 150;      // דפים לאתר מ-sitemap
const SEARCH_MAX_PAGES  = 20000;    // תקרה לכל האינדקס
const SEARCH_REFRESH    = 86400;    // כל מקור — פעם ביום
const SEARCH_PER_HOUR   = 60;       // חיפושים לילד בשעה
const SEARCH_RESULTS    = 20;

/* ── טבלאות ────────────────────────────────────────────────────── */

/** האם יש FTS5 בשרת. נבדק פעם אחת ונשמר. */
function searchHasFts(): bool {
    static $has = null;
    if ($has !== null) return $has;
    try {
        db()->exec("CREATE VIRTUAL TABLE IF NOT EXISTS search_fts USING fts5(title, body, tokenize='unicode61 remove_diacritics 2')");
        return $has = true;
    } catch (Throwable) {
        return $has = false;
    }
}

function searchFtsPut(int $id, string $title, string $body): void {
    if (!searchHasFts()) return;
    q('DELETE FROM search_fts WHERE rowid = ?', [$id]);
    q('INSERT INTO search_fts (rowid, title, body) VALUES (?, ?, ?)', [$id, $title, $body]);
}

function searchFtsDrop(array $ids): void {
    if (!$ids || !searchHasFts()) return;
    foreach (array_chunk($ids, 400) as $chunk) {
        q('DELETE FROM search_fts WHERE rowid IN (' . implode(',', array_map('intval', $chunk)) . ')');
    }
}

/** דף באינדקס — חדש או עדכון. מוחזר המזהה. */
function searchPut(int $sourceId, string $url, string $kind, string $title, string $body, bool $needsFetch = false): int {
    $url = mb_substr($url, 0, 600);
    $title = mb_substr(trim(preg_replace('/\s+/u', ' ', $title)), 0, 200);
    $body = mb_substr(trim(preg_replace('/\s+/u', ' ', $body)), 0, 600);
    $host = (string) (normalizeUrl($url)['host'] ?? '');
    $row = one('SELECT id, needs_fetch FROM search_pages WHERE url = ?', [$url]);
    if ($row) {
        // דף שכבר יש לו כותרת אמיתית — לא נדרס בכותרת מנוחשת מה-sitemap.
        if ($needsFetch && !(int) $row['needs_fetch']) return (int) $row['id'];
        q('UPDATE search_pages SET source_id = ?, kind = ?, title = ?, body = ?, needs_fetch = ?, fetched_at = ? WHERE id = ?',
          [$sourceId, $kind, $title, $body, $needsFetch ? 1 : 0, nowIso(), (int) $row['id']]);
        $id = (int) $row['id'];
    } else {
        if ((int) one('SELECT COUNT(*) n FROM search_pages')['n'] >= SEARCH_MAX_PAGES) return 0;
        q('INSERT INTO search_pages (source_id, url, host, kind, title, body, needs_fetch, fetched_at) VALUES (?,?,?,?,?,?,?,?)',
          [$sourceId, $url, $host, $kind, $title, $body, $needsFetch ? 1 : 0, nowIso()]);
        $id = (int) db()->lastInsertId();
    }
    searchFtsPut($id, $title, $body);
    return $id;
}

/* ── המקורות: מה מאושר למישהו ─────────────────────────────────── */

/**
 * מקורות לסריקה = כל מה שמאושר לילד כלשהו: אתר (כלל domain) או דף (exact), ופריטי יוטיוב.
 * מקור שאיש כבר לא מאשר — נמחק יחד עם הדפים שלו.
 */
function searchSyncSources(): void {
    $want = [];
    foreach (all("SELECT pattern, scope FROM rules WHERE action = 'allow' AND enabled = 1
                  AND (expires_at = '' OR expires_at > ?)", [nowIso()]) as $r) {
        $n = normalizeUrl((string) $r['pattern']);
        if (!$n || platformOf($n['host']) === PLATFORM_YOUTUBE) continue;
        if ($r['scope'] === 'exact') $want['page|' . $r['pattern']] = ['page', (string) $r['pattern']];
        else $want['site|' . $n['host']] = ['site', $n['host']];
    }
    foreach (all("SELECT kind, item_id FROM platform_items WHERE platform = ? AND action = 'allow'", [PLATFORM_YOUTUBE]) as $i) {
        $k = ['channel' => 'yt_channel', 'handle' => 'yt_handle', 'playlist' => 'yt_playlist', 'video' => 'yt_video'][$i['kind']] ?? '';
        if ($k !== '') $want["$k|{$i['item_id']}"] = [$k, (string) $i['item_id']];
    }
    $have = [];
    foreach (all('SELECT id, kind, ref FROM search_sources') as $s) $have["{$s['kind']}|{$s['ref']}"] = (int) $s['id'];
    foreach ($want as $key => [$kind, $ref]) {
        if (!isset($have[$key])) q('INSERT INTO search_sources (kind, ref, next_at) VALUES (?, ?, ?)', [$kind, $ref, '']);
    }
    foreach ($have as $key => $id) {
        if (isset($want[$key])) continue;
        searchFtsDrop(array_map('intval', array_column(all('SELECT id FROM search_pages WHERE source_id = ?', [$id]), 'id')));
        q('DELETE FROM search_pages WHERE source_id = ?', [$id]);
        q('DELETE FROM search_sources WHERE id = ?', [$id]);
    }
}

/* ── סריקה ─────────────────────────────────────────────────────── */

/** כותרת ותיאור מדף HTML. */
function searchMeta(string $html): array {
    $title = preg_match('#<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']*)#i', $html, $m) ? $m[1]
           : (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) ? $m[1] : '');
    $desc = preg_match('#<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)#i', $html, $m) ? $m[1]
          : (preg_match('#<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']*)#i', $html, $m) ? $m[1] : '');
    $d = fn(string $s) => trim(html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return [$d($title), $d($desc)];
}

/** כותרת מנוחשת מכתובת, עד שהדף עצמו ייסרק: "/wiki/אריה_(חיה)" ← "אריה (חיה)". */
function searchSlugTitle(string $url): string {
    $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
    $last = $path === '' ? (string) parse_url($url, PHP_URL_HOST) : basename($path);
    $last = preg_replace('/\.(html?|php|aspx?)$/i', '', rawurldecode($last));
    return trim(str_replace(['-', '_', '+'], ' ', $last));
}

/** ה-sitemap של אתר: מ-robots.txt, ואם אין — ‎/sitemap.xml. אינדקס של sitemaps — עד 3 תת-מפות. */
function searchSitemapUrls(string $host, callable $get): array {
    $maps = [];
    $robots = $get("https://$host/robots.txt");
    if (($robots['status'] ?? 0) === 200 && preg_match_all('/^\s*sitemap:\s*(\S+)/im', (string) $robots['body'], $m)) $maps = $m[1];
    if (!$maps) $maps = ["https://$host/sitemap.xml"];
    $urls = [];
    $seen = 0;
    while ($maps && $seen < 4 && count($urls) < SEARCH_SITE_PAGES) {
        $r = $get(array_shift($maps));
        $seen++;
        if (($r['status'] ?? 0) !== 200) continue;
        $xml = (string) $r['body'];
        preg_match_all('#<loc>\s*(.*?)\s*</loc>#is', $xml, $m);
        $locs = array_map(fn($u) => html_entity_decode($u, ENT_QUOTES | ENT_XML1, 'UTF-8'), $m[1] ?? []);
        if (stripos($xml, '<sitemapindex') !== false) { $maps = array_merge($maps, array_slice($locs, 0, 3)); continue; }
        foreach ($locs as $u) {
            $h = (string) (normalizeUrl($u)['host'] ?? '');
            if ($h === $host || str_ends_with($h, ".$host")) $urls[$u] = true;
            if (count($urls) >= SEARCH_SITE_PAGES) break;
        }
    }
    return array_keys($urls);
}

/** פיד RSS של יוטיוב (ערוץ או פלייליסט): [שם, [[id, title, channel, desc], …]]. */
function searchYtFeed(string $url, callable $get): ?array {
    $r = $get($url);
    if (($r['status'] ?? 0) !== 200) return null;
    $xml = (string) $r['body'];
    $name = preg_match('#<title>(.*?)</title>#s', $xml, $m) ? html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8') : '';
    $items = [];
    foreach (array_slice(explode('<entry>', $xml), 1) as $e) {
        if (!preg_match('#<yt:videoId>([A-Za-z0-9_-]{6,15})</yt:videoId>#', $e, $v)) continue;
        $t = preg_match('#<title>(.*?)</title>#s', $e, $m) ? $m[1] : '';
        $c = preg_match('#<yt:channelId>(UC[A-Za-z0-9_-]{10,})</yt:channelId>#', $e, $m) ? $m[1] : '';
        $d = preg_match('#<media:description>(.*?)</media:description>#s', $e, $m) ? $m[1] : '';
        $dec = fn(string $s) => html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $items[] = [$v[1], $dec($t), $c, mb_substr($dec($d), 0, 300)];
    }
    return [$name, $items];
}

/** סרטונים מפיד: לאינדקס, ובעלות הסרטון למטמון — כך המנוע מכריע עליהם בלי לפנות ליוטיוב. */
function searchPutVideos(int $sourceId, array $items, string $handle = ''): int {
    $n = 0;
    foreach ($items as [$id, $title, $channel, $desc]) {
        if ($channel !== '' || $handle !== '') {
            upsert('video_owner', ['platform' => PLATFORM_YOUTUBE, 'video_id' => $id],
                   ['channel_id' => $channel, 'handle' => $handle, 'title' => mb_substr($title, 0, 200), 'fetched_at' => nowIso()]);
        }
        if (searchPut($sourceId, "https://m.youtube.com/watch?v=$id", 'video', $title, $desc)) $n++;
    }
    return $n;
}

/** מקור אחד. מוחזר [מספר דפים, שגיאה]. */
function searchRunSource(array $s, callable $get): array {
    $id = (int) $s['id'];
    $ref = (string) $s['ref'];
    switch ($s['kind']) {
        case 'page':
            $r = $get($ref);
            if (($r['status'] ?? 0) !== 200) return [0, 'HTTP ' . ($r['status'] ?? 0)];
            [$t, $d] = searchMeta((string) $r['body']);
            searchPut($id, $ref, 'page', $t ?: searchSlugTitle($ref), $d);
            return [1, ''];

        case 'site':
            $r = $get("https://$ref/");
            $n = 0;
            if (($r['status'] ?? 0) === 200) {
                [$t, $d] = searchMeta((string) $r['body']);
                searchPut($id, "https://$ref/", 'site', $t ?: $ref, $d);
                $n++;
            }
            foreach (searchSitemapUrls($ref, $get) as $u) {
                if (searchPut($id, $u, 'page', searchSlugTitle($u), '', true)) $n++;
            }
            return [$n, $n ? '' : 'האתר לא ענה'];

        case 'yt_handle':
            $meta = json_decode((string) $s['meta'], true) ?: [];
            if (empty($meta['channel'])) {
                $r = $get("https://www.youtube.com/@$ref");
                $info = ($r['status'] ?? 0) === 200 ? parseYouTubeOwnerHtml((string) $r['body']) : ['channel' => ''];
                if (($info['channel'] ?? '') === '') return [0, 'לא נמצא מזהה הערוץ'];
                $meta['channel'] = $info['channel'];
                q('UPDATE search_sources SET meta = ? WHERE id = ?', [json_encode($meta), $id]);
            }
            $f = searchYtFeed('https://www.youtube.com/feeds/videos.xml?channel_id=' . $meta['channel'], $get);
            if (!$f) return [0, 'הפיד לא נטען'];
            searchPut($id, "https://m.youtube.com/@$ref", 'channel', $f[0] ?: "@$ref", 'ערוץ ביוטיוב');
            return [1 + searchPutVideos($id, $f[1], strtolower($ref)), ''];

        case 'yt_channel':
            $f = searchYtFeed("https://www.youtube.com/feeds/videos.xml?channel_id=$ref", $get);
            if (!$f) return [0, 'הפיד לא נטען'];
            searchPut($id, "https://m.youtube.com/channel/$ref", 'channel', $f[0] ?: $ref, 'ערוץ ביוטיוב');
            return [1 + searchPutVideos($id, $f[1]), ''];

        case 'yt_playlist':
            $f = searchYtFeed("https://www.youtube.com/feeds/videos.xml?playlist_id=$ref", $get);
            if (!$f) return [0, 'הפיד לא נטען'];
            searchPut($id, "https://m.youtube.com/playlist?list=$ref", 'playlist', $f[0] ?: 'פלייליסט', 'פלייליסט ביוטיוב');
            return [1 + searchPutVideos($id, $f[1]), ''];

        case 'yt_video':
            $r = $get('https://www.youtube.com/oembed?format=json&url=' . rawurlencode("https://www.youtube.com/watch?v=$ref"));
            $j = ($r['status'] ?? 0) === 200 ? json_decode((string) $r['body'], true) : null;
            if (!is_array($j)) return [0, 'הסרטון לא נמצא'];
            searchPut($id, "https://m.youtube.com/watch?v=$ref", 'video', (string) ($j['title'] ?? ''), (string) ($j['author_name'] ?? ''));
            return [1, ''];
    }
    return [0, 'סוג לא מוכר'];
}

/**
 * צעד סריקה, עד $budget שניות: מסנכרן מקורות, סורק את אלה שהגיע זמנם, ומשלים כותרות
 * לדפים שנכנסו מה-sitemap. ‏$get — להחלפה בבדיקות. מוחזר כמה מקורות נסרקו.
 */
function searchTick(float $budget = 5.0, ?callable $get = null): int {
    $get ??= fn(string $u) => httpGet($u, 400000);
    $until = microtime(true) + $budget;
    searchSyncSources();
    $done = 0;
    while (microtime(true) < $until) {
        $s = one("SELECT * FROM search_sources WHERE next_at <= ? ORDER BY next_at, id LIMIT 1", [nowIso()]);
        if (!$s) break;
        // קודם מסמנים — מקור שנופל באמצע לא ייבחר שוב ושוב.
        q('UPDATE search_sources SET next_at = ? WHERE id = ?', [gmdate('Y-m-d\TH:i:s\Z', time() + 3600), (int) $s['id']]);
        try { [$n, $err] = searchRunSource($s, $get); }
        catch (Throwable $e) { [$n, $err] = [0, $e->getMessage()]; }
        q('UPDATE search_sources SET pages = (SELECT COUNT(*) FROM search_pages WHERE source_id = ?), last_run = ?, error = ?, next_at = ? WHERE id = ?',
          [(int) $s['id'], nowIso(), mb_substr($err, 0, 200), gmdate('Y-m-d\TH:i:s\Z', time() + ($err && !$n ? 6 * 3600 : SEARCH_REFRESH)), (int) $s['id']]);
        $done++;
    }
    // כותרות אמיתיות לדפים מה-sitemap, כמה בכל צעד.
    while (microtime(true) < $until) {
        $p = one('SELECT * FROM search_pages WHERE needs_fetch = 1 ORDER BY id LIMIT 1');
        if (!$p) break;
        $r = $get($p['url']);
        [$t, $d] = ($r['status'] ?? 0) === 200 ? searchMeta((string) $r['body']) : ['', ''];
        q('UPDATE search_pages SET needs_fetch = 0 WHERE id = ?', [(int) $p['id']]);
        if ($t !== '') searchPut((int) $p['source_id'], $p['url'], $p['kind'], $t, $d);
    }
    return $done;
}

/** אחרי התשובה (כדי לא לעכב אף אחד) — צעד סריקה קטן. */
function searchTickAfterResponse(float $budget = 5.0): void {
    register_shutdown_function(function () use ($budget) {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
        try { searchTick($budget); } catch (Throwable $e) { error_log('migdalor search tick: ' . $e->getMessage()); }
    });
}

function searchStatus(): array {
    return [
        'fts' => searchHasFts(),
        'sources' => (int) one('SELECT COUNT(*) n FROM search_sources')['n'],
        'pages' => (int) one('SELECT COUNT(*) n FROM search_pages')['n'],
        'pending' => (int) one('SELECT COUNT(*) n FROM search_pages WHERE needs_fetch = 1')['n'],
        'errors' => array_map(fn($r) => ['kind' => $r['kind'], 'ref' => $r['ref'], 'error' => $r['error']],
            all("SELECT kind, ref, error FROM search_sources WHERE error != '' ORDER BY last_run DESC LIMIT 10")),
        'last_run' => (string) (one('SELECT MAX(last_run) m FROM search_sources')['m'] ?? ''),
        'brave' => braveKey() !== '',
    ];
}

/* ── חיפוש ─────────────────────────────────────────────────────── */

function braveKey(): string {
    return (string) (one("SELECT value FROM app_meta WHERE key = 'brave_key'")['value'] ?? '');
}

function braveKeySave(string $key): string {
    $key = trim($key);
    if ($key !== '' && !preg_match('/^[A-Za-z0-9_-]{16,80}$/', $key)) return 'המפתח לא נראה כמו מפתח של Brave';
    upsert('app_meta', ['key' => 'brave_key'], ['value' => $key, 'updated_at' => nowIso()]);
    return '';
}

/** מילים לחיפוש: אותיות וספרות בלבד, עד 6 מילים. */
function searchTerms(string $q): array {
    preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($q), $m);
    return array_slice(array_values(array_unique(array_filter($m[0], fn($w) => mb_strlen($w) >= 2))), 0, 6);
}

/** מועמדים מהאינדקס, מהטוב לפחות טוב. כותרת שווה פי 5 מתיאור. */
function searchIndexCandidates(array $terms, int $limit = 200): array {
    if (!$terms) return [];
    $rows = [];
    if (searchHasFts()) {
        $match = implode(' ', array_map(fn($t) => '"' . $t . '"*', $terms));
        try {
            $rows = all("SELECT p.* FROM search_fts f JOIN search_pages p ON p.id = f.rowid
                         WHERE search_fts MATCH ? ORDER BY bm25(search_fts, 5.0, 1.0) LIMIT $limit", [$match]);
        } catch (Throwable) { $rows = []; }
    }
    // גם בתוך מילה ("האריה" כשמחפשים "אריה") — ‏LIKE משלים את מה ש-FTS לא מצא.
    if (count($rows) < 20) {
        $where = implode(' AND ', array_fill(0, count($terms), "(LOWER(title) LIKE ? OR LOWER(body) LIKE ?)"));
        $args = [];
        foreach ($terms as $t) { $args[] = "%$t%"; $args[] = "%$t%"; }
        $have = array_flip(array_column($rows, 'id'));
        foreach (all("SELECT * FROM search_pages WHERE $where ORDER BY CASE kind WHEN 'site' THEN 0 WHEN 'channel' THEN 1 ELSE 2 END, id LIMIT $limit", $args) as $r) {
            if (!isset($have[$r['id']])) $rows[] = $r;
        }
    }
    return $rows;
}

/**
 * מסנן תוצאות דרך מנוע ההרשאות של הילד. בעלות של סרטון — רק מהמטמון: סרטון שלא ידוע של מי
 * הוא, נחסם (בלי פנייה ליוטיוב באמצע חיפוש).
 */
function searchFilter(array $user, array $rows): array {
    $uid = (int) $user['id'];
    $policy = policyFor($uid);
    $set = ruleSetFor($uid);
    $set['owner_of'] = function (string $v): array {
        $r = one('SELECT channel_id, handle FROM video_owner WHERE platform = ? AND video_id = ?', [PLATFORM_YOUTUBE, $v]);
        return ['channel' => (string) ($r['channel_id'] ?? ''), 'handle' => (string) ($r['handle'] ?? '')];
    };
    $now = nowIn((string) $policy['timezone']);
    $out = [];
    foreach ($rows as $r) {
        $d = evaluate($user, $policy, $set, $now, ['url' => $r['url'], 'main_frame' => true, 'used_today' => 0]);
        if (!$d['allow']) continue;
        $out[] = ['title' => $r['title'] !== '' ? $r['title'] : $r['url'], 'url' => $r['url'],
                  'host' => (string) (normalizeUrl($r['url'])['host'] ?? ''), 'snippet' => mb_substr((string) ($r['body'] ?? ''), 0, 160),
                  'kind' => $r['kind'] ?? 'page'];
        if (count($out) >= SEARCH_RESULTS) break;
    }
    return $out;
}

/** Brave: עד 20 תוצאות, safesearch קפדני, עברית קודם. ‏$fetch — להחלפה בבדיקות. */
function braveSearch(string $q, ?callable $fetch = null): ?array {
    $key = braveKey();
    if ($key === '') return null;
    $url = 'https://api.search.brave.com/res/v1/web/search?' . http_build_query([
        'q' => $q, 'count' => 20, 'safesearch' => 'strict', 'search_lang' => 'he', 'country' => 'IL', 'ui_lang' => 'he-IL']);
    $r = $fetch ? $fetch($url, $key) : (function () use ($url, $key) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Subscription-Token: ' . $key]]);
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => $body];
    })();
    $j = ($r['status'] ?? 0) === 200 ? json_decode((string) $r['body'], true) : null;
    if (!is_array($j)) return null;
    $clean = fn($s) => trim(html_entity_decode(strip_tags((string) $s), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return array_map(fn($x) => ['url' => (string) ($x['url'] ?? ''), 'title' => $clean($x['title'] ?? ''),
                                'body' => $clean($x['description'] ?? ''), 'kind' => 'web'],
                     array_filter($j['web']['results'] ?? [], fn($x) => !empty($x['url'])));
}

/**
 * החיפוש של הילד. מוחזר ['engine' => index|web|none, 'results' => […], 'note' => ''].
 * ‏deny_all — האינדקס (מודול search_index). ‏allow_all — Brave (מודול search_web + מפתח);
 * אם אין — נופלים לאינדקס, ואם גם הוא סגור — הסבר.
 */
function searchFor(array $user, string $q, ?callable $braveFetch = null): array {
    $q = trim(mb_substr($q, 0, 120));
    $terms = searchTerms($q);
    if (!$terms) return ['engine' => 'none', 'results' => [], 'note' => 'כתבו לפחות מילה אחת של שתי אותיות'];

    $uid = (int) $user['id'];
    $recent = (int) one("SELECT COUNT(*) n FROM audit WHERE user_id = ? AND kind = 'search' AND at > ?",
                        [$uid, gmdate('Y-m-d\TH:i:s\Z', time() - 3600)])['n'];
    if ($recent >= SEARCH_PER_HOUR) return ['engine' => 'none', 'results' => [], 'note' => 'הרבה חיפושים בשעה האחרונה. נסו שוב עוד מעט.'];
    // בלי המילים עצמן: זו רק ספירה להגבלת הקצב. מה חיפשו — לא נשמר.
    audit($uid, 'search', true);

    $mods = moduleList((string) (accountRow((int) $user['account_id'])['modules'] ?? ''));
    $open = (string) policyFor($uid)['posture'] === POSTURE_ALLOW;
    if ($open && in_array('search_web', $mods, true)) {
        $web = braveSearch($q, $braveFetch);
        if ($web !== null) return ['engine' => 'web', 'results' => searchFilter($user, $web), 'note' => ''];
    }
    if (in_array('search_index', $mods, true)) {
        return ['engine' => 'index', 'results' => searchFilter($user, searchIndexCandidates($terms)),
                'note' => $open ? 'חיפוש באתרים שאושרו' : ''];
    }
    return ['engine' => 'none', 'results' => [], 'note' => 'החיפוש עוד לא פתוח בחשבון שלכם. ההורה יכול לבקש ממנהל המערכת.'];
}
