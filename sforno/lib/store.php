<?php
/**
 * החנות — קטלוג WooCommerce (אפיון 09). **WooCommerce הוא מקור האמת**: ספורנו קוראת בלבד, לא משנה נתוני מסחר, לא אוספת
 * פרטי תשלום ואין בה סל. "לרכישה באתר" = כתובת המוצר בחנות.
 *
 *   החיבור   — data/woo.json (0600, נכתב אטומית): כתובת החנות, consumer_key / consumer_secret (קריאה בלבד), סוד ה-webhook.
 *              **המפתחות לא יוצאים בשום תשובה ולא נכתבים ביומן** (קריטריון 3) — sf_woo_public() מחזיר רק "יש / אין".
 *   המקור    — ממשק SfWooSource (get(path, query) → [status, data, pages]). SfWooRest — HTTPS GET בלבד, רק למארח שהוגדר,
 *              בלי redirect, עם תקרת גודל וזמן (קריטריון 6). SfWooSample — נתוני הדוגמה (lib/store-sample.json), באותה צורה
 *              של ה-REST — כדי שהבעלים יראה את המסכים לפני שהגיעה הגישה (📦 §24). בבדיקות: $GLOBALS['sf_woo_http'].
 *   הסנכרון  — משימת רקע (01): store.tick כל שעה — שינויים בלבד (modified_after) ביום, מלא בלילה. ריצה = store_sync_runs,
 *              בשלבים (קטגוריות → מוצרים → ביקורות) ובעמודים של 100; ממשיכה בסבב הבא כשנגמר הזמן. ריצה מלאה שהסתיימה —
 *              מה שלא חזר מסומן removed_at. webhook (אם הוגדר) מכניס לתור רענון של מוצר אחד — **לא סומכים על התוכן שלו**,
 *              קוראים את המוצר מחדש מה-API.
 *   הכשל     — הקטלוג השמור מוצג תמיד; מחיר ומלאי "מאומתים" רק אם הסנכרון האחרון שהצליח (או המוצר עצמו) מלפני פחות מ-24 שעות
 *              (קריטריון 4). אחרת — "עודכן לאחרונה ב-…; המחיר הסופי באתר", ובלי מלאי.
 *   המיפוי   — meta "store_map": לכל שדה (מחבר, הוצאה, מהדורה, כריכה, תוכן עניינים, דפי דוגמה) — attribute / meta / tag.
 *              **שדה שלא מופה — לא יוצא בתשובה בכלל** (קריטריון 5: לא "לא ידוע").
 *   הטקסט    — תיאור, ביקורות ותוכן עניינים יוצאים כפסקאות של טקסט פשוט (בלי HTML) — הדפדפן מציג אותם כטקסט.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/health.php';
require_once __DIR__ . '/markup.php';

const SF_STORE_FRESH = 86400;            // מחיר / מלאי "מאומת" — עד 24 שעות
const SF_STORE_PER = 24;                 // מוצרים לעמוד בקטלוג
const SF_STORE_PAGE = 100;               // עמוד של ה-REST
const SF_STORE_MAX_PAGES = 200;          // לשלב (20,000 מוצרים)
const SF_STORE_MAX_BYTES = 8_000_000;    // תשובה אחת מהחנות
const SF_STORE_TIMEOUT = 15;
const SF_STORE_STEP = 8.0;               // שניות לצעד של ריצה (cron נותן 25)
const SF_STORE_RUN_STUCK = 900;          // ריצה שלא התקדמה 15 דקות — נכשלה
const SF_STORE_HOOK_MAX = 2_000_000;     // גוף webhook
const SF_STORE_HOOK_PER_MIN = 300;
const SF_STORE_OUT_PER_MIN = 60;         // "מעבר לחנות" לכתובת IP
const SF_STORE_FIELDS = ['author' => 'מחבר', 'publisher' => 'הוצאה', 'edition' => 'מהדורה', 'binding' => 'כריכה', 'toc' => 'תוכן עניינים', 'samples' => 'דפי דוגמה'];
const SF_STORE_MAP_TYPES = ['attribute', 'meta', 'tag'];
const SF_STORE_HOOK_TOPICS = ['product.created', 'product.updated', 'product.deleted', 'product.restored'];
const SF_STORE_SAMPLE_HOST = 'store.example';

// ── החיבור (data/woo.json) ──────────────────────────────────

function sf_woo_file(): string
{
    return sf_data_dir() . '/woo.json';
}

/** ההגדרה, או null. ['url' => 'https://host[/path]', 'key', 'secret', 'webhook_secret', 'saved_at'] */
function sf_woo_conf(): ?array
{
    $f = sf_woo_file();
    if (!is_file($f)) return null;
    $j = json_decode((string)@file_get_contents($f), true);
    if (!is_array($j) || !is_string($j['url'] ?? null) || sf_woo_url($j['url']) === null) return null;
    return $j;
}

/** יש כתובת + מפתחות — אפשר לסנכרן */
function sf_woo_ready(?array $c = null): bool
{
    $c ??= sf_woo_conf();
    return $c !== null && ($c['key'] ?? '') !== '' && ($c['secret'] ?? '') !== '';
}

