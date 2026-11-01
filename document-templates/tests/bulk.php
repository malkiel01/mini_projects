<?php
/**
 * בדיקות שלב 12: קריאת גיליונות (xlsx כמו ש-Excel כותב, CSV ב-UTF-8 וב-Windows-1255),
 * הצעת מיפוי, הנפקה מאקסל (כולל שורות שגויות, אנשי קשר ומשתתפים), וייצוא.
 * הרצה: php document-templates/tests/bulk.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-bulk-' . bin2hex(random_bytes(4));
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
function upload(string $bytes): array
{
    global $tmp;
    $p = "$tmp/up-" . bin2hex(random_bytes(4));
    file_put_contents($p, $bytes);
    return ['file' => ['tmp_name' => $p, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes), 'name' => 'x']];
}
function user(string $email, string $name): array
{
    db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES (?, '!', ?, 'active', ?)")
        ->execute([$email, $name, now_iso()]);
    return db()->query('SELECT * FROM users WHERE id = ' . (int)db()->lastInsertId())->fetch();
}

// ── xlsx כמו ש-Excel כותב: מחרוזות משותפות, תאריך בעיצוב, עמודה שדולגה ──
$zp = "$tmp/excel.xlsx";
$z = new ZipArchive();
$z->open($zp, ZipArchive::CREATE);
$z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
$z->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="נתונים" sheetId="1" r:id="rId7"/></sheets></workbook>');
$z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId7" Type="worksheet" Target="worksheets/data.xml"/></Relationships>');
$z->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>שם השוכר</t></si><si><t>תאריך</t></si><si><t>סכום</t></si><si><t>משה כהן</t></si><si><r><t>דנה </t></r><r><t>לוי</t></r></si><si><t>הערה</t></si></sst>');
$z->addFromString('xl/styles.xml', '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts><numFmt numFmtId="164" formatCode="dd/mm/yyyy;@"/></numFmts><cellXfs><xf numFmtId="0"/><xf numFmtId="14"/><xf numFmtId="164"/><xf numFmtId="4"/></cellXfs></styleSheet>');
$z->addFromString('xl/worksheets/data.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
    . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="E1" t="s"><v>5</v></c></row>'
    . '<row r="2"><c r="A2" t="s"><v>3</v></c><c r="B2" s="1"><v>46309</v></c><c r="C2" s="3"><v>4500</v></c><c r="E2" t="inlineStr"><is><t>חניה</t></is></c></row>'
    . '<row r="4"><c r="A4" t="s"><v>4</v></c><c r="B4" s="2"><v>46310.5</v></c><c r="C4"><v>1.25E3</v></c></row>'
    . '</sheetData></worksheet>');
$z->close();
$rows = sheet_read(file_get_contents($zp));
check('xlsx: כותרות, כולל עמודה שדולגה (D ריקה)', $rows[0] === ['שם השוכר', 'תאריך', 'סכום', '', 'הערה']);
check('xlsx: מחרוזת משותפת, תאריך מעיצוב מובנה, מספר', $rows[1] === ['משה כהן', '2026-10-14', '4500', '', 'חניה']);
check('xlsx: שורה ריקה שדולגה נשמרת כריקה', $rows[2] === ['', '', '', '', '']);
check('xlsx: טקסט עשיר, תאריך מעיצוב מותאם, מספר מדעי', $rows[3] === ['דנה לוי', '2026-10-15', '1250', '', '']);

// ── xlsx שהמערכת כותבת → נקרא חזרה זהה ──
$w = xlsx_write([['א', 'ב', 'ג'], ['שלום & <עולם>', 12.5, "שורה 1\nשורה 2"], [7, '', 'סוף']]);
check('xlsx שנכתב — נקרא חזרה זהה', sheet_read($w) === [['א', 'ב', 'ג'], ['שלום & <עולם>', '12.5', "שורה 1\nשורה 2"], ['7', '', 'סוף']]);
check('xlsx שנכתב: מימין לשמאל, שורה ראשונה קפואה', str_contains((function () use ($w, $tmp) {
    file_put_contents("$tmp/w.xlsx", $w); $z = new ZipArchive(); $z->open("$tmp/w.xlsx");
    $x = $z->getFromName('xl/worksheets/sheet1.xml'); $z->close(); return $x; })(), 'rightToLeft="1"'));

// ── CSV ──
check('CSV UTF-8 עם BOM, פסיק, שדה במרכאות עם ירידת שורה', sheet_read("\xEF\xBB\xBFשם,הערה\nמשה,\"שורה 1\nשורה 2\"\n") === [['שם', 'הערה'], ['משה', "שורה 1\nשורה 2"]]);
$win = iconv('UTF-8', 'WINDOWS-1255', "שם;סכום\r\nדנה;100\r\n");
check('CSV מ-Excel בעברית (Windows-1255, נקודה-פסיק)', sheet_read($win) === [['שם', 'סכום'], ['דנה', '100']]);
throws('קובץ ריק', fn() => sheet_read(''), 422);
throws('קובץ בינארי', fn() => sheet_read("\x89PNG\r\n\x1a\n\0\0\0"), 415);
check('תאריך Excel', excel_date(46309) === '2026-10-14' && excel_date(0) === '');

// ── תבנית ──
$a = user('a@x.com', 'אבי');
$b = user('b@x.com', 'בתיה');
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
     . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
$tid = tpl_create($a, ['name' => 'הסכם שכירות'], upload($pdf))['id'];
$F = fn($key, $type, $label, $x = []) => ['key' => $key, 'type' => $type, 'label' => $label, 'page' => 0, 'x' => 0.1, 'y' => 0.1, 'w' => 0.3, 'h' => 0.03] + $x;
tpl_save($a, ['id' => $tid, 'schema' => ['pages' => [['w' => 595, 'h' => 842]],
    'roles' => [['key' => 'owner', 'label' => 'אני'], ['key' => 'client', 'label' => 'שוכר', 'auth' => 'none']],
    'fields' => [
        $F('tenant', 'text', 'שם השוכר', ['required' => true]), $F('start', 'date', 'תאריך כניסה'), $F('rent', 'number', 'סכום'),
        $F('pets', 'checkbox', 'חיות מחמד'), $F('phone', 'text', 'טלפון השוכר', ['contactField' => 'phone']),
        $F('sig', 'signature', 'חתימה', ['role' => 'client']), $F('total', 'calc', 'שנתי', ['formula' => 'rent * 12', 'format' => 'money']),
    ]]]);
$schema = tpl_get($a, $tid)['template']['schema'];

// ── הצעת מיפוי ──
$sug = bulk_suggest(['שם השוכר', 'תאריך-כניסה', 'סכום', 'חיות מחמד', 'טלפון', 'מייל', 'כותרת', 'שוכר — מייל', 'לא קשור'], $schema);
check('מיפוי: שדות לפי תווית (גם עם מקף)', ($sug[0] ?? '') === 'f:tenant' && ($sug[1] ?? '') === 'f:start' && ($sug[2] ?? '') === 'f:rent' && ($sug[3] ?? '') === 'f:pets');
check('מיפוי: איש קשר, כותרת ומשתתף לפי מילים', ($sug[4] ?? '') === '_c:phone' && ($sug[5] ?? '') === '_c:email' && ($sug[6] ?? '') === '_title' && ($sug[7] ?? '') === '_p:client:email');
check('מיפוי: עמודה לא מוכרת — בלי הצעה', !isset($sug[8]));
check('יעדים: חתימה ומחושב לא ממפים', !in_array('f:sig', array_keys(bulk_targets($schema)), true) && !in_array('f:total', array_keys(bulk_targets($schema)), true));
$prev = bulk_preview($a, ['template_id' => $tid], upload("שם השוכר,סכום\nמשה,100\n,\nדנה,200\n"));
check('תצוגה מקדימה: 2 שורות (בלי הריקה), דוגמה ומיפוי', $prev['rows'] === 2 && $prev['sample'][1][0] === 'דנה' && $prev['mapping']->{'0'} === 'f:tenant');
throws('תבנית של אחר (404)', fn() => bulk_preview($b, ['template_id' => $tid], upload("x\n1\n")), 404);

// ── הנפקה ──
$csv = "שם השוכר,תאריך כניסה,סכום,חיות מחמד,טלפון,מייל,כותרת,שוכר — מייל,תגיות\n"
     . "משה כהן,14/10/2026,\"4,500\",כן,050-1234567,moshe@x.com,דירה ברחוב הרצל,moshe@x.com,דחוף\n"
     . "דנה לוי,2026-11-01,3200,,052-7654321,,,dana@x.com,\n"
     . ",,,,,,,,\n"
     . "יוסי,31/02/2026,100,,,,,,\n"
     . "רות,01/12/26,לא מספר,,,,,,\n"
     . "משה כהן — חוזה 2,1/1/2027,5000,,0501234567,,,,\n";
$map = json_encode(['0' => 'f:tenant', '1' => 'f:start', '2' => 'f:rent', '3' => 'f:pets', '4' => '_c:phone', '5' => '_c:email',
    '6' => '_title', '7' => '_p:client:email', '8' => '_tags']);
$r = bulk_issue($a, ['template_id' => $tid, 'mapping' => $map, 'tag' => 'ייבוא בדיקה', 'send' => '1'], upload($csv));
check('נוצרו 3 מסמכים (שורות 2, 3, 7)', array_column($r['created'], 'row') === [2, 3, 7]);
check('2 שורות נכשלו עם סיבה (תאריך לא קיים, לא מספר)', array_column($r['failed'], 'row') === [5, 6]
    && str_contains($r['failed'][0]['error'], 'תאריך לא קיים') && str_contains($r['failed'][1]['error'], '"סכום": לא מספר'));
$d1 = $r['created'][0]['id'];
$v1 = doc_values($d1);
check('ערכים: תאריך ישראלי → ISO, "4,500" → 4500, "כן" → מסומן', $v1['tenant'] === 'משה כהן' && $v1['start'] === '2026-10-14' && $v1['rent'] === '4500' && $v1['pets'] === '1');
check('שדה מחושב חושב', $v1['total'] === '54000');
$g = doc_get($a, $d1)['document'];
check('כותרת מהעמודה, תגית הייבוא ותגית מהשורה', $g['title'] === 'דירה ברחוב הרצל' && $g['tags'] === ['ייבוא בדיקה', 'דחוף']);
check('בלי כותרת — שם התבנית', doc_get($a, $r['created'][1]['id'])['document']['title'] === 'הסכם שכירות');
check('איש קשר נוצר וקושר; השדה "מאיש הקשר" מולא', $g['contact'] === 'משה כהן' && $v1['phone'] === '050-1234567');
$contacts = contact_list($a, [])['contacts'];
check('אותו טלפון בשורה 7 — אותו איש קשר, לא כפול', count(array_filter($contacts, fn($c) => $c['name'] === 'משה כהן')) === 1
    && doc_get($a, $r['created'][2]['id'])['document']['contact_id'] === $g['contact_id']);
$p = array_values(array_filter($g['participants'], fn($x) => $x['role_key'] === 'client'))[0];
check('הממלא בקישור — אותו מייל כמו איש הקשר: מקושר אליו, בלי כפילות', $p['contact_id'] === $g['contact_id']);
check('ממלא עם מייל חדש — נוצר לו כרטיס', count($contacts) === 3 && in_array('dana@x.com', array_column($contacts, 'email'), true));
check('משתתף "שוכר": מייל נשמר וקישור נשלח', $p['email'] === 'moshe@x.com' && $p['notified_at'] !== null);
$mails = array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)@file_get_contents("$tmp/mail.log"))));
check('נשלחו 2 מיילים (משה ודנה)', count(array_filter($mails, fn($m) => str_contains($m['body'] ?? '', 'fill.html#t='))) === 2);
check('ביומן: "יובא" עם מספר השורה', in_array('imported', array_column($g['events'], 'type'), true)
    && array_values(array_filter($g['events'], fn($e) => $e['type'] === 'imported'))[0]['detail']['row'] === 2);
throws('בלי מיפוי', fn() => bulk_issue($a, ['template_id' => $tid, 'mapping' => '{}'], upload($csv)), 422);
throws('מיפוי ליעד לא קיים — מתעלמים, ואז אין מיפוי', fn() => bulk_issue($a, ['template_id' => $tid, 'mapping' => '{"0":"f:nope"}'], upload($csv)), 422);
throws('מייל לא תקין — השורה נכשלת, לא כל הייבוא', fn() => throw new ApiError(
    bulk_issue($a, ['template_id' => $tid, 'mapping' => json_encode(['0' => 'f:tenant', '1' => '_c:email'])], upload("שם,מייל\nא,לא-מייל\n"))['failed'][0]['error'], 422), 422);
$big = "שם השוכר\n" . str_repeat("א\n", BULK_MAX + 1);
throws('יותר מ-500 שורות — נדחה', fn() => bulk_issue($a, ['template_id' => $tid, 'mapping' => '{"0":"f:tenant"}'], upload($big)), 422);
share_set($a, ['type' => 'template', 'id' => $tid, 'email' => 'b@x.com', 'level' => 'edit', 'perms' => []]);
throws('עורך בלי "הנפקה" — אי אפשר להנפיק', fn() => bulk_issue($b, ['template_id' => $tid, 'mapping' => $map], upload($csv)), 403);

// ── קובץ דוגמה ──
$sample = bulk_sample_rows(tpl_owned($tid, $a));
check('קובץ דוגמה: כותרת, שדות (בלי חתימה ומחושב), איש קשר, משתתף', $sample[0] === ['כותרת המסמך', 'שם השוכר', 'תאריך כניסה', 'סכום', 'חיות מחמד', 'טלפון השוכר',
    'איש קשר — שם', 'איש קשר — טלפון', 'איש קשר — מייל', 'שוכר — שם', 'שוכר — מייל']);
$auto = bulk_suggest($sample[0], $schema);
check('קובץ הדוגמה ממופה לבד — כל עמודה', count($auto) === count($sample[0]));

// ── ייצוא ──
$rows = export_rows($a, tpl_owned($tid, $a), []);
check('ייצוא: כותרות בסיס ואז השדות', array_slice($rows[0], 0, 8) === ['מספר', 'כותרת', 'מצב', 'איש קשר', 'תגיות', 'נוצר', 'הושלם', 'שם השוכר']);
check('ייצוא: שורה לכל מסמך', count($rows) === 4);
$byTitle = array_column(array_slice($rows, 1), null, 1);
$m = $byTitle['דירה ברחוב הרצל'];
$col = fn($label) => array_search($label, $rows[0], true);
check('ייצוא: מספר כמספר, תאריך 14/10/2026, סימון "כן", מחושב כמספר', $m[$col('סכום')] === 4500 && $m[$col('תאריך כניסה')] === '14/10/2026'
    && $m[$col('חיות מחמד')] === 'כן' && $m[$col('שנתי')] === 54000);
check('ייצוא: מצב בעברית, איש קשר ותגיות: ' . implode(' | ', array_slice($m, 2, 3)), $m[2] === 'בתהליך' && $m[3] === 'משה כהן' && $m[4] === 'ייבוא בדיקה, דחוף');
check('ייצוא נרשם ביומן של כל מסמך', (int)db()->query("SELECT COUNT(*) FROM events WHERE type = 'exported'")->fetchColumn() === 3);
check('ייצוא לפי סינון (תגית)', count(export_rows($a, tpl_owned($tid, $a), ['tag' => 'דחוף'])) === 2);
check('ייצוא אצל מי ששותף לצפייה בתבנית — רק מה שהוא רואה (כלום)', count(export_rows($b, tpl_owned($tid, $b, 'view'), [])) === 1);
$x = xlsx_write($rows, 'תשובות');
check('ייצוא → xlsx שנקרא חזרה', sheet_read($x)[1][1] === $rows[1][1]);

foreach (array_merge(glob("$tmp/files/*") ?: [], array_filter(glob("$tmp/{,.}*", GLOB_BRACE) ?: [], 'is_file')) as $f) @unlink($f);
@rmdir("$tmp/files");
@rmdir($tmp);
echo $fail ? "\n$fail נכשלו\n" : "\nהכול עבר\n";
exit($fail ? 1 : 0);
