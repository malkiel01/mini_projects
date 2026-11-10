<?php
// משרדים וצוות — 01-C1 … 01-C11, ומקרי הקצה 8–10.
require __DIR__ . '/lib.php';

$owner = ident('1', 'הבעלים', 'owner@example.com', ['platform_admin' => true]);
$avi = ident('10', 'אבי מנהל', 'avi@a.co');
$ella = ident('11', 'אלה עובדת', 'ella@a.co');
$batya = ident('20', 'בתיה מנהלת', 'batya@b.co');
$mem = fn(string $as) => array_column(call('GET', 'office', [], $as)[1]['members'] ?? [], null, 'name');

// ── 01-C1: משתמש בלי משרד מקים משרד → חבר פעיל עם is_admin, והמשרד פעיל אצלו ──
eq(call('GET', 'me', [], $avi)[1]['office'], null, 'לפני: בלי משרד');
eq(code(call('GET', 'office', [], $avi)), 'no_office', 'בלי משרד — אין נתוני משרד');
[$st, $r] = call('POST', 'office-create', ['name' => '  משרד   א  ', 'role' => 'appraiser', 'op' => 'op-12345678'], $avi);
$A = $r['office'] ?? '';
check('01-C1 המשרד נפתח, עם מזהה ציבורי', $st === 200 && apr_is_pid($A), $r);
[, $me] = call('GET', 'me', [], $avi);
eq([$me['office'], $me['offices'][0]['name'], $me['offices'][0]['role'], $me['offices'][0]['is_admin'], $me['offices'][0]['status']], [$A, 'משרד א', 'appraiser', true, 'active'], '01-C1 המקים: חבר פעיל, מנהל, והמשרד פעיל אצלו');
eq(apr_val('SELECT status FROM offices WHERE pid = ?', [$A]), 'active', '01-C1 המשרד פעיל מיד');
// מקרה 8: לחיצה כפולה — משרד אחד
[$st, $r2] = call('POST', 'office-create', ['name' => 'משרד א', 'role' => 'appraiser', 'op' => 'op-12345678'], $avi);
eq([$st, $r2['office'], (int)apr_val('SELECT COUNT(*) FROM offices')], [200, $A, 1], 'מקרה 8: אותו מזהה פעולה — משרד אחד');
eq(code(call('POST', 'office-create', ['name' => '', 'role' => 'staff'], $avi)), 'name_required', 'בלי שם');
eq(code(call('POST', 'office-create', ['name' => str_repeat('א', 121), 'role' => 'staff'], $avi)), 'name_too_long', 'שם ארוך מדי');
eq(code(call('POST', 'office-create', ['name' => 'x', 'role' => 'emperor'], $avi)), 'role_invalid', 'תפקיד לא מוכר');
$B = office_for($batya, 'משרד ב', 'staff');

// ── 01-C2: הזמנה וקבלה ──
[$st, $inv] = call('POST', 'invite-create', ['email' => 'ELLA@a.co', 'role' => 'staff'], $avi);
check('01-C2 הזמנה נוצרה, עם אסימון לקישור', $st === 200 && preg_match('/^[a-f0-9]{48}$/', $inv['token']) === 1, $inv);
check('01-C2 במסד רק hash של האסימון', !apr_val('SELECT 1 FROM invitations WHERE token_hash = ?', [$inv['token']]) && apr_val('SELECT 1 FROM invitations WHERE token_hash = ?', [hash('sha256', $inv['token'])]));
[, $list] = call('GET', 'office', [], $avi);
eq([count($list['invites']), $list['invites'][0]['email'], array_key_exists('token', $list['invites'][0])], [1, 'ella@a.co', false], 'ההזמנה ברשימה של המנהל — בלי האסימון');
[, $mine] = call('GET', 'my-invites', [], $ella);
eq([$mine['verified'], count($mine['invites']), $mine['invites'][0]['office_name']], [true, 1, 'משרד א'], 'המוזמנת רואה את ההזמנה שלה ("יש לי הזמנה")');
[$st, $info] = call('POST', 'invite-info', ['token' => $inv['token']], $ella);
eq([$st, $info['invite']['office_name'], $info['invite']['role']], [200, 'משרד א', 'staff'], 'מסך הקבלה: שם המשרד והתפקיד');
[$st, $acc] = call('POST', 'invite-accept', ['token' => $inv['token']], $ella);
[, $meE] = call('GET', 'me', [], $ella);
eq([$st, $acc['office'], $meE['office'], $meE['offices'][0]['role'], $meE['offices'][0]['is_admin']], [200, $A, $A, 'staff', false], '01-C2 המוזמנת חברה פעילה בתפקיד שהוזמן');
$again = call('POST', 'invite-accept', ['token' => $inv['token']], $ella);
eq([$again[0], code($again)], [404, 'invite_invalid'], '01-C2 אותה הזמנה בפעם השנייה — "אינה בתוקף"');
eq(code(call('POST', 'invite-create', ['email' => 'ella@a.co', 'role' => 'staff'], $avi)), 'already_member', 'הזמנה למי שכבר חבר');
eq(code(call('POST', 'invite-create', ['email' => 'לא-מייל', 'role' => 'staff'], $avi)), 'email_invalid', 'מייל לא תקין');

