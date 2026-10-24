<?php
/**
 * ה-API של ממשק הניהול (index.html). מאחורי השער — רק מי שהשער הכניס (היום: המנהל בלבד).
 * בתוך התוסף: הבעלים (owner) רואה הכול ומאשר מנויים; מנוי מאושר (tenant) — רק את שלו (פרק 12).
 * POST admin.php?action=<פעולה>, JSON. הגנת CSRF: Origin חייב להיות של אותו אתר, והגוף JSON.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/jobs.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function out(array|object $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    tick_after_response('admin');
    exit;
}

$action = (string)($_GET['action'] ?? '');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new ApiError('נדרש POST', 'method', 405);
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($origin === '' || parse_url($origin, PHP_URL_HOST) . (parse_url($origin, PHP_URL_PORT) ? ':' . parse_url($origin, PHP_URL_PORT) : '') !== $host) {
        throw new ApiError('בקשה ממקור לא מוכר', 'bad_origin', 403);
    }
    if (!str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) throw new ApiError('נדרש JSON', 'bad_json', 415);
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) $in = [];

    $t = current_tenant();
    if (!$t) throw new ApiError('לא מחובר', 'no_user', 401);
    // כתובת הבסיס — לעבודות שרצות משורת הפקודה (אין שם כתובת בקשה)
    if (!setting('base_url') && !getenv('PAY_BASE_URL')) set_setting('base_url', base_url());
    if ($action === 'me') out(['tenant' => tenant_public($t)]);
    if ($t['status'] !== 'active') throw new ApiError('הגישה ממתינה לאישור המנהל', 'pending', 403);
    $owner = $t['role'] === 'owner';
    $s = fn(string $k) => (string)($in[$k] ?? '');

    out(match ($action) {
        'overview' => overview($t),

        // עוסקים
        'merchants' => ['data' => db_all('SELECT * FROM merchant WHERE tenant = ? ORDER BY created_at', [$t['id']])],
        'merchant_save' => ['merchant' => merchant_save($t, $in)],

        // מערכות וקודים
        'clients' => ['data' => db_all('SELECT c.id, c.name, c.kind, c.status, c.live_ok, c.merchant, m.name AS merchant_name,
                (SELECT COUNT(*) FROM customer cu WHERE cu.client = c.id) AS customers,
                (SELECT COUNT(*) FROM product p WHERE p.client = c.id) AS products
            FROM client c LEFT JOIN merchant m ON m.id = c.merchant WHERE c.tenant = ? ORDER BY c.created_at', [$t['id']])],
        'client_create' => ['client' => client_create($t, $in)],
        'client_update' => ['client' => client_update($t, $s('id'), $in)],
        'client_get' => client_detail($t, $s('id')),
        'key_create' => ['key' => key_create($t, $s('client'), $s('env'), $s('kind'), !empty($in['revoke_old']))],
        'key_revoke' => (function () use ($t, $s) { key_revoke($t, $s('id')); return ['ok' => true]; })(),
        'webhook_test' => (function () use ($t, $s) {
            $c = client_get($t, $s('client'));
            if ($c['webhook_url'] === '') throw new ApiError('למערכת אין כתובת webhook', 'no_webhook', 409);
            $id = webhook_emit($c['id'], in_array($s('env'), ['test', 'live'], true) ? $s('env') : 'test', 'ping', null, ['message' => 'בדיקה מממשק הניהול']);
            webhooks_deliver(5);
            return ['event' => db_one('SELECT id, status, attempt, response_code FROM webhook_delivery WHERE id = ?', [$id])];
        })(),
        'webhook_retry' => (function () use ($t, $s) { webhook_retry($t, $s('id')); webhooks_deliver(5); return ['ok' => true]; })(),

        // קטלוג
        'product_save' => ['product' => product_save($t, $s('client'), $in)],
        'price_create' => ['price' => price_create($t, $s('product'), $in)],
        'price_deactivate' => (function () use ($t, $s) { price_deactivate($t, $s('id')); return ['ok' => true]; })(),

        // לקוחות
        'customers' => customers_list($t, $in),
        'customer_detail' => customer_detail($t, $s('id')),
        'wallet_adjust' => wallet_admin($t, $s('account'), (int)($in['agorot'] ?? 0), $s('kind'), $s('note')),
        'subscription_grant' => ['subscription' => subscription_grant($t, $s('customer'), $in)],
        'subscription_cancel' => (function () use ($t, $s) { subscription_cancel_admin($t, $s('id')); return ['ok' => true]; })(),

        // מנויים מאושרים — רק הבעלים (פרק 12.5)
        'tenants' => $owner ? ['data' => array_map('tenant_public', db_all('SELECT * FROM tenant ORDER BY created_at'))] : throw new ApiError('רק לבעלים', 'forbidden', 403),
        'tenant_status' => $owner ? tenant_set_status($t, $s('id'), $s('status')) : throw new ApiError('רק לבעלים', 'forbidden', 403),

        // כספים (שלב 3)
        'payments' => ['data' => db_all("SELECT p.*, cu.name AS customer_name, cu.external_id, cu.client, cu.id AS customer_id,
                (SELECT number FROM tax_document d WHERE d.payment = p.id LIMIT 1) AS doc_number,
                (SELECT type FROM tax_document d WHERE d.payment = p.id LIMIT 1) AS doc_type,
                pa.provider AS provider_type
            FROM payment p JOIN account a ON a.id = p.account JOIN merchant m ON m.id = a.merchant
            LEFT JOIN customer cu ON cu.account = a.id LEFT JOIN provider_account pa ON pa.id = p.provider WHERE m.tenant = ? GROUP BY p.id ORDER BY p.created_at DESC LIMIT 200", [$t['id']])],
        'invoices' => ['data' => db_all("SELECT i.*, (SELECT description FROM invoice_line WHERE invoice = i.id LIMIT 1) AS description,
                (SELECT name FROM customer WHERE account = i.account LIMIT 1) AS customer_name
            FROM invoice i JOIN merchant m ON m.id = i.merchant WHERE m.tenant = ? ORDER BY i.created_at DESC LIMIT 200", [$t['id']])],
        'refund' => (function () use ($t, $s, $in) {
            $p = db_one('SELECT p.* FROM payment p JOIN account a ON a.id = p.account JOIN merchant m ON m.id = a.merchant WHERE p.id = ? AND m.tenant = ?', [$s('payment'), $t['id']]);
            if (!$p) throw new ApiError('התשלום לא נמצא', 'not_found', 404);
            return payment_refund($p['id'], (int)($in['agorot'] ?? 0), $s('reason'), 'admin:' . $t['id'], !empty($in['manual']));
        })(),

        // חברות סליקה (שלב 4)
        'providers' => ['data' => provider_accounts($t, $s('merchant')), 'types' => PROVIDER_TYPES],
        'provider_save' => ['provider' => provider_account_save($t, $in)],
        'provider_check' => ['provider' => provider_account_check($t, $s('id'))],
        'clock' => [
            'last' => (int)setting('tick_last', '0'), 'cron' => (int)setting('tick_last_cron', '0'), 'external' => (int)setting('tick_last_external', '0'),
            'url' => base_url() . 'api.php?action=tick', 'now' => now(),
            'notifications' => db_all("SELECT n.channel, n.template, n.to_addr, n.status, n.created_at FROM notification n JOIN account a ON a.id = n.account JOIN merchant m ON m.id = a.merchant WHERE m.tenant = ? ORDER BY n.created_at DESC LIMIT 30", [$t['id']]),
        ],
        'tick_now' => tick('admin', true) ?? [],

        'audit' => ['data' => db_all('SELECT actor_type, actor_id, action, object_type, object_id, detail, created_at FROM audit WHERE tenant = ? ORDER BY id DESC LIMIT 100', [$t['id']])],
        default => throw new ApiError('פעולה לא מוכרת', 'unknown_action', 404),
    });
} catch (ApiError $e) {
    out(['error' => $e->getMessage(), 'code' => $e->codeName], $e->status);
} catch (Throwable $e) {
    error_log('payments admin: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    out(['error' => 'תקלה בשרת', 'code' => 'server_error'], 500);
}

function tenant_public(array $t): array
{
    return ['id' => $t['id'], 'name' => $t['name'], 'email' => $t['email'], 'role' => $t['role'], 'status' => $t['status'], 'created_at' => (int)$t['created_at'], 'last_seen' => (int)$t['last_seen']];
}

function tenant_set_status(array $owner, string $id, string $status): array
{
    if (!in_array($status, ['active', 'suspended', 'closed'], true)) throw new ApiError('מצב לא תקין', 'bad_status');
    $t = db_one('SELECT * FROM tenant WHERE id = ?', [$id]);
    if (!$t) throw new ApiError('המנוי לא נמצא', 'not_found', 404);
    if ($t['role'] === 'owner') throw new ApiError('אי אפשר לשנות את הבעלים', 'forbidden', 403);
    db_run('UPDATE tenant SET status = ? WHERE id = ?', [$status, $id]);
    audit('admin', $owner['id'], 'tenant.' . $status, 'tenant', $id, [], $owner['id']);
    return ['tenant' => tenant_public(db_one('SELECT * FROM tenant WHERE id = ?', [$id]))];
}

function overview(array $t): array
{
    $one = fn(string $sql) => (int)(db_one($sql, [$t['id']])['n'] ?? 0);
    [$from] = month_range('');
    return [
        'merchants' => $one('SELECT COUNT(*) AS n FROM merchant WHERE tenant = ?'),
        'clients' => $one('SELECT COUNT(*) AS n FROM client WHERE tenant = ?'),
        'customers' => $one('SELECT COUNT(*) AS n FROM customer cu JOIN client c ON c.id = cu.client WHERE c.tenant = ?'),
        'subscriptions' => $one("SELECT COUNT(*) AS n FROM subscription s JOIN client c ON c.id = s.client WHERE c.tenant = ? AND s.status IN ('active','trialing','past_due')"),
        'wallets' => micros_to_agorot((int)(db_one('SELECT SUM(b.micros) AS n FROM wallet_balance b JOIN account a ON a.id = b.account JOIN merchant m ON m.id = a.merchant WHERE m.tenant = ? AND a.env = \'live\'', [$t['id']])['n'] ?? 0)),
        'usage_month' => micros_to_agorot((int)(db_one('SELECT SUM(u.cost_micros) AS n FROM usage_event u JOIN client c ON c.id = u.client WHERE c.tenant = ? AND u.env = \'live\' AND u.occurred_at >= ?', [$t['id'], $from])['n'] ?? 0)),
        'webhooks_failed' => $one("SELECT COUNT(*) AS n FROM webhook_delivery d JOIN client c ON c.id = d.client WHERE c.tenant = ? AND d.status = 'failed'"),
        'pending_tenants' => $t['role'] === 'owner' ? (int)(db_one("SELECT COUNT(*) AS n FROM tenant WHERE status = 'pending'")['n'] ?? 0) : 0,
    ];
}

function client_detail(array $t, string $id): array
{
    $c = client_get($t, $id);
    $products = db_all('SELECT * FROM product WHERE client = ? ORDER BY created_at', [$id]);
    foreach ($products as &$p) {
        $p['grants'] = json_obj($p['grants']);
        $p['prices'] = array_map('price_public', db_all('SELECT * FROM price WHERE product = ? AND active = 1 ORDER BY version DESC', [$p['id']]));
    }
    unset($p);
    $c['origins'] = json_obj($c['origins']);
    unset($c['ed_keys']);
    return [
        'client' => $c, 'keys' => keys_list($t, $id), 'products' => $products,
        'log' => db_all('SELECT action, env, status, code, ms, created_at FROM api_log WHERE client = ? ORDER BY id DESC LIMIT 50', [$id]),
        'webhooks' => db_all('SELECT id, env, type, status, attempt, response_code, created_at FROM webhook_delivery WHERE client = ? ORDER BY created_at DESC LIMIT 30', [$id]),
    ];
}

function customers_list(array $t, array $in): array
{
    $q = trim((string)($in['q'] ?? ''));
    $args = [$t['id']];
    $where = '';
    if ($q !== '') {
        $where = ' AND (cu.external_id LIKE ? OR cu.name LIKE ? OR cu.email LIKE ? OR a.tax_id LIKE ?)';
        array_push($args, ...array_fill(0, 4, '%' . addcslashes($q, '%_') . '%'));
    }
    $rows = db_all("SELECT cu.id, cu.client, cu.env, cu.external_id, cu.name, cu.email, cu.created_at, a.id AS account, b.micros
        FROM customer cu JOIN client c ON c.id = cu.client JOIN account a ON a.id = cu.account LEFT JOIN wallet_balance b ON b.account = a.id
        WHERE c.tenant = ?$where ORDER BY cu.created_at DESC LIMIT 200", $args);
    foreach ($rows as &$r) $r['balance'] = micros_to_agorot((int)$r['micros']);
    return ['data' => $rows];
}

function customer_detail(array $t, string $id): array
{
    $cus = db_one('SELECT cu.* FROM customer cu JOIN client c ON c.id = cu.client WHERE cu.id = ? AND c.tenant = ?', [$id, $t['id']]);
    if (!$cus) throw new ApiError('הלקוח לא נמצא', 'not_found', 404);
    $client = db_one('SELECT * FROM client WHERE id = ?', [$cus['client']]);
    $subs = db_all('SELECT * FROM subscription WHERE customer = ? ORDER BY created_at DESC', [$id]);
    foreach ($subs as &$s) $s['items'] = db_all('SELECT si.quantity, p.code, p.name FROM subscription_item si JOIN price pr ON pr.id = si.price JOIN product p ON p.id = pr.product WHERE si.subscription = ?', [$s['id']]);
    unset($s);
    return customer_view($cus) + [
        'client' => ['id' => $client['id'], 'name' => $client['name']],
        'entitlement' => entitlement($client, $cus['env'], ['external_id' => $cus['external_id']]),
        'subscriptions' => $subs,
        'wallet' => db_all('SELECT amount, kind, note, actor, created_at FROM wallet_tx WHERE account = ? ORDER BY created_at DESC LIMIT 50', [$cus['account']]),
        'usage' => db_all('SELECT u.quantity, u.cost_micros, u.occurred_at, p.code FROM usage_event u JOIN product p ON p.id = u.product WHERE u.customer = ? ORDER BY u.occurred_at DESC LIMIT 50', [$id]),
    ];
}
