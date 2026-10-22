<?php
/**
 * בדיקות: זהות מהפלטפורמה, חברה, סניפים, צוות והפרדה בין חברות (שלב 1);
 * עובדים, תנאים בגרסאות, קישור הזמנה, הפעלה וקוד, מכשיר והרשאות מנהל סניף (שלב 2);
 * דיווח, חישוב שעות (משמרת לילה, שעון קיץ, חסר יציאה, כפילות), החודש והמסך "היום" (שלב 3);
 * עמדה: הצמדה, קוד שגוי ונעילה, סניף, ביטול (שלב 4);
 * תיקונים: בקשה, אישור ודחייה, תיקון ישיר, המקור לא משתנה, הרשאות (שלב 5).
 * הרצה: php time-clock/tests/run.php — על מסד זמני, בלי לגעת ב-data/.
 */

declare(strict_types=1);

define('DB_FILE', sys_get_temp_dir() . '/time-clock-test-' . getmypid() . '.sqlite');
require_once __DIR__ . '/../lib/attendance.php';
require_once __DIR__ . '/../lib/station.php';
require_once __DIR__ . '/../lib/corrections.php';

$fails = 0; $n = 0;
function check(string $name, bool $cond): void {
    global $fails, $n; $n++;
    echo ($cond ? '✓ ' : '✗ ') . $name . "\n";
    if (!$cond) $fails++;
}
function throws(string $name, callable $fn, int $status = 0): void {
    try { $fn(); check($name, false); }
    catch (AppError $e) {
        if (db()->inTransaction()) db()->rollBack(); check($name . ' — ' . $e->getMessage(), $status === 0 || $e->status === $status); }
}
function as_(array $id): array { $_SERVER['PLATFORM_USER'] = json_encode($id); return currentPerson(); }

// בלי זהות — 401
unset($_SERVER['PLATFORM_USER']);
throws('בלי פלטפורמה — אין כניסה', fn() => currentPerson(), 401);

$owner = as_(['sub' => 'u-owner', 'name' => 'רונית', 'email' => 'ronit@example.com', 'email_verified' => true]);
check('אדם נפתח בכניסה הראשונה', $owner['name'] === 'רונית');
check('כניסה שנייה — אותו אדם', as_(['sub' => 'u-owner'])['id'] === $owner['id']);
check('אין חברות עדיין', myCompanies($owner) === []);

throws('חברה בלי שם', fn() => companyCreate($owner, ['name' => '', 'days_per_week' => 5, 'daily_minutes' => 504]));
throws('7 ימים בשבוע', fn() => companyCreate($owner, ['name' => 'א', 'days_per_week' => 7, 'daily_minutes' => 504]));
throws('ח.פ. לא תקין', fn() => companyCreate($owner, ['name' => 'א', 'tax_id' => '12ab', 'days_per_week' => 5, 'daily_minutes' => 504]));
$c = companyCreate($owner, ['name' => 'מאפיית השקד', 'tax_id' => '512345678', 'days_per_week' => 6, 'daily_minutes' => 480]);
$cid = $c['company']['id'];
check('חברה נפתחה והפותח בעלים', $c['my_role'] === 'owner' && $c['company']['days_per_week'] === 6);
check('החברה ברשימה שלי', count(myCompanies($owner)) === 1);

$c = siteSave($owner, ['company_id' => $cid, 'name' => 'סניף מרכז']);
$siteId = $c['sites'][0]['id'];
check('סניף נוסף', $c['sites'][0]['name'] === 'סניף מרכז');

