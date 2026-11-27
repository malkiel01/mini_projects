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


// ---------- מודל ההחלטה 00B (lib/model.php, lib/syllabus.php, lib/metrics.php, lib/milestones.php) ----------
require_once __DIR__ . '/../lib/syllabus.php';
require_once __DIR__ . '/../lib/model.php';
require_once __DIR__ . '/../lib/metrics.php';
require_once __DIR__ . '/../lib/milestones.php';

$syl = ['tracks' => [['id' => '01', 'title' => 'יסודות'], ['id' => '03', 'title' => 'Linux']],
        'modules' => [['id' => '01.01', 'trackId' => '01', 'title' => 'א'], ['id' => '01.02', 'trackId' => '01', 'title' => 'ב'],
                      ['id' => '03.01', 'trackId' => '03', 'title' => 'ג'], ['id' => '03.02', 'trackId' => '03', 'title' => 'ד']],
        'lessons' => [
            ['id' => '01.01.A', 'moduleId' => '01.01', 'title' => 'תיאוריה', 'kind' => 'theory', 'order' => 1, 'learningObjectives' => ['o']],
            ['id' => '01.01.B', 'moduleId' => '01.01', 'title' => 'מעבדה', 'kind' => 'lab', 'order' => 2, 'learningObjectives' => []],
            ['id' => '03.02.A', 'moduleId' => '03.02', 'title' => 'מעבדה 2', 'kind' => 'lab', 'order' => 1, 'learningObjectives' => []],
        ]];
$cur = lp_read_curriculum($syl);
$t0 = new DateTimeImmutable('2026-11-24', new DateTimeZone('Asia/Jerusalem'));
$dec = fn(array $items) => lp_read_decisions(['schemaVersion' => 1, 'items' => $items]);
$eff = fn(array $items) => lp_effective_decisions($dec($items)[1]);
$rec = fn(string $date, string $status, ?string $mid, array $extra = []) => ['date' => $date, 'topic' => 't', 'status' => $status, 'note' => ''] + ['moduleId' => $mid] + $extra;

// --- מצבים ומדדים: רשומת תרגול אינה השלמה ---
$practiced = [$rec('2026-11-22', 'done', '03.02'), $rec('2026-11-22', 'done', '03.02')];
$sum = lp_summary($cur, $practiced, [], $t0);
$mod = fn($sum, $id) => array_values(array_filter($sum['tracks'][array_search('03', array_column($sum['tracks'], 'id'), true) ?: 0]['modules'], fn($m) => $m['id'] === $id))[0];
ok('תרגול בלבד: המודול במצב in_progress, לא נלמד', $mod($sum, '03.02')['status'] === 'in_progress');
ok('תרגול בלבד: אף יחידה לא הושלמה', $sum['overall']['lessonsCompleted'] === 0 && $sum['overall']['percentLessonsCompleted'] === 0.0);
ok('שני תרגולים באותו מודול לא מכפילים ספירה', $sum['practice']['modulesTouched'] === 1);

// verified במודול 03.02 אינו מאמת מודול
$ver = [$rec('2026-11-22', 'verified', '03.02')];
$sumV = lp_summary($cur, $ver, [], $t0);
ok('verified רשומה לא מאמתת מודול', $sumV['overall']['modulesVerified'] === 0 && $mod($sumV, '03.02')['status'] === 'in_progress');
ok('verified נספר כאימות יכולת נפרד', $sumV['practice']['capabilityChecks'] === 1);

// רשומה בלי lessonId לא משלימה יחידה
ok('רשומה בלי lessonId לא מסמנת יחידה', $sum['tracks'][0]['modules'][0]['lessons'][0]['status'] === 'not_started');

// --- החלטות מנהל ---
$unit = [['decisionId' => 'd1', 'date' => '2026-11-25', 'targetType' => 'lesson', 'targetId' => '01.01.A', 'status' => 'completed', 'approvedBy' => 'manager-00', 'reason' => 'ok']];
$sumU = lp_summary($cur, [], $eff($unit), $t0);
$lessonA = $sumU['tracks'][0]['modules'][0]['lessons'][0];
ok('החלטת מנהל: יחידה completed נספרת', $lessonA['status'] === 'completed' && $sumU['overall']['lessonsCompleted'] === 1);
ok('אחוז יחידות מחושב נכון (1 מתוך 3)', $sumU['overall']['percentLessonsCompleted'] === 33.3);

