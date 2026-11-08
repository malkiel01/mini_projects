<?php
/**
 * פוש לדפדפן (spec-stage-a 8.3) — Web Push עם VAPID, **בלי תוכן בהודעה**: השרת שולח רק "יש משהו", ו-Service Worker
 * (sw.js) מושך את ההתראה האחרונה מהתיבה בבקשה אחת ומציג אותה. כך אין צורך בהצפנת תוכן (RFC 8291), ושום טקסט לא עובר
 * דרך שירות הפוש של הדפדפן.
 *
 * מפתחות VAPID (P-256) — נוצרים בשרת בפעם הראשונה, ב-data/vapid.json (מוחרג מהפריסה, חסום ב-.htaccess). הפרטי לא יוצא.
 * כתובת מנוי — רק HTTPS של שירות פוש מוכר (רשימה למטה): אחרת המנוי היה דרך לגרום לשרת לפנות לכל כתובת (SSRF).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';

/** שירותי הפוש של הדפדפנים. בבדיקות (TS_DEV) — גם push.test */
const TS_PUSH_HOSTS = ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com'];
const TS_PUSH_HOST_SUFFIXES = ['.notify.windows.com', '.push.apple.com'];

function ts_b64u(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function ts_vapid_path(): string
{
    return defined('TS_DB_PATH') ? TS_DB_PATH . '.vapid' : dirname(__DIR__) . '/data/vapid.json';
}

/** ['private' => PEM, 'public' => base64url של הנקודה הלא-דחוסה (65 בתים)] */
function ts_vapid(): array
{
    static $v = null;
    if ($v) return $v;
    $path = ts_vapid_path();
    $saved = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
    if (is_array($saved) && isset($saved['private'], $saved['public'])) return $v = $saved;
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$key) throw new RuntimeException('לא ניתן ליצור מפתח VAPID');
    openssl_pkey_export($key, $pem);
    $d = openssl_pkey_get_details($key)['ec'];
    $pub = "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
    $v = ['private' => $pem, 'public' => ts_b64u($pub)];
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    file_put_contents($path, json_encode($v), LOCK_EX);
    @chmod($path, 0600);
    return $v;
}

/** חתימת DER של ECDSA → 64 בתים (r‖s), כמו ש-JWT ES256 דורש */
function ts_der_to_raw(string $der): string
{
    $pos = 2;
    if (ord($der[1]) & 0x80) $pos += ord($der[1]) & 0x7f;
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$pos + 1]);
        $int = substr($der, $pos + 2, $len);
        $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
        $pos += 2 + $len;
    }
    return $out;
}

function ts_push_endpoint_ok(mixed $url): bool
{
    if (!is_string($url) || strlen($url) > 1000 || !str_starts_with($url, 'https://')) return false;
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host === '' || parse_url($url, PHP_URL_PORT) !== null || parse_url($url, PHP_URL_USER) !== null) return false;
    if (in_array($host, TS_PUSH_HOSTS, true)) return true;
    foreach (TS_PUSH_HOST_SUFFIXES as $s) if (str_ends_with($host, $s)) return true;
    return defined('TS_DEV') && $host === 'push.test';
}

/** כותרות VAPID לכתובת מנוי (RFC 8292) */
function ts_vapid_headers(string $endpoint, int $now): array
{
    $v = ts_vapid();
    $p = parse_url($endpoint);
    $head = ts_b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = ts_b64u(json_encode(['aud' => $p['scheme'] . '://' . $p['host'], 'exp' => $now + 12 * 3600,
        'sub' => 'https://mbe-plus.com/mini_projects/trading-strategies/']));
    openssl_sign("$head.$claims", $sig, $v['private'], OPENSSL_ALGO_SHA256);
    return ['Authorization: vapid t=' . "$head.$claims." . ts_b64u(ts_der_to_raw($sig)) . ', k=' . $v['public'],
        'TTL: 86400', 'Urgency: normal', 'Content-Length: 0'];
}

/**
 * "יש התראה" לכל המנויים של המשתמש. מנוי שהשירות אומר שאיננו (404/410) — נמחק. מחזיר כמה נשלחו.
 */
function ts_push_user(int $uid, int $now): array
{
    $st = ts_db()->prepare('SELECT id, endpoint FROM push_subs WHERE user_id = ?');
    $st->execute([$uid]);
    $sent = 0; $gone = 0; $failed = 0;
    foreach ($st->fetchAll() as $s) {
        if (!ts_push_endpoint_ok($s['endpoint'])) { $failed++; continue; }
        try {
            [$code] = ts_http_post($s['endpoint'], ts_vapid_headers($s['endpoint'], $now));
        } catch (TsHttpError) {
            $failed++;
            continue;
        }
        if ($code === 404 || $code === 410) {
            ts_db()->prepare('DELETE FROM push_subs WHERE id = ?')->execute([$s['id']]);
            $gone++;
        } elseif ($code >= 200 && $code < 300) {
            $sent++;
        } else {
            $failed++;
        }
    }
    return ['sent' => $sent, 'gone' => $gone, 'failed' => $failed];
}

function ts_push_subscribe(int $uid, array $in): void
{
    $endpoint = $in['endpoint'] ?? null;
    if (!ts_push_endpoint_ok($endpoint)) throw new TsInvalid('כתובת פוש לא מוכרת');
    $keys = is_array($in['keys'] ?? null) ? array_intersect_key($in['keys'], ['p256dh' => 1, 'auth' => 1]) : [];
    foreach ($keys as $k) if (!is_string($k) || strlen($k) > 200) throw new TsInvalid('מפתחות מנוי לא תקינים');
    $st = ts_db()->prepare('SELECT COUNT(*) FROM push_subs WHERE user_id = ?');
    $st->execute([$uid]);
    if ((int)$st->fetchColumn() >= 10) throw new TsInvalid('עד 10 מכשירים לפוש');
    // כתובת שכבר רשומה למשתמש אחר (למשל דפדפן משותף): המנוי הישן נמחק ונוצר חדש — לא מעבירים בעלות על שורה קיימת
    // (ממצא בקרה #1275). של אותו משתמש — רק המפתחות מתעדכנים.
    $db = ts_db();
    $db->beginTransaction();
    $db->prepare('DELETE FROM push_subs WHERE endpoint = ? AND user_id != ?')->execute([$endpoint, $uid]);
    $db->prepare('INSERT INTO push_subs (user_id, endpoint, keys, created_at) VALUES (?, ?, ?, ?)
                  ON CONFLICT(endpoint) DO UPDATE SET keys = excluded.keys WHERE push_subs.user_id = excluded.user_id')
        ->execute([$uid, $endpoint, json_encode($keys), time()]);
    $db->commit();
}

function ts_push_unsubscribe(int $uid, mixed $endpoint): void
{
    ts_db()->prepare('DELETE FROM push_subs WHERE user_id = ? AND endpoint = ?')->execute([$uid, is_string($endpoint) ? $endpoint : '']);
}

function ts_push_count(int $uid): int
{
    $st = ts_db()->prepare('SELECT COUNT(*) FROM push_subs WHERE user_id = ?');
    $st->execute([$uid]);
    return (int)$st->fetchColumn();
}