/** כתיבה אטומית (קובץ זמני + rename), 0600. */
function sf_woo_write(array $c): void
{
    $dir = sf_data_dir();
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $tmp = sf_woo_file() . '.' . bin2hex(random_bytes(6));
    if (@file_put_contents($tmp, json_encode($c, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) throw new SfError('storage');
    @chmod($tmp, 0600);
    if (!@rename($tmp, sf_woo_file())) { @unlink($tmp); throw new SfError('storage'); }
}

/**
 * כתובת חנות מותרת: https, שם מארח (לא IP, לא localhost), בלי משתמש / פורט אחר / query. מחזיר את הבסיס בלי / בסוף, או null.
 * WordPress בתת-תיקייה (https://host/shop) — מותר.
 */
function sf_woo_url(mixed $url): ?string
{
    if (!is_string($url) || strlen($url) > 300) return null;
    $url = trim($url);
    $u = parse_url($url);
    if (!$u || strtolower($u['scheme'] ?? '') !== 'https' || empty($u['host']) || isset($u['user']) || isset($u['pass']) || isset($u['query']) || isset($u['fragment'])) return null;
    if (isset($u['port']) && $u['port'] !== 443) return null;
    $host = strtolower($u['host']);
    if (!preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) || $host === 'localhost') return null;
    $path = rtrim((string)($u['path'] ?? ''), '/');
    if ($path !== '' && !preg_match('~^(/[A-Za-z0-9._-]+){1,4}$~', $path)) return null;
    return 'https://' . $host . $path;
}

function sf_woo_host(): ?string
{
    $c = sf_woo_conf();
    return $c ? strtolower((string)parse_url($c['url'], PHP_URL_HOST)) : null;
}

/** מה שיוצא לדפדפן על החיבור — **בלי שום מפתח ובלי סוד**, רק האם קיימים. */
function sf_woo_public(): array
{
    $c = sf_woo_conf();
    return ['url' => $c['url'] ?? null, 'has_key' => sf_woo_ready($c), 'has_webhook' => ($c['webhook_secret'] ?? '') !== '',
        'saved_at' => $c['saved_at'] ?? null, 'webhook_url' => sf_self_url('api.php?action=store-webhook')];
}

/**
 * dev-store-set: הכתובת, המפתחות וסוד ה-webhook. שדה מפתח ריק = נשאר מה שהיה (המפתח לא מוצג אחרי שמירה, ולכן אי אפשר
 * "לערוך" אותו — רק להחליף). clear = מחיקת החיבור.
 */
function sf_dev_store_set(array $in): array
{
    if (($in['clear'] ?? false) === true) {
        @unlink(sf_woo_file());
        sf_audit('store.connection', null, null, ['on' => false]);
        return ['connection' => sf_woo_public()];
    }
    $url = sf_woo_url($in['url'] ?? null) ?? sf_fail('bad_input', 'כתובת החנות צריכה להיות https://… (שם האתר, בלי פורט ובלי פרמטרים)');
    $old = sf_woo_conf() ?? [];
    $str = fn(string $k) => is_string($in[$k] ?? null) ? trim($in[$k]) : '';
    $key = $str('key');
    $secret = $str('secret');
    $hook = $str('webhook_secret');
    if ($key !== '' && !preg_match('/^ck_[A-Za-z0-9]{20,64}$/', $key)) sf_fail('bad_input', 'ה-Consumer key מתחיל ב-ck_');
    if ($secret !== '' && !preg_match('/^cs_[A-Za-z0-9]{20,64}$/', $secret)) sf_fail('bad_input', 'ה-Consumer secret מתחיל ב-cs_');
    if ($hook !== '' && (strlen($hook) < 16 || strlen($hook) > 200 || preg_match('/[\x00-\x20\x7f]/', $hook))) sf_fail('bad_input', 'סוד ה-webhook — 16 עד 200 תווים, בלי רווחים');
    // מארח אחר = מפתחות חדשים (מפתח של חנות אחת לא נשלח לאחרת)
    $sameHost = isset($old['url']) && parse_url($old['url'], PHP_URL_HOST) === parse_url($url, PHP_URL_HOST);
    $c = ['url' => $url,
        'key' => $key !== '' ? $key : ($sameHost ? (string)($old['key'] ?? '') : ''),
        'secret' => $secret !== '' ? $secret : ($sameHost ? (string)($old['secret'] ?? '') : ''),
        'webhook_secret' => ($in['webhook_clear'] ?? false) === true ? '' : ($hook !== '' ? $hook : ($sameHost ? (string)($old['webhook_secret'] ?? '') : '')),
        'saved_at' => sf_now()];
    sf_woo_write($c);
    sf_audit('store.connection', null, null, ['on' => true, 'host' => parse_url($url, PHP_URL_HOST), 'keys' => sf_woo_ready($c), 'webhook' => $c['webhook_secret'] !== '']);
    return ['connection' => sf_woo_public()];
}

/** dev-store-test: בקשה אחת (מוצר אחד) — האם הכתובת והמפתח עובדים. **גוף התשובה מהחנות לא חוזר** — רק מצב וספירה. */
function sf_dev_store_test(): array
{
    if (!sf_woo_ready()) sf_fail('state', 'עוד אין כתובת ומפתחות');
    $src = new SfWooRest();
    $t0 = microtime(true);
    try {
        [$st, $data, , $total] = $src->get('products', ['per_page' => 1, 'status' => 'publish']);
    } catch (SfError $e) {
        return ['ok' => false, 'message' => $e->getMessage(), 'ms' => (int)round((microtime(true) - $t0) * 1000)];
    }
    $ms = (int)round((microtime(true) - $t0) * 1000);
    if ($st !== 200 || !is_array($data)) return ['ok' => false, 'status' => $st, 'message' => sf_woo_status_text($st), 'ms' => $ms];
    $version = null;
    try {
        [$s2, $sys] = $src->get('system_status', []);
        if ($s2 === 200 && is_string($sys['environment']['version'] ?? null)) $version = mb_substr($sys['environment']['version'], 0, 20);
    } catch (SfError $e) { /* הגרסה — רק אם המפתח רשאי */ }
    return ['ok' => true, 'status' => 200, 'ms' => $ms, 'products' => $total, 'version' => $version];
}

function sf_woo_status_text(int $st): string
{
    return match (true) {
        $st === 401, $st === 403 => 'החנות דחתה את המפתח (401/403) — לבדוק את ה-Consumer key/secret ושההרשאה היא Read',
        $st === 404 => 'אין WooCommerce REST בכתובת הזו (404) — לבדוק את הכתובת ושה-Permalinks אינם "Plain"',
        $st === 0 => 'אין חיבור לחנות (DNS / TLS / זמן)',
        $st >= 500 => "תקלה בשרת של החנות ($st)",
        default => "החנות ענתה $st",
    };
}

// ── המקור: הממשק והמימושים ──────────────────────────────────

interface SfWooSource
{
    /** GET ל-/wp-json/wc/v3/<path>. מחזיר [status, data (JSON מפוענח או null), totalPages (או null), total (או null)]. */
    public function get(string $path, array $query): array;
}

/** WooCommerce REST אמיתי — HTTPS GET בלבד, רק לכתובת שב-data/woo.json. */
final class SfWooRest implements SfWooSource
{
    private array $c;
    public int $requests = 0;

    public function __construct(?array $conf = null)
    {
        $this->c = $conf ?? sf_woo_conf() ?? throw new SfError('state', 'עוד אין חיבור לחנות');
        if (!sf_woo_ready($this->c)) throw new SfError('state', 'עוד אין מפתחות לחנות');
    }

    public function get(string $path, array $query): array
    {
        if (!preg_match('~^[a-z_]+(/[a-z_]+|/[0-9]{1,12})*$~', $path)) throw new SfError('bad_input');
        $base = sf_woo_url($this->c['url']) ?? throw new SfError('state');
        $url = $base . '/wp-json/wc/v3/' . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        // המפתח — בכותרת Basic על HTTPS, לא ב-URL (כך הוא לא מגיע ליומני גישה ולא לשום מקום שנרשמת בו כתובת)
        $headers = ['Authorization' => 'Basic ' . base64_encode($this->c['key'] . ':' . $this->c['secret']), 'Accept' => 'application/json', 'User-Agent' => 'sforno/store'];
        $this->requests++;
        [$st, $body, $h] = sf_woo_http('GET', $url, $headers);
        $data = $body !== '' ? json_decode($body, true) : null;
        $pages = isset($h['x-wp-totalpages']) && ctype_digit((string)$h['x-wp-totalpages']) ? (int)$h['x-wp-totalpages'] : null;
        $total = isset($h['x-wp-total']) && ctype_digit((string)$h['x-wp-total']) ? (int)$h['x-wp-total'] : null;
        return [$st, $st === 200 ? $data : null, $pages, $total];
    }
}

/**
 * בקשה יוצאת לחנות. **רק GET** (אין כאן אפשרות אחרת — קריטריון 6), https בלבד, בלי redirect, עד SF_STORE_MAX_BYTES.
 * מחזיר [status (0 = אין חיבור), body, headers (שמות באותיות קטנות)]. בבדיקות — $GLOBALS['sf_woo_http'](method, url, headers).
 */
function sf_woo_http(string $method, string $url, array $headers): array
{
    if ($method !== 'GET') throw new LogicException('רק GET לחנות');
    if (isset($GLOBALS['sf_woo_http'])) return ($GLOBALS['sf_woo_http'])($method, $url, $headers);
    if (!function_exists('curl_init')) return [0, '', []];
    // 10: המארח נפתר כאן, רק לכתובת ציבורית — ו-curl מוצמד אליה (CURLOPT_RESOLVE), כך ש-DNS שמשתנה בין הבדיקה לחיבור לא
    // יפנה לרשת הפנימית של השרת
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    $ip = sf_net_public_ip($host);
    if ($ip === null) return [0, '', []];
    $body = '';
    $resp = [];
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_RESOLVE => [$host . ':443:' . (str_contains($ip, ':') ? "[$ip]" : $ip)], CURLOPT_HTTPGET => true, CURLOPT_RETURNTRANSFER => false, CURLOPT_TIMEOUT => SF_STORE_TIMEOUT, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers),
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$resp) {
            if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $resp[strtolower(trim($k))] = trim($v); }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body) {
            if (strlen($body) + strlen($chunk) > SF_STORE_MAX_BYTES) return 0;   // עוצר — תשובה גדולה מדי
            $body .= $chunk;
            return strlen($chunk);
        }]);
    $ok = curl_exec($c);
    $st = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    if ($ok === false) return [0, '', []];
    return [$st, $body, $resp];
}

/** כתובת IP ציבורית (לא פרטית, לא שמורה, לא loopback / link-local). */
function sf_net_ip_public(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return false;
    // מה ש-filter_var לא חוסם בכל הגרסאות: 100.64/10 (CGNAT), 0/8, ‏IPv4 ממופה ב-IPv6, ULA / link-local ב-IPv6
    // + 198.18/15 (בדיקות ביצועים), 224/4 multicast ומעלה (240/4 שמור)
    if (preg_match('/^(0\.|100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.|169\.254\.|127\.|198\.1[89]\.|22[4-9]\.|2[3-5]\d\.)/', $ip)) return false;
    if (str_contains($ip, ':')) {
        $b = inet_pton($ip);
        if ($b === false || strlen($b) !== 16) return false;
        $h = bin2hex($b);
        // ::/96 (כולל ::, ::1, IPv4 תואם), IPv4 ממופה ::ffff:0:0/96, NAT64 64:ff9b::/96 ו-64:ff9b:1::/48, 6to4 2002::/16, Teredo 2001::/32,
        // ULA fc00::/7, link-local fe80::/10, site-local fec0::/10, multicast ff00::/8, תיעוד 2001:db8::/32
        if (str_starts_with($h, str_repeat('0', 24)) || str_starts_with($h, str_repeat('0', 20) . 'ffff') || str_starts_with($h, '0064ff9b')
            || str_starts_with($h, '2002') || str_starts_with($h, '20010000') || str_starts_with($h, '20010db8')
            || preg_match('/^(f[cd]|fe[89a-f]|ff)/', $h)) return false;
    }
    return true;
}

