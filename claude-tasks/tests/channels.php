<?php
/**
 * שלב 2 באפיון (docs/spec.md, 5) — הגשר החי, צד השרת: ערוצים, הודעות,
 * אסימון ערוץ, תיבה אחת, המתנה על פעמון, והקישור בין מטלה לערוץ.
 *
 *   php claude-tasks/tests/channels.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/ctc-' . getmypid();
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
/** workerScope כפי שהשרת רואה אותו, לאסימון נתון. */
function scopeOf(string $token): ?array {
    $_SERVER['HTTP_X_WORKER_TOKEN'] = $token;
    unset($_SERVER['HTTP_X_WORKER_NAME']);
    return workerScope();
}
function task(string $title, ?int $project, string $status = 'open'): int {
    db()->prepare('INSERT INTO tasks (title, project_id, status, created_by, created_at, updated_at) VALUES (?,?,?,1,?,?)')
        ->execute([$title, $project, $status, nowTs(), nowTs()]);
    return (int) db()->lastInsertId();
}

createUser('boss', 'pass-12345', 'בעל הלוח');
createUser('dana', 'pass-12345', 'דנה');
createUser('rami', 'pass-12345', 'רמי');
$boss = ['id' => 1, 'role' => 'admin', 'username' => 'boss', 'display_name' => 'בעל הלוח'];
$dana = ['id' => 2, 'role' => 'member', 'username' => 'dana', 'display_name' => 'דנה'];
$rami = ['id' => 3, 'role' => 'member', 'username' => 'rami', 'display_name' => 'רמי'];

$root  = createProject($boss, 'mini_projects', 'malkiel01', 'mini_projects');
$suite = createSubproject($boss, $root, 'חבילת היצירה', 'creative-suite');
$app   = createSubproject($boss, $root, 'מתכונים', 'recipes-app');
$other = createProject($boss, 'ריפו אחר', 'someone', 'else');
setMember($boss, $root, 2, 'write');
setMember($boss, $root, 3, 'read');
db()->prepare('UPDATE projects SET owner_only = 1 WHERE id = ?')->execute([$suite]);
$regToken = bin2hex(random_bytes(16));
db()->prepare('UPDATE projects SET agent_token = ? WHERE id = ?')->execute([encryptSecret($regToken), $root]);
$regScope = scopeOf($regToken);

echo "\n— הרשמה —\n";
check('אסימון ההרשמה = אסימון הפרויקט', $regScope['project_id'] ?? null, $root);
$r = registerChannel($regScope, $suite, 'היצירה v2 - המתכלל', 'מתכלל', 'session_ABC123', 'https://claude.ai/code/session_ABC123');
check('נוצר ערוץ, ואסימון ch_', [$r['renewed'], str_starts_with($r['channel_token'], 'ch_')], [false, true]);
$cid = $r['channel_id']; $tok1 = $r['channel_token'];
check('האסימון לא נשמר — רק גיבוב', db()->query("SELECT token_hash FROM channels WHERE id = $cid")->fetch()['token_hash'],
      channelTokenHash($tok1));
throws('פרויקט מחוץ לענף של אסימון ההרשמה — נדחה', fn () => registerChannel($regScope, $other, 'x', '', 'session_OTHER1', ''), 403);
throws('session_id לא תקין — נדחה', fn () => registerChannel($regScope, $app, 'x', '', 'a b', ''));
$ch1 = scopeOf($tok1);
check('אסימון ערוץ מזוהה — עם הערוץ והענף', [$ch1['channel_id'] ?? null, $ch1['project_id'] ?? null, $ch1['name'] ?? null],
      [$cid, $suite, "channel-$cid"]);
throws('אסימון ערוץ לא רושם ערוצים', fn () => registerChannel($ch1, $suite, 'x', '', 'session_XYZ999', ''), 403);

$r2 = registerChannel($regScope, $suite, 'היצירה v2 - המתכלל', 'מתכלל', 'session_ABC123', '');
check('אותו session_id → אותו ערוץ, אסימון חדש', [$r2['channel_id'], $r2['renewed'], $r2['channel_token'] !== $tok1], [$cid, true, true]);
check('האסימון הישן נפסל', scopeOf($tok1), null);
$tok = $r2['channel_token'];
$ch = scopeOf($tok)['channel'];

echo "\n— צ'אט ← לוח (out) —\n";
$m1 = channelPost($ch, 'owner', 'מה המצב עם הרסטר?');
$m2 = channelPost($ch, 'chat', 'עובד על זה', $m1);
throws('author_role לא מוכר — נדחה', fn () => channelPost($ch, 'admin', 'x'));
throws('הודעה ריקה — נדחית', fn () => channelPost($ch, 'chat', '   '));
$t_app = task('מטלה של מתכונים', $app);
throws('task_id מחוץ לענף של הערוץ — נדחה', fn () => channelPost($ch, 'chat', 'x', null, $t_app), 403);
$long = channelPost($ch, 'chat', str_repeat('א', MAX_MESSAGE + 50));
check('הודעה ארוכה נחתכת עם סימון', str_ends_with(db()->query("SELECT body FROM messages WHERE id = $long")->fetch()['body'], '…(truncated)'), true);
check('out לא נכנס ל-inbox', channelInbox($ch), []);

