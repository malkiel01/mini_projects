<?php
// נתוני בדיקה לפרוסה 06 (CLI בלבד): מנהל ועובד שטח בארגון 1, וארגון שני עם תיק "סודי".
// מניח שהמסד כבר עבר מיגרציה (בקשה ראשונה ל-api.php). php ai-fixture.php <db>
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$pdo = new PDO('sqlite:' . $argv[1]);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$now = gmdate('c');
$user = function (string $sub, string $name, string $email) use ($pdo, $now): int {
    $pdo->prepare('INSERT INTO users(sub, email, name, created_at, last_seen_at, active) VALUES (?,?,?,?,?,1)')->execute([$sub, $email, $name, $now, $now]);
    return (int)$pdo->lastInsertId();
};
$member = fn(int $org, int $uid, string $role) => $pdo->prepare('INSERT INTO members(org_id, user_id, role, created_at) VALUES (?,?,?,?)')->execute([$org, $uid, $role, $now]);
$member(1, $user('701', 'מנהלת בדיקה', 'ai-admin@example.com'), 'admin');
$member(1, $user('702', 'עובד שטח', 'ai-field@example.com'), 'field');
$pdo->prepare('INSERT INTO orgs(id, name, created_at, settings) VALUES (2, ?, ?, \'{}\')')->execute(['ארגון שני', $now]);
$member(2, $user('703', 'משרד שני', 'ai-org2@example.com'), 'admin');
$case = ['id' => 'fd_org2secretcase01', 'month' => '2026-11', 'name' => 'סודי-ארגון-שני', 'date' => '2026-11-02', 'time' => '', 'origin' => '',
    'team' => '', 'grave' => 'חלקה-סודית-77', 'notes' => '', 'folder_url' => '', 'documents' => [], 'checks' => [], 'payments' => [], 'manual_fields' => []];
$pdo->prepare('INSERT INTO funerals(id, month, name, body, updated_at, org_id) VALUES (?,?,?,?,?,2)')
    ->execute([$case['id'], $case['month'], $case['name'], json_encode($case, JSON_UNESCAPED_UNICODE), $now]);
