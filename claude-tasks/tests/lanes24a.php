<?php
/**
 * שלב 24א (docs/spec.md §20.3, §20.7) — המתג "מאזינים", התקרה של 5, המד, הדיווח של הגשר (listen_state / next_check_at /
 * check_trigger_id), מצב השורה לכל מקרה בטבלה, ו"צ'אטים שלא יתעוררו לבד".
 *
 *   php claude-tasks/tests/lanes24a.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/ctl24a-' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp));
@mkdir($tmp, 0777, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('CONFIG_FILE', $tmp . '/c.json');
define('SECRET_KEY_FILE', $tmp . '/secret.key');
define('BELL_DIR', $tmp . '/bell');
define('THROTTLE_DIR', $tmp . '/throttle');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/projects.php';
require_once __DIR__ . '/../lib/channels.php';
require_once __DIR__ . '/../lib/throttle.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}
function throws(string $name, callable $fn, string $code) {
    try { $fn(); check($name, 'לא נזרקה חריגה', 'חריגה'); }
    catch (AppError $e) { check($name . ' — ' . $e->getMessage(), [$e->status, $e->errorCode], [503, $code]); }
}
$GLOBALS['CT_FAKE_NOW'] = 1_800_000_000;
$now = fn () => nowTs();

createUser('boss', 'pass-12345', 'בעל הלוח');
$boss = ['id' => 1, 'role' => 'admin', 'username' => 'boss', 'display_name' => 'בעל הלוח'];
$dana = ['id' => 2, 'role' => 'user', 'username' => 'dana', 'display_name' => 'דנה'];
$root = createProject($boss, 'mini_projects', 'malkiel01', 'mini_projects');
$regToken = bin2hex(random_bytes(16));
db()->prepare('UPDATE projects SET agent_token = ? WHERE id = ?')->execute([encryptSecret($regToken), $root]);
$_SERVER['HTTP_X_WORKER_TOKEN'] = $regToken;
$ids = [];
for ($i = 1; $i <= 7; $i++) $ids[$i] = (int) registerChannel(workerScope(), $root, "צ'אט $i", 'קוד', "session_L24_$i", "https://claude.ai/code/session_L24_$i")['channel_id'];
db()->exec('UPDATE channels SET listening_until = 0');

echo "\n— המתג: ⏸ / 🕐 / 🎧 —\n";
check('ברירת מחדל אחרי הפריסה — 🕐 בדיקה שעתית', listenersMode(), 'hourly');
check('הבלם הישן (bridge_paused) הוא "עצורים"', listenersMode(['bridge_paused' => 1]), 'paused');
try { setListenersMode($dana, 'on'); check('לא בעל הלוח — נדחה', 'עבר', 'נדחה'); } catch (AppError $e) { check('לא בעל הלוח — נדחה', $e->status, 403); }
check('🎧 — on, והבלם כבוי', [setListenersMode($boss, 'on')['mode'], empty(config()['bridge_paused'])], ['on', true]);
check('⏸ — הבלם', setListenersMode($boss, 'paused')['mode'], 'paused');
setListenersMode($boss, 'hourly');

echo "\n— המאזין רק כשהמתג on, ועד 5 —\n";
throws('🕐 — channel-wait נדחה, המאזין יוצא (listeners_off)', fn () => listenGate(getChannel($ids[1])), 'listeners_off');
check('…והמצב נרשם off_policy', getChannel($ids[1])['listen_state'], 'off_policy');
setListenersMode($boss, 'on');
for ($i = 1; $i <= 5; $i++) { listenGate(getChannel($ids[$i])); touchChannel($ids[$i], $now() + 90); }
check('5 מאזינים — כולם running', array_map(fn ($i) => getChannel($ids[$i])['listen_state'], [1, 2, 3, 4, 5]), array_fill(0, 5, 'running'));
throws('השישי — listener_slots_full', fn () => listenGate(getChannel($ids[6])), 'listener_slots_full');
listenGate(getChannel($ids[3]));
check('מאזין שכבר בפנים — ממשיך גם כשמלא', getChannel($ids[3])['listen_state'], 'running');

echo "\n— המד —\n";
meterBump('wait', 1_800_000_000); meterBump('wait', 1_800_000_000); meterBump('wait', 1_800_000_000 - 120); meterBump('err', 1_800_000_000);
check('בקשות לדקה (10 דק\'), 429/503 בשעה', [round(meterCount('wait', 10, 1_800_000_000) / 10, 1), meterCount('err', 60, 1_800_000_000)], [0.3, 1]);
check('listenersInfo: המצב, התקרה, כמה מאזינים', array_intersect_key(listenersInfo(), array_flip(['mode', 'cap', 'listening'])), ['mode' => 'on', 'cap' => 5, 'listening' => 5]);

echo "\n— הדיווח של הגשר —\n";
listenReport(getChannel($ids[6]), ['listen_state' => 'evil', 'next_check_at' => $now() + 3000, 'check_trigger_id' => 'trig_01ABCDEFGHJKLMNOPQ']);
$c6 = getChannel($ids[6]);
check('מצב לא מוכר נזרק; next_check_at ו-check_trigger_id נשמרים', [$c6['listen_state'], (int) $c6['next_check_at'], $c6['check_trigger_id']], ['slots_full', $now() + 3000, 'trig_01ABCDEFGHJKLMNOPQ']);
listenReport(getChannel($ids[6]), ['next_check_at' => $now() + 99999, 'check_trigger_id' => 'rm -rf /']);
check('זמן מופרך ו-trigger לא תקין — נזרקים', [(int) getChannel($ids[6])['next_check_at'], getChannel($ids[6])['check_trigger_id']], [$now() + 3000, 'trig_01ABCDEFGHJKLMNOPQ']);

echo "\n— השורה: כל מצב בטבלה —\n";
$st = fn (int $id) => channelListen(getChannel($id))['state'];
check('🎧 מאזין', $st($ids[1]), 'listening');
db()->exec("UPDATE channels SET listening_until = 0, listen_state = 'slots_full', next_check_at = " . ($now() + 1800) . " WHERE id = {$ids[6]}");
check('כל המקומות תפוסים', $st($ids[6]), 'slots_full');
db()->exec("UPDATE channels SET listen_state = 'ended', next_check_at = " . ($now() + 1800) . ", check_trigger_id = 'trig_01ABCDEFGHJKLMNOPQ' WHERE id = {$ids[6]}");
check('⏰ בודק כל שעה · הבא …', [channelListen(getChannel($ids[6]))['state'], channelListen(getChannel($ids[6]))['at']], ['checking', $now() + 1800]);
db()->exec("UPDATE channels SET listen_state = 'backoff:" . ($now() + 900) . ":HTTP 429' WHERE id = {$ids[6]}");
check('🛑 בנסיגה עד …, עם הסיבה', array_intersect_key(channelListen(getChannel($ids[6])), array_flip(['state', 'reason'])), ['state' => 'backoff', 'reason' => 'HTTP 429']);
db()->exec("UPDATE channels SET listen_state = 'ended', next_check_at = " . ($now() - 4000) . " WHERE id = {$ids[6]}");
check('💤 הבדיקה הפסיקה — כנראה נסגר', $st($ids[6]), 'stale');
db()->exec("UPDATE channels SET listen_state = 'never', next_check_at = 0, check_trigger_id = '' WHERE id = {$ids[6]}");
check('✗ אין בדיקה שעתית', $st($ids[6]), 'no_check');
check('ℹ️ גשר ישן — אין שום דיווח', $st($ids[7]), 'old_bridge');
setListenersMode($boss, 'paused');
check('⏸ המאזינים עצורים', $st($ids[7]), 'paused');
setListenersMode($boss, 'on');
check('ערוץ בארכיון — off', channelListen(['status' => 'archived'] + getChannel($ids[7]))['state'], 'off');
check('השורה יוצאת ב-publicChannel (לרשימה ולכותרת)', publicChannel(getChannel($ids[6]))['listen']['state'] ?? null, 'no_check');

echo "\n— צ'אטים שלא יתעוררו לבד —\n";
$ww = array_column(wontWake($boss, $root), 'id');
sort($ww);
check('גשר ישן, אין בדיקה — ברשימה; מאזינים ובודקים — לא', $ww, [$ids[6], $ids[7]]);
check('בכל שורה — קישור הסשן (לפתוח ב-Claude)', array_values(array_filter(wontWake($boss, $root), fn ($x) => $x['id'] === $ids[7]))[0]['session_url'], 'https://claude.ai/code/session_L24_7');
check('יומן "מאזינים" — בעל הלוח, כל הערוצים', count(listenersLog($boss)), 7);

echo "\n  ════ עברו: $pass · נכשלו: $fail ════\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
