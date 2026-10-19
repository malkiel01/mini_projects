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
 *
 * כשהאפליקציה מחוברת לפלטפורמה — הכניסה רק דרכה, אוטומטית; ראו "הפלטפורמה" בסוף הקובץ.
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
            'linked' => !empty($u['platform_sub']),
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

/**
 * המשתמש המחובר. כשהאפליקציה מחוברת לפלטפורמה — הזהות שהשער כתב קובעת, והסשן רק עוקב אחריה;
 * חשבון חסום או מחוץ לתוקף נדחה עם הסיבה (403). מנותקת — הסשן של הכניסה בסיסמה, כמו תמיד.
 */
function currentUser(): ?array {
    sessionStart();
    $pid = platformIdentity();
    if ($pid) {
        $u = platformAccount($pid);
        // החסימות והתוקף של האפליקציה חלים בנוסף להרשאה בפלטפורמה.
        if ((int) $u['blocked'] === 1) throw new AppError('החשבון חסום — פנו למנהל', 403);
        if (!userInPeriod($u)) throw new AppError('החשבון אינו בתוקף — פנו למנהל', 403);
        if ((int) ($_SESSION['uid'] ?? 0) !== (int) $u['id']) {
            // כניסה ראשונה בסשן הזה, או שמישהו אחר נכנס לפלטפורמה באותו דפדפן — מחליפים.
            if (PHP_SAPI !== 'cli') session_regenerate_id(true);
            $_SESSION['uid'] = (int) $u['id'];
            db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([nowIso(), $u['id']]);
        }
        return $u;
    }
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

// ---- הפלטפורמה ----
//
// מחוברת לשער: מי שנכנס לפלטפורמה ויש לו הרשאה לאפליקציה — נכנס אוטומטית (platform/docs/auth.md,
// sso.md סעיף 0). החשבון: המקושר ל-sub; אין — חשבון באותו מייל, ומקשרים; אין — חשבון חדש, פעיל, כמו
// שהמנהל היה פותח (נגר, בלי תוקף), ובמסד ריק — המנהל. הכניסה בסיסמה חסומה כל עוד יש זהות, אבל הסיסמה
// לא נמחקת: כשהאפליקציה מנותקת היא עובדת שוב.

/** הזהות שהשער כתב: {sub, name, email, email_verified}. מפתח שאינו HTTP_* — לא ניתן לזייף בבקשה. */
function platformIdentity(): ?array {
    $id = json_decode((string) ($_SERVER['PLATFORM_USER'] ?? ''), true);
    if (!is_array($id) || !is_string($id['sub'] ?? null) || $id['sub'] === '') return null;
    return $id;
}

/** החשבון באפליקציה של משתמש הפלטפורמה — מקושר, לפי מייל, או חדש. */
function platformAccount(array $id): array {
    $pdo = db();
    $sub = $id['sub'];
    $st = $pdo->prepare('SELECT * FROM users WHERE platform_sub = ?');
    $st->execute([$sub]);
    if ($u = $st->fetch()) return $u;

    $email = is_string($id['email'] ?? null) ? mb_strtolower(trim($id['email'])) : '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
    if ($email !== '') {
        $st = $pdo->prepare('SELECT * FROM users WHERE email = ? COLLATE NOCASE');
        $st->execute([$email]);
        if ($u = $st->fetch()) {
            if ($u['platform_sub'] !== null) throw new AppError('כתובת הדוא"ל הזו כבר מקושרת לחשבון אחר — פנו למנהל', 403);
            $pdo->prepare('UPDATE users SET platform_sub = ?, linked_at = ? WHERE id = ?')->execute([$sub, nowIso(), $u['id']]);
            error_log("carpentry-3d: חשבון {$u['id']} ({$email}) קושר לפלטפורמה לפי מייל");
            return getUser((int) $u['id']);
        }
    }

    // חשבון חדש. בלי מייל (משתמש ותיק בפלטפורמה) — כתובת פנימית, רק כדי שהעמודה הייחודית תתמלא.
    $name = trim((string) ($id['name'] ?? ''));
    $name = mb_substr($name !== '' ? $name : ($email !== '' ? explode('@', $email)[0] : 'משתמש'), 0, 60);
    $role = userCount() === 0 ? 'admin' : 'carpenter';
    $pdo->prepare('INSERT INTO users (email, name, role, password_hash, created_at, platform_sub, linked_at, auto_created) VALUES (?,?,?,?,?,?,?,1)')
        ->execute([$email !== '' ? $email : 'platform-' . substr(hash('sha256', $sub), 0, 16) . '@platform.invalid',
                   $name, $role, '', nowIso(), $sub, nowIso()]);
    return getUser((int) $pdo->lastInsertId());
}

/** יש בכלל חשבון ישן (בסיסמה, לא מקושר) שאפשר לחבר — אחרת אין טעם במסך החיבור. */
function hasLinkableAccounts(): bool {
    return (bool) db()->query("SELECT 1 FROM users WHERE platform_sub IS NULL AND password_hash <> '' LIMIT 1")->fetchColumn();
}

/** מה `me` מחזיר על מסך החיבור: מותר לחבר (מהתפריט), והאם להציע אותו עכשיו. */
function linkState(array $u): array {
    $can = (int) $u['auto_created'] === 1 && hasLinkableAccounts();
    return ['can_link' => $can, 'offer_link' => $can && (int) $u['link_dismissed'] === 0];
}

/**
 * מסך החיבור החד-פעמי: "יש לי כבר חשבון" — דוא"ל וסיסמה של החשבון הישן. מכאן הוא החשבון של משתמש
 * הפלטפורמה; מה שנוצר בחשבון שנפתח לבד עובר אליו, וזה נמחק. 5 ניסיונות שגויים → נעילה לרבע שעה.
 * הסיסמה הישנה נשארת (auth.md, סעיף 4).
 */
function linkOldAccount(array $auto, string $email, string $password): array {
    if ((int) $auto['auto_created'] !== 1) throw new AppError('החשבון כבר מחובר', 409);
    $locked = (int) $auto['link_locked_until'] - time();
    if ($locked > 0) throw new AppError('יותר מדי ניסיונות. נסו שוב בעוד ' . (int) ceil($locked / 60) . ' דקות', 429);

    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM users WHERE email = ? COLLATE NOCASE');
    $st->execute([mb_strtolower(trim($email))]);
    $old = $st->fetch();
    $ok = $old && (int) $old['id'] !== (int) $auto['id'] && $old['platform_sub'] === null
        && $old['password_hash'] !== '' && password_verify($password, $old['password_hash']);
    if (!$ok) {
        $fails = (int) $auto['link_fails'] + 1;
        $pdo->prepare('UPDATE users SET link_fails = ?, link_locked_until = ? WHERE id = ?')
            ->execute([$fails >= 5 ? 0 : $fails, $fails >= 5 ? time() + 15 * 60 : 0, $auto['id']]);
        throw new AppError('דוא"ל או סיסמה שגויים', 401);
    }
    // מנהל יחיד שמתחבר לחשבון שאינו מנהל — המערכת הייתה נשארת בלי מנהל.
    if ($auto['role'] === 'admin' && $old['role'] !== 'admin'
        && (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() === 1) {
        throw new AppError('החשבון הזה הוא המנהל היחיד — אי אפשר להחליף אותו בחשבון שאינו מנהל', 409);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE users SET platform_sub = NULL WHERE id = ?')->execute([$auto['id']]);
        $pdo->prepare('UPDATE users SET platform_sub = ?, linked_at = ? WHERE id = ?')->execute([$auto['platform_sub'], nowIso(), $old['id']]);
        foreach (['projects' => 'owner_id', 'assemblies' => 'owner_id', 'clients' => 'created_by', 'product_types' => 'created_by'] as $t => $c) {
            $pdo->prepare("UPDATE $t SET $c = ? WHERE $c = ?")->execute([$old['id'], $auto['id']]);
        }
        $pdo->prepare('INSERT OR IGNORE INTO rates (user_id, key, value) SELECT ?, key, value FROM rates WHERE user_id = ?')->execute([$old['id'], $auto['id']]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$auto['id']]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    error_log("carpentry-3d: חשבון {$old['id']} חובר לפלטפורמה במסך החיבור (במקום {$auto['id']})");

    sessionStart();
    $_SESSION['uid'] = (int) $old['id'];
    return getUser((int) $old['id']);
}

/** "אין לי חשבון" — ההצעה לא חוזרת; נשארת בתפריט המשתמש. */
function dismissLink(array $u): void {
    db()->prepare('UPDATE users SET link_dismissed = 1 WHERE id = ?')->execute([$u['id']]);
}
