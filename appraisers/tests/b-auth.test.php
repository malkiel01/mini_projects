<?php
// התחברות — 01-B1 … 01-B9, ומקרי הקצה 1–7.
require __DIR__ . '/lib.php';

$dana = ident('17', 'דנה לוי', 'Dana@Example.com');
$users = fn() => (int)apr_val('SELECT COUNT(*) FROM users');
$sessions = fn() => (int)apr_val('SELECT COUNT(*) FROM sessions');

// ── 01-B2: בלי זהות, או granted:false → 401 לכל פעולה לא-ציבורית ──
foreach (array_keys(APR_ACCESS) as $a) {
    $rule = APR_ACCESS[$a];
    if ($rule === 'public' || $rule === 'token') continue;
    $m = in_array($a, APR_READS, true) ? 'GET' : 'POST';
    eq(call($m, $a)[0], 401, "01-B2 בלי זהות: $a → 401");
    eq(call($m, $a, [], ident('18', 'בלי הרשאה', 'n@x.co', ['granted' => false]))[0], 401, "01-B2 granted:false: $a → 401");
}
eq(code(call('GET', 'me', [], '{"sub":"abc","name":"x","granted":true}')), 'unauthenticated', '01-B2 sub שאינו ספרות');
eq(code(call('GET', 'me', [], '{"sub":"5","name":"x"}')), 'unauthenticated', '01-B2 בלי granted בכלל — לא סומכים על השער');
eq([$users(), $sessions()], [0, 0], '01-B2 לא נפתח חשבון ולא session למי שלא נכנס');
eq(call('GET', 'health')[1], ['ok' => true, 'version' => apr_app_version()], 'בקשה ציבורית בלי זהות עובדת, ולא יוצרת session');
eq($sessions(), 0, 'בקשה ציבורית לא יצרה session');

// ── 01-B1: זהות חדשה → חשבון פעיל; בקשה שנייה לא יוצרת עוד אחד ──
[$st, $me] = call('GET', 'me', [], $dana);
eq($st, 200, '01-B1 כניסה ראשונה');
eq([$me['user']['name'], $me['user']['email'], $me['user']['is_developer'], $me['user']['is_sysadmin']], ['דנה לוי', 'dana@example.com', false, false], '01-B1 החשבון נפתח מהזהות');
check('01-B1 מזהה ציבורי, ולא המספר הפנימי', apr_is_pid($me['user']['pid']) && !array_key_exists('id', $me['user']));
eq(apr_val("SELECT status FROM users WHERE platform_sub = '17'"), 'active', '01-B1 פעיל מיד, בלי אישור נוסף');
[, $me2] = call('GET', 'me', [], $dana);
eq([$users(), $sessions(), $me2['csrf'], $me2['user']['pid']], [1, 1, $me['csrf'], $me['user']['pid']], '01-B1 בקשה שנייה — אותו חשבון, אותו session');
eq(apr_val("SELECT action FROM audit_log WHERE entity_pid = ?", [$me['user']['pid']]), 'user.create', 'פתיחת החשבון ביומן הפעולות');
check('במסד רק hash של אסימון העוגייה', apr_val('SELECT token_hash FROM sessions') === hash('sha256', jar()[$dana]) && !apr_val('SELECT 1 FROM sessions WHERE token_hash = ?', [jar()[$dana]]));

// ── 01-B3: platform_admin → מנהל המערכת והמפתח ──
$owner = ident('1', 'הבעלים', 'owner@example.com', ['platform_admin' => true]);
[, $meO] = call('GET', 'me', [], $owner);
eq([$meO['user']['is_sysadmin'], $meO['user']['is_developer']], [true, true], '01-B3 platform_admin → is_sysadmin ו-is_developer');
check('01-B3 נרשם ביומן הפעולות', (bool)apr_val("SELECT 1 FROM audit_log WHERE action = 'user.grant_sysadmin' AND entity_pid = ?", [$meO['user']['pid']]));
call('GET', 'me', [], $owner);
eq((int)apr_val("SELECT COUNT(*) FROM audit_log WHERE action = 'user.grant_sysadmin'"), 1, '01-B3 ורק פעם אחת');
// is_developer שווה תמיד ל-platform_admin שבזהות — לשני הכיוונים; is_sysadmin נשאר
[, $meO2] = call('GET', 'me', [], ident('1', 'הבעלים', 'owner@example.com'));
eq([$meO2['user']['is_developer'], $meO2['user']['is_sysadmin']], [false, true], '01-B3 נכנס עם platform_admin:false ← is_developer כבוי, is_sysadmin נשאר');
eq(call('GET', 'dev-health', [], ident('1', 'הבעלים', 'owner@example.com'))[0], 404, '01-B3 ומסך המפתח נסגר לו מיד');
eq(apr_val("SELECT actor_kind FROM audit_log WHERE action = 'user.developer_off'"), 'system', '01-B3 user.developer_off, המבצע: המערכת');
call('POST', 'sys-user-flags', ['user' => $me['user']['pid'], 'is_developer' => true], $owner);
eq([call('GET', 'me', [], $dana)[1]['user']['is_developer'], call('GET', 'me', [], $owner)[1]['user']['is_developer']], [false, true], '01-B3 אין פעולת API שמדליקה is_developer; הבעלים חוזר להיות מפתח בכניסה');

