<?php
// פרוסה 10 — נתונים, מקור לכל נתון, וסתירות (docs/spec/10-facts-conflicts.md §11): 10-A1 … 10-C4, מקרי הקצה, ההרשאות, הבידוד, המרוצים והמיגרציה.
if (in_array($argv[1] ?? '', ['adder', 'fixer'], true)) {
    // תהליך-בן למרוץ: כולם יוצאים באותו רגע, על אותו קובץ מסד
    define('APR_DATA_DIR', $argv[2]);
    define('APR_DB_PATH', $argv[2] . '/app.sqlite');
    require __DIR__ . '/lib.php';
    $as = ident('2', 'אבי', 'avi@example.com');
    call('GET', 'me', [], $as);
    while (microtime(true) < (float)$argv[4]) usleep(200);
    $r = $argv[1] === 'adder'
        ? call('POST', 'fact-add', ['case' => $argv[3], 'attribute' => 'area', 'value' => $argv[5], 'unit' => 'sqm', 'area_kind' => 'built', 'source' => ['kind' => 'user']], $as)
        : call('POST', 'fact-correct', ['fact' => $argv[3], 'rev' => 1, 'value' => $argv[5]], $as);
    echo $r[0];
    exit(0);
}
require __DIR__ . '/lib.php';

$warnings = [];
$GLOBALS['apr_upload_rate'] = [100000, 600];
set_error_handler(function (int $no, string $msg, string $file, int $line) use (&$warnings) { if (!(error_reporting() & $no)) return true; $warnings[] = "$msg @" . basename($file) . ":$line"; return true; });

$owner = ident('1', 'הבעלים', 'owner@example.com', ['platform_admin' => true]);
$avi = ident('2', 'אבי', 'avi@example.com');       // מנהל משרד א, שמאי — האחראי על התיקים
$dana = ident('3', 'דנה', 'dana@example.com');     // שמאית: שותפה בתיק (review_docs)
$tal = ident('5', 'טל', 'tal@example.com');        // עובדת משרד: documents בלי review_docs
$gil = ident('7', 'גיל', 'gil@example.com');       // שמאי במשרד א שאינו משתתף בתיקים
$ron = ident('8', 'רון', 'ron@example.com');       // עובד משרד: view בלבד
$boaz = ident('4', 'בועז', 'boaz@example.com');    // משרד ב
call('GET', 'me', [], $owner);
office_for($avi, 'משרד א');
office_for($boaz, 'משרד ב');
$danaPid = join_office($avi, $dana, 'dana@example.com', 'appraiser');
$talPid = join_office($avi, $tal, 'tal@example.com', 'staff');
join_office($avi, $gil, 'gil@example.com', 'appraiser');
$ronPid = join_office($avi, $ron, 'ron@example.com', 'staff');

function make_profile(string $as): string
{
    $pid = call('POST', 'profile-create', ['scope' => 'office', 'name' => 'פרופיל'], $as)[1]['profile'];
    $it = fn($k, $t) => ['key' => $k, 'group' => '', 'doc_type' => 'rights', 'title' => $t, 'priority' => 'normal', 'mandatory' => false, 'party_kind' => 'client', 'due' => ['mode' => 'relative', 'days' => 3], 'needs_review' => true, 'when' => null, 'sort' => 1];
    [, $d] = call('POST', 'profile-draft-open', ['profile' => $pid], $as);
    $s = call('POST', 'profile-draft-save', ['profile' => $pid, 'content' => ['flags' => [], 'requirements' => ['groups' => [], 'items' => [$it('contract', 'הסכם מכר'), $it('plan', 'תשריט')]], 'tasks' => ['items' => []], 'rules' => []], 'revision' => $d['draft']['revision']], $as);
    $r = call('POST', 'profile-publish', ['profile' => $pid, 'note' => '', 'revision' => $s[1]['revision'] ?? ''], $as);
    if ($r[0] !== 200) throw new RuntimeException('publish: ' . json_encode([$s[1], $r[1]], JSON_UNESCAPED_UNICODE));
    return $pid;
}
function make_case(string $as, string $profile): string
{
    $c = call('POST', 'case-create', ['profile' => $profile], $as)[1]['case'];
    call('POST', 'case-update', ['case' => $c, 'title' => 'דירה ברחוב הרצל', 'purpose' => 'purchase'], $as);
    call('POST', 'case-identifier-add', ['case' => $c, 'kind' => 'parcel', 'gush' => '6158', 'helka' => '12'], $as);
    call('POST', 'case-party-add', ['case' => $c, 'name' => 'משה לקוח', 'kind' => 'client', 'is_orderer' => true], $as);
    $s = call('POST', 'case-start', ['case' => $c], $as);
    if ($s[0] !== 200) throw new RuntimeException('case-start: ' . json_encode($s[1], JSON_UNESCAPED_UNICODE));
    return $c;
}
function pdf(string $mark): string
{
    return "%PDF-1.4\n%$mark\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
}
function up(string $as, string $case, string $mark, array $q = []): array
{
    $tmp = tempnam(apr_upload_tmp_dir(), 'up');
    file_put_contents($tmp, pdf($mark));
    $r = call('POST', 'file-upload', $q + ['case' => $case, 'name' => "$mark.pdf"], $as, [], ['env' => ['upload' => $tmp]]);
    if ($r[0] !== 200) throw new RuntimeException('upload: ' . json_encode($r[1], JSON_UNESCAPED_UNICODE));
    return $r[1];
}

$P = make_profile($avi);
$PB = make_profile($boaz);
$newCase = function () use ($avi, $P, $danaPid, $talPid, $ronPid): string {
    $c = make_case($avi, $P);
    call('POST', 'case-member-add', ['case' => $c, 'user' => $danaPid], $avi);
    call('POST', 'case-member-add', ['case' => $c, 'user' => $talPid], $avi);
    call('POST', 'case-member-add', ['case' => $c, 'user' => $ronPid, 'role' => 'visitor'], $avi);
    call('POST', 'case-member-update', ['case' => $c, 'user' => $ronPid, 'perms' => ['edit' => false, 'documents' => false, 'review_docs' => false, 'tasks' => false, 'visit' => false]], $avi);
    return $c;
};
$C = $newCase();
$CB = make_case($boaz, $PB);

$USER = ['kind' => 'user', 'note' => 'נמסר בעל פה מהלקוח'];
$add = fn(string $as, string $case, mixed $attr, mixed $value, array $more = []) => call('POST', 'fact-add', $more + ['case' => $case, 'attribute' => $attr, 'value' => $value, 'source' => $USER], $as);
$area = fn(string $as, string $case, mixed $v, mixed $kind, array $more = []) => $add($as, $case, 'area', $v, $more + ['unit' => 'sqm', 'area_kind' => $kind]);
$facts = fn(string $as, string $case) => call('GET', 'case-facts', [], $as, ['case' => $case]);
$attrOf = fn(array $res, string $key) => array_values(array_filter($res[1]['attributes'] ?? [], fn($a) => $a['key'] === $key))[0] ?? null;
$conflicts = fn(string $as, string $case) => call('GET', 'case-conflicts', [], $as, ['case' => $case])[1]['conflicts'] ?? null;
$frow = fn(string $pid) => apr_row('SELECT * FROM facts WHERE pid = ?', [$pid]);
$allFacts = fn() => apr_rows('SELECT * FROM facts ORDER BY id');
$count = fn(string $t, string $w = '1') => (int)apr_val("SELECT COUNT(*) FROM $t WHERE $w");
$caseId = fn(string $pid) => (int)apr_val('SELECT id FROM cases WHERE pid = ?', [$pid]);
$crow = fn(string $pid) => apr_row('SELECT * FROM cases WHERE pid = ?', [$pid]) + ['perms' => ['view' => true], 'my_role' => 'responsible'];
$raises = function (string $sql, array $args = []): bool { try { apr_exec($sql, $args); return false; } catch (PDOException) { return true; } };

// ── 10-A1: "לא ידוע" אינו אפס ──
$f0 = $facts($avi, $C);
$json = json_encode($f0[1], JSON_UNESCAPED_UNICODE);
eq([$f0[0], count($f0[1]['attributes']), array_unique(array_map(fn($a) => [$a['known'], $a['value'], $a['facts']], $f0[1]['attributes']), SORT_REGULAR), $count('facts'), $f0[1]['conflicts_open'],
    str_contains($json, '"value":0'), str_contains($json, '"value":""'), str_contains($json, '"null"')],
    [200, 27, [[false, null, []]], 0, 0, false, false, false], '10-A1 מאפיין בלי נתון מוחזר "לא ידוע": known = false, value = null, בלי שורה במסד — לא 0, לא מחרוזת ריקה ולא "null"');
