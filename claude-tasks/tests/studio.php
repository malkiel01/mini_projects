<?php
/**
 * סטודיו (docs/studio.md) — מול פלטפורמה מדומה, בלי רשת ובלי מפתח אמיתי:
 * יצירת סוכן/סביבה/סשן פעם אחת, שליחה, סנכרון בשני סדרי עמודים, כפילויות,
 * חישוב המצב מהיומן, אישור פעולה, תקציב, התאמה מהקורן, וחתימת webhook.
 *
 *   php claude-tasks/tests/studio.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/cts-' . getmypid();
@mkdir($tmp, 0777, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('CONFIG_FILE', $tmp . '/c.json');
define('SECRET_KEY_FILE', $tmp . '/secret.key');
define('BELL_DIR', $tmp . '/bell');
define('STUDIO_CRON_NO_RUN', true);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/projects.php';
require_once __DIR__ . '/../lib/connections.php';
require_once __DIR__ . '/../lib/studio/studio.php';
require_once __DIR__ . '/../lib/studio/webhook.php';
require_once __DIR__ . '/../bin/studio-cron.php';

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
}

/* ── פלטפורמה מדומה ────────────────────────────────────────────── */

$FAKE = ['calls' => [], 'events' => [], 'order' => 'asc', 'page_size' => 3, 'session_status' => 'running', 'fail' => null, 'n' => 0, 'echo' => true];
$GLOBALS['STUDIO_HTTP'] = function (string $method, string $url, array $headers, ?string $body) {
    global $FAKE;
    $path = parse_url($url, PHP_URL_PATH);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $in = $body === null ? null : json_decode($body, true);
    $FAKE['calls'][] = ['m' => $method, 'p' => $path, 'q' => $q, 'b' => $in, 'h' => $headers];
    $json = fn (array $d, int $s = 200, array $h = []) => ['status' => $s, 'body' => json_encode($d), 'headers' => $h];
    if ($FAKE['fail']) { [$s, $t] = $FAKE['fail']; return $json(['error' => ['type' => $t, 'message' => 'x']], $s, ['retry-after' => '30']); }

    if ($method === 'POST' && $path === '/v1/environments') return $json(['id' => 'env_1']);
    if ($method === 'POST' && $path === '/v1/agents') return $json(['id' => 'agent_1', 'version' => 1]);
    if ($method === 'POST' && $path === '/v1/agents/agent_1') return $json(['id' => 'agent_1', 'version' => 2]);
    if ($method === 'POST' && $path === '/v1/sessions') return $json(['id' => 'sesn_1', 'status' => 'idle']);
    if ($method === 'GET' && $path === '/v1/sessions/sesn_1') return $json(['id' => 'sesn_1', 'status' => $FAKE['session_status']]);
    if ($method === 'POST' && $path === '/v1/sessions/sesn_1') return $json(['id' => 'sesn_1']);
    if ($method === 'POST' && $path === '/v1/sessions/sesn_1/events') {
        $out = [];
        foreach ($in['events'] as $e) { $e['id'] = 'sevt_u' . (++$FAKE['n']); $e['processed_at'] = null; $FAKE['events'][] = $e; $out[] = $e; }
        return $json(['data' => $FAKE['echo'] ? $out : []]);
    }
    if ($method === 'GET' && $path === '/v1/sessions/sesn_1/events') {
        $all = $FAKE['order'] === 'desc' ? array_reverse($FAKE['events']) : $FAKE['events'];
        $off = (int) ($q['page'] ?? 0);
        if (isset($q['page']) && $q['page'] === 'stale') return $json(['error' => ['type' => 'invalid_request_error', 'message' => 'bad page']], 400);
        $slice = array_slice($all, $off, $FAKE['page_size']);
        $next = $off + $FAKE['page_size'] < count($all) ? (string) ($off + $FAKE['page_size']) : null;
        return $json(['data' => $slice, 'next_page' => $next]);
    }
    return $json(['error' => ['type' => 'not_found_error', 'message' => "$method $path"]], 404);
};
function fakeEmit(string $type, array $extra = []): void {
    global $FAKE;
    static $t = 1700000000;
    $FAKE['events'][] = ['id' => 'sevt_a' . (++$FAKE['n']), 'type' => $type, 'processed_at' => gmdate('c', ++$t)] + $extra;
}
function calls(string $m, string $p): array {
    global $FAKE;
    return array_values(array_filter($FAKE['calls'], fn ($c) => $c['m'] === $m && $c['p'] === $p));
}

