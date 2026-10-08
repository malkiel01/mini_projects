<?php
/**
 * בדיקת importer.php — ייבוא מתכון מהרשת — על קבצי דוגמה, בלי רשת.
 * הרצה: php recipes-app/tools/import-check.php
 *
 * מוכיחה: שלוש שכבות החילוץ (JSON-LD אמיתי מאתר ישראלי, Microdata, כותרות),
 * פענוח רכיב לשכבה המחושבת, זמנים ומנות, ניחוש תגים, הגנת SSRF, ושהמקור
 * נשמר במתכון ומוצג — ואינו נמחק בעריכה.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/recipes-import-' . getmypid();
@mkdir($tmp, 0775, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('MEDIA_DIR', $tmp . '/media');
@ini_set('sendmail_path', '/bin/true');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/recipes.php';
require_once __DIR__ . '/../lib/media.php';
require_once __DIR__ . '/../lib/importer.php';

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
$fx = fn(string $name) => file_get_contents(__DIR__ . '/fixtures/' . $name);

$mali  = createUser('mali', 'm@x.com', 'sod12345', 'מלי');
$maliU = ['id' => $mali['id'], 'role' => 'admin'];

echo "\n1. פענוח רכיב — טקסט חופשי נשאר, השכבה המחושבת מנוחשת\n";
$i = importParseIngredient('2 כוסות (400 גרם) קמח');
check('"2 כוסות (400 גרם) קמח"', [$i['amount_min'], $i['unit'], $i['product']], [2.0, 'cup', 'קמח']);
$i = importParseIngredient('1 ק"ג גבינה לבנה 5%');
check('"1 ק\\"ג גבינה לבנה 5%"', [$i['amount_min'], $i['unit'], $i['product']], [1.0, 'kg', 'גבינה לבנה 5%']);
$i = importParseIngredient('1/2 כוס שמן זית');
check('שבר "1/2"', [$i['amount_min'], $i['unit']], [0.5, 'cup']);
$i = importParseIngredient('1 ½ כפיות מלח');
check('"1 ½" = 1.5', [$i['amount_min'], $i['unit']], [1.5, 'tsp']);
$i = importParseIngredient('4 ביצים');
check('מספר בלי יחידה → יחידה', [$i['amount_min'], $i['unit'], $i['product']], [4.0, 'unit', 'ביצים']);
$i = importParseIngredient('קורט מלח');
check('בלי מספר → בלי שכבה מחושבת, המוצר נשאר', [$i['amount_min'], $i['unit'], $i['product']], [null, '', 'קורט מלח']);
$i = importParseIngredient('100 גרם שוקולד מריר (לא חובה)');
check('"לא חובה" מזוהה', [$i['optional'], $i['product']], [true, 'שוקולד מריר']);
$i = importParseIngredient('500 g bread flour, sifted');
check('אנגלית, ופסיק חותך תיאור', [$i['amount_min'], $i['unit'], $i['product']], [500.0, 'gram', 'bread flour']);
check('הטקסט החופשי נשמר כמו שהוא', importParseIngredient(" \t4 ביצים")['free_text'], '4 ביצים');

$i = importParseIngredient('1 כוס או 240 מ"ל שמן קנולה');
check('מידה חלופית אחרי "או" אינה שם המוצר', [$i['amount_min'], $i['unit'], $i['product']], [1.0, 'cup', 'שמן קנולה']);
$i = importParseIngredient('1 מיכל או 200 מ"ל שמנת חמוצה 15%');
check('"1 מיכל או 200 מ"ל" → המיכל הוא היחידה (אריזה)', [$i['unit'], $i['product']], ['package', 'שמנת חמוצה 15%']);

echo "\n2. זמנים ומנות\n";
check('PT1H30M', importDurationMinutes('PT1H30M'), 90);
check('PT45M', importDurationMinutes('PT45M'), 45);
check('P1DT2H', importDurationMinutes('P1DT2H'), 1560);
check('לא תקין → null', importDurationMinutes('בערך שעה'), null);
check('"8 מנות" → מספר', importParseYield('8 מנות'), ['servings' => 8, 'yield_text' => '']);
check('"Serves 6"', importParseYield('Serves 6'), ['servings' => 6, 'yield_text' => '']);
check('"עוגה אחת בתבנית 24" → טקסט', importParseYield('עוגה אחת בתבנית 24')['yield_text'], 'עוגה אחת בתבנית 24');

echo "\n3. הגנה: כתובות שאסור להביא\n";
expectError('ftp', fn() => importCheckUrl('ftp://example.com/x'), 'http');
expectError('localhost', fn() => importCheckUrl('http://localhost/x'), 'פנימית');
expectError('127.0.0.1', fn() => importCheckUrl('http://127.0.0.1/x'), 'פנימית');
expectError('10.x', fn() => importCheckUrl('http://10.0.0.5/x'), 'פנימית');
expectError('192.168.x', fn() => importCheckUrl('https://192.168.1.1/admin'), 'פנימית');
expectError('169.254 (metadata)', fn() => importCheckUrl('http://169.254.169.254/latest'), 'פנימית');
expectError('פורט לא רגיל', fn() => importCheckUrl('http://8.8.8.8:8080/x'), 'פורט');
expectError('שם משתמש בכתובת', fn() => importCheckUrl('http://user:pw@8.8.8.8/x'), 'שם משתמש');
check('כתובת ציבורית עוברת', importCheckUrl('https://8.8.8.8/recipe')['host'], '8.8.8.8');

echo "\n4. JSON-LD — דף אמיתי (10dakot.co.il)\n";
$raw = importParse($fx('jsonld-10dakot.html'), 'https://www.10dakot.co.il/recipe/x/');
check('זוהה ב-JSON-LD', $raw['method'], 'json-ld');
check('שם', $raw['title'], 'עוגת גבינה של בית מלון');
check('רכיבים', count($raw['ingredients']) >= 7, true);
check('שלבים כ-HowToStep', count($raw['sections'][0]['steps']) >= 5, true);
check('זמנים', [$raw['prep_minutes'], $raw['cook_minutes']], [10, 60]);
check('תמונה מוחלטת', str_starts_with($raw['images'][0], 'https://www.10dakot.co.il/'), true);
check('כותבת', $raw['author'], 'אפרת סיאצ\'י');
check('קטגוריות ומילות מפתח', in_array('מתכונים לעוגות', $raw['categories'], true), true);
$draft = importToDraft($raw);
check('הטיוטה: פרטי, עם מקור', [$draft['visibility'], $draft['source_url']], ['private', 'https://www.10dakot.co.il/recipe/x/']);
check('שם האתר מ-og:site_name', $draft['source_name'] !== '', true);
check('הרכיבים בחלק הראשון, עם שכבה מחושבת', $draft['sections'][0]['ingredients'][1]['amount_min'], 4.0);
check('תגים נוחשו: עוגות, חלבי', count(array_intersect(
    array_column(array_filter(db()->query('SELECT id, name FROM tags')->fetchAll(), fn($t) => in_array($t['name'], ['עוגות', 'חלבי'], true)), 'id'),
    $draft['tag_ids'])), 2);
check('pending_media: תמונה אחת, בלי סרטון', [count($draft['pending_media']['images']), count($draft['pending_media']['videos'])], [1, 0]);
check('התיאור הפך לטיפים', str_contains($draft['tips'], 'עוגת גבינה'), true);

echo "\n4ב. JSON-LD עם הוראות כמחרוזת אחת (carine.co.il)\n";
$raw = importParse($fx('jsonld-carine.html'), 'https://www.carine.co.il/foody_recipe/x/');
$steps = $raw['sections'][0]['steps'];
check('חמישה שלבים מתוך מחרוזת אחת', count($steps), 5);
check('"אופן הכנה" הוסר מהראשון', str_starts_with($steps[0], 'טורפים'), true);
check('הפרסומת שנדבקה לסוף נחתכה', str_ends_with($steps[4], 'להתייצב.'), true);
check('ואינה שלב בפני עצמה', count(array_filter($steps, fn($s) => str_contains($s, 'לרכוש'))), 0);
check('זמנים: cook בלבד, total לא דורס', [$raw['prep_minutes'], $raw['cook_minutes']], [null, 15]);
check('"1" כמנה → מספר', importToDraft($raw)['servings'], 1);
check('הניקוי: מפרסומת והלאה נחתך, כותרת דבוקה מוסרת', importCleanSteps(['הוראות: מערבבים.', 'אופים.', 'לפרטים נוספים לחצו כאן', 'עוד פרסומת']), ['מערבבים.', 'אופים.']);

echo "\n5. Microdata\n";
$raw = importParse($fx('microdata.html'), 'https://example.org/recipes/salad');
check('זוהה', $raw['method'], 'microdata');
check('שם, כותבת, מנות', [$raw['title'], $raw['author'], $raw['yield']], ['סלט עגבניות של סבתא', 'סבתא רחל', '4 מנות']);
check('רכיבים ושלבים', [count($raw['ingredients']), count($raw['sections'][0]['steps'])], [3, 2]);
check('תמונה יחסית → מוחלטת', $raw['images'][0], 'https://example.org/img/salad.jpg');
check('כתובת יחסית שומרת פורט', importAbsolute('/a.jpg', 'http://127.0.0.1:8080/x/y'), 'http://127.0.0.1:8080/a.jpg');
check('כתובת יחסית בלי לוכסן — מהתיקייה', importAbsolute('b.jpg', 'https://e.org/x/y'), 'https://e.org/x/b.jpg');
check('זמן הכנה מ-meta content', $raw['prep_minutes'], 15);
$draft = importToDraft($raw);
check('מנות כמספר', [$draft['servings'], $draft['yield_text']], [4, '']);

echo "\n6. ניחוש לפי כותרות (בלוג בלי סימון)\n";
$raw = importParse($fx('headings.html'), 'https://dana.example.org/bread');
check('זוהה', $raw['method'], 'headings');
check('שם מ-og:title', $raw['title'], 'לחם מחמצת ביתי');
check('ארבעה מצרכים, שלושה שלבים', [count($raw['ingredients']), count($raw['sections'][0]['steps'])], [4, 3]);
check('"תגובות" לא נלקח כרשימה', $raw['sections'][0]['steps'][2], 'מקפלים כל חצי שעה, ארבע פעמים.');
check('אתר וכותבת מה-meta', [$raw['publisher'], $raw['author']], ['הבלוג של דנה', 'דנה']);
$draft = importToDraft($raw);
check('אזהרה על חילוץ לפי כותרות', count(array_filter($draft['warnings'], fn($w) => str_contains($w, 'כותרות'))), 1);
expectError('דף בלי מתכון', fn() => importParse($GLOBALS['fx']('norecipe.html'), 'https://example.org/'), 'לא מצאתי מתכון');

echo "\n6ב. תיעוד — שמירה, קריאה, ומשיכה חוזרת\n";
$raw = importParse($fx('jsonld-10dakot.html'), 'https://www.10dakot.co.il/recipe/x/');
$draftS = importToDraft($raw);
$sid = saveRecipe($draftS, $maliU);
check('לפני: אין תיעוד', importGetSnapshot($sid, $maliU), null);
importSaveSnapshot($sid, $draftS['snapshot'], $maliU);
$snap = importGetSnapshot($sid, $maliU);
check('נשמר: שיטה, כתובת, שלבים כלשונם', [$snap['extracted_by'], $snap['source_url'], count($snap['raw']['sections'][0]['steps']) >= 5], ['json-ld', 'https://www.10dakot.co.il/recipe/x/', true]);
$noa = createUser('noa', 'n@x.com', 'sod12345', 'נועה'); verifyEmail($noa['token']);
expectError('רק המייבא רואה', fn() => importGetSnapshot($GLOBALS['sid'], ['id' => $GLOBALS['noa']['id'], 'role' => 'admin']), 'רק למי שייבא');
importSaveSnapshot($sid, $draftS['snapshot'], $maliU);
check('שמירה חוזרת מחליפה ולא מכפילה', (int) db()->query("SELECT COUNT(*) FROM import_snapshots WHERE recipe_id = $sid")->fetchColumn(), 1);
$plain = saveRecipe(['title' => 'בלי מקור', 'visibility' => 'private', 'sections' => $draftS['sections']], $maliU);
expectError('משיכה למתכון בלי מקור', fn() => importRefreshSnapshot($GLOBALS['plain'], $GLOBALS['maliU']), 'אין מקור');
expectError('משיכה בידי מי שאינו הבעלים', fn() => importRefreshSnapshot($GLOBALS['sid'], ['id' => $GLOBALS['noa']['id'], 'role' => 'user']), 'רק מי שייבא');
deleteRecipe($sid, $maliU);
check('נמחק עם המתכון', (int) db()->query("SELECT COUNT(*) FROM import_snapshots WHERE recipe_id = $sid")->fetchColumn(), 0);

echo "\n6ג. יומן ייבוא — אבחון הדף, מה חולץ, טקסט להדבקה\n";
$diag = importDiagnose(importDom($fx('jsonld-carine.html')), $fx('jsonld-carine.html'));
check('JSON-LD: יש Recipe, ההוראות מחרוזת אחת', [$diag['jsonld']['recipe'], str_starts_with($diag['jsonld']['instructions'], 'string(')], [true, true]);
check('קטע מההוראות במקור', mb_strlen($diag['jsonld']['instructions_excerpt']) > 20, true);
$diag = importDiagnose(importDom($fx('headings.html')), $fx('headings.html'));
check('בלוג בלי סימון: אין JSON-LD, יש כותרות', [$diag['jsonld']['blocks'], count($diag['headings']) >= 2, $diag['microdata']['scopes']], [0, true, 0]);
$diagN = importDiagnose(importDom($fx('norecipe.html')), $fx('norecipe.html'));
$draftL = importToDraft(importParse($fx('jsonld-10dakot.html'), 'https://www.10dakot.co.il/recipe/x/'));
$lid = importLogDraft('scout-preview', 'https://www.10dakot.co.il/recipe/x/', $draftL, $maliU, $diag, 123, ['scout_id' => 7]);
$fid = importLogFailure('preview', 'https://blog.example/no', 'לא מצאתי מתכון בדף הזה', $maliU, $diagN, 45);
$sid2 = saveRecipe($draftL, $maliU);
importLogSetRecipe($lid, $sid2);
importLogSaved('https://www.10dakot.co.il/recipe/x/', $sid2, ['title' => 'מתוקן', 'sections' => [['ingredients' => [['free_text' => 'א'], ['free_text' => 'ב']], 'steps' => [['text' => 'x']]]]], $maliU);
$rows = importLogList();
check('שלוש שורות, החדשה ראשונה', [count($rows), $rows[0]['kind'], $rows[1]['kind'], $rows[2]['kind']], [3, 'editor-save', 'preview', 'scout-preview']);
check('ההצלחה: שיטה, ספירות, מקור, מועמד, מתכון', [$rows[2]['method'], $rows[2]['ingredients_n'] > 5, $rows[2]['steps_n'] >= 5, $rows[2]['host'], $rows[2]['scout_id'], $rows[2]['recipe_id']],
      ['json-ld', true, true, 'www.10dakot.co.il', 7, $sid2]);
check('הכשל: השגיאה והאבחון נשמרו', [$rows[1]['ok'], $rows[1]['error'], is_array($rows[1]['diag'])], [false, 'לא מצאתי מתכון בדף הזה', true]);
check('השמירה: מה נשמר בסוף', [$rows[0]['ingredients_n'], $rows[0]['steps_n'], $rows[0]['recipe_id']], [2, 1, $sid2]);
$txt = importLogAsText($rows);
check('טקסט: כרונולוגי, מקור, רכיבים כלשונם עם פענוח, שלבים, אבחון', [
    strpos($txt, 'תצוגה מקדימה בסורק') < strpos($txt, 'שמירה מהעורך'),
    str_contains($txt, 'מקור: https://www.10dakot.co.il/recipe/x/'),
    str_contains($txt, 'רכיבים (כלשונם → הפענוח):'), str_contains($txt, 'שלבים (כלשונם):'),
    str_contains($txt, 'בדף:') && str_contains($txt, 'JSON-LD:'), str_contains($txt, 'שגיאה: לא מצאתי'),
], [true, true, true, true, true, true]);
check('סינון: לפי אתר, לפי תוצאה, לפי סוג', [count(importLogList(['host' => 'blog.example'])), count(importLogList(['ok' => '0'])), count(importLogList(['kind' => 'editor-save']))], [1, 1, 1]);
check('סטטיסטיקה', [importLogStats()['rows'], importLogStats()['hosts']], [3, ['blog.example', 'www.10dakot.co.il']]);
deleteRecipe($sid2, $maliU);
check('היומן שורד מחיקת המתכון', count(importLogList(['recipe_id' => $sid2])), 2);

echo "\n6ד. חסימה — דף \"הוכח שאתה אדם\" אינו מתכון, והאתר מקבל מנוחה\n";
// הדף שהשרת קיבל מ-carine.co.il ב-2026-10-08, אחרי ~6 הבאות בדקה: 2,415 בתים, 202
$waf = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title></title><script>window.awsWafCookieDomainList = [];window.gokuProps = {};</script><script src="https://x.token.awswaf.com/x/challenge.js"></script></head><body><noscript><h1>JavaScript is disabled</h1>In order to continue, we need to verify that you\'re not a robot.</noscript></body></html>';
check('AWS WAF לפי הכותרת', importDetectBlock(202, ['x-amzn-waf-action' => 'challenge'], ''), 'AWS WAF');
check('AWS WAF לפי הגוף', importDetectBlock(202, [], $waf), 'AWS WAF');
check('Cloudflare', importDetectBlock(403, [], '<title>Just a moment...</title><div id="challenge-platform">'), 'Cloudflare');
check('"JavaScript is disabled" לבד', importDetectBlock(200, [], '<h1>JavaScript is disabled</h1>'), 'challenge');
check('429', importDetectBlock(429, [], 'slow down'), 'rate limit (429)');
check('דף מתכון אמיתי — לא חסימה', importDetectBlock(200, [], $fx('jsonld-carine.html')), null);
check('דף גדול שמזכיר JavaScript — לא חסימה', importDetectBlock(200, [], str_repeat('x', 40000) . 'JavaScript is disabled'), null);
check('לפני: אין חסימה', importHostBlockedUntil('blocked.example'), null);
$until = importMarkHostBlocked('blocked.example');
check('אחרי: 45 דקות מנוחה', $until - time() >= 44 * 60 && importHostBlockedUntil('blocked.example') === $until, true);
expectError('הבאה לאתר חסום — נעצרת לפני הרשת, 429', function () {
    try { importFetch('https://blocked.example/recipe/x/'); }
    catch (AppError $e) { if ($e->status !== 429) throw new AppError('status ' . $e->status); throw $e; }
}, 'חסם זמנית');
check('אתר אחר אינו מושפע', importHostBlockedUntil('other.example'), null);

echo "\n7. שמירה — המקור נשמר, מוצג, ואינו נמחק בעריכה\n";
$raw = importParse($fx('jsonld-10dakot.html'), 'https://www.10dakot.co.il/recipe/x/');
$draft = importToDraft($raw);
$id = saveRecipe($draft, $maliU);
$r = loadRecipe($id, $maliU);
check('המקור במתכון', [$r['source']['url'], $r['source']['author'] !== null, $r['source']['imported_at'] !== null],
      ['https://www.10dakot.co.il/recipe/x/', true, true]);
check('הרכיבים נשמרו עם הטקסט המקורי', $r['sections'][0]['ingredients'][1]['free_text'], '4 ביצים');
check('הזמנים נשמרו', [$r['work_minutes'], $r['wait_minutes']], [10, 60]);
$m = storeLink($id, $maliU, $draft['pending_media']['images'][0], 'image');
check('תמונה כקישור', [$m['kind'], $m['source'], $m['bytes']], ['image', 'link', 0]);
check('והיא הראשית', loadRecipe($id, $maliU)['main_media_id'], $m['id']);
check('הרשימה מציגה אותה כ-thumb חיצוני ואת שם האתר',
      [str_starts_with(searchRecipes($maliU, 'בית מלון')[0]['thumb'], 'https://'), searchRecipes($maliU, 'בית מלון')[0]['source_name'] !== null], [true, true]);
check('סינון "מהרשת"', count(searchRecipes($maliU, '', ['from_web' => true])), 1);
saveRecipe(['title' => 'שם חדש', 'visibility' => 'public', 'sections' => $draft['sections']], $maliU, $id);
check('עריכה בלי שדות מקור — הקרדיט נשאר', loadRecipe($id, $maliU)['source']['url'], 'https://www.10dakot.co.il/recipe/x/');
$own = saveRecipe(['title' => 'שלי', 'visibility' => 'private', 'sections' => $draft['sections']], $maliU);
check('מתכון רגיל — בלי מקור', loadRecipe($own, $maliU)['source'], null);
check('ואינו ברשימת "מהרשת"', count(searchRecipes($maliU, '', ['from_web' => true])), 1);
$own2 = saveRecipe(['title' => 'מקור לא תקין', 'visibility' => 'private', 'source_url' => 'javascript:alert(1)', 'sections' => $draft['sections']], $maliU);
check('קישור מקור לא תקין נזרק', loadRecipe($own2, $maliU)['source'], null);

foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
@rmdir($tmp . '/media'); @rmdir($tmp);
echo "\n";
if ($fail) { echo "\u{274C} נכשלו " . count($fail) . ": " . implode(', ', $fail) . "\n"; exit(1); }
echo "\u{2705} כל הבדיקות עברו\n";
