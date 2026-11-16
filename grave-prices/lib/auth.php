<?php
/**
 * התחברות — platform/docs/auth.md, ‏sso.md סעיף 0. אין סיסמה משלנו.
 *
 * מחוברת (השער פעיל): הזהות ב-$_SERVER['PLATFORM_USER'], בכל בקשה. חשבון נפתח מיד בכניסה ראשונה, ו-is_dev
 * מתעדכן לפי platform_admin בכל כניסה. ה-session שייך ל-sub אחר — מחליפים.
 * מנותקת (אין זהות מהשער): ה-session שלנו (gp_session), שנפתח ב"כניסה עם הכלים הדיגיטליים" (sso.php).
 */

declare(strict_types=1);

const GP_CLIENT = 'grave-prices';

function gp_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function gp_app_path(): string
{
    return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
}

function gp_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') return;
    $dir = gp_data_dir() . '/sessions';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    session_save_path($dir);
    session_name('gp_session');
    ini_set('session.gc_maxlifetime', (string)(180 * 86400));
    session_set_cookie_params(['lifetime' => 180 * 86400, 'path' => gp_app_path(), 'secure' => gp_https(),
        'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

/** הזהות שהשער העביר — רק עם הרשאה. */
function gp_platform_identity(): ?array
{
    $j = json_decode((string)($_SERVER['PLATFORM_USER'] ?? ''), true);
    if (!is_array($j) || (string)($j['sub'] ?? '') === '' || (isset($j['granted']) && !$j['granted'])) return null;
    return $j;
}

/** החשבון המקושר ל-sub — נפתח אם אין. $isDev = null: לא משנים (זהות מ-sso.php אין בה platform_admin). */
function gp_user_upsert(string $sub, string $name, string $email, ?bool $isDev): array
{
    $pdo = db();
    $now = now_iso();
    $pdo->prepare('INSERT INTO users (sub, name, email, is_dev, created_at) VALUES (?, ?, ?, ?, ?) ON CONFLICT(sub) DO NOTHING')
        ->execute([$sub, $name, $email ?: null, $isDev ? 1 : 0, $now]);
    $pdo->prepare('UPDATE users SET name = ?, email = COALESCE(?, email), is_dev = COALESCE(?, is_dev), last_seen_at = ? WHERE sub = ?')
        ->execute([$name, $email ?: null, $isDev === null ? null : (int)$isDev, $now, $sub]);
    $st = $pdo->prepare('SELECT * FROM users WHERE sub = ?');
    $st->execute([$sub]);
    return $st->fetch();
}

function gp_login(array $u, string $via): void
{
    if (PHP_SAPI !== 'cli') session_regenerate_id(true);
    $_SESSION = ['uid' => (int)$u['id'], 'csrf' => bin2hex(random_bytes(16))];
    gp_audit((int)$u['id'], 'login', null, ['via' => $via]);
}

/** המשתמש של הבקשה, או null. */
function gp_user(): ?array
{
    gp_session_start();
    if ($id = gp_platform_identity()) {
        $u = gp_user_upsert((string)$id['sub'], (string)($id['name'] ?? ''), (string)($id['email'] ?? ''), !empty($id['platform_admin']));
        if ((int)($_SESSION['uid'] ?? 0) !== (int)$u['id']) gp_login($u, 'gate');
        return $u;
    }
    $uid = (int)($_SESSION['uid'] ?? 0);
    if (!$uid) return null;
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$uid]);
    return $st->fetch() ?: null;
}

function gp_csrf(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}

function gp_check_csrf(): void
{
    if (!hash_equals(gp_csrf(), (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) throw new ApiError('הבקשה נדחתה. רעננו את הדף', 403);
}

/* ===== מנותקת: "כניסה עם הכלים הדיגיטליים" (sso.md סעיף 2) ===== */

/** כתובת החזרה — מה שהוצהר ב-app.json (התאמה מדויקת). */
function gp_sso_return(): string
{
    $app = json_decode((string)file_get_contents(dirname(__DIR__) . '/app.json'), true);
    return (string)($app['sso']['return'][0] ?? '');
}

function gp_sso_url(): string
{
    $r = gp_sso_return();
    return substr($r, 0, (int)strpos($r, '/' . GP_CLIENT . '/')) . '/platform/sso.php';
}

/** המפתח הציבורי של הפלטפורמה — במטמון ב-data/. */
function gp_sso_key(bool $fresh = false): string
{
    $cache = gp_data_dir() . '/platform-key.json';
    $c = is_file($cache) ? json_decode((string)file_get_contents($cache), true) : null;
    if (!$fresh && is_array($c) && !empty($c['key'])) return (string)$c['key'];
    $raw = @file_get_contents(gp_sso_url() . '?action=key', false, stream_context_create(['http' => ['timeout' => 5]]));
    $j = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($j) || empty($j['key'])) throw new ApiError('הכניסה לא זמינה כרגע. נסו שוב בעוד כמה דקות', 503);
    @file_put_contents($cache, json_encode(['key' => $j['key'], 'at' => now_iso()]));
    return (string)$j['key'];
}

function gp_sso_start(): never
{
    gp_session_start();
    $_SESSION['sso_state'] = bin2hex(random_bytes(16));
    header('Location: ' . gp_sso_url() . '?' . http_build_query(['client' => GP_CLIENT, 'state' => $_SESSION['sso_state'], 'return' => gp_sso_return()]));
    exit;
}

/** בודק את הזהות החתומה. מחזיר אותה, או מחרוזת שגיאה. */
function gp_sso_verify(string $token, string $state, string $key): array|string
{
    $b64 = fn(string $s) => (string)base64_decode(strtr($s, '-_', '+/'));
    [$payload, $sig] = array_pad(explode('.', $token, 2), 2, '');
    $sig = $b64($sig);
    $key = $b64($key);
    if (strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
        || !sodium_crypto_sign_verify_detached($sig, $payload, $key)) return 'חתימה לא תקינה';
    $id = json_decode($b64($payload), true);
    if (!is_array($id) || ($id['aud'] ?? '') !== GP_CLIENT || !hash_equals($state, (string)($id['state'] ?? ''))
        || ($id['return'] ?? '') !== gp_sso_return() || (int)($id['exp'] ?? 0) < time() || (string)($id['sub'] ?? '') === '') {
        return 'זהות לא תקפה';
    }
    return $id;
}

function gp_sso_callback(array $g): never
{
    gp_session_start();
    $state = (string)($_SESSION['sso_state'] ?? '');
    unset($_SESSION['sso_state']);
    $err = '';
    if ($state === '' || !hash_equals($state, (string)($g['state'] ?? ''))) $err = 'state';
    elseif (isset($g['error'])) $err = 'denied';
    else {
        $id = gp_sso_verify((string)($g['token'] ?? ''), $state, gp_sso_key());
        if (is_string($id)) $id = gp_sso_verify((string)($g['token'] ?? ''), $state, gp_sso_key(true));   // אולי המפתח התחלף
        if (is_string($id)) $err = 'invalid';
        else gp_login(gp_user_upsert((string)$id['sub'], (string)($id['name'] ?? ''), (string)($id['email'] ?? ''), null), 'sso');
    }
    header('Location: ' . gp_app_path() . ($err ? '?login_error=' . $err : ''));
    exit;
}
