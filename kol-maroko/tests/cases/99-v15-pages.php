<?php
/**
 * V15 — עמודים (lib/project_ops.php: pages_list, page_create, page_move, page_set_entry, page_set_404, pages_order).
 * כל בדיקה מול קריטריון ב-docs/spec-v2/slices/V15-page-settings-pages.md §2.4–§2.6: רשימה, יצירה (ריק / שכפול / הפניה),
 * שינוי כתובת עם עדכון קישורים (תצוגה, סימון, עסקה אחת, JS — לבדיקה ידנית), עמוד ראשי, דף 404, סדר, הגבול (400),
 * הרשאות; ו-§2.6: index + about + 404 — נבנה, ומוגש בתוסף האמיתי (tests/plugin/wp.php): /lexicon/ ← index,
 * /lexicon/about/ ← about, /lexicon/xyz ← 404.html בסטטוס 404, עם ה-<title>, og:image ו-favicon כמו שנכתבו.
 */

declare(strict_types=1);

require_once KOL_ROOT . '/lib/build.php';
require_once KOL_ROOT . '/tests/plugin/lib.php';

function v15_post(string $action, array $json, array $user = U_PUBLISHER): array
{
    return http('POST', "/api.php?action=$action", ['user' => $user, 'json' => $json]);
}
function v15_list(array $user = U_EDITOR): array
{
    $r = http('GET', '/api.php?action=pages_list', ['user' => $user]);
    if ($r['status'] !== 200) throw new TestFail('pages_list: ' . $r['body']);
    return $r['json'];
}
function v15_text(string $path): string { $r = pf_by_path($path); return $r ? pf_blob_get($r['sha']) : ''; }
function v15_put(string $path, string $content): void
{
    $r = pf_by_path($path);
    $res = $r ? http('POST', '/api.php?action=pf_write', ['user' => U_EDITOR, 'json' => ['id' => (int)$r['id'], 'rev' => (int)$r['rev'], 'content' => $content]])
        : http('POST', '/api.php?action=pf_create', ['user' => U_EDITOR, 'json' => ['path' => $path, 'content' => $content]]);
    if ($res['status'] !== 200) throw new TestFail("כתיבה $path: " . $res['body']);
}
function v15_page(string $title, string $extra = '', string $body = ''): string
{
    return "<!doctype html>\n<html lang=\"he\" dir=\"rtl\">\n<head>\n  <meta charset=\"utf-8\">\n  <title>$title</title>\n$extra</head>\n<body>\n$body</body>\n</html>\n";
}

test('V15 §2.4 רשימה: כל עמוד (auto — .html מחוץ ל-components/), שם מ-<title>, כתובת, ⭐ ראשי, 🚫 404; עורך תוכן קורא', function () {
    http('GET', '/api.php?action=pf_tree', ['user' => U_EDITOR]);
    v15_put('index.html', v15_page('קול מרוקו', '', "  <a href=\"about/\">אודות</a>\n  <a href=\"/lexicon/about/\">אודות מלא</a>\n  <a href=\"../lexicon/nothing/\">שבור</a>\n"));
    v15_put('components/v15-card/template.html', '<div>x</div>');
    $l = v15_list(U_CONTENT);
    $paths = array_column($l['pages'], 'path');
    ok(in_array('index.html', $paths, true) && !in_array('components/v15-card/template.html', $paths, true), 'index.html ברשימה; תבנית רכיב — לא');
    $ix = $l['pages'][0];
    eq(['index.html', '', 'קול מרוקו', true, false], [$ix['path'], $ix['url'], $ix['title'], $ix['entry'], $ix['is404']], 'הראשי ראשון ("auto")');
    eq('auto', $l['mode']);
    eq('/lexicon/', $l['base']);
    ok(isset($ix['at']), 'מתי שונה');
});

