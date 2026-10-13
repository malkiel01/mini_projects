<?php
/**
 * בדיקות שלב 8: אנשי קשר, מילוי אוטומטי, תגיות, חיפוש וסינון.
 * הרצה: php document-templates/tests/catalog.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-cat-' . bin2hex(random_bytes(4));
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
foreach ([['a@x.com', 'אבי'], ['b@x.com', 'בתיה']] as [$e, $n]) {
    db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES (?, '!', ?, 'active', ?)")->execute([$e, $n, now_iso()]);
}
$a = db()->query('SELECT * FROM users WHERE id = 1')->fetch();
$b = db()->query('SELECT * FROM users WHERE id = 2')->fetch();

$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
     . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
file_put_contents("$tmp/u.pdf", $pdf);
$tid = tpl_create($a, ['name' => 'הסכם שכירות'], ['file' => ['tmp_name' => "$tmp/u.pdf", 'error' => 0, 'size' => strlen($pdf)]])['id'];
$F = fn($key, $type, $cf = '') => ['key' => $key, 'type' => $type, 'label' => $key, 'page' => 0, 'x' => .1, 'y' => .1,
    'w' => .2, 'h' => .03, 'contactField' => $cf];
tpl_save($a, ['id' => $tid, 'schema' => ['pages' => [['w' => 595, 'h' => 842]], 'fields' => [
    $F('tenant', 'text', 'name'), $F('tz', 'text', 'id_number'), $F('phone', 'text', 'phone'), $F('sig', 'signature', 'name'),
    $F('notes', 'textarea', 'nonsense'), $F('rent', 'number'),
]]]);
$f = array_column(tpl_get($a, $tid)['template']['schema']['fields'], 'contactField', 'key');
check('שדה מאיש קשר נשמר; חתימה ושדה לא מוכר — לא', $f['tenant'] === 'name' && $f['sig'] === '' && $f['notes'] === '');

// ── אנשי קשר ──
throws('איש קשר בלי שם', fn() => contact_save($a, ['phone' => '050']), 422);
throws('מייל לא תקין', fn() => contact_save($a, ['name' => 'x', 'email' => 'nope']), 422);
$moshe = contact_save($a, ['name' => 'משה כהן', 'id_number' => '012345678', 'phone' => '050-1234567', 'email' => 'moshe@x.com',
    'tags' => ['דיירים', 'דיירים', ' VIP ']])['id'];
$dana = contact_save($a, ['name' => 'דנה לוי', 'phone' => '052-7654321'])['id'];
$list = contact_list($a, [])['contacts'];
check('רשימה ממוינת לפי שם', array_column($list, 'name') === ['דנה לוי', 'משה כהן']);
check('תגיות נוקו (כפילות, רווחים)', $list[1]['tags'] === ['דיירים', 'VIP']);
check('הכרטיס מוצפן במסד', !str_contains((string)db()->query('SELECT data_enc FROM contacts LIMIT 1')->fetchColumn(), 'משה'));
check('אנשי קשר פרטיים — לבתיה אין', contact_list($b, [])['contacts'] === []);
throws('בתיה לא עורכת את משה', fn() => contact_save($b, ['id' => $moshe, 'name' => 'x']), 404);

// ── קישור למסמך + מילוי אוטומטי ──
$d1 = doc_create($a, ['template_id' => $tid])['id'];
doc_save($a, ['id' => $d1, 'values' => ['phone' => '03-0000000']]);
$r = doc_contact($a, ['id' => $d1, 'contact_id' => $moshe]);
$v = doc_get($a, $d1)['document']['values'];
check('מילוי אוטומטי: שם ות"ז', $v['tenant'] === 'משה כהן' && $v['tz'] === '012345678');
check('שדה שכבר מלא לא נדרס', $v['phone'] === '03-0000000' && $r['filled'] === 2);
doc_contact($a, ['id' => $d1, 'contact_id' => $moshe, 'overwrite' => true]);
check('עם overwrite — נדרס', doc_get($a, $d1)['document']['values']['phone'] === '050-1234567');
check('המסמך מראה את איש הקשר', doc_get($a, $d1)['document']['contact'] === 'משה כהן');
check('ספירת מסמכים לאיש קשר', array_column(contact_list($a, [])['contacts'], 'documents', 'name')['משה כהן'] === 1);
check('יומן: נקבע איש קשר', in_array('contact_set', array_column(doc_get($a, $d1)['document']['events'], 'type'), true));

// במסמך משותף — אי אפשר לקשר כרטיס של מישהו אחר, וכרטיס הבעלים לא נחשף.
share_set($a, ['type' => 'document', 'id' => $d1, 'email' => 'b@x.com', 'level' => 'fill']);
throws('בתיה לא מקשרת את הכרטיס של אבי', fn() => doc_contact($b, ['id' => $d1, 'contact_id' => $moshe]), 404);
check('בתיה לא רואה את שם איש הקשר של אבי', doc_get($b, $d1)['document']['contact'] === null);

// ── תגיות ──
doc_tags($a, ['id' => $d1, 'tags' => ['שכירות', 'תל אביב']]);
$d2 = doc_create($a, ['template_id' => $tid])['id'];
doc_tags($a, ['id' => $d2, 'tags' => ['שכירות']]);
doc_contact($a, ['id' => $d2, 'contact_id' => $dana]);
doc_save($a, ['id' => $d2, 'values' => ['notes' => 'החוזה כולל חניה מקורה', 'rent' => '4200']]);
doc_complete($a, ['id' => $d1]) ?? null;
check('תיוג גם אחרי נעילה', doc_tags($a, ['id' => $d1, 'tags' => ['שכירות', 'תל אביב', 'הושלם']])['ok']);
tpl_tags($a, ['id' => $tid, 'tags' => ['נדל"ן']]);
check('תגית לתבנית, בלי גרסה חדשה', tpl_get($a, $tid)['template']['tags'] === ['נדל"ן'] && tpl_get($a, $tid)['template']['version'] === 1);
throws('צופה לא מתייג', fn() => (share_set($a, ['type' => 'document', 'id' => $d2, 'email' => 'b@x.com', 'level' => 'view'])
    && doc_tags($b, ['id' => $d2, 'tags' => ['x']])), 403);

// ── חיפוש וסינון ──
$ids = fn($q) => array_column(doc_list($a, $q)['documents'], 'id');
check('כל התגיות לרשימת הסינון', doc_list($a, [])['tags'] === ['הושלם', 'שכירות', 'תל אביב']);
check('סינון לפי תגית', $ids(['tag' => 'תל אביב']) === [$d1]);
check('סינון לפי איש קשר', $ids(['contact_id' => $dana]) === [$d2]);
check('חיפוש בערך שדה', $ids(['q' => 'חניה']) === [$d2]);
check('חיפוש בשם איש קשר', $ids(['q' => 'דנה']) === [$d2]);
check('חיפוש בת"ז שמולאה מכרטיס', $ids(['q' => '012345678']) === [$d1]);
check('חיפוש לא תלוי רישיות/מספר', $ids(['q' => '4200']) === [$d2]);
check('סינון לפי מצב', $ids(['status' => 'completed']) === [$d1]);
check('טווח תאריכים — היום', count($ids(['from' => gmdate('Y-m-d'), 'to' => gmdate('Y-m-d')])) === 2);
check('טווח תאריכים — מחר', $ids(['from' => gmdate('Y-m-d', time() + 86400)]) === []);
check('חיפוש לא מוצא חתימות (data:)', $ids(['q' => 'base64']) === []);
$bd = array_column(doc_list($b, ['by' => 'shared'])['documents'], 'id');
check('בתיה: "שותף איתי"', count($bd) === 2);
check('בתיה: "שלי" — ריק', doc_list($b, ['by' => 'me'])['documents'] === []);

// ── ארכיון איש קשר ──
contact_archive($a, ['id' => $dana]);
check('ארכיון: לא ברשימה הרגילה', array_column(contact_list($a, [])['contacts'], 'name') === ['משה כהן']);
check('המסמך עדיין מקושר', doc_get($a, $d2)['document']['contact'] === 'דנה לוי');

foreach (array_merge(glob("$tmp/files/*") ?: [], array_filter(glob("$tmp/{,.}*", GLOB_BRACE) ?: [], 'is_file')) as $p) @unlink($p);
@rmdir("$tmp/files");
@rmdir($tmp);
echo $fail ? "\n$fail נכשלו\n" : "\nהכול עבר\n";
exit($fail ? 1 : 0);
