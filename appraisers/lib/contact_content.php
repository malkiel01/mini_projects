<?php
/**
 * מקטע "קשר ותזכורות" של פרופיל (אפיון 08 §4): מבנה, ברירות מחדל, ונוסחים עם משתנים.
 * משתני נוסח — **רשימה סגורה** (APR_TPL_VARS). אין משתנה שמכניס כתובת נכס, מספר תיק או שם של גורם אחר; משתנה שאינו ברשימה
 * חוסם את פרסום הפרופיל (08-D3). ההצבה היא החלפת מחרוזות במעבר אחד — בלי eval, ובלי לסרוק שוב ערך שהוצב.
 */

declare(strict_types=1);

const APR_TPL_VARS = ['שם_הנמען', 'שם_המשרד', 'שם_השמאי', 'רשימת_הדרישות', 'קישור', 'תאריך_יעד'];
const APR_WEEK = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
const APR_CONTACT_CHANNELS = ['email', 'sms', 'whatsapp'];
const APR_STOP_WHEN = ['requirement_closed', 'recipient_answered', 'request_revoked'];
const APR_TPL_MAX = ['subject' => 150, 'body' => 4000];

/** ברירת המחדל: שליחה ידנית, מייל, ותזכורות כבויות — פרופיל שלא הגדיר את המקטע אינו שולח דבר לבד. */
function apr_contact_defaults(): array
{
    return ['send_mode' => 'manual', 'default_channel' => 'email',
        'templates' => [
            'request' => ['subject' => 'בקשה למסמכים — {שם_המשרד}',
                'body' => "שלום {שם_הנמען},\n\nלצורך הכנת חוות דעת שמאית נבקש לקבל את המסמכים שברשימה.\nאפשר להעלות אותם בקישור האישי שבהודעה זו — בלי הרשמה ובלי סיסמה.\n\nתודה,\n{שם_השמאי}\n{שם_המשרד}"],
            'reminder' => ['subject' => 'תזכורת: מסמכים שטרם התקבלו — {שם_המשרד}',
                'body' => "שלום {שם_הנמען},\n\nתזכורת: עדיין לא התקבלו המסמכים הבאים:\n{רשימת_הדרישות}\n\nלהעלאה:\n{קישור}\n\nתודה,\n{שם_השמאי}\n{שם_המשרד}"]],
        'reminders' => ['enabled' => false, 'after_days' => [3, 7], 'max' => 3, 'days' => ['sun', 'mon', 'tue', 'wed', 'thu'],
            'hours' => ['from' => '09:00', 'to' => '18:00'], 'stop_when' => APR_STOP_WHEN]];
}

