<?php
/**
 * שיוכים של קבצים — "נקודה" (ספק × שלב), ושני עצים שמציגים אותה (README: "שיוך דו-צדדי").
 *
 * שיוך = הקובץ שייך לספק בשלב: מהצעה, חוזה, תוספת, אבן דרך (מסמך / חשבונית / אסמכתא), דיווח מהשטח,
 * מסמך (file_refs) — או כי הוא יושב בתיקייה של ספק במקצוע. לקובץ יכולות להיות כמה נקודות.
 * העצים מחושבים מהנקודות, לא מעתיקים קבצים — שיוך שהוסר נעלם משניהם, ושום דבר לא "נשכח" בתיקייה:
 *   🧰 ספקים:  ספק ← מקצוע ← קבצים          (trade; במתחם: ספק ← "חשמל" ← דירה — tgroup)
 *   🧱 שלבים:  שלב ← ספק ← קבצים            (stage → ssup; במתחם: "חשמל" ← דירה ← ספק — sgroup)
 *              ובשלב עצמו — מה ששייך לשלב בלי ספק (תמונות מהשטח של השלב).
 * הסרת שיוך: pm_file_unlink_use — הרשומה נשארת, בלי הקובץ; קובץ שלא נשאר לו שיוך — חוזר ל"ממתין למיון".
 */

declare(strict_types=1);

/** SQL של כל הנקודות בפרויקט: (file_id, supplier_id, stage_id). $pid — מספר, משובץ ישירות. */
function pm_coords_sql(int $pid): string
{
    return "SELECT c.file_id, c.supplier_id, c.stage_id FROM (
        SELECT file_id, supplier_id, stage_id FROM quotes WHERE project_id = $pid AND file_id IS NOT NULL
        UNION SELECT k.file_id, k.supplier_id, CAST(j.value AS INTEGER) FROM contracts k,
               json_each(CASE WHEN json_valid(k.stage_ids) AND json_array_length(k.stage_ids) > 0 THEN k.stage_ids ELSE '[null]' END) j
         WHERE k.project_id = $pid AND k.file_id IS NOT NULL
        UNION SELECT file_id, supplier_id, stage_id FROM change_orders WHERE project_id = $pid AND file_id IS NOT NULL
        UNION SELECT file_id, supplier_id, stage_id FROM payments WHERE project_id = $pid AND file_id IS NOT NULL
        UNION SELECT invoice_file_id, supplier_id, stage_id FROM payments WHERE project_id = $pid AND invoice_file_id IS NOT NULL
        UNION SELECT x.file_id, p.supplier_id, p.stage_id FROM payment_proofs x JOIN payments p ON p.id = x.payment_id
         WHERE p.project_id = $pid AND x.file_id IS NOT NULL
        UNION SELECT rf.file_id, r.supplier_id, r.stage_id FROM report_files rf JOIN reports r ON r.id = rf.report_id
         WHERE r.project_id = $pid AND r.deleted_at IS NULL
        UNION SELECT file_id, supplier_id, stage_id FROM file_refs WHERE project_id = $pid
        UNION SELECT l.file_id, d.contact_id, d.stage_id FROM file_links l JOIN folders d ON d.id = l.folder_id
         WHERE d.project_id = $pid AND d.kind IN ('trade', 'ssup', 'stage')
    ) c JOIN files f ON f.id = c.file_id AND f.deleted_at IS NULL WHERE c.supplier_id IS NOT NULL OR c.stage_id IS NOT NULL";
}

/** כל הנקודות: [[file_id, supplier_id|null, stage_id|null], ...] */
function pm_coords(int $pid): array
{
    return array_map(fn($r) => [(int)$r['file_id'], $r['supplier_id'] !== null ? (int)$r['supplier_id'] : null, $r['stage_id'] !== null ? (int)$r['stage_id'] : null],
        pm_db()->query(pm_coords_sql($pid))->fetchAll());
}

/**
 * מה שתיקייה מציגה מעבר לקבצים שבה: [SQL על c (נקודה), args] או null.
 * ספק / מקצוע ושלב / ספק — הנקודה; ספק — מה ששלו בלי שלב; שלב — מה ששלו בלי ספק.
 */
