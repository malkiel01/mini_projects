<?php
/**
 * בדיקות: זהות מהפלטפורמה, חברה, סניפים, צוות והפרדה בין חברות (שלב 1);
 * עובדים, תנאים בגרסאות, קישור הזמנה, הפעלה וקוד, מכשיר והרשאות מנהל סניף (שלב 2);
 * דיווח, חישוב שעות (משמרת לילה, שעון קיץ, חסר יציאה, כפילות), החודש והמסך "היום" (שלב 3);
 * עמדה: הצמדה, קוד שגוי ונעילה, סניף, ביטול (שלב 4);
 * תיקונים: בקשה, אישור ודחייה, תיקון ישיר, המקור לא משתנה, הרשאות (שלב 5);
 * אישור חודשי: שלבים, פתיחה, אישור לפי תפקיד, במקום העובד, נעילה, פתיחה מחדש, ייצוא (שלב 6);
 * גרסה 2 — סיווג שעות: יומי, נוספות, לילה, מנוחה, חציית חצות לשבת, שבועי, דת, כללים בגרסאות;
 * חגים: לוח יהודי מובנה, ימים של החברה לפי דת, חג במנוחה (מצטבר / לא), חודש נעול.
 * גרסה 3 — יתרות: טבלת הוותק, יתרת פתיחה, צבירה, הורדה, תקרת מחלה, רצף מחלה, ייצוא.
 * גרסה 3 — היעדרויות: בקשה, אישור ודחייה, רישום ישיר וביטול, יחידה, סוגי החברה, זיכוי התקן, חג, ייצוא, חודש נעול.
 * הרצה: php time-clock/tests/run.php — על מסד זמני, בלי לגעת ב-data/.
 */

declare(strict_types=1);

define('DB_FILE', sys_get_temp_dir() . '/time-clock-test-' . getmypid() . '.sqlite');
// מסד נקי תמיד: ריצה שנפלה באמצע משאירה קובץ, ומספר תהליך שחוזר היה פותח מסד עם נתונים ישנים.
$clean = function () { foreach (['', '-wal', '-shm'] as $x) @unlink(DB_FILE . $x); };
$clean();
register_shutdown_function($clean);
require_once __DIR__ . '/../lib/attendance.php';
require_once __DIR__ . '/../lib/station.php';
require_once __DIR__ . '/../lib/corrections.php';
require_once __DIR__ . '/../lib/periods.php';

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


// ===== שלב 6: אישור חודשי =====
$GLOBALS['TC_NOW'] = $z('2026-12-01 10:00');
$owner = as_(['sub' => 'u-owner']); $sara = as_(['sub' => 'u-sara']);
check('ברירת מחדל: עובד ← מנהל', stepsGet($owner, ['company_id' => $cid])['steps'] === ['employee', 'manager']);
throws('רק הבעלים משנה שלבים', fn() => stepsSave($sara, ['company_id' => $cid, 'steps' => ['manager']]), 403);
throws('אותו תפקיד פעמיים', fn() => stepsSave($owner, ['company_id' => $cid, 'steps' => ['manager', 'manager']]));
stepsSave($owner, ['company_id' => $cid, 'steps' => ['employee', 'manager', 'payroll']]);
$v = periodView($owner, ['company_id' => $cid, 'month' => '2026-11']);
check('לפני פתיחה: open, אפשר לפתוח', $v['status'] === 'open' && $v['can']['open']);
$ids = array_column($v['employees'], 'id');
check('עובדי החודש: מי שהועסק בו (עומר שסיים באוקטובר — לא)', in_array($h, $ids, true) && in_array($dana, $ids, true) && !in_array($omer, $ids, true));
throws('חודש עתידי', fn() => periodOpen($owner, ['company_id' => $cid, 'month' => '2027-01']));
throws('מנהלת שכר לא פותחת', fn() => periodOpen($sara, ['company_id' => $cid, 'month' => '2026-11']), 403);
$v = periodOpen($owner, ['company_id' => $cid, 'month' => '2026-11']);
check('נפתח לאישור עם 3 שלבים', $v['status'] === 'review' && count($v['steps']) === 3);
stepsSave($owner, ['company_id' => $cid, 'steps' => ['manager']]);
check('שינוי שלבים לא משנה חודש שכבר בתהליך', count(periodView($owner, ['company_id' => $cid, 'month' => '2026-11'])['steps']) === 3);

