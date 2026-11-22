<?php
/**
 * השגיאות של ספורנו — קוד קבוע, מצב HTTP והודעה בעברית (אפיון 01 §5.3, §18 "שגיאות אחידות").
 * התשובה: { "ok": false, "error": { "code": "…", "message": "…" } } — בלי SQL, בלי נתיב קובץ ובלי trace.
 * תקלה שאינה SfError היא "server" (500), וההודעה המקורית נשארת ביומן הטכני בלבד.
 */

declare(strict_types=1);

const SF_ERRORS = [
    'bad_input'         => [400, 'הנתונים שנשלחו אינם תקינים'],
    'login_required'    => [401, 'צריך להיכנס דרך הכלים הדיגיטליים'],
    'forbidden'         => [403, 'אין הרשאה לפעולה הזו'],
    'csrf'              => [403, 'הבקשה נחסמה מטעמי אבטחה. רעננו את הדף ונסו שוב'],
    'suspended'         => [403, 'החשבון הושעה. פנו למנהל אחר'],
    'not_found'         => [404, 'לא נמצא'],
    'unknown_action'    => [404, 'פעולה לא מוכרת'],
    'method'            => [405, 'שיטת הבקשה אינה מתאימה לפעולה'],
    'last_admin'        => [409, 'חייב להישאר לפחות מנהל פעיל אחד'],
    'self_suspend'      => [409, 'אי אפשר להשעות את עצמך'],
    'state'             => [409, 'הפריט אינו במצב שמאפשר את הפעולה'],
    'too_large'         => [413, 'הבקשה גדולה מדי'],
    'content_type'      => [415, 'הבקשה חייבת להיות בפורמט JSON'],
    'server'            => [500, 'תקלה בשרת. היא נרשמה ביומן, ונטפל בה'],
    'login_unavailable' => [503, 'הכניסה לא זמינה כרגע. נסו שוב בעוד כמה דקות'],
    'storage'           => [503, 'המסד לא זמין כרגע. נסו שוב בעוד כמה דקות'],
    'upgrading'         => [503, 'המערכת מתעדכנת. נסו שוב בעוד דקה'],
    'devloop'           => [400, ''],
];

final class SfError extends RuntimeException
{
    public readonly string $errorCode;

    public function __construct(string $code, ?string $message = null, ?int $status = null)
    {
        $def = SF_ERRORS[$code] ?? SF_ERRORS['server'];
        $this->errorCode = isset(SF_ERRORS[$code]) ? $code : 'server';
        parent::__construct($message ?? $def[1], $status ?? $def[0]);
    }
}

function sf_fail(string $code, ?string $message = null): never
{
    throw new SfError($code, $message);
}

/** [status, body] לשגיאה. כל מה שאינו SfError — "server", בלי לחשוף את ההודעה. */
function sf_error_response(Throwable $e): array
{
    if ($e instanceof SfError) {
        $status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
        return [$status, ['ok' => false, 'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]]];
    }
    $req = function_exists('devloop_req_id') ? devloop_req_id() : '';
    return [500, ['ok' => false, 'error' => ['code' => 'server', 'message' => SF_ERRORS['server'][1] . ($req !== '' ? " (מזהה: $req)" : '')]]];
}
