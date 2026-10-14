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
    'log'          => fn(string $level, string $cat, string $event, array $extra = []) => pm_log($level, $cat, $event, $extra),
    // בקשות באסימון מסומנות 🤖 ביומן
    'as_claude'    => fn(int $tokenId) => pm_log_user(['id' => null, 'username' => '🤖 devapi:' . $tokenId], true),
    // שגיאות ביומן (שרת ודפדפן) — הופכות למטלות "⚠️ לבירור"
    'errors_max'   => fn() => (int)(pm_log_db()?->query('SELECT COALESCE(MAX(id), 0) FROM log')->fetchColumn() ?: 0),
    'errors'       => function (int $after, int $limit): array {
        $log = pm_log_db();
        if (!$log) return [];
        $st = $log->prepare("SELECT * FROM log WHERE id > ? AND level = 'error' ORDER BY id LIMIT " . max(1, $limit));
        $st->execute([$after]);
        return array_map(fn($r) => $r + ['data' => json_decode((string)$r['data_json'], true) ?: []], $st->fetchAll());
    },
]);
