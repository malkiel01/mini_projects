<?php
/**
 * שלב 8b (docs/spec.md §9.1) — Platform שואל את בעל הלוח בבועת בחירה, ממשיך מהתשובה, לא שואל יותר מ-3 שאלות
 * פתוחות, מעביר לצ'אט האמיתי מה שרק הוא יכול (needs_chat), ומסמן תשובה שמתארת פעולה שלא קרתה. ספק מדומה.
 *
 *   php claude-tasks/tests/platformask.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/ctpask-' . getmypid();
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
/** ספק מדומה: מחזיר את ה-JSON; שומר את ההנחיות שקיבל. */
function model(array $out, ?array &$seen = null): callable {
    return function ($prov, $url, $json) use ($out, &$seen) {
        $seen[] = json_decode($json, true);
        return ['status' => 200, 'body' => json_encode(['content' => [['type' => 'text', 'text' => json_encode($out, JSON_UNESCAPED_UNICODE)]],
                                                        'usage' => ['input_tokens' => 100, 'output_tokens' => 20]])];
    };
}
$question = ['questions' => [['header' => 'צבע', 'question' => 'באיזה צבע לצבוע את הכפתור?', 'multiSelect' => false,
             'options' => [['label' => 'כחול', 'description' => 'צבע המותג'], ['label' => 'ירוק', 'description' => 'כמו "אושר"']]]]];

createUser('boss', 'pass-12345', 'בעל הלוח');
$boss = ['id' => 1, 'role' => 'admin', 'username' => 'boss', 'display_name' => 'בעל הלוח'];
$root = createProject($boss, 'mini_projects', 'malkiel01', 'mini_projects');
addConnection($boss, 'anthropic', 'sk-ant-bossbossbossbossboss', 'אישי', 'claude-opus-5-5');
setQuota($boss, 1, 25);
db()->prepare("UPDATE projects SET route = 'platform' WHERE id = ?")->execute([$root]);
$regToken = bin2hex(random_bytes(16));
db()->prepare('UPDATE projects SET agent_token = ? WHERE id = ?')->execute([encryptSecret($regToken), $root]);
$_SERVER['HTTP_X_WORKER_TOKEN'] = $regToken;
$ch = getChannel(registerChannel(workerScope(), $root, 'המתכלל', 'תכלול', 'session_PASK1', '')['channel_id']);
$cid = (int) $ch['id'];

echo "\n— תשובה רגילה —\n";
$m1 = channelSend($boss, $cid, 'מה מצב הפריסה?');
$r = platformReply($boss, $m1, model(['reply' => 'עברה.', 'task' => null, 'ask' => null, 'needs_chat' => null]));
check('תשובה — answer, בלי שאלה', [$r['message']['kind'], $r['asked'], $r['message']['body']], ['answer', false, 'עברה.']);

echo "\n— שאלה: בועת בחירה במקום ניחוש —\n";
$m2 = channelSend($boss, $cid, 'תצבע את כפתור השמירה');
$seen = [];
$r = platformReply($boss, $m2, model(['reply' => '', 'ask' => $question], $seen));
check('ההנחיה אומרת לשאול ולא להניח', str_contains($seen[0]['system'] ?? '', 'שאל אותו בשדה ask'), true);
check('Platform שאל — בועת choice פתוחה, author platform', [$r['asked'], $r['message']['kind'], $r['message']['author_role'], $r['message']['status']], [true, 'choice', 'platform', 'open']);
check('…בלי תשובה מנוחשת לצדה', count($r['messages']), 1);
$choiceId = (int) $r['message']['id'];
// §18.3 (22א): אין העתק לצ'אט כהודעה
check('אין שורת status / system שמעתיקה את השאלה', (int) db()->query("SELECT COUNT(*) FROM messages WHERE reply_to = $m2 AND author_role = 'system'")->fetchColumn(), 0);
check('ההודעה המקורית נמסרה (לא ממתינה)', db()->query("SELECT delivered_at IS NOT NULL FROM messages WHERE id = $m2")->fetchColumn(), 1);

echo "\n— תשובה לשאלה → Platform ממשיך —\n";
$b0 = bellValue($cid);
$a = choiceAnswer($boss, $choiceId, ['באיזה צבע לצבוע את הכפתור?' => 'ירוק']);
check('התשובה מסומנת ל-Platform, לא מעירה את הצ\'אט', [$a['platform'], bellValue($cid) === $b0,
      db()->query("SELECT answered_by FROM messages WHERE id = {$a['id']}")->fetchColumn()], [true, true, 'platform']);
