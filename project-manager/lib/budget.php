<?php
/**
 * הצעת תקציב לפי שלבים (PM-026). README: "הצעת תקציב".
 *
 * לכל שלב — סכום משוער (stages.budget, באגורות); ועוד שכר הטרחה ו"בלתי צפוי" (אחוז מהשלבים). הסה"כ — תקציב הפרויקט.
 * טיוטה — עורכים חופשי. "שליחה לאישור" — גרסה (budget_versions) שהלקוח / העו"ד מאשרים בקישור (או שהמנהל רושם
 * אישור). אחרי האישור הראשון ההצעה נעולה: הסכומים שאושרו — "ראשוני" (stages.budget_initial), וכל שינוי — גרסה
 * נוספת שצריכה אישור, ונרשמת ביומן השינויים. פרויקט בלי סכומים בשלבים — כמו פעם: מספר אחד שעורכים בהגדרות.
 *
 * מתחם: שלב של דירה — שלה; שלב משותף — לפי אחוזי החלוקה בהגדרות הפרויקט (projects.unit_split; ריק — שווה בשווה).
 */

declare(strict_types=1);

require_once __DIR__ . '/finance.php';

// i18n-ok-start
const PM_BUDGET_APPROVERS = ['client' => 'הלקוח', 'lawyer' => 'עו"ד', 'both' => 'הלקוח ואז עו"ד'];
const PM_BUDGET_STATUSES = ['pending' => 'ממתינה לאישור', 'client_approved' => 'הלקוח אישר — ממתינה לעו"ד', 'approved' => 'אושרה',
    'rejected' => 'נדחתה', 'cancelled' => 'בוטלה'];
// i18n-ok-end

/** חלוקת השלבים המשותפים בין הדירות: [unit_id => שבר]. מההגדרות; ריק או לא תקין — שווה בשווה. */
function pm_unit_split(array $project, array $units): array
{
    if (!$units) return [];
    $raw = json_decode((string)($project['unit_split'] ?? ''), true);
    $w = [];
    if (is_array($raw)) foreach ($units as $u) $w[$u['id']] = max(0.0, (float)($raw[(string)$u['id']] ?? 0));
    $sum = array_sum($w);
    if ($sum <= 0) return array_fill_keys(array_column($units, 'id'), 1 / count($units));
    return array_map(fn($x) => $x / $sum, $w);
}

/** פורקה לשלבים: יש סכום לשלב, או שכבר נשלחה גרסה. אז תקציב הפרויקט מחושב — לא נערך כמספר אחד. */
function pm_budget_broken(int $projectId): bool
{
    $st = pm_db()->prepare("SELECT (SELECT COUNT(*) FROM stages WHERE project_id = ? AND budget > 0) + (SELECT COUNT(*) FROM budget_versions WHERE project_id = ? AND status != 'cancelled')");
    $st->execute([$projectId, $projectId]);
    return (int)$st->fetchColumn() > 0;
}

/** נעולה: יש גרסה ראשונית שאושרה. */
function pm_budget_locked(int $projectId): bool
{
    $st = pm_db()->prepare("SELECT 1 FROM budget_versions WHERE project_id = ? AND kind = 'initial' AND status = 'approved'");
    $st->execute([$projectId]);
    return (bool)$st->fetchColumn();
}

/** שכר טרחה ו"בלתי צפוי" על סכום השלבים (באגורות). */
function pm_budget_extras(array $p, int $stages, ?float $contPct = null): array
{
    $fee = $p['fee_type'] === 'percent' ? (int)round($stages * (float)$p['fee_value'] / 100) : ($p['fee_type'] === 'fixed' ? (int)round((float)$p['fee_value'] * 100) : 0);
    $cont = (int)round($stages * ($contPct ?? (float)$p['contingency_pct']) / 100);
    return [$fee, $cont];
}

/**
 * אחרי כל שינוי בסכומים: תקציב הפרויקט (₪) = השלבים + שכר טרחה + בלתי צפוי; בטיוטה — גם הראשוני;
 * ותקציב כל דירה — השלבים שלה + חלקה במשותפים (ושכר הטרחה והבלתי צפוי באותו יחס).
 */
