<?php
/**
 * בדיקת מוטציות לפרוסה 05: שובר תנאי אחד בקוד בכל פעם, מריץ את s-shares.test.php, ומוודא שבדיקה נופלת (✗) — ולא שהקוד קורס.
 * הרצה: php appraisers/tests/mutations-05.php  (כמה דקות). הקובץ שנשבר מוחזר למצבו אחרי כל מוטציה, גם אם ההרצה נקטעת.
 * NOT-APPLIED = המחרוזת של המוטציה כבר אינה בקוד (הקוד השתנה) — לעדכן את המוטציה, אחרת התנאי נשאר בלי בדיקה.
 * 05-C2 (התראה לשותף) אינו כאן: אין תנאי אחד שמונע אותה — שותף אינו שורה ב-case_members, ולכן אינו נמען של שום התראה בתיק.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$lib = dirname(__DIR__) . '/lib/';
$M = [
    ['05-A2 הזמנה שלא אושרה', 'shares.php', "AND s.invite_status = 'active' AND (s.expires_at IS NULL OR s.expires_at > ?) AND o.status = 'active'\n            AND ((s.kind", "AND (s.expires_at IS NULL OR s.expires_at > ?) AND o.status = 'active'\n            AND ((s.kind"],
    ['05-C1 ביטול', 'shares.php', "WHERE s.case_id = ? AND s.revoked_at IS NULL AND s.invite_status = 'active'", "WHERE s.case_id = ? AND s.invite_status = 'active'"],
    ['05-B6 תפוגה בכל בקשה', 'shares.php', "AND s.invite_status = 'active' AND (s.expires_at IS NULL OR s.expires_at > ?) AND o.status = 'active'\n            AND ((s.kind", "AND s.invite_status = 'active' AND (1 = 1 OR s.expires_at > ?) AND o.status = 'active'\n            AND ((s.kind"],
    ['§8.3 המשרד הבעלים הושעה', 'shares.php', "(s.expires_at IS NULL OR s.expires_at > ?) AND o.status = 'active'\n            AND ((s.kind", "(s.expires_at IS NULL OR s.expires_at > ?)\n            AND ((s.kind"],
    ['ברירת מחדל סגורה: רק פעולת case', 'shares.php', "!== 'case') return null;", "=== 'never') return null;"],
    ['שיתוף הוא view בלבד', 'cases.php', "        if (\$perm !== 'view') apr_fail('not_found');\n", ''],
    ['05-A5 הרשאה אסורה שנשמרה אינה עוברת', 'shares.php', "foreach (\$allowed as \$k) \$perms[\$k] = (\$stored[\$k] ?? false) === true;", "foreach (array_keys(\$stored) as \$k) \$perms[\$k] = (\$stored[\$k] ?? false) === true;"],
    ['תמיכה: רק מנהל מערכת', 'shares.php', "OR (s.kind = 'support' AND CAST(? AS INTEGER) = 1))\n        ORDER BY", "OR (s.kind = 'support' AND CAST(? AS INTEGER) >= 0))\n        ORDER BY"],
    ['05-A3 פריט מחוץ להיקף', 'shares.php', "'requirement', 'file', 'visit', 'source_check' => \$in(\$area),", "'requirement', 'file', 'visit', 'source_check' => true,"],
    ['05-A3 פריט אחר מאותו סוג', 'shares.php', "if (\$pid === null || \$i['item_pid'] === null || hash_equals((string)\$i['item_pid'], \$pid)) return true;", "return true;"],
    ['05-A3 כרטיס התיק בשיתוף פריטים', 'shares.php', "'details' => \$whole,", "'details' => true,"],
    ['05-A4 download', 'shares.php', "'download' => \$has('download'),", "'download' => true,"],
    ['05-A4 see_internal', 'shares.php', "'internal' => \$has('see_internal'),", "'internal' => true,"],
    ['05-A4 see_draft', 'shares.php', "'report' => \$in('report') && \$has('see_draft'),", "'report' => \$in('report'),"],
    ['15-C3 עסקאות רק עם see_internal', 'shares.php', "'comps' => \$in('comps') && \$has('see_internal'),", "'comps' => \$in('comps'),"],
    ['נתונים: לשותף רק עם see_internal', 'shares.php', "=> !\$ext || (\$whole && \$has('see_internal')),", "=> !\$ext || \$whole,"],
    ['נתונים: לא בשיתוף פריטים', 'shares.php', "=> !\$ext || (\$whole && \$has('see_internal')),", "=> !\$ext || \$has('see_internal'),"],
    ['צוות, פעילות וניהול — פנימי בלבד', 'shares.php', "'team', 'activity', 'manage' => !\$ext,", "'team', 'activity', 'manage' => true,"],
    ['05-A5 הרשאה שאינה ניתנת', 'shares.php', "        if (!in_array(\$k, \$grantable, true)) apr_fail('share_perm');\n", ''],
    ['05-B4 לא מעניקים מה שאין', 'shares.php', "apr_fail('share_perm_exceeds');", "\$perms[\$k] = true;"],
    ['05-B2 מנהל מערכת אינו פותח תמיכה', 'shares.php', "if (\$kind === 'support' && (int)\$ctx['user']['is_sysadmin']) apr_fail('share_sysadmin');", ''],
    ['05-B3 תמיכה: רק פריטים', 'shares.php', "    if (\$kind === 'support' && \$scope !== 'items') apr_fail('share_items_required');\n", ''],
    ['05-B3 בלי פריטים', 'shares.php', "    if (\$scope === 'items' && !\$items) apr_fail('share_items_required');\n", ''],
    ['05-B3 בלי תוקף', 'shares.php', "if (!is_int(\$days) || !in_array(\$days, APR_SHARE_DAYS[\$kind], true)) apr_fail('share_expiry');", "if (!is_int(\$days)) \$days = 0;"],
    ['05-B3 בלי נימוק', 'shares.php', "\$reason = \$kind === 'support' ? apr_reason(\$in['reason'] ?? null) : apr_clean_text(\$in['reason'] ?? '', 500);", "\$reason = apr_clean_text(\$in['reason'] ?? '', 500);"],
    ['אין שיתוף בלי תצוגה מקדימה', 'shares.php', "if (!\$pv || !hash_equals(\$pv['params_hash'], \$k['hash'])) apr_fail('preview_required');", "if (!\$pv) apr_fail('preview_required');"],
    ['תצוגה מקדימה: של אותו משתמש', 'shares.php', "WHERE pid = ? AND case_id = ? AND by_user_id = ? AND confirmed_at IS NULL', [\$in['preview'], \$c['id'], \$ctx['user']['id']]", "WHERE pid = ? AND case_id = ? AND confirmed_at IS NULL', [\$in['preview'], \$c['id']]"],
    ['תצוגה מקדימה: פעם אחת', 'shares.php', "        apr_exec('UPDATE share_previews SET confirmed_at = ? WHERE id = ?', [apr_now(), \$pv['id']]);\n", ''],
    ['תצוגה מקדימה: תוקף', 'shares.php', "        if (\$pv['created_at'] < apr_iso(apr_time() - APR_SHARE_PREVIEW_TTL)) apr_fail('preview_expired');\n", ''],
    ['הזמנה: רק המוזמן מאשר', 'shares.php', "WHERE s.pid = ? AND s.kind = 'cross_office' AND s.user_id = ? AND s.revoked_at IS NULL", "WHERE s.pid = ? AND s.kind = 'cross_office' AND (s.user_id = ? OR 1 = 1) AND s.revoked_at IS NULL"],
    ['נמען: רק חשבון פעיל', 'shares.php', "WHERE lower(email) = ? AND status = 'active'\", [\$email])", "WHERE lower(email) = ?\", [\$email])"],
    ['נמען: הגבלת קצב', 'shares.php', "if (!apr_limit('share-lookup:' . \$ctx['user']['id'], 30, 3600)) apr_fail('share_rate');", ''],
    ['פריט: של התיק הזה', 'shares.php', "' WHERE pid = ? AND case_id = ?', [\$pid, \$c['id']]) ?? apr_fail('share_invalid');", "' WHERE pid = ? AND case_id <> ?', [\$pid, 0]) ?? apr_fail('share_invalid');"],
    ['05-B5 צפיית תמיכה בפריט', 'shares.php', "    \$GLOBALS['apr_support_logged'][(int)\$c['id']] = true;\n    apr_case_audit(\$c, 'support.view', \$type, \$pid,", "    \$GLOBALS['apr_support_logged'][(int)\$c['id']] = true;\n    if (false) apr_case_audit(\$c, 'support.view', \$type, \$pid,"],
    ['05-B5 צפיית תמיכה בבקשה כללית', 'shares.php', "if (empty(\$logged[\$id])) apr_case_audit(\$c, 'support.view', 'case'", "if (false) apr_case_audit(\$c, 'support.view', 'case'"],
    ['05-C1 ביטול נרשם', 'shares.php', "apr_case_audit(\$c, \$s['kind'] === 'support' ? 'support.revoke' : 'share.revoke'", "if (false) apr_case_audit(\$c, \$s['kind'] === 'support' ? 'support.revoke' : 'share.revoke'"],
    ['05-C1 ביטול: רק עם members', 'shares.php', "\$c = apr_case_get(\$ctx, \$casePid ?: null, 'members', true);", "\$c = apr_case_get(\$ctx, \$casePid ?: null, 'view', true);"],
    ['§5 התראה אחת לפני תפוגה', 'shares.php', "if (apr_exec('UPDATE case_shares SET warned_at = ? WHERE id = ? AND warned_at IS NULL', [\$now, \$s['id']]) !== 1) return;", ''],
    ['כרטיס התיק לשותף', 'case_parts.php', "    if (\$c['share'] !== null) return apr_case_view_shared(\$ctx, \$c);", "    if (false) return apr_case_view_shared(\$ctx, \$c);"],
    ['פעילות — פנימי בלבד', 'access.php', "'cases' => 'case', 'case-activity' => 'member',", "'cases' => 'case', 'case-activity' => 'case',"],
    ['05-A4 הורדה דורשת download', 'documents.php', "    if (!\$inline && !apr_can(\$c, 'download')) apr_fail('forbidden');\n", ''],
    ['05-A3 קובץ: ההכרעה לפריט', 'documents.php', "    return apr_can(\$c, 'file', \$pid);", "    return true;"],
    ['05-A3 רשימת הדרישות', 'documents.php', "        if (!apr_can(\$c, 'requirement', \$r['pid'])) continue;\n        foreach (\$links", "        foreach (\$links"],
    ['05-A3 רשימת הקבצים', 'documents.php', "    foreach (\$files as \$id => \$f) if (!apr_file_visible(\$ctx, \$c, \$f)) unset(\$files[\$id]);\n", ''],
    ['05-A4 הערות פנימיות ברשימה', 'documents.php', "        if (!\$internal) {\n            \$r = ['explain_internal' => ''", "        if (false) {\n            \$r = ['explain_internal' => ''"],
    ['קבוצות הפרופיל אינן לשותף', 'documents.php', "'groups' => \$ext ? [] : apr_case_version_content(", "'groups' => false ? [] : apr_case_version_content("],
    ['05-C3 חיפוש: קובץ שלא שותף', 'documents.php', "if (!(\$c = \$case(\$f['case_pid'])) || !apr_file_visible(\$ctx, \$c, ['id' => (int)\$f['id'], 'pid' => \$f['pid'], 'case_id' => (int)\$c['id']])) continue;", "if (!(\$c = \$case(\$f['case_pid']))) continue;"],
    ['05-C3 חיפוש: דרישה שלא שותפה', 'documents.php', "if (!(\$c = \$case(\$r['case_pid'])) || !apr_can(\$c, 'requirement', \$r['pid'])) continue;", "if (!(\$c = \$case(\$r['case_pid']))) continue;"],
    ['05-C3 חיפוש: טקסט של קובץ שלא שותף', 'extract.php', "if (!\$c || !apr_file_visible(\$ctx, \$c, ['id' => (int)\$r['id'], 'pid' => \$r['pid'], 'case_id' => (int)\$r['case_id']])) continue;", "if (!\$c) continue;"],
    ['תמונת ביקור פרטית אינה לשותף', 'visit_photos.php', "return \$p['usage'] !== 'private' && is_string(\$p['visit_pid'] ?? null)", "return is_string(\$p['visit_pid'] ?? null)"],
    ['תמונת ביקור: רק של ביקור ששותף', 'visit_photos.php', "&& apr_can(\$c, 'visit', \$p['visit_pid']);", "&& true;"],
    ['ביקור: רק ביקור ששותף', 'visits.php', "    apr_can_or_404(\$c, 'visit', \$v['pid']);", ''],
    ['ביקור: בלי נתוני התיק', 'visits.php', "foreach (!apr_can(\$c, 'facts') ? [] : apr_rows(", "foreach (false ? [] : apr_rows("],
    ['מקורות: רק בדיקה ששותפה (רשימה)', 'sources.php', "as \$k) if (apr_can(\$c, 'source_check', \$k['pid'])) \$by[(int)\$k['source_id']][] = \$k;", "as \$k) \$by[(int)\$k['source_id']][] = \$k;"],
    ['מקורות: רק בדיקה ששותפה (בדיקה)', 'sources.php', "    apr_can_or_404(\$c, 'source_check', \$k['pid']);\n", ''],
    ['מקורות: בלי התכנון', 'sources.php', "'planning' => apr_can(\$c, 'planning') ? apr_planning_summary((int)\$c['id']) : null];", "'planning' => apr_planning_summary((int)\$c['id'])];"],
    ['נתונים: השער', 'facts.php', "    apr_can_or_404(\$c, 'facts');", ''],
    ['עסקאות: בלי נתוני התיק', 'comps.php', "'subject_areas' => apr_can(\$c, 'facts') ? array_map(", "'subject_areas' => true ? array_map("],
    ['11 §12.7 בינה: שותף אינו מפעיל', 'ai.php', "if (\$case !== null && ((int)\$case['office_id'] !== \$office || (\$case['share'] ?? null) !== null)) return \$block('no_assignment', ['case_id' => null]);", ''],
    ['בינה: החסימה בלי מזהה התיק של המשרד האחר', 'ai.php', "return \$block('no_assignment', ['case_id' => null]);", "return \$block('no_assignment');"],
    // ── ביקורת סבב 1 ──
    ['B1 ביקור: בלי איש הקשר לגישה', 'visits.php', "    \$out['access_contact'] = null;\n", ''],
    ['B1 ביקור: הערת גישה ונוכחים רק עם internal', 'visits.php', "if (!apr_can(\$c, 'internal')) \$out = ['access_note' => null", "if (false) \$out = ['access_note' => null"],
    ['B1 ביקור: מסך הביקור', 'visits.php', "'visit' => apr_visit_out_for(\$c, \$v) + ['snapshot'", "'visit' => apr_visit_out(\$v) + ['snapshot'"],
    ['B1 ביקור: הרשימה', 'visits.php', "apr_visit_out_for(\$c, \$v) + ['can_fill' => false], \$mine)", "apr_visit_out(\$v) + ['can_fill' => false], \$mine)"],
    ['ביקור: others רק ביקור ששותף', 'visits.php', "fn(\$o) => apr_can(\$c, 'visit', \$o['pid'])", "fn(\$o) => true"],
    ['B2 עסקאות: רשומת הנתון הנישום', 'comps.php', "\$f['deleted_at'] === null && apr_can(\$c, 'facts') ? apr_fact_out(", "\$f['deleted_at'] === null ? apr_fact_out("],
    ['B2 עסקאות: דומה / שונה', 'comps.php', "'similar' => apr_can(\$c, 'facts') ? apr_comp_similar(", "'similar' => true ? apr_comp_similar("],
    ['B2 עסקאות: נתוני השטח בקבוצה', 'comps.php', "'subject_areas' => !apr_can(\$c, 'facts') ? [] : array_map(", "'subject_areas' => false ? [] : array_map("],
    ['B3 מי העלה והערת המקור', 'documents.php', "if (!\$internal) foreach (\$files as \$id => \$f) \$files[\$id] = ['uploader_name' => null", "if (false) foreach (\$files as \$id => \$f) \$files[\$id] = ['uploader_name' => null"],
    ['הגורם של הדרישה אינו לשותף', 'documents.php', "        if (\$ext) {\n            \$r = ['party_pid' => null", "        if (false) {\n            \$r = ['party_pid' => null"],
    ['קובץ בלי מזהה אינו נראה לשותף', 'documents.php', "    if (\$pid === null && (\$c['share'] ?? null) !== null) return false;\n", ''],
    ['🟡1 צפיית תמיכה לכל תיק', 'shares.php', "foreach (\$cases as \$id => \$c) if (empty(\$logged[\$id]))", "foreach (array_slice(\$cases, 0, 1, true) as \$id => \$c) if (empty(\$logged[\$id]))"],
    ['sys-support: רק שורות תמיכה', 'shares.php', "\" AND s.kind = 'support' AND s.invite_status = 'active' ORDER BY s.expires_at", "\" AND s.invite_status = 'active' ORDER BY s.expires_at"],
    ['שיתוף בלי view', 'shares.php', "    if (!\$perms['view']) return null;\n", ''],
    ['הזמנה שפגה אינה מתאשרת', 'shares.php', "AND s.invite_status = 'pending' AND (s.expires_at IS NULL OR s.expires_at > ?)", "AND s.invite_status = 'pending' AND (1 = 1 OR s.expires_at > ?)"],
    ['בדיקת מקור: בלי הנתונים', 'sources.php', "'facts' => !apr_can(\$c, 'facts') ? [] : apr_rows(", "'facts' => false ? [] : apr_rows("],
    ['בדיקת מקור: בלי פריטי התכנון', 'sources.php', "'items' => !apr_can(\$c, 'planning') ? [] : array_map(", "'items' => false ? [] : array_map("],
    ['בדיקת מקור: בלי המשימות', 'sources.php', "'tasks' => !apr_can(\$c, 'tasks') ? [] : array_map(", "'tasks' => false ? [] : array_map("],
    ['🟡4 סתירות אינן לשותף', 'facts.php', "\$seeConf = \$c['share'] === null;", "\$seeConf = true;"],
    ['🟡4 היסטוריית נתון אינה לשותף', 'facts.php', "'history' => \$c['share'] !== null ? [] : array_map(", "'history' => false ? [] : array_map("],
    ['🟡2 הרעבת חיפוש: טקסט', 'extract.php', "ORDER BY f.id DESC, t.page LIMIT 5000\"", "ORDER BY f.id DESC, t.page LIMIT 300\""],
    ['🟡2 הרעבת חיפוש: דרישות', 'documents.php', "ORDER BY r.id DESC LIMIT 5000\"", "ORDER BY r.id DESC LIMIT 200\""],
    ['פנימי לפני 05: ברירות המחדל', 'cases.php', "return \$stored + array_fill_keys(APR_CASE_PERMS_05, true) + array_fill_keys(APR_CASE_PERMS, false);", "return \$stored + array_fill_keys(APR_CASE_PERMS, false);"],
    ['פנימי לפני 05: עדכון הרשאה אחרת', 'cases.php', "        \$perms = apr_case_perms_full(json_decode(\$m['perms'], true) ?: []);\n        if (array_key_exists('role', \$in)) {", "        \$perms = json_decode(\$m['perms'], true) ?: [];\n        if (array_key_exists('role', \$in)) {"],
    ['פנימי בלי see_internal: הערות פנימיות בכרטיס', 'case_parts.php', "(apr_can(\$c, 'internal') ? [] : ['explain_internal' => ''])", "(true ? [] : ['explain_internal' => ''])"],
    ['פנימי בלי see_internal: שורות comp.* ביומן', 'case_parts.php', " && (\$seeComps || !str_starts_with(\$r['action'], 'comp.'))", ''],
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
    $out = (string)shell_exec('php ' . escapeshellarg(__DIR__ . '/s-shares.test.php') . ' 2>&1');
    file_put_contents($lib . $file, $src);
    unset($orig[$file]);
    // "נתפסה" = בדיקה נפלה (✗). קריסה בלי שום ✗ אינה תפיסה: הקוד נשבר, ולא ידוע איזה קריטריון שומר עליו.
    // ✗ ואחריו קריסה — נתפסה: הבדיקה שנפלה מזהה את הקריטריון, וההמשך של הקובץ נשען על מצב שכבר אינו קיים.
    $crash = (bool)preg_match('/Fatal error|Parse error/', $out);
    $failed = substr_count($out, '✗');
    if ($failed > 0) { echo "killed ($failed" . ($crash ? ', ואז הקובץ נקטע' : '') . ")  $name\n"; continue; }
    $bad++;
    echo ($crash ? 'CRASH        ' : 'SURVIVED     ') . "$name ($file)\n" . ($crash ? '    ' . trim((string)preg_replace('/\s+/', ' ', mb_substr($out, 0, 300))) . "\n" : '');
}
echo $bad ? "✗ $bad מוטציות לא נתפסו\n" : "✓ כל המוטציות נתפסו\n";
exit($bad ? 1 : 0);
