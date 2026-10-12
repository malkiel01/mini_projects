<?php
/**
 * משתמשים, כניסה והגנות — של האפליקציה עצמה, **בלי שום קשר לפלטפורמה**
 * (הוכרע 2026-10-12): לא חשבונות, לא עוגיות, לא מסד. האפליקציה של דף הכלים
 * והאפליקציה הזו הן שתי מערכות משתמשים נפרדות לגמרי — היום, וגם כשהאפליקציה
 * תעמוד בפני עצמה.
 *
 * השער של הפלטפורמה לא חוסם את api.php ("public" ב-app.json), ולכן כל ההגנה כאן:
 *   - סיסמאות ב-password_hash.
 *   - העוגייה pm_session מחזיקה אסימון אקראי; במסד נשמר רק ה-hash שלו.
 *   - CSRF: אסימון לכל התחברות, נדרש בכותרת X-CSRF בכל פעולה משנה. מה שקודם
 *     לכניסה (כניסה, הרשמה, הקמה, שחזור) — רק JSON עם הכותרת X-PM.
 *   - נעילה: 5 כשלים לשם משתמש — ל-15 דקות, רק לאותו שם משתמש. לא לפי רשת.
 *
 * תפקידים: developer (המפתח) · manager (מנהל פרויקט).
 * מצבים:   pending (נרשם, ממתין) · active · blocked.
 *
 * **קוד ההקמה** — כמו PLATFORM_SETUP_CODE של דף הכלים: סוד GitHub בשם
 * PM_RECOVERY_CODE, שהפריסה כותבת ל-lib/recovery-code.php כ-hash בלבד. משמש **רק**
 * להקמת המפתח הראשון, כשאין אף משתמש. אחרי ההקמה — אין לו שום שימוש, ואין במסך
 * הכניסה שום אפשרות "אני המפתח" (הוסרה 2026-10-12: אין סיבה להציג אותה לכל מי שנכנס).
 *
 * **איפוס סיסמה בקישור** — קישור חד-פעמי (login.html#reset=…):
 *   - המפתח שולח למנהל: באימייל, ובכל מקרה גם מוצג לו להעתקה / וואטסאפ. תקף 24 שעות.
 *   - משתמש מבקש לעצמו ("שכחתי סיסמה"): נשלח רק לאימייל הרשום. תקף שעה.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail.php';

const PM_COOKIE = 'pm_session';
const PM_SESSION_DAYS = 30;
const PM_MAX_FAILURES = 5;      // לשם משתמש (וגם לקוד ההקמה)
const PM_MAX_REQUESTS = 20;     // הרשמות / בקשות איפוס מאותה רשת, לפני השהייה
const PM_LOCK_MINUTES = 15;
const PM_MIN_PASSWORD = 8;
/** המשתמש הראשון מקבל מזהה 1000 ומעלה — כך מזהים ישנים (לפני שהיו משתמשים משלה) לא מתנגשים. */
const PM_FIRST_USER_ID = 1000;
const PM_RESET_HOURS_BY_DEV = 24;
const PM_RESET_HOURS_SELF = 1;

function pm_client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; // לא X-Forwarded-For: הלקוח שולט בו
}

function pm_users_exist(): bool
{
    return (bool)pm_db()->query('SELECT 1 FROM users LIMIT 1')->fetchColumn();
}

