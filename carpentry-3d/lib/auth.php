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
            'role' => $u['role'], 'blocked' => (int) $u['blocked'] === 1, 'created_at' => $u['created_at'],
            'valid_from' => $u['valid_from'] ?? null, 'valid_until' => $u['valid_until'] ?? null,
            'last_login_at' => $u['last_login_at'] ?? null, 'in_period' => userInPeriod($u),
            'projects' => isset($u['projects']) ? (int) $u['projects'] : null];
}

/** האם החשבון בתוקף היום: בין valid_from ל-valid_until (כולל), כשהם מוגדרים. */
function userInPeriod(array $u, ?string $today = null): bool {
    $today = $today ?? gmdate('Y-m-d');
    $from = $u['valid_from'] ?? null; $until = $u['valid_until'] ?? null;
    if ($from && $today < $from) return false;
    if ($until && $today > $until) return false;
    return true;
}

function validDate(?string $d): ?string {
    if ($d === null || trim($d) === '') return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4))) throw new AppError('תאריך לא תקין (YYYY-MM-DD)');
    return $d;
}

function currentUser(): ?array {
    sessionStart();
    if (empty($_SESSION['uid'])) return null;
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    $u = $st->fetch();
    // חסימה או פקיעת תוקף מנתקות סשן קיים, לא רק מונעות כניסה חדשה.
    if (!$u || (int) $u['blocked'] === 1 || !userInPeriod($u)) { logout(); return null; }
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
function createUser(string $email, string $password, string $name, string $role, ?string $validFrom = null, ?string $validUntil = null): array {
    $email = validateEmail($email);
    validatePassword($password);
    if (!in_array($role, ['admin', 'carpenter', 'viewer'], true)) throw new AppError('תפקיד לא מוכר');
    $name = trim($name) !== '' ? mb_substr(trim($name), 0, 60) : explode('@', $email)[0];
    $validFrom = validDate($validFrom); $validUntil = validDate($validUntil);
    if ($validFrom && $validUntil && $validFrom > $validUntil) throw new AppError('תאריך ההתחלה אחרי תאריך הסיום');
    $st = db()->prepare('INSERT INTO users (email, name, role, password_hash, created_at, valid_from, valid_until) VALUES (?,?,?,?,?,?,?)');
    try {
        $st->execute([$email, $name, $role, password_hash($password, PASSWORD_DEFAULT), nowIso(), $validFrom, $validUntil]);
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
    if (!userInPeriod($u)) throw new AppError('החשבון אינו בתוקף — פנו למנהל', 403);
    sessionStart();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $u['id'];
    db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([nowIso(), $u['id']]);
    $u['last_login_at'] = nowIso();
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
    return array_map('publicUser', db()->query('SELECT u.*, (SELECT COUNT(*) FROM projects p WHERE p.owner_id = u.id) AS projects FROM users u ORDER BY u.created_at')->fetchAll());
}

/**
 * מחיקת משתמש בידי המנהל. הפרויקטים וההרכבות שלו עוברים למנהל שמוחק
 * (לא נמחקים — FK היה מוחק אותם). מנהל אינו מוחק את עצמו.
 */
function deleteUser(array $admin, int $id): void {
    if ((int) $admin['id'] === $id) throw new AppError('מנהל אינו יכול למחוק את עצמו');
    getUser($id);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE projects SET owner_id = ? WHERE owner_id = ?')->execute([$admin['id'], $id]);
        $pdo->prepare('UPDATE assemblies SET owner_id = ? WHERE owner_id = ?')->execute([$admin['id'], $id]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

/** מצב המערכת למסך הניהול: מונים וגודל המסד. */
function adminStats(): array {
    $pdo = db();
    $c = fn (string $sql) => (int) $pdo->query($sql)->fetchColumn();
    return [
        'users' => ['admin' => $c("SELECT COUNT(*) FROM users WHERE role='admin'"), 'carpenter' => $c("SELECT COUNT(*) FROM users WHERE role='carpenter'"), 'viewer' => $c("SELECT COUNT(*) FROM users WHERE role='viewer'"), 'blocked' => $c('SELECT COUNT(*) FROM users WHERE blocked=1')],
        'projects' => $c('SELECT COUNT(*) FROM projects'), 'shared' => $c('SELECT COUNT(*) FROM projects WHERE share_token IS NOT NULL'),
        'clients' => $c('SELECT COUNT(*) FROM clients'), 'assemblies' => $c('SELECT COUNT(*) FROM assemblies'),
        'types' => $c('SELECT COUNT(*) FROM product_types'), 'materials' => $c('SELECT COUNT(*) FROM materials'),
        'db_bytes' => is_file(DB_FILE) ? (int) filesize(DB_FILE) : 0,
        'media_files' => is_dir(MEDIA_DIR) ? count(glob(MEDIA_DIR . '/*.jpg') ?: []) : 0,
        'last_project_at' => $pdo->query('SELECT MAX(updated_at) FROM projects')->fetchColumn() ?: null,
        'php' => PHP_VERSION,
    ];
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
    // תוקף: תאריך או ריק. מנהל לא קובע לעצמו תקופה שמוציאה אותו עכשיו — אחרת ננעל בחוץ.
    $period = $u;
    foreach (['valid_from', 'valid_until'] as $k) {
        if (!array_key_exists($k, $patch)) continue;
        $d = validDate(is_string($patch[$k]) ? $patch[$k] : null);
        $sets[] = "$k = ?"; $vals[] = $d; $period[$k] = $d;
    }
    if ($self && !userInPeriod($period)) throw new AppError('מנהל אינו יכול לקבוע לעצמו תקופה שאינה בתוקף עכשיו');
    if (isset($period['valid_from'], $period['valid_until']) && $period['valid_from'] && $period['valid_until'] && $period['valid_from'] > $period['valid_until']) throw new AppError('תאריך ההתחלה אחרי תאריך הסיום');
    if ($sets) {
        $vals[] = $id;
        db()->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    }
    return publicUser(getUser($id));
}
