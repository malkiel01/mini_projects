<?php
/**
 * החזרה לאפליקציית אנדרואיד עם ההתחברות — בלי מסך ובלי כפתור.
 *
 *   GET platform/handoff.php?app=<id>
 *
 * אפליקציה עם דפדפן פנימי משלה (WebView) שנפתחה בלי התחברות פותחת את הכתובת הזו בחוץ (ACTION_VIEW —
 * כרום, שבו הכלים הדיגיטליים מחוברים). כאן: מחובר ויש הרשאה → קוד חד-פעמי (lib/gate.php, handoff_issue),
 * והפניה של השרת (302) לאפליקציה עצמה — intent עם החבילה שלה, ובתוכו הכתובת עם ?platform-handoff.
 * כרום פותח אפליקציה אחרת רק מתוך לחיצה — או מהפניה של השרת בשרשרת שהתחילה מאפליקציה (כמו חזרה מכניסה
 * של גוגל). לכן זה השרת ולא JavaScript: JavaScript אחרי טעינת דף היה נחסם ונופל לדף בדפדפן.
 * לא מחובר → מסך הכניסה של הכלים הדיגיטליים, ואחריו חזרה לכאן (?next).
 */

declare(strict_types=1);

require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/access.php';
require_once __DIR__ . '/lib/gate.php';
require_once __DIR__ . '/lib/diag.php';

header('Cache-Control: no-store');
$base = cookie_path();                                   // /mini_projects/
$app = is_string($_GET['app'] ?? null) && preg_match('/^[a-z0-9][a-z0-9-]*$/', $_GET['app']) ? $_GET['app'] : '';

function handoff_page(string $title, string $text, string $base): never
{
    $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="he" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . $e($title) . '</title><body style="font-family:system-ui,sans-serif;text-align:center;padding:60px 20px;background:#f0f2f5">'
       . '<h1 style="font-size:1.3rem">' . $e($title) . '</h1><p>' . $e($text) . '</p><p><a href="' . $e($base) . '">לכלים הדיגיטליים</a></p></body></html>';
    exit;
}

function handoff_log(string $event, string $app, ?array $user, array $info = []): void
{
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    \Platform\Diag\write(db(), [
        'event' => $event, 'app' => $app, 'user_id' => $user ? (int)$user['id'] : null,
        'device' => \Platform\Gate\device_label($ua), 'ua' => $ua,
        'path' => (string)($_SERVER['REQUEST_URI'] ?? ''), 'referrer' => (string)($_SERVER['HTTP_REFERER'] ?? ''),
        'info' => $info + \Platform\Diag\request_info(isset($_COOKIE[SESSION_COOKIE]), $user !== null),
    ]);
}

try {
    $user = current_user();
    if ($app === '') handoff_page('קישור לא תקין', 'חסרה האפליקציה.', $base);
    if (!$user || $user['status'] !== 'active') {
        handoff_log('handoff:login-first', $app, $user);
        header('Location: ' . $base . '?next=' . rawurlencode($base . 'platform/handoff.php?app=' . $app), true, 302);
        exit;
    }
    $found = null;
    $visible = visible_for($user);
    foreach ([...$visible['apps'], ...($visible['plugins'] ?? [])] as $a) if ($a['id'] === $app) $found = $a;
    $cfg = \Platform\Gate\app_config($app);
    if (!$found || !$cfg || empty($cfg['gate']) || \Platform\Gate\is_detached($app)) {
        handoff_log('handoff:forbidden', $app, $user);
        handoff_page('אין לך הרשאת גישה לאפליקציה הזו', 'פנו למנהל.', $base);
    }

    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $path = $base . ltrim((string)$found['href'], '/');
    $token = \Platform\Gate\handoff_issue((int)$user['id'], $app);
    $query = (str_contains($path, '?') ? '&' : '?') . \Platform\Gate\HANDOFF_PARAM . '=' . $token;
    $url = ($https ? 'https' : 'http') . "://$host$path$query";
    $pkg = $found['install']['android']['package'] ?? null;
    $target = is_string($pkg) && preg_match('/^[a-z][a-z0-9_.]*$/', $pkg)
        ? "intent://$host$path$query#Intent;scheme=" . ($https ? 'https' : 'http') . ";package=$pkg;"
          . 'S.browser_fallback_url=' . rawurlencode($url) . ';end'
        : $url;
    handoff_log('handoff:redirect', $app, $user, ['pkg' => $pkg ?: '']);
    header('Location: ' . $target, true, 302);
} catch (Throwable $e) {
    error_log('platform handoff: ' . $e);
    handoff_page('תקלה זמנית', 'נסו שוב בעוד רגע.', $base);
}
