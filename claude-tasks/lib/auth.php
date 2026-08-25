<?php
/**
 * חשבונות והרשאות.
 *
 * שני סוגי גישה, בכוונה נפרדים:
 *   בני אדם  — סשן עם עוגייה, נוצר בהתחברות עם שם משתמש וסיסמה.
 *   עובדים   — אסימון בכותרת. סשן Claude Code או cron אינם דפדפן ואין
 *              להם מקום לשמור עוגייה, והפרדה גם מונעת מדליפת אסימון
 *              עובד להפוך לגישה מלאה לחשבון של אדם.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

function sessionStart(): void {
    if (session_status() !== PHP_SESSION_NONE) return;
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('CLAUDETASKS');
    session_start();
}

function currentUser(): ?array {
    sessionStart();
    if (empty($_SESSION['uid'])) return null;
    // כולל את שדות הפרופיל שכל בקשה נזקקת להם. הסודות עצמם אינם כאן —
    // הם נשלפים ומפוענחים רק בנקודה שבה משתמשים בהם.
    $st = db()->prepare('SELECT id, username, display_name, role, github_login, github_scopes,
                                default_provider
                           FROM users WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    return $st->fetch() ?: null;
}

function userCount(): int {
    return (int) db()->query('SELECT COUNT(*) c FROM users')->fetch()['c'];
}

/**
 * יוצר משתמש. המשתמש הראשון נעשה admin — אחרת אין דרך להיכנס למערכת
 * ריקה בלי לזרוע סיסמה קבועה בקוד.
 */
function createUser(string $username, string $password, string $displayName): array {
    $username = trim($username);
    if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $username)) {
        throw new InvalidArgumentException('שם משתמש: 3-32 תווים, אותיות לועזיות, ספרות, נקודה, מקף או קו תחתון');
    }
    if (strlen($password) < 8) {
        throw new InvalidArgumentException('הסיסמה חייבת להיות באורך 8 תווים לפחות');
    }

    $role = userCount() === 0 ? 'admin' : 'member';
    $st = db()->prepare(
        'INSERT INTO users (username, password_hash, display_name, role, created_at) VALUES (?,?,?,?,?)'
    );
    try {
        $st->execute([
            $username,
            password_hash($password, PASSWORD_DEFAULT),
            trim($displayName) !== '' ? trim($displayName) : $username,
            $role,
            nowTs(),
        ]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) {
            throw new InvalidArgumentException('שם המשתמש כבר תפוס');
        }
        throw $e;
    }

    return ['id' => (int) db()->lastInsertId(), 'username' => $username, 'role' => $role];
}

function login(string $username, string $password): ?array {
    $st = db()->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([trim($username)]);
    $user = $st->fetch();

    // password_verify מורץ גם כשאין משתמש, כדי שזמן התגובה לא יגלה
    // אילו שמות משתמש קיימים.
    $hash = $user['password_hash'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidin';
    if (!password_verify($password, $hash) || !$user) return null;

    sessionStart();
    session_regenerate_id(true);   // מונע קיבוע סשן
    $_SESSION['uid'] = $user['id'];

    return ['id' => (int) $user['id'], 'username' => $user['username'],
            'display_name' => $user['display_name'], 'role' => $user['role']];
}

function logout(): void {
    sessionStart();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** מזהה עובד מתוך הכותרת. מחזיר את שמו, או null אם אינו עובד מורשה. */
function workerName(): ?string {
    $token = $_SERVER['HTTP_X_WORKER_TOKEN'] ?? '';
    if ($token === '' || !hash_equals(config()['worker_token'], $token)) return null;

    $name = trim($_SERVER['HTTP_X_WORKER_NAME'] ?? 'worker');
    return preg_match('/^[A-Za-z0-9 ._-]{1,40}$/', $name) ? $name : 'worker';
}