$st = empState();
$hm_ = array_values(array_filter($st['memberships'], fn($m) => $m['id'] === $h))[0];
check('העובד רואה שנובמבר ממתין לאישורו', $hm_['to_approve'] === ['2026-11']);
throws('המנהל לא מאשר במקום העובד בלי הערה', fn() => periodApprove($owner, ['company_id' => $cid, 'month' => '2026-11', 'member_ids' => [$h]]));
empApprove(['member_id' => $h, 'month' => '2026-11']);
check('העובד אישר', empMonth(['member_id' => $h, 'month' => '2026-11'])['period']['done'] === [0]);
throws('העובד לא מאשר פעמיים', fn() => empApprove(['member_id' => $h, 'month' => '2026-11']));
throws('מנהל סניף אחר — לא', fn() => periodApprove($y2, ['company_id' => $cid, 'month' => '2026-11', 'member_ids' => [$h]]), 404);
throws('מנהלת שכר לא מאשרת שלב מנהל', fn() => periodApprove($sara, ['company_id' => $cid, 'month' => '2026-11', 'member_ids' => [$h]]), 403);
periodApprove($owner, ['company_id' => $cid, 'month' => '2026-11', 'member_ids' => [$h]]);
$v = periodApprove($sara, ['company_id' => $cid, 'month' => '2026-11', 'member_ids' => [$h]]);
$row = array_values(array_filter($v['employees'], fn($e) => $e['id'] === $h))[0];
check('שלושה שלבים — הושלם', $row['next'] === null && count($row['signoffs']) === 3);

throws('מי שאינו בעלים או מנהל לא נועל', fn() => periodLock(as_(['sub' => 'u-david']), ['company_id' => $cid, 'month' => '2026-11']));
throws('בעלים בלי סיבה — לא', fn() => periodLock($owner, ['company_id' => $cid, 'month' => '2026-11']));
periodApprove($owner, ['company_id' => $cid, 'month' => '2026-11', 'member_ids' => [$dana], 'on_behalf' => true, 'note' => 'בחופשה, אושר בטלפון']);
$v = periodApprove($owner, ['company_id' => $cid, 'month' => '2026-11', 'member_ids' => [$dana]]);
$v = periodApprove($sara, ['company_id' => $cid, 'month' => '2026-11', 'member_ids' => [$dana]]);
$rd = array_values(array_filter($v['employees'], fn($e) => $e['id'] === $dana))[0];
check('במקום העובד — מסומן, עם ההערה', $rd['signoffs'][0]['on_behalf'] && $rd['signoffs'][0]['note'] === 'בחופשה, אושר בטלפון');
check('כולם אישרו', $v['done'] === count($v['employees']));
$v = periodLock($owner, ['company_id' => $cid, 'month' => '2026-11']);
check('ננעל', $v['status'] === 'locked' && $v['can']['reopen']);
$n13 = (int) db()->query("SELECT id FROM punches WHERE member_id = $h AND at_utc = '" . $z('2026-11-10 08:00') . "'")->fetchColumn();
throws('חודש נעול — אין תיקון ישיר', fn() => corrDirect($owner, ['company_id' => $cid, 'member_id' => $h, 'op' => 'void', 'punch_id' => $n13, 'reason' => 'x']));
throws('חודש נעול — אין בקשת תיקון', fn() => empCorrect(['member_id' => $h, 'op' => 'add', 'kind' => 'in', 'at' => '2026-11-20T08:00', 'reason' => 'x']));

throws('פתיחה מחדש בלי סיבה', fn() => periodReopen($owner, ['company_id' => $cid, 'month' => '2026-11']));
throws('רק בעלים פותח מחדש', fn() => periodReopen($sara, ['company_id' => $cid, 'month' => '2026-11', 'reason' => 'x']), 403);
$v = periodReopen($owner, ['company_id' => $cid, 'month' => '2026-11', 'reason' => 'שכחנו חג']);
check('נפתח מחדש — כל האישורים בוטלו', $v['status'] === 'review' && $v['done'] === 0);
check('האישורים נשמרים כמבוטלים', (int) db()->query('SELECT COUNT(*) FROM period_signoffs WHERE revoked_at IS NOT NULL')->fetchColumn() === 6);
empApprove(['member_id' => $h, 'month' => '2026-11']);
corrDirect($owner, ['company_id' => $cid, 'member_id' => $h, 'op' => 'void', 'punch_id' => $n13, 'reason' => 'דיווח כפול']);
check('תיקון בחודש שבאישור — האישור של העובד בוטל', empMonth(['member_id' => $h, 'month' => '2026-11'])['period']['done'] === []);

$csv = periodCsv($owner, ['company_id' => $cid, 'month' => '2026-11']);
check('CSV: BOM, כותרות, שורת סיכום', str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, 'סה״כ,תקן') && str_contains($csv, 'סה״כ 2026-11'));
check('CSV: יום עם תיקון מסומן', str_contains($csv, '2026-11-13') && str_contains($csv, ',כן,'));
unset($GLOBALS['TC_NOW']);

