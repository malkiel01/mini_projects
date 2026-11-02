<?php
/**
 * שלב 12a (docs/spec.md §5.11) — ⚙ הגדרות הצ'אט: מידע, שם/תפקיד/צבע, מי עונה,
 * השתקה לכל משתמש, ניתוק וארכיון.
 *
 *   php claude-tasks/tests/chatsettings.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/ctcs-' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp));
@mkdir($tmp, 0777, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('CONFIG_FILE', $tmp . '/c.json');
define('SECRET_KEY_FILE', $tmp . '/secret.key');
define('BELL_DIR', $tmp . '/bell');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/projects.php';
require_once __DIR__ . '/../lib/channels.php';
require_once __DIR__ . '/../lib/monitor.php';

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

createUser('boss', 'pass-12345', 'בעל הלוח');
createUser('dana', 'pass-12345', 'דנה');
createUser('rami', 'pass-12345', 'רמי');
$boss = ['id' => 1, 'role' => 'admin', 'username' => 'boss', 'display_name' => 'בעל הלוח'];
$dana = ['id' => 2, 'role' => 'member', 'username' => 'dana', 'display_name' => 'דנה'];
$rami = ['id' => 3, 'role' => 'member', 'username' => 'rami', 'display_name' => 'רמי'];
$root = createProject($boss, 'Mini projects', 'malkiel01', 'mini_projects');
$sub  = createSubproject($boss, $root, 'רסטר', 'raster-studio');
setMember($boss, $root, 2, 'write');
setMember($boss, $root, 3, 'read');
$scope = ['project_id' => null, 'channel_id' => null];
$a = registerChannel($scope, $sub, 'היצירה v2 - רסטר', 'raster', 'session_cs_a', 'https://claude.ai/code/session_cs_a')['channel_id'];
$b = registerChannel($scope, $sub, 'צ\'אט שני', 'x', 'session_cs_b', '')['channel_id'];

echo "\n— ℹ️ על הצ'אט —\n";
$i = channelInfo($rami, $a);
check('גם צפייה בלבד רואה את המידע', [$i['name'], $i['project_path'], $i['project_id'], $i['session_url']],
      ['היצירה v2 - רסטר', 'Mini projects › רסטר', $sub, 'https://claude.ai/code/session_cs_a']);
check('…אבל לא עורכת ואינה בעלת הלוח', [$i['can_edit'], $i['is_owner']], [false, false]);
check('מצב ההתחלה: לא מושתק, בלוח, מסלול כמו הפרויקט, בלי צבע', [$i['muted'], $i['questions'], $i['route_own'], $i['color'], $i['hidden']],
      [false, 'board', '', '', false]);

echo "\n— ✏️ שם, תפקיד וצבע · 🧭 מי עונה —\n";
throws('צפייה בלבד — לא משנה שם', fn () => channelUpdate($rami, $a, ['name' => 'אחר']), 403);
$u = channelUpdate($dana, $a, ['name' => '  רסטר חדש  ', 'role' => 'raster', 'color' => 'violet']);
check('כתיבה בענף — משנה שם, תפקיד וצבע', [$u['name'], $u['color']], ['רסטר חדש', 'violet']);
throws('שם ריק — נדחה', fn () => channelUpdate($dana, $a, ['name' => '  ']));
throws('צבע שאינו מ-8 הקבועים — נדחה', fn () => channelUpdate($dana, $a, ['color' => '#ff0000']));
check('צבע ריק — חוזר ללפי התפקיד', channelUpdate($dana, $a, ['color' => ''])['color'], '');
check('סמל — אימוג\'י (גם עם מתאם)', [channelUpdate($dana, $a, ['icon' => '🧭'])['icon'], channelUpdate($dana, $a, ['icon' => '🖌️'])['icon']], ['🧭', '🖌️']);
throws('סמל — לא טקסט', fn () => channelUpdate($dana, $a, ['icon' => 'abc']));
throws('סמל — לא HTML', fn () => channelUpdate($dana, $a, ['icon' => '<b>']));
check('סמל ריק — חוזר ללפי התפקיד', channelUpdate($dana, $a, ['icon' => ''])['icon'], '');
throws('צפייה בלבד — לא משנה סמל', fn () => channelUpdate($rami, $a, ['icon' => '🎨']), 403);
throws('מי עונה — לא לדנה', fn () => channelUpdate($dana, $a, ['route' => 'platform']), 403);
$r = channelUpdate($boss, $a, ['route' => 'platform']);
check('בעל הלוח: ☁️ לצ\'אט הזה — גובר על הפרויקט', [$r['route_own'], $r['route_effective']], ['platform', 'platform']);
check('ריק — חוזר ל"כמו הפרויקט"', channelUpdate($boss, $a, ['route' => ''])['route_own'], '');
throws('מסלול לא מוכר', fn () => channelUpdate($boss, $a, ['route' => 'email']));

echo "\n— 🔔 השתקה — לכל משתמש —\n";
choicePost(getChannel($a), [['question' => 'מה לבנות?', 'options' => [['label' => 'א'], ['label' => 'ב']]]]);
check('שאלה פתוחה — ב"ממתין לך" של בעל הלוח', count(projectMonitor($root, 1)['choices']), 1);
channelMute($boss, $a, true);
check('אחרי השתקה — לא ב"ממתין לך" שלו', projectMonitor($root, 1)['choices'], []);
check('…אבל כן אצל דנה (ההשתקה אישית)', count(projectMonitor($root, 2)['choices']), 1);
$rows = channelsFor($boss, $root);
check('שורת הצ\'אט מסומנת מושתקת — רק אצלו', [array_values(array_filter($rows, fn ($c) => $c['id'] === $a))[0]['muted'],
      array_values(array_filter(channelsFor($dana, $root), fn ($c) => $c['id'] === $a))[0]['muted']], [true, false]);
channelMute($boss, $a, true);
check('השתקה פעמיים — בלי כפילות', (int) db()->query('SELECT COUNT(*) FROM channel_mutes')->fetchColumn(), 1);
channelMute($rami, $a, true);
check('גם צפייה בלבד משתיקה לעצמה', channelInfo($rami, $a)['muted'], true);
channelMute($boss, $a, false);
check('ביטול — חוזר ל"ממתין לך"', count(projectMonitor($root, 1)['choices']), 1);

echo "\n— 🔌 ניתוק · 🗄 ארכיון —\n";
throws('ארכיון — לא לדנה', fn () => channelSetHidden($dana, $b, true), 403);
channelSetHidden($boss, $b, true);
$cb = getChannel($b);
check('ארכיון של צ\'אט פעיל — מתנתק קודם (האסימון נפסל)', [$cb['status'], $cb['token_hash'], (int) $cb['hidden']], ['archived', '', 1]);
check('יורד מהרשימה', in_array($b, array_column(channelsFor($boss, $root), 'id'), true), false);
check('…ונספר ב"בארכיון"', channelsHiddenCount($boss, $root), 1);
check('include_hidden — מוצג, מסומן', array_values(array_filter(channelsFor($boss, $root, true), fn ($c) => $c['id'] === $b))[0]['hidden'], true);
channelSetHidden($boss, $b, false);
check('החזרה מהארכיון — שוב ברשימה, ועדיין מנותק', [in_array($b, array_column(channelsFor($boss, $root), 'id'), true), getChannel($b)['status']],
      [true, 'archived']);
throws('ניתוק — לא לדנה', fn () => archiveChannel($a, $dana), 403);

echo "\n— ↩ תשובה להודעה (12b) —\n";
$c3 = registerChannel($scope, $sub, 'שלישי', 'x', 'session_cs_c', '')['channel_id'];
$orig = channelPost(getChannel($c3), 'chat', 'הודעה מקורית');
$other = channelPost(getChannel($a), 'chat', 'הודעה בערוץ אחר');
$r1 = channelSend($dana, $c3, 'תשובה', null, $orig);
check('תשובה להודעה מאותו ערוץ — נשמר reply_to', messageRows('m.id = ?', [$r1])[0]['reply_to'], $orig);
$r2 = channelSend($dana, $c3, 'תשובה לזר', null, $other);
check('reply_to להודעה מערוץ אחר — נשמר בלי הקישור', messageRows('m.id = ?', [$r2])[0]['reply_to'], null);
check('…וההודעה עצמה נמסרת כרגיל', in_array($r2, array_column(channelInbox(getChannel($c3)), 'id'), true), true);

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
