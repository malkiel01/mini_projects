<?php
/**
 * החזרה מ-Dropbox — כתובת אחת לכל האפליקציות (מודול הכוננים, suite-cloud/drives).
 *
 * בקונסולה של Dropbox רושמים redirect URI אחד: https://mbe-plus.com/mini_projects/suite-cloud/oauth/dropbox.php.
 * ה-state הוא "<client>.<אקראי>": הדף מעביר את ?code&state הלאה ל-api.php של אותו לקוח (רשום ופעיל בענן),
 * ושם המודול מחליף את הקוד בעזרת ה-code_verifier שרק הוא מחזיק (PKCE) — כך שהקוד לבדו חסר ערך כאן.
 * ציבורי (בלי השער): Dropbox מפנה לכאן, והמשתמש לא בהכרח מורשה לענן עצמו.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/clients.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$state = (string) ($_GET['state'] ?? '');
$client = explode('.', $state, 2)[0];
try {
    $c = activeClient(cleanClientId($client));
} catch (Throwable) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('החזרה מ-Dropbox לא שייכת לאפליקציה רשומה');
}
// ה-endpoint של הלקוח = key_url בלי השאילתה (Drives.php נרשם עם endpoint?action=drives_key)
$endpoint = strtok($c['key_url'], '?');
$q = ['action' => 'drives_oauth_callback', 'provider' => 'dropbox', 'state' => $state];
foreach (['code', 'error', 'error_description'] as $k) if (isset($_GET[$k])) $q[$k] = (string) $_GET[$k];
header('Location: ' . $endpoint . '?' . http_build_query($q), true, 303);
