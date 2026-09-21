<?php
/**
 * יומן — כל צעד במערכת נרשם, וטוקן שמאפשר למי שמפתח לראות אותו.
 *
 * מה נרשם: כל קריאת API (הפעולה, מי, הצליח או נכשל, כמה זמן), העלאות,
 * אימות ואיפוס, אירועי דוא"ל, ושגיאות JavaScript מהדפדפן. לכל שורה רמה:
 * info / warn / error. כשל שהמשתמש גרם לו (4xx) הוא warn; תקלה שלנו (5xx)
 * היא error.
 *
 * מה **לא** נרשם: סיסמאות, כתובות דוא"ל, טקסט של מתכונים ותגובות, אסימונים.
 * מהקלט נשמרים רק שדות מרשימה סגורה (LOG_SAFE_FIELDS) — מזהים ומפתחות,
 * שמספיקים לשחזר מה קרה בלי לשמור מה נכתב.
 *
 * הטוקן: המפתח יוצר אותו עם תוקף (חצי שעה, שעה, יום, שבוע, או מותאם),
 * ומעביר קישור. הקישור מציג את היומן בלי כניסה — קריאה בלבד, עד שהתוקף
 * פג או שהמפתח ביטל. במסד נשמר רק hash של הטוקן, כמו אסימוני האימות.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';

const LOG_LEVELS      = ['info', 'warn', 'error'];
const LOG_SAFE_FIELDS = ['id', 'recipe_id', 'user_id', 'fav_id', 'parent_id', 'comment_id',
                         'key', 'q', 'open', 'value', 'blocked', 'verified', 'kind',
                         'username', 'ttl_minutes', 'label', 'level', 'action'];
const LOG_KEEP_DAYS   = 30;
const LOG_KEEP_ROWS   = 50000;
const LOG_TOKEN_MAX_MINUTES = 60 * 24 * 90;   // עד שלושה חודשים — יותר מזה זה כבר לא "זמני"

/** מזהה בקשה — קושר כמה שורות מאותה בקשה, וזהה בכל הקבצים. */
function logRequestId(): string {
    static $id = null;
    return $id ??= substr(bin2hex(random_bytes(6)), 0, 12);
}

/** שורה ביומן. אף פעם לא זורק — יומן שמפיל את הבקשה גרוע מיומן חסר. */
function logEvent(string $level, string $action, string $message = '', array $meta = [],
                  ?array $user = null, ?int $durationMs = null): void {
    if (!in_array($level, LOG_LEVELS, true)) $level = 'info';
    try {
        $st = db()->prepare('INSERT INTO app_log (at, level, request_id, user_id, username, action, message,
                                                  meta, ip, user_agent, duration_ms)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([
            nowIso(), $level, logRequestId(),
            $user ? (int) $user['id'] : null,
            $user ? ($user['username'] ?? null) : null,
            mb_substr($action, 0, 60),
            mb_substr($message, 0, 500),
            $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            PHP_SAPI === 'cli' ? null : ($_SERVER['REMOTE_ADDR'] ?? null),
            PHP_SAPI === 'cli' ? null : mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160),
            $durationMs,
        ]);
    } catch (Throwable $e) {
        error_log('recipes-app log: ' . $e->getMessage());
    }
}

/** הקלט של הבקשה, מסונן לרשימה הסגורה. */
function logSafeInput(array $in): array {
    $out = [];
    foreach (LOG_SAFE_FIELDS as $k) {
        if (!array_key_exists($k, $in)) continue;
        $v = $in[$k];
        if (is_scalar($v) || $v === null) $out[$k] = is_string($v) ? mb_substr($v, 0, 80) : $v;
    }
    return $out;
}

/**
 * ניקוי: מוחק מה שישן מ-30 יום או מעבר ל-50k שורות. נקרא אחת ל-200 בקשות
 * בערך — לא בכל בקשה, כי DELETE על טבלה גדולה עולה זמן.
 */
