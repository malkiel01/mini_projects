<?php
/**
 * בדיקות שלב 9: תבנית Word — איחוד סימונים מפוצלים, שדות, מילוי ושמירת עיצוב.
 * הרצה: php document-templates/tests/docx.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-docx-' . bin2hex(random_bytes(4));
define('DT_DATA_DIR', $tmp);
define('DT_MAIL_SINK', "$tmp/mail.log");
require __DIR__ . '/../lib/documents.php';
ensure_data_dir($tmp);

$fail = 0;
function check(string $name, bool $ok): void
{
    global $fail;
    echo ($ok ? '✓ ' : '✗ ') . $name . "\n";
    if (!$ok) $fail++;
}
function throws(string $name, callable $fn, int $status): void
{
    try { $fn(); check($name, false); }
    catch (ApiError $e) { check($name . " ({$e->getMessage()})", $e->status === $status); }
}

/** docx מינימלי. הגוף כתוב כמו ש-Word באמת כותב: סימון מפוצל לכמה ריצות. */
function make_docx(string $body, string $header = ''): string
{
    global $tmp;
    $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
    $p = "$tmp/t-" . bin2hex(random_bytes(4)) . '.docx';
    $z = new ZipArchive();
    $z->open($p, ZipArchive::CREATE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="xml" ContentType="application/xml"/><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $z->addFromString('word/document.xml', "<?xml version=\"1.0\" encoding=\"UTF-8\"?><w:document $w><w:body>$body<w:sectPr/></w:body></w:document>");
    if ($header) $z->addFromString('word/header1.xml', "<?xml version=\"1.0\" encoding=\"UTF-8\"?><w:hdr $w>$header</w:hdr>");
    $z->close();
    $b = (string)file_get_contents($p);
    unlink($p);
    return $b;
}
function part(string $docx, string $name): string
{
    global $tmp;
    $p = "$tmp/r.docx";
    file_put_contents($p, $docx);
    $z = new ZipArchive();
    $z->open($p);
    $x = (string)$z->getFromName($name);
    $z->close();
    return $x;
}
function text_of(string $xml): string
{
    preg_match_all('#<w:t[^>]*>([^<]*)</w:t>|<w:br/>#u', $xml, $m, PREG_SET_ORDER);
    return implode('', array_map(fn($x) => isset($x[1]) ? html_entity_decode($x[1]) : "\n", $m));
}

$r = fn($t, $bold = false) => '<w:r>' . ($bold ? '<w:rPr><w:b/></w:rPr>' : '') . '<w:t xml:space="preserve">' . htmlspecialchars($t) . '</w:t></w:r>';
$body = '<w:p>' . $r('שם השוכר: ') . $r('{{', true) . $r('שם_', true) . $r('השוכר}}') . $r(' ת"ז {{ת"ז}}') . '</w:p>'
      . '<w:p>' . $r('תאריך: {{תאריך_חתימה}} · סכום: {{סכום}} · מאשר {{סימון_מאשר}}') . '</w:p>'
      . '<w:p>' . $r('הערות: {{הערות}}') . '</w:p>'
      . '<w:p>' . $r('שוב: {{שם_השוכר}}') . '</w:p>';
$docx = make_docx($body, '<w:p>' . $r('חוזה מספר {{מספר_חוזה}}') . '</w:p>');

check('זוהה כקובץ Word', is_docx($docx));
check('PDF לא מזוהה כ-Word', !is_docx('%PDF-1.4'));

// ── איחוד ושדות ──
$norm = docx_normalize($docx, $names);
check('נמצאו כל הסימונים, כולל בכותרת', in_array('מספר_חוזה', $names, true) && in_array('שם_השוכר', $names, true));
$xml = part($norm, 'word/document.xml');
check('הסימון המפוצל אוחד לריצה אחת', str_contains($xml, '{{שם_השוכר}}'));
check('העיצוב של תחילת הסימון נשמר (מודגש)', (bool)preg_match('#<w:b/></w:rPr><w:t xml:space="preserve">\{\{שם_השוכר\}\}</w:t>#u', $xml));
check('הטקסט שמסביב לא השתנה', text_of($xml) === text_of(part($docx, 'word/document.xml')));
$fields = docx_fields($names);
$types = array_column($fields, 'type', 'placeholder');
check('שדה לכל סימון, בלי כפילויות', count($fields) === 7);
check('ניחוש סוגים: תאריך, סכום, סימון, טקסט ארוך', $types['תאריך_חתימה'] === 'date' && $types['סכום'] === 'number'
    && $types['סימון_מאשר'] === 'checkbox' && $types['הערות'] === 'textarea' && $types['שם_השוכר'] === 'text');
check('תווית קריאה (בלי קווים תחתונים)', $fields[0]['label'] === 'שם השוכר');

// ── תבנית ומסמך ──
db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES ('a@x.com', '!', 'אבי', 'active', ?)")->execute([now_iso()]);
$a = db()->query('SELECT * FROM users WHERE id = 1')->fetch();
file_put_contents("$tmp/u.docx", $docx);
$tid = tpl_create($a, ['name' => 'חוזה'], ['file' => ['tmp_name' => "$tmp/u.docx", 'error' => 0, 'size' => strlen($docx)]])['id'];
$t = tpl_get($a, $tid)['template'];
check('תבנית Word: מקור docx, 7 שדות', $t['source'] === 'docx' && count($t['schema']['fields']) === 7);
check('הסימון נשמר בכל שדה', $t['schema']['fields'][0]['placeholder'] === 'שם_השוכר');
file_put_contents("$tmp/e.docx", make_docx('<w:p>' . $r('בלי שדות') . '</w:p>'));
$plain = tpl_create($a, ['name' => 'בלי סימונים'], ['file' => ['tmp_name' => "$tmp/e.docx", 'error' => 0, 'size' => 10]])['id'];
check('Word בלי סימונים — נוצרת תבנית בלי שדות (מסמנים אותם בעורך)', tpl_get($a, $plain)['template']['schema']['fields'] === []);

// ── סימון שדות בעורך, על הטקסט של Word (lib/wordmark.php) ──
file_put_contents("$tmp/m.docx", make_docx(
    '<w:p>' . $r('שם השוכר: ', true) . $r('______') . '</w:p>'
    . '<w:p>' . $r('חתום: ישר') . $r('אל ישראלי, ') . $r('היום') . '</w:p>'
    . '<w:p>' . $r('קיים: {{תאריך}}') . '</w:p>', '<w:p>' . $r('כותרת עליונה') . '</w:p>'));
$mid = tpl_create($a, ['name' => 'סימון'], ['file' => ['tmp_name' => "$tmp/m.docx", 'error' => 0, 'size' => 10]])['id'];
$paras = tpl_word_text($a, $mid)['paragraphs'];
check('הפסקאות לסימון: גוף קודם, ואחריו הכותרת העליונה', array_column($paras, 'text') === ['שם השוכר: ______', 'חתום: ישראל ישראלי, היום', 'קיים: {{תאריך}}', 'כותרת עליונה']
    && $paras[0]['part'] === 'word/document.xml' && $paras[3]['part'] === 'word/header1.xml');
$mark = fn($pi, $sel, $name, $id = null) => tpl_word_mark($a, ['id' => $id ?? $mid, 'part' => $paras[$pi]['part'], 'p' => $paras[$pi]['p'],
    'start' => mb_strpos($paras[$pi]['text'], $sel), 'len' => mb_strlen($sel), 'text' => $sel, 'name' => $name]);
$m1 = $mark(0, '______', 'שם השוכר');
$m2 = $mark(1, 'ישראל ישראלי', 'שם החותם');   // חוצה שתי ריצות
$m3 = $mark(3, 'כותרת', 'שם השוכר');          // אותו שדה במקום נוסף
$tm = tpl_get($a, $mid)['template'];
$fx = array_column($tm['schema']['fields'], null, 'placeholder');
check('סימון: נוספו שדות, תווית מהשם; אותו שם — שדה אחד', $m1['added'] && $m2['added'] && !$m3['added'] && count($tm['schema']['fields']) === 3
    && $fx['שם_השוכר']['label'] === 'שם השוכר' && isset($fx['שם_החותם'], $fx['תאריך']));
check('סימון: מפתחות ייחודיים', count(array_unique(array_column($tm['schema']['fields'], 'key'))) === 3);
$mb = asset_bytes(tpl_asset_row((int)tpl_owned($mid, $a)['version_id']));
$doc = part($mb, 'word/document.xml');
check('בקובץ: {{...}} במקום הטקסט, והעיצוב של תחילת הבחירה נשמר',
    str_contains($doc, '{{שם_השוכר}}') && !str_contains($doc, '______') && str_contains($doc, 'חתום: {{שם_החותם}}') && str_contains($doc, 'היום')
    && str_contains(part($mb, 'word/header1.xml'), '{{שם_השוכר}} עליונה') && (new DOMDocument())->loadXML($doc));
throws('טקסט שלא תואם לקובץ — 409', fn() => tpl_word_mark($a, ['id' => $mid, 'part' => 'word/document.xml', 'p' => $paras[1]['p'],
    'start' => 0, 'len' => 4, 'text' => 'אחר!', 'name' => 'x']), 409);
$paras = tpl_word_text($a, $mid)['paragraphs'];
throws('בחירה שנוגעת בשדה קיים — 422', fn() => $mark(2, 'קיים: {{', 'x'), 422);
throws('שם עם נקודה — 422', fn() => $mark(1, 'היום', 'א.ב'), 422);
$dm = doc_create($a, ['template_id' => $mid])['id'];
doc_save($a, ['id' => $dm, 'values' => [$fx['שם_השוכר']['key'] => 'דנה', $fx['שם_החותם']['key'] => 'משה']]);
$filled = part(docx_fill($mb, tpl_get($a, $mid)['template']['schema'], doc_values($dm), []), 'word/document.xml');
check('מילוי: הערכים במקום השדות שסומנו', str_contains($filled, 'דנה') && str_contains($filled, 'חתום: משה') && !str_contains($filled, '{{שם_השוכר}}'));
$v = $mark(1, 'היום', 'תאריך החתימה')['version'];
check('תבנית שהונפק ממנה מסמך — הסימון בגרסה חדשה, והמסמך על הקודמת',
    $v === 2 && str_contains(part(asset_bytes(tpl_asset_row((int)doc_owned($dm, $a, 'view')['template_version_id'])), 'word/document.xml'), 'היום'));
tpl_save($a, ['id' => $tid, 'schema' => $t['schema']]);
check('שמירה בעורך לא מאבדת את הסימונים', tpl_get($a, $tid)['template']['schema']['fields'][3]['placeholder'] !== '');

$key = array_column($t['schema']['fields'], 'key', 'placeholder');
$did = doc_create($a, ['template_id' => $tid])['id'];
doc_save($a, ['id' => $did, 'values' => [$key['שם_השוכר'] => 'משה <כהן> & בניו', $key['ת"ז'] => '012345678',
    $key['תאריך_חתימה'] => '2026-10-13', $key['סכום'] => '4500', $key['סימון_מאשר'] => '1',
    $key['הערות'] => "שורה ראשונה\nשורה שנייה", $key['מספר_חוזה'] => 'שכ-7']]);
$d = doc_owned($did, $a, 'view');
$out = docx_fill(load_file(tpl_asset_row((int)$d['template_version_id'])['path']), doc_schema($d), doc_values($did), []);
$txt = text_of(part($out, 'word/document.xml'));
check('מולא: שם (עם תווים מיוחדים)', str_contains($txt, 'שם השוכר: משה <כהן> & בניו ת"ז 012345678'));
check('מולא: תאריך בפורמט ישראלי, סכום, סימון', str_contains($txt, 'תאריך: 13/10/2026 · סכום: 4500 · מאשר ☒'));
check('ירידת שורה הפכה ל-<w:br/>', str_contains($txt, "הערות: שורה ראשונה\nשורה שנייה"));
check('אותו שדה פעמיים — מולא בשני המקומות', str_contains($txt, 'שוב: משה <כהן> & בניו'));
check('הכותרת העליונה מולאה', text_of(part($out, 'word/header1.xml')) === 'חוזה מספר שכ-7');
check('לא נשאר אף סימון', !str_contains(part($out, 'word/document.xml'), '{{'));
check('ה-XML תקין', (new DOMDocument())->loadXML(part($out, 'word/document.xml')) === true);
check('העיצוב נשמר בפלט (השם מודגש)', (bool)preg_match('#<w:b/></w:rPr><w:t xml:space="preserve">משה &lt;כהן&gt; &amp; בניו</w:t>#u', part($out, 'word/document.xml')));

$out2 = docx_fill(load_file(tpl_asset_row((int)$d['template_version_id'])['path']), doc_schema($d), [], [], ['דף אישור', 'שורה 2']);
$x2 = part($out2, 'word/document.xml');
check('שדה ריק — הסימון נמחק (לא נשאר {{}})', !str_contains($x2, '{{') && str_contains(text_of($x2), 'שם השוכר:  ת"ז '));
check('דף אישור: מעבר עמוד ופסקאות לפני sectPr', (bool)preg_match('#<w:br w:type="page"/><w:t[^>]*>דף אישור</w:t>.*שורה 2.*<w:sectPr/>#us', $x2));
check('דף אישור: XML תקין', (new DOMDocument())->loadXML($x2) === true);

foreach (array_merge(glob("$tmp/files/*") ?: [], array_filter(glob("$tmp/{,.}*", GLOB_BRACE) ?: [], 'is_file')) as $p) @unlink($p);
@rmdir("$tmp/files");
@rmdir($tmp);
echo $fail ? "\n$fail נכשלו\n" : "\nהכול עבר\n";
exit($fail ? 1 : 0);
