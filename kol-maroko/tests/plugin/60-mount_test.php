<?php
/**
 * M4-BC — אופני ההצבה (slices/M4-mount-modes.md §2): הפעולה החתומה `mount`, מסמך המעטפת בכל אופן, הכותרות, והזרקה
 * דרך הכותרת / התיאור / שמות הקבצים. מול האתר המדומה tests/plugin/wp.php (התוסף האמיתי + ערכת נושא מדומה:
 * SITE-HEADER / SITE-FOOTER). הגשר עצמו בדפדפן — tests/browser/m4bc.cjs.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

const KP_FRAME_SANDBOX = 'allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox allow-modals allow-downloads allow-presentation';

/** חבילה עם <head> אמיתי: כותרת, תיאור, תגי שיתוף. */
function kp_mount_bundle(string $tag = 'M', array $extra = []): array
{
    return $extra + [
        'index.html' => "<!doctype html><html><head><meta charset=utf-8><title>שלום $tag &amp; ברוכים</title>"
            . "<meta name=\"description\" content=\"תיאור $tag\"><meta property=\"og:title\" content=\"שיתוף $tag\"><meta property='og:image' content='img/a.png'>"
            . "<meta http-equiv=\"refresh\" content=\"0;url=https://evil.example/\"><meta name=\"generator\" content=\"x\"></head><body><p>VERSION-$tag</p></body></html>",
        'a.css' => "p{color:red}/*$tag*/",
        'about/index.html' => "<!doctype html><title>אודות $tag</title><p>about $tag</p>",
        'מילים/index.html' => "<!doctype html><title>מילים</title><p>he $tag</p>",
        'media/w.mp3' => "ID3" . str_repeat("\x01\x02$tag", 300),
    ];
}

function kp_mount(string $m): array { return kp_send('mount', ['mount' => $m]); }

/** GET כמו ניווט עליון (Sec-Fetch-Dest: document), או כמו ה-iframe. */
function kp_nav(string $path, string $dest = 'document', array $h = []): array
{
    return kp_http('GET', $path, array_merge($dest === '' ? [] : ['Sec-Fetch-Dest: ' . $dest], $h));
}

function kp_is_wrap(array $r): bool { return str_contains($r['body'], 'id="kol-maroko-frame"'); }

/** ה-src וה-sandbox של ה-iframe במעטפת. */
function kp_frame(array $r): array
{
    preg_match('~<iframe id="kol-maroko-frame" src="([^"]*)" title="([^"]*)" sandbox="([^"]*)"~', $r['body'], $m);
    return ['src' => html_entity_decode($m[1] ?? '', ENT_QUOTES), 'title' => $m[2] ?? null, 'sandbox' => $m[3] ?? null];
}

