<?php
/**
 * חזרה מהסולק. לא סומכים על מה שבכתובת — שואלים את הסולק (checkout_sync) ורק אז סוגרים את הקנייה.
 * אם עוד לא שולם — חזרה לדף התשלום (שם אפשר לנסות שוב).
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/pages.php';

$id = (string)($_GET['c'] ?? '');
$c = preg_match('/^chk_[a-z0-9]{20}$/', $id) ? checkout_load($id) : null;
if (!$c) page_error('הקנייה לא נמצאה.');
try {
    checkout_sync($c);
} catch (ApiError $e) {
    page_error($e->getMessage(), checkout_view($c)['client']);
}
header('Location: ./?c=' . rawurlencode($c['id']), true, 303);
tick_after_response('pay');
