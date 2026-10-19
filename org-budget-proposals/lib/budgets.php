<?php
/**
 * הצעות תקציב — שלב 3.
 *
 * הצעה = ארגון (או תת-ארגון) + שנה. בתוכה, לכל קופה, שורות הכנסה ושורות הוצאה.
 *
 *   שורה ── סכום מוצע (או חישוב: חודשי × חודשים, עם שינוי מחודש X)
 *        ── תקציב שנה קודמת, ניצול שנה קודמת  (מועתקים מההצעה הקודמת, או מוקלדים)
 *        ── ניצול השנה                          (מוקלד במהלך השנה / בסופה; עובר להצעה הבאה)
 *        ── lineage: אותה "זהות" משנה לשנה — כך סעיף ששינה שם ממשיך להראות את העבר שלו
 *        ── transfer_id: העברה בין קופות — שתי שורות (הוצאה ↔ הכנסה) שהסכום שלהן תמיד זהה,
 *           גם כשהן בהצעות שונות (ארגון ↔ תת-ארגון באותה שנה)
 *
 * הריכוז: כל שורה שייכת לשורת ריכוז — שנבחרה לה, או ברירת המחדל של הקופה בהצעה, או
 * ברירת מחדל אוטומטית לפי סוג הקופה. שורת ריכוז = סכום השורות שלה, בתוך "מקטע" (כותרת טבלה).
 *
 * מצבים: טיוטה (עריכה) → הוגשה (נעולה; עורך מחזיר לטיוטה) → אושרה (רק בעלים; נעולה).
 * הערות — כל חבר, בכל מצב.
 */

declare(strict_types=1);

require_once __DIR__ . '/orgs.php';

const BUDGET_STATUS = ['draft' => 'טיוטה', 'submitted' => 'הוגשה', 'approved' => 'אושרה'];
const DIRECTIONS = ['income' => 'הכנסות', 'expense' => 'הוצאות'];
const MAX_AMOUNT = 9_999_999_999;

function budgets_migrate(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS budgets (
            id                INTEGER PRIMARY KEY,
            org_id            INTEGER NOT NULL REFERENCES orgs(id),
            year              INTEGER NOT NULL,
            title             TEXT,
            status            TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','submitted','approved')),
            based_on_id       INTEGER REFERENCES budgets(id),
            submitted_to      TEXT,
            signers           TEXT,
            work_plan         TEXT,
            summary_note      TEXT,
            prev_actual_as_of TEXT,
            actual_as_of      TEXT,
            created_by        INTEGER NOT NULL REFERENCES users(id),
            created_at        TEXT NOT NULL,
            updated_at        TEXT NOT NULL,
            status_at         TEXT,
            status_by         INTEGER REFERENCES users(id),
            deleted_at        TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_budgets_org ON budgets(org_id, year);
        -- הקופות שבהצעה, עם ההערות שאחרי הטבלאות ושורת הריכוז של כל כיוון
        CREATE TABLE IF NOT EXISTS budget_funds (
            budget_id     INTEGER NOT NULL REFERENCES budgets(id) ON DELETE CASCADE,
            fund_id       INTEGER NOT NULL REFERENCES funds(id),
            notes_income  TEXT,
            notes_expense TEXT,
            row_income    INTEGER,
            row_expense   INTEGER,
            PRIMARY KEY (budget_id, fund_id)
        );
        CREATE TABLE IF NOT EXISTS summary_rows (
            id        INTEGER PRIMARY KEY,
            budget_id INTEGER NOT NULL REFERENCES budgets(id) ON DELETE CASCADE,
            direction TEXT NOT NULL CHECK (direction IN ('income','expense')),
            section   TEXT NOT NULL,
            label     TEXT NOT NULL,
            sort      INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS budget_lines (
            id             INTEGER PRIMARY KEY,
            budget_id      INTEGER NOT NULL REFERENCES budgets(id) ON DELETE CASCADE,
            fund_id        INTEGER NOT NULL REFERENCES funds(id),
            direction      TEXT NOT NULL CHECK (direction IN ('income','expense')),
            sort           INTEGER NOT NULL DEFAULT 0,
            lineage        TEXT NOT NULL,
            title          TEXT NOT NULL,
            subtitle       TEXT,
            contact_id     INTEGER REFERENCES contacts(id),
            amount         INTEGER NOT NULL DEFAULT 0,
            calc           TEXT,
            cell_note      TEXT,
            prev_budget    INTEGER,
            prev_actual    INTEGER,
            actual         INTEGER,
            summary_row_id INTEGER,
            transfer_id    TEXT,
            created_at     TEXT NOT NULL,
            updated_at     TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_lines_budget ON budget_lines(budget_id, fund_id, direction, sort);
        CREATE INDEX IF NOT EXISTS idx_lines_transfer ON budget_lines(transfer_id);
        CREATE TABLE IF NOT EXISTS budget_comments (
            id          INTEGER PRIMARY KEY,
            budget_id   INTEGER NOT NULL REFERENCES budgets(id) ON DELETE CASCADE,
            line_id     INTEGER,
            user_id     INTEGER NOT NULL REFERENCES users(id),
            body        TEXT NOT NULL,
            at          TEXT NOT NULL,
            resolved_at TEXT,
            resolved_by INTEGER
        );
        CREATE INDEX IF NOT EXISTS idx_comments_budget ON budget_comments(budget_id, id);
        SQL);
}

// ── עזרים ────────────────────────────────────────────────────

function money_in($v, string $label, bool $nullable = true): ?int
{
    if ($v === null || $v === '') return $nullable ? null : 0;
    $s = str_replace([',', ' ', '₪'], '', (string)$v);
    if (!preg_match('/^-?\d+(\.\d+)?$/', $s)) throw new ApiError("$label: מספר לא תקין", 422);
    $n = (int)round((float)$s);
    if (abs($n) > MAX_AMOUNT) throw new ApiError("$label: סכום גדול מדי", 422);
    return $n;
}

function date_in($v, string $label): ?string
{
    $v = trim((string)($v ?? ''));
    if ($v === '') return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4))) {
        throw new ApiError("$label: תאריך לא תקין", 422);
    }
    return $v;
}

function new_lineage(): string
{
    return bin2hex(random_bytes(8));
}

/**
 * חישוב שורה חודשית: monthly × חודשים from..to, ומחודש change_month — תוספת change_pct אחוזים.
 * מחזיר [סכום שנתי, הערה לתא, חישוב מנורמל].
 */