// ── 01-C3: פגה / בוטלה / לא קיים → אותה תשובה בדיוק ──
$gil = ident('12', 'גיל', 'gil@a.co');
$mk = fn() => call('POST', 'invite-create', ['email' => 'gil@a.co', 'role' => 'trainee'], $avi)[1];
$i1 = $mk();
$i2 = $mk();   // הזמנה חדשה לאותו מייל מבטלת את הקודמת
eq(apr_val('SELECT status FROM invitations WHERE pid = ?', [$i1['invite']]), 'revoked', 'הזמנה חדשה לאותו מייל מבטלת את הקודמת');
$replaced = call('POST', 'invite-accept', ['token' => $i1['token']], $gil);
call('POST', 'invite-revoke', ['invite' => $i2['invite']], $avi);
$revoked = call('POST', 'invite-accept', ['token' => $i2['token']], $gil);
$i3 = $mk();
$GLOBALS['apr_time'] = time() + APR_INVITE_TTL + 5;
$expired = call('POST', 'invite-accept', ['token' => $i3['token']], $gil);
eq(call('GET', 'office', [], $avi)[1]['invites'], [], 'הזמנה שפגה לא מופיעה עוד ברשימת הממתינות');
unset($GLOBALS['apr_time']);
$missing = call('POST', 'invite-accept', ['token' => str_repeat('ab', 24)], $gil);
$garbage = call('POST', 'invite-accept', ['token' => "x' OR 1=1"], $gil);
$strip = fn(array $r) => [$r[0], $r[1]['error']];
foreach (['בוטלה (הוחלפה)' => $replaced, 'בוטלה' => $revoked, 'פגה' => $expired, 'מחרוזת משובשת' => $garbage] as $what => $res) {
    eq($strip($res), $strip($missing), "01-C3 $what — אותה תשובה כמו אסימון שלא קיים");
}
eq($strip($missing), [404, ['code' => 'invite_invalid', 'message' => 'ההזמנה אינה בתוקף. בקשו הזמנה חדשה.']], '01-C3 הקוד והנוסח');
eq(apr_val('SELECT status FROM invitations WHERE pid = ?', [$i3['invite']]), 'expired', 'הזמנה שפגה מסומנת "פג"');

// "העתקת קישור": אסימון חדש, והקודם מפסיק לעבוד
$i4 = $mk();
[$st, $link] = call('POST', 'invite-link', ['invite' => $i4['invite']], $avi);
check('העתקת קישור מנפיקה אסימון חדש', $st === 200 && $link['token'] !== $i4['token']);
eq(code(call('POST', 'invite-info', ['token' => $i4['token']], $gil)), 'invite_invalid', 'הקישור הקודם מפסיק לעבוד');

