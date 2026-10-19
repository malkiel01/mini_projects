<?php
/**
 * בדיקת שלב 2: ארגונים, תת-ארגון, חברים ותפקידים, קופות, גורמים, סעיפים, היסטוריה ואכיפה.
 * הרצה: php org-budget-proposals/tests/orgs.php
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/ob-orgs-' . bin2hex(random_bytes(4));
mkdir($tmp);
define('OB_DATA_DIR', $tmp);
define('OB_MAIL_SINK', $tmp . '/mail.jsonl');
$_SERVER['REMOTE_ADDR'] = '10.0.0.2';

require __DIR__ . '/../lib/budgets.php';   // org_get סופר גם הצעות

$fails = 0;
function check(bool $ok, string $what): void
{
    global $fails;
    echo ($ok ? '  ✓ ' : '  ✕ ') . $what . "\n";
    if (!$ok) $fails++;
}
function expect_error(callable $fn, int $status, string $what): void
{
    try { $fn(); check(false, "$what (לא נזרקה שגיאה)"); }
    catch (ApiError $e) { check($e->status === $status, "$what → {$e->status} {$e->getMessage()}"); }
}
/** משתמש פעיל ישירות במסד (ההרשמה נבדקת ב-tests/auth.php), ומחזיר session. */
function user(string $email, string $name, bool $dev = false): array
{
    db()->prepare("INSERT INTO users (email, password_hash, display_name, is_developer, status, created_at) VALUES (?, ?, ?, ?, 'active', ?)")
        ->execute([$email, password_hash('secret-123', PASSWORD_DEFAULT), $name, $dev ? 1 : 0, now_iso()]);
    return current_session(auth_login(['email' => $email, 'password' => 'secret-123'])['token']);
}

$dev   = user('dev@example.com', 'המפתח', true);
$nili  = user('nili@example.com', 'נילי');
$yossi = user('yossi@example.com', 'יוסי');
$out   = user('out@example.com', 'זר');

echo "ארגון ותת-ארגון\n";
$org = org_create($nili, ['name' => 'ארגון קהילות יהודי צפון אפריקה', 'subtitle' => 'מיסודו של ועד העדה המערבית'])['id'];
$g = org_get($nili, $org);
check($g['org']['role'] === 'owner', 'הפותח הוא בעלים');
check(count($g['funds']) === 1 && $g['funds'][0]['kind'] === 'operating', 'נוצרה קופה שוטפת');
$sub = org_create($nili, ['name' => 'חברה קדישא לעדת המערבים', 'parent_id' => $org])['id'];
expect_error(fn() => org_create($nili, ['name' => 'נכד', 'parent_id' => $sub]), 422, 'אין תת-ארגון של תת-ארגון');
$gs = org_get($nili, $sub);
check($gs['org']['parent']['id'] === $org, 'תת-הארגון מכיר את ההורה');
check(count(org_list($nili)['orgs']) === 1 && org_list($nili)['orgs'][0]['subs'] === 1, 'ברשימה — רק הראשי, עם מונה תתי-ארגונים');
expect_error(fn() => org_get($out, $org), 404, 'זר לא רואה את הארגון (וגם לא שהוא קיים)');
expect_error(fn() => org_get($out, $sub), 404, 'וגם לא את תת-הארגון');
expect_error(fn() => org_create($nili, ['name' => '']), 422, 'שם חובה');

echo "חברים ותפקידים\n";
expect_error(fn() => member_add($nili, ['org_id' => $org, 'email' => 'nobody@example.com', 'role' => 'editor']), 404, 'אי אפשר להוסיף מי שלא רשום');
member_add($nili, ['org_id' => $org, 'email' => 'yossi@example.com', 'role' => 'viewer']);
expect_error(fn() => member_add($nili, ['org_id' => $org, 'email' => 'yossi@example.com', 'role' => 'viewer']), 409, 'לא פעמיים');
check(org_get($yossi, $sub)['org']['role'] === 'viewer', 'החברות בראשי חלה גם על תת-הארגון');
expect_error(fn() => fund_save($yossi, ['org_id' => $org, 'name' => 'הקדש', 'kind' => 'hekdesh']), 403, 'צופה לא עורך');
expect_error(fn() => member_add($yossi, ['org_id' => $org, 'email' => 'out@example.com']), 403, 'צופה לא מוסיף חברים');
member_set($nili, ['org_id' => $org, 'user_id' => $yossi['id'], 'role' => 'editor']);
expect_error(fn() => member_add($yossi, ['org_id' => $org, 'email' => 'out@example.com']), 403, 'עורך לא מוסיף חברים');
expect_error(fn() => member_set($nili, ['org_id' => $org, 'user_id' => $nili['id'], 'role' => 'editor']), 409, 'הבעלים היחיד לא מוריד את עצמו');
expect_error(fn() => member_remove($nili, ['org_id' => $org, 'user_id' => $nili['id']]), 409, 'הבעלים היחיד לא עוזב');

