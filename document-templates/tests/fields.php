<?php
/**
 * בדיקות: ניהול שדות — שדות שמתמלאים לבד (מספר המסמך, תאריכים, מנפיק, כותרת),
 * "מוסתר מהממלאים בקישור", ו"לא להדפיס".
 * הרצה: php document-templates/tests/fields.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-fields-' . bin2hex(random_bytes(4));
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

db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES ('a@x.com', '!', 'אבי לוי', 'active', ?)")->execute([now_iso()]);
$a = db()->query('SELECT * FROM users WHERE id = 1')->fetch();
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
     . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
file_put_contents("$tmp/u.pdf", $pdf);
$tid = tpl_create($a, ['name' => 'הצעה'], ['file' => ['tmp_name' => "$tmp/u.pdf", 'error' => 0, 'size' => strlen($pdf)]])['id'];
$F = fn($key, $type, $label, $x = []) => ['key' => $key, 'type' => $type, 'label' => $label, 'page' => 0, 'x' => 0.1, 'y' => 0.1, 'w' => 0.3, 'h' => 0.03] + $x;
tpl_save($a, ['id' => $tid, 'numbering' => ['scheme' => 'prefix_year_seq', 'prefix' => 'הצ', 'digits' => 3], 'schema' => ['pages' => [['w' => 595, 'h' => 842]],
    'roles' => [['key' => 'owner', 'label' => 'אני'], ['key' => 'client', 'label' => 'לקוח']],
    'fields' => [
        $F('num', 'system', 'מספר המסמך', ['source' => 'number', 'required' => true]),
        $F('iss', 'system', 'תאריך הנפקה', ['source' => 'issued']),
        $F('who', 'system', 'המנפיק', ['source' => 'owner']),
        $F('ttl', 'system', 'כותרת', ['source' => 'title']),
        $F('done', 'system', 'הושלם', ['source' => 'completed']),
        $F('bad', 'system', 'מקור לא מוכר', ['source' => 'nope']),
        $F('cost', 'number', 'עלות פנימית', ['hidden' => true, 'print' => false]),
        $F('secret_calc', 'calc', 'רווח', ['hidden' => true, 'formula' => 'מחיר_ללקוח - עלות_פנימית']),
        $F('price', 'number', 'מחיר ללקוח', ['locked' => true, 'fmt' => '₪ #,##0.00']),
        $F('due', 'date', 'מועד', ['fmt' => 'd בMMMM yyyy (hd בhMMMM hyy)']),
        $F('note', 'text', 'הערה', ['fmt' => 'dd/MM']),
        $F('cname', 'text', 'שם הלקוח', ['role' => 'client', 'required' => true, 'hidden' => true]),
    ]]]);
$s = tpl_get($a, $tid)['template']['schema'];
$by = array_column($s['fields'], null, 'key');
check('מתמלא לבד: מקור נשמר; מקור לא מוכר → מספר המסמך', $by['num']['source'] === 'number' && $by['bad']['source'] === 'number');
check('מתמלא לבד אינו חובה (גם אם סומן)', $by['num']['required'] === false);
check('נעילה במקום נשמרת; ברירת מחדל — לא נעול', $by['price']['locked'] === true && $by['cost']['locked'] === false);
check('מבנה תצוגה נשמר; לשדה טקסט — מתבטל', $by['price']['fmt'] === '₪ #,##0.00' && $by['note']['fmt'] === '');
check('Word: מבנה מותאם — מספר ותאריך משולב', docx_value($by['price'], '4500', []) === '₪ 4,500.00'
    && docx_value($by['due'], '2026-10-14', []) === '14 באוקטובר 2026 (ג׳ בחשוון תשפ״ז)');
check('הרשאות נשמרות: מוסתר, לא להדפיס; ברירת מחדל — להדפיס', $by['cost']['hidden'] === true && $by['cost']['print'] === false && $by['price']['print'] === true);

$did = doc_create($a, ['template_id' => $tid, 'title' => 'הצעה לדנה'])['id'];
$v = doc_values($did);
$d = db()->query("SELECT * FROM documents WHERE id = $did")->fetch();
check('מספר המסמך מתמלא לבד בהנפקה', $v['num'] === $d['number'] && str_starts_with($v['num'], 'הצ-'));
check('תאריך הנפקה, מנפיק, כותרת', $v['iss'] === bulk_now('Y-m-d') && $v['who'] === 'אבי לוי' && $v['ttl'] === 'הצעה לדנה');
check('תאריך השלמה — ריק עד הסיום', ($v['done'] ?? '') === '');
doc_save($a, ['id' => $did, 'values' => ['num' => 'זיוף', 'cost' => '700', 'price' => '1000'], 'title' => 'הצעה לדנה כהן']);
$v = doc_values($did);
check('אי אפשר לכתוב לשדה שמתמלא לבד', $v['num'] === $d['number']);
check('שינוי כותרת — שדה הכותרת מתעדכן', $v['ttl'] === 'הצעה לדנה כהן');
check('שדה שמתמלא לבד לא נספר כחובה חסר', !in_array('מספר המסמך', doc_progress($s, $v)['missing'], true));

// ממלא בקישור
$st = db()->prepare("SELECT id FROM participants WHERE document_id = ? AND role_key = 'client'");
$st->execute([$did]);
$pid = (int)$st->fetchColumn();
part_contact($a, ['id' => $did, 'participant_id' => $pid, 'name' => 'דנה', 'email' => 'd@x.com']);
$link = part_link($a, ['id' => $did, 'participant_id' => $pid, 'via' => 'link'])['url'];
$_SERVER['HTTP_X_FILL_TOKEN'] = substr($link, strpos($link, '#t=') + 3);
$g = fill_get()['document'];
$keys = array_column($g['schema']['fields'], 'key');
check('הממלא לא מקבל שדה מוסתר — ולא את הערך שלו', !in_array('cost', $keys, true) && !array_key_exists('cost', $g['values']));
check('הממלא כן רואה שדה מוסתר שהוא שלו', in_array('cname', $keys, true));
check('הממלא רואה את מספר המסמך ואת המחיר', $g['values']['num'] === $d['number'] && $g['values']['price'] === '1000');
$r = fill_save(['values' => ['cname' => 'דנה כהן', 'num' => 'זיוף']]);
check('שמירת הממלא לא מחזירה ערך מחושב מוסתר', array_key_exists('num', $r['computed']) && !array_key_exists('secret_calc', $r['computed']));
fill_complete([]);
$v = doc_values($did);
$d = db()->query("SELECT * FROM documents WHERE id = $did")->fetch();
check('בסיום: תאריך השלמה מתמלא, והמסמך הושלם', $d['status'] === 'completed' && $v['done'] === bulk_now('Y-m-d', $d['completed_at']));
$vals = doc_values($did);
ksort($vals);
check('תאריך ההשלמה כלול בטביעת האצבע', $d['final_hash'] === hash('sha256', json_encode(['template_version_id' => (int)$d['template_version_id'], 'values' => $vals], JSON_UNESCAPED_UNICODE)));

// Word: לא להדפיס → ריק; מתמלא לבד → תאריך ישראלי
check('Word: "לא להדפיס" יוצא ריק, ותאריך הנפקה בפורמט ישראלי',
    docx_value($by['iss'], '2026-10-14', []) === '14/10/2026');
$out = (function () use ($by, $v) {
    $sch = ['fields' => [array_merge($by['cost'], ['placeholder' => 'עלות']), array_merge($by['iss'], ['placeholder' => 'תאריך'])]];
    $w = 'xmlns:w="' . W_NS . '"';
    $p = sys_get_temp_dir() . '/f' . bin2hex(random_bytes(3)) . '.docx';
    $z = new ZipArchive(); $z->open($p, ZipArchive::CREATE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $z->addFromString('word/document.xml', "<?xml version=\"1.0\"?><w:document $w><w:body><w:p><w:r><w:t>עלות: {{עלות}} · {{תאריך}}</w:t></w:r></w:p><w:sectPr/></w:body></w:document>");
    $z->close();
    $b = docx_fill((string)file_get_contents($p), $sch, $v, []);
    file_put_contents($p, $b); $z = new ZipArchive(); $z->open($p); $x = $z->getFromName('word/document.xml'); $z->close(); unlink($p);
    return trim(html_entity_decode(strip_tags($x), ENT_QUOTES | ENT_XML1, "UTF-8"));
})();
check('Word: ' . $out, $out === 'עלות:  · ' . bulk_now('d/m/Y'));
check('ייצוא: שדה מוסתר כן נכלל (מידע פנימי), תאריך בפורמט ישראלי', (function () use ($a, $tid) {
    $rows = export_rows($a, tpl_owned($tid, $a), []);
    $i = array_search('עלות פנימית', $rows[0], true); $j = array_search('תאריך הנפקה', $rows[0], true);
    return $rows[1][$i] === 700 && $rows[1][$j] === bulk_now('d/m/Y');
})());

foreach (array_merge(glob("$tmp/files/*") ?: [], array_filter(glob("$tmp/{,.}*", GLOB_BRACE) ?: [], 'is_file')) as $f) @unlink($f);
@rmdir("$tmp/files");
@rmdir($tmp);
echo $fail ? "\n$fail נכשלו\n" : "\nהכול עבר\n";
exit($fail ? 1 : 0);
