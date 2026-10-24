<?php
/**
 * ליבת החיוב — בלי כסף אמיתי (שלב 2): קטלוג, לקוחות וחשבונות, ארנק, שימוש מדוד ושריון, זכאות, מנוי ידני.
 * פרקים 2–4 באפיון. דף התשלום, החידוש והסולקים — שלב 3 ואילך.
 */

declare(strict_types=1);

require_once __DIR__ . '/clients.php';
require_once __DIR__ . '/webhooks.php';

const PRODUCT_TYPES = ['plan', 'seat', 'unit', 'metered', 'credit_pack', 'addon'];
const RESERVE_TTL = 900;
const RESERVE_TTL_MAX = 3600;
const ENTITLEMENT_TTL = 300;

// ---------- קטלוג ----------

function product_save(array $tenant, string $clientId, array $in): array
{
    $c = client_get($tenant, $clientId);
    $code = strtolower(trim((string)($in['code'] ?? '')));
    if (!preg_match('/^[a-z0-9][a-z0-9_\-]{0,59}$/', $code)) throw new ApiError('קוד המוצר: אותיות לטיניות קטנות, ספרות, _ ומקף', 'bad_code');
    $name = trim(mb_substr((string)($in['name'] ?? ''), 0, 120));
    if ($name === '') throw new ApiError('שם המוצר חסר', 'bad_name');
    $type = (string)($in['type'] ?? '');
    if (!in_array($type, PRODUCT_TYPES, true)) throw new ApiError('סוג מוצר לא מוכר', 'bad_type');
    $grants = $in['grants'] ?? [];
    if (is_string($grants)) $grants = $grants === '' ? [] : json_decode($grants, true);
    if (!is_array($grants)) throw new ApiError('מה המוצר נותן — JSON לא תקין', 'bad_grants');
    $grants = array_intersect_key($grants, ['features' => 1, 'limits' => 1]);
    foreach ($grants['limits'] ?? [] as $k => $v) if (!is_numeric($v)) throw new ApiError("מגבלה '$k' חייבת להיות מספר", 'bad_grants');
    $unit = mb_substr((string)($in['unit_label'] ?? ''), 0, 40);
    $active = (int)(bool)($in['active'] ?? true);
    $existing = db_one('SELECT * FROM product WHERE client = ? AND code = ?', [$c['id'], $code]);
    if ($existing) {
        db_run('UPDATE product SET name = ?, type = ?, unit_label = ?, grants = ?, active = ? WHERE id = ?',
            [$name, $type, $unit, json_encode((object)$grants, JSON_UNESCAPED_UNICODE), $active, $existing['id']]);
        $id = $existing['id'];
    } else {
        $id = new_id('prod');
        db_run('INSERT INTO product (id, client, code, name, type, unit_label, grants, active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $c['id'], $code, $name, $type, $unit, json_encode((object)$grants, JSON_UNESCAPED_UNICODE), $active, now()]);
    }
    audit('admin', $tenant['id'], $existing ? 'product.updated' : 'product.created', 'product', $id, ['code' => $code, 'type' => $type], $tenant['id']);
    return db_one('SELECT * FROM product WHERE id = ?', [$id]);
}

/** מחיר חדש = גרסה חדשה. מחיר קיים לא נערך (פרק 2.3); הקודם נשאר למנויים שכבר עליו. */
function price_create(array $tenant, string $productId, array $in): array
{
    $p = db_one('SELECT p.* FROM product p JOIN client c ON c.id = p.client WHERE p.id = ? AND c.tenant = ?', [$productId, $tenant['id']]);
    if (!$p) throw new ApiError('המוצר לא נמצא', 'not_found', 404);
    $int = fn(string $k, int $def, int $min, int $max) => int_field($in, $k, $def, $min, $max);
    $interval = (string)($in['interval'] ?? (in_array($p['type'], ['plan', 'seat', 'addon'], true) ? 'month' : 'none'));
    if (!in_array($interval, ['month', 'year', 'week', 'none'], true)) throw new ApiError('מחזור לא מוכר', 'bad_price');
    if (in_array($p['type'], ['plan', 'seat', 'addon'], true) && $interval === 'none') throw new ApiError('מנוי חייב מחזור', 'bad_price');
    $aggregate = (string)($in['aggregate'] ?? 'sum');
    if (!in_array($aggregate, ['sum', 'max', 'last', 'unique'], true)) throw new ApiError('סיכום לא מוכר', 'bad_price');
    $tax = (string)($in['tax_behavior'] ?? 'inclusive');
    if (!in_array($tax, ['inclusive', 'exclusive'], true)) throw new ApiError('מע"מ: כולל או לא כולל', 'bad_price');
    $row = [
        'amount' => $int('amount', 0, 0, 100_000_000),
        'amount_micros' => $int('amount_micros', 0, 0, 1_000_000_000_000),
        'interval' => $interval,
        'interval_count' => $int('interval_count', 1, 1, 36),
        'package_size' => $int('package_size', 1, 1, 1_000_000_000),
        'included' => $int('included', 0, 0, 1_000_000_000_000),
        'aggregate' => $aggregate,
        'prepaid' => (int)(bool)($in['prepaid'] ?? true),
        'trial_days' => $int('trial_days', 0, 0, 365),
        'grace_days' => $int('grace_days', 7, 0, 60),
        'tax_behavior' => $tax,
    ];
    if ($p['type'] === 'metered' && $row['amount_micros'] === 0 && $row['amount'] > 0) $row['amount_micros'] = agorot_to_micros($row['amount']);
    $version = (int)(db_one('SELECT MAX(version) AS v FROM price WHERE product = ?', [$p['id']])['v'] ?? 0) + 1;
    $id = new_id('price');
    db_run('INSERT INTO price (id, product, version, amount, amount_micros, currency, tax_behavior, interval, interval_count, package_size, included, aggregate, prepaid, trial_days, grace_days, active, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)', [
        $id, $p['id'], $version, $row['amount'], $row['amount_micros'], 'ILS', $row['tax_behavior'], $row['interval'], $row['interval_count'],
        $row['package_size'], $row['included'], $row['aggregate'], $row['prepaid'], $row['trial_days'], $row['grace_days'], now(),
    ]);
    if (!empty($in['replace'])) db_run('UPDATE price SET active = 0 WHERE product = ? AND id <> ?', [$p['id'], $id]);
    audit('admin', $tenant['id'], 'price.created', 'price', $id, ['product' => $p['code'], 'version' => $version] + $row, $tenant['id']);
    return db_one('SELECT * FROM price WHERE id = ?', [$id]);
}

function int_field(array $in, string $k, int $def, int $min, int $max): int
{
    $v = $in[$k] ?? $def;
    if ($v === '' || $v === null) $v = $def;
    if (!is_numeric($v) || (int)$v != $v || $v < $min || $v > $max) throw new ApiError("ערך לא תקין: $k", 'bad_price');
    return (int)$v;
}

function price_deactivate(array $tenant, string $priceId): void
{
    $pr = db_one('SELECT pr.* FROM price pr JOIN product p ON p.id = pr.product JOIN client c ON c.id = p.client WHERE pr.id = ? AND c.tenant = ?', [$priceId, $tenant['id']]);
    if (!$pr) throw new ApiError('המחיר לא נמצא', 'not_found', 404);
    db_run('UPDATE price SET active = 0 WHERE id = ?', [$priceId]);
    audit('admin', $tenant['id'], 'price.deactivated', 'price', $priceId, [], $tenant['id']);
}

/** המחיר הפעיל העדכני של מוצר (לפי קוד), במערכת נתונה. */
function current_price(string $clientId, string $code): array
{
    $p = db_one('SELECT * FROM product WHERE client = ? AND code = ? AND active = 1', [$clientId, $code]);
    if (!$p) throw new ApiError("מוצר לא מוכר: $code", 'unknown_product', 404);
    $pr = db_one('SELECT * FROM price WHERE product = ? AND active = 1 ORDER BY version DESC LIMIT 1', [$p['id']]);
    if (!$pr) throw new ApiError("למוצר $code אין מחיר פעיל", 'no_price', 409);
    return [$p, $pr];
}

function catalog(array $client): array
{
    $out = [];
    foreach (db_all('SELECT * FROM product WHERE client = ? AND active = 1 ORDER BY created_at, rowid', [$client['id']]) as $p) {
        $prices = array_map(fn($pr) => price_public($pr), db_all('SELECT * FROM price WHERE product = ? AND active = 1 ORDER BY version DESC', [$p['id']]));
        $out[] = ['code' => $p['code'], 'name' => $p['name'], 'type' => $p['type'], 'unit_label' => $p['unit_label'], 'grants' => json_obj($p['grants']), 'prices' => $prices];
    }
    return $out;
}

function price_public(array $pr): array
{
    return [
        'id' => $pr['id'], 'version' => (int)$pr['version'], 'amount' => (int)$pr['amount'], 'amount_micros' => (int)$pr['amount_micros'],
        'currency' => $pr['currency'], 'tax_behavior' => $pr['tax_behavior'], 'interval' => $pr['interval'], 'interval_count' => (int)$pr['interval_count'],
        'package_size' => (int)$pr['package_size'], 'included' => (int)$pr['included'], 'aggregate' => $pr['aggregate'],
        'prepaid' => (bool)$pr['prepaid'], 'trial_days' => (int)$pr['trial_days'],
    ];
}

// ---------- לקוחות וחשבונות ----------

function external_id(array $in): string
{
    $x = (string)($in['external_id'] ?? '');
    if ($x === '' || strlen($x) > 120 || preg_match('/[\x00-\x1f]/', $x)) throw new ApiError('external_id חסר או לא תקין', 'bad_external_id');
    return $x;
}

function customer_upsert(array $client, string $env, array $in): array
{
    $ext = external_id($in);
    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 120);
    $email = mb_strtolower(trim((string)($in['email'] ?? '')));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ApiError('מייל לא תקין', 'bad_email');
    $type = (string)($in['customer_type'] ?? 'private');
    if (!in_array($type, ['private', 'business'], true)) throw new ApiError('סוג לקוח: private או business', 'bad_type');
    $taxId = preg_replace('/\D/', '', (string)($in['tax_id'] ?? ''));
    if (!$client['merchant']) throw new ApiError('למערכת עוד לא שויך עוסק — במסך הניהול', 'no_merchant', 409);
    return db_tx(function () use ($client, $env, $ext, $name, $email, $type, $taxId, $in) {
        $cus = db_one('SELECT * FROM customer WHERE client = ? AND env = ? AND external_id = ?', [$client['id'], $env, $ext]);
        if ($cus) {
            db_run('UPDATE customer SET name = ?, email = ? WHERE id = ?', [$name ?: $cus['name'], $email ?: $cus['email'], $cus['id']]);
            $acc = db_one('SELECT * FROM account WHERE id = ?', [$cus['account']]);
            db_run('UPDATE account SET name = ?, email = ?, customer_type = ?, tax_id = ? WHERE id = ?', [
                trim((string)($in['billing_name'] ?? '')) ?: ($name ?: $acc['name']), $email ?: $acc['email'], $type, $taxId ?: $acc['tax_id'], $acc['id'],
            ]);
        } else {
            $accId = new_id('acc');
            db_run('INSERT INTO account (id, merchant, env, name, tax_id, customer_type, email, email_verified_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $accId, $client['merchant'], $env, trim((string)($in['billing_name'] ?? '')) ?: $name, $taxId, $type, $email,
                !empty($in['email_verified']) && $email !== '' ? now() : null, now(),
            ]);
            db_run('INSERT INTO wallet_balance (account, micros) VALUES (?, 0)', [$accId]);
            $cusId = new_id('cus');
            db_run('INSERT INTO customer (id, client, env, external_id, account, name, email, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$cusId, $client['id'], $env, $ext, $accId, $name, $email, now()]);
            audit('client', $client['id'], 'customer.created', 'customer', $cusId, ['env' => $env], $client['tenant']);
        }
        return customer_view(customer_find($client, $env, $ext));
    });
}

