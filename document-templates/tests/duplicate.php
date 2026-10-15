<?php
/**
 * בדיקות: שכפול מסמך — מסמך חדש מאותה תבנית עם אותם ערכים; חתימות וקבצים לא עוברים,
 * שדה מחושב מחושב מחדש, ושדה שהשתנה בתבנית לא מקבל ערך ישן.
 * הרצה: php document-templates/tests/duplicate.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-dup-' . bin2hex(random_bytes(4));
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

foreach (['a@x.com' => 'אבי', 'b@x.com' => 'בני'] as $mail => $name) {
    db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES (?, '!', ?, 'active', ?)")->execute([$mail, $name, now_iso()]);
}
$a = db()->query('SELECT * FROM users WHERE id = 1')->fetch();
$b = db()->query('SELECT * FROM users WHERE id = 2')->fetch();
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
     . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
file_put_contents("$tmp/u.pdf", $pdf);
$tid = tpl_create($a, ['name' => 'קבלה'], ['file' => ['tmp_name' => "$tmp/u.pdf", 'error' => 0, 'size' => strlen($pdf)]])['id'];
$F = fn($key, $type, $label, $x = []) => ['key' => $key, 'type' => $type, 'label' => $label, 'page' => 0, 'x' => 0.1, 'y' => 0.1, 'w' => 0.3, 'h' => 0.03] + $x;
$fields = [
    $F('name', 'text', 'שם'),
    $F('qty', 'number', 'כמות'),
    $F('price', 'number', 'מחיר'),
    $F('total', 'calc', 'סה״כ', ['formula' => 'כמות * מחיר']),
    $F('when', 'date', 'תאריך'),
    $F('note', 'text', 'הערה', ['default' => 'ברירת מחדל']),
    $F('size', 'select', 'גודל', ['options' => [['value' => 'S', 'label' => 'S'], ['value' => 'L', 'label' => 'L']]]),
    $F('sig', 'signature', 'חתימה'),
];
$save = fn($fields) => tpl_save($a, ['id' => $tid, 'numbering' => ['scheme' => 'prefix_year_seq', 'prefix' => 'ק', 'digits' => 3],
    'schema' => ['pages' => [['w' => 595, 'h' => 842]], 'fields' => $fields]]);
$save($fields);

$d1 = doc_create($a, ['template_id' => $tid, 'title' => 'קבלה ללוי']);
$png = 'data:image/png;base64,iVBORw0KGgo=';
doc_save($a, ['id' => $d1['id'], 'values' => ['name' => 'משה לוי', 'qty' => '3', 'price' => '10', 'when' => '2026-10-15', 'note' => '', 'size' => 'L', 'sig' => $png]]);
db()->prepare('UPDATE documents SET tags_enc = ? WHERE id = ?')->execute([seal(json_encode(['חשבוניות'])), $d1['id']]);

$r = doc_duplicate($a, ['id' => $d1['id']]);
$v = doc_values($r['id']);
$g = doc_get($a, $r['id'])['document'];
check('מסמך חדש עם מספר חדש', $r['id'] !== $d1['id'] && $r['number'] !== $d1['number'] && $g['status'] === 'draft');
check('הערכים הועתקו (טקסט, מספר, תאריך, בחירה)', $v['name'] === 'משה לוי' && $v['qty'] === '3' && $v['when'] === '2026-10-15' && $v['size'] === 'L');
check('שדה מחושב — מחושב מחדש', ($v['total'] ?? '') === '30');
check('חתימה לא מועתקת, ומדווחת', ($v['sig'] ?? '') === '' && $r['skipped'] === ['חתימה']);
check('שדה שרוקן במקור — נשאר ריק (לא חוזר לברירת המחדל)', ($v['note'] ?? '') === '');
check('הכותרת והתגיות עוברות', $g['title'] === 'קבלה ללוי' && $g['tags'] === ['חשבוניות']);
check('ביומן: נוצר כעותק של המקור', ($g['events'][0]['detail']['copy_of'] ?? '') === $d1['number']);
check('המקור לא השתנה', doc_values($d1['id'])['sig'] === $png);

// התבנית השתנתה: שדה נמחק, סוג שהשתנה, אפשרות שהוסרה, שדה חדש עם ברירת מחדל
$fields2 = array_values(array_filter($fields, fn($f) => $f['key'] !== 'name'));
foreach ($fields2 as &$f) {
    if ($f['key'] === 'when') $f['type'] = 'text';
    if ($f['key'] === 'size') $f['options'] = [['value' => 'S', 'label' => 'S']];
}
unset($f);
$fields2[] = $F('extra', 'text', 'חדש', ['default' => 'ערך התחלתי']);
$save($fields2);
$v2 = doc_values(doc_duplicate($a, ['id' => $d1['id']])['id']);
check('גרסה חדשה של התבנית: שדה שנמחק / שסוגו השתנה / אפשרות שהוסרה — לא מועתקים',
    !isset($v2['name']) && ($v2['when'] ?? '') === '' && ($v2['size'] ?? '') === '' && $v2['qty'] === '3');
check('שדה חדש בתבנית — ברירת המחדל שלו', $v2['extra'] === 'ערך התחלתי');

// מי שאין לו גישה למסמך — לא משכפל
try { doc_duplicate($b, ['id' => $d1['id']]); check('בלי גישה — נחסם', false); }
catch (ApiError $e) { check('בלי גישה — נחסם', $e->status === 404); }

echo "\n" . ($fail ? "$fail נכשלו\n" : "הכול עבר\n");
exit($fail ? 1 : 0);