// ── 01-C4: מייל אחר → נדחה, בלי לחשוף את המייל שבהזמנה ──
$other = call('POST', 'invite-accept', ['token' => $link['token']], $batya);
eq([$other[0], code($other)], [403, 'invite_other_email'], '01-C4 מייל אחר — נדחה');
check('01-C4 התשובה אינה כוללת את המייל שבהזמנה', !str_contains(json_encode($other, JSON_UNESCAPED_UNICODE), 'gil'));
$info = call('POST', 'invite-info', ['token' => $link['token']], $batya);
check('01-C4 גם מסך הקבלה לא חושף כלום', $info[0] === 403 && !str_contains(json_encode($info, JSON_UNESCAPED_UNICODE), 'gil') && !str_contains(json_encode($info, JSON_UNESCAPED_UNICODE), 'משרד א'));
eq(code(call('POST', 'invite-accept', ['invite' => $i4['invite']], $batya)), 'invite_invalid', 'קבלה לפי pid של הזמנה למייל אחר — "אינה בתוקף"');
eq(code(call('POST', 'invite-accept', ['invite' => $i4['invite']], ident('12', 'גיל', 'gil@a.co', ['email_verified' => false]))), 'invite_invalid', 'קבלה מהרשימה — רק למייל מאומת');
eq(call('POST', 'invite-accept', ['token' => $link['token']], $gil)[0], 200, 'והנמען האמיתי מקבל');
$gilPid = call('GET', 'me', [], $gil)[1]['user']['pid'];
$ellaPid = $meE['user']['pid'];
$aviPid = $me['user']['pid'];

// ── 01-C5: מי שאינו is_admin אינו מנהל את הצוות ──
foreach (['invite-create' => ['email' => 'y@a.co', 'role' => 'staff'], 'invite-revoke' => ['invite' => $i4['invite']], 'invite-link' => ['invite' => $i4['invite']],
          'member-update' => ['user' => $gilPid, 'role' => 'appraiser'], 'member-remove' => ['user' => $gilPid], 'office-rename' => ['name' => 'שלי']] as $a => $body) {
    eq([call('POST', $a, $body, $ella)[0], code(call('POST', $a, $body, $ella))], [403, 'forbidden'], "01-C5 עובדת: $a → 403");
}
eq(call('GET', 'office-audit', [], $ella)[0], 403, '01-C5 עובדת לא רואה את יומן הפעולות');
[, $view] = call('GET', 'office', [], $ella);
eq([$view['can_manage'], array_key_exists('invites', $view), array_key_exists('email', $view['members'][0])], [false, false, false], 'חבר רגיל רואה שם ותפקיד בלבד — בלי מיילים ובלי הזמנות');
eq(apr_val('SELECT role FROM memberships m JOIN users u ON u.id = m.user_id WHERE u.pid = ?', [$gilPid]), 'trainee', '01-C5 ושום דבר לא השתנה');

// ── 01-C6: המנהל הפעיל האחרון ──
eq(code(call('POST', 'member-update', ['user' => $aviPid, 'is_admin' => false], $avi)), 'last_admin', '01-C6 המנהל האחרון לא מוריד את עצמו');
eq(code(call('POST', 'member-update', ['user' => $aviPid, 'status' => 'suspended'], $avi)), 'self_action', '01-C6 מנהל לא משעה את עצמו');
eq(code(call('POST', 'member-remove', ['user' => $aviPid], $avi)), 'self_action', '01-C6 ולא מסיר את עצמו');
eq(code(call('POST', 'office-leave', [], $avi)), 'last_admin_leave', 'מקרה 9: המנהל האחרון עוזב — נחסם, עם הסבר');
eq(call('POST', 'member-update', ['user' => $ellaPid, 'is_admin' => true, 'title' => 'מנהלת המשרד'], $avi)[0], 200, 'מינוי מנהלת נוספת');
eq([$mem($avi)['אלה עובדת']['is_admin'], $mem($avi)['אלה עובדת']['role'], $mem($avi)['אלה עובדת']['title']], [true, 'staff', 'מנהלת המשרד'], 'מנהלת בלי להיות שמאית — ניהול הוא דגל נפרד מהתפקיד');
eq(call('POST', 'member-update', ['user' => $aviPid, 'is_admin' => false], $avi)[0], 200, 'עכשיו אבי יכול לרדת מניהול');
eq(code(call('POST', 'member-update', ['user' => $ellaPid, 'is_admin' => false], $ella)), 'last_admin', '01-C6 ועכשיו אלה היא האחרונה');
eq(code(call('POST', 'member-remove', ['user' => $ellaPid], $avi)), 'forbidden', 'ומי שירד מניהול כבר לא מנהל');