function logPrune(bool $force = false): void {
    if (!$force && random_int(1, 200) !== 1) return;
    try {
        $pdo = db();
        $pdo->prepare('DELETE FROM app_log WHERE at < ?')
            ->execute([gmdate('Y-m-d\TH:i:s\Z', time() - LOG_KEEP_DAYS * 86400)]);
        $pdo->exec('DELETE FROM app_log WHERE id <= (SELECT id FROM app_log ORDER BY id DESC
                     LIMIT 1 OFFSET ' . LOG_KEEP_ROWS . ')');
        // טוקן מת נשאר ברשימה שבוע — כדי שיהיה אפשר לראות מה פג ומה בוטל.
        $week = gmdate('Y-m-d\TH:i:s\Z', time() - 7 * 86400);
        $pdo->prepare('DELETE FROM log_tokens WHERE expires_at < ? OR revoked_at < ?')->execute([$week, $week]);
    } catch (Throwable $e) {
        error_log('recipes-app log prune: ' . $e->getMessage());
    }
}

/**
 * קריאה. $filters: level, action, user (שם), q (בטקסט/במטא), request_id,
 * before (id — לדפדוף אחורה), since (ISO). מחזיר עד $limit שורות, מהחדש לישן.
 */
function listLog(array $filters = [], int $limit = 100): array {
    $where = [];
    $params = [];
    if (!empty($filters['level']) && in_array($filters['level'], LOG_LEVELS, true)) {
        // warn כולל error: מי שמסנן "בעיות" רוצה את שתיהן.
        if ($filters['level'] === 'warn') $where[] = "level IN ('warn','error')";
        else { $where[] = 'level = ?'; $params[] = $filters['level']; }
    }
    if (!empty($filters['action']))     { $where[] = 'action = ?';         $params[] = mb_substr((string) $filters['action'], 0, 60); }
    if (!empty($filters['user']))       { $where[] = 'username = ?';       $params[] = mb_substr((string) $filters['user'], 0, 32); }
    if (!empty($filters['request_id'])) { $where[] = 'request_id = ?';     $params[] = mb_substr((string) $filters['request_id'], 0, 12); }
    if (!empty($filters['since']))      { $where[] = 'at >= ?';            $params[] = mb_substr((string) $filters['since'], 0, 25); }
    if (!empty($filters['before']))     { $where[] = 'id < ?';             $params[] = (int) $filters['before']; }
    if (!empty($filters['q'])) {
        $where[] = '(message LIKE ? OR meta LIKE ? OR action LIKE ?)';
        $like = '%' . mb_substr((string) $filters['q'], 0, 80) . '%';
        array_push($params, $like, $like, $like);
    }
    $limit = max(1, min(500, $limit));
    $sql = 'SELECT * FROM app_log' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY id DESC LIMIT ' . $limit;
    $st = db()->prepare($sql);
    $st->execute($params);
    return array_map(fn($r) => [
        'id'          => (int) $r['id'],
        'at'          => $r['at'],
        'level'       => $r['level'],
        'request_id'  => $r['request_id'],
        'user_id'     => $r['user_id'] !== null ? (int) $r['user_id'] : null,
        'username'    => $r['username'],
        'action'      => $r['action'],
        'message'     => $r['message'],
        'meta'        => $r['meta'] !== null ? json_decode($r['meta'], true) : null,
        'ip'          => $r['ip'],
        'user_agent'  => $r['user_agent'],
        'duration_ms' => $r['duration_ms'] !== null ? (int) $r['duration_ms'] : null,
    ], $st->fetchAll());
}

/** מספרים לכותרת המסך: כמה שורות, כמה בעיות ב-24 השעות האחרונות, אילו פעולות קיימות. */
function logStats(): array {
    $pdo = db();
    $day = gmdate('Y-m-d\TH:i:s\Z', time() - 86400);
    $st = $pdo->prepare("SELECT COUNT(*) FROM app_log WHERE at >= ? AND level IN ('warn','error')");
    $st->execute([$day]);
    $problems = (int) $st->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM app_log WHERE at >= ? AND level = 'error'");
    $st->execute([$day]);
    $errors = (int) $st->fetchColumn();
    return [
        'rows'          => (int) $pdo->query('SELECT COUNT(*) FROM app_log')->fetchColumn(),
        'oldest'        => $pdo->query('SELECT MIN(at) FROM app_log')->fetchColumn() ?: null,
        'problems_24h'  => $problems,
        'errors_24h'    => $errors,
        'actions'       => array_column($pdo->query('SELECT DISTINCT action FROM app_log ORDER BY action')->fetchAll(), 'action'),
        'users'         => array_column($pdo->query('SELECT DISTINCT username FROM app_log WHERE username IS NOT NULL ORDER BY username')->fetchAll(), 'username'),
        'keep_days'     => LOG_KEEP_DAYS,
        'keep_rows'     => LOG_KEEP_ROWS,
    ];
}

