<?php
/**
 * בדיקת מוטציות לפרוסה 17 (הדוח): שובר תנאי אחד בקוד בכל פעם, מריץ את u-report.test.php, ומוודא שבדיקה נופלת (✗) — ולא שהקוד קורס.
 * הרצה: php appraisers/tests/mutations-17.php [מחרוזת מהשם]  (כמה דקות). הקובץ שנשבר מוחזר למצבו אחרי כל מוטציה, גם אם ההרצה נקטעת.
 * **לא לעשות commit בזמן שהיא רצה** — היא שוברת זמנית קבצים ב-lib/ (גם את המיגרציה 017).
 * NOT-APPLIED = המחרוזת של המוטציה כבר אינה בקוד (הקוד השתנה) — לעדכן את המוטציה, אחרת התנאי נשאר בלי בדיקה.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$lib = dirname(__DIR__) . '/lib/';
$SQL = 'migrations/017_report.sql';
$M = [
    // ── מי רשאי (§11) ──
    ['17-C1 רק approve_report מאשר', 'report.php', "'approve' => 'approve_report'][\$need]", "'approve' => 'edit'][\$need]"],
    ['§11 סקירה / העברה / הסרת פרק — רק שמאי בתיק', 'report.php', "    if (\$need === 'judge' && !apr_comp_can_judge(\$ctx, \$c)) apr_fail('forbidden');\n", ''],
    ['§11 העברה לבדיקה דורשת judge', 'report.php', "\$c = apr_report_case(\$ctx, apr_doc_case_pid('report_versions', \$in['version'] ?? null), 'judge');", "\$c = apr_report_case(\$ctx, apr_doc_case_pid('report_versions', \$in['version'] ?? null), 'edit');"],
    ['§11 גרסה חדשה — רק approve_report', 'report.php', "\$c = apr_report_case(\$ctx, \$in['case'] ?? null, 'approve');", "\$c = apr_report_case(\$ctx, \$in['case'] ?? null, 'edit');"],
    ['§8.2 גרסה חדשה — בנימוק', 'report.php', "    \$reason = apr_clean_text(\$in['reason'] ?? null, 500) ?? apr_fail('reason_required');\n    return apr_tx(function () use (\$ctx, \$c, \$reason) {", "    \$reason = apr_clean_text(\$in['reason'] ?? null, 500) ?? 'x';\n    return apr_tx(function () use (\$ctx, \$c, \$reason) {"],
    ['מקרה קצה 12: מחיקת טיוטה — רק approve_report', 'report.php', "'approve');\n    \$rev = apr_report_rev(\$in['rev'] ?? null);\n    \$reason =", "'edit');\n    \$rev = apr_report_rev(\$in['rev'] ?? null);\n    \$reason ="],
    ['17-A3 טיוטה אחת לתיק', 'report.php', "        if (apr_val('SELECT 1 FROM report_versions WHERE case_id = ? AND deleted_at IS NULL', [\$c['id']])) apr_fail('report_exists');\n", ''],
    // ── שותף (17-D3) ──
    ['17-D3 שותף: טיוטה רק עם apr_can(report)', 'report.php', "    return apr_can(\$c, 'report') || (in_array(\$v['state'], APR_REPORT_FINAL, true) && apr_can(\$c, 'details'));", "    return true;"],
    ['17-D3 שותף: מאושרת רק בשיתוף של כל התיק', 'report.php', "(in_array(\$v['state'], APR_REPORT_FINAL, true) && apr_can(\$c, 'details'));", "in_array(\$v['state'], APR_REPORT_FINAL, true);"],
    ['17-D3 הגרסה נבדקת בכל קריאה', 'report.php', "    if (!\$v || !apr_report_visible(\$c, \$v)) apr_fail('not_found');", "    if (!\$v) apr_fail('not_found');"],
    ['17-D3 רשימת הגרסאות מסוננת', 'report.php', "    \$rows = array_values(array_filter(\$rows, fn(\$v) => apr_report_visible(\$c, \$v)));\n    \$internal", "    \$rows = array_values(\$rows);\n    \$internal"],
    ['17-D3 see_draft באזור report', 'shares.php', "'report' => \$in('report') && \$has('see_draft'),", "'report' => \$in('report'),"],
    ['שותף: מאושרת — פרקים בלבד (בלי ממצאים)', 'report.php', "'findings' => \$internal ? (\$snap['findings'] ?? []) : [],", "'findings' => (\$snap['findings'] ?? []),"],
    ['שותף: מאושרת — בלי מקורות ומזהים פנימיים', 'report.php', ": apr_report_partner_section(\$s), \$snap['sections'] ?? []),", ": \$s, \$snap['sections'] ?? []),"],
    ['שותף: טיוטה — בלי ממצאים והערות', 'report.php', "'findings' => \$internal ? apr_report_findings_out(\$c, \$v, \$sections) : [],", "'findings' => apr_report_findings_out(\$c, \$v, \$sections),"],
    ['שותף: פרק בלי שדות העריכה', 'report.php', "    if (!\$internal) return apr_report_partner_section(\$o);\n", ''],
    ['כרטיס התיק: סיכום הדוח רק למה שנראה', 'report.php', "fn(\$v) => apr_report_visible(\$c, \$v)));\n    if (\$c['share'] !== null && !\$rows) return null;", "fn(\$v) => true));\n    if (\$c['share'] !== null && !\$rows) return null;"],
    // ── עריכה, rev, הצעות (§7) ──
    ['17-B4 rev ישן ← 409', 'report.php', "rev = rev + 1 WHERE id = ? AND rev = ? AND deleted_at IS NULL'", "rev = rev + 1 WHERE id = ? AND rev >= ? - 1000000 AND deleted_at IS NULL'"],
    ['17-B1 פרק שנערך אינו נדרס', 'report.php', "            if ((int)\$s['user_edited'] === 0 && \$s['origin'] !== 'ai') {", "            if (\$s['origin'] !== 'ai') {"],
    ['17-B1 טקסט בינה אינו נדרס', 'report.php', "            if ((int)\$s['user_edited'] === 0 && \$s['origin'] !== 'ai') {", "            if ((int)\$s['user_edited'] === 0) {"],
    ['17-B2 פרק שלא נערך מתעדכן', 'report.php', "            if (\$hash === \$s['auto_hash']) continue;\n", "            continue;\n"],
    ['17-B3 יצירה מחדש אינה נוגעת בנערך', 'report.php', "            if ((int)\$s['user_edited'] === 1 && !\$force) {", "            if (false) {"],
    ['§7 עריכה רק בטיוטה', 'report.php', "    if (\$v['state'] !== 'draft') apr_fail('report_state');\n", ''],
    ['§7 עריכה מסמנת user_edited', 'report.php', "'title' => \$title, 'user_edited' => 1,", "'title' => \$title, 'user_edited' => 0,"],
    ['§7 חותמת הערך בעריכה', 'report.php', "        unset(\$b);\n        \$title = array_key_exists('title', \$in)", "        foreach (\$blocks as &\$b) unset(\$b['was']);\n        unset(\$b);\n        \$title = array_key_exists('title', \$in)"],
    ['§5 מקור של תיק אחר אינו מקור', 'report_build.php', "    if (!apr_val(\"SELECT 1 FROM \$table WHERE pid = ? AND \$col = ?\", [\$s['pid'], \$c['id']])) apr_fail('report_block');", ''],
    ['§5 סימון בינה נשאר רק על טקסט שלא השתנה', 'report_build.php', " && in_array(\$o['text'], array_column(array_filter(\$prev, fn(\$x) => !empty(\$x['ai'])), 'text'), true)) \$o['ai'] = true;", ") \$o['ai'] = true;"],
    ['17-D1 "מספר שלא בנתונים" אינו נמחק בשמירה חוזרת', 'report_build.php', "        if (\$o['type'] === 'p' && isset(\$flagged[\$o['text']])) \$o['num_unknown'] = \$flagged[\$o['text']];\n", ''],
    // ── בינה ──
    // (אין מוטציה ל"בינה לא זמינה ← אין קריאה למתאם": גם השער של ai_run חוסם — שתי שכבות, נבדק ב-q-ai; ואין ל"רק תמונות usage = report": CHECK ב-011 אוסר for_report בלי usage = report)
    ['סטייה 7: רשימה סגורה בפרופיל', 'report_build.php', "&& \$build === 'data' && in_array(\$key, APR_REPORT_AI_KEYS, true)];", "&& \$build === 'data' && !in_array(\$key, APR_REPORT_NO_AI, true)];"],
    ['סטייה 7: purpose אינו ברשימה', 'report_build.php', "const APR_REPORT_AI_KEYS = ['property', 'area_desc', 'visit', 'planning'];", "const APR_REPORT_AI_KEYS = ['property', 'area_desc', 'visit', 'planning', 'purpose'];"],
    ['סטייה 7: הרשימה נאכפת גם בזמן הניסוח', 'report_build.php', " || !in_array(\$section['key'], APR_REPORT_AI_KEYS, true)) return ['state' => 'failed', 'reason' => 'not_allowed'", ") return ['state' => 'failed', 'reason' => 'not_allowed'"],
    ['סטייה 7: שם של גורם מושמט', 'report_build.php', "        if (str_contains(\$norm, apr_report_ai_norm(\$p))) return null;\n        foreach (apr_report_ai_tokens(\$p) as \$t) if (apr_report_ai_word(\$norm, \$t, true)) return null;\n", ''],
    ['סטייה 7: טלפון מושמט', 'report_build.php', "\\d{4}(?!\\d)/', \$flat) === 1) return null;", "\\d{4}(?!\\d)/', \$flat) === 2) return null;"],
    ['סטייה 7: ת"ז מושמטת', 'report_build.php', "\\d{8,9}(?!\\d)/', \$text) === 1 ||", "\\d{8,9}(?!\\d)/', \$text) === 2 ||"],
    ['סטייה 7: מייל מושמט', 'report_build.php', "[a-z]{2,}/iu', \$text) === 1) return null;", "[a-z]{2,}/iu', \$text) === 2) return null;"],
    ['סטייה 7: שם המבקר אינו נכנס לקלט', 'report_build.php', "(\$v['visitor'] && empty(\$tpl['for_ai']) ? ', בידי ' . \$v['visitor'] : '')", "(\$v['visitor'] ? ', בידי ' . \$v['visitor'] : '')"],
    ['סטייה 7: הקלט עובר ניקוי', 'report_build.php', "        \$text = apr_report_ai_scrub(\$text, \$pii);\n        if (\$text === null) { \$omitted++; return false; }\n", ''],
    ['17-D1 src שלא נשלח ← נזרק', 'report_build.php', " || \$src === null || !isset(\$items[\$src])) { \$dropped++; continue; }", " || \$src === null) { \$dropped++; continue; }"],
    ['17-D1 מספר שאינו בנתונים מסומן', 'report_build.php', " + (\$unknown ? ['num_unknown' => \$unknown] : []);", " + [];"],
    ['§6.2 טקסט בינה נכנס "לא נסקר"', 'report.php', "'ai_input_hash' => \$r['hash'] ?? null, 'ai_reviewed' => 0,", "'ai_input_hash' => \$r['hash'] ?? null, 'ai_reviewed' => 1,"],
    ['ניסוח בבינה אינו מעלים "לא ידוע"', 'report.php', "in_array(\$b['type'], ['data_table', 'image', 'note_missing'], true)", "in_array(\$b['type'], ['data_table', 'image'], true)"],
    ['17-D2 רק נתוני הפרק', 'report_build.php', "    \$built = apr_report_resolve(\$ctx, \$c, apr_report_build(\$c, \$section['key'], \$tpl)['blocks'], \$tpl);\n    \$facts = apr_report_facts(\$c);", "    \$built = apr_report_resolve(\$ctx, \$c, [...apr_report_build(\$c, \$section['key'], \$tpl)['blocks'], ...apr_report_build(\$c, 'property', \$tpl)['blocks']], \$tpl);\n    \$facts = apr_report_facts(\$c);"],
    // ── הבקרה (§9) והאישור (§8) ──
    ['17-C2 אין שמאי מורשה', 'report_build.php', "    if (!\$approver) \$add('no_approver',", "    if (false) \$add('no_approver',"],
    ['17-C2 פרק חובה ריק', 'report_build.php', "        if ((int)\$s['mandatory'] === 1 && !apr_report_has_content(\$blocks)) \$add(", "        if (false) \$add("],
    ['17-C2 טקסט בינה שלא נסקר', 'report_build.php', "        if (\$s['origin'] === 'ai' && (int)\$s['ai_reviewed'] === 0) \$add(", "        if (false) \$add("],
    ['17-C2 הצעת עדכון שלא הוכרעה', 'report_build.php', "        if (\$s['pending'] ?? false) \$add(", "        if (false) \$add("],
    ['17-C2 תמונה בלי usage = report', 'report_build.php', "            if (\$b['type'] === 'image' && \$b['state'] !== 'ok') \$add(", "            if (false) \$add("],
    ['17-C2 מספר שלא נמצא בנתונים', 'report_build.php', "            if (\$b['type'] === 'p' && !empty(\$b['num_unknown'])) \$add(", "            if (false) \$add("],
    ['§9 ארבעת הראשונים תמיד חוסמים', 'report_build.php', "'level' => APR_REPORT_FINDINGS[\$kind][1] ? 'block' : \$levels[\$kind],", "'level' => \$levels[\$kind],"],
    ['§9 תמונה: מצב ההרשאה', 'report_build.php', ": (\$p['usage'] !== 'report' ? 'not_report' : 'ok');", ": 'ok';"],
    ['17-C4 נתון חסר עולה בבקרה', 'report_build.php', "            if (\$b['type'] === 'note_missing') \$add(", "            if (false) \$add("],
    ['17-C2 ממצא חוסם ← אין אישור', 'report.php', "        if (\$blocking) throw new AprVisitError('report_blocked', ['findings' => \$blocking]);\n", ''],
    ['17-C3 אזהרה בלי נימוק ← אין אישור', 'report.php', "        if (\$need) throw new AprVisitError('report_ack_required', ['findings' => \$need]);\n", ''],
    ['§8.1 אישור רק מ"בבדיקה"', 'report.php', "        if (\$v['state'] !== 'in_review') apr_fail('report_state');\n        if ((int)\$v['rev'] !== \$rev)", "        if ((int)\$v['rev'] !== \$rev)"],
    ['מקרה קצה 6: כבר אושרה', 'report.php', "        if (in_array(\$v['state'], APR_REPORT_FINAL, true)) apr_fail('report_approved');\n", ''],
    ['17-C6 ה-snapshot נושא את הערכים של הרגע', 'report.php', "'ai_reviewed' => (int)\$s['ai_reviewed'] === 1, 'blocks' => \$s['view'], 'sources' => json_decode(\$s['sources'], true) ?: []], \$sections),", "'ai_reviewed' => (int)\$s['ai_reviewed'] === 1, 'blocks' => \$s['blocks'], 'sources' => json_decode(\$s['sources'], true) ?: []], \$sections),"],
    ['17-C6 גרסה שאושרה נקראת מה-snapshot', 'report.php', "    if (in_array(\$v['state'], APR_REPORT_FINAL, true)) {\n        if ((int)\$v['stale_after_approval'] === 0", "    if (false) {\n        if ((int)\$v['stale_after_approval'] === 0"],
    ['17-C6 stale אחרי שינוי נתון', 'report.php', "apr_report_live_hash(\$ctx, \$c, \$v) !== \$v['data_hash']) {", "false) {"],
    ['17-C7 הקודמת superseded', 'report.php', "AND state IN ('approved', 'delivered') AND deleted_at IS NULL\", [\$v['report_id'], \$v['id']]) as \$old", "AND state IN ('delivered') AND deleted_at IS NULL\", [\$v['report_id'], \$v['id']]) as \$old"],
    ['§8.1 מצב התיק ← approved', 'report.php', "        if (\$c['status'] !== 'approved') apr_exec(\"UPDATE cases SET status = 'approved'", "        if (false) apr_exec(\"UPDATE cases SET status = 'approved'"],
    ['§8.1 הנימוקים נשמרים', 'report.php', "        foreach (\$noted as \$f) apr_insert('report_findings_ack',", "        foreach ([] as \$f) apr_insert('report_findings_ack',"],
    ['17-C7 גרסה חדשה — מהמאושרת האחרונה', 'report.php', "AND state IN ('approved', 'delivered') ORDER BY number DESC LIMIT 1\", [\$c['id']]) ?? apr_fail('report_state');", "ORDER BY number DESC LIMIT 1\", [\$c['id']]) ?? apr_fail('report_state');"],
    // ── הטריגרים במסד (17-C5, 17-C8) ──
    ['סטייה 7: המסד מסרב לסמן ai לפרק שאינו ברשימה', $SQL, "CHECK (ai_allowed = 0 OR key IN ('property', 'area_desc', 'visit', 'planning'))", "CHECK (ai_allowed IN (0, 1))"],
    ['17-C5 טריגר: גרסה שאושרה קפואה', $SQL, "WHEN OLD.approved_at IS NOT NULL AND (", "WHEN 0 AND OLD.approved_at IS NOT NULL AND ("],
    ['17-C8 טריגר: אין דרך למאושר שלא מ"בבדיקה"', $SQL, "WHEN NEW.state <> OLD.state AND NOT (", "WHEN 0 AND NEW.state <> OLD.state AND NOT ("],
    ['17-C8 טריגר: גרסה נולדת טיוטה', $SQL, "WHEN NEW.state <> 'draft' OR NEW.approved_at IS NOT NULL OR NEW.snapshot IS NOT NULL", "WHEN 0"],
    ['17-C5 טריגר: פרק קפוא כשהגרסה אינה טיוטה', $SQL, "WHEN (SELECT state FROM report_versions WHERE id = OLD.version_id) <> 'draft'", "WHEN 0"],
    ['17-C5 טריגר: אין פרק חדש בגרסה שאינה טיוטה', $SQL, "WHEN (SELECT state FROM report_versions WHERE id = NEW.version_id) <> 'draft'", "WHEN 0"],
    ['17-C5 טריגר: אין מחיקת גרסה', $SQL, "CREATE TRIGGER report_versions_no_delete BEFORE DELETE ON report_versions BEGIN SELECT RAISE(ABORT, 'report_versions: no delete'); END;", ''],
    // ── יצירת הטיוטה (§6) ──
    ['17-A2 / 17-A3 הטריגר לפי הפרופיל של התיק', 'report.php', "    if (!\$has && apr_report_profile(\$c)['trigger'] !== 'visit_completed') return;\n", ''],
    ['17-A6 הטריגר פעמיים ← גרסה אחת', 'report.php', "\$has ? 'report.refresh:' . \$visit['pid'] . ':' . \$visit['rev'] : 'report.draft:' . \$c['pid']", "'report.x:' . apr_pid()"],
    ['§6.2 include_unverified = omit', 'report_build.php', "(\$f['verified'] || (\$tpl['include_unverified'] ?? 'marked') === 'marked')", "true"],
    ['מקרה קצה 8: תמונה שגרסה שאושרה מפנה אליה', 'visit_photos.php', "AND instr(snapshot, ?) > 0 LIMIT 1\"", "AND instr(snapshot, ?) > 999999999 LIMIT 1\""],
];

$orig = [];
// ── סבב 2 (ביקורת 17-report.md): B1–B3 והצהובים. המחרוזות ב-nowdoc — כפי שהן בקוד ──
$R2 = [
    ['B1 ת"ז של 8–9 ספרות, גם לפני נקודה / פסיק', 'report_build.php', <<<'A'
    if (preg_match('/(?<!\d)\d{8,9}(?!\d)/', $text) === 1 || 
A, <<<'B'
    if (
B],
    ['B1 ת"ז עם מקף לפני ספרת הביקורת', 'report_build.php', <<<'A'
 || preg_match('/(?<!\d)\d{7,8}[\-\x{05BE}\x{2010}-\x{2015}]\d(?!\d)/u', $text) === 1) return null;
A, <<<'B'
) return null;
B],
    ['B1 טלפון עם נקודות', 'report_build.php', <<<'A'
\d{1,2}[\s.\-]*\d{3}[\s.\-]*\d{4}(?!\d)/', $flat)
A, <<<'B'
\d{1,2}[\s\-]*\d{3}[\s\-]*\d{4}(?!\d)/', $flat)
B],
    ['B1 כל אסימון של שם גורם / ארגון', 'report_build.php', <<<'A'
        foreach (apr_report_ai_tokens($p) as $t) if (apr_report_ai_word($norm, $t, true)) return null;
A, ''],
    ['B1 אות שימוש לפני שם של גורם', 'report_build.php', <<<'A'
if (apr_report_ai_word($norm, $t, true)) return null;
A, <<<'B'
if (apr_report_ai_word($norm, $t, false)) return null;
B],
    ['B1 ניקוד אינו מסתיר שם', 'report_build.php', <<<'A'
    $text = (string)preg_replace('/[\x{0591}-\x{05BD}\x{05BF}-\x{05C7}\x{200E}\x{200F}\x{202A}-\x{202E}]+/u', '', $text);
A, ''],
    ['B1 מייל ב-[at] / (at)', 'report_build.php', <<<'A'
'/@|[\[({]\s*at\s*[\])}]|
A, <<<'B'
'/@|
B],
    ['B1 / 🟡7 טלפון שמור של גורם', 'report_build.php', <<<'A'
            if (strlen($core) >= 7 && str_contains($digits, $core)) return null;
A, ''],
    // ── סבב 3: טקסט חופשי שאדם הקליד אינו נשלח — בשום קבוצה ובשום מסלול (סריקת הסכימה שבסוף u-report) ──
    ['סבב 3: נתון שהערך שלו טקסט חופשי אינו נשלח', 'report_build.php', <<<'A'
            $free = $facts[$b['attr']]['free'] ?? true;
A, <<<'B'
            $free = false;
B],
    ['סבב 3: "בחירה" שאינה אחת מהאפשרויות היא טקסט', 'report_build.php', <<<'A'
            return $a['value_type'] !== 'choice' || !in_array($f['value_text'], array_column(json_decode((string)($a['choices'] ?? ''), true) ?: [], 'key'), true);
A, <<<'B'
            return $a['value_type'] !== 'choice';
B],
    ['סבב 3: יחידה שאינה מהרשימה הסגורה היא טקסט', 'report_build.php', <<<'A'
            if ($f['unit'] !== null && !isset(APR_REPORT_UNIT_LABELS[$f['unit']])) return true;
A, ''],
    ['סבב 3: תאריך שאינו תאריך הוא טקסט', 'report_build.php', <<<'A'
return preg_match('/^\d{4}-\d{2}-\d{2}\z/', (string)$f['value_date']) !== 1;
A, <<<'B'
return false;
B],
    ['סבב 3: עמודת "ממצא" של בדיקת מקור אינה נשלחת', 'report_build.php', <<<'A'
'source_checks' => [0, 1, 2]];
A, <<<'B'
'source_checks' => [0, 1, 2, 3]];
B],
    ['סבב 3: הכותרת הקבועה של המפתח, לא זו שנשמרה בפרק', 'report_build.php', <<<'A'
    $title = array_column(apr_pc_report_default()['sections'], 'title', 'key')[$section['key']] ?? 'פרק בדוח';
A, <<<'B'
    $title = $section['title'];
B],
    ['סבב 3: פרק שאינו ברשימה הסגורה אינו מרכיב קלט', 'report_build.php', <<<'A'
    if (!in_array($section['key'], APR_REPORT_AI_KEYS, true)) return ['items' => [], 'omitted' => 0, 'kept' => [], 'message' => '', 'hash' => apr_report_hash([])];
A, ''],
    ['סבב 3: פסקת הביקור — מועד בלבד, מסומנת safe', 'report_build.php', <<<'A'
                + (empty($tpl['for_ai']) ? [] : ['safe' => true]);
A, <<<'B'
                ;
B],
    ['סבב 3: שורה שנשאר ממנה רק הסוג אינה נשלחת', 'report_build.php', <<<'A'
                if (count(array_filter($cols, fn($j) => ($row[$j] ?? '—') !== '—')) < min(2, count($cols))) continue;
A, ''],
    ['סבב 3: ת"ז ברווחים', 'report_build.php', <<<'A'
    if (preg_match('/(?<![\d.,])\d(?:[ \x{00A0}]?\d){7,8}(?![\d.,])/u', $text) === 1) return null;
A, <<<'B'
    if (false) return null;
B],
    ['B1 העיקרון: טקסט חופשי של מזהה אינו נשלח', 'report_build.php', <<<'A'
empty($tpl['for_ai']) ? $i['value_text'] : null]);
A, <<<'B'
$i['value_text']]);
B],
    ['B1 נתון שלא נשלח נשאר בפרק כהפניה', 'report_build.php', <<<'A'
            if (!$sent) $kept[] = ['type' => 'fact', 'attr' => $b['attr']];
A, ''],
    // ── סבב 3: B3-ב והצהובים של ביקורת סבב 2 ──
    ['B3-ב "סקרתי" על נתונים שהשתנו ← 409', 'report.php', <<<'A'
        if ($changed && ($in['confirm_changed'] ?? false) !== true) apr_fail('report_ai_stale');
A, ''],
    ['B3-ב האישור המפורש נרשם ביומן', 'report.php', <<<'A'
['key' => $s['key']] + ($changed ? ['confirm_changed' => true] : []));
A, <<<'B'
['key' => $s['key']]);
B],
    ['סבב 3 🟡1: מי שאינו שמאי אינו מלבין הצעת בינה במיזוג', 'report.php', <<<'A'
 || !apr_comp_can_judge($ctx, $c));
A, <<<'B'
);
B],
    ['סבב 3 🟡4: מיזוג של הצעת נתונים אינו מלבין פרק בינה', 'report.php', <<<'A'
                $set = $took ? $aiSet : apr_report_origin_after($ctx, $c, $s, $blocks, $json);
A, <<<'B'
                $set = $took ? $aiSet : ['origin' => 'manual'] + $reset;
B],
    ['סבב 3 🟡4: מיזוג של הצעת בינה מסמן בינה', 'report.php', <<<'A'
                $set = $took ? $aiSet : apr_report_origin_after($ctx, $c, $s, $blocks, $json);
A, <<<'B'
                $set = apr_report_origin_after($ctx, $c, $s, $blocks, $json);
B],
    ['סבב 3 🟡4: קבלת הצעת בינה מאפסת סקירה', 'report.php', <<<'A'
            $aiSet = ['origin' => 'ai', 'ai_run_id' => $p['ai_run_id'], 'ai_input_hash' => $p['ai_input_hash']] + $reset;
A, <<<'B'
            $aiSet = ['origin' => 'ai', 'ai_run_id' => $p['ai_run_id'], 'ai_input_hash' => $p['ai_input_hash']];
B],
    ['סבב 3 🟡4: קבלת הצעת בינה נושאת את נתוני הקלט של ההצעה', 'report.php', <<<'A'
            $aiSet = ['origin' => 'ai', 'ai_run_id' => $p['ai_run_id'], 'ai_input_hash' => $p['ai_input_hash']] + $reset;
A, <<<'B'
            $aiSet = ['origin' => 'ai', 'ai_run_id' => $p['ai_run_id']] + $reset;
B],
    ['סבב 3 🟡4: קבלת הצעת בינה נושאת את הריצה של ההצעה', 'report.php', <<<'A'
            $aiSet = ['origin' => 'ai', 'ai_run_id' => $p['ai_run_id'], 'ai_input_hash' => $p['ai_input_hash']] + $reset;
A, <<<'B'
            $aiSet = ['origin' => 'ai', 'ai_input_hash' => $p['ai_input_hash']] + $reset;
B],
    ['סבב 3 🟡4: הצעה פתוחה קודמת מוחלפת בהצעת הבינה', 'report.php', <<<'A'
    if ($moved) {
        apr_exec("UPDATE section_proposals SET state = 'rejected', decided_at = ? WHERE section_id = ? AND state = 'pending'", [apr_now(), $s['id']]);
A, <<<'B'
    if ($moved) {
B],
    ['סבב 3 🟡3: פרק שנמחק בזמן הקריאה ← נזרק', 'report.php', <<<'A'
    if (!$s || $s['deleted_at'] !== null || $s['version_deleted'] !== null || $s['version_state'] !== 'draft') return 'discarded';
A, <<<'B'
    if (!$s || $s['version_deleted'] !== null || $s['version_state'] !== 'draft') return 'discarded';
B],
    ['סבב 3 🟡3: טקסט אינו נכתב לגרסה מחוקה', 'report.php', <<<'A'
    if (!$s || $s['deleted_at'] !== null || $s['version_deleted'] !== null || $s['version_state'] !== 'draft') return 'discarded';
A, <<<'B'
    if (!$s || $s['deleted_at'] !== null || $s['version_state'] !== 'draft') return 'discarded';
B],
    ['סבב 3 🟡3: גרסה שיצאה מטיוטה בזמן הקריאה ← נזרק', 'report.php', <<<'A'
    if (!$s || $s['deleted_at'] !== null || $s['version_deleted'] !== null || $s['version_state'] !== 'draft') return 'discarded';
A, <<<'B'
    if (!$s || $s['deleted_at'] !== null || $s['version_deleted'] !== null) return 'discarded';
B],
    ['סבב 3 🟡3: התשובה אומרת "נזרק", לא "נוסח"', 'report.php', <<<'A'
    return ['state' => $outcome === 'discarded' ? 'discarded' : $r['state'],
A, <<<'B'
    return ['state' => $r['state'],
B],
    ['סבב 3 🟡3: ב"הכול", גרסה שיצאה מטיוטה עוצרת את הלולאה', 'report.php', <<<'A'
        if (!apr_val("SELECT 1 FROM report_versions WHERE id = ? AND deleted_at IS NULL AND state = 'draft'", [$v['id']])) return;
A, ''],
    ['סבב 3 🟡4: B2 במסלול "הכול" — השורה שלפני הקריאה', 'report.php', <<<'A'
        apr_tx(fn() => apr_report_ai_apply($c, $s, $r, $tpl, $ctx !== null ? (int)$ctx['user']['id'] : null));
A, <<<'B'
        apr_tx(fn() => apr_report_ai_apply($c, apr_row('SELECT * FROM report_sections WHERE id = ?', [$s['id']]), $r, $tpl, $ctx !== null ? (int)$ctx['user']['id'] : null));
B],
    ['סבב 3 🟡3: מזהה הפעולה נושא את ה-rev של הגרסה', 'report.php', <<<'A'
    return 'rd:' . $s['pid'] . ':' . $s['rev'] . ':' . (int)apr_val('SELECT rev FROM report_versions WHERE id = ?', [$s['version_id']]);
A, <<<'B'
    return 'rd:' . $s['pid'] . ':' . $s['rev'];
B],
    ['סבב 3 🟡4: גרסה חדשה נושאת ai_input_hash', 'report.php', <<<'A'
                'auto_hash' => $s['auto_hash'], 'ai_input_hash' => $s['ai_input_hash'], 'updated_by' => $uid, 'updated_at' => $now]);
A, <<<'B'
                'auto_hash' => $s['auto_hash'], 'updated_by' => $uid, 'updated_at' => $now]);
B],
    ['סבב 3 🟡4: ai_stale נעול — הפרופיל והתבנית אינם מורידים אותו', 'report_build.php', <<<'A'
'ai_stale' => ['block', true]
A, <<<'B'
'ai_stale' => ['block', false]
B],
    ['סבב 3 🟡4: data_new נעול', 'report_build.php', <<<'A'
'data_new' => ['block', true]
A, <<<'B'
'data_new' => ['block', false]
B],
    ['סבב 3 🟡4: שותף — טיוטה בלי reopen_reason / rev', 'report.php', <<<'A'
    return $base + ['version' => apr_report_version_head($v, $internal), 'frozen' => false,
A, <<<'B'
    return $base + ['version' => apr_report_version_head($v), 'frozen' => false,
B],
    ['סבב 3 🟡4: שותף — גרסה שאושרה בלי rev', 'report.php', <<<'A'
        return $base + ['version' => apr_report_version_head($v, $internal), 'frozen' => true,
A, <<<'B'
        return $base + ['version' => apr_report_version_head($v), 'frozen' => true,
B],
    ['סבב 3 🟡4: שותף — רשימת הגרסאות בלי reopen_reason / rev', 'report.php', <<<'A'
'versions' => array_map(fn($v) => apr_report_version_head($v, $internal), $rows),
A, <<<'B'
'versions' => array_map(fn($v) => apr_report_version_head($v), $rows),
B],
    ['סבב 3 🟡2: הסינון אינו מוחק מילים מתוך טקסט', 'report_build.php', <<<'A'
    $text = trim((string)preg_replace('/\s{2,}/u', ' ', $text));
    return $text === '' ? null : $text;
A, <<<'B'
    $text = trim((string)preg_replace('/\s{2,}/u', ' ', str_replace(['אביב', 'גיל'], '', $text)));
    return $text === '' ? null : $text;
B],
    ['B2 פרק שהשתנה בזמן הקריאה ← הצעה, לא דריסה', 'report.php', <<<'A'
    if ($moved) {
        apr_exec("UPDATE section_proposals SET state = 'rejected', decided_at = ? WHERE section_id = ? AND state = 'pending'", [apr_now(), $s['id']]);
A, <<<'B'
    if ($moved) { $before = (int)$s['rev']; }
    if (false) {
        apr_exec("UPDATE section_proposals SET state = 'rejected', decided_at = ? WHERE section_id = ? AND state = 'pending'", [apr_now(), $s['id']]);
B],
    ['B2 הצעה שמקורה בבינה נכנסת כטקסט בינה שלא נסקר', 'report.php', <<<'A'
                $set = $fromAi ? $aiSet : ['origin' => 'auto',
A, <<<'B'
                $set = false ? $aiSet : ['origin' => 'auto',
B],
    ['B3 רענון: נתוני הקלט של טקסט בינה השתנו', 'report.php', <<<'A'
apr_report_ai_input($ctx, $c, $s, $tpl)['hash']) !== $s['ai_input_hash']) {
A, <<<'B'
apr_report_ai_input($ctx, $c, $s, $tpl)['hash']) === 'x') {
B],
    ['B3 הסקירה מתבטלת', 'report.php', <<<'A'
                if ((int)$s['ai_reviewed'] === 1) apr_report_update(
A, <<<'B'
                if (false) apr_report_update(
B],
    ['B3 ממצא חוסם ai_stale', 'report_build.php', <<<'A'
!== ($s['ai_input_hash'] ?? null)) $add('ai_stale'
A, <<<'B'
=== 'x') $add('ai_stale'
B],
    ['B3 ai_stale נעול כחוסם', 'report_build.php', <<<'A'
'ai_stale' => ['block', true]
A, <<<'B'
'ai_stale' => ['warn', false]
B],
    ['B3 סקירה מחדש קובעת את הבסיס', 'report.php', <<<'A'
        apr_report_update('report_sections', $s, $rev, ['ai_input_hash' => $now, 'ai_reviewed' => 1,
A, <<<'B'
        apr_report_update('report_sections', $s, $rev, ['ai_reviewed' => 1,
B],
    ['B3 / 17-C6 נתוני הקלט של פרק בינה ב-data_hash', 'report.php', <<<'A'
        if ($s['origin'] === 'ai') $all[$s['key'] . '#ai'] =
A, <<<'B'
        if (false) $all[$s['key'] . '#ai'] =
B],
    ['🟡1 ממצא אחד למפתח', 'report_build.php', <<<'A'
        $out[$kind . ':' . $id] ??= [
A, <<<'B'
        $out[] = [
B],
    ['🟡2 אישור — רק שמאי בתיק', 'report.php', <<<'A'
    if ($need === 'approve' && !apr_report_is_appraiser($ctx, $c)) apr_fail('forbidden');
A, <<<'B'
    if (false) apr_fail('forbidden');
B],
    ['🟡2 perms.approve — רק שמאי בתיק', 'report.php', <<<'A'
!empty($c['perms']['approve_report']) && apr_report_is_appraiser($ctx, $c)];
A, <<<'B'
!empty($c['perms']['approve_report'])];
B],
    ['🟡3 מי שאינו שמאי אינו מוריד את סימון הבינה', 'report.php', <<<'A'
    return ['origin' => 'ai'] + ($json !== $s['content'] ? $reset : []);
A, <<<'B'
    return ($ai ? ['origin' => 'ai'] : ['origin' => 'manual']) + $reset;
B],
    ['🟡3 שמירה של מי שאינו שמאי מבטלת סקירה', 'report.php', <<<'A'
    return ['origin' => 'ai'] + ($json !== $s['content'] ? $reset : []);
A, <<<'B'
    return ['origin' => 'ai'];
B],
    ['🟡3 סימון הפסקה נקבע בשרת', 'report_build.php', <<<'A'
        if ($o['type'] === 'p' && in_array($o['text'], array_column(
A, <<<'B'
        if ($o['type'] === 'p' && ($b['ai'] ?? false) === true && in_array($o['text'], array_column(
B],
    ['🟡3 שמאי ששינה את כל טקסט הבינה ← manual', 'report.php', <<<'A'
    if (apr_comp_can_judge($ctx, $c)) return $ai ? ['origin' => 'ai'] : ['origin' => 'manual'] + $reset;
A, <<<'B'
    if (apr_comp_can_judge($ctx, $c)) return ['origin' => 'ai'];
B],
    ['🟡5 נתון בזמן "בבדיקה" ← data_new', 'report_build.php', <<<'A'
        if ($version['state'] === 'in_review' && $s['build'] === 'data') {
A, <<<'B'
        if (false) {
B],
    ['🟡5 גרסה חדשה: ה-hash של הגרסה הקודמת', 'report.php', <<<'A'
                'auto_hash' => $s['auto_hash'], 'ai_input_hash' => $s['ai_input_hash'], 'updated_by' => $uid, 'updated_at' => $now]);
A, <<<'B'
                'auto_hash' => $s['build'] === 'data' ? apr_report_hash(apr_report_build($c, $s['key'], $tpl)['blocks']) . ':x' : null, 'ai_input_hash' => $s['ai_input_hash'], 'updated_by' => $uid, 'updated_at' => $now]);
B],
    ['🟡6 שותף: בלי reopen_reason / rev', 'report.php', <<<'A'
    return $internal ? $h : array_diff_key($h,
A, <<<'B'
    return true ? $h : array_diff_key($h,
B],
    ['🟡6 שותף: פרק בלי מזהים פנימיים', 'report.php', <<<'A'
    $s['blocks'] = array_map(fn($b) => array_diff_key($b, ['src' => 1, 'fact' => 1, 'refs' => 1, 'was' => 1, 'changed' => 1, 'ai' => 1, 'num_unknown' => 1]), $s['blocks'] ?? []);
A, ''],
    ['🟡7 שותף: טיוטה בלי הערות הבודק', 'report.php', <<<'A'
apr_report_findings_out($c, $v, $sections) : [], 'comments' => $internal ? apr_report_comments($v) : [],
A, <<<'B'
apr_report_findings_out($c, $v, $sections) : [], 'comments' => apr_report_comments($v),
B],
    ['🟡7 שותף: בלי פרטי האישור', 'report.php', <<<'A'
'approval' => $internal ? [
A, <<<'B'
'approval' => true ? [
B],
    ['🟡7 שותף: בלי ספירת הבקרה', 'report.php', <<<'A'
    $open = $internal ? (array_values(
A, <<<'B'
    $open = true ? (array_values(
B],
    ['🟡7 תמונת בלוק שייכת לתיק', 'report_build.php', <<<'A'
WHERE p.pid = ? AND v.case_id = ?', [$b['photo'], $c['id']])
A, <<<'B'
WHERE p.pid = ? AND ? > 0', [$b['photo'], $c['id']])
B],
    ['🟡7 תשובה קטועה (stop_reason) נדחית', 'report_build.php', <<<'A'
 && ($r['stop_reason'] ?? 'end_turn') === 'end_turn' ?
A, <<<'B'
 ?
B],
    ['🟡7 הגבלת קצב ל"ניסוח עכשיו"', 'report.php', <<<'A'
    if (!apr_limit('ai-report:u' . $ctx['user']['id'], ...APR_REPORT_RATE_AI)) apr_fail('ai_rate');
A, ''],
    ['🟡7 הסרת פרק דורשת judge', 'report.php', <<<'A'
function apr_report_section_delete(array $ctx, array $in): array
{
    $c = apr_report_case($ctx, apr_report_section_case($in['section'] ?? null), 'judge');
A, <<<'B'
function apr_report_section_delete(array $ctx, array $in): array
{
    $c = apr_report_case($ctx, apr_report_section_case($in['section'] ?? null), 'edit');
B],
    ['🟡8 הערה על גרסה שאושרה אינה נסגרת', 'report.php', <<<'A'
    if (!in_array(apr_report_version_row($c, $k['version_pid'])['state'], APR_REPORT_OPEN, true)) apr_fail('report_approved');
A, <<<'B'
    if (false) apr_fail('report_approved');
B],
    ['🟡8 ניסוח בבינה על פרק שנערך — רק עם replace', 'report.php', <<<'A'
    if ((int)$s['user_edited'] === 1 && ($in['replace'] ?? false) !== true) apr_fail('report_replace');
A, ''],
    ['🟡9 המסקנה כמחרוזת המנוע', 'report_build.php', <<<'A'
apr_comp_dec_out($a['conclusion_value'], 2)]);
A, <<<'B'
apr_val_money($a['conclusion_value'])['nis']]);
B],
    ['§6.2 מאפיין רב-ערכים נכנס לדוח', 'report_build.php', <<<'A'
        if ((int)$a['multi']) {
A, <<<'B'
        if ((int)$a['multi']) continue;
        if (false) {
B],
    ['🟡7 ממצא: סתירה פתוחה', 'report_build.php', "\$add('conflict_open', \$k['pid'],", "if (false) \$add('conflict_open', \$k['pid'],"],
    ['🟡7 ממצא: משימה פתוחה', 'report_build.php', "\$add('task_open', \$t['pid'],", "if (false) \$add('task_open', \$t['pid'],"],
    ['🟡7 ממצא: דרישת חובה פתוחה', 'report_build.php', "\$add('req_open', \$r['pid'],", "if (false) \$add('req_open', \$r['pid'],"],
    ['🟡7 ממצא: מסמך שנמחק', 'report_build.php', "\$add('doc_gone', \$factPid,", "if (false) \$add('doc_gone', \$factPid,"],
    ['🟡7 ממצא: חלופה בלי מסקנה', 'report_build.php', "if (\$a['conclusion_value'] === null) \$add('alt_no_conclusion',", "if (false) \$add('alt_no_conclusion',"],
    ['🟡7 ממצא: מדגם קטן', 'report_build.php', "if (\$calc['rows'] && count(\$calc['rows']) < \$small) \$add('small_sample',", "if (false) \$add('small_sample',"],
    ['🟡7 ממצא: מקור שלא נבדק', 'report_build.php', "        \$add('source_unchecked', \$k['pid'],", "        if (false) \$add('source_unchecked', \$k['pid'],"],
];
// nowdoc מסתיים בלי שורה חדשה; מוטציה שמסירה שורה שלמה מסירה גם את סוף השורה שלה
foreach ($R2 as $m) $M[] = $m[3] === '' ? [$m[0], $m[1], $m[2] . "\n", ''] : $m;

$restore = function () use (&$orig, $lib) { foreach ($orig as $f => $src) file_put_contents($lib . $f, $src); };
register_shutdown_function($restore);
$only = $argv[1] ?? null;
$bad = 0;
foreach ($M as [$name, $file, $from, $to]) {
    if ($only !== null && !str_contains($name, $only)) continue;
    $src = (string)file_get_contents($lib . $file);
    if (substr_count($src, $from) !== 1) { echo "NOT-APPLIED  $name ($file)\n"; $bad++; continue; }
    $orig[$file] = $src;
    file_put_contents($lib . $file, str_replace($from, $to, $src));
    $out = (string)shell_exec('php ' . escapeshellarg(__DIR__ . '/u-report.test.php') . ' 2>&1');
    file_put_contents($lib . $file, $src);
    unset($orig[$file]);
    // "נתפסה" = בדיקה נפלה (✗). קריסה בלי שום ✗ אינה תפיסה: הקוד נשבר, ולא ידוע איזה קריטריון שומר עליו.
    $crash = (bool)preg_match('/Fatal error|Parse error/', $out);
    $failed = substr_count($out, '✗');
    if ($failed > 0) { echo "killed ($failed" . ($crash ? ', ואז הקובץ נקטע' : '') . ")  $name\n"; continue; }
    $bad++;
    echo ($crash ? 'CRASH        ' : 'SURVIVED     ') . "$name ($file)\n" . ($crash ? '    ' . trim((string)preg_replace('/\s+/', ' ', mb_substr($out, 0, 300))) . "\n" : '');
}
echo $bad ? "✗ $bad מוטציות לא נתפסו\n" : "✓ כל המוטציות נתפסו\n";
exit($bad ? 1 : 0);
