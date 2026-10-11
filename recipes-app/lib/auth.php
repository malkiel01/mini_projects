<?php
/**
 * חשבונות, כניסה ואסימונים.
 *
 * ההרשמה חופשית (סעיף 2 באפיון), ולכן כל מי שמגיע לכתובת יכול ליצור
 * חשבון. זו הסיבה שכל פונקציה כאן מתייחסת למשתמש כאלמוני עד שהוכח אחרת:
 * אימות דוא"ל לפני כניסה ראשונה, חסימה בידי המנהל, ואסימונים שנשמרים
 * כ-hash ולא כטקסט.
 *
 * המשתמש הראשון נעשה admin. אחרת אין דרך להיכנס למערכת ריקה בלי לזרוע
 * סיסמה קבועה בקוד, וזה בדיוק מה שאסור באתר פתוח.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/errors.php';

const VERIFY_TTL_HOURS = 24;
const RESET_TTL_HOURS  = 1;

function sessionStart(): void {
    // ב-CLI אין עוגיות ואין כותרות, ו-session_start() רק היה מייצר אזהרות.
    // סשן בזיכרון מספיק כדי שכלי שורת פקודה ובדיקות יריצו את אותו קוד
    // בדיוק כמו הדפדפן, במקום מסלול נפרד שאף אחד לא בודק.
    if (PHP_SAPI === 'cli') {
        if (!isset($_SESSION)) $_SESSION = [];
        return;
    }
    if (session_status() !== PHP_SESSION_NONE) return;
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('RECIPES');
    session_start();
}

function currentUser(): ?array {
    sessionStart();
    // הסשן נמחק (24 דקות בלי פעילות בשרת, או דפדפן שנסגר) — העוגייה הארוכה מחזירה אותו
    if (empty($_SESSION['uid'])) keepRestore();
    if (empty($_SESSION['uid'])) return null;
    $st = db()->prepare('SELECT id, username, email, display_name, role, email_verified,
                                blocked, storage_used
                           FROM users WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    $user = $st->fetch();
    // חסימה בידי המנהל מנתקת סשן קיים, ולא רק מונעת כניסה חדשה. אחרת
    // מי שנחסם ממשיך לעבוד עד שייצא מעצמו.
    if (!$user || (int) $user['blocked'] === 1) {
        logout();
        return null;
    }
    return $user;
}

function requireUser(): array {
    $user = currentUser();
    if (!$user) throw new AppError('נדרשת התחברות', 401);
    return $user;
}

function requireAdmin(): array {
    $user = requireUser();
    if ($user['role'] !== 'admin') throw new AppError('אין לך הרשאה לפעולה הזו', 403);
    return $user;
}

function userCount(): int {
    return (int) db()->query('SELECT COUNT(*) c FROM users')->fetch()['c'];
}

/**
 * יוצר חשבון ומחזיר אותו יחד עם האסימון שנשלח בדוא"ל.
 *
 * ‏mail_sent אומר אם ה-MTA קיבל את ההודעה. כשהוא false החשבון קיים ולא
 * מאומת, והממשק אומר למשתמש לפנות למנהל — ולא מציג לו את הקישור, כי זה
 * היה הופך את האימות למופע ריק.
 */
function createUser(string $username, string $email, string $password, string $displayName = ''): array {
    $username = trim($username);
    $email    = trim($email);

    if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $username)) {
        throw new AppError('שם משתמש: 3-32 תווים, אותיות לועזיות, ספרות, נקודה, מקף או קו תחתון');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new AppError('כתובת הדוא"ל אינה תקינה');
    }
    if (strlen($password) < 8) {
        throw new AppError('הסיסמה חייבת להיות באורך 8 תווים לפחות');
    }

    $role = userCount() === 0 ? 'admin' : 'user';
    // המשתמש הראשון הוא בעל האתר, והוא מאומת מראש. אימות דוא"ל נועד להגן
    // מזרים, לא ממנו — ובלי זה, אם mail() אינו פועל בשרת, נוצר מנהל לא
    // מאומת שאינו יכול להיכנס ואין מי שיאמת אותו. קיפאון שאין ממנו יציאה.
    $verified = $role === 'admin' ? 1 : 0;
    $st = db()->prepare('INSERT INTO users (username, email, password_hash, display_name,
                                            role, email_verified, created_at) VALUES (?,?,?,?,?,?,?)');
    try {
        $st->execute([
            $username,
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            trim($displayName) !== '' ? trim($displayName) : $username,
            $role,
            $verified,
            nowIso(),
        ]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) {
            // אותה הודעה לשני המקרים: שם תפוס וכתובת תפוסה. הפרדה ביניהם
            // הופכת את הטופס לכלי שבודק אם כתובת רשומה כאן.
            throw new AppError('שם המשתמש או כתובת הדוא"ל כבר בשימוש');
        }
        throw $e;
    }

    $id    = (int) db()->lastInsertId();
    $token = issueToken($id, 'verify_email', VERIFY_TTL_HOURS);
    $sent  = sendVerifyEmail($email, $token);
    recordMailResult($id, $sent);

    // הדוא"ל נשלח גם למנהל שכבר מאומת: זו הבדיקה היחידה שיש לנו לשאלה
    // אם mail() עובד בשרת, ו-mail_sent הוא מה שמדווח על כך במסך האבחון.
    return [
        'id'        => $id,
        'username'  => $username,
        'role'      => $role,
        'verified'  => $verified === 1,
        'token'     => $token,                        // לבדיקות ולשליחה; לא לתצוגה
        'mail_sent' => $sent,
    ];
}

