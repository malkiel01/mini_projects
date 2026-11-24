<?php
declare(strict_types=1);

/**
 * API לדפדפן: כניסה, me, יציאה, רשימה, ייצוא, שמירה וייבוא מתוך הדשבורד.
 * מאחורי השער של הפלטפורמה, ובנוסף דורש את הכניסה של האפליקציה (session + CSRF).
 * מערכות חיצוניות עם Authorization: Bearer עובדות מול ingest.php, לא מול הקובץ הזה.
 */
require __DIR__ . '/lib.php';

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('fd_session');
session_set_cookie_params([
    'lifetime' => 0, 'path' => rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/',
    'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict'
]);
session_start();

$cfg = config();
$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$sessionAuth = !empty($_SESSION['fd_auth']);
if ($action === 'login') {
    if ($method !== 'POST') reply(405, ['error'=>'שיטה לא מורשית']);
    $payload = body();
    $user = cleanText($payload['username'] ?? '', 100);
    $password = is_string($payload['password'] ?? null) ? $payload['password'] : '';
    if (strlen($password) > 200) reply(400, ['error'=>'סיסמה ארוכה מדי']);
    if ($user !== ($cfg['username'] ?? 'admin') || !password_verify($password, (string)$cfg['password_hash'])) {
        usleep(350000);
        reply(401, ['error'=>'שם משתמש או סיסמה אינם נכונים']);
    }
    session_regenerate_id(true);
    $_SESSION['fd_auth'] = true;
    $_SESSION['fd_csrf'] = bin2hex(random_bytes(24));
    reply(200, ['ok'=>true,'csrf'=>$_SESSION['fd_csrf']]);
}
if (!$sessionAuth) reply(401, ['error'=>'יש להתחבר למערכת']);
if ($action === 'me') {
    reply(200, ['ok'=>true,'csrf'=>$_SESSION['fd_csrf'] ?? '',
        'embed_key'=>cleanText($cfg['dropbox_app_key'] ?? '', 100)]);
}
if ($action === 'logout') {
    if ($method !== 'POST') reply(403, ['error'=>'אין הרשאה']);
    if (!hash_equals((string)($_SESSION['fd_csrf'] ?? ''), (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) reply(403, ['error'=>'אימות בקשה נכשל']);
    $_SESSION = [];
    session_destroy();
    reply(200, ['ok'=>true]);
}
if ($method === 'POST' &&
    (!isset($_SERVER['HTTP_X_CSRF_TOKEN']) || !hash_equals((string)($_SESSION['fd_csrf'] ?? ''), (string)$_SERVER['HTTP_X_CSRF_TOKEN']))) {
    reply(403, ['error'=>'אימות בקשה נכשל']);
}
$pdo = db();
if ($action === 'list' || $action === 'export') listMonth($pdo, $action, $method);
if ($action === 'save') {
    if ($method !== 'POST') reply(403, ['error'=>'שינוי ידני דורש התחברות']);
    $payload = body();
    $item = normalize($payload);
    $old = existing($pdo, $item['id']);
    if ($old !== null) {
        foreach (['name','date','time','origin','team','grave','notes','folder_url','payments'] as $field) {
            if (($item[$field] ?? null) !== ($old[$field] ?? null)) $item['manual_fields'][] = $field;
        }
        $item['manual_fields'] = array_values(array_unique(array_merge($old['manual_fields'] ?? [], $item['manual_fields'])));
    } else {
        $item['manual_fields'] = ['name','date','time','origin','team','grave','notes','folder_url','payments'];
    }
    foreach ($item['checks'] as $key => &$check) {
        $previous = $old['checks'][$key] ?? null;
        if (is_array($previous) && $previous['status'] === $check['status']
            && $previous['value'] === $check['value'] && $previous['note'] === $check['note']) {
            $check = $previous;
        } elseif ($check['status'] !== 'unknown' || $check['value'] !== '' || $check['note'] !== '') {
            $check['source'] = 'עדכון ידני';
        }
    }
    unset($check);
    store($pdo,$item,'dashboard','save');
    reply(200, ['ok'=>true,'record'=>$item]);
}
if ($action === 'import') importRecords($pdo, $method, 'dashboard');
reply(404, ['error' => 'פעולה לא מוכרת']);
