<?php
/**
 * מנויים, עוסקים, מערכות מחוברות וקודי גישה — ואימות של כל בקשה חתומה (פרקים 4.2, 12.2–12.3).
 *
 * שתי דרכים לחתום (אותה מחרוזת: action \n time \n nonce \n sha256hex(body)):
 *  - קוד סודי:  X-Pay-Key: <key_id>, X-Pay-Time, X-Pay-Nonce, X-Pay-Signature: hex(HMAC-SHA256(secret, msg))
 *  - Ed25519:   X-Embed-Client: <client>[~test], X-Embed-Time, X-Embed-Nonce, X-Embed-Signature: <kid>.<base64>
 *    (בדיוק platform/docs/embed.md §2 — שלוש אפליקציות כבר חותמות כך)
 */

declare(strict_types=1);

require_once __DIR__ . '/util.php';

const SIG_WINDOW = 300;
const NONCE_TTL = 600;
const ED_KEY_CACHE = 3600;
const KEY_OVERLAP = 7 * 86400;

// ---------- מנויים ----------

/**
 * המשתמש שנכנס לממשק הניהול, מהשער (PLATFORM_USER). הראשון שנכנס — הבעלים (owner, פעיל).
 * כל אחד אחר — מנוי "ממתין" עד שהבעלים מאשר (פרק 12.2). התוסף לא קורא את מסד הפלטפורמה.
 */
function current_tenant(): ?array
{
    $raw = $_SERVER['PLATFORM_USER'] ?? (getenv('PAY_DEV_USER') ?: '');
    $id = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($id) || empty($id['sub'])) return null;
    $sub = (string)$id['sub'];
    $row = db_one('SELECT * FROM tenant WHERE platform_sub = ?', [$sub]);
    if (!$row) {
        $row = db_tx(function () use ($sub, $id) {
            $hasOwner = (bool)db_one("SELECT 1 FROM tenant WHERE role = 'owner'");
            $t = [
                'id' => new_id('ten'), 'platform_sub' => $sub,
                'name' => mb_substr((string)($id['name'] ?? ''), 0, 120), 'email' => (string)($id['email'] ?? ''),
                'role' => $hasOwner ? 'tenant' : 'owner', 'status' => $hasOwner ? 'pending' : 'active',
            ];
            db_run('INSERT INTO tenant (id, platform_sub, name, email, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$t['id'], $sub, $t['name'], $t['email'], $t['role'], $t['status'], now()]);
            audit('admin', $t['id'], 'tenant.created', 'tenant', $t['id'], ['role' => $t['role']], $t['id']);
            return db_one('SELECT * FROM tenant WHERE id = ?', [$t['id']]);
        });
    }
    db_run('UPDATE tenant SET last_seen = ? WHERE id = ?', [now(), $row['id']]);
    return $row;
}

// ---------- עוסקים ----------

const TAX_STATUSES = ['exempt', 'licensed', 'company'];

