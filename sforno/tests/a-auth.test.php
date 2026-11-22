<?php
/** מסד, כניסת מנהלים, CSRF, הרשאות, ניהול מנהלים, יומן פעולות, שגיאות ו-health — קריטריונים 1–12. */

declare(strict_types=1);

require __DIR__ . '/lib.php';

// ── K1: מסד ריק → כל הטבלאות; בקשה שנייה לא משנה דבר ─────────
check('K1 אין מסד לפני הבקשה הראשונה', !is_file(SF_DB_PATH));
[$st, $h] = call('GET', 'health');
eq($st, 200, 'K1 health עונה');
$tables = fn() => array_column(sf_rows("SELECT name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY name"), 'sql', 'name');
$t1 = $tables();
foreach (['meta', 'staff', 'staff_sessions', 'audit_log', 'jobs'] as $t) check("K1 הטבלה $t נוצרה", isset($t1[$t]));
eq(sf_schema_version(sf_db()), sf_schema_expected(), 'K1 schema_version = המיגרציה האחרונה');
check('K1 יש לפחות מיגרציה אחת', sf_schema_expected() >= 1);
$metaBefore = sf_rows("SELECT k, v FROM meta WHERE k = 'schema_version'");
unset($GLOBALS['sf_pdo']);
call('GET', 'health');
eq($tables(), $t1, 'K1 בקשה שנייה — אותה סכימה');
eq(sf_rows("SELECT k, v FROM meta WHERE k = 'schema_version'"), $metaBefore, 'K1 בקשה שנייה — אותה גרסה');
sf_migrate(sf_db());
eq($tables(), $t1, 'K1 מיגרציה חוזרת לא עושה דבר');
eq((string)sf_db()->query('PRAGMA journal_mode')->fetchColumn(), 'wal', 'מסד ב-WAL');
eq((int)sf_db()->query('PRAGMA foreign_keys')->fetchColumn(), 1, 'מפתחות זרים דלוקים');

// ── K12: health בלי משתמש — רק ok ו-version ───────────────────
eq(array_keys($h), ['ok', 'version'], 'K12 health מחזיר רק ok ו-version');
eq($h['ok'], true, 'K12 ok = true');
eq($h['version'], sf_version(), 'K12 version מ-app.json');

// ── K2: זהות חדשה → מנהל פעיל; platform_admin → מפתח ──────────
$dev = ident('101', 'מלכיאל', 'dev@example.test', ['platform_admin' => true]);
[$st, $me] = call('GET', 'me', [], $dev);
eq($st, 200, 'K2 me למפתח חדש');
$s = staff_by_sub('101');
check('K2 נפתח staff', $s !== null);
eq($s['status'] ?? null, 'active', 'K2 פעיל מיד');
eq((int)($s['is_developer'] ?? 0), 1, 'K2 platform_admin → is_developer');
eq($me['staff']['is_developer'] ?? null, true, 'K2 me מחזיר is_developer');
check('K2 me מחזיר csrf', preg_match('/^[a-f0-9]{64}$/', (string)($me['csrf'] ?? '')) === 1);
check('K2 me בלי id פנימי', !isset($me['staff']['id']) && sf_is_pid($me['staff']['pid'] ?? null));
eq(audit_count('staff.create'), 1, 'K2 נרשם staff.create');
eq(audit_count('staff.developer_on'), 1, 'K2 נרשם staff.developer_on');
$a = ident('102', 'עוזר', 'a@example.test');
call('GET', 'me', [], $a);
eq((int)staff_by_sub('102')['is_developer'], 0, 'K2 בלי platform_admin — לא מפתח');
$sessions = (int)sf_val('SELECT COUNT(*) FROM staff_sessions');
call('GET', 'me', [], $a);
eq((int)sf_val('SELECT COUNT(*) FROM staff_sessions'), $sessions, 'session קיים משמש שוב (בלי session חדש בכל בקשה)');

