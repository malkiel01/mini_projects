<?php
declare(strict_types=1);

/**
 * יומן הפעולות (events) — המקום היחיד שבו נרשם שינוי (אפיון 00 §3.7, 01 §2.1.6), ויומן הבקשות (req_log, 1,000 אחרונות).
 * audit הישן נשאר כמו שהוא — היסטוריה בלבד.
 */
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

const EVENT_SOURCES = ['ui', 'import', 'api', 'system'];
const REQ_LOG_KEEP = 1000;

/** $actor — ['id' => user id או null, 'actor' => מחרוזת להצגה]. */
function recordEvent(?int $orgId, ?string $caseId, array $actor, string $source, string $action, array $diff = []): void {
    if (!in_array($source, EVENT_SOURCES, true)) throw new LogicException('recordEvent: מקור לא מוכר');
    db()->prepare('INSERT INTO events(org_id, case_id, at, actor_user_id, actor, source, action, diff) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$orgId, $caseId, gmdate('c'), $actor['id'] ?? null, (string)($actor['actor'] ?? ''), $source, $action,
            json_encode((object)$diff, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
}

/**
 * מה השתנה בתיק: {שדה: [לפני, אחרי]}. בדיקות — לכל בדיקה בנפרד (checks.<key>). תיק חדש — כל השדות שאינם ריקים.
 */
function caseDiff(?array $old, array $new): array {
    $diff = [];
    $skip = ['checks', 'manual_fields'];
    foreach ($new as $k => $v) {
        if (in_array($k, $skip, true)) continue;
        $before = $old[$k] ?? null;
        if ($old === null && ($v === '' || $v === [] || $v === null)) continue;
        if ($before !== $v) $diff[$k] = [$before, $v];
    }
    foreach (($new['checks'] ?? []) as $k => $c) {
        $b = $old['checks'][$k] ?? null;
        $strip = fn($x) => is_array($x) ? ['status' => $x['status'] ?? '', 'value' => $x['value'] ?? '', 'note' => $x['note'] ?? ''] : null;
        if ($strip($b) !== $strip($c)) $diff['checks.' . $k] = [$strip($b), $strip($c)];
    }
    return $diff;
}

/** שורה ליומן הבקשות. נקרא בסוף כל בקשה (register_shutdown_function ב-api.php). כשל ברישום לא מפיל דבר. */
function logRequest(string $method, string $action, int $status, int $ms, ?int $userId, string $error): void {
    try {
        $pdo = db();
        if ($pdo->inTransaction()) $pdo->rollBack();   // בקשה שנקטעה באמצע טרנזקציה — שום דבר ממנה לא נשמר
        $pdo->prepare('INSERT INTO req_log(at, method, action, status, ms, user_id, error) VALUES (?,?,?,?,?,?,?)')
            ->execute([gmdate('c'), substr($method, 0, 10), mb_substr($action, 0, 60), $status, $ms, $userId, mb_substr($error, 0, 500)]);
        $id = (int)$pdo->lastInsertId();
        if ($id > REQ_LOG_KEEP) $pdo->prepare('DELETE FROM req_log WHERE id <= ?')->execute([$id - REQ_LOG_KEEP]);
    } catch (Throwable $e) {
        error_log('funerals-dashboard req_log: ' . $e->getMessage());
    }
}
