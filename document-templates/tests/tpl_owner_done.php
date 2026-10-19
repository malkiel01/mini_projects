<?php
/**
 * בדיקות: בעל התבנית רואה כל מסמך שהונפק ממנה, ו"סיימתי" של ממלא בחשבון (בלי הרשאת סיום)
 * מעביר את המסמך אל בעל התבנית — שמסיים ונועל, או מחזיר לתיקון.
 * הרצה: php document-templates/tests/tpl_owner_done.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-tod-' . bin2hex(random_bytes(4));
define('DT_DATA_DIR', $tmp);
define('DT_MAIL_SINK', "$tmp/mail.log");
require __DIR__ . '/../lib/participants.php';
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
function user(string $email): array
{
    db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES (?, '!', ?, 'active', ?)")->execute([$email, $email, now_iso()]);
    return db()->query('SELECT * FROM users WHERE id = ' . (int)db()->lastInsertId())->fetch();
}
$a = user('a@x.com'); $b = user('b@x.com'); $c = user('c@x.com'); $z = user('z@x.com');
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
     . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
file_put_contents("$tmp/u.pdf", $pdf);
$tid = tpl_create($a, ['name' => 'ת'], ['file' => ['tmp_name' => "$tmp/u.pdf", 'error' => 0, 'size' => strlen($pdf)]])['id'];
tpl_save($a, ['id' => $tid, 'schema' => ['pages' => [['w' => 595, 'h' => 842]],
    'fields' => [['key' => 'n', 'type' => 'text', 'label' => 'שם', 'required' => true, 'page' => 0, 'x' => .1, 'y' => .3, 'w' => .5, 'h' => .05]]]]);
share_set($a, ['type' => 'template', 'id' => $tid, 'email' => 'b@x.com', 'level' => 'fill']);

// ב מנפיק מהתבנית של א
$d = doc_create($b, ['template_id' => $tid])['id'];
check('בעל התבנית רואה ברשימה מסמך שאחר הנפיק', in_array($d, array_column(doc_list($a, [])['documents'], 'id'), true));
check('בעל התבנית: רמת "עורך" במסמך', doc_get($a, $d)['document']['access'] === 'edit');
check('מי שאינו קשור — לא רואה', doc_list($z, [])['documents'] === []);
throws('מי שאינו קשור — 404', fn() => doc_get($z, $d), 404);
check('המנפיק עצמו מסיים בעצמו (אין "סיימתי" שעובר)', doc_get($b, $d)['document']['finisher'] === null);

// ב משתף את ג כממלא — ג לוחץ "סיימתי"
share_set($b, ['type' => 'document', 'id' => $d, 'email' => 'c@x.com', 'level' => 'fill']);
$cd = doc_get($c, $d)['document'];
check('ממלא בחשבון: "סיימתי" עובר אל בעל התבנית', ($cd['finisher']['name'] ?? '') === 'a@x.com');
throws('ממלא בחשבון לא נועל בעצמו', fn() => doc_complete($c, ['id' => $d]), 403);
throws('"סיימתי" עם שדות חובה חסרים', fn() => doc_submit($c, ['id' => $d]), 422);
doc_save($c, ['id' => $d, 'values' => ['n' => 'ישראל']]);
doc_submit($c, ['id' => $d]);
$ad = doc_get($a, $d)['document'];
check('אצל בעל התבנית: ממתין לסיום, ממי', $ad['awaiting_approval'] === true && $ad['submitted_by'] === 'c@x.com');
check('מייל לבעל התבנית', str_contains((string)file_get_contents(DT_MAIL_SINK), 'סיימו למלא'));

// החזרה לתיקון — חוזר לממלא
$r = doc_return($a, ['id' => $d, 'participant' => 0, 'note' => 'תקן']);
check('החזרה לתיקון — אל מי שלחץ "סיימתי"', $r['returned'] === 'c@x.com');
check('אחרי החזרה — לא ממתין', doc_get($a, $d)['document']['awaiting_approval'] === false);
doc_submit($c, ['id' => $d]);
doc_complete($a, ['id' => $d]);
check('בעל התבנית סיים ונעל', doc_get($c, $d)['document']['status'] === 'completed');

echo $fail ? "\n✗ $fail נכשלו\n" : "\n✓ הכול עבר\n";
exit($fail ? 1 : 0);
