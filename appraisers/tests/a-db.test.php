<?php
// מסד ותשתית — 01-A1, 01-A2, ומקרי הקצה 11 ו-13.
define('APR_DATA_DIR', sys_get_temp_dir() . '/apr-test-' . bin2hex(random_bytes(5)));
define('APR_DB_PATH', APR_DATA_DIR . '/sub/app.sqlite');   // תיקייה שעוד לא קיימת — "data/ ריקה"
require __DIR__ . '/lib.php';

// ── 01-A1: הרצה ראשונה יוצרת מסד, מריצה את כל המיגרציות ויוצרת secret.key; שנייה לא משנה דבר ──
$db = apr_db();
$key = apr_secret();
$want = apr_schema_expected();
check('01-A1 יש לפחות מיגרציה אחת', $want >= 1);
check('01-A1 המסד נוצר בקובץ', is_file(APR_DB_PATH));
eq(apr_schema_version($db), $want, '01-A1 המסד בגרסה שהקוד מצפה לה');
eq((int)$db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn(), $want, '01-A1 כל מיגרציה רשומה');
$tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
foreach (['users', 'sessions', 'offices', 'memberships', 'invitations', 'audit_log', 'jobs', 'meta'] as $t) check("01-A1 הטבלה $t קיימת", in_array($t, $tables, true));
check('01-A1 secret.key נוצר, 32 בתים', is_file(APR_DATA_DIR . '/secret.key') && filesize(APR_DATA_DIR . '/secret.key') === 32);
eq((string)$db->query('PRAGMA journal_mode')->fetchColumn(), 'wal', 'מצב WAL');
eq((int)$db->query('PRAGMA foreign_keys')->fetchColumn(), 1, 'מפתחות זרים דלוקים');

$schema = fn() => json_encode($db->query('SELECT name, sql FROM sqlite_master ORDER BY name')->fetchAll());
$before = [$schema(), hash_file('sha256', APR_DATA_DIR . '/secret.key'), (int)$db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()];
unset($GLOBALS['apr_pdo'], $GLOBALS['apr_secret']);
$db = apr_db();
eq([$schema(), hash_file('sha256', APR_DATA_DIR . '/secret.key'), (int)$db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()], $before, '01-A1 הרצה שנייה אינה משנה דבר');
eq(apr_secret(), $key, '01-A1 אותו מפתח בהרצה השנייה');

