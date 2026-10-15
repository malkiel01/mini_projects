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
// תרגומים: בוחרים בעברית, ובמסמך שיוצא — בשפה שנקבעה לשדה
$full = list_get($a, ['id' => $l['id']])['list'];
list_save($a, ['id' => $l['id'], 'name' => $full['name'], 'levels' => $full['levels'], 'rows' => $full['rows'], 'translations' => [
    '0' => ['הר המנוחות' => ['en' => 'Har HaMenuchot', 'fr' => 'Har HaMenouhot', 'xx' => 'bad'], 'לא קיים' => ['en' => 'Nope']],
    '1' => ['51' => ['en' => '']], '9' => ['x' => ['en' => 'y']]]]);
$tr = list_get($a, ['id' => $l['id']])['list'];
$trA = json_decode(json_encode($tr['translations']), true);
check('תרגומים נשמרים: רק ערך שקיים, שפה מוכרת ולא ריק', $trA === ['0' => ['הר המנוחות' => ['en' => 'Har HaMenuchot', 'fr' => 'Har HaMenouhot']]] && $tr['langs'] === ['en', 'fr']);
check('ברשימת הרשימות — השפות', list_index($a)['lists'][0]['langs'] === ['en', 'fr']);
tpl_save($a, ['id' => $tid, 'schema' => ['pages' => [['w' => 595, 'h' => 842]], 'fields' => [
    ['key' => 'cem', 'type' => 'select', 'label' => 'בית העלמין', 'list' => $ref(0) + ['lang' => 'en']] + $geo,
    ['key' => 'cem2', 'type' => 'select', 'label' => 'בית העלמין 2', 'list' => $ref(0) + ['lang' => 'zz']] + $geo]]]);
$s = tpl_get($a, $tid)['template']['schema'];
check('שפה במסמך נשמרת בשדה; שפה לא מוכרת — בלי', $s['fields'][0]['list'] === $ref(0) + ['lang' => 'en'] && $s['fields'][1]['list'] === $ref(0));
check('בפלט: התרגום; בלי תרגום — הערך עצמו', docx_value($s['fields'][0], 'הר המנוחות', []) === 'Har HaMenuchot'
    && docx_value($s['fields'][0], 'גבעת שאול', []) === 'גבעת שאול' && docx_value($s['fields'][1], 'הר המנוחות', []) === 'הר המנוחות');
$d2 = doc_create($a, ['template_id' => $tid])['id'];
doc_save($a, ['id' => $d2, 'values' => ['cem' => 'הר המנוחות']]);
$doc = doc_get($a, $d2)['document'];
check('הערך נשמר בעברית, והתרגומים מגיעים עם המסמך', doc_values($d2)['cem'] === 'הר המנוחות'
    && (json_decode(json_encode($doc['lists'][(string)$l['id']]['translations']), true)['0']['הר המנוחות']['en'] ?? '') === 'Har HaMenuchot');
$csv2 = "$tmp/l2.csv";
file_put_contents($csv2, "בית עלמין,בית עלמין (en),גוש,בית עלמין [fr]\nהר המנוחות,Har HaMenuchot,51,Har HaMenouhot\n,,52,\nגבעת שאול,Givat Shaul,30180,\n");
$imp = list_import($a, ['file' => ['tmp_name' => $csv2, 'error' => 0, 'size' => filesize($csv2)]]);
$it = json_decode(json_encode($imp['translations']), true);
check('ייבוא: עמודת "(en)" / "[fr]" — תרגום, לא רמה', $imp['levels'] === ['בית עלמין', 'גוש'] && count($imp['rows']) === 3
    && $it === ['names' => ['0' => ['en' => 'בית עלמין (en)', 'fr' => 'בית עלמין [fr]']], '0' => ['הר המנוחות' => ['en' => 'Har HaMenuchot', 'fr' => 'Har HaMenouhot'], 'גבעת שאול' => ['en' => 'Givat Shaul']]]);

