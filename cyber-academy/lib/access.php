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
    'viewer' => ['snapshot.read'],
    // עתיד (לא פעיל): 'instructor' => ['snapshot.read', 'assessment.read'], 'admin' => [...]
];

/** התפקיד של הבקשה הנוכחית, או null. */
function academy_role(): ?string {
    // השער עוצר לפני הקוד הזה כל מי שאין לו הרשאה; בקשה שהגיעה לכאן — עברה אותו.
    // (בהרצה מקומית/CLI אין שער — שם זה viewer של סביבת פיתוח.)
    return 'viewer';
}

function academy_can(string $permission, ?string $role = null): bool {
    $role ??= academy_role();
    return $role !== null && in_array($permission, ACADEMY_ROLES[$role] ?? [], true);
}
