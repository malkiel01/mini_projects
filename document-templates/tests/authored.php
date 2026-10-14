<?php
/**
 * בדיקות שלב 11: תבנית שנכתבה בכלי, טבלה חוזרת, שדות מחושבים, תנאי הצגה —
 * בשרת: מבנה, שמירה, חישוב מחדש, חובה, והפקת Word (גם שורות חוזרות בתבנית Word).
 * הרצה: php document-templates/tests/authored.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-auth-' . bin2hex(random_bytes(4));
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
function part(string $docx, string $name): string
{
    global $tmp;
    file_put_contents("$tmp/r.docx", $docx);
    $z = new ZipArchive();
    $z->open("$tmp/r.docx");
    $x = (string)$z->getFromName($name);
    $z->close();
    return $x;
}
/** הטקסט של המסמך, פסקה בשורה; תאי טבלה מופרדים ב-" | ". */
function text_of(string $xml): string
{
    $dom = new DOMDocument();
    $dom->loadXML($xml);
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('w', W_NS);
    $out = [];
    foreach ($xp->query('//w:body/w:p | //w:body/w:tbl/w:tr') as $n) {
        if ($n->localName === 'tr') {
            $out[] = implode(' | ', array_map(fn($tc) => trim($tc->textContent), iterator_to_array($xp->query('w:tc', $n))));
        } else {
            $s = '';
            foreach ($xp->query('.//w:t | .//w:br', $n) as $x) $s .= $x->localName === 'br' ? "\n" : $x->textContent;
            $out[] = $s;
        }
    }
    return implode("\n", $out);
}

db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES ('a@x.com', '!', 'אבי', 'active', ?)")->execute([now_iso()]);
$a = db()->query('SELECT * FROM users WHERE id = 1')->fetch();

// ── יצירה ──
$tid = tpl_create_authored($a, ['name' => 'הצעת מחיר'])['id'];
$t = tpl_get($a, $tid)['template'];
check('נכתב בכלי: מקור authored, בלי קובץ', $t['source'] === 'authored' && $t['mime'] === AUTHORED_MIME);
check('מתחיל בכותרת עם השם ופסקה ריקה', $t['schema']['body'][0]['text'] === 'הצעת מחיר' && $t['schema']['body'][1]['type'] === 'p');
throws('אין קובץ מקור להגיש', fn() => asset_bytes(tpl_asset_row((int)tpl_owned($tid, $a)['version_id'])), 404);
throws('בלי שם', fn() => tpl_create_authored($a, ['name' => ' ']), 422);

// ── מבנה: שדות, טבלה, חישובים, תנאים, גוף ──
$F = fn($key, $type, $label, $extra = []) => ['key' => $key, 'type' => $type, 'label' => $label, 'placeholder' => str_replace(' ', '_', $label),
    'page' => 0, 'x' => 0, 'y' => 0, 'w' => 0.3, 'h' => 0.03] + $extra;