// עמודה באנגלית בשם משלה: ליד כל עמודה בעברית, או כולן בסוף — מה שנכתב במסמך
$pair = function (string $csv) use ($a, $tmp) {
    file_put_contents("$tmp/p.csv", $csv);
    $r = list_import($a, ['file' => ['tmp_name' => "$tmp/p.csv", 'error' => 0, 'size' => strlen($csv)]]);
    return [$r['levels'], json_decode(json_encode($r['translations']), true)];
};
$want = ['names' => ['0' => ['en' => 'Cemetery'], '1' => ['en' => 'Parcel']], '0' => ['גבעת שאול מערב' => ['en' => 'Givat Shaul West']], '1' => ['ז' => ['en' => 'Z']]];
[$lv1, $t1] = $pair("\u{FEFF}בית עלמין,Cemetery,חלקה,Parcel,קבר\nגבעת שאול מערב,Givat Shaul West,ז,Z,1\n,,,,2\n");
check('עמודה באנגלית צמודה: לא רמה — הפלט של הרמה, בשם שלה', $lv1 === ['בית עלמין', 'חלקה', 'קבר'] && $t1 == $want);
[$lv2, $t2] = $pair("בית עלמין,חלקה,קבר,Cemetery,Parcel\nגבעת שאול מערב,ז,1,Givat Shaul West,Z\n");
check('עמודות באנגלית בסוף — לפי הסדר', $lv2 === ['בית עלמין', 'חלקה', 'קבר'] && $t2 == $want);
[$lv3] = $pair("Cemetery,Parcel\nGivat Shaul,Z\n");
foreach (['בית עלמין (אנגלית)', 'בית עלמין באנגלית', 'בית עלמין - English', 'שם לועזי'] as $hh) {
    [$lv4, $t4] = $pair("בית עלמין,$hh,חלקה\nגבעת שאול מערב,Givat Shaul West,ז\n");
    check("עמודה לפלט מזוהה: \"$hh\"", $lv4 === ['בית עלמין', 'חלקה'] && ($t4['0']['גבעת שאול מערב']['en'] ?? '') === 'Givat Shaul West' && $t4['names']['0']['en'] === $hh);
}
check('רשימה כולה באנגלית — הכול רמות', $lv3 === ['Cemetery', 'Parcel']);

// רשימה גדולה: 30,000 קברים — לא נשלחת כולה עם המסמך; האפשרויות מהשרת רמה אחרי רמה
$csvBig = "בית עלמין,בית עלמין (en),גוש,חלקה,קבר\n";
for ($i = 0; $i < 30000; $i++) $csvBig .= ($i < 15000 ? 'הר המנוחות,Har HaMenuchot' : 'גבעת שאול,Givat Shaul') . ',' . (100 + intdiv($i, 1000)) . ',' . (1 + intdiv($i % 1000, 100)) . ',' . ($i % 100 + 1) . "\n";
$stage = bin2hex(random_bytes(8));
$half = intdiv(strlen($csvBig), 2);   // חיתוך באמצע — גם באמצע תו
foreach ([substr($csvBig, 0, $half), substr($csvBig, $half)] as $i => $chunk) {
    file_put_contents("$tmp/part$i", $chunk);
    $res = list_import($a, ['file' => ['tmp_name' => "$tmp/part$i", 'error' => 0, 'size' => strlen($chunk)]],
        ['stage' => $stage, 'part' => $i] + ($i ? ['last' => '1', 'apply' => '1', 'name' => 'קברים גדול'] : []));
}
check('ייבוא בחלקים: 30,000 שורות נשמרו ישר בשרת, עם תרגום', ($res['saved'] ?? false) && $res['count'] === 30000 && $res['list']['row_count'] === 30000
    && $res['levels'] === ['בית עלמין', 'גוש', 'חלקה', 'קבר'] && $res['translated'] === ['en']);