// ── 01-B4: איחוד לפי מייל מאומת ──
$now = apr_now();
$pre = apr_insert('users', ['pid' => apr_pid(), 'email' => 'noa@example.com', 'name' => 'נועה (ישן)', 'created_at' => $now, 'updated_at' => $now]);
[, $meN] = call('GET', 'me', [], ident('30', 'נועה שמאי', 'NOA@example.com'));
eq([$users(), apr_val('SELECT platform_sub FROM users WHERE id = ?', [$pre]), $meN['user']['name']], [3, '30', 'נועה שמאי'], '01-B4 מייל מאומת זהה — מתקשר ולא נכפל');
check('01-B4 הקישור ביומן הפעולות', (bool)apr_val("SELECT 1 FROM audit_log WHERE action = 'user.link' AND entity_pid = ?", [$meN['user']['pid']]));
$pre2 = apr_insert('users', ['pid' => apr_pid(), 'email' => 'gil@example.com', 'name' => 'גיל (ישן)', 'created_at' => $now, 'updated_at' => $now]);
[$st, $meG] = call('GET', 'me', [], ident('31', 'מתחזה', 'gil@example.com', ['email_verified' => false]));
eq([$st, apr_val('SELECT platform_sub FROM users WHERE id = ?', [$pre2]), $users()], [200, null, 5], '01-B4 מייל לא מאומת — חשבון חדש, והישן לא קושר');
eq($meG['user']['email'], null, 'מקרה 5: החשבון החדש בלי המייל (הוא שייך לחשבון אחר)');

// ── ביקורת 01-align 🔴1: הזהות היא מקור האמת למייל, בכל כניסה ──
$tal = fn(?string $email, bool $v = true) => ident('50', 'טל', $email, ['email_verified' => $v]);
$mail = fn(string $sub) => apr_row('SELECT email, email_verified FROM users WHERE platform_sub = ?', [$sub]);
call('GET', 'me', [], $tal('tal@example.com'));
eq($mail('50'), ['email' => 'tal@example.com', 'email_verified' => 1], 'מייל מאומת נשמר');
[, $t2] = call('GET', 'me', [], $tal(null));
eq([$mail('50'), $t2['user']['email'], $t2['user']['email_verified']], [['email' => null, 'email_verified' => 0], null, false], '🔴1 הזהות חזרה עם email:null (הוחלף וטרם אומת) ← המייל והאימות נמחקים');
call('GET', 'me', [], $tal('tal@example.com'));
call('GET', 'me', [], $tal('tal@example.com', false));
eq($mail('50'), ['email' => 'tal@example.com', 'email_verified' => 0], '🔴1 אותו מייל, כבר לא מאומת ← האימות יורד');
call('GET', 'me', [], $tal('tal@example.com'));
call('GET', 'me', [], $tal('dana@example.com'));   // הכתובת מאומתת אצל דנה
eq($mail('50'), ['email' => null, 'email_verified' => 0], '🔴1 הזהות עברה למייל שמאומת אצל חשבון אחר ← בלי מייל (לא נשאר הישן "המאומת")');
eq($mail('17'), ['email' => 'dana@example.com', 'email_verified' => 1], 'ובעלת הכתובת לא נפגעה');

