<?php
/**
 * דוחות (שלב 9): הכנסות לפי חודש, הכנסה חודשית קבועה ממנויים, מנויים שנוספו ובוטלו, חיובים שנכשלו, החזרים,
 * ופילוח לפי מערכת ולפי מוצר. הכול לפי מנוי (tenant) ולפי סביבה; סכומים באגורות, כולל מע"מ.
 */

declare(strict_types=1);

require_once __DIR__ . '/checkout.php';

function reports(array $t, string $env, int $months = 12): array
{
    if (!in_array($env, ['test', 'live'], true)) throw new ApiError('סביבה: בדיקות או חי', 'bad_env');
    $months = max(1, min(24, $months));
    $tid = $t['id'];
    [$curStart, $curEnd] = month_range('');
    $first = strtotime('-' . ($months - 1) . ' months', $curStart);
    $scope = 'JOIN account a ON a.id = p.account JOIN merchant m ON m.id = a.merchant WHERE m.tenant = ? AND p.env = ?';

    // הכנסה לפי חודש: מה שנגבה בפועל, פחות החזרים שבוצעו באותו חודש
    $series = [];
    for ($i = 0; $i < $months; $i++) {
        $s = strtotime("+$i months", $first);
        $series[gmdate('Y-m', $s)] = ['period' => gmdate('Y-m', $s), 'gross' => 0, 'refunds' => 0, 'count' => 0, 'failed' => 0];
    }
    foreach (db_all("SELECT strftime('%Y-%m', p.created_at, 'unixepoch') AS ym, p.status, COUNT(*) AS n, SUM(p.amount) AS s FROM payment p $scope
            AND p.created_at >= ? AND p.amount > 0 GROUP BY ym, p.status = 'failed'", [$tid, $env, $first]) as $r) {
        if (!isset($series[$r['ym']])) continue;
        if ($r['status'] === 'failed') $series[$r['ym']]['failed'] += (int)$r['n'];
        else { $series[$r['ym']]['gross'] += (int)$r['s']; $series[$r['ym']]['count'] += (int)$r['n']; }
    }
    foreach (db_all("SELECT strftime('%Y-%m', r.created_at, 'unixepoch') AS ym, SUM(r.amount) AS s FROM refund r JOIN payment p ON p.id = r.payment $scope
            AND r.created_at >= ? GROUP BY ym", [$tid, $env, $first]) as $r) {
        if (isset($series[$r['ym']])) $series[$r['ym']]['refunds'] += (int)$r['s'];
    }
    foreach ($series as &$row) $row['net'] = $row['gross'] - $row['refunds'];
    unset($row);

    // מנויים: עכשיו, ומה קרה החודש
    $sScope = "JOIN client c ON c.id = s.client JOIN customer cu ON cu.id = s.customer WHERE c.tenant = ? AND cu.env = ?";
    $count = fn(string $where, array $args = []) => (int)(db_one("SELECT COUNT(*) AS n FROM subscription s $sScope AND $where", [$tid, $env, ...$args])['n'] ?? 0);
    $active = $count("s.status IN ('active','past_due')");
    $canceledMonth = $count("s.status = 'canceled' AND s.canceled_at >= ? AND s.canceled_at < ?", [$curStart, $curEnd]);
    $subs = [
        'active' => $active, 'trialing' => $count("s.status = 'trialing'"), 'past_due' => $count("s.status = 'past_due'"),
        'new_month' => $count('s.created_at >= ? AND s.created_at < ?', [$curStart, $curEnd]),
        'canceled_month' => $canceledMonth,
        // נטישה: מי שבוטל החודש, מתוך מי שהיה פעיל בתחילתו (הפעילים עכשיו שנוצרו לפני החודש + מי שבוטל)
        'churn_pct' => (function () use ($count, $curStart, $curEnd, $canceledMonth) {
            $base = $count("s.status IN ('active','past_due') AND s.created_at < ?", [$curStart]) + $canceledMonth;
            return $base ? round($canceledMonth * 100 / $base, 1) : 0.0;
        })(),
    ];

    // הכנסה חודשית קבועה: כל פריטי המנויים הפעילים, מנורמלים לחודש, כולל מע"מ
    $mrr = 0;
    $rows = db_all("SELECT si.quantity, pr.amount, pr.interval, pr.interval_count, pr.tax_behavior, m.vat_rate FROM subscription s
        JOIN client c ON c.id = s.client JOIN customer cu ON cu.id = s.customer
        JOIN subscription_item si ON si.subscription = s.id JOIN price pr ON pr.id = si.price JOIN account a ON a.id = s.account JOIN merchant m ON m.id = a.merchant
        WHERE c.tenant = ? AND cu.env = ? AND s.status IN ('active','past_due') AND pr.amount IS NOT NULL", [$tid, $env]);
    foreach ($rows as $r) {
        $per = gross_amount((int)$r['amount'] * (int)$r['quantity'], (string)$r['tax_behavior'], (int)$r['vat_rate']);
        $n = max(1, (int)$r['interval_count']);
        $mrr += (int)round(match ($r['interval']) { 'year' => $per / (12 * $n), 'week' => $per * 52 / (12 * $n), default => $per / $n });
    }

    // פילוח — 12 החודשים, לפי שורות החשבוניות ששולמו
    $breakdown = fn(string $key, string $label) => db_all("SELECT $key AS k, $label AS name, SUM(l.amount) AS amount, COUNT(DISTINCT i.id) AS n
        FROM invoice_line l JOIN invoice i ON i.id = l.invoice JOIN merchant m ON m.id = i.merchant
        LEFT JOIN client c ON c.id = l.client LEFT JOIN product pd ON pd.id = l.product
        WHERE m.tenant = ? AND i.env = ? AND i.status = 'paid' AND i.paid_at >= ? GROUP BY k ORDER BY amount DESC LIMIT 12", [$tid, $env, $first]);

    $cur = $series[gmdate('Y-m', $curStart)];
    return [
        'env' => $env, 'months' => array_values($series),
        'month' => ['net' => $cur['net'], 'gross' => $cur['gross'], 'refunds' => $cur['refunds'], 'count' => $cur['count'], 'failed' => $cur['failed']],
        'mrr' => $mrr, 'subs' => $subs,
        'wallets' => micros_to_agorot((int)(db_one("SELECT SUM(b.micros) AS n FROM wallet_balance b JOIN account a ON a.id = b.account JOIN merchant m ON m.id = a.merchant
            WHERE m.tenant = ? AND a.env = ?", [$tid, $env])['n'] ?? 0)),
        'by_client' => array_map(fn($r) => ['name' => $r['name'] ?? $r['k'], 'amount' => (int)$r['amount'], 'count' => (int)$r['n']], $breakdown('l.client', 'c.name')),
        'by_product' => array_map(fn($r) => ['name' => $r['name'] ?? 'טעינה / אחר', 'amount' => (int)$r['amount'], 'count' => (int)$r['n']], $breakdown("COALESCE(l.product, '')", 'pd.name')),
    ];
}
