<?php
/**
 * פרוסה X1 §2.3 — 📝 טקסט ארוך כאזור בקובץ (lib/longtext.php). עורך התמלילים מדומה ($GLOBALS['kol_wp_fake']) — בלי רשת.
 */

declare(strict_types=1);

final class X1FakeWp
{
    public array $docs = [];
    public array $imports = [];
    private int $n = 0;
    public function embedSession(?string $doc, array $user, string $can = 'edit', string $mode = 'document', ?array $page = null, string $title = ''): array
    {
        $doc ??= 'doc-new-' . ++$this->n;
        $this->docs[$doc] ??= ['html' => '', 'css' => '', 'title' => $title];
        return ['embed_url' => 'https://wp.example/embed?doc=' . $doc, 'doc' => $doc];
    }
    public function export(string $doc, string $format): array { return $this->docs[$doc] ?? throw new WordProcessorError('אין מסמך', 'not_found', 404); }
    public function import(string $doc, string $format, string $content): array { $this->imports[] = [$doc, $content]; $this->docs[$doc]['html'] = $content; return ['ok' => true]; }
}

function x1_wp(): X1FakeWp
{
    return $GLOBALS['kol_wp_fake'] ??= new X1FakeWp();
}

const X1_PAGE = "<!doctype html>\n<html lang=\"he\" dir=\"rtl\">\n<head>\n  <meta charset=\"utf-8\">\n  <title>t</title>\n</head>\n<body>\n  <main class=\"page\">\n    <h1>כותרת</h1>\n    <section class=\"intro\"><p>מבוא שהוסב</p></section>\n  </main>\n</body>\n</html>\n";

const X1_HOSTILE = '<p onclick="steal()">שלום <a href="javascript:alert(1)">א</a> <a href="https://ok.example/x" onmouseover="x()">ב</a></p>'
    . '<script>alert(1)</script><img src="x" onerror="alert(1)"><img src="https://ok.example/i.png" alt="ת">'
    . '<iframe src="https://evil.example"></iframe><svg><script>alert(2)</script></svg><style>body{display:none}</style>'
    . '<div data-kol-longtext="other" id="x" style="background:url(https://evil.example/t)">פנימי</div>'
    . '<form action="https://evil.example"><input name="p"></form><marquee>מוזר</marquee><!-- הערה --></div></div><p style="color:red">אדום</p>'
    . '<a href=" JaVaScRiPt:alert(3)">ג</a><object data="x"></object><math><mi>x</mi></math><template><script>alert(4)</script></template>'
    . '<p class="a&quot; onclick=&quot;x">ד</p><base href="https://evil.example/"><meta http-equiv="refresh" content="0;url=https://evil.example">';

test('X1 📝: ניקוי לפי רשימה מותרת — payload עוין: בלי script / on* / javascript: / iframe / svg / style / form / data-* / הערות', function () {
    $out = sanitize_doc(X1_HOSTILE);
    foreach (['<script', 'onclick', 'onerror', 'onmouseover', 'javascript:', '<iframe', '<svg', '<style', '<form', '<input', 'data-kol', 'id="x"', '<!--', 'url(',
        '<marquee', '<object', '<math', '<template', '<base', '<meta', 'alert('] as $bad) ok(!str_contains(strtolower($out), strtolower($bad)), "נשאר: $bad\n$out");
    ok(str_contains($out, 'שלום'), 'הטקסט נשאר');
    ok(str_contains($out, 'מוזר'), 'תגית לא מוכרת נפרקת — התוכן נשאר');
    ok(str_contains($out, 'href="https://ok.example/x"'));
    ok(str_contains($out, 'rel="noopener noreferrer"'));
    ok(str_contains($out, 'src="https://ok.example/i.png"'));
    ok(str_contains($out, '<p style="color:red">אדום</p>'));
    ok(!preg_match('/<\/div>.*<\/div>.*<\/div>/s', $out) || substr_count($out, '<div') === substr_count($out, '</div>'), 'התגיות מאוזנות');
    eq(substr_count($out, '<div'), substr_count($out, '</div>'), 'div מאוזן — האזור לא נשבר');
    $css = sanitize_css('.wp-doc p{color:red} .x{background:url(https://evil.example/a)} @import "x.css"; </style><script>');
    ok(!str_contains($css, 'url(') && !str_contains($css, '@import') && !str_contains($css, '</style'), "CSS: $css");
    ok(str_contains($css, '.wp-doc p{color:red}'), 'חוק תקין נשאר');
});

