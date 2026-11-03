<?php
/**
 * גרסאות, השבתה, הסרה וסל מחזור.
 *
 * גרסאות: לפני כל שינוי בהצעה נשמרת תמונה שלמה שלה (הפרטים, הקופות, שורות הריכוז והשורות) — budget_versions.
 * שינויים רצופים של אותו אדם מתאחדים לגרסה אחת לכל VERSION_WINDOW; לפני מחיקה, הסרה ושחזור — תמיד גרסה.
 * כל גרסה ניתנת לצפייה ("מה השתנה מאז") ולשחזור, והשחזור עצמו שומר קודם גרסה — כך גם הוא הפיך.
 * השער ותכנית העבודה — לא כאן: יש להם גרסאות משלהם בעורך התמלילים (🕘 בתוך העורך).
 *
 * השבתה: שורה או קופה נשארות בהצעה, מחוץ לחישוב, לריכוז ולמסמך, ומוחזרות בלחיצה (budget_lines.disabled,
 * budget_funds.disabled). שורת העברה — לא מושבתת (הצד השני היה נשאר בלי בן זוג): מוחקים אותה.
 *
 * סל מחזור: הצעות שנמחקו (deleted_at) וקופות שהוסרו מהארגון (archived_at) — עם שחזור.
 */

declare(strict_types=1);

require_once __DIR__ . '/budgets.php';

const VERSION_WINDOW = 600;   // שניות
const VERSION_KEEP = 300;     // לכל הצעה

/** הפעולות ששומרות גרסה לפניהן (api.php), והתיאור שלהן ברשימת הגרסאות. */
const VERSIONED = [
    'budget_update' => 'עדכון פרטי ההצעה', 'bfund_set' => 'הערות / שורת ריכוז של קופה', 'bfund_toggle' => 'הוספת / הוצאת קופה ריקה',
    'line_save' => 'עריכת שורה', 'lines_batch' => 'עריכה כטבלה', 'line_delete' => 'מחיקת שורה', 'line_order' => 'סידור שורות',
    'transfer_create' => 'העברה חדשה', 'srow_save' => 'שורת ריכוז', 'srow_delete' => 'מחיקת שורת ריכוז', 'srow_order' => 'סידור הריכוז',
    'line_disable' => 'השבתת / החזרת שורה', 'bfund_disable' => 'השבתת / החזרת קופה', 'bfund_remove' => 'הוצאת קופה מההצעה',
    'lines_delete' => 'מחיקת שורות', 'version_restore' => 'שחזור גרסה',
];
/** לפני אלה — תמיד גרסה, גם בתוך החלון. */
const VERSION_FORCE = ['line_delete', 'srow_delete', 'bfund_remove', 'lines_delete', 'version_restore', 'bfund_toggle'];

const VERSION_FIELDS = ['title', 'submitted_to', 'signers', 'work_plan', 'summary_note', 'prev_actual_as_of', 'actual_as_of', 'expense_first'];
const VERSION_FIELD_LABELS = ['title' => 'שם ההצעה', 'submitted_to' => 'מוגשת ל…', 'signers' => 'חותמים', 'work_plan' => 'תכנית העבודה (טקסט)',
    'summary_note' => 'הערת הריכוז', 'prev_actual_as_of' => 'ניצול שנה קודמת עד', 'actual_as_of' => 'ניצול השנה עד', 'expense_first' => 'הוצאות לפני הכנסות'];
const VERSION_LINE_FIELDS = ['title' => 'שם', 'subtitle' => 'שורה שנייה', 'amount' => 'מוצע', 'prev_budget' => 'תקציב קודם',
    'prev_actual' => 'ניצול קודם', 'actual' => 'ניצול השנה', 'cell_note' => 'הערה', 'disabled' => 'מושבתת', 'fund_id' => 'קופה', 'direction' => 'כיוון'];