/* ── הכנה ──────────────────────────────────────────────────────── */

$admin = createUser('owner', 'pw-owner-1234', 'בעל הלוח');
$member = createUser('member', 'pw-member-1234', 'חבר');
$owner = ['id' => $admin['id'], 'username' => 'owner', 'role' => 'admin', 'display_name' => 'בעל הלוח'];
$other = ['id' => $member['id'], 'username' => 'member', 'role' => 'member', 'display_name' => 'חבר'];
$uid = (int) $owner['id'];

echo "הרשאה\n";
throws('חבר רגיל לא נכנס לסטודיו', fn () => studioRequireOwner($other), 403);
throws('בלי התחברות — 401', fn () => studioRequireOwner(null), 401);
check('בעל הלוח נכנס', studioRequireOwner($owner)['id'], $owner['id']);

echo "שיחה בלי חיבורים\n";
throws('ריפו לא תקין נדחה', fn () => studioCreateConv($owner, ['repo' => 'not a repo', 'model' => 'claude-x']));
throws('בלי מודל ובלי חיבור — נדחה', fn () => studioCreateConv($owner, ['repo' => 'o/r']));
$c = studioCreateConv($owner, ['repo' => 'malkiel01/mini_projects', 'model' => 'claude-test-1']);
check('שיחה חדשה במצב new', $c['status'], 'new');
check('תקציב ברירת מחדל 20$', (int) $c['budget_cents'], 2000);
check('מיזוג מותר כברירת מחדל', (int) $c['allow_merge'], 1);
throws('שליחה בלי מפתח Anthropic נדחית', fn () => studioSend($owner, $c, 'היי'));
check('ולא יצאה אף קריאה לפלטפורמה', count($FAKE['calls']), 0);

db()->prepare("INSERT INTO connections (user_id, kind, label, secret, model, priority, created_at) VALUES (?, 'anthropic', '', ?, 'claude-conn-model', 1, ?)")
    ->execute([$uid, encryptSecret('sk-ant-test'), nowTs()]);
throws('שליחה בלי GitHub נדחית', fn () => studioSend($owner, $c, 'היי'));
db()->prepare("INSERT INTO connections (user_id, kind, label, secret, login, created_at) VALUES (?, 'github', '', ?, 'malkiel01', ?)")
    ->execute([$uid, encryptSecret('ghp_secret_token'), nowTs()]);
check('מודל ברירת מחדל מגיע מהחיבור', studioDefaultModel($uid), 'claude-conn-model');

echo "שליחה ראשונה\n";
$c = studioSend($owner, $c, 'תבנה לי אפליקציה');
check('נוצרה סביבה פעם אחת', count(calls('POST', '/v1/environments')), 1);
check('נוצר סוכן פעם אחת', count(calls('POST', '/v1/agents')), 1);
$agentDef = calls('POST', '/v1/agents')[0]['b'];
check('כלי הסוכן ב-always_allow', $agentDef['tools'][0]['default_config']['permission_policy']['type'], 'always_allow');
check('הסביבה ברשת פתוחה', calls('POST', '/v1/environments')[0]['b']['config']['networking']['type'], 'unrestricted');
$sessDef = calls('POST', '/v1/sessions')[0]['b'];
check('הסשן מצמיד גרסת סוכן', [$sessDef['agent']['type'], $sessDef['agent']['id'], $sessDef['agent']['version']], ['agent_with_overrides', 'agent_1', 1]);
check('המודל של השיחה עובר לסשן', $sessDef['agent']['model']['id'], 'claude-test-1');
check('הריפו מחובר עם האסימון', [$sessDef['resources'][0]['url'], $sessDef['resources'][0]['authorization_token']], ['https://github.com/malkiel01/mini_projects', 'ghp_secret_token']);
check('תקציב נשלח כמחרוזת סנטים', $sessDef['budget']['max_list_cost'], ['amount' => '2000', 'currency' => 'USD']);
check('האסימון לא בהנחיית המערכת', str_contains($sessDef['agent']['system'], 'ghp_'), false);
check('ההנחיה מתירה מיזוג', str_contains($sessDef['agent']['system'], 'יש לך הרשאה למזג'), true);
check('כותרת הבטא נשלחת', in_array('anthropic-beta: managed-agents-2026-04-01', calls('POST', '/v1/sessions')[0]['h'], true), true);
check('ההודעה נשלחה פעם אחת', count(calls('POST', '/v1/sessions/sesn_1/events')), 1);
check('המצב: רץ', $c['status'], 'running');
check('הכותרת נלקחה מההודעה', $c['title'], 'תבנה לי אפליקציה');
check('אין משיכת אירועים לפני הצורך', count(calls('GET', '/v1/sessions/sesn_1/events')), 0);

