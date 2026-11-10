<?php
// יומן הפעולות — 01-D1 … 01-D4, וניהול המערכת (§7): משתמשים, דגלים, מנהל מערכת אחרון.
require __DIR__ . '/lib.php';

$owner = ident('1', 'הבעלים', 'owner@example.com', ['platform_admin' => true]);
$avi = ident('10', 'אבי', 'avi@a.co');
$ella = ident('11', 'אלה', 'ella@a.co');
$batya = ident('20', 'בתיה', 'batya@b.co');
$rows = fn(string $action) => apr_rows('SELECT a.*, o.pid AS office_pid, u.pid AS actor_pid FROM audit_log a LEFT JOIN offices o ON o.id = a.office_id LEFT JOIN users u ON u.id = a.actor_user_id WHERE a.action = ? ORDER BY a.id', [$action]);
$pid = fn(string $as) => call('GET', 'me', [], $as)[1]['user']['pid'];

// ── מפעילים כל פעולה מהרשימה שבסעיף 8, פעם אחת ──
[$ownerPid, $aviPid, $ellaPid, $batyaPid] = [$pid($owner), $pid($avi), $pid($ella), $pid($batya)];                 // user.create ×4, user.grant_sysadmin
$now = apr_now();
apr_insert('users', ['pid' => $oldPid = apr_pid(), 'email' => 'old@a.co', 'name' => 'ישן', 'created_at' => $now, 'updated_at' => $now]);
call('GET', 'me', [], ident('12', 'ישן', 'old@a.co'));                                                              // user.link
$A = office_for($avi, 'משרד א');                                                                                    // office.create, member.join
$B = office_for($batya, 'משרד ב');
call('POST', 'office-rename', ['name' => 'משרד אלף'], $avi);                                                         // office.rename
$inv = call('POST', 'invite-create', ['email' => 'ella@a.co', 'role' => 'staff'], $avi)[1];                          // invite.create
call('POST', 'invite-accept', ['token' => $inv['token']], $ella);                                                   // invite.accept, member.join
$inv2 = call('POST', 'invite-create', ['email' => 'x@a.co', 'role' => 'staff'], $avi)[1];
call('POST', 'invite-revoke', ['invite' => $inv2['invite']], $avi);                                                 // invite.revoke
call('POST', 'member-update', ['user' => $ellaPid, 'role' => 'trainee'], $avi);                                      // member.role
call('POST', 'member-update', ['user' => $ellaPid, 'is_admin' => true], $avi);                                       // member.admin_on
call('POST', 'member-update', ['user' => $ellaPid, 'is_admin' => false], $avi);                                      // member.admin_off
call('POST', 'member-update', ['user' => $ellaPid, 'status' => 'suspended'], $avi);                                  // member.suspend
call('POST', 'member-update', ['user' => $ellaPid, 'status' => 'active'], $avi);                                     // member.unsuspend
call('POST', 'office-leave', [], $ella);                                                                            // member.leave
join_office($avi, $ella, 'ella@a.co');
call('POST', 'member-remove', ['user' => $ellaPid], $avi);                                                          // member.remove
call('POST', 'sys-office-status', ['office' => $B, 'status' => 'suspended', 'reason' => 'חשד לשימוש לרעה'], $owner); // office.suspend
call('POST', 'sys-office-status', ['office' => $B, 'status' => 'active', 'reason' => 'נבדק'], $owner);               // office.unsuspend
call('POST', 'sys-user-status', ['user' => $batyaPid, 'status' => 'suspended', 'reason' => 'לבקשתה'], $owner);       // user.suspend
call('POST', 'sys-user-status', ['user' => $batyaPid, 'status' => 'active', 'reason' => 'חזרה'], $owner);            // user.unsuspend
call('POST', 'sys-user-flags', ['user' => $aviPid, 'is_sysadmin' => true, 'is_developer' => true], $owner);          // user.grant_sysadmin (is_developer — מתעלמים)
call('POST', 'sys-user-flags', ['user' => $aviPid, 'is_sysadmin' => false], $owner);                                 // user.revoke_sysadmin
call('GET', 'me', [], ident('1', 'הבעלים', 'owner@example.com'));                                                   // user.developer_off (כבר לא platform_admin)
call('GET', 'me', [], $owner);                                                                                      // user.developer_on
call('POST', 'sys-setting-set', ['name' => 'office_self_create', 'value' => false], $owner);                         // setting.change
call('POST', 'sys-setting-set', ['name' => 'office_self_create', 'value' => true], $owner);
$tok = call('POST', 'dev-token-create', ['label' => 'Claude'], $owner)[1];                                          // devtoken.create
call('POST', 'dev-token-revoke', ['id' => 1], $owner);                                                              // devtoken.revoke

