<?php
/**
 * ייבוא מתכון מהרשת — כתובת של דף באינטרנט → טיוטה בעורך, עם קרדיט למקור.
 *
 * שלוש שכבות חילוץ, מהאמינה לפחות אמינה:
 *   1. JSON-LD של schema.org/Recipe — רוב אתרי המתכונים (וגם אתרים
 *      ישראליים) מסמנים כך, כי גוגל דורש את זה לתוצאות עשירות.
 *   2. Microdata (itemprop="recipeIngredient" וכו') — אתרים ישנים יותר.
 *   3. ניחוש לפי כותרות: "מצרכים"/"רכיבים" ואחריהן רשימה, "אופן ההכנה"
 *      ואחריה רשימה או פסקאות. ל-og:title / og:image כגיבוי לשם ולתמונה.
 *
 * מה שחוזר הוא **טיוטה**, לא מתכון שמור: המשתמש רואה אותה בעורך, מתקן,
 * ושומר. כך גם חילוץ חלקי שימושי, וגם אין שמירה אוטומטית של תוכן זר.
 *
 * קרדיט: source_url / source_name / source_author נשמרים במתכון ומוצגים
 * תמיד — בתצוגה וברשימה — עם קישור למקור. התמונות והסרטונים נשמרים
 * כקישורים לכתובת המקורית (לא מועתקים לשרת): כך הקובץ נשאר אצל בעליו,
 * ואנחנו רק מצביעים עליו. מתכון מיובא נוצר פרטי; לפרסם אותו זו החלטה
 * של המשתמש, והעורך אומר לו את זה.
 *
 * אבטחה: השרת מביא כתובת שמשתמש זר הקליד. לכן: רק http/https, רק פורטים
 * 80/443, ולא כתובות פנימיות (loopback, רשתות פרטיות, link-local) — גם
 * אחרי הפניה. גודל מרבי 3MB, זמן מרבי 15 שניות, עד 5 הפניות.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/recipes.php';
require_once __DIR__ . '/import_log.php';

const IMPORT_MAX_BYTES     = 3 * 1024 * 1024;
const IMPORT_TIMEOUT       = 15;
const IMPORT_MAX_REDIRECTS = 5;
const IMPORT_USER_AGENT    = 'Mozilla/5.0 (compatible; recipes-app/1.0; +https://github.com/malkiel01/mini_projects) recipe-import';
const IMPORT_MAX_IMAGES    = 5;
const IMPORT_MAX_VIDEOS    = 2;

// ─────────────────────────────────────────────────────────────
// הבאה — בטוחה
// ─────────────────────────────────────────────────────────────

/** כתובת שמותר להביא: http/https, פורט רגיל, ומארח ציבורי. זורק אחרת. */
function importCheckUrl(string $url): array {
    $url = trim($url);
    $p = parse_url($url);
    if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) {
        throw new AppError('הכתובת חייבת להתחיל ב-http:// או https://');
    }
    if (isset($p['user']) || isset($p['pass'])) throw new AppError('כתובת עם שם משתמש אינה נתמכת');
    if (defined('IMPORT_ALLOW_LOCAL') && IMPORT_ALLOW_LOCAL) return $p;   // בבדיקות בלבד: השרת המקומי
    if (isset($p['port']) && !in_array((int) $p['port'], [80, 443], true)) throw new AppError('פורט לא נתמך');
    $host = strtolower($p['host']);

    if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
        throw new AppError('כתובת פנימית אינה מותרת');
    }
    $isIp = (bool) filter_var($host, FILTER_VALIDATE_IP);
    $ip = $isIp ? $host : gethostbyname($host);   // בכשל מחזיר את השם עצמו
    if (!$isIp && $ip === $host) throw new AppError('לא הצלחתי למצוא את האתר הזה');
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        throw new AppError('כתובת פנימית אינה מותרת');
    }
    return $p;
}

// ─────────────────────────────────────────────────────────────
// חסימה: האתר מגיש "הוכח שאתה אדם" במקום הדף
// ─────────────────────────────────────────────────────────────

const IMPORT_BLOCK_COOLDOWN = 45 * 60;   // אחרי חסימה — לא פונים לאתר 45 דקות
const IMPORT_CACHE_TTL      = 15 * 60;   // דף שהובא — נשמר רבע שעה, כדי שייבוא אחרי תצוגה מקדימה לא יביא שוב

function importStateDir(string $sub): string {
    $d = dirname(DB_FILE) . '/' . $sub;
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d;
}

/**
 * דף אתגר של חומת אש במקום התוכן: AWS WAF (202 + "JavaScript is disabled"),
 * Cloudflare ("Just a moment…", cf-mitigated), ודומיהם. האתר אומר בזה "אתם
 * בוט, עצרו". לא מנסים לעקוף — עוצרים ומכבדים.
 * מחזיר שם קצר של המנגנון, או null.
 */
function importDetectBlock(int $status, array $headers, string $body): ?string {
    if (($headers['x-amzn-waf-action'] ?? '') !== '') return 'AWS WAF';
    if (($headers['cf-mitigated'] ?? '') !== '') return 'Cloudflare';
    if (strlen($body) > 30000) return null;   // דף אתגר הוא קטן; דף אמיתי גדול
    if (preg_match('/awsWafCookieDomainList|AwsWafIntegration|challenge\.js/i', $body)) return 'AWS WAF';
    if (preg_match('/cf-chl-|challenge-platform|<title>Just a moment/i', $body)) return 'Cloudflare';
    if (preg_match('/sgcaptcha|captcha-delivery|px-captcha|_Incapsula_Resource|Request unsuccessful\. Incapsula/i', $body)) return 'captcha';
    if (preg_match('/JavaScript is disabled|enable JavaScript and cookies to continue|verify (that )?you(\'re| are) (not a robot|human)/i', $body)) return 'challenge';
    if ($status === 202 && strlen($body) < 10000) return 'challenge (202)';
    if ($status === 429) return 'rate limit (429)';
    return null;
}

