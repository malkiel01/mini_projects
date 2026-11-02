<?php
/**
 * שלב 12c (docs/spec.md §5.11) — הצמדה ותוויות לפי סוג: נראות (משותפת / פרטית),
 * היקף (כל הלוח / פרויקט), הרשאות, ומחיקה שמנקה סימונים.
 *
 *   php claude-tasks/tests/labels.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/ctlbl-' . getmypid();
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
function throws(string $name, callable $fn, int $status = 0) {
    try { $fn(); check($name, 'לא נזרקה חריגה', 'חריגה'); }
    catch (AppError $e) { check($name . ' — ' . $e->getMessage(), $status === 0 || $e->status === $status, true); }
    catch (Throwable $e) { check($name . ' — ' . $e->getMessage(), $status === 0, true); }
}
function names(array $labels): array { return array_column($labels, 'name'); }
function msgOf(array $user, int $channelId, int $id): array {
    return array_values(array_filter(channelMessages($user, $channelId)['messages'], fn ($m) => $m['id'] === $id))[0];
}

createUser('boss', 'pass-12345', 'בעל הלוח');
createUser('dana', 'pass-12345', 'דנה');
createUser('rami', 'pass-12345', 'רמי');
$boss = ['id' => 1, 'role' => 'admin', 'username' => 'boss', 'display_name' => 'בעל הלוח'];
$dana = ['id' => 2, 'role' => 'member', 'username' => 'dana', 'display_name' => 'דנה'];
$rami = ['id' => 3, 'role' => 'member', 'username' => 'rami', 'display_name' => 'רמי'];
$root  = createProject($boss, 'Mini projects', 'malkiel01', 'mini_projects');
$suite = createSubproject($boss, $root, 'חבילת היצירה', 'creative-suite');
$rast  = createSubproject($boss, $suite, 'רסטר', 'raster-studio');
$other = createSubproject($boss, $root, 'מתכונים', 'recipes');
setMember($boss, $root, 2, 'write');
setMember($boss, $root, 3, 'read');
$scope = ['project_id' => null, 'channel_id' => null];
$ch = registerChannel($scope, $rast, 'היצירה v2 - רסטר', 'raster', 'session_lbl_a', '')['channel_id'];
$m1 = channelPost(getChannel($ch), 'chat', 'הוחלט: לוח השכבות משותף');
$m2 = channelPost(getChannel($ch), 'chat', 'באג בייצוא PNG');

echo "\n— יצירה לפי סוג —\n";
$decided = labelSave($boss, ['name' => '✅ החלטה', 'color' => 'green', 'visibility' => 'shared', 'project_id' => null]);
check('בעל הלוח — משותפת לכל הלוח', [$decided['visibility'], $decided['project_id']], ['shared', null]);
$suiteBug = labelSave($boss, ['name' => '🐞 באג', 'color' => 'red', 'visibility' => 'shared', 'project_id' => $suite]);
$recipes = labelSave($boss, ['name' => 'מתכונים', 'color' => 'amber', 'visibility' => 'shared', 'project_id' => $other]);
throws('דנה — לא יוצרת משותפת', fn () => labelSave($dana, ['name' => 'x', 'color' => 'blue', 'visibility' => 'shared', 'project_id' => $rast]), 403);
throws('דנה — לא יוצרת של כל הלוח (גם פרטית)', fn () => labelSave($dana, ['name' => 'x', 'color' => 'blue', 'visibility' => 'private', 'project_id' => null]), 403);
$danaLater = labelSave($dana, ['name' => '⏳ לבדוק', 'color' => 'violet', 'visibility' => 'private', 'project_id' => $rast]);
$ramiMine = labelSave($rami, ['name' => 'שלי', 'color' => 'teal', 'visibility' => 'private', 'project_id' => $rast]);
check('פרטית בפרויקט — כל מי שרואה (גם צפייה בלבד)', [$danaLater['visibility'], $ramiMine['created_by']], ['private', 3]);
throws('צבע שאינו מ-8', fn () => labelSave($boss, ['name' => 'x', 'color' => 'pink', 'visibility' => 'shared', 'project_id' => null]));
throws('שם ריק', fn () => labelSave($boss, ['name' => ' ', 'color' => 'blue', 'visibility' => 'shared', 'project_id' => null]));

echo "\n— מה כל אחד רואה בערוץ —\n";
check('בעל הלוח: של כל הלוח + של האב (חבילת היצירה); לא של פרויקט אח, ולא פרטיות של אחרים',
      names(labelsForChannel($boss, $ch)), ['✅ החלטה', '🐞 באג']);
$dn = names(labelsForChannel($dana, $ch)); sort($dn);
$want = ['✅ החלטה', '🐞 באג', '⏳ לבדוק']; sort($want);
check('דנה: גם הפרטית שלה', $dn, $want);

echo "\n— סימון —\n";
messageLabel($dana, $m1, $decided['id'], true);
messageLabel($dana, $m2, $danaLater['id'], true);
throws('צפייה בלבד — לא מסמן בתווית משותפת', fn () => messageLabel($rami, $m2, $suiteBug['id'], true), 403);
messageLabel($rami, $m2, $ramiMine['id'], true);
throws('תווית של פרויקט אח — לא זמינה כאן', fn () => messageLabel($boss, $m1, $recipes['id'], true), 403);
throws('תווית פרטית של מישהו אחר — לא', fn () => messageLabel($boss, $m2, $danaLater['id'], true), 403);
check('משותפת — כולם רואים', [names(msgOf($rami, $ch, $m1)['labels']), names(msgOf($boss, $ch, $m1)['labels'])], [['✅ החלטה'], ['✅ החלטה']]);
check('פרטית — רק מי שסימן', [names(msgOf($dana, $ch, $m2)['labels']), names(msgOf($boss, $ch, $m2)['labels']), names(msgOf($rami, $ch, $m2)['labels'])],
      [['⏳ לבדוק'], [], ['שלי']]);
messageLabel($dana, $m1, $decided['id'], true);
check('סימון פעמיים — בלי כפילות', count(msgOf($boss, $ch, $m1)['labels']), 1);
messageLabel($dana, $m1, $decided['id'], false);
check('הסרה', msgOf($boss, $ch, $m1)['labels'], []);

echo "\n— 📌 הצמדה —\n";
messagePin($dana, $m1, 'shared', true);
messagePin($rami, $m2, 'private', true);
throws('צפייה בלבד — לא מצמיד לכולם', fn () => messagePin($rami, $m1, 'shared', true), 403);
check('לכולם — כולם רואים; לי — רק אני', [msgOf($boss, $ch, $m1)['pinned'], msgOf($boss, $ch, $m2)['pinned'], msgOf($rami, $ch, $m2)['pinned']],
      [['shared' => true, 'mine' => false], ['shared' => false, 'mine' => false], ['shared' => false, 'mine' => true]]);
check('רשימת המוצמדות — לפי הצופה', [array_column(channelPins($boss, $ch), 'message_id'), array_column(channelPins($rami, $ch), 'message_id')],
      [[$m1], [$m2, $m1]]);
messagePin($dana, $m1, 'shared', false);
check('ביטול הצמדה', channelPins($boss, $ch), []);

echo "\n— עריכה ומחיקה —\n";
throws('דנה — לא עורכת משותפת', fn () => labelSave($dana, ['id' => $decided['id'], 'name' => 'x', 'color' => 'blue']), 403);
check('דנה — עורכת את הפרטית שלה (שם וצבע)', labelSave($dana, ['id' => $danaLater['id'], 'name' => '⏳ אחר כך', 'color' => 'blue'])['name'], '⏳ אחר כך');
messageLabel($dana, $m1, $suiteBug['id'], true);
messageLabel($dana, $m2, $suiteBug['id'], true);
throws('דנה — לא מוחקת משותפת', fn () => labelDelete($dana, $suiteBug['id']), 403);
check('מחיקה — מחזירה כמה סומנו, ומנקה את הסימונים', [labelDelete($boss, $suiteBug['id']), names(msgOf($boss, $ch, $m2)['labels'])], [2, []]);
throws('מחקה — כבר לא קיימת', fn () => messageLabel($boss, $m1, $suiteBug['id'], true), 404);

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
