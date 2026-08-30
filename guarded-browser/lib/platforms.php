<?php
/**
 * פלטפורמות שדורשות היתר מפורט מהאתר כולו.
 *
 * יוטיוב אינו אתר אחד. דף הבית, החיפוש, ערוץ וסרטון הם דברים שונים
 * לחלוטין מבחינת מה שראוי להתיר, ו"יוטיוב מותר" או "יוטיוב חסום" הן
 * שתי תשובות גסות מדי לשאלה האמיתית.
 *
 * הפירוק לכתובת (מה זה — סרטון? ערוץ? חיפוש?) הוא טהור וניתן לבדיקה.
 * רק פענוח "לאיזה ערוץ שייך הסרטון" דורש רשת, והוא מופרד לקובץ הזה
 * בנפרד ועם מטמון.
 */

declare(strict_types=1);

const PLATFORM_YOUTUBE = 'youtube';

/** האם הדומיין שייך לפלטפורמה מנוהלת, ואיזו. */
function platformOf(string $host): string {
    $host = strtolower($host);
    if (str_starts_with($host, 'www.')) $host = substr($host, 4);

    foreach (['youtube.com', 'youtu.be', 'youtube-nocookie.com', 'm.youtube.com',
              'music.youtube.com'] as $d) {
        if ($host === $d || str_ends_with($host, '.' . $d)) return PLATFORM_YOUTUBE;
    }
    return '';
}

/**
 * מפרק כתובת יוטיוב לסוג ולמזהה.
 *
 * מחזיר ['kind' => ..., 'id' => ...] כאשר kind הוא:
 *   video | shorts | channel | handle | playlist | search | home | other
 *
 * ‏handle (‎@name) נפרד מ-channel: המזהה שיוטיוב מחזיר הוא UC..., וכינוי
 * אינו ניתן להשוואה מולו בלי פענוח. מי שמאשר כינוי מקבל התאמה לפי
 * הכינוי; מי שמאשר UC מקבל התאמה לפי המזהה.
 */
function parseYouTube(string $url): array {
    $p = parse_url($url);
    if (!$p || empty($p['host'])) return ['kind' => 'other', 'id' => ''];

    $host = strtolower($p['host']);
    if (str_starts_with($host, 'www.')) $host = substr($host, 4);
    $path = rtrim($p['path'] ?? '/', '/');
    if ($path === '') $path = '/';

    parse_str($p['query'] ?? '', $q);

    // ‏youtu.be/VIDEOID — הקישור המקוצר.
    if ($host === 'youtu.be') {
        $id = ltrim($path, '/');
        return $id !== '' ? ['kind' => 'video', 'id' => $id] : ['kind' => 'home', 'id' => ''];
    }

    if ($path === '/watch' && !empty($q['v'])) {
        return ['kind' => 'video', 'id' => (string) $q['v']];
    }
    // ‏/embed/ID ו-/v/ID — נגן מוטמע.
    if (preg_match('#^/(?:embed|v)/([A-Za-z0-9_-]{6,})#', $path, $m)) {
        return ['kind' => 'video', 'id' => $m[1]];
    }
    if (preg_match('#^/shorts/([A-Za-z0-9_-]{6,})#', $path, $m)) {
        return ['kind' => 'shorts', 'id' => $m[1]];
    }
    if (preg_match('#^/channel/(UC[A-Za-z0-9_-]{10,})#', $path, $m)) {
        return ['kind' => 'channel', 'id' => $m[1]];
    }
    if (preg_match('#^/@([A-Za-z0-9._-]+)#', $path, $m)) {
        return ['kind' => 'handle', 'id' => strtolower($m[1])];
    }
    // ‏/c/Name ו-/user/Name — הצורות הישנות.
    if (preg_match('#^/(?:c|user)/([A-Za-z0-9._-]+)#', $path, $m)) {
        return ['kind' => 'handle', 'id' => strtolower($m[1])];
    }
    if ($path === '/playlist' && !empty($q['list'])) {
        return ['kind' => 'playlist', 'id' => (string) $q['list']];
    }
    if ($path === '/results' || $path === '/search') {
        return ['kind' => 'search', 'id' => ''];
    }
    if ($path === '/' || $path === '/feed/trending' || str_starts_with($path, '/feed')) {
        return ['kind' => 'home', 'id' => ''];
    }

    return ['kind' => 'other', 'id' => ''];
}

/**
 * ‏מזהי המשאבים ש-YouTube טוען לעצמו, ואינם ניווט.
 *
 * בלי זה, מצב מוגבל היה חוסם את ה-CSS, הסקריפטים וזרם הווידאו של
 * הדף שכן הותר, והמשתמש היה מקבל מסך שחור במקום סרטון מאושר.
 */
function isYouTubeAsset(string $url): bool {
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
    foreach (['googlevideo.com', 'ytimg.com', 'ggpht.com', 'gstatic.com',
              'youtube.com/api', 'youtube.com/youtubei'] as $d) {
        if (str_contains($host, $d)) return true;
    }
    $path = parse_url($url, PHP_URL_PATH) ?: '';
    return (bool) preg_match('#^/(?:s/|yts/|youtubei/|api/|generate_204|videoplayback)#', $path);
}

/* ── פענוח בעלות: איזה ערוץ מאחורי הסרטון ─────────────────────────
 *
 * זו הפעולה היחידה כאן שדורשת רשת. מכתובת של סרטון אי אפשר לדעת
 * לאיזה ערוץ הוא שייך, ובלי זה "לאשר ערוץ שלם" אינו ניתן לאכיפה.
 *
 * התשובה נשמרת במטמון לצמיתות: הבעלות על סרטון אינה משתנה.
 */

