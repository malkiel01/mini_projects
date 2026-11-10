<?php
/**
 * DevLoop — המטלות, האימות והיומן הטכני של המפתח (אפיון 01 §9.1–9.2). המנוע הוא המודול המשותף: lib/devloop.php הוא
 * **עותק מסונכרן** מ-devloop/src שבשורש הריפו, וכך גם assets/js/devloop.js ו-assets/css/devloop.css. לא עורכים
 * אותם כאן — שער בפריסה עוצר עותק ששונה.
 *
 * כאן רק החיבור: המסד, השגיאה, מי מפתח, ויומן הפעולות. היומן הטכני — המובנה של DevLoop (data/_dev/log.sqlite,
 * 30 יום): כל בקשה (פעולה, מצב, משך, מי), שגיאות PHP, חריגות, ושגיאות JavaScript. גוף הבקשה לא נרשם.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/devloop.php';

devloop_setup([
    'db'           => fn() => apr_db(),
    'now'          => fn() => apr_now(),
    'items_file'   => dirname(__DIR__) . '/dev/open-items.json',
    'data_dir'     => apr_data_dir() . '/_dev',
    'prefix'       => 'AP-',
    'token_prefix' => 'apd_',
    'error'        => fn(string $msg, int $status, array $vars) => new AprError('devloop',
        strtr($msg, array_combine(array_map(fn($k) => '{' . $k . '}', array_keys($vars)), array_map('strval', $vars)) ?: []), $status),
    'user_names'   => fn() => array_column(apr_rows('SELECT id, name FROM users'), 'name', 'id'),
    'is_developer' => fn(int $id) => (bool)apr_val("SELECT 1 FROM users WHERE id = ? AND status = 'active' AND is_developer = 1", [$id]),
    'audit'        => function (string $actor, string $action, string $kind = '', int $id = 0, array $data = []): void {
        $map = ['devtasks.token_create' => 'devtoken.create', 'devtasks.token_revoke' => 'devtoken.revoke'];
        apr_audit($map[$action] ?? $action, 'devtoken', (string)$id, [], actor: (int)preg_replace('/\D/', '', $actor) ?: null);
    },
]);