function calc_amount(array $c): array
{
    $monthly = money_in($c['monthly'] ?? null, 'סכום חודשי', false);
    $from = max(1, min(12, (int)($c['from'] ?? 1)));
    $to = max($from, min(12, (int)($c['to'] ?? 12)));
    $chm = (int)($c['change_month'] ?? 0);
    $pct = (float)str_replace(',', '.', (string)($c['change_pct'] ?? 0));
    if ($pct < -100 || $pct > 1000) throw new ApiError('אחוז השינוי לא סביר', 422);
    $months = $to - $from + 1;
    $note = number_format($monthly) . ' ₪ לחודש';
    if ($months !== 12) $note .= " · $months חודשים";
    if ($chm > $from && $chm <= $to && $pct != 0) {
        $after = (int)round($monthly * (1 + $pct / 100));
        $amount = $monthly * ($chm - $from) + $after * ($to - $chm + 1);
        $note .= ' · ' . ($pct > 0 ? 'תוספת' : 'הפחתה') . ' של ' . rtrim(rtrim(number_format(abs($pct), 2), '0'), '.') . "% מחודש $chm (" . number_format($after) . ')';
        $norm = ['monthly' => $monthly, 'from' => $from, 'to' => $to, 'change_month' => $chm, 'change_pct' => $pct];
    } else {
        $amount = $monthly * $months;
        $norm = ['monthly' => $monthly, 'from' => $from, 'to' => $to];
    }
    return [$amount, $note, $norm];
}

/** ההצעה, הארגון והתפקיד. $edit — דורש עריכה ומצב טיוטה. */
function budget_access(array $s, int $id, bool $edit = false): array
{
    $st = db()->prepare('SELECT * FROM budgets WHERE id = ? AND deleted_at IS NULL');
    $st->execute([$id]);
    $b = $st->fetch() ?: throw new ApiError('ההצעה לא נמצאה', 404);
    $org = org_access($s, (int)$b['org_id'], $edit ? 'editor' : 'viewer');
    if ($edit && $b['status'] !== 'draft') {
        throw new ApiError('ההצעה ' . BUDGET_STATUS[$b['status']] . ' ונעולה. כדי לערוך — להחזיר אותה לטיוטה', 409);
    }
    $b['org'] = $org;
    return $b;
}

function touch_budget(int $id): void
{
    db()->prepare('UPDATE budgets SET updated_at = ? WHERE id = ?')->execute([now_iso(), $id]);
}

/** הקופות של ארגון שאפשר להכניס להצעה שלו. */
function org_funds(int $orgId): array
{
    $st = db()->prepare('SELECT * FROM funds WHERE org_id = ? AND archived_at IS NULL ORDER BY sort, id');
    $st->execute([$orgId]);
    return $st->fetchAll();
}

// ── הצעות ────────────────────────────────────────────────────

function budget_list(array $s, int $orgId): array
{
    $org = org_access($s, $orgId);
    $st = db()->prepare("SELECT b.*, u.display_name AS creator,
            (SELECT COALESCE(SUM(amount), 0) FROM budget_lines l WHERE l.budget_id = b.id AND l.direction = 'income') AS income,
            (SELECT COALESCE(SUM(amount), 0) FROM budget_lines l WHERE l.budget_id = b.id AND l.direction = 'expense') AS expense,
            (SELECT COUNT(*) FROM budget_comments c WHERE c.budget_id = b.id AND c.resolved_at IS NULL) AS open_comments
        FROM budgets b JOIN users u ON u.id = b.created_by
        WHERE b.org_id = ? AND b.deleted_at IS NULL ORDER BY b.year DESC, b.id DESC");
    $st->execute([$org['id']]);
    return ['ok' => true, 'budgets' => array_map(fn($b) => [
        'id' => (int)$b['id'], 'year' => (int)$b['year'], 'title' => $b['title'], 'status' => $b['status'],
        'income' => (int)$b['income'], 'expense' => (int)$b['expense'], 'open_comments' => (int)$b['open_comments'],
        'creator' => $b['creator'], 'updated_at' => $b['updated_at'],
    ], $st->fetchAll()), 'statuses' => BUDGET_STATUS];
}

/** הצעה חדשה. based_on — הצעה קודמת של אותו ארגון: הכול מועתק, והסכומים שלה הופכים ל"שנה קודמת". */
function budget_create(array $s, array $b): array
{
    $org = org_access($s, (int)($b['org_id'] ?? 0), 'editor');
    $year = (int)($b['year'] ?? 0);
    if ($year < 2000 || $year > 2100) throw new ApiError('שנה לא תקינה', 422);
    $title = text_in($b['title'] ?? '', 80, 'כותרת');
    $baseId = (int)($b['based_on_id'] ?? 0) ?: null;
    $pdo = db();
    $base = null;
    if ($baseId) {
        $st = $pdo->prepare('SELECT * FROM budgets WHERE id = ? AND org_id = ? AND deleted_at IS NULL');
        $st->execute([$baseId, $org['id']]);
        $base = $st->fetch() ?: throw new ApiError('ההצעה הקודמת לא נמצאה', 404);
    }
    $now = now_iso();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO budgets (org_id, year, title, based_on_id, submitted_to, signers, work_plan, summary_note,
                prev_actual_as_of, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$org['id'], $year, $title, $baseId,
                $base['submitted_to'] ?? 'מוגשת לדיון בוועדת הכספים, בהנהלה ובמליאת המועצה',
                $base['signers'] ?? null,
                $base ? year_shift((string)$base['work_plan'], (int)$base['year'], $year) : null,
                $base['summary_note'] ?? '** ביצוע התקציב והתוכנית השנתית מותנה בהכנסות בפועל',
                $base['actual_as_of'] ?? null, $s['id'], $now, $now]);
        $id = (int)$pdo->lastInsertId();
        if ($base) copy_budget_body((int)$base['id'], $id, $org['id'], fn($tid) => $tid ? substr(hash('sha256', "$tid:$year"), 0, 16) : null, true);
        else foreach (org_funds($org['id']) as $f) {
            $pdo->prepare('INSERT INTO budget_funds (budget_id, fund_id) VALUES (?, ?)')->execute([$id, $f['id']]);
        }
        org_event($org['root_id'], $s, 'budget_create', ['name' => $org['name'], 'year' => $year, 'from' => $base ? (int)$base['year'] : null]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'id' => $id];
}

/** שנה קודמת → השנה הזו, בטקסט חופשי ("תכנית עבודה לשנת 2026" → 2027). רק המספר המדויק. */
function year_shift(string $text, int $from, int $to): ?string
{
    if ($text === '') return null;
    return preg_replace('/(?<!\d)' . $from . '(?!\d)/', (string)$to, $text);
}

/**
 * מעתיק קופות, שורות ריכוז ושורות. $newYear — שנה חדשה: הסכומים הופכים לעמודות "שנה קודמת".
 * $tid — מיפוי מזהה העברה (שנה חדשה: גזירה דטרמיניסטית, כדי שגם ההצעה של הצד השני תתחבר).
 */
