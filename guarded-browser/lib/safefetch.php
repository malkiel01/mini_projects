<?php
/**
 * בקשות יוצאות לכתובות שלא אנחנו בחרנו (סורק החיפוש: אתרים שההורה התיר, sitemap, דפים מתוכן חיצוני).
 *
 * סקירת אבטחה (2026-10-20): httpGet עקב אחרי הפניות לבד ולא בדק לאן הוא מתחבר — כתובת (או הפניה,
 * או שם DNS) שמובילה ל-127.0.0.1, לרשת הפנימית או לשרת המטא-דאטה של הענן הייתה נסרקת מהשרת שלנו.
 * כאן, לכל בקשה ולכל הפניה:
 *   • רק http/https, ורק פורטים 80/443;
 *   • כל הכתובות שהשם מתפרש אליהן — ציבוריות (לא loopback, פרטיות, link-local, CGNAT, ULA, ממופות);
 *   • החיבור ננעל לכתובת שנבדקה (CURLOPT_RESOLVE) — אין פער בין ה-DNS שנבדק לזה שמתחברים אליו;
 *   • הפניות — ידנית, עד 3, וכל אחת נבדקת מחדש; גודל וזמן מוגבלים.
 */

declare(strict_types=1);

const SAFE_FETCH_MAX_REDIRECTS = 3;
const SAFE_FETCH_TIMEOUT = 8;

/** האם כתובת IP ציבורית (מותר להתחבר אליה מהשרת). */
function ipIsPublic(string $ip): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
    // IPv4 ממופה ב-IPv6 (‎::ffff:10.0.0.1) — בודקים את ה-IPv4 שבפנים.
    if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) return ipIsPublic($m[1]);
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $n = ip2long($ip);
        foreach ([['0.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16], ['192.0.0.0', 24],
                  ['192.0.2.0', 24], ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 3]] as [$net, $bits]) {
            $mask = -1 << (32 - $bits);
            if (($n & $mask) === (ip2long($net) & $mask)) return false;
        }
        return true;
    }
    $bin = inet_pton($ip);
    if ($bin === false) return false;
    $first = ord($bin[0]); $second = ord($bin[1]);
    if ($bin === inet_pton('::1') || $bin === inet_pton('::')) return false;
    if (($first & 0xfe) === 0xfc) return false;                         // fc00::/7 — ULA
    if ($first === 0xfe && ($second & 0xc0) === 0x80) return false;     // fe80::/10 — link-local
    if ($first === 0xff) return false;                                   // multicast
    if (substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") return false; // ממופה שלא זוהה למעלה
    return true;
}

/** כל כתובות ה-IP של שם (A ו-AAAA). ‏$resolver — להחלפה בבדיקות. */
function safeResolve(string $host, ?callable $resolver = null): array {
    if ($resolver) return $resolver($host);
    if (filter_var($host, FILTER_VALIDATE_IP)) return [$host];
    $ips = [];
    foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $r) {
        if (!empty($r['ip'])) $ips[] = $r['ip'];
        if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
    }
    if (!$ips) $ips = @gethostbynamel($host) ?: [];
    return array_values(array_unique($ips));
}

/**
 * בדיקת כתובת לפני חיבור: ['ok' => bool, 'ip' => הכתובת שננעלים עליה, 'host', 'port', 'error'].
 * כל הכתובות של השם חייבות להיות ציבוריות — אחרת שם עם כתובת ציבורית ופרטית היה עוקף.
 */
function safeUrlCheck(string $url, ?callable $resolver = null): array {
    $p = parse_url($url);
    $scheme = strtolower((string) ($p['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) return ['ok' => false, 'error' => 'סכימה אסורה'];
    if (isset($p['user']) || isset($p['pass'])) return ['ok' => false, 'error' => 'פרטי כניסה בכתובת'];
    $host = strtolower(trim((string) ($p['host'] ?? ''), '[]'));
    if ($host === '') return ['ok' => false, 'error' => 'אין שרת'];
    $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
    if (!in_array($port, [80, 443], true)) return ['ok' => false, 'error' => 'פורט אסור'];
    $ips = safeResolve($host, $resolver);
    if (!$ips) return ['ok' => false, 'error' => 'השם לא נמצא'];
    foreach ($ips as $ip) if (!ipIsPublic($ip)) return ['ok' => false, 'error' => 'יעד פנימי'];
    return ['ok' => true, 'ip' => $ips[0], 'host' => $host, 'port' => $port, 'error' => ''];
}

/**
 * ‏GET בטוח, עם אותו חוזה כמו httpGet: ['status', 'body', 'error']. ‏$exec($url, $check, $maxBytes)
 * מבצע בקשה אחת בלי הפניות ומחזיר גם 'location' — להחלפה בבדיקות.
 */
function safeHttpGet(string $url, int $maxBytes = 300000, ?callable $resolver = null, ?callable $exec = null): array {
    $exec ??= 'safeCurlOnce';
    for ($hop = 0; $hop <= SAFE_FETCH_MAX_REDIRECTS; $hop++) {
        $c = safeUrlCheck($url, $resolver);
        if (!$c['ok']) return ['status' => 0, 'body' => '', 'error' => $c['error']];
        $r = $exec($url, $c, $maxBytes);
        $status = (int) ($r['status'] ?? 0);
        if ($status >= 300 && $status < 400 && ($r['location'] ?? '') !== '') {
            $url = safeJoinUrl($url, (string) $r['location']);
            continue;
        }
        return ['status' => $status, 'body' => (string) ($r['body'] ?? ''), 'error' => (string) ($r['error'] ?? '')];
    }
    return ['status' => 0, 'body' => '', 'error' => 'יותר מדי הפניות'];
}

/** כתובת הפניה יחסית → מוחלטת. */
function safeJoinUrl(string $base, string $loc): string {
    if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $loc)) return $loc;
    $b = parse_url($base);
    $origin = ($b['scheme'] ?? 'https') . '://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($loc, '//')) return ($b['scheme'] ?? 'https') . ':' . $loc;
    if (str_starts_with($loc, '/')) return $origin . $loc;
    $dir = preg_replace('~/[^/]*$~', '/', (string) ($b['path'] ?? '/'));
    return $origin . $dir . $loc;
}

/** בקשה אחת, ננעלת לכתובת שנבדקה, בלי הפניות. */
function safeCurlOnce(string $url, array $c, int $maxBytes): array {
    if (!function_exists('curl_init')) return ['status' => 0, 'body' => '', 'error' => 'אין cURL'];
    $body = '';
    $ip = str_contains($c['ip'], ':') ? '[' . $c['ip'] . ']' : $c['ip'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_RESOLVE        => [$c['host'] . ':' . $c['port'] . ':' . $ip],
        CURLOPT_TIMEOUT        => SAFE_FETCH_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; MigdalorSearch/1.0)',
        CURLOPT_HTTPHEADER     => ['Accept-Language: he,en;q=0.8', 'Accept: text/html,application/xml,application/json,*/*'],
        CURLOPT_HEADER         => false,
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$body, $maxBytes) {
            $body .= $chunk;
            return strlen($body) > $maxBytes ? 0 : strlen($chunk);
        },
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $loc = (string) (curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: '');
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    curl_close($ch);
    return ['status' => $status, 'body' => $body, 'location' => $loc,
            'error' => ($errno && $errno !== CURLE_WRITE_ERROR) ? $err : ''];
}
