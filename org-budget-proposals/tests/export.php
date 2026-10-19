<?php
/**
 * בדיקת שלב 5: מודל המסמך, Word, אקסל ולוגו. נתונים מומצאים.
 * הרצה: php org-budget-proposals/tests/export.php
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/ob-export-' . bin2hex(random_bytes(4));
mkdir($tmp);
define('OB_DATA_DIR', $tmp);
define('OB_MAIL_SINK', $tmp . '/mail.jsonl');
define('OB_EXPORT_SINK', $tmp . '/out');
$_SERVER['REMOTE_ADDR'] = '10.0.0.5';

require __DIR__ . '/../lib/export.php';

$fails = 0;
function check(bool $ok, string $what): void
{
    global $fails;
    echo ($ok ? '  ✓ ' : '  ✕ ') . $what . "\n";
    if (!$ok) $fails++;
}
/** מריץ ייצוא שכותב לקובץ (OB_EXPORT_SINK) במקום לשלוח, ומחזיר את הנתיב. */
function export(callable $fn, array $s, int $id, string $ext): string
{
    try { $fn($s, $id); } catch (RuntimeException $e) { if ($e->getMessage() !== 'sink') throw $e; }
    return OB_EXPORT_SINK . ".$ext";
}
function xml_ok(string $xml): bool
{
    $d = new DOMDocument();
    return @$d->loadXML($xml) !== false;
}

db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES ('a@example.com', ?, 'גזבר', 'active', ?)")
    ->execute([password_hash('secret-123', PASSWORD_DEFAULT), now_iso()]);
$s = current_session(auth_login(['email' => 'a@example.com', 'password' => 'secret-123'])['token']);

$L = fn($fund, $dir, $title, $amt, $more = []) => ['fund' => $fund, 'direction' => $dir, 'title' => $title, 'prev_budget' => 1, 'prev_actual' => 1, 'amount' => $amt] + $more;
$r = org_import($s, ['data' => ['format' => IMPORT_FORMAT, 'org' => [
    'name' => 'ארגון לדוגמה', 'subtitle' => 'תת-כותרת',
    'funds' => [['key' => 'ops', 'kind' => 'operating', 'name' => 'שוטף'],
                ['key' => 'h1', 'kind' => 'hekdesh', 'name' => 'הקדש א', 'address' => "רח' א 1"],
                ['key' => 'h2', 'kind' => 'hekdesh', 'name' => 'הקדש ב']],
    'budgets' => [['year' => 2026, 'prev_actual_as_of' => '2025-09-30',
        'work_plan' => "תכנית עבודה לשנת 2026\nמבוא\n# הקדשות\nפסקה\n- תבליט\n## כותרת בלי אות",
        'signers' => [['name' => 'חותם', 'titles' => ['גזבר', 'יו"ר']]], 'summary_note' => '** הערה',
        'funds' => ['ops' => ['notes_income' => '* הערה להכנסות']],
        'summary' => [['key' => 'hin', 'direction' => 'income', 'section' => 'הכנסות מהקדשות', 'label' => 'הכנסות מהקדשות', 'funds' => ['h1', 'h2']]],
        'lines' => [
            $L('ops', 'income', 'הכנסה כללית', 100),
            $L('ops', 'income', 'החזר מהקדשות', 300, ['transfer' => 't']),
            $L('ops', 'income', 'הלוואה', 50, ['summary_only' => true]),
            $L('ops', 'expense', 'הוצאה', 450),
            $L('h1', 'income', 'שוכר', 1674000, ['monthly' => 135000, 'change_month' => 5, 'change_pct' => 5, 'lease' => true]),
            $L('h1', 'expense', 'החזר', 200, ['transfer' => 't']),
            $L('h1', 'expense', 'מלגות', 1673800),
            $L('h2', 'income', 'שוכר ב', 100),
            $L('h2', 'expense', 'החזר', 100, ['transfer' => 't']),
        ]]]]]]);
$bid = budget_list($s, $r['org_id'])['budgets'][0]['id'];

echo "מודל המסמך\n";
$d = doc_model($s, $bid);
check($d['cover']['org'] === 'ארגון לדוגמה' && $d['cover']['title'] === 'הצעת תקציב לשנת 2026', 'שער');
check($d['plan']['blocks'][0] === ['type' => 'center', 'text' => 'מבוא'] && $d['plan']['blocks'][1]['text'] === 'א. הקדשות'
    && end($d['plan']['blocks'])['text'] === 'כותרת בלי אות', 'תכנית עבודה: כותרת השנה לא כפולה, "מבוא" במרכז, פרק עם אות, כותרת בלי אות');