// ── מיגרציה 002 עוברת משני המצבים (team/decisions.md): מסד ריק, ומסד שכבר ב-user_version = 1 (כמו בשרת) ──
eq($want >= 2, true, 'יש מיגרציה 002');
$pdoOpt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$shape = fn(PDO $d) => json_encode($d->query("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' AND name NOT LIKE 'dev_%' AND name <> 'schema_migrations' ORDER BY name")->fetchAll());
// מצב א: מסד ריק — 001 ואז 002 ברצף
$fresh = new PDO('sqlite::memory:', null, null, $pdoOpt);
$fresh->exec('PRAGMA foreign_keys = ON');
apr_migrate($fresh);
eq(apr_schema_version($fresh), $want, '002 ממסד ריק: מגיע לגרסה האחרונה');
// מצב ב: מסד ב-user_version = 1, עם שורות בטבלאות של 001 (כולל קשרים), ועם טבלאות DevLoop
$only1 = APR_DATA_DIR . '/mig-001';
mkdir($only1);
copy(__DIR__ . '/../lib/migrations/001_foundation.sql', $only1 . '/001_foundation.sql');
$old = new PDO('sqlite::memory:', null, null, $pdoOpt);
$old->exec('PRAGMA foreign_keys = ON');
apr_migrate($old, $only1);
eq(apr_schema_version($old), 1, 'מסד במצב של השרת: user_version = 1');
$old->exec("INSERT INTO users (platform_sub, email, display_name, is_platform_manager, is_developer, created_at) VALUES ('1', 'owner@example.com', 'הבעלים', 1, 1, '2026-11-10T08:00:00Z');
    INSERT INTO offices (name, created_by, created_at) VALUES ('משרד ישן', 1, '2026-11-10T08:00:00Z');
    INSERT INTO memberships (office_id, user_id, role, created_at) VALUES (1, 1, 'office_admin', '2026-11-10T08:00:00Z');
    INSERT INTO sessions (token_hash, user_id, csrf, office_id, created_at, last_seen) VALUES ('h', 1, 'c', 1, '2026-11-10T08:00:00Z', 1);
    INSERT INTO audit_log (at, office_id, user_id, action) VALUES ('2026-11-10T08:00:00Z', 1, 1, 'office.created');
    INSERT INTO service_state (name) VALUES ('x');
    CREATE TABLE dev_tasks (id INTEGER PRIMARY KEY, code TEXT, created_by INTEGER);
    INSERT INTO dev_tasks (code, created_by) VALUES ('AP-024', 1);");
apr_migrate($old);
eq(apr_schema_version($old), $want, '002 ממסד ב-user_version = 1: מגיע לגרסה האחרונה');
eq($shape($old), $shape($fresh), 'משני המצבים — אותו מבנה בדיוק');
$cols = array_column($old->query('PRAGMA table_info(users)')->fetchAll(), 'name');
check('המבנה המיושר: pid ו-is_sysadmin, בלי is_platform_manager', in_array('pid', $cols, true) && in_array('is_sysadmin', $cols, true) && !in_array('is_platform_manager', $cols, true));
check('service_state נמחקה; invitations, jobs ו-meta נוצרו', !$old->query("SELECT 1 FROM sqlite_master WHERE name = 'service_state'")->fetchColumn()
    && count($old->query("SELECT name FROM sqlite_master WHERE name IN ('invitations', 'jobs', 'meta')")->fetchAll()) === 3);
eq([(int)$old->query('SELECT COUNT(*) FROM users')->fetchColumn(), (int)$old->query('SELECT COUNT(*) FROM audit_log')->fetchColumn()], [0, 0], 'הטבלאות החדשות ריקות');
eq($old->query('SELECT code FROM dev_tasks')->fetchColumn(), 'AP-024', 'טבלאות DevLoop לא נגעו');
eq((int)$old->query('PRAGMA foreign_key_check')->fetchColumn(), 0, 'אין מפתח זר שבור');
apr_migrate($old);
eq((int)$old->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn(), $want, 'הרצה חוזרת לא משנה דבר');
check('מיגרציה 001 זהה למה שב-main (לא משתנה אחרי מיזוג)', trim((string)shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' diff --stat origin/main -- lib/migrations/001_foundation.sql 2>/dev/null')) === '');

// ── 01-A2: מיגרציה שזורקת חריגה אינה מקדמת את הגרסה, והמסד נשאר כפי שהיה ──
$dir = APR_DATA_DIR . '/mig';
mkdir($dir);
foreach (glob(__DIR__ . '/../lib/migrations/*.sql') as $f) copy($f, $dir . '/' . basename($f));
file_put_contents(sprintf('%s/%03d_broken.sql', $dir, $want + 1), "CREATE TABLE half_done (a);\nINSERT INTO half_done VALUES (1);\nCREATE TABLE users (a);");
try { apr_migrate($db, $dir); check('01-A2 המיגרציה השבורה זרקה', false); } catch (PDOException) { check('01-A2 המיגרציה השבורה זרקה', true); }
eq(apr_schema_version($db), $want, '01-A2 הגרסה לא התקדמה');
check('01-A2 שום חצי-שינוי לא נשאר', !$db->query("SELECT 1 FROM sqlite_master WHERE name = 'half_done'")->fetchColumn());
eq($schema(), $before[0], '01-A2 המסד נשאר כפי שהיה');
check('01-A2 הטרנזקציה נסגרה', !$db->inTransaction());