$zero = $add($avi, $C, 'contract_price', 0, ['unit' => 'ILS']);
$zeroS = $add($avi, $C, 'contract_price', '0.00', ['unit' => 'ILS']);
eq([code($zero), code($zeroS), code($add($avi, $C, 'contract_price', null, ['unit' => 'ILS'])), code($add($avi, $C, 'contract_price', '', ['unit' => 'ILS'])), $count('facts')],
    ['fact_zero_confirm', 'fact_zero_confirm', 'fact_no_value', 'fact_no_value', 0], '10-A1 / מקרה 5: אפס בלי אישור ← "אפס, או לא ידוע?"; ערך ריק אינו נשמר כאפס — נדחה');
$unk = $add($avi, $C, 'declared_budget', null, ['unit' => 'ILS', 'state' => 'needs_clarification', 'note' => 'חיפשנו בהסכם ולא מצאנו']);
$z = $add($avi, $C, 'verified_cost', 0, ['unit' => 'ILS', 'zero_ok' => true]);
$fa = $facts($avi, $C);
eq([$unk[0], $unk[1]['fact']['num'], $unk[1]['fact']['text'], $unk[1]['fact']['has_value'], $frow($unk[1]['fact']['pid'])['value_num'], $attrOf($fa, 'declared_budget')['known'], $attrOf($fa, 'declared_budget')['value'],
    code($add($avi, $C, 'declared_budget', null, ['unit' => 'ILS', 'state' => 'needs_clarification'])),
    $z[0], $z[1]['fact']['num'], $frow($z[1]['fact']['pid'])['value_num'], $attrOf($fa, 'verified_cost')['known'], $attrOf($fa, 'verified_cost')['value']['num']],
    [200, null, null, false, null, false, null, 'fact_note_required', 200, '0', 0, true, '0'],
    '10-A1 "חיפשנו ולא מצאנו": שורה בלי ערך (NULL במסד, null ב-API) והמאפיין נשאר "לא ידוע"; אפס מאושר הוא ערך — "0", וידוע');

// ── 10-B3: סוג שטח חובה ──
eq([code($area($avi, $C, 80, null)), code($area($avi, $C, 80, '')), code($area($avi, $C, 80, 'nope')), code($area($avi, $C, 80, ['built'])), code($add($avi, $C, 'area', 80, ['area_kind' => 'built'])),
    code($add($avi, $C, 'area', 80, ['area_kind' => 'built', 'unit' => 'ILS'])), $count('facts', "attribute_key = 'area'")],
    ['area_kind_required', 'area_kind_required', 'area_kind_required', 'area_kind_required', 'fact_unit', 'fact_unit', 0], '10-B3 נתון שטח בלי סוג שטח (או בלי יחידה) ← נדחה, ושום שורה לא נכתבת');

// ── מספרים: מדויקים, בטווח, ובלי נקודה צפה ──
$bad = array_map(fn($v) => code($area($avi, $C, $v, 'unknown')), ['78.555', '12abc', "78\n5", '1e3', ' ', '-5', -5, 1e21, NAN, true, ['78'], '0x10', '1,5', '9999999999999']);
$rooms = $add($avi, $C, 'rooms', 3.5);
$big = $add($avi, $C, 'rent_expenses', '999999999999.99', ['unit' => 'ILS']);
$tenth = $add($avi, $C, 'price_quote', 0.1, ['unit' => 'ILS']);
$floor = $add($avi, $C, 'floor', -1);
eq([array_unique($bad), code($add($avi, $C, 'rooms', '3.55')), code($add($avi, $C, 'floor', '2.5')), $rooms[1]['fact']['num'] ?? null, $big[1]['fact']['num'] ?? null, $frow($big[1]['fact']['pid'])['value_num'],
    $tenth[1]['fact']['num'] ?? null, $frow($tenth[1]['fact']['pid'])['value_num'], $floor[1]['fact']['num'] ?? null, code($add($avi, $C, 'rooms', 3, ['unit' => 'sqm']))],
    [['fact_value'], 'fact_value', 'fact_value', '3.5', '999999999999.99', 99999999999999, '0.1', 10, '-1', 'fact_unit'],
    'מספר: רק עשרוני פשוט בטווח (\\z — בלי שורה חדשה בסוף), לפי מספר הספרות של המאפיין; נשמר כשלם במאיות ומוחזר כמחרוזת מדויקת; שלילי רק היכן שמותר');
eq([code($add($avi, $C, 'contract_date', '2026-02-30')), code($add($avi, $C, 'contract_date', "2026-02-03\n")), $add($avi, $C, 'contract_date', '2026-02-03')[1]['fact']['date'] ?? null,
    code($add($avi, $C, 'build_state', 'nope')), $add($avi, $C, 'build_state', 'built')[1]['fact']['text'] ?? null, code($add($avi, $C, 'no_such', 1)), code($add($avi, $C, null, 1)),
    code(call('POST', 'fact-add', ['case' => $C, 'attribute' => 'rooms', 'value' => 3], $avi)), code(call('POST', 'fact-add', ['case' => $C, 'attribute' => 'rooms', 'value' => 3, 'source' => ['kind' => 'ai']], $avi)),
    code(call('POST', 'fact-add', ['case' => $C, 'attribute' => 'rooms', 'value' => 3, 'source' => ['kind' => 'document']], $avi))],
    ['date_invalid', 'date_invalid', '2026-02-03', 'fact_value', 'built', 'attr_invalid', 'attr_invalid', 'fact_source', 'fact_source', 'source_file_required'],
    'תאריך, בחירה, מאפיין ומקור נבדקים: תאריך שאינו קיים, אפשרות שאינה ברשימה, מאפיין לא קיים, בלי מקור, מקור "ai" מהזנה ידנית, ומסמך בלי קובץ ← נדחים');

// ── 10-A2: סתירה, משימת בירור, והנתונים ללא שינוי ──
$F1 = up($avi, $C, 'contract', ['requirement' => apr_val("SELECT pid FROM requirements WHERE case_id = ? AND origin_key = 'contract'", [$caseId($C)])]);
$F2 = up($avi, $C, 'plan');
$XSS = '<img src=x onerror=alert(1)> סעיף 3.1';
$a80 = $area($avi, $C, 80, 'built', ['source' => ['kind' => 'document', 'file' => $F1['file'], 'page' => 1, 'locator' => $XSS, 'date' => '2025-01-15']]);
$before = [$frow($a80[1]['fact']['pid']), $count('conflicts'), $count('tasks', "kind = 'clarification'")];
$a86 = $area($tal, $C, '86', 'built', ['source' => ['kind' => 'document', 'file' => $F2['file']]]);
$cf = $conflicts($avi, $C);
$K = $cf[0]['pid'] ?? '';
$task = apr_row("SELECT t.*, u.name FROM tasks t LEFT JOIN users u ON u.id = t.assignee_user_id WHERE t.kind = 'clarification' AND t.case_id = ?", [$caseId($C)]);
$list = fn(string $as) => array_values(array_filter(call('GET', 'cases', [], $as)[1]['cases'], fn($r) => $r['pid'] === $C))[0];
eq([$a80[0], $a86[0], $before[1], $before[2], count($cf), $cf[0]['kind'], $cf[0]['status'], array_column($cf[0]['facts'], 'num'), array_column(array_column($cf[0]['facts'], 'source'), 'file'),
    $frow($a80[1]['fact']['pid']) === $before[0], $task['link_type'], $task['link_pid'] === $K, $task['status'], $task['name'], $cf[0]['task'] === $task['pid'],
    $list($avi)['open_conflicts'], call('GET', 'case', [], $avi, ['case' => $C])[1]['open_conflicts'], $facts($avi, $C)[1]['conflicts_open'], $attrOf($facts($avi, $C), 'area')['value'],
    $a80[1]['fact']['source']['locator']],
    [200, 200, 0, 0, 1, 'value', 'open', ['80', '86'], [$F1['file'], $F2['file']], true, 'conflict', true, 'open', 'אבי', true, 1, 1, 1, null, $XSS],
    '10-A2 שני נתוני שטח "בנוי" עם ערכים שונים ← סתירה פתוחה ומשימת בירור לאחראי, מקושרת לסתירה; שני הנתונים והמקורות ללא שינוי; התיק מסומן ברשימה; אין "ערך מוסכם"');

// ── 10-A3, 10-A4, 10-A5 ומקרי הקצה 9, 10 ──
$C2 = $newCase();
$area($avi, $C2, 78, 'registered');
$area($avi, $C2, 86, 'built');
$a2 = $attrOf($facts($avi, $C2), 'area');
eq([$count('conflicts', 'case_id = ' . $caseId($C2)), count($a2['facts']), array_column($a2['facts'], 'area_kind'), $a2['known'], $a2['value']], [0, 2, ['registered', 'built'], true, null],
    '10-A3 שטח רשום 78 ושטח בנוי 86 ← אין סתירה; שניהם מוצגים, כל אחד עם סוגו, ואין השוואה ביניהם');
