<?php
/**
 * משתמשי האפליקציה עצמה (platform/docs/auth.md, sso.md). אין כאן סיסמאות:
 * - מחוברת לפלטפורמה: $_SERVER['PLATFORM_USER'] (השער כותב אותו רק למי שיש לו הרשאה) → החשבון המקושר ל-sub,
 *   ואם אין — איחוד לפי מייל, ואם אין — חשבון חדש, פעיל.
 * - מנותקת: "כניסה עם הכלים הדיגיטליים" (sso.php — זהות חתומה), ואז עוגייה משלנו: wp_session.
 * החשבון הראשון שנפתח — מנהל העורך (מאשר לקוחות הטמעה).
 */

require_once __DIR__ . '/db.php';

const PLATFORM_SSO = 'https://mbe-plus.com/mini_projects/platform/sso.php';
const APP_ID = 'word-processor';
const APP_COOKIE = 'wp_session';
const APP_SESSION_TTL = 30 * 86400;

function current_account(): ?array
{
    $raw = $_SERVER['PLATFORM_USER'] ?? '';
    if ($raw !== '') {
        $id = json_decode($raw, true);
        if (is_array($id) && !empty($id['sub'])) return account_for_identity($id);
    }
    // אין זהות מהשער: באפליקציה מחוברת זה אומר "אין משתמש" — וגם הסשן שלנו נמחק (יציאה מהפלטפורמה = יציאה מכאן)
    if (platform_connected()) {
        clear_app_session();
        return null;
    }
    return account_from_cookie();
}

