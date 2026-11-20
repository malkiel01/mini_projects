<?php
/**
 * ביקור בנכס (אפיון 13): כמה ביקורים לתיק, טופס שמצולם מהפרופיל ב"התחלת ביקור", תשובות עם מספר גרסה לכל שדה, סיום
 * (הושלם / נדחה / לא הייתה גישה), והעברת התשובות לנתוני התיק. התמונות: lib/visit_photos.php.
 *
 * הכללים שאינם מתכופפים:
 *  · **"לא מולא" אינו 0 ואינו false** — שדה שלא מולא אינו שורה, ושדה שנוקה הוא שורה בלי ערך (כדי שמספר הגרסה לא יתחיל מחדש).
 *  · **כל שמירת שדה היא UPDATE … WHERE rev = ?** — אפס שורות ← 409 עם הערך שבשרת ומי כתב אותו. אין דריסה.
 *  · **"נדחה" ו"לא הייתה גישה" אינם ביקור שבוצע** — apr_visits_completed() הוא המקום היחיד ששואל "אילו ביקורים בוצעו".
 *  · **נתון נוצר רק ב"הושלם"**, רק דרך apr_fact_insert, במצב manual — אף פעם לא verified; ונתון מאומת אינו משתנה בידי מי שאינו
 *    רשאי לאמת (נוצר לצידו נתון חדש, והסתירה נפתחת במנגנון של פרוסה 10).
 *  · **אין שום שדה של עבירה** — "אי התאמה אפשרית" הוא טקסט שיוצר משימת בדיקה.
 */

declare(strict_types=1);

require_once __DIR__ . '/cases.php';
require_once __DIR__ . '/facts.php';
require_once __DIR__ . '/visit_form.php';

const APR_VISIT_STATUSES = ['planned', 'in_progress', 'completed', 'postponed', 'no_access', 'cancelled'];
const APR_VISIT_SELECT = 'SELECT v.*, vu.pid AS visitor_pid, vu.name AS visitor_name, ct.pid AS contact_pid, ct.name AS contact_name, ct.phone AS contact_phone
    FROM visits v JOIN users vu ON vu.id = v.visitor_user_id LEFT JOIN contacts ct ON ct.id = v.access_contact_id';
const APR_VISIT_ANSWER_SELECT = 'SELECT a.*, u.name AS updated_name FROM visit_answers a LEFT JOIN users u ON u.id = a.updated_by';

/** שגיאה שנושאת פרטים לתשובה: current (409 — הערך שבשרת), missing (422 — מה חסר להשלמה). */
class AprVisitError extends AprError
{
    public function __construct(string $code, public readonly array $extra = [], ?string $message = null)
    {
        parent::__construct($code, $message);
    }
}

// ── גישה ──────────────────────────────────────────────────────

function apr_visit_is_filler(array $ctx, array $c, array $v): bool
{
    $me = (int)$ctx['user']['id'];
    return !empty($c['perms']['visit']) && ($me === (int)$v['visitor_user_id'] || $me === (int)$c['responsible_user_id']);
}

/**
 * הביקור והתיק שלו. ביקור של משרד אחר, של תיק שאינני משתתף בו ומזהה שלא קיים — אותו 404 (13-C5).
 * $need: view · plan (edit בתיק) · fill (visit בתיק, ואני המבקר או האחראי — 13-C6) · any (plan או fill). פעולה משנה על תיק סגור ← 409.
 */
function apr_visit_get(array $ctx, mixed $pid, string $need = 'view'): array
{
    $c = apr_case_get($ctx, apr_doc_case_pid('visits', $pid));
    $v = apr_row(APR_VISIT_SELECT . ' WHERE v.pid = ? AND v.case_id = ?', [$pid, $c['id']]) ?? apr_fail('not_found');
    if ($need === 'view') return [$c, $v];
    $plan = !empty($c['perms']['edit']);
    $fill = apr_visit_is_filler($ctx, $c, $v);
    if (!match ($need) { 'plan' => $plan, 'fill' => $fill, default => $plan || $fill }) apr_fail('forbidden');
    apr_case_writable($c);
    return [$c, $v];
}

function apr_visit_rev(mixed $v, int $min = 1): int
{
    if (!is_int($v) || $v < $min) apr_fail('rev_required');
    return $v;
}

/** מועד: YYYY-MM-DDTHH:MM, שעון ישראל. null / '' ← בלי מועד. */
function apr_visit_when(mixed $v): ?string
{
    if ($v === null || $v === '') return null;
    if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})T([01]\d|2[0-3]):([0-5]\d)\z/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) apr_fail('visit_when');
    return $v;
}

function apr_visit_local(int $ts, string $fmt = 'Y-m-d\\TH:i'): string
{
    return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone(APR_CASE_TZ))->format($fmt);
}

/** משתתף בתיק שיכול לבקר: שורה פעילה ב-case_members עם visit. */
function apr_visit_visitor(array $c, mixed $userPid): int
{
    $m = apr_is_pid($userPid) ? apr_row('SELECT cm.user_id, cm.perms FROM case_members cm JOIN users u ON u.id = cm.user_id WHERE cm.case_id = ? AND u.pid = ? AND cm.revoked_at IS NULL', [$c['id'], $userPid]) : null;
    if (!$m || empty((json_decode($m['perms'], true) ?: [])['visit'])) apr_fail('visit_visitor');
    return (int)$m['user_id'];
}

function apr_visit_contact(array $c, mixed $pid): ?int
{
    if ($pid === null || $pid === '') return null;
    $id = apr_is_pid($pid) ? apr_val('SELECT id FROM contacts WHERE pid = ? AND office_id = ? AND deleted_at IS NULL', [$pid, $c['office_id']]) : false;
    return $id ? (int)$id : apr_fail('not_found');
}

// ── הטופס של ביקור ────────────────────────────────────────────

/** הטופס: מה שצולם ב"התחלת ביקור"; לפני כן — הטופס של גרסת הפרופיל שהתיק נעול עליה (תצוגה בלבד). */
function apr_visit_form(array $c, array $v): array
{
    if ($v['form_snapshot'] !== null) return json_decode($v['form_snapshot'], true) ?: apr_pc_visit([]);
    return apr_case_version_content((int)$c['profile_version_id'])['visit'] ?? apr_visit_default_form();
}

function apr_visit_answers(int $visitId): array
{
    $out = [];
    foreach (apr_rows(APR_VISIT_ANSWER_SELECT . ' WHERE a.visit_id = ? ORDER BY a.id', [$visitId]) as $a) $out[$a['field_key']][(int)$a['row_no']] = $a;
    return $out;
}

function apr_visit_answer_filled(?array $a): bool
{
    return $a !== null && ($a['value_text'] !== null || $a['value_num'] !== null || $a['value_date'] !== null || $a['value_json'] !== null);
}

/** הערך לתצוגה, לפי סוג השדה. null — לא מולא; אף פעם לא 0 ולא false במקומו (13-A3). */
function apr_visit_value_out(?array $a, string $type): mixed
{
    if (!apr_visit_answer_filled($a)) return null;
    return apr_visit_cond_value($a, $type);
}

