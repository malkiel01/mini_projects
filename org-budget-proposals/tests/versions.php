<?php
/**
 * גרסאות, השבתה, הוצאת קופה, מחיקת כמה שורות וסל מחזור.
 * הרצה: php org-budget-proposals/tests/versions.php
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/ob-versions-' . bin2hex(random_bytes(4));
mkdir($tmp);
define('OB_DATA_DIR', $tmp);
define('OB_MAIL_SINK', $tmp . '/mail.jsonl');
$_SERVER['REMOTE_ADDR'] = '10.0.0.5';

require __DIR__ . '/../lib/export.php';

$fails = 0;
function check(bool $ok, string $what): void
{
    global $fails;
    echo ($ok ? '  ✓ ' : '  ✕ ') . $what . "\n";
    if (!$ok) $fails++;
}
function fails_with(callable $fn, int $status): bool
{
    try { $fn(); return false; } catch (ApiError $e) { return $e->status === $status; }
}
/** כמו api.php: גרסה לפני הפעולה, ואז הפעולה. */
function act(array $s, string $action, array $body): array
{
    version_before($s, $action, $body);
    return $action($s, $body);
}

db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES ('a@example.com', ?, 'גזבר', 'active', ?)")
    ->execute([password_hash('secret-123', PASSWORD_DEFAULT), now_iso()]);
$s = current_session(auth_login(['email' => 'a@example.com', 'password' => 'secret-123'])['token']);

$L = fn($fund, $dir, $title, $amt, $more = []) => ['fund' => $fund, 'direction' => $dir, 'title' => $title, 'amount' => $amt] + $more;
$r = org_import($s, ['data' => ['format' => IMPORT_FORMAT, 'org' => [
    'name' => 'ארגון', 'funds' => [['key' => 'ops', 'kind' => 'operating', 'name' => 'שוטף'],
        ['key' => 'h1', 'kind' => 'hekdesh', 'name' => 'הקדש ישן'], ['key' => 'h2', 'kind' => 'hekdesh', 'name' => 'הקדש פעיל']],
    'budgets' => [['year' => 2026, 'lines' => [
        $L('ops', 'income', 'הכנסה', 1000), $L('ops', 'income', 'החזר מהקדש', 200, ['transfer' => 't']), $L('ops', 'expense', 'הוצאה', 1200),
        $L('h1', 'income', 'שכירות', 500), $L('h1', 'expense', 'החזר', 200, ['transfer' => 't']), $L('h1', 'expense', 'מלגות', 300),
        $L('h2', 'income', 'שכירות ב', 100), $L('h2', 'expense', 'מלגות ב', 60), $L('h2', 'expense', 'שונות ב', 40),
    ]]]]]]);
$orgId = $r['org_id'];
$bid = budget_list($s, $orgId)['budgets'][0]['id'];
$g = fn() => budget_get($s, $bid);
$line = fn($t) => current(array_filter($g()['lines'], fn($l) => $l['title'] === $t)) ?: null;
$fund = fn($n) => current(array_filter($g()['funds'], fn($f) => $f['name'] === $n)) ?: null;
$vcount = fn() => count(versions_list($s, $bid)['versions']);

echo "גרסאות\n";
$inc = $line('הכנסה');
comment_add($s, ['budget_id' => $bid, 'line_id' => $inc['id'], 'body' => 'לבדוק']);
act($s, 'line_save', ['budget_id' => $bid, 'id' => $inc['id'], 'fund_id' => $inc['fund_id'], 'direction' => 'income', 'title' => 'הכנסה', 'amount' => 1500]);
check($vcount() === 1 && str_contains(versions_list($s, $bid)['versions'][0]['label'], 'עריכת שורה — הכנסה'), 'לפני עריכה — גרסה, עם שם השורה');
act($s, 'line_save', ['budget_id' => $bid, 'id' => $inc['id'], 'fund_id' => $inc['fund_id'], 'direction' => 'income', 'title' => 'הכנסה', 'amount' => 1600]);
check($vcount() === 1, 'עריכה נוספת של אותו אדם בתוך 10 דקות — אותה גרסה');
$v1 = versions_list($s, $bid)['versions'][0];
check($v1['income'] === 1800, 'בגרסה — הסכומים של לפני השינוי');
act($s, 'line_delete', ['budget_id' => $bid, 'id' => $line('הוצאה')['id']]);
check($vcount() === 2, 'לפני מחיקה — תמיד גרסה חדשה');
$d = version_get($s, ['budget_id' => $bid, 'id' => $v1['id']])['diff'];
check(count($d['removed']) === 1 && $d['removed'][0]['title'] === 'הוצאה', 'מה השתנה מאז: השורה שנמחקה');
$ch = current(array_filter($d['changed'], fn($c) => $c['title'] === 'הכנסה'));
check($ch && $ch['changes'][0]['then'] === '1,000' && $ch['changes'][0]['now'] === '1,600', '…והסכום ששונה (קודם → עכשיו)');