function copy_budget_body(int $fromId, int $toId, int $orgId, callable $tid, bool $newYear): void
{
    $pdo = db();
    $active = array_column(org_funds($orgId), 'id');
    $rows = $pdo->prepare('SELECT * FROM summary_rows WHERE budget_id = ? ORDER BY sort, id');
    $rows->execute([$fromId]);
    $rowMap = [];
    $ins = $pdo->prepare('INSERT INTO summary_rows (budget_id, direction, section, label, sort) VALUES (?, ?, ?, ?, ?)');
    foreach ($rows->fetchAll() as $r) {
        $ins->execute([$toId, $r['direction'], $r['section'], $r['label'], $r['sort']]);
        $rowMap[(int)$r['id']] = (int)$pdo->lastInsertId();
    }
    $map = fn($v) => $v !== null && isset($rowMap[(int)$v]) ? $rowMap[(int)$v] : null;

    $bf = $pdo->prepare('SELECT * FROM budget_funds WHERE budget_id = ?');
    $bf->execute([$fromId]);
    $had = [];
    $ins = $pdo->prepare('INSERT INTO budget_funds (budget_id, fund_id, notes_income, notes_expense, row_income, row_expense) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($bf->fetchAll() as $f) {
        if (!in_array($f['fund_id'], $active)) continue;
        $ins->execute([$toId, $f['fund_id'], $f['notes_income'], $f['notes_expense'], $map($f['row_income']), $map($f['row_expense'])]);
        $had[] = (int)$f['fund_id'];
    }
    foreach (array_diff(array_map('intval', $active), $had) as $fid) {   // קופה שנוספה לארגון מאז
        $pdo->prepare('INSERT INTO budget_funds (budget_id, fund_id) VALUES (?, ?)')->execute([$toId, $fid]);
    }

    $lines = $pdo->prepare('SELECT * FROM budget_lines WHERE budget_id = ? ORDER BY id');
    $lines->execute([$fromId]);
    $ins = $pdo->prepare('INSERT INTO budget_lines (budget_id, fund_id, direction, sort, lineage, title, subtitle, contact_id, amount, calc,
            cell_note, prev_budget, prev_actual, actual, summary_row_id, transfer_id, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $now = now_iso();
    foreach ($lines->fetchAll() as $l) {
        if (!in_array($l['fund_id'], $active)) continue;
        $ins->execute([$toId, $l['fund_id'], $l['direction'], $l['sort'], $l['lineage'], $l['title'], $l['subtitle'], $l['contact_id'],
            $l['amount'], $l['calc'], $l['cell_note'],
            $newYear ? $l['amount'] : $l['prev_budget'],
            $newYear ? $l['actual'] : $l['prev_actual'],
            $newYear ? null : $l['actual'],
            $map($l['summary_row_id']), $tid($l['transfer_id']), $now, $now]);
    }
}

function budget_get(array $s, int $id): array
{
    $b = budget_access($s, $id);
    $org = $b['org'];
    $pdo = db();

    $funds = $pdo->prepare('SELECT f.*, bf.notes_income, bf.notes_expense, bf.row_income, bf.row_expense
        FROM budget_funds bf JOIN funds f ON f.id = bf.fund_id WHERE bf.budget_id = ? ORDER BY f.sort, f.id');
    $funds->execute([$id]);
    $funds = $funds->fetchAll();

    $lines = $pdo->prepare('SELECT l.*, c.name AS contact_name FROM budget_lines l LEFT JOIN contacts c ON c.id = l.contact_id
        WHERE l.budget_id = ? ORDER BY l.sort, l.id');
    $lines->execute([$id]);
    $lines = $lines->fetchAll();

    // הצד השני של כל העברה: איזו קופה, באיזה ארגון והצעה
    $partners = [];
    $tids = array_values(array_unique(array_filter(array_column($lines, 'transfer_id'))));
    if ($tids) {
        $ph = implode(',', array_fill(0, count($tids), '?'));
        $st = $pdo->prepare("SELECT l.id, l.transfer_id, l.budget_id, l.direction, f.name AS fund, o.name AS org, b.year, b.status
            FROM budget_lines l JOIN budgets b ON b.id = l.budget_id AND b.deleted_at IS NULL
            JOIN funds f ON f.id = l.fund_id JOIN orgs o ON o.id = b.org_id WHERE l.transfer_id IN ($ph)");
        $st->execute($tids);
        foreach ($st->fetchAll() as $p) $partners[$p['transfer_id']][] = $p;
    }

    $rows = $pdo->prepare('SELECT * FROM summary_rows WHERE budget_id = ? ORDER BY sort, id');
    $rows->execute([$id]);
    $rows = $rows->fetchAll();

    $comments = $pdo->prepare('SELECT c.*, u.display_name FROM budget_comments c JOIN users u ON u.id = c.user_id WHERE c.budget_id = ? ORDER BY c.id');
    $comments->execute([$id]);

    // יעדים להעברה: כל הקופות בכל הארגון (הראשי ותתי-הארגונים)
    $targets = $pdo->prepare('SELECT f.id, f.name, f.kind, o.id AS org_id, o.name AS org FROM funds f JOIN orgs o ON o.id = f.org_id
        WHERE (o.id = ? OR o.parent_id = ?) AND o.deleted_at IS NULL AND f.archived_at IS NULL ORDER BY o.parent_id IS NOT NULL, o.id, f.sort, f.id');
    $targets->execute([$org['root_id'], $org['root_id']]);

    $contacts = $pdo->prepare('SELECT id, name, kind FROM contacts WHERE org_id = ? AND archived_at IS NULL ORDER BY name');
    $contacts->execute([$org['root_id']]);
    $cats = $pdo->prepare('SELECT name, kind FROM categories WHERE org_id = ? AND archived_at IS NULL ORDER BY name');
    $cats->execute([$org['root_id']]);
    $versions = $pdo->prepare('SELECT id, year, title, status FROM budgets WHERE org_id = ? AND deleted_at IS NULL AND id != ? ORDER BY year DESC, id DESC');
    $versions->execute([$org['id'], $id]);

    $out = [
        'id' => (int)$b['id'], 'year' => (int)$b['year'], 'title' => $b['title'], 'status' => $b['status'],
        'submitted_to' => $b['submitted_to'], 'signers' => json_decode((string)$b['signers'], true) ?: [],
        'work_plan' => $b['work_plan'], 'summary_note' => $b['summary_note'],
        'prev_actual_as_of' => $b['prev_actual_as_of'], 'actual_as_of' => $b['actual_as_of'],
        'based_on_id' => $b['based_on_id'] ? (int)$b['based_on_id'] : null,
        'created_at' => $b['created_at'], 'updated_at' => $b['updated_at'], 'status_at' => $b['status_at'],
    ];
    $fundsOut = array_map(fn($f) => [
        'id' => (int)$f['id'], 'kind' => $f['kind'], 'name' => $f['name'], 'address' => $f['address'],
        'notes_income' => $f['notes_income'], 'notes_expense' => $f['notes_expense'],
        'row_income' => $f['row_income'] === null ? null : (int)$f['row_income'],
        'row_expense' => $f['row_expense'] === null ? null : (int)$f['row_expense'],
    ], $funds);
    $linesOut = array_map(fn($l) => [
        'id' => (int)$l['id'], 'fund_id' => (int)$l['fund_id'], 'direction' => $l['direction'], 'sort' => (int)$l['sort'],
        'title' => $l['title'], 'subtitle' => $l['subtitle'], 'contact_id' => $l['contact_id'] === null ? null : (int)$l['contact_id'],
        'contact' => $l['contact_name'], 'amount' => (int)$l['amount'], 'calc' => json_decode((string)$l['calc'], true),
        'cell_note' => $l['cell_note'], 'calc_note' => $l['calc'] ? calc_amount(json_decode($l['calc'], true))[1] : null, 'prev_budget' => $l['prev_budget'] === null ? null : (int)$l['prev_budget'],
        'prev_actual' => $l['prev_actual'] === null ? null : (int)$l['prev_actual'], 'actual' => $l['actual'] === null ? null : (int)$l['actual'],
        'summary_row_id' => $l['summary_row_id'] === null ? null : (int)$l['summary_row_id'],
        'transfer' => $l['transfer_id'] ? array_values(array_map(fn($p) => [
            'line_id' => (int)$p['id'], 'budget_id' => (int)$p['budget_id'], 'fund' => $p['fund'], 'org' => $p['org'],
            'year' => (int)$p['year'], 'same_budget' => (int)$p['budget_id'] === $id,
        ], array_filter($partners[$l['transfer_id']] ?? [], fn($p) => (int)$p['id'] !== (int)$l['id']))) : null,
    ], $lines);

    return ['ok' => true, 'budget' => $out,
        'org' => ['id' => (int)$org['id'], 'name' => $org['name'], 'subtitle' => $org['subtitle'], 'role' => $org['role'],
            'parent' => $org['parent_id'] ? (int)$org['parent_id'] : null],
        'funds' => $fundsOut, 'lines' => $linesOut,
        'rows' => array_map(fn($r) => ['id' => (int)$r['id'], 'direction' => $r['direction'], 'section' => $r['section'], 'label' => $r['label'], 'sort' => (int)$r['sort']], $rows),
        'summary' => budget_summary($fundsOut, $linesOut, $rows),
        'comments' => array_map(fn($c) => [
            'id' => (int)$c['id'], 'line_id' => $c['line_id'] === null ? null : (int)$c['line_id'], 'by' => $c['display_name'],
            'mine' => (int)$c['user_id'] === (int)$s['id'], 'body' => $c['body'], 'at' => $c['at'], 'resolved' => $c['resolved_at'] !== null,
        ], $comments->fetchAll()),
        'targets' => array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['name'], 'kind' => $t['kind'], 'org_id' => (int)$t['org_id'], 'org' => $t['org']], $targets->fetchAll()),
        'contacts' => array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'kind' => $c['kind']], $contacts->fetchAll()),
        'categories' => $cats->fetchAll(),
        'versions' => array_map(fn($v) => ['id' => (int)$v['id'], 'year' => (int)$v['year'], 'title' => $v['title'], 'status' => $v['status']], $versions->fetchAll()),
        'labels' => ['status' => BUDGET_STATUS, 'directions' => DIRECTIONS, 'funds' => FUND_KINDS],
    ];
}

/** שורת ריכוז אוטומטית לשורה שלא נבחרה לה אחת: לפי סוג הקופה. */
function auto_row(array $fund, string $dir): array
{
    [$key, $label] = match ($fund['kind']) {
        'hekdesh' => ['hekdesh', 'הקדשות דתיים'],
        'secular' => ['secular', 'הקדשות חילוניים'],
        default   => ['fund' . $fund['id'], $fund['name']],
    };
    return ['key' => "auto:$dir:$key", 'section' => DIRECTIONS[$dir], 'label' => $label];
}

/**
 * דף הריכוז: מקטעים (כותרת + כיוון) ← שורות ← סכומי שלוש העמודות.
 * הסדר: שורות שהוגדרו (לפי sort), ואחריהן האוטומטיות לפי סדר הקופות. הכנסות לפני הוצאות.
 */
function budget_summary(array $funds, array $lines, array $rows): array
{
    $fundById = array_column($funds, null, 'id');
    $defined = [];
    foreach ($rows as $r) $defined[(int)$r['id']] = $r;
    $buckets = [];   // key => [section, label, direction, order, sums]
    $add = function (string $key, string $section, string $label, string $dir, float $order) use (&$buckets) {
        $buckets[$key] ??= ['key' => $key, 'section' => $section, 'label' => $label, 'direction' => $dir, 'order' => $order,
            'prev_budget' => 0, 'prev_actual' => 0, 'amount' => 0, 'actual' => 0, 'lines' => 0, 'funds' => []];
        return $key;
    };
    foreach ($defined as $id => $r) $add("row:$id", $r['section'], $r['label'], $r['direction'], (float)$r['sort']);
    $fundOrder = array_flip(array_column($funds, 'id'));
    foreach ($lines as $l) {
        $f = $fundById[$l['fund_id']] ?? null;
        if (!$f) continue;
        $dir = $l['direction'];
        $rid = $l['summary_row_id'] ?? ($f["row_$dir"] ?? null);
        if ($rid !== null && isset($defined[$rid]) && $defined[$rid]['direction'] === $dir) {
            $key = "row:$rid";
        } else {
            $a = auto_row($f, $dir);
            $key = $add($a['key'], $a['section'], $a['label'], $dir, 10000 + $fundOrder[$f['id']]);
        }
        foreach (['prev_budget', 'prev_actual', 'amount', 'actual'] as $c) $buckets[$key][$c] += (int)($l[$c] ?? 0);
        $buckets[$key]['lines']++;
        if (!in_array($f['id'], $buckets[$key]['funds'], true)) $buckets[$key]['funds'][] = $f['id'];
    }
    usort($buckets, fn($a, $b) => [$a['direction'] === 'income' ? 0 : 1, $a['order']] <=> [$b['direction'] === 'income' ? 0 : 1, $b['order']]);
    $sections = [];
    foreach ($buckets as $r) {
        $sk = $r['direction'] . '|' . $r['section'];
        $sections[$sk] ??= ['title' => $r['section'], 'direction' => $r['direction'], 'rows' => [],
            'total' => ['prev_budget' => 0, 'prev_actual' => 0, 'amount' => 0, 'actual' => 0]];
        unset($r['order']);
        $sections[$sk]['rows'][] = $r;
        foreach ($sections[$sk]['total'] as $c => $_) $sections[$sk]['total'][$c] += $r[$c];
    }
    $tot = fn($dir) => array_sum(array_map(fn($l) => $l['direction'] === $dir ? (int)$l['amount'] : 0, $lines));
    // איזון לכל קופה — הכנסות מול הוצאות
    $balance = [];
    foreach ($funds as $f) {
        $in = $ex = 0;
        foreach ($lines as $l) if ($l['fund_id'] === $f['id']) $l['direction'] === 'income' ? $in += $l['amount'] : $ex += $l['amount'];
        $balance[] = ['fund_id' => $f['id'], 'income' => $in, 'expense' => $ex, 'diff' => $in - $ex];
    }
    return ['sections' => array_values($sections), 'income' => $tot('income'), 'expense' => $tot('expense'),
        'balance' => $balance, 'unbalanced' => count(array_filter($balance, fn($x) => $x['diff'] !== 0))];
}

function budget_update(array $s, array $b): array
{
    $bud = budget_access($s, (int)($b['budget_id'] ?? 0), true);
    $signers = [];
    foreach (array_slice((array)($b['signers'] ?? []), 0, 6) as $sg) {
        $name = text_in($sg['name'] ?? '', 80, 'שם החותם');
        if (!$name) continue;
        $titles = array_values(array_filter(array_map(fn($t) => text_in($t, 80, 'תפקיד'), array_slice((array)($sg['titles'] ?? []), 0, 4))));
        $signers[] = ['name' => $name, 'titles' => $titles];
    }
    db()->prepare('UPDATE budgets SET title = ?, submitted_to = ?, signers = ?, work_plan = ?, summary_note = ?,
            prev_actual_as_of = ?, actual_as_of = ?, updated_at = ? WHERE id = ?')
        ->execute([text_in($b['title'] ?? '', 80, 'כותרת'), text_in($b['submitted_to'] ?? '', 200, 'מוגשת ל'),
            json_encode($signers, JSON_UNESCAPED_UNICODE), text_in($b['work_plan'] ?? '', 30000, 'תכנית העבודה'),
            text_in($b['summary_note'] ?? '', 500, 'הערת הריכוז'),
            date_in($b['prev_actual_as_of'] ?? '', 'ניצול עד'), date_in($b['actual_as_of'] ?? '', 'ניצול השנה עד'),
            now_iso(), $bud['id']]);
    return ['ok' => true];
}

/** מעבר מצב: draft ↔ submitted (עורך), submitted ↔ approved (בעלים). */
function budget_status(array $s, array $b): array
{
    $bud = budget_access($s, (int)($b['budget_id'] ?? 0));
    $to = one_of($b['status'] ?? '', BUDGET_STATUS, 'מצב');
    $from = $bud['status'];
    $ok = ['draft' => ['submitted'], 'submitted' => ['draft', 'approved'], 'approved' => ['submitted']];
    if (!in_array($to, $ok[$from], true)) throw new ApiError('מעבר מצב לא אפשרי', 409);
    $need = ($from === 'approved' || $to === 'approved') ? 'owner' : 'editor';
    if (ROLES[$bud['org']['role']] < ROLES[$need]) throw new ApiError($need === 'owner' ? 'רק בעלים מאשר הצעה' : 'אין לך הרשאת עריכה', 403);
    db()->prepare('UPDATE budgets SET status = ?, status_at = ?, status_by = ?, updated_at = ? WHERE id = ?')
        ->execute([$to, now_iso(), $s['id'], now_iso(), $bud['id']]);
    org_event($bud['org']['root_id'], $s, 'budget_status', ['name' => $bud['org']['name'], 'year' => (int)$bud['year'], 'from' => $from, 'to' => $to]);
    return ['ok' => true];
}

/** עותק לאותה שנה (גרסה נוספת), טיוטה. העברות מקבלות מזהים חדשים — העותק לא משנה את המקור. */
function budget_duplicate(array $s, array $b): array
{
    $bud = budget_access($s, (int)($b['budget_id'] ?? 0));
    if (!canEditRole($bud['org']['role'])) throw new ApiError('אין לך הרשאת עריכה', 403);
    $pdo = db();
    $now = now_iso();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO budgets (org_id, year, title, based_on_id, submitted_to, signers, work_plan, summary_note,
                prev_actual_as_of, actual_as_of, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$bud['org_id'], $bud['year'], text_in(($bud['title'] ? $bud['title'] . ' — ' : '') . 'עותק', 80, 'כותרת'), $bud['based_on_id'],
                $bud['submitted_to'], $bud['signers'], $bud['work_plan'], $bud['summary_note'], $bud['prev_actual_as_of'], $bud['actual_as_of'],
                $s['id'], $now, $now]);
        $id = (int)$pdo->lastInsertId();
        $fresh = [];
        copy_budget_body((int)$bud['id'], $id, (int)$bud['org_id'], function ($tid) use (&$fresh) {
            return $tid ? ($fresh[$tid] ??= new_lineage()) : null;
        }, false);
        org_event($bud['org']['root_id'], $s, 'budget_create', ['name' => $bud['org']['name'], 'year' => (int)$bud['year'], 'copy' => true]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'id' => $id];
}

function canEditRole(string $role): bool
{
    return ROLES[$role] >= ROLES['editor'];
}

/** מחיקה רכה. טיוטה — עורך; הוגשה / אושרה — בעלים. דורש הקלדת השנה. */
function budget_delete(array $s, array $b): array
{
    $bud = budget_access($s, (int)($b['budget_id'] ?? 0));
    $need = $bud['status'] === 'draft' ? 'editor' : 'owner';
    if (ROLES[$bud['org']['role']] < ROLES[$need]) throw new ApiError($need === 'owner' ? 'רק בעלים מוחק הצעה שהוגשה' : 'אין לך הרשאת עריכה', 403);
    if ((string)($b['confirm'] ?? '') !== (string)$bud['year']) throw new ApiError('לאישור — הקלידו את השנה', 422);
    db()->prepare('UPDATE budgets SET deleted_at = ? WHERE id = ?')->execute([now_iso(), $bud['id']]);
    org_event($bud['org']['root_id'], $s, 'budget_delete', ['name' => $bud['org']['name'], 'year' => (int)$bud['year']]);
    return ['ok' => true];
}

// ── קופות בהצעה ──────────────────────────────────────────────

/** הערות אחרי הטבלאות, ושורת הריכוז של הקופה לכל כיוון. */
function bfund_set(array $s, array $b): array
{
    $bud = budget_access($s, (int)($b['budget_id'] ?? 0), true);
    $fid = (int)($b['fund_id'] ?? 0);
    $st = db()->prepare('SELECT 1 FROM budget_funds WHERE budget_id = ? AND fund_id = ?');
    $st->execute([$bud['id'], $fid]);
    if (!$st->fetch()) throw new ApiError('הקופה לא בהצעה', 404);
    $row = fn($k, $dir) => ($v = (int)($b[$k] ?? 0)) ? summary_row_of($bud['id'], $v, $dir) : null;
    db()->prepare('UPDATE budget_funds SET notes_income = ?, notes_expense = ?, row_income = ?, row_expense = ? WHERE budget_id = ? AND fund_id = ?')
        ->execute([text_in($b['notes_income'] ?? '', 3000, 'הערות'), text_in($b['notes_expense'] ?? '', 3000, 'הערות'),
            $row('row_income', 'income'), $row('row_expense', 'expense'), $bud['id'], $fid]);
    touch_budget($bud['id']);
    return ['ok' => true];
}

/** הוספת קופה שנוספה לארגון אחרי שההצעה נפתחה, או הוצאת קופה ריקה מההצעה. */
function bfund_toggle(array $s, array $b): array
{
    $bud = budget_access($s, (int)($b['budget_id'] ?? 0), true);
    $fid = (int)($b['fund_id'] ?? 0);
    if (!in_array($fid, array_map('intval', array_column(org_funds((int)$bud['org_id']), 'id')), true)) throw new ApiError('הקופה לא נמצאה', 404);
    if (!empty($b['include'])) {
        db()->prepare('INSERT OR IGNORE INTO budget_funds (budget_id, fund_id) VALUES (?, ?)')->execute([$bud['id'], $fid]);
    } else {
        $st = db()->prepare('SELECT COUNT(*) FROM budget_lines WHERE budget_id = ? AND fund_id = ?');
        $st->execute([$bud['id'], $fid]);
        if ((int)$st->fetchColumn() > 0) throw new ApiError('יש בקופה שורות — קודם למחוק אותן', 409);
        db()->prepare('DELETE FROM budget_funds WHERE budget_id = ? AND fund_id = ?')->execute([$bud['id'], $fid]);
    }
    touch_budget($bud['id']);
    return ['ok' => true];
}

// ── שורות ────────────────────────────────────────────────────

function summary_row_of(int $budgetId, int $rowId, string $dir): int
{
    $st = db()->prepare('SELECT direction FROM summary_rows WHERE id = ? AND budget_id = ?');
    $st->execute([$rowId, $budgetId]);
    $d = $st->fetchColumn() ?: throw new ApiError('שורת הריכוז לא נמצאה', 404);
    if ($d !== $dir) throw new ApiError('שורת הריכוז בכיוון אחר (הכנסות / הוצאות)', 422);
    return $rowId;
}

/** הערכים של שורה מהקלט — משותף ליצירה, לעריכה ולהעברה. */
function line_values(array $bud, array $b, string $dir): array
{
    $title = text_in($b['title'] ?? '', 160, 'שם הסעיף', true);
    $contact = (int)($b['contact_id'] ?? 0) ?: null;
    if ($contact) {
        $st = db()->prepare('SELECT 1 FROM contacts WHERE id = ? AND org_id = ? AND archived_at IS NULL');
        $st->execute([$contact, $bud['org']['root_id']]);
        if (!$st->fetch()) throw new ApiError('הגורם לא נמצא', 404);
    }
    $calc = null;
    $note = text_in($b['cell_note'] ?? '', 160, 'הערה בתא');
    if (!empty($b['calc']) && is_array($b['calc'])) {
        // ההערה האוטומטית ("65,063 ₪ לחודש") נגזרת מהחישוב בכל קריאה (calc_note); cell_note — רק תוספת של המשתמש
        [$amount, , $calc] = calc_amount($b['calc']);
    } else {
        $amount = money_in($b['amount'] ?? null, 'סכום', false);
    }
    return [
        'title' => $title, 'subtitle' => text_in($b['subtitle'] ?? '', 200, 'שורה שנייה'), 'contact_id' => $contact,
        'amount' => $amount, 'calc' => $calc ? json_encode($calc) : null, 'cell_note' => $note,
        'prev_budget' => money_in($b['prev_budget'] ?? null, 'תקציב שנה קודמת'),
        'prev_actual' => money_in($b['prev_actual'] ?? null, 'ניצול שנה קודמת'),
        'actual' => money_in($b['actual'] ?? null, 'ניצול השנה'),
        'summary_row_id' => ($r = (int)($b['summary_row_id'] ?? 0)) ? summary_row_of((int)$bud['id'], $r, $dir) : null,
    ];
}

function line_save(array $s, array $b): array
{
    $bud = budget_access($s, (int)($b['budget_id'] ?? 0), true);
    $pdo = db();
    $id = (int)($b['id'] ?? 0);
    $now = now_iso();
    if ($id) {
        $st = $pdo->prepare('SELECT * FROM budget_lines WHERE id = ? AND budget_id = ?');
        $st->execute([$id, $bud['id']]);
        $old = $st->fetch() ?: throw new ApiError('השורה לא נמצאה', 404);
        $v = line_values($bud, $b, $old['direction']);
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE budget_lines SET title = ?, subtitle = ?, contact_id = ?, amount = ?, calc = ?, cell_note = ?,
                    prev_budget = ?, prev_actual = ?, actual = ?, summary_row_id = ?, updated_at = ? WHERE id = ?')
                ->execute([$v['title'], $v['subtitle'], $v['contact_id'], $v['amount'], $v['calc'], $v['cell_note'],
                    $v['prev_budget'], $v['prev_actual'], $v['actual'], $v['summary_row_id'], $now, $id]);
            if ($old['transfer_id']) sync_transfer((string)$old['transfer_id'], $id, $v);
            touch_budget((int)$bud['id']);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['ok' => true, 'id' => $id];
    }
    $dir = one_of($b['direction'] ?? '', DIRECTIONS, 'כיוון');
    $fid = budget_fund_of($bud, (int)($b['fund_id'] ?? 0));
    $v = line_values($bud, $b, $dir);
    $id = insert_line((int)$bud['id'], $fid, $dir, $v, null);
    touch_budget((int)$bud['id']);
    return ['ok' => true, 'id' => $id];
}

function budget_fund_of(array $bud, int $fid): int
{
    $st = db()->prepare('SELECT 1 FROM budget_funds WHERE budget_id = ? AND fund_id = ?');
    $st->execute([$bud['id'], $fid]);
    if (!$st->fetch()) throw new ApiError('הקופה לא בהצעה', 404);
    return $fid;
}

function insert_line(int $budgetId, int $fid, string $dir, array $v, ?string $transfer): int
{
    $pdo = db();
    $st = $pdo->prepare('SELECT COALESCE(MAX(sort), -1) + 1 FROM budget_lines WHERE budget_id = ? AND fund_id = ? AND direction = ?');
    $st->execute([$budgetId, $fid, $dir]);
    $now = now_iso();
    $pdo->prepare('INSERT INTO budget_lines (budget_id, fund_id, direction, sort, lineage, title, subtitle, contact_id, amount, calc, cell_note,
            prev_budget, prev_actual, actual, summary_row_id, transfer_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$budgetId, $fid, $dir, (int)$st->fetchColumn(), new_lineage(), $v['title'], $v['subtitle'] ?? null, $v['contact_id'] ?? null,
            $v['amount'], $v['calc'] ?? null, $v['cell_note'] ?? null, $v['prev_budget'] ?? null, $v['prev_actual'] ?? null, $v['actual'] ?? null,
            $v['summary_row_id'] ?? null, $transfer, $now, $now]);
    return (int)$pdo->lastInsertId();
}

