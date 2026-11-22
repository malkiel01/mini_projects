<?php
/**
 * כלי הבדיקות: תיקיית נתונים ומסד זמניים, בקשות "החוצה" מדומות, וקריאה לנתב בלי שרת (sf_dispatch) — עם זהות
 * "מהשער", עוגייה ו-CSRF כמו בדפדפן.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('SF_DATA_DIR', sys_get_temp_dir() . '/sforno-test-' . bin2hex(random_bytes(5)));
define('SF_DB_PATH', SF_DATA_DIR . '/sforno.sqlite');
define('SF_ORIGIN', 'https://example.test');
define('SF_BASE_PATH', '/apps/sforno/');
mkdir(SF_DATA_DIR, 0700, true);
copy(dirname(__DIR__) . '/data/.htaccess', SF_DATA_DIR . '/.htaccess');
register_shutdown_function(function () {
    $rm = function (string $d) use (&$rm) {
        foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $f) is_dir("$d/$f") ? $rm("$d/$f") : @unlink("$d/$f");
        @rmdir($d);
    };
    if (str_starts_with(SF_DATA_DIR, sys_get_temp_dir() . '/sforno-test-')) $rm(SF_DATA_DIR);
});
putenv('SF_PLATFORM');
putenv('SF_TEST_IDENTITY');

// אין רשת: כל בקשה יוצאת נענית כאן, ונרשמת
$GLOBALS['t_http_log'] = [];
$GLOBALS['t_sso'] = [200, '{"app":"sforno","connected":true,"login":{"platform":true}}'];
$GLOBALS['sf_http'] = function (string $url) {
    $GLOBALS['t_http_log'][] = $url;
    if (str_contains($url, 'sso.php')) return $GLOBALS['t_sso'];
    if (str_contains($url, 'generate_204')) return [204, '', 40];
    return [403, '', 5];
};

require dirname(__DIR__) . '/lib/app.php';

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
function ident(string $sub, string $name = 'מנהל', ?string $email = null, array $more = []): string
{
    return (string)json_encode($more + ['sub' => $sub, 'name' => $name, 'email' => $email, 'email_verified' => $email !== null,
        'platform_admin' => false, 'granted' => true], JSON_UNESCAPED_UNICODE);
}

/** העוגיות של הבדיקה, לפי "דפדפן". */
function &jar(): array
{
    static $jar = [];
    return $jar;
}

/**
 * קריאה לנתב. $as — הזהות (ident(...)) או null. $opt: jar (שם הדפדפן; ברירת מחדל — הזהות), csrf (מפורש),
 * cookie (מפורש), content_type. מחזיר [status, body].
 */
function call(string $method, string $action, array $in = [], ?string $as = null, array $get = [], array $opt = []): array
{
    $jar = &jar();
    $key = $opt['jar'] ?? ($as ?? '-');
    if (array_key_exists('cookie', $opt)) $jar[$key] = $opt['cookie'];
    $run = function (string $method, string $action, array $in, array $get, string $csrf) use (&$jar, $key, $as, $opt): array {
        unset($GLOBALS['sf_cookie_out']);
        try {
            $body = sf_dispatch($method, $action, $get + ['action' => $action], $in, ['platform_user' => $as, 'cookie' => $jar[$key] ?? '',
                'csrf' => $csrf, 'ua' => 'test', 'content_type' => $opt['content_type'] ?? 'application/json']);
            $status = 200;
        } catch (Throwable $e) {
            sf_log_exception($e);
            [$status, $body] = sf_error_response($e);
            if ($status === 500) $GLOBALS['t_last_500'] = get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine();
        }
        if (isset($GLOBALS['sf_cookie_out'])) $jar[$key] = $GLOBALS['sf_cookie_out'];
        return [$status, $body];
    };
    $csrf = $opt['csrf'] ?? null;
    if ($method === 'POST' && $csrf === null && (SF_ACTIONS[$action]['who'] ?? '') !== 'public') {
        [$st, $me] = $run('GET', 'me', [], [], '');
        $csrf = $st === 200 ? $me['csrf'] : '';
    }
    return $run($method, $action, $in, $get, $csrf ?? '');
}

function code(array $res): ?string
{
    return $res[1]['error']['code'] ?? null;
}

function staff_by_sub(string $sub): ?array
{
    return sf_row('SELECT * FROM staff WHERE platform_sub = ?', [$sub]);
}

function audit_count(string $action): int
{
    return (int)sf_val('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$action]);
}

function done(): never
{
    echo $GLOBALS['t_fail'] ? "  {$GLOBALS['t_fail']} מתוך {$GLOBALS['t_n']} נכשלו\n" : "  ✓ {$GLOBALS['t_n']} בדיקות\n";
    exit($GLOBALS['t_fail'] ? 1 : 0);
}
