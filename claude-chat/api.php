<?php
/**
 * claude-chat — צ'אט מול Claude Platform (Messages API), בצד השרת.
 *
 * - המפתח נקרא מקובץ בשרת, מחוץ לתיקיית האתר: <home>/claude-secrets/anthropic.key.
 *   הוא לא בריפו, לא נפרס, ולעולם לא נשלח לדפדפן ולא נכתב ליומן.
 * - הכניסה לכלי היא דרך השער של הפלטפורמה (app.json: gate + restricted). בנוסף, כאן נבדק
 *   $_SERVER['PLATFORM_USER'] — כך שגם אם השער יוסר בטעות, אי אפשר לשרוף קרדיטים בלי זהות.
 * - אין נתונים בשרת: השיחות נשמרות בדפדפן בלבד.
 *
 * GET  ?action=health  — מצב: האם נמצא מפתח, ומי המשתמש (בלי לחשוף את המפתח)
 * POST ?action=chat    — גוף JSON: {model, messages:[{role,content}]} ← זרם SSE של Anthropic
 */
declare(strict_types=1);

const MODELS = ['claude-sonnet-5-5', 'claude-opus-5-5', 'claude-haiku-5-5'];
const DEFAULT_MODEL = 'claude-sonnet-5-5';
const MAX_MESSAGES = 40;
const MAX_CHARS = 60000;
const MAX_TOKENS = 4096;
const SYSTEM_PROMPT = 'אתה עוזר פיתוח חכם ותמציתי. ענה בעברית אלא אם התבקשת אחרת, וכתוב קוד בבלוקים עם שם השפה.';

function fail(int $code, string $msg): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/** המשתמש שהשער זיהה. null = אין זהות. */
function platform_user(): ?array
{
    $raw = $_SERVER['PLATFORM_USER'] ?? '';
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $u = json_decode($raw, true);
    return is_array($u) ? $u : null;
}

/** מחפש claude-secrets/anthropic.key בתיקיית הבית ובכל אב קדמון של התיקייה הזו. */
function find_key_file(): ?string
{
    $dirs = [];
    $home = getenv('HOME');
    if (is_string($home) && $home !== '') {
        $dirs[] = $home;
    }
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $pw = @posix_getpwuid(posix_geteuid());
        if (is_array($pw) && !empty($pw['dir'])) {
            $dirs[] = $pw['dir'];
        }
    }
    for ($d = __DIR__, $i = 0; $i < 8; $i++) {
        $dirs[] = $d;
        $parent = dirname($d);
        if ($parent === $d) {
            break;
        }
        $d = $parent;
    }
    foreach ($dirs as $dir) {
        $f = rtrim($dir, '/') . '/claude-secrets/anthropic.key';
        if (is_file($f) && is_readable($f)) {
            return $f;
        }
    }
    return null;
}

function read_key(): ?string
{
    $f = find_key_file();
    if ($f === null) {
        return null;
    }
    $k = trim((string) @file_get_contents($f));
    return preg_match('/^sk-ant-[A-Za-z0-9_\-]{20,}$/', $k) === 1 ? $k : null;
}

$user = platform_user();
if ($user === null) {
    fail(401, 'נדרשת כניסה דרך הפלטפורמה');
}

$action = $_GET['action'] ?? '';

if ($action === 'health') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $f = find_key_file();
    echo json_encode([
        'ok'        => true,
        'key_file'  => $f !== null,                 // האם הקובץ נמצא וקריא
        'key_valid' => read_key() !== null,         // האם התוכן נראה כמו מפתח (בלי להציג אותו)
        'curl'      => function_exists('curl_init'),
        'php'       => PHP_VERSION,
        'models'    => MODELS,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action !== 'chat') {
    fail(404, 'פעולה לא מוכרת');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail(405, 'POST בלבד');
}
// הגנה מפני בקשה מאתר זר: כותרת מותאמת אישית לא נשלחת מטופס חיצוני.
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'claude-chat') {
    fail(403, 'בקשה לא תקינה');
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body) || !isset($body['messages']) || !is_array($body['messages'])) {
    fail(400, 'גוף הבקשה לא תקין');
}
$model = in_array($body['model'] ?? '', MODELS, true) ? $body['model'] : DEFAULT_MODEL;

$messages = [];
$chars = 0;
foreach ($body['messages'] as $m) {
    if (!is_array($m) || !in_array($m['role'] ?? '', ['user', 'assistant'], true)
        || !is_string($m['content'] ?? null) || trim($m['content']) === '') {
        fail(400, 'הודעה לא תקינה');
    }
    $chars += mb_strlen($m['content']);
    $messages[] = ['role' => $m['role'], 'content' => $m['content']];
}
if ($messages === [] || count($messages) > MAX_MESSAGES) {
    fail(400, 'מספר ההודעות חורג מהמותר');
}
if ($chars > MAX_CHARS) {
    fail(413, 'השיחה ארוכה מדי — התחל שיחה חדשה');
}
if ($messages[0]['role'] !== 'user' || end($messages)['role'] !== 'user') {
    fail(400, 'השיחה חייבת להתחיל ולהסתיים בהודעת משתמש');
}

$key = read_key();
if ($key === null) {
    fail(500, 'מפתח ה-API לא נמצא בשרת (claude-secrets/anthropic.key)');
}
if (!function_exists('curl_init')) {
    fail(500, 'curl לא זמין ב-PHP');
}

// ---- מכאן זרם SSE: בלי באפרינג ובלי דחיסה, אחרת התשובה מגיעה בבת אחת ----
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) {
    @ob_end_flush();
}
@set_time_limit(115);
ignore_user_abort(false);
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-store');
header('X-Accel-Buffering: no');

function sse(string $event, array $data): void
{
    echo 'event: ' . $event . "\n" . 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    @flush();
}

$status = 0;
$errBody = '';
$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'content-type: application/json',
        'x-api-key: ' . $key,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_POSTFIELDS     => json_encode([
        'model'      => $model,
        'max_tokens' => MAX_TOKENS,
        'stream'     => true,
        'system'     => SYSTEM_PROMPT,
        'messages'   => $messages,
    ], JSON_UNESCAPED_UNICODE),
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT        => 110,
    CURLOPT_HEADERFUNCTION => function ($c, string $h) use (&$status): int {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $status = (int) $m[1];
        }
        return strlen($h);
    },
    CURLOPT_WRITEFUNCTION  => function ($c, string $chunk) use (&$status, &$errBody): int {
        if ($status === 200) {
            echo $chunk;           // מעבירים את זרם Anthropic כמות שהוא; הדפדפן מפענח
            @flush();
        } else {
            $errBody .= $chunk;    // שגיאה: אוספים ומדווחים בצורה מסוננת בסוף
        }
        return connection_aborted() ? 0 : strlen($chunk);
    },
]);
curl_exec($ch);
$curlErr = curl_error($ch);
curl_close($ch);

if ($status !== 200) {
    $msg = 'שגיאה מול Claude';
    $j = json_decode($errBody, true);
    if (is_array($j) && isset($j['error']['message']) && is_string($j['error']['message'])) {
        $msg = $j['error']['message'];
    } elseif ($curlErr !== '') {
        $msg = 'שגיאת רשת: ' . $curlErr;
    }
    // הודעת השגיאה של Anthropic אינה כוללת את המפתח; ליתר ביטחון מסירים כל דבר שנראה כמוהו.
    $msg = preg_replace('/sk-ant-[A-Za-z0-9_\-]+/', '[מפתח]', $msg) ?? 'שגיאה מול Claude';
    sse('error', ['status' => $status, 'message' => $msg]);
}
