<?php
/**
 * בדיקות: גופנים — העלאה (רק TTF/OTF), מי מקבל את הקובץ, בחירה בשדה, יישור אנכי.
 * הרצה: php document-templates/tests/fonts.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-fonts-' . bin2hex(random_bytes(4));
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
function up(string $path, string $name = ''): array { return ['file' => ['tmp_name' => $path, 'error' => 0, 'size' => filesize($path), 'name' => $name ?: basename($path)]]; }

foreach (['a@x.com' => 'אבי', 'b@x.com' => 'בתיה', 'c@x.com' => 'גדי'] as $e => $n) {
    db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES (?, '!', ?, 'active', ?)")->execute([$e, $n, now_iso()]);
}
[$a, $b, $c] = array_map(fn($i) => db()->query("SELECT * FROM users WHERE id = $i")->fetch(), [1, 2, 3]);

$ttf = __DIR__ . '/../assets/fonts/Alef-Regular.ttf';
file_put_contents("$tmp/fake.ttf", "<?php echo 'x';");
throws('קובץ שאינו גופן (גם אם השם .ttf) — נדחה', fn() => font_upload($a, [], up("$tmp/fake.ttf")), 415);
$font = font_upload($a, ['name' => 'אלף מותאם'], up($ttf))['font'];
check('העלאת TTF: נשמר עם השם', $font['format'] === 'ttf' && $font['name'] === 'אלף מותאם');
$stored = db()->query("SELECT path FROM fonts WHERE id = {$font['id']}")->fetchColumn();
check('הקובץ נשמר מוצפן, ונטען בחזרה זהה', file_get_contents(DT_FILES_DIR . "/$stored") !== file_get_contents($ttf) && load_file($stored) === file_get_contents($ttf));
check('רשימה: רק הגופנים שלי', count(font_list($a)['fonts']) === 1 && count(font_list($b)['fonts']) === 0);

// תבנית: גופן ויישור אנכי
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
     . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
file_put_contents("$tmp/u.pdf", $pdf);
$tid = tpl_create($a, ['name' => 'טופס'], up("$tmp/u.pdf"))['id'];
$F = fn($key, $x = []) => ['key' => $key, 'type' => 'text', 'label' => $key, 'page' => 0, 'x' => 0.1, 'y' => 0.1, 'w' => 0.3, 'h' => 0.05] + $x;
tpl_save($a, ['id' => $tid, 'schema' => ['pages' => [['w' => 595, 'h' => 842]], 'fields' => [
    $F('top', ['font' => $font['id'], 'valign' => 'top', 'align' => 'left']), $F('plain', ['valign' => 'diagonal', 'font' => 999])]]]);
$t = tpl_get($a, $tid)['template'];
$by = array_column($t['schema']['fields'], null, 'key');
check('שדה: הגופן והמיקום נשמרים', $by['top']['font'] === $font['id'] && $by['top']['valign'] === 'top' && $by['top']['align'] === 'left');
check('ערכים לא תקינים — ברירת מחדל (אמצע, גופן רגיל)', $by['plain']['valign'] === 'middle' && $by['plain']['font'] === 0);
check('התבנית מחזירה את שמות הגופנים', $t['fonts'] === [['id' => $font['id'], 'name' => 'אלף מותאם']]);

// בתיה: לא יכולה להפנות לגופן של אבי בתבנית שלה
$tidB = tpl_create($b, ['name' => 'של בתיה'], up("$tmp/u.pdf"))['id'];
tpl_save($b, ['id' => $tidB, 'schema' => ['pages' => [['w' => 595, 'h' => 842]], 'fields' => [$F('x', ['font' => $font['id']])]]]);
check('גופן של משתמש אחר — לא נשמר בתבנית שלי', tpl_get($b, $tidB)['template']['schema']['fields'][0]['font'] === 0);
// שיתוף: בתיה עורכת את התבנית של אבי — הגופן נשאר
share_set($a, ['type' => 'template', 'id' => $tid, 'email' => 'b@x.com', 'level' => 'edit']);
tpl_save($b, ['id' => $tid, 'schema' => tpl_get($b, $tid)['template']['schema']]);
check('שותפה שעורכת — הגופן של הבעלים נשאר', array_column(tpl_get($a, $tid)['template']['schema']['fields'], null, 'key')['top']['font'] === $font['id']);

// מי מקבל את הקובץ (font_send יוצא ב-exit — בודקים את ההרשאה עד לפניו)
$can = function (array $u, array $q) {
    try { $id = (int)$q['id'];
        $owner = (int)db()->query("SELECT owner_id FROM fonts WHERE id = $id")->fetchColumn();
        if ($owner === (int)$u['id']) return true;
        $schema = !empty($q['doc']) ? doc_schema(doc_owned((int)$q['doc'], $u, 'view'))
            : (json_decode((string)unseal(tpl_owned((int)($q['tpl'] ?? 0), $u, 'view')['schema_enc']), true) ?: []);
        return in_array($id, schema_fonts($schema), true);
    } catch (ApiError) { return false; } };
check('שותפה בתבנית מקבלת את הגופן; זר — לא', $can($b, ['id' => $font['id'], 'tpl' => $tid]) && !$can($c, ['id' => $font['id'], 'tpl' => $tid]));
check('זר עם תבנית משלו — לא מקבל גופן שאינו בה', !$can($b, ['id' => $font['id'], 'tpl' => $tidB]));

font_delete($a, ['id' => $font['id']]);
check('מחיקה: יוצא מהרשימה, אבל תבנית קיימת עוד מקבלת אותו', font_list($a)['fonts'] === [] && $can($b, ['id' => $font['id'], 'tpl' => $tid]));

foreach (array_merge(glob("$tmp/files/*") ?: [], array_filter(glob("$tmp/{,.}*", GLOB_BRACE) ?: [], 'is_file')) as $f) @unlink($f);
@rmdir("$tmp/files");
@rmdir($tmp);
echo $fail ? "\n$fail נכשלו\n" : "\nהכול עבר\n";
exit($fail ? 1 : 0);
