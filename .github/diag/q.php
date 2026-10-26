<?php
// זמני: איתור פרויקט הקניות המשותפות (family-shopping) בשרת — מיקום, מצב, מסד (ספירות בלבד), אילו מפתחות ב-.env (בלי ערכים).
$home = rtrim(getenv('HOME') ?: '/home2/mbeplusc', '/');
$out = ['cwd' => getcwd()];
$cands = [];
foreach (array_merge(glob("$home/public_html/*/offsets.php") ?: [], glob("$home/public_html/*/*/offsets.php") ?: [], glob("$home/*/offsets.php") ?: []) as $f) {
    $d = dirname($f);
    if (is_file("$d/group.php") && is_file("$d/config.php")) $cands[] = $d;
}
$out['found'] = $cands;
foreach ($cands as $d) {
    $i = ['mtime_latest' => null, 'files' => 0];
    $latest = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) { if ($f->isFile()) { $i['files']++; $latest = max($latest, $f->getMTime()); } }
    $i['mtime_latest'] = gmdate('c', $latest);
    $i['uploads'] = count(glob("$d/uploads/*") ?: []);
    $env = is_file("$d/.env") ? file("$d/.env", FILE_IGNORE_NEW_LINES) : [];
    $keys = []; $vals = [];
    foreach ($env as $l) if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/', $l, $m)) { $keys[$m[1]] = $m[2] !== ''; $vals[$m[1]] = trim($m[2], "\"' "); }
    $i['env_keys_set'] = $keys;
    $i['base_url'] = $vals['BASE_URL'] ?? null;
    $i['app_base_path'] = $vals['APP_BASE_PATH'] ?? null;
    try {
        $pdo = new PDO('mysql:host=' . ($vals['DB_HOST'] ?? 'localhost') . ';dbname=' . ($vals['DB_NAME'] ?? '') . ';charset=utf8mb4', $vals['DB_USER'] ?? '', $vals['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $i['tables'] = [];
        foreach ($tables as $t) $i['tables'][$t] = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        foreach (['users', 'purchases', 'purchase_groups'] as $t) if (in_array($t, $tables, true)) {
            $cols = $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN);
            $tc = array_values(array_intersect($cols, ['created_at', 'updated_at', 'last_login', 'last_activity']));
            if ($tc) $i['last_' . $t] = $pdo->query("SELECT MAX(`{$tc[0]}`) FROM `$t`")->fetchColumn();
        }
    } catch (Throwable $e) { $i['db_error'] = $e->getMessage(); }
    $out['apps'][$d] = $i;
}
$out['cron'] = trim((string)shell_exec('crontab -l 2>&1 | grep -i -E "family|notif|shopping" | sed "s/[A-Za-z0-9]\{24,\}/…/g"'));
echo json_encode($out, JSON_UNESCAPED_UNICODE);