/**
 * פותר את המארח ומחזיר כתובת אחת לחיבור — רק אם **כל** הכתובות שלו ציבוריות (מארח שמצביע גם לפנימית — נחסם כולו).
 * בבדיקות: $GLOBALS['sf_dns'] = fn(host) => [ip, …].
 */
function sf_net_public_ip(string $host): ?string
{
    $ips = isset($GLOBALS['sf_dns']) ? ($GLOBALS['sf_dns'])($host) : null;
    if ($ips === null) {
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $r) $ips[] = $r['ip'] ?? $r['ipv6'] ?? null;
        if (!$ips) $ips = @gethostbynamel($host) ?: [];
    }
    $ips = array_values(array_filter($ips, 'is_string'));
    if (!$ips) return null;
    foreach ($ips as $ip) if (!sf_net_ip_public($ip)) return null;
    $v4 = array_values(array_filter($ips, fn($i) => !str_contains($i, ':')));
    return $v4[0] ?? $ips[0];
}

/** נתוני הדוגמה (lib/store-sample.json) — באותה צורה של ה-REST, עם עימוד וסינון בסיסי. לא נוגע ברשת. */
final class SfWooSample implements SfWooSource
{
    public int $requests = 0;

    public function get(string $path, array $query): array
    {
        $this->requests++;
        $all = sf_store_sample_data();
        $list = match ($path) {
            'products' => array_values(array_filter($all['products'], fn($p) => ($p['status'] ?? '') === 'publish')),
            'products/categories' => $all['categories'],
            'products/reviews' => $all['reviews'],
            default => null,
        };
        if ($list === null) {
            if (preg_match('~^products/(\d+)$~', $path, $m)) {
                foreach ($all['products'] as $p) if ((int)$p['id'] === (int)$m[1]) return [200, $p, null, null];
            }
            return [404, null, null, null];
        }
        $per = max(1, min(100, (int)($query['per_page'] ?? 10)));
        $page = max(1, (int)($query['page'] ?? 1));
        $pages = max(1, (int)ceil(count($list) / $per));
        return [200, array_slice($list, ($page - 1) * $per, $per), $pages, count($list)];
    }
}

function sf_store_sample_data(): array
{
    static $d = null;
    return $d ??= json_decode((string)file_get_contents(__DIR__ . '/store-sample.json'), true);
}

// ── נרמול: מה שנשמר מכל מוצר ────────────────────────────────

/** "49.90" → 4990 (אגורות). בלי float. ריק / לא תקין → null. */
function sf_store_money(mixed $v): ?int
{
    if (is_int($v)) return $v >= 0 && $v < 100_000_000 ? $v * 100 : null;
    if (!is_string($v) || !preg_match('/^(\d{1,9})(?:\.(\d{1,6}))?$/', trim($v), $m)) return null;
    $frac = str_pad(substr($m[2] ?? '', 0, 3), 3, '0');
    $minor = (int)$m[1] * 100 + intdiv((int)$frac + 5, 10);   // עיגול לאגורה
    return $minor;
}

/** HTML מהחנות → פסקאות של טקסט פשוט (בלי תגיות, בלי shortcodes). */
function sf_store_text(mixed $html, int $maxParas = 200): array
{
    if (!is_string($html) || $html === '') return [];
    $s = (string)preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $html);
    $s = (string)preg_replace('~<br\s*/?>|</(p|div|li|h[1-6]|tr|blockquote)>~i', "\n", $s);
    $s = (string)preg_replace('~<li\b[^>]*>~i', '• ', $s);
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = (string)preg_replace('~\[/?[a-z_][a-z0-9_-]*(\s[^\]]*)?\]~i', '', $s);
    $out = [];
    foreach (preg_split('/\R/u', $s) ?: [] as $line) {
        $line = trim((string)preg_replace('/[ \t\x{00A0}]+/u', ' ', $line));
        if ($line !== '') $out[] = mb_substr($line, 0, 4000);
        if (count($out) >= $maxParas) break;
    }
    return $out;
}

