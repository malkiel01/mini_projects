<?php
// בדיקות 05 בלבד: משתמשים, חברויות ותיקים על המסד שהמיגרציה של 01 יצרה, ושינויי מצב לבדיקה. שורת פקודה בלבד.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
[$_, $dbPath, $cmd] = $argv + [null, null, null];
$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA busy_timeout=5000');
switch ($cmd) {
    case 'setup':   // המסד כבר עבר את המיגרציה של 01 (הבקשה הראשונה ל-ext.php)
        $now = gmdate('c');
        $pdo->exec("INSERT OR IGNORE INTO orgs(id,name,created_at) VALUES(2,'ארגון בדיקה','$now')");
        $pdo->exec("INSERT OR IGNORE INTO users(id,sub,email,name,created_at,active) VALUES(7,'7','tester@example.com','בודק','$now',1),(9,'9','field@example.com','עובד שטח','$now',1),(10,'10','org2@example.com','משרד שני','$now',1),(11,'11','nobody@example.com','בלי ארגון','$now',1)");
        $pdo->exec("INSERT OR IGNORE INTO members(org_id,user_id,role,created_at) VALUES(1,7,'admin','$now'),(1,9,'field','$now'),(2,10,'office','$now')");
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
    case 'member_off': $pdo->prepare('UPDATE members SET active=0 WHERE user_id=(SELECT id FROM users WHERE sub=?)')->execute([$argv[3]]); break;
    case 'role': $pdo->prepare('UPDATE members SET role=? WHERE user_id=(SELECT id FROM users WHERE sub=?)')->execute([$argv[4], $argv[3]]); break;
    case 'expire': $pdo->prepare('UPDATE tokens SET expires_at=? WHERE id=?')->execute([gmdate('c', time() - 60), (int)$argv[3]]); break;
    case 'body': $st = $pdo->prepare('SELECT body FROM funerals WHERE id=?'); $st->execute([$argv[3]]); echo $st->fetchColumn(); break;
    case 'sql': echo json_encode($pdo->query($argv[3])->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE); break;
    default: fwrite(STDERR, "פקודה לא מוכרת\n"); exit(1);
}
