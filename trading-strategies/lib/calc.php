<?php
/**
 * החישובים — מקור האמת (אפיון 2.5, 2.6, 3.1). פונקציות טהורות: מקבלות עסקאות, מחזירות מספרים.
 * הכול מחושב בקריאה ולא נשמר במסד — כך אין ערך מחושב שמתיישן כשעסקה נערכת.
 *
 *   רווח ברוטו = Σ (מחיר יציאה − מחיר כניסה) × כמות היציאה × מכפיל × כיוון        (כיוון: לונג 1, שורט −1)
 *   עמלות      = עמלת כניסה + Σ עמלות היציאות
 *   נטו        = ברוטו − עמלות          — הרווח שמומש. בעסקה פתוחה עם יציאה חלקית: מה שמומש עד עכשיו.
 *   סיכון (1R) = |כניסה − סטופ מתוכנן| × כמות × מכפיל
 *   R          = נטו ÷ סיכון            — רק לעסקה עם סטופ (אחרת null, ולא נכנסת לסטטיסטיקות ה-R)
 *   יחס סיכוי/סיכון מתוכנן = |יעד − כניסה| ÷ |כניסה − סטופ|
 *   תשואה %    = נטו ÷ (כניסה × כמות × מכפיל) × 100
 */

declare(strict_types=1);

require_once __DIR__ . '/decimal.php';

const TS_MARKETS = [
    'stocks'  => ['name' => 'מניות / תעודות סל', 'unit' => 'מניות',  'multiplier' => '1'],
    'crypto'  => ['name' => 'קריפטו',            'unit' => 'יחידות', 'multiplier' => '1'],
    'forex'   => ['name' => 'מט"ח',              'unit' => 'לוטים',  'multiplier' => '100000'],
    'futures' => ['name' => 'חוזים עתידיים',     'unit' => 'חוזים',  'multiplier' => null],   // המשתמש מזין (ES = 50)
    'options' => ['name' => 'אופציות',           'unit' => 'חוזים',  'multiplier' => '100'],
];

/** גודל פיפ במט"ח: זוג עם ין — 0.01, השאר — 0.0001 */
function ts_pip_size(string $symbol): string
{
    return stripos($symbol, 'JPY') !== false ? '0.01' : '0.0001';
}

/**
 * כל הערכים המחושבים של עסקה אחת.
 * $t: direction, entry_price, quantity, entry_fees, multiplier, planned_stop, planned_target, opened_at,
 *     market, symbol, exits: [{exited_at, price, quantity, fees}]
 */
function ts_trade_calc(array $t): array
{
    $dir = $t['direction'] === 'short' ? '-1' : '1';
    $entry = $t['entry_price'];
    $qty = $t['quantity'];
    $mult = $t['multiplier'];
    $exits = $t['exits'] ?? [];

    $gross = '0'; $exitQty = '0'; $exitFees = '0'; $exitValue = '0'; $closedAt = null;
    foreach ($exits as $x) {
        $gross = dec_add($gross, dec_mul(dec_mul(dec_mul(dec_sub($x['price'], $entry), $x['quantity']), $mult), $dir));
        $exitQty = dec_add($exitQty, $x['quantity']);
        $exitFees = dec_add($exitFees, $x['fees'] ?? '0');
        $exitValue = dec_add($exitValue, dec_mul($x['price'], $x['quantity']));
        if ($closedAt === null || strcmp($x['exited_at'], $closedAt) > 0) $closedAt = $x['exited_at'];
    }
    $fees = dec_add($t['entry_fees'] ?? '0', $exitFees);
    $net = dec_sub($gross, $fees);
    $closed = dec_cmp($exitQty, $qty) === 0;

    $risk = null; $r = null; $rr = null;
    $stop = $t['planned_stop'] ?? null;
    if ($stop !== null && $stop !== '') {
        $dist = dec_abs(dec_sub($entry, $stop));
        if (dec_sign($dist) > 0) {
            $risk = dec_mul(dec_mul($dist, $qty), $mult);
            if (dec_sign($risk) > 0) $r = dec_div($net, $risk, 2);
            $target = $t['planned_target'] ?? null;
            if ($target !== null && $target !== '') $rr = dec_div(dec_abs(dec_sub($target, $entry)), $dist, 2);
        }
    }

    $cost = dec_mul(dec_mul($entry, $qty), $mult);
    $avgExit = dec_sign($exitQty) > 0 ? dec_div($exitValue, $exitQty, 10) : null;
    $pips = null;
    if (($t['market'] ?? '') === 'forex' && $avgExit !== null) {
        $pips = dec_div(dec_mul(dec_sub($avgExit, $entry), $dir), ts_pip_size((string)($t['symbol'] ?? '')), 1);
    }

    return [
        'status' => $closed ? 'closed' : 'open',
        'exited_qty' => $exitQty,
        'remaining_qty' => dec_sub($qty, $exitQty),
        'avg_exit' => $avgExit,
        'gross' => $gross,
        'fees' => $fees,
        'net' => $net,
        'risk' => $risk,
        'r' => $r,
        'rr_planned' => $rr,
        'return_pct' => dec_sign($cost) > 0 && dec_sign($exitQty) > 0 ? dec_div(dec_mul($net, '100'), $cost, 2) : null,
        'pips' => $pips,
        'closed_at' => $closed ? $closedAt : null,
        'duration_sec' => $closed && $closedAt ? max(0, strtotime($closedAt) - strtotime($t['opened_at'])) : null,
    ];
}

