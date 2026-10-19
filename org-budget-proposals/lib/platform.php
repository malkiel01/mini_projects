<?php
/**
 * כניסה דרך הפלטפורמה ("הכלים הדיגיטליים") — platform/docs/auth.md ו-sso.md סעיף 0.
 *
 * מחוברת (השער פעיל): השער כותב לכל בקשת PHP את $_SERVER['PLATFORM_USER'] — JSON של
 * {sub, name, email, email_verified}, ורק למי שיש לו הרשאה לאפליקציה. הוא קובע מי המשתמש:
 *   1. חשבון שמקושר ל-sub.
 *   2. אין — חשבון באותו מייל (כשהפלטפורמה אימתה אותו): מקשרים ונכנסים.
 *   3. אין — מסך חד-פעמי: "יש לי כבר חשבון" (מייל וסיסמה ישנים) או "אין לי חשבון" (חשבון חדש).
 * חשבון חדש נפתח כמו הרשמה רגילה — משתמש רגיל, ואם עוד אין מפתח הוא המפתח — אבל פעיל מיד:
 * ההרשאה בפלטפורמה היא האישור. הכניסה, ההרשמה והאיפוס בסיסמה — חסומים. הסיסמה לא נמחקת.
 *
 * מנותקת: הכול כמו לפני — כניסה בסיסמה של האפליקציה.
 * בלי PLATFORM_USER האפליקציה שואלת את הפלטפורמה אם היא מחוברת (sso.php?action=config, נשמר לדקה).
 * הפלטפורמה לא עונה — התשובה האחרונה; אף פעם לא ענתה — "הכניסה לא זמינה כרגע".
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

// בדומיין עצמאי, בלי פלטפורמה — '' (תמיד מנותקת). בבדיקות — קובץ מקומי.
if (!defined('OB_PLATFORM_CONFIG')) {
    define('OB_PLATFORM_CONFIG', 'https://mbe-plus.com/mini_projects/platform/sso.php?action=config&client=org-budget-proposals');
}
const PLATFORM_CONFIG_TTL = 60;

function platform_migrate(PDO $pdo): void
{
    $have = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
    if (!in_array('platform_sub', $have, true)) $pdo->exec('ALTER TABLE users ADD COLUMN platform_sub TEXT');
    if (!in_array('platform_created', $have, true)) $pdo->exec('ALTER TABLE users ADD COLUMN platform_created INTEGER NOT NULL DEFAULT 0');
    $pdo->exec(<<<'SQL'
        CREATE UNIQUE INDEX IF NOT EXISTS idx_users_platform_sub ON users(platform_sub) WHERE platform_sub IS NOT NULL;
        -- כל קישור / ניתוק של חשבון לפלטפורמה — מי, איך, מתי.
        CREATE TABLE IF NOT EXISTS platform_links (
            id      INTEGER PRIMARY KEY,
            at      TEXT NOT NULL,
            user_id INTEGER NOT NULL REFERENCES users(id),
            sub     TEXT NOT NULL,
            how     TEXT NOT NULL CHECK (how IN ('email','password','new','unlinked'))
        );
        SQL);
}

// ── מי המשתמש בפלטפורמה, והאם מחוברת ─────────────────────────

/** הזהות מהשער, או null. */
function platform_identity(): ?array
{
    $id = json_decode((string)($_SERVER['PLATFORM_USER'] ?? ''), true);
    if (!is_array($id) || !is_scalar($id['sub'] ?? null) || (string)$id['sub'] === '') return null;
    $email = is_string($id['email'] ?? null) && filter_var($id['email'], FILTER_VALIDATE_EMAIL) ? mb_strtolower($id['email']) : null;
    return [
        'sub' => (string)$id['sub'],
        'name' => trim((string)($id['name'] ?? '')),
        'email' => $email,
        'email_verified' => ($id['email_verified'] ?? false) === true,
    ];
}

/** true / false — מחוברת או מנותקת; null — לא ידוע (הפלטפורמה לא ענתה אף פעם). */
function platform_connected(): ?bool
{
    if (platform_identity()) return true;
    if (OB_PLATFORM_CONFIG === '') return false;

    $cache = OB_DATA_DIR . '/platform-config.json';
    $c = is_file($cache) ? json_decode((string)file_get_contents($cache), true) : null;
    if (!is_array($c) || time() - (int)($c['at'] ?? 0) > PLATFORM_CONFIG_TTL) {
        $ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
        $raw = @file_get_contents(OB_PLATFORM_CONFIG, false, $ctx);
        $cfg = $raw ? json_decode($raw, true) : null;
        if (is_array($cfg) && is_bool($cfg['connected'] ?? null)) {
            $c = ['at' => time(), 'connected' => $cfg['connected']];
            ensure_data_dir(OB_DATA_DIR);
            @file_put_contents($cache, json_encode($c));
        }
    }
    return is_array($c) && is_bool($c['connected'] ?? null) ? $c['connected'] : null;
}