function apr_visit_answer_out(array $a, string $type): array
{
    return ['field' => $a['field_key'], 'row' => (int)$a['row_no'], 'value' => apr_visit_value_out($a, $type), 'unit' => $a['unit'], 'area_kind' => $a['area_kind'],
        'obtained' => $a['obtained'], 'obtained_from' => $a['obtained_from'], 'note' => $a['note'], 'copied_from' => $a['copied_from_seq'] === null ? null : (int)$a['copied_from_seq'],
        'rev' => (int)$a['rev'], 'updated_by' => $a['updated_name'] ?? null, 'updated_at' => $a['updated_at']];
}

function apr_visit_rows(int $visitId): array
{
    $out = [];
    foreach (apr_rows('SELECT group_key, row_no FROM visit_rows WHERE visit_id = ? AND deleted_at IS NULL ORDER BY row_no', [$visitId]) as $r) $out[$r['group_key']][] = (int)$r['row_no'];
    return $out;
}

/**
 * מה גלוי, מה מולא ומה חסר — המקום היחיד שמחשב את זה (המסך, סימון ההשלמה וההעברה לנתונים שואלים כאן).
 * מחזיר: visible [[field, row]], missing [{field, row, label, group, why}], progress [{group, title, filled, total, missing}].
 * שדה חובה שמוסתר בתנאי אינו נספר (13-A5); קבוצה חוזרת בלי שורות — אין חובה, אלא אם min_rows (מקרה קצה 12);
 * תשובה שהועתקה מביקור אחר ולא אושרה נחשבת חסרה ("לאמת").
 */
function apr_visit_state(array $c, array $v, array $form, ?array $answers = null): array
{
    $answers ??= apr_visit_answers((int)$v['id']);
    $rows = apr_visit_rows((int)$v['id']);
    [$groups, $fields] = apr_visit_form_ordered($form);
    $types = array_column($fields, 'type', 'key');
    $photos = [];
    foreach (apr_rows('SELECT field_key, row_no, COUNT(*) AS n FROM visit_photos WHERE visit_id = ? AND deleted_at IS NULL AND field_key IS NOT NULL GROUP BY field_key, row_no', [$v['id']]) as $p) $photos[$p['field_key']][(int)$p['row_no']] = (int)$p['n'];
    $base = ['property_type' => $c['property_type'], 'purpose' => $c['purpose'], 'answers' => []];
    $repeat = array_column($groups, 'repeat', 'key');
    foreach ($fields as $f) if (!$repeat[$f['group']]) $base['answers'][$f['key']] = apr_visit_cond_value(apr_visit_answer_filled($answers[$f['key']][0] ?? null) ? $answers[$f['key']][0] : null, $f['type']);
    $visible = $missing = $progress = [];
    foreach ($groups as $g) {
        $p = ['group' => $g['key'], 'title' => $g['title'], 'filled' => 0, 'total' => 0, 'missing' => 0, 'hidden' => !apr_visit_eval($g['when'], $base)];
        if (!$p['hidden']) {
            $mine = array_values(array_filter($fields, fn($f) => $f['group'] === $g['key']));
            $rowNos = $g['repeat'] ? ($rows[$g['key']] ?? []) : [0];
            if ($g['repeat'] && count($rowNos) < $g['min_rows']) {
                $missing[] = ['field' => null, 'row' => 0, 'label' => $g['title'], 'group' => $g['key'], 'why' => 'min_rows'];
                $p['missing']++;
            }
            foreach ($rowNos as $row) {
                $in = $base;
                if ($g['repeat']) foreach ($mine as $f) $in['answers'][$f['key']] = apr_visit_cond_value(apr_visit_answer_filled($answers[$f['key']][$row] ?? null) ? $answers[$f['key']][$row] : null, $f['type']);
                foreach ($mine as $f) {
                    if (!apr_visit_eval($f['when'], $in)) continue;
                    $visible[] = [$f['key'], $row];
                    $a = $answers[$f['key']][$row] ?? null;
                    $copied = $a !== null && $a['copied_from_seq'] !== null;
                    $done = $f['type'] === 'photo' ? ($photos[$f['key']][$row] ?? 0) >= ($f['min'] ?? 1) : (apr_visit_answer_filled($a) && !$copied);
                    $p['total']++;
                    if ($done) $p['filled']++;
                    elseif ($f['required']) {
                        $missing[] = ['field' => $f['key'], 'row' => $row, 'label' => $f['label'], 'group' => $g['key'], 'why' => $f['type'] === 'photo' ? 'photo' : ($copied ? 'copied' : 'empty')];
                        $p['missing']++;
                    }
                }
            }
        }
        $progress[] = $p;
    }
    return ['visible' => $visible, 'missing' => $missing, 'progress' => $progress, 'types' => $types];
}

// ── תצוגה ─────────────────────────────────────────────────────

function apr_visit_out(array $v): array
{
    return ['pid' => $v['pid'], 'seq' => (int)$v['seq'], 'status' => $v['status'], 'status_note' => $v['status_note'], 'scheduled_at' => $v['scheduled_at'],
        'started_at' => $v['started_at'], 'completed_at' => $v['status'] === 'completed' ? $v['completed_at'] : null,
        'visitor' => ['pid' => $v['visitor_pid'], 'name' => $v['visitor_name']],
        'access_contact' => $v['contact_pid'] === null ? null : ['pid' => $v['contact_pid'], 'name' => $v['contact_name'], 'phone' => $v['contact_phone']],
        'access_note' => $v['access_note'], 'attendees' => $v['attendees'], 'rev' => (int)$v['rev'],
        // "נדחה" ו"לא הייתה גישה" אינם ביקור שבוצע — המסך מציג תווית משלהם, אף פעם לא "הושלם" (§5)
        'done' => $v['status'] === 'completed'];
}

function apr_visit_perms(array $ctx, array $c, ?array $v = null): array
{
    $open = $c['status'] !== 'closed';
    return ['plan' => $open && !empty($c['perms']['edit']), 'fill' => $open && $v !== null && apr_visit_is_filler($ctx, $c, $v)];
}

/** הביקורים שבוצעו בתיק — **רק `completed`**. כל שאילתה של "ביקור שבוצע" (כרטיס התיק, ובהמשך הדוח והטיוטה) עוברת כאן (13-A6). */
function apr_visits_completed(int $caseId): array
{
    return apr_rows(APR_VISIT_SELECT . " WHERE v.case_id = ? AND v.status = 'completed' ORDER BY v.seq", [$caseId]);
}

/** לכרטיס התיק: כמה ביקורים בוצעו, מתי האחרון, וכמה פתוחים. */
function apr_case_visits_summary(array $c): array
{
    $done = apr_visits_completed((int)$c['id']);
    $by = array_column(apr_rows('SELECT status, COUNT(*) AS n FROM visits WHERE case_id = ? GROUP BY status', [$c['id']]), 'n', 'status');
    return ['completed' => count($done), 'last_completed_at' => $done ? end($done)['completed_at'] : null, 'open' => (int)($by['planned'] ?? 0) + (int)($by['in_progress'] ?? 0) + (int)($by['postponed'] ?? 0),
        'no_access' => (int)($by['no_access'] ?? 0), 'postponed' => (int)($by['postponed'] ?? 0)];
}

