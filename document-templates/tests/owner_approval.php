<?php
/**
 * בדיקות: "אישור רק דרכי" — מסמך שמשתמש משותף מנפיק מהתבנית ננעל רק באישור של בעל התבנית.
 * הרצה: php document-templates/tests/owner_approval.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-oappr-' . bin2hex(random_bytes(4));
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

db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES ('ed@x.com', '!', 'לאה', 'active', ?)")->execute([now_iso()]);
$ed = db()->query('SELECT * FROM users WHERE id = 2')->fetch();
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
     . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
file_put_contents("$tmp/u.pdf", $pdf);
$tid = tpl_create($owner, ['name' => 'אישור קונסולרי'], ['file' => ['tmp_name' => "$tmp/u.pdf", 'error' => 0, 'size' => strlen($pdf)]])['id'];
$geo = ['page' => 0, 'x' => .1, 'y' => .3, 'w' => .8, 'h' => .05];
tpl_save($owner, ['id' => $tid, 'schema' => ['pages' => [['w' => 595, 'h' => 842]], 'approval_owner' => true,
    'fields' => [['key' => 'name', 'type' => 'text', 'label' => 'שם', 'required' => true] + $geo]]]);
check('התבנית שומרת "אישור רק דרכי"', tpl_get($owner, $tid)['template']['schema']['approval_owner'] === true);
share_set($owner, ['type' => 'template', 'id' => $tid, 'email' => 'ed@x.com', 'level' => 'edit']);

// העורך מנפיק — לא יכול לנעול
$d = doc_create($ed, ['template_id' => $tid])['id'];
doc_save($ed, ['id' => $d, 'values' => ['name' => 'כהן']]);
$g = doc_get($ed, $d)['document'];
check('אצל המנפיק: מי מאשר — מלכיאל (לא אני)', $g['approver'] === ['name' => 'מלכיאל', 'me' => false] && !$g['awaiting_approval']);
throws('המנפיק לא נועל לבד', fn() => doc_complete($ed, ['id' => $d]), 403);
check('בעל התבנית רואה את המסמך', doc_get($owner, $d)['document']['approver']['me'] === true);

$r = doc_submit($ed, ['id' => $d]);
check('שליחה לאישור — מייל לבעל התבנית', $r['approver'] === 'מלכיאל' && str_contains(last_mail_to('owner@x.com')['subject'] ?? '', 'ממתין לאישורך'));
check('ממתין לאישור — אצל שניהם', doc_get($ed, $d)['document']['awaiting_approval'] && doc_get($owner, $d)['document']['awaiting_approval']);

// החזרה לתיקון, ושליחה שוב
$rr = doc_return($owner, ['id' => $d, 'note' => 'השם חסר שם פרטי']);
check('החזרה לתיקון — מייל למנפיק עם ההערה', $rr['mailed'] && str_contains(last_mail_to('ed@x.com')['body'] ?? '', 'השם חסר שם פרטי'));
check('אחרי החזרה — לא ממתין', !doc_get($ed, $d)['document']['awaiting_approval']);
doc_save($ed, ['id' => $d, 'values' => ['name' => 'משה כהן']]);
doc_submit($ed, ['id' => $d]);

// בעל התבנית מאשר
doc_complete($owner, ['id' => $d]);
$g = doc_get($ed, $d)['document'];
$types = array_column($g['events'], 'type');
check('אושר ונעל — והמנפיק קיבל מייל', $g['status'] === 'completed' && in_array('approved', $types, true) && in_array('submitted', $types, true)
    && str_contains(last_mail_to('ed@x.com')['subject'] ?? '', 'אושר'));

// בעל התבנית עצמו מנפיק — נועל כרגיל
$d2 = doc_create($owner, ['template_id' => $tid])['id'];
doc_save($owner, ['id' => $d2, 'values' => ['name' => 'לוי']]);
check('בעל התבנית מנפיק — בלי אישור, נועל בעצמו', doc_get($owner, $d2)['document']['approver'] === null && doc_complete($owner, ['id' => $d2])['ok']);

// בלי ההגדרה — העורך נועל לבד (כמו קודם)
tpl_save($owner, ['id' => $tid, 'schema' => ['pages' => [['w' => 595, 'h' => 842]], 'fields' => [['key' => 'name', 'type' => 'text', 'label' => 'שם'] + $geo]]]);
$d3 = doc_create($ed, ['template_id' => $tid])['id'];
check('בלי ההגדרה — העורך נועל בעצמו', doc_get($ed, $d3)['document']['approver'] === null && doc_complete($ed, ['id' => $d3])['ok']);

echo "\n" . ($fail ? "$fail נכשלו\n" : "הכול עבר\n");
exit($fail ? 1 : 0);
