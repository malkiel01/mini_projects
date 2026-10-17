<?php
/**
 * חשבונות: הרשמה, אימות מייל, כניסה, איפוס סיסמה, ופעולות המפתח.
 *
 * מחזור חיים של חשבון:
 *   unverified ──קישור במייל──▶ pending ──המפתח מאשר──▶ active
 *                                        ╰─דחייה──▶ deleted
 *   active ◀──▶ blocked (חסימה מנתקת מיד מכל המכשירים)
 *
 * החשבון הראשון שמאמת מייל הופך למפתח, ופעיל מיד — אחרת אין מי שיאשר.
 * (בתקופת הפיתוח האפליקציה מאחורי שער הפלטפורמה, אז רק אתה מגיע לכאן.)
 *
 * המפתח רואה רק מטא-דאטה על חשבונות. אין כאן שום פעולה שמחזירה תוכן.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail.php';

const DT_COOKIE        = 'dt_session';
const SESSION_DAYS     = 30;
const VERIFY_HOURS     = 48;
const RESET_MINUTES    = 60;
const ONLINE_SECONDS   = 300;
const LOGIN_MAX_FAILS  = 5;
const LOCK_MINUTES     = 15;
const FORM_MAX_PER_IP  = 10;   // הרשמה / שכחתי סיסמה — לכל IP, לפני נעילה

final class ApiError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}

function iso_in(int $seconds): string
{
    return gmdate('Y-m-d\TH:i:s\Z', time() + $seconds);
}

function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function token_hash(string $token): string
{
    return hash('sha256', $token);
}

// ── אימות קלט ────────────────────────────────────────────────

function valid_email($e): string
{
    $e = trim((string)$e);
    if ($e === '' || strlen($e) > 190 || !filter_var($e, FILTER_VALIDATE_EMAIL)) {
        throw new ApiError('כתובת מייל לא תקינה', 422);
    }
    return mb_strtolower($e);
}

function valid_password($p): string
{
    $p = (string)$p;
    if (mb_strlen($p) < 8)   throw new ApiError('סיסמה: לפחות 8 תווים', 422);
    if (strlen($p) > 200)    throw new ApiError('סיסמה ארוכה מדי', 422);
    return $p;
}

function valid_name($n): string
{
    $n = trim(preg_replace('/\s+/u', ' ', (string)$n));
    if (mb_strlen($n) < 2 || mb_strlen($n) > 60) throw new ApiError('שם: 2 עד 60 תווים', 422);
    return $n;
}

// ── הגבלת ניסיונות ───────────────────────────────────────────

function lock_remaining(array $keys): int
{
    $max = 0;
    $st = db()->prepare('SELECT locked_until FROM login_attempts WHERE key = ?');
    foreach ($keys as $k) {
        $st->execute([$k]);
        $until = $st->fetchColumn();
        if ($until) $max = max($max, strtotime($until) - time());
    }
    return max(0, $max);
}

function record_failure(array $keys, int $limit): void
{
    $up = db()->prepare(
        'INSERT INTO login_attempts (key, failures) VALUES (?, 1)
         ON CONFLICT(key) DO UPDATE SET failures = failures + 1'
    );
    $lock = db()->prepare('UPDATE login_attempts SET locked_until = ?, failures = 0 WHERE key = ? AND failures >= ?');
    foreach ($keys as $k) {
        $up->execute([$k]);
        $lock->execute([iso_in(LOCK_MINUTES * 60), $k, $limit]);
    }
}

function clear_failures(array $keys): void
{
    $st = db()->prepare('DELETE FROM login_attempts WHERE key = ?');
    foreach ($keys as $k) $st->execute([$k]);
}

function guard_form(string $kind): void
{
    $keys = ["$kind-ip:" . client_ip()];
    if (lock_remaining($keys) > 0) throw new ApiError('יותר מדי ניסיונות. נסו שוב בעוד רבע שעה', 429);
    record_failure($keys, FORM_MAX_PER_IP);
}

// ── אסימונים במייל ───────────────────────────────────────────

function issue_email_token(int $userId, string $purpose, int $ttl, ?string $payload = null): string
{
    $token = bin2hex(random_bytes(24));
    db()->prepare(
        'INSERT INTO email_tokens (token_hash, user_id, purpose, created_at, expires_at, payload_enc)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([token_hash($token), $userId, $purpose, now_iso(), iso_in($ttl), seal($payload)]);
    return $token;
}

/** מחזיר את הרשומה ומסמן אותה משומשת — באותה טרנזקציה, כדי שקישור לא ישמש פעמיים. */
function consume_email_token(string $token, string $purpose): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'SELECT * FROM email_tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > ?'
        );
        $st->execute([token_hash($token), $purpose, now_iso()]);
        $row = $st->fetch();
        if (!$row) throw new ApiError('הקישור אינו בתוקף. אפשר לבקש קישור חדש', 410);
        $pdo->prepare('UPDATE email_tokens SET used_at = ? WHERE token_hash = ?')->execute([now_iso(), $row['token_hash']]);
        $pdo->commit();
        return $row;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ── הרשמה ואימות ─────────────────────────────────────────────