/** האתר חסם — עד מתי ממתינים. null כשאין חסימה פעילה. */
function importHostBlockedUntil(string $host): ?int {
    $f = importStateDir('fetch-blocks') . '/' . md5(strtolower($host));
    if (!is_file($f)) return null;
    $until = (int) @file_get_contents($f);
    return $until > time() ? $until : null;
}

function importMarkHostBlocked(string $host): int {
    $until = time() + IMPORT_BLOCK_COOLDOWN;
    @file_put_contents(importStateDir('fetch-blocks') . '/' . md5(strtolower($host)), (string) $until);
    return $until;
}

function importBlockedMessage(string $host, int $until, string $how = ''): string {
    $tz = new DateTimeZone('Asia/Jerusalem');
    $at = (new DateTimeImmutable('@' . $until))->setTimezone($tz)->format('H:i');
    return "האתר $host חסם זמנית את ההבאות מהשרת" . ($how !== '' ? " ($how)" : '') .
           " — במקום המתכון הוא מגיש דף \"הוכח שאתה אדם\". לא מנסים לעקוף: ממתינים עד $at, ואז אפשר להמשיך לאט יותר.";
}

/** מביא דף. מחזיר [html, finalUrl, status]. $useCache — דף שהובא ברבע השעה האחרונה לא מובא שוב. */
function importFetch(string $url, bool $useCache = false): array {
    if (!function_exists('curl_init')) throw new AppError('בשרת אין cURL — אי אפשר להביא דפים', 500);
    $host0 = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
    if ($host0 !== '' && ($until = importHostBlockedUntil($host0))) throw new AppError(importBlockedMessage($host0, $until), 429);
    $cacheFile = importStateDir('fetch-cache') . '/' . md5($url);
    if ($useCache && is_file($cacheFile) && filemtime($cacheFile) > time() - IMPORT_CACHE_TTL) {
        $c = json_decode((string) @file_get_contents($cacheFile), true);
        if (is_array($c) && isset($c['body'], $c['final'])) return [$c['body'], $c['final'], 200];
    }
    $current = $url;
    for ($hop = 0; $hop <= IMPORT_MAX_REDIRECTS; $hop++) {
        importCheckUrl($current);
        $ch = curl_init($current);
        $body = '';
        $headers = [];
        curl_setopt_array($ch, [
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $headers[strtolower(trim($k))] = trim($v); }
                return strlen($line);
            },
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,          // הפניות ידניות — כל יעד נבדק מחדש
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => IMPORT_TIMEOUT,
            CURLOPT_USERAGENT      => IMPORT_USER_AGENT,
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: he,en;q=0.8'],
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_ENCODING       => '',
            CURLOPT_NOPROXY        => '127.0.0.1,localhost',   // בבדיקות, דרך שרת מקומי
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$body) {
                $body .= $chunk;
                return strlen($body) > IMPORT_MAX_BYTES ? 0 : strlen($chunk);   // 0 = עצור
            },
        ]);
        curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $location = curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: '';
        $err      = curl_errno($ch);
        curl_close($ch);

        if (strlen($body) > IMPORT_MAX_BYTES) throw new AppError('הדף גדול מדי (מעל 3MB)');
        if ($status >= 300 && $status < 400 && $location !== '') { $current = $location; continue; }
        if ($err !== 0 && $body === '') throw new AppError('לא הצלחתי להביא את הדף (האתר לא ענה)');
        if ($how = importDetectBlock($status, $headers, $body)) {
            $h = strtolower(parse_url($current, PHP_URL_HOST) ?: $host0);
            throw new AppError(importBlockedMessage($h, importMarkHostBlocked($h), $how), 429);
        }
        if ($status === 403 || $status === 401) throw new AppError('האתר חוסם הבאה אוטומטית של הדף (' . $status . '). אפשר להעתיק את המתכון ידנית');
        if ($status >= 400) throw new AppError('האתר החזיר שגיאה ' . $status);
        @file_put_contents($cacheFile, json_encode(['body' => $body, 'final' => $current]));
        if (random_int(1, 30) === 1) foreach (glob(importStateDir('fetch-cache') . '/*') ?: [] as $f) if (filemtime($f) < time() - IMPORT_CACHE_TTL) @unlink($f);
        return [$body, $current, $status];
    }
    throw new AppError('יותר מדי הפניות');
}

// ─────────────────────────────────────────────────────────────
// חילוץ
// ─────────────────────────────────────────────────────────────

/** html → DOMDocument, עם קידוד UTF-8 נכון גם לדף שלא מצהיר. */
function importDom(string $html): DOMDocument {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    // הצהרת קידוד בראש — אחרת libxml מניח ISO-8859-1 והעברית נהרסת.
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
    libxml_clear_errors();
    return $doc;
}

function importText(?string $s): string {
    $s = html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = strip_tags($s);
    return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $s) ?? '');
}

