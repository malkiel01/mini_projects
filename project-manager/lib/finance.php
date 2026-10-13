<?php
/**
 * אגף הכספים — שלב כ1 (README, "אגף הכספים").
 *
 * ארבע שכבות לכל שלב, ספק ופרויקט:
 *   תקציב      — של הפרויקט (₪) ושל השלב (stages.budget)
 *   התחייבות   — ההצעה שאושרה (ובהמשך: + תוספות), ושכר הטרחה של המנהל
 *   מתוכנן     — אבני הדרך; "הגיע מועד" = תאריך שעבר, שלב שבוצע, או התקדמות שהגיעה לאחוז
 *   בפועל      — מה שהמנהל אישר שהתקבל
 * ומהן: יתרה, "צריך היה עד היום", פער (פיגור / שולם מראש), שולם מול בוצע, כיסוי, וצפי סופי.
 *
 * מע"מ — כל הסכומים כוללים מע"מ, ולכל סכום מוחזר גם "לפני מע"מ" (n): לפי השיעור שנשמר בהצעה /
 * באבן הדרך ביום שנקבעו, 0 לספק עוסק פטור, ואחרת — השיעור בהגדרות (vat_rate, ברירת מחדל 18).
 * הכול מחושב בכל קריאה; לא נשמר.
 */

declare(strict_types=1);

require_once __DIR__ . '/payments.php';
require_once __DIR__ . '/changes.php';
require_once __DIR__ . '/expenses.php';
require_once __DIR__ . '/contracts.php';

const PM_FEE_TYPES = ['', 'percent', 'fixed'];
const PM_DUE_KINDS = ['date', 'stage_done', 'progress'];
/** שולם יותר מהביצוע ביותר מזה (נקודות אחוז) — מסומן. */
const PM_OVERPAY_MARGIN = 10;

function pm_vat_rate(): float
{
    $r = (float)pm_setting('vat_rate', '18');
    return $r >= 0 && $r <= 50 ? $r : 18.0;
}

/** שיעור המע"מ של הצעה: 0 לספק עוסק פטור, השיעור שנשמר בה, או השיעור היום. */
function pm_quote_vat(array $q): float
{
    return pm_payment_vat($q);
}

/** לפני מע"מ, באגורות. */
function pm_net(int $gross, float $rate): int
{
    return $rate > 0 ? (int)round($gross / (1 + $rate / 100)) : $gross;
}

/** סכום לתצוגה: g — כולל מע"מ, n — לפני מע"מ (₪). */
function pm_m(int $gross, int $net): array
{
    return ['g' => $gross / 100, 'n' => $net / 100];
}

/** שורה מצטברת: [ברוטו, נטו] באגורות. */
function pm_acc(array &$acc, string $key, int $gross, float $rate): void
{
    $acc[$key][0] = ($acc[$key][0] ?? 0) + $gross;
    $acc[$key][1] = ($acc[$key][1] ?? 0) + pm_net($gross, $rate);
}

function pm_acc_out(array $acc, array $keys): array
{
    $out = [];
    foreach ($keys as $k) $out[$k] = pm_m($acc[$k][0] ?? 0, $acc[$k][1] ?? 0);
    return $out;
}

/** אבן דרך שהגיע מועדה — תאריך שעבר, שלב שבוצע, או התקדמות שהגיעה לאחוז. */
function pm_payment_due(array $p, ?array $stage, string $today, string $projectStatus = ''): bool
{
    $done = $stage && in_array($stage['status'], ['done', 'paid'], true);
    return match ($p['due_kind'] ?? 'date') {
        'advance'    => true, // מקדמה — כבר בתחילת העבודה
        'handover'   => pm_handover_done((int)($p['project_id'] ?? 0), isset($p['unit_id']) ? (int)$p['unit_id'] ?: null : null, $projectStatus),
        'stage_done' => $done,
        'progress'   => $done || ($stage && (int)$stage['progress'] >= (int)($p['due_pct'] ?? 101)),
        default      => $p['due_date'] !== '' && $p['due_date'] <= $today,
    };
}

/**
 * נמסר? פרויקט שהסתיים — הכול נמסר. יחידה — כשנמסרה. כל המתחם (בלי יחידה) — כשכל היחידות נמסרו.
 */
function pm_handover_done(int $projectId, ?int $unitId, string $projectStatus): bool
{
    if ($projectStatus === 'done') return true;
    static $cache = [];
    if (!isset($cache[$projectId])) {
        $st = pm_db()->prepare('SELECT id, handed_over_at FROM units WHERE project_id = ?');
        $st->execute([$projectId]);
        $cache[$projectId] = array_column($st->fetchAll(), 'handed_over_at', 'id');
    }
    $u = $cache[$projectId];
    if ($unitId) return !empty($u[$unitId]);
    return $u && !in_array(null, $u, true);
}


/** אחוז הביצוע של שלב — "בוצע" = 100. */
function pm_stage_progress(array $s): int
{
    return in_array($s['status'], ['done', 'paid'], true) ? 100 : max(0, min(100, (int)$s['progress']));
}

/** הגדרות הכספים של הפרויקט (מתוך שורת projects). */
function pm_finance_settings(array $p): array
{
    return [
        'budget' => (int)$p['budget'], 'budget_initial' => (int)$p['budget_initial'],
        'fee_type' => $p['fee_type'], 'fee_value' => (float)$p['fee_value'],
        'retention_pct' => (float)$p['retention_pct'],
        'index_on' => (bool)$p['index_on'], 'index_name' => $p['index_name'],
        'index_base_month' => $p['index_base_month'], 'index_base' => $p['index_base'] !== null ? (float)$p['index_base'] : null,
        'vat_rate' => pm_vat_rate(),
    ];
}

function pm_finance(array $user, int $projectId): array
{
    $p = pm_project_row($user, $projectId);
    $cfg = pm_fin_alert_settings((int)$p['owner_id']);
    return pm_finance_of($projectId, false, $cfg['overpay']) + ['alerts' => pm_fin_alerts($projectId, $cfg)];
}

/**
 * כל החישוב. $portal — לפורטל של הלקוח / העו"ד: בלי סימונים פנימיים, אותם מספרים.
 * מבנה: settings · totals · stages[{…, rows[ספק]}] · fee.
 */