throws('מנהל סניף בלי סניף', fn() => teamAdd($owner, ['company_id' => $cid, 'name' => 'דוד', 'email' => 'david@example.com', 'role' => 'branch_manager']), 404);
throws('תפקיד בעלים לא ניתן מהצוות', fn() => teamAdd($owner, ['company_id' => $cid, 'name' => 'דוד', 'email' => 'david@example.com', 'role' => 'owner']));
$c = teamAdd($owner, ['company_id' => $cid, 'name' => 'דוד', 'email' => 'David@Example.com', 'role' => 'branch_manager', 'site_id' => $siteId]);
check('מנהל סניף נוסף, עדיין לא מקושר', count($c['team']) === 2 && $c['team'][1]['linked'] === false);
throws('אותו אדם פעמיים', fn() => teamAdd($owner, ['company_id' => $cid, 'name' => 'דוד', 'email' => 'david@example.com', 'role' => 'payroll']));
throws('אי אפשר למחוק סניף שיש בו אנשים', fn() => siteDelete($owner, ['company_id' => $cid, 'id' => $siteId]));

// מייל לא מאומת לא מקשר — נפתח אדם חדש בלי חברות
$fake = as_(['sub' => 'u-fake', 'name' => 'מתחזה', 'email' => 'david@example.com', 'email_verified' => false]);
check('מייל לא מאומת — לא מקבל את המקום בצוות', myCompanies($fake) === []);
$david = as_(['sub' => 'u-david', 'name' => 'David', 'email' => 'david@example.com', 'email_verified' => true]);
$mine = myCompanies($david);
check('מייל מאומת — מקושר ורואה את החברה כמנהל סניף', count($mine) === 1 && $mine[0]['role'] === 'branch_manager' && $mine[0]['site_id'] === $siteId);

throws('מנהל סניף לא משנה פרטי חברה', fn() => companyUpdate($david, ['company_id' => $cid, 'name' => 'x', 'days_per_week' => 5, 'daily_minutes' => 480]), 403);
throws('מנהל סניף לא מוסיף לצוות', fn() => teamAdd($david, ['company_id' => $cid, 'name' => 'x', 'email' => 'x@example.com', 'role' => 'payroll']), 403);
check('מנהל סניף רואה את פרטי החברה', companyGet($david, $cid)['my_role'] === 'branch_manager');

// הפרדה בין חברות
$other = as_(['sub' => 'u-other', 'name' => 'אחר', 'email' => 'o@example.com', 'email_verified' => true]);
$oc = companyCreate($other, ['name' => 'חברה אחרת', 'days_per_week' => 5, 'daily_minutes' => 504])['company']['id'];
throws('בעלים של חברה אחת לא רואה חברה אחרת', fn() => companyGet($owner, $oc), 403);
throws('סניף של חברה אחרת לא זמין', fn() => teamAdd($other, ['company_id' => $oc, 'name' => 'y', 'email' => 'y@example.com', 'role' => 'branch_manager', 'site_id' => $siteId]), 404);

$c = companyUpdate($owner, ['company_id' => $cid, 'name' => 'מאפיית השקד בע״מ', 'tax_id' => '', 'days_per_week' => 5, 'daily_minutes' => 504]);
check('פרטי החברה עודכנו', $c['company']['name'] === 'מאפיית השקד בע״מ' && $c['company']['tax_id'] === null);
$davidMember = $c['team'][1]['id'];
$c = teamUpdate($owner, ['company_id' => $cid, 'id' => $davidMember, 'role' => 'payroll']);
check('שינוי תפקיד — בלי סניף', $c['team'][1]['role'] === 'payroll' && $c['team'][1]['site_id'] === null);
throws('את הבעלים לא מסירים', fn() => teamRemove($owner, ['company_id' => $cid, 'id' => $c['team'][0]['id']]));
$c = teamRemove($owner, ['company_id' => $cid, 'id' => $davidMember]);
check('הוסר מהצוות', count($c['team']) === 1 && myCompanies($david) === []);
$c = siteDelete($owner, ['company_id' => $cid, 'id' => $siteId]);
check('סניף ריק נמחק', $c['sites'] === []);
$log = (int) db()->query("SELECT COUNT(*) FROM audit WHERE company_id = $cid")->fetchColumn();
check('כל שינוי ביומן', $log >= 7);


