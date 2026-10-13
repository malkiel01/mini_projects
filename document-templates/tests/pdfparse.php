<?php
/**
 * בדיקות שלב 10: PDF "לפרק" — הצד של השרת. הקריאה של הקובץ עצמה (שדות טופס,
 * שכבת טקסט) רצה בדפדפן, ונבדקת בבדיקת הדפדפן.
 * הרצה: php document-templates/tests/pdfparse.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-pdfp-' . bin2hex(random_bytes(4));
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
function upload(string $bytes): array
{
    global $tmp;
    $p = "$tmp/up-" . bin2hex(random_bytes(4));
    file_put_contents($p, $bytes);
    return ['file' => ['tmp_name' => $p, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes), 'name' => 'x']];
}
function user(string $email): array
{
    db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES (?, '!', 'x', 'active', ?)")
        ->execute([$email, now_iso()]);
    return db()->query('SELECT * FROM users WHERE id = ' . (int)db()->lastInsertId())->fetch();
}

$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
     . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
$a = user('a@x.com');
$b = user('b@x.com');
$src = fn($id) => tpl_get($a, $id)['template']['source'];

// ── בחירה בהעלאה ──
$p = tpl_create($a, ['name' => 'טופס', 'mode' => 'parse'], upload($pdf))['id'];
check('PDF עם "לפרק" — מקור pdf_parsed', $src($p) === 'pdf_parsed');
$l = tpl_create($a, ['name' => 'נעול', 'mode' => 'locked'], upload($pdf))['id'];
check('PDF עם "נעול" — מקור pdf_locked', $src($l) === 'pdf_locked');
$d = tpl_create($a, ['name' => 'ישן'], upload($pdf))['id'];
check('בלי בחירה — נעול, כמו קודם', $src($d) === 'pdf_locked');
$i = tpl_create($a, ['name' => 'תמונה', 'mode' => 'parse'], upload($png))['id'];
check('תמונה עם "לפרק" — נשארת תמונה', $src($i) === 'image');

// ── מעבר בין המצבים ──
tpl_set_source($a, ['id' => $p, 'source' => 'pdf_locked']);
check('"לא הצלחתי — להמשיך כנעול"', $src($p) === 'pdf_locked');
tpl_set_source($a, ['id' => $p, 'source' => 'pdf_parsed']);
check('ובחזרה ל"לפרק"', $src($p) === 'pdf_parsed');
throws('מצב לא מוכר', fn() => tpl_set_source($a, ['id' => $p, 'source' => 'docx']), 422);
throws('לא בתבנית תמונה', fn() => tpl_set_source($a, ['id' => $i, 'source' => 'pdf_parsed']), 422);
throws('לא בתבנית של אחר (404)', fn() => tpl_set_source($b, ['id' => $p, 'source' => 'pdf_locked']), 404);
share_set($a, ['type' => 'template', 'id' => $p, 'email' => 'b@x.com', 'level' => 'fill']);
throws('לא בשיתוף למילוי בלבד (403)', fn() => tpl_set_source($b, ['id' => $p, 'source' => 'pdf_locked']), 403);
share_set($a, ['type' => 'template', 'id' => $p, 'email' => 'b@x.com', 'level' => 'edit']);
tpl_set_source($b, ['id' => $p, 'source' => 'pdf_locked']);
check('עורך משותף — יכול', $src($p) === 'pdf_locked');

// ── שמירת התוצאה של הפירוק ──
$schema = ['pages' => [['w' => 595, 'h' => 842]], 'parsed' => true, 'fields' => [
    ['key' => 'full_name', 'type' => 'text', 'label' => 'שם מלא', 'page' => 0, 'x' => 0.5, 'y' => 0.1, 'w' => 0.3, 'h' => 0.03,
     'pdfName' => 'applicant.full_name'],
    ['key' => 'agree', 'type' => 'checkbox', 'label' => 'מסכים', 'page' => 0, 'x' => 0.1, 'y' => 0.2, 'w' => 0.02, 'h' => 0.02,
     'pdfName' => str_repeat('x', 200)],
]];
tpl_save($a, ['id' => $p, 'schema' => $schema]);
$s = tpl_get($a, $p)['template']['schema'];
check('הסימון "כבר פורק" נשמר', $s['parsed'] === true);
check('שם השדה בטופס המקורי נשמר', $s['fields'][0]['pdfName'] === 'applicant.full_name');
check('שם ארוך מדי נחתך', mb_strlen($s['fields'][1]['pdfName']) === 120);
unset($schema['parsed']);
tpl_save($a, ['id' => $l, 'schema' => $schema]);
check('תבנית בלי פירוק — parsed=false', tpl_get($a, $l)['template']['schema']['parsed'] === false);

// מסמך מתבנית מפורקת — אותו מסלול כמו כל PDF
$did = doc_create($a, ['template_id' => $p])['id'];
doc_save($a, ['id' => $did, 'values' => ['full_name' => 'משה כהן', 'agree' => '1']]);
check('מסמך מתבנית מפורקת נשמר ונקרא', doc_values($did)['full_name'] === 'משה כהן');

foreach (array_merge(glob("$tmp/files/*") ?: [], array_filter(glob("$tmp/{,.}*", GLOB_BRACE) ?: [], 'is_file')) as $f) @unlink($f);
@rmdir("$tmp/files");
@rmdir($tmp);
echo $fail ? "\n$fail נכשלו\n" : "\nהכול עבר\n";
exit($fail ? 1 : 0);
