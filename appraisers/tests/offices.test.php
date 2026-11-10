<?php
// משרדים, חברים ותפקידים — ובעיקר: משרד לא רואה ולא נוגע במשרד אחר, ומנהל הפלטפורמה לא רואה תוכן של משרד.
require __DIR__ . '/lib.php';

$owner = ident('1', 'הבעלים', 'owner@example.com', ['platform_admin' => true]);
$adminA = ident('10', 'אבי מנהל', 'avi@a.co');
$adminB = ident('20', 'בתיה מנהלת', 'batya@b.co');
$staffA = ident('11', 'אלה עובדת', 'ella@a.co');

// ── רק מנהל הפלטפורמה פותח משרדים ──
call('GET', 'me', [], $adminA);
eq(call('POST', 'office-create', ['name' => 'פיראטי', 'admin_email' => 'avi@a.co'], $adminA)[0], 403, 'משתמש רגיל לא פותח משרד');
eq(call('GET', 'offices', [], $adminA)[0], 403, 'משתמש רגיל לא רואה את רשימת המשרדים');

[$st, $a] = call('POST', 'office-create', ['name' => '  משרד   א  ', 'admin_email' => 'AVI@a.co'], $owner);
eq([$st, $a['admin']['pending']], [200, false], 'משרד א נפתח, המנהל — חשבון קיים');
[$st, $b] = call('POST', 'office-create', ['name' => 'משרד ב', 'admin_email' => 'batya@b.co', 'admin_name' => 'בתיה'], $owner);
eq([$st, $b['admin']['pending']], [200, true], 'משרד ב נפתח, המנהלת — הוזמנה (עוד לא נכנסה)');
eq(call('POST', 'office-create', ['name' => '', 'admin_email' => 'x@x.co'], $owner)[0], 400, 'בלי שם — 400');
eq(call('POST', 'office-create', ['name' => 'ג', 'admin_email' => 'לא-מייל'], $owner)[0], 400, 'מייל לא תקין — 400');
eq((int)apr_row('SELECT COUNT(*) AS n FROM offices')['n'], 2, 'בקשה שנדחתה לא השאירה משרד בלי מנהל');
[$A, $B] = [$a['id'], $b['id']];
eq(apr_row('SELECT name FROM offices WHERE id = ?', [$A])['name'], 'משרד א', 'השם מנוקה מרווחים');

// ── המנהלת שהוזמנה נכנסת — ומקבלת את המשרד שלה ──
[, $meB] = call('GET', 'me', [], $adminB);
eq([$meB['office']['id'] ?? null, $meB['office']['role'] ?? null], [$B, 'office_admin'], 'ההזמנה התקשרה: בתיה מנהלת את משרד ב');
[, $meA] = call('GET', 'me', [], $adminA);
eq([$meA['office']['id'], count($meA['offices'])], [$A, 1], 'אבי — רק משרד א');

// ── מנהל הפלטפורמה: מנהל משרדים, בלי גישה לתוכן ──
[, $meO] = call('GET', 'me', [], $owner);
eq($meO['office'], null, 'מנהל הפלטפורמה אינו חבר באף משרד');
eq(call('GET', 'members', [], $owner)[0], 403, 'ואינו רואה את חברי המשרדים');
eq(call('GET', 'office-audit', [], $owner)[0], 403, 'ולא את יומן הפעולות של משרד');
[, $all] = call('GET', 'offices', [], $owner);
eq(array_column($all['offices'], 'members', 'name'), ['משרד א' => 1, 'משרד ב' => 1], 'רשימת המשרדים: שם ומספר חברים בלבד');

// ── מנהל משרד מזמין צוות ──
[$st, $inv] = call('POST', 'member-invite', ['email' => 'ella@a.co', 'name' => 'אלה', 'role' => 'staff'], $adminA);
eq([$st, $inv['pending']], [200, true], 'הזמנת עובדת למשרד א');
eq(call('POST', 'member-invite', ['email' => 'ella@a.co', 'role' => 'staff'], $adminA)[0], 409, 'הזמנה כפולה — 409');
eq(call('POST', 'member-invite', ['email' => 'x@a.co', 'role' => 'emperor'], $adminA)[0], 400, 'תפקיד לא מוכר — 400');
call('GET', 'me', [], $staffA);
eq(call('POST', 'member-invite', ['email' => 'y@a.co', 'role' => 'staff'], $staffA)[0], 403, 'עובדת לא מזמינה');
[, $team] = call('GET', 'members', [], $staffA);
eq([count($team['members']), $team['can_manage']], [2, false], 'עובדת רואה את הצוות שלה, בלי ניהול');
$ella = $inv['user_id'];
$avi = $meA['user']['id'];
$batya = $meB['user']['id'];

