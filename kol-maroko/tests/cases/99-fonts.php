<?php
/**
 * פרוסה V9 — גופנים (lib/fonts.php): העלאה (חתימה, שם, נתיב), קריאת name / OS/2 / fvar, ובקשות יוצאות — רק ל-
 * fonts.googleapis.com / fonts.gstatic.com, https, בלי הפניות, בלי כתובת פנימית, בגודל מוגבל. בלי רשת: $GLOBALS['kol_font_fake'].
 * הקבצים ב-tests/fixtures/fonts — תת-קבוצות קטנות של Heebo / Roboto (OFL / Apache) מ-Google Fonts.
 */

declare(strict_types=1);

require_once KOL_ROOT . '/lib/fonts.php';

const V9_ED = ['sub' => 'u-editor', 'name' => 'עורך בדיקה', 'role' => 'editor'];
const V9_PUB = ['fonts.googleapis.com' => ['142.250.75.10'], 'fonts.gstatic.com' => ['142.250.75.3']];

function v9_fx(string $n): string { return (string)file_get_contents(KOL_ROOT . '/tests/fixtures/fonts/' . $n); }

/** ttf ← woff (zlib לכל טבלה), כמו שכלי המרה עושה — כדי לבדוק גם את הקריאה מ-woff. */
function v9_woff(string $ttf): string
{
    $n = unpack('n', $ttf, 4)[1]; $dir = ''; $data = ''; $off = 44 + 20 * $n;
    for ($i = 0; $i < $n; $i++) {
        $r = 12 + 16 * $i; $tag = substr($ttf, $r, 4); [, $sum, $o, $len] = array_values(unpack('a4t/Nsum/No/Nl', $ttf, $r));
        $raw = substr($ttf, $o, $len); $z = gzcompress($raw); if (strlen($z) >= $len) $z = $raw;
        $dir .= $tag . pack('NNNN', $off + strlen($data), strlen($z), $len, $sum);
        $data .= $z . str_repeat("\0", (4 - strlen($z) % 4) % 4);
    }
    $total = 44 + strlen($dir) + strlen($data);
    return 'wOFF' . substr($ttf, 0, 4) . pack('NnnNnnNNNNN', $total, $n, 0, strlen($ttf), 1, 0, 0, 0, 0, 0, 0) . $dir . $data;
}

function v9_fake(array $map, ?array &$seen = null): void
{
    $seen = [];
    $GLOBALS['kol_font_fake'] = function (string $url, string $ip) use ($map, &$seen) {
        $seen[] = [$url, $ip];
        foreach ($map as $pre => $resp) if (str_starts_with($url, $pre)) return is_callable($resp) ? $resp($url) : $resp;
        return [404, ''];
    };
}

test('V9 גופן: הסוג לפי החתימה (woff2 / woff / ttf / otf), לא לפי השם; כל דבר אחר — null', function () {
    eq('woff2', font_kind(v9_fx('heebo-bold-sub.woff2')));
    eq('ttf', font_kind(v9_fx('heebo-bold-sub.ttf')));
    eq('woff', font_kind(v9_woff(v9_fx('heebo-bold-sub.ttf'))));
    eq('otf', font_kind('OTTO' . str_repeat("\0", 20)));
    eq('ttf', font_kind('true' . str_repeat("\0", 20)));
    foreach (['<html><script>alert(1)</script>', "\x89PNG\r\n", 'GIF89a', "PK\x03\x04", '%PDF', '', 'wOF', "\0\1\0"] as $bad) eq(null, font_kind($bad), 'לא גופן: ' . bin2hex(substr($bad, 0, 4)));
});

