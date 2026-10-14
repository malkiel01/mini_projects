<?php
/**
 * בדיקות: מטלות — קוד לכל מטלה, שיח, ואסימון שדרכו Claude קורא ועונה.
 * הרצה: php document-templates/tests/devtasks.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-tasks-' . bin2hex(random_bytes(4));
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
db()->prepare("INSERT INTO users (email, password_hash, display_name, status, is_developer, created_at) VALUES ('dev@x.com', '!', 'המפתח', 'active', 1, ?)")->execute([$now]);
db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES ('a@x.com', '!', 'אבי', 'active', ?)")->execute([$now]);
$dev = db()->query('SELECT * FROM users WHERE id = 1')->fetch();

// הקובץ: לכל פריט קוד ייחודי
$file = dev_items_file()['items'];
$codes = array_column($file, 'code');
check('לכל פריט בקובץ יש קוד', count($codes) === count($file) && !in_array('', $codes, true));
check('הקודים ייחודיים ובמבנה DT-000', count(array_unique($codes)) === count($codes) && !array_filter($codes, fn($c) => !preg_match('/^DT-\d{3}$/', $c)));
$max = max(array_map('dev_code_num', $codes));

// מטלה חדשה מהמסך
throws('מטלה בלי כותרת', fn() => dev_task_create($dev, ['title' => ' ']), 422);
$code = dev_task_create($dev, ['title' => 'לבדוק את הייצוא', 'area' => 'מסמכים'])['code'];
check("קוד חדש אחרי הגבוה שבקובץ ($code)", dev_code_num($code) === $max + 1);
$code2 = dev_task_create($dev, ['title' => 'עוד אחת'])['code'];
check('והבא אחריו', dev_code_num($code2) === $max + 2);
$list = dev_items()['items'];
check('המטלות מהמסך ברשימה, הראשונות', $list[0]['code'] === $code2 && $list[1]['source'] === 'user');

// שיח
throws('הערה למטלה שלא קיימת', fn() => dev_comment($dev, ['code' => 'DT-999', 'body' => 'x']), 404);
throws('הערה ריקה', fn() => dev_comment($dev, ['code' => $code, 'body' => '  ']), 422);
$c1 = dev_comment($dev, ['code' => $codes[0], 'body' => "תסגור את זה\nבבקשה"])['id'];
$x = dev_task_get($codes[0]);
check('ההערה בשיח, וממתינה ל-Claude', count($x['comments']) === 1 && $x['waiting'] === 'claude' && $x['comments'][0]['name'] === 'המפתח');

// מצב: במטלה מהמסך משתנה, במטלה מהקוד — לא
dev_task_set($dev, ['code' => $code, 'status' => 'closed']);
check('מטלה מהמסך נסגרה', dev_task_get($code)['status'] === 'closed');
throws('מצב של מטלה מהקוד — רק בקוד', fn() => dev_task_set($dev, ['code' => $codes[0], 'status' => 'closed']), 409);
throws('מצב לא מוכר', fn() => dev_task_set($dev, ['code' => $code, 'status' => 'gone']), 422);

// אסימון
$t = dev_token_create($dev, ['label' => 'Claude']);
check('אסימון במבנה dtd_', (bool)preg_match('/^dtd_[0-9a-f]{48}$/', $t['token']));
check('נשמר רק כגיבוב', !db()->query("SELECT COUNT(*) FROM dev_tokens WHERE token_hash = " . db()->quote($t['token']))->fetchColumn());
$_SERVER['HTTP_X_DEV_TOKEN'] = '';
throws('בלי אסימון', fn() => devapi_auth(), 401);
$_SERVER['HTTP_X_DEV_TOKEN'] = 'dtd_' . str_repeat('0', 48);
throws('אסימון שלא קיים', fn() => devapi_auth(), 401);
$_SERVER['HTTP_X_DEV_TOKEN'] = $t['token'];
$k = devapi_auth();

$feed = devapi_feed($k, []);
check('ברירת מחדל: רק מטלות עם הערה חדשה', count($feed['items']) === 1 && $feed['items'][0]['code'] === $codes[0] && $feed['items'][0]['comments'][0]['new']);
check('הקוד הבא מוצע', $feed['next_code'] === sprintf('DT-%03d', $max + 3));
check('all=1 — הכול', count(devapi_feed($k, ['all' => 1])['items']) === count($codes) + 2);
check('code= — אחת', count(devapi_feed($k, ['code' => $code])['items']) === 1);
devapi_feed($k, ['mark' => 1]);
$k = devapi_auth();
check('אחרי סימון — אין חדשות', count(devapi_feed($k, [])['items']) === 0);

// Claude עונה
devapi_comment($k, ['code' => $codes[0], 'body' => 'נסגר ב-PR הבא']);
$x = dev_task_get($codes[0]);
check('התשובה בשיח, והתור עבר למפתח', $x['comments'][1]['author'] === 'claude' && $x['comments'][1]['name'] === 'Claude' && $x['waiting'] === 'user');
devapi_comment($k, ['code' => $code2, 'body' => 'בוצע', 'status' => 'closed']);
check('Claude סוגר מטלה מהמסך', dev_task_get($code2)['status'] === 'closed');
check('תשובה של Claude לא נחשבת "חדשה"', count(devapi_feed($k, [])['items']) === 0);

// מחיקת הערה: רק שלי, ורק לפני תשובה
throws('הערה שכבר נענתה', fn() => dev_comment_delete($dev, ['id' => $c1]), 409);
$c2 = dev_comment($dev, ['code' => $code, 'body' => 'טעות'])['id'];
throws('הערה של מישהו אחר', fn() => dev_comment_delete(['id' => 2], ['id' => $c2]), 404);
dev_comment_delete($dev, ['id' => $c2]);
check('נמחקה', !dev_task_get($code)['comments']);

// ביטול, ומפתח שאיבד הרשאה
dev_token_revoke($dev, ['id' => $t['id']]);
throws('אסימון שבוטל', fn() => devapi_auth(), 401);
$t2 = dev_token_create($dev, []);
db()->exec('UPDATE users SET is_developer = 0 WHERE id = 1');
$_SERVER['HTTP_X_DEV_TOKEN'] = $t2['token'];
throws('מי שכבר לא מפתח', fn() => devapi_auth(), 401);

foreach (array_merge(glob("$tmp/files/*") ?: [], array_filter(glob("$tmp/{,.}*", GLOB_BRACE) ?: [], 'is_file')) as $f) @unlink($f);
@rmdir("$tmp/files");
@rmdir($tmp);
echo $fail ? "\n$fail נכשלו\n" : "\nהכול עבר\n";
exit($fail ? 1 : 0);
