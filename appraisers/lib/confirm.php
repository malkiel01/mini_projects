<?php
/**
 * אישור חוזר לפעולות רגישות (אפיון 02 §6). הרשימה היא נתון — פרוסות מאוחרות מוסיפות אליה שורה.
 *   מחוברת: הבקשה נושאת confirm = מילת האישור שהמשתמש הקליד בחלון האישור.
 *   מנותקת: קוד בן 6 ספרות למייל (10 דקות, 5 ניסיונות); אחרי אימות ה-session מאושר ל-10 דקות של פעולות רגישות.
 * האישור נרשם ביומן הפעולות (confirm.ok), לצד השורה של הפעולה עצמה. בלי אישור — 403 confirm_required.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/mail.php';

const APR_CONFIRM_WORD = 'אישור';
const APR_CONFIRM_TTL = 600;
const APR_CONFIRM_TRIES = 5;
const APR_CONFIRM_CODES = 3;
const APR_CONFIRM_WINDOW = 900;

/**
 * הפעולות הרגישות: פעולה => null (תמיד), או [שדה, ערך] — רגישה רק כשהשדה בבקשה שווה לערך.
 */
const APR_SENSITIVE = [
    'sys-office-status' => ['status', 'suspended'],     // השעיית משרד
    'sys-user-flags'    => ['is_sysadmin', true],       // הענקת מנהל מערכת
    'dev-token-create'  => null,                        // יצירת אסימון DevLoop
];

function apr_is_sensitive(string $action, array $in): bool
{
    if (!array_key_exists($action, APR_SENSITIVE)) return false;
    $when = APR_SENSITIVE[$action];
    return $when === null || ($in[$when[0]] ?? null) === $when[1];
}

/** נקרא מהנתב לפני כל פעולה. */
function apr_confirm_check(array $ctx, string $action, array $in): void
{
    if (!apr_is_sensitive($action, $in)) return;
    if ($ctx['mode'] === 'connected') {
        if (($in['confirm'] ?? null) !== APR_CONFIRM_WORD) apr_fail('confirm_required');
        $by = 'word';
    } else {
        $until = apr_val('SELECT confirmed_until FROM sessions WHERE id = ?', [$ctx['session']]);
        if (!is_string($until) || $until <= apr_now()) apr_fail('confirm_required');
        $by = 'code';
    }
    apr_audit('confirm.ok', 'action', $action, ['by' => $by]);
}

/** מנותקת: שולח קוד בן 6 ספרות למייל של המשתמש. */
function apr_confirm_request(array $ctx): array
{
    if ($ctx['mode'] !== 'detached') apr_fail('forbidden');
    $u = $ctx['user'];
    if ($u['email'] === null) apr_fail('no_email');
    $prev = apr_val('SELECT created_at FROM confirm_codes WHERE session_id = ?', [$ctx['session']]);
    if (is_string($prev) && strtotime($prev) > apr_time() - 60) apr_fail('too_soon');
    // תקרה שלא מתאפסת בניחושים: 3 קודים ב-15 דקות, ל-session ולמשתמש — אחרת 5 ניחושים לכל קוד הם ניחושים בלי סוף
    if (!apr_limit('otp:u:' . $u['id'], APR_CONFIRM_CODES, APR_CONFIRM_WINDOW) || !apr_limit('otp:s:' . $ctx['session'], APR_CONFIRM_CODES, APR_CONFIRM_WINDOW)) apr_fail('too_many_codes');
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    apr_exec('INSERT INTO confirm_codes (session_id, code_hash, attempts, created_at, expires_at) VALUES (?, ?, 0, ?, ?)
        ON CONFLICT(session_id) DO UPDATE SET code_hash = excluded.code_hash, attempts = 0, created_at = excluded.created_at, expires_at = excluded.expires_at',
        [$ctx['session'], apr_confirm_hash($ctx['session'], $code), apr_now(), apr_iso(apr_time() + APR_CONFIRM_TTL)]);
    $status = apr_mail_send('otp', $u['email'], 'קוד אישור — שמאות מקרקעין',
        "קוד האישור שלך: $code\n\nהקוד תקף ל-10 דקות. אם לא ביקשת אותו — אל תמסרו אותו לאיש.\n", 'otp:' . $ctx['session'] . ':' . apr_time());
    if ($status !== 'sent') apr_fail('mail_failed');
    return [];
}

function apr_confirm_hash(int $session, string $code): string
{
    return hash_hmac('sha256', "otp:$session:$code", apr_secret());
}

function apr_confirm_verify(array $ctx, array $in): array
{
    if ($ctx['mode'] !== 'detached') apr_fail('forbidden');
    $code = is_string($in['code'] ?? null) ? trim($in['code']) : '';
    // הניסיון נספר לפני ההשוואה, באותה פקודה שבודקת שהקוד חי. אחרי 5 ניסיונות הקוד מת — והשורה **נשארת**, כדי
    // ש"המתינו דקה" והתקרה של הקודים ימשיכו לחול.
    $live = apr_exec('UPDATE confirm_codes SET attempts = attempts + 1 WHERE session_id = ? AND expires_at > ? AND attempts < ?', [$ctx['session'], apr_now(), APR_CONFIRM_TRIES]);
    $r = $live === 1 ? apr_row('SELECT * FROM confirm_codes WHERE session_id = ?', [$ctx['session']]) : null;
    if (!$r || !hash_equals($r['code_hash'], apr_confirm_hash($ctx['session'], $code))) apr_fail('confirm_code_invalid');
    apr_exec('DELETE FROM confirm_codes WHERE session_id = ?', [$ctx['session']]);
    apr_exec('UPDATE sessions SET confirmed_until = ? WHERE id = ?', [apr_iso(apr_time() + APR_CONFIRM_TTL), $ctx['session']]);
    return ['until' => apr_iso(apr_time() + APR_CONFIRM_TTL)];
}
