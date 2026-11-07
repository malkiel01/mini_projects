<?php
/**
 * 🛠️ דשבורד פיתוח של אפליקציה — הדף וה-API שלו (lib/devboard.php).
 *
 *   GET  devboard.php?app=<id>                    הדף
 *   GET  devboard.php?app=<id>&action=dev-items   (וכל פעולות DevLoop) — מפתח או עוקב, בעוגיית הפלטפורמה
 *   POST devboard.php?app=<id>&action=dev-…       + X-CSRF; JSON עם X-Platform (חוץ מ-dev-attach, שגופו הקובץ)
 *   *    devboard.php?app=<id>&action=devapi-…    ל-Claude של האפליקציה, באסימון (X-Dev-Token) — devloop/README.md
 *
 * הקובץ יושב בשורש platform/ (ולא בתת-תיקייה) — עוגיית הכניסה מתחדשת לפי עומק הסקריפט (lib/auth.php, cookie_path).
 */

declare(strict_types=1);

require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/access.php';
require __DIR__ . '/lib/devboard.php';

/** מה עוקב רשאי. כל השאר — למפתח בלבד. */
const DEVBOARD_FOLLOWER = ['GET dev-items', 'GET dev-file', 'POST dev-comment', 'POST dev-comment-delete', 'POST dev-attach',
                           'POST dev-task-create', 'POST dev-report'];

$appId = is_string($_GET['app'] ?? null) && preg_match('/^[a-z0-9][a-z0-9-]{0,60}$/', $_GET['app']) ? $_GET['app'] : '';
$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($action === '') devboard_page($appId);

