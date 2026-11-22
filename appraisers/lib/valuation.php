<?php
/**
 * פרוסה 16 — חלופות שווי וגישות שומה (docs/spec/16-valuation-methods.md): חלופות, הנחות, גישות, גיליונות חישוב, תבניות גיליון,
 * ורכישה ושיפוץ. **מסגרת לתיעוד** — המערכת אינה יודעת מהי הנוסחה הנכונה, אינה מציעה שיעור היוון, ואינה מייצרת שווי:
 * מסקנה ותוצאה מוקלדת נכתבות רק מקלט של שמאי בתיק, ורק עם נימוק (16-A4). תקציב שיפוץ, הצעת מחיר ועלות שאומתה אינם
 * מתווספים לשום חישוב — אין כאן שורה, פעולה או תבנית שמחברת אותם לשווי (16-C1).
 *
 * החשבון: lib/sheet_calc.php (גיליון) ו-lib/comp_calc.php (מנוע 15) בלבד. כאן רק קוראים מהמסד, מוסרים למנוע שלמים, ומציגים מחרוזות.
 *
 * הרשאות (§9) — apr_comp_case() של פרוסה 15, ולכן כל נקודת גישה עוברת ב-apr_case_get וב-apr_can:
 *   view  — view בתיק; שותף ממשרד אחר — רק כש"עסקאות וחישובים" בהיקף השיתוף ועם see_internal (רק case-valuation ו-sheet פתוחות לו)
 *   write — edit: פריטי רכישה ושיפוץ, שורות קלט
 *   judge — edit + שמאי במשרד שהוא האחראי או שותף בתיק: חלופות, הנחות, גישות, נוסחאות, משקולות, מסקנות, תבניות
 * מזהה של משרד אחר, של תיק שאינני משתתף בו ומזהה שלא קיים — אותו 404 (16-C5). תיק סגור — קריאה בלבד.
 */

declare(strict_types=1);

require_once __DIR__ . '/comps.php';
require_once __DIR__ . '/sheet_calc.php';

const APR_ALT_STATES = ['existing', 'after_works', 'hypothetical', 'other'];
const APR_ALT_MAX = 12;
const APR_ASSUMPTION_KINDS = ['assumption', 'limitation', 'condition'];
const APR_ASSUMPTION_APPROVALS = ['stated', 'supported', 'unverified'];
const APR_ASSUMPTION_MAX = 60;
const APR_APPROACH_KINDS = ['comparison', 'income', 'cost', 'other'];
const APR_APPROACH_TITLES = ['comparison' => 'גישת ההשוואה', 'income' => 'גישת ההכנסה', 'cost' => 'גישת העלות', 'other' => 'גישה אחרת'];
const APR_APPROACH_MAX = 8;
const APR_SHEET_ROW_KINDS = ['input', 'calc', 'note'];
const APR_SHEET_TIME_UNITS = ['none', 'month', 'year'];
const APR_SHEET_ORIGINS = ['provided', 'estimated', 'assumed'];
const APR_SHEET_MAX = 30;
const APR_SHEET_TEMPLATE_MAX = 50;
const APR_RENOVATION_KINDS = ['contract_price', 'declared_budget', 'quote', 'verified_cost'];
const APR_RENOVATION_MAX = 200;
const APR_ALT_NAME_EXISTING = 'מצב קיים';
const APR_ALT_NAME_AFTER = 'בהנחת השלמת השיפוץ';

// ── עזרים ─────────────────────────────────────────────────────

/** העדכון היחיד של שורה עם rev: rev + 1, רק אם לא השתנה בינתיים. אפס שורות ← 409 "עודכן בידי <שם>"; שום דבר לא נכתב. */
function apr_val_update(array $ctx, string $table, array $row, int $rev, array $set): void
{
    if (!in_array($table, ['alternatives', 'assumptions', 'approaches', 'sheets'], true)) throw new LogicException('apr_val_update: ' . $table);
    $set += ['updated_by' => (int)$ctx['user']['id'], 'updated_at' => apr_now()];
    $n = apr_exec("UPDATE $table SET " . implode(', ', array_map(fn($f) => "$f = ?", array_keys($set))) . ', rev = rev + 1 WHERE id = ? AND rev = ? AND deleted_at IS NULL', [...array_values($set), $row['id'], $rev]);
    if ($n === 1) return;
    $by = apr_val("SELECT u.name FROM $table x LEFT JOIN users u ON u.id = x.updated_by WHERE x.id = ?", [$row['id']]);
    throw new AprVisitError('val_changed', ['by' => $by ?: null], is_string($by) && $by !== '' ? 'עודכן בידי ' . $by . ' — רעננו ונסו שוב' : APR_ERRORS['val_changed'][1]);
}

/** מספר עשרוני בסימן ← מיליוניות, דרך apr_comp_dec (בלי נקודה צפה). null / '' ← null. */
function apr_val_dec6(mixed $v, string $err = 'sheet_value'): ?int
{
    if ($v === null || $v === '') return null;
    if (is_string($v)) $v = trim($v);
    $neg = (is_string($v) && str_starts_with($v, '-')) || ((is_int($v) || is_float($v)) && $v < 0);
    if ($neg) $v = is_string($v) ? substr($v, 1) : (is_int($v) ? -$v : (string)json_encode(-$v));
    $n = apr_comp_dec($v, 6, 12, $err);
    return $n === null ? null : ($neg ? -$n : $n);
}

/** מחרוזת עשרונית ← עם מפריד אלפים ("1500000.5" ← "1,500,000.5"). לתצוגה בלבד — פעולת מחרוזות, לא חשבון. */
function apr_val_fmt(?string $s): ?string
{
    if ($s === null) return null;
    $neg = str_starts_with($s, '-');
    [$int, $frac] = explode('.', $neg ? substr($s, 1) : $s) + [1 => null];
    $int = strrev(implode(',', str_split(strrev($int), 3)));
    return ($neg ? '-' : '') . $int . ($frac === null ? '' : '.' . $frac);
}

/** סכום באגורות (שלם או מחרוזת שלם) ← {agorot, nis, exact} כמו apr_comp_money. */
function apr_val_money(int|string|null $agorot): ?array
{
    return $agorot === null ? null : apr_comp_money([apr_n($agorot), '1']);
}

/** קישור לנתון של התיק — רק למי שרואה את נתוני התיק (05: בלי facts אין רשומת נתון, גם לא בעקיפין). */
function apr_val_fact_ref(array $c, mixed $factId): ?array
{
    if ($factId === null || !apr_can($c, 'facts')) return null;
    $f = apr_row('SELECT pid, attribute_key, state, deleted_at FROM facts WHERE id = ? AND case_id = ?', [$factId, $c['id']]);
    if (!$f) return null;
    return ['pid' => $f['pid'], 'label' => apr_attr($f['attribute_key'])['label'], 'state' => $f['state'], 'deleted' => $f['deleted_at'] !== null];
}

/** קישור לקובץ של התיק — רק אם הקובץ גלוי למבקש (apr_file_visible). */
function apr_val_file_ref(array $ctx, array $c, mixed $fileId): ?array
{
    if ($fileId === null) return null;
    $f = apr_row('SELECT * FROM files WHERE id = ? AND case_id = ?', [$fileId, $c['id']]);
    if (!$f || !apr_file_visible($ctx, $c, $f)) return null;
    return ['pid' => $f['pid'], 'name' => $f['original_name'], 'deleted' => $f['deleted_at'] !== null];
}

/** נתון של התיק מהקלט ← id, או null. נתון של תיק אחר / שנמחק ← 404; נתון שנדחה ← fact_state. */
function apr_val_fact_in(array $c, mixed $pid): ?int
{
    if ($pid === null || $pid === '') return null;
    $f = apr_fact_row($c, $pid);
    if ($f['state'] === 'rejected') apr_fail('fact_state');
    return (int)$f['id'];
}

function apr_val_file_in(array $ctx, array $c, mixed $pid): ?int
{
    if ($pid === null || $pid === '') return null;
    $f = apr_file_row($c, $pid);
    if (!apr_file_visible($ctx, $c, $f)) apr_fail('not_found');
    return (int)$f['id'];
}

/**
 * מי נשען על אילו נתונים (fact_uses, פרוסה 10): מוסיף רישום לנתון חדש ומסיר רישום של נתון שכבר אינו מקושר — **בלי לגעת ברישום
 * קיים**, כדי ש-stale לא ירד בשמירה רגילה (לקח 🟡4 של ביקורת 15). "נבדק מחדש" — רק ב-$recheck, בפעולה מפורשת.
 */
function apr_val_fact_sync(array $c, string $type, string $usedPid, array $factIds, bool $recheck = false): void
{
    $want = array_values(array_unique(array_map('intval', array_filter($factIds, fn($x) => $x !== null))));
    $have = array_map('intval', array_column(apr_rows('SELECT u.fact_id FROM fact_uses u JOIN facts f ON f.id = u.fact_id WHERE u.used_in_type = ? AND u.used_in_pid = ? AND f.case_id = ?', [$type, $usedPid, $c['id']]), 'fact_id'));
    foreach (array_diff($have, $want) as $id) apr_exec('DELETE FROM fact_uses WHERE fact_id = ? AND used_in_type = ? AND used_in_pid = ?', [$id, $type, $usedPid]);
    foreach ($want as $id) {
        if (!$recheck && in_array($id, $have, true)) continue;
        $f = apr_row('SELECT pid, state, deleted_at FROM facts WHERE id = ? AND case_id = ?', [$id, $c['id']]);
        if (!$f || $f['deleted_at'] !== null || $f['state'] === 'rejected') continue;       // נתון שנמחק או נדחה: הסימון נשאר כפי שהוא
        apr_fact_use($c, $f['pid'], $type, $usedPid);
    }
}

function apr_val_stale(array $c, string $type, string $usedPid): bool
{
    return (bool)array_filter(apr_fact_uses_of($c, $type, $usedPid), fn($u) => $u['stale']);
}

// ── חלופות (§4) ───────────────────────────────────────────────

const APR_ALT_SELECT = 'SELECT a.*, cb.name AS conclusion_name, ub.name AS updated_name FROM alternatives a LEFT JOIN users cb ON cb.id = a.conclusion_by LEFT JOIN users ub ON ub.id = a.updated_by';

function apr_alt_row(array $c, mixed $pid): array
{
    return (apr_is_pid($pid) ? apr_row(APR_ALT_SELECT . ' WHERE a.pid = ? AND a.case_id = ? AND a.deleted_at IS NULL', [$pid, $c['id']]) : null) ?? apr_fail('not_found');
}

function apr_alts(array $c): array
{
    return apr_rows(APR_ALT_SELECT . ' WHERE a.case_id = ? AND a.deleted_at IS NULL ORDER BY a.sort, a.id', [$c['id']]);
}

function apr_alt_name_key(string $name): string
{
    return mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $name)));
}