$schema = ['pages' => [['w' => 595, 'h' => 842]], 'fields' => [
    $F('client', 'text', 'שם הלקוח', ['required' => true]),
    $F('date', 'date', 'תאריך'),
    $F('items', 'table', 'פריטים', ['columns' => [
        ['key' => 'desc', 'label' => 'תיאור', 'type' => 'text'],
        ['key' => 'qty', 'label' => 'כמות', 'type' => 'number'],
        ['key' => 'unit', 'label' => 'מחיר', 'type' => 'number'],
        ['key' => 'line', 'label' => 'סה"כ', 'type' => 'calc', 'formula' => 'qty * unit', 'format' => 'money'],
    ]]),
    $F('vat_on', 'checkbox', 'כולל מעמ'),
    $F('sub', 'calc', 'סכום ביניים', ['formula' => 'sum(פריטים.line)', 'format' => 'money']),
    $F('vat', 'calc', 'מעמ', ['formula' => 'if(vat_on, sub * 0.18, 0)', 'format' => 'money']),
    $F('total', 'calc', 'סה"כ לתשלום', ['formula' => 'sub + vat', 'format' => 'money']),
    $F('due', 'calc', 'לתשלום עד', ['formula' => 'days(date, 30)', 'format' => 'date']),
    $F('has_g', 'checkbox', 'יש ערב'),
    $F('g_name', 'text', 'שם הערב', ['required' => true, 'showIf' => ['key' => 'has_g', 'op' => 'eq', 'value' => 'כן']]),
    $F('self', 'text', 'עצמי', ['showIf' => ['key' => 'self', 'op' => 'filled']]),
], 'body' => [
    ['id' => 'b1', 'type' => 'heading', 'text' => 'הצעת מחיר', 'align' => 'center'],
    ['id' => 'b2', 'type' => 'p', 'text' => "לכבוד {{שם_הלקוח}}\nתאריך: {{תאריך}}"],
    ['id' => 'b3', 'type' => 'table', 'table' => 'items'],
    ['id' => 'b4', 'type' => 'p', 'text' => 'סכום ביניים: {{סכום_ביניים}} · מע"מ: {{מעמ}} · סה"כ: {{סה"כ_לתשלום}}'],
    ['id' => 'b5', 'type' => 'p', 'text' => 'לתשלום עד {{לתשלום_עד}}.'],
    ['id' => 'b6', 'type' => 'p', 'text' => 'ערב: {{שם_הערב}}', 'showIf' => ['key' => 'has_g', 'op' => 'eq', 'value' => 'כן']],
    ['id' => 'b7', 'type' => 'table', 'table' => 'nope'],
    ['id' => 'b8', 'type' => 'break'],
    ['id' => 'b9', 'type' => 'p', 'text' => 'נספח'],
]];
tpl_save($a, ['id' => $tid, 'schema' => $schema]);
$s = tpl_get($a, $tid)['template']['schema'];
$by = array_column($s['fields'], null, 'key');
check('טבלה: 4 עמודות, אחת מחושבת', count($by['items']['columns']) === 4 && $by['items']['columns'][3]['formula'] === 'qty * unit');
check('שדה מחושב: נוסחה ותצוגה', $by['total']['formula'] === 'sub + vat' && $by['total']['format'] === 'money');
check('תנאי נשמר', $by['g_name']['showIf'] === ['key' => 'has_g', 'op' => 'eq', 'value' => 'כן']);
check('תנאי על עצמו — נמחק', $by['self']['showIf'] === null);
check('גוף: קטע טבלה לשדה שאינו קיים — נמחק', count($s['body']) === 8 && !in_array('nope', array_column($s['body'], 'table'), true));
check('גוף: יישור ותנאי של קטע', $s['body'][0]['align'] === 'center' && $s['body'][5]['showIf']['key'] === 'has_g');

$bad = $schema;
$bad['fields'][4]['formula'] = 'sum(פריטים.line) +';
throws('נוסחה שבורה — נדחית עם הסבר', fn() => tpl_save($a, ['id' => $tid, 'schema' => $bad]), 422);
$bad = $schema;
$bad['fields'][6]['formula'] = 'sub + מס';
throws('מזהה לא מוכר — נדחה', fn() => tpl_save($a, ['id' => $tid, 'schema' => $bad]), 422);
$bad = $schema;
$bad['fields'][2]['columns'][3]['formula'] = 'qty * price';
throws('נוסחה בעמודה — מזהה לא מוכר', fn() => tpl_save($a, ['id' => $tid, 'schema' => $bad]), 422);

