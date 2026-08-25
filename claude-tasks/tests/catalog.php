<?php
/**
 * בדיקות הקטלוג האוטומטי.
 *
 *   php claude-tasks/tests/catalog.php
 *
 * רצות על מסד זמני, ולא נוגעות בנתונים שבשרת. הפניות למודל מוחלפות
 * בתעבורה מזויפת, כך שאין צורך במפתח ואין עלות.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/ct-' . getmypid();
@mkdir($tmp, 0777, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('CONFIG_FILE', $tmp . '/c.json');

require_once __DIR__ . '/../lib/order.php';
require_once __DIR__ . '/../lib/config.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    $ok = $got === $want;
    if ($ok) { $pass++; echo "  ok   $name\n"; }
    else { $fail++; echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
                     . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n"; }
}
function truthy(string $name, $got) { check($name, (bool) $got, true); }

$pdo = db();
$pdo->prepare('INSERT INTO users (username,password_hash,display_name,role,created_at) VALUES (?,?,?,?,?)')
    ->execute(['tester', 'x', 'בודק', 'admin', time()]);

function topic(string $name, string $kw = '', string $desc = ''): int {
    db()->prepare('INSERT INTO topics (name,description,repo,keywords,created_by,created_at) VALUES (?,?,?,?,1,?)')
        ->execute([$name, $desc, '', $kw, time()]);
    return (int) db()->lastInsertId();
}
function task(string $title, ?int $topic = null, string $priority = 'normal',
              string $body = '', string $kind = 'code', int $age = 0): int {
    db()->prepare('INSERT INTO tasks (topic_id,title,body,kind,priority,created_by,created_at,updated_at)
                   VALUES (?,?,?,?,?,1,?,?)')
        ->execute([$topic, $title, $body, $kind, $priority, time() - $age, time()]);
    return (int) db()->lastInsertId();
}

echo "\n— עיבוד טקסט —\n";
check('חיתוך תחילית', stemWord('לשיפוצים'), 'שיפוצ');
check('חיתוך סופית', stemWord('קבלנים'), 'קבלנ');
check('מילה קצרה נשמרת', stemWord('צבע'), 'צבע');
check('מילות עצירה יורדות', textTokens('אני צריך את זה בבקשה'), []);
check('ניקוד לא משנה', textTokens('שִׁיפּוּץ') === textTokens('שיפוץ'), true);
check('אנגלית עוברת ל-lowercase', textTokens('Deploy SSH'), ['deploy', 'ssh']);

echo "\n— שיוך לפי מילות מפתח —\n";
$shipuz = topic('שיפוצים', 'קבלן, צבע, אינסטלציה, ריצוף');
$deploy = topic('פריסה לשרת', 'ssh, rsync, github actions, cpanel', 'הפעלת האתר בשרת');
$design = topic('עיצוב', 'css, צבעים, פונט');

$profiles = topicProfiles($pdo);
$r = classifyByKeywords('לתאם עם הקבלן את הריצוף במטבח', '', $profiles);
check('מטלת שיפוצים', $r['topic_id'], $shipuz);
check('מקור: מילות מפתח', $r['source'], 'keyword');
truthy('ביטחון סביר', $r['confidence'] > 0.3);

$r = classifyByKeywords('להריץ rsync דרך ssh לשרת', '', $profiles);
check('מטלת פריסה', $r['topic_id'], $deploy);

$r = classifyByKeywords('לדבר עם אמא בטלפון', '', $profiles);
check('בלי התאמה — אין שיוך', $r['topic_id'], null);
check('מקור: אין', $r['source'], 'none');

$r = classifyByKeywords('ביטוי שלם: github actions', '', $profiles);
check('ביטוי רב-מילים נתפס', $r['topic_id'], $deploy);

echo "\n— למידה ממטלות קיימות —\n";
$cars = topic('רכב');   // בלי שום מילת מפתח
foreach (['להחליף שמן מנוע', 'טסט שנתי למנוע', 'בדיקת מנוע במוסך', 'תיקון מנוע'] as $t) task($t, $cars);
$profiles = topicProfiles($pdo);
$r = classifyByKeywords('רעש מוזר מהמנוע', '', $profiles);
check('נלמד מהמטלות הקיימות', $r['topic_id'], $cars);

echo "\n— המודל (תעבורה מזויפת) —\n";
$topics = [['id' => $shipuz, 'name' => 'שיפוצים', 'description' => ''],
           ['id' => $deploy, 'name' => 'פריסה', 'description' => '']];

$reply = fn(string $text) => fn($json, $key) => ['status' => 200,
    'body' => json_encode(['content' => [['type' => 'text', 'text' => $text]]], JSON_UNESCAPED_UNICODE)];

$r = classifyByModel($topics, 'לצבוע את הסלון', '', 'k', 'm',
        $reply('{"topic_id": ' . $shipuz . ', "new_topic": null, "confidence": 0.9, "reason": "צביעה"}'));
check('תשובת מודל תקינה', $r['topic_id'], $shipuz);
check('מקור: מודל', $r['source'], 'llm');

$r = classifyByModel($topics, 'x', '', 'k', 'm',
        $reply("בטח! הנה:\n```json\n{\"topic_id\": null, \"new_topic\": \"כספים\", \"confidence\": 0.8}\n```\nבהצלחה"));
check('JSON עטוף בטקסט', $r['hint'], 'כספים');
check('בלי נושא קיים', $r['topic_id'], null);

$r = classifyByModel($topics, 'x', '', 'k', 'm', $reply('{"topic_id": 9999, "confidence": 0.9}'));
check('מזהה שהומצא נדחה', $r['topic_id'], null);

$r = classifyByModel($topics, 'x', '', 'k', 'm',
        $reply('{"topic_id": ' . $shipuz . ', "confidence": 0.2, "new_topic": "אחר"}'));
check('ביטחון נמוך — לא משייכים', $r['topic_id'], null);

try {
    classifyByModel($topics, 'x', '', 'k', 'm', fn($j, $k) => ['status' => 401, 'body' => '{"error":{"message":"bad"}}']);
    check('401 זורק', false, true);
} catch (RuntimeException $e) { check('401 זורק חריגה', str_contains($e->getMessage(), 'נדחה'), true); }

echo "\n— נפילה חזרה למילות מפתח —\n";
$boom = function ($j, $k) { throw new RuntimeException('רשת נפלה'); };
$r = classifyTask($pdo, 'לתאם עם הקבלן ריצוף', '', ['key' => 'sk-ant-x', 'transport' => $boom]);
check('המודל נפל — מילות המפתח תפסו', $r['topic_id'], $shipuz);
check('מדווח על הנפילה', str_contains((string) $r['fallback'], 'רשת נפלה'), true);
check('המקור מסומן נכון', $r['source'], 'keyword');

$r = classifyTask($pdo, 'לצבוע את הסלון', '', ['key' => '']);
check('בלי מפתח — לא פונים למודל', $r['source'] !== 'llm', true);

echo "\n— בקשת המודל —\n";
$seen = null;
classifyByModel($topics, 'כותרת', 'גוף', 'sk-ant-key', 'my-model', function ($json, $key) use (&$seen) {
    $seen = ['payload' => json_decode($json, true), 'key' => $key];
    return ['status' => 200, 'body' => json_encode(['content' => [['type' => 'text', 'text' => '{"topic_id":null}']]])];
});
check('המודל שנשלח', $seen['payload']['model'], 'my-model');
check('המפתח מועבר', $seen['key'], 'sk-ant-key');
truthy('הנושאים בפרומפט', str_contains($seen['payload']['messages'][0]['content'], 'שיפוצים'));
truthy('הכותרת בפרומפט', str_contains($seen['payload']['messages'][0]['content'], 'כותרת'));

echo "\n— סידור —\n";
$proj = topic('פרויקט');
$t_ui   = task('לעצב את המסך הראשי', $proj, 'normal', '', 'code', 300);
$t_db   = task('להקים סכימה של מסד הנתונים', $proj, 'normal', '', 'code', 200);
$t_test = task('לכתוב בדיקות', $proj, 'normal', '', 'code', 100);
$t_hot  = task('לתקן קריסה בייצור', $proj, 'high', '', 'code', 0);

$order = heuristicOrder(db()->query("SELECT id,title,body,kind,priority,created_at FROM tasks WHERE topic_id=$proj")->fetchAll());
check('דחוף ראשון', $order[0], $t_hot);
check('תשתית לפני ממשק', array_search($t_db, $order, true) < array_search($t_ui, $order, true), true);
check('בדיקות אחרונות', $order[3], $t_test);

$deps = [$t_db => [$t_ui]];    // הסכימה תלויה בממשק — הפוך מהאינטואיציה
$adj  = applyDependencies($order, $deps);
check('תלות גוברת על ההערכה', array_search($t_ui, $adj, true) < array_search($t_db, $adj, true), true);
check('כל המטלות נשארו', count($adj), 4);

$cyc = applyDependencies([1, 2], [1 => [2], 2 => [1]]);
check('מעגל תלות לא תוקע', count($cyc), 2);

$t_dep = task("להריץ אחרי #$t_db", $proj, 'high', '', 'code', 0);
$r = reorderTopic($pdo, $proj);
check('נכתב סדר לכל המטלות', $r['ordered'], 5);
check('מקור הסידור', $r['source'], 'heuristic');
check('#N נאכף', array_search($t_db, $r['order'], true) < array_search($t_dep, $r['order'], true), true);
$seqs = db()->query("SELECT id,seq FROM tasks WHERE topic_id=$proj ORDER BY seq")->fetchAll();
check('seq בקפיצות של 10', $seqs[0]['seq'], 10);
check('seq עוקב את הסדר', (int) $seqs[0]['id'], $r['order'][0]);

echo "\n— סידור עם מודל —\n";
$tasks = db()->query("SELECT id,title,body,kind,priority,created_at FROM tasks WHERE topic_id=$proj")->fetchAll();
$ids   = array_map(fn($t) => (int) $t['id'], $tasks);
$rev   = array_reverse($ids);
$r = orderByModel($tasks, 'k', 'm', $reply(json_encode(['order' => $rev, 'reason' => 'כי כן'])));
check('סדר מהמודל מתקבל', $r['order'], $rev);

$partial = [$ids[0]];
$r = orderByModel($tasks, 'k', 'm', $reply(json_encode(['order' => array_merge($partial, [999999])])));
check('מזהה שהומצא מושמט', in_array(999999, $r['order'], true), false);
check('מטלות שנשכחו מצורפות', count($r['order']), count($ids));
check('מה שהמודל ביקש נשאר ראשון', $r['order'][0], $ids[0]);

try {
    orderByModel($tasks, 'k', 'm', $reply('{"order": []}'));
    check('סדר ריק זורק', false, true);
} catch (RuntimeException $e) { check('סדר ריק זורק חריגה', true, true); }

echo "\n— הגדרות —\n";
check('ברירת מחדל: אין מפתח', aiStatus()['has_key'], false);
check('ברירת מחדל: קטלוג פעיל', aiAuto(), true);
configSet(['anthropic_key' => 'sk-ant-abcd1234']);
check('המפתח נשמר', aiKey(), 'sk-ant-abcd1234');
check('רק הזנב נחשף', aiStatus()['key_tail'], '1234');
configSet(['anthropic_key' => null]);
check('אפשר לנתק', aiKey(), '');
check('הרשאות הקובץ', substr(sprintf('%o', fileperms(CONFIG_FILE)), -3), '600');

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
array_map('unlink', glob("$tmp/*") ?: []);
@rmdir($tmp);
exit($fail === 0 ? 0 : 1);