/**
 * העברה: הסכומים (מוצע, ניצול, שנה קודמת) של הצד השני תמיד זהים.
 * צד שנמצא בהצעה נעולה — חוסם את השינוי, כדי שהשניים לא ייפרדו.
 */
function sync_transfer(string $tid, int $lineId, array $v): void
{
    $st = db()->prepare('SELECT l.id, l.budget_id, b.status, o.name FROM budget_lines l JOIN budgets b ON b.id = l.budget_id AND b.deleted_at IS NULL
        JOIN orgs o ON o.id = b.org_id WHERE l.transfer_id = ? AND l.id != ?');
    $st->execute([$tid, $lineId]);
    foreach ($st->fetchAll() as $p) {
        if ($p['status'] !== 'draft') throw new ApiError("הצד השני של ההעברה (הצעה של {$p['name']}) נעול — קודם להחזיר אותה לטיוטה", 409);
        db()->prepare('UPDATE budget_lines SET amount = ?, calc = ?, cell_note = ?, prev_budget = ?, prev_actual = ?, actual = ?, updated_at = ? WHERE id = ?')
            ->execute([$v['amount'], $v['calc'], $v['cell_note'], $v['prev_budget'], $v['prev_actual'], $v['actual'], now_iso(), $p['id']]);
        touch_budget((int)$p['budget_id']);
    }
}

/** מחיקת שורה. שורה של העברה — נמחקת עם הצד השני. */
function line_delete(array $s, array $b): array
{
    $bud = budget_access($s, (int)($b['budget_id'] ?? 0), true);
    $st = db()->prepare('SELECT * FROM budget_lines WHERE id = ? AND budget_id = ?');
    $st->execute([(int)($b['id'] ?? 0), $bud['id']]);
    $l = $st->fetch() ?: throw new ApiError('השורה לא נמצאה', 404);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($l['transfer_id']) {
            $p = $pdo->prepare('SELECT l.id, l.budget_id, b.status FROM budget_lines l JOIN budgets b ON b.id = l.budget_id WHERE l.transfer_id = ? AND l.id != ? AND b.deleted_at IS NULL');
            $p->execute([$l['transfer_id'], $l['id']]);
            foreach ($p->fetchAll() as $x) {
                if ($x['status'] !== 'draft') throw new ApiError('הצד השני של ההעברה נמצא בהצעה נעולה', 409);
                $pdo->prepare('DELETE FROM budget_lines WHERE id = ?')->execute([$x['id']]);
                $pdo->prepare('UPDATE budget_comments SET line_id = NULL WHERE line_id = ?')->execute([$x['id']]);
                touch_budget((int)$x['budget_id']);
            }
        }
        $pdo->prepare('DELETE FROM budget_lines WHERE id = ?')->execute([$l['id']]);
        $pdo->prepare('UPDATE budget_comments SET line_id = NULL WHERE line_id = ?')->execute([$l['id']]);
        touch_budget((int)$bud['id']);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'partner_deleted' => (bool)$l['transfer_id']];
}

