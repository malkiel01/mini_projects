<?php
/**
 * ייבוא ארגון מקובץ JSON — ארגון, תתי-ארגונים, קופות והצעות של כמה שנים, בבת אחת.
 * כך נקלטו הצעות 2025–2026 של ארגון הקהילות וחבר"ק. המבנה המלא: README, "קובץ ייבוא".
 *
 * מה הייבוא עושה מעבר להעתקה:
 *  - שורה עם monthly: אם monthly × חודשים (עם השינוי) = amount → חישוב חודשי; אחרת סכום + הערה בתא.
 *  - העברות: transfer = מפתח קבוצה. בכל קבוצה שורת הכנסה אחת (המקבלת) והוצאות (המעבירות);
 *    הסכום של המקבלת חייב להיות סכום המעבירות — אחרת הייבוא נעצר ואומר איפה.
 *  - שנים רצופות: הניצול של שנה N נלקח מעמודת "ניצול" של N+1 (אותה קופה, כיוון ושם),
 *    ותאריך "ניצול עד" — מ-N+1. כך 2027 שתיפתח מ-2026 תקבל את הניצול הנכון.
 */

declare(strict_types=1);

require_once __DIR__ . '/budgets.php';

const IMPORT_FORMAT = 'org-budget-proposals/1';
const IMPORT_MAX_BYTES = 2_000_000;