$C3 = $newCase();
$u80 = $area($avi, $C3, 80, 'unknown');
$area($avi, $C3, 86, 'built');
$c3 = $conflicts($avi, $C3);
eq([count($c3), $c3[0]['kind'] ?? null, $c3[0]['status'] ?? null, count($c3[0]['facts'] ?? [])], [1, 'incomparable', 'open', 2], '10-A4 שטח שסוגו "לא ידוע" מול שטח בנוי ← סתירה מסוג "אי אפשר להשוות"');
$C4 = $newCase();
$area($avi, $C4, 80, 'built');
$area($avi, $C4, '80.00', 'built', ['source' => ['kind' => 'visit']]);
$area($avi, $C4, '0.08', 'built', ['unit' => 'dunam']);
$a4 = $attrOf($facts($avi, $C4), 'area');
eq([$count('conflicts', 'case_id = ' . $caseId($C4)), count($a4['facts']), $a4['value']['num'] ?? null, $a4['value']['area_kind'] ?? null], [0, 3, '80', 'built'],
    '10-A5 / מקרה 10: אותו ערך משלושה מקורות (גם 0.08 דונם = 80 מ"ר, המרה מדויקת) ← אין סתירה, ויש ערך מוסכם');
$area($avi, $C4, '0.09', 'built', ['unit' => 'dunam']);
$add($avi, $C4, 'contract_price', 1500000, ['unit' => 'ILS']);
$add($avi, $C4, 'contract_price', 1500000, ['unit' => 'USD']);
$add($avi, $C4, 'price_quote', 50000, ['unit' => 'ILS']);
$add($avi, $C4, 'price_quote', 70000, ['unit' => 'ILS']);
$add($avi, $C4, 'registered_owner', 'משה כהן');
$add($avi, $C4, 'registered_owner', 'רחל כהן');
$add($avi, $C4, 'payment_terms', 'שלושה תשלומים');
$add($avi, $C4, 'payment_terms', '  שלושה   תשלומים ');
$c4 = array_column($conflicts($avi, $C4), 'kind', 'pid');
eq([array_count_values(array_map(fn($c) => $c['attribute']['key'] . ':' . $c['kind'], $conflicts($avi, $C4))), count($c4)], [['contract_price:incomparable' => 1, 'area:value' => 1], 2],
    'מקרים 9, 10: 0.09 דונם מול 80 מ"ר ← סתירת ערך; ש"ח מול דולר ← "אי אפשר להשוות"; מאפיין רב-ערכי (שתי הצעות מחיר, שני בעלים) ואותו טקסט ברווחים אחרים ← אין סתירה');

// ── 10-A6: הכרעה בלי נימוק או בלי מטרה ──
$kv = fn(string $as = null) => call('GET', 'conflict', [], $as ?? $GLOBALS['avi_'], ['conflict' => $GLOBALS['K_']])[1]['conflict'];
$GLOBALS['avi_'] = $avi;
$GLOBALS['K_'] = $K;
$res = fn(string $as, array $more) => call('POST', 'conflict-resolve', $more + ['conflict' => $K, 'rev' => $kv()['rev'], 'fact' => $a86[1]['fact']['pid'], 'purpose' => 'לחישוב מחיר למ"ר', 'reasoning' => 'התוכנית עדכנית מההסכם'], $as);
$n0 = [$count('conflict_resolutions'), $kv()['rev']];
eq([code($res($avi, ['reasoning' => ''])), code($res($avi, ['reasoning' => "  \n "])), code($res($avi, ['reasoning' => null])), code($res($avi, ['purpose' => ''])), code($res($avi, ['purpose' => ['x']])),
    code($res($avi, ['fact' => $u80[1]['fact']['pid']])), code($res($avi, ['fact' => 'nope'])), code($res($avi, ['rev' => 99])), code($res($avi, ['rev' => null])), [$count('conflict_resolutions'), $kv()['rev']] === $n0, $kv()['status']],
    ['reasoning_required', 'reasoning_required', 'reasoning_required', 'purpose_required', 'purpose_required', 'conflict_fact', 'conflict_fact', 'conflict_changed', 'rev_required', true, 'open'],
    '10-A6 הכרעה בלי נימוק, בלי מטרה, עם נתון של סתירה אחרת או עם rev ישן ← נדחית, ושום דבר לא נכתב');

// ── 10-A7: הכרעה אינה נוגעת בנתונים ──
$snap = $allFacts();
$r1 = $res($avi, []);
$r2 = $res($dana, ['purpose' => 'לתיאור הנכס בדוח', 'fact' => $a80[1]['fact']['pid'], 'reasoning' => 'כך נרשם בהסכם']);
$r3 = $res($avi, ['fact' => $a80[1]['fact']['pid'], 'reasoning' => 'בדיקה חוזרת: ההסכם קובע']);
$k = $kv();
eq([$r1[0], $r2[0], $r3[0], $allFacts() === $snap, $k['status'], $k['close_kind'], array_map(fn($r) => [$r['purpose'], $r['fact'] === $a80[1]['fact']['pid'] ? 80 : 86, $r['valid'], $r['void_reason'], $r['by']], $k['resolutions']),
    apr_val('SELECT status FROM tasks WHERE id = ?', [$task['id']]), $list($avi)['open_conflicts']],
    [200, 200, 200, true, 'resolved', 'resolution', [['לחישוב מחיר למ"ר', 86, false, 'superseded', 'אבי'], ['לתיאור הנכס בדוח', 80, true, null, 'דנה'], ['לחישוב מחיר למ"ר', 80, true, null, 'אבי']], 'done', 0],
    '10-A7 אחרי הכרעה אף נתון לא נמחק ולא שונה; הכרעה שנייה למטרה אחרת — אפשר; הכרעה חדשה לאותה מטרה מחליפה את הקודמת, שנשארת בהיסטוריה; המשימה נסגרת');
eq([$raises('DELETE FROM facts WHERE id = 1'), $raises('DELETE FROM conflicts'), $raises('DELETE FROM conflict_facts'), $raises('DELETE FROM conflict_resolutions'),
    $raises("UPDATE conflict_resolutions SET reasoning = 'x' WHERE voided_at IS NULL"), $raises("UPDATE conflict_resolutions SET voided_at = NULL, void_reason = NULL WHERE voided_at IS NOT NULL"),
    $raises('UPDATE facts SET value_num = 1 WHERE id = ' . (int)$before[0]['id']), $raises('UPDATE facts SET value_num = 1, rev = rev + 1 WHERE id = ' . (int)$before[0]['id']),
    $raises('UPDATE facts SET case_id = ' . $caseId($CB) . ', rev = rev + 1 WHERE id = ' . (int)$before[0]['id']), $raises('UPDATE conflict_facts SET fact_id = 1'),
    $raises("INSERT INTO conflicts (pid, office_id, case_id, attribute_key, scope, kind, created_at, updated_at) SELECT 'dup0000000000000', office_id, case_id, attribute_key, scope, kind, 'x', 'x' FROM conflicts LIMIT 1"),
    $allFacts() === $snap],
    array_merge(array_fill(0, 11, true), [true]),
    '10-A7 / 10-B1 במסד: טריגרים חוסמים מחיקה של נתון, סתירה והכרעה, עריכה של הכרעה, שינוי ערך בלי rev ובלי שמירת המקורי, והעברת נתון לתיק אחר; UNIQUE חוסם סתירה כפולה');

// ── 10-A8: נתון שלישי שונה פותח את הסתירה מחדש ──
$same = $area($avi, $C, 80, 'built', ['source' => ['kind' => 'visit']]);
$stay = $kv()['status'];
$a90 = $area($avi, $C, 90, 'built');
$k = $kv();
eq([$stay, $k['status'], $k['opened_count'], count($k['facts']), count($k['resolutions']), count(array_filter($k['resolutions'], fn($r) => $r['valid'])), $count('conflicts', 'case_id = ' . $caseId($C)),
    $count('tasks', "kind = 'clarification' AND status = 'open' AND case_id = " . $caseId($C)), $list($avi)['open_conflicts']],
    ['resolved', 'open', 2, 4, 3, 2, 1, 1, 1], '10-A8 נתון נוסף ששווה לערך שהוכרע אינו פותח דבר; נתון שלישי ושונה ← הסתירה שוב פתוחה (אותה סתירה), ההכרעות הקודמות קיימות, ומשימת בירור חדשה');

// ── 10-B1: תיקון שומר את המקורי ──
$fx = fn(string $as, array $f, array $more) => call('POST', 'fact-correct', $more + ['fact' => $f['pid'], 'rev' => $f['rev']], $as);
$p90 = $a90[1]['fact'];
$c1 = $fx($tal, $p90, ['value' => '91.5', 'correction_note' => 'טעות הקלדה']);
$c2 = $fx($avi, $c1[1]['fact'], ['value' => 92, 'area_kind' => 'gross']);
$row = $frow($p90['pid']);
eq([$c1[0], $c1[1]['fact']['num'], $c1[1]['fact']['original']['num'], $c1[1]['fact']['corrected_by'], $c2[0], $c2[1]['fact']['num'], $c2[1]['fact']['area_kind'], $c2[1]['fact']['original'], $c2[1]['fact']['corrected_by'],
    [(int)$row['original_num'], $row['original_area_kind'], (int)$row['value_num'], (int)$row['rev']], $p90['original'],
    $raises('UPDATE facts SET original_num = 1, rev = rev + 1 WHERE id = ' . (int)$row['id']), $raises('UPDATE facts SET corrected_at = NULL, rev = rev + 1 WHERE id = ' . (int)$row['id'])],
    [200, '91.5', '90', 'טל', 200, '92', 'gross', ['num' => '90', 'bool' => null, 'text' => null, 'date' => null, 'unit' => 'sqm', 'area_kind' => 'built'], 'אבי', [9000, 'built', 9200, 3], null, true, true],
    '10-B1 תיקון שומר את הערך כפי שהגיע ב-original (ערך, יחידה וסוג שטח); תיקון שני אינו דורס אותו — גם לא ישירות במסד; נרשמים המתקן והזמן');