/** כניסה, הרשמה ואיפוס בסיסמה — רק כשהאפליקציה מנותקת. */
function require_password_login(): void
{
    $c = platform_connected();
    if ($c === null) throw new ApiError('הכניסה לא זמינה כרגע. נסו שוב בעוד כמה דקות', 503);
    if ($c) throw new ApiError('הכניסה היא דרך הכלים הדיגיטליים', 403);
}

// ── החשבון של מי שנכנס דרך הפלטפורמה ──────────────────────────

function user_by(string $col, string $value): ?array
{
    $st = db()->prepare("SELECT * FROM users WHERE $col = ?");
    $st->execute([$value]);
    return $st->fetch() ?: null;
}

function platform_log(int $userId, string $sub, string $how): void
{
    db()->prepare('INSERT INTO platform_links (at, user_id, sub, how) VALUES (?, ?, ?, ?)')
        ->execute([now_iso(), $userId, $sub, $how]);
}

/**
 * מקשר חשבון ל-sub. חשבון שממתין לאישור או לאימות — פעיל: ההרשאה בפלטפורמה היא האישור.
 * חשבון חסום נשאר חסום (המפתח חסם אותו באפליקציה).
 */
function platform_attach(array $u, string $sub, string $how): array
{
    $status = in_array($u['status'], ['blocked', 'active'], true) ? $u['status'] : 'active';
    db()->prepare('UPDATE users SET platform_sub = ?, status = ?, email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?')
        ->execute([$sub, $status, now_iso(), $u['id']]);
    platform_log((int)$u['id'], $sub, $how);
    return user_by('id', (string)$u['id']);
}

/** החשבון המקושר, או המקושר עכשיו לפי מייל; null — אין, וצריך את המסך החד-פעמי. */
function platform_account(array $id): ?array
{
    if ($u = user_by('platform_sub', $id['sub'])) return $u;
    // איחוד לפי מייל — רק כשהפלטפורמה אימתה את המייל (sso.md, סעיף 4: "אפליקציה זהירה")
    if ($id['email'] && $id['email_verified'] && ($u = user_by('email', $id['email'])) && $u['platform_sub'] === null) {
        return platform_attach($u, $id['sub'], 'email');
    }
    return null;
}

/** "אין לי חשבון": חשבון חדש כמו בהרשמה, פעיל מיד. */
function platform_create(array $id): array
{
    $pdo = db();
    $email = $id['email'] ?? '';
    // מייל שכבר תפוס (חשבון שמקושר לזהות אחרת, או מייל שלא אומת) / אין מייל — כתובת פנימית
    if ($email === '' || user_by('email', $email)) {
        $email = 'platform-' . preg_replace('/[^a-z0-9_-]/i', '_', $id['sub']) . '@users.invalid';
    }
    $name = mb_strlen($id['name']) >= 2 ? mb_substr($id['name'], 0, 60) : 'משתמש';
    $first = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_developer = 1')->fetchColumn() === 0;
    $pdo->prepare(
        "INSERT INTO users (email, password_hash, display_name, is_developer, status, email_verified_at, created_at, platform_sub, platform_created)
         VALUES (?, '!', ?, ?, 'active', ?, ?, ?, 1)"
    )->execute([$email, $name, $first ? 1 : 0, $id['email'] ? now_iso() : null, now_iso(), $id['sub']]);
    $u = user_by('id', $pdo->lastInsertId());
    platform_log((int)$u['id'], $id['sub'], 'new');
    return $u;
}

/** ה-session שבעוגייה — נמחק (מי שהיה בדפדפן הזה לפני כן — בחוץ). */
function drop_cookie_session(): void
{
    $token = $_COOKIE[OB_COOKIE] ?? null;
    if (!is_string($token) || $token === '') return;
    db()->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([token_hash($token)]);
    set_session_cookie('', time() - 3600);
    unset($_COOKIE[OB_COOKIE]);
}