function pm_finance_of(int $projectId, bool $portal = false, int $overpay = PM_OVERPAY_MARGIN, ?array $scope = null): array
{
    // $scope (מתחם, פורטל של לקוח של יחידה): רק השלבים שלו, התקציב שלו, והיחידות שלו (pm_portal_scope)
    $in = fn($sid) => $scope === null || in_array((int)$sid, $scope['stages'], true);
    $db = pm_db();
    $pr = $db->prepare('SELECT p.*, u.display_name AS owner_name FROM projects p JOIN users u ON u.id = p.owner_id WHERE p.id = ?');
    $pr->execute([$projectId]);
    $p = $pr->fetch() ?: throw new PmError('הפרויקט לא נמצא', 404);
    $today = pm_today();
    $vat = pm_vat_rate();

    $st = $db->prepare('SELECT s.*, u.name AS unit_name FROM stages s LEFT JOIN units u ON u.id = s.unit_id WHERE s.project_id = ? ORDER BY s.sort, s.id');
    $st->execute([$projectId]);
    $stages = [];
    foreach ($st->fetchAll() as $s) $stages[(int)$s['id']] = $s;

    // ספקים: פטור ממע"מ
    $ex = $db->prepare('SELECT c.id, c.name, c.vat_exempt FROM contacts c WHERE c.id IN (
        SELECT supplier_id FROM quotes WHERE project_id = ? UNION SELECT supplier_id FROM payments WHERE project_id = ?)');
    $ex->execute([$projectId, $projectId]);
    $sup = [];
    foreach ($ex->fetchAll() as $c) $sup[(int)$c['id']] = $c;
    $rateOf = fn(int $supplierId, $stored) => !empty($sup[$supplierId]['vat_exempt']) ? 0.0 : ($stored !== null ? (float)$stored : $vat);

    // שורות: [שלב][ספק]
    $rows = [];
    $row = function (int $stageId, int $supplierId) use (&$rows, $sup) {
        $rows[$stageId][$supplierId] ??= ['supplier_id' => $supplierId, 'name' => $sup[$supplierId]['name'] ?? '', 'quote_id' => null,
            'acc' => [], 'rate' => null, 'milestones' => 0];
        return $rows[$stageId][$supplierId];
    };

    // התחייבות — ההצעה העדכנית שאושרה לכל שלב וספק
    $q = $db->prepare("SELECT * FROM quotes WHERE project_id = ? AND status = 'approved' ORDER BY id");
    $q->execute([$projectId]);
    foreach ($q->fetchAll() as $x) {
        $sid = (int)$x['stage_id'];
        if (!$in($sid)) continue;
        $cid = (int)$x['supplier_id'];
        $row($sid, $cid);
        $r = $rateOf($cid, $x['vat_rate']);
        $rows[$sid][$cid]['acc']['committed'] = [(int)$x['amount'], pm_net((int)$x['amount'], $r)]; // האחרונה גוברת
        $rows[$sid][$cid]['quote_id'] = (int)$x['id'];
        $rows[$sid][$cid]['rate'] = $r;
    }
    // תוספות וחריגות (כ4): שאושרו — נכנסות להתחייבות; כולן — מוצגות בשורה
    $co = $db->prepare("SELECT * FROM change_orders WHERE project_id = ? AND status != 'cancelled' ORDER BY id");
    $co->execute([$projectId]);
    foreach ($co->fetchAll() as $x) {
        $sid = (int)$x['stage_id'];
        if (!$in($sid)) continue;
        $cid = (int)$x['supplier_id'];
        $row($sid, $cid);
        $r = $rateOf($cid, $x['vat_rate']);
        $rows[$sid][$cid]['rate'] ??= $r;
        if ($x['status'] === 'approved') pm_acc($rows[$sid][$cid]['acc'], 'committed', (int)$x['amount'], $r);
        $rows[$sid][$cid]['changes'][] = ['id' => (int)$x['id'], 'amount' => (int)$x['amount'] / 100, 'reason' => $x['reason'], 'status' => $x['status'],
            'days' => pm_days_since($x['updated_at'])];
    }

    // מתוכנן / הגיע מועד / שולם — מאבני הדרך
    $pay = $db->prepare("SELECT * FROM payments WHERE project_id = ? AND status != 'cancelled'");
    $pay->execute([$projectId]);
    $payments = $pay->fetchAll();
    $proofs = pm_payment_proofs(array_map('intval', array_column($payments, 'id')));
    $next = null;
    $retHeld = [0, 0];
    foreach ($payments as $x) {
        $sid = (int)$x['stage_id'];
        if ($scope !== null && !pm_portal_in_scope($scope, $sid ?: null, $x['unit_id'] ? (int)$x['unit_id'] : null)) continue;
        $cid = (int)$x['supplier_id'];
        $row($sid, $cid);
        $r = $rateOf($cid, $x['vat_rate']);
        $rows[$sid][$cid]['rate'] ??= $r;
        // מה משלמים באבן הדרך: בלי העיכבון (הוא בשחרור), ועם הפרשי ההצמדה
        $amount = pm_payment_due_amount($x);
        $received = 0;
        foreach ($proofs[(int)$x['id']] ?? [] as $pf) if ($pf['status'] === 'received') $received += (int)round($pf['received_amount'] * 100);
        $a = &$rows[$sid][$cid]['acc'];
        pm_acc($a, 'scheduled', $amount, $r);
        pm_acc($a, 'paid', $received, $r);
        if ((int)$x['index_diff']) pm_acc($a, 'index', (int)$x['index_diff'], $r);
        if (pm_payment_due($x, $stages[$sid] ?? null, $today, $p['status'])) pm_acc($a, 'due', $amount, $r);
        unset($a);
        if ($x['kind'] === 'retention') { $retHeld[0] += max(0, $amount - $received); $retHeld[1] += pm_net(max(0, $amount - $received), $r); }
        $rows[$sid][$cid]['milestones']++;
        // התשלום הבא — לפורטל: הקרוב שעוד לא שולם במלואו
        if ($received < $amount && !in_array($x['status'], ['received'], true)) {
            // שחרור העיכבון — אחרון, עד שהפרויקט נמסר
            $key = ($x['due_kind'] === 'advance' ? '0000' : '') . ($x['due_kind'] === 'date' && $x['due_date'] !== '' ? $x['due_date'] : ($x['due_kind'] === 'handover' && !pm_handover_done($projectId, $x['unit_id'] ? (int)$x['unit_id'] : null, $p['status']) ? 'Z' : '9999')) . sprintf('%08d', $x['id']);
            if (!$next || $key < $next['key']) {
                $next = ['key' => $key, 'title' => $x['title'], 'supplier' => $sup[$cid]['name'] ?? '', 'amount' => pm_m($amount - $received, pm_net($amount - $received, $r)),
                    'due_kind' => $x['due_kind'], 'due_date' => $x['due_date'], 'due_pct' => $x['due_pct'] !== null ? (int)$x['due_pct'] : null,
                    'stage' => isset($stages[$sid]) ? $stages[$sid]['name'] : '', 'payable' => pm_payment_payable($x)];
            }
        }
    }

    $contracts = pm_contracts_of($projectId);
    // שלבים
    $T = [];
    $outStages = [];
    $forecast = [0, 0];
    $suppliersCommitted = [0, 0];
    foreach ($stages + [0 => null] as $sid => $s) {
        if (!$s && empty($rows[0])) continue;
        if ($s && !$in($sid)) continue;
        $S = [];
        $outRows = [];
        foreach ($rows[$sid] ?? [] as $cid => $r) {
            $acc = $r['acc'];
            // הפרשי הצמדה — מגדילים את ההתחייבות
            if (!empty($acc['index'])) foreach ([0, 1] as $i) $acc['committed'][$i] = ($acc['committed'][$i] ?? 0) + $acc['index'][$i];
            $committed = $acc['committed'][0] ?? 0;
            $scheduled = $acc['scheduled'][0] ?? 0;
            $paid = $acc['paid'][0] ?? 0;
            $due = $acc['due'][0] ?? 0;
            $rate = $r['rate'] ?? $vat;
            $base = $committed ?: $scheduled;          // יתרה — מההתחייבות, ובלי הצעה — מאבני הדרך
            $balance = max(0, $base - $paid);
            $gap = $due - $paid;                       // חיובי = פיגור, שלילי = שולם מראש
            $paidPct = $base > 0 ? (int)round($paid * 100 / $base) : 0;
            $progress = $s ? pm_stage_progress($s) : 0;
            $flags = [];
            if ($gap > 100) $flags[] = 'behind';
            if ($s && $base > 0 && $paidPct > $progress + $overpay) $flags[] = 'overpaid';
            if ($committed > 0 && abs($scheduled - $committed) > 100) $flags[] = $scheduled < $committed ? 'uncovered' : 'overscheduled';
            if ($committed === 0 && $scheduled > 0) $flags[] = 'no_quote';
            foreach (['committed' => $committed, 'scheduled' => $scheduled, 'due' => $due, 'paid' => $paid] as $k => $v) pm_acc($S, $k, $v, $rate);
            if (!empty($acc['index'])) foreach ([0, 1] as $i) $S['index'][$i] = ($S['index'][$i] ?? 0) + $acc['index'][$i];
            pm_acc($S, 'balance', $balance, $rate);
            pm_acc($S, 'gap', $gap, $rate);
            if ($committed) { $suppliersCommitted[0] += $committed; $suppliersCommitted[1] += pm_net($committed, $rate); }
            $outRows[] = [
                'supplier_id' => $cid, 'name' => $r['name'], 'quote_id' => $r['quote_id'], 'vat_rate' => $rate, 'milestones' => $r['milestones'],
                'committed' => pm_m($committed, pm_net($committed, $rate)), 'scheduled' => pm_m($scheduled, pm_net($scheduled, $rate)),
                'due' => pm_m($due, pm_net($due, $rate)), 'paid' => pm_m($paid, pm_net($paid, $rate)),
                'balance' => pm_m($balance, pm_net($balance, $rate)), 'gap' => pm_m($gap, pm_net($gap, $rate)),
                'paid_pct' => $paidPct, 'progress' => $progress, 'flags' => $portal ? array_values(array_intersect($flags, ['overpaid'])) : $flags,
                'changes' => $r['changes'] ?? [], 'index' => pm_m($acc['index'][0] ?? 0, $acc['index'][1] ?? 0),
                // החוזה עם הספק (מועדים, אבני דרך, קנס) — תקציר לשורה
                'contract' => isset($contracts[$cid]) ? array_intersect_key($contracts[$cid], array_flip(['id', 'end_date', 'completed_on', 'delay_days', 'penalty', 'days_left', 'worst_deviation', 'max_deviation_days']))
                    + ['late_milestones' => count(array_filter($contracts[$cid]['milestones'], fn($m) => in_array($m['status'], ['late', 'breach'], true)))] : null,
            ];
        }
        $committedStage = $S['committed'][0] ?? 0;
        $budgetStage = $s ? (int)$s['budget'] : 0;
        // צפי: מה שסוכם, ובשלב בלי הצעה — התקציב שלו
        $fc = $committedStage ?: $budgetStage;
        $forecast[0] += $fc;
        $forecast[1] += $committedStage ? ($S['committed'][1] ?? 0) : pm_net($budgetStage, $vat);
        foreach ($S as $k => [$g, $n]) { $T[$k][0] = ($T[$k][0] ?? 0) + $g; $T[$k][1] = ($T[$k][1] ?? 0) + $n; }
        if (!$outRows && !$budgetStage) continue;
        $outStages[] = [
            'stage_id' => $s ? (int)$s['id'] : null, 'name' => $s['name'] ?? '', 'status' => $s['status'] ?? '', 'unit_name' => $s['unit_name'] ?? null,
            'progress' => $s ? pm_stage_progress($s) : 0, 'budget' => pm_m($budgetStage, pm_net($budgetStage, $vat)),
            'over_budget' => $budgetStage > 0 && $committedStage > $budgetStage,
            'rows' => $outRows,
        ] + pm_acc_out($S, ['committed', 'scheduled', 'due', 'paid', 'balance', 'gap']);
    }

    // הוצאות ישירות (כ5) — הלקוח כבר שילם: נכנסות לסוכם, למתוכנן, ל"עד היום" ולשולם (בלי פיגור ובלי יתרה)
    $expenses = array_values(array_filter(pm_expenses_of($projectId), fn($x) => $scope === null || ($x['stage_id'] ? $in($x['stage_id']) : $scope['shared'])));
    $E = [0, 0];
    foreach ($expenses as $x) { $E[0] += (int)round($x['amount']['g'] * 100); $E[1] += (int)round($x['amount']['n'] * 100); }
    if ($E[0]) {
        foreach (['committed', 'scheduled', 'due', 'paid'] as $k) { $T[$k][0] = ($T[$k][0] ?? 0) + $E[0]; $T[$k][1] = ($T[$k][1] ?? 0) + $E[1]; }
        $forecast[0] += $E[0];
        $forecast[1] += $E[1];
    }

    // שכר הטרחה של המנהל — גלוי ללקוח כחלק מההוצאות; אבני דרך משלו (כ5)
    $fee = null;
    if ($scope === null && ($p['fee_type'] === 'percent' || $p['fee_type'] === 'fixed')) { // שכר הטרחה — של כל המתחם, לא ללקוח של יחידה
        $g = $p['fee_type'] === 'percent' ? (int)round($suppliersCommitted[0] * (float)$p['fee_value'] / 100) : (int)round((float)$p['fee_value'] * 100);
        $n = pm_net($g, $vat); // שכר הטרחה — שירות של המנהל, במע"מ הרגיל
        $fp = pm_fee_payments_of($projectId);
        $F = ['scheduled' => 0, 'due' => 0, 'paid' => 0];
        foreach ($fp as $x) {
            $a = (int)round($x['amount']['g'] * 100);
            $F['scheduled'] += $a;
            if ($x['received']) $F['paid'] += (int)round($x['received_amount'] * 100);
            if ($x['received'] || ($x['due_date'] !== '' && $x['due_date'] <= $today)) $F['due'] += $a;
        }
        $bal = max(0, $g - $F['paid']);
        $fee = ['type' => $p['fee_type'], 'value' => (float)$p['fee_value'], 'name' => $p['owner_name'], 'amount' => pm_m($g, $n),
            'scheduled' => pm_m($F['scheduled'], pm_net($F['scheduled'], $vat)), 'paid' => pm_m($F['paid'], pm_net($F['paid'], $vat)),
            'due' => pm_m($F['due'], pm_net($F['due'], $vat)), 'balance' => pm_m($bal, pm_net($bal, $vat)), 'payments' => $fp];
        foreach (['committed' => $g, 'balance' => $bal, 'scheduled' => $F['scheduled'], 'due' => $F['due'], 'paid' => $F['paid'], 'gap' => $F['due'] - $F['paid']] as $k => $v) {
            $T[$k][0] = ($T[$k][0] ?? 0) + $v;
            $T[$k][1] = ($T[$k][1] ?? 0) + pm_net($v, $vat);
        }
        $forecast[0] += $g;
        $forecast[1] += $n;
    }

    $budget = ($scope !== null ? (int)$scope['budget'] : (int)$p['budget']) * 100;
    $initial = $scope !== null ? $budget : (int)$p['budget_initial'] * 100;
    $committedAll = $T['committed'][0] ?? 0;
    // "תקציב היום": התקציב שהמנהל עדכן — או ההתחייבויות, כשעברו אותו
    $todayBudget = max($budget, $committedAll);
    // עיכבון: מה שנעצר בפועל (שחרורים שעוד לא שולמו); בלי אבני דרך — הערכה לפי האחוז
    $retention = $retHeld[0] ?: (int)round(($suppliersCommitted[0]) * (float)$p['retention_pct'] / 100);
    $totals = pm_acc_out($T, ['committed', 'scheduled', 'due', 'paid', 'balance', 'gap']) + [
        'budget' => pm_m($budget, pm_net($budget, $vat)),
        'budget_initial' => pm_m($initial, pm_net($initial, $vat)),
        'budget_today' => pm_m($todayBudget, pm_net($todayBudget, $vat)),
        'budget_growth' => pm_m($todayBudget - $initial, pm_net($todayBudget - $initial, $vat)),
        'forecast' => pm_m($forecast[0], $forecast[1]),
        'retention' => $retHeld[0] ? pm_m($retHeld[0], $retHeld[1]) : pm_m($retention, pm_net($retention, $vat)),
        'retention_held' => (bool)$retHeld[0],
        'index' => pm_m($T['index'][0] ?? 0, $T['index'][1] ?? 0),
        'paid_pct' => $committedAll > 0 ? (int)round(($T['paid'][0] ?? 0) * 100 / $committedAll) : 0,
        'progress' => pm_project_progress($stages),
        'over_budget' => $budget > 0 && $committedAll > $budget,
        'next' => $next ? array_diff_key($next, ['key' => 1]) : null,
    ];
    if (!$portal) {
        // כמה שורות מסומנות — לכל סוג
        $issues = [];
        foreach ($outStages as $s) foreach ($s['rows'] as $r) foreach ($r['flags'] as $f) $issues[$f] = ($issues[$f] ?? 0) + 1;
        $totals['issues'] = $issues;
    }
    return ['settings' => pm_finance_settings($p), 'totals' => $totals, 'stages' => $outStages, 'fee' => $fee,
        'expenses' => ['items' => $expenses, 'total' => pm_m($E[0], $E[1])],
        'units' => array_values(array_filter(pm_finance_units($projectId, $stages, $outStages, $contracts, $expenses),
            fn($u) => $scope === null || in_array($u['id'], $scope['units'], true)))];
}