$ops = $d['funds'][0];
check($ops['title'] === 'הכנסות והוצאות – שוטף' && count($ops['tables'][0]['rows']) === 2, 'קופה: כותרת, והלוואה "רק בריכוז" לא בטבלה');
check($ops['tables'][0]['notes'] === ['* הערה להכנסות'] && $ops['tables'][1]['notes'] === [], 'הערת ההכנסות — אחרי טבלת ההכנסות');
check($d['cols']['actual'] === "ניצול תקציב\nעד 30.09.2025", 'כותרת עמודת הניצול עם התאריך');
$hek = $d['summary']['sections'][0]['rows'][0];
check($hek['pages'][0] < $hek['pages'][1] && $hek['pages'][0] > $ops['page'], 'שורת ריכוז של כמה קופות — טווח עמודים, אחרי הקופה השוטפת');

echo "Word\n";
$f = export('export_docx', $s, $bid, 'docx');
$z = new ZipArchive();
check($z->open($f) === true, 'קובץ docx נפתח');
$doc = $z->getFromName('word/document.xml');
check(xml_ok($doc) && xml_ok($z->getFromName('word/styles.xml')) && xml_ok($z->getFromName('word/footer1.xml')), 'כל ה-XML תקין');
check(str_contains($doc, '<w:bidiVisual/>') && str_contains($doc, '<w:rtl/>'), 'מימין לשמאל: טבלאות וטקסט');
check(substr_count($doc, 'PAGEREF fund') >= 2 && str_contains($doc, 'w:name="fund'), 'מספרי עמודים בריכוז — שדות PAGEREF לסימניות של הקופות');
check(str_contains($doc, '>135,000</w:t>') && str_contains($doc, ' ₪ לחודש') && str_contains($doc, 'הצעת תקציב לשנת </w:t>'), 'הערה בתא והשער');
check(str_contains($z->getFromName('word/settings.xml'), 'updateFields'), 'Word מעדכן את השדות בפתיחה');
check(preg_match('~<w:rPr>(?:(?!</w:rPr>).)*<w:rtl/>(?:(?!</w:rPr>).)*</w:rPr><w:t[^>]*>[^<]*[0-9]~u', $doc) === 0,
    'כיווניות: אין ספרות בריצה מימין לשמאל (Word הופך בהן תאריכים ומינוס)');
check(preg_match('~<w:rPr><w:sz[^>]*/><w:szCs[^>]*/></w:rPr><w:t xml:space="preserve">135,000</w:t>~', $doc) === 1
    && str_contains($doc, '<w:t xml:space="preserve"> ₪ לחודש'), 'כיווניות: "135,000 ₪ לחודש" — המספר בריצה משמאל לימין, העברית מימין לשמאל');
check(bidi_split('סדנת AI בינה')[1] === ['AI', true] && bidi_split('-1,000') === [['-1,000', true]]
    && bidi_split('01-04/2026, 05-12/2026') === [['01-04/2026', true], [', ', false], ['05-12/2026', true]], 'כיווניות: אנגלית, מינוס וטווחי תאריכים');
check(!str_contains($doc, '<w:jc w:val="right"/>'), 'יישור לימין בלי jc — Word ו-LibreOffice מפרשים אותו הפוך בפסקה מימין לשמאל');
$z->close();

echo "אקסל\n";
$f = export('export_xlsx', $s, $bid, 'xlsx');
check($z->open($f) === true, 'קובץ xlsx נפתח');
$wb = $z->getFromName('xl/workbook.xml');
preg_match_all('/<sheet name="([^"]+)"/', $wb, $m);
check($m[1][0] === 'ריכוז' && in_array('נספח שכירויות', $m[1], true) && count($m[1]) === 5, 'גיליונות: ריכוז, קופה לכל קופה, נספח');
$all = '';
for ($i = 1; $i <= count($m[1]); $i++) {
    $x = $z->getFromName("xl/worksheets/sheet$i.xml");
    check(xml_ok($x), "גיליון $i תקין");
    $all .= html_entity_decode($x, ENT_QUOTES | ENT_XML1);   // בנוסחאות ' נשמר כ-&apos;
}
check(str_contains($all, 'rightToLeft="1"') && str_contains($all, 'paperSize="9"') && str_contains($all, 'fitToWidth="1"'), 'מימין לשמאל, A4, התאמה לרוחב');
check(str_contains($all, '<f>135000*4+141750*8</f>'), 'שורה חודשית = נוסחה: 135000*4+141750*8');
check(preg_match("~<f>'הקדש א'!F\\d+\\+'הקדש ב'!F\\d+</f>~u", $all) === 1, 'שורה מקבלת = סכום המעבירות בגיליונות שלהן');
check(preg_match('~<f>SUM\(F\d+:F\d+\)</f>~', $all) === 1 || substr_count($all, '<f>SUM(F') >= 2, 'סה"כ = SUM');
check(str_contains(html_entity_decode($z->getFromName('xl/worksheets/sheet1.xml'), ENT_QUOTES | ENT_XML1), "'הקדש א'!F"), 'דף הריכוז מפנה לתאים בגיליונות הקופות');
check(str_contains($wb, 'fullCalcOnLoad="1"'), 'אקסל מחשב הכול בפתיחה');
check(substr_count($z->getFromName('xl/styles.xml'), 'readingOrder="2"') >= 8, 'כיווניות: תאי טקסט באקסל — סדר קריאה מימין לשמאל');
check(preg_match('~s="11" t="inlineStr"><is><t xml:space="preserve">01-\d\d/2026~', $all) === 1, 'כיווניות: תקופה בלי עברית — תא משמאל לימין');
$z->close();