// ===== גרסה 2: סיווג שעות =====
$GLOBALS['TC_NOW'] = $z('2027-02-01 10:00');
$owner = as_(['sub' => 'u-owner']); $sara = as_(['sub' => 'u-sara']);
$w = empAdd($owner, ['company_id' => $cid, 'name' => 'סיווג', 'site_id' => $s1, 'start_date' => '2027-01-01', 'days_per_week' => 5, 'daily_minutes' => 504])['employee']['id'];
$P = function (string $a, string $b) use ($w, $z) { recordPunch($w, 'in', 'phone', null, null, $z($a)); recordPunch($w, 'out', 'phone', null, null, $z($b)); };
$P('2027-01-03 08:00', '2027-01-03 16:00');   // ראשון רגיל
$P('2027-01-04 07:00', '2027-01-04 18:00');   // 11 שעות
$P('2027-01-05 22:00', '2027-01-06 06:30');   // לילה
$P('2027-01-08 20:00', '2027-01-09 02:00');   // שישי בערב ← שבת
$P('2027-01-09 08:00', '2027-01-09 19:00');   // שבת
foreach (['10', '11', '12', '13', '14'] as $dd) $P("2027-01-$dd 08:00", "2027-01-$dd 16:36");   // שבוע של 5 × 8:36
$cm = fn() => classifyMonth($w, '2027-01', $tz)['days'];
$c = $cm();
$B = function ($d, $k) { global $c; return $c[$d]['b'][$k]; };   // לא fn: היא הייתה מעתיקה את $c הראשון
check('יום רגיל — 8 שעות רגילות', $B('2027-01-03', 'reg') === 480 && $B('2027-01-03', 'ot1') === 0);
check('11 שעות: 8:36 רגילות, 2 ב-125%, 24 דק׳ ב-150%', $B('2027-01-04', 'reg') === 516 && $B('2027-01-04', 'ot1') === 120 && $B('2027-01-04', 'ot2') === 24);
check('משמרת לילה: תקן 7 שעות', $c['2027-01-05']['night'] && $B('2027-01-05', 'reg') === 420 && $B('2027-01-05', 'ot1') === 90);
check('שישי בערב שגולש לשבת: עד חצות רגיל, אחרי חצות מנוחה', $B('2027-01-08', 'reg') === 240 && $B('2027-01-08', 'rest') === 120);
check('שבת: 150% ← 175% ← 200%', $c['2027-01-09']['rest'] && $B('2027-01-09', 'rest') === 516 && $B('2027-01-09', 'rest_ot1') === 120 && $B('2027-01-09', 'rest_ot2') === 24);
check('סכום הסלים = הדקות של היום', array_sum($c['2027-01-04']['b']) === 660 && array_sum($c['2027-01-05']['b']) === 510);
check('שבועי: 5 × 8:36 = 43 שעות — שעה אחת עוברת ל-125%, ביום האחרון', $B('2027-01-14', 'ot1') === 60 && $B('2027-01-14', 'reg') === 456 && $B('2027-01-13', 'ot1') === 0);
$sum = classifyMonth($w, '2027-01', $tz)['summary'];
check('סיכום החודש', $sum['ot1'] === 120 + 90 + 60 && $sum['rest'] === 636 && $sum['rest_ot2'] === 24);

empUpdate($owner, ['company_id' => $cid, 'id' => $w, 'name' => 'סיווג', 'site_id' => $s1, 'start_date' => '2027-01-01', 'religion' => 'muslim', 'rest_day' => '']);
$c = $cm();
check('מוסלמי: שישי יום המנוחה — שישי בערב מנוחה, שבת רגילה', $B('2027-01-08', 'rest') === 240 && $B('2027-01-08', 'reg') === 120 && !$c['2027-01-09']['rest'] && $B('2027-01-09', 'reg') === 516);
empUpdate($owner, ['company_id' => $cid, 'id' => $w, 'name' => 'סיווג', 'site_id' => $s1, 'start_date' => '2027-01-01', 'religion' => 'muslim', 'rest_day' => '6']);
check('יום מנוחה שנקבע לעובד גובר על הדת', $cm()['2027-01-09']['rest']);
throws('יום מנוחה לא תקין', fn() => empUpdate($owner, ['company_id' => $cid, 'id' => $w, 'name' => 'סיווג', 'religion' => 'jewish', 'rest_day' => '9']));
empUpdate($owner, ['company_id' => $cid, 'id' => $w, 'name' => 'סיווג', 'site_id' => $s1, 'start_date' => '2027-01-01', 'religion' => 'jewish', 'rest_day' => '']);