function apr_alt_insert(array $c, string $name, ?string $keyDate, string $state, ?string $note, bool $primary, ?int $uid): string
{
    $pid = apr_pid();
    $now = apr_now();
    apr_insert('alternatives', ['pid' => $pid, 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'name' => $name, 'name_key' => apr_alt_name_key($name),
        'sort' => (int)apr_val('SELECT COALESCE(MAX(sort), 0) + 1 FROM alternatives WHERE case_id = ?', [$c['id']]), 'key_date' => $keyDate, 'state_kind' => $state, 'state_note' => $note,
        'is_primary' => $primary ? 1 : 0, 'created_by' => $uid, 'updated_by' => $uid, 'created_at' => $now, 'updated_at' => $now]);
    return $pid;
}

/**
 * החלופות שנוצרות כשהתיק נפתח (case-start): "מצב קיים" עם המועד הקובע של התיק; ועם הדגל "מתוכנן שיפוץ" — גם "בהנחת השלמת
 * השיפוץ" (16-A2). בתוך הטרנזקציה של הפתיחה. תיק שכבר יש לו חלופות — לא נוגעים.
 */
function apr_alts_seed(array $c, array $flags, ?int $uid): void
{
    if (apr_val('SELECT 1 FROM alternatives WHERE case_id = ? AND deleted_at IS NULL', [$c['id']])) return;
    $keyDate = apr_val('SELECT key_date FROM cases WHERE id = ?', [$c['id']]) ?: null;
    apr_alt_insert($c, APR_ALT_NAME_EXISTING, $keyDate, 'existing', null, true, $uid);
    if (($flags['renovation_planned'] ?? false) === true) apr_alt_insert($c, APR_ALT_NAME_AFTER, $keyDate, 'after_works', null, false, $uid);
}

/**
 * העבודות השתנו (§7): הצעת מחיר חדשה שמחליפה קודמת, או שינוי בדגל השיפוץ. כל חלופת after_works מסומנת stale, ולכל אחת נוצרת משימת
 * סקירה מחדש לאחראי על התיק — אחת פתוחה לכל חלופה (16-C2). **שום ערך אינו משתנה**: לא מסקנה, לא תוצאה ולא הנחה.
 */
function apr_alt_works_changed(array $c, string $why): int
{
    $n = 0;
    $now = apr_now();
    foreach (apr_rows("SELECT * FROM alternatives WHERE case_id = ? AND deleted_at IS NULL AND state_kind = 'after_works' ORDER BY id", [$c['id']]) as $a) {
        apr_exec('UPDATE alternatives SET stale = 1, stale_at = ?, stale_why = ?, rev = rev + 1 WHERE id = ?', [$now, $why, $a['id']]);
        $key = 'alt-review:' . $a['pid'];
        if (!apr_val("SELECT 1 FROM tasks WHERE case_id = ? AND origin_key = ? AND status IN ('open', 'in_progress')", [$c['id'], $key])) {
            $assignee = apr_val('SELECT c.responsible_user_id FROM cases c JOIN case_members m ON m.case_id = c.id AND m.user_id = c.responsible_user_id AND m.revoked_at IS NULL WHERE c.id = ?', [$c['id']]);
            apr_insert('tasks', ['pid' => apr_pid(), 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'kind' => 'system', 'title' => 'סקירה מחדש של ההנחות והחישובים: ' . $a['name'],
                'detail' => $why === 'quote_replaced' ? 'הצעת מחיר הוחלפה בגרסה חדשה' : 'הדגל "מתוכנן שיפוץ" השתנה', 'assignee_user_id' => $assignee ?: null, 'origin_key' => $key,
                'link_type' => 'alternative', 'link_pid' => $a['pid'], 'created_by' => null, 'created_at' => $now, 'updated_at' => $now]);
        }
        apr_case_audit($c, 'alt.stale', 'alternative', $a['pid'], ['why' => $why]);
        $n++;
    }
    return $n;
}

/** שינוי בדגלי התיק אחרי הפתיחה (case-update): הדגל "מתוכנן שיפוץ" השתנה ← סקירה מחדש; נדלק ואין חלופת after_works ← נוצרת אחת. */
function apr_alts_on_flags(array $c, array $before, array $after, ?int $uid): void
{
    $was = ($before['renovation_planned'] ?? false) === true;
    $is = ($after['renovation_planned'] ?? false) === true;
    if ($was === $is || $c['status'] === 'draft') return;
    apr_alt_works_changed($c, 'flag_changed');
    if ($is && !apr_val("SELECT 1 FROM alternatives WHERE case_id = ? AND deleted_at IS NULL AND state_kind = 'after_works'", [$c['id']])
        && !apr_val('SELECT 1 FROM alternatives WHERE case_id = ? AND deleted_at IS NULL AND name_key = ?', [$c['id'], apr_alt_name_key(APR_ALT_NAME_AFTER)])) {
        $has = (bool)apr_val('SELECT 1 FROM alternatives WHERE case_id = ? AND deleted_at IS NULL', [$c['id']]);
        $pid = apr_alt_insert($c, APR_ALT_NAME_AFTER, apr_val('SELECT key_date FROM cases WHERE id = ?', [$c['id']]) ?: null, 'after_works', null, !$has, $uid);
        apr_case_audit($c, 'alt.add', 'alternative', $pid, ['auto' => 'flag', 'state_kind' => 'after_works']);
    }
}

/**
 * 27.4: עסקה שמצבה לא ידוע אינה נכנסת לקבוצה של "אחרי שיפוץ" בלי נימוק מפורש. נקרא כשקבוצה מקושרת לחלופת after_works
 * (או כשחלופה הופכת לכזו). מחזיר את מזהי העסקאות שחוסמות.
 */
function apr_val_set_unreasoned(int $setId): array
{
    return array_column(apr_rows("SELECT k.pid FROM comp_set_members m JOIN comps k ON k.id = m.comp_id
        WHERE m.set_id = ? AND m.active = 1 AND k.deleted_at IS NULL AND k.status = 'selected' AND k.condition IS NULL AND (m.reason IS NULL OR m.reason = '') ORDER BY k.id", [$setId]), 'pid');
}

/** alt-save {case | alt, rev?, name, key_date?, state_kind, state_note?} — חלופה חדשה, או עדכון. שתי חלופות עם אותו שם — נחסם (§10.3). */
function apr_alt_save(array $ctx, array $in): array
{
    $isNew = ($in['alt'] ?? null) === null || $in['alt'] === '';
    $c = apr_comp_case($ctx, $isNew ? ($in['case'] ?? null) : apr_doc_case_pid('alternatives', $in['alt']), 'judge');
    $rev = $isNew ? 0 : apr_comp_rev($in['rev'] ?? null);
    $row = ['name' => apr_clean_name($in['name'] ?? null, 80), 'key_date' => apr_clean_date($in['key_date'] ?? null),
        'state_kind' => is_string($in['state_kind'] ?? null) && in_array($in['state_kind'], APR_ALT_STATES, true) ? $in['state_kind'] : apr_fail('alt_state'),
        'state_note' => apr_clean_text($in['state_note'] ?? '', 1000)];
    return apr_tx(function () use ($ctx, $c, $in, $isNew, $rev, $row) {
        $a = $isNew ? null : apr_alt_row($c, $in['alt']);
        $key = apr_alt_name_key($row['name']);
        if (apr_val('SELECT 1 FROM alternatives WHERE case_id = ? AND deleted_at IS NULL AND name_key = ? AND id <> ?', [$c['id'], $key, $a['id'] ?? 0])) apr_fail('alt_name_taken');
        if ($isNew) {
            $count = (int)apr_val('SELECT COUNT(*) FROM alternatives WHERE case_id = ? AND deleted_at IS NULL', [$c['id']]);
            if ($count >= APR_ALT_MAX) apr_fail('alt_limit');
            $pid = apr_alt_insert($c, $row['name'], $row['key_date'], $row['state_kind'], $row['state_note'], $count === 0, (int)$ctx['user']['id']);
            apr_case_audit($c, 'alt.add', 'alternative', $pid, ['to' => $row]);
            apr_case_touch($c);
            return ['alt' => $pid, 'rev' => 1];
        }
        $from = [];
        $to = [];
        foreach ($row as $f => $v) if ($a[$f] !== $v) [$from[$f], $to[$f]] = [$a[$f], $v];
        if (!$from) apr_fail('nothing_to_change');
        if (($to['state_kind'] ?? '') === 'after_works') {
            foreach (apr_rows("SELECT comp_set_id FROM approaches WHERE alternative_id = ? AND deleted_at IS NULL AND comp_set_id IS NOT NULL", [$a['id']]) as $p) {
                if (apr_val_set_unreasoned((int)$p['comp_set_id'])) apr_fail('alt_condition_unknown');
            }
        }
        apr_val_update($ctx, 'alternatives', $a, $rev, $row + ['name_key' => $key]);
        apr_case_audit($c, 'alt.update', 'alternative', $a['pid'], ['from' => $from, 'to' => $to]);
        apr_case_touch($c);
        return ['alt' => $a['pid'], 'rev' => $rev + 1];
    });
}

/** alt-primary {alt, rev} — החלופה הראשית של התיק. תמיד בדיוק אחת (§10.4). */
function apr_alt_primary(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, apr_doc_case_pid('alternatives', $in['alt'] ?? null), 'judge');
    $rev = apr_comp_rev($in['rev'] ?? null);
    return apr_tx(function () use ($ctx, $c, $in, $rev) {
        $a = apr_alt_row($c, $in['alt'] ?? null);
        if ((int)$a['is_primary'] === 1) apr_fail('nothing_to_change');
        if ((int)$a['rev'] !== $rev) apr_val_update($ctx, 'alternatives', $a, $rev, []);        // rev ישן ← 409, לפני שנוגעים בחלופה האחרת
        $old = apr_row('SELECT id, pid FROM alternatives WHERE case_id = ? AND deleted_at IS NULL AND is_primary = 1', [$c['id']]);
        if ($old) apr_exec('UPDATE alternatives SET is_primary = 0, rev = rev + 1 WHERE id = ?', [$old['id']]);
        apr_val_update($ctx, 'alternatives', $a, $rev, ['is_primary' => 1]);
        apr_case_audit($c, 'alt.primary', 'alternative', $a['pid'], ['from' => $old['pid'] ?? null]);
        apr_case_touch($c);
        return ['alt' => $a['pid'], 'rev' => $rev + 1];
    });
}

/**
 * alt-delete {alt, rev, reason} — מחיקה רכה (§10.1): החלופה, ההנחות והגישות שלה נשארות במסד. מחיקת הראשית מעבירה את הסימון
 * לראשונה שנותרה (§10.4); החלופה האחרונה של תיק אינה נמחקת — תמיד יש ראשית אחת.
 */
function apr_alt_delete(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, apr_doc_case_pid('alternatives', $in['alt'] ?? null), 'judge');
    $rev = apr_comp_rev($in['rev'] ?? null);
    $reason = apr_reason($in['reason'] ?? null);
    return apr_tx(function () use ($ctx, $c, $in, $rev, $reason) {
        $a = apr_alt_row($c, $in['alt'] ?? null);
        $next = apr_row('SELECT id, pid FROM alternatives WHERE case_id = ? AND deleted_at IS NULL AND id <> ? ORDER BY sort, id LIMIT 1', [$c['id'], $a['id']]);
        if (!$next) apr_fail('alt_last');
        apr_val_update($ctx, 'alternatives', $a, $rev, ['deleted_at' => apr_now(), 'deleted_by' => (int)$ctx['user']['id'], 'delete_reason' => $reason, 'is_primary' => 0]);
        if ((int)$a['is_primary'] === 1) apr_exec('UPDATE alternatives SET is_primary = 1, rev = rev + 1 WHERE id = ?', [$next['id']]);
        apr_fact_unuse($c, 'alternative', $a['pid']);
        apr_exec("UPDATE tasks SET status = 'cancelled', cancel_reason = ?, updated_at = ? WHERE case_id = ? AND origin_key = ? AND status IN ('open', 'in_progress')", ['החלופה נמחקה', apr_now(), $c['id'], 'alt-review:' . $a['pid']]);
        apr_case_audit($c, 'alt.delete', 'alternative', $a['pid'], ['name' => $a['name'], 'primary_to' => (int)$a['is_primary'] === 1 ? $next['pid'] : null,
            'conclusion' => apr_comp_dec_out($a['conclusion_value'], 2)], $reason);
        apr_case_touch($c);
        return ['deleted' => true, 'primary' => (int)$a['is_primary'] === 1 ? $next['pid'] : null];
    });
}

