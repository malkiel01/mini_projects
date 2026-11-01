<?php
/**
 * בדיקות שלב 4: מסמכים, מספור, שמירה, יומן ונעילה. מסד וקבצים זמניים.
 * הרצה: php document-templates/tests/documents.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-doc-' . bin2hex(random_bytes(4));
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
function user(string $email): array
{
    db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES (?, '!', 'x', 'active', ?)")
        ->execute([$email, now_iso()]);
    return ['id' => (int)db()->lastInsertId()];
}
function upload(string $bytes): array
{
    global $tmp;
    $p = "$tmp/up-" . bin2hex(random_bytes(4));
    file_put_contents($p, $bytes);
    return ['file' => ['tmp_name' => $p, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes), 'name' => 'x']];
}

$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
     . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
$a = user('a@x.com');
$b = user('b@x.com');
$tid = tpl_create($a, ['name' => 'הסכם'], upload($pdf))['id'];

throws('תבנית בלי שדות — אי אפשר להנפיק', fn() => doc_create($a, ['template_id' => $tid]), 409);

$field = fn($key, $type, $req = false, $def = '') => ['id' => $key, 'key' => $key, 'type' => $type, 'label' => $key,
    'page' => 0, 'x' => .1, 'y' => .1, 'w' => .3, 'h' => .03, 'required' => $req, 'default' => $def];
tpl_save($a, ['id' => $tid, 'schema' => ['pages' => [['w' => 595, 'h' => 842]], 'fields' => [
    $field('name', 'text', true), $field('amount', 'number'), $field('day', 'date', false, '2026-01-01'),
    $field('agree', 'checkbox', true), $field('sig', 'signature', true),
]], 'numbering' => ['scheme' => 'prefix_year_seq', 'prefix' => 'שכ', 'digits' => 4]]);

// ── הנפקה ומספור ──
$y = gmdate('Y');
$d1 = doc_create($a, ['template_id' => $tid]);
$d2 = doc_create($a, ['template_id' => $tid]);
check('מספור: קידומת-שנה-רץ', $d1['number'] === "שכ-$y-0001" && $d2['number'] === "שכ-$y-0002");
check('ההנפקה סימנה את התבנית בשימוש', tpl_get($a, $tid)['template']['used'] === true);
throws('תבנית בשימוש — לא נמחקת', fn() => tpl_delete($a, ['id' => $tid]), 409);
$doc = doc_get($a, $d1['id'])['document'];
check('ערך ברירת מחדל הועתק למסמך', $doc['values']['day'] === '2026-01-01');
check('מצב התחלתי: טיוטה', $doc['status'] === 'draft');
check('יומן: נוצר', $doc['events'][0]['type'] === 'created');

$schemes = [
    ['seq', '', 3, '007'], ['yearly', '', 4, "$y-0007"], ['year_month_seq', 'ה', 4, 'ה-' . gmdate('Y/m') . '-007'],
    ['pattern', 'X', 2, 'X/' . gmdate('m') . '/07'], ['none', '', 4, null],
];
foreach ($schemes as [$sc, $pre, $dig, $want]) {
    $n = clean_numbering(['scheme' => $sc, 'prefix' => $pre, 'digits' => $dig, 'pattern' => '{prefix}/{mm}/{seq}']);
    check("פורמט $sc", format_number($n, 7, time()) === $want);
}

// ── שמירה ──
$r = doc_save($a, ['id' => $d1['id'], 'values' => ['name' => "משה\nכהן", 'amount' => '1500', 'nope' => 'x']]);
check('שמירה: שני שדות, שדה לא מוכר נדחה בשקט', $r['saved'] === 2);
$doc = doc_get($a, $d1['id'])['document'];
check('טקסט חד-שורתי — ירידת שורה הוחלפה ברווח', $doc['values']['name'] === 'משה כהן');
check('אחרי שמירה ראשונה — בתהליך', $doc['status'] === 'in_progress');
check('שמירה זהה לא נרשמת שוב', doc_save($a, ['id' => $d1['id'], 'values' => ['name' => 'משה כהן']])['saved'] === 0);
throws('מספר לא תקין', fn() => doc_save($a, ['id' => $d1['id'], 'values' => ['amount' => 'אלף']]), 422);
throws('תאריך שלא קיים בלוח (31 בפברואר)', fn() => doc_save($a, ['id' => $d1['id'], 'values' => ['day' => '2026-02-31']]), 422);
throws('תאריך בלי מבנה', fn() => doc_save($a, ['id' => $d1['id'], 'values' => ['day' => '2026-2-3']]), 422);
check('ברירת מחדל: תאריך שלא קיים נמחק', clean_default('date', '2026-13-01') === '' && clean_default('date', '2026-10-15') === '2026-10-15');
throws('תאריך לא תקין', fn() => doc_save($a, ['id' => $d1['id'], 'values' => ['day' => '31/12/2026']]), 422);
throws('חתימה שאינה PNG', fn() => doc_save($a, ['id' => $d1['id'], 'values' => ['sig' => 'javascript:alert(1)']]), 422);
$raw = db()->query("SELECT value_enc FROM field_values WHERE field_key = 'name'")->fetchColumn();
check('הערכים מוצפנים במסד', !str_contains($raw, 'משה'));

// ── פרטיות ──
throws('משתמש אחר לא רואה מסמך', fn() => doc_get($b, $d1['id']), 404);
throws('משתמש אחר לא שומר', fn() => doc_save($b, ['id' => $d1['id'], 'values' => ['name' => 'x']]), 404);
throws('משתמש אחר לא מנפיק מהתבנית', fn() => doc_create($b, ['template_id' => $tid]), 404);
check('הרשימה של ב׳ ריקה', doc_list($b, [])['documents'] === []);

// ── סיום ונעילה ──
throws('סיום עם שדות חובה חסרים', fn() => doc_complete($a, ['id' => $d1['id']]), 422);
$sig = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
doc_save($a, ['id' => $d1['id'], 'values' => ['agree' => true, 'sig' => $sig]]);
$list = doc_list($a, [])['documents'];
$one = array_values(array_filter($list, fn($d) => $d['id'] === $d1['id']))[0];
check('ברשימה: 3 מתוך 3 שדות חובה', $one['required'] === 3 && $one['filled'] === 3);
$c = doc_complete($a, ['id' => $d1['id']]);
check('סיום: נשמרה טביעת אצבע', strlen($c['hash']) === 64);
throws('מסמך שהושלם — נעול לשינוי', fn() => doc_save($a, ['id' => $d1['id'], 'values' => ['name' => 'אחר']]), 409);
throws('מסמך שהושלם — לא נמחק', fn() => doc_delete($a, ['id' => $d1['id']]), 409);

// ── יומן ──
$doc = doc_get($a, $d1['id'])['document'];
$types = array_column($doc['events'], 'type');
check('יומן: נוצר → נשמר → נשמר → הושלם', $types === ['created', 'values_saved', 'values_saved', 'completed']);
$sigEvent = $doc['events'][2]['detail']['changes'];
check('ביומן החתימה נרשמת כ"נחתם", לא כתמונה', !str_contains(json_encode($sigEvent), 'base64'));
check('שרשרת היומן שלמה', $doc['chain_ok'] === true);
// זיוף: שינוי ישיר של רשומה (עוקפים את החסימה כדי לבדוק שהשרשרת תופסת)
db()->exec('DROP TRIGGER events_no_update');
db()->exec("UPDATE events SET at = '2020-01-01T00:00:00Z' WHERE id = (SELECT MIN(id) FROM events WHERE document_id = {$d1['id']})");
check('שינוי ברשומה ביומן — השרשרת נשברת', doc_verify_chain($d1['id']) === false);

// ── ארכיון ומחיקה ──
doc_archive($a, ['id' => $d1['id']]);
check('ארכיון: לא ברשימה הרגילה', count(doc_list($a, [])['documents']) === 1);
check('ארכיון: מופיע עם archived=1', count(doc_list($a, ['archived' => 1])['documents']) === 2);
doc_delete($a, ['id' => $d2['id']]);
throws('טיוטה שנמחקה — לא נמצאת', fn() => doc_get($a, $d2['id']), 404);
$d3 = doc_create($a, ['template_id' => $tid]);
check('מספור ממשיך אחרי מחיקה (לא ממחזר מספרים)', $d3['number'] === "שכ-$y-0003");
check('29 בפברואר בשנה מעוברת — תקין', doc_save($a, ['id' => $d3['id'], 'values' => ['day' => '2028-02-29']])['saved'] === 1);

// ── 15.3: לחיצה על תבנית ← טיוטה; יוצאים בלי לגעת — נמחקת; נגעו — נשארת ──
check('untouched: מסמך שנשמר בו ערך — לא נמחק, בלי שגיאה', doc_delete($a, ['id' => $d3['id'], 'untouched' => true])['deleted'] === false);
check('...והוא עדיין קיים', doc_get($a, $d3['id'])['document']['id'] === $d3['id']);
$d4 = doc_create($a, ['template_id' => $tid]);
check('untouched: טיוטה שלא נגעו בה — נמחקת', doc_delete($a, ['id' => $d4['id'], 'untouched' => true])['deleted'] === true);
throws('...ולא נמצאת', fn() => doc_get($a, $d4['id']), 404);
$d5 = doc_create($a, ['template_id' => $tid]);
doc_tags($a, ['id' => $d5['id'], 'tags' => ['x']]);
check('untouched: טיוטה שקיבלה תגית — נשארת', doc_delete($a, ['id' => $d5['id'], 'untouched' => true])['deleted'] === false);

// ── גרסה: מסמך נשאר על הגרסה שלו ──
tpl_save($a, ['id' => $tid, 'schema' => ['pages' => [['w' => 595, 'h' => 842]], 'fields' => [$field('other', 'text')]]]);
check('אחרי עריכת התבנית — גרסה 2', tpl_get($a, $tid)['template']['version'] === 2);
check('מסמך ישן נשאר עם השדות של גרסה 1', count(doc_get($a, $d3['id'])['document']['schema']['fields']) === 5);

foreach (array_merge(glob("$tmp/files/*") ?: [], array_filter(glob("$tmp/{,.}*", GLOB_BRACE) ?: [], 'is_file')) as $p) @unlink($p);
@rmdir("$tmp/files");
@rmdir($tmp);
echo $fail ? "\n$fail נכשלו\n" : "\nהכול עבר\n";
exit($fail ? 1 : 0);
