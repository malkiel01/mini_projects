<?php
declare(strict_types=1);

/**
 * webhook של Dropbox — כתובת ציבורית (ב-"public" של app.json): Dropbox ניגשת בלי השער.
 * GET ?challenge=X → X כטקסט (אימות הכתובת ב-App Console).
 * POST → X-Dropbox-Signature = HMAC-SHA256(גוף, App secret). לא תקין → 403. תקין → מסמנים sync_pending לארגונים של
 * ה-account_id-ים שבגוף, עונים 200 מיד, ואחרי fastcgi_finish_request (כשקיים) מסנכרנים — עד 8 שניות לארגון.
 * הגוף לא נשמר ולא נכתב ליומן.
 */
require __DIR__ . '/lib.php';
require_once __DIR__ . '/dropbox/lib.php';

header('X-Content-Type-Options: nosniff');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    $challenge = (string)($_GET['challenge'] ?? '');
    if ($challenge === '' || strlen($challenge) > 500) reply(400, ['error' => 'חסר challenge']);
    header('Content-Type: text/plain; charset=utf-8');
    echo $challenge;
    exit;
}
if ($method !== 'POST') reply(405, ['error' => 'שיטה לא מורשית']);
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 1000000) reply(413, ['error' => 'הבקשה גדולה מדי']);
$raw = (string)file_get_contents('php://input', false, null, 0, 1000001);
$secret = DbxClient::appSecret();
$sig = strtolower((string)($_SERVER['HTTP_X_DROPBOX_SIGNATURE'] ?? ''));
if ($secret === null || $sig === '' || !hash_equals(hash_hmac('sha256', $raw, $secret), $sig)) reply(403, ['error' => 'חתימה לא תקינה']);

$data = json_decode($raw, true);
$accounts = is_array($data['list_folder']['accounts'] ?? null) ? $data['list_folder']['accounts'] : [];
$accounts = array_values(array_unique(array_filter($accounts, static fn($a) => is_string($a) && preg_match('/^dbid:[A-Za-z0-9_-]{1,100}$/D', $a))));
$pdo = db();
$orgs = [];
if ($accounts) {
    $in = implode(',', array_fill(0, count($accounts), '?'));
    $st = $pdo->prepare("SELECT org_id FROM dbx_orgs WHERE status='connected' AND account_id IN ($in)");
    $st->execute($accounts);
    $orgs = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    foreach ($orgs as $o) dbxOrgSet($pdo, $o, ['sync_pending' => 1, 'last_webhook_at' => gmdate('c')]);
}

// תשובה מיד — Dropbox מצפה לתשובה בתוך 10 שניות, והסנכרון ממשיך אחריה
ignore_user_abort(true);
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
$out = json_encode(['ok' => true]);
header('Content-Length: ' . strlen($out));
echo $out;
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
else flush();
set_time_limit(20 + 10 * count($orgs));
foreach ($orgs as $o) {
    try { dbxSync($pdo, $o, 8.0); } catch (Throwable $e) { dbxOrgError($pdo, $o, 'webhook_sync'); }
}
