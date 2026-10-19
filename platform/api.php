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
 *   GET  ?action=installs&device=<id>  מה ידוע על המכשיר הזה {app_id: {state, how, checked_at}}
 *   POST ?action=install_report {device, label, app, state, how} — מהמכשיר (pwa-beacon.js / app.js)
 *   GET  ?action=sso_mine  האפליקציות שהמשתמש אישר להן כניסה מאוחדת
 *   POST ?action=sso_revoke {client_id} — ביטול חיבור
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
require_once __DIR__ . '/lib/sso.php';
require_once __DIR__ . '/lib/installs.php';
require_once __DIR__ . '/lib/email.php';

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        match ($action) {
            'me'   => action_me(),
            'apps' => action_apps(),
            'sso_mine' => action_sso_mine(),
            'installs' => action_installs(),
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
            'verify'   => action_verify($body),
            'resend_verify' => action_resend_verify(),
            'setup'    => action_setup($body),
            'password' => action_password($body),
            'sso_revoke' => action_sso_revoke($body),
            'install_report' => action_install_report($body),
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
        'email'        => $u['email'] ?? null,
        // בלי מייל = משתמש ותיק, שנרשם לפני שהיה אימות — לא נדרש ממנו.
        'email_verified' => empty($u['email']) || !empty($u['email_verified_at']),
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

    // נכנסים בשם המשתמש או במייל (נרשמים חדשים — המייל הוא שם המשתמש).
    $st = db()->prepare('SELECT * FROM users WHERE username = ? OR email = ?');
    $st->execute([$username, mb_strtolower($username)]);
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

function action_installs(): never
{
    $user = current_user();
    if (!$user) throw new ApiError('נדרשת כניסה', 401);
    json_out(['installed' => (object)installs_on_device((int)$user['id'], installs_valid_device($_GET['device'] ?? null))]);
}

// דיווח מהמכשיר: מותקנת / לא מותקנת — רק מה שהדפדפן אישר (lib/installs.php). בלי CSRF:
// הכותרת X-Platform חוסמת אתר זר (json_body), והדיווח משנה רק את המכשיר של המשתמש עצמו.
function action_install_report(array $body): never
{
    $user = current_user();
    if (!$user) throw new ApiError('נדרשת כניסה', 401);
    installs_report((int)$user['id'], $body);
    json_out(['ok' => true]);
}

function action_sso_mine(): never
{
    $user = current_user();
    if (!$user) throw new ApiError('נדרשת כניסה', 401);
    json_out(['consents' => sso_consents_of((int)$user['id'])]);
}

function action_sso_revoke(array $body): never
{
    $user = current_user();
    if (!$user) throw new ApiError('נדרשת כניסה', 401);
    if (!hash_equals($user['csrf'], $_SERVER['HTTP_X_CSRF'] ?? '')) throw new ApiError('בקשה לא תקינה', 403);
    sso_revoke((int)$user['id'], (string)($body['client_id'] ?? ''), 'user');
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

    // המייל הוא גם שם המשתמש: בו נכנסים, ובו מתאחדים החשבונות באפליקציות (docs/sso.md).
    $email    = validate_email($body['email'] ?? null);
    $password = validate_password($body['password'] ?? null);
    $name     = validate_display_name($body['display_name'] ?? null);

    record_failure($keys); // כל הרשמה נספרת; החמישית נועלת לרבע שעה
    try {
        db()->prepare('INSERT INTO users (username, email, password_hash, display_name, role, status, created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$email, $email, password_hash($password, PASSWORD_DEFAULT), $name, 'user', 'pending', now_iso()]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) throw new ApiError('המייל הזה כבר רשום — נכנסים בו', 409);
        throw $e;
    }
    $id = (int)db()->lastInsertId();
    start_session($id);

    // קודם התשובה, ורק אז המייל — שההרשמה לא תחכה לשרת הדואר. למנהל מודיעים רק אחרי האימות.
    respond_then_continue(['ok' => true]);
    try {
        if (!verify_send($id, $email, $name)) error_log("platform mail: verify to $email failed");
    } catch (Throwable $e) {
        error_log('platform mail: ' . $e->getMessage());
    }
    exit;
}

/** הקישור מהמייל (#verify=…): המייל אומת. ממתין לאישור → התראה למנהל; בלי צורך באישור → פעיל. */
function action_verify(array $body): never
{
    $keys = ['verify:' . client_ip()];
    if (lock_remaining($keys) > 0) throw new ApiError('יותר מדי ניסיונות. נסו מאוחר יותר', 429);
    try {
        $user = verify_consume(is_string($body['token'] ?? null) ? $body['token'] : '');
    } catch (ApiError $e) {
        record_failure($keys);
        throw $e;
    }
    respond_then_continue(['ok' => true, 'status' => $user['status']]);
    try {
        $who = "{$user['display_name']} ({$user['email']})";
        push_send($user['status'] === 'pending'
            ? ['title' => '👤 הרשמה חדשה', 'body' => "$who אימת/ה את המייל וממתין/ה לאישור", 'tag' => 'signup', 'url' => '#admin']
            : ['title' => '👤 משתמש חדש', 'body' => "$who נרשם/ה ואימת/ה את המייל — בחרו מה יוצג לו/ה", 'tag' => 'signup', 'url' => '#admin']);
    } catch (Throwable $e) {
        error_log('platform push: ' . $e->getMessage());
    }
    exit;
}

/** "לא קיבלתי מייל" — מייל אימות חדש למשתמש המחובר. מוגבל כמו כניסה: אחרי 5 — רבע שעה. */
function action_resend_verify(): never
{
    $user = current_user();
    if (!$user) throw new ApiError('נדרשת כניסה', 401);
    if (empty($user['email']) || !empty($user['email_verified_at'])) throw new ApiError('המייל כבר אומת', 409);
    $keys = ['resend:' . $user['id']];
    if (lock_remaining($keys) > 0) throw new ApiError('נשלחו כבר כמה מיילים. נסו שוב מאוחר יותר', 429);
    record_failure($keys);
    if (!verify_send((int)$user['id'], $user['email'], $user['display_name'])) {
        throw new ApiError('שליחת המייל נכשלה. נסו שוב בעוד כמה דקות', 502);
    }
    json_out(['ok' => true]);
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