function pm_budget_sync(int $projectId): void
{
    if (!pm_budget_broken($projectId)) return;
    $db = pm_db();
    $p = $db->query('SELECT * FROM projects WHERE id = ' . $projectId)->fetch();
    $st = $db->prepare('SELECT id, unit_id, budget FROM stages WHERE project_id = ?');
    $st->execute([$projectId]);
    $stages = $st->fetchAll();
    $sum = array_sum(array_map(fn($s) => (int)$s['budget'], $stages));
    [$fee, $cont] = pm_budget_extras($p, $sum);
    $locked = pm_budget_locked($projectId);
    // נעולה — התקציב הוא מה שאושר אחרון (שינוי בשכר הטרחה לא משנה אותו בלי אישור)
    $approved = $locked ? (int)$db->query("SELECT total_to FROM budget_versions WHERE project_id = $projectId AND status = 'approved' ORDER BY id DESC LIMIT 1")->fetchColumn() : 0;
    $all = $locked ? $approved : $sum + $fee + $cont;
    $total = (int)round($all / 100);
    $db->prepare('UPDATE projects SET budget = ?' . ($locked ? '' : ', budget_initial = ?') . ', updated_at = ? WHERE id = ?')
       ->execute($locked ? [$total, pm_now(), $projectId] : [$total, $total, pm_now(), $projectId]);
    $units = pm_units_of($projectId);
    if (!$units) return;
    $split = pm_unit_split($p, $units);
    $by = array_fill_keys(array_column($units, 'id'), 0.0);
    foreach ($stages as $s) {
        if ($s['unit_id'] !== null && isset($by[(int)$s['unit_id']])) $by[(int)$s['unit_id']] += (int)$s['budget'];
        else foreach ($split as $u => $k) $by[$u] += (int)$s['budget'] * $k;
    }
    $up = $db->prepare('UPDATE units SET budget = ? WHERE id = ?');
    foreach ($by as $u => $g) $up->execute([$sum > 0 ? (int)round($g * $all / $sum / 100) : 0, $u]);
}