/**
 * מתחם: הכספים לפי יחידה. שורה בשלב של יחידה — כולה שלה; בשלב משותף — לפי החלוקה בחוזה עם הספק
 * (pm_contract_ratios), ובלי חלוקה — "משותף". הוצאה ישירה — לפי השלב שלה. [] — פרויקט בלי יחידות.
 */
function pm_finance_units(int $projectId, array $stages, array $outStages, array $contracts, array $expenses): array
{
    $units = pm_units_of($projectId);
    if (!$units) return [];
    $U = [];
    $add = function (int $u, string $k, array $m, float $ratio = 1.0) use (&$U) {
        $U[$u][$k]['g'] = ($U[$u][$k]['g'] ?? 0) + $m['g'] * $ratio;
        $U[$u][$k]['n'] = ($U[$u][$k]['n'] ?? 0) + $m['n'] * $ratio;
    };
    $unitOf = fn(?int $sid) => $sid && isset($stages[$sid]) && $stages[$sid]['unit_id'] !== null ? (int)$stages[$sid]['unit_id'] : 0;
    // כמה מכל ספק בשלבים המשותפים — בשביל "סכום קבוע ליחידה"
    $sharedOf = [];
    foreach ($outStages as $s) if (!$unitOf($s['stage_id'])) foreach ($s['rows'] as $r) $sharedOf[$r['supplier_id']] = ($sharedOf[$r['supplier_id']] ?? 0) + $r['committed']['g'];
    foreach ($outStages as $s) {
        $u = $unitOf($s['stage_id']);
        foreach ($s['rows'] as $r) {
            $ratios = $u ? [$u => 1.0] : (pm_contract_ratios($contracts[$r['supplier_id']] ?? null, $units, $sharedOf[$r['supplier_id']] ?? 0) ?: [0 => 1.0]);
            foreach ($ratios as $to => $k) foreach (['committed', 'paid', 'balance'] as $f) $add((int)$to, $f, $r[$f], $k);
        }
    }
    foreach ($expenses as $x) foreach (['committed', 'paid'] as $f) $add($unitOf($x['stage_id']), $f, $x['amount']);
    $out = [];
    $m = fn(int $u, string $k) => ['g' => round($U[$u][$k]['g'] ?? 0, 2), 'n' => round($U[$u][$k]['n'] ?? 0, 2)];
    foreach ($units as $x) $out[] = ['id' => $x['id'], 'name' => $x['name'], 'area' => $x['area'], 'budget' => $x['budget'],
        'committed' => $m($x['id'], 'committed'), 'paid' => $m($x['id'], 'paid'), 'balance' => $m($x['id'], 'balance')];
    if (isset($U[0])) $out[] = ['id' => 0, 'name' => pm_t('משותף — לא מחולק'), 'area' => null, 'budget' => 0,
        'committed' => $m(0, 'committed'), 'paid' => $m(0, 'paid'), 'balance' => $m(0, 'balance')];
    return $out;
}

