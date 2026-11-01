<?php
/**
 * בדיקות עץ הפרויקטים: הרשאה שעוברת מהאב לתתי-הפרויקטים, הענף לסינון,
 * תיקייה כפולה ותיקייה לא תקינה.
 *
 *   php claude-tasks/tests/projects.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/ctp-' . getmypid();
@mkdir($tmp, 0777, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('CONFIG_FILE', $tmp . '/c.json');
define('SECRET_KEY_FILE', $tmp . '/secret.key');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/projects.php';
require_once __DIR__ . '/../lib/errors.php';

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

createUser('boss', 'pass-12345', 'מנהל');          // admin — הראשון
createUser('dana', 'pass-12345', 'דנה');           // member
$boss = ['id' => 1, 'role' => 'admin', 'username' => 'boss'];
$dana = ['id' => 2, 'role' => 'member', 'username' => 'dana'];

echo "\n— עץ —\n";
$root = createProject($boss, 'mini_projects', 'malkiel01', 'mini_projects');
$a = createSubproject($boss, $root, 'מתכונים', 'recipes-app', '🍲');
$b = createSubproject($boss, $root, '', 'kadisha');
$a1 = createSubproject($boss, $a, 'אנדרואיד', 'recipes-app/android');
check('שם ברירת מחדל — התיקייה', db()->query("SELECT name FROM projects WHERE id = $b")->fetch()['name'], 'kadisha');
check('הריפו עובר מהאב', db()->query("SELECT repo_name FROM projects WHERE id = $a1")->fetch()['repo_name'], 'mini_projects');
$sub = projectSubtree($root); sort($sub);
check('הענף של האב — כל הצאצאים', $sub, [$root, $a, $b, $a1]);
check('הענף של עלה', projectSubtree($b), [$b]);
throws('תיקייה כפולה נדחית', fn () => createSubproject($boss, $root, 'שוב', 'recipes-app'));
throws('תיקייה עם .. נדחית', fn () => createSubproject($boss, $root, 'x', '../etc'));

echo "\n— הרשאות —\n";
check('לא חברה — אין גישה', projectLevel($a1, $dana), null);
setMember($boss, $root, 2, 'write');
check('חברה באב — עובר לנכד', projectLevel($a1, $dana), 'write');
setMember($boss, $a, 2, 'read');
check('דרגה ישירה בתת-פרויקט גוברת', projectLevel($a1, $dana), 'read');
check('רשימה למי שאינו מנהל — כל הענף', count(listProjects($dana)), 4);
throws('חברה בכתיבה לא יוצרת תת-פרויקט', fn () => createSubproject($dana, $root, 'x', 'x'));

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exit($fail ? 1 : 0);