throws('מנהלת שכר לא משנה כללים', fn() => otRulesSave($sara, ['company_id' => $cid, 'effective_from' => '2027-01-10', 'rules' => []]), 403);
throws('ערך לא תקין', fn() => otRulesSave($owner, ['company_id' => $cid, 'effective_from' => '2027-01-10', 'rules' => ['daily5' => 5]]));
$rr = otRulesSave($owner, ['company_id' => $cid, 'effective_from' => '2027-01-10', 'rules' => ['daily5' => 480] + OT_DEFAULTS]);
check('כללים בגרסאות — נשמר עם תאריך', $rr['history'][0]['effective_from'] === '2027-01-10' && $rr['history'][0]['rules']['daily5'] === 480);
$c = $cm();
check('מהתאריך: תקן 8:00 — 36 דק׳ ב-125% בכל יום, ובלי חריגה שבועית', $B('2027-01-11', 'reg') === 480 && $B('2027-01-11', 'ot1') === 36 && $B('2027-01-14', 'ot1') === 36);
check('לפני התאריך — הכלל הקודם', $B('2027-01-04', 'reg') === 516);
unset($GLOBALS['TC_NOW']);


// ===== גרסה 2: חגים =====
$GLOBALS['TC_NOW'] = $z('2027-12-01 10:00');
check('לוח יהודי: פסח, יום העצמאות, ראש השנה, יום כיפור 2027', holidayOn($cid, 'jewish', '2027-04-22') === 'פסח'
      && holidayOn($cid, 'jewish', '2027-05-12') === 'יום העצמאות' && holidayOn($cid, 'jewish', '2027-10-02') === 'ראש השנה' && holidayOn($cid, 'jewish', '2027-10-11') === 'יום כיפור');
check('יום העצמאות שחל בשני נדחה לשלישי (2028)', holidayOn($cid, 'jewish', '2028-05-02') === 'יום העצמאות' && holidayOn($cid, 'jewish', '2028-05-01') === null);
check('לעובד מוסלמי אין את הלוח היהודי', holidayOn($cid, 'muslim', '2027-10-11') === null);
throws('מנהלת שכר לא מוסיפה חג', fn() => holidayAdd($sara, ['company_id' => $cid, 'date' => '2027-03-10', 'name' => 'עיד אל-פיטר', 'religions' => ['muslim']]), 403);
throws('חג בלי דתות', fn() => holidayAdd($owner, ['company_id' => $cid, 'date' => '2027-03-10', 'name' => 'x', 'religions' => []]));
$hg = holidayAdd($owner, ['company_id' => $cid, 'date' => '2027-03-10', 'name' => 'עיד אל-פיטר', 'religions' => ['muslim', 'druze']]);
check('יום של החברה למוסלמים ודרוזים', holidayOn($cid, 'muslim', '2027-03-10') === 'עיד אל-פיטר' && holidayOn($cid, 'druze', '2027-03-10') === 'עיד אל-פיטר' && holidayOn($cid, 'jewish', '2027-03-10') === null);
holidayAdd($owner, ['company_id' => $cid, 'date' => '2027-06-01', 'name' => 'יום החברה', 'religions' => 'all']);
check('יום של החברה לכולם', holidayOn($cid, 'christian', '2027-06-01') === 'יום החברה' && holidayOn($cid, 'jewish', '2027-06-01') === 'יום החברה');
check('הלוח של השנה: מובנה + של החברה', count(holidaysGet($owner, ['company_id' => $cid, 'year' => 2027])['company']) === 2 && count(holidaysGet($owner, ['company_id' => $cid, 'year' => 2027])['jewish']) === 9);

// סיווג ביום חג: עובד יהודי עובד בפסח (חמישי 22.4.2027) — מנוחה/חג
$P('2027-04-22 08:00', '2027-04-22 18:00');
$c = classifyMonth($w, '2027-04', $tz)['days'];
check('עבודה בחג: 150% ואחר כך 175%', $c['2027-04-22']['holiday'] === 'פסח' && $B('2027-04-22', 'rest') === 480 && $B('2027-04-22', 'rest_ot1') === 120);   // התקן מ-10.1.2027: 8:00
// חג במנוחה: יום החברה בשבת 2027-06-05? — בונים: יום חברה בשבת, ומצטבר
holidayAdd($owner, ['company_id' => $cid, 'date' => '2027-06-05', 'name' => 'חג בשבת', 'religions' => 'all']);
$P('2027-06-05 08:00', '2027-06-05 12:00');
$c = classifyMonth($w, '2027-06', $tz)['days'];
check('חג בשבת — לא מצטבר (ברירת מחדל): מנוחה', $B('2027-06-05', 'rest') === 240 && $B('2027-06-05', 'holiday_rest') === 0);
otRulesSave($owner, ['company_id' => $cid, 'effective_from' => '2027-06-01', 'rules' => ['daily5' => 480, 'holiday_rest_cumulative' => true] + OT_DEFAULTS]);
$c = classifyMonth($w, '2027-06', $tz)['days'];
check('המעסיק בחר "מצטבר": סל נפרד', $B('2027-06-05', 'holiday_rest') === 240 && $B('2027-06-05', 'rest') === 0);
holidayDelete($owner, ['company_id' => $cid, 'id' => $hg['company'][0]['id']]);
check('מחיקת יום של החברה', holidayOn($cid, 'muslim', '2027-03-10') === null);
unset($GLOBALS['TC_NOW']);

