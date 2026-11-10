<?php
/**
 * מצב מנותק (אפיון 02 §5): הכניסה כשהשער של המעטפת אינו פעיל.
 *   - "כניסה עם הכלים הדיגיטליים": sso-start → המעטפת → sso-callback עם זהות חתומה (Ed25519). כל הבדיקות של
 *     platform/docs/sso.md §2 — חתימה, aud, iss, state חד-פעמי, return, exp, jti.
 *   - סיסמה משלה: רק כשהמנהל פתח אותה במעטפת (login.password). במצב מחוברת — חסומה לגמרי, גם אם נקבעה.
 *   - יציאה, שכחתי סיסמה, והגבלת ניסיונות.
 * אין כאן מקור זהות חדש: זהות חתומה עוברת באותו טיפול כמו זהות מהשער (apr_user_for).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/platform.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/jobs.php';

const APR_SSO_STATE_TTL = 600;
const APR_SSO_SKEW = 60;
const APR_LOGIN_MAX_FAILS = 5;
const APR_LOGIN_LOCK = 900;
const APR_RESET_TTL = 3600;
const APR_PASSWORD_MIN = 10;

/** מה מסך הכניסה מציג: mode (connected / detached / unavailable), והאם יש כניסה בסיסמה. */
function apr_login_config(array $env): array
{
    $mode = apr_platform_mode($env['platform_user'] ?? null);
    $password = false;
    if ($mode === 'detached') {
        $cfg = apr_platform_config();
        if ($cfg === null) $mode = 'unavailable';
        else $password = ($cfg['login']['password'] ?? null) === true;
    }
    return ['mode' => $mode, 'platform' => $mode === 'detached', 'password' => $password];
}

/** הכניסה בסיסמה פתוחה? אחרת — 503 כשהמעטפת לא עונה, 403 בכל מקרה אחר (מחוברת, או שהמנהל סגר). */
function apr_password_login_guard(array $env): void
{
    $cfg = apr_login_config($env);
    if ($cfg['mode'] === 'unavailable') apr_fail('login_unavailable');
    if (!$cfg['password']) apr_fail('password_login_off');
}

function apr_login_redirect(string $error = ''): array
{
    return ['_redirect' => apr_app_path() . ($error !== '' ? '#/login/' . $error : '')];
}

// ── כניסה עם הכלים הדיגיטליים ────────────────────────────────

function apr_sso_return(): string
{
    return apr_app_url('api.php?action=sso-callback');
}

function apr_sso_start(array $env): array
{
    if (apr_login_config($env)['mode'] !== 'detached') return apr_login_redirect();
    $state = bin2hex(random_bytes(16));
    apr_insert('sso_states', ['state_hash' => hash('sha256', $state), 'created_at' => apr_now(), 'expires_at' => apr_iso(apr_time() + APR_SSO_STATE_TTL)]);
    $GLOBALS['apr_sso_cookie_out'] = $state;            // קושר את ה-state לדפדפן שהתחיל את הכניסה
    return ['_redirect' => apr_platform_url(http_build_query(['client' => APR_CLIENT_ID, 'state' => $state, 'return' => apr_sso_return()]))];
}

/** דחיית זהות: שורה ביומן הטכני עם הסיבה (בלי האסימון), והמשתמש חוזר למסך הכניסה. */
function apr_sso_reject(string $reason, string $error = 'invalid'): array
{
    $GLOBALS['apr_sso_reason'] = $reason;
    if (function_exists('devloop_log_write')) devloop_log_write('warn', 'auth', 'sso_rejected', ['message' => $reason]);
    return apr_login_redirect($error);
}

