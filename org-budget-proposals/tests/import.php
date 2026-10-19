<?php
/**
 * בדיקת שלב 4: ייבוא מקובץ, העברה מכמה קופות לשורה אחת, שכירויות ונספח, שורה "רק בריכוז",
 * סדר הוצאות-קודם, וניצול שנה N שנלקח מ-N+1. נתונים מומצאים (במבנה של ההצעות האמיתיות).
 * הרצה: php org-budget-proposals/tests/import.php
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/ob-import-' . bin2hex(random_bytes(4));
mkdir($tmp);
define('OB_DATA_DIR', $tmp);
define('OB_MAIL_SINK', $tmp . '/mail.jsonl');
$_SERVER['REMOTE_ADDR'] = '10.0.0.4';

require __DIR__ . '/../lib/import.php';

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
function line(array $g, string $title, ?int $amount = null): array
{
    foreach ($g['lines'] as $l) if ($l['title'] === $title && ($amount === null || $l['amount'] === $amount)) return $l;
    throw new RuntimeException("אין שורה $title");
}
db()->prepare("INSERT INTO users (email, password_hash, display_name, is_developer, status, created_at) VALUES ('dev@example.com', ?, 'מפתח', 1, 'active', ?)")
    ->execute([password_hash('secret-123', PASSWORD_DEFAULT), now_iso()]);
$s = current_session(auth_login(['email' => 'dev@example.com', 'password' => 'secret-123'])['token']);

$L = fn($fund, $dir, $title, $pb, $pa, $amt, $more = []) => ['fund' => $fund, 'direction' => $dir, 'title' => $title,
    'prev_budget' => $pb, 'prev_actual' => $pa, 'amount' => $amt] + $more;
$summary = [
    ['key' => 'h_in', 'direction' => 'income', 'section' => 'הכנסות מהקדשות', 'label' => 'הכנסות מהקדשות דתיים', 'funds' => ['h1', 'h2']],
    ['key' => 'h_ret', 'direction' => 'expense', 'section' => 'הוצאות מהקדשות', 'label' => 'החזר השקעות לארגון'],
    ['key' => 'o_in', 'direction' => 'income', 'section' => 'הכנסות מארגון', 'label' => 'הכנסות שוטף', 'funds' => ['ops']],
];
$year = fn(int $y, array $lines, array $more = []) => ['year' => $y, 'status' => 'approved', 'summary' => $summary,
    'signers' => [['name' => 'גזבר לדוגמה', 'titles' => ['גזבר']]], 'work_plan' => "מבוא\n# הקדשות\n- תבליט", 'lines' => $lines] + $more;
$file = ['format' => IMPORT_FORMAT, 'org' => [
    'name' => 'ארגון לדוגמה', 'subtitle' => 'תת-כותרת',
    'funds' => [['key' => 'ops', 'kind' => 'operating', 'name' => 'שוטף'],
                ['key' => 'h1', 'kind' => 'hekdesh', 'name' => 'הקדש א', 'address' => "רח' א 1"],
                ['key' => 'h2', 'kind' => 'hekdesh', 'name' => 'הקדש ב', 'address' => "רח' ב 2"]],
    'budgets' => [
        $year(2025, [
            $L('ops', 'income', 'הכנסות כלליות', 10, 20, 30),
            $L('ops', 'income', 'נכס', 100, 90, 120, ['subtitle' => 'שוכר א', 'lease' => ['property' => 'רחוב הנכס 3', 'tenant' => 'שוכר א', 'monthly' => '10,000']]),
            $L('ops', 'income', 'נכס', 50, 40, 60, ['subtitle' => 'שוכר ב', 'lease' => true]),
            $L('ops', 'income', 'החזר מהקדשות', 300, 300, 300, ['transfer' => 'ret', 'summary' => 'h_ret_in_ignored_key_is_ok'] ),
            $L('h1', 'income', 'שוכר הקדש', 1200, 1000, 1200, ['monthly' => 100, 'lease' => true]),
            $L('h1', 'income', 'שוכר עם הערה', 3348, 3000, 3400, ['monthly' => 279, 'lease' => true]),
            $L('h1', 'expense', 'החזר לארגון', 200, 200, 200, ['transfer' => 'ret', 'summary' => 'h_ret']),
            $L('h2', 'expense', 'החזר לארגון', 100, 100, 100, ['transfer' => 'ret', 'summary' => 'h_ret']),
        ], ['prev_actual_as_of' => '2024-10-31']),
        $year(2026, [
            $L('ops', 'income', 'הכנסות כלליות', 30, 25, 35),
            $L('ops', 'income', 'החזר מהקדשות', 300, 150, 300, ['transfer' => 'ret']),
            $L('h1', 'income', 'שוכר הקדש', 1200, 900, 1674000, ['monthly' => 135000, 'change_month' => 5, 'change_pct' => 5, 'lease' => true]),
            $L('h1', 'expense', 'החזר לארגון', 200, 50, 200, ['transfer' => 'ret', 'summary' => 'h_ret']),
            $L('h2', 'expense', 'החזר לארגון', 100, 100, 100, ['transfer' => 'ret', 'summary' => 'h_ret']),
        ], ['prev_actual_as_of' => '2025-09-30']),
    ],
    'subs' => [['name' => 'תת-ארגון', 'funds' => [['key' => 'k_ops', 'kind' => 'operating', 'name' => 'שוטף'], ['key' => 'k_dev', 'kind' => 'fund', 'name' => 'קרן פיתוח']],
        'budgets' => [['year' => 2026, 'expense_first' => true, 'summary' => [
            ['key' => 'e1', 'direction' => 'expense', 'section' => 'הוצאות', 'label' => 'שוטף', 'funds' => ['k_ops']],
            ['key' => 'i1', 'direction' => 'income', 'section' => 'הכנסות', 'label' => 'הכנסות שוטף', 'funds' => ['k_ops']],
            ['key' => 'loan', 'direction' => 'income', 'section' => 'הכנסות', 'label' => 'הלוואה מהבנק']],
            'lines' => [
                $L('k_ops', 'expense', 'הוצאה', 0, 0, 500),
                $L('k_ops', 'income', 'הכנסה', 0, 0, 400),
                $L('k_dev', 'income', 'הלוואה', 0, 0, 100, ['summary' => 'loan', 'summary_only' => true]),
            ]]]]],
]];

echo "שגיאות בקובץ\n";
expect_error(fn() => org_import($s, ['data' => ['format' => 'x']]), 422, 'פורמט לא מוכר');
$bad = $file;
unset($bad['org']['budgets'][0]['lines'][3]['summary']);   // מפתח ריכוז מיותר — בקובץ התקין אנחנו מסירים אותו
$bad['org']['budgets'][0]['lines'][7]['amount'] = 101;
expect_error(fn() => org_import($s, ['data' => $bad]), 422, 'העברה שהסכומים שלה לא מתאימים — נעצרת, עם הסבר');
expect_error(fn() => org_import($s, ['data' => $file]), 422, 'שורת ריכוז לא מוכרת — נעצרת');
check((int)db()->query('SELECT COUNT(*) FROM orgs')->fetchColumn() === 0, 'ייבוא שנכשל לא משאיר כלום (טרנזקציה)');

echo "ייבוא\n";
unset($file['org']['budgets'][0]['lines'][3]['summary']);
$r = org_import($s, ['data' => $file]);
check($r['orgs'] === 2 && $r['budgets'] === 3 && $r['transfers'] === 2, "נוצרו: {$r['orgs']} ארגונים, {$r['budgets']} הצעות, {$r['transfers']} העברות");
$list = budget_list($s, $r['org_id'])['budgets'];
$b25 = budget_get($s, $list[1]['id']);
$b26 = budget_get($s, $list[0]['id']);
check($b25['budget']['status'] === 'approved' && $b25['budget']['signers'][0]['name'] === 'גזבר לדוגמה', 'מצב וחותמים');
check(line($b25, 'שוכר הקדש')['calc']['monthly'] === 100, 'חודשי שמתאים לסכום → חישוב חודשי');
$n = line($b25, 'שוכר עם הערה');
check($n['calc'] === null && $n['amount'] === 3400 && $n['cell_note'] === '279 ₪ לחודש', 'חודשי שלא מתאים (279 × 12 ≠ 3,400) → הסכום מהמסמך, וההערה בתא');

echo "ניצול מהשנה הבאה\n";
check(line($b25, 'הכנסות כלליות')['actual'] === 25 && $b25['budget']['actual_as_of'] === '2025-09-30', 'ניצול 2025 = עמודת הניצול של 2026, עם התאריך');
check(line($b25, 'נכס', 120)['actual'] === null && line($b25, 'נכס', 60)['actual'] === null, 'שם שחוזר פעמיים — לא מקבל ניצול של שורה אחרת');

echo "העברה מכמה קופות לשורה אחת\n";
$recv = line($b26, 'החזר מהקדשות');
check(count($recv['transfer']) === 2, 'המקבלת מכירה שתי מעבירות');
budget_status($s, ['budget_id' => $b26['budget']['id'], 'status' => 'submitted']);
budget_status($s, ['budget_id' => $b26['budget']['id'], 'status' => 'draft']);
$g1 = array_values(array_filter($b26['lines'], fn($l) => $l['title'] === 'החזר לארגון' && $l['fund_id'] === $b26['funds'][1]['id']))[0];
line_save($s, ['budget_id' => $b26['budget']['id'], 'id' => $g1['id'], 'title' => 'החזר לארגון', 'amount' => 250, 'prev_budget' => 200, 'prev_actual' => 50, 'summary_row_id' => $g1['summary_row_id']]);
$b26 = budget_get($s, $b26['budget']['id']);
check(line($b26, 'החזר מהקדשות')['amount'] === 350, 'שינוי מעבירה אחת: המקבלת = 250 + 100');
expect_error(fn() => line_save($s, ['budget_id' => $b26['budget']['id'], 'id' => $recv['id'], 'title' => 'החזר מהקדשות', 'amount' => 999]), 409, 'אי אפשר לשנות ישירות סכום שנגזר מכמה מעבירות');
line_save($s, ['budget_id' => $b26['budget']['id'], 'id' => $recv['id'], 'title' => 'החזר מהקדשות — שם חדש', 'amount' => 350]);
check(line(budget_get($s, $b26['budget']['id']), 'החזר מהקדשות — שם חדש')['amount'] === 350, 'שינוי שם בלי לגעת בסכום — מותר');
line_delete($s, ['budget_id' => $b26['budget']['id'], 'id' => $g1['id']]);
$b26 = budget_get($s, $b26['budget']['id']);
check(line($b26, 'החזר מהקדשות — שם חדש')['amount'] === 100 && count(line($b26, 'החזר מהקדשות — שם חדש')['transfer']) === 1,
    'מחיקת מעבירה אחת: רק היא נמחקת, והמקבלת = 100');
$h1 = $b26['funds'][1]['id'];
$t = transfer_create($s, ['budget_id' => $b26['budget']['id'], 'from_fund_id' => $h1, 'to_fund_id' => $b26['funds'][0]['id'],
    'to_line_id' => $recv['id'], 'amount' => 70, 'title_from' => 'החזר לארגון']);
$b26 = budget_get($s, $b26['budget']['id']);
check($t['to_line'] === $recv['id'] && line($b26, 'החזר מהקדשות — שם חדש')['amount'] === 170, 'הצטרפות לשורה מקבלת קיימת: 100 + 70');

echo "שכירויות ונספח\n";
$ap = array_column($b25['appendix'], null, 'title');
$p = $ap['נכסים']['rows'];
check(count($p) === 2 && $p[0]['property'] === 'רחוב הנכס 3' && $p[0]['monthly'] === '10,000' && $p[0]['annual'] === 120, 'שכירות עם פרטים מפורשים');
check($p[1]['property'] === 'נכס' && $p[1]['tenant'] === 'שוכר ב' && $p[1]['monthly'] === '5' && $p[1]['period'] === '01-12/2025', 'שכירות בלי פרטים — נגזרת מהשורה (שם, שורה שנייה, סכום ÷ 12)');
$h = array_column(budget_get($s, $b26['budget']['id'])['appendix'], null, 'title')['הקדשות דתיים']['rows'][0];
check($h['property'] === "הקדש א\nרח' א 1" && $h['tenant'] === 'שוכר הקדש' && $h['monthly'] === '135,000 / 141,750' && $h['period'] === '01-04/2026, 05-12/2026',
    'בהקדש: נכס = הקדש וכתובת, שוכר = השורה; שינוי באמצע השנה — שני סכומים ושתי תקופות');

echo "תת-ארגון: הוצאות קודם, ושורה רק בריכוז\n";
$sub = (int)db()->query('SELECT id FROM orgs WHERE parent_id IS NOT NULL')->fetchColumn();
$k = budget_get($s, budget_list($s, $sub)['budgets'][0]['id']);
check($k['budget']['expense_first'] && $k['summary']['sections'][0]['title'] === 'הוצאות', 'בריכוז — הוצאות לפני הכנסות, לפי הסדר שבקובץ');
check(line($k, 'הלוואה')['summary_only'] && $k['summary']['sections'][1]['rows'][1]['amount'] === 100, 'הלוואה: רק בריכוז, ונספרת שם');
$n = budget_create($s, ['org_id' => $sub, 'year' => 2027, 'based_on_id' => $k['budget']['id']])['id'];
$k27 = budget_get($s, $n);
check($k27['budget']['expense_first'] && line($k27, 'הלוואה')['summary_only'], '2027 מ-2026: הסדר והסימון עוברים');
check(org_history($s, $r['org_id'])['events'][0]['label'] === 'ייבוא מקובץ' || in_array('ייבוא מקובץ', array_column(org_history($s, $r['org_id'])['events'], 'label'), true), 'הייבוא בהיסטוריה של הארגון');

array_map('unlink', array_filter(glob("$tmp/{,.}*", GLOB_BRACE) ?: [], 'is_file'));
@rmdir($tmp);
echo $fails ? "\n✕ $fails בדיקות נכשלו\n" : "\n✓ הכול עבר\n";
exit($fails ? 1 : 0);