test('X1 📝: אזור חדש בסוף <main>, סנכרון — התוכן נכתב בקובץ, CSS ב-css/longtext/<doc>.css ו-<link> ב-<head> פעם אחת; גרסה "📝 <שם>"', function () {
    $wp = x1_wp();
    $r = x1_file('lt/a.html', X1_PAGE);
    $o = longtext_open(X1_ED, ['path' => 'lt/a.html']);
    $doc = $o['doc'];
    ok(str_starts_with($o['embed_url'], 'https://wp.example/'));
    $html = pf_blob_get(pf_by_path('lt/a.html')['sha']);
    ok(preg_match('~</section>\n    <div data-kol-longtext="' . $doc . '"></div>\n  </main>~', $html), 'בסוף <main>, בשורה משלו');
    $wp->docs[$doc] = ['html' => X1_HOSTILE . '<h2>המבוא</h2><p>משפט.</p>', 'css' => '.wp-doc h2{color:#a33}', 'title' => 'המבוא'];
    $s = longtext_sync(X1_ED, ['doc' => $doc, 'path' => 'lt/a.html']);
    $html = pf_blob_get(pf_by_path('lt/a.html')['sha']);
    ok(str_contains($html, '<h2>המבוא</h2><p>משפט.</p></div>'), 'ה-HTML של המסמך כתוב בקובץ');
    ok(!str_contains($html, '<script') && !str_contains($html, 'onclick'), 'מנוקה');
    eq(1, substr_count($html, 'href="../css/longtext/' . $doc . '.css"'), '<link> יחסי, פעם אחת');
    ok(strpos($html, 'css/longtext/') < strpos($html, '</head>'));
    eq('.wp-doc h2{color:#a33}', pf_blob_get(pf_by_path("css/longtext/$doc.css")['sha']));
    $h = x1_hist('lt/a.html');
    eq('📝 המבוא', end($h)['note']);
    // סנכרון שני — אין <link> כפול, והאזור מוחלף ולא נוסף
    $wp->docs[$doc]['html'] = '<p>גרסה 2</p>';
    longtext_sync(X1_ED, ['doc' => $doc, 'path' => 'lt/a.html']);
    $html = pf_blob_get(pf_by_path('lt/a.html')['sha']);
    eq(1, substr_count($html, 'css/longtext/' . $doc . '.css'));
    ok(str_contains($html, '<div data-kol-longtext="' . $doc . '"><p>גרסה 2</p></div>'));
    ok(!str_contains($html, 'המבוא'));
    eq(1, substr_count($html, '</main>'), 'המבנה סביב האזור לא נשבר');
});

test('X1 📝: עריכה ביד מאז הסנכרון — 409 עם diff (שני הצדדים), ושום דבר לא נכתב; "דרוס" כותב; "ייבא" שולח לעורך ולא נוגע בקובץ', function () {
    $wp = x1_wp();
    x1_file('lt/b.html', X1_PAGE);
    $doc = longtext_open(X1_ED, ['path' => 'lt/b.html'])['doc'];
    $wp->docs[$doc] = ['html' => '<p>מהעורך</p>', 'css' => '', 'title' => 'ב'];
    longtext_sync(X1_ED, ['doc' => $doc, 'path' => 'lt/b.html']);
    // עריכה ביד בקובץ
    $r = pf_by_path('lt/b.html');
    x1_file('lt/b.html', str_replace('<p>מהעורך</p>', '<p>מהעורך — ותיקון ביד</p>', pf_blob_get($r['sha'])));
    $rev = (int)pf_by_path('lt/b.html')['rev'];
    $wp->docs[$doc]['html'] = '<p>מהעורך, גרסה חדשה</p>';
    $code = 0; $x = [];
    try { longtext_sync(X1_ED, ['doc' => $doc, 'path' => 'lt/b.html']); } catch (ApiError $e) { $code = $e->getCode(); $x = $e->extra['conflict']; }
    eq(409, $code, 'לעולם לא דריסה שקטה');
    eq('<p>מהעורך — ותיקון ביד</p>', $x['file']);
    eq('<p>מהעורך, גרסה חדשה</p>', $x['editor']);
    eq(['overwrite', 'import', 'cancel'], $x['options']);
    eq($rev, (int)pf_by_path('lt/b.html')['rev'], 'לא נכתב');
    // ייבא
    $i = longtext_sync(X1_ED, ['doc' => $doc, 'path' => 'lt/b.html', 'mode' => 'import']);
    eq(true, $i['reopen']);
    eq([$doc, '<p>מהעורך — ותיקון ביד</p>'], end($wp->imports));
    eq($rev, (int)pf_by_path('lt/b.html')['rev'], 'ייבוא אינו כותב לקובץ');
    longtext_sync(X1_ED, ['doc' => $doc, 'path' => 'lt/b.html']);          // אחרי הייבוא — אין התנגשות
    // התנגשות שוב → דרוס
    x1_file('lt/b.html', str_replace('<p>מהעורך — ותיקון ביד</p>', '<p>שוב ביד</p>', pf_blob_get(pf_by_path('lt/b.html')['sha'])));
    $wp->docs[$doc]['html'] = '<p>העורך מנצח</p>';
    eq(409, throws(fn() => longtext_sync(X1_ED, ['doc' => $doc, 'path' => 'lt/b.html'])));
    $o = longtext_sync(X1_ED, ['doc' => $doc, 'path' => 'lt/b.html', 'mode' => 'overwrite']);
    eq(true, $o['overwrote']);
    ok(str_contains(pf_blob_get(pf_by_path('lt/b.html')['sha']), '<p>העורך מנצח</p>'));
});

