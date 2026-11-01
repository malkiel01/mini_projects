<?php
/**
 * בדיקות אבטחה של השיתוף (שלב 2 בביקורת): הזמנות שנשארו אחרי מחיקה / ניתוק / הורדה בדרגה,
 * מנהל-שותף מול מנהל-שותף, המאשר של מסמך "רק באישור שלי", ומי מכבה את האישור.
 * הרצה: php document-templates/tests/security_share.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-sec-' . bin2hex(random_bytes(4));
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
function user(string $email, string $name): array
{
    db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES (?, '!', ?, 'active', ?)")
        ->execute([$email, $name, now_iso()]);
    return db()->query('SELECT * FROM users WHERE id = ' . (int)db()->lastInsertId())->fetch();
}
$tok = fn(array $r) => substr($r['link'], strpos($r['link'], '#invite=') + 8);

$owner = user('owner@x.com', 'בעלים');
$co    = user('co@x.com', 'שותף');
$co2   = user('co2@x.com', 'שותף2');
$bob   = user('bob@x.com', 'בוב');
$eve   = user('eve@x.com', 'אווה');

$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
     . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
file_put_contents("$tmp/u.pdf", $pdf);
$mk = function (array $u, string $name) use ($tmp, $pdf) {
    $id = tpl_create($u, ['name' => $name], ['file' => ['tmp_name' => "$tmp/u.pdf", 'error' => 0, 'size' => strlen($pdf)]])['id'];
    tpl_save($u, ['id' => $id, 'schema' => ['pages' => [['w' => 595, 'h' => 842]], 'fields' => [
        ['key' => 'name', 'type' => 'text', 'label' => 'שם', 'page' => 0, 'x' => .1, 'y' => .3, 'w' => .5, 'h' => .05]]]]);
    return $id;
};

// ── הזמנה של תבנית שנמחקה לא נדבקת לתבנית אחרת עם אותו מספר ──
$t1 = $mk($owner, 'של הבעלים');
$r = share_invite($owner, ['type' => 'template', 'id' => $t1, 'phone' => '0501234567', 'level' => 'edit', 'perms' => []]);
tpl_delete($owner, ['id' => $t1]);
check('מחיקת תבנית מוחקת את ההזמנות שלה', (int)db()->query("SELECT COUNT(*) FROM share_invites WHERE object_type='template' AND object_id=$t1")->fetchColumn() === 0);
$tb = $mk($bob, 'החוזה הפרטי של בוב');
check('המספר חזר לתבנית של בוב (התרחיש אמיתי)', $tb === $t1);
throws('ההזמנה הישנה לא חושפת את התבנית של בוב', fn() => invite_info($tok($r)), 410);
throws('ולא נותנת גישה אליה', fn() => invite_accept($eve, ['token' => $tok($r)]), 410);
check('אווה בלי גישה לתבנית של בוב', access_level('template', $tb, (int)$bob['id'], $eve) === null);
// גם אם השורה נשארה (מסד ישן) — יוצר ההזמנה כבר לא מנהל את התבנית, ולכן היא לא תקפה
db()->prepare("INSERT INTO share_invites (object_type, object_id, level, token_hash, created_by, created_at, expires_at) VALUES ('template', ?, 'edit', ?, ?, ?, ?)")
    ->execute([$tb, token_hash(str_repeat('c', 48)), $owner['id'], now_iso(), iso_in(86400)]);
throws('הזמנה יתומה של אחר — לא תקפה', fn() => invite_accept($eve, ['token' => str_repeat('c', 48)]), 410);

// ── מנהל-שותף שנותק / הורד בדרגה: ההזמנות שלו נופלות ──
$t2 = $mk($owner, 'משותפת');
share_set($owner, ['type' => 'template', 'id' => $t2, 'email' => 'co@x.com', 'level' => 'co_owner']);
$self = share_invite($co, ['type' => 'template', 'id' => $t2, 'phone' => '0501111111', 'level' => 'co_owner']);
share_remove($owner, ['type' => 'template', 'id' => $t2, 'user_id' => $co['id']]);
throws('מי שנותק לא חוזר דרך הזמנה שיצר', fn() => invite_accept($co, ['token' => $tok($self)]), 410);
check('ואין לו גישה', access_level('template', $t2, (int)$owner['id'], $co) === null);

share_set($owner, ['type' => 'template', 'id' => $t2, 'email' => 'co@x.com', 'level' => 'co_owner']);
$old = share_invite($co, ['type' => 'template', 'id' => $t2, 'phone' => '0502222222', 'level' => 'co_owner']);
share_set($owner, ['type' => 'template', 'id' => $t2, 'user_id' => $co['id'], 'level' => 'edit', 'perms' => []]);
throws('מי שהורד לצופה לא מעניק מנהל-שותף דרך הזמנה ישנה', fn() => invite_accept($eve, ['token' => $tok($old)]), 410);
check('אווה בלי גישה', access_level('template', $t2, (int)$owner['id'], $eve) === null);
$ok = share_invite($owner, ['type' => 'template', 'id' => $t2, 'phone' => '0503333333', 'level' => 'fill']);
check('הזמנה של הבעלים — עובדת כרגיל', invite_accept($eve, ['token' => $tok($ok)])['id'] === $t2);

// ── מנהל-שותף מול מנהל-שותף ──
share_set($owner, ['type' => 'template', 'id' => $t2, 'user_id' => $co['id'], 'level' => 'co_owner']);
share_set($owner, ['type' => 'template', 'id' => $t2, 'email' => 'co2@x.com', 'level' => 'co_owner']);
// 15.4א: בתבנית מנהל עושה הכול כמו הבעלים — גם משנה מנהל אחר (רק את היוצר לא)
check('מנהל משנה הרשאה של מנהל אחר', share_set($co, ['type' => 'template', 'id' => $t2, 'user_id' => $co2['id'], 'level' => 'edit', 'perms' => []])['ok']);
check('...והוא עורך שרואה בלבד', access_level('template', $t2, (int)$owner['id'], $co2) === 'view');
share_set($owner, ['type' => 'template', 'id' => $t2, 'user_id' => $co2['id'], 'level' => 'co_owner']);
share_set($co, ['type' => 'template', 'id' => $t2, 'user_id' => $eve['id'], 'level' => 'edit']);
check('מנהל משנה הרשאה לאחרים', access_level('template', $t2, (int)$owner['id'], $eve) === 'edit');
share_set($owner, ['type' => 'template', 'id' => $t2, 'user_id' => $co2['id'], 'level' => 'edit', 'perms' => []]);
check('הבעלים משנה למנהל', access_level('template', $t2, (int)$owner['id'], $co2) === 'view');

// ── "רק באישור שלי": המאשר מוגן, ועורך לא מכבה את האישור ──
$t3 = $mk($owner, 'באישור');
$sch = tpl_get($owner, $t3)['template']['schema'];
$sch['approval_owner'] = true;
tpl_save($owner, ['id' => $t3, 'schema' => $sch]);
share_set($owner, ['type' => 'template', 'id' => $t3, 'email' => 'bob@x.com', 'level' => 'edit']);
$sch2 = $sch; $sch2['approval_owner'] = false;
tpl_save($bob, ['id' => $t3, 'schema' => $sch2]);
check('עורך ששומר בלי האישור — האישור נשאר', tpl_get($owner, $t3)['template']['schema']['approval_owner'] === true);
$sch3 = $sch; $sch3['approval_owner'] = false;
tpl_save($owner, ['id' => $t3, 'schema' => $sch3]);
check('הבעלים כן מכבה', tpl_get($owner, $t3)['template']['schema']['approval_owner'] === false);
tpl_save($owner, ['id' => $t3, 'schema' => $sch]);

$d = doc_create($bob, ['template_id' => $t3])['id'];
throws('המנפיק לא מנתק את המאשר', fn() => share_remove($bob, ['type' => 'document', 'id' => $d, 'user_id' => $owner['id']]), 403);
throws('ולא מוריד לו הרשאה', fn() => share_set($bob, ['type' => 'document', 'id' => $d, 'user_id' => $owner['id'], 'level' => 'view']), 403);
throws('המאשר לא עוזב מסמך שמחכה לו', fn() => share_leave($owner, ['type' => 'document', 'id' => $d]), 403);
check('המאשר עדיין מנהל-שותף', access_level('document', $d, (int)$bob['id'], $owner) === 'co_owner');
share_set($bob, ['type' => 'document', 'id' => $d, 'email' => 'eve@x.com', 'level' => 'view']);
share_remove($bob, ['type' => 'document', 'id' => $d, 'user_id' => $eve['id']]);
check('אחרים — המנפיק מנתק כרגיל', access_level('document', $d, (int)$bob['id'], $eve) === null);

// ── הזמנה למסמך שנמחק ──
$dd = doc_create($owner, ['template_id' => $t2])['id'];
$ri = share_invite($owner, ['type' => 'document', 'id' => $dd, 'phone' => '0504444444', 'level' => 'view']);
doc_delete($owner, ['id' => $dd]);
throws('הזמנה למסמך שנמחק — לא תקפה', fn() => invite_info($tok($ri)), 410);

echo $fail ? "\n✗ $fail נכשלו\n" : "\n✓ הכול עבר\n";
exit($fail ? 1 : 0);
