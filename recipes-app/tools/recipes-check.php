<?php
/**
 * בדיקת שכבת המתכונים על מסד זמני.
 * הרצה: php recipes-app/tools/recipes-check.php
 *
 * מוכיחה את ההכרעות שקשה לראות בעין: מתכון פשוט וחלקים מרובים באותו
 * מבנה, שתי שכבות הרכיב, הרשאות של פרטי מול ציבורי, והחלפה מלאה
 * בשמירה שאינה משאירה שאריות.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/recipes-rcheck-' . getmypid();
@mkdir($tmp, 0775, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('MEDIA_DIR', $tmp . '/media');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/recipes.php';

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

$david = createUser('david', 'd@example.com', 'sod12345', 'דוד');
verifyEmail($david['token']);
$mali = createUser('mali', 'm@example.com', 'sod12345', 'מלי');
verifyEmail($mali['token']);
$davidU = ['id' => $david['id'], 'role' => 'admin'];
$maliU  = ['id' => $mali['id'],  'role' => 'user'];

echo "\n1. מתכון מורכב — שני חלקים, שתי שכבות ברכיב\n";
$cakeId = saveRecipe([
    'title' => 'עוגת גבינה', 'visibility' => 'public', 'servings' => 8,
    'difficulty' => 'medium', 'work_minutes' => 20, 'wait_minutes' => 70,
    'tips' => 'לא לפתוח את התנור בחצי הראשונה',
    'sections' => [
        ['name' => 'בצק', 'ingredients' => [
            ['free_text' => '2 כוסות', 'amount_min' => 300, 'unit' => 'gram', 'product' => 'קמח'],
            ['free_text' => '2-3 ביצים', 'amount_min' => 2, 'amount_max' => 3, 'unit' => 'unit', 'product' => 'ביצים'],
            ['free_text' => 'קורט קינמון', 'product' => 'קינמון', 'optional' => true],
        ], 'steps' => [['text' => 'לפורר'], ['text' => 'ללחוץ לתבנית']]],
        ['name' => 'מלית', 'ingredients' => [
            ['free_text' => '750 גרם', 'amount_min' => 750, 'unit' => 'gram', 'product' => 'גבינה 5%'],
        ], 'steps' => [['text' => 'להקציף'], ['text' => 'לאפות 50 דקות']]],
    ],
], $davidU);
$cake = loadRecipe($cakeId, $davidU);
check('שני חלקים', count($cake['sections']), 2);
check('שם החלק הראשון', $cake['sections'][0]['name'], 'בצק');
check('שלושה רכיבים בבצק', count($cake['sections'][0]['ingredients']), 3);
check('הטקסט החופשי נשמר', $cake['sections'][0]['ingredients'][0]['free_text'], '2 כוסות');
check('והערך המחושב לצדו', $cake['sections'][0]['ingredients'][0]['amount_min'], 300.0);
check('טווח נשמר', $cake['sections'][0]['ingredients'][1]['amount_max'], 3.0);
check('רכיב אופציונלי מסומן', $cake['sections'][0]['ingredients'][2]['optional'], true);
check('רכיב בלי יחידה אינו נושא כמות', $cake['sections'][0]['ingredients'][2]['amount_min'], null);
check('שלבים ממוספרים', array_column($cake['sections'][1]['steps'], 'position'), [1, 2]);

echo "\n2. מתכון פשוט — חלק אחד בלי שם, אותו מבנה\n";
$saladId = saveRecipe(['title' => 'סלט ירקות', 'visibility' => 'private', 'sections' => [
    ['ingredients' => [['free_text' => '3 עגבניות', 'product' => 'עגבניות']],
     'steps' => [['text' => 'לחתוך הכול']]],
]], $maliU);
$salad = loadRecipe($saladId, $maliU);
check('חלק אחד', count($salad['sections']), 1);
check('בלי שם', $salad['sections'][0]['name'], null);

echo "\n3. הרשאות\n";
check('דוד רואה את הציבורי של עצמו', loadRecipe($cakeId, $davidU)['title'], 'עוגת גבינה');
check('מלי רואה מתכון ציבורי של דוד', loadRecipe($cakeId, $maliU)['title'], 'עוגת גבינה');
check('אורח רואה ציבורי', loadRecipe($cakeId, null)['title'], 'עוגת גבינה');
check('דוד אינו רואה פרטי של מלי', loadRecipe($saladId, $davidU), null);
check('אורח אינו רואה פרטי', loadRecipe($saladId, null), null);
expectError('דוד אינו יכול לערוך מתכון של מלי',
            fn() => requireOwnRecipe($GLOBALS['saladId'], $GLOBALS['davidU']), 'אינו שלך');

echo "\n4. שמירה מחליפה הכול ואינה משאירה שאריות\n";
saveRecipe(['title' => 'עוגת גבינה קלה', 'visibility' => 'public', 'servings' => 8,
    'sections' => [['name' => 'הכול ביחד', 'ingredients' => [
        ['free_text' => 'חצי קילו גבינה', 'amount_min' => 500, 'unit' => 'gram', 'product' => 'גבינה 5%'],
    ], 'steps' => [['text' => 'לערבב']]]],
], $davidU, $cakeId);
$after = loadRecipe($cakeId, $davidU);
check('השם התעדכן', $after['title'], 'עוגת גבינה קלה');
check('חלק אחד נשאר', count($after['sections']), 1);
check('אין רכיבים יתומים',
      (int) db()->query('SELECT COUNT(*) c FROM ingredients i LEFT JOIN sections s
                          ON s.id = i.section_id WHERE s.id IS NULL')->fetch()['c'], 0);
check('אין שלבים יתומים',
      (int) db()->query('SELECT COUNT(*) c FROM steps st LEFT JOIN sections s
                          ON s.id = st.section_id WHERE s.id IS NULL')->fetch()['c'], 0);
check('updated_at זז', $after['updated_at'] >= $after['created_at'], true);

echo "\n5. קטלוג המוצרים גדל מעצמו ואינו מכפיל\n";
check('נוצרו חמישה מוצרים',
      (int) db()->query('SELECT COUNT(*) c FROM products')->fetch()['c'], 5);
$before = (int) db()->query('SELECT COUNT(*) c FROM products')->fetch()['c'];
productId('קמח');
check('שם קיים אינו נוצר שוב',
      (int) db()->query('SELECT COUNT(*) c FROM products')->fetch()['c'], $before);

echo "\n6. חיפוש\n";
$r = searchRecipes($maliU, 'גבינה');
check('"גבינה" מחזיר את העוגה', count($r), 1);
check('והיא מסומנת כלא־שלי', $r[0]['is_mine'], false);
check('מלי מוצאת את הפרטי שלה', count(searchRecipes($maliU, 'סלט')), 1);
check('דוד אינו מוצא את הפרטי של מלי', count(searchRecipes($davidU, 'סלט')), 0);
check('אורח אינו מוצא פרטי', count(searchRecipes(null, 'סלט')), 0);
check('מלי מוצאת לפי רכיב שלה', count(searchRecipes($maliU, 'עגבני')), 1);
check('דוד אינו מוצא לפי רכיב של פרטי שאינו שלו', count(searchRecipes($davidU, 'עגבני')), 0);
check('התאמה בשם מדורגת לפני התאמה ברכיב',
      searchRecipes($davidU, 'גבינה')[0]['title'], 'עוגת גבינה קלה');

echo "\n6ב. כמות — מספר מנות, תיאור חופשי, או כלום\n";
$cake = loadRecipe($cakeId, $maliU);
check('מספר מנות נשמר, ובלי טקסט', [$cake['servings'], $cake['yield_text']], [8, null]);
$yid = saveRecipe(['title' => 'עוגה שלמה', 'visibility' => 'private', 'yield_text' => '  עוגה אחת בתבנית 26  ',
    'sections' => [['ingredients' => [['free_text' => 'קמח']], 'steps' => [['text' => 'לאפות']]]]], $maliU);
$y = loadRecipe($yid, $maliU);
check('תיאור חופשי נשמר מנוקה, בלי מספר', [$y['servings'], $y['yield_text']], [null, 'עוגה אחת בתבנית 26']);
saveRecipe(['title' => 'עוגה שלמה', 'visibility' => 'private', 'servings' => 12, 'yield_text' => 'עוגה',
    'sections' => [['ingredients' => [['free_text' => 'קמח']], 'steps' => [['text' => 'לאפות']]]]], $maliU, $yid);
$y = loadRecipe($yid, $maliU);
check('נשלחו שניהם — המספר גובר והטקסט נזרק', [$y['servings'], $y['yield_text']], [12, null]);
saveRecipe(['title' => 'עוגה שלמה', 'visibility' => 'private',
    'sections' => [['ingredients' => [['free_text' => 'קמח']], 'steps' => [['text' => 'לאפות']]]]], $maliU, $yid);
$y = loadRecipe($yid, $maliU);
check('בלי שניהם — השדה מוסתר (שני NULL)', [$y['servings'], $y['yield_text']], [null, null]);
check('החיפוש מחזיר yield_text', array_key_exists('yield_text', searchRecipes($maliU, 'שלמה')[0]), true);

echo "\n7. מחיקה\n";
saveRecipe(['title' => 'למחיקה', 'visibility' => 'public', 'sections' => [
    ['ingredients' => [['free_text' => 'משהו']], 'steps' => [['text' => 'משהו']]]]], $maliU);
$doomed = (int) db()->query("SELECT id FROM recipes WHERE title='למחיקה'")->fetch()['id'];
db()->prepare('INSERT INTO comments (recipe_id,user_id,visibility,text,created_at) VALUES (?,?,?,?,?)')
    ->execute([$doomed, $david['id'], 'public', 'נחמד', nowIso()]);
expectError('משתמש רגיל אינו מוחק מתכון של אחר',
            fn() => deleteRecipe($GLOBALS['cakeId'], $GLOBALS['maliU']), 'הרשאה');
check('המנהל מוחק מתכון ציבורי, ומדווח כמה תגובות', deleteRecipe($doomed, $davidU), 1);
check('התגובות נמחקו',
      (int) db()->query('SELECT COUNT(*) c FROM comments')->fetch()['c'], 0);
expectError('מתכון שאינו קיים', fn() => deleteRecipe(9999, $GLOBALS['davidU']), 'אינו קיים');

echo "\n8. ולידציה\n";
expectError('בלי שם', fn() => saveRecipe(['sections' => [[]]], $GLOBALS['maliU']), 'שם');
expectError('בלי חלקים', fn() => saveRecipe(['title' => 'ריק'], $GLOBALS['maliU']), 'חלק אחד');

foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
@rmdir($tmp . '/media'); @rmdir($tmp);

echo "\n";
if ($fail) { echo "\u{274C} נכשלו " . count($fail) . ": " . implode(', ', $fail) . "\n"; exit(1); }
echo "\u{2705} כל הבדיקות עברו\n";
