<?php
/**
 * בדיקות: טבלה חוזרת על דף PDF — עמודת בחירה, קיבוץ לפי העמודה הראשונה, וסכום השורות בשדה מחושב.
 * הרצה: php document-templates/tests/pdftable.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-ptbl-' . bin2hex(random_bytes(4));
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

db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES ('a@x.com', '!', 'אבי', 'active', ?)")->execute([now_iso()]);
$a = db()->query('SELECT * FROM users WHERE id = 1')->fetch();
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
     . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
file_put_contents("$tmp/u.pdf", $pdf);
$tid = tpl_create($a, ['name' => 'הצעת מחיר'], ['file' => ['tmp_name' => "$tmp/u.pdf", 'error' => 0, 'size' => strlen($pdf)]])['id'];
$geo = ['page' => 0, 'x' => .1, 'y' => .3, 'w' => .8, 'h' => .2];
tpl_save($a, ['id' => $tid, 'schema' => ['pages' => [['w' => 595, 'h' => 842]], 'fields' => [
    ['key' => 'graves', 'type' => 'table', 'label' => 'קברים', 'group' => true, 'columns' => [
        ['key' => 'plot', 'label' => 'אחוזה', 'type' => 'text'],
        ['key' => 'kind', 'label' => 'סוג', 'type' => 'select', 'options' => ['קבר בודד', 'מכפלה', '', 'מכפלה']],
        ['key' => 'res', 'label' => 'תושבות', 'type' => 'select', 'options' => ['תושב ישראל', 'תושב חו״ל']],
        ['key' => 'price', 'label' => 'מחיר', 'type' => 'number']]] + $geo,
    ['key' => 'sum', 'type' => 'calc', 'label' => 'מחיר הקברים', 'formula' => 'sum(קברים.מחיר)', 'format' => 'number'] + $geo,
    ['key' => 'funeral', 'type' => 'number', 'label' => 'שירות לוויה'] + $geo,
    ['key' => 'total', 'type' => 'calc', 'label' => 'סה"כ לתשלום', 'formula' => 'מחיר_הקברים + שירות_לוויה', 'format' => 'number'] + $geo,
]]]);
$f = tpl_get($a, $tid)['template']['schema']['fields'][0];
check('טבלה על דף PDF נשמרת, עם מיקום; קיבוץ true (ישן) = עמודה אחת', $f['type'] === 'table' && $f['w'] > 0.7 && $f['group'] === 1);
check('עמודת בחירה: אפשרויות נקיות (בלי ריק וכפולים)', $f['columns'][1]['type'] === 'select' && $f['columns'][1]['options'] === ['קבר בודד', 'מכפלה']);

$d = doc_create($a, ['template_id' => $tid])['id'];
$rows = [['plot' => '1', 'kind' => 'מכפלה', 'res' => 'תושב ישראל', 'price' => '9500'],
         ['plot' => '1', 'kind' => 'מכפלה', 'res' => 'תושב חו״ל', 'price' => '19000'],
         ['plot' => '2', 'kind' => 'קבר בודד', 'res' => 'תושב חו״ל', 'price' => '22000']];
$r = doc_save($a, ['id' => $d, 'values' => ['graves' => json_encode($rows, JSON_UNESCAPED_UNICODE), 'funeral' => '1200']]);
$v = doc_values($d);
check('סכום השורות: 9,500 + 19,000 + 22,000', $v['sum'] === '50500');
check('סה"כ = הקברים + לוויה', $v['total'] === '51700');
check('המחושבים חוזרים לדפדפן', ($r['computed']['total'] ?? '') === '51700');
try { doc_save($a, ['id' => $d, 'values' => ['graves' => json_encode([['plot' => '1', 'kind' => 'משהו אחר']], JSON_UNESCAPED_UNICODE)]]); check('ערך שלא ברשימה — נדחה', false); }
catch (ApiError $e) { check('ערך שלא ברשימה — נדחה (' . $e->getMessage() . ')', $e->status === 422); }

echo "\n" . ($fail ? "$fail נכשלו\n" : "הכול עבר\n");
exit($fail ? 1 : 0);
