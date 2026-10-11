<?php
/**
 * בדיקת collections.php — אוספים, "שמורים", שיתוף אוסף, והמיגרציות החד־פעמיות
 * (נושאים למתכונים קיימים, מועדפים ל"שמורים") — על מסד זמני.
 * הרצה: php recipes-app/tools/collections-check.php
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/recipes-coll-' . getmypid();
@mkdir($tmp . '/media', 0775, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('MEDIA_DIR', $tmp . '/media');
@ini_set('sendmail_path', '/bin/true');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/social.php';
require_once __DIR__ . '/../lib/collections.php';
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
function expectError(string $label, callable $fn, string $needle = ''): void {
    global $fail;
    try { $fn(); echo "  \u{274C} $label: לא נזרקה שגיאה\n"; $fail[] = $label; }
    catch (AppError $e) {
        $ok = $needle === '' || str_contains($e->getMessage(), $needle);
        echo ($ok ? "  \u{2705} " : "  \u{274C} ") . "$label: \"{$e->getMessage()}\"\n";
        if (!$ok) $fail[] = $label;
    }
}
$mk = function (string $u) {
    $r = createUser($u, "$u@x.com", 'sod12345', $u);
    if (!empty($r['token'])) verifyEmail($r['token']);
    $row = db()->query('SELECT role FROM users WHERE id = ' . (int) $r['id'])->fetch();
    return ['id' => $r['id'], 'role' => $row['role'], 'username' => $u, 'display_name' => $u];
};
$dana = $mk('dana'); $noa = $mk('noa'); $eli = $mk('eli');
$sec = [['ingredients' => [['free_text' => 'קמח']], 'steps' => [['text' => 'לאפות']]]];
$rec = fn(string $t, string $v, array $u) => saveRecipe(['title' => $t, 'visibility' => $v, 'sections' => $sec], $u);
$titles = fn(array $items) => array_column($items, 'title');
function viewCode(int $id, array $u) { try { collectionView($id, $u); return 'רואה'; } catch (AppError $e) { return $e->status; } }
$pdo = db();

echo "\n1. מיגרציות חד־פעמיות\n";
$cake = $rec('עוגת גבינה של סבתא', 'public', $dana);
$soupTagged = saveRecipe(['title' => 'מרק עוף', 'visibility' => 'private', 'tag_ids' => [catalogTagIds('topic')['סלטים']], 'sections' => $sec], $dana);
$plain = $rec('משהו טעים', 'public', $dana);
$elisPub = $rec('קציצות של אלי', 'public', $eli);
$elisGone = $rec('יימחק', 'public', $eli);
$pdo->prepare('INSERT INTO favorites (user_id, recipe_id, title_snapshot, created_at) VALUES (?,?,?,?)')->execute([$noa['id'], $elisPub, 'קציצות של אלי', '2026-01-01T00:00:00Z']);
$pdo->prepare('INSERT INTO favorites (user_id, recipe_id, title_snapshot, created_at) VALUES (?,?,?,?)')->execute([$noa['id'], $elisGone, 'יימחק', '2026-01-02T00:00:00Z']);
deleteRecipe($elisGone, $eli);
$pdo->exec("DELETE FROM migrations WHERE name IN ('topics-backfill', 'favorites-to-saved')");
$pdo->exec('DELETE FROM collection_items'); $pdo->exec('DELETE FROM collections');
migrate($pdo);
$topicsOf = function (int $rid) use ($pdo) { $st = $pdo->prepare("SELECT t.name FROM recipe_tags rt JOIN tags t ON t.id = rt.tag_id WHERE rt.recipe_id = ? AND t.axis = 'topic' ORDER BY t.name"); $st->execute([$rid]); return $st->fetchAll(PDO::FETCH_COLUMN); };
check('נושא לפי השם — למתכון בלי נושא', $topicsOf($cake), ['עוגות']);
check('מתכון שכבר יש לו נושא — לא נגעו (גם אם השם אומר אחרת)', $topicsOf($soupTagged), ['סלטים']);
check('שם בלי מילת נושא — בלי נושא', $topicsOf($plain), []);
check('כשרות — לא שויכה', (int) $pdo->query("SELECT COUNT(*) FROM recipe_tags rt JOIN tags t ON t.id = rt.tag_id WHERE t.axis = 'kosher'")->fetchColumn(), 0);
$pdo->exec("DELETE FROM recipe_tags WHERE recipe_id = $cake");
migrate($pdo);
check('רצה פעם אחת: הסרתי את הנושא — לא חוזר', $topicsOf($cake), []);
$favs = listFavorites($noa);
check('המועדפים עברו ל"שמורים", כולל מה שנמחק (עם השם)', array_map(fn($f) => [$f['title'], $f['status']], $favs), [['יימחק', 'gone'], ['קציצות של אלי', 'ok']]);
$mine = collectionsMine($noa)['mine'];
check('"שמורים" — האוסף הראשון', [$mine[0]['name'], $mine[0]['is_saved'], $mine[0]['count']], ['שמורים', true, 1]);
check('תוויות אישיות — הטבלאות נמחקו', (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('user_tags', 'recipe_user_tags')")->fetchColumn(), 0);

echo "\n2. אוסף — יצירה ועריכה\n";
expectError('בלי שם', fn() => collectionCreate(['name' => '  '], $GLOBALS['dana']), 'שם');
expectError('פרטיות לא מוכרת', fn() => collectionCreate(['name' => 'x', 'visibility' => 'friends'], $GLOBALS['dana']), 'לא מוכרת');
$mom = collectionCreate(['name' => 'ספר מתכונים לאמא', 'icon' => '📖', 'description' => 'מה שאמא ביקשה'], $dana);
check('נוצר: פרטי, ריק, בסמל שבחרתי', [$mom['visibility'], $mom['count'], $mom['icon']], ['private', 0, '📖']);
$saved = collectionsMine($dana)['mine'][0];
expectError('"שמורים" לא נמחק', fn() => collectionDelete($GLOBALS['saved']['id'], $GLOBALS['dana']), 'שמורים');
check('"שמורים" לא משנה שם (אבל כן סמל)', [collectionUpdate($saved['id'], ['name' => 'אחר', 'icon' => '⭐'], $dana)['name'], collectionRow($saved['id'])['icon']], ['שמורים', '⭐']);
expectError('אוסף של אחר — לא עורכים', fn() => collectionUpdate($GLOBALS['mom']['id'], ['name' => 'שלי'], $GLOBALS['noa']), 'אינו קיים');

echo "\n3. פריטים\n";
$danaPriv = $rec('הסוד של דנה', 'private', $dana);
$elisPriv = $rec('הסוד של אלי', 'private', $eli);
shareRecipe($elisPriv, [$dana['id']], $eli);           // גלוי לדנה — שותף איתה
$pdo->prepare('INSERT INTO index_sites (host, name, start_url, added_at, next_crawl_at) VALUES (?,?,?,?,?)')->execute(['a.co.il', 'אתר א', 'https://a.co.il/', nowIso(), time() + 9999]);
$pdo->prepare('INSERT INTO index_entries (site_id, url, title, stems, image, seen_at) VALUES (?,?,?,?,?,1)')->execute([(int) $pdo->lastInsertId(), 'https://a.co.il/r/1/', 'עוגת שמרים', ' עוג שמר', 'https://a.co.il/1.jpg']);
$entry = (int) $pdo->lastInsertId();
foreach ([['recipe_id' => $cake], ['recipe_id' => $danaPriv], ['recipe_id' => $elisPub], ['recipe_id' => $elisPriv], ['entry_id' => $entry]] as $t) collectionToggle($mom['id'], $t, true, $dana);
collectionToggle($mom['id'], ['recipe_id' => $cake], true, $dana);
$view = collectionView($mom['id'], $dana);
check('חמישה פריטים, לפי סדר ההוספה, בלי כפילות', $titles($view['items']), ['עוגת גבינה של סבתא', 'הסוד של דנה', 'קציצות של אלי', 'הסוד של אלי', 'עוגת שמרים']);
check('מהרשת — בלי לייבא: תמונה, אתר, ופתיחה דרך החיפוש', [$view['items'][4]['kind'], $view['items'][4]['thumb'], $view['items'][4]['open']], ['web', 'https://a.co.il/1.jpg', "#/web/$entry"]);
expectError('פרטי של אחר שלא גלוי לי — לא נכנס (גם לאוסף שלי)', fn() => collectionToggle(collectionsMine($GLOBALS['noa'])['mine'][0]['id'], ['recipe_id' => $GLOBALS['elisPriv']], true, $GLOBALS['noa']), 'המתכון אינו קיים');
$for = collectionsFor(['recipe_id' => $cake], $dana);
check('גיליון "לאוסף": האוספים שלי, ובאילו הוא כבר', array_map(fn($c) => [$c['name'], $c['has']], $for), [['שמורים', false], ['ספר מתכונים לאמא', true]]);
$ids = array_column($view['items'], 'item_id');
collectionMoveItem($ids[4], -1, $dana);
collectionMoveItem($ids[0], -1, $dana);   // כבר ראשון — כלום
check('הזזה למעלה', $titles(collectionView($mom['id'], $dana)['items'])[3], 'עוגת שמרים');
collectionRemoveItem($ids[3], $dana);
expectError('הסרה מאוסף של אחר', fn() => collectionRemoveItem($GLOBALS['ids'][0], $GLOBALS['noa']), 'אינו קיים');
check('הסרה — "הסוד של אלי" יצא', in_array('הסוד של אלי', $titles(collectionView($mom['id'], $dana)['items']), true), false);
collectionToggle($mom['id'], ['recipe_id' => $elisPriv], true, $dana);
unshareRecipe($elisPriv, $dana['id'], $eli);
$st = array_column(collectionView($mom['id'], $dana)['items'], 'status', 'title');
check('אלי הפסיק לשתף — אצל דנה "אינו זמין כרגע"', $st['הסוד של אלי'], 'hidden');

echo "\n4. שיתוף אוסף (החלטה 3)\n";
check('לפני שיתוף — נועה לא רואה את האוסף', viewCode($mom['id'], $noa), 404);
check('ולא את הסוד של דנה', loadRecipe($danaPriv, $noa), null);
shareCollection($mom['id'], [$noa['id']], $dana);
$nv = collectionView($mom['id'], $noa);
check('נועה רואה: הפרטי של דנה — כן; הפרטי של אלי — לעולם לא', $titles($nv['items']), ['עוגת גבינה של סבתא', 'הסוד של דנה', 'קציצות של אלי', 'עוגת שמרים']);
check('ופותחת את הסוד של דנה', loadRecipe($danaPriv, $noa)['title'], 'הסוד של דנה');
check('אצלה — "שותפו איתי", חדש עד שנפתח', [collectionsMine($noa)['shared'][0]['name'], collectionsMine($noa)['shared'][0]['new']], ['ספר מתכונים לאמא', false]);
check('הסוד לא מופיע ברשימה הראשית שלה (רק באוסף)', in_array($danaPriv, array_column(searchRecipes($noa), 'id'), true), false);
expectError('נועה לא מוסיפה לאוסף של דנה', fn() => collectionToggle($GLOBALS['mom']['id'], ['recipe_id' => $GLOBALS['cake']], false, $GLOBALS['noa']), 'אינו שלך');
unshareCollection($mom['id'], $noa['id'], $dana);
check('ביטול השיתוף — הסוד כבר לא גלוי', loadRecipe($danaPriv, $noa), null);
collectionUpdate($mom['id'], ['visibility' => 'public'], $dana);
$pv = collectionView($mom['id'], $eli);
check('אוסף ציבורי — לא חושף את הפרטי של דנה (את של אלי — הוא שלו)', $titles($pv['items']), ['עוגת גבינה של סבתא', 'קציצות של אלי', 'עוגת שמרים', 'הסוד של אלי']);
check('בפרופיל של דנה — האוסף הציבורי (ו"שמורים" הפרטי לא)', array_column(personCollections($dana['id'], $eli), 'name'), ['ספר מתכונים לאמא']);
setUserPrefs(['share_from' => 'none'], $noa);
expectError('מי שלא מקבל שיתופים — גם לא אוסף', fn() => shareCollection($GLOBALS['mom']['id'], [$GLOBALS['noa']['id']], $GLOBALS['dana']), 'לא מקבל');
setUserPrefs(['share_from' => 'all'], $noa);
shareCollection($mom['id'], [$noa['id']], $dana);
blockUser($dana['id'], $noa);
check('חסימה — שיתוף האוסף נמחק', (int) $pdo->query('SELECT COUNT(*) FROM collection_shares')->fetchColumn(), 0);
check('ואת האוסף הציבורי — לא רואה', viewCode($mom['id'], $noa), 404);
unblockUser($dana['id'], $noa);
shareCollection($mom['id'], [$noa['id']], $dana);
leaveCollectionShare($mom['id'], $noa);
check('הנמענת עוזבת', collectionsMine($noa)['shared'], []);

echo "\n5. ♡ = \"שמורים\"\n";
check('♡ על מתכון — נכנס ל"שמורים"', [toggleFavorite($cake, $noa), in_array('עוגת גבינה של סבתא', $titles(collectionView(collectionsMine($noa)['mine'][0]['id'], $noa)['items']), true)], [true, true]);
check('ו"שמורים" הוא אוסף רגיל — אפשר להוסיף אליו גם מהרשת', collectionToggle(collectionsMine($noa)['mine'][0]['id'], ['entry_id' => $entry], true, $noa), true);
check('listFavorites — רק מתכונים', count(listFavorites($noa)), 3);
collectionDelete($mom['id'], $dana);
check('מחיקת אוסף — המתכונים עצמם נשארים', (bool) loadRecipe($cake, $dana), true);

echo "\n";
@array_map('unlink', glob("$tmp/{,media/}*", GLOB_BRACE) ?: []);
if ($fail) { echo "\u{274C} נכשלו " . count($fail) . ': ' . implode(' | ', $fail) . "\n"; exit(1); }
echo "\u{2705} כל הבדיקות עברו\n";