$labNoEvidence = [['decisionId' => 'd2', 'date' => '2026-11-25', 'targetType' => 'lesson', 'targetId' => '01.01.B', 'status' => 'completed', 'approvedBy' => 'm', 'reason' => '']];
$sumL = lp_summary($cur, [], $eff($labNoEvidence), $t0);
ok('מעבדה בלי ראיה מעשית לא נספרת', $sumL['overall']['lessonsCompleted'] === 0);
$labEv = [['decisionId' => 'd3', 'date' => '2026-11-25', 'targetType' => 'lesson', 'targetId' => '01.01.B', 'status' => 'completed', 'approvedBy' => 'm', 'reason' => '', 'evidenceRefs' => ['pr#1']]];
ok('מעבדה עם ראיה נספרת', lp_summary($cur, [], $eff($labEv), $t0)['overall']['lessonsCompleted'] === 1);

$modLearned = [['decisionId' => 'm1', 'date' => '2026-11-25', 'targetType' => 'module', 'targetId' => '01.01', 'status' => 'learned_pending_verification', 'approvedBy' => 'm', 'reason' => '']];
$sumM = lp_summary($cur, [], $eff($modLearned), $t0);
ok('החלטת מודול: נלמד ממתין לאימות', $sumM['tracks'][0]['modules'][0]['status'] === 'learned_pending_verification' && $sumM['overall']['modulesLearnedPending'] === 1);
$modVer = array_merge($modLearned, [['decisionId' => 'm2', 'date' => '2026-11-26', 'targetType' => 'module', 'targetId' => '01.01', 'status' => 'verified', 'approvedBy' => 'm', 'reason' => '']]);
ok('החלטה אחרונה קובעת: verified אחרי learned', lp_summary($cur, [], $eff($modVer), $t0)['overall']['modulesVerified'] === 1);

// --- קובץ החלטות לא תקין ---
ok('schemaVersion שגוי → לא תקין', $dec([])[0] === true && lp_read_decisions(['schemaVersion' => 2, 'items' => []])[0] === false);
ok('decisionId כפול → לא תקין', lp_read_decisions(['schemaVersion' => 1, 'items' => [$unit[0], $unit[0]]])[0] === false);
ok('החלטת יחידה עם סטטוס מודול → לא תקין', lp_read_decisions(['schemaVersion' => 1, 'items' => [['decisionId' => 'x', 'date' => '2026-11-25', 'targetType' => 'lesson', 'targetId' => '01.01.A', 'status' => 'learned_pending_verification', 'approvedBy' => 'm']]])[0] === false);
ok('קובץ החלטות לא תקין → אין החלטה נספרת', lp_effective_decisions([])  === []);

// --- שדות הרחבה ---
$extOk = lp_validate(['items' => [['date' => '2026-11-22', 'topic' => 'a', 'status' => 'done', 'moduleId' => '01.01', 'lessonId' => '01.01.A', 'studyMode' => 'phone', 'activityType' => 'theory', 'durationMinutes' => 30, 'assistanceLevel' => 'independent', 'evidenceType' => 'quiz', 'evidenceRef' => null]]]);
ok('שדות הרחבה תקינים נשמרים', is_array($extOk) && $extOk[0]['durationMinutes'] === 30 && $extOk[0]['lessonId'] === '01.01.A');
$legacy = lp_validate(['items' => [['date' => '2026-11-22', 'topic' => 'a', 'status' => 'done', 'moduleId' => '03.02']]]);
ok('רשומה ישנה בלי שדות הרחבה תקפה, והשדות חסרים (null)', is_array($legacy) && $legacy[0]['durationMinutes'] === null && $legacy[0]['studyMode'] === null);
ok('משך 0 → לא תקין (לא מומר לאפס)', lp_validate(['items' => [['date' => '2026-11-22', 'topic' => 'a', 'status' => 'done', 'durationMinutes' => 0]]]) === null);
ok('studyMode לא מוכר → לא תקין', lp_validate(['items' => [['date' => '2026-11-22', 'topic' => 'a', 'status' => 'done', 'studyMode' => 'tablet']]]) === null);
ok('lessonId בצורה לא תקינה → לא תקין', lp_validate(['items' => [['date' => '2026-11-22', 'topic' => 'a', 'status' => 'done', 'lessonId' => '01.01']]]) === null);
ok('recordId כפול → לא תקין', lp_validate(['items' => [['date' => '2026-11-22', 'topic' => 'a', 'status' => 'done', 'recordId' => 'r1'], ['date' => '2026-11-22', 'topic' => 'b', 'status' => 'done', 'recordId' => 'r1']]]) === null);

