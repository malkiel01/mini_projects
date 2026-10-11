<?php
/**
 * בדיקת foryou.php — "בשבילך": אירועים (רק בהסכמה), טעם מוצהר, עונה וחגים, ניקוד,
 * סיבה, מגוון, "לא מעניין", "פחות כאלה", סינון תזונה, מהרשת, המלצות מחברים, מדידה.
 * הרצה: php recipes-app/tools/foryou-check.php
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/recipes-fy-' . getmypid();
@mkdir($tmp . '/media', 0775, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('MEDIA_DIR', $tmp . '/media');
@ini_set('sendmail_path', '/bin/true');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/social.php';
require_once __DIR__ . '/../lib/network.php';
require_once __DIR__ . '/../lib/shopping.php';
require_once __DIR__ . '/../lib/foryou.php';

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
$dana = $mk('dana', 'דנה'); $noa = $mk('noa', 'נועה'); $eli = $mk('eli', 'אלי'); $tal = $mk('tal', 'טל');
$T = fn(string $axis, string $name) => catalogTagIds($axis)[$name];
$sec = [['ingredients' => [['free_text' => 'קמח'], ['free_text' => 'ביצים']], 'steps' => [['text' => 'מערבבים'], ['text' => 'אופים']]]];
$rec = fn(string $title, array $u, array $tags, string $vis = 'public') => saveRecipe(['title' => $title, 'visibility' => $vis, 'tag_ids' => $tags, 'sections' => $sec], $u);
$cheese  = $rec('עוגת גבינה', $noa, [$T('topic', 'עוגות')]);
$choco   = $rec('עוגת שוקולד', $noa, [$T('topic', 'עוגות')]);
$yeast   = $rec('עוגת שמרים', $tal, [$T('topic', 'עוגות')]);
$apple   = $rec('עוגת תפוחים', $tal, [$T('topic', 'עוגות')]);
$lentil  = $rec('מרק עדשים', $eli, [$T('topic', 'מרקים')]);
$vegan   = $rec('סלט קינואה', $tal, [$T('topic', 'סלטים'), $T('suitable', 'טבעוני')]);
$schnitz = $rec('שניצל', $tal, [$T('topic', 'מנה עיקרית'), $T('kosher', 'בשרי')]);
$title = fn(array $r) => array_column($r['items'], 'title');
$reasonOf = fn(array $r, string $t) => array_column($r['items'], 'reason', 'title')[$t] ?? null;

echo "\n1. אירועים — רק בהסכמה\n";
fyEvent($dana, 'open', $cheese); fyEvent($dana, 'open', $cheese);
check('פתיחה נרשמת — פעם ביום לכל מתכון', (int) db()->query("SELECT COUNT(*) FROM user_events WHERE kind = 'open'")->fetchColumn(), 1);
setUserPrefs(['personalize' => false], $dana);
fyEvent($dana, 'open', $choco);
check('התאמה אישית כבויה — לא נרשם כלום', (int) db()->query("SELECT COUNT(*) FROM user_events WHERE recipe_id = $choco")->fetchColumn(), 0);
setUserPrefs(['personalize' => true], $dana);
expectError('אירוע לא מוכר', fn() => fyClientEvent(['kind' => 'hack', 'recipe_id' => 1], $GLOBALS['dana']), 'לא מוכר');
expectError('"פחות כאלה" — רק נושא', fn() => fyClientEvent(['kind' => 'less_topic', 'tag_id' => $GLOBALS['T']('kosher', 'בשרי')], $GLOBALS['dana']), 'נושא');
$list = shoppingCreate([$lentil => 1], 'שבת', $eli);
check('הוספה לקניות — "shop" (של אלי, על המתכון שלו)', (int) db()->query("SELECT COUNT(*) FROM user_events WHERE kind = 'shop' AND recipe_id = $lentil")->fetchColumn(), 1);
$steps = db()->query("SELECT st.id FROM steps st JOIN sections s ON s.id = st.section_id WHERE s.recipe_id = $cheese")->fetchAll(PDO::FETCH_COLUMN);
cookingSetDone((int) $steps[0], true, $dana);
check('שלב ראשון — עוד לא "בישלתי"', (int) db()->query("SELECT COUNT(*) FROM user_events WHERE kind = 'cook'")->fetchColumn(), 0);
cookingSetDone((int) $steps[1], true, $dana);
check('השלב האחרון — "בישלתי"', (int) db()->query("SELECT COUNT(*) FROM user_events WHERE kind = 'cook' AND recipe_id = $cheese")->fetchColumn(), 1);

echo "\n2. עונה וחגים (לוח עברי, שעון ישראל)\n";
$at = fn(string $d) => array_column(fySeason((new DateTimeImmutable($d, new DateTimeZone('Asia/Jerusalem')))->getTimestamp()), 'label');
check('חנוכה 5787 (6.12.2026, ראשון)', $at('2026-12-06 12:00'), ['לחנוכה']);
check('שבוע לפני פסח 5787 (15.4.2027, חמישי) — פסח וגם שבת', $at('2027-04-15 12:00'), ['לפסח', 'לשבת']);
check('פורים בשנה מעוברת (אדר ב׳, 23.3.2027)', $at('2027-03-23 12:00'), ['לפורים']);
check('יום רגיל (11.10.2026, ראשון) — כלום', $at('2026-10-11 12:00'), []);

echo "\n3. ניקוד, סיבה ומגוון\n";
$r = forYou($dana);
check('בלי אותות — "מה אתה אוהב?"', $r['needs_taste'], true);
toggleFavorite($cheese, $dana);                       // שמרה עוגת גבינה — אוהבת עוגות
fySetTaste(['topics' => [$T('topic', 'עוגות'), $T('topic', 'מרקים')], 'diet' => []], $dana);
$r = forYou($dana);
check('מה ששמורה — לא מוצע שוב', in_array('עוגת גבינה', $title($r), true), false);
check('עוגות קודם, עם סיבה', [array_slice($title($r), 0, 1) !== [] && str_starts_with($title($r)[0], 'עוגת'), $reasonOf($r, $title($r)[0])], [true, 'כי אתה אוהב עוגות']);
check('מגוון: לכל היותר 2 עוגות (יש 3 מועמדות)', count(array_filter($title($r), fn($t) => str_starts_with($t, 'עוגת'))), 2);
check('לכל היותר 2 מאותו כותב', max(array_count_values(array_column($r['items'], 'by'))) <= 2, true);
followUser($eli['id'], $dana);
$pos = fn(array $r, string $t) => array_search($t, $title($r), true);
$before = forYou($dana);
check('מי שאני עוקבת אחריו — עם הסיבה', $reasonOf($before, 'מרק עדשים'), 'של אלי');
fyClientEvent(['kind' => 'less_topic', 'tag_id' => $T('topic', 'מרקים')], $dana);
$after = forYou($dana);
$score = fn(array $r, string $t) => array_column($r['items'], 'score', 'title')[$t] ?? -99;
check('"פחות מרקים" — הניקוד של המרק יורד', $score($after, 'מרק עדשים') < $score($before, 'מרק עדשים'), true);
fyClientEvent(['kind' => 'not_interested', 'recipe_id' => $choco], $dana);
check('"לא מעניין" — יוצא', in_array('עוגת שוקולד', $title(forYou($dana)), true), false);
$secret = $rec('הסוד של נועה', $noa, [], 'private');
shareRecipe($secret, [$dana['id']], $noa);
check('שותף איתי ולא פתחתי — עם הסיבה', $reasonOf(forYou($dana), 'הסוד של נועה'), 'נועה שיתף/ה איתך');

echo "\n4. תזונה מוצהרת — מסננת (החלטה 6)\n";
fySetTaste(['topics' => [], 'diet' => [$T('suitable', 'טבעוני')]], $dana);
check('טבעונית — רק מה שמסומן טבעוני', $title(forYou($dana)), ['סלט קינואה']);
fySetTaste(['topics' => [$T('topic', 'עוגות')], 'diet' => []], $dana);

echo "\n5. מהרשת — רק עם תמונה, לפי הנושאים\n";
$pdo = db();
$pdo->prepare('INSERT INTO index_sites (host, name, start_url, added_at, next_crawl_at) VALUES (?,?,?,?,?)')->execute(['a.co.il', 'אתר א', 'https://a.co.il/', nowIso(), time() + 9999]);
$sa = (int) $pdo->lastInsertId();
$ins = $pdo->prepare('INSERT INTO index_entries (site_id, url, title, stems, image, seen_at, verified) VALUES (?,?,?,?,?,1,1)');
// נושא שבאפליקציה אין בו מועמדים (בעוגות כבר שתיים — המגוון לא נותן שלישית)
fySetTaste(['topics' => [$T('topic', 'עוגות'), $T('topic', 'מאפים')], 'diet' => []], $dana);
$ins->execute([$sa, 'https://a.co.il/1/', 'פשטידת גזר', ' פשטיד גזר', 'https://a.co.il/1.jpg']);
$carrot = (int) $pdo->lastInsertId();
$ins->execute([$sa, 'https://a.co.il/2/', 'פשטידת בצל', ' פשטיד בצל', null]);
$ins->execute([$sa, 'https://a.co.il/4/', 'עוגת גזר', ' עוג גזר', 'https://a.co.il/4.jpg']);
$r = forYou($dana, 30);
check('מאפה מהרשת עם תמונה — כן; בלי תמונה — לא', [in_array('פשטידת גזר', $title($r), true), in_array('פשטידת בצל', $title($r), true)], [true, false]);
check('ועוגה שלישית — לא (מגוון: שתיים לנושא)', in_array('עוגת גזר', $title($r), true), false);
check('פותחים דרך החיפוש ברשת', array_column($r['items'], 'open', 'title')['פשטידת גזר'] ?? null, "#/web/$carrot");
fyClientEvent(['kind' => 'not_interested', 'entry_id' => $carrot], $dana);
check('"לא מעניין" — גם מהרשת', in_array('פשטידת גזר', $title(forYou($dana, 30)), true), false);

echo "\n6. המלצה מחבר — ראשונה\n";
$pdo->prepare('INSERT INTO index_entries (site_id, url, title, stems, image, seen_at, verified) VALUES (?,?,?,?,?,1,1)')->execute([$sa, 'https://a.co.il/3/', 'פשטידת תרד', ' פשטיד תרד', 'https://a.co.il/3.jpg']);
recommend(['entry_id' => (int) $pdo->lastInsertId()], [$dana['id']], 'נראה טוב', $noa);
$r = forYou($dana);
check('📨 ראשונה, עם שם הממליצה', [$r['items'][0]['title'], $r['items'][0]['reason']], ['פשטידת תרד', '📨 נועה ממליץ/ה']);

echo "\n7. התאמה אישית כבויה — רק עונה ופופולרי (החלטה 7)\n";
setUserPrefs(['personalize' => false], $dana);
$r = forYou($dana, 30);
check('בלי סיבות אישיות', array_values(array_filter(array_column($r['items'], 'reason'), fn($x) => str_starts_with($x, 'כי אתה') || str_starts_with($x, 'של '))), []);
check('ובלי "מה אתה אוהב?"', $r['needs_taste'], false);
setUserPrefs(['personalize' => true], $dana);

echo "\n8. מדידה\n";
fyClientEvent(['kind' => 'foryou_click', 'recipe_id' => $apple], $dana);
fyClientEvent(['kind' => 'cook', 'recipe_id' => $apple], $dana);
$s = foryouStats();
check('לחיצה אחת, ובושל אחריה', [$s['clicks'], $s['then_cooked']], [1, 1]);
deleteAccount($dana, 'sod12345');
check('מחיקת חשבון — האירועים והטעם נמחקו', [(int) db()->query('SELECT COUNT(*) FROM user_events WHERE user_id = ' . (int) $dana['id'])->fetchColumn(),
      (int) db()->query('SELECT COUNT(*) FROM user_taste')->fetchColumn()], [0, 0]);

echo "\n";
@array_map('unlink', glob("$tmp/{,media/}*", GLOB_BRACE) ?: []);
if ($fail) { echo "\u{274C} נכשלו " . count($fail) . ': ' . implode(' | ', $fail) . "\n"; exit(1); }
echo "\u{2705} כל הבדיקות עברו\n";