echo "סנכרון (ישן→חדש), עמודים, כפילויות\n";
fakeEmit('session.status_running');
fakeEmit('agent.message', ['content' => [['type' => 'text', 'text' => 'מתחיל']]]);
fakeEmit('agent.tool_use', ['name' => 'bash', 'input' => ['command' => 'ls -la']]);
fakeEmit('agent.tool_result', ['tool_use_id' => 'x', 'content' => [['type' => 'text', 'text' => 'README.md']]]);
$c = studioSync($c, 'sk-ant-test');
check('זוהה סדר עולה', $c['order_mode'], 'asc');
check('עדיין רץ', $c['status'], 'running');
$ev = studioEventsSince((int) $c['id'], 0)['events'];
check('סדר התצוגה', array_column($ev, 'kind'), ['user', 'agent', 'tool', 'result']);
check('תקציר הכלי', $ev[2]['summary'], 'ls -la');
$before = (int) db()->query('SELECT COUNT(*) c FROM studio_events')->fetch()['c'];
$c = studioSync($c, 'sk-ant-test');
check('סנכרון חוזר לא מכפיל', (int) db()->query('SELECT COUNT(*) c FROM studio_events')->fetch()['c'], $before);
$n = count($FAKE['calls']);
fakeEmit('session.usage', ['list_cost' => ['amount' => '37', 'currency' => 'USD'], 'active_seconds' => 12]);
fakeEmit('agent.message', ['content' => [['type' => 'text', 'text' => 'סיימתי']]]);
fakeEmit('session.status_idle', ['stop_reason' => ['type' => 'end_turn']]);
$c = studioSync($c, 'sk-ant-test');
$listCalls = array_slice($FAKE['calls'], $n);
check('ממשיך מהסמן השמור ולא מההתחלה', $listCalls[0]['q']['page'] ?? '', '3');
check('המצב: ממתין', [$c['status'], $c['status_detail']], ['idle', 'end_turn']);
check('עלות מהאירוע', studioPublicConv($c)['cost_cents'], 37);
check('אין עוד מה למשוך', (int) $c['more'], 0);

echo "סמן שפג\n";
studioUpdateConv((int) $c['id'], ['page_cursor' => 'stale']);
$c = studioSync(studioConv($uid, (int) $c['id']), 'sk-ant-test');
check('סמן שנדחה — מתחילים מההתחלה בלי כפילות', (int) db()->query('SELECT COUNT(*) c FROM studio_events')->fetch()['c'], $before + 3);

echo "הודעה שנייה באותו סשן\n";
$c = studioSend($owner, $c, 'עכשיו תריץ בדיקות');
check('לא נוצר סשן נוסף', count(calls('POST', '/v1/sessions')), 1);
check('לא נוצר סוכן נוסף', count(calls('POST', '/v1/agents')), 1);
check('הודעה אחרי idle = רץ (בתור)', [$c['status'], $c['status_detail']], ['running', 'queued']);

