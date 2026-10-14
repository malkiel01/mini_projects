<?php
/**
 * מטלות ושיח — אזור הפיתוח (📋 מטלות, רק למפתח). README: "מטלות ושיח (DevLoop)".
 *
 * המנוע הוא המודול המשותף DevLoop (lib/devloop.php — עותק מסונכרן מ-devloop/src בשורש הריפו; לא עורכים כאן).
 * כאן רק החיבור שלו לתבניות המסמכים: המסד, השגיאה, המשתמשים, המייל — ולוג המעקב (dev_log), שממנו
 * שגיאות שרת (5xx) ושגיאות דפדפן נפתחות לבד כמטלות "⚠️ לבירור", ובחזרה: שורה בלוג מראה לאיזו מטלה היא שייכת.
 */

declare(strict_types=1);

require_once __DIR__ . '/devloop.php';

devloop_setup([
    'db'           => fn() => db(),
    'now'          => fn() => now_iso(),
    'items_file'   => __DIR__ . '/../dev/open-items.json',
    'data_dir'     => DT_DATA_DIR . '/files/_dev',      // צילומים בשיח — data/files מוחרג מ-rsync
    'prefix'       => 'DT-',
    'token_prefix' => 'dtd_',                            // האסימונים מלפני המודול ממשיכים לעבוד
    'error'        => fn(string $msg, int $status, array $vars) => new ApiError(
        strtr($msg, array_combine(array_map(fn($k) => '{' . $k . '}', array_keys($vars)), array_map('strval', $vars)) ?: []), $status),
    'user_names'   => fn() => array_column(db()->query('SELECT id, display_name FROM users')->fetchAll(), 'display_name', 'id'),
    'is_developer' => function (int $id): bool {
        $st = db()->prepare("SELECT 1 FROM users WHERE id = ? AND status = 'active' AND is_developer = 1");
        $st->execute([$id]);
        return (bool)$st->fetchColumn();
    },
    // מייל למפתח: ממתינה לאימות, שאלה, Claude ענה, שגיאות חדשות לבירור
    'notify'       => function (string $subject, string $body): void {
        foreach (db()->query("SELECT email FROM users WHERE is_developer = 1 AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN) as $to) {
            send_mail($to, '[תבניות מסמכים] ' . $subject, $body);
        }
    },
    'item_url'     => fn(string $code) => app_base_url() . '#dev?tab=items&code=' . rawurlencode($code),
    // היומן של הכלי (לוג המעקב, dev_log, 30 יום) — במקום היומן המובנה של DevLoop. ממנו: "⚠️ לבירור" (שגיאת שרת
    // 5xx ושגיאת דפדפן), העקבות של 🐞, "📜 יומן" בכל מטלה, ו-🔗 / "+ מטלה" בכל שורה בלוג
    'log' => [
        'rows'   => 'dt_devloop_log_rows',
        'max_id' => fn() => (int)db()->query('SELECT COALESCE(MAX(id), 0) FROM dev_log')->fetchColumn(),
        'write'  => 'dt_devloop_log_write',
    ],
]);

/** הרמה של שורה בלוג — שורות ישנות בלי level: לפי התוצאה. */
const DT_LOG_LEVEL = "COALESCE(l.level, CASE WHEN l.status >= 500 OR l.action = 'client_error' THEN 'error' WHEN l.status >= 400 THEN 'warn' ELSE 'info' END)";

/** שורות מלוג המעקב, בחוזה של devloop_log_rows: after, before, level (error | warn ומעלה), ids, reqs, q, limit. */
function dt_devloop_log_rows(array $q): array
{
    $w = ['1 = 1'];
    $a = [];
    if (!empty($q['after'])) { $w[] = 'l.id > ?'; $a[] = (int)$q['after']; }
    if (!empty($q['before'])) { $w[] = 'l.id < ?'; $a[] = (int)$q['before']; }
    if (($q['level'] ?? '') === 'error') $w[] = DT_LOG_LEVEL . " = 'error'";
    elseif (($q['level'] ?? '') === 'warn') $w[] = DT_LOG_LEVEL . " IN ('warn', 'error')";
    foreach (['ids' => 'l.id', 'reqs' => 'l.req'] as $k => $col) {
        if (!array_key_exists($k, $q)) continue;
        $vals = array_values(array_slice(array_filter((array)$q[$k], fn($v) => $v !== '' && $v !== null), 0, 200));
        if (!$vals) return [];
        $w[] = "$col IN (" . implode(',', array_fill(0, count($vals), '?')) . ')';
        array_push($a, ...$vals);
    }
    if (!empty($q['q'])) { $w[] = '(l.action LIKE ? OR l.message LIKE ? OR l.trace LIKE ?)'; $x = '%' . strtr((string)$q['q'], ['%' => '', '_' => '']) . '%'; array_push($a, $x, $x, $x); }
    $limit = max(1, min(500, (int)($q['limit'] ?? 100)));
    $st = db()->prepare('SELECT l.*, ' . DT_LOG_LEVEL . ' AS lvl, u.display_name FROM dev_log l LEFT JOIN users u ON u.id = l.user_id
        WHERE ' . implode(' AND ', $w) . ' ORDER BY l.id ' . (!empty($q['after']) ? 'ASC' : 'DESC') . " LIMIT $limit");
    $st->execute($a);
    return array_map('dt_log_row', $st->fetchAll());
}

/** שורת dev_log ← שורה של DevLoop: id, at, level, category, event, message, username, method, action, status, ms, req, ua, data. */
function dt_log_row(array $r): array
{
    $js = $r['action'] === 'client_error';
    $data = array_filter(['page' => $r['page'], 'trace' => $r['trace'] && !$js ? [$r['trace']] : null]);
    if ($js && preg_match('/^(.*?):(\d+)(?::\d+)?$/', (string)$r['trace'], $m)) $data += ['source' => $m[1], 'line' => $m[2]];
    elseif ($js && $r['trace']) $data['source'] = $r['trace'];
    return ['id' => (int)$r['id'], 'at' => $r['at'], 'level' => $r['lvl'] ?? ($r['level'] ?? 'info'),
        'category' => $r['category'] ?: ($js ? 'client' : 'api'), 'event' => $r['event'] ?: ($js ? 'client.error' : 'request'),
        'message' => (string)$r['message'], 'username' => (string)($r['display_name'] ?? ''), 'method' => (string)$r['method'],
        'action' => $js ? '' : (string)$r['action'], 'status' => (int)$r['status'], 'ms' => (int)$r['duration_ms'],
        'req' => (string)($r['req'] ?? ''), 'ua' => (string)($r['ua'] ?? ''), 'data' => $data];
}

/** רשומה מהמודול (למשל אסימון שנדחה) — שורה בלוג המעקב, באותה בקשה. */
function dt_devloop_log_write(string $level, string $category, string $event, array $extra = []): void
{
    try {
        db()->prepare('INSERT INTO dev_log (at, action, duration_ms, method, status, actor, ip, message, req, ua, level, category, event)
                       VALUES (?, ?, 0, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([now_iso(), mb_substr($event, 0, 60), $_SERVER['REQUEST_METHOD'] ?? 'GET', 'claude', client_ip(),
                mb_substr((string)($extra['message'] ?? ''), 0, 500), dev_req_id(), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
                in_array($level, ['info', 'warn', 'error'], true) ? $level : 'info', mb_substr($category, 0, 20), mb_substr($event, 0, 60)]);
    } catch (Throwable) {
        // לוג שנכשל לא מפיל את הבקשה
    }
}

/** טבלאות שנוצרו לפני המודול — מה שחסר בהן (הגבלת קצב לאסימון). */
function devtasks_migrate(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(dev_tokens)')->fetchAll(), 'name');
    if (!$cols) return;   // עוד אין טבלה — המודול ייצור אותה מלאה
    if (!in_array('rate_hour', $cols, true)) $pdo->exec("ALTER TABLE dev_tokens ADD COLUMN rate_hour TEXT NOT NULL DEFAULT ''");
    if (!in_array('rate_n', $cols, true)) $pdo->exec('ALTER TABLE dev_tokens ADD COLUMN rate_n INTEGER NOT NULL DEFAULT 0');
}
