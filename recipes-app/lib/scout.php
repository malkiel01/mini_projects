<?php
/**
 * סורק אתרים — כלי פרטי של המפתח: לאתר מתכונים באתר, לסמן מה כן ומה לא,
 * ולייבא את המסומנים אחד-אחד. חצי ידני, חצי אוטומטי — בכוונה.
 *
 * למה לא סריקה מלאה: סורק שמוריד אתר שלם נראה כמו בוט, מעמיס על השרת
 * שלהם, ומייצר העתקה המונית — בדיוק מה שמביא מכתב מעורך דין. כאן:
 *   - האדם בוחר מה מייבאים. המערכת רק מציעה.
 *   - כל הבאה היא דף אחד, עם מרווח בין הבאות (לפחות SCOUT_MIN_GAP לכל
 *     דומיין, והדפדפן מוסיף מרווח של שניות בין פריטים).
 *   - robots.txt נבדק, ודף שהאתר אסר — לא מובא.
 *   - User-Agent מזהה את האפליקציה, לא מתחזה לדפדפן.
 *   - מה שיובא נשאר פרטי, עם קרדיט, והשלבים מנוסחים מחדש אם יש מפתח.
 *
 * מקורות לרשימת מועמדים: דף קטגוריה/בית (קישורים שנראים כמו מתכון), או
 * sitemap.xml — רוב אתרי WordPress מפרסמים recipe-sitemap.xml עם כל
 * המתכונים, וזה הרבה יותר אמין מניחוש קישורים.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/importer.php';
require_once __DIR__ . '/recipes.php';
require_once __DIR__ . '/media.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/log.php';

const SCOUT_MAX_CANDIDATES = 300;
const SCOUT_STATUSES = ['new', 'wanted', 'skipped', 'imported', 'error'];

// ─────────────────────────────────────────────────────────────
// נימוס כלפי האתר: robots.txt ומרווח בין הבאות
// ─────────────────────────────────────────────────────────────

function scoutStateDir(): string {
    $d = dirname(DB_FILE) . '/scout';
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d;
}

/**
 * robots.txt של המארח, במטמון ליום. מחזיר true אם מותר להביא את הנתיב
 * (לפי User-agent: * ו-recipes-app). פרסר פשוט: Disallow/Allow עם קידומת
 * ו-* ; הכלל הארוך ביותר שתואם מנצח. כשאין robots או שהוא לא נגיש — מותר.
 */
function scoutRobotsAllowed(string $url): bool {
    $p = parse_url($url);
    $host = strtolower($p['host'] ?? '');
    $path = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    if ($host === '') return true;
    if (defined('IMPORT_ALLOW_LOCAL') && IMPORT_ALLOW_LOCAL && !defined('SCOUT_TEST_ROBOTS')) return true;

    $cache = scoutStateDir() . '/robots-' . md5($host) . '.txt';
    if (!is_file($cache) || filemtime($cache) < time() - 86400) {
        $txt = '';
        try {
            $robotsUrl = ($p['scheme'] ?? 'https') . '://' . $host . (isset($p['port']) ? ':' . $p['port'] : '') . '/robots.txt';
            [$txt] = importFetch($robotsUrl);
        } catch (Throwable $e) { $txt = ''; }   // אין robots → מותר
        @file_put_contents($cache, mb_substr($txt, 0, 200000));
    }
    return scoutRobotsCheck((string) @file_get_contents($cache), $path);
}

