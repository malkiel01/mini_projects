<?php
/**
 * נעילה מול שמירה מקבילה (נספח הביקורת v0.3, DT-F07): השלמה ושמירה לא נכנסות זו לתוך זו.
 * שני תהליכים אמיתיים (pcntl_fork) על אותו מסד. מסד וקבצים זמניים.
 * הרצה: php document-templates/tests/finalize_race.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-race-' . bin2hex(random_bytes(4));
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
function status_of(int $id): array
{
    return db()->query("SELECT status, final_hash FROM documents WHERE id = $id")->fetch();
}

db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES ('a@x.com', '!', 'א', 'active', ?)")->execute([now_iso()]);
$a = db()->query('SELECT * FROM users WHERE id = 1')->fetch();
$F = fn($key, $type, $extra = []) => ['key' => $key, 'type' => $type, 'label' => $key, 'placeholder' => $key,
    'page' => 0, 'x' => 0, 'y' => 0, 'w' => 0.3, 'h' => 0.03] + $extra;
$tid = tpl_create_authored($a, ['name' => 'נעילה'])['id'];
tpl_save($a, ['id' => $tid, 'schema' => ['pages' => [['w' => 595, 'h' => 842]], 'fields' => [
    $F('name', 'text', ['required' => true]), $F('note', 'text'),
], 'body' => [['id' => 'b1', 'type' => 'p', 'text' => '{{name}} {{note}}']]]]);

// ── השלמה כפולה: השנייה נדחית, וטביעת האצבע לא משתנה ──
$d = doc_create($a, ['template_id' => $tid]);
doc_save($a, ['id' => $d['id'], 'values' => ['name' => 'משה']]);
$row = doc_owned((int)$d['id'], $a, 'edit');
doc_finalize($row, 'user:1');
$first = status_of((int)$d['id']);
throws('השלמה שנייה של אותו מסמך — נדחית', fn() => doc_finalize($row, 'user:1'), 409);
check('טביעת האצבע לא נכתבה מחדש', status_of((int)$d['id'])['final_hash'] === $first['final_hash']);

// ── כותב מאוחר: עבר את בדיקת המצב לפני ההשלמה, ומגיע לטרנזקציה אחריה ──
throws('כתיבה בתוך טרנזקציה אחרי השלמה — נדחית', function () use ($d) {
    $pdo = db();
    $pdo->beginTransaction();
    try { doc_lock($pdo, (int)$d['id'], DOC_EDITABLE); } finally { $pdo->rollBack(); }
}, 409);

// ── השלמה בלי שדה חובה (שנמחק בשמירה מקבילה) — נדחית בתוך הטרנזקציה, בלי שינוי מצב ──
$e = doc_create($a, ['template_id' => $tid]);
$rowE = doc_owned((int)$e['id'], $a, 'edit');
throws('השלמה עם שדה חובה ריק — נדחית', fn() => doc_finalize($rowE, 'user:1'), 422);
check('המסמך נשאר פתוח', in_array(status_of((int)$e['id'])['status'], DOC_EDITABLE, true));

// ── שני תהליכים: שמירה שתפסה את הנעילה ראשונה נכללת בטביעת האצבע ──
if (!function_exists('pcntl_fork')) {
    echo "- אין pcntl — מדלגים על בדיקת שני התהליכים\n";
} else {
    $g = doc_create($a, ['template_id' => $tid]);
    doc_save($a, ['id' => $g['id'], 'values' => ['name' => 'דנה']]);
    $flag = "$tmp/locked";
    $pid = pcntl_fork();
    if ($pid === 0) {
        // הילד: חיבור משלו למסד. תופס נעילה, מחכה, כותב "note" ומתחייב
        $pdo = new PDO('sqlite:' . DT_DB_FILE, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA busy_timeout = 15000');
        $pdo->beginTransaction();
        doc_lock($pdo, (int)$g['id'], DOC_EDITABLE);
        touch($flag);
        usleep(600000);
        $pdo->prepare("INSERT INTO field_values (document_id, field_key, row_index, value_enc, updated_by, updated_at)
            VALUES (?, 'note', 0, ?, 'user:1', ?)")->execute([$g['id'], seal('נכתב בזמן ההשלמה'), now_iso()]);
        $pdo->commit();
        exit(0);
    }
    while (!is_file($flag)) usleep(10000);
    $rowG = doc_owned((int)$g['id'], $a, 'edit');
    doc_finalize($rowG, 'user:1');   // מחכה לילד (busy_timeout), ורק אז נועל
    pcntl_waitpid($pid, $status);
    $values = doc_values((int)$g['id']);
    ksort($values);
    $expect = hash('sha256', json_encode(['template_version_id' => (int)$rowG['template_version_id'], 'values' => $values], JSON_UNESCAPED_UNICODE));
    check('הערך שנכתב במקביל נשמר', ($values['note'] ?? '') === 'נכתב בזמן ההשלמה');
    check('טביעת האצבע כוללת אותו — תואמת לערכים הסופיים', status_of((int)$g['id'])['final_hash'] === $expect);
}

foreach (array_merge(glob("$tmp/files/*") ?: [], array_filter(glob("$tmp/{,.}*", GLOB_BRACE) ?: [], 'is_file')) as $p) @unlink($p);
@rmdir("$tmp/files");
@rmdir($tmp);
echo $fail ? "\n$fail נכשלו\n" : "\nהכול עבר\n";
exit($fail ? 1 : 0);
