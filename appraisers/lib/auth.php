<?php
/**
 * מי המשתמש (platform/docs/auth.md, sso.md §0 ו-§4).
 *
 * האפליקציה מחוברת ("gate": true): השער של הפלטפורמה כותב את $_SERVER['PLATFORM_USER'] —
 * {sub, name, email, email_verified, platform_admin, granted} — רק למי שיש לו הרשאה לאפליקציה. זה מקור הזהות היחיד:
 * אין כאן סיסמה, אין מסך כניסה ואין יציאה (יציאה מהפלטפורמה = יציאה מכאן). בלי זהות — אין כניסה.
 *
 * החשבונות כאן הם של האפליקציה, במסד שלה (users), מקושרים לזהות ב-platform_sub:
 *   1. יש חשבון עם ה-sub → זה החשבון.
 *   2. אין, והמייל אומת בפלטפורמה → חשבון שהוזמן באותו מייל ועוד לא קושר מתקשר עכשיו (ונרשם ביומן הפעולות).
 *   3. אחרת → חשבון חדש, פעיל. ההרשאה בפלטפורמה היא האישור; חברות במשרד — עניין נפרד (lib/offices.php).
 *
 * העוגייה apr_session היא ה-session של האפליקציה עצמה: אסימון אקראי (במסד רק ה-hash), איתו אסימון ה-CSRF והמשרד
 * שהמשתמש עובד בו. session ששייך למשתמש אחר מזה שהשער אומר — לא בשימוש; נפתח חדש.
 *
 * מנהל הפלטפורמה (platform_admin מהשער) הוא הבעלים: כאן הוא גם מנהל הפלטפורמה של האפליקציה וגם המפתח. שני הדגלים
 * מתעדכנים מהשער בכל כניסה — אין דרך להפוך למפתח מתוך האפליקציה.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';

const APR_COOKIE = 'apr_session';
const APR_SESSION_TTL = 180 * 86400;   // כמו הפלטפורמה — חצי שנה מהשימוש האחרון

/** הזהות מהשער, מנוקה. null — אין זהות, או שאין הרשאה לאפליקציה. */
function apr_identity(?string $raw): ?array
{
    $id = json_decode((string)$raw, true);
    if (!is_array($id) || !is_scalar($id['sub'] ?? null) || !preg_match('/^[0-9]{1,12}$/', (string)$id['sub'])) return null;
    if (($id['granted'] ?? true) !== true) return null;   // מחובר לפלטפורמה בלי הרשאה לאפליקציה — לא נכנס
    $email = is_string($id['email'] ?? null) && filter_var($id['email'], FILTER_VALIDATE_EMAIL) ? mb_strtolower($id['email']) : null;
    return [
        'sub' => (string)$id['sub'],
        'name' => mb_substr(trim((string)($id['name'] ?? '')), 0, 120),
        'email' => $email,
        'email_verified' => ($id['email_verified'] ?? false) === true,
        'platform_admin' => ($id['platform_admin'] ?? false) === true,
    ];
}