// ── 01-D1: כל פעולה כתבה שורה, עם המבצע, המשרד והישות ──
$expect = [   // פעולה => [כמה שורות, משרד (pid או null), מבצע, סוג ישות, ישות]
    'user.link' => [1, null, $oldPid, 'user', $oldPid],
    'office.rename' => [1, $A, $aviPid, 'office', $A],
    'office.suspend' => [1, $B, $ownerPid, 'office', $B],
    'office.unsuspend' => [1, $B, $ownerPid, 'office', $B],
    'invite.revoke' => [1, $A, $aviPid, 'invitation', $inv2['invite']],
    'member.role' => [1, $A, $aviPid, 'user', $ellaPid],
    'member.admin_on' => [1, $A, $aviPid, 'user', $ellaPid],
    'member.admin_off' => [1, $A, $aviPid, 'user', $ellaPid],
    'member.suspend' => [1, $A, $aviPid, 'user', $ellaPid],
    'member.unsuspend' => [1, $A, $aviPid, 'user', $ellaPid],
    'member.leave' => [1, $A, $ellaPid, 'user', $ellaPid],
    'member.remove' => [1, $A, $aviPid, 'user', $ellaPid],
    'user.suspend' => [1, null, $ownerPid, 'user', $batyaPid],
    'user.unsuspend' => [1, null, $ownerPid, 'user', $batyaPid],
    'user.revoke_sysadmin' => [1, null, $ownerPid, 'user', $aviPid],
    'user.developer_off' => [1, null, null, 'user', $ownerPid],
    'devtoken.create' => [1, null, $ownerPid, 'devtoken', '1'],
    'devtoken.revoke' => [1, null, $ownerPid, 'devtoken', '1'],
];
foreach ($expect as $action => [$n, $office, $actor, $type, $entity]) {
    $r = $rows($action);
    eq([count($r), $r[0]['office_pid'] ?? null, $r[0]['actor_pid'] ?? null, $r[0]['entity_type'] ?? null, $r[0]['entity_pid'] ?? null],
        [$n, $office, $actor, $type, $entity], "01-D1 $action — שורה אחת, עם המשרד, המבצע והישות");
}
eq(count($rows('user.create')), 4, '01-D1 user.create — שורה לכל חשבון שנפתח בכניסה');
eq(count($rows('office.create')), 2, '01-D1 office.create — שורה לכל משרד');
eq([count($rows('user.developer_on')), $rows('user.developer_on')[0]['actor_kind'], $rows('user.developer_on')[0]['actor_pid']], [2, 'system', null], '01-D1 user.developer_on — בכניסה הראשונה ובחזרה; המבצע: המערכת');
eq(array_map(fn($r) => json_decode($r['meta'], true)['to'], $rows('setting.change')), [false, true], '01-D1 setting.change — עם הערך החדש');
eq(apr_val("SELECT is_developer FROM users WHERE pid = ?", [$aviPid]), 0, 'אין פעולת API שמדליקה is_developer');
eq(count($rows('user.grant_sysadmin')), 2, '01-D1 user.grant_sysadmin — הבעלים (מהמעטפת) ואבי (בידי מנהל המערכת)');
eq(array_map(fn($r) => json_decode($r['meta'], true)['via'], $rows('member.join')), ['create', 'create', 'invite', 'invite'], '01-D1 member.join — בהקמה ובקבלת הזמנה');
eq(count($rows('invite.create')), 3, '01-D1 invite.create');
eq(count($rows('invite.accept')), 2, '01-D1 invite.accept');
eq([$rows('office.suspend')[0]['reason'], $rows('user.suspend')[0]['reason']], ['חשד לשימוש לרעה', 'לבקשתה'], '01-D1 הנימוק נשמר');
eq(json_decode($rows('member.role')[0]['meta'], true), ['from' => 'staff', 'to' => 'trainee'], '01-D1 meta: מצב קודם וחדש');
$known = ['user.create', 'user.link', 'user.suspend', 'user.unsuspend', 'user.grant_sysadmin', 'user.revoke_sysadmin', 'user.developer_on', 'user.developer_off', 'setting.change',
    'office.create', 'office.rename', 'office.suspend', 'office.unsuspend', 'member.join', 'member.role', 'member.admin_on', 'member.admin_off', 'member.suspend',
    'member.unsuspend', 'member.remove', 'member.leave', 'invite.create', 'invite.revoke', 'invite.accept', 'devtoken.create', 'devtoken.revoke'];
eq(array_values(array_diff($known, array_column(apr_rows('SELECT DISTINCT action FROM audit_log'), 'action'))), [], '01-D1 כל 26 הפעולות שבאפיון נרשמו');
eq(array_values(array_diff(array_column(apr_rows('SELECT DISTINCT action FROM audit_log'), 'action'), $known)), [], '01-D1 ואין קוד פעולה שאינו באפיון');

