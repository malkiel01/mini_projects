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
function pm_payment_due(array $p, ?array $stage, string $today): bool
{
    $done = $stage && in_array($stage['status'], ['done', 'paid'], true);
    return match ($p['due_kind'] ?? 'date') {
        'stage_done' => $done,
        'progress'   => $done || ($stage && (int)$stage['progress'] >= (int)($p['due_pct'] ?? 101)),
        default      => $p['due_date'] !== '' && $p['due_date'] <= $today,
    };
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
function pm_finance_of(int $projectId, bool $portal = false, int $overpay = PM_OVERPAY_MARGIN): array
{
    $db = pm_db();
    $pr = $db->prepare('SELECT p.*, u.display_name AS owner_name FROM projects p JOIN users u ON u.id = p.owner_id WHERE p.id = ?');
    $pr->execute([$projectId]);
    $p = $pr->fetch() ?: throw new PmError('הפרויקט לא נמצא', 404);
    $today = pm_today();
    $vat = pm_vat_rate();

    $st = $db->prepare('SELECT * FROM stages WHERE project_id = ? ORDER BY sort, id');
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
        $cid = (int)$x['supplier_id'];
        $row($sid, $cid);
        $r = $rateOf($cid, $x['vat_rate']);
        $rows[$sid][$cid]['acc']['committed'] = [(int)$x['amount'], pm_net((int)$x['amount'], $r)]; // האחרונה גוברת
        $rows[$sid][$cid]['quote_id'] = (int)$x['id'];
        $rows[$sid][$cid]['rate'] = $r;
    }

    // מתוכנן / הגיע מועד / שולם — מאבני הדרך
    $pay = $db->prepare("SELECT * FROM payments WHERE project_id = ? AND status != 'cancelled'");
    $pay->execute([$projectId]);
    $payments = $pay->fetchAll();
    $proofs = pm_payment_proofs(array_map('intval', array_column($payments, 'id')));
    $next = null;
    foreach ($payments as $x) {
        $sid = (int)$x['stage_id'];
        $cid = (int)$x['supplier_id'];
        $row($sid, $cid);
        $r = $rateOf($cid, $x['vat_rate']);
        $rows[$sid][$cid]['rate'] ??= $r;
        $amount = (int)$x['amount'];
        $received = 0;
        foreach ($proofs[(int)$x['id']] ?? [] as $pf) if ($pf['status'] === 'received') $received += (int)round($pf['received_amount'] * 100);
        $a = &$rows[$sid][$cid]['acc'];
        pm_acc($a, 'scheduled', $amount, $r);
        pm_acc($a, 'paid', $received, $r);
        if (pm_payment_due($x, $stages[$sid] ?? null, $today)) pm_acc($a, 'due', $amount, $r);
        unset($a);
        $rows[$sid][$cid]['milestones']++;
        // התשלום הבא — לפורטל: הקרוב שעוד לא שולם במלואו
        if ($received < $amount && !in_array($x['status'], ['received'], true)) {
            $key = ($x['due_kind'] === 'date' && $x['due_date'] !== '' ? $x['due_date'] : '9999') . sprintf('%08d', $x['id']);
            if (!$next || $key < $next['key']) {
                $next = ['key' => $key, 'title' => $x['title'], 'supplier' => $sup[$cid]['name'] ?? '', 'amount' => pm_m($amount - $received, pm_net($amount - $received, $r)),
                    'due_kind' => $x['due_kind'], 'due_date' => $x['due_date'], 'due_pct' => $x['due_pct'] !== null ? (int)$x['due_pct'] : null,
                    'stage' => isset($stages[$sid]) ? $stages[$sid]['name'] : '', 'payable' => pm_payment_payable($x)];
            }
        }
    }

    // שלבים
    $T = [];
    $outStages = [];
    $forecast = [0, 0];
    $suppliersCommitted = [0, 0];
    foreach ($stages + [0 => null] as $sid => $s) {
        if (!$s && empty($rows[0])) continue;
        $S = [];
        $outRows = [];
        foreach ($rows[$sid] ?? [] as $cid => $r) {
            $acc = $r['acc'];
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
            pm_acc($S, 'balance', $balance, $rate);
            pm_acc($S, 'gap', $gap, $rate);
            if ($committed) { $suppliersCommitted[0] += $committed; $suppliersCommitted[1] += pm_net($committed, $rate); }
            $outRows[] = [
                'supplier_id' => $cid, 'name' => $r['name'], 'quote_id' => $r['quote_id'], 'vat_rate' => $rate, 'milestones' => $r['milestones'],
                'committed' => pm_m($committed, pm_net($committed, $rate)), 'scheduled' => pm_m($scheduled, pm_net($scheduled, $rate)),
                'due' => pm_m($due, pm_net($due, $rate)), 'paid' => pm_m($paid, pm_net($paid, $rate)),
                'balance' => pm_m($balance, pm_net($balance, $rate)), 'gap' => pm_m($gap, pm_net($gap, $rate)),
                'paid_pct' => $paidPct, 'progress' => $progress, 'flags' => $portal ? array_values(array_intersect($flags, ['overpaid'])) : $flags,
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
            'stage_id' => $s ? (int)$s['id'] : null, 'name' => $s['name'] ?? '', 'status' => $s['status'] ?? '',
            'progress' => $s ? pm_stage_progress($s) : 0, 'budget' => pm_m($budgetStage, pm_net($budgetStage, $vat)),
            'over_budget' => $budgetStage > 0 && $committedStage > $budgetStage,
            'rows' => $outRows,
        ] + pm_acc_out($S, ['committed', 'scheduled', 'due', 'paid', 'balance', 'gap']);
    }

    // שכר הטרחה של המנהל — גלוי ללקוח כחלק מההוצאות
    $fee = null;
    if ($p['fee_type'] === 'percent' || $p['fee_type'] === 'fixed') {
        $g = $p['fee_type'] === 'percent' ? (int)round($suppliersCommitted[0] * (float)$p['fee_value'] / 100) : (int)round((float)$p['fee_value'] * 100);
        $n = pm_net($g, $vat); // שכר הטרחה — שירות של המנהל, במע"מ הרגיל
        $fee = ['type' => $p['fee_type'], 'value' => (float)$p['fee_value'], 'name' => $p['owner_name'], 'amount' => pm_m($g, $n)];
        foreach (['committed', 'balance'] as $k) { $T[$k][0] = ($T[$k][0] ?? 0) + $g; $T[$k][1] = ($T[$k][1] ?? 0) + $n; }
        $forecast[0] += $g;
        $forecast[1] += $n;
    }

    $budget = (int)$p['budget'] * 100;
    $initial = (int)$p['budget_initial'] * 100;
    $committedAll = $T['committed'][0] ?? 0;
    // "תקציב היום": התקציב שהמנהל עדכן — או ההתחייבויות, כשעברו אותו
    $todayBudget = max($budget, $committedAll);
    $retention = (int)round(($suppliersCommitted[0]) * (float)$p['retention_pct'] / 100);
    $totals = pm_acc_out($T, ['committed', 'scheduled', 'due', 'paid', 'balance', 'gap']) + [
        'budget' => pm_m($budget, pm_net($budget, $vat)),
        'budget_initial' => pm_m($initial, pm_net($initial, $vat)),
        'budget_today' => pm_m($todayBudget, pm_net($todayBudget, $vat)),
        'budget_growth' => pm_m($todayBudget - $initial, pm_net($todayBudget - $initial, $vat)),
        'forecast' => pm_m($forecast[0], $forecast[1]),
        'retention' => pm_m($retention, pm_net($retention, $vat)),
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
    return ['settings' => pm_finance_settings($p), 'totals' => $totals, 'stages' => $outStages, 'fee' => $fee];
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
        } elseif ($mgr && $p['due_kind'] !== 'date' && $p['status'] === 'planned' && pm_payment_due($p, $stages[(int)$p['stage_id']] ?? null, $today)) {
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