// ── 🟡2: מייל לא מאומת אינו חוסם את בעל הכתובת ──
call('GET', 'me', [], ident('51', 'תופס', 'real@example.com', ['email_verified' => false]));
eq($mail('51'), ['email' => 'real@example.com', 'email_verified' => 0], 'מייל לא מאומת נשמר כל עוד הכתובת פנויה');
[, $real] = call('GET', 'me', [], ident('52', 'בעל הכתובת', 'real@example.com'));
eq([$real['user']['email'], $real['user']['email_verified'], $mail('51')], ['real@example.com', true, ['email' => null, 'email_verified' => 0]], '🟡2 זהות מאומתת לוקחת את הכתובת; מי שלא אימת נשאר בלי מייל');
call('GET', 'me', [], ident('51', 'תופס', 'real@example.com', ['email_verified' => false]));
eq([$mail('51')['email'], $mail('52')['email']], [null, 'real@example.com'], '🟡2 והוא לא מקבל אותה בחזרה בכניסה הבאה');
[, $meD2] = call('GET', 'me', [], ident('99', 'דנה אחרת', 'dana@example.com'));
check('חשבון שכבר מקושר לא מתקשר ל-sub נוסף', $meD2['user']['pid'] !== $me['user']['pid']);

// ── 01-B5: CSRF ──
$before = (int)apr_val('SELECT COUNT(*) FROM offices');
eq(code(call('POST', 'office-create', ['name' => 'x', 'role' => 'staff'], $dana, [], ['csrf' => ''])), 'csrf', '01-B5 POST בלי X-CSRF → 403');
eq(call('POST', 'office-create', ['name' => 'x', 'role' => 'staff'], $dana, [], ['csrf' => $meO['csrf']])[0], 403, '01-B5 POST עם CSRF של משתמש אחר → 403');
eq((int)apr_val('SELECT COUNT(*) FROM offices'), $before, '01-B5 ושום דבר לא השתנה');
eq(call('GET', 'office-create', [], $dana)[0], 405, 'פעולה משנה ב-GET → 405');
eq(call('POST', 'me', [], $dana)[0], 405, 'פעולת קריאה ב-POST → 405');
eq(code(call('GET', 'no-such', [], $dana)), 'not_found', 'פעולה לא מוכרת → 404');

// ── 01-B6: session של sub אחר מוחלף; המשרד הפעיל של הקודם לא עובר ──
$A = office_for($dana, 'משרד של דנה');
eq(call('GET', 'me', [], $dana)[1]['office'], $A, 'לדנה יש משרד פעיל');
$yossi = ident('20', 'יוסי', 'yossi@example.com');
$danaCookie = jar()[$dana];
$n = $sessions();
[, $meY] = call('GET', 'me', [], $yossi, [], ['cookie' => $danaCookie]);
eq([$meY['user']['name'], $meY['office'], $meY['offices']], ['יוסי', null, []], '01-B6 המשרד הפעיל של הקודם לא עבר, ושום נתון שלו לא מוצג');
check('01-B6 CSRF חדש', $meY['csrf'] !== $me['csrf']);
check('01-B6 ה-session הישן נמחק', !apr_val('SELECT 1 FROM sessions WHERE token_hash = ?', [hash('sha256', $danaCookie)]) && $sessions() === $n);
eq(call('GET', 'me', [], ident('21', 'רון', null), [], ['cookie' => "' OR 1=1 --"])[0], 200, 'עוגייה משובשת לא מפילה');

// מקרה 1: אותו אדם, שני דפדפנים — שני sessions תקפים
[, $d1] = call('GET', 'me', [], $dana, [], ['jar' => 'dana-phone']);
[, $d2] = call('GET', 'me', [], $dana, [], ['jar' => 'dana-laptop']);
check('מקרה 1: שני sessions, שניהם תקפים', $d1['csrf'] !== $d2['csrf'] && $d1['user']['pid'] === $d2['user']['pid']);
eq([$d1['office'], $d2['office']], [$A, $A], 'כניסה במכשיר חדש: המשרד הפעיל האחרון');

