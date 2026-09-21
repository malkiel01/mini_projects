<?php
/**
 * בדיקת social.php — תגובות, פתקים פרטיים ומועדפים — על מסד זמני.
 * הרצה: php recipes-app/tools/social-check.php
 *
 * מוכיחה את ההכרעות של סעיפים 6–7: תגובה רק בציבורי ורק כשפתוח, שתי רמות
 * בלבד, מי מוחק ומי עורך, "נכתבה לפני עדכון", פתק אחד לכל משתמש, ומועדף
 * ששורד מחיקה והסתרה של המקור.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/recipes-social-' . getmypid();
@mkdir($tmp, 0775, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('MEDIA_DIR', $tmp . '/media');
@ini_set('sendmail_path', '/bin/true');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/recipes.php';
require_once __DIR__ . '/../lib/social.php';

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

$david = createUser('david', 'd@example.com', 'sod12345', 'דוד');      // הראשון = מנהל
$mali  = createUser('mali',  'm@example.com', 'sod12345', 'מלי');  verifyEmail($mali['token']);
$noa   = createUser('noa',   'n@example.com', 'sod12345', 'נועה'); verifyEmail($noa['token']);
$davidU = ['id' => $david['id'], 'role' => 'admin'];
$maliU  = ['id' => $mali['id'],  'role' => 'user'];
$noaU   = ['id' => $noa['id'],   'role' => 'user'];

$simple = fn(string $title, string $vis) => [
    'title' => $title, 'visibility' => $vis,
    'sections' => [['ingredients' => [['free_text' => '1 כוס קמח']], 'steps' => [['text' => 'לערבב']]]],
];
$pubId  = saveRecipe($simple('עוגה ציבורית', 'public'),  $maliU);
$privId = saveRecipe($simple('סלט פרטי',     'private'), $maliU);

echo "\n1. תגובה — רק בציבורי, רק כשפתוח\n";
$c1 = addComment($pubId, null, 'יצא מעולה', $noaU);
check('נועה מגיבה במתכון של מלי', $c1 > 0, true);
expectError('אין תגובות במתכון פרטי — גם לבעליו', fn() => addComment($GLOBALS['privId'], null, 'x', $GLOBALS['maliU']), 'ציבורי');
expectError('זר אינו רואה פרטי — 404 אחיד', fn() => addComment($GLOBALS['privId'], null, 'x', $GLOBALS['noaU']), 'אינו קיים');
expectError('תגובה ריקה', fn() => addComment($GLOBALS['pubId'], null, '   ', $GLOBALS['noaU']), 'ריקה');
expectError('תגובה ארוכה מדי', fn() => addComment($GLOBALS['pubId'], null, str_repeat('א', 2001), $GLOBALS['noaU']), 'ארוכה');
setCommentsOpen($pubId, false, $maliU);
expectError('הכותב סגר תגובות', fn() => addComment($GLOBALS['pubId'], null, 'x', $GLOBALS['noaU']), 'סגר');
expectError('רק הבעלים סוגר', fn() => setCommentsOpen($GLOBALS['pubId'], true, $GLOBALS['noaU']));
setCommentsOpen($pubId, true, $maliU);
check('ואחרי פתיחה — שוב אפשר', addComment($pubId, null, 'שנית', $noaU) > 0, true);

echo "\n2. שרשור — שתי רמות בלבד\n";
$reply = addComment($pubId, $c1, 'תודה!', $maliU);
$deep  = addComment($pubId, $reply, 'בכיף', $noaU);   // תשובה לתשובה
$tree  = listComments($pubId, $noaU);
check('שתי תגובות ראשיות', count($tree), 2);
check('לראשונה שתי תשובות — העמוקה נתלתה על הראשית', count($tree[0]['replies']), 2);
check('התשובה מציגה את שם המגיב', $tree[0]['replies'][0]['user_name'], 'מלי');
expectError('תשובה לתגובה שאינה קיימת', fn() => addComment($GLOBALS['pubId'], 9999, 'x', $GLOBALS['noaU']), 'אינה קיימת');
expectError('תשובה לתגובה ממתכון אחר', function () {
    $other = saveRecipe($GLOBALS['simple']('אחר', 'public'), $GLOBALS['noaU']);
    addComment($other, $GLOBALS['c1'], 'x', $GLOBALS['noaU']);
}, 'אינה קיימת');

echo "\n3. מי עורך ומי מוחק\n";
expectError('מלי אינה עורכת תגובה של נועה', fn() => editComment($GLOBALS['c1'], 'שונה', $GLOBALS['maliU']), 'שכתבת');
editComment($c1, 'יצא מעולה — ערכתי', $noaU);
$tree = listComments($pubId, $noaU);
check('הכותבת ערכה, וסומן', [$tree[0]['text'], $tree[0]['edited_at'] !== null], ['יצא מעולה — ערכתי', true]);
check('הרשאות בעיני נועה: עורכת ומוחקת את שלה', [$tree[0]['can_edit'], $tree[0]['can_delete']], [true, true]);
$treeMali = listComments($pubId, $maliU);
check('בעיני מלי (בעלת המתכון): לא עורכת, כן מוחקת', [$treeMali[0]['can_edit'], $treeMali[0]['can_delete']], [false, true]);
$treeDavid = listComments($pubId, $davidU);
check('בעיני המנהל: מוחק', $treeDavid[0]['can_delete'], true);
$treeGuest = listComments($pubId, null);
check('אורח רואה תגובות במתכון ציבורי, בלי הרשאות', [count($treeGuest), $treeGuest[0]['can_delete']], [2, false]);
$third = createUser('zar', 'z@example.com', 'sod12345', 'זר'); verifyEmail($third['token']);
expectError('זר אינו מוחק', fn() => deleteComment($GLOBALS['c1'], ['id' => $GLOBALS['third']['id'], 'role' => 'user']), 'הרשאה');
deleteComment($c1, $maliU);   // בעלת המתכון
$tree = listComments($pubId, $noaU);
check('נמחקה — ואיתה שתי התשובות (CASCADE)', [count($tree), (int) db()->query('SELECT COUNT(*) FROM comments')->fetchColumn()], [1, 1]);

echo "\n4. \"נכתבה לפני עדכון המתכון\" ומתכון שהפך לפרטי\n";
sleep(1);   // ISO ברזולוציית שנייה — העדכון חייב להיות מאוחר יותר
saveRecipe($simple('עוגה ציבורית — מתוקן', 'public'), $maliU, $pubId);
$tree = listComments($pubId, $noaU);
check('התגובה שנותרה מסומנת', $tree[0]['before_update'], true);
$new = addComment($pubId, null, 'אחרי התיקון', $noaU);
check('תגובה חדשה — לא מסומנת', listComments($pubId, $noaU)[1]['before_update'], false);
saveRecipe($simple('עוגה ציבורית — מתוקן', 'private'), $maliU, $pubId);
check('הפך לפרטי: הבעלים רואה רשימה ריקה', listComments($pubId, $maliU), []);
check('אבל התגובות נשמרו במסד', (int) db()->query('SELECT COUNT(*) FROM comments WHERE recipe_id = ' . $pubId)->fetchColumn(), 2);
saveRecipe($simple('עוגה ציבורית — מתוקן', 'public'), $maliU, $pubId);
check('חזר לציבורי: התגובות חזרו', count(listComments($pubId, $noaU)), 2);

echo "\n5. comments_open דרך saveRecipe\n";
saveRecipe($simple('עוגה', 'public') + ['comments_open' => false], $maliU, $pubId);
check('נשלח false — נסגר', (int) db()->query('SELECT comments_open FROM recipes WHERE id = ' . $pubId)->fetchColumn(), 0);
saveRecipe($simple('עוגה', 'public'), $maliU, $pubId);
check('לא נשלח — נשאר סגור', (int) db()->query('SELECT comments_open FROM recipes WHERE id = ' . $pubId)->fetchColumn(), 0);
saveRecipe($simple('עוגה', 'public') + ['comments_open' => true], $maliU, $pubId);
check('נשלח true — נפתח', (int) db()->query('SELECT comments_open FROM recipes WHERE id = ' . $pubId)->fetchColumn(), 1);
$fresh = saveRecipe($simple('חדש', 'private'), $noaU);
check('מתכון חדש בלי השדה — פתוח', (int) db()->query('SELECT comments_open FROM recipes WHERE id = ' . $fresh)->fetchColumn(), 1);

echo "\n6. פתק פרטי — אחד לכל משתמש, על כל מתכון שרואים\n";
check('אין פתק', getNote($pubId, $noaU), null);
$n = setNote($pubId, 'אצלי 5 דקות פחות', $noaU);
check('נשמר', $n['text'], 'אצלי 5 דקות פחות');
setNote($pubId, 'אצלי 7 דקות פחות', $noaU);
check('עדכון מחליף ולא מכפיל', [getNote($pubId, $noaU)['text'],
      (int) db()->query("SELECT COUNT(*) FROM comments WHERE visibility='private_note'")->fetchColumn()], ['אצלי 7 דקות פחות', 1]);
check('מלי אינה רואה את הפתק של נועה', getNote($pubId, $maliU), null);
check('הפתק אינו מופיע בתגובות', count(listComments($pubId, $maliU)), 2);
check('פתק על מתכון פרטי שלי', setNote($privId, 'לפסח', $maliU)['text'], 'לפסח');
expectError('אין פתק על פרטי של אחר', fn() => setNote($GLOBALS['privId'], 'x', $GLOBALS['noaU']), 'אינו קיים');
check('טקסט ריק מוחק', setNote($pubId, '  ', $noaU), null);
check('ואין פתק', getNote($pubId, $noaU), null);
expectError('פתק ארוך מדי', fn() => setNote($GLOBALS['pubId'], str_repeat('א', 4001), $GLOBALS['noaU']), 'ארוך');

echo "\n7. מועדפים — מצביעים על המקור ושורדים את היעלמותו\n";
expectError('אין לשמור מתכון של עצמי', fn() => toggleFavorite($GLOBALS['pubId'], $GLOBALS['maliU']), 'שלך');
expectError('אין לשמור פרטי של אחר', fn() => toggleFavorite($GLOBALS['privId'], $GLOBALS['noaU']), 'אינו קיים');
check('שמירה', toggleFavorite($pubId, $noaU), true);
check('isFavorite', isFavorite($pubId, $noaU), true);
check('שמירה שנייה = הסרה', toggleFavorite($pubId, $noaU), false);
toggleFavorite($pubId, $noaU);
$favs = listFavorites($noaU);
check('ברשימה, זמין, עם שם הכותבת', [$favs[0]['status'], $favs[0]['title'], $favs[0]['owner_name']], ['ok', 'עוגה', 'מלי']);
saveRecipe($simple('עוגה', 'private'), $maliU, $pubId);
$favs = listFavorites($noaU);
check('המקור הפך לפרטי — hidden, בלי שם כותבת', [$favs[0]['status'], $favs[0]['owner_name']], ['hidden', null]);
saveRecipe($simple('עוגה', 'public'), $maliU, $pubId);
deleteRecipe($pubId, $maliU);
$favs = listFavorites($noaU);
// הצילום נלקח ברגע השמירה — ואז המתכון כבר נקרא 'עוגה' (סעיף 5).
check('המקור נמחק — gone, השם מהצילום', [$favs[0]['status'], $favs[0]['id'], $favs[0]['title']], ['gone', null, 'עוגה']);
$gone2 = saveRecipe($simple('עוד אחד', 'public'), $maliU);
toggleFavorite($gone2, $noaU); deleteRecipe($gone2, $maliU);
check('שני מועדפים "gone" חיים יחד (UNIQUE עם NULL)', count(listFavorites($noaU)), 2);
removeFavorite($favs[0]['fav_id'], $noaU);
check('הסרה', count(listFavorites($noaU)), 1);
expectError('הסרה של מועדף של אחר', fn() => removeFavorite($GLOBALS['favs'][0]['fav_id'], $GLOBALS['maliU']), 'אינו קיים');
check('הפתקים של המתכון שנמחק נמחקו איתו', (int) db()->query("SELECT COUNT(*) FROM comments WHERE recipe_id = $pubId")->fetchColumn(), 0);

foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
@rmdir($tmp . '/media'); @rmdir($tmp);
echo "\n";
if ($fail) { echo "\u{274C} נכשלו " . count($fail) . ": " . implode(', ', $fail) . "\n"; exit(1); }
echo "\u{2705} כל הבדיקות עברו\n";