function merchant_save(array $tenant, array $in): array
{
    $name = trim(mb_substr((string)($in['name'] ?? ''), 0, 120));
    if ($name === '') throw new ApiError('שם העוסק חסר', 'bad_name');
    $tax = (string)($in['tax_status'] ?? 'exempt');
    if (!in_array($tax, TAX_STATUSES, true)) throw new ApiError('סוג עוסק: פטור, מורשה או חברה', 'bad_tax_status');
    $vat = $tax === 'exempt' ? 0 : (int)($in['vat_rate'] ?? 1800);
    if ($vat < 0 || $vat > 5000) throw new ApiError('שיעור מע"מ לא תקין', 'bad_vat');
    $taxId = preg_replace('/\D/', '', (string)($in['tax_id'] ?? ''));
    $fields = [$name, $taxId, $tax, $vat, mb_substr((string)($in['email'] ?? ''), 0, 160), mb_substr((string)($in['address'] ?? ''), 0, 240)];
    $id = (string)($in['id'] ?? '');
    if ($id !== '') {
        merchant_get($tenant, $id);
        db_run('UPDATE merchant SET name = ?, tax_id = ?, tax_status = ?, vat_rate = ?, email = ?, address = ? WHERE id = ?', [...$fields, $id]);
        audit('admin', $tenant['id'], 'merchant.updated', 'merchant', $id, ['tax_status' => $tax], $tenant['id']);
    } else {
        $id = new_id('mer');
        db_run('INSERT INTO merchant (id, tenant, name, tax_id, tax_status, vat_rate, email, address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $tenant['id'], ...$fields, now()]);
        audit('admin', $tenant['id'], 'merchant.created', 'merchant', $id, ['tax_status' => $tax], $tenant['id']);
    }
    return merchant_get($tenant, $id);
}

function merchant_get(array $tenant, string $id): array
{
    $m = db_one('SELECT * FROM merchant WHERE id = ? AND tenant = ?', [$id, $tenant['id']]);
    if (!$m) throw new ApiError('העוסק לא נמצא', 'not_found', 404);
    return $m;
}

// ---------- מערכות מחוברות ----------

const CLIENT_KINDS = ['internal', 'server', 'static', 'mobile'];

function client_create(array $tenant, array $in): array
{
    $id = strtolower(trim((string)($in['id'] ?? '')));
    if (!preg_match('/^[a-z0-9][a-z0-9\-]{1,59}$/', $id)) throw new ApiError('מזהה המערכת: אותיות לטיניות קטנות, ספרות ומקף', 'bad_id');
    if (db_one('SELECT 1 FROM client WHERE id = ?', [$id])) throw new ApiError('המזהה תפוס', 'taken', 409);
    $name = trim(mb_substr((string)($in['name'] ?? ''), 0, 120)) ?: $id;
    $kind = (string)($in['kind'] ?? 'server');
    if (!in_array($kind, CLIENT_KINDS, true)) throw new ApiError('סוג מערכת לא מוכר', 'bad_kind');
    $merchant = (string)($in['merchant'] ?? '');
    if ($merchant !== '') merchant_get($tenant, $merchant);
    db_run('INSERT INTO client (id, tenant, merchant, name, kind, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$id, $tenant['id'], $merchant ?: null, $name, $kind, 'active', now()]);
    audit('admin', $tenant['id'], 'client.created', 'client', $id, ['kind' => $kind], $tenant['id']);
    return client_get($tenant, $id);
}

function client_update(array $tenant, string $id, array $in): array
{
    $c = client_get($tenant, $id);
    $name = array_key_exists('name', $in) ? (trim(mb_substr((string)$in['name'], 0, 120)) ?: $c['name']) : $c['name'];
    $merchant = array_key_exists('merchant', $in) ? (string)$in['merchant'] : (string)$c['merchant'];
    if ($merchant !== '') merchant_get($tenant, $merchant);
    $webhook = array_key_exists('webhook_url', $in) ? trim((string)$in['webhook_url']) : $c['webhook_url'];
    if ($webhook !== '' && !valid_url($webhook)) throw new ApiError('כתובת webhook — https מלאה', 'bad_url');
    $keyUrl = array_key_exists('key_url', $in) ? trim((string)$in['key_url']) : $c['key_url'];
    if ($keyUrl !== '' && !valid_url($keyUrl)) throw new ApiError('כתובת המפתח — https מלאה', 'bad_url');
    $origins = array_key_exists('origins', $in) ? clean_origins($in['origins']) : json_obj($c['origins']);
    $status = array_key_exists('status', $in) ? (string)$in['status'] : $c['status'];
    if (!in_array($status, ['active', 'disabled'], true)) throw new ApiError('מצב לא תקין', 'bad_status');
    $liveOk = array_key_exists('live_ok', $in) ? (int)(bool)$in['live_ok'] : (int)$c['live_ok'];
    if ($liveOk && $tenant['role'] !== 'owner') $liveOk = (int)$c['live_ok']; // ‏live — רק הבעלים מאשר
    $keysReset = $keyUrl !== $c['key_url'];
    db_run('UPDATE client SET name = ?, merchant = ?, webhook_url = ?, key_url = ?, origins = ?, status = ?, live_ok = ?'
        . ($keysReset ? ', ed_keys = NULL, ed_keys_at = 0' : '') . ' WHERE id = ?',
        [$name, $merchant ?: null, $webhook, $keyUrl, json_encode($origins), $status, $liveOk, $id]);
    audit('admin', $tenant['id'], 'client.updated', 'client', $id, ['status' => $status, 'live_ok' => $liveOk, 'key_url_changed' => $keysReset], $tenant['id']);
    return client_get($tenant, $id);
}

function client_get(array $tenant, string $id): array
{
    $c = db_one('SELECT * FROM client WHERE id = ? AND tenant = ?', [$id, $tenant['id']]);
    if (!$c) throw new ApiError('המערכת לא נמצאה', 'not_found', 404);
    return $c;
}

function valid_url(string $u): bool
{
    $ok = str_starts_with($u, 'https://') || (getenv('PAY_ALLOW_HTTP') === '1' && str_starts_with($u, 'http://'));
    return $ok && filter_var($u, FILTER_VALIDATE_URL) !== false && strlen($u) <= 500;
}

function origin_of(string $url): string
{
    $p = parse_url($url);
    if (!$p || empty($p['scheme']) || empty($p['host'])) return '';
    return strtolower($p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : ''));
}

function clean_origins(mixed $origins): array
{
    if (is_string($origins)) $origins = preg_split('/[\s,]+/', $origins, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($origins) || count($origins) > 20) throw new ApiError('אתרים מותרים — עד 20', 'bad_origins');
    $out = [];
    foreach ($origins as $o) {
        $o = origin_of((string)$o);
        if ($o === '' || !valid_url($o)) throw new ApiError('אתר לא תקין (https://host)', 'bad_origins');
        $out[] = $o;
    }
    return array_values(array_unique($out));
}

// ---------- קודי גישה ----------

/**
 * יוצר קוד. מוחזר פעם אחת — אחר כך רק 4 תווים אחרונים.
 * sk / whsec: ‏"<key_id>.<secret>" — ה-key_id גלוי בכותרת, הסוד רק לחתימה. pk: המזהה הוא הקוד כולו.
 * החלפה: הקודם מאותו סוג וסביבה ממשיך לעבוד KEY_OVERLAP שניות (חפיפה), אלא אם revoke_old.
 */
function key_create(array $tenant, string $clientId, string $env, string $kind, bool $revokeOld = false): array
{
    $c = client_get($tenant, $clientId);
    if (!in_array($env, ['test', 'live'], true)) throw new ApiError('סביבה: test או live', 'bad_env');
    if (!in_array($kind, ['sk', 'pk', 'whsec'], true)) throw new ApiError('סוג קוד לא מוכר', 'bad_kind');
    return db_tx(function () use ($tenant, $c, $env, $kind, $revokeOld) {
        $old = db_all("SELECT id FROM api_key WHERE client = ? AND env = ? AND kind = ? AND status = 'active' AND expires_at IS NULL", [$c['id'], $env, $kind]);
        foreach ($old as $o) {
            if ($revokeOld) db_run("UPDATE api_key SET status = 'revoked' WHERE id = ?", [$o['id']]);
            else db_run('UPDATE api_key SET expires_at = ? WHERE id = ?', [now() + KEY_OVERLAP, $o['id']]);
        }
        $keyId = $kind . '_' . $env . '_' . substr(new_id('x'), 2);
        $secret = $kind === 'pk' ? '' : rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $full = $secret === '' ? $keyId : "$keyId.$secret";
        db_run('INSERT INTO api_key (id, client, env, kind, secret_enc, last4, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$keyId, $c['id'], $env, $kind, seal($secret), substr($full, -4), now()]);
        audit('admin', $tenant['id'], 'key.created', 'client', $c['id'], ['key' => $keyId, 'env' => $env, 'kind' => $kind, 'old' => count($old)], $tenant['id']);
        return ['id' => $keyId, 'key' => $full, 'kind' => $kind, 'env' => $env];
    });
}

function key_revoke(array $tenant, string $keyId): void
{
    $k = db_one('SELECT k.* FROM api_key k JOIN client c ON c.id = k.client WHERE k.id = ? AND c.tenant = ?', [$keyId, $tenant['id']]);
    if (!$k) throw new ApiError('הקוד לא נמצא', 'not_found', 404);
    db_run("UPDATE api_key SET status = 'revoked' WHERE id = ?", [$keyId]);
    audit('admin', $tenant['id'], 'key.revoked', 'client', $k['client'], ['key' => $keyId], $tenant['id']);
}

function keys_list(array $tenant, string $clientId): array
{
    client_get($tenant, $clientId);
    $rows = db_all('SELECT id, env, kind, last4, status, expires_at, last_used, created_at FROM api_key WHERE client = ? ORDER BY created_at DESC', [$clientId]);
    foreach ($rows as &$r) if ($r['status'] === 'active' && $r['expires_at'] && $r['expires_at'] < now()) $r['status'] = 'expired';
    return $rows;
}

/** הסוד של קוד פעיל (לחתימת webhooks ב-HMAC). */
function key_secret(string $clientId, string $env, string $kind): ?array
{
    $k = db_one("SELECT * FROM api_key WHERE client = ? AND env = ? AND kind = ? AND status = 'active' AND (expires_at IS NULL OR expires_at > ?) ORDER BY expires_at IS NULL DESC, created_at DESC LIMIT 1",
        [$clientId, $env, $kind, now()]);
    return $k ? ['id' => $k['id'], 'secret' => unseal($k['secret_enc'])] : null;
}

// ---------- אימות בקשה חתומה ----------

/**
 * @param array<string,string> $h כותרות באותיות גדולות, מקפים (X-PAY-KEY)
 * @return array{client:array, env:string, key:?string}
 */
function auth_request(string $action, string $body, array $h): array
{
    $fail = fn(string $code, string $msg) => new ApiError($msg, $code, 401);
    if (!empty($h['X-PAY-KEY'])) {
        $keyId = (string)$h['X-PAY-KEY'];
        $time = (string)($h['X-PAY-TIME'] ?? '');
        $nonce = (string)($h['X-PAY-NONCE'] ?? '');
        $sig = strtolower((string)($h['X-PAY-SIGNATURE'] ?? ''));
        $k = db_one("SELECT * FROM api_key WHERE id = ? AND kind = 'sk'", [$keyId]);
        if (!$k || $k['status'] !== 'active' || ($k['expires_at'] && $k['expires_at'] < now())) throw $fail('unknown_key', 'קוד הגישה לא מוכר או בוטל');
        $client = active_client($k['client'], $k['env']);
        $ips = json_obj($k['ips']);
        if ($ips && !in_array(client_ip(), $ips, true)) throw $fail('ip_not_allowed', 'הכתובת לא מורשית לקוד הזה');
        check_time_nonce($client['id'], $time, $nonce);
        $want = hash_hmac('sha256', sign_string($action, $time, $nonce, $body), unseal($k['secret_enc']));
        if (!hash_equals($want, $sig)) throw $fail('bad_signature', 'החתימה לא תקינה');
        db_run('UPDATE api_key SET last_used = ? WHERE id = ?', [now(), $keyId]);
        return ['client' => $client, 'env' => $k['env'], 'key' => $keyId];
    }
    if (!empty($h['X-EMBED-CLIENT'])) {
        [$cid, $env] = array_pad(explode('~', (string)$h['X-EMBED-CLIENT'], 2), 2, 'live');
        if (!in_array($env, ['test', 'live'], true)) throw $fail('unknown_client', 'סביבה לא מוכרת');
        $time = (string)($h['X-EMBED-TIME'] ?? '');
        $nonce = (string)($h['X-EMBED-NONCE'] ?? '');
        [$kid, $b64] = array_pad(explode('.', (string)($h['X-EMBED-SIGNATURE'] ?? ''), 2), 2, '');
        $client = active_client($cid, $env);
        if ($client['key_url'] === '') throw $fail('unknown_client', 'למערכת אין מפתח Ed25519 רשום');
        check_time_nonce($client['id'], $time, $nonce);
        $sig = base64_decode($b64, true);
        $msg = sign_string($action, $time, $nonce, $body);
        $keys = ed_keys($client, false);
        if (!$sig || !ed_verify($keys, $kid, $sig, $msg)) {
            $keys = ed_keys($client, true); // אולי הוחלף מפתח — הורדה אחת מחדש
            if (!$sig || !ed_verify($keys, $kid, $sig, $msg)) throw $fail('bad_signature', 'החתימה לא תקינה');
        }
        return ['client' => $client, 'env' => $env, 'key' => null];
    }
    throw $fail('no_auth', 'חסרה חתימה');
}

function sign_string(string $action, string $time, string $nonce, string $body): string
{
    return "$action\n$time\n$nonce\n" . hash('sha256', $body);
}

function active_client(string $id, string $env): array
{
    $c = db_one('SELECT c.*, t.status AS tenant_status FROM client c JOIN tenant t ON t.id = c.tenant WHERE c.id = ?', [$id]);
    if (!$c) throw new ApiError('מערכת לא מוכרת', 'unknown_client', 401);
    if ($c['tenant_status'] !== 'active') throw new ApiError('החשבון מושהה', 'tenant_suspended', 403);
    if ($c['status'] !== 'active') throw new ApiError('המערכת מושבתת', 'disabled', 403);
    if ($env === 'live' && !$c['live_ok']) throw new ApiError('סביבת live טרם אושרה למערכת הזו', 'live_not_approved', 403);
    return $c;
}

function check_time_nonce(string $clientId, string $time, string $nonce): void
{
    if (!ctype_digit($time) || abs(now() - (int)$time) > SIG_WINDOW) throw new ApiError('הזמן בבקשה רחוק מדי (±5 דקות)', 'stale', 401);
    if (!preg_match('/^[A-Za-z0-9_\-]{16,64}$/', $nonce)) throw new ApiError('nonce חסר', 'bad_nonce', 401);
    db_run('DELETE FROM nonce WHERE t < ?', [now() - NONCE_TTL]);
    if (db_run('INSERT OR IGNORE INTO nonce (client, nonce, t) VALUES (?, ?, ?)', [$clientId, $nonce, now()]) !== 1) {
        throw new ApiError('הבקשה כבר התקבלה (nonce חוזר)', 'replay', 401);
    }
}

function ed_verify(array $keys, string $kid, string $sig, string $msg): bool
{
    if (!isset($keys[$kid]) || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) return false;
    try {
        return sodium_crypto_sign_verify_detached($sig, $msg, base64_decode($keys[$kid]));
    } catch (Throwable) {
        return false;
    }
}

/** המפתחות הציבוריים של מערכת Ed25519 — במטמון שעה; המערכת מפרסמת אותם בעצמה (embed.md §1). */
function ed_keys(array $client, bool $refresh): array
{
    $cached = json_obj($client['ed_keys'] ?? '');
    if (!$refresh && $cached && now() - (int)$client['ed_keys_at'] < ED_KEY_CACHE) return $cached;
    $raw = fetch_url($client['key_url']);
    $j = $raw !== null ? json_decode($raw, true) : null;
    $keys = [];
    if (is_array($j) && is_array($j['keys'] ?? null)) {
        foreach ($j['keys'] as $k) {
            if (!is_array($k) || ($k['alg'] ?? '') !== 'Ed25519' || !is_string($k['kid'] ?? null) || !is_string($k['key'] ?? null)) continue;
            $bin = base64_decode(strtr($k['key'], '-_', '+/'), true);
            if ($bin !== false && strlen($bin) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && preg_match('/^[\w.\-]{1,40}$/', $k['kid'])) $keys[$k['kid']] = base64_encode($bin);
        }
    }
    if (!$keys) return $cached;
    db_run('UPDATE client SET ed_keys = ?, ed_keys_at = ? WHERE id = ?', [json_encode($keys), now(), $client['id']]);
    return $keys;
}

function fetch_url(string $url, ?string $post = null, array $headers = [], int $timeout = 5): ?string
{
    $res = http_request($url, $post, $headers, $timeout);
    return $res['status'] >= 200 && $res['status'] < 300 ? $res['body'] : null;
}

/** @return array{status:int, body:string} */
function http_request(string $url, ?string $post = null, array $headers = [], int $timeout = 5): array
{
    $hook = $GLOBALS['PAY_HTTP_HOOK'] ?? null; // בדיקות
    if (is_callable($hook)) return $hook($url, $post, $headers);
    if (!valid_url($url)) return ['status' => 0, 'body' => ''];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXFILESIZE => 1 << 20,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | (getenv('PAY_ALLOW_HTTP') === '1' ? CURLPROTO_HTTP : 0),
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $body === false ? 0 : $status, 'body' => $body === false ? '' : (string)$body];
}