// ── 01-C7: חבר מושעה נדחה במשרד הזה, וממשיך במשרד אחר ──
join_office($batya, $gil, 'gil@a.co', 'appraiser');          // גיל חבר גם במשרד ב
call('POST', 'office-switch', ['office' => $A], $gil);
eq(call('GET', 'office', [], $gil)[0], 200, 'לפני ההשעיה גיל עובד במשרד א');
eq(call('POST', 'member-update', ['user' => $gilPid, 'status' => 'suspended'], $ella)[0], 200, 'אלה משעה את גיל במשרד א');
[, $meG] = call('GET', 'me', [], $gil);
eq([$meG['office'], array_column($meG['offices'], 'status', 'pid')[$A]], [$B, 'suspended'], '01-C7 הבקשה הבאה: המשרד הפעיל עבר למשרד הזמין, וא׳ מסומן מושעה');
eq(call('GET', 'office', [], $gil)[1]['office']['pid'], $B, '01-C7 במשרד ב ממשיך לעבוד');
eq(code(call('GET', 'office', [], $gil, ['office' => $A])), 'office_changed', '01-C7 ופעולה על משרד א נדחית');
call('POST', 'office-switch', ['office' => $A], $gil);
eq(code(call('GET', 'office', [], $gil, ['office' => $A])), 'office_changed', '01-C7 גם אחרי ניסיון החלפה — המשרד המושעה אינו נהיה פעיל');
eq(call('POST', 'member-update', ['user' => $gilPid, 'status' => 'active'], $ella)[0], 200, 'ביטול ההשעיה');

// ── 01-C8: בידוד — כל פעולת משרד ממשרד ב׳ עם ה-pid של משרד א׳ → 404, כמו pid שלא קיים ──
$ghost = apr_pid();
$i5 = call('POST', 'invite-create', ['email' => 'new@a.co', 'role' => 'staff'], $ella)[1];
$ops = [['GET', 'office', []], ['GET', 'office-audit', []], ['POST', 'invite-create', ['email' => 'q@q.co', 'role' => 'staff']],
    ['POST', 'invite-revoke', ['invite' => $i5['invite']]], ['POST', 'invite-link', ['invite' => $i5['invite']]],
    ['POST', 'member-update', ['user' => $ellaPid, 'role' => 'trainee']], ['POST', 'member-remove', ['user' => $ellaPid]],
    ['POST', 'office-rename', ['name' => 'נכבש']], ['POST', 'office-leave', []], ['POST', 'office-switch', []]];
$dump = fn() => json_encode([apr_rows('SELECT * FROM memberships ORDER BY id'), apr_rows('SELECT * FROM invitations ORDER BY id'), apr_rows('SELECT * FROM offices ORDER BY id')]);
$state = $dump();
foreach ($ops as [$m, $a, $body]) {
    $foreign = $m === 'GET' ? call($m, $a, [], $batya, ['office' => $A]) : call($m, $a, $body + ['office' => $A], $batya);
    $none = $m === 'GET' ? call($m, $a, [], $batya, ['office' => $ghost]) : call($m, $a, $body + ['office' => $ghost], $batya);
    eq([$foreign[0], $foreign[1]['error']], [404, ['code' => 'not_found', 'message' => 'לא נמצא']], "01-C8 $a עם pid של משרד א׳ → 404");
    eq($foreign[1]['error'], $none[1]['error'], "01-C8 $a — אותו נוסח כמו pid שלא קיים");
}
// גם בלי office בבקשה: המזהים של א׳ (חבר, הזמנה) "לא נמצאו" מתוך משרד ב׳
foreach ([['member-update', ['user' => $ellaPid, 'role' => 'trainee']], ['member-remove', ['user' => $ellaPid]], ['invite-revoke', ['invite' => $i5['invite']]], ['invite-link', ['invite' => $i5['invite']]]] as [$a, $body]) {
    eq(code(call('POST', $a, $body, $batya)), 'not_found', "01-C8 $a על ישות של משרד א׳, מתוך משרד ב׳ → 404");
}
eq($dump(), $state, '01-C8 ושום דבר במסד לא השתנה');
$viewB = call('GET', 'office', [], $batya)[1];
check('01-C8 התשובה למשרד ב׳ אינה מכילה שום פרט של משרד א׳', !preg_match('/ella@|avi@|new@a\.co|משרד א|אלה|אבי/u', json_encode($viewB, JSON_UNESCAPED_UNICODE)));

