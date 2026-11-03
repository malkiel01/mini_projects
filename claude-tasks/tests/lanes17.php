<?php
/**
 * שלב 17 (docs/spec.md §15.6, §15.12.2) — היומן, התגים, והמעבר הידני.
 * כל סיבה — שורה אחת ב-lane_log ושורת system אחת; המעבר הידני לא משנה את המסלול של הפרויקט ולא מזיז מטלה
 * שרצה; התגים נגזרים מ-handled_by / tasks.route, לא מהטקסט.
 *
 *   php claude-tasks/tests/lanes17.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/ctl17-' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp));
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
function throws(string $name, callable $fn, ?string $has = null) {
    try { $fn(); check($name, 'לא נזרקה חריגה', 'חריגה'); }
    catch (Throwable $e) { check($name . ' — ' . $e->getMessage(), $has === null || str_contains($e->getMessage(), $has), true); }
}
$GLOBALS['CT_FAKE_NOW'] = 1_800_000_000;
$tick = function (int $s) { $GLOBALS['CT_FAKE_NOW'] += $s; };
$col = fn (string $sql) => db()->query($sql)->fetchColumn();

createUser('boss', 'pass-12345', 'בעל הלוח');
$boss = ['id' => 1, 'role' => 'admin', 'username' => 'boss', 'display_name' => 'בעל הלוח'];
$root = createProject($boss, 'mini_projects', 'malkiel01', 'mini_projects');
addConnection($boss, 'anthropic', 'sk-ant-bossbossbossbossboss', 'אישי', 'claude-opus-5-5');
setQuota($boss, 1, 25);
$regToken = bin2hex(random_bytes(16));
db()->prepare('UPDATE projects SET agent_token = ? WHERE id = ?')->execute([encryptSecret($regToken), $root]);
$_SERVER['HTTP_X_WORKER_TOKEN'] = $regToken;
$mk = function (string $name) use ($root) {
    static $n = 0; $n++;
    $c = getChannel(registerChannel(workerScope(), $root, $name, 'קוד', "session_L17_$n", '')['channel_id']);
    db()->prepare('UPDATE channels SET listening_until = 0 WHERE id = ?')->execute([$c['id']]);
    return (int) $c['id'];
};
/** כמה שורות ביומן וכמה שורות system (status) נוספו לערוץ מאז $mark. */
$delta = function (int $cid, array $mark) use ($col) {
    return [(int) $col("SELECT COUNT(*) FROM lane_log WHERE channel_id = $cid") - $mark[0],
            (int) $col("SELECT COUNT(*) FROM messages WHERE channel_id = $cid AND author_role = 'system' AND kind = 'status'") - $mark[1]];
};
$mark = fn (int $cid) => $delta($cid, [0, 0]);
$reasons = fn (int $cid) => array_column(db()->query("SELECT reason FROM lane_log WHERE channel_id = $cid ORDER BY id")->fetchAll(), 'reason');

