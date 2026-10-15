<?php
/**
 * בדיקות: מסלול אישור (ממלא מסיים → ממתין לאישור → אושר / הוחזר לתיקון → הממלא מוריד),
 * וברירת מחדל "היום" לתאריך.
 * הרצה: php document-templates/tests/approval.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-appr-' . bin2hex(random_bytes(4));
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
function as_token(string $url): void { $_SERVER['HTTP_X_FILL_TOKEN'] = substr($url, strpos($url, '#t=') + 3); }
function mails(): array
{
    return is_file(DT_MAIL_SINK) ? array_map(fn($l) => json_decode($l, true), file(DT_MAIL_SINK, FILE_IGNORE_NEW_LINES)) : [];
}
function last_mail_to(string $to): ?array
{
    foreach (array_reverse(mails()) as $m) if ($m['to'] === $to) return $m;
    return null;
}

db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES ('owner@x.com', '!', 'מלכיאל', 'active', ?)")->execute([now_iso()]);
$owner = db()->query('SELECT * FROM users WHERE id = 1')->fetch();

$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
     . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
file_put_contents("$tmp/up.pdf", $pdf);
$tid = tpl_create($owner, ['name' => 'דיווח שעות'], ['file' => ['tmp_name' => "$tmp/up.pdf", 'error' => 0, 'size' => strlen($pdf)]])['id'];
$F = fn($key, $role, $type = 'text', $x = []) => array_merge(['key' => $key, 'type' => $type, 'label' => $key, 'page' => 0,
    'x' => .1, 'y' => .1, 'w' => .3, 'h' => .03, 'required' => true, 'role' => $role], $x);
$schema = ['pages' => [['w' => 595, 'h' => 842]], 'approval' => true, 'fill_download' => true,
    'roles' => [['key' => 'emp', 'label' => 'עובד', 'auth' => 'none']],
    'fields' => [$F('when', 'emp', 'date', ['default' => '@today']), $F('due', 'emp', 'date', ['default' => '@today+30', 'required' => false]),
                 $F('hours', 'emp', 'number'), $F('salary', 'emp', 'number', ['hidden' => true, 'role' => 'owner', 'required' => false]),
                 $F('bad', 'emp', 'date', ['default' => 'מחר', 'required' => false])]];
tpl_save($owner, ['id' => $tid, 'schema' => $schema]);
$s = tpl_get($owner, $tid)['template']['schema'];
check('הגדרות התבנית נשמרות: אישור והורדה', $s['approval'] === true && $s['fill_download'] === true);
check('ברירת מחדל לתאריך: "@today", "@today+30"; ערך פסול — נמחק',
    $s['fields'][0]['default'] === '@today' && $s['fields'][1]['default'] === '@today+30' && $s['fields'][4]['default'] === '');

$docId = doc_create($owner, ['template_id' => $tid])['id'];
$tz = new DateTimeZone('Asia/Jerusalem');
$v = doc_values($docId);
check('במסמך חדש: "היום" → התאריך של היום', $v['when'] === (new DateTimeImmutable('now', $tz))->format('Y-m-d'));
check('"היום + 30"', $v['due'] === (new DateTimeImmutable('now', $tz))->modify('+30 days')->format('Y-m-d'));
doc_save($owner, ['id' => $docId, 'values' => ['salary' => '9000']]);

$pid = doc_get($owner, $docId)['document']['participants'][0]['id'];
part_contact($owner, ['id' => $docId, 'participant_id' => $pid, 'name' => 'יוסי', 'email' => 'yossi@x.com']);
as_token(part_link($owner, ['id' => $docId, 'participant_id' => $pid, 'via' => 'link'])['url']);
fill_save(['values' => ['hours' => '42']]);
$c = fill_complete();
$d = doc_get($owner, $docId)['document'];
check('העובד סיים → לא ננעל, ממתין לאישור', !$c['document_completed'] && $d['status'] !== 'completed' && $d['awaiting_approval'] === true);
check('מייל למנהל הטופס: "ממתין לאישורך"', str_starts_with(last_mail_to('owner@x.com')['subject'] ?? '', 'ממתין לאישורך'));
$me = fill_get()['document']['me'];
check('אצל העובד: סיים, ממתין, עוד לא להורדה', $me['completed'] && $me['awaiting'] && !$me['can_download']);
throws('לפני אישור — הורדה חסומה', fn() => fill_downloaded([]), 403);

// החזרה לתיקון
$r = doc_return($owner, ['id' => $docId, 'note' => 'חסרות שעות של יום ראשון']);
check('הוחזר לתיקון, ונשלח קישור חדש במייל', $r['returned'] === 1 && $r['mailed'] === 1);
$m = last_mail_to('yossi@x.com');
check('במייל: ההערה', str_contains($m['body'] ?? '', 'חסרות שעות של יום ראשון'));
throws('הקישור הישן כבר לא עובד', fn() => fill_get(), 404);
preg_match('/#t=([0-9a-f]{48})/', $m['body'], $mm); $_SERVER['HTTP_X_FILL_TOKEN'] = $mm[1];
$me = fill_get()['document']['me'];
check('אצל העובד: פתוח שוב, עם ההערה', !$me['completed'] && !$me['locked'] && $me['returned'] === 'חסרות שעות של יום ראשון');
check('אצל מנהל הטופס: כבר לא ממתין', doc_get($owner, $docId)['document']['awaiting_approval'] === false);
fill_save(['values' => ['hours' => '45']]);
fill_complete();
check('סיים שוב → ממתין לאישור', doc_get($owner, $docId)['document']['awaiting_approval'] === true);

// אישור
doc_complete($owner, ['id' => $docId]);
$d = doc_get($owner, $docId)['document'];
check('אושר: ננעל, ביומן "approved"', $d['status'] === 'completed' && in_array('approved', array_column($d['events'], 'type'), true));
$m = last_mail_to('yossi@x.com');
check('לעובד: "המסמך אושר" עם קישור', str_starts_with($m['subject'] ?? '', 'המסמך אושר') && preg_match('/#t=([0-9a-f]{48})/', $m['body'], $mm));
$_SERVER['HTTP_X_FILL_TOKEN'] = $mm[1];
$got = fill_get()['document'];
check('אצל העובד: אושר, אפשר להוריד', $got['me']['approved'] && $got['me']['can_download']);
check('השדה המוסתר (שכר) לא מגיע אליו', !array_key_exists('salary', $got['values']));
check('הורדה נרשמת ביומן', fill_downloaded(['format' => 'pdf'])['ok'] && end(doc_get($owner, $docId)['document']['events'])['type'] === 'downloaded');
throws('אחרי האישור — אין החזרה לתיקון', fn() => doc_return($owner, ['id' => $docId]), 409);

// בלי מסלול אישור: הממלא האחרון נועל לבד, ובלי הורדה — הקישור לא נותן להוריד
$schema['approval'] = false; $schema['fill_download'] = false;
tpl_save($owner, ['id' => $tid, 'schema' => $schema]);
$doc2 = doc_create($owner, ['template_id' => $tid])['id'];
$p2 = doc_get($owner, $doc2)['document']['participants'][0]['id'];
part_contact($owner, ['id' => $doc2, 'participant_id' => $p2, 'name' => 'יוסי', 'email' => 'yossi@x.com']);
as_token(part_link($owner, ['id' => $doc2, 'participant_id' => $p2, 'via' => 'link'])['url']);
fill_save(['values' => ['hours' => '8']]);
check('בלי אישור: הממלא האחרון נועל לבד', fill_complete()['document_completed'] === true);
throws('בלי "הממלא יכול להוריד" — חסום', fn() => fill_downloaded([]), 403);

echo "\n" . ($fail ? "$fail נכשלו\n" : "הכול עבר\n");
exit($fail ? 1 : 0);
