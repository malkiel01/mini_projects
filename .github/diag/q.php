<?php
// זמני: קריאה בלבד — מי מציף את האתר (429 לכולם): ספירה לפי נתיב ולפי IP מוסתר ביומן הגישה האחרון.
$home = getenv('HOME');
$out = [];
foreach (glob("$home/access-logs/*") ?: [] as $f) $out['files'][] = basename($f) . ' ' . filesize($f) . ' ' . gmdate('m-d H:i', filemtime($f));
$paths = []; $ips = []; $codes = []; $n = 0; $uas = [];
foreach (["$home/access-logs/mbe-plus.com-ssl_log", "$home/access-logs/mbe-plus.com"] as $f) {
    if (!is_readable($f)) continue;
    foreach (file($f) ?: [] as $line) {
        if (!preg_match('/^(\S+) .*?\[([^\]]+)\] "(\S+) (\S+)[^"]*" (\d{3}) \S+ "[^"]*" "([^"]*)"/', $line, $m)) continue;
        $n++;
        $p = preg_replace('/\?.*/', '', $m[4]);
        $paths[$m[3] . ' ' . $p] = ($paths[$m[3] . ' ' . $p] ?? 0) + 1;
        $ip = substr(hash('sha256', $m[1]), 0, 8);
        $ips[$ip] = ($ips[$ip] ?? 0) + 1;
        $codes[$m[5]] = ($codes[$m[5]] ?? 0) + 1;
        $uas[substr($m[6], 0, 70)] = ($uas[substr($m[6], 0, 70)] ?? 0) + 1;
        $out['first'] ??= $m[2]; $out['last'] = $m[2];
    }
}
arsort($paths); arsort($ips); arsort($uas);
$out += ['lines' => $n, 'codes' => $codes, 'top_paths' => array_slice($paths, 0, 15, true), 'top_ips' => array_slice($ips, 0, 8, true), 'top_ua' => array_slice($uas, 0, 6, true)];
$out['crontab'] = array_values(array_filter(array_map('trim', explode("\n", (string)shell_exec('crontab -l 2>/dev/null'))), fn($l) => $l !== '' && $l[0] !== '#' && !str_starts_with($l, 'SHELL')));
$out['crontab'] = array_map(fn($l) => preg_replace('/[^\s"@]+@[^\s"]+/', '<mail>', $l), $out['crontab']);
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