/** ISO 8601 duration (PT1H30M) → דקות. null כשלא ניתן לפענח. */
function importDurationMinutes(?string $iso): ?int {
    if (!$iso || !preg_match('/^P(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/i', trim($iso), $m)) return null;
    $min = (int) ($m[1] ?? 0) * 1440 + (int) ($m[2] ?? 0) * 60 + (int) ($m[3] ?? 0);
    return $min > 0 ? $min : null;
}

/** מוצא בעץ JSON-LD את האובייקט הראשון מסוג Recipe (גם בתוך @graph). */
function importFindRecipeNode($node): ?array {
    if (!is_array($node)) return null;
    $type = $node['@type'] ?? null;
    $types = is_array($type) ? $type : ($type !== null ? [$type] : []);
    if (in_array('Recipe', $types, true)) return $node;
    foreach (['@graph', 'mainEntity', 'mainEntityOfPage', 'itemListElement', 'hasPart'] as $k) {
        if (isset($node[$k])) { $r = importFindRecipeNode($node[$k]); if ($r) return $r; }
    }
    if (array_is_list($node)) {
        foreach ($node as $child) { $r = importFindRecipeNode($child); if ($r) return $r; }
    }
    return null;
}

/** שכבה 1: JSON-LD. */
function importFromJsonLd(DOMDocument $doc): ?array {
    foreach ($doc->getElementsByTagName('script') as $script) {
        if (stripos((string) $script->getAttribute('type'), 'ld+json') === false) continue;
        $json = json_decode(trim($script->textContent), true);
        if (!is_array($json)) continue;
        $r = importFindRecipeNode($json);
        if (!$r) continue;

        $str = function ($v): string {
            if (is_array($v)) {
                foreach (['text', 'name', '@value', 'url'] as $k) if (is_string($v[$k] ?? null)) return importText($v[$k]);
                return '';
            }
            return is_scalar($v) ? importText((string) $v) : '';
        };

        $images = [];
        foreach ((array) ($r['image'] ?? []) as $img) {
            $u = is_array($img) ? ($img['url'] ?? $img['contentUrl'] ?? '') : $img;
            if (is_string($u) && $u !== '') $images[] = $u;
        }
        $videos = [];
        foreach ((array) ($r['video'] ?? []) as $v) {
            if (!is_array($v)) { if (is_string($v)) $videos[] = $v; continue; }
            $u = $v['embedUrl'] ?? $v['contentUrl'] ?? $v['url'] ?? '';
            if (is_string($u) && $u !== '') $videos[] = $u;
        }

        // הוראות: רשימה של HowToStep, או HowToSection עם שלבים, או טקסט אחד
        $sections = [];
        $flat = [];
        $instr = $r['recipeInstructions'] ?? [];
        // מחרוזת אחת (לא רשימת HowToStep): מפצלים לפי שורות, ולפי נקודה שאחריה
        // אות עברית — גם בלי רווח, כי אתרים מדביקים פסקאות ("…להתייצב.רוצים לרכוש").
        if (is_string($instr)) $instr = preg_split('/\r?\n+|(?<=[.!?])\s*(?=[א-ת])/u', $instr) ?: [];
        foreach ((array) $instr as $it) {
            if (is_array($it) && ($it['@type'] ?? '') === 'HowToSection') {
                $steps = [];
                foreach ((array) ($it['itemListElement'] ?? []) as $st) $steps[] = $str($st);
                $sections[] = ['name' => importText((string) ($it['name'] ?? '')), 'steps' => array_values(array_filter($steps))];
            } else {
                $flat[] = $str($it);
            }
        }
        $flat = importCleanSteps(array_values(array_filter($flat)));
        foreach ($sections as &$sec) $sec['steps'] = importCleanSteps($sec['steps']);
        unset($sec);
        if ($flat) array_unshift($sections, ['name' => '', 'steps' => $flat]);

        $author = $r['author'] ?? null;
        if (is_array($author) && array_is_list($author)) $author = $author[0] ?? null;
        $publisher = $r['publisher'] ?? null;

        $cats = [];
        foreach (['recipeCategory', 'recipeCuisine', 'keywords'] as $k) {
            $v = $r[$k] ?? null;
            if (is_string($v)) $cats = array_merge($cats, preg_split('/\s*,\s*/u', $v) ?: []);
            elseif (is_array($v)) foreach ($v as $x) if (is_string($x)) $cats[] = $x;
        }

        return [
            'method'       => 'json-ld',
            'title'        => importText((string) ($r['name'] ?? '')),
            'description'  => importText((string) ($r['description'] ?? '')),
            'ingredients'  => array_values(array_filter(array_map('importText', (array) ($r['recipeIngredient'] ?? $r['ingredients'] ?? [])))),
            'sections'     => $sections,
            'yield'        => $str($r['recipeYield'] ?? ''),
            'prep_minutes' => importDurationMinutes(is_string($r['prepTime'] ?? null) ? $r['prepTime'] : null),
            'cook_minutes' => importDurationMinutes(is_string($r['cookTime'] ?? null) ? $r['cookTime'] : null),
            'total_minutes' => importDurationMinutes(is_string($r['totalTime'] ?? null) ? $r['totalTime'] : null),
            'images'       => $images,
            'videos'       => $videos,
            'author'       => is_array($author) ? importText((string) ($author['name'] ?? '')) : importText((string) $author),
            'publisher'    => is_array($publisher) ? importText((string) ($publisher['name'] ?? '')) : '',
            'categories'   => array_values(array_filter(array_map('importText', $cats))),
            'published'    => is_string($r['datePublished'] ?? null) ? $r['datePublished'] : null,
        ];
    }
    return null;
}

