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
function fetchHead(string $url, array $opts = []): array {
    if (!function_exists('curl_init')) fail('cURL אינו זמין בשרת', 'no_curl');

    $maxBytes = (int) ($opts['max_bytes'] ?? MAX_BYTES);
    $extra    = (array) ($opts['headers'] ?? []);
    $agent    = (string) ($opts['ua'] ?? 'Mozilla/5.0 (compatible; frame-browser/1.0)');
    $started  = microtime(true);

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
            CURLOPT_USERAGENT      => $agent,
            CURLOPT_HTTPHEADER     => array_merge(['Accept: text/html,application/xhtml+xml'], $extra),
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
                // עוצרים אחרי הכמות שנדרשה; אין צורך בדף שלם.
                return strlen($body) > $maxBytes ? 0 : strlen($chunk);
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

        return ['url' => $url, 'status' => $status, 'headers' => $headers, 'body' => $body,
                'ms' => (int) round((microtime(true) - $started) * 1000)];
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


/* ── אבחון מעמיק ───────────────────────────────────────────────
 *
 * במקום לומר "נכשל", לנסות בפועל כמה דרכים ולהראות מה כל אחת החזירה.
 * ההבדל בין הניסיונות אינו קוסמטי: אתרים רבים מסננים לפי מזהה הדפדפן
 * או דורשים Referer, ואותה כתובת מחזירה 403 באחד ו-200 באחר.
 */

const BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                 . '(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

/** הדרכים שננסה, לפי הסדר. כל אחת משנה דבר אחד, כדי שיהיה ברור מה עזר. */
function probeProfiles(string $url): array {
    $host = parse_url($url, PHP_URL_HOST) ?: '';
    $root = 'https://' . $host . '/';

    return [
        [
            'name' => 'בקשה רגילה',
            'why'  => 'ברירת המחדל — מה שקורה בבדיקה הרגילה',
            'opts' => [],
        ],
        [
            'name' => 'עם מזהה של דפדפן',
            'why'  => 'אתרים רבים חוסמים כל מי שאינו מציג עצמו כדפדפן',
            'opts' => ['ua' => BROWSER_UA, 'headers' => [
                'Accept-Language: he-IL,he;q=0.9,en;q=0.8',
                'Sec-Fetch-Dest: document', 'Sec-Fetch-Mode: navigate', 'Sec-Fetch-Site: none',
                'Upgrade-Insecure-Requests: 1',
            ]],
        ],
        [
            'name' => 'עם Referer מהאתר עצמו',
            'why'  => 'דפים שמוגשים רק למי שהגיע מתוך האתר',
            'opts' => ['ua' => BROWSER_UA, 'headers' => ["Referer: $root"]],
        ],
    ];
}

/** מסכם ניסיון יחיד לשורה שאפשר להציג. */
function probeRow(string $name, string $why, array $res, string $origin): array {
    [$allowed, $reason] = framingVerdict($res, $origin);
    $challenge = looksLikeChallenge($res);

    if ($challenge)       $verdict = 'challenge';
    elseif (!$allowed)    $verdict = 'blocked';
    elseif ($res['status'] >= 400) $verdict = 'http_error';
    else                  $verdict = 'ok';

    return [
        'name'    => $name,
        'why'     => $why,
        'status'  => $res['status'],
        'ms'      => $res['ms'] ?? 0,
        'server'  => $res['headers']['server'] ?? '',
        'xfo'     => $res['headers']['x-frame-options'] ?? '',
        'csp'     => frameAncestorsOf($res['headers']['content-security-policy'] ?? ''),
        'type'    => $res['headers']['content-type'] ?? '',
        'bytes'   => strlen($res['body']),
        'title'   => pageTitle($res['body']),
        'verdict' => $verdict,
        'reason'  => $reason,
    ];
}

/** רק החלק שנוגע להטמעה מתוך CSP שלם — השאר רעש על המסך. */
function frameAncestorsOf(string $csp): string {
    if ($csp === '' || !preg_match('/frame-ancestors([^;]*)/i', $csp, $m)) return '';
    return trim($m[1]);
}

/**
 * מחפש בדף מסגרות פנימיות.
 *
 * זה הלב של האבחון: דף עטיפה חוסם הטמעה, בזמן שהנגן שבתוכו — שנועד
 * מלכתחילה להיות מוטמע באתרים אחרים — פתוח לגמרי. מי שמוצא את הכתובת
 * הפנימית מקבל את התוכן בלי לעקוף דבר.
 */
function findFrameCandidates(string $html, string $base): array {
    $out = [];

    // src של iframe, וגם data-src שנטענים ב-JS מאוחר יותר.
    if (preg_match_all('#<iframe[^>]+(?:data-)?src\s*=\s*["\']([^"\']+)["\']#i', $html, $m)) {
        foreach ($m[1] as $src) $out[] = $src;
    }
    // כתובות שנראות כמו נגן ומופיעות בקוד גם בלי תגית iframe.
    if (preg_match_all('#["\'](https?://[^"\'\s]*(?:embed|player|stream|iframe)[^"\'\s]*)["\']#i', $html, $m)) {
        foreach ($m[1] as $src) $out[] = $src;
    }

    $seen = [];
    $clean = [];
    foreach ($out as $src) {
        $src = html_entity_decode(trim($src), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($src === '' || str_starts_with($src, 'data:') || str_starts_with($src, 'about:')) continue;

        $abs = str_starts_with($src, '//') ? 'https:' . $src : $src;
        if (!preg_match('#^https?://#i', $abs)) {
            if (!str_starts_with($abs, '/') && !preg_match('#^[\w.-]+/#', $abs)) continue;
            $abs = resolveUrl($base, $abs);
        }
        if (isset($seen[$abs])) continue;
        $seen[$abs] = true;

        // פרסום ומדידה אינם נגנים, והם מציפים את הרשימה.
        foreach (['doubleclick', 'googletagmanager', 'google-analytics', 'facebook.com/tr',
                  'googlesyndication', 'adservice', 'hotjar', 'recaptcha', 'gstatic'] as $noise) {
            if (str_contains(strtolower($abs), $noise)) continue 2;
        }
        $clean[] = $abs;
    }

    // מה שנראה כמו נגן קודם: זה מה שהמשתמש מחפש.
    usort($clean, function ($a, $b) {
        $score = fn($u) => preg_match('#embed|player|stream#i', $u) ? 0 : 1;
        return $score($a) <=> $score($b);
    });

    return array_slice($clean, 0, 8);
}

/**
 * מריץ את כל האבחון ומחזיר דוח.
 *
 * הסדר חשוב: קודם שלוש הדרכים על הכתובת עצמה, ואז — מתוך התשובה
 * המוצלחת ביותר — חיפוש מסגרות פנימיות ובדיקה של כל אחת מהן.
 */
function deepProbe(string $url, string $origin): array {
    $attempts = [];
    $best     = null;
    $bestReal = false;   // האם ה"טובה ביותר" היא תוכן אמיתי ולא מסך אתגר

    foreach (probeProfiles($url) as $profile) {
        try {
            $res = fetchHead($url, $profile['opts'] + ['max_bytes' => 400000]);
            $row = probeRow($profile['name'], $profile['why'], $res, $origin);

            // הדף שממנו נחפש מסגרות פנימיות: התוכן האמיתי הגדול ביותר.
            // מסך אתגר משמש רק אם לא התקבל דבר טוב ממנו.
            $real = $row['verdict'] !== 'challenge' && $res['status'] < 400;
            if ($best === null
                || ($real && (!$bestReal || strlen($res['body']) > strlen($best['body'])))) {
                $best     = $res;
                $bestReal = $real;
            }
        } catch (InspectError $e) {
            $row = ['name' => $profile['name'], 'why' => $profile['why'], 'status' => 0, 'ms' => 0,
                    'server' => '', 'xfo' => '', 'csp' => '', 'type' => '', 'bytes' => 0,
                    'title' => '', 'verdict' => 'failed', 'reason' => $e->getMessage()];
        }
        $attempts[] = $row;
    }

    $candidates = [];
    if ($best && $best['body'] !== '') {
        foreach (findFrameCandidates($best['body'], $best['url']) as $cand) {
            $entry = ['url' => $cand, 'framable' => null, 'reason' => '', 'status' => 0];
            try {
                $r = fetchHead($cand, ['ua' => BROWSER_UA, 'max_bytes' => 8192]);
                [$ok, $why] = framingVerdict($r, $origin);
                $entry['status']   = $r['status'];
                $entry['framable'] = $ok && !looksLikeChallenge($r);
                $entry['reason']   = looksLikeChallenge($r) ? 'שירות הגנה חסם את הבדיקה' : $why;
                $entry['title']    = pageTitle($r['body']);
            } catch (InspectError $e) {
                $entry['reason'] = $e->getMessage();
                $entry['framable'] = false;
            }
            $candidates[] = $entry;
        }
    }

    return ['attempts' => $attempts, 'candidates' => $candidates,
            'conclusion' => probeConclusion($attempts, $candidates)];
}

/** המסקנה בשורה אחת — מה שהמשתמש באמת צריך לדעת מכל הטבלה. */
function probeConclusion(array $attempts, array $candidates): string {
    $open = array_values(array_filter($candidates, fn($c) => $c['framable'] === true));
    if ($open) {
        return 'נמצאה מסגרת פנימית שכן ניתנת להטמעה. זה בדרך כלל הנגן עצמו — נסו אותה.';
    }

    $verdicts = array_column($attempts, 'verdict');
    if (in_array('ok', $verdicts, true)) {
        return 'אחת השיטות הצליחה. אם הדף עדיין אינו נטען, החסימה נעשית בקוד של האתר ולא בכותרות.';
    }
    if (in_array('blocked', $verdicts, true)) {
        return 'האתר אוסר הטמעה במפורש, ולא נמצאה בתוכו מסגרת פתוחה. פתיחה בלשונית היא הדרך.';
    }
    if (in_array('challenge', $verdicts, true)) {
        return 'שירות הגנה חוסם כל פנייה מהשרת שלנו — מה שהתקבל הוא מסך אתגר, לא הדף. '
             . 'הדפדפן שלכם כן עובר אותו: הדביקו למטה את מקור הדף, ואמצא בו את הנגן.';
    }
    return 'אף שיטה לא הצליחה להגיע לאתר. ייתכן שהכתובת שגויה או שהאתר אינו זמין.';
}
