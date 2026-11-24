<?php
declare(strict_types=1);

/**
 * API לדפדפן: me, יציאה, יצירת אסימון API, רשימה, ייצוא, שמירה וייבוא מתוך הדשבורד.
 * הזהות מגיעה מהשער של הפלטפורמה בלבד ($_SERVER['PLATFORM_USER'] עם granted === true) — אין כניסה בסיסמה.
 * ה-session משמש ל-CSRF בלבד, וקשור ל-sub של המשתמש מהשער.
 * מערכות חיצוניות עם Authorization: Bearer עובדות מול ingest.php, לא מול הקובץ הזה.
 */
require __DIR__ . '/lib.php';

$user = platformUser();
if ($user === null) reply(401, ['error' => 'אין הרשאה לאפליקציה. יש להיכנס דרך הפלטפורמה ולקבל אישור לאפליקציה.']);

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('fd_session');
session_set_cookie_params([
    'lifetime' => 0, 'path' => rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/',
    'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict'
]);
session_start();
// session של משתמש אחר (או בלי sub) — מתחילים חדש, עם CSRF חדש
if (($_SESSION['fd_sub'] ?? null) !== $user['sub']) {
    session_regenerate_id(true);
    $_SESSION = ['fd_sub' => $user['sub'], 'fd_csrf' => bin2hex(random_bytes(24))];
}

$cfg = config();
$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($action === 'me') {
    reply(200, ['ok'=>true,'csrf'=>$_SESSION['fd_csrf'],'user'=>['name'=>$user['name'],'email'=>$user['email']],
        'has_api_token'=>($cfg['api_token_sha256'] ?? '') !== '',
        'embed_key'=>cleanText($cfg['dropbox_app_key'] ?? '', 100)]);
}
if ($method !== 'GET' &&
    (!isset($_SERVER['HTTP_X_CSRF_TOKEN']) || !hash_equals((string)$_SESSION['fd_csrf'], (string)$_SERVER['HTTP_X_CSRF_TOKEN']))) {
    reply(403, ['error'=>'אימות בקשה נכשל']);
}
if ($action === 'logout') {
    if ($method !== 'POST') reply(405, ['error'=>'שיטה לא מורשית']);
    $_SESSION = [];
    session_destroy();
    reply(200, ['ok'=>true]);
}
if ($action === 'api_token') {
    // יוצר אסימון חדש ומבטל את הקודם. האסימון מוחזר פעם אחת; בשרת נשמר רק ה-SHA-256 שלו.
    if ($method !== 'POST') reply(405, ['error'=>'שיטה לא מורשית']);
    $token = 'fd_' . bin2hex(random_bytes(32));
    $cfg['api_token_sha256'] = hash('sha256', $token);
    writeConfig($cfg);
    db()->prepare('INSERT INTO audit(funeral_id,at,actor,action) VALUES(?,?,?,?)')
        ->execute(['-', gmdate('c'), $user['actor'], 'api_token']);
    reply(200, ['ok'=>true,'token'=>$token]);
}
$pdo = db();
if ($action === 'list' || $action === 'export') listMonth($pdo, $action, $method);
if ($action === 'save') {
    if ($method !== 'POST') reply(405, ['error'=>'שיטה לא מורשית']);
    $item = formSaveCase($pdo, body(), 'ui', $user['actor']);   // lib/form.php — manual_fields ומקור לבדיקה
    reply(200, ['ok'=>true,'record'=>$item]);
}
if ($action === 'history') formHistoryAction($pdo, $method, null);   // TODO(01): הארגון הפעיל במקום null
if ($action === 'import') importRecords($pdo, $method, $user['actor']);
reply(404, ['error' => 'פעולה לא מוכרת']);
