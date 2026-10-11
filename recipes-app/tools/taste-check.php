<?php
/**
 * בדיקת יומן השימוש והניתוח היומי של הטעם (lib/taste_job.php) — על מסד זמני.
 * הרצה: php recipes-app/tools/taste-check.php
 *
 * מוכיחה:
 *   1. יומן השימוש: חיפוש (ופעם בחצי שעה לאותו חיפוש), חיפוש ברשת, שמירה והסרה מאוסף,
 *      הסרה מהמועדפים, "הסר מהרשימה שלי" — רק כשהתאמה אישית דלוקה.
 *   2. יצוא: רק מי שיש לו מה לנתח, בלי שם ובלי דוא"ל, עם פרטי המתכונים (תגים, זמן, רכיבים),
 *      מה ששמר, ומועמדים; מי שכבר נותח ולא עשה דבר — לא שוב.
 *   3. יבוא: בדיקת כל שדה — תג לא מוכר, משקל מחוץ לטווח, בחירה שאינה זמינה, טקסט ארוך,
 *      לקוח שכיבה התאמה אישית — נדחים; השאר נשמר.
 *   4. בשבילך: בחירה של הצ'אט ראשונה, עם הסיבה שלו; "אוהב בשר" מקדם בשרי; "לא אוהב" מוריד;
 *      "קליל ומהיר" מקדם מהיר; התקציר מוצג; איפוס וכיבוי מוחקים.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/recipes-taste-' . getmypid();
@mkdir($tmp . '/media', 0775, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('MEDIA_DIR', $tmp . '/media');
@ini_set('sendmail_path', '/bin/true');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/social.php';
require_once __DIR__ . '/../lib/network.php';
require_once __DIR__ . '/../lib/collections.php';
require_once __DIR__ . '/../lib/taste_job.php';

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
$chef  = $mk('chef', 'השף');          // הראשון — מנהל; מפרסם את המתכונים
$meat  = $mk('meat', 'אוהב בשר');
$light = $mk('light', 'אוהבת קליל');
$off   = $mk('off', 'בלי התאמה');
setUserPrefs(['personalize' => false], $off);

$T = fn(string $axis, string $name) => catalogTagIds($axis)[$name];
$sec = fn(string ...$ings) => [['ingredients' => array_map(fn($i) => ['free_text' => $i], $ings), 'steps' => [['text' => 'להכין']]]];
$pub = fn(string $title, array $tags, ?int $work = null, ?string $d = null, array $ings = ['מלח']) =>
    saveRecipe(['title' => $title, 'visibility' => 'public', 'tag_ids' => $tags, 'work_minutes' => $work, 'difficulty' => $d, 'sections' => $sec(...$ings)], $GLOBALS['chef']);
$steak  = $pub('סטייק אנטריקוט על הגריל', [$T('kosher', 'בשרי')], 25, 'easy', ['אנטריקוט', 'מלח גס']);
$asado  = $pub('אסאדו בבישול ארוך', [$T('kosher', 'בשרי')], 300, 'hard', ['אסאדו', 'יין אדום']);
$salad  = $pub('סלט ירקות קצוץ', [$T('suitable', 'טבעוני')], 10, 'easy', ['מלפפון', 'עגבנייה']);
$fish   = $pub('דג סלמון בתנור', [], 30, 'easy', ['סלמון']);
$quiche = $pub('קיש פטריות', [$T('kosher', 'חלבי')], 75, 'medium', ['פטריות', 'שמנת']);
$events = fn(array $u, string $kind) => (int) db()->query("SELECT COUNT(*) FROM user_events WHERE user_id = {$u['id']} AND kind = '$kind'")->fetchColumn();

echo "\n1. יומן השימוש\n";
fyTrack($meat, 'search', null, null, ['q' => 'אסאדו', 'n' => 1]);
fyTrack($meat, 'search', null, null, ['q' => 'אסאדו', 'n' => 1]);
fyTrack($meat, 'search', null, null, ['q' => 'צלעות', 'n' => 0]);
check('חיפוש נרשם; אותו חיפוש שוב בחצי שעה — לא כפול', $events($meat, 'search'), 2);
collectionToggle(mySavedCollection($meat), ['recipe_id' => $steak], true, $meat);
collectionToggle(mySavedCollection($meat), ['recipe_id' => $asado], true, $meat);
collectionToggle(mySavedCollection($meat), ['recipe_id' => $asado], false, $meat);
check('שמירה (2) והסרה (1) מאוסף', [$events($meat, 'save'), $events($meat, 'unsave')], [2, 1]);
toggleFavorite($asado, $meat);
$fav = (int) db()->query("SELECT ci.id FROM collection_items ci JOIN collections c ON c.id = ci.collection_id WHERE c.owner_id = {$meat['id']} AND ci.recipe_id = $asado")->fetchColumn();
removeFavorite($fav, $meat);
check('הסרה מהמועדפים — גם נרשמת', $events($meat, 'unsave'), 2);
leaveShare($salad, $meat);
check('"הסר מהרשימה שלי" — נרשם כ-hide', $events($meat, 'hide'), 1);
$before = $events($meat, 'save');   // סטייק, אסאדו, ואסאדו כמועדף
collectionToggle(mySavedCollection($meat), ['recipe_id' => $steak], true, $meat);   // כבר שמור — לא שורה נוספת
check('שמירה של מה שכבר שמור — לא נרשמת שוב', [$before, $events($meat, 'save')], [3, 3]);
fyTrack($off, 'search', null, null, ['q' => 'משהו']);
collectionToggle(mySavedCollection($off), ['recipe_id' => $steak], true, $off);
check('התאמה אישית כבויה — כלום לא נרשם', (int) db()->query("SELECT COUNT(*) FROM user_events WHERE user_id = {$off['id']}")->fetchColumn(), 0);
fyEvent($light, 'open', $salad);
fyTrack($light, 'search', null, null, ['q' => 'סלט מהיר', 'n' => 3]);
collectionToggle(mySavedCollection($light), ['recipe_id' => $salad], true, $light);

echo "\n2. יצוא\n";
$ex = tasteExport();
$ids = array_column($ex['users'], 'user_id');
check('גרסה, אוצר מילים, תכונות', [$ex['version'], isset($ex['vocabulary']['tags']['kosher']), array_keys($ex['vocabulary']['traits'])], [1, true, ['quick', 'special']]);
check('רק מי שיש מה לנתח: בשר וקליל — לא השף (אין פעילות) ולא הכבוי', [in_array($meat['id'], $ids), in_array($light['id'], $ids), in_array($off['id'], $ids)], [true, true, false]);
$mu = array_values(array_filter($ex['users'], fn($u) => $u['user_id'] === $meat['id']))[0];
check('בלי שם ובלי דוא"ל', [str_contains(json_encode($mu, JSON_UNESCAPED_UNICODE), 'אוהב בשר'), str_contains(json_encode($mu), '@x.com')], [false, false]);
$save = array_values(array_filter($mu['events'], fn($e) => $e['kind'] === 'save' && ($e['recipe']['title'] ?? '') === 'סטייק אנטריקוט על הגריל'))[0];
check('שמירה ביומן — עם המתכון: תגים, זמן, קושי, רכיבים', [$save['recipe']['title'], $save['recipe']['tags'], $save['recipe']['min'], $save['recipe']['d'], $save['recipe']['ings'][0], $save['to']],
      ['סטייק אנטריקוט על הגריל', ['מנה עיקרית', 'בשרי'], 25, 'easy', 'אנטריקוט', 'שמורים']);   // הנושא — מהשם
check('חיפוש ביומן — המילים וכמה נמצא', array_values(array_map(fn($e) => [$e['q'], $e['n']], array_filter($mu['events'], fn($e) => $e['kind'] === 'search'))), [['צלעות', 0], ['אסאדו', 1]]);
check('מה ששמור עכשיו', array_column($mu['saved'], 'title'), ['סטייק אנטריקוט על הגריל']);
check('מועמדים — מפתחות r:/w:', count($mu['candidates']) > 0 && (bool) preg_match('/^[rw]:\d+$/', $mu['candidates'][0]['key']), true);

echo "\n3. יבוא\n";
expectError('גרסה לא מוכרת', fn() => tasteImport(['version' => 9, 'users' => []]), 'גרסה');
$res = tasteImport(['version' => 1, 'users' => [
    ['user_id' => $meat['id'], 'summary' => '<b>אוהב בשר</b> על האש ובישולים ארוכים', 'profile' => [
        'tags' => ['בשרי' => 9, 'טבעוני' => -2, 'מילה שאינה תג' => 1], 'special' => 0.6, 'quick' => -3,
        'keywords' => ['אסאדו', 'צלעות', 'x'], 'avoid' => ['דג']],
     'picks' => [['key' => "r:$asado", 'reason' => 'כי שמרת סטייק וחיפשת אסאדו'], ['key' => 'r:999999', 'reason' => 'לא קיים'], ['key' => 'בלגן']]],
    ['user_id' => $light['id'], 'summary' => 'אוהבת מתכונים קלילים ומהירים', 'profile' => ['quick' => 1, 'tags' => ['טבעוני' => 2]], 'picks' => []],
    ['user_id' => $off['id'], 'summary' => 'לא אמור להישמר', 'profile' => [], 'picks' => []],
    ['user_id' => 424242, 'summary' => 'אין כזה'],
]]);
check('עודכנו 2; נדחו הכבוי ומי שאינו קיים', [$res['updated'], array_column($res['skipped'], 'user_id')], [2, [$off['id'], 424242]]);
check('אזהרות: תג לא מוכר, בחירה לא זמינה, מפתח לא תקין', count($res['warnings']), 3);
$a = aiTaste($meat['id']);
check('נשמר נקי: תקציר בלי תגיות, משקל עד 3, תכונה עד 1-, מילה קצרה נזרקה',
      [$a['summary'], $a['profile']['tags'], $a['profile']['quick'], $a['profile']['keywords'], count($a['picks'])],
      ['אוהב בשר על האש ובישולים ארוכים', ['בשרי' => 3, 'טבעוני' => -2], -1, ['אסאדו', 'צלעות'], 1]);   // JSON: 3.0 חוזר כ-3
check('הכבוי — לא נשמר', aiTaste($off['id']), null);

echo "\n4. בשבילך\n";
$fy = forYou($meat, 10);
$first = $fy['items'][0];
check('הבחירה של הצ\'אט ראשונה, עם הסיבה שלו', [$first['id'], $first['reason']], [$asado, 'כי שמרת סטייק וחיפשת אסאדו']);
check('התקציר מגיע ל"בשבילך"', $fy['ai']['summary'], 'אוהב בשר על האש ובישולים ארוכים');
$pos = fn(array $items, int $id) => array_search($id, array_column($items, 'id'), true);
check('"לא אוהב דג" — הסלמון לא לפני הקיש', ($pos($fy['items'], $fish) === false) || ($pos($fy['items'], $quiche) !== false && $pos($fy['items'], $fish) > $pos($fy['items'], $quiche)), true);
$fl = forYou($light, 10);
$sc = fn(array $items, int $id) => array_values(array_filter($items, fn($i) => $i['id'] === $id))[0]['score'] ?? null;
check('אוהבת קליל: דג בתנור (30 דק\', קל) מעל קיש (75 דק\')', $sc($fl['items'], $fish) > $sc($fl['items'], $quiche), true);
check('והסיבה — "קליל ומהיר"', array_values(array_filter($fl['items'], fn($i) => $i['id'] === $fish))[0]['reason'], 'קליל ומהיר — כמו שאתה אוהב');
$ex2 = tasteExport();
check('מי שנותח ולא עשה דבר מאז — לא ביצוא הבא', in_array($meat['id'], array_column($ex2['users'], 'user_id')), false);
aiTasteReset($light['id']);
check('"זה לא אני — אפס" — נמחק, ובשבילך בלי תקציר', [aiTaste($light['id']), forYou($light, 5)['ai']], [null, null]);
setUserPrefs(['personalize' => false], $meat);
check('כיבוי התאמה אישית — מוחק יומן וטעם', [aiTaste($meat['id']), $events($meat, 'search') + $events($meat, 'save')], [null, 0]);

echo "\n";
@array_map('unlink', glob("$tmp/*") ?: []);
if ($fail) { echo "\u{274C} נכשלו " . count($fail) . ': ' . implode(' | ', $fail) . "\n"; exit(1); }
echo "\u{2705} כל הבדיקות עברו\n";
