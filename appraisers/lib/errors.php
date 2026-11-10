<?php
/**
 * השגיאות של ה-API — הקודים והנוסחים במקום אחד (אפיון 01 §5.3).
 * התשובה למשתמש: { "ok": false, "error": { "code": "…", "message": "…" }, "req": "<מזהה הבקשה>" }.
 * נוסח לעולם אינו כולל SQL, נתיב קובץ, טקסט שהמשתמש הקליד, או שם של ישות שאין לו הרשאה אליה.
 * "אסור" ו"לא קיים" מחזירים אותו קוד ואותו נוסח (not_found) — לא רומזים שיש משהו מוסתר.
 */

declare(strict_types=1);

const APR_ERRORS = [
    // code => [status, message]
    'unauthenticated'    => [401, 'נדרשת כניסה דרך הכלים הדיגיטליים'],
    'login_unavailable'  => [503, 'הכניסה לא זמינה כרגע'],
    'account_suspended'  => [403, 'החשבון הושעה — פנו למנהל המערכת'],
    'csrf'               => [403, 'הדף לא מעודכן — רעננו ונסו שוב'],
    'forbidden'          => [403, 'אין לכם הרשאה לפעולה הזו'],
    'not_found'          => [404, 'לא נמצא'],
    'method'             => [405, 'שיטה לא נתמכת'],
    'too_large'          => [413, 'הבקשה גדולה מדי'],
    'upgrading'          => [503, 'המערכת בעדכון — נסו שוב בעוד רגע'],
    'busy'               => [503, 'המערכת עמוסה — נסו שוב'],
    'storage'            => [503, 'המערכת לא זמינה כרגע'],
    'server'             => [500, 'תקלה בשרת'],

    'no_office'          => [403, 'אינכם חברים במשרד'],
    'self_create_off'    => [403, 'פתיחת משרד נעשית בידי מנהל המערכת'],
    'setting_invalid'    => [422, 'הגדרה לא מוכרת'],
    'office_suspended'   => [403, 'המשרד הושעה — פנו למנהל המערכת'],
    'office_changed'     => [409, 'המשרד הפעיל הוחלף — רעננו את הדף'],
    'name_required'      => [422, 'חסר שם'],
    'name_too_long'      => [422, 'השם ארוך מדי'],
    'email_invalid'      => [422, 'כתובת מייל לא תקינה'],
    'role_invalid'       => [422, 'תפקיד לא מוכר'],
    'status_invalid'     => [422, 'מצב לא מוכר'],
    'text_too_long'      => [422, 'הטקסט ארוך מדי'],
    'reason_required'    => [422, 'נדרש נימוק'],
    'last_admin'         => [409, 'זה מנהל המשרד היחיד. מנו מנהל אחר לפני כן.'],
    'last_admin_leave'   => [409, 'מנו מנהל אחר לפני העזיבה'],
    'self_action'        => [409, 'אי אפשר להשעות או להסיר את עצמכם'],
    'last_sysadmin'      => [409, 'חייב להישאר מנהל מערכת פעיל אחד לפחות'],
    'already_member'     => [409, 'כבר חבר/ה במשרד'],
    'invite_invalid'     => [404, 'ההזמנה אינה בתוקף. בקשו הזמנה חדשה.'],
    'invite_other_email' => [403, 'ההזמנה נשלחה לכתובת אחרת'],
    'no_email'           => [422, 'לחשבון אין כתובת מייל מאומתת, ולכן אי אפשר להזמין אותו לפי מייל'],
    'login_required'     => [401, 'נדרשת כניסה'],
    'password_login_off' => [403, 'הכניסה בסיסמה אינה פעילה'],
    'login_failed'       => [401, 'המייל או הסיסמה שגויים'],
    'login_locked'       => [429, 'יותר מדי ניסיונות. נסו שוב בעוד 15 דקות.'],
    'password_long'      => [422, 'הסיסמה ארוכה מדי — עד 72 תווים'],
    'password_short'     => [422, 'הסיסמה צריכה להיות באורך 10 תווים לפחות'],
    'reset_invalid'      => [404, 'הקישור אינו בתוקף. בקשו קישור חדש.'],
    'confirm_required'   => [403, 'הפעולה דורשת אישור חוזר'],
    'confirm_code_invalid' => [403, 'הקוד שגוי או שפג תוקפו'],
    'mail_failed'        => [502, 'המייל לא נשלח — נסו שוב'],
    'too_many_codes'     => [429, 'נשלחו יותר מדי קודים. נסו שוב בעוד רבע שעה.'],
    'too_many_requests'  => [429, 'יותר מדי בקשות. נסו שוב בעוד רבע שעה.'],
    'too_soon'           => [429, 'נשלח לפני רגע — המתינו דקה ונסו שוב'],
    'invite_limit'       => [429, 'הגעתם למגבלת ההזמנות היומית'],
    'content_invalid'    => [422, 'מבנה הפרופיל אינו תקין'],
    'condition_invalid'  => [422, 'תנאי במבנה שאינו נתמך'],
    'content_too_large'  => [422, 'הפרופיל גדול מדי: עד 300 דרישות, 100 משימות ו-100 כללים'],
    'draft_changed'      => [409, 'הטיוטה עודכנה בינתיים — טענו אותה מחדש'],
    'publish_blocked'    => [422, 'אי אפשר לפרסם: יש בפרופיל שגיאות שצריך לתקן'],
    'profile_state'      => [409, 'אי אפשר לבצע את הפעולה במצב הנוכחי של הפרופיל'],
    'share_no_user'      => [404, 'לא נמצא משתמש בכתובת הזו'],
    'job_state'          => [409, 'אי אפשר לבצע את הפעולה במצב הנוכחי של המשימה'],
];

class AprError extends RuntimeException
{
    public readonly int $status;
    public readonly string $errorCode;

    public function __construct(string $code, ?string $message = null, ?int $status = null)
    {
        [$st, $msg] = APR_ERRORS[$code] ?? [400, $code];
        $this->errorCode = isset(APR_ERRORS[$code]) ? $code : 'error';
        $this->status = $status ?? $st;
        parent::__construct($message ?? $msg, $this->status);
    }
}

function apr_fail(string $code): never
{
    throw new AprError($code);
}