/** בודק זהות חתומה. מחזיר את ה-payload, או מחרוזת — הסיבה הטכנית לדחייה. */
function apr_sso_verify(string $token, string $state): array|string
{
    [$payload, $sig] = array_pad(explode('.', $token, 2), 2, '');
    $b64 = fn(string $s) => base64_decode(strtr($s, '-_', '+/'), true);
    $sigBin = $b64($sig);
    if ($payload === '' || !is_string($sigBin) || strlen($sigBin) !== SODIUM_CRYPTO_SIGN_BYTES) return 'bad_token';
    $ok = false;
    foreach ([false, true] as $fresh) {                 // חתימה שנכשלה — מרעננים את המפתח פעם אחת (המפתח התחלף)
        $key = apr_platform_sso_key($fresh);
        if ($key !== null && sodium_crypto_sign_verify_detached($sigBin, $payload, $key)) { $ok = true; break; }
    }
    if (!$ok) return 'bad_signature';
    $id = json_decode((string)$b64($payload), true);
    if (!is_array($id)) return 'bad_payload';
    if (($id['aud'] ?? null) !== APR_CLIENT_ID) return 'bad_aud';
    if (($id['iss'] ?? null) !== apr_platform_base() . '/') return 'bad_iss';
    if (!is_string($id['state'] ?? null) || !hash_equals($state, $id['state'])) return 'bad_state';
    if (($id['return'] ?? null) !== apr_sso_return()) return 'bad_return';
    if (!is_int($id['exp'] ?? null) || $id['exp'] + APR_SSO_SKEW < apr_time()) {
        apr_meta_set('sso_expired_at', (string)apr_time());   // אולי השעון סוטה — אזהרה בבדיקות התקינות
        return 'expired';
    }
    if (!is_string($id['jti'] ?? null) || $id['jti'] === '' || strlen($id['jti']) > 100) return 'bad_jti';
    try {
        apr_insert('sso_jti', ['jti' => $id['jti'], 'exp' => $id['exp'] + APR_SSO_SKEW]);
    } catch (PDOException) {
        return 'replay';
    }
    return $id;
}

function apr_sso_callback(array $get, array $env): array
{
    if (apr_login_config($env)['mode'] !== 'detached') return apr_login_redirect();
    $GLOBALS['apr_sso_cookie_out'] = '';
    $state = is_string($get['state'] ?? null) ? $get['state'] : '';
    // 1. state: נוצר כאן, בדפדפן הזה, לא נוצל ולא פג — ומסומן כנוצל מיד
    if (!preg_match('/^[a-f0-9]{32}$/', $state) || !hash_equals((string)($env['sso_state'] ?? ''), $state)) return apr_sso_reject('state_unknown');
    $used = apr_exec('UPDATE sso_states SET used_at = ? WHERE state_hash = ? AND used_at IS NULL AND expires_at > ?', [apr_now(), hash('sha256', $state), apr_now()]);
    if ($used !== 1) return apr_sso_reject('state_used_or_expired');
    // 2. המשתמש ביטל במעטפת
    if (isset($get['error'])) return apr_sso_reject('cancelled', 'cancelled');
    // 3–5. חתימה, aud, iss, return, exp, jti
    $id = apr_sso_verify(is_string($get['token'] ?? null) ? $get['token'] : '', $state);
    if (is_string($id)) return apr_sso_reject($id);
    $ident = apr_platform_identity(json_encode(['sub' => $id['sub'] ?? null, 'name' => $id['name'] ?? '', 'email' => $id['email'] ?? null,
        'email_verified' => $id['email_verified'] ?? false, 'granted' => true]));
    if (!$ident) return apr_sso_reject('bad_sub');
    $ident['platform_admin'] = null;                    // הזהות החתומה לא נושאת אותו — לא נוגעים בדגלים
    $user = apr_user_for($ident);
    if ($user['status'] !== 'active') return apr_sso_reject('suspended', 'suspended');
    apr_login_open($user, $env, 'sso');
    return apr_login_redirect();
}

/** פותח session חדש למשתמש וכותב את העוגייה. */
function apr_login_open(array $user, array $env, string $via): void
{
    $s = apr_session_for($user, '', (string)($env['ua'] ?? ''));
    // הכניסה עצמה היא בדיקת הרשאה שהצליחה (זהות חתומה ניתנת רק למורשה; בסיסמה — grant_status נבדק לפני כן)
    apr_exec('UPDATE sessions SET grant_checked_at = ?, grant_ok_at = ? WHERE id = ?', [apr_now(), apr_now(), $s['id']]);
    $GLOBALS['apr_cookie_out'] = $s['new_token'];
    apr_audit('user.login', 'user', $user['pid'], ['via' => $via], actor: (int)$user['id']);
}

// ── סיסמה משלה ───────────────────────────────────────────────

function apr_login_keys(string $email, array $env): array
{
    return ['e:' . apr_mail_hash($email), 'i:' . hash_hmac('sha256', 'ip:' . (string)($env['ip'] ?? ''), apr_secret())];
}

/**
 * ניסיון כניסה נספר **לפני** בדיקת הסיסמה, באותה טרנזקציה שבודקת את הנעילה (BEGIN IMMEDIATE) — כך N בקשות במקביל
 * אינן N ניחושים. הניסיון החמישי בתוך 15 דקות נועל ל-15 דקות. מחזיר false כשהמפתח נעול (ואז לא סופר).
 */