/** המשתמש המחובר — [id, username, display_name, email, role, status, lang, csrf] — או null (גם ממתין). */
function pm_session_user(): ?array
{
    static $cached = false;
    if ($cached !== false) return $cached;
    $cached = null;
    $token = $_COOKIE[PM_COOKIE] ?? '';
    if (!is_string($token) || strlen($token) !== 64) return null;
    $st = pm_db()->prepare(
        'SELECT u.id, u.username, u.display_name, u.email, u.phone, u.role, u.status, u.lang, s.csrf
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

/**
 * כשל נספר לכל מפתח. **הכניסה ננעלת רק לפי שם המשתמש** — טעות של אחד לא נוגעת
 * באף אחד אחר, גם לא באותה רשת. ההגבלה לפי רשת קיימת רק להרשמה ולבקשות איפוס
 * (מפתחות reg: ו-forgot:), כדי שאי אפשר יהיה להציף את המפתח בבקשות — והיא לא
 * חוסמת כניסה של אף אחד. מחזיר את המפתחות שננעלו עכשיו.
 */
function pm_record_failure(array $keys): array
{
    $locked = [];
    $lock = gmdate('Y-m-d\TH:i:s\Z', time() + PM_LOCK_MINUTES * 60);
    foreach ($keys as $k) {
        $max = str_starts_with($k, 'reg:') || str_starts_with($k, 'forgot:') ? PM_MAX_REQUESTS : PM_MAX_FAILURES;
        pm_db()->prepare('INSERT INTO login_attempts (key, failures) VALUES (?, 1)
                          ON CONFLICT(key) DO UPDATE SET failures = failures + 1')->execute([$k]);
        $st = pm_db()->prepare('UPDATE login_attempts SET locked_until = ?, failures = 0 WHERE key = ? AND failures >= ?');
        $st->execute([$lock, $k, $max]);
        if ($st->rowCount()) $locked[] = $k;
    }
    return $locked;
}

/** כמה כשלים נספרו עד עכשיו למפתח (עד הנעילה). */
function pm_failures(string $key): int
{
    $st = pm_db()->prepare('SELECT failures FROM login_attempts WHERE key = ?');
    $st->execute([$key]);
    return (int)$st->fetchColumn();
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
        throw new PmError('הסיסמה צריכה לפחות {n} תווים', 422, ['n' => PM_MIN_PASSWORD]);
    }
    return $p;
}

function pm_valid_name($n): string
{
    $n = is_string($n) ? trim($n) : '';
    if ($n === '' || mb_strlen($n) > 60) throw new PmError('שם מלא: 1–60 תווים', 422);
    return $n;
}

/** שפה שנשלחה מהדפדפן (כניסה / הרשמה) — קוד מ-PM_LANGS, או '' כשלא נבחרה. */
function pm_in_lang(array $in): string
{
    $l = is_string($in['lang'] ?? null) ? $in['lang'] : '';
    return isset(PM_LANGS[$l]) ? $l : '';
}

/** בקשות שקודמות לאסימון CSRF: רק JSON עם X-PM (README, "אבטחה"). */
function pm_require_app_header(): void
{
    if (($_SERVER['HTTP_X_PM'] ?? '') !== '1' || !str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        throw new PmError('בקשה לא תקינה', 400);
    }
}

// ---- קלט נוסף ----

function pm_valid_email($e, bool $required): string
{
    $e = is_string($e) ? trim($e) : '';
    if ($e === '' && !$required) return '';
    if (!filter_var($e, FILTER_VALIDATE_EMAIL) || strlen($e) > 120) throw new PmError('אימייל לא תקין', 422);
    return $e;
}

/** לאן שולחים מייל: האימייל שבחשבון, ואם הוא ריק ושם המשתמש הוא אימייל — אליו. */
function pm_user_email(array $u): string
{
    if (($u['email'] ?? '') !== '') return $u['email'];
    $name = (string)($u['username'] ?? '');
    return filter_var($name, FILTER_VALIDATE_EMAIL) ? $name : '';
}

function pm_user_row(int $id): array
{
    $st = pm_db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) throw new PmError('המשתמש לא נמצא', 404);
    return $u;
}

// ---- קוד ההקמה (כמו PLATFORM_SETUP_CODE) ----

/** ה-hash של PM_RECOVERY_CODE, שהפריסה כותבת — או null אם הסוד לא הוגדר. */
function pm_recovery_hash(): ?string
{
    $file = __DIR__ . '/recovery-code.php';
    if (!is_file($file)) return null;
    $h = require $file;
    return is_string($h) && $h !== '' ? $h : null;
}

/** בדיקת קוד ההקמה, עם נעילה כמו בכניסה. */
function pm_check_recovery(string $code, string $purpose): void
{
    $keys = ['recovery'];
    $wait = pm_lock_remaining($keys);
    if ($wait > 0) {
        pm_log('warn', 'auth', "$purpose.locked", ['message' => "נעול עוד {$wait} שניות"]);
        throw new PmError('יותר מדי ניסיונות. נסו שוב בעוד {n} דקות', 429, ['n' => (int)ceil($wait / 60)]);
    }
    $hash = pm_recovery_hash();
    if (!$hash) {
        pm_log('error', 'auth', "$purpose.no_code", ['message' => 'הסוד PM_RECOVERY_CODE לא הוגדר ב-GitHub']);
        throw new PmError('קוד ההקמה עוד לא הוגדר. יש להוסיף ב-GitHub את הסוד PM_RECOVERY_CODE ולפרוס.', 409);
    }
    if (!password_verify($code, $hash)) {
        $locked = pm_record_failure($keys);
        pm_log('warn', 'auth', "$purpose.bad_code", ['data' => ['failures' => pm_failures('recovery'), 'locked_now' => $locked]]);
        throw new PmError('קוד ההקמה שגוי', 403);
    }
    pm_clear_failures(['recovery']);
}

// ---- פעולות לפני כניסה ----

function pm_auth_state(): array
{
    $u = pm_session_user();
    return [
        'user'          => $u ? array_diff_key($u, ['csrf' => 1]) : null,
        'csrf'          => $u['csrf'] ?? null,
        'setup_needed'  => !pm_users_exist(),
        'recovery_set'  => pm_recovery_hash() !== null,
        'mail'          => pm_mail_available(),
    ];
}

function pm_login(array $in): array
{
    pm_require_app_header();
    $login = is_string($in['username'] ?? null) ? trim($in['username']) : '';
    $password = is_string($in['password'] ?? null) ? $in['password'] : '';
    $keys = ['u:' . mb_strtolower($login)];
    $wait = pm_lock_remaining($keys);
    if ($wait > 0) {
        pm_log('warn', 'auth', 'login.locked', ['username' => $login, 'message' => "נעול עוד {$wait} שניות",
            'data' => ['failures_before_lock' => PM_MAX_FAILURES]]);
        throw new PmError('יותר מדי ניסיונות. נסו שוב בעוד {n} דקות', 429, ['n' => (int)ceil($wait / 60)]);
    }
    // נכנסים בשם המשתמש או באימייל
    $st = pm_db()->prepare('SELECT * FROM users WHERE username = ? OR (email != \'\' AND email = ? COLLATE NOCASE) ORDER BY username = ? DESC LIMIT 1');
    $st->execute([$login, $login, $login]);
    $u = $st->fetch();
    // גם כשאין משתמש כזה מריצים password_verify — שזמן התגובה לא יסגיר מי קיים.
    $hash = $u['password_hash'] ?? '$2y$10$rxAGOCUktx7c3KoNsrn5quXX6u4.mqw4TkeXZk1yylgcrXjz.UZFa';
    if (!password_verify($password, $hash) || !$u) {
        $locked = pm_record_failure($keys);
        pm_log('warn', 'auth', 'login.fail', ['username' => $login,
            'message' => $u ? 'סיסמה שגויה' : 'אין משתמש בשם או באימייל הזה',
            'data' => ['user_exists' => (bool)$u, 'matched_by' => $u ? ($u['username'] === $login ? 'username' : 'email') : null,
                       'failures_user' => pm_failures($keys[0]), 'lock_after' => PM_MAX_FAILURES,
                       'password_length' => mb_strlen($password), 'users_total' => (int)pm_db()->query('SELECT COUNT(*) FROM users')->fetchColumn(),
                       'locked_now' => $locked]]);
        throw new PmError('שם משתמש או סיסמה שגויים', 401);
    }
    if ($u['status'] === 'blocked') {
        pm_log('warn', 'auth', 'login.blocked', ['username' => $u['username'], 'user' => ['id' => (int)$u['id']]]);
        throw new PmError('החשבון חסום — פנה למפתח', 403);
    }
    pm_clear_failures($keys);
    // השפה שנבחרה במסך הכניסה — נשמרת למשתמש (גם המיילים אליו יוצאים בה)
    if (($lang = pm_in_lang($in)) !== '') {
        pm_db()->prepare('UPDATE users SET lang = ? WHERE id = ?')->execute([$lang, $u['id']]);
    }
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        pm_db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    pm_log('info', 'auth', 'login.ok', ['username' => $u['username'], 'user' => ['id' => (int)$u['id'], 'username' => $u['username']],
        'data' => ['role' => $u['role'], 'status' => $u['status']]]);
    pm_start_session((int)$u['id']);
    return ['ok' => true];
}

function pm_register(array $in): array
{
    pm_require_app_header();
    if (!pm_users_exist()) throw new PmError('האפליקציה עוד לא הוקמה — קודם המפתח', 409);
    $keys = ['reg:' . pm_client_ip()];
    if (pm_lock_remaining($keys) > 0) {
        pm_log('warn', 'auth', 'register.locked', ['username' => (string)($in['username'] ?? '')]);
        throw new PmError('יותר מדי הרשמות מהרשת הזו. נסו מאוחר יותר', 429);
    }
    $username = pm_valid_username($in['username'] ?? null);
    $password = pm_valid_password($in['password'] ?? null);
    $name = pm_valid_name($in['display_name'] ?? null);
    $email = pm_valid_email($in['email'] ?? null, true);
    $phone = is_string($in['phone'] ?? null) ? mb_substr(trim($in['phone']), 0, 30) : '';
    pm_record_failure($keys); // כל הרשמה נספרת; החמישית נועלת לרבע שעה
    $dup = pm_db()->prepare('SELECT 1 FROM users WHERE email = ? COLLATE NOCASE');
    $dup->execute([$email]);
    if ($dup->fetchColumn()) throw new PmError('האימייל הזה כבר רשום', 409);
    try {
        pm_db()->prepare('INSERT INTO users (username, password_hash, display_name, email, phone, role, status, created_at, lang) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $name, $email, $phone, 'manager', 'pending', pm_now(), pm_in_lang($in)]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) throw new PmError('שם המשתמש תפוס', 409);
        throw $e;
    }
    $id = (int)pm_db()->lastInsertId();
    pm_audit("user:$id", 'user.register', 'user', $id, ['username' => $username]);
    pm_log('info', 'auth', 'register', ['username' => $username, 'user' => ['id' => $id, 'username' => $username],
        'message' => 'נרשם — ממתין לאישור', 'data' => ['display_name' => $name, 'email' => $email, 'phone' => $phone]]);
    // הודעה למפתח, אם יש לו אימייל — בשפה שלו
    $devRow = pm_db()->query("SELECT email, lang FROM users WHERE role = 'developer' AND email != '' LIMIT 1")->fetch();
    if ($devRow) {
        $dev = $devRow['email'];
        $lang = pm_user_lang($devRow);
        $sent = pm_send_mail($dev, pm_t('הרשמה חדשה ממתינה לאישור — ניהול פרויקטים', [], $lang),
            pm_t("{name} ({username}, {email}) נרשם כמנהל פרויקט וממתין לאישור.\n\nלאישור: {link}\n",
                 ['name' => $name, 'username' => $username, 'email' => $email, 'link' => pm_app_url() . '/#/settings/access'], $lang), $lang);
        pm_log($sent ? 'info' : 'warn', 'mail', 'mail.register_notice', ['data' => ['to' => $dev, 'sent' => $sent]]);
    }
    pm_start_session($id);
    return ['ok' => true];
}

/** המפתח הראשון — כשאין אף משתמש, עם קוד ההקמה. אחרי שנוצר, המסך נסגר לתמיד. */
function pm_setup(array $in): array
{
    pm_require_app_header();
    if (pm_users_exist()) throw new PmError('המפתח כבר הוקם', 409);
    pm_check_recovery((string)($in['code'] ?? ''), 'setup');
    $username = pm_valid_username($in['username'] ?? null);
    $password = pm_valid_password($in['password'] ?? null);
    $name = pm_valid_name($in['display_name'] ?? null);
    $email = pm_valid_email($in['email'] ?? null, false);
    pm_db()->prepare('INSERT INTO users (id, username, password_hash, display_name, email, role, status, created_at, approved_at, lang) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([PM_FIRST_USER_ID, $username, password_hash($password, PASSWORD_DEFAULT), $name, $email, 'developer', 'active', pm_now(), pm_now(), pm_in_lang($in)]);
    pm_audit('user:' . PM_FIRST_USER_ID, 'user.setup', 'user', PM_FIRST_USER_ID, ['username' => $username]);
    pm_log('info', 'auth', 'setup', ['username' => $username, 'user' => ['id' => PM_FIRST_USER_ID, 'username' => $username], 'message' => 'המפתח הוקם']);
    pm_start_session(PM_FIRST_USER_ID);
    return ['ok' => true];
}

/**
 * "שכחתי סיסמה" — קישור לאימייל הרשום. התשובה זהה תמיד (קיים או לא), כדי לא
 * לחשוף מי רשום; מה שקרה באמת — ביומן.
 */
function pm_forgot(array $in): array
{
    pm_require_app_header();
    $login = is_string($in['login'] ?? null) ? trim($in['login']) : '';
    $keys = ['forgot:' . pm_client_ip()];
    if (pm_lock_remaining($keys) > 0) throw new PmError('יותר מדי בקשות. נסו שוב מאוחר יותר', 429);
    pm_record_failure($keys); // כל בקשה נספרת — החמישית נועלת לרבע שעה
    $st = pm_db()->prepare('SELECT * FROM users WHERE username = ? OR (email != \'\' AND email = ? COLLATE NOCASE) LIMIT 1');
    $st->execute([$login, $login]);
    $u = $st->fetch();
    $result = 'no_user';
    if ($u && $u['status'] === 'blocked') $result = 'blocked';
    elseif ($u && pm_user_email($u) === '') $result = 'no_email';
    elseif ($u) {
        $link = pm_create_reset((int)$u['id'], PM_RESET_HOURS_SELF, 'self');
        $lang = pm_user_lang($u);
        $sent = pm_send_mail(pm_user_email($u), pm_t('איפוס סיסמה — ניהול פרויקטים', [], $lang),
            pm_t("שלום {name},\n\nלקביעת סיסמה חדשה:\n\n{link}\n\nהקישור תקף שעה אחת ולשימוש חד-פעמי. אם לא ביקשת — אין צורך לעשות דבר.\n",
                 ['name' => $u['display_name'], 'link' => $link], $lang), $lang);
        $result = $sent ? 'sent' : 'mail_failed';
    }
    pm_log($result === 'sent' ? 'info' : 'warn', 'auth', 'forgot', ['username' => $u['username'] ?? $login, 'message' => $result,
        'data' => ['to' => $u ? pm_user_email($u) : null]]);
    return ['ok' => true];
}

/** קישור איפוס חדש. $by: self · developer. מבטל קישורים קודמים של אותו משתמש. */
function pm_create_reset(int $userId, int $hours, string $by): string
{
    $token = bin2hex(random_bytes(24));
    $db = pm_db();
    $db->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);
    $db->prepare('INSERT INTO password_resets (token_hash, user_id, created_by, created_at, expires_at) VALUES (?,?,?,?,?)')
       ->execute([hash('sha256', $token), $userId, $by, pm_now(), gmdate('Y-m-d\TH:i:s\Z', time() + $hours * 3600)]);
    return pm_app_url() . '/login.html#reset=' . $token;
}

function pm_reset_row(string $token): array
{
    $st = pm_db()->prepare('SELECT r.*, u.username, u.display_name, u.status FROM password_resets r JOIN users u ON u.id = r.user_id
                             WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > ?');
    $st->execute([hash('sha256', $token), pm_now()]);
    $r = $st->fetch();
    if (!$r) {
        pm_log('warn', 'auth', 'reset.invalid', ['data' => ['token_length' => strlen($token)]]);
        throw new PmError('הקישור לא תקף, שומש כבר, או שפג — אפשר לבקש חדש', 410);
    }
    return $r;
}

/** מי הקישור — כדי שמסך האיפוס יציג "שלום X". */
function pm_reset_info(string $token): array
{
    $r = pm_reset_row($token);
    return ['display_name' => $r['display_name'], 'username' => $r['username']];
}

function pm_reset_password(array $in): array
{
    pm_require_app_header();
    $r = pm_reset_row((string)($in['token'] ?? ''));
    $password = pm_valid_password($in['password'] ?? null);
    $db = pm_db();
    $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $r['user_id']]);
    $db->prepare('UPDATE password_resets SET used_at = ? WHERE token_hash = ?')->execute([pm_now(), $r['token_hash']]);
    $db->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$r['user_id']]);
    $db->prepare('DELETE FROM login_attempts WHERE key = ?')->execute(['u:' . mb_strtolower($r['username'])]);
    pm_log('info', 'auth', 'reset.done', ['username' => $r['username'], 'user' => ['id' => (int)$r['user_id'], 'username' => $r['username']],
        'data' => ['created_by' => $r['created_by']]]);
    if ($r['status'] === 'blocked') return ['ok' => true, 'blocked' => true];
    pm_start_session((int)$r['user_id']);
    return ['ok' => true];
}