// ── 01-B7: לא זמינה → 503 לכל פעולה לא-ציבורית; מנותקת (מאז פרוסה 02) → 401 login_required בלי session ──
foreach (['detached' => [401, 'login_required'], 'unavailable' => [503, 'login_unavailable']] as $mode => $want) {
    putenv("APR_PLATFORM=$mode");
    eq([call('GET', 'me')[0], code(call('GET', 'me'))], $want, "01-B7 $mode: me → {$want[0]}");
    eq(call('POST', 'office-create', ['name' => 'x'])[0], $want[0], "01-B7 $mode: פעולה משנה → {$want[0]}");
    eq(call('GET', 'dev-health')[0], $want[0], "01-B7 $mode: מסך המפתח → {$want[0]}");
    eq(call('GET', 'health')[0], 200, "01-B7 $mode: הציבורית עדיין עונה");
}
putenv('APR_PLATFORM');
// בשרת (לא CLI): המצב נקבע לפי מה שהמעטפת עונה, ונשמר במטמון
$GLOBALS['apr_http'] = fn() => [200, '{"connected":false}'];
apr_meta_set('platform_config_cache', '');
eq(apr_platform_mode(null, 'litespeed'), 'detached', '01-B7 המעטפת אומרת "מנותקת"');
$GLOBALS['apr_http'] = fn() => null;
eq(apr_platform_mode(null, 'litespeed'), 'detached', 'התשובה במטמון 60 שניות');
$GLOBALS['apr_time'] = time() + 61;
eq(apr_platform_mode(null, 'litespeed'), 'unavailable', '01-B7 המעטפת לא עונה → לא זמינה');
$GLOBALS['apr_http'] = fn() => [200, '{"connected":true}'];
eq(apr_platform_mode(null, 'litespeed'), 'unavailable', 'כישלון במטמון 15 שניות');
$GLOBALS['apr_time'] = time() + 61 + 16;
eq(apr_platform_mode(null, 'litespeed'), 'connected', 'ואחריהן שואלים שוב');
unset($GLOBALS['apr_time']);
eq(apr_platform_mode($dana, 'litespeed'), 'connected', 'יש זהות תקינה → מחוברת, בלי לשאול אף אחד');
// ביקורת 02 סבב 1, 🔴1: שום כתובת לא נגזרת מהבקשה — SERVER_NAME / HTTP_HOST / SCRIPT_NAME זרים לא משנים אף אחת
$urls = fn() => [apr_app_url('x'), apr_app_path(), apr_app_origin(), apr_platform_base(), apr_platform_url('action=config'), apr_self_url('cron.php'), apr_sso_return()];
$u0 = $urls();
$_SERVER['SERVER_NAME'] = 'evil.example'; $_SERVER['HTTP_HOST'] = 'evil.example:8443'; $_SERVER['SCRIPT_NAME'] = '/evil/app/api.php'; $_SERVER['HTTP_X_FORWARDED_HOST'] = 'evil.example';
eq($urls(), $u0, '🔴1 Host זר לא משנה אף כתובת');
eq($u0, ['https://example.test/mini_projects/appraisers/x', '/mini_projects/appraisers/', 'https://example.test', 'https://example.test/mini_projects',
    'https://example.test/mini_projects/platform/sso.php?action=config', 'https://example.test/mini_projects/appraisers/cron.php',
    'https://example.test/mini_projects/appraisers/api.php?action=sso-callback'], '🔴1 כל הכתובות נגזרות מהכתובת הקבועה של האפליקציה');
// בשרת (בלי APR_DEV): הכתובת הקבועה נקראת מ-app.json — נבדק בתהליך נפרד, עם Host זר
$probe = 'define("APR_DATA_DIR", sys_get_temp_dir() . "/apr-probe"); define("APR_DB_PATH", ":memory:"); $_SERVER["SERVER_NAME"] = "evil.example"; $_SERVER["HTTP_HOST"] = "evil.example";'
    . ' $_SERVER["SCRIPT_NAME"] = "/evil/api.php"; require ' . var_export(dirname(__DIR__) . '/lib/login.php', true) . '; echo json_encode([apr_app_url(), apr_platform_url("action=key"), apr_sso_return()]);';
eq(json_decode((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($probe)), true), ['https://mbe-plus.com/mini_projects/appraisers/',
    'https://mbe-plus.com/mini_projects/platform/sso.php?action=key', 'https://mbe-plus.com/mini_projects/appraisers/api.php?action=sso-callback'], '🔴1 בשרת: הכתובות מ-app.json, גם כשה-Host של הבקשה זר');
$app = json_decode(file_get_contents(dirname(__DIR__) . '/app.json'), true);
eq([$app['sso']['return'], $app['provision']['key_url']], [['https://mbe-plus.com/mini_projects/appraisers/api.php?action=sso-callback'], 'https://mbe-plus.com/mini_projects/appraisers/api.php?action=platform_key'], 'app.json: כתובת החזרה וכתובת המפתח תחת אותה כתובת קבועה');

