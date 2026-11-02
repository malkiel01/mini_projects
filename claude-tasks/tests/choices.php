<?php
/**
 * שלב 11b (docs/spec.md §5.10) — שאלות בחירה מרובה בלוח: פרסום מה-hook, מענה
 * מהלוח, מרוץ, תשובה שהוקלדה בצ'אט, מצב "באפליקציה", הרשאות, ו"ממתין לך".
 *
 *   php claude-tasks/tests/choices.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/ctcho-' . getmypid();
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
function msg(int $id): array {
    $r = messageRows('m.id = ?', [$id]);
    return $r[0];
}

createUser('boss', 'pass-12345', 'בעל הלוח');
createUser('dana', 'pass-12345', 'דנה');
createUser('rami', 'pass-12345', 'רמי');
$boss = ['id' => 1, 'role' => 'admin', 'username' => 'boss', 'display_name' => 'בעל הלוח'];
$dana = ['id' => 2, 'role' => 'member', 'username' => 'dana', 'display_name' => 'דנה'];
$rami = ['id' => 3, 'role' => 'member', 'username' => 'rami', 'display_name' => 'רמי'];
$root = createProject($boss, 'mini_projects', 'malkiel01', 'mini_projects');
$sub  = createSubproject($boss, $root, 'סטודיו העיצוב', 'greeting-cards');
setMember($boss, $root, 2, 'write');
setMember($boss, $root, 3, 'read');

$scope = ['project_id' => null, 'channel_id' => null];
registerChannel($scope, $sub, 'היצירה v2 - סטודיו העיצוב', 'design-studio', 'session_choice_1', '');
$ch = getChannel((int) db()->query("SELECT id FROM channels WHERE session_id = 'session_choice_1'")->fetchColumn());

$one = [['question' => 'לאיזה פרויקט לרשום?', 'header' => 'פרויקט', 'multiSelect' => false,
         'options' => [['label' => 'פרויקט 10', 'description' => 'מהסביבה'], ['label' => 'פרויקט 6', 'description' => '']]]];

echo "\n— פרסום מה-hook —\n";
$r = choicePost($ch, $one);
check('נשמרה, ומצב ברירת המחדל board (ה-hook ידחה את הכלי)', [$r['mode'], msg($r['id'])['kind'], msg($r['id'])['status'], msg($r['id'])['direction']],
      ['board', 'choice', 'open', 'out']);
check('הגוף — השאלה והאפשרויות ממוספרות', msg($r['id'])['body'], "❓ פרויקט: לאיזה פרויקט לרשום?\n1. פרויקט 10 — מהסביבה\n2. פרויקט 6");
check('השאלות נשמרו כמו שהן ב-meta', msg($r['id'])['meta']['questions'][0]['options'][1]['label'], 'פרויקט 6');
throws('בלי שאלות — נדחה', fn () => choicePost($ch, []));
throws('יותר מ-4 שאלות — נדחה', fn () => choicePost($ch, array_fill(0, 5, $one[0])));
throws('שאלה בלי אפשרויות — נדחה', fn () => choicePost($ch, [['question' => 'מה?', 'options' => []]]));
check('channel-post רגיל עם kind choice — נשמר כטקסט (בלי שאלה ריקה)',
      msg(channelPost($ch, 'chat', 'ניסיון', null, null, 'choice'))['kind'], 'text');

echo "\n— מענה מהלוח —\n";
$bellBefore = bellValue((int) $ch['id']);
$a = choiceAnswer($boss, $r['id'], ['לאיזה פרויקט לרשום?' => 'פרויקט 6']);
$ans = msg($a['id']);
check('נוצרה הודעה לצ\'אט: in, choice_answer, reply_to', [$ans['direction'], $ans['kind'], $ans['reply_to']], ['in', 'choice_answer', $r['id']]);
check('…בגוף שהצ\'אט מבין', $ans['body'], "תשובה לשאלה #{$r['id']}:\n• פרויקט: פרויקט 6");
check('…ומעירה את הצ\'אט (פעמון)', bellValue((int) $ch['id']) !== $bellBefore, true);
check('…ונמסרת ב-inbox', in_array($a['id'], array_column(channelInbox($ch), 'id'), true), true);
$c = msg($r['id']);
check('השאלה סומנה "נענתה בלוח", עם התשובה', [$c['status'], $c['meta']['answered_via'], $c['meta']['answers']],
      ['answered', 'board', ['לאיזה פרויקט לרשום?' => 'פרויקט 6']]);

echo "\n— מרוץ: הראשון קובע —\n";
throws('תשובה שנייה מהלוח — 409', fn () => choiceAnswer($dana, $r['id'], ['לאיזה פרויקט לרשום?' => 'פרויקט 10']), 409);
$before = msg($r['id'])['meta'];
channelPost($ch, 'owner', '2', $r['id']);
check('הקלדה בצ\'אט אחרי מענה בלוח — לא משנה את התשובה', msg($r['id'])['meta']['answers'], $before['answers']);

echo "\n— אימות התשובות —\n";
$multi = [
  ['question' => 'אילו מסכים?', 'header' => 'מסכים', 'multiSelect' => true,
   'options' => [['label' => 'הגדרות'], ['label' => 'שיחה'], ['label' => 'לוח']]],
  ['question' => 'מתי?', 'header' => 'זמן', 'multiSelect' => false, 'options' => [['label' => 'היום'], ['label' => 'מחר']]],
];
$m2 = choicePost($ch, $multi)['id'];
throws('חסרה תשובה לשאלה השנייה', fn () => choiceAnswer($boss, $m2, ['אילו מסכים?' => ['הגדרות']]));
throws('אפשרות שלא קיימת', fn () => choiceAnswer($boss, $m2, ['אילו מסכים?' => ['מטבח'], 'מתי?' => 'היום']));
throws('כמה תשובות לשאלה של בחירה אחת', fn () => choiceAnswer($boss, $m2, ['אילו מסכים?' => ['הגדרות'], 'מתי?' => ['היום', 'מחר']]));
throws('"אחר" ריק', fn () => choiceAnswer($boss, $m2, ['אילו מסכים?' => ['הגדרות'], 'מתי?' => ['other' => '  ']]));
check('השאלה עדיין פתוחה אחרי ניסיונות פסולים', msg($m2)['status'], 'open');
$a2 = choiceAnswer($dana, $m2, ['אילו מסכים?' => ['הגדרות', 'שיחה'], 'מתי?' => ['other' => 'בשבוע הבא']]);
check('כמה שאלות, בחירה מרובה ו"אחר" — עובר (גם write בענף)', msg($a2['id'])['body'],
      "תשובה לשאלה #$m2:\n• מסכים: הגדרות, שיחה\n• זמן: בשבוע הבא");

echo "\n— הרשאות —\n";
$m3 = choicePost($ch, $one)['id'];
throws('צפייה בלבד — לא עונה', fn () => choiceAnswer($rami, $m3, ['לאיזה פרויקט לרשום?' => 'פרויקט 6']), 403);
throws('הודעה שאינה שאלת בחירה — 404', fn () => choiceAnswer($boss, $a2['id'], []), 404);

echo "\n— תשובה שהוקלדה בצ'אט —\n";
$typed = channelPost($ch, 'owner', '2', $m3);
$c3 = msg($m3);
check('prompt של בעל הלוח עם reply_to — השאלה "נענתה בצ\'אט"', [$c3['status'], $c3['meta']['answered_via']], ['answered', 'chat']);
check('…והטקסט שהוקלד הוא התשובה', $c3['meta']['answers'], ['' => ['other' => '2']]);
check('…וההודעה עצמה נשמרה כרגיל (שיקוף)', [msg($typed)['direction'], msg($typed)['author_role']], ['out', 'owner']);
throws('מענה בלוח אחרי הקלדה בצ\'אט — 409', fn () => choiceAnswer($boss, $m3, ['לאיזה פרויקט לרשום?' => 'פרויקט 6']), 409);
$m4 = choicePost($ch, $one)['id'];
channelPost($ch, 'chat', 'אני ממשיך', $m4);
check('הודעה של הצ\'אט עצמו עם reply_to — לא סוגרת את השאלה', msg($m4)['status'], 'open');

echo "\n— מצב באפליקציה —\n";
throws('צפייה בלבד — לא משנה מצב', fn () => setChannelQuestions($rami, (int) $ch['id'], 'app'), 403);
throws('מצב לא מוכר', fn () => setChannelQuestions($boss, (int) $ch['id'], 'popup'));
setChannelQuestions($boss, (int) $ch['id'], 'app');
$ch = getChannel((int) $ch['id']);
$r5 = choicePost($ch, $one);
check('app — ה-hook לא ידחה (הכלי רץ), והלוח משקף', [$r5['mode'], msg($r5['id'])['status']], ['app', 'open']);
throws('app — עונים בחלון, לא בלוח', fn () => choiceAnswer($boss, $r5['id'], ['לאיזה פרויקט לרשום?' => 'פרויקט 6']), 409);
channelPost($ch, 'owner', "תשובה לשאלה #{$r5['id']}:\n• לאיזה פרויקט לרשום?: פרויקט 6", $r5['id'], null, 'choice_answer',
            ['לאיזה פרויקט לרשום?' => 'פרויקט 6']);
$c5 = msg($r5['id']);
check('PostToolUse — "נענתה באפליקציה" עם התשובה מהחלון', [$c5['status'], $c5['meta']['answered_via'], $c5['meta']['answers']],
      ['answered', 'app', ['לאיזה פרויקט לרשום?' => 'פרויקט 6']]);
check('channel-messages מחזיר את המצב לממשק', channelMessages($boss, (int) $ch['id'])['channel']['questions'], 'app');
setChannelQuestions($dana, (int) $ch['id'], 'board');
check('write בענף — מחזיר ל"בלוח"', channelQuestionsMode(getChannel((int) $ch['id'])), 'board');

echo "\n— ממתין לך —\n";
$open = choicePost(getChannel((int) $ch['id']), $one)['id'];
$row = array_values(array_filter(channelsFor($boss, $root), fn ($c) => $c['id'] === (int) $ch['id']))[0];
check('שורת הצ\'אט סופרת שאלות פתוחות', $row['open_choices'], 2);   // m4 ו-open
$w = projectMonitor($root)['choices'];
check('"ממתין לך" בשורש — כולל שאלות מתת-פרויקט', array_column($w, 'message_id'), [$m4, $open]);
check('…עם שם הצ\'אט והשאלה', [$w[0]['channel_name'], $w[0]['title']], ['היצירה v2 - סטודיו העיצוב', 'לאיזה פרויקט לרשום?']);
archiveChannel((int) $ch['id'], $boss);
check('צ\'אט מנותק — יוצא מ"ממתין לך"', projectMonitor($root)['choices'], []);
throws('צ\'אט מנותק — אי אפשר לענות', fn () => choiceAnswer($boss, $open, ['לאיזה פרויקט לרשום?' => 'פרויקט 6']), 409);

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
