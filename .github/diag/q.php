<?php
$p = new PDO('sqlite:platform/data/platform.sqlite', null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
$out = ['apk' => [], 'nav' => []];
$out['grants'] = $p->query("SELECT g.user_id, u.display_name, u.role, g.kind, g.target, g.allowed FROM grants g JOIN users u ON u.id = g.user_id WHERE g.target IN ('file-merger','pdf-merger','documents') ORDER BY g.user_id")->fetchAll(PDO::FETCH_ASSOC);
$out['overrides'] = $p->query("SELECT * FROM app_overrides WHERE app_id IN ('file-merger','pdf-merger')")->fetchAll(PDO::FETCH_ASSOC);
$out['users'] = $p->query("SELECT id, display_name, role, status FROM users")->fetchAll(PDO::FETCH_ASSOC);
$out['devices'] = $p->query("SELECT user_id, app_id, state, version FROM device_apps WHERE app_id IN ('file-merger','pdf-merger')")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($out, JSON_UNESCAPED_UNICODE);
