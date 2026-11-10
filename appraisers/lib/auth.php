<?php
/**
 * חשבונות ו-session (אפיון 01 §5.2). הזהות מגיעה מהמעטפת (lib/platform.php); החשבונות כאן הם של האפליקציה:
 *
 *   1. יש חשבון עם ה-sub → זה החשבון.
 *   2. אין, והמייל אומת במעטפת → חשבון באותו מייל שעוד לא קושר מתקשר עכשיו (user.link).
 *   3. אחרת → חשבון חדש, פעיל, מיד (user.create). ההרשאה במעטפת היא האישור — אין אישור כפול.
 *   platform_admin → מדליקים is_sysadmin אם כבוי; is_developer שווה תמיד ל-platform_admin שבזהות, ומתעדכן בכל כניסה
 *   לשני הכיוונים. כך הבעלים מקבל את המסכים שלו בכניסה הראשונה, ואין דרך להפוך למפתח מתוך האפליקציה.
 *
 * ה-session: העוגייה apr_session — אסימון אקראי (במסד רק ה-hash), אסימון ה-CSRF והמשרד הפעיל. 30 יום מהשימוש
 * האחרון. לא session של PHP (בשרת המשותף הוא נמחק אחרי 24 דקות). session ששייך ל-sub אחר — נמחק, ונפתח חדש.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';

const APR_COOKIE = 'apr_session';
const APR_SESSION_TTL = 30 * 86400;

/** החשבון לפי הזהות — מקושר, מתאחד לפי מייל מאומת, או נפתח. */
function apr_user_for(array $id): array
{
    try {
        return apr_tx(fn() => apr_user_resolve($id));
    } catch (PDOException $e) {
        // שתי בקשות ראשונות במקביל מאותו משתמש חדש: platform_sub ייחודי — המפסיד קורא מחדש
        if (!str_contains($e->getMessage(), 'UNIQUE')) throw $e;
        return apr_tx(fn() => apr_user_resolve($id));
    }
}