// מיגרציה תקינה אחריה — רצה
unlink(sprintf('%s/%03d_broken.sql', $dir, $want + 1));
file_put_contents(sprintf('%s/%03d_extra.sql', $dir, $want + 1), 'CREATE TABLE extra_ok (a);');
apr_migrate($db, $dir);
eq(apr_schema_version($db), $want + 1, 'מיגרציה חדשה מקדמת את הגרסה באחד');
apr_migrate($db, $dir);
eq((int)$db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn(), $want + 1, 'ולא רצה פעמיים');

// שמות ומספרים: רצופים מ-001
file_put_contents(sprintf('%s/%03d_gap.sql', $dir, $want + 3), 'SELECT 1;');
try { apr_migration_files($dir); check('מספר לא רצוף נדחה', false); } catch (LogicException) { check('מספר לא רצוף נדחה', true); }

// ── אילוצים במסד ──
$now = apr_now();
$u = apr_insert('users', ['pid' => apr_pid(), 'email' => 'a@x.co', 'name' => 'א', 'created_at' => $now, 'updated_at' => $now]);
$o = apr_insert('offices', ['pid' => apr_pid(), 'name' => 'משרד', 'created_at' => $now, 'updated_at' => $now]);
apr_insert('memberships', ['office_id' => $o, 'user_id' => $u, 'role' => 'appraiser', 'created_at' => $now, 'updated_at' => $now]);
foreach (["INSERT INTO memberships (office_id, user_id, role, created_at, updated_at) VALUES ($o, $u, 'staff', 'x', 'x')" => 'חברות כפולה',
          "UPDATE memberships SET role = 'king'" => 'תפקיד לא מוכר',
          "UPDATE users SET status = 'gone'" => 'מצב לא מוכר',
          "INSERT INTO users (pid, email, created_at, updated_at) VALUES ('p2', 'a@x.co', 'x', 'x')" => 'מייל כפול',
          "INSERT INTO memberships (office_id, user_id, role, created_at, updated_at) VALUES (999, $u, 'staff', 'x', 'x')" => 'משרד שלא קיים'] as $sql => $what) {
    try { apr_exec($sql); check("נדחה במסד: $what", false); } catch (PDOException) { check("נדחה במסד: $what", true); }
}

// מזהה ציבורי
$pids = array_map(fn() => apr_pid(), range(1, 200));
check('pid: 16 תווים, אותיות קטנות וספרות, ייחודי', count(array_unique($pids)) === 200 && !array_filter($pids, fn($p) => !preg_match('/^[a-z0-9]{16}$/', $p)));

// apr_tx: חריגה מבטלת הכול
try { apr_tx(function () { apr_exec("UPDATE offices SET name = 'אחר'"); apr_fail('forbidden'); }); } catch (AprError) {}
eq(apr_val('SELECT name FROM offices'), 'משרד', 'apr_tx מבטל בחריגה');

// ── מקרה קצה 13: data/ שאי אפשר ליצור או לכתוב אליה → 503 עם קוד storage, בלי לחשוף נתיב ──
// (תהליך נפרד, כי הנתיב הוא קבוע; "תיקייה" שההורה שלה הוא קובץ — נכשלת גם כשהבדיקה רצה כ-root)
file_put_contents(APR_DATA_DIR . '/not-a-dir', 'x');
$php = 'define("APR_DATA_DIR", ' . var_export(APR_DATA_DIR . '/not-a-dir/data', true) . '); require ' . var_export(__DIR__ . '/../lib/app.php', true) . ';'
    . 'try { apr_db(); echo "opened"; } catch (AprError $e) { echo $e->status, "|", $e->errorCode, "|", $e->getMessage(); }';
$out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d error_log=/dev/null -r ' . escapeshellarg($php) . ' 2>/dev/null');
[$st, $cd, $msg] = array_pad(explode('|', $out, 3), 3, '');
eq([$st, $cd], ['503', 'storage'], 'מקרה 13: 503 storage');
check('מקרה 13: ההודעה למשתמש בלי נתיב', $msg !== '' && !str_contains($msg, '/') && !str_contains($msg, 'not-a-dir'), $out);

done();