/**
 * ניקוי שלבים: כותרת שנדבקה לשלב הראשון ("אופן הכנה טורפים…"), ושורות
 * פרסומת שאתרים מדביקים לסוף הטקסט ("לפרטים נוספים לחצו כאן").
 */
function importCleanSteps(array $steps): array {
    $out = [];
    foreach ($steps as $st) {
        $st = trim((string) $st);
        $st = preg_replace('/^(אופן ההכנה|אופן הכנה|הוראות הכנה|הוראות|דרך ההכנה|instructions?|directions?|method)\s*[:\-–—]?\s*/iu', '', $st) ?? $st;
        if ($st === '') continue;
        // פרסומת — ומה שאחריה כבר לא שלבים: הזנב הזה תמיד בסוף.
        if (preg_match('/לחצו כאן|לפרטים נוספים|לרכישה|רוצים לרכוש|קנו עכשיו|הירשמו|רב המכר|https?:\/\/|www\./iu', $st)) break;
        // "מארחים?" — שאלה של שתי מילים אינה שלב
        if (str_ends_with($st, '?') && str_word_count_u2($st) <= 3) break;
        $out[] = $st;
    }
    return $out;
}

function str_word_count_u2(string $s): int {
    return count(preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: []);
}

/** שכבה 2: Microdata. */
function importFromMicrodata(DOMDocument $doc): ?array {
    $xp = new DOMXPath($doc);
    $scope = $xp->query('//*[contains(@itemtype, "schema.org/Recipe")]')->item(0);
    if (!$scope) return null;
    $prop = fn(string $name) => $xp->query('.//*[@itemprop="' . $name . '"]', $scope);
    $val = function (DOMNode $el): string {
        foreach (['content', 'src', 'href', 'datetime'] as $a) {
            if ($el instanceof DOMElement && $el->getAttribute($a) !== '') return $el->getAttribute($a);
        }
        return importText($el->textContent);
    };
    $list = fn(string $name) => array_values(array_filter(array_map(fn($n) => importText($n->textContent), iterator_to_array($prop($name)))));
    $first = fn(string $name) => ($n = $prop($name)->item(0)) ? $val($n) : '';

    $steps = $list('recipeInstructions');
    if (count($steps) === 1) $steps = array_values(array_filter(preg_split('/\r?\n+/u', $steps[0]) ?: []));
    $images = array_values(array_filter(array_map($val, iterator_to_array($prop('image')))));
    return [
        'method'        => 'microdata',
        'title'         => $first('name'),
        'description'   => $first('description'),
        'ingredients'   => $list('recipeIngredient') ?: $list('ingredients'),
        'sections'      => $steps ? [['name' => '', 'steps' => $steps]] : [],
        'yield'         => $first('recipeYield'),
        'prep_minutes'  => importDurationMinutes($first('prepTime') ?: null),
        'cook_minutes'  => importDurationMinutes($first('cookTime') ?: null),
        'total_minutes' => importDurationMinutes($first('totalTime') ?: null),
        'images'        => $images,
        'videos'        => [],
        'author'        => $first('author'),
        'publisher'     => '',
        'categories'    => $list('recipeCategory'),
        'published'     => $first('datePublished') ?: null,
    ];
}

