<?php
/**
 * כלי הבדיקות: מסד SQLite בזיכרון, תיקיית נתונים זמנית, וקריאה ל-API בלי שרת (apr_dispatch) — עם זהות "מהשער",
 * עוגייה ו-CSRF כמו בדפדפן. כל קובץ בדיקה רץ בתהליך משלו (tests/run.php). שמות הבדיקות נושאים את קוד קריטריון
 * הקבלה מהאפיון (docs/spec/01-foundation.md §14) — 01-A1, 01-B2 …
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

if (!defined('APR_DATA_DIR')) define('APR_DATA_DIR', sys_get_temp_dir() . '/apr-test-' . bin2hex(random_bytes(5)));
if (!defined('APR_DB_PATH')) define('APR_DB_PATH', ':memory:');
define('APR_DEV', true);
if (!is_dir(APR_DATA_DIR)) mkdir(APR_DATA_DIR, 0700, true);
register_shutdown_function(function () {
    $rm = function (string $d) use (&$rm) {
        foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $f) is_dir("$d/$f") ? $rm("$d/$f") : @unlink("$d/$f");
        @rmdir($d);
    };
    if (str_starts_with(APR_DATA_DIR, sys_get_temp_dir() . '/apr-test-')) $rm(APR_DATA_DIR);
});
putenv('APR_PLATFORM');
putenv('APR_TEST_IDENTITY');
// שום בדיקה לא יוצאת לרשת: בקשת HTTP "עצמית" נענית כאן
$GLOBALS['apr_http'] = fn(string $url) => str_contains($url, 'sso.php') ? [200, '{"app":"appraisers","connected":true,"login":{"platform":true}}'] : [403, ''];

require __DIR__ . '/../lib/app.php';

$GLOBALS['t_fail'] = 0;
$GLOBALS['t_n'] = 0;

function check(string $name, bool $ok, mixed $got = null): void
{
    $GLOBALS['t_n']++;
    if ($ok) return;
    $GLOBALS['t_fail']++;
    echo "  ✗ $name" . ($got !== null ? ' — ' . json_encode($got, JSON_UNESCAPED_UNICODE) : '') . "\n";
}

function eq(mixed $got, mixed $want, string $name): void
{
    check($name, $got === $want, ['got' => $got, 'want' => $want]);
}

/** זהות כמו שהשער כותב ב-PLATFORM_USER. */
function ident(string $sub, string $name, ?string $email, array $more = []): string
{
    return json_encode($more + ['sub' => $sub, 'name' => $name, 'email' => $email, 'email_verified' => true, 'platform_admin' => false, 'granted' => true]);
}

/** העוגיות של הבדיקה, לפי זהות. */
function &jar(): array
{
    static $jar = [];
    return $jar;
}

/**
 * קריאה ל-API. $as — הזהות (ident(...)) או null בלי שער. העוגייה נשמרת לכל זהות, וה-CSRF נשלח לבד ב-POST
 * (אלא אם $opt['csrf'] נקבע במפורש). מחזיר [status, body] — body כמו שהדפדפן מקבל.
 */
function call(string $method, string $action, array $in = [], ?string $as = null, array $get = [], array $opt = []): array
{
    static $csrf = [];
    $jar = &jar();
    $key = $opt['jar'] ?? ($as ?? '-');
    $run = function (string $method, string $action, array $in, array $get, ?string $token) use (&$jar, $key, $as): array {
        unset($GLOBALS['apr_cookie_out']);
        apr_actor(null);
        try {
            // כמו בשרת: $_GET תמיד כולל את action — פעולה שקוראת פרמטר בשם הזה תיתפס כאן
            $out = apr_dispatch($method, $action, $get + ['action' => $action], $in,
                ['platform_user' => $as, 'cookie' => $jar[$key] ?? '', 'csrf' => $token ?? '', 'ua' => 'test', 'cron_url' => 'https://example.test/appraisers/cron.php']);
            [$status, $body] = [200, apr_is_devloop($action) ? ($out ?? ['ok' => true]) : ['ok' => true] + (array)$out];
        } catch (Throwable $e) {
            [$status, $body] = apr_error_response($e, $action);
            if ($status === 500) $body['_debug'] = get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine();
        }
        if (isset($GLOBALS['apr_cookie_out'])) $jar[$key] = $GLOBALS['apr_cookie_out'];
        return [$status, $body];
    };
    if (array_key_exists('cookie', $opt)) $jar[$key] = $opt['cookie'];
    $token = $opt['csrf'] ?? null;
    if ($method === 'POST' && $token === null && $as !== null) {
        [$st, $me] = $run('GET', 'me', [], [], null);
        if ($st === 200) $csrf[$key] = $me['csrf'];
        $token = $csrf[$key] ?? '';
    }
    return $run($method, $action, $in, $get, $token);
}

/** קוד השגיאה של תשובה (או null כשהצליחה). */
function code(array $res): ?string
{
    return $res[1]['error']['code'] ?? (isset($res[1]['error']) ? 'devloop' : null);
}

/** משתמש שמקים משרד; מחזיר את ה-pid של המשרד. */
function office_for(string $as, string $name, string $role = 'appraiser'): string
{
    [$st, $r] = call('POST', 'office-create', ['name' => $name, 'role' => $role], $as);
    if ($st !== 200) throw new RuntimeException('office-create: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
    return $r['office'];
}

/** מנהל מזמין, המוזמן מקבל. מחזיר את ה-pid של המוזמן. */
function join_office(string $admin, string $member, string $email, string $role = 'staff', bool $isAdmin = false): string
{
    [$st, $inv] = call('POST', 'invite-create', ['email' => $email, 'role' => $role, 'is_admin' => $isAdmin], $admin);
    if ($st !== 200) throw new RuntimeException('invite-create: ' . json_encode($inv, JSON_UNESCAPED_UNICODE));
    [$st, $r] = call('POST', 'invite-accept', ['token' => $inv['token']], $member);
    if ($st !== 200) throw new RuntimeException('invite-accept: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
    return call('GET', 'me', [], $member)[1]['user']['pid'];
}

function done(): never
{
    echo $GLOBALS['t_fail'] ? "  {$GLOBALS['t_fail']} מתוך {$GLOBALS['t_n']} נכשלו\n" : "  ✓ {$GLOBALS['t_n']} בדיקות\n";
    exit($GLOBALS['t_fail'] ? 1 : 0);
}
