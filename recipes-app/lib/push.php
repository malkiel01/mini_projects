<?php
/**
 * התראות לטלפון (Web Push) — שותף איתך מתכון, המלצה, תגובה, עוקב חדש...
 *
 *   מה נשלח: **רק "צלצול" ריק** דרך שירות ההתראות של הדפדפן (גוגל / מוזילה / אפל). הטלפון
 *   מתעורר, ו-service-worker.js שואל את השרת שלנו (push-pending) מה חדש — עם העוגייה של המשתמש.
 *   כך שמות ומתכונים לעולם לא עוברים דרך צד שלישי, ואין צורך בהצפנת תוכן (RFC 8291) —
 *   רק חתימת VAPID (RFC 8292, ES256) שמוכיחה שהצלצול מאיתנו.
 *
 *   מה חדש: מרכז הפעילות (lib/activity.php) — אותם סוגים, אותן חסימות. push_state זוכר עד
 *   איפה צלצלנו ועד איפה נמסר, כך שכל דבר מצלצל פעם אחת.
 *
 *   מתי מצלצלים: מיד אחרי פעולה שיוצרת פעילות לאחרים (שיתוף, המלצה, תגובה, מעקב — אחרי
 *   שהתשובה כבר יצאה למשתמש), ובכל דופק (heartbeat, כל רבע שעה) — למקרה שמשהו התפספס.
 *
 *   המפתח (VAPID) נוצר בשרת בפעם הראשונה ונשמר במסד — לא בריפו. כתובת שירות ההתראות
 *   מגיעה מהדפדפן — ולכן מותרת רק לשירותים המוכרים (PUSH_HOSTS): השרת לא ישלח בקשה לכל כתובת.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/activity.php';

const PUSH_SUBJECT = 'mailto:support@mbe-plus.com';
const PUSH_MAX_SUBS = 10;          // מכשירים למשתמש
const PUSH_TTL = 86400;            // שירות ההתראות שומר צלצול עד יום, אם הטלפון כבוי
const PUSH_HOSTS = ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'push.services.mozilla.com',
                    'web.push.apple.com', 'notify.windows.com'];   // וגם תת־דומיינים שלהם

function b64u(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function b64uDecode(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

// ─────────────────────────────────────────────────────────────
// מפתח VAPID
// ─────────────────────────────────────────────────────────────

/** המפתח של השרת: ['private' => PEM, 'public' => base64url של הנקודה הציבורית], או null אם אין EC ב-openssl. */
function pushKeys(): ?array {
    $r = db()->query('SELECT private_pem, public_key FROM push_vapid WHERE id = 1')->fetch();
    if ($r) return ['private' => $r['private_pem'], 'public' => $r['public_key']];
    if (!function_exists('openssl_pkey_new')) return null;
    $k = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$k || !openssl_pkey_export($k, $pem)) return null;
    $d = openssl_pkey_get_details($k);
    $pad = fn(string $b) => str_pad($b, 32, "\0", STR_PAD_LEFT);
    $pub = b64u("\x04" . $pad($d['ec']['x']) . $pad($d['ec']['y']));
    db()->prepare('INSERT OR IGNORE INTO push_vapid (id, private_pem, public_key, created_at) VALUES (1, ?, ?, ?)')->execute([$pem, $pub, nowIso()]);
    return pushKeys();
}

/** חתימת DER (ASN.1: SEQUENCE של שני INTEGER) → r||s, 64 בתים — מה ש-JWT ES256 מצפה לו. */
function derToRaw(string $der): string {
    $pos = 2;                                    // 0x30 + אורך (חתימת P-256 תמיד קצרה מ-128)
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$pos + 1]);
        $int = substr($der, $pos + 2, $len);
        $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
        $pos += 2 + $len;
    }
    return $out;
}

/** JWT של VAPID לשירות ההתראות של הכתובת הזאת (aud = המקור שלה). */
function pushJwt(string $endpoint, string $privatePem, ?int $now = null): string {
    $p = parse_url($endpoint);
    $head = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $body = b64u(json_encode(['aud' => $p['scheme'] . '://' . $p['host'], 'exp' => ($now ?? time()) + 12 * 3600, 'sub' => PUSH_SUBJECT]));
    openssl_sign("$head.$body", $sig, $privatePem, OPENSSL_ALGO_SHA256);
    return "$head.$body." . b64u(derToRaw($sig));
}

// ─────────────────────────────────────────────────────────────
// מכשירים
// ─────────────────────────────────────────────────────────────

/** כתובת של שירות התראות מוכר בלבד — אחרת השרת היה שולח בקשות לכל כתובת שמישהו נותן. */
function pushEndpointOk(string $endpoint): bool {
    $p = parse_url($endpoint);
    if (($p['scheme'] ?? '') !== 'https' || empty($p['host'])) return false;
    foreach (PUSH_HOSTS as $h) if ($p['host'] === $h || str_ends_with($p['host'], '.' . $h)) return true;
    return false;
}

