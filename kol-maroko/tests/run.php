<?php
/**
 * הבדיקות האוטומטיות של קול מרוקו.   php kol-maroko/tests/run.php [מסנן]
 *
 * בלי תלות חיצונית (בלי Composer): PHP בלבד. הכול רץ על מסד בתיקייה זמנית של המערכת — data/ לא נפתחת ולא נכתבת.
 * כל קובץ ב-tests/cases/*.php רושם בדיקות ב-test('שם', function () {...}); בתוכן: ok(), eq(), throws().
 * יציאה בקוד 0 רק כשכל הבדיקות עברו.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);

$TMP = sys_get_temp_dir() . '/kol-tests-' . bin2hex(random_bytes(5));
mkdir($TMP . '/data', 0775, true);
mkdir($TMP . '/wp', 0775, true);
define('KOL_DATA_DIR', $TMP . '/data');
define('KOL_ROOT', dirname(__DIR__));
if (realpath(KOL_DATA_DIR) === realpath(KOL_ROOT . '/data')) exit("הבדיקות לא רצות על data/\n");

foreach (['members', 'import', 'ai', 'agent', 'dev'] as $lib) require_once KOL_ROOT . "/lib/$lib.php";

final class TestFail extends Exception {}
$TESTS = [];
function test(string $name, callable $fn): void { $GLOBALS['TESTS'][] = [$name, $fn]; }
function ok(mixed $cond, string $what = ''): void { if (!$cond) throw new TestFail($what ?: 'התנאי לא התקיים'); }
function eq(mixed $want, mixed $got, string $what = ''): void
{
    if ($want !== $got) throw new TestFail(($what ? "$what: " : '') . 'ציפיתי ל-' . json_encode($want, JSON_UNESCAPED_UNICODE) . ', התקבל ' . mb_substr((string)json_encode($got, JSON_UNESCAPED_UNICODE), 0, 300));
}
/** מצפה ל-ApiError; מחזיר את הקוד שלה. */
function throws(callable $fn, string $what = ''): int
{
    try { $fn(); } catch (ApiError $e) { return $e->getCode() ?: 400; }
    throw new TestFail(($what ? "$what: " : '') . 'ציפיתי לשגיאה, והפעולה עברה');
}

/** שרת PHP מובנה על פורט פנוי. $workers > 1 כשהשרת צריך לפנות לעצמו. */
function serve(string $router, array $env): array
{
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $router], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, KOL_ROOT, $env + getenv());
    for ($i = 0; $i < 100; $i++) {
        if ($c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.1)) { fclose($c); return [$proc, "http://127.0.0.1:$port"]; }
        usleep(50000);
    }
    throw new RuntimeException('שרת הבדיקות לא עלה');
}

/**
 * בקשה לשרת הבדיקות. $o: user (מערך זהות מהשער | null), json (גוף), raw (גוף גולמי), headers [], csrf (ברירת מחדל: אוטומטי למשתמש),
 * jar (ברירת מחדל: true — העוגייה kolm_sess של המשתמש נשמרת ונשלחת, כמו בדפדפן; P1).
 * מחזיר ['status', 'json', 'headers', 'body'].
 */
function http(string $method, string $url, array $o = []): array
{
    $h = $o['headers'] ?? [];
    $jarKey = null;
    if (!empty($o['user'])) {
        $h[] = 'X-Test-User: ' . json_encode($o['user'], JSON_UNESCAPED_UNICODE);
        if ($o['jar'] ?? true) {
            $jarKey = (string)$o['user']['sub'];
            // P1: ה-CSRF הוא של ה-session — לפני POST ראשון, "me" (כמו שהסטודיו עושה בטעינה)
            if ($method === 'POST' && ($o['csrf'] ?? true) && empty($GLOBALS['JAR'][$jarKey]['csrf'])) {
                http('GET', '/api.php?action=me', ['user' => $o['user']]);
            }
            if (!empty($GLOBALS['JAR'][$jarKey]['cookie'])) {
                $c = 'kolm_sess=' . $GLOBALS['JAR'][$jarKey]['cookie'];
                $i = array_key_first(array_filter($h, fn($x) => stripos($x, 'Cookie:') === 0));
                if ($i === null) $h[] = "Cookie: $c"; else $h[$i] .= "; $c";      // עוגייה שהבדיקה עצמה שולחת — באותה כותרת
            }
            if ($method === 'POST' && ($o['csrf'] ?? true)) { $h[] = 'X-Kol: 1'; $h[] = 'X-CSRF: ' . ($GLOBALS['JAR'][$jarKey]['csrf'] ?? ''); }
        }
    }
    $body = isset($o['json']) ? json_encode($o['json'], JSON_UNESCAPED_UNICODE) : ($o['raw'] ?? null);
    if (isset($o['json'])) $h[] = 'Content-Type: application/json';
    $ch = curl_init(($GLOBALS['BASE'] ?? '') . $url);
    $rh = [];
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$rh) { if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $rh[strtolower(trim($k))] = trim($v); } return strlen($line); }]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $res = curl_exec($ch);
    if ($res === false) throw new TestFail('הבקשה נכשלה: ' . curl_error($ch));
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $json = json_decode((string)$res, true);
    if ($jarKey !== null) {
        if (preg_match('/^kolm_sess=([^;]*)/', $rh['set-cookie'] ?? '', $cm)) {
            if (($GLOBALS['JAR'][$jarKey]['cookie'] ?? '') !== $cm[1]) $GLOBALS['JAR'][$jarKey] = ['cookie' => $cm[1], 'csrf' => null];
            if ($cm[1] === '') unset($GLOBALS['JAR'][$jarKey]);
        }
        if (is_array($json) && is_string($json['csrf'] ?? null) && !empty($GLOBALS['JAR'][$jarKey]['cookie'])) $GLOBALS['JAR'][$jarKey]['csrf'] = $json['csrf'];
    }
    return ['status' => $status, 'json' => $json, 'headers' => $rh, 'body' => (string)$res];
}