test('V9 גופן: name / OS/2 — משפחה, משקל, נטוי, עברית (ttf ו-woff); woff2 — ניחוש מהשם', function () {
    $i = font_info(v9_fx('heebo-bold-sub.ttf'), 'ttf', 'x.ttf');
    eq(['Heebo', 700, 'normal', true], [$i['family'], $i['weight'], $i['style'], $i['hebrew']], 'Heebo Bold');
    $w = font_info(v9_woff(v9_fx('heebo-bold-sub.ttf')), 'woff', 'whatever.woff');
    eq(['Heebo', 700, true], [$w['family'], $w['weight'], $w['hebrew']], 'אותו קובץ כ-woff (zlib)');
    $r = font_info(v9_fx('roboto-italic-sub.ttf'), 'ttf', 'r.ttf');
    eq(['Roboto', 400, 'italic', false], [$r['family'], $r['weight'], $r['style'], $r['hebrew']], 'Roboto Italic — בלי עברית');
    $g = font_info(v9_fx('heebo-bold-sub.woff2'), 'woff2', 'Frank-Ruhl-Libre-SemiBold-Italic.woff2');
    eq(['Frank Ruhl Libre', 600, 'italic', null], [$g['family'], $g['weight'], $g['style'], $g['hebrew']], 'woff2: מהשם');
    $q = font_info(v9_fx('heebo-bold-sub.woff2'), 'woff2', "x');}<b>\".woff2");
    ok(preg_match('/^[\p{L}\p{N} _-]+$/u', $q['family']) === 1, 'שם מזיק מנוקה: ' . $q['family']);
});

test('V9 גופן: שם משפחה לכתיבה ב-CSS — אותיות, ספרות, רווח, מקף; מרכאות, <, ;, {}, () — נדחים', function () {
    eq('Frank Ruhl Libre', font_family_ok('  Frank   Ruhl Libre '));
    eq('גופן שלי 2', font_family_ok('גופן שלי 2'));
    foreach (["A'B", 'A"B', 'A<b', 'A;color:red', 'A}x{', 'url(x)', "A\nB", str_repeat('a', 61), '', 'A/B', 'A\\B', 'A,B'] as $bad) throws(fn() => font_family_ok($bad), 'נדחה: ' . $bad);
    eq([400, 700], font_weights(['700', 400, 700]));
    throws(fn() => font_weights(['400;color:red']));
    throws(fn() => font_weights([1200]));
    eq('heebo-700', font_slug('Heebo 700'));
    eq('font', font_slug('גופן'));
    eq('etc-passwd', font_slug('../../etc/passwd'));
});

test('V9 העלאה: נשמר ב-media/fonts/<slug>.<סיומת מהחתימה>, כמו שהוא; כפילות ← -2; לא גופן ← 415; מעל 500KB — הערה', function () {
    pf_seed(V9_ED);
    $b = v9_fx('heebo-bold-sub.ttf');
    $o = font_upload(V9_ED, ['name' => '../../evil.php', 'content_b64' => base64_encode($b)]);
    eq('media/fonts/heebo-700.ttf', $o['file']['path']);
    eq($b, pf_blob_get(pf_by_path('media/fonts/heebo-700.ttf')['sha']), 'הבייטים כמו שהם');
    eq('truetype', $o['format']);
    $o2 = font_upload(V9_ED, ['name' => 'Heebo-Bold.ttf', 'content_b64' => base64_encode($b)]);
    eq('media/fonts/heebo-700-2.ttf', $o2['file']['path'], 'שם תפוס');
    $o3 = font_upload(V9_ED, ['name' => 'heebo.png', 'content_b64' => base64_encode(v9_fx('heebo-bold-sub.woff2'))]);
    ok(str_starts_with($o3['file']['path'], 'media/fonts/heebo') && str_ends_with($o3['file']['path'], '.woff2'), 'הסיומת מהחתימה, לא מהשם: ' . $o3['file']['path']);
    eq(415, throws(fn() => font_upload(V9_ED, ['name' => 'x.woff2', 'content_b64' => base64_encode('<svg onload=alert(1)>')])));
    $r = font_upload(V9_ED, ['name' => 'r.ttf', 'content_b64' => base64_encode(v9_fx('roboto-italic-sub.ttf'))]);
    eq('media/fonts/roboto-400-italic.ttf', $r['file']['path']);
    ok(count($r['notes']) === 1 && str_contains($r['notes'][0], 'גופן הגיבוי'), 'בלי עברית — הערה');
    $big = $b . str_repeat("\0", 520 * 1024);
    ok(str_contains(implode(' ', font_upload(V9_ED, ['name' => 'b.ttf', 'content_b64' => base64_encode($big)])['notes']), 'כבד'), 'מעל 500KB — הערה');
    eq(413, throws(fn() => font_upload(V9_ED, ['name' => 'c.ttf', 'content_b64' => base64_encode($b . str_repeat("\0", FONT_MAX))])));
    foreach (pf_tree(V9_ED)['files'] as $f) if (str_starts_with($f['path'], 'media/fonts/')) ok(preg_match('~^media/fonts/[a-z0-9-]+\.(woff2|woff|ttf|otf)$~', $f['path']) === 1, 'נתיב: ' . $f['path']);
});