// ===== שלב 2: עובדים =====
$owner = as_(['sub' => 'u-owner']);
$c = siteSave($owner, ['company_id' => $cid, 'name' => 'מרכז']);
$s1 = $c['sites'][0]['id'];
$c = siteSave($owner, ['company_id' => $cid, 'name' => 'צפון']);
$s2 = array_values(array_filter($c['sites'], fn($x) => $x['name'] === 'צפון'))[0]['id'];
$base = ['company_id' => $cid, 'days_per_week' => 5, 'daily_minutes' => 504, 'pay_type' => 'hourly'];

throws('עובד בלי שם', fn() => empAdd($owner, $base + ['name' => '']));
throws('טלפון לא תקין', fn() => empAdd($owner, $base + ['name' => 'דנה', 'phone' => '123']));
$r = empAdd($owner, $base + ['name' => 'דנה כהן', 'phone' => '050-123-4567', 'employee_no' => '7', 'site_id' => $s1, 'start_date' => '2026-10-01']);
$dana = $r['employee']['id']; $tok = $r['invite_token'];
check('עובד נוסף עם קישור ותנאים מתאריך ההתחלה', $r['employee']['phone'] === '972501234567' && $r['employee']['invite'] === 'pending'
      && $r['terms_history'][0]['effective_from'] === '2026-10-01' && $r['employee']['terms']['pay_type'] === 'hourly');
throws('מספר עובד כפול', fn() => empAdd($owner, $base + ['name' => 'אחר', 'employee_no' => '7']));
check('עובד אינו בצוות הניהול', count(companyGet($owner, $cid)['team']) === 1);

// מנהל סניף: רק הסניף שלו
$c = teamAdd($owner, ['company_id' => $cid, 'name' => 'יוסי', 'email' => 'yossi@example.com', 'role' => 'branch_manager', 'site_id' => $s2]);
$yossi = as_(['sub' => 'u-yossi', 'email' => 'yossi@example.com', 'email_verified' => true]);
check('מנהל סניף לא רואה עובד מסניף אחר', empList($yossi, ['company_id' => $cid])['employees'] === []);
throws('מנהל סניף לא פותח עובד מסניף אחר', fn() => empGet($yossi, ['company_id' => $cid, 'id' => $dana]), 404);
$r = empAdd($yossi, $base + ['name' => 'עומר', 'site_id' => $s1]);
check('עובד שמנהל סניף מוסיף — תמיד בסניף שלו', $r['employee']['site_id'] === $s2);
$omer = $r['employee']['id'];

// הפעלה
throws('קישור מזויף', fn() => empInviteInfo(['token' => str_repeat('a', 43)]), 410);
check('פרטי הקישור', empInviteInfo(['token' => $tok]) === ['name' => 'דנה כהן', 'company' => 'מאפיית השקד בע״מ']);
throws('קוד קל מדי (1234)', fn() => empActivate(['token' => $tok, 'pin' => '1234']));
throws('קוד קל מדי (7777)', fn() => empActivate(['token' => $tok, 'pin' => '7777']));
throws('קוד של 3 ספרות', fn() => empActivate(['token' => $tok, 'pin' => '482']));
$_COOKIE = [];
$st = empActivateAndLogin(['token' => $tok, 'pin' => '4826']);
check('הופעל: המכשיר מחובר לדנה', count($st['memberships']) === 1 && $st['memberships'][0]['name'] === 'דנה כהן');
throws('קישור שנוצל', fn() => empActivate(['token' => $tok, 'pin' => '4826']), 410);
check('הקוד נשמר כ-hash', password_verify('4826', (string) db()->query("SELECT pin_hash FROM members WHERE id = $dana")->fetchColumn()));
check('מצב ההזמנה: פעיל', empGet($owner, ['company_id' => $cid, 'id' => $dana])['employee']['invite'] === 'active');

// עובד בשתי חברות — שני אסימונים באותה עוגייה
$r2 = empAdd($other, ['company_id' => $oc, 'name' => 'דנה כהן', 'days_per_week' => 6, 'daily_minutes' => 420]);
$st = empActivateAndLogin(['token' => $r2['invite_token'], 'pin' => '9071']);
check('אותו מכשיר — שתי חברות', count($st['memberships']) === 2);

