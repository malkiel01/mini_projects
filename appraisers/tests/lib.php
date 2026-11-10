<?php
/**
 * כלי הבדיקות: מסד SQLite בזיכרון, תיקיית נתונים זמנית, וקריאה ל-API בלי שרת (apr_dispatch) — עם זהות "מהשער",
 * עוגייה ו-CSRF כמו בדפדפן. כל קובץ בדיקה רץ בתהליך משלו (tests/run.php).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('APR_DB_PATH', ':memory:');
define('APR_DATA_DIR', sys_get_temp_dir() . '/apr-test-' . bin2hex(random_bytes(5)));
define('APR_DEV', true);
mkdir(APR_DATA_DIR, 0700, true);
register_shutdown_function(function () {
    $rm = function (string $d) use (&$rm) {
        foreach (glob($d . '/{,.}[!.,!..]*', GLOB_BRACE) ?: [] as $f) is_dir($f) ? $rm($f) : @unlink($f);
        @rmdir($d);
    };
    $rm(APR_DATA_DIR);
});

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

/**
 * קריאה ל-API. $as — הזהות (ident(...)) או null בלי שער. העוגייה נשמרת לכל זהות, וה-CSRF נשלח לבד ב-POST
 * (אלא אם $opt['csrf'] נקבע במפורש). מחזיר [status, body].
 */
function call(string $method, string $action, array $in = [], ?string $as = null, array $get = [], array $opt = []): array
{
    static $jar = [], $csrf = [];
    $key = $as ?? '-';
    $run = function (string $method, string $action, array $in, array $get, ?string $token) use (&$jar, $key, $as): array {
        unset($GLOBALS['apr_cookie_out']);
        try {
            // כמו בשרת: $_GET תמיד כולל את action — פעולה שקוראת פרמטר בשם הזה תיתפס כאן
            $body = apr_dispatch($method, $action, $get + ['action' => $action], $in, ['platform_user' => $as, 'cookie' => $jar[$key] ?? '', 'csrf' => $token ?? '']);
            $status = 200;
        } catch (AprError $e) {
            [$status, $body] = [$e->status, ['error' => $e->getMessage()]];
        }
        $c = $GLOBALS['apr_cookie_out'] ?? null;
        if ($c) $jar[$key] = !empty($c['clear']) ? '' : $c['token'];
        return [$status, $body];
    };
    if (array_key_exists('cookie', $opt)) $jar[$key] = $opt['cookie'];
    $token = $opt['csrf'] ?? null;
    if ($method === 'POST' && $token === null && $as !== null) {
        if (!isset($csrf[$key]) || !empty($opt['fresh'])) {
            [$st, $me] = $run('GET', 'me', [], [], null);
            if ($st === 200) $csrf[$key] = $me['csrf'];
        }
        $token = $csrf[$key] ?? '';
    }
    return $run($method, $action, $in, $get, $token);
}

function done(): never
{
    echo $GLOBALS['t_fail'] ? "  {$GLOBALS['t_fail']} מתוך {$GLOBALS['t_n']} נכשלו\n" : "  ✓ {$GLOBALS['t_n']} בדיקות\n";
    exit($GLOBALS['t_fail'] ? 1 : 0);
}
