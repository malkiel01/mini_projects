<?php
/**
 * בדיקת שלב 3: הצעה, שורות, חישוב חודשי, העברות, ריכוז, מצבים, העתקה לשנה הבאה והערות.
 * המספרים — מהצעת 2026 של ארגון הקהילות.
 * הרצה: php org-budget-proposals/tests/budgets.php
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/ob-budgets-' . bin2hex(random_bytes(4));
mkdir($tmp);
define('OB_DATA_DIR', $tmp);
define('OB_MAIL_SINK', $tmp . '/mail.jsonl');
$_SERVER['REMOTE_ADDR'] = '10.0.0.3';

require __DIR__ . '/../lib/budgets.php';

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
function user(string $email, string $name): array
{
    db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES (?, ?, ?, 'active', ?)")
        ->execute([$email, password_hash('secret-123', PASSWORD_DEFAULT), $name, now_iso()]);
    return current_session(auth_login(['email' => $email, 'password' => 'secret-123'])['token']);
}
function line(array $g, string $title): array
{
    foreach ($g['lines'] as $l) if ($l['title'] === $title) return $l;
    throw new RuntimeException("אין שורה $title");
}

$owner  = user('owner@example.com', 'אבשלום');
$editor = user('editor@example.com', 'נילי');
$viewer = user('viewer@example.com', 'יוסי');

$org = org_create($owner, ['name' => 'ארגון קהילות'])['id'];
member_add($owner, ['org_id' => $org, 'email' => 'editor@example.com', 'role' => 'editor']);
member_add($owner, ['org_id' => $org, 'email' => 'viewer@example.com', 'role' => 'viewer']);
$ops = org_get($owner, $org)['funds'][0]['id'];
$mesila = fund_save($owner, ['org_id' => $org, 'kind' => 'hekdesh', 'name' => 'הקדש ועד העדה', 'address' => "רח' מסילת ישרים 1"])['id'];
$elmalich = fund_save($owner, ['org_id' => $org, 'kind' => 'hekdesh', 'name' => 'הקדש יוסף אלמליח'])['id'];
$sub = org_create($owner, ['name' => 'חברה קדישא', 'parent_id' => $org])['id'];
$subOps = org_get($owner, $sub)['funds'][0]['id'];

echo "חישוב חודשי\n";
check(calc_amount(['monthly' => '65,063'])[0] === 780756, '65,063 × 12 = 780,756');
[$a, $note] = calc_amount(['monthly' => 135000, 'change_month' => 5, 'change_pct' => 5]);
check($a === 1674000, 'בן רז: 135,000 ארבעה חודשים, ומחודש 5 תוספת 5% → 1,674,000 (כמו במסמך)');
check(str_contains($note, '135,000 ₪ לחודש') && str_contains($note, 'תוספת של 5% מחודש 5 (141,750)'), "ההערה בתא: $note");
check(calc_amount(['monthly' => 1000, 'from' => 4, 'to' => 12])[0] === 9000, 'חלק מהשנה: אפריל עד דצמבר');

echo "הצעה ושורות\n";
$b = budget_create($editor, ['org_id' => $org, 'year' => 2026])['id'];
$g = budget_get($viewer, $b);
check(count($g['funds']) === 3 && $g['budget']['status'] === 'draft', 'הצעה חדשה: כל קופות הארגון, טיוטה');
expect_error(fn() => budget_create($viewer, ['org_id' => $org, 'year' => 2026]), 403, 'צופה לא פותח הצעה');
expect_error(fn() => line_save($viewer, ['budget_id' => $b, 'fund_id' => $ops, 'direction' => 'expense', 'title' => 'x', 'amount' => 1]), 403, 'צופה לא עורך שורות');
line_save($editor, ['budget_id' => $b, 'fund_id' => $mesila, 'direction' => 'income', 'title' => 'הקדש ועד העדה', 'subtitle' => "רח' מסילת ישרים 1",
    'calc' => ['monthly' => 65063], 'prev_budget' => '743,600', 'prev_actual' => '620,000']);
foreach ([['מלגות לסטודנטים', 200000], ['אחזקה ויעוץ מקצועי', 100000], ['השת\' בהילולות ספרי קודש ומורשת', 152000], ['סיוע לנזקקים לחגים', 70756]] as [$t, $v]) {
    line_save($editor, ['budget_id' => $b, 'fund_id' => $mesila, 'direction' => 'expense', 'title' => $t, 'amount' => $v]);
}
line_save($editor, ['budget_id' => $b, 'fund_id' => $elmalich, 'direction' => 'income', 'title' => 'בן רז', 'calc' => ['monthly' => 135000, 'change_month' => 5, 'change_pct' => 5]]);
line_save($editor, ['budget_id' => $b, 'fund_id' => $ops, 'direction' => 'income', 'title' => 'הכנסות כלליות', 'amount' => 80000]);
line_save($editor, ['budget_id' => $b, 'fund_id' => $ops, 'direction' => 'expense', 'title' => 'משכורות', 'subtitle' => 'מנהלת חשבונות 50%', 'amount' => 150000]);
$g = budget_get($editor, $b);
check(line($g, 'הקדש ועד העדה')['amount'] === 780756 && line($g, 'הקדש ועד העדה')['calc_note'] === '65,063 ₪ לחודש', 'שורה מחושבת נשמרת עם הסכום וההערה');
check(line($g, 'הקדש ועד העדה')['prev_budget'] === 743600, 'שנה קודמת מוקלדת עם פסיקים');
expect_error(fn() => line_save($editor, ['budget_id' => $b, 'fund_id' => $ops, 'direction' => 'expense', 'title' => 'x', 'amount' => '29,2000x']), 422, 'סכום לא תקין');
expect_error(fn() => line_save($editor, ['budget_id' => $b, 'fund_id' => $subOps, 'direction' => 'expense', 'title' => 'x', 'amount' => 1]), 404, 'קופה של ארגון אחר — לא בהצעה הזו');

echo "העברות\n";
$t = transfer_create($editor, ['budget_id' => $b, 'from_fund_id' => $mesila, 'to_fund_id' => $ops, 'amount' => 258000,
    'title_from' => 'החזר הלוואה מהקדש לארגון (השקעות בנכס)', 'title_to' => 'הכנסות מהחזר הלוואה מהקדשות']);
$g = budget_get($editor, $b);
$bal = array_column($g['summary']['balance'], null, 'fund_id');
check($bal[$mesila]['diff'] === 0, 'הקדש ועד העדה מאוזן: 780,756 מול 780,756 (כמו במסמך)');
line_save($editor, ['budget_id' => $b, 'id' => $t['to_line'], 'title' => 'הכנסות מהחזר הלוואה מהקדשות', 'amount' => 260000]);
$g = budget_get($editor, $b);
check(line($g, 'החזר הלוואה מהקדש לארגון (השקעות בנכס)')['amount'] === 260000, 'שינוי צד אחד של העברה משנה את השני');
check(line($g, 'החזר הלוואה מהקדש לארגון (השקעות בנכס)')['transfer'][0]['fund'] === 'ארגון שוטף', 'כל צד יודע מי הצד השני');
expect_error(fn() => transfer_create($editor, ['budget_id' => $b, 'from_fund_id' => $ops, 'to_fund_id' => $subOps, 'amount' => 1, 'title_from' => 'a', 'title_to' => 'b']),
    409, 'העברה לתת-ארגון בלי הצעה שלו לאותה שנה');
$kb = budget_create($editor, ['org_id' => $sub, 'year' => 2026])['id'];
$t2 = transfer_create($editor, ['budget_id' => $kb, 'from_fund_id' => $subOps, 'to_fund_id' => $ops, 'amount' => 69000,
    'title_from' => 'דמי שימוש לארגון – השת\' באחזקת משרד', 'title_to' => 'דמי שימוש במשרדים חברת קדישא']);
check($t2['to_budget'] === $b && line(budget_get($owner, $b), 'דמי שימוש במשרדים חברת קדישא')['amount'] === 69000,
    'העברה מחבר"ק לארגון — שורה בכל אחת משתי ההצעות, באותו סכום');
line_delete($editor, ['budget_id' => $b, 'id' => $t['to_line']]);
$g = budget_get($editor, $b);
check(!in_array('החזר הלוואה מהקדש לארגון (השקעות בנכס)', array_column($g['lines'], 'title'), true), 'מחיקת צד אחד מוחקת את ההעברה כולה');
$t = transfer_create($editor, ['budget_id' => $b, 'from_fund_id' => $mesila, 'to_fund_id' => $ops, 'amount' => 258000,
    'title_from' => 'החזר הלוואה מהקדש לארגון (השקעות בנכס)', 'title_to' => 'הכנסות מהחזר הלוואה מהקדשות']);

echo "ריכוז\n";
$g = budget_get($editor, $b);
$sections = $g['summary']['sections'];
check($sections[0]['direction'] === 'income', 'הכנסות לפני הוצאות');
$rows = array_merge(...array_map(fn($x) => $x['rows'], $sections));
$hek = array_values(array_filter($rows, fn($r) => $r['label'] === 'הקדשות דתיים' && $r['direction'] === 'income'))[0];
check($hek['amount'] === 780756 + 1674000, 'ברירת מחדל: כל ההקדשות הדתיים בשורת ריכוז אחת');
$r = srow_save($editor, ['budget_id' => $b, 'direction' => 'expense', 'section' => 'הוצאות מהקדשות', 'label' => 'החזר השקעות מהקדשות לארגון'])['id'];
line_save($editor, ['budget_id' => $b, 'id' => $t['from_line'], 'title' => 'החזר הלוואה מהקדש לארגון (השקעות בנכס)', 'amount' => 258000, 'summary_row_id' => $r]);
$g = budget_get($editor, $b);
$sec = array_values(array_filter($g['summary']['sections'], fn($x) => $x['title'] === 'הוצאות מהקדשות'))[0];
check($sec['rows'][0]['amount'] === 258000 && $sec['total']['amount'] === 258000, 'שורה ששויכה לשורת ריכוז — בתוך המקטע שלה, עם סה"כ');
expect_error(fn() => line_save($editor, ['budget_id' => $b, 'id' => line($g, 'הכנסות כלליות')['id'], 'title' => 'הכנסות כלליות', 'amount' => 80000, 'summary_row_id' => $r]),
    422, 'שורת הכנסה לא משויכת לשורת ריכוז של הוצאות');
bfund_set($editor, ['budget_id' => $b, 'fund_id' => $mesila, 'notes_expense' => '* הערה אחרי הטבלה']);
check(array_column(budget_get($editor, $b)['funds'], null, 'id')[$mesila]['notes_expense'] === '* הערה אחרי הטבלה', 'הערה אחרי הטבלה של קופה');
check(budget_get($editor, $b)['summary']['income'] === 780756 + 1674000 + 80000 + 69000 + 258000, 'סה"כ הכנסות');

echo "מצבים ונעילה\n";
budget_status($editor, ['budget_id' => $b, 'status' => 'submitted']);
expect_error(fn() => line_save($editor, ['budget_id' => $b, 'fund_id' => $ops, 'direction' => 'expense', 'title' => 'x', 'amount' => 1]), 409, 'הצעה שהוגשה — נעולה');
expect_error(fn() => line_save($editor, ['budget_id' => $kb, 'id' => line(budget_get($editor, $kb), 'דמי שימוש לארגון – השת\' באחזקת משרד')['id'],
    'title' => 'דמי שימוש', 'amount' => 70000]), 409, 'העברה שהצד השני שלה נעול — לא משתנה');
expect_error(fn() => budget_status($editor, ['budget_id' => $b, 'status' => 'approved']), 403, 'רק בעלים מאשר');
budget_status($owner, ['budget_id' => $b, 'status' => 'approved']);
check(comment_add($viewer, ['budget_id' => $b, 'body' => 'למה סעיף המלגות לא גדל?', 'line_id' => line(budget_get($viewer, $b), 'מלגות לסטודנטים')['id']])['ok'],
    'צופה מעיר — גם על הצעה מאושרת');
$c = budget_get($owner, $b)['comments'][0];
check($c['by'] === 'יוסי' && $c['line_id'] !== null && !$c['resolved'], 'ההערה שמורה עם הכותב והשורה');
expect_error(fn() => comment_set($editor, ['budget_id' => $b, 'id' => $c['id'], 'op' => 'delete']), 403, 'רק הכותב מוחק הערה');
comment_set($editor, ['budget_id' => $b, 'id' => $c['id'], 'op' => 'resolve']);
check(budget_get($owner, $b)['comments'][0]['resolved'], 'עורך מסמן "טופל"');

echo "ניצול והעתקה לשנה הבאה\n";
budget_status($owner, ['budget_id' => $b, 'status' => 'submitted']);
budget_status($editor, ['budget_id' => $b, 'status' => 'draft']);
$ml = line(budget_get($editor, $b), 'מלגות לסטודנטים');
line_save($editor, ['budget_id' => $b, 'id' => $ml['id'], 'title' => 'מלגות לסטודנטים', 'amount' => 200000, 'actual' => '185,000']);
budget_update($editor, ['budget_id' => $b, 'actual_as_of' => '2026-09-30', 'work_plan' => "תכנית עבודה לשנת 2026\n# הקדשות\n- חלוקת קמחא דפסחא", 'signers' => [['name' => 'אבשלום מלכה', 'titles' => ['יו"ר ועדת כספים', 'ממ"מ גזבר הארגון']]]]);
$n = budget_create($editor, ['org_id' => $org, 'year' => 2027, 'based_on_id' => $b])['id'];
$g = budget_get($editor, $n);
$ml2 = line($g, 'מלגות לסטודנטים');
check($ml2['prev_budget'] === 200000 && $ml2['prev_actual'] === 185000 && $ml2['actual'] === null && $ml2['amount'] === 200000,
    '2027 מ-2026: הסכום → תקציב קודם, הניצול → ניצול קודם, והמוצע מתחיל מהשנה שעברה');
check($g['budget']['prev_actual_as_of'] === '2026-09-30', 'תאריך "ניצול עד" עובר לעמודה של השנה הקודמת');
check(str_contains((string)$g['budget']['work_plan'], 'לשנת 2027') && $g['budget']['signers'][0]['titles'][1] === 'ממ"מ גזבר הארגון', 'תכנית העבודה (עם השנה החדשה) והחותמים מועתקים');
check(line($g, 'בן רז')['calc']['change_pct'] == 5, 'החישוב החודשי מועתק');
check(count(array_filter($g['lines'], fn($l) => $l['transfer'] && $l['transfer'][0]['same_budget'])) === 2, 'העברה פנימית מתחברת בשנה החדשה');
$ln = line($g, 'דמי שימוש במשרדים חברת קדישא');
check($ln['transfer'] === [], 'העברה מחבר"ק — מחכה שגם לחבר"ק תיפתח הצעת 2027');
$kn = budget_create($editor, ['org_id' => $sub, 'year' => 2027, 'based_on_id' => $kb])['id'];
check(count(line(budget_get($editor, $n), 'דמי שימוש במשרדים חברת קדישא')['transfer']) === 1, '…וכשנפתחה — ההעברה מתחברת לבד');
check(array_values(array_filter(budget_get($editor, $n)['summary']['sections'], fn($x) => $x['title'] === 'הוצאות מהקדשות'))[0]['rows'][0]['amount'] === 258000,
    'שורות הריכוז והשיוך מועתקים');

echo "עותק ומחיקה\n";
$d = budget_duplicate($editor, ['budget_id' => $n])['id'];
$tl = line(budget_get($editor, $d), 'החזר הלוואה מהקדש לארגון (השקעות בנכס)');
line_save($editor, ['budget_id' => $d, 'id' => $tl['id'], 'title' => $tl['title'], 'amount' => 1]);
check(line(budget_get($editor, $n), 'החזר הלוואה מהקדש לארגון (השקעות בנכס)')['amount'] === 258000, 'שינוי בעותק לא נוגע במקור');
expect_error(fn() => budget_delete($editor, ['budget_id' => $d, 'confirm' => '2026']), 422, 'מחיקה בהקלדת השנה');
budget_delete($editor, ['budget_id' => $d, 'confirm' => '2027']);
check(count(budget_list($owner, $org)['budgets']) === 2, 'נשארו 2026 ו-2027');
check(org_history($owner, $org)['events'][0]['label'] === 'מחיקת הצעה', 'בהיסטוריה של הארגון');

array_map('unlink', array_filter(glob("$tmp/{,.}*", GLOB_BRACE) ?: [], 'is_file'));
@rmdir($tmp);
echo $fails ? "\n✕ $fails בדיקות נכשלו\n" : "\n✓ הכול עבר\n";
exit($fails ? 1 : 0);
