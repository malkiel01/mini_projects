<?php
/**
 * פרוסה 17 — הדוח: הפעולות (docs/spec/17-report.md §6–§11). הדוח הוא תוכן ונתונים בתוך התיק; **אין דוח "מאושר" רק מפני שנוצר קובץ**,
 * והדוח לעולם אינו מוצג כ"חתום". הבנייה מהנתונים, הבקרה והבינה — ב-lib/report_build.php.
 *
 * הרשאות (§11): צפייה — view למשתתף פנימי; שותף ממשרד אחר — טיוטה רק עם apr_can($c, 'report') (היקף + see_draft), גרסה מאושרת גם בשיתוף של
 * כל התיק. עריכה, הצעות, הערות וניסוח בבינה — edit. "נסקר", העברה לבדיקה והחזרה — edit + שמאי בתיק (apr_comp_can_judge). אישור, גרסה
 * חדשה ומחיקת טיוטה — approve_report בלבד. כל הפעולות המשנות סגורות לשותף ('member' ב-APR_ACCESS).
 *
 * אחרי האישור הגרסה קפואה: היא מוצגת מה-snapshot, וטריגרים במסד דוחים כל שינוי בה ובפרקיה (מיגרציה 017).
 */

declare(strict_types=1);

const APR_REPORT_OPEN = ['draft', 'in_review'];
const APR_REPORT_FINAL = ['approved', 'delivered', 'superseded'];
const APR_REPORT_RATE_AI = [30, 3600];      // ניסוחים בבינה למשתמש, לשעה — לפני השער

// ── גישה ושורות ───────────────────────────────────────────────

/** התיק, לפי מה שהפעולה צריכה: view · edit · judge (edit + שמאי בתיק) · approve (approve_report). */
function apr_report_case(array $ctx, mixed $casePid, string $need = 'view'): array
{
    $c = apr_case_get($ctx, $casePid, ['view' => 'view', 'edit' => 'edit', 'judge' => 'edit', 'approve' => 'approve_report'][$need]);
    if ($need === 'judge' && !apr_comp_can_judge($ctx, $c)) apr_fail('forbidden');
    if ($need === 'approve' && !apr_report_is_appraiser($ctx, $c)) apr_fail('forbidden');       // approve_report לבדו אינו מספיק: מאשר רק שמאי בתיק
    return $c;
}

/** שמאי בתיק: שמאי במשרד, שהוא האחראי או שותף בתיק — אותו תנאי כמו הממצא no_approver. */
function apr_report_is_appraiser(array $ctx, array $c): bool
{
    return in_array($c['my_role'] ?? null, ['responsible', 'partner'], true) && ($ctx['office']['role'] ?? '') === 'appraiser';
}

/** האם המבקש רואה את הגרסה הזו: משתתף פנימי — תמיד; שותף — טיוטה רק עם see_draft בהיקף, מאושרת גם בשיתוף של כל התיק. */
function apr_report_visible(array $c, array $v): bool
{
    if ($c['share'] === null) return true;
    return apr_can($c, 'report') || (in_array($v['state'], APR_REPORT_FINAL, true) && apr_can($c, 'details'));
}