// ===== גרסה 2: תצוגה וייצוא =====
$GLOBALS['TC_NOW'] = $z('2027-12-01 10:00');
$mo = empMonthManager($owner, ['company_id' => $cid, 'id' => $w, 'month' => '2027-04']);
$d22 = array_values(array_filter($mo['days'], fn($d) => $d['date'] === '2027-04-22'))[0];
check('בחודש: לכל יום הסיווג, ובסיכום הסלים', $d22['ot']['holiday'] === 'פסח' && $mo['summary']['ot']['rest'] === 480 && $mo['ot_rules']['rate_ot1'] === 125);
$csv = periodCsv($owner, ['company_id' => $cid, 'month' => '2027-01']);
check('CSV: עמודות הסיווג עם השיעורים', str_contains($csv, 'נוספות 125%') && str_contains($csv, 'מנוחה/חג 150%') && str_contains($csv, 'חג במנוחה'));
recordPunch($w, 'out', 'manager', null, null, $z('2027-03-03 17:00'));
check('דיווח שמוזן לאחור (לפני האחרון) — לא נחסם ככפול', (bool) db()->query("SELECT COUNT(*) FROM punches WHERE member_id = $w AND at_utc = '" . $z('2027-03-03 17:00') . "'")->fetchColumn());
check('CSV: שורת שבת מסומנת "מנוחה"', (bool) preg_match('/2027-01-09,[^\n]*מנוחה/u', $csv));
$pv = periodView($owner, ['company_id' => $cid, 'month' => '2027-01']);
$pw = array_values(array_filter($pv['employees'], fn($e) => $e['id'] === $w))[0];
check('מסך "חודש": הסלים לכל עובד', isset($pw['summary']['ot']['ot1']) && $pw['summary']['ot']['ot1'] > 0);
unset($GLOBALS['TC_NOW']);

// ===== גרסה 3: היעדרויות =====
$GLOBALS['TC_NOW'] = $z('2028-02-20 10:00');   // ראשון
$owner = as_(['sub' => 'u-owner']); $sara = as_(['sub' => 'u-sara']); $yossi = as_(['sub' => 'u-yossi']);
$a = empAdd($owner, ['company_id' => $cid, 'name' => 'נעדרת', 'site_id' => $s1, 'start_date' => '2028-01-01', 'days_per_week' => 5, 'daily_minutes' => 504])['employee']['id'];
$_COOKIE = [];
empActivateAndLogin(['token' => empInvite($owner, ['company_id' => $cid, 'id' => $a])['invite_token'], 'pin' => '3579']);
$R = fn(array $x) => empAbsenceRequest(['member_id' => $a] + $x)['id'];
$dayOf = function (string $date) use ($a) { foreach (empMonth(['member_id' => $a, 'month' => substr($date, 0, 7)])['days'] as $d) if ($d['date'] === $date) return $d; };
$ea = empAbsences(['member_id' => $a]);
check('לעובד: שלושת הסוגים המובנים, וברירת המחדל — יום או חצי', count($ea['types']) === 3 && $ea['unit'] === 'half');
throws('סוג לא מוכר', fn() => $R(['type' => 'x', 'from' => '2028-02-06']));
throws('"עד" לפני "מ"', fn() => $R(['type' => 'vacation', 'from' => '2028-02-08', 'to' => '2028-02-06']));
throws('מחלה עתידית', fn() => $R(['type' => 'sick', 'from' => '2028-02-24']));
throws('רק סוף שבוע — אין ימי עבודה', fn() => $R(['type' => 'vacation', 'from' => '2028-02-11', 'to' => '2028-02-12']));
throws('שעות כשהיחידה חצי יום', fn() => $R(['type' => 'vacation', 'from' => '2028-02-06', 'part' => 'hours', 'minutes' => 60]));
$v1 = $R(['type' => 'vacation', 'from' => '2028-02-06', 'to' => '2028-02-12', 'note' => 'אילת']);
throws('חפיפה לבקשה קיימת', fn() => $R(['type' => 'sick', 'from' => '2028-02-08']));
check('בקשה ממתינה: מסומנת, בלי זיכוי', $dayOf('2028-02-07')['absence']['status'] === 'pending' && $dayOf('2028-02-07')['absence']['credit'] === 0);
$lst = absList($owner, ['company_id' => $cid])['absences'];
check('ברשימת האישורים: 5 ימי עבודה (שישי-שבת לא נספרים)', count($lst) === 1 && $lst[0]['workdays'] === 5 && $lst[0]['credit'] === 5 * 504);
check('מנהל סניף לא רואה בקשה מסניף אחר', absList($yossi, ['company_id' => $cid])['absences'] === []);
throws('מנהל סניף אחר לא מחליט', fn() => absDecide($yossi, ['company_id' => $cid, 'id' => $v1, 'approve' => true]), 404);
throws('מנהלת שכר לא מחליטה', fn() => absDecide($sara, ['company_id' => $cid, 'id' => $v1, 'approve' => true]), 403);
throws('דחייה בלי הסבר', fn() => absDecide($owner, ['company_id' => $cid, 'id' => $v1, 'approve' => false]));
absDecide($owner, ['company_id' => $cid, 'id' => $v1, 'approve' => true]);
$mo = empMonth(['member_id' => $a, 'month' => '2028-02']);
check('אושרה: היום זוקף את התקן', $dayOf('2028-02-07')['absence']['credit'] === 504 && $dayOf('2028-02-07')['absence']['name'] === 'חופשה');
check('שישי בתוך הטווח — לא מסומן', $dayOf('2028-02-11')['absence'] === null);
check('הסיכום: זיכוי 5 ימים, וההפרש מולו', $mo['summary']['absence'] === 5 * 504 && $mo['summary']['diff'] === 5 * 504 - $mo['summary']['standard']);

