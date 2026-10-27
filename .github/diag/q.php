<?php
// זמני: קריאה בלבד — האם קוד קניות משותפות בשרת עדכני, ומאיפה בעל המערכת נכנס אליה.
$out = [];
foreach (['family-shopping/includes/layout.php', 'family-shopping/css/app.css', 'family-shopping/dashboard.php', 'family-shopping/service-worker.js'] as $f) {
    $out['files'][$f] = is_file($f) ? [md5_file($f), gmdate('Y-m-d H:i:s', filemtime($f))] : 'חסר';
}
$l = (string)@file_get_contents('family-shopping/includes/layout.php');
$out['layout_has_menu'] = substr_count($l, 'menu');
preg_match_all('/app\.css[^"\']*/', $l, $m); $out['css_ref'] = $m[0];
$p = new PDO('sqlite:platform/data/platform.sqlite');
$r = $p->query("SELECT at, event, path, referrer, info FROM diag_log WHERE at > '2026-10-27T06:00:00Z' AND (app = 'family-shopping' OR path LIKE '%family%') ORDER BY id DESC LIMIT 25");
$out['log'] = $r->fetchAll(PDO::FETCH_ASSOC);
$v = $p->query("SELECT at, app, device FROM visits WHERE at > '2026-10-27T06:00:00Z' AND app = 'family-shopping' ORDER BY id DESC LIMIT 10");
$out['visits'] = $v->fetchAll(PDO::FETCH_ASSOC);
// האתר הישן — האם יש בו service worker שעוד רשום אצל משתמשים
$old = getenv('HOME') . '/public_html/form/family';
$out['old_site'] = ['sw' => is_file("$old/service-worker.js"), 'htaccess' => (string)@file_get_contents("$old/.htaccess")];
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