check('קובץ הביניים נמחק', !glob("$tmp/tmp/list-*"));
$big = $res['list'];
$g = list_get($a, ['id' => $big['id']])['list'];
check('בעורך: רשימה גדולה — 200 שורות לתצוגה, והערכים בכל רמה', ($g['big'] ?? false) && count($g['rows']) === 200 && $g['values'][0] === ['הר המנוחות', 'גבעת שאול'] && $g['values_more'][3] === false);
$g = list_get($a, ['id' => $big['id'], 'q' => 'גבעת'])['list'];
check('חיפוש ברשימה גדולה', $g['matches'] === 15000 && $g['rows'][0][0] === 'גבעת שאול');
list_save($a, ['id' => $big['id'], 'name' => 'קברים', 'keep_rows' => true, 'translations' => ['0' => ['גבעת שאול' => ['en' => 'Givat Shaul', 'fr' => 'Guivat Shaoul']]]]);
$g = list_get($a, ['id' => $big['id']])['list'];
check('שמירה בלי שורות: השם והתרגומים מתעדכנים, השורות נשארות', $g['name'] === 'קברים' && $g['row_count'] === 30000 && $g['langs'] === ['en', 'fr']);
$bref = fn($lv) => ['id' => $big['id'], 'level' => $lv];
tpl_save($a, ['id' => $tid, 'schema' => ['pages' => [['w' => 595, 'h' => 842]], 'fields' => [
    ['key' => 'cem', 'type' => 'select', 'label' => 'בית העלמין', 'list' => $bref(0) + ['lang' => 'en']] + $geo,
    ['key' => 'gush', 'type' => 'select', 'label' => 'גוש', 'list' => $bref(1)] + $geo,
    ['key' => 'grave', 'type' => 'select', 'label' => 'קבר', 'list' => $bref(3)] + $geo]]]);
$d3 = doc_create($a, ['template_id' => $tid])['id'];
$L = doc_get($a, $d3)['document']['lists'][(string)$big['id']];
check('עם המסמך: בלי השורות (big), עם התרגומים', ($L['big'] ?? false) && !isset($L['rows']) && json_encode($L)  !== false && strlen(json_encode($L)) < 2000);
$o = fn($lv, $known) => list_opts($a, ['doc' => $d3, 'list' => $big['id'], 'level' => $lv, 'known' => $known])['values'];
check('מהשרת: גושים בגבעת שאול', $o(1, ['0' => 'גבעת שאול']) === array_map('strval', range(115, 129)));
check('מהשרת: קברים בגוש 115 חלקה 3', count($o(3, ['0' => 'גבעת שאול', '1' => '115', '2' => '3'])) === 100);
check('רמה שמתחת לא מסננת', count($o(1, ['0' => 'הר המנוחות', '3' => '5'])) === 15);
try { list_opts($b, ['doc' => $d3, 'list' => $big['id'], 'level' => 0]); check('משתמש אחר — לא מקבל אפשרויות', false); } catch (ApiError $e) { check('משתמש אחר — לא מקבל אפשרויות', in_array($e->status, [403, 404], true)); }
try { list_opts($a, ['doc' => $d3, 'list' => $l['id'], 'level' => 0]); check('רשימה שלא בתבנית — לא נחשפת', false); } catch (ApiError $e) { check('רשימה שלא בתבנית — לא נחשפת', $e->status === 404); }
doc_save($a, ['id' => $d3, 'values' => ['cem' => 'גבעת שאול', 'gush' => '120', 'grave' => '7']]);
check('בחירה מרשימה גדולה נשמרת, ובפלט — באנגלית', doc_values($d3)['gush'] === '120' && docx_value(tpl_get($a, $tid)['template']['schema']['fields'][0], 'גבעת שאול', []) === 'Givat Shaul');
try { doc_save($a, ['id' => $d3, 'values' => ['gush' => '999']]); check('גוש שלא ברשימה הגדולה — נדחה', false); } catch (ApiError $e) { check('גוש שלא ברשימה הגדולה — נדחה', $e->status === 422); }
list_delete($a, ['id' => $big['id']]);

list_delete($a, ['id' => $l['id']]);
check('נמחקה — לא ברשימה', list_index($a)['lists'] === []);

echo "\n" . ($fail ? "$fail נכשלו\n" : "הכול עבר\n");
exit($fail ? 1 : 0);
