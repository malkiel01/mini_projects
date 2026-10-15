<?php
/**
 * בדיקות: רשימות ערכים — שמירה (עם "כמו בשורה שמעל"), ייבוא מ-CSV, קישור שדה ועמודה לרשימה ולרמה,
 * בדיקת ערך בשרת, והרשימות שמגיעות עם המסמך.
 * הרצה: php document-templates/tests/lists.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-lists-' . bin2hex(random_bytes(4));
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

// היררכיה כמו באקסל: בית העלמין כתוב פעם אחת, ומתחתיו ריק
$l = list_save($a, ['name' => 'מיקומי קברים', 'levels' => ['בית עלמין', 'גוש', 'חלקה', 'שורה'], 'rows' => [
    ['הר המנוחות', '51', '2א', '19'], ['', '', '', '20'], ['', '', '3', '1'], ['', '52', '1', '1'],
    ['גבעת שאול', '30180', '12', '4'], ['הר המנוחות', '51', '2א', '19'], [' ', '', '', '']]])['list'];
$full = list_get($a, ['id' => $l['id']])['list'];
check('נשמרה: 5 שורות (כמו בשורה שמעל, בלי כפולה וריקה)', $l['row_count'] === 5 && $full['rows'][1] === ['הר המנוחות', '51', '2א', '20'] && $full['rows'][3] === ['הר המנוחות', '52', '1', '1']);
try { list_get($b, ['id' => $l['id']]); check('רשימה של אחר — לא נגישה', false); } catch (ApiError $e) { check('רשימה של אחר — לא נגישה', $e->status === 404); }

$csv = "$tmp/l.csv";
file_put_contents($csv, "בית עלמין,גוש,חלקה\nהר המנוחות,51,2א\n,,3\nגבעת שאול,30180,12\n");
$imp = list_import($a, ['file' => ['tmp_name' => $csv, 'error' => 0, 'size' => filesize($csv)]]);
check('ייבוא CSV: הכותרת → רמות, ריק → כמו מעל', $imp['levels'] === ['בית עלמין', 'גוש', 'חלקה'] && $imp['rows'][1] === ['הר המנוחות', '51', '3'] && count($imp['rows']) === 3);

$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
     . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
file_put_contents("$tmp/u.pdf", $pdf);
$tid = tpl_create($a, ['name' => 'הצעה'], ['file' => ['tmp_name' => "$tmp/u.pdf", 'error' => 0, 'size' => strlen($pdf)]])['id'];
$geo = ['page' => 0, 'x' => .1, 'y' => .3, 'w' => .8, 'h' => .05];
$ref = fn($lv) => ['id' => $l['id'], 'level' => $lv];
tpl_save($a, ['id' => $tid, 'schema' => ['pages' => [['w' => 595, 'h' => 842]], 'fields' => [
    ['key' => 'cem', 'type' => 'select', 'label' => 'בית העלמין', 'list' => $ref(0)] + $geo,
    ['key' => 'note', 'type' => 'text', 'label' => 'הערה', 'list' => $ref(9)] + $geo,
    ['key' => 'graves', 'type' => 'table', 'label' => 'קברים', 'columns' => [
        ['key' => 'gush', 'label' => 'גוש', 'type' => 'select', 'list' => $ref(1)],
        ['key' => 'helka', 'label' => 'חלקה', 'type' => 'text', 'list' => $ref(2)],
        ['key' => 'price', 'label' => 'מחיר', 'type' => 'number', 'list' => $ref(3)]]] + $geo,
]]]);
$s = tpl_get($a, $tid)['template']['schema'];
check('שדה ועמודות קשורים לרשימה; רמה שלא קיימת / עמודת מספר — בלי קישור',
    $s['fields'][0]['list'] === $ref(0) && !isset($s['fields'][1]['list']) && $s['fields'][2]['columns'][0]['list'] === $ref(1)
    && $s['fields'][2]['columns'][1]['list'] === $ref(2) && !isset($s['fields'][2]['columns'][2]['list']));
check('אפשרויות שדה הבחירה — מהרשימה', array_column(select_options($s['fields'][0]), 'value') === ['הר המנוחות', 'גבעת שאול']);

$d = doc_create($a, ['template_id' => $tid])['id'];
doc_save($a, ['id' => $d, 'values' => ['cem' => 'הר המנוחות', 'graves' => json_encode([['gush' => '51', 'helka' => 'חלקה שלא ברשימה']], JSON_UNESCAPED_UNICODE)]]);
check('בחירה מהרשימה נשמרת; טקסט — גם ערך שלא ברשימה', doc_values($d)['cem'] === 'הר המנוחות' && str_contains(doc_values($d)['graves'], 'חלקה שלא ברשימה'));
try { doc_save($a, ['id' => $d, 'values' => ['cem' => 'בית עלמין אחר']]); check('ערך שלא ברשימה — נדחה', false); } catch (ApiError $e) { check('ערך שלא ברשימה — נדחה', $e->status === 422); }
try { doc_save($a, ['id' => $d, 'values' => ['graves' => json_encode([['gush' => '999']])]]); check('גוש שלא ברשימה (בטבלה) — נדחה', false); } catch (ApiError $e) { check('גוש שלא ברשימה (בטבלה) — נדחה', $e->status === 422); }
$doc = doc_get($a, $d)['document'];
check('המסמך מגיע עם הרשימה (לסינון המקונן)', ($doc['lists'][(string)$l['id']]['levels'] ?? []) === ['בית עלמין', 'גוש', 'חלקה', 'שורה'] && count($doc['lists'][(string)$l['id']]['rows']) === 5);

// משתמש אחר לא יכול לקשור רשימה שאינה שלו
$t2 = tpl_create($b, ['name' => 'שלי'], ['file' => ['tmp_name' => "$tmp/u.pdf", 'error' => 0, 'size' => strlen($pdf)]])['id'];
tpl_save($b, ['id' => $t2, 'schema' => ['pages' => [['w' => 595, 'h' => 842]], 'fields' => [['key' => 'x', 'type' => 'select', 'label' => 'x', 'list' => $ref(0)] + $geo]]]);
check('רשימה של אחר — לא נקשרת', !isset(tpl_get($b, $t2)['template']['schema']['fields'][0]['list']));
list_delete($a, ['id' => $l['id']]);
check('נמחקה — לא ברשימה', list_index($a)['lists'] === []);

echo "\n" . ($fail ? "$fail נכשלו\n" : "הכול עבר\n");
exit($fail ? 1 : 0);