/** סדר השורות בטבלה של קופה וכיוון. */
function line_order(array $s, array $b): array
{
    $bud = budget_access($s, (int)($b['budget_id'] ?? 0), true);
    $st = db()->prepare('UPDATE budget_lines SET sort = ? WHERE id = ? AND budget_id = ?');
    foreach (array_map('intval', (array)($b['ids'] ?? [])) as $i => $id) $st->execute([$i, $id, $bud['id']]);
    touch_budget((int)$bud['id']);
    return ['ok' => true];
}

/**
 * העברה מקופה בהצעה הזו (הוצאה) לקופה אחרת (הכנסה) — באותה הצעה, או בהצעה של ארגון אחר
 * באותו ארגון-על ובאותה שנה (חבר"ק → ארגון הקהילות).
 */
function transfer_create(array $s, array $b): array
{
    $bud = budget_access($s, (int)($b['budget_id'] ?? 0), true);
    $from = budget_fund_of($bud, (int)($b['from_fund_id'] ?? 0));
    $toFund = (int)($b['to_fund_id'] ?? 0);
    if ($toFund === $from) throw new ApiError('העברה היא בין שתי קופות שונות', 422);
    $pdo = db();
    $st = $pdo->prepare('SELECT f.*, o.parent_id FROM funds f JOIN orgs o ON o.id = f.org_id WHERE f.id = ? AND f.archived_at IS NULL AND o.deleted_at IS NULL');
    $st->execute([$toFund]);
    $tf = $st->fetch() ?: throw new ApiError('קופת היעד לא נמצאה', 404);
    $root = $bud['org']['root_id'];
    if ((int)$tf['org_id'] !== $root && (int)$tf['parent_id'] !== $root) throw new ApiError('קופת היעד לא נמצאה', 404);

    if ((int)$tf['org_id'] === (int)$bud['org_id']) {
        $toBudget = (int)$bud['id'];
    } else {
        // ההצעה של הארגון השני לאותה שנה — הטיוטה האחרונה
        $st = $pdo->prepare("SELECT id FROM budgets WHERE org_id = ? AND year = ? AND deleted_at IS NULL AND status = 'draft' ORDER BY id DESC LIMIT 1");
        $st->execute([$tf['org_id'], $bud['year']]);
        $toBudget = (int)$st->fetchColumn();
        if (!$toBudget) {
            $st = $pdo->prepare('SELECT name FROM orgs WHERE id = ?');
            $st->execute([$tf['org_id']]);
            throw new ApiError('אין טיוטת הצעה ל' . $st->fetchColumn() . ' לשנת ' . $bud['year'] . ' — קודם לפתוח אותה', 409);
        }
        org_access($s, (int)$tf['org_id'], 'editor');
    }
    $pdo->prepare('INSERT OR IGNORE INTO budget_funds (budget_id, fund_id) VALUES (?, ?)')->execute([$toBudget, $toFund]);

    $amount = money_in($b['amount'] ?? null, 'סכום', false);
    $prevB = money_in($b['prev_budget'] ?? null, 'תקציב שנה קודמת');
    $prevA = money_in($b['prev_actual'] ?? null, 'ניצול שנה קודמת');
    $base = ['amount' => $amount, 'prev_budget' => $prevB, 'prev_actual' => $prevA];
    $tid = new_lineage();
    $pdo->beginTransaction();
    try {
        $a = insert_line((int)$bud['id'], $from, 'expense', $base + ['title' => text_in($b['title_from'] ?? '', 160, 'שם השורה בקופה המעבירה', true)], $tid);
        $z = insert_line($toBudget, $toFund, 'income', $base + ['title' => text_in($b['title_to'] ?? '', 160, 'שם השורה בקופה המקבלת', true)], $tid);
        touch_budget((int)$bud['id']);
        if ($toBudget !== (int)$bud['id']) touch_budget($toBudget);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'from_line' => $a, 'to_line' => $z, 'to_budget' => $toBudget];
}

