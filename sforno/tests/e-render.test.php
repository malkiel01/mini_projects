<?php
/**
 * מנוע התצוגה — קריטריון 10 של docs/spec/02-content-engine.md: התצוגה המקדימה והקורא מפיקים אותו DOM לאותה גרסה
 * ואותן הגדרות. המקור עובר קודם את הניקוי של השרת (כמו בפרסום), ואז render.js רץ ב-node (tests/render.mjs) ומושווה
 * ל-snapshot ב-tests/fixtures/render-*.snap.html. עדכון snapshot מכוון: SF_UPDATE_SNAPSHOTS=1 php sforno/tests/run.php
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$root = dirname(__DIR__);
$fx = __DIR__ . '/fixtures';

$src = (string)file_get_contents("$fx/render-source.html");
$clean = sf_mk_clean($src, ['customs' => ['eretz' => 'place', 'chul' => 'place']]);
eq($clean['errors'], [], 'המקור של הבדיקה עובר את הניקוי');
preg_match_all('/sid="([a-z0-9]{8})"/', $src, $m1);
preg_match_all('/sid="([a-z0-9]{8})"/', (string)$clean['html'], $m2);
eq($m2[1], $m1[1], 'הניקוי שומר את כל המזהים של המקור');

$cases = [
    'render' => [
        'preview' => ['conds' => 'all', 'marks' => true],
        'reader-eretz' => ['conds' => ['eretz' => true, 'chul' => false]],
        'reader-default' => (object)[],
        'notes-open' => ['conds' => ['chul' => true], 'notes' => ['commentary' => true, 'instruction' => false], 'filesBase' => 'media/'],
        // הקורא (04): תנאי שלא נבחר — סימון שנפתח לצפייה; הסתרת ניקוד בתצוגה
        'reader-collapsed' => ['conds' => ['eretz' => true, 'chul' => false], 'condCollapsed' => true, 'condNames' => ['chul' => 'חוץ לארץ']],
        'reader-strip' => ['conds' => ['eretz' => true], 'strip' => ['niqqud' => true, 'taamim' => true]],
    ],
    'norm' => ['בָּרוּךְ אַתָּה', "בְּרֵאשִׁ֖ית  בָּרָ֣א", 'על־פי', 'מלכ ואמנ', "א\u{00A0}\u{200F}ב", 'ABC שָׁלוֹם', 'סוֹף־פָּסוּק׃ ס'],
];
$tmp = SF_DATA_DIR;
file_put_contents("$tmp/source.html", $clean['html']);
file_put_contents("$tmp/cases.json", json_encode($cases, JSON_UNESCAPED_UNICODE));
exec('command -v node 2>/dev/null', $nodeOut, $nodeCode);
check('C10 node מותקן (נדרש לבדיקת render.js; בשרת עצמו אין צורך)', $nodeCode === 0);
if ($nodeCode !== 0) done();
exec('node ' . escapeshellarg(__DIR__ . '/render.mjs') . ' ' . escapeshellarg("$tmp/source.html") . ' ' . escapeshellarg("$tmp/cases.json") . ' 2>&1', $out, $code);
eq($code, 0, 'C10 render.mjs רץ' . ($code ? ': ' . implode(' | ', array_slice($out, -5)) : ''));
$res = json_decode(implode("\n", $out), true);
if (!is_array($res)) done();

foreach ($cases['render'] as $name => $_) {
    $got = $res['cases'][$name] ?? '';
    $file = "$fx/render-$name.snap.html";
    if (getenv('SF_UPDATE_SNAPSHOTS') === '1' || !is_file($file)) file_put_contents($file, $got . "\n");
    eq($got . "\n", (string)file_get_contents($file), "C10 snapshot: $name");
    eq($res['same'][$name] ?? null, true, "C10 תצוגה מקדימה = קורא: $name");
}
$p = $res['cases']['preview'];
$e = $res['cases']['reader-eretz'];
$d = $res['cases']['reader-default'];
check('C10 שורש אחד: ‹div class="sf-doc"› בעברית', str_starts_with($p, '<div class="sf-doc sf-marks" lang="he" dir="rtl">') && str_starts_with($e, '<div class="sf-doc" lang="he" dir="rtl">'));
check('תצוגה מקדימה: שני התנאים גלויים ומסומנים', str_contains($p, 'data-rule="eretz"') && str_contains($p, 'רק בחוץ לארץ'));
check('קורא: רק התנאי שהוכרע', str_contains($e, 'רק בארץ ישראל') && !str_contains($e, 'רק בחוץ לארץ') && !str_contains($e, 'data-rule'));
check('קורא בלי הכרעה: תנאי לא מוצג', !str_contains($d, 'רק בארץ ישראל') && !str_contains($d, 'רק בחוץ לארץ'));
check('הטקסט ‹script› נשאר טקסט', str_contains($p, '&lt;script&gt; כטקסט &amp; סימנים') && !str_contains($p, '<script'));
check('נקודת ציון: span עם data-sid ו-data-title', str_contains($p, '<span class="sf-anchor" data-title="ברכה ראשונה" data-sid="anc00001">'));
check('מקטע: section + כותרת לפי עומק', str_contains($p, '<section class="sf-sec" data-sid="sec00001"><h2 class="sf-sec-title">ברכות השחר</h2>') && str_contains($p, '<h3 class="sf-sec-title">פנימי</h3>'));
check('הוראה פתוחה, פירוש סגור (ברירת מחדל)', str_contains($p, '<span class="sf-note sf-note-instruction"><button type="button" class="sf-note-btn" aria-expanded="true">הוראה</button><span class="sf-note-body">')
    && str_contains($p, 'aria-expanded="false">פירוש</button><span class="sf-note-body" hidden="">'));
check('הגדרת הערות הפוכה', str_contains($res['cases']['notes-open'], 'aria-expanded="true">פירוש') && str_contains($res['cases']['notes-open'], 'aria-expanded="false">הוראה'));
check('קישור פנימי — כפתור עם data-to', str_contains($p, '<button type="button" class="sf-link" data-to="anc00001">'));
check('סגנון שעוטף פסקה — div; בתוך שורה — span', str_contains($p, '<div class="sf-st sf-st-center">') && str_contains($p, '<span class="sf-st sf-st-large">'));
check('תמונה: נתיב מקובצי הספר עם הקידומת', str_contains($res['cases']['notes-open'], '<img src="media/files/pic.png" alt="תמונה" loading="lazy">'));
check('ניקוד נשמר בתצוגה', str_contains($p, 'בָּרוּךְ אַתָּה'));

eq($res['norm'], array_map('sf_norm', $cases['norm']), 'נרמול: render.js = השרת (sf_norm)');

// הקורא (04)
$cl = $res['cases']['reader-collapsed'];
check('R3 תנאי שלא נבחר — סימון "▸ למי שאומר — <שם>", והתוכן מוסתר (hidden)', str_contains($cl, '<div class="sf-cond sf-cond-off"><button type="button" class="sf-cond-btn" aria-expanded="false">▸ למי שאומר — חוץ לארץ</button><div class="sf-cond-body" hidden=""><p data-sid="par00004">רק בחוץ לארץ</p>'));
check('R3 התנאי שנבחר — מוצג כרגיל', str_contains($cl, '<div class="sf-cond"><p data-sid="par00003">רק בארץ ישראל</p>'));
$sp = $res['cases']['reader-strip'];
check('R6 הסתרת ניקוד — בתצוגה', str_contains($sp, 'ברוך אתה') && !preg_match('/[\x{05B0}-\x{05BC}]/u', $sp));
check('R6 בלי הסתרה — הניקוד נשאר (המקור לא השתנה)', str_contains($e, 'בָּרוּךְ אַתָּה'));

// המנוע אחד, ובלי innerHTML
$r = (string)file_get_contents("$root/assets/js/render.js");
check('render.js בלי innerHTML / outerHTML / insertAdjacentHTML / document.write', !preg_match('/innerHTML|outerHTML|insertAdjacentHTML|document\.write/', $r));
$ui = (string)file_get_contents("$root/assets/js/screens/content.js");
check('התצוגה המקדימה בניהול משתמשת ב-SFRender.render', str_contains($ui, 'SFRender.render('));
check('מסך התוכן בלי innerHTML = (רק קריאה לסידור)', !preg_match('/innerHTML\s*=|outerHTML|insertAdjacentHTML|document\.write/', $ui));
$html = (string)file_get_contents("$root/index.html");
check('index.html טוען את render.js לפני המסכים', ($i = strpos($html, 'assets/js/render.js')) !== false && $i < strpos($html, 'assets/js/screens/content.js'));

done();
