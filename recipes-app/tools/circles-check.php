<?php
/**
 * בדיקת circles.php — מעגלים: חברות דינמית, שיתוף מתכון ואוסף עם מעגל, הסרה
 * עצמית, חסימה ו"מי משתף איתי", והמלצה למעגל — על מסד זמני.
 * הרצה: php recipes-app/tools/circles-check.php
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/recipes-circ-' . getmypid();
@mkdir($tmp . '/media', 0775, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('MEDIA_DIR', $tmp . '/media');
@ini_set('sendmail_path', '/bin/true');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/social.php';
require_once __DIR__ . '/../lib/collections.php';
require_once __DIR__ . '/../lib/circles.php';

$fail = [];
function check(string $label, $got, $want): void {
    global $fail;
    $ok = $got === $want;
    echo ($ok ? "  \u{2705} " : "  \u{274C} ") . $label . ': ' . var_export($got, true) .
         ($ok ? "\n" : '  (צפוי ' . var_export($want, true) . ")\n");
    if (!$ok) $fail[] = $label;
}
function expectError(string $label, callable $fn, string $needle = ''): void {
    global $fail;
    try { $fn(); echo "  \u{274C} $label: לא נזרקה שגיאה\n"; $fail[] = $label; }
    catch (AppError $e) {
        $ok = $needle === '' || str_contains($e->getMessage(), $needle);
        echo ($ok ? "  \u{2705} " : "  \u{274C} ") . "$label: \"{$e->getMessage()}\"\n";
        if (!$ok) $fail[] = $label;
    }
}
$mk = function (string $u) {
    $r = createUser($u, "$u@x.com", 'sod12345', $u);
    if (!empty($r['token'])) verifyEmail($r['token']);
    $row = db()->query('SELECT role FROM users WHERE id = ' . (int) $r['id'])->fetch();
    return ['id' => $r['id'], 'role' => $row['role'], 'username' => $u, 'display_name' => $u];
};
$dana = $mk('dana'); $noa = $mk('noa'); $eli = $mk('eli'); $sara = $mk('sara');
$sec = [['ingredients' => [['free_text' => 'קמח']], 'steps' => [['text' => 'לאפות']]]];
$secret = saveRecipe(['title' => 'הסוד של דנה', 'visibility' => 'private', 'sections' => $sec], $dana);
$sees = fn(array $u) => loadRecipe($GLOBALS['secret'], $u) !== null;
$who = fn() => [$sees($GLOBALS['noa']), $sees($GLOBALS['eli']), $sees($GLOBALS['sara'])];

echo "\n1. מעגל\n";
expectError('בלי שם', fn() => circleCreate(['name' => ' '], $GLOBALS['dana']), 'שם');
$fam = circleCreate(['name' => 'משפחה', 'icon' => '👪', 'user_ids' => [$noa['id'], $eli['id']]], $dana);
check('נוצר עם שני חברים', [$fam['circle']['name'], $fam['circle']['n'], array_column($fam['members'], 'username')], ['משפחה', 2, ['eli', 'noa']]);
$fid = $fam['circle']['id'];
expectError('לא את עצמי', fn() => circleAddMembers($GLOBALS['fid'], [$GLOBALS['dana']['id']], $GLOBALS['dana']), 'לא נבחרו');
expectError('מעגל של אחר — לא קיים', fn() => circleView($GLOBALS['fid'], $GLOBALS['noa']), 'אינו קיים');
expectError('לא משתפים מתכון של אחר עם המעגל שלי', fn() => shareWithCircle('recipe', $GLOBALS['secret'], $GLOBALS['fid'], $GLOBALS['noa']), 'אינו שלך');

echo "\n2. שיתוף מתכון עם מעגל — חברות דינמית (החלטה 4)\n";
check('לפני: אף אחד', $who(), [false, false, false]);
check('שיתוף עם המשפחה', array_column(shareWithCircle('recipe', $secret, $fid, $dana), 'name'), ['משפחה']);
check('נועה ואלי רואים; שרה לא', $who(), [true, true, false]);
check('אצל נועה — ב"שותפו איתי", חדש', array_map(fn($r) => [$r['title'], $r['shared_new']], searchRecipes($noa, '', ['shared' => true])), [['הסוד של דנה', true]]);
check('ברשימת הנמענים בשם — אף אחד (הם דרך המעגל)', recipeShares($secret, $dana), []);
check('אבל המתכון מגיע לשניים', recipeShareReach($secret), 2);
circleAddMembers($fid, [$sara['id']], $dana);
check('שרה נוספה למשפחה — רואה מיד', $who(), [true, true, true]);
circleRemoveMember($fid, $eli['id'], $dana);
check('אלי יצא — כבר לא רואה', $who(), [true, false, true]);

echo "\n3. ישיר ודרך מעגל יחד\n";
shareRecipe($secret, [$noa['id']], $dana);
check('נועה גם בשם', array_column(recipeShares($secret, $dana), 'username'), ['noa']);
circleRemoveMember($fid, $noa['id'], $dana);
check('יצאה מהמעגל — עדיין רואה (שותפה בשם)', $sees($noa), true);
circleAddMembers($fid, [$noa['id']], $dana);
unshareRecipe($secret, $noa['id'], $dana);
check('ביטול השיתוף בשם — עדיין רואה דרך המעגל', [$sees($noa), recipeShares($secret, $dana)], [true, []]);

echo "\n4. מי שהסיר את עצמו\n";
leaveShare($secret, $sara);
check('שרה הסירה — לא רואה', $sees($sara), false);
circleAddMembers($fid, [$eli['id']], $dana);     // סנכרון מלא של המעגל
check('שינוי במעגל לא מחזיר אותה', $sees($sara), false);
shareRecipe($secret, [$sara['id']], $dana);
check('שיתוף בשם — כן מחזיר (הבעלים בחר בה שוב)', $sees($sara), true);
unshareRecipe($secret, $sara['id'], $dana);

echo "\n5. מי שלא מקבל ממני\n";
setUserPrefs(['share_from' => 'none'], $eli);
circleRemoveMember($fid, $eli['id'], $dana);
circleAddMembers($fid, [$eli['id']], $dana);
$v = circleView($fid, $dana);
check('אלי במעגל, מסומן "לא מקבל", ולא רואה', [array_column($v['members'], 'reachable', 'username')['eli'], $sees($eli)], [false, false]);
setUserPrefs(['share_from' => 'all'], $eli);
circleRemoveMember($fid, $eli['id'], $dana); circleAddMembers($fid, [$eli['id']], $dana);
check('שינה את ההעדפה — מקבל בסנכרון הבא', $sees($eli), true);
blockUser($dana['id'], $eli);
check('חסם את דנה — יצא מהמעגל ולא רואה', [in_array('eli', array_column(circleView($fid, $dana)['members'], 'username'), true), $sees($eli)], [false, false]);
unblockUser($dana['id'], $eli);
expectError('אחרי חסימה — אי אפשר להוסיף', fn() => (function () { blockUser($GLOBALS['dana']['id'], $GLOBALS['eli']); circleAddMembers($GLOBALS['fid'], [$GLOBALS['eli']['id']], $GLOBALS['dana']); })(), 'אינו קיים');
unblockUser($dana['id'], $eli);

echo "\n6. אוסף עם מעגל\n";
$book = collectionCreate(['name' => 'ספר מתכונים'], $dana);
$soup = saveRecipe(['title' => 'מרק פרטי', 'visibility' => 'private', 'sections' => $sec], $dana);
collectionToggle($book['id'], ['recipe_id' => $soup], true, $dana);
shareWithCircle('collection', $book['id'], $fid, $dana);
check('נועה רואה את האוסף, ואת המרק הפרטי שבו', [array_column(collectionsMine($noa)['shared'], 'name'), loadRecipe($soup, $noa)['title'] ?? null], [['ספר מתכונים'], 'מרק פרטי']);
check('במסך המעגל: משותפים איתו מתכון אחד ואוסף אחד', circleView($fid, $dana)['used'], ['recipe' => 1, 'collection' => 1]);
unshareWithCircle('collection', $book['id'], $fid, $dana);
check('ביטול — האוסף והמרק נעלמו אצלה', [collectionsMine($noa)['shared'], loadRecipe($soup, $noa)], [[], null]);

echo "\n7. המלצה למעגל, ומחיקת מעגל\n";
setUserPrefs(['share_from' => 'none'], $sara);
check('המלצה: החברים שאפשר להגיע אליהם (שרה — לא)', array_map(fn($i) => db()->query("SELECT username FROM users WHERE id = $i")->fetchColumn(), circleMemberIds([$fid], $dana)), ['noa']);
expectError('מעגל של אחר בהמלצה', fn() => circleMemberIds([$GLOBALS['fid']], $GLOBALS['noa']), 'אינו קיים');
circleDelete($fid, $dana);
check('נמחק — מי שקיבל רק דרכו כבר לא רואה', [$sees($noa), recipeShareReach($secret), count(circlesMine($dana))], [false, 0, 0]);

echo "\n";
@array_map('unlink', glob("$tmp/{,media/}*", GLOB_BRACE) ?: []);
if ($fail) { echo "\u{274C} נכשלו " . count($fail) . ': ' . implode(' | ', $fail) . "\n"; exit(1); }
echo "\u{2705} כל הבדיקות עברו\n";
