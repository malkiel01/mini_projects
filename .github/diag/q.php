<?php
// זמני: קריאה בלבד — האם החסימה (429) השתחררה, וכמה בקשות ללוח בדקות האחרונות.
$out = [];
foreach (['https://mbe-plus.com/mini_projects/platform/home/', 'https://mbe-plus.com/mini_projects/platform/api.php?action=me'] as $u) {
    $ch = curl_init($u);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    curl_exec($ch);
    $out['http'][$u] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
}
$f = getenv('HOME') . '/access-logs/mbe-plus.com-ssl_log';
$per = [];
foreach (is_readable($f) ? file($f) : [] as $line) {
    if (!str_contains($line, 'claude-tasks/api.php')) continue;
    if (preg_match('/\[(\d+\/\w+\/\d+:\d+:\d+)/', $line, $m)) $per[$m[1]] = ($per[$m[1]] ?? 0) + 1;
}
$out['board_per_minute'] = array_slice($per, -12, null, true);
echo json_encode($out, JSON_UNESCAPED_SLASHES);
