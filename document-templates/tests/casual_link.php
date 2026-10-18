<?php
/**
 * בדיקות: לקוח מזדמן בקישור (שלב 3 בביקורת) — וואטסאפ בלי קוד, אותו קישור בשליחה חוזרת, נמען אחר
 * מבטל את הקישור והאימות, בלי "לקוח" כפול שחוסם השלמה, ומסמך בארכיון סוגר קישורים.
 * הרצה: php document-templates/tests/casual_link.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-casual-' . bin2hex(random_bytes(4));
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
function as_token(string $url): void { $_SERVER['HTTP_X_FILL_TOKEN'] = substr($url, strpos($url, '#t=') + 3); }

db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES ('o@x.com', '!', 'בעלים', 'active', ?)")->execute([now_iso()]);
$owner = db()->query('SELECT * FROM users WHERE id = 1')->fetch();
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
     . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
file_put_contents("$tmp/u.pdf", $pdf);
$tid = tpl_create($owner, ['name' => 'אישור'], ['file' => ['tmp_name' => "$tmp/u.pdf", 'error' => 0, 'size' => strlen($pdf)]])['id'];
$geo = ['page' => 0, 'x' => .1, 'y' => .3, 'w' => .5, 'h' => .05];
// בתבנית: תפקיד "לקוח" עם קוד למייל — ושדה שלו
tpl_save($owner, ['id' => $tid, 'schema' => ['pages' => [['w' => 595, 'h' => 842]],
    'roles' => [['key' => 'owner', 'label' => 'אני'], ['key' => 'client', 'label' => 'לקוח', 'auth' => 'email']],
    'fields' => [['key' => 'name', 'type' => 'text', 'label' => 'שם', 'required' => true, 'role' => 'client'] + $geo,
                 ['key' => 'note', 'type' => 'text', 'label' => 'הערה'] + $geo]]]);
$pid = fn(int $d, string $role) => (int)array_values(array_filter(doc_get($owner, $d)['document']['participants'], fn($p) => $p['role_key'] === $role))[0]['id'];

// ── וואטסאפ: בלי קוד, גם כשהתפקיד מוגדר "קוד למייל" ──
$d1 = doc_create($owner, ['template_id' => $tid])['id'];
$p1 = $pid($d1, 'client');
part_contact($owner, ['id' => $d1, 'participant_id' => $p1, 'name' => 'כהן', 'phone' => '050-1111111']);
check('רק טלפון — שמירת איש הקשר לא נחסמת', true);
throws('העתקת קישור לממלא בלי מייל עם קוד — הסבר', fn() => part_link($owner, ['id' => $d1, 'participant_id' => $p1, 'via' => 'link']), 422);
$w = part_link($owner, ['id' => $d1, 'participant_id' => $p1, 'via' => 'whatsapp']);
check('וואטסאפ עובר', str_starts_with($w['whatsapp'], 'https://wa.me/972501111111'));
as_token($w['url']);
$g = fill_get();
check('הלקוח נכנס בלי קוד', empty($g['need_otp']) && isset($g['document']));
$w2 = part_link($owner, ['id' => $d1, 'participant_id' => $p1, 'via' => 'whatsapp']);
check('שליחה חוזרת — אותו קישור', $w2['url'] === $w['url']);
part_link($owner, ['id' => $d1, 'participant_id' => $p1, 'via' => 'whatsapp', 'renew' => true]);
throws('"קישור חדש" מבטל את הקודם', fn() => fill_get(), 404);

// ── במייל: עדיין עם קוד ──
$d2 = doc_create($owner, ['template_id' => $tid])['id'];
$p2 = $pid($d2, 'client');
part_contact($owner, ['id' => $d2, 'participant_id' => $p2, 'name' => 'לוי', 'email' => 'levi@x.com']);
$e = part_link($owner, ['id' => $d2, 'participant_id' => $p2, 'via' => 'email']);
as_token($e['url']);
check('נשלח במייל — נדרש קוד', !empty(fill_get()['need_otp']));

// ── נמען אחר: הקישור והאימות של הקודם מתבטלים ──
db()->prepare('UPDATE participants SET verified_at = ? WHERE id = ?')->execute([now_iso(), $p2]);
$r = part_contact($owner, ['id' => $d2, 'participant_id' => $p2, 'name' => 'אחר', 'email' => 'other@x.com']);
check('החלפת נמען — מדווח שהקישור בוטל', $r['revoked'] === true);
throws('הקישור של הקודם לא עובד', fn() => fill_get(), 404);
$e2 = part_link($owner, ['id' => $d2, 'participant_id' => $p2, 'via' => 'email']);
as_token($e2['url']);
check('לנמען החדש — נדרש קוד משלו', !empty(fill_get()['need_otp']));
$r = part_contact($owner, ['id' => $d2, 'participant_id' => $p2, 'name' => 'אחר מעודכן', 'email' => 'other@x.com']);
check('עדכון פרטים של אותו נמען — הקישור נשאר', $r['revoked'] === false && fill_get()['ok']);

// ── "הכול ללקוח" במסמך מתבנית עם תפקיד: בלי "לקוח" כפול ──
$d3 = doc_create($owner, ['template_id' => $tid])['id'];
doc_assign($owner, ['id' => $d3, 'fields' => ['name' => ['link'], 'note' => ['link']], 'label' => 'לקוח']);
$roles = array_column(doc_get($owner, $d3)['document']['participants'], 'role_key');
check('רק ממלא אחד בקישור (תפקיד התבנית בלי שדות ירד)', $roles === ['link']);
$pl = $pid($d3, 'link');
part_contact($owner, ['id' => $d3, 'participant_id' => $pl, 'name' => 'דוד', 'phone' => '0522222222']);
$l = part_link($owner, ['id' => $d3, 'participant_id' => $pl, 'via' => 'whatsapp']);
as_token($l['url']);
fill_save(['values' => ['name' => 'דוד', 'note' => 'x']]);
check('הלקוח מסיים — המסמך נסגר', fill_complete()['document_completed'] === true);

// ── קוד למייל אחרי שהקישור כבר נשלח ──
$d4 = doc_create($owner, ['template_id' => $tid])['id'];
doc_assign($owner, ['id' => $d4, 'fields' => ['name' => ['link']]]);
$p4 = $pid($d4, 'link');
part_contact($owner, ['id' => $d4, 'participant_id' => $p4, 'name' => 'בלי מייל', 'phone' => '0523333333']);
part_link($owner, ['id' => $d4, 'participant_id' => $p4, 'via' => 'link']);
throws('נשלח בהעתקה ללקוח בלי מייל — אי אפשר להדליק קוד', fn() => doc_assign($owner, ['id' => $d4, 'fields' => ['name' => ['link']], 'auth' => 'email']), 422);
part_link($owner, ['id' => $d4, 'participant_id' => $p4, 'via' => 'whatsapp']);
doc_assign($owner, ['id' => $d4, 'fields' => ['name' => ['link']], 'auth' => 'email']);
check('נשלח בוואטסאפ — מותר, והוא ממשיך בלי קוד', true);

// ── ארכיון סוגר את הקישורים ──
$d5 = doc_create($owner, ['template_id' => $tid])['id'];
$p5 = $pid($d5, 'client');
part_contact($owner, ['id' => $d5, 'participant_id' => $p5, 'name' => 'א', 'phone' => '0524444444']);
$l5 = part_link($owner, ['id' => $d5, 'participant_id' => $p5, 'via' => 'whatsapp']);
doc_archive($owner, ['id' => $d5]);
as_token($l5['url']);
throws('מסמך בארכיון — הקישור לא נפתח', fn() => fill_get(), 404);

echo $fail ? "\n✗ $fail נכשלו\n" : "\n✓ הכול עבר\n";
exit($fail ? 1 : 0);
