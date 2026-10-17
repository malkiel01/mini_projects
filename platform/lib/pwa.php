<?php
/**
 * "אכיפת PWA" ובדיקת התקנה אמיתית — מה שהשער מוסיף לכל דף של אפליקציה מחוברת.
 *
 * בלי לגעת בקבצים של הכלים: השער כבר מגיש את הדפים שלהם (gate.php — HTML סטטי, gate-prepend.php —
 * PHP), ולפני ה-</head> הוא מוסיף:
 *   - <script pwa-beacon.js> — בודק באמת אם האפליקציה מותקנת במכשיר הזה (נפתחה מהאייקון /
 *     getInstalledRelatedApps / beforeinstallprompt), מדווח לפלטפורמה, ומציג פס "התקנה" כשהיא
 *     פתוחה בלשונית דפדפן.
 *   - <link rel="manifest"> — רק לאפליקציה שאין לה manifest משלה: platform/pwa.php מייצר אחד
 *     (שם, אייקון מ-assets/app-icons, טווח = התיקייה שלה). כך כל אפליקציה ניתנת להתקנה.
 * ול-manifest שיש לכלי משלו — מוסיפים בהגשה related_applications שמצביע עליו, כדי שהדף יוכל
 * לשאול את הדפדפן אם הוא מותקן.
 *
 * רץ גם בתוך התהליך של הכלי (gate-prepend) — לכן בלי שמות גלובליים, כמו lib/gate.php.
 */

declare(strict_types=1);

namespace Platform\Pwa;

/** האם להוסיף לאפליקציה הזו: מחוברת לפלטפורמה (שער פעיל ולא מנותקת). */
function applies(string $app, ?array $cfg): bool
{
    return $cfg !== null && !empty($cfg['gate']) && !\Platform\Gate\is_detached($app);
}

/** נתיב ה-manifest של הכלי עצמו (יחסי לתיקייה שלו), או null. */
function own_manifest(string $app): ?string
{
    foreach (['manifest.webmanifest', 'manifest.json'] as $f) {
        if (is_file(\Platform\Gate\ROOT . "/$app/$f")) return $f;
    }
    return null;
}

/** מוסיף לפני </head> את הסקריפט, ואם אין manifest בדף — את ה-manifest של הפלטפורמה. */
function inject_html(string $html, string $app, array $cfg, string $base): string
{
    $pos = stripos($html, '</head>');
    if ($pos === false || str_contains($html, 'pwa-beacon.js')) return $html;
    $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $tags = '<script src="' . $e($base . 'platform/assets/pwa-beacon.js') . '" defer'
          . ' data-app="' . $e($app) . '" data-name="' . $e((string)($cfg['name'] ?? $app)) . '"'
          . ' data-api="' . $e($base . 'platform/api.php') . '"></script>';
    if (!preg_match('/<link[^>]+rel=["\']?manifest/i', $html)) {
        $tags .= '<link rel="manifest" href="' . $e($base . 'platform/pwa.php?app=' . rawurlencode($app)) . '">';
        if (!preg_match('/apple-touch-icon/i', $html)) {
            $tags .= '<link rel="apple-touch-icon" href="' . $e(icon_url($app, $base, 192)) . '">';
        }
        if (!preg_match('/name=["\']?theme-color/i', $html)) $tags .= '<meta name="theme-color" content="#4338ca">';
    }
    return substr($html, 0, $pos) . $tags . substr($html, $pos);
}

function icon_url(string $app, string $base, int $size): string
{
    $rel = "platform/assets/app-icons/$app-$size.png";
    return $base . (is_file(\Platform\Gate\ROOT . "/$rel") ? $rel : "platform/assets/icons/icon-$size.png");
}

/**
 * ה-manifest של כלי, כפי שהשער מגיש אותו: related_applications שמצביע על עצמו (כדי שהדף יוכל
 * לשאול את הדפדפן "אני מותקן?"), ו-pwa-launch=1 ב-start_url (סימן שנפתח מהאייקון שלו).
 */
function patch_manifest(string $json, string $selfUrl): string
{
    $m = json_decode($json, true);
    if (!is_array($m)) return $json;
    $rel = is_array($m['related_applications'] ?? null) ? $m['related_applications'] : [];
    $has = false;
    foreach ($rel as $r) if (($r['platform'] ?? '') === 'webapp') $has = true;
    if (!$has) $rel[] = ['platform' => 'webapp', 'url' => $selfUrl];
    $m['related_applications'] = $rel;
    $start = is_string($m['start_url'] ?? null) ? $m['start_url'] : '.';
    // בלי "id" — הדפדפן מזהה את האפליקציה לפי start_url. שומרים את הזהות המקורית, אחרת אפליקציה
    // שכבר מותקנת הייתה נחשבת לאפליקציה אחרת.
    if (!isset($m['id'])) $m['id'] = $start;
    if (!str_contains($start, 'pwa-launch=')) {
        [$path, $hash] = array_pad(explode('#', $start, 2), 2, null);
        $m['start_url'] = $path . (str_contains($path, '?') ? '&' : '?') . 'pwa-launch=1' . ($hash !== null ? "#$hash" : '');
    }
    return (string)json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

/** כתובת מלאה (https://host/...) מנתיב באתר. */
function absolute(string $path): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $path;
}

/**
 * gate-prepend: לדף PHP של כלי — מאזין לפלט, ואם הוא HTML מוסיף את התגים. רק בפתיחת דף
 * (ניווט GET), לא ב-API או בהורדות — אלה לא נאגרים בזיכרון.
 */
function inject_php_page(string $app, array $cfg, string $base): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    $dest = $_SERVER['HTTP_SEC_FETCH_DEST'] ?? '';
    if ($dest !== '' ? $dest !== 'document' : !str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html')) return;
    ob_start(static function (string $buf, int $phase) use ($app, $cfg, $base): string {
        foreach (headers_list() as $h) {
            if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) return $buf;
        }
        return inject_html($buf, $app, $cfg, $base);
    });
}
