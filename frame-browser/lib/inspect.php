<?php
/**
 * בדיקת קישור לפני הטמעה — הלוגיקה.
 *
 * הבעיה שזה פותר: iframe שנחסם אינו מדווח דבר. הדפדפן פשוט מסרב לצייר,
 * והמשתמש רואה מלבן ריק בלי לדעת אם האתר איטי, הכתובת שגויה, או שהאתר
 * פשוט אינו מרשה הטמעה. השרת יכול לשאול את האתר בעצמו ולדעת מראש.
 *
 * ‏הקובץ הזה מבצע פנייה לכתובת שהמשתמש נתן, ולכן הוא שער SSRF פוטנציאלי:
 * בלי הגנה אפשר לבקש ממנו לפנות ל-127.0.0.1 או לרשת הפנימית של השרת
 * ולדלות משם מידע. שלוש שכבות מונעות זאת — סכימה ויציאה מותרות, בדיקת
 * כתובות IP פרטיות, והצמדת החיבור ל-IP שנבדק כדי שלא יוחלף בין הבדיקה
 * לפנייה.
 */

declare(strict_types=1);

const MAX_REDIRECTS = 5;
const MAX_BYTES     = 65536;   // מספיק לכותרות ול-<title>
const TIMEOUT       = 12;

/**
 * כישלון צפוי — כתובת פסולה, אתר שאינו נענה, רשת פנימית.
 *
 * חריגה ולא הדפסה-ויציאה: כך אפשר לבדוק את הלוגיקה בלי לזייף בקשת HTTP,
 * ונקודת הקצה היא היחידה שמחליטה איך נראית תשובת שגיאה.
 */
class InspectError extends RuntimeException {
    // ‏$kind ולא $code: ל-Exception כבר יש $code משלה, והיא מספרית.
    public string $kind;

    public function __construct(string $message, string $kind = 'error') {
        parent::__construct($message);
        $this->kind = $kind;
    }
}

function fail(string $message, string $code = 'error') {
    throw new InspectError($message, $code);
}

/* ── הגנות ─────────────────────────────────────────────────────── */

/**
 * האם הכתובת שייכת לרשת שאסור לפנות אליה.
 *
 * ‏FILTER_FLAG_NO_PRIV_RANGE ו-NO_RES_RANGE מכסים את הטווחים הפרטיים
 * והשמורים, כולל IPv6. כתובת שאינה עוברת אותם היא לולאה מקומית, רשת
 * פנימית, או link-local — כל אלה בתוך השרת, ואין להם מה לעשות כאן.
 */
function isForbiddenIp(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP,
                      FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

/** מאמת כתובת ומחזיר את חלקיה, או נכשל עם סיבה מובנת. */
function checkUrl(string $url): array {
    $parts = parse_url($url);
    if (!$parts) fail('הכתובת אינה תקינה', 'bad_url');

    // הסכימה נבדקת ראשונה: ל-file:/// אין host, ובדיקת host קודמת הייתה
    // מחזירה "כתובת לא תקינה" במקום לומר מה באמת לא מותר.
    $scheme = strtolower($parts['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true)) {
        fail('רק כתובות http ו-https נתמכות', 'bad_scheme');
    }
    if (empty($parts['host'])) fail('הכתובת אינה תקינה', 'bad_url');

    $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    if (!in_array($port, [80, 443], true)) {
        fail('רק היציאות הרגילות (80 ו-443) נתמכות', 'bad_port');
    }

    $host = $parts['host'];

    // כתובת IP שנכתבה ישירות — נבדקת כמות שהיא.
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (isForbiddenIp($host)) fail('כתובת ברשת פנימית אינה מותרת', 'private');
        return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'ip' => $host];
    }

    $ips = [];
    foreach (['A', 'AAAA'] as $type) {
        foreach (@dns_get_record($host, $type === 'A' ? DNS_A : DNS_AAAA) ?: [] as $rec) {
            $ip = $rec['ip'] ?? $rec['ipv6'] ?? '';
            if ($ip !== '') $ips[] = $ip;
        }
    }
    if (!$ips) {
        $resolved = gethostbyname($host);
        if ($resolved !== $host) $ips[] = $resolved;
    }
    if (!$ips) fail('לא הצלחתי למצוא את השרת של הכתובת הזו', 'dns');

    // די בכתובת אסורה אחת כדי לפסול: שם שמצביע גם החוצה וגם פנימה הוא
    // בדיוק הדרך לעקוף בדיקה שמסתפקת בכתובת הראשונה.
    foreach ($ips as $ip) {
        if (isForbiddenIp($ip)) fail('הכתובת מצביעה לרשת פנימית', 'private');
    }

    return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'ip' => $ips[0]];
}

/* ── הפנייה ────────────────────────────────────────────────────── */

/**
 * מביא כותרות ותחילת גוף.
 *
 * ‏CURLOPT_RESOLVE מצמיד את החיבור ל-IP שנבדק. בלעדיו נשארת חלון שבו
 * ה-DNS מחזיר כתובת ציבורית לבדיקה וכתובת פנימית לפנייה עצמה.
 * ההפניות מטופלות ידנית, כי כל קפיצה היא כתובת חדשה שצריכה בדיקה משלה.
 */
