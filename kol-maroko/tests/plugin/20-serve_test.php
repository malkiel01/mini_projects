<?php
/**
 * M2 §2 "הגשה — M-A": התפיסה לפני וורדפרס, נתיב ← קובץ (I5), הכותרות על כל תשובה (I1, I2, I4, I7, H10), מטמון, Range,
 * בלי Set-Cookie (גם כשתוסף אחר קבע עוגייה לפני), ו-G7 (כבוי / בלי גרסה — לא תופס). ואטומיות: 200 בקשות בזמן פרסום.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

function kp_site_page(array $r): bool { return str_contains($r['body'], 'WP-SITE-PAGE'); }

test('M2 G7: המפסק כבוי, או דולק בלי גרסה v2 — התוסף אינו תופס דבר', function () {
    kp_site();
    kp_reset();
    ok(kp_site_page(kp_http('GET', '/lexicon/')), 'אין גרסה, כבוי');
    kp_opt('kol_maroko_v2_on', true);
    ok(kp_site_page(kp_http('GET', '/lexicon/')), 'דולק, אין גרסה');
    kp_live(kp_bundle('A'), 1, false);
    ok(kp_site_page(kp_http('GET', '/lexicon/')), 'יש גרסה, כבוי');
    ok(kp_site_page(kp_http('GET', '/lexicon/a.css')), 'יש גרסה, כבוי — גם קובץ');
    kp_opt('kol_maroko_v2_on', true);
    ok(!kp_site_page(kp_http('GET', '/lexicon/')), 'דולק ויש גרסה — נתפס');
    foreach (['/', '/about/', '/lexicon-other/', '/lexico', '/wp-admin/', '/xlexicon/a.css'] as $p) ok(kp_site_page(kp_http('GET', $p)), "מחוץ לתחום: $p");
    foreach (['POST', 'PUT', 'DELETE', 'OPTIONS'] as $m) ok(kp_site_page(kp_http($m, '/lexicon/')), "$m — לא נתפס");
    // H14: כיבוי — העמוד המקורי חוזר מיד; הדלקה — החדש חוזר
    kp_opt('kol_maroko_v2_on', false);
    ok(kp_site_page(kp_http('GET', '/lexicon/')), 'כיבוי ← העמוד המקורי');
    kp_opt('kol_maroko_v2_on', true);
    ok(str_contains(kp_http('GET', '/lexicon/')['body'], 'VERSION-A'), 'הדלקה ← החדש');
});

test('M2 נתיב ← קובץ: entry, x/ ← x/index.html, קבצים, עברית מקודדת; /lexicon ← 301', function () {
    kp_site();
    kp_reset();
    $b = kp_bundle('A');
    kp_live($b, 1);
    eq($b['index.html'], kp_http('GET', '/lexicon/')['body']);
    eq($b['index.html'], kp_http('GET', '/lexicon/index.html')['body']);
    eq($b['x/index.html'], kp_http('GET', '/lexicon/x/')['body']);
    eq($b['a.css'], kp_http('GET', '/lexicon/a.css?v=3')['body'], 'מחרוזת שאילתה — מתעלמים');
    eq($b['מילים/דף.html'], kp_http('GET', '/lexicon/' . rawurlencode('מילים') . '/' . rawurlencode('דף.html'))['body'], 'עברית, מקודדת');
    eq($b['media/w.mp3'], kp_http('GET', '/lexicon/media/w.mp3')['body']);
    $r = kp_http('GET', '/lexicon');
    eq([301, '/lexicon/'], [$r['status'], kp_h($r, 'location')]);
    eq([], kp_iso($r), 'גם ה-301 עם כל הכותרות');
});

test('M2 I5 ו-H7: נתיב שאינו במניפסט, טיפוס החוצה, מקודד, כפול, \\ ו-NUL ← 404 (עמוד ה-404 של החבילה, או ריק)', function () {
    kp_site();
    kp_reset();
    kp_live(kp_bundle('A'), 1);
    $evil = ['/lexicon/../../wp-config.php', '/lexicon/..%2f..%2fwp-config.php', '/lexicon/%2e%2e/%2e%2e/wp-config.php', '/lexicon/x/../a.css',
        '/lexicon/%252e%252e%252fa.css', '/lexicon//a.css', '/lexicon/x//index.html', '/lexicon/a.css%00.html', '/lexicon/a%5ca.css', '/lexicon/.%2fa.css',
        '/lexicon/nope.html', '/lexicon/x', '/lexicon/A.CSS', '/lexicon/a.css/', '/lexicon/%2e/a.css', '/lexicon/x/%2e%2e/a.css', '/lexicon/wp-config.php',
        '/lexicon/' . str_repeat('a/', 600) . 'x', '/lexicon/%ff%fe', '/lexicon/a.css%0a'];
    foreach ($evil as $p) {
        $r = kp_http('GET', $p);
        eq(404, $r['status'], $p);
        eq('', $r['body'], "$p — 404 ריק, לא עמוד של האתר");
        eq([], kp_iso($r), "$p — כל הכותרות");
    }
    ok(!str_contains(kp_http('GET', '/lexicon/..%2f..%2f..%2foptions.json')['body'], 'kol_maroko_keys'), 'קובץ של האתר לא דלף');
    // עם 404.html בחבילה
    kp_live(kp_bundle('B') + ['404.html' => '<h1>OUR-404</h1>'], 2);
    $r = kp_http('GET', '/lexicon/../../wp-config.php');
    eq([404, '<h1>OUR-404</h1>'], [$r['status'], $r['body']]);
    eq([], kp_iso($r));
    eq('no-store', kp_h($r, 'cache-control'));
});

test('M2 H10: הכותרות על כל סוג קובץ ועל 200/206/304/404/416/HEAD — sandbox, nosniff, CORS, CORP, סוג מרשימה, בלי Set-Cookie', function () {
    kp_site();
    kp_reset();
    $files = kp_bundle('A') + ['data/w.json' => '{"a":1}', 'i.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'p.png' => "\x89PNG....",
        'f.woff2' => 'wOF2xxxx', 'm.js' => 'export default 1', 'x.php' => '<?php echo "RAN";', '.htaccess' => 'Options +ExecCGI', '.user.ini' => 'auto_prepend_file=x.php',
        'x.phtml' => '<?php echo 1;', 'x.php.jpg' => '<?php echo 2;', 'z.unknownext' => 'zz', 'README' => 'no ext'];
    kp_live($files, 1);
    $want = ['index.html' => 'text/html; charset=utf-8', 'a.css' => 'text/css; charset=utf-8', 'data/w.json' => 'application/json; charset=utf-8',
        'i.svg' => 'image/svg+xml; charset=utf-8', 'p.png' => 'image/png', 'f.woff2' => 'font/woff2', 'm.js' => 'text/javascript; charset=utf-8',
        'media/w.mp3' => 'audio/mpeg', 'x.php' => 'application/octet-stream', '.htaccess' => 'application/octet-stream', '.user.ini' => 'application/octet-stream',
        'x.phtml' => 'application/octet-stream', 'x.php.jpg' => 'image/jpeg', 'z.unknownext' => 'application/octet-stream', 'README' => 'application/octet-stream'];
    foreach ($want as $p => $type) {
        foreach (['GET', 'HEAD'] as $m) {
            $r = kp_http($m, '/lexicon/' . $p);
            eq([200, $type], [$r['status'], kp_h($r, 'content-type')], "$m $p");
            eq([], kp_iso($r), "$m $p");
            if ($m === 'GET') eq($files[$p], $r['body'], "$p — בייט בבייט, לא הורץ (H6)");
            else eq('', $r['body'], "HEAD $p — בלי גוף");
            eq((string)strlen($files[$p]), kp_h($r, 'content-length'), "$m $p — Content-Length");
        }
        if ($type === 'application/octet-stream') eq('attachment', kp_h(kp_http('GET', '/lexicon/' . $p), 'content-disposition'), "$p — הורדה");
    }
    $r = kp_http('GET', '/lexicon/a.css', ['If-None-Match: "' . hash('sha256', $files['a.css']) . '"']);
    eq([304, ''], [$r['status'], $r['body']]);
    eq([], kp_iso($r), '304');
    $r = kp_http('GET', '/lexicon/media/w.mp3', ['Range: bytes=99999999-']);
    eq(416, $r['status']);
    eq([], kp_iso($r), '416');
    $r = kp_http('GET', '/lexicon/media/w.mp3', ['Range: bytes=0-9']);
    eq(206, $r['status']);
    eq([], kp_iso($r), '206');
    // CSP: בלי allow-same-origin ובלי allow-top-navigation
    $csp = kp_h(kp_http('GET', '/lexicon/'), 'content-security-policy');
    ok(str_starts_with($csp, 'sandbox allow-scripts') && !str_contains($csp, 'allow-same-origin') && !str_contains($csp, 'allow-top-navigation'), $csp);
});

test('M2 I7: בלי Set-Cookie — גם כשתוסף אחר קבע עוגייה לפני התוסף, וגם כשהבקשה מגיעה עם עוגיות של מחובר', function () {
    kp_site();
    kp_reset();
    kp_live(kp_bundle('A'), 1);
    $r = kp_http('GET', '/__site/');
    ok(isset($r['headers']['set-cookie']), 'בקרה: האתר המדומה אכן קובע עוגייה לפני התוסף');
    foreach (['/lexicon/', '/lexicon/a.css', '/lexicon/nope', '/lexicon'] as $p) {
        $r = kp_http('GET', $p, ['Cookie: wordpress_logged_in_test=1; other=2']);
        ok(!isset($r['headers']['set-cookie']), "$p — בלי Set-Cookie");
        ok(!isset($r['headers']['x-powered-by']), "$p — בלי כותרות של האתר");
    }
});

test('M2 מטמון: מדיה ו-immutable — לשנה; HTML ו"יפה" — max-age=0 + ETag = sha256; If-None-Match ← 304', function () {
    kp_site();
    kp_reset();
    $b = kp_bundle('A') + ['assets/app.3f2a.js' => 'x'];
    $man = kp_manifest($b, 1);
    $man['files']['assets/app.3f2a.js']['immutable'] = true;
    kp_publish($man);
    kp_opt('kol_maroko_v2_on', true);
    $year = 'public, max-age=31536000, immutable';
    $short = 'public, max-age=0, must-revalidate';
    eq($year, kp_h(kp_http('GET', '/lexicon/media/w.mp3'), 'cache-control'), 'מדיה');
    eq($year, kp_h(kp_http('GET', '/lexicon/assets/app.3f2a.js'), 'cache-control'), 'immutable במניפסט');
    foreach (['/lexicon/', '/lexicon/x/', '/lexicon/a.css'] as $p) {
        $r = kp_http('GET', $p);
        eq($short, kp_h($r, 'cache-control'), $p);
        ok(preg_match('/^"[a-f0-9]{64}"$/', (string)kp_h($r, 'etag')) === 1, "$p — ETag");
    }
    $etag = kp_h(kp_http('GET', '/lexicon/'), 'etag');
    eq('"' . hash('sha256', $b['index.html']) . '"', $etag);
    eq(304, kp_http('GET', '/lexicon/', ["If-None-Match: $etag"])['status']);
    eq(304, kp_http('GET', '/lexicon/', ['If-None-Match: "zzz", W/' . $etag])['status'], 'רשימה, וחלש');
    eq(200, kp_http('GET', '/lexicon/', ['If-None-Match: "' . str_repeat('0', 64) . '"'])['status'], 'ETag אחר ← 200');
});

test('M2 Range: 206 עם Content-Range ו-Accept-Ranges; סוף, התחלה, סיומת; לא חוקי ← 416; כמה טווחים ← הקובץ כולו', function () {
    kp_site();
    kp_reset();
    $b = kp_bundle('A');
    kp_live($b, 1);
    $mp3 = $b['media/w.mp3'];
    $n = strlen($mp3);
    foreach (['bytes=0-9' => [0, 9], 'bytes=10-' => [10, $n - 1], 'bytes=-5' => [$n - 5, $n - 1], "bytes=5-99999999" => [5, $n - 1], 'bytes=0-0' => [0, 0]] as $h => [$a, $z]) {
        $r = kp_http('GET', '/lexicon/media/w.mp3', ["Range: $h"]);
        eq([206, "bytes $a-$z/$n", substr($mp3, $a, $z - $a + 1), (string)($z - $a + 1)], [$r['status'], kp_h($r, 'content-range'), $r['body'], kp_h($r, 'content-length')], $h);
        eq('bytes', kp_h($r, 'accept-ranges'));
    }
    foreach (["bytes=$n-", 'bytes=9-3', 'bytes=-0', 'bytes=-', 'bytes=x-1'] as $h) {
        $r = kp_http('GET', '/lexicon/media/w.mp3', ["Range: $h"]);
        eq([416, "bytes */$n"], [$r['status'], kp_h($r, 'content-range')], $h);
    }
    $r = kp_http('GET', '/lexicon/media/w.mp3', ['Range: bytes=0-1,5-6']);
    eq([200, $mp3], [$r['status'], $r['body']], 'כמה טווחים');
    $r = kp_http('HEAD', '/lexicon/media/w.mp3', ['Range: bytes=0-9']);
    eq([206, '10', ''], [$r['status'], kp_h($r, 'content-length'), $r['body']], 'HEAD עם Range');
});

