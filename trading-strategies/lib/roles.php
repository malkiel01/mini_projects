<?php
/**
 * מי מנהל (spec-stage-a 1.1).
 *
 * מנהל ראשי = platform_admin: true ב-PLATFORM_USER, מהשער. אין מינוי ידני שלו, ואין דרך להיות מנהל ראשי מתוך
 * האפליקציה. מנהלים נוספים — app_roles; רק מנהל ראשי ממנה ומבטל, וביטול חל מהבקשה הבאה (נבדק במסד בכל בקשה).
 * כל פעולת מנהל עוברת ts_require_admin() בשרת; הדפדפן רק מסתיר את התפריט. כל פעולת מנהל נרשמת ב-admin_log.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

final class TsForbidden extends RuntimeException {}

function ts_is_primary_admin(): bool
{
    $id = ts_identity();
    return $id !== null && $id['platform_admin'];
}

function ts_is_admin(int $uid): bool
{
    if (ts_is_primary_admin()) return true;
    $st = ts_db()->prepare("SELECT 1 FROM app_roles WHERE user_id = ? AND role = 'admin'");
    $st->execute([$uid]);
    return (bool)$st->fetchColumn();
}

function ts_require_admin(int $uid): void
{
    if (!ts_is_admin($uid)) throw new TsForbidden('פעולה של מנהל בלבד');
}

function ts_require_primary_admin(): void
{
    if (!ts_is_primary_admin()) throw new TsForbidden('פעולה של המנהל הראשי בלבד');
}

function ts_admin_log(int $uid, string $action, string $target = ''): void
{
    ts_db()->prepare('INSERT INTO admin_log (user_id, action, target, at) VALUES (?, ?, ?, ?)')
        ->execute([$uid, $action, mb_substr($target, 0, 300), time()]);
}

function ts_admins_list(): array
{
    return ts_db()->query("SELECT r.user_id, u.display_name, u.email, r.granted_at FROM app_roles r JOIN users u ON u.id = r.user_id
                           WHERE r.role = 'admin' ORDER BY r.granted_at")->fetchAll();
}

function ts_admin_grant(int $by, int $target): void
{
    ts_require_primary_admin();
    $st = ts_db()->prepare('SELECT 1 FROM users WHERE id = ?');
    $st->execute([$target]);
    if (!$st->fetchColumn()) throw new TsNotFound('המשתמש לא נמצא');
    ts_db()->prepare("INSERT OR IGNORE INTO app_roles (user_id, role, granted_by, granted_at) VALUES (?, 'admin', ?, ?)")
        ->execute([$target, $by, time()]);
    ts_admin_log($by, 'admin_grant', "user:$target");
}

function ts_admin_revoke(int $by, int $target): void
{
    ts_require_primary_admin();
    ts_db()->prepare("DELETE FROM app_roles WHERE user_id = ? AND role = 'admin'")->execute([$target]);
    ts_admin_log($by, 'admin_revoke', "user:$target");
}

/** תקרת בקשות נתונים לדקה למשתמש (3.5, ✅3). מעבר לה — 429, והדפדפן לא מנסה שוב לבד. */
const TS_RATE_PER_MIN = 30;

final class TsRateLimited extends RuntimeException {}

function ts_rate_hit(int $uid): void
{
    $db = ts_db();
    $minute = intdiv(time(), 60);
    $db->prepare('INSERT INTO rate_hits (user_id, minute, hits) VALUES (?, ?, 1)
                  ON CONFLICT(user_id, minute) DO UPDATE SET hits = hits + 1')->execute([$uid, $minute]);
    $st = $db->prepare('SELECT hits FROM rate_hits WHERE user_id = ? AND minute = ?');
    $st->execute([$uid, $minute]);
    if ((int)$st->fetchColumn() > TS_RATE_PER_MIN) {
        throw new TsRateLimited('יותר מ-' . TS_RATE_PER_MIN . ' בקשות נתונים בדקה. חכו דקה ונסו שוב.');
    }
    if (random_int(1, 100) === 1) $db->prepare('DELETE FROM rate_hits WHERE minute < ?')->execute([$minute - 5]);
}
