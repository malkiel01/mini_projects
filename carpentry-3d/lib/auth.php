<?php
/**
 * חשבונות וכניסה.
 *
 * אין הרשמה חופשית: המנהל יוצר נגרים (סעיף "משתמשים" באפיון). החריג היחיד
 * הוא המשתמש הראשון — כשהמסד ריק, `setup` יוצר אותו כמנהל, אחרת אין דרך
 * להיכנס למערכת ריקה בלי לזרוע סיסמה בקוד. אחרי שיש משתמש אחד, setup נסגר.
 *
 * שלושה תפקידים: admin (הכול), carpenter (הפרויקטים שלו ותעריפיו), viewer
 * (צפייה בלבד — לקוח עם קישור אינו משתמש כלל; הוא מגיע דרך share_token).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function sessionStart(): void {
    if (PHP_SAPI === 'cli') {
        if (!isset($_SESSION)) $_SESSION = [];
        return;
    }
    if (session_status() !== PHP_SESSION_NONE) return;
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,   // חודש: נגר בסדנה לא רוצה להתחבר כל בוקר
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('CARPENTRY');
    session_start();
}

function userCount(): int {
    return (int) db()->query('SELECT COUNT(*) c FROM users')->fetch()['c'];
}

function publicUser(array $u): array {
    return ['id' => (int) $u['id'], 'email' => $u['email'], 'name' => $u['name'],
            'role' => $u['role'], 'blocked' => (int) $u['blocked'] === 1, 'created_at' => $u['created_at']];
}

function currentUser(): ?array {
    sessionStart();
    if (empty($_SESSION['uid'])) return null;
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    $u = $st->fetch();
    // חסימה מנתקת סשן קיים, לא רק מונעת כניסה חדשה.
    if (!$u || (int) $u['blocked'] === 1) { logout(); return null; }
    return $u;
}

function requireUser(): array {
    $u = currentUser();
    if (!$u) throw new AppError('נדרשת התחברות', 401);
    return $u;
}

function requireAdmin(): array {
    $u = requireUser();
    if ($u['role'] !== 'admin') throw new AppError('הפעולה הזו שמורה למנהל', 403);
    return $u;
}

/** נגר או מנהל — מי שיוצר פרויקטים. צופה לא. */
function requireEditor(): array {
    $u = requireUser();
    if ($u['role'] === 'viewer') throw new AppError('חשבון צפייה אינו יכול לערוך', 403);
    return $u;
}

function validateEmail(string $email): string {
    $email = mb_strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new AppError('כתובת הדוא"ל אינה תקינה');
    return $email;
}

function validatePassword(string $password): void {
    if (strlen($password) < 8) throw new AppError('הסיסמה חייבת להיות באורך 8 תווים לפחות');
}

/** יצירת משתמש. `$role` נבדק מול הרשימה; השם — אם ריק, החלק שלפני ה-@. */
function createUser(string $email, string $password, string $name, string $role): array {
    $email = validateEmail($email);
    validatePassword($password);
    if (!in_array($role, ['admin', 'carpenter', 'viewer'], true)) throw new AppError('תפקיד לא מוכר');
    $name = trim($name) !== '' ? mb_substr(trim($name), 0, 60) : explode('@', $email)[0];
    $st = db()->prepare('INSERT INTO users (email, name, role, password_hash, created_at) VALUES (?,?,?,?,?)');
    try {
        $st->execute([$email, $name, $role, password_hash($password, PASSWORD_DEFAULT), nowIso()]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) throw new AppError('כתובת הדוא"ל כבר בשימוש');
        throw $e;
    }
    return publicUser(getUser((int) db()->lastInsertId()));
}

/** המנהל הראשון. נסגר ברגע שיש משתמש כלשהו. */
function setupAdmin(string $email, string $password, string $name): array {
    if (userCount() > 0) throw new AppError('המערכת כבר הוקמה — יש להתחבר', 403);
    $u = createUser($email, $password, $name, 'admin');
    sessionStart();
    $_SESSION['uid'] = $u['id'];
    return $u;
}

function getUser(int $id): array {
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) throw new AppError('משתמש לא נמצא', 404);
    return $u;
}

function login(string $email, string $password): array {
    $st = db()->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([mb_strtolower(trim($email))]);
    $u = $st->fetch();
    // אותה הודעה לכתובת לא קיימת ולסיסמה שגויה — כדי שהטופס לא יגלה מי רשום.
    if (!$u || !password_verify($password, $u['password_hash'])) throw new AppError('דוא"ל או סיסמה שגויים', 401);
    if ((int) $u['blocked'] === 1) throw new AppError('החשבון חסום — פנו למנהל', 403);
    sessionStart();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $u['id'];
    return publicUser($u);
}

function logout(): void {
    sessionStart();
    $_SESSION = [];
    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function changePassword(array $user, string $current, string $new): void {
    if (!password_verify($current, $user['password_hash'])) throw new AppError('הסיסמה הנוכחית שגויה', 403);
    validatePassword($new);
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
}

function listUsers(): array {
    return array_map('publicUser', db()->query('SELECT * FROM users ORDER BY created_at')->fetchAll());
}

/**
 * עדכון בידי המנהל: שם, תפקיד, חסימה, סיסמה חדשה. המנהל אינו יכול לחסום
 * את עצמו או להוריד את עצמו מתפקיד — אחרת נשארת מערכת בלי מנהל.
 */
function adminUpdateUser(array $admin, int $id, array $patch): array {
    $u = getUser($id);
    $self = (int) $admin['id'] === $id;
    $sets = []; $vals = [];
    if (isset($patch['name']) && is_string($patch['name']) && trim($patch['name']) !== '') {
        $sets[] = 'name = ?'; $vals[] = mb_substr(trim($patch['name']), 0, 60);
    }
    if (isset($patch['role'])) {
        if (!in_array($patch['role'], ['admin', 'carpenter', 'viewer'], true)) throw new AppError('תפקיד לא מוכר');
        if ($self && $patch['role'] !== 'admin') throw new AppError('מנהל אינו יכול להוריד את עצמו מתפקיד');
        $sets[] = 'role = ?'; $vals[] = $patch['role'];
    }
    if (array_key_exists('blocked', $patch)) {
        if ($self && $patch['blocked']) throw new AppError('מנהל אינו יכול לחסום את עצמו');
        $sets[] = 'blocked = ?'; $vals[] = $patch['blocked'] ? 1 : 0;
    }
    if (isset($patch['password']) && is_string($patch['password']) && $patch['password'] !== '') {
        validatePassword($patch['password']);
        $sets[] = 'password_hash = ?'; $vals[] = password_hash($patch['password'], PASSWORD_DEFAULT);
    }
    if ($sets) {
        $vals[] = $id;
        db()->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    }
    return publicUser(getUser($id));
}
