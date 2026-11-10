<?php
// התחברות לפי platform/docs/auth.md: הזהות רק מהשער, חשבון נפתח מיד, איחוד לפי מייל מאומת, עוגייה ו-CSRF משלה.
require __DIR__ . '/lib.php';

$dana = ident('17', 'דנה לוי', 'Dana@Example.com');

// ── בלי זהות ──
eq(call('GET', 'me')[0], 401, 'בלי זהות מהשער — 401');
eq(call('GET', 'me', [], '{"sub":"abc","name":"x"}')[0], 401, 'sub שאינו ספרות — 401');
eq(call('GET', 'me', [], ident('18', 'בלי הרשאה', 'n@x.co', ['granted' => false]))[0], 401, 'מחובר לפלטפורמה בלי הרשאה (granted:false) — 401');
eq((int)apr_row('SELECT COUNT(*) AS n FROM users')['n'], 0, 'לא נפתח חשבון למי שלא נכנס');

// ── כניסה ראשונה: חשבון נפתח מיד, פעיל ──
[$st, $me] = call('GET', 'me', [], $dana);
eq($st, 200, 'כניסה ראשונה');
eq([$me['user']['name'], $me['user']['email'], $me['user']['is_developer'], $me['user']['is_platform_manager']],
    ['דנה לוי', 'dana@example.com', false, false], 'החשבון נפתח מהזהות, בלי הרשאות מיוחדות');
eq([$me['offices'], $me['office']], [[], null], 'חשבון חדש — בלי משרד');
check('יש אסימון CSRF', preg_match('/^[a-f0-9]{64}$/', $me['csrf']) === 1);
$u = apr_row('SELECT * FROM users WHERE platform_sub = ?', ['17']);
eq($u['status'], 'active', 'החשבון פעיל בלי אישור נוסף');
eq(apr_row("SELECT action FROM audit_log WHERE target = 'user:1'")['action'], 'user.created', 'פתיחת החשבון ביומן הפעולות');

// ── העוגייה: session אחד, נשאר ──
$n = fn() => (int)apr_row('SELECT COUNT(*) AS n FROM sessions')['n'];
eq($n(), 1, 'נפתח session אחד');
[, $me2] = call('GET', 'me', [], $dana);
eq([$n(), $me2['csrf']], [1, $me['csrf']], 'בקשה שנייה — אותו session ואותו CSRF');
check('במסד רק hash של האסימון', preg_match('/^[a-f0-9]{64}$/', apr_row('SELECT token_hash FROM sessions')['token_hash']) === 1);

// עוגייה של משתמש אחר (מישהו אחר נכנס לפלטפורמה באותו דפדפן) — לא בשימוש; נפתח session חדש
$jar = (new ReflectionFunction('call'))->getStaticVariables()['jar'];
$yossi = ident('20', 'יוסי', 'yossi@example.com');
[, $meY] = call('GET', 'me', [], $yossi, [], ['cookie' => $jar[$dana]]);
check('עוגייה של אחר — CSRF אחר ומשתמש אחר', $meY['csrf'] !== $me['csrf'] && $meY['user']['name'] === 'יוסי');
eq($n(), 2, 'session חדש למשתמש השני');

// עוגייה משובשת — מתעלמים ופותחים חדש
eq(call('GET', 'me', [], ident('21', 'רון', null), [], ['cookie' => "' OR 1=1 --"])[0], 200, 'עוגייה משובשת לא מפילה');

// ── CSRF ──
eq(call('POST', 'office-switch', ['office_id' => 1], $dana, [], ['csrf' => ''])[0], 403, 'POST בלי CSRF — 403');
eq(call('POST', 'office-switch', ['office_id' => 1], $dana, [], ['csrf' => $meY['csrf']])[0], 403, 'POST עם CSRF של משתמש אחר — 403');
eq(call('POST', 'office-switch', ['office_id' => 1], $dana)[0], 404, 'POST עם CSRF נכון עובר לבדיקת ההרשאה');
eq(call('PUT', 'me', [], $dana)[0], 405, 'שיטה אחרת — 405');
eq(call('GET', 'no-such', [], $dana)[0], 404, 'פעולה לא מוכרת — 404');

