<?php
/**
 * הודעות נכנסות ממורנינג (פרק 9.4). שני סוגים:
 *  1. ‏notifyUrl של דף התשלום (‏?pa=…&c=…) — לא חתום, ולכן רק "רמז": שואלים את מורנינג ובודקים סכום.
 *  2. ‏webhooks שהוגדרו בממשק מורנינג (‏?pa=…, עם סוד) — חתומים ב-HMAC; נשמרים פעם אחת, ואז אותה שאלה.
 * בשני המקרים שום דבר לא נסגר בלי checkout_sync — אישור מהשרת שלנו מול מורנינג.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/pages.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$paId = (string)($_GET['pa'] ?? '');
$pa = preg_match('/^pa_[a-z0-9]{20}$/', $paId) ? db_one("SELECT * FROM provider_account WHERE id = ? AND provider = 'morning'", [$paId]) : null;
if (!$pa) { http_response_code(404); echo '{"ok":false}'; exit; }
$p = provider_from_account($pa);
$raw = (string)file_get_contents('php://input');
$checkoutIds = [];

$sig = (string)($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '');
$delivery = (string)($_SERVER['HTTP_X_WEBHOOK_DELIVERY_ID'] ?? '');
if ($delivery !== '') {
    $ok = $p instanceof MorningProvider && $p->verifyWebhook($raw, $sig);
    if (!$ok) { http_response_code(401); echo '{"ok":false}'; exit; } // לא חתום — לא נשמר
    db_run('INSERT OR IGNORE INTO provider_event (id, provider_account, delivery_id, topic, payload, verified, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [
        new_id('pev'), $pa['id'], mb_substr($delivery, 0, 120), mb_substr((string)($_SERVER['HTTP_X_WEBHOOK_TOPIC'] ?? ''), 0, 60), mb_substr($raw, 0, 200_000), (int)$ok, now(),
    ]);
    if (preg_match_all('/chk_[a-z0-9]{20}/', $raw, $m)) $checkoutIds = array_unique($m[0]);
    if (preg_match_all('/inv_[a-z0-9]{20}/', $raw, $m)) foreach (array_unique($m[0]) as $invId) {
        // חידוש שעבר אצל מורנינג אבל לא אצלנו (ניתוק באמצע) — יתגלה בניסיון הבא; כאן רק מתעדים
        audit('system', 'morning', 'webhook.invoice_seen', 'invoice', $invId, [], null);
    }
} else {
    $c = (string)($_GET['c'] ?? '');
    if (preg_match('/^chk_[a-z0-9]{20}$/', $c)) $checkoutIds = [$c];
}

foreach ($checkoutIds as $id) {
    // קנייה שנסגרה — אין מה לשאול; קריאה לא חתומה לא מפעילה שאלה למורנינג שוב ושוב
    $c = db_one("SELECT * FROM checkout WHERE id = ? AND provider = ? AND status <> 'completed'", [$id, $pa['id']]);
    if (!$c) continue;
    try {
        checkout_sync($c);
    } catch (Throwable $e) {
        error_log('payments morning hook ' . $id . ': ' . $e->getMessage());
    }
}
if ($delivery !== '') db_run('UPDATE provider_event SET processed_at = ? WHERE provider_account = ? AND delivery_id = ?', [now(), $pa['id'], $delivery]);
echo '{"ok":true}';
tick_after_response('hook');
