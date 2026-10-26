<?php
// דמוי UAPI של cPanel לפיתוח מקומי: Email/add_pop ו-delete_pop כותבים לקובץ המשתמשים של dovecot.
// נקרא דרך MB_CPANEL_ENDPOINT (tests/dev-prepend.php). לא נפרס.
header('Content-Type: application/json');
$users = '/etc/dovecot/users';
$path = $_SERVER['PATH_INFO'] ?? '';
$email = strtolower(($_POST['email'] ?? '') . '@' . ($_POST['domain'] ?? ''));
$lines = file_exists($users) ? file($users, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
$has = (bool)array_filter($lines, fn($l) => str_starts_with($l, $email . ':'));
if ($path === '/execute/Email/add_pop') {
    if ($has) exit(json_encode(['status' => 0, 'errors' => ["The account $email already exists!"]]));
    file_put_contents($users, $email . ':{PLAIN}' . $_POST['password'] . "\n", FILE_APPEND);
    exit(json_encode(['status' => 1, 'data' => $email]));
}
if ($path === '/execute/Email/delete_pop') {
    file_put_contents($users, implode("\n", array_filter($lines, fn($l) => !str_starts_with($l, $email . ':'))) . "\n");
    exit(json_encode(['status' => 1, 'data' => null]));
}
if ($path === '/execute/Email/list_pops') exit(json_encode(['status' => 1, 'data' => []]));
echo json_encode(['status' => 0, 'errors' => ['unknown']]);