echo "שחזור\n";
$rr = act($s, 'version_restore', ['budget_id' => $bid, 'id' => $v1['id']]);
check($line('הוצאה') !== null && $line('הכנסה')['amount'] === 1000, 'השורה שנמחקה חזרה, הסכום חזר');
check($line('הכנסה')['id'] === $inc['id'] && count(array_filter($g()['comments'], fn($c) => $c['line_id'] === $inc['id'])) === 1, 'אותו מזהה — ההערה עדיין על השורה');
check($rr['fixed'] === 0 && $rr['orphans'] === [], 'העברות תקינות');
$vs = versions_list($s, $bid)['versions'];
$before = $vs[0]['label'] === 'לפני: שחזור גרסה' && $vs[0]['lines'] === 8 && $vs[0]['income'] === 2400 ? $vs[0] : null;
check((bool)$before, 'השחזור עצמו הפיך: יש גרסה של המצב שלפניו');

echo "השבתה\n";
$ex = $line('מלגות');
act($s, 'line_disable', ['budget_id' => $bid, 'id' => $ex['id'], 'disabled' => 1]);
$gg = $g();
check($line('מלגות') === null && count($gg['disabled']['lines']) === 1 && $gg['disabled']['lines'][0]['title'] === 'מלגות', 'שורה מושבתת — מחוץ לשורות, ברשימת המושבתות');
$h1 = current(array_filter($gg['summary']['balance'], fn($x) => $x['fund_id'] === $fund('הקדש ישן')['id']));
check($h1['expense'] === 200, 'ומחוץ לחישוב');
check(!str_contains(json_encode(doc_model($s, $bid), JSON_UNESCAPED_UNICODE), 'מלגות"'), 'ומחוץ למסמך');
check(fails_with(fn() => line_disable($s, ['budget_id' => $bid, 'id' => $line('החזר')['id'], 'disabled' => 1]), 409), 'שורת העברה — לא מושבתת');
act($s, 'line_disable', ['budget_id' => $bid, 'id' => $ex['id'], 'disabled' => 0]);
check($line('מלגות') !== null, 'החזרה');
$h1id = $fund('הקדש ישן')['id'];
check(fails_with(fn() => bfund_disable($s, ['budget_id' => $bid, 'fund_id' => $h1id, 'disabled' => 1]), 409), 'קופה עם העברות — לא מושבתת');
$h2id = $fund('הקדש פעיל')['id'];
act($s, 'bfund_disable', ['budget_id' => $bid, 'fund_id' => $h2id, 'disabled' => 1]);
$gg = $g();
check($fund('הקדש פעיל') === null && $gg['disabled']['funds'][0]['lines'] === 3 && !array_filter($gg['lines'], fn($l) => $l['fund_id'] === $h2id), 'קופה מושבתת — מחוץ להצעה, עם מספר השורות שלה');
check(budget_list($s, $orgId)['budgets'][0]['income'] === 1700, 'גם בסיכום שברשימת ההצעות');
$next = budget_create($s, ['org_id' => $orgId, 'year' => 2027, 'based_on_id' => $bid])['id'];
check(count(budget_get($s, $next)['disabled']['funds']) === 1, 'על בסיס — ההשבתה עוברת');
act($s, 'bfund_disable', ['budget_id' => $bid, 'fund_id' => $h2id, 'disabled' => 0]);
check($fund('הקדש פעיל') !== null, 'החזרת קופה');

