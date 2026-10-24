<?php
/**
 * ה-API למערכות מחוברות — שרת-לשרת, חתום (פרק 4.3). ב-"public" של app.json: ההגנה היא החתימה, לא השער.
 * POST api.php?action=<פעולה>, גוף JSON. שגיאה: {"error":"<עברית>","code":"…"}.
 * GET api.php?action=key — המפתח הציבורי של התוסף (לאימות webhooks ב-Ed25519), פתוח.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/pages.php';

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
    // השעון (הודעות שנוצרו בבקשה, חידושים, ניסיונות חוזרים) — אחרי שהתשובה נשלחה, לכל היותר פעם בדקה
    if ($action !== 'tick') tick_after_response('api');
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
    'checkout_create', 'checkout_get', 'charge', 'subscription_list', 'subscription_update', 'subscription_cancel', 'subscription_resume',
    'portal_session', 'invoice_list',
];

try {
    if ($action === 'key') {
        $k = signing_key();
        out(['service' => 'payments', 'keys' => [['kid' => $k['kid'], 'alg' => 'Ed25519', 'key' => base64_encode($k['public'])]]]);
    }
    if ($action === 'tick') {
        // שעון חיצוני: בלי מידע ובלי פרמטרים; מוגבל בעצמו לפעם בדקה
        $r = tick('external');
        out(['ok' => true, 'ran' => $r !== null]);
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
        'checkout_create' => checkout_create($client, $env, $in),
        'checkout_get' => (function () use ($client, $env, $in) {
            $c = checkout_load((string)($in['checkout'] ?? ''));
            if (!$c || $c['client'] !== $client['id'] || $c['env'] !== $env) throw new ApiError('הקנייה לא נמצאה', 'not_found', 404);
            $c = checkout_sync($c);
            return ['checkout' => $c['id'], 'status' => $c['status'], 'mode' => $c['mode'], 'amount' => (int)$c['amount'], 'result' => json_obj($c['result'])];
        })(),
        'charge' => charge_api($client, $env, $in),
        'subscription_list' => (function () use ($client, $env, $in) {
            $cus = customer_find($client, $env, external_id($in));
            return ['data' => array_map(fn($s) => [
                'id' => $s['id'], 'status' => $s['status'], 'period_start' => (int)$s['current_period_start'], 'period_end' => (int)$s['current_period_end'],
                'trial_end' => $s['trial_end'] ? (int)$s['trial_end'] : null, 'cancel_at' => $s['cancel_at_period_end'] ? (int)$s['current_period_end'] : null,
                'items' => array_map(fn($i) => ['product' => $i['code'], 'quantity' => (int)$i['quantity']], sub_items($s['id'])),
            ], db_all('SELECT * FROM subscription WHERE customer = ? ORDER BY created_at DESC', [$cus['id']]))];
        })(),
        'subscription_update' => subscription_update($client, $env, $in),
        'subscription_cancel' => (function () use ($client, $env, $in) {
            $cus = customer_find($client, $env, external_id($in));
            $s = subscription_cancel($cus, (string)($in['subscription'] ?? ''), ($in['at'] ?? '') === 'now' ? 'now' : 'period_end', 'client:' . $client['id']);
            return ['subscription' => $s['id'], 'status' => $s['status'], 'cancel_at' => $s['cancel_at_period_end'] ? (int)$s['current_period_end'] : null];
        })(),
        'subscription_resume' => (function () use ($client, $env, $in) {
            $s = subscription_resume(customer_find($client, $env, external_id($in)), (string)($in['subscription'] ?? ''));
            return ['subscription' => $s['id'], 'status' => $s['status']];
        })(),
        'portal_session' => portal_session_create($client, $env, $in),
        'invoice_list' => (function () use ($client, $env, $in) {
            $cus = customer_find($client, $env, external_id($in));
            return ['data' => array_map(fn($i) => ['id' => $i['id'], 'total' => (int)$i['total'], 'vat' => (int)$i['vat'], 'status' => $i['status'],
                'created_at' => (int)$i['created_at'], 'paid_at' => $i['paid_at'] ? (int)$i['paid_at'] : null],
                db_all("SELECT * FROM invoice WHERE account = ? AND status IN ('paid','open','uncollectible') ORDER BY created_at DESC LIMIT 100", [$cus['account']]))];
        })(),
    });
} catch (ApiError $e) {
    out(['error' => $e->getMessage(), 'code' => $e->codeName], $e->status);
} catch (Throwable $e) {
    error_log('payments api: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    out(['error' => 'תקלה בשרת', 'code' => 'server_error'], 500);
}
