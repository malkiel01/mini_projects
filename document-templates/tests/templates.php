<?php
/**
 * בדיקות שלב 3: תבניות. מסד וקבצים זמניים.
 * הרצה: php document-templates/tests/templates.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-tpl-' . bin2hex(random_bytes(4));
define('DT_DATA_DIR', $tmp);
define('DT_MAIL_SINK', "$tmp/mail.log");
require __DIR__ . '/../lib/templates.php';
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
    $now = now_iso();
    db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES (?, '!', 'x', 'active', ?)")
        ->execute([$email, $now]);
    return ['id' => (int)db()->lastInsertId()];
}

$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
     . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

$a = user('a@x.com');
$b = user('b@x.com');

// ── יצירה ──
$id = tpl_create($a, ['name' => '  הסכם   שכירות '], upload($pdf))['id'];
$t = tpl_get($a, $id)['template'];
check('יצירה מ-PDF: מקור pdf_locked, גרסה 1, בלי שדות', $t['source'] === 'pdf_locked' && $t['version'] === 1 && !$t['schema']['fields']);
check('השם נוקה מרווחים', $t['name'] === 'הסכם שכירות');
$row = db()->query("SELECT name_enc FROM templates WHERE id = $id")->fetchColumn();
check('השם מוצפן במסד', !str_contains($row, 'שכירות'));
$path = db()->query("SELECT path FROM template_assets")->fetchColumn();
check('הקובץ מוצפן על הדיסק', !str_contains((string)file_get_contents(DT_FILES_DIR . "/$path"), '%PDF'));
check('הקובץ מפוענח חזרה זהה', load_file($path) === $pdf);

$img = tpl_create($a, ['name' => 'תמונה'], upload($png))['id'];
check('יצירה מתמונה: מקור image', tpl_get($a, $img)['template']['source'] === 'image');
throws('קובץ טקסט נדחה (לפי התוכן, לא השם)', fn() => tpl_create($a, ['name' => 'x'], upload("hello <?php echo 1;")), 415);
throws('בלי שם', fn() => tpl_create($a, ['name' => ' '], upload($pdf)), 422);

// ── פרטיות ──
throws('משתמש אחר לא רואה (404, לא 403)', fn() => tpl_get($b, $id), 404);
throws('משתמש אחר לא שומר', fn() => tpl_save($b, ['id' => $id, 'schema' => ['pages' => [['w' => 1, 'h' => 1]]]]), 404);
throws('משתמש אחר לא מוחק', fn() => tpl_delete($b, ['id' => $id]), 404);
check('הרשימה של ב׳ ריקה', tpl_list($b)['templates'] === []);
check('הרשימה של א׳: 2', count(tpl_list($a)['templates']) === 2);

// ── שמירה וניקוי ──
$schema = [
    'pages' => [['w' => 595, 'h' => 842]],
    'fields' => [
        ['id' => 'f1', 'key' => 'Tenant Name!', 'type' => 'text', 'label' => 'שם השוכר', 'page' => 0,
         'x' => 0.1, 'y' => 0.2, 'w' => 0.3, 'h' => 0.03, 'required' => true],
        ['id' => 'f2', 'key' => 'tenantname', 'type' => 'evil<script>', 'label' => 'כפול', 'page' => 7,
         'x' => 1.5, 'y' => -3, 'w' => 9, 'h' => 0.03, 'overflow' => 'nope'],
    ],
];
check('שמירה ראשונה — אותה גרסה', tpl_save($a, ['id' => $id, 'schema' => $schema])['version'] === 1);
$f = tpl_get($a, $id)['template']['schema']['fields'];
check('מפתח נוקה', $f[0]['key'] === 'tenantname');
check('מפתח כפול קיבל שם אחר', $f[1]['key'] !== 'tenantname');
check('סוג לא מוכר → טקסט', $f[1]['type'] === 'text');
check('שדה שחורג — נחסם לגבולות הדף', $f[1]['w'] == 1 && $f[1]['x'] == 0 && $f[1]['y'] == 0 && $f[1]['page'] === 0);
check('overflow לא מוכר → shrink', $f[1]['overflow'] === 'shrink');
throws('תבנית בלי דפים נדחית', fn() => tpl_save($a, ['id' => $id, 'schema' => ['pages' => []]]), 422);

// ── גרסאות ──
db()->exec("UPDATE templates SET used = 1 WHERE id = $id");
$v = tpl_save($a, ['id' => $id, 'schema' => $schema, 'name' => 'הסכם שכירות 2026'])['version'];
check('אחרי שימוש — שמירה יוצרת גרסה 2', $v === 2);
check('גרסה 1 נשמרה כמו שהייתה', (int)db()->query("SELECT COUNT(*) FROM template_versions WHERE template_id = $id")->fetchColumn() === 2);
check('הגרסה החדשה משתמשת באותו קובץ', tpl_get($a, $id)['template']['mime'] === 'application/pdf');
check('שינוי שם נשמר', tpl_get($a, $id)['template']['name'] === 'הסכם שכירות 2026');

// ── ארכיון ומחיקה ──
throws('תבנית בשימוש — אי אפשר למחוק', fn() => tpl_delete($a, ['id' => $id]), 409);
tpl_archive($a, ['id' => $id]);
check('ארכיון', tpl_get($a, $id)['template']['archived'] === true);
tpl_archive($a, ['id' => $id, 'restore' => true]);
check('שחזור מארכיון', tpl_get($a, $id)['template']['archived'] === false);
$imgPath = db()->query("SELECT a.path FROM template_assets a JOIN template_versions v ON v.id = a.template_version_id WHERE v.template_id = $img")->fetchColumn();
tpl_delete($a, ['id' => $img]);
check('מחיקה של תבנית שלא בשימוש — גם הקובץ נמחק', !is_file(DT_FILES_DIR . "/$imgPath"));
throws('אחרי מחיקה — לא נמצאת', fn() => tpl_get($a, $img), 404);

// צבע הטקסט: #rrggbb בלבד; ריק — שחור
$col = fn($c) => clean_schema(['pages' => [['w' => 595, 'h' => 842]], 'fields' => [['key' => 'x', 'type' => 'text', 'page' => 0, 'x' => .1, 'y' => .1, 'w' => .2, 'h' => .03] + ($c === null ? [] : ['color' => $c])]])['fields'][0]['color'];
check('צבע: ברירת מחדל ריק (שחור), #B91C1C נשמר באותיות קטנות, ערך לא תקין נזרק',
    $col(null) === '' && $col('#B91C1C') === '#b91c1c' && $col('red') === '' && $col('#12345') === '' && $col('#123456;x') === '');

$cs = fn($x) => clean_schema(['pages' => [['w' => 595, 'h' => 842]], 'fields' => []] + $x)['certificate'];
check('דף אישור: כבוי כברירת מחדל, נשמר כשמופעל', $cs([]) === false && $cs(['certificate' => true]) === true);

// סגנונות: שדה מקושר מקבל את התצוגה מהסגנון; סגנון שלא קיים — הקישור נופל
$F = fn($k, $x = []) => ['key' => $k, 'type' => 'text', 'page' => 0, 'x' => .1, 'y' => .1, 'w' => .2, 'h' => .03, 'fontSize' => 9, 'color' => '#15803d'] + $x;
$sc = clean_schema(['pages' => [['w' => 595, 'h' => 842]],
    'styles' => [['id' => 'title', 'name' => 'כותרת', 'fontSize' => 18, 'color' => '#B91C1C', 'align' => 'center', 'valign' => 'top', 'font' => 999]],
    'fields' => [$F('a', ['style' => 'title']), $F('b', ['style' => 'nope']), $F('c')]]);
$by = array_column($sc['fields'], null, 'key');
check('סגנון: השדה המקושר מקבל גודל, צבע, יישור ומיקום מהסגנון', $by['a']['style'] === 'title' && $by['a']['fontSize'] === 18
    && $by['a']['color'] === '#b91c1c' && $by['a']['align'] === 'center' && $by['a']['valign'] === 'top');
check('סגנון: גופן שאינו מותר — ברירת מחדל', $sc['styles'][0]['font'] === 0 && $by['a']['font'] === 0);
check('סגנון שלא קיים — הקישור נופל, הערכים של השדה נשארים', $by['b']['style'] === '' && $by['b']['fontSize'] === 9 && $by['b']['color'] === '#15803d');
check('שדה בלי סגנון — כמו שהוא', $by['c']['style'] === '' && $by['c']['fontSize'] === 9);

foreach (array_merge(glob("$tmp/files/*") ?: [], array_filter(glob("$tmp/{,.}*", GLOB_BRACE) ?: [], 'is_file')) as $p) @unlink($p);
@rmdir("$tmp/files");
@rmdir($tmp);
echo $fail ? "\n$fail נכשלו\n" : "\nהכול עבר\n";
exit($fail ? 1 : 0);