// ── הבידוד: משרד ב לא רואה ולא נוגע במשרד א ──
[, $teamB] = call('GET', 'members', [], $adminB);
eq(array_column($teamB['members'], 'name'), ['בתיה מנהלת'], 'משרד ב רואה רק את עצמו');
check('אין שום פרט של משרד א בתשובה למשרד ב', !str_contains(json_encode($teamB, JSON_UNESCAPED_UNICODE), 'a.co'));
eq(call('POST', 'member-update', ['user_id' => $ella, 'role' => 'office_admin'], $adminB)[0], 404, 'מנהלת ב משנה חבר של א — "לא נמצא"');
eq(call('POST', 'member-remove', ['user_id' => $ella], $adminB)[0], 404, 'מנהלת ב מסירה חבר של א — "לא נמצא"');
eq(call('POST', 'member-update', ['user_id' => $ella, 'role' => 'appraiser', 'office_id' => $A], $adminB)[0], 404, 'גם עם office_id של א בבקשה — המשרד נקבע בשרת');
eq(call('GET', 'members', [], $adminB, ['office_id' => $A])[1]['office']['id'], $B, 'office_id בכתובת לא משנה את המשרד');
eq(call('POST', 'office-switch', ['office_id' => $A], $adminB)[0], 404, 'מעבר למשרד שאינני חברה בו — 404');
eq(call('POST', 'office-switch', ['office_id' => 9999], $adminB)[0], 404, 'ומשרד שלא קיים — אותה תשובה');
eq(apr_row('SELECT role FROM memberships WHERE office_id = ? AND user_id = ?', [$A, $ella])['role'], 'staff', 'שום דבר לא השתנה במשרד א');
eq(call('POST', 'office-update', ['id' => $A, 'status' => 'suspended'], $adminB)[0], 403, 'מנהלת משרד לא משעה משרד');

// יומן הפעולות — כל משרד רואה רק את שלו
[, $logA] = call('GET', 'office-audit', [], $adminA);
[, $logB] = call('GET', 'office-audit', [], $adminB);
check('יומן משרד א — רק משרד א', $logA['rows'] && !array_filter($logA['rows'], fn($r) => $r['office_id'] !== $A));
check('יומן משרד ב — רק משרד ב', $logB['rows'] && !array_filter($logB['rows'], fn($r) => $r['office_id'] !== $B));
check('ההזמנה של אלה ביומן של א', (bool)array_filter($logA['rows'], fn($r) => $r['action'] === 'member.added' && $r['target'] === "user:$ella" && $r['actor'] === 'אבי מנהל'));
eq(call('GET', 'office-audit', [], $staffA)[0], 403, 'עובדת לא רואה את יומן הפעולות');
eq(call('GET', 'dev-audit', [], $adminA)[0], 403, 'מנהל משרד לא רואה את היומן של כל האפליקציה');

