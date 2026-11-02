<?php
/**
 * §5.10.1 — גרסת הגשר, "ממתין לאישור באפליקציה", הוראת עדכון, ורישום מחדש אחרי החלפת קונטיינר.
 * (צד הצ'אט — hooks, fail-safe ועותק הריצה — נבדק מקצה לקצה מול לוח מקומי; ראו §14.)
 *
 *   php claude-tasks/tests/bridgesafety.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/ctbs-' . getmypid();
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
function throws(string $name, callable $fn, int $status = 0) {
    try { $fn(); check($name, 'לא נזרקה חריגה', 'חריגה'); }
    catch (AppError $e) { check($name . ' — ' . $e->getMessage(), $status === 0 || $e->status === $status, true); }
}
function msgs(int $ch, string $where = '1'): array {
    $st = db()->prepare("SELECT * FROM messages WHERE channel_id = ? AND $where ORDER BY id");
    $st->execute([$ch]);
    return $st->fetchAll();
}

createUser('boss', 'pass-12345', 'בעל הלוח');
createUser('dana', 'pass-12345', 'דנה');
$boss = ['id' => 1, 'role' => 'admin', 'username' => 'boss', 'display_name' => 'בעל הלוח'];
$dana = ['id' => 2, 'role' => 'member', 'username' => 'dana', 'display_name' => 'דנה'];
$root = createProject($boss, 'Mini projects', 'malkiel01', 'mini_projects');
$sub  = createSubproject($boss, $root, 'סטודיו', 'studio');
setMember($boss, $root, 2, 'write');
$scope = ['project_id' => null, 'channel_id' => null];
$r = registerChannel($scope, $sub, 'סטודיו - מתכלל', 'lead', 'session_bs_a', 'https://claude.ai/code/session_bs_a');
$ch = $r['channel_id'];

echo "\n— גרסת הגשר —\n";
check('הרישום מחזיר שם ופרויקט (לרישום השקט)', [$r['name'], $r['project_id']], ['סטודיו - מתכלל', $sub]);
check('בלי דיווח — "גרסה ישנה"', publicChannel(getChannel($ch))['bridge_outdated'], true);
channelHealth(getChannel($ch), 1000, 3, 0, 'nonsense; drop table');
check('ערך לא תקין — לא נשמר', getChannel($ch)['bridge_version'], '');
channelHealth(getChannel($ch), 1000, 3, 0, BRIDGE_VERSION_CURRENT);
check('גרסה נוכחית — לא מסומן', publicChannel(getChannel($ch))['bridge_outdated'], false);
channelHealth(getChannel($ch), 1000, 3, 0, '2026-10-01.1');
check('גרסה אחרת — מסומן', publicChannel(getChannel($ch))['bridge_outdated'], true);
check('…ובשורה ברשימה', channelsFor($boss, $root)[0]['bridge_outdated'], true);
$bash = (string) file_get_contents(__DIR__ . '/../bridge/lib.sh');
check('הגרסה ב-lib.sh = הגרסה בשרת', str_contains($bash, 'BRIDGE_VERSION="' . BRIDGE_VERSION_CURRENT . '"'), true);

echo "\n— 📨 הוראת עדכון —\n";
throws('דנה — לא שולחת', fn () => bridgeSendUpdate($dana, $ch), 403);
$id = bridgeSendUpdate($boss, $ch)['id'];
$m = msgs($ch, 'id = ' . (int) $id)[0];
check('הודעת owner נכנסת לצ\'אט', [$m['direction'], $m['author_role']], ['in', 'owner']);
check('…עם הפקודה, בלי commit', [str_contains($m['body'], 'update.sh'), str_contains($m['body'], 'commit')], [true, true]);
check('…ומעירה את המאזין', count(channelInbox(getChannel($ch))) >= 1, true);

echo "\n— ⏸ ממתין לאישור באפליקציה —\n";
check('ראשונה — נשלחת הודעת system', channelWaiting(getChannel($ch), 'mcp__x__create_trigger'), true);
check('אותו כלי שוב — לא', channelWaiting(getChannel($ch), 'mcp__x__create_trigger'), false);
$w = msgs($ch, "body LIKE '⏸%'");
check('הודעה אחת, עם קישור לסשן', [count($w), str_contains($w[0]['body'], 'https://claude.ai/code/session_bs_a')], [1, true]);
check('kind status — לא מעירה את הצ\'אט', $w[0]['kind'], 'status');
check('בשורה: ⏸ ושם הכלי', [channelsFor($boss, $root)[0]['waiting_at'] > 0, channelsFor($boss, $root)[0]['waiting_tool']], [true, 'mcp__x__create_trigger']);
channelPost(getChannel($ch), 'system', 'שורת מערכת');
check('system לא מנקה', getChannel($ch)['waiting_at'] > 0, true);
channelPost(getChannel($ch), 'chat', 'המשכתי');
check('הצ\'אט כתב — מתנקה', (int) getChannel($ch)['waiting_at'], 0);
check('כלי אחר אחר כך — שוב הודעה', channelWaiting(getChannel($ch), 'Bash'), true);

echo "\n— רישום מחדש אחרי החלפת קונטיינר —\n";
check('סשן רשום — הפרויקט, השם והתפקיד שלו', sessionChannelDefaults('session_bs_a'), ['project_id' => $sub, 'name' => 'סטודיו - מתכלל', 'role' => 'lead']);
check('סשן לא מוכר — כלום', sessionChannelDefaults('session_nope_1'), null);
$d = sessionChannelDefaults('session_bs_a');
$again = registerChannel($scope, $d['project_id'], $d['name'], $d['role'], 'session_bs_a', '');
check('אותו ערוץ, אסימון חדש', [$again['channel_id'], $again['renewed'], $again['channel_token'] !== $r['channel_token']], [$ch, true, true]);

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
