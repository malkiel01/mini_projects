<?php
/**
 * קישורי תשלום (פרק 12.6) — בלי קוד: המנהל יוצר קישור, מדביק בוואטסאפ / במייל / באתר, והלקוח משלם.
 * הסכום תמיד מוגדר אצלנו (מחיר המוצר, או טווח שהמנהל קבע) — הדפדפן לא קובע סכום.
 *
 * וגם — מנוע הכללים (פרק 12.4): "כש… אז…", נבדק בכל קנייה.
 */

declare(strict_types=1);

require_once __DIR__ . '/checkout.php';

const LINK_FIELDS = ['name' => 'שם', 'email' => 'מייל', 'phone' => 'טלפון', 'tax_id' => 'ח"פ / ע"מ', 'note' => 'הערה'];

function link_save(array $tenant, array $in): array
{
    $c = client_get($tenant, (string)($in['client'] ?? ''));
    $title = trim(mb_substr((string)($in['title'] ?? ''), 0, 120));
    if ($title === '') throw new ApiError('כותרת חסרה', 'bad_title');
    $env = (string)($in['env'] ?? 'test');
    if (!in_array($env, ['test', 'live'], true)) throw new ApiError('סביבה לא תקינה', 'bad_env');
    if ($env === 'live' && !$c['live_ok']) throw new ApiError('המערכת עוד לא מאושרת לכסף אמיתי', 'live_not_approved', 409);
    [$p, $pr] = current_price($c['id'], (string)($in['product'] ?? ''));
    if (!in_array($p['type'], ['unit', 'credit_pack'], true)) throw new ApiError('קישור — רק למוצר לפי יחידה או חבילה', 'bad_product');
    $mode = (string)($in['amount_mode'] ?? 'fixed') === 'custom' ? 'custom' : 'fixed';
    $min = int_field($in, 'min_amount', 100, 100, 10_000_000);
    $max = int_field($in, 'max_amount', 100_000, 100, 10_000_000);
    if ($mode === 'custom' && $min > $max) throw new ApiError('מינימום גדול ממקסימום', 'bad_amount');
    if ($mode === 'fixed' && (int)$pr['amount'] <= 0) throw new ApiError('למוצר אין מחיר — בחר "סכום לבחירה"', 'bad_amount');
    $fields = array_values(array_intersect((array)($in['fields'] ?? ['name', 'email']), array_keys(LINK_FIELDS)));
    if (!in_array('email', $fields, true) && !in_array('phone', $fields, true)) $fields[] = 'email'; // צריך לאן לשלוח קבלה
    $success = trim((string)($in['success_url'] ?? ''));
    if ($success !== '' && !valid_url($success)) throw new ApiError('כתובת אחרי תשלום — https מלאה', 'bad_url');
    $row = [$title, mb_substr(trim((string)($in['description'] ?? '')), 0, 600), $p['code'], max(1, min(1000, (int)($in['quantity'] ?? 1))), $mode, $min, $max,
        json_encode($fields), $success, max(0, (int)($in['max_uses'] ?? 0)), isset($in['expires_at']) && $in['expires_at'] !== '' ? (int)$in['expires_at'] : null, (int)(bool)($in['active'] ?? true)];
    $id = (string)($in['id'] ?? '');
    if ($id !== '') {
        $l = db_one('SELECT l.* FROM payment_link l JOIN client c ON c.id = l.client WHERE l.id = ? AND c.tenant = ?', [$id, $tenant['id']]);
        if (!$l) throw new ApiError('הקישור לא נמצא', 'not_found', 404);
        db_run('UPDATE payment_link SET title = ?, description = ?, product = ?, quantity = ?, amount_mode = ?, min_amount = ?, max_amount = ?, fields = ?, success_url = ?, max_uses = ?, expires_at = ?, active = ? WHERE id = ?', [...$row, $id]);
    } else {
        $id = new_id('lnk');
        $code = substr(strtr(base64_encode(random_bytes(9)), '+/', 'xy'), 0, 10);
        db_run('INSERT INTO payment_link (id, code, client, env, title, description, product, quantity, amount_mode, min_amount, max_amount, fields, success_url, max_uses, expires_at, active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $code, $c['id'], $env, ...$row, now()]);
    }
    audit('admin', $tenant['id'], 'link.saved', 'payment_link', $id, ['title' => $title, 'env' => $env], $tenant['id']);
    return link_public(db_one('SELECT * FROM payment_link WHERE id = ?', [$id]));
}

function link_public(array $l): array
{
    return [
        'id' => $l['id'], 'code' => $l['code'], 'client' => $l['client'], 'env' => $l['env'], 'title' => $l['title'], 'description' => $l['description'],
        'product' => $l['product'], 'quantity' => (int)$l['quantity'], 'amount_mode' => $l['amount_mode'], 'min_amount' => (int)$l['min_amount'], 'max_amount' => (int)$l['max_amount'],
        'fields' => json_decode($l['fields'], true) ?: [], 'success_url' => $l['success_url'], 'max_uses' => (int)$l['max_uses'], 'uses' => (int)$l['uses'],
        'expires_at' => $l['expires_at'] ? (int)$l['expires_at'] : null, 'active' => (bool)$l['active'],
        'url' => base_url() . 'pay/link.php?k=' . $l['code'],
    ];
}

/** הקישור כפי שהלקוח רואה אותו, או שגיאה למה הוא לא זמין. */
function link_open(string $code): array
{
    $l = preg_match('/^[A-Za-z0-9]{10}$/', $code) ? db_one('SELECT * FROM payment_link WHERE code = ?', [$code]) : null;
    if (!$l || !$l['active']) throw new ApiError('הקישור לא קיים או הופסק', 'not_found', 404);
    if ($l['expires_at'] && (int)$l['expires_at'] < now()) throw new ApiError('הקישור פג', 'expired', 410);
    if ((int)$l['max_uses'] > 0 && (int)$l['uses'] >= (int)$l['max_uses']) throw new ApiError('הקישור כבר נוצל', 'used_up', 410);
    $client = active_client($l['client'], $l['env']);
    return [$l, $client];
}

/** הלקוח מילא את הטופס → לקוח (לפי המייל/טלפון) → קנייה → כתובת דף התשלום. */
function link_checkout(string $code, array $form): string
{
    [$l, $client] = link_open($code);
    [, $pr] = current_price($client['id'], (string)$l['product']);
    $fields = json_decode($l['fields'], true) ?: [];
    $vals = [];
    foreach ($fields as $f) {
        $v = trim(mb_substr((string)($form[$f] ?? ''), 0, $f === 'note' ? 500 : 120));
        if ($v === '' && $f !== 'note' && $f !== 'tax_id') throw new ApiError('חסר: ' . LINK_FIELDS[$f], 'missing_field');
        $vals[$f] = $v;
    }
    if (isset($vals['email']) && $vals['email'] !== '' && !filter_var($vals['email'], FILTER_VALIDATE_EMAIL)) throw new ApiError('המייל לא תקין', 'bad_email');
    if (isset($vals['phone']) && $vals['phone'] !== '' && !preg_match('/^[0-9+\-\s]{7,20}$/', $vals['phone'])) throw new ApiError('הטלפון לא תקין', 'bad_phone');
    $unit = (int)$pr['amount'];
    if ($l['amount_mode'] === 'custom') {
        $unit = (int)round((float)str_replace(',', '.', (string)($form['amount'] ?? '0')) * 100);
        if ($unit < (int)$l['min_amount'] || $unit > (int)$l['max_amount']) throw new ApiError('הסכום בין ' . money_text((int)$l['min_amount']) . ' ל-' . money_text((int)$l['max_amount']), 'bad_amount');
    }
    $key = mb_strtolower($vals['email'] ?? '') ?: preg_replace('/\D/', '', $vals['phone'] ?? '');
    $ext = 'link:' . ($key !== '' ? substr(hash('sha256', $key), 0, 24) : substr(new_id('x'), 2));
    customer_upsert($client, $l['env'], ['external_id' => $ext, 'name' => $vals['name'] ?? '', 'email' => $vals['email'] ?? '', 'tax_id' => $vals['tax_id'] ?? '',
        'customer_type' => ($vals['tax_id'] ?? '') !== '' ? 'business' : 'private']);
    if (($vals['phone'] ?? '') !== '') db_run('UPDATE account SET phone = ? WHERE id = (SELECT account FROM customer WHERE client = ? AND env = ? AND external_id = ?)', [$vals['phone'], $client['id'], $l['env'], $ext]);
    $back = $l['success_url'] !== '' ? $l['success_url'] : base_url() . 'pay/link.php?k=' . $l['code'] . '&done=1';
    $c = checkout_create($client, $l['env'], ['external_id' => $ext, 'mode' => 'payment', 'items' => [['product' => $l['product'], 'quantity' => (int)$l['quantity']]],
        'return_url' => $back, 'cancel_url' => base_url() . 'pay/link.php?k=' . $l['code']],
        ['unit_amount' => $unit, 'link' => $l['code'], 'form' => $vals]);
    return $c['url'];
}

// ---------- כללים (פרק 12.4) ----------

const RULE_METHODS = ['card', 'bit', 'apple', 'google', 'paypal'];

function rule_save(array $tenant, array $in): array
{
    $name = trim(mb_substr((string)($in['name'] ?? ''), 0, 120));
    if ($name === '') throw new ApiError('שם לכלל', 'bad_name');
    $scope = (string)($in['scope_client'] ?? '');
    if ($scope !== '') client_get($tenant, $scope);
    $cond = is_array($in['cond'] ?? null) ? $in['cond'] : [];
    $act = is_array($in['act'] ?? null) ? $in['act'] : [];
    $cleanCond = array_filter([
        'modes' => array_values(array_intersect((array)($cond['modes'] ?? []), CHECKOUT_MODES)),
        'min_amount' => isset($cond['min_amount']) && $cond['min_amount'] !== '' ? max(0, (int)$cond['min_amount']) : null,
        'max_amount' => isset($cond['max_amount']) && $cond['max_amount'] !== '' ? max(0, (int)$cond['max_amount']) : null,
        'customer_type' => in_array($cond['customer_type'] ?? '', ['private', 'business'], true) ? $cond['customer_type'] : null,
        'products' => array_values(array_filter(array_map('strval', (array)($cond['products'] ?? [])))),
        'env' => in_array($cond['env'] ?? '', ['test', 'live'], true) ? $cond['env'] : null,
    ], fn($v) => $v !== null && $v !== []);
    $cleanAct = array_filter([
        'hide_methods' => array_values(array_intersect((array)($act['hide_methods'] ?? []), RULE_METHODS)),
        'only_methods' => array_values(array_intersect((array)($act['only_methods'] ?? []), RULE_METHODS)),
        'block' => trim(mb_substr((string)($act['block'] ?? ''), 0, 200)) ?: null,
    ], fn($v) => $v !== null && $v !== []);
    if (!$cleanAct) throw new ApiError('לכלל צריך פעולה (הסתרת אמצעי, רק אמצעים מסוימים, או חסימה)', 'no_action');
    $prio = max(1, min(1000, (int)($in['priority'] ?? 100)));
    $active = (int)(bool)($in['active'] ?? true);
    $id = (string)($in['id'] ?? '');
    if ($id !== '') {
        $r = db_one('SELECT * FROM rule WHERE id = ? AND tenant = ?', [$id, $tenant['id']]);
        if (!$r) throw new ApiError('הכלל לא נמצא', 'not_found', 404);
        db_run('UPDATE rule SET name = ?, scope_client = ?, priority = ?, cond = ?, act = ?, active = ?, version = version + 1, updated_at = ? WHERE id = ?',
            [$name, $scope ?: null, $prio, json_encode((object)$cleanCond), json_encode((object)$cleanAct, JSON_UNESCAPED_UNICODE), $active, now(), $id]);
        audit('admin', $tenant['id'], 'rule.updated', 'rule', $id, ['before' => ['cond' => json_obj($r['cond']), 'act' => json_obj($r['act'])], 'after' => ['cond' => $cleanCond, 'act' => $cleanAct]], $tenant['id']);
    } else {
        $id = new_id('rule');
        db_run('INSERT INTO rule (id, tenant, name, scope_client, priority, cond, act, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $tenant['id'], $name, $scope ?: null, $prio, json_encode((object)$cleanCond), json_encode((object)$cleanAct, JSON_UNESCAPED_UNICODE), $active, now(), now()]);
        audit('admin', $tenant['id'], 'rule.created', 'rule', $id, ['cond' => $cleanCond, 'act' => $cleanAct], $tenant['id']);
    }
    return db_one('SELECT * FROM rule WHERE id = ?', [$id]);
}

/** ‏$ctx: ‏{client, env, mode, amount, customer_type, products[]} → ‏{matched[], hide[], only|null, block|null} */
function rules_eval(string $tenantId, array $ctx): array
{
    $out = ['matched' => [], 'hide' => [], 'only' => null, 'block' => null];
    foreach (db_all('SELECT * FROM rule WHERE tenant = ? AND active = 1 ORDER BY priority, created_at', [$tenantId]) as $r) {
        if ($r['scope_client'] && $r['scope_client'] !== $ctx['client']) continue;
        $c = json_obj($r['cond']);
        if (isset($c['modes']) && !in_array($ctx['mode'], $c['modes'], true)) continue;
        if (isset($c['env']) && $c['env'] !== $ctx['env']) continue;
        if (isset($c['min_amount']) && $ctx['amount'] < $c['min_amount']) continue;
        if (isset($c['max_amount']) && $ctx['amount'] > $c['max_amount']) continue;
        if (isset($c['customer_type']) && $c['customer_type'] !== $ctx['customer_type']) continue;
        if (isset($c['products']) && !array_intersect($c['products'], $ctx['products'])) continue;
        $a = json_obj($r['act']);
        $out['matched'][] = ['id' => $r['id'], 'name' => $r['name']];
        $out['hide'] = array_values(array_unique(array_merge($out['hide'], $a['hide_methods'] ?? [])));
        if (!empty($a['only_methods'])) $out['only'] = $out['only'] === null ? $a['only_methods'] : array_values(array_intersect($out['only'], $a['only_methods']));
        if (!empty($a['block']) && $out['block'] === null) $out['block'] = $a['block'];
    }
    return $out;
}

function checkout_rule_ctx(array $c): array
{
    $client = db_one('SELECT * FROM client WHERE id = ?', [$c['client']]);
    $acc = db_one('SELECT customer_type FROM account WHERE id = ?', [$c['account']]);
    return ['tenant' => $client['tenant'], 'client' => $client['id'], 'env' => $c['env'], 'mode' => $c['mode'], 'amount' => (int)$c['amount'],
        'customer_type' => $acc['customer_type'] ?? 'private', 'products' => array_column(json_decode($c['items'], true) ?: [], 'product')];
}
