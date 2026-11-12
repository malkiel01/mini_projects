<?php
/**
 * האתר המדומה של ההקלטות (פרוסה A2) — php -S, בלי רשת אמיתית. כל בקשה נרשמת ב-<dir>/log.jsonl: השיטה, הנתיב,
 * ה-User-Agent, והאם הגיעו עוגייה או כותרת הרשאה. הנתיב קובע את ההתנהגות:
 *   /wp-content/uploads/ok/<n>.mp3     שמע אמיתי (MP3), Content-Length
 *   …/html/…   דף HTML (200)          …/missing/…  404           …/cf/…   403 עם אתגר של Cloudflare
 *   …/big/…    שמע גדול (3MB)         …/stream/…   3MB בלי Content-Length
 *   …/cut/…    Content-Length גדול, והחיבור נסגר באמצע
 *   …/flaky/…  נופל באמצע בפעם הראשונה, ותקין מהשנייה ("שרת שנופל באמצע")
 *   …/text/…   טקסט שמוצהר כ-audio/mpeg     …/slow/…  4 שניות עד הבייט הראשון
 *   …/redir-in/<n>.mp3   302 ל-ok/<n>.mp3     …/redir-out/…  302 לדומיין אחר     …/redir-admin/…  302 ל-/wp-admin/
 *
 * ⚠️ רץ רק בשרת המובנה של PHP ורק כש-KOL_TEST_AUDIO מוגדר (התיקייה tests/ אינה נפרסת, וחסומה ב-.htaccess).
 */

declare(strict_types=1);

$dir = getenv('KOL_TEST_AUDIO');
if (PHP_SAPI !== 'cli-server' || !$dir || !is_dir($dir)) { http_response_code(404); exit; }

$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
file_put_contents("$dir/log.jsonl", json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'cookie' => isset($_SERVER['HTTP_COOKIE']), 'auth' => isset($_SERVER['HTTP_AUTHORIZATION'])]) . "\n", FILE_APPEND | LOCK_EX);

function mp3(int $frames): string
{
    return "ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat("\xff\xfb\x90\x64" . str_repeat("\x00", 413), $frames);
}

if (!preg_match('~^/wp-content/uploads/([a-z-]+)/(\d+)\.mp3$~', $path, $m)) { http_response_code(404); echo 'not found'; exit; }
[, $kind, $n] = $m;
$head = $_SERVER['REQUEST_METHOD'] === 'HEAD';
$send = function (string $body, string $type = 'audio/mpeg') use ($head) {
    header("Content-Type: $type");
    header('Content-Length: ' . strlen($body));
    if (!$head) echo $body;
};
switch ($kind) {
    case 'ok': $send(mp3(4 + (int)$n)); break;
    case 'html': $send('<!doctype html><html><head><title>Just a moment...</title></head><body>challenge</body></html>', 'text/html; charset=utf-8'); break;
    case 'text': $send(str_repeat("hello, this is not audio\n", 40)); break;
    case 'missing': http_response_code(404); header('Content-Type: text/html'); echo 'not found'; break;
    case 'cf':
        http_response_code(403);
        header('Server: cloudflare');
        header('cf-mitigated: challenge');
        header('Content-Type: text/html');
        if (!$head) echo '<html>Just a moment...</html>';
        break;
    case 'big': $send(mp3(7600)); break;                         // ~3.1MB
    case 'stream':
        header('Content-Type: audio/mpeg');
        if ($head) break;
        for ($i = 0; $i < 40; $i++) { echo mp3(190); flush(); }
        break;
    case 'cut':
        header('Content-Type: audio/mpeg');
        header('Content-Length: 50000');
        if (!$head) { echo substr(mp3(20), 0, 2000); flush(); }
        exit;
    case 'flaky':
        $flag = "$dir/flaky-$n";
        if ($head || is_file($flag)) { $send(mp3(6)); break; }
        touch($flag);
        header('Content-Type: audio/mpeg');
        header('Content-Length: 40000');
        echo substr(mp3(6), 0, 1500);
        flush();
        exit;
    case 'slow': if (!$head) sleep(4); $send(mp3(5)); break;
    case 'redir-in': http_response_code(302); header("Location: /wp-content/uploads/ok/$n.mp3"); break;
    case 'redir-out': http_response_code(302); header("Location: https://evil.example/wp-content/uploads/ok/$n.mp3"); break;
    case 'redir-admin': http_response_code(302); header('Location: /wp-admin/admin-ajax.php'); break;
    default: http_response_code(404);
}
