<?php
// בדיקות 05 בלבד: מכין במסד את הטבלאות של 01 לפי האפיון (01 §2.1) כשהן עוד לא קיימות, ומשנה מצב לבדיקה.
// אחרי מיזוג 01 — CREATE … IF NOT EXISTS לא נוגע במה שהמיגרציה של 01 יצרה. שורת פקודה בלבד.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
[$_, $dbPath, $cmd] = $argv + [null, null, null];
$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA busy_timeout=5000');
switch ($cmd) {
    case 'setup':
        $pdo->exec('CREATE TABLE IF NOT EXISTS orgs (id INTEGER PRIMARY KEY, name TEXT NOT NULL, created_at TEXT, settings TEXT)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, sub TEXT UNIQUE, email TEXT UNIQUE, name TEXT, created_at TEXT, last_seen_at TEXT, active INTEGER NOT NULL DEFAULT 1)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS members (org_id INTEGER, user_id INTEGER, role TEXT, created_at TEXT, created_by INTEGER, PRIMARY KEY(org_id, user_id))');
        $pdo->exec('CREATE TABLE IF NOT EXISTS events (id INTEGER PRIMARY KEY AUTOINCREMENT, org_id INTEGER, case_id TEXT, at TEXT, actor_user_id INTEGER, actor TEXT, source TEXT, action TEXT, diff TEXT)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS req_log (at TEXT, method TEXT, action TEXT, status INTEGER, ms INTEGER, user_id INTEGER, error TEXT)');
        $cols = array_column($pdo->query('PRAGMA table_info(funerals)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('org_id', $cols, true)) $pdo->exec('ALTER TABLE funerals ADD COLUMN org_id INTEGER');
        $pdo->exec("INSERT OR IGNORE INTO orgs(id,name) VALUES(1,'חברה קדישא'),(2,'ארגון בדיקה')");
        $pdo->exec("INSERT OR IGNORE INTO users(id,sub,email,name,active) VALUES(7,'7','tester@example.com','בודק',1),(9,'9','field@example.com','עובד שטח',1),(10,'10','org2@example.com','משרד שני',1),(11,'11','nobody@example.com','בלי ארגון',1)");
        $pdo->exec("INSERT OR IGNORE INTO members(org_id,user_id,role) VALUES(1,7,'admin'),(1,9,'field'),(2,10,'office')");
        $case = static function (string $id, string $name, int $org) use ($pdo) {
            $body = ['id' => $id, 'month' => '2026-10', 'name' => $name, 'date' => '2026-10-05', 'time' => '', 'origin' => 'ירושלים', 'team' => '',
                'grave' => '', 'notes' => '', 'folder_url' => '', 'documents' => [], 'payments' => [['amount' => 100, 'purpose' => 'קבר', 'reference' => '', 'source' => '']],
                'checks' => ['license_document' => ['status' => 'missing', 'value' => '', 'source' => '', 'note' => '']], 'manual_fields' => []];
            $pdo->prepare('INSERT OR REPLACE INTO funerals(id,month,name,body,updated_at,org_id) VALUES(?,?,?,?,?,?)')
                ->execute([$id, '2026-10', $name, json_encode($body, JSON_UNESCAPED_UNICODE), gmdate('c'), $org]);
        };
        $case('fd_case_org1_cohen', 'משה כהן', 1);
        $case('fd_case_org1_cohen2', 'שרה כהן', 1);
        $case('fd_case_org2_levi', 'דוד לוי', 2);
        break;
    case 'deactivate': $pdo->prepare('UPDATE users SET active=0 WHERE sub=?')->execute([$argv[3]]); break;
    case 'role': $pdo->prepare('UPDATE members SET role=? WHERE user_id=(SELECT id FROM users WHERE sub=?)')->execute([$argv[4], $argv[3]]); break;
    case 'expire': $pdo->prepare('UPDATE tokens SET expires_at=? WHERE id=?')->execute([gmdate('c', time() - 60), (int)$argv[3]]); break;
    case 'body': $st = $pdo->prepare('SELECT body FROM funerals WHERE id=?'); $st->execute([$argv[3]]); echo $st->fetchColumn(); break;
    case 'sql': echo json_encode($pdo->query($argv[3])->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE); break;
    default: fwrite(STDERR, "פקודה לא מוכרת\n"); exit(1);
}