/** שולף את מזהה הערוץ מדף הצפייה. מחזיר '' אם לא הצליח. */
function fetchYouTubeOwner(string $videoId): array {
    if (!preg_match('#^[A-Za-z0-9_-]{6,20}$#', $videoId)) return ['channel' => '', 'title' => ''];
    if (!function_exists('curl_init')) return ['channel' => '', 'title' => ''];

    $url = 'https://www.youtube.com/watch?v=' . rawurlencode($videoId);
    $ch  = curl_init($url);
    $body = '';

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                                . '(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => ['Accept-Language: en-US,en;q=0.9'],
        // הדף ענק, והמזהה יושב בראשו. 300KB מספיקים, ואין טעם למשוך יותר.
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$body) {
            $body .= $chunk;
            return strlen($body) > 300000 ? 0 : strlen($chunk);
        },
    ]);
    curl_exec($ch);
    curl_close($ch);

    return parseYouTubeOwnerHtml($body);
}

/**
 * מחלץ מזהה ערוץ וכותרת מ-HTML של דף צפייה.
 *
 * מופרד מהפנייה כדי שיהיה ניתן לבדיקה בלי רשת. שלוש תבניות ולא אחת,
 * כי יוטיוב משנה את מבנה הדף מדי פעם — וכשכולן נכשלות מוחזר ריק,
 * וההכרעה נופלת לצד הסוגר.
 */
function parseYouTubeOwnerHtml(string $html): array {
    $channel = '';
    foreach (['#"channelId"\s*:\s*"(UC[A-Za-z0-9_-]{10,})"#',
              '#<meta itemprop="channelId" content="(UC[A-Za-z0-9_-]{10,})"#',
              '#"externalChannelId"\s*:\s*"(UC[A-Za-z0-9_-]{10,})"#',
              '#/channel/(UC[A-Za-z0-9_-]{10,})#'] as $re) {
        if (preg_match($re, $html, $m)) { $channel = $m[1]; break; }
    }

    $title = '';
    if (preg_match('#<meta name="title" content="([^"]*)"#', $html, $m)) {
        $title = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    } elseif (preg_match('#<title>(.*?)</title>#is', $html, $m)) {
        $title = trim(preg_replace('/\s*-\s*YouTube\s*$/u', '',
                 html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    return ['channel' => $channel, 'title' => mb_substr($title, 0, 200)];
}

/**
 * מקבל מה שהמנהל הדביק, ומחזיר סוג ומזהה.
 *
 * הדרישה להדביק כתובת מלאה היא עבודה שהמערכת אמורה לעשות במקומו:
 * מי שרוצה לאשר ערוץ מכיר אותו בשם, לא ב-URL. לכן כל הצורות האלה
 * מתקבלות:
 *
 *   ‏@MercazDafYomi                       כינוי
 *   ‏MercazDafYomi                        שם בלי @
 *   ‏youtube.com/@MercazDafYomi           בלי סכימה
 *   ‏https://www.youtube.com/@Mercaz…     כתובת מלאה
 *   ‏UCxxxxxxxxxxxxxxxxxxxxxx             מזהה ערוץ
 *   ‏dQw4w9WgXcQ                          מזהה סרטון
 *   ‏PLxxxxxxxx                           מזהה פלייליסט
 *   ‏youtu.be/dQw4w9WgXcQ                 קישור מקוצר
 */
function normalizeYouTubeInput(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') return ['kind' => 'other', 'id' => ''];

    // כינוי מפורש — הצורה הנפוצה, ואין בה ספק.
    if (str_starts_with($raw, '@')) {
        $name = substr($raw, 1);
        return preg_match('#^[A-Za-z0-9._-]+$#', $name)
            ? ['kind' => 'handle', 'id' => strtolower($name)]
            : ['kind' => 'other', 'id' => ''];
    }

    /*
     * נראה ככתובת: יש בו נקודה או לוכסן. הסכימה מושלמת אם חסרה,
     * אחרת "youtube.com/@x" היה הופך ל"youtube.com/youtube.com/@x".
     */
    if (str_contains($raw, '/') || str_contains($raw, '.')) {
        $url = preg_match('#^[a-z][a-z0-9+.\-]*://#i', $raw) ? $raw : 'https://' . $raw;
        return parseYouTube($url);
    }

    // מזהים חשופים, לפי הצורה שלהם. מזהה ערוץ הוא UC ועוד 22 תווים.
    if (preg_match('#^UC[A-Za-z0-9_-]{22}$#', $raw))            return ['kind' => 'channel', 'id' => $raw];
    if (preg_match('#^(?:PL|UU|OL|RD|LL|FL)[A-Za-z0-9_-]{10,}$#', $raw))
                                                                return ['kind' => 'playlist', 'id' => $raw];
    // מזהה סרטון הוא בדיוק 11 תווים; שם ערוץ באורך כזה הוא נדיר,
    // והמנהל רואה בטבלה מה זוהה ויכול להסיר.
    if (preg_match('#^[A-Za-z0-9_-]{11}$#', $raw))              return ['kind' => 'video', 'id' => $raw];

    // מילה רגילה — שם ערוץ בלי @.
    return preg_match('#^[A-Za-z0-9._-]+$#', $raw)
        ? ['kind' => 'handle', 'id' => strtolower($raw)]
        : ['kind' => 'other', 'id' => ''];
}
