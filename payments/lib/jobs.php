<?php
/**
 * "השעון" של התוסף (פרק 9.1). ב-PHP אין תהליך שרץ ברקע, ולכן tick() רץ משלושה מקורות:
 *  1. אחרי כל בקשה לתוסף (ה-API, ממשק הניהול, דף התשלום) — אחרי שהתשובה נשלחה, לכל היותר פעם בדקה.
 *  2. api.php?action=tick — כתובת ציבורית בלי מידע, לשעון חיצוני.
 *  3. משימה מתוזמנת בלוח הבקרה של האחסון (cron) — רק היא מבטיחה ריצה גם כשאין אף בקשה.
 * כל עבודה בטוחה להרצה כפולה; הנעילה כאן רק חוסכת עבודה כפולה.
 */

declare(strict_types=1);

require_once __DIR__ . '/subscriptions.php';
require_once __DIR__ . '/documents.php';

const TICK_EVERY = 55;

/** @return array<string,int>|null ‏null — רץ לפני פחות מדקה */
function tick(string $source, bool $force = false): ?array
{
    $now = now();
    db_run("INSERT OR IGNORE INTO setting (key, value) VALUES ('tick_at', '0')");
    if (!$force && db_run('UPDATE setting SET value = ? WHERE key = ? AND CAST(value AS INTEGER) <= ?', [(string)$now, 'tick_at', $now - TICK_EVERY]) !== 1) return null;
    if ($force) set_setting('tick_at', (string)$now);
    $out = [
        'reservations' => reservations_expire(),
        'checkouts' => checkouts_reconcile(),
        'renewed' => subscriptions_renew(),
        'retried' => invoices_retry(),
        'notices' => subscriptions_notices(),
        'documents' => tax_docs_issue(),
        'mails' => notifications_send(),
    ];
    $w = webhooks_deliver(100);
    $out['webhooks'] = $w['sent'];
    db_run('DELETE FROM nonce WHERE t < ?', [$now - NONCE_TTL]);
    db_run('DELETE FROM idempotency WHERE created_at < ?', [$now - 86400]);
    db_run('DELETE FROM portal_session WHERE expires_at < ?', [$now]);
    if ($now % 3600 < TICK_EVERY + 5) db_run('DELETE FROM api_log WHERE created_at < ?', [$now - 30 * 86400]);
    set_setting('tick_last_' . $source, (string)$now);
    set_setting('tick_last', (string)$now);
    return $out;
}

/** קניות פתוחות שהלקוח שילם בהן אבל לא חזר מהסולק — שואלים את הסולק (פרק 9.4). */
function checkouts_reconcile(): int
{
    $n = 0;
    foreach (db_all("SELECT * FROM checkout WHERE status = 'open' AND provider_ref <> '' AND created_at > ? LIMIT 50", [now() - 2 * CHECKOUT_TTL]) as $c) {
        try {
            $after = checkout_sync($c);
            $n += (int)($after['status'] === 'completed');
        } catch (Throwable $e) {
            error_log('payments reconcile ' . $c['id'] . ': ' . $e->getMessage());
        }
    }
    foreach (db_all("SELECT id FROM checkout WHERE status = 'open' AND expires_at < ?", [now()]) as $c) checkout_load($c['id']);
    return $n;
}

/** אחרי שהתשובה נשלחה ללקוח — הרצת השעון בלי לעכב אותו. */
function tick_after_response(string $source): void
{
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
    try {
        tick($source);
    } catch (Throwable $e) {
        error_log('payments tick: ' . $e->getMessage());
    }
}