// ── מסמך: שמירה, חישוב, חובה ──
$did = doc_create($a, ['template_id' => $tid])['id'];
$r = doc_save($a, ['id' => $did, 'values' => [
    'client' => 'משה כהן', 'date' => '2026-10-14', 'vat_on' => '1',
    'items' => json_encode([['desc' => 'שולחן', 'qty' => '2', 'unit' => '450', 'line' => '99999', 'junk' => 'x'], ['desc' => 'כיסא', 'qty' => '4', 'unit' => '120.5']]),
    'total' => '1',
]]);
$v = doc_values($did);
$rows = json_decode($v['items'], true);
check('טבלה: עמודה מחושבת בשרת (לא מהדפדפן), בלי עמודות זרות', $rows[0] === ['desc' => 'שולחן', 'qty' => '2', 'unit' => '450', 'line' => '900'] && $rows[1]['line'] === '482');
check('שדות מחושבים: סכום, מע"מ, סה"כ, תאריך', $v['sub'] === '1382' && $v['vat'] === '248.76' && $v['total'] === '1630.76' && $v['due'] === '2026-11-13');
check('שדה מחושב לא נכתב מהדפדפן', $v['total'] !== '1');
check('השמירה מחזירה את הערכים המחושבים', $r['computed']['total'] === '1630.76' && str_contains($r['computed']['items'], '"line":"900"'));
throws('טבלה: מספר לא תקין בעמודת מספר', fn() => doc_save($a, ['id' => $did, 'values' => ['items' => json_encode([['qty' => 'שתיים']])]]), 422);
throws('טבלה: לא JSON', fn() => doc_save($a, ['id' => $did, 'values' => ['items' => 'x']]), 422);
check('חובה מוסתר (אין ערב) — לא נדרש', doc_progress($s, $v)['missing'] === []);
doc_save($a, ['id' => $did, 'values' => ['has_g' => '1']]);
check('יש ערב → "שם הערב" חובה', doc_progress($s, doc_values($did))['missing'] === ['שם הערב']);
throws('אי אפשר לסיים בלי שם הערב', fn() => doc_complete($a, ['id' => $did]), 422);
doc_save($a, ['id' => $did, 'values' => ['vat_on' => '']]);
check('הסרת מע"מ — הכול מחושב מחדש', doc_values($did)['vat'] === '0' && doc_values($did)['total'] === '1382');
$ev = db()->query("SELECT COUNT(*) FROM events WHERE document_id = $did AND type = 'values_saved'")->fetchColumn();
check('ביומן — רק מה שהוקלד (3 שמירות)', (int)$ev === 3);

// ── הפקת Word ──
$v = doc_values($did);
$out = docx_fill(authored_docx($s, $v), $s, $v, []);
$txt = text_of(part($out, 'word/document.xml'));
check('Word: כותרת, לקוח, תאריך', str_contains($txt, "הצעת מחיר\nלכבוד משה כהן\nתאריך: 14/10/2026"));
check('Word: טבלה — כותרת ושורה לכל פריט', str_contains($txt, 'תיאור | כמות | מחיר | סה"כ') && str_contains($txt, 'שולחן | 2 | 450 | 900.00 ₪') && str_contains($txt, 'כיסא | 4 | 120.5 | 482.00 ₪'));
check('Word: סכומים בפורמט ₪', str_contains($txt, 'סכום ביניים: 1,382.00 ₪ · מע"מ: 0.00 ₪ · סה"כ: 1,382.00 ₪'));
check('Word: תאריך מחושב', str_contains($txt, 'לתשלום עד 13/11/2026.'));
check('Word: קטע מותנה מוצג (יש ערב)', str_contains($txt, 'ערב: '));
check('Word: מעבר עמוד', str_contains(part($out, 'word/document.xml'), '<w:br w:type="page"/>'));
check('Word: XML תקין, בלי סימונים', (new DOMDocument())->loadXML(part($out, 'word/document.xml')) && !str_contains(part($out, 'word/document.xml'), '{{'));
$v2 = array_merge($v, ['has_g' => '']);
$txt2 = text_of(part(docx_fill(authored_docx($s, $v2), $s, $v2, []), 'word/document.xml'));
check('Word: קטע מותנה — לא נכנס כשהתנאי לא מתקיים', !str_contains($txt2, 'ערב:'));
$v3 = array_merge($v, ['items' => '']);
$txt3 = text_of(part(docx_fill(authored_docx($s, $v3), $s, $v3, []), 'word/document.xml'));
check('Word: טבלה ריקה — רק שורת הכותרת', str_contains($txt3, 'תיאור | כמות | מחיר | סה"כ') && !str_contains($txt3, 'שולחן'));

