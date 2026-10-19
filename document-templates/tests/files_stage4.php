<?php
/**
 * בדיקות: קבצים (שלב 4 בביקורת) — קובץ שהלקוח החליף או הסיר נמחק ולא נספר במכסה, תקרה לשדה,
 * וקבצי Word/Excel שמזוהים כ-zip.
 * הרצה: php document-templates/tests/files_stage4.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-files4-' . bin2hex(random_bytes(4));
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
db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES ('o@x.com', '!', 'x', 'active', ?)")->execute([now_iso()]);
$owner = db()->query('SELECT * FROM users WHERE id = 1')->fetch();
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
     . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
$tid = tpl_create($owner, ['name' => 'קבצים'], ['file' => up($pdf)])['id'];
$F = fn($key, $extra = []) => ['key' => $key, 'type' => 'file', 'label' => $key, 'page' => 0,
    'x' => .1, 'y' => .1, 'w' => .3, 'h' => .03, 'role' => 'link'] + $extra;
tpl_save($owner, ['id' => $tid, 'schema' => ['pages' => [['w' => 595, 'h' => 842]], 'fields' => [
    $F('one', ['accept' => ['pdf']]), $F('many', ['accept' => ['pdf'], 'multiple' => true]), $F('office', ['accept' => ['office']])]]]);
$docId = doc_create($owner, ['template_id' => $tid])['id'];
doc_assign($owner, ['id' => $docId, 'fields' => ['one' => ['link'], 'many' => ['link'], 'office' => ['link']]]);
$pid = (int)doc_get($owner, $docId)['document']['participants'][0]['id'];
part_contact($owner, ['id' => $docId, 'participant_id' => $pid, 'name' => 'לקוח', 'phone' => '0501111111']);
$l = part_link($owner, ['id' => $docId, 'participant_id' => $pid, 'via' => 'whatsapp']);
$_SERVER['HTTP_X_FILL_TOKEN'] = substr($l['url'], strpos($l['url'], '#t=') + 3);
$rows = fn() => (int)db()->query("SELECT COUNT(*) FROM attachments WHERE document_id = $docId")->fetchColumn();

// ── הלקוח לא ממלא את המכסה בהחלפות ──
for ($i = 0; $i < 6; $i++) fill_upload(['field_key' => 'one'], ['file' => up($pdf . str_repeat(' ', $i), "v$i.pdf")]);
check('6 החלפות בשדה של קובץ אחד — נשאר קובץ אחד', $rows() === 1);
$used = usage_bytes((int)$owner['id']);
check('והמכסה סופרת רק אותו', $used < strlen($pdf) * 2 + 1000);

// ── הסרה בשמירה ──
$a1 = fill_upload(['field_key' => 'many'], ['file' => up($pdf, 'a.pdf')])['attachment']['id'];
$a2 = fill_upload(['field_key' => 'many'], ['file' => up($pdf, 'b.pdf')])['attachment']['id'];
fill_save(['values' => ['many' => json_encode([$a2])]]);
check('הלקוח הסיר קובץ — נמחק', !db()->query("SELECT COUNT(*) FROM attachments WHERE id = $a1")->fetchColumn());
check('השני נשאר', (int)db()->query("SELECT COUNT(*) FROM attachments WHERE id = $a2")->fetchColumn() === 1);

// ── תקרה לשדה ──
db()->exec("UPDATE attachments SET field_key = 'many' WHERE document_id = $docId");
$st = db()->prepare("INSERT INTO attachments (document_id, field_key, path, name_enc, mime, size, sha256, uploaded_by, at) VALUES (?, 'many', 'x', '', 'application/pdf', 1, '', 'user:1', ?)");
for ($i = 0; $i < FILES_PER_FIELD; $i++) $st->execute([$docId, now_iso()]);
throws('מעבר לתקרה בשדה — נחסם', fn() => fill_upload(['field_key' => 'many'], ['file' => up($pdf)]), 429);
db()->exec("DELETE FROM attachments WHERE path = 'x'");

// ── Word שמזוהה כ-zip ──
$z = "$tmp/w.docx"; $zip = new ZipArchive(); $zip->open($z, ZipArchive::CREATE);
$zip->addFromString('word/document.xml', '<w:document/>'); $zip->addFromString('[Content_Types].xml', '<Types/>'); $zip->close();
$r = fill_upload(['field_key' => 'office'], ['file' => up((string)file_get_contents($z), 'חוזה.docx')]);
check('קובץ Word מתקבל בשדה Office', str_contains($r['attachment']['mime'], 'wordprocessingml'));
$zz = "$tmp/x.zip"; $zip = new ZipArchive(); $zip->open($zz, ZipArchive::CREATE); $zip->addFromString('a.txt', 'x'); $zip->close();
throws('zip רגיל — לא מתקבל', fn() => fill_upload(['field_key' => 'office'], ['file' => up((string)file_get_contents($zz), 'a.zip')]), 415);

echo $fail ? "\n✗ $fail נכשלו\n" : "\n✓ הכול עבר\n";
exit($fail ? 1 : 0);