/** שכבה 3: ניחוש לפי כותרות. עובד על רוב הבלוגים — ולא על דפי "10 מתכונים". */
function importFromHeadings(DOMDocument $doc): ?array {
    $xp = new DOMXPath($doc);
    $meta = fn(string $p) => ($n = $xp->query('//meta[@property="' . $p . '" or @name="' . $p . '"]')->item(0)) ? $n->getAttribute('content') : '';

    // גם "המצרכים", "מה צריך", "הכנות:" (ניקי ב׳), "שלבי הכנה", "איך מכינים"
    $ingHead  = '/^(ה?מצרכים|ה?רכיבים|ה?חומרים|ה?מרכיבים|מה צריך|ingredients?)(\b|:|$)/iu';
    $stepHead = '/^(אופן ה?הכנה|הוראות( ה?הכנה)?|ה?הכנה|הכנות|דרך ה?הכנה|שלבי ה?הכנה|איך מכינים|instructions?|directions?|method|preparation)(\b|:|$)/iu';
    $ingredients = [];
    $steps = [];
    $stepsHead = null;
    foreach ($xp->query('//h1|//h2|//h3|//h4|//strong|//b|//p[strong]') as $h) {
        $t = importText($h->textContent);
        if ($t === '' || mb_strlen($t) > 40) continue;
        $isIng = preg_match($ingHead, $t) === 1;
        $isStep = !$isIng && preg_match($stepHead, $t) === 1;
        if (!$isIng && !$isStep) continue;
        // הרשימה או הפסקאות שאחרי הכותרת, עד הכותרת הבאה
        $items = [];
        for ($n = $h->nextSibling; $n; $n = $n->nextSibling) {
            if (!$n instanceof DOMElement) continue;
            if (in_array($n->tagName, ['h1', 'h2', 'h3', 'h4'], true)) break;
            if (in_array($n->tagName, ['ul', 'ol'], true)) {
                foreach ($n->getElementsByTagName('li') as $li) $items[] = importText($li->textContent);
                if ($items) break;
            } elseif ($n->tagName === 'p' || $n->tagName === 'div') {
                // פסקה של שורות מופרדות ב-<br> (ניקי ב׳: "מרכיבים:" ואחריה <p>שורה<br>שורה…) — שורה לכל רכיב
                if ($n->getElementsByTagName('br')->length >= 2) {
                    foreach (importBrLines($n) as $line) $items[] = $line;
                } else {
                    $t2 = importText($n->textContent);
                    if ($t2 !== '' && mb_strlen($t2) < 600) $items[] = $t2;
                }
                if ($n->tagName === 'div' && $items) break;
            }
        }
        // כותרת בתוך שורה — "<p><strong>מצרכים למתכון</strong><br>שורה<br>שורה…" (נפוץ בבלוגים
        // ישראליים, למשל קרן אגם). אין אחריה רשימה או פסקה — השורות הן הטקסט שבין ה-<br>.
        if (!array_filter($items) && in_array($h->tagName, ['strong', 'b'], true)) {
            $items = importInlineLines($h, $isIng ? $stepHead : '/^(תגובות|שתפו|הערות|טיפים|בתאבון|לסיכום)/u', $isIng ? $ingHead : $stepHead);
        }
        $items = array_values(array_filter($items));
        if ($isIng && !$ingredients) $ingredients = $items;
        if ($isStep && !$steps) { $steps = $items; $stepsHead = $h; }
    }
    // אין כותרת למצרכים (אוגיו: שם המתכון, ואז "לשזיפים:<br>…", "לבלילה:<br>…", "להגשה:<br>…",
    // ואז "אופן ההכנה") — כל בלוקי השורות הקצרות שבין הכותרת הקודמת לשלבים, לפי הסדר
    if (!$ingredients && $steps && $stepsHead) {
        $blocks = [];
        for ($n = $stepsHead->previousSibling, $k = 0; $n && $k < 12; $n = $n->previousSibling) {
            if (!$n instanceof DOMElement) continue;
            $k++;
            if (in_array($n->tagName, ['h1', 'h2', 'h3'], true)) break;
            $lines = in_array($n->tagName, ['ul', 'ol'], true)
                ? array_map(fn($li) => importText($li->textContent), iterator_to_array($n->getElementsByTagName('li')))
                : ($n->getElementsByTagName('br')->length >= 1 ? importBrLines($n) : []);
            $short = array_filter($lines, fn($l) => mb_strlen($l) <= 90);
            if (count($lines) >= 2 && count($short) >= count($lines) * 0.8) array_unshift($blocks, $lines);
        }
        $ingredients = $blocks ? array_values(array_merge(...$blocks)) : [];
        // שורת תת-כותרת ("לבלילה", "להגשה") אינה רכיב
        $ingredients = array_values(array_filter($ingredients, fn($l) => !preg_match('/^ל\S+$/u', $l) || mb_strlen($l) > 12));
    }
    if (!$ingredients && !$steps) return null;

    $title = $meta('og:title') ?: importText(($n = $xp->query('//h1')->item(0)) ? $n->textContent : '')
           ?: importText(($n = $doc->getElementsByTagName('title')->item(0)) ? $n->textContent : '');
    return [
        'method'        => 'headings',
        'title'         => $title,
        'description'   => $meta('og:description') ?: $meta('description'),
        'ingredients'   => $ingredients,
        'sections'      => $steps ? [['name' => '', 'steps' => $steps]] : [],
        'yield'         => '',
        'prep_minutes'  => null, 'cook_minutes' => null, 'total_minutes' => null,
        'images'        => array_values(array_filter([$meta('og:image')])),
        'videos'        => array_values(array_filter([$meta('og:video') ?: $meta('og:video:url')])),
        'author'        => $meta('author') ?: $meta('article:author'),
        'publisher'     => $meta('og:site_name'),
        'categories'    => [],
        'published'     => $meta('article:published_time') ?: null,
    ];
}

/**
 * השורות שאחרי כותרת בתוך שורה: טקסט מופרד ב-<br>, עד כותרת שעוצרת ($stopRe —
 * למשל "אופן הכנה" אחרי המצרכים). ממשיך לבלוקים הבאים (p/div) כל עוד אין בהם
 * כותרת אחרת. תת-כותרת קצרה עם נקודתיים ("להגשה:") אינה שורה.
 */
function importInlineLines(DOMElement $h, string $stopRe, string $selfRe): array {
    $lines = [];
    $cur = '';
    $stopped = false;
    $flush = function () use (&$lines, &$cur) {
        $t = trim(importText($cur), " :\t");
        if ($t !== '' && mb_strlen($t) < 400 && !preg_match('/^(בתאבון|בהצלחה|שיהיה בתאבון)\W*$/u', $t)) $lines[] = $t;
        $cur = '';
    };
    $walk = function (?DOMNode $n) use (&$walk, &$cur, &$stopped, $flush, $stopRe, $selfRe) {
        for (; $n && !$stopped; $n = $n->nextSibling) {
            if ($n instanceof DOMText) { $cur .= $n->textContent; continue; }
            if (!$n instanceof DOMElement) continue;
            $tag = strtolower($n->tagName);
            if ($tag === 'br') { $flush(); continue; }
            if (in_array($tag, ['script', 'style', 'img', 'figure', 'button', 'form'], true)) continue;
            if (in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) { $flush(); $stopped = true; return; }
            $tt = importText($n->textContent);
            if (in_array($tag, ['strong', 'b'], true) && $tt !== '' && mb_strlen($tt) <= 40) {
                if (preg_match($stopRe, $tt) || preg_match($selfRe, $tt)) { $flush(); $stopped = true; return; }
                if (str_ends_with($tt, ':')) { $flush(); continue; }   // "להגשה:" — תת-כותרת
            }
            if (in_array($tag, ['p', 'div', 'ul', 'ol', 'li'], true)) { $flush(); $walk($n->firstChild); $flush(); continue; }
            $cur .= $n->textContent;
        }
    };
    $walk($h->nextSibling);
    $flush();
    // הבלוקים שאחרי — עד כותרת אחרת, ולכל היותר חמישה
    for ($b = $h->parentNode?->nextSibling, $k = 0; $b && !$stopped && $k < 5; $b = $b->nextSibling) {
        if (!$b instanceof DOMElement) continue;
        $k++;
        $walk($b->firstChild);
        $flush();
    }
    return $lines;
}