/** הפרסר עצמו — נפרד כדי שייבדק בלי רשת. */
function scoutRobotsCheck(string $robots, string $path): bool {
    $groups = [];   // כל קבוצה: ['agents' => [], 'rules' => [[kind, pattern]]]
    $gi = -1;
    foreach (preg_split('/\r?\n/', $robots) ?: [] as $line) {
        $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
        if ($line === '' || !str_contains($line, ':')) continue;
        [$k, $v] = array_map('trim', explode(':', $line, 2));
        $k = strtolower($k);
        if ($k === 'user-agent') {
            // User-agent אחרי כללים פותח קבוצה חדשה; כמה User-agent ברצף — אותה קבוצה
            if ($gi < 0 || $groups[$gi]['rules']) { $groups[] = ['agents' => [], 'rules' => []]; $gi++; }
            $groups[$gi]['agents'][] = strtolower($v);
        } elseif (($k === 'disallow' || $k === 'allow') && $gi >= 0) {
            $groups[$gi]['rules'][] = [$k, $v];
        }
    }
    // הקבוצה הספציפית ביותר: recipes-app, אחרת *
    $pick = null;
    foreach ($groups as $g) if (in_array('recipes-app', $g['agents'], true)) $pick = $g;
    if ($pick === null) foreach ($groups as $g) if (in_array('*', $g['agents'], true)) $pick = $g;
    if ($pick === null) return true;

    $best = null; $bestLen = -1;
    foreach ($pick['rules'] as [$kind, $pattern]) {
        if ($pattern === '') { if ($kind === 'disallow' && $bestLen < 0) { $best = 'allow'; $bestLen = 0; } continue; }
        $anchor = str_ends_with($pattern, '$');
        $pat = $anchor ? substr($pattern, 0, -1) : $pattern;
        $re = '~^' . str_replace('\*', '.*', preg_quote($pat, '~')) . ($anchor ? '$' : '') . '~';
        if (preg_match($re, $path) && strlen($pattern) > $bestLen) { $best = $kind; $bestLen = strlen($pattern); }
    }
    return $best !== 'disallow';
}

const SCOUT_MIN_GAP = 10;  // שניות בין שתי הבאות מאותו דומיין, בצד השרת. 3 היה מהר מדי: carine חסם אחרי ~6 הבאות בדקה

/** מרווח מינימלי בין הבאות לאותו מארח — ישן (sleep) עד שהמרווח מלא. */
function scoutThrottle(string $url): void {
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
    if ($host === '' || $host === '127.0.0.1' || $host === 'localhost') return;
    $f = scoutStateDir() . '/last-' . md5($host);
    $last = is_file($f) ? (float) file_get_contents($f) : 0.0;
    $wait = SCOUT_MIN_GAP - (microtime(true) - $last);
    if ($wait > 0) usleep((int) ($wait * 1e6));
    @file_put_contents($f, (string) microtime(true));
}

// ─────────────────────────────────────────────────────────────
// איתור מועמדים
// ─────────────────────────────────────────────────────────────

/** קישור שנראה כמו דף מתכון — לפי הנתיב או לפי טקסט הקישור. */
function scoutLooksLikeRecipe(string $url, string $text): bool {
    $path = strtolower(urldecode(parse_url($url, PHP_URL_PATH) ?: ''));
    // ספריית המתכונים עצמה (/recipes/) היא דף רשימה, לא מתכון — נדרש משהו אחריה
    if (preg_match('~/(recipe|recipes|foody_recipe|מתכון|מתכונים)/[^/]+~u', $path)) return true;
    if (preg_match('~/(category|tag|author|page|feed|wp-|login|cart|search)(/|$)~', $path)) return false;
    // טקסט קישור שנראה כמו שם מנה (לא "המשך קריאה", לא "צור קשר")
    $t = trim($text);
    if (mb_strlen($t) >= 6 && mb_strlen($t) <= 90 && preg_match('~(עוג|מרק|סלט|פשטיד|לחם|עוגי|מאפ|קציצ|תבשיל|פסט|ריזוטו|שניצל|מתכון|cake|soup|salad|bread|recipe|pie|cookies)~iu', $t)) return true;
    return false;
}

/** sitemap (רגיל או אינדקס) → כתובות. */
function scoutFromSitemap(string $xml): array {
    $locs = [];
    if (preg_match_all('~<loc>\s*([^<\s]+)\s*</loc>~i', $xml, $m)) foreach ($m[1] as $u) $locs[] = html_entity_decode($u);
    return $locs;
}

/**
 * דף רשימה (קטגוריה/בית) או sitemap → מועמדים [{url, title}], מאותו דומיין בלבד,
 * בלי כפילויות, עד SCOUT_MAX_CANDIDATES.
 */