test('M2 אחרי פרסום / activate: ניקוי מטמון לתחום הכתובת (LiteSpeed — פעולה וכותרת), ונרשם לאבחון', function () {
    kp_site();
    kp_reset();
    $r = kp_live(kp_bundle('A'), 1);
    ok(str_contains(implode(',', $r['headers']['x-litespeed-purge'] ?? []), 'tag=kol_maroko_v2'), 'X-LiteSpeed-Purge בתשובה');
    $acts = array_column((array)kp_get('test_actions'), 1, 0);
    eq([kp_site()['base'] . '/lexicon/'], $acts['litespeed_purge_url'] ?? null, 'do_action(litespeed_purge_url, תחום הכתובת)');
    eq(true, kp_get('kol_maroko_v2_purge')['ok'] ?? null);
    eq('kol_maroko_v2', kp_h(kp_http('GET', '/lexicon/'), 'x-litespeed-tag'), 'התשובות מתויגות — כדי שהניקוי יתפוס אותן');
    kp_opt('test_actions', null);
    kp_live(kp_bundle('B'), 2);
    kp_send('activate', ['version' => 1]);
    eq(2, count(array_filter((array)kp_get('test_actions'), fn($a) => $a[0] === 'litespeed_purge_url')), 'גם אחרי activate');
});