/**
 * רושם על המשתמש מה קרה לשליחה האחרונה. זה מה שמאפשר למנהל לראות
 * "נשלח / נכשל" במסך המשתמשים במקום לנחש — ומה שחסר כשמשתמש אמר
 * "לא קיבלתי מייל" ולא הייתה שום דרך לדעת אם המערכת בכלל ניסתה.
 */
function recordMailResult(int $userId, bool $ok): void {
    logEvent($ok ? 'info' : 'error', 'mail', $ok ? 'ה-MTA קיבל את ההודעה' : 'ה-MTA דחה את ההודעה', ['user_id' => $userId]);
    $st = db()->prepare('UPDATE users SET last_mail_at = ?, last_mail_ok = ? WHERE id = ?');
    $st->execute([nowIso(), $ok ? 1 : 0, $userId]);
}

/** לא יותר משליחה אחת בשתי דקות לאותו משתמש — אחרת הכפתור הוא כלי ספאם. */
const RESEND_COOLDOWN_SECONDS = 120;

/**
 * שולח שוב את דוא"ל האימות. מזוהה לפי שם משתמש או כתובת.
 * **מחזיר אותה תשובה גם כשאין משתמש כזה** — אחרת הטופס בודק מי רשום.
 * מחזיר null כשלא נשלח מסיבה שאינה שגיאה (לא קיים / כבר מאומת / קירור),
 * true/false לפי תוצאת ה-MTA.
 */