echo "\n— כל סיבה: שורה אחת ביומן ושורה אחת בשיחה —\n";
// הצ'אט שותק → אוטומטי → chat_silent
setLanePrefs($boss, ['on_stop' => 'auto']);
$a = $mk('שקט');
channelSend($boss, $a, 'x'); $tick(11 * 60);
$m0 = $mark($a);
silenceCheck(getChannel($a));
check('chat_silent (אוטומטי) — 1 ביומן, 1 בשיחה', $delta($a, $m0), [1, 1]);
// החזרה, עם "לחזור באמצע: כן" → recovered
setLanePrefs($boss, ['return_preferred' => 'yes', 'preferred' => 'chat']);
$m0 = $mark($a);
laneReturn(getChannel($a));
check('recovered (הצ\'אט חזר, חוזרים ל-💬) — 1 ו-1', $delta($a, $m0), [1, 1]);
setLanePrefs($boss, ['return_preferred' => 'no', 'preferred' => '']);
// מגבלה → chat_limit
$l = $mk('מגבלה');
channelSend($boss, $l, 'x');
$m0 = $mark($l);
channelLimit(getChannel($l), 'rate_limit');
check('chat_limit — 1 ו-1', $delta($l, $m0), [1, 1]);
// כרטיס → בעל הלוח בוחר ☁️ → chat_silent, by=בעל הלוח
setLanePrefs($boss, ['on_stop' => 'ask']);
$k = $mk('כרטיס');
channelSend($boss, $k, 'x'); $tick(11 * 60);
silenceCheck(getChannel($k));
$m0 = $mark($k);
laneCardAnswer($boss, (int) getChannel($k)['silence_card_id'], 'platform');
check('כרטיס ← ☁️ — 1 ו-1, עם מי החליט', [$delta($k, $m0), (int) $col("SELECT by_user FROM lane_log WHERE channel_id = $k ORDER BY id DESC LIMIT 1")], [[1, 1], 1]);
// תקרה → cap_reached
setLanePrefs($boss, ['on_stop' => 'auto']);
db()->exec('UPDATE users SET auto_ceiling_usd = 0.01 WHERE id = 1');
$c = $mk('תקרה');
channelSend($boss, $c, 'x'); $tick(11 * 60);
$m0 = $mark($c);
silenceCheck(getChannel($c));
check('cap_reached — 1 ו-1 (והכרטיס שואל)', [$delta($c, $m0), $reasons($c), (int) $col("SELECT COUNT(*) FROM messages WHERE channel_id = $c AND kind = 'lane_card'")], [[1, 1], ['cap_reached'], 1]);
db()->exec('UPDATE users SET auto_ceiling_usd = 0 WHERE id = 1');
// Platform נעצר: ערוץ ☁️ → platform_credit; "לעבור" → שוב platform_credit עם מי; חזר → recovered
$p = $mk('ענן');
db()->prepare("UPDATE channels SET route = 'platform' WHERE id = ?")->execute([$p]);
setLanePrefs($boss, ['on_stop' => 'ask']);
$m0 = $mark($p);
breakerTrip(1, 'credit_exhausted', 'Your credit balance is too low');
check('platform_credit (המפסק נפתח) — 1 ו-1', $delta($p, $m0), [1, 1]);
$m0 = $mark($p);
breakerDecide($boss, 'handover');
check('"לעבור לצ\'אט" — 1 ו-1', $delta($p, $m0), [1, 1]);
$m0 = $mark($p);
breakerReset(1);
check('recovered (Platform חזר) — 1 ו-1', $delta($p, $m0), [1, 1]);
check('…הסיבות ביומן, בסדר', $reasons($p), ['platform_credit', 'platform_credit', 'recovered']);
breakerTrip(1, 'key_rejected', 'invalid x-api-key'); breakerReset(1);
breakerTrip(1, 'rate_limited', 'rate limit'); breakerReset(1);
breakerTrip(1, 'down', '500'); breakerReset(1);
check('מפתח / עומס / תקלה — כל אחד בשמו', array_slice($reasons($p), 3), ['platform_key', 'recovered', 'platform_rate', 'recovered', 'platform_down', 'recovered']);
db()->prepare("UPDATE channels SET route = '' WHERE id = ?")->execute([$p]);
throws('סיבה לא מוכרת — נדחית', fn () => laneLogAdd($p, 'chat', 'platform', 'because'), 'לא מוכרת');

echo "\n— המעבר הידני: רק הקבוצה שממתינה, לא המסלול —\n";
$mn = $mk('ידני');
db()->exec("UPDATE projects SET route = 'chat' WHERE id = $root");
$w1 = channelSend($boss, $mn, 'ממתינה 1');
$w2 = channelSend($boss, $mn, 'ממתינה 2');
db()->prepare('INSERT INTO tasks (title, project_id, status, created_by, created_at, updated_at) VALUES (?,?,?,1,?,?)')
    ->execute(['מטלה שרצה', $root, 'open', nowTs(), nowTs()]);
