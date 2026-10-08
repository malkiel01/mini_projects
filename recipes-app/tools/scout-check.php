<?php
/**
 * בדיקת scout.php — סורק האתרים — בלי רשת.
 * הרצה: php recipes-app/tools/scout-check.php
 *
 * מוכיחה: פרסר robots.txt, זיהוי קישורי מתכון, חילוץ מועמדים מדף רשימה
 * ומ-sitemap (כולל סינון דומיין זר וכפילויות), והרשימה במסד: סימון,
 * ספירה, מחיקה, והרשאה למפתח בלבד. המסלול עם HTTP — ב-api-check.sh.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/recipes-scout-' . getmypid();
@mkdir($tmp, 0775, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('MEDIA_DIR', $tmp . '/media');
define('SECRETS_FILE', $tmp . '/secrets.json');
@ini_set('sendmail_path', '/bin/true');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/scout_job.php';

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
$fx = fn(string $n) => file_get_contents(__DIR__ . '/fixtures/' . $n);

$dev  = createUser('malkiel', 'd@x.com', 'sod12345', 'מלכיאל');
$devU = ['id' => $dev['id'], 'role' => 'admin', 'username' => 'malkiel'];
$mali = createUser('mali', 'm@x.com', 'sod12345', 'מלי'); verifyEmail($mali['token']);
$maliU = ['id' => $mali['id'], 'role' => 'user'];

echo "\n1. robots.txt\n";
$robots = "User-agent: *\nDisallow: /private/\nDisallow: /norecipe.html\nAllow: /private/open/\n\nUser-agent: recipes-app\nDisallow: /only-for-us/\n";
check('נתיב פתוח', scoutRobotsCheck($robots, '/recipe/x/'), true);
check('נתיב אסור', scoutRobotsCheck("User-agent: *\nDisallow: /private/\n", '/private/x'), false);
check('Allow ספציפי יותר מנצח', scoutRobotsCheck("User-agent: *\nDisallow: /private/\nAllow: /private/open/\n", '/private/open/x'), true);
check('קבוצת recipes-app גוברת על *', scoutRobotsCheck($robots, '/only-for-us/x'), false);
check('ו-* לא חל כשיש קבוצה שלנו', scoutRobotsCheck($robots, '/private/x'), true);
check('Disallow ריק = הכול מותר', scoutRobotsCheck("User-agent: *\nDisallow:\n", '/anything'), true);
check('בלי robots → מותר', scoutRobotsCheck('', '/x'), true);
check('כוכבית בתבנית', scoutRobotsCheck("User-agent: *\nDisallow: /*?print=\n", '/recipe/x?print=1'), false);
check('סיומת $', scoutRobotsCheck("User-agent: *\nDisallow: /*.pdf$\n", '/a.pdf'), false);
check('סיומת $ לא תופסת המשך', scoutRobotsCheck("User-agent: *\nDisallow: /*.pdf$\n", '/a.pdfx'), true);
check('הערות מתעלמים', scoutRobotsCheck("# hi\nUser-agent: * # all\nDisallow: /x # no\n", '/x'), false);

echo "\n2. מה נראה כמו מתכון\n";
check('/recipe/ בנתיב', scoutLooksLikeRecipe('https://a.co.il/recipe/cake/', ''), true);
check('/foody_recipe/', scoutLooksLikeRecipe('https://www.carine.co.il/foody_recipe/x/', ''), true);
check('/מתכון/ מקודד', scoutLooksLikeRecipe('https://a.co.il/%D7%9E%D7%AA%D7%9B%D7%95%D7%9F/x/', ''), true);
check('קטגוריה — לא', scoutLooksLikeRecipe('https://a.co.il/category/cakes/', 'עוגות'), false);
check('טקסט קישור של מנה', scoutLooksLikeRecipe('https://a.co.il/x-y-z/', 'עוגת גבינה של סבתא'), true);
check('טקסט ניווט — לא', scoutLooksLikeRecipe('https://a.co.il/x/', 'צור קשר'), false);

echo "\n3. מועמדים מדף רשימה\n";
$c = scoutExtractCandidates($fx('listing.html'), 'https://example.co.il/category/x/');
check('שישה: לפי נתיב, לפי alt של תמונה, לפי טקסט, ושני סרטונים', count($c), 6);
$byUrl = array_column($c, 'title', 'url');
check('"8:44" על התמונה אינו שם — הקישור השני באותו דף נותן את השם', $byUrl['https://example.co.il/foody_recipe/%d7%a9%d7%95%d7%a7%d7%95%d7%a4%d7%90%d7%99/'] ?? null, 'שוקופאי אפוי');
check('"12:11" בלי שם אחר → השם מהכתובת, בעברית', $byUrl['https://example.co.il/foody_recipe/%d7%91%d7%a8%d7%90%d7%95%d7%a0%d7%99%d7%96/'] ?? null, 'בראוניז');
check('scoutGoodTitle', [scoutGoodTitle('8:44'), scoutGoodTitle('1:02:33'), scoutGoodTitle(''), scoutGoodTitle('עוגה'), scoutGoodTitle('x')], [false, false, false, true, false]);
check('כתובות יחסיות → מוחלטות, אותו דומיין', $c[0]['url'], 'https://example.co.il/jsonld-10dakot.html');
check('שם מתוך alt של תמונה', $c[1]['title'], 'עוגת יומולדת שילדים אוהבים');
check('#comments אינו כפילות', count(array_filter($c, fn($x) => str_contains($x['url'], 'soup-1'))), 1);
check('אתר אחר לא נכנס', count(array_filter($c, fn($x) => str_contains($x['url'], 'other.example'))), 0);
check('ניווט לא נכנס', count(array_filter($c, fn($x) => str_contains($x['url'], '/contact') || str_contains($x['url'], '/page/'))), 0);

echo "\n3ב. לכלוך שנראה כמו קישור — תבניות JS, whatsapp מקונן, ודף הרשימה עצמו\n";
$dirty = '<html><body>
  <a href="/foody_recipe/\' + product_url + \'">\' + title + \'</a>
  <a href="/foody_recipe/whatsapp://send?text=עוגת שוקולד https://x">send</a>
  <a href="/foody_recipe/">foody recipe</a>
  <a href="https://www.carine.co.il/foody_recipe/">foody recipe</a>
  <a href="/foody_recipe/%d7%a2%d7%95%d7%92%d7%aa-%d7%a9%d7%a7%d7%93%d7%99%d7%9d/">עוגת שקדים נימוחה</a>
  <a href="/foody_recipe/{{slug}}/">{{title}}</a>
</body></html>';
$c = scoutExtractCandidates($dirty, 'https://www.carine.co.il/foody_recipe/');
check('רק המתכון האמיתי נשאר', array_column($c, 'title'), ['עוגת שקדים נימוחה']);
check('scoutCleanUrl', [scoutCleanUrl("https://a.co.il/x/' + u + '"), scoutCleanUrl('https://a.co.il/r/whatsapp://send?text=x'),
                        scoutCleanUrl('https://a.co.il/r/{{slug}}/'), scoutCleanUrl('https://a.co.il/r/%d7%a2/?x=1')], [false, false, false, true]);
check('ספריית המתכונים לבדה אינה מתכון', [scoutLooksLikeRecipe('https://a.co.il/recipes/', ''), scoutLooksLikeRecipe('https://a.co.il/recipes/x', '')], [false, true]);

echo "\n4. מועמדים מ-sitemap\n";
$c = scoutExtractCandidates($fx('sitemap.xml'), 'http://127.0.0.1/sitemap.xml');
check('שלוש כתובות', count($c), 3);
check('שם מהסלאג (בלי .html)', $c[0]['title'], 'jsonld 10dakot');
$idx = scoutExtractCandidates('<?xml version="1.0"?><sitemapindex><sitemap><loc>http://127.0.0.1/recipe-sitemap.xml</loc></sitemap></sitemapindex>', 'http://127.0.0.1/sitemap_index.xml');
check('אינדקס: ה-sitemaps עצמם כמועמדים, מסומנים', $idx[0]['title'], '(sitemap) recipe-sitemap.xml');

echo "\n5. הרשימה במסד\n";
expectError('משתמש רגיל אינו רואה', fn() => scoutList([], $GLOBALS['maliU']), 'מפתח');
expectError('ואינו מסמן', fn() => scoutMark([1], 'wanted', $GLOBALS['maliU']), 'מפתח');
$pdo = db();
$ins = $pdo->prepare("INSERT INTO scout_items (url, title, site, found_on, status, created_at) VALUES (?,?,?,?,'new',?)");
foreach ([['https://a.co.il/recipe/1/', 'אחד'], ['https://a.co.il/recipe/2/', 'שניים'], ['https://b.co.il/recipe/3/', 'שלושה']] as [$u, $t]) $ins->execute([$u, $t, parse_url($u, PHP_URL_HOST), 'x', nowIso()]);
$l = scoutList([], $devU);
check('שלושה פריטים, שני אתרים', [count($l['items']), $l['sites']], [3, ['a.co.il', 'b.co.il']]);
$ids = array_column($l['items'], 'id');
scoutMark([$ids[0], $ids[1]], 'wanted', $devU);
scoutMark([$ids[2]], 'skipped', $devU);
$l = scoutList([], $devU);
check('ספירות', [$l['counts']['wanted'] ?? 0, $l['counts']['skipped'] ?? 0, $l['counts']['new'] ?? 0], [2, 1, 0]);
check('סינון לפי מצב', count(scoutList(['status' => 'wanted'], $devU)['items']), 2);
check('סינון לפי אתר', scoutList(['site' => 'b.co.il'], $devU)['items'][0]['title'], 'שלושה');
check('חיפוש', count(scoutList(['q' => 'שני'], $devU)['items']), 1);
expectError('סימון לא מוכר', fn() => scoutMark([1], 'imported', $GLOBALS['devU']), 'לא מוכר');
$pdo->exec("UPDATE scout_items SET status='imported', recipe_id=NULL WHERE id = {$ids[0]}");
scoutMark([$ids[0]], 'skipped', $devU);
check('"יובא" אינו משתנה בסימון', scoutList(['status' => 'imported'], $devU)['items'][0]['id'], $ids[0]);
scoutRemove([$ids[2]], $devU);
check('מחיקה', count(scoutList([], $devU)['items']), 2);
expectError('ייבוא של פריט שאינו קיים', fn() => scoutImportOne(9999, $GLOBALS['devU'], false), 'אינו קיים');

echo "\n6. אתר שחסם — הפריט נשאר \"לייבוא\", לא \"שגיאה\"\n";
$pdo->prepare("INSERT INTO scout_items (url, title, site, found_on, status, created_at) VALUES (?,?,?,?,'wanted',?)")
    ->execute(['https://waf.example/recipe/x/', 'עוגה', 'waf.example', 'x', nowIso()]);
$wid = (int) $pdo->lastInsertId();
importMarkHostBlocked('waf.example');
expectError('הייבוא נעצר בחסימה', fn() => scoutImportOne($GLOBALS['wid'], $GLOBALS['devU'], false), 'חסם זמנית');
$w = $pdo->query("SELECT status, error FROM scout_items WHERE id = $wid")->fetch();
check('עדיין "לייבוא", עם ההסבר', [$w['status'], str_contains((string) $w['error'], 'חסם זמנית')], ['wanted', true]);

echo "\n7. ייבוא ברקע — מצבי העובד (בלי רשת: האתר היחיד ברשימה חסום)\n";
scoutMark([$ids[1]], 'skipped', $devU);   // a.co.il אמיתי — לא להביא ממנו בבדיקה
expectError('משתמש רגיל אינו מפעיל', fn() => scoutJobStart($GLOBALS['maliU'], 25, false), 'מפתח');
check('בהתחלה: idle, מפתח עובד קבוע', [scoutJobStatus($devU)['state'], strlen(scoutJobRow()['worker_key'])], ['idle', 40]);
check('צעד כשאין ריצה — idle', scoutJobTick(), 'idle');
$key = scoutJobRow()['worker_key'];
$st = scoutJobStart($devU, 5, false);
check('הופעל: running, מרווח לפחות 10, נשאר 1', [$st['state'], $st['gap_sec'], $st['left']], ['running', 10, 1]);
check('המפתח לא מתחלף בהפעלה (כדי שה-cron ימשיך לעבוד)', scoutJobRow()['worker_key'], $key);
check('צעד: האתר חסום → blocked', scoutJobTick(), 'blocked');
$j = scoutJobRow();
check('ממתין לסוף החסימה ומאט ×1.5', [(int) $j['next_at'] >= (importHostBlockedUntil('waf.example') ?? 0) + 60, (int) $j['gap_sec'], (int) $j['blocks_n']], [true, 15, 1]);
check('הפריט עדיין "לייבוא"', $pdo->query("SELECT status FROM scout_items WHERE id = $wid")->fetchColumn(), 'wanted');
check('עוד לא הגיע הזמן — wait', scoutJobTick(), 'wait');
$st = scoutJobStatus($devU);
check('המצב: חי, ההמתנה ארוכה, הרשומה האחרונה מסומנת חסימה', [$st['alive'], $st['next_in'] > 40 * 60, $st['recent'][0]['blocked']], [true, true, true]);
scoutJobStop($devU);
check('עצירה', [scoutJobStatus($devU)['state'], scoutJobTick()], ['stopped', 'idle']);
scoutMark([$wid], 'skipped', $devU);
expectError('הפעלה בלי מסומנים', fn() => scoutJobStart($GLOBALS['devU'], 25, false), 'אין פריטים');
scoutMark([$wid], 'wanted', $devU);
scoutJobStart($devU, 25, false);
check('הפעלה מחדש: המונים מתאפסים', scoutJobStatus($devU)['blocks_n'], 0);
scoutMark([$wid], 'skipped', $devU);
db()->exec('UPDATE scout_job SET next_at = 0');
check('הרשימה נגמרה — done', [scoutJobTick(), scoutJobStatus($devU)['state']], ['done', 'done']);
check('run כשאין מה לעשות יוצא נקי', scoutWorkerRun(true), 'idle');
$held = fopen(scoutStateDir() . '/worker.lock', 'c'); flock($held, LOCK_EX);
check('עובד אחד בכל פעם: כשאחר מחזיק את הנעילה — busy', scoutWorkerRun(true), 'busy');
flock($held, LOCK_UN); fclose($held);

foreach (['scout', 'fetch-blocks', 'fetch-cache'] as $d) { foreach (glob("$tmp/$d/*") ?: [] as $f) @unlink($f); @rmdir("$tmp/$d"); }
foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
@rmdir($tmp . '/media'); @rmdir($tmp);
echo "\n";
if ($fail) { echo "\u{274C} נכשלו " . count($fail) . ": " . implode(', ', $fail) . "\n"; exit(1); }
echo "\u{2705} כל הבדיקות עברו\n";