echo "אישור פעולה\n";
fakeEmit('session.status_running');
fakeEmit('agent.mcp_tool_use', ['name' => 'merge', 'input' => ['pr' => 1], 'evaluated_permission' => 'ask']);
$askId = end($FAKE['events'])['id'];
fakeEmit('session.status_idle', ['stop_reason' => ['type' => 'requires_action', 'event_ids' => [$askId]]]);
$c = studioSync($c, 'sk-ant-test');
check('המצב: מחכה לאישור', [$c['status'], studioPublicConv($c)['pending']], ['needs_action', 1]);
throws('הודעה רגילה נחסמת בזמן המתנה לאישור', fn () => studioSend($owner, $c, 'משהו'), 409);
$c = studioConfirm($owner, $c, 'allow');
$sent = end($FAKE['calls'])['b']['events'][0];
check('נשלח אישור לאירוע הנכון', [$sent['type'], $sent['tool_use_id'], $sent['result']], ['user.tool_confirmation', $askId, 'allow']);
check('אחרי אישור — רץ', $c['status'], 'running');
throws('אין מה לאשר פעמיים', fn () => studioConfirm($owner, $c, 'allow'), 409);

echo "תקציב\n";
fakeEmit('session.status_running');
fakeEmit('session.status_idle', ['stop_reason' => ['type' => 'budget_reached']]);
$c = studioSync($c, 'sk-ant-test');
check('המצב: תקרת תקציב', $c['status'], 'budget');
throws('הודעה נחסמת בתקרת התקציב', fn () => studioSend($owner, $c, 'המשך'), 409);
$c = studioSetBudget($owner, $c, 5000);
check('עדכון תקציב נשלח לסשן', end($FAKE['calls'])['b']['budget']['max_list_cost']['amount'], '5000');
check('אחרי העלאה — ממשיך לבד', $c['status'], 'running');

echo "סדר יורד\n";
$FAKE['events'] = []; $FAKE['order'] = 'desc';
db()->exec('DELETE FROM studio_events');
studioUpdateConv((int) $c['id'], ['order_mode' => '', 'page_cursor' => '', 'status' => 'running']);
fakeEmit('session.status_running');
fakeEmit('agent.message', ['content' => [['type' => 'text', 'text' => 'א']]]);
fakeEmit('agent.message', ['content' => [['type' => 'text', 'text' => 'ב']]]);
fakeEmit('agent.message', ['content' => [['type' => 'text', 'text' => 'ג']]]);
$c = studioSync(studioConv($uid, (int) $c['id']), 'sk-ant-test');
check('זוהה סדר יורד', $c['order_mode'], 'desc');
check('היומן בסדר כרונולוגי', array_column(studioEventsSince((int) $c['id'], 0)['events'], 'text'), ['א', 'ב', 'ג']);
fakeEmit('agent.message', ['content' => [['type' => 'text', 'text' => 'ד']]]);
fakeEmit('session.status_idle', ['stop_reason' => ['type' => 'end_turn']]);
$n = count($FAKE['calls']);
$c = studioSync($c, 'sk-ant-test');
check('רק החדשים נוספו, בסוף', array_column(studioEventsSince((int) $c['id'], 0)['events'], 'kind'), ['agent', 'agent', 'agent', 'agent', 'idle']);
check('נעצר בעמוד שכולו מוכר', count($FAKE['calls']) - $n, 2);
check('המצב: ממתין', $c['status'], 'idle');
$FAKE['order'] = 'asc';

echo "מרווח הסנכרון\n";
$GLOBALS['CT_FAKE_NOW'] = time() + 100;
$c = studioSyncIfDue(studioConv($uid, (int) $c['id']), 'sk-ant-test');
$n = count($FAKE['calls']);
studioSyncIfDue(studioConv($uid, (int) $c['id']), 'sk-ant-test');
check('סנכרון שני בתוך המרווח לא פונה לפלטפורמה', count($FAKE['calls']), $n);

