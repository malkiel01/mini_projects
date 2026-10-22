<?php
/**
 * בדיקות: זהות מהפלטפורמה, חברה, סניפים, צוות והפרדה בין חברות (שלב 1);
 * עובדים, תנאים בגרסאות, קישור הזמנה, הפעלה וקוד, מכשיר והרשאות מנהל סניף (שלב 2).
 * הרצה: php time-clock/tests/run.php — על מסד זמני, בלי לגעת ב-data/.
 */

declare(strict_types=1);

define('DB_FILE', sys_get_temp_dir() . '/time-clock-test-' . getmypid() . '.sqlite');
require_once __DIR__ . '/../lib/session.php';

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

@unlink(DB_FILE); @unlink(DB_FILE . '-wal'); @unlink(DB_FILE . '-shm');
echo "\n" . ($n - $fails) . "/$n עברו\n";
exit($fails ? 1 : 0);