/** אלמנט עם שורות מופרדות ב-<br> → השורות, נקיות. */
function importBrLines(DOMElement $el): array {
    $html = '';
    foreach ($el->childNodes as $c) $html .= $el->ownerDocument->saveHTML($c);
    $out = [];
    foreach (preg_split('~<br\s*/?>~i', $html) ?: [] as $part) {
        $t = trim(importText($part), " :\t");
        if ($t !== '' && mb_strlen($t) < 400 && !preg_match('/^(בתאבון|בהצלחה)\W*$/u', $t)) $out[] = $t;
    }
    return $out;
}

/** html + כתובת → חילוץ גולמי (או זריקה אם לא נמצא מתכון). */
function importParse(string $html, string $url): array {
    return importParseDoc(importDom($html), $url);
}

/** כמו importParse, על DOM שכבר נבנה — כדי שהאבחון ליומן ישתמש באותו עץ. */
function importParseDoc(DOMDocument $doc, string $url): array {
    $raw = importFromJsonLd($doc) ?? importFromMicrodata($doc) ?? importFromHeadings($doc);
    if (!$raw || (!$raw['ingredients'] && !$raw['sections'])) {
        throw new AppError('לא מצאתי מתכון בדף הזה. אולי זה דף של כמה מתכונים, או דף שלא מסומן כמתכון — אפשר להעתיק ידנית');
    }
    $xp = new DOMXPath($doc);
    $siteName = ($n = $xp->query('//meta[@property="og:site_name"]')->item(0)) ? importText($n->getAttribute('content')) : '';
    $raw['publisher'] = $raw['publisher'] ?: $siteName ?: (parse_url($url, PHP_URL_HOST) ?: '');
    $raw['url'] = $url;
    // כתובות יחסיות → מוחלטות
    $raw['images'] = array_values(array_unique(array_map(fn($u) => importAbsolute($u, $url), $raw['images'])));
    $raw['videos'] = array_values(array_unique(array_map(fn($u) => importAbsolute($u, $url), $raw['videos'])));
    return $raw;
}

function importAbsolute(string $u, string $base): string {
    $u = trim($u);
    if ($u === '' || preg_match('~^https?://~i', $u)) return $u;
    if (str_starts_with($u, '//')) return 'https:' . $u;
    $b = parse_url($base);
    $root = ($b['scheme'] ?? 'https') . '://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($u, '/')) return $root . $u;
    return $root . rtrim(dirname($b['path'] ?? '/'), '/') . '/' . $u;
}

// ─────────────────────────────────────────────────────────────
// מהחילוץ אל המודל של העורך
// ─────────────────────────────────────────────────────────────

const IMPORT_UNIT_WORDS = [
    'gram' => ['גרם', 'גר\'', 'ג\'', 'ג', 'g', 'gr', 'grams'],
    'kg'   => ['ק"ג', 'קילו', 'קילוגרם', 'kg'],
    'ml'   => ['מ"ל', 'מל', 'ml'],
    'liter' => ['ליטר', 'ל\'', 'l', 'liter', 'litre'],
    'cup'  => ['כוס', 'כוסות', 'cup', 'cups'],
    'tbsp' => ['כף', 'כפות', 'tbsp', 'tablespoon', 'tablespoons'],
    'tsp'  => ['כפית', 'כפיות', 'tsp', 'teaspoon', 'teaspoons'],
    'unit' => ['יחידה', 'יחידות', 'יח\'', 'יח', 'unit', 'units'],
    'package' => ['חבילה', 'חבילות', 'אריזה', 'שקית', 'מיכל', 'מיכלים', 'גביע', 'גביעים', 'קופסה', 'קופסא', 'פחית', 'package', 'pack'],
    'pinch' => ['קורט', 'קמצוץ', 'pinch'],
];