echo "קורן\n";
$n = count($FAKE['calls']);
check('אין ריצה פעילה — הקורן לא בודק כלום', studioCronRun()['checked'], 0);
check('ואין אף פנייה החוצה', count($FAKE['calls']), $n);
studioLocalMarker((int) $c['id'], 'local.sent');
studioRefreshConv((int) $c['id']);
$GLOBALS['CT_FAKE_NOW'] += 2000;
$FAKE['session_status'] = 'idle';
check('שיחה שרצה ולא סונכרנה — נבדקת', studioCronRun()['checked'], 1);
check('הסשן בעצם idle — השיחה מותאמת', studioConv($uid, (int) $c['id'])['status'], 'idle');
studioLocalMarker((int) $c['id'], 'local.sent');
studioRefreshConv((int) $c['id']);
$GLOBALS['CT_FAKE_NOW'] += 100;
$FAKE['fail'] = [429, 'rate_limit_error'];
$n = count($FAKE['calls']);
$r = studioCronRun();
check('429 נספר כשגיאה, בלי ניסיון חוזר', [$r['errors'], count($FAKE['calls']) - $n], [1, 1]);
check('והשיחה נדחית לפי retry-after', (int) studioConv($uid, (int) $c['id'])['synced_at'], nowTs() + 30);
$FAKE['fail'] = null;

echo "שגיאות הפלטפורמה\n";
$FAKE['fail'] = [402, 'billing_error'];
try { maCall('sk', 'GET', '/v1/sessions/sesn_1'); check('402 זורק', false, true); }
catch (MaError $e) { check('402 מתורגם להודעה על קרדיט', [$e->http, str_contains($e->getMessage(), 'קרדיט')], [402, true]); }
$FAKE['fail'] = null;

echo "בידוד בין משתמשים\n";
throws('משתמש אחר לא רואה שיחה', fn () => studioConv((int) $other['id'], (int) $c['id']), 404);

echo "שליחה בלי הד מהפלטפורמה\n";
unset($GLOBALS['CT_FAKE_NOW']);
$FAKE['echo'] = false;
fakeEmit('session.status_idle', ['stop_reason' => ['type' => 'end_turn']]);
$c = studioSync(studioConv($uid, (int) $c['id']), 'sk-ant-test');
$c = studioSend($owner, $c, 'עוד משהו');
check('גם בלי הד — המצב רץ', $c['status'], 'running');
$FAKE['echo'] = true;

echo "הנחיה בלי הרשאת מיזוג\n";
$c2 = studioCreateConv($owner, ['repo' => 'o/r', 'model' => 'claude-test-1', 'allow_merge' => false, 'budget_cents' => 0]);
check('ההנחיה אוסרת מיזוג', str_contains(studioSystemPrompt($c2), 'אין לך הרשאה למזג'), true);
$c2 = studioSend($owner, $c2, 'x');
check('בלי תקציב — השדה לא נשלח', array_key_exists('budget', calls('POST', '/v1/sessions')[1]['b']), false);
throws('אי אפשר להוסיף תקציב לסשן קיים', fn () => studioSetBudget($owner, $c2, 500), 409);

echo "חתימת webhook\n";
$secretRaw = random_bytes(32);
$secret = 'whsec_' . base64_encode($secretRaw);
$body = '{"type":"event","id":"whe_1","data":{"type":"session.status_idled","id":"sesn_1"}}';
$ts = (string) time();
$sig = 'v1,' . base64_encode(hash_hmac('sha256', "whe_1.$ts.$body", $secretRaw, true));
check('חתימה נכונה', studioWebhookVerify($secret, $body, 'whe_1', $ts, $sig), true);
check('כמה חתימות בכותרת', studioWebhookVerify($secret, $body, 'whe_1', $ts, "v1,AAAA $sig"), true);
check('גוף ששונה', studioWebhookVerify($secret, $body . ' ', 'whe_1', $ts, $sig), false);
check('מזהה אחר', studioWebhookVerify($secret, $body, 'whe_2', $ts, $sig), false);
check('סוד אחר', studioWebhookVerify('whsec_' . base64_encode(random_bytes(32)), $body, 'whe_1', $ts, $sig), false);
check('ישן מ-5 דקות', studioWebhookVerify($secret, $body, 'whe_1', $ts, $sig, time() + 301), false);
check('בלי סוד', studioWebhookVerify('', $body, 'whe_1', $ts, $sig), false);
check('חותמת זמן לא מספרית', studioWebhookVerify($secret, $body, 'whe_1', 'abc', $sig), false);

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
