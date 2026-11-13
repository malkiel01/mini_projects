<?php
/**
 * בקשות החוצה — הבדיקה המשותפת (V16c, אחרי ביקורת #1548). כל מי שפונה לכתובת שמשתמש קבע — webhook של טופס (forms.php),
 * הורדת ההקלטות (recordings.php), Google Fonts (fonts.php) — בודק כאן את שם המארח ואת כתובות ה-IP שה-DNS החזיר.
 *
 * למה שם המארח קפדני: ההצמדה (CURLOPT_RESOLVE "<host>:443:<ip>") חלה רק כש-<host> זהה תו-בתו למארח שב-URL ש-curl מקבל.
 * "evil.example.com." (נקודה בסוף) הוא אותו שם ב-DNS, אבל מפתח אחר ל-curl — ההצמדה מתעלמת, curl פותר מחדש, ו-DNS בשליטת
 * התוקף מחזיר הפעם 127.0.0.1 / 169.254.169.254 (DNS rebinding). לכן: נקודה בסוף, תווית ריקה או תו אחר — נדחים, ולא "מנורמלים".
 */

/** שם מארח תקין לפנייה החוצה: אותיות קטנות, ספרות ומקפים, תוויות לא ריקות, בלי נקודה בסוף. מחזיר אותו (קטן) או '' — לא תקין. */
function net_host(string $host): string
{
    $h = strtolower($host);
    if ($h === '' || strlen($h) > 253) return '';
    $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
    return preg_match('/^(?:' . $label . '\.)+' . $label . '$/', $h) ? $h : '';
}

/** כתובת IP ציבורית בלבד: לא loopback, פרטית, link-local, CGNAT (100.64/10), בדיקות (198.18/15), 192.0.0/24, multicast, שמורה. */
function net_ip_public(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
        && !preg_match('/^(0\.|100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.|192\.0\.0\.|198\.1[89]\.|2(2[4-9]|[3-5]\d)\.)/', $ip)
        && !preg_match('/^(::|::1|f[cd]|fe[89ab]|ff|::ffff:)/i', $ip);
}