// ── שורות ריכוז ──────────────────────────────────────────────

function srow_save(array $s, array $b): array
{
    $bud = budget_access($s, (int)($b['budget_id'] ?? 0), true);
    $section = text_in($b['section'] ?? '', 120, 'כותרת הטבלה', true);
    $label = text_in($b['label'] ?? '', 160, 'שם השורה', true);
    $id = (int)($b['id'] ?? 0);
    if ($id) {
        $st = db()->prepare('UPDATE summary_rows SET section = ?, label = ? WHERE id = ? AND budget_id = ?');
        $st->execute([$section, $label, $id, $bud['id']]);
        if (!$st->rowCount()) throw new ApiError('שורת הריכוז לא נמצאה', 404);
    } else {
        $dir = one_of($b['direction'] ?? '', DIRECTIONS, 'כיוון');
        $st = db()->prepare('SELECT COALESCE(MAX(sort), -1) + 1 FROM summary_rows WHERE budget_id = ?');
        $st->execute([$bud['id']]);
        db()->prepare('INSERT INTO summary_rows (budget_id, direction, section, label, sort) VALUES (?, ?, ?, ?, ?)')
            ->execute([$bud['id'], $dir, $section, $label, (int)$st->fetchColumn()]);
        $id = (int)db()->lastInsertId();
        // שיוך קופות: כל הקופות שנבחרו מקבלות את השורה כברירת מחדל לכיוון הזה
        $col = $dir === 'income' ? 'row_income' : 'row_expense';
        $st = db()->prepare("UPDATE budget_funds SET $col = ? WHERE budget_id = ? AND fund_id = ?");
        foreach (array_map('intval', (array)($b['fund_ids'] ?? [])) as $fid) $st->execute([$id, $bud['id'], $fid]);
    }
    touch_budget((int)$bud['id']);
    return ['ok' => true, 'id' => $id];
}

