<?php
$p = new PDO('sqlite:platform/data/platform.sqlite', null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
$out = ['apk' => [], 'nav' => []];
try { $out['apk'] = $p->query("SELECT user_id, device_id, app_id, version, source, device, checked_at FROM device_apps WHERE how = 'apk' ORDER BY checked_at DESC")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $out['apk_err'] = $e->getMessage(); }
$since = gmdate('Y-m-d\TH:i:s\Z', time() - 4 * 3600);
$out['nav'] = $p->query("SELECT at, event, app, user_id, substr(path,1,80) path, substr(info,1,160) info FROM diag_log WHERE at >= '$since' AND (event LIKE 'gate:%' OR event LIKE 'client:apk%' OR event LIKE 'client:open%') ORDER BY id DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($out, JSON_UNESCAPED_UNICODE);