echo "קופות\n";
$f1 = fund_save($yossi, ['org_id' => $org, 'kind' => 'hekdesh', 'name' => 'הקדש ועד העדה', 'address' => "רח' מסילת ישרים 1"])['id'];
$f2 = fund_save($yossi, ['org_id' => $org, 'kind' => 'secular', 'name' => 'הקדש עמיאל — חילוני', 'address' => "רח' פרי חדש 64"])['id'];
$fs = fund_save($yossi, ['org_id' => $sub, 'kind' => 'fund', 'name' => 'קרן פיתוח'])['id'];
check(count(org_get($nili, $org)['funds']) === 3, 'שלוש קופות בארגון הראשי');
check(count(org_get($nili, $sub)['funds']) === 2, 'ולתת-הארגון קופות משלו');
expect_error(fn() => fund_save($yossi, ['org_id' => $org, 'kind' => 'xx', 'name' => 'x']), 422, 'סוג קופה לא מוכר');
expect_error(fn() => fund_save($yossi, ['org_id' => $org, 'id' => $fs, 'name' => 'גניבה', 'kind' => 'fund']), 404, 'אי אפשר לערוך קופה של ארגון אחר דרך הארגון הזה');
$ids = array_column(org_get($nili, $org)['funds'], 'id');
fund_order($yossi, ['org_id' => $org, 'ids' => array_reverse($ids)]);
check(array_column(org_get($nili, $org)['funds'], 'id') === array_reverse($ids), 'סדר הקופות נשמר');
fund_archive($yossi, ['org_id' => $org, 'id' => $f2]);
check(count(org_get($nili, $org)['funds']) === 2, 'הסרת קופה');

echo "גורמים וסעיפים\n";
$c = contact_save($yossi, ['org_id' => $sub, 'kind' => 'customer', 'name' => 'בן רז', 'phone' => '050-1234567'])['id'];
check(count(org_get($nili, $org)['contacts']) === 1, 'גורם שנוסף מתת-הארגון — משותף לכל הארגון');
expect_error(fn() => contact_save($yossi, ['org_id' => $org, 'name' => 'x', 'email' => 'not-mail']), 422, 'מייל לא תקין');
contact_save($yossi, ['org_id' => $org, 'id' => $c, 'kind' => 'both', 'name' => 'בן רז בע"מ']);
check(org_get($nili, $org)['contacts'][0]['kind'] === 'both', 'עדכון גורם');
cat_save($yossi, ['org_id' => $org, 'kind' => 'expense', 'name' => 'משרדיות']);
cat_save($yossi, ['org_id' => $org, 'kind' => 'income', 'name' => 'הכנסות מתרומות']);
expect_error(fn() => cat_save($yossi, ['org_id' => $org, 'kind' => 'expense', 'name' => 'משרדיות']), 409, 'סעיף כפול');
$cats = org_get($nili, $org)['categories'];
check(count($cats) === 2, 'שני סעיפים');
cat_archive($yossi, ['org_id' => $org, 'id' => $cats[0]['id']]);
check(count(org_get($nili, $org)['categories']) === 1, 'הסרת סעיף');

echo "היסטוריה, עזיבה ומחיקה\n";
$h = org_history($nili, $org)['events'];
check(count($h) >= 10 && $h[0]['label'] === 'הסרת סעיף' && $h[0]['by'] === 'יוסי', 'ההיסטוריה: מי עשה מה, האחרון למעלה');
member_remove($yossi, ['org_id' => $org, 'user_id' => $yossi['id']]);
expect_error(fn() => org_get($yossi, $org), 404, 'מי שעזב — כבר לא רואה');
expect_error(fn() => org_delete($nili, ['org_id' => $org, 'confirm' => 'שם אחר']), 422, 'מחיקה דורשת הקלדת השם');
org_delete($nili, ['org_id' => $org, 'confirm' => 'ארגון קהילות יהודי צפון אפריקה']);
expect_error(fn() => org_get($nili, $sub), 404, 'מחיקת הראשי מוחקת גם את תת-הארגון');
check(org_list($nili)['orgs'] === [], 'הרשימה ריקה');

echo "אכיפה של המפתח\n";
check(dev_orgs([])['orgs'][0]['deleted'] === true, 'המפתח רואה את הארגון המחוק (מטא-דאטה)');
expect_error(fn() => dev_org_force($dev, ['op' => 'restore', 'id' => $org, 'confirm' => (string)$org]), 422, 'בלי סיבה — לא');
expect_error(fn() => dev_org_force($dev, ['op' => 'restore', 'id' => $org, 'reason' => 'נמחק בטעות', 'confirm' => '0']), 422, 'בלי הקלדת המזהה — לא');
dev_org_force($dev, ['op' => 'restore', 'id' => $org, 'reason' => 'נמחק בטעות', 'confirm' => (string)$org]);
check(org_get($nili, $sub)['org']['parent']['id'] === $org, 'שחזור מחזיר גם את תת-הארגון');
check(dev_audit_list()['audit'][0]['label'] === 'שחזור ארגון', 'נרשם ביומן הביקורת');
check(org_history($nili, $org)['events'][0]['action'] === 'dev_force', 'ובהיסטוריה של הארגון — שמפתח התערב');

array_map('unlink', array_filter(glob("$tmp/{,.}*", GLOB_BRACE) ?: [], 'is_file'));
@rmdir($tmp);
echo $fails ? "\n✕ $fails בדיקות נכשלו\n" : "\n✓ הכול עבר\n";
exit($fails ? 1 : 0);