/** היומן כטקסט — להדבקה בצ'אט או לשמירה. שורה לכל אירוע. */
function logAsText(array $rows): string {
    $out = [];
    foreach (array_reverse($rows) as $r) {   // כרונולוגי, כמו קובץ לוג
        $out[] = sprintf('%s %-5s %s %s %s%s%s',
            $r['at'], strtoupper($r['level']), $r['request_id'],
            $r['username'] ?? '-', $r['action'],
            $r['message'] !== '' ? ' — ' . $r['message'] : '',
            $r['meta'] ? ' ' . json_encode($r['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '')
            . ($r['duration_ms'] !== null ? ' (' . $r['duration_ms'] . 'ms)' : '');
    }
    return implode("\n", $out) . ($out ? "\n" : '');
}

// ─────────────────────────────────────────────────────────────
// טוקן צפייה — למפתח
// ─────────────────────────────────────────────────────────────

/** יוצר טוקן ומחזיר אותו **פעם אחת** — במסד נשמר רק ה-hash. */
function createLogToken(string $label, int $ttlMinutes, array $developer): array {
    if ($ttlMinutes < 5) throw new AppError('תוקף קצר מדי — לפחות 5 דקות');
    if ($ttlMinutes > LOG_TOKEN_MAX_MINUTES) throw new AppError('תוקף ארוך מדי — עד 90 יום');
    $label = mb_substr(trim($label), 0, 60);
    if ($label === '') $label = 'טוקן';

    $raw = bin2hex(random_bytes(24));
    $expires = gmdate('Y-m-d\TH:i:s\Z', time() + $ttlMinutes * 60);
    $st = db()->prepare('INSERT INTO log_tokens (token_hash, label, created_by, created_at, expires_at)
                         VALUES (?,?,?,?,?)');
    $st->execute([hash('sha256', $raw), $label, $developer['id'], nowIso(), $expires]);
    $id = (int) db()->lastInsertId();
    logEvent('info', 'log-token-create', "נוצר טוקן \"$label\"", ['token_id' => $id, 'ttl_minutes' => $ttlMinutes], $developer);
    return ['id' => $id, 'token' => $raw, 'label' => $label, 'expires_at' => $expires];
}

function listLogTokens(): array {
    $rows = db()->query('SELECT t.*, u.username AS created_by_name FROM log_tokens t
                          JOIN users u ON u.id = t.created_by ORDER BY t.id DESC LIMIT 50')->fetchAll();
    $now = nowIso();
    return array_map(fn($t) => [
        'id'              => (int) $t['id'],
        'label'           => $t['label'],
        'created_by'      => $t['created_by_name'],
        'created_at'      => $t['created_at'],
        'expires_at'      => $t['expires_at'],
        'revoked_at'      => $t['revoked_at'],
        'last_used_at'    => $t['last_used_at'],
        'uses'            => (int) $t['uses'],
        'active'          => $t['revoked_at'] === null && $t['expires_at'] > $now,
    ], $rows);
}

function revokeLogToken(int $id, array $developer): void {
    $st = db()->prepare('UPDATE log_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL');
    $st->execute([nowIso(), $id]);
    if ($st->rowCount() === 0) throw new AppError('הטוקן אינו קיים או שכבר בוטל', 404);
    logEvent('info', 'log-token-revoke', 'טוקן בוטל', ['token_id' => $id], $developer);
}

/** הטוקן מהקישור → השורה שלו, או null. רושם שימוש. */
function resolveLogToken(string $raw): ?array {
    if (!preg_match('/^[0-9a-f]{48}$/', $raw)) return null;
    $st = db()->prepare('SELECT * FROM log_tokens WHERE token_hash = ?');
    $st->execute([hash('sha256', $raw)]);
    $t = $st->fetch();
    if (!$t || $t['revoked_at'] !== null || $t['expires_at'] <= nowIso()) return null;
    db()->prepare('UPDATE log_tokens SET last_used_at = ?, uses = uses + 1 WHERE id = ?')
        ->execute([nowIso(), $t['id']]);
    return $t;
}