/** כתובת https תקינה (לתמונות ולדפי דוגמה), או null */
function sf_store_https(mixed $u): ?string
{
    if (!is_string($u) || strlen($u) > 1000) return null;
    $u = trim($u);
    $p = parse_url($u);
    if (!$p || strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass'])) return null;
    return preg_match('/[\s<>"\'\\\\]/', $u) ? null : $u;
}

/** כתובת המוצר: https **ובמארח של החנות** (נתוני דוגמה — store.example). אחרת null — אין כפתור רכישה. */
function sf_store_permalink(mixed $u, string $source): ?string
{
    $u = sf_store_https($u);
    if ($u === null) return null;
    $host = strtolower((string)parse_url($u, PHP_URL_HOST));
    $want = $source === 'sample' ? SF_STORE_SAMPLE_HOST : sf_woo_host();
    return $want !== null && $host === $want ? $u : null;
}

/** המוצר מה-REST → מה שנשמר ב-raw: רק השדות שספורנו מציגה. meta פרטי (מתחיל ב-_) — לא נשמר. */
function sf_store_raw(array $p): array
{
    $str = fn($v, int $n = 300) => is_string($v) ? mb_substr(trim($v), 0, $n) : '';
    $meta = [];
    foreach (array_slice(is_array($p['meta_data'] ?? null) ? $p['meta_data'] : [], 0, 200) as $m) {
        $k = $m['key'] ?? null;
        if (!is_string($k) || $k === '' || $k[0] === '_' || strlen($k) > 100) continue;
        $v = $m['value'] ?? null;
        if (is_array($v)) $v = array_values(array_filter(array_slice($v, 0, 50), 'is_scalar'));
        elseif (is_scalar($v)) $v = mb_substr((string)$v, 0, 20000);
        else continue;
        $meta[] = ['key' => $k, 'value' => $v];
    }
    $attrs = [];
    foreach (array_slice(is_array($p['attributes'] ?? null) ? $p['attributes'] : [], 0, 50) as $a) {
        if (!is_array($a) || !is_string($a['name'] ?? null)) continue;
        $attrs[] = ['name' => $str($a['name'], 100), 'slug' => $str($a['slug'] ?? '', 100),
            'options' => array_values(array_map(fn($o) => mb_substr((string)$o, 0, 300), array_filter(array_slice((array)($a['options'] ?? []), 0, 50), 'is_scalar')))];
    }
    $ids = fn($v) => array_values(array_slice(array_filter(array_map('intval', is_array($v) ? $v : []), fn($i) => $i > 0), 0, 50));
    return [
        'type' => $str($p['type'] ?? '', 30),
        'sku' => $str($p['sku'] ?? '', 100),
        'description' => is_string($p['description'] ?? null) ? mb_substr($p['description'], 0, 100000) : '',
        'short' => is_string($p['short_description'] ?? null) ? mb_substr($p['short_description'], 0, 20000) : '',
        'images' => array_values(array_filter(array_map(fn($i) => is_array($i) && ($src = sf_store_https($i['src'] ?? null)) ? ['src' => $src, 'alt' => $str($i['alt'] ?? '', 200)] : null,
            array_slice(is_array($p['images'] ?? null) ? $p['images'] : [], 0, 12)))),
        'categories' => $ids(array_map(fn($c) => is_array($c) ? ($c['id'] ?? 0) : 0, is_array($p['categories'] ?? null) ? $p['categories'] : [])),
        'tags' => array_values(array_filter(array_map(fn($t) => is_array($t) && is_string($t['name'] ?? null) ? mb_substr($t['name'], 0, 200) : null,
            array_slice(is_array($p['tags'] ?? null) ? $p['tags'] : [], 0, 100)))),
        'attributes' => $attrs,
        'meta' => $meta,
        'related' => $ids(array_merge(is_array($p['upsell_ids'] ?? null) ? $p['upsell_ids'] : [], is_array($p['related_ids'] ?? null) ? $p['related_ids'] : [])),
    ];
}

// ── המיפוי ─────────────────────────────────────────────────

/** המיפוי הנוכחי: [field => ['type' => attribute|meta|tag, 'key' => …]] — רק שדות שמופו. */
function sf_store_map(): array
{
    $m = json_decode((string)sf_meta('store_map', '{}'), true);
    return is_array($m) ? array_filter($m, fn($v, $k) => isset(SF_STORE_FIELDS[$k]) && is_array($v), ARRAY_FILTER_USE_BOTH) : [];
}

function sf_store_map_clean(mixed $in): array
{
    if (!is_array($in)) sf_fail('bad_input');
    $out = [];
    foreach ($in as $f => $v) {
        if (!isset(SF_STORE_FIELDS[$f])) sf_fail('bad_input', 'שדה לא מוכר: ' . (is_string($f) ? $f : ''));
        if ($v === null || $v === '' || (is_array($v) && trim((string)($v['key'] ?? '')) === '')) continue;
        if (!is_array($v) || !in_array($v['type'] ?? null, SF_STORE_MAP_TYPES, true) || !is_string($v['key'] ?? null)) sf_fail('bad_input');
        $key = trim($v['key']);
        if (mb_strlen($key) > 100 || preg_match('/[\x00-\x1f]/', $key)) sf_fail('bad_input', 'שם השדה ארוך מדי');
        $out[$f] = ['type' => $v['type'], 'key' => $key];
    }
    return $out;
}

/** ערך שדה ממופה מתוך raw — רשימת מחרוזות (ריקה = אין). */
function sf_store_field_values(array $raw, array $spec): array
{
    $key = $spec['key'];
    $lk = mb_strtolower($key);
    $vals = [];
    if ($spec['type'] === 'attribute') {
        foreach ($raw['attributes'] ?? [] as $a) {
            if (mb_strtolower($a['name']) === $lk || mb_strtolower($a['slug'] ?? '') === $lk || mb_strtolower($a['slug'] ?? '') === 'pa_' . $lk) $vals = array_merge($vals, $a['options']);
        }
    } elseif ($spec['type'] === 'meta') {
        foreach ($raw['meta'] ?? [] as $m) {
            if ($m['key'] !== $key) continue;
            foreach ((array)$m['value'] as $v) $vals[] = (string)$v;
        }
    } elseif ($spec['type'] === 'tag') {
        foreach ($raw['tags'] ?? [] as $t) if (str_starts_with($t, $key) && trim(mb_substr($t, mb_strlen($key))) !== '') $vals[] = trim(mb_substr($t, mb_strlen($key)));
    }
    return array_values(array_unique(array_filter(array_map(fn($v) => trim((string)$v), $vals), fn($v) => $v !== '')));
}

/** השדות הממופים של מוצר. שדה שלא מופה, או שאין לו ערך — **לא נמצא במערך בכלל**. */
function sf_store_fields(array $raw, ?array $map = null): array
{
    $map ??= sf_store_map();
    $out = [];
    foreach ($map as $f => $spec) {
        $vals = sf_store_field_values($raw, $spec);
        if (!$vals) continue;
        if ($f === 'toc') {
            $paras = [];
            foreach ($vals as $v) $paras = array_merge($paras, sf_store_text(str_contains($v, '<') ? $v : nl2br(htmlspecialchars($v, ENT_NOQUOTES))));
            if ($paras) $out['toc'] = $paras;
        } elseif ($f === 'samples') {
            $urls = [];
            foreach ($vals as $v) foreach (preg_split('/[\s,]+/', $v) ?: [] as $u) if ($u = sf_store_https($u)) $urls[] = $u;
            if ($urls) $out['samples'] = array_slice(array_values(array_unique($urls)), 0, 20);
        } else {
            $txt = implode(', ', array_map(fn($v) => implode(' ', sf_store_text($v, 3)), $vals));
            if ($txt !== '') $out[$f] = mb_substr($txt, 0, 300);
        }
    }
    return $out;
}

/** author + search — נגזרים מ-raw ומהמיפוי (נשמרים בעמודות, לסינון ולחיפוש). */
function sf_store_derived(string $name, array $raw, ?array $map = null): array
{
    $f = sf_store_fields($raw, $map);
    $search = sf_norm(implode(' ', array_filter([$name, $f['author'] ?? '', $f['publisher'] ?? '', $raw['sku'] ?? '', implode(' ', $raw['tags'] ?? []),
        implode(' ', sf_store_text($raw['short'] ?? '', 5))])));
    return ['author' => $f['author'] ?? null, 'search' => mb_substr($search, 0, 4000)];
}

/** store-map-save: מיפוי חדש ← כל המוצרים נגזרים מחדש מה-raw השמור (בלי פנייה לחנות). */
function sf_store_map_save(array $in): array
{
    $map = sf_store_map_clean($in['map'] ?? null);
    sf_tx(function () use ($map) {
        sf_meta_set('store_map', json_encode((object)$map, JSON_UNESCAPED_UNICODE));
        sf_store_rederive($map);
        sf_audit('store.map', null, null, ['fields' => array_keys($map)]);
    });
    return ['map' => (object)$map];
}

function sf_store_rederive(array $map): void
{
    foreach (sf_rows('SELECT id, name, raw FROM store_products') as $r) {
        $raw = json_decode((string)$r['raw'], true) ?: [];
        $d = sf_store_derived((string)$r['name'], $raw, $map);
        sf_exec('UPDATE store_products SET author = ?, search = ? WHERE id = ?', [$d['author'], $d['search'], $r['id']]);
    }
}

// ── שמירה במטמון ────────────────────────────────────────────

/** מוצר מה-REST → שורה ב-store_products (חדש / עדכון). מוצר שאינו publish או מוסתר — removed_at. */
function sf_store_upsert_product(array $p, string $source, ?int $run): void
{
    $wid = (int)($p['id'] ?? 0);
    if ($wid <= 0 || !is_string($p['name'] ?? null)) return;
    $visible = ($p['status'] ?? 'publish') === 'publish' && !in_array($p['catalog_visibility'] ?? 'visible', ['hidden'], true);
    $raw = sf_store_raw($p);
    $name = mb_substr(implode(' ', sf_store_text($p['name'], 2)) ?: trim(strip_tags($p['name'])), 0, 300);
    $d = sf_store_derived($name, $raw);
    $rating = is_string($p['average_rating'] ?? null) && preg_match('/^\d(\.\d{1,2})?$/', $p['average_rating']) && (float)$p['average_rating'] > 0 ? $p['average_rating'] : null;
    $row = ['woo_id' => $wid, 'source' => $source, 'name' => $name, 'slug' => is_string($p['slug'] ?? null) ? mb_substr($p['slug'], 0, 200) : null,
        'permalink' => sf_store_permalink($p['permalink'] ?? null, $source),
        'price' => sf_store_money($p['price'] ?? null), 'regular_price' => sf_store_money($p['regular_price'] ?? null), 'sale_price' => sf_store_money($p['sale_price'] ?? null),
        'on_sale' => !empty($p['on_sale']) ? 1 : 0, 'stock_status' => in_array($p['stock_status'] ?? null, ['instock', 'outofstock', 'onbackorder'], true) ? $p['stock_status'] : null,
        'purchasable' => ($p['purchasable'] ?? true) ? 1 : 0, 'rating' => $rating, 'rating_count' => max(0, min(1_000_000, (int)($p['rating_count'] ?? 0))),
        'author' => $d['author'], 'search' => $d['search'], 'raw' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'modified_at' => is_string($p['date_modified_gmt'] ?? null) ? mb_substr($p['date_modified_gmt'], 0, 20) : null,
        'synced_at' => sf_now(), 'removed_at' => $visible ? null : sf_now()];
    if ($run !== null) $row['seen_run'] = $run;
    $id = sf_val('SELECT id FROM store_products WHERE woo_id = ?', [$wid]);
    if ($id) {
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($row)));
        sf_exec("UPDATE store_products SET $set WHERE id = ?", [...array_values($row), $id]);
    } else {
        sf_insert('store_products', $row);
    }
    sf_exec('DELETE FROM store_product_categories WHERE product_woo_id = ?', [$wid]);
    foreach (array_unique($raw['categories']) as $c) sf_insert('store_product_categories', ['product_woo_id' => $wid, 'category_woo_id' => $c]);
}

