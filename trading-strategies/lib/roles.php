<?php
/**
 * עורך ראשי, עורך משני, משתמש (spec-stage-a פרק 24, ✅38 — מחליף את 1.1).
 *
 * עורך ראשי — (א) מי שמגיע מהשער עם platform_admin: תמיד, ואי אפשר לבטל אותו מתוך האפליקציה (הוא לא שורה ב-app_roles).
 *             (ב) app_roles.role = 'chief' — עורך ראשי ממנה ומבטל (ר1).
 * עורך משני — app_roles.role = 'editor' (ה-admin של עד מיגרציה 18). עורך ראשי ממנה ומבטל.
 * הבדיקה בשרת בכל פעולה (ts_require_chief / ts_require_editor → TsForbidden → 403); הדפדפן רק מסתיר. ביטול חל מהבקשה
 * הבאה — התפקיד נקרא מהמסד בכל בקשה. כל מינוי וביטול נרשם ב-admin_log (role_grant / role_revoke, עם התפקיד).
 * הכלל (24.2): מה שמשנה ספירה, הסתברות או גישה של אחרים — עורך ראשי; כל השאר — כל העורכים.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

final class TsForbidden extends RuntimeException {}

const TS_ROLES = ['chief', 'editor'];
const TS_ROLE_NAMES = ['chief' => 'עורך ראשי', 'editor' => 'עורך משני'];

/** המשתמש של הבקשה, אם יש */
function ts_current_uid(): ?int
{
    $cur = function_exists('ts_current') ? ts_current() : null;
    return $cur !== null ? (int)$cur['user']['id'] : null;
}

/**
 * העורך הראשי מהשער — של הבקשה הנוכחית. מחוברת: platform_admin בזהות. מנותקת (20.1 ו'): הזהות החתומה לא נושאת
 * platform_admin — לכן users.platform_admin השמור (מתעדכן רק מהשער — ts_user_for), ובתנאי ש-grant_status מאשר: session
 * מנותק קיים רק כל עוד ההרשאה אושרה ב-5 הדקות האחרונות (ts_session_grant_check). אין דרך לקבל אותו מתוך האפליקציה.
 */
function ts_is_gate_chief(): bool
{
    $id = ts_identity();
    if ($id !== null) return $id['platform_admin'];
    $cur = function_exists('ts_current') ? ts_current() : null;
    return $cur !== null && $cur['via'] !== 'gate' && (int)($cur['user']['platform_admin'] ?? 0) === 1;
}

/** התפקיד הממונה (app_roles): chief / editor / null */
function ts_role_of(int $uid): ?string
{
    $st = ts_db()->prepare('SELECT role FROM app_roles WHERE user_id = ?');
    $st->execute([$uid]);
    $r = $st->fetchColumn();
    return $r === false ? null : (string)$r;
}

/** עורך ראשי — מהשער, או ממונה. בלי $uid — המשתמש של הבקשה. עם $uid של מישהו אחר — רק הממונה (השער — של הבקשה בלבד). */
function ts_is_chief(?int $uid = null): bool
{
    $me = ts_current_uid();
    $uid ??= $me;
    if ($uid !== null && $uid === $me && ts_is_gate_chief()) return true;
    if ($uid === null) return ts_is_gate_chief();
    return ts_role_of($uid) === 'chief';
}

/** עורך — ראשי או משני */
function ts_is_editor(int $uid): bool
{
    return ts_is_chief($uid) || ts_role_of($uid) === 'editor';
}

/** 'chief' / 'editor' / 'user' — למשתמש של הבקשה (me) */
function ts_role(int $uid): string
{
    return ts_is_chief($uid) ? 'chief' : (ts_is_editor($uid) ? 'editor' : 'user');
}

function ts_require_editor(int $uid): void
{
    if (!ts_is_editor($uid)) throw new TsForbidden('פעולה של עורך בלבד');
}

function ts_require_chief(?int $uid = null): void
{
    if (!ts_is_chief($uid)) throw new TsForbidden('פעולה של עורך ראשי בלבד');
}

// השמות הישנים (1.1) — מפנים לחדשים, בלי לוגיקה משלהם. נשארים בשביל קוד של ענפים מקבילים; קוד חדש — בשמות החדשים
function ts_is_primary_admin(): bool { return ts_is_chief(); }
function ts_is_admin(int $uid): bool { return ts_is_editor($uid); }
function ts_require_admin(int $uid): void { ts_require_editor($uid); }
function ts_require_primary_admin(): void { ts_require_chief(); }