/**
 * alt-conclude {alt, rev, value | null, reason} — מסקנת השווי של החלופה. הערך מוקלד; שום פעולה אינה ממלאת אותו, ושמירה בלי
 * נימוק נדחית (16-A4). value = null מוחק את המסקנה.
 */
function apr_alt_conclude(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, apr_doc_case_pid('alternatives', $in['alt'] ?? null), 'judge');
    $rev = apr_comp_rev($in['rev'] ?? null);
    $value = apr_comp_dec($in['value'] ?? null, 2, 11);
    $reason = $value === null ? null : apr_reason($in['reason'] ?? null);
    return apr_tx(function () use ($ctx, $c, $in, $rev, $value, $reason) {
        $a = apr_alt_row($c, $in['alt'] ?? null);
        $old = $a['conclusion_value'] === null ? null : (int)$a['conclusion_value'];
        if ($old === $value && $a['conclusion_reason'] === $reason) apr_fail('nothing_to_change');
        apr_val_update($ctx, 'alternatives', $a, $rev, $value === null ? ['conclusion_value' => null, 'conclusion_reason' => null, 'conclusion_by' => null, 'conclusion_at' => null]
            : ['conclusion_value' => $value, 'conclusion_reason' => $reason, 'conclusion_by' => (int)$ctx['user']['id'], 'conclusion_at' => apr_now()]);
        apr_case_audit($c, 'alt.conclude', 'alternative', $a['pid'], ['from' => apr_comp_dec_out($old, 2), 'to' => apr_comp_dec_out($value, 2)], $reason);
        apr_case_touch($c);
        return ['alt' => $a['pid'], 'rev' => $rev + 1];
    });
}

/** alt-recheck {alt, rev} — "נבדק מחדש": הסימונים stale (עבודות שהשתנו; נתון שהשתנה) יורדים, ומשימת הסקירה נסגרת. פעולה מפורשת, נרשמת ביומן. */
function apr_alt_recheck(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, apr_doc_case_pid('alternatives', $in['alt'] ?? null), 'judge');
    $rev = apr_comp_rev($in['rev'] ?? null);
    return apr_tx(function () use ($ctx, $c, $in, $rev) {
        $a = apr_alt_row($c, $in['alt'] ?? null);
        $factStale = apr_val_stale($c, 'alternative', $a['pid']);
        if ((int)$a['stale'] === 0 && !$factStale) apr_fail('nothing_to_change');
        apr_val_update($ctx, 'alternatives', $a, $rev, ['stale' => 0, 'stale_at' => null, 'stale_why' => null]);
        apr_val_fact_sync($c, 'alternative', $a['pid'], apr_alt_fact_ids((int)$a['id']), true);
        apr_exec("UPDATE tasks SET status = 'done', done_at = ?, updated_at = ? WHERE case_id = ? AND origin_key = ? AND status IN ('open', 'in_progress')", [apr_now(), apr_now(), $c['id'], 'alt-review:' . $a['pid']]);
        apr_case_audit($c, 'alt.recheck', 'alternative', $a['pid'], ['why' => $a['stale_why'], 'facts' => $factStale]);
        return ['alt' => $a['pid'], 'rev' => $rev + 1];
    });
}

// ── הנחות (§3, §4) ────────────────────────────────────────────

function apr_alt_fact_ids(int $altId): array
{
    return array_column(apr_rows('SELECT fact_id FROM assumptions WHERE alternative_id = ? AND deleted_at IS NULL AND fact_id IS NOT NULL', [$altId]), 'fact_id');
}

function apr_assumption_row(array $c, mixed $pid): array
{
    return (apr_is_pid($pid) ? apr_row('SELECT s.*, a.pid AS alt_pid FROM assumptions s LEFT JOIN alternatives a ON a.id = s.alternative_id WHERE s.pid = ? AND s.case_id = ? AND s.deleted_at IS NULL', [$pid, $c['id']]) : null) ?? apr_fail('not_found');
}

function apr_assumption_out(array $ctx, array $c, array $s): array
{
    return ['pid' => $s['pid'], 'rev' => (int)$s['rev'], 'text' => $s['text'], 'kind' => $s['kind'], 'basis' => $s['basis'], 'approval' => $s['approval'],
        'fact' => apr_val_fact_ref($c, $s['fact_id']), 'file' => apr_val_file_ref($ctx, $c, $s['file_id'])];
}

/**
 * assumption-save {case | alt | assumption, rev?, text, kind, basis?, fact?, file?, approval?} — הנחה, מגבלה או תנאי: של חלופה (alt),
 * או של התיק כולו (case). לכל חלופה הנחות משלה — שינוי באחת אינו נוגע באחרת (16-A1).
 */
function apr_assumption_save(array $ctx, array $in): array
{
    $isNew = ($in['assumption'] ?? null) === null || $in['assumption'] === '';
    $forAlt = $isNew && ($in['alt'] ?? null) !== null && $in['alt'] !== '';
    $c = apr_comp_case($ctx, !$isNew ? apr_doc_case_pid('assumptions', $in['assumption']) : ($forAlt ? apr_doc_case_pid('alternatives', $in['alt']) : ($in['case'] ?? null)), 'judge');
    $rev = $isNew ? 0 : apr_comp_rev($in['rev'] ?? null);
    $row = ['text' => apr_clean_text($in['text'] ?? '', 1000) ?? apr_fail('assumption_text'),
        'kind' => is_string($in['kind'] ?? null) && in_array($in['kind'], APR_ASSUMPTION_KINDS, true) ? $in['kind'] : apr_fail('assumption_kind'),
        'basis' => apr_clean_text($in['basis'] ?? '', 500),
        'approval' => apr_comp_enum($in['approval'] ?? null, APR_ASSUMPTION_APPROVALS, 'unverified', 'assumption_kind')];
    return apr_tx(function () use ($ctx, $c, $in, $isNew, $forAlt, $rev, $row) {
        $row['fact_id'] = apr_val_fact_in($c, $in['fact'] ?? null);
        $row['file_id'] = apr_val_file_in($ctx, $c, $in['file'] ?? null);
        $now = apr_now();
        if ($isNew) {
            $a = $forAlt ? apr_alt_row($c, $in['alt']) : null;
            if ((int)apr_val('SELECT COUNT(*) FROM assumptions WHERE case_id = ? AND deleted_at IS NULL', [$c['id']]) >= APR_ASSUMPTION_MAX) apr_fail('assumption_limit');
            $pid = apr_pid();
            apr_insert('assumptions', ['pid' => $pid, 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'alternative_id' => $a['id'] ?? null,
                'sort' => (int)apr_val('SELECT COALESCE(MAX(sort), 0) + 1 FROM assumptions WHERE case_id = ?', [$c['id']]),
                'created_by' => (int)$ctx['user']['id'], 'updated_by' => (int)$ctx['user']['id'], 'created_at' => $now, 'updated_at' => $now] + $row);
            apr_case_audit($c, 'assumption.add', 'assumption', $pid, ['alt' => $a['pid'] ?? null, 'kind' => $row['kind'], 'approval' => $row['approval']]);
            $altId = $a['id'] ?? null;
            $altPid = $a['pid'] ?? null;
            $out = ['assumption' => $pid, 'rev' => 1];
        } else {
            $s = apr_assumption_row($c, $in['assumption']);
            $changed = array_keys(array_filter($row, fn($v, $f) => $v !== (in_array($f, ['fact_id', 'file_id'], true) && $s[$f] !== null ? (int)$s[$f] : $s[$f]), ARRAY_FILTER_USE_BOTH));
            if (!$changed) apr_fail('nothing_to_change');
            apr_val_update($ctx, 'assumptions', $s, $rev, $row);
            apr_case_audit($c, 'assumption.update', 'assumption', $s['pid'], ['alt' => $s['alt_pid'], 'fields' => $changed]
                + (in_array('approval', $changed, true) ? ['from' => $s['approval'], 'to' => $row['approval']] : []));
            $altId = $s['alternative_id'];
            $altPid = $s['alt_pid'];
            $out = ['assumption' => $s['pid'], 'rev' => $rev + 1];
        }
        if ($altPid !== null) apr_val_fact_sync($c, 'alternative', $altPid, apr_alt_fact_ids((int)$altId));
        apr_case_touch($c);
        return $out;
    });
}

/** assumption-delete {assumption, rev} — מחיקה רכה; מה שנכתב נשאר ביומן. */
function apr_assumption_delete(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, apr_doc_case_pid('assumptions', $in['assumption'] ?? null), 'judge');
    $rev = apr_comp_rev($in['rev'] ?? null);
    return apr_tx(function () use ($ctx, $c, $in, $rev) {
        $s = apr_assumption_row($c, $in['assumption'] ?? null);
        apr_val_update($ctx, 'assumptions', $s, $rev, ['deleted_at' => apr_now()]);
        if ($s['alt_pid'] !== null) apr_val_fact_sync($c, 'alternative', $s['alt_pid'], apr_alt_fact_ids((int)$s['alternative_id']));
        apr_case_audit($c, 'assumption.delete', 'assumption', $s['pid'], ['alt' => $s['alt_pid'], 'kind' => $s['kind'], 'text' => $s['text']]);
        apr_case_touch($c);
        return ['deleted' => true];
    });
}

// ── גיליון חישוב (§5) ─────────────────────────────────────────

function apr_sheet_get(array $c, mixed $pid): array
{
    return (apr_is_pid($pid) ? apr_row('SELECT s.*, ub.name AS updated_name FROM sheets s LEFT JOIN users ub ON ub.id = s.updated_by WHERE s.pid = ? AND s.case_id = ? AND s.deleted_at IS NULL', [$pid, $c['id']]) : null) ?? apr_fail('not_found');
}

function apr_sheet_rows(int $sheetId): array
{
    $rows = apr_rows('SELECT * FROM sheet_rows WHERE sheet_id = ? ORDER BY sort, id', [$sheetId]);
    foreach ($rows as &$r) {
        $r['value'] = $r['value'] === null ? null : (int)$r['value'];
        $r['formula'] = $r['formula'] === null ? null : json_decode($r['formula'], true);
    }
    return $rows;
}