function scoutExtractCandidates(string $body, string $pageUrl): array {
    $host = strtolower(parse_url($pageUrl, PHP_URL_HOST) ?: '');
    $out = [];
    $seen = [];   // url → אינדקס ב-$out, כדי שקישור שני לאותו דף עם שם טוב יותר ישפר את השם
    $pageKey = rtrim(preg_replace('/[#?].*$/', '', $pageUrl) ?? $pageUrl, '/');
    $add = function (string $u, string $title) use (&$out, &$seen, $host, $pageKey) {
        $u = trim(preg_replace('/#.*$/', '', $u) ?? $u);
        if ($u === '' || !preg_match('~^https?://~i', $u)) return;
        if (!scoutCleanUrl($u)) return;   // תבנית JS שנשארה ב-HTML, whatsapp://… וכדומה
        if (strtolower(parse_url($u, PHP_URL_HOST) ?: '') !== $host) return;
        $key = rtrim($u, '/');
        if ($key === $pageKey) return;    // דף הרשימה עצמו אינו מועמד
        $title = mb_substr(trim($title), 0, 120);
        if (isset($seen[$key])) {
            // כבר יש — אבל קישור שני לאותו דף יכול לתת שם טוב יותר: כשהראשון
            // היה "8:44" מהתמונה ונפל לסלאג, או כשהחדש הוא הארכה של הקיים
            // ("פיצה" מה-alt → "פיצה רמאות מהירה" מהכותרת)
            $i = $seen[$key];
            if (scoutGoodTitle($title) && ($out[$i]['from_slug'] || (mb_strlen($title) > mb_strlen($out[$i]['title']) && str_starts_with($title, $out[$i]['title'])))) {
                $out[$i]['title'] = $title; $out[$i]['from_slug'] = false;
            }
            return;
        }
        if (count($out) >= SCOUT_MAX_CANDIDATES) return;
        $seen[$key] = count($out);
        $good = scoutGoodTitle($title);
        $out[] = ['url' => $u, 'title' => $good ? $title : scoutTitleFromUrl($u), 'from_slug' => !$good];
    };

    if (preg_match('~^\s*(<\?xml|<urlset|<sitemapindex)~i', $body)) {
        $isIndex = stripos($body, '<sitemapindex') !== false;
        foreach (scoutFromSitemap($body) as $u) {
            if ($isIndex) { $add($u, '(sitemap) ' . basename(parse_url($u, PHP_URL_PATH) ?: $u)); continue; }
            $add($u, scoutTitleFromUrl($u));   // ב-sitemap הכול מועמד; האדם בוחר
        }
        return $out;
    }

    $doc = importDom($body);
    $xp = new DOMXPath($doc);
    // JSON-LD ItemList — דפי קטגוריה מסודרים מפרסמים את זה
    foreach ($doc->getElementsByTagName('script') as $script) {
        if (stripos((string) $script->getAttribute('type'), 'ld+json') === false) continue;
        $json = json_decode(trim($script->textContent), true);
        if (!is_array($json)) continue;
        $stack = [$json];
        while ($stack) {
            $n = array_pop($stack);
            if (!is_array($n)) continue;
            if (($n['@type'] ?? '') === 'ItemList') {
                foreach ((array) ($n['itemListElement'] ?? []) as $it) {
                    $u = is_array($it) ? ($it['url'] ?? ($it['item']['url'] ?? ($it['item']['@id'] ?? ''))) : '';
                    $t = is_array($it) ? ($it['name'] ?? ($it['item']['name'] ?? '')) : '';
                    if (is_string($u) && $u !== '') $add(importAbsolute($u, $pageUrl), is_string($t) ? $t : '');
                }
            }
            foreach ($n as $v) if (is_array($v)) $stack[] = $v;
        }
    }
    foreach ($xp->query('//a[@href]') as $a) {
        $href = importAbsolute((string) $a->getAttribute('href'), $pageUrl);
        $text = importText($a->textContent);
        // קישור על תמונה: הטקסט הוא לעתים משך הסרטון ("8:44") או ריק —
        // אז alt של התמונה, title או aria-label של הקישור עדיפים
        if (!scoutGoodTitle($text)) {
            foreach ([$a->getAttribute('title'), $a->getAttribute('aria-label')] as $alt) {
                if (scoutGoodTitle(importText($alt))) { $text = importText($alt); break; }
            }
        }
        if (!scoutGoodTitle($text)) {
            $img = $xp->query('.//img[@alt]', $a)->item(0);
            if ($img && scoutGoodTitle(importText($img->getAttribute('alt')))) $text = importText($img->getAttribute('alt'));
        }
        if (scoutLooksLikeRecipe($href, $text)) $add($href, $text);
    }
    return $out;
}

