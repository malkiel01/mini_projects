<?php
/**
 * בדיקות שלב 5: משתתפים, קישורים, אימות במייל, רצף, וסיום אוטומטי.
 * הרצה: php document-templates/tests/participants.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-part-' . bin2hex(random_bytes(4));
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
function as_token(string $url): void { $_SERVER['HTTP_X_FILL_TOKEN'] = substr($url, strpos($url, '#t=') + 3); }
function mails(): array
{
    return is_file(DT_MAIL_SINK) ? array_map(fn($l) => json_decode($l, true), file(DT_MAIL_SINK, FILE_IGNORE_NEW_LINES)) : [];
}
function last_mail_to(string $to): ?array
{
    foreach (array_reverse(mails()) as $m) if ($m['to'] === $to) return $m;
    return null;
}

db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES ('owner@x.com', '!', 'מלכיאל', 'active', ?)")->execute([now_iso()]);
$owner = db()->query('SELECT * FROM users WHERE id = 1')->fetch();
db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES ('other@x.com', '!', 'x', 'active', ?)")->execute([now_iso()]);
$other = ['id' => 2];

$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
     . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
$up = "$tmp/up.pdf";
file_put_contents($up, $pdf);
$tid = tpl_create($owner, ['name' => 'הסכם'], ['file' => ['tmp_name' => $up, 'error' => 0, 'size' => strlen($pdf)]])['id'];
$F = fn($key, $role, $type = 'text', $req = true) => ['key' => $key, 'type' => $type, 'label' => $key, 'page' => 0,
    'x' => .1, 'y' => .1, 'w' => .3, 'h' => .03, 'required' => $req, 'role' => $role];
$schema = ['pages' => [['w' => 595, 'h' => 842]], 'sequential' => true,
    'roles' => [['key' => 'tenant', 'label' => 'שוכר', 'auth' => 'none'], ['key' => 'guarantor', 'label' => 'ערב', 'auth' => 'email'],
                ['key' => 'owner', 'label' => 'ניסיון לדרוס'], ['key' => 'tenant', 'label' => 'כפול']],
    'fields' => [$F('price', 'owner'), $F('tenant_name', 'tenant'), $F('tenant_sig', 'tenant', 'signature'),
                 $F('g_name', 'guarantor'), $F('note', 'nobody', 'text', false)]];
tpl_save($owner, ['id' => $tid, 'schema' => $schema]);
$s = tpl_get($owner, $tid)['template']['schema'];
check('תפקידים: "אני" ראשון, כפול ודריסה נדחו', array_column($s['roles'], 'key') === ['owner', 'tenant', 'guarantor']);
check('שדה עם תפקיד לא קיים → של המנפיק', $s['fields'][4]['role'] === 'owner');

$docId = doc_create($owner, ['template_id' => $tid])['id'];
$doc = doc_get($owner, $docId)['document'];
check('נוצרו 2 משתתפים לפי הסדר', array_column($doc['participants'], 'role') === ['שוכר', 'ערב']);
[$pt, $pg] = array_column($doc['participants'], 'id');
check('ברצף: לפני שהמנפיק מילא — חסום', $doc['participants'][0]['blocked'] === 'קודם צריך למלא את השדות שלך');
throws('קישור לפני שהמנפיק מילא', fn() => part_link($owner, ['id' => $docId, 'participant_id' => $pt]), 409);

doc_save($owner, ['id' => $docId, 'values' => ['price' => '5000', 'tenant_name' => 'מילוי מראש']]);
check('לפני שליחה — המנפיק יכול למלא מראש שדה של משתתף', doc_get($owner, $docId)['document']['values']['tenant_name'] === 'מילוי מראש');
throws('ברצף: הערב עוד לא בתור', fn() => part_link($owner, ['id' => $docId, 'participant_id' => $pg]), 409);
part_contact($owner, ['id' => $docId, 'participant_id' => $pg, 'name' => 'דן']);
check('אימות במייל בלי כתובת — שמירת איש הקשר לא נחסמת', true);
part_contact($owner, ['id' => $docId, 'participant_id' => $pt, 'name' => 'משה', 'email' => 'moshe@x.com', 'phone' => '050-1234567']);
part_contact($owner, ['id' => $docId, 'participant_id' => $pg, 'name' => 'דן', 'email' => 'dan@x.com']);
throws('משתמש אחר לא מנפיק קישור', fn() => part_link($other, ['id' => $docId, 'participant_id' => $pt]), 404);

$l1 = part_link($owner, ['id' => $docId, 'participant_id' => $pt, 'via' => 'link']);
check('וואטסאפ עם מספר בינלאומי', str_starts_with($l1['whatsapp'], 'https://wa.me/972501234567?text='));
$l2 = part_link($owner, ['id' => $docId, 'participant_id' => $pt, 'via' => 'email']);
check('נשלח מייל עם הקישור', str_contains(last_mail_to('moshe@x.com')['body'] ?? '', $l2['url']));
check('המסמך פתוח למילוי (נשלח / בתהליך)', in_array(doc_get($owner, $docId)['document']['status'], ['sent', 'in_progress'], true));
throws('אחרי שליחה — המנפיק לא משנה שדה של השוכר', fn() => doc_save($owner, ['id' => $docId, 'values' => ['tenant_name' => 'x']]), 409);

check('שליחה חוזרת — אותו קישור (מה שכבר נשלח לא מתבטל)', $l2['url'] === $l1['url']);
$l3 = part_link($owner, ['id' => $docId, 'participant_id' => $pt, 'renew' => true]);
check('"קישור חדש" — כתובת אחרת', $l3['url'] !== $l1['url']);
as_token($l1['url']);
throws('קישור ישן בוטל אחרי "קישור חדש"', fn() => fill_get(), 404);
as_token($l3['url']);
$_SERVER['HTTP_X_FILL_TOKEN'] = 'not-a-token';
throws('אסימון לא תקין', fn() => fill_get(), 404);

// ── השוכר ──
as_token($l3['url']);
$f = fill_get()['document'];
check('השוכר רואה את המסמך ואת תפקידו', $f['me']['role'] === 'שוכר' && $f['values']['price'] === '5000');
check('לשוכר אין יומן ואין פרטי משתתפים', !isset($f['events']) && !isset($f['participants']));
check('נרשם "נפתח"', in_array('opened', array_column(doc_get($owner, $docId)['document']['events'], 'type'), true));
$r = fill_save(['values' => ['tenant_name' => 'משה כהן', 'price' => '1', 'g_name' => 'זיוף']]);
$vals = doc_get($owner, $docId)['document']['values'];
check('השוכר שומר רק את השדות שלו', $r['saved'] === 1 && $vals['tenant_name'] === 'משה כהן' && $vals['price'] === '5000' && !isset($vals['g_name']));
throws('סיום בלי חתימה', fn() => fill_complete(), 422);
fill_save(['values' => ['tenant_sig' => 'data:image/png;base64,iVBORw0KGgo=']]);
$c = fill_complete();
check('השוכר סיים, המסמך עוד לא הושלם', $c['document_completed'] === false);
throws('אחרי סיום — לא משנים', fn() => fill_save(['values' => ['tenant_name' => 'x']]), 409);
check('אחרי סיום — רואה, נעול', fill_get()['document']['me']['locked'] === true);

// ── הערב: קיבל קישור אוטומטית, עם אימות במייל ──
$m = last_mail_to('dan@x.com');
check('הבא בתור קיבל מייל אוטומטית', $m && str_contains($m['body'], 'fill.html#t='));
preg_match('/#t=([0-9a-f]+)/', $m['body'], $mm);
$_SERVER['HTTP_X_FILL_TOKEN'] = $mm[1];
$g = fill_get();
check('ערב: נדרש קוד, המייל מוסתר', $g['need_otp'] === true && $g['email'] === 'da•@x.com');
throws('ערב: בלי אימות — אין קובץ', fn() => fill_save(['values' => ['g_name' => 'דן']]), 401);
fill_otp_send();
throws('קוד שני מיד — נחסם', fn() => fill_otp_send(), 429);
preg_match('/(\d{6})/', last_mail_to('dan@x.com')['subject'], $code);
throws('קוד שגוי', fn() => fill_otp_verify(['code' => '000000']), 401);
fill_otp_verify(['code' => $code[1]]);
check('אחרי קוד נכון — רואה את המסמך', isset(fill_get()['document']));
fill_save(['values' => ['g_name' => 'דן לוי']]);
$c = fill_complete();
check('האחרון סיים — המסמך הושלם', $c['document_completed'] === true);
$doc = doc_get($owner, $docId)['document'];
check('מצב: הושלם, עם טביעת אצבע', $doc['status'] === 'completed' && strlen((string)$doc['final_hash']) === 64);
check('מנהל הטופס קיבל מייל', str_contains(last_mail_to('owner@x.com')['subject'] ?? '', 'הושלם'));
$types = array_column($doc['events'], 'type');
check('יומן מלא', $types === ['created', 'values_saved', 'link_issued', 'link_issued', 'link_issued', 'opened', 'values_saved',
    'values_saved', 'participant_completed', 'link_issued', 'otp_sent', 'verified', 'opened', 'values_saved', 'participant_completed', 'completed']);
check('ביומן: מי עשה מה (משתתף, לא משתמש)', $doc['events'][6]['actor'] === "participant:$pt");
check('שרשרת היומן שלמה', $doc['chain_ok'] === true);
throws('אחרי השלמה — קישור כבר לא מאפשר שינוי', fn() => fill_save(['values' => ['g_name' => 'x']]), 409);

foreach (array_merge(glob("$tmp/files/*") ?: [], array_filter(glob("$tmp/{,.}*", GLOB_BRACE) ?: [], 'is_file')) as $p) @unlink($p);
@rmdir("$tmp/files");
@rmdir($tmp);
echo $fail ? "\n$fail נכשלו\n" : "\nהכול עבר\n";
exit($fail ? 1 : 0);
