<?php
/**
 * שלב 6 (docs/spec.md §7) — מסלול אחד: ירושה, ערוץ גובר, claim מסנן לפי
 * X-Worker-Route, מטלה שנלקחה נשארת במסלול שלה, ו-code = chat.
 *
 *   php claude-tasks/tests/routes.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/ctr-' . getmypid();
@mkdir($tmp, 0777, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('CONFIG_FILE', $tmp . '/c.json');
define('SECRET_KEY_FILE', $tmp . '/secret.key');
define('BELL_DIR', $tmp . '/bell');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/projects.php';
require_once __DIR__ . '/../lib/channels.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}
function throws(string $name, callable $fn, int $status = 0) {
    try { $fn(); check($name, 'לא נזרקה חריגה', 'חריגה'); }
    catch (AppError $e) { check($name . ' — ' . $e->getMessage(), $status === 0 || $e->status === $status, true); }
    catch (Throwable $e) { check($name . ' — ' . $e->getMessage(), $status === 0, true); }
}
function task(string $title, ?int $project): int {
    db()->prepare("INSERT INTO tasks (title, project_id, status, created_by, created_at, updated_at) VALUES (?,?, 'open', 1,?,?)")
        ->execute([$title, $project, nowTs(), nowTs()]);
    return (int) db()->lastInsertId();
}
function route(int $id, string $r): void { db()->prepare('UPDATE projects SET route = ? WHERE id = ?')->execute([$r, $id]); }
function hdr(?string $v): string {
    if ($v === null) unset($_SERVER['HTTP_X_WORKER_ROUTE']); else $_SERVER['HTTP_X_WORKER_ROUTE'] = $v;
    return workerRoute();
}

createUser('boss', 'pass-12345', 'בעל הלוח');
createUser('dana', 'pass-12345', 'דנה');
$boss = ['id' => 1, 'role' => 'admin', 'username' => 'boss', 'display_name' => 'בעל הלוח'];
$dana = ['id' => 2, 'role' => 'member', 'username' => 'dana', 'display_name' => 'דנה'];
$root  = createProject($boss, 'mini_projects', 'malkiel01', 'mini_projects');
$suite = createSubproject($boss, $root, 'חבילת היצירה', 'creative-suite');
$raster = createSubproject($boss, $suite, 'רסטר', 'creative-suite/raster');
$app   = createSubproject($boss, $root, 'מתכונים', 'recipes-app');
setMember($boss, $root, 2, 'admin');

echo "\n— מכסה (שלב 7) —\n";
setDefaultRoute($boss, 'platform');
check('בלי מכסה — ☁️ כבוי: נחשב chat', [projectRoute($root), projectRoutes(true)[$root]], ['chat', 'platform']);
setDefaultRoute($boss, 'chat');
setQuota($boss, 1, 25);   // מכאן — לבעל הלוח יש מכסה, ו-☁️ פועל

echo "\n— ירושה —\n";
check('בלי כלום — chat (ברירת המחדל)', [projectRoute($root), projectRoute($raster)], ['chat', 'chat']);
setDefaultRoute($boss, 'platform');
check('ברירת המחדל של הלוח חלה על השורש ועל כל מי שיורש', [projectRoute($root), projectRoute($raster)], ['platform', 'platform']);
setDefaultRoute($boss, 'chat');
route($suite, 'platform');
check('ענף שבחר platform — הוא וכל מה שמתחתיו', [projectRoute($suite), projectRoute($raster), projectRoute($app), projectRoute($root)],
      ['platform', 'platform', 'chat', 'chat']);
route($raster, 'chat');
check('תת-פרויקט שבחר במפורש — גובר על האב', projectRoute($raster), 'chat');
route($raster, '');
check('ריק — חזרה לירושה', projectRoute($raster), 'platform');
check('מטלה בלי פרויקט — chat', taskRoute(['status' => 'open', 'project_id' => null, 'route' => '']), 'chat');
$byId = array_column(listProjects($boss), null, 'id');
check('projects מחזיר route ו-route_effective', [$byId[$raster]['route'], $byId[$raster]['route_effective']], ['', 'platform']);

echo "\n— ערוץ גובר —\n";
$regToken = bin2hex(random_bytes(16));
db()->prepare('UPDATE projects SET agent_token = ? WHERE id = ?')->execute([encryptSecret($regToken), $root]);
$_SERVER['HTTP_X_WORKER_TOKEN'] = $regToken;
$reg = registerChannel(workerScope(), $raster, 'רסטר', 'קוד', 'session_ROUTE1', '');
$ch = getChannel($reg['channel_id']);
check('ערוץ בלי בחירה — לפי הפרויקט', channelRoute($ch), 'platform');
db()->prepare("UPDATE channels SET route = 'chat' WHERE id = ?")->execute([$ch['id']]);
check('ערוץ שבחר chat — גובר על הפרויקט', channelRoute(getChannel($ch['id'])), 'chat');
check('channels מחזיר route_effective', channelsFor($boss, $raster)[0]['route_effective'], 'chat');

echo "\n— X-Worker-Route —\n";
check('בלי כותרת — chat', hdr(null), 'chat');
check('code — השם הישן של chat', hdr('code'), 'chat');
check('Platform באותיות גדולות', hdr('Platform'), 'platform');
throws('מסלול לא מוכר — נדחה', fn () => hdr('robot'));
hdr(null);

echo "\n— claim מסנן —\n";
$tRoot  = task('בשורש', $root);
$tSuite = task('בחבילה', $raster);
$tNone  = task('בלי פרויקט', null);
$ids = [];
while ($t = claimNextTask(db(), 'chat-worker', null, null, [], 'chat')) $ids[] = (int) $t['id'];
sort($ids);
check('chat — רק מה שבמסלול chat (כולל בלי פרויקט)', $ids, [$tRoot, $tNone]);
check('…והמטלות זוכרות את המסלול שבו נלקחו', db()->query("SELECT route FROM tasks WHERE id = $tRoot")->fetchColumn(), 'chat');
check('platform — רק מה שבענף platform', (int) (claimNextTask(db(), 'p', null, null, [], 'platform')['id'] ?? 0), $tSuite);
check('…ואחריו כלום', claimNextTask(db(), 'p', null, null, [], 'platform'), null);
check('אסימון פרויקט + מסלול — שני המסננים יחד', claimNextTask(db(), 'x', null, projectSubtree($suite), [], 'chat'), null);

echo "\n— החלפה חלה על עבודה חדשה בלבד —\n";
route($root, 'platform');
$t = getTask(db(), $tRoot);
check('in_progress שנלקחה ב-chat — נשארת chat אחרי שהענף עבר ל-platform', [$t['status'], taskRoute($t)], ['in_progress', 'chat']);
db()->prepare("UPDATE tasks SET status = 'open', claimed_by = '' WHERE id = ?")->execute([$tRoot]);
check('…וכשהיא חוזרת לתור — לפי המסלול החדש', taskRoute(getTask(db(), $tRoot)), 'platform');
check('…ולכן chat כבר לא מקבל אותה', claimNextTask(db(), 'chat-worker', null, null, [], 'chat'), null);

echo "\n— מי מחליט —\n";
throws('מנהל פרויקט (לא בעל הלוח) — לא משנה מסלול', fn () => setProjectRoute($dana, $root, 'chat'), 403);
throws('ברירת המחדל — בעל הלוח בלבד', fn () => setDefaultRoute($dana, 'chat'), 403);
throws('מסלול לא מוכר בפרויקט — נדחה', fn () => setProjectRoute($boss, $root, 'robot'));
setProjectRoute($boss, $root, 'code');
check('code נשמר כ-chat', db()->query("SELECT route FROM projects WHERE id = $root")->fetchColumn(), 'chat');
check('ברירת המחדל נשמרת בקונפיג', [setDefaultRoute($boss, 'platform'), config()['default_route']], ['platform', 'platform']);

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
