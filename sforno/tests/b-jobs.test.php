<?php
/** שירותי רקע ו-cron.php — קריטריונים 13–14. */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$dev = ident('301', 'מפתח', 'd@example.test', ['platform_admin' => true]);
call('GET', 'me', [], $dev);

// ── K13: cron.php ─────────────────────────────────────────────
eq(sf_cron_handle(null)[0], 404, 'K13 בלי אסימון → 404');
eq(sf_cron_handle('')[0], 404, 'K13 אסימון ריק → 404');
eq(sf_cron_handle(str_repeat('0', 48))[0], 404, 'K13 אסימון שגוי → 404');
eq(sf_cron_handle(['x'])[0], 404, 'K13 אסימון כמערך → 404');
check('K13 האסימון נוצר בפעם הראשונה ב-data/cron.token', is_file(SF_DATA_DIR . '/cron.token'));
check('K13 עדיין לא רץ', sf_meta('cron_last_ok_at') === null);
$token = sf_cron_token();
[$st, $body] = sf_cron_handle($token);
eq($st, 200, 'K13 אסימון תקין → רץ');
eq($body['ok'] ?? null, true, 'K13 סיכום JSON');
check('K13 cron_last_ok_at מתעדכן', sf_meta('cron_last_ok_at') === sf_now() || sf_meta('cron_last_ok_at') !== null);
eq((int)sf_val("SELECT COUNT(*) FROM jobs WHERE kind = 'maintenance.cleanup' AND status = 'done'"), 1, 'K13 maintenance.cleanup רצה');
// שתי הרצות במקביל — השנייה יוצאת בלי לעבוד
$lock = fopen(SF_DATA_DIR . '/cron.lock', 'c');
flock($lock, LOCK_EX);
sf_job_add('maintenance.cleanup', [], 'parallel-test');
$out = sf_jobs_run(5.0);
eq($out, ['skipped' => 'locked'], 'K13 הרצה שנייה במקביל → skipped');
eq(sf_val("SELECT status FROM jobs WHERE dedupe_key = 'parallel-test'"), 'pending', 'K13 ולא עבדה');
flock($lock, LOCK_UN);
fclose($lock);
eq(sf_jobs_run(5.0)['ran'] ?? null, 1, 'K13 אחרי שהנעילה שוחררה — רצה');
// אסימון חדש
$r = call('POST', 'dev-cron-rotate', [], $dev);
eq($r[0], 200, 'אסימון חדש');
eq(sf_cron_handle($token)[0], 404, 'K13 האסימון הישן מפסיק לעבוד');
eq(sf_cron_handle(sf_cron_token())[0], 200, 'והחדש עובד');
eq(audit_count('cron.token_rotate'), 1, 'נרשם cron.token_rotate');
check('הכתובת המלאה במסך', str_starts_with($r[1]['cron']['url'] ?? '', 'https://example.test/apps/sforno/cron.php?token='));
check('פקודת cPanel מוכנה', str_starts_with($r[1]['cron']['command'] ?? '', 'curl -s "https://example.test/apps/sforno/cron.php?token='));

