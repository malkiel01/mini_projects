<?php
$p = new PDO('sqlite:platform/data/platform.sqlite', null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
$since = gmdate('Y-m-d\TH:i:s\Z', time() - 6 * 3600);
$r = $p->query("SELECT id,at,event,app,user_id,device,ua,path,info FROM diag_log WHERE at >= '$since' AND (event LIKE '%probe%' OR event LIKE 'client:installed-apps' OR path LIKE '%probe%') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($r, JSON_UNESCAPED_UNICODE);