/** sub → חשבון; אין — לפי מייל (בלי תלות באותיות); אין — חדש. */
function account_for_identity(array $id): array
{
    $sub = (string)$id['sub'];
    $email = is_string($id['email'] ?? null) && $id['email'] !== '' ? strtolower($id['email']) : null;
    $name = mb_substr(trim((string)($id['name'] ?? '')), 0, 120);
    $st = db()->prepare('SELECT * FROM accounts WHERE sub = ?');
    $st->execute([$sub]);
    $acc = $st->fetch(); $st->closeCursor();
    if (!$acc && $email) {
        $st = db()->prepare('SELECT * FROM accounts WHERE sub IS NULL AND email = ? COLLATE NOCASE');
        $st->execute([$email]);
        $acc = $st->fetch(); $st->closeCursor();
        if ($acc) {
            db()->prepare('UPDATE accounts SET sub = ? WHERE id = ?')->execute([$sub, $acc['id']]);
            wp_log('account_linked', "{$acc['id']} ↔ $sub (מייל)");
        }
    }
    if (!$acc) {
        $first = (int)db()->query('SELECT COUNT(*) FROM accounts')->fetchColumn() === 0;
        db()->prepare('INSERT INTO accounts (sub, email, name, role, created_at, last_seen) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$sub, $email, $name, $first ? 'admin' : 'user', time(), time()]);
        $acc = ['id' => (int)db()->lastInsertId()];
        wp_log('account_created', "{$acc['id']} ← $sub" . ($first ? ' (מנהל)' : ''));
    }
    // כתיבה רק כשמשהו השתנה (או פעם ב-5 דקות) — לא בכל בקשה: פחות תחרות על המסד
    $stale = !isset($acc['last_seen']) || time() - (int)$acc['last_seen'] > 300
        || ($name !== '' && $name !== ($acc['name'] ?? '')) || ($email !== null && $email !== ($acc['email'] ?? null));
    if ($stale) {
        db()->prepare('UPDATE accounts SET name = COALESCE(NULLIF(?, \'\'), name), email = COALESCE(?, email), last_seen = ? WHERE id = ?')
            ->execute([$name, $email, time(), $acc['id']]);
    }
    $st = db()->prepare('SELECT * FROM accounts WHERE id = ?');
    $st->execute([$acc['id']]);
    return $st->fetch();
}

/** האם האפליקציה מחוברת לפלטפורמה (sso.php?action=config), במטמון של דקה. תקלה → מחוברת (נכשל סגור). */
function platform_connected(): bool
{
    if (getenv('WP_DETACHED') === '1') return false;
    static $cached = null;
    if ($cached !== null) return $cached;
    $file = wp_data_dir() . '/sso-config.json';
    $j = is_file($file) && time() - filemtime($file) < 60 ? json_decode((string)file_get_contents($file), true) : null;
    if (!is_array($j)) {
        $ctx = stream_context_create(['http' => ['timeout' => 4, 'header' => "Cookie: humans_21909=1\r\n"]]);
        $raw = @file_get_contents(PLATFORM_SSO . '?action=config&client=' . APP_ID, false, $ctx);
        $j = $raw ? json_decode($raw, true) : null;
        if (is_array($j)) @file_put_contents($file, json_encode($j));
    }
    return $cached = !is_array($j) || !empty($j['connected']);
}

function account_from_cookie(): ?array
{
    $tok = $_COOKIE[APP_COOKIE] ?? '';
    if (!preg_match('/^[0-9a-f]{64}$/', $tok)) return null;
    $st = db()->prepare('SELECT a.* FROM app_sessions s JOIN accounts a ON a.id = s.account WHERE s.hash = ? AND s.expires > ?');
    $st->execute([hash('sha256', $tok), time()]);
    return $st->fetch() ?: null;
}

function start_app_session(int $account): void
{
    $tok = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO app_sessions (hash, account, expires) VALUES (?, ?, ?)')->execute([hash('sha256', $tok), $account, time() + APP_SESSION_TTL]);
    setcookie(APP_COOKIE, $tok, ['expires' => time() + APP_SESSION_TTL, 'path' => app_path(), 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
}

function clear_app_session(): void
{
    $tok = $_COOKIE[APP_COOKIE] ?? '';
    if ($tok === '') return;
    db()->prepare('DELETE FROM app_sessions WHERE hash = ?')->execute([hash('sha256', $tok)]);
    if (!headers_sent()) setcookie(APP_COOKIE, '', ['expires' => 1, 'path' => app_path(), 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
}

function app_path(): string
{
    return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
}

// ---------- מנותקת: כניסה עם הכלים הדיגיטליים (sso.md, סעיף 2) ----------

function sso_start(): void
{
    $state = bin2hex(random_bytes(16));
    setcookie('wp_sso_state', $state, ['expires' => time() + 600, 'path' => app_path(), 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    header('Location: ' . PLATFORM_SSO . '?' . http_build_query(['client' => APP_ID, 'state' => $state, 'return' => sso_return_url()]));
    exit;
}

function sso_return_url(): string
{
    return 'https://' . ($_SERVER['HTTP_HOST'] ?? 'mbe-plus.com') . app_path() . 'api.php?action=sso_callback';
}

function sso_callback(): void
{
    $state = $_COOKIE['wp_sso_state'] ?? '';
    setcookie('wp_sso_state', '', ['expires' => 1, 'path' => app_path(), 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    $back = app_path();
    if ($state === '' || !hash_equals($state, (string)($_GET['state'] ?? '')) || isset($_GET['error'])) {
        header('Location: ' . $back . '#login-failed');
        exit;
    }
    $b64 = fn($s) => base64_decode(strtr($s, '-_', '+/'));
    [$payload, $sig] = array_pad(explode('.', (string)($_GET['token'] ?? ''), 2), 2, '');
    $key = platform_sso_key();
    $ok = $key && $sig !== '' && sodium_crypto_sign_verify_detached($b64($sig), $payload, $key);
    $id = $ok ? json_decode($b64($payload), true) : null;
    if (!is_array($id) || ($id['aud'] ?? '') !== APP_ID || ($id['state'] ?? '') !== $state
        || ($id['return'] ?? '') !== sso_return_url() || ($id['exp'] ?? 0) < time() || empty($id['sub'])) {
        header('Location: ' . $back . '#login-failed');
        exit;
    }
    $acc = account_for_identity($id);
    start_app_session((int)$acc['id']);
    header('Location: ' . $back);
    exit;
}

function platform_sso_key(): ?string
{
    $k = setting('platform_sso_key');
    if (!$k) {
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'header' => "Cookie: humans_21909=1\r\n"]]);
        $j = json_decode((string)@file_get_contents(PLATFORM_SSO . '?action=key', false, $ctx), true);
        if (!is_string($j['key'] ?? null)) return null;
        set_setting('platform_sso_key', $j['key']);
        $k = $j['key'];
    }
    return base64_decode(strtr($k, '-_', '+/'));
}