/** מספר JSON שהגיע כנקודה צפה ← מחרוזת עשרונית (הייצוג הקצר שלו), בכל עומק — המנוע אינו רואה נקודה צפה לעולם. */
function apr_sheet_formula_in(mixed $f, int $depth = 0): mixed
{
    if ($depth > 8) apr_fail('sheet_formula');
    if (is_float($f)) return is_finite($f) ? (string)json_encode($f) : apr_fail('sheet_formula');
    if (is_array($f)) foreach ($f as $k => $v) $f[$k] = apr_sheet_formula_in($v, $depth + 1);
    return $f;
}

/** הגיליון מחושב: [שורות, ערכים, תוצאה]. מחושב מחדש בכל קריאה — אין תוצאה שמורה שיכולה להתיישן. */
function apr_sheet_compute(array $sheet): array
{
    $rows = apr_sheet_rows((int)$sheet['id']);
    $vals = apr_sheet_eval($rows);
    return [$rows, $vals, apr_sheet_result($rows, $vals, $sheet['rounding'])];
}

/** הנוסחה במילים ובמספרים (16-D2): ['words' => "דמי שכירות × 12", 'numbers' => "8,500 × 12"]. $top — בלי סוגריים חיצוניים. */
function apr_sheet_formula_text(mixed $f, array $labels, array $vals, bool $top = true): array
{
    if (is_string($f)) {
        if (!apr_sheet_is_key($f)) return ['words' => apr_val_fmt($f), 'numbers' => apr_val_fmt($f)];
        $v = $vals[$f]['value'] ?? null;
        return ['words' => $labels[$f] ?? 'שורה שנמחקה', 'numbers' => $v === null ? '—' : apr_val_fmt(apr_sheet_dec($v)['dec'])];
    }
    if (isset($f['pct'])) {
        $x = apr_sheet_formula_text($f['pct'], $labels, $vals, false);
        return ['words' => $x['words'] . '%', 'numbers' => $x['numbers'] . '%'];
    }
    if (($f['op'] ?? '') === 'sum') {
        $keys = array_keys($labels);
        $from = array_search($f['args'][0], $keys, true);
        $to = array_search($f['args'][1], $keys, true);
        $range = $from === false || $to === false ? [] : array_values(array_filter(array_slice($keys, min($from, $to), abs($to - $from) + 1), fn($k) => isset($vals[$k])));
        $nums = array_map(fn($k) => $vals[$k]['value'] === null ? '—' : apr_val_fmt(apr_sheet_dec($vals[$k]['value'])['dec']), $range);
        $w = 'סכום השורות מ"' . ($labels[$f['args'][0]] ?? 'שורה שנמחקה') . '" עד "' . ($labels[$f['args'][1]] ?? 'שורה שנמחקה') . '"';
        $n = $nums ? implode(' + ', $nums) : '—';
        return ['words' => $w, 'numbers' => $top || count($nums) < 2 ? $n : "($n)"];
    }
    $sym = ['add' => ' + ', 'sub' => ' − ', 'mul' => ' × ', 'div' => ' ÷ '][$f['op'] ?? ''] ?? ' ? ';
    $parts = array_map(fn($a) => apr_sheet_formula_text($a, $labels, $vals, false), $f['args'] ?? []);
    $w = implode($sym, array_column($parts, 'words'));
    $n = implode($sym, array_column($parts, 'numbers'));
    return $top ? ['words' => $w, 'numbers' => $n] : ['words' => "($w)", 'numbers' => "($n)"];
}

/** ערך של שורה לתשובה: {dec, text (עם מפריד אלפים), exact} — או null. */
function apr_sheet_val_out(?array $q): ?array
{
    if ($q === null) return null;
    $d = apr_sheet_dec($q);
    return ['dec' => $d['dec'], 'text' => apr_val_fmt($d['dec']), 'exact' => $d['exact']];
}

function apr_sheet_rows_out(array $ctx, array $c, array $rows, array $vals): array
{
    $labels = [];
    foreach ($rows as $r) $labels[$r['key']] = $r['label'];
    $out = [];
    foreach ($rows as $r) {
        $v = $vals[$r['key']] ?? null;
        $o = ['key' => $r['key'], 'kind' => $r['kind'], 'label' => $r['label'], 'unit' => $r['unit'], 'time_unit' => $r['time_unit'], 'reasoning' => $r['reasoning']];
        if ($r['kind'] === 'input') {
            $o += ['value' => apr_comp_dec_out($r['value'], 6), 'value_text' => apr_val_fmt(apr_comp_dec_out($r['value'], 6)), 'origin' => $r['origin'], 'source_note' => $r['source_note'],
                'fact' => apr_val_fact_ref($c, $r['fact_id']), 'file' => apr_val_file_ref($ctx, $c, $r['file_id']), 'warnings' => $v['warnings'] ?? []];
        } elseif ($r['kind'] === 'calc') {
            $o += ['formula' => $r['formula'], 'text' => apr_sheet_formula_text($r['formula'], $labels, $vals), 'result' => apr_sheet_val_out($v['value'] ?? null),
                'reason' => $v['reason'] ?? null, 'ref' => $v['ref'] ?? null, 'ref_label' => isset($v['ref']) ? ($labels[$v['ref']] ?? null) : null, 'warnings' => $v['warnings'] ?? []];
        }
        $out[] = $o;
    }
    return $out;
}

function apr_sheet_result_out(array $rows, array $res): array
{
    $labels = array_column($rows, 'label', 'key');
    return ['key' => $res['key'], 'label' => $res['key'] === null ? null : ($labels[$res['key']] ?? null), 'value' => apr_val_money($res['agorot']), 'exact' => apr_sheet_val_out($res['exact']),
        'reason' => $res['reason'], 'ref' => $res['ref'], 'ref_label' => $res['ref'] === null ? null : ($labels[$res['ref']] ?? null)];
}

function apr_sheet_head_out(array $c, array $sheet): array
{
    return ['pid' => $sheet['pid'], 'rev' => (int)$sheet['rev'], 'title' => $sheet['title'], 'rounding' => $sheet['rounding'], 'stale' => apr_val_stale($c, 'calc', $sheet['pid']),
        'updated_by' => $sheet['updated_name'] ?? null, 'updated_at' => $sheet['updated_at']];
}

/** sheet-save {case | sheet, rev?, title, rounding} — גיליון חדש (ריק), או שינוי הכותרת והעיגול. */
function apr_sheet_save(array $ctx, array $in): array
{
    $isNew = ($in['sheet'] ?? null) === null || $in['sheet'] === '';
    $c = apr_comp_case($ctx, $isNew ? ($in['case'] ?? null) : apr_doc_case_pid('sheets', $in['sheet']), 'judge');
    $rev = $isNew ? 0 : apr_comp_rev($in['rev'] ?? null);
    $row = ['title' => apr_clean_name($in['title'] ?? null, 120),
        'rounding' => in_array((string)($in['rounding'] ?? 'none'), APR_COMP_ROUNDINGS, true) ? (string)($in['rounding'] ?? 'none') : apr_fail('sheet_rounding')];
    return apr_tx(function () use ($ctx, $c, $in, $isNew, $rev, $row) {
        if ($isNew) {
            $pid = apr_sheet_insert($ctx, $c, $row['title'], $row['rounding']);
            apr_case_audit($c, 'sheet.add', 'sheet', $pid, ['to' => $row]);
            apr_case_touch($c);
            return ['sheet' => $pid, 'rev' => 1];
        }
        $s = apr_sheet_get($c, $in['sheet']);
        $from = array_filter(['title' => $s['title'], 'rounding' => $s['rounding']], fn($v, $f) => $v !== $row[$f], ARRAY_FILTER_USE_BOTH);
        if (!$from) apr_fail('nothing_to_change');
        apr_val_update($ctx, 'sheets', $s, $rev, $row);
        apr_case_audit($c, 'sheet.update', 'sheet', $s['pid'], ['from' => $from, 'to' => array_intersect_key($row, $from)]);
        apr_case_touch($c);
        return ['sheet' => $s['pid'], 'rev' => $rev + 1];
    });
}

function apr_sheet_insert(array $ctx, array $c, string $title, string $rounding): string
{
    if ((int)apr_val('SELECT COUNT(*) FROM sheets WHERE case_id = ? AND deleted_at IS NULL', [$c['id']]) >= APR_SHEET_MAX) apr_fail('sheet_limit');
    $pid = apr_pid();
    $now = apr_now();
    apr_insert('sheets', ['pid' => $pid, 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'title' => $title, 'rounding' => $rounding,
        'created_by' => (int)$ctx['user']['id'], 'updated_by' => (int)$ctx['user']['id'], 'created_at' => $now, 'updated_at' => $now]);
    return $pid;
}

function apr_sheet_fact_ids(int $sheetId): array
{
    return array_column(apr_rows('SELECT fact_id FROM sheet_rows WHERE sheet_id = ? AND fact_id IS NOT NULL', [$sheetId]), 'fact_id');
}

/**
 * sheet-row-save {sheet, rev, key?, kind, label, unit?, time_unit?, reasoning?, …} — שורה חדשה (בסוף הגיליון), או עדכון של שורה (key).
 *   input: value?, origin (חובה כשיש ערך: provided | estimated | assumed), source_note?, fact?, file? — מי שיש לו edit בתיק.
 *   calc:  formula — מבנה (§5); נבדק בשמירה מול השורות שמעל: שורה שמתחת, שורה שאינה קיימת או פעולה לא מוכרת ← נדחה (16-B4). רק שמאי בתיק.
 *   note:  טקסט בלבד. רק שמאי בתיק.
 * סוג השורה אינו משתנה אחרי שנוצרה. ערך ריק נשאר NULL — "לא ידוע", לא אפס (16-B2).
 */