/**
 * כתובת נקייה. אתרים משאירים ב-HTML תבניות JavaScript שלא רונדרו —
 * href="/foody_recipe/' + product_url + '" — וקישורי שיתוף שמקננים כתובת
 * אחרת (…/whatsapp://send?text=…). אלה נראים כמו קישורים ואינם דפים.
 */
function scoutCleanUrl(string $u): bool {
    // רווח, גרשיים, סוגריים מסולסלים, backslash — או המקודדים שלהם
    if (preg_match('/[\s\x27"<>`{}|\\\\]/', $u) || preg_match('/%27|%22|%7B|%3C/i', $u)) return false;
    $path = (string) parse_url($u, PHP_URL_PATH);
    if (preg_match('~[a-z][a-z0-9+.-]*:/~i', ltrim($path, '/')) || str_contains($path, '//')) return false;   // סכמה מקוננת בנתיב
    if (preg_match('~/(whatsapp|mailto|tel|javascript)\b~i', $path)) return false;
    return true;
}

/** שם שאפשר להציג: לפחות שתי אותיות, ולא משך סרטון כמו "8:44". */
function scoutGoodTitle(string $t): bool {
    $t = trim($t);
    if ($t === '' || preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $t)) return false;
    return preg_match_all('/\p{L}/u', $t) >= 2;
}

function scoutTitleFromUrl(string $u): string {
    $slug = basename(rtrim(urldecode(parse_url($u, PHP_URL_PATH) ?: ''), '/'));
    $slug = preg_replace('/\.(html?|php)$/i', '', $slug) ?? $slug;
    return mb_substr(trim(str_replace(['-', '_'], ' ', $slug)), 0, 120);
}

// ─────────────────────────────────────────────────────────────
// הרשימה במסד
// ─────────────────────────────────────────────────────────────

/** מביא דף רשימה ושומר מועמדים חדשים. מחזיר כמה נמצאו וכמה חדשים. */
function scoutPage(string $url, array $developer): array {
    requireDeveloper($developer);
    importCheckUrl($url);
    if (!scoutRobotsAllowed($url)) throw new AppError('האתר אוסר על הבאה אוטומטית של הדף הזה (robots.txt). מכבדים.', 403);
    scoutThrottle($url);
    [$body, $final] = importFetch($url);
    $cands = scoutExtractCandidates($body, $final);
    if (!$cands) throw new AppError('לא מצאתי בדף הזה קישורים שנראים כמו מתכונים. לנסות דף קטגוריה, או sitemap.xml של האתר');

    $host = strtolower(parse_url($final, PHP_URL_HOST) ?: '');
    $pdo = db();
    $ins = $pdo->prepare('INSERT OR IGNORE INTO scout_items (url, title, site, found_on, status, created_at) VALUES (?,?,?,?,?,?)');
    $already = $pdo->prepare('SELECT id FROM recipes WHERE source_url = ? LIMIT 1');
    $new = 0;
    foreach ($cands as $c) {
        $ins->execute([$c['url'], $c['title'], $host, $final, 'new', nowIso()]);
        if ($ins->rowCount() > 0) {
            $new++;
            // כבר יובא בעבר (ידנית) — מסומן כך מראש
            $already->execute([$c['url']]);
            if ($rid = $already->fetchColumn()) {
                $pdo->prepare("UPDATE scout_items SET status='imported', recipe_id=? WHERE url=?")->execute([(int) $rid, $c['url']]);
            }
        }
    }
    logEvent('info', 'scout-page', "נמצאו " . count($cands) . " מועמדים, $new חדשים", ['host' => $host], $developer);
    return ['found' => count($cands), 'new' => $new, 'site' => $host, 'is_sitemap_index' => (bool) preg_match('~^\s*(<\?xml[^>]*>\s*)?<sitemapindex~i', $body)];
}