$tid = (int) db()->lastInsertId();
db()->prepare("UPDATE tasks SET status = 'in_progress', route = 'chat', claimed_by = 'x', asked_channel_id = ? WHERE id = ?")->execute([$mn, $tid]);
$routeBefore = [projectRoute($root), (string) getChannel($mn)['route']];
$m0 = $mark($mn);
$r = laneSwitchManual($boss, $mn, 'platform');
check('☁️ — שתי הממתינות עברו, והלוח מפעיל אותן', [$r['moved'], $r['run']], [2, [$w1, $w2]]);
check('…1 ביומן (manual), 1 בשיחה', [$delta($mn, $m0), $reasons($mn)], [[1, 1], ['manual']]);
check('…המסלול של הפרויקט ושל הערוץ לא השתנו', [projectRoute($root), (string) getChannel($mn)['route']], $routeBefore);
check('…והערוץ עדיין 💬 להודעה הבאה', channelRoute(getChannel($mn)), 'chat');
check('…מטלה שרצה לא זזה', [$col("SELECT status FROM tasks WHERE id = $tid"), $col("SELECT route FROM tasks WHERE id = $tid")], ['in_progress', 'chat']);
$w3 = channelSend($boss, $mn, 'הודעה חדשה אחרי המעבר');
check('…הודעה חדשה — לפי המסלול הרגיל (💬)', $col("SELECT answered_by FROM messages WHERE id = $w3"), '');
$r = laneSwitchManual($boss, $mn, 'chat');
check('💬 — מה שממתין ל-Platform חוזר לצ\'אט', [$r['moved'], $col("SELECT answered_by FROM messages WHERE id = $w1")], [2, '']);
throws('אין מה להעביר — נדחה', fn () => laneSwitchManual($boss, $mn, 'chat'), 'אין הודעות');
breakerTrip(1, 'credit_exhausted', 'Your credit balance is too low');
throws('המפסק פתוח — ☁️ נדחה, ואומר למה', fn () => laneSwitchManual($boss, $mn, 'platform'), 'Platform נעצר');
breakerReset(1);
$member = ['id' => 99, 'role' => 'user', 'username' => 'm', 'display_name' => 'm'];
throws('רק בעל הלוח', fn () => laneSwitchManual($member, $mn, 'platform'), 'בעל הלוח');

echo "\n— התגים: מ-handled_by / answered_by / tasks.route — לא מהטקסט —\n";
$t = $mk('תגים');
$fake = channelSend($boss, $t, '☁️ Platform ענה על זה כבר. טופל ✓');
$row = messageRows('m.id = ?', [$fake])[0];
check('טקסט שאומר "Platform ענה" — עדיין 💬 ממתין לצ\'אט', [$row['lane'], $row['lane_state']], ['chat', 'chat']);
channelAck(getChannel($t), [$fake]);
$row = messageRows('m.id = ?', [$fake])[0];
check('אחרי ack — 💬 טופל', [$row['lane'], $row['lane_state']], ['chat', 'done']);
$pl = channelSend($boss, $t, 'שאלה');
db()->prepare("UPDATE messages SET answered_by = 'platform', handled_by = 'platform' WHERE id = ?")->execute([$pl]);
$row = messageRows('m.id = ?', [$pl])[0];
check('handled_by=platform — ☁️ ממתין ל-Platform', [$row['lane'], $row['lane_state']], ['platform', 'platform']);
db()->prepare("INSERT INTO messages (channel_id, direction, author_role, kind, body, answered_by, meta, status, created_at)
               VALUES (?, 'out', 'platform', 'choice', 'שאלת בחירה', '', '{}', 'open', ?)")->execute([$t, nowTs()]);
$row = messageRows('m.id = ?', [(int) db()->lastInsertId()])[0];
check('שאלה פתוחה של Platform — ☁️ ממתין לבעל הלוח', [$row['lane'], $row['lane_state']], ['platform', 'owner']);
$tk = getTask(db(), $tid);
check('מטלה שרצה ב-💬 — 💬', taskLane($tk), 'chat');
db()->prepare("UPDATE tasks SET status = 'open', route = '' WHERE id = ?")->execute([$tid]);
db()->exec("UPDATE projects SET route = 'platform' WHERE id = $root");
check('מטלה פתוחה בפרויקט ☁️ — 🤖 (המנוע)', taskLane(getTask(db(), $tid)), 'engine');
db()->prepare("UPDATE tasks SET title = '💬 הצ''אט יטפל' WHERE id = ?")->execute([$tid]);
check('…גם כשהכותרת אומרת אחרת', taskLane(getTask(db(), $tid)), 'engine');
db()->exec("UPDATE projects SET route = 'chat' WHERE id = $root");

echo "\n— היומן לבעל הלוח —\n";
$log = laneLogFor($boss, $p);
check('מהחדש לישן, עם הסבר בעברית', [$log[0]['reason'], $log[0]['reason_text'], count($log)], ['recovered', 'חזר', 9]);

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