function sf_store_upsert_category(array $c, string $source, ?int $run): void
{
    $wid = (int)($c['id'] ?? 0);
    if ($wid <= 0 || !is_string($c['name'] ?? null)) return;
    $row = ['woo_id' => $wid, 'source' => $source, 'name' => mb_substr(html_entity_decode(strip_tags($c['name']), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 0, 200),
        'slug' => is_string($c['slug'] ?? null) ? mb_substr($c['slug'], 0, 200) : null, 'parent' => max(0, (int)($c['parent'] ?? 0)),
        'product_count' => max(0, (int)($c['count'] ?? 0)), 'synced_at' => sf_now(), 'seen_run' => $run, 'removed_at' => null];
    $id = sf_val('SELECT id FROM store_categories WHERE woo_id = ?', [$wid]);
    if ($id) sf_exec('UPDATE store_categories SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($row))) . ' WHERE id = ?', [...array_values($row), $id]);
    else sf_insert('store_categories', $row);
}

/** ביקורת מאושרת. **המייל של המבקר לא נשמר.** */
function sf_store_upsert_review(array $r, string $source, ?int $run): void
{
    $wid = (int)($r['id'] ?? 0);
    $pid = (int)($r['product_id'] ?? 0);
    if ($wid <= 0 || $pid <= 0) return;
    $ok = ($r['status'] ?? 'approved') === 'approved';
    $row = ['woo_id' => $wid, 'source' => $source, 'product_woo_id' => $pid,
        'reviewer' => is_string($r['reviewer'] ?? null) ? mb_substr(trim(strip_tags($r['reviewer'])), 0, 100) : null,
        'rating' => is_int($r['rating'] ?? null) && $r['rating'] >= 1 && $r['rating'] <= 5 ? $r['rating'] : null,
        'body' => json_encode(sf_store_text($r['review'] ?? '', 40), JSON_UNESCAPED_UNICODE), 'verified' => !empty($r['verified']) ? 1 : 0,
        'created_at' => is_string($r['date_created_gmt'] ?? null) ? mb_substr($r['date_created_gmt'], 0, 20) : null,
        'synced_at' => sf_now(), 'seen_run' => $run, 'removed_at' => $ok ? null : sf_now()];
    $id = sf_val('SELECT id FROM store_reviews WHERE woo_id = ?', [$wid]);
    if ($id) sf_exec('UPDATE store_reviews SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($row))) . ' WHERE id = ?', [...array_values($row), $id]);
    else sf_insert('store_reviews', $row);
}

// ── הסנכרון ────────────────────────────────────────────────

const SF_STORE_STAGES = [
    'categories' => ['path' => 'products/categories', 'next' => 'products'],
    'products' => ['path' => 'products', 'next' => 'reviews'],
    'reviews' => ['path' => 'products/reviews', 'next' => 'finish'],
];

function sf_store_source(string $source): SfWooSource
{
    return $source === 'sample' ? new SfWooSample() : new SfWooRest();
}

/**
 * פתיחת ריצה (full / delta) של מקור (woo / sample) + משימה בתור. ריצה פעילה של אותו מקור — חוזרת היא (לא שתיים במקביל).
 * delta: since = תחילת הריצה האחרונה שהצליחה, פחות 10 דקות (חפיפה — מה שהשתנה בזמן הריצה לא נופל בין הכיסאות).
 */
function sf_store_run_start(string $kind, string $source = 'woo'): array
{
    return sf_tx(function () use ($kind, $source) {
        sf_exec("UPDATE store_sync_runs SET status = 'failed', error = 'נתקעה (לא התקדמה 15 דקות)', finished_at = ? WHERE status = 'running' AND updated_at < ?",
            [sf_now(), sf_iso(sf_time() - SF_STORE_RUN_STUCK)]);
        $cur = sf_row("SELECT * FROM store_sync_runs WHERE status = 'running' AND source = ? ORDER BY id DESC LIMIT 1", [$source]);
        if ($cur) return $cur;
        $since = null;
        if ($kind === 'delta') {
            $last = sf_val("SELECT started_at FROM store_sync_runs WHERE status = 'ok' AND source = ? ORDER BY id DESC LIMIT 1", [$source]);
            if (!$last) $kind = 'full';
            else $since = sf_iso(strtotime((string)$last) - 600);
        }
        $pid = sf_pid();
        sf_insert('store_sync_runs', ['pid' => $pid, 'kind' => $kind, 'source' => $source, 'status' => 'running', 'stage' => $kind === 'full' ? 'categories' : 'products',
            'page' => 1, 'since' => $since, 'started_at' => sf_now(), 'updated_at' => sf_now()]);
        sf_job_add('store.sync', ['run' => $pid], null, 0, 1);
        return sf_row('SELECT * FROM store_sync_runs WHERE pid = ?', [$pid]);
    });
}

/**
 * צעד של ריצה: עמודים עד SF_STORE_STEP שניות, ואז משימה חדשה בתור להמשך. כשל בחנות → הריצה נכשלת (הקטלוג השמור נשאר
 * כמו שהוא — שום דבר לא נמחק בכשל), והניסיון הבא — בסבב השעתי.
 */
function sf_store_run_step(string $pid, ?SfWooSource $src = null): array
{
    $run = sf_row("SELECT * FROM store_sync_runs WHERE pid = ? AND status = 'running'", [$pid]);
    if (!$run) return ['status' => 'not_running'];
    $t0 = microtime(true);
    try {
        $src ??= sf_store_source((string)$run['source']);
        while ($run['stage'] !== 'finish') {
            if (microtime(true) - $t0 > SF_STORE_STEP) {
                sf_job_add('store.sync', ['run' => $pid], null, 0, 1);
                return ['status' => 'continues', 'stage' => $run['stage'], 'page' => (int)$run['page']];
            }
            $stage = SF_STORE_STAGES[$run['stage']];
            $page = (int)$run['page'];
            $q = ['per_page' => SF_STORE_PAGE, 'page' => $page, 'orderby' => 'id', 'order' => 'asc'];
            if ($run['stage'] === 'products') $q['status'] = 'publish';
            if ($run['stage'] === 'reviews') { $q['status'] = 'approved'; unset($q['orderby'], $q['order']); }
            if ($run['since'] !== null && $run['stage'] === 'products') $q += ['modified_after' => $run['since'], 'dates_are_gmt' => 'true'];
            if ($run['since'] !== null && $run['stage'] === 'reviews') $q += ['after' => $run['since']];
            [$st, $data, $pages] = $src->get($stage['path'], $q);
            if ($st !== 200 || !is_array($data) || !array_is_list($data)) throw new SfError('state', sf_woo_status_text($st));
            $rid = (int)$run['id'];
            $source = (string)$run['source'];
            sf_tx(function () use ($data, $run, $rid, $source) {
                foreach ($data as $it) {
                    if (!is_array($it)) continue;
                    match ($run['stage']) {
                        'categories' => sf_store_upsert_category($it, $source, $rid),
                        'products' => sf_store_upsert_product($it, $source, $rid),
                        'reviews' => sf_store_upsert_review($it, $source, $rid),
                    };
                }
            });
            $last = $pages !== null ? $page >= $pages : count($data) < SF_STORE_PAGE;
            if ($page >= SF_STORE_MAX_PAGES) $last = true;
            $run['products'] = (int)$run['products'] + ($run['stage'] === 'products' ? count($data) : 0);
            [$run['stage'], $run['page']] = $last ? [$stage['next'], 1] : [$run['stage'], $page + 1];
            sf_exec('UPDATE store_sync_runs SET stage = ?, page = ?, products = ?, requests = requests + 1, updated_at = ? WHERE id = ?',
                [$run['stage'], $run['page'], $run['products'], sf_now(), $rid]);
        }
        sf_store_run_finish($run);
        return ['status' => 'ok', 'products' => (int)$run['products']];
    } catch (SfError $e) {
        sf_store_run_fail($run, $e->getMessage());
        return ['status' => 'failed', 'error' => $e->getMessage()];
    } catch (Throwable $e) {
        sf_store_run_fail($run, 'תקלה פנימית (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
        throw $e;
    }
}

function sf_store_run_fail(array $run, string $msg): void
{
    sf_exec("UPDATE store_sync_runs SET status = 'failed', error = ?, finished_at = ?, updated_at = ? WHERE id = ?", [mb_substr($msg, 0, 300), sf_now(), sf_now(), $run['id']]);
    if ($run['source'] === 'woo') sf_meta_set('store_last_error_at', sf_now());
}

/**
 * סיום: מלאה — מה שלא נראה בה מסומן removed_at (רק של אותו מקור); הסנכרון האמיתי הראשון שהצליח מוחק את נתוני הדוגמה.
 * store_verified_at = תחילת הריצה (כל מה שבמטמון נכון לרגע הזה).
 */
function sf_store_run_finish(array $run): void
{
    sf_tx(function () use ($run) {
        $now = sf_now();
        if ($run['kind'] === 'full') {
            foreach (['store_products', 'store_categories', 'store_reviews'] as $t) {
                sf_exec("UPDATE $t SET removed_at = ? WHERE source = ? AND removed_at IS NULL AND (seen_run IS NULL OR seen_run <> ?)", [$now, $run['source'], $run['id']]);
            }
        }
        if ($run['source'] === 'woo') {
            sf_store_drop_sample();
            sf_meta_set('store_verified_at', (string)$run['started_at']);
            if ($run['kind'] === 'full') sf_meta_set('store_full_at', (string)$run['started_at']);
        } else {
            sf_meta_set('store_sample_at', (string)$run['started_at']);
        }
        sf_exec("UPDATE store_sync_runs SET status = 'ok', stage = 'finish', finished_at = ?, updated_at = ? WHERE id = ?", [$now, $now, $run['id']]);
    });
}

function sf_store_drop_sample(): void
{
    $ids = array_map(fn($r) => (int)$r['woo_id'], sf_rows("SELECT woo_id FROM store_products WHERE source = 'sample'"));
    foreach ($ids as $w) sf_exec('DELETE FROM store_product_categories WHERE product_woo_id = ?', [$w]);
    foreach (['store_products', 'store_categories', 'store_reviews'] as $t) sf_exec("DELETE FROM $t WHERE source = 'sample'");
}

/** רענון של מוצר אחד (webhook). 404 / לא publish → removed_at. כשל רשת → חריגה (המשימה תנסה שוב). */
function sf_store_refresh_product(int $wid, ?SfWooSource $src = null): string
{
    if (!sf_woo_ready()) return 'off';
    $src ??= new SfWooRest();
    [$st, $p] = $src->get('products/' . $wid, []);
    if ($st === 404 || $st === 410) {
        sf_exec('UPDATE store_products SET removed_at = ?, synced_at = ? WHERE woo_id = ? AND removed_at IS NULL', [sf_now(), sf_now(), $wid]);
        return 'removed';
    }
    if ($st !== 200 || !is_array($p) || (int)($p['id'] ?? 0) !== $wid) throw new SfError('state', sf_woo_status_text($st));
    sf_tx(fn() => sf_store_upsert_product($p, 'woo', null));
    return 'updated';
}

/** השעתי: מלא בלילה (02:00–05:59 בישראל) אם עברו 20 שעות מהמלא האחרון, או תמיד אחרי 30 שעות; אחרת שינויים בלבד. */
function sf_store_tick(): ?array
{
    sf_exec('DELETE FROM store_webhook_log WHERE received_at < ?', [sf_iso(sf_time() - 7 * 86400)]);
    if (!sf_woo_ready()) return null;
    $full = sf_meta('store_full_at');
    $age = $full ? sf_time() - (int)strtotime($full) : PHP_INT_MAX;
    $hour = (int)(new DateTimeImmutable('@' . sf_time()))->setTimezone(new DateTimeZone('Asia/Jerusalem'))->format('G');
    $kind = $age > 30 * 3600 || ($age > 20 * 3600 && $hour >= 2 && $hour < 6) ? 'full' : 'delta';
    return sf_store_run_start($kind);
}

sf_job_register('store.tick', 'החנות: סנכרון מ-WooCommerce — שינויים בלבד כל שעה, מלא בלילה', function (): void { sf_store_tick(); }, every: 3600);
sf_job_register('store.sync', 'החנות: המשך ריצת סנכרון (עמודים)', function (array $p): void {
    if (is_string($p['run'] ?? null)) sf_store_run_step($p['run']);
});
sf_job_register('store.product', 'החנות: רענון מוצר אחד (webhook)', function (array $p): void {
    if (is_int($p['id'] ?? null) && $p['id'] > 0) sf_store_refresh_product($p['id']);
});

// ── webhook (WooCommerce → ספורנו) ─────────────────────────

/**
 * store-webhook — ציבורי (ב-"public" של app.json), כי WooCommerce לא מתחבר. האימות: HMAC-SHA256 של הגוף בסוד שהוגדר
 * (X-WC-Webhook-Signature, base64), ב-hash_equals. אירוע כפול (אותו delivery id) — לא מטופל פעמיים. **התוכן לא נשמר
 * ולא סומכים עליו**: רק המזהה נלקח, והמוצר נקרא מחדש מה-API במשימת רקע.
 */
function sf_store_webhook(array $env): array
{
    $c = sf_woo_conf();
    $secret = (string)($c['webhook_secret'] ?? '');
    if ($secret === '') sf_fail('not_found');
    if ((int)($env['content_length'] ?? 0) > SF_STORE_HOOK_MAX) sf_fail('too_large');
    // 10: הגבלה לכתובת IP **לפני** קריאת הגוף — הצפה לא חתומה נעצרת בלי לקרוא 2MB ובלי לחשב HMAC
    sf_rate('store_hook', sf_reader_ip($env), SF_STORE_HOOK_PER_MIN, 60);
    $body = array_key_exists('raw_body', $env) ? (string)$env['raw_body'] : (string)file_get_contents('php://input', false, null, 0, SF_STORE_HOOK_MAX + 1);
    if (strlen($body) > SF_STORE_HOOK_MAX) sf_fail('too_large');
    $sig = (string)($env['wc_signature'] ?? '');
    // בדיקת החיבור של WooCommerce ביצירת webhook ("webhook_id=N") — עונים 200 ולא עושים דבר
    if ($sig === '' && preg_match('/^webhook_id=\d{1,12}$/', $body)) return ['ok' => true, 'ping' => true];
    if (!hash_equals(base64_encode(hash_hmac('sha256', $body, $secret, true)), $sig)) sf_fail('forbidden');
    if ((int)sf_val('SELECT COUNT(*) FROM store_webhook_log WHERE received_at > ?', [sf_iso(sf_time() - 60)]) >= SF_STORE_HOOK_PER_MIN) sf_fail('rate');
    $delivery = (string)($env['wc_delivery'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $delivery)) $delivery = 'h' . substr(hash('sha256', $body), 0, 40);
    $topic = (string)($env['wc_topic'] ?? '');
    $data = json_decode($body, true);
    $wid = is_array($data) && is_int($data['id'] ?? null) && $data['id'] > 0 ? $data['id'] : null;
    $topicOk = in_array($topic, SF_STORE_HOOK_TOPICS, true);
    try {
        sf_insert('store_webhook_log', ['delivery_id' => $delivery, 'topic' => $topicOk ? $topic : 'other', 'woo_id' => $wid, 'received_at' => sf_now()]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'Duplicate')) return ['ok' => true, 'duplicate' => true];
        throw $e;
    }
    if ($topicOk && $wid !== null) sf_job_add('store.product', ['id' => $wid], 'store.product:' . $wid . '@' . intdiv(sf_time(), 60), 30, 3);
    return ['ok' => true];
}

// ── מה שהקונה רואה ──────────────────────────────────────────

/** הסנכרון האחרון שהצליח (תחילתו), או null */
function sf_store_verified_at(): ?string
{
    return sf_meta('store_verified_at') ?: (sf_meta('store_sample_at') ?: null);
}

/** מחיר ומלאי "מאומתים" — אם המוצר או הסנכרון האחרון שהצליח מלפני פחות מ-24 שעות */
function sf_store_fresh(array $row): bool
{
    $v = sf_store_verified_at();
    $t = max((int)strtotime((string)$row['synced_at']), $v ? (int)strtotime($v) : 0);
    return sf_time() - $t < SF_STORE_FRESH;
}

function sf_store_currency(): string
{
    $c = sf_meta('store_currency', 'ILS');
    return preg_match('/^[A-Z]{3}$/', (string)$c) ? (string)$c : 'ILS';
}

/** שורה → כרטיס בקטלוג. stock — רק כשמאומת. שדה ממופה שאין לו ערך — לא נמצא. */
function sf_store_card(array $r): array
{
    $raw = json_decode((string)$r['raw'], true) ?: [];
    $fresh = sf_store_fresh($r);
    $out = ['id' => (int)$r['woo_id'], 'name' => $r['name'], 'image' => $raw['images'][0] ?? null, 'fresh' => $fresh,
        'price' => $r['price'] !== null ? ['amount' => (int)$r['price'], 'regular' => $r['regular_price'] !== null ? (int)$r['regular_price'] : null,
            'on_sale' => (bool)$r['on_sale'] && $r['regular_price'] !== null && (int)$r['regular_price'] > (int)$r['price'], 'from' => ($raw['type'] ?? '') === 'variable',
            'currency' => sf_store_currency()] : null,
        'synced_at' => $r['synced_at'], 'buy' => $r['permalink'] !== null && (int)$r['purchasable'] === 1];
    if ($fresh && $r['stock_status'] !== null) $out['stock'] = $r['stock_status'];
    if ($r['rating'] !== null && (int)$r['rating_count'] > 0) $out['rating'] = ['avg' => (float)$r['rating'], 'count' => (int)$r['rating_count']];
    if ($r['author'] !== null) $out['author'] = $r['author'];
    return $out;
}

function sf_store_like(string $s): string
{
    return '%' . strtr($s, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
}

/** מצב החנות לקונה: מתי עודכן, האם ישן, נתוני דוגמה */
function sf_store_status(): array
{
    $v = sf_store_verified_at();
    $sample = (bool)sf_val("SELECT 1 FROM store_products WHERE source = 'sample' LIMIT 1");
    return ['verified_at' => $v, 'stale' => $v === null || sf_time() - (int)strtotime($v) >= SF_STORE_FRESH, 'sample' => $sample];
}

/**
 * read-store-catalog: q (חיפוש), cat, author, min / max (ביחידות מטבע שלמות), sort (name / price_asc / price_desc / new), page (1…).
 * עימוד בשרת, SF_STORE_PER לעמוד. גם הפילטרים שיש להם ערכים: קטגוריות ומחברים (אם המחבר מופה).
 */
function sf_store_catalog(array $g): array
{
    $where = ['p.removed_at IS NULL'];
    $args = [];
    $q = is_string($g['q'] ?? null) ? sf_norm(mb_substr($g['q'], 0, 100)) : '';
    foreach (array_slice(array_filter(explode(' ', $q), fn($w) => $w !== ''), 0, 8) as $w) {
        $where[] = "p.search LIKE ? ESCAPE '!'";   // '!' ולא '\' — עובד אותו דבר ב-SQLite וב-MySQL
        $args[] = sf_store_like($w);
    }
    if (isset($g['cat']) && ctype_digit((string)$g['cat'])) {
        $where[] = 'p.woo_id IN (SELECT product_woo_id FROM store_product_categories WHERE category_woo_id = ?)';
        $args[] = (int)$g['cat'];
    }
    if (is_string($g['author'] ?? null) && $g['author'] !== '') {
        $where[] = 'p.author = ?';
        $args[] = mb_substr($g['author'], 0, 300);
    }
    foreach (['min' => '>=', 'max' => '<='] as $k => $op) {
        if (isset($g[$k]) && is_string($g[$k]) && preg_match('/^\d{1,7}$/', $g[$k])) {
            $where[] = "p.price IS NOT NULL AND p.price $op ?";
            $args[] = (int)$g[$k] * 100;
        }
    }
    $order = match ($g['sort'] ?? 'name') {
        'price_asc' => 'p.price IS NULL, p.price, p.name, p.id',
        'price_desc' => 'p.price IS NULL, p.price DESC, p.name, p.id',
        'new' => 'p.woo_id DESC',
        default => 'p.name, p.id',
    };
    $w = implode(' AND ', $where);
    $total = (int)sf_val("SELECT COUNT(*) FROM store_products p WHERE $w", $args);
    $pages = max(1, (int)ceil($total / SF_STORE_PER));
    $page = isset($g['page']) && ctype_digit((string)$g['page']) ? max(1, min($pages, (int)$g['page'])) : 1;
    $rows = sf_rows("SELECT p.* FROM store_products p WHERE $w ORDER BY $order LIMIT " . SF_STORE_PER . ' OFFSET ' . (($page - 1) * SF_STORE_PER), $args);
    $cats = sf_rows('SELECT c.woo_id AS id, c.name, c.parent, (SELECT COUNT(*) FROM store_product_categories pc JOIN store_products p ON p.woo_id = pc.product_woo_id
            WHERE pc.category_woo_id = c.woo_id AND p.removed_at IS NULL) AS n FROM store_categories c WHERE c.removed_at IS NULL ORDER BY c.name');
    $out = ['products' => array_map('sf_store_card', $rows), 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => SF_STORE_PER,
        'categories' => array_values(array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'parent' => (int)$c['parent'], 'n' => (int)$c['n']],
            array_filter($cats, fn($c) => (int)$c['n'] > 0))),
        'status' => sf_store_status()];
    if (isset(sf_store_map()['author'])) {
        $out['authors'] = array_map(fn($r) => $r['author'], sf_rows('SELECT author FROM store_products WHERE removed_at IS NULL AND author IS NOT NULL GROUP BY author ORDER BY author LIMIT 300'));
    }
    return $out;
}

/** read-store-product: עמוד המוצר. צפייה נספרת (מצרפי ליום, בלי קורא). */
function sf_store_product(array $g): array
{
    $wid = isset($g['id']) && ctype_digit((string)$g['id']) ? (int)$g['id'] : 0;
    $r = $wid ? sf_row('SELECT * FROM store_products WHERE woo_id = ? AND removed_at IS NULL', [$wid]) : null;
    if (!$r) sf_fail('not_found');
    $raw = json_decode((string)$r['raw'], true) ?: [];
    $out = sf_store_card($r);
    $out['images'] = $raw['images'] ?? [];
    $out['description'] = sf_store_text($raw['description'] ?? '');
    $out['short'] = sf_store_text($raw['short'] ?? '', 20);
    if (($raw['sku'] ?? '') !== '') $out['sku'] = $raw['sku'];
    $out += sf_store_fields($raw);   // מחבר, הוצאה, מהדורה, כריכה, תוכן עניינים, דפי דוגמה — רק מה שמופה ויש לו ערך
    $out['buy_url'] = $out['buy'] ? $r['permalink'] : null;
    $cids = $raw['categories'] ?? [];
    $out['categories'] = $cids ? array_map(fn($c) => ['id' => (int)$c['woo_id'], 'name' => $c['name']],
        sf_rows('SELECT woo_id, name FROM store_categories WHERE removed_at IS NULL AND woo_id IN (' . implode(',', array_fill(0, count($cids), '?')) . ') ORDER BY name', $cids)) : [];
    $rel = array_values(array_diff($raw['related'] ?? [], [$wid]));
    $out['related'] = $rel ? array_map('sf_store_card', sf_rows('SELECT * FROM store_products WHERE removed_at IS NULL AND woo_id IN (' . implode(',', array_fill(0, count($rel), '?')) . ') ORDER BY name LIMIT 8', $rel)) : [];
    $out['reviews'] = array_map(fn($v) => ['reviewer' => $v['reviewer'], 'rating' => $v['rating'] !== null ? (int)$v['rating'] : null,
        'text' => json_decode((string)$v['body'], true) ?: [], 'verified' => (bool)$v['verified'], 'at' => $v['created_at']],
        sf_rows('SELECT * FROM store_reviews WHERE product_woo_id = ? AND removed_at IS NULL ORDER BY created_at DESC, id DESC LIMIT 50', [$wid]));
    // "יש גם ספר דיגיטלי" — רק כותר שהקורא רשאי לראות (אותו תנאי של הקורא, 04), והמהדורה הראשונה שפורסמה
    $out['digital'] = array_map(fn($t) => ['title' => $t['name'], 'edition' => $t['e_pid']], sf_rows('SELECT t.name, (SELECT e.pid FROM editions e WHERE e.title_id = t.id
            AND EXISTS (SELECT 1 FROM revisions rv WHERE rv.edition_id = e.id AND rv.status = \'published\') ORDER BY e.id LIMIT 1) AS e_pid
        FROM title_products tp JOIN titles t ON t.id = tp.title_id WHERE tp.product_woo_id = ? AND ' . SF_READER_TITLE . ' ORDER BY t.name', [$wid]));
    $out['digital'] = array_values(array_filter($out['digital'], fn($d) => $d['edition'] !== null));
    $out['status'] = sf_store_status();
    sf_store_stat('view', $wid);
    return $out;
}

/** מונה מצרפי ליום (UTC). בלי קורא, בלי IP. */
function sf_store_stat(string $kind, int $wid): void
{
    $day = gmdate('Y-m-d', sf_time());
    sf_tx(function () use ($kind, $wid, $day) {
        if (!sf_exec('UPDATE store_stats SET n = n + 1 WHERE day = ? AND kind = ? AND product_woo_id = ?', [$day, $kind, $wid])) {
            sf_insert('store_stats', ['day' => $day, 'kind' => $kind, 'product_woo_id' => $wid, 'n' => 1]);
        }
    });
}

/**
 * store-out (guest): "לרכישה באתר" נלחץ — נספר כ"מעבר לחנות" (10). POST בלי אסימון: הנתב בודק סוג מדיה מדויק ו-Sec-Fetch-Site /
 * Origin (כמו 05). עד 60 לדקה לכתובת IP (נשמר כ-HMAC). מחזיר את הכתובת — **מהמטמון, לא ממה שנשלח**.
 */
function sf_store_out(array $in, array $env): array
{
    $wid = is_int($in['id'] ?? null) ? $in['id'] : 0;
    $r = $wid > 0 ? sf_row('SELECT woo_id, source, permalink, purchasable FROM store_products WHERE woo_id = ? AND removed_at IS NULL', [$wid]) : null;
    if (!$r || $r['permalink'] === null) sf_fail('not_found');
    // 10: מוצר שלא ניתן לרכישה — אין מעבר; קישור שנשאר ממארח קודם של החנות (עד הסנכרון המלא) — לא יוצא
    if (!(int)$r['purchasable']) sf_fail('state', 'המוצר לא זמין לרכישה כרגע');
    $host = strtolower((string)parse_url((string)$r['permalink'], PHP_URL_HOST));
    if ($host === '' || $host !== ($r['source'] === 'sample' ? SF_STORE_SAMPLE_HOST : sf_woo_host())) sf_fail('not_found');
    $ip = (string)($env['ip'] ?? '');
    sf_rate('store_out', $ip, SF_STORE_OUT_PER_MIN, 60);
    sf_attempt_add('store_out', $ip);
    sf_store_stat('out', $wid);
    return ['url' => $r['permalink']];
}

// ── הניהול ─────────────────────────────────────────────────

/** store-admin: מצב הסנכרון, המיפוי, הקישורים לכותרים, השדות שנמצאו במוצרים (לבחירה במיפוי). */
function sf_store_admin(): array
{
    $runs = array_map(fn($r) => ['pid' => $r['pid'], 'kind' => $r['kind'], 'source' => $r['source'], 'status' => $r['status'], 'stage' => $r['stage'],
        'products' => (int)$r['products'], 'requests' => (int)$r['requests'], 'error' => $r['error'], 'started_at' => $r['started_at'], 'finished_at' => $r['finished_at']],
        sf_rows('SELECT * FROM store_sync_runs ORDER BY id DESC LIMIT 10'));
    // השמות שקיימים במוצרים — כדי שהמיפוי ייבחר מרשימה ולא יוקלד בעיוור
    $attrs = $metas = [];
    foreach (sf_rows('SELECT raw FROM store_products WHERE removed_at IS NULL ORDER BY id DESC LIMIT 300') as $r) {
        $raw = json_decode((string)$r['raw'], true) ?: [];
        foreach ($raw['attributes'] ?? [] as $a) $attrs[$a['name']] = ($attrs[$a['name']] ?? 0) + 1;
        foreach ($raw['meta'] ?? [] as $m) $metas[$m['key']] = ($metas[$m['key']] ?? 0) + 1;
    }
    arsort($attrs);
    arsort($metas);
    return ['connection' => sf_woo_public(), 'map' => (object)sf_store_map(), 'fields' => SF_STORE_FIELDS, 'runs' => $runs,
        'counts' => ['products' => (int)sf_val('SELECT COUNT(*) FROM store_products WHERE removed_at IS NULL'),
            'removed' => (int)sf_val('SELECT COUNT(*) FROM store_products WHERE removed_at IS NOT NULL'),
            'categories' => (int)sf_val('SELECT COUNT(*) FROM store_categories WHERE removed_at IS NULL'),
            'reviews' => (int)sf_val('SELECT COUNT(*) FROM store_reviews WHERE removed_at IS NULL')],
        'status' => sf_store_status(), 'full_at' => sf_meta('store_full_at'), 'error_at' => sf_meta('store_last_error_at'),
        'seen' => ['attributes' => array_slice(array_keys($attrs), 0, 60), 'meta' => array_slice(array_keys($metas), 0, 60)],
        'links' => array_map(fn($l) => ['title' => $l['t_pid'], 'title_name' => $l['t_name'], 'product' => (int)$l['product_woo_id'], 'product_name' => $l['p_name']],
            sf_rows('SELECT t.pid AS t_pid, t.name AS t_name, tp.product_woo_id, p.name AS p_name FROM title_products tp JOIN titles t ON t.id = tp.title_id
                LEFT JOIN store_products p ON p.woo_id = tp.product_woo_id WHERE t.deleted_at IS NULL ORDER BY t.name, tp.id')),
        'stats' => sf_rows('SELECT kind, SUM(n) AS n FROM store_stats WHERE day >= ? GROUP BY kind', [gmdate('Y-m-d', sf_time() - 30 * 86400)])];
}

/** store-sync-now: ריצה עכשיו (full / delta), ומיד צעד ראשון — המנהל לא מחכה ל-cron. */
function sf_store_sync_now(array $in): array
{
    if (!sf_woo_ready()) sf_fail('state', 'עוד אין חיבור לחנות — כתובת ומפתחות במסך המפתח');
    $run = sf_store_run_start(($in['kind'] ?? '') === 'full' ? 'full' : 'delta');
    $job = sf_val("SELECT pid FROM jobs WHERE kind = 'store.sync' AND status = 'pending' ORDER BY id DESC LIMIT 1");
    $res = $job ? sf_job_run_now((string)$job) : 'not_pending';
    sf_audit('store.sync_now', null, null, ['kind' => $run['kind']]);
    $r = sf_row('SELECT pid, kind, status, stage, products, error FROM store_sync_runs WHERE pid = ?', [$run['pid']]);
    return ['run' => $r, 'job' => $res];
}

/** store-sample: on — טעינת נתוני הדוגמה (רק כשאין נתונים מהחנות); off — הסרה. */
function sf_store_sample(array $in): array
{
    if (($in['on'] ?? null) === false) {
        sf_tx(function () {
            sf_store_drop_sample();
            sf_exec("DELETE FROM store_sync_runs WHERE source = 'sample'");
            sf_exec("DELETE FROM meta WHERE k = 'store_sample_at'");
        });
        sf_audit('store.sample', null, null, ['on' => false]);
        return ['sample' => false];
    }
    if (sf_val("SELECT 1 FROM store_products WHERE source = 'woo' LIMIT 1")) sf_fail('state', 'יש כבר נתונים מהחנות — נתוני הדוגמה לא נטענים מעליהם');
    // מיפוי ריק ← מיפוי לשמות שבנתוני הדוגמה (כדי שיהיה מה לראות). מיפוי קיים — לא נוגעים
    if (!sf_store_map()) sf_meta_set('store_map', json_encode(sf_store_sample_data()['map'], JSON_UNESCAPED_UNICODE));
    $run = sf_store_run_start('full', 'sample');
    $res = sf_store_run_step((string)$run['pid']);
    sf_audit('store.sample', null, null, ['on' => true]);
    return ['sample' => true, 'result' => $res];
}

/** store-link-set: {title (pid), product (woo id), on} — הקישור כותר ↔ מוצר. */
function sf_store_link_set(array $in): array
{
    $t = sf_is_pid($in['title'] ?? null) ? sf_row('SELECT id, pid FROM titles WHERE pid = ? AND deleted_at IS NULL', [$in['title']]) : null;
    if (!$t) sf_fail('not_found');
    $wid = is_int($in['product'] ?? null) && $in['product'] > 0 ? $in['product'] : sf_fail('bad_input');
    if (($in['on'] ?? true) === false) {
        sf_exec('DELETE FROM title_products WHERE title_id = ? AND product_woo_id = ?', [$t['id'], $wid]);
    } else {
        if (!sf_val('SELECT 1 FROM store_products WHERE woo_id = ?', [$wid])) sf_fail('not_found', 'המוצר לא נמצא בקטלוג');
        if ((int)sf_val('SELECT COUNT(*) FROM title_products WHERE title_id = ?', [$t['id']]) >= 50) sf_fail('state', 'עד 50 מוצרים לכותר');
        if (!sf_val('SELECT 1 FROM title_products WHERE title_id = ? AND product_woo_id = ?', [$t['id'], $wid])) {
            sf_insert('title_products', ['title_id' => $t['id'], 'product_woo_id' => $wid, 'created_at' => sf_now()]);
        }
    }
    sf_audit('store.link', 'title', $t['pid'], ['product' => $wid, 'on' => ($in['on'] ?? true) !== false]);
    return ['ok' => true];
}

// ── בדיקת תקינות (01) ──────────────────────────────────────

sf_health_register('store', 'החנות (WooCommerce)', function () {
    if (!sf_woo_ready()) {
        $sample = sf_val("SELECT 1 FROM store_products WHERE source = 'sample' LIMIT 1");
        return ['warn', 'עוד אין חיבור לחנות (📦 §24: כתובת ומפתחות קריאה)' . ($sample ? ' · מוצגים נתוני דוגמה' : '')];
    }
    $last = sf_row("SELECT status, error, started_at FROM store_sync_runs WHERE source = 'woo' AND status <> 'running' ORDER BY id DESC LIMIT 1");
    $v = sf_meta('store_verified_at');
    $n = (int)sf_val("SELECT COUNT(*) FROM store_products WHERE source = 'woo' AND removed_at IS NULL");
    if ($last && $last['status'] === 'failed') return ['bad', 'הסנכרון האחרון נכשל: ' . $last['error'] . ' · עודכן לאחרונה ' . ($v ?: 'אף פעם') . " · $n מוצרים שמורים"];
    if (!$v) return ['warn', 'עוד לא היה סנכרון שהצליח'];
    if (sf_time() - (int)strtotime($v) >= SF_STORE_FRESH) return ['warn', "הסנכרון האחרון שהצליח — $v (מעל 24 שעות): המחירים מוצגים כלא מאומתים"];
    return ['ok', "$n מוצרים · עודכן $v"];
});
