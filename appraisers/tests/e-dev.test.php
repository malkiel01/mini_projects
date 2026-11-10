<?php
// מסך המפתח — 01-E1 … 01-E5: DevLoop, בדיקות תקינות, שירותי רקע והמתזמן. (01-E6 ו-cron.php עצמו — z-http.test.php.)
require __DIR__ . '/lib.php';

$owner = ident('1', 'הבעלים', 'owner@example.com', ['platform_admin' => true]);
$user = ident('10', 'אבי', 'avi@a.co');
call('GET', 'me', [], $user);
call('GET', 'me', [], $owner);

// ── 01-E1: כל פעולת dev-*, בדיקות התקינות ושירותי הרקע → 404 למי שאינו מפתח ──
$devActions = array_keys(array_filter(APR_ACCESS, fn($r) => $r === 'developer'));
check('01-E1 יש פעולות מפתח לבדוק', count($devActions) >= 20);
foreach ($devActions as $a) {
    $m = in_array($a, APR_READS, true) ? 'GET' : 'POST';
    eq(call($m, $a, ['title' => 'x', 'code' => 'AP-001', 'body' => 'x'], $user)[0], 404, "01-E1 $m $a → 404 למי שאינו מפתח");
}
eq(call('GET', 'dev-health', [], $user)[1]['error'], ['code' => 'not_found', 'message' => 'לא נמצא'], '01-E1 אותו נוסח כמו פעולה שלא קיימת');
eq(call('GET', 'no-such-action', [], $user)[1]['error'], ['code' => 'not_found', 'message' => 'לא נמצא'], '01-E1 (פעולה שלא קיימת)');
eq((int)apr_val("SELECT COUNT(*) FROM sqlite_master WHERE name = 'dev_tasks'"), 0, '01-E1 ושום דבר לא נוצר');

// ── 01-E2: health בלי משתמש — רק ok ו-version ──
eq(call('GET', 'health')[1], ['ok' => true, 'version' => apr_app_version()], '01-E2 health ציבורי: רק ok ו-version');
eq(call('POST', 'health')[0], 405, 'health ב-POST → 405');

// ── בדיקות התקינות ──
copy(__DIR__ . '/../data/.htaccess', APR_DATA_DIR . '/.htaccess');
$health = fn() => array_column(call('GET', 'dev-health', [], $owner)[1]['checks'], null, 'id');
[$st, $h] = call('GET', 'dev-health', [], $owner);
$by = array_column($h['checks'], null, 'id');
$expect = ['db', 'schema', 'write', 'protect', 'secret', 'php', 'version', 'platform', 'devloop', 'roadmap', 'disk', 'cron', 'queue', 'mail'];
eq(array_values(array_diff($expect, array_keys($by))), [], 'כל 14 הבדיקות שבאפיון (§9.4) רשומות');
check('לכל בדיקה: שם, מצב ופירוט', !array_filter($h['checks'], fn($c) => $c['label'] === '' || !in_array($c['state'], ['ok', 'warn', 'bad'], true) || $c['detail'] === ''));
foreach (['db', 'schema', 'write', 'protect', 'secret', 'version', 'platform', 'devloop', 'roadmap', 'queue'] as $id) eq($by[$id]['state'], 'ok', "תקין: $id — {$by[$id]['detail']}");
eq($by['cron']['state'], 'warn', 'המתזמן עוד לא רץ — אזהרה עם הוראה');
check('בסביבת הבדיקה אין כשל', $h['state'] !== 'bad', array_filter($h['checks'], fn($c) => $c['state'] === 'bad'));

