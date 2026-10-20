<?php
/**
 * ההטמעה — platform/docs/embed.md: רישום עצמי של לקוח (פרק 1), חתימת Ed25519 על כל קריאה שרת-לשרת
 * (פרק 2), טוקן חד-פעמי ל-iframe וסשן Bearer בזיכרון (פרק 4).
 */

require_once __DIR__ . '/docs.php';

const SIG_WINDOW = 300;          // ±5 דקות
const NONCE_TTL = 600;           // nonce נזכר 10 דקות
const KEY_CACHE = 3600;          // המפתח של הלקוח — שעה במטמון
const KEY_REFETCH_MIN = 60;      // הורדה מחדש אחרי חתימה שנכשלה — לכל היותר פעם בדקה
const TOKEN_TTL = 60;
const SESSION_TTL = 8 * 3600;

/** מוריד את המפתחות של הלקוח. ניתן להחלפה בבדיקות (WP_KEY_FETCHER). */
function fetch_url(string $url): ?string
{
    if (isset($GLOBALS['WP_KEY_FETCHER'])) return ($GLOBALS['WP_KEY_FETCHER'])($url);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_PROTOCOLS => getenv('WP_ALLOW_HTTP') === '1' ? CURLPROTO_HTTPS | CURLPROTO_HTTP : CURLPROTO_HTTPS, CURLOPT_MAXFILESIZE => 65536,
        // השרת מציג לפעמים אתגר JS ללקוח שאינו דפדפן; העוגייה הזו עוברת אותו (recipes-heartbeat.yml)
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Cookie: humans_21909=1'],
        CURLOPT_USERAGENT => 'word-processor/1 (+embed key fetch)',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ($body !== false && $code === 200 && strlen($body) <= 65536) ? $body : null;
}

/** {"client":"…","keys":[{"kid","alg":"Ed25519","key":"<base64>"}]} → [kid => מפתח בינארי]. */
function fetch_client_keys(string $client, string $keyUrl): ?array
{
    $raw = fetch_url($keyUrl);
    $j = $raw !== null ? json_decode($raw, true) : null;
    if (!is_array($j) || ($j['client'] ?? null) !== $client || !is_array($j['keys'] ?? null)) return null;
    $keys = [];
    foreach ($j['keys'] as $k) {
        if (!is_array($k) || ($k['alg'] ?? '') !== 'Ed25519' || !is_string($k['kid'] ?? null) || !is_string($k['key'] ?? null)) continue;
        $bin = base64_decode(strtr($k['key'], '-_', '+/'), true);
        if ($bin !== false && strlen($bin) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && preg_match('/^[\w.\-]{1,40}$/', $k['kid'])) {
            $keys[$k['kid']] = base64_encode($bin);
        }
    }
    return $keys ?: null;
}

/** המקור של העורך עצמו (https://mbe-plus.com). */
function own_origin(): string
{
    return origin_of(base_url());
}

/**
 * client_register: {client, name, key_url, origins}.
 * אותו מקור כמו העורך, ו-key_url בתיקייה של הלקוח עצמו (/mini_projects/<client>/…) → active. אחרת → pending.
 * שינוי של key_url או origins בלקוח קיים → שוב לפי אותו כלל; אף פעם לא דריסה שקטה.
 */