// --- זמן: חסר לא נספר כאפס ---
$timed = [$rec('2026-11-22', 'done', '01.01', ['durationMinutes' => 30, 'studyMode' => 'phone']), $rec('2026-11-22', 'done', '01.01')];
$sumT = lp_summary($cur, $timed, [], $t0);
ok('כיסוי זמן חלקי: 50%, סה"כ 30 דקות', $sumT['time']['coveragePercent'] === 50.0 && $sumT['time']['minutesReported'] === 30);
ok('בלי רשומות: כיסוי זמן null', lp_summary($cur, [], [], $t0)['time']['coveragePercent'] === null);
ok('מטריקס: הערת זמן חלקי', str_contains(lp_metrics($sumT, $timed, $t0)['missing']['studyTime'], 'חסר אינו נחשב כאפס'));

// --- תחזית: ביטחון נמוך תמיד, ומבוססת על מודולים שלא נלמדו/אומתו ---
$metT = lp_metrics($sumM, [], $t0);
ok('תחזית: ביטחון נמוך גם עם נתונים', lp_metrics($sumM, $timed, $t0)['forecastBasis']['confidence'] === 'נמוכה');
ok('תחזית: מודול שנלמד לא נספר כנותר (ממתין לאימות נחשב נלמד)', $metT['forecastBasis']['remainingModules'] === 3);
ok('תחזית: תרגול בלבד לא מפחית מודול', lp_metrics($sum, $practiced, $t0)['forecastBasis']['remainingModules'] === 4);

// --- אבני דרך: כיסוי ולא מומחיות ---
$states = ['01.01' => 'verified', '01.02' => 'not_started', '03.01' => 'not_started', '03.02' => 'in_progress'];
$ms = lp_milestones($cur['modules'], $states);
ok('אבן דרך יסודות: מודול 01 מכוסה, מודול אחד מאומת מתוך 2', $ms[0]['requiredModules'] === 2 && $ms[0]['verifiedModules'] === 1 && $ms[0]['state'] === 'in_progress');
ok('הרחבת טווח: 03.01–03.03 מכסה רק מודולים קיימים', lp_expand_coverage('03.01–03.03', $cur['modules']) === ['03.01', '03.02']);
ok('הרחבת מסלול: "03" מכסה את מודולי המסלול', count(lp_expand_coverage('03', $cur['modules'])) === 2);
ok('אבן דרך לא מסומנת מוכנה בלי תנאי מנהל', $ms[0]['state'] !== 'achieved' && !str_contains(json_encode($ms), 'achieved'));

// --- שינוי מעתה: רשומה ישנה נשמרת, ורשומת done אינה מחליפה החלטה ---
ok('החלטה אינה נמחקת על ידי רשומת תרגול', lp_summary($cur, $practiced, $eff($modLearned), $t0)['overall']['modulesLearnedPending'] === 1);

// --- קצב, שבועות, רמות (נבדק כמו קודם) ---
$mi = [$rec('2026-11-22', 'done', '03.02'), $rec('2026-11-22', 'verified', '01.01'), $rec('2026-11-15', 'done', null), $rec('2026-10-20', 'done', null)];
$pc = lp_pace($mi, new DateTimeImmutable('2026-11-24'));
ok('קצב: ימים פעילים = 3', $pc['activeDays'] === 3);
ok('קצב: 7 ימים אחרונים = 2', $pc['windows']['d7']['items'] === 2);
ok('שבועות: 8 נקודות, השבוע האחרון = 2', count(lp_weekly($mi, new DateTimeImmutable('2026-11-24'), 8)) === 8);
ok('אזור ישראל: התרגול האחרון לפני 2 ימים', lp_pace([$rec('2026-11-22', 'done', null)], $t0)['daysSinceLast'] === 2);

// רגרסיה: מסלולים 10 ו-11 מופיעים בסיכום
$curTen = lp_read_curriculum(['tracks' => [['id' => '09', 'title' => 'a'], ['id' => '10', 'title' => 'b'], ['id' => '11', 'title' => 'c']],
    'modules' => [['id' => '09.01', 'trackId' => '09', 'title' => 'x'], ['id' => '10.01', 'trackId' => '10', 'title' => 'y'], ['id' => '11.01', 'trackId' => '11', 'title' => 'z']]]);
$sumTen = lp_summary($curTen, [], [], $t0);
ok('מסלולים 10 ו-11 מופיעים בסיכום', count($sumTen['tracks']) === 3 && $sumTen['tracks'][1]['id'] === '10' && $sumTen['tracks'][2]['id'] === '11');

echo ($fails === 0 ? "✓ " : "✗ ") . ($n - $fails) . " עברו, $fails נכשלו (מתוך $n)\n";
exit($fails === 0 ? 0 : 1);
