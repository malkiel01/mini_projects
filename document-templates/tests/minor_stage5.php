<?php
/**
 * בדיקות: שלב 5 בביקורת — תקלות קלות: ביטול קישור, תקרת קודים ליום, רשימה גדולה אחרי אימות,
 * "link" שמור, הבעלים לא שורף הזמנה, צופה לא רואה IP ופרטי לקוח, ו"שיתופים" רק של מה שנשלח.
 * הרצה: php document-templates/tests/minor_stage5.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-minor-' . bin2hex(random_bytes(4));
define('DT_DATA_DIR', $tmp);
define('DT_MAIL_SINK', "$tmp/mail.log");
require __DIR__ . '/../lib/participants.php';
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
    db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES (?, '!', ?, 'active', ?)")->execute([$email, $email, now_iso()]);
    return db()->query('SELECT * FROM users WHERE id = ' . (int)db()->lastInsertId())->fetch();
}
$owner = user('o@x.com'); $viewer = user('v@x.com');
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
     . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
file_put_contents("$tmp/u.pdf", $pdf);
$tid = tpl_create($owner, ['name' => 'ת'], ['file' => ['tmp_name' => "$tmp/u.pdf", 'error' => 0, 'size' => strlen($pdf)]])['id'];
$geo = ['page' => 0, 'x' => .1, 'y' => .3, 'w' => .5, 'h' => .05];
tpl_save($owner, ['id' => $tid, 'schema' => ['pages' => [['w' => 595, 'h' => 842]],
    'roles' => [['key' => 'owner', 'label' => 'אני'], ['key' => 'link', 'label' => 'תפקיד בשם שמור', 'auth' => 'email'], ['key' => 'tmpl', 'label' => 'מתבנית']],
    'fields' => [['key' => 'name', 'type' => 'text', 'label' => 'שם', 'role' => 'link_role'] + $geo, ['key' => 't', 'type' => 'text', 'label' => 'ת', 'role' => 'tmpl'] + $geo]]]);
$keys = array_column(tpl_get($owner, $tid)['template']['schema']['roles'], 'key');
check('"link" שמור — תפקיד בתבנית מקבל שם אחר', !in_array('link', $keys, true) && in_array('link_role', $keys, true));

$d = doc_create($owner, ['template_id' => $tid])['id'];
$pid = (int)array_values(array_filter(doc_get($owner, $d)['document']['participants'], fn($p) => $p['role_key'] === 'link_role'))[0]['id'];
check('"שיתופים": מסמך שלא נשלח — לא מופיע', fill_shares($owner)['shares'] === []);
part_contact($owner, ['id' => $d, 'participant_id' => $pid, 'name' => 'לקוח', 'email' => 'c@x.com', 'phone' => '0501111111']);
$l = part_link($owner, ['id' => $d, 'participant_id' => $pid, 'via' => 'email']);
check('"שיתופים": אחרי שליחה — מופיע', count(fill_shares($owner)['shares']) === 1);

// רשימה גדולה — רק אחרי אימות הקוד
$_SERVER['HTTP_X_FILL_TOKEN'] = substr($l['url'], strpos($l['url'], '#t=') + 3);
throws('רשימה בלי אימות קוד', fn() => list_opts(null, ['list' => 'x', 'level' => 0]), 401);

// תקרת קודים ליום
for ($i = 0; $i < OTP_MAX_SENDS; $i++) {
    db()->exec("UPDATE participants SET otp_expires_at = NULL WHERE id = $pid");
    fill_otp_send();
}
db()->exec("UPDATE participants SET otp_expires_at = NULL WHERE id = $pid");
throws('יותר מ-' . OTP_MAX_SENDS . ' קודים ביום — נחסם', fn() => fill_otp_send(), 429);

// ביטול קישור
part_revoke($owner, ['id' => $d, 'participant_id' => $pid]);
throws('אחרי ביטול — הקישור לא נפתח', fn() => fill_get(), 404);
throws('ביטול שני — אין קישור', fn() => part_revoke($owner, ['id' => $d, 'participant_id' => $pid]), 409);

// צופה: בלי IP ובלי פרטי לקוח
share_set($owner, ['type' => 'document', 'id' => $d, 'email' => 'v@x.com', 'level' => 'view']);
$vd = doc_get($viewer, $d)['document'];
check('צופה: אין כתובות IP ביומן', array_filter(array_column($vd['events'], 'ip')) === []);
$vp = array_values(array_filter($vd['participants'], fn($p) => $p['role_key'] === 'link_role'))[0];
check('צופה: אין מייל וטלפון של הלקוח (השם כן)', $vp['email'] === '' && $vp['phone'] === '' && $vp['name'] === 'לקוח');
$op = array_values(array_filter(doc_get($owner, $d)['document']['participants'], fn($p) => $p['role_key'] === 'link_role'))[0];
check('הבעלים: רואה את הפרטים', $op['email'] === 'c@x.com');
$vs = fill_shares($viewer)['shares'];
check('צופה ב"שיתופים": בלי פרטי קשר', $vs && $vs[0]['email'] === '' && $vs[0]['phone'] === '');

// הבעלים לא שורף את ההזמנה שלו
$r = share_invite($owner, ['type' => 'template', 'id' => $tid, 'phone' => '0502222222', 'level' => 'view']);
$tok = substr($r['link'], strpos($r['link'], '#invite=') + 8);
invite_accept($owner, ['token' => $tok]);
check('הבעלים פתח את ההזמנה — היא עדיין פתוחה למוזמן', invite_info($tok)['used'] === false);

echo $fail ? "\n✗ $fail נכשלו\n" : "\n✓ הכול עבר\n";
exit($fail ? 1 : 0);
