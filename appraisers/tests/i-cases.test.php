<?php
// פרוסה 04 — תיקים (docs/spec/04-cases.md §11): 04-A1 … 04-C4, ומקרי הקצה.
if (($argv[1] ?? '') === 'worker') {
    // 04-A7: תהליך-בן שפותח תיקים במקביל לאחרים, על אותו קובץ מסד
    define('APR_DATA_DIR', $argv[2]);
    define('APR_DB_PATH', $argv[2] . '/app.sqlite');
    require __DIR__ . '/lib.php';
    $as = ident('2', 'אבי', 'avi@example.com');
    usleep(random_int(0, 20000));
    $out = [];
    for ($i = 0; $i < (int)$argv[4]; $i++) {
        $r = call('POST', 'case-create', ['profile' => $argv[3]], $as);
        $out[] = $r[0] === 200 ? $r[1]['number'] : 'ERR ' . json_encode($r[1], JSON_UNESCAPED_UNICODE);
    }
    echo json_encode($out);
    exit(0);
}
require __DIR__ . '/lib.php';

$owner = ident('1', 'הבעלים', 'owner@example.com', ['platform_admin' => true]);
$avi = ident('2', 'אבי', 'avi@example.com');       // מנהל משרד א, שמאי
$dana = ident('3', 'דנה', 'dana@example.com');     // שמאית במשרד א
$tal = ident('5', 'טל', 'tal@example.com');        // עובדת משרד במשרד א
$noam = ident('6', 'נועם', 'noam@example.com');    // מתמחה במשרד א
$boaz = ident('4', 'בועז', 'boaz@example.com');    // מנהל משרד ב
$A = office_for($avi, 'משרד א');
$B = office_for($boaz, 'משרד ב');
$danaPid = join_office($avi, $dana, 'dana@example.com', 'appraiser');
$talPid = join_office($avi, $tal, 'tal@example.com', 'staff');
$noamPid = join_office($avi, $noam, 'noam@example.com', 'trainee');
$aviPid = call('GET', 'me', [], $avi)[1]['user']['pid'];

function req(string $key, array $more = []): array
{
    return $more + ['key' => $key, 'group' => '', 'doc_type' => 'rights', 'title' => "דרישה $key", 'priority' => 'normal', 'mandatory' => false, 'party_kind' => 'client',
        'due' => ['mode' => 'relative', 'days' => 3], 'needs_review' => true, 'when' => null, 'sort' => 1];
}
function content(array $more = []): array
{
    return $more + ['flags' => [['key' => 'renovation_planned', 'title' => 'שיפוץ', 'question' => 'מתוכנן שיפוץ?']],
        'requirements' => ['groups' => [], 'items' => [req('rights', ['mandatory' => true]), req('contract', ['doc_type' => 'contract', 'party_kind' => 'lawyer', 'sort' => 2])]],
        'tasks' => ['items' => [['key' => 'check_rights', 'title' => 'בדיקת זכויות', 'assignee' => 'responsible', 'due' => ['mode' => 'relative', 'days' => 5], 'sort' => 1]]],
        'rules' => [['key' => 'renovation', 'title' => 'שיפוץ', 'when' => ['all' => [['field' => 'flags.renovation_planned', 'op' => 'is', 'value' => true]]],
            'then' => [['do' => 'add_requirement', 'item' => req('works_quote', ['doc_type' => 'works_quote', 'title' => 'פירוט עבודות'])], ['do' => 'add_task', 'item' => ['key' => 'review_works', 'title' => 'בדיקת פירוט העבודות']],
                ['do' => 'alert', 'text' => 'לבדוק היתר']]]]];
}
function publish(string $as, string $pid, array $content, string $note = ''): int
{
    [, $d] = call('POST', 'profile-draft-open', ['profile' => $pid], $as);
    $s = call('POST', 'profile-draft-save', ['profile' => $pid, 'content' => $content, 'updated_at' => $d['draft']['updated_at']], $as);
    $r = call('POST', 'profile-publish', ['profile' => $pid, 'note' => $note], $as);
    if ($r[0] !== 200) throw new RuntimeException('publish: ' . json_encode([$s[1], $r[1]], JSON_UNESCAPED_UNICODE));
    return $r[1]['number'];
}
function make_profile(string $as, string $name, ?array $content = null): string
{
    $pid = call('POST', 'profile-create', ['scope' => 'office', 'name' => $name], $as)[1]['profile'];
    publish($as, $pid, $content ?? content());
    return $pid;
}
/** טיוטת תיק עם כל מה שנדרש ליציאה מטיוטה; $start — גם "יצירה". מחזיר pid. */
function make_case(string $as, string $profile, array $opt = []): string
{
    $r = call('POST', 'case-create', ['profile' => $profile] + array_intersect_key($opt, ['responsible' => 0]), $as);
    if ($r[0] !== 200) throw new RuntimeException('case-create: ' . json_encode($r[1], JSON_UNESCAPED_UNICODE));
    $c = $r[1]['case'];
    call('POST', 'case-update', ['case' => $c, 'title' => $opt['title'] ?? 'דירה ברחוב הרצל', 'purpose' => 'purchase', 'flags' => $opt['flags'] ?? []], $as);
    call('POST', 'case-identifier-add', ['case' => $c, 'kind' => 'parcel', 'gush' => $opt['gush'] ?? '6158', 'helka' => $opt['helka'] ?? '12', 'tat_helka' => $opt['tat'] ?? ''], $as);
    call('POST', 'case-party-add', ['case' => $c, 'name' => $opt['client'] ?? 'משה לקוח', 'kind' => 'client', 'is_orderer' => true], $as);
    if ($opt['start'] ?? true) {
        $s = call('POST', 'case-start', ['case' => $c], $as);
        if ($s[0] !== 200) throw new RuntimeException('case-start: ' . json_encode($s[1], JSON_UNESCAPED_UNICODE));
    }
    return $c;
}
$P = make_profile($avi, 'דירה לרכישה');
$crow = fn(string $pid) => apr_row('SELECT * FROM cases WHERE pid = ?', [$pid]);
$reqs = fn(string $pid) => apr_rows('SELECT * FROM requirements WHERE case_id = ? ORDER BY id', [$crow($pid)['id']]);
$tasks = fn(string $pid) => apr_rows('SELECT * FROM tasks WHERE case_id = ? ORDER BY id', [$crow($pid)['id']]);
$view = fn(string $as, string $pid) => call('GET', 'case', [], $as, ['case' => $pid]);
$log = fn(string $pid, string $action) => apr_rows('SELECT * FROM audit_log WHERE case_id = ? AND action = ? ORDER BY id', [$crow($pid)['id'], $action]);