/** החשבון באפליקציה לפי הזהות — מקושר, מתאחד לפי מייל, או נפתח. */
function apr_user_for(array $id): array
{
    return apr_tx(function () use ($id) {
        $now = apr_now();
        $admin = $id['platform_admin'] ? 1 : 0;
        $user = apr_row('SELECT * FROM users WHERE platform_sub = ?', [$id['sub']]);
        if (!$user && $id['email'] !== null && $id['email_verified']) {
            // איחוד לפי מייל — רק מייל שאומת, ורק חשבון שהוזמן ועוד לא קושר לאף אחד
            $user = apr_row('SELECT * FROM users WHERE email = ? AND platform_sub IS NULL ORDER BY id LIMIT 1', [$id['email']]);
            if ($user) {
                apr_exec('UPDATE users SET platform_sub = ? WHERE id = ?', [$id['sub'], $user['id']]);
                apr_audit($user, null, 'user.linked', 'user:' . $user['id'], ['by' => 'email']);
            }
        }
        if (!$user) {
            apr_exec('INSERT INTO users (platform_sub, email, display_name, is_platform_manager, is_developer, created_at, last_login_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?)', [$id['sub'], $id['email'], $id['name'], $admin, $admin, $now, $now]);
            $user = apr_row('SELECT * FROM users WHERE platform_sub = ?', [$id['sub']]);
            apr_audit($user, null, 'user.created', 'user:' . $user['id']);
            return $user;
        }
        // לא כותבים למסד בכל בקשה: רק כשמשהו השתנה, או פעם בשעה
        $name = $id['name'] !== '' ? $id['name'] : $user['display_name'];
        $email = $id['email'] ?? $user['email'];
        $stale = $user['last_login_at'] === null || strtotime($user['last_login_at']) < apr_time() - 3600;
        if ($stale || $name !== $user['display_name'] || $email !== $user['email']
            || (int)$user['is_platform_manager'] !== $admin || (int)$user['is_developer'] !== $admin) {
            apr_exec('UPDATE users SET display_name = ?, email = ?, is_platform_manager = ?, is_developer = ?, last_login_at = ? WHERE id = ?',
                [$name, $email, $admin, $admin, $now, $user['id']]);
            $user = apr_row('SELECT * FROM users WHERE id = ?', [$user['id']]);
        }
        return $user;
    });
}

/**
 * ה-session של הבקשה. מחזיר ['token_hash', 'csrf', 'office_id', 'new_token' => אסימון לעוגייה, אם צריך לכתוב אותה].
 */
function apr_session_for(array $user, string $cookie): array
{
    $now = apr_time();
    if (preg_match('/^[a-f0-9]{64}$/', $cookie)) {
        $s = apr_row('SELECT * FROM sessions WHERE token_hash = ?', [hash('sha256', $cookie)]);
        if ($s && (int)$s['user_id'] === (int)$user['id'] && (int)$s['last_seen'] > $now - APR_SESSION_TTL) {
            $refresh = (int)$s['last_seen'] < $now - 3600;
            if ($refresh) apr_exec('UPDATE sessions SET last_seen = ? WHERE token_hash = ?', [$now, $s['token_hash']]);
            return ['token_hash' => $s['token_hash'], 'csrf' => $s['csrf'], 'office_id' => $s['office_id'] === null ? null : (int)$s['office_id'],
                'new_token' => $refresh ? $cookie : null];
        }
    }
    $token = bin2hex(random_bytes(32));
    $csrf = bin2hex(random_bytes(32));
    apr_exec('INSERT INTO sessions (token_hash, user_id, csrf, created_at, last_seen) VALUES (?, ?, ?, ?, ?)',
        [hash('sha256', $token), $user['id'], $csrf, apr_now(), $now]);
    if (random_int(1, 50) === 1) apr_exec('DELETE FROM sessions WHERE last_seen < ?', [$now - APR_SESSION_TTL]);
    return ['token_hash' => hash('sha256', $token), 'csrf' => $csrf, 'office_id' => null, 'new_token' => $token];
}

/** אין זהות מהשער (למשל יצאו מהפלטפורמה): ה-session של האפליקציה נמחק. */
function apr_session_drop(string $cookie): void
{
    if (preg_match('/^[a-f0-9]{64}$/', $cookie)) apr_exec('DELETE FROM sessions WHERE token_hash = ?', [hash('sha256', $cookie)]);
}

function apr_set_cookie(string $token, bool $clear = false): void
{
    if (headers_sent()) return;
    $path = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
    setcookie(APR_COOKIE, $clear ? '' : $token, [
        'expires' => $clear ? 1 : time() + APR_SESSION_TTL,
        'path' => $path,
        'secure' => !defined('APR_DEV'),        // בפיתוח מקומי (http) — בלי Secure
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