/** session של האפליקציה לחשבון — הקיים בדפדפן אם הוא שלו, אחרת חדש (ומי שהיה כאן לפניו — בחוץ). */
function platform_session(array $u): array
{
    $token = $_COOKIE[OB_COOKIE] ?? null;
    if (is_string($token) && $token !== '' && ($s = current_session($token)) && (int)$s['id'] === (int)$u['id']) return $s;
    drop_cookie_session();

    $token = new_session((int)$u['id']);
    $_COOKIE[OB_COOKIE] = $token;
    return current_session($token);
}

/**
 * המשתמש של הבקשה, ומצב הכניסה דרך הפלטפורמה.
 * state: off (מנותקת) · ok · login (מחוברת, אבל אין זהות — דף ציבורי) · link (מסך חד-פעמי) ·
 *        blocked (החשבון חסום באפליקציה) · unavailable (לא ידוע אם מחוברת).
 */
function resolve_session(): array
{
    $id = platform_identity();
    if (!$id) {
        $c = platform_connected();
        if ($c === false) return [current_session(), ['state' => 'off']];
        if ($c === true) {
            drop_cookie_session();   // יציאה מהפלטפורמה = יציאה מהאפליקציה
            return [null, ['state' => 'login']];
        }
        return [null, ['state' => 'unavailable']];
    }

    $info = ['name' => $id['name'], 'email' => $id['email']];
    $u = platform_account($id);
    if (!$u || $u['status'] !== 'active') {
        drop_cookie_session();
        return [null, ['state' => $u ? 'blocked' : 'link'] + $info];
    }
    $s = platform_session($u);
    // "חיבור חשבון ישן" בתפריט — רק לחשבון שנפתח כאן דרך הפלטפורמה
    return [$s, ['state' => 'ok', 'can_link' => (bool)$s['platform_created']] + $info];
}

// ── המסך החד-פעמי ────────────────────────────────────────────

/** פעולה של מי שנכנס דרך הפלטפורמה — רק בבקשת JSON: אתר זר לא יכול לשלוח כזו בלי CORS (אין כאן CSRF). */
function platform_guard(): array
{
    $id = platform_identity();
    if (!$id) throw new ApiError('הכניסה היא דרך הכלים הדיגיטליים', 403);
    if (!str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        throw new ApiError('הבקשה נדחתה', 415);
    }
    return $id;
}

/**
 * "יש לי כבר חשבון": מייל וסיסמה של החשבון הישן (5 ניסיונות ← רבע שעה). החשבון הישן מתקשר ל-sub.
 * כבר יש חשבון שנפתח דרך הפלטפורמה — הוא מתנתק (הנתונים שלו נשארים, בלי קישור).
 */
function platform_link(array $b): array
{
    $id = platform_guard();
    $keys = ['link:' . $id['sub']];
    $wait = lock_remaining($keys);
    if ($wait > 0) throw new ApiError('יותר מדי ניסיונות. נסו שוב בעוד ' . (int)ceil($wait / 60) . ' דקות', 429);

    $cur = user_by('platform_sub', $id['sub']);
    if ($cur && !$cur['platform_created']) throw new ApiError('החשבון כבר מחובר', 409);

    $old = user_by('email', mb_strtolower(trim((string)($b['email'] ?? ''))));
    $real = $old && str_starts_with((string)$old['password_hash'], '$');
    $ok = password_verify((string)($b['password'] ?? ''), $real ? $old['password_hash'] : password_hash('-', PASSWORD_DEFAULT)) && $real
        && $old['status'] !== 'deleted';
    if (!$ok) {
        record_failure($keys, LOGIN_MAX_FAILS);
        throw new ApiError('מייל או סיסמה שגויים', 401);
    }
    if ($old['platform_sub'] !== null) throw new ApiError('החשבון הזה כבר מחובר לחשבון אחר בכלים הדיגיטליים', 409);
    if ($old['status'] === 'blocked') throw new ApiError('החשבון חסום', 403);
    clear_failures($keys);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($cur) {
            $pdo->prepare('UPDATE users SET platform_sub = NULL WHERE id = ?')->execute([$cur['id']]);
            $pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$cur['id']]);
            platform_log((int)$cur['id'], $id['sub'], 'unlinked');
        }
        platform_attach($old, $id['sub'], 'password');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'message' => 'החשבון חובר'];
}

/** "אין לי חשבון": נפתח חשבון חדש, וההצעה לא חוזרת (נשארת בתפריט). */
function platform_new(): array
{
    $id = platform_guard();
    if (!user_by('platform_sub', $id['sub'])) platform_create($id);
    return ['ok' => true];
}
