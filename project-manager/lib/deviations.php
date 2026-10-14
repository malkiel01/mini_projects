<?php
/**
 * חריגה מהחוזה בהסכמת כל הצדדים (PM-027). README: "חריגה מהחוזה".
 *
 * כבוי כברירת מחדל — מדליקים בהגדרות הכספים (projects.deviations_on). חריגה = כלל שסותר את החוזה: ויתור על
 * עיכבון, אחוז עיכבון אחר, תנאי תשלום, או אחר. היא חלה רק כשכל הצדדים הרלוונטיים אישרו: הלקוח, העו"ד (אם יש
 * בפרויקט), וכל ספק שהחריגה נוגעת לו — כל אחד בקישור האישי שלו, או שהמנהל רושם. דחייה אחת — נדחתה.
 * ביטול חריגה שאושרה — שוב בהסכמת כולם; אז חוזרים למה שהיה לפי החוזה.
 *
 * עיכבון: החריגה לא משנה את ההגדרה של הפרויקט (זה החוזה) — pm_deviation_retention_pct גובר עליה באבני הדרך.
 */

declare(strict_types=1);

require_once __DIR__ . '/finance.php';

// i18n-ok-start
const PM_DEVIATION_TYPES = ['retention_waive' => 'ויתור על העיכבון', 'retention_pct' => 'אחוז עיכבון אחר', 'payment_terms' => 'תנאי תשלום', 'other' => 'אחר'];
const PM_DEVIATION_STATUSES = ['pending' => 'ממתינה להסכמת כולם', 'approved' => 'בתוקף — כולם הסכימו', 'rejected' => 'נדחתה',
    'cancelled' => 'בוטלה', 'revoke_pending' => 'ביטול — ממתין להסכמת כולם', 'revoked' => 'בוטלה בהסכמת כולם'];
const PM_DEVIATION_PARTIES = ['client' => 'הלקוח', 'lawyer' => 'עו"ד', 'supplier' => 'הספק'];
// i18n-ok-end

function pm_deviations_on(int $projectId): bool
{
    $st = pm_db()->prepare('SELECT deviations_on FROM projects WHERE id = ?');
    $st->execute([$projectId]);
    return (bool)$st->fetchColumn();
}

