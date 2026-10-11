<?php
/**
 * בדיקת network.php — שיתוף מתכונים ומעקב — על מסד זמני.
 * הרצה: php recipes-app/tools/network-check.php
 *
 * מוכיחה: מתכון פרטי ששותף גלוי לנמען בלבד (מתכון, רשימה, מועדפים), "חדש" עד
 * שנפתח, רק הבעלים משתף ומסיר, הנמען מסיר את עצמו; חיפוש אנשים בלי חסומים ולא
 * מאומתים; מעקב — רק ציבורי (ומה ששותף איתי); פרופיל ומונים; מחיקה מנקה.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/recipes-net-' . getmypid();
@mkdir($tmp, 0775, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('MEDIA_DIR', $tmp . '/media');
@ini_set('sendmail_path', '/bin/true');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/network.php';
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
$mk = function (string $u, string $name, bool $verify = true) {
    $r = createUser($u, "$u@x.com", 'sod12345', $name);
    if ($verify && !empty($r['token'])) verifyEmail($r['token']);
    return ['id' => $r['id'], 'role' => 'user', 'username' => $u, 'display_name' => $name];
};
$dana = $mk('dana', 'דנה כהן');          // הראשונה — מנהלת, ומאומתת אוטומטית
$noa  = $mk('noa', 'נועה');
$dan  = $mk('dan', 'דן לוי');
$ghost = $mk('ghost', 'דני לא מאומת', false);
$bad  = $mk('bad', 'דנית חסומה');
db()->prepare('UPDATE users SET blocked = 1 WHERE id = ?')->execute([$bad['id']]);

$sec = [['ingredients' => [['free_text' => 'קמח']], 'steps' => [['text' => 'לאפות']]]];
$priv = saveRecipe(['title' => 'עוגת שמרים של סבתא', 'visibility' => 'private', 'sections' => $sec], $dana);
$pub  = saveRecipe(['title' => 'עוגת גבינה', 'visibility' => 'public', 'sections' => $sec], $dana);
$noaPub = saveRecipe(['title' => 'עוגת תפוחים', 'visibility' => 'public', 'sections' => $sec], $noa);
$ids = fn(array $list) => array_column($list, 'id');

echo "\n1. שיתוף\n";
check('לפני שיתוף — נועה לא רואה את הפרטי', [loadRecipe($priv, $noa), in_array($priv, $ids(searchRecipes($noa, 'עוגת')))], [null, false]);
expectError('רק הבעלים משתף', fn() => shareRecipe($GLOBALS['priv'], [$GLOBALS['dan']['id']], $GLOBALS['noa']), 'אינו שלך');
expectError('שיתוף עם עצמי בלבד — אין נמענים', fn() => shareRecipe($GLOBALS['priv'], [$GLOBALS['dana']['id']], $GLOBALS['dana']), 'לא נבחרו');
expectError('שיתוף עם חסום — נדחה', fn() => shareRecipe($GLOBALS['priv'], [$GLOBALS['bad']['id']], $GLOBALS['dana']), 'אינו קיים');
$sh = shareRecipe($priv, [$noa['id'], $noa['id']], $dana);
check('שותף עם נועה (פעם אחת)', array_column($sh, 'username'), ['noa']);
check('נועה רואה אותו, דן לא', [loadRecipe($priv, $noa)['title'], loadRecipe($priv, $dan)], ['עוגת שמרים של סבתא', null]);
$row = array_values(array_filter(searchRecipes($noa, 'עוגת'), fn($r) => $r['id'] === $priv))[0] ?? null;
check('ברשימה של נועה: משותף איתה, וחדש', [$row['shared_with_me'] ?? null, $row['shared_new'] ?? null, $row['is_mine'] ?? null], [true, true, false]);
check('לשונית "שותפו איתי" — רק הוא', $ids(searchRecipes($noa, '', ['shared' => true])), [$priv]);
check('תג: מתכון אחד חדש', sharesUnseen($noa), 1);
markShareSeen($priv, $noa);
check('אחרי שנפתח — לא חדש', [sharesUnseen($noa), array_values(array_filter(searchRecipes($noa), fn($r) => $r['id'] === $priv))[0]['shared_new']], [0, false]);
check('הבעלים רואה את מי ששותף, ושפתחה', [recipeShares($priv, $dana)[0]['username'], recipeShares($priv, $dana)[0]['seen']], ['noa', true]);
expectError('אחר לא רואה למי משותף', fn() => recipeShares($GLOBALS['priv'], $GLOBALS['noa']), 'אינו שלך');
check('מועדף של נועה על פרטי משותף — זמין, לא "מוסתר"', [toggleFavorite($priv, $noa), listFavorites($noa)[0]['status']], [true, 'ok']);
leaveShare($priv, $noa);
check('נועה הסירה את עצמה — שוב לא רואה; המועדף מוסתר', [loadRecipe($priv, $noa), listFavorites($noa)[0]['status']], [null, 'hidden']);
expectError('הסרה כפולה', fn() => leaveShare($GLOBALS['priv'], $GLOBALS['noa']), 'אינו זמין');
shareRecipe($priv, [$noa['id'], $dan['id']], $dana);
check('הבעלים מסיר את דן', array_column(unshareRecipe($priv, $dan['id'], $dana), 'username'), ['noa']);

echo "\n2. חיפוש אנשים\n";
$who = function (string $q, array $u) { $n = array_column(peopleSearch($q, $u), 'username'); sort($n); return $n; };
check('"דנ" — דנה ודן (גם "דן" עם ן סופית); לא חסומה, לא לא-מאומת, לא אני', $who('דנ', $noa), ['dan', 'dana']);
check('"דן" מוצא גם את דנה (סופית = רגילה)', $who('דן', $noa), ['dan', 'dana']);
check('גם לפי מילה שנייה בשם ("כהן")', array_column(peopleSearch('כהן', $noa), 'username'), ['dana']);
check('אות אחת — כלום', peopleSearch('ד', $noa), []);
check('בלי דוא"ל בתוצאה', array_keys(peopleSearch('נועה', $dana)[0]), ['id', 'username', 'display_name', 'following']);

echo "\n3. מעקב\n";
expectError('לא אחרי עצמי', fn() => followUser($GLOBALS['noa']['id'], $GLOBALS['noa']), 'עצמך');
expectError('לא אחרי חסום', fn() => followUser($GLOBALS['bad']['id'], $GLOBALS['noa']), 'אינו קיים');
$p = followUser($dana['id'], $noa);
check('נועה עוקבת אחרי דנה', [$p['following'], $p['followers_n']], [true, 1]);
followUser($dana['id'], $noa);
check('מעקב כפול — עדיין אחד', personProfile($dana['id'], $noa)['person']['followers_n'], 1);
followUser($dana['id'], $dan);
check('"נעקבים" של נועה: הציבורי, ומה ששותף איתה', array_map('intval', $ids(searchRecipes($noa, '', ['following' => true]))) == [$pub, $priv] || array_map('intval', $ids(searchRecipes($noa, '', ['following' => true]))) == [$priv, $pub], true);
check('"נעקבים" של דן: רק הציבורי', $ids(searchRecipes($dan, '', ['following' => true])), [$pub]);
check('בלי מעקב — ריק', $ids(searchRecipes($dana, '', ['following' => true])), []);
$net = myNetwork($noa);
check('האנשים של נועה: עוקבת אחרי דנה (מתכון ציבורי אחד)', [array_column($net['following'], 'username'), $net['following'][0]['public_n']], [['dana'], 1]);
check('העוקבים של דנה: נועה ודן', (function ($n) { sort($n); return $n; })(array_column(myNetwork($dana)['followers'], 'username')), ['dan', 'noa']);

echo "\n4. פרופיל\n";
$pr = personProfile($dana['id'], $dan);
check('דנה בעיני דן: מונים', [$pr['person']['public_n'], $pr['person']['followers_n'], $pr['person']['following_n'], $pr['person']['following'], $pr['person']['is_me']], [1, 2, 0, true, false]);
check('המתכונים שדן רואה אצלה — רק הציבורי', $ids($pr['recipes']), [$pub]);
check('נועה רואה אצלה גם את המשותף', count(personProfile($dana['id'], $noa)['recipes']), 2);
check('דנה בדף של עצמה — הכול, ו"אני"', [count(personProfile($dana['id'], $dana)['recipes']), personProfile($dana['id'], $dana)['person']['is_me']], [2, true]);
check('נועה בעיני דנה: עוקבת אחריי', personProfile($noa['id'], $dana)['person']['follows_me'], true);
unfollowUser($dana['id'], $dan);
check('דן הפסיק לעקוב', personProfile($dana['id'], $dan)['person']['following'], false);
expectError('פרופיל של לא-מאומת — לא קיים', fn() => personProfile($GLOBALS['ghost']['id'], $GLOBALS['noa']), 'אינו קיים');

echo "\n6. מתכון מהרשת — פרטי בלבד\n";
$webSec = $sec;
expectError('ייבוא כציבורי — נחסם', fn() => saveRecipe(['title' => 'מהרשת', 'visibility' => 'public', 'sections' => $GLOBALS['webSec'], 'source_url' => 'https://a.co.il/r/1/'], $GLOBALS['dana']), 'פרטי בלבד');
$wid = saveRecipe(['title' => 'פשטידה מהרשת', 'visibility' => 'private', 'sections' => $sec, 'source_url' => 'https://a.co.il/r/1/', 'source_name' => 'אתר א'], $dana);
expectError('ובעריכה — אי אפשר להפוך לציבורי', fn() => saveRecipe(['title' => 'פשטידה מהרשת', 'visibility' => 'public', 'sections' => $GLOBALS['webSec']], $GLOBALS['dana'], $GLOBALS['wid']), 'פרטי בלבד');
check('מתכון שלי (לא מהרשת) — ציבורי מותר', loadRecipe(saveRecipe(['title' => 'שלי', 'visibility' => 'public', 'sections' => $sec], $dana), $dana)['visibility'], 'public');
check('אבל לשתף אותו — מותר', array_column(shareRecipe($wid, [$noa['id']], $dana), 'username'), ['noa']);
db()->exec("UPDATE recipes SET visibility = 'public' WHERE id = $wid");   // כמו שהיה לפני הכלל
migrate(db());
check('תיקון חד-פעמי: מיובא שהיה ציבורי — עכשיו פרטי', loadRecipe($wid, $dana)['visibility'], 'private');

echo "\n7. המלצה — לעיין במתכון מהרשת\n";
db()->prepare('INSERT INTO index_sites (host, name, start_url, added_at, next_crawl_at) VALUES (?,?,?,?,?)')->execute(['a.co.il', 'אתר א', 'https://a.co.il/', nowIso(), time() + 9999]);
$site = (int) db()->lastInsertId();
db()->prepare('INSERT INTO index_entries (site_id, url, title, stems, image, seen_at) VALUES (?,?,?,?,?,1)')->execute([$site, 'https://a.co.il/r/9/', 'עוגת שזיפים', ' עוג שזיפ ', 'https://a.co.il/9.jpg']);
$eid = (int) db()->lastInsertId();
$r = recommend(['entry_id' => $eid], [$noa['id'], $dan['id'], $dana['id']], 'נראה טעים!', $dana);
check('נשלחה לשניים (לא לעצמי)', $r, ['sent' => 2, 'already' => 0]);
check('שוב, לפני שראו — לא כפול', recommend(['entry_id' => $eid], [$noa['id']], '', $dana), ['sent' => 0, 'already' => 1]);
$in = recommendationsInbox($noa);
check('אצל נועה: חדשה, ממי, ההערה, ופתיחה דרך החיפוש ברשת', [$in['unseen'], $in['items'][0]['from']['username'], $in['items'][0]['note'], $in['items'][0]['open'], $in['items'][0]['image']],
      [1, 'dana', 'נראה טעים!', '#/web/' . $eid, 'https://a.co.il/9.jpg']);
recommendationSeen($in['items'][0]['id'], $noa);
check('נפתחה — כבר לא חדשה', recommendationsInbox($noa)['unseen'], 0);
check('על המקור של מתכון מיובא (לא באינדקס) — דרך ייבוא מקישור', (function () {
    recommend(['recipe_id' => $GLOBALS['wid']], [$GLOBALS['dan']['id']], '', $GLOBALS['dana']);
    return recommendationsInbox($GLOBALS['dan'])['items'][0]['open'];
})(), '#/import?url=' . rawurlencode('https://a.co.il/r/1/'));
expectError('על מתכון שלא מהרשת — לא (משתפים)', fn() => recommend(['recipe_id' => $GLOBALS['pub']], [$GLOBALS['noa']['id']], '', $GLOBALS['dana']), 'רק על מתכון מהרשת');
expectError('על מתכון פרטי של אחר — לא', fn() => recommend(['recipe_id' => $GLOBALS['wid']], [$GLOBALS['dana']['id']], '', $GLOBALS['dan']), 'אינו קיים');
expectError('אחר לא מוחק המלצה שלא אליו', fn() => recommendationDismiss($GLOBALS['in']['items'][0]['id'], $GLOBALS['dan']), 'אינה קיימת');
recommendationDismiss($in['items'][0]['id'], $noa);
check('נועה מחקה — הרשימה ריקה', recommendationsInbox($noa)['items'], []);

echo "\n5. מחיקה מנקה\n";
deleteRecipe($priv, $dana);
check('מחיקת מתכון מוחקת את השיתופים שלו', (int) db()->query("SELECT COUNT(*) FROM recipe_shares WHERE recipe_id = $priv")->fetchColumn(), 0);

echo "\n";
@array_map('unlink', glob("$tmp/*") ?: []);
if ($fail) { echo "\u{274C} נכשלו " . count($fail) . ': ' . implode(' | ', $fail) . "\n"; exit(1); }
echo "\u{2705} כל הבדיקות עברו\n";
