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
@ini_set('sendmail_path', '/bin/true');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/web_index.php';

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

echo "\n2. שם מהכתובת, וגזעים\n";
check('סלאג עברי', indexTitleFromUrl('https://a.co.il/recipe/%d7%a2%d7%95%d7%92%d7%aa-%d7%92%d7%91%d7%99%d7%a0%d7%94-%d7%90%d7%a4%d7%95%d7%99%d7%94-2/'), 'עוגת גבינה אפויה');
check('__trashed ומספרים — בלי שם', [indexTitleFromUrl('https://c.co.il/foody_recipe/__trashed-3/'), indexTitleFromUrl('https://c.co.il/r/12345/')], ['', '']);
check('גזעים: עוגות→עוג, גבינה→גבינ', indexStems('עוגות גבינה'), ' עוג גבינ ');

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
$s1 = webSave($e1, $noaU);
$rec = loadRecipe($s1['recipe_id'], $noaU);
check('נשמר: פרטי, עם קרדיט, מנוסח, עם הרכיבים', [$rec['visibility'], $rec['source']['url'], $rec['source']['rewritten'], count($rec['sections'][0]['ingredients']) > 3],
      ['private', 'https://a.co.il/r/1/', true, true]);
check('שמירה שנייה — אותו מתכון, בלי כפילות', webSave($e1, $noaU), ['recipe_id' => $s1['recipe_id'], 'existing' => true]);
check('נרשם ביומן הייבוא כשמירה מהחיפוש', importLogList(['kind' => 'web-save'])[0]['recipe_id'], $s1['recipe_id']);
expectError('מתכון מאתר כבוי אינו נפתח', fn() => webOpen((int) $GLOBALS['pdo']->query("SELECT id FROM index_entries WHERE site_id = {$GLOBALS['so']}")->fetchColumn(), $GLOBALS['noaU']), 'אינו באינדקס');

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
