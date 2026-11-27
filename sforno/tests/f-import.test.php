<?php
/**
 * ייבוא — קריטריונים 1–9 של docs/spec/03-import.md §4 ("I1 …"). קבצי הדוגמה נבנים כאן (📦 §24 — דוגמאות אמיתיות
 * מהבעלים עוד לא הגיעו). בלי ZipArchive / GD בסביבה — הבדיקות שתלויות בהם בודקות נפילה מסודרת במקום.
 * 5–6 (👁) נבדקים כאן בצד השרת; התצוגה עצמה — אצל הבעלים.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$A = ident('401', 'מייבאת', 'imp@example.test');
call('GET', 'me', [], $A);
$ok = function (array $r, string $name): array {
    eq($r[0], 200, $name . ($r[0] !== 200 ? ' — ' . json_encode($r[1]['error'] ?? $GLOBALS['t_last_500'] ?? null, JSON_UNESCAPED_UNICODE) : ''));
    return $r[1];
};
$ZIP = class_exists('ZipArchive');
$GD = extension_loaded('gd');
$tmp = SF_DATA_DIR . '/../sforno-fixtures-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700);
register_shutdown_function(function () use ($tmp) { foreach (glob("$tmp/*") ?: [] as $f) @unlink($f); @rmdir($tmp); });

function t_zip(string $path, array $entries): string
{
    $z = new ZipArchive();
    $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $n => $c) is_array($c) ? $z->addFile($c[0], $n) : $z->addFromString($n, $c);
    $z->close();
    return $path;
}

const W_NS = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
function t_docx(string $path, string $body, ?string $styles = null): string
{
    return t_zip($path, [
        '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
        'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . W_NS . '><w:body>' . $body . '</w:body></w:document>',
        'word/styles.xml' => $styles ?? '<?xml version="1.0"?><w:styles ' . W_NS . '>'
            . '<w:style w:type="paragraph" w:styleId="1"><w:name w:val="heading 1"/></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/></w:style>'
            . '<w:style w:type="paragraph" w:styleId="MyHead"><w:name w:val="כותרת שלי"/><w:basedOn w:val="1"/></w:style>'
            . '<w:style w:type="character" w:styleId="Strong"><w:name w:val="Strong"/><w:rPr><w:b/></w:rPr></w:style></w:styles>',
    ]);
}
$p = fn(string $runs, string $ppr = '') => "<w:p><w:pPr>$ppr</w:pPr>$runs</w:p>";
$r = fn(string $t, string $rpr = '') => "<w:r><w:rPr>$rpr</w:rPr><w:t xml:space=\"preserve\">" . htmlspecialchars($t, ENT_XML1) . "</w:t></w:r>";

$upload = fn(string $file, string $name, ?string $edition = null, array $more = []) => call('POST', 'import-upload', [], $A,
    ['edition' => $edition ?? $GLOBALS['E'], 'name' => $name] + $more, ['upload' => $file, 'content_type' => 'application/octet-stream']);
$run = fn(string $pid) => call('POST', 'import-run', ['import' => $pid], $A)[1]['import'] ?? null;
$sources = fn() => array_values(array_diff(scandir(SF_DATA_DIR . '/sources') ?: [], ['.', '..']));

// ── כותר ומהדורה ─────────────────────────────────────────────
$t = $ok(call('POST', 'content-title-save', ['name' => 'סידור לייבוא', 'kind' => 'siddur'], $A), 'כותר')['title'];
$E = $ok(call('POST', 'content-edition-save', ['title' => $t['pid'], 'label' => 'נוסח'], $A), 'מהדורה')['edition']['pid'];
$GLOBALS['E'] = $E;
$lim = $ok(call('GET', 'import-list', [], $A, ['edition' => $E]), 'import-list');
check('הגבלות מוצגות למנהל לפני ההעלאה (עד 50MB, ומגבלת השרת)', $lim['limits']['max'] > 0 && $lim['limits']['max'] <= 50 << 20, $lim['limits']);
eq(call('GET', 'import-list', [], null, ['edition' => $E])[0], 401, 'בלי זהות — אין ייבוא');
eq(code(call('POST', 'import-upload', [], $A, ['edition' => $E, 'name' => 'x.docx'], ['upload' => __FILE__, 'csrf' => 'זר'])), 'csrf', 'העלאה בלי CSRF → נחסמת');

// ── I1: docx עם כותרות, מודגש וניקוד ─────────────────────────
$NIQ = 'בָּרוּךְ אַתָּה יְהֹוָה אֱלֹהֵינוּ מֶלֶךְ הָעוֹלָם';
if ($ZIP) {
    $doc1 = t_docx("$tmp/a.docx",
        $p($r('שַׁחֲרִית'), '<w:pStyle w:val="1"/>')
        . $p($r('בָּרוּךְ', '<w:b/>') . $r(' אַתָּה יְהֹוָה') . $r(' אֱלֹהֵינוּ', '<w:bCs/>') . $r(' מֶלֶךְ הָעוֹלָם', '<w:b w:val="0"/><w:i/>'), '<w:bidi/>')
        . '<w:p><w:r><w:t>נמחק?</w:t></w:r><w:del><w:r><w:delText>מחוק</w:delText></w:r></w:del><w:ins><w:r><w:t>נוסף</w:t></w:r></w:ins><w:r><w:footnoteReference w:id="1"/></w:r></w:p>'
        . $p($r('ברכות השחר'), '<w:pStyle w:val="Heading2"/>')
        . $p($r('פריט ראשון'), '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr>')
        . $p($r('פריט שני'), '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr>')
        . '<w:tbl><w:tr><w:tc><w:tcPr><w:gridSpan w:val="2"/></w:tcPr>' . $p($r('תא <רחב>')) . '</w:tc></w:tr><w:tr><w:tc>' . $p($r('א')) . '</w:tc><w:tc>' . $p($r('ב')) . '</w:tc></w:tr></w:tbl>'
        . $p($r('מנחה'), '<w:pStyle w:val="MyHead"/>')
        . $p('<w:r><w:rPr><w:rStyle w:val="Strong"/></w:rPr><w:t>מודגש בסגנון</w:t></w:r>' . $r(' <script>alert(1)</script>'))
        . $p($r('Hello world')));
    $u = $ok($upload($doc1, 'ספר-בדיקה.docx'), 'I1 העלאת docx');
    $I1 = $u['import']['pid'];
    eq([$u['import']['kind'], $u['import']['status']], ['docx', 'queued'], 'I1 נבדק ונכנס לתור (משימת רקע)');
    check('I1 המקור נשמר ב-data/sources/<pid>/', is_file(SF_DATA_DIR . "/sources/$I1/original.docx"));
    eq((int)sf_val('SELECT COUNT(*) FROM jobs WHERE kind = ?', ['import.process']), 1, 'I1 משימת import.process בתור');
    $im = $run($I1);
    eq([$im['status'] ?? null, $im['progress'] ?? null], ['ready', 100], 'I1 העיבוד הסתיים');
    $html = $ok(call('GET', 'import-status', [], $A, ['import' => $I1, 'html' => '1']), 'import-status')['import']['html'] ?? '';
    check('I1 כותרת 1 ← מקטע', str_contains($html, '<sf-section sid="') && str_contains($html, 'title="שַׁחֲרִית"'), $html);
    check('I1 כותרת 2 ← מקטע משנה בתוך הראשי', (bool)preg_match('~<sf-section[^>]+title="שַׁחֲרִית">.*<sf-section[^>]+title="ברכות השחר">~s', $html), $html);
    check('I1 סגנון שיורש מכותרת 1 ← מקטע ראשי', (bool)preg_match('~</sf-section></sf-section><sf-section[^>]+title="מנחה">~', $html), $html);
    check('I1 מודגש ← b (w:b וגם w:bCs, ו-w:b="0" אינו מודגש)', str_contains($html, '<b>בָּרוּךְ</b>') && str_contains($html, '<b> אֱלֹהֵינוּ</b>') && str_contains($html, '<i> מֶלֶךְ הָעוֹלָם</i>'), $html);
    check('I1 מודגש לפי סגנון תו', str_contains($html, '<b>מודגש בסגנון</b>'), $html);
    $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    check('I1 הניקוד זהה בתו', str_contains($plain, $NIQ), $plain);
    $niq = fn(string $s) => preg_match_all('/[\x{0591}-\x{05C7}]/u', $s);
    eq($niq($plain), $niq($NIQ), 'I1 אותו מספר סימני ניקוד בטקסט');
    check('I1 מחיקה במעקב שינויים לא נכנסת, הוספה — כן', !str_contains($html, 'מחוק') && str_contains($html, 'נוסף'));
    check('I1 רשימה ← ul/li', (bool)preg_match('~<ul><li sid="[a-z0-9]{8}">פריט ראשון</li><li sid="[a-z0-9]{8}">פריט שני</li></ul>~', $html), $html);
    check('I1 טבלה, עם colspan, והטקסט מוברח', str_contains($html, '<td colspan="2">תא &lt;רחב&gt;</td>'), $html);
    check('I1 טקסט שנראה כמו תגית — נשאר טקסט', !str_contains($html, '<script') && str_contains($html, '&lt;script&gt;'));
    check('I1 פסקה לטינית ← dir="ltr"', str_contains($html, 'dir="ltr">Hello world'), $html);
    $w = implode(' | ', array_column($im['warnings'], 'msg'));
    check('I1 אזהרה: הערות שוליים', str_contains($w, 'הערות שוליים'), $w);
    $tree = $im['tree'];
    eq(array_column($tree, 'title'), ['שַׁחֲרִית', 'מנחה'], 'I1 עץ המקטעים');
    eq($tree[0]['sub'], 1, 'I1 בעץ: מקטע משנה אחד');

    // מיפוי ותיקון
    $im = $ok(call('POST', 'import-map', ['import' => $I1, 'op' => 'rename', 'i' => 1, 'title' => 'תפילת מנחה'], $A), 'מיפוי: שם')['import'];
    eq(array_column($im['tree'], 'title'), ['שַׁחֲרִית', 'תפילת מנחה'], 'מיפוי: שינוי כותרת');
    $im = $ok(call('POST', 'import-map', ['import' => $I1, 'op' => 'up', 'i' => 1], $A), 'מיפוי: למעלה')['import'];
    eq(array_column($im['tree'], 'title'), ['תפילת מנחה', 'שַׁחֲרִית'], 'מיפוי: הזזה');
    $im = $ok(call('POST', 'import-map', ['import' => $I1, 'op' => 'merge', 'i' => 1], $A), 'מיפוי: מיזוג')['import'];
    eq(array_column($im['tree'], 'title'), ['תפילת מנחה'], 'מיפוי: מיזוג למקטע שלפניו');
    $h2 = call('GET', 'import-status', [], $A, ['import' => $I1, 'html' => '1'])[1]['import']['html'];
    check('מיפוי: שם המקטע שמוזג נשאר ככותרת, והטקסט כולו נשאר', (bool)preg_match('~<h2 sid="[a-z0-9]{8}">שַׁחֲרִית</h2>~', $h2) && str_contains(strip_tags($h2), $NIQ), $h2);
    eq(code(call('POST', 'import-map', ['import' => $I1, 'op' => 'merge', 'i' => 0], $A)), 'state', 'מיפוי: אין לפני הראשון');
    eq(code(call('POST', 'import-map', ['import' => $I1, 'op' => 'rename', 'i' => 9, 'title' => 'x'], $A)), 'bad_input', 'מיפוי: פריט שלא קיים');

    // I8: ייבוא לא מפרסם ולא משנה public_allowed
    $ok(call('POST', 'content-save', ['edition' => $E, 'html' => '<p>גרסה ידנית</p>', 'base' => 0, 'from' => 0], $A), 'טיוטה ידנית');
    eq(code(call('POST', 'import-apply', ['import' => $I1], $A)), 'state', 'I8 טיוטה פתוחה — הייבוא לא דורס אותה');
    $v = call('GET', 'content-edition', [], $A, ['edition' => $E])[1]['draft']['v'];
    $ok(call('POST', 'content-publish', ['edition' => $E, 'base' => $v], $A), 'פרסום ידני');
    $ok(call('POST', 'content-title-public', ['title' => $t['pid'], 'on' => true], $A), 'ציבורי');
    $ap = $ok(call('POST', 'import-apply', ['import' => $I1], $A), 'I8 הייבוא נכנס לטיוטה');
    eq([$ap['draft']['status'], $ap['draft']['n']], ['draft', 2], 'I8 טיוטה חדשה (גרסה 2), לא פורסמה');
    $ev = call('GET', 'content-edition', [], $A, ['edition' => $E])[1];
    eq([$ev['published']['n'], $ev['title']['public_allowed']], [1, true], 'I8 הגרסה שפורסמה לא השתנתה, public_allowed לא השתנה');
    check('I8 הטיוטה היא התוכן המיובא', str_contains(strip_tags($ev['draft']['html']), $NIQ));
    check('I8 הקורא עדיין רואה רק את הגרסה שפורסמה', str_contains(call('GET', 'read-edition', [], null, ['edition' => $E])[1]['html'] ?? '', 'גרסה ידנית'));
    eq((int)sf_val("SELECT COUNT(*) FROM revisions WHERE status = 'published'"), 1, 'I8 אין פרסום אוטומטי');
    eq(code(call('POST', 'import-apply', ['import' => $I1], $A)), 'state', 'I8 אותו ייבוא לא נכנס פעמיים');
    $vd = $ev['draft']['v'];
    $ok(call('POST', 'content-discard', ['edition' => $E, 'base' => $vd], $A), 'ביטול הטיוטה');
    $ok(call('POST', 'content-title-public', ['title' => $t['pid'], 'on' => false], $A), 'כיבוי ציבורי');
} else {
    $u = $upload(__FILE__, 'a.docx');
    eq(code($u), 'invalid', 'I1 (בלי ZipArchive) — נדחה עם הסבר');
}

// ── I4: סיומת docx שאינה zip ─────────────────────────────────
$before = $sources();
file_put_contents("$tmp/fake.docx", "זה בכלל לא מסמך Word\n");
$res = $upload("$tmp/fake.docx", 'fake.docx');
eq([$res[0], code($res)], [422, 'invalid'], 'I4 ‎.docx שאינו zip ← נדחה בבדיקת הקובץ');
check('I4 ההודעה מסבירה', str_contains($res[1]['error']['message'] ?? '', 'zip') || !$ZIP, $res[1]);
eq($sources(), $before, 'I4 שום קובץ לא נשאר');
file_put_contents("$tmp/fake.pdf", "PK\x03\x04 not a pdf");
eq(code($upload("$tmp/fake.pdf", 'fake.pdf')), 'invalid', 'I4 ‎.pdf שאינו PDF ← נדחה');
copy("$tmp/fake.docx", "$tmp/fake.png");
eq(code($upload("$tmp/fake.png", 'fake.png')), 'invalid', 'I4 ‎.png שאינו תמונה ← נדחה');
eq(code($upload("$tmp/fake.docx", 'evil.php')), 'invalid', 'I4 סיומת לא נתמכת ← נדחה');
eq(code($upload("$tmp/fake.docx", 'a.exe.html', null)), null, 'HTML: טקסט רגיל מתקבל');
eq($sources() === $before, false, 'HTML נשמר');
$before = $sources();
$big = "$tmp/big.pdf";
$fh = fopen($big, 'wb'); fwrite($fh, '%PDF-1.4'); ftruncate($fh, (50 << 20) + 1); fclose($fh);
eq([$upload($big, 'big.pdf')[0], code($upload($big, 'big.pdf'))], [413, 'too_large'], 'מעל 50MB ← נדחה');
@unlink($big);
file_put_contents("$tmp/empty.html", '');
eq(code($upload("$tmp/empty.html", 'empty.html')), 'invalid', 'קובץ ריק ← נדחה');
eq($sources(), $before, 'דחייה לא משאירה קבצים');

// ── I3: zip slip ו-zip bomb ──────────────────────────────────
if ($ZIP) {
    $before = $sources();
    $evil = t_zip("$tmp/evil.docx", ['word/document.xml' => '<w:document ' . W_NS . '/>', '../evil.php' => '<?php echo 1;']);
    $res = $upload($evil, 'evil.docx');
    eq(code($res), 'invalid', 'I3 zip עם ../evil.php ← נדחה');
    check('I3 ההודעה: נתיב לא חוקי', str_contains($res[1]['error']['message'] ?? '', 'נתיב'), $res[1]);
    foreach (['/../evil.php', '/evil.php', '/sources/evil.php'] as $f) check("I3 לא נכתב $f", !file_exists(SF_DATA_DIR . $f));
    $found = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SF_DATA_DIR, FilesystemIterator::SKIP_DOTS)) as $f) if (str_contains($f->getFilename(), 'evil')) $found[] = $f->getPathname();
    eq($found, [], 'I3 שום קובץ evil בתיקיית הנתונים');
    eq($sources(), $before, 'I3 גם המקור לא נשמר');
    foreach (['/abs/x.xml' => 'נתיב מוחלט', 'a\\..\\b.xml' => 'לוכסן הפוך', 'C:/x.xml' => 'כונן'] as $n => $what) {
        eq(code($upload(t_zip("$tmp/n.docx", ['word/document.xml' => '<x/>', $n => 'x']), 'n.docx')), 'invalid', "I3 $what ← נדחה");
    }
    $many = [];
    for ($i = 0; $i <= 2000; $i++) $many["f$i.txt"] = '';
    $many['word/document.xml'] = '<x/>';
    eq(code($upload(t_zip("$tmp/many.docx", $many), 'many.docx')), 'invalid', 'I3 יותר מ-2,000 רשומות ← נדחה');
    $chunk = "$tmp/chunk.bin";
    $fh = fopen($chunk, 'wb'); for ($i = 0; $i < 19; $i++) fwrite($fh, str_repeat("\0", 1 << 20)); fclose($fh);
    $bomb = ['word/document.xml' => '<x/>'];
    for ($i = 0; $i < 11; $i++) $bomb["z$i.bin"] = [$chunk];
    $bf = t_zip("$tmp/bomb.docx", $bomb);
    check('I3 (הקובץ הדחוס עצמו קטן)', filesize($bf) < (1 << 20), filesize($bf));
    $res = $upload($bf, 'bomb.docx');
    eq(code($res), 'invalid', 'I3 מעל 200MB אחרי פריסה ← נדחה');
    check('I3 ההודעה: 200MB', str_contains($res[1]['error']['message'] ?? '', '200MB'), $res[1]);
    @unlink($chunk);
    eq($sources(), $before, 'I3 דחייה לא משאירה קבצים');
    // הגודל בפועל, לא המוצהר: document.xml של 21MB נדחה בקריאה
    $u = $ok($upload(t_docx("$tmp/huge.docx", str_repeat(' ', 21 << 20)), 'huge.docx'), 'I3 docx עם document.xml ענק עולה');
    $im = $run($u['import']['pid']);
    check('I3 ...ונכשל בעיבוד: גדול מדי אחרי פריסה', ($im['status'] ?? '') === 'failed' && str_contains($im['error'] ?? '', 'גדול מדי'), $im);
    @unlink("$tmp/huge.docx");
}

// ── I7: כשל עם סיבה ברורה, וניסיון חוזר ──────────────────────
if ($ZIP) {
    $u = $ok($upload(t_zip("$tmp/bad.docx", ['word/document.xml' => '<w:document ' . W_NS . '><w:body><w:p>']), 'bad.docx'), 'I7 docx עם XML פגום עולה');
    $IB = $u['import']['pid'];
    $im = $run($IB);
    eq($im['status'] ?? null, 'failed', 'I7 העיבוד נכשל');
    check('I7 הסיבה ברורה (איזה קובץ ומה)', str_contains($im['error'] ?? '', 'document.xml') && str_contains($im['error'] ?? '', 'פגום'), $im['error'] ?? null);
    eq(sf_val('SELECT status FROM jobs WHERE pid = (SELECT job_pid FROM imports WHERE pid = ?)', [$IB]), 'failed', 'I7 המשימה נכשלת (ניסיון אחד — כשל של קובץ לא מתקן את עצמו)');
    $re = $ok(call('POST', 'import-retry', ['import' => $IB], $A), 'I7 ניסיון חוזר')['import'];
    eq([$re['status'], $re['error']], ['queued', null], 'I7 ניסיון חוזר → בתור שוב');
    eq(($run($IB)['status'] ?? null), 'failed', 'I7 ...ונכשל שוב, באותה סיבה');
    eq(code(call('POST', 'import-retry', ['import' => $I1 ?? 'x'], $A)), 'state', 'I7 ניסיון חוזר רק לייבוא שנכשל');
    $xxe = '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x SYSTEM "file:///etc/passwd">]><w:document ' . W_NS . '><w:body><w:p><w:r><w:t>&x;</w:t></w:r></w:p></w:body></w:document>';
    $im = $run($ok($upload(t_zip("$tmp/xxe.docx", ['word/document.xml' => $xxe]), 'xxe.docx'), 'XXE עולה')['import']['pid']);
    check('XXE: ישות חיצונית ← נכשל, ולא נקרא קובץ', ($im['status'] ?? '') === 'failed' && str_contains($im['error'] ?? '', 'ישויות'), $im);
}

// ── I2: EPUB ─────────────────────────────────────────────────
$jpegExif = null;
if ($GD) {
    $g = imagecreatetruecolor(1800, 40);
    imagefill($g, 0, 0, imagecolorallocate($g, 200, 30, 30));
    ob_start(); imagejpeg($g); $j = (string)ob_get_clean();
    $exif = "Exif\0\0MM\0*\0\0\0\x08\0\0GPS-SECRET";
    $jpegExif = substr($j, 0, 2) . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($j, 2);
}
$opf = fn(string $spine) => '<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf" version="3.0"><metadata/><manifest>'
    . '<item id="c2" href="text/ch2.xhtml" media-type="application/xhtml+xml"/><item id="c1" href="text/ch1.xhtml" media-type="application/xhtml+xml"/>'
    . '<item id="c3" href="text/ch3.xhtml" media-type="application/xhtml+xml"/><item id="im" href="img/pic%201.jpg" media-type="image/jpeg"/>'
    . '<item id="gif" href="img/a.gif" media-type="image/gif"/><item id="out" href="../../escape.xhtml" media-type="application/xhtml+xml"/>'
    . "</manifest><spine>$spine</spine></package>";
$xh = fn(string $body) => '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE html><html xmlns="http://www.w3.org/1999/xhtml"><head><title>t</title></head><body>' . $body . '</body></html>';
$epubEntries = [
    'mimetype' => 'application/epub+zip',
    'META-INF/container.xml' => '<?xml version="1.0"?><container xmlns="urn:oasis:names:tc:opendocument:xmlns:container" version="1.0"><rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>',
    'OEBPS/content.opf' => $opf('<itemref idref="c1"/><itemref idref="c2"/><itemref idref="out"/>'),
    'OEBPS/text/ch1.xhtml' => $xh('<h1>פֶּרֶק רִאשׁוֹן</h1><p>שָׁלוֹם <b>עוֹלָם</b>&nbsp;יפה</p><div><p>פסקה בתוך div</p><img src="../img/pic%201.jpg" alt="תמונה"/></div><script>alert(1)</script>'),
    'OEBPS/text/ch2.xhtml' => $xh('<h2>פרק שני</h2><ul><li>אחת</li><li>שתיים</li></ul><p><img src="../img/a.gif"/><img src="../../../etc/x.jpg"/>סוף</p>'),
    'OEBPS/text/ch3.xhtml' => $xh('<h1>לא ב-spine</h1>'),
    'OEBPS/img/a.gif' => 'GIF89a',
];
if ($jpegExif !== null) $epubEntries['OEBPS/img/pic 1.jpg'] = $jpegExif;
if ($ZIP) {
    $ep = t_zip("$tmp/book.epub", $epubEntries);
    $before = $sources();
    $res = $upload($ep, 'book.epub');
    eq(code($res), 'invalid', 'I2 EPUB בלי "בהרשאת בעליו" ← לא עולה');
    check('I2 ההודעה מסבירה', str_contains($res[1]['error']['message'] ?? '', 'הרשאת בעליו'), $res[1]);
    eq($sources(), $before, 'I2 ושום דבר לא נשמר');
    $u = $ok($upload($ep, 'book.epub', null, ['owner' => '1']), 'I2 EPUB עם אישור');
    $IE = $u['import']['pid'];
    check('I2 האישור נשמר בייבוא', $u['import']['owner_permission'] === true);
    $im = $run($IE);
    eq($im['status'] ?? null, 'ready', 'I2 העיבוד הסתיים');
    eq(array_column($im['tree'] ?? [], 'title'), ['פֶּרֶק רִאשׁוֹן', 'פרק שני'], 'I2 פרקים לפי ה-spine (לא לפי ה-manifest), ומה שמחוץ ל-spine — לא');
    $html = call('GET', 'import-status', [], $A, ['import' => $IE, 'html' => '1'])[1]['import']['html'];
    check('I2 הכותרת שהפכה לשם הפרק לא נכפלת', substr_count($html, 'פֶּרֶק רִאשׁוֹן') === 1, $html);
    check('I2 הטקסט, הניקוד והסימון', str_contains($html, 'שָׁלוֹם <b>עוֹלָם</b>') && str_contains($html, 'פסקה בתוך div'), $html);
    check('I2 script לא נכנס', !str_contains($html, 'script') && !str_contains($html, 'alert'));
    check('I2 נתיב שיוצא מה-EPUB — לא נקרא', !str_contains($html, 'escape') && !str_contains($html, 'etc/x'));
    $w = implode(' | ', array_column($im['warnings'], 'msg'));
    check('I2 gif — לא מיובא, עם אזהרה', str_contains($w, 'png / jpeg / webp'), $w);
    if ($GD) {
        check('I2 התמונה בטיוטה, מתוך קובצי הספר', (bool)preg_match('~<img src="files/' . $IE . '/1\.jpg" alt="תמונה">~', $html), $html);
        [$f, $mime] = sf_content_file_path(['path' => "files/$IE/1.jpg"]);
        check('I2 התמונה נשמרה ב-data/sources/<pid>/files/ ומוגשת', $mime === 'image/jpeg' && str_starts_with($f, SF_DATA_DIR . "/sources/$IE/files/"));
        $bin = (string)file_get_contents($f);
        check('I2 בלי EXIF', !str_contains($bin, 'Exif') && !str_contains($bin, 'GPS-SECRET'));
        eq(getimagesize($f)[0] ?? null, 1400, 'I2 מוקטנת לרוחב המרבי');
        file_put_contents("$tmp/src.html", $html);
        file_put_contents("$tmp/cases.json", json_encode(['render' => ['img' => ['filesBase' => 'api.php?action=content-file&path=']]]));
        $render = json_decode((string)shell_exec('node ' . escapeshellarg(__DIR__ . '/render.mjs') . ' ' . escapeshellarg("$tmp/src.html") . ' ' . escapeshellarg("$tmp/cases.json") . ' 2>/dev/null'), true);
        if (is_array($render)) check('I2 התמונה מוצגת במנוע הקורא (render.js)', str_contains((string)($render['cases']['img'] ?? ''), 'src="api.php?action=content-file&amp;path=files/' . $IE . '/1.jpg"'), $render['cases']['img'] ?? $render);
        else echo "  (node לא זמין — דילוג על בדיקת render.js)\n";
    } else {
        check('I2 (בלי GD) — אזהרה שהתמונות לא יובאו', str_contains($w, 'GD'), $w);
    }
    foreach (['files/../../sforno.sqlite', "files/$IE/../original.epub", "files/$IE/original.epub", 'files/x/1.jpg', "files/$IE/1.php"] as $bad) {
        eq(code(t_call_file($bad)), 'not_found', "תמונה: \"$bad\" ← not_found");
    }
}
function t_call_file(string $path): array
{
    try { sf_content_file_path(['path' => $path]); return [200, []]; } catch (SfError $e) { return sf_error_response($e); }
}

// ── HTML ─────────────────────────────────────────────────────
file_put_contents("$tmp/w.html", "<html><head><style>p{}</style></head><body><b><p>מודגש שבור</p></b><h1>כותרת</h1><p onclick=\"x()\">טקסט <span style=\"x\">רגיל</span></p><script>alert(1)</script><table><tr><th>א</th><td><p>ב</p><p>ג</p></td></tr></table></body></html>");
$im = $run($ok($upload("$tmp/w.html", 'w.html'), 'HTML עולה')['import']['pid']);
$IH = $im['pid'] ?? '';
$html = call('GET', 'import-status', [], $A, ['import' => $IH, 'html' => '1'])[1]['import']['html'] ?? '';
// איך libxml פותר ‹b›‹p› משתנה בין גרסאות — בודקים שהפסקה תקינה והטקסט נשאר, לא את המודגש
check('HTML: קינון שבור (p בתוך b) ← פסקה תקינה, הטקסט נשאר', (bool)preg_match('~<p sid="[a-z0-9]{8}">(<b>)?מודגש שבור(</b>)?</p>~', $html), $html);
check('HTML: h1 ← מקטע, בלי script / onclick / style', str_contains($html, 'title="כותרת"') && !preg_match('~script|onclick|style|alert~', $html), $html);
check('HTML: תא עם שתי פסקאות ← br', str_contains($html, '<td>ב<br>ג</td>') && str_contains($html, '<td><b>א</b></td>'), $html);
file_put_contents("$tmp/sf.html", "<sf-section title=\"א\">\n<p>טוב</p>\n<p>שבור</sf-section>");
$im = $run($ok($upload("$tmp/sf.html", 'sf.html'), 'HTML בסימון של ספורנו עולה')['import']['pid']);
check('HTML בסימון של ספורנו עם שגיאת מבנה ← נכשל עם שורה', ($im['status'] ?? '') === 'failed' && str_contains($im['error'] ?? '', 'שורה 3'), $im);
file_put_contents("$tmp/1255.html", iconv('UTF-8', 'WINDOWS-1255', '<p>שלום בקידוד ישן</p>'));
$im = $run($ok($upload("$tmp/1255.html", '1255.html'), 'HTML ב-Windows-1255')['import']['pid']);
check('HTML ב-Windows-1255 ← מומר, עם אזהרה', ($im['status'] ?? '') === 'ready' && str_contains(implode(' ', array_column($im['warnings'], 'msg')), '1255')
    && str_contains(call('GET', 'import-status', [], $A, ['import' => $im['pid'], 'html' => '1'])[1]['import']['html'], 'שלום בקידוד ישן'), $im);

// ── I5–I6: PDF (הטקסט מהדפדפן) ───────────────────────────────
file_put_contents("$tmp/t.pdf", "%PDF-1.4\n% דוגמה\n%%EOF\n");
$u = $ok($upload("$tmp/t.pdf", 't.pdf'), 'PDF עולה');
$IP = $u['import']['pid'];
eq($u['import']['status'], 'awaiting_text', 'PDF מחכה לטקסט מהדפדפן (אין כלי PDF בשרת)');
check('PDF: המקור נשמר', is_file(SF_DATA_DIR . "/sources/$IP/original.pdf"));
[$sf] = sf_import_source_path(['import' => $IP]);
check('PDF: המקור מוגש לתצוגת העמודים', is_file($sf));
$pageText = "בָּרוּךְ שֶׁאָמַר וְהָיָה הָעוֹלָם\nשורה שנייה של הפסקה\n\nפסקה חדשה בעמוד";
$im = $ok(call('POST', 'import-pdf-text', ['import' => $IP, 'pages' => [$pageText, 'עמוד שני עם טקסט מספיק כדי לא להיות סרוק']], $A), 'I6 הטקסט נשלח')['import'];
eq($im['status'], 'queued', 'I6 נכנס לתור');
$im = $run($IP);
eq([$im['status'] ?? null, $im['kind'] ?? null, $im['has_text'] ?? null, $im['pages'] ?? null], ['ready', 'pdf', true, 2], 'I6 PDF עם טקסט ← תוצאה לטיוטה');
eq($im['text_checked'], false, 'I6 הטקסט לא מסומן כנבדק (התצוגה הזורמת מוסתרת)');
check('I6 אזהרה: לא מוגה', str_contains(implode(' ', array_column($im['warnings'], 'msg')), 'לא מוגה'));
$html = call('GET', 'import-status', [], $A, ['import' => $IP, 'html' => '1'])[1]['import']['html'];
check('I6 שורות ← פסקה, שורה ריקה ← פסקה חדשה, והניקוד נשמר', (bool)preg_match('~<p sid="[a-z0-9]{8}">בָּרוּךְ שֶׁאָמַר וְהָיָה הָעוֹלָם שורה שנייה של הפסקה</p><p sid="[a-z0-9]{8}">פסקה חדשה בעמוד</p>~', $html), $html);
$im = $ok(call('POST', 'import-text-checked', ['import' => $IP, 'on' => true], $A), 'I6 סימון "נבדק"')['import'];
eq($im['text_checked'], true, 'I6 אחרי הסימון — התצוגה הזורמת מותרת');
eq(audit_count('import.text_checked'), 1, 'I6 הסימון ביומן');
$rev = implode(' ', array_fill(0, 40, 'םולש ךורב'));
$im = $ok(call('POST', 'import-pdf-text', ['import' => $IP, 'pages' => [$rev]], $A), 'טקסט הפוך נשלח')['import'];
$im = $run($IP);
check('PDF בסדר חזותי (הפוך) ← אזהרה', str_contains(implode(' ', array_column($im['warnings'] ?? [], 'msg')), 'הפוך'), $im);

file_put_contents("$tmp/scan.pdf", "%PDF-1.7\n%%EOF\n");
$IS = $ok($upload("$tmp/scan.pdf", 'scan.pdf'), 'I5 PDF סרוק עולה')['import']['pid'];
$ok(call('POST', 'import-pdf-text', ['import' => $IS, 'pages' => ['', " \n ", '12']], $A), 'I5 (כמעט) בלי טקסט');
$im = $run($IS);
eq([$im['status'] ?? null, $im['kind'] ?? null, $im['has_text'] ?? null, $im['pages'] ?? null], ['ready', 'pdf_scan', false, 3], 'I5 PDF סרוק ← עמודים בלבד, בלי טקסט');
eq($im['tree'], [], 'I5 אין עץ ואין טקסט לחיפוש');
check('I5 לא מוצג כטקסט מזוהה', str_contains(implode(' ', array_column($im['warnings'], 'msg')), 'בלי טקסט'));
eq(code(call('POST', 'import-apply', ['import' => $IS], $A)), 'state', 'I5 אין ממנו טיוטת טקסט');
eq(code(call('POST', 'import-text-checked', ['import' => $IS, 'on' => true], $A)), 'state', 'I5 אין "טקסט נבדק" לסרוק');
eq(code(call('POST', 'import-pdf-text', ['import' => $IS, 'pages' => 'x'], $A)), 'bad_input', 'PDF: עמודים לא תקינים');
eq(code(call('POST', 'import-pdf-text', ['import' => $IH, 'pages' => ['x']], $A)), 'state', 'PDF: טקסט רק לייבוא PDF');

if ($GD) {
    $g = imagecreatetruecolor(20, 20);
    ob_start(); imagepng($g); file_put_contents("$tmp/p.png", ob_get_clean());
    $im = $run($ok($upload("$tmp/p.png", 'p.png'), 'תמונה עולה')['import']['pid']);
    eq([$im['status'] ?? null, $im['kind'] ?? null, $im['has_text'] ?? null], ['ready', 'image', false], 'תמונה ← עמוד בלבד');
    check('תמונה: עמוד מקודד מחדש', is_file(SF_DATA_DIR . "/sources/{$im['pid']}/files/page-1.png"));
}

// ── I9: היומן ────────────────────────────────────────────────
check('I9 import.upload ביומן', audit_count('import.upload') >= 6, audit_count('import.upload'));
check('I9 import.done ביומן', audit_count('import.done') >= 4, audit_count('import.done'));
check('I9 import.fail ביומן', audit_count('import.fail') >= 3, audit_count('import.fail'));
$metas = implode("\n", array_column(sf_rows("SELECT meta FROM audit_log WHERE action LIKE 'import.%' OR (action = 'content.draft' AND meta LIKE '%import%')"), 'meta'));
foreach (['ספר-בדיקה', 'book.epub', 't.pdf', 'w.html', '.docx', 'name'] as $n) check("I9 בלי שם הקובץ ב-meta (\"$n\")", !str_contains($metas, $n), $metas);
check('I9 meta: סוג וגודל', str_contains($metas, '"kind":') && str_contains($metas, '"size":'));
eq((int)sf_val("SELECT COUNT(*) FROM audit_log WHERE action = 'import.done' AND actor_kind = 'job'"), audit_count('import.done'), 'I9 import.done נרשם כמשימת רקע');

// ── הרשאות ופעולות ───────────────────────────────────────────
foreach (SF_ACTIONS as $a => $s) if (str_starts_with($a, 'import-')) eq($s['who'], 'staff', "$a — רק למנהל");
eq(code(call('GET', 'import-status', [], $A, ['import' => '../../etc'])), 'bad_input', 'מזהה ייבוא לא תקין');
check('אין קבצים זמניים שנשארו', !glob(SF_DATA_DIR . '/sources/.up_*'));

done();