// חצי יום מחלה, ועבד את החצי השני
$s14 = $R(['type' => 'sick', 'from' => '2028-02-14', 'part' => 'half']);
absDecide($owner, ['company_id' => $cid, 'id' => $s14, 'approve' => true]);
recordPunch($a, 'in', 'phone', null, null, $z('2028-02-14 08:00')); recordPunch($a, 'out', 'phone', null, null, $z('2028-02-14 12:12'));
$d14 = $dayOf('2028-02-14');
check('חצי יום: זוקף חצי תקן, והדיווחים נספרים', $d14['absence']['credit'] === 252 && $d14['minutes'] === 252);

// יחידה — רק הבעלים
throws('מנהלת שכר לא משנה יחידה', fn() => absSettingsSave($sara, ['company_id' => $cid, 'unit' => 'day']), 403);
absSettingsSave($owner, ['company_id' => $cid, 'unit' => 'day']);
throws('יחידה "יום" — אין חצי יום', fn() => $R(['type' => 'vacation', 'from' => '2028-02-15', 'part' => 'half']));
absSettingsSave($owner, ['company_id' => $cid, 'unit' => 'hours']);
$h15 = $R(['type' => 'vacation', 'from' => '2028-02-15', 'part' => 'hours', 'minutes' => 120]);
absDecide($owner, ['company_id' => $cid, 'id' => $h15, 'approve' => true]);
check('שעות: שעתיים', $dayOf('2028-02-15')['absence']['credit'] === 120);

// רישום ישיר, וביטול
$r16 = absDirect($owner, ['company_id' => $cid, 'member_id' => $a, 'type' => 'reserve', 'from' => '2028-02-16', 'to' => '2028-02-17'])['id'];
check('רישום ישיר — מאושר מיד', $dayOf('2028-02-17')['absence']['name'] === 'מילואים' && $dayOf('2028-02-17')['absence']['credit'] === 504);
throws('העובד לא מבטל היעדרות שרשם המנהל', fn() => empAbsenceCancel(['member_id' => $a, 'id' => $r16]), 404);
throws('ביטול בלי סיבה', fn() => absVoid($owner, ['company_id' => $cid, 'id' => $r16]));
absVoid($owner, ['company_id' => $cid, 'id' => $r16, 'note' => 'נרשם בטעות']);
check('בוטלה — אין זיכוי', $dayOf('2028-02-17')['absence'] === null);

// סוג של החברה
throws('רק הבעלים מוסיף סוג', fn() => absTypeSave($sara, ['company_id' => $cid, 'name' => 'אבל']), 403);
$types = absTypeSave($owner, ['company_id' => $cid, 'name' => 'אבל', 'icon' => '🕯️'])['types'];
$tk = end($types)['key'];
$t17 = $R(['type' => $tk, 'from' => '2028-02-17']);
check('סוג של החברה בבקשה', absList($owner, ['company_id' => $cid])['absences'][0]['type_name'] === 'אבל');
empAbsenceCancel(['member_id' => $a, 'id' => $t17]);
check('העובד ביטל בקשה ממתינה', absList($owner, ['company_id' => $cid])['absences'] === []);
absTypeSave($owner, ['company_id' => $cid, 'id' => (int) substr($tk, 1), 'name' => 'אבל', 'active' => false]);
throws('סוג שהושבת — אין בקשות חדשות', fn() => $R(['type' => $tk, 'from' => '2028-02-17']));

// חג באמצע — לא נספר
holidayAdd($owner, ['company_id' => $cid, 'date' => '2028-02-22', 'name' => 'יום החברה', 'religions' => 'all']);
$f = $R(['type' => 'vacation', 'from' => '2028-02-21', 'to' => '2028-02-23']);
check('חג בתוך הטווח לא יורד: 2 ימי עבודה', absList($owner, ['company_id' => $cid])['absences'][0]['workdays'] === 2);
empAbsenceCancel(['member_id' => $a, 'id' => $f]);