function org_import(array $s, array $b): array
{
    $data = $b['data'] ?? null;
    if (!is_array($data)) throw new ApiError('הקובץ ריק או אינו JSON', 422);
    if (strlen(json_encode($data)) > IMPORT_MAX_BYTES) throw new ApiError('הקובץ גדול מדי (עד 2MB)', 422);
    if (($data['format'] ?? '') !== IMPORT_FORMAT) throw new ApiError('זה לא קובץ ייבוא של הצעות תקציב (format: ' . IMPORT_FORMAT . ')', 422);
    $root = $data['org'] ?? null;
    if (!is_array($root)) throw new ApiError('חסר org בקובץ', 422);

    $pdo = db();
    $uid = bin2hex(random_bytes(6));   // מזהה הייבוא — לגזירת זהות שורות והעברות
    $ctx = ['uid' => $uid, 'stats' => ['orgs' => 0, 'budgets' => 0, 'lines' => 0, 'transfers' => 0], 'groups' => [], 'warnings' => []];
    $pdo->beginTransaction();
    try {
        $rootId = import_org($s, $root, null, $ctx);
        foreach ((array)($root['subs'] ?? []) as $i => $sub) {
            if (!is_array($sub)) throw new ApiError("subs[$i]: לא תקין", 422);
            import_org($s, $sub, $rootId, $ctx);
        }
        import_check_transfers($ctx);
        org_event($rootId, $s, 'org_import', ['name' => $root['name'] ?? '', 'budgets' => $ctx['stats']['budgets']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'org_id' => $rootId] + $ctx['stats'] + ['warnings' => $ctx['warnings']];
}

function import_org(array $s, array $o, ?int $parent, array &$ctx): int
{
    $pdo = db();
    $now = now_iso();
    $name = text_in($o['name'] ?? '', 120, 'שם הארגון', true);
    $pdo->prepare('INSERT INTO orgs (parent_id, name, subtitle, notes, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$parent, $name, text_in($o['subtitle'] ?? '', 160, 'תת-כותרת'), text_in($o['notes'] ?? '', 2000, 'הערות'), $s['id'], $now, $now]);
    $orgId = (int)$pdo->lastInsertId();
    $ctx['stats']['orgs']++;
    $rootId = $parent ?? $orgId;
    if (!$parent) {
        $pdo->prepare("INSERT INTO org_members (org_id, user_id, role, added_by, added_at) VALUES (?, ?, 'owner', ?, ?)")->execute([$orgId, $s['id'], $s['id'], $now]);
    }

    // קופות — לפי מפתח
    $funds = [];
    foreach ((array)($o['funds'] ?? []) as $i => $f) {
        $key = (string)($f['key'] ?? '');
        if ($key === '' || isset($funds[$key])) throw new ApiError("$name: לקופה $i חסר מפתח (key) ייחודי", 422);
        $pdo->prepare('INSERT INTO funds (org_id, kind, name, address, bank, notes, sort, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$orgId, one_of($f['kind'] ?? 'operating', FUND_KINDS, "$name: סוג הקופה $key"), text_in($f['name'] ?? '', 120, 'שם הקופה', true),
                text_in($f['address'] ?? '', 160, 'כתובת'), text_in($f['bank'] ?? '', 160, 'חשבון בנק'), text_in($f['notes'] ?? '', 2000, 'הערות'), $i, $now]);
        $funds[$key] = (int)$pdo->lastInsertId();
    }
    if (!$funds) throw new ApiError("$name: אין קופות", 422);

    foreach ((array)($o['contacts'] ?? []) as $c) {
        $pdo->prepare('INSERT INTO contacts (org_id, kind, name, phone, email, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$rootId, one_of($c['kind'] ?? 'customer', CONTACT_KINDS, 'סוג גורם'), text_in($c['name'] ?? '', 120, 'שם גורם', true),
                text_in($c['phone'] ?? '', 40, 'טלפון'), text_in($c['email'] ?? '', 190, 'מייל'), text_in($c['notes'] ?? '', 2000, 'הערות'), $now]);
    }
    foreach ((array)($o['categories'] ?? []) as $c) {
        $pdo->prepare('INSERT INTO categories (org_id, kind, name, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$rootId, one_of($c['kind'] ?? 'expense', CAT_KINDS, 'סוג סעיף'), text_in($c['name'] ?? '', 120, 'שם סעיף', true), $now]);
    }

    $budgets = (array)($o['budgets'] ?? []);
    usort($budgets, fn($a, $b) => (int)($a['year'] ?? 0) <=> (int)($b['year'] ?? 0));
    $byYear = [];
    foreach ($budgets as $bd) $byYear[(int)$bd['year']] = import_budget($s, $bd, $orgId, $name, $funds, $ctx);
    import_backfill_actual($byYear);
    return $orgId;
}

function import_budget(array $s, array $bd, int $orgId, string $orgName, array $funds, array &$ctx): int
{
    $pdo = db();
    $now = now_iso();
    $year = (int)($bd['year'] ?? 0);
    if ($year < 2000 || $year > 2100) throw new ApiError("$orgName: שנה לא תקינה", 422);
    $where = "$orgName $year";
    $signers = [];
    foreach ((array)($bd['signers'] ?? []) as $sg) {
        $signers[] = ['name' => text_in($sg['name'] ?? '', 80, 'חותם', true),
            'titles' => array_values(array_filter(array_map(fn($t) => text_in($t, 80, 'תפקיד'), (array)($sg['titles'] ?? []))))];
    }
    $pdo->prepare('INSERT INTO budgets (org_id, year, title, status, submitted_to, signers, work_plan, summary_note, prev_actual_as_of, actual_as_of,
            expense_first, created_by, created_at, updated_at, status_at, status_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$orgId, $year, text_in($bd['title'] ?? '', 80, 'כותרת'), one_of($bd['status'] ?? 'draft', BUDGET_STATUS, "$where: מצב"),
            text_in($bd['submitted_to'] ?? '', 200, 'מוגשת ל'), json_encode($signers, JSON_UNESCAPED_UNICODE),
            text_in($bd['work_plan'] ?? '', 30000, 'תכנית העבודה'), text_in($bd['summary_note'] ?? '', 500, 'הערת הריכוז'),
            date_in($bd['prev_actual_as_of'] ?? '', "$where: ניצול עד"), date_in($bd['actual_as_of'] ?? '', "$where: ניצול השנה עד"),
            empty($bd['expense_first']) ? 0 : 1, $s['id'], $now, $now, $now, $s['id']]);
    $bid = (int)$pdo->lastInsertId();
    $ctx['stats']['budgets']++;

    $notes = (array)($bd['funds'] ?? []);
    foreach ($funds as $key => $fid) {
        $n = (array)($notes[$key] ?? []);
        $pdo->prepare('INSERT INTO budget_funds (budget_id, fund_id, notes_income, notes_expense) VALUES (?, ?, ?, ?)')
            ->execute([$bid, $fid, text_in($n['notes_income'] ?? '', 3000, 'הערות'), text_in($n['notes_expense'] ?? '', 3000, 'הערות')]);
    }

    // שורות ריכוז; funds — ברירת המחדל של הקופות האלה לכיוון של השורה
    $rows = [];
    foreach ((array)($bd['summary'] ?? []) as $i => $r) {
        $dir = one_of($r['direction'] ?? '', DIRECTIONS, "$where: כיוון שורת ריכוז");
        $pdo->prepare('INSERT INTO summary_rows (budget_id, direction, section, label, sort) VALUES (?, ?, ?, ?, ?)')
            ->execute([$bid, $dir, text_in($r['section'] ?? '', 120, 'מקטע', true), text_in($r['label'] ?? '', 160, 'שורת ריכוז', true), $i]);
        $rid = (int)$pdo->lastInsertId();
        if (isset($r['key'])) $rows[(string)$r['key']] = [$rid, $dir];
        $col = $dir === 'income' ? 'row_income' : 'row_expense';
        foreach ((array)($r['funds'] ?? []) as $fk) {
            if (!isset($funds[$fk])) throw new ApiError("$where: שורת ריכוז מפנה לקופה לא מוכרת \"$fk\"", 422);
            $pdo->prepare("UPDATE budget_funds SET $col = ? WHERE budget_id = ? AND fund_id = ?")->execute([$rid, $bid, $funds[$fk]]);
        }
    }

    $contacts = [];
    $st = $pdo->prepare('SELECT id, name FROM contacts WHERE org_id = (SELECT COALESCE(parent_id, id) FROM orgs WHERE id = ?)');
    $st->execute([$orgId]);
    foreach ($st->fetchAll() as $c) $contacts[$c['name']] = (int)$c['id'];

    // שם שחוזר פעמיים באותה קופה וכיוון (במסמך 2025: שתי שורות "הלל 24") — בלי זהות משותפת לשנה אחרת,
    // אחרת הניצול של שורה אחת היה נכתב לשתיהן
    $seen = [];
    foreach ((array)($bd['lines'] ?? []) as $l) {
        $k = ($l['fund'] ?? '') . '|' . ($l['direction'] ?? '') . '|' . trim((string)($l['title'] ?? ''));
        $seen[$k] = ($seen[$k] ?? 0) + 1;
    }
    foreach ((array)($bd['lines'] ?? []) as $i => $l) {
        $fk = (string)($l['fund'] ?? '');
        if (!isset($funds[$fk])) throw new ApiError("$where, שורה " . ($i + 1) . ": קופה לא מוכרת \"$fk\"", 422);
        $dir = one_of($l['direction'] ?? '', DIRECTIONS, "$where, שורה " . ($i + 1) . ': כיוון');
        $title = text_in($l['title'] ?? '', 160, 'שם הסעיף', true);
        $v = [
            'title' => $title, 'subtitle' => text_in($l['subtitle'] ?? '', 200, 'שורה שנייה'),
            'contact_id' => isset($l['contact']) ? ($contacts[$l['contact']] ?? null) : null,
            'prev_budget' => money_in($l['prev_budget'] ?? null, 'תקציב קודם'), 'prev_actual' => money_in($l['prev_actual'] ?? null, 'ניצול קודם'),
            'actual' => money_in($l['actual'] ?? null, 'ניצול'), 'cell_note' => text_in($l['note'] ?? '', 160, 'הערה בתא'),
            'summary_only' => empty($l['summary_only']) ? 0 : 1,
            'lineage' => $seen["$fk|$dir|$title"] > 1 ? new_lineage() : substr(hash('sha256', "{$ctx['uid']}:$orgId:$fk:$dir:$title"), 0, 16),
        ];
        $amount = money_in($l['amount'] ?? null, 'סכום');
        if (isset($l['monthly'])) {
            $calc = ['monthly' => $l['monthly'], 'from' => $l['from'] ?? 1, 'to' => $l['to'] ?? 12,
                'change_month' => $l['change_month'] ?? 0, 'change_pct' => $l['change_pct'] ?? 0];
            [$calcAmount, $calcNote, $norm] = calc_amount($calc);
            if ($amount === null || $amount === $calcAmount) {
                $v['amount'] = $calcAmount;
                $v['calc'] = json_encode($norm);
            } else {
                // במסמך: 279 ₪ לחודש מול 3,400 לשנה — נשמר כמו במסמך, עם ההערה
                $v['amount'] = $amount;
                $v['cell_note'] ??= number_format((int)money_in($l['monthly'], 'חודשי')) . ' ₪ לחודש';
            }
        } else {
            $v['amount'] = $amount ?? 0;
        }
        if (isset($l['summary'])) {
            [$rid, $rdir] = $rows[(string)$l['summary']] ?? throw new ApiError("$where, \"$title\": שורת ריכוז לא מוכרת \"{$l['summary']}\"", 422);
            if ($rdir !== $dir) throw new ApiError("$where, \"$title\": שורת הריכוז בכיוון אחר", 422);
            $v['summary_row_id'] = $rid;
        }
        if (!empty($l['lease'])) {
            if ($dir !== 'income') throw new ApiError("$where, \"$title\": שכירות רק בשורת הכנסה", 422);
            $v = array_merge($v, lease_values(['lease' => is_array($l['lease']) ? $l['lease'] : []], 'income'));
        }
        $tid = null;
        if (isset($l['transfer'])) {
            $gk = $year . ':' . $l['transfer'];
            $tid = substr(hash('sha256', "{$ctx['uid']}:$gk"), 0, 16);
            $ctx['groups'][$gk] ??= ['tid' => $tid, 'where' => "$year, העברה \"{$l['transfer']}\""];
        }
        $id = insert_line($bid, $funds[$fk], $dir, $v, $tid);
        $pdo->prepare('UPDATE budget_lines SET sort = ? WHERE id = ?')->execute([$i, $id]);
        $ctx['stats']['lines']++;
    }
    return $bid;
}

/** כל קבוצת העברה: הכנסה אחת, הוצאה אחת לפחות, והסכומים מתאימים. */
function import_check_transfers(array &$ctx): void
{
    foreach ($ctx['groups'] as $g) {
        $lines = transfer_group($g['tid']);
        $in = array_values(array_filter($lines, fn($l) => $l['direction'] === 'income'));
        $out = array_values(array_filter($lines, fn($l) => $l['direction'] === 'expense'));
        if (count($in) !== 1 || !$out) throw new ApiError("{$g['where']}: צריך שורת הכנסה אחת ולפחות שורת הוצאה אחת", 422);
        $sum = array_sum(array_map(fn($l) => (int)$l['amount'], $out));
        if ($sum !== (int)$in[0]['amount']) {
            throw new ApiError("{$g['where']}: ההכנסה \"{$in[0]['title']}\" היא " . number_format((int)$in[0]['amount']) . ', וסכום ההוצאות ' . number_format($sum), 422);
        }
        $ctx['stats']['transfers']++;
    }
}

/** ניצול שנה N ← עמודת "ניצול" של N+1 (אותה זהות שורה). רק כשלשורה של N אין ניצול. */
function import_backfill_actual(array $byYear): void
{
    $pdo = db();
    foreach ($byYear as $year => $bid) {
        $next = $byYear[$year + 1] ?? null;
        if (!$next) continue;
        $st = $pdo->prepare('SELECT lineage, prev_actual FROM budget_lines WHERE budget_id = ? AND prev_actual IS NOT NULL');
        $st->execute([$next]);
        $up = $pdo->prepare('UPDATE budget_lines SET actual = ? WHERE budget_id = ? AND lineage = ? AND actual IS NULL');
        foreach ($st->fetchAll() as $r) $up->execute([$r['prev_actual'], $bid, $r['lineage']]);
        $pdo->prepare('UPDATE budgets SET actual_as_of = COALESCE(actual_as_of, (SELECT prev_actual_as_of FROM budgets WHERE id = ?)) WHERE id = ?')->execute([$next, $bid]);
    }
}