/**
 * תמיד אותה תשובה — גם כשהכתובת כבר רשומה. אחרת ההרשמה הייתה מגלה מי
 * משתמש באפליקציה.
 */
function auth_register(array $b): array
{
    guard_form('register');
    $email = valid_email($b['email'] ?? '');
    $pass  = valid_password($b['password'] ?? '');
    $name  = valid_name($b['name'] ?? '');

    $st = db()->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([$email]);
    $u = $st->fetch();

    if (!$u) {
        // הסיסמה נקבעת רק באימות (מתוך האסימון). עד אז — hash שלא מתאים לשום דבר.
        db()->prepare('INSERT INTO users (email, password_hash, display_name, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$email, '!', $name, now_iso()]);
        $u = ['id' => (int)db()->lastInsertId(), 'status' => 'unverified'];
    }
    if ($u['status'] === 'unverified') {
        // הגיע מהזמנה בוואטסאפ: האסימון נשמר באימות, והשיתוף נוצר כשהכתובת מאומתת
        $invite = preg_match('/^[0-9a-f]{48}$/', (string)($b['invite'] ?? '')) ? (string)$b['invite'] : null;
        $payload = json_encode(['hash' => password_hash($pass, PASSWORD_DEFAULT), 'name' => $name, 'invite' => $invite], JSON_UNESCAPED_UNICODE);
        mail_verify($email, $name, issue_email_token((int)$u['id'], 'verify', VERIFY_HOURS * 3600, $payload));
    }
    return ['ok' => true, 'message' => 'שלחנו מייל לאימות הכתובת. הקישור בתוקף 48 שעות.'];
}

function auth_verify(array $b): array
{
    $row = consume_email_token((string)($b['token'] ?? ''), 'verify');
    $p = json_decode((string)unseal($row['payload_enc']), true);

    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$row['user_id']]);
    $u = $st->fetch();
    if (!$u || $u['status'] !== 'unverified') {
        return ['ok' => true, 'status' => $u['status'] ?? 'deleted', 'message' => 'הכתובת כבר אומתה'];
    }

    $first = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_developer = 1')->fetchColumn() === 0;
    $status = $first ? 'active' : 'pending';
    // הזמנה ממפתח היא האישור עצמו: החשבון פעיל מיד. הזמנה ממשתמש רגיל — השיתוף נוצר, והחשבון ממתין לאישור.
    $invited = null;
    if (!empty($p['invite']) && function_exists('invite_redeem')) {
        try { $invited = invite_redeem((int)$u['id'], (string)$p['invite']); } catch (ApiError) { $invited = null; }
    }
    if ($invited) {
        $st = $pdo->prepare("SELECT is_developer FROM users WHERE id = ? AND status = 'active'");
        $st->execute([$invited['inviter']]);
        if ((int)$st->fetchColumn() === 1) $status = 'active';
    }
    $pdo->prepare(
        'UPDATE users SET password_hash = ?, display_name = ?, status = ?, is_developer = ?, email_verified_at = ? WHERE id = ?'
    )->execute([$p['hash'], $p['name'], $status, $first ? 1 : 0, now_iso(), $u['id']]);
    // קישורי אימות אחרים לאותה כתובת — כבר לא רלוונטיים.
    $pdo->prepare("UPDATE email_tokens SET used_at = ? WHERE user_id = ? AND purpose = 'verify' AND used_at IS NULL")
        ->execute([now_iso(), $u['id']]);

    return [
        'ok' => true,
        'status' => $status,
        'message' => $first
            ? 'הכתובת אומתה. זה החשבון הראשון — הוא חשבון המפתח, ופעיל מיד.'
            : ($status === 'active'
                ? 'הכתובת אומתה והחשבון פעיל. אפשר להיכנס — מה ששותף איתך כבר מחכה.'
                : 'הכתובת אומתה. הבקשה ממתינה לאישור, ותקבלו גישה ברגע שתאושר.'),
    ];
}

