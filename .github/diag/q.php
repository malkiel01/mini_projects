<?php
// זמני: קריאה בלבד — האם דף הבית של האפליקציה המותקנת עונה, ומה ביומני השגיאות.
$out = [];
foreach (['https://mbe-plus.com/mini_projects/platform/home/', 'https://mbe-plus.com/mini_projects/', 'https://mbe-plus.com/mini_projects/family-sw.js', 'https://mbe-plus.com/mini_projects/platform/manifest.webmanifest', 'https://mbe-plus.com/mini_projects/platform/api.php?action=me'] as $u) {
    $ch = curl_init($u);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HEADER => true]);
    $r = (string)curl_exec($ch);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $out['http'][$u] = ['code' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'err' => curl_error($ch), 'bytes' => strlen($r) - $hs, 'start' => substr($r, $hs, 120)];
}
foreach (['platform/home/error_log', 'platform/error_log', 'error_log'] as $f) if (is_file($f)) $out['errors'][$f] = array_slice(array_map('trim', file($f)), -6);
$out['load'] = sys_getloadavg();
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