echo "הוצאת קופה מההצעה\n";
$p = bfund_remove($s, ['budget_id' => $bid, 'fund_id' => $h1id, 'preview' => 1]);
check($p['lines'] === 3 && $p['income'] === 500 && $p['expense'] === 500 && count($p['transfers']) === 1 && $p['transfers'][0]['with'][0]['fund'] === 'שוטף',
    'תצוגה מקדימה: שורות, סכומים, וההעברה עם הצד השני');
$vBefore = $vcount();
version_before($s, 'bfund_remove', ['budget_id' => $bid, 'fund_id' => $h1id, 'preview' => 1]);
check($vcount() === $vBefore, 'תצוגה מקדימה — לא גרסה');
check(fails_with(fn() => bfund_remove($s, ['budget_id' => $bid, 'fund_id' => $h1id, 'confirm' => 'לא']), 422), 'בלי שם הקופה — נדחה');
act($s, 'bfund_remove', ['budget_id' => $bid, 'fund_id' => $h1id, 'confirm' => 'הקדש ישן', 'archive' => 1]);
check($fund('הקדש ישן') === null && $line('החזר מהקדש') === null, 'הקופה, השורות שלה והצד השני של ההעברה — נמחקו');
check(!array_filter(org_get($s, $orgId)['funds'], fn($f) => $f['name'] === 'הקדש ישן'), 'והוסרה מהארגון');
$n2 = budget_create($s, ['org_id' => $orgId, 'year' => 2028, 'based_on_id' => $bid])['id'];
check(!array_filter(budget_get($s, $n2)['funds'], fn($f) => $f['name'] === 'הקדש ישן'), 'ולא נכנסת להצעה הבאה');
$t = trash_list($s, $orgId);
check(count($t['funds']) === 1 && $t['funds'][0]['name'] === 'הקדש ישן', 'בסל המחזור');

echo "שחזור אחרי הוצאה\n";
$vr = versions_list($s, $bid)['versions'][0];
check(str_contains($vr['label'], 'הוצאת קופה מההצעה — הקדש ישן'), 'הגרסה של לפני ההוצאה');
fund_restore($s, ['org_id' => $orgId, 'id' => $h1id]);
version_restore($s, ['budget_id' => $bid, 'id' => $vr['id']]);
$gg = $g();
check($fund('הקדש ישן') !== null && $line('שכירות') !== null && $line('החזר מהקדש')['transfer'][0]['fund'] === 'הקדש ישן', 'הקופה, השורות וההעברה חזרו — מחוברת');

echo "מחיקת כמה שורות\n";
$ids = [$line('שכירות ב')['id'], $line('שונות ב')['id']];
act($s, 'lines_delete', ['budget_id' => $bid, 'ids' => $ids]);
check($line('שכירות ב') === null && $line('שונות ב') === null && $line('מלגות ב') !== null, 'שתיהן נמחקו, השאר נשארו');
check(fails_with(fn() => lines_delete($s, ['budget_id' => $bid, 'ids' => [999999]]), 404), 'שורה לא קיימת — לא נמחק כלום');
check(str_contains(versions_list($s, $bid)['versions'][0]['label'], 'מחיקת שורות (2)'), 'גרסה לפני');

echo "סל מחזור: הצעה\n";
budget_delete($s, ['budget_id' => $n2, 'confirm' => '2028']);
$t = trash_list($s, $orgId);
check(count($t['budgets']) === 1 && $t['budgets'][0]['year'] === 2028, 'ההצעה שנמחקה בסל');
budget_restore($s, ['budget_id' => $n2]);
check((bool)array_filter(budget_list($s, $orgId)['budgets'], fn($x) => $x['id'] === $n2), 'שוחזרה');

echo "הרשאות\n";
budget_status($s, ['budget_id' => $bid, 'status' => 'submitted']);
check(fails_with(fn() => version_restore($s, ['budget_id' => $bid, 'id' => $vr['id']]), 409), 'הצעה שהוגשה — לא משחזרים (קודם לטיוטה)');
check(versions_list($s, $bid)['can_restore'] === false && count(versions_list($s, $bid)['versions']) > 0, 'אבל רואים את הגרסאות');

echo $fails ? "\n✕ $fails נכשלו\n" : "\n✓ הכול עבר\n";
exit($fails ? 1 : 0);