/** המסך: השורות (שלב — ראשוני · היום · סוכם · חריגה), התוספות, הסה"כ, הגרסה שממתינה, היומן והדירות. */
function pm_budget_of(int $projectId, bool $portal = false, ?array $scope = null): array
{
    $db = pm_db();
    $p = $db->query('SELECT * FROM projects WHERE id = ' . $projectId)->fetch() ?: throw new PmError('הפרויקט לא נמצא', 404);
    $fin = pm_finance_of($projectId, $portal, PM_OVERPAY_MARGIN, $scope);
    $committed = [];
    foreach ($fin['stages'] as $s) if ($s['stage_id']) $committed[$s['stage_id']] = (int)round($s['committed']['g'] * 100);
    $st = $db->prepare('SELECT s.id, s.name, s.unit_id, s.budget, s.budget_initial, u.name AS unit_name FROM stages s LEFT JOIN units u ON u.id = s.unit_id
                         WHERE s.project_id = ? ORDER BY s.sort, s.id');
    $st->execute([$projectId]);
    $lines = [];
    // לקוח של דירה: שלב משותף — החלק של הדירות שלו לפי החלוקה בהגדרות
    $units = pm_units_of($projectId);
    $split = pm_unit_split($p, $units);
    $mine = $scope === null ? 1.0 : array_sum(array_map(fn($u) => $split[$u] ?? 0, $scope['units']));
    foreach ($st->fetchAll() as $s) {
        if ($scope !== null && !in_array((int)$s['id'], $scope['stages'], true)) continue;
        $k = $scope !== null && $s['unit_id'] === null ? $mine : 1.0;
        $c = $committed[(int)$s['id']] ?? 0;
        $today = (int)round((int)$s['budget'] * $k);
        $lines[] = ['stage_id' => (int)$s['id'], 'name' => $s['name'], 'unit_id' => $s['unit_id'] !== null ? (int)$s['unit_id'] : null,
            'unit_name' => $s['unit_name'], 'initial' => (int)round((int)$s['budget_initial'] * $k) / 100, 'today' => $today / 100, 'committed' => $c / 100,
            'share' => $k < 1 ? round($k * 100, 1) : null,
            // חריגה: מה שסוכם בפועל מעבר לתקציב השלב (רק כשיש תקציב לשלב)
            'over' => $today > 0 && $c > $today ? ($c - $today) / 100 : 0];
    }
    $sum = (int)round(array_sum(array_column($lines, 'today')) * 100);
    [$fee, $cont] = pm_budget_extras($p, $sum);
    $locked = pm_budget_locked($projectId);
    $vs = $db->prepare('SELECT * FROM budget_versions WHERE project_id = ? ORDER BY id DESC');
    $vs->execute([$projectId]);
    $versions = array_map('pm_budget_version_out', $vs->fetchAll());
    $pending = null;
    foreach ($versions as $v) if (in_array($v['status'], ['pending', 'client_approved'], true)) $pending = $v;
    return [
        'state' => $pending ? 'pending' : ($locked ? 'locked' : 'draft'),
        'broken' => pm_budget_broken($projectId),
        'approved_once' => $locked, // יש "ראשוני" — הוצעה ואושרה פעם (גם כששינוי ממתין)
        'approver' => $p['budget_approver'] ?: 'client',
        'approver_name' => pm_t(PM_BUDGET_APPROVERS[$p['budget_approver'] ?: 'client'] ?? 'הלקוח'),
        'contingency_pct' => (float)$p['contingency_pct'],
        'fee_type' => $p['fee_type'], 'fee_value' => (float)$p['fee_value'],
        'lines' => $lines,
        'stages_total' => $sum / 100, 'fee' => $fee / 100, 'contingency' => $cont / 100, 'total' => ($sum + $fee + $cont) / 100,
        'initial_total' => (int)$p['budget_initial'], 'committed_total' => $fin['totals']['committed']['g'],
        'over_total' => (float)array_sum(array_column($lines, 'over')),
        'pending' => $pending, 'versions' => $versions,
        'units' => array_values(array_map(fn($u) => ['id' => $u['id'], 'name' => $u['name'], 'pct' => round(($split[$u['id']] ?? 0) * 100, 2), 'budget' => $u['budget']],
            $scope === null ? $units : array_filter($units, fn($u) => in_array($u['id'], $scope['units'], true)))),
    ];
}

function pm_budget_version_out(array $v): array
{
    $d = pm_db()->prepare('SELECT role, decision, method, by_name, note, created_at FROM budget_decisions WHERE version_id = ? ORDER BY id');
    $d->execute([$v['id']]);
    return ['id' => (int)$v['id'], 'seq' => (int)$v['seq'], 'kind' => $v['kind'], 'status' => $v['status'],
        'status_label' => pm_t(PM_BUDGET_STATUSES[$v['status']] ?? $v['status']),
        'lines' => json_decode($v['lines_json'], true) ?: [],
        'total_from' => (int)$v['total_from'] / 100, 'total_to' => (int)$v['total_to'] / 100,
        'contingency_pct' => (float)$v['contingency_pct'], 'note' => $v['note'], 'created_at' => $v['created_at'], 'decided_at' => $v['decided_at'],
        'decisions' => $d->fetchAll()];
}

function pm_budget(array $user, int $projectId): array
{
    pm_project_row($user, $projectId);
    return pm_budget_of($projectId);
}

/** טיוטה: סכום לכל שלב (₪) ואחוז "בלתי צפוי". {project_id, lines: [{stage_id, amount}], contingency_pct} */
function pm_budget_draft_save(array $user, array $in): array
{
    $projectId = (int)($in['project_id'] ?? 0);
    pm_project_row($user, $projectId);
    $b = pm_budget_of($projectId);
    if ($b['state'] !== 'draft') throw new PmError($b['state'] === 'pending' ? 'ההצעה ממתינה לאישור — אפשר לבטל את השליחה ולערוך' : 'הצעת התקציב אושרה ונעולה — שינוי דרך "שינוי תקציב"', 422);
    $db = pm_db();
    $db->beginTransaction();
    try {
        $up = $db->prepare('UPDATE stages SET budget = ?, updated_at = ? WHERE id = ? AND project_id = ?');
        foreach (pm_budget_lines_in($in) as $sid => $g) $up->execute([$g, pm_now(), $sid, $projectId]);
        if (array_key_exists('contingency_pct', $in)) {
            $c = $in['contingency_pct'];
            if ($c !== '' && $c !== null && (!is_numeric($c) || $c < 0 || $c > 50)) throw new PmError('{field}: מספר לא תקין', 422, ['field' => pm_t('בלתי צפוי')]);
            $db->prepare('UPDATE projects SET contingency_pct = ? WHERE id = ?')->execute([(float)($c ?: 0), $projectId]);
        }
        pm_budget_sync($projectId);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return pm_budget_of($projectId);
}

/** [stage_id => אגורות] מהקלט; סכום שלילי או לא מספר — שגיאה. */
function pm_budget_lines_in(array $in): array
{
    $out = [];
    foreach ((array)($in['lines'] ?? []) as $l) {
        $sid = (int)($l['stage_id'] ?? 0);
        $a = $l['amount'] ?? '';
        if (!$sid) continue;
        if ($a !== '' && $a !== null && (!is_numeric($a) || $a < 0 || $a > 1e9)) throw new PmError('{field}: מספר לא תקין', 422, ['field' => pm_t('סכום')]);
        $out[$sid] = (int)round((float)($a ?: 0) * 100);
    }
    return $out;
}

/**
 * "📤 שליחה לאישור". טיוטה — ההצעה הראשונה (kind=initial, הסכומים שבשלבים). נעולה — שינוי (kind=change): lines עם
 * הסכומים החדשים, והערה למה. גרסה אחת ממתינה בכל פעם.
 */
function pm_budget_send(array $user, array $in): array
{
    $projectId = (int)($in['project_id'] ?? 0);
    $p = pm_project_row($user, $projectId);
    $b = pm_budget_of($projectId);
    if ($b['state'] === 'pending') throw new PmError('כבר יש הצעה שממתינה לאישור', 422);
    $approver = $b['approver'];
    if (in_array($approver, ['client', 'both'], true) && !$p['client_id']) throw new PmError('אין לקוח בפרויקט — מי יאשר את התקציב?', 422);
    if ($approver === 'lawyer' && !$p['lawyer_id']) throw new PmError('אין עו"ד בפרויקט — שנה בהגדרות הכספים מי מאשר', 422);
    $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 1000);
    $kind = $b['state'] === 'locked' ? 'change' : 'initial';
    $want = $kind === 'change' ? pm_budget_lines_in($in) : [];
    $contTo = $kind === 'change' && ($in['contingency_pct'] ?? '') !== '' ? (float)$in['contingency_pct'] : (float)$p['contingency_pct'];
    if ($contTo < 0 || $contTo > 50) throw new PmError('{field}: מספר לא תקין', 422, ['field' => pm_t('בלתי צפוי')]);
    $lines = [];
    $sumFrom = $sumTo = 0;
    foreach ($b['lines'] as $l) {
        $from = (int)round($l['today'] * 100);
        $to = $want[$l['stage_id']] ?? $from;
        $sumFrom += $from;
        $sumTo += $to;
        if ($kind === 'initial' || $to !== $from) $lines[] = ['stage_id' => $l['stage_id'], 'name' => $l['name'], 'unit_name' => $l['unit_name'], 'from' => $from / 100, 'to' => $to / 100];
    }
    if ($kind === 'initial' && $sumTo <= 0) throw new PmError('רשום סכום לפחות לשלב אחד', 422);
    if ($kind === 'change' && !$lines && abs($contTo - (float)$p['contingency_pct']) < 0.001) throw new PmError('לא שונה אף סכום', 422);
    if ($kind === 'change' && $note === '') throw new PmError('שינוי בתקציב — כתוב למה', 422);
    [$feeF, $contF] = pm_budget_extras($p, $sumFrom);
    [$feeT, $contT] = pm_budget_extras($p, $sumTo, $contTo);
    $db = pm_db();
    $seq = (int)$db->query("SELECT COALESCE(MAX(seq), 0) + 1 FROM budget_versions WHERE project_id = $projectId")->fetchColumn();
    $now = pm_now();
    $db->prepare('INSERT INTO budget_versions (project_id, seq, kind, status, lines_json, total_from, total_to, contingency_pct, note, created_by, created_at)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([$projectId, $seq, $kind, 'pending', json_encode($lines, JSON_UNESCAPED_UNICODE), $kind === 'initial' ? 0 : $sumFrom + $feeF + $contF,
                  $sumTo + $feeT + $contT, $contTo, $note, $user['id'], $now]);
    $id = (int)$db->lastInsertId();
    pm_event($user, $projectId, 'finance', $kind === 'initial' ? '📐 הצעת התקציב נשלחה לאישור: {amount}' : '📐 שינוי בתקציב נשלח לאישור: {from} ← {to}',
        ['budget_version' => $id], ['amount' => pm_money($sumTo + $feeT + $contT), 'from' => pm_money($sumFrom + $feeF + $contF), 'to' => pm_money($sumTo + $feeT + $contT)]);
    pm_budget_notify($user, $projectId, $id, $approver === 'lawyer' ? (int)$p['lawyer_id'] : (int)$p['client_id']);
    return pm_budget_of($projectId);
}

