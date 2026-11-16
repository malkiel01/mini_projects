<?php
/**
 * DevLoop — המטלות, האימות והיומן המחובר של המפתח (PROTOCOL 7, פרוסה A1). המנוע הוא המודול המשותף: lib/devloop.php
 * הוא **עותק מסונכרן** מ-devloop/src שבשורש הריפו, וכך גם assets/js/devloop.js ו-assets/css/devloop.css. לא עורכים
 * אותם כאן — עורכים במקור ומריצים php devloop/tools/sync.php; שער בפריסה עוצר עותק ששונה.
 *
 * כאן רק החיבור:
 *   המפתח     — המנהל הראשי בלבד (platform_admin מהשער). בבקשה עם משתמש — ts_is_primary_admin(); לאסימון של Claude
 *               (שמגיע בלי משתמש) — users.platform_admin, שמתעדכן בכל כניסה (מיגרציה 13). אסימון חי רק כל עוד מי
 *               שיצר אותו עדיין המנהל הראשי.
 *   המטלות    — dev/open-items.json (מה ש-Claude מנהל בקוד) + הטבלאות של המודול במסד של האפליקציה (data/app.sqlite).
 *   הנתונים   — data/_dev: צילומים ו-log.sqlite (היומן המובנה, 30 יום). תחת data/, שמוחרג מ-rsync בפריסה.
 *   היומן     — המובנה של DevLoop: כל בקשה (פעולה, מצב, משך, מי), שגיאות PHP, חריגות, ושגיאות JavaScript. **גוף הבקשה
 *               לא נרשם.**
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/auth.php';   // מנותקת: ts_identity, ts_platform_mode, ts_grant_status (is_developer)
require_once __DIR__ . '/devloop.php';

/** שגיאה של המודול — ההודעה בעברית, והקוד הוא מצב ה-HTTP. RuntimeException: ביומן רק כהודעת הבקשה, לא כחריגה. */
final class TsDevError extends RuntimeException {}

/** התיקייה של DevLoop — תחת data/ (מוחרגת מ-rsync). בבדיקות — ליד המסד הזמני. */
function ts_dev_dir(): string
{
    return defined('TS_DB_PATH') ? TS_DB_PATH . '.dev' : dirname(__DIR__) . '/data/_dev';
}

devloop_setup([
    'db'           => fn() => ts_db(),
    'items_file'   => dirname(__DIR__) . '/dev/open-items.json',
    'data_dir'     => ts_dev_dir(),
    'prefix'       => 'TS-',
    'token_prefix' => 'tsd_',
    'error'        => fn(string $msg, int $status, array $vars) => new TsDevError(
        strtr($msg, array_combine(array_map(fn($k) => '{' . $k . '}', array_keys($vars)), array_map('strval', $vars)) ?: []), $status),
    'user_names'   => fn() => array_column(ts_db()->query('SELECT id, display_name FROM users')->fetchAll(), 'display_name', 'id'),
    'is_developer' => function (int $id): bool {
        $st = ts_db()->prepare('SELECT platform_sub FROM users WHERE id = ? AND platform_admin = 1');
        $st->execute([$id]);
        $sub = $st->fetchColumn();
        if ($sub === false) return false;
        // מחוברת — השער כבר אכף את ההרשאה. אחרת (מנותקת / unknown) — גם grant_status (20.1 ו', ביקורת A2 🟡2)
        return ts_identity() !== null || ts_platform_mode() === 'connected' || ts_dev_granted((int)$id, (string)$sub);
    },
    'audit'        => function (string $actor, string $action, string $kind = '', int $id = 0, array $data = []): void {
        $uid = (int)preg_replace('/\D/', '', $actor);
        if ($uid) ts_admin_log($uid, $action, "$kind:$id");
    },
]);

/**
 * המפתח במנותקת: grant_status, שמור 5 דקות (settings). רק true נשמר; false או אין תשובה — לא מפתח (נכשלים סגור).
 */
