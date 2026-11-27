<?php
/**
 * DevLoop — המטלות, האימות והיומן הטכני של המפתח (אפיון 01 §8.1–8.2). המנוע: lib/devloop.php — **עותק מסונכרן**
 * מ-devloop/src שבשורש הריפו (וכך גם assets/js/devloop.js ו-assets/css/devloop.css). לא עורכים אותם כאן.
 *
 * כאן רק החיבור. היומן הטכני — המובנה של DevLoop (data/_dev/log.sqlite, 30 יום): כל בקשה (פעולה, מצב, משך, מי),
 * שגיאות PHP, חריגות, ושגיאות JavaScript. גוף הבקשה לא נרשם.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/devloop.php';

devloop_setup([
    'db'           => fn() => sf_db(),
    'now'          => fn() => sf_now(),
    'items_file'   => sf_root() . '/dev/open-items.json',
    'data_dir'     => sf_data_dir() . '/_dev',
    'prefix'       => 'SF-',
    'token_prefix' => 'sfd_',
    'error'        => fn(string $msg, int $status, array $vars) => new SfError('devloop',
        strtr($msg, array_combine(array_map(fn($k) => '{' . $k . '}', array_keys($vars)), array_map('strval', $vars)) ?: []), $status),
    'user_names'   => fn() => array_column(sf_rows('SELECT id, name FROM staff'), 'name', 'id'),
    'is_developer' => fn(int $id) => (bool)sf_val("SELECT 1 FROM staff WHERE id = ? AND status = 'active' AND is_developer = 1", [$id]),
    'audit'        => function (string $actor, string $action, string $kind = '', int $id = 0, array $data = []): void {
        $map = ['devtasks.token_create' => 'devtoken.create', 'devtasks.token_revoke' => 'devtoken.revoke'];
        if (!isset($map[$action])) return;
        sf_audit($map[$action], 'devtoken', null, ['ref' => $id], actor: (int)preg_replace('/\D/', '', $actor) ?: null);
    },
]);
