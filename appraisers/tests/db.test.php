<?php
// המסד והמיגרציות: רצות מאפס, רשומות, לא רצות פעמיים, והגרסה תואמת לקוד.
require __DIR__ . '/lib.php';

$db = apr_db();
$want = apr_schema_expected();
check('יש לפחות מיגרציה אחת', $want >= 1);
eq(apr_schema_version($db), $want, 'המסד בגרסה שהקוד מצפה לה');
eq((int)$db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn(), $want, 'כל מיגרציה רשומה');
$tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
foreach (['users', 'sessions', 'offices', 'memberships', 'audit_log', 'service_state'] as $t) check("הטבלה $t קיימת", in_array($t, $tables, true));

apr_migrate($db);   // שוב — לא עושה כלום ולא נכשל
eq((int)$db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn(), $want, 'הרצה חוזרת לא מוסיפה');
eq((int)$db->query('PRAGMA foreign_keys')->fetchColumn(), 1, 'מפתחות זרים נאכפים');

// מיגרציה שנכשלת באמצע — מתבטלת כולה, והגרסה לא מתקדמת
$bad = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
apr_migrate($bad);
$bad->exec('BEGIN IMMEDIATE');
try { $bad->exec('CREATE TABLE x1 (a); CREATE TABLE users (a);'); $bad->exec('COMMIT'); } catch (Throwable) { $bad->exec('ROLLBACK'); }
check('טרנזקציה שנכשלה לא משאירה חצי', !$bad->query("SELECT 1 FROM sqlite_master WHERE name = 'x1'")->fetchColumn());

// אילוצים: תפקיד ומצב רק מהרשימה, חברות אחת למשתמש במשרד
apr_exec("INSERT INTO users (email, display_name, created_at) VALUES ('a@x.co', 'א', ?)", [apr_now()]);
apr_exec("INSERT INTO offices (name, created_at) VALUES ('משרד', ?)", [apr_now()]);
apr_exec("INSERT INTO memberships (office_id, user_id, role, created_at) VALUES (1, 1, 'appraiser', ?)", [apr_now()]);
foreach (["INSERT INTO memberships (office_id, user_id, role, created_at) VALUES (1, 1, 'staff', 'x')" => 'חברות כפולה',
          "UPDATE memberships SET role = 'king'" => 'תפקיד לא מוכר',
          "UPDATE users SET status = 'gone'" => 'מצב לא מוכר',
          "INSERT INTO memberships (office_id, user_id, role, created_at) VALUES (99, 1, 'staff', 'x')" => 'משרד שלא קיים'] as $sql => $what) {
    try { apr_exec($sql); check("נדחה: $what", false); } catch (PDOException) { check("נדחה: $what", true); }
}
eq(apr_row('SELECT id FROM users WHERE email = ?', ['A@X.CO'])['id'] ?? null, 1, 'מייל — בלי תלות באותיות גדולות');

// apr_tx: חריגה מבטלת הכול
try { apr_tx(function () { apr_exec("UPDATE offices SET name = 'אחר'"); throw new AprError('עצור'); }); } catch (AprError) {}
eq(apr_row('SELECT name FROM offices')['name'], 'משרד', 'apr_tx מבטל בחריגה');

done();
