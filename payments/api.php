<?php
/**
 * ה-API למערכות מחוברות — שרת-לשרת, חתום (פרק 4.3). ב-"public" של app.json: ההגנה היא החתימה, לא השער.
 * POST api.php?action=<פעולה>, גוף JSON. שגיאה: {"error":"<עברית>","code":"…"}.
 * GET api.php?action=key — המפתח הציבורי של התוסף (לאימות webhooks ב-Ed25519), פתוח.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/billing.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$started = microtime(true);
$action = (string)($_GET['action'] ?? '');
$raw = (string)file_get_contents('php://input');
$ctx = null;

function out(array|object $data, int $status = 200): never
{
    global $ctx, $action, $started;
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    try {
        db_run('INSERT INTO api_log (client, env, action, status, code, ms, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
            $ctx['client']['id'] ?? null, $ctx['env'] ?? null, mb_substr($action, 0, 40), $status,
            is_array($data) ? (string)($data['code'] ?? '') : '', (int)((microtime(true) - $started) * 1000), client_ip(), now(),
        ]);
    } catch (Throwable) {
    }
    // אירועים שנוצרו בבקשה — יוצאים אחרי שהתשובה נשלחה, כדי לא לעכב את המערכת
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        try { webhooks_deliver(10); } catch (Throwable) {}
    }
    exit;
}

function request_headers(): array
{
    $h = [];
    foreach ($_SERVER as $k => $v) if (str_starts_with($k, 'HTTP_')) $h[str_replace('_', '-', substr($k, 5))] = (string)$v;
    return $h;
}

const CLIENT_ACTIONS = [
    'catalog', 'customer_upsert', 'customer_get', 'entitlement', 'wallet_get',
    'usage_record', 'usage_batch', 'usage_reserve', 'usage_commit', 'usage_release', 'usage_summary', 'ping',
];

try {
    if ($action === 'key') {
        $k = signing_key();
        out(['service' => 'payments', 'keys' => [['kid' => $k['kid'], 'alg' => 'Ed25519', 'key' => base64_encode($k['public'])]]]);
    }
    if (!in_array($action, CLIENT_ACTIONS, true)) throw new ApiError('פעולה לא מוכרת', 'unknown_action', 404);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new ApiError('נדרש POST', 'method', 405);
    if (strlen($raw) > 1 << 20) throw new ApiError('הגוף גדול מדי', 'too_large', 413);
    $in = $raw === '' ? [] : json_decode($raw, true);
    if (!is_array($in)) throw new ApiError('הגוף חייב להיות JSON', 'bad_json');

    $ctx = auth_request($action, $raw, request_headers());
    [$client, $env] = [$ctx['client'], $ctx['env']];

    out(match ($action) {
        'ping' => ['ok' => true, 'client' => $client['id'], 'env' => $env, 'time' => now()],
        'catalog' => ['products' => catalog($client)],
        'customer_upsert' => customer_upsert($client, $env, $in),
        'customer_get' => customer_view(customer_find($client, $env, external_id($in))),
        'entitlement' => entitlement($client, $env, $in),
        'wallet_get' => (function () use ($client, $env, $in) {
            $cus = customer_find($client, $env, external_id($in));
            $bal = wallet_balance($cus['account']);
            $recent = db_all('SELECT amount, kind, note, created_at FROM wallet_tx WHERE account = ? ORDER BY created_at DESC LIMIT 20', [$cus['account']]);
            return ['balance' => micros_to_agorot($bal), 'balance_micros' => $bal, 'recent' => array_map(fn($t) => [
                'amount_micros' => (int)$t['amount'], 'kind' => $t['kind'], 'note' => $t['note'], 'at' => (int)$t['created_at'],
            ], $recent)];
        })(),
        'usage_record' => usage_record($client, $env, $in),
        'usage_batch' => (function () use ($client, $env, $in) {
            $events = $in['events'] ?? null;
            if (!is_array($events) || !$events || count($events) > 500) throw new ApiError('events — 1 עד 500', 'bad_events');
            $accepted = $dups = 0;
            $rejected = [];
            foreach (array_values($events) as $i => $e) {
                try {
                    $r = usage_record($client, $env, is_array($e) ? $e : []);
                    $r['duplicate'] ? $dups++ : $accepted++;
                } catch (ApiError $err) {
                    $rejected[] = ['i' => $i, 'code' => $err->codeName, 'error' => $err->getMessage()];
                }
            }
            return ['accepted' => $accepted, 'duplicates' => $dups, 'rejected' => $rejected];
        })(),
        'usage_reserve' => usage_reserve($client, $env, $in),
        'usage_commit' => usage_commit($client, $env, $in),
        'usage_release' => usage_release($client, $env, $in),
        'usage_summary' => usage_summary($client, $env, $in),
    });
} catch (ApiError $e) {
    out(['error' => $e->getMessage(), 'code' => $e->codeName], $e->status);
} catch (Throwable $e) {
    error_log('payments api: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    out(['error' => 'תקלה בשרת', 'code' => 'server_error'], 500);
}