function scoutList(array $f, array $developer): array {
    requireDeveloper($developer);
    $where = []; $params = [];
    if (!empty($f['status']) && in_array($f['status'], SCOUT_STATUSES, true)) { $where[] = 'status = ?'; $params[] = $f['status']; }
    if (!empty($f['site'])) { $where[] = 'site = ?'; $params[] = mb_substr((string) $f['site'], 0, 120); }
    if (!empty($f['q'])) { $where[] = '(title LIKE ? OR url LIKE ?)'; $like = '%' . mb_substr((string) $f['q'], 0, 80) . '%'; array_push($params, $like, $like); }
    $sql = 'SELECT * FROM scout_items' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT 500';
    $st = db()->prepare($sql); $st->execute($params);
    $items = array_map(fn($r) => [
        'id' => (int) $r['id'], 'url' => $r['url'], 'title' => $r['title'], 'site' => $r['site'],
        'status' => $r['status'], 'recipe_id' => $r['recipe_id'] !== null ? (int) $r['recipe_id'] : null,
        'error' => $r['error'], 'created_at' => $r['created_at'], 'decided_at' => $r['decided_at'],
    ], $st->fetchAll());
    $counts = [];
    foreach (db()->query('SELECT status, COUNT(*) c FROM scout_items GROUP BY status')->fetchAll() as $r) $counts[$r['status']] = (int) $r['c'];
    $sites = array_column(db()->query('SELECT site FROM scout_items GROUP BY site ORDER BY site')->fetchAll(), 'site');
    return ['items' => $items, 'counts' => $counts, 'sites' => $sites];
}

/** סימון: wanted / skipped / new (ביטול). לא נוגע ב-imported. */
function scoutMark(array $ids, string $status, array $developer): void {
    requireDeveloper($developer);
    if (!in_array($status, ['wanted', 'skipped', 'new'], true)) throw new AppError('סימון לא מוכר');
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return;
    $in = implode(',', array_fill(0, count($ids), '?'));
    db()->prepare("UPDATE scout_items SET status = ?, decided_at = ?, error = NULL WHERE id IN ($in) AND status != 'imported'")
        ->execute(array_merge([$status, nowIso()], $ids));
}

function scoutRemove(array $ids, array $developer): void {
    requireDeveloper($developer);
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return;
    $in = implode(',', array_fill(0, count($ids), '?'));
    db()->prepare("DELETE FROM scout_items WHERE id IN ($in)")->execute($ids);
}

/**
 * ייבוא פריט אחד, מקצה לקצה: הבאה (עם robots ומרווח) → טיוטה → מתכון פרטי
 * עם קרדיט ותיעוד → תמונות/סרטון כקישורים → ניסוח מחדש בבינה (אם ביקשו
 * ויש מפתח). שגיאה נרשמת על הפריט ואינה עוצרת את השאר — הדפדפן ממשיך לבא.
 */
/** תצוגה מקדימה של מועמד: הבאה (robots + מרווח) → הטיוטה, בלי לשמור. להשוואה מול המקור ולתיקון בעורך. */
function scoutPreview(int $id, array $developer): array {
    requireDeveloper($developer);
    $st = db()->prepare('SELECT * FROM scout_items WHERE id = ?');
    $st->execute([$id]);
    $item = $st->fetch();
    if (!$item) throw new AppError('הפריט אינו קיים', 404);
    if (!scoutRobotsAllowed($item['url'])) throw new AppError('האתר אוסר על הבאה אוטומטית של הדף (robots.txt)');
    scoutThrottle($item['url']);
    $draft = importPreview($item['url'], 'scout-preview', $developer, ['scout_id' => $id]);
    if (!scoutGoodTitle((string) $item['title']) || $item['title'] !== $draft['title']) {
        db()->prepare('UPDATE scout_items SET title = ? WHERE id = ?')->execute([mb_substr($draft['title'], 0, 120), $id]);
    }
    return $draft;
}

