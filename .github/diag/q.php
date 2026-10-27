<?php
// זמני: קריאה בלבד — מה קרה לבקשת השיתוף (share=contact): יומני הגישה והשגיאות, בלי כתובות IP.
$home = getenv('HOME');
$out = ['hits' => [], 'errors' => []];
foreach (glob("$home/access-logs/*") ?: [] as $f) {
    if (!is_file($f) || !preg_match('/^mbe-plus\.com/', basename($f))) continue;
    $out['files'][] = basename($f) . ' ' . filesize($f) . ' ' . gmdate('H:i', filemtime($f));
    foreach (file($f) ?: [] as $line) if (str_contains($line, 'share=contact') || str_contains($line, 'POST /mini_projects/platform/home')) $out["hits"][] = preg_replace('/^\S+/', '<ip>', trim($line));
}
foreach (['platform/home/error_log', 'platform/error_log', 'error_log', "$home/logs/error_log"] as $f) {
    if (is_file($f)) $out['errors'][$f] = array_slice(array_map('trim', file($f)), -8);
}
// (בלי ניסוי עצמי הפעם)
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
