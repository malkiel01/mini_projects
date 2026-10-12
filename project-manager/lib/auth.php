<?php
/**
 * משתמשים, כניסה והגנות — של האפליקציה עצמה (הוכרע 2026-10-12).
 *
 * האפליקציה לא תלויה בחשבונות הפלטפורמה: כך היא יכולה לעמוד בפני עצמה כשתשוחרר
 * מהאינדקס. השער של הפלטפורמה לא חוסם את api.php ("public" ב-app.json), ולכן
 * כל ההגנה כאן:
 *
 *   - סיסמאות ב-password_hash.
 *   - העוגייה pm_session מחזיקה אסימון אקראי; במסד נשמר רק ה-hash שלו.
 *   - CSRF: אסימון לכל התחברות, נדרש בכותרת X-CSRF בכל פעולה משנה. כניסה,
 *     הרשמה והקמה (שקודמות לאסימון) דורשות JSON עם הכותרת X-PM — אתר זר לא יכול
 *     לשלוח כזו בלי אישור CORS.
 *   - הגבלת ניסיונות: 5 כשלים → נעילה של 15 דקות, לפי שם משתמש ולפי IP.
 *
 * תפקידים: developer (המפתח — רואה הכול, מאשר מנהלים) · manager (מנהל פרויקט).
 * מצבים:   pending (נרשם, ממתין לאישור) · active · blocked.
 *
 * המפתח הראשון: כשאין אף משתמש, מסך הכניסה מציע "הקמת המפתח" — רק למי שמחובר
 * לפלטפורמה כמנהל שלה. אחרי שנוצר, המסך נסגר לתמיד.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const PM_COOKIE = 'pm_session';
const PM_SESSION_DAYS = 30;
const PM_MAX_FAILURES = 5;
const PM_LOCK_MINUTES = 15;
const PM_MIN_PASSWORD = 8;
/** המשתמש הראשון מקבל מזהה 1000 ומעלה — שלא יתנגש במזהים ישנים מהפלטפורמה (README). */
const PM_FIRST_USER_ID = 1000;

if (!defined('PM_PLATFORM_DB')) define('PM_PLATFORM_DB', __DIR__ . '/../../platform/data/platform.sqlite');

function pm_client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; // לא X-Forwarded-For: הלקוח שולט בו
}

function pm_users_exist(): bool
{
    return (bool)pm_db()->query('SELECT 1 FROM users LIMIT 1')->fetchColumn();
}

/** המשתמש המחובר — [id, username, display_name, role, status, csrf] — או null (גם ממתין). */
function pm_session_user(): ?array
{
    static $cached = false;
    if ($cached !== false) return $cached;
    $cached = null;
    $token = $_COOKIE[PM_COOKIE] ?? '';
    if (!is_string($token) || strlen($token) !== 64) return null;
    $st = pm_db()->prepare(
        'SELECT u.id, u.username, u.display_name, u.role, u.status, s.csrf
           FROM sessions s JOIN users u ON u.id = s.user_id
          WHERE s.token_hash = ? AND s.expires_at > ?'
    );
    $st->execute([hash('sha256', $token), pm_now()]);
    $u = $st->fetch();
    if (!$u) return null;
    $u['id'] = (int)$u['id'];
    return $cached = $u;
}

/** משתמש פעיל בלבד — מי שרשאי לעבוד באפליקציה. */
function pm_current_user(): ?array
{
    $u = pm_session_user();
    return $u && $u['status'] === 'active' ? $u : null;
}

function pm_cookie_path(): string
{
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $dir . '/';
}