$GLOBALS['apr_http'] = fn(string $url) => str_contains($url, 'sso.php') ? [200, '{"connected":true}'] : [200, 'SQLite format 3'];
eq([$health()['protect']['state'], $health()['secret']['state']], ['bad', 'bad'], 'המסד או המפתח נגישים מהדפדפן — כשל');
$GLOBALS['apr_http'] = fn(string $url) => str_contains($url, 'sso.php') ? [200, '{"connected":false}'] : [403, ''];
eq($health()['platform']['state'], 'bad', 'האפליקציה מנותקת במעטפת — כשל');
$GLOBALS['apr_http'] = fn(string $url) => str_contains($url, 'sso.php') ? null : [403, ''];
eq($health()['platform']['state'], 'warn', 'המעטפת לא עונה — אזהרה');
$GLOBALS['apr_http'] = fn(string $url) => str_contains($url, 'sso.php') ? [200, '{"connected":true}'] : [403, ''];
unlink(APR_DATA_DIR . '/.htaccess');
eq($health()['protect']['state'], 'bad', 'חסר data/.htaccess — כשל');
copy(__DIR__ . '/../data/.htaccess', APR_DATA_DIR . '/.htaccess');
// רישום: פרוסה אחרת מוסיפה בדיקה; בדיקה שזורקת לא מפילה את השאר, ולא חושפת את ההודעה
apr_health_register('x', 'ספק דמה', fn() => throw new RuntimeException('סוד בתוך הודעה'));
$by = $health();
eq([$by['x']['state'], $by['db']['state']], ['bad', 'ok'], 'בדיקה שזורקת מסומנת ככשל, והשאר נבדקו');
check('והודעת החריגה אינה מוצגת', !str_contains($by['x']['detail'], 'סוד'));
unset($GLOBALS['apr_health']['x']);

// ── 01-E3: DevLoop והאסימון של Claude ──
unset($_SERVER['HTTP_X_DEV_TOKEN']);
eq(call('GET', 'devapi-feed')[0], 401, '01-E3 devapi-feed בלי אסימון → 401');
[$st, $items] = call('GET', 'dev-items', [], $owner);
$file = json_decode(file_get_contents(__DIR__ . '/../dev/open-items.json'), true);
check('מפת הדרכים: JSON תקין עם פריטים', is_array($file['items'] ?? null) && count($file['items']) > 0);
$codes = array_column($file['items'], 'code');
check('לכל פריט קוד ייחודי AP-000', count(array_unique($codes)) === count($codes) && !array_filter($codes, fn($c) => !preg_match('/^AP-\d{3}$/', $c)));
check('לכל פריט כותרת, אזור ומצב תקין', !array_filter($file['items'], fn($x) => empty($x['title']) || empty($x['area']) || !in_array($x['status'] ?? '', ['open', 'in_progress', 'verify', 'question', 'closed'], true)));
eq([$st, count($items['items'])], [200, count($file['items'])], 'הרשימה במסך = הקובץ');
[$st, $t] = call('POST', 'dev-task-create', ['title' => 'כפתור השמירה לא מגיב', 'kind' => 'bug'], $owner);
check('מטלה חדשה מהמסך מקבלת את הקוד הבא', $st === 200 && preg_match('/^AP-\d{3}$/', $t['code']) === 1 && !in_array($t['code'], $codes, true), $t);
eq(call('POST', 'dev-comment', ['code' => $t['code'], 'body' => 'קורה רק בטלפון'], $owner)[0], 200, 'הערה בשיח');
[$st, $rep] = call('POST', 'dev-report', ['title' => 'המסך נתקע', 'context' => ['url' => '#/office', 'screen' => '390×844', 'requests' => [], 'errors' => []]], $owner);
check('דיווח 🐞 פותח מטלה עם ההקשר', $st === 200 && !empty($rep['code']), $rep);
[$st, $tok] = call('POST', 'dev-token-create', ['label' => 'Claude'], $owner);
check('אסימון apd_', $st === 200 && str_starts_with($tok['token'], 'apd_'));
$_SERVER['HTTP_X_DEV_TOKEN'] = 'apd_' . str_repeat('ab', 24);
eq(call('GET', 'devapi-feed')[0], 401, '01-E3 אסימון מומצא → 401');
$_SERVER['HTTP_X_DEV_TOKEN'] = 'pmd_' . substr($tok['token'], 4);
eq(call('GET', 'devapi-feed')[0], 401, '01-E3 אסימון של אפליקציה אחרת → 401');
$_SERVER['HTTP_X_DEV_TOKEN'] = $tok['token'];
[$st, $feed] = call('GET', 'devapi-feed');
check('01-E3 אסימון תקף → המטלות, בלי זהות מהשער', $st === 200 && in_array($t['code'], array_column($feed['items'], 'code'), true) && !empty($feed['next_code']), array_keys((array)$feed));
eq(call('POST', 'devapi-comment', ['code' => $t['code'], 'body' => 'תוקן', 'status' => 'verify'])[0], 200, 'Claude עונה ומעביר לאימות');
eq([call('GET', 'dev-items')[0], call('GET', 'me')[0], call('GET', 'dev-health')[0]], [401, 401, 401], 'האסימון לא פותח שום פעולה אחרת');
eq(call('POST', 'dev-verify', ['code' => $t['code'], 'ok' => true], $user)[0], 404, 'משתמש רגיל לא מאמת');
[$st] = call('POST', 'dev-verify', ['code' => $t['code'], 'ok' => true], $owner);
eq([$st, array_column(call('GET', 'dev-items', [], $owner)[1]['items'], 'status', 'code')[$t['code']]], [200, 'closed'], '✅ של המפתח סוגר');
// מקרה 16: אסימון שדלף — ביטול חוסם מיד
eq(call('POST', 'dev-token-revoke', ['id' => 1], $owner)[0], 200, 'ביטול האסימון');
eq(call('GET', 'devapi-feed')[0], 401, 'מקרה 16: אסימון שבוטל → 401 מיד');
check('מקרה 16: נרשם ביומן הפעולות', (bool)apr_val("SELECT 1 FROM audit_log WHERE action = 'devtoken.revoke'"));
unset($_SERVER['HTTP_X_DEV_TOKEN']);
eq(call('POST', 'dev-task-create', ['title' => ''], $owner)[1]['error'] ?? null, 'חסרה כותרת', 'פעולות DevLoop עונות בחוזה של המודול ({error: "…"})');

