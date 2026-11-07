<?php
/**
 * מי המשתמש (אפיון פרק 1; platform/docs/auth.md).
 *
 * האפליקציה מחוברת ("gate": true): השער של הפלטפורמה כותב את $_SERVER['PLATFORM_USER'] —
 * {sub, name, email, email_verified, platform_admin} — רק למי שיש לו הרשאה לאפליקציה. זה מקור הזהות
 * היחיד: אין כאן סיסמה משלה. בלי PLATFORM_USER (אין הרשאה, או שהאפליקציה נותקה) — אין כניסה.
 *
 * חשבון באפליקציה נפתח מיד בכניסה הראשונה (users.platform_sub). העוגייה ts_session היא ה-session
 * של האפליקציה עצמה: מחזיקה אסימון אקראי (במסד — רק ה-hash), ואיתו אסימון ה-CSRF. session ששייך
 * למשתמש אחר מזה שהשער אומר — לא בשימוש; נפתח חדש.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const TS_COOKIE = 'ts_session';
const TS_SESSION_TTL = 180 * 86400;   // כמו הפלטפורמה — חצי שנה מהשימוש האחרון

function ts_identity(): ?array
{
    $id = json_decode((string)($_SERVER['PLATFORM_USER'] ?? ''), true);
    if (!is_array($id) || !is_scalar($id['sub'] ?? null) || (string)$id['sub'] === '') return null;
    $email = is_string($id['email'] ?? null) && filter_var($id['email'], FILTER_VALIDATE_EMAIL)
        ? strtolower($id['email']) : null;
    return [
        'sub' => (string)$id['sub'],
        'name' => mb_substr(trim((string)($id['name'] ?? '')), 0, 120),
        'email' => $email,
        'platform_admin' => ($id['platform_admin'] ?? false) === true,   // המנהל הראשי (spec-stage-a 1.1)
    ];
}

/** המשתמש באפליקציה לפי הזהות מהשער — נפתח אם אין. */
function ts_user_for(array $id): array
{
    $db = ts_db();
    $now = time();
    $db->prepare('INSERT INTO users (platform_sub, display_name, email, created_at, last_login_at) VALUES (?, ?, ?, ?, ?)
                  ON CONFLICT(platform_sub) DO UPDATE SET display_name = excluded.display_name,
                      email = COALESCE(excluded.email, users.email), last_login_at = excluded.last_login_at')
        ->execute([$id['sub'], $id['name'], $id['email'], $now, $now]);
    $st = $db->prepare('SELECT * FROM users WHERE platform_sub = ?');
    $st->execute([$id['sub']]);
    return $st->fetch();
}

/**
 * ה-session של הבקשה: [משתמש, csrf]. null — אין זהות מהשער.
 * כותב את העוגייה כשנפתח session חדש.
 */
function ts_session(): ?array
{
    $id = ts_identity();
    if (!$id) return null;
    $user = ts_user_for($id);
    $db = ts_db();
    $now = time();

    $token = (string)($_COOKIE[TS_COOKIE] ?? '');
    if (preg_match('/^[a-f0-9]{64}$/', $token)) {
        $st = $db->prepare('SELECT * FROM sessions WHERE token_hash = ?');
        $st->execute([hash('sha256', $token)]);
        $s = $st->fetch();
        if ($s && (int)$s['user_id'] === (int)$user['id'] && (int)$s['last_seen'] > $now - TS_SESSION_TTL) {
            if ((int)$s['last_seen'] < $now - 3600) {   // לא כותבים למסד בכל בקשה
                $db->prepare('UPDATE sessions SET last_seen = ? WHERE token_hash = ?')->execute([$now, $s['token_hash']]);
                ts_set_cookie($token);
            }
            return [$user, $s['csrf']];
        }
    }

    $token = bin2hex(random_bytes(32));
    $csrf = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO sessions (token_hash, user_id, csrf, created_at, last_seen) VALUES (?, ?, ?, ?, ?)')
        ->execute([hash('sha256', $token), $user['id'], $csrf, $now, $now]);
    if (random_int(1, 50) === 1) {
        $db->prepare('DELETE FROM sessions WHERE last_seen < ?')->execute([$now - TS_SESSION_TTL]);
    }
    ts_set_cookie($token);
    return [$user, $csrf];
}

function ts_set_cookie(string $token): void
{
    if (headers_sent()) return;
    $path = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
    setcookie(TS_COOKIE, $token, [
        'expires' => time() + TS_SESSION_TTL,
        'path' => $path,
        'secure' => !defined('TS_DEV'),        // בפיתוח מקומי (http) — בלי Secure
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