function apr_report_version_row(array $c, mixed $pid): array
{
    $v = apr_is_pid($pid) ? apr_row('SELECT v.*, cb.name AS created_name, sb.name AS submitted_name, ab.name AS approved_name FROM report_versions v LEFT JOIN users cb ON cb.id = v.created_by
        LEFT JOIN users sb ON sb.id = v.submitted_by LEFT JOIN users ab ON ab.id = v.approved_by WHERE v.pid = ? AND v.case_id = ? AND v.deleted_at IS NULL', [$pid, $c['id']]) : null;
    if (!$v || !apr_report_visible($c, $v)) apr_fail('not_found');
    return $v;
}

function apr_report_section_row(array $c, mixed $pid): array
{
    $s = apr_is_pid($pid) ? apr_row('SELECT s.* FROM report_sections s JOIN report_versions v ON v.id = s.version_id WHERE s.pid = ? AND v.case_id = ? AND s.deleted_at IS NULL AND v.deleted_at IS NULL', [$pid, $c['id']]) : null;
    return $s ?? apr_fail('not_found');
}

function apr_report_rev(mixed $rev): int
{
    return is_int($rev) && $rev > 0 ? $rev : apr_fail('report_rev');
}

/** עדכון עם rev: מי ששמר על בסיס גרסה ישנה מקבל 409 עם שם מי שעדכן — אין דריסה שקטה (17-B4). */
function apr_report_update(string $table, array $row, int $rev, array $set, ?int $uid): void
{
    if ($table === 'report_sections') $set += ['updated_by' => $uid, 'updated_at' => apr_now()];
    $sql = 'UPDATE ' . ($table === 'report_sections' ? 'report_sections' : 'report_versions') . ' SET ' . implode(', ', array_map(fn($f) => "$f = ?", array_keys($set))) . ($set ? ', ' : '') . 'rev = rev + 1 WHERE id = ? AND rev = ? AND deleted_at IS NULL';
    if (apr_exec($sql, [...array_values($set), $row['id'], $rev]) !== 1) {
        $by = $table === 'report_sections' ? apr_val('SELECT u.name FROM report_sections s LEFT JOIN users u ON u.id = s.updated_by WHERE s.id = ?', [$row['id']]) : null;
        throw new AprVisitError('report_changed', ['by' => $by ?: null]);
    }
}

function apr_report_tpl(array $v): array
{
    return json_decode((string)$v['template'], true) ?: apr_pc_report_default();
}

/** הפרקים של גרסה, מוכנים לעבודה: 'blocks' (השמורים), 'view' (פתורים לערכים של הרגע), 'pending' (הצעת עדכון פתוחה | null). */
function apr_report_sections(array $ctx, array $c, array $v): array
{
    $tpl = apr_report_tpl($v);
    $rows = apr_rows('SELECT s.*, ub.name AS updated_name, rb.name AS reviewed_name FROM report_sections s LEFT JOIN users ub ON ub.id = s.updated_by LEFT JOIN users rb ON rb.id = s.ai_reviewed_by
        WHERE s.version_id = ? AND s.deleted_at IS NULL ORDER BY s.sort, s.id', [$v['id']]);
    foreach ($rows as &$s) {
        $s['blocks'] = json_decode($s['content'], true) ?: [];
        $s['view'] = apr_report_resolve($ctx, $c, $s['blocks'], $tpl);
        $s['pending'] = apr_row("SELECT * FROM section_proposals WHERE section_id = ? AND state = 'pending' ORDER BY id DESC LIMIT 1", [$s['id']]);
    }
    return $rows;
}

/** בלוקים ← שורות טקסט, להשוואה בין גרסאות ולהצגת הצעת עדכון כהבדלים. טקסט בלבד. */
function apr_report_lines(array $view): array
{
    $out = [];
    foreach ($view as $b) {
        switch ($b['type']) {
            case 'p': case 'h': $out[] = $b['text']; break;
            case 'note_missing': $out[] = 'לא ידוע: ' . $b['what']; break;
            case 'list': foreach ($b['items'] as $i) $out[] = '• ' . $i; break;
            case 'table': foreach ($b['rows'] as $r) $out[] = implode(' | ', $r); break;
            case 'data_table': foreach ($b['rows'] ?? [] as $r) $out[] = implode(' | ', $r); break;
            case 'fact': $out[] = ($b['label'] ?? $b['attr']) . ': ' . (($b['value'] ?? null) === null ? 'לא ידוע' : apr_report_fact_stamp($b['value'])); break;
            case 'image': $out[] = '[תמונה] ' . ($b['caption'] ?? ''); break;
        }
    }
    return $out;
}

function apr_report_section_out(array $ctx, array $c, array $v, array $s, bool $internal): array
{
    $o = ['pid' => $s['pid'], 'key' => $s['key'], 'title' => $s['title'], 'mandatory' => (int)$s['mandatory'] === 1, 'build' => $s['build'], 'origin' => $s['origin'], 'state' => $s['state'],
        'ai_reviewed' => (int)$s['ai_reviewed'] === 1, 'blocks' => $s['view']];
    if (!$internal) return apr_report_partner_section($o);
    $p = $s['pending'];
    $pview = $p ? apr_report_resolve($ctx, $c, json_decode($p['content'], true) ?: [], apr_report_tpl($v)) : null;
    return $o + ['rev' => (int)$s['rev'], 'ai_allowed' => (int)$s['ai_allowed'] === 1, 'ai_wait' => $s['ai_wait'], 'ai_reviewed_by' => $s['reviewed_name'], 'ai_reviewed_at' => $s['ai_reviewed_at'],
        'user_edited' => (int)$s['user_edited'] === 1, 'changed_note' => $s['changed_note'], 'updated_by' => $s['updated_name'], 'updated_at' => $s['updated_at'],
        'sources' => apr_report_sources_out($c, json_decode($s['sources'], true) ?: []), 'lines' => apr_report_lines($s['view']),
        'proposal' => $p ? ['pid' => $p['pid'], 'origin' => $p['origin'], 'reason' => $p['reason'], 'created_at' => $p['created_at'], 'blocks' => $pview, 'lines' => apr_report_lines($pview)] : null];
}

/**
 * מקורות של פרק, להצגה: לכל מקור מסוג נתון — המאפיין שלו, והמסמך והעמוד שהנתון נשען עליהם (17-E1: לחיצה פותחת את המסמך בעמוד).
 * תוספת לקריאה בלבד — מה ששמור (וגם ה-snapshot) אינו משתנה. מסמך שנמחק אינו מקושר.
 */
function apr_report_sources_out(array $c, array $sources): array
{
    foreach ($sources as &$s) {
        if (($s['type'] ?? '') !== 'fact') continue;
        $f = apr_row('SELECT f.attribute_key, f.source_page, fl.pid AS file_pid FROM facts f LEFT JOIN files fl ON fl.id = f.source_file_id AND fl.deleted_at IS NULL WHERE f.pid = ? AND f.case_id = ?', [$s['pid'] ?? '', $c['id']]);
        if ($f) $s += ['attr' => $f['attribute_key'], 'file' => $f['file_pid'], 'page' => $f['source_page'] === null ? null : (int)$f['source_page']];
    }
    return $sources;
}

/** מה שכרטיס התיק צריך כדי להציג את הלשונית "דוח וגרסאות": כמה גרסאות המבקש רואה, ומצב האחרונה. null — אין לו מה לראות. */
function apr_case_report_summary(array $c): ?array
{
    if ($c['share'] !== null && !apr_can($c, 'report') && !apr_can($c, 'details')) return null;
    $rows = array_values(array_filter(apr_rows('SELECT state FROM report_versions WHERE case_id = ? AND deleted_at IS NULL ORDER BY number DESC', [$c['id']]), fn($v) => apr_report_visible($c, $v)));
    if ($c['share'] !== null && !$rows) return null;
    return ['versions' => count($rows), 'state' => $rows[0]['state'] ?? null];
}

function apr_report_version_head(array $v, bool $internal = true): array
{
    $h = ['pid' => $v['pid'], 'number' => (int)$v['number'], 'state' => $v['state'], 'origin' => $v['origin'], 'rev' => (int)$v['rev'], 'created_by' => $v['created_name'], 'created_at' => $v['created_at'],
        'submitted_by' => $v['submitted_name'], 'submitted_at' => $v['submitted_at'], 'approved_by' => $v['approved_name'], 'approved_at' => $v['approved_at'],
        'reopen_reason' => $v['reopen_reason'], 'stale_after_approval' => (int)$v['stale_after_approval'] === 1, 'content_hash' => $v['content_hash']];
    // שותף ממשרד אחר: בלי הנימוק הפנימי לפתיחת הגרסה, בלי rev ובלי שמות העובדים
    return $internal ? $h : array_diff_key($h, ['reopen_reason' => 1, 'rev' => 1, 'stale_after_approval' => 1, 'created_by' => 1, 'submitted_by' => 1, 'submitted_at' => 1]);
}

/**
 * פרק כפי ששותף ממשרד אחר מקבל אותו: התוכן בלבד. בלי מקור הפרק וסימוני הבינה, ובלי מזהים פנימיים של נתון, עסקה או בדיקת מקור
 * (src, fact, refs), ובלי סימוני העריכה (was / changed / ai / num_unknown).
 */
function apr_report_partner_section(array $s): array
{
    $s = array_diff_key($s, ['origin' => 1, 'ai_reviewed' => 1, 'sources' => 1, 'rev' => 1, 'state' => 1, 'build' => 1]);
    $s['blocks'] = array_map(fn($b) => array_diff_key($b, ['src' => 1, 'fact' => 1, 'refs' => 1, 'was' => 1, 'changed' => 1, 'ai' => 1, 'num_unknown' => 1]), $s['blocks'] ?? []);
    return $s;
}

function apr_report_perms(array $ctx, array $c): array
{
    $open = $c['status'] !== 'closed' && $c['share'] === null;
    return ['edit' => $open && !empty($c['perms']['edit']), 'judge' => $open && apr_comp_can_judge($ctx, $c), 'approve' => $open && !empty($c['perms']['approve_report']) && apr_report_is_appraiser($ctx, $c)];
}

// ── יצירת טיוטה (§6) ──────────────────────────────────────────

/**
 * יוצר גרסה חדשה (טיוטה) עם הפרקים של התבנית, בנויים מהנתונים. $from — גרסה מאושרת שממנה מעתיקים ("גרסה חדשה", §8.2): התוכן השמור שלה,
 * וההפניות שבו מציגות את הערכים העדכניים. מחזיר את שורת הגרסה. בתוך טרנזקציה של הקורא.
 */
function apr_report_create(array $ctx, array $c, ?int $uid, string $origin, ?array $from = null, ?string $reason = null): array
{
    $now = apr_now();
    $r = apr_row('SELECT * FROM reports WHERE case_id = ?', [$c['id']]);
    $rid = $r ? (int)$r['id'] : apr_insert('reports', ['pid' => apr_pid(), 'office_id' => $c['office_id'], 'case_id' => $c['id'], 'created_at' => $now]);
    if (apr_val("SELECT 1 FROM report_versions WHERE report_id = ? AND deleted_at IS NULL AND state IN ('draft', 'in_review')", [$rid])) apr_fail('report_exists');
    $tpl = $from ? apr_report_tpl($from) : apr_report_profile($c);
    $pid = apr_pid();
    $vid = apr_insert('report_versions', ['pid' => $pid, 'report_id' => $rid, 'office_id' => $c['office_id'], 'case_id' => $c['id'],
        'number' => (int)apr_val('SELECT COALESCE(MAX(number), 0) + 1 FROM report_versions WHERE report_id = ?', [$rid]), 'origin' => $origin, 'based_on_id' => $from['id'] ?? null,
        'reopen_reason' => $reason, 'template' => json_encode($tpl, JSON_UNESCAPED_UNICODE), 'created_by' => $uid, 'created_at' => $now]);
    $sort = 0;
    if ($from) {
        foreach (apr_rows('SELECT * FROM report_sections WHERE version_id = ? AND deleted_at IS NULL ORDER BY sort, id', [$from['id']]) as $s) {
            apr_insert('report_sections', ['pid' => apr_pid(), 'version_id' => $vid, 'key' => $s['key'], 'title' => $s['title'], 'sort' => ++$sort, 'mandatory' => $s['mandatory'], 'build' => $s['build'],
                'ai_allowed' => $s['ai_allowed'], 'content' => $s['content'], 'origin' => $s['origin'], 'ai_run_id' => $s['ai_run_id'], 'ai_reviewed' => $s['ai_reviewed'], 'ai_reviewed_by' => $s['ai_reviewed_by'],
                'ai_reviewed_at' => $s['ai_reviewed_at'], 'user_edited' => $s['user_edited'], 'state' => $s['state'], 'sources' => $s['sources'],
                // ה-hash של הגרסה הקודמת, לא של הרגע: התוכן מועתק כפי שאושר, ומה שהשתנה בנתונים מאז יעלה ברענון הראשון — עדכון, הצעה או ממצא
                'auto_hash' => $s['auto_hash'], 'ai_input_hash' => $s['ai_input_hash'], 'updated_by' => $uid, 'updated_at' => $now]);
        }
    } else {
        foreach ($tpl['sections'] as $t) {
            $built = $t['build'] === 'data' ? apr_report_build($c, $t['key'], $tpl) : ['blocks' => [], 'sources' => []];
            apr_insert('report_sections', ['pid' => apr_pid(), 'version_id' => $vid, 'key' => $t['key'], 'title' => $t['title'], 'sort' => ++$sort, 'mandatory' => $t['mandatory'] ? 1 : 0, 'build' => $t['build'],
                'ai_allowed' => $t['ai'] && in_array($t['key'], APR_REPORT_AI_KEYS, true) ? 1 : 0, 'content' => json_encode($built['blocks'], JSON_UNESCAPED_UNICODE), 'origin' => $t['build'] === 'data' ? 'auto' : 'manual',
                'state' => $built['blocks'] ? 'drafted' : 'empty', 'sources' => json_encode($built['sources'], JSON_UNESCAPED_UNICODE),
                'auto_hash' => $t['build'] === 'data' ? apr_report_hash($built['blocks']) . ':' . apr_report_data_hash($ctx, $c, $built['blocks'], $tpl) : null, 'updated_by' => $uid, 'updated_at' => $now]);
        }
    }
    apr_exec('UPDATE reports SET current_version_id = ? WHERE id = ?', [$vid, $rid]);
    apr_case_audit($c, 'report.draft_create', 'report_version', $pid, ['number' => (int)apr_val('SELECT number FROM report_versions WHERE id = ?', [$vid]), 'origin' => $origin, 'from' => $from['pid'] ?? null], $reason, $uid, $uid === null ? 'system' : 'user');
    return apr_row('SELECT * FROM report_versions WHERE id = ?', [$vid]);
}

/**
 * האם התוכן הזה כבר הוצע לפרק — בגרסה הזו **או בגרסה קודמת של אותו דוח** (אותו מפתח פרק): הצעה שהוכרעה אינה חוזרת, גם לא אחרי "גרסה חדשה".
 * $any — גם הצעה לאותו מבנה שנוצרה בגלל טקסט בינה ישן (המפתח שלה "<מבנה>#<קלט>").
 */
function apr_report_proposed(array $s, string $hash, bool $any = false): bool
{
    return (bool)apr_val('SELECT 1 FROM section_proposals p JOIN report_sections s2 ON s2.id = p.section_id JOIN report_versions v2 ON v2.id = s2.version_id
        WHERE s2.key = ? AND v2.report_id = (SELECT report_id FROM report_versions WHERE id = ?) AND (p.content_hash = ?' . ($any ? ' OR p.content_hash LIKE ?' : '') . ')',
        [$s['key'], $s['version_id'], $hash, ...($any ? [$hash . '#%'] : [])]);
}

/**
 * "הגיע מידע חדש" (§7), לטיוטה בלבד: לכל פרק שנבנה מהנתונים — אם הנתונים השתנו מאז הבנייה האחרונה:
 *  · פרק שלא נגעו בו (user_edited = 0, לא טקסט בינה) ← התוכן נבנה מחדש, והפרק מציג "עודכן".
 *  · פרק שנערך בידי אדם, או שנוסח בבינה ← **התוכן אינו משתנה**; אם מבנה הפרק המוצע שונה — נוצרת הצעת עדכון (אחת לכל תוכן מוצע).
 * מחזיר כמה פרקים עודכנו וכמה הצעות נוצרו. אידמפוטנטי.
 */
function apr_report_refresh(array $ctx, array $c, array $v, ?int $uid): array
{
    if ($v['state'] !== 'draft' || $c['status'] === 'closed') return ['updated' => 0, 'proposed' => 0];
    return apr_tx(function () use ($ctx, $c, $v, $uid) {
        $tpl = apr_report_tpl($v);
        $n = ['updated' => 0, 'proposed' => 0];
        foreach (apr_rows("SELECT * FROM report_sections WHERE version_id = ? AND deleted_at IS NULL AND build = 'data' ORDER BY sort, id", [$v['id']]) as $s) {
            $built = apr_report_build($c, $s['key'], $tpl);
            $struct = apr_report_hash($built['blocks']);
            $hash = $struct . ':' . apr_report_data_hash($ctx, $c, $built['blocks'], $tpl);
            // טקסט בינה שנכתב על נתונים שהשתנו מאז (B3): הסקירה מתבטלת, ונוצרת הצעה לבנות את הפרק מהנתונים של הרגע. הבקרה חוסמת עד סקירה / ניסוח מחדש
            if ($s['origin'] === 'ai' && ($ih = apr_report_ai_input($ctx, $c, $s, $tpl)['hash']) !== $s['ai_input_hash']) {
                if ((int)$s['ai_reviewed'] === 1) apr_report_update('report_sections', $s, (int)$s['rev'], ['ai_reviewed' => 0, 'ai_reviewed_by' => null, 'ai_reviewed_at' => null] + ['state' => 'drafted',
                    'changed_note' => 'נתוני התיק השתנו אחרי שהטקסט נוסח בבינה — נדרשת סקירה מחדש'], $s['updated_by'] === null ? null : (int)$s['updated_by']);
                if (!apr_report_proposed($s, $struct . '#' . $ih)) {
                    apr_exec("UPDATE section_proposals SET state = 'rejected', decided_at = ? WHERE section_id = ? AND state = 'pending'", [apr_now(), $s['id']]);
                    apr_insert('section_proposals', ['pid' => apr_pid(), 'section_id' => $s['id'], 'content' => json_encode($built['blocks'], JSON_UNESCAPED_UNICODE), 'content_hash' => $struct . '#' . $ih,
                        'origin' => 'auto', 'reason' => 'נתוני התיק השתנו אחרי שהטקסט נוסח בבינה', 'created_at' => apr_now()]);
                    $n['proposed']++;
                }
                continue;
            }
            if ($hash === $s['auto_hash']) continue;
            if ((int)$s['user_edited'] === 0 && $s['origin'] !== 'ai') {
                apr_report_update('report_sections', $s, (int)$s['rev'], ['content' => json_encode($built['blocks'], JSON_UNESCAPED_UNICODE), 'sources' => json_encode($built['sources'], JSON_UNESCAPED_UNICODE),
                    'auto_hash' => $hash, 'state' => $built['blocks'] ? 'drafted' : 'empty', 'changed_note' => 'נתוני התיק השתנו — הפרק נבנה מחדש'], $s['updated_by'] === null ? null : (int)$s['updated_by']);
                $n['updated']++;
            } elseif ($struct !== explode(':', (string)$s['auto_hash'])[0] && $struct !== apr_report_hash(json_decode($s['content'], true) ?: [])
                && !apr_report_proposed($s, $struct, true)) {
                apr_exec("UPDATE section_proposals SET state = 'rejected', decided_at = ? WHERE section_id = ? AND state = 'pending'", [apr_now(), $s['id']]);     // הצעה ישנה שלא הוכרעה מוחלפת בעדכנית
                apr_insert('section_proposals', ['pid' => apr_pid(), 'section_id' => $s['id'], 'content' => json_encode($built['blocks'], JSON_UNESCAPED_UNICODE), 'content_hash' => $struct,
                    'origin' => 'auto', 'reason' => 'נתוני התיק השתנו מאז שהפרק נערך', 'created_at' => apr_now()]);
                $n['proposed']++;
            }
        }
        return $n;
    });
}

/**
 * ניסוח הפרקים שמותר לבינה לנסח, אחרי יצירת הטיוטה. כשהבינה אינה זמינה (לפי ההגדרות בלבד) — **אין קריאה למתאם**, והפרק מסומן "ממתין"
 * עם הסיבה (§6.3, 17-A5). מה שנוסח — נשאר; מכסה שנגמרה באמצע משאירה את השאר "ממתין: מכסה".
 */
function apr_report_ai_all(?array $ctx, array $c, array $v): void
{
    $tpl = apr_report_tpl($v);
    $user = $ctx !== null ? (int)$ctx['user']['id'] : ($c['responsible_user_id'] === null ? null : (int)$c['responsible_user_id']);
    foreach (apr_rows("SELECT id FROM report_sections WHERE version_id = ? AND deleted_at IS NULL AND ai_allowed = 1 AND user_edited = 0 AND origin = 'auto' ORDER BY sort, id", [$v['id']]) as $row) {
        // לפני כל קריאה לספק — המצב של הרגע. קריאה אחת יכולה להימשך דקה, ובינתיים אנשים עובדים: גרסה שנמחקה או יצאה מ"טיוטה" ← עוצרים (אין
        // לאן לכתוב, ואין סיבה לשלם על קריאה); פרק שנערך, נמחק או כבר נוסח בינתיים ← מדלגים עליו
        if (!apr_val("SELECT 1 FROM report_versions WHERE id = ? AND deleted_at IS NULL AND state = 'draft'", [$v['id']])) return;
        $s = apr_row("SELECT * FROM report_sections WHERE id = ? AND deleted_at IS NULL AND ai_allowed = 1 AND user_edited = 0 AND origin = 'auto'", [$row['id']]);
        if (!$s) continue;
        $why = apr_extract_unavailable($c, $user);
        if ($why === null && !apr_limit('ai-report:o' . $c['office_id'], 200, 3600)) $why = 'failed';
        $r = $why !== null ? ['state' => 'wait', 'reason' => in_array($why, APR_REPORT_AI_WAITS, true) ? $why : 'failed', 'blocks' => [], 'run' => null]
            : apr_report_ai_section($ctx, $c, $s, $tpl, 'rd:' . $s['pid'] . ':' . $s['rev']);
        apr_tx(fn() => apr_report_ai_apply($c, $s, $r, $tpl, $ctx !== null ? (int)$ctx['user']['id'] : null));
    }
}

/**
 * כותב לפרק את תוצאת הניסוח: טקסט הבינה (מסומן, לא נסקר), אחריו הנתונים שלא נשלחו למודל (כהפניות), הטבלאות, התמונות **והחוסרים** של
 * הפרק — ניסוח בבינה אינו מעלים "לא ידוע" מהבקרה; או "ממתין" עם הסיבה.
 *
 * $s — השורה **כפי שהייתה לפני הקריאה לספק**. אם הפרק השתנה בינתיים (מישהו שמר בזמן שהספק ענה) — הטקסט **אינו נכתב לפרק**: הוא נשמר
 * כהצעה (origin = ai), והעריכה של האדם נשארת (B2). מחזיר מה קרה לתוצאה: applied | proposal | wait | discarded. עם הטקסט נשמר hash של נתוני הקלט — כך יודעים אחר כך שהטקסט נכתב לפני שינוי בנתונים (B3).
 */
function apr_report_ai_apply(array $c, array $s, array $r, array $tpl, ?int $uid): string
{
    $before = (int)$s['rev'];
    $s = apr_row('SELECT s.*, v.state AS version_state, v.deleted_at AS version_deleted FROM report_sections s JOIN report_versions v ON v.id = s.version_id WHERE s.id = ?', [$s['id']]);
    // הפרק נמחק, או שהגרסה נמחקה / יצאה מ"טיוטה" בזמן הקריאה: אין לאן לכתוב. התוצאה נזרקת — והקורא יודע זאת (לא "נוסח")
    if (!$s || $s['deleted_at'] !== null || $s['version_deleted'] !== null || $s['version_state'] !== 'draft') return 'discarded';
    $moved = (int)$s['rev'] !== $before;
    if ($r['state'] !== 'done') {
        if (!$moved && $r['reason'] !== 'no_data' && $r['reason'] !== 'not_allowed') apr_report_update('report_sections', $s, (int)$s['rev'], ['ai_wait' => $r['reason']], $uid);
        return 'wait';
    }
    $keep = array_values(array_filter(apr_report_build($c, $s['key'], $tpl)['blocks'], fn($b) => in_array($b['type'], ['data_table', 'image', 'note_missing'], true)));
    $content = json_encode([...$r['blocks'], ...($r['kept'] ?? []), ...$keep], JSON_UNESCAPED_UNICODE);
    $meta = ['key' => $s['key'], 'blocks' => count($r['blocks']), 'dropped' => $r['dropped'] ?? 0, 'num_unknown' => count(array_filter($r['blocks'], fn($b) => !empty($b['num_unknown'])))];
    if ($moved) {
        apr_exec("UPDATE section_proposals SET state = 'rejected', decided_at = ? WHERE section_id = ? AND state = 'pending'", [apr_now(), $s['id']]);
        apr_insert('section_proposals', ['pid' => apr_pid(), 'section_id' => $s['id'], 'content' => $content, 'content_hash' => apr_report_hash($content), 'origin' => 'ai', 'ai_run_id' => $r['run'],
            'ai_input_hash' => $r['hash'] ?? null, 'reason' => 'הפרק נערך בזמן שהבינה ניסחה אותו — הניסוח נשמר כהצעה, והעריכה נשארה', 'created_at' => apr_now()]);
        apr_case_audit($c, 'report.section_ai_proposal', 'report_section', $s['pid'], $meta, null, $uid, $uid === null ? 'system' : 'user');
        return 'proposal';
    }
    apr_report_update('report_sections', $s, $before, ['content' => $content, 'origin' => 'ai', 'ai_run_id' => $r['run'], 'ai_input_hash' => $r['hash'] ?? null, 'ai_reviewed' => 0,
        'ai_reviewed_by' => null, 'ai_reviewed_at' => null, 'ai_wait' => null, 'state' => 'drafted', 'user_edited' => 0], $uid);
    apr_case_audit($c, 'report.section_ai', 'report_section', $s['pid'], $meta, null, $uid, $uid === null ? 'system' : 'user');
    return 'applied';
}

/** הטריגר: ביקור הושלם (נקרא מ-apr_visit_finish, בתוך הטרנזקציה שלו). טיוטה ראשונה — פעם אחת לתיק (17-A6); ביקור נוסף — רענון והצעות. */
function apr_report_on_visit(array $c, array $visit): void
{
    $has = (bool)apr_val('SELECT 1 FROM report_versions WHERE case_id = ? AND deleted_at IS NULL', [$c['id']]);
    if (!$has && apr_report_profile($c)['trigger'] !== 'visit_completed') return;
    apr_job_add('report.draft', ['case' => $c['pid']], $has ? 'report.refresh:' . $visit['pid'] . ':' . $visit['rev'] : 'report.draft:' . $c['pid'], (int)$c['office_id']);
}

function apr_report_job(array $payload): string
{
    $c = apr_is_pid($payload['case'] ?? null) ? apr_row("SELECT * FROM cases WHERE pid = ? AND deleted_at IS NULL AND status <> 'closed'", [$payload['case']]) : null;
    if (!$c) return 'done';
    $c += ['perms' => [], 'my_role' => 'system', 'share' => null];
    $ctx = ['user' => null, 'office' => null];
    $v = apr_row("SELECT * FROM report_versions WHERE case_id = ? AND deleted_at IS NULL ORDER BY number DESC LIMIT 1", [$c['id']]);
    if ($v) { apr_report_refresh($ctx, $c, $v, null); return 'done'; }
    $v = apr_tx(fn() => apr_report_create($ctx, $c, null, 'auto'));
    apr_report_ai_all(null, $c, $v);
    return 'done';
}

apr_job_register('report.draft', 'דוח: יצירת טיוטה אחרי ביקור, ורענון הפרקים כשמגיע מידע חדש', fn(array $p) => apr_report_job($p));

// ── קריאה ─────────────────────────────────────────────────────

/** case-report {case} — "דוח וגרסאות": רשימת הגרסאות שהמבקש רואה, ומה מותר לו. */
function apr_case_report(array $ctx, array $q): array
{
    $c = apr_report_case($ctx, $q['case'] ?? null);
    if ($c['share'] !== null && !apr_can($c, 'report') && !apr_can($c, 'details')) apr_fail('not_found');
    $rows = apr_rows('SELECT v.*, cb.name AS created_name, sb.name AS submitted_name, ab.name AS approved_name FROM report_versions v LEFT JOIN users cb ON cb.id = v.created_by
        LEFT JOIN users sb ON sb.id = v.submitted_by LEFT JOIN users ab ON ab.id = v.approved_by WHERE v.case_id = ? AND v.deleted_at IS NULL ORDER BY v.number DESC', [$c['id']]);
    $rows = array_values(array_filter($rows, fn($v) => apr_report_visible($c, $v)));
    $internal = $c['share'] === null;
    $job = $internal ? apr_row("SELECT status, last_error FROM jobs WHERE dedupe_key = ?", ['report.draft:' . $c['pid']]) : null;
    // הבקרה בסקירת התיק (§9): כמה חוסמים וכמה אזהרות בגרסה הפתוחה — בלי רענון ובלי כתיבה
    $check = null;
    $open = $internal ? (array_values(array_filter($rows, fn($v) => in_array($v['state'], APR_REPORT_OPEN, true)))[0] ?? null) : null;
    if ($open !== null) {
        $levels = array_count_values(array_column(apr_report_findings($c, $open, apr_report_sections($ctx, $c, $open)), 'level'));
        $check = ['version' => $open['pid'], 'number' => (int)$open['number'], 'state' => $open['state'], 'block' => $levels['block'] ?? 0, 'warn' => $levels['warn'] ?? 0];
    }
    return ['case' => apr_comp_case_head($c), 'perms' => apr_report_perms($ctx, $c), 'versions' => array_map(fn($v) => apr_report_version_head($v, $internal), $rows),
        'trigger' => $internal ? apr_report_profile($c)['trigger'] : null, 'check' => $check,
        // "יצירת הטיוטה מתעכבת: <סיבה>" (§6.3) — המשימה נכשלה או ממתינה לטיפול
        'draft_job' => $job && !$rows && in_array($job['status'], ['failed', 'needs_action'], true) ? ['status' => $job['status'], 'error' => $job['last_error']] : null,
        'can_create' => $internal && !array_filter($rows, fn($v) => in_array($v['state'], APR_REPORT_OPEN, true)) ? (!$rows ? 'draft' : 'new_version') : null];
}

/**
 * report-version {version} — העורך / הקריאה. טיוטה ובבדיקה: הפרקים עם הערכים של הרגע, ההצעות, ההערות והבקרה. גרסה שאושרה: **מה-snapshot בלבד**
 * (17-C6), ואם הנתונים השתנו מאז — מסומנת "נתונים השתנו אחרי האישור". שותף ממשרד אחר מקבל את הפרקים בלבד.
 */
function apr_report_version(array $ctx, array $q): array
{
    $c = apr_report_case($ctx, apr_doc_case_pid('report_versions', $q['version'] ?? null));
    $v = apr_report_version_row($c, $q['version'] ?? null);
    $internal = $c['share'] === null;
    $base = ['case' => apr_comp_case_head($c), 'perms' => apr_report_perms($ctx, $c), 'internal' => $internal];
    if (in_array($v['state'], APR_REPORT_FINAL, true)) {
        if ((int)$v['stale_after_approval'] === 0 && $internal && apr_report_live_hash($ctx, $c, $v) !== $v['data_hash']) {
            apr_tx(function () use ($c, $v) {
                apr_exec('UPDATE report_versions SET stale_after_approval = 1, rev = rev + 1 WHERE id = ? AND stale_after_approval = 0', [$v['id']]);
                apr_case_audit($c, 'report.stale', 'report_version', $v['pid'], ['number' => (int)$v['number']], null, null, 'system');
            });
            $v = apr_report_version_row($c, $v['pid']);
        }
        $snap = json_decode((string)$v['snapshot'], true) ?: [];
        return $base + ['version' => apr_report_version_head($v, $internal), 'frozen' => true, 'sections' => array_map(fn($s) => $internal ? ['sources' => apr_report_sources_out($c, $s['sources'] ?? [])] + $s : apr_report_partner_section($s), $snap['sections'] ?? []),
            'findings' => $internal ? ($snap['findings'] ?? []) : [], 'comments' => $internal ? apr_report_comments($v) : [], 'approval' => $internal ? ['by' => $snap['approved_by'] ?? null, 'at' => $v['approved_at']] : null];
    }
    if ($internal && !empty($c['perms']['edit'])) {
        apr_report_refresh($ctx, $c, $v, (int)$ctx['user']['id']);
        $v = apr_report_version_row($c, $v['pid']);
    }
    $sections = apr_report_sections($ctx, $c, $v);
    return $base + ['version' => apr_report_version_head($v, $internal), 'frozen' => false, 'sections' => array_map(fn($s) => apr_report_section_out($ctx, $c, $v, $s, $internal), $sections),
        'findings' => $internal ? apr_report_findings_out($c, $v, $sections) : [], 'comments' => $internal ? apr_report_comments($v) : [],
        'lists' => $internal ? ['tables' => APR_REPORT_TABLE_LABELS, 'attributes' => array_map(fn($a) => ['key' => $a['key'], 'label' => $a['label']], array_values(array_filter(apr_attrs_all(), fn($a) => $a['status'] === 'active'))),
            'photos' => array_map(fn($p) => ['pid' => $p['pid'], 'caption' => $p['caption']], apr_rows("SELECT p.pid, p.caption FROM visit_photos p JOIN visits vv ON vv.id = p.visit_id WHERE vv.case_id = ? AND p.deleted_at IS NULL AND p.usage = 'report' ORDER BY p.id", [$c['id']]))] : null];
}

/** הממצאים + הנימוקים שכבר נשמרו לגרסה. */
function apr_report_findings_out(array $c, array $v, array $sections): array
{
    $acks = array_column(apr_rows('SELECT finding_key, reason FROM report_findings_ack WHERE version_id = ?', [$v['id']]), 'reason', 'finding_key');
    return array_map(fn($f) => $f + ['ack' => $acks[$f['key']] ?? null], apr_report_findings($c, $v, $sections));
}

function apr_report_comments(array $v): array
{
    return array_map(fn($r) => ['pid' => $r['pid'], 'section' => $r['section_pid'], 'text' => $r['text'], 'by' => $r['by_name'], 'at' => $r['created_at'], 'resolved_at' => $r['resolved_at'], 'resolved_by' => $r['resolved_name']],
        apr_rows('SELECT k.*, s.pid AS section_pid, u.name AS by_name, r.name AS resolved_name FROM report_comments k LEFT JOIN report_sections s ON s.id = k.section_id LEFT JOIN users u ON u.id = k.created_by
            LEFT JOIN users r ON r.id = k.resolved_by WHERE k.version_id = ? ORDER BY k.id', [$v['id']]));
}

/** hash של הנתונים שגרסה נשענת עליהם, כפי שהם עכשיו: כל הפרקים, פתורים. נשמר באישור, ומושווה אחר כך (§8.2). */
function apr_report_live_hash(array $ctx, array $c, array $v): string
{
    $tpl = apr_report_tpl($v);
    $all = [];
    foreach (apr_rows('SELECT key, title, content, origin FROM report_sections WHERE version_id = ? AND deleted_at IS NULL ORDER BY sort, id', [$v['id']]) as $s) {
        $all[$s['key']] = apr_report_resolve($ctx, $c, json_decode($s['content'], true) ?: [], $tpl, false);
        // פרק בינה הוא טקסט, לא הפניות: הנתונים שעליהם נכתב נכנסים ל-hash בנפרד — אחרת שינוי בהם אחרי האישור לא היה מדליק "נתונים השתנו" (17-C6)
        if ($s['origin'] === 'ai') $all[$s['key'] . '#ai'] = apr_report_ai_input($ctx, $c, $s, $tpl)['hash'];
    }
    return apr_report_hash($all);
}

/** report-compare {version, with} — השוואת שתי גרסאות: לכל פרק, השורות של כל צד. הגרסאות שאושרו — מה-snapshot. */
function apr_report_compare(array $ctx, array $q): array
{
    $c = apr_report_case($ctx, apr_doc_case_pid('report_versions', $q['version'] ?? null));
    if ($c['share'] !== null) apr_fail('not_found');
    $side = function (mixed $pid) use ($ctx, $c) {
        $v = apr_report_version_row($c, $pid);
        $out = [];
        if (in_array($v['state'], APR_REPORT_FINAL, true)) foreach ((json_decode((string)$v['snapshot'], true) ?: [])['sections'] ?? [] as $s) $out[$s['key']] = ['title' => $s['title'], 'lines' => apr_report_lines($s['blocks'])];
        else foreach (apr_report_sections($ctx, $c, $v) as $s) $out[$s['key']] = ['title' => $s['title'], 'lines' => apr_report_lines($s['view'])];
        return [$v, $out];
    };
    [$a, $sa] = $side($q['with'] ?? null);
    [$b, $sb] = $side($q['version'] ?? null);
    $sections = [];
    foreach (array_unique([...array_keys($sa), ...array_keys($sb)]) as $key) {
        $x = $sa[$key] ?? null;
        $y = $sb[$key] ?? null;
        $sections[] = ['key' => $key, 'title' => ($y ?? $x)['title'], 'status' => $x === null ? 'added' : ($y === null ? 'removed' : ($x['lines'] === $y['lines'] ? 'same' : 'changed')),
            'removed' => array_values(array_diff($x['lines'] ?? [], $y['lines'] ?? [])), 'added' => array_values(array_diff($y['lines'] ?? [], $x['lines'] ?? []))];
    }
    return ['case' => apr_comp_case_head($c), 'from' => apr_report_version_head($a), 'to' => apr_report_version_head($b), 'sections' => $sections];
}

// ── כתיבה: טיוטה, פרקים, הצעות, הערות ──────────────────────────

/** גרסה פתוחה לעריכה: טיוטה בלבד. */
function apr_report_draft(array $c, mixed $pid): array
{
    $v = apr_report_version_row($c, $pid);
    if ($v['state'] !== 'draft') apr_fail('report_state');
    return $v;
}

/** report-draft-create {case} — "יצירת טיוטה" ביוזמת השמאי (גם כשהטריגר אוטומטי וטרם הופעל; תיק בלי ביקור — מקרה קצה 13). */
function apr_report_draft_create(array $ctx, array $in): array
{
    $c = apr_report_case($ctx, $in['case'] ?? null, 'edit');
    if ($c['status'] === 'draft') apr_fail('report_state');
    $v = apr_tx(function () use ($ctx, $c) {
        if (apr_val('SELECT 1 FROM report_versions WHERE case_id = ? AND deleted_at IS NULL', [$c['id']])) apr_fail('report_exists');
        return apr_report_create($ctx, $c, (int)$ctx['user']['id'], 'manual');
    });
    apr_report_ai_all($ctx, $c, $v);
    apr_case_touch($c);
    return ['version' => $v['pid']];
}

/** report-section-save {section, rev, content, title?} — שמירת פרק בידי אדם. מעכשיו הפרק "נערך": מידע חדש יגיע כהצעה, לא כדריסה (§7). */
function apr_report_section_save(array $ctx, array $in): array
{
    $c = apr_report_case($ctx, apr_report_section_case($in['section'] ?? null), 'edit');
    $rev = apr_report_rev($in['rev'] ?? null);
    return apr_tx(function () use ($ctx, $c, $in, $rev) {
        $s = apr_report_section_row($c, $in['section'] ?? null);
        $v = apr_report_draft($c, apr_val('SELECT pid FROM report_versions WHERE id = ?', [$s['version_id']]));
        $prev = json_decode($s['content'], true) ?: [];
        $blocks = apr_report_blocks_in($c, $in['content'] ?? null, $prev);
        // חותמת על כל הפניה לנתון: הערך כפי שהיה כשהפרק נערך — כדי לסמן אחר כך "הערך השתנה: היה X"
        $facts = apr_report_facts($c);
        foreach ($blocks as &$b) if ($b['type'] === 'fact') $b['was'] = apr_report_fact_stamp($facts[$b['attr']]['value'] ?? null);
        unset($b);
        $title = array_key_exists('title', $in) ? (apr_clean_text($in['title'], 120) ?? apr_fail('report_block')) : $s['title'];
        $json = json_encode($blocks, JSON_UNESCAPED_UNICODE);
        if ($json === $s['content'] && $title === $s['title']) apr_fail('nothing_to_change');
        apr_report_update('report_sections', $s, $rev, ['content' => $json, 'title' => $title, 'user_edited' => 1, 'state' => $blocks ? 'edited' : 'empty', 'changed_note' => null]
            + apr_report_origin_after($ctx, $c, $s, $blocks, $json), (int)$ctx['user']['id']);
        apr_case_audit($c, 'report.section_save', 'report_section', $s['pid'], ['key' => $s['key'], 'version' => (int)$v['number'], 'blocks' => count($blocks)]);
        apr_case_touch($c);
        return ['section' => $s['pid'], 'rev' => $rev + 1];
    });
}

/**
 * מה קורה לסימון הבינה כשאדם שומר תוכן לפרק (🟡3). הסימון של פסקה נקבע בשרת: פסקה שהטקסט שלה זהה לטקסט בינה שהיה בפרק — עדיין בינה,
 * מה שלא יישלח מהמסך. הסימון של הפרק יורד רק ב-report-section-reviewed, או כש**שמאי בתיק** שינה בפועל את כל טקסט הבינה. מי שאינו שמאי
 * אינו מוריד אותו: הפרק נשאר "בינה", וסקירה שכבר נעשתה מתבטלת כשהתוכן השתנה.
 */
function apr_report_origin_after(array $ctx, array $c, array $s, array $blocks, string $json): array
{
    $reset = ['ai_reviewed' => 0, 'ai_reviewed_by' => null, 'ai_reviewed_at' => null];
    if ($s['origin'] !== 'ai') return ['origin' => 'manual'] + $reset;
    $ai = (bool)array_filter($blocks, fn($b) => !empty($b['ai']));
    if (apr_comp_can_judge($ctx, $c)) return $ai ? ['origin' => 'ai'] : ['origin' => 'manual'] + $reset;
    return ['origin' => 'ai'] + ($json !== $s['content'] ? $reset : []);
}

function apr_report_section_case(mixed $pid): ?string
{
    return apr_is_pid($pid) ? (apr_val('SELECT c.pid FROM report_sections s JOIN report_versions v ON v.id = s.version_id JOIN cases c ON c.id = v.case_id WHERE s.pid = ?', [$pid]) ?: null) : null;
}

/** report-section-add {version, title} — פרק נוסף בגרסה הזו (נכתב ידנית). */
function apr_report_section_add(array $ctx, array $in): array
{
    $c = apr_report_case($ctx, apr_doc_case_pid('report_versions', $in['version'] ?? null), 'edit');
    $title = apr_clean_text($in['title'] ?? null, 120) ?? apr_fail('report_block');
    return apr_tx(function () use ($ctx, $c, $in, $title) {
        $v = apr_report_draft($c, $in['version'] ?? null);
        if ((int)apr_val('SELECT COUNT(*) FROM report_sections WHERE version_id = ? AND deleted_at IS NULL', [$v['id']]) >= APR_REPORT_MAX_SECTIONS) apr_fail('report_limit');
        $pid = apr_pid();
        apr_insert('report_sections', ['pid' => $pid, 'version_id' => $v['id'], 'key' => 'custom_' . $pid, 'title' => $title, 'sort' => (int)apr_val('SELECT COALESCE(MAX(sort), 0) + 1 FROM report_sections WHERE version_id = ?', [$v['id']]),
            'build' => 'manual', 'origin' => 'manual', 'updated_by' => (int)$ctx['user']['id'], 'updated_at' => apr_now()]);
        apr_case_audit($c, 'report.section_add', 'report_section', $pid, ['title' => $title, 'version' => (int)$v['number']]);
        return ['section' => $pid];
    });
}

/** report-section-delete {section, rev} — הסרת פרק מהגרסה. גם פרק חובה: התבנית של הגרסה היא של השמאי, וההסרה נרשמת (מקרה קצה 11). */
function apr_report_section_delete(array $ctx, array $in): array
{
    $c = apr_report_case($ctx, apr_report_section_case($in['section'] ?? null), 'judge');
    $rev = apr_report_rev($in['rev'] ?? null);
    return apr_tx(function () use ($ctx, $c, $in, $rev) {
        $s = apr_report_section_row($c, $in['section'] ?? null);
        $v = apr_report_draft($c, apr_val('SELECT pid FROM report_versions WHERE id = ?', [$s['version_id']]));
        apr_report_update('report_sections', $s, $rev, ['deleted_at' => apr_now(), 'deleted_by' => (int)$ctx['user']['id']], (int)$ctx['user']['id']);
        apr_case_audit($c, 'report.section_delete', 'report_section', $s['pid'], ['key' => $s['key'], 'title' => $s['title'], 'mandatory' => (int)$s['mandatory'] === 1, 'version' => (int)$v['number']]);
        return ['deleted' => true];
    });
}

/**
 * report-rebuild {version, sections?: [pid], include_edited?: bool} — "יצירה מחדש" מהנתונים. ברירת המחדל **אינה נוגעת בפרקים שנערכו ידנית**
 * (17-B3): הם חוזרים ב-skipped, והמסך מציג אותם באזהרה לפני שמבקשים include_edited.
 */
function apr_report_rebuild(array $ctx, array $in): array
{
    $c = apr_report_case($ctx, apr_doc_case_pid('report_versions', $in['version'] ?? null), 'edit');
    $only = array_key_exists('sections', $in) ? (is_array($in['sections']) && array_is_list($in['sections']) ? $in['sections'] : apr_fail('report_block')) : null;
    $force = ($in['include_edited'] ?? false) === true;
    return apr_tx(function () use ($ctx, $c, $in, $only, $force) {
        $v = apr_report_draft($c, $in['version'] ?? null);
        $tpl = apr_report_tpl($v);
        $done = [];
        $skipped = [];
        foreach (apr_rows("SELECT * FROM report_sections WHERE version_id = ? AND deleted_at IS NULL AND build = 'data' ORDER BY sort, id", [$v['id']]) as $s) {
            if ($only !== null && !in_array($s['pid'], $only, true)) continue;
            if ((int)$s['user_edited'] === 1 && !$force) { $skipped[] = ['pid' => $s['pid'], 'title' => $s['title']]; continue; }
            $built = apr_report_build($c, $s['key'], $tpl);
            apr_report_update('report_sections', $s, (int)$s['rev'], ['content' => json_encode($built['blocks'], JSON_UNESCAPED_UNICODE), 'sources' => json_encode($built['sources'], JSON_UNESCAPED_UNICODE),
                'auto_hash' => apr_report_hash($built['blocks']) . ':' . apr_report_data_hash($ctx, $c, $built['blocks'], $tpl), 'origin' => 'auto', 'user_edited' => 0, 'state' => $built['blocks'] ? 'drafted' : 'empty',
                'ai_run_id' => null, 'ai_input_hash' => null, 'ai_reviewed' => 0, 'ai_reviewed_by' => null, 'ai_reviewed_at' => null, 'changed_note' => null], (int)$ctx['user']['id']);
            apr_exec("UPDATE section_proposals SET state = 'rejected', decided_by = ?, decided_at = ? WHERE section_id = ? AND state = 'pending'", [(int)$ctx['user']['id'], apr_now(), $s['id']]);
            $done[] = $s['pid'];
        }
        if ($done) apr_case_audit($c, 'report.rebuild', 'report_version', $v['pid'], ['sections' => count($done), 'include_edited' => $force, 'skipped' => count($skipped)]);
        return ['rebuilt' => $done, 'skipped' => $skipped];
    });
}

/**
 * report-section-ai {section, rev, replace?} — "ניסוח עכשיו": מנסח פרק אחד בבינה. רק פרק שהתבנית מתירה, ורק דרך השער. הטקסט מסומן וממתין
 * לסקירה. פרק שנערך בידי אדם מנוסח מחדש רק עם replace: true מפורש (המסך שואל) — אחרת 409, ואין קריאה לספק.
 */
function apr_report_section_ai(array $ctx, array $in): array
{
    $c = apr_report_case($ctx, apr_report_section_case($in['section'] ?? null), 'edit');
    $rev = apr_report_rev($in['rev'] ?? null);
    $s = apr_report_section_row($c, $in['section'] ?? null);
    $v = apr_report_draft($c, apr_val('SELECT pid FROM report_versions WHERE id = ?', [$s['version_id']]));
    if ((int)$s['ai_allowed'] !== 1 || in_array($s['key'], APR_REPORT_NO_AI, true) || !in_array($s['key'], APR_REPORT_AI_KEYS, true)) apr_fail('report_no_ai');
    if ((int)$s['rev'] !== $rev) throw new AprVisitError('report_changed', ['by' => null]);
    if ((int)$s['user_edited'] === 1 && ($in['replace'] ?? false) !== true) apr_fail('report_replace');
    if (!apr_limit('ai-report:u' . $ctx['user']['id'], ...APR_REPORT_RATE_AI)) apr_fail('ai_rate');
    $tpl = apr_report_tpl($v);
    $r = apr_report_ai_section($ctx, $c, $s, $tpl, 'rd:' . $s['pid'] . ':' . $s['rev']);
    $outcome = apr_tx(fn() => apr_report_ai_apply($c, $s, $r, $tpl, (int)$ctx['user']['id']));
    apr_case_touch($c);
    // discarded: הפרק או הגרסה כבר אינם פתוחים לכתיבה — הטקסט לא נשמר בשום מקום, והמסך אומר זאת (לא "הפרק נוסח")
    return ['state' => $outcome === 'discarded' ? 'discarded' : $r['state'], 'reason' => $outcome === 'discarded' ? 'gone' : $r['reason'], 'as_proposal' => $outcome === 'proposal', 'rev' => (int)apr_val('SELECT rev FROM report_sections WHERE id = ?', [$s['id']])];
}

/**
 * report-section-reviewed {section, rev, confirm_changed?} — שמאי בתיק מסמן שסקר את טקסט הבינה. בלי זה אין אישור (ממצא חוסם). כשהנתונים
 * שעליהם נכתב הטקסט השתנו מאז — 409 (report_ai_stale), אלא אם הבקשה נושאת confirm_changed: true.
 */
function apr_report_section_reviewed(array $ctx, array $in): array
{
    $c = apr_report_case($ctx, apr_report_section_case($in['section'] ?? null), 'judge');
    $rev = apr_report_rev($in['rev'] ?? null);
    return apr_tx(function () use ($ctx, $c, $in, $rev) {
        $s = apr_report_section_row($c, $in['section'] ?? null);
        $v = apr_report_draft($c, apr_val('SELECT pid FROM report_versions WHERE id = ?', [$s['version_id']]));
        if ($s['origin'] !== 'ai' || (int)$s['ai_reviewed'] === 1) apr_fail('nothing_to_change');
        // B3-ב: "סקרתי" אינו חותם על נתונים שהשתנו מאז שהטקסט נכתב. ה-rev של פרק שלא נסקר אינו מתקדם כשנתון משתנה, ולכן מי שסוקר ממסך ישן
        // היה חותם בלי לדעת — הוא מקבל 409. רק אישור מפורש (confirm_changed), שהמסך שולח אחרי שהציג את הממצא ואת ההצעה, קובע את הנתונים של
        // הרגע כבסיס שממנו נמדד "הנתונים השתנו אחרי שהטקסט נכתב" (B3). האישור נרשם ביומן
        $now = apr_report_ai_input($ctx, $c, $s, apr_report_tpl($v))['hash'];
        $changed = $now !== $s['ai_input_hash'];
        if ($changed && ($in['confirm_changed'] ?? false) !== true) apr_fail('report_ai_stale');
        apr_report_update('report_sections', $s, $rev, ['ai_input_hash' => $now, 'ai_reviewed' => 1, 'ai_reviewed_by' => (int)$ctx['user']['id'], 'ai_reviewed_at' => apr_now(), 'state' => 'reviewed'], (int)$ctx['user']['id']);
        apr_case_audit($c, 'report.section_reviewed', 'report_section', $s['pid'], ['key' => $s['key']] + ($changed ? ['confirm_changed' => true] : []));
        return ['section' => $s['pid'], 'rev' => $rev + 1];
    });
}

/** report-proposal-decide {proposal, decision: accept | reject | merge, content? (למיזוג)} — ההחלטה נרשמת; התוכן משתנה רק ב-accept / merge. */
function apr_report_proposal_decide(array $ctx, array $in): array
{
    $p = apr_is_pid($in['proposal'] ?? null) ? apr_row('SELECT p.*, s.pid AS section_pid FROM section_proposals p JOIN report_sections s ON s.id = p.section_id WHERE p.pid = ?', [$in['proposal']]) : null;
    $c = apr_report_case($ctx, $p ? apr_report_section_case($p['section_pid']) : null, 'edit');
    $decision = in_array($in['decision'] ?? null, ['accept', 'reject', 'merge'], true) ? $in['decision'] : apr_fail('report_block');
    return apr_tx(function () use ($ctx, $c, $in, $p, $decision) {
        $s = apr_report_section_row($c, $p['section_pid']);
        $v = apr_report_draft($c, apr_val('SELECT pid FROM report_versions WHERE id = ?', [$s['version_id']]));
        if ($p['state'] !== 'pending') apr_fail('nothing_to_change');
        $uid = (int)$ctx['user']['id'];
        if ($decision !== 'reject') {
            $tpl = apr_report_tpl($v);
            $proposed = json_decode($p['content'], true) ?: [];
            $fromAi = $p['origin'] === 'ai';
            $blocks = $decision === 'accept' ? $proposed : apr_report_blocks_in($c, $in['content'] ?? null, [...(json_decode($s['content'], true) ?: []), ...($fromAi ? $proposed : [])]);
            if ($decision === 'merge') {
                $facts = apr_report_facts($c);
                foreach ($blocks as &$b) if ($b['type'] === 'fact') $b['was'] = apr_report_fact_stamp($facts[$b['attr']]['value'] ?? null);
                unset($b);
            }
            $json = json_encode($blocks, JSON_UNESCAPED_UNICODE);
            $reset = ['ai_reviewed' => 0, 'ai_reviewed_by' => null, 'ai_reviewed_at' => null];
            $aiSet = ['origin' => 'ai', 'ai_run_id' => $p['ai_run_id'], 'ai_input_hash' => $p['ai_input_hash']] + $reset;
            if ($decision === 'accept') {
                // הצעה שמקורה בבינה (ניסוח שהגיע אחרי עריכה, B2) נכנסת כטקסט בינה שלא נסקר; הצעה מהנתונים — כפרק שנבנה מהנתונים
                $set = $fromAi ? $aiSet : ['origin' => 'auto', 'ai_input_hash' => null, 'auto_hash' => apr_report_hash($proposed) . ':' . apr_report_data_hash($ctx, $c, $proposed, $tpl)] + $reset;
            } else {
                // מיזוג אינו מסיר את סימון הבינה (🟡3): אותו כלל כמו בשמירה. מיזוג של הצעת בינה: טקסט בינה שנשאר כלשונו — הפרק "בינה", לא נסקר;
                // ומי שאינו שמאי בתיק אינו מוריד את הסימון גם כששינה את הטקסט — תו אחד שונה אינו סקירה
                $took = $fromAi && (array_filter($blocks, fn($b) => !empty($b['ai'])) || !apr_comp_can_judge($ctx, $c));
                $set = $took ? $aiSet : apr_report_origin_after($ctx, $c, $s, $blocks, $json);
                if (!$fromAi) $set['auto_hash'] = apr_report_hash($proposed) . ':' . apr_report_data_hash($ctx, $c, $proposed, $tpl);
            }
            apr_report_update('report_sections', $s, (int)$s['rev'], ['content' => $json, 'user_edited' => $decision === 'merge' ? 1 : 0, 'state' => $decision === 'merge' ? 'edited' : 'drafted', 'changed_note' => null] + $set, $uid);
        }
        apr_exec('UPDATE section_proposals SET state = ?, decided_by = ?, decided_at = ? WHERE id = ?', [['accept' => 'accepted', 'reject' => 'rejected', 'merge' => 'merged'][$decision], $uid, apr_now(), $p['id']]);
        apr_case_audit($c, 'report.proposal_' . $decision, 'report_section', $s['pid'], ['key' => $s['key'], 'proposal' => $p['pid']]);
        apr_case_touch($c);
        return ['section' => $s['pid'], 'decision' => $decision];
    });
}

/** report-comment-add {version, section?, text} — הערה לבודק. מותרת גם כשהגרסה בבדיקה. */
function apr_report_comment_add(array $ctx, array $in): array
{
    $c = apr_report_case($ctx, apr_doc_case_pid('report_versions', $in['version'] ?? null), 'edit');
    $text = apr_clean_text($in['text'] ?? null, 2000) ?? apr_fail('report_block');
    return apr_tx(function () use ($ctx, $c, $in, $text) {
        $v = apr_report_version_row($c, $in['version'] ?? null);
        if (!in_array($v['state'], APR_REPORT_OPEN, true)) apr_fail('report_state');
        $sid = ($in['section'] ?? null) === null ? null : (int)(apr_val('SELECT id FROM report_sections WHERE pid = ? AND version_id = ? AND deleted_at IS NULL', [is_string($in['section']) ? $in['section'] : '', $v['id']]) ?: apr_fail('not_found'));
        $pid = apr_pid();
        apr_insert('report_comments', ['pid' => $pid, 'version_id' => $v['id'], 'section_id' => $sid, 'text' => $text, 'created_by' => (int)$ctx['user']['id'], 'created_at' => apr_now()]);
        apr_case_audit($c, 'report.comment', 'report_version', $v['pid'], ['comment' => $pid]);
        return ['comment' => $pid];
    });
}

/** report-comment-resolve {comment} */
function apr_report_comment_resolve(array $ctx, array $in): array
{
    $k = apr_is_pid($in['comment'] ?? null) ? apr_row('SELECT k.*, v.pid AS version_pid FROM report_comments k JOIN report_versions v ON v.id = k.version_id WHERE k.pid = ?', [$in['comment']]) : null;
    $c = apr_report_case($ctx, $k ? apr_doc_case_pid('report_versions', $k['version_pid']) : null, 'edit');
    if ($k['resolved_at'] !== null) apr_fail('nothing_to_change');
    if (!in_array(apr_report_version_row($c, $k['version_pid'])['state'], APR_REPORT_OPEN, true)) apr_fail('report_approved');       // גרסה שאושרה קפואה — גם ההערות שלה
    apr_exec('UPDATE report_comments SET resolved_at = ?, resolved_by = ? WHERE id = ?', [apr_now(), (int)$ctx['user']['id'], $k['id']]);
    apr_case_audit($c, 'report.comment_resolve', 'report_version', $k['version_pid'], ['comment' => $k['pid']]);
    return ['comment' => $k['pid']];
}

// ── בדיקה, אישור וגרסאות (§8) ─────────────────────────────────

/** report-submit {version, rev} — "העברה לבדיקה": מעכשיו הפרקים אינם נערכים, עד אישור או החזרה לעריכה. */
function apr_report_submit(array $ctx, array $in): array
{
    return apr_report_move($ctx, $in, 'draft', 'in_review', 'report.submit');
}

/** report-return {version, rev, reason?} — "החזרה לעריכה". */
function apr_report_return(array $ctx, array $in): array
{
    return apr_report_move($ctx, $in, 'in_review', 'draft', 'report.return');
}

function apr_report_move(array $ctx, array $in, string $from, string $to, string $action): array
{
    $c = apr_report_case($ctx, apr_doc_case_pid('report_versions', $in['version'] ?? null), 'judge');
    $rev = apr_report_rev($in['rev'] ?? null);
    $reason = apr_clean_text($in['reason'] ?? null, 500);
    return apr_tx(function () use ($ctx, $c, $in, $rev, $from, $to, $action, $reason) {
        $v = apr_report_version_row($c, $in['version'] ?? null);
        if ($v['state'] !== $from) apr_fail('report_state');
        if ($to === 'in_review') apr_report_refresh($ctx, $c, $v, (int)$ctx['user']['id']);
        apr_report_update('report_versions', $v, $rev, ['state' => $to] + ($to === 'in_review' ? ['submitted_by' => (int)$ctx['user']['id'], 'submitted_at' => apr_now()] : ['submitted_by' => null, 'submitted_at' => null]), null);
        apr_case_audit($c, $action, 'report_version', $v['pid'], ['number' => (int)$v['number']], $reason);
        apr_case_touch($c);
        return ['version' => $v['pid'], 'state' => $to, 'rev' => $rev + 1];
    });
}

/**
 * report-approve {version, rev, acks: {finding_key: נימוק}} — האישור (§8.1). רק בעל approve_report בתיק; פעולה רגישה. רצה הבקרה: ממצא חוסם ←
 * אין אישור; לכל אזהרה נדרש נימוק. באישור נשמר snapshot מלא (כל הפניה עם הערך של הרגע), מחושב content_hash, הגרסה ננעלת, הגרסה המאושרת
 * הקודמת הופכת superseded, ומצב התיק ← approved. **זו הדרך היחידה להגיע ל"מאושר"** (17-C8).
 */
function apr_report_approve(array $ctx, array $in): array
{
    $c = apr_report_case($ctx, apr_doc_case_pid('report_versions', $in['version'] ?? null), 'approve');
    $rev = apr_report_rev($in['rev'] ?? null);
    $acks = $in['acks'] ?? [];
    if (!is_array($acks)) apr_fail('report_block');
    return apr_tx(function () use ($ctx, $c, $in, $rev, $acks) {
        $v = apr_report_version_row($c, $in['version'] ?? null);
        if (in_array($v['state'], APR_REPORT_FINAL, true)) apr_fail('report_approved');
        if ($v['state'] !== 'in_review') apr_fail('report_state');
        if ((int)$v['rev'] !== $rev) throw new AprVisitError('report_changed', ['by' => null]);
        $uid = (int)$ctx['user']['id'];
        $now = apr_now();
        $sections = apr_report_sections($ctx, $c, $v);
        $findings = apr_report_findings($c, $v, $sections);
        $blocking = array_values(array_filter($findings, fn($f) => $f['level'] === 'block'));
        if ($blocking) throw new AprVisitError('report_blocked', ['findings' => $blocking]);
        $noted = [];
        $need = [];
        foreach ($findings as $f) {
            $reason = apr_clean_text($acks[$f['key']] ?? null, 500);
            if ($reason === null) { $need[] = $f; continue; }
            $noted[] = $f + ['ack' => $reason];
        }
        if ($need) throw new AprVisitError('report_ack_required', ['findings' => $need]);
        foreach ($noted as $f) apr_insert('report_findings_ack', ['version_id' => $v['id'], 'finding_key' => $f['key'], 'reason' => $f['ack'], 'created_by' => $uid, 'created_at' => $now]);
        $tpl = apr_report_tpl($v);
        $snapshot = ['at' => $now, 'approved_by' => $ctx['user']['name'] ?? null, 'template' => $tpl, 'findings' => $noted,
            'sections' => array_map(fn($s) => ['pid' => $s['pid'], 'key' => $s['key'], 'title' => $s['title'], 'mandatory' => (int)$s['mandatory'] === 1, 'build' => $s['build'], 'origin' => $s['origin'], 'state' => $s['state'],
                'ai_reviewed' => (int)$s['ai_reviewed'] === 1, 'blocks' => $s['view'], 'sources' => json_decode($s['sources'], true) ?: []], $sections),
            // הנתונים שהדוח נשען עליהם, כפי שהיו: הערכים המוסכמים, וכל הטבלאות שנבנות מהתיק — גם אלה שאינן משובצות בפרק
            'data' => ['facts' => array_map(fn($f) => ['label' => $f['attr']['label'], 'value' => $f['value'], 'verified' => $f['verified'], 'fact' => $f['fact']['pid']], apr_report_facts($c)),
                'tables' => array_combine(APR_REPORT_TABLES, array_map(fn($t) => apr_report_table($ctx, $c, $t, $tpl), APR_REPORT_TABLES))]];
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        apr_report_update('report_versions', $v, $rev, ['state' => 'approved', 'approved_by' => $uid, 'approved_at' => $now, 'approval_notes' => json_encode(array_column($noted, 'ack', 'key'), JSON_UNESCAPED_UNICODE),
            'snapshot' => $json, 'content_hash' => hash('sha256', $json), 'data_hash' => apr_report_live_hash($ctx, $c, $v)], null);
        foreach (apr_rows("SELECT id, pid FROM report_versions WHERE report_id = ? AND id <> ? AND state IN ('approved', 'delivered') AND deleted_at IS NULL", [$v['report_id'], $v['id']]) as $old) {
            apr_exec("UPDATE report_versions SET state = 'superseded', rev = rev + 1 WHERE id = ?", [$old['id']]);
            apr_case_audit($c, 'report.superseded', 'report_version', $old['pid'], ['by' => $v['pid']], null, null, 'system');
        }
        if ($c['status'] !== 'approved') apr_exec("UPDATE cases SET status = 'approved', updated_at = ? WHERE id = ?", [$now, $c['id']]);
        apr_case_audit($c, 'report.approve', 'report_version', $v['pid'], ['number' => (int)$v['number'], 'warnings' => count($noted), 'hash' => hash('sha256', $json), 'case_status_from' => $c['status']]);
        apr_case_touch($c);
        return ['version' => $v['pid'], 'state' => 'approved', 'rev' => $rev + 1, 'content_hash' => hash('sha256', $json)];
    });
}

/** report-version-new {case, reason} — "גרסה חדשה" אחרי אישור: טיוטה במספר הבא, העתק של המאושרת עם הערכים העדכניים. אין "ביטול אישור". */
function apr_report_version_new(array $ctx, array $in): array
{
    $c = apr_report_case($ctx, $in['case'] ?? null, 'approve');
    $reason = apr_clean_text($in['reason'] ?? null, 500) ?? apr_fail('reason_required');
    return apr_tx(function () use ($ctx, $c, $reason) {
        $from = apr_row("SELECT * FROM report_versions WHERE case_id = ? AND deleted_at IS NULL AND state IN ('approved', 'delivered') ORDER BY number DESC LIMIT 1", [$c['id']]) ?? apr_fail('report_state');
        $v = apr_report_create($ctx, $c, (int)$ctx['user']['id'], 'from_version', $from, $reason);
        apr_case_touch($c);
        return ['version' => $v['pid'], 'number' => (int)$v['number']];
    });
}

/** report-version-delete {version, rev, reason} — רק טיוטה שלא אושרה, רק בידי approve_report, בנימוק. מחיקה רכה (מקרה קצה 12). */
function apr_report_version_delete(array $ctx, array $in): array
{
    $c = apr_report_case($ctx, apr_doc_case_pid('report_versions', $in['version'] ?? null), 'approve');
    $rev = apr_report_rev($in['rev'] ?? null);
    $reason = apr_clean_text($in['reason'] ?? null, 500) ?? apr_fail('reason_required');
    return apr_tx(function () use ($ctx, $c, $in, $rev, $reason) {
        $v = apr_report_draft($c, $in['version'] ?? null);
        apr_report_update('report_versions', $v, $rev, ['deleted_at' => apr_now(), 'deleted_by' => (int)$ctx['user']['id'], 'delete_reason' => $reason], null);
        apr_exec('UPDATE reports SET current_version_id = (SELECT id FROM report_versions WHERE report_id = ? AND deleted_at IS NULL ORDER BY number DESC LIMIT 1) WHERE id = ?', [$v['report_id'], $v['report_id']]);
        apr_case_audit($c, 'report.version_delete', 'report_version', $v['pid'], ['number' => (int)$v['number']], $reason);
        return ['deleted' => true];
    });
}