// ── 01-B8: APR_TEST_IDENTITY נקרא רק ב-CLI ──
putenv('APR_TEST_IDENTITY=' . $dana);
eq(apr_platform_raw([], 'cli'), $dana, '01-B8 ב-CLI — מוזרקת');
eq(apr_platform_raw([], 'litespeed'), null, '01-B8 בשרת — מתעלמים');
eq(apr_platform_raw([], 'cli-server'), null, '01-B8 גם בשרת הפיתוח');
eq(apr_platform_raw(['PLATFORM_USER' => $yossi], 'litespeed'), $yossi, 'הזהות מהשער גוברת');
putenv('APR_TEST_IDENTITY');
putenv('APR_PLATFORM=detached');
$GLOBALS['apr_http'] = fn() => [200, '{"connected":true}'];
apr_meta_set('platform_config_cache', '');
eq(apr_platform_mode(null, 'litespeed'), 'connected', '01-B8 APR_PLATFORM מתעלמים ממנו כשה-SAPI אינו CLI');
putenv('APR_PLATFORM');

// ── 01-B9: חשבון מושעה → 403 לכל פעולה, גם כשהוא מפתח ──
apr_exec("UPDATE users SET status = 'suspended', is_developer = 1, is_sysadmin = 1 WHERE platform_sub = '20'");
foreach (['me' => 'GET', 'office' => 'GET', 'dev-health' => 'GET', 'dev-items' => 'GET', 'sys-offices' => 'GET', 'office-create' => 'POST', 'devloop-client-error' => 'POST'] as $a => $m) {
    eq([call($m, $a, [], $yossi)[0], code(call($m, $a, [], $yossi))], [403, apr_is_devloop($a) ? 'devloop' : 'account_suspended'], "01-B9 מושעה (גם מפתח): $a → 403");
}

// ── מקרה 4: המייל והשם השתנו במעטפת — אותו חשבון ──
[, $me3] = call('GET', 'me', [], ident('17', 'דנה לוי-כהן', 'dana.new@example.com'));
eq([$me3['user']['pid'], $me3['user']['name'], $me3['user']['email']], [$me['user']['pid'], 'דנה לוי-כהן', 'dana.new@example.com'], 'מקרה 4: שם ומייל מתעדכנים, החשבון נשאר');

// ── מקרה 6: מייל null ──
[$st, $meR] = call('GET', 'me', [], ident('21', 'רון', null));
eq([$st, $meR['user']['email'], $meR['user']['email_verified']], [200, null, false], 'מקרה 6: חשבון בלי מייל');
eq(call('GET', 'my-invites', [], ident('21', 'רון', null))[1]['verified'], false, 'מקרה 6: והמסך יודע שאי אפשר להזמין אותו');

// ── מקרה 7: שתי בקשות ראשונות במקביל — לא נוצרים שני חשבונות ──
$id = apr_platform_identity(ident('40', 'מקביל', 'par@example.com'));
$a = apr_user_for($id);
$b = apr_user_for($id);
eq([$a['id'], (int)apr_val("SELECT COUNT(*) FROM users WHERE platform_sub = '40'")], [$b['id'], 1], 'מקרה 7: אותו חשבון');
try { apr_insert('users', ['pid' => apr_pid(), 'platform_sub' => '40', 'created_at' => $now, 'updated_at' => $now]); check('מקרה 7: platform_sub ייחודי במסד', false); }
catch (PDOException) { check('מקרה 7: platform_sub ייחודי במסד', true); }

// ── session שפג: 30 יום בלי שימוש ──
[, $x1] = call('GET', 'me', [], $dana);
$GLOBALS['apr_time'] = time() + APR_SESSION_TTL + 10;
[, $x2] = call('GET', 'me', [], $dana);
check('session שפג (30 יום) — נפתח חדש', $x2['csrf'] !== $x1['csrf']);
unset($GLOBALS['apr_time']);

// ── פורמט השגיאה ──
[$st, $err] = call('GET', 'me');
eq([array_keys($err), array_keys($err['error'])], [['ok', 'error', 'req'], ['code', 'message']], 'שגיאה: {ok:false, error:{code, message}, req}');

done();
