<?php
/**
 * יומן הפעולות (אפיון 01 §7). פונקציה אחת: sf_audit(), **באותה טרנזקציה של השינוי** — יומן שנכשל מפיל את הפעולה
 * (אין כאן try/catch בכוונה). הטבלה רק מתווספת: טריגר במסד חוסם עדכון ומחיקה.
 *
 * ב-meta: מזהים ומצבים (קודם → חדש). לעולם לא אסימון, סיסמה, תוכן, שמות לתפילה או הערות (§19) — המסננת למטה מוודאת.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** המבצע של הבקשה הנוכחית: id של מנהל, או null (המערכת). נקבע בנתב אחרי הזיהוי. */
function sf_actor(?int $staffId = null, bool $set = false): ?int
{
    if ($set) $GLOBALS['sf_actor'] = $staffId;
    return $GLOBALS['sf_actor'] ?? null;
}

/**
 * @param string|null $kind staff · system · job. ברירת מחדל: staff כשיש מבצע, אחרת system.
 * @param int|false|null $actor false = המבצע של הבקשה; null = המערכת.
 */
function sf_audit(string $action, ?string $entityType = null, ?string $entityPid = null, array $meta = [], int|false|null $actor = false, ?string $kind = null): void
{
    $actorId = $actor === false ? sf_actor() : $actor;
    $kind ??= $actorId === null ? 'system' : 'staff';
    sf_insert('audit_log', [
        'at' => sf_now(),
        'actor_staff_id' => $actorId,
        'actor_kind' => $kind,
        'action' => $action,
        'entity_type' => $entityType,
        'entity_pid' => $entityPid,
        'meta' => $meta ? json_encode(sf_audit_clean($meta), JSON_UNESCAPED_UNICODE) : null,
        'req' => function_exists('devloop_req_id') ? devloop_req_id() : null,
    ]);
}

/** רק סקלרים קצרים, ובלי מפתחות שנשמעים כמו סוד או תוכן. */
function sf_audit_clean(array $meta): array
{
    $out = [];
    foreach ($meta as $k => $v) {
        if (preg_match('/token|secret|pass|csrf|body|content|note|text|name/i', (string)$k)) continue;
        if (is_array($v)) { $out[$k] = sf_audit_clean($v); continue; }
        if (is_string($v)) $v = mb_substr($v, 0, 80);
        if (is_scalar($v) || $v === null) $out[$k] = $v;
    }
    return $out;
}

/** מסך המפתח, "יומן פעולות": מהחדש לישן, עם סינון. */
function sf_audit_view(array $q): array
{
    $where = [];
    $args = [];
    $action = is_string($q['action'] ?? null) ? $q['action'] : '';
    if ($action !== '' && preg_match('/^[a-z_.]{1,60}$/', $action)) { $where[] = 'a.action = ?'; $args[] = $action; }
    $actor = $q['actor'] ?? '';
    if ($actor === 'system') $where[] = 'a.actor_staff_id IS NULL';
    elseif (sf_is_pid($actor)) { $where[] = 's.pid = ?'; $args[] = $actor; }
    foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
        $d = is_string($q[$k] ?? null) ? $q[$k] : '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { $where[] = "a.at $op ?"; $args[] = $d . ($k === 'to' ? 'T23:59:59Z' : 'T00:00:00Z'); }
    }
    $page = max(0, min(1000, (int)($q['page'] ?? 0)));
    $limit = 100;
    $rows = sf_rows('SELECT a.id, a.at, a.actor_kind, a.action, a.entity_type, a.entity_pid, a.meta, a.req, s.name AS actor_name, s.pid AS actor_pid
        FROM audit_log a LEFT JOIN staff s ON s.id = a.actor_staff_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') .
        ' ORDER BY a.id DESC LIMIT ' . ($limit + 1) . ' OFFSET ' . ($page * $limit), $args);
    $more = count($rows) > $limit;
    return [
        'rows' => array_map(fn($r) => ['at' => $r['at'], 'actor' => $r['actor_name'], 'actor_pid' => $r['actor_pid'], 'actor_kind' => $r['actor_kind'],
            'action' => $r['action'], 'entity_type' => $r['entity_type'], 'entity_pid' => $r['entity_pid'],
            'meta' => $r['meta'] !== null ? json_decode($r['meta'], true) : null, 'req' => $r['req']], array_slice($rows, 0, $limit)),
        'more' => $more,
        'page' => $page,
        'actions' => array_column(sf_rows('SELECT DISTINCT action FROM audit_log ORDER BY action'), 'action'),
        'actors' => sf_rows('SELECT pid, name FROM staff ORDER BY name'),
    ];
}