/** מנקה את המקטע. מה שחסר — ברירת המחדל; מה שאינו בצורה הנכונה — content_invalid. משתנה לא מוכר נשאר, ונתפס בבדיקות הפרסום. */
function apr_contact_clean(mixed $c): array
{
    $d = apr_contact_defaults();
    if ($c === null) return $d;
    if (!is_array($c)) apr_fail('content_invalid');
    $out = $d;
    foreach (['send_mode' => ['manual', 'auto'], 'default_channel' => APR_CONTACT_CHANNELS] as $k => $allowed) {
        if (array_key_exists($k, $c)) $out[$k] = is_string($c[$k]) && in_array($c[$k], $allowed, true) ? $c[$k] : apr_fail('content_invalid');
    }
    foreach (['request', 'reminder'] as $t) {
        foreach (APR_TPL_MAX as $f => $max) {
            $v = $c['templates'][$t][$f] ?? null;
            if ($v === null) continue;
            if (!is_string($v)) apr_fail('content_invalid');
            $v = trim(str_replace("\r", '', $v));
            if ($f === 'subject') $v = trim((string)preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', $v));
            else $v = (string)preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $v);
            if (mb_strlen($v) > $max) apr_fail('text_too_long');
            if ($v !== '') $out['templates'][$t][$f] = $v;
        }
    }
    $r = $c['reminders'] ?? null;
    if ($r === null) return $out;
    if (!is_array($r)) apr_fail('content_invalid');
    if (array_key_exists('enabled', $r)) $out['reminders']['enabled'] = $r['enabled'] === true;
    if (array_key_exists('after_days', $r)) {
        $a = $r['after_days'];
        if (!is_array($a) || !array_is_list($a) || count($a) > 10) apr_fail('content_invalid');
        foreach ($a as $n) if (!is_int($n) || $n < 1 || $n > 365) apr_fail('content_invalid');
        $a = array_values(array_unique($a));
        sort($a);
        $out['reminders']['after_days'] = $a;
    }
    if (array_key_exists('max', $r)) $out['reminders']['max'] = is_int($r['max']) && $r['max'] >= 0 && $r['max'] <= 10 ? $r['max'] : apr_fail('content_invalid');
    if (array_key_exists('days', $r)) {
        if (!is_array($r['days']) || array_diff($r['days'], APR_WEEK)) apr_fail('content_invalid');
        $out['reminders']['days'] = array_values(array_intersect(APR_WEEK, $r['days']));
    }
    foreach (['from', 'to'] as $k) {
        $v = $r['hours'][$k] ?? null;
        if ($v === null) continue;
        if (!is_string($v) || preg_match('/^([01]\d|2[0-3]):[0-5]\d\z/', $v) !== 1) apr_fail('content_invalid');
        $out['reminders']['hours'][$k] = $v;
    }
    if (array_key_exists('stop_when', $r)) {
        if (!is_array($r['stop_when']) || array_diff($r['stop_when'], APR_STOP_WHEN)) apr_fail('content_invalid');
        // דרישה שנסגרה ובקשה שבוטלה עוצרות תמיד — אין תזכורת על מה שכבר אינו נדרש; רק "הנמען ענה" נתון לבחירה
        $out['reminders']['stop_when'] = array_values(array_unique(array_merge(['requirement_closed', 'request_revoked'], array_values($r['stop_when']))));
    }
    return $out;
}

/** המשתנים שבנוסח ואינם ברשימה הסגורה. */
function apr_tpl_unknown(string $tpl): array
{
    preg_match_all('/\{([^{}\r\n]*)\}/u', $tpl, $m);
    return array_values(array_unique(array_diff($m[1], APR_TPL_VARS)));
}

/** ערך של משתנה שמקורו במשתמש (שם נמען, שם משרד, כותרת דרישה): שורה אחת, בלי תווי בקרה, עד 160 תווים. */
function apr_tpl_val(mixed $v): string
{
    return mb_substr(trim((string)preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', is_string($v) ? $v : '')), 0, 160);
}

/** מציב ערכים במשתנים המוכרים, במעבר אחד: ערך שהוצב אינו נסרק שוב, ומשתנה לא מוכר נשאר כפי שהוא. */
function apr_tpl_render(string $tpl, array $vars): string
{
    return (string)preg_replace_callback('/\{([^{}\r\n]*)\}/u',
        fn(array $m) => in_array($m[1], APR_TPL_VARS, true) && array_key_exists($m[1], $vars) ? (string)$vars[$m[1]] : $m[0], $tpl);
}

/** נושא של הודעה, אחרי הצבה: שורה אחת — שום CR/LF אינו מגיע לכותרות המייל. */
function apr_tpl_subject(string $tpl, array $vars): string
{
    return mb_substr(trim((string)preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', apr_tpl_render($tpl, $vars))), 0, 150);
}

/** בדיקות הפרסום של המקטע: [code, message, ref]. */
function apr_contact_checks(array $contact): array
{
    $err = [];
    foreach ($contact['templates'] as $t => $fields) {
        foreach ($fields as $f => $text) {
            foreach (apr_tpl_unknown($text) as $name) $err[] = ['bad_variable', 'משתנה לא מוכר בנוסח: {' . mb_substr($name, 0, 40) . '}', "contact:$t.$f"];
        }
    }
    $r = $contact['reminders'];
    if ($r['hours']['from'] >= $r['hours']['to']) $err[] = ['bad_hours', 'חלון השעות של התזכורות ריק', 'contact:reminders'];
    if ($r['enabled'] && (!$r['after_days'] || !$r['days'] || $r['max'] < 1)) $err[] = ['bad_reminders', 'תזכורות פעילות בלי ימים, בלי מועדים או בלי מספר מרבי', 'contact:reminders'];
    return $err;
}