function ts_admin_log(int $uid, string $action, string $target = ''): void
{
    ts_db()->prepare('INSERT INTO admin_log (user_id, action, target, at) VALUES (?, ?, ?, ?)')
        ->execute([$uid, $action, mb_substr($target, 0, 300), time()]);
}

/**
 * מסך "עורכים" (24.5): העורכים מהשער (users.platform_admin — מה שהשער אמר בכניסה האחרונה) ואחריהם הממונים.
 * gate: true — מהשער (בלי "בטל"). מי מינה ומתי — לממונים.
 */
function ts_editors_list(): array
{
    $rows = ts_db()->query("SELECT u.id AS user_id, u.display_name, u.email, u.platform_admin, r.role, r.granted_by, r.granted_at,
                                   g.display_name AS granted_by_name
                            FROM users u LEFT JOIN app_roles r ON r.user_id = u.id LEFT JOIN users g ON g.id = r.granted_by
                            WHERE u.platform_admin = 1 OR r.user_id IS NOT NULL
                            ORDER BY u.platform_admin DESC, CASE r.role WHEN 'chief' THEN 0 ELSE 1 END, r.granted_at, u.id")->fetchAll();
    return array_map(fn($r) => [
        'user_id' => (int)$r['user_id'], 'display_name' => $r['display_name'], 'email' => $r['email'],
        'role' => (int)$r['platform_admin'] === 1 ? 'chief' : $r['role'], 'gate' => (int)$r['platform_admin'] === 1,
        'granted_by' => $r['granted_by'] === null ? null : (int)$r['granted_by'], 'granted_by_name' => $r['granted_by_name'],
        'granted_at' => $r['granted_at'] === null ? null : (int)$r['granted_at'],
    ], $rows);
}

/** העורך מהשער — לפי מה שהשער אמר בכניסה האחרונה */
function ts_is_gate_user(int $uid): bool
{
    $st = ts_db()->prepare('SELECT platform_admin FROM users WHERE id = ?');
    $st->execute([$uid]);
    return (int)$st->fetchColumn() === 1;
}

/**
 * מינוי, או שינוי תפקיד של עורך קיים (24.3): רק עורך ראשי. תפקיד אחד למשתמש — מינוי של עורך משני לראשי מעדכן את השורה.
 * לא את עצמך, ולא את העורך מהשער (הוא תמיד ראשי) — 422.
 */
function ts_role_grant(int $by, int $target, mixed $role): void
{
    ts_require_chief($by);
    if (!is_string($role) || !in_array($role, TS_ROLES, true)) throw new TsInvalid('תפקיד לא מוכר');
    $st = ts_db()->prepare('SELECT 1 FROM users WHERE id = ?');
    $st->execute([$target]);
    if (!$st->fetchColumn()) throw new TsNotFound('המשתמש לא נמצא');
    if ($target === $by) throw new TsInvalid('אי אפשר לשנות את התפקיד של עצמך — עורך ראשי אחר משנה');
    if (ts_is_gate_user($target)) throw new TsInvalid('העורך מהכלים הדיגיטליים הוא תמיד עורך ראשי');
    if (ts_role_of($target) === $role) return;
    ts_db()->prepare('INSERT INTO app_roles (user_id, role, granted_by, granted_at) VALUES (?, ?, ?, ?)
                      ON CONFLICT(user_id) DO UPDATE SET role = excluded.role, granted_by = excluded.granted_by, granted_at = excluded.granted_at')
        ->execute([$target, $role, $by, time()]);
    ts_admin_log($by, 'role_grant', "user:$target role:$role");
}

/** ביטול (24.3): רק עורך ראשי. לא את עצמך, ולא את העורך מהשער — 422. מי שאינו עורך — 404. */
function ts_role_revoke(int $by, int $target): void
{
    ts_require_chief($by);
    if ($target === $by) throw new TsInvalid('אי אפשר לבטל את עצמך — עורך ראשי אחר מבטל');
    if (ts_is_gate_user($target)) throw new TsInvalid('העורך מהכלים הדיגיטליים הוא תמיד עורך ראשי — אי אפשר לבטל אותו מכאן');
    $role = ts_role_of($target) ?? throw new TsNotFound('המשתמש אינו עורך');
    ts_db()->prepare('DELETE FROM app_roles WHERE user_id = ?')->execute([$target]);
    ts_admin_log($by, 'role_revoke', "user:$target role:$role");
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
