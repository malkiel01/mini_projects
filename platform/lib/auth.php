<?php
/**
 * משתמשים, התחברות והגנות.
 *
 * - סיסמאות ב-password_hash.
 * - העוגייה מחזיקה אסימון אקראי; במסד נשמר רק ה-hash שלו.
 * - CSRF: אסימון לכל התחברות, נדרש בכותרת X-CSRF בכל פעולה משנה.
 * - הגבלת ניסיונות: 5 כשלים → נעילה של 15 דקות, לפי שם משתמש ולפי IP.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const SESSION_COOKIE   = 'mp_session';
const SESSION_DAYS     = 30;
const MAX_FAILURES     = 5;
const LOCK_MINUTES     = 15;
const MIN_PASSWORD_LEN = 8;

/** נתיב העוגייה: שורש האתר (התיקייה שמעל platform/), כדי שבשלב השער תגיע גם לכלים. */
function cookie_path(): string
{
    $dir = dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/platform/api.php'));
    return rtrim(str_replace('\\', '/', $dir), '/') . '/';
}

function client_ip(): string
{
    // REMOTE_ADDR בלבד: X-Forwarded-For נשלט בידי הלקוח ואסור לסמוך עליו כאן.
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function users_exist(): bool
{
    return (bool)db()->query('SELECT 1 FROM users LIMIT 1')->fetchColumn();
}

/** המשתמש המחובר, עם אסימון ה-CSRF שלו — או null. */
function current_user(): ?array
{
    static $cached = false;
    if ($cached !== false) return $cached;

    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    $cached = null;
    if (!is_string($token) || strlen($token) !== 64) return null;

    $st = db()->prepare(
        'SELECT u.id, u.username, u.display_name, u.role, u.status, s.csrf
           FROM sessions s JOIN users u ON u.id = s.user_id
          WHERE s.token_hash = ? AND s.expires_at > ?'
    );
    $st->execute([hash('sha256', $token), now_iso()]);
    $row = $st->fetch();
    if ($row && $row['status'] !== 'blocked') $cached = $row;
    return $cached;
}

function start_session(int $userId): void
{
    $token = bin2hex(random_bytes(32));
    $expires = time() + SESSION_DAYS * 86400;
    db()->prepare('INSERT INTO sessions (token_hash, user_id, csrf, created_at, expires_at) VALUES (?,?,?,?,?)')
        ->execute([hash('sha256', $token), $userId, bin2hex(random_bytes(16)), now_iso(), gmdate('Y-m-d\TH:i:s\Z', $expires)]);
    db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([now_iso(), $userId]);
    // ניקוי התחברויות שפג תוקפן — זול, ורץ רק בכניסה.
    db()->prepare('DELETE FROM sessions WHERE expires_at <= ?')->execute([now_iso()]);
    set_session_cookie($token, $expires);
}

function end_session(): void
{
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    if (is_string($token) && $token !== '') {
        db()->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([hash('sha256', $token)]);
    }
    set_session_cookie('', time() - 3600);
}

function set_session_cookie(string $value, int $expires): void
{
    setcookie(SESSION_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => cookie_path(),
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// ---- הגבלת ניסיונות ----

/** שניות שנותרו לנעילה, או 0. */
function lock_remaining(array $keys): int
{
    $max = 0;
    $st = db()->prepare('SELECT locked_until FROM login_attempts WHERE key = ?');
    foreach ($keys as $key) {
        $st->execute([$key]);
        $until = $st->fetchColumn();
        if ($until) $max = max($max, strtotime($until) - time());
    }
    return max(0, $max);
}

function record_failure(array $keys): void
{
    $lockUntil = gmdate('Y-m-d\TH:i:s\Z', time() + LOCK_MINUTES * 60);
    foreach ($keys as $key) {
        db()->prepare(
            'INSERT INTO login_attempts (key, failures) VALUES (?, 1)
             ON CONFLICT(key) DO UPDATE SET failures = failures + 1'
        )->execute([$key]);
        db()->prepare('UPDATE login_attempts SET locked_until = ?, failures = 0 WHERE key = ? AND failures >= ?')
            ->execute([$lockUntil, $key, MAX_FAILURES]);
    }
}

function clear_failures(array $keys): void
{
    $st = db()->prepare('DELETE FROM login_attempts WHERE key = ?');
    foreach ($keys as $key) $st->execute([$key]);
}

// ---- אימות קלט ----

function validate_username($u): string
{
    // גם כתובת מייל מתקבלת כשם משתמש — זה מה שאנשים מקלידים מעצמם.
    $u = is_string($u) ? trim($u) : '';
    if (!preg_match('/^[A-Za-z0-9._@+-]{3,100}$/', $u)) {
        throw new ApiError('שם משתמש: לפחות 3 תווים באנגלית — אפשר גם כתובת מייל', 422);
    }
    return $u;
}

function validate_password($p): string
{
    if (!is_string($p) || mb_strlen($p) < MIN_PASSWORD_LEN || strlen($p) > 200) {
        throw new ApiError('הסיסמה צריכה לפחות ' . MIN_PASSWORD_LEN . ' תווים', 422);
    }
    return $p;
}

function validate_display_name($n): string
{
    $n = is_string($n) ? trim($n) : '';
    if ($n === '' || mb_strlen($n) > 60) throw new ApiError('שם לתצוגה: 1–60 תווים', 422);
    return $n;
}

// ---- המנהל הראשון ----

/** ה-hash של PLATFORM_SETUP_CODE, שהפריסה כותבת ל-lib/setup-code.php — או null. */
function setup_code_hash(): ?string
{
    $file = __DIR__ . '/setup-code.php';
    if (!is_file($file)) return null;
    $hash = require $file;
    return is_string($hash) && $hash !== '' ? $hash : null;
}