test('V9 בקשות יוצאות: רק https://fonts.googleapis.com / fonts.gstatic.com; פורט, משתמש, ".." ומארח אחר — נדחים', function () {
    eq('fonts.gstatic.com', font_url_ok('https://fonts.gstatic.com/s/heebo/v28/abc_-.woff2'));
    eq('fonts.googleapis.com', font_url_ok('https://fonts.googleapis.com/css2?family=Heebo:wght@400;700&display=swap'));
    foreach (['http://fonts.gstatic.com/s/x.woff2', 'https://evil.example/s/x.woff2', 'https://fonts.gstatic.com.evil.example/x', 'https://fonts.gstatic.com:8443/x',
        'https://user@fonts.gstatic.com/x', 'https://fonts.gstatic.com@127.0.0.1/x', 'https://127.0.0.1/x', 'https://fonts.gstatic.com/../x', 'https://fonts.gstatic.com/a/%2e%2e/x',
        'https://evil.example\\@fonts.gstatic.com/x', 'ftp://fonts.gstatic.com/x', 'file:///etc/passwd', 'gopher://fonts.gstatic.com/x', '//fonts.gstatic.com/x',
        'https://FONTS.gstatic.com/x', 'https://fonts.gstatic.com/x#y', 'https://fonts.gstatic.com/x?a=<b>', 'https://[::1]/x', 'https://fonts.gstatic.com/x y'] as $bad) {
        throws(fn() => font_url_ok($bad), 'נדחה: ' . $bad);
    }
});

test('V9 בקשות יוצאות: כתובת IP פנימית / שמורה — נחסמת (גם כשהמארח הנכון נפתר אליה)', function () {
    foreach (['127.0.0.1', '10.1.2.3', '172.16.0.1', '192.168.1.1', '169.254.169.254', '0.0.0.0', '100.64.0.1', '::1', 'fc00::1', 'fe80::1', '::ffff:127.0.0.1', '224.0.0.1', '255.255.255.255'] as $ip) ok(!font_ip_ok($ip), "פנימית: $ip");
    foreach (['142.250.75.10', '8.8.8.8', '2a00:1450:4001:800::2003'] as $ip) ok(font_ip_ok($ip), "ציבורית: $ip");
    v9_fake(['https://' => [200, 'x']], $seen);
    foreach (['127.0.0.1', '169.254.169.254', '10.0.0.5'] as $ip) {
        eq(502, throws(fn() => font_http('https://fonts.gstatic.com/s/x.woff2', 100, ['fonts.gstatic.com' => [$ip]])), "נפתר ל-$ip");
    }
    eq(502, throws(fn() => font_http('https://fonts.gstatic.com/s/x.woff2', 100, ['fonts.gstatic.com' => ['142.250.75.3', '127.0.0.1']])), 'אחת מהכתובות פנימית');
    eq([], $seen, 'שום בקשה לא יצאה');
    unset($GLOBALS['kol_font_fake']);
    // curl אמיתי לשרת מקומי: נחסם לפני החיבור (אותה בדיקה, בלי fake)
    $hit = false;
    try { font_http('https://fonts.gstatic.com/s/x.woff2', 100, ['fonts.gstatic.com' => ['127.0.0.1']]); } catch (ApiError $e) { $hit = str_contains($e->getMessage(), 'פנימית'); }
    ok($hit, 'curl אמיתי: נחסם לפני החיבור');
});