test('M4-BC mount: חתום בלבד; ערך מרשימה סגורה; נשמר ב-kol_maroko_v2_mount; ביומן; ping מחזיר v2_mount; בקשה חוזרת נדחית', function () {
    kp_site();
    kp_reset();
    eq('full', kp_send('ping')['json']['v2_mount'] ?? null, 'ברירת המחדל — full (M-A)');
    eq(KOL_PLUGIN_EXPECTED, kp_send('ping')['json']['plugin'] ?? null, 'גרסת התוסף');

    $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    eq(401, kp_send('mount', ['mount' => 'framed'], ['secret' => $other])['status'], 'חתימה של מפתח אחר');
    $s = kp_signed('mount', ['mount' => 'framed']);
    eq(401, kp_http('POST', '/wp-json/kol-maroko/v1/mount', $s['headers'], str_replace('framed', 'bar', $s['body']))['status'], 'גוף ששונה אחרי החתימה');
    eq(null, kp_get('kol_maroko_v2_mount'), 'שום דחייה לא כתבה דבר');

    foreach (['', 'FRAMED', 'redirect', 'domain', 'framed ', ['framed'], 1, null] as $bad) {
        $r = kp_send('mount', ['mount' => $bad]);
        eq([400, 'bad_request'], [$r['status'], $r['json']['code'] ?? null], 'ערך לא מוכר: ' . json_encode($bad));
    }
    foreach (['framed', 'bar', 'full', 'framed'] as $m) {
        $r = kp_mount($m);
        eq([200, true, $m], [$r['status'], $r['json']['ok'] ?? null, $r['json']['mount'] ?? null], "mount=$m");
        eq($m, kp_get('kol_maroko_v2_mount'));
        eq($m, kp_send('ping')['json']['v2_mount'] ?? null, 'ping אחרי ' . $m);
    }
    $log = array_column((array)kp_get('kol_maroko_v2_log'), 'op');
    ok(in_array('mount full → framed', $log, true) && in_array('mount framed → bar', $log, true), 'היומן של התוסף: ' . json_encode($log, JSON_UNESCAPED_UNICODE));
    $again = kp_signed('mount', ['mount' => 'bar']);
    eq(200, kp_http('POST', '/wp-json/kol-maroko/v1/mount', $again['headers'], $again['body'])['status']);
    eq(401, kp_http('POST', '/wp-json/kol-maroko/v1/mount', $again['headers'], $again['body'])['status'], 'אותה בקשה פעמיים ← replay');
    eq('bar', kp_get('kol_maroko_v2_mount'));
    kp_opt('kol_maroko_v2_mount', 'evil');
    eq('full', kp_send('ping')['json']['v2_mount'] ?? null, 'ערך פגום באפשרות ← full');
});

test('M4-BC framed: הכותרת והתחתית של האתר, iframe עם sandbox בלי allow-same-origin, src של הקובץ; הקובץ עצמו — עם I1', function () {
    kp_site();
    kp_reset();
    kp_live(kp_mount_bundle(), 1);
    kp_mount('framed');
    foreach (['document', ''] as $dest) {
        $r = kp_nav('/lexicon/', $dest);
        eq(200, $r['status'], "dest=$dest");
        ok(kp_is_wrap($r), 'מעטפת: ' . substr($r['body'], 0, 200));
        ok(str_contains($r['body'], 'SITE-HEADER') && str_contains($r['body'], 'SITE-FOOTER'), 'framed — כותרת ותחתית של האתר');
        $f = kp_frame($r);
        eq('/lexicon/index.html', $f['src']);
        eq(KP_FRAME_SANDBOX, $f['sandbox']);
        ok(!str_contains($r['body'], 'allow-same-origin') && !str_contains($r['body'], 'allow-top-navigation'), 'בלי allow-same-origin ובלי ניווט עליון');
        ok(!str_contains($r['body'], 'VERSION-M'), 'תוכן החבילה אינו במסמך של האתר');
        eq(null, kp_h($r, 'content-security-policy'), 'המעטפת היא עמוד של האתר — בלי I1 (החבילה ב-iframe)');
        eq('nosniff', kp_h($r, 'x-content-type-options'));
        eq('kol_maroko_v2', kp_h($r, 'x-litespeed-tag'), 'תגית המטמון — מתנקה עם כל פרסום / שינוי אופן');
    }
    // הבקשה של ה-iframe, וכל קובץ בשמו — הקובץ עצמו, עם כל הכותרות של M-A
    foreach ([['/lexicon/', 'iframe'], ['/lexicon/index.html', 'document'], ['/lexicon/index.html', 'iframe'], ['/lexicon/about/', 'iframe'], ['/lexicon/a.css', 'style']] as [$p, $d]) {
        $r = kp_nav($p, $d);
        eq(200, $r['status'], "$p ($d)");
        ok(!kp_is_wrap($r), "$p ($d) — לא מעטפת");
        eq([], kp_iso($r), "$p ($d) — I1–I7");
    }
    $r = kp_nav('/lexicon/about/');
    eq('/lexicon/about/index.html', kp_frame($r)['src'], 'כתובת עמוקה ← src של about/index.html');
    ok(str_contains($r['body'], '<title>אודות M</title>'), 'הכותרת של הדף העמוק');
    $r = kp_nav('/lexicon/' . rawurlencode('מילים') . '/');
    eq('/lexicon/' . rawurlencode('מילים') . '/index.html', kp_frame($r)['src'], 'נתיב בעברית — מקודד');
    // נתיב שאינו בחבילה — 404 של החבילה (לא מעטפת); נתיב בלי / בסוף — כמו ב-M-A
    $r = kp_nav('/lexicon/nope/');
    ok($r['status'] === 404 && !kp_is_wrap($r) && !str_contains($r['body'], 'SITE-HEADER'), '404: ' . $r['status']);
    eq(301, kp_nav('/lexicon')['status']);
    // HEAD — המעטפת, בלי גוף
    eq(200, kp_http('HEAD', '/lexicon/', ['Sec-Fetch-Dest: document'])['status']);
});