function rrmdir(string $d): void
{
    foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $f) is_dir("$d/$f") && !is_link("$d/$f") ? rrmdir("$d/$f") : @unlink("$d/$f");
    @rmdir($d);
}

const U_OWNER = ['sub' => 'u-owner', 'name' => 'בעלים בדיקה', 'email' => 'owner@example.test', 'email_verified' => true, 'platform_admin' => true];
const U_EDITOR = ['sub' => 'u-editor', 'name' => 'עורך בדיקה', 'email' => 'editor@example.test', 'email_verified' => true];
const U_PUBLISHER = ['sub' => 'u-publisher', 'name' => 'מפרסם בדיקה', 'email' => 'publisher@example.test', 'email_verified' => true];
const U_STRANGER = ['sub' => 'u-stranger', 'name' => 'זר', 'email' => 'stranger@example.test', 'email_verified' => true];
// P1 — חמשת התפקידים
const U_CONTENT = ['sub' => 'u-content', 'name' => 'עורכת תוכן בדיקה', 'email' => 'content@example.test', 'email_verified' => true];
const U_PAGE = ['sub' => 'u-page', 'name' => 'בעלת העמוד בדיקה', 'email' => 'page@example.test', 'email_verified' => true];

$procs = [];
$failed = 0;
$ran = 0;
try {
    db();                                                   // יוצר את המסד הזמני ומריץ את המיגרציות
    $now = now_iso();
    // P1: האנשים — ב-users (members נשארת לקריאה בלבד)
    foreach ([[U_EDITOR, 'editor'], [U_PUBLISHER, 'publisher'], [U_CONTENT, 'content_editor'], [U_PAGE, 'page_owner']] as [$u, $role]) {
        db()->prepare("INSERT INTO users (sub, email, name, role, status, created_at) VALUES (?, ?, ?, ?, 'active', ?)")->execute([$u['sub'], $u['email'], $u['name'], $role, $now]);
    }
    [$procs[], $GLOBALS['PROV']] = serve(__DIR__ . '/fake-provision.php', ['KOL_TEST_PROV' => $TMP . '/prov.json']);
    $GLOBALS['PROV_FILE'] = $TMP . '/prov.json';
    [$procs[], $GLOBALS['BASE']] = serve(__DIR__ . '/router.php', ['KOL_TEST_DATA' => KOL_DATA_DIR, 'KOL_TEST_PROVISION' => $GLOBALS['PROV'] . '/provision.php']);
    [$procs[], $GLOBALS['WP']] = serve(__DIR__ . '/fake-wp.php', ['KOL_TEST_WP' => $TMP . '/wp']);
    $GLOBALS['WP_DIR'] = $TMP . '/wp';

    foreach (glob(__DIR__ . '/cases/*.php') as $f) require $f;
    foreach (glob(__DIR__ . '/plugin/*_test.php') as $f) require $f;      // M2 — התוסף 2.0 מול אתר מדומה משלו
    // בדיקות של פרוסות אחרות שמחזירות מערך של שם → פונקציה (tests/*_test.php). רצות אחרונות — הן ממלאות את המסד במילים
    foreach (glob(__DIR__ . '/*_test.php') as $f) foreach ((array)(require $f) as $name => $fn) test($name, $fn);
    $filter = $argv[1] ?? '';
    foreach ($TESTS as [$name, $fn]) {
        if ($filter !== '' && !str_contains($name, $filter)) continue;
        $ran++;
        try {
            $fn();
            echo "✅ $name\n";
        } catch (Throwable $e) {
            $failed++;
            echo "❌ $name\n   " . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
        }
    }
} catch (Throwable $e) {
    $failed++;
    echo '❌ ההכנה נכשלה: ' . $e->getMessage() . "\n";
} finally {
    foreach ($procs as $p) { proc_terminate($p); proc_close($p); }
    rrmdir($TMP);
}
echo "\n" . ($failed ? "נכשלו $failed מתוך $ran" : "כל $ran הבדיקות עברו") . "\n";
exit($failed || !$ran ? 1 : 0);