/** "2 כוסות (400 גרם) קמח" → [amount, unit, product]. השכבה המחושבת היא ניחוש; הטקסט החופשי נשאר כמו שהוא. */
function importParseIngredient(string $text): array {
    $t = trim($text);
    $amount = null; $unit = ''; $rest = $t;

    // שבר יוניקוד או מספר (עם אפשרות ל"1/2", "1.5", "1,5", "1 1/2")
    $fr = ['½' => 0.5, '¼' => 0.25, '¾' => 0.75, '⅓' => 1 / 3, '⅔' => 2 / 3, '⅛' => 0.125];
    if (preg_match('/^(\d+)\s+(\d+)\/(\d+)\s*(.*)$/u', $t, $m)) { $amount = (int) $m[1] + (int) $m[2] / max(1, (int) $m[3]); $rest = $m[4]; }
    elseif (preg_match('/^(\d+)\/(\d+)\s*(.*)$/u', $t, $m)) { $amount = (int) $m[1] / max(1, (int) $m[2]); $rest = $m[3]; }
    elseif (preg_match('/^(\d+(?:[.,]\d+)?)\s*([½¼¾⅓⅔⅛])?\s*(.*)$/u', $t, $m)) { $amount = (float) str_replace(',', '.', $m[1]) + ($m[2] !== '' ? $fr[$m[2]] : 0); $rest = $m[3]; }
    elseif (preg_match('/^([½¼¾⅓⅔⅛])\s*(.*)$/u', $t, $m)) { $amount = $fr[$m[1]]; $rest = $m[2]; }

    if ($amount !== null) {
        foreach (IMPORT_UNIT_WORDS as $code => $words) {
            foreach ($words as $w) {
                if (preg_match('/^' . preg_quote($w, '/') . '(?=\s|$|\()\s*(.*)$/u', $rest, $m)) { $unit = $code; $rest = $m[1]; break 2; }
            }
        }
        if ($unit === '') $unit = 'unit';
    }
    // "(200 ג')" בסוגריים אחרי היחידה — ניקוי מהמוצר; וגם "1 כוס או 240 מ"ל שמן"
    // — המידה החלופית אחרי "או" אינה חלק משם המוצר (היומן הראה את זה ב-carine)
    $product = trim(preg_replace('/\([^)]*\)/u', '', $rest) ?? '');
    $product = preg_replace('/^(?:או|or)\s+[\d½¼¾⅓⅔⅛.,\/]+\s*\S+\s+/u', '', $product) ?? $product;
    $product = preg_replace('/^(של|מ-|מ)\s+/u', '', $product) ?? $product;
    $product = trim(explode(',', $product)[0]);
    $product = mb_substr($product, 0, 60);
    return [
        'free_text'  => $t,
        'amount_min' => $amount !== null ? round($amount, 3) : null,
        'amount_max' => null,
        'unit'       => $unit,
        'product'    => mb_strlen($product) >= 2 && mb_strlen($product) <= 40 ? $product : '',
        'optional'   => (bool) preg_match('/לא חובה|אופציונלי|optional|רשות/iu', $t),
    ];
}

/** "8 מנות" / "8 servings" / "Serves 6" → מספר; אחרת טקסט חופשי. */
function importParseYield(string $y): array {
    $y = trim($y);
    if ($y === '') return ['servings' => null, 'yield_text' => ''];
    if (preg_match('/^(?:serves|makes|yield:?)?\s*(\d{1,3})\s*(?:מנות|מנה|servings?|portions?|people|persons?)?\s*$/iu', $y, $m)) {
        return ['servings' => (int) $m[1], 'yield_text' => ''];
    }
    return ['servings' => null, 'yield_text' => mb_substr($y, 0, 60)];
}

/** תגים מהצירים הסגורים לפי קטגוריות/מילות מפתח של המקור. */
function importGuessTags(array $categories): array {
    $hay = mb_strtolower(implode(' | ', $categories));
    if ($hay === '') return [];
    $ids = [];
    foreach (db()->query('SELECT id, name FROM tags')->fetchAll() as $tag) {
        $name = mb_strtolower($tag['name']);
        // "עוגות" נמצא ב"מתכונים לעוגות"; "חלבי" ב"מתכונים חלביים" לא — ולכן גם הגזע
        $stem = mb_substr($name, 0, max(3, mb_strlen($name) - 1));
        if (str_contains($hay, $name) || (mb_strlen($stem) >= 4 && str_contains($hay, $stem))) $ids[] = (int) $tag['id'];
    }
    return $ids;
}

/** החילוץ הגולמי → המודל שהעורך מצייר, עם pending_media לקישורים אחרי השמירה. */
function importToDraft(array $raw): array {
    $ings = array_map('importParseIngredient', $raw['ingredients']);
    $sections = [];
    if ($raw['sections']) {
        foreach ($raw['sections'] as $i => $sec) {
            $sections[] = [
                'name'        => $sec['name'],
                'ingredients' => $i === 0 ? $ings : [],   // המקור לא אומר איזה רכיב לאיזה חלק
                'steps'       => array_map(fn($s) => ['text' => $s], $sec['steps']),
            ];
        }
    } else {
        $sections[] = ['name' => '', 'ingredients' => $ings, 'steps' => []];
    }
    $yield = importParseYield($raw['yield']);
    $prep = $raw['prep_minutes']; $cook = $raw['cook_minutes'];
    if ($prep === null && $cook === null && $raw['total_minutes'] !== null) $prep = $raw['total_minutes'];

    $host = parse_url($raw['url'], PHP_URL_HOST) ?: '';
    $sourceName = $raw['publisher'] ?: preg_replace('/^www\./', '', $host);

    return [
        'title'         => mb_substr($raw['title'] ?: 'מתכון מ-' . $sourceName, 0, 120),
        'visibility'    => 'private',
        'servings'      => $yield['servings'],
        'yield_text'    => $yield['yield_text'],
        'difficulty'    => null,
        'work_minutes'  => $prep,
        'wait_minutes'  => $cook,
        'tips'          => mb_substr($raw['description'], 0, 2000),
        'tag_ids'       => importGuessTags($raw['categories']),
        'sections'      => $sections,
        'comments_open' => true,
        'source_url'    => $raw['url'],
        'source_name'   => mb_substr($sourceName, 0, 80),
        'source_author' => mb_substr($raw['author'], 0, 80),
        'pending_media' => [
            'images' => array_slice($raw['images'], 0, IMPORT_MAX_IMAGES),
            'videos' => array_slice($raw['videos'], 0, IMPORT_MAX_VIDEOS),
        ],
        'extracted_by'  => $raw['method'],
        // התיעוד הפרטי: מה חולץ, כלשונו. נשמר עם המתכון (import_snapshots) ורואה
        // אותו רק המייבא. בלי ה-HTML — רק הנתונים, כמה קילובייט.
        'snapshot'      => [
            'title' => $raw['title'], 'description' => $raw['description'],
            'ingredients' => $raw['ingredients'], 'sections' => $raw['sections'],
            'yield' => $raw['yield'], 'prep_minutes' => $raw['prep_minutes'], 'cook_minutes' => $raw['cook_minutes'],
            'total_minutes' => $raw['total_minutes'], 'images' => $raw['images'], 'videos' => $raw['videos'],
            'author' => $raw['author'], 'publisher' => $raw['publisher'], 'published' => $raw['published'],
            'categories' => $raw['categories'], 'url' => $raw['url'], 'method' => $raw['method'],
        ],
        'warnings'      => array_values(array_filter([
            !$raw['ingredients'] ? 'לא נמצאו רכיבים — יש להוסיף ידנית' : null,
            !$raw['sections'] ? 'לא נמצאו שלבי הכנה — יש להוסיף ידנית' : null,
            $raw['method'] === 'headings' ? 'הדף לא מסומן כמתכון; החילוץ לפי כותרות עלול להיות חלקי' : null,
            !$raw['images'] ? 'לא נמצאה תמונה' : null,
        ])),
    ];
}

