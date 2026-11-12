<?php
/**
 * שרת משיכה מדומה (php -S) — "הכלי" מול התוסף האמיתי בבדיקות של M7 (tests/plugin/70-pull_test.php).
 * התור, הגושים וההתנהגויות הזדוניות — מה שהבדיקה כתבה ב-<KOL_TEST_PULL>/pull.json:
 *   token          האסימון הצפוי (Authorization: Bearer)
 *   items          הפריטים ש-?after= מחזיר (מסוננים לפי seq > after), more
 *   blobs          sha ← נתיב הקובץ שמוגש ב-?blob=
 *   corrupt        sha ← true: מגיש בייטים אחרים באותו גודל (H20)
 *   redirect       כתובת: כל בקשה מקבלת 302 אליה (H23)
 *   ignore_range   מגיש את כל הקובץ ב-200 גם כשנשלח Range
 *   slow           מיקרו-שניות השהיה לכל תשובה (H28)
 * כל בקשה נרשמת ב-<dir>/pull-log.json (נתיב, query, Range, האם האסימון תקין); כל דיווח — ב-<dir>/reports.json.
 */

declare(strict_types=1);

$dir = getenv('KOL_TEST_PULL');
if (PHP_SAPI !== 'cli-server' || !$dir || !is_dir($dir)) { http_response_code(404); exit; }

function fp_locked(string $file, callable $fn)
{
    $lock = fopen("$file.lock", 'c');
    flock($lock, LOCK_EX);
    $v = json_decode((string)@file_get_contents($file), true) ?: [];
    [$v2, $ret] = $fn($v);
    if ($v2 !== null) file_put_contents($file, json_encode($v2));
    flock($lock, LOCK_UN);
    fclose($lock);
    return $ret;
}

$cfg = json_decode((string)@file_get_contents("$dir/pull.json"), true) ?: [];
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$okAuth = isset($cfg['token']) && hash_equals('Bearer ' . $cfg['token'], $auth);
fp_locked("$dir/pull-log.json", function ($log) use ($okAuth) {
    $log[] = ['method' => $_SERVER['REQUEST_METHOD'], 'query' => (string)($_SERVER['QUERY_STRING'] ?? ''), 'range' => $_SERVER['HTTP_RANGE'] ?? null, 'auth' => $okAuth,
        'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '', 'at' => microtime(true)];
    return [$log, null];
});
if (!empty($cfg['slow'])) usleep((int)$cfg['slow']);
if (!empty($cfg['redirect'])) { header('Location: ' . $cfg['redirect'], true, 302); exit; }
header('Cache-Control: no-store');
if (!$okAuth) { http_response_code(401); header('Content-Type: application/json'); echo '{"ok":false,"code":"bad_token"}'; exit; }

if (isset($_GET['blob'])) {
    $sha = (string)$_GET['blob'];
    $file = $cfg['blobs'][$sha] ?? null;
    if (!$file || !is_file($file)) { http_response_code(404); exit; }
    $bytes = (string)file_get_contents($file);
    if (!empty($cfg['corrupt'][$sha])) $bytes = str_repeat('X', strlen($bytes));
    $size = strlen($bytes);
    if (!empty($cfg['ignore_range']) || !isset($_SERVER['HTTP_RANGE'])) { header('Content-Type: application/octet-stream'); echo $bytes; exit; }
    preg_match('/^bytes=(\d+)-(\d*)$/', (string)$_SERVER['HTTP_RANGE'], $m);
    $a = (int)$m[1];
    $z = $m[2] === '' ? $size - 1 : min((int)$m[2], $size - 1);
    http_response_code(206);
    header('Content-Type: application/octet-stream');
    header("Content-Range: bytes $a-$z/$size");
    echo substr($bytes, $a, $z - $a + 1);
    exit;
}
header('Content-Type: application/json; charset=utf-8');
if (isset($_GET['ping'])) { echo json_encode(['ok' => true, 'server_time' => time(), 'waiting' => count($cfg['items'] ?? [])]); exit; }
if (isset($_GET['report'])) {
    $b = json_decode((string)file_get_contents('php://input'), true);
    fp_locked("$dir/reports.json", fn($r) => [array_merge($r, [$b]), null]);
    echo json_encode(['ok' => true, 'recorded' => []]);
    exit;
}
$after = (int)($_GET['after'] ?? 0);
$items = array_values(array_filter($cfg['items'] ?? [], fn($i) => ($i['seq'] ?? 0) > $after || !empty($i['always'])));
echo json_encode(['ok' => true, 'items' => array_map(fn($i) => array_diff_key($i, ['always' => 1]), $items), 'more' => false, 'server_time' => time()]);