// טלפון חדש: קישור חדש מבטל את המכשיר הקודם — רק בחברה הזו
$r = empInvite($owner, ['company_id' => $cid, 'id' => $dana]);
$st = empState();
check('קישור חדש ניתק את המכשיר בחברה הזו בלבד', count($st['memberships']) === 1 && $st['memberships'][0]['company'] === 'חברה אחרת');
check('והעוגייה נוקתה מהאסימון שבוטל', count(sessionTokens()) === 1);

// תנאים בגרסאות
$r = empTermsAdd($owner, ['company_id' => $cid, 'id' => $dana, 'effective_from' => '2027-01-01', 'days_per_week' => 6, 'daily_minutes' => 420, 'pay_type' => 'monthly']);
check('גרסה עתידית לא משנה את התקף היום', count($r['terms_history']) === 2 && $r['employee']['terms']['days_per_week'] === 5);
check('בתאריך התחולה — הגרסה החדשה', (int) termsAt($dana, '2027-01-02')['daily_minutes'] === 420);
$r = empTermsAdd($owner, ['company_id' => $cid, 'id' => $dana, 'effective_from' => '2027-01-01', 'days_per_week' => 6, 'daily_minutes' => 480, 'pay_type' => 'monthly']);
check('אותו תאריך — מתקן ולא מכפיל', count($r['terms_history']) === 2 && $r['terms_history'][0]['daily_minutes'] === 480);
throws('מנהלת שכר לא עורכת', function () use ($cid, $dana) {
    global $owner;
    teamAdd($owner, ['company_id' => $cid, 'name' => 'שרה', 'email' => 'sara@example.com', 'role' => 'payroll']);
    $sara = as_(['sub' => 'u-sara', 'email' => 'sara@example.com', 'email_verified' => true]);
    check('מנהלת שכר רואה עובדים', count(empList($sara, ['company_id' => $cid])['employees']) === 2);
    empUpdate($sara, ['company_id' => $cid, 'id' => $dana, 'name' => 'x']);
}, 403);
$r = empUpdate($owner, ['company_id' => $cid, 'id' => $dana, 'name' => 'דנה לוי', 'phone' => '', 'employee_no' => '7', 'site_id' => $s1, 'start_date' => '2026-10-01']);
check('עדכון פרטים', $r['employee']['name'] === 'דנה לוי' && $r['employee']['phone'] === null);

// סיום העסקה
$r = empEnd($owner, ['company_id' => $cid, 'id' => $omer, 'end_date' => '2026-10-31']);
check('סיום העסקה', $r['employee']['status'] === 'left' && $r['employee']['invite'] === 'none');
throws('לעובד שסיים אין קישור חדש', fn() => empInvite($owner, ['company_id' => $cid, 'id' => $omer]));
throws('סניף עם עובדים לא נמחק', fn() => siteDelete($owner, ['company_id' => $cid, 'id' => $s1]));


