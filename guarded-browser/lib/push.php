<?php
/**
 * התראות לטלפון של ההורה (Web Push) — "נועה מבקשת את wikipedia.org".
 *
 *   מה נשלח: **רק "צלצול" ריק** דרך שירות ההתראות של הדפדפן (גוגל / מוזילה / אפל). הטלפון
 *   מתעורר, ו-parent/sw.js שואל את השרת (push_pending) מה חדש — עם העוגייה של ההורה. כך
 *   שמות הילדים והכתובות לעולם לא עוברים דרך צד שלישי, ואין צורך בהצפנת תוכן (RFC 8291) —
 *   רק חתימת VAPID (RFC 8292, ES256) שמוכיחה שהצלצול מאיתנו.
 *
 *   מה חדש: בקשות גישה ממתינות בחשבון. push_state זוכר עד איפה צלצלנו להורה, ו-push_subs עד
 *   איפה נמסר לכל מכשיר שלו — כך כל בקשה מצלצלת פעם אחת, ומופיעה בכל הטלפונים של ההורה.
 *
 *   המפתח (VAPID) נוצר בשרת בפעם הראשונה ונשמר במסד — לא בריפו. כתובת שירות ההתראות מגיעה
 *   מהדפדפן — ולכן מותרת רק לשירותים המוכרים (PUSH_HOSTS): השרת לא ישלח בקשה לכל כתובת.
 *
 *   אותה שיטה כמו ב-recipes-app (שם נבדקה בשרת הזה); הקוד הועתק ולא נטען משם — כל אפליקציה
 *   עצמאית (CLAUDE.md).
 */

declare(strict_types=1);

require_once __DIR__ . '/requests.php';

const PUSH_SUBJECT = 'mailto:support@mbe-plus.com';
const PUSH_MAX_SUBS = 10;          // מכשירים להורה
const PUSH_TTL = 86400;            // שירות ההתראות שומר צלצול עד יום, אם הטלפון כבוי
const PUSH_HOSTS = ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'push.services.mozilla.com',
                    'web.push.apple.com', 'notify.windows.com'];   // וגם תת־דומיינים שלהם

function b64u(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }

/* ── מפתח VAPID ────────────────────────────────────────────────── */

/** המפתח של השרת: ['private' => PEM, 'public' => base64url של הנקודה הציבורית], או null אם אין EC ב-openssl. */
function pushKeys(): ?array {
    $r = one('SELECT private_pem, public_key FROM push_vapid WHERE id = 1');
    if ($r) return ['private' => $r['private_pem'], 'public' => $r['public_key']];
    if (!function_exists('openssl_pkey_new')) return null;
    $k = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$k || !openssl_pkey_export($k, $pem)) return null;
    $d = openssl_pkey_get_details($k);
    $pad = fn(string $b) => str_pad($b, 32, "\0", STR_PAD_LEFT);
    $pub = b64u("\x04" . $pad($d['ec']['x']) . $pad($d['ec']['y']));
    q('INSERT OR IGNORE INTO push_vapid (id, private_pem, public_key, created_at) VALUES (1, ?, ?, ?)', [$pem, $pub, nowIso()]);
    return pushKeys();
}

/** חתימת DER (ASN.1: SEQUENCE של שני INTEGER) → r||s, 64 בתים — מה ש-JWT ES256 מצפה לו. */
function derToRaw(string $der): string {
    $pos = 2;                                    // 0x30 + אורך (חתימת P-256 תמיד קצרה מ-128)
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$pos + 1]);
        $out .= str_pad(ltrim(substr($der, $pos + 2, $len), "\0"), 32, "\0", STR_PAD_LEFT);
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

/* ── מכשירים של ההורה ─────────────────────────────────────────── */

/** כתובת של שירות התראות מוכר בלבד — אחרת השרת היה שולח בקשות לכל כתובת שמישהו נותן. */
function pushEndpointOk(string $endpoint): bool {
    $p = parse_url($endpoint);
    if (($p['scheme'] ?? '') !== 'https' || empty($p['host'])) return false;
    foreach (PUSH_HOSTS as $h) if ($p['host'] === $h || str_ends_with($p['host'], '.' . $h)) return true;
    return false;
}

/** מוחזרת שגיאה, או ''. */
function pushSubscribe(int $parentId, string $endpoint): string {
    if (strlen($endpoint) > 1000 || !pushEndpointOk($endpoint)) return 'שירות ההתראות של הדפדפן הזה אינו נתמך';
    // מהרגע הזה — לא על מה שכבר היה.
    $now = nowIso();
    upsert('push_subs', ['endpoint' => $endpoint], ['parent_id' => $parentId, 'fails' => 0],
           ['created_at' => $now, 'delivered_at' => $now]);
    q('DELETE FROM push_subs WHERE parent_id = ? AND id NOT IN (SELECT id FROM push_subs WHERE parent_id = ? ORDER BY id DESC LIMIT ' . PUSH_MAX_SUBS . ')',
      [$parentId, $parentId]);
    q('INSERT OR IGNORE INTO push_state (parent_id, pinged_at) VALUES (?,?)', [$parentId, $now]);
    return '';
}

