<?php
// זמני, קריאה בלבד — האם הפלטפורמה הורידה את המפתח של mbe-cloud ל-grant_status (אחרי #1332). לא משנה כלום.
$out = [];
$app = json_decode((string)@file_get_contents('mbe-cloud/app.json'), true) ?: [];
$out['app_grant_status'] = $app['grant_status'] ?? null;
$out['app_provision'] = isset($app['provision']);
$url = $app['grant_status']['key_url'] ?? null;
if ($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $body = (string)curl_exec($ch);
    $j = json_decode($body, true);
    $out['key_url_http'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $out['key_url_len'] = strlen($body);
    $out['key_url_json_keys'] = is_array($j) ? array_keys($j) : null;
}
try {
    $p = new PDO('sqlite:platform/data/platform.sqlite');
    $rows = $p->query("SELECT client, key_url, keys, fetched_at FROM provision_keys WHERE client LIKE '%mbe-cloud%'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $k = json_decode($r['keys'], true); $r['keys'] = is_array($k) ? count($k) : 0; $r['fetched_at'] = gmdate('c', (int)$r['fetched_at']); }
    $out['provision_keys'] = $rows;
} catch (Throwable $e) { $out['db_error'] = $e->getMessage(); }
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
