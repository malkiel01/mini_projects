<?php
/**
 * בדיקות: בחירה מרשימה (קבועה וממקור חיצוני), מפתחות API, הנפקה מבחוץ, webhook.
 * מעלה שרת מדומה (tests/fixtures/crm.php) על פורט מקומי.
 * הרצה: php document-templates/tests/integrations.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-int-' . bin2hex(random_bytes(4));
define('DT_DATA_DIR', $tmp);
define('DT_MAIL_SINK', "$tmp/mail.log");
define('DT_ALLOW_LOCAL_FETCH', true);
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

// המערכת החיצונית המדומה
$port = 18000 + random_int(0, 999);
$hookLog = "$tmp/hooks.log";
$srv = proc_open(['php', '-S', "127.0.0.1:$port", __DIR__ . '/fixtures/crm.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['HOOK_LOG' => $hookLog]);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$base = "http://127.0.0.1:$port";
$hooks = fn() => is_file($hookLog) ? array_map(fn($l) => json_decode($l, true), file($hookLog, FILE_IGNORE_NEW_LINES)) : [];

db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES ('a@x.com', '!', 'אבי לוי', 'active', ?)")->execute([now_iso()]);
db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES ('b@x.com', '!', 'בתיה', 'active', ?)")->execute([now_iso()]);
$a = db()->query('SELECT * FROM users WHERE id = 1')->fetch();
$b = db()->query('SELECT * FROM users WHERE id = 2')->fetch();

// ── כתובות אסורות ──
throws('מקור לכתובת לא-http נדחה', fn() => safe_target('file:///etc/passwd'), 422);
check('רק כתובות באינטרנט: שרת עצמי, רשת פרטית ו-169.254 נחסמים',
    !public_ip('127.0.0.1') && !public_ip('10.0.0.5') && !public_ip('192.168.1.1') && !public_ip('169.254.169.254') && !public_ip('::1') && public_ip('8.8.8.8'));

// ── מקור נתונים ──
$bad = src_save($a, ['name' => 'לקוחות', 'url' => "$base/customers", 'path' => 'data.items'])['source'];
check('מקור בלי האסימון — השגיאה נשמרת: ' . $bad['last_error'], str_contains((string)$bad['last_error'], '401') && $bad['row_count'] === 0);
$src = src_save($a, ['id' => $bad['id'], 'name' => 'לקוחות', 'url' => "$base/customers", 'path' => 'data.items',
    'headers' => "Authorization: Bearer s3cret\nHost: evil", 'value_key' => 'id', 'label_key' => 'name'])['source'];
check('מקור עם אסימון: 2 רשומות, מפתחות שטוחים: ' . implode(',', $src['keys']),
    $src['row_count'] === 2 && in_array('contact.city', $src['keys'], true) && !$src['last_error']);
check('הכותרות לא נחשפות לאחרים', src_view(src_row($src['id'], $a))['headers'] === null && src_view(src_row($src['id'], $a))['has_headers']);
throws('מקור של משתמש אחר — לא נמצא', fn() => src_sync($b, ['id' => $src['id']]), 404);

// ── תבנית עם בחירה קבועה ובחירה ממקור ──
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
     . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
file_put_contents("$tmp/u.pdf", $pdf);
$tid = tpl_create($a, ['name' => 'הזמנה'], ['file' => ['tmp_name' => "$tmp/u.pdf", 'error' => 0, 'size' => strlen($pdf)]])['id'];
$F = fn($key, $type, $label, $x = []) => ['key' => $key, 'type' => $type, 'label' => $label, 'page' => 0, 'x' => 0.1, 'y' => 0.1, 'w' => 0.3, 'h' => 0.03] + $x;
tpl_save($a, ['id' => $tid, 'schema' => ['pages' => [['w' => 595, 'h' => 842]],
    'roles' => [['key' => 'owner', 'label' => 'אני'], ['key' => 'client', 'label' => 'לקוח']],
    'fields' => [
        $F('size', 'select', 'מידה', ['options' => ['S', ['value' => 'M', 'label' => 'בינוני'], 'M', '', ['value' => 'L']], 'required' => true]),
        $F('cust', 'select', 'לקוח', ['source' => ['id' => $src['id'], 'fills' => [['key' => 'phone', 'from' => 'contact.phone'], ['key' => 'city', 'from' => 'contact.city']]]]),
        $F('phone', 'text', 'טלפון'),
        $F('city', 'text', 'עיר', ['hidden' => true]),
        $F('sig', 'signature', 'חתימה', ['role' => 'client']),
    ]]]);
$s = tpl_get($a, $tid)['template']['schema'];
$by = array_column($s['fields'], null, 'key');
check('אפשרויות קבועות: בלי כפילויות ובלי ריקות, תווית ברירת מחדל = הערך',
    array_column($by['size']['options'], 'value') === ['S', 'M', 'L'] && $by['size']['options'][1]['label'] === 'בינוני' && $by['size']['options'][2]['label'] === 'L');
check('בחירה ממקור: נשמרים מזהה המקור והמילויים', $by['cust']['source']['id'] === $src['id'] && count($by['cust']['source']['fills']) === 2);

// ── מקור של מישהו אחר: אי אפשר להפנות אליו ──
$tidB = tpl_create($b, ['name' => 'של בתיה'], ['file' => ['tmp_name' => "$tmp/u.pdf", 'error' => 0, 'size' => strlen($pdf)]])['id'];
tpl_save($b, ['id' => $tidB, 'schema' => ['pages' => [['w' => 595, 'h' => 842]], 'fields' => [$F('steal', 'select', 'גניבה', ['source' => ['id' => $src['id']]])]]]);
$stolen = tpl_get($b, $tidB)['template']['schema']['fields'][0];
check('תבנית של משתמש אחר לא יכולה להפנות למקור שלך', $stolen['source'] === null && select_options($stolen) === []);
share_set($a, ['type' => 'template', 'id' => $tid, 'email' => 'b@x.com', 'level' => 'edit']);
tpl_save($b, ['id' => $tid, 'schema' => tpl_get($b, $tid)['template']['schema']]);
check('שותף שעורך את התבנית — המקור של הבעלים נשאר', tpl_get($a, $tid)['template']['schema']['fields'][1]['source']['id'] === $src['id']);

// ── הנפקה ידנית: ערך חייב להיות ברשימה ──
$did = doc_create($a, ['template_id' => $tid])['id'];
throws('ערך שאינו ברשימה — נדחה', fn() => doc_save($a, ['id' => $did, 'values' => ['size' => 'XXL']]), 422);
doc_save($a, ['id' => $did, 'values' => ['size' => 'M', 'cust' => 'C-2']]);
$g = doc_get($a, $did)['document'];
$opt = array_column($g['schema']['fields'], null, 'key')['cust']['options'];
check('במסמך: הרשימה מהמקור, עם הנתונים למילוי: ' . $opt[1]['label'] . ' → ' . json_encode($opt[1]['data'], JSON_UNESCAPED_UNICODE),
    count($opt) === 2 && $opt[1]['data'] === ['phone' => '052-2222222', 'city' => 'אילת']);
check('במסמך: פרטי ההתחברות של המקור לא נשלחים', !isset(array_column($g['schema']['fields'], null, 'key')['cust']['source']['fills']));
check('תווית לתצוגה ולייצוא', select_label($by['size'], 'M') === 'בינוני' && select_label($by['cust'], 'C-2') === 'משה לוי'
    && docx_value($by['size'], 'M', []) === 'בינוני' && export_value($by['cust'], 'C-2', []) === 'משה לוי');

// ── מפתח API ──
$k = key_create($a, ['name' => 'CRM', 'webhook' => "$base/hook"]);
check('מפתח חדש: מוחזר פעם אחת, נשמר רק ה-hash', str_starts_with($k['key'], 'dtk_')
    && !db()->query("SELECT COUNT(*) FROM api_keys WHERE key_hash = " . db()->quote($k['key']))->fetchColumn());
$auth = function (?string $key) { $_SERVER['HTTP_X_API_KEY'] = $key ?? ''; unset($_SERVER['HTTP_AUTHORIZATION']); return api_auth(); };
throws('בלי מפתח — 401', fn() => $auth(null), 401);
throws('מפתח שגוי — 401', fn() => $auth('dtk_' . str_repeat('0', 48)), 401);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $k['key']; unset($_SERVER['HTTP_X_API_KEY']);
[$u, $key] = api_auth();
check('Authorization: Bearer — מזהה את בעל המפתח', (int)$u['id'] === 1);

check('ext_templates: התבנית ברשימה', ext_templates($u)['templates'][0]['name'] === 'הזמנה');
$et = ext_template($u, ['id' => $tid])['template'];
$ef = array_column($et['fields'], null, 'key');
check('ext_template: שדות, אפשרויות, ממלאים', $ef['cust']['options'][0]['label'] === 'דנה כהן' && $ef['size']['required'] && count($et['roles']) === 2);

$r = ext_issue($u, $key, ['template_id' => $tid, 'title' => 'הזמנה 77', 'ref' => 'ORD-77', 'sender_name' => 'חנות הדוגמה',
    'values' => ['מידה' => 'L', 'cust' => 'C-1', 'טלפון' => '03-0000000'],
    'participants' => [['role' => 'client', 'name' => 'דנה', 'email' => 'dana@x.com', 'send' => 'email']]]);
check('הנפקה: מספר, ref, קישור לממלא', $r['ref'] === 'ORD-77' && str_contains($r['links']['client'], 'fill.html#t=') && !$r['existing']);
check('הנפקה: ערכים לפי תווית ומפתח; בחירה ממקור ממלאת — בלי לדרוס מה שנשלח',
    $r['values']['size'] === 'L' && $r['values']['city'] === 'חיפה' && $r['values']['phone'] === '03-0000000' && $r['labels']->cust === 'דנה כהן');
$mail = json_decode(trim(array_slice(file(DT_MAIL_SINK), -1)[0]), true);
check('המייל לממלא — בשם השולח שנמסר, בלי שם המערכת', str_contains($mail['body'], 'חנות הדוגמה') && !str_contains($mail['body'], 'אבי לוי'));
$r2 = ext_issue($u, $key, ['template_id' => $tid, 'ref' => 'ORD-77', 'values' => ['מידה' => 'S']]);
check('אותו ref — אותו מסמך (לא נוצר כפול)', $r2['existing'] && $r2['id'] === $r['id']
    && (int)db()->query("SELECT COUNT(*) FROM documents WHERE external_ref = 'ORD-77'")->fetchColumn() === 1);
throws('ערך שאינו ברשימה דרך ה-API — נדחה', fn() => ext_update($u, ['ref' => 'ORD-77', 'values' => ['size' => 'XL']]), 422);
check('ext_update לפי ref', ext_update($u, ['ref' => 'ORD-77', 'values' => ['size' => 'S']])['document']['values']['size'] === 'S');
throws('ext_file מתבנית PDF — 409 עם הסבר', fn() => ext_file($u, ['id' => $r['id']]), 409);
throws('מסמך של מישהו אחר — לא נמצא', fn() => ext_document($b, ['id' => $r['id']]), 404);

// ── הממלא בקישור: לא מקבל ערכים של שדה מוסתר, גם לא דרך הבחירה ──
$_SERVER['HTTP_X_FILL_TOKEN'] = substr($r['links']['client'], strpos($r['links']['client'], '#t=') + 3);
$fg = fill_get()['document'];
$fcust = array_column($fg['schema']['fields'], null, 'key')['cust'];
check('הממלא: הבחירה בלי נתוני "עיר" המוסתרת', !array_key_exists('city', $fcust['options'][0]['data'] ?? []) && ($fcust['options'][0]['data']['phone'] ?? '') === '050-1111111');
fill_save(['values' => ['sig' => 'data:image/png;base64,iVBORw0KGgo=']]);
fill_complete();
$h = $hooks();
$events = array_map(fn($x) => json_decode($x['body'], true)['event'], $h);
check('webhook: ממלא סיים, ואז המסמך הושלם: ' . implode(', ', $events), $events === ['participant.completed', 'document.completed']);
$last = end($h);
check('webhook חתום ב-HMAC עם הסוד של המפתח, ונושא את ה-ref',
    $last['sig'] === 'sha256=' . hash_hmac('sha256', $last['body'], $k['secret']) && json_decode($last['body'], true)['document']['ref'] === 'ORD-77');
check('ext_document: הושלם, עם טביעת אצבע', ($d = ext_document($u, ['ref' => 'ORD-77'])['document'])['status'] === 'completed' && strlen((string)$d['final_hash']) === 64);

key_revoke($a, ['id' => $k['id']]);
throws('מפתח שבוטל — 401', fn() => api_auth(), 401);
src_delete($a, ['id' => $src['id']]);
check('מקור שנמחק — הבחירה ריקה, אבל ערך קיים עוד מוצג', select_options($by['cust']) === [] && select_label($by['cust'], 'C-2') === 'C-2');

proc_terminate($srv);
foreach (array_merge(glob("$tmp/files/*") ?: [], array_filter(glob("$tmp/{,.}*", GLOB_BRACE) ?: [], 'is_file')) as $f) @unlink($f);
@rmdir("$tmp/files");
@rmdir($tmp);
echo $fail ? "\n$fail נכשלו\n" : "\nהכול עבר\n";
exit($fail ? 1 : 0);