function client_register(array $in): array
{
    $client = $in['client'] ?? '';
    if (!is_string($client) || !preg_match('/^[a-z0-9][a-z0-9\-]{1,59}$/', $client)) throw new ApiError('מזהה לקוח לא תקין (אותיות לטיניות קטנות, ספרות ומקף)', 'bad_client');
    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 120);
    $keyUrl = (string)($in['key_url'] ?? '');
    $origins = $in['origins'] ?? null;
    if (!is_array($origins) || !$origins || count($origins) > 10) throw new ApiError('origins — רשימה של 1–10 מקורות', 'bad_origins');
    $clean = [];
    foreach ($origins as $o) {
        // http — רק בבדיקה מקומית (WP_ALLOW_HTTP, לא מוגדר בשרת)
        $https = str_starts_with((string)$o, 'https://') || (getenv('WP_ALLOW_HTTP') === '1' && str_starts_with((string)$o, 'http://'));
        if (!is_string($o) || origin_of($o) === '' || origin_of($o) !== rtrim(strtolower($o), '/') || !$https) {
            throw new ApiError('מקור לא תקין: ' . (is_string($o) ? $o : '?') . ' (https://host בלבד, בלי נתיב)', 'bad_origins');
        }
        $clean[] = origin_of($o);
    }
    $clean = array_values(array_unique($clean));
    sort($clean);
    if (!(str_starts_with($keyUrl, 'https://') || (getenv('WP_ALLOW_HTTP') === '1' && str_starts_with($keyUrl, 'http://'))) || strlen($keyUrl) > 500 || !in_array(origin_of($keyUrl), $clean, true)) {
        throw new ApiError('key_url חייב להיות https ותחת אחד ה-origins', 'bad_key_url');
    }
    $keys = fetch_client_keys($client, $keyUrl);
    if (!$keys) throw new ApiError('לא הצלחתי להוריד מפתח תקין מ-key_url (צריך {"client":"' . $client . '","keys":[…]})', 'key_fetch_failed', 422);

    $trusted = trusted_key_url($client, $keyUrl);
    $now = time();
    $st = db()->prepare('SELECT * FROM clients WHERE id = ?');
    $st->execute([$client]);
    $old = $st->fetch();
    $originsJson = json_encode($clean);
    if ($old) {
        $changed = $old['key_url'] !== $keyUrl || $old['origins'] !== $originsJson;
        if (!$changed) {
            db()->prepare('UPDATE clients SET name = ?, keys = ?, keys_at = ?, updated_at = ? WHERE id = ?')
                ->execute([$name ?: $old['name'], json_encode($keys), $now, $now, $client]);
            return ['status' => $old['status'] === 'disabled' ? 'pending' : $old['status']];
        }
        $status = $trusted ? 'active' : 'pending';
        db()->prepare('UPDATE clients SET name = ?, key_url = ?, origins = ?, status = ?, keys = ?, keys_at = ?, updated_at = ? WHERE id = ?')
            ->execute([$name ?: $old['name'], $keyUrl, $originsJson, $status, json_encode($keys), $now, $now, $client]);
        wp_log('client_changed', "$client → $status ($keyUrl)");
        return ['status' => $status];
    }
    $status = $trusted ? 'active' : 'pending';
    db()->prepare('INSERT INTO clients (id, name, key_url, origins, status, keys, keys_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$client, $name, $keyUrl, $originsJson, $status, json_encode($keys), $now, $now, $now]);
    wp_log('client_registered', "$client → $status ($keyUrl)");
    return ['status' => $status];
}

/**
 * מפתח שרק הלקוח עצמו שולט בו: אותו מקור כמו העורך, ובתיקייה ששמה כמזהה הלקוח, לצד העורך
 * (…/mini_projects/<client>/…). כך אפליקציה אחרת באותו דומיין לא יכולה להירשם בשם אחרת.
 */
function trusted_key_url(string $client, string $keyUrl): bool
{
    if (origin_of($keyUrl) !== own_origin()) return false;
    $parent = rtrim(dirname(rtrim(parse_url(base_url(), PHP_URL_PATH) ?: '/', '/')), '/');
    $path = parse_url($keyUrl, PHP_URL_PATH) ?: '';
    return str_starts_with($path, $parent . '/' . $client . '/');
}