// ── כניסה, יציאה, משתמש נוכחי ────────────────────────────────

function cookie_path(): string
{
    return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
}

function set_session_cookie(string $value, int $expires): void
{
    if (PHP_SAPI === 'cli') return;
    setcookie(DT_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => cookie_path(),
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** מחזיר את האסימון (לבדיקות); בשרת הוא נשלח בעוגייה. */
function auth_login(array $b): array
{
    $email = mb_strtolower(trim((string)($b['email'] ?? '')));
    $pass  = (string)($b['password'] ?? '');
    $keys  = ['email:' . $email, 'ip:' . client_ip()];
    $wait  = lock_remaining($keys);
    if ($wait > 0) throw new ApiError('יותר מדי ניסיונות. נסו שוב בעוד ' . (int)ceil($wait / 60) . ' דקות', 429);

    $st = db()->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([$email]);
    $u = $st->fetch();
    // בודקים סיסמה גם כשאין משתמש — כדי שזמן התגובה לא יסגיר אם הכתובת רשומה.
    $real = $u && str_starts_with((string)$u['password_hash'], '$');
    $ok = password_verify($pass, $real ? $u['password_hash'] : password_hash('-', PASSWORD_DEFAULT)) && $real;
    if (!$u || !$ok) {
        record_failure($keys, LOGIN_MAX_FAILS);
        throw new ApiError('מייל או סיסמה שגויים', 401);
    }
    clear_failures($keys);

    $msg = [
        'unverified' => 'הכתובת עוד לא אומתה. חפשו את מייל האימות',
        'pending'    => 'החשבון ממתין לאישור',
        'blocked'    => 'החשבון חסום',
        'deleted'    => 'מייל או סיסמה שגויים',
    ];
    if ($u['status'] !== 'active') {
        throw new ApiError($msg[$u['status']], $u['status'] === 'deleted' ? 401 : 403);
    }

    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
    }

    $token = bin2hex(random_bytes(32));
    $now = now_iso();
    db()->prepare(
        'INSERT INTO sessions (token_hash, user_id, csrf, device, ip, created_at, last_seen_at, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([token_hash($token), $u['id'], bin2hex(random_bytes(16)),
        mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200), client_ip(),
        $now, $now, iso_in(SESSION_DAYS * 86400)]);
    db()->prepare('UPDATE users SET last_login_at = ?, last_seen_at = ? WHERE id = ?')->execute([$now, $now, $u['id']]);
    set_session_cookie($token, time() + SESSION_DAYS * 86400);
    return ['ok' => true, 'token' => $token];
}

/** המשתמש של העוגייה, או null. מעדכן "נראה לאחרונה" פעם בדקה לכל היותר. */
function current_session(?string $token = null): ?array
{
    $token ??= $_COOKIE[DT_COOKIE] ?? null;
    if (!is_string($token) || $token === '') return null;

    $st = db()->prepare(
        "SELECT s.token_hash, s.csrf, s.last_seen_at AS s_seen, u.*
         FROM sessions s JOIN users u ON u.id = s.user_id
         WHERE s.token_hash = ? AND s.expires_at > ? AND u.status = 'active'"
    );
    $st->execute([token_hash($token), now_iso()]);
    $row = $st->fetch();
    if (!$row) return null;

    if (time() - strtotime($row['s_seen']) > 60) {
        $now = now_iso();
        db()->prepare('UPDATE sessions SET last_seen_at = ? WHERE token_hash = ?')->execute([$now, $row['token_hash']]);
        db()->prepare('UPDATE users SET last_seen_at = ? WHERE id = ?')->execute([$now, $row['id']]);
    }
    return $row;
}

function public_user(array $u): array
{
    return [
        'id' => (int)$u['id'],
        'email' => $u['email'],
        'name' => $u['display_name'],
        'is_developer' => (bool)$u['is_developer'],
    ];
}

function auth_logout(?array $s): array
{
    if ($s) db()->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([$s['token_hash']]);
    set_session_cookie('', time() - 3600);
    return ['ok' => true];
}

// ── שכחתי סיסמה ──────────────────────────────────────────────

