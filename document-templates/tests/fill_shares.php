<?php
/**
 * בדיקות: שיתוף למילוי מתוך תבנית — בכל שיתוף בוחרים מי ממלא כל שדה; שדה "לעולם לא בקישור";
 * הקישור, הגבלות הממלא, צירוף קובץ, ורשימת השיתופים עם המצב.
 * הרצה: php document-templates/tests/fill_shares.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-fshare-' . bin2hex(random_bytes(4));
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

$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
     . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
file_put_contents("$tmp/u.pdf", $pdf);
$tid = tpl_create($owner, ['name' => 'אישור קבורה'], ['file' => ['tmp_name' => "$tmp/u.pdf", 'error' => 0, 'size' => strlen($pdf)]])['id'];
$geo = ['page' => 0, 'x' => .1, 'y' => .3, 'w' => .8, 'h' => .05];
tpl_save($owner, ['id' => $tid, 'schema' => ['pages' => [['w' => 595, 'h' => 842]], 'approval' => true, 'fields' => [
    ['key' => 'name', 'type' => 'text', 'label' => 'המנוח/ה', 'required' => true] + $geo,
    ['key' => 'idno', 'type' => 'text', 'label' => 'ת.ז'] + $geo,
    ['key' => 'idcopy', 'type' => 'file', 'label' => 'צילום ת.ז', 'accept' => 'image', 'required' => true] + $geo,
    ['key' => 'plot', 'type' => 'text', 'label' => 'מקום הקבורה', 'no_link' => true, 'required' => true] + $geo,
]]]);
check('בתבנית: "לעולם לא בקישור" נשמר', tpl_get($owner, $tid)['template']['schema']['fields'][3]['no_link'] === true);

// שני שיתופים מאותה תבנית — כל אחד עם שדות אחרים ללקוח
$d1 = doc_create($owner, ['template_id' => $tid])['id'];
$d2 = doc_create($owner, ['template_id' => $tid])['id'];
check('בלי שיתוף — אין ממלא בקישור', participants_of($d1) === []);
doc_assign($owner, ['id' => $d1, 'label' => 'משפחת כהן', 'fields' => ['name' => ['link'], 'idno' => ['owner', 'link'], 'idcopy' => ['link'], 'plot' => ['link']]]);
doc_assign($owner, ['id' => $d2, 'fields' => ['name' => ['link']]]);
$s1 = doc_get($owner, $d1)['document']['schema'];
$by = array_column($s1['fields'], null, 'key');
check('שיתוף 1: name ללקוח, idno לשנינו, קובץ ללקוח; מקום הקבורה — נשאר אצלי ("לעולם לא בקישור")',
    $by['name']['role'] === 'link' && $by['idno']['roles'] === ['owner', 'link'] && $by['idcopy']['role'] === 'link' && $by['plot']['role'] === 'owner');
check('ממלא בקישור נוצר — בשם שבחרתי', count(participants_of($d1)) === 1 && participants_of($d1)[0]['role_key'] === 'link'
    && array_column($s1['roles'], 'label', 'key')['link'] === 'משפחת כהן');
$by2 = array_column(doc_get($owner, $d2)['document']['schema']['fields'], null, 'key');
check('שיתוף 2 — הגדרות משלו (idno אצלי)', $by2['name']['role'] === 'link' && $by2['idno']['role'] === 'owner');

// הלקוח בקישור
$pid = (int)participants_of($d1)[0]['id'];
part_contact($owner, ['id' => $d1, 'participant_id' => $pid, 'name' => 'משה כהן', 'email' => 'moshe@x.com', 'phone' => '0501234567']);
as_token(part_link($owner, ['id' => $d1, 'participant_id' => $pid, 'via' => 'link'])['url']);
$g = fill_get()['document'];
check('הלקוח רואה את המסמך, והשדות שלו', $g['me']['role'] === 'משפחת כהן');
fill_save(['values' => ['name' => 'יוסף כהן ז״ל', 'idno' => '012345678']]);
fill_save(['values' => ['plot' => 'גוש 5']]);
check('הלקוח לא ממלא את מקום הקבורה (נדחה בשקט)', (doc_values($d1)['plot'] ?? '') === '');
throws('בלי צילום ת.ז (חובה) — לא מסיים', fn() => fill_complete(), 422);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
file_put_contents("$tmp/id.png", $png);
$up = fill_upload(['field_key' => 'idcopy'], ['file' => ['tmp_name' => "$tmp/id.png", 'name' => 'id.png', 'error' => 0, 'size' => strlen($png), 'type' => 'image/png']]);
check('הלקוח צירף צילום ת.ז', !empty($up['ok']));
fill_complete();
unset($_SERVER['HTTP_X_FILL_TOKEN']);
$sh = fill_shares($owner)['shares'];
$mine = array_values(array_filter($sh, fn($x) => $x['doc_id'] === $d1))[0];
check('ברשימת השיתופים: שם, תבנית, ו"מילא"', $mine['name'] === 'משה כהן' && $mine['template'] === 'אישור קבורה' && $mine['status'] === 'filled');
$other = array_values(array_filter($sh, fn($x) => $x['doc_id'] === $d2))[0];
check('השיתוף השני — "לא נשלח"', $other['status'] === 'waiting');
doc_save($owner, ['id' => $d1, 'values' => ['plot' => 'גוש 5 חלקה ז']]);
$mine = array_values(array_filter(fill_shares($owner)['shares'], fn($x) => $x['doc_id'] === $d1))[0];
check('כשהכול מלא — "ממתין לאישור"', $mine['status'] === 'awaiting');
doc_complete($owner, ['id' => $d1]);
$mine = array_values(array_filter(fill_shares($owner)['shares'], fn($x) => $x['doc_id'] === $d1))[0];
check('אחרי אישור — "הושלם"', $mine['status'] === 'done');

echo "\n" . ($fail ? "$fail נכשלו\n" : "הכול עבר\n");
exit($fail ? 1 : 0);