function client_get(string $id): ?array
{
    $st = db()->prepare('SELECT * FROM clients WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * בודק קריאה חתומה (פרק 2). מחזיר את הלקוח. זורק 401 עם code:
 * bad_signature | stale | replay | unknown_client | pending.
 */
function verify_signed(string $action, string $rawBody, array $headers, ?int $now = null): array
{
    $now ??= time();
    $h = array_change_key_case($headers, CASE_LOWER);
    $clientId = (string)($h['x-embed-client'] ?? '');
    $time = (string)($h['x-embed-time'] ?? '');
    $nonce = (string)($h['x-embed-nonce'] ?? '');
    $sigH = (string)($h['x-embed-signature'] ?? '');
    $fail = fn($code, $msg) => new ApiError($msg, $code, 401);

    $client = $clientId !== '' ? client_get($clientId) : null;
    if (!$client) throw $fail('unknown_client', 'לקוח לא רשום');
    if ($client['status'] !== 'active') throw $fail('pending', 'הלקוח ממתין לאישור המנהל של העורך');
    if (!ctype_digit($time) || abs($now - (int)$time) > SIG_WINDOW) throw $fail('stale', 'הזמן בבקשה רחוק מדי מהשעון של העורך');
    if (!preg_match('/^[0-9a-f]{32}$/', $nonce)) throw $fail('bad_signature', 'nonce לא תקין');
    [$kid, $sigB64] = array_pad(explode('.', $sigH, 2), 2, '');
    $sig = base64_decode($sigB64, true);
    if ($kid === '' || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) throw $fail('bad_signature', 'חתימה לא תקינה');

    $msg = "$action\n$time\n$nonce\n" . hash('sha256', $rawBody);
    $ok = sig_check($client, $kid, $sig, $msg);
    if (!$ok && $now - (int)$client['refetch_at'] >= KEY_REFETCH_MIN) {
        // אולי הלקוח החליף מפתח — הורדה אחת מחדש, ואז דחייה
        db()->prepare('UPDATE clients SET refetch_at = ? WHERE id = ?')->execute([$now, $client['id']]);
        $keys = fetch_client_keys($client['id'], $client['key_url']);
        if ($keys) {
            db()->prepare('UPDATE clients SET keys = ?, keys_at = ? WHERE id = ?')->execute([json_encode($keys), $now, $client['id']]);
            $client = client_get($client['id']);
            $ok = sig_check($client, $kid, $sig, $msg);
        }
    }
    if (!$ok) throw $fail('bad_signature', 'החתימה אינה תואמת את המפתח של הלקוח');

    // nonce — רק אחרי שהחתימה תקינה, כדי שזר לא ימלא את הטבלה
    db()->prepare('DELETE FROM nonces WHERE t < ?')->execute([$now - NONCE_TTL]);
    $st = db()->prepare('INSERT OR IGNORE INTO nonces (client, nonce, t) VALUES (?, ?, ?)');
    $st->execute([$client['id'], $nonce, $now]);
    if ($st->rowCount() !== 1) throw $fail('replay', 'הבקשה כבר התקבלה (nonce חוזר)');

    // מטמון המפתח פג — מרעננים ברקע הבקשה הזו (לא מפיל אם נכשל)
    if ($now - (int)$client['keys_at'] > KEY_CACHE) {
        $keys = fetch_client_keys($client['id'], $client['key_url']);
        if ($keys) db()->prepare('UPDATE clients SET keys = ?, keys_at = ? WHERE id = ?')->execute([json_encode($keys), $now, $client['id']]);
    }
    return $client;
}

function sig_check(array $client, string $kid, string $sig, string $msg): bool
{
    $keys = json_decode($client['keys'] ?? '', true) ?: [];
    if (!isset($keys[$kid])) return false;
    $pk = base64_decode($keys[$kid]);
    try {
        return sodium_crypto_sign_verify_detached($sig, $msg, $pk);
    } catch (Throwable $e) {
        return false;
    }
}

function client_origins(array $client): array
{
    return json_decode($client['origins'], true) ?: [];
}

// ---------- טוקן וסשן ----------

/**
 * embed_session (פרק 3): {doc, user:{id,name}, can, mode, page?, return_origin} → {embed_url, expires_in, doc}.
 * doc ריק — מסמך חדש במרחב של הלקוח.
 */
function embed_session(array $client, array $in): array
{
    $space = 'c:' . $client['id'];
    $user = $in['user'] ?? null;
    $uid = is_array($user) ? (string)($user['id'] ?? '') : '';
    $uname = is_array($user) ? mb_substr(trim((string)($user['name'] ?? '')), 0, 120) : '';
    if ($uid === '' || strlen($uid) > 120) throw new ApiError('user.id חסר', 'bad_user');
    if ($uname === '') $uname = $uid;
    $can = $in['can'] ?? 'edit';
    if (!in_array($can, ['edit', 'view'], true)) throw new ApiError('can — edit או view', 'bad_can');
    $mode = $in['mode'] ?? 'document';
    if (!in_array($mode, ['document', 'section'], true)) throw new ApiError('mode — document או section', 'bad_mode');
    $ret = (string)($in['return_origin'] ?? '');
    if (!in_array(origin_of($ret), client_origins($client), true) || origin_of($ret) !== rtrim($ret, '/')) {
        throw new ApiError('return_origin אינו אחד המקורות הרשומים של הלקוח', 'bad_origin');
    }
    $ret = origin_of($ret);

    $docId = $in['doc'] ?? '';
    if ($docId === '' || $docId === null) {
        $docId = doc_new($space, (string)($in['title'] ?? ''), null, $in['page'] ?? null, $uid, $uname);
    } else {
        $row = doc_find($docId, $space);
        if ($mode === 'section' && is_array($in['page'] ?? null)) {
            $merged = page_normalize($in['page'], doc_page($row));
            if ($merged != doc_page($row)) doc_set_page($row, $merged);
        }
    }

    $token = bin2hex(random_bytes(24));
    db()->prepare('DELETE FROM embed_tokens WHERE expires < ?')->execute([time() - 3600]);
    db()->prepare('INSERT INTO embed_tokens (hash, client, doc, user_id, user_name, can, mode, return_origin, expires) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([hash('sha256', $token), $client['id'], $docId, $uid, $uname, $can, $mode, $ret, time() + TOKEN_TTL]);
    return ['embed_url' => base_url() . 'embed.php?t=' . $token, 'expires_in' => TOKEN_TTL, 'doc' => $docId];
}

/** הטוקן בלי לצרוך אותו — embed.php צריך את הלקוח כדי לשלוח frame-ancestors. */
function token_peek(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{48}$/', $token)) return null;
    $st = db()->prepare('SELECT * FROM embed_tokens WHERE hash = ?');
    $st->execute([hash('sha256', $token)]);
    $t = $st->fetch();
    return $t && !$t['used'] && (int)$t['expires'] >= time() ? $t : null;
}

/** embed_open: טוקן (חד-פעמי) → סשן. */
function token_consume(string $token): array
{
    if (!preg_match('/^[0-9a-f]{48}$/', $token)) throw new ApiError('הקישור לא תקין', 'expired', 401);
    $h = hash('sha256', $token);
    $st = db()->prepare('UPDATE embed_tokens SET used = 1 WHERE hash = ? AND used = 0 AND expires >= ?');
    $st->execute([$h, time()]);
    if ($st->rowCount() !== 1) throw new ApiError('הקישור פג או כבר נוצל — פתחו את העורך מחדש', 'expired', 401);
    $q = db()->prepare('SELECT * FROM embed_tokens WHERE hash = ?');
    $q->execute([$h]);
    $t = $q->fetch();
    $client = client_get($t['client']);
    if (!$client || $client['status'] !== 'active') throw new ApiError('הלקוח אינו פעיל', 'pending', 401);

    $session = bin2hex(random_bytes(32));
    db()->prepare('DELETE FROM embed_sessions WHERE expires < ?')->execute([time()]);
    db()->prepare('INSERT INTO embed_sessions (hash, client, doc, user_id, user_name, can, mode, return_origin, expires) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([hash('sha256', $session), $t['client'], $t['doc'], $t['user_id'], $t['user_name'], $t['can'], $t['mode'], $t['return_origin'], time() + SESSION_TTL]);
    return ['session' => $session] + embed_session_row($session);
}

function embed_session_row(string $session): array
{
    $st = db()->prepare('SELECT * FROM embed_sessions WHERE hash = ?');
    $st->execute([hash('sha256', $session)]);
    return $st->fetch() ?: [];
}

/** הסשן מכותרת Authorization: Bearer (או X-Embed-Session — יש שרתים שמורידים את Authorization). מתחדש בכל פעולה. */
function embed_auth(): array
{
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    $tok = preg_match('/^Bearer\s+([0-9a-f]{64})$/i', $hdr, $m) ? $m[1] : ($_SERVER['HTTP_X_EMBED_SESSION'] ?? '');
    if (!preg_match('/^[0-9a-f]{64}$/', $tok)) throw new ApiError('אין סשן עריכה', 'expired', 401);
    $s = embed_session_row($tok);
    if (!$s || (int)$s['expires'] < time()) throw new ApiError('הסשן פג — פתחו את העורך מחדש', 'expired', 401);
    $client = client_get($s['client']);
    if (!$client || $client['status'] !== 'active') throw new ApiError('הלקוח אינו פעיל', 'pending', 401);
    db()->prepare('UPDATE embed_sessions SET expires = ? WHERE hash = ?')->execute([time() + SESSION_TTL, $s['hash']]);
    $s['token'] = $tok;
    return $s;
}

// ---------- המפתח של העורך עצמו (לבדיקה החיה: העורך כלקוח של עצמו) ----------

function own_keypair(): array
{
    $kp = setting('own_keypair', fn() => base64_encode(sodium_crypto_sign_keypair()));
    $kp = base64_decode($kp);
    return ['kid' => 'wp1', 'secret' => sodium_crypto_sign_secretkey($kp), 'public' => sodium_crypto_sign_publickey($kp)];
}

/** כותרות חתומות — כמו שהמארחת שולחת (embed.md, פרק 2). */
function sign_headers(string $client, string $kid, string $secret, string $action, string $body, ?int $time = null, ?string $nonce = null): array
{
    $time = (string)($time ?? time());
    $nonce ??= bin2hex(random_bytes(16));
    $sig = sodium_crypto_sign_detached("$action\n$time\n$nonce\n" . hash('sha256', $body), $secret);
    return ['X-Embed-Client' => $client, 'X-Embed-Time' => $time, 'X-Embed-Nonce' => $nonce,
        'X-Embed-Signature' => $kid . '.' . base64_encode($sig)];
}