// מה לא נכנס ליומן: אסימונים, מיילים, שמות
$dump = json_encode(apr_rows('SELECT * FROM audit_log'), JSON_UNESCAPED_UNICODE);
check('היומן בלי אסימונים', !str_contains($dump, $inv['token']) && !str_contains($dump, $tok['token']) && !preg_match('/[a-f0-9]{48}/', $dump));
check('היומן בלי מיילים ובלי שמות (רק מזהים ומצבים)', !preg_match('/@|אבי|אלה|בתיה|משרד/u', preg_replace('/"reason":"[^"]*"/u', '', $dump)));

// ── 01-D2: פעולה שנכשלה אחרי הכתיבה ליומן אינה משאירה שורה ──
$n = (int)apr_val('SELECT COUNT(*) FROM audit_log');
try { apr_tx(function () { apr_audit('office.rename', 'office', 'x'); throw new RuntimeException('נכשל אחרי היומן'); }); } catch (RuntimeException) {}
eq((int)apr_val('SELECT COUNT(*) FROM audit_log'), $n, '01-D2 אותה טרנזקציה: אין שורה');
// וההפך: היומן נכשל → הפעולה נכשלת
apr_db()->exec("CREATE TRIGGER t_fail BEFORE INSERT ON audit_log WHEN NEW.action = 'office.rename' BEGIN SELECT RAISE(ABORT, 'audit down'); END");
$r = call('POST', 'office-rename', ['name' => 'לא אמור להישמר'], $avi);
eq([$r[0], apr_val('SELECT name FROM offices WHERE pid = ?', [$A])], [500, 'משרד אלף'], '01-D2 היומן נכשל → השם לא השתנה');
check('שגיאה לא צפויה: "תקלה בשרת" בלי פרטים', $r[1]['error'] === ['code' => 'server', 'message' => 'תקלה בשרת']);
apr_db()->exec('DROP TRIGGER t_fail');

// ── 01-D3: אין עדכון ואין מחיקה ביומן ──
foreach (["UPDATE audit_log SET action = 'x'", 'DELETE FROM audit_log'] as $sql) {
    try { apr_exec($sql); check("01-D3 המסד חוסם: $sql", false); } catch (PDOException $e) { check("01-D3 המסד חוסם: $sql", str_contains($e->getMessage(), 'append-only')); }
}
eq((int)apr_val('SELECT COUNT(*) FROM audit_log'), $n, '01-D3 היומן שלם');
$code = '';
foreach (glob(__DIR__ . '/../{*.php,lib/*.php}', GLOB_BRACE) as $f) if (basename($f) !== 'devloop.php') $code .= file_get_contents($f);
check('01-D3 אין בקוד עדכון או מחיקה של audit_log', !preg_match('/(UPDATE\s+audit_log|DELETE\s+FROM\s+audit_log)/i', $code));
check('01-D3 אין ב-API פעולה שכותבת ליומן ישירות', !preg_grep('/audit/', array_diff(array_keys(APR_ACCESS), ['office-audit', 'dev-audit'])) && in_array('office-audit', APR_READS, true) && in_array('dev-audit', APR_READS, true));