// היומן הטכני: שגיאות שרת ודפדפן, ושגיאה שנפתחת לבד כמטלה
devloop_log_write('error', 'php', 'exception', ['message' => 'PDOException: disk I/O error', 'data' => ['file' => 'offices.php', 'line' => 120]]);
eq(call('POST', 'devloop-client-error', ['message' => 'x is not defined', 'source' => 'app.js', 'line' => 12, 'page' => '#/office', 'secret_field' => 'לא אמור להישמר'], $user)[0], 200, 'שגיאת JavaScript של משתמש רגיל נרשמת');
eq(call('POST', 'devloop-client-error', ['message' => 'x'])[0], 401, 'אבל לא בלי זהות');
[$st, $log] = call('GET', 'dev-log', [], $owner, ['level' => 'error']);
$ev = array_column($log['rows'], 'event');
check('היומן: שגיאת השרת ושגיאת הדפדפן', $st === 200 && in_array('exception', $ev, true) && in_array('client.error', $ev, true), $ev);
$js = array_values(array_filter($log['rows'], fn($r) => $r['event'] === 'client.error'))[0];
eq([$js['username'], $js['data']['page'] ?? null, $js['data']['line'] ?? null, isset($js['data']['secret_field'])], ['אבי', '#/office', 12, false], 'שגיאת הדפדפן: מי, איפה, באיזו שורה — ורק השדות המותרים');
devloop_errors_sync();   // בשרת — רץ בכל פתיחה של המטלות
check('שגיאה ביומן נפתחת לבד כמטלה ⚠️ לבירור', (bool)array_filter(call('GET', 'dev-items', [], $owner)[1]['items'], fn($x) => $x['status'] === 'triage'));
check('היומן הטכני והצילומים — ב-data/_dev (מוחרגת מהפריסה)', is_file(APR_DATA_DIR . '/_dev/log.sqlite'));

