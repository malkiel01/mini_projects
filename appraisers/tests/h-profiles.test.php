<?php
// פרוסה 03 — פרופילים (docs/spec/03-profiles.md §11): 03-A1 … 03-D2, ומקרי הקצה.
require __DIR__ . '/lib.php';

$owner = ident('1', 'הבעלים', 'owner@example.com', ['platform_admin' => true]);   // מנהל המערכת
$avi = ident('2', 'אבי', 'avi@example.com');       // מנהל משרד א
$dana = ident('3', 'דנה', 'dana@example.com');     // חברה במשרד א
$boaz = ident('4', 'בועז', 'boaz@example.com');    // מנהל משרד ב
$A = office_for($avi, 'משרד א');
$B = office_for($boaz, 'משרד ב');
$danaPid = join_office($avi, $dana, 'dana@example.com', 'appraiser');
$counts = fn() => array_map(fn($t) => (int)apr_val("SELECT COUNT(*) FROM $t"), array_combine($ts = array_column(apr_rows("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'dev_%' AND name NOT LIKE 'devloop%'"), 'name'), $ts));

/** דרישה תקינה לבדיקות. */
function req(string $key, array $more = []): array
{
    return $more + ['key' => $key, 'group' => 'identity', 'doc_type' => 'rights', 'title' => "דרישה $key", 'explain_recipient' => '', 'priority' => 'normal', 'mandatory' => false,
        'party_kind' => 'client', 'due' => ['mode' => 'relative', 'days' => 3], 'needs_review' => true, 'when' => null, 'sort' => 1];
}
function content(array $more = []): array
{
    return $more + ['schema' => 1, 'flags' => [['key' => 'renovation_planned', 'title' => 'שיפוץ', 'question' => 'מתוכנן שיפוץ?']],
        'requirements' => ['groups' => [['key' => 'identity', 'title' => 'זהות וזכויות', 'sort' => 1]], 'items' => [req('rights')]],
        'tasks' => ['items' => [['key' => 'check_rights', 'title' => 'בדיקת זכויות', 'assignee' => 'responsible', 'due' => ['mode' => 'relative', 'days' => 5], 'when' => null, 'sort' => 1]]],
        'rules' => [['key' => 'renovation_quote', 'title' => 'שיפוץ מתוכנן — פירוט עבודות',
            'when' => ['all' => [['field' => 'flags.renovation_planned', 'op' => 'is', 'value' => true]]],
            'then' => [['do' => 'add_requirement', 'item' => req('works_quote', ['doc_type' => 'works_quote', 'title' => 'פירוט עבודות'])], ['do' => 'alert', 'text' => 'לבדוק היתר לשיפוץ']]]]];
}
/** ה-revision של הטיוטה (אסימון השמירה) — 0 כשאין טיוטה. */
function rev(string $pid): int
{
    return (int)apr_val("SELECT v.revision FROM profile_versions v JOIN profiles p ON p.id = v.profile_id WHERE p.pid = ? AND v.state = 'draft'", [$pid]);
}
/** יוצר פרופיל, שומר תוכן ומפרסם. מחזיר pid. */
function make_profile(string $as, string $scope, string $name, ?array $content = null, bool $publish = true): string
{
    [$st, $r] = call('POST', 'profile-create', ['scope' => $scope, 'name' => $name], $as);
    if ($st !== 200) throw new RuntimeException('profile-create: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
    [, $d] = call('POST', 'profile-draft-open', ['profile' => $r['profile']], $as);
    [$st, $s] = call('POST', 'profile-draft-save', ['profile' => $r['profile'], 'content' => $content ?? content(), 'revision' => $d['draft']['revision']], $as);
    if ($st !== 200) throw new RuntimeException('profile-draft-save: ' . json_encode($s, JSON_UNESCAPED_UNICODE));
    if ($publish && ($x = call('POST', 'profile-publish', ['profile' => $r['profile'], 'revision' => rev($r['profile']), 'note' => 'ראשונה'], $as))[0] !== 200) throw new RuntimeException('publish: ' . json_encode($x, JSON_UNESCAPED_UNICODE));
    return $r['profile'];
}
/** שומר תוכן חדש לטיוטה (פותח אותה אם צריך). */
function save_draft(string $as, string $pid, array $content): array
{
    [, $d] = call('POST', 'profile-draft-open', ['profile' => $pid], $as);
    return call('POST', 'profile-draft-save', ['profile' => $pid, 'content' => $content, 'revision' => $d['draft']['revision'] ?? ''], $as);
}

// ── הספרייה והדוגמאות ──
[$st, $lib] = call('GET', 'profiles', [], $dana);
eq([$st, count($lib['recommended']), array_unique(array_column($lib['recommended'], 'is_sample')), array_unique(array_column($lib['recommended'], 'scope'))], [200, 4, [true], ['recommended']], '§1.8 ארבעה פרופילים מומלצים, מסומנים "דוגמה"');
call('GET', 'profiles', [], $avi);
eq((int)apr_val("SELECT COUNT(*) FROM profiles WHERE scope = 'recommended'"), 4, 'הדוגמאות נזרעות פעם אחת');
$recPid = $lib['recommended'][0]['pid'];
$none = ident('9', 'בלי משרד', 'none@example.com');
eq([count(call('GET', 'profiles', [], $none)[1]['recommended']), call('GET', 'profile', [], $none, ['profile' => $recPid])[0]], [4, 200], '§9 צפייה במומלץ — כל משתמש פעיל, גם בלי משרד');
foreach ($lib['recommended'] as $r) {
    [, $v] = call('GET', 'profile', [], $dana, ['profile' => $r['pid']]);
    eq(apr_profile_checks($v['content'], apr_doc_type_keys())['errors'], [], "הדוגמה \"{$r['name']}\" עוברת את בדיקות הפרסום");
}
[, $lists] = call('GET', 'profile-lists', [], $dana);
check('§4.3 סוגי המסמך נזרעו, והרשימות הסגורות מגיעות לעורך', count($lists['doc_types']) === 11 && $lists['party_kinds'] === APR_PARTY_KINDS && $lists['limits']['requirements'] === 300);

// ── 03-A1: מי שאינו מנהל המערכת לא יוצר, עורך, מפרסם או מארכב מומלץ — כל פעולה בנפרד ──
$recMine = make_profile($owner, 'recommended', 'מומלץ לבדיקה');
call('POST', 'profile-draft-open', ['profile' => $recMine], $owner);   // טיוטה פתוחה — כדי שהדחייה תהיה על ההרשאה
$draftAt = fn(string $pid) => apr_row("SELECT v.updated_at, v.content FROM profile_versions v JOIN profiles p ON p.id = v.profile_id WHERE p.pid = ? AND v.state = 'draft'", [$pid]);
foreach (['מנהל משרד' => $avi, 'חברה במשרד' => $dana, 'בלי משרד' => $none] as $who => $as) {
    $before = [apr_row('SELECT * FROM profiles WHERE pid = ?', [$recMine]), $draftAt($recMine), (int)apr_val('SELECT COUNT(*) FROM profiles'), (int)apr_val('SELECT COUNT(*) FROM profile_versions')];
    eq(code(call('POST', 'profile-create', ['scope' => 'recommended', 'name' => 'x'], $as)), 'forbidden', "03-A1 $who: יצירת מומלץ → 403");
    eq(code(call('POST', 'profile-update', ['profile' => $recMine, 'name' => 'שם אחר'], $as)), 'forbidden', "03-A1 $who: עריכת פרטי מומלץ → 403");
    eq(code(call('POST', 'profile-draft-open', ['profile' => $recMine], $as)), 'forbidden', "03-A1 $who: פתיחת טיוטה של מומלץ → 403");
    eq(code(call('POST', 'profile-draft-save', ['profile' => $recMine, 'content' => content(), 'revision' => rev($recMine)], $as)), 'forbidden', "03-A1 $who: שמירת טיוטה של מומלץ → 403");
    eq(code(call('POST', 'profile-publish', ['profile' => $recMine, 'revision' => rev($recMine)], $as)), 'forbidden', "03-A1 $who: פרסום מומלץ → 403");
    eq(code(call('POST', 'profile-archive', ['profile' => $recMine], $as)), 'forbidden', "03-A1 $who: ארכוב מומלץ → 403");
    eq(code(call('POST', 'profile-delete', ['profile' => $recMine], $as)), 'forbidden', "03-A1 $who: מחיקת מומלץ → 403");
    eq(code(call('POST', 'profile-share', ['profile' => $recMine, 'email' => 'boaz@example.com', 'can' => 'edit'], $as)), 'forbidden', "03-A1 $who: שיתוף מומלץ → 403");
    eq(code(call('GET', 'sys-doc-types', [], $as)), 'not_found', "§9 $who: סוגי מסמך — רק מנהל המערכת");
    eq([apr_row('SELECT * FROM profiles WHERE pid = ?', [$recMine]), $draftAt($recMine), (int)apr_val('SELECT COUNT(*) FROM profiles'), (int)apr_val('SELECT COUNT(*) FROM profile_versions')], $before, "03-A1 $who: שום דבר לא השתנה");
}
eq([call('POST', 'profile-update', ['profile' => $recMine, 'name' => 'מומלץ לבדיקה 2', 'purposes' => ['purchase']], $owner)[0], call('GET', 'profile', [], $dana, ['profile' => $recMine])[1]['profile']['name']], [200, 'מומלץ לבדיקה 2'], '03-A1 מנהל המערכת עורך');
eq(code(call('POST', 'profile-share', ['profile' => $recMine, 'email' => 'boaz@example.com', 'can' => 'view'], $owner)), 'profile_state', '§6 פרופיל מומלץ אינו משותף');

// ── 03-A2: שכפול מומלץ → אישי, מנותק מהמקור ──
[$st, $dup] = call('POST', 'profile-duplicate', ['profile' => $recMine], $dana);
$copy = $dup['profile'] ?? '';
$row = apr_row('SELECT * FROM profiles WHERE pid = ?', [$copy]);
$srcV1 = (int)apr_val("SELECT v.id FROM profile_versions v JOIN profiles p ON p.id = v.profile_id WHERE p.pid = ? AND v.number = 1", [$recMine]);
eq([$st, $row['scope'], (int)$row['source_version_id'], $row['status'], (int)$row['owner_user_id'] > 0], [200, 'personal', $srcV1, 'draft', true], '03-A2 שכפול מומלץ → פרופיל אישי עם source_version_id');
[, $cv] = call('GET', 'profile', [], $dana, ['profile' => $copy]);
eq([$cv['profile']['source'], $cv['profile']['can']['manage']], [['name' => 'מומלץ לבדיקה 2', 'number' => 1, 'archived' => false, 'newer' => false], true], '§5 "שוכפל מ: <שם> גרסה 1", והעותק שלה');
$srcContent = fn() => apr_val('SELECT content FROM profile_versions WHERE id = ?', [$srcV1]);
$src0 = $srcContent();
eq(save_draft($dana, $copy, content(['tasks' => ['items' => []]]))[0], 200, 'עורכים את העותק');
eq(call('POST', 'profile-publish', ['profile' => $copy, 'revision' => rev($copy)], $dana)[0], 200, 'ומפרסמים אותו');
eq([$srcContent(), call('GET', 'profile', [], $owner, ['profile' => $recMine])[1]['content']['tasks']['items'][0]['key']], [$src0, 'check_rights'], '03-A2 עריכת העותק אינה משנה את המקור');
$copyContent = fn() => call('GET', 'profile', [], $dana, ['profile' => $copy])[1]['content'];
$c0 = $copyContent();
save_draft($owner, $recMine, content(['requirements' => ['groups' => [['key' => 'identity', 'title' => 'ז', 'sort' => 1]], 'items' => [req('new_in_source')]]]));
eq(call('POST', 'profile-publish', ['profile' => $recMine, 'revision' => rev($recMine), 'note' => 'שנייה'], $owner)[1]['number'] ?? null, 2, 'המקור מפרסם גרסה 2');
eq([$copyContent(), call('GET', 'profile', [], $dana, ['profile' => $copy])[1]['profile']['source']['newer']], [$c0, true], '03-A2 פרסום במקור אינו משנה את העותק — רק "למקור יש גרסה חדשה"');
call('POST', 'profile-archive', ['profile' => $recMine], $owner);
eq(call('GET', 'profile', [], $dana, ['profile' => $copy])[1]['profile']['source']['archived'], true, 'מקרה 4: המקור אורכב — העותק לא מושפע, "(בארכיון)"');
call('POST', 'profile-archive', ['profile' => $recMine, 'archived' => false], $owner);

// ── 03-A4: מספר עוקב, current_version_id, וטיוטה חדשה כהעתק ──
$P = make_profile($avi, 'office', 'פרופיל משרדי');
$prow = fn(string $pid) => apr_row('SELECT * FROM profiles WHERE pid = ?', [$pid]);
$v1 = apr_row('SELECT * FROM profile_versions WHERE profile_id = ? AND number = 1', [$prow($P)['id']]);
eq([(int)$prow($P)['current_version_id'], $prow($P)['status'], $v1['state'], apr_profile_draft_row((int)$prow($P)['id'])], [(int)$v1['id'], 'active', 'published', null], '03-A4 פרסום ראשון: number = 1, current_version_id מצביע עליו, ואין טיוטה');
[$st, $d] = call('POST', 'profile-draft-open', ['profile' => $P], $avi);
eq([$st, $d['draft']['number'], $d['draft']['state'], $d['content']], [200, null, 'draft', apr_profile_content_read($v1['content'])], '03-A4 טיוטה חדשה מתחילה כהעתק של הגרסה האחרונה');
eq(call('POST', 'profile-draft-open', ['profile' => $P], $avi)[1]['draft']['pid'], $d['draft']['pid'], '§3 לכל פרופיל טיוטה אחת לכל היותר');
save_draft($avi, $P, content(['tasks' => ['items' => []]]));
[$st, $pub] = call('POST', 'profile-publish', ['profile' => $P, 'revision' => rev($P), 'note' => 'בלי משימות'], $avi);
$v2 = apr_row('SELECT * FROM profile_versions WHERE profile_id = ? AND number = 2', [$prow($P)['id']]);
eq([$st, $pub['number'], (int)$prow($P)['current_version_id'], $v2['change_note'], $pub['version']], [200, 2, (int)$v2['id'], 'בלי משימות', $v2['pid']], '03-A4 פרסום שני: number = 2, והמצביע עבר אליו');
eq(code(call('POST', 'profile-publish', ['profile' => $P, 'revision' => rev($P)], $avi)), 'not_found', 'בלי טיוטה אין מה לפרסם');
[, $hist] = call('GET', 'profile-versions', [], $dana, ['profile' => $P]);
eq([array_column($hist['versions'], 'number'), $hist['versions'][0]['published_by'], $hist['versions'][0]['change_note']], [[2, 1], 'אבי', 'בלי משימות'], '§7 היסטוריה: מספר, מי פרסם, והערה');
[, $cmp] = call('GET', 'profile-compare', [], $dana, ['profile' => $P, 'a' => $v1['pid'], 'b' => $v2['pid']]);
eq([$cmp['diff']['tasks']['removed'], $cmp['diff']['requirements'], $cmp['diff']['total']], [[['key' => 'check_rights', 'title' => 'בדיקת זכויות']], ['added' => [], 'removed' => [], 'changed' => []], 1], '§7 השוואה בין שתי גרסאות: מה הוסר');

// ── 03-A3: גרסה מפורסמת אינה ניתנת לעדכון בשום פעולת API ──
$frozen = fn() => [apr_row('SELECT * FROM profile_versions WHERE id = ?', [$v1['id']]), apr_row('SELECT * FROM profile_versions WHERE id = ?', [$v2['id']])];
$f0 = $frozen();
$stale = call('POST', 'profile-draft-save', ['profile' => $P, 'content' => content(), 'revision' => 1], $avi);
eq(code($stale), 'not_found', '03-A3 אין טיוטה: draft-save עם ה-revision של הגרסה המפורסמת — נדחה');
call('POST', 'profile-draft-open', ['profile' => $P], $avi);
foreach ([['profile-draft-save', ['content' => content(['rules' => []]), 'revision' => 99]], ['profile-draft-save', ['content' => content(['rules' => []]), 'revision' => 0]],
    ['profile-draft-discard', ['revision' => 99]], ['profile-update', ['name' => 'שם חדש']], ['profile-archive', []], ['profile-archive', ['archived' => false]], ['profile-delete', []],
    ['profile-duplicate', []], ['profile-try', ['input' => []]], ['profile-publish', ['note' => 'x']]] as [$action, $in]) {
    if ($action === 'profile-publish') $in['revision'] = rev($P);
    call('POST', $action, ['profile' => $P] + $in, $avi);
    if ($action === 'profile-draft-discard') call('POST', 'profile-draft-open', ['profile' => $P], $avi);
}
$v3 = apr_row('SELECT * FROM profile_versions WHERE profile_id = ? AND number = 3', [$prow($P)['id']]);
check('03-A3 אחרי כל פעולות ה-API — גרסאות 1 ו-2 לא השתנו בתו', $frozen() === $f0 && $v3 !== null, [$frozen()[1]['updated_at'], $f0[1]['updated_at']]);
foreach (["UPDATE profile_versions SET content = '{}' WHERE id = {$v1['id']}", "UPDATE profile_versions SET state = 'draft', number = NULL WHERE id = {$v1['id']}", "DELETE FROM profile_versions WHERE id = {$v1['id']}"] as $sql) {
    try { apr_db()->exec($sql); $blocked = false; } catch (PDOException) { $blocked = true; }
    check('03-A3 גם במסד: ' . strtok($sql, ' ') . ' על גרסה מפורסמת נחסם', $blocked);
}
eq(code(call('POST', 'profile-delete', ['profile' => $P], $avi)), 'profile_state', 'מקרה 3: פרופיל שפורסם אינו נמחק — רק ארכוב');
eq($frozen(), $f0, '03-A3 ועדיין לא השתנו');

// ── 03-A5: כל בדיקה ❌ חוסמת פרסום ──
$E = make_profile($avi, 'office', 'פרופיל לבדיקות', null, false);
$blocked = [
    'שתי דרישות עם אותו key' => ['dup_key', content(['requirements' => ['groups' => [['key' => 'identity', 'title' => 'ז', 'sort' => 1]], 'items' => [req('rights'), req('rights')]]])],
    'שתי משימות עם אותו key' => ['dup_key', content(['tasks' => ['items' => [['key' => 't', 'title' => 'א'], ['key' => 't', 'title' => 'ב']]]])],
    'תנאי שמפנה לדגל שלא מוגדר (מקרה 9)' => ['bad_condition', content(['flags' => []])],
    'תנאי עם ערך שאינו ברשימה' => ['bad_condition', content(['requirements' => ['groups' => [['key' => 'identity', 'title' => 'ז', 'sort' => 1]],
        'items' => [req('rights', ['when' => ['field' => 'purpose', 'op' => 'is', 'value' => 'no_such_purpose']])]]])],
    'כלל שמוסיף דרישה עם key שכבר קיים' => ['rule_key_exists', content(['requirements' => ['groups' => [['key' => 'identity', 'title' => 'ז', 'sort' => 1]], 'items' => [req('rights'), req('works_quote')]]])],
    'דרישה בלי כותרת' => ['no_title', content(['requirements' => ['groups' => [['key' => 'identity', 'title' => 'ז', 'sort' => 1]], 'items' => [req('rights', ['title' => ''])]]])],
];
foreach ($blocked as $name => [$want, $c]) {
    eq(save_draft($avi, $E, $c)[0], 200, "03-A5 $name: הטיוטה נשמרת");
    $r = call('POST', 'profile-publish', ['profile' => $E, 'revision' => rev($E)], $avi);
    eq([$r[0], code($r), in_array($want, array_column($r[1]['error']['checks']['errors'] ?? [], 'code'), true), $prow($E)['current_version_id']], [422, 'publish_blocked', true, null], "03-A5 $name → הפרסום נחסם ($want)");
}
eq([save_draft($avi, $E, content())[1]['checks']['errors'], call('POST', 'profile-publish', ['profile' => $E, 'revision' => rev($E)], $avi)[0]], [[], 200], '03-A5 אחרי התיקון — מתפרסם');
// אזהרות אינן חוסמות
$W = make_profile($avi, 'office', 'אזהרות', ['requirements' => ['groups' => [], 'items' => []]], false);
[, $chk] = call('GET', 'profile-check', [], $avi, ['profile' => $W]);
eq([array_column($chk['checks']['warnings'], 'code'), $chk['checks']['errors'], call('POST', 'profile-publish', ['profile' => $W, 'revision' => rev($W)], $avi)[0]], [['empty'], [], 200], '§8 פרופיל ריק — אזהרה, לא חסימה');
$warn = apr_profile_checks(apr_profile_content_clean(content(['requirements' => ['groups' => [['key' => 'identity', 'title' => 'ז', 'sort' => 1]],
    'items' => [req('a', ['party_kind' => 'other']), req('b', ['mandatory' => true, 'due' => ['mode' => 'none']])]],
    'rules' => [['key' => 'r1', 'title' => 'א', 'when' => ['field' => 'party.bank', 'op' => 'is', 'value' => true], 'then' => []], ['key' => 'r2', 'title' => 'ב', 'when' => ['field' => 'party.bank', 'op' => 'is', 'value' => true], 'then' => []]]])), apr_doc_type_keys());
eq([array_column($warn['warnings'], 'code'), $warn['errors']], [['other_no_explain', 'mandatory_no_due', 'same_condition'], []], '§8 שלוש האזהרות האחרות');

// ── 03-A6: תנאי לא מוכר נדחה בשמירה ──
$leaf = ['field' => 'flags.renovation_planned', 'op' => 'is', 'value' => true];
$bad = [
    'מבנה לא מוכר' => ['or' => [$leaf]],
    'ביטוי טקסטואלי' => 'flags.renovation_planned == true',
    'שדה לא מוכר' => ['field' => 'case.price', 'op' => 'is', 'value' => 'x'],
    'גורם לא מוכר' => ['field' => 'party.neighbour', 'op' => 'is', 'value' => true],
    'אופרטור לא מוכר' => ['field' => 'purpose', 'op' => 'matches', 'value' => '.*'],
    'מפתח נוסף בעלה' => $leaf + ['eval' => 'x'],
    'ערך שאינו כן/לא לדגל' => ['field' => 'flags.renovation_planned', 'op' => 'is', 'value' => 'yes'],
    'עומק 4' => ['all' => [['any' => [['all' => [['any' => [$leaf]]]]]]]],
];
[, $d] = call('POST', 'profile-draft-open', ['profile' => $E], $avi);
$kept = $draftAt($E);
foreach ($bad as $name => $when) {
    foreach (['דרישה' => content(['requirements' => ['groups' => [], 'items' => [req('x', ['group' => '', 'when' => $when])]]]), 'כלל' => content(['rules' => [['key' => 'r', 'title' => 'כ', 'when' => $when, 'then' => []]]])] as $where => $c) {
        $r = call('POST', 'profile-draft-save', ['profile' => $E, 'content' => $c, 'revision' => $d['draft']['revision']], $avi);
        eq([$r[0], code($r)], [422, 'condition_invalid'], "03-A6 תנאי ב$where — $name → נדחה בשמירה");
    }
}
eq($draftAt($E), $kept, '03-A6 והטיוטה לא השתנתה');
$deep3 = ['all' => [['any' => [['all' => [$leaf]]]]]];
eq(call('POST', 'profile-draft-save', ['profile' => $E, 'content' => content(['rules' => [['key' => 'r', 'title' => 'כ', 'when' => $deep3, 'then' => []]]]), 'revision' => $d['draft']['revision']], $avi)[0], 200, '03-A6 עומק 3 — מתקבל');
foreach (['לא מערך' => 'x', 'priority לא מוכר' => content(['requirements' => ['groups' => [], 'items' => [req('x', ['priority' => 'urgent'])]]]),
    'party_kind לא מוכר' => content(['requirements' => ['groups' => [], 'items' => [req('x', ['party_kind' => 'neighbour'])]]]),
    'מפתח לא תקין' => content(['requirements' => ['groups' => [], 'items' => [req('X y')]]]), 'פעולת כלל לא מוכרת' => content(['rules' => [['key' => 'r', 'title' => '', 'when' => null, 'then' => [['do' => 'run_code', 'text' => 'x']]]]])] as $name => $c) {
    eq(code(save_draft($avi, $E, is_array($c) ? $c : [$c])), 'content_invalid', "§4.1 $name → נדחה בשמירה");
}
$many = fn(int $n) => array_map(fn($i) => req("r$i", ['group' => '']), range(1, $n));
eq([save_draft($avi, $E, content(['requirements' => ['groups' => [], 'items' => $many(300)]]))[0], code(save_draft($avi, $E, content(['requirements' => ['groups' => [], 'items' => $many(301)]])))], [200, 'content_too_large'], 'מקרה 7: 300 דרישות — כן; 301 — שגיאה ברורה');
call('POST', 'profile-draft-discard', ['profile' => $E, 'revision' => rev($E)], $avi);

// ── 03-B1: תצוגת ניסיון ──
$before = $counts();
[$st, $with] = call('POST', 'profile-try', ['profile' => $P, 'input' => ['purpose' => 'purchase', 'flags' => ['renovation_planned' => true]]], $dana);
[, $without] = call('POST', 'profile-try', ['profile' => $P, 'input' => ['purpose' => 'purchase', 'flags' => ['renovation_planned' => false]]], $dana);
$added = array_values(array_filter($with['result']['requirements'], fn($r) => $r['key'] === 'works_quote'));
eq([$st, count($added), $added[0]['rule'] ?? null, $with['result']['alerts'][0] ?? null], [200, 1, ['key' => 'renovation_quote', 'title' => 'שיפוץ מתוכנן — פירוט עבודות'],
    ['text' => 'לבדוק היתר לשיפוץ', 'rule' => ['key' => 'renovation_quote', 'title' => 'שיפוץ מתוכנן — פירוט עבודות']]], '03-B1 עם הדגל: הדרישה שהכלל מוסיף מופיעה, מסומנת בשם הכלל');
eq([array_column($without['result']['requirements'], 'key'), $without['result']['alerts'], $without['result']['requirements'][0]['rule']], [['rights'], [], null], '03-B1 בלי הדגל: לא מופיעה');
eq(array_column(call('POST', 'profile-try', ['profile' => $P, 'input' => []], $dana)[1]['result']['requirements'], 'key'), ['rights'], '03-B1 בלי קלט בכלל — כמו בלי הדגל');
eq($counts(), $before, '03-B1 אחרי הקריאות אין שום שורה חדשה בשום טבלה');
check('03-B1 בדיקת הספירה מכסה את כל הטבלאות, כולל היומן', isset($before['audit_log'], $before['profiles'], $before['profile_versions'], $before['jobs'], $before['mail_log']) && count($before) >= 20, array_keys($before));
check('ההסבר הפנימי לא יוצא בתצוגת הניסיון', !array_key_exists('explain_internal', $with['result']['requirements'][0]));
eq(code(call('POST', 'profile-try', ['profile' => $P, 'draft' => true, 'input' => []], $dana)), 'forbidden', 'תצוגת ניסיון של הטיוטה — רק למי שעורך');
// תנאים: purpose, party, in / not_in, any
$cond = apr_profile_content_clean(content(['requirements' => ['groups' => [], 'items' => [
    req('a', ['group' => '', 'when' => ['field' => 'purpose', 'op' => 'in', 'value' => ['purchase', 'collateral']]]),
    req('b', ['group' => '', 'when' => ['field' => 'party.bank', 'op' => 'is', 'value' => true]]),
    req('c', ['group' => '', 'when' => ['any' => [['field' => 'property_type', 'op' => 'is', 'value' => 'land'], ['field' => 'purpose', 'op' => 'not_in', 'value' => ['purchase']]]]]),
    req('d', ['group' => '', 'when' => ['field' => 'purpose', 'op' => 'is_not', 'value' => 'purchase']])]], 'rules' => []]));
$keys = fn(array $in) => array_column(apr_profile_try($cond, $in)['requirements'], 'key');
eq([$keys(['purpose' => 'purchase']), $keys(['purpose' => 'taxation', 'parties' => ['bank' => true]]), $keys(['purpose' => 'purchase', 'property_type' => 'land'])], [['a'], ['b', 'c', 'd'], ['a', 'c']], '§4.2 תנאים: in, not_in, is_not, party, any');

// ── 03-B2: שני פרופילים — רשימות שונות לאותו קלט ──
$Q = make_profile($avi, 'office', 'פרופיל אחר', content(['requirements' => ['groups' => [], 'items' => [req('lease', ['group' => '', 'doc_type' => 'lease']), req('plans', ['group' => '', 'doc_type' => 'plans'])]], 'rules' => []]));
$in = ['purpose' => 'purchase', 'flags' => ['renovation_planned' => true]];
eq([array_column(call('POST', 'profile-try', ['profile' => $P, 'input' => $in], $dana)[1]['result']['requirements'], 'key'), array_column(call('POST', 'profile-try', ['profile' => $Q, 'input' => $in], $dana)[1]['result']['requirements'], 'key')],
    [['rights', 'works_quote'], ['lease', 'plans']], '03-B2 שני פרופילים עם רשימות שונות → רשימות שונות לאותו קלט');

// ── 03-C4: בידוד בין משרדים ──
$mine = make_profile($dana, 'personal', 'האישי של דנה');
foreach (['משרדי' => $P, 'אישי' => $mine] as $kind => $pid) {
    $v = (string)apr_val('SELECT v.pid FROM profile_versions v JOIN profiles p ON p.id = v.profile_id WHERE p.pid = ? AND v.number = 1', [$pid]);
    $n0 = [(int)apr_val('SELECT COUNT(*) FROM profiles'), (int)apr_val('SELECT COUNT(*) FROM profile_shares'), $prow($pid)];
    foreach ([['GET', 'profile', []], ['GET', 'profile-versions', []], ['GET', 'profile-version', ['version' => $v]], ['GET', 'profile-compare', ['a' => $v, 'b' => $v]], ['GET', 'profile-check', []],
        ['GET', 'profile-draft-info', []], ['GET', 'profile-shares', []], ['POST', 'profile-update', ['name' => 'x']], ['POST', 'profile-draft-open', []],
        ['POST', 'profile-draft-save', ['content' => content(), 'revision' => 1]], ['POST', 'profile-draft-discard', []], ['POST', 'profile-publish', []], ['POST', 'profile-archive', []],
        ['POST', 'profile-delete', []], ['POST', 'profile-duplicate', []], ['POST', 'profile-try', ['input' => []]], ['POST', 'profile-share-preview', ['email' => 'dana@example.com', 'can' => 'view']],
        ['POST', 'profile-share', ['email' => 'dana@example.com', 'can' => 'view']], ['POST', 'office-required-profile', []]] as [$m, $action, $in]) {
        $r = $m === 'GET' ? call('GET', $action, [], $boaz, ['profile' => $pid] + $in) : call('POST', $action, ['profile' => $pid] + $in, $boaz);
        eq([$r[0], code($r)], [404, 'not_found'], "03-C4 משרד ב׳ → $action על פרופיל $kind של משרד א׳ → 404");
    }
    eq([(int)apr_val('SELECT COUNT(*) FROM profiles'), (int)apr_val('SELECT COUNT(*) FROM profile_shares'), $prow($pid)], $n0, "03-C4 ושום דבר לא נוצר או השתנה ($kind)");
}
[, $bl] = call('GET', 'profiles', [], $boaz);
eq([$bl['office'], $bl['mine'], $bl['shared'], call('GET', 'profiles', [], $boaz, ['q' => 'דנה'])[1]['shared'], count($bl['recommended'])], [[], [], [], [], 5], '03-C4 והם אינם מופיעים בספרייה או בחיפוש שלו');
eq(code(call('GET', 'profile', [], $boaz, ['profile' => 'aaaaaaaaaaaaaaaa'])), 'not_found', 'פרופיל שלא קיים — אותה תשובה');
eq(code(call('GET', 'profile', [], $avi, ['profile' => $mine])), 'not_found', '§5 פרופיל אישי — גם מנהל המשרד לא רואה בלי שיתוף');
eq([call('GET', 'profile', [], $dana, ['profile' => $P])[1]['profile']['can'], code(call('POST', 'profile-duplicate', ['profile' => $P], $dana))], [['duplicate' => false, 'edit' => false, 'manage' => false, 'share' => false], 'forbidden'], '§5 חבר משרד רואה פרופיל משרדי; שכפול — רק עם duplicate ומעלה');
eq(code(call('POST', 'profile-create', ['scope' => 'office', 'name' => 'x'], $dana)), 'forbidden', '§9 יצירת פרופיל משרדי — מנהל משרד');
eq(code(call('POST', 'profile-create', ['scope' => 'personal', 'name' => 'x'], $none)), 'no_office', '§9 פרופיל אישי — רק לחבר משרד');
[, $dl] = call('GET', 'profiles', [], $dana);
eq([in_array($mine, array_column($dl['mine'], 'pid'), true), in_array($P, array_column($dl['office'], 'pid'), true), array_column(call('GET', 'profiles', [], $dana, ['q' => 'האישי'])[1]['mine'], 'pid')], [true, true, [$mine]], '§7 הספרייה: שלי, של המשרד, וחיפוש');

// ── 03-C1: view / duplicate / edit ──
$share = fn(string $can) => call('POST', 'profile-share', ['profile' => $mine, 'email' => 'boaz@example.com', 'can' => $can], $dana);
[$st, $prev] = call('POST', 'profile-share-preview', ['profile' => $mine, 'email' => 'boaz@example.com', 'can' => 'view'], $dana);
eq([$st, $prev['grantee'], $prev['can'], (int)apr_val('SELECT COUNT(*) FROM profile_shares')], [200, ['kind' => 'user', 'name' => 'בועז'], 'view', 0], '§6 תצוגה מקדימה: מי הנמען ומה יוכל — בלי לשתף עדיין');
eq(code(call('POST', 'profile-share', ['profile' => $mine, 'email' => 'nobody@example.com', 'can' => 'view'], $dana)), 'share_no_user', 'מקרה 5: מייל שאין לו חשבון — "לא נמצא משתמש בכתובת הזו"');
[$st, $sh] = $share('view');
$mineRow = $prow($mine);
$try = fn(string $action, array $in = []) => code(call('POST', $action, ['profile' => $mine] + $in, $boaz));
$draftOf = fn() => apr_profile_draft_row((int)$mineRow['id']);
eq([$st, call('GET', 'profile', [], $boaz, ['profile' => $mine])[0], array_column(call('GET', 'profiles', [], $boaz)[1]['shared'], 'pid')], [200, 200, [$mine]], '03-C1 view: רואה, ומופיע ב"שותפו איתי"');
eq([$try('profile-duplicate'), $try('profile-draft-open'), $try('profile-update', ['name' => 'x']), $try('profile-publish'), $try('profile-archive'), $try('profile-share', ['email' => 'avi@example.com', 'can' => 'view'])],
    array_fill(0, 6, 'forbidden'), '03-C1 view: אינו יכול לשכפל, לערוך, לפרסם, לארכב או לשתף');
$share('duplicate');
[$st, $bdup] = call('POST', 'profile-duplicate', ['profile' => $mine], $boaz);
eq([$st, $try('profile-draft-open'), $try('profile-update', ['name' => 'x']), $try('profile-publish'), $draftOf()], [200, 'forbidden', 'forbidden', 'forbidden', null], '03-C1 duplicate: משכפל, ואינו עורך את המקור');
$share('edit');
eq([(int)apr_val('SELECT COUNT(*) FROM profile_shares WHERE revoked_at IS NULL'), call('GET', 'profile', [], $boaz, ['profile' => $mine])[1]['profile']['can']], [1, ['duplicate' => true, 'edit' => true, 'manage' => false, 'share' => false]], 'שינוי רמה — אותו שיתוף');
eq([save_draft($boaz, $mine, content(['tasks' => ['items' => []]]))[0], call('POST', 'profile-publish', ['profile' => $mine, 'revision' => rev($mine), 'note' => 'בועז'], $boaz)[1]['number'] ?? null], [200, 2], '03-C1 edit: עורך ומפרסם');
eq([$try('profile-share', ['email' => 'avi@example.com', 'can' => 'view']), $try('profile-share-preview', ['email' => 'avi@example.com', 'can' => 'view']), $try('profile-archive'), $try('profile-delete'),
    code(call('GET', 'profile-shares', [], $boaz, ['profile' => $mine])), code(call('POST', 'profile-share-revoke', ['share' => $sh['share']], $boaz))], array_fill(0, 6, 'forbidden'), '03-C1 edit: אינו יכול לשתף, לארכב, למחוק, לראות שיתופים או לבטל אותם');
eq([$prow($mine)['status'], (int)apr_val('SELECT COUNT(*) FROM profile_shares')], ['active', 1], '03-C1 והפרופיל לא אורכב, ואין שיתוף נוסף');
eq(code(call('POST', 'profile-share', ['profile' => $mine, 'email' => 'dana@example.com', 'can' => 'view'], $dana)), 'self_action', 'אי אפשר לשתף עם עצמך');

// ── 03-C2: מה עובר בשיתוף ובשכפול ──
$allowedProfile = ['pid', 'scope', 'name', 'description', 'purposes', 'property_types', 'status', 'is_sample', 'owner', 'version', 'has_draft', 'updated_at', 'source', 'can'];
[, $seen] = call('GET', 'profile', [], $boaz, ['profile' => $mine]);
eq([array_keys($seen), array_keys($seen['profile']), array_keys($seen['content'])], [['ok', 'profile', 'version', 'content'], $allowedProfile, ['schema', 'flags', 'requirements', 'tasks', 'rules']], '03-C2 תשובת השיתוף: רק פרטי הפרופיל ו-content');
[, $copyView] = call('POST', 'profile-draft-open', ['profile' => $bdup['profile']], $boaz);
eq([array_keys($copyView['profile']), array_keys($copyView['content'])], [$allowedProfile, ['schema', 'flags', 'requirements', 'tasks', 'rules']], '03-C2 הפרופיל המשוכפל: אותם שדות');
$ids = fn(string $t) => array_column(apr_rows("SELECT pid FROM $t"), 'pid');
$danaOffice = (int)apr_val('SELECT id FROM offices WHERE pid = ?', [$A]);
$blob = json_encode([$seen, $copyView, call('GET', 'profiles', [], $boaz)[1]], JSON_UNESCAPED_UNICODE);
check('03-C2 אין מפתחות פנימיים: office_id, owner_user_id, מזהים מספריים', !preg_match('/"(office_id|owner_user_id|profile_id|grantee_id|granted_by|id|current_version_id|source_version_id|email)"\s*:/', $blob));
check('03-C2 אין מזהה של משרד א׳, של משתמשיו או של ההזמנות שלו', !str_contains($blob, $A) && !str_contains($blob, $danaPid) && !array_filter($ids('invitations'), fn($x) => str_contains($blob, $x)) && !str_contains($blob, 'dana@example.com'));
$bcopy = $prow($bdup['profile']);
eq([(int)$bcopy['office_id'] === (int)apr_val('SELECT id FROM offices WHERE pid = ?', [$B]), (int)$bcopy['office_id'] !== $danaOffice, $bcopy['scope'], (int)apr_val('SELECT COUNT(*) FROM profile_shares WHERE profile_id = ?', [$bcopy['id']])],
    [true, true, 'personal', 0], '03-C2 העותק שייך למשרד של המשכפל, ובלי השיתופים של המקור');
[, $shares] = call('GET', 'profile-shares', [], $dana, ['profile' => $mine]);
eq([count($shares['shares']), $shares['shares'][0]['name'], $shares['shares'][0]['can'], $shares['shares'][0]['email']], [1, 'בועז', 'edit', 'boaz@example.com'], '§6 הבעלים רואה את רשימת השיתופים');

// ── 03-C3: ביטול שיתוף ──
eq(call('POST', 'profile-share-revoke', ['share' => $sh['share']], $dana)[0], 200, 'הבעלים מבטלת את השיתוף');
eq([call('GET', 'profile', [], $boaz, ['profile' => $mine])[0], $try('profile-duplicate'), $try('profile-draft-open'), call('GET', 'profiles', [], $boaz)[1]['shared']], [404, 'not_found', 'not_found', []], '03-C3 אחרי ביטול → 404 לנמען, והפרופיל נעלם מהספרייה שלו');
eq([call('GET', 'profile', [], $boaz, ['profile' => $bdup['profile']])[0] === 200 || call('POST', 'profile-draft-open', ['profile' => $bdup['profile']], $boaz)[0] === 200, in_array($bdup['profile'], array_column(call('GET', 'profiles', [], $boaz)[1]['mine'], 'pid'), true)], [true, true], '03-C3 העותק שכבר שוכפל נשאר שלו');
eq(code(call('POST', 'profile-share-revoke', ['share' => $sh['share']], $dana)), 'not_found', 'ביטול חוזר — לא נמצא');
// שיתוף עם המשרד
eq(call('POST', 'profile-share', ['profile' => $mine, 'office' => true, 'can' => 'duplicate'], $dana)[0], 200, '§6 שיתוף עם המשרד');
eq([call('GET', 'profile', [], $avi, ['profile' => $mine])[1]['profile']['can']['duplicate'], call('GET', 'profile', [], $boaz, ['profile' => $mine])[0]], [true, 404], 'חברי המשרד רואים ומשכפלים; משרד אחר — לא');

// ── 03-D1: שמירה עם אסימון ישן → 409, והטיוטה לא השתנתה ──
[, $d1] = call('POST', 'profile-draft-open', ['profile' => $P], $avi);
$t = $d1['draft']['revision'];
[$st, $s1] = call('POST', 'profile-draft-save', ['profile' => $P, 'content' => content(['tasks' => ['items' => [['key' => 'first', 'title' => 'ראשון']]]]), 'revision' => $t], $avi);
$kept = $draftAt($P);
$r = call('POST', 'profile-draft-save', ['profile' => $P, 'content' => content(['tasks' => ['items' => [['key' => 'second', 'title' => 'שני']]]]), 'revision' => $t], $avi);
eq([$st, $s1['revision'], $r[0], code($r), $draftAt($P)], [200, $t + 1, 409, 'draft_changed', $kept], '03-D1 שמירה עם אסימון ישן → 409, והטיוטה שבשרת לא השתנתה');
eq([code(call('POST', 'profile-draft-save', ['profile' => $P, 'content' => content()], $avi)), code(call('POST', 'profile-draft-save', ['profile' => $P, 'content' => content(), 'revision' => (string)$s1['revision']], $avi))], ['draft_changed', 'draft_changed'], '03-D1 בלי אסימון, או אסימון שאינו מספר — גם נדחה');
// 🟡5: האסימון הוא מספר שעולה ב-1 — שתי שמירות באותה שנייה אינן דורסות זו את זו
$GLOBALS['apr_time'] = time();
[, $sa] = call('POST', 'profile-draft-save', ['profile' => $P, 'content' => content(), 'revision' => $s1['revision']], $avi);
$rb = call('POST', 'profile-draft-save', ['profile' => $P, 'content' => content(['rules' => []]), 'revision' => $s1['revision']], $avi);
eq([$sa['revision'], code($rb), call('POST', 'profile-draft-save', ['profile' => $P, 'content' => content(), 'revision' => $sa['revision']], $avi)[1]['revision']], [$s1['revision'] + 1, 'draft_changed', $s1['revision'] + 2], '🟡5 שתי שמירות באותה שנייה עם אותו אסימון — השנייה נדחית');
unset($GLOBALS['apr_time']);
eq(call('GET', 'profile-draft-info', [], $avi, ['profile' => $P])[1]['draft']['updated_by'], 'אבי', 'מקרה 1: "עודכנה בידי <שם>"');
// 🟡4: פרסום וביטול טיוטה — רק עם האסימון העדכני; ביטול — לבעלים, ונרשם
$cur = rev($P);
eq([code(call('POST', 'profile-publish', ['profile' => $P], $avi)), code(call('POST', 'profile-publish', ['profile' => $P, 'revision' => $cur - 1], $avi)), code(call('POST', 'profile-draft-discard', ['profile' => $P], $avi)),
    code(call('POST', 'profile-draft-discard', ['profile' => $P, 'revision' => $cur - 1], $avi)), rev($P)], ['draft_changed', 'draft_changed', 'draft_changed', 'draft_changed', $cur], '🟡4 פרסום וביטול טיוטה בלי האסימון העדכני → 409, והטיוטה נשארת');
$n0 = (int)apr_val("SELECT COUNT(*) FROM audit_log WHERE action = 'profile.draft_discard'");
eq([call('POST', 'profile-draft-discard', ['profile' => $P, 'revision' => $cur], $avi)[0], rev($P), (int)apr_val("SELECT COUNT(*) FROM audit_log WHERE action = 'profile.draft_discard'") - $n0], [200, 0, 1], '🟡4 עם האסימון — הטיוטה מבוטלת, ונרשם ביומן הפעולות');

// ── 03-D2: ארכיון ──
$v1pid = (string)apr_val('SELECT v.pid FROM profile_versions v JOIN profiles p ON p.id = v.profile_id WHERE p.pid = ? AND v.number = 1', [$Q]);
$pickOf = fn() => array_column(call('GET', 'profiles', [], $dana, ['pick' => '1'])[1]['office'], 'pid');
check('03-D2 לפני הארכוב — מופיע ב"פרופילים לבחירה"', in_array($Q, $pickOf(), true));
eq(call('POST', 'profile-archive', ['profile' => $Q], $avi)[0], 200, 'מקרה 2: ארכוב');
eq([in_array($Q, $pickOf(), true), call('GET', 'profile-version', [], $dana, ['version' => $v1pid])[0], call('GET', 'profile-version', [], $dana, ['version' => $v1pid])[1]['content']['requirements']['items'][0]['key']], [false, 200, 'lease'],
    '03-D2 פרופיל בארכיון אינו ב"פרופילים לבחירה"; הגרסה המפורסמת עדיין נקראת לפי מזהה');
$draftMine = call('POST', 'profile-create', ['scope' => 'personal', 'name' => 'טיוטה אישית של דנה'], $dana)[1]['profile'];
eq([in_array($draftMine, array_column(call('GET', 'profiles', [], $dana)[1]['mine'], 'pid'), true), in_array($draftMine, array_column(call('GET', 'profiles', [], $dana, ['pick' => '1'])[1]['mine'], 'pid'), true)], [true, false], '03-D2 פרופיל שלי שעוד לא פורסם: בספרייה כן, ב"פרופילים לבחירה" לא');
call('POST', 'profile-delete', ['profile' => $draftMine], $dana);
eq([call('POST', 'profile-archive', ['profile' => $Q, 'archived' => false], $avi)[0], in_array($Q, $pickOf(), true)], [200, true], 'החזרה לפעיל');
$unpub = make_profile($avi, 'office', 'טיוטה בלבד', null, false);
eq([in_array($unpub, $pickOf(), true), in_array($unpub, array_column(call('GET', 'profiles', [], $dana)[1]['office'], 'pid'), true), in_array($unpub, array_column(call('GET', 'profiles', [], $avi)[1]['office'], 'pid'), true),
    in_array($unpub, array_column(call('GET', 'profiles', [], $avi, ['pick' => '1'])[1]['office'], 'pid'), true), call('GET', 'profile', [], $dana, ['profile' => $unpub])[0]], [false, false, true, false, 404], 'פרופיל שלא פורסם: נראה רק למי שעורך — וגם לו אינו "לבחירה" (מסנן pick)');
eq([call('POST', 'profile-delete', ['profile' => $unpub], $avi)[0], call('GET', 'profile', [], $avi, ['profile' => $unpub])[0], (int)apr_val('SELECT COUNT(*) FROM profile_versions WHERE profile_id = ?', [$prow($unpub)['id']])], [200, 404, 0], 'מקרה 3: טיוטה שלא פורסמה נמחקת באמת');

// ── פרופיל משרדי מחייב ──
eq([call('POST', 'office-required-profile', ['profile' => $P], $avi)[0], call('GET', 'profiles', [], $dana)[1]['required_profile'], code(call('POST', 'office-required-profile', ['profile' => $P], $dana)),
    code(call('POST', 'office-required-profile', ['profile' => $mine], $avi)), code(call('POST', 'office-required-profile', ['profile' => $recPid], $avi))], [200, $P, 'forbidden', 'profile_state', 'profile_state'], '§9 "פרופיל משרדי מחייב": מנהל משרד, ורק פרופיל משרדי פעיל');
eq([call('POST', 'office-required-profile', ['profile' => null], $avi)[0], call('GET', 'profiles', [], $dana)[1]['required_profile']], [200, null], 'כיבוי');

// ── §9 הבעלים עוזב את המשרד: האישי נשאר במשרד, כמשרדי בארכיון ──
$draftOnly = make_profile($dana, 'personal', 'טיוטה של דנה', null, false);
call('POST', 'office-leave', [], $dana);
eq([$prow($mine)['scope'], $prow($mine)['status'], (int)$prow($mine)['office_id'] === $danaOffice, $prow($draftOnly)['scope']], ['office', 'archived', true, 'office'], '§9 הבעלים עזבה: הפרופיל האישי נשאר במשרד כפרופיל משרדי בארכיון');
eq([call('POST', 'profile-archive', ['profile' => $mine, 'archived' => false], $avi)[0], $prow($mine)['status'], call('GET', 'profile', [], $dana, ['profile' => $mine])[0]], [200, 'active', 404], 'מנהל המשרד מחזיר אותו לפעיל; מי שעזבה — כבר לא רואה');
eq((int)apr_val("SELECT COUNT(*) FROM audit_log WHERE action = 'profile.to_office'"), 3, 'ונרשם ביומן הפעולות — שלושת הפרופילים האישיים שלה');

// ── סוגי מסמך ──
eq([call('POST', 'sys-doc-type-save', ['key' => 'appraisal_old', 'name' => 'שומה קודמת'], $owner)[0], count(call('GET', 'sys-doc-types', [], $owner)[1]['doc_types'])], [200, 12], '§4.3 מנהל המערכת מוסיף סוג מסמך');
call('POST', 'sys-doc-type-save', ['key' => 'lease', 'name' => 'חוזה שכירות', 'hidden' => true], $owner);
eq([in_array('lease', array_column(call('GET', 'profile-lists', [], $avi)[1]['doc_types'], 'key'), true), (bool)apr_val("SELECT 1 FROM doc_types WHERE key = 'lease'"), call('POST', 'profile-try', ['profile' => $Q, 'input' => []], $avi)[1]['result']['requirements'][0]['doc_type']], [false, true, 'lease'],
    '§4.3 סוג בשימוש אינו נמחק — רק מוסתר, והפרופילים שמשתמשים בו ממשיכים לעבוד');
eq(code(call('POST', 'sys-doc-type-save', ['key' => 'Bad Key', 'name' => 'x'], $owner)), 'content_invalid', 'מפתח לא תקין');

// ── יומן הפעולות, ומקרה 8 ──
$logged = array_column(apr_rows("SELECT DISTINCT action FROM audit_log WHERE action LIKE 'profile.%' OR action LIKE 'doctype.%' OR action = 'office.required_profile'"), 'action');
sort($logged);
eq($logged, ['doctype.create', 'doctype.update', 'office.required_profile', 'profile.archive', 'profile.create', 'profile.delete', 'profile.draft_discard', 'profile.duplicate', 'profile.publish', 'profile.share', 'profile.to_office',
    'profile.unarchive', 'profile.unshare', 'profile.update'], 'כל פעולה משנה נרשמת ביומן הפעולות');
// לא רק שמות: לכל שורה — הפרופיל הנכון, המשרד, המבצע, ומה שה-meta אמור לשאת
$la = fn(string $action, string $pid) => apr_row('SELECT a.*, u.pid AS actor_pid, o.pid AS office_pid FROM audit_log a LEFT JOIN users u ON u.id = a.actor_user_id LEFT JOIN offices o ON o.id = a.office_id WHERE a.action = ? AND a.entity_pid = ? ORDER BY a.id DESC LIMIT 1', [$action, $pid]);
$x = $la('profile.publish', $P);
eq([$x['entity_type'], $x['office_pid'], $x['actor_pid'], json_decode($x['meta'], true)], ['profile', $A, call('GET', 'me', [], $avi)[1]['user']['pid'], ['number' => 3]], 'profile.publish: הפרופיל, המשרד, המבצע ומספר הגרסה');
$x = $la('profile.share', $mine);
eq([$x['office_pid'], array_keys(json_decode($x['meta'], true)), json_decode($x['meta'], true)['kind'], preg_match('/^[a-z0-9]{16}$/', json_decode($x['meta'], true)['grantee'])], [$A, ['kind', 'can', 'grantee'], 'office', 1], 'profile.share: עם מזהה הנמען (pid), בלי מייל');
$x = $la('profile.unshare', $mine);
eq([json_decode($x['meta'], true)['kind'], json_decode($x['meta'], true)['grantee'], $x['actor_pid']], ['user', call('GET', 'me', [], $boaz)[1]['user']['pid'], $danaPid], 'profile.unshare: מי הנמען, ומי ביטל');
$x = $la('profile.create', $recMine);
eq([$x['office_pid'], json_decode($x['meta'], true)], [null, ['scope' => 'recommended']], 'profile.create של מומלץ: בלי משרד');
eq([(int)apr_val("SELECT COUNT(*) FROM audit_log WHERE action = 'profile.duplicate' AND json_extract(meta, '$.from_number') IS NOT NULL"), (int)apr_val("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'profile.%' AND entity_type <> 'profile'")], [(int)apr_val("SELECT COUNT(*) FROM audit_log WHERE action = 'profile.duplicate'"), 0], 'profile.duplicate: ממספר איזו גרסה; וכל שורה על ישות מסוג profile');
check('היומן בלי תוכן של פרופיל', !preg_match('/דרישה|rights|works_quote/u', json_encode(apr_rows("SELECT meta FROM audit_log WHERE action LIKE 'profile.%'"), JSON_UNESCAPED_UNICODE)));
eq(array_keys(apr_profile_content_read('{"schema":0,"requirements":{"groups":[],"items":[]},"visit":{"x":1}}')), ['schema', 'flags', 'requirements', 'tasks', 'rules'], 'מקרה 8: תוכן ישן — מקטע חסר מושלם, מקטע לא מוכר מתעלמים ממנו');
eq(apr_profile_content_read('{"schema":0}')['schema'], APR_PROFILE_SCHEMA, 'מקרה 8: ה-schema מתעדכן בקריאה');

// ══ ביקורת סבב 1 (team/reviews/03-profiles.md) ══
$R = make_profile($avi, 'office', 'סבב 2', null, false);
$leaf = ['field' => 'flags.renovation_planned', 'op' => 'is', 'value' => true];
// 🟡1: ירידת שורה בסוף אינה עוקפת את הקבוצות הסגורות
foreach (['מפתח דרישה' => content(['requirements' => ['groups' => [], 'items' => [req("abc\n", ['group' => ''])]]]), 'מפתח קבוצה' => content(['requirements' => ['groups' => [['key' => "g\n", 'title' => 'x']], 'items' => []]]),
    'מפתח דגל' => content(['flags' => [['key' => "f\n", 'title' => 'x']], 'rules' => []]), 'סוג מסמך' => content(['requirements' => ['groups' => [], 'items' => [req('a', ['group' => '', 'doc_type' => "rights\n"])]]])] as $name => $c) {
    eq(code(save_draft($avi, $R, $c)), 'content_invalid', "🟡1 $name עם ירידת שורה בסוף → נדחה");
}
foreach (["purpose\n", "flags.renovation_planned\n", "party.bank\n"] as $f) {
    eq(code(save_draft($avi, $R, content(['rules' => [['key' => 'r', 'title' => 'x', 'when' => ['field' => $f, 'op' => 'is', 'value' => true], 'then' => []]]]))), 'condition_invalid', '🟡1 שדה תנאי עם ירידת שורה (' . trim($f) . ') → נדחה');
}
eq([code(call('POST', 'sys-doc-type-save', ['key' => "newtype\n", 'name' => 'x'], $owner)), (int)apr_val("SELECT COUNT(*) FROM doc_types WHERE key LIKE 'newtype%'")], ['content_invalid', 0], '🟡1 מפתח סוג מסמך עם ירידת שורה → נדחה');
// 🟡2: פריט שכלל מוסיף אינו נושא when משלו
foreach (['דרישה' => ['do' => 'add_requirement', 'item' => req('x1', ['group' => '', 'when' => $leaf])], 'משימה' => ['do' => 'add_task', 'item' => ['key' => 't1', 'title' => 'x', 'when' => $leaf]]] as $name => $action) {
    eq(code(save_draft($avi, $R, content(['rules' => [['key' => 'r', 'title' => 'כ', 'when' => $leaf, 'then' => [$action]]]]))), 'condition_invalid', "🟡2 $name שכלל מוסיף, עם when משלה → נדחית בשמירה");
}
eq(save_draft($avi, $R, content(['rules' => [['key' => 'r', 'title' => 'כ', 'when' => $leaf, 'then' => [['do' => 'add_task', 'item' => ['key' => 't1', 'title' => 'x', 'when' => null]]]]]]))[0], 200, '🟡2 בלי when — נשמרת');
// 🟡3: מפתח כפול בין פריטים שכללים מוסיפים
$twoRules = fn(array $a, array $b) => content(['rules' => [['key' => 'r1', 'title' => 'א', 'when' => $leaf, 'then' => [$a]], ['key' => 'r2', 'title' => 'ב', 'when' => ['field' => 'party.bank', 'op' => 'is', 'value' => true], 'then' => [$b]]]]);
foreach (['דרישות' => $twoRules(['do' => 'add_requirement', 'item' => req('same', ['group' => ''])], ['do' => 'add_requirement', 'item' => req('same', ['group' => ''])]),
    'משימות' => $twoRules(['do' => 'add_task', 'item' => ['key' => 'same', 'title' => 'א']], ['do' => 'add_task', 'item' => ['key' => 'same', 'title' => 'ב']])] as $name => $c) {
    save_draft($avi, $R, $c);
    $r = call('POST', 'profile-publish', ['profile' => $R, 'revision' => rev($R)], $avi);
    eq([code($r), in_array('dup_key', array_column($r[1]['error']['checks']['errors'] ?? [], 'code'), true)], ['publish_blocked', true], "🟡3 אותו מפתח בשני כללים ($name) → הפרסום נחסם");
}
save_draft($avi, $R, $twoRules(['do' => 'add_requirement', 'item' => req('same', ['group' => ''])], ['do' => 'add_task', 'item' => ['key' => 'same', 'title' => 'ב']]));
eq(call('POST', 'profile-publish', ['profile' => $R, 'revision' => rev($R)], $avi)[0], 200, '🟡3 אותו מפתח בדרישה ובמשימה — רשימות נפרדות, מותר');
// 🟡8 / 03-C1: view ו-duplicate לא יכולים check, draft-info, discard; edit — check ו-draft-info כן, discard לא
$S = make_profile($avi, 'personal', 'לשיתוף בסבב 2');
call('POST', 'profile-draft-open', ['profile' => $S], $avi);
$as = fn(string $can) => call('POST', 'profile-share', ['profile' => $S, 'email' => 'boaz@example.com', 'can' => $can], $avi);
foreach (['view' => ['forbidden', 'forbidden', 'forbidden'], 'duplicate' => ['forbidden', 'forbidden', 'forbidden'], 'edit' => [null, null, 'forbidden']] as $can => $want) {
    $as($can);
    eq([code(call('GET', 'profile-check', [], $boaz, ['profile' => $S])), code(call('GET', 'profile-draft-info', [], $boaz, ['profile' => $S])), code(call('POST', 'profile-draft-discard', ['profile' => $S, 'revision' => rev($S)], $boaz))], $want,
        "03-C1 $can: profile-check, profile-draft-info, profile-draft-discard");
}
check('🟡4 והטיוטה עדיין קיימת — מי שאינו הבעלים לא ביטל אותה', rev($S) > 0);
// 🟡8: profile-compare — שתי הגרסאות חייבות להיות של הפרופיל שבבקשה
$vS = (string)apr_val('SELECT v.pid FROM profile_versions v JOIN profiles p ON p.id = v.profile_id WHERE p.pid = ? AND v.number = 1', [$S]);
$vP = (string)apr_val('SELECT v.pid FROM profile_versions v JOIN profiles p ON p.id = v.profile_id WHERE p.pid = ? AND v.number = 1', [$P]);
eq([code(call('GET', 'profile-compare', [], $avi, ['profile' => $S, 'a' => $vS, 'b' => $vP])), code(call('GET', 'profile-compare', [], $avi, ['profile' => $S, 'a' => $vP, 'b' => $vS])), call('GET', 'profile-compare', [], $avi, ['profile' => $S, 'a' => $vS, 'b' => $vS])[0]],
    ['not_found', 'not_found', 200], '🟡8 profile-compare: גרסה של פרופיל אחר — לא נמצאה, גם כשיש לי גישה לשניהם');
// 🟡8: שיתוף — רק למשתמש פעיל עם מייל מאומת
$eve = ident('40', 'חוה', 'eve@example.com', ['email_verified' => false]);
call('GET', 'me', [], $eve);
eq(code(call('POST', 'profile-share-preview', ['profile' => $S, 'email' => 'eve@example.com', 'can' => 'view'], $avi)), 'share_no_user', '🟡8 נמען עם מייל לא מאומת — "לא נמצא משתמש"');
apr_exec("UPDATE users SET email_verified = 1, status = 'suspended' WHERE email = 'eve@example.com'");
eq(code(call('POST', 'profile-share', ['profile' => $S, 'email' => 'eve@example.com', 'can' => 'view'], $avi)), 'share_no_user', '🟡8 נמען מושעה — אותה תשובה');
apr_exec("UPDATE users SET status = 'active' WHERE email = 'eve@example.com'");
eq(call('POST', 'profile-share-preview', ['profile' => $S, 'email' => 'eve@example.com', 'can' => 'view'], $avi)[1]['grantee']['name'] ?? null, 'חוה', 'מאומת ופעיל — נמצא');
// 🟡6: הגבלת קצב למשתמש על preview ו-share (30 ב-15 דקות)
apr_exec("DELETE FROM login_attempts WHERE key LIKE 'pshare:%'");
for ($i = 1; $i <= 30; $i++) $last = call('POST', 'profile-share-preview', ['profile' => $S, 'email' => "guess$i@example.com", 'can' => 'view'], $avi);
eq([code($last), code(call('POST', 'profile-share-preview', ['profile' => $S, 'email' => 'eve@example.com', 'can' => 'view'], $avi)), code(call('POST', 'profile-share', ['profile' => $S, 'email' => 'eve@example.com', 'can' => 'view'], $avi))],
    ['share_no_user', 'too_many_requests', 'too_many_requests'], '🟡6 אחרי 30 בקשות ב-15 דקות — preview ו-share נחסמים (מייל→שם אינו מנחש כתובות)');
$GLOBALS['apr_time'] = time() + 901;
eq(call('POST', 'profile-share-preview', ['profile' => $S, 'email' => 'eve@example.com', 'can' => 'view'], $avi)[0], 200, 'ואחרי 15 דקות — שוב אפשר');
unset($GLOBALS['apr_time']);
// 🟡7: ארכוב הפרופיל המחייב מנקה את ההגדרה, עם רישום
$req1 = make_profile($avi, 'office', 'מחייב');
call('POST', 'office-required-profile', ['profile' => $req1], $avi);
$n0 = (int)apr_val("SELECT COUNT(*) FROM audit_log WHERE action = 'office.required_profile'");
call('POST', 'profile-archive', ['profile' => $req1], $avi);
$lastLog = apr_row("SELECT meta, office_id FROM audit_log WHERE action = 'office.required_profile' ORDER BY id DESC LIMIT 1");
eq([call('GET', 'profiles', [], $avi)[1]['required_profile'], (int)apr_val("SELECT COUNT(*) FROM audit_log WHERE action = 'office.required_profile'") - $n0, json_decode($lastLog['meta'], true), (int)$lastLog['office_id'] > 0],
    [null, 1, ['set' => false, 'by' => 'archive'], true], '🟡7 ארכוב הפרופיל המחייב מנקה את ההגדרה באותה פעולה, ונרשם');
call('POST', 'office-required-profile', ['profile' => $P], $avi);
call('POST', 'profile-archive', ['profile' => $Q], $avi);
eq(call('GET', 'profiles', [], $avi)[1]['required_profile'], $P, '🟡7 ארכוב פרופיל אחר לא נוגע בהגדרה');
call('POST', 'office-required-profile', ['profile' => null], $avi);
// ⚪ is_sample: עותק של דוגמה מפסיק להיות "דוגמה" כשמפרסמים בו גרסה; במומלץ — מנהל המערכת מנקה
$sample = call('GET', 'profiles', [], $avi)[1]['recommended'][0]['pid'];
$dup = call('POST', 'profile-duplicate', ['profile' => $sample], $avi)[1]['profile'];
$isSample = fn(string $pid) => (int)apr_val('SELECT is_sample FROM profiles WHERE pid = ?', [$pid]);
$b0 = $isSample($dup);
call('POST', 'profile-publish', ['profile' => $dup, 'revision' => rev($dup)], $avi);
eq([apr_val('SELECT is_sample FROM profiles WHERE pid = ?', [$sample]), $b0, $isSample($dup)], [1, 1, 0], '⚪ עותק של דוגמה: מסומן "דוגמה" עד שמפרסמים בו גרסה משלו');
call('POST', 'profile-update', ['profile' => $dup, 'is_sample' => true], $avi);
call('POST', 'profile-update', ['profile' => $sample, 'is_sample' => false], $avi);
eq([$isSample($dup), $isSample($sample), call('POST', 'profile-update', ['profile' => $sample, 'is_sample' => false], $owner)[0], $isSample($sample)], [0, 1, 200, 0], '⚪ רק מנהל המערכת מנקה את הסימון, ורק בפרופיל מומלץ');
eq(code(call('POST', 'profile-update', ['profile' => $dup, 'purposes' => [['x']]], $avi)), 'content_invalid', '⚪ purposes עם ערך שאינו מחרוזת — נדחה, בלי אזהרת PHP');

done();
