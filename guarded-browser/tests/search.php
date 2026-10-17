<?php
/**
 * בדיקות לחיפוש (שלב 8): סריקה (עם רשת מזויפת), אינדקס, סינון לפי הילד, Brave.
 *
 *   php guarded-browser/tests/search.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/gb-srch-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("GB_DATA_DIR=$tmp");
register_shutdown_function(function () use ($tmp) {
    foreach (glob("$tmp/*") ?: [] as $f) @unlink($f);
    @rmdir($tmp);
});

require_once __DIR__ . '/../lib/search.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}

// הרשת המזויפת: כתובת ← [סטטוס, גוף].
$WEB = [
    'https://kids.example/' => [200, '<html><head><title>אתר הילדים</title><meta name="description" content="משחקים ולימוד"></head></html>'],
    'https://kids.example/robots.txt' => [200, "User-agent: *\nSitemap: https://kids.example/sm.xml\n"],
    'https://kids.example/sm.xml' => [200, '<urlset><url><loc>https://kids.example/animals/lion-facts</loc></url><url><loc>https://kids.example/space/moon</loc></url><url><loc>https://evil.example/x</loc></url></urlset>'],
    'https://kids.example/animals/lion-facts' => [200, '<title>הכול על האריות</title><meta name="description" content="האריה מלך החיות">'],
    'https://kids.example/space/moon' => [200, '<title>הירח</title>'],
    'https://www.youtube.com/@kidschan' => [200, '<script>{"channelId":"UCaaaaaaaaaaaaaaaaaaaaaa"}</script>'],
    'https://www.youtube.com/feeds/videos.xml?channel_id=UCaaaaaaaaaaaaaaaaaaaaaa' => [200,
        '<feed><title>ערוץ הילדים</title><entry><yt:videoId>vid00000001</yt:videoId><yt:channelId>UCaaaaaaaaaaaaaaaaaaaaaa</yt:channelId><title>שיר על אריה</title><media:description>שיר ילדים</media:description></entry>'
      . '<entry><yt:videoId>vid00000002</yt:videoId><yt:channelId>UCaaaaaaaaaaaaaaaaaaaaaa</yt:channelId><title>מסע לירח</title></entry></feed>'],
];
$calls = [];
$get = function (string $u) use (&$WEB, &$calls) { $calls[] = $u; $r = $WEB[$u] ?? [404, '']; return ['status' => $r[0], 'body' => $r[1]]; };

db();
[$a] = accountCreate(['name' => 'כהן', 'modules' => ['search_index', 'search_web', 'yt_filtered']], 'dana@x.com', 'דנה');
[$a2] = accountCreate(['name' => 'לוי', 'modules' => []], 'avi@x.com', 'אבי');
[$kid] = childCreate($a, 'נועה');
[$kid2] = childCreate($a, 'יוסי');
[$kid3] = childCreate($a2, 'דני');
$user = fn(int $id) => one('SELECT * FROM users WHERE id = ?', [$id]);
$titles = fn(array $r) => array_column($r['results'], 'title');

childRuleAdd($kid, 'kids.example');
childYouTubeSave($kid, 'restricted', false, false, ['off', 'restricted']);
childYouTubeAdd($kid, '@kidschan');

echo "\n— סריקה —\n";
check('FTS5 זמין', searchHasFts(), true);
$n = searchTick(30, $get);
check('נסרקו שני מקורות (אתר, ערוץ)', $n, 2);
check('הדף הראשי עם הכותרת והתיאור', one("SELECT title, body FROM search_pages WHERE url = 'https://kids.example/'"),
      ['title' => 'אתר הילדים', 'body' => 'משחקים ולימוד']);
check('דפי ה-sitemap — רק מאותו אתר', array_column(all("SELECT url FROM search_pages WHERE host = 'evil.example'"), 'url'), []);
check('כותרות אמיתיות הושלמו', one("SELECT title FROM search_pages WHERE url = 'https://kids.example/animals/lion-facts'")['title'], 'הכול על האריות');
check('הסרטונים מהפיד', (int) one("SELECT COUNT(*) n FROM search_pages WHERE kind = 'video'")['n'], 2);
check('ובעלות הסרטון נשמרה (לסינון בלי רשת)', one("SELECT channel_id, handle FROM video_owner WHERE video_id = 'vid00000001'"),
      ['channel_id' => 'UCaaaaaaaaaaaaaaaaaaaaaa', 'handle' => 'kidschan']);
$calls = [];
check('סריקה שנייה מיד — אין מה לסרוק (פעם ביום)', [searchTick(5, $get), $calls], [0, []]);
check('כותרת מנוחשת מכתובת', searchSlugTitle('https://he.wikipedia.org/wiki/%D7%90%D7%A8%D7%99%D7%94_(%D7%97%D7%99%D7%94)'), 'אריה (חיה)');

echo "\n— חיפוש באינדקס (deny_all) —\n";
$r = searchFor($user($kid), 'אריה');
check('מנוע: אינדקס', $r['engine'], 'index');
check('מוצא דף וסרטון', $titles($r), ['שיר על אריה', 'הכול על האריות']);
$t = $titles(searchFor($user($kid), 'ירח')); sort($t);
check('ירח', $t, ['הירח', 'מסע לירח']);
check('קידומת: "אר" לא מספיק? (2 אותיות כן)', count(searchFor($user($kid), 'אר')['results']) > 0, true);
check('מילה אחת קצרה מדי', searchFor($user($kid), 'א')['note'], 'כתבו לפחות מילה אחת של שתי אותיות');

echo "\n— סינון לפי הילד —\n";
check('ילד בלי האתר — לא רואה כלום', searchFor($user($kid2), 'אריה')['results'], []);
childRuleAdd($kid2, 'kids.example');
check('...עם האתר אבל בלי הערוץ — רק הדף', $titles(searchFor($user($kid2), 'אריה')), ['הכול על האריות']);
childRuleAdd($kid, 'kids.example/space/moon', 'exact', '', 'deny');
check('דף שנחסם במפורש — נעלם מהתוצאות', $titles(searchFor($user($kid), 'ירח')), ['מסע לירח']);
check('חשבון בלי מודול חיפוש — הסבר', searchFor($user($kid3), 'אריה')['note'], 'החיפוש עוד לא פתוח בחשבון שלכם. ההורה יכול לבקש ממנהל המערכת.');
check('מה חיפשו — לא נשמר', (int) one("SELECT COUNT(*) n FROM audit WHERE kind = 'search' AND url != ''")['n'], 0);

echo "\n— Brave (allow_all) —\n";
childPolicySave($kid2, ['posture' => 'allow_all']);
childRuleAdd($kid2, 'bad.example', 'domain', '', 'deny');
childYouTubeSave($kid2, 'restricted', false, false, ['off', 'restricted']);   // סרטון שלא ידוע של מי — לא יעבור
$brave = fn($u, $k) => ['status' => 200, 'body' => json_encode(['web' => ['results' => [
    ['url' => 'https://good.example/lions', 'title' => '<strong>אריות</strong> בטבע', 'description' => 'מידע'],
    ['url' => 'https://bad.example/x', 'title' => 'חסום', 'description' => ''],
    ['url' => 'https://m.youtube.com/watch?v=unknownvid1', 'title' => 'סרטון לא מוכר', 'description' => ''],
]]])];
$r = searchFor($user($kid2), 'אריות', $brave);
check('בלי מפתח — נופל לאינדקס', [$r['engine'], $r['note']], ['index', 'חיפוש באתרים שאושרו']);
check('מפתח לא תקין נדחה', braveKeySave('x y'), 'המפתח לא נראה כמו מפתח של Brave');
braveKeySave('BSAtestkey1234567890');
$sent = '';
$r = searchFor($user($kid2), 'אריות', function ($u, $k) use ($brave, &$sent) { $sent = $u; return $brave($u, $k); });
check('מנוע: ברשת', $r['engine'], 'web');
check('safesearch קפדני תמיד', str_contains($sent, 'safesearch=strict'), true);
check('רק מה שמותר לילד; HTML נוקה', $titles($r), ['אריות בטבע']);
check('ילד במצב סגור — לא משתמש ב-Brave', searchFor($user($kid), 'אריה', $brave)['engine'], 'index');

echo "\n— מקורות שבוטלו —\n";
q("DELETE FROM rules WHERE pattern = 'kids.example'");
searchTick(5, $get);
check('אתר שאף ילד לא מאשר — יצא מהאינדקס', (int) one("SELECT COUNT(*) n FROM search_pages WHERE host = 'kids.example'")['n'], 0);
check('...וגם מה-FTS', (int) one("SELECT COUNT(*) n FROM search_fts WHERE search_fts MATCH '\"הירח\"'")['n'], 0);

echo "\n— הגבלת קצב —\n";
for ($i = 0; $i < SEARCH_PER_HOUR; $i++) audit($kid, 'search', true);
check('יותר מדי חיפושים בשעה', searchFor($user($kid), 'ירח')['note'], 'הרבה חיפושים בשעה האחרונה. נסו שוב עוד מעט.');

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
