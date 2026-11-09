<?php
// זמני, קריאה בלבד — סבב 3 (בדיקת עומסים): crontab, ומי פונה לשרת מתוך יומני הגישה.
$O = []; $home = getenv('HOME');
$O['now'] = date('c');
$O['crontab'] = (string)@shell_exec('crontab -l 2>&1');
$O['loadavg'] = @file_get_contents('/proc/loadavg');
$files = glob("$home/access-logs/*") ?: [];
foreach ($files as $f) {
  if (!is_readable($f) || !is_file($f) || filesize($f) == 0 || filemtime($f) < time() - 86400*3) continue;
  $site = basename($f); $r = ['size' => filesize($f), 'lines' => 0, 'first' => null, 'last' => null, 'codes' => [], 'hours' => [], 'apps' => [], 'ua' => [], 'actions' => [], 'ua_app' => [], 'min_peak' => [], 'e429' => []];
  $h = fopen($f, 'r'); $perMin = [];
  while (($line = fgets($h)) !== false) {
    if (!preg_match('/^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) (\S+)[^"]*" (\d{3}) \S+(?: "[^"]*" "([^"]*)")?/', $line, $m)) continue;
    $r['lines']++; $r['first'] ??= $m[2]; $r['last'] = $m[2];
    $r['codes'][$m[5]] = ($r['codes'][$m[5]] ?? 0) + 1;
    $hr = substr($m[2], 0, 14); $r['hours'][$hr] = ($r['hours'][$hr] ?? 0) + 1;
    $mn = substr($m[2], 0, 17); $perMin[$mn] = ($perMin[$mn] ?? 0) + 1;
    $path = $m[4]; $app = preg_match('#^/mini_projects/([^/?]+)#', $path, $a) ? $a[1] : (explode('/', ltrim(preg_replace('/\?.*/', '', $path), '/'))[0] ?: '/');
    $r['apps'][$app] = ($r['apps'][$app] ?? 0) + 1;
    $ua = $m[6] ?? '-'; $uak = preg_match('#^(curl|python-requests|Go-http-client|node|axios|wget|okhttp|Dalvik)[/ ]#i', $ua, $u) ? strtolower($u[1]) : (preg_match('/bot|crawl|spider|scan|censys/i', $ua) ? 'bot' : (preg_match('/selftest|heartbeat|WordPress/i', $ua, $u2) ? 'internal' : (preg_match('/Android/', $ua) ? 'android' : (preg_match('/iPhone|iPad/', $ua) ? 'ios' : (preg_match('/Mozilla/', $ua) ? 'desktop' : 'other')))));
    $r['ua'][$uak] = ($r['ua'][$uak] ?? 0) + 1;
    $k = $app . ' | ' . $uak; $r['ua_app'][$k] = ($r['ua_app'][$k] ?? 0) + 1;
    if (preg_match('/[?&]action=([\w-]+)/', $path, $ac)) { $k2 = $app . ':' . $ac[1]; $r['actions'][$k2] = ($r['actions'][$k2] ?? 0) + 1; }
    if ($m[5] === '429' || $m[5] === '508' || $m[5][0] === '5') { $k3 = $m[5] . ' ' . $app; $r['e429'][$k3] = ($r['e429'][$k3] ?? 0) + 1; }
  }
  fclose($h);
  arsort($r['apps']); arsort($r['ua']); arsort($r['actions']); arsort($r['ua_app']); arsort($perMin);
  $r['apps'] = array_slice($r['apps'], 0, 25, true); $r['actions'] = array_slice($r['actions'], 0, 30, true); $r['ua_app'] = array_slice($r['ua_app'], 0, 30, true);
  $r['min_peak'] = array_slice($perMin, 0, 5, true);
  $O['logs'][$site] = $r;
}
echo json_encode($O, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