// ── 04-A6, 04-A8: טיוטה, ומה חסר ──
[$st, $r] = call('POST', 'case-create', ['profile' => $P], $avi);
$C = $r['case'];
eq([$st, preg_match('/^\d{4}-0001$/', $r['number']), $crow($C)['status'], (int)$crow($C)['profile_version_id'] > 0, (int)$crow($C)['responsible_user_id'] > 0], [200, 1, 'draft', true, true], '04-A6 טיוטה נשמרת עם פרופיל ואחראי בלבד; המספר מוקצה כבר בטיוטה');
[, $v] = $view($avi, $C);
eq([$v['case']['key_date'], $v['case']['due_date'], $v['case']['purpose'], $v['case']['property_type'], $crow($C)['key_date']], [null, null, null, null, null], '04-A8 key_date שלא הוזן נשמר NULL ומוחזר null');
call('POST', 'case-update', ['case' => $C, 'key_date' => '', 'due_date' => '', 'title' => 'x'], $avi);
eq([$view($avi, $C)[1]['case']['key_date'], $crow($C)['key_date'], $crow($C)['due_date']], [null, null, null], '04-A8 מחרוזת ריקה אינה נשמרת — נשאר NULL');
eq([code(call('POST', 'case-update', ['case' => $C, 'key_date' => '2026-13-40'], $avi)), call('POST', 'case-update', ['case' => $C, 'key_date' => '2026-05-01'], $avi)[0], $view($avi, $C)[1]['case']['key_date']], ['date_invalid', 200, '2026-05-01'], 'תאריך לא תקין נדחה; תקין נשמר');
call('POST', 'case-update', ['case' => $C, 'key_date' => null], $avi);
eq($crow($C)['key_date'], null, '04-A8 ואפשר לחזור ל"לא ידוע"');
$r = call('POST', 'case-start', ['case' => $C], $avi);
eq([$r[0], code($r), $r[1]['error']['missing'] ?? null, $crow($C)['status'], count($reqs($C))], [422, 'case_incomplete', ['purpose', 'identifier', 'orderer'], 'draft', 0], '04-A6 יציאה מטיוטה בלי מטרה, מזהה ומזמין → נדחית, עם רשימת החסרים');
call('POST', 'case-update', ['case' => $C, 'purpose' => 'purchase'], $avi);
eq(call('POST', 'case-start', ['case' => $C], $avi)[1]['error']['missing'], ['identifier', 'orderer'], '04-A6 בלי מזהה ומזמין');
call('POST', 'case-identifier-add', ['case' => $C, 'kind' => 'address', 'locality' => 'תל אביב', 'street' => 'הרצל', 'house_no' => '10', 'apartment_no' => '4', 'source_note' => 'נמסר מהלקוח'], $avi);
eq(call('POST', 'case-start', ['case' => $C], $avi)[1]['error']['missing'], ['orderer'], '04-A6 בלי מזמין');
call('POST', 'case-party-add', ['case' => $C, 'name' => 'רות לקוחה', 'kind' => 'client'], $avi);
eq(call('POST', 'case-start', ['case' => $C], $avi)[1]['error']['missing'], ['orderer'], '04-A6 גורם שאינו המזמין לא מספיק');
[, $pa] = call('POST', 'case-party-add', ['case' => $C, 'name' => 'עו"ד כהן', 'kind' => 'lawyer', 'is_orderer' => true], $avi);
eq([code(call('POST', 'case-update', ['case' => $C, 'purpose' => 'other'], $avi)), call('POST', 'case-update', ['case' => $C, 'purpose' => 'other', 'purpose_note' => 'ייעוץ'], $avi)[0]], ['purpose_note_required', 200], 'מטרה "אחר" מחייבת הערה');
call('POST', 'case-update', ['case' => $C, 'purpose' => 'purchase', 'flags' => ['renovation_planned' => true]], $avi);

// ── 04-A1, 04-A2: היצירה ──
[, $plan] = call('GET', 'case-plan', [], $avi, ['case' => $C]);
eq([array_column($plan['plan']['requirements'], 'key'), $plan['missing']], [['rights', 'contract', 'works_quote'], []], '§4.5 תוכנית העבודה — אותו מנוע של תצוגת הניסיון');
[$st, $s] = call('POST', 'case-start', ['case' => $C], $avi);
$rs = array_column($reqs($C), null, 'origin_key');
eq([$st, $crow($C)['status'], array_keys($rs), array_column($tasks($C), 'origin_key')], [200, 'collecting', ['rights', 'contract', 'works_quote'], ['check_rights', 'review_works']], '04-A1 נוצרות הדרישות והמשימות שבגרסה');
$verId = (int)apr_val('SELECT current_version_id FROM profiles WHERE pid = ?', [$P]);
eq([(int)$crow($C)['profile_version_id'], $rs['rights']['origin'], (int)$rs['rights']['mandatory'], $rs['rights']['status'], $rs['rights']['due_at'] !== null], [$verId, 'profile', 1, 'required', true], '04-A1 profile_version_id נשמר; הדרישות לפי הגרסה');
eq([$rs['works_quote']['origin'], apr_row("SELECT kind FROM tasks WHERE origin_key = 'review_works'")['kind']], ['rule', 'rule'], '04-A2 עם הדגל: הדרישה והמשימה של הכלל נוצרות עם origin = rule');
$lawyer = (int)apr_val('SELECT id FROM case_parties WHERE pid = ?', [$pa['party']]);
eq([(int)$rs['contract']['party_id'], $rs['rights']['party_id'] !== null, (int)$tasks($C)[0]['assignee_user_id'] === (int)$crow($C)['responsible_user_id']], [$lawyer, true, true], 'הדרישה מוקצית לגורם מהסוג שלה; המשימה — לאחראי');
$snap = json_decode($crow($C)['plan_snapshot'], true);
eq([$snap['version'], array_column($snap['requirements'], 'origin'), $snap['rules'], $snap['alerts'][0]['text'], $snap['input']['flags']], [1, ['profile', 'profile', 'rule'], ['renovation' => 'שיפוץ'], 'לבדוק היתר', ['renovation_planned' => true]], 'plan_snapshot: מה נוצר, אילו כללים הופעלו, ועם איזה קלט');
$C2 = make_case($avi, $P, ['gush' => '7000', 'helka' => '1']);
eq([array_column($reqs($C2), 'origin_key'), array_column($tasks($C2), 'origin_key')], [['rights', 'contract'], ['check_rights']], '04-A2 בלי הדגל — הדרישה של הכלל לא נוצרת');
eq(code(call('POST', 'case-start', ['case' => $C], $avi)), 'case_state', 'תיק שכבר נוצר לא נוצר שוב');
// התאמות לפני היצירה
$C3 = make_case($avi, $P, ['gush' => '7001', 'helka' => '1', 'start' => false]);
eq(code(call('POST', 'case-start', ['case' => $C3, 'plan' => ['skip' => ['contract' => '']]], $avi)), 'reason_required', '§4.5 "לא רלוונטי" — רק עם נימוק');
eq(count($reqs($C3)), 0, 'והיצירה כולה לא קרתה');
call('POST', 'case-start', ['case' => $C3, 'plan' => ['skip' => ['contract' => 'אין הסכם — ירושה'], 'set' => ['rights' => ['priority' => 'high', 'party_kind' => 'lawyer']], 'add' => [['title' => 'צו ירושה', 'mandatory' => true]]]], $avi);
$r3 = array_column($reqs($C3), null, 'title');
eq([array_keys($r3), $r3['דרישה rights']['priority'], $r3['דרישה rights']['party_kind'], $r3['צו ירושה']['origin'], json_decode($crow($C3)['plan_snapshot'], true)['skipped']],
    [['דרישה rights', 'צו ירושה'], 'high', 'lawyer', 'manual', [['key' => 'contract', 'title' => 'דרישה contract', 'reason' => 'אין הסכם — ירושה']]], '§4.5 התאמה לפני היצירה: דילוג עם נימוק, שינוי עדיפות וגורם, ודרישה ידנית');

// ── 04-A3, 04-A5: התיק והפרופיל לא משפיעים זה על זה ──
$dump = fn(string $pid) => [$crow($pid), $reqs($pid), $tasks($pid), apr_rows('SELECT * FROM case_identifiers WHERE case_id = ?', [$crow($pid)['id']]), apr_rows('SELECT * FROM case_parties WHERE case_id = ?', [$crow($pid)['id']]), apr_rows('SELECT * FROM case_members WHERE case_id = ?', [$crow($pid)['id']])];
$before = [$dump($C), $dump($C2)];
$v2content = content(['requirements' => ['groups' => [], 'items' => [req('rights', ['mandatory' => true, 'title' => 'נסח רישום עדכני', 'priority' => 'high']), req('survey', ['doc_type' => 'survey', 'sort' => 3])]],
    'tasks' => ['items' => [['key' => 'check_rights', 'title' => 'בדיקת זכויות מעמיקה', 'assignee' => 'responsible', 'sort' => 1], ['key' => 'visit', 'title' => 'תיאום ביקור', 'assignee' => 'responsible', 'sort' => 2]]]]);
eq(publish($avi, $P, $v2content, 'גרסה 2'), 2, 'הפרופיל מפרסם גרסה 2');
eq([$dump($C), $dump($C2)], $before, '04-A3 פרסום גרסה חדשה בפרופיל אינו משנה אף שורה בתיק קיים');
eq([$view($avi, $C)[1]['profile']['version'], $view($avi, $C)[1]['profile']['newer']], [1, 2], '§8 בכרטיס: "לפרופיל גרסה חדשה (2)"');
$versions = fn() => apr_rows('SELECT id, content, state, number FROM profile_versions ORDER BY id');
$pv = $versions();
$rightsPid = $rs['rights']['pid'];
eq(call('POST', 'requirement-update', ['requirement' => $rightsPid, 'title' => 'נסח — שונה בתיק', 'priority' => 'low', 'mandatory' => false], $avi)[0], 200, 'משנים דרישה בתיק');
call('POST', 'requirement-add', ['case' => $C, 'title' => 'דרישה ידנית'], $avi);
eq([$versions(), apr_val('SELECT title FROM requirements WHERE pid = ?', [$rightsPid])], [$pv, 'נסח — שונה בתיק'], '04-A5 שינוי דרישה בתיק אינו משנה את content של שום גרסת פרופיל');