// ── תבנית Word עם שורה חוזרת ──
$w = 'xmlns:w="' . W_NS . '"';
$r = fn($t) => '<w:r><w:t xml:space="preserve">' . htmlspecialchars($t) . '</w:t></w:r>';
$tc = fn($t) => '<w:tc><w:p>' . $r($t) . '</w:p></w:tc>';
$body = '<w:p>' . $r('לקוח: {{לקוח}}') . '</w:p><w:tbl><w:tr>' . $tc('פריט') . $tc('כמות') . $tc('מחיר') . '</w:tr>'
      . '<w:tr><w:tc><w:p>' . $r('{{') . $r('שורות.פריט}}') . '</w:p></w:tc>' . $tc('{{שורות.כמות}}') . $tc('{{שורות.מחיר}}') . '</w:tr></w:tbl>'
      . '<w:p>' . $r('סה"כ: {{סה"כ}}') . '</w:p>';
$zp = "$tmp/w.docx";
$z = new ZipArchive();
$z->open($zp, ZipArchive::CREATE);
$z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
$z->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
$z->addFromString('word/document.xml', "<?xml version=\"1.0\" encoding=\"UTF-8\"?><w:document $w><w:body>$body<w:sectPr/></w:body></w:document>");
$z->close();
$wid = tpl_create($a, ['name' => 'Word עם טבלה'], ['file' => ['tmp_name' => $zp, 'error' => 0, 'size' => filesize($zp)]])['id'];
$ws = tpl_get($a, $wid)['template']['schema'];
$tbl = array_values(array_filter($ws['fields'], fn($f) => $f['type'] === 'table'))[0] ?? null;
check('Word: {{שורות.x}} → שדה טבלה אחד עם 3 עמודות', $tbl && $tbl['placeholder'] === 'שורות' && array_column($tbl['columns'], 'label') === ['פריט', 'כמות', 'מחיר']);
check('Word: שם העמודה בקובץ נשמר', array_column($tbl['columns'], 'name') === ['פריט', 'כמות', 'מחיר']);
check('Word: שאר השדות — רגילים', count($ws['fields']) === 3);
// הופכים את "סה"כ" לשדה מחושב ומשנים תווית של עמודה — הסימון עדיין נמצא
foreach ($ws['fields'] as &$f) {
    if ($f['placeholder'] === 'סה"כ') { $f['type'] = 'calc'; $f['formula'] = 'sum(שורות.מחיר)'; $f['format'] = 'money'; }
    if ($f['type'] === 'table') { $f['columns'][0]['label'] = 'תיאור הפריט'; $f['columns'][2]['type'] = 'number'; }
}
unset($f);
tpl_save($a, ['id' => $wid, 'schema' => $ws]);
$ws = tpl_get($a, $wid)['template']['schema'];
$tk = $tbl['key'];
$wd = doc_create($a, ['template_id' => $wid])['id'];
$lk = array_column($ws['fields'], 'key', 'placeholder');
doc_save($a, ['id' => $wd, 'values' => [$lk['לקוח'] => 'דנה', $tk => json_encode([
    ['c1' => 'מדף', 'c2' => '3', 'c3' => '80'], ['c1' => 'מנורה', 'c2' => '1', 'c3' => '150.5']])]]);
$wv = doc_values($wd);
$wx = text_of(part(docx_fill(asset_bytes(tpl_asset_row((int)doc_owned($wd, $a)['template_version_id'])), $ws, $wv, []), 'word/document.xml'));
check('Word: השורה חזרה לכל פריט, גם אחרי שינוי תווית', str_contains($wx, "פריט | כמות | מחיר\nמדף | 3 | 80\nמנורה | 1 | 150.5"));
check('Word: סה"כ מחושב ממולא', str_contains($wx, 'סה"כ: 230.50 ₪'));
check('Word: בלי סימונים שנשארו', !str_contains($wx, '{{'));

foreach (array_merge(glob("$tmp/files/*") ?: [], array_filter(glob("$tmp/{,.}*", GLOB_BRACE) ?: [], 'is_file')) as $p) @unlink($p);
@rmdir("$tmp/files");
@rmdir($tmp);
echo $fail ? "\n$fail נכשלו\n" : "\nהכול עבר\n";
exit($fail ? 1 : 0);
