<?php
// פרוסה 09 — מקורות מידע, בדיקות, תכנון והיתרים, המסלול הידני (docs/spec/09-sources.md §12): 09-A1 … 09-C5, מקרי הקצה, ההרשאות, הבידוד והמיגרציה.
$dir = sys_get_temp_dir() . '/apr-test-' . bin2hex(random_bytes(5));
mkdir($dir, 0700, true);
define('APR_DATA_DIR', $dir);
define('APR_DB_PATH', $dir . '/app.sqlite');
require __DIR__ . '/lib.php';

$warnings = [];
$GLOBALS['apr_upload_rate'] = [100000, 600];
set_error_handler(function (int $no, string $msg, string $file, int $line) use (&$warnings) { if (!(error_reporting() & $no)) return true; $warnings[] = "$msg @" . basename($file) . ":$line"; return true; });
// שעון קפוא: שום בדיקה כאן אינה תלויה בשעון הקיר
$T0 = 1794400000;
$GLOBALS['apr_time'] = $T0;

$owner = ident('1', 'הבעלים', 'owner@example.com', ['platform_admin' => true]);   // מנהל המערכת
$avi = ident('2', 'אבי', 'avi@example.com');       // מנהל משרד א, שמאי — האחראי על התיקים
$dana = ident('3', 'דנה', 'dana@example.com');     // שמאית: שותפה בתיק
$tal = ident('5', 'טל', 'tal@example.com');        // עובדת משרד: sources, לא שמאית
$gil = ident('7', 'גיל', 'gil@example.com');       // שמאי במשרד א שאינו משתתף בתיקים
$ron = ident('8', 'רון', 'ron@example.com');       // view בלבד
$noa = ident('9', 'נועה', 'noa@example.com');      // מתמחה: שותפה בתיק עם sources — ועדיין אינה שמאית
$yael = ident('10', 'יעל', 'yael@example.com');    // שמאית במשרד: בתיק כצופה עם sources — לא אחראית ולא שותפה
$boaz = ident('4', 'בועז', 'boaz@example.com');    // משרד ב
call('GET', 'me', [], $owner);
office_for($avi, 'משרד א');
office_for($boaz, 'משרד ב');
$danaPid = join_office($avi, $dana, 'dana@example.com', 'appraiser');
$talPid = join_office($avi, $tal, 'tal@example.com', 'staff');
join_office($avi, $gil, 'gil@example.com', 'appraiser');
$ronPid = join_office($avi, $ron, 'ron@example.com', 'staff');
$noaPid = join_office($avi, $noa, 'noa@example.com', 'trainee');
$yaelPid = join_office($avi, $yael, 'yael@example.com', 'appraiser');