// ── 01-E4: המתזמן ──
check('01-E4 אסימון המתזמן יציב, ונגזר ממפתח הסודות', apr_cron_token() === apr_cron_token() && preg_match('/^[a-f0-9]{40}$/', apr_cron_token()) === 1);
$now = apr_now();
$uid = (int)apr_val('SELECT id FROM users LIMIT 1');
apr_insert('sessions', ['user_id' => $uid, 'token_hash' => 'dead', 'csrf' => 'x', 'platform_sub' => '1', 'created_at' => $now, 'last_used_at' => $now, 'expires_at' => apr_iso(time() - 5)]);
apr_insert('jobs', ['pid' => apr_pid(), 'kind' => 'old.done', 'status' => 'done', 'run_after' => $now, 'finished_at' => apr_iso(time() - 31 * 86400), 'created_at' => $now]);
$r = apr_jobs_run(5.0, 'cron');
check('01-E4 הסבב רץ', ($r['ran'] ?? 0) >= 1 && ($r['failed'] ?? 1) === 0, $r);
eq(apr_val("SELECT status FROM jobs WHERE kind = 'maintenance.cleanup'"), 'done', '01-E4 maintenance.cleanup רץ');
check('01-E4 cron_last_ok_at התעדכן', apr_meta('cron_last_ok_at') !== null && strtotime(apr_meta('cron_last_ok_at')) >= time() - 5);
eq([(int)apr_val("SELECT COUNT(*) FROM sessions WHERE token_hash = 'dead'"), (int)apr_val("SELECT COUNT(*) FROM jobs WHERE kind = 'old.done'")], [0, 0], 'הניקוי: session שפג ומשימה ישנה נמחקו');
$r2 = apr_jobs_run(5.0, 'cron');
eq([$r2['ran'], (int)apr_val("SELECT COUNT(*) FROM jobs WHERE kind = 'maintenance.cleanup'")], [0, 1], 'אותה משימה לא נכנסת פעמיים באותו חלון (dedupe)');
// שתי הרצות במקביל — השנייה מדלגת
$lock = fopen(APR_DATA_DIR . '/cron.lock', 'c');
flock($lock, LOCK_EX);
eq(apr_jobs_run(1.0, 'cron'), ['skipped' => 'locked'], '01-E4 / מקרה 14: הרצה במקביל — השנייה מדלגת');
flock($lock, LOCK_UN);
fclose($lock);
eq($health()['cron']['state'], 'ok', 'אחרי הרצה — בדיקת המתזמן תקינה');
$GLOBALS['apr_time'] = time() + 1000;
eq([$health()['cron']['state'], apr_cron_stale()], ['warn', true], 'מתזמן שלא רץ 15 דקות — אזהרה; אחרי 10 — סבב גיבוי מבקשה רגילה');
unset($GLOBALS['apr_time']);

// ── 01-E5: משימה שנכשלת ──
$runs = 0;
apr_job_register('test.flaky', 'משימת בדיקה', function () use (&$runs) { $runs++; throw new RuntimeException('שם לקוח: ישראל ישראלי'); });
$pid = apr_job_add('test.flaky', ['n' => 1], 'flaky-1');
eq(apr_job_add('test.flaky', ['n' => 1], 'flaky-1'), null, 'dedupe_key: אותה משימה לא נכנסת פעמיים');
$job = fn() => apr_row('SELECT * FROM jobs WHERE pid = ?', [$pid]);
$t = time();
apr_jobs_run(2.0);
eq([$job()['status'], (int)$job()['attempts'], $runs], ['pending', 1, 1], '01-E5 נכשלה: attempts עולה, נשארת בתור');
check('01-E5 run_after נדחה בדקה', abs(strtotime($job()['run_after']) - ($t + 60)) <= 3, $job()['run_after']);
apr_jobs_run(2.0);
eq($runs, 1, 'לא רצה שוב לפני run_after');
$GLOBALS['apr_time'] = $t + 61;
apr_jobs_run(2.0);
check('01-E5 ניסיון שני: נדחה ב-5 דקות', (int)$job()['attempts'] === 2 && abs(strtotime($job()['run_after']) - ($t + 61 + 300)) <= 3);
$GLOBALS['apr_time'] = $t + 61 + 301;
apr_jobs_run(2.0);
eq([$job()['status'], (int)$job()['attempts'], $runs], ['failed', 3, 3], '01-E5 אחרי max_attempts → failed');
check('01-E5 last_error: הודעה טכנית', str_contains((string)$job()['last_error'], 'RuntimeException'));
check('last_error בלי תוכן (הודעת החריגה לא נשמרת)', !str_contains((string)$job()['last_error'], 'ישראל'));
check('וגם היומן הטכני בלי התוכן', !str_contains(json_encode(devloop_log_rows(['q' => 'test.flaky', 'limit' => 20]), JSON_UNESCAPED_UNICODE), 'ישראלי'));
eq($health()['queue']['state'], 'bad', 'משימה שנכשלה — כשל בבדיקת התור');