test('M2 אטומיות: 200 בקשות מקבילות בזמן פרסומים — כל תשובה שייכת לגרסה אחת, שלמה', function () {
    kp_site();
    kp_reset();
    $mk = function (string $t) { return ['index.html' => "<p>$t</p>" . str_repeat("<!-- $t -->", 4000), 'a.css' => "/*$t*/" . str_repeat("p{color:#$t}", 3000)]; };
    $versions = [];
    foreach (['111', '222', '333', '444'] as $i => $t) {
        $versions[$t] = $mk($t);           // הגושים נשלחים לפני כל פרסום (הניקוי מוחק גוש שאף מניפסט שמור אינו מפנה אליו)
    }
    kp_live($versions['111'], 1);
    // ארבעה גלים של 50 בקשות מקבילות; בזמן שכל גל באוויר — פרסום של הגרסה הבאה
    $mh = curl_multi_init();
    $hs = [];
    $n = 2;
    $tags = ['222', '333', '444', null];
    foreach ($tags as $t) {
        for ($i = 0; $i < 50; $i++) {
            $ch = curl_init(kp_site()['base'] . (count($hs) % 2 ? '/lexicon/' : '/lexicon/a.css'));
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
            curl_multi_add_handle($mh, $ch);
            $hs[] = $ch;
        }
        curl_multi_exec($mh, $running);
        if ($t !== null) {
            $pr = kp_publish(kp_manifest($versions[$t], $n++));
            ok($pr['status'] === 200, 'פרסום בזמן הבקשות: ' . $pr['body']);
        }
        do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.01); } while ($running > 0);
    }
    $seen = [];
    foreach ($hs as $i => $ch) {
        $body = (string)curl_multi_getcontent($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $file = $i % 2 ? 'index.html' : 'a.css';
        $hit = null;
        foreach ($versions as $t => $v) if ($body === $v[$file]) $hit = $t;
        ok($code === 200 && $hit !== null, "בקשה $i: $code, " . strlen($body) . ' בייטים — אינה שלמה מגרסה אחת');
        $seen[$hit] = true;
        curl_multi_remove_handle($mh, $ch);
    }
    curl_multi_close($mh);
    eq(4, kp_get('kol_maroko_v2_live'));
    ok(count($seen) >= 2, 'הפרסומים קרו בזמן הבקשות — נראו כמה גרסאות: ' . implode(',', array_keys($seen)));
});

test('M2 בדיקה עצמית: אסימון חד-פעמי מגיש גם כשהמפסק כבוי; אסימון שגוי או שפג — לא', function () {
    kp_site();
    kp_reset();
    kp_live(kp_bundle('A'), 1, false);
    kp_opt('kol_maroko_v2_selftest', ['t' => str_repeat('c', 32), 'exp' => time() + 60]);
    ok(!kp_site_page(kp_http('GET', '/lexicon/', ['X-Kol-Selftest: ' . str_repeat('c', 32)])), 'אסימון נכון — מוגש');
    ok(kp_site_page(kp_http('GET', '/lexicon/', ['X-Kol-Selftest: ' . str_repeat('d', 32)])), 'אסימון שגוי — לא');
    ok(kp_site_page(kp_http('GET', '/lexicon/', ['X-Kol-Selftest: '])), 'ריק — לא');
    kp_opt('kol_maroko_v2_selftest', ['t' => str_repeat('c', 32), 'exp' => time() - 1]);
    ok(kp_site_page(kp_http('GET', '/lexicon/', ['X-Kol-Selftest: ' . str_repeat('c', 32)])), 'פג — לא');
});
