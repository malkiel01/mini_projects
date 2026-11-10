<?php
/**
 * DevLoop — המטלות, האימות והיומן של המפתח. המנוע הוא המודול המשותף: lib/devloop.php הוא **עותק מסונכרן** מ-devloop/src
 * שבשורש הריפו, וכך גם assets/js/devloop.js ו-assets/css/devloop.css. לא עורכים אותם כאן — שער בפריסה עוצר עותק ששונה.
 *
 * כאן רק החיבור לאפליקציה: המסד, השגיאה, מי מפתח. היומן — המובנה של DevLoop (data/devloop/log.sqlite, 30 יום):
 * כל בקשה, כל שגיאת PHP וכל שגיאת JavaScript, עם מזהה בקשה. הצילומים שבשיח — data/devloop/ (מוחרגת מהפריסה).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/devloop.php';

devloop_setup([
    'db'           => fn() => apr_db(),
    'now'          => fn() => apr_now(),
    'items_file'   => dirname(__DIR__) . '/dev/open-items.json',
    'data_dir'     => apr_data_dir() . '/devloop',
    'prefix'       => 'AP-',
    'token_prefix' => 'apd_',
    'error'        => fn(string $msg, int $status, array $vars) => new AprError(
        strtr($msg, array_combine(array_map(fn($k) => '{' . $k . '}', array_keys($vars)), array_map('strval', $vars)) ?: []), $status),
    'user_names'   => fn() => array_column(apr_rows('SELECT id, display_name FROM users'), 'display_name', 'id'),
    'is_developer' => fn(int $id) => (bool)apr_row("SELECT 1 AS x FROM users WHERE id = ? AND status = 'active' AND is_developer = 1", [$id]),
    // פעולות המפתח על אסימונים נרשמות גם ביומן הפעולות של האפליקציה
    'audit'        => function (string $actor, string $action, string $kind = '', int $id = 0, array $data = []): void {
        $uid = (int)preg_replace('/\D/', '', $actor);
        apr_audit(apr_row('SELECT id, display_name FROM users WHERE id = ?', [$uid]), null, $action, "$kind:$id", $data);
    },
]);
