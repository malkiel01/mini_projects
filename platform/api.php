<?php
/**
 * ה-API של הפלטפורמה.
 *
 *   GET  ?action=me        מי מחובר, אסימון CSRF, והאם נדרשת התקנה
 *   GET  ?action=apps      האפליקציות שהמשתמש רשאי לראות
 *   POST ?action=login     {username, password}
 *   POST ?action=logout
 *   POST ?action=register  {username, password, display_name} → ממתין לאישור
 *   POST ?action=setup     {code, username, password, display_name} → המנהל הראשון
 *   POST ?action=password  {current, password} — החלפת סיסמה (גם אחרי סיסמה זמנית מהמנהל)
 *
 * פעולות מנהל (lib/admin.php): GET admin_state, ו-POST admin_* — כולן דורשות
 * מנהל מחובר ו-X-CSRF.
 */

declare(strict_types=1);

require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/access.php';
require __DIR__ . '/lib/admin.php';
require_once __DIR__ . '/lib/push.php';

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        match ($action) {
            'me'   => action_me(),
            'apps' => action_apps(),
            'admin_state' => admin_state(require_admin()),
            'admin_push'  => admin_push_state(require_admin()),
            'admin_usage' => admin_usage(require_admin(), $_GET),
            default => throw new ApiError('פעולה לא מוכרת', 404),
        };
    }
    if ($method === 'POST') {
        $body = json_body();
        match ($action) {
            'login'    => action_login($body),
            'logout'   => action_logout(),
            'register' => action_register($body),
            'setup'    => action_setup($body),
            'password' => action_password($body),
            default    => str_starts_with($action, 'admin_')
                ? admin_dispatch($action, $body, require_admin(true))
                : throw new ApiError('פעולה לא מוכרת', 404),
        };
    }
    throw new ApiError('שיטה לא נתמכת', 405);
} catch (ApiError $e) {
    json_out(['error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log('platform api: ' . $e);
    json_out(['error' => 'שגיאה בשרת'], 500);
}

function public_user(array $u): array
{
    return [
        'username'     => $u['username'],
        'display_name' => $u['display_name'],
        'role'         => $u['role'],
        'status'       => $u['status'],
        'must_change'  => (bool)($u['must_change_pw'] ?? false),
    ];
}

function action_me(): never
{
    $user = current_user();
    json_out([
        'user'        => $user ? public_user($user) : null,
        'csrf'        => $user['csrf'] ?? null,
        'setupNeeded' => !users_exist(),
        'setupReady'  => setup_code_hash() !== null,
        // למנהל: כמה ממתינים לאישור — המונה בכותרת.
        'pending'     => ($user['role'] ?? '') === 'admin'
            ? (int)db()->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn()
            : 0,
    ]);
}

function action_apps(): never
{
    $user = current_user();
    if (!$user) throw new ApiError('נדרשת כניסה', 401);

    json_out(visible_for($user));
}

function action_login(array $body): never
{
    $username = is_string($body['username'] ?? null) ? trim($body['username']) : '';
    $password = is_string($body['password'] ?? null) ? $body['password'] : '';
    $keys = ['u:' . mb_strtolower($username), 'ip:' . client_ip()];

    $wait = lock_remaining($keys);
    if ($wait > 0) {
        throw new ApiError('יותר מדי ניסיונות. נסו שוב בעוד ' . (int)ceil($wait / 60) . ' דקות', 429);
    }

    $st = db()->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([$username]);
    $user = $st->fetch();

    // גם למשתמש שאינו קיים מריצים password_verify — כדי שזמן התגובה לא יסגיר
    // אילו שמות משתמש קיימים.
    $hash = $user['password_hash'] ?? '$2y$10$rxAGOCUktx7c3KoNsrn5quXX6u4.mqw4TkeXZk1yylgcrXjz.UZFa';
    $ok = password_verify($password, $hash) && $user;

    if (!$ok) {
        record_failure($keys);
        throw new ApiError('שם משתמש או סיסמה שגויים', 401);
    }
    if ($user['status'] === 'blocked') throw new ApiError('החשבון חסום', 403);

    clear_failures($keys);
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    start_session((int)$user['id']);
    json_out(['ok' => true]);
}

function action_logout(): never
{
    $user = current_user();
    if ($user && !hash_equals($user['csrf'], $_SERVER['HTTP_X_CSRF'] ?? '')) {
        throw new ApiError('בקשה לא תקינה', 403);
    }
    end_session();
    json_out(['ok' => true]);
}

function action_password(array $body): never
{
    $user = current_user();
    if (!$user) throw new ApiError('נדרשת כניסה', 401);
    if (!hash_equals($user['csrf'], $_SERVER['HTTP_X_CSRF'] ?? '')) throw new ApiError('בקשה לא תקינה', 403);

    // אותה הגבלה כמו בכניסה: אחרת אפשר לנחש את הסיסמה הנוכחית ממכשיר מחובר.
    $keys = ['pw:' . $user['id']];
    $wait = lock_remaining($keys);
    if ($wait > 0) throw new ApiError('יותר מדי ניסיונות. נסו שוב בעוד ' . (int)ceil($wait / 60) . ' דקות', 429);

    $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $st->execute([$user['id']]);
    if (!password_verify(is_string($body['current'] ?? null) ? $body['current'] : '', (string)$st->fetchColumn())) {
        record_failure($keys);
        throw new ApiError('הסיסמה הנוכחית שגויה', 403);
    }
    $password = validate_password($body['password'] ?? null);
    if (hash_equals((string)($body['current'] ?? ''), $password)) throw new ApiError('הסיסמה החדשה זהה לנוכחית', 422);

    clear_failures($keys);
    db()->prepare('UPDATE users SET password_hash = ?, must_change_pw = 0 WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    end_other_sessions((int)$user['id'], true); // שאר המכשירים מתנתקים
    json_out(['ok' => true]);
}

function action_register(array $body): never
{
    if (!users_exist()) throw new ApiError('המערכת עוד לא הותקנה', 409);

    // הרשמה מוגבלת לפי IP, כמו כניסה — אחרת אפשר להציף את תור האישורים.
    $keys = ['reg:' . client_ip()];
    if (lock_remaining($keys) > 0) throw new ApiError('יותר מדי הרשמות מהרשת הזו. נסו מאוחר יותר', 429);

    $username = validate_username($body['username'] ?? null);
    $password = validate_password($body['password'] ?? null);
    $name     = validate_display_name($body['display_name'] ?? null);

    record_failure($keys); // כל הרשמה נספרת; החמישית נועלת לרבע שעה
    try {
        db()->prepare('INSERT INTO users (username, password_hash, display_name, role, status, created_at) VALUES (?,?,?,?,?,?)')
            ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $name, 'user', 'pending', now_iso()]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) throw new ApiError('שם המשתמש תפוס', 409);
        throw $e;
    }
    start_session((int)db()->lastInsertId());

    // קודם התשובה למשתמש, ורק אז ההתראה למנהלים — שההרשמה לא תחכה לשירותי ההתראות.
    respond_then_continue(['ok' => true]);
    try {
        push_send([
            'title' => '👤 הרשמה חדשה',
            'body'  => "$name ($username) ממתין/ה לאישור",
            'tag'   => 'signup',
            'url'   => '#admin',
        ]);
    } catch (Throwable $e) {
        error_log('platform push: ' . $e->getMessage());
    }
    exit;
}

function action_setup(array $body): never
{
    $hash = setup_code_hash();
    if ($hash === null) throw new ApiError('קוד ההתקנה לא הוגדר בשרת (PLATFORM_SETUP_CODE)', 503);

    $keys = ['setup:' . client_ip()];
    $wait = lock_remaining($keys);
    if ($wait > 0) throw new ApiError('יותר מדי ניסיונות. נסו שוב בעוד ' . (int)ceil($wait / 60) . ' דקות', 429);

    $code = is_string($body['code'] ?? null) ? $body['code'] : '';
    if (!password_verify($code, $hash)) {
        record_failure($keys);
        throw new ApiError('קוד ההתקנה שגוי', 403);
    }

    $username = validate_username($body['username'] ?? null);
    $password = validate_password($body['password'] ?? null);
    $name     = validate_display_name($body['display_name'] ?? null);

    // הבדיקה וההוספה בטרנזקציה אחת: שתי בקשות במקביל לא ייצרו שני מנהלים.
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    if (users_exist()) {
        $pdo->exec('ROLLBACK');
        throw new ApiError('המערכת כבר הותקנה', 409);
    }
    $pdo->prepare('INSERT INTO users (username, password_hash, display_name, role, status, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $name, 'admin', 'active', now_iso()]);
    $id = (int)$pdo->lastInsertId();
    $pdo->exec('COMMIT');

    clear_failures($keys);
    start_session($id);
    json_out(['ok' => true]);
}
