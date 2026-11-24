<?php
/** בדיקות linux-progress: אימות הנתונים, נתיבי הסוד, שמירה/קריאה, ומקור. רק CLI, בלי רשת. */
declare(strict_types=1);
require_once __DIR__ . '/../lib/store.php';

$fails = 0; $n = 0;
function ok(string $name, bool $cond): void { global $fails, $n; $n++; if (!$cond) { $fails++; echo "✗ $name\n"; } }

// --- אימות צורה ---
$good = ['items' => [['date' => '2026-11-20', 'topic' => 'chmod', 'status' => 'done', 'note' => 'x', 'extra' => 'dropped']]];
$v = lp_validate($good);
ok('תקין עובר', is_array($v) && count($v) === 1);
ok('שדה נוסף נזרק', $v !== null && !array_key_exists('extra', $v[0]));
ok('בלי items → לא תקין', lp_validate(['x' => 1]) === null);
ok('סטטוס לא מוכר → לא תקין', lp_validate(['items' => [['date' => '2026-11-20', 'topic' => 'a', 'status' => 'passed']]]) === null);
ok('תאריך שגוי → לא תקין', lp_validate(['items' => [['date' => '2026-02-31', 'topic' => 'a', 'status' => 'done']]]) === null);
ok('תאריך בפורמט אחר → לא תקין', lp_validate(['items' => [['date' => '20/11/2026', 'topic' => 'a', 'status' => 'done']]]) === null);
ok('נושא ריק → לא תקין', lp_validate(['items' => [['date' => '2026-11-20', 'topic' => '', 'status' => 'done']]]) === null);
ok('הערה ארוכה מדי → לא תקין', lp_validate(['items' => [['date' => '2026-11-20', 'topic' => 'a', 'status' => 'done', 'note' => str_repeat('א', 501)]]]) === null);
ok('סטטוס verified תקין', is_array(lp_validate(['items' => [['date' => '2026-11-20', 'topic' => 'a', 'status' => 'verified']]])));

// --- מקור: לא לכתוב, לטפל בכשלים ---
$tok = str_repeat('t', 30);
ok('בלי סוד → no_token', lp_fetch_progress(null)[0] === 'no_token');
$f = fn(int $c, string|false $b = false) => fn($u, $t) => [$c, $b];
ok('404 → no_file', lp_fetch_progress($tok, $f(404))[0] === 'no_file');
ok('401 → unauthorized', lp_fetch_progress($tok, $f(401))[0] === 'unauthorized');
ok('403 → unauthorized', lp_fetch_progress($tok, $f(403))[0] === 'unauthorized');
ok('500 → unavailable', lp_fetch_progress($tok, $f(500))[0] === 'unavailable');
ok('JSON שבור → invalid', lp_fetch_progress($tok, $f(200, '{nope'))[0] === 'invalid');
ok('200 תקין → ok', lp_fetch_progress($tok, $f(200, json_encode($good, JSON_UNESCAPED_UNICODE)))[0] === 'ok');
[$st, $items] = lp_fetch_progress($tok, $f(200, json_encode($good, JSON_UNESCAPED_UNICODE)));
ok('הטוקן לא מופיע בתשובה', !str_contains(json_encode([$st, $items]), $tok));
$seen = null;
lp_fetch_progress($tok, function ($u, $t) use (&$seen) { $seen = $u; return [404, false]; });
ok('כתובת הקריאה: הקובץ המוגדר בלבד', $seen === 'https://api.github.com/repos/malkiel01/offensive-cyber-academy/contents/progress/linux-progress.json');

// --- נתיב הסוד: מחוץ לאתר ---
ok('נתיב הסוד לא בתוך האתר', !lp_in_webroot(lp_secret_path()));
ok('נתיב הסוד מוביל לתיקייה academy-secrets', str_ends_with(lp_secret_path(), '/academy-secrets/linux-progress-token'));
ok('נתיב בתוך public_html נחסם', lp_in_webroot('/home2/u/public_html/x/tok'));
ok('נתיב בתוך האפליקציה נחסם', lp_in_webroot(lp_app_dir() . '/tok'));

// --- שמירה וקריאה בנתיב זמני מחוץ לאתר ---
$base = sys_get_temp_dir() . '/lp-test-' . getmypid();
@mkdir($base, 0o700);
$p = "$base/academy-secrets/tok";
ok('שמירה ראשונה (יוצרת תיקייה 0700)', lp_save_token($tok, $p) === true);
ok('הקובץ נקרא בחזרה', lp_read_token($p) === $tok);
ok('הרשאות 0600', (fileperms($p) & 0o777) === 0o600);
ok('שמירה חוזרת מחליפה', lp_save_token(str_repeat('u', 30), $p) === true && lp_read_token($p) === str_repeat('u', 30));
ok('בלי קבצי זמניים', glob("$base/academy-secrets/.lp-token.*") === []);
chmod($p, 0o644);
ok('קובץ קריא לאחרים → לא נקרא', lp_read_token($p) === null);
chmod($p, 0o600);
ok('ערך קצר מדי → לא נשמר', lp_save_token('short', "$base/academy-secrets/t2") === false || lp_read_token("$base/academy-secrets/t2") === null);
ok('שמירה בתוך האתר נחסמת', lp_save_token($tok, lp_app_dir() . '/tok') === false);
ok('תיקיית הורה חסרה → לא נוצרת', lp_save_token($tok, "$base/missing/deep/tok") === false && !is_dir("$base/missing"));
ok('תיקייה פתוחה לכולם → לא נשמר', (function () use ($base, $tok) { @mkdir("$base/wide", 0o777); chmod("$base/wide", 0o777); return lp_save_token($tok, "$base/wide/tok") === false; })());

// ניקוי
array_map('unlink', glob("$base/academy-secrets/*") ?: []);
array_map('unlink', glob("$base/wide/*") ?: []);
@rmdir("$base/academy-secrets"); @rmdir("$base/wide"); @rmdir($base);

echo ($fails === 0 ? "✓ " : "✗ ") . ($n - $fails) . " עברו, $fails נכשלו (מתוך $n)\n";
exit($fails === 0 ? 0 : 1);
