<?php
/**
 * שלב 18 (docs/spec.md §15.7, §15.11, §15.11a, §15.12.3) — ברירות המחדל הבטוחות, התקרה הדו-צדדית, עקיפה לפרויקט,
 * מטלת מעבר שלא רצה בלי אישור, ומטריצת דרישה ב: פעולה רגישה בכל מסלול → בקשה בלוח או דחייה עם סיבה, אף פעם לא בשקט.
 *
 *   php claude-tasks/tests/lanes18.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/ctl18-' . getmypid();
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
$sub = createProject($boss, 'רסטר', 'malkiel01', 'mini_projects');
$leaf = createProject($boss, 'רסטר — מסננים', 'malkiel01', 'mini_projects');
db()->prepare('UPDATE projects SET parent_id = ? WHERE id = ?')->execute([$root, $sub]);
db()->prepare('UPDATE projects SET parent_id = ? WHERE id = ?')->execute([$sub, $leaf]);
db()->prepare("UPDATE projects SET path = 'claude-tasks' WHERE id = ?")->execute([$root]);
addConnection($boss, 'anthropic', 'sk-ant-bossbossbossbossboss', 'אישי', 'claude-opus-5-5');
setQuota($boss, 1, 25);
$regToken = bin2hex(random_bytes(16));
db()->prepare('UPDATE projects SET agent_token = ? WHERE id = ?')->execute([encryptSecret($regToken), $root]);
$_SERVER['HTTP_X_WORKER_TOKEN'] = $regToken;
$mk = function (string $name, ?int $pid = null) use ($root) {
    static $n = 0; $n++;
    $c = getChannel(registerChannel(workerScope(), $pid ?? $root, $name, 'קוד', "session_L18_$n", '')['channel_id']);
    db()->prepare('UPDATE channels SET listening_until = 0 WHERE id = ?')->execute([$c['id']]);
    return (int) $c['id'];
};

echo "\n— ברירות המחדל: העמודה הבטוחה —\n";
check('לשאול · רק תשובות · שתיקה 10 · בלי חזרה באמצע', array_intersect_key(lanePrefs(1), array_flip(['on_stop', 'engine', 'silence_minutes', 'return_preferred'])),
      ['on_stop' => 'ask', 'return_preferred' => 'no', 'silence_minutes' => '10', 'engine' => 'answers']);
check('בלי תקרה — אין מעבר אוטומטי שעולה כסף', [autoCeilingLeft(1, $root)['left'], !empty(autoCeilingLeft(1, $root)['none'])], [0.0, true]);
$a = $mk('לא פתח הגדרות');
channelSend($boss, $a, 'x'); $tick(11 * 60);
check('משתמש שלא פתח את ההגדרות — כרטיס, לא מעבר', [silenceCheck(getChannel($a)), $col("SELECT COUNT(*) FROM messages WHERE channel_id = $a AND answered_by = 'platform'")], ['card', 0]);
setLanePrefs($boss, ['on_stop' => 'auto']);
$b = $mk('אוטומטי בלי תקרה');
channelSend($boss, $b, 'x'); $tick(11 * 60);
check('"אוטומטי" בלי תקרה — עדיין כרטיס, ואומר איפה מגדירים', [silenceCheck(getChannel($b)),
      str_contains((string) $col('SELECT meta FROM messages WHERE id = ' . (int) getChannel($b)['silence_card_id']), 'הגדרות ← 🔀 מסלולים')], ['card', true]);

echo "\n— התקרה: אכיפה דו-צדדית, עם שם מי שחוסם —\n";
throws('התקרה שלי מעל המכסה — נדחית', fn () => setUserCeiling($boss, 30), 'חלק מהמכסה');
check('התקרה שלי — 10', setUserCeiling($boss, 10)['ceiling'], 10.0);
throws('תת-פרויקט מעל התקרה שלי — נדחה', fn () => setProjectCeiling($boss, $sub, 12), 'התקרה של המשלם');
check('תת-פרויקט 6', setProjectCeiling($boss, $sub, 6)['ceiling'], 6.0);
throws('תת-תת-פרויקט מעל ההורה — נדחה, בשם ההורה', fn () => setProjectCeiling($boss, $leaf, 7), '"רסטר"');
check('תת-תת-פרויקט 4', setProjectCeiling($boss, $leaf, 4)['ceiling'], 4.0);
throws('הורה שיורד מתחת לילד — נדחה, בשם הילד', fn () => setProjectCeiling($boss, $sub, 3), '"רסטר — מסננים"');
throws('התקרה שלי מתחת לפרויקט — נדחית, בשם הפרויקט', fn () => setUserCeiling($boss, 5), '"רסטר"');
check('ביטול התקרה של הילד — ואז ההורה יכול לרדת', [setProjectCeiling($boss, $leaf, 0)['ceiling'], setProjectCeiling($boss, $sub, 3)['ceiling']], [0.0, 3.0]);
$member = ['id' => 99, 'role' => 'user', 'username' => 'm', 'display_name' => 'm'];
throws('תקרה לפרויקט — רק בעל הלוח', fn () => setProjectCeiling($member, $sub, 1), 'בעל הלוח');
check('הקרובה חוסמת: בתת-הפרויקט — "רסטר"', autoCeilingLeft(1, $leaf)['by'], 'רסטר');
check('…ובשורש — התקרה שלי', autoCeilingLeft(1, $root)['by'], 'התקרה שלך');

echo "\n— התקרה והמכסה נבדקות בשרת בכל שימוש אוטומטי —\n";
$c = $mk('תקרה', $sub);
channelSend($boss, $c, 'x'); $tick(11 * 60);
check('יש תקרה ונשאר — עובר אוטומטית', silenceCheck(getChannel($c)), 'moved');
db()->prepare('INSERT INTO usage (at, user_id, project_id, kind, provider, model, cost_usd, auto) VALUES (?,1,?,?,?,?,?,1)')
    ->execute([nowTs(), $sub, 'answer', 'anthropic', 'claude-opus-5-5', 2.99]);
$d = $mk('אחרי שהוצא', $sub);
channelSend($boss, $d, 'x'); $tick(11 * 60);
check('מה שנשאר בתקרת "רסטר" קטן מתשובה — כרטיס', silenceCheck(getChannel($d)), 'card');
db()->prepare('INSERT INTO usage (at, user_id, project_id, kind, provider, model, cost_usd) VALUES (?,1,?,?,?,?,?)')
    ->execute([nowTs(), $root, 'develop', 'anthropic', 'claude-opus-5-5', 30]);
$e = $mk('מכסה');
channelSend($boss, $e, 'x'); $tick(11 * 60);
check('מכסה 100% — כרטיס גם בשורש', silenceCheck(getChannel($e)), 'card');
db()->exec('DELETE FROM usage');

echo "\n— עקיפה לפרויקט (בעל הלוח) —\n";
setLanePrefs($boss, ['on_stop' => 'auto', 'silence_minutes' => '10']);
setProjectLanePrefs($boss, $sub, ['on_stop' => 'wait', 'silence_minutes' => '30']);
check('בתת-הפרויקט: "להמתין" ו-30 דק\' — דורס את שלי', [laneEffectivePrefs(1, $leaf)['on_stop'], laneEffectivePrefs(1, $leaf)['silence_minutes']], ['wait', '30']);
check('…בשורש — שלי', laneEffectivePrefs(1, $root)['on_stop'], 'auto');
$f = $mk('עקיפה', $leaf);
channelSend($boss, $f, 'x'); $tick(20 * 60);
check('20 דק\' — עוד לא (העקיפה: 30)', silenceCheck(getChannel($f)), '');
$tick(11 * 60);
check('31 דק\' — "להמתין" (העקיפה), לא מעבר', silenceCheck(getChannel($f)), 'waiting');
setProjectLanePrefs($boss, $sub, ['on_stop' => '', 'silence_minutes' => '']);
check('"כמו המשלם" — העקיפה בוטלה', projectLanePrefs($sub)['prefs'], []);
throws('עקיפה — רק בעל הלוח', fn () => setProjectLanePrefs($member, $sub, ['on_stop' => 'auto']), 'בעל הלוח');
throws('ערך לא מוכר', fn () => setProjectLanePrefs($boss, $sub, ['engine' => 'always']));

echo "\n— מטלת מנוע מתוך מעבר (origin: failover) —\n";
$g = $mk('מעבר');
$q = channelSend($boss, $g, 'תתקן את הבאג');
db()->prepare('UPDATE channels SET unresponsive_since = ?, lane_decision = ? WHERE id = ?')->execute([nowTs(), 'platform', $g]);
db()->prepare("INSERT INTO messages (channel_id, direction, author_role, kind, body, reply_to, answered_by, created_at) VALUES (?, 'out', 'platform', 'answer', 'צריך קוד', ?, '', ?)")
    ->execute([$g, $q, nowTs()]);
$ans = (int) db()->lastInsertId();
check('מטלה מתשובה של Platform בזמן מעבר — failover', taskOriginFromMessage($ans), 'failover');
check('…מהודעה של הצ\'אט — לא', taskOriginFromMessage($q), '');
db()->prepare("INSERT INTO tasks (title, project_id, status, created_by, created_at, updated_at, origin, asked_channel_id) VALUES ('לתקן באג', ?, 'open', 1, ?, ?, 'failover', ?)")
    ->execute([$root, nowTs(), nowTs(), $g]);
$tid = (int) db()->lastInsertId();
$getP = fn () => requireProject($root, $boss, 'read');
$project = $getP();
throws('ההגדרה "רק תשובות" (ברירת המחדל) — נדחה, עם הסבר', fn () => failoverRunCheck($boss, getTask(db(), $tid), $project), 'רק בטקסט');
setLanePrefs($boss, ['engine' => 'card']);
db()->prepare("UPDATE projects SET engine = 'builtin' WHERE id = ?")->execute([$root]);
throws('מנוע בלי השער — נדחה', fn () => failoverRunCheck($boss, getTask(db(), $tid), $getP()), 'רק במנוע Claude Code');
db()->prepare("UPDATE projects SET engine = 'claude-code' WHERE id = ?")->execute([$root]);
$r = failoverRunCheck($boss, getTask(db(), $tid), $getP());
check('"מנוע אחרי כרטיס" — לא רץ: כרטיס אישור', [$r['needs_approval'], getTask(db(), $tid)['status'], lastApproval($tid)['type']], [true, 'awaiting_approval', 'engine-failover']);
check('…ניסיון שני לפני אישור — עדיין לא רץ, בלי כרטיס שני', [failoverRunCheck($boss, getTask(db(), $tid), $getP())['needs_approval'],
      (int) $col("SELECT COUNT(*) FROM notes WHERE task_id = $tid AND kind = 'approval'")], [true, 1]);
throws('"תמיד" — אין לסוג הזה', fn () => decide($boss, getTask(db(), $tid), 'always'), 'רק פעם אחת');
decide($boss, getTask(db(), $tid), 'once');
check('אחרי "פעם אחת" — רץ (null = ממשיכים)', failoverRunCheck($boss, getTask(db(), $tid), $getP()), null);
db()->prepare("INSERT INTO tasks (title, project_id, status, created_by, created_at, updated_at) VALUES ('רגילה', ?, 'open', 1, ?, ?)")->execute([$root, nowTs(), nowTs()]);
check('מטלה רגילה — לא נוגעים', failoverRunCheck($boss, getTask(db(), (int) db()->lastInsertId()), $getP()), null);

echo "\n— מטריצת דרישה ב: פעולה רגישה בכל מסלול → בקשה בלוח או דחייה עם סיבה —\n";
$ch = $mk('מטריצה');
// 💬 צ'אט (שלב 13): בקשת הרשאה → כרטיס בלוח
$p = permissionPost(getChannel($ch), ['tool_name' => 'Bash', 'tool_input' => ['command' => 'rm -rf /repo/raster-studio', 'description' => 'ניקוי']]);
check('💬 צ\'אט — בקשת הרשאה נכנסת ללוח (כרטיס פתוח)', [$p['id'] > 0, $col("SELECT status FROM messages WHERE id = " . (int) $p['id'])], [true, 'open']);
// 🔁 Routine / 🤖 מנוע (שלב 14): השער — ask עם כרטיס, או deny עם סיבה
$hook = ['root' => '/repo', 'branch' => 'feature-x'];
$gr = gateCheck(getChannel($ch), null, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'git push --force origin main'], 'permission_mode' => 'auto'] + $hook);
check('🔁 Routine — push בכוח: כרטיס או דחייה, עם סיבה', [in_array($gr['verdict'], ['ask', 'deny'], true), ($gr['message_id'] ?? 0) > 0 || trim((string) ($gr['reason'] ?? '')) !== ''], [true, true]);
$ge = gateCheck(null, getTask(db(), $tid), ['tool_name' => 'Bash', 'tool_input' => ['command' => 'cat .env'], 'permission_mode' => 'bypassPermissions'] + $hook, 'claude-code');
check('🤖 מנוע — קריאת סודות: לא רץ בשקט', [in_array($ge['verdict'], ['ask', 'deny'], true), ($ge['message_id'] ?? 0) > 0 || trim((string) ($ge['reason'] ?? '')) !== ''], [true, true]);
// ☁️ Platform: טקסט בלבד — הבקשה לספק בלי כלים, וההוראה אומרת שאין לו כלים
$sent = null;
$spy = function ($provider, $url, $json) use (&$sent) { $sent = json_decode($json, true);
    return ['status' => 200, 'body' => json_encode(['content' => [['type' => 'text', 'text' => '{"reply": "אני לא יכול למחוק — אין לי כלים."}']], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]])]; };
db()->exec("UPDATE projects SET route = 'platform' WHERE id = $root");
$pm = channelSend($boss, $ch, 'תמחק את התיקייה raster-studio');
$pr = platformReply($boss, $pm, $spy);
check('☁️ Platform — הבקשה לספק בלי tools, וההוראה: "אין לך שום כלי"', [isset($sent['tools']), str_contains(json_encode($sent, JSON_UNESCAPED_UNICODE), 'אין לך שום כלי')], [false, true]);
check('…ותשובה בטקסט בלבד, בלי פעולה', [$pr['answered'], $pr['message']['kind']], [true, 'answer']);

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
