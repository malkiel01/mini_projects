<?php
/**
 * שלב 25 (docs/spec.md §21, §21.7) — צ'אט המנהל: lease (מנהל שני נדחה, החלפה אטומית, retired), התור (היעד מהלוח בלבד),
 * המגבלות (ערוץ ל-10 דק', 20 בשעה), בלי העירה לערוץ מאזין / בארכיון, העירה אוטומטית לפי הגדרת הערוץ, ושורת ⏰.
 *
 *   php claude-tasks/tests/lanes25.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/ctl25-' . getmypid();
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
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}
function code(callable $fn): string { try { $fn(); return 'עבר'; } catch (AppError $e) { return $e->errorCode ?: (string) $e->status; } }

$GLOBALS['CT_FAKE_NOW'] = 1_800_000_000;
$tick = function (int $s) { $GLOBALS['CT_FAKE_NOW'] += $s; };

createUser('boss', 'pass-12345', 'בעל הלוח');
$boss = ['id' => 1, 'role' => 'admin', 'username' => 'boss', 'display_name' => 'בעל הלוח'];
$dana = ['id' => 2, 'role' => 'user', 'username' => 'dana', 'display_name' => 'דנה'];
$root = createProject($boss, 'mini_projects', 'malkiel01', 'mini_projects');
$regToken = bin2hex(random_bytes(16));
db()->prepare('UPDATE projects SET agent_token = ? WHERE id = ?')->execute([encryptSecret($regToken), $root]);
$_SERVER['HTTP_X_WORKER_TOKEN'] = $regToken;
$reg = fn (string $name, string $role, string $sid) => (int) registerChannel(workerScope(), $root, $name, $role, $sid, "https://claude.ai/code/$sid")['channel_id'];
$disp = function (string $sid, bool $replaces = false) use ($reg) {
    dispatcherPrecheck($sid, $replaces);
    $id = $reg('🛰 צ\'אט המנהל', 'dispatcher', $sid);
    dispatcherClaim(getChannel($id));
    return $id;
};
$chats = [];
for ($i = 1; $i <= 4; $i++) $chats[$i] = $reg("צ'אט $i", 'קוד', "session_W25_chat$i");
db()->exec('UPDATE channels SET listening_until = 0');

echo "\n— אין מנהל —\n";
check('⏰ בלי מנהל — no_dispatcher', code(fn () => queueWake($boss, $chats[1])), 'no_dispatcher');
check('השורה: "🛰 אין מנהל פעיל"', wakeInfo(getChannel($chats[1]))['state'], 'no_dispatcher');

echo "\n— ה-lease: אחד בכל רגע —\n";
$d1 = $disp('session_W25_disp1');
check('המנהל הראשון — פעיל', dispatcherInfo()['active'], true);
check('מנהל שני — נדחה (כבר יש מנהל פעיל)', code(fn () => $disp('session_W25_disp2')), 'dispatcher_exists');
check('…וגם עם --replaces-dispatcher, בלי החלפה פתוחה', code(fn () => $disp('session_W25_disp2', true)), 'dispatcher_exists');
check('אותו סשן נרשם שוב — מותר', code(fn () => $disp('session_W25_disp1')), 'עבר');
check('המנהל לא נספר במאזינים, ולא ברשימת הישנים', [listenersInfo()['listening'], in_array($d1, array_column(wontWake($boss, $root), 'id'), true)], [0, false]);
check('השורה של המנהל — dispatcher', wakeInfo(getChannel($d1))['state'], 'dispatcher');
setListenersMode($boss, 'hourly');
listenGate(getChannel($d1));
check('המתג "💤 כבויים" לא חל על המנהל (listenGate עובר)', getChannel($d1)['listen_state'], 'running');

echo "\n— התור: היעד מהלוח בלבד —\n";
@unlink(bellFile($d1));
$r1 = queueWake($boss, $chats[1], '⏰ להעיר');
check('⏰ להעיר — נכנס לתור, ומצלצל למנהל', [$r1['status'], $r1['joined'], bellValue($d1) !== ''], ['queued', false, true]);
check('channel-wait של המנהל רואה בקשה אחת', dispatchQueuedCount(), 1);
check('שוב באותו ערוץ — מצטרף לפתוחה', queueWake($boss, $chats[1])['joined'], true);
check('לא בעל הלוח — נדחה', code(fn () => queueWake($dana, $chats[2])), '403');
check('צ\'אט רגיל לא יכול לקרוא את התור', code(fn () => dispatchPending(getChannel($chats[2]))), 'not_dispatcher');
$p = dispatchPending(getChannel($d1));
check('dispatch-pending — היעד הוא ה-session מהרישום, והטקסט הקבוע', [count($p['requests']), $p['requests'][0]['session_id'], str_contains($p['text'], 'זה לא מנהל הפרויקטים')], [1, 'session_W25_chat1', true]);
check('…והבקשה עכשיו sent; האצווה נספרה', [dispatchRequest($r1['id'])['status'], dispatcherInfo()['handled']], ['sent', 1]);
check('השורה: "⏰ נשלחה העירה · ממתין שיענה"', wakeInfo(getChannel($chats[1]))['state'], 'sent');
dispatchDone(getChannel($d1), $r1['id'], true);
check('dispatch-done ok — done', dispatchRequest($r1['id'])['status'], 'done');
check('דיווח כפול — נדחה', code(fn () => dispatchDone(getChannel($d1), $r1['id'], true)), '409');
$tick(30);
touchChannel($chats[1]);
check('הצ\'אט נראה בלוח אחרי ההעירה — השורה חוזרת ל"אפשר להעיר"', wakeInfo(getChannel($chats[1]))['state'], 'can_wake');
check('ערוץ — העירה אחת ל-10 דק\' (מצטרף)', queueWake($boss, $chats[1])['joined'], true);

echo "\n— לא מעירים: מאזין, בארכיון, המנהל עצמו —\n";
touchChannel($chats[2], nowTs() + 120);
check('צ\'אט מאזין — לא מעירים', code(fn () => queueWake($boss, $chats[2])), 'listening');
db()->prepare("UPDATE channels SET status = 'archived' WHERE id = ?")->execute([$chats[3]]);
check('ערוץ בארכיון — לא מעירים', code(fn () => queueWake($boss, $chats[3])), 'not_active');
check('המנהל עצמו — לא', code(fn () => queueWake($boss, $d1)), 'is_dispatcher');
$r4 = queueWake($boss, $chats[4]);
touchChannel($chats[4], nowTs() + 120);   // התחיל להאזין לפני שהמנהל קרא
$p = dispatchPending(getChannel($d1));
check('צ\'אט שבינתיים התחיל להאזין — לא נשלח, failed עם הסיבה', [count($p['requests']), dispatchRequest($r4['id'])['status']], [0, 'failed']);
db()->exec('UPDATE channels SET listening_until = 0');

echo "\n— העירה אוטומטית כשכותבים —\n";
$tick(700);
$before = dispatchQueuedCount();
channelSend($boss, $chats[4], 'מה המצב?');
check('בעל הלוח כותב לצ\'אט ישן — נכנסה העירה', dispatchQueuedCount(), $before + 1);
dispatchPending(getChannel($d1));
$tick(700);
setChannelWakeOnWrite($boss, $chats[4], false);
channelSend($boss, $chats[4], 'עוד שאלה');
check('"להעיר כשאני כותב" כבוי — אין העירה', dispatchQueuedCount(), 0);

echo "\n— 20 בשעה —\n";
$tick(3700);
dispatcherRenew(getChannel($d1));   // המאזין של המנהל בודק כל ~30 שנ'
for ($i = 0; $i < WAKE_HOURLY; $i++) db()->prepare("INSERT INTO dispatch_requests (channel_id, status, created_at) VALUES (?, 'done', ?)")->execute([$chats[2], nowTs() - 10]);
check('העירה ה-21 בשעה — wake_limit', code(fn () => queueWake($boss, $chats[1])), 'wake_limit');

echo "\n— החלפה: אטומית, והישן מקבל retired —\n";
dispatcherStartHandover($boss);
$d2 = $disp('session_W25_disp2', true);
check('אחרי "🔁 החלפת מנהל" — החדש נרשם ומקבל את ה-lease', dispatcherInfo()['channel_id'], $d2);
check('הישן — retired בבדיקה הבאה', [dispatcherRenew(getChannel($d1)), dispatchPending(getChannel($d1))['retired']], ['retired', true]);
check('החדש — מונה האצוות מתאפס', dispatcherInfo()['handled'], 0);

echo "\n— המנהל נעלם —\n";
$tick(DISPATCH_LEASE + DISPATCH_GRACE + 5);
check('ה-lease פג — "🛰 המנהל לא פעיל"', dispatcherInfo()['active'], false);
check('…ואי אפשר להעיר', code(fn () => queueWake($boss, $chats[1])), 'no_dispatcher');
check('מנהל חדש נרשם בלי החלפה כשהקודם פג', code(fn () => $disp('session_W25_disp3')), 'עבר');

echo "\n— בלי בדיקה שעתית בשום מקום —\n";
check('הפרומפט של המנהל — בלי cron', str_contains(dispatcherPrompt(), '0 * * * *'), false);
$skill = file_get_contents(__DIR__ . '/../../.claude/skills/claude-tasks-live/SKILL.md');
check('הסקיל — יש פרק למנהל, ואין בדיקה חוזרת', [str_contains($skill, '## 7. The dispatcher'), str_contains($skill, '"0 * * * *"')], [true, false]);

echo "\n  ════ עברו: $pass · נכשלו: $fail ════\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
