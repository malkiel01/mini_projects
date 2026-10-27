<?php
// זמני: קריאה בלבד — מה קרה לבקשת השיתוף (share=contact): יומני הגישה והשגיאות, בלי כתובות IP.
$home = getenv('HOME');
$out = ['hits' => [], 'errors' => []];
foreach (glob("$home/access-logs/*") ?: [] as $f) {
    if (!is_file($f) || !preg_match('/^mbe-plus\.com/', basename($f))) continue;
    $out['files'][] = basename($f) . ' ' . filesize($f) . ' ' . gmdate('H:i', filemtime($f));
    foreach (file($f) ?: [] as $line) if (str_contains($line, 'share=contact') || str_contains($line, 'probe-contacts')) $out['hits'][] = preg_replace('/^\S+/', '<ip>', trim($line));
}
foreach (['platform/home/error_log', 'platform/error_log', 'error_log', "$home/logs/error_log"] as $f) {
    if (is_file($f)) $out['errors'][$f] = array_slice(array_map('trim', file($f)), -8);
}
// ניסוי מהשרת עצמו: POST עם vcf — מה חוזר
$tmp = tempnam(sys_get_temp_dir(), 'v');
file_put_contents($tmp, "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Test\r\nTEL:0500000000\r\nEND:VCARD\r\n");
$ch = curl_init('https://mbe-plus.com/mini_projects/platform/home/?share=contact');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HEADER => true, CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => ['contact' => new CURLFile($tmp, 'text/x-vcard', 'c.vcf'), 'title' => '', 'text' => '']]);
$r = (string)curl_exec($ch);
$out['self_test'] = ['code' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'head' => substr($r, 0, 600), 'err' => curl_error($ch)];
unlink($tmp);
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
