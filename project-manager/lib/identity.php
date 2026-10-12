<?php
/**
 * מי המשתמש — מתוך הפלטפורמה, בלי מערכת כניסה משלנו.
 *
 * העוגייה mp_session נבדקת מול platform/data/platform.sqlite, שנפתח לקריאה
 * בלבד: האפליקציה לעולם לא כותבת למסד של הפלטפורמה.
 *
 * השער (gate-prepend.php) כבר עצר כל מי שאינו רשאי לפני שהקוד כאן רץ. הבדיקה
 * חוזרת כאן בכל זאת — אם השער כבוי או לא נבנה, האפליקציה לא נפתחת לכולם.
 * היא נכשלת סגור: תקלה במסד הפלטפורמה = אין משתמש.
 *
 *   role=admin בפלטפורמה  → 'developer' (המפתח: רואה הכול, הגדרות מפתח)
 *   הרשאה ישירה לאפליקציה → 'manager'   (מנהל פרויקט: רואה רק את שלו)
 */

declare(strict_types=1);

const PM_APP_ID = 'project-manager';

if (!defined('PM_PLATFORM_DB')) define('PM_PLATFORM_DB', __DIR__ . '/../../platform/data/platform.sqlite');

/** חיבור לקריאה בלבד למסד הפלטפורמה. */
function pm_platform_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $pdo = new PDO('sqlite:' . PM_PLATFORM_DB, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::SQLITE_ATTR_OPEN_FLAGS  => PDO::SQLITE_OPEN_READONLY,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    return $pdo;
}

/**
 * למפתח: מי ממתין לאישור בפלטפורמה, ומי כבר מנהל פרויקט כאן.
 * האישור עצמו נעשה בממשק הניהול של הפלטפורמה — האפליקציה לא כותבת לשם.
 */
function pm_access_overview(): array
{
    $pdo = pm_platform_pdo();
    $pending = $pdo->query(
        "SELECT id, username, display_name, created_at FROM users WHERE status = 'pending' ORDER BY created_at DESC"
    )->fetchAll();
    $st = $pdo->prepare(
        "SELECT u.id, u.username, u.display_name, u.status, u.last_login_at FROM users u
           JOIN grants g ON g.user_id = u.id AND g.kind = 'app' AND g.target = ? AND g.allowed = 1
          WHERE u.role != 'admin' ORDER BY u.display_name"
    );
    $st->execute([PM_APP_ID]);
    return ['pending' => $pending, 'managers' => $st->fetchAll()];
}

/** [id, username, display_name, role(developer|manager), csrf] — או null. */
function pm_current_user(): ?array
{
    static $cached = false;
    if ($cached !== false) return $cached;
    $cached = null;

    $token = $_COOKIE['mp_session'] ?? '';
    if (!is_string($token) || strlen($token) !== 64 || !is_file(PM_PLATFORM_DB)) return null;

    try {
        $pdo = pm_platform_pdo();
        $st = $pdo->prepare(
            'SELECT u.id, u.username, u.display_name, u.role, u.status, s.csrf
               FROM sessions s JOIN users u ON u.id = s.user_id
              WHERE s.token_hash = ? AND s.expires_at > ?'
        );
        $st->execute([hash('sha256', $token), gmdate('Y-m-d\TH:i:s\Z')]);
        $u = $st->fetch();
        if (!$u || $u['status'] !== 'active') return null;

        if ($u['role'] === 'admin') {
            $role = 'developer';
        } else {
            // האפליקציה restricted: רק הרשאה ישירה פותחת אותה, לא קטגוריה.
            $g = $pdo->prepare("SELECT allowed FROM grants WHERE user_id = ? AND kind = 'app' AND target = ?");
            $g->execute([$u['id'], PM_APP_ID]);
            if ((int)$g->fetchColumn() !== 1) return null;
            $role = 'manager';
        }
    } catch (Throwable $e) {
        error_log('project-manager identity: ' . $e->getMessage());
        return null;
    }

    return $cached = [
        'id'           => (int)$u['id'],
        'username'     => $u['username'],
        'display_name' => $u['display_name'],
        'role'         => $role,
        'csrf'         => $u['csrf'],
    ];
}