test('V9 בקשות יוצאות: הפניה (3xx) אינה נעקבת; גודל מעל המותר — נעצר; 404 — "אין גופן כזה"', function () {
    v9_fake(['https://fonts.gstatic.com/s/redir' => [302, ''], 'https://fonts.gstatic.com/s/big' => [200, str_repeat('x', 2000)], 'https://fonts.gstatic.com/s/ok' => [200, 'wOF2abc']]);
    eq(502, throws(fn() => font_http('https://fonts.gstatic.com/s/redir.woff2', 1000, V9_PUB)));
    eq(413, throws(fn() => font_http('https://fonts.gstatic.com/s/big.woff2', 1000, V9_PUB)));
    eq(404, throws(fn() => font_http('https://fonts.gstatic.com/s/none.woff2', 1000, V9_PUB)));
    eq('wOF2abc', font_http('https://fonts.gstatic.com/s/ok.woff2', 1000, V9_PUB));
    unset($GLOBALS['kol_font_fake']);
    // curl אמיתי: CURLOPT_FOLLOWLOCATION כבוי ו-https בלבד — נבדק בקוד (אין דרך לבדוק https מקומי בלי תעודה)
    $src = (string)file_get_contents(KOL_ROOT . '/lib/fonts.php');
    ok(str_contains($src, 'CURLOPT_FOLLOWLOCATION => false') && str_contains($src, 'CURLOPT_PROTOCOLS => CURLPROTO_HTTPS') && str_contains($src, 'CURLOPT_RESOLVE'), 'curl: בלי הפניות, https בלבד, נעול לכתובת שנבדקה');
});

test('V9 Google: css2 נבנה בשרת משם שעבר תבנית; ה-src מה-CSS נבדק (מארח אחר / http — נדחה)', function () {
    eq('https://fonts.googleapis.com/css2?family=Frank+Ruhl+Libre:wght@400;700&display=swap', font_css2_url('Frank Ruhl Libre', [700, 400]));
    eq('https://fonts.googleapis.com/css2?family=Rubik:ital,wght@0,400;1,400&display=swap', font_css2_url('Rubik', [400], true));
    throws(fn() => font_css2_url('גופן', [400]), 'עברית — לא שם של Google');
    throws(fn() => font_css2_url('Heebo&family=X', [400]));
    $css = "/* hebrew */\n@font-face {\n  font-family: 'Heebo';\n  font-style: normal;\n  font-weight: 700;\n  font-display: swap;\n  src: url(https://fonts.gstatic.com/s/heebo/v28/a.woff2) format('woff2');\n  unicode-range: U+0307-0308, U+0590-05FF;\n}\n";
    $f = font_css2_parse($css);
    eq([['Heebo', 'normal', '700', 'https://fonts.gstatic.com/s/heebo/v28/a.woff2', 'U+0307-0308, U+0590-05FF', 'hebrew', 'woff2']], array_map('array_values', $f));
    throws(fn() => font_css2_parse(str_replace('https://fonts.gstatic.com', 'https://evil.example', $css)));
    throws(fn() => font_css2_parse(str_replace('https://fonts.gstatic.com', 'https://fonts.googleapis.com', $css)), 'קובץ רק מ-gstatic');
    eq([], font_css2_parse(str_replace('https://', 'http://', $css)), 'http — אינו נלקח');
    $inj = font_css2_parse(str_replace('U+0307-0308, U+0590-05FF', 'U+0590; } body { color: red', $css));
    ok(count($inj) === 1 && preg_match('/^[Uu]\+[0-9A-Fa-f?\-, Uu+]+$/', $inj[0]['range']) === 1, 'unicode-range מוזרק — רק החלק התקין נשאר');
    eq('', font_css2_parse(str_replace('U+0307-0308, U+0590-05FF', 'U+0590 url(x)', $css))[0]['range'], 'unicode-range לא תקין — נזרק');
});