/** לשונית "ביקור" בתיק. */
function apr_case_visits(array $ctx, array $q): array
{
    $c = apr_case_get($ctx, $q['case'] ?? null);
    $visitors = array_values(array_filter(apr_rows('SELECT u.pid, u.name, cm.perms, cm.role FROM case_members cm JOIN users u ON u.id = cm.user_id WHERE cm.case_id = ? AND cm.revoked_at IS NULL ORDER BY u.name', [$c['id']]),
        fn($m) => !empty((json_decode($m['perms'], true) ?: [])['visit'])));
    return ['case' => apr_src_case_head($c), 'perms' => apr_visit_perms($ctx, $c), 'summary' => apr_case_visits_summary($c),
        'visits' => array_map(function ($v) use ($ctx, $c) { return apr_visit_out($v) + ['can_fill' => apr_visit_perms($ctx, $c, $v)['fill']]; },
            apr_rows(APR_VISIT_SELECT . ' WHERE v.case_id = ? ORDER BY v.seq DESC', [$c['id']])),
        'visitors' => array_map(fn($m) => ['pid' => $m['pid'], 'name' => $m['name'], 'role' => $m['role']], $visitors),
        'contacts' => array_map(fn($r) => ['pid' => $r['pid'], 'name' => $r['name'], 'phone' => $r['phone']],
            apr_rows('SELECT DISTINCT ct.pid, ct.name, ct.phone FROM case_parties cp JOIN contacts ct ON ct.id = cp.contact_id WHERE cp.case_id = ? AND cp.removed_at IS NULL AND ct.deleted_at IS NULL ORDER BY ct.name', [$c['id']]))];
}

