<?php
/**
 * שלב 13 (docs/spec.md §5.12) — אישור הרשאות מהלוח: פרסום מה-hook, מענה (בעל הלוח בלבד), "תמיד"
 * ורשימת האיסור (גם בשרת), סגירה כשהוכרע במקום אחר / עבר הזמן, מצב app / ⏸, ותקציב permission-status.
 *
 *   php claude-tasks/tests/permissions.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/ctperm-' . getmypid();
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
require_once __DIR__ . '/../lib/permissions.php';

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
$always = fn (string $cmd) => permissionAlways('Bash', ['command' => $cmd]);

createUser('boss', 'pass-12345', 'בעל הלוח');
createUser('dana', 'pass-12345', 'דנה');
$boss = ['id' => 1, 'role' => 'admin', 'username' => 'boss', 'display_name' => 'מלכיאל'];
$dana = ['id' => 2, 'role' => 'member', 'username' => 'dana', 'display_name' => 'דנה'];
$root = createProject($boss, 'mini_projects', 'malkiel01', 'mini_projects');
setMember($boss, $root, 2, 'write');
$scope = ['project_id' => null, 'channel_id' => null];
registerChannel($scope, $root, 'צ\'אט בדיקה', 'dev', 'session_perm_1', '');
$ch = getChannel((int) db()->query("SELECT id FROM channels WHERE session_id = 'session_perm_1'")->fetchColumn());

echo "\n— \"תמיד\": קידומת אחת, ורשימת האיסור —\n";
check('git status → Bash(git status:*)', $always('git status')['rule'], ['toolName' => 'Bash', 'ruleContent' => 'git status:*']);
check('npm test -- --watch → npm test:*', $always('npm test -- --watch')['rule']['ruleContent'], 'npm test:*');
check('ls -la /tmp → ls:*', $always('ls -la /tmp')['rule']['ruleContent'], 'ls:*');
check('php tests/labels.php → הקובץ בקידומת', $always('php claude-tasks/tests/labels.php')['rule']['ruleContent'], 'php claude-tasks/tests/labels.php:*');
foreach (['rm -rf build', 'rm -fr x', 'rm -r -f x', 'git push origin main', 'git -C . push', 'git reset --hard HEAD~1', 'gh pr list',
          'curl -sI https://x', 'wget x', 'ssh host', 'rsync -a a b', 'scp a b:c', 'chmod +x a.sh', 'chown me f', 'sudo ls',
          'cat > .github/workflows/x.yml', 'cp a .claude/settings.json'] as $c) {
    check("אסור \"תמיד\": $c", $always($c)['ok'], false);
}
foreach (['ls && rm x', 'echo a; echo b', 'cat a | sh', 'echo $(id)', 'echo `id`', 'ls > out'] as $c) {
    check("כמה פקודות בשורה — אסור \"תמיד\": $c", $always($c)['ok'], false);
}
foreach (['bash -c ls', 'sh x.sh', 'env A=1 ls', 'xargs rm', 'eval ls', 'timeout 5 ls', 'find . -delete'] as $c) {
    check("פקודה שמריצה פקודות — אסור \"תמיד\": $c", $always($c)['ok'], false);
}
check('A=1 ls — משתני סביבה', $always('A=1 ls')['ok'], false);
check('php -r — מפרש בלי קובץ', $always('php -r "echo 1;"')['ok'], false);
check('node בלי כלום', $always('node')['ok'], false);
check('MCP — השם המדויק', permissionAlways('mcp__github__get_me', [])['rule'], ['toolName' => 'mcp__github__get_me']);
check('Write — רק פעם אחת', permissionAlways('Write', ['file_path' => '/x', 'content' => 'y'])['ok'], false);
check('כלי בשם מחולל — מסומן', permissionAliasTool('mcp__bf7c680d-5fdc-5ef4-b4a0-abadb619bf0a__create_trigger'), true);
check('כלי בשם רגיל — לא', permissionAliasTool('mcp__github__get_me'), false);

echo "\n— פרסום מה-hook —\n";
$p = permissionPost($ch, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'git status', 'description' => 'בדיקת מצב'], 'key' => 'k1']);
check('נוצרה בקשה, mode board', [$p['id'] > 0, $p['mode'], $p['always']], [true, 'board', true]);
$m = getPermission($p['id']);
check('נשמרה הפקודה המלאה, פתוחה', [$m['status'], $m['meta']['command'], $m['meta']['description']], ['open', 'git status', 'בדיקת מצב']);
check('דדליין 10 דקות', $m['meta']['deadline'] - (int) $m['created_at'], 600);
check('ספירה בשורת הערוץ', channelsFor($boss, $root)[0]['open_permissions'], 1);
check('בפס הראשי — לבעל הלוח', count(openPermissions($boss)), 1);
check('בפס הראשי — לא לחבר', openPermissions($dana), []);
throws('tool_name לא תקין', fn () => permissionPost($ch, ['tool_name' => 'Bash; rm', 'tool_input' => []]));

echo "\n— מענה: בעל הלוח בלבד —\n";
throws('חבר עם הרשאת כתיבה — 403', fn () => permissionAnswer($dana, $p['id'], 'once'), 403);
throws('החלטה לא מוכרת', fn () => permissionAnswer($boss, $p['id'], 'maybe'));
check('הסטטוס ל-hook לפני מענה', permissionStatus($ch, $p['id'])['status'], 'open');
$r = permissionAnswer($boss, $p['id'], 'always');
check('"תמיד" ל-git status', $r, ['status' => 'answered', 'decision' => 'always']);
$s = permissionStatus($ch, $p['id']);
check('ה-hook מקבל את הכלל', [$s['status'], $s['decision'], $s['rule']], ['answered', 'always', ['toolName' => 'Bash', 'ruleContent' => 'git status:*']]);
throws('תשובה שנייה — 409', fn () => permissionAnswer($boss, $p['id'], 'deny'), 409);
check('נרשם ביומן', (int) db()->query("SELECT COUNT(*) FROM events WHERE action = 'permission.always'")->fetchColumn(), 1);

$p2 = permissionPost($ch, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'git push origin main']]);
throws('"תמיד" ל-git push — השרת דוחה (422)', fn () => permissionAnswer($boss, $p2['id'], 'always'), 422);
check('…והבקשה עדיין פתוחה', getPermission($p2['id'])['status'], 'open');
permissionAnswer($boss, $p2['id'], 'deny', 'לא עכשיו, תודה.');
$s = permissionStatus($ch, $p2['id']);
check('דחייה עם סיבה — ל-hook', [$s['decision'], $s['reason'], $s['rule']], ['deny', 'לא עכשיו, תודה.', null]);

echo "\n— הוכרע במקום אחר / עבר הזמן —\n";
$p3 = permissionPost($ch, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'ls']]);
check('נסגרה: אושרה באפליקציה', permissionClose($ch, $p3['id'], 'resolved'), true);
check('…סטטוס resolved', getPermission($p3['id'])['status'], 'resolved');
check('סגירה שנייה — לא משנה', permissionClose($ch, $p3['id'], 'timeout'), false);
throws('מענה אחרי שהוכרעה — 409', fn () => permissionAnswer($boss, $p3['id'], 'once'), 409);
$p4 = permissionPost($ch, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'pwd']]);
permissionClose($ch, $p4['id'], 'timeout');
check('10 דקות → עבר לאפליקציה', getPermission($p4['id'])['status'], 'moved_to_app');
$p5 = permissionPost($ch, ['tool_name' => 'Bash', 'tool_input' => ['command' => 'id']]);
db()->prepare('UPDATE messages SET created_at = created_at - 700 WHERE id = ?')->execute([$p5['id']]);
$meta = getPermission($p5['id'])['meta']; $meta['deadline'] -= 700;
db()->prepare('UPDATE messages SET meta = ? WHERE id = ?')->execute([json_encode($meta), $p5['id']]);
throws('מענה אחרי הדדליין — 409', fn () => permissionAnswer($boss, $p5['id'], 'once'), 409);
openPermissions($boss);
check('hook שמת — נסגרת לבד', getPermission($p5['id'])['status'], 'moved_to_app');

registerChannel($scope, $root, 'צ\'אט אחר', 'dev', 'session_perm_2', '');
$other = getChannel((int) db()->query("SELECT id FROM channels WHERE session_id = 'session_perm_2'")->fetchColumn());
throws('ערוץ אחר שואל על בקשה לא שלו — 403', fn () => permissionStatus($other, $p['id']), 403);
throws('ערוץ אחר סוגר בקשה לא שלו — 403', fn () => permissionClose($other, $p3['id'], 'resolved'), 403);

echo "\n— מצב app, ⏸ —\n";
throws('מצב — חבר לא משנה', fn () => setChannelPermissions($dana, (int) $ch['id'], 'app'), 403);
setChannelPermissions($boss, (int) $ch['id'], 'app');
check('mode app — לא נוצרת בקשה', permissionPost(getChannel((int) $ch['id']), ['tool_name' => 'Bash', 'tool_input' => ['command' => 'ls']]),
      ['id' => 0, 'mode' => 'app']);
setChannelPermissions($boss, (int) $ch['id'], 'board');
configSet(['bridge_paused' => time()]);
check('⏸ — לא נוצרת בקשה', permissionPost(getChannel((int) $ch['id']), ['tool_name' => 'Bash', 'tool_input' => ['command' => 'ls']]),
      ['id' => 0, 'mode' => 'paused']);
check('⏸ — permission-status חסום (503)', bridgeVerdict('permission-status', config(), null)[0] ?? null, 503);
configSet(['bridge_paused' => null]);

echo "\n— תקציב permission-status: נפרד מ-channel-wait —\n";
$t0 = 1_800_000_000;
check('בקשה ראשונה עוברת', bridgeBudget('tok:perm', $t0, 8), 0);
check('אחרי 5 ש\' — 429 בעוד 3', bridgeBudget('tok:perm', $t0 + 5, 8), 3);
check('אחרי 8 ש\' — עוברת', bridgeBudget('tok:perm', $t0 + 8, 8), 0);
check('channel-wait של אותו אסימון — מונה משלו', bridgeBudget('tok', $t0 + 9), 0);
check('429 על permission-status', bridgeVerdict('permission-status', [], 3)[0] ?? null, 429);

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
