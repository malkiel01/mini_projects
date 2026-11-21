<?php
/**
 * בדיקת B1 — "הדוח יוצא מאוזן": הנספח נגזר מהשורה (הזנה דו-צדדית), budget_integrity() על כל הכללים,
 * והאזהרה במסמך המופק. ובסוף — ההצעה של 2027 (tests/fixtures/budget-2027.json: המבנה והמספרים
 * מ-docs/owner-brief.md §4, שמות השוכרים בדויים).
 * הרצה: php org-budget-proposals/tests/integrity.php
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/ob-integrity-' . bin2hex(random_bytes(4));
mkdir($tmp);
define('OB_DATA_DIR', $tmp);
define('OB_MAIL_SINK', $tmp . '/mail.jsonl');
define('OB_EXPORT_SINK', $tmp . '/out');
$_SERVER['REMOTE_ADDR'] = '10.0.0.7';

require __DIR__ . '/../lib/export.php';
require_once __DIR__ . '/../lib/import.php';

$fails = 0;
function check(bool $ok, string $what): void
{
    global $fails;
    echo ($ok ? '  ✓ ' : '  ✕ ') . $what . "\n";
    if (!$ok) $fails++;
}
function line(array $g, string $title): array
{
    foreach ($g['lines'] as $l) if ($l['title'] === $title) return $l;
    throw new RuntimeException("אין שורה $title");
}
function rules(array $g): array
{
    $r = array_map(fn($x) => $x['rule'] . ($x['col'] ? ':' . $x['col'] : ''), $g['integrity']);
    sort($r);
    return $r;
}
function export(callable $fn, array $s, int $id, string $ext): string
{
    try { $fn($s, $id); } catch (RuntimeException $e) { if ($e->getMessage() !== 'sink') throw $e; }
    return OB_EXPORT_SINK . ".$ext";
}
function zip_part(string $file, string $part): string
{
    $z = new ZipArchive();
    $z->open($file);
    $x = (string)$z->getFromName($part);
    $z->close();
    return str_ends_with($part, 'document.xml') ? strip_tags(str_replace('</w:p>', "\n", $x)) . (str_contains($x, 'C00000') ? '[אדום]' : '') : $x;
}

db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES ('g@example.com', ?, 'גזבר', 'active', ?)")
    ->execute([password_hash('secret-123', PASSWORD_DEFAULT), now_iso()]);
$s = current_session(auth_login(['email' => 'g@example.com', 'password' => 'secret-123'])['token']);

$org = org_create($s, ['name' => 'ארגון'])['id'];
$ops = org_get($s, $org)['funds'][0]['id'];
$vaad = fund_save($s, ['org_id' => $org, 'kind' => 'hekdesh', 'name' => 'הקדש ועד העדה', 'address' => "רח' מסילת ישרים 1"])['id'];
$sas = fund_save($s, ['org_id' => $org, 'kind' => 'hekdesh', 'name' => 'הקדש ששפורטס'])['id'];
$b = budget_create($s, ['org_id' => $org, 'year' => 2027])['id'];

echo "1. הנספח = השורה: השנתי מהסכום, החודשי מהחישוב, בלי ערך ידני שגובר\n";
$id = line_save($s, ['budget_id' => $b, 'fund_id' => $vaad, 'direction' => 'income', 'title' => 'שוכר א', 'calc' => ['monthly' => 72300],
    'lease' => ['monthly' => '65,063', 'annual' => 780756]])['id'];   // כמו בנספח שלא עודכן מ-2026
foreach ([['מלגות', 450000], ['סיוע לנזקקים', 417600]] as [$t, $v]) line_save($s, ['budget_id' => $b, 'fund_id' => $vaad, 'direction' => 'expense', 'title' => $t, 'amount' => $v]);
$g = budget_get($s, $b);
$r = $g['appendix'][0]['rows'][0];
check($r['monthly'] === '72,300' && $r['annual'] === 867600, 'רמי אמיר: 72,300 / 867,600 — לא 65,063 / 780,756 שהוקלדו');
check($g['appendix'][0]['annual'] === 867600 && $g['appendix'][0]['monthly'] === 72300, 'סה"כ הקבוצה מאותן שורות');
$st = db()->prepare('SELECT lease_monthly, lease_annual FROM budget_lines WHERE id = ?');
$st->execute([$id]);
check($st->fetch() === ['lease_monthly' => '65,063', 'lease_annual' => 780756], 'הערכים הידניים הישנים נשארו במסד (לא נמחקים)');
check(line($g, 'שוכר א')['lease']['legacy'] === ['monthly' => '65,063', 'annual' => 780756], '…ומוצגים "לא בשימוש"');

echo "10. הזנה דו-צדדית: עריכה של השורה (מכל מקום) משנה את הנספח\n";
line_save($s, ['budget_id' => $b, 'id' => $id, 'title' => 'שוכר א', 'calc' => ['monthly' => 75000], 'lease' => ['period' => '']]);
$g = budget_get($s, $b);
check($g['appendix'][0]['rows'][0]['annual'] === 900000 && $g['appendix'][0]['rows'][0]['monthly'] === '75,000', 'חודשי 75,000 → בנספח 75,000 / 900,000');
$st->execute([$id]);
check($st->fetch()['lease_annual'] === 780756, 'עריכה לא נוגעת בערך הישן');
lines_batch($s, ['budget_id' => $b, 'lines' => [['id' => line($g, 'מלגות')['id'], 'amount' => 482400]]]);
line_save($s, ['budget_id' => $b, 'id' => $id, 'title' => 'שוכר א', 'calc' => ['monthly' => 72300], 'lease' => []]);
lines_batch($s, ['budget_id' => $b, 'lines' => [['id' => line($g, 'מלגות')['id'], 'amount' => 450000]]]);
$fix = line_save($s, ['budget_id' => $b, 'fund_id' => $ops, 'direction' => 'income', 'title' => 'חניה', 'amount' => 30000, 'lease' => ['period' => '01-06/2027']])['id'];
line_save($s, ['budget_id' => $b, 'fund_id' => $ops, 'direction' => 'expense', 'title' => 'הוצאות', 'amount' => 30000]);
$g = budget_get($s, $b);
$park = array_column(array_column($g['appendix'], null, 'title')['נכסים']['rows'], null, 'line_id')[$fix];
check($park['monthly'] === '5,000' && $park['months'] === 6, 'בלי חישוב: חודשי = 30,000 ÷ 6 חודשים (לפי התקופה)');
check($g['integrity'] === [], 'הצעה מאוזנת — אין אף בעיה');

echo "3, 7. איזון בעמודות ההצעה\n";
line_save($s, ['budget_id' => $b, 'fund_id' => $sas, 'direction' => 'income', 'title' => 'שוכר ה', 'amount' => 32400, 'prev_budget' => 0, 'prev_actual' => 31200]);
line_save($s, ['budget_id' => $b, 'fund_id' => $sas, 'direction' => 'expense', 'title' => 'אחזקה', 'amount' => 32400, 'prev_budget' => 31200, 'prev_actual' => 31200]);
$g = budget_get($s, $b);
$bal = array_values(array_filter($g['integrity'], fn($x) => $x['rule'] === 'fund_balance'));
check(count($bal) === 1 && $bal[0]['fund_id'] === $sas && $bal[0]['col'] === 'prev_budget' && $bal[0]['diff'] === -31200,
    'ששפורטס בעמודת "הצעת 2026": 0 מול 31,200 — בעיה, עם ההפרש והקופה');
check(str_contains($bal[0]['msg'], 'הצעת 2026') && str_contains($bal[0]['msg'], '31,200'), "ההודעה: {$bal[0]['msg']}");
check(in_array('summary_balance:prev_budget', rules($g), true), 'וגם הריכוז לא מאוזן באותה עמודה');
check(!in_array('fund_balance:amount', rules($g), true), 'עמודת השנה — מאוזנת, ולא מסומנת');

echo "2. הנספח = שורת הריכוז: הכנסה בהקדש שלא סומנה שכירות\n";
$miss = array_values(array_filter($g['integrity'], fn($x) => $x['rule'] === 'appendix_row'));
check(count($miss) === 1 && $miss[0]['line_ids'] === [line($g, 'שוכר ה')['id']] && $miss[0]['diff'] === 32400,
    'ששפורטס חסר בנספח — 32,400, עם השורה');
line_lease($s, ['budget_id' => $b, 'id' => line($g, 'שוכר ה')['id'], 'on' => 1]);
$g = budget_get($s, $b);
$hekRow = array_values(array_filter(array_merge(...array_map(fn($x) => $x['rows'], $g['summary']['sections'])), fn($r) => $r['label'] === 'הקדשות דתיים' && $r['direction'] === 'income'))[0];
check(!in_array('appendix_row', rules($g), true) && array_column($g['appendix'], null, 'title')['הקדשות דתיים']['annual'] === $hekRow['amount'],
    '🏠 מהנספח: הוסף — סה"כ הקבוצה = שורת הריכוז (' . number_format($hekRow['amount']) . ')');
line_save($s, ['budget_id' => $b, 'id' => line($g, 'שוכר ה')['id'], 'title' => 'שוכר ה', 'amount' => 32400, 'prev_budget' => 31200, 'prev_actual' => 31200, 'lease' => []]);
check(budget_get($s, $b)['integrity'] === [], 'אחרי התיקון — אין בעיות');

echo "4. חודשי × חודשים = שנתי\n";
$x = line_save($s, ['budget_id' => $b, 'fund_id' => $ops, 'direction' => 'income', 'title' => 'קרן', 'amount' => 3000, 'cell_note' => '250 ₪ לחודש'])['id'];
line_save($s, ['budget_id' => $b, 'fund_id' => $ops, 'direction' => 'expense', 'title' => 'מענק', 'amount' => 3000]);
check(budget_get($s, $b)['integrity'] === [], '250 × 12 = 3,000 — תקין');
line_save($s, ['budget_id' => $b, 'id' => $x, 'title' => 'קרן', 'amount' => 2400, 'cell_note' => '250 ₪ לחודש']);
line_save($s, ['budget_id' => $b, 'id' => line(budget_get($s, $b), 'מענק')['id'], 'title' => 'מענק', 'amount' => 2400]);
$m = array_values(array_filter(budget_get($s, $b)['integrity'], fn($x) => $x['rule'] === 'monthly'));
check(count($m) === 1 && $m[0]['line_ids'] === [$x], 'הערה "250 ₪ לחודש" מול 2,400 לשנה — בעיה (כמו קרן המרכז העולמי)');
line_save($s, ['budget_id' => $b, 'id' => $x, 'title' => 'קרן', 'amount' => 2400]);
line_save($s, ['budget_id' => $b, 'id' => $id, 'title' => 'שוכר א', 'calc' => ['monthly' => 72300], 'lease' => ['period' => '01-06/2027']]);
check(in_array('monthly:amount', rules(budget_get($s, $b)), true), 'תקופה בנספח (6 חודשים) מול חישוב של 12 — בעיה');
line_save($s, ['budget_id' => $b, 'id' => $id, 'title' => 'שוכר א', 'calc' => ['monthly' => 72300], 'lease' => []]);

echo "5. העברה — שני הצדדים שווים\n";
$t = transfer_create($s, ['budget_id' => $b, 'from_fund_id' => $vaad, 'to_fund_id' => $ops, 'amount' => 1000, 'title_from' => 'החזר', 'title_to' => 'החזר מהקדש']);
line_save($s, ['budget_id' => $b, 'id' => line(budget_get($s, $b), 'מלגות')['id'], 'title' => 'מלגות', 'amount' => 449000]);
line_save($s, ['budget_id' => $b, 'id' => line(budget_get($s, $b), 'הוצאות')['id'], 'title' => 'הוצאות', 'amount' => 31000]);
check(budget_get($s, $b)['integrity'] === [], 'העברה של 1,000 — שני הצדדים, מאוזן');
db()->prepare('UPDATE budget_lines SET prev_budget = 5 WHERE id = ?')->execute([$t['to_line']]);   // צד אחד ששונה בלי השני (נתונים ישנים)
$tr = array_values(array_filter(budget_get($s, $b)['integrity'], fn($x) => $x['rule'] === 'transfer'));
check(count($tr) === 1 && $tr[0]['col'] === 'prev_budget' && count($tr[0]['line_ids']) === 2, 'צד אחד של העברה ששונה — בעיה, על שתי השורות');
db()->prepare('UPDATE budget_lines SET prev_budget = NULL WHERE id = ?')->execute([$t['to_line']]);

echo "11. המסמך המופק: אזהרה בראש — ורק כשיש בעיה\n";
$f = export('export_docx', $s, $b, 'docx');
check(!str_contains(zip_part($f, 'word/document.xml'), 'ההצעה לא מאוזנת'), 'Word של הצעה מאוזנת — בלי אזהרה');
line_save($s, ['budget_id' => $b, 'fund_id' => $sas, 'direction' => 'expense', 'title' => 'תוספת', 'amount' => 500]);
$xml = zip_part(export('export_docx', $s, $b, 'docx'), 'word/document.xml');
$at = mb_strpos($xml, 'ההצעה לא מאוזנת');
check($at !== false && $at < mb_strpos($xml, 'הצעת תקציב לשנת 2027') && str_contains($xml, '[אדום]'), 'Word: "ההצעה לא מאוזנת" באדום, לפני השער');
check(str_contains($xml, 'גירעון 500'), 'Word: עם ההפרש ואיפה');
$x = export('export_xlsx', $s, $b, 'xlsx');
check(str_contains(zip_part($x, 'xl/workbook.xml'), '<sheet name="⚠️ אזהרות" sheetId="1"'), 'אקסל: גיליון "אזהרות" ראשון');
$d = doc_model($s, $b);
check(count($d['integrity']) === count(budget_get($s, $b)['integrity']) && $d['integrity'][0]['rule'] === 'fund_balance', 'מודל המסמך (הדפסה / PDF) — אותה רשימה');
$ap = zip_part($x, 'xl/worksheets/sheet' . count(array_filter(explode('<sheet ', zip_part($x, 'xl/workbook.xml')), fn($p) => str_contains($p, 'sheetId'))) . '.xml');
check(str_contains($ap, '!E'), 'אקסל: השנתי בנספח = הפניה לתא של השורה בגיליון הקופה');

echo "9. ההצעה של 2027 (מבנה ומספרים אמיתיים, שמות בדויים)\n";
$data = json_decode((string)file_get_contents(__DIR__ . '/fixtures/budget-2027.json'), true);
$imp = org_import($s, ['data' => $data]);
$rb = budget_list($s, $imp['org_id'])['budgets'][0]['id'];
$g = budget_get($s, $rb);
$hekRow = fn($g) => array_values(array_filter(array_merge(...array_map(fn($x) => $x['rows'], $g['summary']['sections'])), fn($r) => $r['label'] === 'הכנסות מהקדשות דתיים'))[0];
$hr = $hekRow($g);
check([$hr['amount'], $hr['prev_budget'], $hr['prev_actual']] === [3099360, 1869360, 3099905], '"הכנסות מהקדשות דתיים": 3,099,360 · 1,869,360 · 3,099,905 — כמו בעמ\' 3');
$rami = array_column(array_column($g['appendix'], null, 'title')['הקדשות דתיים']['rows'], null, 'tenant')['שוכר א (בדוי)'];
check($rami['monthly'] === '72,300' && $rami['annual'] === 867600, 'בנספח: 72,300 / 867,600 — תואם את עמוד ההקדש (לא 65,063 / 780,756)');
check(rules($g) === ['appendix_row:amount', 'fund_balance:prev_budget', 'fund_balance:prev_budget', 'summary_balance:prev_budget'],
    'הבעיות — בדיוק במקומות הידועים: ' . implode(', ', rules($g)));
$names = array_column($g['funds'], 'name', 'id');
$fb = array_map(fn($x) => $names[$x['fund_id']], array_filter($g['integrity'], fn($x) => $x['rule'] === 'fund_balance'));
sort($fb);
check($fb === ['הקדש ועד העדה', 'הקדש ששפורטס'], 'הצעת 2026: ועד העדה (0 מול 780,760) וששפורטס (0 מול 31,200)');
$ar = array_values(array_filter($g['integrity'], fn($x) => $x['rule'] === 'appendix_row'))[0];
check($ar['diff'] === 32400 && str_contains($ar['msg'], 'ששפורטס'), 'ששפורטס חסר בנספח — 32,400');
line_lease($s, ['budget_id' => $rb, 'id' => $ar['line_ids'][0], 'on' => 1]);
$g = budget_get($s, $rb);
check(array_column($g['appendix'], null, 'title')['הקדשות דתיים']['annual'] === $hekRow($g)['amount'], 'אחרי 🏠: סה"כ הנספח (הקדשות דתיים) = 3,099,360 = שורת הריכוז');
check(rules($g) === ['fund_balance:prev_budget', 'fund_balance:prev_budget', 'summary_balance:prev_budget'], 'נשארו רק שתי הקופות של 2026 (והריכוז שלהן)');
$xml = zip_part(export('export_docx', $s, $rb, 'docx'), 'word/document.xml');
check(str_contains($xml, 'הקדש ועד העדה · הצעת 2026: הכנסות 0 מול הוצאות 780,760') && str_contains($xml, 'הקדש ששפורטס · הצעת 2026'), 'בראש ה-Word: ועד העדה וששפורטס');

echo "12. B1.2 — אזעקות שווא: ניצול לא נבדק לאיזון; סה\"כ קופה ממקור אחד\n";
// המצב של 12:36: הבעלים תיקן את הצעת 2026 (ועד העדה, ששפורטס), והניצול — מה שקרה בפועל — לא מאוזן
$upd = db()->prepare('UPDATE budget_lines SET prev_budget = ? WHERE id = ?');
$upd->execute([780760, line($g, 'שוכר א (בדוי)')['id']]);
$upd->execute([31200, line($g, 'שוכר ה (בדוי)')['id']]);
$ua = db()->prepare('UPDATE budget_lines SET prev_actual = ? WHERE id = ?');
$ua->execute([205000, line($g, 'הכנסות כלליות')['id']]);      // ארגון שוטף: עודף 15,000 בניצול
$ua->execute([350000, line($g, 'מלגות לסטודנטים')['id']]);    // ועד העדה: עודף 50,000 בניצול
$g = budget_get($s, $rb);
$act = fn($fund) => array_sum(array_map(fn($l) => ($l['direction'] === 'income' ? 1 : -1) * (int)$l['prev_actual'],
    array_filter($g['lines'], fn($l) => $l['fund_id'] === $fund)));
$byName = array_flip($names);
check($act($byName['ארגון שוטף']) === 15000 && $act($byName['הקדש ועד העדה']) === 50000, 'הניצול לא מאוזן בשתי קופות (עודף 15,000 ו-50,000)');
check($g['integrity'] === [], 'הצעת 2027 — 0 בעיות (ניצול לא מאוזן אינו בעיה): ' . implode(', ', rules($g)));
check(!array_filter(array_column($g['integrity'], 'col'), fn($c) => $c === 'prev_actual'), 'אין אף התראה בעמודת הניצול');
$xml = zip_part(export('export_docx', $s, $rb, 'docx'), 'word/document.xml');
check(!str_contains($xml, 'ההצעה לא מאוזנת'), 'Word — בלי עמוד אזהרה');
check(doc_model($s, $rb)['integrity'] === [], 'מודל המסמך (הדפסה) — בלי אזהרה');
// הריכוז = סכום הקופות עדיין נבדק בכל העמודות (גם בניצול) — רק האיזון לא
check(in_array('prev_actual', array_keys(integrity_cols(2027)), true) && array_keys(balance_cols(2027)) === ['prev_budget', 'amount'], 'איזון: רק הצעת 2026 והצעת 2027');

// קופה שכל הכנסתה "רק בריכוז": הטבלה מראה 0 — גם האיזון סופר 0, וגם התג
$rent = line($g, 'שוכר ו (בדוי)')['id'];
db()->prepare('UPDATE budget_lines SET summary_only = 1 WHERE id = ?')->execute([$rent]);
$g = budget_get($s, $rb);
$sec = $byName['הקדש עמיאל חילוני'];
$fb = array_values(array_filter($g['integrity'], fn($x) => $x['rule'] === 'fund_balance' && $x['fund_id'] === $sec));
check(array_column($fb, 'col') === ['prev_budget', 'amount'] && $fb[1]['diff'] === -2400, 'הכנסה "רק בריכוז" — הקופה לא מאוזנת (0 מול 2,400), בשתי עמודות ההצעה');
$bal = array_column($g['summary']['balance'], null, 'fund_id')[$sec];
check($bal['income'] === 0 && $bal['diff'] === -2400, 'הסה"כ של הקופה (התג והכותרת) — 0 הכנסות, כמו בטבלה');
$d = doc_model($s, $rb);
$df = array_column($d['funds'], null, 'id')[$sec];
check(array_column($df['tables'], 'dir') === ['expense'], 'במסמך — לקופה רק טבלת הוצאות (ההכנסה "רק בריכוז" לא בטבלה)');
check(!array_filter($g['integrity'], fn($x) => $x['rule'] === 'summary_total'), 'בריכוז השורה נספרת (הריכוז = סכום השורות)');
db()->prepare('UPDATE budget_lines SET summary_only = 0 WHERE id = ?')->execute([$rent]);
check(budget_get($s, $rb)['integrity'] === [], 'בלי "רק בריכוז" — שוב 0 בעיות');

array_map('unlink', array_filter(glob("$tmp/{,.}*", GLOB_BRACE) ?: [], 'is_file'));
@rmdir($tmp);
echo $fails ? "\n✕ $fails בדיקות נכשלו\n" : "\n✓ הכול עבר\n";
exit($fails ? 1 : 0);