// ייצוא
$csv = periodCsv($owner, ['company_id' => $cid, 'month' => '2028-02']);
check('CSV: עמודות היעדרות וזיכוי', str_contains($csv, 'היעדרות,זיכוי'));
check('CSV: יום חופשה — לא "לא דווח"', (bool) preg_match('/^נעדרת,,2028-02-07,[^\n]*,0:00,[^\n]*חופשה,8:24/mu', $csv) && !preg_match('/^נעדרת,,2028-02-07,[^\n]*לא דווח/mu', $csv));
check('CSV: חצי יום מחלה', (bool) preg_match('/^נעדרת,,2028-02-14,[^\n]*"מחלה ½",4:12/mu', $csv));

// חודש באישור וחודש נעול
stepsSave($owner, ['company_id' => $cid, 'steps' => ['employee', 'manager']]);
periodOpen($owner, ['company_id' => $cid, 'month' => '2028-02']);
empApprove(['member_id' => $a, 'month' => '2028-02']);
absDirect($owner, ['company_id' => $cid, 'member_id' => $a, 'type' => 'sick', 'from' => '2028-02-17']);
check('היעדרות בחודש שבאישור — האישור של העובד בוטל', empMonth(['member_id' => $a, 'month' => '2028-02'])['period']['done'] === []);
periodOpen($owner, ['company_id' => $cid, 'month' => '2028-01']);
periodLock($owner, ['company_id' => $cid, 'month' => '2028-01', 'reason' => 'בדיקה']);
throws('חודש נעול — אין בקשה', fn() => $R(['type' => 'vacation', 'from' => '2028-01-31', 'to' => '2028-02-01']));
throws('חודש נעול — אין רישום ישיר', fn() => absDirect($owner, ['company_id' => $cid, 'member_id' => $a, 'type' => 'vacation', 'from' => '2028-01-10']));
check('כל החלטה ביומן', (int) db()->query("SELECT COUNT(*) FROM audit WHERE action LIKE 'absence%'")->fetchColumn() >= 12);
unset($GLOBALS['TC_NOW']);

