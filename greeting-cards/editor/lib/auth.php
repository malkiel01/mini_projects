<?php
/**
 * משתמשים של סטודיו העיצוב — מערכת משלה (כלל היסוד: משתמשי הפלטפורמה אינם משתמשי האפליקציה).
 *
 * שתי דרכי כניסה:
 * - **מהפלטפורמה** (כל עוד האפליקציה מאחורי השער): השער כותב את הזהות ל-PLATFORM_USER, ומשתמש מקושר
 *   ל-sub שלו ונכנס לבד. אין סיסמה.
 * - **קוד במייל** (לדומיין עצמאי): 6 ספרות, 10 דקות, 5 ניסיונות. אין סיסמאות לשמור.
 *
 * הסשן שלנו: עוגייה gc_studio (HttpOnly, Secure, SameSite=Lax), ובמסד רק hash של האסימון.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const SESSION_COOKIE = 'gc_studio';
const SESSION_DAYS = 60;

function cookiePath(): string {
    // רק תיקיית האפליקציה — העוגייה לא נשלחת לאפליקציות אחרות באותו דומיין.
    $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    return dirname($dir) . '/';
}

function setSessionCookie(string $value, int $expires): void {
    setcookie(SESSION_COOKIE, $value, [
        'expires' => $expires, 'path' => cookiePath(), 'httponly' => true, 'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
}

function startSession(int $userId): void {
    $token = bin2hex(random_bytes(32));
    $expires = time() + SESSION_DAYS * 86400;
    db()->prepare('INSERT INTO sessions (token_hash, user_id, expires_at, created_at) VALUES (?, ?, ?, ?)')
        ->execute([hash('sha256', $token), $userId, $expires, nowIso()]);
    db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([nowIso(), $userId]);
    setSessionCookie($token, $expires);
}

function endSession(): void {
    $token = (string)($_COOKIE[SESSION_COOKIE] ?? '');
    if ($token !== '') db()->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([hash('sha256', $token)]);
    setSessionCookie('', time() - 3600);
}

/** המשתמש המחובר, או null. מהפלטפורמה — נכנס (ונוצר) לבד. */
function currentUser(): ?array {
    static $user = false;
    if ($user !== false) return $user;
    $user = null;

    $platform = json_decode((string)($_SERVER['PLATFORM_USER'] ?? ''), true);
    $sub = (string)($platform['sub'] ?? '');

    $token = (string)($_COOKIE[SESSION_COOKIE] ?? '');
    if ($token !== '') {
        $st = db()->prepare('SELECT u.* FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token_hash = ? AND s.expires_at > ?');
        $st->execute([hash('sha256', $token), time()]);
        $row = $st->fetch() ?: null;
        // מחובר דרך הפלטפורמה כחשבון אחר — הזהות של השער קובעת, לא העוגייה.
        if ($row && ($sub === '' || $row['platform_sub'] === null || $row['platform_sub'] === $sub)) $user = $row;
    }
    if (!$user && $sub !== '') $user = platformUser($sub, $platform);
    if ($user && $user['status'] !== 'active') $user = null;
    return $user;
}

