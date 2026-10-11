<?php
/**
 * בדיקת catalog.php — נושא מהשם, הצעת כשרות, מונים, וסינון לפי נושא באינדקס — על מסד זמני.
 * הרצה: php recipes-app/tools/catalog-check.php
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/recipes-cat-' . getmypid();
@mkdir($tmp . '/media', 0775, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('MEDIA_DIR', $tmp . '/media');
@ini_set('sendmail_path', '/bin/true');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/network.php';
require_once __DIR__ . '/../lib/privacy.php';
require_once __DIR__ . '/../lib/importer.php';
require_once __DIR__ . '/../lib/web_index.php';
require_once __DIR__ . '/../lib/catalog.php';

$fail = [];
function check(string $label, $got, $want): void {
    global $fail;
    $ok = $got === $want;
    echo ($ok ? "  \u{2705} " : "  \u{274C} ") . $label . ': ' . var_export($got, true) .
         ($ok ? "\n" : '  (צפוי ' . var_export($want, true) . ")\n");
    if (!$ok) $fail[] = $label;
}
$tag = fn(string $axis, string $name) => catalogTagIds($axis)[$name];
$names = function (array $ids): array {
    $all = db()->query('SELECT id, name FROM tags')->fetchAll(PDO::FETCH_KEY_PAIR);
    $out = array_map(fn($i) => $all[$i], $ids); sort($out); return $out;
};

echo "\n1. נושא מהשם\n";
foreach ([
    'עוגת גבינה אפויה'            => ['עוגות'],
    'עוגיות חמאה'                 => ['עוגיות'],
    'מרק עדשים כתומות'            => ['מרקים'],
    'סלט קישואים'                 => ['סלטים'],          // לא "קיש"
    'פשטידת ברוקולי'              => ['מאפים'],
    'שניצל אפוי בתנור'            => ['מנה עיקרית'],
    'מוס שוקולד'                  => ['קינוחים'],
    'לימונדה נענע'                => ['שתייה'],
    'עוגת שוקו'                   => ['עוגות'],          // לא שתייה
    'דגני בוקר ביתיים'            => [],                 // לא "דג"
    'והעוגה של סבתא'              => ['עוגות'],          // תחילית
    "צ'יזקייק פירות יער"          => ['עוגות'],
] as $title => $want) check($title, $names(catalogGuessTopics($title)), $want);

echo "\n2. הצעת כשרות\n";
check('עוף ושום — בשרי', catalogGuessKosher(['1 עוף שלם', '5 שיני שום']), 'בשרי');
check('חמאה וקמח — חלבי', catalogGuessKosher(['200 גרם חמאה', '2 כוסות קמח']), 'חלבי');
check('חלבונים — לא חלב', catalogGuessKosher(['4 חלבונים', 'כוס סוכר']), null);
check('חלב סויה וחמאת בוטנים — לא חלבי', catalogGuessKosher(['כוס חלב סויה', '2 כפות חמאת בוטנים']), null);
check('עוף ושמנת — סותר, אין הצעה', catalogGuessKosher(['חזה עוף', 'שמנת מתוקה']), null);
check('ירקות בלבד — אין הצעה (לא "פרווה")', catalogGuessKosher(['גזר', 'בצל', 'שמן']), null);
check('רכיב אחד — אין על מה', catalogGuessKosher(['חמאה']), null);
check('ייבוא: הקטגוריה גוברת על הרכיבים', importGuessKosher(['מתכונים חלביים'], ['קמח', 'ביצים'])['name'], 'חלבי');
check('ייבוא: בלי קטגוריה — מהרכיבים', importGuessKosher([], ['עוף', 'אורז'])['name'], 'בשרי');
check('ייבוא: כשרות לעולם לא בתגים', $names(importGuessTags(['מתכונים חלביים', 'עוגות'], 'עוגת גבינה')), ['עוגות']);
check('ייבוא: נושא מהשם + "מתאים ל" מהקטגוריות', $names(importGuessTags(['טבעוני'], 'מרק ירקות')), ['טבעוני', 'מרקים']);

echo "\n3. מונים\n";
$mk = function (string $u) {
    $r = createUser($u, "$u@x.com", 'sod12345', $u);
    if (!empty($r['token'])) verifyEmail($r['token']);
    return ['id' => $r['id'], 'role' => 'user', 'username' => $u, 'display_name' => $u];
};
$dana = $mk('dana'); $noa = $mk('noa'); $eli = $mk('eli');
$sec = [['ingredients' => [['free_text' => 'קמח']], 'steps' => [['text' => 'לאפות']]]];
$cake = $tag('topic', 'עוגות'); $soup = $tag('topic', 'מרקים'); $dairy = $tag('kosher', 'חלבי');
saveRecipe(['title' => 'עוגה ציבורית', 'visibility' => 'public', 'tag_ids' => [$cake, $dairy], 'sections' => $sec], $dana);
$priv = saveRecipe(['title' => 'עוגה פרטית', 'visibility' => 'private', 'tag_ids' => [$cake], 'sections' => $sec], $dana);
saveRecipe(['title' => 'מרק של אלי', 'visibility' => 'public', 'tag_ids' => [$soup], 'sections' => $sec], $eli);
$c = catalogCounts($noa);
check('נועה: עוגה ציבורית אחת, מרק אחד, חלבי אחד', [$c[$cake] ?? 0, $c[$soup] ?? 0, $c[$dairy] ?? 0], [1, 1, 1]);
check('דנה רואה גם את הפרטית שלה', catalogCounts($dana)[$cake], 2);
shareRecipe($priv, [$noa['id']], $dana);
check('שותפה עם נועה — נספרת אצלה', catalogCounts($noa)[$cake], 2);
blockUser($eli['id'], $noa);
check('חסמה את אלי — המרק שלו לא נספר', catalogCounts($noa)[$soup] ?? 0, 0);
check('אורח — רק ציבוריים', [catalogCounts(null)[$cake], catalogCounts(null)[$soup]], [1, 1]);
saveRecipe(['title' => 'מרק ארוך', 'visibility' => 'public', 'work_minutes' => 20, 'wait_minutes' => 100, 'sections' => $sec], $eli);
saveRecipe(['title' => 'סלט מהיר', 'visibility' => 'public', 'work_minutes' => 10, 'difficulty' => 'easy', 'sections' => $sec], $dana);
check('זמן כולל עד 30 — רק הקצר; בלי זמן כלל — לא נכלל', array_column(searchRecipes($dana, '', ['max_minutes' => 30]), 'title'), ['סלט מהיר']);
check('קושי + זמן', array_column(searchRecipes($dana, '', ['max_minutes' => '30', 'difficulty' => 'easy']), 'title'), ['סלט מהיר']);
check('המונים תואמים לרשימה המסוננת', count(searchRecipes($noa, '', ['tag_ids' => [$cake]])), catalogCounts($noa)[$cake]);

echo "\n4. סינון לפי נושא בחיפוש ברשת\n";
$pdo = db();
$pdo->prepare('INSERT INTO index_sites (host, name, start_url, added_at, next_crawl_at) VALUES (?,?,?,?,?)')->execute(['a.co.il', 'א', 'https://a.co.il/', nowIso(), time() + 9999]);
$sa = (int) $pdo->lastInsertId();
$ins = $pdo->prepare('INSERT INTO index_entries (site_id, url, title, stems, seen_at) VALUES (?,?,?,?,1)');
foreach (['עוגת גבינה', 'קיש גבינה', 'סלט קישואים וגבינה', 'מרק גבינות', 'עוגיות גבינה'] as $i => $t) $ins->execute([$sa, "https://a.co.il/r/$i/", $t, indexStems($t)]);
$titles = fn(array $r) => array_column($r['results'], 'title');
check('"גבינה" + מאפים — הקיש בלבד (לא הקישואים)', $titles(indexSearch('גבינה', 0, null, 'מאפים')), ['קיש גבינה']);
check('"גבינה" + עוגות — לא העוגיות', $titles(indexSearch('גבינה', 0, null, 'עוגות')), ['עוגת גבינה']);
check('נושא בלי מילים — דפדוף', indexSearch('', 0, null, 'מרקים')['total'], 1);
check('נושא לא מוכר — מתעלמים, כמו בלי נושא', indexSearch('גבינה', 0, null, 'אין כזה')['total'], indexSearch('גבינה')['total']);
check('הקיים אצלי — מסונן גם הוא', array_column(indexSearch('עוגה', 0, $dana, 'מרקים')['local'], 'title'), []);

echo "\n";
@array_map('unlink', glob("$tmp/{,media/}*", GLOB_BRACE) ?: []);
if ($fail) { echo "\u{274C} נכשלו " . count($fail) . ': ' . implode(' | ', $fail) . "\n"; exit(1); }
echo "\u{2705} כל הבדיקות עברו\n";
