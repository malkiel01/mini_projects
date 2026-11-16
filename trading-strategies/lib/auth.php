<?php
/**
 * מי המשתמש (אפיון פרק 1; platform/docs/auth.md, sso.md; A2 — app-rules 2).
 *
 * מחוברת ("gate": true והשער פעיל): השער כותב את $_SERVER['PLATFORM_USER'] —
 * {sub, name, email, email_verified, platform_admin, granted} — רק למי שיש לו הרשאה לאפליקציה. המפתח אינו HTTP_*,
 * ולכן אי אפשר לזייף אותו בבקשה. כשיש אותו — הוא קובע, וה-session של האפליקציה הולך אחריו.
 *
 * אין PLATFORM_USER → שואלים את המעטפת מה המצב (lib/sso.php):
 *   - מחוברת: אין משתמש. ה-session של האפליקציה נמחק — יציאה מהפלטפורמה היא יציאה מכאן (sso.md §0.2).
 *   - מנותקת: ה-session שנפתח בכניסה המאוחדת (via = 'sso') — ורק הוא. session שנפתח מהשער לא עובר למצב מנותק.
 *   - לא עונה: נכשלים סגור (TsUnavailable → 503).
 *
 * חשבון באפליקציה נפתח מיד בכניסה הראשונה (users.platform_sub). אין חשבון לאותו sub, ויש חשבון באותו מייל — אותו
 * אדם (sso.md §4): החשבון מתקשר ל-sub החדש, בלי שכפול ובלי מחיקה, ונרשם ביומן.
 *
 * העוגייה ts_session היא ה-session של האפליקציה: אסימון אקראי (במסד — רק ה-hash), ואיתו אסימון ה-CSRF.
 * המזהה של המשתמש לא מגיע מהדפדפן לעולם — רק מהשער או מזהות חתומה שנבדקה.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sso.php';

const TS_COOKIE = 'ts_session';
const TS_SESSION_TTL = 180 * 86400;   // כמו הפלטפורמה — חצי שנה מהשימוש האחרון

/** הזהות, מנוקה: sub (ספרות), name, email (קטנות או null), email_verified, platform_admin. null — לא תקינה. */
function ts_identity_clean(mixed $id): ?array
{
    if (!is_array($id) || !is_scalar($id['sub'] ?? null) || !preg_match('/^[0-9]{1,12}$/', (string)$id['sub'])) return null;
    $email = is_string($id['email'] ?? null) && mb_strlen($id['email']) <= 200 && filter_var($id['email'], FILTER_VALIDATE_EMAIL)
        ? mb_strtolower($id['email']) : null;
    return [
        'sub' => (string)$id['sub'],
        'name' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($id['name'] ?? '')) ?? ''), 0, 120),
        'email' => $email,
        'email_verified' => $email !== null && ($id['email_verified'] ?? false) === true,
        'platform_admin' => ($id['platform_admin'] ?? false) === true,   // המנהל הראשי (spec-stage-a 1.1)
    ];
}

/** הזהות מהשער בבקשה הזו, או null. "granted": false (כתובת signed_in) — לא זהות של משתמש באפליקציה. */
function ts_identity(): ?array
{
    $raw = json_decode((string)($_SERVER['PLATFORM_USER'] ?? ''), true);
    if (is_array($raw) && ($raw['granted'] ?? null) === false) return null;
    return ts_identity_clean($raw);
}

/**
 * המשתמש באפליקציה לפי הזהות — נפתח אם אין.
 * platform_admin: true/false — מה שהשער אומר עכשיו (ממנו "מי המפתח" גם בבקשה בלי משתמש, lib/devtasks.php);
 * null — זהות חתומה, שלא נושאת אותו: הערך השמור לא משתנה.
 */
