<?php
/**
 * בדיקות: אזור הפיתוח — אכיפה (מחיקה בכוח, ביטול נעילה, מחיקה לצמיתות),
 * יומן הביקורת, לוג המעקב, רשימת פתוח/סגור, והגדרות המשתמש.
 * הרצה: php document-templates/tests/devzone.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-dev-' . bin2hex(random_bytes(4));
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

$now = now_iso();
db()->prepare("INSERT INTO users (email, password_hash, display_name, status, is_developer, created_at) VALUES ('dev@x.com', ?, 'המפתח', 'active', 1, ?)")
    ->execute([password_hash('devpass123', PASSWORD_DEFAULT), $now]);
db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES ('a@x.com', '!', 'אבי', 'active', ?)")->execute([$now]);
$dev = db()->query('SELECT * FROM users WHERE id = 1')->fetch();
$a = db()->query('SELECT * FROM users WHERE id = 2')->fetch();

$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
     . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
file_put_contents("$tmp/u.pdf", $pdf);
$tid = tpl_create($a, ['name' => 'חוזה'], ['file' => ['tmp_name' => "$tmp/u.pdf", 'error' => 0, 'size' => strlen($pdf)]])['id'];
tpl_save($a, ['id' => $tid, 'schema' => ['pages' => [['w' => 595, 'h' => 842]],
    'fields' => [['key' => 'name', 'type' => 'text', 'label' => 'שם', 'page' => 0, 'x' => 0.1, 'y' => 0.1, 'w' => 0.3, 'h' => 0.03, 'required' => true]]]]);
$did = doc_create($a, ['template_id' => $tid, 'title' => 'חוזה סודי'])['id'];
doc_save($a, ['id' => $did, 'values' => ['name' => 'ערך סודי']]);
doc_complete($a, ['id' => $did]);

// ── המשתמש לא יכול; המפתח כן ──
throws('משתמש: מסמך שהושלם — אי אפשר למחוק', fn() => doc_delete($a, ['id' => $did]), 409);
throws('משתמש: תבנית בשימוש — אי אפשר למחוק', fn() => tpl_delete($a, ['id' => $tid]), 409);

// ── מה המפתח רואה: מטא-דאטה בלבד ──
$list = dev_docs(['q' => 'a@x.com'])['documents'];
$json = json_encode($list, JSON_UNESCAPED_UNICODE);
check('רשימת המסמכים למפתח: מספר, בעלים, מצב, כמויות', $list[0]['id'] === $did && $list[0]['status'] === 'completed' && $list[0]['values'] >= 1 && $list[0]['locked']);
check('המפתח לא רואה כותרת או ערך', !str_contains($json, 'חוזה סודי') && !str_contains($json, 'ערך סודי'));

// ── אכיפה: סיבה ואישור חובה ──
throws('בלי סיבה — נדחה', fn() => dev_force($dev, ['type' => 'document', 'op' => 'unlock', 'id' => $did, 'confirm' => $did]), 422);
throws('בלי הקלדת המזהה — נדחה', fn() => dev_force($dev, ['type' => 'document', 'op' => 'unlock', 'id' => $did, 'reason' => 'בדיקה']), 422);
throws('פעולה לא מוכרת — נדחתה', fn() => dev_force($dev, ['type' => 'document', 'op' => 'drop', 'id' => $did, 'reason' => 'בדיקה', 'confirm' => $did]), 422);

dev_force($dev, ['type' => 'document', 'op' => 'unlock', 'id' => $did, 'reason' => 'תיקון שגיאת הקלדה', 'confirm' => (string)$did]);
$d = db()->query("SELECT * FROM documents WHERE id = $did")->fetch();
check('ביטול נעילה: חוזר ל"בתהליך", טביעת האצבע מתאפסת', $d['status'] === 'in_progress' && $d['final_hash'] === null);
$ev = doc_get($a, $did)['document']['events'];
$last = end($ev);
check('ביומן המסמך: "פעולת מפתח", עם הסיבה ושם המפתח', $last['type'] === 'dev_override' && $last['detail']['reason'] === 'תיקון שגיאת הקלדה' && str_contains($last['by'], 'המפתח'));
check('שרשרת היומן נשארת שלמה', doc_verify_chain($did));

dev_force($dev, ['type' => 'document', 'op' => 'delete', 'id' => $did, 'reason' => 'בקשת המשתמש', 'confirm' => (string)$did]);
throws('אחרי מחיקה — המשתמש לא רואה את המסמך', fn() => doc_get($a, $did), 404);
dev_force($dev, ['type' => 'document', 'op' => 'restore', 'id' => $did, 'reason' => 'נמחק בטעות', 'confirm' => (string)$did]);
check('שחזור — חוזר למשתמש', doc_get($a, $did)['document']['id'] === $did);

$st = db()->prepare('SELECT token_hash FROM participants WHERE document_id = ?');
dev_force($dev, ['type' => 'document', 'op' => 'purge', 'id' => $did, 'reason' => 'בקשת מחיקה לפי חוק', 'confirm' => (string)$did]);
check('מחיקה לצמיתות: הערכים נמחקו, המסמך מחוק', (int)db()->query("SELECT COUNT(*) FROM field_values WHERE document_id = $did")->fetchColumn() === 0
    && db()->query("SELECT deleted_at FROM documents WHERE id = $did")->fetchColumn() !== null);
check('מחיקה לצמיתות: היומן נשאר (הוספה בלבד)', (int)db()->query("SELECT COUNT(*) FROM events WHERE document_id = $did")->fetchColumn() > 3);

dev_force($dev, ['type' => 'template', 'op' => 'delete', 'id' => $tid, 'reason' => 'תבנית ישנה', 'confirm' => (string)$tid]);
throws('תבנית בשימוש — נמחקה בכוח המפתח', fn() => tpl_get($a, $tid), 404);
$audit = dev_audit_list()['audit'];
check('יומן הביקורת: 5 פעולות, האחרונה ראשונה, עם הסיבה', count($audit) === 5 && $audit[0]['op'] === 'delete' && $audit[0]['type'] === 'template' && $audit[0]['reason'] === 'תבנית ישנה');

// ── לוג מעקב ──
$_SERVER['REQUEST_METHOD'] = 'POST';
$GLOBALS['dt_body'] = ['id' => 7, 'values' => ['x' => 'ערך שלא נרשם']];
dev_track($a, 'doc_save', microtime(true), 422, new ApiError('"שם": נדרש', 422));
dev_track(null, 'doc_get', microtime(true), 500, new RuntimeException('boom'));
client_error($a, ['message' => 'x is undefined', 'where' => 'editor.js:10:5', 'page' => '#tpl=3']);
$rows = dev_log_list(['errors' => 1])['rows'];
$byA = array_column($rows, null, 'action');
check('לוג: שגיאה עם מזהה מהבקשה, בלי הערך', $byA['doc_save']['ref'] === 'id=7' && $byA['doc_save']['status'] === 422 && !str_contains(json_encode($rows, JSON_UNESCAPED_UNICODE), 'ערך שלא נרשם'));
check('לוג: שגיאת שרת — קובץ ושורה', str_contains((string)$byA['doc_get']['trace'], 'devzone.php:') && $byA['doc_get']['actor'] === 'guest');
check('לוג: שגיאת JavaScript מהדפדפן', $byA['client_error']['message'] === 'x is undefined' && $byA['client_error']['page'] === '#tpl=3');
dev_track($dev, 'dev_items', microtime(true), 200);
$all = array_column(dev_log_list([])['rows'], 'action');
$quiet = array_column(dev_log_list(['nodev' => 1])['rows'], 'action');
check('לוג: "בלי אזור הפיתוח" מסתיר את הבקשות שלו, לא שגיאות', in_array('dev_items', $all, true) && !in_array('dev_items', $quiet, true) && in_array('doc_save', $quiet, true));
check('לוג: סיכום 24 שעות', dev_log_list([])['day']['e5'] === 1);

// ── פתוח / סגור, מצב ──
$items = dev_items();
check('פתוח/סגור: הרשימה נקראת, עם שאלות פתוחות', count($items['items']) > 5 && count(array_filter($items['items'], fn($x) => $x['status'] === 'question')) > 0);
$h = dev_health()['health'];
check('מצב המערכת: הצפנה תקינה, ספירות', $h['crypto'] && $h['counts']['users'] === 2 && is_array($h['chain_broken']));

// ── הגדרות משתמש ──
$sess = auth_login(['email' => 'dev@x.com', 'password' => 'devpass123']);
$other = auth_login(['email' => 'dev@x.com', 'password' => 'devpass123']);
$s = current_session($sess['token']);
throws('החלפת סיסמה: הנוכחית שגויה — נדחה', fn() => me_password($s, ['current' => 'wrong', 'new' => 'newpass123']), 403);
$r = me_password($s, ['current' => 'devpass123', 'new' => 'newpass123']);
check('החלפת סיסמה: עובדת, ומנתקת את המכשיר האחר', $r['other_sessions'] === 1 && current_session($other['token']) === null && current_session($sess['token']) !== null);
check('אפשר להיכנס עם הסיסמה החדשה', !empty(auth_login(['email' => 'dev@x.com', 'password' => 'newpass123'])['token']));
check('שינוי שם', me_update($s, ['name' => 'מלכיאל המפתח'])['user']['name'] === 'מלכיאל המפתח');
throws('שם קצר מדי — נדחה', fn() => me_update($s, ['name' => 'א']), 422);

foreach (array_merge(glob("$tmp/files/*") ?: [], array_filter(glob("$tmp/{,.}*", GLOB_BRACE) ?: [], 'is_file')) as $f) @unlink($f);
@rmdir("$tmp/files");
@rmdir($tmp);
echo $fail ? "\n$fail נכשלו\n" : "\nהכול עבר\n";
exit($fail ? 1 : 0);