/** מייל למאשר, עם הקישור האישי. */
function pm_budget_notify(array $actor, int $projectId, int $versionId, int $contactId): void
{
    if (!$contactId) return;
    $v = pm_db()->query('SELECT * FROM budget_versions WHERE id = ' . $versionId)->fetch();
    // i18n-none-start — שמות המשתנים בנוסח
    pm_notify($projectId, $contactId, 'budget_request', [
        'פרטים' => pm_t($v['kind'] === 'initial' ? 'הצעת התקציב לפי שלבים' : 'שינוי בתקציב: {note}', ['note' => $v['note']], pm_contact_lang($contactId)),
        'סכום' => pm_money((int)$v['total_to']), 'קישור' => pm_notify_link($projectId, $contactId),
    ], $actor);
    // i18n-none-end
}

/**
 * החלטה על גרסה: מהקישור (click / signature) או שהמנהל רושם (manual). "both" — קודם הלקוח, אחר כך העו"ד.
 * אישור אחרון — מחיל: initial — הסכומים הם הראשוני והיום; change — הסכומים החדשים הם היום.
 */
function pm_budget_decide(array $user, int $id, string $role, string $decision, string $note, string $method = 'manual', string $byName = '',
                          ?array $actor = null, array $portal = []): array
{
    $actor ??= $user;
    $db = pm_db();
    $v = $db->query('SELECT * FROM budget_versions WHERE id = ' . $id)->fetch() ?: throw new PmError('הגרסה לא נמצאה', 404);
    $p = pm_project_row($user, (int)$v['project_id']);
    $approver = $p['budget_approver'] ?: 'client';
    if (!in_array($role, ['client', 'lawyer'], true) || !in_array($decision, ['approved', 'rejected'], true)) throw new PmError('החלטה לא מוכרת', 422);
    $need = $v['status'] === 'pending' ? ($approver === 'lawyer' ? 'lawyer' : 'client') : ($v['status'] === 'client_approved' ? 'lawyer' : '');
    if ($need === '') throw new PmError('הגרסה כבר {status}', 422, ['status' => pm_t(PM_BUDGET_STATUSES[$v['status']] ?? $v['status'])]);
    if ($role !== $need) throw new PmError($need === 'lawyer' ? 'העו"ד מאשר עכשיו' : 'הלקוח מאשר קודם', 422);
    $note = mb_substr(trim($note), 0, 1000);
    if ($byName === '') $byName = pm_contact_name((int)($role === 'client' ? $p['client_id'] : $p['lawyer_id'])) ?: pm_t($role === 'client' ? 'הלקוח' : 'עו"ד');
    $status = $decision === 'rejected' ? 'rejected' : ($role === 'client' && $approver === 'both' ? 'client_approved' : 'approved');
    $now = pm_now();
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO budget_decisions (version_id, role, decision, method, by_name, note, signature, portal_id, recorded_by, ip, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$id, $role, $decision, $method, $byName, $note, (string)($portal['signature'] ?? ''), $portal['portal_id'] ?? null,
                      $method === 'manual' ? $user['id'] : null, (string)($_SERVER['REMOTE_ADDR'] ?? ''), $now]);
        $db->prepare('UPDATE budget_versions SET status = ?, decided_at = ? WHERE id = ?')->execute([$status, in_array($status, ['approved', 'rejected'], true) ? $now : null, $id]);
        if ($status === 'approved') pm_budget_apply($v);
        // i18n-ok-start
        $text = $decision === 'approved' ? ($status === 'approved' ? '📐 {who} אישר את {what}: {amount}{note}{how}' : '📐 {who} אישר את {what} — ממתין לעו"ד{note}{how}')
            : '📐 {who} דחה את {what}{note}{how}';
        $how = ['manual' => ' (נרשם על ידי מנהל הפרויקט)', 'signature' => ' — בחתימה בקישור', 'click' => ' — בקישור'][$method] ?? '';
        $what = $v['kind'] === 'initial' ? 'הצעת התקציב' : 'השינוי בתקציב';
        // i18n-ok-end
        pm_event($actor, (int)$v['project_id'], 'finance', $text, ['budget_version' => $id, 'role' => $role, 'decision' => $decision],
            ['who' => $byName, 'what' => $what, 'amount' => pm_money((int)$v['total_to']), 'note' => $note !== '' ? ": $note" : '', 'how' => $how]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    if ($status === 'client_approved' && $p['lawyer_id']) pm_budget_notify($user, (int)$v['project_id'], $id, (int)$p['lawyer_id']);
    return pm_budget_of((int)$v['project_id']);
}

