<?php
/**
 * Web Push — התראה לטלפון של המנהל על כל הרשמה חדשה, גם כשהדפדפן סגור.
 *
 * בלי ספריות: חתימת VAPID (ES256) והצפנת התוכן (RFC 8291, aes128gcm) נעשות
 * ב-openssl של PHP. הקוד הועתק מ-catering-menu/push.php (שם הוא עובד בשרת הזה)
 * ומותאם לפלטפורמה: המפתחות והמנויים במסד (platform/data, שכבר מוחרג מהפריסה),
 * וכל מנוי שייך למשתמש — רק מנהלים מקבלים התראות.
 *
 * הקידומת push_ בכל שם: הקובץ נטען רק מ-api.php, אבל שמות גלובליים כלליים
 * (b64u, pushPost…) כבר קיימים בכלים אחרים בריפו.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function push_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS push_vapid (
            id      INTEGER PRIMARY KEY CHECK (id = 1),
            private TEXT NOT NULL,
            public  TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS push_subs (
            endpoint   TEXT PRIMARY KEY,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            p256dh     TEXT NOT NULL,
            auth       TEXT NOT NULL,
            device     TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL,
            last_ok    TEXT,
            last_error TEXT
        );
        SQL);
}

function push_b64u(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function push_b64u_decode(string $s): string
{
    return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

/** נקודה ציבורית P-256 גולמית (65 בתים) מתוך פרטי מפתח של openssl. */
function push_raw_public(array $details): string
{
    return "\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)
                  . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
}

/** עוטף נקודה ציבורית גולמית ב-PEM, כדי ש-openssl יוכל להשתמש בה. */
function push_public_from_raw(string $raw)
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    return openssl_pkey_get_public($pem);
}

function push_new_key()
{
    return openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
}

/** מפתחות VAPID — נוצרים בפעם הראשונה ונשמרים במסד. */
function push_vapid(): array
{
    push_migrate();
    $row = db()->query('SELECT private, public FROM push_vapid WHERE id = 1')->fetch();
    if ($row) return $row;
    $key = push_new_key();
    openssl_pkey_export($key, $pem);
    $row = ['private' => $pem, 'public' => push_b64u(push_raw_public(openssl_pkey_get_details($key)))];
    db()->prepare('INSERT OR IGNORE INTO push_vapid (id, private, public) VALUES (1, ?, ?)')->execute([$row['private'], $row['public']]);
    return db()->query('SELECT private, public FROM push_vapid WHERE id = 1')->fetch(); // אם שתי בקשות יצרו במקביל — של הראשונה
}

/** חתימת DER של ECDSA → 64 בתים גולמיים (r||s), כפי ש-JWT דורש. */
function push_der_to_raw(string $der): string
{
    $pos = 2;
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$pos + 1]);
        $int = substr($der, $pos + 2, $len);
        $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
        $pos += 2 + $len;
    }
    return $out;
}

function push_vapid_header(string $endpoint, array $vapid, string $subject): string
{
    $parts = parse_url($endpoint);
    $aud = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $head = push_b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    // תוקף של שעה: אפל וגוגל דוחים אסימון ארוך מדי, ושעה מקובלת על שניהם
    $body = push_b64u(json_encode(['aud' => $aud, 'exp' => time() + 3600, 'sub' => $subject], JSON_UNESCAPED_SLASHES));
    openssl_sign("$head.$body", $sig, openssl_pkey_get_private($vapid['private']), OPENSSL_ALGO_SHA256);
    return 'vapid t=' . "$head.$body." . push_b64u(push_der_to_raw($sig)) . ', k=' . $vapid['public'];
}

/** הצפנת התוכן לפי RFC 8291 (aes128gcm) — רק הדפדפן של המנוי יכול לפענח. */
function push_encrypt(string $payload, string $p256dh, string $auth): string
{
    $uaPublic = push_b64u_decode($p256dh);
    $authSecret = push_b64u_decode($auth);

    $local = push_new_key();
    $asPublic = push_raw_public(openssl_pkey_get_details($local));
    $shared = openssl_pkey_derive(push_public_from_raw($uaPublic), $local, 32);

    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    return $salt . pack('N', 4096) . chr(strlen($asPublic)) . $asPublic . $cipher . $tag;
}

/** בקשה אחת לשירות ההתראות. מחזיר [קוד HTTP, גוף התשובה, שגיאת רשת]. */
function push_post(string $url, string $body, array $headers): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => $headers,
        ]);
        $resp = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return [$code, $resp, $err];
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body,
        'timeout' => 10, 'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m)) $code = (int)$m[1];
    return [$code, (string)$resp, $resp === false ? 'stream failed' : ''];
}