$hist = call('GET', 'fact', [], $avi, ['fact' => $p90['pid']])[1];
eq([array_column($hist['history'], 'action'), $hist['history'][1]['meta']['from']['num'] ?? null, $hist['history'][1]['meta']['to']['num'] ?? null, $hist['history'][1]['by'], $hist['history'][1]['reason']],
    [['fact.add', 'fact.correct', 'fact.correct'], '90', '91.5', 'טל', 'טעות הקלדה'], 'היסטוריה מלאה: כל שינוי בנתון — מי, מתי, מאיזה ערך לאיזה, ולמה');

// ── 10-B2: rev ישן ← 409, והשרת לא השתנה ──
$rowB = $frow($p90['pid']);
$old = $fx($tal, $p90, ['value' => 100]);
$oldS = call('POST', 'fact-state', ['fact' => $p90['pid'], 'rev' => 1, 'do' => 'clarify', 'note' => 'x'], $tal);
$oldD = call('POST', 'fact-delete', ['fact' => $p90['pid'], 'rev' => 1, 'reason' => 'x'], $avi);
$noop = $fx($avi, ['pid' => $p90['pid'], 'rev' => 1], ['value' => 92]);
eq([$old[0], code($old), $old[1]['error']['message'], $old[1]['error']['current']['num'] ?? null, $old[1]['error']['current']['rev'] ?? null, code($oldS), code($oldD), code($noop), $frow($p90['pid']) === $rowB,
    code($fx($avi, ['pid' => $p90['pid'], 'rev' => '3'], ['value' => 1])), code($fx($avi, ['pid' => $p90['pid'], 'rev' => null], ['value' => 1])), code($fx($avi, ['pid' => $p90['pid'], 'rev' => 0], ['value' => 1]))],
    [409, 'fact_changed', 'הנתון עודכן בידי אבי', '92', 3, 'fact_changed', 'fact_changed', 'fact_changed', true, 'rev_required', 'rev_required', 'rev_required'],
    '10-B2 עדכון, שינוי מצב ומחיקה עם rev ישן ← 409 "הנתון עודכן בידי <שם>" עם הערך שבשרת; הנתון בשרת לא השתנה; rev חסר או מסוג שגוי ← נדחה');

// ── 10-B5 וכל §9: מי רשאי למה ──
$mk = fn() => $add($avi, $C, 'rooms', '3.5')[1]['fact'];
$st = fn(string $as, array $f, string $do, array $more = []) => call('POST', 'fact-state', $more + ['fact' => $f['pid'], 'rev' => $f['rev'], 'do' => $do], $as);
$f = $mk();
eq([code($st($tal, $f, 'verify')), code($st($ron, $f, 'verify')), code($st($gil, $f, 'verify')), code($st($boaz, $f, 'verify')), code($st($tal, $f, 'reject', ['reason' => 'x'])), $frow($f['pid'])['state']],
    ['forbidden', 'forbidden', 'not_found', 'not_found', 'forbidden', 'manual'], '10-B5 אימות ודחייה בידי מי שאינו שמאי בתיק (עובדת משרד, צופה) ← 403; מי שאינו משתתף ומשרד אחר ← 404; הנתון לא השתנה');
call('POST', 'case-member-update', ['case' => $C, 'user' => $talPid, 'perms' => ['review_docs' => true]], $avi);
$talReview = code($st($tal, $f, 'verify'));
call('POST', 'case-member-update', ['case' => $C, 'user' => $talPid, 'perms' => ['review_docs' => false]], $avi);
call('POST', 'case-member-update', ['case' => $C, 'user' => $danaPid, 'perms' => ['review_docs' => false]], $avi);
$danaNo = code($st($dana, $f, 'verify'));
call('POST', 'case-member-update', ['case' => $C, 'user' => $danaPid, 'perms' => ['review_docs' => true]], $avi);
$v = $st($dana, $f, 'verify');
eq([$talReview, $danaNo, $v[0], $v[1]['fact']['state'], $v[1]['fact']['verified_by'], code($st($dana, $v[1]['fact'], 'verify')), code($res($tal, [])), code($res($ron, [])),
    code(call('POST', 'conflict-dismiss', ['conflict' => $K, 'rev' => $kv()['rev'], 'reasoning' => 'x'], $tal)), $kv()['status']],
    ['forbidden', 'forbidden', 200, 'verified', 'דנה', 'fact_state', 'forbidden', 'forbidden', 'forbidden', 'open'],
    '10-B5 review_docs בלי תפקיד שמאי — לא מספיק; שמאית בלי review_docs — לא מספיק; שמאית שותפה עם review_docs מאמתת; הכרעה ו"אין סתירה" — אותו כלל');
$f2 = $mk();
eq([code($add($ron, $C, 'rooms', 4)), code($fx($ron, $f2, ['value' => 5])), code($st($ron, $f2, 'clarify', ['note' => 'x'])), code(call('POST', 'fact-delete', ['fact' => $f2['pid'], 'rev' => 1, 'reason' => 'x'], $ron)),
    code(call('POST', 'fact-delete', ['fact' => $f2['pid'], 'rev' => 1, 'reason' => 'x'], $tal)), $facts($ron, $C)[0], $facts($ron, $C)[1]['perms'], $facts($tal, $C)[1]['perms'], $facts($dana, $C)[1]['perms'],
    code(call('GET', 'sys-attributes', [], $avi)), code(call('POST', 'sys-attribute-save', ['key' => 'x'], $avi)), call('GET', 'sys-attributes', [], $owner)[0]],
    ['forbidden', 'forbidden', 'forbidden', 'forbidden', 'forbidden', 200, ['write' => false, 'judge' => false], ['write' => true, 'judge' => false], ['write' => true, 'judge' => true], 'not_found', 'not_found', 200],
    '§9 צופה (view) רואה ואינו מזין, מתקן, מסמן או מוחק; מי שמזין אינו מוחק נתון של אחר; הקטלוג — רק מנהל המערכת');
$t1 = $add($tal, $C, 'rooms', '3.5')[1]['fact'];
$t2 = $st($tal, $t1, 'clarify', ['note' => 'לא ברור אם כולל חצי חדר']);
$t3 = $st($tal, $t2[1]['fact'], 'manual');
eq([$t2[1]['fact']['state'], $t2[1]['fact']['note'], code($st($tal, $t1, 'clarify')), $t3[1]['fact']['state'], call('POST', 'fact-delete', ['fact' => $t1['pid'], 'rev' => $t3[1]['fact']['rev'], 'reason' => 'הוזן בטעות'], $tal)[0],
    code(call('GET', 'fact', [], $avi, ['fact' => $t1['pid']])), $frow($t1['pid'])['delete_reason'], in_array($t1['pid'], array_column($attrOf($facts($avi, $C), 'rooms')['facts'], 'pid'), true)],
    ['needs_clarification', 'לא ברור אם כולל חצי חדר', 'fact_note_required', 'manual', 200, 'not_found', 'הוזן בטעות', false],
    '§9 / מקרה 12: מי שמזין מסמן "דורש בירור" (עם הערה) ומחזיר; מוחק נתון שלו שעוד לא אומת — מחיקה רכה: השורה נשארת במסד, ואינה מוצגת');

