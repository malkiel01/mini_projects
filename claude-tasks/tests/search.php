<?php
/**
 * שלב 12d (docs/spec.md §5.11) — חיפוש, סינון וייצוא בשיחה.
 *
 *   php claude-tasks/tests/search.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/ctsrch-' . getmypid();
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
function throws(string $name, callable $fn) {
    try { $fn(); check($name, 'לא נזרקה חריגה', 'חריגה'); }
    catch (Throwable $e) { check($name . ' — ' . $e->getMessage(), true, true); }
}

createUser('boss', 'pass-12345', 'בעל הלוח');
createUser('dana', 'pass-12345', 'דנה');
$boss = ['id' => 1, 'role' => 'admin', 'username' => 'boss', 'display_name' => 'בעל הלוח'];
$dana = ['id' => 2, 'role' => 'member', 'username' => 'dana', 'display_name' => 'דנה'];
$root = createProject($boss, 'Mini projects', 'malkiel01', 'mini_projects');
setMember($boss, $root, 2, 'write');
$ch = registerChannel(['project_id' => null, 'channel_id' => null], $root, 'היצירה v2 - רסטר', 'raster', 'session_srch_a', '')['channel_id'];
$c = getChannel($ch);
$s = fn (array $u, array $f) => array_column(channelSearch($u, $ch, $f), 'id');

$a = channelPost($c, 'chat', 'הוחלט: לוח השכבות משותף ב-ui-core');
$b = channelPost($c, 'chat', 'באג: ייצוא PNG מאבד שקיפות', null, null, 'question');
$d = channelSend($boss, $ch, 'תבדוק את השקיפות בטלפון');
$e = channelPost($c, 'system', 'הצ\'אט נרשם מחדש');
$f100 = channelPost($c, 'chat', 'הנחה של 100% ו-a_b');
db()->prepare('UPDATE messages SET created_at = ? WHERE id = ?')->execute([strtotime('2026-10-01 12:00'), $a]);

echo "\n— 🔎 טקסט —\n";
check('מילה בעברית', $s($boss, ['q' => 'שקיפות']), [$b, $d]);
check('גם באמצע מילה', $s($boss, ['q' => 'שכבות']), [$a]);
check('באנגלית — בלי רישיות', $s($boss, ['q' => 'png']), [$b]);
check('% ו-_ הם תווים רגילים (לא תווים כלליים)', [$s($boss, ['q' => '100%']), $s($boss, ['q' => 'a_b']), $s($boss, ['q' => '%'])], [[$f100], [$f100], [$f100]]);
check('בלי טקסט ובלי סינון — הכול', count($s($boss, [])), 6);   // + "הצטרף"

echo "\n— 🏷 סינון —\n";
$shared = labelSave($boss, ['name' => '✅ החלטה', 'color' => 'green', 'visibility' => 'shared', 'project_id' => null]);
$mine = labelSave($dana, ['name' => 'שלי', 'color' => 'violet', 'visibility' => 'private', 'project_id' => $root]);
messageLabel($boss, $a, $shared['id'], true);
messageLabel($dana, $b, $mine['id'], true);
check('לפי תווית משותפת', $s($boss, ['labels' => [$shared['id']]]), [$a]);
check('תווית פרטית של אחר — לא מסננת לפיה (אין תוצאות)', $s($boss, ['labels' => [$mine['id']]]), []);
check('…אבל לבעלים שלה כן', $s($dana, ['labels' => [$mine['id']]]), [$b]);
check('כמה תוויות — כל אחת מהן', $s($dana, ['labels' => [$shared['id'], $mine['id']]]), [$a, $b]);
messagePin($boss, $d, 'shared', true);
messagePin($dana, $e, 'private', true);
check('📌 מוצמדות — לכולם + שלי', [$s($boss, ['pinned' => true]), $s($dana, ['pinned' => true])], [[$d], [$d, $e]]);
check('סוג: שאלות', $s($boss, ['kinds' => ['question']]), [$b]);
check('מי כתב: אנשים בלוח', $s($boss, ['authors' => ['board']]), [$d]);
check('מי כתב: מערכת', count($s($boss, ['authors' => ['system']])), 2);   // "הצטרף" + "נרשם מחדש"
check('תאריכים: עד סוף אוקטובר', $s($boss, ['to' => '2026-10-31']), [$a]);
check('תאריכים: מנובמבר', in_array($a, $s($boss, ['from' => '2026-11-01']), true), false);
throws('תאריך לא תקין', fn () => channelSearch($boss, $ch, ['from' => 'אתמול']));
check('שילוב: טקסט + מי כתב + סוג', $s($boss, ['q' => 'שקיפות', 'authors' => ['chat'], 'kinds' => ['question']]), [$b]);
check('סוג / כותב לא מוכר — מתעלמים', count($s($boss, ['kinds' => ['bogus'], 'authors' => ['x']])), 6);

echo "\n— ⬇️ ייצוא —\n";
$all = channelExport($boss, $ch, []);
check('כל השיחה — כותרת, ספירה וקובץ md', [str_starts_with($all['markdown'], '# היצירה v2 - רסטר — ערוץ #'), $all['count'], str_ends_with($all['filename'], '.md')], [true, 6, true]);
check('הסימון המשותף בפנים', str_contains($all['markdown'], '[✅ החלטה]'), true);
check('בעל הלוח — בלי התווית הפרטית של דנה', str_contains($all['markdown'], 'שלי'), false);
$dx = channelExport($dana, $ch, ['labels' => [$mine['id']]]);
check('דנה — מסונן, עם הסימון הפרטי שלה', [$dx['count'], str_contains($dx['markdown'], '🔒[שלי]'), str_contains($dx['markdown'], 'מסונן')], [1, true, true]);
check('הגוף נשמר כמו שהוא (Markdown)', str_contains($all['markdown'], 'הוחלט: לוח השכבות משותף ב-ui-core'), true);

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