function pm_logout(?array $u): array
{
    if ($u && !hash_equals($u['csrf'], (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) throw new PmError('בקשה לא תקינה', 403);
    pm_end_session();
    if ($u) pm_log('info', 'auth', 'logout', ['user' => $u]);
    return ['ok' => true];
}

// ---- החשבון שלי ----

function pm_change_password(array $u, array $in): array
{
    $st = pm_db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $st->execute([$u['id']]);
    if (!password_verify((string)($in['current'] ?? ''), (string)$st->fetchColumn())) {
        pm_log('warn', 'auth', 'password.bad_current', ['user' => $u]);
        throw new PmError('הסיסמה הנוכחית שגויה', 422);
    }
    $new = pm_valid_password($in['password'] ?? null);
    pm_db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
    $token = (string)($_COOKIE[PM_COOKIE] ?? '');
    pm_db()->prepare('DELETE FROM sessions WHERE user_id = ? AND token_hash != ?')->execute([$u['id'], hash('sha256', $token)]);
    pm_audit("user:{$u['id']}", 'user.password', 'user', $u['id']);
    pm_log('info', 'auth', 'password.changed', ['user' => $u]);
    return ['ok' => true];
}

function pm_update_profile(array $u, array $in): array
{
    $name = pm_valid_name($in['display_name'] ?? null);
    $email = pm_valid_email($in['email'] ?? null, false);
    $phone = is_string($in['phone'] ?? null) ? mb_substr(trim($in['phone']), 0, 30) : '';
    pm_unique_email($email, $u['id']);
    pm_db()->prepare('UPDATE users SET display_name = ?, email = ?, phone = ? WHERE id = ?')->execute([$name, $email, $phone, $u['id']]);
    pm_log('info', 'auth', 'profile.updated', ['user' => $u, 'data' => ['email' => $email, 'phone' => $phone]]);
    return ['user' => pm_public_user(pm_user_row($u['id']))];
}

function pm_unique_email(string $email, int $exceptId): void
{
    if ($email === '') return;
    $st = pm_db()->prepare('SELECT 1 FROM users WHERE email = ? COLLATE NOCASE AND id != ?');
    $st->execute([$email, $exceptId]);
    if ($st->fetchColumn()) throw new PmError('האימייל הזה כבר רשום אצל משתמש אחר', 409);
}

// ---- ניהול הרשומים (המפתח) ----

function pm_public_user(array $r): array
{
    return [
        'id' => (int)$r['id'], 'username' => $r['username'], 'display_name' => $r['display_name'],
        'email' => $r['email'], 'phone' => $r['phone'], 'role' => $r['role'], 'status' => $r['status'],
        'notes' => $r['notes'] ?? '', 'created_at' => $r['created_at'], 'approved_at' => $r['approved_at'],
        'last_login_at' => $r['last_login_at'], 'lang' => $r['lang'] ?? '',
    ];
}

function pm_users_list(): array
{
    $now = pm_now();
    $rows = pm_db()->query(
        "SELECT u.*,
                (SELECT COUNT(*) FROM projects p WHERE p.owner_id = u.id) AS projects,
                (SELECT COUNT(*) FROM contacts c WHERE c.owner_id = u.id) AS contacts,
                (SELECT COUNT(*) FROM sessions s WHERE s.user_id = u.id AND s.expires_at > '$now') AS sessions,
                (SELECT MAX(expires_at) FROM password_resets r WHERE r.user_id = u.id AND r.used_at IS NULL AND r.expires_at > '$now') AS reset_pending
           FROM users u
          ORDER BY CASE u.status WHEN 'pending' THEN 0 WHEN 'active' THEN 1 ELSE 2 END, u.display_name"
    )->fetchAll();
    return array_map(fn($r) => pm_public_user($r) + [
        'projects' => (int)$r['projects'], 'contacts' => (int)$r['contacts'],
        'sessions' => (int)$r['sessions'], 'reset_pending' => $r['reset_pending'],
    ], $rows);
}

/** נתונים שנוצרו לפני שהיו לאפליקציה משתמשים משלה — בעלים שאינו משתמש. */
function pm_orphans(): array
{
    $c = (int)pm_db()->query('SELECT COUNT(*) FROM contacts WHERE owner_id NOT IN (SELECT id FROM users)')->fetchColumn();
    $p = (int)pm_db()->query('SELECT COUNT(*) FROM projects WHERE owner_id NOT IN (SELECT id FROM users)')->fetchColumn();
    return ['contacts' => $c, 'projects' => $p];
}

/** עריכת רשום בידי המפתח: שם, שם משתמש, אימייל, טלפון, הערות. */
function pm_user_save(array $dev, array $in): array
{
    $u = pm_user_row((int)($in['id'] ?? 0));
    $username = pm_valid_username($in['username'] ?? null);
    $email = pm_valid_email($in['email'] ?? null, false);
    pm_unique_email($email, (int)$u['id']);
    $f = [
        'display_name' => pm_valid_name($in['display_name'] ?? null),
        'username'     => $username,
        'email'        => $email,
        'phone'        => is_string($in['phone'] ?? null) ? mb_substr(trim($in['phone']), 0, 30) : '',
        'notes'        => is_string($in['notes'] ?? null) ? mb_substr(trim($in['notes']), 0, 2000) : '',
    ];
    try {
        pm_db()->prepare('UPDATE users SET display_name = ?, username = ?, email = ?, phone = ?, notes = ? WHERE id = ?')
            ->execute([...array_values($f), $u['id']]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) throw new PmError('שם המשתמש תפוס', 409);
        throw $e;
    }
    $changed = array_keys(array_filter($f, fn($v, $k) => (string)$v !== (string)($u[$k] ?? ''), ARRAY_FILTER_USE_BOTH));
    pm_audit("user:{$dev['id']}", 'user.edit', 'user', (int)$u['id'], ['fields' => $changed]);
    pm_log('info', 'admin', 'user.edit', ['data' => ['target' => $u['username'], 'fields' => $changed]]);
    return ['users' => pm_users_list()];
}

/**
 * $action: approve · reject (מחיקת בקשה ממתינה) · block · unblock ·
 *          reset (קישור איפוס — לאימייל, ומוצג למפתח) · logout (ניתוק מכל המכשירים) ·
 *          delete (רק בלי פרויקטים ואנשי קשר — אחרת קודם העברה) · transfer (to: משתמש)
 */
function pm_user_action(array $dev, int $id, string $action, array $in = []): array
{
    $u = pm_user_row($id);
    $db = pm_db();
    $extra = [];
    if ($u['role'] === 'developer' && !in_array($action, ['reset', 'logout'], true)) throw new PmError('אי אפשר לשנות את המפתח', 422);
    switch ($action) {
        case 'approve':
            $db->prepare("UPDATE users SET status = 'active', approved_at = COALESCE(approved_at, ?) WHERE id = ?")->execute([pm_now(), $id]);
            if (pm_user_email($u) !== '') {
                $lang = pm_user_lang($u);
                $sent = pm_send_mail(pm_user_email($u), pm_t('החשבון שלך אושר — ניהול פרויקטים', [], $lang),
                    pm_t("שלום {name},\n\nהחשבון שלך אושר. אפשר להיכנס:\n\n{link}\n\nשם המשתמש: {username}\n",
                         ['name' => $u['display_name'], 'link' => pm_app_url() . '/login.html', 'username' => $u['username']], $lang), $lang);
                pm_log($sent ? 'info' : 'warn', 'mail', 'mail.approved', ['data' => ['to' => pm_user_email($u), 'sent' => $sent]]);
            }
            break;
        case 'unblock':
            $db->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$id]);
            break;
        case 'block':
            $db->prepare("UPDATE users SET status = 'blocked' WHERE id = ?")->execute([$id]);
            $db->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$id]);
            break;
        case 'logout':
            $db->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$id]);
            break;
        case 'reject':
            if ($u['status'] !== 'pending') throw new PmError('אפשר לדחות רק בקשה ממתינה', 422);
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            break;
        case 'reset':
            $link = pm_create_reset($id, PM_RESET_HOURS_BY_DEV, 'developer');
            $to = pm_user_email($u);
            $lang = pm_user_lang($u);
            $sent = $to !== '' && pm_send_mail($to, pm_t('קביעת סיסמה חדשה — ניהול פרויקטים', [], $lang),
                pm_t("שלום {name},\n\nהמפתח שלח לך קישור לקביעת סיסמה חדשה:\n\n{link}\n\nשם המשתמש: {username}\nהקישור תקף 24 שעות ולשימוש חד-פעמי.\n",
                     ['name' => $u['display_name'], 'link' => $link, 'username' => $u['username']], $lang), $lang);
            pm_log($sent ? 'info' : 'warn', 'mail', 'mail.reset', ['data' => ['to' => $to, 'sent' => $sent]]);
            $extra = ['reset_link' => $link, 'mailed' => $sent, 'email' => $to, 'phone' => $u['phone'],
                      'display_name' => $u['display_name'], 'username' => $u['username']];
            break;
        case 'transfer':
            $to = pm_user_row((int)($in['to'] ?? 0));
            if ($to['id'] == $id) throw new PmError('בחר משתמש אחר', 422);
            foreach (['projects', 'contacts'] as $t) $db->prepare("UPDATE $t SET owner_id = ? WHERE owner_id = ?")->execute([$to['id'], $id]);
            $extra = ['transferred_to' => $to['username']];
            break;
        case 'delete':
            $o = $db->prepare('SELECT (SELECT COUNT(*) FROM projects WHERE owner_id = ?) + (SELECT COUNT(*) FROM contacts WHERE owner_id = ?)');
            $o->execute([$id, $id]);
            if ((int)$o->fetchColumn() > 0) throw new PmError('יש לו פרויקטים או אנשי קשר — קודם להעביר אותם למשתמש אחר', 422);
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            break;
        default:
            throw new PmError('פעולה לא מוכרת', 422);
    }
    pm_audit("user:{$dev['id']}", "user.$action", 'user', $id, ['username' => $u['username']] + $extra);
    pm_log('info', 'admin', "user.$action", ['data' => ['target_id' => $id, 'target' => $u['username']]
        + array_diff_key($extra, ['reset_link' => 1])]);
    return ['users' => pm_users_list()] + $extra;
}

/** נתונים ישנים בלי בעלים → למשתמש שנבחר. */
function pm_claim_orphans(array $dev, int $to): array
{
    $u = pm_user_row($to);
    $n = 0;
    foreach (['projects', 'contacts'] as $t) {
        $st = pm_db()->prepare("UPDATE $t SET owner_id = ? WHERE owner_id NOT IN (SELECT id FROM users)");
        $st->execute([$to]);
        $n += $st->rowCount();
    }
    pm_log('info', 'admin', 'orphans.claimed', ['data' => ['to' => $u['username'], 'rows' => $n]]);
    return ['users' => pm_users_list(), 'orphans' => pm_orphans(), 'moved' => $n];
}