function srow_delete(array $s, array $b): array
{
    $bud = budget_access($s, (int)($b['budget_id'] ?? 0), true);
    $id = (int)($b['id'] ?? 0);
    $pdo = db();
    $pdo->prepare('UPDATE budget_lines SET summary_row_id = NULL WHERE budget_id = ? AND summary_row_id = ?')->execute([$bud['id'], $id]);
    $pdo->prepare('UPDATE budget_funds SET row_income = NULL WHERE budget_id = ? AND row_income = ?')->execute([$bud['id'], $id]);
    $pdo->prepare('UPDATE budget_funds SET row_expense = NULL WHERE budget_id = ? AND row_expense = ?')->execute([$bud['id'], $id]);
    $pdo->prepare('DELETE FROM summary_rows WHERE id = ? AND budget_id = ?')->execute([$id, $bud['id']]);
    touch_budget((int)$bud['id']);
    return ['ok' => true];
}

function srow_order(array $s, array $b): array
{
    $bud = budget_access($s, (int)($b['budget_id'] ?? 0), true);
    $st = db()->prepare('UPDATE summary_rows SET sort = ? WHERE id = ? AND budget_id = ?');
    foreach (array_map('intval', (array)($b['ids'] ?? [])) as $i => $id) $st->execute([$i, $id, $bud['id']]);
    return ['ok' => true];
}

