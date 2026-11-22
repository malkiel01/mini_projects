<?php
/**
 * בדיקת מוטציות לפרוסה 16: שובר תנאי אחד בקוד בכל פעם, מריץ את t-valuation.test.php, ומוודא שבדיקה נופלת (✗) — ולא שהקוד קורס.
 * הרצה: php appraisers/tests/mutations-16.php [מחרוזת מהשם]  (כמה דקות). הקובץ שנשבר מוחזר למצבו אחרי כל מוטציה, גם אם ההרצה נקטעת.
 * NOT-APPLIED = המחרוזת של המוטציה כבר אינה בקוד (הקוד השתנה) — לעדכן את המוטציה, אחרת התנאי נשאר בלי בדיקה.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$lib = dirname(__DIR__) . '/lib/';
$M = [
    // ── מנוע הגיליון ──
    ['16-B1 כפל', 'sheet_calc.php', "'mul' => apr_q_mul(\$acc, \$v),", "'mul' => apr_q_add(\$acc, \$v),"],
    ['16-B1 חיסור', 'sheet_calc.php', "'sub' => apr_q_sub(\$acc, \$v),", "'sub' => apr_q_add(\$acc, \$v),"],
    ['16-B1 pct = ÷ 100', 'sheet_calc.php', "return apr_sheet_done(apr_q_div(\$v, apr_q(100)));", "return apr_sheet_done(\$v);"],
    ['16-B1 הקלט במיליוניות', 'sheet_calc.php', "const APR_SHEET_SCALE = '1000000';", "const APR_SHEET_SCALE = '100000';"],
    ['16-B2 קלט ריק אינו אפס', 'sheet_calc.php', "            if (\$r['value'] === null) {\n                \$out[\$key] = ['value' => null, 'reason' => 'empty', 'ref' => \$key, 'warnings' => []];\n            } else {", "            if (false) {\n            } else {"],
    ['16-B2 הסיבה מגיעה עם השורה החסרה', 'sheet_calc.php', "'empty', 'missing' => [null, 'missing', \$r['ref']],", "'empty', 'missing' => [null, 'missing', \$f],"],
    ['16-B3 חלוקה באפס', 'sheet_calc.php', "        if (\$op === 'div' && \$v[0] === '0') return [null, 'div_zero', apr_sheet_is_key(\$a) ? \$a : null];\n", "        if (\$op === 'div' && \$v[0] === '0') { \$acc = apr_q(0); continue; }\n"],
    ['16-B4 הפניה לשורה שאינה מעל', 'sheet_calc.php', "if (apr_sheet_is_key(\$f)) return in_array(\$f, \$above, true) ? [\$f, null] : [null, 'sheet_ref'];", "if (apr_sheet_is_key(\$f)) return [\$f, null];"],
    ['16-B4 op לא מוכר', 'sheet_calc.php', "    if (!in_array(\$f['op'], APR_SHEET_OPS, true)) return [null, 'sheet_op'];\n", ''],
    ['16-B4 טקסט חופשי אינו מספר', 'sheet_calc.php', "if (!is_string(\$v) || preg_match('/^(-?)0*(\\d{1,12})(?:\\.(\\d{1,6}))?\\z/', \$v, \$m) !== 1) return null;", "if (!is_string(\$v) || preg_match('/^(-?)0*(\\d{1,12})(?:\\.(\\d{1,6}))?/', \$v, \$m) !== 1) return null;"],
    ['16-B4 נוסחה היא פעולה', 'sheet_calc.php', "    if (\$depth === 0 && !is_array(\$f)) return [null, 'sheet_formula'];      // נוסחה היא תמיד פעולה — לא מפתח ולא מספר לבדם\n", ''],
    ['§10.5 נוסחה שבורה קודמת', 'sheet_calc.php', "                if (!isset(\$out[\$k])) { \$brokenAt = \$k; break; }\n", ''],
    ['§10.5 לגיליון שבור אין תוצאה', 'sheet_calc.php', "        if ((\$values[\$last]['reason'] ?? null) === 'broken_ref') return ['key' => \$last, 'agorot' => null, 'exact' => null, 'reason' => 'broken', 'ref' => \$last];\n", ''],
    ['עיגול הגיליון — פעם אחת, ליחידה שנבחרה', 'sheet_calc.php', "apr_q_round(apr_q_mul(\$v['value'], apr_q(100)), APR_CALC_ROUNDING[\$rounding])", "apr_q_round(apr_q_mul(\$v['value'], apr_q(100)), 1)"],
    ['מספר ארוך מדי', 'sheet_calc.php', "    if (strlen(\$un) > APR_SHEET_MAX_DIGITS || strlen(\$d) > APR_SHEET_MAX_DIGITS) return null;\n", ''],
    ['§5 יחידות זמן שונות', 'sheet_calc.php', "if (count(\$units) > 1) \$warn[] = 'time_units';", "if (count(\$units) > 2) \$warn[] = 'time_units';"],
    ['§10.9 מעל 100 ביחידת %', 'sheet_calc.php', "\$over = \$meta[\$key]['unit'] === '%' && apr_q_cmp(\$v, apr_q(100)) > 0;", "\$over = false;"],
    ['§10.11 גישה בלי תוצאה', 'sheet_calc.php', "        if (\$it['value'] === null) return ['block' => 'no_result', 'ref' => \$it['pid']] + \$none;\n", "        if (\$it['value'] === null) return \$none;\n"],
    ['§6 גישה בלי משקל', 'sheet_calc.php', "        if (\$it['weight'] === null) return ['block' => 'weight_missing', 'ref' => \$it['pid']] + \$none;\n", "        if (\$it['weight'] === null) continue;\n"],
    ['§6 הממוצע משוקלל', 'sheet_calc.php', "\$acc = apr_q_add(\$acc, apr_q_mul(apr_q(apr_n(\$it['value'])), \$w));", "\$acc = apr_q_add(\$acc, apr_q_mul(apr_q(apr_n(\$it['value'])), apr_q(1)));"],
    // ── חלופות ──
    ['16-A2 חלופת after_works עם הדגל', 'valuation.php', "if ((\$flags['renovation_planned'] ?? false) === true) apr_alt_insert(\$c, APR_ALT_NAME_AFTER,", "if (false) apr_alt_insert(\$c, APR_ALT_NAME_AFTER,"],
    ['16-A2 המועד הקובע של התיק', 'valuation.php', "    apr_alt_insert(\$c, APR_ALT_NAME_EXISTING, \$keyDate, 'existing', null, true, \$uid);", "    apr_alt_insert(\$c, APR_ALT_NAME_EXISTING, null, 'existing', null, true, \$uid);"],
    ['16-A1 הנחה שייכת לחלופה שלה', 'valuation.php', "'alternative_id' => \$a['id'] ?? null,\n                'sort'", "'alternative_id' => null,\n                'sort'"],
    ['16-A3 חסרות הנחות: רק after_works', 'valuation.php', "    if (\$a['state_kind'] === 'after_works') {\n        \$missing = !apr_val(", "    if (\$a['state_kind'] === 'never') {\n        \$missing = !apr_val("],
    ['16-A3 חסרות הנחות: נדרש מסמך', 'valuation.php', "FROM assumptions s JOIN files f ON f.id = s.file_id WHERE s.alternative_id = ? AND s.deleted_at IS NULL AND f.deleted_at IS NULL", "FROM assumptions s LEFT JOIN files f ON f.id = s.file_id WHERE s.alternative_id = ? AND s.deleted_at IS NULL"],
    ['16-A4 מסקנה בלי נימוק', 'valuation.php', "    \$reason = \$value === null ? null : apr_reason(\$in['reason'] ?? null);\n    return apr_tx(function () use (\$ctx, \$c, \$in, \$rev, \$value, \$reason) {\n        \$a = apr_alt_row(", "    \$reason = \$value === null ? null : (apr_clean_text(\$in['reason'] ?? '', 500) ?? 'x');\n    return apr_tx(function () use (\$ctx, \$c, \$in, \$rev, \$value, \$reason) {\n        \$a = apr_alt_row("],
    ['16-A4 תוצאה מוקלדת בלי נימוק', 'valuation.php', "    \$reason = \$source === 'entered' ? apr_reason(\$in['reason'] ?? null) : null;", "    \$reason = \$source === 'entered' ? (apr_clean_text(\$in['reason'] ?? '', 500) ?? 'x') : null;"],
    ['16-A4 גישת ההשוואה אינה מוקלדת', 'valuation.php', "        if (\$source === 'entered' && \$p['kind'] === 'comparison') apr_fail('approach_source');\n", ''],
    ['§6 גישה בלי נימוק', 'valuation.php', "'reasoning' => apr_clean_text(\$in['reasoning'] ?? '', 1000) ?? apr_fail('reason_required'),", "'reasoning' => apr_clean_text(\$in['reasoning'] ?? '', 1000) ?? 'x',"],
    ['§10.3 שם כפול', 'valuation.php', "[\$c['id'], \$key, \$a['id'] ?? 0])) apr_fail('alt_name_taken');", "[\$c['id'], \$key, \$a['id'] ?? 0])) \$key .= ' ';"],
    ['§10.4 החלופה האחרונה אינה נמחקת', 'valuation.php', "        if (!\$next) apr_fail('alt_last');\n", "        if (!\$next) \$next = ['id' => 0, 'pid' => null];\n"],
    ['§10.4 מחיקת הראשית מעבירה את הסימון', 'valuation.php', "        if ((int)\$a['is_primary'] === 1) apr_exec('UPDATE alternatives SET is_primary = 1, rev = rev + 1 WHERE id = ?', [\$next['id']]);\n", ''],
    ['§10.1 מחיקה עם נימוק', 'valuation.php', "    \$reason = apr_reason(\$in['reason'] ?? null);\n    return apr_tx(function () use (\$ctx, \$c, \$in, \$rev, \$reason) {\n        \$a = apr_alt_row(\$c, \$in['alt'] ?? null);\n        \$next", "    \$reason = (string)(\$in['reason'] ?? '');\n    return apr_tx(function () use (\$ctx, \$c, \$in, \$rev, \$reason) {\n        \$a = apr_alt_row(\$c, \$in['alt'] ?? null);\n        \$next"],
    ['27.4 קישור קבוצה לחלופת after_works', 'valuation.php', "        if (\$set && \$a['state_kind'] === 'after_works' && apr_val_set_unreasoned((int)\$set['id'])) apr_fail('alt_condition_unknown');\n", ''],
    ['27.4 חלופה שהופכת ל-after_works', 'valuation.php', "                if (apr_val_set_unreasoned((int)\$p['comp_set_id'])) apr_fail('alt_condition_unknown');\n", ''],
    // ── גיליון: שרת ──
    ['16-B6 תבנית בלי value', 'valuation.php', "return array_map(fn(\$r) => ['key' => \$r['key'], 'kind' => \$r['kind'], 'label' => \$r['label'], 'unit' => \$r['unit'], 'time_unit' => \$r['time_unit'], 'formula' => \$r['formula']], \$rows);", "return array_map(fn(\$r) => ['key' => \$r['key'], 'kind' => \$r['kind'], 'label' => \$r['label'], 'unit' => \$r['unit'], 'time_unit' => \$r['time_unit'], 'formula' => \$r['formula'], 'val' => \$r['value'], 'note' => \$r['reasoning']], \$rows);"],
    ['תבנית אישית — רק לבעליה', 'valuation.php', "WHERE pid = ? AND office_id = ? AND deleted_at IS NULL AND (owner_user_id IS NULL OR owner_user_id = ?)', [\$pid, \$office, \$ctx['user']['id']]", "WHERE pid = ? AND office_id = ? AND deleted_at IS NULL AND (1 = 1 OR owner_user_id = ?)', [\$pid, \$office, \$ctx['user']['id']]"],
    ['תבנית — רק במשרד שלה', 'valuation.php', "WHERE pid = ? AND office_id = ? AND deleted_at IS NULL AND (owner_user_id IS NULL OR owner_user_id = ?)', [\$pid, \$office, \$ctx['user']['id']]", "WHERE pid = ? AND office_id > ? - 100 AND deleted_at IS NULL AND (owner_user_id IS NULL OR owner_user_id = ?)', [\$pid, \$office, \$ctx['user']['id']]"],
    ['רשימת התבניות: אישית רק לבעליה', 'valuation.php', "WHERE t.office_id = ? AND t.deleted_at IS NULL AND (t.owner_user_id IS NULL OR t.owner_user_id = ?) ORDER BY", "WHERE t.office_id = ? AND t.deleted_at IS NULL AND (1 = 1 OR t.owner_user_id = ?) ORDER BY"],
    ['מחיקת תבנית משרדית', 'valuation.php', "if ((int)\$t['created_by'] !== (int)\$ctx['user']['id'] && !(\$t['owner_user_id'] === null && (int)\$office['is_admin'] === 1)) apr_fail('forbidden');", ''],
    ['24.3 ערך בלי מקור (סופק / הוערך / הנחה)', 'valuation.php', "        if (\$row['value'] !== null && \$row['origin'] === null) apr_fail('sheet_origin');", "        if (\$row['value'] !== null && \$row['origin'] === null) \$row['origin'] = 'assumed';"],
    ['§9 נוסחה — רק שמאי בתיק', 'valuation.php', "    if (\$kind !== 'input' && !apr_comp_can_judge(\$ctx, \$c)) apr_fail('forbidden');\n", ''],
    ['סוג השורה אינו משתנה', 'valuation.php', "        if (\$cur !== null && \$cur['kind'] !== \$kind) apr_fail('sheet_kind');\n", ''],
    ['§10.6 שורת חישוב מעל הקלט שלה', 'valuation.php', "if (isset(\$pos[\$ref]) && \$pos[\$ref] >= \$pos[\$key]) apr_fail('sheet_ref_below');", "if (isset(\$pos[\$ref]) && \$pos[\$ref] >= \$pos[\$key]) continue;"],
    ['גיליון שבור אינו נשמר כתבנית', 'valuation.php', "        if (array_filter(\$vals, fn(\$v) => \$v['reason'] === 'broken_ref')) apr_fail('sheet_broken');\n", ''],
    ['גיליון שגישה נשענת עליו', 'valuation.php', "WHERE p.sheet_id = ? AND p.deleted_at IS NULL AND a.deleted_at IS NULL', [\$s['id']])) apr_fail('sheet_in_use');", "WHERE p.sheet_id = ? AND p.deleted_at IS NULL AND a.deleted_at IS NULL', [\$s['id']])) \$s['x'] = 1;"],
    ['16-C4 שמירה רגילה אינה מורידה stale', 'valuation.php', "        if (!\$recheck && in_array(\$id, \$have, true)) continue;\n", ''],
    ['16-C4 שורה מקושרת נרשמת ב-fact_uses', 'valuation.php', "        apr_val_fact_sync(\$c, 'calc', \$s['pid'], apr_sheet_fact_ids((int)\$s['id']));\n        apr_case_touch(\$c);\n        return ['sheet' => \$s['pid'], 'key' => \$key, 'rev' => \$rev + 1];", "        apr_case_touch(\$c);\n        return ['sheet' => \$s['pid'], 'key' => \$key, 'rev' => \$rev + 1];"],
    ['rev: עדכון רק אם לא השתנה', 'valuation.php', "', rev = rev + 1 WHERE id = ? AND rev = ? AND deleted_at IS NULL', [...array_values(\$set), \$row['id'], \$rev]);", "', rev = rev + 1 WHERE id = ? AND ? > 0 AND deleted_at IS NULL', [...array_values(\$set), \$row['id'], \$rev]);"],
    // ── רכישה ושיפוץ ──
    ['16-C2 גרסה חדשה ← סקירה מחדש', 'valuation.php', "        \$review = \$prev ? apr_alt_works_changed(\$c, 'quote_replaced') : 0;", "        \$review = 0;"],
    ['16-C2 משימת סקירה', 'valuation.php', "        if (!apr_val(\"SELECT 1 FROM tasks WHERE case_id = ? AND origin_key = ? AND status IN ('open', 'in_progress')\", [\$c['id'], \$key])) {", "        if (false) {"],
    ['16-C2 משימה אחת פתוחה לכל חלופה', 'valuation.php', "        if (!apr_val(\"SELECT 1 FROM tasks WHERE case_id = ? AND origin_key = ? AND status IN ('open', 'in_progress')\", [\$c['id'], \$key])) {", "        if (true) {"],
    ['16-C2 רק חלופות after_works', 'valuation.php', "WHERE case_id = ? AND deleted_at IS NULL AND state_kind = 'after_works' ORDER BY id\", [\$c['id']]) as \$a) {\n        apr_exec('UPDATE alternatives SET stale = 1", "WHERE case_id = ? AND deleted_at IS NULL ORDER BY id\", [\$c['id']]) as \$a) {\n        apr_exec('UPDATE alternatives SET stale = 1"],
    ['16-C2 להצעה גרסה אחת שמחליפה', 'valuation.php', "            if (apr_val('SELECT 1 FROM renovation_items WHERE supersedes_id = ? AND deleted_at IS NULL', [\$prev['id']])) apr_fail('renovation_superseded');\n", ''],
    ['גרסאות — רק להצעת מחיר', 'valuation.php', "            if (\$kind !== 'quote') apr_fail('renovation_kind');\n", ''],
    ['§10.7 פריט בלי סכום ובלי מסמך', 'valuation.php', "        if (\$row['amount'] === null && \$row['file_id'] === null) apr_fail('renovation_empty');\n", ''],
    ['מחיקת פריט של אחר', 'valuation.php', "        if ((int)\$r['entered_by'] !== (int)\$ctx['user']['id'] && !apr_comp_can_judge(\$ctx, \$c)) apr_fail('forbidden');\n", ''],
    ['§7 שינוי בדגל: רק אחרי הפתיחה', 'valuation.php', "    if (\$was === \$is || \$c['status'] === 'draft') return;", "    if (\$was === \$is) return;"],
    // ── שיתוף, בידוד והרשאות ──
    ['05-B2 פריטי שיפוץ בלי facts', 'valuation.php', "        'renovation' => \$seeFacts ? apr_renovation_out(\$ctx, \$c) : null,", "        'renovation' => apr_renovation_out(\$ctx, \$c),"],
    ['05-B2 רשומת נתון בלי facts', 'valuation.php', "    if (\$factId === null || !apr_can(\$c, 'facts')) return null;", "    if (\$factId === null) return null;"],
    ['05 קובץ שאינו בהיקף', 'valuation.php', "    if (!\$f || !apr_file_visible(\$ctx, \$c, \$f)) return null;\n    return ['pid' => \$f['pid'], 'name'", "    if (!\$f) return null;\n    return ['pid' => \$f['pid'], 'name'"],
    ['05 רשימות הבחירה — רק למי שמזין', 'valuation.php', "    if (empty(apr_comp_perms(\$ctx, \$c)['write'])) return ['facts' => [], 'files' => []];\n", ''],
    ['קישור לקובץ שאינו גלוי', 'valuation.php', "    if (!apr_file_visible(\$ctx, \$c, \$f)) apr_fail('not_found');\n    return (int)\$f['id'];", "    return (int)\$f['id'];"],
    ['15-C3 / 16 §9 שותף בלי see_internal', 'comps.php', "    if (!apr_comp_visible(\$c)) apr_fail('not_found');\n", ''],
    ['§9 judge', 'comps.php', "    if (empty(\$c['perms']['edit']) || (\$need === 'judge' && !apr_comp_can_judge(\$ctx, \$c))) apr_fail('forbidden');", "    if (empty(\$c['perms']['edit'])) apr_fail('forbidden');"],
    ['§9 write', 'comps.php', "    if (empty(\$c['perms']['edit']) || (\$need === 'judge' && !apr_comp_can_judge(\$ctx, \$c))) apr_fail('forbidden');", "    if (\$need === 'judge' && !apr_comp_can_judge(\$ctx, \$c)) apr_fail('forbidden');"],
    ['תיק סגור', 'comps.php', "    apr_case_writable(\$c);\n    return \$c;\n}\n\nfunction apr_comp_perms", "    return \$c;\n}\n\nfunction apr_comp_perms"],
    ['16-C5 גיליון של תיק אחר בגישה', 'valuation.php', "ub.id = s.updated_by WHERE s.pid = ? AND s.case_id = ? AND s.deleted_at IS NULL'", "ub.id = s.updated_by WHERE s.pid = ? AND s.case_id > ? - 1000 AND s.deleted_at IS NULL'"],
    // ── שיוך ומשקל לכל קבוצה ──
    ['16 משקל לכל קבוצה', 'comps.php', "JOIN comp_set_members m ON m.comp_id = k.id AND m.set_id = ? AND m.active = 1 ', APR_COMP_SELECT)", "JOIN comp_set_members m ON m.comp_id = k.id AND m.set_id >= ? - 1000 AND m.active = 1 AND m.set_id = (SELECT MIN(set_id) FROM comp_set_members WHERE comp_id = k.id) ', APR_COMP_SELECT)"],
    ['16 הוצאה מקבוצה', 'comps.php', "AND m.set_id = ? AND m.active = 1 ', APR_COMP_SELECT)", "AND m.set_id = ? ', APR_COMP_SELECT)"],
    ['16 משקל רק לעסקה שבקבוצה', 'comps.php', "        if (!\$m || (int)\$m['active'] !== 1) apr_fail('comp_not_member');\n", "        if (!\$m) apr_fail('comp_not_member');\n"],
    ['16 שיוך רק לעסקה שנבחרה', 'comps.php', "        if (\$on && \$k['status'] !== 'selected') apr_fail('comp_not_selected');\n", ''],
    ['27.4 שיוך בלי נימוק לקבוצת after_works', 'comps.php', "&& \$reason === null && isset(apr_comp_sets_after_works((int)\$c['id'])[(int)\$g['id']])) apr_fail('comp_condition_unknown');", "&& \$reason === null && false) apr_fail('comp_condition_unknown');"],
    ['27.4 עסקה אינה מצטרפת לבד לקבוצת after_works', 'comps.php', "        if (isset(\$after[(int)\$g['id']])) continue;\n", ''],
    ['16 עסקה שנבחרה מצטרפת לקבוצות', 'comps.php', "        if (\$to === 'selected') apr_comp_join_sets(\$c, (int)\$k['id'], (int)\$ctx['user']['id']);\n", ''],
    ['16 rev של הקבוצה בשינוי משקל', 'comps.php', "        if (\$old === \$w) apr_fail('nothing_to_change');\n        apr_comp_set_update(\$ctx, \$c, \$g, \$rev, []);\n", "        if (\$old === \$w) apr_fail('nothing_to_change');\n"],
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
    $out = (string)shell_exec('php ' . escapeshellarg(__DIR__ . '/t-valuation.test.php') . ' 2>&1');
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
