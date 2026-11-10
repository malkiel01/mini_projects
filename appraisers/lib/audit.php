<?php
/**
 * יומן הפעולות (audit) — אפיון 01 §8. פונקציה אחת, apr_audit(), שכל פעולה משנה קוראת לה **באותה טרנזקציה** של
 * השינוי: אם היומן נכשל, הפעולה נכשלת. הטבלה רק מתמלאת — טריגרים במסד חוסמים עדכון ומחיקה.
 *
 * מה נכנס ל-meta: מזהים (pid), מצב קודם וחדש, תפקיד, ספירות.
 * מה לא נכנס, לעולם: שם לקוח, כתובת נכס, שם קובץ, תוכן מסמך, מפתח, אסימון, סיסמה.
 *
 * ההצגה מפענחת מזהים לשמות בזמן התצוגה ולפי הרשאת הצופה: מנהל משרד רואה שמות של חברי המשרד שלו; המפתח רואה
 * מזהים, קודי פעולה ומצבים.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** מזהה הבקשה — אותו מזהה ביומן הטכני (X-Request-Id). */
function apr_req_id(): string
{
    return function_exists('devloop_req_id') ? devloop_req_id() : '';
}

/** המבצע של הבקשה הנוכחית — הנתב קובע אותו אחרי הזיהוי. */
function apr_actor(?int $userId = null): ?int
{
    if (func_num_args()) $GLOBALS['apr_actor'] = $userId;
    return $GLOBALS['apr_actor'] ?? null;
}

function apr_audit(string $action, string $entityType, string $entityPid, array $meta = [], ?string $reason = null,
    ?int $office = null, ?int $actor = null, string $kind = 'user'): void
{
    if ($kind === 'user') $actor ??= apr_actor();   // פעולה של המערכת (kind אחר) — בלי מבצע
    apr_insert('audit_log', ['at' => apr_now(), 'office_id' => $office, 'actor_user_id' => $actor,
        'actor_kind' => $actor === null && $kind === 'user' ? 'system' : $kind, 'action' => $action,
        'entity_type' => $entityType, 'entity_pid' => $entityPid,
        'meta' => json_encode((object)$meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'reason' => $reason === null || $reason === '' ? null : mb_substr($reason, 0, 500), 'req' => apr_req_id()]
        + (isset($GLOBALS['apr_audit_case']) ? ['case_id' => $GLOBALS['apr_audit_case']] : []));   // פעולה בתיק — לשונית הפעילות שלו
}

/** נימוק לפעולה שדורשת אותו. */
function apr_reason(mixed $v): string
{
    $s = trim(is_string($v) ? $v : '');
    if ($s === '') apr_fail('reason_required');
    if (mb_strlen($s) > 500) apr_fail('text_too_long');
    return $s;
}

/**
 * שורות מהיומן, מהחדשה לישנה. $office (id פנימי) — רק המשרד הזה, עם שמות חברי המשרד; null — כל המערכת, מזהים
 * בלבד (המפתח). $q: offset, what (תחילת קוד הפעולה), office (pid), user (pid), from, to (תאריך), limit.
 */
function apr_audit_rows(?int $office, array $q = []): array
{
    $w = ['1 = 1'];
    $a = [];
    if ($office !== null) { $w[] = 'a.office_id = ?'; $a[] = $office; }
    // פעולות בתוך תיק שייכות ללשונית הפעילות של התיק (למשתתפים בלבד). מנהל משרד רואה מהן רק העברת אחריות — כמו בשורת הניהול.
    if ($office !== null) $w[] = "(a.case_id IS NULL OR a.action = 'case.responsible')";
    if (is_string($q['what'] ?? null) && $q['what'] !== '') { $w[] = "a.action LIKE ? ESCAPE '\\'"; $a[] = strtr($q['what'], ['%' => '', '_' => '\\_', '\\' => '']) . '%'; }
    if ($office === null && apr_is_pid($q['office'] ?? null)) { $w[] = 'o.pid = ?'; $a[] = $q['office']; }
    if ($office === null && apr_is_pid($q['user'] ?? null)) { $w[] = 'u.pid = ?'; $a[] = $q['user']; }
    foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
        if (is_string($q[$k] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $q[$k])) { $w[] = "a.at $op ?"; $a[] = $q[$k] . ($k === 'from' ? 'T00:00:00Z' : 'T23:59:59Z'); }
    }
    $limit = max(1, min(200, (int)($q['limit'] ?? 100)));
    $offset = max(0, (int)($q['offset'] ?? 0));
    $rows = apr_rows('SELECT a.*, o.pid AS office_pid, u.pid AS actor_pid, u.name AS actor_name FROM audit_log a
        LEFT JOIN offices o ON o.id = a.office_id LEFT JOIN users u ON u.id = a.actor_user_id
        WHERE ' . implode(' AND ', $w) . " ORDER BY a.id DESC LIMIT $limit OFFSET $offset", $a);

    // יומן המפתח: נתונים תפעוליים בלבד — הנימוק של פעולה בתיק הוא תוכן של התיק
    if ($office === null) foreach ($rows as $i => $r) if ($r['case_id'] !== null) $rows[$i]['reason'] = null;

    // שמות — רק למנהל משרד, ורק של מי שהיה אי-פעם חבר במשרד שלו
    $names = [];
    if ($office !== null) {
        $names = array_column(apr_rows('SELECT u.pid, u.name FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.office_id = ?', [$office]), 'name', 'pid');
    }
    return array_map(function ($r) use ($office, $names) {
        $row = ['at' => $r['at'], 'action' => $r['action'], 'actor_kind' => $r['actor_kind'],
            'entity_type' => $r['entity_type'], 'entity_pid' => $r['entity_pid'], 'meta' => json_decode($r['meta'], true) ?: (object)[],
            'reason' => $r['reason'], 'req' => $r['req']];
        if ($office !== null) {
            return $row + ['actor' => $r['actor_pid'] !== null ? ($names[$r['actor_pid']] ?? '') : '',
                'entity_name' => $r['entity_type'] === 'user' ? ($names[$r['entity_pid']] ?? '') : ''];
        }
        return $row + ['office' => $r['office_pid'], 'actor' => $r['actor_pid']];
    }, $rows);
}