/** התקדמות הפרויקט: ממוצע השלבים, משוקלל בהתחייבות כשיש — אחרת פשוט. */
function pm_project_progress(array $stages): int
{
    if (!$stages) return 0;
    return (int)round(array_sum(array_map('pm_stage_progress', $stages)) / count($stages));
}

/** הגדרות הכספים של הפרויקט: תקציב (ראשוני והיום), שכר טרחה, עיכבון והצמדה. */
function pm_finance_settings_save(array $user, array $in): array
{
    $projectId = (int)($in['project_id'] ?? 0);
    $p = pm_project_row($user, $projectId);
    $num = function (string $k, float $max, string $label) use ($in): float {
        $v = $in[$k] ?? 0;
        if ($v === '' || $v === null) return 0.0;
        if (!is_numeric($v) || $v < 0 || $v > $max) throw new PmError('{field}: מספר לא תקין', 422, ['field' => $label]);
        return (float)$v;
    };
    $feeType = (string)($in['fee_type'] ?? '');
    if (!in_array($feeType, PM_FEE_TYPES, true)) throw new PmError('סוג שכר טרחה לא מוכר', 422);
    $feeValue = $num('fee_value', $feeType === 'percent' ? 100 : 1e10, 'שכר טרחה'); // i18n-ok
    $indexOn = !empty($in['index_on']) ? 1 : 0;
    $month = (string)($in['index_base_month'] ?? '');
    if ($month !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) throw new PmError('{field}: מספר לא תקין', 422, ['field' => 'חודש בסיס']);
    $f = [
        'budget'           => (int)round($num('budget', 1e10, 'תקציב')), // i18n-ok
        'budget_initial'   => (int)round($num('budget_initial', 1e10, 'תקציב ראשוני')), // i18n-ok
        'fee_type'         => $feeType,
        'fee_value'        => $feeType ? $feeValue : 0,
        'retention_pct'    => $num('retention_pct', 50, 'עיכבון'), // i18n-ok
        'index_on'         => $indexOn,
        'index_name'       => pm_str($in, 'index_name', 60, false, 'מדד'),
        'index_base_month' => $month,
        'index_base'       => ($in['index_base'] ?? '') === '' ? null : $num('index_base', 1e6, 'מדד בסיס'), // i18n-ok
    ];
    if ($indexOn && ($f['index_base_month'] === '' || $f['index_base'] === null)) throw new PmError('הצמדה: צריך חודש בסיס ומדד בסיס', 422);
    $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($f)));
    pm_db()->prepare("UPDATE projects SET $set, updated_at = ? WHERE id = ?")->execute(array_merge(array_values($f), [pm_now(), $projectId]));
    $changed = array_keys(array_filter($f, fn($v, $k) => (string)$v !== (string)$p[$k], ARRAY_FILTER_USE_BOTH));
    if ($changed) pm_event($user, $projectId, 'finance', 'הגדרות הכספים עודכנו', ['fields' => $changed]);
    if (in_array('budget', $changed, true)) {
        pm_event($user, $projectId, 'finance', 'התקציב עודכן: {from} ← {to}', ['budget' => $f['budget']],
            ['from' => pm_money((int)$p['budget'] * 100), 'to' => pm_money($f['budget'] * 100)]);
    }
    return pm_finance($user, $projectId);
}

/**
 * "חלק את ההצעה": אבני דרך מההצעה שאושרה — {stage_id, supplier_id, parts: [{title, percent, due_kind, due_pct, due_date}]}.
 * סכום האחוזים — 100.
 */