function pm_set_cookie(string $value, int $expires): void
{
    setcookie(PM_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => pm_cookie_path(),
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function pm_start_session(int $userId): void
{
    $token = bin2hex(random_bytes(32));
    $expires = time() + PM_SESSION_DAYS * 86400;
    $db = pm_db();
    $db->prepare('INSERT INTO sessions (token_hash, user_id, csrf, created_at, expires_at) VALUES (?,?,?,?,?)')
       ->execute([hash('sha256', $token), $userId, bin2hex(random_bytes(16)), pm_now(), gmdate('Y-m-d\TH:i:s\Z', $expires)]);
    $db->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([pm_now(), $userId]);
    $db->prepare('DELETE FROM sessions WHERE expires_at <= ?')->execute([pm_now()]);
    pm_set_cookie($token, $expires);
}

function pm_end_session(): void
{
    $token = $_COOKIE[PM_COOKIE] ?? '';
    if (is_string($token) && $token !== '') {
        pm_db()->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([hash('sha256', $token)]);
    }
    pm_set_cookie('', time() - 3600);
}

// ---- הגבלת ניסיונות ----

function pm_lock_remaining(array $keys): int
{
    $max = 0;
    $st = pm_db()->prepare('SELECT locked_until FROM login_attempts WHERE key = ?');
    foreach ($keys as $k) {
        $st->execute([$k]);
        $until = $st->fetchColumn();
        if ($until) $max = max($max, strtotime($until) - time());
    }
    return max(0, $max);
}

function pm_record_failure(array $keys): void
{
    $lock = gmdate('Y-m-d\TH:i:s\Z', time() + PM_LOCK_MINUTES * 60);
    foreach ($keys as $k) {
        pm_db()->prepare('INSERT INTO login_attempts (key, failures) VALUES (?, 1)
                          ON CONFLICT(key) DO UPDATE SET failures = failures + 1')->execute([$k]);
        pm_db()->prepare('UPDATE login_attempts SET locked_until = ?, failures = 0 WHERE key = ? AND failures >= ?')
            ->execute([$lock, $k, PM_MAX_FAILURES]);
    }
}

function pm_clear_failures(array $keys): void
{
    $st = pm_db()->prepare('DELETE FROM login_attempts WHERE key = ?');
    foreach ($keys as $k) $st->execute([$k]);
}

// ---- קלט ----

function pm_valid_username($u): string
{
    $u = is_string($u) ? trim($u) : '';
    if (!preg_match('/^[A-Za-z0-9._@+-]{3,100}$/', $u)) {
        throw new PmError('שם משתמש: לפחות 3 תווים באנגלית — אפשר גם כתובת אימייל', 422);
    }
    return $u;
}

function pm_valid_password($p): string
{
    if (!is_string($p) || mb_strlen($p) < PM_MIN_PASSWORD || strlen($p) > 200) {
        throw new PmError('הסיסמה צריכה לפחות ' . PM_MIN_PASSWORD . ' תווים', 422);
    }
    return $p;
}

function pm_valid_name($n): string
{
    $n = is_string($n) ? trim($n) : '';
    if ($n === '' || mb_strlen($n) > 60) throw new PmError('שם מלא: 1–60 תווים', 422);
    return $n;
}

/** בקשות שקודמות לאסימון CSRF: רק JSON עם X-PM (README, "אבטחה"). */
function pm_require_app_header(): void
{
    if (($_SERVER['HTTP_X_PM'] ?? '') !== '1' || !str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        throw new PmError('בקשה לא תקינה', 400);
    }
}

// ---- פעולות ----

function pm_auth_state(): array
{
    $u = pm_session_user();
    $setupNeeded = !pm_users_exist();
    return [
        'user'           => $u ? array_diff_key($u, ['csrf' => 1]) : null,
        'csrf'           => $u['csrf'] ?? null,
        'setup_needed'   => $setupNeeded,
        'setup_allowed'  => $setupNeeded && pm_platform_admin() !== null,
    ];
}

function pm_login(array $in): array
{
    pm_require_app_header();
    $username = is_string($in['username'] ?? null) ? trim($in['username']) : '';
    $password = is_string($in['password'] ?? null) ? $in['password'] : '';
    $keys = ['u:' . mb_strtolower($username), 'ip:' . pm_client_ip()];
    $wait = pm_lock_remaining($keys);
    if ($wait > 0) throw new PmError('יותר מדי ניסיונות. נסו שוב בעוד ' . (int)ceil($wait / 60) . ' דקות', 429);

    $st = pm_db()->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([$username]);
    $u = $st->fetch();
    // גם כשאין משתמש כזה מריצים password_verify — שזמן התגובה לא יסגיר מי קיים.
    $hash = $u['password_hash'] ?? '$2y$10$rxAGOCUktx7c3KoNsrn5quXX6u4.mqw4TkeXZk1yylgcrXjz.UZFa';
    if (!password_verify($password, $hash) || !$u) {
        pm_record_failure($keys);
        throw new PmError('שם משתמש או סיסמה שגויים', 401);
    }
    if ($u['status'] === 'blocked') throw new PmError('החשבון חסום — פנה למפתח', 403);
    pm_clear_failures($keys);
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        pm_db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    pm_start_session((int)$u['id']);
    return ['ok' => true];
}

function pm_register(array $in): array
{
    pm_require_app_header();
    if (!pm_users_exist()) throw new PmError('האפליקציה עוד לא הוקמה — קודם המפתח', 409);
    $keys = ['reg:' . pm_client_ip()];
    if (pm_lock_remaining($keys) > 0) throw new PmError('יותר מדי הרשמות מהרשת הזו. נסו מאוחר יותר', 429);
    $username = pm_valid_username($in['username'] ?? null);
    $password = pm_valid_password($in['password'] ?? null);
    $name = pm_valid_name($in['display_name'] ?? null);
    $phone = is_string($in['phone'] ?? null) ? mb_substr(trim($in['phone']), 0, 30) : '';
    pm_record_failure($keys); // כל הרשמה נספרת; החמישית נועלת לרבע שעה
    try {
        pm_db()->prepare('INSERT INTO users (username, password_hash, display_name, phone, role, status, created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $name, $phone, 'manager', 'pending', pm_now()]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) throw new PmError('שם המשתמש תפוס', 409);
        throw $e;
    }
    $id = (int)pm_db()->lastInsertId();
    pm_audit("user:$id", 'user.register', 'user', $id, ['username' => $username]);
    pm_start_session($id);
    return ['ok' => true];
}

/**
 * המפתח הראשון. מותר רק כשאין אף משתמש, ורק למי שמחובר לפלטפורמה כמנהל שלה —
 * כך אף אחד מבחוץ לא יכול "לתפוס" את האפליקציה לפני המפתח.
 */
function pm_setup(array $in): array
{
    pm_require_app_header();
    $admin = pm_platform_admin();
    if (pm_users_exist()) throw new PmError('המפתח כבר הוקם', 409);
    if (!$admin) throw new PmError('הקמת המפתח — רק למי שמחובר לפלטפורמה כמנהל', 403);
    $username = pm_valid_username($in['username'] ?? null);
    $password = pm_valid_password($in['password'] ?? null);
    $name = pm_valid_name($in['display_name'] ?? null);

    $db = pm_db();
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO users (id, username, password_hash, display_name, role, status, created_at, approved_at) VALUES (?,?,?,?,?,?,?,?)')
           ->execute([PM_FIRST_USER_ID, $username, password_hash($password, PASSWORD_DEFAULT), $name, 'developer', 'active', pm_now(), pm_now()]);
        // מה שמנהל הפלטפורמה כבר יצר באפליקציה (לפני שהיו לה משתמשים משלה) עובר אליו.
        $old = (int)$admin['id'];
        foreach (['contacts' => 'owner_id', 'projects' => 'owner_id', 'events' => 'actor_id'] as $table => $col) {
            $db->prepare("UPDATE $table SET $col = ? WHERE $col = ?")->execute([PM_FIRST_USER_ID, $old]);
        }
        pm_audit('user:' . PM_FIRST_USER_ID, 'user.setup', 'user', PM_FIRST_USER_ID, ['username' => $username, 'platform_admin' => $old]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    pm_start_session(PM_FIRST_USER_ID);
    return ['ok' => true];
}

function pm_logout(?array $u): array
{
    if ($u && !hash_equals($u['csrf'], (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) throw new PmError('בקשה לא תקינה', 403);
    pm_end_session();
    return ['ok' => true];
}

function pm_change_password(array $u, array $in): array
{
    $st = pm_db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $st->execute([$u['id']]);
    if (!password_verify((string)($in['current'] ?? ''), (string)$st->fetchColumn())) throw new PmError('הסיסמה הנוכחית שגויה', 422);
    $new = pm_valid_password($in['password'] ?? null);
    pm_db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
    // כל ההתחברויות האחרות מתנתקות
    $token = (string)($_COOKIE[PM_COOKIE] ?? '');
    pm_db()->prepare('DELETE FROM sessions WHERE user_id = ? AND token_hash != ?')->execute([$u['id'], hash('sha256', $token)]);
    pm_audit("user:{$u['id']}", 'user.password', 'user', $u['id']);
    return ['ok' => true];
}

// ---- ניהול מנהלים (המפתח) ----

function pm_users_list(): array
{
    $rows = pm_db()->query(
        "SELECT id, username, display_name, phone, role, status, created_at, approved_at, last_login_at,
                (SELECT COUNT(*) FROM projects p WHERE p.owner_id = users.id) AS projects
           FROM users ORDER BY CASE status WHEN 'pending' THEN 0 WHEN 'active' THEN 1 ELSE 2 END, display_name"
    )->fetchAll();
    return array_map(fn($r) => ['id' => (int)$r['id'], 'projects' => (int)$r['projects']] + $r, $rows);
}

/** $action: approve · block · unblock · reject (מחיקת בקשה ממתינה) */
function pm_user_action(array $dev, int $id, string $action): array
{
    $st = pm_db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) throw new PmError('המשתמש לא נמצא', 404);
    if ($u['role'] === 'developer') throw new PmError('אי אפשר לשנות את המפתח', 422);
    $db = pm_db();
    switch ($action) {
        case 'approve':
            $db->prepare("UPDATE users SET status = 'active', approved_at = COALESCE(approved_at, ?) WHERE id = ?")->execute([pm_now(), $id]);
            break;
        case 'unblock':
            $db->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$id]);
            break;
        case 'block':
            $db->prepare("UPDATE users SET status = 'blocked' WHERE id = ?")->execute([$id]);
            $db->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$id]);
            break;
        case 'reject':
            if ($u['status'] !== 'pending') throw new PmError('אפשר לדחות רק בקשה ממתינה', 422);
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            break;
        default:
            throw new PmError('פעולה לא מוכרת', 422);
    }
    pm_audit("user:{$dev['id']}", "user.$action", 'user', $id, ['username' => $u['username']]);
    return pm_users_list();
}

// ---- הפלטפורמה: רק לבדיקת "מי רשאי להקים את המפתח" ----

/** מנהל הפלטפורמה המחובר — [id, display_name] — או null. קריאה בלבד ממסד הפלטפורמה. */
function pm_platform_admin(): ?array
{
    $token = $_COOKIE['mp_session'] ?? '';
    if (!is_string($token) || strlen($token) !== 64 || !is_file(PM_PLATFORM_DB)) return null;
    try {
        $pdo = new PDO('sqlite:' . PM_PLATFORM_DB, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::SQLITE_ATTR_OPEN_FLAGS  => PDO::SQLITE_OPEN_READONLY,
        ]);
        $st = $pdo->prepare(
            "SELECT u.id, u.display_name FROM sessions s JOIN users u ON u.id = s.user_id
              WHERE s.token_hash = ? AND s.expires_at > ? AND u.role = 'admin' AND u.status = 'active'"
        );
        $st->execute([hash('sha256', $token), gmdate('Y-m-d\TH:i:s\Z')]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        error_log('project-manager platform check: ' . $e->getMessage());
        return null;
    }
}
