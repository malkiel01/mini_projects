<?php
/**
 * בדיקת עשן לשלב 1: מסד זמני, הצפנה הלוך-חזור, והיומן שאינו ניתן לשינוי.
 * הרצה: php document-templates/tests/smoke.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-smoke-' . bin2hex(random_bytes(4));
define('DT_DATA_DIR', $tmp);
require __DIR__ . '/../lib/db.php';

$fail = 0;
function check(string $name, bool $ok): void
{
    global $fail;
    echo ($ok ? '✓ ' : '✗ ') . $name . "\n";
    if (!$ok) $fail++;
}

$pdo = db();
check('המסד נוצר', is_file(DT_DB_FILE));
check('data/.htaccess נוצר', is_file(DT_DATA_DIR . '/.htaccess'));

$plain = "שם הלקוח: משה כהן — 0501234567";
$sealed = seal($plain);
check('הצפנה: הטקסט אינו גלוי', !str_contains($sealed, 'משה'));
check('הצפנה: הלוך-חזור', unseal($sealed) === $plain);
check('הצפנה: שני הצפנות שונות לאותו טקסט', seal($plain) !== $sealed);

$tampered = substr($sealed, 0, -4) . 'AAAA';
try { unseal($tampered); check('הצפנה: שינוי מתגלה', false); }
catch (RuntimeException) { check('הצפנה: שינוי מתגלה', true); }

$now = now_iso();
$pdo->exec("INSERT INTO users (email, password_hash, display_name, created_at) VALUES ('a@b.c', 'x', 'א', '$now')");
$pdo->exec("INSERT INTO templates (owner_id, name_enc, source, created_at) VALUES (1, 'v1:x', 'pdf_locked', '$now')");
$pdo->exec("INSERT INTO template_versions (template_id, version, schema_enc, created_at) VALUES (1, 1, 'v1:x', '$now')");
$pdo->exec("INSERT INTO documents (owner_id, template_version_id, created_at) VALUES (1, 1, '$now')");
$pdo->exec("INSERT INTO events (document_id, actor, type, hash, at) VALUES (1, 'user:1', 'created', 'h', '$now')");
foreach (['UPDATE events SET type = ?' => ['x'], 'DELETE FROM events' => []] as $sql => $args) {
    try { $pdo->prepare($sql)->execute($args); check("היומן חסום: $sql", false); }
    catch (PDOException) { check("היומן חסום: $sql", true); }
}

db_migrate($pdo);
check('הגירה חוזרת אינה נכשלת', true);

array_map('unlink', array_filter(glob("$tmp/{,.}*", GLOB_BRACE) ?: [], 'is_file'));
@rmdir($tmp);
exit($fail ? 1 : 0);