test('V15 §2.4 ➕ עמוד חדש: ריק (עם ה-CSS המשותף של styles), שכפול, הפניה; הכתובת ^[a-z0-9-]+(/…)*$; מפרסם ומעלה', function () {
    v15_put('css/v15.css', 'body{color:#123}');
    $kolRow = pf_by_path('kol.json');
    $kol = json_decode(pf_blob_get($kolRow['sha']), true);
    $kol['styles'] = ['base' => 'css/v15.css'];
    v15_put('kol.json', json_encode($kol, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    eq(403, v15_post('page_create', ['slug' => 'about', 'title' => 'אודות', 'start' => 'blank'], U_EDITOR)['status'], 'עורך — 403');
    $r = v15_post('page_create', ['slug' => 'about', 'title' => 'אודות <b>"', 'start' => 'blank']);
    eq(200, $r['status'], $r['body']);
    eq('about/', $r['json']['url']);
    $s = v15_text('about/index.html');
    ok(str_contains($s, '<title>אודות &lt;b&gt;&quot;</title>') && str_contains($s, '<link rel="stylesheet" href="../css/v15.css">'), 'שם מקודד; הקישור ל-CSS המשותף — יחסי מהתיקייה החדשה', $s);
    $h = db()->query("SELECT h.note FROM pf_history h JOIN pf_files f ON f.id = h.file_id WHERE f.path = 'about/index.html' AND f.deleted_at IS NULL ORDER BY h.id DESC LIMIT 1")->fetchColumn();
    eq('עמוד חדש', $h, 'גרסה בהיסטוריה');
    eq(409, v15_post('page_create', ['slug' => 'about', 'title' => 'שוב', 'start' => 'blank'])['status'], 'כתובת תפוסה — 409');
    // שכפול: הפניות יחסיות — מהמקום החדש
    v15_put('about/index.html', v15_page('אודות', "  <link rel=\"stylesheet\" href=\"../css/v15.css\">\n", "  <img src=\"../media/x.png\"><a href=\"../\">בית</a>\n"));
    v15_put('media/x.png', 'PNG');
    $c = v15_post('page_create', ['slug' => 'team/dana', 'title' => 'דנה', 'start' => 'copy', 'from' => 'about/index.html']);
    eq(200, $c['status'], $c['body']);
    $t = v15_text('team/dana/index.html');
    ok(str_contains($t, 'href="../../css/v15.css"') && str_contains($t, 'src="../../media/x.png"') && str_contains($t, '<title>דנה</title>'), 'שכפול לעומק אחר: ההפניות מתוקנות, השם חדש', $t);
});

test('V15 §2.4 ➕ מתבנית עמוד (V13: templates/<שם>/, kind: page) — ההפניות מתוקנות למקום החדש; תבנית מקטע — 400', function () {
    v15_put('templates/landing/template.json', '{"label":"דף נחיתה","kind":"page"}');
    v15_put('templates/landing/template.html', v15_page('נחיתה', "  <link rel=\"stylesheet\" href=\"css/v15.css\">\n", "  <img src=\"media/x.png\">\n"));
    v15_put('templates/strip/template.json', '{"label":"פס","kind":"section"}');
    v15_put('templates/strip/template.html', '<div>x</div>');
    $l = v15_list();
    eq([['name' => 'landing', 'label' => 'דף נחיתה']], $l['templates'], 'רק תבניות עמוד ברשימה');
    $r = v15_post('page_create', ['slug' => 'promo/summer', 'title' => 'קיץ', 'start' => 'tpl', 'template' => 'landing']);
    eq(200, $r['status'], $r['body']);
    $t = v15_text('promo/summer/index.html');
    ok(str_contains($t, 'href="../../css/v15.css"') && str_contains($t, 'src="../../media/x.png"') && str_contains($t, '<title>קיץ</title>'), 'מתבנית: הפניות מהמקום החדש, השם חדש', $t);
    eq(400, v15_post('page_create', ['slug' => 'x2', 'title' => 'x', 'start' => 'tpl', 'template' => 'strip'])['status'], 'תבנית מקטע — 400');
    eq(400, v15_post('page_create', ['slug' => 'x3', 'title' => 'x', 'start' => 'tpl', 'template' => '../landing'])['status'], 'שם תבנית עם ../ — 400');
    eq(404, v15_post('page_create', ['slug' => 'x4', 'title' => 'x', 'start' => 'tpl', 'template' => 'nope'])['status'], 'תבנית שאינה — 404');
});

test('V15 §2.4 הגבול: כתובת מחוץ ל-/lexicon/ — אין נתיב שמגיע לשם; השרת 400', function () {
    foreach (['../wp-admin', '/etc', 'a//b', 'a/../b', 'A', 'a b', 'a/', '', '..', 'about/index.html', "a\0", 'components/x', '%2e%2e', 'a_b', str_repeat('a', 130)] as $bad) {
        eq(400, v15_post('page_create', ['slug' => $bad, 'title' => 'x', 'start' => 'blank'])['status'], 'יצירה: ' . json_encode($bad));
        eq(400, v15_post('page_move', ['from' => 'about/index.html', 'to' => $bad])['status'], 'הזזה: ' . json_encode($bad));
    }
    eq(400, v15_post('page_create', ['slug' => 'x', 'title' => 'x', 'start' => 'copy', 'from' => '../../etc/passwd'])['status'], 'שכפול מנתיב חיצוני — 400');
    ok(!pf_by_path('wp-admin/index.html'), 'שום קובץ לא נוצר');
});

test('V15 §2.4 שינוי כתובת: about/ ← odot/ — הקובץ זז, הקישורים (about/, /lexicon/about/, ../about/) מתעדכנים בעסקה אחת; JS — לבדיקה ידנית', function () {
    v15_put('team/dana/index.html', v15_page('דנה', '', "  <a href=\"../../about/\">אודות</a>\n  <script>location.href = 'about/';</script>\n"));
    v15_put('js/v15-nav.js', "const go = () => fetch('/lexicon/about/data.json');\n");
    $p = v15_post('page_move', ['from' => 'about/index.html', 'to' => 'odot']);
    eq(200, $p['status'], $p['body']);
    $v = $p['json'];
    eq(['about/', 'odot/', 'about', 'odot'], [$v['from_url'], $v['to_url'], $v['unit']['from'], $v['unit']['to']]);
    $olds = array_map(fn($x) => $x['path'] . ' ' . $x['old'] . ' → ' . $x['new'], $v['links']);
    foreach (['index.html about/ → odot/', 'index.html /lexicon/about/ → /lexicon/odot/', 'team/dana/index.html ../../about/ → ../../odot/'] as $want) ok(in_array($want, $olds, true), "בתצוגה: $want", $olds);
    ok(!in_array('index.html ../lexicon/nothing/', array_column($v['links'], 'old'), true), 'קישור שכבר שבור — לא בתוכנית');
    $man = array_map(fn($x) => $x['path'], $v['manual']);
    ok(in_array('team/dana/index.html', $man, true) && in_array('js/v15-nav.js', $man, true), 'קישור שנכתב ב-JS — "לבדיקה ידנית"', $v['manual']);
    ok(pf_by_path('about/index.html') && !pf_by_path('odot/index.html'), 'תצוגה בלבד — שום דבר לא זז');
    // token ישן — 409; בחירה של חלק מהקישורים
    eq(409, v15_post('page_move', ['from' => 'about/index.html', 'to' => 'odot', 'apply' => true, 'token' => 'x'])['status'], 'אסימון לא תואם — 409');
    $skip = array_values(array_filter($v['links'], fn($x) => $x['old'] === '/lexicon/about/'))[0]['n'];
    $pick = array_values(array_diff(array_column($v['links'], 'n'), [$skip]));
    $a = v15_post('page_move', ['from' => 'about/index.html', 'to' => 'odot', 'apply' => true, 'token' => $v['token'], 'pick' => $pick, 'redirect' => true]);
    eq(200, $a['status'], $a['body']);
    ok(pf_by_path('odot/index.html') && str_contains(v15_text('odot/index.html'), '<title>אודות</title>'), 'הקובץ זז: ' . mb_substr(v15_text('odot/index.html'), 0, 300) . ' / ' . json_encode($a['json']['done'] ?? null, JSON_UNESCAPED_UNICODE));
    $ix = v15_text('index.html');
    ok(str_contains($ix, 'href="odot/"') && str_contains($ix, 'href="/lexicon/about/"'), 'הקישור שנבחר עודכן; זה שלא סומן — נשאר', $ix);
    ok(str_contains(v15_text('team/dana/index.html'), 'href="../../odot/"') && str_contains(v15_text('team/dana/index.html'), "location.href = 'about/'"), 'יחסי מעומק — עודכן; JS — לא נגעו');
    $red = v15_text('about/index.html');
    ok(str_contains($red, '<meta http-equiv="refresh" content="0; url=../odot/">') && str_contains($red, '<link rel="canonical" href="/lexicon/odot/">'), '"השאר הפניה": about/index.html עם refresh + canonical — קובץ רגיל', $red);
    $mv = db()->query("SELECT note FROM pf_history h JOIN pf_files f ON f.id = h.file_id WHERE f.path = 'odot/index.html' ORDER BY h.id DESC LIMIT 1")->fetchColumn();
    eq('שינוי כתובת מ-about/index.html', $mv);
    eq(400, v15_post('page_move', ['from' => 'index.html', 'to' => 'home'])['status'], 'index.html שבשורש — לא זז');
    // "השאר הפניה" מ"מה ישתנה" (build.js): page_create start=redirect
    $rd = v15_post('page_create', ['slug' => 'old-odot', 'start' => 'redirect', 'to' => 'odot/index.html']);
    eq(200, $rd['status'], $rd['body']);
    ok(str_contains(v15_text('old-odot/index.html'), 'content="0; url=../odot/"'), 'הפניה מכתובת ישנה — קובץ רגיל עם refresh');
    eq(400, v15_post('page_create', ['slug' => 'old-x', 'start' => 'redirect', 'to' => '../../wp-config.php'])['status'], 'הפניה אל ../ — 400');
    eq(404, v15_post('page_create', ['slug' => 'old-y', 'start' => 'redirect', 'to' => 'nope.html'])['status'], 'הפניה ליעד שאינו עמוד — 404');
    eq(403, v15_post('page_move', ['from' => 'odot/index.html', 'to' => 'x'], U_EDITOR)['status'], 'עורך — 403');
});

test('V15 §2.4 עמוד ראשי, דף 404, סדר ("auto" ← רשימה, וחזרה)', function () {
    eq(200, v15_post('page_set_entry', ['path' => 'odot/index.html'])['status']);
    $kol = json_decode(v15_text('kol.json'), true);
    eq('odot/index.html', $kol['entry'], 'entry ב-kol.json');
    ok(str_contains(v15_text('kol.json'), '"styles"'), 'שאר kol.json — נשמר');
    $l = v15_list();
    eq('odot/index.html', $l['pages'][0]['path'], 'הראשי — ראשון ומסומן');
    ok($l['pages'][0]['entry']);
    eq(200, v15_post('page_set_entry', ['path' => 'index.html'])['status']);
    eq(404, v15_post('page_set_entry', ['path' => 'css/v15.css'])['status'], 'קובץ שאינו עמוד — 404');
    // 404: מתבנית
    $n = v15_post('page_set_404', []);
    eq(200, $n['status'], $n['body']);
    ok(str_contains(v15_text('404.html'), '<a href="/lexicon/">לעמוד הראשי</a>'), 'תבנית "לא נמצא" עם קישור לראשי');
    eq(409, v15_post('page_set_404', ['path' => 'team/dana/index.html'])['status'], 'כבר יש 404.html — 409');
    ok((array_values(array_filter(v15_list()['pages'], fn($p) => $p['path'] === '404.html'))[0]['is404'] ?? false), '🚫 מסומן ברשימה');
    // סדר
    $o = v15_post('pages_order', ['order' => ['odot/index.html', 'index.html', '404.html']]);
    eq(200, $o['status'], $o['body']);
    eq('list', $o['json']['mode']);
    eq(['odot/index.html', 'index.html', '404.html'], array_slice(array_column($o['json']['pages'], 'path'), 0, 3), 'הסדר המפורש');
    ok(in_array('team/dana/index.html', array_column(array_filter($o['json']['pages'], fn($p) => !$p['listed']), 'path'), true), 'עמוד שאינו ברשימה — עדיין מוצג (מסומן)');
    eq(['odot/index.html', 'index.html', '404.html'], json_decode(v15_text('kol.json'), true)['pages']);
    eq(400, v15_post('pages_order', ['order' => ['../x.html']])['status'], 'נתיב לא חוקי — 400');
    eq(400, v15_post('pages_order', ['order' => ['css/v15.css']])['status'], 'לא עמוד — 400');
    $c = v15_post('page_create', ['slug' => 'more', 'title' => 'עוד', 'start' => 'blank']);
    eq(200, $c['status']);
    eq('more/index.html', array_slice(json_decode(v15_text('kol.json'), true)['pages'], -1)[0], 'עמוד חדש ברשימה מפורשת — נוסף בסופה');
    eq('auto', v15_post('pages_order', ['order' => 'auto'])['json']['mode'], '"חזור לאוטומטי"');
});

test('V15 §2.5 קישור לעמוד שנמחק — "קישור שבור: about/"', function () {
    v15_put('index.html', v15_page('קול מרוקו', '', "  <a href=\"odot/\">אודות</a>\n  <a href=\"gone/\">נמחק</a>\n  <a href=\"/lexicon/gone2/\">נמחק</a>\n  <a href=\"https://example.com/x/\">חוץ</a>\n  <a href=\"#top\">עוגן</a>\n"));
    $b = array_column(array_filter(v15_list()['broken'], fn($x) => $x['path'] === 'index.html'), 'msg');
    ok(in_array('קישור שבור: gone/', $b, true) && in_array('קישור שבור: /lexicon/gone2/', $b, true) && count($b) === 2, 'שני השבורים בלבד', $b);
});

test('V15 §2.6 index + about + 404 — נבנה, ומוגש בתוסף האמיתי: /lexicon/ · /lexicon/about/ · /lexicon/xyz ← 404.html (404); title / og:image / favicon כמו בטופס', function () {
    $head = "  <meta name=\"description\" content=\"מילון\">\n  <meta property=\"og:image\" content=\"media/share.png\">\n  <link rel=\"icon\" href=\"media/icon.png\">\n  <link rel=\"apple-touch-icon\" href=\"media/icon.png\">\n";
    v15_put('index.html', v15_page('מילון מרוקאי', $head, "  <a href=\"about/\">אודות</a>\n"));
    if (!pf_by_path('about/index.html') || str_contains(v15_text('about/index.html'), 'http-equiv')) v15_put('about/index.html', v15_page('אודות', '', "  <p>ABOUT-PAGE</p>\n"));
    v15_put('404.html', v15_page('לא נמצא', '', "  <p>OUR-404</p>\n"));
    v15_put('media/share.png', 'PNG-SHARE');
    v15_put('media/icon.png', 'PNG-ICON');
    $s = http('POST', '/api.php?action=build_start', ['user' => U_EDITOR, 'json' => ['prerender' => false]]);
    eq(200, $s['status'], $s['body']);
    $f = http('POST', '/api.php?action=build_finish', ['user' => U_EDITOR, 'json' => ['id' => $s['json']['id']]]);
    eq(200, $f['status'], $f['body']);
    $files = json_decode(build_row((int)$s['json']['id'])['files'], true);
    foreach (['index.html', 'about/index.html', '404.html', 'media/share.png', 'media/icon.png'] as $p) ok(isset($files[$p]), "בחבילה: $p");
    $bundle = [];
    foreach (['index.html', 'about/index.html', '404.html', 'media/share.png', 'media/icon.png'] as $p) $bundle[$p] = pf_blob_get($files[$p]['sha']);
    kp_site();
    kp_reset();
    kp_live($bundle, 1);
    $ix = kp_http('GET', '/lexicon/');
    eq(200, $ix['status']);
    ok(str_contains($ix['body'], '<title>מילון מרוקאי</title>') && str_contains($ix['body'], '<meta property="og:image" content="media/share.png">') && str_contains($ix['body'], '<link rel="icon" href="media/icon.png">'), '/lexicon/ ← index, עם title / og:image / favicon', $ix['body']);
    eq('PNG-SHARE', kp_http('GET', '/lexicon/media/share.png')['body'], 'תמונת השיתוף מוגשת');
    $ab = kp_http('GET', '/lexicon/about/');
    ok($ab['status'] === 200 && str_contains($ab['body'], 'ABOUT-PAGE'), '/lexicon/about/ ← about/index.html', $ab['status']);
    $nf = kp_http('GET', '/lexicon/xyz');
    eq([404, true], [$nf['status'], str_contains($nf['body'], 'OUR-404')], '/lexicon/xyz ← 404.html בסטטוס 404');
    kp_reset();
});

test('V15 ניקוי: kol.json חוזר ל-entry index.html / pages auto (בדיקות אחרות)', function () {
    $kol = json_decode(v15_text('kol.json'), true);
    $kol['entry'] = 'index.html'; $kol['pages'] = 'auto'; unset($kol['styles']);
    v15_put('kol.json', json_encode($kol, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    ok(true);
});