try {
    if ($appId === '') throw new ApiError('חסרה אפליקציה', 400);
    devboard_setup($appId);

    // Claude של האפליקציה — באסימון שהמפתח יצר בדשבורד (🔑). לא עוגייה.
    if (str_starts_with($action, 'devapi-')) {
        $body = $method === 'POST' && $action !== 'devapi-attach' ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : [];
        json_out(match ("$method $action") {
            'GET devapi-feed'     => devloop_api_feed(devloop_api_auth(), $_GET),
            'POST devapi-comment' => devloop_api_comment(devloop_api_auth(), $body),
            'GET devapi-file'     => (devloop_api_auth() and devloop_file_out((int)($_GET['id'] ?? 0))),
            'POST devapi-attach'  => devloop_attach('claude', $_GET + ['_auth' => devloop_api_auth()]),
            default => throw new ApiError('פעולה לא מוכרת', 404),
        });
    }

    $user = current_user();
    if (!$user) throw new ApiError('נדרשת כניסה', 401);
    $role = devboard_role($user, $appId) ?? throw new ApiError('אין לך גישה לדשבורד הזה', 403);
    $key = "$method $action";
    if ($role !== 'dev' && !in_array($key, DEVBOARD_FOLLOWER, true)) throw new ApiError('למפתח בלבד', 403);

    $body = [];
    if ($method === 'POST') {
        if (!hash_equals((string)$user['csrf'], $_SERVER['HTTP_X_CSRF'] ?? '')) throw new ApiError('בקשה לא תקינה — רעננו את הדף', 403);
        if ($action !== 'dev-attach') $body = json_body();
    }
    $me = ['id' => (int)$user['id']];

    // עוקב: מטלה שהמפתח הסתיר ממנו — כאילו אינה (ברשימה, בשיח, בצירוף ובקבצים)
    $hidden = $role === 'follower' ? devboard_hidden_for($appId, $me['id']) : [];
    if ($hidden) {
        $code = (string)($body['code'] ?? $_GET['code'] ?? '');
        if ($action === 'dev-file') {
            $st = devloop_db()->prepare('SELECT code FROM dev_files WHERE id = ?');
            $st->execute([(int)($_GET['id'] ?? 0)]);
            $code = (string)$st->fetchColumn();
        }
        if ($action === 'dev-comment-delete') {
            $st = devloop_db()->prepare('SELECT code FROM dev_comments WHERE id = ?');
            $st->execute([(int)($body['id'] ?? 0)]);
            $code = (string)$st->fetchColumn();
        }
        if (in_array($code, $hidden, true)) throw new ApiError('לא נמצא', 404);
    }

    // עוקב: מה שהוא פותח הוא המלצה (או 🐞 דיווח) — סוג ועדיפות קבועים; המפתח מחליט מה הלאה
    if ($role === 'follower' && $action === 'dev-task-create') {
        $body['kind'] = 'feature'; $body['priority'] = 'normal'; $body['area'] = '💡 המלצה';
        unset($body['steps']);
    }
    if ($role === 'follower' && $action === 'dev-report') { $body['priority'] = 'normal'; unset($body['steps']); }

    $out = match ($key) {
        'GET dev-items'            => (function () use ($role, $hidden) {
            $l = devloop_task_list();
            $l['items'] = array_values(array_filter($l['items'], fn($x) => !in_array($x['code'], $hidden, true)));
            return ['ok' => true, 'role' => $role] + $l;
        })(),
        'GET db-users'             => ['ok' => true] + devboard_users($appId),
        'GET db-logs'              => ['ok' => true] + devboard_logs($appId, $_GET),
        'POST db-hide'             => devboard_hide($appId, $body),
        'GET dev-file'             => devloop_file_out((int)($_GET['id'] ?? 0)),
        'GET dev-item-log'         => ['ok' => true] + devloop_item_log((string)($_GET['code'] ?? '')),
        'GET dev-tokens'           => ['ok' => true] + devloop_token_list(),
        'GET dev-log'              => ['ok' => true] + devloop_log_view($_GET),
        'POST dev-task-create'     => devloop_task_create($me, $body),
        'POST dev-task-set'        => devloop_task_set($me, $body),
        'POST dev-comment'         => devloop_comment_add((string)($body['code'] ?? ''), 'user', $me['id'], (string)($body['body'] ?? '')),
        'POST dev-comment-delete'  => devloop_comment_delete($me, $body),
        'POST dev-verify'          => devloop_verify($me, $body),
        'POST dev-report'          => devloop_report($me, $body),
        'POST dev-attach'          => devloop_attach('user:' . $me['id'], $_GET),
        'POST dev-log-links'       => ['ok' => true] + devloop_log_links((array)($body['ids'] ?? []), (array)($body['reqs'] ?? [])),
        'POST dev-task-from-log'   => devloop_task_from_log($me, $body),
        'POST dev-token-create'    => devloop_token_create($me, $body),
        'POST dev-token-revoke'    => devloop_token_revoke($me, $body),
        default => throw new ApiError('פעולה לא מוכרת', 404),
    };
    if ($role === 'follower') {
        $what = ['dev-comment' => 'הערה חדשה', 'dev-task-create' => '💡 המלצה חדשה', 'dev-report' => '🐞 דיווח חדש'][$action] ?? '';
        if ($what !== '') devboard_push_follower($appId, $user, (string)($out['code'] ?? $body['code'] ?? ''), $what);
    }
    json_out($out);
} catch (ApiError $e) {
    json_out(['error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log('devboard: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_out(['error' => 'שגיאה בשרת'], 500);
}

/** הדף. בלי הרשאה — הסבר קצר וקישור לכניסה, בלי לחשוף אם יש דשבורד. */
function devboard_page(string $appId): never
{
    $user = current_user();
    $role = $appId !== '' ? devboard_role($user, $appId) : null;
    $app = $role ? (array_column(apps_effective(), null, 'id')[$appId] ?? null) : null;
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $v = fn($f) => @filemtime(__DIR__ . '/assets/' . $f) ?: 0;
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    $cfg = $app ? json_encode(['app' => $appId, 'name' => $app['name'], 'role' => $role, 'csrf' => $user['csrf']],
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : '';
    ?><!doctype html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title><?= $app ? '🛠️ ' . $e($app['name']) . ' — פיתוח' : 'דשבורד פיתוח' ?></title>
    <link rel="icon" href="assets/icons/favicon.png">
    <link rel="stylesheet" href="assets/app.css?v=<?= $v('app.css') ?>">
    <link rel="stylesheet" href="assets/devloop.css?v=<?= $v('devloop.css') ?>">
    <link rel="stylesheet" href="assets/devboard.css?v=<?= $v('devboard.css') ?>">
</head>
<body class="devboard">
<header class="db-head">
    <a class="icon-btn" href="../" data-home aria-label="חזרה">→</a>
    <div class="db-title">
        <?php if ($app): ?><span class="db-ic" aria-hidden="true"><?= $e($app['icon']) ?></span><?php endif ?>
        <h1><?= $app ? $e($app['name']) : 'דשבורד פיתוח' ?> <small>🛠️ פיתוח</small></h1>
    </div>
    <?php if ($role): ?><span class="chip <?= $role === 'dev' ? 'brand' : '' ?>"><?= $role === 'dev' ? 'מפתח' : '👁️ עוקב' ?></span><?php endif ?>
</header>
<main class="db-main">
<?php if ($app): ?>
    <p class="hint db-legend"><?= $role === 'dev'
        ? 'כל המטלות של האפליקציה — מה נעשה, מה בעבודה ומה מתוכנן. העוקבים רואים הכול, מעירים ופותחים 💡 המלצות; רק אתה משנה מצב.'
        : 'מה כבר פותח, מה בעבודה ומה מתוכנן. פותחים מטלה כדי להעיר או לצרף צילום מסך, או "💡 המלצה חדשה" לרעיון.' ?></p>
    <?php if ($role === 'dev'): ?>
    <nav class="db-tabs" role="tablist" aria-label="רמות">
        <button type="button" role="tab" data-tab="board" aria-selected="true">📋 משימות</button>
        <button type="button" role="tab" data-tab="users" aria-selected="false">👥 משתמשים</button>
        <button type="button" role="tab" data-tab="logs" aria-selected="false">📜 לוגים</button>
    </nav>
    <section id="users" hidden></section>
    <section id="logs" hidden></section>
    <?php endif ?>
    <section id="board" aria-live="polite"></section>
    <script id="db-cfg" type="application/json"><?= $cfg ?></script>
    <script src="assets/devloop.js?v=<?= $v('devloop.js') ?>"></script>
    <script src="assets/devboard.js?v=<?= $v('devboard.js') ?>"></script>
<?php else: ?>
    <div class="db-denied">
        <p><?= $user ? 'אין לך גישה לדשבורד הזה. המנהל מוסיף עוקבים לכל אפליקציה.' : 'צריך להתחבר כדי לראות את הדשבורד.' ?></p>
        <a class="btn primary" href="../" data-home><?= $user ? 'לכלים הדיגיטליים' : 'להתחברות' ?></a>
    </div>
<?php endif ?>
</main>
<script>
// חזרה: באפליקציה המותקנת הבית הוא platform/home/ — שורש האתר שם נחשב "התקנה ישנה" ומציג "צריך להתקין
// מחדש" (app.js, oldInstall). כשבאנו מהבית — אחורה בהיסטוריה, בלי טעינה מחדש.
(() => {
    const standalone = matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
    const home = standalone ? 'home/' : '../';
    for (const a of document.querySelectorAll('[data-home]')) {
        a.href = home;
        a.addEventListener('click', e => {
            let ref = null; try { ref = document.referrer && new URL(document.referrer); } catch { /* */ }
            if (ref && ref.origin === location.origin && history.length > 1 && !/devboard\.php/.test(ref.pathname)) { e.preventDefault(); history.back(); }
        });
    }
})();
</script>
</body>
</html>
<?php
    exit;
}