/** win / loss / be לפי הנטו */
function ts_outcome(string $net): string
{
    return match (dec_sign($net)) { 1 => 'win', -1 => 'loss', default => 'be' };
}

function ts_pct(int $part, int $whole): ?string
{
    return $whole > 0 ? dec_div((string)($part * 100), (string)$whole, 1) : null;
}

/** ממוצע של רשימת מחרוזות עשרוניות, או null */
function ts_avg(array $xs, int $scale = 2): ?string
{
    return $xs ? dec_div(dec_sum($xs), (string)count($xs), $scale) : null;
}

/**
 * סיכום קצר בלי תלות במטבע — לכרטיס אסטרטגיה (אפיון 2.1): מספר עסקאות, אחוז הצלחה, תוחלת ב-R.
 * $trades — עסקאות סגורות עם calc.
 */
function ts_summary(array $trades): array
{
    $wins = 0; $rs = [];
    foreach ($trades as $t) {
        if (ts_outcome($t['calc']['net']) === 'win') $wins++;
        if ($t['calc']['r'] !== null) $rs[] = $t['calc']['r'];
    }
    return ['count' => count($trades), 'win_rate' => ts_pct($wins, count($trades)), 'expectancy_r' => ts_avg($rs)];
}

/**
 * הסטטיסטיקה המלאה (אפיון 2.6), לכל מטבע בנפרד — אין המרת מטבעות בשלב 1.
 * $trades — עסקאות סגורות, כל אחת עם currency, followed_plan, calc (ts_trade_calc).
 * $tz — אזור הזמן של המשתמש, לפילוח לפי חודש ויום בשבוע.
 */
function ts_stats(array $trades, string $tz): array
{
    usort($trades, fn($a, $b) => strcmp($a['calc']['closed_at'], $b['calc']['closed_at']) ?: ($a['id'] <=> $b['id']));
    $by = [];
    foreach ($trades as $t) $by[$t['currency']][] = $t;
    ksort($by);
    $out = [];
    foreach ($by as $cur => $list) $out[] = ['currency' => $cur] + ts_stats_one($list, $tz);
    return $out;
}

function ts_stats_one(array $trades, string $tz): array
{
    $zone = new DateTimeZone($tz);
    $wins = []; $losses = []; $rs = []; $be = 0;
    $cum = '0'; $peak = '0'; $dd = '0'; $equity = [];
    $streak = 0; $maxStreak = 0;
    $groups = ['month' => [], 'weekday' => [], 'plan' => []];

    foreach ($trades as $t) {
        $c = $t['calc'];
        $o = ts_outcome($c['net']);
        if ($o === 'win') $wins[] = $c['net']; elseif ($o === 'loss') $losses[] = $c['net']; else $be++;
        if ($c['r'] !== null) $rs[] = $c['r'];

        $streak = $o === 'loss' ? $streak + 1 : 0;
        $maxStreak = max($maxStreak, $streak);

        $cum = dec_add($cum, $c['net']);
        if (dec_cmp($cum, $peak) > 0) $peak = $cum;
        $draw = dec_sub($peak, $cum);
        if (dec_cmp($draw, $dd) > 0) $dd = $draw;
        $equity[] = ['t' => $c['closed_at'], 'v' => $cum, 'id' => (int)$t['id']];

        $local = (new DateTimeImmutable($c['closed_at']))->setTimezone($zone);
        $keys = [
            'month' => $local->format('Y-m'),
            'weekday' => $local->format('w'),                    // 0 = ראשון
            'plan' => $t['followed_plan'] ?: 'none',
        ];
        foreach ($keys as $g => $k) $groups[$g][$k][] = $t;
    }

    $grossWin = dec_sum($wins);
    $grossLoss = dec_abs(dec_sum($losses));
    $n = count($trades);
    $breakdown = [];
    foreach ($groups as $g => $rows) {
        if ($g !== 'plan') ksort($rows);
        $breakdown[$g] = [];
        foreach ($rows as $k => $list) {
            $nets = array_map(fn($t) => $t['calc']['net'], $list);
            $breakdown[$g][] = ['key' => (string)$k, 'net' => dec_sum($nets)] + ts_summary($list);
        }
    }

    return [
        'count' => $n,
        'wins' => count($wins),
        'losses' => count($losses),
        'breakeven' => $be,
        'win_rate' => ts_pct(count($wins), $n),
        'avg_win' => ts_avg($wins),
        'avg_loss' => ts_avg($losses),
        'net' => $cum,
        'gross_win' => $grossWin,
        'gross_loss' => $grossLoss,
        'profit_factor' => dec_sign($grossLoss) > 0 ? dec_div($grossWin, $grossLoss, 2) : null,
        'expectancy_r' => ts_avg($rs),
        'r_count' => count($rs),
        'max_loss_streak' => $maxStreak,
        'max_drawdown' => $dd,
        'equity' => $equity,
        'breakdown' => $breakdown,
    ];
}
