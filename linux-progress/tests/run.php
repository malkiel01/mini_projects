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


// ---------- סיכום מול התוכנית (lib/syllabus.php) ----------
require_once __DIR__ . '/../lib/syllabus.php';
$syl = ['tracks' => [['id' => '01', 'title' => 'יסודות'], ['id' => '03', 'title' => 'Linux']],
        'modules' => [['id' => '01.01', 'trackId' => '01', 'title' => 'א'], ['id' => '01.02', 'trackId' => '01', 'title' => 'ב'],
                      ['id' => '03.01', 'trackId' => '03', 'title' => 'ג'], ['id' => '03.02', 'trackId' => '03', 'title' => 'ד']]];
$cur = lp_read_curriculum($syl);
ok('תוכנית: נקראה עם 2 מסלולים ו-4 מודולים', $cur !== null && count($cur['tracks']) === 2 && count($cur['modules']) === 4);
ok('תוכנית לא תקינה → null', lp_read_curriculum(['x' => 1]) === null);
$today = new DateTimeImmutable('2026-11-24');
$items = [
    ['date' => '2026-11-22', 'topic' => 'chmod', 'status' => 'done', 'note' => '', 'moduleId' => '03.02'],
    ['date' => '2026-11-22', 'topic' => 'מודול אחר', 'status' => 'verified', 'note' => '', 'moduleId' => '01.01'],
    ['date' => '2026-11-22', 'topic' => 'בלי מזהה', 'status' => 'done', 'note' => ''],
    ['date' => '2026-11-22', 'topic' => 'מזהה לא קיים', 'status' => 'done', 'note' => '', 'moduleId' => '09.09'],
];
$sum = lp_summary($cur, $items, $today);
ok('סיכול: נלמדו 2 מודולים מתוך 4 (50%)', $sum['overall']['practiced'] === 2 && $sum['overall']['total'] === 4 && $sum['overall']['percent'] === 50.0);
ok('סיכום: אומתו 1 (רק 01.01)', $sum['overall']['verified'] === 1);
ok('מסלול 03: 1 מתוך 2, נותר 03.01', $sum['tracks'][1]['id'] === '03' && $sum['tracks'][1]['practiced'] === 1 && $sum['tracks'][1]['remaining'][0]['id'] === '03.01');
ok('לא ממופה: 2 רשומות (בלי מזהה, ומזהה לא קיים)', $sum['unmapped'] === 2);
ok('קצב: שבוע 1 מ-22.11, 2 מודולים נלמדים', $sum['pace']['weeks'] === 1 && $sum['pace']['modulesPerWeek'] === 2.0);
$sumEmpty = lp_summary($cur, [], $today);
ok('בלי רשומות: אחוז 0 וקצב ריק', $sumEmpty['overall']['percent'] === 0.0 && $sumEmpty['pace'] === null);
$sumFar = lp_summary($cur, [['date' => '2026-10-01', 'topic' => 'x', 'status' => 'done', 'note' => '', 'moduleId' => '01.01']], $today);
ok('קצב: 54 ימים = 8 שבועות, 1 מודול → 0.1 בשבוע', $sumFar['pace']['weeks'] === 8 && $sumFar['pace']['modulesPerWeek'] === 0.1);
ok('moduleId בצורה לא תקינה → הרשומה נפסלת', lp_validate(['items' => [['date' => '2026-11-22', 'topic' => 'a', 'status' => 'done', 'moduleId' => '3.2']]]) === null);
ok('moduleId תקין נשמר', lp_validate(['items' => [['date' => '2026-11-22', 'topic' => 'a', 'status' => 'done', 'moduleId' => '03.02']]])[0]['moduleId'] === '03.02');
[$cs, $cc] = lp_fetch_curriculum(str_repeat('t', 30), fn($u, $t) => [200, json_encode($syl, JSON_UNESCAPED_UNICODE)]);
ok('תוכנית נקראת מהריפו דרך השרת', $cs === 'ok' && count($cc['modules']) === 4);
ok('תוכנית: בלי סוד → no_token', lp_fetch_curriculum(null)[0] === 'no_token');
ok('תוכנית: 404 → unavailable', lp_fetch_curriculum('x', fn($u, $t) => [404, false])[0] === 'unavailable');