test('M4-BC <head> של המעטפת: הכותרת, התיאור ותגי השיתוף מועתקים כטקסט; http-equiv ושמות אחרים — לא', function () {
    kp_site();
    kp_reset();
    kp_live(kp_mount_bundle(), 1);
    kp_mount('framed');
    $r = kp_nav('/lexicon/');
    ok(str_contains($r['body'], '<title>שלום M &amp; ברוכים</title>'), 'title (מפוענח ונכתב שוב ב-esc_html)');
    ok(str_contains($r['body'], '<meta name="description" content="תיאור M">'), 'description');
    ok(str_contains($r['body'], '<meta property="og:title" content="שיתוף M">') && str_contains($r['body'], '<meta property="og:image" content="img/a.png">'), 'og:*');
    ok(!str_contains($r['body'], 'evil.example') && !str_contains($r['body'], 'http-equiv') && !str_contains($r['body'], 'generator'), 'http-equiv / generator — לא מועתקים');
    eq('שלום M &amp; ברוכים', kp_frame($r)['title'], 'title של ה-iframe (נגישות)');
});

test('M4-BC הזרקה: תווים מסוכנים בכותרת, בתיאור, בתגי השיתוף ובשמות הקבצים — לא יוצאים מהטקסט', function () {
    kp_site();
    kp_reset();
    // (</title> בתחילת הכותרת סוגר אותה גם בדפדפן — כותרת ריקה; לכן ההזרקה בתוך הטקסט)
    $evil = 'א<script>alert(1)</script>"\'&lt;/title&gt;&lt;x&gt;<img src=x onerror=alert(2)>';
    $b = [
        'index.html' => '<html><head><title>' . $evil . '</title><meta name="description" content="&quot;&gt;&lt;script&gt;alert(3)&lt;/script&gt;">'
            . '<meta property="og:title" content=\'"></script><script>alert(4)</script>\'><meta name="twitter:title" content="a&#10;b&#9;c"></head><body>x</body></html>',
        'a</script><img src=x onerror=alert(5)>/index.html' => '<title>t</title>',
        "q'\"u/index.html" => '<title>q</title>',
    ];
    kp_live($b, 1);
    kp_mount('framed');
    $r = kp_nav('/lexicon/');
    eq(200, $r['status']);
    $body = $r['body'];
    foreach (['<script>alert', '"><script', '</script><img', '<img src=x', '<img'] as $bad) ok(!str_contains($body, $bad), 'לא מופיע גולמי: ' . $bad);
    // בדיוק תג <script> אחד שלנו לבדיקת המסגרת, אחד לגשר, ואחד של התחתית — ושום תג אחר
    eq(3, substr_count(strtolower($body), '<script'), 'מספר תגי script: ' . substr_count(strtolower($body), '<script'));
    ok(preg_match('~<title>([^<]*)</title>~', $body, $m) && str_contains($m[1], '&lt;/title&gt;&lt;x&gt;') && str_contains($m[1], '&quot;'), 'הכותרת — טקסט: ' . ($m[1] ?? ''));
    ok(str_contains($body, 'content="&quot;&gt;&lt;script&gt;alert(3)&lt;/script&gt;"'), 'description — escape');
    ok(str_contains($body, 'content="a b c"'), 'תווי בקרה בתוכן ← רווח');
    // השמות ב-JSON של הגשר: בלי < > ' " גולמיים
    ok(preg_match('~<script>\(function\(C\)\{.*?\}\)\((\{.*?\})\);</script>~s', $body, $m), 'הגשר וההגדרות שלו');
    $cfg = $m[1] ?? '';
    ok(!str_contains($cfg, '<') && !str_contains($cfg, '>') && !str_contains($cfg, "'"), 'בלי < > \' ב-JSON: ' . $cfg);
    $j = json_decode($cfg, true);
    ok(is_array($j) && in_array('a</script><img src=x onerror=alert(5)>/index.html', $j['paths'], true) && in_array("q'\"u/index.html", $j['paths'], true), 'אחרי פענוח — אותם שמות');
    // src של דף עם שם מסוכן — מקודד
    $r2 = kp_nav('/lexicon/' . rawurlencode("q'\"u") . '/');
    eq('/lexicon/q%27%22u/index.html', kp_frame($r2)['src']);
    ok(!str_contains($r2['body'], "q'\"u/index.html\""), 'src — בלי מרכאות גולמיות');
});