// מסך שירותי הרקע
[$st, $jv] = call('GET', 'dev-jobs', [], $owner);
check('המסך: המתזמן, התור, השירותים והכתובת להעתקה', $st === 200 && $jv['cron']['last_ok_at'] !== null && ((array)$jv['counts'])['failed'] === 1
    && in_array('maintenance.cleanup', array_column($jv['kinds'], 'kind'), true) && str_ends_with($jv['cron']['url'], '/cron.php?token=' . apr_cron_token()), $jv['cron']);
check('המזהה הפנימי של משימה לא יוצא', !array_key_exists('id', $jv['jobs'][0]) && apr_is_pid($jv['jobs'][0]['pid']));
eq(code(call('POST', 'dev-job-retry', ['job' => apr_pid()], $owner)), 'not_found', 'משימה שלא קיימת');
eq(call('POST', 'dev-job-retry', ['job' => $pid], $owner)[0], 200, '"ניסיון חוזר" למשימה שנכשלה');
eq([$job()['status'], (int)$job()['attempts']], ['pending', 0], 'חזרה לתור, מאפס');
eq(code(call('POST', 'dev-job-retry', ['job' => $pid], $owner)), 'job_state', 'ניסיון חוזר למשימה שכבר בתור — נדחה');
eq(call('POST', 'dev-job-cancel', ['job' => $pid], $owner)[0], 200, '"ביטול"');
eq($job()['status'], 'cancelled', 'בוטלה');
eq($health()['queue']['state'], 'ok', 'ובדיקת התור חזרה לתקין');
[$st, $run] = call('POST', 'dev-cron-run', [], $owner);
check('"הרצה עכשיו" מהמסך', $st === 200 && array_key_exists('ran', $run), $run);

// מקרה 15: משימה שנהרגה באמצע — running מעל 10 דקות חוזרת לתור, ונספרת כניסיון
unset($GLOBALS['apr_time']);
apr_job_register('test.ok', 'משימה תקינה', fn() => 'partial');
$stuck = apr_job_add('test.ok');
apr_exec("UPDATE jobs SET status = 'running', attempts = 1, started_at = ? WHERE pid = ?", [apr_iso(time() - 700), $stuck]);
eq($health()['queue']['state'], 'bad', 'מקרה 15: running תקועה — כשל בבדיקת התור');
apr_jobs_run(2.0);
$s = apr_row('SELECT * FROM jobs WHERE pid = ?', [$stuck]);
eq([$s['status'], (int)$s['attempts']], ['partial', 2], 'מקרה 15: חזרה לתור, רצה, והניסיון הקודם נספר');

// סוג משימה שלא רשום — נכשל בלי להפיל את הסבב
$orphan = apr_job_add('gone.kind', [], null, null, 0, 1);
$r = apr_jobs_run(2.0);
eq([apr_val('SELECT status FROM jobs WHERE pid = ?', [$orphan]), $r['failed']], ['failed', 1], 'סוג לא רשום → failed, והסבב ממשיך');

done();