function apr_sheet_row_save(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, apr_doc_case_pid('sheets', $in['sheet'] ?? null), 'write');
    $rev = apr_comp_rev($in['rev'] ?? null);
    $isNew = ($in['key'] ?? null) === null || $in['key'] === '';
    $kind = is_string($in['kind'] ?? null) && in_array($in['kind'], APR_SHEET_ROW_KINDS, true) ? $in['kind'] : apr_fail('sheet_kind');
    if ($kind !== 'input' && !apr_comp_can_judge($ctx, $c)) apr_fail('forbidden');
    $row = ['label' => apr_clean_text($in['label'] ?? '', 120) ?? apr_fail('sheet_label'), 'unit' => apr_clean_text($in['unit'] ?? '', 20),
        'time_unit' => apr_comp_enum($in['time_unit'] ?? null, APR_SHEET_TIME_UNITS, 'none', 'sheet_kind'), 'reasoning' => apr_clean_text($in['reasoning'] ?? '', 1000),
        'value' => null, 'origin' => null, 'source_note' => null, 'fact_id' => null, 'file_id' => null, 'formula' => null];
    if ($kind === 'input') {
        $row['value'] = apr_val_dec6($in['value'] ?? null);
        $row['origin'] = ($in['origin'] ?? null) === null || $in['origin'] === '' ? null : (is_string($in['origin']) && in_array($in['origin'], APR_SHEET_ORIGINS, true) ? $in['origin'] : apr_fail('sheet_origin'));
        if ($row['value'] !== null && $row['origin'] === null) apr_fail('sheet_origin');         // ערך בלי "סופק / הוערך / הנחה" אינו נשמר (24.3)
        $row['source_note'] = apr_clean_text($in['source_note'] ?? '', 300);
    }
    $formula = $kind === 'calc' ? apr_sheet_formula_in($in['formula'] ?? null) : null;
    return apr_tx(function () use ($ctx, $c, $in, $rev, $isNew, $kind, $row, $formula) {
        $s = apr_sheet_get($c, $in['sheet'] ?? null);
        $rows = apr_sheet_rows((int)$s['id']);
        $cur = null;
        $above = [];
        foreach ($rows as $r) {
            if (!$isNew && $r['key'] === $in['key']) { $cur = $r; break; }
            if ($r['kind'] !== 'note') $above[] = $r['key'];
        }
        if (!$isNew && $cur === null) apr_fail('not_found');
        if ($cur !== null && $cur['kind'] !== $kind) apr_fail('sheet_kind');
        if ($kind === 'input') {
            $row['fact_id'] = apr_val_fact_in($c, $in['fact'] ?? null);
            $row['file_id'] = apr_val_file_in($ctx, $c, $in['file'] ?? null);
        } elseif ($kind === 'calc') {
            [$canon, $err] = apr_sheet_formula($formula, $above);
            if ($err !== null) apr_fail($err);
            $row['formula'] = json_encode($canon, JSON_UNESCAPED_UNICODE);
        }
        $now = apr_now();
        if ($isNew) {
            if (count($rows) >= APR_SHEET_MAX_ROWS) apr_fail('sheet_limit');
            $key = 'r' . (int)$s['next_key'];
            apr_val_update($ctx, 'sheets', $s, $rev, ['next_key' => (int)$s['next_key'] + 1]);
            apr_insert('sheet_rows', ['sheet_id' => $s['id'], 'sort' => count($rows) ? (int)end($rows)['sort'] + 1 : 1, 'key' => $key, 'kind' => $kind, 'updated_by' => (int)$ctx['user']['id'], 'updated_at' => $now] + $row);
            apr_case_audit($c, 'sheet.row_add', 'sheet', $s['pid'], ['key' => $key, 'kind' => $kind, 'label' => $row['label'], 'value' => apr_comp_dec_out($row['value'], 6), 'origin' => $row['origin'], 'formula' => $row['formula']]);
        } else {
            $key = $cur['key'];
            $old = ['formula' => $cur['formula'] === null ? null : json_encode($cur['formula'], JSON_UNESCAPED_UNICODE)] + $cur;
            $changed = array_keys(array_filter($row, fn($v, $f) => $v !== (in_array($f, ['fact_id', 'file_id'], true) && $old[$f] !== null ? (int)$old[$f] : $old[$f]), ARRAY_FILTER_USE_BOTH));
            if (!$changed) apr_fail('nothing_to_change');
            apr_val_update($ctx, 'sheets', $s, $rev, []);
            apr_exec('UPDATE sheet_rows SET ' . implode(', ', array_map(fn($f) => "$f = ?", array_keys($row))) . ', updated_by = ?, updated_at = ? WHERE id = ?', [...array_values($row), (int)$ctx['user']['id'], $now, $cur['id']]);
            apr_case_audit($c, 'sheet.row_update', 'sheet', $s['pid'], ['key' => $key, 'fields' => $changed]
                + (in_array('value', $changed, true) ? ['from' => apr_comp_dec_out($cur['value'], 6), 'to' => apr_comp_dec_out($row['value'], 6)] : [])
                + (in_array('formula', $changed, true) ? ['formula_from' => $old['formula'], 'formula_to' => $row['formula']] : []));
        }
        apr_val_fact_sync($c, 'calc', $s['pid'], apr_sheet_fact_ids((int)$s['id']));
        apr_case_touch($c);
        return ['sheet' => $s['pid'], 'key' => $key, 'rev' => $rev + 1];
    });
}

/** sheet-row-delete {sheet, rev, key} — השורה נמחקת; שורה שהפנתה אליה נשארת, מסומנת "נוסחה שבורה", ואין לגיליון תוצאה סופית (§10.5). */
function apr_sheet_row_delete(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, apr_doc_case_pid('sheets', $in['sheet'] ?? null), 'judge');
    $rev = apr_comp_rev($in['rev'] ?? null);
    return apr_tx(function () use ($ctx, $c, $in, $rev) {
        $s = apr_sheet_get($c, $in['sheet'] ?? null);
        $r = (apr_sheet_is_key($in['key'] ?? null) ? apr_row('SELECT * FROM sheet_rows WHERE sheet_id = ? AND key = ?', [$s['id'], $in['key']]) : null) ?? apr_fail('not_found');
        apr_val_update($ctx, 'sheets', $s, $rev, []);
        apr_exec('DELETE FROM sheet_rows WHERE id = ?', [$r['id']]);
        apr_val_fact_sync($c, 'calc', $s['pid'], apr_sheet_fact_ids((int)$s['id']));
        apr_case_audit($c, 'sheet.row_delete', 'sheet', $s['pid'], ['key' => $r['key'], 'kind' => $r['kind'], 'label' => $r['label'], 'value' => apr_comp_dec_out($r['value'], 6), 'formula' => $r['formula']]);
        apr_case_touch($c);
        return ['sheet' => $s['pid'], 'rev' => $rev + 1];
    });
}

/** sheet-row-order {sheet, rev, order[]} — סדר השורות. שורת חישוב אינה יכולה לעלות מעל שורה שהיא מפנה אליה (§10.6). */
function apr_sheet_row_order(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, apr_doc_case_pid('sheets', $in['sheet'] ?? null), 'judge');
    $rev = apr_comp_rev($in['rev'] ?? null);
    $order = $in['order'] ?? null;
    if (!is_array($order) || !array_is_list($order)) apr_fail('sheet_order');
    return apr_tx(function () use ($ctx, $c, $in, $rev, $order) {
        $s = apr_sheet_get($c, $in['sheet'] ?? null);
        $rows = array_column(apr_sheet_rows((int)$s['id']), null, 'key');
        if (count($order) !== count($rows) || count(array_unique($order, SORT_REGULAR)) !== count($order) || array_filter($order, fn($k) => !is_string($k) || !isset($rows[$k]))) apr_fail('sheet_order');
        if ($order === array_keys($rows)) apr_fail('nothing_to_change');
        $pos = array_flip($order);
        foreach ($rows as $key => $r) {
            // הפניה לשורה שכבר נמחקה (נוסחה שבורה) אינה חוסמת סידור; הפניה לשורה קיימת חייבת להישאר מעל
            foreach ($r['kind'] === 'calc' ? apr_sheet_refs($r['formula']) : [] as $ref) if (isset($pos[$ref]) && $pos[$ref] >= $pos[$key]) apr_fail('sheet_ref_below');
        }
        apr_val_update($ctx, 'sheets', $s, $rev, []);
        foreach ($order as $i => $key) apr_exec('UPDATE sheet_rows SET sort = ? WHERE id = ?', [$i + 1, $rows[$key]['id']]);
        apr_case_audit($c, 'sheet.row_order', 'sheet', $s['pid'], ['from' => array_keys($rows), 'to' => $order]);
        return ['sheet' => $s['pid'], 'rev' => $rev + 1];
    });
}

/** sheet-delete {sheet, rev} — מחיקה רכה. גיליון שגישה נשענת עליו אינו נמחק — קודם מנתקים אותו מהגישה. */
function apr_sheet_delete(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, apr_doc_case_pid('sheets', $in['sheet'] ?? null), 'judge');
    $rev = apr_comp_rev($in['rev'] ?? null);
    return apr_tx(function () use ($ctx, $c, $in, $rev) {
        $s = apr_sheet_get($c, $in['sheet'] ?? null);
        if (apr_val('SELECT 1 FROM approaches p JOIN alternatives a ON a.id = p.alternative_id WHERE p.sheet_id = ? AND p.deleted_at IS NULL AND a.deleted_at IS NULL', [$s['id']])) apr_fail('sheet_in_use');
        apr_val_update($ctx, 'sheets', $s, $rev, ['deleted_at' => apr_now()]);
        apr_fact_unuse($c, 'calc', $s['pid']);
        apr_case_audit($c, 'sheet.delete', 'sheet', $s['pid'], ['title' => $s['title']]);
        apr_case_touch($c);
        return ['deleted' => true];
    });
}

/** sheet-recheck {sheet, rev} — "נבדק מחדש" אחרי שנתון מקושר השתנה: הסימון stale יורד, ונרשם ביומן (16-C4). */
function apr_sheet_recheck(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, apr_doc_case_pid('sheets', $in['sheet'] ?? null), 'judge');
    $rev = apr_comp_rev($in['rev'] ?? null);
    return apr_tx(function () use ($ctx, $c, $in, $rev) {
        $s = apr_sheet_get($c, $in['sheet'] ?? null);
        if (!apr_val_stale($c, 'calc', $s['pid'])) apr_fail('nothing_to_change');
        apr_val_update($ctx, 'sheets', $s, $rev, []);
        apr_val_fact_sync($c, 'calc', $s['pid'], apr_sheet_fact_ids((int)$s['id']), true);
        apr_case_audit($c, 'sheet.recheck', 'sheet', $s['pid']);
        return ['sheet' => $s['pid'], 'rev' => $rev + 1];
    });
}

