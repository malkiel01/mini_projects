<?php
// זמני: למה הרשימה / הניהול נשברו אחרי #776 (מיקומים וקבוצות). מריץ את הקוד האמיתי ולוכד שגיאות.
$out = [];
try {
    $ro = new PDO('sqlite:platform/data/platform.sqlite', null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
    $out['tables'] = $ro->query("SELECT name, sql FROM sqlite_master WHERE type='table' AND name IN ('grants','groups','placements','app_placed','seeded_groups')")->fetchAll(PDO::FETCH_ASSOC);
    foreach (['groups','placements','app_placed','seeded_groups'] as $t) {
        try { $out['rows'][$t] = $ro->query("SELECT * FROM $t")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $out['rows'][$t] = 'ERR ' . $e->getMessage(); }
    }
    $out['grants_kinds'] = $ro->query("SELECT kind, COUNT(*) n FROM grants GROUP BY kind")->fetchAll(PDO::FETCH_ASSOC);
    $out['sqlite'] = $ro->query('SELECT sqlite_version()')->fetchColumn();
    $out['diag_recent'] = $ro->query("SELECT at, event, app, user_id, path, info FROM diag_log WHERE at >= strftime('%Y-%m-%dT%H:%M:%SZ','now','-30 minutes') ORDER BY id DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $out['ro_error'] = $e->getMessage(); }
try {
    require 'platform/lib/access.php';
    $admin = ['id' => 1, 'role' => 'admin', 'status' => 'active'];
    $v = visible_for($admin);
    $out['admin_apps'] = count($v['apps']);
    $out['admin_groups'] = $v['groups'];
    $out['admin_cats'] = array_column($v['categories'], 'id');
    $out['sample'] = array_map(fn($a) => [$a['id'], $a['placements']], array_slice($v['apps'], 0, 40));
    foreach ($ro->query("SELECT id, role, status FROM users WHERE role <> 'admin'")->fetchAll(PDO::FETCH_ASSOC) as $u) {
        try { $out['users'][$u['id']] = array_column(visible_for($u + ['id' => (int)$u['id']])['apps'], 'id'); }
        catch (Throwable $e) { $out['users'][$u['id']] = 'ERR ' . $e->getMessage(); }
    }
} catch (Throwable $e) { $out['code_error'] = get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(); }
foreach (['error_log', 'platform/error_log', 'platform/lib/error_log'] as $f) {
    if (is_file($f)) $out['errlog'][$f] = array_slice(file($f, FILE_IGNORE_NEW_LINES), -40);
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