// ── 01-C9: מנהל המערכת רואה את המשרד ברשימה, ואינו יכול להיכנס אליו ──
[$st, $sys] = call('GET', 'sys-offices', [], $owner);
check('01-C9 רואה את שני המשרדים ברשימה', $st === 200 && array_column($sys['offices'], 'name', 'pid')[$A] === 'משרד א' && isset(array_column($sys['offices'], null, 'pid')[$B]));
eq(code(call('POST', 'office-switch', ['office' => $A], $owner)), 'not_found', '01-C9 אינו יכול להחליף אליו כמשרד פעיל');
eq(call('GET', 'me', [], $owner)[1]['office'], null, '01-C9 ואין לו משרד פעיל');
eq(code(call('GET', 'office', [], $owner, ['office' => $A])), 'not_found', '01-C9 ולא רואה את המשרד מבפנים');
eq(code(call('GET', 'office-audit', [], $owner)), 'no_office', '01-C9 ולא את יומן הפעולות שלו');

// ── 01-C10: משרד מושעה ──
eq(code(call('POST', 'sys-office-status', ['office' => $B, 'status' => 'suspended'], $owner)), 'reason_required', 'השעיית משרד דורשת נימוק');
eq(call('POST', 'sys-office-status', ['office' => $B, 'status' => 'suspended', 'reason' => 'בדיקה'], $owner)[0], 200, 'מנהל המערכת משעה את משרד ב');
$count = (int)apr_val('SELECT COUNT(*) FROM memberships');
foreach ([['GET', 'office', []], ['GET', 'office-audit', []], ['POST', 'invite-create', ['email' => 'z@b.co', 'role' => 'staff']], ['POST', 'office-rename', ['name' => 'x']], ['POST', 'office-leave', []]] as [$m, $a, $body]) {
    $r = call($m, $a, $body, $batya);
    eq([$r[0], code($r)], [403, 'office_suspended'], "01-C10 משרד מושעה: $a → 403");
}
eq(call('GET', 'me', [], $batya)[1]['offices'][0]['status'], 'office_suspended', '01-C10 והמסך יודע להסביר: "המשרד הושעה"');
eq(call('POST', 'sys-office-status', ['office' => $B, 'status' => 'active', 'reason' => 'הבדיקה הסתיימה'], $owner)[0], 200, 'ביטול ההשעיה');
eq([call('GET', 'office', [], $batya)[0], (int)apr_val('SELECT COUNT(*) FROM memberships')], [200, $count], '01-C10 חוזרים לעבוד, והנתונים שלמים');

// ── 01-C11: חבר בשני משרדים — הצוות תמיד של המשרד הפעיל ──
call('POST', 'office-switch', ['office' => $A], $gil);
[, $t1] = call('GET', 'office', [], $gil);
eq([$t1['office']['pid'], isset($mem($gil)['אלה עובדת']), isset($mem($gil)['בתיה מנהלת'])], [$A, true, false], '01-C11 במשרד א: הצוות של א');
eq(call('POST', 'office-switch', ['office' => $B], $gil)[0], 200, 'החלפה למשרד ב');
[, $t2] = call('GET', 'office', [], $gil);
eq([$t2['office']['pid'], isset($mem($gil)['אלה עובדת']), isset($mem($gil)['בתיה מנהלת'])], [$B, false, true], '01-C11 אחרי החלפה: הצוות של ב');
eq(code(call('GET', 'office', [], $gil, ['office' => $A])), 'office_changed', 'לשונית ישנה על משרד א — "המשרד הוחלף", לא נתונים של המשרד הלא נכון');

