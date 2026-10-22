<?php
/**
 * בדיקות שלב 1: זהות מהפלטפורמה, חברה, סניפים, צוות והפרדה בין חברות.
 * הרצה: php time-clock/tests/run.php — על מסד זמני, בלי לגעת ב-data/.
 */

declare(strict_types=1);

define('DB_FILE', sys_get_temp_dir() . '/time-clock-test-' . getmypid() . '.sqlite');
require_once __DIR__ . '/../lib/company.php';

$fails = 0; $n = 0;
function check(string $name, bool $cond): void {
    global $fails, $n; $n++;
    echo ($cond ? '✓ ' : '✗ ') . $name . "\n";
    if (!$cond) $fails++;
}
function throws(string $name, callable $fn, int $status = 0): void {
    try { $fn(); check($name, false); }
    catch (AppError $e) { check($name . ' — ' . $e->getMessage(), $status === 0 || $e->status === $status); }
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

@unlink(DB_FILE); @unlink(DB_FILE . '-wal'); @unlink(DB_FILE . '-shm');
echo "\n" . ($n - $fails) . "/$n עברו\n";
exit($fails ? 1 : 0);