function pm_folder_virtual(array $f): ?array
{
    $k = $f['kind'];
    $sid = isset($f['stage_id']) && $f['stage_id'] !== null ? (int)$f['stage_id'] : null;
    $cid = isset($f['contact_id']) && $f['contact_id'] !== null ? (int)$f['contact_id'] : null;
    if (in_array($k, ['trade', 'ssup'], true) && $sid && $cid) return ['(c.supplier_id = ? AND c.stage_id = ?)', [$cid, $sid]];
    if ($k === 'contact' && $cid && $f['parent_id'] === null) return ['(c.supplier_id = ? AND c.stage_id IS NULL)', [$cid]];
    if ($k === 'stage' && $sid) return ['(c.stage_id = ? AND c.supplier_id IS NULL)', [$sid]];
    return null;
}

/** תנאי SQL על x.id לתוכן תיקיות (קבצים שבהן + הנקודות שלהן). */
function pm_folders_files_where(int $pid, array $folders): array
{
    $ids = array_map(fn($f) => (int)$f['id'], $folders);
    $parts = ['x.id IN (SELECT file_id FROM file_links WHERE folder_id IN (' . implode(',', array_fill(0, count($ids), '?')) . '))'];
    $args = $ids;
    $v = [];
    foreach ($folders as $f) if ($q = pm_folder_virtual($f)) { $v[] = $q[0]; array_push($args, ...$q[1]); }
    if ($v) $parts[] = 'x.id IN (SELECT c.file_id FROM (' . pm_coords_sql($pid) . ') c WHERE ' . implode(' OR ', $v) . ')';
    return ['(' . implode(' OR ', $parts) . ')', $args];
}

/** כמה קבצים בכל תיקייה — כולל הנקודות. folder_id => n */
function pm_folder_counts(int $pid, array $folders): array
{
    $db = pm_db();
    $sets = [];
    $l = $db->prepare('SELECT l.folder_id, l.file_id FROM file_links l JOIN files x ON x.id = l.file_id AND x.deleted_at IS NULL WHERE x.project_id = ?');
    $l->execute([$pid]);
    foreach ($l->fetchAll() as $r) $sets[(int)$r['folder_id']][(int)$r['file_id']] = true;
    $by = [];
    foreach (pm_coords($pid) as [$fid, $sup, $st]) $by[($sup ?? 0) . ':' . ($st ?? 0)][$fid] = true;
    $out = [];
    foreach ($folders as $f) {
        $id = (int)$f['id'];
        $set = $sets[$id] ?? [];
        $k = $f['kind'];
        $sid = (int)($f['stage_id'] ?? 0);
        $cid = (int)($f['contact_id'] ?? 0);
        $key = in_array($k, ['trade', 'ssup'], true) && $sid && $cid ? "$cid:$sid"
             : ($k === 'contact' && $cid && $f['parent_id'] === null ? "$cid:0" : ($k === 'stage' && $sid ? "0:$sid" : null));
        if ($key !== null) $set += $by[$key] ?? [];
        $out[$id] = count($set);
    }
    return $out;
}