/** תמיד אותה תשובה — לא מגלים אם הכתובת רשומה. */
function auth_forgot(array $b): array
{
    guard_form('forgot');
    $email = valid_email($b['email'] ?? '');
    $st = db()->prepare("SELECT * FROM users WHERE email = ? AND status IN ('active','pending')");
    $st->execute([$email]);
    if ($u = $st->fetch()) send_reset((int)$u['id']);
    return ['ok' => true, 'message' => 'אם הכתובת רשומה — נשלח אליה קישור לאיפוס. הקישור בתוקף שעה.'];
}

function send_reset(int $userId): bool
{
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$userId]);
    $u = $st->fetch();
    if (!$u) return false;
    return mail_reset($u['email'], $u['display_name'], issue_email_token($userId, 'reset', RESET_MINUTES * 60));
}

/** סיסמה חדשה מנתקת את כל המכשירים — אם מישהו אחר היה בפנים, הוא בחוץ. */
function auth_reset(array $b): array
{
    $pass = valid_password($b['password'] ?? '');
    $row = consume_email_token((string)($b['token'] ?? ''), 'reset');
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($pass, PASSWORD_DEFAULT), $row['user_id']]);
    db()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$row['user_id']]);
    db()->prepare("UPDATE email_tokens SET used_at = ? WHERE user_id = ? AND purpose = 'reset' AND used_at IS NULL")
        ->execute([now_iso(), $row['user_id']]);
    return ['ok' => true, 'message' => 'הסיסמה עודכנה. אפשר להיכנס.'];
}

// ── המפתח: ניהול חשבונות, בלי תוכן ───────────────────────────

function dev_users(): array
{
    $rows = db()->query(
        "SELECT u.id, u.email, u.display_name, u.is_developer, u.status, u.created_at,
                u.last_login_at, u.last_seen_at,
                (SELECT COUNT(*) FROM sessions s WHERE s.user_id = u.id AND s.expires_at > '" . now_iso() . "') AS devices
         FROM users u WHERE u.status != 'deleted'
         ORDER BY CASE u.status WHEN 'pending' THEN 0 ELSE 1 END, u.created_at DESC"
    )->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['is_developer'] = (bool)$r['is_developer'];
        $r['devices'] = (int)$r['devices'];
        $r['online'] = $r['devices'] > 0 && $r['last_seen_at'] && time() - strtotime($r['last_seen_at']) < ONLINE_SECONDS;
        // נפח ומכסה — מספרים בלבד, בלי שום פרט על התוכן.
        if (function_exists('quota_of')) $r['quota'] = quota_of($r['id']) + ['used_mb' => round(usage_bytes($r['id']) / 1048576, 2)];
    }
    return ['ok' => true, 'users' => $rows, 'online_seconds' => ONLINE_SECONDS];
}

function dev_target(array $b, array $dev): array
{
    $st = db()->prepare("SELECT * FROM users WHERE id = ? AND status != 'deleted'");
    $st->execute([(int)($b['user_id'] ?? 0)]);
    $u = $st->fetch();
    if (!$u) throw new ApiError('המשתמש לא נמצא', 404);
    if ((int)$u['id'] === (int)$dev['id']) throw new ApiError('אי אפשר לבצע פעולה זו על החשבון שלך', 409);
    return $u;
}

function dev_user_action(array $b, array $dev): array
{
    $u = dev_target($b, $dev);
    $action = (string)($b['op'] ?? '');
    $allowed = [
        'approve' => ['pending'],
        'reject'  => ['pending', 'unverified'],
        'block'   => ['active', 'pending'],
        'unblock' => ['blocked'],
        'logout'  => ['active', 'pending', 'blocked'],
        'reset'   => ['active', 'pending'],
    ];
    if (!isset($allowed[$action])) throw new ApiError('פעולה לא מוכרת', 422);
    if (!in_array($u['status'], $allowed[$action], true)) throw new ApiError('הפעולה אינה מתאימה למצב החשבון', 409);

    $pdo = db();
    $kill = fn() => $pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$u['id']]);
    $set  = fn(string $s) => $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$s, $u['id']]);

    switch ($action) {
        case 'approve': $set('active'); break;
        // דחייה = מחיקה רכה. הכתובת לא תוכל להירשם שוב בלי שהמפתח יחזיר אותה.
        case 'reject':  $set('deleted'); $kill(); break;
        case 'block':   $set('blocked'); $kill(); break;
        case 'unblock': $set('active'); break;
        case 'logout':  $kill(); break;
        case 'reset':
            if (!send_reset((int)$u['id'])) throw new ApiError('שליחת המייל נכשלה', 502);
            return ['ok' => true, 'message' => 'נשלח קישור איפוס ל-' . $u['email']];
    }
    return ['ok' => true];
}