// ── הסרה (מחיקה רכה), עזיבה, והצטרפות מחדש ──
eq(call('POST', 'member-remove', ['user' => $gilPid], $ella)[0], 200, 'הסרת חבר');
check('הסרה היא מחיקה רכה; החשבון נשאר', apr_val('SELECT deleted_at FROM memberships m JOIN users u ON u.id = m.user_id JOIN offices o ON o.id = m.office_id WHERE u.pid = ? AND o.pid = ?', [$gilPid, $A]) !== null
    && apr_val('SELECT 1 FROM users WHERE pid = ?', [$gilPid]));
eq(array_column(call('GET', 'me', [], $gil)[1]['offices'], 'pid'), [$B], 'מי שהוסר לא רואה עוד את המשרד');
join_office($ella, $gil, 'gil@a.co', 'appraiser');
eq(array_column(call('GET', 'me', [], $gil)[1]['offices'], 'role', 'pid')[$A], 'appraiser', 'הזמנה חדשה מחזירה את החברות, בתפקיד החדש');
eq(call('POST', 'office-leave', [], $avi)[0], 200, 'חבר רגיל עוזב את המשרד');
eq(call('GET', 'me', [], $avi)[1]['offices'], [], 'ואחרי העזיבה — בלי משרד');
eq(call('POST', 'office-rename', ['name' => 'משרד אלף'], $ella)[0], 200, 'מנהלת משנה את שם המשרד');
eq(call('GET', 'me', [], $ella)[1]['offices'][0]['name'], 'משרד אלף', 'השם החדש בכותרת');

// ── 01-C12: מנהל המערכת מקים משרד עם מייל של מנהל ראשון ──
$hila = ident('30', 'הילה', 'hila@c.co');
[$st, $so] = call('POST', 'sys-office-create', ['name' => 'משרד ג', 'admin_email' => 'HILA@c.co'], $owner);
$C = $so['office'] ?? '';
check('01-C12 נוצר משרד, עם אסימון להזמנה', $st === 200 && apr_is_pid($C) && preg_match('/^[a-f0-9]{48}$/', $so['token']) === 1, $so);
eq([apr_val('SELECT status FROM offices WHERE pid = ?', [$C]), (int)apr_val('SELECT COUNT(*) FROM memberships m JOIN offices o ON o.id = m.office_id WHERE o.pid = ?', [$C])], ['active', 0], '01-C12 משרד פעיל, בלי אף חבר');
eq(apr_row('SELECT i.email, i.is_admin, i.status FROM invitations i JOIN offices o ON o.id = i.office_id WHERE o.pid = ?', [$C]), ['email' => 'hila@c.co', 'is_admin' => 1, 'status' => 'pending'], '01-C12 והזמנה עם is_admin למייל של המנהל הראשון');
eq([call('GET', 'me', [], $owner)[1]['offices'], code(call('POST', 'office-switch', ['office' => $C], $owner)), code(call('GET', 'office', [], $owner, ['office' => $C]))], [[], 'not_found', 'not_found'], '01-C12 מנהל המערכת אינו חבר, ואינו יכול להחליף אליו');
eq(array_column(call('GET', 'sys-offices', [], $owner)[1]['offices'], 'no_active_members', 'pid')[$C], true, 'ברשימה שלו המשרד מסומן "בלי חבר פעיל" עד שהמנהל מצטרף');
eq(call('POST', 'invite-accept', ['token' => $so['token']], $hila)[0], 200, 'המנהלת הראשונה מקבלת את ההזמנה');
[, $meH] = call('GET', 'me', [], $hila);
eq([$meH['office'], $meH['offices'][0]['is_admin']], [$C, true], '01-C12 והיא מנהלת את המשרד');
eq(code(call('POST', 'sys-office-create', ['name' => 'x', 'admin_email' => 'y@y.co'], $batya)), 'not_found', 'מי שאינו מנהל המערכת — 404');
eq(code(call('POST', 'sys-office-create', ['name' => 'x', 'admin_email' => 'לא-מייל'], $owner)), 'email_invalid', 'מייל לא תקין');
eq((int)apr_val("SELECT COUNT(*) FROM offices WHERE name = 'x'"), 0, 'בקשה שנדחתה לא השאירה משרד בלי מנהל');