function apr_user_resolve(array $id): array
{
    $now = apr_now();
    $user = apr_row('SELECT * FROM users WHERE platform_sub = ?', [$id['sub']]);
    if (!$user && $id['email'] !== null && $id['email_verified']) {
        $user = apr_row('SELECT * FROM users WHERE email = ? AND platform_sub IS NULL', [$id['email']]);
        if ($user) {
            apr_exec('UPDATE users SET platform_sub = ?, email_verified = 1, updated_at = ? WHERE id = ?', [$id['sub'], $now, $user['id']]);
            apr_audit('user.link', 'user', $user['pid'], ['by' => 'email'], actor: (int)$user['id']);
        }
    }
    if (!$user) {
        // מייל שכבר שייך לחשבון אחר (למשל שני חשבונות מעטפת באותו מייל) — החשבון החדש נפתח בלי מייל
        $email = $id['email'] !== null && !apr_val('SELECT 1 FROM users WHERE email = ?', [$id['email']]) ? $id['email'] : null;
        $uid = apr_insert('users', ['pid' => apr_pid(), 'platform_sub' => $id['sub'], 'email' => $email,
            'email_verified' => $email !== null && $id['email_verified'] ? 1 : 0, 'name' => $id['name'],
            'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        $user = apr_row('SELECT * FROM users WHERE id = ?', [$uid]);
        apr_audit('user.create', 'user', $user['pid'], [], actor: $uid);
    }

    $set = [];
    if ($id['name'] !== '' && $id['name'] !== $user['name']) $set['name'] = $id['name'];
    if ($id['email'] !== null && $id['email'] !== $user['email'] && !apr_val('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$id['email'], $user['id']])) {
        $set['email'] = $id['email'];
    }
    if (($set['email'] ?? $user['email']) === $id['email'] && (int)$user['email_verified'] !== (int)$id['email_verified']) {
        $set['email_verified'] = (int)$id['email_verified'];
    }
    if ($id['platform_admin'] && !(int)$user['is_sysadmin']) {
        $set['is_sysadmin'] = 1;
        apr_audit('user.grant_sysadmin', 'user', $user['pid'], ['by' => 'platform_admin'], actor: null, kind: 'system');
    }
    // המפתח = מנהל הפלטפורמה שבזהות, לשני הכיוונים, בכל כניסה. אין דרך להפוך למפתח מתוך האפליקציה.
    if ((int)$user['is_developer'] !== (int)$id['platform_admin']) {
        $set['is_developer'] = (int)$id['platform_admin'];
        apr_audit($id['platform_admin'] ? 'user.developer_on' : 'user.developer_off', 'user', $user['pid'], [], actor: null, kind: 'system');
    }
    // לא כותבים למסד בכל בקשה: רק כשמשהו השתנה, או פעם ב-10 דקות
    if ($set || $user['last_seen_at'] === null || strtotime($user['last_seen_at']) < apr_time() - 600) {
        $set += ['last_seen_at' => $now];
        if (count($set) > 1) $set['updated_at'] = $now;
        apr_exec('UPDATE users SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($set))) . ' WHERE id = ?', [...array_values($set), $user['id']]);
        $user = apr_row('SELECT * FROM users WHERE id = ?', [$user['id']]);
    }
    return $user;
}

/**
 * ה-session של הבקשה: השורה מהמסד, ועוד 'new_token' — אסימון לעוגייה, כשצריך לכתוב אותה.
 */
function apr_session_for(array $user, string $cookie, string $ua = ''): array
{
    $now = apr_time();
    $valid = preg_match('/^[a-f0-9]{64}$/', $cookie) === 1;
    if ($valid) {
        $s = apr_row('SELECT * FROM sessions WHERE token_hash = ?', [hash('sha256', $cookie)]);
        if ($s && $s['platform_sub'] === $user['platform_sub'] && (int)$s['user_id'] === (int)$user['id'] && $s['expires_at'] > apr_iso($now)) {
            $refresh = strtotime($s['last_used_at']) < $now - 3600;
            if ($refresh) {
                apr_exec('UPDATE sessions SET last_used_at = ?, expires_at = ? WHERE id = ?', [apr_iso($now), apr_iso($now + APR_SESSION_TTL), $s['id']]);
            }
            return $s + ['new_token' => $refresh ? $cookie : null];
        }
        // פג, או שייך ל-sub אחר (מישהו אחר נכנס למעטפת באותו דפדפן) — נמחק; המשרד הפעיל שלו לא עובר
        if ($s) apr_exec('DELETE FROM sessions WHERE id = ?', [$s['id']]);
    }
    $token = bin2hex(random_bytes(32));
    // כניסה: המשרד הפעיל האחרון של המשתמש (מ-session אחר שלו); אם אינו זמין — ייבחר הראשון הזמין (lib/access.php)
    $last = apr_val('SELECT active_office_id FROM sessions WHERE user_id = ? AND active_office_id IS NOT NULL ORDER BY last_used_at DESC, id DESC LIMIT 1', [$user['id']]);
    $sid = apr_insert('sessions', ['user_id' => $user['id'], 'token_hash' => hash('sha256', $token), 'csrf' => bin2hex(random_bytes(32)),
        'active_office_id' => $last ?: null, 'platform_sub' => $user['platform_sub'], 'ua' => mb_substr($ua, 0, 300),
        'created_at' => apr_iso($now), 'last_used_at' => apr_iso($now), 'expires_at' => apr_iso($now + APR_SESSION_TTL)]);
    return apr_row('SELECT * FROM sessions WHERE id = ?', [$sid]) + ['new_token' => $token];
}

function apr_set_cookie(string $token): void
{
    if (headers_sent()) return;
    $path = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
    setcookie(APR_COOKIE, $token, [
        'expires' => time() + APR_SESSION_TTL,
        'path' => $path,                        // תיקיית האפליקציה בלבד
        'secure' => !defined('APR_DEV'),        // בפיתוח מקומי (http) — בלי Secure
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