/** מסך הביקור: הטופס, התשובות, השורות, התמונות שמותר לי לראות, ההתקדמות, ומה שהוכן מהתיק (לקריאה). */
function apr_visit_view(array $ctx, array $q): array
{
    [$c, $v] = apr_visit_get($ctx, $q['visit'] ?? null);
    $form = apr_visit_form($c, $v);
    $answers = apr_visit_answers((int)$v['id']);
    $st = apr_visit_state($c, $v, $form, $answers);
    $outA = [];
    foreach ($answers as $key => $byRow) foreach ($byRow as $a) if (isset($st['types'][$key])) $outA[] = apr_visit_answer_out($a, $st['types'][$key]);
    [$groups, $fields] = apr_visit_form_ordered($form);
    $facts = [];
    foreach (apr_rows("SELECT f.*, a.label, a.value_type, a.unit_kind, a.has_area_kind, a.choices FROM facts f JOIN attributes a ON a.key = f.attribute_key
        WHERE f.case_id = ? AND f.deleted_at IS NULL AND f.state <> 'rejected' AND (f.value_num IS NOT NULL OR f.value_text IS NOT NULL OR f.value_date IS NOT NULL) ORDER BY a.sort, f.id LIMIT 80", [$c['id']]) as $f) {
        $facts[] = ['label' => $f['label'], 'state' => $f['state'], 'source' => $f['source_kind']] + apr_fact_value_out($f['value_num'] === null ? null : (int)$f['value_num'], $f['value_text'], $f['value_date'], $f['unit'], $f['area_kind'], $f);
    }
    return ['case' => apr_src_case_head($c) + ['property_type' => $c['property_type'], 'purpose' => $c['purpose']],
        'visit' => apr_visit_out($v) + ['snapshot' => $v['form_snapshot'] !== null, 'was_completed' => $v['first_completed_at'] !== null],
        'perms' => apr_visit_perms($ctx, $c, $v),
        'form' => ['groups' => $groups, 'fields' => $fields, 'completion' => $form['completion'], 'example' => !empty($form['example'])],
        'answers' => $outA, 'rows' => (object)apr_visit_rows((int)$v['id']),
        'photos' => apr_visit_photos_out($ctx, $c, $v),
        'progress' => $st['progress'], 'missing' => $st['missing'],
        'units' => ['area' => array_keys(APR_UNITS['area']), 'length' => array_keys(APR_UNITS['length'])], 'area_kinds' => APR_AREA_KINDS, 'obtained' => APR_VISIT_OBTAINED, 'places' => APR_VISIT_PLACES,
        'geo' => (bool)apr_val('SELECT photo_geo FROM office_visit_settings WHERE office_id = ?', [$c['office_id']]),
        'limits' => ['max_size' => apr_file_max(), 'max_side' => 2560],
        'others' => array_map(fn($o) => ['pid' => $o['pid'], 'seq' => (int)$o['seq'], 'status' => $o['status']], apr_rows('SELECT pid, seq, status FROM visits WHERE case_id = ? AND id <> ? AND form_snapshot IS NOT NULL ORDER BY seq', [$c['id'], $v['id']])),
        'prepared' => ['identifiers' => array_map('apr_ident_out', apr_rows('SELECT * FROM case_identifiers WHERE case_id = ? AND removed_at IS NULL ORDER BY id', [$c['id']])), 'facts' => $facts]];
}

/** ללוח הראשי: הביקורים הקרובים שלי — אני המבקר, התיק פתוח ואני עדיין משתתף בו. */
function apr_my_visits(array $ctx): array
{
    $rows = apr_rows(APR_VISIT_SELECT . " JOIN cases c ON c.id = v.case_id WHERE v.visitor_user_id = ? AND v.status IN ('planned', 'in_progress', 'postponed') AND c.status <> 'closed'
        AND v.case_id IN (SELECT c.id " . apr_my_cases_sql('view') . ') ORDER BY (v.scheduled_at IS NULL), v.scheduled_at, v.id LIMIT 50', [$ctx['user']['id'], $ctx['user']['id'], apr_office_id($ctx)]);
    return ['visits' => array_map(function ($v) {
        $c = apr_row('SELECT pid, number, title FROM cases WHERE id = ?', [$v['case_id']]);
        return apr_visit_out($v) + ['case' => $c];
    }, $rows)];
}

// ── משימות של ביקור ───────────────────────────────────────────

/** המשימה של המבקר: אחת לכל ביקור. מתעדכנת עם המבקר והמועד; נסגרת כשהביקור הסתיים, ומתבטלת כשבוטל. */
function apr_visit_task_sync(array $c, array $v): void
{
    $key = 'visit:' . $v['pid'];
    $t = apr_row('SELECT * FROM tasks WHERE case_id = ? AND origin_key = ? ORDER BY id DESC LIMIT 1', [$c['id'], $key]);
    $due = $v['scheduled_at'] === null ? null : substr($v['scheduled_at'], 0, 10) . 'T00:00:00Z';
    $title = 'ביקור בנכס' . ($v['scheduled_at'] === null ? '' : ' — ' . substr($v['scheduled_at'], 8, 2) . '/' . substr($v['scheduled_at'], 5, 2) . ' ' . substr($v['scheduled_at'], 11, 5));
    $now = apr_now();
    if (in_array($v['status'], ['planned', 'in_progress', 'postponed'], true)) {
        if ($t && in_array($t['status'], ['open', 'in_progress'], true)) {
            apr_exec('UPDATE tasks SET assignee_user_id = ?, due_at = ?, title = ?, updated_at = ? WHERE id = ?', [$v['visitor_user_id'], $due, $title, $now, $t['id']]);
        } else {
            apr_insert('tasks', ['pid' => apr_pid(), 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'kind' => 'system', 'title' => $title, 'assignee_user_id' => $v['visitor_user_id'], 'due_at' => $due,
                'origin_key' => $key, 'link_type' => 'visit', 'link_pid' => $v['pid'], 'created_by' => null, 'created_at' => $now, 'updated_at' => $now]);
        }
    } elseif ($t && in_array($t['status'], ['open', 'in_progress'], true)) {
        if ($v['status'] === 'cancelled') apr_exec("UPDATE tasks SET status = 'cancelled', cancel_reason = ?, updated_at = ? WHERE id = ?", ['הביקור בוטל', $now, $t['id']]);
        else apr_exec("UPDATE tasks SET status = 'done', done_at = ?, updated_at = ? WHERE id = ?", [$now, $now, $t['id']]);
    }
}

// ── תיאום ─────────────────────────────────────────────────────

/** ביקור חדש בתיק (seq הבא): visitor, scheduled_at?, access_contact?, access_note?. הביקורים הקודמים אינם משתנים (13-A7). */
function apr_visit_plan(array $ctx, array $in): array
{
    $c = apr_case_get($ctx, $in['case'] ?? null, 'edit');
    if ($c['status'] === 'draft') apr_fail('visit_case_draft');
    $visitor = apr_visit_visitor($c, $in['visitor'] ?? null);
    $when = apr_visit_when($in['scheduled_at'] ?? null);
    $contact = apr_visit_contact($c, $in['access_contact'] ?? null);
    $note = apr_clean_text($in['access_note'] ?? null, 500);
    return apr_tx(function () use ($ctx, $c, $visitor, $when, $contact, $note) {
        apr_case_still_open($c);
        $pid = apr_pid();
        $seq = (int)apr_val('SELECT COALESCE(MAX(seq), 0) + 1 FROM visits WHERE case_id = ?', [$c['id']]);
        apr_insert('visits', ['pid' => $pid, 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'seq' => $seq, 'visitor_user_id' => $visitor, 'scheduled_at' => $when,
            'access_contact_id' => $contact, 'access_note' => $note, 'created_by' => (int)$ctx['user']['id'], 'created_at' => apr_now(), 'updated_at' => apr_now()]);
        $v = apr_row(APR_VISIT_SELECT . ' WHERE v.pid = ?', [$pid]);
        apr_visit_task_sync($c, $v);
        apr_case_audit($c, 'visit.plan', 'visit', $pid, ['seq' => $seq, 'scheduled_at' => $when]);
        apr_case_touch($c);
        return ['visit' => apr_visit_out($v)];
    });
}

/** העדכון היחיד של שורת ביקור: rev + 1, רק אם rev (ואם נדרש — המצב) לא השתנו. אפס שורות ← 409 עם הביקור שבשרת. */
function apr_visit_set(array $c, array $v, int $rev, array $set, ?array $from = null): array
{
    $set += ['updated_at' => apr_now()];
    $sql = 'UPDATE visits SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($set))) . ', rev = rev + 1 WHERE id = ? AND rev = ?';
    $args = [...array_values($set), $v['id'], $rev];
    if ($from !== null) { $sql .= ' AND status IN (' . implode(',', array_fill(0, count($from), '?')) . ')'; $args = [...$args, ...$from]; }
    $cur = fn() => apr_row(APR_VISIT_SELECT . ' WHERE v.id = ?', [$v['id']]);
    if (apr_exec($sql, $args) !== 1) {
        $now = $cur();
        if ((int)$now['rev'] === $rev) apr_fail('visit_state');       // אותה גרסה, מצב שאינו מתאים לפעולה
        throw new AprVisitError('visit_changed', ['current' => apr_visit_out($now)]);
    }
    return $cur();
}

/** עדכון פרטי הביקור: visit, rev + מה שמשתנה. מבקר, מועד ואיש קשר — edit בתיק; נוכחים והערת גישה — גם המבקר. */
function apr_visit_update(array $ctx, array $in): array
{
    [$c, $v] = apr_visit_get($ctx, $in['visit'] ?? null, 'any');
    $rev = apr_visit_rev($in['rev'] ?? null);
    $plan = !empty($c['perms']['edit']);
    $set = [];
    if (array_intersect(['visitor', 'scheduled_at', 'access_contact'], array_keys($in)) && !$plan) apr_fail('forbidden');
    if (array_key_exists('visitor', $in)) $set['visitor_user_id'] = apr_visit_visitor($c, $in['visitor']);
    if (array_key_exists('scheduled_at', $in)) $set['scheduled_at'] = apr_visit_when($in['scheduled_at']);
    if (array_key_exists('access_contact', $in)) $set['access_contact_id'] = apr_visit_contact($c, $in['access_contact']);
    if (array_key_exists('access_note', $in)) $set['access_note'] = apr_clean_text($in['access_note'], 500);
    if (array_key_exists('attendees', $in)) $set['attendees'] = apr_clean_text($in['attendees'], 500);
    if (!$set) apr_fail('nothing_to_change');
    return apr_tx(function () use ($c, $v, $rev, $set) {
        apr_case_still_open($c);
        // אחרי סיום — רק נוכחים והערות; המבקר והמועד של ביקור שהסתיים אינם משתנים
        $closed = array_intersect(['visitor_user_id', 'scheduled_at'], array_keys($set)) ? ['planned', 'in_progress', 'postponed'] : ['planned', 'in_progress', 'postponed', 'completed', 'no_access'];
        $now = apr_visit_set($c, $v, $rev, $set, $closed);
        apr_visit_task_sync($c, $now);
        apr_case_audit($c, 'visit.update', 'visit', $v['pid'], ['seq' => (int)$v['seq'], 'fields' => array_keys($set)]);
        apr_case_touch($c);
        return ['visit' => apr_visit_out($now)];
    });
}

/** "התחלת ביקור": in_progress, והטופס מצולם מגרסת הפרופיל של התיק (13-A1). ביקור שנדחה ממשיך עם מה שכבר צולם. */
function apr_visit_start(array $ctx, array $in): array
{
    [$c, $v] = apr_visit_get($ctx, $in['visit'] ?? null, 'fill');
    $rev = apr_visit_rev($in['rev'] ?? null);
    return apr_tx(function () use ($c, $v, $rev) {
        apr_case_still_open($c);
        $set = ['status' => 'in_progress', 'status_note' => null];
        if ($v['form_snapshot'] === null) {
            $set += ['form_snapshot' => json_encode(apr_visit_form($c, $v), JSON_UNESCAPED_UNICODE), 'form_version_id' => (int)$c['profile_version_id']];
        }
        if ($v['started_at'] === null) $set['started_at'] = apr_now();
        $now = apr_visit_set($c, $v, $rev, $set, ['planned', 'postponed']);
        apr_visit_task_sync($c, $now);
        apr_case_audit($c, 'visit.start', 'visit', $v['pid'], ['seq' => (int)$v['seq']]);
        apr_case_touch($c);
        return ['visit' => apr_visit_out($now)];
    });
}

/** ביטול ביקור שלא התקיים: edit בתיק, עם סיבה. ביקור שהושלם אי פעם אינו מתבטל. */
function apr_visit_cancel(array $ctx, array $in): array
{
    [$c, $v] = apr_visit_get($ctx, $in['visit'] ?? null, 'plan');
    $rev = apr_visit_rev($in['rev'] ?? null);
    $reason = apr_reason($in['reason'] ?? null);
    return apr_tx(function () use ($c, $v, $rev, $reason) {
        apr_case_still_open($c);
        if ($v['first_completed_at'] !== null) apr_fail('visit_state');
        $now = apr_visit_set($c, $v, $rev, ['status' => 'cancelled', 'status_note' => $reason], ['planned', 'postponed']);
        apr_visit_task_sync($c, $now);
        apr_case_audit($c, 'visit.cancel', 'visit', $v['pid'], ['seq' => (int)$v['seq']], $reason);
        apr_case_touch($c);
        return ['visit' => apr_visit_out($now)];
    });
}

// ── מילוי ─────────────────────────────────────────────────────

function apr_visit_field(array $form, mixed $key): array
{
    foreach ($form['fields'] as $f) if ($f['key'] === $key) return $f;
    apr_fail('visit_field');
}

function apr_visit_group(array $form, mixed $key): array
{
    foreach ($form['groups'] as $g) if ($g['key'] === $key) return $g;
    apr_fail('visit_field');
}

/** מספר שורה של שדה: 0 לשדה רגיל; לשדה בקבוצה חוזרת — שורה קיימת של הביקור. */
function apr_visit_row_no(array $v, array $form, array $f, mixed $row): int
{
    $g = apr_visit_group($form, $f['group']);
    if (!$g['repeat']) {
        if ($row !== null && $row !== 0) apr_fail('visit_field');
        return 0;
    }
    if (!is_int($row) || $row < 1 || !apr_val('SELECT 1 FROM visit_rows WHERE visit_id = ? AND group_key = ? AND row_no = ? AND deleted_at IS NULL', [$v['id'], $g['key'], $row])) apr_fail('visit_field');
    return $row;
}

/**
 * הערך מהקלט, לפי סוג השדה: [value_text, value_num, value_date, value_json, unit, area_kind]. ריק (null / '' / []) ← הכול null.
 * שטח — יחידה וסוג שטח חובה ("לא ידוע" מותר, ריק לא — 13-A4); אורך — יחידה חובה.
 */
function apr_visit_value(array $f, array $in): array
{
    $v = $in['value'] ?? null;
    if ($v === null || $v === '' || $v === []) return [null, null, null, null, null, null];
    $num = fn() => apr_fact_num($v, ['decimals' => 2, 'allow_negative' => 1]);
    switch ($f['type']) {
        case 'text':
        case 'longtext':
            if (!is_string($v)) apr_fail('visit_value');
            $s = trim($v);
            if (mb_strlen($s) > ($f['type'] === 'text' ? 300 : 4000)) apr_fail('text_too_long');
            return [$s === '' ? null : $s, null, null, null, null, null];
        case 'number':
            return [null, $num(), null, null, null, null];
        case 'area':
        case 'length':
            $unit = $in['unit'] ?? null;
            if (!is_string($unit) || !isset(APR_UNITS[$f['type']][$unit])) apr_fail('fact_unit');
            $kind = null;
            if ($f['type'] === 'area') {
                $kind = $in['area_kind'] ?? null;
                if (!is_string($kind) || !in_array($kind, APR_AREA_KINDS, true)) apr_fail('area_kind_required');
            }
            $n = $num();
            if ($n < 0) apr_fail('visit_value');
            return [null, $n, null, null, $unit, $kind];
        case 'yesno':
            if (!is_bool($v)) apr_fail('visit_value');
            return [null, $v ? 100 : 0, null, null, null, null];
        case 'date':
            return [null, null, apr_clean_date($v) ?? apr_fail('visit_value'), null, null, null];
        case 'choice':
            if (!is_string($v) || !in_array($v, array_column($f['options'] ?? [], 'key'), true)) apr_fail('visit_value');
            return [$v, null, null, null, null, null];
        case 'multichoice':
            if (!is_array($v) || !array_is_list($v) || array_diff($v, array_column($f['options'] ?? [], 'key')) || count($v) !== count(array_unique($v))) apr_fail('visit_value');
            return [null, null, null, json_encode(array_values($v)), null, null];
    }
    apr_fail('visit_value');      // photo: תמונה אינה תשובה — היא מועלית
}

/** מזהה פעולה מהמכשיר (פרוסה 14 תשתמש בו לתור): אותו מבנה של מזהה העלאה. */
function apr_visit_op(mixed $v): ?string
{
    if ($v === null || $v === '') return null;
    if (!is_string($v) || preg_match('/^[A-Za-z0-9_-]{8,64}\z/', $v) !== 1) apr_fail('visit_value');
    return $v;
}

/**
 * שמירת שדה אחד: visit, field, row?, rev (0 — עוד אין שורה), value, unit?, area_kind?, obtained?, obtained_from?, note?, op?.
 * רק המבקר (או האחראי), עם visit, ובביקור פתוח (13-C6). rev ישן ← 409 עם הערך שבשרת ומי כתב אותו, ושום דבר לא נכתב (13-A2).
 * ערך ריק כשאין שורה ← אין שורה (13-A3). שמירה חוזרת של אותה פעולה (op) אחרי תקלת רשת — מצליחה בלי לכתוב שוב.
 */
function apr_visit_answer(array $ctx, array $in): array
{
    [$c, $v] = apr_visit_get($ctx, $in['visit'] ?? null, 'fill');
    if ($v['status'] !== 'in_progress') apr_fail('visit_not_open');
    $form = apr_visit_form($c, $v);
    $f = apr_visit_field($form, $in['field'] ?? null);
    $rev = apr_visit_rev($in['rev'] ?? null, 0);
    $op = apr_visit_op($in['op'] ?? null);
    [$text, $num, $date, $json, $unit, $kind] = apr_visit_value($f, $in);
    $empty = $text === null && $num === null && $date === null && $json === null;
    $obtained = $in['obtained'] ?? null;
    if ($obtained === null || $obtained === '') $obtained = $f['obtained'] ?? apr_visit_default_obtained($f['type']);
    elseif (!is_string($obtained) || !in_array($obtained, APR_VISIT_OBTAINED, true)) apr_fail('visit_value');
    $from = $obtained === 'reported' ? apr_clean_text($in['obtained_from'] ?? null, 120) : null;
    $note = apr_clean_text($in['note'] ?? null, 1000);
    $uid = (int)$ctx['user']['id'];
    return apr_tx(function () use ($ctx, $c, $v, $form, $f, $in, $rev, $op, $text, $num, $date, $json, $unit, $kind, $empty, $obtained, $from, $note, $uid) {
        apr_case_still_open($c);
        $row = apr_visit_row_no($v, $form, $f, $in['row'] ?? null);
        if (apr_val('SELECT status FROM visits WHERE id = ?', [$v['id']]) !== 'in_progress') apr_fail('visit_not_open');
        $get = fn() => apr_row(APR_VISIT_ANSWER_SELECT . ' WHERE a.visit_id = ? AND a.field_key = ? AND a.row_no = ?', [$v['id'], $f['key'], $row]);
        $cur = $get();
        $ok = function () use ($get, $c, $v, $form, $f) {
            $a = $get();
            $st = apr_visit_state($c, $v, $form);
            return ['answer' => $a ? apr_visit_answer_out($a, $f['type']) : null, 'progress' => $st['progress'], 'missing' => $st['missing']];
        };
        $changed = function () use ($get, $f, $op) {
            $a = $get();
            if ($op !== null && $a && $a['client_op_id'] === $op) return null;          // אותה פעולה, נשלחה שוב
            throw new AprVisitError('visit_changed', ['current' => $a ? apr_visit_answer_out($a, $f['type']) : null], $a && $a['updated_name'] !== null ? "השדה עודכן בידי {$a['updated_name']}" : null);
        };
        $vals = ['value_text' => $text, 'value_num' => $num, 'value_date' => $date, 'value_json' => $json, 'unit' => $unit, 'area_kind' => $kind, 'obtained' => $obtained,
            'obtained_from' => $from, 'note' => $empty && $note === null ? null : $note, 'copied_from_seq' => null, 'updated_by' => $uid, 'updated_at' => apr_now(), 'client_op_id' => $op];
        if (!$cur) {
            if ($rev !== 0) apr_fail('rev_required');
            if ($empty && $note === null) return $ok();                                 // לא מולא ← אין שורה
            apr_insert('visit_answers', ['visit_id' => $v['id'], 'field_key' => $f['key'], 'row_no' => $row] + $vals);
        } elseif (apr_exec('UPDATE visit_answers SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($vals))) . ', rev = rev + 1 WHERE id = ? AND rev = ?', [...array_values($vals), $cur['id'], $rev]) !== 1) {
            $changed();
            return $ok();
        }
        apr_case_touch($c);
        return $ok();
    });
}

/** שורה חדשה בקבוצה חוזרת: visit, group. */
function apr_visit_row_add(array $ctx, array $in): array
{
    [$c, $v] = apr_visit_get($ctx, $in['visit'] ?? null, 'fill');
    if ($v['status'] !== 'in_progress') apr_fail('visit_not_open');
    $form = apr_visit_form($c, $v);
    $g = apr_visit_group($form, $in['group'] ?? null);
    if (!$g['repeat']) apr_fail('visit_field');
    return apr_tx(function () use ($ctx, $c, $v, $g, $form) {
        apr_case_still_open($c);
        if (apr_val('SELECT status FROM visits WHERE id = ?', [$v['id']]) !== 'in_progress') apr_fail('visit_not_open');
        $n = (int)apr_val('SELECT COALESCE(MAX(row_no), 0) + 1 FROM visit_rows WHERE visit_id = ? AND group_key = ?', [$v['id'], $g['key']]);
        if ($n > 200) apr_fail('content_too_large');
        apr_insert('visit_rows', ['visit_id' => $v['id'], 'group_key' => $g['key'], 'row_no' => $n, 'created_by' => (int)$ctx['user']['id'], 'created_at' => apr_now()]);
        $st = apr_visit_state($c, $v, $form);
        return ['row' => $n, 'rows' => (object)apr_visit_rows((int)$v['id']), 'progress' => $st['progress'], 'missing' => $st['missing']];
    });
}

/** הסרת שורה מקבוצה חוזרת: visit, group, row. התשובות שלה נשארות במסד, ואינן מוצגות ואינן נספרות. */
function apr_visit_row_remove(array $ctx, array $in): array
{
    [$c, $v] = apr_visit_get($ctx, $in['visit'] ?? null, 'fill');
    if ($v['status'] !== 'in_progress') apr_fail('visit_not_open');
    $form = apr_visit_form($c, $v);
    $g = apr_visit_group($form, $in['group'] ?? null);
    return apr_tx(function () use ($c, $v, $g, $form, $in) {
        apr_case_still_open($c);
        if (apr_val('SELECT status FROM visits WHERE id = ?', [$v['id']]) !== 'in_progress') apr_fail('visit_not_open');
        if (!is_int($in['row'] ?? null) || apr_exec('UPDATE visit_rows SET deleted_at = ? WHERE visit_id = ? AND group_key = ? AND row_no = ? AND deleted_at IS NULL', [apr_now(), $v['id'], $g['key'], $in['row']]) !== 1) apr_fail('visit_field');
        $st = apr_visit_state($c, $v, $form);
        return ['rows' => (object)apr_visit_rows((int)$v['id']), 'progress' => $st['progress'], 'missing' => $st['missing']];
    });
}

/**
 * "העתקת תשובות מביקור N" (§5.5): visit (פתוח), from (ביקור אחר של אותו תיק). מועתקים רק שדות רגילים שקיימים בשני הטפסים
 * באותו סוג, ורק לשדה שעוד אין לו שורה. מה שהועתק מסומן copied_from — "לאמת": אינו נחשב מולא ואינו עובר לנתונים עד שהמבקר שומר אותו.
 * הביקור שממנו מעתיקים אינו משתנה.
 */
function apr_visit_copy(array $ctx, array $in): array
{
    [$c, $v] = apr_visit_get($ctx, $in['visit'] ?? null, 'fill');
    if ($v['status'] !== 'in_progress') apr_fail('visit_not_open');
    $src = apr_is_pid($in['from'] ?? null) ? apr_row('SELECT * FROM visits WHERE pid = ? AND case_id = ? AND id <> ?', [$in['from'], $c['id'], $v['id']]) : null;
    if (!$src || $src['form_snapshot'] === null) apr_fail('not_found');
    $form = apr_visit_form($c, $v);
    $srcForm = apr_visit_form($c, $src);
    $repeat = array_column($form['groups'], 'repeat', 'key');
    $srcTypes = [];
    foreach ($srcForm['fields'] as $f) if (!(array_column($srcForm['groups'], 'repeat', 'key')[$f['group']] ?? true)) $srcTypes[$f['key']] = $f;
    return apr_tx(function () use ($ctx, $c, $v, $src, $form, $repeat, $srcTypes) {
        apr_case_still_open($c);
        if (apr_val('SELECT status FROM visits WHERE id = ?', [$v['id']]) !== 'in_progress') apr_fail('visit_not_open');
        $from = apr_visit_answers((int)$src['id']);
        $have = apr_visit_answers((int)$v['id']);
        $n = 0;
        foreach ($form['fields'] as $f) {
            $a = $from[$f['key']][0] ?? null;
            $s = $srcTypes[$f['key']] ?? null;
            if (($repeat[$f['group']] ?? true) || $f['type'] === 'photo' || !$s || $s['type'] !== $f['type'] || isset($have[$f['key']][0]) || !apr_visit_answer_filled($a)) continue;
            if (in_array($f['type'], ['choice', 'multichoice'], true) && array_column($f['options'] ?? [], 'key') != array_column($s['options'] ?? [], 'key')) continue;
            apr_insert('visit_answers', ['visit_id' => $v['id'], 'field_key' => $f['key'], 'row_no' => 0, 'value_text' => $a['value_text'], 'value_num' => $a['value_num'], 'value_date' => $a['value_date'],
                'value_json' => $a['value_json'], 'unit' => $a['unit'], 'area_kind' => $a['area_kind'], 'obtained' => 'not_verified', 'obtained_from' => $a['obtained_from'], 'note' => $a['note'],
                'copied_from_seq' => (int)$src['seq'], 'updated_by' => (int)$ctx['user']['id'], 'updated_at' => apr_now()]);
            $n++;
        }
        apr_case_audit($c, 'visit.copy', 'visit', $v['pid'], ['seq' => (int)$v['seq'], 'from_seq' => (int)$src['seq'], 'answers' => $n]);
        apr_case_touch($c);
        return ['copied' => $n];
    });
}

// ── סיום ──────────────────────────────────────────────────────

/**
 * סיום: visit, rev, result (completed | postponed | no_access), note?, override_reason?, scheduled_at? (לנדחה).
 *  · completed — רק מביקור פתוח. שדה חובה גלוי ריק ← 422 עם רשימת החסרים; כש-block_on_required כבוי בפרופיל אפשר להשלים עם
 *    נימוק (override_reason). אז — ורק אז — התשובות עוברות לנתוני התיק (13-B1), באותה טרנזקציה.
 *  · postponed — לא התקיים; סיבה חובה; מועד חדש באותה רשומה. · no_access — הגיעו ולא נכנסו; תיאור חובה; מה שנצפה נשמר.
 * ביקור שהושלם אי פעם אינו הופך לנדחה או ל"לא הייתה גישה" (גם הטריגר חוסם).
 */
function apr_visit_finish(array $ctx, array $in): array
{
    $result = is_string($in['result'] ?? null) && in_array($in['result'], ['completed', 'postponed', 'no_access'], true) ? $in['result'] : apr_fail('visit_result');
    [$c, $v] = apr_visit_get($ctx, $in['visit'] ?? null, $result === 'postponed' ? 'any' : 'fill');
    $rev = apr_visit_rev($in['rev'] ?? null);
    $note = apr_clean_text($in['note'] ?? null, 1000);
    $override = apr_clean_text($in['override_reason'] ?? null, 500);
    if ($result !== 'completed' && $note === null) apr_fail('visit_note_required');
    $when = $result === 'postponed' && array_key_exists('scheduled_at', $in) ? apr_visit_when($in['scheduled_at']) : false;
    return apr_tx(function () use ($ctx, $c, $v, $rev, $result, $note, $override, $when) {
        apr_case_still_open($c);
        if ($result !== 'completed' && $v['first_completed_at'] !== null) apr_fail('visit_state');
        $out = [];
        if ($result === 'completed') {
            $form = apr_visit_form($c, $v);
            $st = apr_visit_state($c, $v, $form);
            $block = ($form['completion']['block_on_required'] ?? true) !== false;
            if ($st['missing'] && ($block || $override === null)) throw new AprVisitError('visit_incomplete', ['missing' => $st['missing'], 'can_override' => !$block]);
            $now = apr_visit_set($c, $v, $rev, ['status' => 'completed', 'completed_at' => apr_now(), 'first_completed_at' => $v['first_completed_at'] ?? apr_now(),
                'status_note' => $st['missing'] ? $override : $note], ['in_progress']);
            $out = apr_visit_to_facts($ctx, $c, $now, $form, $st);
            $out['tasks'] = apr_visit_finding_tasks($c, $now, $form, $st);
        } else {
            $set = ['status' => $result, 'status_note' => $note] + ($when !== false ? ['scheduled_at' => $when] : []);
            $now = apr_visit_set($c, $v, $rev, $set, $result === 'postponed' ? ['planned', 'in_progress', 'postponed'] : ['planned', 'in_progress']);
        }
        apr_visit_task_sync($c, $now);
        apr_case_audit($c, 'visit.' . $result, 'visit', $v['pid'], ['seq' => (int)$v['seq']] + ($result === 'completed' ? ['facts' => $out['facts'], 'skipped' => count($out['skipped']), 'missing' => count($st['missing'])] : []),
            $result === 'completed' ? ($st['missing'] ? $override : null) : $note);
        apr_case_touch($c);
        return ['visit' => apr_visit_out($now)] + $out;
    });
}

/** פתיחה מחדש של ביקור שהסתיים: המבקר או האחראי, עם נימוק (§5.6). הנתונים שנוצרו ממנו מסומנים stale לצרכניהם. */
function apr_visit_reopen(array $ctx, array $in): array
{
    [$c, $v] = apr_visit_get($ctx, $in['visit'] ?? null, 'fill');
    $rev = apr_visit_rev($in['rev'] ?? null);
    $reason = apr_reason($in['reason'] ?? null);
    return apr_tx(function () use ($c, $v, $rev, $reason) {
        apr_case_still_open($c);
        $set = ['status' => 'in_progress', 'status_note' => null];
        if ($v['form_snapshot'] === null) $set += ['form_snapshot' => json_encode(apr_visit_form($c, $v), JSON_UNESCAPED_UNICODE), 'form_version_id' => (int)$c['profile_version_id'], 'started_at' => apr_now()];
        $now = apr_visit_set($c, $v, $rev, $set, ['completed', 'no_access']);
        apr_facts_stale(array_map('intval', array_column(apr_rows('SELECT fact_id FROM visit_facts WHERE visit_id = ?', [$v['id']]), 'fact_id')));
        apr_visit_task_sync($c, $now);
        apr_case_audit($c, 'visit.reopen', 'visit', $v['pid'], ['seq' => (int)$v['seq'], 'from' => $v['status']], $reason);
        apr_case_touch($c);
        return ['visit' => apr_visit_out($now)];
    });
}

// ── מהביקור לנתוני התיק (§8) ──────────────────────────────────

/**
 * לכל תשובה גלויה ומלאה עם maps_to: נתון במצב manual, מקור visit + ה-pid של הביקור, תאריך המקור = יום הביקור. בתוך הטרנזקציה של
 * סימון ההשלמה. visit_facts (מפתח: ביקור + שדה) מבטיח נתון אחד לתשובה — השלמה חוזרת אחרי פתיחה מחדש מעדכנת, ואינה מכפילה.
 *
 * עדכון של נתון קיים: דרך apr_fact_correct_tx (הערך המקורי נשמר, אימות יורד ל-manual, התלויות stale). **נתון מאומת, כשמי שמשלים
 * אינו רשאי לאמת, אינו משתנה** — נוצר לצידו נתון חדש, והסתירה נפתחת במנגנון של פרוסה 10. כך גם לנתון שנדחה או שנמחק.
 * מחזיר: facts (כמה נוצרו או עודכנו), skipped [{field, why}].
 */
function apr_visit_to_facts(array $ctx, array $c, array $v, array $form, array $st): array
{
    $answers = apr_visit_answers((int)$v['id']);
    $visible = array_flip(array_map(fn($x) => $x[0] . '#' . $x[1], $st['visible']));
    $repeat = array_column($form['groups'], 'repeat', 'key');
    $date = apr_visit_local(strtotime((string)$v['started_at']) ?: apr_time(), 'Y-m-d');
    $uid = (int)$ctx['user']['id'];
    $n = 0;
    $skipped = [];
    foreach ($form['fields'] as $f) {
        if (($f['maps_to'] ?? null) === null || ($repeat[$f['group']] ?? true)) continue;
        $a = $answers[$f['key']][0] ?? null;
        $link = apr_row('SELECT vf.fact_id, f.pid FROM visit_facts vf JOIN facts f ON f.id = vf.fact_id WHERE vf.visit_id = ? AND vf.field_key = ?', [$v['id'], $f['key']]);
        if (!isset($visible[$f['key'] . '#0']) || !apr_visit_answer_filled($a) || $a['copied_from_seq'] !== null) {
            // תשובה שהוסתרה בתנאי, נוקתה או לא אושרה אינה עוברת (מקרה קצה 4); נתון שכבר נוצר ממנה נשאר, ומסומן לצרכניו
            if ($link) { apr_facts_stale([(int)$link['fact_id']]); $skipped[] = ['field' => $f['key'], 'why' => 'answer_removed']; }
            continue;
        }
        $attr = apr_row('SELECT * FROM attributes WHERE key = ?', [$f['maps_to']]);
        if (!$attr || apr_visit_maps_problem($f, $attr) !== null) { $skipped[] = ['field' => $f['key'], 'why' => 'attribute']; continue; }
        $in = ['value' => apr_visit_cond_value($a, $f['type']), 'unit' => $a['unit'], 'area_kind' => $a['area_kind'], 'zero_ok' => true];
        try {
            [$num, $text, $dt, $unit, $kind] = apr_fact_value($attr, $in, false);
        } catch (AprError) {
            $skipped[] = ['field' => $f['key'], 'why' => 'value'];       // למשל: טקסט ארוך מהמותר לנתון, או מספר עם שבר למאפיין של שלמים
            continue;
        }
        $note = trim(match ($a['obtained']) { 'reported' => 'נמסר בביקור' . ($a['obtained_from'] !== null ? ' מ-' . $a['obtained_from'] : ''), 'not_verified' => 'לא אומת בביקור', default => '' }
            . ($a['note'] !== null ? ($a['obtained'] === 'reported' || $a['obtained'] === 'not_verified' ? '. ' : '') . $a['note'] : ''));
        $note = $note === '' ? null : mb_substr($note, 0, 1000);
        $est = (int)($a['obtained'] === 'estimated');
        $insert = function () use ($c, $attr, $num, $text, $dt, $unit, $kind, $est, $note, $uid, $v, $date, $f, $link) {
            $pid = apr_fact_insert($c, $attr, ['value_num' => $num, 'value_text' => $text, 'value_date' => $dt, 'unit' => $unit, 'area_kind' => $kind, 'estimated' => $est,
                'state' => 'manual', 'method' => 'manual', 'note' => $note, 'entered_by' => $uid, 'updated_by' => $uid,
                'source_kind' => 'visit', 'source_file_id' => null, 'source_page' => null, 'source_locator' => null, 'source_ref_pid' => $v['pid'], 'source_date' => $date, 'source_note' => null]);
            $id = (int)apr_val('SELECT id FROM facts WHERE pid = ?', [$pid]);
            if ($link) apr_exec('UPDATE visit_facts SET fact_id = ? WHERE visit_id = ? AND field_key = ?', [$id, $v['id'], $f['key']]);
            else apr_insert('visit_facts', ['visit_id' => $v['id'], 'field_key' => $f['key'], 'fact_id' => $id, 'created_at' => apr_now()]);
        };
        if (!$link) { $insert(); $n++; continue; }
        $old = apr_row(APR_FACT_SELECT . ' WHERE f.id = ?', [$link['fact_id']]);
        $same = fn($x, $y) => ($x === null ? null : (string)$x) === ($y === null ? null : (string)$y);
        $valueSame = $same($old['value_num'], $num) && $same($old['value_text'], $text) && $same($old['value_date'], $dt) && $same($old['unit'], $unit) && $same($old['area_kind'], $kind);
        if ($valueSame && (int)$old['estimated'] === $est && $same($old['note'], $note) && $old['deleted_at'] === null) continue;
        if ($old['deleted_at'] !== null || $old['state'] === 'rejected' || ($old['state'] === 'verified' && !apr_fact_can_judge($ctx, $c))) {
            if ($valueSame) continue;                                     // אותו ערך: הקביעה של השמאי (אימות, דחייה, מחיקה) עומדת
            $insert();
            $n++;
            continue;
        }
        if (apr_fact_correct_tx($ctx, $c, $old, ['rev' => (int)$old['rev'], 'estimated' => (bool)$est, 'note' => $note, 'correction_note' => 'עודכן מביקור ' . $v['seq']]
            + ($valueSame ? [] : ['value' => $in['value'], 'unit' => $unit, 'area_kind' => $kind, 'zero_ok' => true]))) $n++;
    }
    return ['facts' => $n, 'skipped' => $skipped];
}

/**
 * "אי התאמה אפשרית" (§8): שדה עם creates_task שמולא יוצר משימת בדיקה לאחראי, עם הטקסט ומספר התמונות של הביקור — מקושרת לביקור,
 * לבדיקה מול "מקורות ותכנון". זו משימה ותו לא: לא נקבעת עבירה, ואין שדה שמסמן כזו. אחת פתוחה לכל שדה בביקור.
 */
function apr_visit_finding_tasks(array $c, array $v, array $form, array $st): int
{
    $answers = apr_visit_answers((int)$v['id']);
    $visible = array_flip(array_map(fn($x) => $x[0] . '#' . $x[1], $st['visible']));
    $photos = (int)apr_val('SELECT COUNT(*) FROM visit_photos WHERE visit_id = ? AND deleted_at IS NULL', [$v['id']]);
    $n = 0;
    foreach ($form['fields'] as $f) {
        $a = $answers[$f['key']][0] ?? null;
        if (empty($f['creates_task']) || !isset($visible[$f['key'] . '#0']) || $a === null || $a['value_text'] === null || $a['copied_from_seq'] !== null) continue;
        $key = 'visit-finding:' . $v['pid'] . ':' . $f['key'];
        $detail = mb_substr($a['value_text'], 0, 3000) . "\n\n" . 'נרשם בביקור ' . $v['seq'] . ($photos ? " · $photos תמונות בביקור" : '') . '. לבדוק מול "מקורות ותכנון" — זו אינה קביעה.';
        $open = apr_row("SELECT id, detail FROM tasks WHERE case_id = ? AND origin_key = ? AND status IN ('open', 'in_progress')", [$c['id'], $key]);
        if ($open) {
            if ($open['detail'] !== $detail) apr_exec('UPDATE tasks SET detail = ?, updated_at = ? WHERE id = ?', [$detail, apr_now(), $open['id']]);
            continue;
        }
        if (apr_val('SELECT 1 FROM tasks WHERE case_id = ? AND origin_key = ? AND detail = ?', [$c['id'], $key, $detail])) continue;    // כבר טופלה, והטקסט לא השתנה
        $assignee = apr_val('SELECT c.responsible_user_id FROM cases c JOIN case_members m ON m.case_id = c.id AND m.user_id = c.responsible_user_id AND m.revoked_at IS NULL WHERE c.id = ?', [$c['id']]);
        apr_insert('tasks', ['pid' => apr_pid(), 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'kind' => 'clarification', 'title' => $f['label'] . ' — לבדיקה (ביקור ' . $v['seq'] . ')',
            'detail' => $detail, 'assignee_user_id' => $assignee ?: null, 'origin_key' => $key, 'link_type' => 'visit', 'link_pid' => $v['pid'], 'created_by' => null, 'created_at' => apr_now(), 'updated_at' => apr_now()]);
        $n++;
    }
    return $n;
}

// ── הגדרת משרד: מיקום לתמונות (§6.3) ─────────────────────────

/** "לצרף מיקום לתמונות" — כבוי כברירת מחדל. גם כשדולק: רק בהסכמת המבקר במכשיר שלו, בכל ביקור. */
function apr_office_visit_geo(array $ctx, array $in): array
{
    $office = apr_office_id($ctx);
    $on = ($in['on'] ?? null) === true;
    return apr_tx(function () use ($office, $on, $ctx) {
        apr_exec('INSERT INTO office_visit_settings (office_id, photo_geo, updated_at) VALUES (?, ?, ?) ON CONFLICT(office_id) DO UPDATE SET photo_geo = excluded.photo_geo, updated_at = excluded.updated_at', [$office, (int)$on, apr_now()]);
        apr_audit('office.visit_geo', 'office', $ctx['office']['pid'], ['on' => $on], office: $office);
        return ['visit_geo' => $on];
    });
}