function resendVerification(string $usernameOrEmail): ?bool {
    $id = trim($usernameOrEmail);
    $st = db()->prepare('SELECT id, email, email_verified, last_mail_at FROM users
                          WHERE (username = ? OR email = ?) AND blocked = 0');
    $st->execute([$id, $id]);
    $u = $st->fetch();
    if (!$u || (int) $u['email_verified'] === 1) return null;
    if ($u['last_mail_at'] !== null &&
        strtotime($u['last_mail_at']) > time() - RESEND_COOLDOWN_SECONDS) return null;

    $sent = sendVerifyEmail($u['email'], issueToken((int) $u['id'], 'verify_email', VERIFY_TTL_HOURS));
    recordMailResult((int) $u['id'], $sent);
    return $sent;
}

/** כניסה בשם משתמש או בכתובת דוא"ל. מחזיר null לכל כשל, בלי לפרט מה נכשל. */
function login(string $usernameOrEmail, string $password): ?array {
    $id = trim($usernameOrEmail);
    $st = db()->prepare('SELECT * FROM users WHERE username = ? OR email = ?');
    $st->execute([$id, $id]);
    $user = $st->fetch();

    // password_verify מורץ גם כשאין משתמש, כדי שזמן התגובה לא יגלה
    // אילו שמות וכתובות רשומים.
    $hash = $user['password_hash'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidin';
    if (!password_verify($password, $hash) || !$user) return null;

    if ((int) $user['blocked'] === 1)        throw new AppError('החשבון חסום', 403);
    if ((int) $user['email_verified'] === 0) throw new AppError('החשבון טרם אומת. יש לאשר את הקישור שנשלח בדוא"ל', 403);

    sessionStart();
    if (PHP_SAPI !== 'cli') session_regenerate_id(true);   // מונע קיבוע סשן
    $_SESSION['uid'] = (int) $user['id'];
    keepIssue((int) $user['id']);

    return ['id' => (int) $user['id'], 'username' => $user['username'],
            'display_name' => $user['display_name'], 'role' => $user['role']];
}

function logout(): void {
    sessionStart();
    keepForget();
    $_SESSION = [];
    if (PHP_SAPI === 'cli') return;
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'],
                  $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ─────────────────────────────────────────────────────────────
// להישאר מחובר — עוגייה ארוכה
// ─────────────────────────────────────────────────────────────
//
// סשן של PHP לבדו לא מחזיק: בשרת המשותף הוא נמחק אחרי כ-24 דקות בלי פעילות,
// ועוגיית הסשן נמחקת כשסוגרים את הדפדפן. המשתמש ראה את עצמו מחובר, ובפעולה
// הבאה ("נקה סינון") קיבל "נדרשת התחברות". העוגייה RECIPES_KEEP מחזירה את הסשן.
//
// selector:validator — ה-selector לחיפוש במסד, ה-validator נבדק מול hash בזמן
// קבוע. 180 יום, מתחדש בשימוש (פעם ביום לכל היותר). נמחק ביציאה, באיפוס סיסמה
// ובחסימה (כל המכשירים), ועם החשבון (CASCADE).

const KEEP_COOKIE = 'RECIPES_KEEP';
const KEEP_DAYS   = 180;
const KEEP_MAX    = 20;     // מכשירים לחשבון — הישנים נמחקים

function keepCookieSet(string $value, int $expires): void {
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    setcookie(KEEP_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** אחרי כניסה: אסימון חדש למכשיר הזה. */
function keepIssue(int $userId): void {
    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));
    $exp = time() + KEEP_DAYS * 86400;
    db()->prepare('INSERT INTO login_tokens (user_id, selector, token_hash, created_at, last_used_at, expires_at, user_agent) VALUES (?,?,?,?,?,?,?)')
        ->execute([$userId, $selector, hash('sha256', $validator), nowIso(), nowIso(), gmdate('Y-m-d\TH:i:s\Z', $exp),
                   mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)]);
    // מעבר לתקרה — הישנים ביותר נמחקים
    db()->prepare('DELETE FROM login_tokens WHERE user_id = ? AND id NOT IN (SELECT id FROM login_tokens WHERE user_id = ? ORDER BY last_used_at DESC LIMIT ' . KEEP_MAX . ')')
        ->execute([$userId, $userId]);
    keepCookieSet($selector . ':' . $validator, $exp);
    $_COOKIE[KEEP_COOKIE] = $selector . ':' . $validator;
}

/** השורה של העוגייה הנוכחית, אם תקפה. */
function keepRow(): ?array {
    $raw = (string) ($_COOKIE[KEEP_COOKIE] ?? '');
    if (!preg_match('/^([0-9a-f]{18}):([0-9a-f]{64})$/', $raw, $m)) return null;
    $st = db()->prepare('SELECT * FROM login_tokens WHERE selector = ?');
    $st->execute([$m[1]]);
    $row = $st->fetch();
    $st->closeCursor();
    if (!$row || !hash_equals($row['token_hash'], hash('sha256', $m[2]))) return null;
    if ($row['expires_at'] < nowIso()) {
        db()->prepare('DELETE FROM login_tokens WHERE id = ?')->execute([(int) $row['id']]);
        return null;
    }
    return $row;
}

/** אין סשן — מחזיר אותו מהעוגייה, אם תקפה ושל משתמש פעיל. */
function keepRestore(): void {
    if (empty($_COOKIE[KEEP_COOKIE])) return;
    $row = keepRow();
    if (!$row) { keepCookieSet('', time() - 42000); return; }
    $st = db()->prepare('SELECT blocked, email_verified FROM users WHERE id = ?');
    $st->execute([(int) $row['user_id']]);
    $u = $st->fetch();
    $st->closeCursor();
    if (!$u || (int) $u['blocked'] === 1 || (int) $u['email_verified'] === 0) return;
    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    $_SESSION['uid'] = (int) $row['user_id'];
    // מתחדש — פעם ביום לכל היותר, כדי לא לכתוב למסד בכל בקשה
    if ($row['last_used_at'] < gmdate('Y-m-d\TH:i:s\Z', time() - 86400)) {
        $exp = time() + KEEP_DAYS * 86400;
        db()->prepare('UPDATE login_tokens SET last_used_at = ?, expires_at = ? WHERE id = ?')
            ->execute([nowIso(), gmdate('Y-m-d\TH:i:s\Z', $exp), (int) $row['id']]);
        keepCookieSet((string) $_COOKIE[KEEP_COOKIE], $exp);
    }
}

/** יציאה מהמכשיר הזה. */
function keepForget(): void {
    $raw = (string) ($_COOKIE[KEEP_COOKIE] ?? '');
    if ($raw === '') return;
    $sel = explode(':', $raw)[0];
    db()->prepare('DELETE FROM login_tokens WHERE selector = ?')->execute([$sel]);
    keepCookieSet('', time() - 42000);
    unset($_COOKIE[KEEP_COOKIE]);
}

/** כל המכשירים של משתמש — באיפוס סיסמה ובחסימה. */
function keepForgetAll(int $userId): void {
    db()->prepare('DELETE FROM login_tokens WHERE user_id = ?')->execute([$userId]);
}

// ─────────────────────────────────────────────────────────────
// אסימונים — אימות דוא"ל ואיפוס סיסמה
// ─────────────────────────────────────────────────────────────

/**
 * מנפיק אסימון ומחזיר את הטקסט שלו. **במסד נשמר רק ה-hash**, כדי
 * שהצצה בקובץ הנתונים לא תאפשר לאמת חשבון או לאפס סיסמה של אחר.
 *
 * אסימונים קודמים מאותו סוג מסומנים כמנוצלים, כך שבקשת איפוס שנייה
 * מבטלת את הראשונה ולא משאירה שניים תקפים במקביל.
 */
function issueToken(int $userId, string $kind, int $ttlHours): string {
    $token = bin2hex(random_bytes(32));
    $st = db()->prepare('UPDATE user_tokens SET used_at = ?
                          WHERE user_id = ? AND kind = ? AND used_at IS NULL');
    $st->execute([nowIso(), $userId, $kind]);

    $st = db()->prepare('INSERT INTO user_tokens (user_id, kind, token_hash, expires_at, created_at)
                         VALUES (?,?,?,?,?)');
    $st->execute([
        $userId,
        $kind,
        hash('sha256', $token),
        gmdate('Y-m-d\TH:i:s\Z', time() + $ttlHours * 3600),
        nowIso(),
    ]);
    return $token;
}

/** צורך אסימון ומחזיר את מזהה המשתמש, או זורק שגיאה עם סיבה למשתמש. */
function consumeToken(string $token, string $kind): int {
    $st = db()->prepare('SELECT * FROM user_tokens WHERE token_hash = ? AND kind = ?');
    $st->execute([hash('sha256', $token), $kind]);
    $row = $st->fetch();

    if (!$row)                        throw new AppError('הקישור אינו תקף', 400);
    if ($row['used_at'] !== null)     throw new AppError('הקישור כבר נוצל', 400);
    if ($row['expires_at'] < nowIso()) throw new AppError('הקישור פג תוקף', 400);

    $st = db()->prepare('UPDATE user_tokens SET used_at = ? WHERE id = ?');
    $st->execute([nowIso(), $row['id']]);
    return (int) $row['user_id'];
}

function verifyEmail(string $token): void {
    $userId = consumeToken($token, 'verify_email');
    $st = db()->prepare('UPDATE users SET email_verified = 1 WHERE id = ?');
    $st->execute([$userId]);
}

/**
 * מבקש איפוס סיסמה. **מחזיר את אותה תוצאה גם לכתובת שאינה רשומה** —
 * אחרת הטופס הופך לכלי שבודק מי רשום באתר.
 */
function requestPasswordReset(string $email): void {
    $st = db()->prepare('SELECT id, email FROM users WHERE email = ? AND blocked = 0');
    $st->execute([trim($email)]);
    $user = $st->fetch();
    if (!$user) return;

    sendResetEmail($user['email'], issueToken((int) $user['id'], 'reset_password', RESET_TTL_HOURS));
}

function resetPassword(string $token, string $newPassword): void {
    if (strlen($newPassword) < 8) {
        throw new AppError('הסיסמה חייבת להיות באורך 8 תווים לפחות');
    }
    $userId = consumeToken($token, 'reset_password');
    // איפוס מוצלח מאמת גם את הכתובת: מי שקיבל את ההודעה הוכיח שהיא שלו.
    $st = db()->prepare('UPDATE users SET password_hash = ?, email_verified = 1 WHERE id = ?');
    $st->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
    // סיסמה חדשה מנתקת כל מכשיר שנשאר מחובר — אולי זה בדיוק מה שהאיפוס בא לעצור
    keepForgetAll($userId);
}