function customer_find(array $client, string $env, string $ext, bool $must = true): ?array
{
    $c = db_one('SELECT * FROM customer WHERE client = ? AND env = ? AND external_id = ?', [$client['id'], $env, $ext]);
    if (!$c && $must) throw new ApiError('לקוח לא נמצא', 'not_found', 404);
    return $c;
}

function customer_view(array $cus): array
{
    $acc = db_one('SELECT * FROM account WHERE id = ?', [$cus['account']]);
    $bal = wallet_balance($acc['id']);
    return [
        'customer' => ['id' => $cus['id'], 'external_id' => $cus['external_id'], 'name' => $cus['name'], 'email' => $cus['email'], 'env' => $cus['env']],
        'account' => [
            'id' => $acc['id'], 'name' => $acc['name'], 'email' => $acc['email'], 'customer_type' => $acc['customer_type'], 'tax_id' => $acc['tax_id'],
            'balance' => micros_to_agorot($bal), 'balance_micros' => $bal, 'status' => $acc['status'],
        ],
    ];
}

// ---------- ארנק ----------

function wallet_balance(string $accountId): int
{
    return (int)(db_one('SELECT micros FROM wallet_balance WHERE account = ?', [$accountId])['micros'] ?? 0);
}

/** תנועה בארנק. חובה בתוך db_tx (נקרא רק משם). מחזיר את היתרה אחרי התנועה. */
function wallet_post(string $accountId, int $micros, string $kind, string $refType = '', string $refId = '', string $note = '', string $actor = ''): int
{
    if (!db_in_tx()) throw new LogicException('wallet_post מחוץ לטרנזקציה');
    db_run('INSERT INTO wallet_tx (id, account, amount, kind, ref_type, ref_id, note, actor, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [new_id('wtx'), $accountId, $micros, $kind, $refType, $refId, mb_substr($note, 0, 240), $actor, now()]);
    db_run('UPDATE wallet_balance SET micros = micros + ? WHERE account = ?', [$micros, $accountId]);
    return wallet_balance($accountId);
}

/** הזמין לחיוב: יתרה + מסגרת אשראי (אם המנהל נתן). */
function wallet_available(array $acc): int
{
    return wallet_balance($acc['id']) + (int)$acc['credit_limit'];
}

/** פעולת מנהל: טעינה ידנית / מתנה / תיקון. סיבה חובה (פרק 8.1). */
function wallet_admin(array $tenant, string $accountId, int $agorot, string $kind, string $note): array
{
    if (!in_array($kind, ['topup', 'grant', 'adjust'], true)) throw new ApiError('סוג תנועה לא מוכר', 'bad_kind');
    if ($agorot === 0 || abs($agorot) > 10_000_000) throw new ApiError('סכום לא תקין', 'bad_amount');
    if ($kind !== 'adjust' && $agorot < 0) throw new ApiError('טעינה/מתנה — רק סכום חיובי', 'bad_amount');
    if (trim($note) === '') throw new ApiError('חובה לכתוב סיבה', 'note_required');
    $acc = db_one('SELECT a.* FROM account a JOIN merchant m ON m.id = a.merchant WHERE a.id = ? AND m.tenant = ?', [$accountId, $tenant['id']]);
    if (!$acc) throw new ApiError('החשבון לא נמצא', 'not_found', 404);
    $bal = db_tx(fn() => wallet_post($acc['id'], agorot_to_micros($agorot), $kind, 'admin', $tenant['id'], $note, 'admin:' . $tenant['id']));
    audit('admin', $tenant['id'], 'wallet.' . $kind, 'account', $acc['id'], ['agorot' => $agorot, 'note' => $note], $tenant['id']);
    foreach (db_all('SELECT * FROM customer WHERE account = ?', [$acc['id']]) as $cus) {
        webhook_emit($cus['client'], $cus['env'], 'wallet.topped_up', $cus['external_id'], ['amount' => $agorot, 'kind' => $kind, 'balance' => micros_to_agorot($bal)]);
        webhook_emit($cus['client'], $cus['env'], 'entitlement.changed', $cus['external_id'], []);
    }
    return ['balance' => micros_to_agorot($bal), 'balance_micros' => $bal];
}

// ---------- שימוש ----------

/** עלות כמות לפי מחיר מדוד (מיליוניות ₪), מעוגלת לשלם הקרוב. */
function usage_cost(array $price, float $qty): int
{
    return (int)round($qty * (int)$price['amount_micros'] / max(1, (int)$price['package_size']));
}

function quantity(array $in, string $key = 'quantity'): float
{
    $q = $in[$key] ?? null;
    if (!is_numeric($q) || (float)$q <= 0 || (float)$q > 1e12) throw new ApiError("$key — מספר חיובי", 'bad_quantity');
    return (float)$q;
}

function idem_key(array $in): string
{
    $k = (string)($in['idempotency_key'] ?? '');
    if ($k === '' || strlen($k) > 120) throw new ApiError('idempotency_key חסר (עד 120 תווים)', 'bad_idempotency_key');
    return $k;
}

/**
 * usage_record (פרק 4.3). השימוש **כבר קרה** — לכן נרשם תמיד, גם אם היתרה יורדת מתחת לאפס;
 * allowed:false אומר לאפליקציה לעצור מכאן והלאה. כדי לא לרדת למינוס — usage_reserve לפני הפעולה.
 * אותו idempotency_key פעמיים → האירוע הקיים, duplicate:true, בלי חיוב נוסף.
 */
function usage_record(array $client, string $env, array $in): array
{
    $ext = external_id($in);
    $key = idem_key($in);
    $qty = quantity($in);
    [$product, $price] = current_price($client['id'], (string)($in['product'] ?? ''));
    $at = isset($in['occurred_at']) && ctype_digit((string)$in['occurred_at']) ? (int)$in['occurred_at'] : now();
    $meta = is_array($in['meta'] ?? null) ? $in['meta'] : [];
    return db_tx(function () use ($client, $env, $ext, $key, $qty, $product, $price, $at, $meta) {
        $dup = db_one('SELECT * FROM usage_event WHERE client = ? AND env = ? AND idempotency_key = ?', [$client['id'], $env, $key]);
        $cus = customer_find($client, $env, $ext);
        $acc = db_one('SELECT * FROM account WHERE id = ?', [$cus['account']]);
        if ($dup) return usage_result($dup, $acc, true);
        return usage_result(usage_insert($client, $env, $cus, $acc, $product, $price, $qty, $key, $at, $meta), $acc, false);
    });
}

function usage_insert(array $client, string $env, array $cus, array $acc, array $product, array $price, float $qty, string $key, int $at, array $meta): array
{
    $charge = $product['type'] === 'metered' && $price['prepaid'];
    $cost = $charge ? usage_cost($price, $qty) : 0;
    $id = new_id('use');
    db_run('INSERT INTO usage_event (id, client, env, customer, product, price, quantity, cost_micros, idempotency_key, occurred_at, meta, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$id, $client['id'], $env, $cus['id'], $product['id'], $price['id'], $qty, $cost, $key, $at, json_encode((object)$meta, JSON_UNESCAPED_UNICODE), now()]);
    if ($cost > 0) {
        $before = wallet_balance($acc['id']);
        $after = wallet_post($acc['id'], -$cost, 'usage', 'usage_event', $id, $product['code']);
        $low = (int)setting('wallet_low_micros', (string)agorot_to_micros(500));
        if ($after <= 0 && $before > 0) webhook_emit($client['id'], $env, 'wallet.empty', $cus['external_id'], ['balance' => micros_to_agorot($after)]);
        elseif ($after < $low && $before >= $low) webhook_emit($client['id'], $env, 'wallet.low', $cus['external_id'], ['balance' => micros_to_agorot($after)]);
    }
    return db_one('SELECT * FROM usage_event WHERE id = ?', [$id]);
}

function usage_result(array $ev, array $acc, bool $dup): array
{
    $acc = db_one('SELECT * FROM account WHERE id = ?', [$acc['id']]);
    $bal = wallet_balance($acc['id']);
    return [
        'event' => $ev['id'], 'duplicate' => $dup, 'cost_micros' => (int)$ev['cost_micros'],
        'allowed' => wallet_available($acc) > 0, 'balance' => micros_to_agorot($bal), 'balance_micros' => $bal,
    ];
}

/** שריון לפני פעולה (AI): מוריד מהיתרה עכשיו, ו-commit מתקן לכמות בפועל. אין מספיק → allowed:false, בלי שריון. */
function usage_reserve(array $client, string $env, array $in): array
{
    $ext = external_id($in);
    $est = quantity($in, 'estimate');
    [$product, $price] = current_price($client['id'], (string)($in['product'] ?? ''));
    if ($product['type'] !== 'metered' || !$price['prepaid']) throw new ApiError('שריון — רק למוצר מדוד בתשלום מראש', 'not_prepaid');
    $ttl = (int)($in['ttl'] ?? RESERVE_TTL);
    $ttl = max(60, min(RESERVE_TTL_MAX, $ttl));
    return db_tx(function () use ($client, $env, $ext, $est, $product, $price, $ttl) {
        $cus = customer_find($client, $env, $ext);
        $acc = db_one('SELECT * FROM account WHERE id = ?', [$cus['account']]);
        $cost = usage_cost($price, $est);
        if (wallet_available($acc) - $cost < 0) {
            return ['reservation' => null, 'allowed' => false, 'balance' => micros_to_agorot(wallet_balance($acc['id'])), 'code' => 'insufficient_balance'];
        }
        $id = new_id('res');
        db_run('INSERT INTO reservation (id, account, customer, product, price, estimate, micros, status, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $acc['id'], $cus['id'], $product['id'], $price['id'], $est, $cost, 'held', now() + $ttl, now()]);
        $bal = wallet_post($acc['id'], -$cost, 'reserve', 'reservation', $id, $product['code']);
        return ['reservation' => $id, 'allowed' => true, 'expires_at' => now() + $ttl, 'balance' => micros_to_agorot($bal), 'balance_micros' => $bal];
    });
}

function reservation_for(array $client, string $id): array
{
    $r = db_one('SELECT r.*, cu.client, cu.env, cu.external_id FROM reservation r JOIN customer cu ON cu.id = r.customer WHERE r.id = ?', [$id]);
    if (!$r || $r['client'] !== $client['id']) throw new ApiError('השריון לא נמצא', 'not_found', 404);
    return $r;
}

/** סגירת שריון בכמות בפועל: משחרר את השריון ורושם אירוע שימוש (שעשוי להיות גדול מהשריון). */
function usage_commit(array $client, string $env, array $in): array
{
    $key = idem_key($in);
    $qty = quantity($in);
    return db_tx(function () use ($client, $env, $in, $key, $qty) {
        $dup = db_one('SELECT * FROM usage_event WHERE client = ? AND env = ? AND idempotency_key = ?', [$client['id'], $env, $key]);
        $r = reservation_for($client, (string)($in['reservation'] ?? ''));
        if ($r['env'] !== $env) throw new ApiError('השריון לא נמצא', 'not_found', 404);
        $acc = db_one('SELECT * FROM account WHERE id = ?', [$r['account']]);
        if ($dup) return usage_result($dup, $acc, true);
        if ($r['status'] !== 'held') throw new ApiError('השריון כבר נסגר או פג', 'reservation_closed', 409);
        wallet_post($acc['id'], (int)$r['micros'], 'release', 'reservation', $r['id'], 'commit');
        db_run("UPDATE reservation SET status = 'committed' WHERE id = ?", [$r['id']]);
        $cus = db_one('SELECT * FROM customer WHERE id = ?', [$r['customer']]);
        $product = db_one('SELECT * FROM product WHERE id = ?', [$r['product']]);
        $price = db_one('SELECT * FROM price WHERE id = ?', [$r['price']]);
        $meta = is_array($in['meta'] ?? null) ? $in['meta'] : [];
        return usage_result(usage_insert($client, $env, $cus, $acc, $product, $price, $qty, $key, now(), $meta + ['reservation' => $r['id']]), $acc, false);
    });
}

function usage_release(array $client, string $env, array $in): array
{
    return db_tx(function () use ($client, $env, $in) {
        $r = reservation_for($client, (string)($in['reservation'] ?? ''));
        if ($r['env'] !== $env) throw new ApiError('השריון לא נמצא', 'not_found', 404);
        if ($r['status'] === 'held') {
            wallet_post($r['account'], (int)$r['micros'], 'release', 'reservation', $r['id'], 'release');
            db_run("UPDATE reservation SET status = 'released' WHERE id = ?", [$r['id']]);
        }
        return ['ok' => true, 'balance' => micros_to_agorot(wallet_balance($r['account']))];
    });
}

/** שריונים שפגו — חוזרים לארנק (cron). */
function reservations_expire(): int
{
    $n = 0;
    foreach (db_all("SELECT id FROM reservation WHERE status = 'held' AND expires_at < ?", [now()]) as $row) {
        $n += db_tx(function () use ($row) {
            $r = db_one("SELECT * FROM reservation WHERE id = ? AND status = 'held'", [$row['id']]);
            if (!$r) return 0;
            wallet_post($r['account'], (int)$r['micros'], 'release', 'reservation', $r['id'], 'expired');
            db_run("UPDATE reservation SET status = 'expired' WHERE id = ?", [$r['id']]);
            return 1;
        });
    }
    return $n;
}

/** סיכום שימוש לתקופה, לפי מוצר ולפי aggregate של המחיר. */
function usage_summary(array $client, string $env, array $in): array
{
    $cus = customer_find($client, $env, external_id($in));
    [$from, $to] = month_range((string)($in['period'] ?? ''));
    $out = [];
    foreach (db_all('SELECT * FROM product WHERE client = ?', [$client['id']]) as $p) {
        $ev = db_all('SELECT quantity, cost_micros, meta FROM usage_event WHERE customer = ? AND product = ? AND occurred_at >= ? AND occurred_at < ? ORDER BY occurred_at', [$cus['id'], $p['id'], $from, $to]);
        if (!$ev) continue;
        $pr = db_one('SELECT * FROM price WHERE product = ? AND active = 1 ORDER BY version DESC LIMIT 1', [$p['id']]);
        $out[] = [
            'product' => $p['code'], 'events' => count($ev), 'quantity' => aggregate_quantity($ev, $pr['aggregate'] ?? 'sum'),
            'included' => (int)($pr['included'] ?? 0), 'cost' => micros_to_agorot((int)array_sum(array_column($ev, 'cost_micros'))),
        ];
    }
    return ['period' => ['from' => $from, 'to' => $to], 'data' => $out];
}

function aggregate_quantity(array $events, string $agg): float
{
    $q = array_map(fn($e) => (float)$e['quantity'], $events);
    return match ($agg) {
        'max' => max($q),
        'last' => end($q),
        'unique' => (float)count(array_unique(array_map(fn($e) => (string)(json_obj($e['meta'])['subject'] ?? ''), $events))),
        default => array_sum($q),
    };
}

/** חודש קלנדרי (UTC) — "2026-10" או ריק לחודש הנוכחי. */
function month_range(string $period): array
{
    if ($period !== '' && !preg_match('/^\d{4}-\d{2}$/', $period)) throw new ApiError('period בצורה YYYY-MM', 'bad_period');
    $start = strtotime(($period ?: gmdate('Y-m', now())) . '-01 00:00:00 UTC');
    return [$start, strtotime('+1 month', $start)];
}

// ---------- מנוי ידני וזכאות ----------

/** המנהל מעניק תכנית ללקוח (בלי חיוב) — לבדיקות ולמקרים מיוחדים. החיוב האמיתי — שלב 3. */
function subscription_grant(array $tenant, string $customerId, array $in): array
{
    $cus = db_one('SELECT cu.* FROM customer cu JOIN client c ON c.id = cu.client WHERE cu.id = ? AND c.tenant = ?', [$customerId, $tenant['id']]);
    if (!$cus) throw new ApiError('הלקוח לא נמצא', 'not_found', 404);
    $items = $in['items'] ?? [];
    if (!is_array($items) || !$items) throw new ApiError('בחר לפחות מוצר אחד', 'bad_items');
    $end = isset($in['period_end']) && $in['period_end'] !== '' ? (int)$in['period_end'] : null;
    $id = db_tx(function () use ($cus, $items, $end) {
        $id = new_id('sub');
        db_run('INSERT INTO subscription (id, account, customer, client, status, source, current_period_start, current_period_end, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $cus['account'], $cus['id'], $cus['client'], 'active', 'manual', now(), $end, now()]);
        foreach ($items as $it) {
            [, $price] = current_price($cus['client'], (string)($it['product'] ?? ''));
            $qty = max(1, min(10_000, (int)($it['quantity'] ?? 1)));
            db_run('INSERT INTO subscription_item (id, subscription, price, quantity) VALUES (?, ?, ?, ?)', [new_id('si'), $id, $price['id'], $qty]);
        }
        return $id;
    });
    audit('admin', $tenant['id'], 'subscription.granted', 'subscription', $id, ['customer' => $cus['id']], $tenant['id']);
    webhook_emit($cus['client'], $cus['env'], 'subscription.created', $cus['external_id'], ['subscription' => $id, 'source' => 'manual']);
    webhook_emit($cus['client'], $cus['env'], 'entitlement.changed', $cus['external_id'], []);
    return db_one('SELECT * FROM subscription WHERE id = ?', [$id]);
}

function subscription_cancel_admin(array $tenant, string $subId): void
{
    $s = db_one('SELECT s.*, cu.env, cu.external_id FROM subscription s JOIN customer cu ON cu.id = s.customer JOIN client c ON c.id = s.client WHERE s.id = ? AND c.tenant = ?', [$subId, $tenant['id']]);
    if (!$s) throw new ApiError('המנוי לא נמצא', 'not_found', 404);
    db_run("UPDATE subscription SET status = 'canceled', canceled_at = ? WHERE id = ?", [now(), $subId]);
    audit('admin', $tenant['id'], 'subscription.canceled', 'subscription', $subId, [], $tenant['id']);
    webhook_emit($s['client'], $s['env'], 'subscription.canceled', $s['external_id'], ['subscription' => $subId]);
    webhook_emit($s['client'], $s['env'], 'entitlement.changed', $s['external_id'], []);
}

/** מנוי שמעניק זכאות עכשיו: פעיל/ניסיון, או באיחור בתוך ימי החסד; ובתוך התקופה (אם יש סוף). */
function subscription_live(array $s): bool
{
    if ($s['current_period_end'] && (int)$s['current_period_end'] < now() && $s['status'] !== 'past_due') return false;
    return in_array($s['status'], ['trialing', 'active', 'past_due'], true);
}

/**
 * entitlement (פרק 4.4): מה מותר ללקוח עכשיו. features/limits — איחוד ה-grants של כל המוצרים במנויים החיים;
 * מגבלות נסכמות × כמות (תכנית עם 3 מכשירים + "מכשיר נוסף" × 2 → 5).
 */
function entitlement(array $client, string $env, array $in): array
{
    $cus = customer_find($client, $env, external_id($in), false);
    if (!$cus) return ['active' => false, 'state' => 'none', 'plans' => [], 'features' => (object)[], 'limits' => (object)[], 'balance' => 0, 'ttl' => ENTITLEMENT_TTL];
    $subs = db_all("SELECT * FROM subscription WHERE customer = ? AND status <> 'canceled' ORDER BY created_at", [$cus['id']]);
    $plans = $features = $limits = [];
    $state = 'none';
    $until = null;
    $rank = ['none' => 0, 'canceled' => 1, 'paused' => 2, 'past_due' => 3, 'trialing' => 4, 'active' => 5];
    foreach ($subs as $s) {
        if (!subscription_live($s)) continue;
        if ($rank[$s['status']] > $rank[$state]) $state = $s['status'];
        if ($s['current_period_end']) $until = max($until ?? 0, (int)$s['current_period_end']);
        foreach (db_all('SELECT si.quantity, p.code, p.type, p.grants FROM subscription_item si JOIN price pr ON pr.id = si.price JOIN product p ON p.id = pr.product WHERE si.subscription = ?', [$s['id']]) as $it) {
            if ($it['type'] === 'plan') $plans[] = $it['code'];
            $g = json_obj($it['grants']);
            $features[$it['code']] = (int)$it['quantity'] > 1 ? (int)$it['quantity'] : true;
            foreach ($g['features'] ?? [] as $k => $v) $features[$k] = $v;
            foreach ($g['limits'] ?? [] as $k => $v) $limits[$k] = ($limits[$k] ?? 0) + $v * (int)$it['quantity'];
        }
    }
    $acc = db_one('SELECT * FROM account WHERE id = ?', [$cus['account']]);
    $bal = wallet_balance($acc['id']);
    [$from, $to] = month_range('');
    $metered = [];
    foreach (db_all("SELECT p.id, p.code FROM product p WHERE p.client = ? AND p.type = 'metered'", [$client['id']]) as $p) {
        $ev = db_all('SELECT quantity, meta FROM usage_event WHERE customer = ? AND product = ? AND occurred_at >= ? AND occurred_at < ?', [$cus['id'], $p['id'], $from, $to]);
        $pr = db_one('SELECT included, aggregate FROM price WHERE product = ? AND active = 1 ORDER BY version DESC LIMIT 1', [$p['id']]);
        $metered[$p['code']] = ['used' => $ev ? aggregate_quantity($ev, $pr['aggregate'] ?? 'sum') : 0, 'included' => (int)($pr['included'] ?? 0)];
    }
    return [
        'active' => in_array($state, ['trialing', 'active', 'past_due'], true),
        'state' => $state, 'plans' => array_values(array_unique($plans)),
        'features' => (object)$features, 'limits' => (object)$limits,
        'balance' => micros_to_agorot($bal), 'balance_micros' => $bal, 'can_spend' => wallet_available($acc) > 0,
        'metered' => (object)$metered, 'until' => $until, 'ttl' => ENTITLEMENT_TTL,
    ];
}