function ts_user_for(array $id): array
{
    $db = ts_db();
    $now = time();
    ts_user_merge_by_email($id);
    $admin = $id['platform_admin'] === null ? null : ($id['platform_admin'] ? 1 : 0);
    $db->prepare('INSERT INTO users (platform_sub, display_name, email, created_at, last_login_at, platform_admin) VALUES (?, ?, ?, ?, ?, ?)
                  ON CONFLICT(platform_sub) DO UPDATE SET display_name = excluded.display_name,
                      email = COALESCE(excluded.email, users.email), last_login_at = excluded.last_login_at,
                      platform_admin = COALESCE(?, users.platform_admin)')
        ->execute([$id['sub'], $id['name'], $id['email'], $now, $now, $admin ?? 0, $admin]);
    $st = $db->prepare('SELECT * FROM users WHERE platform_sub = ?');
    $st->execute([$id['sub']]);
    return $st->fetch();
}

/**
 * איחוד לפי מייל (sso.md §4): אין חשבון ל-sub הזה, ויש בדיוק חשבון אחד באותו מייל — אותו אדם. החשבון מתקשר ל-sub
 * (platform_sub מתעדכן), כל הנתונים שלו נשארים, ושורה ביומן. שני חשבונות באותו מייל — לא מנחשים: נפתח חשבון חדש.
 */
function ts_user_merge_by_email(array $id): void
{
    if ($id['email'] === null) return;
    $db = ts_db();
    $st = $db->prepare('SELECT 1 FROM users WHERE platform_sub = ?');
    $st->execute([$id['sub']]);
    if ($st->fetchColumn()) return;
    $st = $db->prepare('SELECT id, platform_sub FROM users WHERE lower(email) = ? LIMIT 2');
    $st->execute([$id['email']]);
    $rows = $st->fetchAll();
    if (count($rows) !== 1) return;
    $db->prepare('UPDATE users SET platform_sub = ? WHERE id = ?')->execute([$id['sub'], $rows[0]['id']]);
    $db->prepare('INSERT INTO admin_log (user_id, action, target, at) VALUES (?, ?, ?, ?)')
        ->execute([$rows[0]['id'], 'user_merge_email', 'sub ' . $rows[0]['platform_sub'] . ' → ' . $id['sub'], time()]);
    if (function_exists('devloop_log_write')) {
        devloop_log_write('info', 'auth', 'user_merge_email', ['message' => 'user ' . $rows[0]['id'] . ': sub ' . $rows[0]['platform_sub'] . ' → ' . $id['sub']]);
    }
}

/** ה-session הנוכחי: ['user', 'via']. נקבע ב-ts_session / ts_session_open. */
function ts_current(): ?array
{
    return $GLOBALS['ts_current'] ?? null;
}

/**
 * ה-session של הבקשה: [משתמש, csrf]. null — אין משתמש. זורק TsUnavailable כשאין זהות והמעטפת לא עונה.
 * כותב את העוגייה כשנפתח session חדש.
 */
function ts_session(): ?array
{
    $GLOBALS['ts_current'] = null;
    $id = ts_identity();
    if (!$id) {
        $mode = ts_platform_mode();
        if ($mode === 'unavailable') throw new TsUnavailable('המעטפת לא עונה');
        if ($mode === 'connected') {
            ts_session_drop();   // השער פעיל ואין זהות — לא נשאר מחובר כאן
            return null;
        }
        return ts_session_cookie(null, 'sso');
    }
    $user = ts_user_for($id);
    return ts_session_cookie($user, 'gate') ?? ts_session_open($user, 'gate');
}

/**
 * ה-session מהעוגייה, אם הוא תקף. $user — המשתמש שהשער אומר (session של משתמש אחר — לא בשימוש);
 * null — כל משתמש, אבל רק session שנפתח ב-$via.
 */
function ts_session_cookie(?array $user, string $via): ?array
{
    $token = (string)($_COOKIE[TS_COOKIE] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $db = ts_db();
    $now = time();
    $st = $db->prepare('SELECT * FROM sessions WHERE token_hash = ?');
    $st->execute([hash('sha256', $token)]);
    $s = $st->fetch();
    if (!$s || (int)$s['last_seen'] <= $now - TS_SESSION_TTL) return null;
    if ($user !== null && (int)$s['user_id'] !== (int)$user['id']) return null;
    if ($user === null) {
        if ($s['via'] !== $via) return null;
        $u = $db->prepare('SELECT * FROM users WHERE id = ?');
        $u->execute([$s['user_id']]);
        $user = $u->fetch();
        if (!$user) return null;
    }
    if ((int)$s['last_seen'] < $now - 3600) {   // לא כותבים למסד בכל בקשה
        $db->prepare('UPDATE sessions SET last_seen = ? WHERE token_hash = ?')->execute([$now, $s['token_hash']]);
        ts_set_cookie($token);
    }
    $GLOBALS['ts_current'] = ['user' => $user, 'via' => $s['via']];
    return [$user, $s['csrf']];
}

/** session חדש למשתמש, והעוגייה. $via: gate (מהשער) / sso (כניסה מאוחדת, מנותקת). */
function ts_session_open(array $user, string $via): array
{
    $db = ts_db();
    $now = time();
    $token = bin2hex(random_bytes(32));
    $csrf = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO sessions (token_hash, user_id, csrf, created_at, last_seen, via) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([hash('sha256', $token), $user['id'], $csrf, $now, $now, $via]);
    if (random_int(1, 50) === 1) {
        $db->prepare('DELETE FROM sessions WHERE last_seen < ?')->execute([$now - TS_SESSION_TTL]);
    }
    ts_set_cookie($token);
    $GLOBALS['ts_current'] = ['user' => $user, 'via' => $via];
    return [$user, $csrf];
}

/** מוחק את ה-session שבעוגייה (אם יש) ואת העוגייה. */
function ts_session_drop(): void
{
    $token = (string)($_COOKIE[TS_COOKIE] ?? '');
    if ($token === '') return;
    if (preg_match('/^[a-f0-9]{64}$/', $token)) {
        ts_db()->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([hash('sha256', $token)]);
    }
    ts_set_cookie('', true);
}

function ts_cookie_path(): string
{
    return rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
}

function ts_set_cookie(string $token, bool $clear = false): void
{
    if (headers_sent()) return;
    setcookie(TS_COOKIE, $token, [
        'expires' => $clear ? time() - 3600 : time() + TS_SESSION_TTL,
        'path' => ts_cookie_path(),
        'secure' => !defined('TS_DEV'),        // בפיתוח מקומי (http) — בלי Secure
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
