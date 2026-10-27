<?php
// זמני: קריאה בלבד — ניסיונות הבחירה מאנשי הקשר מאז 08:15, והאם הגיעה בקשת שיתוף (share=contact).
$p = new PDO('sqlite:platform/data/platform.sqlite');
$r = $p->query("SELECT at, event, path, ua, info FROM diag_log WHERE at > '2026-10-27T08:15:00Z' AND (event LIKE '%contacts%' OR path LIKE '%platform/home%') ORDER BY id DESC LIMIT 30");
$out['log'] = $r->fetchAll(PDO::FETCH_ASSOC);
$home = getenv('HOME');
$hits = [];
foreach (array_merge(glob("$home/access-logs/*") ?: [], glob("$home/logs/*mbe-plus.com*") ?: []) as $f) {
    if (!is_file($f) || filesize($f) > 300e6) continue;
    $fh = str_ends_with($f, '.gz') ? gzopen($f, 'r') : fopen($f, 'r');
    while ($fh && ($line = (str_ends_with($f, '.gz') ? gzgets($fh) : fgets($fh))) !== false) {
        if (str_contains($line, 'share=contact')) $hits[] = basename($f) . ': ' . preg_replace('/^\S+/', '<ip>', trim($line));
    }
    $fh && (str_ends_with($f, '.gz') ? gzclose($fh) : fclose($fh));
}
$out['share_requests'] = array_slice($hits, -10);
$out['log_files'] = array_map('basename', array_merge(glob("$home/access-logs/*") ?: [], glob("$home/logs/*") ?: []));
$ch = curl_init('https://mbe-plus.com/mini_projects/platform/manifest.webmanifest');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
$m = json_decode((string)curl_exec($ch), true);
$out['manifest_share_target'] = $m['share_target'] ?? null;
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