// ===== גרסה 3: יתרות =====
$GLOBALS['TC_NOW'] = $z('2028-03-10 10:00');
$owner = as_(['sub' => 'u-owner']); $sara = as_(['sub' => 'u-sara']); $yossi = as_(['sub' => 'u-yossi']);
check('טבלת חופשה: שנה 6 — חוק 14, צו הרחבה 17, שבוע של 6 ימים 16', vacationEntitlement(6, 5, 'law') === 14 && vacationEntitlement(6, 5, 'extension') === 17 && vacationEntitlement(6, 6, 'law') === 16);
check('טבלת חופשה: 14 שנה ומעלה', vacationEntitlement(20, 5, 'law') === 20 && vacationEntitlement(14, 6, 'extension') === 24);
$b = empAdd($owner, ['company_id' => $cid, 'name' => 'יתרות', 'site_id' => $s1, 'start_date' => '2025-01-01', 'days_per_week' => 5, 'daily_minutes' => 504])['employee']['id'];
$bal = fn(string $k) => memberBalance(memberRow($b), $k, '2028-03-10');
check('בלי יתרת פתיחה: צבירה מתאריך ההתחלה — 38 חודשים', $bal('vacation')['balance'] === 38.0 && $bal('sick')['balance'] === 57.0);
throws('מנהלת שכר לא מזינה יתרה', fn() => absOpeningSave($sara, ['company_id' => $cid, 'member_id' => $b, 'kind' => 'vacation', 'as_of' => '2028-01-01', 'days' => 10]), 403);
throws('מנהל סניף אחר לא מזין יתרה', fn() => absOpeningSave($yossi, ['company_id' => $cid, 'member_id' => $b, 'kind' => 'vacation', 'as_of' => '2028-01-01', 'days' => 10]), 404);
throws('יתרה לא מספר', fn() => absOpeningSave($owner, ['company_id' => $cid, 'member_id' => $b, 'kind' => 'vacation', 'as_of' => '2028-01-01', 'days' => 'x']));
absOpeningSave($owner, ['company_id' => $cid, 'member_id' => $b, 'kind' => 'vacation', 'as_of' => '2028-01-01', 'days' => 10, 'note' => 'מהמערכת הקודמת']);
check('יתרת פתיחה 10 ב-1.1 + ינואר ופברואר', $bal('vacation')['balance'] === 12.0 && $bal('vacation')['accrued'] === 2.0);
absDirect($owner, ['company_id' => $cid, 'member_id' => $b, 'type' => 'vacation', 'from' => '2028-02-06', 'to' => '2028-02-07']);
absDirect($owner, ['company_id' => $cid, 'member_id' => $b, 'type' => 'vacation', 'from' => '2028-02-08', 'part' => 'hours', 'minutes' => 252]);
absDirect($owner, ['company_id' => $cid, 'member_id' => $b, 'type' => 'vacation', 'from' => '2028-03-12']);
absDirect($owner, ['company_id' => $cid, 'member_id' => $b, 'type' => 'reserve', 'from' => '2028-02-09']);
$v = $bal('vacation');
check('הורדה: יומיים, וחצי (שעות חלקי התקן); מילואים לא מוריד', $v['used'] === 2.5 && $v['balance'] === 9.5);
check('חופשה עתידית — מתוכננת, עוד לא ירדה', $v['planned'] === 1.0);
absOpeningSave($owner, ['company_id' => $cid, 'member_id' => $b, 'kind' => 'sick', 'as_of' => '2027-12-01', 'days' => 89.5]);
check('מחלה: עד התקרה של 90', $bal('sick')['balance'] === 90.0);
absDirect($owner, ['company_id' => $cid, 'member_id' => $b, 'type' => 'sick', 'from' => '2028-02-13']);
absDirect($owner, ['company_id' => $cid, 'member_id' => $b, 'type' => 'sick', 'from' => '2028-02-17']);
absDirect($owner, ['company_id' => $cid, 'member_id' => $b, 'type' => 'sick', 'from' => '2028-02-20', 'to' => '2028-02-21']);
check('מחלה: 4 ימים ירדו, ואז צבירה של החודש (86 + 1.5)', $bal('sick')['balance'] === 87.5 && $bal('sick')['used'] === 4.0);
check('רצף מחלה: שישי-שבת באמצע לא שוברים, יום עבודה בלי מחלה כן', sickRuns($b, '2028-02-01', '2028-02-29') === ['2028-02-13' => 1, '2028-02-17' => 1, '2028-02-20' => 4, '2028-02-21' => 5]);
check('תוויות הרצף', sickRunLabel(1) === 'יום 1' && sickRunLabel(3) === 'יום 2–3' && sickRunLabel(4) === 'יום 4+');
absSettingsSave($owner, ['company_id' => $cid, 'sick_monthly' => 2, 'sick_max' => 100]);
check('כללי המחלה של החברה (2 לחודש, עד 100)', $bal('sick')['balance'] === 91.5);
throws('ערך מחלה לא תקין', fn() => absSettingsSave($owner, ['company_id' => $cid, 'sick_max' => -1]));
absSettingsSave($owner, ['company_id' => $cid, 'sick_monthly' => 1.5, 'sick_max' => 90]);
$p = empAdd($owner, ['company_id' => $cid, 'name' => 'באמצע החודש', 'site_id' => $s1, 'start_date' => '2028-01-15', 'days_per_week' => 5, 'daily_minutes' => 504])['employee']['id'];
check('התחיל באמצע ינואר — ינואר לא נצבר', memberBalance(memberRow($p), 'vacation', '2028-03-10')['accrued'] === 1.0);
insertAbsence($b, ['type' => 'vacation', 'from_date' => '2028-03-13', 'to_date' => '2028-03-13', 'part' => 'full', 'minutes' => null, 'note' => null], $b, null, false);
$pa = array_values(array_filter(absList($owner, ['company_id' => $cid])['absences'], fn($a) => $a['member_id'] === $b))[0];
check('באישורים: היתרה אחרי האישור (9.5 − מתוכננת − הבקשה)', $pa['balance']['now'] === 9.5 && $pa['balance']['after'] === 7.5);
$h = absHistory($owner, ['company_id' => $cid, 'member_id' => $b]);
check('בכרטיס העובד: היתרות והיסטוריית הפתיחה', count($h['balances']) === 2 && count($h['openings']) === 2 && $h['openings'][0]['note'] === 'מהמערכת הקודמת');
$csv = periodCsv($owner, ['company_id' => $cid, 'month' => '2028-02']);
check('CSV: יום מחלה ברצף', str_contains($csv, 'יום מחלה ברצף') && (bool) preg_match('/^יתרות,,2028-02-20,[^\n]*מחלה,8:24,"יום 4\+"/mu', $csv) && (bool) preg_match('/^יתרות,,2028-02-17,[^\n]*"יום 1"/mu', $csv));
check('CSV: היתרות בשורת הסיכום', (bool) preg_match('/^יתרות,,"סה״כ 2028-02"[^\n]*יתרת חופשה 9\.5 · יתרת מחלה 87\.5/mu', $csv));
check('יתרת פתיחה ביומן', (int) db()->query("SELECT COUNT(*) FROM audit WHERE action = 'balance.opening'")->fetchColumn() === 2);
unset($GLOBALS['TC_NOW']);

echo "\n" . ($n - $fails) . "/$n עברו\n";
exit($fails ? 1 : 0);