// ── 01-C13: office_self_create כבוי ← הקמה עצמית נדחית למי שאינו מנהל המערכת ──
eq(call('GET', 'me', [], $batya)[1]['can_create_office'], true, 'ברירת המחדל: הקמה עצמית דלוקה');
eq(call('POST', 'sys-setting-set', ['name' => 'office_self_create', 'value' => false], $owner)[0], 200, 'מנהל המערכת מכבה');
$r = call('POST', 'office-create', ['name' => 'לא אמור לקום', 'role' => 'staff'], $batya);
eq([$r[0], code($r), (int)apr_val("SELECT COUNT(*) FROM offices WHERE name = 'לא אמור לקום'")], [403, 'self_create_off', 0], '01-C13 הקמה עצמית נדחית');
eq(call('GET', 'me', [], $batya)[1]['can_create_office'], false, '01-C13 והמסך יודע להסביר במקום להציג את הטופס');
eq(call('POST', 'office-create', ['name' => 'של מנהל המערכת', 'role' => 'staff'], $owner)[0], 200, '01-C13 מנהל המערכת עדיין יכול');
eq(code(call('POST', 'sys-setting-set', ['name' => 'office_self_create', 'value' => true], $batya)), 'not_found', 'רק מנהל המערכת משנה הגדרות');
eq(code(call('POST', 'sys-setting-set', ['name' => 'no_such', 'value' => true], $owner)), 'setting_invalid', 'הגדרה לא מוכרת');
eq(code(call('POST', 'sys-setting-set', ['name' => 'office_self_create', 'value' => 'yes'], $owner)), 'setting_invalid', 'ערך שאינו בוליאני');
call('POST', 'sys-setting-set', ['name' => 'office_self_create', 'value' => true], $owner);
eq(call('GET', 'sys-settings', [], $owner)[1]['settings'], ['office_self_create' => true], 'ההגדרה חזרה');

// ── 01-C14: הזמנה אינה נתפסת בידי חשבון שהמייל שלו לא אומת ──
$inv = call('POST', 'invite-create', ['email' => 'victim@c.co', 'role' => 'appraiser', 'is_admin' => true], $hila)[1];
$squatter = ident('31', 'מתחזה', 'victim@c.co', ['email_verified' => false]);
[, $sq] = call('GET', 'my-invites', [], $squatter);
eq([$sq['verified'], $sq['invites']], [false, []], '01-C14 מייל לא מאומת: ההזמנה אינה מוצגת');
eq(code(call('POST', 'invite-accept', ['invite' => $inv['invite']], $squatter)), 'invite_invalid', '01-C14 ואינה מתקבלת בלי הקישור');
eq(apr_val('SELECT status FROM invitations WHERE pid = ?', [$inv['invite']]), 'pending', '01-C14 ההזמנה נשארת ממתינה');
eq(call('GET', 'me', [], $squatter)[1]['offices'], [], '01-C14 והמתחזה בלי משרד');

// ── ביקורת 01-align 🔴1: מייל שכבר אינו בזהות לא תופס הזמנה ──
$invR = call('POST', 'invite-create', ['email' => 'rina@c.co', 'role' => 'staff', 'is_admin' => true], $hila)[1];
$rina = fn(?string $email, bool $v = true) => ident('32', 'רינה', $email, ['email_verified' => $v]);
eq(count(call('GET', 'my-invites', [], $rina('rina@c.co'))[1]['invites']), 1, 'עם מייל מאומת — ההזמנה מוצגת');
[, $mi] = call('GET', 'my-invites', [], $rina(null));
eq([$mi['verified'], $mi['invites']], [false, []], '🔴1 הזהות חזרה בלי מייל ← ההזמנה כבר אינה מוצגת');
eq(code(call('POST', 'invite-accept', ['invite' => $invR['invite']], $rina(null))), 'invite_invalid', '🔴1 ואינה מתקבלת בלי הקישור');
eq(code(call('POST', 'invite-accept', ['token' => $invR['token']], $rina(null))), 'invite_other_email', '🔴1 וגם לא עם הקישור — אין לה מייל');
eq(code(call('POST', 'invite-accept', ['invite' => $invR['invite']], $rina('hila@c.co'))), 'invite_invalid', '🔴1 זהות שעברה למייל תפוס — לא נשארת עם הישן');
eq(apr_val('SELECT status FROM invitations WHERE pid = ?', [$invR['invite']]), 'pending', '🔴1 ההזמנה נשארת ממתינה');
// 🟡2: מי שתפס את הכתובת בלי לאמת לא חוסם את בעלת הכתובת
call('GET', 'me', [], $rina(null));
call('GET', 'me', [], ident('33', 'תופסת', 'rina@c.co', ['email_verified' => false]));
eq(call('POST', 'invite-accept', ['invite' => $invR['invite']], $rina('rina@c.co'))[0], 200, '🟡2 בעלת הכתובת המאומתת מקבלת את ההזמנה, גם אחרי שמישהי תפסה את הכתובת בלי לאמת');