// ── הערות ────────────────────────────────────────────────────

/** כל חבר בארגון מעיר — גם צופה, וגם על הצעה נעולה. */
function comment_add(array $s, array $b): array
{
    $bud = budget_access($s, (int)($b['budget_id'] ?? 0));
    $body = text_in($b['body'] ?? '', 2000, 'ההערה', true);
    $line = (int)($b['line_id'] ?? 0) ?: null;
    if ($line) {
        $st = db()->prepare('SELECT 1 FROM budget_lines WHERE id = ? AND budget_id = ?');
        $st->execute([$line, $bud['id']]);
        if (!$st->fetch()) throw new ApiError('השורה לא נמצאה', 404);
    }
    db()->prepare('INSERT INTO budget_comments (budget_id, line_id, user_id, body, at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$bud['id'], $line, $s['id'], $body, now_iso()]);
    return ['ok' => true, 'id' => (int)db()->lastInsertId()];
}

/** סימון "טופל" (או ביטול) — מי שכתב, או עורך. מחיקה — רק מי שכתב. */
function comment_set(array $s, array $b): array
{
    $bud = budget_access($s, (int)($b['budget_id'] ?? 0));
    $st = db()->prepare('SELECT * FROM budget_comments WHERE id = ? AND budget_id = ?');
    $st->execute([(int)($b['id'] ?? 0), $bud['id']]);
    $c = $st->fetch() ?: throw new ApiError('ההערה לא נמצאה', 404);
    $mine = (int)$c['user_id'] === (int)$s['id'];
    $op = (string)($b['op'] ?? '');
    if ($op === 'delete') {
        if (!$mine) throw new ApiError('רק מי שכתב מוחק הערה', 403);
        db()->prepare('DELETE FROM budget_comments WHERE id = ?')->execute([$c['id']]);
    } elseif ($op === 'resolve' || $op === 'reopen') {
        if (!$mine && !canEditRole($bud['org']['role'])) throw new ApiError('אין לך הרשאה', 403);
        db()->prepare('UPDATE budget_comments SET resolved_at = ?, resolved_by = ? WHERE id = ?')
            ->execute($op === 'resolve' ? [now_iso(), $s['id'], $c['id']] : [null, null, $c['id']]);
    } else {
        throw new ApiError('פעולה לא מוכרת', 422);
    }
    return ['ok' => true];
}