function apr_login_attempt(array $keys): bool
{
    return apr_tx(function () use ($keys) {
        $now = apr_time();
        foreach ($keys as $k) {
            $until = apr_val('SELECT locked_until FROM login_attempts WHERE key = ?', [$k]);
            if (is_string($until) && $until > apr_iso($now)) return false;
        }
        foreach ($keys as $k) {
            $r = apr_row('SELECT count, updated_at FROM login_attempts WHERE key = ?', [$k]);
            $count = $r && strtotime($r['updated_at']) > $now - APR_LOGIN_LOCK ? (int)$r['count'] + 1 : 1;
            apr_exec('INSERT INTO login_attempts (key, count, updated_at, locked_until) VALUES (?, ?, ?, ?)
                ON CONFLICT(key) DO UPDATE SET count = excluded.count, updated_at = excluded.updated_at, locked_until = excluded.locked_until',
                [$k, $count, apr_iso($now), $count >= APR_LOGIN_MAX_FAILS ? apr_iso($now + APR_LOGIN_LOCK) : null]);
        }
        return true;
    });
}

/** הכניסה הצליחה: המונה של המייל מתאפס, והניסיון הזה יורד מהמונה של ה-IP. */
function apr_login_succeeded(array $keys): void
{
    apr_exec('DELETE FROM login_attempts WHERE key = ?', [$keys[0]]);
    apr_exec('UPDATE login_attempts SET count = MAX(count - 1, 0), locked_until = NULL WHERE key = ?', [$keys[1]]);
}

/**
 * כניסה במייל ובסיסמה. מייל שלא קיים, סיסמה שגויה, חשבון בלי סיסמה, חשבון מושעה ומי שהמעטפת ביטלה לו את ההרשאה —
 * אותה תשובה בדיוק. הנעילה נבדקת לפני הסיסמה: בזמן נעילה גם הסיסמה הנכונה נדחית.
 */
function apr_login(array $in, array $env): array
{
    apr_password_login_guard($env);
    $email = mb_strtolower(trim(is_string($in['email'] ?? null) ? $in['email'] : ''));
    $password = is_string($in['password'] ?? null) ? $in['password'] : '';
    $keys = apr_login_keys($email, $env);
    if (!apr_login_attempt($keys)) apr_fail('login_locked');
    $user = $email !== '' ? apr_row('SELECT * FROM users WHERE email = ? AND password_hash IS NOT NULL', [$email]) : null;
    // גם כשאין חשבון — מחשבים hash, כדי שזמן התשובה לא יסגיר אם הכתובת רשומה
    static $dummy = null;
    $dummy ??= password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
    $ok = password_verify($password, $user['password_hash'] ?? $dummy) && $user && $user['status'] === 'active';
    if (!$ok) apr_fail('login_failed');
    // ההרשאה במעטפת היא האישור גם כאן: בוטלה → כמו סיסמה שגויה; המעטפת לא עונה → נכשל סגור
    $granted = apr_grant_status($user['platform_sub']);
    if ($granted === null) apr_fail('login_unavailable');
    if (!$granted) apr_fail('login_failed');
    apr_login_succeeded($keys);
    apr_actor((int)$user['id']);
    apr_login_open($user, $env, 'password');
    return [];
}

function apr_password_check(mixed $v): string
{
    if (!is_string($v) || mb_strlen($v) < APR_PASSWORD_MIN) apr_fail('password_short');
    if (strlen($v) > 72) apr_fail('password_long');     // bcrypt מתעלם ממה שאחרי 72 בתים
    return $v;
}

