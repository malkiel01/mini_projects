<?php
/**
 * בדיקת "מה לבשל הערב?" (lib/tonight.php) — על מסד זמני.
 * הרצה: php recipes-app/tools/tonight-check.php
 *
 * מוכיחה: ארוחה ולא קינוח; זמן (וזמן לא ידוע — לא כשאין זמן); כשרות; תזונה מוצהרת;
 * "מה יש בבית" — מה שיש הכול ראשון, ומה חסר; בישלתי השבוע — לא ראשון; "הסר מהרשימה שלי"
 * ו"לא מעניין" — לא; מגוון נושאים; "עוד רעיונות" — לא חוזר על מה שהוצג; בלי שלבים — לא.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/recipes-tonight-' . getmypid();
@mkdir($tmp . '/media', 0775, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('MEDIA_DIR', $tmp . '/media');
@ini_set('sendmail_path', '/bin/true');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/network.php';
require_once __DIR__ . '/../lib/tonight.php';

$fail = [];
function check(string $label, $got, $want): void {
    global $fail;
    $ok = $got === $want;
    echo ($ok ? "  \u{2705} " : "  \u{274C} ") . $label . ': ' . var_export($got, true) .
         ($ok ? "\n" : '  (צפוי ' . var_export($want, true) . ")\n");
    if (!$ok) $fail[] = $label;
}
$mk = function (string $u, string $name) {
    $r = createUser($u, "$u@x.com", 'sod12345', $name);
    if (!empty($r['token'])) verifyEmail($r['token']);
    return ['id' => $r['id'], 'role' => 'user', 'username' => $u, 'display_name' => $name];
};
$chef = $mk('chef', 'השף');
$me = $mk('mymy', 'אני');
$T = fn(string $axis, string $name) => catalogTagIds($axis)[$name];
$add = function (string $title, array $tags, ?int $min, array $ings, bool $steps = true) use ($chef) {
    return saveRecipe(['title' => $title, 'visibility' => 'public', 'tag_ids' => $tags, 'work_minutes' => $min,
                       'sections' => [['ingredients' => array_map(fn($i) => ['free_text' => $i], $ings), 'steps' => $steps ? [['text' => 'להכין']] : []]]], $chef);
};
$schnitzel = $add('שניצל עוף', [$T('topic', 'מנה עיקרית'), $T('kosher', 'בשרי')], 30, ['חזה עוף', 'פירורי לחם', 'ביצים']);
$pasta     = $add('פסטה ברוטב עגבניות', [$T('topic', 'מנה עיקרית'), $T('kosher', 'חלבי')], 20, ['פסטה', 'רסק עגבניות', 'גבינה צהובה']);
$soup      = $add('מרק עדשים', [$T('topic', 'מרקים'), $T('kosher', 'פרווה'), $T('suitable', 'טבעוני')], 50, ['עדשים כתומות', 'גזר', 'בצל']);
$salad     = $add('סלט ירקות', [$T('topic', 'סלטים'), $T('kosher', 'פרווה'), $T('suitable', 'טבעוני')], 10, ['מלפפון', 'עגבנייה']);
$cake      = $add('עוגת שוקולד', [$T('topic', 'עוגות')], 40, ['קמח', 'קקאו']);
$stew      = $add('חמין לשבת', [$T('topic', 'מנה עיקרית'), $T('kosher', 'בשרי')], 720, ['בשר', 'שעועית']);
$untimed   = $add('קציצות של סבתא', [$T('topic', 'מנה עיקרית')], null, ['בשר טחון', 'בצל']);
$nosteps   = $add('רשימת רכיבים בלבד', [$T('topic', 'מנה עיקרית')], 10, ['משהו'], false);
$ids = fn(array $r) => array_column($r['items'], 'id');
$all = fn(array $o = []) => (function () use ($o) {   // כל ההצעות — עוברים על "עוד רעיונות" עד הסוף
    global $me; $seen = []; $o['exclude'] = [];
    for ($i = 0; $i < 10; $i++) { $r = tonight($me, $o); if (!$r['items']) break; foreach ($r['items'] as $it) { $seen[] = $it['id']; $o['exclude'][] = $it['id']; } $o['seed'] = $i + 1; }
    return $seen;
})();

echo "\n1. מה נכנס\n";
$every = $all();
check('שלוש הצעות', count(tonight($me)['items']), 3);
check('עוגה — לא ארוחה', in_array($cake, $every), false);
check('בלי שלבים — לא', in_array($nosteps, $every), false);
check('עד 20 דקות: פסטה וסלט; בלי שניצל (30), בלי זמן לא ידוע', [in_array($pasta, $all(['minutes' => 20])), in_array($salad, $all(['minutes' => 20])), in_array($schnitzel, $all(['minutes' => 20])), in_array($untimed, $all(['minutes' => 20]))], [true, true, false, false]);
check('עד שעה: זמן לא ידוע — כן; חמין (12 שעות) — לא', [in_array($untimed, $all(['minutes' => 60])), in_array($stew, $all(['minutes' => 60]))], [true, false]);
check('חלבי — רק פסטה', $all(['kosher' => 'חלבי']), [$pasta]);
fySetTaste(['topics' => [], 'diet' => [$T('suitable', 'טבעוני')]], $me);
check('מוצהר טבעוני — רק טבעוני', (function () use ($all, $soup, $salad) { $a = $all(); sort($a); $w = [$soup, $salad]; sort($w); return $a === $w; })(), true);
fySetTaste(['topics' => [], 'diet' => []], $me);

echo "\n2. מה יש בבית\n";
check('בלי מה יש בבית — missing לא ידוע (null)', tonight($me)['items'][0]['missing'], null);
pantryAdd(['פסטה', 'רסק עגבניות', 'גבינה צהובה', 'חזה עוף', 'ביצים'], 'text', $me);
$r = tonight($me);
check('יש הכול לפסטה — ראשונה, בלי חסר', [$r['items'][0]['id'], $r['items'][0]['missing']], [$pasta, []]);
$sch = array_values(array_filter($r['items'], fn($i) => $i['id'] === $schnitzel))[0] ?? null;
check('שניצל — חסר רק פירורי לחם', $sch['missing'] ?? 'לא הוצע', ['פירורי לחם']);

echo "\n3. מה לא\n";
db()->prepare("INSERT INTO user_events (user_id, kind, recipe_id, created_at) VALUES (?, 'cook', ?, ?)")->execute([$me['id'], $pasta, nowIso()]);
check('בישלתי פסטה השבוע — כבר לא ראשונה', tonight($me)['items'][0]['id'] !== $pasta, true);
leaveShare($soup, $me);
fyEvent($me, 'not_interested', $salad);
check('"הסר מהרשימה שלי" ו"לא מעניין" — לא מוצעים', [in_array($soup, $all()), in_array($salad, $all())], [false, false]);

echo "\n4. מגוון ו\"עוד רעיונות\"\n";
$first = tonight($me, ['seed' => 0]);
$next = tonight($me, ['seed' => 1, 'exclude' => $ids($first)]);
check('עוד רעיונות — בלי מה שכבר הוצג', array_intersect($ids($first), $ids($next)), []);
check('אין עוד — more=false', tonight($me, ['exclude' => $all()])['more'], false);

echo "\n";
@array_map('unlink', glob("$tmp/*") ?: []);
if ($fail) { echo "\u{274C} נכשלו " . count($fail) . ': ' . implode(' | ', $fail) . "\n"; exit(1); }
echo "\u{2705} כל הבדיקות עברו\n";
