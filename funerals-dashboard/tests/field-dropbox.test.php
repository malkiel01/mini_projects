<?php
declare(strict_types=1);
/**
 * פרוסה 04 — W2/W3 מול Dropbox מדומה (tests/dropbox-mock.php של פרוסה 03): צילום קבר מגיע לתיקיית התיק בשם הצפוי,
 * ב-files עם kind=grave_photo ו-added_via=field; אותו מזהה פעמיים → העלאה אחת. רץ מ-tests/run.sh על data/ נקייה.
 * בלי פרוסה 03 בקוד — מדלג (ו-W7 בודק את "לא מחובר").
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$app = dirname(__DIR__);
if (!is_file("$app/dropbox/actions.php")) { echo "  - פרוסה 04 מול Dropbox מדומה — מדלג: פרוסה 03 עוד לא בקוד\n"; exit(0); }
$tmpDir = sys_get_temp_dir() . '/fd-field-dbx-' . getmypid();
@mkdir($tmpDir, 0700, true);
$state = "$tmpDir/mock.json";
file_put_contents($state, '');
$port = 18975;
$mock = proc_open(['php', '-d', 'opcache.jit=disable', '-S', "127.0.0.1:$port", "$app/tests/dropbox-mock.php"], [1 => ['file', "$tmpDir/mock.log", 'a'], 2 => ['file', "$tmpDir/mock.log", 'a']],
    $pipes, null, ['DBX_MOCK_STATE' => $state, 'PATH' => getenv('PATH')]);
register_shutdown_function(static function () use ($mock, $tmpDir) {
    proc_terminate($mock); proc_close($mock);
    array_map('unlink', glob("$tmpDir/*") ?: []); @rmdir($tmpDir);
});
$base = "http://127.0.0.1:$port";
putenv("DBX_API_BASE=$base");
$ctl = static function (array $op) use ($base): array {
    $c = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => json_encode($op, JSON_UNESCAPED_UNICODE), 'ignore_errors' => true]]);
    return json_decode((string)@file_get_contents("$base/_mock/do", false, $c), true) ?? [];
};
for ($i = 0; $i < 50 && @file_get_contents("$base/_mock/state") === false; $i++) usleep(100000);
$mstate = static fn() => json_decode((string)file_get_contents("$base/_mock/state"), true);

require "$app/lib.php";
require "$app/dropbox/actions.php";
require "$app/lib/field.php";
$fails = 0;
function ok(bool $c, string $m): void { global $fails; if (!$c) { $fails++; fwrite(STDERR, "FAIL: $m\n"); } }

// ארגון 1 מחובר: מפתחות, אסימון רענון שהמדומה מכיר, ותיקייה ראשית
$pdo = db();
$rootId = $ctl(['op' => 'add_folder', 'path' => '/Funerals'])['id'] ?? '';
$ctl(['op' => 'set', 'values' => ['refresh' => ['rt_field_test' => 'dbid:AAA111']]]);
dbxSecretPut('dropbox_app_key', DBX_GLOBAL_ORG, 'mockkey123');
dbxSecretPut('dropbox_app_secret', DBX_GLOBAL_ORG, 'mocksecret456');
dbxSecretPut('dropbox_refresh', 1, 'rt_field_test');
dbxOrgSet($pdo, 1, ['status' => 'connected', 'account_id' => 'dbid:AAA111', 'root_id' => $rootId, 'root_path' => '/Funerals', 'root_lower' => '/funerals']);

$case = normalize(['month' => '2026-11', 'name' => 'כהן אברהם', 'date' => '2026-11-24'], 1);
store($pdo, $case, null, 1, ['id' => null, 'actor' => 'test'], 'ui', 'save');
$actor = ['id' => 3, 'actor' => 'field@example.com'];
$now = new DateTimeImmutable('2026-11-24T08:30:00Z');
$img = "$tmpDir/p.jpg";
file_put_contents($img, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9");
$uid = 'up_' . str_repeat('a', 32);

// W2
[$st, $b] = fieldUpload($pdo, 1, $case['id'], $uid, 'grave_photo', $img, 'IMG_1.jpg', $actor, 'fieldDropboxUploader', $now);
ok($st === 200, "W2: העלאה ($st " . json_encode($b, JSON_UNESCAPED_UNICODE) . ')');
$paths = array_column($mstate()['entries'], 'path_display');
$want = '/Funerals/2026-11/2026-11-24 כהן אברהם/קבר כהן אברהם 2026-11-24 1030.jpg';
ok(in_array($want, $paths, true), 'W2: בתיקיית התיק בשם הצפוי: ' . json_encode($paths, JSON_UNESCAPED_UNICODE));
$row = $pdo->query("SELECT * FROM files WHERE case_id = " . $pdo->quote($case['id']))->fetch();
ok($row && $row['kind'] === 'grave_photo' && $row['added_via'] === 'field' && $row['added_by'] === 'field@example.com' && $row['upload_id'] === $uid, 'W2: files — kind, added_via, added_by, upload_id');
ok(($b['file']['link'] ?? '') === 'api.php?action=file_view&id=' . (int)$row['id'], 'W2: קישור לקובץ');
ok((int)$pdo->query("SELECT COUNT(*) FROM events WHERE case_id = " . $pdo->quote($case['id']) . " AND action IN ('field_upload','file_uploaded')")->fetchColumn() === 2, 'W2: events (field_upload + file_uploaded)');

// W3: אותו מזהה — גם כשה-field_uploads לא הספיק להירשם (נקטע אחרי ההעלאה) — העלאה אחת ל-Dropbox
$ctl(['op' => 'clear_calls']);
[$st2, $b2] = fieldUpload($pdo, 1, $case['id'], $uid, 'grave_photo', $img, 'IMG_1.jpg', $actor, 'fieldDropboxUploader', $now);
ok($st2 === 200 && $b2['duplicate'] === true && $mstate()['calls'] === [], 'W3: כפול — בלי קריאה ל-Dropbox');
$pdo->exec("UPDATE field_uploads SET status = 'failed' WHERE upload_id = '$uid'");
[$st3] = fieldUpload($pdo, 1, $case['id'], $uid, 'grave_photo', $img, 'IMG_1.jpg', $actor, 'fieldDropboxUploader', $now);
ok($st3 === 200 && !in_array('files/upload', $mstate()['calls'], true), 'W3: נקטע אחרי ההעלאה → ניסיון חוזר לא מעלה שוב');
ok((int)$pdo->query("SELECT COUNT(*) FROM files WHERE case_id = " . $pdo->quote($case['id']))->fetchColumn() === 1, 'W3: קובץ אחד');

// W4 מול המדומה: רישיון קבורה → kind=license, הבדיקה לאימות
[$st] = fieldUpload($pdo, 1, $case['id'], 'up_' . str_repeat('b', 32), 'license', $img, 'רישיון.jpg', $actor, 'fieldDropboxUploader', $now);
$c = existing($pdo, $case['id'], 1)['checks']['license_document'] ?? [];
ok($st === 200 && ($c['status'] ?? '') === 'document_present' && ($c['via'] ?? '') === 'field', 'W4: רישיון → לאימות, via field');
ok($pdo->query("SELECT kind FROM files WHERE name = 'רישיון.jpg'")->fetchColumn() === 'license', 'W4: kind=license ב-files');

// U6 (פרוסה 12b): צילום בלי תיק → לתיקייה הראשית, בלי תיק; הזיהוי (10) שואל לאיזה תיק; אותו מזהה פעמיים → העלאה אחת
require_once "$app/link/actions.php";
$luid = 'up_' . str_repeat('d', 32);
[$st, $b] = fieldInboxUpload($pdo, 1, $luid, $img, 'IMG_9.jpg', $actor, 'fieldDropboxInbox', $now);
ok($st === 200 && ($b['inbox'] ?? false) === true, "U6: העלאה בלי תיק ($st " . json_encode($b, JSON_UNESCAPED_UNICODE) . ')');
$want = '/Funerals/צילום מהשטח 2026-11-24 1030.jpg';
ok(in_array($want, array_column($mstate()['entries'], 'path_display'), true), 'U6: בתיקייה הראשית בשם הצפוי');
$f = $pdo->query("SELECT * FROM files WHERE upload_id = " . $pdo->quote($luid))->fetch();
ok($f && $f['case_id'] === null && $f['added_via'] === 'field', 'U6: ב-files בלי תיק, added_via=field');
$q = $pdo->query("SELECT text FROM ai_questions WHERE org_id = 1 AND kind = 'file' AND subject = " . $pdo->quote((string)($f['id'] ?? '')))->fetchColumn();
ok(is_string($q) && str_contains($q, 'צילום מהשטח'), 'U6: הזיהוי שואל לאיזה תיק הקובץ שייך');
$n = count($mstate()['entries']);
[$st2, $b2] = fieldInboxUpload($pdo, 1, $luid, $img, 'IMG_9.jpg', $actor, 'fieldDropboxInbox', $now);
ok($st2 === 200 && ($b2['duplicate'] ?? false) === true && count($mstate()['entries']) === $n, 'U6: אותו מזהה → אותו קובץ, בלי העלאה נוספת');
[$st3] = fieldUpload($pdo, 1, $case['id'], $luid, 'other', $img, 'x.jpg', $actor, 'fieldDropboxUploader', $now);
ok($st3 === 409, "U6: מזהה של צילום בלי תיק לא נכנס לתיק ($st3)");

// L3 (12b): תבנית התיקייה — בדיקת התבנית, ותיקייה חדשה לפי תבנית הארגון
ok(dbxTemplateError('{date} {name}') === null && dbxTemplateError('') === null, 'L3: תבנית תקינה / ריקה');
ok(dbxTemplateError('{month}') !== null && dbxTemplateError('{name}/{x}') !== null && dbxTemplateError('../{name}') !== null && dbxTemplateError('a/b/c/{name}') !== null && dbxTemplateError('a\\b {name}') !== null, 'L3: תבנית פגומה נדחית');
orgSettingsPut($pdo, 1, ['dropbox_folder_template' => '{year}/{dm} {name}']);
ok(dbxTemplateInfo($pdo, 1)['folder_template_example'] === '2026/14.10 ישראל ישראלי', 'L3: דוגמה לפי התבנית');
$case2 = normalize(['month' => '2026-11', 'name' => 'לוי שרה', 'date' => '2026-11-20'], 1);
store($pdo, $case2, null, 1, ['id' => null, 'actor' => 'test'], 'ui', 'save');
[$st] = fieldUpload($pdo, 1, $case2['id'], 'up_' . str_repeat('e', 32), 'other', $img, 'מסמך.jpg', $actor, 'fieldDropboxUploader', $now);
ok($st === 200 && in_array('/Funerals/2026/20.11 לוי שרה/מסמך.jpg', array_column($mstate()['entries'], 'path_display'), true), 'L3: תיקייה חדשה לפי תבנית הארגון');

// W7: מנותק → 409 בהודעה ברורה
dbxOrgSet($pdo, 1, ['status' => 'disconnected']);
[$st, $b] = fieldUpload($pdo, 1, $case['id'], 'up_' . str_repeat('c', 32), 'grave_photo', $img, 'x.jpg', $actor, 'fieldDropboxUploader', $now);
ok($st === 409 && $b['error'] === FIELD_DBX_DOWN, "W7: מנותק → 409 ($st)");

// אזהרות PHP בשרת המדומה (בלי אזהרת ה-JIT של ה-runner, "in Unknown on line 0")
$warn = array_filter(file("$tmpDir/mock.log") ?: [], static fn($l) => preg_match('/PHP (Fatal|Warning|Parse)/', $l) && !str_contains($l, 'in Unknown on line 0'));
ok($warn === [], 'אזהרות PHP במדומה: ' . implode('', $warn));
if ($fails) { fwrite(STDERR, "field-dropbox.test.php: $fails כשלים\n"); exit(1); }
echo "  ✓ פרוסה 04 — W2–W4, W7 מול Dropbox מדומה; פרוסה 12b — U6 (צילום בלי תיק), L3 (תבנית תיקייה)\n";
