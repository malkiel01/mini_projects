<?php
/**
 * מטלות ושיח — אזור הפיתוח (הגדרות ← 📋 מטלות, רק למפתח). README: "מטלות ושיח" ו-"DevLoop".
 *
 * המנוע הוא המודול המשותף DevLoop (lib/devloop.php — עותק מסונכרן מ-devloop/src בשורש הריפו; לא עורכים כאן).
 * כאן רק החיבור שלו למנהל הפרויקטים: המסד, השגיאה, המשתמשים, יומן הביקורת, והיומן (לשגיאות "⚠️ לבירור").
 */

declare(strict_types=1);

require_once __DIR__ . '/devloop.php';

devloop_setup([
    'db'           => fn() => pm_db(),
    'now'          => fn() => pm_now(),
    'items_file'   => __DIR__ . '/../dev/open-items.json',
    'data_dir'     => __DIR__ . '/../data/files/_dev',
    'prefix'       => 'PM-',
    'token_prefix' => 'pmd_',
    'error'        => fn(string $msg, int $status, array $vars) => new PmError($msg, $status, $vars),
    'user_names'   => fn() => array_column(pm_db()->query('SELECT id, display_name FROM users')->fetchAll(), 'display_name', 'id'),
    'is_developer' => function (int $id): bool {
        $st = pm_db()->prepare("SELECT 1 FROM users WHERE id = ? AND status = 'active' AND role = 'developer'");
        $st->execute([$id]);
        return (bool)$st->fetchColumn();
    },
    'audit'        => fn(string $actor, string $action, string $kind, ?int $id, array $data = []) => pm_audit($actor, $action, $kind, $id, $data),
    // בקשות באסימון מסומנות 🤖 ביומן
    'as_claude'    => fn(int $tokenId) => pm_log_user(['id' => null, 'username' => '🤖 devapi:' . $tokenId], true),
    // התראות במייל למפתח: ממתינה לאימות, שאלה, Claude ענה, שגיאות חדשות (DevLoop שלב 4)
    'notify'       => function (string $subject, string $body): void {
        foreach (pm_db()->query("SELECT email FROM users WHERE role = 'developer' AND status = 'active' AND email != ''")->fetchAll(PDO::FETCH_COLUMN) as $to) {
            pm_send_mail($to, '[מנהל הפרויקטים] ' . $subject, $body); // i18n-none
        }
    },
    'item_url'     => fn(string $code) => pm_app_url() . '/#/settings/tasks/' . rawurlencode($code),
    // היומן של מנהל הפרויקטים (lib/log.php, 90 יום) — במקום היומן המובנה של DevLoop. ממנו: "⚠️ לבירור",
    // העקבות של 🐞, והקישור בשני הכיוונים בין מטלה לשורות ביומן
    'log' => [
        'write'  => fn(string $level, string $cat, string $event, array $extra = []) => pm_log($level, $cat, $event, $extra),
        'max_id' => fn() => (int)(pm_log_db()?->query('SELECT COALESCE(MAX(id), 0) FROM log')->fetchColumn() ?: 0),
        'rows'   => 'pm_devloop_log_rows',
    ],
]);

/** שורות מהיומן בשביל DevLoop — אותו חוזה כמו devloop_log_rows (after, before, level, ids, reqs, q, limit). */
function pm_devloop_log_rows(array $q): array
{
    $db = pm_log_db();
    if (!$db) return [];
    $w = ['1 = 1'];
    $a = [];
    if (!empty($q['after'])) { $w[] = 'id > ?'; $a[] = (int)$q['after']; }
    if (!empty($q['before'])) { $w[] = 'id < ?'; $a[] = (int)$q['before']; }
    if (($q['level'] ?? '') === 'error') $w[] = "level = 'error'";
    elseif (($q['level'] ?? '') === 'warn') $w[] = "level IN ('warn', 'error')";
    foreach (['ids' => 'id', 'reqs' => 'req'] as $k => $col) {
        if (!array_key_exists($k, $q)) continue;
        $vals = array_values(array_slice(array_filter((array)$q[$k], fn($v) => $v !== '' && $v !== null), 0, 200));
        if (!$vals) return [];
        $w[] = "$col IN (" . implode(',', array_fill(0, count($vals), '?')) . ')';
        array_push($a, ...$vals);
    }
    if (!empty($q['q'])) { $w[] = '(event LIKE ? OR message LIKE ? OR action LIKE ? OR data_json LIKE ?)'; $l = '%' . strtr((string)$q['q'], ['%' => '', '_' => '']) . '%'; array_push($a, $l, $l, $l, $l); }
    $limit = max(1, min(500, (int)($q['limit'] ?? 100)));
    $st = $db->prepare('SELECT * FROM log WHERE ' . implode(' AND ', $w) . ' ORDER BY id ' . (!empty($q['after']) ? 'ASC' : 'DESC') . " LIMIT $limit");
    $st->execute($a);
    return array_map(function ($r) {
        $r['id'] = (int)$r['id'];
        $r['data'] = json_decode((string)$r['data_json'], true) ?: [];
        unset($r['data_json']);
        return $r;
    }, $st->fetchAll());
}
