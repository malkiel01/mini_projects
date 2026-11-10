<?php
/**
 * יומן פעולות (audit): מי עשה מה, מתי ובאיזה משרד — כל פעולת ניהול, שיתוף ושינוי הרשאה.
 * אין בו תוכן אישי מעבר לנדרש, ואין בו סודות. מנהל משרד רואה את המשרד שלו; המפתח רואה הכול (מסך המפתח).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** מזהה הבקשה — אותו מזהה ביומן הבקשות (X-Request-Id), כדי לעבור מפעולה לבקשה שביצעה אותה. */
function apr_req_id(): string
{
    return function_exists('devloop_req_id') ? devloop_req_id() : '';
}

function apr_audit(?array $user, ?int $officeId, string $action, string $target = '', array $data = []): void
{
    apr_exec('INSERT INTO audit_log (at, office_id, user_id, actor, action, target, data_json, req) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
        apr_now(), $officeId, $user['id'] ?? null, mb_substr((string)($user['display_name'] ?? ''), 0, 120),
        mb_substr($action, 0, 60), mb_substr($target, 0, 200),
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', apr_req_id(),
    ]);
}

/**
 * שורות מהיומן, מהחדשה לישנה. $officeId — רק המשרד הזה (מנהל משרד); null — הכול (המפתח בלבד; הקורא אחראי להרשאה).
 * $q: before (id), what (תחילת שם הפעולה — לא "action", שהוא שם הפעולה של ה-API בכתובת), limit.
 */
function apr_audit_rows(?int $officeId, array $q = []): array
{
    $w = ['1 = 1'];
    $a = [];
    if ($officeId !== null) { $w[] = 'a.office_id = ?'; $a[] = $officeId; }
    if (!empty($q['before'])) { $w[] = 'a.id < ?'; $a[] = (int)$q['before']; }
    if (!empty($q['what'])) { $w[] = "a.action LIKE ? ESCAPE '\\'"; $a[] = strtr((string)$q['what'], ['%' => '', '_' => '\\_']) . '%'; }
    $limit = max(1, min(200, (int)($q['limit'] ?? 100)));
    $rows = apr_rows('SELECT a.*, o.name AS office_name FROM audit_log a LEFT JOIN offices o ON o.id = a.office_id
        WHERE ' . implode(' AND ', $w) . " ORDER BY a.id DESC LIMIT $limit", $a);
    // "user:12" ביעד → השם, כדי שהיומן ייקרא בלי לפענח מזהים
    $ids = [];
    foreach ($rows as $r) if (preg_match('/^user:(\d+)$/', $r['target'], $m)) $ids[(int)$m[1]] = true;
    $names = $ids ? array_column(apr_rows('SELECT id, display_name FROM users WHERE id IN (' . implode(',', array_keys($ids)) . ')'), 'display_name', 'id') : [];
    return array_map(fn($r) => ['id' => (int)$r['id'], 'at' => $r['at'], 'office_id' => $r['office_id'], 'office' => (string)($r['office_name'] ?? ''),
        'user_id' => $r['user_id'], 'actor' => $r['actor'], 'action' => $r['action'], 'target' => $r['target'],
        'target_name' => preg_match('/^user:(\d+)$/', $r['target'], $m) ? (string)($names[(int)$m[1]] ?? '') : '',
        'data' => json_decode($r['data_json'], true) ?: [], 'req' => $r['req']], $rows);
}
