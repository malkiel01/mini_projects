<?php
/**
 * שלב 10 (docs/spec.md §11) — מנוע Claude Code: בחירה וירושה, התקנה (קבצים
 * וסודות), הפעלה ל-workflow הנכון, "רק PR" בקובץ עצמו, והעלות במכסה של מי שהריץ.
 *
 *   php claude-tasks/tests/engine.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/cteng-' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp));
@mkdir($tmp, 0777, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('CONFIG_FILE', $tmp . '/c.json');
define('SECRET_KEY_FILE', $tmp . '/secret.key');
define('BELL_DIR', $tmp . '/bell');
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = 'board.example';
$_SERVER['SCRIPT_NAME'] = '/claude-tasks/api.php';

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/projects.php';
require_once __DIR__ . '/../lib/engine.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}
function throws(string $name, callable $fn, string $needle = '') {
    try { $fn(); check($name, 'לא נזרקה חריגה', 'חריגה'); }
    catch (Throwable $e) { check($name . ' — ' . $e->getMessage(), $needle === '' || str_contains($e->getMessage(), $needle), true); }
}

createUser('boss', 'pass-12345', 'בעל הלוח');
$boss = ['id' => 1, 'role' => 'admin', 'username' => 'boss', 'display_name' => 'בעל הלוח'];
addConnection($boss, 'github', 'ghp_tokentokentoken', '', '', ['login' => 'malkiel01', 'scopes' => ['repo', 'workflow']]);
$root  = createProject($boss, 'mini_projects', 'malkiel01', 'mini_projects');
$suite = createSubproject($boss, $root, 'חבילת היצירה', 'creative-suite');
$P = fn (int $id) => db()->query("SELECT * FROM projects WHERE id = $id")->fetch();

echo "\n— בחירה וירושה —\n";
check('ברירת מחדל — loop', [projectEngine($P($root)), projectEngine($P($suite))], ['loop', 'loop']);
db()->prepare('UPDATE projects SET engine_at = 123 WHERE id = ?')->execute([$root]);
check('בחירה בשורש — עוברת לתת-פרויקט', [setProjectEngine($boss, $P($root), 'claude-code'), projectEngine($P($suite))], ['claude-code', 'claude-code']);
check('החלפת מנוע — צריך להתקין מחדש', (int) $P($root)['engine_at'], 0);
setProjectEngine($boss, $P($suite), 'loop');
check('תת-פרויקט שבחר — גובר', [projectEngine($P($suite)), projectEngine($P($root))], ['loop', 'claude-code']);
throws('מנוע לא מוכר — נדחה', fn () => setProjectEngine($boss, $P($root), 'robot'));

echo "\n— התקנה —\n";
$calls = [];
$gh = function ($method, $url, $json, $token) use (&$calls) {
    $calls[] = ['method' => $method, 'url' => $url, 'body' => json_decode((string) $json, true)];
    if (str_contains($url, '/actions/secrets/public-key')) {
        return ['status' => 200, 'headers' => [], 'raw' => '', 'data' => ['key' => base64_encode(str_repeat('K', 32)), 'key_id' => '1']];
    }
    if (str_contains($url, '/actions/secrets/')) return ['status' => 201, 'data' => [], 'headers' => [], 'raw' => ''];
    if ($method === 'GET') return ['status' => 404, 'data' => ['message' => 'Not Found'], 'headers' => [], 'raw' => ''];
    if ($method === 'PUT') return ['status' => 201, 'headers' => [], 'raw' => '', 'data' => ['content' => ['sha' => 'n'], 'commit' => ['sha' => 'c']]];
    return ['status' => 204, 'data' => [], 'headers' => [], 'raw' => ''];
};
$anthropic = ['provider' => 'anthropic', 'key' => 'sk-ant-keykeykeykeykeykey', 'model' => 'claude-opus-5-5'];
throws('Claude Code בלי Anthropic — נעצר', fn () => installEngine($boss, $P($root), ['provider' => 'openai', 'key' => 'sk-x', 'model' => 'gpt-5'], $gh), 'Anthropic');
$calls = [];
$res = installEngine($boss, $P($root), $anthropic, $gh);
check('הותקנו קובצי Claude Code (ולא run.mjs) — ושער המדיניות', array_keys($res['files']),
      ['.github/workflows/claude-code-agent.yml', '.github/claude-agent/finish.mjs', '.github/claude-agent/hook-gate.sh',
       '.github/claude-agent/lib.sh', '.github/claude-agent/classify.php']);
$secrets = array_values(array_map(fn ($c) => basename(parse_url($c['url'], PHP_URL_PATH)),
    array_filter($calls, fn ($c) => $c['method'] === 'PUT' && str_contains($c['url'], '/actions/secrets/'))));
check('סודות: AGENT_BOARD_TOKEN ו-ANTHROPIC_API_KEY', $secrets, ['AGENT_BOARD_TOKEN', 'ANTHROPIC_API_KEY']);
check('המפתח לא נשלח גולמי', str_contains(json_encode($calls), 'sk-ant-keykey'), false);
check('loop בתת-פרויקט — עדיין run.mjs', array_keys(installEngine($boss, $P($suite), $anthropic, $gh)['files']),
      ['.github/workflows/claude-agent.yml', '.github/claude-agent/run.mjs']);

echo "\n— רק PR, לעולם לא פריסה — בקובץ עצמו —\n";
$yml = (string) file_get_contents(__DIR__ . '/../agent/claude-code.yml');
check('הרשאות: contents + pull-requests, בלי actions', [str_contains($yml, 'contents: write'), str_contains($yml, 'pull-requests: write'),
      (bool) preg_match('/^\s*actions:/m', $yml)], [true, true, false]);
check('מיזוג והפעלת workflows — חסומים ל-Claude', [str_contains($yml, 'Bash(gh pr merge:*)'), str_contains($yml, 'Bash(gh workflow:*)')], [true, true]);
check('hook שחוסם push לענף הבסיס', str_contains($yml, '.git/hooks/pre-push'), true);
check('route platform מול הלוח', str_contains($yml, 'X-Worker-Route: platform'), true);
check('דיווח העלות רץ תמיד (גם בכישלון)', (bool) preg_match('/if: always\(\)\s*\n\s*run: node \.github\/claude-agent\/finish\.mjs/', $yml), true);
exec('node --check ' . escapeshellarg(__DIR__ . '/../agent/finish.mjs') . ' 2>&1', $o, $rc);
check('finish.mjs — תחביר תקין', $rc, 0);

echo "\n— הפעלה —\n";
$sent = null;
$dispatch = function ($method, $url, $json, $token) use (&$sent) {
    $sent = ['url' => $url, 'body' => json_decode((string) $json, true)];
    return ['status' => 204, 'data' => [], 'headers' => [], 'raw' => ''];
};
db()->prepare("INSERT INTO tasks (project_id, title, status, created_by, created_at, updated_at) VALUES (?, 'משימה', 'open', 1, 1, 1)")->execute([$root]);
$tid = (int) db()->lastInsertId();
$r = dispatchTask($boss, $P($root), getTask(db(), $tid), $anthropic, $dispatch);
check('Claude Code — ה-workflow שלו', [str_contains($sent['url'], 'claude-code-agent.yml/dispatches'), $r['engine']], [true, 'claude-code']);
check('…עם המטלה, הלוח והמודל, בלי provider', [$sent['body']['inputs']['task_id'], $sent['body']['inputs']['model'], isset($sent['body']['inputs']['provider'])],
      [(string) $tid, 'claude-opus-5-5', false]);
throws('Claude Code בלי Anthropic — לא מופעל', fn () => dispatchTask($boss, $P($root), getTask(db(), $tid), ['provider' => 'openai', 'key' => 'k', 'model' => 'm'], $dispatch), 'Anthropic');
dispatchTask($boss, $P($suite), getTask(db(), $tid), $anthropic, $dispatch);
check('loop — ה-workflow הישן', str_contains($sent['url'], 'claude-agent.yml/dispatches'), true);

echo "\n— העלות —\n";
setQuota($boss, 1, 25);
$cost = recordUsage(['user_id' => 1, 'project_id' => $root, 'task_id' => $tid, 'kind' => 'develop', 'cost_usd' => 0.8731],
                    'anthropic', 'claude-code', 120000, 9000);
check('total_cost_usd נרשם כמו שהוא (לא מחושב מחדש)', [$cost, (float) db()->query('SELECT cost_usd FROM usage ORDER BY id DESC LIMIT 1')->fetchColumn()], [0.8731, 0.8731]);
check('…כ-develop, ובלי אזהרת "מודל לא מוכר"', [db()->query('SELECT kind FROM usage ORDER BY id DESC LIMIT 1')->fetchColumn(),
      (int) db()->query("SELECT COUNT(*) FROM events WHERE action = 'usage.unknown_model'")->fetchColumn()], ['develop', 0]);
check('…ונספר במכסה של מי שהריץ', quotaState(1)['used'], 0.8731);

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
