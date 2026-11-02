<?php
/**
 * שלב 11 (docs/spec.md §5.9) — פתיחת צ'אטים: תבנית בירושה שדה-שדה, פרומפט בלי
 * ערכים למילוי, שגיאות הרישום, צ'אט המשך (replaces + handoff), ורמות "הצ'אט מתארך".
 *
 *   php claude-tasks/tests/templates.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/cttpl-' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp));
@mkdir($tmp, 0777, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('CONFIG_FILE', $tmp . '/c.json');
define('SECRET_KEY_FILE', $tmp . '/secret.key');
define('BELL_DIR', $tmp . '/bell');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/projects.php';
require_once __DIR__ . '/../lib/channels.php';
require_once __DIR__ . '/../lib/engine.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}
function throws(string $name, callable $fn, int $status = 0, string $code = '') {
    try { $fn(); check($name, 'לא נזרקה חריגה', 'חריגה'); }
    catch (AppError $e) { check($name . ' — ' . $e->getMessage(), ($status === 0 || $e->status === $status) && ($code === '' || $e->errorCode === $code), true); }
    catch (Throwable $e) { check($name . ' — ' . $e->getMessage(), $status === 0 && $code === '', true); }
}
function scopeOf(string $token): ?array {
    $_SERVER['HTTP_X_WORKER_TOKEN'] = $token;
    unset($_SERVER['HTTP_X_WORKER_NAME']);
    return workerScope();
}

createUser('boss', 'pass-12345', 'בעל הלוח');
createUser('dana', 'pass-12345', 'דנה');
$boss = ['id' => 1, 'role' => 'admin', 'username' => 'boss', 'display_name' => 'בעל הלוח'];
$dana = ['id' => 2, 'role' => 'member', 'username' => 'dana', 'display_name' => 'דנה'];
$root   = createProject($boss, 'Mini projects', 'malkiel01', 'mini_projects');
$suite  = createSubproject($boss, $root, 'חבילת היצירה', 'creative-suite');
$studio = createSubproject($boss, $suite, 'סטודיו העיצוב', 'greeting-cards');
$other  = createProject($boss, 'ריפו אחר', 'someone', 'else');
setMember($boss, $root, 2, 'write');

echo "\n— תבנית בירושה —\n";
$t = templateResolved($studio);
check('בלי תבנית — ברירות מחדל (שם, תפקיד אחד, הודעה לצ\'אט האפיון)',
      [$t['name_pattern'], count($t['roles']), $t['notify_session']], ['{project} - {role}', 1, NOTIFY_SESSION_DEFAULT]);
throws('דנה (לא בעלת הלוח) — לא עורכת', fn () => saveTemplate($dana, $suite, ['hierarchy' => 'x']), 403);
throws('תבנית שם בלי {role} — נדחית', fn () => saveTemplate($boss, $suite, ['name_pattern' => 'היצירה v2']));
throws('מפתח תפקיד בעברית — נדחה', fn () => saveTemplate($boss, $suite, ['roles' => [['key' => 'מתכלל', 'label' => 'המתכלל']]]));
saveTemplate($boss, $suite, [
    'name_pattern' => 'היצירה v2 - {role}',
    'roles' => [['key' => 'coordinator', 'label' => 'המתכלל', 'icon' => '🧭', 'reports_to' => 'בעל המערכת', 'brief' => 'אתה מכריע.'],
                ['key' => 'design-studio', 'label' => 'סטודיו העיצוב', 'icon' => '🎨', 'reports_to' => 'המתכלל', 'brief' => '']],
    'context_files' => "CLAUDE.md\n.claude/skills/creative-suite-team/SKILL.md\n\ncreative-suite/team/roster.json",
    'hierarchy' => 'המתכלל מעל כולם; מתחתיו האפיון והקוד המשותף',
]);
saveTemplate($boss, $root, ['extra' => 'עברית בלבד בתכתובת.']);
$t = templateResolved($studio);
check('תת-פרויקט יורש מ"חבילת היצירה" (שם ותפקידים)', [$t['name_pattern'], array_column($t['roles'], 'key')],
      ['היצירה v2 - {role}', ['coordinator', 'design-studio']]);
check('…ושדה אחר מהשורש — כל שדה בנפרד', [$t['extra'], $t['sources']['extra']['name'], $t['sources']['roles']['name']],
      ['עברית בלבד בתכתובת.', 'Mini projects', 'חבילת היצירה']);
saveTemplate($boss, $studio, ['hierarchy' => 'מתחת למתכלל']);
$t = templateResolved($studio);
check('שדה מקומי גובר, והשאר ממשיך לרשת', [$t['hierarchy'], $t['sources']['hierarchy']['name'], $t['name_pattern']],
      ['מתחת למתכלל', 'סטודיו העיצוב', 'היצירה v2 - {role}']);
saveTemplate($boss, $studio, ['hierarchy' => '']);
check('שדה ריק — חוזר לירושה', templateResolved($studio)['sources']['hierarchy']['name'], 'חבילת היצירה');
check('הפרויקט האחר — לא מושפע', templateResolved($other)['name_pattern'], '{project} - {role}');

echo "\n— הפרומפט —\n";
$sheet = chatSheet($boss, $studio);
$p = $sheet['roles'][1]['prompt'];
check('שם לפי התבנית', $sheet['roles'][1]['name'], 'היצירה v2 - סטודיו העיצוב');
check('מספר הפרויקט בפקודה — בלי מילוי ידני', str_contains($p, "link.sh register --project $studio --name \"היצירה v2 - סטודיו העיצוב\" --role \"design-studio\""), true);
check('אין סוגריים או ___ למילוי', preg_match('/\[[^\]]*\]|___|<[^>]+>/u', $p), 0);
check('נתיב הפרויקט ו-#id', str_contains($p, "\"Mini projects › חבילת היצירה › סטודיו העיצוב\" (#$studio)"), true);
check('קובצי ההקשר, CLAUDE.md פעם אחת', substr_count($p, 'CLAUDE.md') === 1 && str_contains($p, 'creative-suite/team/roster.json'), true);
check('ההיררכיה ולמי מדווחים', str_contains($p, 'ההיררכיה: המתכלל מעל כולם; מתחתיו האפיון והקוד המשותף. אתה מדווח ל: המתכלל.'), true);
check('הודעת ההצטרפות — לצ\'אט האפיון כברירת מחדל', str_contains($p, NOTIFY_SESSION_DEFAULT), true);
check('ה-extra מהשורש בסוף', str_ends_with($p, 'עברית בלבד בתכתובת.'), true);
check('השורש — האסימון היחיד לסביבה', $sheet['root'], ['id' => $root, 'name' => 'Mini projects']);
setChatEnvironment($boss, 'Default Cloud Environment');
check('שם הסביבה נשמר ומוצג', chatSheet($boss, $studio)['environment'], 'Default Cloud Environment');
throws('שם הסביבה — בעל הלוח בלבד', fn () => setChatEnvironment($dana, 'x'), 403);
saveTemplate($boss, $suite, ['notify_session' => 'session_01XFFMNKh9yc4XGoUa7AYMfv']);
check('notify_session מהתבנית גובר על ברירת המחדל', str_contains(chatSheet($boss, $studio)['roles'][0]['prompt'], 'session_01XFFMNKh9yc4XGoUa7AYMfv'), true);
saveTemplate($boss, $suite, ['notify_session' => null]);
throws('notify_session לא תקין — נדחה', fn () => saveTemplate($boss, $suite, ['notify_session' => 'בעל המערכת']));

echo "\n— רישום: שגיאות שאומרות מה לתקן —\n";
$studioToken = projectAgentToken($studio);
$rootToken = projectAgentToken($root);
throws('אסימון של תת-פרויקט, רישום לאח שלו — token_scope', fn () => registerChannel(scopeOf($studioToken), $suite, 'x', 'y', 'session_scope_1', ''), 403, 'token_scope');
try { registerChannel(scopeOf($studioToken), $suite, 'x', 'y', 'session_scope_1', ''); } catch (AppError $e) { $msg = $e->getMessage(); }
check('…ההודעה: של מי האסימון, ושל מי צריך', str_contains($msg, 'שייך ל-"סטודיו העיצוב"') && str_contains($msg, "מכסה את #$suite") && str_contains($msg, 'האסימון של "Mini projects"'), true);
throws('פרויקט שלא קיים — no_project', fn () => registerChannel(scopeOf($rootToken), 9999, 'x', 'y', 'session_scope_2', ''), 404, 'no_project');
$r1 = registerChannel(scopeOf($rootToken), $studio, 'היצירה v2 - סטודיו העיצוב', 'design-studio', 'session_old_chat', '');
check('אסימון השורש — נרשם לנכד, ומחזיר למי לדווח', [$r1['renewed'], $r1['notify_session'], $r1['project_path']],
      [false, NOTIFY_SESSION_DEFAULT, 'Mini projects › חבילת היצירה › סטודיו העיצוב']);
$joined = messageRows("m.channel_id = ? AND m.author_role = 'system'", [$r1['channel_id']]);
check('הודעת system "הצטרף" בערוץ', $joined[0]['body'] ?? '', '"היצירה v2 - סטודיו העיצוב" הצטרף');
check('הסמל של התפקיד בתבנית — עובר לצ\'אט', getChannel($r1['channel_id'])['icon'], '🎨');

echo "\n— הצ'אט מתארך —\n";
$old = getChannel($r1['channel_id']);
check('לא דיווח — בלי רמה (בלי ניחוש)', healthLevel($old), '');
check('קטן ובלי דחיסה — תקין', channelHealth($old, 400_000, 40, 0), '');
check('1.5MB — 🟡', channelHealth($old, 1_600_000, 120, 0), 'warn');
check('דחיסה אחת — 🟡 גם כשקטן', channelHealth($old, 100_000, 10, 1), 'warn');
check('3MB — 🔴', channelHealth($old, 3_100_000, 300, 0), 'alert');
check('2 דחיסות — 🔴', channelHealth($old, 900_000, 90, 2), 'alert');
configSet(['chat_alert_compactions' => 5]);
check('הספים מהקונפיג', channelHealth($old, 900_000, 90, 2), 'warn');
configSet(['chat_alert_compactions' => null]);
$row = array_values(array_filter(channelsFor($boss, $root), fn ($c) => $c['id'] === $r1['channel_id']))[0];
check('שורת הצ\'אט מחזירה את הרמה', [$row['health'], $row['health_compactions']], ['alert', 2]);

echo "\n— צ'אט המשך —\n";
db()->prepare("INSERT INTO tasks (title, project_id, status, created_by, created_at, updated_at, asked_channel_id) VALUES ('שאלה פתוחה', ?, 'blocked', 1, 1, 1, ?)")
    ->execute([$studio, $r1['channel_id']]);
$taskId = (int) db()->lastInsertId();
throws('דנה — לא מבקשת צ\'אט המשך', fn () => handoffRequest($dana, $r1['channel_id']), 403);
check('לפני בקשה — "לא התבקש" (בלי שגיאה)', handoffPrompt($boss, $r1['channel_id'])['requested'], false);
$hr = handoffRequest($boss, $r1['channel_id']);
check('הבקשה מגיעה לצ\'אט (in, handoff_request, מעירה)', [in_array($hr['id'], array_column(channelInbox($old), 'id'), true),
      messageRows('m.id = ?', [$hr['id']])[0]['kind']], [true, 'handoff_request']);
$st = handoffPrompt($boss, $r1['channel_id']);
check('עוד אין תקציר — ממתינים, ואי אפשר "בלי תקציר" לפני 2 דקות', [$st['ready'], $st['can_fallback']], [false, false]);
throws('"בלי תקציר" מוקדם מדי — 409', fn () => handoffPrompt($boss, $r1['channel_id'], true), 409);
channelPost($old, 'chat', "## מצב\nמ4 נפרסה.\n## מה הצ'אט הבא צריך לעשות קודם\nלבדוק את לוח השכבות.", null, null, 'handoff');
$hp = handoffPrompt($boss, $r1['channel_id']);
check('יש תקציר — הפרומפט מוכן, עם --replaces והתקציר', [$hp['ready'], $hp['source'], str_contains($hp['prompt'], "--replaces {$r1['channel_id']}"),
      str_contains($hp['prompt'], 'לבדוק את לוח השכבות.')], [true, 'handoff', true, true]);
check('…אותו תפקיד ואותו שם', str_contains($hp['prompt'], '--name "היצירה v2 - סטודיו העיצוב" --role "design-studio"'), true);
// "בלי תקציר": בקשה ישנה, בלי תשובה
db()->prepare("UPDATE messages SET created_at = created_at - 600 WHERE kind = 'handoff_request'")->execute();
handoffRequest($boss, $r1['channel_id']);
db()->prepare("UPDATE messages SET created_at = ? WHERE id = (SELECT MAX(id) FROM messages WHERE kind = 'handoff_request')")->execute([nowTs() - 130]);
$fb = handoffPrompt($boss, $r1['channel_id'], true);
check('בלי תקציר אחרי 2 דקות — מההודעות האחרונות', [$fb['ready'], $fb['source'], str_contains($fb['prompt'], 'לא החזיר תקציר')], [true, 'messages', true]);

$r2 = registerChannel(scopeOf($rootToken), $studio, 'היצירה v2 - סטודיו העיצוב', 'design-studio', 'session_new_chat', '', $r1['channel_id']);
$old = getChannel($r1['channel_id']);
check('הישן — replaced, ומצביע על החדש', [$old['status'], (int) $old['replaced_by']], ['replaced', $r2['channel_id']]);
check('המטלה שנשאלה בישן עברה לחדש', (int) db()->query("SELECT asked_channel_id FROM tasks WHERE id = $taskId")->fetchColumn(), $r2['channel_id']);
$sys = messageRows("m.channel_id = ? AND m.author_role = 'system' AND m.direction = 'in'", [$r1['channel_id']]);
check('הישן קיבל "הוחלפת" — ומקבל אותה (האסימון עוד פועל לקריאה)', [str_starts_with($sys[0]['body'] ?? '', 'הוחלפת על ידי'),
      in_array($sys[0]['id'] ?? 0, array_column(channelInbox($old), 'id'), true)], [true, true]);
throws('לוח ← ערוץ שהוחלף — 409', fn () => channelSend($boss, $r1['channel_id'], 'עוד משהו'), 409);
$rows = channelsFor($boss, $root);
$oldRow = array_values(array_filter($rows, fn ($c) => $c['id'] === $r1['channel_id']))[0];
check('שורת הישן: "הוחלף ← החדש", ואחרי הפעילים', [$oldRow['replaced_by_name'], $rows[0]['id']], ['היצירה v2 - סטודיו העיצוב', $r2['channel_id']]);
$join = messageRows("m.channel_id = ? AND m.author_role = 'system'", [$r2['channel_id']]);
check('בחדש — "הצטרף — ממשיך את…"', str_contains($join[0]['body'] ?? '', "ממשיך את \"היצירה v2 - סטודיו העיצוב\" (ערוץ #{$r1['channel_id']})"), true);
$foreign = registerChannel(['project_id' => null, 'channel_id' => null], $other, 'זר', 'x', 'session_foreign', '');
throws('replaces של ערוץ מעץ אחר — token_scope', fn () => registerChannel(scopeOf($rootToken), $studio, 'n', 'r', 'session_thief', '', $foreign['channel_id']), 403, 'token_scope');
check('…ולא נשאר ערוץ מהרישום שנדחה', (int) db()->query("SELECT COUNT(*) FROM channels WHERE session_id = 'session_thief'")->fetchColumn(), 0);
check('…והערוץ הזר לא נגע', getChannel($foreign['channel_id'])['status'], 'active');

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
