<?php
// זמני, קריאה בלבד — סבב 2: cgroup של החשבון, StatsBar, ופיזור 429 לאורך הזמן.
$O = [];
$sh = function (string $c, int $max = 8000) { $o = @shell_exec('timeout 25 sh -c ' . escapeshellarg($c) . ' 2>&1'); return $o === null ? null : mb_substr($o, 0, $max); };
$home = getenv('HOME');
$O['cg_mount'] = $sh('mount | grep -i cgroup; ls -la /sys/fs/cgroup/ | head -40');
$O['cg_self_dir'] = $sh('ls -la /sys/fs/cgroup/USERS/726/ 2>&1 | head -80; ls /sys/fs/cgroup/USERS 2>&1 | head -5');
foreach (['cpu.max','cpu.stat','cpu.weight','memory.max','memory.high','memory.current','memory.peak','memory.events','memory.stat','pids.max','pids.current','pids.peak','pids.events','io.max','io.stat','io.pressure','cpu.pressure','memory.pressure','cgroup.procs','cgroup.threads','cgroup.stat'] as $f) {
  foreach (['/sys/fs/cgroup/USERS/726/', '/sys/fs/cgroup/'] as $base) {
    $p = $base . $f;
    $O['cg'][$p] = is_readable($p) ? mb_substr((string)@file_get_contents($p), 0, 1500) : (file_exists($p) ? 'EXISTS-UNREADABLE' : 'MISSING');
  }
}
$O['limits'] = $sh('cat /proc/self/limits; ulimit -a');
$O['statsbar'] = $sh("uapi --output=json StatsBar get_stats display='cpuusage|entryprocesses|memoryusage|iousage|iops|numberofprocesses|faults|bandwidthusage|diskusage|physicalmemoryusage|virtualmemoryusage'", 20000);
$O['bandwidth'] = $sh("uapi --output=json Bandwidth query grouping='domain|year_month_day' interval=daily", 20000);
$O['domlogs'] = $sh('ls -la /etc/apache2/logs/domlogs/ 2>&1 | grep -i -e mbeplus -e mbe-plus -e total | head; ls -la /etc/apache2/logs/domlogs/mbeplusc 2>&1 | head');
$O['bins'] = $sh('for b in uapi cpapi2 lveps lveinfo lvectl cloudlinux-statistics quota top vmstat iostat sar; do printf "%s: " $b; command -v $b || echo -; done');
$O['top'] = $sh('top -b -n1 | head -15');
// 429 ושיא לדקה ביומן הראשי
$f = "$home/access-logs/mbe-plus.com-ssl_log"; $perMin = []; $e429 = []; $p429 = []; $sec = [];
foreach (is_readable($f) ? file($f) : [] as $line) {
  if (!preg_match('/\[(\d+\/\w+\/\d+:\d+:\d+):(\d+)[^\]]*\] "(\S+) (\S+)[^"]*" (\d{3})/', $line, $m)) continue;
  $perMin[$m[1]] = ($perMin[$m[1]] ?? 0) + 1;
  $sec[$m[1] . ':' . $m[2]] = ($sec[$m[1] . ':' . $m[2]] ?? 0) + 1;
  if ($m[5] === '429') { $e429[$m[1]] = ($e429[$m[1]] ?? 0) + 1; $pp = preg_replace('/\?.*/', '', $m[4]); $p429[$pp] = ($p429[$pp] ?? 0) + 1; }
}
arsort($p429);
$O['main_per_min'] = $perMin; $O['main_429_per_min'] = $e429; $O['main_429_paths'] = array_slice($p429, 0, 15, true); $O['main_peak_sec'] = $sec ? max($sec) : 0;
$O['now'] = date('c');
echo json_encode($O, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
