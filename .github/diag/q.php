<?php
// זמני: "קניות משותפות" — חלק א' של family-shopping/docs/requests/platform-move-runtime.md (בקשת בעל המערכת, 2026-10-26).
// רק העתקה. form/family לא משתנה. פלט: מספרים בלבד — בלי מיילים.
$home = rtrim(getenv('HOME') ?: '/home2/mbeplusc', '/');
$src = "$home/public_html/form/family";
$dst = getcwd() . '/family-shopping';
$out = ['dst_exists' => is_dir($dst), 'dst_config' => is_file("$dst/config.php")];
// 1. .env — cp -p, בלי עריכה; לא דורסים אם כבר קיים
if (!is_file("$dst/.env")) {
    exec('cp -p ' . escapeshellarg("$src/.env") . ' ' . escapeshellarg("$dst/.env") . ' 2>&1', $o, $rc);
    $out['env_copy_rc'] = $rc;
} else $out['env_copy'] = 'קיים כבר — לא נדרס';
$out['env_same'] = is_file("$dst/.env") && hash_file('sha256', "$src/.env") === hash_file('sha256', "$dst/.env");
$out['env_perms'] = is_file("$dst/.env") ? substr(sprintf('%o', fileperms("$dst/.env")), -4) : null;
// 2. uploads — rsync -a --ignore-existing (לא דורס את .htaccess שהגיע בפריסה)
$before = count(glob("$dst/uploads/*") ?: []);
exec('rsync -a --ignore-existing ' . escapeshellarg("$src/uploads/") . ' ' . escapeshellarg("$dst/uploads/") . ' 2>&1', $o2, $rc2);
$out['uploads_rsync_rc'] = $rc2;
$out['uploads_src'] = count(glob("$src/uploads/*") ?: []);
$out['uploads_dst_before'] = $before;
$out['uploads_dst_after'] = count(glob("$dst/uploads/*") ?: []);
$out['uploads_htaccess_kept'] = is_file("$dst/uploads/.htaccess") && str_contains((string)file_get_contents("$dst/uploads/.htaccess"), 'engine off');
// 3. בדיקה לקריאה בלבד — מספרים בלבד
$env = [];
foreach (file("$dst/.env", FILE_IGNORE_NEW_LINES) ?: [] as $l) if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/', $l, $m)) $env[$m[1]] = trim($m[2], "\"' ");
try {
    $pdo = new PDO('mysql:host=' . ($env['DB_HOST'] ?? 'localhost') . ';dbname=' . ($env['DB_NAME'] ?? '') . ';charset=utf8mb4', $env['DB_USER'] ?? '', $env['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $q = fn($s) => $pdo->query($s)->fetchAll(PDO::FETCH_ASSOC);
    $out['q1'] = $q("SELECT COUNT(*) AS users_total, SUM(email IS NULL OR email = '') AS users_without_email, SUM(is_active = 0) AS users_inactive FROM users");
    $out['q2'] = $q("SELECT COUNT(*) AS duplicate_email_groups FROM (SELECT LOWER(TRIM(email)) e FROM users WHERE email IS NOT NULL AND email <> '' GROUP BY e HAVING COUNT(*) > 1) d");
    $out['q3'] = $q('SELECT auth_type, COUNT(*) AS n FROM users GROUP BY auth_type');
    $out['q4'] = $q("SELECT COUNT(*) AS pending_invitations FROM group_invitations WHERE status = 'pending'");
    $out['q5'] = array_map(fn($c) => [$c['Field'], $c['Type'], $c['Null'], $c['Key'], $c['Default']], $q('SHOW COLUMNS FROM users'));
} catch (Throwable $e) { $out['db_error'] = $e->getMessage(); }
// 4. הקוד בתיקייה החדשה מתחבר למסד — בלי לעבד את תור ההתראות
$out['connect'] = trim((string)shell_exec('cd ' . escapeshellarg($dst) . ' && php -r ' . escapeshellarg('require "config.php"; getDBConnection(); echo "ok\n";') . ' 2>&1'));
// ‎.env לא נגיש מבחוץ
$ch = curl_init('https://mbe-plus.com/mini_projects/family-shopping/.env');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_NOBODY => true]);
curl_exec($ch);
$out['env_http'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$out['src_untouched_env'] = is_file("$src/.env");
echo json_encode($out, JSON_UNESCAPED_UNICODE);
