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
    'job_state'          => [409, 'אי אפשר לבצע את הפעולה במצב הנוכחי של המשימה'],
];

final class AprError extends RuntimeException
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
