<?php
/**
 * בדיקות שלב 6: צירוף קבצים ומכסות. מסד וקבצים זמניים.
 * הרצה: php document-templates/tests/attachments.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-att-' . bin2hex(random_bytes(4));
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
function up(string $bytes, string $name = 'x'): array
{
    global $tmp;
    $p = "$tmp/up-" . bin2hex(random_bytes(4));
    file_put_contents($p, $bytes);
    return ['tmp_name' => $p, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes), 'name' => $name];
}

foreach (['owner@x.com', 'other@x.com'] as $e) {
    db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES (?, '!', 'x', 'active', ?)")->execute([$e, now_iso()]);
}
$owner = db()->query('SELECT * FROM users WHERE id = 1')->fetch();
$other = db()->query('SELECT * FROM users WHERE id = 2')->fetch();

$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
     . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

$tid = tpl_create($owner, ['name' => 'הרשמה'], ['file' => up($pdf)])['id'];
$F = fn($key, $role, $extra = []) => ['key' => $key, 'type' => 'file', 'label' => $key, 'page' => 0,
    'x' => .1, 'y' => .1, 'w' => .3, 'h' => .03, 'required' => true, 'role' => $role] + $extra;
tpl_save($owner, ['id' => $tid, 'schema' => ['pages' => [['w' => 595, 'h' => 842]],
    'roles' => [['key' => 'client', 'label' => 'לקוח']],
    'fields' => [
        $F('id_card', 'client', ['accept' => ['image', 'pdf', 'evil'], 'maxMb' => 999]),
        $F('contract', 'owner', ['accept' => ['pdf'], 'multiple' => true]),
        $F('client_extra', 'client', ['accept' => ['pdf'], 'multiple' => true, 'required' => false]),
    ]]]);
$f = tpl_get($owner, $tid)['template']['schema']['fields'];
check('סוג לא מוכר נוקה, גודל נחסם ל-50', $f[0]['accept'] === ['pdf', 'image'] && $f[0]['maxMb'] === 50 && $f[0]['multiple'] === false);

$docId = doc_create($owner, ['template_id' => $tid])['id'];
$d = fn() => doc_get($owner, $docId)['document'];

// ── מנהל הטופס ──
$r = doc_upload($owner, ['id' => $docId, 'field_key' => 'contract'], ['file' => up($pdf, 'חוזה חתום.pdf')]);
check('העלאה: שם מקורי נשמר', $r['attachment']['name'] === 'חוזה חתום.pdf');
doc_upload($owner, ['id' => $docId, 'field_key' => 'contract'], ['file' => up($pdf, 'נספח.pdf')]);
check('כמה קבצים לשדה — נצברים', count(json_decode($d()['values']['contract'], true)) === 2);
throws('תמונה לשדה שמקבל רק PDF', fn() => doc_upload($owner, ['id' => $docId, 'field_key' => 'contract'], ['file' => up($png, 'a.png')]), 415);
throws('קובץ PHP בתחפושת', fn() => doc_upload($owner, ['id' => $docId, 'field_key' => 'contract'], ['file' => up('<?php system($_GET[1]);', 'x.pdf')]), 415);
$raw = (string)file_get_contents(DT_FILES_DIR . '/' . db()->query('SELECT path FROM attachments WHERE id = 1')->fetchColumn());
check('הקובץ מוצפן על הדיסק', !str_contains($raw, '%PDF'));
check('שם הקובץ מוצפן במסד', !str_contains((string)db()->query('SELECT name_enc FROM attachments WHERE id = 1')->fetchColumn(), 'חוזה'));
check('ביומן: הועלה קובץ', in_array('file_uploaded', array_column($d()['events'], 'type'), true));
check('מנהל הטופס רואה את הקבצים', count($d()['attachments']) === 2);
throws('משתמש אחר לא מעלה', fn() => doc_upload($other, ['id' => $docId, 'field_key' => 'contract'], ['file' => up($pdf)]), 404);

// הסרה = שמירה בלי המזהה
doc_save($owner, ['id' => $docId, 'values' => ['contract' => json_encode([1])]]);
check('הסרה מהשדה — נשאר קובץ אחד', json_decode($d()['values']['contract'], true) === [1]);
check('הקובץ שהוסר נשמר בתיעוד', count($d()['attachments']) === 2);
throws('מזהה של קובץ משדה אחר', fn() => doc_save($owner, ['id' => $docId, 'values' => ['id_card' => json_encode([1])]]), 422);

// ── הממלא ──
$pid = (int)db()->query('SELECT id FROM participants LIMIT 1')->fetchColumn();
part_contact($owner, ['id' => $docId, 'participant_id' => $pid, 'name' => 'ממלא']);
$link = part_link($owner, ['id' => $docId, 'participant_id' => $pid, 'via' => 'link']);
$_SERVER['HTTP_X_FILL_TOKEN'] = substr($link['url'], strpos($link['url'], '#t=') + 3);
throws('הממלא לא מעלה לשדה של המנפיק', fn() => fill_upload(['field_key' => 'contract'], ['file' => up($pdf)]), 403);
$a = fill_upload(['field_key' => 'id_card'], ['file' => up($png, 'תז.png')]);
fill_upload(['field_key' => 'id_card'], ['file' => up($pdf, 'תז-גב.pdf')]);
check('שדה של קובץ אחד — העלאה שנייה מחליפה', json_decode($d()['values']['id_card'], true) !== [$a['attachment']['id']]);
$fa = fill_get()['document']['attachments'];
check('הממלא רואה רק את הקבצים שלו', count($fa) === 1 && !in_array('חוזה חתום.pdf', array_column($fa, 'name'), true));
check('הקובץ שהוחלף אצל הממלא — נמחק (לא נספר במכסה)', (int)db()->query("SELECT COUNT(*) FROM attachments WHERE id = {$a['attachment']['id']}")->fetchColumn() === 0);
throws('הממלא לא מוריד קובץ של המנפיק', fn() => fill_file(1), 404);
throws('המנפיק לא שולח לממלא קובץ במקום שליחה', fn() => doc_upload($owner, ['id' => $docId, 'field_key' => 'id_card'], ['file' => up($png)]), 409);

// ── מכסות ──
$u = usage_view(1);
check('נפח בשימוש נמדד', $u['used_mb'] >= 0 && $u['storage_mb'] === 200 && $u['max_file_mb'] === 10);
dev_quota(['user_id' => 1, 'max_file_mb' => 1, 'storage_mb' => 1]);
$big = '%PDF-1.4' . str_repeat(' ', 1100000);
throws('מכסת גודל קובץ (1MB) גוברת על השדה', fn() => fill_upload(['field_key' => 'client_extra'], ['file' => up($big)]), 413);
dev_quota(['user_id' => 1, 'max_file_mb' => 50, 'storage_mb' => 1]);
throws('מכסת נפח: קובץ שיחרוג נדחה', fn() => fill_upload(['field_key' => 'client_extra'], ['file' => up($big)]), 507);
throws('מכסת נפח חלה גם על תבנית חדשה', fn() => tpl_create($owner, ['name' => 'x'], ['file' => up($big)]), 507);
$q = dev_quota(['user_id' => 1, 'storage_mb' => 500, 'max_file_mb' => 20, 'ai_enabled' => true, 'ai_credits' => 100])['quota'];
check('המפתח קובע מכסות', $q['storage_mb'] === 500 && $q['max_file_mb'] === 20 && $q['ai_enabled'] === true && $q['ai_credits'] === 100);
check('בתוך המכסה — עובר', fill_upload(['field_key' => 'client_extra'], ['file' => up($big)])['ok'] === true);
check('המכסה של חשבון אחר — ברירת מחדל', quota_of(2)['storage_mb'] === 200);

// ── סיום ──
fill_complete();
$doc = $d();
check('הממלא סיים עם קבצים; השרשרת שלמה', $doc['chain_ok'] === true);
throws('אחרי סיום — לא מעלים', fn() => fill_upload(['field_key' => 'id_card'], ['file' => up($png)]), 409);

foreach (array_merge(glob("$tmp/files/*") ?: [], array_filter(glob("$tmp/{,.}*", GLOB_BRACE) ?: [], 'is_file')) as $p) @unlink($p);
@rmdir("$tmp/files");
@rmdir($tmp);
echo $fail ? "\n$fail נכשלו\n" : "\nהכול עבר\n";
exit($fail ? 1 : 0);
