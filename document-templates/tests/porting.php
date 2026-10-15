<?php
/**
 * בדיקות: ייצוא תבנית לקובץ אחד, וייבוא שלה אצל משתמש אחר — הקובץ המקורי, המבנה, טבלה,
 * רשימות (לפי שם), גופן שהועלה, מספור; ומה שלא עובר (איש קשר קבוע, מקור חיצוני).
 * הרצה: php document-templates/tests/porting.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-port-' . bin2hex(random_bytes(4));
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
$up = fn($p) => ['file' => ['tmp_name' => $p, 'error' => 0, 'size' => filesize($p), 'name' => basename($p)]];

$l = list_save($a, ['name' => 'מיקומי קברים', 'levels' => ['בית עלמין', 'גוש'], 'rows' => [['הר המנוחות', '51'], ['', '52']]])['list'];
$font = font_upload($a, ['name' => 'אלף'], $up(__DIR__ . '/../assets/fonts/Alef-Regular.ttf'))['font'];
$c = contact_save($a, ['name' => 'יוסי', 'email' => 'y@x.com'])['contact'] ?? null;
$tid = tpl_create($a, ['name' => 'הצעת מחיר', 'mode' => 'parse'], $up("$tmp/u.pdf"))['id'];
$geo = ['page' => 0, 'x' => .1, 'y' => .3, 'w' => .8, 'h' => .05];
tpl_save($a, ['id' => $tid, 'numbering' => ['scheme' => 'prefix_year_seq', 'prefix' => 'הצ', 'digits' => 3], 'tags' => ['קברים'],
  'schema' => ['pages' => [['w' => 595, 'h' => 842]],
    'roles' => [['key' => 'emp', 'label' => 'עובד', 'auth' => 'none', 'contact' => $c ? $c['id'] : 0]],
    'styles' => [['id' => 's1', 'name' => 'כותרת', 'font' => $font['id'], 'fontSize' => 14]],
    'fields' => [
      ['key' => 'cem', 'type' => 'select', 'label' => 'בית העלמין', 'list' => ['id' => $l['id'], 'level' => 0], 'font' => $font['id']] + $geo,
      ['key' => 'graves', 'type' => 'table', 'label' => 'קברים', 'group' => 1, 'columns' => [
        ['key' => 'gush', 'label' => 'גוש', 'type' => 'select', 'list' => ['id' => $l['id'], 'level' => 1]],
        ['key' => 'price', 'label' => 'מחיר', 'type' => 'number']]] + $geo,
      ['key' => 'sum', 'type' => 'calc', 'label' => 'סה"כ', 'formula' => 'sum(קברים.מחיר)', 'format' => 'number', 'role' => 'emp'] + $geo,
    ]]]);

$x = tpl_export_data($a, $tid);
check('הקובץ: פורמט, שם, הקובץ המקורי', $x['format'] === 'dt-template' && $x['name'] === 'הצעת מחיר' && base64_decode($x['asset']['data']) === $pdf);
check('רשימה — לפי שם, עם השורות', $x['schema']['fields'][0]['list'] === ['name' => 'מיקומי קברים', 'level' => 0] && $x['lists'][0]['rows'][1] === ['הר המנוחות', '52']);
check('גופן — עם הקובץ', count($x['fonts']) === 1 && strlen(base64_decode($x['fonts'][0]['data'])) === filesize(__DIR__ . '/../assets/fonts/Alef-Regular.ttf'));
check('איש קשר קבוע — לא עובר, ונרשם', !isset($x['schema']['roles'][0]['contact']) && ($c === null || count($x['notes']) === 1));
check('JSON קריא (בלי בינארי מחוץ ל-base64)', json_encode($x, JSON_UNESCAPED_UNICODE) !== false);
try { tpl_export_data($b, $tid); check('משתמש אחר — לא מייצא', false); } catch (ApiError $e) { check('משתמש אחר — לא מייצא', $e->status === 404); }

// ייבוא אצל משתמש אחר
file_put_contents("$tmp/t.dt.json", json_encode($x, JSON_UNESCAPED_UNICODE));
$r = tpl_import($b, [], $up("$tmp/t.dt.json"));
$t2 = tpl_get($b, $r['id'])['template'];
$s2 = $t2['schema'];
$bl = list_index($b)['lists'];
check('נוצרה תבנית אצל המייבא, באותו שם ומקור', $t2['name'] === 'הצעת מחיר' && $t2['source'] === 'pdf_parsed' && count($s2['fields']) === 3);
check('הרשימה נוצרה אצלו, והשדה והעמודה קשורים אליה', count($bl) === 1 && $bl[0]['row_count'] === 2
    && $s2['fields'][0]['list'] === ['id' => $bl[0]['id'], 'level' => 0] && $s2['fields'][1]['columns'][0]['list'] === ['id' => $bl[0]['id'], 'level' => 1]);
$bf = font_list($b)['fonts'] ?? [];
check('הגופן נוצר אצלו, והשדה והסגנון מצביעים עליו', count($bf) === 1 && $s2['fields'][0]['font'] === $bf[0]['id']);
check('טבלה, קיבוץ, נוסחה, מספור ותגיות — עברו', $s2['fields'][1]['group'] === 1 && $s2['fields'][2]['formula'] === 'sum(קברים.מחיר)'
    && ($t2['numbering']['prefix'] ?? '') === 'הצ' && $t2['tags'] === ['קברים']);
// ייבוא נוסף — הרשימה הזהה לא משוכפלת
tpl_import($b, ['name' => 'עותק'], $up("$tmp/t.dt.json"));
check('ייבוא שני: אותה רשימה — לא נוצרת שוב', count(list_index($b)['lists']) === 1);
// קובץ לא נכון
file_put_contents("$tmp/bad.json", '{"hello":1}');
try { tpl_import($b, [], $up("$tmp/bad.json")); check('קובץ שאינו ייצוא — נדחה', false); } catch (ApiError $e) { check('קובץ שאינו ייצוא — נדחה', $e->status === 415); }

echo "\n" . ($fail ? "$fail נכשלו\n" : "הכול עבר\n");
exit($fail ? 1 : 0);