/** תמונה שלמה של ההצעה (בלי השער והתכנית שבעורך). */
function budget_snapshot(int $bid): array
{
    $pdo = db();
    $q = function (string $sql) use ($pdo, $bid) {
        $st = $pdo->prepare($sql);
        $st->execute([$bid]);
        return $st->fetchAll();
    };
    $b = $q('SELECT ' . implode(', ', VERSION_FIELDS) . ' FROM budgets WHERE id = ?')[0] ?? [];
    return ['budget' => $b,
        'funds' => $q('SELECT * FROM budget_funds WHERE budget_id = ? ORDER BY fund_id'),
        'rows' => $q('SELECT * FROM summary_rows WHERE budget_id = ? ORDER BY id'),
        'lines' => $q('SELECT * FROM budget_lines WHERE budget_id = ? ORDER BY id')];
}

/** סיכום לרשימה: שורות, הכנסות והוצאות פעילות. */
function snapshot_stats(array $sn): array
{
    $off = array_map(fn($f) => (int)$f['fund_id'], array_filter($sn['funds'], fn($f) => !empty($f['disabled'])));
    $on = array_filter($sn['lines'], fn($l) => empty($l['disabled']) && !in_array((int)$l['fund_id'], $off, true));
    $sum = fn($d) => array_sum(array_map(fn($l) => (int)$l['amount'], array_filter($on, fn($l) => $l['direction'] === $d)));
    return ['lines' => count($sn['lines']), 'income' => $sum('income'), 'expense' => $sum('expense')];
}

/**
 * נקודת גרסה לפני שינוי. מתאחד עם הגרסה האחרונה כשהיא של אותו אדם ובתוך החלון (אלא אם $force),
 * וכשאין שינוי מאז הגרסה האחרונה — לא נשמרת כפולה (חוץ מ-$force: התיאור "לפני מחיקה" חשוב לחיפוש).
 */