echo "\n— לוח ← צ'אט (in), inbox ו-ack —\n";
throws('צופה לא כותב לצ\'אט', fn () => channelSend($rami, $cid, 'x'), 403);
throws('write בענף owner_only — לא כותב', fn () => channelSend($dana, $cid, 'x'), 403);
$in1 = channelSend($boss, $cid, 'תעצור ותסכם');
$in2 = channelSend($boss, $cid, 'ותוסיף בדיקות');
$inbox = channelInbox($ch);
check('inbox מחזיר את שתיהן, בסדר', array_column($inbox, 'id'), [$in1, $in2]);
check('עם author_role owner ושם הכותב', [$inbox[0]['author_role'], $inbox[0]['author_name']], ['owner', 'בעל הלוח']);
check('inbox לא מסמן', count(channelInbox($ch)), 2);
check('ack מסמן רק את מה שנשלח', channelAck($ch, [$in1, 99999]), 1);
check('אחרי ack — רק השנייה', array_column(channelInbox($ch), 'id'), [$in2]);
$r3 = registerChannel($regScope, $app, 'מתכונים', 'קוד', 'session_RECIPE1', '');
$chApp = scopeOf($r3['channel_token'])['channel'];
check('ack של ערוץ אחר לא נוגע', channelAck($chApp, [$in2]), 0);
check('write בפרויקט רגיל — כותבת', is_int(channelSend($dana, $r3['channel_id'], 'שלום')), true);
channelAck($ch, [$in2]);

echo "\n— המתנה (channel-wait) —\n";
$ticks = 0;
$in3 = channelSend($boss, $cid, 'יש הודעה');
$got = channelWait($ch, 20, function () use (&$ticks) { $ticks++; });
check('יש הודעה — חוזר מיד, בלי לחכות', [array_column($got, 'id'), $ticks], [[$in3], 0]);
channelAck($ch, [$in3]);
$ticks = 0; $in4 = null;
$got = channelWait($ch, 20, function () use (&$ticks, &$in4, $boss, $cid) {
    $ticks++;
    if ($ticks === 3) $in4 = channelSend($boss, $cid, 'הגיעה באמצע ההמתנה');
});
check('הודעה באמצע ההמתנה — חוזר כשהפעמון מצלצל', [array_column($got, 'id'), $ticks], [[$in4], 3]);
channelAck($ch, [$in4]);
$ticks = 0;
check('אין הודעה — מחזיר ריק אחרי wait', [channelWait($ch, 4, function () use (&$ticks) { $ticks++; }), $ticks], [[], 4]);
configSet(['long_poll_max' => 2]);
$ticks = 0;
channelWait($ch, 20, function () use (&$ticks) { $ticks++; });
check('long_poll_max מגביל את ההמתנה', $ticks, 2);
configSet(['long_poll_max' => 0]);
$ticks = 0;
channelWait($ch, 20, function () use (&$ticks) { $ticks++; });
check('long_poll_max = 0 — בלי המתנה (polling רגיל)', $ticks, 0);
check('ההמתנה רושמת listening_until', (int) db()->query("SELECT listening_until FROM channels WHERE id = $cid")->fetch()['listening_until'] > nowTs(), true);

echo "\n— מטלה ↔ ערוץ —\n";
$t = task('לתקן את הבורר', $raster = createSubproject($boss, $suite, 'רסטר', 'raster-studio'));
channelAsked($ch, $t, 'איזה צבע ברירת מחדל?');
check('המטלה זוכרת את הערוץ', (int) getTask(db(), $t)['asked_channel_id'], $cid);
$q = messageRows("m.channel_id = ? AND m.task_id = ?", [$cid, $t]);
check('השאלה מופיעה בערוץ — out, question', [$q[0]['direction'], $q[0]['kind'], $q[0]['author_role']], ['out', 'question', 'chat']);
db()->prepare("UPDATE tasks SET status = 'blocked' WHERE id = ?")->execute([$t]);
$mid = channelAnswered(getTask(db(), $t), $boss, 'כחול');
$a = messageRows('m.id = ?', [$mid]);
check('תשובת owner → הודעה נכנסת לערוץ ששאל', [$a[0]['direction'], $a[0]['kind'], $a[0]['author_role'], $a[0]['task_id']], ['in', 'answer', 'owner', $t]);
check('מטלה שלא נשאלה מערוץ — אין הודעה', channelAnswered(getTask(db(), $t_app), $boss, 'x'), null);

echo "\n— ממשק —\n";
$list = channelsFor($boss, $root);
$mine = array_values(array_filter($list, fn ($c) => $c['id'] === $cid))[0];
check('channels — כל הענף, בלי הגיבוב', [count($list), array_key_exists('token_hash', $mine)], [2, false]);
check('מונים: ממתינה אחת, שאלה פתוחה אחת', [$mine['pending_in'], $mine['open_questions']], [1, 1]);
createUser('zvi', 'pass-12345', 'צבי');
throws('channels — לא חבר לא רואה', fn () => channelsFor(['id' => 4, 'role' => 'member', 'username' => 'zvi'], $root), 403);
$msgs = channelMessages($rami, $cid, $m2);
check('channel-messages?after — רק מה שאחרי', $msgs['messages'][0]['id'] > $m2, true);
check('can_send לצופה — לא', $msgs['channel']['can_send'], false);

echo "\n— ניתוק —\n";
throws('member לא מנתק', fn () => archiveChannel($cid, $dana), 403);
archiveChannel($r3['channel_id'], null, $chApp);
check('הערוץ מנתק את עצמו — האסימון נפסל', scopeOf($r3['channel_token']), null);
archiveChannel($cid, $boss);
check('בעל הלוח מנתק — האסימון נפסל', scopeOf($tok), null);
throws('לערוץ מנותק לא שולחים', fn () => channelSend($boss, $cid, 'x'), 409);
$back = registerChannel($regScope, $suite, 'היצירה v2 - המתכלל', 'מתכלל', 'session_ABC123', '');
check('רישום מחדש מחזיר את הערוץ לפעילות', [$back['channel_id'], getChannel($cid)['status']], [$cid, 'active']);

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