/** מי צריך להסכים: הלקוח, העו"ד (אם יש), וכל ספק שנוגע. [['party', 'contact_id', 'name'], …] */
function pm_deviation_parties(array $p, array $d): array
{
    $out = [];
    if ($p['client_id']) $out[] = ['party' => 'client', 'contact_id' => (int)$p['client_id']];
    if ($p['lawyer_id']) $out[] = ['party' => 'lawyer', 'contact_id' => (int)$p['lawyer_id']];
    if ($d['supplier_id']) $sup = [(int)$d['supplier_id']];
    else {
        // כל הפרויקט — כל ספק שיש לו אבן דרך או הצעה מאושרת
        $st = pm_db()->prepare("SELECT DISTINCT supplier_id FROM payments WHERE project_id = ? AND status != 'cancelled' AND kind = 'milestone'
                                UNION SELECT supplier_id FROM quotes WHERE project_id = ? AND status = 'approved'");
        $st->execute([$p['id'], $p['id']]);
        $sup = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
    foreach ($sup as $s) $out[] = ['party' => 'supplier', 'contact_id' => $s];
    return array_map(fn($x) => $x + ['name' => pm_contact_name($x['contact_id'])], $out);
}

function pm_deviation_row(array $user, int $id): array
{
    $st = pm_db()->prepare('SELECT * FROM deviations WHERE id = ?');
    $st->execute([$id]);
    $d = $st->fetch() ?: throw new PmError('החריגה לא נמצאה', 404);
    pm_project_row($user, (int)$d['project_id']);
    return $d;
}

/** החריגה לתצוגה: מה, על מי, מי כבר הסכים ומי עוד לא (בשלב הנוכחי: אישור או ביטול). */
function pm_deviation_out(array $d, ?array $p = null): array
{
    $p ??= pm_db()->query('SELECT * FROM projects WHERE id = ' . (int)$d['project_id'])->fetch();
    $phase = $d['status'] === 'revoke_pending' || $d['status'] === 'revoked' ? 'revoke' : 'approve';
    $parties = json_decode((string)$d['parties_json'], true) ?: [];
    $st = pm_db()->prepare('SELECT * FROM deviation_approvals WHERE deviation_id = ? ORDER BY id');
    $st->execute([$d['id']]);
    $all = $st->fetchAll();
    $now = array_values(array_filter($all, fn($a) => $a['phase'] === $phase));
    $parties = array_map(function ($x) use ($now) {
        $a = null;
        foreach ($now as $y) if ($y['party'] === $x['party'] && (int)$y['contact_id'] === $x['contact_id']) $a = $y;
        return $x + ['label' => pm_t(PM_DEVIATION_PARTIES[$x['party']]), 'decision' => $a['decision'] ?? null, 'method' => $a['method'] ?? null,
            'by_name' => $a['by_name'] ?? null, 'at' => $a['created_at'] ?? null, 'note' => $a['note'] ?? ''];
    }, $parties);
    $stage = $d['payment_id'] ? pm_db()->query('SELECT p.title, s.name AS stage FROM payments p LEFT JOIN stages s ON s.id = p.stage_id WHERE p.id = ' . (int)$d['payment_id'])->fetch() : null;
    return ['id' => (int)$d['id'], 'type' => $d['type'], 'type_label' => pm_t(PM_DEVIATION_TYPES[$d['type']] ?? $d['type']),
        'status' => $d['status'], 'status_label' => pm_t(PM_DEVIATION_STATUSES[$d['status']] ?? $d['status']),
        'scope' => $d['payment_id'] ? 'milestone' : ($d['supplier_id'] ? 'supplier' : 'project'),
        'supplier_id' => $d['supplier_id'] !== null ? (int)$d['supplier_id'] : null, 'supplier' => $d['supplier_id'] ? pm_contact_name((int)$d['supplier_id']) : '',
        'payment_id' => $d['payment_id'] !== null ? (int)$d['payment_id'] : null, 'milestone' => $stage ? trim($stage['title'] . ($stage['stage'] ? ' · ' . pm_t($stage['stage']) : '')) : '',
        'value' => $d['value'] !== null ? (float)$d['value'] : null, 'contract_pct' => (float)$p['retention_pct'],
        'description' => $d['description'], 'created_at' => $d['created_at'], 'decided_at' => $d['decided_at'],
        'phase' => $phase, 'parties' => $parties,
        'history' => array_map(fn($a) => ['phase' => $a['phase'], 'party' => $a['party'], 'by_name' => $a['by_name'], 'decision' => $a['decision'],
            'method' => $a['method'], 'note' => $a['note'], 'at' => $a['created_at']], $all)];
}

function pm_deviations_of(int $projectId): array
{
    $p = pm_db()->query('SELECT * FROM projects WHERE id = ' . $projectId)->fetch();
    $st = pm_db()->prepare('SELECT * FROM deviations WHERE project_id = ? ORDER BY id DESC');
    $st->execute([$projectId]);
    return ['deviations_on' => (bool)$p['deviations_on'], 'deviations' => array_map(fn($d) => pm_deviation_out($d, $p), $st->fetchAll()),
        'types' => pm_t_map(PM_DEVIATION_TYPES)];
}

function pm_deviations(array $user, int $projectId): array
{
    pm_project_row($user, $projectId);
    return pm_deviations_of($projectId);
}

/** חריגה חדשה: {project_id, type, supplier_id?, payment_id?, value?, description}. נשלחת לכל הצדדים. */
function pm_deviation_save(array $user, array $in): array
{
    $projectId = (int)($in['project_id'] ?? 0);
    $p = pm_project_row($user, $projectId);
    if (!$p['deviations_on']) throw new PmError('חריגה מהחוזה כבויה בפרויקט — מדליקים בהגדרות הכספים', 422);
    $type = (string)($in['type'] ?? '');
    if (!isset(PM_DEVIATION_TYPES[$type])) throw new PmError('סוג חריגה לא מוכר', 422);
    $paymentId = (int)($in['payment_id'] ?? 0) ?: null;
    $supplierId = (int)($in['supplier_id'] ?? 0) ?: null;
    if ($paymentId) {
        $pay = pm_payment_row($user, $paymentId);
        if ((int)$pay['project_id'] !== $projectId || ($pay['kind'] ?? '') === 'retention') throw new PmError('אבן הדרך לא מתאימה', 422);
        $supplierId = (int)$pay['supplier_id'];
    }
    if ($supplierId) pm_owned_contact($supplierId, (int)$p['owner_id'], 'supplier', 'הספק'); // i18n-ok
    $value = null;
    if ($type === 'retention_pct') {
        $v = $in['value'] ?? '';
        if (!is_numeric($v) || $v < 0 || $v > 50) throw new PmError('{field}: מספר לא תקין', 422, ['field' => pm_t('אחוז עיכבון')]);
        $value = (float)$v;
    }
    $desc = mb_substr(trim((string)($in['description'] ?? '')), 0, 2000);
    if ($desc === '') throw new PmError('כתוב מה החריגה ולמה', 422);
    $d = ['project_id' => $projectId, 'supplier_id' => $supplierId, 'payment_id' => $paymentId];
    $parties = pm_deviation_parties($p + ['id' => $projectId], $d);
    if (count(array_filter($parties, fn($x) => $x['party'] !== 'supplier')) === 0) throw new PmError('אין לקוח בפרויקט — מי יסכים לחריגה?', 422);
    $db = pm_db();
    $db->prepare('INSERT INTO deviations (project_id, supplier_id, payment_id, type, value, description, status, parties_json, created_by, created_at)
                  VALUES (?,?,?,?,?,?,?,?,?,?)')
       ->execute([$projectId, $supplierId, $paymentId, $type, $value, $desc, 'pending',
                  json_encode(array_map(fn($x) => ['party' => $x['party'], 'contact_id' => $x['contact_id']], $parties)), $user['id'], pm_now()]);
    $id = (int)$db->lastInsertId();
    pm_event($user, $projectId, 'finance', '⚖️ בקשת חריגה מהחוזה: {type}{who} — ממתינה להסכמת כולם', ['deviation' => $id],
        ['type' => PM_DEVIATION_TYPES[$type], 'who' => $supplierId ? ' — ' . pm_contact_name($supplierId) : '']);
    foreach ($parties as $x) pm_deviation_notify($user, $projectId, $id, $x['contact_id']);
    return pm_deviations_of($projectId);
}

function pm_deviation_notify(array $actor, int $projectId, int $id, int $contactId): void
{
    $d = pm_db()->query('SELECT * FROM deviations WHERE id = ' . $id)->fetch();
    $lang = pm_contact_lang($contactId);
    // i18n-none-start — שמות המשתנים בנוסח
    pm_notify($projectId, $contactId, 'deviation_request', [
        'פרטים' => pm_t($d['status'] === 'revoke_pending' ? 'ביטול חריגה: {type}' : '{type}', ['type' => pm_t(PM_DEVIATION_TYPES[$d['type']], [], $lang)], $lang) . ' — ' . $d['description'],
        'קישור' => pm_notify_link($projectId, $contactId),
    ], $actor);
    // i18n-none-end
}

/**
 * הסכמה / סירוב של צד: מהקישור (click / signature) או שהמנהל רושם (manual). כשכולם הסכימו — החריגה חלה
 * (או, בשלב הביטול — מתבטלת). סירוב אחד — נדחתה (בביטול — נשארת בתוקף).
 */
function pm_deviation_decide(array $user, int $id, string $party, int $contactId, string $decision, string $note, string $method = 'manual',
                             string $byName = '', ?array $actor = null, array $portal = []): array
{
    $actor ??= $user;
    $d = pm_deviation_row($user, $id);
    if (!in_array($decision, ['approved', 'rejected'], true)) throw new PmError('החלטה לא מוכרת', 422);
    if (!in_array($d['status'], ['pending', 'revoke_pending'], true)) throw new PmError('החריגה כבר {status}', 422, ['status' => pm_t(PM_DEVIATION_STATUSES[$d['status']] ?? $d['status'])]);
    $phase = $d['status'] === 'revoke_pending' ? 'revoke' : 'approve';
    $parties = json_decode((string)$d['parties_json'], true) ?: [];
    $mine = array_filter($parties, fn($x) => $x['party'] === $party && (int)$x['contact_id'] === $contactId);
    if (!$mine) throw new PmError('הצד הזה לא צריך להסכים לחריגה', 422);
    $db = pm_db();
    $done = $db->prepare('SELECT 1 FROM deviation_approvals WHERE deviation_id = ? AND phase = ? AND party = ? AND contact_id = ?');
    $done->execute([$id, $phase, $party, $contactId]);
    if ($done->fetchColumn()) throw new PmError('כבר נרשמה החלטה של הצד הזה', 422);
    $note = mb_substr(trim($note), 0, 1000);
    if ($byName === '') $byName = pm_contact_name($contactId);
    $now = pm_now();
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO deviation_approvals (deviation_id, phase, party, contact_id, decision, method, by_name, note, signature, portal_id, recorded_by, ip, created_at)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$id, $phase, $party, $contactId, $decision, $method, $byName, $note, (string)($portal['signature'] ?? ''), $portal['portal_id'] ?? null,
                      $method === 'manual' ? $user['id'] : null, (string)($_SERVER['REMOTE_ADDR'] ?? ''), $now]);
        $cnt = $db->prepare("SELECT COUNT(*) FROM deviation_approvals WHERE deviation_id = ? AND phase = ? AND decision = 'approved'");
        $cnt->execute([$id, $phase]);
        $status = $d['status'];
        if ($decision === 'rejected') $status = $phase === 'approve' ? 'rejected' : 'approved';
        elseif ((int)$cnt->fetchColumn() >= count($parties)) $status = $phase === 'approve' ? 'approved' : 'revoked';
        if ($status !== $d['status']) {
            $db->prepare('UPDATE deviations SET status = ?, decided_at = ? WHERE id = ?')->execute([$status, $now, $id]);
            if ($phase === 'approve' && $status === 'approved') pm_deviation_apply($user, $d);
            if ($phase === 'revoke' && $status === 'revoked') pm_deviation_apply($user, $d, true);
        }
        // i18n-ok-start
        $how = ['manual' => ' (נרשם על ידי מנהל הפרויקט)', 'signature' => ' — בחתימה בקישור', 'click' => ' — בקישור'][$method] ?? '';
        $text = $decision === 'rejected' ? ($phase === 'approve' ? '⚖️ {who} לא הסכים לחריגה: {type}{note}{how}' : '⚖️ {who} לא הסכים לביטול החריגה: {type}{note}{how}')
            : ($status === 'approved' ? '⚖️ כולם הסכימו — החריגה בתוקף: {type}{how}' : ($status === 'revoked' ? '⚖️ כולם הסכימו — החריגה בוטלה: {type}{how}'
            : ($phase === 'approve' ? '⚖️ {who} הסכים לחריגה: {type}{note}{how}' : '⚖️ {who} הסכים לביטול החריגה: {type}{note}{how}')));
        // i18n-ok-end
        pm_event($actor, (int)$d['project_id'], 'finance', $text, ['deviation' => $id, 'party' => $party, 'decision' => $decision],
            ['who' => $byName, 'type' => PM_DEVIATION_TYPES[$d['type']], 'note' => $note !== '' ? ": $note" : '', 'how' => $how]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return pm_deviations_of((int)$d['project_id']);
}

/** "ביטול חריגה": ממתינה — המנהל מבטל; בתוקף — בקשת ביטול, שוב בהסכמת כולם. */
function pm_deviation_cancel(array $user, int $id): array
{
    $d = pm_deviation_row($user, $id);
    $db = pm_db();
    if ($d['status'] === 'pending') {
        $db->prepare("UPDATE deviations SET status = 'cancelled', decided_at = ? WHERE id = ?")->execute([pm_now(), $id]);
        pm_event($user, (int)$d['project_id'], 'finance', '⚖️ בקשת החריגה בוטלה: {type}', ['deviation' => $id], ['type' => PM_DEVIATION_TYPES[$d['type']]]);
    } elseif ($d['status'] === 'approved') {
        $db->prepare("UPDATE deviations SET status = 'revoke_pending' WHERE id = ?")->execute([$id]);
        pm_event($user, (int)$d['project_id'], 'finance', '⚖️ בקשה לבטל חריגה: {type} — ממתינה להסכמת כולם', ['deviation' => $id], ['type' => PM_DEVIATION_TYPES[$d['type']]]);
        foreach (json_decode((string)$d['parties_json'], true) ?: [] as $x) pm_deviation_notify($user, (int)$d['project_id'], $id, (int)$x['contact_id']);
    } elseif ($d['status'] === 'revoke_pending') {
        // המנהל מוותר על בקשת הביטול — החריגה נשארת בתוקף
        $db->prepare("UPDATE deviations SET status = 'approved' WHERE id = ?")->execute([$id]);
        $db->prepare("DELETE FROM deviation_approvals WHERE deviation_id = ? AND phase = 'revoke'")->execute([$id]);
    } else throw new PmError('החריגה כבר {status}', 422, ['status' => pm_t(PM_DEVIATION_STATUSES[$d['status']] ?? $d['status'])]);
    return pm_deviations_of((int)$d['project_id']);
}

/**
 * אחוז העיכבון לאבן דרך, כשיש חריגה בתוקף שחלה עליה (אבן הדרך > הספק > כל הפרויקט; האחרונה גוברת). null — לפי החוזה.
 * $exclude — חריגה שמבטלים עכשיו (לחשב בלעדיה).
 */
function pm_deviation_retention_pct(int $projectId, int $supplierId, int $paymentId, int $exclude = 0): ?float
{
    $st = pm_db()->prepare("SELECT * FROM deviations WHERE project_id = ? AND status IN ('approved', 'revoke_pending') AND type IN ('retention_waive', 'retention_pct') AND id != ?
                             ORDER BY id");
    $st->execute([$projectId, $exclude]);
    $best = null;
    $rank = -1;
    foreach ($st->fetchAll() as $d) {
        $r = $d['payment_id'] ? ((int)$d['payment_id'] === $paymentId ? 2 : -1) : ($d['supplier_id'] ? ((int)$d['supplier_id'] === $supplierId ? 1 : -1) : 0);
        if ($r < 0 || $r < $rank) continue;
        $rank = $r;
        $best = $d['type'] === 'retention_waive' ? 0.0 : (float)$d['value'];
    }
    return $best;
}

/**
 * החריגה חלה (או מתבטלת): עיכבון — מחשבים מחדש את העיכבון באבני הדרך שעוד לא שולמו במלואן, ואת השחרור.
 * תנאי תשלום / אחר — תיעוד בלבד (אין שינוי אוטומטי).
 */
function pm_deviation_apply(array $user, array $d, bool $revoke = false): void
{
    if (!in_array($d['type'], ['retention_waive', 'retention_pct'], true)) return;
    $db = pm_db();
    $pid = (int)$d['project_id'];
    $contract = (float)$db->query('SELECT retention_pct FROM projects WHERE id = ' . $pid)->fetchColumn();
    $w = ["project_id = ?", "kind = 'milestone'", "status NOT IN ('cancelled', 'received')", "due_kind != 'advance'"];
    $a = [$pid];
    if ($d['payment_id']) { $w[] = 'id = ?'; $a[] = (int)$d['payment_id']; }
    elseif ($d['supplier_id']) { $w[] = 'supplier_id = ?'; $a[] = (int)$d['supplier_id']; }
    $st = $db->prepare('SELECT * FROM payments WHERE ' . implode(' AND ', $w));
    $st->execute($a);
    $touched = [];
    $up = $db->prepare('UPDATE payments SET retention = ?, updated_at = ? WHERE id = ?');
    foreach ($st->fetchAll() as $x) {
        if (!$x['stage_id']) continue; // בלי שלב — אין עיכבון (pm_payment_save)
        $pct = pm_deviation_retention_pct($pid, (int)$x['supplier_id'], (int)$x['id'], $revoke ? (int)$d['id'] : 0) ?? $contract;
        $up->execute([(int)round((int)$x['amount'] * $pct / 100), pm_now(), $x['id']]);
        $touched[$x['stage_id'] . ':' . $x['supplier_id']] = [(int)$x['stage_id'], (int)$x['supplier_id']];
    }
    foreach ($touched as [$s, $c]) pm_retention_sync($user, $pid, $s, $c);
}
