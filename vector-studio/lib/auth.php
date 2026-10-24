<?php
/**
 * משתמשים וכניסה.
 *
 * לאפליקציה משתמשים משלה (CLAUDE.md, "אפליקציה פנימית עצמאית"), אבל בגרסה 1 היא **מחוברת** לפלטפורמה:
 * הכניסה רק דרך השער, אוטומטית (platform/docs/auth.md). מי שהשער העביר — החשבון המקושר ל-sub שלו; אין
 * כזה — חשבון באותו מייל, ומקשרים; אין — חשבון חדש, פעיל. הראשון במסד ריק — מנהל.
 *
 * אין סיסמה ואין מסך חיבור: האפליקציה חדשה, ואין חשבונות ישנים לחבר. כשתהיה מנותקת (דומיין משלה) —
 * נוסיף כניסה עם הכלים הדיגיטליים (sso.md, סעיף 2) וסיסמה משלה, לפי ההגדרה בניהול.
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
        'lifetime' => 60 * 60 * 24 * 30,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('VECTORSTUDIO');
    session_start();
}

/** הזהות שהשער כתב: {sub, name, email, email_verified}. מפתח שאינו HTTP_* — לא ניתן לזייף בבקשה. */
function platformIdentity(): ?array {
    $id = json_decode((string) ($_SERVER['PLATFORM_USER'] ?? ''), true);
    if (!is_array($id) || !is_string($id['sub'] ?? null) || $id['sub'] === '') return null;
    return $id;
}

function getUser(int $id): array {
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) throw new AppError('משתמש לא נמצא', 404);
    return $u;
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
        $st = $pdo->prepare('SELECT * FROM users WHERE email = ?');
        $st->execute([$email]);
        if ($u = $st->fetch()) {
            if ($u['platform_sub'] !== null) throw new AppError('כתובת הדוא"ל הזו כבר מקושרת לחשבון אחר — פנו למנהל', 403);
            $pdo->prepare('UPDATE users SET platform_sub = ? WHERE id = ?')->execute([$sub, $u['id']]);
            return getUser((int) $u['id']);
        }
    }

    // בלי מייל (משתמש ותיק בפלטפורמה) — כתובת פנימית, רק כדי שהעמודה הייחודית תתמלא.
    $name = trim((string) ($id['name'] ?? ''));
    $name = mb_substr($name !== '' ? $name : ($email !== '' ? explode('@', $email)[0] : 'משתמש'), 0, 60);
    $empty = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
    $pdo->prepare('INSERT INTO users (platform_sub, email, name, role, created_at) VALUES (?,?,?,?,?)')
        ->execute([$sub, $email !== '' ? $email : 'platform-' . substr(hash('sha256', $sub), 0, 16) . '@platform.invalid',
                   $name, $empty ? 'admin' : 'editor', nowIso()]);
    return getUser((int) $pdo->lastInsertId());
}

/**
 * המשתמש המחובר. הזהות שהשער כתב קובעת, והסשן רק עוקב אחריה: מישהו אחר נכנס לפלטפורמה באותו
 * דפדפן — מחליפים. אין זהות — אין משתמש, והסשן נמחק (יציאה מהפלטפורמה = יציאה מכאן).
 */
function currentUser(): ?array {
    sessionStart();
    $pid = platformIdentity();
    if (!$pid) {
        $_SESSION = [];
        return null;
    }
    $u = platformAccount($pid);
    if ((int) $u['blocked'] === 1) throw new AppError('החשבון חסום — פנו למנהל', 403);
    if ((int) ($_SESSION['uid'] ?? 0) !== (int) $u['id']) {
        if (PHP_SAPI !== 'cli') session_regenerate_id(true);
        $_SESSION['uid'] = (int) $u['id'];
        db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([nowIso(), $u['id']]);
    }
    return $u;
}

function requireUser(): array {
    $u = currentUser();
    if (!$u) throw new AppError('נדרשת כניסה דרך הכלים הדיגיטליים', 401);
    return $u;
}

function publicUser(array $u): array {
    return ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role']];
}