test('M4-BC bar: הכותרת של האתר בלבד (בלי התחתית), iframe ממלא את המסך; full — כמו M-A; המפסק כבוי — העמוד המקורי בכל אופן', function () {
    kp_site();
    kp_reset();
    kp_live(kp_mount_bundle(), 1);
    kp_mount('bar');
    $r = kp_nav('/lexicon/');
    ok(kp_is_wrap($r) && str_contains($r['body'], 'SITE-HEADER') && !str_contains($r['body'], 'SITE-FOOTER'), 'bar — כותרת בלבד');
    ok(str_contains($r['body'], 'WP_FOOTER_RAN') && str_ends_with(rtrim($r['body']), '</html>'), 'wp_footer רץ והמסמך נסגר');
    ok(str_contains($r['body'], '"mode":"bar"'), 'הגשר יודע שהוא bar');
    kp_mount('full');
    $r = kp_nav('/lexicon/');
    ok(!kp_is_wrap($r) && str_contains($r['body'], 'VERSION-M'), 'full — החבילה עצמה');
    eq([], kp_iso($r));
    kp_mount('framed');
    kp_opt('kol_maroko_v2_on', false);
    $r = kp_nav('/lexicon/');
    ok(!kp_is_wrap($r) && str_contains($r['body'], 'WP-SITE-PAGE'), 'כבוי ← העמוד המקורי (גם ב-framed)');
    ok(!str_contains(kp_nav('/__site/other')['body'], 'kol-maroko-frame'), 'שאר האתר — ללא שינוי');
});

test('M4-BC כתובת הבדיקה ב-framed: מעטפת פרטית (no-store, noindex), וה-src נושא את האסימון — כך גם ה-iframe רואה את החדש', function () {
    kp_site();
    kp_reset();
    kp_live(kp_mount_bundle(), 1, false);
    kp_mount('framed');
    $tok = bin2hex(random_bytes(16));
    kp_opt('kol_maroko_v2_preview_hash', hash('sha256', $tok));
    $r = kp_nav('/lexicon/about/?kol_preview=' . $tok);
    ok(kp_is_wrap($r), 'מעטפת בכתובת הבדיקה');
    eq('/lexicon/about/index.html?kol_preview=' . $tok, kp_frame($r)['src']);
    eq('private, no-store', kp_h($r, 'cache-control'));
    eq('noindex, nofollow', kp_h($r, 'x-robots-tag'));
    ok(str_contains($r['body'], '<meta name="robots" content="noindex,nofollow">'));
    $f = kp_nav('/lexicon/about/index.html?kol_preview=' . $tok, 'iframe');
    ok(str_contains($f['body'], 'about M') && kp_h($f, 'content-security-policy') === KP_CSP, 'ה-iframe — החבילה, עם I1');
    ok(str_contains(kp_nav('/lexicon/about/')['body'], 'WP-SITE-PAGE'), 'בלי אסימון — הישן');
});
