<?php
/**
 * בדיקת shopping.php — רשימת קניות ומצב בישול — על מסד זמני.
 * הרצה: php recipes-app/tools/shopping-check.php
 *
 * מוכיחה את סעיף 8: איחוד על השכבה המחושבת (אותה יחידה מתחברת, יחידות
 * שונות נשארות שורות נפרדות, בלי שכבה מחושבת → טקסט חופשי), המרת מנות
 * לכל מתכון, רשות, "מ-2 מתכונים", שורות ידניות, סימון שנשמר בבנייה מחדש,
 * הרשאות, ומצב בישול: בוצע/איפוס לכל משתמש.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/recipes-shop-' . getmypid();
@mkdir($tmp, 0775, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('MEDIA_DIR', $tmp . '/media');
@ini_set('sendmail_path', '/bin/true');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/recipes.php';
require_once __DIR__ . '/../lib/shopping.php';

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

$mali = createUser('mali', 'm@x.com', 'sod12345', 'מלי');
$maliU = ['id' => $mali['id'], 'role' => 'admin'];
$noa = createUser('noa', 'n@x.com', 'sod12345', 'נועה'); verifyEmail($noa['token']);
$noaU = ['id' => $noa['id'], 'role' => 'user'];

echo "\n1. האיחוד — על השכבה המחושבת\n";
$ing = fn(string $free, ?float $min, ?string $unit, string $product = '', int $rid = 1, float $f = 1, bool $opt = false, ?float $max = null) =>
    ['free_text' => $free, 'amount_min' => $min, 'amount_max' => $max, 'unit' => $unit, 'product' => $product ?: null,
     'product_id' => $product ? crc32($product) : null, 'optional' => $opt, 'recipe_id' => $rid, 'factor' => $f];
$rows = shoppingMerge([
    $ing('2 כוסות קמח', 300, 'gram', 'קמח', 1),
    $ing('200 גרם קמח', 200, 'gram', 'קמח', 2),
    $ing('2 כפיות קינמון', 2, 'tsp', 'קינמון', 1),
    $ing('קורט קינמון', null, null, 'קינמון', 2),
    $ing('מלח לפי הטעם', null, null, '', 1),
    $ing('100 גרם שוקולד', 100, 'gram', 'שוקולד', 1, 1, true),
]);
$by = [];
foreach ($rows as $r) $by[$r['label'] . '|' . ($r['unit'] ?? 'free')] = $r;
check('קמח: 300 + 200 = 500 גרם, מ-2 מתכונים', [$by['קמח|gram']['amount'], $by['קמח|gram']['recipe_count']], [500.0, 2]);
check('קינמון: כפיות וקורט — שתי שורות, לא אוחד', [$by['קינמון|tsp']['amount'], $by['קינמון|free']['free_text']], [2.0, 'קורט קינמון']);
check('בלי שכבה מחושבת ובלי מוצר → שורה מהטקסט', $by['מלח לפי הטעם|free']['free_text'], 'מלח לפי הטעם');
check('רשות מסומן ובסוף', [$by['שוקולד|gram']['optional'], end($rows)['label']], [true, 'שוקולד']);
check('חובה אינו רשות', $by['קמח|gram']['optional'], false);
$rows = shoppingMerge([$ing('300 גרם קמח', 300, 'gram', 'קמח', 1, 2.0), $ing('2 כוסות קמח', 300, 'gram', 'קמח', 2, 0.5)]);
check('המרת מנות: ×2 ו-×0.5 → 600 + 150 = 750', $rows[0]['amount'], 750.0);
$rows = shoppingMerge([$ing('300–400 גרם', 300, 'gram', 'גבינה', 1, 1, false, 400), $ing('100 גרם', 100, 'gram', 'גבינה', 2)]);
check('טווח: 400–500', [$rows[0]['amount'], $rows[0]['amount_max']], [400.0, 500.0]);
$rows = shoppingMerge([$ing('כוס שמן', null, null, '', 1, 2.0)]);
check('טקסט חופשי עם factor מציין ×2', $rows[0]['free_text'], 'כוס שמן ×2');

echo "\n2. רשימה ממתכונים\n";
$mk = fn(string $t, array $ings, string $vis, array $u) => saveRecipe(['title' => $t, 'visibility' => $vis, 'servings' => 4,
    'sections' => [['ingredients' => $ings, 'steps' => [['text' => 'מערבבים 10 דקות'], ['text' => 'אופים שעה']]]]], $u);
$cake = $mk('עוגה', [['free_text' => '2 כוסות קמח', 'amount_min' => 300, 'unit' => 'gram', 'product' => 'קמח'],
                     ['free_text' => '3 ביצים', 'amount_min' => 3, 'unit' => 'unit', 'product' => 'ביצים']], 'public', $maliU);
$bread = $mk('לחם', [['free_text' => '500 גרם קמח', 'amount_min' => 500, 'unit' => 'gram', 'product' => 'קמח'],
                     ['free_text' => 'קורט מלח']], 'public', $noaU);
$secret = $mk('סודי', [['free_text' => 'קמח', 'amount_min' => 100, 'unit' => 'gram', 'product' => 'קמח']], 'private', $noaU);
$l = shoppingCreate([$cake => 1, $bread => 1], 'שבת', $maliU);
$by = []; foreach ($l['items'] as $i) $by[$i['label']] = $i;
check('שם, שני מתכונים', [$l['name'], count($l['recipes'])], ['שבת', 2]);
check('קמח 800 גרם מ-2 מתכונים', [$by['קמח']['amount'], $by['קמח']['unit'], $by['קמח']['recipe_count']], [800.0, 'gram', 2]);
check('ביצים 3 יח׳', [$by['ביצים']['amount'], $by['ביצים']['unit']], [3.0, 'unit']);
check('מלח — טקסט חופשי', $by['קורט מלח']['free_text'], 'קורט מלח');
expectError('מתכון פרטי של אחר לא נכנס', fn() => shoppingAddRecipes($GLOBALS['l']['id'], [$GLOBALS['secret'] => 1], $GLOBALS['maliU']), 'אינו קיים');
expectError('רשימה של אחר', fn() => shoppingGet($GLOBALS['l']['id'], $GLOBALS['noaU']), 'אינה קיימת');
$l2 = shoppingCreate([$cake => 2], null, $maliU);
check('factor 2 → 600 גרם קמח, שם ריק', [array_values(array_filter($l2['items'], fn($i) => $i['label'] === 'קמח'))[0]['amount'], $l2['name']], [600.0, null]);
check('שתי רשימות', count(shoppingLists($maliU)), 2);

echo "\n3. סימון, ידני, בנייה מחדש\n";
shoppingCheck($by['קמח']['id'], true, $maliU);
$l = shoppingAddManual($l['id'], 'שקיות אשפה', $maliU);
check('שורה ידנית נוספה', count(array_filter($l['items'], fn($i) => $i['manual'] && $i['label'] === 'שקיות אשפה')), 1);
$l = shoppingAddRecipes($l['id'], [$bread => 2], $maliU);   // לחם ×2 — בנייה מחדש
$by = []; foreach ($l['items'] as $i) $by[$i['label']] = $i;
check('אחרי בנייה מחדש: קמח 300 + 1000 = 1300', $by['קמח']['amount'], 1300.0);
check('הסימון של קמח נשמר', $by['קמח']['checked'], true);
check('הידני שרד', isset($by['שקיות אשפה']), true);
expectError('סימון שורה של אחר', fn() => shoppingCheck($GLOBALS['by']['קמח']['id'], true, $GLOBALS['noaU']), 'אינה קיימת');
$l = shoppingRemoveRecipe($l['id'], $bread, $maliU);
$by = []; foreach ($l['items'] as $i) $by[$i['label']] = $i;
check('הסרת מתכון: קמח חזר ל-300, מלח נעלם', [$by['קמח']['amount'], isset($by['קורט מלח'])], [300.0, false]);
shoppingCheck($by['שקיות אשפה']['id'], true, $maliU);
$l = shoppingClearChecked($l['id'], $maliU);
$by = []; foreach ($l['items'] as $i) $by[$i['label']] = $i;
check('נקה מסומנים: ידני מסומן נמחק, מחושב רק בוטל סימון', [isset($by['שקיות אשפה']), $by['קמח']['checked']], [false, false]);
$txt = shoppingAsText($l['id'], $maliU);
check('טקסט לשיתוף', str_contains($txt, '🛒 שבת') && str_contains($txt, 'קמח — 300 גרם'), true);
$l = shoppingRename($l['id'], 'שבת גדולה', $maliU);
check('שינוי שם', $l['name'], 'שבת גדולה');
shoppingDelete($l['id'], $maliU);
check('מחיקה', count(shoppingLists($maliU)), 1);
expectError('מחיקה חוזרת', fn() => shoppingDelete($GLOBALS['l']['id'], $GLOBALS['maliU']), 'אינה קיימת');

echo "\n4. מצב בישול\n";
$r = loadRecipe($cake, $maliU);
$s1 = $r['sections'][0]['steps'][0]['id']; $s2 = $r['sections'][0]['steps'][1]['id'];
check('התחלה: כלום', cookingProgress($cake, $maliU), []);
cookingSetDone($s1, true, $maliU);
check('שלב בוצע', cookingProgress($cake, $maliU), [$s1]);
check('של משתמש אחר — נפרד', cookingProgress($cake, $noaU), []);
cookingSetDone($s1, true, $maliU);
check('בוצע פעמיים — לא מכפיל', count(cookingProgress($cake, $maliU)), 1);
cookingSetDone($s1, false, $maliU);
check('ביטול', cookingProgress($cake, $maliU), []);
$rs = loadRecipe($secret, $noaU);
expectError('שלב של מתכון פרטי של אחר', fn() => cookingSetDone($GLOBALS['rs']['sections'][0]['steps'][0]['id'], true, $GLOBALS['maliU']), 'אינו קיים');
cookingSetDone($s1, true, $maliU); cookingSetDone($s2, true, $maliU);
cookingReset($cake, $maliU);
check('איפוס', cookingProgress($cake, $maliU), []);

foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
@rmdir($tmp . '/media'); @rmdir($tmp);
echo "\n";
if ($fail) { echo "\u{274C} נכשלו " . count($fail) . ": " . implode(', ', $fail) . "\n"; exit(1); }
echo "\u{2705} כל הבדיקות עברו\n";
