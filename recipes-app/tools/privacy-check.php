<?php
/**
 * בדיקת privacy.php — העדפות, חסימה, דיווח ומחיקת חשבון — על מסד זמני.
 * הרצה: php recipes-app/tools/privacy-check.php
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/recipes-priv-' . getmypid();
@mkdir($tmp . '/media', 0775, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('MEDIA_DIR', $tmp . '/media');
@ini_set('sendmail_path', '/bin/true');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/network.php';
require_once __DIR__ . '/../lib/social.php';
require_once __DIR__ . '/../lib/privacy.php';

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
$mk = function (string $u, string $name) {
    $r = createUser($u, "$u@x.com", 'sod12345', $name);
    if (!empty($r['token'])) verifyEmail($r['token']);
    $row = db()->query('SELECT role FROM users WHERE id = ' . (int) $r['id'])->fetch();
    return ['id' => $r['id'], 'role' => $row['role'], 'username' => $u, 'display_name' => $name];
};
$dev  = $mk('dev', 'המפתח');
$dana = $mk('dana', 'דנה');
$noa  = $mk('noa', 'נועה');
$sec = [['ingredients' => [['free_text' => 'קמח']], 'steps' => [['text' => 'לאפות']]]];
$danaPub  = saveRecipe(['title' => 'עוגה של דנה', 'visibility' => 'public', 'sections' => $sec], $dana);
$danaPriv = saveRecipe(['title' => 'סוד של דנה', 'visibility' => 'private', 'sections' => $sec], $dana);
$noaPriv  = saveRecipe(['title' => 'סוד של נועה', 'visibility' => 'private', 'sections' => $sec], $noa);
$names = fn(array $l) => array_column($l, 'username');

echo "\n1. העדפות\n";
check('ברירת מחדל: מוצאים אותי, כולם משתפים, התאמה אישית', userPrefs($noa['id']), ['discoverable' => true, 'share_from' => 'all', 'personalize' => true]);
expectError('ערך לא מוכר', fn() => setUserPrefs(['share_from' => 'friends'], $GLOBALS['noa']), 'לא מוכר');
setUserPrefs(['discoverable' => false], $noa);
check('בלתי-מוצאת — לא בחיפוש אנשים', $names(peopleSearch('נועה', $dana)), []);
setUserPrefs(['discoverable' => true, 'share_from' => 'none'], $noa);
check('שוב מוצאת', $names(peopleSearch('נועה', $dana)), ['noa']);
expectError('"אף אחד לא משתף איתי" — שיתוף נדחה', fn() => shareRecipe($GLOBALS['danaPriv'], [$GLOBALS['noa']['id']], $GLOBALS['dana']), 'לא מקבל');
setUserPrefs(['share_from' => 'following'], $noa);
expectError('"רק מי שאני עוקבת אחריו" — דנה עוד לא', fn() => shareRecipe($GLOBALS['danaPriv'], [$GLOBALS['noa']['id']], $GLOBALS['dana']), 'לא מקבל');
followUser($dana['id'], $noa);
check('נועה עוקבת אחרי דנה — עכשיו מותר', $names(shareRecipe($danaPriv, [$noa['id']], $dana)), ['noa']);
setUserPrefs(['share_from' => 'all'], $noa);
followUser($noa['id'], $dana);
shareRecipe($noaPriv, [$dana['id']], $noa);

echo "\n2. חסימה\n";
expectError('לא את עצמי', fn() => blockUser($GLOBALS['noa']['id'], $GLOBALS['noa']), 'עצמך');
blockUser($dana['id'], $noa);
check('המעקבים נמחקו בשני הכיוונים', (int) db()->query('SELECT COUNT(*) FROM follows')->fetchColumn(), 0);
check('השיתופים נמחקו בשני הכיוונים', (int) db()->query('SELECT COUNT(*) FROM recipe_shares')->fetchColumn(), 0);
check('דנה כבר לא רואה את הסוד של נועה', loadRecipe($noaPriv, $dana), null);
check('חיפוש אנשים — לא רואות זו את זו', [$names(peopleSearch('דנה', $noa)), $names(peopleSearch('נועה', $dana))], [[], []]);
expectError('דנה לא עוקבת אחרי נועה', fn() => followUser($GLOBALS['noa']['id'], $GLOBALS['dana']), 'אינו קיים');
expectError('ולא משתפת איתה', fn() => shareRecipe($GLOBALS['danaPriv'], [$GLOBALS['noa']['id']], $GLOBALS['dana']), 'לא מקבל');
expectError('הפרופיל של נועה — לא קיים בעיני דנה', fn() => personProfile($GLOBALS['noa']['id'], $GLOBALS['dana']), 'אינו קיים');
check('ובעיני נועה — "חסמת", בלי מתכונים', [personProfile($dana['id'], $noa)['person']['blocked_by_me'], personProfile($dana['id'], $noa)['recipes']], [true, []]);
check('המתכון הציבורי של דנה — לא ברשימה של נועה', in_array($danaPub, array_column(searchRecipes($noa), 'id')), false);
$cid = addComment($danaPub, null, 'יצא מעולה', $dev);
expectError('נועה לא מגיבה אצל דנה', fn() => addComment($GLOBALS['danaPub'], null, 'היי', $GLOBALS['noa']), 'אי אפשר להגיב');
check('רשימת החסומים של נועה', $names(myBlocks($noa)), ['dana']);
unblockUser($dana['id'], $noa);
check('ביטול חסימה — שוב בחיפוש וברשימה', [$names(peopleSearch('דנה', $noa)), in_array($danaPub, array_column(searchRecipes($noa), 'id'))], [['dana'], true]);
blockUser($dev['id'], $noa);
check('תגובה של מי שחסמתי — מוסתרת אצלי בלבד', [count(listComments($danaPub, $noa)), count(listComments($danaPub, $dana))], [0, 1]);
unblockUser($dev['id'], $noa);

echo "\n3. דיווח\n";
expectError('סיבה לא מוכרת', fn() => reportContent('recipe', $GLOBALS['danaPub'], 'meh', '', $GLOBALS['noa']), 'סיבה');
expectError('על תוכן שלי — לא', fn() => reportContent('recipe', $GLOBALS['danaPub'], 'spam', '', $GLOBALS['dana']), 'שלך');
expectError('על פרטי שאיני רואה — לא קיים', fn() => reportContent('recipe', $GLOBALS['danaPriv'], 'spam', '', $GLOBALS['noa']), 'אינו קיים');
reportContent('recipe', $danaPub, 'copyright', 'מועתק מאתר', $noa);
reportContent('recipe', $danaPub, 'copyright', 'שוב', $noa);
reportContent('comment', $cid, 'offensive', '', $noa);
reportContent('user', $dana['id'], 'spam', '', $noa);
expectError('רק המפתח רואה את התור', fn() => reportsList($GLOBALS['noa']), 'מפתח');
$open = reportsList($dev);
check('בתור: 3 (דיווח כפול נספר פעם אחת), עם הצילום של מה שדווח', [count($open), $open[2]['snapshot'], $open[2]['reason_label']], [3, 'עוגה של דנה', 'הפרת זכויות יוצרים']);
$byKind = array_column($open, null, 'kind');
reportResolve($byKind['recipe']['id'], 'hide', $dev);
check('הסתרה — המתכון פרטי, והדיווח נסגר', [loadRecipe($danaPub, $dana)['visibility'], count(reportsList($dev))], ['private', 2]);
reportResolve($byKind['comment']['id'], 'delete', $dev);
check('מחיקת תגובה', (int) db()->query("SELECT COUNT(*) FROM comments WHERE id = $cid")->fetchColumn(), 0);
reportResolve($byKind['user']['id'], 'dismiss', $dev);
check('דחייה — התור ריק, וההיסטוריה שמורה', [count(reportsList($dev)), count(reportsList($dev, 'closed'))], [0, 3]);
reportContent('user', $dana['id'], 'spam', '', $noa);
reportResolve(reportsList($dev)[0]['id'], 'block', $dev);
check('חסימה באתר — המשתמשת חסומה', (int) db()->query('SELECT blocked FROM users WHERE id = ' . (int) $dana['id'])->fetchColumn(), 1);
db()->exec('UPDATE users SET blocked = 0 WHERE id = ' . (int) $dana['id']);

echo "\n4. מחיקת חשבון\n";
file_put_contents(MEDIA_DIR . '/abc.jpg', 'x');
db()->prepare("INSERT INTO media (recipe_id, uploader_id, kind, source, path_or_url, mime, bytes, position, created_at) VALUES (?,?,'image','upload','abc.jpg','image/jpeg',1,1,?)")
    ->execute([$noaPriv, $noa['id'], nowIso()]);
logEvent('info', 'test', '', [], $noa);
expectError('סיסמה שגויה — לא נמחק', fn() => deleteAccount($GLOBALS['noa'], 'wrong'), 'שגויה');
deleteAccount($noa, 'sod12345');
check('המשתמשת, המתכונים והקובץ — נמחקו', [(int) db()->query('SELECT COUNT(*) FROM users WHERE id = ' . (int) $noa['id'])->fetchColumn(),
      (int) db()->query('SELECT COUNT(*) FROM recipes WHERE owner_id = ' . (int) $noa['id'])->fetchColumn(), is_file(MEDIA_DIR . '/abc.jpg')], [0, 0, false]);
check('ביומן — השורות נשארו, בלי השם', [(int) db()->query('SELECT COUNT(*) FROM app_log WHERE user_id = ' . (int) $noa['id'])->fetchColumn() > 0,
      (int) db()->query("SELECT COUNT(*) FROM app_log WHERE username = 'noa'")->fetchColumn()], [true, 0]);
check('הדיווחים שלה נשארו, בלי מי דיווח', db()->query('SELECT reporter_id FROM reports ORDER BY id LIMIT 1')->fetchColumn(), null);

echo "\n";
@array_map('unlink', glob("$tmp/{,media/}*", GLOB_BRACE) ?: []);
if ($fail) { echo "\u{274C} נכשלו " . count($fail) . ': ' . implode(' | ', $fail) . "\n"; exit(1); }
echo "\u{2705} כל הבדיקות עברו\n";