// ── 04-A4: החלת גרסה ──
call('POST', 'requirement-update', ['requirement' => $rightsPid, 'title' => 'דרישה rights', 'priority' => 'normal', 'mandatory' => true], $avi);
apr_exec("UPDATE requirements SET status = 'received' WHERE case_id = ? AND origin_key = 'contract'", [$crow($C)['id']]);   // כבר טופלה (המצבים עצמם — פרוסה 06)
[$st, $d] = call('GET', 'case-profile-diff', [], $avi, ['case' => $C]);
eq([$st, $d['diff']['from'], $d['diff']['to'], array_column($d['diff']['requirements']['added'], 'key'), array_column($d['diff']['requirements']['changed'], 'key'), array_column($d['diff']['requirements']['removed'], 'key')],
    [200, 1, 2, ['survey'], ['rights'], ['contract']], '§8 תצוגת הבדלים: יתווסף / השתנה / הוסר');
eq([$d['diff']['requirements']['changed'][0]['fields'], array_column($d['diff']['tasks']['added'], 'key'), array_column($d['diff']['tasks']['changed'], 'key')],
    [['title' => ['case' => 'דרישה rights', 'profile' => 'נסח רישום עדכני'], 'priority' => ['case' => 'normal', 'profile' => 'high']], ['visit'], ['check_rights']], '§8 הערך בתיק ליד הערך החדש');
$nReq = count($reqs($C));
$nTask = count($tasks($C));
$contractBefore = apr_row("SELECT * FROM requirements WHERE case_id = ? AND origin_key = 'contract'", [$crow($C)['id']]);
[$st, $ap] = call('POST', 'case-profile-apply', ['case' => $C, 'requirements_add' => ['survey', 'no_such'], 'requirements_change' => ['contract'], 'tasks_add' => []], $avi);
$after = array_column($reqs($C), null, 'origin_key');
eq([$st, $ap['applied'], count($reqs($C)) - $nReq, count($tasks($C)) - $nTask, isset($after['survey'])], [200, ['requirements_add' => 1, 'tasks_add' => 0, 'requirements_change' => 0, 'tasks_change' => 0], 1, 0, true], '04-A4 רק הפריטים שסומנו מתווספים');
eq([$after['rights']['title'], $after['rights']['priority'], apr_row('SELECT * FROM requirements WHERE id = ?', [$contractBefore['id']])], ['דרישה rights', 'normal', $contractBefore], '04-A4 מה שלא סומן לא משתנה; דרישה שכבר אינה required — לא משתנה גם כשסומנה');
eq([count($reqs($C)) >= $nReq, isset($after['contract']), isset($after['works_quote'])], [true, true, true], '04-A4 שום שורה לא נמחקת — גם מה שהוסר מהפרופיל');
$snap = json_decode($crow($C)['plan_snapshot'], true);
eq([(int)$crow($C)['profile_version_id'] === (int)apr_val('SELECT current_version_id FROM profiles WHERE pid = ?', [$P]), count($snap['applications']), array_intersect_key($snap['applications'][0], ['from' => 0, 'to' => 0, 'requirements_add' => 0, 'by' => 0])],
    [true, 1, ['by' => $aviPid, 'from' => 1, 'to' => 2, 'requirements_add' => ['survey']]], '04-A4 profile_version_id מתעדכן, ו-plan_snapshot מקבל רשומת החלה (מה, מי)');
eq([count($log($C, 'case.profile_apply')), $view($avi, $C)[1]['profile']['newer'], in_array(false, array_column($view($avi, $C)[1]['requirements'], 'in_profile'), true)], [1, null, true], 'נרשם ביומן; "אינה בגרסה החדשה" מסומנת');
call('POST', 'case-profile-apply', ['case' => $C, 'requirements_change' => ['rights'], 'tasks_change' => ['check_rights'], 'tasks_add' => ['visit']], $avi);
eq([apr_val("SELECT title FROM requirements WHERE case_id = ? AND origin_key = 'rights'", [$crow($C)['id']]), apr_val("SELECT title FROM tasks WHERE case_id = ? AND origin_key = 'check_rights'", [$crow($C)['id']]), count($tasks($C)) - $nTask],
    ['נסח רישום עדכני', 'בדיקת זכויות מעמיקה', 1], '04-A4 שינוי שסומן — מוחל על מה שעוד לא טופל');
// מקרה 9: שינוי דגל אחרי הפתיחה — הכללים לא רצים לבד
call('POST', 'case-update', ['case' => $C2, 'flags' => ['renovation_planned' => true]], $avi);
call('POST', 'case-profile-apply', ['case' => $C2], $avi);
eq([in_array('works_quote', array_column($reqs($C2), 'origin_key'), true), array_column(call('GET', 'case-profile-diff', [], $avi, ['case' => $C2])[1]['diff']['requirements']['added'], 'key')], [false, ['works_quote']], 'מקרה 9: דגל שסומן אחרי הפתיחה — אין הוספה אוטומטית; ההבדלים מציעים אותה');

// ── 04-A7: 100 פתיחות מקבילות ──
$dir = sys_get_temp_dir() . '/apr-test-conc-' . bin2hex(random_bytes(5));
mkdir($dir, 0700, true);
$setup = 'define("APR_DATA_DIR", ' . var_export($dir, true) . '); define("APR_DB_PATH", ' . var_export("$dir/app.sqlite", true) . '); require ' . var_export(__DIR__ . '/lib.php', true) . ';'
    . ' $a = ident("2", "אבי", "avi@example.com"); office_for($a, "x"); $p = call("POST", "profile-create", ["scope" => "office", "name" => "p"], $a)[1]["profile"];'
    . ' $d = call("POST", "profile-draft-open", ["profile" => $p], $a)[1]; call("POST", "profile-publish", ["profile" => $p], $a); echo $p;';