// ── הזהות מתעדכנת מהשער ──
[, $me3] = call('GET', 'me', [], ident('17', 'דנה לוי-כהן', 'dana@example.com'));
eq($me3['user']['name'], 'דנה לוי-כהן', 'שינוי שם בפלטפורמה מגיע לכאן');
eq((int)apr_row('SELECT COUNT(*) AS n FROM users WHERE platform_sub = ?', ['17'])['n'], 1, 'אותו חשבון, לא חדש');

// ── המפתח = מנהל הפלטפורמה מהשער, ורק משם ──
$owner = ident('1', 'הבעלים', 'owner@example.com', ['platform_admin' => true]);
[, $meO] = call('GET', 'me', [], $owner);
eq([$meO['user']['is_developer'], $meO['user']['is_platform_manager']], [true, true], 'platform_admin → מפתח ומנהל הפלטפורמה');
[, $meO2] = call('GET', 'me', [], ident('1', 'הבעלים', 'owner@example.com'));
eq([$meO2['user']['is_developer'], $meO2['user']['is_platform_manager']], [false, false], 'הוסר בפלטפורמה → הוסר כאן, מהבקשה הבאה');
eq(call('GET', 'dev-health', [], ident('1', 'הבעלים', 'owner@example.com'))[0], 403, 'ומסך המפתח נסגר לו');
call('GET', 'me', [], $owner);

// ── איחוד לפי מייל: חשבון שהוזמן מתקשר בכניסה הראשונה ──
apr_exec("INSERT INTO users (email, display_name, created_at) VALUES ('noa@example.com', 'נועה (הוזמנה)', ?)", [apr_now()]);
$invited = (int)apr_db()->lastInsertId();
[, $meN] = call('GET', 'me', [], ident('30', 'נועה שמאי', 'NOA@example.com'));
eq($meN['user']['id'], $invited, 'מייל מאומת זהה — נכנסים לחשבון שהוזמן');
eq(apr_row('SELECT platform_sub, display_name FROM users WHERE id = ?', [$invited]), ['platform_sub' => '30', 'display_name' => 'נועה שמאי'], 'החשבון קושר ל-sub');
check('הקישור נרשם ביומן הפעולות', (bool)apr_row("SELECT 1 AS x FROM audit_log WHERE action = 'user.linked' AND target = ?", ["user:$invited"]));

// מייל שלא אומת — לא מתאחד (אחרת מי שרשם מייל של אחר היה מקבל את ההזמנה שלו)
apr_exec("INSERT INTO users (email, display_name, created_at) VALUES ('gil@example.com', 'גיל (הוזמן)', ?)", [apr_now()]);
$gilInvite = (int)apr_db()->lastInsertId();
[, $meG] = call('GET', 'me', [], ident('31', 'מתחזה', 'gil@example.com', ['email_verified' => false]));
check('מייל לא מאומת — חשבון חדש, לא ההזמנה', $meG['user']['id'] !== $gilInvite);
eq(apr_row('SELECT platform_sub FROM users WHERE id = ?', [$gilInvite])['platform_sub'], null, 'ההזמנה נשארת לא מקושרת');

// חשבון שכבר מקושר — sub אחר עם אותו מייל לא נכנס אליו
[, $meD2] = call('GET', 'me', [], ident('99', 'דנה אחרת', 'dana@example.com'));
check('חשבון מקושר לא מתקשר שוב', $meD2['user']['id'] !== $me['user']['id']);

// ── חשבון מושעה ──
apr_exec("UPDATE users SET status = 'suspended' WHERE platform_sub = '20'");
eq(call('GET', 'me', [], $yossi)[0], 403, 'חשבון מושעה — 403');

// ── יציאה מהפלטפורמה = יציאה מכאן: בקשה בלי זהות עם העוגייה מוחקת את ה-session ──
$before = $n();
$jar = (new ReflectionFunction('call'))->getStaticVariables()['jar'];
eq(call('GET', 'me', [], null, [], ['cookie' => $jar[$dana]])[0], 401, 'בלי זהות — 401');
eq($n(), $before - 1, 'ה-session נמחק');
[, $me4] = call('GET', 'me', [], $dana);
check('כניסה מחדש — session ו-CSRF חדשים', $me4['csrf'] !== $me['csrf']);

// ── session שפג (חצי שנה בלי שימוש) ──
$GLOBALS['apr_time'] = time() + APR_SESSION_TTL + 10;
[, $me5] = call('GET', 'me', [], $dana);
check('session שפג — נפתח חדש', $me5['csrf'] !== $me4['csrf']);
unset($GLOBALS['apr_time']);

done();