/**
 * שולח התראה למכשירים של המשתמשים שנבחרו (ברירת מחדל: כל המנהלים הפעילים).
 * מנוי שהדפדפן ביטל (404/410) נמחק. מחזיר [נשלחו, נכשלו].
 */
function push_send(array $message, ?array $userIds = null): array
{
    push_migrate();
    if ($userIds === null) {
        $userIds = db()->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
    }
    if (!$userIds) return [0, 0];
    $in = implode(',', array_map('intval', $userIds));
    $subs = db()->query("SELECT * FROM push_subs WHERE user_id IN ($in)")->fetchAll();
    if (!$subs) return [0, 0];

    $vapid = push_vapid();
    $host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    $json = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $sent = $failed = 0;
    foreach ($subs as $sub) {
        try {
            [$code, $resp, $err] = push_post($sub['endpoint'], push_encrypt($json, $sub['p256dh'], $sub['auth']), [
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'TTL: 86400',
                'Urgency: high',
                'Authorization: ' . push_vapid_header($sub['endpoint'], $vapid, "https://$host"),
            ]);
        } catch (Throwable $e) {
            [$code, $resp, $err] = [0, '', $e->getMessage()];
        }
        if ($code >= 200 && $code < 300) {
            $sent++;
            db()->prepare('UPDATE push_subs SET last_ok = ?, last_error = NULL WHERE endpoint = ?')->execute([now_iso(), $sub['endpoint']]);
        } elseif ($code === 404 || $code === 410) {
            $failed++;
            db()->prepare('DELETE FROM push_subs WHERE endpoint = ?')->execute([$sub['endpoint']]);
        } else {
            $failed++;
            $info = mb_substr(trim($err ?: strip_tags($resp)), 0, 200);
            db()->prepare('UPDATE push_subs SET last_error = ? WHERE endpoint = ?')->execute(["$code $info", $sub['endpoint']]);
        }
    }
    return [$sent, $failed];
}

function push_subscribe(int $userId, $sub, $device): void
{
    push_migrate();
    $endpoint = is_array($sub) ? ($sub['endpoint'] ?? '') : '';
    $keys = is_array($sub) ? ($sub['keys'] ?? []) : [];
    if (!is_string($endpoint) || !preg_match('#^https://#', $endpoint) || strlen($endpoint) > 1000
        || !is_string($keys['p256dh'] ?? null) || !is_string($keys['auth'] ?? null)
        || strlen($keys['p256dh']) > 200 || strlen($keys['auth']) > 100) {
        throw new ApiError('פרטי ההרשמה להתראות אינם תקינים', 422);
    }
    $device = is_string($device) ? mb_substr(trim($device), 0, 80) : '';
    db()->prepare(
        'INSERT INTO push_subs (endpoint, user_id, p256dh, auth, device, created_at) VALUES (?,?,?,?,?,?)
         ON CONFLICT(endpoint) DO UPDATE SET user_id = excluded.user_id, p256dh = excluded.p256dh,
             auth = excluded.auth, device = excluded.device'
    )->execute([$endpoint, $userId, $keys['p256dh'], $keys['auth'], $device, now_iso()]);
    // לא יותר מ-10 מכשירים למשתמש — הישנים יוצאים.
    db()->prepare(
        'DELETE FROM push_subs WHERE user_id = ? AND endpoint NOT IN
            (SELECT endpoint FROM push_subs WHERE user_id = ? ORDER BY created_at DESC LIMIT 10)'
    )->execute([$userId, $userId]);
}

function push_unsubscribe(int $userId, $endpoint): void
{
    push_migrate();
    db()->prepare('DELETE FROM push_subs WHERE user_id = ? AND endpoint = ?')->execute([$userId, (string)$endpoint]);
}

/** המכשירים הרשומים של משתמש — להצגה בממשק. */
function push_devices(int $userId): array
{
    push_migrate();
    $st = db()->prepare('SELECT endpoint, device, created_at, last_ok, last_error FROM push_subs WHERE user_id = ? ORDER BY created_at DESC');
    $st->execute([$userId]);
    return $st->fetchAll();
}

/**
 * שולח את התשובה ללקוח וממשיך לעבוד ברקע — כדי שההרשמה לא תחכה לשליחת
 * ההתראות. בשרת (LiteSpeed) יש litespeed_finish_request; ב-FPM — fastcgi_finish_request.
 */
function respond_then_continue(array $payload): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ignore_user_abort(true);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen($json));
    header('Connection: close');
    echo $json;
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
    else { @ob_end_flush(); flush(); }
}
