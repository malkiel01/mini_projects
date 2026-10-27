<?php
// זמני: קריאה בלבד — האם הקבצים בשרת הם של הגרסה האחרונה, ומה קרה בבחירה מאנשי הקשר מאז.
$out = [];
foreach (['platform/assets/admin.js', 'platform/assets/app.js', 'platform/assets/app.css', 'platform/lib/invites.php', 'index.html'] as $f) {
    $out['files'][$f] = is_file($f) ? [md5_file($f), gmdate('Y-m-d H:i:s', filemtime($f))] : 'חסר';
}
$a = (string)@file_get_contents('platform/assets/admin.js');
$out['has'] = ['multiple_true' => str_contains($a, "multiple: true"), 'pick_note' => str_contains($a, 'pick-note'), 'invite' => str_contains($a, 'admin_invite_create')];
foreach (['https://mbe-plus.com/mini_projects/platform/assets/admin.js', 'https://mbe-plus.com/mini_projects/platform/home/'] as $u) {
    $ch = curl_init($u);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HEADER => true]);
    $r = (string)curl_exec($ch);
    $hs = substr($r, 0, curl_getinfo($ch, CURLINFO_HEADER_SIZE));
    $body = substr($r, curl_getinfo($ch, CURLINFO_HEADER_SIZE));
    $out['live'][$u] = ['code' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'md5' => md5($body), 'multiple_true' => str_contains($body, 'multiple: true'),
        'headers' => array_values(array_filter(explode("\r\n", $hs), fn($h) => preg_match('/^(cache-control|cf-cache-status|age|expires|last-modified|server):/i', $h)))];
}
$p = new PDO('sqlite:platform/data/platform.sqlite');
$r = $p->query("SELECT at, event, info FROM diag_log WHERE at > '2026-10-27T07:00:00Z' AND (event LIKE '%contacts%' OR event = 'client:page') ORDER BY id DESC LIMIT 20");
$out['log'] = $r->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