/** sheet {sheet} — גיליון: השורות, לכל שורת חישוב הנוסחה במילים ובמספרים והתוצאה (או למה אין), התוצאה של הגיליון, ומי נשען עליו. */
function apr_sheet_view(array $ctx, array $q): array
{
    $c = apr_comp_case($ctx, apr_doc_case_pid('sheets', $q['sheet'] ?? null));
    $s = apr_sheet_get($c, $q['sheet'] ?? null);
    [$rows, $vals, $res] = apr_sheet_compute($s);
    return ['case' => apr_comp_case_head($c), 'perms' => apr_comp_perms($ctx, $c), 'sheet' => apr_sheet_head_out($c, $s), 'rows' => apr_sheet_rows_out($ctx, $c, $rows, $vals),
        'result' => apr_sheet_result_out($rows, $res),
        'used_by' => array_map(fn($r) => ['alt' => $r['alt_name'], 'approach' => $r['title']], apr_rows('SELECT p.title, a.name AS alt_name FROM approaches p JOIN alternatives a ON a.id = p.alternative_id
            WHERE p.sheet_id = ? AND p.deleted_at IS NULL AND a.deleted_at IS NULL ORDER BY a.sort, p.sort, p.id', [$s['id']])),
        'lists' => ['kinds' => APR_SHEET_ROW_KINDS, 'time_units' => APR_SHEET_TIME_UNITS, 'origins' => APR_SHEET_ORIGINS, 'ops' => APR_SHEET_OPS, 'roundings' => APR_COMP_ROUNDINGS, 'max_rows' => APR_SHEET_MAX_ROWS],
        'pick' => apr_val_pick($ctx, $c)];
}

// ── תבניות גיליון (§5): מבנה ותיאורים בלבד ────────────────────

/** מה שנשמר בתבנית משורה: **רשימה סגורה של שדות** — בלי value, בלי מקור, בלי נתון, בלי קובץ ובלי נימוק (§10.10, 16-B6). */
function apr_sheet_template_rows(array $rows): array
{
    return array_map(fn($r) => ['key' => $r['key'], 'kind' => $r['kind'], 'label' => $r['label'], 'unit' => $r['unit'], 'time_unit' => $r['time_unit'], 'formula' => $r['formula']], $rows);
}

function apr_sheet_template_row(array $ctx, mixed $pid): array
{
    $office = apr_office_id($ctx);
    return (apr_is_pid($pid) ? apr_row('SELECT * FROM sheet_templates WHERE pid = ? AND office_id = ? AND deleted_at IS NULL AND (owner_user_id IS NULL OR owner_user_id = ?)', [$pid, $office, $ctx['user']['id']]) : null) ?? apr_fail('not_found');
}

/** sheet-templates — התבניות שאני רואה: של המשרד, והאישיות שלי. */
function apr_sheet_templates(array $ctx): array
{
    $office = apr_office($ctx);
    $uid = (int)$ctx['user']['id'];
    return ['templates' => array_map(fn($t) => ['pid' => $t['pid'], 'name' => $t['name'], 'scope' => $t['owner_user_id'] === null ? 'office' : 'personal', 'rounding' => $t['rounding'],
        'rows' => count(json_decode($t['rows'], true) ?: []), 'by' => $t['by_name'], 'created_at' => $t['created_at'],
        'can_delete' => (int)$t['created_by'] === $uid || ($t['owner_user_id'] === null && (int)$office['is_admin'] === 1)],
        apr_rows('SELECT t.*, u.name AS by_name FROM sheet_templates t LEFT JOIN users u ON u.id = t.created_by WHERE t.office_id = ? AND t.deleted_at IS NULL AND (t.owner_user_id IS NULL OR t.owner_user_id = ?) ORDER BY t.name, t.id',
            [(int)$office['office_id'], $uid]))];
}

/** sheet-template-save {sheet, name, scope: personal | office} — שומר את מבנה הגיליון כתבנית. הערכים אינם נשמרים — אין שדה שיכיל אותם. */
function apr_sheet_template_save(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, apr_doc_case_pid('sheets', $in['sheet'] ?? null), 'judge');
    $name = apr_clean_name($in['name'] ?? null, 80);
    $scope = is_string($in['scope'] ?? null) && in_array($in['scope'], ['personal', 'office'], true) ? $in['scope'] : apr_fail('sheet_template_scope');
    return apr_tx(function () use ($ctx, $c, $in, $name, $scope) {
        $s = apr_sheet_get($c, $in['sheet'] ?? null);
        [$rows, $vals] = apr_sheet_compute($s);
        if (!$rows) apr_fail('sheet_empty');
        if (array_filter($vals, fn($v) => $v['reason'] === 'broken_ref')) apr_fail('sheet_broken');
        $owner = $scope === 'personal' ? (int)$ctx['user']['id'] : null;
        if ((int)apr_val('SELECT COUNT(*) FROM sheet_templates WHERE office_id = ? AND deleted_at IS NULL AND owner_user_id IS ?', [$c['office_id'], $owner]) >= APR_SHEET_TEMPLATE_MAX) apr_fail('sheet_limit');
        $pid = apr_pid();
        apr_insert('sheet_templates', ['pid' => $pid, 'office_id' => $c['office_id'], 'owner_user_id' => $owner, 'name' => $name, 'rounding' => $s['rounding'],
            'rows' => json_encode(apr_sheet_template_rows($rows), JSON_UNESCAPED_UNICODE), 'created_by' => (int)$ctx['user']['id'], 'created_at' => apr_now()]);
        apr_case_audit($c, 'sheet.template_save', 'sheet_template', $pid, ['scope' => $scope, 'rows' => count($rows)]);
        return ['template' => $pid];
    });
}

/** sheet-template-use {case, template, title?} — גיליון חדש בתיק, מהמבנה של התבנית: אותן שורות ונוסחאות, כל הקלטים ריקים. */
function apr_sheet_template_use(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, $in['case'] ?? null, 'judge');
    $t = apr_sheet_template_row($ctx, $in['template'] ?? null);
    if ((int)$t['office_id'] !== (int)$c['office_id']) apr_fail('not_found');
    $title = apr_clean_text($in['title'] ?? '', 120) ?? $t['name'];
    return apr_tx(function () use ($ctx, $c, $t, $title) {
        $pid = apr_sheet_insert($ctx, $c, $title, $t['rounding']);
        $id = (int)apr_val('SELECT id FROM sheets WHERE pid = ?', [$pid]);
        $above = [];
        $max = 0;
        $now = apr_now();
        foreach (array_slice(json_decode($t['rows'], true) ?: [], 0, APR_SHEET_MAX_ROWS) as $i => $r) {
            if (!is_array($r) || !apr_sheet_is_key($r['key'] ?? null) || !in_array($r['kind'] ?? '', APR_SHEET_ROW_KINDS, true)) apr_fail('sheet_broken');
            $formula = null;
            if ($r['kind'] === 'calc') {
                [$canon, $err] = apr_sheet_formula($r['formula'] ?? null, $above);
                if ($err !== null) apr_fail('sheet_broken');
                $formula = json_encode($canon, JSON_UNESCAPED_UNICODE);
            }
            // רק מבנה ותיאורים: value, origin, source_note, fact_id, file_id ו-reasoning אינם מועתקים — הם אינם קיימים בתבנית
            apr_insert('sheet_rows', ['sheet_id' => $id, 'sort' => $i + 1, 'key' => $r['key'], 'kind' => $r['kind'], 'label' => apr_clean_text($r['label'] ?? '', 120) ?? '—',
                'unit' => apr_clean_text($r['unit'] ?? '', 20), 'time_unit' => in_array($r['time_unit'] ?? '', APR_SHEET_TIME_UNITS, true) ? $r['time_unit'] : 'none', 'formula' => $formula,
                'updated_by' => (int)$ctx['user']['id'], 'updated_at' => $now]);
            if ($r['kind'] !== 'note') $above[] = $r['key'];
            $max = max($max, (int)substr($r['key'], 1));
        }
        apr_exec('UPDATE sheets SET next_key = ?, rev = rev + 1 WHERE id = ?', [$max + 1, $id]);
        apr_case_audit($c, 'sheet.add', 'sheet', $pid, ['template' => $t['pid'], 'title' => $title]);
        apr_case_touch($c);
        return ['sheet' => $pid];
    });
}

/** sheet-template-delete {template} — תבנית אישית: בעליה; תבנית משרדית: מי שיצר אותה, או מנהל המשרד. */
function apr_sheet_template_delete(array $ctx, array $in): array
{
    $t = apr_sheet_template_row($ctx, $in['template'] ?? null);
    $office = apr_office($ctx);
    if ((int)$t['created_by'] !== (int)$ctx['user']['id'] && !($t['owner_user_id'] === null && (int)$office['is_admin'] === 1)) apr_fail('forbidden');
    return apr_tx(function () use ($t, $office) {
        apr_exec('UPDATE sheet_templates SET deleted_at = ? WHERE id = ? AND deleted_at IS NULL', [apr_now(), $t['id']]);
        apr_audit('sheet.template_delete', 'sheet_template', $t['pid'], ['scope' => $t['owner_user_id'] === null ? 'office' : 'personal'], null, (int)$office['office_id']);
        return ['deleted' => true];
    });
}

// ── גישות (§6) ────────────────────────────────────────────────