function make_profile(string $as, array $sources = []): string
{
    $pid = call('POST', 'profile-create', ['scope' => 'office', 'name' => 'פרופיל'], $as)[1]['profile'];
    $it = ['key' => 'contract', 'group' => '', 'doc_type' => 'rights', 'title' => 'הסכם מכר', 'priority' => 'normal', 'mandatory' => false, 'party_kind' => 'client', 'due' => ['mode' => 'relative', 'days' => 3], 'needs_review' => true, 'when' => null, 'sort' => 1];
    [, $d] = call('POST', 'profile-draft-open', ['profile' => $pid], $as);
    $s = call('POST', 'profile-draft-save', ['profile' => $pid, 'content' => ['flags' => [], 'requirements' => ['groups' => [], 'items' => [$it]], 'tasks' => ['items' => []], 'rules' => []] + ($sources ? ['sources' => $sources] : []), 'revision' => $d['draft']['revision']], $as);
    $r = call('POST', 'profile-publish', ['profile' => $pid, 'note' => '', 'revision' => $s[1]['revision'] ?? ''], $as);
    if ($r[0] !== 200) throw new RuntimeException('publish: ' . json_encode([$s[1], $r[1]], JSON_UNESCAPED_UNICODE));
    return $pid;
}
/** $ident: המזהה של התיק — ברירת המחדל גוש וחלקה בירושלים; ['kind' => 'other', …] = מזהה שאינו מספיק לבדיקת מקור. */
function make_case(string $as, string $profile, array $ident = ['kind' => 'parcel', 'gush' => '6158', 'helka' => '12']): string
{
    $c = call('POST', 'case-create', ['profile' => $profile], $as)[1]['case'];
    call('POST', 'case-update', ['case' => $c, 'title' => 'דירה ברחוב הרצל', 'purpose' => 'purchase'], $as);
    $i = call('POST', 'case-identifier-add', ['case' => $c] + $ident, $as);
    if ($i[0] !== 200) throw new RuntimeException('identifier: ' . json_encode($i[1], JSON_UNESCAPED_UNICODE));
    call('POST', 'case-party-add', ['case' => $c, 'name' => 'משה לקוח', 'kind' => 'client', 'is_orderer' => true], $as);
    $s = call('POST', 'case-start', ['case' => $c], $as);
    if ($s[0] !== 200) throw new RuntimeException('case-start: ' . json_encode($s[1], JSON_UNESCAPED_UNICODE));
    return $c;
}
function up(string $as, string $case, string $mark): string
{
    $tmp = tempnam(apr_upload_tmp_dir(), 'up');
    file_put_contents($tmp, "%PDF-1.4\n%$mark\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
    $r = call('POST', 'file-upload', ['case' => $case, 'name' => "$mark.pdf"], $as, [], ['env' => ['upload' => $tmp]]);
    if ($r[0] !== 200) throw new RuntimeException('upload: ' . json_encode($r[1], JSON_UNESCAPED_UNICODE));
    return $r[1]['file']['pid'] ?? $r[1]['file'];
}

$count = fn(string $t, string $w = '1', array $a = []) => (int)apr_val("SELECT COUNT(*) FROM $t WHERE $w", $a);
$caseId = fn(string $pid) => (int)apr_val('SELECT id FROM cases WHERE pid = ?', [$pid]);
$raises = function (string $sql, array $args = []): bool { try { apr_exec($sql, $args); return false; } catch (PDOException) { return true; } };
$dumpAll = fn() => hash('sha256', json_encode([apr_rows('SELECT * FROM source_checks ORDER BY id'), apr_rows('SELECT * FROM planning_items ORDER BY id'), apr_rows('SELECT * FROM sources ORDER BY id'), apr_rows('SELECT * FROM tasks ORDER BY id')]));
$rec = fn(string $as, string $case, string $source, string $status, array $more = []) => call('POST', 'source-check-record', $more + ['case' => $case, 'source' => $source, 'status' => $status], $as);
$srcOf = fn(array $res, string $key) => array_values(array_filter($res[1]['sources'] ?? [], fn($s) => $s['key'] === $key))[0] ?? null;
$cs = fn(string $as, string $case) => call('GET', 'case-sources', [], $as, ['case' => $case]);
$tasksOf = fn(string $case, string $like) => apr_rows("SELECT * FROM tasks WHERE case_id = ? AND origin_key LIKE ? ORDER BY id", [$caseId($case), $like]);
/** כל המפתחות וכל המחרוזות של תשובה, שטוחים — לחיפוש שדה או ניסוח שמסיק "אין". */
$flat = function (mixed $v) use (&$flat): array {
    $out = [];
    if (is_array($v)) foreach ($v as $k => $x) { if (is_string($k)) $out[] = "K:$k"; $out = array_merge($out, $flat($x)); }
    elseif (is_string($v)) $out[] = "V:$v";
    return $out;
};
/** כל הבדיקות שבתשובה, בכל עומק: כל מערך שיש בו status ו-disclaimer. */
$checksIn = function (mixed $v) use (&$checksIn): array {
    $out = [];
    if (!is_array($v)) return $out;
    if (array_key_exists('disclaimer', $v) && array_key_exists('status', $v) && array_key_exists('performed_at', $v)) $out[] = $v;
    foreach ($v as $x) $out = array_merge($out, $checksIn($x));
    return $out;
};
$NEG = '/no_permit|has_permit|permit_exists|is_legal|illegal|violation|offen[cs]e|negative|none_found|is_clean|אין היתר|יש היתר|אין עבירה|אין תוכנית|(?<!\p{L})חוקי(?!\p{L})/u';

// ── הקטלוג: זריעה (09-C2), קריאה, עריכה (09-C1) ──
$cat = call('GET', 'sources', [], $tal);
$keys = array_column($cat[1]['sources'], 'key');
eq([$cat[0], count($keys), in_array('xplan', $keys, true), in_array('tabu_extract', $keys, true), in_array('other_municipality', $keys, true)], [200, 13, true, true, true], 'הקטלוג נזרע: 13 כרטיסים, וכל משתמש פעיל קורא אותו');
$allSrc = apr_rows('SELECT * FROM sources');
eq([count(array_filter($allSrc, fn($s) => $s['access_mode'] === 'connector' || $s['feasibility'] === 'approved')),
    count(array_filter($allSrc, fn($s) => array_unique(array_values(json_decode($s['license'], true))) !== ['unknown'] || count(json_decode($s['license'], true)) !== 6)),
    count(array_filter($cat[1]['sources'], fn($s) => $s['connected'] !== false || array_unique(array_values($s['license'])) !== ['unknown'])),
    count(array_filter($allSrc, fn($s) => trim((string)$s['research_ref']) === '' || !apr_is_pid($s['pid'])))], [0, 0, 0, 0],
    '09-C2 אחרי הזריעה: אין כרטיס connector או approved, כל שדות license הם unknown, אף מקור אינו "מחובר", ולכל כרטיס מקור במחקר');
$xp = array_values(array_filter($cat[1]['sources'], fn($s) => $s['key'] === 'xplan'))[0];
eq([str_contains($xp['coverage_note'], '2011'), $xp['card_stale'], $xp['access_mode'], $xp['store_note'] !== null], [true, true, 'guided', true], '09-D2 (נתונים) כרטיס XPLAN נושא את הערת הכיסוי של 2011; כרטיס שלא נבדק מעולם מסומן card_stale; license לא ידוע ← אזהרת שמירה');

$card = ['key' => 'xplan', 'name' => 'מינהל התכנון — XPLAN', 'owner' => 'מינהל התכנון', 'url' => 'https://ags.iplan.gov.il/xplan/', 'info_kinds' => ['planning'], 'region' => 'national',
    'access_mode' => 'guided', 'feasibility' => 'needs_inquiry', 'auth_required' => 'none', 'coverage_note' => 'אינו כולל תוכניות שהוגשו לפני 2011.', 'sort' => 30];
$before = $dumpAll();
eq([code(call('POST', 'sys-source-save', $card, $avi)), code(call('POST', 'sys-source-save', $card, $tal)), code(call('GET', 'sys-sources', [], $avi)), code(call('POST', 'sys-source-save', ['key' => 'ghost'] + $card, $avi)), $dumpAll() === $before],
    ['not_found', 'not_found', 'not_found', 'not_found', true], '09-C1 מי שאינו מנהל המערכת (גם מנהל משרד) אינו עורך ואינו רואה את ניהול הקטלוג — 404, והמסד לא השתנה');
eq([call('POST', 'sys-source-save', $card + ['mark_verified' => true, 'license' => ['store' => 'yes']], $owner)[0], apr_val("SELECT last_verified_at FROM sources WHERE key = 'xplan'") === apr_now(),
    json_decode(apr_val("SELECT license FROM sources WHERE key = 'xplan'"), true)['store'], count(call('GET', 'sys-sources', [], $owner)[1]['sources'])], [200, true, 'yes', 13], 'מנהל המערכת עורך כרטיס ומסמן "נבדק"');
$xpNow = fn() => array_values(array_filter(call('GET', 'sources', [], $tal)[1]['sources'], fn($s) => $s['key'] === 'xplan'))[0];
$fresh = $xpNow()['card_stale'];
$GLOBALS['apr_time'] = $T0 + 181 * 86400;
$stale = $xpNow()['card_stale'];
$GLOBALS['apr_time'] = $T0;
eq([$fresh, $stale], [false, true], 'כרטיס שנבדק היום אינו ישן; אחרי 181 יום (שעון קפוא) — "הכרטיס לא נבדק מזמן"');
eq([code(call('POST', 'sys-source-save', ['access_mode' => 'connector'] + $card, $owner)), code(call('POST', 'sys-source-save', ['feasibility' => 'approved'] + $card, $owner)),
    code(call('POST', 'sys-source-save', ['url' => 'javascript:alert(1)'] + $card, $owner)), code(call('POST', 'sys-source-save', ['terms_url' => 'https://user:secret@example.org/'] + $card, $owner)),
    code(call('POST', 'sys-source-save', ['info_kinds' => ['weather']] + $card, $owner)), code(call('POST', 'sys-source-save', ['key' => "xplan\n"] + $card, $owner)),
    code(call('POST', 'sys-source-save', ['create' => true] + $card, $owner)), apr_val("SELECT access_mode FROM sources WHERE key = 'xplan'")],
    ['source_connector_later', 'source_connector_later', 'url_invalid', 'url_invalid', 'source_invalid', 'source_invalid', 'source_exists', 'guided'],
    'גם מנהל המערכת אינו קובע connector / approved בפרוסה הזו; כתובת שאינה http(s) או שיש בה שם וסיסמה נדחית; מפתח עם ירידת שורה נדחה');
eq([$raises("DELETE FROM sources WHERE key = 'xplan'"), $raises("UPDATE sources SET key = 'x2' WHERE key = 'xplan'")], [true, true], 'מקור אינו נמחק ומפתחו אינו משתנה — גם ב-SQL ישיר');
call('POST', 'sys-source-save', $card, $owner);     // license חוזר ל-unknown

// ── פרופיל: מקטע "מקורות" (§8) ──
$SRC = ['items' => [['source' => 'xplan', 'mode' => 'auto', 'when' => null], ['source' => 'tax_authority_deals', 'mode' => 'manual', 'when' => null],
    ['source' => 'tabu_extract', 'mode' => 'ask', 'when' => null], ['source' => 'no_such_source', 'mode' => 'auto', 'when' => null]], 'spend_needs_approval' => true];
$P = make_profile($avi, $SRC);
$PB = make_profile($boaz);
$pv = call('GET', 'profile', [], $avi, ['profile' => $P])[1];
$chk = apr_profile_checks(apr_profile_content_read(json_encode($pv['content'])), apr_doc_type_keys());
eq([array_column($pv['content']['sources']['items'] ?? [], 'mode', 'source'), $pv['content']['sources']['spend_needs_approval'] ?? null,
    in_array('source_unknown', array_column($chk['warnings'] ?? [], 'code'), true), array_column($chk['errors'] ?? [], 'code')],
    [['xplan' => 'auto', 'tax_authority_deals' => 'manual', 'tabu_extract' => 'ask', 'no_such_source' => 'auto'], true, true, []],
    'מקטע "מקורות" נשמר בפרופיל; מקור שאינו בקטלוג — אזהרה בעורך, לא חסימה (מקרה קצה 10)');
$badSrc = function (array $sources) use ($avi): ?string {
    $pid = call('POST', 'profile-create', ['scope' => 'office', 'name' => 'פגום'], $avi)[1]['profile'];
    [, $d] = call('POST', 'profile-draft-open', ['profile' => $pid], $avi);
    return code(call('POST', 'profile-draft-save', ['profile' => $pid, 'content' => ['flags' => [], 'requirements' => ['groups' => [], 'items' => []], 'tasks' => ['items' => []], 'rules' => [], 'sources' => $sources], 'revision' => $d['draft']['revision']], $avi));
};
eq([$badSrc(['items' => [['source' => 'xplan', 'mode' => 'connect']]]), $badSrc(['items' => [['source' => 'xplan', 'mode' => 'auto'], ['source' => 'xplan', 'mode' => 'ask']]]),
    $badSrc(['items' => [['source' => "xplan\n", 'mode' => 'auto']]]), $badSrc(['items' => [], 'spend_needs_approval' => 'no'])],
    ['content_invalid', 'content_invalid', 'content_invalid', 'content_invalid'], 'מקטע "מקורות" פגום נדחה: mode לא מוכר, מקור כפול, מפתח עם ירידת שורה, דגל שאינו בוליאני');

// ── פתיחת תיק לפי הפרופיל (09-B1, 09-B2, 09-B3) ──
$newCase = function (array $ident = ['kind' => 'parcel', 'gush' => '6158', 'helka' => '12']) use ($avi, $P, $danaPid, $talPid, $ronPid, $noaPid, $yaelPid): string {
    $c = make_case($avi, $P, $ident);
    call('POST', 'case-member-add', ['case' => $c, 'user' => $danaPid], $avi);
    call('POST', 'case-member-add', ['case' => $c, 'user' => $talPid], $avi);
    call('POST', 'case-member-add', ['case' => $c, 'user' => $ronPid, 'role' => 'visitor'], $avi);
    call('POST', 'case-member-update', ['case' => $c, 'user' => $ronPid, 'perms' => ['edit' => false, 'documents' => false, 'review_docs' => false, 'tasks' => false, 'visit' => false, 'sources' => false]], $avi);
    call('POST', 'case-member-add', ['case' => $c, 'user' => $noaPid, 'role' => 'partner'], $avi);
    call('POST', 'case-member-update', ['case' => $c, 'user' => $noaPid, 'perms' => ['sources' => true]], $avi);
    call('POST', 'case-member-add', ['case' => $c, 'user' => $yaelPid, 'role' => 'visitor'], $avi);
    call('POST', 'case-member-update', ['case' => $c, 'user' => $yaelPid, 'perms' => ['sources' => true, 'edit' => true]], $avi);
    return $c;
};
$C = $newCase();
$CB = make_case($boaz, $PB);
$perm = fn(string $pid) => json_decode((string)apr_val('SELECT perms FROM case_members WHERE case_id = ? AND user_id = (SELECT id FROM users WHERE pid = ?)', [$caseId($C), $pid]), true);
eq([$perm($talPid)['sources'] ?? null, $perm($ronPid)['sources'] ?? null, $perm($noaPid)['sources'] ?? null, $perm($yaelPid)['sources'] ?? null], [true, false, true, true], 'הכנה: לטל, לנועה וליעל sources בתיק; לרון — צפייה בלבד');

$r = $cs($avi, $C);
$x = $srcOf($r, 'xplan');
$tx = $srcOf($r, 'tax_authority_deals');
$tb = $srcOf($r, 'tabu_extract');
eq([$r[0], $x['check_status'], $x['mode'], $x['access_mode'], $x['connected'], $x['latest']['performed_by_kind'], $x['latest']['performed_by'], array_column($tasksOf($C, 'source-check:xplan'), 'status'),
    $tasksOf($C, 'source-check:xplan')[0]['link_pid'] === $x['latest']['pid'], $count('source_checks', "status IN ('found', 'verified', 'partial', 'not_found')")],
    [200, 'planned', 'auto', 'guided', false, 'system', null, ['open'], true, 0],
    '09-B1 auto למקור guided ← נוצרת משימה, הבדיקה planned, המקור אינו מוחזר כמחובר — ואין שום "בדיקה שבוצעה"');
eq([$tx['check_status'], $tx['latest'], $tx['checks'], $tx['mode'], count($tasksOf($C, 'source-%:tax_authority_deals')), $count('source_checks', "source_id = (SELECT id FROM sources WHERE key = 'tax_authority_deals')")],
    ['not_checked', null, 0, 'manual', 0, 0], '09-B2 manual ← אין משימה ואין שורה; המקור מופיע כ-not_checked');
eq([$tb['check_status'], array_column($tasksOf($C, 'source-approve:tabu_extract'), 'status'), count($tasksOf($C, 'source-check:tabu_extract')), $count('source_checks', "source_id NOT IN (SELECT id FROM sources)")],
    ['needs_approval', ['open'], 0, 0], 'ask ← הבדיקה needs_approval ומשימת אישור לשמאי; מקור שאינו בקטלוג — מתעלמים ממנו (מקרה קצה 10)');

$C3 = $newCase(['kind' => 'other', 'value_text' => 'המגרש ליד בית הכנסת']);
$r3 = $cs($avi, $C3);
eq([$srcOf($r3, 'xplan')['check_status'], count($tasksOf($C3, 'source-check:xplan')), $r3[1]['identified'], $r3[1]['locality_hint'] !== null,
    array_values(array_unique(array_column(array_filter($r3[1]['sources'], fn($s) => $s['checks'] === 0), 'region')))],
    ['awaiting_identification', 0, false, true, ['national']], '09-B3 תיק בלי מזהה נכס מספיק + auto ← awaiting_identification, בלי משימה; בלי יישוב — רק המקורות הארציים, עם הרמז (מקרה קצה 1)');
$firstRow = apr_row("SELECT * FROM source_checks WHERE case_id = ? AND status = 'awaiting_identification'", [$caseId($C3)]);
call('POST', 'case-identifier-add', ['case' => $C3, 'kind' => 'address', 'locality' => 'חיפה', 'street' => 'הנמל', 'house_no' => '3'], $avi);
$r3 = $cs($avi, $C3);
eq([$srcOf($r3, 'xplan')['check_status'], $srcOf($r3, 'xplan')['checks'], array_column($tasksOf($C3, 'source-check:xplan'), 'status'), apr_row('SELECT * FROM source_checks WHERE id = ?', [$firstRow['id']]) === $firstRow,
    $srcOf($r3, 'other_municipality') !== null, $srcOf($r3, 'jerusalem_permits'), $srcOf($r3, 'tlv_permits'), $r3[1]['locality_hint']],
    ['planned', 2, ['open'], true, true, null, null, null], 'נוסף מזהה מספיק ← שורה חדשה planned ומשימה (השורה הקודמת לא השתנתה); יישוב בלי כרטיס ← הכרטיס הכללי (מקרה קצה 2)');
$CJ = $newCase(['kind' => 'address', 'locality' => 'ירושלים', 'street' => 'יפו', 'house_no' => '1']);
$CT = $newCase(['kind' => 'address', 'locality' => 'תל אביב', 'street' => 'הרצל', 'house_no' => '5']);
$rj = $cs($avi, $CJ);
$rt = $cs($avi, $CT);
eq([$srcOf($rj, 'jerusalem_permits') !== null, $srcOf($rj, 'tlv_permits'), $srcOf($rj, 'other_municipality'), $srcOf($rt, 'tlv_archive') !== null, $srcOf($rt, 'jerusalem_gis')], [true, null, null, true, null],
    'המקורות של הרשות לפי היישוב שבמזהים: ירושלים רואה את ירושלים, "תל אביב" רואה את "תל אביב-יפו", והכרטיס הכללי אינו מוצג כשיש כרטיס');

// ── רישום תוצאה: "לא אותר" אינו "אין" (09-A1 … 09-A3), היסטוריה (09-A7) ──
$ids = array_column($cs($avi, $C)[1]['identifiers'], 'pid');
$before = $dumpAll();
eq([code($rec($tal, $C, 'xplan', 'not_found', ['identifiers' => $ids])), code($rec($tal, $C, 'xplan', 'not_found', ['identifiers' => $ids, 'coverage_note' => "  \n "])),
    code($rec($tal, $C, 'xplan', 'partial', ['identifiers' => $ids])), code($rec($tal, $C, 'xplan', 'found', ['identifiers' => $ids])),
    code($rec($tal, $C, 'xplan', 'not_found', ['coverage_note' => 'חיפוש'])), code($rec($tal, $C, 'xplan', 'verified', ['identifiers' => $ids, 'result_summary' => 'x'])),
    code($rec($tal, $C, 'xplan', 'planned')), code($rec($tal, $C, 'xplan', 'none')), $dumpAll() === $before,
    $raises("INSERT INTO source_checks (pid, office_id, case_id, source_id, performed_by_kind, performed_at, status, created_at, updated_at) SELECT 'aaaaaaaaaaaaaaaa', office_id, case_id, source_id, 'system', 'x', 'not_found', 'x', 'x' FROM source_checks LIMIT 1")],
    ['check_coverage_required', 'check_coverage_required', 'check_coverage_required', 'check_summary_required', 'check_query_required', 'check_status', 'check_status', 'check_status', true, true],
    '09-A3 not_found בלי coverage_note נדחה (גם רווחים בלבד, וגם ב-SQL ישיר); partial — אותו דבר; found בלי סיכום, תוצאה בלי "מה חיפשו", ומצב שאינו תוצאה — נדחים');

$planned = apr_row("SELECT * FROM source_checks WHERE case_id = ? AND status = 'planned'", [$caseId($C)]);
$nf = $rec($tal, $C, 'xplan', 'not_found', ['identifiers' => $ids, 'coverage_note' => 'חיפוש לפי גוש 6158 חלקה 12 בשכבת התוכניות המקוונות', 'link_url' => 'https://ags.iplan.gov.il/xplan/?q=6158', 'op' => 'op-00000001']);
$nfAgain = $rec($tal, $C, 'xplan', 'not_found', ['identifiers' => $ids, 'coverage_note' => 'אחר', 'op' => 'op-00000001']);
eq([$nf[0], $nf[1]['check']['status'], $nf[1]['check']['disclaimer'], $nf[1]['check']['query']['identifiers'][0]['label'], $nf[1]['check']['ids_changed'], $nf[1]['check']['performed_by'],
    $nfAgain[1]['check']['pid'] === $nf[1]['check']['pid'], $nfAgain[1]['repeat'] ?? null, $count('source_checks', "status = 'not_found'"),
    apr_row('SELECT * FROM source_checks WHERE id = ?', [$planned['id']]) === $planned, array_column($tasksOf($C, 'source-check:xplan'), 'status'), count($tasksOf($C, 'source-retry:xplan'))],
    [200, 'not_found', APR_SRC_DISCLAIMERS['not_found'], 'גוש 6158 חלקה 12', false, 'טל', true, true, 1, true, ['done'], 0],
    '09-A7 רישום תוצאה הוא שורה חדשה — השורה הקודמת (planned) לא השתנתה; אותה פעולה פעמיים = בדיקה אחת; משימת הבדיקה נסגרת');
$nfRow = apr_row('SELECT * FROM source_checks WHERE pid = ?', [$nf[1]['check']['pid']]);
$un = $rec($tal, $C, 'xplan', 'unavailable', ['limitation' => 'האתר החזיר שגיאה']);
$retry = $tasksOf($C, 'source-retry:xplan');
eq([$un[0], $un[1]['check']['disclaimer'], $un[1]['task'] === ($retry[0]['pid'] ?? null), $retry[0]['status'] ?? null, $retry[0]['kind'] ?? null, $retry[0]['link_pid'] === $un[1]['check']['pid'],
    (int)$retry[0]['assignee_user_id'] === (int)apr_val('SELECT responsible_user_id FROM cases WHERE id = ?', [$caseId($C)]), preg_match($NEG, $retry[0]['title']),
    apr_row('SELECT * FROM source_checks WHERE id = ?', [$nfRow['id']]) === $nfRow, $srcOf($cs($avi, $C), 'xplan')['checks']],
    [200, APR_SRC_DISCLAIMERS['unavailable'], true, 'open', 'system', true, true, 0, true, 3],
    '09-A1 רישום unavailable ← משימת השלמה ידנית לאחראי, מקושרת לבדיקה; 09-A7 הבדיקה הקודמת (not_found) לא השתנתה');
foreach (['needs_login', 'failed'] as $st) $rec($tal, $C, 'xplan', $st);
eq([count($tasksOf($C, 'source-retry:xplan')), $count('source_checks', 'case_id = ' . $caseId($C) . " AND status IN ('needs_login', 'failed')")], [1, 2], 'needs_login ו-failed ← אותה משימת השלמה (אחת פתוחה לכל מקור), וכל בדיקה היא שורה');

// כל צורת תשובה שמחזירה בדיקה: רשימה, מקור, פריט, כרטיס התיק — ואף אחת אינה מסיקה "אין"
$rec($tal, $C, 'tax_authority_deals', 'not_found', ['query_text' => 'הרצל 5', 'coverage_note' => 'עסקאות ב-12 החודשים האחרונים ברחוב']);
$shapes = ['case-sources' => $cs($ron, $C), 'case-source' => call('GET', 'case-source', [], $ron, ['case' => $C, 'source' => 'xplan']),
    'source-check(not_found)' => call('GET', 'source-check', [], $ron, ['check' => $nf[1]['check']['pid']]), 'source-check(unavailable)' => call('GET', 'source-check', [], $ron, ['check' => $un[1]['check']['pid']]),
    'case' => call('GET', 'case', [], $ron, ['case' => $C]), 'case-planning' => call('GET', 'case-planning', [], $ron, ['case' => $C]), 'my-tasks' => call('GET', 'my-tasks', [], $avi),
    'cases' => call('GET', 'cases', [], $avi), 'case-activity' => call('GET', 'case-activity', [], $avi, ['case' => $C])];
$bad = [];
$seen = ['not_found' => 0, 'unavailable' => 0];
foreach ($shapes as $name => $res) {
    if ($res[0] !== 200) $bad[] = "$name: {$res[0]}";
    foreach ($checksIn($res[1]) as $k) {
        if (isset(APR_SRC_DISCLAIMERS[$k['status']])) { $seen[$k['status']]++; if ($k['disclaimer'] !== APR_SRC_DISCLAIMERS[$k['status']]) $bad[] = "$name: {$k['status']} בלי הסתייגות"; }
        elseif ($k['disclaimer'] !== null) $bad[] = "$name: הסתייגות על {$k['status']}";
    }
    foreach ($flat($res[1]) as $s) {
        // נוסחי ההסתייגות עצמם אומרים "אין בכך הוכחה שאין…" — הם ההפך מהסקה, ולכן מוסרים לפני החיפוש
        $t = str_replace([...array_values(APR_SRC_DISCLAIMERS), APR_PLAN_EMPTY, APR_PLAN_ZERO], '', $s);
        if (preg_match($NEG, $t)) $bad[] = "$name: $s";
        if (preg_match('/^K:/', $s) && is_bool(($res[1][substr($s, 2)] ?? null)) && preg_match('/permit|found|exists/', $s)) $bad[] = "$name: בוליאני $s";
    }
}
$caseView = $shapes['case'][1]['sources'] ?? [];
eq([$bad, $seen['not_found'] >= 3, $seen['unavailable'] >= 1, array_column($caseView['attention'] ?? [], 'status'), $caseView['checked'] ?? null,
    count(array_filter($shapes['case-source'][1]['checks'], fn($k) => $k['status'] === 'unavailable')), $shapes['case-source'][1]['source']['check_status']],
    [[], true, true, ['needs_approval', 'failed', 'not_found'], 2, 1, 'failed'],
    '09-A1 / 09-A2 כל תשובה שמחזירה בדיקה not_found / unavailable (רשימה, מקור, פריט, כרטיס התיק) נושאת את נוסח ההסתייגות — ואין בשום תשובה של התיק שדה או ניסוח שמסיק "אין היתר"');
// סכימה: אין מיפוי למצב בוליאני
$cols = [];
foreach (['sources', 'source_checks', 'planning_items'] as $t) foreach (apr_rows("PRAGMA table_info($t)") as $col) $cols["$t.{$col['name']}"] = strtoupper($col['type']);
eq([array_values(array_filter(array_keys($cols), fn($c) => preg_match('/violation|illegal|legal|permit|offen[cs]e|avera|lawful|clean|negative|exists|has_|is_|no_/', explode('.', $c)[1]))),
    array_values(array_filter($cols, fn($t) => !in_array($t, ['INTEGER', 'TEXT'], true))),
    array_values(array_filter(array_keys($cols), fn($c) => preg_match('/pass|secret|otp|token|credential|pin|cvv|card|login_code|sms/', explode('.', $c)[1]) && $c !== 'source_checks.idempotency_key'))],
    [[], [], []], '09-A6 בסכימה אין עמודה של "עבירה" / "חוקי" / "אין היתר" ואין עמודה בוליאנית בשם כזה; 09-C5 ואין עמודה לסיסמה או לקוד');

// ── היסטוריה: שורה אינה נערכת ואינה נמחקת (09-A7, SQL ישיר) ──
$kid = (int)$nfRow['id'];
eq([$raises("DELETE FROM source_checks WHERE id = $kid"), $raises("UPDATE source_checks SET status = 'found', result_summary = 'x' WHERE id = $kid"), $raises("UPDATE source_checks SET coverage_note = 'אחר' WHERE id = $kid"),
    $raises("UPDATE source_checks SET result_summary = 'x' WHERE id = $kid"), $raises("UPDATE source_checks SET link_url = NULL WHERE id = $kid"), $raises("UPDATE source_checks SET query = '{}' WHERE id = $kid"),
    $raises("UPDATE source_checks SET performed_by = 1 WHERE id = $kid"), $raises("UPDATE source_checks SET case_id = " . $caseId($CB) . " WHERE id = $kid"),
    $raises("UPDATE source_checks SET status = 'verified', verified_by = 1, verified_at = 'x' WHERE id = $kid"), $raises("UPDATE source_checks SET status = 'not_checked' WHERE id = " . (int)$planned['id']),
    apr_row('SELECT * FROM source_checks WHERE id = ?', [$kid]) === $nfRow],
    [true, true, true, true, true, true, true, true, true, true, true], '09-A7 בדיקה שנרשמה אינה נמחקת ואינה נערכת — גם ב-SQL ישיר; not_found אינו הופך ל-verified');

// ── אישור, אימות והוצאה (09-C4) ──
$nb = $srcOf($cs($avi, $C), 'tabu_extract')['latest']['pid'];
$before = $dumpAll();
eq([code($rec($tal, $C, 'tabu_extract', 'found', ['identifiers' => $ids, 'result_summary' => 'נסח הופק'])), code(call('POST', 'source-check-approve', ['check' => $nb], $tal)),
    code(call('POST', 'source-check-approve', ['check' => $nb], $noa)), code(call('POST', 'source-check-approve', ['check' => $nb], $yael)), code(call('POST', 'source-check-approve', ['check' => $nb], $ron)),
    code(call('POST', 'source-check-approve', ['check' => $nf[1]['check']['pid']], $avi)), $dumpAll() === $before],
    ['check_needs_approval', 'forbidden', 'forbidden', 'forbidden', 'forbidden', 'check_state', true],
    'בדיקה שממתינה לאישור: מי שאינו שמאי בתיק אינו רושם תוצאה ואינו מאשר (עובדת משרד, מתמחה שותפה, שמאית שאינה אחראית/שותפה, צופה); אישור של בדיקה שאינה ממתינה — נדחה');
$ap = call('POST', 'source-check-approve', ['check' => $nb], $dana);
eq([$ap[0], $ap[1]['check']['status'], $ap[1]['check']['approved_by'], $ap[1]['check']['pid'] !== $nb, apr_val('SELECT status FROM source_checks WHERE pid = ?', [$nb]),
    array_column($tasksOf($C, 'source-approve:tabu_extract'), 'status'), array_column($tasksOf($C, 'source-check:tabu_extract'), 'status'), code(call('POST', 'source-check-approve', ['check' => $nb], $dana))],
    [200, 'planned', 'דנה', true, 'needs_approval', ['done'], ['open'], 'check_state'], 'שמאית שותפה מאשרת: שורה חדשה planned עם מי אישר; השורה הממתינה לא השתנתה; משימת האישור נסגרת ונפתחת משימת בדיקה');
$F = up($avi, $C, 'nesach');
$fd = $rec($tal, $C, 'tabu_extract', 'found', ['identifiers' => $ids, 'result_summary' => 'נסח מיום הבדיקה — בעלות רשומה', 'file' => $F, 'cost_amount' => 1600, 'cost_note' => 'אגרת נסח']);
$fdPid = $fd[1]['check']['pid'] ?? '';
eq([$fd[0], $fd[1]['check']['file']['pid'] ?? null, $fd[1]['check']['cost_amount'] ?? null, $fd[1]['check']['no_evidence'] ?? null, $fd[1]['check']['disclaimer'], array_column($tasksOf($C, 'source-check:tabu_extract'), 'status')],
    [200, $F, 1600, false, null, ['done']], 'אחרי האישור: עובדת המשרד רושמת "אותר" עם קובץ ועם הוצאה; משימת הבדיקה נסגרת');
$before = $dumpAll();
eq([code(call('POST', 'source-check-verify', ['check' => $fdPid], $tal)), code(call('POST', 'source-check-verify', ['check' => $fdPid], $noa)), code(call('POST', 'source-check-verify', ['check' => $fdPid], $yael)),
    code(call('POST', 'source-check-verify', ['check' => $fdPid], $ron)), code(call('POST', 'source-check-verify', ['check' => $fdPid], $gil)), code(call('POST', 'source-check-verify', ['check' => $fdPid], $boaz)),
    code(call('POST', 'source-check-verify', ['check' => $nf[1]['check']['pid']], $avi)), code(call('POST', 'source-check-verify', ['check' => $un[1]['check']['pid']], $avi)), $dumpAll() === $before],
    ['forbidden', 'forbidden', 'forbidden', 'forbidden', 'not_found', 'not_found', 'check_state', 'check_state', true],
    '09-C4 verified בידי עובדת משרד, מתמחה שותפה, או שמאית שאינה אחראית/שותפה ← 403; מי שאינו בתיק ← 404; "לא אותר" ו"לא זמין" אינם ניתנים לאימות — והמסד לא השתנה');
$vf = call('POST', 'source-check-verify', ['check' => $fdPid], $dana);
eq([$vf[0], $vf[1]['check']['status'], $vf[1]['check']['verified_by'], $vf[1]['check']['verified_at'], code(call('POST', 'source-check-verify', ['check' => $fdPid], $avi)),
    $raises("UPDATE source_checks SET status = 'found', verified_by = NULL, verified_at = NULL WHERE pid = ?", [$fdPid]), $raises("UPDATE source_checks SET cost_amount = 1 WHERE pid = ?", [$fdPid])],
    [200, 'verified', 'דנה', apr_now(), 'check_state', true, true], 'שמאית שותפה מאמתת "אותר": נרשם מי ומתי; אימות אינו מתבטל והוצאה שנרשמה אינה נכתבת מחדש — גם ב-SQL ישיר');
// הלקח מפרוסה 10: מי שאינו שמאי אינו מבטל ואינו משנה קביעה של שמאי — גם בעקיפין
$after = $rec($tal, $C, 'tabu_extract', 'needs_clarification', ['identifiers' => $ids, 'result_summary' => 'הנסח אינו קריא']);
$tbNow = $srcOf($cs($ron, $C), 'tabu_extract');
eq([$after[0], apr_val('SELECT status FROM source_checks WHERE pid = ?', [$fdPid]), $tbNow['check_status'], $tbNow['verified']['pid'] ?? null, $tbNow['verified']['verified_by'] ?? null],
    [200, 'verified', 'needs_clarification', $fdPid, 'דנה'], 'בדיקה חדשה של עובדת משרד אחרי בדיקה מאומתת: המאומתת נשארת מאומתת, ומוחזרת לצד האחרונה — לא נעלמת');
$c2 = $rec($tal, $C, 'cbs_indices', 'found', ['query_text' => 'מדד תשומות הבנייה', 'result_summary' => 'המדד לחודש האחרון']);
$c2Pid = $c2[1]['check']['pid'];
eq([$c2[1]['check']['no_evidence'], code(call('POST', 'source-check-cost', ['check' => $c2Pid, 'cost_amount' => 500], $tal)), code(call('POST', 'source-check-cost', ['check' => $c2Pid, 'cost_amount' => -1], $tal)),
    code(call('POST', 'source-check-cost', ['check' => $c2Pid, 'cost_amount' => '500'], $tal)), code(call('POST', 'source-check-cost', ['check' => $c2Pid], $tal)),
    code($rec($tal, $C, 'cbs_indices', 'found', ['query_text' => 'מדד', 'result_summary' => 'x', 'cost_amount' => 500])),
    code(call('POST', 'source-check-cost', ['check' => $fdPid, 'cost_amount' => 0], $tal)), code(call('POST', 'source-check-cost', ['check' => $planned['pid'], 'cost_amount' => 0], $avi))],
    [true, 'check_spend_approval', 'cost_invalid', 'cost_invalid', 'cost_invalid', 'check_spend_approval', 'forbidden', 'check_state'],
    '"אותר" בלי קישור ובלי קובץ מותר ומסומן "בלי אסמכתא" (מקרה קצה 7); הוצאה בלי אישור של שמאי נדחית (גם בעת הרישום); סכום שלילי או מחרוזת — נדחה; בדיקה מאומתת — רק שמאי');
$z = call('POST', 'source-check-cost', ['check' => $c2Pid, 'cost_amount' => 0], $tal);
eq([$z[0], $z[1]['check']['cost_amount'], apr_val('SELECT cost_amount FROM source_checks WHERE pid = ?', [$nf[1]['check']['pid']]), code(call('POST', 'source-check-cost', ['check' => $c2Pid, 'cost_amount' => 100], $avi))],
    [200, 0, null, 'check_state'], 'מקרה קצה 9: הוצאה של 0 = "ללא עלות", NULL = "לא נרשם"; הוצאה נרשמת פעם אחת');
$pl = call('POST', 'source-check-plan', ['case' => $C, 'source' => 'rmi_rights', 'spend' => true], $tal);
$pl2 = call('POST', 'source-check-plan', ['case' => $C, 'source' => 'rmi_rights', 'spend' => true], $tal);
$pl3 = call('POST', 'source-check-plan', ['case' => $C, 'source' => 'govmap'], $tal);
eq([$pl[1]['check']['status'], $pl2[1]['check']['pid'] === $pl[1]['check']['pid'], array_column($tasksOf($C, 'source-approve:rmi_rights'), 'status'), $pl3[1]['check']['status'], $pl3[1]['check']['performed_by'],
    call('POST', 'source-check-plan', ['case' => $C, 'source' => 'rmi_rights'], $avi)[1]['check']['status'], code(call('POST', 'source-check-plan', ['case' => $C, 'source' => 'govmap'], $ron))],
    ['needs_approval', true, ['open'], 'in_progress', 'טל', 'in_progress', 'forbidden'], '"אני מתחיל לבדוק": הוצאה צפויה ← needs_approval ומשימת אישור (פעם אחת); בלי הוצאה ← in_progress; שמאי בתיק אינו ממתין לאישור של עצמו; צופה — 403');

// ── license.store = no (09-B4), קישורים ──
call('POST', 'sys-source-save', ['key' => 'govmap', 'name' => 'GovMap', 'owner' => 'מפ"י', 'url' => 'https://www.govmap.gov.il/', 'info_kinds' => ['map'], 'access_mode' => 'guided', 'feasibility' => 'needs_inquiry', 'license' => ['store' => 'no']], $owner);
$before = $dumpAll();
$noFile = $rec($tal, $C, 'govmap', 'found', ['identifiers' => $ids, 'result_summary' => 'אותר במפה', 'file' => $F]);
$same = $dumpAll() === $before;
$withLink = $rec($tal, $C, 'govmap', 'found', ['identifiers' => $ids, 'result_summary' => 'אותר במפה', 'link_url' => 'https://www.govmap.gov.il/?c=1,2&z=9']);
eq([code($noFile), $same, $withLink[0], $withLink[1]['check']['link_url'], $withLink[1]['check']['file'], $srcOf($cs($tal, $C), 'govmap')['store_note'] === APR_SRC_STORE_NOTES['no']],
    ['source_store_forbidden', true, 200, 'https://www.govmap.gov.il/?c=1,2&z=9', null, true], '09-B4 מקור עם license.store = no ← צירוף קובץ נדחה בשרת (שום דבר לא נכתב); קישור מתקבל');
$urlCode = fn(string $u) => code($rec($tal, $C, 'cbs_indices', 'found', ['query_text' => 'מדד', 'result_summary' => 'x', 'link_url' => $u]));
$before = $dumpAll();
eq([$urlCode('javascript:alert(1)'), $urlCode('JaVaScRiPt:alert(1)'), $urlCode('data:text/html,<script>1</script>'), $urlCode('//evil.example/x'), $urlCode('/relative'), $urlCode("https://example.org/\njavascript:1"),
    $urlCode('https://example.org/"onmouseover="x'), $urlCode('https://user:pass@example.org/'), $urlCode('ftp://example.org/x'), $urlCode('https://'), $urlCode(' javascript:alert(1)'),
    $urlCode('vbscript:x'), $urlCode('https://example.org/' . str_repeat('a', 500)), code($rec($tal, $C, 'cbs_indices', 'found', ['query_text' => 'מדד', 'result_summary' => 'x', 'link_url' => ['https://a.example/']])), $dumpAll() === $before],
    [...array_fill(0, 14, 'url_invalid'), true], 'קישור מאומת בשרת: http / https בלבד — javascript:, data:, יחסי, עם ירידת שורה, עם מירכאות, עם שם וסיסמה, ארוך מדי — נדחים, ושום דבר לא נכתב');
eq([code($rec($tal, $C, 'cbs_indices', 'found', ['query_text' => 'מדד', 'result_summary' => 'x', 'file' => up($boaz, $CB, 'other')])), code($rec($tal, $C, 'cbs_indices', 'found', ['identifiers' => [array_column($cs($boaz, $CB)[1]['identifiers'], 'pid')[0]], 'result_summary' => 'x'])),
    code($rec($tal, $C, 'cbs_indices', 'found', ['query_text' => 'מדד', 'result_summary' => 'x', 'file' => 'aaaaaaaaaaaaaaaa'])), code($rec($tal, $C, 'no_such_source', 'found', ['query_text' => 'x', 'result_summary' => 'x'])),
    code($rec($tal, $C, "xplan\n", 'found', ['query_text' => 'x', 'result_summary' => 'x']))],
    ['not_found', 'not_found', 'not_found', 'not_found', 'not_found'], 'מזהי בן מתיק של משרד אחר (קובץ, מזהה נכס) — כמו מזהה שלא קיים: 404; מקור שלא קיים — 404');

// ── תכנון והיתרים: בקשה אינה היתר (09-A4 … 09-A6) ──
$padd = fn(string $as, string $case, string $kind, string $title, array $more = []) => call('POST', 'planning-add', $more + ['case' => $case, 'kind' => $kind, 'title' => $title], $as);
$empty = call('GET', 'case-planning', [], $ron, ['case' => $C]);
eq([$empty[0], $empty[1]['items'], $empty[1]['summary']['disclaimer'], $empty[1]['summary']['total'], count(array_filter($empty[1]['summary']['kinds'], fn($k) => $k['caveat'] === APR_PLAN_ZERO))],
    [200, [], APR_PLAN_EMPTY, 0, 6], '09-D3 (נתונים) רשימה ריקה נושאת את נוסח ההסתייגות, וכל סוג בלי פריט — "0 נרשמו" עם הסתייגות');
$a1 = $padd($tal, $C, 'application', 'בקשה להיתר לתוספת בנייה', ['ref_number' => '2019/0456', 'status_reported' => 'בבדיקת תנאים', 'item_date' => '2019-03-01', 'check' => $withLink[1]['check']['pid'], 'url' => 'https://www.jerusalem.muni.il/x']);
$a2 = $padd($tal, $C, 'application', 'בקשה להיתר — שימוש חורג', ['ref_number' => '2021/0012']);
$a1Pid = $a1[1]['item']['pid'];
$lst = call('GET', 'case-planning', [], $ron, ['case' => $C]);
$permitLine = array_values(array_filter($lst[1]['summary']['kinds'], fn($k) => $k['kind'] === 'permit'))[0];
$appLine = array_values(array_filter($lst[1]['summary']['kinds'], fn($k) => $k['kind'] === 'application'))[0];
$leak = array_values(array_filter($flat($lst[1]), fn($s) => preg_match($NEG, str_replace([APR_PLAN_ZERO, APR_PLAN_EMPTY], '', $s))));
eq([$a1[0], $a1[1]['item']['status_reported'], $a1[1]['item']['source'], $a1[1]['item']['applicability'], array_values(array_unique(array_column($lst[1]['items'], 'kind'))),
    count(array_filter($lst[1]['items'], fn($i) => $i['kind'] === 'permit')), $permitLine['count'], $permitLine['text'], $permitLine['caveat'], $appLine['count'], $appLine['caveat'], $lst[1]['summary']['disclaimer'], $leak],
    [200, 'בבדיקת תנאים', 'govmap', 'unknown', ['application'], 0, 0, 'היתרים שהונפקו: 0 נרשמו', APR_PLAN_ZERO, 2, null, null, []],
    '09-A5 רשימה שיש בה רק בקשות: אין בתשובה אף permit, "היתרים שהונפקו: 0 נרשמו" מופיע עם ההסתייגות — ואין ניסוח של "יש היתר" / "אין היתר"; הסטטוס נשמר בלשון המקור');
$before = $dumpAll();
eq([code(call('POST', 'planning-update', ['item' => $a1Pid, 'kind' => 'permit'], $tal)), code(call('POST', 'planning-update', ['item' => $a1Pid, 'kind' => 'permit', 'title' => 'היתר'], $avi)),
    code(call('POST', 'planning-update', ['item' => $a1Pid, 'kind' => 'decision'], $dana)), $raises("UPDATE planning_items SET kind = 'permit' WHERE pid = ?", [$a1Pid]),
    $raises("UPDATE planning_items SET kind = 'permit', title = 'x' WHERE kind = 'application'"), $raises('DELETE FROM planning_items WHERE pid = ?', [$a1Pid]), $dumpAll() === $before,
    call('POST', 'planning-update', ['item' => $a1Pid, 'kind' => 'application', 'note' => 'אותו סוג'], $tal)[0]],
    ['planning_kind_fixed', 'planning_kind_fixed', 'planning_kind_fixed', true, true, true, true, 200],
    '09-A4 אין פעולה שהופכת application ל-permit: עדכון עם kind אחר נדחה (גם לשמאי האחראי), וטריגר חוסם גם SQL ישיר; פריט אינו נמחק מהמסד');
$routes = array_keys(APR_ACCESS);
eq([array_values(array_filter($routes, fn($a) => preg_match('/planning|permit/', $a))), array_values(array_filter($routes, fn($a) => preg_match('/convert|promote|issue|to-permit/', $a)))],
    [['case-planning', 'planning-item', 'planning-add', 'planning-update', 'planning-applicability', 'planning-delete', 'planning-task'], []], '09-A4 רשימת הפעולות על פריטי תכנון סגורה — ואין בה פעולת המרה');
$pm = $padd($tal, $C, 'permit', 'היתר בנייה 2020/118', ['ref_number' => '2020/118', 'related' => $a1Pid, 'file' => $F]);
$dupP = $padd($tal, $C, 'permit', 'היתר בנייה — רישום שני', ['ref_number' => '2020/118']);
eq([$pm[0], $pm[1]['item']['related']['kind'] ?? null, $pm[1]['duplicate'], $dupP[0], $dupP[1]['duplicate'] === $pm[1]['item']['pid'], apr_val('SELECT kind FROM planning_items WHERE pid = ?', [$a1Pid]), $pm[1]['item']['file']['pid'] ?? null],
    [200, 'application', null, 200, true, 'application', $F], 'היתר הוא פריט חדש שמקושר לבקשה — הבקשה נשארת בקשה; רישום כפול של אותו היתר אינו נחסם ומחזיר התרעה (מקרה קצה 8)');
eq([code($padd($tal, $C, 'permit', 'x', ['check' => apr_val('SELECT pid FROM source_checks WHERE case_id = ?', [$caseId($C3)])])), code($padd($tal, $C, 'permit', 'x', ['related' => 'aaaaaaaaaaaaaaaa'])), code($padd($tal, $C, 'permit', 'x', ['related' => $padd($avi, $CJ, 'application', 'בקשה בתיק אחר')[1]['item']['pid']])),
    code($padd($tal, $C, 'violation', 'x')), code($padd($tal, $C, 'permit', '')), code($padd($tal, $C, 'permit', 'x', ['url' => 'javascript:alert(1)'])), code($padd($tal, $C, 'permit', 'x', ['item_date' => '2020-02-30'])),
    code($padd($tal, $C, 'building_doc', 'x', ['source' => 'govmap', 'file' => $F])), $padd($tal, $C, 'building_doc', 'תשריט מהמפה', ['source' => 'govmap', 'url' => 'https://www.govmap.gov.il/'])[0],
    code($padd($ron, $C, 'plan', 'x')), code($padd($gil, $C, 'plan', 'x')), code($padd($boaz, $C, 'plan', 'x'))],
    ['not_found', 'not_found', 'not_found', 'planning_kind', 'name_required', 'url_invalid', 'date_invalid', 'source_store_forbidden', 200, 'forbidden', 'not_found', 'not_found'],
    'פריט תכנון: בדיקה מתיק אחר / פריט שלא קיים ← 404; סוג לא מוכר, כותרת ריקה, קישור javascript:, תאריך לא קיים ← נדחים; קובץ ממקור שאסור לשמור ממנו ← נדחה, קישור מתקבל; צופה 403, מי שאינו בתיק 404');

// תחולה — רק שמאי בתיק (09-C4), ומי שאינו שמאי אינו משנה קביעה של שמאי
$apl = fn(string $as, string $item, mixed $to, mixed $note = 'התוכנית חלה על החלקה') => call('POST', 'planning-applicability', ['item' => $item, 'applicability' => $to, 'note' => $note], $as);
$before = $dumpAll();
eq([code($apl($tal, $a1Pid, 'applies')), code($apl($noa, $a1Pid, 'applies')), code($apl($yael, $a1Pid, 'applies')), code($apl($ron, $a1Pid, 'applies')), code($apl($gil, $a1Pid, 'applies')), code($apl($boaz, $a1Pid, 'applies')),
    code(call('POST', 'planning-update', ['item' => $a1Pid, 'applicability' => 'applies', 'applicability_note' => 'x'], $tal)), code($padd($tal, $C, 'plan', 'x', ['applicability' => 'applies'])),
    code($apl($avi, $a1Pid, 'applies', '')), code($apl($avi, $a1Pid, 'maybe')), $raises("UPDATE planning_items SET applicability = 'applies' WHERE pid = ?", [$a1Pid])],
    ['forbidden', 'forbidden', 'forbidden', 'forbidden', 'not_found', 'not_found', 'forbidden', null, 'reason_required', 'planning_applicability', true],
    '09-C4 קביעת תחולה בידי עובדת משרד, מתמחה שותפה, או שמאית שאינה אחראית/שותפה ← 403 (גם דרך planning-update); בלי הערה או עם ערך לא מוכר — נדחה; SQL ישיר בלי מי ומתי — נחסם');
eq([apr_val("SELECT applicability FROM planning_items WHERE title = 'x' AND kind = 'plan' AND case_id = ?", [$caseId($C)]), $dumpAll() !== $before], ['unknown', true], 'פריט חדש תמיד מתחיל ב-unknown — שדה תחולה בבקשת ההוספה אינו נקרא');
$ok = $apl($dana, $a1Pid, 'partial', 'חלה על חלק מהחלקה');
$before = $dumpAll();
eq([$ok[0], $ok[1]['item']['applicability'], $ok[1]['item']['applicability_by'], $ok[1]['item']['applicability_note'],
    code(call('POST', 'planning-update', ['item' => $a1Pid, 'title' => 'שונה'], $tal)), code(call('POST', 'planning-update', ['item' => $a1Pid, 'status_reported' => 'אושר'], $noa)),
    code(call('POST', 'planning-delete', ['item' => $a1Pid, 'reason' => 'טעות'], $tal)), code(call('POST', 'planning-delete', ['item' => $a1Pid, 'reason' => 'טעות'], $yael)), $dumpAll() === $before,
    call('GET', 'planning-item', [], $tal, ['item' => $a1Pid])[1]['perms']['edit_item'], call('GET', 'planning-item', [], $avi, ['item' => $a1Pid])[1]['perms']['edit_item'],
    call('POST', 'planning-update', ['item' => $a1Pid, 'status_reported' => 'אושר בתנאים'], $avi)[1]['item']['applicability']],
    [200, 'partial', 'דנה', 'חלה על חלק מהחלקה', 'forbidden', 'forbidden', 'forbidden', 'forbidden', true, false, true, 'partial'],
    'פריט שתחולתו נקבעה בידי שמאית: מי שאינו שמאי אינו עורך ואינו מוחק אותו (הקביעה אינה מתבטלת בעקיפין); שמאי בתיק עורך, והתחולה נשארת');
$tk = call('POST', 'planning-task', ['item' => $a2[1]['item']['pid'], 'detail' => 'התוספת בפועל גדולה מהבקשה'], $tal);
$tk2 = call('POST', 'planning-task', ['item' => $a2[1]['item']['pid']], $tal);
$trow = apr_row('SELECT * FROM tasks WHERE pid = ?', [$tk[1]['task']]);
eq([$tk[0], $tk2[1]['existing'], $tk2[1]['task'] === $tk[1]['task'], $trow['kind'], $trow['link_type'], str_starts_with($trow['title'], 'אי התאמה אפשרית'), preg_match($NEG, $trow['title']), code(call('POST', 'planning-task', ['item' => $a2[1]['item']['pid']], $ron))],
    [200, true, true, 'clarification', 'planning_item', true, 0, 'forbidden'], 'משימת "אי התאמה אפשרית" — אחת פתוחה לפריט, בלי ניסוח של עבירה; צופה — 403');
$del = call('POST', 'planning-delete', ['item' => $a2[1]['item']['pid'], 'reason' => 'נרשם בטעות'], $tal);
eq([$del[0], code(call('POST', 'planning-delete', ['item' => $a2[1]['item']['pid'], 'reason' => 'שוב'], $tal)), code(call('GET', 'planning-item', [], $avi, ['item' => $a2[1]['item']['pid']])), apr_val('SELECT status FROM tasks WHERE pid = ?', [$tk[1]['task']]),
    $count('planning_items', 'pid = ? AND deleted_at IS NOT NULL', [$a2[1]['item']['pid']]), code(call('POST', 'planning-delete', ['item' => $pm[1]['item']['pid'], 'reason' => ''], $tal))],
    [200, 'not_found', 'not_found', 'cancelled', 1, 'reason_required'], 'מחיקה רכה עם נימוק: השורה נשארת, הפריט "לא נמצא", והמשימה שלו מתבטלת; בלי נימוק — נדחה');

// ── נתון מבדיקת מקור: רק דרך apr_fact_insert, והמקור נפתח מהנתון ──
$fadd = fn(string $as, string $case, mixed $ref) => call('POST', 'fact-add', ['case' => $case, 'attribute' => 'rooms', 'value' => 4, 'source' => ['kind' => 'source_check', 'ref' => $ref]], $as);
$auditBefore = $count('audit_log', "action = 'fact.add'");
$ff = $fadd($avi, $C, $fdPid);
$kv = call('GET', 'source-check', [], $ron, ['check' => $fdPid]);
eq([$ff[0], $ff[1]['fact']['source']['kind'] ?? null, $ff[1]['fact']['source']['check'] ?? null, $ff[1]['fact']['source']['check_source'] ?? null, $ff[1]['fact']['source']['check_status'] ?? null,
    apr_val('SELECT source_ref_pid FROM facts WHERE pid = ?', [$ff[1]['fact']['pid'] ?? '']), $count('audit_log', "action = 'fact.add'") - $auditBefore, array_column($kv[1]['facts'] ?? [], 'pid'),
    code($fadd($avi, $C, $nf[1]['check']['pid'])), code($fadd($avi, $C, $un[1]['check']['pid'])), code($fadd($avi, $C, $planned['pid'])), code($fadd($avi, $C, 'aaaaaaaaaaaaaaaa')),
    code($fadd($avi, $C, apr_val('SELECT pid FROM source_checks WHERE case_id = ?', [$caseId($C3)]))), code($fadd($avi, $C, ['x']))],
    [200, 'source_check', $fdPid, 'רישום מקרקעין — נסח טאבו', 'verified', $fdPid, 1, [$ff[1]['fact']['pid'] ?? ''], 'fact_source', 'fact_source', 'fact_source', 'fact_source', 'fact_source', 'fact_source'],
    'נתון מבדיקת מקור נכנס דרך fact-add (apr_fact_insert, עם יומן) עם source_ref_pid, והמקור נפתח מהנתון; בדיקה בלי ממצא ("לא אותר", "לא זמין", מתוכננת), של תיק אחר או שלא קיימת — אינה מקור לנתון');
$libs = implode("\n", array_map(fn($f) => basename($f) === 'facts.php' ? '' : (string)file_get_contents($f), glob(__DIR__ . '/../lib/*.php')));
eq([preg_match('/INSERT\s+INTO\s+facts\b|apr_insert\(\s*\'facts\'/i', $libs), preg_match_all('/apr_insert\(\'source_checks\'/', $libs), preg_match_all('/INSERT\s+INTO\s+source_checks/i', $libs)], [0, 1, 0],
    'אין INSERT ישיר ל-facts מחוץ ל-lib/facts.php, ושורה ב-source_checks נכתבת ממקום אחד (apr_src_insert)');

// ── מזהים שהשתנו אחרי בדיקה (מקרה קצה 4), נכס על שתי חלקות (מקרה קצה 3), מקור מוסתר (מקרה קצה 5), אותו מסמך לשתי בדיקות (מקרה קצה 6) ──
$id2 = call('POST', 'case-identifier-add', ['case' => $C, 'kind' => 'parcel', 'gush' => '6158', 'helka' => '13'], $avi)[1]['identifier'];
$both = $rec($tal, $C, 'planning_info', 'partial', ['identifiers' => [$ids[0]], 'coverage_note' => 'נבדקה חלקה 12 בלבד; חלקה 13 לא נבדקה', 'file' => $F]);
$two = $rec($tal, $C, 'planning_info', 'found', ['identifiers' => [$ids[0], $id2], 'result_summary' => 'שתי החלקות נבדקו', 'file' => $F]);
call('POST', 'case-identifier-remove', ['case' => $C, 'identifier' => $id2, 'reason' => 'הוזן בטעות'], $avi);
$hist = call('GET', 'case-source', [], $avi, ['case' => $C, 'source' => 'planning_info'])[1]['checks'];
eq([$both[1]['check']['status'], count($two[1]['check']['query']['identifiers']), array_column($hist, 'ids_changed'), count($hist[0]['query']['identifiers']), $hist[0]['file']['pid'] ?? null, $hist[1]['file']['pid'] ?? null],
    ['partial', 2, [true, false], 2, $F, $F], 'בדיקה שכיסתה חלקה אחת = partial; מזהה שהוסר אחרי הבדיקה — הבדיקה נשארת עם ה-query המקורי ומסומנת ids_changed; אותו מסמך לשתי בדיקות — מותר');
call('POST', 'sys-source-save', ['key' => 'planning_info', 'name' => 'מינהל התכנון — מידע תכנוני', 'owner' => 'מינהל התכנון', 'url' => 'https://mavat.iplan.gov.il/', 'info_kinds' => ['planning'], 'access_mode' => 'guided', 'feasibility' => 'needs_inquiry', 'status' => 'hidden'], $owner);
call('POST', 'sys-source-save', ['key' => 'cbs_indices', 'name' => 'הלמ"ס', 'owner' => 'הלמ"ס', 'info_kinds' => ['stats'], 'status' => 'hidden'], $owner);
call('POST', 'sys-source-save', ['key' => 'housing_company', 'name' => 'חברה משכנת', 'owner' => 'x', 'info_kinds' => ['rights'], 'status' => 'hidden'], $owner);
eq([count(call('GET', 'case-source', [], $avi, ['case' => $C, 'source' => 'planning_info'])[1]['checks']), $srcOf($cs($avi, $C), 'planning_info')['status'], code($rec($tal, $C, 'planning_info', 'found', ['identifiers' => $ids, 'result_summary' => 'x'])),
    code(call('POST', 'source-check-plan', ['case' => $C, 'source' => 'planning_info'], $tal)), $srcOf($cs($avi, $C), 'housing_company'), code(call('GET', 'case-source', [], $avi, ['case' => $C, 'source' => 'housing_company'])),
    in_array('planning_info', array_column(call('GET', 'sources', [], $tal)[1]['sources'], 'key'), true)],
    [2, 'hidden', 'source_hidden', 'source_hidden', null, 'not_found', false], 'מקור שהוסתר: בדיקות קיימות נשארות ומוצגות, אי אפשר לפתוח חדשה; מקור מוסתר שלא נבדק בתיק אינו מופיע; הקטלוג לקריאה אינו מציג אותו');

// ── בידוד (09-C3): משרד אחר, מי שאינו משתתף, ומזהי בן ──
$itemPid = $pm[1]['item']['pid'];
$probe = function (string $as) use ($C, $fdPid, $nb, $itemPid): array {
    $gets = [['case-sources', ['case' => $C]], ['case-source', ['case' => $C, 'source' => 'xplan']], ['source-check', ['check' => $fdPid]], ['case-planning', ['case' => $C]], ['planning-item', ['item' => $itemPid]]];
    $posts = [['source-check-plan', ['case' => $C, 'source' => 'xplan']], ['source-check-record', ['case' => $C, 'source' => 'xplan', 'status' => 'found', 'query_text' => 'x', 'result_summary' => 'x']],
        ['source-check-approve', ['check' => $nb]], ['source-check-verify', ['check' => $fdPid]], ['source-check-cost', ['check' => $fdPid, 'cost_amount' => 0]],
        ['planning-add', ['case' => $C, 'kind' => 'plan', 'title' => 'x']], ['planning-update', ['item' => $itemPid, 'title' => 'x']], ['planning-applicability', ['item' => $itemPid, 'applicability' => 'applies', 'note' => 'x']],
        ['planning-delete', ['item' => $itemPid, 'reason' => 'x']], ['planning-task', ['item' => $itemPid]]];
    $out = [];
    foreach ($gets as [$a, $q]) $out[$a] = call('GET', $a, [], $as, $q);
    foreach ($posts as [$a, $in]) $out[$a] = call('POST', $a, $in, $as);
    return $out;
};
$ghost = ['case' => 'zzzzzzzzzzzzzzzz', 'check' => 'zzzzzzzzzzzzzzzz', 'item' => 'zzzzzzzzzzzzzzzz'];
$before = $dumpAll();
$isolated = [];
foreach ([$boaz, $gil] as $who) foreach ($probe($who) as $a => $res) {
    $ref = call(in_array($a, APR_READS, true) ? 'GET' : 'POST', $a, in_array($a, APR_READS, true) ? [] : $ghost + ['source' => 'xplan', 'status' => 'found', 'kind' => 'plan', 'title' => 'x', 'applicability' => 'applies', 'note' => 'x', 'reason' => 'x', 'cost_amount' => 0, 'query_text' => 'x', 'result_summary' => 'x'],
        $who, in_array($a, APR_READS, true) ? $ghost + ['source' => 'xplan'] : []);
    if ($res[0] !== 404 || json_encode($res[1]) !== json_encode(array_replace($ref[1], ['error' => $ref[1]['error']]))) $isolated[] = "$a: {$res[0]} " . code($res);
}
eq([$isolated, $dumpAll() === $before, count($probe($boaz))], [[], true, 15], '09-C3 בידוד: 15 הפעולות על בדיקות ופריטי תכנון, ממשרד אחר וממי שאינו משתתף (גם דרך מזהי בן) ← 404 זהה למזהה שלא קיים, והמסד לא השתנה');
$before = $dumpAll();
$ro = array_map(fn($r) => $r[0], $probe($ron));
eq([array_values(array_unique(array_slice($ro, 0, 5))), array_values(array_unique(array_slice($ro, 5))), $dumpAll() === $before], [[200], [403], true], 'צפייה בלבד: 200 בכל קריאה, 403 בכל כתיבה, והמסד לא השתנה');
call('POST', 'case-member-remove', ['case' => $C3, 'user' => $talPid], $avi);
eq([code($cs($tal, $C3)), code($rec($tal, $C3, 'xplan', 'found', ['query_text' => 'x', 'result_summary' => 'x']))], ['not_found', 'not_found'], 'משתתף שהוסר מהתיק — 404, כמו מי שמעולם לא היה בו');

// ── תיק סגור = קריאה בלבד ──
call('POST', 'case-status', ['case' => $C, 'status' => 'closed', 'reason' => 'בדיקה'], $avi);
$before = $dumpAll();
$closed = $probe($avi);
eq([apr_val('SELECT status FROM cases WHERE pid = ?', [$C]), array_values(array_unique(array_map(fn($r) => $r[0], array_slice($closed, 0, 5)))), array_values(array_unique(array_map('code', array_slice($closed, 5)))), $dumpAll() === $before,
    $closed['case-sources'][1]['perms'], $closed['planning-item'][1]['perms']],
    ['closed', [200], ['case_closed'], true, ['write' => false, 'judge' => false], ['write' => false, 'judge' => false, 'edit_item' => false]], 'תיק סגור: כל קריאה 200, כל כתיבה 409 (גם לשמאי האחראי), והמסד לא השתנה');

// ── 09-C5: שום פרמטר לסיסמה או לקוד של אתר חיצוני; שום קריאה החוצה ──
$code = (string)file_get_contents(__DIR__ . '/../lib/sources.php');
preg_match_all('/\$(?:in|q|src|it|v)\[\'([a-z_]+)\'\]/', $code, $m);
$params = array_values(array_unique($m[1]));
$SECRET = '/pass|pwd|secret|otp|token|credential|pin\b|pin_|cvv|card|login|sms|auth_code|national_id\b|username|user_name|id_number|payment/';
$js = (string)@file_get_contents(__DIR__ . '/../assets/js/screens/sources.js');
preg_match_all('/name="([a-zA-Z_]+)"/', $js, $jm);
eq([array_values(array_filter($params, fn($p) => preg_match($SECRET, $p))), count($params) > 30, array_values(array_filter(array_unique($jm[1]), fn($p) => preg_match($SECRET, strtolower($p)))),
    preg_match('/type="password"|autocomplete="(?:current-password|one-time-code|cc-)/', $js),
    preg_match('/curl_|fsockopen|stream_socket|file_get_contents\s*\(\s*[\'"$]?https?|apr_http|fopen\s*\(\s*[\'"]https?|\bmail\s*\(/', $code), preg_match('/\bfetch\s*\(\s*[\'"`]https?:|XMLHttpRequest|WebSocket|sendBeacon/', $js)],
    [[], true, [], 0, 0, 0], '09-C5 אין בשום פעולה (ובשום טופס במסך) פרמטר שמקבל סיסמה, קוד חד-פעמי או פרטי תשלום של אתר חיצוני; ואין ב-lib/sources.php ובמסך שום קריאה לאתר חיצוני');
eq([$count('source_checks', "performed_by_kind = 'connector'"), $count('sources', "access_mode = 'connector' OR feasibility = 'approved'")], [0, 0], 'אחרי כל הבדיקות: אף בדיקה לא נרשמה כ"connector", ואף מקור לא הפך למחובר');
eq($warnings, [], 'בלי אזהרות PHP');

// ══ סבב 2 (team/reviews/09-sources.md) ══
// 🔴1: בקשת אישור שלא נענתה חוסמת — לפי כל היסטוריית המקור בתיק, לא לפי השורה האחרונה
$CA = $newCase(['kind' => 'other', 'value_text' => 'מגרש בלי מזהה']);
$ask = fn() => apr_rows("SELECT k.status, k.approved_by, k.performed_by FROM source_checks k JOIN sources s ON s.id = k.source_id WHERE k.case_id = ? AND s.key = 'tabu_extract' ORDER BY k.id", [$caseId($CA)]);
$found = ['query_text' => 'גוש 1', 'result_summary' => 'נסח'];
$r1 = $rec($tal, $CA, 'tabu_extract', 'found', $found);
$before = $dumpAll();
$p1 = call('POST', 'source-check-plan', ['case' => $CA, 'source' => 'tabu_extract'], $tal);
$r2 = $rec($tal, $CA, 'tabu_extract', 'found', $found);
$r2b = $rec($noa, $CA, 'tabu_extract', 'found', $found);
eq([array_column($ask(), 'status'), code($r1), $p1[0], $p1[1]['check']['status'] ?? null, code($r2), code($r2b), $dumpAll() === $before],
    [['needs_approval'], 'check_needs_approval', 200, 'needs_approval', 'check_needs_approval', 'check_needs_approval', true],
    '🔴1 הרצף המדויק: needs_approval (ask, מזהה שאינו מספיק) ← record נדחה ← plan של עובדת משרד אינו מוסיף שורה (מוחזרת הבקשה הקיימת) ← record עדיין נדחה; המסד לא השתנה');
call('POST', 'case-identifier-add', ['case' => $CA, 'kind' => 'parcel', 'gush' => '1', 'helka' => '2'], $avi);
$p2 = call('POST', 'source-check-plan', ['case' => $CA, 'source' => 'tabu_extract'], $tal);
eq([array_column($ask(), 'status'), $p2[1]['check']['status'] ?? null, code($rec($tal, $CA, 'tabu_extract', 'found', $found)), count($tasksOf($CA, 'source-check:tabu_extract')), array_column($tasksOf($CA, 'source-approve:tabu_extract'), 'status')],
    [['needs_approval'], 'needs_approval', 'check_needs_approval', 0, ['open']], '🔴1 גם אחרי שנוסף מזהה מספיק: הבקשה אינה הופכת ל"מתוכנן" לבד (apr_sources_on_identifier), plan אינו מחליף אותה, ו-record נדחה');
// שורה שנדחסה ליומן אחרי הבקשה (כמו בגרסה הקודמת) אינה מבטלת אותה
apr_exec("INSERT INTO source_checks (pid, office_id, case_id, source_id, performed_by_kind, performed_by, performed_at, status, created_at, updated_at)
    SELECT 'bbbbbbbbbbbbbbbb', office_id, case_id, source_id, 'user', (SELECT id FROM users WHERE pid = ?), 'x', 'awaiting_identification', 'x', 'x' FROM source_checks WHERE case_id = ? AND status = 'needs_approval'", [$talPid, $caseId($CA)]);
eq([array_column($ask(), 'status'), code($rec($tal, $CA, 'tabu_extract', 'found', $found)), call('POST', 'source-check-plan', ['case' => $CA, 'source' => 'tabu_extract'], $tal)[1]['check']['status'], count($ask())],
    [['needs_approval', 'awaiting_identification'], 'check_needs_approval', 'needs_approval', 2], '🔴1 גם כשהשורה האחרונה אינה needs_approval — הבקשה שלא נענתה עדיין חוסמת');
$CA3 = $newCase(['kind' => 'other', 'value_text' => 'מגרש שלישי']);
apr_exec("INSERT INTO source_checks (pid, office_id, case_id, source_id, performed_by_kind, performed_by, performed_at, status, created_at, updated_at)
    SELECT 'cccccccccccccccc', office_id, case_id, source_id, 'user', (SELECT id FROM users WHERE pid = ?), 'x', 'awaiting_identification', 'x', 'x' FROM source_checks WHERE case_id = ? AND status = 'needs_approval'", [$talPid, $caseId($CA3)]);
call('POST', 'case-identifier-add', ['case' => $CA3, 'kind' => 'parcel', 'gush' => '7', 'helka' => '8'], $avi);
eq([apr_val("SELECT GROUP_CONCAT(k.status) FROM source_checks k JOIN sources s ON s.id = k.source_id WHERE k.case_id = ? AND s.key = 'tabu_extract'", [$caseId($CA3)]), count($tasksOf($CA3, 'source-check:tabu_extract')),
    apr_val("SELECT k.status FROM source_checks k JOIN sources s ON s.id = k.source_id WHERE k.case_id = ? AND s.key = 'xplan' ORDER BY k.id DESC LIMIT 1", [$caseId($CA3)])],
    ['needs_approval,awaiting_identification', 0, 'planned'], '🔴1 מקור ש"ממתין לזיהוי" אבל יש לו בקשת אישור פתוחה אינו הופך ל-planned כשנוסף מזהה (בלי מאשר); מקור בלי בקשה כזו — כן');
$askPid = apr_val("SELECT pid FROM source_checks WHERE case_id = ? AND status = 'needs_approval'", [$caseId($CA)]);
$okA = call('POST', 'source-check-approve', ['check' => $askPid], $dana);
$r3 = $rec($tal, $CA, 'tabu_extract', 'found', $found);
eq([$okA[0], $okA[1]['check']['status'] ?? null, $okA[1]['check']['approved_by'] ?? null, $r3[0], code(call('POST', 'source-check-approve', ['check' => $askPid], $dana))], [200, 'planned', 'דנה', 200, 'check_state'],
    '🔴1 אחרי אישור של שמאית (גם כשהבקשה אינה השורה האחרונה) — הרישום מתקבל; אישור שני נדחה');
$CA2 = $newCase(['kind' => 'other', 'value_text' => 'מגרש אחר']);
$rj = $rec($dana, $CA2, 'tabu_extract', 'found', $found);
$rowJ = apr_row('SELECT * FROM source_checks WHERE pid = ?', [$rj[1]['check']['pid'] ?? '']);
eq([$rj[0], $rj[1]['check']['approved_by'] ?? null, $rowJ['approved_at'] ?? null, array_column($tasksOf($CA2, 'source-approve:tabu_extract'), 'status'), $rec($tal, $CA2, 'tabu_extract', 'needs_clarification', $found)[0]],
    [200, 'דנה', apr_now(), ['done'], 200], '🔴1 שמאית שרושמת בעצמה על מקור שממתין לאישור: השורה נושאת approved_by — האישור רשום ביומן, ולא משתמע');

// 🟡2: מסמך מצורף ברישום התוצאה — דרך צינור הקליטה של 06, עם שיוך לדרישה
$upSrc = function (string $as, string $case, string $source, string $body, array $q = []) {
    $tmp = tempnam(apr_upload_tmp_dir(), 'up');
    file_put_contents($tmp, $body);
    return call('POST', 'source-file-upload', $q + ['case' => $case, 'source' => $source, 'name' => 'nesach.pdf'], $as, [], ['env' => ['upload' => $tmp]]);
};
$pdfBody = fn(string $m) => "%PDF-1.4\n%$m\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
$reqPid = apr_val('SELECT pid FROM requirements WHERE case_id = ? ORDER BY id LIMIT 1', [$caseId($CA)]);
$u1 = $upSrc($tal, $CA, 'tabu_extract', $pdfBody('src-one'), ['requirement' => $reqPid, 'key' => 'up-00000001']);
$u1again = $upSrc($tal, $CA, 'tabu_extract', $pdfBody('src-one'), ['requirement' => $reqPid, 'key' => 'up-00000001']);
$frowU = apr_row('SELECT * FROM files WHERE pid = ?', [$u1[1]['file'] ?? '']);
$withFile = $rec($tal, $CA, 'tabu_extract', 'found', $found + ['file' => $u1[1]['file'] ?? '']);
eq([$u1[0], (int)($frowU['case_id'] ?? 0) === $caseId($CA), $frowU['mime'] ?? null, $u1again[1]['file'] ?? null, $count('requirement_files', 'file_id = ?', [$frowU['id'] ?? 0]),
    apr_val('SELECT status FROM requirements WHERE pid = ?', [$reqPid]), $withFile[1]['check']['file']['pid'] ?? null, $count('audit_log', "action LIKE 'file.%' AND entity_pid = ?", [$u1[1]['file'] ?? '']) > 0],
    [200, true, 'application/pdf', $u1[1]['file'] ?? 'x', 1, 'received', $u1[1]['file'] ?? 'x', true],
    '🟡2 מסמך שמועלה מתוך "רישום התוצאה" עובר את צינור הקליטה של 06 (סוג לפי התוכן, יומן, מזהה פעולה), משויך לדרישה — והדרישה עוברת ל"התקבל" — ומצורף לבדיקה');
$filesBefore = $count('files');
$before = $dumpAll();
$reads = 0;
$noRead = ['env' => ['upload_reader' => function () use (&$reads) { $reads++; return ['upload_error' => 'file_missing']; }]];
eq([code($upSrc($tal, $CA, 'govmap', $pdfBody('no-store'))), code(call('POST', 'source-file-upload', ['case' => $CA, 'source' => 'govmap', 'name' => 'x.pdf'], $tal, [], $noRead)),
    code(call('POST', 'source-file-upload', ['case' => $CA, 'source' => 'tabu_extract', 'name' => 'x.pdf'], $ron, [], $noRead)), code(call('POST', 'source-file-upload', ['case' => $CA, 'source' => 'tabu_extract', 'name' => 'x.pdf'], $gil, [], $noRead)),
    code(call('POST', 'source-file-upload', ['case' => $CA, 'source' => 'tabu_extract', 'name' => 'x.pdf'], $boaz, [], $noRead)), code(call('POST', 'source-file-upload', ['case' => $CA, 'source' => 'planning_info', 'name' => 'x.pdf'], $tal, [], $noRead)),
    code(call('POST', 'source-file-upload', ['case' => $CA, 'source' => 'no_such', 'name' => 'x.pdf'], $tal, [], $noRead)),
    code(call('POST', 'source-file-upload', ['case' => $CA, 'source' => 'tabu_extract', 'name' => 'x.pdf', 'requirement' => apr_val('SELECT pid FROM requirements WHERE case_id = ? LIMIT 1', [$caseId($CB)])], $tal, [], $noRead)),
    code($upSrc($tal, $CA, 'tabu_extract', '<html><script>alert(1)</script></html>')), $reads, $count('files') === $filesBefore, $dumpAll() === $before],
    ['source_store_forbidden', 'source_store_forbidden', 'forbidden', 'not_found', 'not_found', 'source_hidden', 'not_found', 'not_found', 'file_type', 0, true, true],
    '🟡2 / 09-B4 ההעלאה נדחית בשרת **לפני שהגוף נקרא**: מקור עם license.store = no, צופה (403), מי שאינו בתיק / משרד אחר (404), מקור מוסתר, מקור שלא קיים, דרישה של תיק אחר; קובץ שאינו מסוג מותר נדחה בצינור — ושום קובץ לא נשמר');
call('POST', 'case-member-update', ['case' => $CA, 'user' => $yaelPid, 'perms' => ['sources' => true, 'documents' => false]], $avi);
eq([code(call('POST', 'source-file-upload', ['case' => $CA, 'source' => 'tabu_extract', 'name' => 'x.pdf', 'requirement' => $reqPid], $yael, [], $noRead)), $upSrc($yael, $CA, 'tabu_extract', $pdfBody('no-req'))[0]], ['forbidden', 200],
    '🟡2 שיוך לדרישה דורש גם documents; בלי שיוך — sources מספיק');

// 🟡3: יומן הפעילות נושא את ההסתייגות
$act = call('GET', 'case-activity', [], $ron, ['case' => $C])[1]['rows'];
$srcRows = array_values(array_filter($act, fn($r) => $r['entity_type'] === 'source_check'));
$missing = array_values(array_filter($srcRows, fn($r) => !array_key_exists('disclaimer', $r) || $r['disclaimer'] !== (APR_SRC_DISCLAIMERS[((array)$r['meta'])['status'] ?? ''] ?? null)));
eq([$missing, count(array_filter($srcRows, fn($r) => $r['disclaimer'] === APR_SRC_DISCLAIMERS['not_found'])) >= 1, count(array_filter($srcRows, fn($r) => $r['disclaimer'] === APR_SRC_DISCLAIMERS['unavailable'])) >= 1,
    count(array_filter($act, fn($r) => $r['entity_type'] !== 'source_check' && array_key_exists('disclaimer', $r)))], [[], true, true, 0],
    '🟡3 / 09-A2 גם ב-case-activity: כל שורה של בדיקה במצב not_found / unavailable נושאת את נוסח ההסתייגות (ושורה במצב אחר — null)');

// 🟡4: INSERT OR REPLACE אינו עוקף את "שורה אינה נמחקת" (PRAGMA recursive_triggers)
$nfR = apr_row("SELECT * FROM source_checks WHERE status = 'not_found' ORDER BY id LIMIT 1");
$apR = apr_row("SELECT * FROM planning_items WHERE kind = 'application' ORDER BY id LIMIT 1");
$fcR = apr_row('SELECT * FROM facts ORDER BY id LIMIT 1');
$replace = fn(string $t, array $row, array $set) => $raises("INSERT OR REPLACE INTO $t (" . implode(', ', array_keys($set + $row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')', array_values($set + $row));
eq([(int)apr_val('PRAGMA recursive_triggers'), $replace('source_checks', $nfR, ['status' => 'found', 'result_summary' => 'x']), $replace('planning_items', $apR, ['kind' => 'permit']),
    $replace('facts', $fcR, ['value_num' => 1]), $raises('REPLACE INTO sources (id, pid, key, name, owner) SELECT id, pid, key, name, owner FROM sources LIMIT 1'),
    apr_row('SELECT * FROM source_checks WHERE id = ?', [$nfR['id']]) === $nfR, apr_row('SELECT * FROM planning_items WHERE id = ?', [$apR['id']]) === $apR, apr_row('SELECT * FROM facts WHERE id = ?', [$fcR['id']]) === $fcR],
    [1, true, true, true, true, true, true, true], '🟡4 INSERT OR REPLACE ב-SQL ישיר נחסם: not_found אינו נכתב מחדש כ-found, application אינו הופך ל-permit, ונתון אינו נדרס (recursive_triggers דולק בחיבור)');

// 🟡5: נתון שמצטט בדיקה מאומתת ותוקן — המקור מציין שהערך תוקן
$CF = $newCase();
$kf = $rec($tal, $CF, 'govmap', 'found', ['query_text' => 'גוש 6158', 'result_summary' => 'אותר', 'link_url' => 'https://www.govmap.gov.il/']);
call('POST', 'source-check-verify', ['check' => $kf[1]['check']['pid']], $dana);
$f5 = $fadd($tal, $CF, $kf[1]['check']['pid']);
$src0 = $f5[1]['fact']['source'];
$fix = call('POST', 'fact-correct', ['fact' => $f5[1]['fact']['pid'], 'rev' => $f5[1]['fact']['rev'], 'value' => 5], $tal);
$src1 = call('GET', 'fact', [], $ron, ['fact' => $f5[1]['fact']['pid']])[1]['fact']['source'];
eq([$src0['check_status'], $src0['value_corrected'], $fix[0], $src1['check_status'], $src1['value_corrected'], str_contains((string)file_get_contents(__DIR__ . '/../assets/js/screens/facts.js'), 's.value_corrected ?')],
    ['verified', false, 200, 'verified', true, true], '🟡5 נתון שמצטט בדיקה מאומתת ותוקן אחר כך: המקור נושא value_corrected, והמסך מציג "הערך תוקן אחרי הבדיקה" במקום "נבדק ואומת"');

// 🟡6: בדיקה עם ממצא — מתיק אחר — אינה מקור לנתון
eq([apr_val('SELECT status FROM source_checks WHERE pid = ?', [$kf[1]['check']['pid']]), code($fadd($avi, $CJ, $kf[1]['check']['pid'])), code($fadd($boaz, $CB, $kf[1]['check']['pid'])), $fadd($avi, $CF, $kf[1]['check']['pid'])[0]],
    ['verified', 'fact_source', 'fact_source', 200], '🟡6 בדיקה מאומתת של תיק אחר (גם באותו משרד) אינה מקור לנתון — רק בתיק שלה');
eq($warnings, [], 'בלי אזהרות PHP (סבב 2)');

// ── מיגרציה 010 על מסד v9 מאוכלס: כל שורה נשמרת, והזריעה אידמפוטנטית ──
$m9 = sys_get_temp_dir() . '/apr-m9-' . bin2hex(random_bytes(5));
mkdir($m9, 0700, true);
foreach (glob(__DIR__ . '/../lib/migrations/*.sql') as $mf) if ((int)basename($mf) <= 9) copy($mf, $m9 . '/' . basename($mf));
$pdoOpt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$v9 = new PDO("sqlite:$dir/v9.sqlite", null, null, $pdoOpt);
apr_migrate($v9, $m9);
$v9->exec('PRAGMA foreign_keys = OFF');
$v9->exec('ATTACH ' . $v9->quote("$dir/app.sqlite") . ' AS live');
$tables = array_column($v9->query("SELECT name FROM main.sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name <> 'schema_migrations'")->fetchAll(), 'name');
foreach ($tables as $t) { $v9->exec("DELETE FROM main.\"$t\""); $v9->exec("INSERT INTO main.\"$t\" SELECT * FROM live.\"$t\""); }
$v9->exec('DETACH live');
$v9->exec('PRAGMA foreign_keys = ON');
$dump = fn(PDO $d) => array_map(fn($t) => [$t, hash('sha256', json_encode($d->query("SELECT * FROM \"$t\" ORDER BY 1")->fetchAll()))], $tables);
$d9 = $dump($v9);
$rows9 = array_sum(array_map(fn($t) => (int)$v9->query("SELECT COUNT(*) FROM \"$t\"")->fetchColumn(), $tables));
$had = [in_array('sources', $tables, true), (int)$v9->query('SELECT COUNT(*) FROM facts')->fetchColumn() > 0, (int)$v9->query('SELECT COUNT(*) FROM cases')->fetchColumn() > 3];
apr_migrate($v9);
$freshDb = new PDO('sqlite::memory:', null, null, $pdoOpt);
apr_migrate($freshDb);
$shape = fn(PDO $d) => json_encode($d->query("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' AND name <> 'schema_migrations' ORDER BY name")->fetchAll());
$seedSql = preg_match('/INSERT OR IGNORE INTO sources.*?;\n/s', (string)file_get_contents(__DIR__ . '/../lib/migrations/010_sources.sql'), $sm) ? $sm[0] : '';
$v9->exec("UPDATE sources SET name = 'שם שהמנהל שינה', license = '{\"store\":\"no\"}' WHERE key = 'xplan'");
$pids = $v9->query('SELECT key, pid FROM sources ORDER BY key')->fetchAll();
$v9->exec($seedSql);
$v9->exec($seedSql);
eq([apr_schema_version($v9), $had, $rows9 > 100, $dump($v9) === $d9, $shape($v9) === $shape($freshDb), (int)$v9->query('SELECT COUNT(*) FROM sources')->fetchColumn(), $seedSql !== '',
    $v9->query("SELECT name || '|' || license FROM sources WHERE key = 'xplan'")->fetchColumn(), $v9->query('SELECT key, pid FROM sources ORDER BY key')->fetchAll() === $pids,
    (int)$v9->query('SELECT (SELECT COUNT(*) FROM source_checks) + (SELECT COUNT(*) FROM planning_items)')->fetchColumn(), count($v9->query('PRAGMA foreign_key_check')->fetchAll()),
    trim((string)shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' diff --stat origin/main -- lib/migrations/00[1-9]_*.sql 2>/dev/null'))],
    [10, [false, true, true], true, true, true, 13, true, 'שם שהמנהל שינה|{"store":"no"}', true, 0, 0, ''],
    'מיגרציה 010 על מסד v9 מאוכלס: כל שורה בכל טבלה קיימת נשמרת כפי שהיא, המבנה זהה למסד חדש, הקטלוג נזרע (13) — וזריעה חוזרת אינה מכפילה ואינה דורסת שינוי של המנהל; 001–009 לא נגעו');
$v9 = null;
$rm = function (string $d) use (&$rm) { foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $f) is_dir("$d/$f") ? $rm("$d/$f") : @unlink("$d/$f"); @rmdir($d); };
$rm($m9);

done();
