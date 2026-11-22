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
    ['שותף: מאושרת — בלי מקורות', 'report.php', ": array_diff_key(\$s, ['sources' => 1]), \$snap['sections'] ?? []),", ": \$s, \$snap['sections'] ?? []),"],
    ['שותף: טיוטה — בלי ממצאים והערות', 'report.php', "'findings' => \$internal ? apr_report_findings_out(\$c, \$v, \$sections) : [],", "'findings' => apr_report_findings_out(\$c, \$v, \$sections),"],
    ['שותף: פרק בלי שדות העריכה', 'report.php', "    if (!\$internal) return \$o;\n", ''],
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
    ['סטייה 7: שם של גורם מושמט', 'report_build.php', "        if (mb_stripos(\$text, \$p) !== false) return null;\n", ''],
    ['סטייה 7: טלפון מושמט', 'report_build.php', "\\d{4}(?!\\d)/', \$text) === 1) return null;", "\\d{4}(?!\\d)/', \$text) === 2) return null;"],
    ['סטייה 7: ת"ז מושמטת', 'report_build.php', "\\d{9}(?![\\d.,])/', \$text) === 1) return null;", "\\d{9}(?![\\d.,])/', \$text) === 2) return null;"],
    ['סטייה 7: מייל מושמט', 'report_build.php', "[\\w.\\-]+/u', \$text) === 1) return null;", "[\\w.\\-]+/u', \$text) === 2) return null;"],
    ['סטייה 7: שם המבקר נמחק', 'report_build.php', "    foreach (\$pii['staff'] as \$n) {", "    foreach ([] as \$n) {"],
    ['סטייה 7: הקלט עובר ניקוי', 'report_build.php', "        \$text = apr_report_ai_scrub(\$text, \$pii);\n        if (\$text === null) { \$omitted++; return; }\n", ''],
    ['17-D1 src שלא נשלח ← נזרק', 'report_build.php', " || \$src === null || !isset(\$items[\$src])) { \$dropped++; continue; }", " || \$src === null) { \$dropped++; continue; }"],
    ['17-D1 מספר שאינו בנתונים מסומן', 'report_build.php', " + (\$unknown ? ['num_unknown' => \$unknown] : []);", " + [];"],
    ['§6.2 טקסט בינה נכנס "לא נסקר"', 'report.php', "'origin' => 'ai', 'ai_run_id' => \$r['run'], 'ai_reviewed' => 0,", "'origin' => 'ai', 'ai_run_id' => \$r['run'], 'ai_reviewed' => 1,"],
    ['ניסוח בבינה אינו מעלים "לא ידוע"', 'report.php', "in_array(\$b['type'], ['data_table', 'image', 'note_missing'], true)", "in_array(\$b['type'], ['data_table', 'image'], true)"],
    ['17-D2 רק נתוני הפרק', 'report_build.php', "    \$built = apr_report_resolve(\$ctx, \$c, apr_report_build(\$c, \$section['key'], \$tpl)['blocks'], \$tpl);\n    \$items = [];", "    \$built = apr_report_resolve(\$ctx, \$c, [...apr_report_build(\$c, \$section['key'], \$tpl)['blocks'], ...apr_report_build(\$c, 'property', \$tpl)['blocks']], \$tpl);\n    \$items = [];"],
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