function pm_payment_split(array $user, array $in): array
{
    $stage = pm_stage_row($user, (int)($in['stage_id'] ?? 0));
    $projectId = (int)$stage['project_id'];
    $quote = pm_stage_approved_quote((int)$stage['id']) ?? throw new PmError('אין בשלב הצעה שאושרה', 422);
    $parts = is_array($in['parts'] ?? null) ? $in['parts'] : [];
    if (!$parts || count($parts) > 12) throw new PmError('חלוקה: בין תשלום אחד ל-12', 422);
    $sum = array_sum(array_map(fn($x) => (float)($x['percent'] ?? 0), $parts));
    if (abs($sum - 100) > 0.01) throw new PmError('החלוקה צריכה להסתכם ב-100% (עכשיו {n}%)', 422, ['n' => round($sum, 2)]);
    $p = pm_project_row($user, $projectId);
    $db = pm_db();
    $db->beginTransaction();
    try {
        foreach ($parts as $x) {
            pm_payment_save($user, [
                'project_id' => $projectId, 'stage_id' => (int)$stage['id'], 'supplier_id' => (int)$quote['supplier_id'],
                'title' => (string)($x['title'] ?? ''), 'percent' => (float)($x['percent'] ?? 0), 'amount' => null,
                'due_kind' => (string)($x['due_kind'] ?? 'date'), 'due_pct' => $x['due_pct'] ?? null, 'due_date' => (string)($x['due_date'] ?? ''),
                'payer' => 'client', 'needs_lawyer' => $p['lawyer_id'] ? 1 : 0, 'notes' => '',
            ]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return pm_finance($user, $projectId) + pm_payments($user, $projectId);
}

// ================= כ2 — התראות =================

/** ברירות המחדל של הספים — לכל מנהל אפשר לשנות (הגדרות ← התראות). */
const PM_FIN_ALERT_DEFAULTS = [
    'days_before'     => 3,    // תשלום מתקרב — כמה ימים לפני
    'overpay'         => 10,   // שולם מעל הביצוע — ביותר מכמה נקודות אחוז
    'budget_warn'     => 80,   // אזהרה כשההתחייבויות מגיעות לאחוז הזה מהתקציב
    'proof_days'      => 2,    // אסמכתא שלא אושרה — אחרי כמה ימים היא "באיחור"
    'notify_contacts' => true, // מייל ללקוח ולעו"ד על חריגה מהתקציב, גידול בתקציב ושולם מעל הביצוע
    'invoice_days'    => 14,   // חשבונית מהספק — כמה ימים אחרי שהתשלום התקבל
];

function pm_fin_alert_settings(int $userId): array
{
    static $cache = [];
    if (!isset($cache[$userId])) {
        $st = pm_db()->prepare('SELECT fin_alerts FROM users WHERE id = ?');
        $st->execute([$userId]);
        $j = json_decode((string)$st->fetchColumn(), true);
        $cache[$userId] = array_merge(PM_FIN_ALERT_DEFAULTS, is_array($j) ? array_intersect_key($j, PM_FIN_ALERT_DEFAULTS) : []);
    }
    return $cache[$userId];
}

function pm_fin_alert_settings_save(array $user, array $in): array
{
    $int = function (string $k, int $min, int $max, string $label) use ($in): int {
        $v = $in[$k] ?? PM_FIN_ALERT_DEFAULTS[$k];
        if (!is_numeric($v) || $v < $min || $v > $max) throw new PmError('{field}: בין {min} ל-{max}', 422, ['field' => $label, 'min' => $min, 'max' => $max]);
        return (int)$v;
    };
    $cfg = [
        'days_before'     => $int('days_before', 0, 60, 'ימים לפני תשלום'), // i18n-ok
        'overpay'         => $int('overpay', 0, 100, 'שולם מעל הביצוע'), // i18n-ok
        'budget_warn'     => $int('budget_warn', 1, 100, 'אזהרת תקציב'), // i18n-ok
        'proof_days'      => $int('proof_days', 0, 60, 'ימים לאישור אסמכתא'), // i18n-ok
        'invoice_days'    => $int('invoice_days', 0, 120, 'ימים לחשבונית'), // i18n-ok
        'notify_contacts' => !empty($in['notify_contacts']),
    ];
    pm_db()->prepare('UPDATE users SET fin_alerts = ? WHERE id = ?')->execute([json_encode($cfg), $user['id']]);
    pm_audit("user:{$user['id']}", 'settings.fin_alerts', 'user', (int)$user['id'], $cfg);
    return ['fin_alerts' => $cfg];
}

/**
 * ההתראות הכספיות של פרויקט. $audience — manager (הכול), או client / lawyer (רק מה שנוגע להם, לפורטל ולמייל).
 * כל התראה: level (late · soon · wait · info), kind, key (קבוע — למייל שנשלח פעם אחת), text, sort,
 * ולפעמים payment_id (לפעולה: דרישת תשלום / אישור קבלה).
 */
function pm_fin_alerts(int $projectId, array $cfg, ?string $lang = null, string $audience = 'manager'): array
{
    $lang ??= pm_lang();
    $db = pm_db();
    $today = pm_today();
    $soon = pm_today((int)$cfg['days_before']);
    $mgr = $audience === 'manager';
    $out = [];
    $add = function (string $level, string $kind, string $key, string $tpl, array $vars, string $sort, ?int $payment = null) use (&$out, $lang) {
        $out[] = ['level' => $level, 'kind' => $kind, 'key' => $key, 'text' => pm_t($tpl, $vars, $lang), 'sort' => $sort, 'payment_id' => $payment];
    };

    $st = $db->prepare('SELECT * FROM stages WHERE project_id = ?');
    $st->execute([$projectId]);
    $stages = [];
    foreach ($st->fetchAll() as $s) $stages[(int)$s['id']] = $s;
    $ps = $db->prepare('SELECT status FROM projects WHERE id = ?');
    $ps->execute([$projectId]);
    $projectStatus = (string)$ps->fetchColumn();

    // ---- תשלומים ----
    $pay = $db->prepare("SELECT p.*, c.name AS supplier FROM payments p JOIN contacts c ON c.id = p.supplier_id
                          WHERE p.project_id = ? AND p.status NOT IN ('received','cancelled')");
    $pay->execute([$projectId]);
    foreach ($pay->fetchAll() as $p) {
        $v = ['title' => $p['title'], 'supplier' => $p['supplier'], 'amount' => pm_money((int)$p['amount']), 'date' => pm_short_date($p['due_date'])];
        $id = (int)$p['id'];
        $mine = $audience === ($p['payer'] === 'lawyer' ? 'lawyer' : 'client');
        if ($p['due_kind'] === 'date' && $p['due_date'] !== '' && $p['due_date'] <= $soon) {
            $late = $p['due_date'] < $today;
            if ($mgr) {
                $add($late ? 'late' : 'soon', $late ? 'pay_late' : 'pay_soon', "pay:$id", $late ? 'תשלום באיחור: {title} — {supplier}, {amount} ({date})' : 'תשלום מתקרב: {title} — {supplier}, {amount} ({date})', $v, $p['due_date'], $id); // i18n-ok
            } elseif ($mine && $p['status'] !== 'planned') {
                $add($late ? 'late' : 'soon', $late ? 'pay_late' : 'pay_soon', "pay:$id", $late ? 'תשלום באיחור: {title} — {supplier}, {amount} ({date})' : 'תשלום קרוב: {title} — {supplier}, {amount} ({date})', $v, $p['due_date'], $id); // i18n-ok
            }
        } elseif ($mgr && $p['due_kind'] === 'advance' && $p['status'] === 'planned') {
            $add('soon', 'pay_reached', "reached:$id", 'מקדמה — לשלוח דרישת תשלום: {title} — {supplier}, {amount}', $v, $today, $id); // i18n-ok
        } elseif ($mgr && $p['due_kind'] === 'handover' && $p['status'] === 'planned' && pm_payment_due($p, null, $today, $projectStatus)) {
            $un = $p['unit_id'] ? pm_unit_name((int)$p['unit_id']) : '';
            if ($p['kind'] === 'retention') $add('soon', 'pay_reached', "reached:$id", $un !== '' ? '{unit} נמסרה — לשחרר את העיכבון: {supplier}, {amount}' : 'הפרויקט נמסר — לשחרר את העיכבון: {supplier}, {amount}', $v + ['unit' => $un], $today, $id); // i18n-ok
            else $add('soon', 'pay_reached', "reached:$id", $un !== '' ? '{unit} נמסרה — הגיע מועד התשלום: {title} — {supplier}, {amount}' : 'הפרויקט נמסר — הגיע מועד התשלום: {title} — {supplier}, {amount}', $v + ['unit' => $un], $today, $id); // i18n-ok
        } elseif ($mgr && $p['due_kind'] !== 'date' && $p['due_kind'] !== 'handover' && $p['status'] === 'planned' && pm_payment_due($p, $stages[(int)$p['stage_id']] ?? null, $today)) {
            // "בגמר השלב" / "בהתקדמות" — הגיע המועד, ועוד לא נשלחה דרישה
            $s = $stages[(int)$p['stage_id']] ?? null;
            $done = $s && in_array($s['status'], ['done', 'paid'], true);
            $add('soon', 'pay_reached', "reached:$id", $done ? 'השלב בוצע — הגיע מועד התשלום: {title} — {supplier}, {amount}' : 'ההתקדמות הגיעה ל-{n}% — הגיע מועד התשלום: {title} — {supplier}, {amount}', // i18n-ok
                $v + ['n' => (int)($s['progress'] ?? 0)], $today, $id);
        }
        if ($mgr && $p['status'] === 'partial') {
            $add('wait', 'pay_partial', "partial:$id", 'יתרה פתוחה: {title} — {supplier}, נשארו {amount}', ['title' => $p['title'], 'supplier' => $p['supplier'], // i18n-ok
                'amount' => pm_money(max(0, (int)$p['amount'] - pm_payment_received($id)))], $today, $id);
        }
    }
    // אסמכתאות שממתינות לאישור קבלה
    if ($mgr) {
        $x = $db->prepare("SELECT p.id, p.title, x.amount, x.created_at FROM payment_proofs x JOIN payments p ON p.id = x.payment_id WHERE p.project_id = ? AND x.status = 'pending'");
        $x->execute([$projectId]);
        foreach ($x->fetchAll() as $r) {
            $days = pm_days_since($r['created_at']);
            $add($days >= (int)$cfg['proof_days'] ? 'late' : 'wait', 'proof', 'proof:' . $r['id'],
                $days >= 1 ? 'דווח תשלום לפני {n} ימים — לאשר שהתקבל: {title}, {amount}' : 'דווח תשלום — לאשר שהתקבל: {title}, {amount}', // i18n-ok
                ['n' => $days, 'title' => $r['title'], 'amount' => pm_money((int)$r['amount'])], substr($r['created_at'], 0, 10), (int)$r['id']);
        }
    }

    // ---- חשבונית מהספק: תשלום שהתקבל, ואין חשבונית אחרי X ימים ----
    if ($mgr || $audience === 'supplier') {
        $inv = $db->prepare("SELECT p.id, p.title, p.supplier_id, c.name AS supplier, MAX(x.reviewed_at) AS got FROM payments p
                              JOIN payment_proofs x ON x.payment_id = p.id AND x.status = 'received' JOIN contacts c ON c.id = p.supplier_id
                             WHERE p.project_id = ? AND p.invoice_at IS NULL AND p.status != 'cancelled' GROUP BY p.id");
        $inv->execute([$projectId]);
        foreach ($inv->fetchAll() as $r) {
            $days = pm_days_since($r['got']);
            if ($days < (int)$cfg['invoice_days']) continue;
            $add('wait', 'invoice', 'invoice:' . $r['id'], 'חסרה חשבונית: {title} — {supplier} (התקבל לפני {n} ימים)', // i18n-ok
                ['title' => $r['title'], 'supplier' => $r['supplier'], 'n' => $days], substr((string)$r['got'], 0, 10), (int)$r['id']);
        }
    }
    // ---- החוזים: אבני דרך, הפרה יסודית, איחור בסיום וקנס ----
    if ($mgr) pm_contract_alerts($projectId, $add, $lang);
    // ---- שכר הטרחה שלי: אבן דרך שעבר מועדה ולא סומנה "התקבל" ----
    if ($mgr) {
        $fp = $db->prepare("SELECT * FROM fee_payments WHERE project_id = ? AND received_on IS NULL AND due_date != '' AND due_date <= ?");
        $fp->execute([$projectId, $soon]);
        foreach ($fp->fetchAll() as $x) {
            $late = $x['due_date'] < $today;
            $add($late ? 'late' : 'soon', 'fee', 'fee:' . $x['id'], $late ? 'שכר טרחה באיחור: {title} — {amount} ({date})' : 'שכר טרחה מתקרב: {title} — {amount} ({date})', // i18n-ok
                ['title' => $x['title'], 'amount' => pm_money((int)$x['amount']), 'date' => pm_short_date($x['due_date'])], $x['due_date']);
        }
    }
    // ---- תוספות וחריגות שממתינות ----
    $co = $db->prepare("SELECT o.*, c.name AS supplier, s.name AS stage FROM change_orders o JOIN contacts c ON c.id = o.supplier_id JOIN stages s ON s.id = o.stage_id
                         WHERE o.project_id = ? AND o.status IN ('sent','client_approved')");
    $co->execute([$projectId]);
    foreach ($co->fetchAll() as $o) {
        $v = ['supplier' => $o['supplier'], 'stage' => $o['stage'], 'amount' => pm_money((int)$o['amount']), 'n' => pm_days_since($o['updated_at'])];
        $waitsFor = $o['status'] === 'sent' ? 'client' : 'lawyer';
        if ($mgr && $v['n'] >= PM_WAIT_DAYS) {
            $add('wait', 'co_pending', 'co:' . $o['id'], $waitsFor === 'client' ? 'תוספת ממתינה ללקוח {n} ימים: {supplier}, {stage} — {amount}' : 'תוספת ממתינה לעו"ד {n} ימים: {supplier}, {stage} — {amount}', $v, substr($o['updated_at'], 0, 10)); // i18n-ok
        } elseif ($audience === $waitsFor) {
            $add('soon', 'co_pending', 'co:' . $o['id'], 'תוספת ממתינה לאישורך: {supplier}, {stage} — {amount}', $v, substr($o['updated_at'], 0, 10)); // i18n-ok
        }
    }

    // ---- מהמצב הכספי: תקציב, שולם מעל הביצוע, כיסוי ----
    $f = pm_finance_of($projectId, false, (int)$cfg['overpay']);
    $T = $f['totals'];
    $budget = $T['budget']['g'];
    $committed = $T['committed']['g'];
    if ($budget > 0 && $committed > $budget) {
        $add('late', 'over_budget', 'over_budget:' . (int)round($committed), 'חריגה מהתקציב: סוכם {committed} מתוך תקציב {budget}', // i18n-ok
            ['committed' => pm_money((int)round($committed * 100)), 'budget' => pm_money((int)round($budget * 100))], $today);
    } elseif ($budget > 0 && $committed >= $budget * (int)$cfg['budget_warn'] / 100) {
        $add('soon', 'budget_warn', 'budget_warn:' . (int)$cfg['budget_warn'], '{n}% מהתקציב כבר סוכם ({committed} מתוך {budget})', // i18n-ok
            ['n' => (int)floor($committed * 100 / $budget), 'committed' => pm_money((int)round($committed * 100)), 'budget' => pm_money((int)round($budget * 100))], $today);
    }
    $growth = (int)round($T['budget_growth']['g']);
    if (!$mgr && $growth > 0) {
        $add('info', 'budget_grew', 'budget_grew:' . $growth, 'התקציב גדל ב-{amount} מאז הפתיחה ({from} ← {to})', // i18n-ok
            ['amount' => pm_money($growth * 100), 'from' => pm_money((int)round($T['budget_initial']['g'] * 100)), 'to' => pm_money((int)round($T['budget_today']['g'] * 100))], $today);
    }
    foreach ($f['stages'] as $s) {
        if ($mgr && $s['over_budget']) {
            $add('late', 'stage_over', 'stage_over:' . $s['stage_id'], 'בשלב {stage} סוכם {committed} — מעל התקציב שלו ({budget})', // i18n-ok
                ['stage' => $s['name'], 'committed' => pm_money((int)round($s['committed']['g'] * 100)), 'budget' => pm_money((int)round($s['budget']['g'] * 100))], $today);
        }
        foreach ($s['rows'] as $r) {
            $v = ['supplier' => $r['name'], 'stage' => $s['name'] ?: pm_t('בלי שלב', [], $lang), 'paid' => $r['paid_pct'], 'done' => $r['progress'],
                  's' => pm_money((int)round($r['scheduled']['g'] * 100)), 'c' => pm_money((int)round($r['committed']['g'] * 100))];
            $k = $s['stage_id'] . ':' . $r['supplier_id'];
            if (in_array('overpaid', $r['flags'], true) && ($mgr || $audience === 'lawyer')) {
                $add('late', 'overpaid', "overpaid:$k", 'שולם מעל הביצוע: {supplier}, {stage} — שולם {paid}%, בוצע {done}%', $v, $today); // i18n-ok
            }
            if (!$mgr) continue;
            if (in_array('uncovered', $r['flags'], true)) $add('wait', 'uncovered', "uncovered:$k", 'אבני הדרך לא מכסות את ההצעה: {supplier}, {stage} — {s} מתוך {c}', $v, $today); // i18n-ok
            if (in_array('overscheduled', $r['flags'], true)) $add('wait', 'overscheduled', "overscheduled:$k", 'אבני הדרך עוברות את ההצעה: {supplier}, {stage} — {s} מול {c}', $v, $today); // i18n-ok
            if (in_array('no_quote', $r['flags'], true)) $add('wait', 'no_quote', "no_quote:$k", 'אבני דרך בלי הצעה שאושרה: {supplier}, {stage}', $v, $today); // i18n-ok
        }
    }
    $rank = ['late' => 0, 'soon' => 1, 'wait' => 2, 'info' => 3];
    usort($out, fn($a, $b) => [$rank[$a['level']], $a['sort']] <=> [$rank[$b['level']], $b['sort']]);
    return $out;
}

/**
 * מייל ללקוח ולעו"ד — פעם אחת לכל התראה (fin_alert_sent): חריגה מהתקציב, התקציב גדל, ולעו"ד גם שולם
 * מעל הביצוע. רץ עם מייל הבוקר (pm_digest_tick), לכל פרויקט פעיל שהמנהל שלו לא כיבה את זה.
 */
function pm_fin_contact_tick(): void
{
    $db = pm_db();
    $kinds = ['over_budget', 'budget_warn', 'budget_grew', 'overpaid'];
    foreach ($db->query("SELECT id, owner_id, name, client_id, lawyer_id FROM projects WHERE status = 'active'")->fetchAll() as $p) {
        $cfg = pm_fin_alert_settings((int)$p['owner_id']);
        if (!$cfg['notify_contacts']) continue;
        foreach (['client' => $p['client_id'], 'lawyer' => $p['lawyer_id']] as $role => $cid) {
            if (!$cid) continue;
            $c = pm_contact_full((int)$cid);
            if (!$c || $c['email'] === '') continue;
            $lang = pm_lang_valid($c['lang'] ?? 'he');
            $new = [];
            foreach (pm_fin_alerts((int)$p['id'], $cfg, $lang, $role) as $a) {
                if (!in_array($a['kind'], $kinds, true)) continue;
                $seen = $db->prepare('SELECT 1 FROM fin_alert_sent WHERE project_id = ? AND contact_id = ? AND key = ?');
                $seen->execute([$p['id'], $cid, $a['key']]);
                if (!$seen->fetchColumn()) $new[] = $a;
            }
            if (!$new) continue;
            $owner = pm_project_owner((int)$p['id']);
            $subject = pm_t('עדכון כספי — {project}', ['project' => $p['name']], $lang);
            $body = pm_t("שלום {name},\n\nעדכון כספי בפרויקט {project}:\n{items}\n\nהמצב הכספי המלא, בקישור האישי שלך:\n{link}\n\n{manager}", [
                'name' => $c['name'], 'project' => $p['name'], 'manager' => $owner['display_name'],
                'items' => implode("\n", array_map(fn($a) => '• ' . $a['text'], $new)),
                'link' => pm_notify_link((int)$p['id'], (int)$cid),
            ], $lang);
            $ok = pm_send_mail($c['email'], $subject, $body, $lang);
            $now = pm_now();
            $db->prepare('INSERT INTO messages (project_id, contact_id, channel, key, recipient, subject, body, status, auto, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
               ->execute([$p['id'], $cid, 'email', 'fin_alert', $c['email'], $subject, $body, $ok ? 'sent' : 'failed', 1, 0, $now]);
            pm_log($ok ? 'info' : 'warn', 'mail', 'mail.fin_alert', ['data' => ['to' => $c['email'], 'project' => (int)$p['id'], 'alerts' => array_column($new, 'key'), 'sent' => $ok]]);
            if (!$ok) continue;
            $ins = $db->prepare('INSERT OR IGNORE INTO fin_alert_sent (project_id, contact_id, key, sent_at) VALUES (?,?,?,?)');
            foreach ($new as $a) $ins->execute([$p['id'], $cid, $a['key'], $now]);
            pm_event(['id' => 0, 'display_name' => 'מערכת'], (int)$p['id'], 'message', '✉️ נשלח מייל ל{name}: {subject}', ['contact' => (int)$cid, 'key' => 'fin_alert'],
                ['name' => $c['name'], 'subject' => $subject]);
        }
    }
}

// ================= כ3 — האגף: כל הפרויקטים =================

/** מתי צפוי תשלום (לתזרים): התאריך; "בגמר שלב" / "בהתקדמות" — סיום השלב (בפועל או מתוכנן). '' — לא ידוע. */
function pm_payment_expected(array $p, ?array $stage): string
{
    if ($p['due_kind'] === 'date') return $p['due_date'];
    if (!$stage) return '';
    if ($stage['done_at']) return substr((string)$stage['done_at'], 0, 10);
    return $stage['plan_end'] ?: '';
}

/**
 * "💰 כספים" — כל הפרויקטים של המנהל. $in: project_id, supplier_id, all (כולל שהסתיימו).
 * מחזיר: סיכום, התראות (עם שם הפרויקט), פרויקטים, תזרים חודשי (6 אחורה, 6 קדימה), תשלומים קרובים.
 */
function pm_finance_all(array $user, array $in): array
{
    $db = pm_db();
    $cfg = pm_fin_alert_settings((int)$user['id']);
    $all = !empty($in['all']);
    $pf = (int)($in['project_id'] ?? 0);
    $sf = (int)($in['supplier_id'] ?? 0);
    [$where, $wargs] = pm_project_clause($user, 'p'); // גם פרויקטים ששותפו איתו
    $st = $db->prepare("SELECT p.id, p.name, p.status, c.name AS client FROM projects p LEFT JOIN contacts c ON c.id = p.client_id
                         WHERE $where AND p.status IN ('active','paused'" . ($all ? ",'done'" : '') . ') ORDER BY p.last_activity_at DESC');
    $st->execute($wargs);
    $projects = $st->fetchAll();
    $allProjects = array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['name']], $projects);
    if ($pf) $projects = array_values(array_filter($projects, fn($p) => (int)$p['id'] === $pf));
    $today = pm_today();

    $sum = [];
    $outProjects = [];
    $alerts = [];
    foreach ($projects as $p) {
        $pid = (int)$p['id'];
        $f = pm_finance_of($pid, false, (int)$cfg['overpay']);
        $P = [];
        $behind = 0;
        foreach ($f['stages'] as $s) foreach ($s['rows'] as $r) {
            if ($sf && $r['supplier_id'] !== $sf) continue;
            foreach (['committed', 'due', 'paid', 'balance'] as $k) { $P[$k][0] = ($P[$k][0] ?? 0) + $r[$k]['g']; $P[$k][1] = ($P[$k][1] ?? 0) + $r[$k]['n']; }
            $g = max(0, $r['gap']['g']);
            if ($g > 0) { $P['overdue'][0] = ($P['overdue'][0] ?? 0) + $g; $P['overdue'][1] = ($P['overdue'][1] ?? 0) + max(0, $r['gap']['n']); }
        }
        // שכר הטרחה וההוצאות הישירות — רק בלי סינון לפי ספק
        if (!$sf && $f['fee']) foreach (['committed' => 'amount', 'due' => 'due', 'paid' => 'paid', 'balance' => 'balance'] as $k => $fk) {
            $P[$k][0] = ($P[$k][0] ?? 0) + $f['fee'][$fk]['g'];
            $P[$k][1] = ($P[$k][1] ?? 0) + $f['fee'][$fk]['n'];
        }
        if (!$sf && $f['expenses']['total']['g']) foreach (['committed', 'due', 'paid'] as $k) {
            $P[$k][0] = ($P[$k][0] ?? 0) + $f['expenses']['total']['g'];
            $P[$k][1] = ($P[$k][1] ?? 0) + $f['expenses']['total']['n'];
        }
        if ($sf && empty($P)) continue;
        $T = $f['totals'];
        $m = fn($k) => ['g' => round($P[$k][0] ?? 0, 2), 'n' => round($P[$k][1] ?? 0, 2)];
        $pa = pm_fin_alerts($pid, $cfg);
        foreach ($pa as $a) $alerts[] = $a + ['project_id' => $pid, 'project_name' => $p['name']];
        $outProjects[] = [
            'id' => $pid, 'name' => $p['name'], 'client' => $p['client'], 'status' => $p['status'],
            'budget_today' => $T['budget_today'], 'over_budget' => $T['over_budget'], 'progress' => $T['progress'],
            'committed' => $m('committed'), 'due' => $m('due'), 'paid' => $m('paid'), 'balance' => $m('balance'), 'overdue' => $m('overdue'),
            // מתוך מה שצריך לשלם בסך הכול (שולם + יתרה) — גם כשאבני הדרך בלי הצעה שאושרה
            'paid_pct' => (($P['paid'][0] ?? 0) + ($P['balance'][0] ?? 0)) > 0 ? (int)round(($P['paid'][0] ?? 0) * 100 / (($P['paid'][0] ?? 0) + ($P['balance'][0] ?? 0))) : 0,
            'alerts' => count(array_filter($pa, fn($a) => in_array($a['level'], ['late', 'soon'], true))),
        ];
        foreach (['committed', 'paid', 'balance', 'overdue'] as $k) { $sum[$k][0] = ($sum[$k][0] ?? 0) + ($P[$k][0] ?? 0); $sum[$k][1] = ($sum[$k][1] ?? 0) + ($P[$k][1] ?? 0); }
    }
    $rank = ['late' => 0, 'soon' => 1, 'wait' => 2, 'info' => 3];
    usort($alerts, fn($a, $b) => [$rank[$a['level']], $a['sort']] <=> [$rank[$b['level']], $b['sort']]);

    // ---- תזרים ותשלומים קרובים — מאבני הדרך ומהאסמכתאות ----
    $months = [];
    $base = new DateTimeImmutable(substr($today, 0, 7) . '-01');
    for ($i = -6; $i <= 6; $i++) $months[$base->modify("$i month")->format('Y-m')] = ['planned' => 0, 'paid' => 0];
    $ids = array_column($outProjects, 'id');
    $upcoming = [];
    $soon30 = [0, 0];
    $in30 = pm_today(30);
    $in14 = pm_today(14);
    if ($ids) {
        $q = implode(',', array_fill(0, count($ids), '?'));
        $ps = $db->prepare("SELECT p.*, c.name AS supplier, pr.name AS project, pr.status AS project_status FROM payments p JOIN contacts c ON c.id = p.supplier_id JOIN projects pr ON pr.id = p.project_id
                             WHERE p.project_id IN ($q) AND p.status != 'cancelled'" . ($sf ? ' AND p.supplier_id = ' . $sf : ''));
        $ps->execute($ids);
        $payments = $ps->fetchAll();
        $sids = array_filter(array_unique(array_map(fn($x) => (int)$x['stage_id'], $payments)));
        $stages = [];
        if ($sids) {
            $ss = $db->query('SELECT * FROM stages WHERE id IN (' . implode(',', $sids) . ')');
            foreach ($ss->fetchAll() as $s) $stages[(int)$s['id']] = $s;
        }
        $proofs = pm_payment_proofs(array_map('intval', array_column($payments, 'id')));
        foreach ($payments as $x) {
            $amount = (int)$x['amount'];
            $received = 0;
            foreach ($proofs[(int)$x['id']] ?? [] as $pf2) {
                if ($pf2['status'] !== 'received') continue;
                $r = (int)round($pf2['received_amount'] * 100);
                $received += $r;
                $mk = substr($pf2['paid_on'], 0, 7);
                if (isset($months[$mk])) $months[$mk]['paid'] += $r;
            }
            $stage = $stages[(int)$x['stage_id']] ?? null;
            $exp = pm_payment_expected($x, $stage);
            if ($exp !== '' && isset($months[substr($exp, 0, 7)])) $months[substr($exp, 0, 7)]['planned'] += $amount;
            $left = $amount - $received;
            if ($left <= 0 || $x['status'] === 'received') continue;
            $rate = pm_payment_vat($x);
            $due = pm_payment_due($x, $stage, $today, $x['project_status']);
            if ($exp !== '' && $exp >= $today && $exp <= $in30) { $soon30[0] += $left; $soon30[1] += pm_net($left, $rate); }
            if ($due || ($exp !== '' && $exp <= $in14)) {
                $upcoming[] = ['payment_id' => (int)$x['id'], 'project_id' => (int)$x['project_id'], 'project' => $x['project'], 'title' => $x['title'],
                    'supplier' => $x['supplier'], 'stage' => $stage['name'] ?? '', 'amount' => pm_m($left, pm_net($left, $rate)),
                    'date' => $exp, 'due_kind' => $x['due_kind'], 'due_pct' => $x['due_pct'] !== null ? (int)$x['due_pct'] : null,
                    'late' => $x['due_kind'] === 'date' ? ($exp !== '' && $exp < $today) : false, 'due' => $due, 'status' => $x['status'], 'label' => pm_payment_label($x)];
            }
        }
    }
    usort($upcoming, fn($a, $b) => [!$a['late'], $a['date'] ?: '9999'] <=> [!$b['late'], $b['date'] ?: '9999']);
    $cash = [];
    foreach ($months as $k => $v) $cash[] = ['month' => $k, 'planned' => $v['planned'] / 100, 'paid' => $v['paid'] / 100, 'past' => $k < substr($today, 0, 7), 'current' => $k === substr($today, 0, 7)];

    // ספקים לסינון — מכל הפרויקטים שלו
    $sp = $db->prepare("SELECT DISTINCT c.id, c.name FROM contacts c JOIN payments p ON p.supplier_id = c.id JOIN projects pr ON pr.id = p.project_id
                         WHERE " . pm_project_clause($user, 'pr')[0] . " AND c.kind = 'supplier' ORDER BY c.name");
    $sp->execute(pm_project_clause($user, 'pr')[1]);

    $M = fn($k) => pm_m((int)round(($sum[$k][0] ?? 0) * 100), (int)round(($sum[$k][1] ?? 0) * 100));
    return [
        'totals' => ['committed' => $M('committed'), 'paid' => $M('paid'), 'balance' => $M('balance'), 'overdue' => $M('overdue'),
                     'next30' => pm_m($soon30[0], $soon30[1]), 'projects' => count($outProjects)],
        'alerts' => array_map(fn($a) => array_diff_key($a, ['key' => 1]), array_slice($alerts, 0, 40)),
        'alerts_total' => count($alerts),
        'projects' => $outProjects, 'cashflow' => $cash, 'upcoming' => array_slice($upcoming, 0, 30),
        'filters' => ['projects' => $allProjects, 'suppliers' => array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name']], $sp->fetchAll())],
        'today' => $today,
        // כ5: שכר הטרחה שלי בכל הפרויקטים, וחשבוניות שחסרות מספקים
        'income' => $sf ? null : pm_my_income($projects),
        'invoices' => pm_missing_invoices(array_column($outProjects, 'id'), $sf),
    ];
}

/** תשלומים שהתקבלו בלי חשבונית מהספק — מהישן. */
function pm_missing_invoices(array $projectIds, int $supplierId = 0): array
{
    if (!$projectIds) return [];
    $q = implode(',', array_map('intval', $projectIds));
    $rows = pm_db()->query("SELECT p.id, p.project_id, p.title, p.amount, p.retention, p.index_diff, p.kind, c.name AS supplier, pr.name AS project, MAX(x.reviewed_at) AS got
                              FROM payments p JOIN payment_proofs x ON x.payment_id = p.id AND x.status = 'received'
                              JOIN contacts c ON c.id = p.supplier_id JOIN projects pr ON pr.id = p.project_id
                             WHERE p.project_id IN ($q) AND p.invoice_at IS NULL AND p.status != 'cancelled'" . ($supplierId ? ' AND p.supplier_id = ' . $supplierId : '') . '
                             GROUP BY p.id ORDER BY got')->fetchAll();
    return array_map(fn($r) => ['payment_id' => (int)$r['id'], 'project_id' => (int)$r['project_id'], 'project' => $r['project'], 'title' => $r['title'],
        'supplier' => $r['supplier'], 'amount' => pm_payment_due_amount($r) / 100, 'days' => pm_days_since($r['got'])], $rows);
}

/** ייצוא ל-Excel (CSV, UTF-8 עם BOM): כל אבני הדרך בפרויקטים, עם מע"מ, מועד, שולם ויתרה. */
function pm_finance_export(array $user, array $in): never
{
    $db = pm_db();
    $all = !empty($in['all']);
    $pf = (int)($in['project_id'] ?? 0);
    $sf = (int)($in['supplier_id'] ?? 0);
    $sql = "SELECT p.*, c.name AS supplier, pr.name AS project, s.name AS stage, s.status AS stage_status, s.progress AS stage_progress, s.done_at, s.plan_end
              FROM payments p JOIN contacts c ON c.id = p.supplier_id JOIN projects pr ON pr.id = p.project_id LEFT JOIN stages s ON s.id = p.stage_id
             WHERE " . pm_project_clause($user, 'pr')[0] . " AND pr.status IN ('active','paused'" . ($all ? ",'done'" : '') . ")" . ($pf ? ' AND pr.id = ' . $pf : '') . ($sf ? ' AND p.supplier_id = ' . $sf : '') . '
             ORDER BY pr.name, s.sort, p.id';
    $st = $db->prepare($sql);
    $st->execute(pm_project_clause($user, 'pr')[1]);
    $rows = $st->fetchAll();
    $proofs = pm_payment_proofs(array_map('intval', array_column($rows, 'id')));
    $kind = ['date' => pm_t('בתאריך'), 'stage_done' => pm_t('בגמר השלב'), 'progress' => pm_t('בהתקדמות')];
    $out = fopen('php://temp', 'w+');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_map('pm_t', ['פרויקט', 'שלב', 'ספק', 'אבן דרך', 'סכום כולל מע"מ', 'לפני מע"מ', 'מע"מ', 'מתי', 'תאריך יעד', 'סטטוס', 'שולם', 'יתרה']), ',', '"', ''); // i18n-ok — מתורגם ב-array_map('pm_t')
    foreach ($rows as $r) {
        $rate = pm_payment_vat($r);
        $g = (int)$r['amount'];
        $n = pm_net($g, $rate);
        $paid = 0;
        foreach ($proofs[(int)$r['id']] ?? [] as $x) if ($x['status'] === 'received') $paid += (int)round($x['received_amount'] * 100);
        $when = $kind[$r['due_kind']] ?? $r['due_kind'];
        if ($r['due_kind'] === 'progress') $when .= ' ' . (int)$r['due_pct'] . '%';
        fputcsv($out, [$r['project'], pm_t((string)$r['stage']), $r['supplier'], $r['title'], $g / 100, $n / 100, ($g - $n) / 100, $when, $r['due_date'],
            pm_payment_label($r), $paid / 100, $r['status'] === 'cancelled' ? 0 : max(0, $g - $paid) / 100], ',', '"', '');
    }
    rewind($out);
    $csv = stream_get_contents($out);
    pm_log('info', 'api', 'api.finance-export', ['data' => ['rows' => count($rows)]]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="finance-' . pm_today() . '.csv"');
    header('Cache-Control: no-store');
    echo $csv;
    exit;
}