test('V9 "הבא לפרויקט": כל קובצי ה-woff2 נשמרים ב-media/fonts/; כשל באמצע — מה שנשמר עובר לסל', function () {
    pf_seed(V9_ED);
    $w2 = v9_fx('heebo-bold-sub.woff2');
    $css = '';
    foreach (['hebrew', 'latin'] as $sub) foreach ([400, 700] as $w) $css .= "/* $sub */\n@font-face { font-family: 'Heebo'; font-style: normal; font-weight: $w; src: url(https://fonts.gstatic.com/s/heebo/v28/$sub-$w.woff2) format('woff2'); unicode-range: U+0590-05FF; }\n";
    v9_fake(['https://fonts.googleapis.com/css2?family=Heebo:wght@100;' => [200, str_replace('font-weight: 400', 'font-weight: 900', $css)], 'https://fonts.googleapis.com/css2?family=Heebo:wght@400;700' => [200, $css], 'https://fonts.googleapis.com/css2?family=Heebo:wght@700' => [200, $css], 'https://fonts.gstatic.com/' => [200, $w2]], $seen);
    $GLOBALS['kol_font_resolve'] = V9_PUB;
    $o = font_fetch(V9_ED, ['family' => 'Heebo', 'weights' => [400, 700]]);
    eq(4, count($o['files']));
    eq(['media/fonts/heebo-400-hebrew.woff2', 'media/fonts/heebo-700-hebrew.woff2', 'media/fonts/heebo-400-latin.woff2', 'media/fonts/heebo-700-latin.woff2'], array_map(fn($f) => $f['file']['path'], $o['files']));
    eq($w2, pf_blob_get(pf_by_path('media/fonts/heebo-700-latin.woff2')['sha']));
    eq(6, count($seen), 'שתי בקשות ל-css2 (המשקלים שבגופן, הנבחרים) וארבע ל-gstatic — לא יותר');
    eq(['family' => 'Heebo', 'weights' => [700], 'available' => [700, 900], 'italic' => false, 'href' => 'https://fonts.googleapis.com/css2?family=Heebo:wght@700&display=swap'], font_google_add(V9_ED, ['family' => 'Heebo', 'weights' => [300, 700]]), 'משקל שאינו בגופן — נשמט');
    // הקובץ הרביעי אינו גופן ← שגיאה, ושלושת הראשונים לסל
    v9_fake(['https://fonts.googleapis.com/' => [200, str_replace('Heebo', 'Rubik', $css)], 'https://fonts.gstatic.com/s/heebo/v28/latin-700' => [200, '<html>'], 'https://fonts.gstatic.com/' => [200, $w2]]);
    throws(fn() => font_fetch(V9_ED, ['family' => 'Rubik', 'weights' => [400, 700]]));
    eq(0, count(array_filter(pf_tree(V9_ED)['files'], fn($f) => str_starts_with($f['path'], 'media/fonts/rubik'))), 'אין קבצים חלקיים בפרויקט');
    unset($GLOBALS['kol_font_fake'], $GLOBALS['kol_font_resolve']);
});

test('V9 api: font_upload דרך השרת — לא גופן ← 415; גופן ← נוצר; עורכת תוכן — 403', function () {
    $r = http('POST', '/api.php?action=font_upload', ['user' => U_EDITOR, 'json' => ['name' => 'a.woff2', 'content_b64' => base64_encode('<html>')]]);
    eq(415, $r['status']);
    $r = http('POST', '/api.php?action=font_upload', ['user' => U_EDITOR, 'json' => ['name' => 'Heebo.woff2', 'content_b64' => base64_encode(v9_fx('heebo-bold-sub.woff2'))]]);
    eq(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    ok(str_starts_with($r['json']['file']['path'], 'media/fonts/'), $r['json']['file']['path'] ?? '');
    eq(403, http('POST', '/api.php?action=font_upload', ['user' => U_CONTENT, 'json' => []])['status']);
    $l = http('GET', '/api.php?action=font_google_list', ['user' => U_CONTENT]);
    ok($l['status'] === 200 && count(array_filter($l['json']['fonts'], fn($f) => $f['hebrew'])) >= 20, 'רשימת Google — 20+ עם עברית');
});