// ── K3: granted false / חסר → 401, בלי חשבון ──────────────────
$n = (int)sf_val('SELECT COUNT(*) FROM staff');
$r = call('GET', 'me', [], ident('103', 'זר', null, ['granted' => false]));
eq([$r[0], code($r)], [401, 'login_required'], 'K3 granted: false → 401');
$noGrant = json_decode(ident('104'), true);
unset($noGrant['granted']);
$r = call('GET', 'me', [], json_encode($noGrant));
eq($r[0], 401, 'K3 granted חסר → 401');
$r = call('GET', 'me', [], json_encode(['sub' => '105', 'granted' => 'true']));
eq($r[0], 401, 'K3 granted כמחרוזת → 401');
eq((int)sf_val('SELECT COUNT(*) FROM staff'), $n, 'K3 לא נפתח חשבון');
$r = call('GET', 'me', [], '{"sub":"../1","granted":true}');
eq($r[0], 401, 'זהות לא תקינה → 401');

// ── K4: קישור לפי מייל מאומת ─────────────────────────────────
$now = sf_now();
sf_insert('staff', ['pid' => sf_pid(), 'platform_sub' => null, 'email' => 'old@example.test', 'email_verified' => 0, 'name' => 'ותיק',
    'is_developer' => 0, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
sf_insert('staff', ['pid' => sf_pid(), 'platform_sub' => null, 'email' => 'unv@example.test', 'email_verified' => 0, 'name' => 'לא מאומת',
    'is_developer' => 0, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
$n = (int)sf_val('SELECT COUNT(*) FROM staff');
call('GET', 'me', [], ident('201', 'ותיק', 'OLD@example.test'));
eq((int)sf_val('SELECT COUNT(*) FROM staff'), $n, 'K4 מייל מאומת → לא נפתח שני');
eq(sf_val("SELECT platform_sub FROM staff WHERE email = 'old@example.test'"), '201', 'K4 החשבון הקיים קושר ל-sub');
eq(audit_count('staff.link'), 1, 'K4 נרשם staff.link');
call('GET', 'me', [], ident('202', 'לא מאומת', 'unv@example.test', ['email_verified' => false]));
check('K4 מייל לא מאומת → לא מתקשר', sf_val("SELECT platform_sub FROM staff WHERE email = 'unv@example.test'") === null);
check('K4 מייל לא מאומת → חשבון נפרד', staff_by_sub('202') !== null && staff_by_sub('202')['email'] === null);

// ── K5: platform_admin כבה → is_developer כבה ─────────────────
call('GET', 'me', [], ident('101', 'מלכיאל', 'dev@example.test', ['platform_admin' => false]));
eq((int)staff_by_sub('101')['is_developer'], 0, 'K5 is_developer כבה בבקשה הבאה');
eq(audit_count('staff.developer_off'), 1, 'K5 נרשם staff.developer_off');
eq(sf_val("SELECT actor_kind FROM audit_log WHERE action = 'staff.developer_off'"), 'system', 'K5 המבצע: המערכת');
call('GET', 'me', [], $dev);
eq((int)staff_by_sub('101')['is_developer'], 1, 'K5 וחוזר כשחוזר');
call('GET', 'me', [], ident('101', 'מלכיאל רבינוביץ', 'dev@example.test', ['platform_admin' => true]));
eq(staff_by_sub('101')['name'], 'מלכיאל רבינוביץ', 'שם מתעדכן מהזהות');

// ── K6: session של sub אחר מוחלף; בלי PLATFORM_USER — נמחק ───
call('GET', 'me', [], $a, [], ['jar' => 'browser']);
$cookieA = jar()['browser'];
$sidA = sf_val('SELECT id FROM staff_sessions WHERE token_hash = ?', [hash('sha256', $cookieA)]);
check('K6 העוגייה של א נשמרה', $sidA !== null);
call('GET', 'me', [], $dev, [], ['jar' => 'browser']);
check('K6 session של sub אחר נמחק', sf_val('SELECT id FROM staff_sessions WHERE id = ?', [$sidA]) === null);
check('K6 ונפתח חדש', jar()['browser'] !== $cookieA && preg_match('/^[a-f0-9]{64}$/', jar()['browser']) === 1);
$cookieB = jar()['browser'];
$r = call('GET', 'me', [], null, [], ['jar' => 'browser']);
eq($r[0], 401, 'K6 בלי PLATFORM_USER → 401');
check('K6 ה-session נמחק', sf_val('SELECT 1 FROM staff_sessions WHERE token_hash = ?', [hash('sha256', $cookieB)]) === null);
eq(jar()['browser'], '', 'K6 והעוגייה נמחקת');
// בלי זהות ומעטפת מנותקת / לא עונה → 503 (נכשל סגור)
$GLOBALS['t_sso'] = [200, '{"connected":false}'];
sf_meta_set('platform_config_cache', '');
eq(code(call('GET', 'me')), 'login_unavailable', 'מעטפת מנותקת → 503');
$GLOBALS['t_sso'] = [500, ''];
sf_meta_set('platform_config_cache', '');
eq(call('GET', 'me')[0], 503, 'מעטפת לא עונה → 503');
$GLOBALS['t_sso'] = [200, '{"connected":true}'];
sf_meta_set('platform_config_cache', '');
check('כתובת המעטפת נגזרת מהתיקייה', in_array('https://example.test/apps/platform/sso.php?action=config&client=sforno', $GLOBALS['t_http_log'], true));
putenv('SF_PLATFORM=detached');
eq(call('GET', 'me')[0], 503, 'SF_PLATFORM=detached (CLI) → 503');
putenv('SF_PLATFORM');
putenv('SF_TEST_IDENTITY=' . $a);
eq(sf_platform_identity(sf_platform_raw([]))['sub'] ?? null, '102', 'SF_TEST_IDENTITY (CLI) מזריק זהות');
putenv('SF_TEST_IDENTITY');
eq(sf_platform_raw([], 'litespeed'), null, 'SF_TEST_IDENTITY לא עובד מחוץ ל-CLI');

// ── K7: CSRF ו-405 ────────────────────────────────────────────
$aPid = staff_by_sub('102')['pid'];
$devPid = staff_by_sub('101')['pid'];
$r = call('POST', 'staff-suspend', ['staff' => $aPid], $dev, [], ['csrf' => '']);
eq([$r[0], code($r)], [403, 'csrf'], 'K7 POST בלי X-CSRF → 403');
$r = call('POST', 'staff-suspend', ['staff' => $aPid], $dev, [], ['csrf' => str_repeat('a', 64)]);
eq([$r[0], code($r)], [403, 'csrf'], 'K7 X-CSRF שגוי → 403');
$r = call('POST', 'staff-suspend', ['staff' => $aPid], $a, [], ['jar' => 'other-a', 'csrf' => call('GET', 'me', [], $dev)[1]['csrf']]);
eq($r[0], 403, 'K7 CSRF של מנהל אחר → 403');
$r = call('GET', 'staff-suspend', [], $dev, ['staff' => $aPid]);
eq([$r[0], code($r)], [405, 'method'], 'K7 GET לפעולה משנה → 405');
eq(staff_by_sub('102')['status'], 'active', 'K7 שום דבר לא השתנה');
$r = call('POST', 'staff-suspend', ['staff' => $aPid], $dev, [], ['content_type' => 'text/plain']);
eq($r[0], 415, 'POST שאינו JSON → 415');
eq(call('GET', 'no-such-thing', [], $dev)[0], 404, 'פעולה לא מוכרת → 404');

// ── K8: מנהל שאינו מפתח → 403 ל-dev-* ────────────────────────
foreach (['dev-items', 'dev-jobs', 'dev-health', 'dev-audit', 'dev-log', 'dev-tokens'] as $act) {
    $r = call('GET', $act, [], $a);
    eq([$r[0], code($r)], [403, 'forbidden'], "K8 $act למנהל שאינו מפתח → 403");
}
foreach (['dev-job-retry', 'dev-cron-rotate', 'dev-token-create', 'dev-task-create'] as $act) {
    eq(call('POST', $act, [], $a)[0], 403, "K8 $act למנהל שאינו מפתח → 403");
}
eq(call('GET', 'dev-items', [], $dev)[0], 200, 'K8 למפתח — dev-items עובד');
eq(call('GET', 'dev-jobs', [], $dev)[0], 200, 'K8 למפתח — dev-jobs עובד');
eq(call('GET', 'staff-list', [], $a)[0], 200, 'מנהל רגיל רואה את רשימת המנהלים');

// ── K9: השעיה ─────────────────────────────────────────────────
$r = call('POST', 'staff-suspend', ['staff' => $devPid], $dev);
eq([$r[0], code($r)], [409, 'self_suspend'], 'K9 מנהל לא משעה את עצמו');
$r = call('POST', 'staff-suspend', ['staff' => $aPid], $dev);
eq($r[0], 200, 'K9 השעיה של מנהל אחר');
eq($r[1]['staff']['status'] ?? null, 'suspended', 'K9 המצב בתשובה');
$r = call('GET', 'me', [], $a);
eq([$r[0], code($r)], [403, 'suspended'], 'K9 מנהל מושעה → 403 בבקשה הבאה');
eq((int)sf_val('SELECT COUNT(*) FROM staff_sessions WHERE staff_id = ?', [staff_by_sub('102')['id']]), 0, 'K9 ל-מושעה אין session');
$r = call('POST', 'staff-unsuspend', ['staff' => $aPid], $dev);
eq([$r[0], staff_by_sub('102')['status']], [200, 'active'], 'K9 ביטול השעיה');
eq(call('GET', 'me', [], $a)[0], 200, 'K9 אחרי ביטול — נכנס');
eq(code(call('POST', 'staff-unsuspend', ['staff' => $aPid], $dev)), 'state', 'ביטול השעיה למי שפעיל → state');
eq(code(call('POST', 'staff-suspend', ['staff' => 'nope'], $dev)), 'bad_input', 'pid לא תקין → bad_input');
// המנהל הפעיל האחרון: כולם מושעים חוץ מאחד, והמערכת מנסה להשעות אותו
sf_exec("UPDATE staff SET status = 'suspended' WHERE platform_sub <> '101' OR platform_sub IS NULL");
try {
    sf_staff_set_status($devPid, 'suspended', null);
    check('K9 אי אפשר להשעות את המנהל הפעיל האחרון', false);
} catch (SfError $e) {
    eq($e->errorCode, 'last_admin', 'K9 אי אפשר להשעות את המנהל הפעיל האחרון');
}
eq(staff_by_sub('101')['status'], 'active', 'K9 האחרון נשאר פעיל');
sf_exec("UPDATE staff SET status = 'active'");

// ── K10: יומן באותה טרנזקציה ──────────────────────────────────
$before = audit_count('staff.suspend');
call('POST', 'staff-suspend', ['staff' => $aPid], $dev);
eq(audit_count('staff.suspend'), $before + 1, 'K10 השעיה כותבת שורה ביומן');
$row = sf_row("SELECT * FROM audit_log WHERE action = 'staff.suspend' ORDER BY id DESC LIMIT 1");
eq([$row['entity_type'], $row['entity_pid'], $row['actor_kind'], (int)$row['actor_staff_id']], ['staff', $aPid, 'staff', (int)staff_by_sub('101')['id']], 'K10 השורה: מי, מה ועל מי');
eq(json_decode($row['meta'], true), ['from' => 'active', 'to' => 'suspended'], 'K10 meta — מצב קודם וחדש');
check('K10 מזהה הבקשה נרשם', preg_match('/^[a-f0-9]{10}$/', (string)$row['req']) === 1);
call('POST', 'staff-unsuspend', ['staff' => $aPid], $dev);
eq(audit_count('staff.unsuspend'), 2, 'K10 ביטול השעיה כותב שורה');
// יומן שנכשל מפיל את השינוי
sf_db()->exec('ALTER TABLE audit_log RENAME TO audit_log_off');
$r = call('POST', 'staff-suspend', ['staff' => $aPid], $dev);
sf_db()->exec('ALTER TABLE audit_log_off RENAME TO audit_log');
eq($r[0], 500, 'K10 יומן שנכשל → הפעולה נכשלת');
eq(staff_by_sub('102')['status'], 'active', 'K10 והשינוי בוטל');
// היומן רק מתווסף
foreach (['UPDATE audit_log SET action = ?' => ['x'], 'DELETE FROM audit_log WHERE id > ?' => [0]] as $sql => $args) {
    try { sf_exec($sql, $args); check("audit_log חוסם: $sql", false); } catch (PDOException) { check("audit_log חוסם: $sql", true); }
}
eq(sf_audit_clean(['token' => 'x', 'csrf' => 'y', 'name' => 'z', 'to' => 'a']), ['to' => 'a'], 'meta בלי אסימונים ושמות');

// ── K11: שגיאה בשרת → JSON בעברית בלי trace, ביומן הטכני ─────
check('K11 תשובה 500', $r[0] === 500 && code($r) === 'server');
$msg = (string)($r[1]['error']['message'] ?? '');
check('K11 ההודעה בעברית', preg_match('/\p{Hebrew}/u', $msg) === 1);
check('K11 בלי SQL, נתיב או trace', !preg_match('/audit_log|SQLSTATE|\.php|#\d|INSERT/i', json_encode($r[1], JSON_UNESCAPED_UNICODE)));
check('K11 מזהה הבקשה בהודעה', str_contains($msg, devloop_req_id()));
$log = devloop_log_db()->query("SELECT * FROM log WHERE event = 'exception' ORDER BY id DESC LIMIT 1")->fetch();
check('K11 נרשם ביומן הטכני', is_array($log) && str_contains((string)$log['message'], 'audit_log'));
eq($log['req'] ?? null, devloop_req_id(), 'K11 עם מזהה הבקשה');

// ── יציאה ────────────────────────────────────────────────────
call('GET', 'me', [], $a, [], ['jar' => 'bye']);
$c = jar()['bye'];
eq(call('POST', 'logout', [], $a, [], ['jar' => 'bye'])[0], 200, 'יציאה');
check('יציאה מוחקת את ה-session', sf_val('SELECT 1 FROM staff_sessions WHERE token_hash = ?', [hash('sha256', $c)]) === null && jar()['bye'] === '');

// ── session פג אחרי 30 יום בלי שימוש ──────────────────────────
call('GET', 'me', [], $a, [], ['jar' => 'old']);
$c = jar()['old'];
$GLOBALS['sf_time'] = time() + 31 * 86400;
call('GET', 'me', [], $a, [], ['jar' => 'old']);
check('session שפג מוחלף', jar()['old'] !== $c && sf_val('SELECT 1 FROM staff_sessions WHERE token_hash = ?', [hash('sha256', $c)]) === null);
unset($GLOBALS['sf_time']);

// ── מסך המפתח: יומן פעולות ובדיקות ───────────────────────────
[$st, $au] = call('GET', 'dev-audit', [], $dev, ['action' => 'staff.suspend']);
check('dev-audit מסנן לפי פעולה', $st === 200 && $au['rows'] && !array_filter($au['rows'], fn($x) => $x['action'] !== 'staff.suspend'));
check('dev-audit בלי id פנימי', !array_key_exists('id', $au['rows'][0] ?? ['id' => 1]));
[$st, $au] = call('GET', 'dev-audit', [], $dev, ['actor' => 'system']);
check('dev-audit — המערכת', $st === 200 && $au['rows'] && !array_filter($au['rows'], fn($x) => $x['actor_kind'] === 'staff'));
[$st, $hl] = call('GET', 'dev-health', [], $dev);
eq($st, 200, 'dev-health עונה');
$ids = array_column($hl['checks'] ?? [], 'state', 'id');
foreach (['db', 'schema', 'write', 'protect', 'php', 'ext', 'limits', 'outbound', 'mail', 'disk', 'cron', 'queue', 'version', 'platform', 'devloop', 'standalone'] as $c) {
    check("dev-health: הבדיקה $c קיימת", isset($ids[$c]));
}
foreach (['db', 'schema', 'write', 'protect', 'outbound', 'platform', 'devloop', 'standalone', 'version'] as $c) eq($ids[$c] ?? null, 'ok', "dev-health: $c תקין");
eq($ids['cron'] ?? null, 'warn', 'dev-health: מתזמן שלא רץ → ⚠️');
check('dev-health: §24 מסומן', count(array_filter($hl['checks'], fn($x) => $x['s24'])) === 8);   // 08: push
$mails = [];
$GLOBALS['sf_mail'] = function (string $to) use (&$mails) { $mails[] = $to; return true; };
$r = call('POST', 'dev-mail-test', [], $dev);
eq([$r[0], $mails], [200, ['dev@example.test']], 'מייל ניסיון — רק לכתובת המפתח');

done();
