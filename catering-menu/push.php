<?php
/**
 * Web Push — התראות לטלפון של המנהל, גם כשהדפדפן סגור.
 *
 * בלי ספריות: חתימת VAPID (ES256) והצפנת התוכן (RFC 8291, aes128gcm) נעשות
 * ב-openssl של PHP. המפתחות והמנויים נשמרים ב-data/push.json, שמוחרג מהפריסה.
 *
 * הקובץ נטען מ-api.php בלבד; פנייה ישירה אליו לא עושה כלום.
 */

declare(strict_types=1);

if (!defined('DATA_DIR')) {         // נטען רק מתוך api.php
    http_response_code(404);
    exit;
}

const PUSH_FILE = DATA_DIR . '/push.json';

/** קידוד base64url (בלי ריפוד), כמו שמשמש ב-Web Push. */
function b64u(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function b64uDecode(string $s): string {
    return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

/** נקודה ציבורית P-256 גולמית (65 בתים) מתוך פרטי מפתח של openssl. */
function rawPublicKey(array $details): string {
    return "\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)
                  . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
}

/** עוטף נקודה ציבורית גולמית ב-PEM, כדי ש-openssl יוכל להשתמש בה. */
function publicKeyFromRaw(string $raw) {
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    return openssl_pkey_get_public($pem);
}

function newEcKey() {
    return openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
}

/** טוען את קובץ ההתראות; בפעם הראשונה יוצר מפתחות VAPID. */
function pushStore(): array {
    $store = readJson(PUSH_FILE);
    if ($store && !empty($store['vapid']['private'])) {
        $store['subs'] ??= [];
        return $store;
    }
    $key = newEcKey();
    openssl_pkey_export($key, $pem);
    $store = [
        'vapid' => ['private' => $pem, 'public' => b64u(rawPublicKey(openssl_pkey_get_details($key)))],
        'subs'  => [],
    ];
    writeJson(PUSH_FILE, $store);
    @chmod(PUSH_FILE, 0600);
    return $store;
}

/** חתימת DER של ECDSA → 64 בתים גולמיים (r||s), כפי ש-JWT דורש. */
function derToRaw(string $der): string {
    $pos = 2;                                   // SEQUENCE + אורך
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$pos + 1]);
        $int = substr($der, $pos + 2, $len);
        $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
        $pos += 2 + $len;
    }
    return $out;
}

/** כותרת Authorization של VAPID לנקודת קצה מסוימת. */
function vapidHeader(string $endpoint, array $vapid, string $subject): string {
    $parts = parse_url($endpoint);
    $aud = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $head = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $body = b64u(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $subject]));
    openssl_sign("$head.$body", $sig, openssl_pkey_get_private($vapid['private']), OPENSSL_ALGO_SHA256);
    return 'vapid t=' . "$head.$body." . b64u(derToRaw($sig)) . ', k=' . $vapid['public'];
}

/** הצפנת התוכן לפי RFC 8291 (aes128gcm) — רק הדפדפן של המנוי יכול לפענח. */
function encryptPayload(string $payload, string $p256dh, string $auth): string {
    $uaPublic = b64uDecode($p256dh);
    $authSecret = b64uDecode($auth);

    $local = newEcKey();
    $asPublic = rawPublicKey(openssl_pkey_get_details($local));
    $shared = openssl_pkey_derive(publicKeyFromRaw($uaPublic), $local, 32);

    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    return $salt . pack('N', 4096) . chr(strlen($asPublic)) . $asPublic . $cipher . $tag;
}

/**
 * שולח התראה לכל המכשירים הרשומים. מנוי שהדפדפן ביטל (404/410) נמחק.
 * מחזיר [נשלחו, נכשלו].
 */
function pushToAll(array $message): array {
    $store = pushStore();
    if (!$store['subs']) return [0, 0];

    $host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    $settings = loadSettings();
    $mail = $settings['email']['replyTo'] ?: $settings['email']['adminNotify'];
    $subject = $mail ? "mailto:$mail" : "https://$host";
    $json = json_encode($message, JSON_UNESCAPED_UNICODE);

    $sent = $failed = 0;
    $keep = [];
    foreach ($store['subs'] as $sub) {
        $body = encryptPayload($json, $sub['keys']['p256dh'], $sub['keys']['auth']);
        $ch = curl_init($sub['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'TTL: 86400',
                'Urgency: high',
                'Authorization: ' . vapidHeader($sub['endpoint'], $store['vapid'], $subject),
            ],
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($code >= 200 && $code < 300) { $sent++; $keep[] = $sub + ['lastOk' => date('c')]; }
        elseif ($code === 404 || $code === 410) { $failed++; }          // המנוי בוטל — לא שומרים
        else { $failed++; $keep[] = $sub + ['lastError' => $code]; }
    }
    $store['subs'] = array_values($keep);
    writeJson(PUSH_FILE, $store);
    return [$sent, $failed];
}

/** רישום מכשיר (או עדכון, אם כבר רשום). */
function pushSubscribe(array $sub, string $device): void {
    $endpoint = $sub['endpoint'] ?? '';
    $keys = $sub['keys'] ?? [];
    if (!is_string($endpoint) || !preg_match('#^https://#', $endpoint) || strlen($endpoint) > 1000
        || !is_string($keys['p256dh'] ?? null) || !is_string($keys['auth'] ?? null)) {
        fail('פרטי ההרשמה להתראות אינם תקינים');
    }
    $store = pushStore();
    $store['subs'] = array_values(array_filter($store['subs'], fn($s) => $s['endpoint'] !== $endpoint));
    $store['subs'][] = [
        'endpoint' => $endpoint,
        'keys'     => ['p256dh' => $keys['p256dh'], 'auth' => $keys['auth']],
        'device'   => mb_substr($device, 0, 80),
        'created'  => date('c'),
    ];
    $store['subs'] = array_slice($store['subs'], -20);   // לא יותר מ-20 מכשירים
    writeJson(PUSH_FILE, $store);
}

function pushUnsubscribe(string $endpoint): void {
    $store = pushStore();
    $store['subs'] = array_values(array_filter($store['subs'], fn($s) => $s['endpoint'] !== $endpoint));
    writeJson(PUSH_FILE, $store);
}

/**
 * שולח את התשובה ללקוח וממשיך לעבוד ברקע — כדי שההזמנה לא תחכה
 * לשליחת ההתראות. ב-cPanel (PHP-FPM) יש fastcgi_finish_request.
 */
function respondThenContinue(array $payload): void {
    $json = json_encode(['success' => true] + $payload, JSON_UNESCAPED_UNICODE);
    ignore_user_abort(true);
    header('Content-Length: ' . strlen($json));
    header('Connection: close');
    echo $json;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_end_flush();
        flush();
    }
}