/** משתמש לפי הזהות מהשער: קיים לפי sub, או לפי מייל מאומת, או חדש. */
function platformUser(string $sub, array $identity): array {
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM users WHERE platform_sub = ?');
    $st->execute([$sub]);
    $user = $st->fetch();
    if (!$user) {
        $email = !empty($identity['email_verified']) && !empty($identity['email']) ? mb_strtolower((string)$identity['email']) : null;
        if ($email) {
            $st = $pdo->prepare('SELECT * FROM users WHERE email = ? AND platform_sub IS NULL');
            $st->execute([$email]);
            $user = $st->fetch() ?: null;
        }
        if ($user) {
            $pdo->prepare('UPDATE users SET platform_sub = ? WHERE id = ?')->execute([$sub, $user['id']]);
        } else {
            $pdo->prepare('INSERT INTO users (email, name, platform_sub, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$email, mb_substr(trim((string)($identity['name'] ?? '')), 0, 80), $sub, nowIso()]);
            audit((int)$pdo->lastInsertId(), 'user.created', ['via' => 'platform']);
        }
        $st = $pdo->prepare('SELECT * FROM users WHERE platform_sub = ?');
        $st->execute([$sub]);
        $user = $st->fetch();
    }
    // הבעלים של ממשק הניהול (לפי חשבון הפלטפורמה) הוא גם הבעלים כאן.
    if ($user['role'] !== 'owner' && in_array($sub, platformOwners(), true)) {
        $pdo->prepare("UPDATE users SET role = 'owner' WHERE id = ?")->execute([$user['id']]);
        $user['role'] = 'owner';
    }
    // הגענו לכאן כי אין סשן תקף בעוגייה — פותחים אחד, כדי שגם בלי השער (או אחרי שיצא) יישאר מחובר.
    startSession((int)$user['id']);
    return $user;
}

function platformOwners(): array {
    $file = dirname(__DIR__, 2) . '/data/admin_config.json';
    $cfg = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
    return array_map('strval', $cfg['owners'] ?? []);
}

function requireUser(): array {
    $u = currentUser();
    if (!$u) throw new ApiError('נדרשת כניסה', 401, ['login' => true]);
    return $u;
}

function publicUser(array $u): array {
    return ['id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role'], 'platform' => $u['platform_sub'] !== null];
}

// ─── קוד במייל ─────────────────────────────────────────────────────────────

function requestLoginCode(string $email): void {
    $email = mb_strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ApiError('כתובת מייל לא תקינה', 422);
    $pdo = db();
    $st = $pdo->prepare('SELECT expires_at FROM login_codes WHERE email = ?');
    $st->execute([$email]);
    $prev = $st->fetchColumn();
    // קוד אחד לדקה לכל כתובת — אי אפשר להציף תיבת דואר של מישהו.
    if ($prev && (int)$prev - 9 * 60 > time()) throw new ApiError('קוד כבר נשלח. נסו שוב בעוד דקה', 429);
    $code = (string)random_int(100000, 999999);
    $pdo->prepare('INSERT OR REPLACE INTO login_codes (email, code_hash, expires_at, attempts) VALUES (?, ?, ?, 0)')
        ->execute([$email, password_hash($code, PASSWORD_DEFAULT), time() + 600]);
    $host = $_SERVER['HTTP_HOST'] ?? 'mbe-plus.com';
    $ok = @mail($email, '=?UTF-8?B?' . base64_encode('קוד כניסה לסטודיו עיצוב') . '?=',
        "קוד הכניסה שלך: $code\nתקף ל-10 דקות. אם לא ביקשת — אפשר להתעלם.",
        "From: no-reply@$host\r\nContent-Type: text/plain; charset=UTF-8");
    if (!$ok) throw new ApiError('שליחת המייל נכשלה', 502);
}

function verifyLoginCode(string $email, string $code): array {
    $email = mb_strtolower(trim($email));
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM login_codes WHERE email = ?');
    $st->execute([$email]);
    $row = $st->fetch();
    if (!$row || (int)$row['expires_at'] < time() || (int)$row['attempts'] >= 5) throw new ApiError('הקוד פג או לא קיים — בקשו קוד חדש', 422);
    if (!password_verify(trim($code), $row['code_hash'])) {
        $pdo->prepare('UPDATE login_codes SET attempts = attempts + 1 WHERE email = ?')->execute([$email]);
        throw new ApiError('קוד שגוי', 422);
    }
    $pdo->prepare('DELETE FROM login_codes WHERE email = ?')->execute([$email]);
    $st = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([$email]);
    $user = $st->fetch();
    if (!$user) {
        $pdo->prepare('INSERT INTO users (email, created_at) VALUES (?, ?)')->execute([$email, nowIso()]);
        $id = (int)$pdo->lastInsertId();
        audit($id, 'user.created', ['via' => 'email']);
        $st->execute([$email]);
        $user = $st->fetch();
    }
    if ($user['status'] !== 'active') throw new ApiError('החשבון חסום', 403);
    startSession((int)$user['id']);
    return $user;
}
