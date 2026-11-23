<?php
/**
 * הרשאות — שכבה דקה שמכינה תפקידים לעתיד.
 *
 * היום: תפקיד אחד, viewer. מי שעבר את שער הפלטפורמה (gate + restricted ב-app.json — רק מי שקיבל
 * אישור ישיר לאפליקציה בממשק המנהל) הוא viewer. אין כאן מערכת משתמשים, ולא נשמר שום פרט מזהה.
 *
 * מחר: תפקיד נוסף = שורה ב-ACADEMY_ROLES, ופונקציה academy_role() שמכריעה לפי משתמש האפליקציה
 * (מערכת משתמשים משלה — CLAUDE.md, "אפליקציה פנימית עצמאית"). המסכים וה-API שואלים רק
 * academy_can(<הרשאה>), ולכן לא משתנים.
 */

declare(strict_types=1);

/** תפקיד → הרשאות. קריאה בלבד בשלב הזה: אין שום הרשאת כתיבה. */
const ACADEMY_ROLES = [
    'viewer' => ['snapshot.read', 'curriculum.read', 'progress.read.self'],
    // מנהל האקדמיה — sub שמופיע ב-progress.managers בקונפיג השרת (lib/progress.php). אין מפתח או סוד חדש.
    'manager' => ['snapshot.read', 'curriculum.read', 'progress.read.self', 'progress.read.all', 'progress.write'],
    // עתיד (לא פעיל): 'instructor' => ['snapshot.read', 'assessment.read'], 'admin' => [...]
];

/**
 * התפקיד של הבקשה הנוכחית, או null.
 *
 * בשרת: רק משתמש **מחובר ומורשה** — השער (platform/gate-prepend.php) קובע את $_SERVER['PLATFORM_USER'] רק למי שיש לו
 * הרשאה לאפליקציה (granted). בלעדיו (שער לא רץ, אפליקציה מנותקת, תקלה בזיהוי) — אין תפקיד: fail-closed.
 * CLI ושרת הפיתוח של PHP (php -S, SAPI "cli-server") — בלי שער, ולכן viewer של סביבת פיתוח. בשרת האמיתי (LiteSpeed) לא
 * רץ אף אחד מהם.
 * לא נשמר ולא מוחזר שום פרט מזהה — רק "יש משתמש מורשה".
 */
function academy_role(?array $server = null, string $sapi = PHP_SAPI): ?string {
    if ($sapi === 'cli' || $sapi === 'cli-server') return 'viewer';
    $server ??= $_SERVER;
    $u = json_decode((string) ($server['PLATFORM_USER'] ?? ''), true);
    if (!is_array($u) || ($u['granted'] ?? null) !== true) return null;
    return 'viewer';
}

function academy_can(string $permission, ?string $role = null): bool {
    $role ??= academy_role();
    return $role !== null && in_array($permission, ACADEMY_ROLES[$role] ?? [], true);
}
