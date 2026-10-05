<?php
/**
 * בדיקת pantry.php — "מה יש לי בבית" — בלי רשת.
 * הרצה: php recipes-app/tools/pantry-check.php
 *
 * מוכיחה: גזעים עבריים (ריבוי/נקבה/תחיליות), כיסוי רכיב במוצר ("שמן" מכסה
 * "שמן קנולה", "שמן זית" לא), המזווה במסד (כפילות, מכסה, מוצרי יסוד),
 * והדירוג: כיסוי, חסרים, רשות לא נספר. הזיהוי בתמונה — מול מדמה ב-api-check.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/recipes-pantry-' . getmypid();
@mkdir($tmp, 0775, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('MEDIA_DIR', $tmp . '/media');
define('SECRETS_FILE', $tmp . '/secrets.json');
@ini_set('sendmail_path', '/bin/true');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/recipes.php';
require_once __DIR__ . '/../lib/pantry.php';

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

echo "\n1. גזעים\n";
check('עגבניות ↔ עגבנייה', pantryStem('עגבניות') === pantryStem('עגבנייה'), true);
check('ביצים ↔ ביצה', pantryStem('ביצים') === pantryStem('ביצה'), true);
check('תחילית "ה" מוסרת: הקמח → קמח', pantryStem('הקמח'), pantryStem('קמח'));
check('מילה קצרה לא נשחקת: מים', pantryStem('מים'), 'מים');
check('ביטוי: מילות כמות ויחידה נזרקות', pantryStems('2 כוסות קמח לבן'), [pantryStem('קמח'), pantryStem('לבן')]);
check('אחוזים ומספרים נזרקים', pantryStems('גבינה לבנה 5%'), [pantryStem('גבינה'), pantryStem('לבנה')]);

echo "\n2. כיסוי\n";
$ing = fn(string $s) => pantryStems($s);
check('"קמח" מכסה "2 כוסות קמח"', pantryCovers(pantryStems('קמח'), $ing('2 כוסות קמח')), true);
check('"שמן" מכסה "שמן קנולה"', pantryCovers(pantryStems('שמן'), $ing('1 כוס שמן קנולה')), true);
check('"שמן זית" אינו מכסה "שמן קנולה"', pantryCovers(pantryStems('שמן זית'), $ing('שמן קנולה')), false);
check('"ביצה" מכסה "4 ביצים L"', pantryCovers(pantryStems('ביצה'), $ing('4 ביצים L')), true);
check('"גבינה צהובה" מכסה "100 גרם גבינה צהובה מגוררת"', pantryCovers(pantryStems('גבינה צהובה'), $ing('100 גרם גבינה צהובה מגוררת')), true);
check('"גבינה" לא מכסה "חמאה"', pantryCovers(pantryStems('גבינה'), $ing('חמאה')), false);
check('ריק — לא', pantryCovers([], $ing('קמח')), false);

echo "\n3. המזווה\n";
$p = pantryAdd(['עגבניות', ' ביצים ', 'עגבניות', 'x'], 'text', $maliU);
check('שניים נוספו — כפילות ושם קצר נזרקו', [$p['added'], count($p['items'])], [2, 2]);
check('מוצרי יסוד דולקים כברירת מחדל', count(array_filter($p['staples'], fn($s) => $s['on'])), count(PANTRY_STAPLES));
$p = pantryStapleToggle('מלח', false, $maliU);
check('כיבוי מוצר יסוד', array_values(array_filter($p['staples'], fn($s) => $s['name'] === 'מלח'))[0]['on'], false);
expectError('מוצר יסוד לא מוכר', fn() => pantryStapleToggle('קוויאר', false, $GLOBALS['maliU']), 'לא מוכר');
check('המזווה של נועה נפרד', count(pantryList($noaU)['items']), 0);
$id = $p['items'][0]['id'];
check('הסרה', count(pantryRemove($id, $maliU)['items']), 1);
check('הסרה של פריט של אחר — לא משפיעה', count(pantryRemove($p['items'][1]['id'], $noaU)['items']), 0);
check('ניקוי', count(pantryClear($maliU)['items']), 0);

echo "\n4. מה אפשר להכין\n";
$mk = fn(string $title, array $ings, string $vis = 'public', array $u = null) => saveRecipe([
    'title' => $title, 'visibility' => $vis,
    'sections' => [['ingredients' => array_map(fn($i) => is_array($i) ? $i : ['free_text' => $i], $ings), 'steps' => [['text' => 'מכינים']]]],
], $u ?? $maliU);
$omelet = $mk('חביתה', ['2 ביצים', 'מלח', 'כף שמן']);
$salad  = $mk('סלט עגבניות', ['3 עגבניות', 'בצל', 'שמן זית', ['free_text' => 'פטרוזיליה', 'optional' => true]]);
$cake   = $mk('עוגה', ['2 כוסות קמח', '3 ביצים', '1 כוס סוכר', '100 גרם חמאה', 'שוקולד']);
$secret = $mk('סודי של נועה', ['ביצים'], 'private', $noaU);
pantryStapleToggle('מלח', true, $maliU);   // הודלק חזרה אחרי סעיף 3
check('בלי מזווה — כלום', pantryMatch($maliU)['recipes'], []);
pantryAdd(['ביצים', 'עגבנייה'], 'text', $maliU);
$m = pantryMatch($maliU, 0);
check('0 חסרים: חביתה (מלח ושמן — יסוד) וסלט (בצל ושמן זית — יסוד)', array_column($m['recipes'], 'title'), ['חביתה', 'סלט עגבניות']);
$m = pantryMatch($maliU, 2);
$byTitle = array_column($m['recipes'], null, 'title');
check('סלט: בצל ושמן זית — יסוד; פטרוזיליה רשות לא נספרת → 0 חסרים', [$byTitle['סלט עגבניות']['missing'], $byTitle['סלט עגבניות']['need']], [[], 3]);
check('עוגה: קמח וסוכר יסוד, חסרים חמאה ושוקולד (בטקסט המקורי)', $byTitle['עוגה']['missing'], ['100 גרם חמאה', 'שוקולד']);
check('הסדר: פחות חסרים קודם', array_column($m['recipes'], 'title')[2], 'עוגה');
check('פרטי של אחר לא מופיע', isset($byTitle['סודי של נועה']), false);
check('כיסוי מספרי', $byTitle['עוגה']['coverage'], 0.6);
pantryStapleToggle('שמן', false, $maliU);
check('כיבוי "שמן" — החביתה חסרה שמן', array_column(pantryMatch($maliU, 1)['recipes'], 'missing', 'title')['חביתה'] ?? null, ['כף שמן']);
check('מזווה של נועה: הסודי שלה מופיע', in_array('סודי של נועה', array_column((pantryAdd(['ביצים'], 'text', $noaU) ? pantryMatch($noaU, 0) : [])['recipes'], 'title'), true), true);
expectError('זיהוי בתמונה בלי מפתח — הודעה ברורה', fn() => pantryRecognize('x', 'image/jpeg', $GLOBALS['maliU']), 'מפתח');

foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
@rmdir($tmp . '/media'); @rmdir($tmp);
echo "\n";
if ($fail) { echo "\u{274C} נכשלו " . count($fail) . ": " . implode(', ', $fail) . "\n"; exit(1); }
echo "\u{2705} כל הבדיקות עברו\n";