test('X1 📝: "🔗 חבר למסמך קיים" — רשימה (שם, תאריך, 200 תווים), עטיפת האלמנט ושמירת ה-hash; סנכרון אחר כך בלי התנגשות', function () {
    $wp = x1_wp();
    db()->prepare('INSERT OR REPLACE INTO longtexts (doc, html, css, updated_at, updated_by) VALUES (?, ?, ?, ?, ?)')
        ->execute(['old-intro', '<p>' . str_repeat('מבוא ישן ', 40) . '</p>', '.wp-doc p{margin:0}', '2026-10-01T00:00:00Z', 'x']);
    $l = array_column(longtexts_list()['docs'], null, 'doc')['old-intro'];
    eq(200, mb_strlen($l['preview']));
    eq('2026-10-01T00:00:00Z', $l['at']);
    $r = x1_file('lt/c.html', X1_PAGE);
    $el = '<section class="intro"><p>מבוא שהוסב</p></section>';
    eq(422, throws(fn() => longtext_link(X1_ED, ['path' => 'lt/c.html', 'rev' => (int)$r['rev'], 'doc' => 'old-intro', 'old' => "\n  "])), 'לא ייחודי');
    eq(404, throws(fn() => longtext_link(X1_ED, ['path' => 'lt/c.html', 'rev' => (int)$r['rev'], 'doc' => 'nope', 'old' => $el])));
    eq(409, throws(fn() => longtext_link(X1_ED, ['path' => 'lt/c.html', 'rev' => 99, 'doc' => 'old-intro', 'old' => $el])), 'rev');
    longtext_link(X1_ED, ['path' => 'lt/c.html', 'rev' => (int)$r['rev'], 'doc' => 'old-intro', 'old' => $el]);
    $html = pf_blob_get(pf_by_path('lt/c.html')['sha']);
    ok(str_contains($html, '<div data-kol-longtext="old-intro">' . $el . '</div>'));
    ok(str_contains($html, 'css/longtext/old-intro.css'));
    eq('.wp-doc p{margin:0}', pf_blob_get(pf_by_path('css/longtext/old-intro.css')['sha']));
    eq('lt/c.html', array_column(longtexts_list()['docs'], 'path', 'doc')['old-intro']);
    $wp->docs['old-intro'] = ['html' => '<p>מבוא מהעורך</p>', 'css' => '', 'title' => 'מבוא'];
    $s = longtext_sync(X1_ED, ['doc' => 'old-intro', 'path' => 'lt/c.html']);
    eq(false, $s['overwrote'], 'ה-hash נשמר בחיבור — אין "עריכה ביד"');
});

test('X1 📝: אזור שנמחק ביד — 404 ברור; הטיוטה הישנה (בלי path) — כמו היום; longtexts_get נשאר', function () {
    $wp = x1_wp();
    x1_file('lt/d.html', X1_PAGE);
    $wp->docs['gone'] = ['html' => '<p>x</p>', 'css' => ''];
    eq(404, throws(fn() => longtext_sync(X1_ED, ['doc' => 'gone', 'path' => 'lt/d.html'])));
    $r = longtext_sync(X1_ED, ['doc' => 'gone']);
    eq('gone', $r['doc']);
    eq('<p>x</p>', longtexts_get(['gone'])['gone']['html']);
    ok(function_exists('longtexts_get'));
    unset($GLOBALS['kol_wp_fake']);
});

test('X1: שורה במסך המפתח — בינה (בקשות, טוקנים, הוחלו / נדחו), צ\'אט לפי כניסה, סנכרונים והתנגשויות', function () {
    $row = array_column(dev_stats(), null, 'id')['x1'] ?? null;
    ok($row !== null, 'יש שורה');
    $s = x1_stats();
    ok(($s['applied'] ?? 0) >= 3 && ($s['rejected'] ?? 0) >= 6, 'מונים מהבדיקות של ✨');
    ok(($s['lt_sync'] ?? 0) >= 3 && ($s['lt_conflict'] ?? 0) >= 2, 'מונים מהבדיקות של 📝');
    ok((bool)array_filter(array_keys($s), fn($k) => str_starts_with((string)$k, "agent:צ'אט בדיקה")), 'בקשות לפי כניסה');
    foreach (['הוחלו', 'נדחו', '🤖', 'סנכרונים', 'התנגשויות'] as $w) ok(str_contains($row['value'], $w), $w);
});