function apr_approach_row(array $c, mixed $pid): array
{
    return (apr_is_pid($pid) ? apr_row('SELECT p.*, a.pid AS alt_pid, a.state_kind AS alt_state FROM approaches p JOIN alternatives a ON a.id = p.alternative_id
        WHERE p.pid = ? AND p.case_id = ? AND p.deleted_at IS NULL AND a.deleted_at IS NULL', [$pid, $c['id']]) : null) ?? apr_fail('not_found');
}

/**
 * approach-save {alt | approach, rev?, kind, title?, reasoning, comp_set?, sheet?, weight?} — גישה חדשה בחלופה, או עדכון. הסוג אינו משתנה.
 * **אין גישה בלי נימוק** (למה היא מתאימה, ולמה במשקל הזה). comparison נשענת על קבוצת השוואה; השאר — על גיליון, או על ערך מוקלד.
 */
function apr_approach_save(array $ctx, array $in): array
{
    $isNew = ($in['approach'] ?? null) === null || $in['approach'] === '';
    $c = apr_comp_case($ctx, apr_doc_case_pid($isNew ? 'alternatives' : 'approaches', $in[$isNew ? 'alt' : 'approach'] ?? null), 'judge');
    $rev = $isNew ? 0 : apr_comp_rev($in['rev'] ?? null);
    $kind = is_string($in['kind'] ?? null) && in_array($in['kind'], APR_APPROACH_KINDS, true) ? $in['kind'] : apr_fail('approach_kind');
    $row = ['title' => apr_clean_text($in['title'] ?? '', 80) ?? APR_APPROACH_TITLES[$kind], 'reasoning' => apr_clean_text($in['reasoning'] ?? '', 1000) ?? apr_fail('reason_required'),
        'weight' => apr_comp_dec($in['weight'] ?? null, 6, 6, 'comp_weight')];
    return apr_tx(function () use ($ctx, $c, $in, $isNew, $rev, $kind, $row) {
        $p = $isNew ? null : apr_approach_row($c, $in['approach']);
        $a = $isNew ? apr_alt_row($c, $in['alt'] ?? null) : apr_alt_row($c, $p['alt_pid']);
        if ($p !== null && $p['kind'] !== $kind) apr_fail('approach_kind');
        $setPid = $kind === 'comparison' && ($in['comp_set'] ?? null) !== null && $in['comp_set'] !== '' ? $in['comp_set'] : null;
        $sheetPid = $kind !== 'comparison' && ($in['sheet'] ?? null) !== null && $in['sheet'] !== '' ? $in['sheet'] : null;
        $set = $setPid !== null ? apr_comp_set_row($c, $setPid) : null;
        $sheet = $sheetPid !== null ? apr_sheet_get($c, $sheetPid) : null;
        // 27.4: קבוצה שמשמשת חלופת "אחרי שיפוץ" — עסקה שמצבה לא ידוע רק עם נימוק מפורש
        if ($set && $a['state_kind'] === 'after_works' && apr_val_set_unreasoned((int)$set['id'])) apr_fail('alt_condition_unknown');
        $row += ['comp_set_id' => $set ? (int)$set['id'] : null, 'sheet_id' => $sheet ? (int)$sheet['id'] : null];
        $now = apr_now();
        if ($isNew) {
            if ((int)apr_val('SELECT COUNT(*) FROM approaches WHERE alternative_id = ? AND deleted_at IS NULL', [$a['id']]) >= APR_APPROACH_MAX) apr_fail('approach_limit');
            $pid = apr_pid();
            apr_insert('approaches', ['pid' => $pid, 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'alternative_id' => $a['id'], 'kind' => $kind,
                'sort' => (int)apr_val('SELECT COALESCE(MAX(sort), 0) + 1 FROM approaches WHERE alternative_id = ?', [$a['id']]),
                'created_by' => (int)$ctx['user']['id'], 'updated_by' => (int)$ctx['user']['id'], 'created_at' => $now, 'updated_at' => $now] + $row);
            apr_case_audit($c, 'approach.add', 'approach', $pid, ['alt' => $a['pid'], 'kind' => $kind, 'comp_set' => $setPid, 'sheet' => $sheetPid, 'weight' => apr_comp_dec_out($row['weight'], 6)], $row['reasoning']);
            apr_case_touch($c);
            return ['approach' => $pid, 'rev' => 1];
        }
        $from = [];
        $to = [];
        foreach ($row as $f => $v) {
            $old = in_array($f, ['weight', 'comp_set_id', 'sheet_id'], true) && $p[$f] !== null ? (int)$p[$f] : $p[$f];
            if ($old !== $v) [$from[$f], $to[$f]] = [$f === 'weight' ? apr_comp_dec_out($old, 6) : $old, $f === 'weight' ? apr_comp_dec_out($v, 6) : $v];
        }
        if (!$from) apr_fail('nothing_to_change');
        apr_val_update($ctx, 'approaches', $p, $rev, $row);
        apr_case_audit($c, 'approach.update', 'approach', $p['pid'], ['alt' => $a['pid'], 'fields' => array_keys($from)] + (isset($from['weight']) ? ['from' => $from['weight'], 'to' => $to['weight']] : []), $row['reasoning']);
        apr_case_touch($c);
        return ['approach' => $p['pid'], 'rev' => $rev + 1];
    });
}

/**
 * approach-result {approach, rev, source: computed | entered, value?, reason?} — מאיפה התוצאה של הגישה: נגזרת (מהקבוצה / מהגיליון),
 * או ערך שהשמאי מקליד — **רק עם נימוק** (16-A4). גישת ההשוואה נגזרת תמיד. שום פעולה אחרת אינה כותבת את result_value.
 */
function apr_approach_result(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, apr_doc_case_pid('approaches', $in['approach'] ?? null), 'judge');
    $rev = apr_comp_rev($in['rev'] ?? null);
    $source = is_string($in['source'] ?? null) && in_array($in['source'], ['computed', 'entered'], true) ? $in['source'] : apr_fail('approach_source');
    $value = $source === 'entered' ? (apr_comp_dec($in['value'] ?? null, 2, 11) ?? apr_fail('approach_value')) : null;
    $reason = $source === 'entered' ? apr_reason($in['reason'] ?? null) : null;
    return apr_tx(function () use ($ctx, $c, $in, $rev, $source, $value, $reason) {
        $p = apr_approach_row($c, $in['approach'] ?? null);
        if ($source === 'entered' && $p['kind'] === 'comparison') apr_fail('approach_source');
        $old = $p['result_value'] === null ? null : (int)$p['result_value'];
        if ($p['result_source'] === $source && $old === $value && $p['result_reason'] === $reason) apr_fail('nothing_to_change');
        apr_val_update($ctx, 'approaches', $p, $rev, ['result_source' => $source, 'result_value' => $value, 'result_reason' => $reason,
            'result_by' => $source === 'entered' ? (int)$ctx['user']['id'] : null, 'result_at' => $source === 'entered' ? apr_now() : null]);
        apr_case_audit($c, 'approach.result', 'approach', $p['pid'], ['alt' => $p['alt_pid'], 'source_from' => $p['result_source'], 'source' => $source, 'from' => apr_comp_dec_out($old, 2), 'to' => apr_comp_dec_out($value, 2)], $reason);
        apr_case_touch($c);
        return ['approach' => $p['pid'], 'rev' => $rev + 1];
    });
}

/** approach-delete {approach, rev} — מחיקה רכה; הקבוצה והגיליון נשארים. */
function apr_approach_delete(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, apr_doc_case_pid('approaches', $in['approach'] ?? null), 'judge');
    $rev = apr_comp_rev($in['rev'] ?? null);
    return apr_tx(function () use ($ctx, $c, $in, $rev) {
        $p = apr_approach_row($c, $in['approach'] ?? null);
        apr_val_update($ctx, 'approaches', $p, $rev, ['deleted_at' => apr_now()]);
        apr_case_audit($c, 'approach.delete', 'approach', $p['pid'], ['alt' => $p['alt_pid'], 'kind' => $p['kind'], 'title' => $p['title']]);
        apr_case_touch($c);
        return ['deleted' => true];
    });
}

/**
 * התוצאה של גישה, באגורות — מחושבת בכל קריאה (אין תוצאה מחושבת שמורה): [אגורות (מחרוזת) | null, סיבה | null, פרטים].
 *   entered    — הערך שהשמאי הקליד.
 *   comparison — המסקנה של קבוצת ההשוואה (15): בקבוצה כוללת — המסקנה; בקבוצה למ"ר — המסקנה × השטח הנישום (חישוב העזר של 15).
 *   אחרות      — התוצאה של הגיליון (השורה האחרונה, אחרי העיגול היחיד).
 * כשאי אפשר — null וסיבה; לעולם לא אפס.
 */
function apr_approach_calc(array $ctx, array $c, array $p): array
{
    if ($p['result_source'] === 'entered') return [(string)(int)$p['result_value'], null, []];
    if ($p['kind'] === 'comparison') {
        if ($p['comp_set_id'] === null) return [null, 'no_set', []];
        $g = apr_row('SELECT g.*, ub.name AS updated_name, cb.name AS conclusion_name FROM comp_sets g LEFT JOIN users ub ON ub.id = g.updated_by LEFT JOIN users cb ON cb.id = g.conclusion_by WHERE g.id = ? AND g.case_id = ?', [$p['comp_set_id'], $c['id']]);
        if (!$g || $g['deleted_at'] !== null) return [null, 'set_deleted', []];
        $info = ['set' => ['pid' => $g['pid'], 'name' => $g['name'], 'unit_basis' => $g['unit_basis']], 'unreasoned' => count(apr_val_set_unreasoned((int)$g['id']))];
        if ($g['conclusion_value'] === null) return [null, 'no_conclusion', $info];
        if ($g['unit_basis'] === 'total') return [(string)(int)$g['conclusion_value'], null, $info];
        $sub = apr_comp_set_subject($ctx, $c, $g);
        if (!$sub || $sub['total'] === null) return [null, 'no_subject_area', $info];
        return [$sub['total']['agorot'], null, $info + ['subject_area' => $sub['area'], 'subject_stale' => $sub['stale'], 'per_sqm' => apr_comp_dec_out($g['conclusion_value'], 2)]];
    }
    if ($p['sheet_id'] === null) return [null, 'no_sheet', []];
    $s = apr_row('SELECT * FROM sheets WHERE id = ? AND case_id = ?', [$p['sheet_id'], $c['id']]);
    if (!$s || $s['deleted_at'] !== null) return [null, 'sheet_deleted', []];
    [$rows, , $res] = apr_sheet_compute($s);
    $labels = array_column($rows, 'label', 'key');
    $info = ['sheet' => ['pid' => $s['pid'], 'title' => $s['title'], 'stale' => apr_val_stale($c, 'calc', $s['pid'])], 'ref_label' => $res['ref'] === null ? null : ($labels[$res['ref']] ?? null)];
    return [$res['agorot'], $res['reason'], $info];
}

// ── רכישה ושיפוץ (§7) ─────────────────────────────────────────

function apr_renovation_row(array $c, mixed $pid): array
{
    return (apr_is_pid($pid) ? apr_row('SELECT * FROM renovation_items WHERE pid = ? AND case_id = ? AND deleted_at IS NULL', [$pid, $c['id']]) : null) ?? apr_fail('not_found');
}

/**
 * renovation-add {case, kind, amount?, item_date?, provider?, scope_note?, file?, fact?, supersedes?} — פריט רכישה או שיפוץ: מחיר חוזי,
 * תקציב מוצהר, הצעת מחיר, או עלות שאומתה. **כל סוג נשמר בנפרד, ואף אחד מהם אינו נכנס לשום חישוב** (16-C1, 16-C3).
 * סכום חסר נשאר NULL ("לא ידוע") — מותר כשיש מסמך (§10.7). supersedes: גרסה חדשה של הצעת מחיר; הקודמת נשארת, וחלופת
 * "אחרי שיפוץ" מסומנת לסקירה מחדש (16-C2). פריט אינו נערך — תיקון הוא פריט חדש.
 */
function apr_renovation_add(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, $in['case'] ?? null, 'write');
    $kind = is_string($in['kind'] ?? null) && in_array($in['kind'], APR_RENOVATION_KINDS, true) ? $in['kind'] : apr_fail('renovation_kind');
    $row = ['kind' => $kind, 'amount' => apr_comp_dec($in['amount'] ?? null, 2, 11), 'item_date' => apr_clean_date($in['item_date'] ?? null),
        'provider' => apr_clean_text($in['provider'] ?? '', 120), 'scope_note' => apr_clean_text($in['scope_note'] ?? '', 1000) ?? ''];
    return apr_tx(function () use ($ctx, $c, $in, $kind, $row) {
        $row['file_id'] = apr_val_file_in($ctx, $c, $in['file'] ?? null);
        $row['fact_id'] = apr_val_fact_in($c, $in['fact'] ?? null);
        if ($row['amount'] === null && $row['file_id'] === null) apr_fail('renovation_empty');
        if ((int)apr_val('SELECT COUNT(*) FROM renovation_items WHERE case_id = ? AND deleted_at IS NULL', [$c['id']]) >= APR_RENOVATION_MAX) apr_fail('renovation_limit');
        $prev = null;
        if (($in['supersedes'] ?? null) !== null && $in['supersedes'] !== '') {
            if ($kind !== 'quote') apr_fail('renovation_kind');
            $prev = apr_renovation_row($c, $in['supersedes']);
            if ($prev['kind'] !== 'quote') apr_fail('renovation_kind');
            if (apr_val('SELECT 1 FROM renovation_items WHERE supersedes_id = ? AND deleted_at IS NULL', [$prev['id']])) apr_fail('renovation_superseded');
            $row['provider'] ??= $prev['provider'];
        }
        $pid = apr_pid();
        apr_insert('renovation_items', ['pid' => $pid, 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'version_no' => $prev ? (int)$prev['version_no'] + 1 : 1, 'supersedes_id' => $prev['id'] ?? null,
            'entered_by' => (int)$ctx['user']['id'], 'created_at' => apr_now()] + $row);
        apr_case_audit($c, 'renovation.add', 'renovation_item', $pid, ['kind' => $kind, 'amount' => apr_comp_dec_out($row['amount'], 2), 'version' => $prev ? (int)$prev['version_no'] + 1 : 1, 'supersedes' => $prev['pid'] ?? null]);
        $review = $prev ? apr_alt_works_changed($c, 'quote_replaced') : 0;
        apr_case_touch($c);
        return ['item' => $pid, 'version' => $prev ? (int)$prev['version_no'] + 1 : 1, 'review' => $review];
    });
}

