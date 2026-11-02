<?php
// זמני, קריאה בלבד: מה חשבון האחסון חושף למוניטור השרת (server-monitor/docs/spec.md).
// לא כותב דבר, לא נוגע בשום מסד. IP מוחלף בגיבוב קצר.
$O = [];
$sh = function (string $c, int $max = 6000) { $o = @shell_exec('timeout 25 ' . $c . ' 2>&1'); return $o === null ? null : mb_substr($o, 0, $max); };
$home = getenv('HOME');
$O['php'] = [PHP_VERSION, PHP_SAPI, 'disable_functions' => ini_get('disable_functions'), 'memory_limit' => ini_get('memory_limit'), 'max_execution_time' => ini_get('max_execution_time')];
$O['id'] = $sh('id; hostname; uname -r; nproc; cat /proc/loadavg; uptime');
$O['meminfo'] = $sh('head -5 /proc/meminfo');
$O['cgroup_self'] = $sh('cat /proc/self/cgroup');
$O['lve_proc'] = $sh('ls -la /proc/lve 2>&1; cat /proc/lve/list 2>&1 | head -5');
$O['bins'] = $sh('for b in uapi cpapi2 lveps lveinfo lvectl cloudlinux-statistics lve-read-snapshot cagefsctl selectorctl quota; do printf "%s: " $b; command -v $b || echo -; done');
foreach ([
  'resource_usage' => 'uapi --output=json ResourceUsage get_usages',
  'lve_info'       => 'uapi --output=json LVEInfo get_lve_info',
  'stats_bar'      => 'uapi --output=json StatsBar get_stats display=cpu_usage|entry_processes|memory_usage|io_usage|iops|number_of_processes|faults|bandwidthusage|diskusage',
  'quota'          => 'uapi --output=json Quota get_quota_info',
  'domains'        => 'uapi --output=json DomainInfo list_domains',
  'bandwidth'      => 'uapi --output=json Bandwidth query grouping=domain|year_month interval=daily',
] as $k => $c) {
  $raw = $sh($c, 40000);
  $j = json_decode((string)$raw, true);
  $O['uapi'][$k] = $j['result'] ?? $raw;
}
$O['lveps'] = $sh('lveps -p 2>&1 | head -20');
$O['lve_snapshot'] = $sh('lve-read-snapshot --help 2>&1 | head -5');
$O['procs'] = $sh('ps -u "$(id -un)" -o pid,ppid,etime,pcpu,rss,comm,args --sort=-pcpu | cut -c1-200 | head -40');
$O['procs_total_visible'] = $sh('ps -e --no-headers | wc -l');
$O['crontab'] = $sh('crontab -l');
$O['logs_dirs'] = $sh("ls -la '$home/access-logs/' '$home/logs/' 2>&1 | head -60");
$O['tmp_awstats'] = $sh("ls '$home/tmp' 2>&1 | head; ls '$home/tmp/awstats' 2>&1 | head -40");
// יומני גישה: פורמט, טווח זמן, קודים, נפח לשעה, מגישים
$files = glob("$home/access-logs/*") ?: [];
foreach ($files as $f) {
  $b = basename($f); $r = ['size' => @filesize($f), 'mtime' => @date('c', filemtime($f)), 'readable' => is_readable($f)];
  if ($r['readable'] && is_file($f)) {
    $h = fopen($f, 'r'); $n = 0; $codes = []; $hours = []; $first = null; $last = null; $sample = null; $ua = []; $paths = []; $ips = []; $bad = [];
    while (($line = fgets($h)) !== false) {
      $n++;
      if (!preg_match('/^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) (\S+)[^"]*" (\d{3}) (\S+)(?: "([^"]*)" "([^"]*)")?/', $line, $m)) { if (count($bad) < 2) $bad[] = mb_substr($line, 0, 200); continue; }
      $first ??= $m[2]; $last = $m[2];
      $sample ??= preg_replace('/^\S+/', 'IP#' . substr(md5($m[1]), 0, 6), mb_substr($line, 0, 400));
      $codes[$m[5]] = ($codes[$m[5]] ?? 0) + 1;
      $hr = substr($m[2], 0, 14); $hours[$hr] = ($hours[$hr] ?? 0) + 1;
      $p = preg_replace('/\?.*/', '', $m[4]); $p = implode('/', array_slice(explode('/', $p), 0, 4));
      $paths[$p] = ($paths[$p] ?? 0) + 1;
      $u = mb_substr($m[8] ?? '', 0, 60); $ua[$u] = ($ua[$u] ?? 0) + 1;
      $ip = substr(md5($m[1]), 0, 6); $ips[$ip] = ($ips[$ip] ?? 0) + 1;
    }
    fclose($h);
    arsort($paths); arsort($ua); arsort($ips);
    $r += ['lines' => $n, 'first' => $first, 'last' => $last, 'codes' => $codes, 'per_hour' => array_slice($hours, -30, null, true),
           'top_paths' => array_slice($paths, 0, 12, true), 'top_ua' => array_slice($ua, 0, 8, true), 'top_ip_hash' => array_slice($ips, 0, 8, true),
           'sample' => $sample, 'unparsed' => $bad];
  }
  $O['access_logs'][$b] = $r;
}
// יומן שגיאות של PHP/Apache בחשבון
$O['error_logs'] = $sh("find '$home/public_html' -maxdepth 3 -name error_log -printf '%s %TY-%Tm-%Td %p\\n' 2>/dev/null | head -30");
$O['apache_err'] = $sh("ls -la '$home/logs' 2>&1 | head; tail -5 '$home/public_html/error_log' 2>&1 | cut -c1-300");
// הפלטפורמה: מבנה visits ו-diag_log (קריאה בלבד)
try {
  $p = new PDO('sqlite:platform/data/platform.sqlite', null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
  foreach (['visits', 'diag_log'] as $t) {
    $O['platform'][$t]['cols'] = array_column($p->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $O['platform'][$t]['count'] = $p->query("SELECT COUNT(*) FROM $t")->fetchColumn();
  }
  $O['platform']['visits_by_app_7d'] = $p->query("SELECT * FROM visits ORDER BY rowid DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $O['platform_err'] = $e->getMessage(); }
// גודל נתוני האפליקציות
$O['data_sizes'] = $sh("du -sh */data 2>/dev/null | sort -h | tail -25");
// כותרות תגובה של השרת (סוג שרת, מטמון)
$ch = curl_init('https://mbe-plus.com/mini_projects/'); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 15]);
$O['headers'] = curl_exec($ch);
echo json_encode($O, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
