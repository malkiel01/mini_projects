<?php
/**
 * מי המשתמש (אפיון פרק 1; spec-stage-a 20.1 — A2; platform/docs/auth.md, sso.md).
 *
 * מחוברת ("gate": true והשער פעיל): השער כותב את $_SERVER['PLATFORM_USER'] —
 * {sub, name, email, email_verified, platform_admin, granted} — רק למי שיש לו הרשאה לאפליקציה. המפתח אינו HTTP_*,
 * ולכן אי אפשר לזייף אותו בבקשה. כשיש אותו — הוא מקור הזהות היחיד, וה-session של האפליקציה הולך אחריו.
 *
 * אין PLATFORM_USER → המצב מהמעטפת (lib/sso.php):
 *   - מחוברת: אין משתמש. ה-session של האפליקציה נמחק — יציאה מהפלטפורמה היא יציאה מכאן (sso.md §0.2).
 *   - מנותקת / unknown: רק session שנפתח בכניסה של המצב המנותק (via = 'sso' / 'password'). session מהשער לא עובר.
 *     ההרשאה נבדקת שוב (grant_status) כל 5 דקות: false → ה-session נמחק; המעטפת לא עונה → נחסם (TsUnavailable).
 *
 * חשבון (20.1 ה', 2.23), בכל כניסה דרך הפלטפורמה: אותו sub → אליו; שורה באותו מייל עם sub אחר → אין כניסה
 * (account_conflict ב-admin_log, TsConflict) — בלי קשר ל-email_verified; אחרת — חשבון חדש. אין מחיקה ואין שכפול.
 * (אין שלב "קישור לשורה בלי sub": platform_sub הוא NOT NULL, ושורה כזו לא קיימת.)
 *
 * כניסה בסיסמה (20.1 ד'): רק כשמנותקת ו-login.password. password_hash; 5 שגיאות → נעילה לרבע שעה, לפי מייל ולפי IP;
 * אותה הודעה ל"אין משתמש" ול"סיסמה שגויה"; grant_status לפני שנפתח session.
 *
 * העוגייה ts_session: אסימון אקראי (במסד — רק ה-hash), ואיתו אסימון ה-CSRF. המזהה של המשתמש לא מגיע מהדפדפן לעולם.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sso.php';

const TS_COOKIE = 'ts_session';
const TS_SESSION_TTL = 180 * 86400;   // כמו הפלטפורמה — חצי שנה מהשימוש האחרון
const TS_LOGIN_MAX_FAILS = 5;
const TS_LOGIN_LOCK = 900;
const TS_PASSWORD_MIN = 10;
const TS_LOGIN_FAIL_MSG = 'מייל או סיסמה שגויים';
const TS_NO_ACCESS_MSG = 'אין לך הרשאת גישה לאפליקציה הזו — פנו לעורך ראשי';

final class TsConflict extends RuntimeException {}
/** כניסה שנדחתה — ההודעה למשתמש והקוד. */
final class TsLoginError extends RuntimeException {}
/** ה-session נמחק כי ההרשאה בפלטפורמה בוטלה. */
final class TsNoAccess extends RuntimeException {}

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
 * המשתמש באפליקציה לפי הזהות (20.1 ה'). זורק TsConflict כשיש חשבון באותו מייל עם sub אחר.
 * platform_admin: true/false — מה שהשער אומר עכשיו; null — זהות חתומה, שלא נושאת אותו: הערך השמור לא משתנה (20.1 ו').
 */
function ts_user_for(array $id): array
{
    $db = ts_db();
    $now = time();
    $st = $db->prepare('SELECT id FROM users WHERE platform_sub = ?');
    $st->execute([$id['sub']]);
    $existing = $st->fetchColumn();
    if (!$existing && $id['email'] !== null) ts_user_conflict_check($id);
    if ($existing && $id['email'] !== null) {
        // מייל שתפוס בשורה אחרת לא מועתק לשורה הזו (ביקורת A2 🟡3) — אחרת שתי שורות באותו מייל, והכניסה בסיסמה נשברת
        $st = $db->prepare('SELECT id FROM users WHERE lower(email) = ? AND id <> ? LIMIT 1');
        $st->execute([$id['email'], $existing]);
        if ($other = $st->fetchColumn()) {
            ts_auth_log((int)$existing, 'account_conflict', 'email in use by user ' . $other . ' — not updated');
            $id['email'] = null;   // COALESCE משאיר את המייל הקודם
        }
    }
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

/** 20.1 ה' שלב 2: אין שורה ל-sub הזה, ויש שורה באותו מייל עם sub אחר → לא מאחדים: TsConflict. */
function ts_user_conflict_check(array $id): void
{
    $st = ts_db()->prepare('SELECT id, platform_sub FROM users WHERE platform_sub <> ? AND lower(email) = ? LIMIT 1');
    $st->execute([$id['sub'], $id['email']]);
    if ($row = $st->fetch()) {
        ts_auth_log((int)$row['id'], 'account_conflict', 'sub ' . $id['sub'] . ' ≠ ' . $row['platform_sub']);
        throw new TsConflict('יש כבר חשבון באפליקציה באותו מייל — פנו לעורך ראשי');
    }
}

function ts_auth_log(int $uid, string $action, string $target): void
{
    ts_db()->prepare('INSERT INTO admin_log (user_id, action, target, at) VALUES (?, ?, ?, ?)')->execute([$uid, $action, mb_substr($target, 0, 300), time()]);
    if (function_exists('devloop_log_write')) devloop_log_write('info', 'auth', $action, ['message' => "user $uid: $target"]);
}

/** ה-session הנוכחי: ['user', 'via']. נקבע ב-ts_session / ts_session_open. */
function ts_current(): ?array
{
    return $GLOBALS['ts_current'] ?? null;
}

/**
 * ה-session של הבקשה: [משתמש, csrf]. null — אין משתמש.
 * זורק: TsUnavailable (המעטפת לא עונה, ואין תשובת הרשאה טרייה), TsNoAccess (ההרשאה בוטלה), TsConflict.
 */
function ts_session(): ?array
{
    $GLOBALS['ts_current'] = null;
    $id = ts_identity();
    if ($id) {
        $user = ts_user_for($id);
        return ts_session_cookie($user) ?? ts_session_open($user, 'gate');
    }
    $mode = ts_platform_mode();
    if ($mode === 'connected') {
        ts_session_drop();   // השער פעיל ואין זהות — לא נשאר מחובר כאן
        return null;
    }
    $s = ts_session_cookie(null);
    if (!$s) return null;
    ts_session_grant_check();
    return $s;
}

/**
 * ה-session מהעוגייה, אם הוא תקף. $user — המשתמש שהשער אומר (session של משתמש אחר, או שלא נפתח מהשער — לא בשימוש);
 * null — מצב מנותק: רק session שנפתח ב-sso או בסיסמה.
 */
function ts_session_cookie(?array $user): ?array
{
    $token = (string)($_COOKIE[TS_COOKIE] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $db = ts_db();
    $now = time();
    $st = $db->prepare('SELECT * FROM sessions WHERE token_hash = ?');
    $st->execute([hash('sha256', $token)]);
    $s = $st->fetch();
    if (!$s || (int)$s['last_seen'] <= $now - TS_SESSION_TTL) return null;
    if ($user !== null) {
        if ((int)$s['user_id'] !== (int)$user['id'] || $s['via'] !== 'gate') return null;
    } else {
        if ($s['via'] === 'gate') return null;
        $u = $db->prepare('SELECT * FROM users WHERE id = ?');
        $u->execute([$s['user_id']]);
        $user = $u->fetch();
        if (!$user) return null;
    }
    if ((int)$s['last_seen'] < $now - 3600) {   // לא כותבים למסד בכל בקשה
        $db->prepare('UPDATE sessions SET last_seen = ? WHERE token_hash = ?')->execute([$now, $s['token_hash']]);
        ts_set_cookie($token);
    }
    $GLOBALS['ts_current'] = ['user' => $user, 'via' => $s['via'], 'token_hash' => $s['token_hash'],
        'grant_checked_at' => (int)$s['grant_checked_at']];
    return [$user, $s['csrf']];
}

/** מנותקת: ההרשאה בפלטפורמה — שמורה עד 5 דקות, ואז grant_status שוב (20.1 ד'). */
function ts_session_grant_check(): void
{
    $cur = ts_current();
    if ($cur === null || $cur['via'] === 'gate' || $cur['grant_checked_at'] > time() - TS_GRANT_TTL) return;
    $ok = ts_grant_status((string)$cur['user']['platform_sub']);
    if ($ok === null) throw new TsUnavailable('grant_status לא ענה');   // נחסם, לא נמחק — חוזר כשהמעטפת עונה
    if ($ok === false) {
        ts_session_drop();
        $GLOBALS['ts_current'] = null;
        throw new TsNoAccess(TS_NO_ACCESS_MSG);
    }
    ts_db()->prepare('UPDATE sessions SET grant_checked_at = ? WHERE token_hash = ?')->execute([time(), $cur['token_hash']]);
}

/** session חדש למשתמש, והעוגייה. $via: gate / sso / password. בכניסה של המצב המנותק — ההרשאה נבדקה זה עתה. */
function ts_session_open(array $user, string $via): array
{
    $db = ts_db();
    $now = time();
    $token = bin2hex(random_bytes(32));
    $csrf = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO sessions (token_hash, user_id, csrf, created_at, last_seen, via, grant_checked_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([hash('sha256', $token), $user['id'], $csrf, $now, $now, $via, $via === 'gate' ? 0 : $now]);
    if (random_int(1, 50) === 1) {
        $db->prepare('DELETE FROM sessions WHERE last_seen < ?')->execute([$now - TS_SESSION_TTL]);
    }
    ts_set_cookie($token);
    $GLOBALS['ts_current'] = ['user' => $user, 'via' => $via, 'token_hash' => hash('sha256', $token), 'grant_checked_at' => $now];
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

// ── כניסה בסיסמה (מנותקת בלבד) ──────────────────────────────

/** null — מותר; אחרת [הודעה, קוד HTTP]. */
function ts_password_login_blocked(): ?array
{
    if (ts_identity()) return ['הכניסה היא דרך הכלים הדיגיטליים', 403];
    $mode = ts_platform_mode();
    if ($mode === 'unknown') return ['הכניסה לא זמינה כרגע — נסו שוב בעוד כמה דקות', 503];
    if ($mode === 'connected') return ['הכניסה היא דרך הכלים הדיגיטליים', 403];
    if (!ts_platform_password_on()) return ['הכניסה בסיסמה כבויה — היכנסו דרך הכלים הדיגיטליים', 403];
    return null;
}

/** מצב הנעילה: true — נעול. נעילה שפגה מתחילה מחדש. */
function ts_lock_locked(string $key): bool
{
    $st = ts_db()->prepare('SELECT fails, locked_until FROM login_locks WHERE lock_key = ?');
    $st->execute([$key]);
    $r = $st->fetch();
    return $r && (int)$r['locked_until'] > time();
}

function ts_lock_fail(string $key): void
{
    // אטומי (ביקורת A2 🟡1): ההוספה היא פקודה אחת — בקשות במקביל לא קוראות את אותו fails ודורסות זו את זו.
    // נעילה שפגה — סופרים מחדש מ-1. ה-UPDATE השני רק נועל מי שהגיע ל-5, ואפשר להריץ אותו כמה פעמים.
    $db = ts_db();
    $now = time();
    $db->prepare('INSERT INTO login_locks (lock_key, fails, locked_until) VALUES (:k, 1, 0)
                   ON CONFLICT(lock_key) DO UPDATE SET
                       fails = CASE WHEN locked_until > 0 AND locked_until <= :now THEN 1 ELSE fails + 1 END,
                       locked_until = CASE WHEN locked_until > 0 AND locked_until <= :now THEN 0 ELSE locked_until END')
        ->execute([':k' => $key, ':now' => $now]);
    $db->prepare('UPDATE login_locks SET locked_until = ?, fails = 0 WHERE lock_key = ? AND locked_until = 0 AND fails >= ?')
        ->execute([$now + TS_LOGIN_LOCK, $key, TS_LOGIN_MAX_FAILS]);
}

function ts_lock_clear(string $key): void
{
    ts_db()->prepare('DELETE FROM login_locks WHERE lock_key = ?')->execute([$key]);
}

/**
 * כניסה במייל וסיסמה. מחזיר [משתמש, csrf] וכותב את העוגייה; זורק TsLoginError (הודעה + קוד).
 * "אין משתמש" ו"סיסמה שגויה" — אותה הודעה, ואותה עבודה (password_verify רץ גם כשאין משתמש).
 */
function ts_password_login(mixed $email, mixed $password, string $ip): array
{
    if ($b = ts_password_login_blocked()) throw new TsLoginError($b[0], $b[1]);
    $email = is_string($email) ? mb_strtolower(trim($email)) : '';
    $password = is_string($password) ? $password : '';
    if ($email === '' || $password === '' || mb_strlen($email) > 200 || strlen($password) > 200) throw new TsLoginError(TS_LOGIN_FAIL_MSG, 400);
    $keys = ['email:' . $email, 'ip:' . $ip];
    foreach ($keys as $k) if (ts_lock_locked($k)) throw new TsLoginError('יותר מדי ניסיונות — נסו שוב בעוד רבע שעה', 429);
    $st = ts_db()->prepare('SELECT * FROM users WHERE lower(email) = ? AND password_hash IS NOT NULL ORDER BY id LIMIT 2');
    $st->execute([$email]);
    $rows = $st->fetchAll();
    if (count($rows) > 1) {
        // שני חשבונות עם סיסמה לאותו מייל (ביקורת A2 🟡3): לא מנחשים לאיזה להיכנס. סיסמה נכונה לאחד מהם — "פנו למנהל"
        // ונרשם; אחרת — כמו כל שגיאה
        foreach ($rows as $r) {
            if (password_verify($password, $r['password_hash'])) {
                ts_auth_log((int)$r['id'], 'account_conflict', 'password login: ' . count($rows) . ' accounts with this email');
                throw new TsLoginError('יש כמה חשבונות באותו מייל — פנו לעורך ראשי', 409);
            }
        }
        $rows = [];
    }
    $user = $rows[0] ?? null;
    static $dummy = null;
    $dummy ??= password_hash('x', PASSWORD_DEFAULT);
    $ok = password_verify($password, $user['password_hash'] ?? $dummy) && $user !== null;
    if (!$ok) {
        foreach ($keys as $k) ts_lock_fail($k);
        throw new TsLoginError(TS_LOGIN_FAIL_MSG, 401);
    }
    // הרשאה בכל כניסה (auth.md 3): הסיסמה נכונה, אבל רק מי שמורשה בפלטפורמה נכנס. לא עונה — נכשלים סגור
    $grant = $user['platform_sub'] !== null ? ts_grant_status((string)$user['platform_sub']) : false;
    if ($grant === null) throw new TsLoginError('הכניסה לא זמינה כרגע — נסו שוב בעוד כמה דקות', 503);
    if ($grant === false) throw new TsLoginError(TS_NO_ACCESS_MSG, 403);
    foreach ($keys as $k) ts_lock_clear($k);
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        ts_db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    ts_db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([time(), $user['id']]);
    return ts_session_open($user, 'password');
}

/** "סיסמה לאפליקציה" (20.1 ד'): המשתמש קובע לעצמו, כשהוא מחובר. לא נמחקת כשהאפליקציה מתחברת שוב — רק חסומה. */
function ts_password_set(int $uid, mixed $password): void
{
    $password = is_string($password) ? $password : '';
    if (mb_strlen($password) < TS_PASSWORD_MIN) throw new TsInvalid('סיסמה — לפחות ' . TS_PASSWORD_MIN . ' תווים');
    if (strlen($password) > 72) throw new TsInvalid('הסיסמה ארוכה מדי — עד 72 בתים');   // bcrypt קורא רק 72 בתים
    $st = ts_db()->prepare('SELECT email FROM users WHERE id = ?');
    $st->execute([$uid]);
    if (!$st->fetchColumn()) throw new TsInvalid('אין מייל בחשבון — אי אפשר להיכנס בסיסמה');
    ts_db()->prepare('UPDATE users SET password_hash = ?, password_set_at = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), time(), $uid]);
    // כניסות אחרות בסיסמה הישנה נסגרות; הנוכחית נשארת
    ts_db()->prepare("DELETE FROM sessions WHERE user_id = ? AND via = 'password' AND token_hash <> ?")->execute([$uid, ts_current()['token_hash'] ?? '']);
}

/** איפוס בידי המנהל הראשי (ש9): הסיסמה נמחקת, וה-sessions של הסיסמה נסגרים. */
function ts_password_reset(int $by, int $target): void
{
    ts_require_chief();
    $db = ts_db();
    $st = $db->prepare('UPDATE users SET password_hash = NULL, password_set_at = NULL WHERE id = ?');
    $st->execute([$target]);
    if ($st->rowCount() !== 1) throw new TsNotFound('המשתמש לא נמצא');
    $db->prepare("DELETE FROM sessions WHERE user_id = ? AND via = 'password'")->execute([$target]);
    ts_admin_log($by, 'password_reset', "user:$target");
}