// ── 10-B4: נתון מ-AI אינו מאומת בלי מקור שאפשר לפתוח ──
$attrRooms = apr_attr('floors_total');
$aiRow = fn(array $more) => apr_tx(fn() => apr_fact_insert($crow($C), $attrRooms, $more + ['value_num' => 500, 'state' => 'proposed', 'source_kind' => 'ai', 'method' => 'ai']));
$ai1 = $aiRow([]);
$F3 = up($avi, $C, 'ai-src');
$ai2 = $aiRow(['source_file_id' => (int)apr_val('SELECT id FROM files WHERE pid = ?', [$F3['file']]), 'source_page' => 1]);
$ai3 = $aiRow(['source_file_id' => (int)apr_val('SELECT id FROM files WHERE pid = ?', [$F3['file']])]);
$v1 = $st($avi, ['pid' => $ai1, 'rev' => 1], 'verify');
$v2 = $st($avi, ['pid' => $ai2, 'rev' => 1], 'verify');
call('POST', 'file-delete', ['file' => $F3['file'], 'reason' => 'הועלה בטעות'], $avi);
$v3 = $st($avi, ['pid' => $ai3, 'rev' => 1], 'verify');
$after2 = call('GET', 'fact', [], $avi, ['fact' => $ai2])[1]['fact'];
eq([code($v1), $frow($ai1)['state'], $v2[0], $v2[1]['fact']['state'] ?? null, code($v3), $frow($ai3)['state'],
    $raises("UPDATE facts SET state = 'verified', verified_by = 1, verified_at = 'x', rev = rev + 1 WHERE pid = ?", [$ai1]), $raises("UPDATE facts SET state = 'verified', rev = rev + 1 WHERE pid = ?", [$ai3]),
    $after2['state'], $after2['source']['doc_state'], $after2['source']['file'], $after2['verified_by'], $count('conflicts', "attribute_key = 'floors_total'")],
    ['fact_source_required', 'proposed', 200, 'verified', 'fact_source_required', 'proposed', true, true, 'needs_clarification', 'deleted', null, null, 0],
    '10-B4 / מקרה 6: נתון מ-AI בלי מקור (או שהקובץ שלו נמחק) אינו מאומת — גם לא ישירות במסד; "מאומת" הוא תמיד פעולת אדם; קובץ המקור נמחק ← הנתון נשאר, מסומן, וחוזר ל"דורש בירור"');

// ── 10-C1: שינוי נתון מסמן את מי שנשען עליו ──
$CR = $crow($C);
$u1 = $mk();
$u2 = $add($avi, $C, 'year_built', 1985)[1]['fact'];
$usePid = fn() => apr_pid();
[$calc, $sec, $comp] = [$usePid(), $usePid(), $usePid()];
apr_fact_use($CR, $u1['pid'], 'calc', $calc);
apr_fact_use($CR, $u1['pid'], 'report_section', $sec);
apr_fact_use($CR, $u2['pid'], 'comp', $comp);
$stale = fn(string $pid) => array_column(call('GET', 'fact', [], $GLOBALS['avi_'], ['fact' => $pid])[1]['uses'] ?? [], 'stale', 'type');
$s0 = [$stale($u1['pid']), $stale($u2['pid'])];
$u1v = $st($avi, $u1, 'verify')[1]['fact'];
$s1 = $stale($u1['pid']);
$u1c = $fx($tal, $u1v, ['note' => 'הערה בלבד'])[1]['fact'];
$s2 = $stale($u1['pid']);
$u1d = $fx($tal, $u1c, ['value' => 5])[1]['fact'];
$s3 = [$stale($u1['pid']), $stale($u2['pid']), apr_fact_uses_of($CR, 'calc', $calc)[0]['stale'], $u1d['state'], $u1d['verified_by']];
apr_fact_use($CR, $u1['pid'], 'calc', $calc);
$s4 = $stale($u1['pid']);
$st($avi, $u2, 'reject', ['reason' => 'שנת ההיתר, לא שנת הבנייה']);
$s5 = $stale($u2['pid']);
$threw = [];
foreach ([[$CR, $u2['pid'], 'calc'], [$crow($CB), $u1['pid'], 'calc'], [$CR, $u1['pid'], 'nope'], [$CR, 'nope', 'calc']] as [$cc, $fp, $ty]) { try { apr_fact_use($cc, $fp, $ty, $calc); $threw[] = null; } catch (AprError $e) { $threw[] = $e->errorCode; } }
eq([$s0, $s1, $s2, $s3, $s4, $s5, $threw],
    [[['calc' => false, 'report_section' => false], ['comp' => false]], ['calc' => false, 'report_section' => false], ['calc' => false, 'report_section' => false],
        [['calc' => true, 'report_section' => true], ['comp' => false], true, 'manual', null], ['calc' => false, 'report_section' => true], ['comp' => true], ['fact_state', 'not_found', 'link_invalid', 'not_found']],
    '10-C1 שינוי ערך ← כל fact_uses של הנתון stale (ורק שלו); אימות והערה אינם מסמנים; "נבדק מחדש" מוריד את הסימון; דחייה מסמנת; נתון מאומת שתוקן כבר אינו מאומת; אי אפשר להישען על נתון שנדחה או של תיק אחר');
$C5 = $newCase();
$w1 = $area($avi, $C5, 70, 'net')[1]['fact'];
apr_fact_use($crow($C5), $w1['pid'], 'alternative', $calc);
$area($avi, $C5, 75, 'net');
eq(apr_fact_uses_of($crow($C5), 'alternative', $calc)[0]['stale'], true, '10-C1 / §7 סתירה שנפתחה מסמנת stale גם את מי שנשען על הנתון הוותיק שבה');

// ── מקרה 8: הכרעה, ואז הנתון שנבחר נדחה ──
$k5 = $conflicts($avi, $C5)[0];
$w2 = array_values(array_filter($k5['facts'], fn($f) => $f['num'] === '75'))[0];
call('POST', 'conflict-resolve', ['conflict' => $k5['pid'], 'rev' => $k5['rev'], 'fact' => $w2['pid'], 'purpose' => 'לחישוב', 'reasoning' => 'מדידה'], $avi);
$st($avi, $w2, 'reject', ['reason' => 'המדידה נפסלה']);
$k5b = call('GET', 'conflict', [], $avi, ['conflict' => $k5['pid']])[1]['conflict'];
eq([$k5b['status'], $k5b['needs_human'], $k5b['resolutions'][0]['valid'], $k5b['resolutions'][0]['void_reason'], array_column($k5b['facts'], 'state'), array_column($k5b['facts'], 'active'),
    code(call('POST', 'conflict-resolve', ['conflict' => $k5['pid'], 'rev' => $k5b['rev'], 'fact' => $w2['pid'], 'purpose' => 'לחישוב', 'reasoning' => 'x'], $avi)),
    code($fx($avi, ['pid' => $w2['pid'], 'rev' => $frow($w2['pid'])['rev']], ['value' => 1])), $raises("UPDATE facts SET state = 'manual', rev = rev + 1 WHERE pid = ?", [$w2['pid']])],
    ['open', true, false, 'fact_rejected', ['manual', 'rejected'], [true, false], 'conflict_fact', 'fact_state', true],
    'מקרה 8: הנתון שנבחר נדחה ← ההכרעה "לא תקפה" (נשארת), הסתירה נפתחת מחדש ומחכה לאדם; נתון שנדחה נשאר להיסטוריה, אינו נבחר ואינו נערך');
$k5c = call('POST', 'conflict-resolve', ['conflict' => $k5['pid'], 'rev' => $k5b['rev'], 'fact' => $w1['pid'], 'purpose' => 'לחישוב', 'reasoning' => 'נשאר נתון אחד'], $avi);
$C6 = $newCase();
$g1 = $area($avi, $C6, 60, 'net')[1]['fact'];
$area($avi, $C6, 65, 'net');
$st($avi, $g1, 'reject', ['reason' => 'שייך לדירה אחרת']);
$k6 = $conflicts($avi, $C6)[0];
eq([$k5c[1]['conflict']['status'] ?? null, $k6['status'], $k6['close_kind'], $count('tasks', "kind = 'clarification' AND status = 'open' AND case_id = " . $caseId($C6)), $attrOf($facts($avi, $C6), 'area')['value']['num'] ?? null],
    ['resolved', 'resolved', 'auto', 0, '65'], '§6.3 דחיית אחד משני הנתונים מיישבת את הסתירה לבד (והמשימה נסגרת); הערך שנשאר הוא הערך של המאפיין');

// ── §6.3 "אין סתירה", עם תיקון סוג השטח באותה פעולה ──
$k3 = $c3[0];
$dis = fn(array $more) => call('POST', 'conflict-dismiss', $more + ['conflict' => $k3['pid'], 'rev' => $k3['rev'], 'reasoning' => 'השטח הראשון הוא השטח הרשום'], $avi);
eq([code($dis(['reasoning' => ''])), code($dis(['rev' => 9])), code($dis(['fixes' => [['fact' => $a80[1]['fact']['pid'], 'rev' => 1, 'area_kind' => 'registered']]])), code($dis(['fixes' => [['fact' => $u80[1]['fact']['pid'], 'rev' => 1, 'area_kind' => 'nope']]])),
    code($dis(['fixes' => [['fact' => $u80[1]['fact']['pid'], 'rev' => 7, 'area_kind' => 'registered']]])), code($dis(['fixes' => 'x'])), $frow($u80[1]['fact']['pid'])['area_kind'], $conflicts($avi, $C3)[0]['status']],
    ['reasoning_required', 'conflict_changed', 'not_found', 'area_kind_required', 'fact_changed', 'content_invalid', 'unknown', 'open'], '"אין סתירה" בלי נימוק, עם rev ישן, עם נתון של תיק אחר, או עם תיקון לא תקין ← נדחה כולו (גם התיקון לא נשמר)');