$seen = [];
$r = platformReply($boss, $a['id'], model(['reply' => 'צובע בירוק — זה דורש קוד.', 'task' => ['title' => 'כפתור שמירה ירוק', 'body' => 'app.css']], $seen));
$ctxPrompt = $seen[0]['messages'][0]['content'] ?? '';
check('Platform ממשיך עם השרשור — השאלה והתשובה בהקשר', [str_contains($ctxPrompt, 'באיזה צבע'), str_contains($ctxPrompt, 'ירוק')], [true, true]);
check('…ועונה (עם הצעת מטלה)', [$r['message']['kind'], $r['message']['meta']['task']['title'] ?? null], ['answer', 'כפתור שמירה ירוק']);
check('השימוש נרשם לכל סבב (answer)', (int) db()->query("SELECT COUNT(*) FROM usage WHERE kind = 'answer'")->fetchColumn(), 3);

echo "\n— לא יותר מ-3 שאלות פתוחות —\n";
for ($i = 1; $i <= 3; $i++) {
    $mi = channelSend($boss, $cid, "שאלה פתוחה $i");
    platformReply($boss, $mi, model(['reply' => '', 'ask' => $question]));
}
check('3 שאלות פתוחות', platformOpenAsks($cid), 3);
$m4 = channelSend($boss, $cid, 'ועוד אחת');
$seen = [];
$r = platformReply($boss, $m4, model(['reply' => '', 'ask' => $question], $seen));
check('ההנחיה אוסרת לשאול עוד', str_contains($seen[0]['system'] ?? '', 'אסור לשאול עוד'), true);
check('הרביעית נדחתה — תשובה במקום שאלה', [$r['asked'], $r['message']['kind'], platformOpenAsks($cid)], [false, 'answer', 3]);
check('…שאומרת לענות קודם או להפוך למטלה', str_contains($r['message']['body'], 'להפוך למטלה'), true);
db()->exec("UPDATE messages SET status = 'answered' WHERE kind = 'choice'");

echo "\n— needs_chat: מה שרק הצ'אט יכול —\n";
db()->prepare('UPDATE channels SET listening_until = 0 WHERE id = ?')->execute([$cid]);
$b0 = bellValue($cid);
$m5 = channelSend($boss, $cid, 'תפעיל את הרסטר');
$r = platformReply($boss, $m5, model(['reply' => 'העברתי את הבקשה לצ\'אט של הערוץ.', 'needs_chat' => 'לשלוח לצ\'אט של הרסטר את המשימה']));
$nc = $r['message']['meta']['needs_chat'] ?? [];
check('התשובה הקצרה נשמרת, עם מה שצריך ולמי', [$r['handed_to_chat'], $nc['what'] ?? null, $nc['to'] ?? null], [true, 'לשלוח לצ\'אט של הרסטר את המשימה', 'המתכלל']);
check('…והכרטיס יודע שהצ\'אט לא מאזין', $nc['listening'] ?? null, false);
// §18.3 (22א): ההודעה המקורית עצמה נפתחת מחדש לצ'אט, עם הערה — בלי הודעת system שנייה
$orig = db()->query("SELECT answered_by, handled_by, delivered_at, meta FROM messages WHERE id = $m5")->fetch();
check('ההודעה המקורית נפתחת לצ\'אט עם ההערה — ומצלצלת', [$orig['answered_by'], $orig['handled_by'], $orig['delivered_at'],
      json_decode($orig['meta'], true)['platform_note'] ?? null, bellValue($cid) !== $b0], ['', 'chat', null, 'לשלוח לצ\'אט של הרסטר את המשימה', true]);
check('…אין הודעת system שמעתיקה אותה', (int) db()->query("SELECT COUNT(*) FROM messages WHERE reply_to = $m5 AND author_role = 'system'")->fetchColumn(), 0);
check('…והיא בתיבה של הצ\'אט, פעם אחת', count(array_filter(channelInbox(getChannel($cid)), fn ($x) => (int) $x['id'] === $m5)), 1);
check('"העברתי" עם needs_chat — לא מסומן כתיאור שקרי', $r['message']['meta']['claimed_action'] ?? false, false);

echo "\n— שמירה בקוד: תיאור של פעולה שלא קרתה —\n";
$m6 = channelSend($boss, $cid, 'תעדכן את הרסטר');
$r = platformReply($boss, $m6, model(['reply' => 'שלחתי לרסטר את העדכון.']));
check('"שלחתי" בלי needs_chat — מסומן ⚠', $r['message']['meta']['claimed_action'] ?? false, true);
check('תשובה רגילה — לא מסומנת', platformClaimsAction('הפריסה עברה בהצלחה.'), false);
check('JSON שבור — הטקסט כולו תשובה', parsePlatformReply('סתם טקסט')['reply'], 'סתם טקסט');
check('שאלה לא תקינה — נזרקת, התשובה נשארת', parsePlatformReply(json_encode(['reply' => 'x', 'ask' => ['questions' => [['question' => '']]]]))['ask'], null);

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