// ── 01-D4: מנהל משרד מקבל רק את השורות של המשרד שלו, עם שמות; המפתח — הכול, מזהים בלבד ──
[, $la] = call('GET', 'office-audit', [], $avi);
[, $lb] = call('GET', 'office-audit', [], $batya);
$officeOf = fn(array $r) => apr_val('SELECT o.pid FROM audit_log a JOIN offices o ON o.id = a.office_id WHERE a.at = ? AND a.action = ? AND a.entity_pid = ?', [$r['at'], $r['action'], $r['entity_pid']]);
check('01-D4 משרד א — רק שורות של משרד א', count($la['rows']) >= 10 && !array_filter($la['rows'], fn($r) => $officeOf($r) !== $A));
check('01-D4 משרד ב — רק שורות של משרד ב', count($lb['rows']) >= 3 && !array_filter($lb['rows'], fn($r) => $officeOf($r) !== $B));
check('01-D4 ואין ביומן של ב שום מזהה של חבר במשרד א', !str_contains(json_encode($lb), $ellaPid) && !str_contains(json_encode($lb), $aviPid));
$role = array_values(array_filter($la['rows'], fn($r) => $r['action'] === 'member.role'))[0];
eq([$role['actor'], $role['entity_name']], ['אבי', 'אלה'], 'מנהל משרד רואה שמות של חברי המשרד שלו (גם של מי שכבר הוסר)');
$susp = array_values(array_filter($lb['rows'], fn($r) => $r['action'] === 'office.suspend'))[0];
eq([$susp['actor'], $susp['reason']], ['', 'חשד לשימוש לרעה'], 'פעולה של מנהל המערכת: בלי שם (אינו חבר במשרד), עם הנימוק');
[, $all] = call('GET', 'dev-audit', [], $owner);
check('המפתח רואה את כל המערכת, מהחדש לישן', count($all['rows']) === min(100, $n) && $all['rows'][0]['action'] === 'devtoken.revoke' &&$all['rows'][0]['at'] >= end($all['rows'])['at']);
check('המפתח רואה מזהים — לא שמות ולא מיילים', !preg_match('/@|אבי|אלה|בתיה|"actor_name"|"entity_name"/u', preg_replace('/"reason":"[^"]*"/u', '', json_encode($all, JSON_UNESCAPED_UNICODE))));
$f = call('GET', 'dev-audit', [], $owner, ['what' => 'office.'])[1]['rows'];
check('סינון לפי פעולה', count($f) === 5 && !array_filter($f, fn($r) => !str_starts_with($r['action'], 'office.')));
check('סינון לפי משרד', !array_filter(call('GET', 'dev-audit', [], $owner, ['office' => $B])[1]['rows'], fn($r) => $r['office'] !== $B));
check('סינון לפי משתמש', !array_filter(call('GET', 'dev-audit', [], $owner, ['user' => $aviPid])[1]['rows'], fn($r) => $r['actor'] !== $aviPid));
eq(call('GET', 'dev-audit', [], $owner, ['from' => '2099-01-01'])[1]['rows'], [], 'סינון לפי תאריך');
eq(count(call('GET', 'dev-audit', [], $owner, ['limit' => 3, 'offset' => 2])[1]['rows']), 3, 'דפדוף');
check('לכל שורה מזהה בקשה לקישור ליומן הטכני (ריק בבדיקה בלי שרת)', !array_filter($all['rows'], fn($r) => !array_key_exists('req', $r)));

// ── ניהול המערכת (§7): נתונים תפעוליים בלבד ──
foreach (array_keys(array_filter(APR_ACCESS, fn($r) => $r === 'sysadmin')) as $a) {
    $m = in_array($a, APR_READS, true) ? 'GET' : 'POST';
    eq([call($m, $a, [], $avi)[0], code(call($m, $a, [], $avi))], [404, 'not_found'], "$a — 404 למי שאינו מנהל המערכת");
}
[, $so] = call('GET', 'sys-office', [], $owner, ['office' => $A]);
eq(array_keys($so['members'][0]), ['pid', 'name', 'email', 'role', 'is_admin', 'status'], 'על חבר במשרד: שם, מייל, תפקיד, מצב — לא יותר');
eq(array_keys(call('GET', 'sys-offices', [], $owner)[1]['offices'][0]), ['pid', 'name', 'status', 'created_at', 'members', 'no_active_members', 'last_activity'], 'על משרד: שם, מצב, הקמה, חברים, פעילות אחרונה');
eq(code(call('GET', 'sys-office', [], $owner, ['office' => apr_pid()])), 'not_found', 'משרד שלא קיים');
// מקרה 10: משרד בלי אף חבר פעיל — נשאר, ומסומן
call('POST', 'sys-user-status', ['user' => $batyaPid, 'status' => 'suspended', 'reason' => 'בדיקה'], $owner);
eq(array_column(call('GET', 'sys-offices', [], $owner)[1]['offices'], 'no_active_members', 'pid')[$B], true, 'מקרה 10: משרד בלי חבר פעיל מסומן אצל מנהל המערכת');
call('POST', 'sys-user-status', ['user' => $batyaPid, 'status' => 'active', 'reason' => 'סוף בדיקה'], $owner);
// 01-C6: מנהל המערכת האחרון
eq(code(call('POST', 'sys-user-flags', ['user' => $ownerPid, 'is_sysadmin' => false], $owner)), 'last_sysadmin', '01-C6 אי אפשר להסיר את מנהל המערכת האחרון');
eq(code(call('POST', 'sys-user-status', ['user' => $ownerPid, 'status' => 'suspended', 'reason' => 'x'], $owner)), 'last_sysadmin', '01-C6 ולא להשעות אותו');
call('POST', 'sys-user-flags', ['user' => $aviPid, 'is_sysadmin' => true], $owner);
eq(call('GET', 'sys-offices', [], $avi)[0], 200, 'מי שקיבל is_sysadmin רואה את מסך המערכת');
eq(call('GET', 'dev-health', [], $avi)[0], 404, 'אבל לא את מסך המפתח — דגל נפרד');
eq(code(call('POST', 'sys-user-status', ['user' => apr_pid(), 'status' => 'suspended', 'reason' => 'x'], $owner)), 'not_found', 'משתמש שלא קיים');
eq(code(call('POST', 'sys-user-status', ['user' => $aviPid, 'status' => 'suspended'], $owner)), 'reason_required', 'השעיית משתמש דורשת נימוק');

done();
