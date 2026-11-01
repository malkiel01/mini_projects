<?php
/**
 * בדיקות: שיתוף בטלפון — הזמנה בקישור חד-פעמי לוואטסאפ, שהופכת לשיתוף כשנכנסים או נרשמים.
 * הרצה: php document-templates/tests/share_invite.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-invite-' . bin2hex(random_bytes(4));
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
function user(string $email, string $name, int $dev = 0): array
{
    db()->prepare("INSERT INTO users (email, password_hash, display_name, status, is_developer, created_at) VALUES (?, '!', ?, 'active', ?, ?)")
        ->execute([$email, $name, $dev, now_iso()]);
    return db()->query('SELECT * FROM users WHERE id = ' . (int)db()->lastInsertId())->fetch();
}
$tok = fn(array $r) => substr($r['link'], strpos($r['link'], '#invite=') + 8);

$owner = user('owner@x.com', 'מלכיאל', 1);
$plain = user('plain@x.com', 'רגיל');
$bob   = user('bob@x.com', 'בועז');
$eve   = user('eve@x.com', 'אווה');

$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
     . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
file_put_contents("$tmp/u.pdf", $pdf);
$tid = tpl_create($owner, ['name' => 'אישור קבורה'], ['file' => ['tmp_name' => "$tmp/u.pdf", 'error' => 0, 'size' => strlen($pdf)]])['id'];
tpl_save($owner, ['id' => $tid, 'schema' => ['pages' => [['w' => 595, 'h' => 842]], 'fields' => [
    ['key' => 'name', 'type' => 'text', 'label' => 'שם', 'page' => 0, 'x' => .1, 'y' => .3, 'w' => .8, 'h' => .05]]]]);

// הזמנה
$r = share_invite($owner, ['type' => 'template', 'id' => $tid, 'phone' => '050-1234567', 'name' => 'בועז', 'level' => 'fill']);
check('קישור וואטסאפ למספר בפורמט בינלאומי', str_starts_with($r['whatsapp'], 'https://wa.me/972501234567?text='));
check('ההודעה: שם, תבנית, הרשאה וקישור', str_contains($r['text'], 'שלום בועז') && str_contains($r['text'], '"אישור קבורה"')
    && str_contains($r['text'], 'עורך (מילוי, הנפקת מסמכים)') && str_contains($r['text'], '#invite='));   // 15.4א: "ממלא" ← עורך שמנפיק וממלא
$t = $tok($r);
throws('טלפון לא תקין נדחה', fn() => share_invite($owner, ['type' => 'template', 'id' => $tid, 'phone' => '12', 'level' => 'edit', 'perms' => []]), 422);
throws('מי שאינו מנהל — לא מזמין', fn() => share_invite($bob, ['type' => 'template', 'id' => $tid, 'phone' => '0501234567', 'level' => 'edit', 'perms' => []]), 404);

$l = share_list($owner, ['type' => 'template', 'id' => $tid]);
check('ברשימה: הזמנה ממתינה עם שם, טלפון והרשאה', count($l['invites']) === 1 && $l['invites'][0]['name'] === 'בועז'
    && $l['invites'][0]['phone'] === '050-1234567' && $l['invites'][0]['level'] === 'edit' && $l['invites'][0]['perms'] === ['fill', 'issue']);
check('לא נשמר האסימון עצמו — רק hash', !db()->query("SELECT COUNT(*) FROM share_invites WHERE token_hash = " . db()->quote($t))->fetchColumn());

// מידע בלי כניסה
$i = invite_info($t);
check('מידע להזמנה: ממי, מה והרשאה', $i['from'] === 'מלכיאל' && $i['title'] === 'אישור קבורה' && $i['level'] === 'edit' && $i['level_name'] === 'עורך (מילוי, הנפקת מסמכים)' && !$i['used']);
throws('אסימון שגוי', fn() => invite_info(str_repeat('a', 48)), 410);

// קבלה
$a = invite_accept($bob, ['token' => $t]);
check('הקבלה מחזירה את התבנית', $a['type'] === 'template' && $a['id'] === $tid);
check('בועז קיבל שיתוף "ממלא"', access_level('template', $tid, (int)$owner['id'], $bob) === 'fill');
check('אחרי הקבלה — לא ממתין ברשימה, אלא משותף', share_list($owner, ['type' => 'template', 'id' => $tid])['invites'] === []
    && share_list($owner, ['type' => 'template', 'id' => $tid])['shares'][0]['name'] === 'בועז');
check('קבלה שנייה מאותו חשבון — מחזירה שוב את היעד', invite_accept($bob, ['token' => $t])['id'] === $tid);
throws('חשבון אחר — הקישור כבר נוצל', fn() => invite_accept($eve, ['token' => $t]), 409);
check('אווה לא קיבלה גישה', access_level('template', $tid, (int)$owner['id'], $eve) === null);

// שיתוף קיים גבוה יותר לא יורד
share_set($owner, ['type' => 'template', 'id' => $tid, 'user_id' => $bob['id'], 'level' => 'edit']);
$r2 = share_invite($owner, ['type' => 'template', 'id' => $tid, 'phone' => '0501234567', 'level' => 'edit', 'perms' => []]);
invite_accept($bob, ['token' => $tok($r2)]);
check('הזמנה ברמה נמוכה לא מורידה שיתוף קיים', access_level('template', $tid, (int)$owner['id'], $bob) === 'edit');

// שליחה חוזרת וביטול
$r3 = share_invite($owner, ['type' => 'template', 'id' => $tid, 'phone' => '0529999999', 'level' => 'edit', 'perms' => []]);
$inv = share_list($owner, ['type' => 'template', 'id' => $tid])['invites'][0];
$r4 = share_invite_resend($owner, ['type' => 'template', 'id' => $tid, 'invite_id' => $inv['id']]);
throws('אחרי שליחה חוזרת — הקישור הישן לא עובד', fn() => invite_info($tok($r3)), 410);
check('הקישור החדש עובד', invite_info($tok($r4))['title'] === 'אישור קבורה');
share_invite_cancel($owner, ['type' => 'template', 'id' => $tid, 'invite_id' => $inv['id']]);
throws('הזמנה שבוטלה — לא נפתחת', fn() => invite_accept($eve, ['token' => $tok($r4)]), 410);
check('ובוטלה — לא ברשימה', share_list($owner, ['type' => 'template', 'id' => $tid])['invites'] === []);

// פגה
$r5 = share_invite($owner, ['type' => 'template', 'id' => $tid, 'phone' => '0521111111', 'level' => 'edit', 'perms' => []]);
db()->exec("UPDATE share_invites SET expires_at = '2000-01-01T00:00:00Z' WHERE used_at IS NULL AND revoked_at IS NULL");
throws('הזמנה שפגה', fn() => invite_accept($eve, ['token' => $tok($r5)]), 410);

// מסמך
$did = doc_create($owner, ['template_id' => $tid])['id'];
$r6 = share_invite($owner, ['type' => 'document', 'id' => $did, 'phone' => '0523333333', 'name' => 'אווה', 'level' => 'view']);
invite_accept($eve, ['token' => $tok($r6)]);
check('מסמך: אווה צופה', access_level('document', $did, (int)$owner['id'], $eve) === 'view');

// הרשמה דרך הזמנה: ממפתח — פעיל מיד; ממשתמש רגיל — ממתין לאישור, והשיתוף כבר קיים
function register_via(string $email, string $token): array
{
    $_SERVER['REMOTE_ADDR'] = '10.0.0.' . random_int(1, 250);
    auth_register(['email' => $email, 'name' => 'חדש', 'password' => 'pass12345', 'invite' => $token]);
    $m = array_map(fn($l) => json_decode($l, true), file(DT_MAIL_SINK, FILE_IGNORE_NEW_LINES));
    $last = array_values(array_filter($m, fn($x) => $x['to'] === $email));
    preg_match('/#verify=([0-9a-f]+)/', end($last)['body'], $mm);
    return auth_verify(['token' => $mm[1]]);
}
$r7 = share_invite($owner, ['type' => 'template', 'id' => $tid, 'phone' => '0524444444', 'level' => 'fill']);
$v = register_via('new1@x.com', $tok($r7));
$n1 = db()->query("SELECT * FROM users WHERE email = 'new1@x.com'")->fetch();
check('הזמנה ממפתח: החשבון פעיל מיד', $v['status'] === 'active' && $n1['status'] === 'active');
check('והשיתוף כבר נוצר', access_level('template', $tid, (int)$owner['id'], $n1) === 'fill');

$tid2 = tpl_create($plain, ['name' => 'של רגיל'], ['file' => ['tmp_name' => "$tmp/u.pdf", 'error' => 0, 'size' => strlen($pdf)]])['id'];
$r8 = share_invite($plain, ['type' => 'template', 'id' => $tid2, 'phone' => '0525555555', 'level' => 'edit', 'perms' => []]);
$v = register_via('new2@x.com', $tok($r8));
$n2 = db()->query("SELECT * FROM users WHERE email = 'new2@x.com'")->fetch();
check('הזמנה ממשתמש רגיל: ממתין לאישור', $v['status'] === 'pending');
check('והשיתוף מחכה לו', access_level('template', $tid2, (int)$plain['id'], $n2) === 'view');
$v = register_via('new3@x.com', str_repeat('b', 48));
check('הרשמה עם הזמנה פסולה — רגילה, ממתינה', $v['status'] === 'pending');

echo $fail ? "\n✗ $fail נכשלו\n" : "\n✓ הכול עבר\n";
exit($fail ? 1 : 0);