/** באילו שלבים לספק יש משהו: עבודה (pm_supplier_stages) או קבצים משויכים. supplier_id => [stage_id...] */
function pm_supplier_stage_map(int $pid): array
{
    $db = pm_db();
    $map = [];
    $sup = $db->prepare("SELECT DISTINCT contact_id FROM folders WHERE project_id = ? AND kind = 'contact' AND parent_id IS NULL AND contact_id IS NOT NULL");
    $sup->execute([$pid]);
    foreach ($sup->fetchAll(PDO::FETCH_COLUMN) as $cid) foreach (pm_supplier_stages($pid, (int)$cid) as $sid) $map[(int)$cid][$sid] = true;
    foreach (pm_coords($pid) as [, $c, $s]) if ($c && $s) $map[$c][$s] = true;
    // חוזה בלי קובץ — גם הוא התקשרות
    $k = $db->prepare("SELECT k.supplier_id, CAST(j.value AS INTEGER) AS stage_id FROM contracts k,
                        json_each(CASE WHEN json_valid(k.stage_ids) THEN k.stage_ids ELSE '[]' END) j WHERE k.project_id = ?");
    $k->execute([$pid]);
    foreach ($k->fetchAll() as $r) if ($r['stage_id']) $map[(int)$r['supplier_id']][(int)$r['stage_id']] = true;
    return $map;
}

/**
 * בונה את שני העצים (ספקים ← מקצוע, שלבים ← ספק) ומנקה תיקיות שכבר לא צריך (רק ריקות).
 * רץ בכל פתיחה של הקבצים (pm_ensure_folders) — זול, ומתקן את עצמו כששלב / ספק משתנים.
 */
function pm_ensure_link_tree(int $pid): void
{
    $db = pm_db();
    $now = pm_now();
    $stages = [];
    $s = $db->prepare('SELECT id, name, unit_id, sort FROM stages WHERE project_id = ? ORDER BY sort, id');
    $s->execute([$pid]);
    foreach ($s->fetchAll() as $r) $stages[(int)$r['id']] = $r;

    // ספקים שיש להם נקודה — גם בלי הצעה / תשלום (חוזה, מסמך, דיווח מהשטח) — מקבלים תיקייה
    $roots = [];
    $r = $db->prepare("SELECT id, contact_id FROM folders WHERE project_id = ? AND kind = 'contact' AND parent_id IS NULL AND contact_id IS NOT NULL");
    $r->execute([$pid]);
    foreach ($r->fetchAll() as $x) $roots[(int)$x['contact_id']] = (int)$x['id'];
    $need = [];
    foreach (pm_coords($pid) as [, $c]) if ($c) $need[$c] = true;
    $k = $db->prepare('SELECT DISTINCT supplier_id FROM contracts WHERE project_id = ?');
    $k->execute([$pid]);
    foreach ($k->fetchAll(PDO::FETCH_COLUMN) as $c) $need[(int)$c] = true;
    $insRoot = $db->prepare("INSERT INTO folders (project_id, parent_id, kind, contact_id, name, sort, created_at) VALUES (?, NULL, 'contact', ?, ?, 40, ?)");
    foreach (array_keys($need) as $c) {
        if (isset($roots[$c])) continue;
        $n = $db->prepare("SELECT name FROM contacts WHERE id = ? AND kind = 'supplier'");
        $n->execute([$c]);
        $name = $n->fetchColumn();
        if ($name === false) continue;
        $insRoot->execute([$pid, $c, $name, $now]);
        $roots[$c] = (int)$db->lastInsertId();
    }

    $all = $db->prepare("SELECT id, parent_id, kind, contact_id, stage_id, name FROM folders WHERE project_id = ? AND kind IN ('trade', 'tgroup', 'stages', 'sgroup', 'stage', 'ssup')");
    $all->execute([$pid]);
    $have = [];
    foreach ($all->fetchAll() as $f) {
        $key = match ($f['kind']) {
            'trade', 'ssup' => $f['kind'] . ':' . (int)$f['contact_id'] . ':' . (int)$f['stage_id'],
            'tgroup' => 'tgroup:' . (int)$f['contact_id'] . ':' . $f['name'],
            'sgroup' => 'sgroup:' . $f['name'],
            'stage' => 'stage:' . (int)$f['stage_id'],
            'stages' => 'stages',
        };
        if (isset($have[$key])) continue; // כפילות ישנה — נשארת כמו שהיא
        $have[$key] = ['id' => (int)$f['id'], 'parent' => $f['parent_id'] !== null ? (int)$f['parent_id'] : null];
    }
    $wanted = [];
    /** תיקייה במקום שלה: קיימת — מעבירים מתחת להורה הנכון; חסרה — נוצרת. */
    $put = function (string $key, ?int $parent, string $kind, ?int $cid, ?int $sid, string $name, int $sort) use (&$have, &$wanted, $db, $pid, $now): int {
        $wanted[$key] = true;
        if (isset($have[$key])) {
            if ($have[$key]['parent'] !== $parent) {
                $db->prepare('UPDATE folders SET parent_id = ? WHERE id = ?')->execute([$parent, $have[$key]['id']]);
                $have[$key]['parent'] = $parent;
            }
            return $have[$key]['id'];
        }
        $db->prepare('INSERT INTO folders (project_id, parent_id, kind, contact_id, stage_id, name, sort, created_at) VALUES (?,?,?,?,?,?,?,?)')
           ->execute([$pid, $parent, $kind, $cid, $sid, $name, $sort, $now]);
        $have[$key] = ['id' => (int)$db->lastInsertId(), 'parent' => $parent];
        return $have[$key]['id'];
    };
    // קבוצות של שלבים באותו שם (מתחם: "חשמל" בכל דירה)
    $groups = [];
    foreach ($stages as $id => $st) $groups[$st['name']][] = $id;

    // 🧰 ספק ← מקצוע (במתחם: ספק ← "חשמל" ← דירה)
    $map = pm_supplier_stage_map($pid);
    foreach ($map as $c => $set) {
        if (!isset($roots[$c])) continue;
        $mine = [];
        foreach (array_keys($set) as $sid) if (isset($stages[$sid])) $mine[$stages[$sid]['name']][] = $sid;
        foreach ($mine as $name => $ids) {
            $parent = $roots[$c];
            if (count($groups[$name]) > 1) $parent = $put("tgroup:$c:$name", $roots[$c], 'tgroup', $c, null, $name, (int)$stages[$ids[0]]['sort']);
            foreach ($ids as $sid) $put("trade:$c:$sid", $parent, 'trade', $c, $sid, $name, (int)$stages[$sid]['sort']);
        }
    }

    // 🧱 שלבים ← ספק (במתחם: "חשמל" ← דירה ← ספק)
    if ($stages) {
        $root = $put('stages', null, 'stages', null, null, PM_SYSTEM_FOLDERS['stages'][0], PM_SYSTEM_FOLDERS['stages'][2]);
        $bySt = [];
        foreach ($map as $c => $set) if (isset($roots[$c])) foreach (array_keys($set) as $sid) $bySt[$sid][] = $c;
        foreach ($groups as $name => $ids) {
            $parent = count($ids) > 1 ? $put("sgroup:$name", $root, 'sgroup', null, null, $name, (int)$stages[$ids[0]]['sort']) : $root;
            foreach ($ids as $sid) {
                $sf = $put("stage:$sid", $parent, 'stage', null, $sid, $name, (int)$stages[$sid]['sort']);
                foreach ($bySt[$sid] ?? [] as $c) $put("ssup:$c:$sid", $sf, 'ssup', $c, $sid, '', 0);
            }
        }
    }

    // ניקוי: תיקייה מחושבת שכבר לא צריך — רק אם ריקה (בלי קבצים ובלי תת-תיקיות). קודם העלים.
    foreach (['ssup', 'trade', 'stage', 'tgroup', 'sgroup'] as $kind) {
        foreach ($have as $key => $f) {
            if (isset($wanted[$key]) || !str_starts_with($key, "$kind:")) continue;
            $c = $db->prepare('SELECT (SELECT COUNT(*) FROM folders WHERE parent_id = ?) + (SELECT COUNT(*) FROM file_links WHERE folder_id = ?)');
            $c->execute([$f['id'], $f['id']]);
            if ((int)$c->fetchColumn() === 0) { $db->prepare('DELETE FROM folders WHERE id = ?')->execute([$f['id']]); unset($have[$key]); }
        }
    }
}

/** שם לתצוגה של תיקייה מחושבת: מקצוע, דירה, ספק. null — השם השמור. */
function pm_link_folder_name(array $f, array $byId): ?string
{
    $sid = $f['stage_id'] !== null ? (int)$f['stage_id'] : 0;
    $parent = $f['parent_id'] !== null ? ($byId[(int)$f['parent_id']] ?? null) : null;
    $unit = function (int $sid): string {
        $st = pm_db()->prepare('SELECT u.name FROM stages s LEFT JOIN units u ON u.id = s.unit_id WHERE s.id = ?');
        $st->execute([$sid]);
        return (string)($st->fetchColumn() ?: '');
    };
    return match ($f['kind']) {
        // בתוך קבוצה ("חשמל") — רק הדירה; אחרת השם המלא
        'trade', 'stage' => $sid ? (in_array($parent['kind'] ?? '', ['tgroup', 'sgroup'], true) ? ($unit($sid) ?: pm_t('משותף')) : (pm_trade_folder_name($sid) ?: null)) : null,
        'ssup' => $f['contact_name'] ?: null,
        default => null,
    };
}

/** מה שקובץ מראה ב"שימוש במערכת": שם השלב לכל שיוך. */
function pm_uses_stage_names(array &$uses): void
{
    $ids = [];
    foreach ($uses as $list) foreach ($list as $u) if (!empty($u['stage_id'])) $ids[$u['stage_id']] = true;
    $names = [];
    foreach (array_keys($ids) as $sid) $names[$sid] = pm_trade_folder_name((int)$sid);
    foreach ($uses as &$list) foreach ($list as &$u) $u['stage'] = !empty($u['stage_id']) ? ($names[$u['stage_id']] ?? '') : '';
}

/**
 * הסרת שיוך מחלון הקובץ: {file_id, kind, id, supplier_id?, stage_id?}. הרשומה נשארת — בלי הקובץ ("חסר מסמך").
 * דיווח מהשטח: התמונה יוצאת מהדיווח; דיווח שלא נשאר בו כלום (בלי טקסט ותמונות, לא "בוצע") — נמחק.
 * קובץ שלא נשאר לו שום שיוך — חוזר ל"ממתין למיון", כדי שיראו שצריך לשייך אותו מחדש.
 */
function pm_file_unlink_use(array $user, array $in): array
{
    $x = pm_file_row($user, (int)($in['file_id'] ?? 0));
    $fid = (int)$x['id'];
    $pid = (int)$x['project_id'];
    $id = (int)($in['id'] ?? 0);
    $kind = (string)($in['kind'] ?? '');
    $db = pm_db();
    $one = function (string $sql, array $args) use ($db) { $st = $db->prepare($sql); $st->execute($args); return $st->fetch() ?: null; };
    $what = '';
    switch ($kind) {
        case 'quote':
            $r = $one('SELECT q.id, s.name AS what FROM quotes q JOIN stages s ON s.id = q.stage_id WHERE q.id = ? AND q.file_id = ? AND q.project_id = ?', [$id, $fid, $pid]);
            if ($r) $db->prepare('UPDATE quotes SET file_id = NULL WHERE id = ?')->execute([$id]);
            $what = 'הצעת מחיר'; // i18n-ok — שם לציר הזמן
            break;
        case 'contract':
            $r = $one('SELECT id FROM contracts WHERE id = ? AND file_id = ? AND project_id = ?', [$id, $fid, $pid]);
            if ($r) $db->prepare('UPDATE contracts SET file_id = NULL WHERE id = ?')->execute([$id]);
            $what = 'חוזה'; // i18n-ok — שם לציר הזמן
            break;
        case 'change':
            $r = $one('SELECT id FROM change_orders WHERE id = ? AND file_id = ? AND project_id = ?', [$id, $fid, $pid]);
            if ($r) $db->prepare('UPDATE change_orders SET file_id = NULL WHERE id = ?')->execute([$id]);
            $what = 'תוספת / חריגה'; // i18n-ok — שם לציר הזמן
            break;
        case 'payment':
            $r = $one('SELECT id FROM payments WHERE id = ? AND file_id = ? AND project_id = ?', [$id, $fid, $pid]);
            if ($r) $db->prepare('UPDATE payments SET file_id = NULL WHERE id = ?')->execute([$id]);
            $what = 'אבן דרך'; // i18n-ok — שם לציר הזמן
            break;
        case 'invoice':
            $r = $one('SELECT id FROM payments WHERE id = ? AND invoice_file_id = ? AND project_id = ?', [$id, $fid, $pid]);
            if ($r) $db->prepare('UPDATE payments SET invoice_file_id = NULL WHERE id = ?')->execute([$id]);
            $what = 'חשבונית'; // i18n-ok — שם לציר הזמן
            break;
        case 'proof':
            $r = $one('SELECT x.id FROM payment_proofs x JOIN payments p ON p.id = x.payment_id WHERE x.id = ? AND x.file_id = ? AND p.project_id = ?', [$id, $fid, $pid]);
            if ($r) $db->prepare('UPDATE payment_proofs SET file_id = NULL WHERE id = ?')->execute([$id]);
            $what = 'אסמכתא'; // i18n-ok — שם לציר הזמן
            break;
        case 'expense':
            $r = $one('SELECT id FROM expenses WHERE id = ? AND file_id = ? AND project_id = ?', [$id, $fid, $pid]);
            if ($r) $db->prepare('UPDATE expenses SET file_id = NULL WHERE id = ?')->execute([$id]);
            $what = 'הוצאה ישירה'; // i18n-ok — שם לציר הזמן
            break;
        case 'report':
            $r = $one('SELECT r.id, r.kind, r.text FROM report_files f JOIN reports r ON r.id = f.report_id WHERE f.report_id = ? AND f.file_id = ? AND r.project_id = ?', [$id, $fid, $pid]);
            if ($r) {
                $db->prepare('DELETE FROM report_files WHERE report_id = ? AND file_id = ?')->execute([$id, $fid]);
                $left = $one('SELECT COUNT(*) AS n FROM report_files WHERE report_id = ?', [$id]);
                if ((int)$left['n'] === 0 && trim((string)$r['text']) === '' && $r['kind'] !== 'done') {
                    $db->prepare('UPDATE reports SET deleted_at = ? WHERE id = ?')->execute([pm_now(), $id]);
                }
            }
            $what = 'דיווח מהשטח'; // i18n-ok — שם לציר הזמן
            break;
        case 'doc':
            $stageId = (int)($in['stage_id'] ?? 0) ?: null;
            $r = $one('SELECT file_id FROM file_refs WHERE file_id = ? AND supplier_id = ? AND stage_id IS ?', [$fid, $id, $stageId]);
            if ($r) $db->prepare('DELETE FROM file_refs WHERE file_id = ? AND supplier_id = ? AND stage_id IS ?')->execute([$fid, $id, $stageId]);
            $what = 'מסמך של הספק'; // i18n-ok — שם לציר הזמן
            break;
        default:
            throw new PmError('סוג שיוך לא מוכר', 422);
    }
    if (!$r) throw new PmError('השיוך כבר לא קיים', 404);

    // בלי שום שיוך — חזרה ל"ממתין למיון"
    $back = false;
    if (empty(pm_file_uses([$fid])[$fid])) {
        $ib = $one("SELECT id FROM folders WHERE project_id = ? AND kind = 'inbox' AND parent_id IS NULL", [$pid]);
        $home = $one('SELECT folder_id FROM file_links WHERE file_id = ? AND is_home = 1', [$fid]);
        if ($ib && (int)($home['folder_id'] ?? 0) !== (int)$ib['id']) {
            $db->prepare('DELETE FROM file_links WHERE file_id = ? AND folder_id = ?')->execute([$fid, (int)$ib['id']]);
            $db->prepare('UPDATE file_links SET folder_id = ? WHERE file_id = ? AND is_home = 1')->execute([(int)$ib['id'], $fid]);
            $back = true;
        }
    }
    pm_event($user, $pid, 'file', $back ? '✂️ {name} — הוסר השיוך ל{what}, וחזר ל"ממתין למיון"' : '✂️ {name} — הוסר השיוך ל{what}',
        ['file' => $fid, 'unlink' => $kind, 'ref' => $id], ['name' => $x['name'], 'what' => $what]);
    return ['file' => pm_file_get($user, $fid), 'inbox' => $back];
}

/** התיקייה של שלב בעץ "לפי שלבים" (מחלון השלב: "📁 קבצי השלב"). */
function pm_stage_folder(array $user, int $projectId, int $stageId): array
{
    $p = pm_project_row($user, $projectId);
    pm_ensure_folders($p);
    $st = pm_db()->prepare("SELECT id FROM folders WHERE project_id = ? AND kind = 'stage' AND stage_id = ?");
    $st->execute([$projectId, $stageId]);
    return ['folder_id' => (int)$st->fetchColumn() ?: null];
}

// ================= תיקון חד-פעמי של פרויקטים קיימים (מיגרציה) =================
// העצים מחושבים מהשיוכים, אז רוב מה שחסר מתוקן בתצוגה לבד. כאן — מה ששמור לא נכון בנתונים:
//  1. אבני דרך בלי שלב של ספק שעובד בשלב אחד — לשלב (pm_payments_auto_stage)
//  2. שלב בלי ספק שספק אחד עובד בו — שלו (pm_stage_sync_suppliers)
//  3. תיקיות כפולות (ספק, ספק / מקצוע, שלב…) — מאוחדות, בלי לאבד קובץ
//  4. תיקיות חסרות — ספק עם מסמך / חוזה בלבד, תתי-תיקיות מקצוע, עץ "לפי שלבים"
//  5. קובץ משויך שנשאר ב"ממתין למיון" — למקום שלו; קובץ בשורש תיקיית ספק ששייך למקצוע אחד — לתיקיית המקצוע
//  6. סוג קובץ "אחר" של הצעה / חוזה / אסמכתא / חשבונית — לסוג הנכון
// רץ פעם אחת, בבקשה הראשונה אחרי הפריסה (api.php), בטרנזקציה אחת; מה שתוקן — בציר הזמן של כל פרויקט.

const PM_LINKS_REPAIR_KEY = 'links_repair_v1';

function pm_links_repair_once(): void
{
    $db = pm_db();
    $st = $db->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([PM_LINKS_REPAIR_KEY]);
    if ($st->fetchColumn() !== false) return;
    $db->exec('BEGIN IMMEDIATE');
    try {
        $st->execute([PM_LINKS_REPAIR_KEY]);
        if ($st->fetchColumn() !== false) { $db->exec('COMMIT'); return; } // בקשה אחרת כבר תיקנה
        $total = ['projects' => 0];
        foreach ($db->query('SELECT id FROM projects ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $pid) {
            $r = pm_links_repair_project((int)$pid);
            $total['projects']++;
            foreach ($r as $k => $n) $total[$k] = ($total[$k] ?? 0) + $n;
        }
        $db->prepare('INSERT INTO settings (key, value) VALUES (?, ?)')->execute([PM_LINKS_REPAIR_KEY, json_encode(['at' => pm_now()] + $total)]);
        $db->exec('COMMIT');
        pm_audit('system', 'links.repair', 'system', null, $total);
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        // לא חוזרים על תיקון שנכשל בכל בקשה — נרשם, ונבדק ידנית
        $db->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)')->execute([PM_LINKS_REPAIR_KEY, json_encode(['failed' => $e->getMessage(), 'at' => pm_now()])]);
        pm_log('error', 'data', 'links.repair.failed', ['message' => $e->getMessage()]);
    }
}

/** התיקון של פרויקט אחד. מחזיר כמה תוקן מכל סוג. */
function pm_links_repair_project(int $pid): array
{
    $db = pm_db();
    $r = ['staged' => 0, 'suppliers' => 0, 'merged' => 0, 'moved' => 0, 'typed' => 0];

    // 1–2
    $r['staged'] = pm_payments_auto_stage($pid);
    $empty = fn() => (int)$db->query("SELECT COUNT(*) FROM stages WHERE project_id = $pid AND supplier_id IS NULL")->fetchColumn();
    $before = $empty();
    pm_stage_sync_suppliers($pid);
    $r['suppliers'] = $before - $empty();

    // 3. כפולות — קודם ההורים (ספק), אחר כך מה שבתוכם
    $keys = [
        'contact' => "kind = 'contact' AND parent_id IS NULL AND contact_id IS NOT NULL", 'stages' => "kind = 'stages'",
        'sgroup' => "kind = 'sgroup'", 'tgroup' => "kind = 'tgroup'", 'stage' => "kind = 'stage'", 'trade' => "kind = 'trade'", 'ssup' => "kind = 'ssup'",
    ];
    foreach ($keys as $kind => $where) {
        $rows = $db->query("SELECT id, contact_id, stage_id, name FROM folders WHERE project_id = $pid AND $where ORDER BY id")->fetchAll();
        $keep = [];
        foreach ($rows as $f) {
            $k = in_array($kind, ['sgroup', 'tgroup'], true) ? $f['contact_id'] . ':' . $f['name'] : $f['contact_id'] . ':' . $f['stage_id'];
            if (!isset($keep[$k])) { $keep[$k] = (int)$f['id']; continue; }
            $to = $keep[$k];
            $db->prepare('UPDATE OR IGNORE file_links SET folder_id = ? WHERE folder_id = ?')->execute([$to, $f['id']]);
            // קובץ שכבר היה בשתיהן: הקיצור נמחק; אם זה היה הבית — הבית עובר לתיקייה שנשארת
            $db->prepare('UPDATE file_links SET is_home = 1 WHERE folder_id = ? AND file_id IN (SELECT file_id FROM file_links WHERE folder_id = ? AND is_home = 1)')->execute([$to, $f['id']]);
            $db->prepare('DELETE FROM file_links WHERE folder_id = ?')->execute([$f['id']]);
            $db->prepare('UPDATE folders SET parent_id = ? WHERE parent_id = ?')->execute([$to, $f['id']]);
            $db->prepare('DELETE FROM folders WHERE id = ?')->execute([$f['id']]);
            $r['merged']++;
        }
    }

    // 4
    pm_ensure_folders(['id' => $pid]);

    // 5–6: לפי השיוכים של כל קובץ
    $coords = [];
    foreach (pm_coords($pid) as [$fid, $c, $s]) $coords[$fid][] = [$c, $s];
    $homes = $db->query("SELECT l.file_id, d.id AS folder_id, d.kind, d.parent_id, d.contact_id, x.type FROM file_links l
                          JOIN folders d ON d.id = l.folder_id JOIN files x ON x.id = l.file_id
                         WHERE l.is_home = 1 AND x.project_id = $pid AND x.deleted_at IS NULL")->fetchAll();
    $uses = pm_file_uses(array_column($homes, 'file_id'));
    $root = function (string $kind, ?int $cid = null) use ($db, $pid): ?int {
        $st = $db->prepare("SELECT id FROM folders WHERE project_id = ? AND parent_id IS NULL AND kind = ? AND (contact_id = ? OR ? IS NULL) ORDER BY id LIMIT 1");
        $st->execute([$pid, $kind, $cid, $cid]);
        return (int)$st->fetchColumn() ?: null;
    };
    $stageFolder = function (int $sid) use ($db, $pid): ?int {
        $st = $db->prepare("SELECT id FROM folders WHERE project_id = ? AND kind = 'stage' AND stage_id = ? LIMIT 1");
        $st->execute([$pid, $sid]);
        return (int)$st->fetchColumn() ?: null;
    };
    $move = function (int $fid, int $from, int $to) use ($db, &$r) {
        if ($from === $to) return;
        $db->prepare('DELETE FROM file_links WHERE file_id = ? AND folder_id = ? AND is_home = 0')->execute([$fid, $to]);
        $db->prepare('UPDATE file_links SET folder_id = ? WHERE file_id = ? AND is_home = 1')->execute([$to, $fid]);
        $r['moved']++;
    };
    $TYPE = ['quote' => 'quote', 'contract' => 'contract', 'proof' => 'proof', 'invoice' => 'invoice'];
    foreach ($homes as $h) {
        $fid = (int)$h['file_id'];
        $u = $uses[$fid] ?? [];
        $c = $coords[$fid] ?? [];
        // 6
        if ($h['type'] === 'other') foreach ($u as $x) if (isset($TYPE[$x['kind']])) {
            $db->prepare("UPDATE files SET type = ? WHERE id = ? AND type = 'other'")->execute([$TYPE[$x['kind']], $fid]);
            $r['typed']++;
            break;
        }
        if (!$u && !$c) continue;
        $sups = array_values(array_unique(array_filter(array_column($c, 0))));
        $stagesOf = fn(?int $sup) => array_values(array_unique(array_filter(array_map(fn($p) => $sup === null || $p[0] === $sup ? $p[1] : null, $c))));
        $general = fn(int $sup) => (bool)array_filter($c, fn($p) => $p[0] === $sup && $p[1] === null);
        $from = (int)$h['folder_id'];
        if ($h['kind'] === 'inbox' && $h['parent_id'] === null) {
            // 5א: משויך — אבל עוד "ממתין למיון"
            $to = null;
            if (count($sups) === 1) {
                $ss = $stagesOf($sups[0]);
                $to = count($ss) === 1 && !$general($sups[0]) ? pm_trade_folder($pid, $sups[0], $ss[0]) : $root('contact', $sups[0]);
            } elseif (count($sups) > 1 || !$sups) {
                $ss = $stagesOf(null);
                if (count($ss) === 1) $to = $stageFolder($ss[0]);                 // כמה ספקים בשלב אחד — לשלב
                elseif (!$sups && array_filter($u, fn($x) => $x['kind'] === 'expense')) $to = $root('client'); // הוצאה ישירה — כמו pm_file_claim
            }
            if ($to) $move($fid, $from, $to);
        } elseif ($h['kind'] === 'contact' && $h['parent_id'] === null && $h['contact_id']) {
            // 5ב: בשורש תיקיית הספק, ושייך למקצוע אחד שלו — לתיקיית המקצוע
            $sup = (int)$h['contact_id'];
            $ss = $stagesOf($sup);
            if (in_array($sup, $sups, true) && count($ss) === 1 && !$general($sup)) {
                $to = pm_trade_folder($pid, $sup, $ss[0]);
                if ($to) $move($fid, $from, $to);
            }
        }
    }

    if (array_sum($r) > 0) {
        // בציר הזמן של הפרויקט — בלי להקפיץ אותו לראש הרשימה
        $text = '🔧 תיקון שיוכים אוטומטי: {moved} קבצים סודרו לתיקייה הנכונה · {staged} אבני דרך שויכו לשלב · {suppliers} שלבים קיבלו ספק · {merged} תיקיות כפולות אוחדו · {typed} סוגי קבצים תוקנו'; // i18n-ok — המפתח במילונים (ארוך)
        $db->prepare('INSERT INTO events (project_id, at, actor_id, actor_name, kind, text, data_json) VALUES (?,?,?,?,?,?,?)')
           ->execute([$pid, pm_now(), 0, 'מערכת', 'file', pm_fill($text, $r), json_encode(['repair' => $r, 'i18n' => ['t' => $text, 'v' => $r]], JSON_UNESCAPED_UNICODE)]); // i18n-ok — שם השחקן בציר הזמן
    }
    return $r;
}
