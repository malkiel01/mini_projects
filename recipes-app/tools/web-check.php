<?php
/**
 * בדיקת web_index.php — חיפוש מתכונים באינדקס משלנו — על מסד זמני, בלי רשת.
 * הרצה: php recipes-app/tools/web-check.php
 *
 * מוכיחה: קריאת sitemap (אינדקס ורשימה), בחירת חלקי המתכונים, שם מהכתובת,
 * חיפוש לפי גזעים בעברית, הרשאות המפתח, פתיחה מהמטמון, מכסה יומית, ושמירה
 * בלי כפילות. ההבאה מהרשת והעדכון השבועי נבדקים ב-api-check מול שרת דוגמה.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/recipes-web-' . getmypid();
@mkdir($tmp, 0775, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('MEDIA_DIR', $tmp . '/media');
define('SECRETS_FILE', $tmp . '/secrets.json');
define('WEB_DAILY_LIMIT', 1);
define('IMPORT_ALLOW_LOCAL', true);   // הוספת אתרים בלי DNS — בלי רשת
@ini_set('sendmail_path', '/bin/true');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/web_index.php';
require_once __DIR__ . '/../lib/rewrite_queue.php';

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

$dev = createUser('malkiel', 'd@x.com', 'sod12345', 'מלכיאל');
$devU = ['id' => $dev['id'], 'role' => 'admin', 'username' => 'malkiel'];
$noa = createUser('noa', 'n@x.com', 'sod12345', 'נועה'); verifyEmail($noa['token']);
$noaU = ['id' => $noa['id'], 'role' => 'user', 'username' => 'noa'];

echo "\n1. קריאת sitemap\n";
// כמו 10dakot: אינדקס עם חלקי מתכונים, פוסטים ודפים
$idx = '<?xml version="1.0"?><sitemapindex>
  <sitemap><loc>https://a.co.il/post-sitemap.xml</loc><lastmod>2026-10-01T00:00:00+00:00</lastmod></sitemap>
  <sitemap><loc>https://a.co.il/page-sitemap.xml</loc></sitemap>
  <sitemap><loc>https://a.co.il/recipe-sitemap1.xml</loc><lastmod>2026-10-08T11:47:54+00:00</lastmod></sitemap>
  <sitemap><loc>https://a.co.il/recipe-sitemap2.xml</loc></sitemap>
</sitemapindex>';
$ch = indexParseSitemapIndex($idx);
check('אינדקס: ארבעה חלקים עם lastmod', [count($ch), $ch[2]], [4, ['https://a.co.il/recipe-sitemap1.xml', '2026-10-08T11:47:54+00:00']]);
check('יש חלקי מתכונים → רק הם', array_column(indexPickSitemaps($ch), 0), ['https://a.co.il/recipe-sitemap1.xml', 'https://a.co.il/recipe-sitemap2.xml']);
// כמו carine (wp-sitemap): foody_recipe
$wp = [['https://c.co.il/wp-sitemap-posts-post-1.xml', null], ['https://c.co.il/wp-sitemap-posts-foody_recipe-1.xml', null],
       ['https://c.co.il/wp-sitemap-posts-foody_ingredient-1.xml', null], ['https://c.co.il/wp-sitemap-taxonomies-category-1.xml', null]];
check('WordPress: foody_recipe בלבד', array_column(indexPickSitemaps($wp), 0), ['https://c.co.il/wp-sitemap-posts-foody_recipe-1.xml']);
$blog = [['https://b.co.il/post-sitemap.xml', null], ['https://b.co.il/page-sitemap.xml', null], ['https://b.co.il/category-sitemap.xml', null], ['https://b.co.il/author-sitemap.xml', null]];
check('בלוג בלי "recipe": פוסטים, לא דפים/קטגוריות/כותבים', array_column(indexPickSitemaps($blog), 0), ['https://b.co.il/post-sitemap.xml']);
check('urlset אינו אינדקס', indexParseSitemapIndex('<urlset><url><loc>x</loc></url></urlset>'), null);
$urls = indexParseUrlset('<urlset><url><loc>https://a.co.il/recipe/%d7%a2%d7%95%d7%92%d7%aa-%d7%92%d7%91%d7%99%d7%a0%d7%94/</loc><lastmod>2026-09-28T11:19:45+00:00</lastmod><image:image><image:loc>https://a.co.il/x.jpg</image:loc></image:image></url><url><loc>https://a.co.il/recipe/b/</loc></url></urlset>');
check('urlset: כתובת ו-lastmod (תמונה לא מתבלבלת עם loc)', [count($urls), $urls[0][1], $urls[1][1]], [2, '2026-09-28T11:19:45+00:00', null]);
check('urlset: תמונה מה-sitemap, ובלי תמונה — null', [$urls[0][2], $urls[1][2]], ['https://a.co.il/x.jpg', null]);
$u = fn($i, $imgs) => "<url><loc>https://a.co.il/r/$i/</loc>" . implode('', array_map(fn($x) => "<image:image><image:loc><![CDATA[ $x ]]></image:loc></image:image>", $imgs)) . '</url>';
$urls = indexParseUrlset('<urlset>' . $u(1, ['https://a.co.il/note.png', 'https://a.co.il/1.jpg']) . $u(2, ['https://a.co.il/note.png', 'https://a.co.il/2.jpg'])
                         . $u(3, ['https://a.co.il/note.png']) . $u(4, ['javascript:alert(1)']) . '</urlset>');
check('תמונה משותפת לכמה דפים (אייקון) מדולגת; CDATA; לא-http נדחה', array_column($urls, 2), ['https://a.co.il/1.jpg', 'https://a.co.il/2.jpg', null, null]);

check('אותו אתר עם www ובלי', [indexSameHost('https://gad-dairy.co.il/recipe/x/', 'www.gad-dairy.co.il'), indexSameHost('https://www.a.co.il/x', 'a.co.il'),
                               indexSameHost('https://foody.co.il/x', 'www.carine.co.il')], [true, true, false]);

echo "\n2. שם מהכתובת, וגזעים\n";
check('סלאג עברי', indexTitleFromUrl('https://a.co.il/recipe/%d7%a2%d7%95%d7%92%d7%aa-%d7%92%d7%91%d7%99%d7%a0%d7%94-%d7%90%d7%a4%d7%95%d7%99%d7%94-2/'), 'עוגת גבינה אפויה');
check('__trashed ומספרים — בלי שם', [indexTitleFromUrl('https://c.co.il/foody_recipe/__trashed-3/'), indexTitleFromUrl('https://c.co.il/r/12345/')], ['', '']);
check('גזעים: עוגות→עוג, גבינה→גבינ', indexStems('עוגות גבינה'), ' עוג גבינ ');

echo "\n2ב. שמות מהדף — למתכונים בלי שם עברי בכתובת\n";
check('עברית?', [indexHasHebrew('עוגה'), indexHasHebrew('apple dessert'), indexHasHebrew('')], [true, false, false]);
check('סיומת האתר נחתכת, גם כשהאתר קורא לעצמו אחרת', indexCleanTitle('עוגת קפה, קוקוס ושוקולד - עוגיו.נט', ['אוגיו'])[0], 'עוגת קפה, קוקוס ושוקולד');
$ct = indexCleanTitle('לבבות עוף עם בצל - הרוטב מעלף הכי מתאים ליד חלת השבת', ['ניקי ב']);
check('זנב לגוגל: להצגה — החלק הראשון; לחיפוש — הכול', $ct, ['לבבות עוף עם בצל', 'לבבות עוף עם בצל הרוטב מעלף הכי מתאים ליד חלת השבת']);
check('"מתכון" באמצע פותח זנב', indexCleanTitle('פשטידת פטריות חלבית מתכון פגז לאירוח', [])[0], 'פשטידת פטריות חלבית');
check('שם של מילה אחת לפני מפריד — שני החלקים', indexCleanTitle('שוקופאי - אפוי', [])[0], 'שוקופאי אפוי');
check('אימוג׳י וסימני קריאה', indexCleanTitle('מאפה גבינות מלוח! ✨', [])[0], 'מאפה גבינות מלוח');
check('| שם האתר', indexCleanTitle('עוגת שוקולד | ניקי ב', ['ניקי ב'])[0], 'עוגת שוקולד');
check('שם שמכיל את המוציא לאור אינו "שם האתר" (באג שנמצא)', indexCleanTitle('קרין גורן מכינה עוגת יומולדת שילדים אוהבים', ['אתר', 'קרין גורן'])[0], 'קרין גורן מכינה עוגת יומולדת שילדים אוהבים');

echo "\n2ג. רשימת אתרים — כתובת ושם בכל שורה\n";
check('כתובת ואחריה שם', indexParseSiteLine('www.10dakot.co.il 10 דקות'), ['www.10dakot.co.il', '10 דקות']);
check('שם ואחריו כתובת, עם מפריד', indexParseSiteLine('קרין גורן — https://www.carine.co.il/'), ['https://www.carine.co.il/', 'קרין גורן']);
check('עם | ', indexParseSiteLine('כיפה — אוכל | www.kipa.co.il'), ['www.kipa.co.il', 'כיפה — אוכל']);
check('כתובת לבד', indexParseSiteLine('  www.gad-dairy.co.il  '), ['www.gad-dairy.co.il', '']);
check('שם עם נקודה בעברית אינו כתובת', indexParseSiteLine('www.oogio.net עוגיו.נט'), ['www.oogio.net', 'עוגיו.נט']);
check('שורה ריקה / הערה', [indexParseSiteLine('   '), indexParseSiteLine('# אתרים')], [null, null]);
check('בלי כתובת', indexParseSiteLine('סתם שם'), ['', 'סתם שם']);
check('כתובת IP עם פורט', indexParseSiteLine('http://127.0.0.1:8792/ אתר מקומי'), ['http://127.0.0.1:8792/', 'אתר מקומי']);
$bulk = indexAddSites("www.bulk-a.co.il אתר א׳\nאתר ב׳ www.bulk-b.co.il\n\nסתם שורה\nwww.bulk-a.co.il", $devU);
check('הוספה מרובה: שניים נוספו, שגיאה לשורה בלי כתובת, כפילות', array_column($bulk['results'], 'status'), ['added', 'added', 'error', 'exists']);
check('השמות בעברית נשמרו', array_column(array_filter($bulk['sites'], fn($x) => str_starts_with($x['host'], 'www.bulk-')), 'name'), ['אתר א׳', 'אתר ב׳']);
$bulk = indexAddSites('bulk-a.co.il אתר אלף', $devU);
check('אתר קיים (גם בלי www) עם שם חדש — השם מתעדכן', [$bulk['results'][0]['status'], array_values(array_filter($bulk['sites'], fn($x) => $x['host'] === 'www.bulk-a.co.il'))[0]['name']], ['renamed', 'אתר אלף']);
expectError('משתמש רגיל אינו מוסיף', fn() => indexAddSites('www.x.co.il', $GLOBALS['noaU']), 'מפתח');
expectError('רשימה ריקה', fn() => indexAddSites("\n  \n", $GLOBALS['devU']), 'ריקה');
db()->exec("DELETE FROM index_sites WHERE host LIKE 'www.bulk-%'");

echo "\n3. הרשאות — רק המפתח מנהל את האינדקס\n";
expectError('משתמש רגיל אינו מוסיף אתר', fn() => indexAddSite('https://a.co.il/', '', $GLOBALS['noaU']), 'מפתח');
expectError('ואינו רואה את הרשימה', fn() => indexSitesStatus($GLOBALS['noaU']), 'מפתח');
expectError('כתובת לא חוקית', fn() => indexAddSite('ftp://x', '', $GLOBALS['devU']), 'http');

echo "\n4. חיפוש\n";
$pdo = db();
$pdo->prepare('INSERT INTO index_sites (host, name, start_url, added_at, next_crawl_at) VALUES (?,?,?,?,?)')->execute(['a.co.il', 'אתר א', 'https://a.co.il/', nowIso(), time() + 9999]);
$sa = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO index_sites (host, name, start_url, added_at, next_crawl_at, enabled) VALUES (?,?,?,?,?,0)')->execute(['off.co.il', 'כבוי', 'https://off.co.il/', nowIso(), time() + 9999]);
$so = (int) $pdo->lastInsertId();
$ins = $pdo->prepare('INSERT INTO index_entries (site_id, url, title, stems, seen_at) VALUES (?,?,?,?,1)');
foreach ([[$sa, 'https://a.co.il/r/1/', 'עוגת גבינה אפויה'], [$sa, 'https://a.co.il/r/2/', 'עוגות גבינה קרות עם פירורים'],
          [$sa, 'https://a.co.il/r/3/', 'עוגת שוקולד'], [$sa, 'https://a.co.il/r/4/', 'גבינה'],
          [$so, 'https://off.co.il/r/9/', 'עוגת גבינה מאתר כבוי']] as [$s, $u, $t]) $ins->execute([$s, $u, $t, indexStems($t)]);
$r = indexSearch('עוגות גבינה');
check('"עוגות גבינה" מוצא את שתי עוגות הגבינה (הביטוי המדויק ראשון), לא שוקולד, לא אתר כבוי', [$r['total'], array_column($r['results'], 'title')],
      [2, ['עוגות גבינה קרות עם פירורים', 'עוגת גבינה אפויה']]);
check('בלי ביטוי מדויק — הקצר ראשון', array_column(indexSearch('גבינה')['results'], 'title'), ['גבינה', 'עוגת גבינה אפויה', 'עוגות גבינה קרות עם פירורים']);
check('מילים בלי משמעות ("מתכון של") לא מצמצמות', indexSearch('מתכון של עוגת שוקולד')['total'], 1);
check('ספירות האינדקס: 4 מתכונים באתר אחד פעיל', [$r['indexed'], $r['sites']], [4, 1]);
check('חיפוש ריק — אפס', indexSearch('  ')['total'], 0);
check('% בחיפוש אינו תו כללי', indexSearch('%')['total'], 0);
$ins->execute([$sa, 'https://a.co.il/r/41767/', '', ' ']);
$pdo->exec("UPDATE index_entries SET needs_title = 1 WHERE url = 'https://a.co.il/r/41767/'");
$ins->execute([$sa, 'https://a.co.il/apple_dessert/', 'apple dessert', indexStems('apple dessert')]);
$pdo->exec("UPDATE index_entries SET needs_title = 1 WHERE url = 'https://a.co.il/apple_dessert/'");
$ins->execute([$sa, 'https://a.co.il/guide/', 'עוגות מדריך', indexStems('עוגות מדריך')]);
$pdo->exec("UPDATE index_entries SET skip = 1 WHERE url = 'https://a.co.il/guide/'");
check('ממתין לשם בלי שם — לא בחיפוש; עם שם אנגלי — נמצא באנגלית', [indexSearch('apple')['total'], indexSearch('עוגות')['total']], [1, 3]);
check('"לא מתכון" — לא בחיפוש ולא בספירה', indexSearch('מדריך')['total'], 0);
$sites = indexSites();
check('ספירות לאתר: 4 בחיפוש, 2 ממתינים לשם, 1 לא מתכון', [$sites[0]['entries_n'], $sites[0]['titles_pending'], $sites[0]['not_recipes']], [4, 2, 1]);
check('ממתינים לתמונה: רק כאלה שיש להם שם ולא נבדקו (לא "לא מתכון")', $sites[0]['images_pending'],
      (int) $pdo->query("SELECT COUNT(*) FROM index_entries WHERE site_id = $sa AND skip = 0 AND needs_title = 0 AND image IS NULL")->fetchColumn());
check('og:image מהדף — יחסי הופך למלא; content לפני property; secure_url קודם', [
    indexPageImage('<head><meta property="og:image" content="/wp/1.jpg"></head>', 'https://a.co.il/r/1/'),
    indexPageImage('<meta content="https://a.co.il/2.jpg" property="og:image" />', 'https://a.co.il/'),
    indexPageImage('<meta property="og:image" content="http://a.co.il/3.jpg"><meta property="og:image:secure_url" content="https://a.co.il/3.jpg">', 'https://a.co.il/'),
    indexPageImage('<meta name="twitter:image" content="https://a.co.il/4.jpg">', 'https://a.co.il/'),
    indexPageImage('<p>אין תמונה</p>', 'https://a.co.il/')],
    ['https://a.co.il/wp/1.jpg', 'https://a.co.il/2.jpg', 'https://a.co.il/3.jpg', 'https://a.co.il/4.jpg', null]);
importMarkHostBlocked('a.co.il');
check('אתר חסום — העובד ממתין, לא מביא', str_starts_with(indexTitleStep(), 'wait:'), true);
check('ושומר-הסף לא מעיר בשבילו', indexWorkReady(), false);
@unlink(importStateDir('fetch-blocks') . '/' . md5('a.co.il'));
$pdo->exec("DELETE FROM index_entries WHERE url IN ('https://a.co.il/r/41767/', 'https://a.co.il/apple_dessert/', 'https://a.co.il/guide/')");

echo "\n5. פתיחה מהמטמון, מכסה יומית, שמירה בלי כפילות\n";
$fx = fn(string $f) => file_get_contents(__DIR__ . '/fixtures/' . $f);
$draft = importToDraft(importParse($fx('jsonld-10dakot.html'), 'https://a.co.il/r/1/'));
$draft['source_rewritten'] = true;
$e1 = (int) $pdo->query("SELECT id FROM index_entries WHERE url = 'https://a.co.il/r/1/'")->fetchColumn();
$pdo->prepare('INSERT INTO web_cache (url, draft, rewritten, created_at) VALUES (?,?,1,?)')->execute(['https://a.co.il/r/1/', json_encode($draft, JSON_UNESCAPED_UNICODE), time()]);
$o = webOpen($e1, $noaU);
check('מהמטמון: בלי רשת, מנוסח, עם המקור', [$o['cached'], $o['rewritten'], $o['entry']['site'], $o['draft']['title']], [true, true, 'אתר א', 'עוגת גבינה של בית מלון']);
check('מכסה: לנועה נשארה 1 (אין פתיחות חדשות היום)', $o['opens_left'], 1);
importLogWrite(['kind' => 'web-open', 'source_url' => 'https://a.co.il/r/3/', 'user' => $noaU, 'ok' => true]);
$e3 = (int) $pdo->query("SELECT id FROM index_entries WHERE url = 'https://a.co.il/r/3/'")->fetchColumn();
expectError('המכסה נגמרה — פתיחה חדשה נחסמת', fn() => webOpen($GLOBALS['e3'], $GLOBALS['noaU']), 'מכסת');
check('אבל מהמטמון — עדיין נפתח', webOpen($e1, $noaU)['cached'], true);
check('למפתח אין מכסה', webOpensLeft($devU), null);
$pdo->prepare('INSERT INTO web_cache (url, draft, rewritten, created_at) VALUES (?,?,0,?)')->execute(['https://a.co.il/r/3/', json_encode($draft, JSON_UNESCAPED_UNICODE), time() - 7200]);
$o3 = webOpen($e3, $noaU);
check('לא מנוסח וישן משעה — מהמטמון, בלי להביא שוב מהאתר (ובלי בינה — בלי ניסיון ניסוח)', [$o3['cached'], $o3['rewritten']], [true, false]);
$s1 = webSave($e1, $noaU);
$rec = loadRecipe($s1['recipe_id'], $noaU);
check('נשמר: פרטי, עם קרדיט, מנוסח, עם הרכיבים', [$rec['visibility'], $rec['source']['url'], $rec['source']['rewritten'], count($rec['sections'][0]['ingredients']) > 3],
      ['private', 'https://a.co.il/r/1/', true, true]);
check('שמירה שנייה — אותו מתכון, בלי כפילות', webSave($e1, $noaU), ['recipe_id' => $s1['recipe_id'], 'existing' => true]);
check('נרשם ביומן הייבוא כשמירה מהחיפוש', importLogList(['kind' => 'web-save'])[0]['recipe_id'], $s1['recipe_id']);
expectError('מתכון מאתר כבוי אינו נפתח', fn() => webOpen((int) $GLOBALS['pdo']->query("SELECT id FROM index_entries WHERE site_id = {$GLOBALS['so']}")->fetchColumn(), $GLOBALS['noaU']), 'אינו באינדקס');

echo "\n5ב. ניסוח ידני — תור, ייצוא, החלה\n";
// במטמון: מתכון שנפתח ולא נוסח (נגמר הקרדיט)
$plain = importToDraft(importParse($fx('jsonld-carine.html'), 'https://a.co.il/r/4/'));
$plain['source_rewritten'] = false;
$pdo->prepare('INSERT INTO web_cache (url, draft, rewritten, created_at) VALUES (?,?,0,?)')->execute(['https://a.co.il/r/4/', json_encode($plain, JSON_UNESCAPED_UNICODE), time()]);
// ומתכון של המפתח שיובא בלי ניסוח
$devRid = saveRecipe($plain, $devU);
expectError('משתמש רגיל אינו רואה את התור', fn() => rewriteExport($GLOBALS['noaU']), 'מפתח');
$ex = rewriteExport($devU);
$ids = array_column($ex['items'], 'id');
// במטמון שניים לא מנוסחים: r/3 (מהבדיקה הקודמת) ו-r/4; r/1 מנוסח — לא בתור
check('בתור: מהמטמון ומהמתכון של המפתח (ולא מתכון מנוסח, ולא של נועה)', [count(array_filter($ids, fn($i) => str_starts_with($i, 'w:'))), in_array('r:' . $devRid, $ids, true), $ex['format']], [2, true, 'recipes-rewrite-1']);
$w = array_values(array_filter($ex['items'], fn($i) => $i['source'] === 'https://a.co.il/r/4/'))[0];
check('השלבים כלשונם, בלי שאר הטיוטה', [count($w['sections'][0]['steps']) >= 4, isset($w['sections'][0]['ingredients'])], [true, false]);

$same = $ex; $same['items'] = [$w];
check('החזרה בלי שינוי — "קרוב מדי", לא מוחל', rewriteApply($same, $devU)['results'][0]['status'], 'too_close');
$bad = $ex; $bad['items'] = [['id' => $w['id'], 'sections' => [['name' => '', 'steps' => ['א']], ['name' => 'ב', 'steps' => ['ב']]]]];
check('מספר חלקים אחר — נדחה', rewriteApply($bad, $devU)['results'][0]['status'], 'mismatch');
$new = $ex;
$new['items'] = array_map(fn($it) => ['id' => $it['id'], 'title' => $it['title'], 'sections' => array_map(fn($sec) => ['name' => $sec['name'],
    'steps' => array_map(fn($k) => "בשלב $k עושים את מה שצריך, בניסוח שלנו לגמרי", array_keys($sec['steps']))], $it['sections'])], $ex['items']);
$new['items'][] = ['id' => 'w:999999', 'sections' => [['name' => '', 'steps' => ['x']]]];
$ap = rewriteApply($new, $devU);
check('הוחל על שלושתם; פריט שלא קיים — מסומן', [$ap['applied'], end($ap['results'])['status']], [3, 'missing']);
$c = json_decode($pdo->query("SELECT draft FROM web_cache WHERE url = 'https://a.co.il/r/4/'")->fetchColumn(), true);
check('במטמון: מנוסח, והרכיבים לא נגעו', [(int) $pdo->query("SELECT rewritten FROM web_cache WHERE url = 'https://a.co.il/r/4/'")->fetchColumn(), $c['source_rewritten'], str_starts_with($c['sections'][0]['steps'][0]['text'], 'בשלב 0'), count($c['sections'][0]['ingredients'])],
      [1, true, true, count($plain['sections'][0]['ingredients'])]);
$rr = loadRecipe($devRid, $devU);
check('המתכון: מנוסח, "מבוסס על", הרכיבים והמקור נשארו', [$rr['source']['rewritten'], str_starts_with($rr['sections'][0]['steps'][0]['text'], 'בשלב 0'), count($rr['sections'][0]['ingredients']), $rr['source']['url']],
      [true, true, count($plain['sections'][0]['ingredients']), 'https://a.co.il/r/4/']);
check('התור התרוקן', rewriteQueue($devU)['total'], 0);
expectError('קובץ לא שלנו', fn() => rewriteApply(['items' => []], $GLOBALS['devU']), 'format');

echo "\n5ג. סדר התוצאות: מהאפליקציה, הורדות, פתיחות, התאמה\n";
$ins->execute([$sa, 'https://a.co.il/q/1/', 'פשטידת תרד', indexStems('פשטידת תרד')]);
$ins->execute([$sa, 'https://a.co.il/q/2/', 'פשטידת תרד וגבינה', indexStems('פשטידת תרד וגבינה')]);
$ins->execute([$sa, 'https://a.co.il/q/3/', 'פשטידת תרד מהירה', indexStems('פשטידת תרד מהירה')]);
$ins->execute([$sa, 'https://a.co.il/q/4/', 'פשטידת תרד של שבת', indexStems('פשטידת תרד של שבת')]);
$order = fn(?array $u = null) => array_column(indexSearch('פשטידת תרד', 0, $u)['results'], 'url');
check('בלי אותות — הקצר ראשון', $order()[0], 'https://a.co.il/q/1/');
$pdo->exec("UPDATE index_entries SET opens = 3 WHERE url = 'https://a.co.il/q/3/'");
check('נפתח אצלנו — עולה מעל התאמה', $order()[0], 'https://a.co.il/q/3/');
$sec = [['ingredients' => [['free_text' => 'תרד']], 'steps' => [['text' => 'לאפות']]]];
saveRecipe(['title' => 'הפשטידה של נועה', 'sections' => $sec, 'source_url' => 'https://a.co.il/q/2/'], $noaU);
check('הורדה (נשמר אצל משתמש) — מעל פתיחות', $order(), ['https://a.co.il/q/2/', 'https://a.co.il/q/3/', 'https://a.co.il/q/1/', 'https://a.co.il/q/4/']);
check('מספר המורידים בתוצאה', indexSearch('פשטידת תרד')['results'][0]['saves'], 1);
saveRecipe(['title' => 'פשטידת תרד של סבתא', 'visibility' => 'public', 'sections' => $sec, 'source_url' => 'https://a.co.il/q/4/'], $devU);
saveRecipe(['title' => 'פשטידת תרד פרטית של נועה', 'sections' => $sec], $noaU);
$r = indexSearch('פשטידת תרד', 0, $noaU);
check('מהאפליקציה קודם: הציבורי של אחר והפרטי שלי (לא פרטי של אחר)', (function ($t) { sort($t); return $t; })(array_column($r['local'], 'title')), ['פשטידת תרד פרטית של נועה', 'פשטידת תרד של סבתא']);
check('דף שכבר מוצג מהאפליקציה — לא שוב ברשת', [in_array('https://a.co.il/q/4/', array_column($r['results'], 'url')), $r['total']], [false, 3]);
check('עמוד שני — בלי "מהאפליקציה"', indexSearch('פשטידת תרד', 30, $noaU)['local'], []);

echo "\n6. הסרת אתר מוחקת את המתכונים שלו מהאינדקס\n";
indexRemoveSite($sa, $devU);
check('האינדקס ריק מאתר א', (int) $pdo->query("SELECT COUNT(*) FROM index_entries WHERE site_id = $sa")->fetchColumn(), 0);
check('המתכון שנשמר אצל נועה נשאר', loadRecipe($s1['recipe_id'], $noaU)['title'], 'עוגת גבינה של בית מלון');

foreach (['scout', 'fetch-blocks', 'fetch-cache'] as $d) { foreach (glob("$tmp/$d/*") ?: [] as $f) @unlink($f); @rmdir("$tmp/$d"); }
foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
@rmdir($tmp . '/media'); @rmdir($tmp);
echo "\n";
if ($fail) { echo "\u{274C} נכשלו " . count($fail) . ": " . implode(', ', $fail) . "\n"; exit(1); }
echo "\u{2705} כל הבדיקות עברו\n";
