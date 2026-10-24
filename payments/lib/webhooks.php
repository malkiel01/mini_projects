<?php
/**
 * webhooks יוצאים — מהתוסף למערכות (פרק 4.5). תור במסד, ניסיונות חוזרים, חתימה.
 *
 * כותרות: X-Payments-Event (evt_…), X-Payments-Time, X-Payments-Signature על המחרוזת
 *   "webhook\n<time>\n<evt>\n<sha256hex(body)>":
 *   - מערכת Ed25519 (יש key_url):  "<kid>.<base64>" — המפתח הציבורי ב-api.php?action=key
 *   - אחרת, עם סוד webhook:        "hmac.<hex(HMAC-SHA256(whsec, …))>"
 * האירוע הוא רמז; הסדר לא מובטח; החלטה חשובה → entitlement טרי.
 */

declare(strict_types=1);

require_once __DIR__ . '/clients.php';

const WEBHOOK_BACKOFF = [60, 300, 1800, 7200, 21600, 43200, 86400];

/** תור אירוע למערכת — רק אם הוגדרה לה כתובת. מחזיר את מזהה האירוע או null. */
function webhook_emit(string $clientId, string $env, string $type, ?string $externalId, array $data): ?string
{
    $c = db_one('SELECT id, webhook_url FROM client WHERE id = ?', [$clientId]);
    if (!$c || $c['webhook_url'] === '') return null;
    $id = new_id('evt');
    $body = json_encode(['id' => $id, 'type' => $type, 'created' => now(), 'app' => $clientId, 'env' => $env,
        'external_id' => $externalId, 'data' => (object)$data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    db_run('INSERT INTO webhook_delivery (id, client, env, type, body, next_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$id, $clientId, $env, $type, $body, now(), now()]);
    return $id;
}

/** @return array<string> כותרות HTTP חתומות */
function webhook_headers(array $client, string $env, string $evtId, string $body): array
{
    $time = (string)now();
    $msg = "webhook\n$time\n$evtId\n" . hash('sha256', $body);
    if ($client['key_url'] !== '') {
        $k = signing_key();
        $sig = $k['kid'] . '.' . base64_encode(sodium_crypto_sign_detached($msg, $k['secret']));
    } else {
        $wh = key_secret($client['id'], $env, 'whsec');
        $sig = $wh ? 'hmac.' . hash_hmac('sha256', $msg, $wh['secret']) : '';
    }
    return ['Content-Type: application/json', "X-Payments-Event: $evtId", "X-Payments-Time: $time", "X-Payments-Signature: $sig"];
}

/** שולח את האירועים שהגיע זמנם. רץ מ-cron.php, ובסוף בקשה שיצרה אירועים. */
function webhooks_deliver(int $limit = 50): array
{
    $sent = $failed = 0;
    foreach (db_all("SELECT * FROM webhook_delivery WHERE status = 'pending' AND next_at <= ? ORDER BY next_at LIMIT ?", [now(), $limit]) as $d) {
        // נעילה רכה: מזיזים את next_at לפני השליחה, כדי ששתי ריצות לא ישלחו אותו אירוע יחד
        if (db_run("UPDATE webhook_delivery SET next_at = ? WHERE id = ? AND next_at = ? AND status = 'pending'", [now() + 120, $d['id'], $d['next_at']]) !== 1) continue;
        $c = db_one('SELECT * FROM client WHERE id = ?', [$d['client']]);
        $res = ($c && $c['webhook_url'] !== '') ? http_request($c['webhook_url'], $d['body'], webhook_headers($c, $d['env'], $d['id'], $d['body']), 10) : ['status' => 0, 'body' => ''];
        $attempt = (int)$d['attempt'] + 1;
        if ($res['status'] >= 200 && $res['status'] < 300) {
            db_run("UPDATE webhook_delivery SET status = 'delivered', attempt = ?, response_code = ? WHERE id = ?", [$attempt, $res['status'], $d['id']]);
            $sent++;
        } else {
            $done = $attempt > count(WEBHOOK_BACKOFF);
            db_run('UPDATE webhook_delivery SET status = ?, attempt = ?, response_code = ?, next_at = ? WHERE id = ?', [
                $done ? 'failed' : 'pending', $attempt, $res['status'], now() + (WEBHOOK_BACKOFF[$attempt - 1] ?? 0), $d['id'],
            ]);
            $failed++;
        }
    }
    return ['sent' => $sent, 'failed' => $failed];
}

/** שליחה חוזרת ידנית (מהמנהל) — מחזיר לתור מיד. */
function webhook_retry(array $tenant, string $evtId): void
{
    $d = db_one('SELECT d.* FROM webhook_delivery d JOIN client c ON c.id = d.client WHERE d.id = ? AND c.tenant = ?', [$evtId, $tenant['id']]);
    if (!$d) throw new ApiError('האירוע לא נמצא', 'not_found', 404);
    db_run("UPDATE webhook_delivery SET status = 'pending', next_at = ? WHERE id = ?", [now(), $evtId]);
}