function pushUnsubscribe(int $parentId, string $endpoint): void {
    q('DELETE FROM push_subs WHERE parent_id = ? AND endpoint = ?', [$parentId, $endpoint]);
}

/* ── צלצול ומסירה ─────────────────────────────────────────────── */

/** צלצול ריק למכשיר אחד. מחזיר את קוד ה-HTTP (0 — לא הגיע). */
function pushSend(string $endpoint, array $keys): int {
    if (!function_exists('curl_init')) return 0;
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER => ['TTL: ' . PUSH_TTL, 'Urgency: high', 'Content-Length: 0',
                               'Authorization: vapid t=' . pushJwt($endpoint, $keys['private']) . ', k=' . $keys['public']],
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

/** הבקשה הממתינה החדשה ביותר בחשבון של ההורה ('' — אין). */
function pushNewest(int $accountId): string {
    return (string) (one("SELECT MAX(created_at) m FROM access_requests WHERE account_id = ? AND status = 'pending'", [$accountId])['m'] ?? '');
}

/**
 * לכל הורה עם מכשיר רשום: יש בקשה חדשה מאז הצלצול האחרון? — צלצול לכל המכשירים שלו.
 * מכשיר שהשירות אומר שאינו קיים עוד (404/410) — נמחק; אחרי 5 כשלונות — גם.
 * $send — להחלפה בבדיקות. מחזיר כמה הורים צולצלו.
 */
function pushFlush(?callable $send = null): int {
    $keys = pushKeys();
    if (!$keys) return 0;
    $send ??= fn(string $endpoint) => pushSend($endpoint, $keys);
    $rows = all("SELECT DISTINCT s.parent_id, p.account_id, st.pinged_at FROM push_subs s
                 JOIN parents p ON p.id = s.parent_id LEFT JOIN push_state st ON st.parent_id = s.parent_id");
    $rung = 0;
    foreach ($rows as $r) {
        $pid = (int) $r['parent_id'];
        $since = (string) ($r['pinged_at'] ?? '');
        $newest = pushNewest((int) $r['account_id']);
        if ($newest === '' || $newest <= $since) continue;
        upsert('push_state', ['parent_id' => $pid], ['pinged_at' => $newest]);
        foreach (all('SELECT id, endpoint, fails FROM push_subs WHERE parent_id = ?', [$pid]) as $s) {
            $code = $send($s['endpoint']);
            if ($code === 404 || $code === 410 || (($code < 200 || $code >= 300) && (int) $s['fails'] >= 4)) {
                q('DELETE FROM push_subs WHERE id = ?', [(int) $s['id']]);
            } elseif ($code >= 200 && $code < 300) {
                q('UPDATE push_subs SET fails = 0, last_ok_at = ? WHERE id = ?', [nowIso(), (int) $s['id']]);
            } else {
                q('UPDATE push_subs SET fails = fails + 1 WHERE id = ?', [(int) $s['id']]);
            }
        }
        $rung++;
    }
    return $rung;
}

/**
 * טלפון התעורר: אילו בקשות חדשות מאז המסירה האחרונה *למכשיר הזה* — ומסומנות כנמסרו לו.
 * מכשיר שאינו מוכר (המינוי התחלף) מקבל את מה שנוצר ב-10 הדקות האחרונות.
 * פריט: title, body, link, tag, id.
 */
function pushPending(array $parent, string $endpoint): array {
    $sub = one('SELECT id, delivered_at FROM push_subs WHERE parent_id = ? AND endpoint = ?', [(int) $parent['id'], $endpoint]);
    $since = $sub ? (string) $sub['delivered_at'] : gmdate('Y-m-d\TH:i:s\Z', time() - 600);
    $rows = all("SELECT r.*, u.display_name, u.username FROM access_requests r JOIN users u ON u.id = r.child_id
                 WHERE r.account_id = ? AND r.status = 'pending' AND r.created_at > ? ORDER BY r.id DESC LIMIT 5",
                [(int) $parent['account_id'], $since]);
    if (!$rows) return [];
    if ($sub) q('UPDATE push_subs SET delivered_at = ? WHERE id = ?', [$rows[0]['created_at'], (int) $sub['id']]);
    return array_map(fn($r) => [
        'title' => ($r['display_name'] ?: $r['username']) . ' מבקש/ת גישה',
        'body'  => $r['host'] . ($r['note'] !== '' ? ' — "' . $r['note'] . '"' : ''),
        'link'  => './?req=' . (int) $r['id'],
        'tag'   => 'req-' . (int) $r['id'],
        'id'    => (int) $r['id'],
    ], $rows);
}

/** אחרי התשובה לילד (כדי לא לעכב אותו) — צלצול להורים. */
function pushAfterResponse(): void {
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
        try { pushFlush(); } catch (Throwable $e) { error_log('migdalor push: ' . $e->getMessage()); }
    });
}