function fetchHead(string $url): array {
    if (!function_exists('curl_init')) fail('cURL אינו זמין בשרת', 'no_curl');

    $seen = [];
    for ($hop = 0; $hop <= MAX_REDIRECTS; $hop++) {
        if (isset($seen[$url])) fail('הכתובת מפנה לעצמה במעגל', 'loop');
        $seen[$url] = true;

        $p    = checkUrl($url);
        $body = '';
        $headers = [];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_RESOLVE        => ["{$p['host']}:{$p['port']}:{$p['ip']}"],
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; frame-browser/1.0)',
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml'],
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                $bits = explode(':', $line, 2);
                if (count($bits) === 2) {
                    $name = strtolower(trim($bits[0]));
                    // כותרת שחוזרת פעמיים (CSP למשל) — שתיהן חלות.
                    $headers[$name] = isset($headers[$name])
                        ? $headers[$name] . ', ' . trim($bits[1])
                        : trim($bits[1]);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body) {
                $body .= $chunk;
                // עוצרים אחרי הכמות שמספיקה ל-<title>; אין צורך בדף שלם.
                return strlen($body) > MAX_BYTES ? 0 : strlen($chunk);
            },
        ]);

        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        $errno  = curl_errno($ch);
        curl_close($ch);

        // ‏23 = הפסקנו את הכתיבה בכוונה אחרי MAX_BYTES.
        if ($status === 0 && $errno !== CURLE_WRITE_ERROR) {
            fail('לא הצלחתי להגיע לאתר: ' . ($err ?: 'אין תשובה'), 'unreachable');
        }

        if (in_array($status, [301, 302, 303, 307, 308], true) && !empty($headers['location'])) {
            $url = resolveUrl($url, $headers['location']);
            continue;
        }

        return ['url' => $url, 'status' => $status, 'headers' => $headers, 'body' => $body];
    }

    fail('יותר מדי הפניות', 'too_many_redirects');
}

/** הופך Location יחסי לכתובת מלאה. */
function resolveUrl(string $base, string $location): string {
    if (preg_match('#^https?://#i', $location)) return $location;

    $p = parse_url($base);
    $root = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');

    if (str_starts_with($location, '/')) return $root . $location;

    $dir = rtrim(dirname($p['path'] ?? '/'), '/');
    return $root . $dir . '/' . $location;
}

/* ── ניתוח החסימה ──────────────────────────────────────────────── */

/**
 * מכריע אם הדף ניתן להטמעה, ומסביר למה לא.
 *
 * שני מנגנונים חיים במקביל: X-Frame-Options הישן, ו-frame-ancestors
 * בתוך CSP שגובר עליו. די באחד מהם כדי לחסום.
 */
function framingVerdict(array $res, string $origin): array {
    $h = $res['headers'];

    $csp = $h['content-security-policy'] ?? '';
    if ($csp !== '' && preg_match('/frame-ancestors([^;]*)/i', $csp, $m)) {
        $list = strtolower(trim($m[1]));

        if ($list === '' || str_contains($list, "'none'")) {
            return [false, 'האתר אוסר הטמעה בכל אתר אחר (frame-ancestors: none)'];
        }
        if (str_contains($list, '*')) return [true, ''];
        if (str_contains($list, "'self'") && !str_contains($list, $origin)) {
            return [false, 'האתר מרשה הטמעה רק בתוך עצמו (frame-ancestors: self)'];
        }
        if (!str_contains($list, strtolower($origin))) {
            return [false, 'האתר מרשה הטמעה רק באתרים מסוימים, ואנחנו לא ברשימה'];
        }
        return [true, ''];
    }

    $xfo = strtolower(trim($h['x-frame-options'] ?? ''));
    if ($xfo !== '') {
        if (str_contains($xfo, 'deny')) {
            return [false, 'האתר אוסר הטמעה בכל מקום (X-Frame-Options: DENY)'];
        }
        if (str_contains($xfo, 'sameorigin')) {
            return [false, 'האתר מרשה הטמעה רק בתוך עצמו (X-Frame-Options: SAMEORIGIN)'];
        }
        if (str_contains($xfo, 'allow-from')) {
            return [false, 'האתר מרשה הטמעה רק באתר מסוים אחר'];
        }
    }

    return [true, ''];
}

/**
 * האם התשובה היא מסך אתגר של שירות הגנה, ולא הדף עצמו.
 *
 * ‏Cloudflare ודומיו מחזירים 403 עם דף "Just a moment…" לכל פנייה שאינה
 * נראית להם כדפדפן אמיתי. זה חוסם את הבדיקה שלנו מהשרת, אבל אינו אומר
 * דבר על מה שיקרה בדפדפן של המשתמש — שם יש עוגיות, JS, והיסטוריה.
 */
function looksLikeChallenge(array $res): bool {
    $h = $res['headers'];

    if (isset($h['cf-mitigated'])) return true;
    if (!in_array($res['status'], [401, 403, 429, 503], true)) return false;

    $server = strtolower($h['server'] ?? '');
    if (str_contains($server, 'cloudflare') || isset($h['cf-ray'])) return true;

    $body = strtolower(substr($res['body'], 0, 4000));
    foreach (['just a moment', 'checking your browser', 'attention required',
              'ddos protection', 'enable javascript and cookies'] as $sign) {
        if (str_contains($body, $sign)) return true;
    }
    return false;
}

function pageTitle(string $body): string {
    if (!preg_match('#<title[^>]*>(.*?)</title>#is', $body, $m)) return '';

    $title = html_entity_decode(trim(preg_replace('/\s+/', ' ', $m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // דפים רבים אינם מכריזים על קידוד בכותרת; המרה עיוורת הייתה הופכת
    // עברית תקינה לג'יבריש, ולכן ממירים רק כשברור שזה לא UTF-8.
    if (!mb_check_encoding($title, 'UTF-8')) {
        $title = mb_convert_encoding($title, 'UTF-8', 'Windows-1255, ISO-8859-8, ISO-8859-1');
    }
    return mb_substr($title, 0, 200);
}

