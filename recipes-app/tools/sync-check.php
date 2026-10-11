<?php
/**
 * בדיקת הסנכרון — מה שהמשתמש עשה מופיע (ונעלם) בכל מקום — על מסד זמני.
 * הרצה: php recipes-app/tools/sync-check.php
 *
 * מוכיחה:
 *   1. להישאר מחובר: הסשן נמחק ← העוגייה הארוכה מחזירה אותו; יציאה, איפוס סיסמה,
 *      חסימה, אסימון מזויף ואסימון שפג — לא מחזירים.
 *   2. "הסר מהרשימה שלי" גם במתכון ציבורי: לא בבית, לא בקטגוריות, לא בבשבילך; כן
 *      בדף של המפרסם; "החזר לרשימה שלי" מחזיר. במתכון שלי — שגיאה.
 *   3. מתכון חדש בלי נושא — מקבל נושא מהשם, ומופיע בקטגוריה; נושא שנבחר — לא נוגעים.
 *   4. מחיקת מתכון — יוצא מהאוספים של הבעלים; אצל אחר נשאר "הוסר".
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/recipes-sync-' . getmypid();
@mkdir($tmp, 0775, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('MEDIA_DIR', $tmp . '/media');
@ini_set('sendmail_path', '/bin/true');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/network.php';
require_once __DIR__ . '/../lib/catalog.php';
require_once __DIR__ . '/../lib/collections.php';
require_once __DIR__ . '/../lib/foryou.php';
require_once __DIR__ . '/../lib/settings.php';

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
    return ['id' => $r['id'], 'role' => 'user', 'username' => $u, 'display_name' => $name];
};
$dana = $mk('dana', 'דנה');      // הראשונה — מנהלת
$noa  = $mk('noa', 'נועה');
$ids = fn(array $list) => array_column($list, 'id');
$sec = [['ingredients' => [['free_text' => 'קמח']], 'steps' => [['text' => 'לאפות']]]];
$topic = catalogTagIds('topic');

echo "\n1. להישאר מחובר\n";
$tokens = fn() => (int) db()->query('SELECT COUNT(*) FROM login_tokens')->fetchColumn();
login('noa', 'sod12345');
check('כניסה — אסימון אחד, ועוגייה', [$tokens(), (bool) preg_match('/^[0-9a-f]{18}:[0-9a-f]{64}$/', $_COOKIE[KEEP_COOKIE] ?? '')], [1, true]);
$cookie = $_COOKIE[KEEP_COOKIE];
check('במסד רק hash — לא הטקסט שבעוגייה', (int) db()->query("SELECT COUNT(*) FROM login_tokens WHERE token_hash = '" . explode(':', $cookie)[1] . "'")->fetchColumn(), 0);
$_SESSION = [];   // השרת מחק את הסשן (24 דקות בלי פעילות / דפדפן שנסגר)
check('הסשן נמחק — העוגייה מחזירה את נועה', currentUser()['username'] ?? null, 'noa');
$_SESSION = [];
$_COOKIE[KEEP_COOKIE] = explode(':', $cookie)[0] . ':' . str_repeat('0', 64);
check('validator מזויף — לא', currentUser(), null);
$_SESSION = [];
$_COOKIE[KEEP_COOKIE] = $cookie;
db()->exec("UPDATE login_tokens SET expires_at = '2000-01-01T00:00:00Z'");
check('אסימון שפג — לא, ונמחק', [currentUser(), $tokens()], [null, 0]);
login('noa', 'sod12345');
$cookie = $_COOKIE[KEEP_COOKIE];
logout();
$_COOKIE[KEEP_COOKIE] = $cookie;   // גם אם הדפדפן שמר אותה — אחרי יציאה היא כבר לא תקפה
check('יציאה — האסימון נמחק', [$tokens(), currentUser()], [0, null]);
login('noa', 'sod12345');
$cookie = $_COOKIE[KEEP_COOKIE];
$_SESSION = [];
keepForgetAll($noa['id']);   // מה שאיפוס סיסמה עושה
check('איפוס סיסמה — כל המכשירים מנותקים', currentUser(), null);
$_COOKIE[KEEP_COOKIE] = $cookie;
login('noa', 'sod12345');
$_SESSION = [];
setUserBlocked($noa['id'], true, ['id' => $dana['id'], 'role' => 'admin', 'username' => 'dana']);
check('חסימה — לא חוזר', [currentUser(), $tokens()], [null, 0]);
setUserBlocked($noa['id'], false, ['id' => $dana['id'], 'role' => 'admin', 'username' => 'dana']);
for ($i = 0; $i < KEEP_MAX + 3; $i++) login('noa', 'sod12345');
check('תקרה: ' . KEEP_MAX . ' מכשירים לחשבון', $tokens(), KEEP_MAX);
logout();
unset($_COOKIE[KEEP_COOKIE]);

echo "\n2. הסר מהרשימה שלי — גם ציבורי\n";
$pub = saveRecipe(['title' => 'עוגת גבינה', 'visibility' => 'public', 'sections' => $sec], $dana);
$cakes = $topic['עוגות'];
check('לפני — אצל נועה בבית ובקטגוריה', [in_array($pub, $ids(searchRecipes($noa))), catalogCounts($noa)[$cakes] ?? 0], [true, 1]);
leaveShare($pub, $noa);
check('אחרי — לא בבית, לא בקטגוריה, לא בסינון', [in_array($pub, $ids(searchRecipes($noa))), catalogCounts($noa)[$cakes] ?? 0, in_array($pub, $ids(searchRecipes($noa, '', ['tag_ids' => [$cakes]])))],
      [false, 0, false]);
check('לא ב"בשבילך"', in_array($pub, array_column(array_filter(forYou($noa, 50)['items'], fn($i) => $i['kind'] === 'recipe'), 'id')), false);
check('כן בדף של דנה, והדף אומר שהוסר', [in_array($pub, $ids(searchRecipes($noa, '', ['owner_id' => $dana['id']]))), recipeHiddenFor($pub, $noa['id'])], [true, true]);
check('אצל דנה עצמה — לא השתנה כלום', in_array($pub, $ids(searchRecipes($dana))), true);
unhideRecipe($pub, $noa);
check('החזר לרשימה שלי — חוזר', [in_array($pub, $ids(searchRecipes($noa))), recipeHiddenFor($pub, $noa['id'])], [true, false]);
expectError('מתכון שלי — לא מסירים, מוחקים', fn() => leaveShare($GLOBALS['pub'], $GLOBALS['dana']), 'מתכון שלך');

echo "\n3. נושא מהשם\n";
$tagsOf = fn(int $rid) => array_map('intval', db()->query("SELECT tag_id FROM recipe_tags WHERE recipe_id = $rid ORDER BY tag_id")->fetchAll(PDO::FETCH_COLUMN));
$new = saveRecipe(['title' => 'מרק עדשים כתומות', 'sections' => $sec], $noa);
check('מתכון חדש בלי נושא — "מרקים"', in_array($topic['מרקים'], $tagsOf($new)), true);
check('ומופיע בקטגוריה', in_array($new, $ids(searchRecipes($noa, '', ['tag_ids' => [$topic['מרקים']]]))), true);
$chosen = saveRecipe(['title' => 'מרק לשבת', 'sections' => $sec, 'tag_ids' => [$topic['לשבת'] ?? $topic['עוגות']]], $noa);
check('נבחר נושא — לא מוסיפים', in_array($topic['מרקים'], $tagsOf($chosen)), false);
$kosher = catalogTagIds('kosher')['פרווה'];
$k = saveRecipe(['title' => 'עוגת שוקולד', 'sections' => $sec, 'tag_ids' => [$kosher]], $noa);
check('נבחרה רק כשרות — נושא עדיין מנוחש, והכשרות נשארת', [in_array($cakes, $tagsOf($k)), in_array($kosher, $tagsOf($k))], [true, true]);
$plain = saveRecipe(['title' => 'משהו של סבתא', 'sections' => $sec], $noa);
check('שם בלי מילה מוכרת — בלי נושא, בלי שגיאה', $tagsOf($plain), []);

echo "\n4. מחיקה — יוצא מהאוספים של הבעלים\n";
$mine = saveRecipe(['title' => 'פשטידת ירקות', 'visibility' => 'public', 'sections' => $sec], $dana);
$danaSaved = mySavedCollection($dana);
$noaSaved = mySavedCollection($noa);
collectionToggle($danaSaved, ['recipe_id' => $mine], true, $dana);
collectionToggle($noaSaved, ['recipe_id' => $mine], true, $noa);
deleteRecipe($mine, $dana);
$items = fn(int $cid) => (int) db()->query("SELECT COUNT(*) FROM collection_items WHERE collection_id = $cid")->fetchColumn();
check('אצל דנה (שמחקה) — נעלם מ"שמורים"', $items($danaSaved), 0);
check('אצל נועה — נשאר "הוסר", כדי שתדע', $items($noaSaved), 1);

echo "\n";
@array_map('unlink', glob("$tmp/*") ?: []);
if ($fail) { echo "\u{274C} נכשלו " . count($fail) . ': ' . implode(' | ', $fail) . "\n"; exit(1); }
echo "\u{2705} כל הבדיקות עברו\n";
