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
    // לוג המעקב → "⚠️ לבירור": שגיאות שרת (5xx) ושגיאות JavaScript מהדפדפן. 4xx — סירוב רגיל, לא באג.
    'errors_max'   => fn() => (int)db()->query('SELECT COALESCE(MAX(id), 0) FROM dev_log')->fetchColumn(),
    'errors'       => function (int $after, int $limit): array {
        $st = db()->prepare("SELECT * FROM dev_log WHERE id > ? AND (status >= 500 OR action = 'client_error') ORDER BY id LIMIT " . max(1, $limit));
        $st->execute([$after]);
        return array_map('dt_log_as_error', $st->fetchAll());
    },
]);

/** שורת dev_log במבנה שהמודול מצפה לו (id, at, category, event, message, action, method, status, ms, req, ua, data). */
function dt_log_as_error(array $r): array
{
    $js = $r['action'] === 'client_error';
    return ['id' => (int)$r['id'], 'at' => $r['at'], 'category' => $js ? 'client' : 'server', 'event' => $js ? 'js.error' : 'http.5xx',
        'message' => (string)$r['message'], 'action' => $js ? '' : (string)$r['action'], 'method' => (string)$r['method'],
        'status' => (int)$r['status'], 'ms' => (int)$r['duration_ms'], 'req' => (string)($r['req'] ?? ''), 'ua' => (string)($r['ua'] ?? ''),
        'data' => array_filter(['page' => $r['page'], 'trace' => $r['trace'] ? [$r['trace']] : null])];
}

/**
 * לאיזו מטלת "⚠️ לבירור" שייכת שגיאה בלוג — אותה חתימה כמו ב-devloop_errors_sync (קטגוריה, אירוע, פעולה,
 * והודעה בלי מספרים ומחרוזות). כך מהלוג קופצים למטלה, ומהמטלה (מזהה הבקשה) — ללוג.
 */
function dt_log_task_codes(array $rows): array
{
    $sigs = [];
    foreach ($rows as $r) {
        if (!((int)$r['status'] >= 500 || $r['action'] === 'client_error')) continue;
        $e = dt_log_as_error($r);
        $norm = preg_replace(['/\b[0-9a-f]{8,}\b/i', '/\d+/', '/"[^"]*"/u', "/'[^']*'/u"], ['#', '#', '"…"', "'…'"], $e['message']);
        $sigs[(int)$r['id']] = substr(hash('sha1', $e['category'] . '|' . $e['event'] . '|' . $e['action'] . '|' . mb_substr($norm, 0, 200)), 0, 16);
    }
    if (!$sigs) return [];
    devloop_migrate();
    $st = db()->prepare('SELECT sig, code FROM dev_err_groups WHERE sig IN (' . implode(',', array_fill(0, count(array_unique($sigs)), '?')) . ')');
    $st->execute(array_values(array_unique($sigs)));
    $bySig = array_column($st->fetchAll(), 'code', 'sig');
    $out = [];
    foreach ($sigs as $id => $sig) if (isset($bySig[$sig])) $out[$id] = $bySig[$sig];
    return $out;
}

/** טבלאות שנוצרו לפני המודול — מה שחסר בהן (הגבלת קצב לאסימון). */
function devtasks_migrate(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(dev_tokens)')->fetchAll(), 'name');
    if (!$cols) return;   // עוד אין טבלה — המודול ייצור אותה מלאה
    if (!in_array('rate_hour', $cols, true)) $pdo->exec("ALTER TABLE dev_tokens ADD COLUMN rate_hour TEXT NOT NULL DEFAULT ''");
    if (!in_array('rate_n', $cols, true)) $pdo->exec('ALTER TABLE dev_tokens ADD COLUMN rate_n INTEGER NOT NULL DEFAULT 0');
}