$pp = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($setup)));
$procs = [];
for ($i = 0; $i < 10; $i++) $procs[] = proc_open([PHP_BINARY, __FILE__, 'worker', $dir, $pp, '10'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
$numbers = [];
foreach ($procs as $i => $proc) {
    $numbers = array_merge($numbers, json_decode((string)stream_get_contents($pipes[$i][1]), true) ?: ['ERR no output: ' . stream_get_contents($pipes[$i][2])]);
    proc_close($proc);
}
sort($numbers);
$year = substr($numbers[0], 0, 4);
eq([count($numbers), count(array_unique($numbers)), $numbers === array_map(fn($n) => sprintf('%s-%04d', $year, $n), range(1, 100))], [100, 100, true], '04-A7 100 פתיחות מקבילות (10 תהליכים) → 100 מספרים שונים ורצופים');
foreach (glob("$dir/*") ?: [] as $f) @unlink($f);
@rmdir($dir);
// מקרה 1: מספר של טיוטה שנמחקה אינו חוזר לשימוש; מקרה 2: שנה חדשה מתחילה מ-0001
$tmp = call('POST', 'case-create', ['profile' => $P], $avi)[1];
call('POST', 'case-delete', ['case' => $tmp['case']], $avi);
$next = call('POST', 'case-create', ['profile' => $P], $avi)[1];
eq([(int)substr($next['number'], 5) - (int)substr($tmp['number'], 5), $view($avi, $tmp['case'])[0], code(call('POST', 'case-delete', ['case' => $C], $avi))], [1, 404, 'case_state'], 'מקרים 1, 10: מספר של טיוטה שנמחקה אינו חוזר; תיק שיצא מטיוטה אינו נמחק');
$GLOBALS['apr_time'] = strtotime('2027-12-31 22:30:00 UTC');   // בישראל כבר 2028
eq(call('POST', 'case-create', ['profile' => $P], $avi)[1]['number'], '2028-0001', 'מקרה 2: השנה לפי שעון ישראל, והמונה מתחיל מ-0001');
unset($GLOBALS['apr_time']);
eq([call('POST', 'case-create', ['profile' => $P, 'op' => 'op-case-0001'], $avi)[1]['case'], count(apr_rows("SELECT 1 FROM audit_log WHERE action = 'case.create' AND json_extract(meta, '$.op') = 'op-case-0001'"))],
    [call('POST', 'case-create', ['profile' => $P, 'op' => 'op-case-0001'], $avi)[1]['case'], 1], 'לחיצה כפולה (אותו מזהה פעולה) = תיק אחד');

// ── 04-B1: בידוד בין משרדים ──
$ident = apr_row('SELECT pid FROM case_identifiers WHERE case_id = ?', [$crow($C)['id']])['pid'];
$party = $pa['party'];
$taskPid = $tasks($C)[0]['pid'];
$contactPid = (string)apr_val('SELECT pid FROM contacts WHERE office_id = ? LIMIT 1', [$crow($C)['office_id']]);
$calls = [['GET', 'case', []], ['GET', 'case-plan', []], ['GET', 'case-activity', []], ['GET', 'case-profile-diff', []], ['POST', 'case-update', ['title' => 'x']], ['POST', 'case-start', []],
    ['POST', 'case-status', ['status' => 'checking']], ['POST', 'case-delete', []], ['POST', 'case-member-add', ['user' => $danaPid]], ['POST', 'case-member-update', ['user' => $aviPid, 'perms' => ['edit' => false]]],
    ['POST', 'case-member-remove', ['user' => $aviPid]], ['POST', 'case-profile-apply', ['requirements_add' => ['survey']]], ['POST', 'case-identifier-add', ['kind' => 'other', 'value_text' => 'x']],
    ['POST', 'case-identifier-update', ['identifier' => $ident, 'state' => 'needs_check']], ['POST', 'case-identifier-remove', ['identifier' => $ident]], ['POST', 'case-identifiers-conflict', []],
    ['POST', 'case-party-add', ['name' => 'x', 'kind' => 'client']], ['POST', 'case-party-update', ['party' => $party, 'is_orderer' => false]], ['POST', 'case-party-remove', ['party' => $party]],
    ['POST', 'task-create', ['title' => 'x']], ['POST', 'task-update', ['task' => $taskPid, 'status' => 'done']], ['POST', 'requirement-add', ['title' => 'x']],
    ['POST', 'requirement-update', ['requirement' => $rightsPid, 'title' => 'x']], ['POST', 'contact-save', ['contact' => $contactPid, 'name' => 'x']], ['POST', 'contact-delete', ['contact' => $contactPid]],
    ['POST', 'office-case-transfer', ['user' => $danaPid, 'reason' => 'x']]];
$isolation = function (string $who, string $as) use ($calls, $C, $dump) {
    $before = [$dump($C), (int)apr_val('SELECT COUNT(*) FROM tasks'), (int)apr_val('SELECT COUNT(*) FROM requirements'), apr_rows('SELECT * FROM contacts')];
    foreach ($calls as [$m, $action, $in]) {
        if ($who !== '04-B1 משרד ב׳' && in_array($action, ['contact-save', 'contact-delete', 'office-case-transfer'], true)) continue;   // ספר הכתובות משרדי; העברת אחריות — מנהל
        $r = $m === 'GET' ? call('GET', $action, [], $as, ['case' => $C] + $in) : call('POST', $action, ['case' => $C] + $in, $as);
        eq([$r[0], code($r)], [404, 'not_found'], "$who: $action על תיק של משרד א׳ → 404");
    }
    eq([$dump($C), (int)apr_val('SELECT COUNT(*) FROM tasks'), (int)apr_val('SELECT COUNT(*) FROM requirements'), apr_rows('SELECT * FROM contacts')], $before, "$who: ושום דבר לא השתנה");
};
$isolation('04-B1 משרד ב׳', $boaz);
foreach ([[], ['q' => 'הרצל'], ['q' => '6158'], ['q' => $crow($C)['number']], ['q' => 'כהן'], ['closed' => '1']] as $q) eq(call('GET', 'cases', [], $boaz, $q)[1]['cases'], [], '04-B1 התיק אינו ברשימה ובחיפוש של משרד ב׳ (' . json_encode($q, JSON_UNESCAPED_UNICODE) . ')');
eq([call('GET', 'my-tasks', [], $boaz)[1]['tasks'], call('GET', 'contacts', [], $boaz)[1]['contacts'], call('GET', 'office-cases', [], $boaz)[1]['cases']], [[], [], []], '04-B1 וגם לא המשימות, אנשי הקשר ושורות הניהול');
eq(code($view($boaz, 'aaaaaaaaaaaaaaaa')), 'not_found', 'תיק שלא קיים — אותה תשובה');

// ── 04-B2: חבר במשרד שאינו משתתף ──
$isolation('04-B2 חברה במשרד שאינה משתתפת', $dana);
foreach ([[], ['q' => 'הרצל'], ['q' => '6158 12'], ['q' => $crow($C)['number']]] as $q) eq(call('GET', 'cases', [], $dana, $q)[1]['cases'], [], '04-B2 התיק אינו ברשימה ובחיפוש שלה (' . json_encode($q, JSON_UNESCAPED_UNICODE) . ')');
foreach ([['q' => 'הרצל'], ['q' => '6158 12'], ['q' => 'גוש 6158 חלקה 12'], ['q' => $crow($C)['number']], ['q' => 'כהן'], ['q' => 'תל אביב']] as $q) {
    check('§9 חיפוש של משתתף מוצא את התיק (' . $q['q'] . ')', in_array($C, array_column(call('GET', 'cases', [], $avi, $q)[1]['cases'], 'pid'), true));
}
eq(call('GET', 'cases', [], $avi, ['q' => 'אין דבר כזה בכלל'])[1]['cases'], [], '§9 אין תוצאות — רשימה ריקה, אותה תשובה כמו לתיק שאינו מורשה');

// ── 04-B3, 04-B4: מנהל משרד שאינו משתתף ──
$D = make_case($dana, $P, ['gush' => '8000', 'helka' => '5', 'title' => 'סודי — וילה בהרצליה', 'client' => 'לקוח סודי']);
[$st, $oc] = call('GET', 'office-cases', [], $avi);
$row = array_values(array_filter($oc['cases'], fn($c) => $c['pid'] === $D))[0];
eq([$st, array_keys($row), $row['responsible'], $row['status']], [200, ['pid', 'number', 'responsible', 'status', 'due_date', 'updated_at'], 'דנה', 'collecting'], '04-B3 שורת הניהול: רק מספר, אחראי, מצב, יעד ועדכון');
check('04-B3 בלי כותרת, נכס וגורמים', !preg_match('/סודי|וילה|8000/u', json_encode($oc, JSON_UNESCAPED_UNICODE)));
eq([$view($avi, $D)[0], call('GET', 'cases', [], $avi, ['q' => 'וילה'])[1]['cases'], code(call('POST', 'case-member-add', ['case' => $D, 'user' => $aviPid], $avi))], [404, [], 'not_found'], '04-B3 פתיחת התיק → 404; לא בחיפוש; ואינו יכול להוסיף את עצמו');
eq(code(call('GET', 'office-cases', [], $dana)), 'forbidden', 'שורות הניהול — רק למנהל משרד');
eq([code(call('POST', 'office-case-transfer', ['case' => $D, 'user' => $aviPid], $avi)), code(call('POST', 'office-case-transfer', ['case' => $D, 'user' => $aviPid, 'reason' => '  '], $avi)), (int)$crow($D)['responsible_user_id'] === (int)apr_val('SELECT id FROM users WHERE pid = ?', [$danaPid])],
    ['reason_required', 'reason_required', true], '04-B4 העברת אחריות בלי נימוק → נדחית');
eq([code(call('POST', 'office-case-transfer', ['case' => $D, 'user' => $talPid, 'reason' => 'x'], $avi)), code(call('POST', 'office-case-transfer', ['case' => $D, 'user' => $danaPid, 'reason' => 'x'], $dana))], ['responsible_invalid', 'forbidden'], 'רק לשמאי; ורק מנהל משרד מעביר');
eq(call('POST', 'office-case-transfer', ['case' => $D, 'user' => $aviPid, 'reason' => 'דנה בחופשה'], $avi)[0], 200, '04-B4 עם נימוק → מתבצעת');
$t = $log($D, 'case.responsible');
$mem = array_column($view($avi, $D)[1]['members'], 'role', 'pid');
eq([count($t), $t[0]['reason'], $mem[$aviPid], $mem[$danaPid], $view($dana, $D)[1]['me']['perms']['members']], [1, 'דנה בחופשה', 'responsible', 'partner', false], '04-B4 ונרשמת; האחראי הקודם נשאר שותף');
call('POST', 'office-case-transfer', ['case' => $D, 'user' => $danaPid, 'reason' => 'חזרה'], $avi);
call('POST', 'case-member-remove', ['case' => $D, 'user' => $aviPid], $dana);

// ── 04-B5: לכל הרשאה — נדחית בלי, מתקבלת עם ──
call('POST', 'case-member-add', ['case' => $D, 'user' => $talPid], $dana);
call('POST', 'case-member-add', ['case' => $D, 'user' => $noamPid], $dana);
$mv = array_column($view($dana, $D)[1]['members'], null, 'pid');
eq([$mv[$talPid]['role'], array_keys(array_filter($mv[$talPid]['perms'])), $mv[$noamPid]['role'], $mv[$noamPid]['perms']['tasks'], $mv[$noamPid]['perms']['documents'], $mv[$noamPid]['perms']['edit']],
    ['staff', ['view', 'tasks', 'documents', 'sources', 'contact'], 'visitor', 'own', true, false], '§6 ברירות המחדל: עובד משרד; מתמחה = מבקר + דרישות');
eq(array_keys(array_filter(apr_case_default_perms('partner'))), ['view', 'edit', 'tasks', 'documents', 'review_docs', 'sources', 'visit', 'contact'], '§6 שותף: בלי members, approve_report ו-export');
eq([apr_case_default_perms('responsible')['approve_report'], apr_case_default_perms('partner')['approve_report']], [true, false], '§6 approve_report אינו נגזר מהיות המשתמש שמאי');
$dReq = $reqs($D)[0]['pid'];
$dTask = $tasks($D)[0]['pid'];
$dIdent = apr_row('SELECT pid FROM case_identifiers WHERE case_id = ?', [$crow($D)['id']])['pid'];
$guarded = [
    'edit' => [['case-update', ['title' => 'כותרת']], ['case-status', ['status' => 'checking']], ['case-identifier-add', ['kind' => 'other', 'value_text' => 'תיק בניין 5']],
        ['case-identifier-update', ['identifier' => $dIdent, 'state' => 'needs_check']], ['case-party-add', ['name' => 'בנק', 'kind' => 'bank']], ['case-profile-apply', []]],
    'tasks' => [['task-create', ['title' => 'משימה']], ['task-update', ['task' => $dTask, 'title' => 'שם חדש']]],
    'documents' => [['requirement-add', ['title' => 'דרישה']], ['requirement-update', ['requirement' => $dReq, 'priority' => 'high']]],
    'members' => [['case-member-add', ['user' => $aviPid]], ['case-member-update', ['user' => $noamPid, 'perms' => ['visit' => false]]]],
];
foreach ($guarded as $perm => $actions) {
    foreach ($actions as [$action, $in]) {
        call('POST', 'case-member-update', ['case' => $D, 'user' => $talPid, 'perms' => [$perm => false]], $dana);
        $r = call('POST', $action, ['case' => $D] + $in, $tal);
        eq([$r[0], code($r)], [403, 'forbidden'], "04-B5 $perm: $action נדחית למשתתף בלי ההרשאה");
        call('POST', 'case-member-update', ['case' => $D, 'user' => $talPid, 'perms' => [$perm => true]], $dana);
        eq(call('POST', $action, ['case' => $D] + $in, $tal)[0], 200, "04-B5 $perm: $action מתקבלת למי שקיבל אותה");
        if ($action === 'case-member-add') call('POST', 'case-member-remove', ['case' => $D, 'user' => $aviPid], $dana);
    }
}
eq(array_values(array_diff(APR_CASE_PERMS, array_keys($guarded), ['view', 'review_docs', 'sources', 'visit', 'contact', 'approve_report', 'export'])), [], '04-B5 כל הרשאה שיש לה פעולה בפרוסה הזו נבדקה (השאר — בפרוסות שלהן)');
eq([$view($tal, $D)[0], call('POST', 'case-member-update', ['case' => $D, 'user' => $talPid, 'perms' => ['view' => false]], $dana)[0], $view($tal, $D)[0]], [200, 200, 200], 'view אינו ניתן לכיבוי — מי שלא רואה מוסר מהתיק');
// tasks: 'own'
$own = call('POST', 'task-create', ['case' => $D, 'title' => 'לצלם את החזית', 'assignee' => $noamPid], $dana)[1]['task'];
eq([code(call('POST', 'task-update', ['task' => $dTask, 'status' => 'done'], $noam)), code(call('POST', 'task-create', ['case' => $D, 'title' => 'x'], $noam)), call('POST', 'task-update', ['task' => $own, 'status' => 'done', 'title' => 'שם אחר'], $noam)[0],
    apr_row('SELECT status, title FROM tasks WHERE pid = ?', [$own])], ['forbidden', 'forbidden', 200, ['status' => 'done', 'title' => 'לצלם את החזית']], '04-B5 tasks "שלו": מבקר מעדכן מצב רק במשימה שלו, ואינו יוצר או משנה אחרת');
eq([code(call('POST', 'case-member-update', ['case' => $D, 'user' => $danaPid, 'perms' => ['export' => false]], $dana)), code(call('POST', 'case-member-remove', ['case' => $D, 'user' => $danaPid], $dana)),
    code(call('POST', 'case-member-update', ['case' => $D, 'user' => $talPid, 'role' => 'responsible'], $dana))], ['responsible_fixed', 'responsible_fixed', 'responsible_invalid'], '§6 בדיוק אחראי אחד, שמאי: לא מצמצמים, לא מסירים, ולא ממנים מי שאינו שמאי');
eq(code(call('POST', 'case-create', ['profile' => $P], $tal)), 'responsible_invalid', '§4.1 מי שאינו שמאי פותח תיק רק עם שמאי אחראי');
$T = call('POST', 'case-create', ['profile' => $P, 'responsible' => $danaPid], $tal)[1]['case'];
eq([array_column($view($tal, $T)[1]['members'], 'role', 'pid'), $view($dana, $T)[0]], [[$danaPid => 'responsible', $talPid => 'staff'], 200], 'עובדת משרד פותחת תיק לשמאית: היא משתתפת, והשמאית אחראית');

// ── משימות ──
eq(code(call('POST', 'task-create', ['case' => $D, 'title' => 'x', 'assignee' => $aviPid], $dana)), 'assignee_not_member', '§7 משימה למי שאינו משתתף בתיק — "הוסיפו אותו לתיק קודם"');
eq([code(call('POST', 'task-update', ['task' => $dTask, 'status' => 'cancelled'], $dana)), apr_val('SELECT status FROM tasks WHERE pid = ?', [$dTask])], ['reason_required', 'open'], '04-C1 ביטול משימה בלי נימוק → נדחה');
eq([call('POST', 'task-update', ['task' => $dTask, 'status' => 'cancelled', 'reason' => 'כפילות'], $dana)[0], apr_row('SELECT status, cancel_reason FROM tasks WHERE pid = ?', [$dTask]), end($GLOBALS['x'] = $log($D, 'task.status'))['reason']],
    [200, ['status' => 'cancelled', 'cancel_reason' => 'כפילות'], 'כפילות'], '04-C1 עם נימוק → מתקבל, והנימוק ביומן');
$mine = call('POST', 'task-create', ['case' => $D, 'title' => 'לתאם ביקור', 'assignee' => $danaPid, 'due_at' => '2026-12-01'], $dana)[1]['task'];
eq([call('POST', 'task-update', ['task' => $mine, 'status' => 'done'], $dana)[0], apr_val('SELECT done_at FROM tasks WHERE pid = ?', [$mine]) !== null], [200, true], '§7 "בוצע" אינו דורש נימוק');
$open = call('POST', 'task-create', ['case' => $D, 'title' => 'לבדוק היתר', 'assignee' => $danaPid, 'due_at' => '2026-12-05'], $dana)[1]['task'];
[, $mt] = call('GET', 'my-tasks', [], $dana);
eq([in_array($open, array_column($mt['tasks'], 'pid'), true), in_array($mine, array_column($mt['tasks'], 'pid'), true), array_values(array_filter($mt['tasks'], fn($t) => $t['pid'] === $open))[0]['case']['pid'], call('GET', 'my-tasks', [], $avi)[1]['tasks'] !== $mt['tasks']],
    [true, false, $D, true], '§7 "המשימות שלי": הפתוחות שלי, מכל התיקים, עם התיק');

// ── 04-C1, 04-C2: מצבים ──
call('POST', 'case-status', ['case' => $D, 'status' => 'analysis'], $dana);
eq([$crow($D)['status'], code(call('POST', 'case-status', ['case' => $D, 'status' => 'collecting'], $dana)), $crow($D)['status']], ['analysis', 'reason_required', 'analysis'], '04-C1 מעבר מצב אחורה בלי נימוק → נדחה');
eq([call('POST', 'case-status', ['case' => $D, 'status' => 'collecting', 'reason' => 'חסר מסמך'], $dana)[0], $crow($D)['status'], array_map(fn($r) => [json_decode($r['meta'], true), $r['reason']], array_slice($log($D, 'case.status'), -1))],
    [200, 'collecting', [[['from' => 'analysis', 'to' => 'collecting'], 'חסר מסמך']]], '04-C1 עם נימוק → מתקבל; ביומן המצב הקודם, החדש והנימוק');
foreach (APR_CASE_STATUS_BY_REPORT as $s) eq([code(call('POST', 'case-status', ['case' => $D, 'status' => $s], $dana)), code(call('POST', 'case-status', ['case' => $D, 'status' => $s, 'reason' => 'x'], $dana)), $crow($D)['status']], ['status_not_manual', 'status_not_manual', 'collecting'], "04-C2 $s אינו ניתן לקביעה ידנית");
eq([code(call('POST', 'case-status', ['case' => $D, 'status' => 'draft', 'reason' => 'x'], $dana)), code(call('POST', 'case-status', ['case' => $D, 'status' => 'nope'], $dana))], ['case_state', 'status_invalid'], 'לא חוזרים לטיוטה; מצב לא מוכר נדחה');
check('יש בתיק דרישת חובה פתוחה ומשימה פתוחה', (bool)apr_val("SELECT 1 FROM requirements WHERE case_id = ? AND mandatory = 1 AND status = 'required'", [$crow($D)['id']]) && (bool)apr_val("SELECT 1 FROM tasks WHERE case_id = ? AND status = 'open'", [$crow($D)['id']]));
eq([code(call('POST', 'case-status', ['case' => $D, 'status' => 'closed'], $dana)), $crow($D)['status']], ['reason_required', 'collecting'], '04-C1 סגירה עם דרישת חובה פתוחה, בלי נימוק → נדחית');
apr_exec("UPDATE tasks SET status = 'done' WHERE case_id = ?", [$crow($D)['id']]);
eq(code(call('POST', 'case-status', ['case' => $D, 'status' => 'closed'], $dana)), 'reason_required', '04-C1 גם כשרק דרישת החובה פתוחה');
eq([call('POST', 'case-status', ['case' => $D, 'status' => 'closed', 'reason' => 'הלקוח ביטל'], $dana)[0], $crow($D)['status'], $crow($D)['close_reason'], $crow($D)['closed_at'] !== null], [200, 'closed', 'הלקוח ביטל', true], '04-C1 עם נימוק → נסגר, והנימוק נשמר');
call('POST', 'case-member-update', ['case' => $D, 'user' => $talPid, 'perms' => ['edit' => true]], $dana);
eq([code(call('POST', 'case-status', ['case' => $D, 'status' => 'collecting', 'reason' => 'x'], $tal)), code(call('POST', 'case-status', ['case' => $D, 'status' => 'collecting'], $dana)), call('POST', 'case-status', ['case' => $D, 'status' => 'collecting', 'reason' => 'הלקוח חזר'], $dana)[0], $crow($D)['closed_at']],
    ['forbidden', 'reason_required', 200, null], '§5 פתיחה מחדש של תיק סגור — רק האחראי, ועם נימוק');
$E = make_case($dana, $P, ['gush' => '8100', 'helka' => '1']);
apr_exec("UPDATE requirements SET mandatory = 0 WHERE case_id = ?", [$crow($E)['id']]);
apr_exec("UPDATE tasks SET status = 'done' WHERE case_id = ?", [$crow($E)['id']]);
eq(call('POST', 'case-status', ['case' => $E, 'status' => 'closed'], $dana)[0], 200, '§5 סגירה בלי דרישת חובה ובלי משימה פתוחה — בלי נימוק');
eq([in_array($E, array_column(call('GET', 'cases', [], $dana)[1]['cases'], 'pid'), true), in_array($E, array_column(call('GET', 'cases', [], $dana, ['closed' => '1'])[1]['cases'], 'pid'), true)], [false, true], 'תיק סגור אינו ברשימה הרגילה');

// ── 04-C3, מקרי 3–4, 04-B7: מזהים ──
$addr = apr_row("SELECT pid FROM case_identifiers WHERE case_id = ? AND kind = 'address'", [$crow($C)['id']])['pid'];
eq([code(call('POST', 'case-identifier-update', ['case' => $C, 'identifier' => $addr, 'state' => 'verified'], $avi)), apr_val('SELECT state FROM case_identifiers WHERE pid = ?', [$addr])], ['address_not_verifiable', 'entered'], '04-C3 כתובת שהמקור שלה הוא המשתמש אינה ניתנת לסימון verified');
eq([call('POST', 'case-identifier-update', ['case' => $C, 'identifier' => $addr, 'state' => 'needs_check'], $avi)[0], call('POST', 'case-identifier-update', ['case' => $C, 'identifier' => $addr, 'state' => 'verified', 'source_kind' => 'document'], $avi)[0],
    code(call('POST', 'case-identifier-update', ['case' => $C, 'identifier' => $addr, 'source_kind' => 'user'], $avi))], [200, 200, 'address_not_verifiable'], '04-C3 עם מקור שאינו המשתמש — כן; ואי אפשר להחזיר ל"משתמש" ולהשאיר מאומת');
$parcel = call('POST', 'case-identifier-add', ['case' => $C, 'kind' => 'parcel', 'gush' => '6158', 'helka' => '13'], $avi)[1];
eq([call('POST', 'case-identifier-update', ['case' => $C, 'identifier' => $parcel['identifier'], 'state' => 'verified'], $avi)[0], apr_row('SELECT tat_helka, apartment_no FROM case_identifiers WHERE pid = ?', [$parcel['identifier']])], [200, ['tat_helka' => null, 'apartment_no' => null]], 'מקרה 4: כמה חלקות; תת-חלקה ריקה נשארת NULL; חלקה כן ניתנת לאימות');
eq([code(call('POST', 'case-identifier-add', ['case' => $C, 'kind' => 'parcel', 'gush' => '61א', 'helka' => '1'], $avi)), code(call('POST', 'case-identifier-add', ['case' => $C, 'kind' => 'parcel', 'gush' => '6158'], $avi)), code(call('POST', 'case-identifier-add', ['case' => $C, 'kind' => 'address'], $avi))],
    ['identifier_invalid', 'identifier_empty', 'identifier_empty'], 'מזהה חסר או לא תקין נדחה');
// מזהה דומה: דנה פותחת תיק על אותה חלקה כמו התיק של אבי (C), שאין לה גישה אליו
$F = call('POST', 'case-create', ['profile' => $P], $dana)[1]['case'];
[$st, $sim] = call('POST', 'case-identifier-add', ['case' => $F, 'kind' => 'parcel', 'gush' => '6158', 'helka' => '12'], $dana);
$blob = json_encode($sim, JSON_UNESCAPED_UNICODE);
eq([$st, $sim['similar'], str_contains($blob, $C), str_contains($blob, $crow($C)['number'])], [200, ['exists' => true, 'cases' => []], false, false], '04-B7 מזהה דומה: למי שאין גישה לתיק הקיים — רק "קיים", בלי מספר ובלי pid');
[, $simA] = call('POST', 'case-identifier-add', ['case' => $C2, 'kind' => 'parcel', 'gush' => ' 6158 ', 'helka' => '12'], $avi);
eq(in_array(['pid' => $C, 'number' => $crow($C)['number']], $simA['similar']['cases'], true), true, '04-B7 למי שיש גישה — מוצג מספר התיק');
eq([call('POST', 'case-identifier-add', ['case' => $F, 'kind' => 'address', 'locality' => 'תל-אביב', 'street' => 'הרצל', 'house_no' => '10', 'apartment_no' => '4'], $dana)[1]['similar']['exists'] ?? null,
    call('POST', 'case-identifier-add', ['case' => $F, 'kind' => 'address', 'locality' => 'תל אביב', 'street' => 'הרצל', 'house_no' => '10', 'apartment_no' => '5'], $dana)[1]['similar'],
    call('POST', 'case-identifier-add', ['case' => $F, 'kind' => 'parcel', 'gush' => '6158', 'helka' => '12', 'tat_helka' => '3'], $dana)[1]['similar']], [true, null, null], 'מקרה 3: אותה כתובת ודירה — דומה; דירה אחרת או תת-חלקה אחרת — לא');
eq([call('POST', 'case-identifiers-conflict', ['case' => $F], $dana)[1]['task'] !== null, array_unique(array_column(apr_rows('SELECT state FROM case_identifiers WHERE case_id = ? AND removed_at IS NULL', [$crow($F)['id']]), 'state')),
    apr_row("SELECT kind, link_type FROM tasks WHERE case_id = ?", [$crow($F)['id']]), call('POST', 'case-identifiers-conflict', ['case' => $F], $dana)[1]['task']],
    [true, ['conflicting'], ['kind' => 'clarification', 'link_type' => 'identifiers'], null], '§4.2 "המזהים לא מתיישבים": מסומנים conflicting, ונוצרת משימת בירור אחת');

// ── אנשי קשר וגורמים (מקרים 6, 7) ──
[, $pv] = $view($avi, $C);
$ruth = array_values(array_filter($pv['parties'], fn($p) => $p['contact']['name'] === 'רות לקוחה'))[0];
call('POST', 'case-party-add', ['case' => $C, 'contact' => $ruth['contact']['pid'], 'kind' => 'client', 'is_orderer' => true], $avi);
$pv2 = $view($avi, $C)[1]['parties'];
eq([count($pv2), count($pv['parties']), array_column(array_filter($pv2, fn($p) => $p['is_orderer']), 'pid')], [count($pv['parties']), 2, [$ruth['pid']]], 'מקרה 7: לקוח שהוא גם המזמין — שורה אחת עם is_orderer; ויש מזמין אחד בתיק');
call('POST', 'case-party-add', ['case' => $C, 'contact' => $ruth['contact']['pid'], 'kind' => 'lawyer'], $avi);
eq(count($view($avi, $C)[1]['parties']), 3, 'מקרה 7: אותו אדם בשני תפקידים — שתי שורות');
call('POST', 'case-party-add', ['case' => $C2, 'contact' => $ruth['contact']['pid'], 'kind' => 'client'], $avi);
call('POST', 'contact-save', ['contact' => $ruth['contact']['pid'], 'name' => 'רות לקוחה-לוי', 'phone' => '050-0000000'], $dana);
eq([array_values(array_filter($view($avi, $C)[1]['parties'], fn($p) => $p['pid'] === $ruth['pid']))[0]['contact']['name'], in_array('רות לקוחה-לוי', array_map(fn($p) => $p['contact']['name'], $view($avi, $C2)[1]['parties']), true)],
    ['רות לקוחה-לוי', true], 'מקרה 6: עריכת איש קשר מתעדכנת בכל התיקים שהוא בהם');
eq([code(call('POST', 'contact-delete', ['contact' => $ruth['contact']['pid']], $avi)), in_array($ruth['contact']['pid'], array_column(call('GET', 'contacts', [], $dana, ['q' => 'לוי'])[1]['contacts'], 'pid'), true)], ['contact_in_use', true], 'מקרה 6: מחיקת איש קשר שמשויך לתיק נחסמת; ספר הכתובות משותף למשרד, עם חיפוש');
$free = call('POST', 'contact-save', ['name' => 'איש קשר חופשי', 'email' => 'Free@Example.com'], $dana)[1]['contact'];
eq([call('POST', 'contact-delete', ['contact' => $free], $avi)[0], code(call('POST', 'contact-save', ['name' => 'x', 'email' => 'לא מייל'], $avi)), code(call('POST', 'case-party-add', ['case' => $C, 'contact' => $free, 'kind' => 'client'], $avi))], [200, 'email_invalid', 'not_found'], 'איש קשר שאינו בתיק נמחק; מייל לא תקין נדחה');

// ── 04-B6, מקרה 5: חבר שהושעה או עזב ──
eq([$view($tal, $D)[0], in_array($D, array_column(call('GET', 'cases', [], $tal)[1]['cases'], 'pid'), true)], [200, true], 'לפני ההשעיה — טל רואה את התיק');
call('POST', 'member-update', ['user' => $talPid, 'status' => 'suspended'], $avi);
eq([$view($tal, $D)[0], call('GET', 'cases', [], $tal)[0], call('GET', 'my-tasks', [], $tal)[0], call('POST', 'case-update', ['case' => $D, 'title' => 'x'], $tal)[0]], [403, 403, 403, 403], '04-B6 חבר שהושעה במשרד מאבד גישה לכל תיקי המשרד בבקשה הבאה');
eq((int)apr_val('SELECT COUNT(*) FROM case_members cm JOIN users u ON u.id = cm.user_id WHERE u.pid = ? AND cm.revoked_at IS NULL', [$talPid]), 0, '§6.1 וכל שורות ההשתתפות שלו בוטלו');
call('POST', 'member-update', ['user' => $talPid, 'status' => 'active'], $avi);
eq([$view($tal, $D)[0], call('GET', 'cases', [], $tal)[1]['cases']], [404, []], '04-B6 ביטול ההשעיה לא מחזיר את ההשתתפות — צריך להוסיף אותו שוב');
call('POST', 'member-remove', ['user' => $danaPid], $avi);
$row = array_values(array_filter(call('GET', 'office-cases', [], $avi)[1]['cases'], fn($c) => $c['pid'] === $D))[0];
eq([$row['responsible'], $crow($D)['responsible_user_id'], count($log($D, 'case.member_out')), (int)apr_val("SELECT COUNT(*) FROM tasks WHERE case_id = ? AND assignee_user_id IS NOT NULL AND status = 'open'", [$crow($D)['id']])], [null, null, 1, 0], 'מקרה 5: האחראית הוסרה מהמשרד — התיק "בלי אחראי פעיל" בשורת הניהול');
eq([call('POST', 'office-case-transfer', ['case' => $D, 'user' => $aviPid, 'reason' => 'דנה עזבה'], $avi)[0], $view($avi, $D)[1]['me']['role'], count($view($avi, $D)[1]['members'])], [200, 'responsible', 2], 'מנהל המשרד מעביר אחריות; מי שעזבה אינה נשארת שותפה');

// ── 04-C4: כל פעולה משנה מופיעה בלשונית הפעילות ──
$G = make_case($avi, $P, ['gush' => '9000', 'helka' => '1', 'start' => false]);
$n = fn() => (int)apr_val('SELECT COUNT(*) FROM audit_log WHERE case_id = ?', [$crow($G)['id']]);
$gi = apr_row('SELECT pid FROM case_identifiers WHERE case_id = ?', [$crow($G)['id']])['pid'];
$gp = apr_row('SELECT pid FROM case_parties WHERE case_id = ?', [$crow($G)['id']])['pid'];
$steps = [
    'case.update' => fn() => call('POST', 'case-update', ['case' => $G, 'title' => 'כותרת חדשה'], $avi),
    'case.identifier_add' => fn() => call('POST', 'case-identifier-add', ['case' => $G, 'kind' => 'other', 'value_text' => 'תיק בניין 77'], $avi),
    'case.identifier_update' => fn() => call('POST', 'case-identifier-update', ['case' => $G, 'identifier' => $gi, 'state' => 'needs_check'], $avi),
    'case.identifiers_conflict' => fn() => call('POST', 'case-identifiers-conflict', ['case' => $G], $avi),
    'case.party_add' => fn() => call('POST', 'case-party-add', ['case' => $G, 'name' => 'בנק הפועלים', 'kind' => 'bank'], $avi),
    'case.party_update' => fn() => call('POST', 'case-party-update', ['case' => $G, 'party' => $gp, 'note' => 'הערה'], $avi),
    'case.member_add' => fn() => call('POST', 'case-member-add', ['case' => $G, 'user' => $noamPid], $avi),
    'case.member_update' => fn() => call('POST', 'case-member-update', ['case' => $G, 'user' => $noamPid, 'role' => 'staff'], $avi),
    'case.start' => fn() => call('POST', 'case-start', ['case' => $G], $avi),
    'case.status' => fn() => call('POST', 'case-status', ['case' => $G, 'status' => 'checking'], $avi),
    'task.create' => fn() => call('POST', 'task-create', ['case' => $G, 'title' => 'משימה'], $avi),
    'task.status' => fn() => call('POST', 'task-update', ['task' => apr_row("SELECT pid FROM tasks WHERE case_id = ? AND kind = 'manual'", [$crow($G)['id']])['pid'], 'status' => 'in_progress'], $avi),
    'task.update' => fn() => call('POST', 'task-update', ['task' => apr_row("SELECT pid FROM tasks WHERE case_id = ? AND kind = 'manual'", [$crow($G)['id']])['pid'], 'title' => 'משימה 2'], $avi),
    'requirement.add' => fn() => call('POST', 'requirement-add', ['case' => $G, 'title' => 'דרישה'], $avi),
    'requirement.update' => fn() => call('POST', 'requirement-update', ['requirement' => $reqs($G)[0]['pid'], 'priority' => 'high'], $avi),
    'case.profile_apply' => fn() => call('POST', 'case-profile-apply', ['case' => $G], $avi),
    'case.responsible' => fn() => call('POST', 'office-case-transfer', ['case' => $G, 'user' => $aviPid, 'reason' => 'x'], $avi) && call('POST', 'case-member-update', ['case' => $G, 'user' => $aviPid, 'role' => 'responsible'], $avi),
    'case.member_remove' => fn() => call('POST', 'case-member-remove', ['case' => $G, 'user' => $noamPid], $avi),
    'case.party_remove' => fn() => call('POST', 'case-party-remove', ['case' => $G, 'party' => apr_row("SELECT pid FROM case_parties WHERE case_id = ? AND kind = 'bank'", [$crow($G)['id']])['pid']], $avi),
    'case.identifier_remove' => fn() => call('POST', 'case-identifier-remove', ['case' => $G, 'identifier' => $gi], $avi),
];
foreach ($steps as $action => $do) {
    if ($action === 'case.responsible') continue;   // כבר האחראי — אין שינוי, ולכן אין שורה; נבדק ב-04-B4
    $n0 = $n();
    $r = $do();
    $last = apr_row('SELECT action, case_id, office_id, actor_user_id FROM audit_log WHERE case_id = ? ORDER BY id DESC LIMIT 1', [$crow($G)['id']]);
    eq([$r[0], $n() - $n0, $last['action'], (int)$last['case_id'], (int)$last['actor_user_id'] > 0], [200, 1, $action, (int)$crow($G)['id'], true], "04-C4 $action — שורה אחת בפעילות, עם case_id והמבצע");
}
[$st, $act] = call('GET', 'case-activity', [], $avi, ['case' => $G]);
eq([$st, count($act['rows']), $act['rows'][0]['action'], end($act['rows'])['action'], $act['rows'][0]['actor']], [200, $n(), 'case.identifier_remove', 'case.create', 'אבי'], '04-C4 לשונית הפעילות: מהחדשה לישנה, עם שם המבצע');
check('היומן בלי תוכן: לא כותרת, לא שם גורם, לא גוש', !preg_match('/כותרת חדשה|בנק הפועלים|9000|תיק בניין/u', json_encode(apr_rows('SELECT meta FROM audit_log WHERE case_id = ?', [$crow($G)['id']]), JSON_UNESCAPED_UNICODE)));
eq((int)apr_val("SELECT COUNT(*) FROM audit_log WHERE case_id IS NOT NULL AND office_id IS NULL"), 0, 'כל שורת תיק נושאת גם את המשרד');

// ── מקרים 8, 11; §4.2; הרשימה ──
$emptyP = make_profile($avi, 'ריק', ['requirements' => ['groups' => [], 'items' => []]]);
$H = make_case($avi, $emptyP, ['gush' => '9100', 'helka' => '1']);
eq([$crow($H)['status'], $view($avi, $H)[1]['requirements'], $view($avi, $H)[1]['tasks']], ['collecting', [], []], 'מקרה 8: תוכנית עבודה ריקה — מותר');
$I = call('POST', 'case-create', ['profile' => $emptyP], $avi)[1]['case'];
call('POST', 'profile-archive', ['profile' => $emptyP], $avi);
call('POST', 'case-update', ['case' => $I, 'purpose' => 'purchase'], $avi);
call('POST', 'case-identifier-add', ['case' => $I, 'kind' => 'other', 'value_text' => 'מגרש 4'], $avi);
call('POST', 'case-party-add', ['case' => $I, 'name' => 'מזמין', 'kind' => 'client', 'is_orderer' => true], $avi);
eq([code(call('POST', 'case-start', ['case' => $I], $avi)), code(call('POST', 'case-create', ['profile' => $emptyP], $avi)), $crow($I)['status'], $view($avi, $H)[0]], ['profile_archived', 'profile_archived', 'draft', 200], '§4.2 הפרופיל אורכב בין הבחירה ליצירה — "בחרו פרופיל אחר"; תיק קיים ממשיך');
call('POST', 'office-required-profile', ['profile' => $P], $avi);
$other = make_profile($avi, 'אחר');
eq([code(call('POST', 'case-create', ['profile' => $other], $avi)), call('POST', 'case-create', ['profile' => $P], $avi)[0], call('GET', 'case-options', [], $avi)[1]['required_profile']], ['profile_required', 200, $P], '§4.1 פרופיל משרדי מחייב — נאכף בפתיחת תיק');
call('POST', 'office-required-profile', ['profile' => null], $avi);
office_for($avi, 'משרד שני של אבי');
eq([$view($avi, $C)[0], call('GET', 'cases', [], $avi)[1]['cases']], [404, []], 'מקרה 11: קישור לתיק של המשרד הלא-פעיל — 404');
call('POST', 'office-switch', ['office' => $A], $avi);
[, $list] = call('GET', 'cases', [], $avi);
$rowC = array_values(array_filter($list['cases'], fn($c) => $c['pid'] === $C))[0];
eq([array_keys($rowC), $rowC['responsible']['name'], $rowC['open_mandatory'] >= 1], [['pid', 'number', 'status', 'title', 'purpose', 'purpose_note', 'property_type', 'key_date', 'due_date', 'updated_at', 'created_at', 'responsible', 'open_mandatory', 'overdue_tasks'], 'אבי', true], '§9 שורה ברשימה: מספר, כותרת, מטרה, אחראי, מצב, יעד, וסימון');
eq([array_unique(array_column(call('GET', 'cases', [], $avi, ['status' => 'draft'])[1]['cases'], 'status')), in_array($C, array_column(call('GET', 'cases', [], $avi, ['open_mandatory' => '1'])[1]['cases'], 'pid'), true),
    in_array($H, array_column(call('GET', 'cases', [], $avi, ['open_mandatory' => '1'])[1]['cases'], 'pid'), true), array_column(call('GET', 'cases', [], $avi, ['orderer' => $ruth['contact']['pid']])[1]['cases'], 'pid')],
    [['draft'], true, false, [$C]], '§9 מסננים: מצב, "יש דרישת חובה פתוחה", מזמין');
[, $cv] = $view($avi, $I);
eq([$cv['missing'], array_values(array_diff($cv['todo'], $cv['missing'])), isset($cv['case']['progress'])], [[], ['property_type', 'key_date', 'due_date', 'title'], false], '§9 הסקירה: רשימת "להשלמה" — לא אחוז התקדמות');
check('כרטיס התיק: בלי מזהים פנימיים', !preg_match('/"(id|office_id|case_id|user_id|contact_id|profile_id|created_by)"\s*:/', json_encode($view($avi, $C)[1])));

done();
