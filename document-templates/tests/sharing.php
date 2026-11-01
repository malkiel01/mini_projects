<?php
/**
 * בדיקות שלב 7: שיתוף בין חשבונות — רמות, הרשאות, רשימות ויומן.
 * הרצה: php document-templates/tests/sharing.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-share-' . bin2hex(random_bytes(4));
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
function mk(string $email, string $name, string $status = 'active'): array
{
    db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES (?, '!', ?, ?, ?)")
        ->execute([$email, $name, $status, now_iso()]);
    return db()->query('SELECT * FROM users WHERE id = ' . (int)db()->lastInsertId())->fetch();
}

$a = mk('a@x.com', 'אבי');        // בעלים
$b = mk('b@x.com', 'בתיה');       // תקבל שיתופים
$c = mk('c@x.com', 'כרמל');       // זר
$p = mk('p@x.com', 'ממתין', 'pending');

$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
     . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
file_put_contents("$tmp/u.pdf", $pdf);
$tid = tpl_create($a, ['name' => 'הצעת מחיר'], ['file' => ['tmp_name' => "$tmp/u.pdf", 'error' => 0, 'size' => strlen($pdf)]])['id'];
$schema = ['pages' => [['w' => 595, 'h' => 842]], 'fields' => [
    ['key' => 'price', 'type' => 'number', 'label' => 'מחיר', 'page' => 0, 'x' => .1, 'y' => .1, 'w' => .2, 'h' => .03, 'required' => true]]];
tpl_save($a, ['id' => $tid, 'schema' => $schema]);
$sig = fn($u, $type, $id, $lvl) => share_set($u, ['type' => $type, 'id' => $id, 'email' => $u === $a ? 'b@x.com' : 'c@x.com', 'level' => $lvl]);

// ── לפני שיתוף ──
throws('לפני שיתוף — 404', fn() => tpl_get($b, $tid), 404);
throws('שיתוף עם ממתין לאישור', fn() => share_set($a, ['type' => 'template', 'id' => $tid, 'email' => 'p@x.com', 'level' => 'edit']), 404);
throws('שיתוף עם עצמי', fn() => share_set($a, ['type' => 'template', 'id' => $tid, 'email' => 'a@x.com', 'level' => 'edit']), 409);
throws('רמה לא תקינה', fn() => share_set($a, ['type' => 'template', 'id' => $tid, 'email' => 'b@x.com', 'level' => 'owner']), 422);
throws('15.4א: בתבנית אין צופה', fn() => share_set($a, ['type' => 'template', 'id' => $tid, 'email' => 'b@x.com', 'level' => 'view']), 422);
throws('זר לא משתף', fn() => share_set($c, ['type' => 'template', 'id' => $tid, 'email' => 'b@x.com', 'level' => 'edit']), 404);

// ── תבנית: עורך בלי מתגים — רואה בלבד ──
share_set($a, ['type' => 'template', 'id' => $tid, 'email' => 'b@x.com', 'level' => 'edit', 'perms' => []]);
check('נשלח מייל למי ששותף', str_contains((string)file_get_contents(DT_MAIL_SINK), 'b@x.com'));
$t = tpl_get($b, $tid)['template'];
check('עורך בלי מתגים רואה את התבנית, עם שם הבעלים', $t['access'] === 'view' && $t['owner_name'] === 'אבי' && $t['perms'] === []);
check('ברשימה של בתיה', array_column(tpl_list($b)['templates'], 'id') === [$tid]);
throws('בלי "מבנה" — לא עורך', fn() => tpl_save($b, ['id' => $tid, 'schema' => $schema]), 403);
throws('בלי "הנפקה" — לא מנפיק', fn() => doc_create($b, ['template_id' => $tid]), 403);
throws('זר עדיין 404', fn() => tpl_get($c, $tid), 404);

// ── תבנית: עורך שמנפיק וממלא (= "ממלא" הישן) — מנפיק מסמך משלו ──
share_set($a, ['type' => 'template', 'id' => $tid, 'email' => 'b@x.com', 'level' => 'editor', 'perms' => ['issue', 'fill']]);
$bd = doc_create($b, ['template_id' => $tid])['id'];
check('המסמך שבתיה הנפיקה — שלה', doc_get($b, $bd)['document']['access'] === 'owner');
check('הבעלים של התבנית רואה את המסמך של בתיה — כעורך', doc_get($a, $bd)['document']['access'] === 'edit');
check('אבי רואה אותו ברשימה', array_column(doc_list($a, [])['documents'], 'id') === [$bd]);
throws('בלי "מבנה" — לא עורך תבנית', fn() => tpl_save($b, ['id' => $tid, 'schema' => $schema]), 403);
throws('עורך בלי "שיתוף" — לא משתף הלאה', fn() => share_set($b, ['type' => 'template', 'id' => $tid, 'email' => 'c@x.com', 'level' => 'edit']), 403);

// ── עורך עם "מבנה" ו"שיתוף": משתף הלאה רק עורך, ורק במתגים שיש לו ──
share_set($a, ['type' => 'template', 'id' => $tid, 'email' => 'b@x.com', 'level' => 'edit', 'perms' => ['structure', 'issue', 'share']]);
check('עורך עם "מבנה" שומר', tpl_save($b, ['id' => $tid, 'schema' => $schema, 'name' => 'הצעת מחיר 2'])['ok'] === true);
throws('עורך לא מעביר לארכיון', fn() => tpl_archive($b, ['id' => $tid]), 403);
throws('עורך לא נותן מנהל', fn() => share_set($b, ['type' => 'template', 'id' => $tid, 'email' => 'c@x.com', 'level' => 'co_owner']), 403);
throws('עורך לא נותן מתג שאין לו', fn() => share_set($b, ['type' => 'template', 'id' => $tid, 'email' => 'c@x.com', 'level' => 'edit', 'perms' => ['issue', 'all_docs']]), 403);
check('עורך משתף עורך במתגים שיש לו', share_set($b, ['type' => 'template', 'id' => $tid, 'email' => 'c@x.com', 'level' => 'edit', 'perms' => ['issue']])['ok']);
check('...ורואה את רשימת השיתופים, עם המתגים', array_column(share_list($b, ['type' => 'template', 'id' => $tid])['shares'], 'perms', 'email')['c@x.com'] === ['issue']);
throws('כרמל (עורך בלי שיתוף) לא רואה רשימת שיתופים', fn() => share_list($c, ['type' => 'template', 'id' => $tid]), 403);
check('כרמל — רק הנפקה: מנפיק', doc_create($c, ['template_id' => $tid])['ok']);
throws('בלי "רואה הכול" — כרמל לא רואה את המסמך של בתיה', fn() => doc_get($c, $bd), 404);

// ── מנהל: הכול כמו הבעלים, כולל מנהלים אחרים — אבל לא היוצר ──
share_set($a, ['type' => 'template', 'id' => $tid, 'email' => 'b@x.com', 'level' => 'manager']);
check('מנהל משתף מנהל', share_set($b, ['type' => 'template', 'id' => $tid, 'email' => 'c@x.com', 'level' => 'co_owner'])['ok']);
check('מנהל מסיר מנהל אחר', share_remove($b, ['type' => 'template', 'id' => $tid, 'user_id' => $c['id']])['ok']);
throws('את היוצר אי אפשר לשתף / להסיר', fn() => share_set($b, ['type' => 'template', 'id' => $tid, 'email' => 'a@x.com', 'level' => 'edit']), 409);
throws('מנהל לא מוחק תבנית', fn() => tpl_delete($b, ['id' => $tid]), 403);
check('רשימת השיתופים', array_column(share_list($a, ['type' => 'template', 'id' => $tid])['shares'], 'level') === ['co_owner']);
throws('אחרי הסרה — כרמל 404', fn() => tpl_get($c, $tid), 404);

// ── מסמך: מנהל בתבנית רואה כל מסמך ממנה; עורך — רק עם "רואה הכול" ──
$ad = doc_create($a, ['template_id' => $tid])['id'];
check('מנהל בתבנית רואה מסמך של הבעלים — כעורך', doc_get($b, $ad)['document']['access'] === 'edit');
check('...וגם ברשימה שלו', in_array($ad, array_column(doc_list($b, [])['documents'], 'id'), true));
share_set($a, ['type' => 'template', 'id' => $tid, 'email' => 'b@x.com', 'level' => 'edit', 'perms' => ['all_docs']]);
check('עורך עם "רואה הכול" (בלי מילוי) — צופה', doc_get($b, $ad)['document']['access'] === 'view');
share_set($a, ['type' => 'template', 'id' => $tid, 'email' => 'b@x.com', 'level' => 'edit', 'perms' => ['all_docs', 'fill']]);
check('עורך עם "רואה הכול" ומילוי — מעדכן', doc_get($b, $ad)['document']['access'] === 'fill');
share_set($a, ['type' => 'template', 'id' => $tid, 'email' => 'b@x.com', 'level' => 'edit', 'perms' => ['issue']]);
throws('עורך בלי "רואה הכול" — לא רואה את המסמכים מהתבנית', fn() => doc_get($b, $ad), 404);
share_set($a, ['type' => 'document', 'id' => $ad, 'email' => 'b@x.com', 'level' => 'view']);
$d = doc_get($b, $ad)['document'];
check('צופה במסמך רואה ויומן', $d['access'] === 'view' && count($d['events']) >= 1);
throws('צופה במסמך לא שומר', fn() => doc_save($b, ['id' => $ad, 'values' => ['price' => '5']]), 403);
share_set($a, ['type' => 'document', 'id' => $ad, 'email' => 'b@x.com', 'level' => 'fill']);
doc_save($b, ['id' => $ad, 'values' => ['price' => '7500']]);
throws('ממלא במסמך לא מסיים', fn() => doc_complete($b, ['id' => $ad]), 403);
$d = doc_get($a, $ad)['document'];
check('ביומן: בתיה שמרה, בשמה', ($e = array_values(array_filter($d['events'], fn($e) => $e['type'] === 'values_saved'))[0]) && $e['by'] === 'בתיה');
check('ביומן: נרשם השיתוף', in_array('shared', array_column($d['events'], 'type'), true));
check('המסמך ברשימה של בתיה עם שם הבעלים', in_array('אבי', array_column(doc_list($b, [])['documents'], 'owner_name'), true));
share_set($a, ['type' => 'document', 'id' => $ad, 'email' => 'b@x.com', 'level' => 'edit']);
check('עורך במסמך מסיים', doc_complete($b, ['id' => $ad])['ok'] === true);
throws('עורך לא מוחק מסמך', fn() => doc_delete($b, ['id' => $ad]), 403);

// ── עזיבה ──
share_leave($b, ['type' => 'document', 'id' => $ad]);
throws('אחרי עזיבה — 404', fn() => doc_get($b, $ad), 404);
check('שרשרת היומן שלמה', doc_get($a, $ad)['document']['chain_ok'] === true);
tpl_archive($a, ['id' => $tid]);
throws('תבנית בארכיון — גם מנהל-שותף לא מנפיק', fn() => doc_create($b, ['template_id' => $tid]), 409);

// ── 15.4א: הגירת שיתופים קיימים (רצה פעם אחת) ──
$m = tpl_create($a, ['name' => 'הגירה'], ['file' => ['tmp_name' => "$tmp/u.pdf", 'error' => 0, 'size' => strlen($pdf)]])['id'];
tpl_save($a, ['id' => $m, 'schema' => $schema]);
db()->exec("DELETE FROM shares WHERE object_type = 'template' AND object_id = $m");
foreach ([[$b, 'fill'], [$c, 'view']] as [$u, $lv]) db()->prepare("INSERT INTO shares (object_type, object_id, user_id, level, granted_by, created_at) VALUES ('template', ?, ?, ?, ?, ?)")
    ->execute([$m, $u['id'], $lv, $a['id'], now_iso()]);
db()->exec("DELETE FROM dt_migrations WHERE name = 'share_v2'");
db_migrate(db());
$rows = array_column(db()->query("SELECT user_id, level, perms_json FROM shares WHERE object_type = 'template' AND object_id = $m")->fetchAll(), null, 'user_id');
check('הגירה: ממלא ← עורך שמנפיק וממלא', $rows[$b['id']]['level'] === 'edit' && clean_perms($rows[$b['id']]['perms_json']) === ['fill', 'issue']);
check('הגירה: צופה ← מתבטל', !isset($rows[$c['id']]));
check('הגירה: רצה פעם אחת', (bool)db()->query("SELECT 1 FROM dt_migrations WHERE name = 'share_v2'")->fetchColumn());
check('הגירה: אחרי — בתיה עדיין מנפיקה', doc_create($b, ['template_id' => $m])['ok']);

foreach (array_merge(glob("$tmp/files/*") ?: [], array_filter(glob("$tmp/{,.}*", GLOB_BRACE) ?: [], 'is_file')) as $f) @unlink($f);
@rmdir("$tmp/files");
@rmdir($tmp);
echo $fail ? "\n$fail נכשלו\n" : "\nהכול עבר\n";
exit($fail ? 1 : 0);
