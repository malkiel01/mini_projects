<?php
// בדיקות יחידה: חשבון עשרוני, חישובי עסקה לכל חמשת השווקים, וסטטיסטיקה על סט ידוע.
// php trading-strategies/tests/run.php — כל התוצאות הצפויות חושבו ביד (אפיון פרק 7).
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../lib/calc.php';

$fail = 0; $n = 0;
function eq($got, $want, string $what): void
{
    global $fail, $n;
    $n++;
    if ($got === $want) return;
    $fail++;
    echo "✗ $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}

// ── חשבון עשרוני ─────────────────────────────────────
eq(dec_add('0.1', '0.2'), '0.3', '0.1+0.2');
eq(dec_sub('1.08645', '1.08345'), '0.003', 'forex diff, 5 digits');
eq(dec_sub('0.00013579', '0.00012345'), '0.00001234', 'crypto diff, 8 digits');
eq(dec_mul('60000.12345678', '0.5'), '30000.06172839', 'mul 8 digits');
eq(dec_mul('-0.0001', '100000'), '-10', 'mul negative');
eq(dec_div('2', '3', 4), '0.6667', 'div rounds half up');
eq(dec_div('-2.075', '1', 2), '-2.08', 'div rounds away from zero');
eq(dec_round('2.075', 2), '2.08', 'round half up');
eq(dec_round('9.995', 2), '10', 'round carries');
eq(dec_fixed('-0.004', 2), '0.00', 'fixed: no -0.00');
eq(dec_fixed('7', 2), '7.00', 'fixed pads');
eq(dec_parse('1,25'), '1.25', 'comma decimal');
eq(dec_parse(1.5), null, 'float rejected');
eq(dec_parse('1e5'), null, 'exponent rejected');
eq(dec_parse('0.12345678901'), null, 'more than 10 decimals rejected');
eq(dec_parse('-000.500'), '-0.5', 'normalized');
eq(dec_parse('4', 0), '4', 'integer field (scale 0)');
eq(dec_parse('4.5', 0), null, 'integer field rejects fraction');
eq(dec_parse('1.12', 2), '1.12', 'scale 2 ok');
eq(dec_parse('1.123', 2), null, 'scale 2 rejects 3 digits');
eq(dec_cmp('0.00000001', '0'), 1, 'cmp tiny');
eq(dec_div('12.34000617', '3.450001725', 2), '3.58', 'div long');

// ── עסקאות ───────────────────────────────────────────
function tr(array $t): array
{
    return ts_trade_calc($t + ['entry_fees' => '0', 'planned_stop' => null, 'planned_target' => null,
        'opened_at' => '2026-01-01T10:00:00Z', 'market' => 'stocks', 'symbol' => 'X']);
}
function ex(string $price, string $qty, string $fees = '0', string $at = '2026-01-02T10:00:00Z'): array
{
    return ['price' => $price, 'quantity' => $qty, 'fees' => $fees, 'exited_at' => $at];
}

// מניות, לונג, שתי יציאות חלקיות ועמלות
$c = tr(['direction' => 'long', 'entry_price' => '100', 'quantity' => '10', 'multiplier' => '1', 'entry_fees' => '1',
    'planned_stop' => '95', 'planned_target' => '110',
    'exits' => [ex('110', '5', '0.5', '2026-01-02T10:00:00Z'), ex('105', '5', '0.5', '2026-01-03T12:00:00Z')]]);
eq([$c['gross'], $c['fees'], $c['net'], $c['risk'], $c['r'], $c['rr_planned'], $c['return_pct'], $c['status']],
    ['75', '2', '73', '50', '1.46', '2', '7.3', 'closed'], 'stocks long partial');
eq($c['avg_exit'], '107.5', 'stocks avg exit');
eq($c['closed_at'], '2026-01-03T12:00:00Z', 'closed at = last exit');
eq($c['duration_sec'], 2 * 86400 + 7200, 'duration');

// מניות, שורט, הפסד
$c = tr(['direction' => 'short', 'entry_price' => '50', 'quantity' => '100', 'multiplier' => '1', 'entry_fees' => '2',
    'planned_stop' => '52', 'exits' => [ex('53', '100', '2')]]);
eq([$c['gross'], $c['net'], $c['risk'], $c['r']], ['-300', '-304', '200', '-1.52'], 'stocks short loss');

// קריפטו — שמונה ספרות, כמות בשבר
$c = tr(['direction' => 'long', 'market' => 'crypto', 'entry_price' => '0.00012345', 'quantity' => '1000000.5', 'multiplier' => '1',
    'planned_stop' => '0.00012', 'exits' => [ex('0.00013579', '1000000.5')]]);
eq([$c['gross'], $c['net'], $c['risk'], $c['r']], ['12.34000617', '12.34000617', '3.450001725', '3.58'], 'crypto 8 digits');
$c = tr(['direction' => 'short', 'market' => 'crypto', 'entry_price' => '61000.87654321', 'quantity' => '0.5', 'multiplier' => '1',
    'entry_fees' => '0.00012345', 'exits' => [ex('60000.12345678', '0.5', '0.00012345')]]);
eq([$c['gross'], $c['net'], $c['r'], $c['risk']], ['500.376543215', '500.376296315', null, null], 'crypto short, no stop → no R');

// מט"ח — חמש ספרות, לוטים, פיפים
$c = tr(['direction' => 'long', 'market' => 'forex', 'symbol' => 'EUR/USD', 'entry_price' => '1.08345', 'quantity' => '2',
    'multiplier' => '100000', 'entry_fees' => '7', 'planned_stop' => '1.08145', 'planned_target' => '1.08745',
    'exits' => [ex('1.08645', '1'), ex('1.08445', '1')]]);
eq([$c['gross'], $c['net'], $c['risk'], $c['r'], $c['avg_exit'], $c['pips'], $c['rr_planned']],
    ['400', '393', '400', '0.98', '1.08545', '20', '2'], 'forex long partial');
$c = tr(['direction' => 'short', 'market' => 'forex', 'symbol' => 'USD/JPY', 'entry_price' => '150.255', 'quantity' => '1',
    'multiplier' => '100000', 'planned_stop' => '150.755', 'exits' => [ex('149.755', '1')]]);
eq([$c['gross'], $c['r'], $c['pips']], ['50000', '1', '50'], 'forex short JPY pip');
$c = tr(['direction' => 'long', 'market' => 'forex', 'symbol' => 'GBP/USD', 'entry_price' => '1.26871', 'quantity' => '0.1',
    'multiplier' => '100000', 'exits' => [ex('1.26864', '0.1')]]);
eq([$c['gross'], $c['pips']], ['-0.7', '-0.7'], 'forex micro lot, fractional pip');

// חוזים עתידיים — מכפיל 50, שורט, יציאה חלקית ברווח ובהפסד
$c = tr(['direction' => 'short', 'market' => 'futures', 'entry_price' => '5000.25', 'quantity' => '2', 'multiplier' => '50',
    'entry_fees' => '5', 'planned_stop' => '5010.25', 'exits' => [ex('4990.25', '1', '2.5'), ex('5005.25', '1', '2.5')]]);
eq([$c['gross'], $c['fees'], $c['net'], $c['risk'], $c['r']], ['250', '10', '240', '1000', '0.24'], 'futures short partial');

// אופציות — מכפיל 100
$c = tr(['direction' => 'long', 'market' => 'options', 'entry_price' => '2.35', 'quantity' => '3', 'multiplier' => '100',
    'entry_fees' => '1.95', 'planned_stop' => '1.35', 'exits' => [ex('3.10', '3', '1.95')]]);
eq([$c['gross'], $c['net'], $c['risk'], $c['r']], ['225', '221.1', '300', '0.74'], 'options long call');
$c = tr(['direction' => 'short', 'market' => 'options', 'entry_price' => '4.20', 'quantity' => '1', 'multiplier' => '100',
    'planned_stop' => '6.20', 'exits' => [ex('0.05', '1')]]);
eq([$c['gross'], $c['r']], ['415', '2.08'], 'options short put, R 2.075 rounds up');

// עסקה פתוחה עם יציאה חלקית
$c = tr(['direction' => 'long', 'entry_price' => '100', 'quantity' => '10', 'multiplier' => '1', 'entry_fees' => '1',
    'exits' => [ex('110', '4')]]);
eq([$c['status'], $c['net'], $c['remaining_qty'], $c['closed_at'], $c['duration_sec']], ['open', '39', '6', null, null], 'open partial');
$c = tr(['direction' => 'long', 'entry_price' => '100', 'quantity' => '10', 'multiplier' => '1', 'exits' => []]);
eq([$c['status'], $c['net'], $c['return_pct'], $c['avg_exit']], ['open', '0', null, null], 'open, no exits');

// ── סטטיסטיקה על סט קבוע ─────────────────────────────
// מניות: כניסה 100, 10 יחידות, סטופ 90 → 1R = 100. רווחים: +100, −50, −50, +200, −150, ו-0 בלי סטופ.
$set = [];
$i = 0;
foreach ([['110', '90', '2026-03-02T10:00:00Z', 'yes'],   // +100, R 1      יום שני
          ['95', '90', '2026-03-03T10:00:00Z', 'no'],     // −50,  R −0.5   יום שלישי
          ['95', '90', '2026-03-04T10:00:00Z', 'no'],     // −50,  R −0.5
          ['120', '90', '2026-04-01T10:00:00Z', 'yes'],   // +200, R 2      אפריל
          ['85', '90', '2026-04-02T10:00:00Z', 'partial'],// −150, R −1.5
          ['100', null, '2026-04-03T10:00:00Z', null]]    // 0, בלי סטופ
         as [$exit, $stop, $at, $plan]) {
    $t = ['id' => ++$i, 'currency' => 'USD', 'followed_plan' => $plan, 'direction' => 'long', 'entry_price' => '100', 'quantity' => '10',
        'multiplier' => '1', 'entry_fees' => '0', 'planned_stop' => $stop, 'planned_target' => null, 'opened_at' => '2026-03-01T10:00:00Z',
        'market' => 'stocks', 'symbol' => 'X', 'exits' => [ex($exit, '10', '0', $at)]];
    $t['calc'] = ts_trade_calc($t);
    $set[] = $t;
}
$ils = ['id' => 99, 'currency' => 'ILS', 'followed_plan' => 'yes', 'direction' => 'long', 'entry_price' => '10', 'quantity' => '1',
    'multiplier' => '1', 'entry_fees' => '0', 'planned_stop' => '9', 'planned_target' => null, 'opened_at' => '2026-03-01T10:00:00Z',
    'market' => 'stocks', 'symbol' => 'TEVA', 'exits' => [ex('12', '1', '0', '2026-03-05T10:00:00Z')]];
$ils['calc'] = ts_trade_calc($ils);

$stats = ts_stats(array_reverse([...$set, $ils]), 'Asia/Jerusalem');   // סדר הקלט לא משנה
eq(array_column($stats, 'currency'), ['ILS', 'USD'], 'grouped by currency');
$s = $stats[1];
eq([$s['count'], $s['wins'], $s['losses'], $s['breakeven']], [6, 2, 3, 1], 'counts');
eq($s['win_rate'], '33.3', 'win rate');
eq([$s['avg_win'], $s['avg_loss']], ['150', '-83.33'], 'avg win / loss');
eq([$s['net'], $s['gross_win'], $s['gross_loss']], ['50', '300', '250'], 'totals');
eq($s['profit_factor'], '1.2', 'profit factor');
eq([$s['expectancy_r'], $s['r_count']], ['0.1', 5], 'expectancy R excludes no-stop trade');
eq($s['max_loss_streak'], 2, 'max losing streak');
eq($s['max_drawdown'], '150', 'max drawdown (200 → 50)');
eq(array_column($s['equity'], 'v'), ['100', '50', '0', '200', '50', '50'], 'equity curve');
eq(array_column($s['breakdown']['month'], 'key'), ['2026-03', '2026-04'], 'months');
eq(array_column($s['breakdown']['month'], 'net'), ['0', '50'], 'net by month');
eq($s['breakdown']['month'][0]['win_rate'], '33.3', 'month win rate');
$plan = array_column($s['breakdown']['plan'], null, 'key');
eq([$plan['yes']['count'], $plan['yes']['net'], $plan['yes']['expectancy_r']], [2, '300', '1.5'], 'followed plan');
eq([$plan['no']['count'], $plan['no']['net'], $plan['no']['win_rate']], [2, '-100', '0'], 'did not follow plan');
eq(isset($plan['none'], $plan['partial']), true, 'plan buckets');
$wd = array_column($s['breakdown']['weekday'], 'count', 'key');
eq([$wd['1'] ?? 0, $wd['2'] ?? 0, $wd['3'] ?? 0, $wd['4'] ?? 0, $wd['5'] ?? 0], [1, 1, 2, 1, 1], 'by weekday (Mon=1)');
eq([$stats[0]['count'], $stats[0]['net'], $stats[0]['expectancy_r'], $stats[0]['profit_factor']], [1, '2', '2', null], 'ILS separate, PF null without losses');

$sum = ts_summary(array_filter($set, fn($t) => $t['calc']['status'] === 'closed'));
eq($sum, ['count' => 6, 'win_rate' => '33.3', 'expectancy_r' => '0.1'], 'strategy card summary');
eq(ts_summary([]), ['count' => 0, 'win_rate' => null, 'expectancy_r' => null], 'empty summary');

// אזור זמן: יציאה ב-31.3 בשעה 22:30 UTC = 1.4 בירושלים
$late = $set[0];
$late['exits'] = [ex('110', '10', '0', '2026-03-31T22:30:00Z')];
$late['calc'] = ts_trade_calc($late);
eq(ts_stats([$late], 'Asia/Jerusalem')[0]['breakdown']['month'][0]['key'], '2026-04', 'month by user timezone');
eq(ts_stats([$late], 'UTC')[0]['breakdown']['month'][0]['key'], '2026-03', 'month in UTC');

// תוחלת R מהערך המלא, לא מ-R מעוגל (ממצא בקרה #1259): R 0.006 ו-0 → ממוצע 0.003 → "0" (ולא 0.01 ו-0 → 0.01)
$tiny = [];
foreach (['100.06', '100'] as $k => $exit) {
    $t = ['id' => 200 + $k, 'currency' => 'USD', 'followed_plan' => null, 'direction' => 'long', 'entry_price' => '100', 'quantity' => '100',
        'multiplier' => '1', 'entry_fees' => '0', 'planned_stop' => '90', 'planned_target' => null, 'opened_at' => '2026-03-01T10:00:00Z',
        'market' => 'stocks', 'symbol' => 'X', 'exits' => [ex($exit, '100', '0', '2026-03-0' . ($k + 2) . 'T10:00:00Z')]];
    $t['calc'] = ts_trade_calc($t);
    $tiny[] = $t;
}
eq([$tiny[0]['calc']['r'], $tiny[0]['calc']['r_full']], ['0.01', '0.006'], 'r rounded for display, r_full exact');
eq(ts_stats($tiny, 'UTC')[0]['expectancy_r'], '0', 'expectancy from full R');
eq(ts_summary($tiny)['expectancy_r'], '0', 'card expectancy from full R');

echo $fail ? "$fail מתוך $n נכשלו\n" : "כל $n הבדיקות עברו\n";
exit($fail ? 1 : 0);
