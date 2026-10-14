<?php
/**
 * בדיקות: מטלות דרך המודול המשותף DevLoop — הקודים, השיח, האסימון (גם מלפני המודול), אימות וראיות,
 * והסנכרון עם לוג המעקב: שגיאות שרת ודפדפן נפתחות לבד "⚠️ לבירור", והלוג מפנה למטלה.
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
$mails = fn() => array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)@file_get_contents("$tmp/mail.log"))));

$now = now_iso();
db()->prepare("INSERT INTO users (email, password_hash, display_name, status, is_developer, created_at) VALUES ('dev@x.com', '!', 'המפתח', 'active', 1, ?)")->execute([$now]);
$dev = db()->query('SELECT * FROM users WHERE id = 1')->fetch();

// ── הרשימה: הקובץ + המסך ──
$file = devloop_items_file()['items'];
$codes = array_column($file, 'code');
check('לכל פריט בקובץ קוד ייחודי DT-000', count(array_unique($codes)) === count($file) && !array_filter($codes, fn($c) => !preg_match('/^DT-\d{3}$/', $c)));
$max = max(array_map('devloop_code_num', $codes));
$openCode = array_values(array_filter($file, fn($x) => $x['status'] !== 'closed'))[0]['code'];
$list = devloop_task_list()['items'];
check('הרשימה: כל הקובץ, ממוין מהחדש', count($list) === count($file) && $list[0]['code'] === sprintf('DT-%03d', $max));
$code = devloop_task_create($dev, ['title' => 'לבדוק ייצוא', 'kind' => 'bug', 'priority' => 'high'])['code'];
check("מטלה מהמסך — הקוד הבא ($code)", $code === sprintf('DT-%03d', $max + 1) && devloop_task_get($code)['priority'] === 'high');

// ── אסימון מלפני המודול (טבלה בלי rate_*) ממשיך לעבוד ──
$legacy = 'dtd_' . str_repeat('ab', 24);
db()->prepare('INSERT INTO dev_tokens (token_hash, label, created_by, created_at) VALUES (?, ?, 1, ?)')->execute([token_hash($legacy), 'ישן', $now]);
$_SERVER['HTTP_X_DEV_TOKEN'] = $legacy;
$k = devloop_api_auth();
check('אסימון dtd_ קיים — עובד', (int)$k['created_by'] === 1);
$_SERVER['HTTP_X_DEV_TOKEN'] = 'pmd_' . str_repeat('ab', 24);
throws('אסימון של אפליקציה אחרת (pmd_)', fn() => devloop_api_auth(), 401);
$_SERVER['HTTP_X_DEV_TOKEN'] = $legacy;

// ── שיח ──
devloop_comment_add($openCode, 'user', 1, 'מה המצב?');
$feed = devloop_api_feed($k, []);
check('הפיד: רק מה שחדש', count($feed['items']) === 1 && $feed['items'][0]['code'] === $openCode && $feed['items'][0]['comments'][0]['new']);
devloop_api_feed($k, ['mark' => 1]);
$k = devloop_api_auth();
check('אחרי סימון — אין חדש', count(devloop_api_feed($k, [])['items']) === 0);

// ── מטלה מהקוד: Claude מעביר לאימות עם ראיות; המפתח מאמת ──
$r = devloop_api_comment($k, ['code' => $openCode, 'body' => 'נפרס. איך בודקים: …', 'status' => 'verify',
    'evidence' => [['kind' => 'pr', 'ref' => '#300', 'url' => 'https://github.com/x/y/pull/300']]]);
$x = devloop_task_get($openCode);
check('ממתינה לאימות, עם הראיה, והתור עבר למפתח', $x['status'] === 'verify' && $x['waiting'] === 'user'
    && in_array('#300', array_column($x['evidence'], 'ref'), true));
check('מייל למפתח: ממתינה לאימות', (bool)array_filter($mails(), fn($m) => str_contains($m['subject'], "🧪 {$openCode}") && str_contains($m['body'], '#dev?tab=items&code=' . $openCode)));
throws('↩️ בלי לכתוב מה לא עובד', fn() => devloop_verify($dev, ['code' => $openCode, 'ok' => false]), 422);
devloop_verify($dev, ['code' => $openCode, 'ok' => false, 'note' => 'הכפתור לא מגיב']);
check('↩️ — חזרה לעבודה, ממתינה ל-Claude', devloop_task_get($openCode)['status'] === 'in_progress' && devloop_task_get($openCode)['waiting'] === 'claude');
devloop_verify($dev, ['code' => $openCode, 'ok' => true]);
check('✅ — נסגרה', devloop_task_get($openCode)['status'] === 'closed');
$r = devloop_api_comment($k, ['code' => $openCode, 'status' => 'verify', 'by' => 'github']);
check('פריסה לא פותחת מחדש מטלה שאומתה', $r['status_skipped'] === true && devloop_task_get($openCode)['status'] === 'closed');

// ── הלוג → "⚠️ לבירור" ──
check('מזהה בקשה — 12 תווים, קבוע לבקשה', (bool)preg_match('/^[0-9a-f]{12}$/', dev_req_id()) && dev_req_id() === dev_req_id());
devloop_errors_sync();   // פעם ראשונה: מתחילים מעכשיו
$log = function (string $action, int $status, string $msg, string $req) {
    dev_track(['id' => 1], $action, microtime(true), $status, null, ['message' => $msg,
        'trace' => $action === 'client_error' ? 'editor.js:12:3' : null, 'page' => $action === 'client_error' ? '#tpl=4' : null]);
    db()->prepare('UPDATE dev_log SET req = ? WHERE id = (SELECT MAX(id) FROM dev_log)')->execute([$req]);   // כאילו בקשות נפרדות
    return (int)db()->query('SELECT MAX(id) FROM dev_log')->fetchColumn();
};
$GLOBALS['dt_tracked'] = false;
$id1 = $log('doc_save', 500, 'Undefined index 17', 'aaaaaaaaaaa1');
$id2 = $log('doc_save', 422, 'חסר שם', 'aaaaaaaaaaa2');                       // סירוב — לא באג
$id3 = $log('client_error', 0, 'x is not a function', 'aaaaaaaaaaa3');
$id4 = $log('doc_save', 500, 'Undefined index 99', 'aaaaaaaaaaa4');             // אותה שגיאה, מספר אחר
$lv = array_column(dt_devloop_log_rows(['ids' => [$id1, $id2, $id3]]), null, 'id');
check('רמות בלוג: 5xx ודפדפן = error, 4xx = warn', $lv[$id1]['level'] === 'error' && $lv[$id2]['level'] === 'warn' && $lv[$id3]['level'] === 'error'
    && $lv[$id3]['category'] === 'client' && $lv[$id3]['data']['source'] === 'editor.js' && $lv[$id3]['data']['line'] === '12');
$made = devloop_errors_sync();
$tri = array_values(array_filter(devloop_task_list()['items'], fn($x) => $x['status'] === 'triage'));
check("שגיאות שרת ודפדפן נפתחו לבירור — 2 ($made), לא ה-422", $made === 2 && count($tri) === 2);
$srv = array_values(array_filter($tri, fn($x) => str_contains($x['title'], 'שרת')))[0];
check('אותה שגיאה פעמיים — מטלה אחת, ×2, עם מזהה הבקשה', $srv['occurrences']['count'] === 2 && in_array($srv['context']['requests'][0]['id'], ['aaaaaaaaaaa1', 'aaaaaaaaaaa4'], true));
$links = devloop_log_links([$id1, $id2, $id3, $id4]);
check('מהלוג למטלה: שתי השורות של השגיאה → אותה מטלה; הסירוב — בלי', ($links['ids'][$id1] ?? '') === $srv['code'] && ($links['ids'][$id4] ?? '') === $srv['code']
    && !isset($links['ids'][$id2]) && isset($links['ids'][$id3]));
$il = devloop_item_log($srv['code']);
check('מהמטלה ללוג (📜): שני המופעים', array_column($il['rows'], 'id') === [$id4, $id1]);
check('הפיד של Claude למטלה כולל את הלוג', count(devloop_api_feed($k, ['code' => $srv['code']])['log']) === 2);
check('חיפוש בלוג לפי מזהה בקשה', count(dev_log_list(['req' => 'aaaaaaaaaaa3'])['rows']) === 1 && dev_log_list(['req' => 'aaaaaaaaaaa3'])['rows'][0]['level'] === 'error');
$id5 = $log('doc_get', 500, 'Division by zero', 'aaaaaaaaaaa5');
$r = devloop_task_from_log($dev, ['log_id' => $id5]);
check('"+ מטלה" משורה בלוג — נפתחת מטלה (פתוח)', $r['created'] && devloop_task_get($r['code'])['status'] === 'open');
check('שוב מאותה חתימה — אותה מטלה', devloop_task_from_log($dev, ['log_id' => $id5])['code'] === $r['code'] && !devloop_task_from_log($dev, ['log_id' => $id5])['created']);
devloop_task_set($dev, ['code' => $srv['code'], 'ignore' => true]);
$log('doc_save', 500, 'Undefined index 5', 'aaaaaaaaaaa6');
devloop_errors_sync();
check('🙈 להתעלם — מופע חדש רק נספר', devloop_task_get($srv['code'])['status'] === 'closed' && devloop_task_get($srv['code'])['occurrences']['count'] === 3);
dt_devloop_log_write('warn', 'auth', 'devapi.denied', ['message' => 'אסימון מטלות לא תקף']);
$w = dt_devloop_log_rows(['limit' => 1])[0];
check('רשומה מהמודול — בלוג, עם רמה ואירוע', $w['level'] === 'warn' && $w['event'] === 'devapi.denied' && $w['category'] === 'auth');

foreach (array_merge(glob("$tmp/files/_dev/*") ?: [], glob("$tmp/files/*") ?: [], array_filter(glob("$tmp/{,.}*", GLOB_BRACE) ?: [], 'is_file')) as $f) @unlink($f);
@rmdir("$tmp/files/_dev");
@rmdir("$tmp/files");
@rmdir($tmp);
echo $fail ? "\n$fail נכשלו\n" : "\nהכול עבר\n";
exit($fail ? 1 : 0);
