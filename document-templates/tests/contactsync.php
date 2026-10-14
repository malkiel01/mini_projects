<?php
/**
 * בדיקות: ממלא בקישור ← איש קשר: קישור, סנכרון בשני הכיוונים, ממלא קבוע בתבנית.
 * הרצה: php document-templates/tests/contactsync.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-csync-' . bin2hex(random_bytes(4));
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
function as_link(string $url): void { $_SERVER['HTTP_X_FILL_TOKEN'] = substr($url, strpos($url, '#t=') + 3); }


$now = now_iso();
db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES ('a@x.com', '!', 'אבי', 'active', ?), ('b@x.com', '!', 'בני', 'active', ?)")->execute([$now, $now]);
$a = db()->query('SELECT * FROM users WHERE id = 1')->fetch();
$b = db()->query('SELECT * FROM users WHERE id = 2')->fetch();
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
     . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
file_put_contents("$tmp/u.pdf", $pdf);
$tid = tpl_create($a, ['name' => 'אישור'], ['file' => ['tmp_name' => "$tmp/u.pdf", 'error' => 0, 'size' => strlen($pdf)]])['id'];
$yair = contact_save($a, ['name' => 'יאיר אנקונינה', 'email' => 'yair@x.com', 'phone' => '050-1111111'])['id'];
$bens = contact_save($b, ['name' => 'של בני'])['id'];
$schema = fn(int $contact) => ['pages' => [['w' => 595, 'h' => 842]],
    'roles' => [['key' => 'owner', 'label' => 'אני'], ['key' => 'consul', 'label' => 'הקונסול', 'contact' => $contact], ['key' => 'client', 'label' => 'לקוח']],
    'fields' => [['key' => 'x', 'type' => 'text', 'label' => 'x', 'page' => 0, 'x' => .1, 'y' => .1, 'w' => .3, 'h' => .03, 'role' => 'client', 'required' => true]]];

// ── ממלא קבוע בתבנית ──
tpl_save($a, ['id' => $tid, 'schema' => $schema($bens)]);
check('איש קשר של משתמש אחר — לא נשמר כממלא קבוע', tpl_get($a, $tid)['template']['schema']['roles'][1]['contact'] === 0);
tpl_save($a, ['id' => $tid, 'schema' => $schema($yair)]);
check('איש קשר שלי — נשמר', tpl_get($a, $tid)['template']['schema']['roles'][1]['contact'] === $yair);
$did = doc_create($a, ['template_id' => $tid])['id'];
$pp = array_column(doc_get($a, $did)['document']['participants'], null, 'role_key');
check('מסמך חדש: הממלא הקבוע מקושר, עם הפרטים שלו', $pp['consul']['contact_id'] === $yair && $pp['consul']['email'] === 'yair@x.com');
check('ממלא בלי איש קשר קבוע — ריק', $pp['client']['contact_id'] === null);

// ── חובה: איש קשר לפני קישור ──
throws('קישור לממלא בלי איש קשר', fn() => part_link($a, ['id' => $did, 'participant_id' => $pp['client']['id'], 'via' => 'link']), 422);

// ── פרטים שהוקלדו → כרטיס ──
$r = part_contact($a, ['id' => $did, 'participant_id' => $pp['client']['id'], 'name' => 'רינה', 'phone' => '052-2222222']);
$rina = $r['contact_id'];
check('פרטים חדשים — נוצר כרטיס, והממלא מקושר', $rina > 0 && contact_view(contact_row($a, $rina))['phone'] === '052-2222222');
$did2 = doc_create($a, ['template_id' => $tid])['id'];
$pp2 = array_column(doc_get($a, $did2)['document']['participants'], null, 'role_key');
$r2 = part_contact($a, ['id' => $did2, 'participant_id' => $pp2['client']['id'], 'name' => 'רינה', 'phone' => '+972 52 222 2222']);
check('אותו טלפון בכתיב אחר — אותו כרטיס, בלי כפילות', $r2['contact_id'] === $rina && count(contact_list($a, [])['contacts']) === 2);

// ── סנכרון: כרטיס → ממלאים ──
contact_save($a, ['id' => $rina, 'name' => 'רינה לוי', 'phone' => '052-2222222', 'email' => 'rina@x.com']);
$v = fn($d, $role) => array_column(doc_get($a, $d)['document']['participants'], null, 'role_key')[$role];
check('עדכון בכרטיס מגיע לממלא בשני המסמכים', $v($did, 'client')['name'] === 'רינה לוי' && $v($did2, 'client')['email'] === 'rina@x.com');

// ── סנכרון: ממלא → כרטיס ──
part_contact($a, ['id' => $did, 'participant_id' => $pp['client']['id'], 'contact_id' => $rina, 'phone' => '052-3333333']);
check('טלפון שתוקן אצל הממלא — עודכן בכרטיס, ומשם במסמך השני', contact_view(contact_row($a, $rina))['phone'] === '052-3333333'
    && $v($did2, 'client')['phone'] === '052-3333333' && contact_view(contact_row($a, $rina))['email'] === 'rina@x.com');
throws('כרטיס של משתמש אחר', fn() => part_contact($a, ['id' => $did, 'participant_id' => $pp['client']['id'], 'contact_id' => $bens]), 404);

// ── איפה הוא ממלא ──
$fills = contact_fills($a, ['id' => $rina])['fills'];
check('בכרטיס: שני מסמכים, התפקיד והמצב', count($fills) === 2 && $fills[0]['role'] === 'לקוח' && $fills[0]['status'] === 'waiting' && $fills[0]['can_send']);
$cl = array_column(contact_list($a, [])['contacts'], null, 'id');
check('ברשימה: בכמה מסמכים הוא ממלא', $cl[$rina]['fills'] === 2 && $cl[$yair]['fills'] === 2);
throws('בני לא רואה את הכרטיס של אבי', fn() => contact_fills($b, ['id' => $rina]), 404);

// ── ממלא שסיים — נשאר כמו שהיה ──
part_link($a, ['id' => $did, 'participant_id' => $pp['client']['id'], 'via' => 'link']);
db()->prepare('UPDATE participants SET completed_at = ?, status = ? WHERE id = ?')->execute([$now, 'completed', $pp['client']['id']]);
contact_save($a, ['id' => $rina, 'name' => 'רינה כהן', 'phone' => '052-3333333']);
check('ממלא שסיים — לא משתנה (תיעוד)', $v($did, 'client')['name'] === 'רינה לוי' && $v($did2, 'client')['name'] === 'רינה כהן');
throws('אי אפשר לשנות פרטים של ממלא שסיים', fn() => part_contact($a, ['id' => $did, 'participant_id' => $pp['client']['id'], 'name' => 'x']), 409);

// ── ניתוק ──
part_contact($a, ['id' => $did2, 'participant_id' => $pp2['client']['id']]);
check('בלי פרטים — הממלא מתנתק', $v($did2, 'client')['contact_id'] === null);

// ── ייבוא מהטלפון ──
$before = count(contact_list($a, [])['contacts']);
$imp = contact_import($a, ['contacts' => [
    ['name' => 'שרה', 'phone' => '054-5555555'], ['name' => 'שרה שוב', 'phone' => '+972545555555'],   // כפול בתוך הייבוא
    ['name' => 'יאיר', 'email' => 'YAIR@x.com'],                                                       // כבר קיים
    ['name' => '', 'phone' => '053-6666666'], ['name' => 'מייל שבור', 'email' => 'not-mail'], ['name' => ''],
]]);
$all = array_column(contact_list($a, [])['contacts'], null, 'name');
check("ייבוא: נוספו 3, דולגו 3 (כפולים וריק) — {$imp['added']}/{$imp['skipped']}", $imp['added'] === 3 && $imp['skipped'] === 3
    && count($all) === $before + 3 && isset($all['053-6666666']) && $all['מייל שבור']['email'] === '' && $all['שרה']['tags'] === ['מהטלפון']);

foreach (array_merge(glob("$tmp/files/*") ?: [], array_filter(glob("$tmp/{,.}*", GLOB_BRACE) ?: [], 'is_file')) as $f) @unlink($f);
@rmdir("$tmp/files");
@rmdir($tmp);
echo $fail ? "\n$fail נכשלו\n" : "\nהכול עבר\n";
exit($fail ? 1 : 0);
