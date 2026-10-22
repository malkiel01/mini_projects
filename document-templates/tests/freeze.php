<?php
/**
 * מסמך שהושלם לא משתנה כשמשהו חי משתנה אחריו (נספח הביקורת v0.3, DT-F06): תרגום של רשימת
 * ערכים (גם בטבלה), ותווית של בחירה ממקור נתונים. מסד וקבצים זמניים.
 * הרצה: php document-templates/tests/freeze.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-frz-' . bin2hex(random_bytes(4));
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

db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES ('a@x.com', '!', 'א', 'active', ?)")->execute([now_iso()]);
$a = db()->query('SELECT * FROM users WHERE id = 1')->fetch();

// רשימה עם תרגום
$l = list_save($a, ['name' => 'ערים', 'levels' => ['עיר'], 'rows' => [['חיפה'], ['אילת']],
    'translations' => ['0' => ['חיפה' => ['en' => 'Haifa'], 'אילת' => ['en' => 'Eilat']]]])['list'];
$retranslate = function (string $haifa) use ($a, $l) {
    $full = list_get($a, ['id' => $l['id']])['list'];
    list_save($a, ['id' => $l['id'], 'name' => $full['name'], 'levels' => $full['levels'], 'rows' => $full['rows'],
        'translations' => ['0' => ['חיפה' => ['en' => $haifa], 'אילת' => ['en' => 'Eilat']]]]);
};

// מקור נתונים — עותק שמור, טרי (בלי רשת)
$cfg = seal(json_encode(['url' => 'https://example.invalid/x', 'value_key' => 'id', 'label_key' => 'name']));
$rows = fn(string $name) => seal(json_encode([['id' => 'C-1', 'name' => $name]], JSON_UNESCAPED_UNICODE));
db()->prepare('INSERT INTO data_sources (owner_id, name_enc, config_enc, ttl_min, rows_enc, row_count, fetched_at, created_at)
    VALUES (1, ?, ?, 100000, ?, 1, ?, ?)')->execute([seal('לקוחות'), $cfg, $rows('דנה כהן'), now_iso(), now_iso()]);
$srcId = (int)db()->lastInsertId();

$F = fn($key, $type, $extra = []) => ['key' => $key, 'type' => $type, 'label' => $key, 'placeholder' => $key,
    'page' => 0, 'x' => 0, 'y' => 0, 'w' => 0.3, 'h' => 0.03] + $extra;
$ref = ['id' => $l['id'], 'level' => 0, 'lang' => 'en'];
$tid = tpl_create_authored($a, ['name' => 'הקפאה'])['id'];
tpl_save($a, ['id' => $tid, 'schema' => ['pages' => [['w' => 595, 'h' => 842]], 'fields' => [
    $F('city', 'select', ['list' => $ref]),
    $F('cust', 'select', ['source' => ['id' => $srcId]]),
    $F('rows', 'table', ['columns' => [['key' => 'c', 'label' => 'עיר', 'type' => 'select', 'list' => $ref]]]),
], 'body' => [['id' => 'b1', 'type' => 'p', 'text' => '{{city}} {{cust}}'], ['id' => 'b2', 'type' => 'table', 'table' => 'rows']]]]);

$out = function (int $id) use ($a): array {
    $d = doc_owned($id, $a, 'view');
    $by = array_column(doc_schema($d)['fields'], null, 'key');
    $v = doc_values($id);
    return ['city' => docx_value($by['city'], $v['city'], []), 'cust' => docx_value($by['cust'], $v['cust'], []),
        'rows' => docx_value($by['rows'], $v['rows'], []),
        'ext' => (array)ext_document($a, ['id' => $id])['document']['labels']];
};

$done = doc_create($a, ['template_id' => $tid])['id'];
$open = doc_create($a, ['template_id' => $tid])['id'];
$vals = ['city' => 'חיפה', 'cust' => 'C-1', 'rows' => json_encode([['c' => 'אילת'], ['c' => 'חיפה']], JSON_UNESCAPED_UNICODE)];
doc_save($a, ['id' => $done, 'values' => $vals]);
doc_save($a, ['id' => $open, 'values' => $vals]);
doc_complete($a, ['id' => $done]);
$before = $out($done);
check('לפני השינוי: תרגום ותווית', $before['city'] === 'Haifa' && $before['cust'] === 'דנה כהן' && str_contains($before['rows'], 'Eilat'));

// משנים את התרגום ואת המקור
$retranslate('HAIFA (new)');
db()->prepare('UPDATE data_sources SET rows_enc = ? WHERE id = ?')->execute([$rows('דנה לוי-כהן'), $srcId]);

// אחרי השינוי — בתהליך נפרד, כמו בקשה חדשה (בלי המטמון של הבקשה הזאת)
$fresh = function (int $id) use ($tmp): array {
    $lib = var_export(__DIR__ . '/../lib/documents.php', true);
    $code = "define('DT_DATA_DIR', " . var_export($tmp, true) . "); require $lib;"
        . "\$d = doc_owned($id, ['id' => 1], 'view'); \$by = array_column(doc_schema(\$d)['fields'], null, 'key'); \$v = doc_values($id);"
        . "echo json_encode(['city' => docx_value(\$by['city'], \$v['city'], []), 'cust' => docx_value(\$by['cust'], \$v['cust'], []),"
        . " 'rows' => docx_value(\$by['rows'], \$v['rows'], []), 'ext' => ext_document(['id' => 1], ['id' => $id])['document']['labels']], JSON_UNESCAPED_UNICODE);";
    return json_decode((string)shell_exec('php -r ' . escapeshellarg($code)), true) ?: [];
};
$after = $fresh($done);
check('הושלם: התרגום מרגע ההשלמה', ($after['city'] ?? '') === 'Haifa' && str_contains($after['rows'] ?? '', 'Haifa') && !str_contains($after['rows'] ?? '', 'new'));
check('הושלם: התווית מהמקור מרגע ההשלמה', ($after['cust'] ?? '') === 'דנה כהן' && ($after['ext']['cust'] ?? '') === 'דנה כהן');
$live = $fresh($open);
check('טיוטה: עדיין חי — התרגום והתווית החדשים', ($live['city'] ?? '') === 'HAIFA (new)' && ($live['cust'] ?? '') === 'דנה לוי-כהן'
    && str_contains($live['rows'] ?? '', 'HAIFA (new)'));
$g = doc_get($a, $done)['document'];
$gby = array_column($g['schema']['fields'], null, 'key');
check('לדפדפן (PDF): התרגום שנלכד ואפשרות הבחירה שנלכדה, בלי פרטי המקור',
    $gby['city']['list']['tr'] === ['חיפה' => 'Haifa'] && $gby['cust']['options'] === [['value' => 'C-1', 'label' => 'דנה כהן']] && !isset($gby['cust']['source']));

foreach (array_merge(glob("$tmp/files/*") ?: [], array_filter(glob("$tmp/{,.}*", GLOB_BRACE) ?: [], 'is_file')) as $p) @unlink($p);
@rmdir("$tmp/files");
@rmdir($tmp);
echo $fail ? "\n$fail נכשלו\n" : "\nהכול עבר\n";
exit($fail ? 1 : 0);
