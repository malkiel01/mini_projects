<?php
/**
 * קונסולת המפתח — JSON בלבד. ‏?action=…
 *
 * נקרא רק מ-dev/index.html. מאחורי שער הפלטפורמה (לא ב-"public" של app.json),
 * ובנוסף כניסה משלו בעוגייה mg_dev — הגנה כפולה, כי מכאן נפתחים ונסגרים חשבונות.
 *
 * כל בקשה משנה: POST עם JSON, הכותרת X-Migdalor (אתר זר לא יכול לשלוח כותרת
 * כזו בלי CORS), ולמחובר — גם X-CSRF שצמוד ל-session.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/accounts.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function out(array $payload, int $code = 200): never {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $message, int $http = 400, string $code = 'error'): never {
    out(['ok' => false, 'error' => $message, 'code' => $code], $http);
}

function body(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $data = json_decode(file_get_contents('php://input') ?: '', true);
    return $cache = is_array($data) ? $data : [];
}

function str(string $k): string {
    $v = body()[$k] ?? '';
    return is_scalar($v) ? trim((string) $v) : '';
}

function num(string $k): int {
    return (int) (body()[$k] ?? 0);
}

/** מה הדף צריך כדי להחליט מה להציג: כניסה, התקנה, או הקונסולה. */
function meState(): array {
    $d = currentDeveloper();
    return [
        'ok'    => true,
        'setup' => developerCount() === 0,
        'me'    => $d ? ['id' => (int) $d['id'], 'username' => $d['username'], 'name' => $d['name']] : null,
        'csrf'  => $d ? (string) (sessionRow('dev')['csrf'] ?? '') : '',
        'meta'  => ['levels' => ACCOUNT_LEVELS, 'modules' => ACCOUNT_MODULES],
    ];
}

try {
    $action = (string) ($_GET['action'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        if ($action === 'me') out(meState());
        fail('שיטה לא נתמכת', 405);
    }
    if (($_SERVER['HTTP_X_MIGDALOR'] ?? '') !== '1') fail('בקשה לא תקינה', 400, 'bad_origin');

    /* ── לפני כניסה ── */
    if ($action === 'login') {
        [$d, $err] = developerLogin(str('username'), (string) (body()['password'] ?? ''));
        if ($err) fail($err, 401);
        out(['ok' => true]);
    }
    if ($action === 'setup') {
        [, $err] = developerSetup(str('username'), str('name'), (string) (body()['password'] ?? ''));
        if ($err) fail($err);
        out(['ok' => true]);
    }

    /* ── מכאן — רק מפתח מחובר ── */
    $dev = currentDeveloper();
    if (!$dev) fail('נדרשת כניסה', 401, 'unauthorized');
    $csrf = (string) (sessionRow('dev')['csrf'] ?? '');
    if ($csrf === '' || !hash_equals($csrf, (string) ($_SERVER['HTTP_X_CSRF'] ?? ''))) {
        fail('פג תוקף הדף — רעננו ונסו שוב', 400, 'csrf');
    }

    switch ($action) {
        case 'logout':
            endSession('dev');
            out(['ok' => true]);

        case 'password':
            $err = developerChangePassword((int) $dev['id'], (string) (body()['current'] ?? ''),
                                           (string) (body()['next'] ?? ''));
            if ($err) fail($err);
            out(['ok' => true]);

        case 'accounts':
            out(['ok' => true, 'accounts' => accountsList()]);

        case 'account':
            $a = accountDetail(num('id'));
            if (!$a) fail('החשבון לא נמצא', 404);
            out(['ok' => true, 'account' => $a]);

        case 'account_create':
            [$id, $pw, $err] = accountCreate(body()['account'] ?? [], str('parent_login'), str('parent_name'));
            if ($err) fail($err);
            out(['ok' => true, 'id' => $id, 'temp_password' => $pw, 'account' => accountDetail($id)]);

        case 'account_update':
            if ($err = accountUpdate(num('id'), body()['account'] ?? [])) fail($err);
            out(['ok' => true, 'account' => accountDetail(num('id'))]);

        case 'account_status':
            if ($err = accountSetStatus(num('id'), str('status'))) fail($err);
            out(['ok' => true, 'account' => accountDetail(num('id'))]);

        case 'account_extend':
            if ($err = accountExtend(num('id'), num('months'))) fail($err);
            out(['ok' => true, 'account' => accountDetail(num('id'))]);

        case 'parent_add':
            [$pw, $err] = parentAdd(num('account_id'), str('login'), str('name'));
            if ($err) fail($err);
            out(['ok' => true, 'temp_password' => $pw, 'account' => accountDetail(num('account_id'))]);

        case 'parent_reset':
            [$pw, $err] = parentReset(num('id'));
            if ($err) fail($err);
            out(['ok' => true, 'temp_password' => $pw]);

        case 'parent_remove':
            $p = one('SELECT account_id FROM parents WHERE id = ?', [num('id')]);
            if ($err = parentRemove(num('id'))) fail($err);
            out(['ok' => true, 'account' => accountDetail((int) ($p['account_id'] ?? 0))]);

        case 'system':
            out(['ok' => true, 'system' => systemOverview()]);
    }
    fail('פעולה לא מוכרת', 404, 'unknown_action');

} catch (Throwable $e) {
    logProblem('dev api: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    fail('שגיאת שרת', 500, 'server');
}