function ts_dev_granted(int $uid, string $sub): bool
{
    $key = 'dev_grant:' . $uid;
    if ((int)ts_setting($key, '0') > time() - TS_GRANT_TTL) return true;
    if (ts_grant_status($sub) !== true) return false;
    ts_setting_set($key, (string)time());
    return true;
}

/** פעולות של DevLoop בעוגייה (למפתח). devloop-client-error — לכל משתמש מחובר: שגיאות JavaScript ליומן. */
const TS_DEV_ACTIONS = ['dev-items', 'dev-task-create', 'dev-task-set', 'dev-comment', 'dev-comment-delete', 'dev-verify', 'dev-report',
    'dev-attach', 'dev-file', 'dev-tokens', 'dev-token-create', 'dev-token-revoke', 'dev-item-log', 'dev-log-links', 'dev-task-from-log',
    'dev-log', 'devloop-client-error'];
/** מהן — POST, גוף JSON ו-X-CSRF. dev-attach — POST ו-X-CSRF, והגוף הוא הקובץ עצמו (המודול קורא אותו). */
const TS_DEV_WRITE = ['dev-task-create', 'dev-task-set', 'dev-comment', 'dev-comment-delete', 'dev-verify', 'dev-report',
    'dev-token-create', 'dev-token-revoke', 'dev-log-links', 'dev-task-from-log', 'devloop-client-error'];
/** באסימון (X-Dev-Token), בלי עוגייה ובלי משתמש — ב-"public" שב-app.json. */
const TS_DEVAPI_ACTIONS = ['devapi-feed', 'devapi-comment', 'devapi-file', 'devapi-attach'];

/** מה ש-Claude קורא וכותב באסימון. devapi-file יוצא כקובץ (exit) ולא חוזר. */
function ts_devapi_dispatch(string $action, array $get, array $in): array
{
    return match ($action) {
        'devapi-feed'    => devloop_api_feed(devloop_api_auth(), $get),
        'devapi-comment' => devloop_api_comment(devloop_api_auth(), $in),
        'devapi-file'    => (devloop_api_auth() and devloop_file_out((int)($get['id'] ?? 0))),
        'devapi-attach'  => devloop_attach('claude', $get + ['_auth' => devloop_api_auth()]),
    };
}

/** הפעולות של המפתח. $user — שורת המשתמש (id). המנהל הראשי כבר נבדק ב-api.php. */
function ts_dev_dispatch(string $action, array $user, array $get, array $in): array
{
    return match ($action) {
        'devloop-client-error' => devloop_log_client(array_intersect_key($in, array_flip(['kind', 'message', 'source', 'line', 'col', 'stack', 'page']))),
        'dev-items'          => devloop_task_list(),
        'dev-task-create'    => devloop_task_create($user, $in),
        'dev-task-set'       => devloop_task_set($user, $in),
        'dev-comment'        => devloop_comment_add((string)($in['code'] ?? ''), 'user', (int)$user['id'], (string)($in['body'] ?? '')),
        'dev-comment-delete' => devloop_comment_delete($user, $in),
        'dev-verify'         => devloop_verify($user, $in),
        'dev-report'         => devloop_report($user, $in),
        'dev-attach'         => devloop_attach('user:' . $user['id'], $get),
        'dev-file'           => devloop_file_out((int)($get['id'] ?? 0)),
        'dev-tokens'         => devloop_token_list(),
        'dev-token-create'   => devloop_token_create($user, $in),
        'dev-token-revoke'   => devloop_token_revoke($user, $in),
        'dev-item-log'       => devloop_item_log((string)($get['code'] ?? '')),
        'dev-log-links'      => devloop_log_links((array)($in['ids'] ?? []), (array)($in['reqs'] ?? [])),
        'dev-task-from-log'  => devloop_task_from_log($user, $in),
        'dev-log'            => devloop_log_view($get),
    };
}

/** הגרסה מ-app.json — להקשר של 🐞 ולשורת "me". */
function ts_app_version(): string
{
    static $v = null;
    return $v ??= (string)((json_decode((string)@file_get_contents(dirname(__DIR__) . '/app.json'), true) ?: [])['version'] ?? '');
}
