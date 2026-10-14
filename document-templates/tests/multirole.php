<?php
/**
 * בדיקות: שדה שאפשר למלא "או פלוני או אלמוני" — כמה ממלאים לאותו שדה.
 * הרצה: php document-templates/tests/multirole.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-multi-' . bin2hex(random_bytes(4));
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
function as_link(string $url): void { $_SERVER['HTTP_X_FILL_TOKEN'] = substr($url, strpos($url, '#t=') + 3); }

db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES ('a@x.com', '!', 'אבי', 'active', ?)")->execute([now_iso()]);
$a = db()->query('SELECT * FROM users WHERE id = 1')->fetch();
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
     . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
file_put_contents("$tmp/u.pdf", $pdf);
$tid = tpl_create($a, ['name' => 'שכירות'], ['file' => ['tmp_name' => "$tmp/u.pdf", 'error' => 0, 'size' => strlen($pdf)]])['id'];
$F = fn($key, $x = []) => ['key' => $key, 'type' => 'text', 'label' => $key, 'page' => 0, 'x' => 0.1, 'y' => 0.1, 'w' => 0.3, 'h' => 0.03] + $x;
tpl_save($a, ['id' => $tid, 'schema' => ['pages' => [['w' => 595, 'h' => 842]],
    'roles' => [['key' => 'owner', 'label' => 'אני'], ['key' => 'tenant', 'label' => 'שוכר'], ['key' => 'guarantor', 'label' => 'ערב']],
    'fields' => [
        $F('phone', ['role' => 'tenant', 'roles' => ['tenant', 'guarantor'], 'required' => true]),        // שוכר או ערב
        $F('note', ['role' => 'owner', 'roles' => ['owner', 'tenant', 'bogus'], 'hidden' => true]),     // אני או שוכר
        $F('tsig', ['role' => 'tenant', 'required' => true]),
        $F('gsig', ['role' => 'guarantor']),
        $F('solo', ['role' => 'tenant', 'roles' => ['tenant']]),
    ]]]);
$by = array_column(tpl_get($a, $tid)['template']['schema']['fields'], null, 'key');
check('נשמר: כמה ממלאים, הראשון הוא role; תפקיד לא קיים — נזרק', $by['phone']['roles'] === ['tenant', 'guarantor'] && $by['note']['roles'] === ['owner', 'tenant']);
check('ממלא אחד — בלי רשימה', $by['solo']['roles'] === []);

$did = doc_create($a, ['template_id' => $tid])['id'];
$parts = array_column(doc_get($a, $did)['document']['participants'], 'id', 'role_key');
$link = fn($role) => part_link($a, ['id' => $did, 'participant_id' => $parts[$role], 'via' => 'link'])['url'];
$tLink = $link('tenant');
$gLink = $link('guarantor');

// המנפיק: שדה משותף איתו — עדיין כותב; שדה של השוכר בלבד — כבר לא
doc_save($a, ['id' => $did, 'values' => ['note' => 'הערה של אבי']]);
check('המנפיק כותב בשדה המשותף לו ולשוכר — גם אחרי שהקישור נשלח', doc_values($did)['note'] === 'הערה של אבי');
throws('המנפיק לא כותב בשדה של שוכר/ערב שכבר נשלח', fn() => doc_save($a, ['id' => $did, 'values' => ['phone' => '050']]), 409);

// הערב רואה ושומר את הטלפון; לא רואה את ההערה המוסתרת (היא של אני/שוכר)
as_link($gLink);
$g = fill_get()['document'];
$keys = array_column($g['schema']['fields'], 'key');
check('הערב רואה את השדה המשותף, לא את המוסתר של אחרים', in_array('phone', $keys, true) && !in_array('note', $keys, true));
check('אצל הערב: הטלפון לא חוסם (השוכר עוד יכול למלא)', !in_array('phone', $g['progress']['missing'], true));
fill_complete();
check('הערב סיים בלי למלא את הטלפון', (bool)db()->query("SELECT completed_at FROM participants WHERE id = {$parts['guarantor']}")->fetchColumn());

// השוכר: עכשיו הוא האחרון שיכול — הטלפון חוסם אותו
as_link($tLink);
$t = fill_get()['document'];
check('השוכר רואה גם את ההערה המוסתרת (היא גם שלו)', in_array('note', array_column($t['schema']['fields'], 'key'), true));
fill_save(['values' => ['tsig' => 'חתום']]);
throws('השוכר — האחרון שנשאר — לא מסיים בלי הטלפון', fn() => fill_complete(), 422);
fill_save(['values' => ['phone' => '052-1234567', 'note' => 'הערת השוכר']]);
check('השוכר שומר בשדות המשותפים', doc_values($did)['phone'] === '052-1234567' && doc_values($did)['note'] === 'הערת השוכר');
fill_complete();
check('הושלם', db()->query("SELECT status FROM documents WHERE id = $did")->fetchColumn() === 'completed');

// כמה ממלאים: כל אחד יכול — גם מי שמילא שני
$did2 = doc_create($a, ['template_id' => $tid])['id'];
$p2 = array_column(doc_get($a, $did2)['document']['participants'], 'id', 'role_key');
as_link(part_link($a, ['id' => $did2, 'participant_id' => $p2['guarantor'], 'via' => 'link'])['url']);
fill_save(['values' => ['phone' => '03-111']]);
check('הערב ממלא את הטלפון (גם בלי שהשוכר נשלח)', doc_values($did2)['phone'] === '03-111');
throws('הערב לא כותב בשדה של השוכר בלבד', fn() => (function () use ($did2) { fill_save(['values' => ['tsig' => 'x']]); if ((doc_values($did2)['tsig'] ?? '') === '') throw new ApiError('לא נכתב', 403); })(), 403);

foreach (array_merge(glob("$tmp/files/*") ?: [], array_filter(glob("$tmp/{,.}*", GLOB_BRACE) ?: [], 'is_file')) as $f) @unlink($f);
@rmdir("$tmp/files");
@rmdir($tmp);
echo $fail ? "\n$fail נכשלו\n" : "\nהכול עבר\n";
exit($fail ? 1 : 0);