$d = $dis(['fixes' => [['fact' => $u80[1]['fact']['pid'], 'rev' => 1, 'area_kind' => 'registered']]]);
$u80r = $frow($u80[1]['fact']['pid']);
eq([$d[0], $d[1]['conflict']['status'], $d[1]['conflict']['close_reason'], $u80r['area_kind'], $u80r['original_area_kind'], (int)$u80r['value_num'], code($dis(['rev' => $d[1]['conflict']['rev'] ?? 0])), $count('conflicts', 'case_id = ' . $caseId($C3))],
    [200, 'dismissed', 'השטח הראשון הוא השטח הרשום', 'registered', 'unknown', 8000, 'conflict_state', 1], '§6.3 "אין סתירה" עם נימוק ותיקון סוג השטח באותה פעולה: הסוג המקורי נשמר, הערך לא נגע, והסתירה נסגרה');

// ── 10-C2: מצב הנתון ומצב המסמך — שני מסלולים נפרדים ──
$link = apr_row('SELECT rf.pid, rf.review_state, r.status FROM requirement_files rf JOIN requirements r ON r.id = rf.requirement_id JOIN files f ON f.id = rf.file_id WHERE f.pid = ?', [$F1['file']]);
$snap = $allFacts();
$ap = call('POST', 'file-review', ['link' => $link['pid'], 'do' => 'approve', 'chk_readable' => true, 'chk_complete' => true, 'chk_matches' => true], $avi);
$sameFacts = $allFacts() === $snap;
$docBefore = apr_rows('SELECT id, review_state, reviewed_by FROM requirement_files ORDER BY id') + ['r' => apr_rows('SELECT id, status FROM requirements ORDER BY id')];
$F4 = up($avi, $C, 'plan-2', ['requirement' => apr_val("SELECT pid FROM requirements WHERE case_id = ? AND origin_key = 'plan'", [$caseId($C)])]);
$fromPlan = $add($avi, $C, 'year_built', 1990, ['source' => ['kind' => 'document', 'file' => $F4['file']]])[1]['fact'];
$docBefore = [apr_rows('SELECT id, review_state, reviewed_by FROM requirement_files ORDER BY id'), apr_rows('SELECT id, status FROM requirements ORDER BY id')];
$vf = $st($avi, $fromPlan, 'verify');
$docAfter = [apr_rows('SELECT id, review_state, reviewed_by FROM requirement_files ORDER BY id'), apr_rows('SELECT id, status FROM requirements ORDER BY id')];
$link4 = apr_val('SELECT rf.pid FROM requirement_files rf JOIN files f ON f.id = rf.file_id WHERE f.pid = ?', [$F4['file']]);
call('POST', 'file-review', ['link' => $link4, 'do' => 'return', 'return_code' => 'missing_pages', 'return_reason' => 'חסר עמוד', 'chk_readable' => true, 'chk_complete' => false], $avi);
$ret = call('GET', 'fact', [], $avi, ['fact' => $fromPlan['pid']])[1]['fact'];
eq([$ap[0], $sameFacts, $vf[1]['fact']['state'], $docAfter === $docBefore, $ret['state'], $ret['source']['doc_state'], $ret['rev'] === $vf[1]['fact']['rev']],
    [200, true, 'verified', true, 'verified', 'returned', true],
    '10-C2 / מקרה 7: אישור המסמך אינו משנה אף נתון; אימות נתון אינו משנה את מצב המסמך או הדרישה; מסמך שהוחזר — הנתון נשאר במצבו, מסומן "המסמך הוחזר להשלמה"');

// ── 10-C3: בידוד — משרד אחר, מי שאינו משתתף, ומזהי בן ──
$FB = up($boaz, $CB, 'boaz-file');
$fb = $add($boaz, $CB, 'rooms', 3)[1]['fact'];
$fbd = $add($boaz, $CB, 'year_built', 2001, ['source' => ['kind' => 'document', 'file' => $FB['file']]])[1]['fact'];
$area($boaz, $CB, 50, 'net');
$area($boaz, $CB, 55, 'net');
$KB = $conflicts($boaz, $CB)[0];
$probe = fn(string $as, string $case, string $fact, string $conf, string $file, string $inConf) => [
    code(call('GET', 'case-facts', [], $as, ['case' => $case])), code(call('GET', 'case-conflicts', [], $as, ['case' => $case])), code(call('GET', 'fact', [], $as, ['fact' => $fact])),
    code(call('GET', 'conflict', [], $as, ['conflict' => $conf])), code(call('GET', 'file-facts', [], $as, ['file' => $file])),
    code(call('POST', 'fact-add', ['case' => $case, 'attribute' => 'rooms', 'value' => 3, 'source' => ['kind' => 'user']], $as)), code(call('POST', 'fact-correct', ['fact' => $fact, 'rev' => 1, 'value' => 9], $as)),
    code(call('POST', 'fact-state', ['fact' => $fact, 'rev' => 1, 'do' => 'verify'], $as)), code(call('POST', 'fact-state', ['fact' => $fact, 'rev' => 1, 'do' => 'reject', 'reason' => 'x'], $as)),
    code(call('POST', 'fact-delete', ['fact' => $fact, 'rev' => 1, 'reason' => 'x'], $as)),
    code(call('POST', 'conflict-resolve', ['conflict' => $conf, 'rev' => 1, 'fact' => $inConf, 'purpose' => 'x', 'reasoning' => 'x'], $as)), code(call('POST', 'conflict-dismiss', ['conflict' => $conf, 'rev' => 1, 'reasoning' => 'x'], $as))];
$snap = [$allFacts(), apr_rows('SELECT * FROM conflicts'), $count('conflict_resolutions'), $count('audit_log')];
$nf = array_fill(0, 12, 'not_found');
$ghost = str_repeat('z', 16);
eq([$probe($avi, $CB, $fb['pid'], $KB['pid'], $FB['file'], $KB['facts'][0]['pid']), $probe($gil, $C, $a80[1]['fact']['pid'], $K, $F1['file'], $a80[1]['fact']['pid']), $probe($boaz, $C, $a80[1]['fact']['pid'], $K, $F1['file'], $a80[1]['fact']['pid']),
    $probe($avi, $ghost, $ghost, $ghost, $ghost, $ghost), [$allFacts(), apr_rows('SELECT * FROM conflicts'), $count('conflict_resolutions'), $count('audit_log')] === $snap],
    [$nf, $nf, $nf, $nf, true], '10-C3 נתונים, סתירות והכרעות של תיק ממשרד אחר, או של תיק שאינני משתתף בו — דרך מזהה התיק, הנתון, הסתירה והקובץ ← 404, זהה למזהה שלא קיים; דבר לא נכתב');
eq([code($add($boaz, $CB, 'rooms', 3, ['source' => ['kind' => 'document', 'file' => $F1['file']]])), code($fx($boaz, $fbd, ['source' => ['file' => $F1['file']]])),
    code(call('POST', 'conflict-resolve', ['conflict' => $KB['pid'], 'rev' => $KB['rev'], 'fact' => $a80[1]['fact']['pid'], 'purpose' => 'x', 'reasoning' => 'x'], $boaz)),
    code(call('POST', 'conflict-dismiss', ['conflict' => $KB['pid'], 'rev' => $KB['rev'], 'reasoning' => 'x', 'fixes' => [['fact' => $a80[1]['fact']['pid'], 'rev' => 1, 'area_kind' => 'net']]], $boaz)),
    code(call('POST', 'conflict-resolve', ['conflict' => $K, 'rev' => $kv()['rev'], 'fact' => $w1['pid'], 'purpose' => 'x', 'reasoning' => 'x'], $avi)), apr_val('SELECT f.pid FROM files f JOIN facts x ON x.source_file_id = f.id WHERE x.pid = ?', [$fbd['pid']]) === $FB['file'], $frow($a80[1]['fact']['pid']) === $before[0]],
    ['source_file_required', 'source_file_required', 'conflict_fact', 'not_found', 'conflict_fact', true, true],
    '10-C3 ערבוב מזהים: קובץ של משרד אחר כמקור, נתון של תיק אחר בהכרעה או בתיקון ← נדחה; הנתון של המשרד האחר לא נגע');