/** אישור אחרון: הסכומים של הגרסה נכנסים לשלבים; הראשונה — גם "ראשוני". */
function pm_budget_apply(array $v): void
{
    $db = pm_db();
    $pid = (int)$v['project_id'];
    $now = pm_now();
    $up = $db->prepare('UPDATE stages SET budget = ?' . ($v['kind'] === 'initial' ? ', budget_initial = ?' : '') . ', updated_at = ? WHERE id = ? AND project_id = ?');
    foreach (json_decode($v['lines_json'], true) ?: [] as $l) {
        $g = (int)round($l['to'] * 100);
        $up->execute($v['kind'] === 'initial' ? [$g, $g, $now, $l['stage_id'], $pid] : [$g, $now, $l['stage_id'], $pid]);
    }
    $db->prepare('UPDATE projects SET contingency_pct = ? WHERE id = ?')->execute([(float)$v['contingency_pct'], $pid]);
    if ($v['kind'] === 'initial') $db->prepare('UPDATE projects SET budget_initial = ? WHERE id = ?')->execute([(int)round((int)$v['total_to'] / 100), $pid]);
    pm_budget_sync($pid);
}

/** ביטול שליחה שממתינה — חוזרים לערוך. */
function pm_budget_cancel(array $user, int $id): array
{
    $db = pm_db();
    $v = $db->query('SELECT * FROM budget_versions WHERE id = ' . $id)->fetch() ?: throw new PmError('הגרסה לא נמצאה', 404);
    pm_project_row($user, (int)$v['project_id']);
    if (!in_array($v['status'], ['pending', 'client_approved'], true)) throw new PmError('הגרסה כבר {status}', 422, ['status' => pm_t(PM_BUDGET_STATUSES[$v['status']] ?? $v['status'])]);
    $db->prepare("UPDATE budget_versions SET status = 'cancelled', decided_at = ? WHERE id = ?")->execute([pm_now(), $id]);
    pm_event($user, (int)$v['project_id'], 'finance', '📐 השליחה לאישור בוטלה', ['budget_version' => $id]);
    return pm_budget_of((int)$v['project_id']);
}

/** תקציב לשלב — בחלון השלב: בטיוטה עורכים; נעולה — רק דרך "שינוי תקציב". */
function pm_budget_stage_guard(int $projectId, int $old, int $new): void
{
    if ($old !== $new && pm_budget_locked($projectId)) throw new PmError('הצעת התקציב אושרה ונעולה — שינוי בתקציב השלב דרך "📐 תקציב" ← "שינוי תקציב"', 422);
}