// ── K14: ניסיונות חוזרים ו-dedupe ────────────────────────────
$calls = 0;
sf_job_register('test.fail', 'נכשלת תמיד', function () use (&$calls) { $calls++; throw new RuntimeException('תוכן סודי שלא ייכנס ליומן'); });
$pid = sf_job_add('test.fail', [], null, 0, 3);
check('משימה נכנסה', sf_is_pid($pid));
check('K14 dedupe_key כפול — לא נכנסת שנייה', sf_job_add('test.fail', [], 'k1') !== null && sf_job_add('test.fail', [], 'k1') === null);
sf_exec("UPDATE jobs SET status = 'cancelled' WHERE dedupe_key = 'k1'");
$job = fn() => sf_row('SELECT * FROM jobs WHERE pid = ?', [$pid]);
$t = time();
$GLOBALS['sf_time'] = $t;
sf_jobs_run(5.0);
eq([(int)$job()['attempts'], $job()['status']], [1, 'pending'], 'K14 ניסיון ראשון נכשל → attempts=1, עדיין pending');
eq($job()['run_after'], sf_iso($t + 60), 'K14 run_after נדחה בדקה');
check('K14 last_error בלי התוכן של החריגה', !str_contains((string)$job()['last_error'], 'סודי') && str_contains((string)$job()['last_error'], 'RuntimeException'));
sf_jobs_run(5.0);
eq((int)$job()['attempts'], 1, 'K14 לפני המועד — לא רצה שוב');
$GLOBALS['sf_time'] = $t + 61;
sf_jobs_run(5.0);
eq([(int)$job()['attempts'], $job()['run_after']], [2, sf_iso($t + 61 + 300)], 'K14 ניסיון שני → נדחה ב-5 דקות');
$GLOBALS['sf_time'] = $t + 61 + 301;
sf_jobs_run(5.0);
eq([(int)$job()['attempts'], $job()['status']], [3, 'failed'], 'K14 אחרי max_attempts → failed');
$GLOBALS['sf_time'] = $t + 100000;
sf_jobs_run(5.0);
eq($calls, 3, 'K14 failed לא רצה עוד');
unset($GLOBALS['sf_time']);
[$st, $view] = call('GET', 'dev-jobs', [], $dev);
eq($view['jobs'][0]['pid'] ?? null, $pid, 'מסך הרקע: הנכשלת בראש');
eq($view['counts']->failed ?? ($view['counts']['failed'] ?? null), 1, 'מסך הרקע: ספירה לפי מצב');
[$st, $hl] = call('GET', 'dev-health', [], $dev);
eq(array_column($hl['checks'], 'state', 'id')['queue'], 'bad', 'בדיקת תור הרקע: יש failed → ❌');
eq(array_column($hl['checks'], 'state', 'id')['cron'], 'ok', 'בדיקת המתזמן: רץ עכשיו → ✅');
// 08 / 11-fix: המתזמן כל דקה — ההנחיה במסך, ו-5 דקות בלי ריצה מוצלחת = ⚠️
eq($r[1]['cron']['every'] ?? null, '* * * * *', 'המתזמן: כל דקה');
sf_meta_set('cron_last_ok_at', sf_iso(time()));
$GLOBALS['sf_time'] = time() + 240;
eq(array_column(call('GET', 'dev-health', [], $dev)[1]['checks'], 'state', 'id')['cron'], 'ok', 'בדיקת המתזמן: 4 דקות → עדיין ✅');
$GLOBALS['sf_time'] = time() + 360;
$cronChk = array_column(call('GET', 'dev-health', [], $dev)[1]['checks'], null, 'id')['cron'];
eq($cronChk['state'], 'warn', 'בדיקת המתזמן: 6 דקות בלי ריצה → ⚠️');
check('בדיקת המתזמן: ההנחיה — כל דקה', str_contains((string)json_encode($cronChk, JSON_UNESCAPED_UNICODE), 'כל דקה'), $cronChk);
unset($GLOBALS['sf_time']);
eq(call('POST', 'dev-job-retry', ['job' => $pid], $dev)[0], 200, 'ניסיון חוזר');
eq([$job()['status'], (int)$job()['attempts']], ['pending', 0], 'ניסיון חוזר → pending מאפס');
eq(call('POST', 'dev-job-cancel', ['job' => $pid], $dev)[0], 200, 'ביטול');
eq($job()['status'], 'cancelled', 'ביטול → cancelled');
eq(code(call('POST', 'dev-job-cancel', ['job' => $pid], $dev)), 'state', 'ביטול כפול → state');
eq([audit_count('job.retry'), audit_count('job.cancel')], [1, 1], 'ניסיון חוזר וביטול נרשמים ביומן');
// משימה שנתקעה ב-running
sf_exec("UPDATE jobs SET status = 'running', attempts = 1, started_at = ? WHERE pid = ?", [sf_iso(time() - 3600), $pid]);
sf_jobs_run(0.0);
eq($job()['status'], 'pending', 'running תקועה מעל 10 דקות חוזרת לתור');
// ניקוי
sf_exec("UPDATE staff_sessions SET expires_at = ?", [sf_iso(time() - 10)]);
sf_exec("UPDATE jobs SET finished_at = ? WHERE status = 'done'", [sf_iso(time() - 31 * 86400)]);
$n = (int)sf_val("SELECT COUNT(*) FROM jobs WHERE status = 'done'");
sf_job_add('maintenance.cleanup', [], 'manual-cleanup');
sf_jobs_run(5.0);
eq((int)sf_val('SELECT COUNT(*) FROM staff_sessions'), 0, 'ניקוי: sessions שפגו נמחקו');
eq((int)sf_val("SELECT COUNT(*) FROM jobs WHERE status = 'done' AND finished_at < ?", [sf_iso(time() - 30 * 86400)]), 0, 'ניקוי: done מעל 30 יום נמחקו');
check('ניקוי היה מה למחוק', $n >= 1);
sf_jobs_schedule();
$n1 = (int)sf_val("SELECT COUNT(*) FROM jobs WHERE kind = 'maintenance.cleanup'");
sf_jobs_schedule();
eq((int)sf_val("SELECT COUNT(*) FROM jobs WHERE kind = 'maintenance.cleanup'"), $n1, 'הניקוי היומי — פעם ביום (dedupe לפי תאריך)');
check('והמפתח הוא התאריך', sf_val('SELECT 1 FROM jobs WHERE dedupe_key = ?', ['maintenance.cleanup:' . gmdate('Y-m-d')]) !== null);

done();
