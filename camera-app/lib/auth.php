<?php
/**
 * חשבונות וכניסה.
 *
 * אין הרשמה חופשית — זו מערכת שמראה את הבית של מישהו. המשתמש הראשון
 * נוצר במסך ההתקנה ונעשה מנהל; את כל השאר יוצר המנהל.
 *
 * שלושה תפקידים: admin (הכול), operator (צופה, מקליט, מסובב, מקטלג),
 * viewer (רק צופה). המחיקה, הגדרות המצלמות והמשתמשים — למנהל בלבד.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function sessionStart(): void {
    // ב-CLI אין עוגיות; סשן בזיכרון מספיק כדי שהבדיקות יריצו את אותו קוד.
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
    session_name('CAMERAS');
    session_start();
}

function userCount(): int {
    return (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
}

function currentUser(): ?array {
    sessionStart();
    if (empty($_SESSION['uid'])) return null;
    $st = db()->prepare('SELECT id, username, display_name, role, blocked FROM users WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    $user = $st->fetch();
    // חסימה מנתקת סשן קיים, לא רק מונעת כניסה חדשה.
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

function requireRole(string ...$roles): array {
    $user = requireUser();
    if (!in_array($user['role'], $roles, true)) throw new AppError('אין לך הרשאה לפעולה הזו', 403);
    return $user;
}

function requireAdmin(): array {
    return requireRole('admin');
}

/** צופה, מפעיל או מנהל — כל מי שרשאי לגעת במצלמה (לא רק להסתכל). */
function requireOperator(): array {
    return requireRole('admin', 'operator');
}

function validUsername(string $u): bool {
    return (bool) preg_match('/^[a-zA-Z0-9_.\-]{3,32}$/', $u);
}

function createUser(string $username, string $password, string $displayName, string $role = 'viewer'): int {
    $username = trim($username);
    if (!validUsername($username)) throw new AppError('שם משתמש: 3–32 תווים, אותיות לטיניות, ספרות, נקודה, קו');
    if (strlen($password) < 8) throw new AppError('הסיסמה חייבת להיות לפחות 8 תווים');
    if (!in_array($role, ['admin', 'operator', 'viewer'], true)) throw new AppError('תפקיד לא מוכר');
    $displayName = trim($displayName) !== '' ? trim($displayName) : $username;

    $st = db()->prepare('SELECT 1 FROM users WHERE username = ? COLLATE NOCASE');
    $st->execute([$username]);
    if ($st->fetch()) throw new AppError('שם המשתמש תפוס');

    db()->prepare('INSERT INTO users (username, password_hash, display_name, role, created_at)
                   VALUES (?,?,?,?,?)')
        ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $displayName, $role, nowIso()]);
    return (int) db()->lastInsertId();
}

/** מסך ההתקנה: המשתמש הראשון, ורק כשאין אף אחד. */
function setupFirstAdmin(string $username, string $password, string $displayName): int {
    if (userCount() > 0) throw new AppError('ההתקנה כבר בוצעה', 409);
    $id = createUser($username, $password, $displayName, 'admin');
    logEvent('setup', 'נוצר המנהל הראשון', ['username' => $username], 'info', null, null, $id);
    return $id;
}

function login(string $username, string $password): array {
    $st = db()->prepare('SELECT * FROM users WHERE username = ? COLLATE NOCASE');
    $st->execute([trim($username)]);
    $user = $st->fetch();
    // אותה הודעה לשם לא קיים ולסיסמה שגויה — לא לגלות אילו שמות קיימים.
    if (!$user || !password_verify($password, $user['password_hash'])) {
        throw new AppError('שם משתמש או סיסמה שגויים', 401);
    }
    if ((int) $user['blocked'] === 1) throw new AppError('החשבון חסום', 403);

    sessionStart();
    if (PHP_SAPI !== 'cli') session_regenerate_id(true);   // מונע קיבוע סשן
    $_SESSION['uid'] = (int) $user['id'];
    db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([nowIso(), $user['id']]);
    logEvent('login', '', [], 'info', null, null, (int) $user['id']);
    unset($user['password_hash']);
    return $user;
}

function logout(): void {
    sessionStart();
    $_SESSION = [];
    if (PHP_SAPI !== 'cli' && ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'],
                  (bool) $p['secure'], (bool) $p['httponly']);
    }
    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) session_destroy();
}

function changePassword(int $userId, string $current, string $new): void {
    $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $st->execute([$userId]);
    $hash = $st->fetchColumn();
    if (!$hash || !password_verify($current, (string) $hash)) throw new AppError('הסיסמה הנוכחית שגויה');
    if (strlen($new) < 8) throw new AppError('הסיסמה החדשה חייבת להיות לפחות 8 תווים');
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
}

function listUsers(): array {
    return db()->query('SELECT id, username, display_name, role, blocked, created_at, last_login_at
                        FROM users ORDER BY id')->fetchAll();
}

function updateUser(int $adminId, int $id, array $in): void {
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) throw new AppError('אין משתמש כזה', 404);

    if (isset($in['display_name'])) {
        $n = trim((string) $in['display_name']);
        if ($n !== '') db()->prepare('UPDATE users SET display_name = ? WHERE id = ?')->execute([$n, $id]);
    }
    if (isset($in['role'])) {
        $r = (string) $in['role'];
        if (!in_array($r, ['admin', 'operator', 'viewer'], true)) throw new AppError('תפקיד לא מוכר');
        if ($id === $adminId && $r !== 'admin') throw new AppError('אי אפשר להוריד את עצמך מניהול');
        db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$r, $id]);
    }
    if (isset($in['blocked'])) {
        if ($id === $adminId) throw new AppError('אי אפשר לחסום את עצמך');
        db()->prepare('UPDATE users SET blocked = ? WHERE id = ?')->execute([$in['blocked'] ? 1 : 0, $id]);
    }
    if (!empty($in['password'])) {
        $p = (string) $in['password'];
        if (strlen($p) < 8) throw new AppError('הסיסמה חייבת להיות לפחות 8 תווים');
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($p, PASSWORD_DEFAULT), $id]);
    }
}

function deleteUser(int $adminId, int $id): void {
    if ($id === $adminId) throw new AppError('אי אפשר למחוק את עצמך');
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
}