function version_point(?array $s, int $bid, string $label, bool $force = false): void
{
    if ($bid <= 0) return;
    $pdo = db();
    $last = $pdo->prepare('SELECT id, at, user_id, hash FROM budget_versions WHERE budget_id = ? ORDER BY id DESC LIMIT 1');
    $last->execute([$bid]);
    $last = $last->fetch();
    $uid = $s['id'] ?? null;
    if (!$force && $last && (int)$last['user_id'] === (int)$uid && time() - strtotime($last['at']) < VERSION_WINDOW) return;
    $sn = budget_snapshot($bid);
    if (!$sn['budget']) return;
    $json = json_encode($sn, JSON_UNESCAPED_UNICODE);
    $hash = hash('sha256', $json);
    if ($last && $last['hash'] === $hash && !$force) return;   // בלי שינוי מאז — אלא אם לפני מחיקה (שהתיאור יופיע)
    $st = snapshot_stats($sn);
    $pdo->prepare('INSERT INTO budget_versions (budget_id, at, user_id, label, hash, lines, income, expense, data) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$bid, now_iso(), $uid, mb_substr($label, 0, 200), $hash, $st['lines'], $st['income'], $st['expense'], gzdeflate($json, 6)]);
    $pdo->prepare('DELETE FROM budget_versions WHERE budget_id = ? AND id NOT IN (SELECT id FROM budget_versions WHERE budget_id = ? ORDER BY id DESC LIMIT ' . VERSION_KEEP . ')')
        ->execute([$bid, $bid]);
}

/** מ-api.php, לפני כל פעולה מ-VERSIONED: התיאור — הפעולה, ובשורה — גם השם שלה. */
function version_before(array $s, string $action, array $body): void
{
    $bid = (int)($body['budget_id'] ?? 0);
    if ($action === 'bfund_remove' && !empty($body['preview'])) return;   // תצוגה מקדימה — לא שינוי
    try {
        budget_access($s, $bid, true);
    } catch (ApiError) {
        return;   // הפעולה עצמה תיכשל ותסביר
    }
    $label = VERSIONED[$action];
    if (in_array($action, ['line_save', 'line_delete', 'line_disable'], true) && ($id = (int)($body['id'] ?? 0))) {
        $st = db()->prepare('SELECT title FROM budget_lines WHERE id = ? AND budget_id = ?');
        $st->execute([$id, $bid]);
        if ($t = $st->fetchColumn()) $label .= ' — ' . $t;
    } elseif (in_array($action, ['bfund_disable', 'bfund_remove', 'bfund_toggle', 'bfund_set'], true) && ($fid = (int)($body['fund_id'] ?? 0))) {
        $st = db()->prepare('SELECT name FROM funds WHERE id = ?');
        $st->execute([$fid]);
        if ($t = $st->fetchColumn()) $label .= ' — ' . $t;
    } elseif ($action === 'lines_delete') {
        $label .= ' (' . count((array)($body['ids'] ?? [])) . ')';
    }
    version_point($s, $bid, 'לפני: ' . $label, in_array($action, VERSION_FORCE, true));
}

function version_row(int $bid, int $id): array
{
    $st = db()->prepare('SELECT v.*, u.display_name FROM budget_versions v LEFT JOIN users u ON u.id = v.user_id WHERE v.id = ? AND v.budget_id = ?');
    $st->execute([$id, $bid]);
    $v = $st->fetch() ?: throw new ApiError('הגרסה לא נמצאה', 404);
    $v['snap'] = json_decode((string)gzinflate($v['data']), true);
    return $v;
}

function versions_list(array $s, int $bid): array
{
    $bud = budget_access($s, $bid);
    $st = db()->prepare('SELECT v.id, v.at, v.label, v.lines, v.income, v.expense, u.display_name FROM budget_versions v
        LEFT JOIN users u ON u.id = v.user_id WHERE v.budget_id = ? ORDER BY v.id DESC');
    $st->execute([$bud['id']]);
    return ['ok' => true, 'can_restore' => canEditRole($bud['org']['role']) && $bud['status'] === 'draft',
        'now' => snapshot_stats(budget_snapshot((int)$bud['id'])),
        'versions' => array_map(fn($v) => ['id' => (int)$v['id'], 'at' => $v['at'], 'by' => $v['display_name'], 'label' => $v['label'],
            'lines' => (int)$v['lines'], 'income' => (int)$v['income'], 'expense' => (int)$v['expense']], $st->fetchAll())];
}

/** מה השתנה מהגרסה עד עכשיו — מה שהשחזור יחזיר ($from = הגרסה, $to = עכשיו). */
function snapshot_diff(array $from, array $to): array
{
    $names = [];
    $ids = array_unique(array_merge(array_column($from['funds'], 'fund_id'), array_column($to['funds'], 'fund_id'),
        array_column($from['lines'], 'fund_id'), array_column($to['lines'], 'fund_id')));
    if ($ids) {
        $st = db()->query('SELECT id, name FROM funds WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
        $names = array_column($st->fetchAll(), 'name', 'id');
    }
    $fname = fn($id) => $names[$id] ?? "קופה $id";
    $show = fn($k, $v) => match ($k) {
        'disabled', 'expense_first' => $v ? 'כן' : 'לא',
        'fund_id' => $fname($v),
        'direction' => DIRECTIONS[$v] ?? $v,
        'amount', 'prev_budget', 'prev_actual', 'actual' => $v === null ? '—' : number_format((int)$v),
        'signers' => implode(', ', array_column(json_decode((string)$v, true) ?: [], 'name')) ?: '—',
        default => ($v === null || $v === '') ? '—' : mb_strimwidth((string)$v, 0, 80, '…'),
    };

    $fields = [];
    foreach (VERSION_FIELDS as $k) {
        if ((string)($from['budget'][$k] ?? '') !== (string)($to['budget'][$k] ?? '')) {
            $fields[] = ['label' => VERSION_FIELD_LABELS[$k], 'then' => $show($k, $from['budget'][$k] ?? null), 'now' => $show($k, $to['budget'][$k] ?? null)];
        }
    }
    $fa = array_column($from['funds'], null, 'fund_id');
    $fb = array_column($to['funds'], null, 'fund_id');
    $funds = [];
    foreach ($fa as $id => $f) {
        if (!isset($fb[$id])) $funds[] = ['name' => $fname($id), 'change' => 'הוצאה מההצעה'];
        elseif ((int)$f['disabled'] !== (int)$fb[$id]['disabled']) $funds[] = ['name' => $fname($id), 'change' => $f['disabled'] ? 'הוחזרה' : 'הושבתה'];
    }
    foreach ($fb as $id => $f) if (!isset($fa[$id])) $funds[] = ['name' => $fname($id), 'change' => 'נוספה להצעה'];

    $la = array_column($from['lines'], null, 'id');
    $lb = array_column($to['lines'], null, 'id');
    $line = fn($l) => ['fund' => $fname($l['fund_id']), 'direction' => DIRECTIONS[$l['direction']] ?? '', 'title' => $l['title'], 'amount' => (int)$l['amount']];
    $removed = $added = $changed = [];
    foreach ($la as $id => $l) {
        if (!isset($lb[$id])) { $removed[] = $line($l); continue; }
        $ch = [];
        foreach (VERSION_LINE_FIELDS as $k => $lab) {
            if ((string)($l[$k] ?? '') !== (string)($lb[$id][$k] ?? '')) $ch[] = ['label' => $lab, 'then' => $show($k, $l[$k] ?? null), 'now' => $show($k, $lb[$id][$k] ?? null)];
        }
        if ($ch) $changed[] = $line($l) + ['changes' => $ch];
    }
    foreach ($lb as $id => $l) if (!isset($la[$id])) $added[] = $line($l);

    $ra = array_column($from['rows'], 'label', 'id');
    $rb = array_column($to['rows'], 'label', 'id');
    $rows = [];
    foreach ($ra as $id => $lab) if (!isset($rb[$id])) $rows[] = ['label' => $lab, 'change' => 'נמחקה'];
    foreach ($rb as $id => $lab) if (!isset($ra[$id])) $rows[] = ['label' => $lab, 'change' => 'נוספה'];
    foreach ($ra as $id => $lab) if (isset($rb[$id]) && $rb[$id] !== $lab) $rows[] = ['label' => $lab, 'change' => "שונתה ל\"{$rb[$id]}\""];

    return ['fields' => $fields, 'funds' => $funds, 'removed' => $removed, 'added' => $added, 'changed' => $changed, 'rows' => $rows,
        'same' => !$fields && !$funds && !$removed && !$added && !$changed && !$rows];
}

function version_get(array $s, array $q): array
{
    $bud = budget_access($s, (int)($q['budget_id'] ?? 0));
    $v = version_row((int)$bud['id'], (int)($q['id'] ?? 0));
    return ['ok' => true, 'version' => ['id' => (int)$v['id'], 'at' => $v['at'], 'by' => $v['display_name'], 'label' => $v['label']],
        'stats' => snapshot_stats($v['snap']), 'now' => snapshot_stats(budget_snapshot((int)$bud['id'])),
        'diff' => snapshot_diff($v['snap'], budget_snapshot((int)$bud['id']))];
}

/** שחזור: ההצעה חוזרת בדיוק למצב שבגרסה. לפני כן נשמרת גרסה של המצב הנוכחי. */
function version_restore(array $s, array $b): array
{
    $bud = budget_access($s, (int)($b['budget_id'] ?? 0), true);
    $bid = (int)$bud['id'];
    $v = version_row($bid, (int)($b['id'] ?? 0));
    $sn = $v['snap'];
    $pdo = db();
    $cur = budget_snapshot($bid);
    $pdo->beginTransaction();
    try {
        $curTids = array_values(array_unique(array_filter(array_column($cur['lines'], 'transfer_id'))));
        $keep = array_map('intval', array_column($sn['lines'], 'id'));
        foreach ($cur['lines'] as $l) {
            if (!in_array((int)$l['id'], $keep, true)) $pdo->prepare('UPDATE budget_comments SET line_id = NULL WHERE line_id = ?')->execute([$l['id']]);
        }
        foreach (['budget_lines', 'budget_funds', 'summary_rows'] as $t) $pdo->prepare("DELETE FROM $t WHERE budget_id = ?")->execute([$bid]);

        $free = function (string $table, int $id) use ($pdo): bool {
            $st = $pdo->prepare("SELECT 1 FROM $table WHERE id = ?");
            $st->execute([$id]);
            return !$st->fetch();
        };
        $cols = function (string $table) use ($pdo): array {
            return array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
        };
        $insert = function (string $table, array $row) use ($pdo, $cols): int {
            $row = array_intersect_key($row, array_flip($cols($table)));
            $pdo->prepare("INSERT INTO $table (" . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')')
                ->execute(array_values($row));
            return (int)$pdo->lastInsertId();
        };
        $rowMap = [];
        foreach ($sn['rows'] as $r) {
            $old = (int)$r['id'];
            if (!$free('summary_rows', $old)) unset($r['id']);
            $rowMap[$old] = $insert('summary_rows', $r);
        }
        $map = fn($x) => $x === null ? null : ($rowMap[(int)$x] ?? null);
        $alive = array_map('intval', array_column($pdo->query('SELECT id FROM funds')->fetchAll(), 'id'));
        foreach ($sn['funds'] as $f) {
            if (!in_array((int)$f['fund_id'], $alive, true)) continue;
            $f['row_income'] = $map($f['row_income']);
            $f['row_expense'] = $map($f['row_expense']);
            $insert('budget_funds', $f);
        }
        foreach ($sn['lines'] as $l) {
            if (!in_array((int)$l['fund_id'], $alive, true)) continue;
            $old = (int)$l['id'];
            $l['summary_row_id'] = $map($l['summary_row_id']);
            if (!$free('budget_lines', $old)) unset($l['id']);
            $new = $insert('budget_lines', $l);
            if ($new !== $old) $pdo->prepare('UPDATE budget_comments SET line_id = ? WHERE line_id = ? AND budget_id = ?')->execute([$new, $old, $bid]);
        }
        $set = array_intersect_key($sn['budget'], array_flip(VERSION_FIELDS));
        if ($set) {
            $pdo->prepare('UPDATE budgets SET ' . implode(' = ?, ', array_keys($set)) . ' = ? WHERE id = ?')->execute([...array_values($set), $bid]);
        }

        // העברות: שורה שהצד השני שלה כבר לא קיים — הופכת לשורה רגילה; צד שני בהצעה אחרת שאיבד את בן הזוג — מדווח
        $fixed = 0;
        $tids = $pdo->prepare('SELECT DISTINCT transfer_id FROM budget_lines WHERE budget_id = ? AND transfer_id IS NOT NULL');
        $tids->execute([$bid]);
        foreach ($tids->fetchAll(PDO::FETCH_COLUMN) as $tid) {
            $dirs = array_unique(array_column(transfer_group((string)$tid), 'direction'));
            if (count($dirs) < 2) {
                $u = $pdo->prepare('UPDATE budget_lines SET transfer_id = NULL WHERE budget_id = ? AND transfer_id = ?');
                $u->execute([$bid, $tid]);
                $fixed += $u->rowCount();
            }
        }
        $orphans = [];
        foreach ($curTids as $tid) {
            $g = transfer_group((string)$tid);
            if (count(array_unique(array_column($g, 'direction'))) < 2) {
                foreach ($g as $p) if ((int)$p['budget_id'] !== $bid) $orphans[] = $p['title'] . ' · ' . $p['org_name'];
            }
        }
        touch_budget($bid);
        org_event($bud['org']['root_id'], $s, 'version_restore', ['name' => $bud['org']['name'], 'year' => (int)$bud['year'], 'at' => $v['at']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'fixed' => $fixed, 'orphans' => $orphans];
}

// ── השבתה ────────────────────────────────────────────────────

function line_disable(array $s, array $b): array
{
    $bud = budget_access($s, (int)($b['budget_id'] ?? 0), true);
    $st = db()->prepare('SELECT * FROM budget_lines WHERE id = ? AND budget_id = ?');
    $st->execute([(int)($b['id'] ?? 0), $bud['id']]);
    $l = $st->fetch() ?: throw new ApiError('השורה לא נמצאה', 404);
    $off = !empty($b['disabled']);
    if ($off && $l['transfer_id']) throw new ApiError('שורת העברה לא מושבתת — הצד השני היה נשאר לבד. אפשר למחוק אותה (שני הצדדים)', 409);
    db()->prepare('UPDATE budget_lines SET disabled = ? WHERE id = ?')->execute([(int)$off, $l['id']]);
    touch_budget((int)$bud['id']);
    return ['ok' => true];
}

/** העברות פעילות של קופה בהצעה — לא משביתים קופה כזו (הצד השני היה נשאר לבד). */
function fund_transfers(int $bid, int $fid): array
{
    $st = db()->prepare('SELECT * FROM budget_lines WHERE budget_id = ? AND fund_id = ? AND transfer_id IS NOT NULL AND disabled = 0');
    $st->execute([$bid, $fid]);
    $out = [];
    foreach ($st->fetchAll() as $l) {
        $others = array_filter(transfer_group((string)$l['transfer_id']), fn($p) => (int)$p['id'] !== (int)$l['id'] && $p['direction'] !== $l['direction']);
        $names = [];
        foreach ($others as $p) {
            $q = db()->prepare('SELECT f.name, b.year FROM funds f JOIN budgets b ON b.id = ? WHERE f.id = ?');
            $q->execute([$p['budget_id'], $p['fund_id']]);
            $x = $q->fetch();
            $names[] = ['fund' => $x['name'] ?? '', 'org' => $p['org_name'], 'year' => (int)($x['year'] ?? 0), 'locked' => $p['status'] !== 'draft',
                'same_budget' => (int)$p['budget_id'] === $bid];
        }
        $out[] = ['title' => $l['title'], 'direction' => $l['direction'], 'amount' => (int)$l['amount'], 'with' => array_values($names)];
    }
    return $out;
}

function bfund_row(array $bud, int $fid): array
{
    $st = db()->prepare('SELECT bf.*, f.name, f.org_id FROM budget_funds bf JOIN funds f ON f.id = bf.fund_id WHERE bf.budget_id = ? AND bf.fund_id = ?');
    $st->execute([$bud['id'], $fid]);
    return $st->fetch() ?: throw new ApiError('הקופה לא בהצעה', 404);
}

function bfund_disable(array $s, array $b): array
{
    $bud = budget_access($s, (int)($b['budget_id'] ?? 0), true);
    $f = bfund_row($bud, (int)($b['fund_id'] ?? 0));
    $off = !empty($b['disabled']);
    if ($off && ($t = fund_transfers((int)$bud['id'], (int)$f['fund_id']))) {
        throw new ApiError('בקופה יש ' . count($t) . ' העברות — הצד השני היה נשאר לבד. קודם למחוק אותן, או להוציא את הקופה מההצעה', 409);
    }
    db()->prepare('UPDATE budget_funds SET disabled = ? WHERE budget_id = ? AND fund_id = ?')->execute([(int)$off, $bud['id'], $f['fund_id']]);
    touch_budget((int)$bud['id']);
    return ['ok' => true];
}

// ── הוצאת קופה מההצעה, ומחיקת כמה שורות ─────────────────────

/** preview — מה יוסר (בלי לשנות). אחרת: confirm = שם הקופה; archive — גם הסרה מהארגון (לא תיכנס להצעות הבאות). */
function bfund_remove(array $s, array $b): array
{
    $bud = budget_access($s, (int)($b['budget_id'] ?? 0), true);
    $f = bfund_row($bud, (int)($b['fund_id'] ?? 0));
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM budget_lines WHERE budget_id = ? AND fund_id = ? ORDER BY id');
    $st->execute([$bud['id'], $f['fund_id']]);
    $lines = $st->fetchAll();
    $sum = fn($d) => array_sum(array_map(fn($l) => (int)$l['amount'], array_filter($lines, fn($l) => $l['direction'] === $d && !$l['disabled'])));
    $transfers = fund_transfers((int)$bud['id'], (int)$f['fund_id']);
    if (!empty($b['preview'])) {
        return ['ok' => true, 'name' => $f['name'], 'lines' => count($lines), 'income' => $sum('income'), 'expense' => $sum('expense'), 'transfers' => $transfers,
            'blocked' => (bool)array_filter($transfers, fn($t) => (bool)array_filter($t['with'], fn($w) => $w['locked'])),
            'can_archive' => true];
    }
    if (trim((string)($b['confirm'] ?? '')) !== trim($f['name'])) throw new ApiError('לאישור — הקלידו את שם הקופה', 422);
    $pdo->beginTransaction();
    try {
        $one = $pdo->prepare('SELECT * FROM budget_lines WHERE id = ?');
        foreach ($lines as $l) {
            $one->execute([$l['id']]);
            if ($row = $one->fetch()) line_delete_one($s, $row);   // אולי כבר נמחקה כצד שני של העברה
        }
        $pdo->prepare('DELETE FROM budget_funds WHERE budget_id = ? AND fund_id = ?')->execute([$bud['id'], $f['fund_id']]);
        if (!empty($b['archive'])) {
            org_access($s, (int)$f['org_id'], 'editor');
            $pdo->prepare('UPDATE funds SET archived_at = ? WHERE id = ? AND archived_at IS NULL')->execute([now_iso(), $f['fund_id']]);
        }
        touch_budget((int)$bud['id']);
        org_event($bud['org']['root_id'], $s, 'bfund_remove', ['name' => $f['name'], 'year' => (int)$bud['year'], 'lines' => count($lines), 'archive' => !empty($b['archive'])]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'deleted' => count($lines)];
}

function lines_delete(array $s, array $b): array
{
    $bud = budget_access($s, (int)($b['budget_id'] ?? 0), true);
    $ids = array_values(array_unique(array_map('intval', (array)($b['ids'] ?? []))));
    if (!$ids) throw new ApiError('לא נבחרו שורות', 422);
    if (count($ids) > 500) throw new ApiError('עד 500 שורות בבת אחת', 422);
    $pdo = db();
    $one = $pdo->prepare('SELECT * FROM budget_lines WHERE id = ? AND budget_id = ?');
    foreach ($ids as $id) {
        $one->execute([$id, $bud['id']]);
        if (!$one->fetch()) throw new ApiError('אחת השורות לא נמצאה — רעננו את הדף', 404);
    }
    $pdo->beginTransaction();
    try {
        $n = 0;
        foreach ($ids as $id) {
            $one->execute([$id, $bud['id']]);
            if ($row = $one->fetch()) { line_delete_one($s, $row); $n++; }
        }
        touch_budget((int)$bud['id']);
        org_event($bud['org']['root_id'], $s, 'lines_delete', ['name' => $bud['org']['name'], 'year' => (int)$bud['year'], 'count' => count($ids)]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'deleted' => count($ids)];
}

// ── סל מחזור ─────────────────────────────────────────────────

function trash_list(array $s, int $orgId): array
{
    $org = org_access($s, $orgId, 'editor');
    $st = db()->prepare('SELECT id, year, title, status, deleted_at FROM budgets WHERE org_id = ? AND deleted_at IS NOT NULL ORDER BY deleted_at DESC');
    $st->execute([$org['id']]);
    $budgets = $st->fetchAll();
    $st = db()->prepare('SELECT id, kind, name, archived_at FROM funds WHERE org_id = ? AND archived_at IS NOT NULL ORDER BY archived_at DESC');
    $st->execute([$org['id']]);
    return ['ok' => true, 'is_owner' => $org['role'] === 'owner',
        'budgets' => array_map(fn($x) => ['id' => (int)$x['id'], 'year' => (int)$x['year'], 'title' => $x['title'], 'status' => $x['status'], 'at' => $x['deleted_at']], $budgets),
        'funds' => array_map(fn($x) => ['id' => (int)$x['id'], 'kind' => $x['kind'], 'name' => $x['name'], 'at' => $x['archived_at']], $st->fetchAll())];
}

function budget_restore(array $s, array $b): array
{
    $st = db()->prepare('SELECT * FROM budgets WHERE id = ? AND deleted_at IS NOT NULL');
    $st->execute([(int)($b['budget_id'] ?? 0)]);
    $bud = $st->fetch() ?: throw new ApiError('ההצעה לא בסל המחזור', 404);
    $org = org_access($s, (int)$bud['org_id'], $bud['status'] === 'draft' ? 'editor' : 'owner');
    db()->prepare('UPDATE budgets SET deleted_at = NULL WHERE id = ?')->execute([$bud['id']]);
    org_event($org['root_id'], $s, 'budget_restore', ['name' => $org['name'], 'year' => (int)$bud['year']]);
    return ['ok' => true, 'id' => (int)$bud['id']];
}

function fund_restore(array $s, array $b): array
{
    $org = org_access($s, (int)($b['org_id'] ?? 0), 'editor');
    $st = db()->prepare('SELECT name FROM funds WHERE id = ? AND org_id = ? AND archived_at IS NOT NULL');
    $st->execute([(int)($b['id'] ?? 0), $org['id']]);
    $name = $st->fetchColumn() ?: throw new ApiError('הקופה לא בסל המחזור', 404);
    db()->prepare('UPDATE funds SET archived_at = NULL WHERE id = ?')->execute([(int)$b['id']]);
    org_event($org['root_id'], $s, 'fund_restore', ['name' => $name, 'org' => $org['name']]);
    return ['ok' => true];
}
