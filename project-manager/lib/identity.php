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

/** [id, username, display_name, role(developer|manager), csrf] — או null. */
function pm_current_user(): ?array
{
    static $cached = false;
    if ($cached !== false) return $cached;
    $cached = null;

    $token = $_COOKIE['mp_session'] ?? '';
    if (!is_string($token) || strlen($token) !== 64 || !is_file(PM_PLATFORM_DB)) return null;

    try {
        $pdo = new PDO('sqlite:' . PM_PLATFORM_DB, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::SQLITE_ATTR_OPEN_FLAGS  => PDO::SQLITE_OPEN_READONLY,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
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