// ── 🟡3 / 01-C7: חבר מושעה במשרד היחיד שלו ──
$solo = ident('34', 'סולו', 'solo@c.co');
$soloPid = join_office($hila, $solo, 'solo@c.co', 'staff', true);
eq(call('GET', 'office', [], $solo)[0], 200, 'לפני ההשעיה — עובד');
call('POST', 'member-update', ['user' => $soloPid, 'status' => 'suspended'], $hila);
[, $meS] = call('GET', 'me', [], $solo);
eq([$meS['office'], $meS['offices'][0]['status']], [$C, 'suspended'], '01-C7 המשרד היחיד שלו, והחברות מושעית');
foreach ([['GET', 'office', []], ['POST', 'office-leave', []], ['GET', 'office-audit', []], ['POST', 'invite-create', ['email' => 'w@c.co', 'role' => 'staff']],
          ['POST', 'office-rename', ['name' => 'שלי']], ['POST', 'member-update', ['user' => $soloPid, 'status' => 'active']]] as [$m, $a, $body]) {
    $r = call($m, $a, $body, $solo);
    eq([$r[0], code($r)], [403, 'forbidden'], "01-C7 חבר מושעה במשרד היחיד שלו: $a → 403");
}
eq(apr_val('SELECT m.status FROM memberships m JOIN users u ON u.id = m.user_id WHERE u.pid = ?', [$soloPid]), 'suspended', '01-C7 ולא ביטל לעצמו את ההשעיה');

// ── 🟡7: הזמנה למשרד מושעה אינה מתקבלת ואינה מוצגת ──
$invS = call('POST', 'invite-create', ['email' => 'late@c.co', 'role' => 'staff'], $hila)[1];
$late = ident('35', 'מאחר', 'late@c.co');
call('POST', 'sys-office-status', ['office' => $C, 'status' => 'suspended', 'reason' => 'בדיקה'], $owner);
eq(call('GET', 'my-invites', [], $late)[1]['invites'], [], 'הזמנה למשרד מושעה אינה מוצגת');
eq([code(call('POST', 'invite-info', ['token' => $invS['token']], $late)), code(call('POST', 'invite-accept', ['token' => $invS['token']], $late)), code(call('POST', 'invite-accept', ['invite' => $invS['invite']], $late))],
    ['invite_invalid', 'invite_invalid', 'invite_invalid'], 'ואינה מתקבלת — באותו נוסח כמו הזמנה שאינה בתוקף');
call('POST', 'sys-office-status', ['office' => $C, 'status' => 'active', 'reason' => 'סוף בדיקה'], $owner);
eq(call('POST', 'invite-accept', ['token' => $invS['token']], $late)[0], 200, 'אחרי ביטול ההשעיה — מתקבלת');

// ── המזהה הפנימי לא יוצא מהשרת ──
$all = json_encode([call('GET', 'me', [], $ella)[1], call('GET', 'office', [], $ella)[1], call('GET', 'office-audit', [], $ella)[1], call('GET', 'sys-offices', [], $owner)[1], call('GET', 'sys-users', [], $owner)[1], call('GET', 'my-invites', [], $gil)[1]]);
check('אין בתשובות מספר פנימי (id, user_id, office_id …)', !preg_match('/"(id|user_id|office_id|membership_id|actor_user_id|invited_by)"\s*:/', $all), $all);

done();