/** מתכון שנשמר עם source_url של מועמד → המועמד מסומן "יובא". נקרא מ-recipe-save. */
function scoutLinkSaved(string $sourceUrl, int $recipeId): void {
    if ($sourceUrl === '') return;
    db()->prepare("UPDATE scout_items SET status = 'imported', recipe_id = ?, error = NULL, decided_at = ? WHERE url = ? AND status != 'imported'")
        ->execute([$recipeId, nowIso(), $sourceUrl]);
}

function scoutImportOne(int $id, array $developer, bool $rewrite): array {
    requireDeveloper($developer);
    $st = db()->prepare('SELECT * FROM scout_items WHERE id = ?');
    $st->execute([$id]);
    $item = $st->fetch();
    if (!$item) throw new AppError('הפריט אינו קיים', 404);
    if ($item['status'] === 'imported' && $item['recipe_id']) return ['recipe_id' => (int) $item['recipe_id'], 'skipped' => true];

    try {
        if (!scoutRobotsAllowed($item['url'])) throw new AppError('האתר אוסר על הבאה אוטומטית של הדף (robots.txt)');
        scoutThrottle($item['url']);
        $draft = importPreview($item['url'], 'scout-import', $developer, ['scout_id' => $id]);
        $rid = saveRecipe($draft, $developer);
        importLogSetRecipe((int) ($draft['import_log_id'] ?? 0), $rid);
        importSaveSnapshot($rid, $draft['snapshot'], $developer);
        foreach ($draft['pending_media']['images'] as $u) { try { storeLink($rid, $developer, $u, 'image'); } catch (Throwable $e) {} }
        foreach ($draft['pending_media']['videos'] as $u) { try { storeLink($rid, $developer, $u, 'video'); } catch (Throwable $e) {} }

        $rewritten = false; $sim = null; $aiError = null;
        if ($rewrite && aiAvailable()) {
            try {
                $secs = array_map(fn($s) => ['name' => $s['name'], 'steps' => array_column($s['steps'], 'text')], $draft['sections']);
                if (array_sum(array_map(fn($s) => count($s['steps']), $secs)) > 0) {
                    $res = aiRewriteSteps($secs, $draft['title'], $developer);
                    $sim = $res['similarity'];
                    if (!$res['too_close']) {
                        $sections = $draft['sections'];
                        foreach ($res['sections'] as $i => $s) if (isset($sections[$i])) $sections[$i]['steps'] = array_map(fn($t) => ['text' => $t], $s['steps']);
                        saveRecipe(['title' => $draft['title'], 'visibility' => 'private', 'sections' => $sections, 'source_rewritten' => true,
                                    'tips' => $draft['tips'], 'servings' => $draft['servings'], 'yield_text' => $draft['yield_text'],
                                    'work_minutes' => $draft['work_minutes'], 'wait_minutes' => $draft['wait_minutes'],
                                    'tag_ids' => $draft['tag_ids'], 'comments_open' => true], $developer, $rid);
                        $rewritten = true;
                    }
                }
            } catch (AppError $e) { $aiError = $e->getMessage(); }
        }
        db()->prepare("UPDATE scout_items SET status='imported', recipe_id=?, error=?, decided_at=? WHERE id=?")
            ->execute([$rid, $aiError ? 'יובא; הניסוח נכשל: ' . $aiError : null, nowIso(), $id]);
        logEvent('info', 'scout-import', $draft['title'], ['scout_id' => $id, 'recipe_id' => $rid, 'rewritten' => $rewritten, 'host' => $item['site']], $developer);
        return ['recipe_id' => $rid, 'title' => $draft['title'], 'rewritten' => $rewritten, 'similarity' => $sim, 'ai_error' => $aiError,
                'warnings' => $draft['warnings']];
    } catch (AppError $e) {
        // חסימה של האתר אינה תקלה בפריט — הוא נשאר "לייבוא", לנסות אחרי ההמתנה
        $blocked = $e->status === 429;
        db()->prepare("UPDATE scout_items SET status=?, error=?, decided_at=? WHERE id=?")
            ->execute([$blocked ? 'wanted' : 'error', $e->getMessage(), nowIso(), $id]);
        throw $e;
    }
}