// ── שינוי תפקיד, השעיה והסרה ──
eq(call('POST', 'member-update', ['user_id' => $ella, 'role' => 'appraiser'], $adminA)[0], 200, 'שינוי תפקיד');
eq(call('GET', 'me', [], $staffA)[1]['office']['role'], 'appraiser', 'התפקיד החדש חל מהבקשה הבאה');
eq(call('POST', 'member-update', ['user_id' => $avi, 'role' => 'staff'], $adminA)[0], 409, 'מנהל המשרד היחיד לא מוריד את עצמו');
eq(call('POST', 'member-update', ['user_id' => $avi, 'status' => 'suspended'], $adminA)[0], 409, 'ולא משעה את עצמו');
eq(call('POST', 'member-remove', ['user_id' => $avi], $adminA)[0], 409, 'ולא מסיר את עצמו');
eq(call('POST', 'member-update', ['user_id' => $ella, 'role' => 'office_admin'], $adminA)[0], 200, 'מינוי מנהלת נוספת');
eq(call('POST', 'member-update', ['user_id' => $avi, 'role' => 'appraiser'], $adminA)[0], 200, 'עכשיו אפשר — יש מנהלת אחרת');
eq(call('POST', 'member-invite', ['email' => 'z@a.co', 'role' => 'staff'], $adminA)[0], 403, 'ומי שירד מניהול — כבר לא מזמין');
eq(call('POST', 'member-update', ['user_id' => $avi, 'status' => 'suspended'], $staffA)[0], 200, 'המנהלת החדשה משעה את אבי');
[, $meA2] = call('GET', 'me', [], $adminA);
eq([$meA2['office'], $meA2['offices']], [null, []], 'חבר מושעה — בלי משרד');
eq(call('GET', 'members', [], $adminA)[0], 403, 'ולא רואה את הצוות');
eq(call('POST', 'member-remove', ['user_id' => $avi], $staffA)[0], 200, 'הסרה מהמשרד');
check('החשבון עצמו נשאר (רק החברות הוסרה)', (bool)apr_row('SELECT 1 AS x FROM users WHERE id = ?', [$avi]));

// ── אדם בשני משרדים: ההקשר נבחר במפורש ──
call('POST', 'member-invite', ['email' => 'ella@a.co', 'role' => 'associate'], $adminB);
[, $meE] = call('GET', 'me', [], $staffA);
eq([count($meE['offices']), $meE['office']], [2, null], 'שני משרדים ולא נבחר אחד — אין משרד פעיל, ואין ניחוש');
eq(call('GET', 'members', [], $staffA)[0], 403, 'ובלי בחירה אין גישה לנתוני משרד');
eq(call('POST', 'office-switch', ['office_id' => $B], $staffA)[0], 200, 'בחירת משרד ב');
[, $meE] = call('GET', 'me', [], $staffA);
eq([$meE['office']['id'], $meE['office']['role']], [$B, 'associate'], 'עכשיו במשרד ב, בתפקיד שלה שם');
eq(call('POST', 'member-invite', ['email' => 'q@b.co', 'role' => 'staff'], $staffA)[0], 403, 'מנהלת בא׳ אינה מנהלת בב׳');
call('POST', 'office-switch', ['office_id' => $A], $staffA);
eq(call('POST', 'member-invite', ['email' => 'q@a.co', 'role' => 'staff'], $staffA)[0], 200, 'ובחזרה בא׳ — מנהלת');

// ── השעיית משרד ──
eq(call('POST', 'office-update', ['id' => $B, 'status' => 'suspended'], $owner)[0], 200, 'מנהל הפלטפורמה משעה את משרד ב');
[, $meB2] = call('GET', 'me', [], $adminB);
eq($meB2['office'], null, 'משרד מושעה — החברים בלי משרד פעיל');
eq(call('GET', 'members', [], $adminB)[0], 403, 'ובלי גישה לנתונים שלו');
eq(call('POST', 'office-update', ['id' => $B, 'status' => 'active', 'name' => 'משרד ב החדש'], $owner)[0], 200, 'החזרה ושינוי שם');
eq(call('GET', 'me', [], $adminB)[1]['office']['name'], 'משרד ב החדש', 'המשרד חזר');
eq(call('POST', 'office-update', ['id' => 777, 'name' => 'x'], $owner)[0], 404, 'משרד שלא קיים — 404');

// ── מנהל הפלטפורמה שרוצה לעבוד במשרד: חברות מפורשת ומתועדת ──
[$st, $mine] = call('POST', 'office-create', ['name' => 'המשרד שלי', 'admin_email' => 'owner@example.com'], $owner);
eq(call('GET', 'me', [], $owner)[1]['office']['id'] ?? null, $mine['id'], 'הבעלים חבר במשרד שפתח לעצמו');
check('והחברות רשומה ביומן הפעולות', (bool)apr_row("SELECT 1 AS x FROM audit_log WHERE action = 'member.added' AND office_id = ?", [$mine['id']]));
eq(call('GET', 'members', [], $owner)[1]['office']['id'], $mine['id'], 'ורואה רק את המשרד הזה');

done();
