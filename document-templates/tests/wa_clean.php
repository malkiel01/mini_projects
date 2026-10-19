<?php
/**
 * בדיקות: (1) לממלא בלי מייל — החזרה לתיקון והמסמך המוכן חוזרים לשולח כהודעת וואטסאפ + קישור.
 * (2) ההגדרות המוכנות שבתוך PDF נמחקות מקובץ המקור — בשמירה, ופעם אחת לכל התבניות הקיימות.
 * הרצה: php document-templates/tests/wa_clean.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-wac-' . bin2hex(random_bytes(4));
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
function user(string $email): array
{
    db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES (?, '!', ?, 'active', ?)")->execute([$email, $email, now_iso()]);
    return db()->query('SELECT * FROM users WHERE id = ' . (int)db()->lastInsertId())->fetch();
}
// PDF עם הגדרות מוכנות (כמו שהמערכת מייצאת — pdf-lib), ועוד קובץ מצורף של המשתמש
$pdf = base64_decode('JVBERi0xLjcKJYGBgYEKCjYgMCBvYmoKPDwKL0ZpbHRlciAvRmxhdGVEZWNvZGUKL0xlbmd0aCA4MQo+PgpzdHJlYW0KeJwr5HIK4TJQAMGidC59j9ScstSSzOREXXMDSwsTCwNzC0sFIxOFkDQuEOnDZQhWCiFDcrlsTCzMTM2cgdDNTiEkiytEi8s1hCuQCwCApRNwCmVuZHN0cmVhbQplbmRvYmoKCjkgMCBvYmoKPDwKL1R5cGUgL0VtYmVkZGVkRmlsZQovU3VidHlwZSAvYXBwbGljYXRpb24jMkZqc29uCi9QYXJhbXMgPDwKL1NpemUgOTEKPj4KL0ZpbHRlciAvRmxhdGVEZWNvZGUKL0xlbmd0aCA4NAo+PgpzdHJlYW0KeJyrVkrLTM1JKVayiq5Wyk6tVLJSCnZ1DnINiXfzdPVxUdJRKqksSAWKlqRWlAB5BYnpQJ6BjlIFkNQz1FGqhNLlINpYRykDRBuY1sbWAgDQDhkICmVuZHN0cmVhbQplbmRvYmoKCjEwIDAgb2JqCjw8Ci9UeXBlIC9FbWJlZGRlZEZpbGUKL1N1YnR5cGUgL3RleHQjMkZwbGFpbgovUGFyYW1zIDw8Ci9TaXplIDE0Cj4+Ci9GaWx0ZXIgL0ZsYXRlRGVjb2RlCi9MZW5ndGggMjIKPj4Kc3RyZWFtCnicKy1OLVLIyy9JVchOTS0AACjCBVsKZW5kc3RyZWFtCmVuZG9iagoKMTEgMCBvYmoKPDwKL0ZpbHRlciAvRmxhdGVEZWNvZGUKL1R5cGUgL09ialN0bQovTiA3Ci9GaXJzdCA0MAovTGVuZ3RoIDUwMAo+PgpzdHJlYW0KeJzVk91r2zAQwN/9V9xj99BaUvwhjRBIE6uF0bX0g42VPTi2CC6JFGxlZP/97iybJIP2aS8jEaf7kn4n33FgICBJYAIiSyGBnCtIQSoJOSjJQALnXMJ0GsXPv3cG4odybboo/tLUHbxiAoNH+BnFC7e3Hng0m0XH2EXpy41bRyEJOAVH8ddyiwpFFduVqWtT62YzWILvFaa60JqxLGEsxyWWQWYpLtozlAtc/GgXBco56hOUlFvMsAbCGw8rguM0gfa5JDnDUkMtWAL+47lGjnDAiYcgH1pX7yvTjgf3NHRoFkh7MoVLoI4+gRdkcrgsCfFEOUFaocPK8pDTw1FsOuQT9FjRMsQmcihkvFcNxeXB/yGPwtLuXL0svYGL5WfBBD4iVyxlieA/PuGXbE3p3f9bXM/fOPtuhWctSp1J/dkaat/QoI+mc/u2GlpSO/TQ5tZsfhnfVOVlzpREzlzSrFAK9cv3+9WbqfpQUouDv3nyxBAMZLszdVNeuwM2FsNfqnDSEkHjM7fWeWr8fpSsRxrSsvPGC8gEFMVP+5XvVTLyKL4uO9OjHjkRwlaubuwa4m+NnduuGQ1/nUjztzMVFgsXtb/0ZrvbIPrVW+cstsSL/kcDiUQ6PCqo8eHeJ8EnMVf+4M8JPp7ikxs4O7niD3BVKkwKZW5kc3RyZWFtCmVuZG9iagoKMTIgMCBvYmoKPDwKL1NpemUgMTMKL1Jvb3QgMiAwIFIKL0luZm8gMyAwIFIKL0ZpbHRlciAvRmxhdGVEZWNvZGUKL1R5cGUgL1hSZWYKL0xlbmd0aCA1MQovVyBbIDEgMiAyIF0KL0luZGV4IFsgMCAxMyBdCj4+CnN0cmVhbQp4nCXJuxUAEBREwbvPJ5GoRJ8ChagSeySTDHBO0MDIhEkmi/6jmCo2SOsR45EnXKTuBHgKZW5kc3RyZWFtCmVuZG9iagoKc3RhcnR4cmVmCjExNjEKJSVFT0Y=');
$owner = user('o@x.com');
file_put_contents("$tmp/u.pdf", $pdf);
$asset = fn(int $tid) => load_file(db()->query("SELECT a.path FROM template_assets a JOIN template_versions v ON v.id = a.template_version_id WHERE v.template_id = $tid")->fetchColumn());
$tid = tpl_create($owner, ['name' => 'ת'], ['file' => ['tmp_name' => "$tmp/u.pdf", 'error' => 0, 'size' => strlen($pdf)]])['id'];
check('לפני שהעורך קרא — ההגדרות עוד בקובץ', pdf_strip_settings($asset($tid)) !== null);

// (2) שמירה ראשונה (העורך החיל את ההגדרות) — הקובץ מנוקה
$geo = ['page' => 0, 'x' => .1, 'y' => .3, 'w' => .5, 'h' => .05];
tpl_save($owner, ['id' => $tid, 'schema' => ['pages' => [['w' => 595, 'h' => 842]], 'fill_download' => true, 'approval' => true,
    'roles' => [['key' => 'owner', 'label' => 'אני'], ['key' => 'c', 'label' => 'לקוח']],
    'fields' => [['key' => 'n', 'type' => 'text', 'label' => 'שם', 'role' => 'c', 'required' => true] + $geo]]]);
$a = $asset($tid);
check('אחרי שמירה — ההגדרות נמחקו מהקובץ', pdf_strip_settings($a) === null && strlen($a) === strlen($pdf));
check('קובץ מצורף אחר (של המשתמש) — נשאר', $a !== $pdf);

// ניקוי חד-פעמי: תבנית ישנה עם שדות, שהקובץ שלה עוד מלוכלך
$t2 = tpl_create($owner, ['name' => 'ישנה'], ['file' => ['tmp_name' => "$tmp/u.pdf", 'error' => 0, 'size' => strlen($pdf)]])['id'];
db()->prepare('UPDATE template_versions SET schema_enc = ? WHERE template_id = ?')->execute([seal(json_encode(['pages' => [['w' => 595, 'h' => 842]], 'fields' => [['key' => 'x']]])), $t2]);
$t3 = tpl_create($owner, ['name' => 'טרם נפתחה'], ['file' => ['tmp_name' => "$tmp/u.pdf", 'error' => 0, 'size' => strlen($pdf)]])['id'];
@unlink("$tmp/pdf-settings-cleaned");
pdf_settings_sweep();
check('ניקוי חד-פעמי: תבנית קיימת — נוקתה', pdf_strip_settings($asset($t2)) === null);
check('ניקוי חד-פעמי: תבנית שהעורך עוד לא פתח — נשארת (ההגדרות עוד נחוצות)', pdf_strip_settings($asset($t3)) !== null);
check('ניקוי חד-פעמי — מסומן, לא רץ שוב', is_file("$tmp/pdf-settings-cleaned"));
$st = pdf_settings_status();
check('סטטוס לאזור המפתח: מתי רץ וכמה נוקו', $st['sweep_at'] !== null && $st['sweep_cleaned'] === 1);
check('סטטוס: 3 קבצים — 2 נקיים, 0 עם הגדרות, 1 ממתין לעורך', [$st['files'], $st['clean'], $st['dirty'], $st['waiting']] === [3, 2, 0, 1]);

// (1) וואטסאפ ללקוח בלי מייל
$d = doc_create($owner, ['template_id' => $tid])['id'];
$pid = (int)array_values(array_filter(doc_get($owner, $d)['document']['participants'], fn($p) => $p['role_key'] === 'c'))[0]['id'];
part_contact($owner, ['id' => $d, 'participant_id' => $pid, 'name' => 'דני', 'email' => '', 'phone' => '050-1234567']);
$l = part_link($owner, ['id' => $d, 'participant_id' => $pid, 'via' => 'whatsapp']);
$_SERVER['HTTP_X_FILL_TOKEN'] = substr($l['url'], strpos($l['url'], '#t=') + 3);
fill_save(['values' => ['n' => 'דני']]);
fill_complete();
$r = doc_return($owner, ['id' => $d, 'participant' => $pid, 'note' => 'לתקן שם']);
$w = $r['notify'][0] ?? [];
check('החזרה לתיקון בלי מייל — הודעת וואטסאפ לשולח', count($r['notify']) === 1 && str_starts_with($w['whatsapp'], 'https://wa.me/972501234567?text='));
check('…ההודעה: הערה וקישור למילוי', str_contains(urldecode($w['whatsapp']), 'לתקן שם') && str_contains(urldecode($w['whatsapp']), $l['url']));
check('…והקישור להעתקה — אותו קישור שכבר אצלו', $w['url'] === $l['url']);
fill_complete();
$c = doc_complete($owner, ['id' => $d]);
check('המסמך מוכן — הודעת וואטסאפ עם קישור להורדה', count($c['notify']) === 1 && str_contains(urldecode($c['notify'][0]['whatsapp']), 'לצפייה ולהורדה'));
check('שליחה חוזרת של העותק מהמשתתפים', str_contains(part_notify($owner, ['id' => $d, 'participant_id' => $pid])['whatsapp'], 'wa.me'));

echo $fail ? "\n✗ $fail נכשלו\n" : "\n✓ הכול עבר\n";
exit($fail ? 1 : 0);