/** קביעת סיסמה — המשתמש עצמו, כשהוא מחובר. מנתקת את שאר ה-sessions שלו. */
function apr_password_set(array $ctx, array $in): array
{
    $password = apr_password_check($in['password'] ?? null);
    return apr_tx(function () use ($ctx, $password) {
        $uid = (int)$ctx['user']['id'];
        apr_exec('UPDATE users SET password_hash = ?, must_change_pw = 0, updated_at = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), apr_now(), $uid]);
        apr_exec('DELETE FROM sessions WHERE user_id = ? AND id <> ?', [$uid, $ctx['session']]);
        apr_exec('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [apr_now(), $uid]);   // קישורי איפוס פתוחים מתבטלים
        apr_audit('user.password_set', 'user', $ctx['user']['pid']);
        return [];
    });
}

/**
 * שכחתי סיסמה: התשובה זהה תמיד. מייל נשלח רק לחשבון פעיל **שקבע סיסמה** ושהמייל שלו **אומת** — מי שלא קבע סיסמה אין
 * לו כניסה בסיסמה (§5.3), ומייל לא מאומת אינו הוכחה שהכתובת של בעל החשבון. עד 5 בקשות ב-15 דקות לכתובת ול-IP.
 */
function apr_forgot(array $in, array $env): array
{
    apr_password_login_guard($env);
    $email = mb_strtolower(trim(is_string($in['email'] ?? null) ? $in['email'] : ''));
    if ($email === '') return [];
    $ipKey = 'f' . apr_login_keys($email, $env)[1];
    if (!apr_limit($ipKey, APR_LOGIN_MAX_FAILS, APR_LOGIN_LOCK) || !apr_limit('f:' . apr_mail_hash($email), APR_LOGIN_MAX_FAILS, APR_LOGIN_LOCK)) return [];
    $user = apr_row("SELECT * FROM users WHERE email = ? AND status = 'active' AND password_hash IS NOT NULL AND email_verified = 1", [$email]);
    if ($user) {
        $token = bin2hex(random_bytes(24));
        $id = apr_insert('password_resets', ['user_id' => $user['id'], 'token_hash' => hash('sha256', $token), 'created_at' => apr_now(),
            'expires_at' => apr_iso(apr_time() + APR_RESET_TTL)]);
        apr_mail_send('reset', $email, 'בחירת סיסמה חדשה — שמאות מקרקעין',
            "שלום,\n\nהתקבלה בקשה לבחור סיסמה חדשה לחשבון שלך בשמאות מקרקעין.\n\n" . apr_app_url('#/reset/' . $token)
            . "\n\nהקישור תקף לשעה, ולשימוש אחד. אם לא ביקשת — אפשר להתעלם מהמייל.\n", 'reset:' . $id);
    }
    return [];
}

/** בחירת סיסמה חדשה מהקישור: פעם אחת, עד שעה. מנתקת את כל ה-sessions של המשתמש. */
function apr_reset(array $in, array $env): array
{
    apr_password_login_guard($env);
    $token = is_string($in['token'] ?? null) && preg_match('/^[a-f0-9]{48}$/', $in['token']) ? $in['token'] : apr_fail('reset_invalid');
    $password = apr_password_check($in['password'] ?? null);
    return apr_tx(function () use ($token, $password) {
        $r = apr_row('SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?', [hash('sha256', $token), apr_now()]) ?? apr_fail('reset_invalid');
        $user = apr_row("SELECT * FROM users WHERE id = ? AND status = 'active'", [$r['user_id']]) ?? apr_fail('reset_invalid');
        apr_exec('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [apr_now(), $user['id']]);
        apr_exec('UPDATE users SET password_hash = ?, must_change_pw = 0, updated_at = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), apr_now(), $user['id']]);
        apr_exec('DELETE FROM sessions WHERE user_id = ?', [$user['id']]);
        if ($user['email'] !== null) apr_exec('DELETE FROM login_attempts WHERE key = ?', ['e:' . apr_mail_hash($user['email'])]);
        apr_audit('user.password_reset', 'user', $user['pid'], [], actor: (int)$user['id']);
        return [];
    });
}

/** יציאה — רק במצב מנותק. במצב מחוברת יוצאים מהמעטפת. */
function apr_logout(array $ctx): array
{
    if ($ctx['mode'] !== 'detached') apr_fail('forbidden');
    apr_exec('DELETE FROM sessions WHERE id = ?', [$ctx['session']]);
    apr_audit('user.logout', 'user', $ctx['user']['pid']);
    $GLOBALS['apr_cookie_out'] = '';
    return [];
}

apr_job_register('auth.cleanup', 'ניקוי: מצבי כניסה, זהויות שנוצלו, קישורי איפוס וקודי אישור שפגו', function (): string {
    $now = apr_now();
    apr_exec('DELETE FROM sso_states WHERE expires_at < ?', [$now]);
    apr_exec('DELETE FROM sso_jti WHERE exp < ?', [apr_time()]);
    apr_exec('DELETE FROM password_resets WHERE expires_at < ?', [apr_iso(apr_time() - 86400)]);
    apr_exec('DELETE FROM confirm_codes WHERE expires_at < ?', [$now]);
    apr_exec('DELETE FROM login_attempts WHERE updated_at < ?', [apr_iso(apr_time() - 86400)]);
    return 'done';
}, 3600);