// ── 10-C4: מי שאין לו גישה לקובץ המקור ──
$ronId = (int)apr_val('SELECT id FROM users WHERE pid = ?', [$ronPid]);
$talId = (int)apr_val('SELECT id FROM users WHERE pid = ?', [$talPid]);
$GLOBALS['apr_file_visible'] = fn(array $ctx, array $c, array $f) => !in_array((int)$ctx['user']['id'], [$ronId, $talId], true);
$secret = [$F1['file'], 'contract.pdf', 'onerror', 'סעיף 3.1', '2025-01-15', $F2['file'], 'plan.pdf'];
$leaks = fn(array $res) => array_values(array_filter($secret, fn($s) => str_contains(json_encode($res[1], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $s)));
$ronFact = call('GET', 'fact', [], $ron, ['fact' => $a80[1]['fact']['pid']]);
$stale409 = $fx($tal, ['pid' => $a80[1]['fact']['pid'], 'rev' => 99], ['note' => 'x']);
$views = [$facts($ron, $C), $ronFact, call('GET', 'case-conflicts', [], $ron, ['case' => $C]), call('GET', 'conflict', [], $ron, ['conflict' => $K]), [200, $act = array_values(array_filter(call('GET', 'case-activity', [], $ron, ['case' => $C])[1]['rows'], fn($r) => in_array($r['entity_type'], ['fact', 'conflict'], true)))],
    call('GET', 'case', [], $ron, ['case' => $C]), call('GET', 'my-tasks', [], $ron), $stale409, $fx($tal, $a80[1]['fact'], ['note' => 'הערה של טל'])];
eq([$ronFact[0], $ronFact[1]['fact']['source'], $ronFact[1]['fact']['num'], array_map($leaks, $views), code($stale409), code(call('GET', 'file-facts', [], $ron, ['file' => $F1['file']])), code(call('GET', 'file', [], $ron, ['file' => $F1['file']])),
    code($add($tal, $C, 'rooms', 4, ['source' => ['kind' => 'document', 'file' => $F1['file']]])), call('GET', 'fact', [], $avi, ['fact' => $a80[1]['fact']['pid']])[1]['fact']['source']['file'],
    count($leaks($facts($avi, $C))) >= 5, call('GET', 'file-facts', [], $avi, ['file' => $F1['file']])[1]['facts'][0]['source']['page'] ?? null, count($act) > 10],
    [200, ['kind' => 'document', 'restricted' => true], '80', array_fill(0, 9, []), 'fact_changed', 'not_found', 'not_found', 'source_file_required', $F1['file'], true, 1, true],
    '10-C4 שותף בלי גישה לקובץ המקור מקבל את הנתון עם "מקור: מסמך" בלבד — בלי מזהה, שם, עמוד, ציטוט או תאריך: ברשימה, בנתון, בסתירות, בשורות הנתונים והסתירות שבפעילות התיק, בתשובת 409 ובתשובה לתיקון; הקובץ עצמו ← 404; בעל גישה רואה הכול');
unset($GLOBALS['apr_file_visible']);

// ── תיק סגור — קריאה בלבד ──
call('POST', 'case-status', ['case' => $C2, 'status' => 'closed', 'reason' => 'הסתיים'], $avi);
$fc = $attrOf($facts($avi, $C2), 'area')['facts'][0];
eq([$crow($C2)['status'], $facts($avi, $C2)[0], $facts($avi, $C2)[1]['perms'], code($area($avi, $C2, 1, 'net')), code($fx($avi, $fc, ['value' => 1])), code($st($avi, $fc, 'verify')), code($st($avi, $fc, 'clarify', ['note' => 'x'])),
    code(call('POST', 'fact-delete', ['fact' => $fc['pid'], 'rev' => $fc['rev'], 'reason' => 'x'], $avi)), call('GET', 'fact', [], $avi, ['fact' => $fc['pid']])[0]],
    ['closed', 200, ['write' => false, 'judge' => false], 'case_closed', 'case_closed', 'case_closed', 'case_closed', 'case_closed', 200], 'תיק סגור = קריאה בלבד: הזנה, תיקון, אימות, סימון ומחיקה ← 409; הצפייה נשארת');

// ── הקטלוג (§3) ──
$sys = call('GET', 'sys-attributes', [], $owner)[1];
$save = fn(array $in) => call('POST', 'sys-attribute-save', $in, $owner);
$new = ['key' => 'ceiling_height', 'create' => true, 'label' => 'גובה תקרה', 'group' => 'features', 'value_type' => 'number', 'unit_kind' => 'length', 'decimals' => 2];
$areaAttr = array_values(array_filter($sys['attributes'], fn($a) => $a['key'] === 'area'))[0];
$upd = fn(array $more) => $save($more + array_intersect_key($areaAttr, array_flip(['key', 'label', 'group', 'value_type', 'unit_kind', 'has_area_kind', 'multi', 'decimals', 'allow_negative', 'status', 'sort'])));
$bs = array_values(array_filter($sys['attributes'], fn($a) => $a['key'] === 'build_state'))[0];
eq([count($sys['attributes']), $areaAttr['in_use'], $areaAttr['example'], $save($new)[0], code($save($new)), code($save(['key' => 'Bad Key'] + $new)), code($save(['key' => 'no_such', 'create' => false] + $new)),
    code($save(['key' => 'm1', 'value_type' => 'money', 'unit_kind' => 'none'] + $new)), code($save(['key' => 'm2', 'value_type' => 'choice', 'unit_kind' => 'none'] + $new)), code($save(['key' => 'm3', 'has_area_kind' => true] + $new)),
    code($upd(['multi' => true])), code($upd(['value_type' => 'text', 'unit_kind' => 'none', 'has_area_kind' => false])), code($upd(['decimals' => 0])), $upd(['label' => 'שטח הנכס', 'status' => 'hidden'])[1]['attribute']['label'] ?? null,
    code($save(['choices' => [['key' => 'land', 'label' => 'קרקע']]] + array_intersect_key($bs, array_flip(['key', 'label', 'group', 'value_type', 'unit_kind'])))),
    code($area($avi, $C5, 71, 'net')), $attrOf($facts($avi, $C5), 'area')['label'], $add($avi, $C5, 'ceiling_height', '2.75', ['unit' => 'm'])[1]['fact']['num'] ?? null, $count('attributes')],
    [27, true, true, 200, 'attr_exists', 'attr_invalid', 'not_found', 'attr_invalid', 'attr_invalid', 'attr_invalid', 'attr_in_use', 'attr_in_use', 'attr_in_use', 'שטח הנכס', 'attr_in_use', 'attr_invalid', 'שטח הנכס', '2.75', 28],
    '§3 הקטלוג: 27 מאפייני דוגמה; מנהל המערכת מוסיף ועורך; מאפיין בשימוש — המבנה נעול ואפשרות שנבחרה אינה מוסרת; מאפיין מוסתר אינו מוצע להזנה, והנתונים שלו נשארים מוצגים; אין מחיקה');
$upd(['status' => 'active']);

// ── קלט מסוג שגוי בכל הפעולות ──
$worst = 0;
$junk = [null, 5, 1.5, true, ['x'], ['a' => ['b' => 1]], str_repeat('א', 3000), "\0", '', -1, PHP_INT_MAX];
foreach ($junk as $j) {
    foreach ([['GET', 'case-facts', ['case' => $j]], ['GET', 'fact', ['fact' => $j]], ['GET', 'conflict', ['conflict' => $j]], ['GET', 'case-conflicts', ['case' => $j]], ['GET', 'file-facts', ['file' => $j]]] as [$m, $a, $q]) {
        $worst = max($worst, call($m, $a, [], $avi, is_scalar($j) || $j === null ? array_map('strval', array_filter($q, fn($x) => $x !== null)) : $q)[0]);
    }
    foreach ([['fact-add', ['case' => $C5, 'attribute' => 'area', 'value' => $j, 'unit' => $j, 'area_kind' => $j, 'estimated' => $j, 'zero_ok' => $j, 'note' => $j, 'state' => $j, 'source' => $j]],
        ['fact-add', ['case' => $C5, 'attribute' => $j, 'value' => 1, 'source' => ['kind' => $j, 'file' => $j, 'page' => $j, 'locator' => $j, 'date' => $j, 'note' => $j]]],
        ['fact-add', ['case' => $C5, 'attribute' => 'rooms', 'value' => 1, 'source' => ['kind' => 'document', 'file' => $F1['file'], 'page' => $j, 'locator' => $j, 'date' => $j]]],
        ['fact-add', ['case' => $j, 'attribute' => 'rooms', 'value' => 1, 'source' => $USER]],
        ['fact-correct', ['fact' => $w1['pid'], 'rev' => $j, 'value' => $j, 'unit' => $j, 'area_kind' => $j, 'estimated' => $j, 'note' => $j, 'source' => $j, 'correction_note' => $j]],
        ['fact-correct', ['fact' => $j, 'rev' => 1]], ['fact-state', ['fact' => $w1['pid'], 'rev' => $j, 'do' => $j, 'reason' => $j, 'note' => $j]], ['fact-state', ['fact' => $j, 'rev' => 1, 'do' => 'verify']],
        ['fact-delete', ['fact' => $w1['pid'], 'rev' => $j, 'reason' => $j]], ['conflict-resolve', ['conflict' => $k5['pid'], 'rev' => $j, 'fact' => $j, 'purpose' => $j, 'reasoning' => $j]],
        ['conflict-resolve', ['conflict' => $j, 'rev' => 1]], ['conflict-dismiss', ['conflict' => $k5['pid'], 'rev' => $j, 'reasoning' => $j, 'fixes' => $j]],
        ['conflict-dismiss', ['conflict' => $K, 'rev' => $kv()['rev'], 'reasoning' => 'x', 'fixes' => [$j, ['fact' => $j, 'rev' => $j, 'area_kind' => $j]]]]] as [$a, $in]) {
        $worst = max($worst, call('POST', $a, $in, $avi)[0]);
    }
    $worst = max($worst, call('POST', 'sys-attribute-save', ['key' => $j, 'create' => $j, 'label' => $j, 'group' => $j, 'value_type' => $j, 'unit_kind' => $j, 'has_area_kind' => $j, 'choices' => $j, 'multi' => $j, 'decimals' => $j, 'sort' => $j, 'status' => $j], $owner)[0],
        call('POST', 'sys-attribute-save', ['key' => 'jj', 'create' => true, 'label' => 'x', 'group' => 'deal', 'value_type' => 'choice', 'choices' => [$j, ['key' => $j, 'label' => $j]]], $owner)[0]);
}
check('קלט מסוג שגוי בכל הפעולות: אף 500', $worst < 500, $worst);
eq($warnings, [], 'ובלי אף אזהרה של PHP לאורך כל הקובץ');
eq([$count('facts', 'deleted_at IS NULL AND (value_num = 0 AND pid NOT IN (' . apr_db()->quote($z[1]['fact']['pid']) . '))'), $count('facts', "value_text = '' OR value_text = 'null' OR unit = '' OR area_kind = ''"),
    (int)apr_db()->query('PRAGMA foreign_key_check')->fetchColumn()], [0, 0, 0], '10-A1 במסד, אחרי כל הקובץ: אין אפס שלא אושר, אין מחרוזת ריקה ואין "null" בשדה ערך; אין מפתח זר שבור');

// ── מרוצים: שש הזנות במקביל ← סתירה אחת; שישה תיקונים עם אותו rev ← אחד מצליח ──
$dir = sys_get_temp_dir() . '/apr-conc-' . bin2hex(random_bytes(5));
mkdir($dir, 0700, true);
$setup = 'define("APR_DATA_DIR", ' . var_export($dir, true) . '); define("APR_DB_PATH", ' . var_export("$dir/app.sqlite", true) . '); $_SERVER["argv"] = []; require ' . var_export(__DIR__ . '/lib.php', true) . ';
    $as = ident("2", "אבי", "avi@example.com"); office_for($as, "משרד");
    $p = call("POST", "profile-create", ["scope" => "office", "name" => "p"], $as)[1]["profile"];
    [, $d] = call("POST", "profile-draft-open", ["profile" => $p], $as);
    $s = call("POST", "profile-draft-save", ["profile" => $p, "content" => ["flags" => [], "requirements" => ["groups" => [], "items" => []], "tasks" => ["items" => []], "rules" => []], "revision" => $d["draft"]["revision"]], $as);
    call("POST", "profile-publish", ["profile" => $p, "note" => "", "revision" => $s[1]["revision"]], $as);
    $c = call("POST", "case-create", ["profile" => $p], $as)[1]["case"];
    call("POST", "case-update", ["case" => $c, "title" => "t", "purpose" => "purchase"], $as);
    call("POST", "case-identifier-add", ["case" => $c, "kind" => "parcel", "gush" => "1", "helka" => "2"], $as);
    call("POST", "case-party-add", ["case" => $c, "name" => "x", "kind" => "client", "is_orderer" => true], $as);
    call("POST", "case-start", ["case" => $c], $as);
    $f = call("POST", "fact-add", ["case" => $c, "attribute" => "rooms", "value" => 3, "source" => ["kind" => "user"]], $as);
    echo json_encode([$c, $f[1]["fact"]["pid"] ?? $f]);';
[$kc, $kf] = json_decode(trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($setup))), true) ?? [null, null];
$race = function (string $mode, string $target) use ($dir): array {
    $procs = $pipes = [];
    $go = (string)(microtime(true) + 1.5);
    for ($i = 0; $i < 6; $i++) $procs[] = proc_open([PHP_BINARY, __FILE__, $mode, $dir, $target, $go, (string)(80 + $i)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    $out = [];
    foreach ($procs as $i => $proc) { $out[] = trim((string)stream_get_contents($pipes[$i][1])); proc_close($proc); }
    sort($out);
    return $out;
};
$adds = $race('adder', (string)$kc);
$pdo = new PDO("sqlite:$dir/app.sqlite");
$one = fn(string $sql) => $pdo->query($sql)->fetchColumn();
eq([$adds, (int)$one('SELECT COUNT(*) FROM facts WHERE attribute_key = \'area\''), (int)$one('SELECT COUNT(*) FROM conflicts'), (int)$one('SELECT COUNT(*) FROM conflict_facts'),
    (int)$one("SELECT COUNT(*) FROM tasks WHERE kind = 'clarification' AND status = 'open'"), (int)$one("SELECT COUNT(*) FROM audit_log WHERE action = 'conflict.open'"), (int)$one("SELECT COUNT(*) FROM audit_log WHERE action = 'fact.add'")],
    [array_fill(0, 6, '200'), 6, 1, 6, 1, 1, 7], '10-B2 מרוץ: שש הזנות של ערכים שונים במקביל ← כולן נשמרות, סתירה אחת, משימת בירור אחת, ושורת יומן לכל כתיבה');
$fixes = $race('fixer', (string)$kf);
eq([$fixes, (int)$one('SELECT rev FROM facts WHERE pid = ' . $pdo->quote((string)$kf)), (int)$one('SELECT original_num FROM facts WHERE pid = ' . $pdo->quote((string)$kf)), (int)$one("SELECT COUNT(*) FROM audit_log WHERE action = 'fact.correct'")],
    [['200', '409', '409', '409', '409', '409'], 2, 300, 1], '10-B2 מרוץ: שישה תיקונים עם אותו rev במקביל ← אחד מצליח וחמישה מקבלים 409; המקורי נשמר פעם אחת');
$pdo = null;

// ── מיגרציה 009 על מסד v8 מאוכלס: כל שורה נשמרת, והזריעה אידמפוטנטית ──
$m8 = sys_get_temp_dir() . '/apr-m8-' . bin2hex(random_bytes(5));
mkdir($m8, 0700, true);
foreach (glob(__DIR__ . '/../lib/migrations/*.sql') as $mf) if ((int)basename($mf) <= 8) copy($mf, $m8 . '/' . basename($mf));
$pdoOpt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$v8 = new PDO("sqlite:$dir/v8.sqlite", null, null, $pdoOpt);
apr_migrate($v8, $m8);
$v8->exec('PRAGMA foreign_keys = OFF');
$v8->exec('ATTACH ' . $v8->quote("$dir/app.sqlite") . ' AS live');
$tables = array_column($v8->query("SELECT name FROM main.sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name <> 'schema_migrations'")->fetchAll(), 'name');
foreach ($tables as $t) { $v8->exec("DELETE FROM main.\"$t\""); $v8->exec("INSERT INTO main.\"$t\" SELECT * FROM live.\"$t\""); }
$v8->exec('DETACH live');
$v8->exec('PRAGMA foreign_keys = ON');
$dump = fn(PDO $d) => array_map(fn($t) => [$t, hash('sha256', json_encode($d->query("SELECT * FROM \"$t\" ORDER BY 1")->fetchAll()))], $tables);
$d8 = $dump($v8);
$rows8 = array_sum(array_map(fn($t) => (int)$v8->query("SELECT COUNT(*) FROM \"$t\"")->fetchColumn(), $tables));
apr_migrate($v8);
$fresh = new PDO('sqlite::memory:', null, null, $pdoOpt);
apr_migrate($fresh);
$shape = fn(PDO $d) => json_encode($d->query("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' AND name <> 'schema_migrations' ORDER BY name")->fetchAll());
$seedSql = preg_match('/INSERT OR IGNORE INTO attributes.*?;\n/s', (string)file_get_contents(__DIR__ . '/../lib/migrations/009_facts.sql'), $sm) ? $sm[0] : '';
$v8->exec("UPDATE attributes SET label = 'שם שהמנהל שינה' WHERE key = 'rooms'");
$v8->exec($seedSql);
eq([apr_schema_version($v8), count($tables) > 30, $rows8 > 30, $dump($v8) === $d8, $shape($v8) === $shape($fresh), (int)$v8->query('SELECT COUNT(*) FROM attributes')->fetchColumn(),
    $v8->query("SELECT label FROM attributes WHERE key = 'rooms'")->fetchColumn(), (int)$v8->query('SELECT COUNT(*) FROM facts')->fetchColumn(), count($v8->query('PRAGMA foreign_key_check')->fetchAll()),
    trim((string)shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' diff --stat origin/main -- lib/migrations/00[1-8]_*.sql 2>/dev/null'))],
    [9, true, true, true, true, 27, 'שם שהמנהל שינה', 0, 0, ''],
    'מיגרציה 009 על מסד v8 מאוכלס: כל שורה בכל טבלה קיימת נשמרת כפי שהיא, המבנה זהה למסד חדש, הקטלוג נזרע (27) — וזריעה חוזרת אינה מכפילה ואינה דורסת שינוי של המנהל; 001–008 לא נגעו');
$v8 = null;
$rm = function (string $d) use (&$rm) { foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $f) is_dir("$d/$f") ? $rm("$d/$f") : @unlink("$d/$f"); @rmdir($d); };
$rm($dir);
$rm($m8);

done();