/** renovation-delete {item, reason} — מחיקה רכה של פריט שהוזן בטעות. גרסה שכבר הוחלפה אינה נמחקת — קודם מוחקים את הגרסה שמחליפה אותה. */
function apr_renovation_delete(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, apr_doc_case_pid('renovation_items', $in['item'] ?? null), 'write');
    $reason = apr_reason($in['reason'] ?? null);
    return apr_tx(function () use ($ctx, $c, $in, $reason) {
        $r = apr_renovation_row($c, $in['item'] ?? null);
        if (apr_val('SELECT 1 FROM renovation_items WHERE supersedes_id = ? AND deleted_at IS NULL', [$r['id']])) apr_fail('renovation_superseded');
        // מי שרק מזין מוחק את מה שהזין בעצמו; פריט של אחר — מי שרשאי להכריע בתיק
        if ((int)$r['entered_by'] !== (int)$ctx['user']['id'] && !apr_comp_can_judge($ctx, $c)) apr_fail('forbidden');
        apr_exec('UPDATE renovation_items SET deleted_at = ?, deleted_by = ?, delete_reason = ? WHERE id = ? AND deleted_at IS NULL', [apr_now(), (int)$ctx['user']['id'], $reason, $r['id']]);
        apr_case_audit($c, 'renovation.delete', 'renovation_item', $r['pid'], ['kind' => $r['kind'], 'amount' => apr_comp_dec_out($r['amount'], 2), 'version' => (int)$r['version_no']], $reason);
        apr_case_touch($c);
        return ['deleted' => true];
    });
}

/**
 * פריטי הרכישה והשיפוץ, **כל סוג ברשימה משלו** — אין שדה מאוחד ואין סכום כולל (16-C3). להצעת מחיר: superseded_by — הגרסה
 * שהחליפה אותה (הקודמת נשארת ברשימה).
 */
function apr_renovation_out(array $ctx, array $c): array
{
    $out = array_fill_keys(APR_RENOVATION_KINDS, []);
    $rows = apr_rows('SELECT r.*, u.name AS entered_name, p.pid AS supersedes_pid FROM renovation_items r LEFT JOIN users u ON u.id = r.entered_by LEFT JOIN renovation_items p ON p.id = r.supersedes_id
        WHERE r.case_id = ? AND r.deleted_at IS NULL ORDER BY r.id', [$c['id']]);
    $next = [];
    foreach ($rows as $r) if ($r['supersedes_id'] !== null) $next[(int)$r['supersedes_id']] = $r['pid'];
    foreach ($rows as $r) {
        $out[$r['kind']][] = ['pid' => $r['pid'], 'amount' => $r['amount'] === null ? null : ['value' => apr_comp_dec_out($r['amount'], 2), 'agorot' => (string)(int)$r['amount']],
            'item_date' => $r['item_date'], 'provider' => $r['provider'], 'version_no' => (int)$r['version_no'], 'supersedes' => $r['supersedes_pid'], 'superseded_by' => $next[(int)$r['id']] ?? null,
            'scope_note' => $r['scope_note'], 'file' => apr_val_file_ref($ctx, $c, $r['file_id']), 'fact' => apr_val_fact_ref($c, $r['fact_id']),
            'entered_by' => $r['entered_name'], 'created_at' => $r['created_at'], 'mine' => (int)$r['entered_by'] === (int)$ctx['user']['id']];
    }
    return $out;
}

// ── קריאה ─────────────────────────────────────────────────────

/** מה אפשר לקשר (נתונים וקבצים של התיק) — רק למי שמזין, ורק מה שהוא רואה. */
function apr_val_pick(array $ctx, array $c): array
{
    if (empty(apr_comp_perms($ctx, $c)['write'])) return ['facts' => [], 'files' => []];
    $facts = [];
    if (apr_can($c, 'facts')) {
        foreach (apr_rows("SELECT * FROM facts WHERE case_id = ? AND deleted_at IS NULL AND state <> 'rejected' ORDER BY id DESC LIMIT 300", [$c['id']]) as $f) {
            if (!apr_fact_has_value($f)) continue;
            $v = $f['value_num'] !== null ? apr_fact_num_out($f['value_num']) : ($f['value_text'] ?? $f['value_date']);
            $facts[] = ['pid' => $f['pid'], 'label' => apr_attr($f['attribute_key'])['label'] . ($v !== null && $v !== '' ? ': ' . mb_substr((string)$v, 0, 40) : '')];
        }
    }
    $files = [];
    foreach (apr_rows('SELECT * FROM files WHERE case_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 300', [$c['id']]) as $f) {
        if (apr_file_visible($ctx, $c, $f)) $files[] = ['pid' => $f['pid'], 'name' => $f['original_name']];
    }
    return ['facts' => $facts, 'files' => $files];
}

function apr_alt_out(array $ctx, array $c, array $a, array $assumptions, array $approaches): array
{
    // "חסרות הנחות" (§4, 16-A3): חלופת after_works בלי הנחה שמקושרת למסמך העבודות (קובץ של התיק שלא נמחק)
    $missing = false;
    if ($a['state_kind'] === 'after_works') {
        $missing = !apr_val('SELECT 1 FROM assumptions s JOIN files f ON f.id = s.file_id WHERE s.alternative_id = ? AND s.deleted_at IS NULL AND f.deleted_at IS NULL', [$a['id']]);
    }
    $items = [];
    $outs = [];
    foreach ($approaches as $p) {
        [$ag, $reason, $info] = apr_approach_calc($ctx, $c, $p);
        $items[] = ['pid' => $p['pid'], 'value' => $ag, 'weight' => $p['weight'] === null ? null : (int)$p['weight']];
        $outs[] = ['pid' => $p['pid'], 'rev' => (int)$p['rev'], 'kind' => $p['kind'], 'title' => $p['title'], 'reasoning' => $p['reasoning'], 'weight' => apr_comp_dec_out($p['weight'], 6),
            'result' => ['source' => $p['result_source'], 'value' => apr_val_money($ag), 'note' => $reason, 'reason' => $p['result_reason'],
                'by' => $p['result_by'] === null ? null : (apr_val('SELECT name FROM users WHERE id = ?', [$p['result_by']]) ?: null), 'at' => $p['result_at']]] + $info;
    }
    $w = apr_sheet_weighted($items);
    $titles = array_column($approaches, 'title', 'pid');
    foreach ($outs as &$o) $o['share'] = isset($w['shares'][$o['pid']]) ? apr_q_decimal(apr_q_mul($w['shares'][$o['pid']], apr_q(100)), 2) : null;
    unset($o);
    return ['pid' => $a['pid'], 'rev' => (int)$a['rev'], 'name' => $a['name'], 'key_date' => $a['key_date'], 'state_kind' => $a['state_kind'], 'state_note' => $a['state_note'], 'is_primary' => (int)$a['is_primary'] === 1,
        'conclusion' => $a['conclusion_value'] === null ? null : ['value' => apr_comp_dec_out($a['conclusion_value'], 2), 'agorot' => (string)(int)$a['conclusion_value'], 'reason' => $a['conclusion_reason'],
            'by' => $a['conclusion_name'] ?? null, 'at' => $a['conclusion_at']],
        'stale' => (int)$a['stale'] === 1 ? ['at' => $a['stale_at'], 'why' => $a['stale_why']] : null, 'facts_stale' => apr_val_stale($c, 'alternative', $a['pid']),
        'missing_assumptions' => $missing, 'assumptions' => array_map(fn($s) => apr_assumption_out($ctx, $c, $s), $assumptions), 'approaches' => $outs,
        // חישוב עזר בלבד (§6) — אינו נכתב לשום מקום, ואינו ממלא את המסקנה
        'weighted' => ['value' => apr_comp_money($w['value']), 'block' => $w['block'], 'ref' => $w['ref'] === null ? null : ($titles[$w['ref']] ?? null)],
        'updated_by' => $a['updated_name'] ?? null, 'updated_at' => $a['updated_at']];
}

/**
 * case-valuation {case} — "חלופות שווי" של תיק: החלופות (מועד קובע, מצב מונח, הנחות, גישות ותוצאותיהן, מסקנה — כל אחת עם שמה),
 * הנחות התיק, הגיליונות, קבוצות ההשוואה שאפשר לקשר, ורכישה ושיפוץ. פריטי הרכישה והשיפוץ הם נתוני התיק: מי שאינו רואה
 * את נתוני התיק (שותף בשיתוף פריטים — 05) אינו מקבל אותם.
 */
function apr_case_valuation(array $ctx, array $q): array
{
    $c = apr_comp_case($ctx, $q['case'] ?? null);
    $byAlt = [];
    $caseLevel = [];
    foreach (apr_rows('SELECT * FROM assumptions WHERE case_id = ? AND deleted_at IS NULL ORDER BY sort, id', [$c['id']]) as $s) {
        if ($s['alternative_id'] === null) $caseLevel[] = $s; else $byAlt[(int)$s['alternative_id']][] = $s;
    }
    $apps = [];
    foreach (apr_rows('SELECT * FROM approaches WHERE case_id = ? AND deleted_at IS NULL ORDER BY sort, id', [$c['id']]) as $p) $apps[(int)$p['alternative_id']][] = $p;
    $sheets = [];
    foreach (apr_rows('SELECT s.*, ub.name AS updated_name FROM sheets s LEFT JOIN users ub ON ub.id = s.updated_by WHERE s.case_id = ? AND s.deleted_at IS NULL ORDER BY s.id', [$c['id']]) as $s) {
        [$rows, , $res] = apr_sheet_compute($s);
        $sheets[] = apr_sheet_head_out($c, $s) + ['rows' => count($rows), 'result' => apr_sheet_result_out($rows, $res)];
    }
    $seeFacts = apr_can($c, 'facts');
    $flags = json_decode((string)$c['flags'], true) ?: [];
    return ['case' => apr_comp_case_head($c) + ['key_date' => $c['key_date']], 'perms' => apr_comp_perms($ctx, $c), 'flags' => ['renovation_planned' => ($flags['renovation_planned'] ?? false) === true],
        'lists' => ['states' => APR_ALT_STATES, 'assumption_kinds' => APR_ASSUMPTION_KINDS, 'approvals' => APR_ASSUMPTION_APPROVALS, 'approach_kinds' => APR_APPROACH_KINDS,
            'renovation_kinds' => APR_RENOVATION_KINDS, 'roundings' => APR_COMP_ROUNDINGS],
        'alternatives' => array_map(fn($a) => apr_alt_out($ctx, $c, $a, $byAlt[(int)$a['id']] ?? [], $apps[(int)$a['id']] ?? []), apr_alts($c)),
        'case_assumptions' => array_map(fn($s) => apr_assumption_out($ctx, $c, $s), $caseLevel),
        'sheets' => $sheets,
        'sets' => array_map(fn($g) => ['pid' => $g['pid'], 'name' => $g['name'], 'unit_basis' => $g['unit_basis'], 'concluded' => $g['conclusion_value'] !== null],
            apr_rows('SELECT pid, name, unit_basis, conclusion_value FROM comp_sets WHERE case_id = ? AND deleted_at IS NULL ORDER BY id', [$c['id']])),
        'renovation' => $seeFacts ? apr_renovation_out($ctx, $c) : null,
        'pick' => apr_val_pick($ctx, $c)];
}