function pushSubscribe(array $user, array $in): void {
    $endpoint = (string) ($in['endpoint'] ?? '');
    if (strlen($endpoint) > 1000 || !pushEndpointOk($endpoint)) throw new AppError('שירות ההתראות של הדפדפן הזה אינו נתמך');
    $uid = (int) $user['id'];
    db()->prepare('INSERT INTO push_subs (user_id, endpoint, created_at) VALUES (?,?,?)
                   ON CONFLICT(endpoint) DO UPDATE SET user_id = excluded.user_id, fails = 0')
        ->execute([$uid, $endpoint, nowIso()]);
    db()->prepare('DELETE FROM push_subs WHERE user_id = ? AND id NOT IN (SELECT id FROM push_subs WHERE user_id = ? ORDER BY id DESC LIMIT ' . PUSH_MAX_SUBS . ')')
        ->execute([$uid, $uid]);
    // מהרגע הזה — לא על מה שכבר היה
    db()->prepare('INSERT INTO push_state (user_id, pinged_at, delivered_at) VALUES (?,?,?) ON CONFLICT(user_id) DO NOTHING')
        ->execute([$uid, nowIso(), nowIso()]);
}

function pushUnsubscribe(array $user, string $endpoint): void {
    db()->prepare('DELETE FROM push_subs WHERE user_id = ? AND endpoint = ?')->execute([(int) $user['id'], $endpoint]);
}

// ─────────────────────────────────────────────────────────────
// צלצול ומסירה
// ─────────────────────────────────────────────────────────────

/** צלצול ריק למכשיר אחד. מחזיר את קוד ה-HTTP (0 — לא הגיע). */
function pushSend(string $endpoint, array $keys): int {
    if (!function_exists('curl_init')) return 0;
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER => ['TTL: ' . PUSH_TTL, 'Urgency: normal', 'Content-Length: 0',
                               'Authorization: vapid t=' . pushJwt($endpoint, $keys['private']) . ', k=' . $keys['public']],
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

/**
 * לכל מי שיש לו מכשיר רשום: יש פעילות חדשה מאז הצלצול האחרון? — צלצול לכל המכשירים שלו.
 * מכשיר שהשירות אומר שאינו קיים עוד (404/410) — נמחק; אחרי 5 כשלונות — גם.
 * $send — להחלפה בבדיקות. מחזיר כמה משתמשים צולצלו.
 */
function pushFlush(?callable $send = null): int {
    $keys = pushKeys();
    if (!$keys) return 0;
    $send ??= fn(string $endpoint) => pushSend($endpoint, $keys);
    $users = db()->query('SELECT DISTINCT s.user_id, st.pinged_at FROM push_subs s LEFT JOIN push_state st ON st.user_id = s.user_id')->fetchAll();
    $rung = 0;
    foreach ($users as $u) {
        $uid = (int) $u['user_id'];
        $user = db()->query("SELECT id, username, display_name, role, blocked FROM users WHERE id = $uid")->fetch();
        if (!$user || (int) $user['blocked'] === 1) continue;
        $since = (string) ($u['pinged_at'] ?? '');
        $newest = '';
        foreach (activityFeed($user)['items'] as $it) if ($it['at'] > $since && $it['at'] > $newest) $newest = $it['at'];
        if ($newest === '') continue;
        db()->prepare('INSERT INTO push_state (user_id, pinged_at, delivered_at) VALUES (?,?,?) ON CONFLICT(user_id) DO UPDATE SET pinged_at = excluded.pinged_at')
            ->execute([$uid, $newest, $since ?: $newest]);
        $st = db()->prepare('SELECT id, endpoint, fails FROM push_subs WHERE user_id = ?');
        $st->execute([$uid]);
        foreach ($st->fetchAll() as $s) {
            $code = $send($s['endpoint']);
            if ($code === 404 || $code === 410 || (($code < 200 || $code >= 300) && (int) $s['fails'] >= 4)) {
                db()->prepare('DELETE FROM push_subs WHERE id = ?')->execute([(int) $s['id']]);
            } elseif ($code >= 200 && $code < 300) {
                db()->prepare('UPDATE push_subs SET fails = 0, last_ok_at = ? WHERE id = ?')->execute([nowIso(), (int) $s['id']]);
            } else {
                db()->prepare('UPDATE push_subs SET fails = fails + 1 WHERE id = ?')->execute([(int) $s['id']]);
            }
        }
        $rung++;
    }
    return $rung;
}

/** הטלפון התעורר: מה חדש מאז המסירה האחרונה — ומסומן כנמסר. פריט: title, body, link. */
function pushPending(array $user): array {
    $uid = (int) $user['id'];
    $st = db()->prepare('SELECT delivered_at FROM push_state WHERE user_id = ?');
    $st->execute([$uid]);
    $since = (string) ($st->fetchColumn() ?: '');
    $st->closeCursor();
    $items = array_values(array_filter(activityFeed($user)['items'], fn($it) => $it['at'] > $since));
    if (!$items) return [];
    db()->prepare('INSERT INTO push_state (user_id, pinged_at, delivered_at) VALUES (?,?,?) ON CONFLICT(user_id) DO UPDATE SET delivered_at = excluded.delivered_at')
        ->execute([$uid, $items[0]['at'], $items[0]['at']]);
    return array_map(fn($it) => [
        'title' => $it['actor']['name'] . ' ' . $it['text'],
        'body'  => trim(($it['title'] ?? '') . ($it['snippet'] ? ' — ' . $it['snippet'] : '')),
        'link'  => $it['link'],
    ], array_slice($items, 0, 5));
}

/** אחרי התשובה למשתמש (כדי לא לעכב אותו) — צלצול למי שקיבל עכשיו פעילות. */
function pushAfterResponse(): void {
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
        try { pushFlush(); } catch (Throwable $e) { error_log('recipes-app push: ' . $e->getMessage()); }
    });
}