// ===== שלב 3: דיווח ושעות =====
$tz = 'Asia/Jerusalem';
$h = empAdd($owner, ['company_id' => $cid, 'name' => 'שעות', 'site_id' => $s1, 'start_date' => '2026-01-01', 'days_per_week' => 5, 'daily_minutes' => 480])['employee']['id'];
$z = fn(string $local) => (new DateTimeImmutable($local, new DateTimeZone($tz)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

recordPunch($h, 'in', 'phone', null, null, $z('2026-11-10 08:00'));
throws('לחיצה כפולה בתוך דקה', fn() => recordPunch($h, 'in', 'phone', null, null, $z('2026-11-10 08:00:30')), 409);
recordPunch($h, 'out', 'phone', null, null, $z('2026-11-10 16:30'));
recordPunch($h, 'in', 'phone', null, null, $z('2026-11-10 22:00'));
recordPunch($h, 'out', 'phone', null, null, $z('2026-11-11 06:00'));
recordPunch($h, 'in', 'phone', null, null, $z('2026-11-12 08:00'));             // שכח לצאת
recordPunch($h, 'in', 'phone', null, null, $z('2026-11-13 08:00'));
recordPunch($h, 'out', 'phone', null, null, $z('2026-11-13 17:00'));
recordPunch($h, 'out', 'phone', null, null, $z('2026-11-15 12:00'));            // יציאה בלי כניסה
$d = daysReport($h, '2026-11-10', '2026-11-15', $tz, $z('2026-11-20 12:00'));
check('יום רגיל + משמרת לילה — הכול ביום ההתחלה', $d[0]['minutes'] === 510 + 480 && count($d[0]['pairs']) === 2);
check('משמרת לילה לא נספרת ביום שאחריו', $d[1]['minutes'] === 0);
check('והיציאה שלה מוצגת ביום ההתחלה, לא ביום שאחריו', count($d[0]['punches']) === 4 && $d[1]['punches'] === []);
check('כניסה בלי יציאה — חסר יציאה, 0 דקות', $d[2]['missing_out'] === 1 && $d[2]['minutes'] === 0);
check('היום שאחריו תקין', $d[3]['minutes'] === 540);
check('יציאה בלי כניסה — חסר כניסה', $d[5]['missing_in'] === 1);
check('תקן: חמישי 8:00, שישי ושבת 0 (5 ימים)', $d[2]['standard'] === 480 && $d[4]['standard'] === 0 && (int) standardMinutes($h, '2026-11-14') === 0);

// שעון קיץ: הלילה שבו השעון קופץ קדימה (26.3.2027) — 8 שעות על השעון הן 7 בפועל
recordPunch($h, 'in', 'phone', null, null, $z('2027-03-25 22:00'));
recordPunch($h, 'out', 'phone', null, null, $z('2027-03-26 06:00'));
check('מעבר לשעון קיץ — 420 דקות ולא 480', daysReport($h, '2027-03-25', '2027-03-25', $tz, $z('2027-04-01 12:00'))[0]['minutes'] === 420);

$mr = monthReport($h, '2026-11', $tz, $z('2026-11-30 20:00'));
check('סיכום חודש: ימים, דקות, חוסרים', $mr['summary']['days'] === 2 && $mr['summary']['minutes'] === 510 + 480 + 540 && $mr['summary']['missing'] === 2);
check('תקן החודש — 22 ימי עבודה (א׳–ה׳) בנובמבר 2026', $mr['summary']['standard'] === 22 * 480 && $mr['summary']['diff'] === 1530 - 22 * 480);
throws('חודש לא תקין', fn() => monthReport($h, '2026-13', $tz));

// עכשיו: בעבודה מאז
recordPunch($h, 'in', 'phone', null, null, $z('2027-05-02 08:00'));
$ns = nowStatus($h, $tz, $z('2027-05-02 10:15'));
check('בעבודה: הכפתור הבא יציאה, 135 דקות היום', $ns['next'] === 'out' && $ns['minutes_today'] === 135 && count($ns['punches']) === 1);
check('כניסה פתוחה מלפני יותר מ-16 שעות — הכפתור הבא שוב כניסה', nowStatus($h, $tz, $z('2027-05-03 09:00'))['next'] === 'in');

// מהטלפון: רק חברוּת שהמכשיר מחובר אליה
$_COOKIE = [];
$t3 = empInvite($owner, ['company_id' => $cid, 'id' => $dana])['invite_token'];
$st = empActivateAndLogin(['token' => $t3, 'pin' => '5839']);
$mine = array_values(array_filter($st['memberships'], fn($m) => $m['id'] === $dana))[0];
check('emp_state מחזיר מצב — הכפתור הבא כניסה', $mine['status']['next'] === 'in');
$r = empPunch(['member_id' => $dana, 'kind' => 'in']);
check('כניסה מהטלפון', $r['status']['next'] === 'out' && $r['status']['open_since'] !== null);
throws('עובד אחר — לא מהמכשיר הזה', fn() => empPunch(['member_id' => $h, 'kind' => 'in']), 401);
check('החודש שלי', count(empMonth(['member_id' => $dana])['days']) >= 28);

// "היום" אצל המנהל
$b = todayBoard($owner, ['company_id' => $cid]);
$row = array_values(array_filter($b['employees'], fn($e) => $e['id'] === $dana))[0];
check('היום: דנה בעבודה', $row['state'] === 'in' && $b['count']['in'] >= 1);
check('עובד שסיים לא מופיע', !in_array($omer, array_column($b['employees'], 'id'), true));
throws('מנהל סניף אחר לא רואה את החודש של דנה', fn() => empMonthManager($yossi, ['company_id' => $cid, 'id' => $dana]), 404);
check('מנהל רואה את החודש של עובד', empMonthManager($owner, ['company_id' => $cid, 'id' => $h, 'month' => '2026-11'])['summary']['minutes'] === 1530);


// ===== שלב 4: עמדה =====
$_COOKIE = [];
throws('בלי הצמדה — אין עמדה', fn() => stationState(), 401);
throws('מנהלת שכר לא יוצרת עמדה', fn() => stationCreate(as_(['sub' => 'u-sara']), ['company_id' => $cid, 'name' => 'x']), 403);
$owner = as_(['sub' => 'u-owner']);
$r = stationCreate($owner, ['company_id' => $cid, 'name' => 'כניסה ראשית', 'site_id' => $s1]);
$code = $r['code']; $stId = $r['station_id'];
check('נוצרה עמדה עם קוד בן 6 ספרות, עוד לא מוצמדת', strlen($code) === 6 && $r['stations'][0]['paired'] === false);
throws('קוד הצמדה שגוי', fn() => stationPair(['code' => $code === '000000' ? '111111' : '000000']));
writeStationCookie(stationPair(['code' => $code]));
$ss = stationState();
check('מוצמדת: שם החברה והסניף', $ss['station']['site'] === 'מרכז' && $ss['station']['company'] === 'מאפיית השקד בע״מ');
$names = array_column($ss['employees'], 'name');
check('בעמדה רק עובדי הסניף שלה, פעילים', in_array('דנה לוי', $names, true) && !in_array('עומר', $names, true));
throws('אותו קוד הצמדה פעמיים', fn() => stationPair(['code' => $code]));

throws('עובד מסניף אחר', fn() => stationCheck(['member_id' => $r2['employee']['id'], 'pin' => '9071']), 404);
throws('קוד אישי שגוי', fn() => stationCheck(['member_id' => $dana, 'pin' => '0001']));
$c1 = stationCheck(['member_id' => $dana, 'pin' => '5839']);
check('קוד נכון: השם והפעולה הצפויה', $c1['name'] === 'דנה לוי' && in_array($c1['next'], ['in', 'out'], true));
$p = stationPunch(['member_id' => $dana, 'pin' => '5839', 'kind' => $c1['next']]);
$last = db()->query("SELECT source, station_id FROM punches WHERE member_id = $dana ORDER BY id DESC LIMIT 1")->fetch();
check('דיווח מהעמדה — עם מקור ועמדה', $last['source'] === 'station' && (int) $last['station_id'] === $stId);
throws('עובד בלי קוד (לא הפעיל)', fn() => stationCheck(['member_id' => $h, 'pin' => '1111']));

for ($k = 0; $k < 4; $k++) { try { stationCheck(['member_id' => $dana, 'pin' => '0002']); } catch (AppError) {} }
throws('חמישה קודים שגויים — נעילה', fn() => stationCheck(['member_id' => $dana, 'pin' => '0003']), 429);
throws('נעול גם עם הקוד הנכון', fn() => stationCheck(['member_id' => $dana, 'pin' => '5839']), 429);
db()->exec("UPDATE throttle SET locked_until = '2000-01-01T00:00:00Z' WHERE k = 'pin:$dana'");
check('אחרי דקה — הקוד הנכון עובד שוב', stationCheck(['member_id' => $dana, 'pin' => '5839'])['name'] === 'דנה לוי');

for ($k = 0; $k < 10; $k++) { try { stationPair(['code' => '12345' . $k]); } catch (AppError) {} }
throws('עשרה קודי הצמדה שגויים — נעילה', fn() => stationPair(['code' => '999999']), 429);
db()->exec("DELETE FROM throttle WHERE k LIKE 'pair:%'");

$y2 = as_(['sub' => 'u-yossi']);
check('מנהל סניף לא רואה עמדה של סניף אחר', stationList($y2, ['company_id' => $cid])['stations'] === []);
throws('ומבטל אותה — לא', fn() => stationRevoke($y2, ['company_id' => $cid, 'id' => $stId]), 404);
$r = stationCreate($owner, ['company_id' => $cid, 'id' => $stId]);
throws('קוד הצמדה חדש מנתק את הטאבלט הקודם', fn() => stationState(), 401);
writeStationCookie(stationPair(['code' => $r['code']]));
check('והטאבלט החדש מוצמד', stationState()['station']['name'] === 'כניסה ראשית');
stationRevoke($owner, ['company_id' => $cid, 'id' => $stId]);
throws('עמדה שבוטלה', fn() => stationState(), 401);


// ===== שלב 5: תיקונים =====
$_COOKIE = [];
empActivateAndLogin(['token' => empInvite($owner, ['company_id' => $cid, 'id' => $h])['invite_token'], 'pin' => '2468']);
$GLOBALS['TC_NOW'] = $z('2026-12-01 10:00');   // "עכשיו" — אחרי נובמבר שבו הדיווחים של הבדיקה
$day = fn(string $d) => daysReport($h, $d, $d, $tz, $z('2026-11-30 12:00'))[0];
$nov12in = (int) db()->query("SELECT id FROM punches WHERE member_id = $h AND at_utc = '" . $z('2026-11-12 08:00') . "'")->fetchColumn();

throws('תיקון בלי סיבה', fn() => empCorrect(['member_id' => $h, 'op' => 'add', 'kind' => 'out', 'at' => '2026-11-12T16:00', 'reason' => '']));
throws('תיקון לשעה עתידית', fn() => empCorrect(['member_id' => $h, 'op' => 'add', 'kind' => 'out', 'at' => '2099-01-01T08:00', 'reason' => 'x']));
throws('עובד לא מתקן דיווח של אחר', fn() => empCorrect(['member_id' => $h, 'op' => 'void', 'punch_id' => (int) db()->query("SELECT id FROM punches WHERE member_id = $dana LIMIT 1")->fetchColumn(), 'reason' => 'x']), 404);
$r1 = empCorrect(['member_id' => $h, 'op' => 'add', 'kind' => 'out', 'at' => '2026-11-12T16:00', 'reason' => 'שכחתי לצאת']);
check('בקשה ממתינה — עוד לא נספרת', $day('2026-11-12')['minutes'] === 0 && $day('2026-11-12')['pending'] === 1);
$list = corrList($owner, ['company_id' => $cid])['corrections'];
check('המנהל רואה את הבקשה עם הסיבה', count(array_filter($list, fn($c) => $c['id'] === $r1['id'] && $c['reason'] === 'שכחתי לצאת')) === 1);
throws('מנהלת שכר לא מחליטה', fn() => corrDecide(as_(['sub' => 'u-sara']), ['company_id' => $cid, 'id' => $r1['id'], 'approve' => true]), 403);
throws('מנהל סניף אחר לא מחליט', fn() => corrDecide($y2, ['company_id' => $cid, 'id' => $r1['id'], 'approve' => true]), 404);
corrDecide($owner, ['company_id' => $cid, 'id' => $r1['id'], 'approve' => true]);
$d12 = $day('2026-11-12');
check('אושר: היום נספר (8 שעות), מסומן "תוקן", בלי חוסר', $d12['minutes'] === 480 && $d12['corrected'] && $d12['missing_out'] === 0 && $d12['pending'] === 0);
throws('אי אפשר להחליט פעמיים', fn() => corrDecide($owner, ['company_id' => $cid, 'id' => $r1['id'], 'approve' => false, 'note' => 'x']));

$nov13in = (int) db()->query("SELECT id FROM punches WHERE member_id = $h AND at_utc = '" . $z('2026-11-13 08:00') . "'")->fetchColumn();
throws('שינוי שעה ביותר מיום', fn() => empCorrect(['member_id' => $h, 'op' => 'move', 'punch_id' => $nov13in, 'at' => '2026-11-15T07:30', 'reason' => 'x']));
$r2 = empCorrect(['member_id' => $h, 'op' => 'move', 'punch_id' => $nov13in, 'at' => '2026-11-13T07:30', 'reason' => 'הגעתי מוקדם']);
throws('בקשה שנייה לאותו דיווח', fn() => empCorrect(['member_id' => $h, 'op' => 'void', 'punch_id' => $nov13in, 'reason' => 'x']));
throws('דחייה בלי הסבר', fn() => corrDecide($owner, ['company_id' => $cid, 'id' => $r2['id'], 'approve' => false]));
corrDecide($owner, ['company_id' => $cid, 'id' => $r2['id'], 'approve' => false, 'note' => 'לפי המצלמה הגעת ב-8']);
check('נדחה: היום לא השתנה', $day('2026-11-13')['minutes'] === 540);
$mine = empCorrections(['member_id' => $h])['corrections'];
check('העובד רואה את הדחייה ואת ההסבר', count(array_filter($mine, fn($c) => $c['id'] === $r2['id'] && $c['status'] === 'rejected' && $c['note'] === 'לפי המצלמה הגעת ב-8')) === 1);

$r3 = empCorrect(['member_id' => $h, 'op' => 'move', 'punch_id' => $nov13in, 'at' => '2026-11-13T07:45', 'reason' => 'שוב']);
empCorrectCancel(['member_id' => $h, 'id' => $r3['id']]);
check('העובד ביטל בקשה שלו', $day('2026-11-13')['pending'] === 0);

// תיקון ישיר של המנהל: ביטול היציאה בלי כניסה ב-15.11
$stray = (int) db()->query("SELECT id FROM punches WHERE member_id = $h AND at_utc = '" . $z('2026-11-15 12:00') . "'")->fetchColumn();
corrDirect($owner, ['company_id' => $cid, 'member_id' => $h, 'op' => 'void', 'punch_id' => $stray, 'reason' => 'דיווח בטעות']);
check('תיקון ישיר — מיד בתוקף', $day('2026-11-15')['missing_in'] === 0 && $day('2026-11-15')['punches'] === []);
check('הדיווח המקורי לא נמחק', (int) db()->query("SELECT COUNT(*) FROM punches WHERE id = $stray")->fetchColumn() === 1);
corrDirect($owner, ['company_id' => $cid, 'member_id' => $h, 'op' => 'move', 'punch_id' => $nov13in, 'at' => '2026-11-13T07:30', 'reason' => 'אושר מול המצלמה']);
check('שינוי שעה ישיר', $day('2026-11-13')['minutes'] === 570);
check('הדיווח המקורי שומר את השעה המקורית', db()->query("SELECT at_utc FROM punches WHERE id = $nov13in")->fetchColumn() === $z('2026-11-13 08:00'));
check('היסטוריית התיקונים בכרטיס', count(corrHistory($owner, ['company_id' => $cid, 'member_id' => $h])['corrections']) === 5);
check('כל החלטה ביומן', (int) db()->query("SELECT COUNT(*) FROM audit WHERE action LIKE 'correction.%'")->fetchColumn() >= 7);
unset($GLOBALS['TC_NOW']);

@unlink(DB_FILE); @unlink(DB_FILE . '-wal'); @unlink(DB_FILE . '-shm');
echo "\n" . ($n - $fails) . "/$n עברו\n";
exit($fails ? 1 : 0);
