<?php
/**
 * סטודיו — צוות בתוך סשן אחד (lib/studio/team.php), מול פלטפורמה מדומה:
 * המנהל ושלושת חברי הצוות נוצרים פעם אחת, הסשן רץ על המנהל עם ההנחיה של השיחה,
 * שיחה רגילה לא נוגעת בצוות, והודעות הצוות מוצגות ביומן.
 *
 *   php claude-tasks/tests/studio-team.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/ctst-' . getmypid();
@mkdir($tmp, 0777, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('CONFIG_FILE', $tmp . '/c.json');
define('SECRET_KEY_FILE', $tmp . '/secret.key');
define('BELL_DIR', $tmp . '/bell');

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

$FAKE = ['calls' => [], 'events' => [], 'n' => 0, 'agents' => 0, 'fail_on' => ''];
$GLOBALS['STUDIO_HTTP'] = function (string $method, string $url, array $headers, ?string $body) {
    global $FAKE;
    $path = parse_url($url, PHP_URL_PATH);
    $in = $body === null ? null : json_decode($body, true);
    $FAKE['calls'][] = ['m' => $method, 'p' => $path, 'b' => $in];
    $json = fn (array $d, int $s = 200) => ['status' => $s, 'body' => json_encode($d), 'headers' => []];
    if ($FAKE['fail_on'] !== '' && ($in['name'] ?? '') === $FAKE['fail_on']) return $json(['error' => ['type' => 'api_error', 'message' => 'x']], 500);
    if ($method === 'POST' && $path === '/v1/environments') return $json(['id' => 'env_1']);
    if ($method === 'POST' && $path === '/v1/agents') return $json(['id' => 'agent_' . (++$FAKE['agents']), 'version' => 1]);
    if ($method === 'POST' && preg_match('~^/v1/agents/(agent_\d+)$~', $path, $m)) return $json(['id' => $m[1], 'version' => 2]);
    if ($method === 'POST' && $path === '/v1/sessions') return $json(['id' => 'sesn_' . (++$FAKE['n'])]);
    if ($method === 'POST' && preg_match('~/events$~', $path)) {
        $out = [];
        foreach ($in['events'] as $e) { $e['id'] = 'sevt_u' . (++$FAKE['n']); $out[] = $e; }
        return $json(['data' => $out]);
    }
    if ($method === 'GET' && preg_match('~/events$~', $path)) return $json(['data' => $FAKE['events'], 'next_page' => null]);
    return $json(['error' => ['type' => 'not_found_error', 'message' => "$method $path"]], 404);
};
function calls(string $m, string $p): array {
    global $FAKE;
    return array_values(array_filter($FAKE['calls'], fn ($c) => $c['m'] === $m && $c['p'] === $p));
}

$admin = createUser('owner', 'pw-owner-1234', 'בעל הלוח');
$owner = ['id' => $admin['id'], 'username' => 'owner', 'role' => 'admin', 'display_name' => 'בעל הלוח'];
$uid = (int) $owner['id'];
db()->prepare("INSERT INTO connections (user_id, kind, label, secret, model, priority, created_at) VALUES (?, 'anthropic', '', ?, 'claude-default', 1, ?)")
    ->execute([$uid, encryptSecret('sk-ant-test'), nowTs()]);
db()->prepare("INSERT INTO connections (user_id, kind, label, secret, login, created_at) VALUES (?, 'github', '', ?, 'o', ?)")
    ->execute([$uid, encryptSecret('ghp_secret_token'), nowTs()]);

echo "שיחה רגילה לא נוגעת בצוות\n";
$solo = studioCreateConv($owner, ['repo' => 'o/r', 'model' => 'claude-conv']);
check('ברירת מחדל: בלי צוות', (int) $solo['team'], 0);
$solo = studioSend($owner, $solo, 'היי');
check('נוצר רק הסוכן היחיד', count(calls('POST', '/v1/agents')), 1);
check('בלי multiagent', array_key_exists('multiagent', calls('POST', '/v1/agents')[0]['b']), false);
check('ההנחיה בלי פרק הצוות', str_contains(calls('POST', '/v1/sessions')[0]['b']['agent']['system'], 'מנהל הצוות'), false);

echo "שיחת צוות\n";
$team = studioCreateConv($owner, ['repo' => 'o/my-app', 'model' => 'claude-conv', 'team' => true]);
check('השיחה מסומנת כצוות', [(int) $team['team'], studioPublicConv($team)['team']], [1, true]);
$team = studioSend($owner, $team, 'תבנה אפליקציה');
$agents = calls('POST', '/v1/agents');
check('נוצרו שלושה חברי צוות ומנהל', count($agents), 5);
check('שמות חברי הצוות', array_column(array_column(array_slice($agents, 1, 3), 'b'), 'name'), ['אפיון', 'קוד', 'בקרה']);
check('לכל חבר צוות תיאור למנהל', count(array_filter(array_slice($agents, 1, 3), fn ($a) => mb_strlen($a['b']['description']) > 40)), 3);
check('חברי הצוות על מודל ברירת המחדל', array_unique(array_column(array_column(array_slice($agents, 1, 3), 'b'), 'model')), ['claude-default']);
check('חבר צוות אינו מאציל הלאה', count(array_filter(array_slice($agents, 1, 3), fn ($a) => isset($a['b']['multiagent']))), 0);
check('חבר צוות לא דוחף ולא ממזג', str_contains($agents[2]['b']['system'], 'אסור לך: push'), true);
$lead = $agents[4]['b'];
check('המנהל מתאם', $lead['multiagent']['type'], 'coordinator');
check('הרשימה מצמידה גרסאות', $lead['multiagent']['agents'], [
    ['type' => 'agent', 'id' => 'agent_2', 'version' => 1], ['type' => 'agent', 'id' => 'agent_3', 'version' => 1], ['type' => 'agent', 'id' => 'agent_4', 'version' => 1]]);
$sess = calls('POST', '/v1/sessions')[1]['b'];
check('הסשן רץ על המנהל', [$sess['agent']['type'], $sess['agent']['id'], $sess['agent']['version']], ['agent_with_overrides', 'agent_5', 1]);
check('המודל של השיחה חל על המנהל', $sess['agent']['model']['id'], 'claude-conv');
check('ההנחיה כוללת את כללי הריפו ואת פרק הצוות', [str_contains($sess['agent']['system'], 'CLAUDE.md'), str_contains($sess['agent']['system'], 'אתה גם מנהל הצוות'), str_contains($sess['agent']['system'], '/workspace/my-app/')], [true, true, true]);
check('האסימון לא בהנחיה', str_contains($sess['agent']['system'], 'ghp_'), false);

echo "פעם אחת בלבד\n";
$team2 = studioCreateConv($owner, ['repo' => 'o/r', 'model' => 'claude-conv', 'team' => true]);
studioSend($owner, $team2, 'עוד אחת');
check('שיחת צוות שנייה לא יוצרת סוכנים', count(calls('POST', '/v1/agents')), 5);
check('ולא מעדכנת אותם', count(array_filter($FAKE['calls'], fn ($c) => preg_match('~^/v1/agents/agent_~', $c['p']))), 0);

echo "שינוי מודל ברירת המחדל\n";
studioSaveSettings($uid, ['model' => 'claude-new-default']);
$team3 = studioCreateConv($owner, ['repo' => 'o/r', 'model' => 'claude-conv', 'team' => true]);
studioSend($owner, $team3, 'x');
$updates = array_values(array_filter($FAKE['calls'], fn ($c) => preg_match('~^/v1/agents/agent_~', $c['p'])));
check('הסוכנים מתעדכנים, לא נוצרים מחדש', [count(calls('POST', '/v1/agents')), count($updates)], [5, 4]);
check('המנהל מצמיד את הגרסאות החדשות', end($updates)['b']['multiagent']['agents'][0], ['type' => 'agent', 'id' => 'agent_2', 'version' => 2]);
check('הסשן על גרסת המנהל החדשה', calls('POST', '/v1/sessions')[3]['b']['agent']['version'], 2);

echo "כשל באמצע ההקמה\n";
db()->exec("DELETE FROM studio_settings");
$FAKE['agents'] = 100; $FAKE['fail_on'] = 'בקרה';
$before = count(calls('POST', '/v1/agents'));
$team4 = studioCreateConv($owner, ['repo' => 'o/r', 'model' => 'claude-conv', 'team' => true]);
try { studioSend($owner, $team4, 'x'); check('הכשל נזרק', false, true); } catch (AppError $e) { check('הכשל נזרק', true, true); }
$FAKE['fail_on'] = '';
studioSend($owner, studioConv($uid, (int) $team4['id']), 'x');
$created = array_column(array_column(array_slice(calls('POST', '/v1/agents'), $before), 'b'), 'name');
check('בניסיון השני נוצר רק מה שחסר', array_count_values($created)['אפיון'] ?? 0, 1);

echo "הודעות הצוות ביומן\n";
$FAKE['events'] = [
    ['id' => 'e1', 'type' => 'session.status_running', 'processed_at' => '2026-01-01T00:00:01Z'],
    ['id' => 'e2', 'type' => 'session.thread_created', 'agent_name' => 'אפיון', 'processed_at' => '2026-01-01T00:00:02Z'],
    ['id' => 'e3', 'type' => 'agent.thread_message_sent', 'to_agent_name' => 'אפיון', 'content' => [['type' => 'text', 'text' => 'כתוב אפיון ל-/workspace/my-app']], 'processed_at' => '2026-01-01T00:00:03Z'],
    ['id' => 'e4', 'type' => 'agent.thread_message_received', 'from_agent_name' => 'אפיון', 'content' => [['type' => 'text', 'text' => 'האפיון מוכן']], 'processed_at' => '2026-01-01T00:00:04Z'],
    ['id' => 'e5', 'type' => 'session.thread_status_idle', 'agent_name' => 'אפיון', 'stop_reason' => ['type' => 'end_turn'], 'processed_at' => '2026-01-01T00:00:05Z'],
    ['id' => 'e6', 'type' => 'agent.message', 'content' => [['type' => 'text', 'text' => 'סיימנו']], 'processed_at' => '2026-01-01T00:00:06Z'],
    ['id' => 'e7', 'type' => 'session.status_idle', 'stop_reason' => ['type' => 'end_turn'], 'processed_at' => '2026-01-01T00:00:07Z'],
];
$team = studioSync(studioConv($uid, (int) $team['id']), 'sk-ant-test');
$ev = studioEventsSince((int) $team['id'], 0)['events'];
check('סדר התצוגה', array_column($ev, 'kind'), ['user', 'team_out', 'team_in', 'agent', 'idle']);
check('מי קיבל ומי החזיר', [$ev[1]['who'], $ev[2]['who'], $ev[2]['text']], ['אפיון', 'אפיון', 'האפיון מוכן']);
check('חבר צוות שסיים לא מסיים את השיחה — רק הסשן', $team['status'], 'idle');
array_pop($FAKE['events']);
db()->exec('DELETE FROM studio_events WHERE conv_id = ' . (int) $team['id'] . " AND ev_id = 'e7'");
studioRefreshConv((int) $team['id']);
check('ובלי idle של הסשן — עדיין רץ', studioConv($uid, (int) $team['id'])['status'], 'running');

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