/** שומר את התיעוד הפרטי למתכון שנוצר מייבוא. שורה אחת למתכון; חוזרת — מחליפה. */
function importSaveSnapshot(int $recipeId, array $snapshot, array $user): void {
    $url = (string) ($snapshot['url'] ?? '');
    if (!preg_match('~^https?://~i', $url)) return;
    $st = db()->prepare('INSERT INTO import_snapshots (recipe_id, user_id, source_url, extracted_by, raw, fetched_at)
                         VALUES (?,?,?,?,?,?)
                         ON CONFLICT(recipe_id) DO UPDATE SET raw = excluded.raw, extracted_by = excluded.extracted_by,
                                                             fetched_at = excluded.fetched_at');
    $st->execute([$recipeId, $user['id'], $url, (string) ($snapshot['method'] ?? '?'),
                  json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), nowIso()]);
}

/** מתכון שיובא לפני שהיה תיעוד: מביאים את המקור שוב ושומרים. הבעלים בלבד. */
function importRefreshSnapshot(int $recipeId, array $user): array {
    $st = db()->prepare('SELECT owner_id, source_url FROM recipes WHERE id = ?');
    $st->execute([$recipeId]);
    $r = $st->fetch();
    if (!$r) throw new AppError('המתכון אינו קיים', 404);
    if ((int) $r['owner_id'] !== (int) $user['id']) throw new AppError('רק מי שייבא את המתכון יכול למשוך את המקור', 403);
    if (!$r['source_url']) throw new AppError('למתכון הזה אין מקור ברשת');
    $draft = importPreview($r['source_url'], 'refresh', $user, ['recipe_id' => $recipeId]);
    importSaveSnapshot($recipeId, $draft['snapshot'], $user);
    return importGetSnapshot($recipeId, $user);
}

/** התיעוד — לבעל המתכון בלבד. null כשאין (מתכון שלא יובא). */
function importGetSnapshot(int $recipeId, array $user): ?array {
    $st = db()->prepare('SELECT s.*, r.owner_id FROM import_snapshots s JOIN recipes r ON r.id = s.recipe_id WHERE s.recipe_id = ?');
    $st->execute([$recipeId]);
    $row = $st->fetch();
    if (!$row) return null;
    if ((int) $row['owner_id'] !== (int) $user['id']) throw new AppError('התיעוד זמין רק למי שייבא את המתכון', 403);
    return [
        'source_url'   => $row['source_url'],
        'extracted_by' => $row['extracted_by'],
        'fetched_at'   => $row['fetched_at'],
        'raw'          => json_decode($row['raw'], true) ?: [],
    ];
}

/**
 * הכול יחד: כתובת → טיוטה. כל קריאה נרשמת ביומן הייבוא (lib/import_log.php) —
 * גם כשנכשלה: עם האבחון של הדף אם הובא, ועם השגיאה. $kind אומר מאיפה
 * הגיעה הקריאה; $ctx — scout_id / recipe_id לקישור. הטיוטה חוזרת עם
 * import_log_id, כדי שמי ששומר אותה יקשר את השורה למתכון.
 */
function importPreview(string $url, string $kind = 'preview', ?array $user = null, array $ctx = []): array {
    $t0 = microtime(true);
    $diag = null;
    try {
        // תצוגה מקדימה ואחריה ייבוא של אותו דף — הבאה אחת, לא שתיים
        [$html, $final, $status] = importFetch($url, in_array($kind, ['scout-preview', 'scout-import', 'preview'], true));
        $doc = importDom($html);
        try { $diag = ['http_status' => $status] + importDiagnose($doc, $html); } catch (Throwable $e) { $diag = ['error' => $e->getMessage()]; }
        $draft = importToDraft(importParseDoc($doc, $final));
        $draft['import_log_id'] = importLogDraft($kind, $url, $draft, $user, $diag, (int) ((microtime(true) - $t0) * 1000), $ctx);
        return $draft;
    } catch (AppError $e) {
        importLogFailure($kind, $url, $e->getMessage(), $user, $diag, (int) ((microtime(true) - $t0) * 1000), $ctx);
        throw $e;
    }
}
