<?php
/**
 * סטודיו — החיבור ל-GitHub ולסביבה, מול פלטפורמה מדומה: שרת ה-MCP של GitHub בהגדרת הסוכן, הכספת עם
 * האסימון (פעם אחת, מוחלפת כשהאסימון מוחלף, והסשן נפתח גם אם היא נכשלת), וסביבה ששמה כבר תפוס.
 *
 *   php claude-tasks/tests/studio-github.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/ctsg-' . getmypid();
@mkdir($tmp, 0777, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('CONFIG_FILE', $tmp . '/c.json');
define('SECRET_KEY_FILE', $tmp . '/secret.key');
define('BELL_DIR', $tmp . '/bell');
ini_set('error_log', $tmp . '/php.log');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/projects.php';
require_once __DIR__ . '/../lib/connections.php';
require_once __DIR__ . '/../lib/studio/studio.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}

$FAKE = ['calls' => [], 'n' => 0, 'env_taken' => false, 'vault_down' => false, 'reject_mcp' => false];
$GLOBALS['STUDIO_HTTP'] = function (string $method, string $url, array $headers, ?string $body) {
    global $FAKE;
    $path = parse_url($url, PHP_URL_PATH);
    $in = $body === null ? null : json_decode($body, true);
    $FAKE['calls'][] = ['m' => $method, 'p' => $path, 'b' => $in];
    $json = fn (array $d, int $s = 200) => ['status' => $s, 'body' => json_encode($d), 'headers' => []];
    if ($method === 'POST' && $path === '/v1/environments') {
        return $FAKE['env_taken'] ? $json(['error' => ['type' => 'invalid_request_error', 'message' => 'name exists']], 409) : $json(['id' => 'env_new']);
    }
    if ($method === 'GET' && $path === '/v1/environments') {
        return $json(['data' => [['id' => 'env_other', 'name' => 'something-else'], ['id' => 'env_old', 'name' => 'claude-tasks-studio']], 'next_page' => null]);
    }
    if (str_starts_with($path, '/v1/vaults') && $FAKE['vault_down']) return $json(['error' => ['type' => 'api_error', 'message' => 'down']], 500);
    if ($method === 'POST' && $path === '/v1/vaults') return $json(['id' => 'vlt_1']);
    if ($method === 'POST' && $path === '/v1/vaults/vlt_1/credentials') return $json(['id' => 'cred_' . (++$FAKE['n'])]);
    if ($method === 'DELETE' && preg_match('~^/v1/vaults/vlt_1/credentials/cred_\d+$~', $path)) return $json(['id' => basename($path), 'type' => 'vault_credential_deleted']);
    if ($method === 'POST' && $path === '/v1/agents') return $json(['id' => 'agent_1', 'version' => 1]);
    if ($method === 'POST' && $path === '/v1/sessions') {
        if ($FAKE['reject_mcp'] && isset($in['vault_ids'])) return $json(['error' => ['type' => 'invalid_request_error', 'message' => 'mcp not allowed']], 400);
        return $json(['id' => 'sesn_' . (++$FAKE['n'])]);
    }
    if ($method === 'POST' && preg_match('~/events$~', $path)) return $json(['data' => []]);
    return $json(['error' => ['type' => 'not_found_error', 'message' => "$method $path"]], 404);
};
function calls(string $m, string $p): array {
    global $FAKE;
    return array_values(array_filter($FAKE['calls'], fn ($c) => $c['m'] === $m && $c['p'] === $p));
}
function setGithubToken(int $uid, string $token): void {
    db()->prepare("DELETE FROM connections WHERE user_id = ? AND kind = 'github'")->execute([$uid]);
    db()->prepare("INSERT INTO connections (user_id, kind, label, secret, login, created_at) VALUES (?, 'github', '', ?, 'o', ?)")
        ->execute([$uid, encryptSecret($token), nowTs()]);
}

$admin = createUser('owner', 'pw-owner-1234', 'בעל הלוח');
$owner = ['id' => $admin['id'], 'username' => 'owner', 'role' => 'admin', 'display_name' => 'בעל הלוח'];
$uid = (int) $owner['id'];
db()->prepare("INSERT INTO connections (user_id, kind, label, secret, model, priority, created_at) VALUES (?, 'anthropic', '', ?, 'claude-default', 1, ?)")
    ->execute([$uid, encryptSecret('sk-ant-test'), nowTs()]);
setGithubToken($uid, 'ghp_first_token');

echo "שיחה ראשונה\n";
studioSend($owner, studioCreateConv($owner, ['repo' => 'o/r', 'model' => 'claude-x']), 'היי');
$agent = calls('POST', '/v1/agents')[0]['b'];
check('הסוכן מצהיר על שרת ה-MCP של GitHub', $agent['mcp_servers'], [['type' => 'url', 'name' => 'github', 'url' => 'https://api.githubcopilot.com/mcp/']]);
check('כלי GitHub רצים בלי לעצור לאישור', [$agent['tools'][1]['type'], $agent['tools'][1]['mcp_server_name'], $agent['tools'][1]['default_config']['permission_policy']['type']], ['mcp_toolset', 'github', 'always_allow']);
check('אין אסימון בהגדרת הסוכן', str_contains(json_encode($agent), 'ghp_'), false);
check('נוצרה כספת אחת', count(calls('POST', '/v1/vaults')), 1);
$cred = calls('POST', '/v1/vaults/vlt_1/credentials')[0]['b'];
check('האישור בכספת: אסימון קבוע לכתובת שרת ה-MCP', $cred['auth'], ['type' => 'static_bearer', 'mcp_server_url' => 'https://api.githubcopilot.com/mcp/', 'token' => 'ghp_first_token']);
$sess = calls('POST', '/v1/sessions')[0]['b'];
check('הסשן מצרף את הכספת', $sess['vault_ids'], ['vlt_1']);
check('והריפו עדיין מחובר עם האסימון, ל-git', $sess['resources'][0]['authorization_token'], 'ghp_first_token');
check('ההנחיה מפנה לכלי GitHub, עם curl כגיבוי', [str_contains($sess['agent']['system'], 'שרת ה-MCP בשם github'), str_contains($sess['agent']['system'], 'curl')], [true, true]);
check('האסימון לא בהנחיה', str_contains($sess['agent']['system'], 'ghp_'), false);
check('האסימון לא נשמר בהגדרות הסטודיו', str_contains(json_encode(studioSettings($uid)), 'ghp_'), false);

echo "שיחה שנייה, אותו אסימון\n";
studioSend($owner, studioCreateConv($owner, ['repo' => 'o/r', 'model' => 'claude-x']), 'היי');
check('הכספת והאישור לא נוצרים שוב', [count(calls('POST', '/v1/vaults')), count(calls('POST', '/v1/vaults/vlt_1/credentials'))], [1, 1]);
check('גם הסשן השני מצרף אותה', calls('POST', '/v1/sessions')[1]['b']['vault_ids'], ['vlt_1']);

echo "האסימון הוחלף בחיבורים\n";
setGithubToken($uid, 'ghp_second_token');
studioSend($owner, studioCreateConv($owner, ['repo' => 'o/r', 'model' => 'claude-x']), 'היי');
check('האישור הישן נמחק', count(calls('DELETE', '/v1/vaults/vlt_1/credentials/cred_1')), 1);
check('ונוצר אישור עם האסימון החדש, באותה כספת', [count(calls('POST', '/v1/vaults')), calls('POST', '/v1/vaults/vlt_1/credentials')[1]['b']['auth']['token']], [1, 'ghp_second_token']);

echo "הכספת נכשלת\n";
db()->exec('DELETE FROM studio_settings');
$FAKE['vault_down'] = true;
$c = studioSend($owner, studioCreateConv($owner, ['repo' => 'o/r', 'model' => 'claude-x']), 'היי');
check('הסשן נפתח בכל זאת', $c['status'], 'running');
$plain = calls('POST', '/v1/sessions')[3]['b'];
check('בלי vault_ids', array_key_exists('vault_ids', $plain), false);
check('ובלי שרת MCP בסשן — רק כלי הסוכן', [$plain['agent']['mcp_servers'], array_column($plain['agent']['tools'], 'type')], [[], ['agent_toolset_20260401']]);
check('הכלים הרגילים נשארים בלי אישור', $plain['agent']['tools'][0]['default_config']['permission_policy']['type'], 'always_allow');
check('והסיבה נשמרה', (string) (studioSettings($uid)['vault_error'] ?? '') !== '', true);
$FAKE['vault_down'] = false;
studioSend($owner, studioCreateConv($owner, ['repo' => 'o/r', 'model' => 'claude-x']), 'היי');
check('בשיחה הבאה הכספת מוקמת והשגיאה מתנקה', [calls('POST', '/v1/sessions')[4]['b']['vault_ids'], (string) studioSettings($uid)['vault_error']], [['vlt_1'], '']);

echo "הפלטפורמה דוחה סשן עם כלי GitHub\n";
$FAKE['reject_mcp'] = true;
$n = count(calls('POST', '/v1/sessions'));
$c = studioSend($owner, studioCreateConv($owner, ['repo' => 'o/r', 'model' => 'claude-x']), 'היי');
$tries = array_slice(calls('POST', '/v1/sessions'), $n);
check('ניסיון שני, בלי הכספת ובלי MCP', [count($tries), isset($tries[0]['b']['vault_ids']), isset($tries[1]['b']['vault_ids']), $tries[1]['b']['agent']['mcp_servers']], [2, true, false, []]);
check('השיחה נפתחת, והסיבה נשמרת', [$c['status'], str_contains((string) studioSettings($uid)['vault_error'], 'בלי כלי GitHub')], ['running', true]);
$FAKE['reject_mcp'] = false;

echo "שם הסביבה כבר תפוס\n";
db()->exec('DELETE FROM studio_settings');
$FAKE['env_taken'] = true;
studioSend($owner, studioCreateConv($owner, ['repo' => 'o/r', 'model' => 'claude-x']), 'היי');
$all = calls('POST', '/v1/sessions');
check('משתמשים בסביבה הקיימת לפי השם', [studioSettings($uid)['env_id'], end($all)['b']['environment_id']], ['env_old', 'env_old']);

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