// ---------- מדדים, קצב ותחזית (lib/metrics.php) ----------
require_once __DIR__ . '/../lib/metrics.php';
$t0 = new DateTimeImmutable('2026-11-24');
$mi = [
    ['date' => '2026-11-22', 'topic' => 'a', 'status' => 'done', 'note' => '', 'moduleId' => '03.02'],
    ['date' => '2026-11-22', 'topic' => 'b', 'status' => 'verified', 'note' => '', 'moduleId' => '01.01'],
    ['date' => '2026-11-15', 'topic' => 'c', 'status' => 'done', 'note' => ''],
    ['date' => '2026-10-20', 'topic' => 'd', 'status' => 'done', 'note' => ''],
];
$pc = lp_pace($mi, $t0);
ok('קצב: ימים פעילים = 3 (תאריכים שונים)', $pc['activeDays'] === 3);
ok('קצב: 7 ימים אחרונים = 2 תרגולים', $pc['windows']['d7']['items'] === 2);
ok('קצב: 30 ימים אחרונים = 3 תרגולים', $pc['windows']['d30']['items'] === 3);
ok('קצב: ימים מאז התרגול האחרון = 2', $pc['daysSinceLast'] === 2);
ok('קצב: בלי רשומות → null ו-0', lp_pace([], $t0)['lastDate'] === null && lp_pace([], $t0)['activeDays'] === 0);
$wk = lp_weekly($mi, $t0, 8);
ok('שבועות: 8 נקודות, השבוע האחרון = 2', count($wk) === 8 && $wk[7]['items'] === 2);
ok('רמת ביטחון נמוכה בלי נתונים מספיקים', lp_confidence($pc, 2) === 'נמוכה');
ok('רמת ביטחון בינונית רק עם 4 שבועות ו-10 מודולים', lp_confidence(['spanWeeks' => 5], 10) === 'בינונית');
$sum = lp_summary(lp_read_curriculum(['tracks' => [['id' => '01', 'title' => 'x']], 'modules' => [['id' => '01.01', 'trackId' => '01', 'title' => 'a'], ['id' => '01.02', 'trackId' => '01', 'title' => 'b']]]), $mi, $t0);
$met = lp_metrics($sum, $mi, $t0);
ok('מדדים: נותרו מודולים = סה"כ פחות נלמדו', $met['measured']['modulesRemaining'] === 1);
ok('מדדים: אחוז נלמד נכון', $met['measured']['percentPracticed'] === 50.0);
ok('מדדים: נתוני זמן מסומנים כחסרים', str_contains($met['missing']['studyTime'], 'אין נתוני זמן'));
ok('תחזית: שלושה תרחישים בהנחות המנהל', count($met['forecastBasis']['scenarios']) === 3 && $met['forecastBasis']['scenarios']['likely']['totalHours'] === 480);


// רגרסיה: מסלולים 10 ו-11 (מפתחות מספריים ב-PHP) חייבים להופיע בסיכום
$curTen = lp_read_curriculum(['tracks' => [['id' => '09', 'title' => 'a'], ['id' => '10', 'title' => 'b'], ['id' => '11', 'title' => 'c']],
    'modules' => [['id' => '09.01', 'trackId' => '09', 'title' => 'x'], ['id' => '10.01', 'trackId' => '10', 'title' => 'y'], ['id' => '11.01', 'trackId' => '11', 'title' => 'z']]]);
$sumTen = lp_summary($curTen, [], $t0);
ok('מסלולים 10 ו-11 מופיעים בסיכום', count($sumTen['tracks']) === 3 && $sumTen['tracks'][1]['id'] === '10' && $sumTen['tracks'][2]['id'] === '11');
ok('סה"כ מודולים כולל מסלולים 10 ו-11', $sumTen['overall']['total'] === 3);

echo ($fails === 0 ? "✓ " : "✗ ") . ($n - $fails) . " עברו, $fails נכשלו (מתוך $n)\n";
exit($fails === 0 ? 0 : 1);