echo "לוגו\n";
$png = $tmp . '/l.png';
$im = imagecreatetruecolor(20, 10);
imagepng($im, $png);
org_logo_upload($s, ['org_id' => $r['org_id']], ['logo' => ['tmp_name' => $png, 'error' => 0, 'size' => filesize($png)]]);
check(doc_model($s, $bid)['cover']['has_logo'], 'הלוגו נשמר ומופיע בשער');
$f = export('export_docx', $s, $bid, 'docx');
$z->open($f);
check($z->locateName('word/media/logo.png') !== false && str_contains($z->getFromName('word/document.xml'), 'r:embed="rLogo"'), 'הלוגו בתוך ה-Word');
$z->close();
$txt = $tmp . '/t.png';
file_put_contents($txt, 'not an image');
try { org_logo_upload($s, ['org_id' => $r['org_id']], ['logo' => ['tmp_name' => $txt, 'error' => 0, 'size' => 12]]); check(false, 'קובץ שאינו תמונה נדחה'); }
catch (ApiError $e) { check($e->status === 422, 'קובץ שאינו תמונה נדחה → ' . $e->getMessage()); }

echo "עיצוב המסמך\n";
check(doc_model($s, $bid)['style'] === DOC_STYLE && doc_model($s, $bid)['style_from'] === 'default', 'בלי הגדרה — ברירת המחדל');
check(doc_style_in(['size_text' => '11', 'size_table' => 99, 'underline' => 0, 'font' => 'Arial']) === ['size_table' => 16, 'underline' => false],
    'קלט: רק מה ששונה מברירת המחדל, ובגבולות (טבלה עד 16)');
try { doc_style_in(['font' => 'Comic Sans']); check(false, 'גופן לא מוכר נדחה'); } catch (ApiError $e) { check($e->status === 422, 'גופן לא מוכר נדחה'); }
$res = org_style_set($s, ['org_id' => $r['org_id'], 'style' => ['font' => 'David', 'size_table' => 12, 'title_align' => 'right', 'underline' => false,
    'margin_side' => 15, 'page_numbers' => false, 'fund_new_page' => false, 'show_plan' => false]]);
check($res['from'] === 'own' && $res['style']['font'] === 'David', 'נשמר לארגון');
$f = export('export_docx', $s, $bid, 'docx');
$z->open($f);
$doc = $z->getFromName('word/document.xml');
$sty = $z->getFromName('word/styles.xml');
check(str_contains($sty, 'w:ascii="David"') && str_contains($sty, 'w:cs="David"'), 'Word: הגופן');
check(str_contains($doc, '<w:sz w:val="24"/>') && !str_contains($doc, '<w:u w:val="single"/>'), 'Word: גודל הטבלה (12) ובלי קו תחתון');
check(str_contains($doc, 'w:right="851"') && !str_contains($doc, 'footerReference'), 'Word: שוליים 15 מ"מ ובלי מספרי עמודים');
check(!str_contains($doc, 'תכנית עבודה לשנת') && substr_count($doc, '<w:pageBreakBefore/>') === 3,
    'Word: בלי תכנית עבודה, והקופות ברצף — מעבר עמוד רק לריכוז, לקופה הראשונה ולנספח');
$z->close();
$f = export('export_xlsx', $s, $bid, 'xlsx');
$z->open($f);
check(str_contains($z->getFromName('xl/styles.xml'), '<name val="David"/>') && !str_contains($z->getFromName('xl/worksheets/sheet1.xml'), 'oddFooter'), 'אקסל: הגופן, ובלי מספר עמוד');
$z->close();
$sub = db()->query('SELECT id FROM orgs WHERE parent_id IS NOT NULL LIMIT 1')->fetchColumn();
if ($sub) {
    $st = db()->prepare('SELECT * FROM orgs WHERE id = ?');
    $st->execute([$sub]);
    check(org_doc_style($st->fetch())['from'] === 'parent', 'תת-ארגון בלי עיצוב משלו — של הארגון הראשי');
}
org_style_set($s, ['org_id' => $r['org_id'], 'style' => null]);
check(doc_model($s, $bid)['style'] === DOC_STYLE, 'חזרה לברירת המחדל');

exec('rm -rf ' . escapeshellarg($tmp));
echo $fails ? "\n✕ $fails בדיקות נכשלו\n" : "\n✓ הכול עבר\n";
exit($fails ? 1 : 0);
