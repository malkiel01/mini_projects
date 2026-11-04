<?php
/**
 * ש1 + ש2 (docs/audit-2026-11.md §6): בלי בדיקה שעתית, בלי הודעת הצטרפות לצ'אט, ועדכון גשר אוטומטי בתחילת תור.
 *
 *   php claude-tasks/tests/audit-s1s2.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/cts12-' . getmypid();
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
require_once __DIR__ . '/../lib/templates.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}

createUser('boss', 'pass-12345', 'בעל הלוח');
$boss = ['id' => 1, 'role' => 'admin', 'username' => 'boss', 'display_name' => 'בעל הלוח'];
$root = createProject($boss, 'mini_projects', 'malkiel01', 'mini_projects');

echo "\n— ש1: הודעת הצטרפות — רק בלוח —\n";
check('ברירת המחדל: אין יעד להודעת הצטרפות', templateResolved($root)['notify_session'], '');
db()->prepare('INSERT INTO chat_templates (project_id, notify_session, updated_at) VALUES (?, ?, ?)')->execute([$root, NOTIFY_SESSION_OLD_DEFAULT, nowTs()]);
check('הערך הישן (צ\'אט האפיון) שנשמר בתבנית — נחשב ריק', templateResolved($root)['notify_session'], '');
check('הפרומפט לצ\'אט חדש — בלי "שלח הודעת הצטרפות"', str_contains(chatPrompt($root, defaultRole()), 'הודעת הצטרפות'), false);
db()->prepare('UPDATE chat_templates SET notify_session = ? WHERE project_id = ?')->execute(['session_ABCDEFGHIJKLMN', $root]);
check('…ורק כשבעל הלוח קבע יעד בכוונה — כן', str_contains(chatPrompt($root, defaultRole()), 'session_ABCDEFGHIJKLMN'), true);

echo "\n— ש1: אין בדיקה שעתית בשום הוראה —\n";
$skill = file_get_contents(__DIR__ . '/../../.claude/skills/claude-tasks-live/SKILL.md');
check('הסקיל לא יוצר cron', str_contains($skill, '"0 * * * *"'), false);
check('הסקיל אומר: לעולם לא בדיקה חוזרת', str_contains($skill, 'Never create a recurring or chained board check'), true);
check('הודעת העדכון — בלי create_trigger חוזר', str_contains(BRIDGE_UPDATE_TEXT, '0 * * * *'), false);
check('הודעת העדכון — מוחקת בדיקה ישנה', str_contains(BRIDGE_UPDATE_TEXT, 'checkin --clear'), true);
check('UI — אין כפתור "שלח הוראת עדכון"', str_contains(file_get_contents(__DIR__ . '/../assets/js/chats.js'), 'שלח הוראת עדכון'), false);

echo "\n— ש2: עדכון גשר אוטומטי בתחילת תור (hook.sh prompt) —\n";
$home = "$tmp/home"; $proj = "$tmp/proj";
@mkdir("$home/.claude-tasks-bridge", 0777, true); @mkdir("$proj/.claude", 0777, true);
$rt = "$home/.claude-tasks-bridge";
copy(__DIR__ . '/../bridge/hook.sh', "$rt/hook.sh");
file_put_contents("$rt/lib.sh", "#\n");
file_put_contents("$rt/hook-prompt.sh", "exit 0\n");
file_put_contents("$rt/update.sh", "echo x >> \"$tmp/updated\"\n");
$run = fn () => exec('HOME=' . escapeshellarg($home) . ' CLAUDE_PROJECT_DIR=' . escapeshellarg($proj) . ' bash ' . escapeshellarg("$rt/hook.sh") . ' prompt');
$count = function () use ($tmp) { usleep(400000); return is_file("$tmp/updated") ? count(file("$tmp/updated")) : 0; };
$run();
check('צ\'אט לא רשום — לא מעדכן', $count(), 0);
file_put_contents("$proj/.claude/board-link.json", '{}');
$run();
check('צ\'אט רשום — מעדכן בשקט בתור הראשון', $count(), 1);
$run();
check('תור שני באותה שעה — לא שוב', $count(), 1);
touch("$home/.claude-tasks-bridge.updated", time() - 3700);
$run();
check('אחרי שעה — שוב', $count(), 2);

echo "\n  ════ עברו: $pass · נכשלו: $fail ════\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
