<?php
// פרוסה 06 — מסמכים (docs/spec/06-documents.md §12): 06-A1 … 06-D3, מקרי הקצה, ההרשאות, הבידוד והמרוצים.
if (in_array($argv[1] ?? '', ['reviewer', 'uploader'], true)) {
    // תהליך-בן למרוץ: כולם יוצאים באותו רגע, על אותו קובץ מסד
    define('APR_DATA_DIR', $argv[2]);
    define('APR_DB_PATH', $argv[2] . '/app.sqlite');
    require __DIR__ . '/lib.php';
    $as = ident('2', 'אבי', 'avi@example.com');
    call('GET', 'me', [], $as);
    if ($argv[1] === 'uploader') {
        $tmp = tempnam(apr_upload_tmp_dir(), 'up');
        file_put_contents($tmp, "%PDF-1.4\n1 0 obj << /Type /Page >> endobj\n%%EOF\n");
    }
    while (microtime(true) < (float)$argv[4]) usleep(200);
    $r = $argv[1] === 'reviewer'
        ? call('POST', 'file-review', ['link' => $argv[3], 'do' => 'approve', 'chk_readable' => true, 'chk_complete' => true, 'chk_matches' => true], $as)
        : call('POST', 'file-upload', ['case' => $argv[3], 'requirement' => $argv[5], 'name' => 'a.pdf', 'key' => 'same-key-123'], $as, [], ['env' => ['upload' => $tmp]]);
    echo $r[0] . ' ' . ($r[1]['file'] ?? '');
    exit(0);
}
require __DIR__ . '/lib.php';

$warnings = [];
$GLOBALS['apr_upload_rate'] = [100000, 600];   // הקצב נבדק בנפרד, למטה
set_error_handler(function (int $no, string $msg, string $file, int $line) use (&$warnings) { if (!(error_reporting() & $no)) return true; $warnings[] = "$msg @" . basename($file) . ":$line"; return true; });

$owner = ident('1', 'הבעלים', 'owner@example.com', ['platform_admin' => true]);
$avi = ident('2', 'אבי', 'avi@example.com');       // מנהל משרד א, שמאי — האחראי על התיקים
$dana = ident('3', 'דנה', 'dana@example.com');     // שמאית: שותפה (documents + review_docs)
$tal = ident('5', 'טל', 'tal@example.com');        // עובדת משרד: documents בלי review_docs
$gil = ident('7', 'גיל', 'gil@example.com');       // שמאי במשרד א שאינו משתתף בתיקים
$ron = ident('8', 'רון', 'ron@example.com');       // עובד משרד: יצורף עם view בלבד
$boaz = ident('4', 'בועז', 'boaz@example.com');    // משרד ב
call('GET', 'me', [], $owner);
office_for($avi, 'משרד א');
office_for($boaz, 'משרד ב');
$danaPid = join_office($avi, $dana, 'dana@example.com', 'appraiser');
$talPid = join_office($avi, $tal, 'tal@example.com', 'staff');
join_office($avi, $gil, 'gil@example.com', 'appraiser');
$ronPid = join_office($avi, $ron, 'ron@example.com', 'staff');

function req(string $key, array $more = []): array
{
    return $more + ['key' => $key, 'group' => '', 'doc_type' => 'rights', 'title' => "דרישה $key", 'priority' => 'normal', 'mandatory' => false, 'party_kind' => 'client',
        'due' => ['mode' => 'relative', 'days' => 3], 'needs_review' => true, 'when' => null, 'sort' => 1];
}
function make_profile(string $as, string $name): string
{
    $pid = call('POST', 'profile-create', ['scope' => 'office', 'name' => $name], $as)[1]['profile'];
    $content = ['flags' => [], 'requirements' => ['groups' => [['key' => 'legal', 'title' => 'משפטי']], 'items' => [req('rights', ['mandatory' => true, 'title' => 'נסח טאבו']), req('contract', ['title' => 'הסכם מכר', 'sort' => 2]),
        req('plan', ['title' => 'תשריט', 'sort' => 3]), req('id', ['title' => 'צילום תעודת זהות', 'sort' => 4]), req('permit', ['title' => 'היתר בנייה', 'sort' => 5])]], 'tasks' => ['items' => []], 'rules' => []];
    [, $d] = call('POST', 'profile-draft-open', ['profile' => $pid], $as);
    $s = call('POST', 'profile-draft-save', ['profile' => $pid, 'content' => $content, 'revision' => $d['draft']['revision']], $as);
    $r = call('POST', 'profile-publish', ['profile' => $pid, 'note' => '', 'revision' => $s[1]['revision'] ?? ''], $as);
    if ($r[0] !== 200) throw new RuntimeException('publish: ' . json_encode([$s[1], $r[1]], JSON_UNESCAPED_UNICODE));
    return $pid;
}
function make_case(string $as, string $profile, string $title = 'דירה ברחוב הרצל'): string
{
    $c = call('POST', 'case-create', ['profile' => $profile], $as)[1]['case'];
    call('POST', 'case-update', ['case' => $c, 'title' => $title, 'purpose' => 'purchase'], $as);
    call('POST', 'case-identifier-add', ['case' => $c, 'kind' => 'parcel', 'gush' => '6158', 'helka' => '12'], $as);
    call('POST', 'case-party-add', ['case' => $c, 'name' => 'משה לקוח', 'kind' => 'client', 'is_orderer' => true], $as);
    $s = call('POST', 'case-start', ['case' => $c], $as);
    if ($s[0] !== 200) throw new RuntimeException('case-start: ' . json_encode($s[1], JSON_UNESCAPED_UNICODE));
    return $c;
}

// ── קבצים לדוגמה ──
function pdf(string $extra = '', string $mark = ''): string
{
    return "%PDF-1.4\n%$mark\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R >>\nendobj\n"
        . "4 0 obj\n<< /Length 9 >>\nstream\nBT (x) Tj ET\nendstream\nendobj\n$extra\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
}
function img(string $type, int $w = 40, int $h = 30): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, 160));
    ob_start();
    $type === 'png' ? imagepng($im) : ($type === 'webp' ? imagewebp($im) : imagejpeg($im));
    return (string)ob_get_clean();
}
function zip(array $entries): string
{
    $f = tempnam(sys_get_temp_dir(), 'z');
    $z = new ZipArchive();
    $z->open($f, ZipArchive::OVERWRITE);
    foreach ($entries as $name => $body) $z->addFromString($name, $body);
    $z->close();
    $out = (string)file_get_contents($f);
    unlink($f);
    return $out;
}
const CT = '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>';

/** העלאה: הגוף נכתב לקובץ זמני, כמו ש-api.php עושה, והנתיב עובר ב-$env. */
function up(string $as, string $bytes, array $q, array $env = []): array
{
    $tmp = tempnam(apr_upload_tmp_dir(), 'up');
    file_put_contents($tmp, $bytes);
    $GLOBALS['t_tmp'] = $tmp;
    return call('POST', 'file-upload', $q + ['name' => 'מסמך.pdf'], $as, [], ['env' => $env + ['upload' => $tmp]]);
}
$approve = fn(string $as, string $link, array $more = []) => call('POST', 'file-review', $more + ['link' => $link, 'do' => 'approve', 'chk_readable' => true, 'chk_complete' => true, 'chk_matches' => true], $as);
$crow = fn(string $pid) => apr_row('SELECT * FROM cases WHERE pid = ?', [$pid]);
$rrow = fn(string $pid) => apr_row('SELECT * FROM requirements WHERE pid = ?', [$pid]);
$frow = fn(string $pid) => apr_row('SELECT * FROM files WHERE pid = ?', [$pid]);
$lrow = fn(string $pid) => apr_row('SELECT * FROM requirement_files WHERE pid = ?', [$pid]);
$nfiles = fn() => (int)apr_val('SELECT COUNT(*) FROM files');
$disk = function (): int {
    $n = 0;
    if (is_dir(apr_files_dir())) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(apr_files_dir(), FilesystemIterator::SKIP_DOTS)) as $f) $n++;
    return $n;
};
$docs = fn(string $as, string $case) => call('GET', 'case-docs', [], $as, ['case' => $case]);
$rq = function (string $case) use ($crow): array {
    return array_column(apr_rows('SELECT * FROM requirements WHERE case_id = ? ORDER BY id', [$crow($case)['id']]), null, 'origin_key');
};
$log = fn(string $case, string $action) => apr_rows('SELECT * FROM audit_log WHERE case_id = ? AND action = ? ORDER BY id', [$crow($case)['id'], $action]);

$P = make_profile($avi, 'דירה לרכישה');
$C = make_case($avi, $P);
$D = make_case($avi, $P, 'תיק אחר');                 // רק אבי משתתף
call('POST', 'case-member-add', ['case' => $C, 'user' => $danaPid], $avi);
call('POST', 'case-member-add', ['case' => $C, 'user' => $talPid], $avi);
call('POST', 'case-member-add', ['case' => $C, 'user' => $ronPid, 'role' => 'visitor'], $avi);
$R = array_map(fn($r) => $r['pid'], $rq($C));
$RD = array_map(fn($r) => $r['pid'], $rq($D));
eq([apr_row('SELECT perms FROM case_members cm JOIN users u ON u.id = cm.user_id WHERE u.pid = ? AND cm.case_id = ?', [$talPid, $crow($C)['id']]) !== null,
    $docs($tal, $C)[1]['perms'], $docs($dana, $C)[1]['perms'], $docs($ron, $C)[1]['perms']],
    [true, ['documents' => true, 'review_docs' => false], ['documents' => true, 'review_docs' => true], ['documents' => false, 'review_docs' => false]], 'הכנה: טל documents בלבד, דנה שתיהן, רון צפייה');

// ── 06-A1: העלאה לדרישה ← received, הקובץ pending — גם כשהמעלה הוא האחראי ──
$r = up($avi, pdf(), ['case' => $C, 'requirement' => $R['rights'], 'name' => 'נסח טאבו.pdf']);
$F1 = $r[1]['file'] ?? '';
$L1 = $r[1]['link'] ?? '';
$f = $frow($F1);
eq([$r[0], $rrow($R['rights'])['status'], $lrow($L1)['review_state'], (int)$lrow($L1)['version_no'], $lrow($L1)['relation'], $crow($C)['responsible_user_id'] === $f['uploader_id']],
    [200, 'received', 'pending', 1, 'version', true], '06-A1 האחראי מעלה לדרישה ← received, השיוך pending, גרסה 1');
eq([$f['mime'], $f['ext'], $f['original_name'], $f['channel'], $f['uploader_kind'], $f['scan_state'], (int)$f['pages'], $f['sha256'], (int)$f['size']],
    ['application/pdf', 'pdf', 'נסח טאבו.pdf', 'internal', 'user', 'not_scanned', 1, hash('sha256', pdf()), strlen(pdf())], '06-A1 שורת הקובץ: סוג מהתוכן, hash, גודל, ערוץ, מעלה');
check('06-A1 הקובץ הזמני נמחק, והקובץ נשמר תחת data/files', !is_file($GLOBALS['t_tmp']) && is_file(apr_files_dir() . '/' . $f['storage_key']) && file_get_contents(apr_files_dir() . '/' . $f['storage_key']) === pdf());
eq([count($log($C, 'file.upload')), json_decode($log($C, 'file.upload')[0]['meta'], true)['requirement'] ?? null], [1, $R['rights']], '06-A1 נרשם בפעילות התיק');
eq([$rrow($R['rights'])['status_by'] === $f['uploader_id'], $rrow($R['rights'])['status_at'] !== null], [true, true], '06-A1 נרשם מי העביר את הדרישה ומתי');

// ── 06-A2: אישור ──
$none = call('POST', 'file-review', ['link' => $L1, 'do' => 'approve'], $avi);
$two = call('POST', 'file-review', ['link' => $L1, 'do' => 'approve', 'chk_readable' => true, 'chk_complete' => true, 'chk_matches' => false], $avi);
$str = call('POST', 'file-review', ['link' => $L1, 'do' => 'approve', 'chk_readable' => 'true', 'chk_complete' => 1, 'chk_matches' => true], $avi);
eq([code($none), code($two), code($str), $lrow($L1)['review_state'], $rrow($R['rights'])['status']], ['review_incomplete', 'review_incomplete', 'review_incomplete', 'pending', 'received'], '06-A2 אישור בלי שלוש התיבות (או עם ערך שאינו true) ← נדחה, ושום דבר לא זז');
$ok = $approve($avi, $L1, ['note' => 'נבדק מול המקור']);
$l = $lrow($L1);
eq([$ok[0], $ok[1]['status'] ?? null, $l['review_state'], $l['reviewed_by'] === $f['uploader_id'], $l['reviewed_at'] !== null, [$l['chk_readable'], $l['chk_complete'], $l['chk_matches']], $l['review_note'], $rrow($R['rights'])['status']],
    [200, 'approved', 'approved', true, true, [1, 1, 1], 'נבדק מול המקור', 'approved'], '06-A2 עם שלוש התיבות ← approved, עם בודק וזמן');
eq(count($log($C, 'file.approve')), 1, '06-A2 האישור נרשם');

// ── מקרה 6: שני בודקים על אותו קובץ ──
$again = $approve($dana, $L1);
eq([$again[0], code($again), $again[1]['error']['review']['by'] ?? null, $again[1]['error']['review']['state'] ?? null, count($log($C, 'file.approve'))], [409, 'already_reviewed', 'אבי', 'approved', 1], 'מקרה 6: הבודק השני מקבל "כבר נבדק בידי אבי", עם התוצאה');

// ── 06-A3: החזרה להשלמה, וגרסה 2 ──
$r = up($tal, pdf('', 'contract-1'), ['case' => $C, 'requirement' => $R['contract']]);
[$F2, $L2] = [$r[1]['file'], $r[1]['link']];
eq([code(call('POST', 'file-review', ['link' => $L2, 'do' => 'return'], $dana)), code(call('POST', 'file-review', ['link' => $L2, 'do' => 'return', 'return_code' => 'nope'], $dana)),
    code(call('POST', 'file-review', ['link' => $L2, 'do' => 'return', 'return_code' => 'other'], $dana)), $rrow($R['contract'])['status']],
    ['return_code_required', 'content_invalid', 'reason_required', 'received'], '06-A3 החזרה בלי סיבה מהרשימה ← נדחית; "אחר" דורש הסבר');
$ret = call('POST', 'file-review', ['link' => $L2, 'do' => 'return', 'return_code' => 'missing_pages', 'return_reason' => 'חסר עמוד 3', 'chk_readable' => true, 'chk_complete' => false], $dana);
$l = $lrow($L2);
eq([$ret[0], $rrow($R['contract'])['status'], $rrow($R['contract'])['status_reason'], $l['review_state'], $l['return_code'], $l['return_reason'], [$l['chk_readable'], $l['chk_complete'], $l['chk_matches']]],
    [200, 'returned', 'חסר עמוד 3', 'returned', 'missing_pages', 'חסר עמוד 3', [1, 0, null]], '06-A3 החזרה ← returned, עם הסיבה; תיבה שלא סומנה נשארת NULL');
$view = $docs($avi, $C)[1];
$creq = array_column($view['requirements'], null, 'pid')[$R['contract']];
eq([count($creq['files']), $creq['files'][0]['file']['pid'], $creq['files'][0]['review_state'], call('GET', 'file', [], $avi, ['file' => $F2])[0]], [1, $F2, 'returned', 200], '06-A3 הקובץ שהוחזר עדיין בהיסטוריה, ואפשר להוריד אותו');
$r = up($tal, pdf('', 'contract-2'), ['case' => $C, 'requirement' => $R['contract']]);
$L2b = $r[1]['link'];
eq([$r[0], $r[1]['version'] ?? null, $rrow($R['contract'])['status'], $rrow($R['contract'])['status_reason'], $lrow($L2)['review_state'], $lrow($L2b)['review_state']], [200, 2, 'received', null, 'returned', 'pending'], '06-A3 קובץ חדש ← received, כגרסה 2; הקודמת נשארת "הוחזרה"');
$creq = array_column($docs($avi, $C)[1]['requirements'], null, 'pid')[$R['contract']];
eq(array_map(fn($x) => [$x['version'], $x['current']], $creq['files']), [[2, true], [1, false]], '06-A3 הגרסה החדשה היא הנוכחית');
eq(code($approve($dana, $L2)), 'already_reviewed', 'גרסה שכבר נבדקה אינה נבדקת שוב');

// ── מקרה 7: אושר, ואז גרסה חדשה ──
$approve($dana, $L2b);
$r = up($tal, pdf('', 'contract-3'), ['case' => $C, 'requirement' => $R['contract']]);
$L2c = $r[1]['link'];
eq([$rrow($R['contract'])['status'], $r[1]['version'], $lrow($L2b)['review_state'], $lrow($L2b)['reviewed_at'] !== null], ['received', 3, 'approved', true], 'מקרה 7: גרסה חדשה לדרישה מאושרת ← received; האישור הקודם נשאר על הגרסה שלו');
// קובץ נלווה מצטרף לגרסה הנוכחית, ונבדק בנפרד
$r = up($tal, img('jpeg'), ['case' => $C, 'requirement' => $R['contract'], 'relation' => 'attachment', 'name' => 'נספח.jpg']);
$L2att = $r[1]['link'];
eq([$r[1]['relation'], $r[1]['version'], $rrow($R['contract'])['status']], ['attachment', 3, 'received'], 'קובץ נלווה מצטרף לגרסה הנוכחית');
$approve($dana, $L2c);
eq($rrow($R['contract'])['status'], 'received', 'הגרסה אושרה אבל הנלווה עוד ממתין ← הדרישה עדיין received');
$approve($dana, $L2att);
eq($rrow($R['contract'])['status'], 'approved', 'כשכל קובצי הגרסה הנוכחית אושרו ← approved');
$r = up($tal, img('png'), ['case' => $C, 'requirement' => $R['id'], 'relation' => 'attachment', 'name' => 'ת.ז.png']);
eq([$r[1]['relation'], $r[1]['version']], ['version', 1], 'הקובץ הראשון של דרישה הוא תמיד גרסה, גם אם התבקש "נלווה"');
$Lid = $r[1]['link'];

// ── 06-A4: "לא רלוונטי" ──
eq([code(call('POST', 'requirement-status', ['requirement' => $R['plan'], 'status' => 'not_relevant'], $avi)), code(call('POST', 'requirement-status', ['requirement' => $R['plan'], 'status' => 'not_relevant', 'reason' => '   '], $avi)),
    code(call('POST', 'requirement-status', ['requirement' => $R['plan'], 'status' => 'not_relevant', 'reason' => 'אין תשריט'], $tal)), $rrow($R['plan'])['status']],
    ['reason_required', 'reason_required', 'forbidden', 'required'], '06-A4 not_relevant בלי נימוק ← נדחה; בלי review_docs ← נדחה');
$r = call('POST', 'requirement-status', ['requirement' => $R['plan'], 'status' => 'not_relevant', 'reason' => 'אין תשריט לנכס'], $dana);
eq([$r[0], $rrow($R['plan'])['status'], $rrow($R['plan'])['status_reason'], count($log($C, 'requirement.status'))], [200, 'not_relevant', 'אין תשריט לנכס', 1], '06-A4 עם נימוק ועם review_docs ← not_relevant, ונרשם');
eq([code(up($tal, pdf('', 'nr'), ['case' => $C, 'requirement' => $R['plan']])), $nfiles()], ['req_state', 6], 'העלאה לדרישה "לא רלוונטי" ← נדחית, ואין קובץ');
eq([code(call('POST', 'requirement-status', ['requirement' => $R['plan'], 'status' => 'required'], $dana)), code(call('POST', 'requirement-status', ['requirement' => $R['plan'], 'status' => 'required', 'reason' => 'בכל זאת'], $tal)),
    call('POST', 'requirement-status', ['requirement' => $R['plan'], 'status' => 'required', 'reason' => 'התקבל תשריט'], $dana)[0], $rrow($R['plan'])['status']],
    ['reason_required', 'forbidden', 200, 'required'], '06-A4 ביטול הסימון ← required, בנימוק, ורק לבעל review_docs');

// ── 06-A5: מכונת המצבים ──
$table = ['required' => ['requested', 'received', 'not_relevant'], 'requested' => ['received', 'not_relevant', 'required'], 'received' => ['approved', 'returned'],
    'returned' => ['received', 'not_relevant'], 'approved' => ['received'], 'not_relevant' => ['required']];
$bad = [];
foreach (APR_REQ_STATUSES as $from) foreach (APR_REQ_STATUSES as $to) if (apr_req_can($from, $to) !== in_array($to, $table[$from], true)) $bad[] = "$from>$to";
eq([$bad, count(APR_REQ_STATUSES)], [[], 6], '06-A5 הטבלה: בדיוק המעברים שבאפיון, לכל 36 הזוגות');
$set = fn(string $pid, string $status) => apr_exec('UPDATE requirements SET status = ? WHERE pid = ?', [$status, $pid]);
$try = fn(string $pid, string $to, string $as) => code(call('POST', 'requirement-status', ['requirement' => $pid, 'status' => $to, 'reason' => 'בדיקה'], $as));
$got = [];
foreach (['required' => ['approved', 'returned', 'received', 'requested'], 'received' => ['not_relevant', 'required', 'approved', 'returned'], 'returned' => ['required', 'approved'],
    'approved' => ['required', 'returned', 'not_relevant'], 'not_relevant' => ['approved', 'received', 'returned'], 'requested' => ['approved', 'returned']] as $from => $tos) {
    foreach ($tos as $to) { $set($R['permit'], $from); $got["$from>$to"] = [$try($R['permit'], $to, $avi), $rrow($R['permit'])['status']]; }
}
eq(array_values(array_unique(array_map(fn($g) => $g[0], $got))), ['req_transition'], '06-A5 מעבר שאינו בטבלה (required ← approved ועוד) — וגם מעבר שקורה רק מאירוע — נדחה ב-requirement-status');
check('06-A5 ושום מצב לא השתנה', !array_filter($got, fn($g, $k) => $g[1] !== explode('>', $k)[0], ARRAY_FILTER_USE_BOTH), $got);
eq(code(call('POST', 'requirement-status', ['requirement' => $R['permit'], 'status' => 'bogus', 'reason' => 'x'], $avi)), 'content_invalid', 'מצב לא מוכר');
$set($R['permit'], 'requested');
eq([call('POST', 'requirement-status', ['requirement' => $R['permit'], 'status' => 'required'], $tal)[0], $rrow($R['permit'])['status']], [200, 'required'], '06-A5 requested ← required (הבקשה בוטלה): documents מספיק, בלי נימוק');
$set($R['permit'], 'requested');
eq([code(call('POST', 'requirement-status', ['requirement' => $R['permit'], 'status' => 'required'], $ron)), call('POST', 'requirement-status', ['requirement' => $R['permit'], 'status' => 'not_relevant', 'reason' => 'אין היתר'], $dana)[0]], ['forbidden', 200], '06-A5 requested ← not_relevant; בלי documents — אין ביטול בקשה');
call('POST', 'requirement-status', ['requirement' => $R['permit'], 'status' => 'required', 'reason' => 'חזר'], $dana);
$set($R['permit'], 'requested');
$r = up($tal, pdf('', 'permit-1'), ['case' => $C, 'requirement' => $R['permit']]);
$Lp = $r[1]['link'];
eq([$r[0], $rrow($R['permit'])['status'], $lrow($Lp)['status_before']], [200, 'received', 'requested'], '06-A5 requested ← received (קובץ)');
call('POST', 'file-review', ['link' => $Lp, 'do' => 'return', 'return_code' => 'unreadable'], $dana);
eq([$rrow($R['permit'])['status'], call('POST', 'requirement-status', ['requirement' => $R['permit'], 'status' => 'not_relevant', 'reason' => 'ויתרנו'], $dana)[0], $rrow($R['permit'])['status']], ['returned', 200, 'not_relevant'], '06-A5 received ← returned ← not_relevant');
// approved ← received: "פתיחה לבדיקה מחדש"
eq([code(call('POST', 'requirement-status', ['requirement' => $R['rights'], 'status' => 'received'], $dana)), code(call('POST', 'requirement-status', ['requirement' => $R['rights'], 'status' => 'received', 'reason' => 'x'], $tal)), $rrow($R['rights'])['status']],
    ['reason_required', 'forbidden', 'approved'], 'פתיחה לבדיקה מחדש: נימוק, ו-review_docs');
$r = call('POST', 'requirement-status', ['requirement' => $R['rights'], 'status' => 'received', 'reason' => 'התגלתה הערת אזהרה'], $dana);
eq([$r[0], $rrow($R['rights'])['status'], $lrow($L1)['review_state']], [200, 'received', 'pending'], '06-A5 approved ← received בנימוק: הגרסה הנוכחית חוזרת ל-pending');
eq([$approve($dana, $L1)[0], $rrow($R['rights'])['status']], [200, 'approved'], 'ואפשר לאשר שוב');

// ── 06-B1: סיומת pdf, תוכן אחר ──
$n = $nfiles(); $d = $disk();
$exe = "MZ\x90\x00\x03\x00\x00\x00" . str_repeat("\x00", 200) . 'This program cannot be run in DOS mode';
$r = up($avi, $exe, ['case' => $C, 'requirement' => $R['plan'], 'name' => 'נסח.pdf']);
$rej = $log($C, 'file.rejected');
eq([$r[0], code($r), $nfiles() - $n, $disk() - $d, $rrow($R['plan'])['status'], is_file($GLOBALS['t_tmp'])], [415, 'file_type', 0, 0, 'required', false], '06-B1 סיומת .pdf ותוכן של קובץ הרצה ← נדחה; אין שורה, אין קובץ, הדרישה לא זזה');
eq([count($rej), json_decode($rej[0]['meta'], true)['sha256'] ?? null, $rej[0]['entity_pid']], [1, hash('sha256', $exe), $C], '06-B1 נשמרת רק שורת יומן עם ה-hash');
$html = '<html><script>alert(1)</script></html>';
eq([code(up($avi, $html, ['case' => $C, 'name' => 'a.pdf'])), code(up($avi, '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', ['case' => $C, 'name' => 'a.png'])), code(up($avi, '', ['case' => $C])),
    code(up($avi, substr(pdf(), 0, 60), ['case' => $C])), code(up($avi, "\xFF\xD8\xFF\xE0" . str_repeat('x', 300), ['case' => $C, 'name' => 'a.jpg'])), code(up($avi, "\x89PNG\r\n\x1a\n" . $html, ['case' => $C])),
    code(up($avi, "PK\x03\x04" . str_repeat("\x00", 100), ['case' => $C])), $nfiles() - $n],
    ['file_type', 'file_type', 'file_type', 'file_mismatch', 'file_mismatch', 'file_mismatch', 'file_mismatch', 0], '06-B1 HTML, SVG, קובץ ריק (מקרה 2), PDF קטוע, ותמונה / ZIP פגומים ← נדחים');
eq([up($avi, img('jpeg'), ['case' => $D, 'name' => 'x.exe'])[0], up($avi, img('png'), ['case' => $D, 'name' => 'x'])[0], up($avi, img('webp'), ['case' => $D])[0],
    up($avi, "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic" . str_repeat("\x00", 64), ['case' => $D, 'name' => 'IMG_1.HEIC'])[0], up($avi, zip(['a.txt' => 'hello', 'sig.p7s' => 'x']), ['case' => $D])[0]],
    [200, 200, 200, 200, 200], 'JPEG, PNG, WEBP, HEIC ו-ZIP מתקבלים — לפי התוכן, לא לפי השם');
eq(array_column(apr_rows('SELECT mime, ext FROM files WHERE case_id = ? ORDER BY id', [$crow($D)['id']]), 'ext', 'mime'),
    ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic', 'application/zip' => 'zip'], 'הסיומת נגזרת מהסוג שזוהה');

// ── 06-B2: תוכן פעיל ──
$n = $nfiles();
$objstm = "5 0 obj\n<< /Type /ObjStm /N 1 /First 4 /Filter /FlateDecode >>\nstream\n" . gzcompress("6 0 << /S /JavaScript /JS (app.alert(1)) >>") . "\nendstream\nendobj";
eq([code(up($avi, pdf('5 0 obj << /S /JavaScript /JS (app.alert(1)) >> endobj'), ['case' => $C])), code(up($avi, pdf('5 0 obj << /OpenAction << /S /J#53 (x) >> >> endobj'), ['case' => $C])),
    code(up($avi, pdf($objstm), ['case' => $C])), code(up($avi, pdf('5 0 obj << /Type /Filespec /EF << /F 6 0 R >> >> endobj 6 0 obj << /Type /EmbeddedFile >> endobj'), ['case' => $C])),
    code(up($avi, pdf('5 0 obj << /S /Launch /F (cmd.exe) >> endobj'), ['case' => $C])), $nfiles() - $n],
    ['file_active', 'file_active', 'file_active', 'file_active', 'file_active', 0], '06-B2 PDF עם JavaScript (גם בשם מוסווה ובזרם אובייקטים), קובץ מוטמע או הפעלה ← נדחה');
$docx = zip(['[Content_Types].xml' => CT, 'word/document.xml' => '<w:document/>']);
$xlsx = zip(['[Content_Types].xml' => CT, 'xl/workbook.xml' => '<workbook/>']);
$macro = zip(['[Content_Types].xml' => CT, 'word/document.xml' => '<w:document/>', 'word/vbaProject.bin' => 'x']);
$docm = zip(['[Content_Types].xml' => str_replace('application/xml', 'application/vnd.ms-word.document.macroEnabled.main+xml', CT), 'word/document.xml' => '<w:document/>']);
$xlsm = zip(['[Content_Types].xml' => CT, 'xl/workbook.xml' => '<workbook/>', 'xl/VBAPROJECT.BIN' => 'x']);
eq([code(up($avi, $macro, ['case' => $C, 'name' => 'a.docx'])), code(up($avi, $docm, ['case' => $C, 'name' => 'a.docx'])), code(up($avi, $xlsm, ['case' => $C, 'name' => 'a.xlsx'])),
    code(up($avi, zip(['[Content_Types].xml' => CT, 'ppt/presentation.xml' => '<p/>']), ['case' => $C])), $nfiles() - $n],
    ['file_active', 'file_active', 'file_active', 'file_type', 0], '06-B2 DOCX / XLSX עם מאקרו ← נדחים; מסמך Office אחר — לא נתמך');
check('06-B2 כל דחייה נרשמה עם hash', count($log($C, 'file.rejected')) >= 10 && json_decode($log($C, 'file.rejected')[9]['meta'], true)['why'] !== '');
$r1 = up($avi, $docx, ['case' => $D, 'name' => 'חוזה.docx']);
$r2 = up($avi, $xlsx, ['case' => $D, 'name' => 'טבלה.pdf']);
eq([$r1[0], $frow($r1[1]['file'])['mime'], $r2[0], $frow($r2[1]['file'])['ext']], [200, APR_MIME_DOCX, 200, 'xlsx'], 'DOCX ו-XLSX נקיים מתקבלים');
$enc = up($avi, pdf('7 0 obj << /Filter /Standard /V 2 >> endobj trailer << /Encrypt 7 0 R >>', 'enc'), ['case' => $D]);
eq([$enc[0], (int)$frow($enc[1]['file'])['is_encrypted'], $frow($enc[1]['file'])['pages']], [200, 1, null], 'מקרה 5: PDF מוגן בסיסמה נשמר ומסומן');
// סורק
$GLOBALS['apr_scanner'] = fn() => 'rejected';
$n = $nfiles(); $d = $disk();
eq([code(up($avi, pdf('', 'virus'), ['case' => $C])), $nfiles() - $n, $disk() - $d, $docs($avi, $C)[1]['limits']['scanner']], ['file_infected', 0, 0, true], 'הסורק מצא איום ← נדחה, ולא נשמר');
$GLOBALS['apr_scanner'] = fn() => 'clean';
$cl = up($avi, pdf('', 'clean'), ['case' => $D]);
unset($GLOBALS['apr_scanner']);
$GLOBALS['apr_scanner_bin'] = null;
eq([$frow($cl[1]['file'])['scan_state'], $frow($F1)['scan_state'], $docs($avi, $C)[1]['limits']['scanner']], ['clean', 'not_scanned', false], '06-E4 בלי סורק: scan_state = not_scanned, והתשובה אומרת שאין סורק');

// ── סבב 2, 🟡3: עקיפות של בדיקת התוכן הפעיל ב-PDF — כל אחת נדחית ──
$n = $nfiles();
$hex = fn(string $s) => strtoupper(bin2hex($s)) . '>';
$evasions = [
    'הערה שמסתירה (% stream)' => pdf("% stream\n5 0 obj << /S /JavaScript /JS (app.alert(1)) >> endobj\n% endstream"),
    'בתוך מחרוזת' => pdf("5 0 obj << /T (stream\n) /JS 6 0 R /X (endstream) >> endobj"),
    'ObjStm בלי Filter' => pdf("5 0 obj\n<< /Type /ObjStm /N 1 /First 4 >>\nstream\n6 0 << /S /JavaScript /JS (x) >>\nendstream\nendobj"),
    'שם עם #xx' => pdf('5 0 obj << /S /#4AavaScript /J#53 (x) >> endobj'),
    'שרשרת פילטרים' => pdf("5 0 obj\n<< /Type /ObjStm /Filter [/ASCIIHexDecode /FlateDecode] >>\nstream\n" . $hex(gzcompress('6 0 << /JS (x) >>')) . "\nendstream\nendobj"),
    'קיצור של פילטר' => pdf("5 0 obj\n<< /Filter [/AHx /Fl] >>\nstream\n" . $hex(gzcompress('x')) . "\nendstream\nendobj"),
    'OpenAction עם URI' => pdf('5 0 obj << /Type /Catalog /OpenAction << /S /URI /URI (http://evil.example/) >> >> endobj'),
    'OpenAction בהפניה' => pdf('5 0 obj << /Type /Catalog /OpenAction 6 0 R >> endobj'),
    'OpenAction עם הערה לפני הערך' => pdf("5 0 obj << /OpenAction %x\n << /S /GoTo >> >> endobj"),
    'AA' => pdf('5 0 obj << /Type /Page /AA << /O 6 0 R >> >> endobj'),
    'SubmitForm' => pdf('5 0 obj << /S /SubmitForm /F (http://evil.example/) >> endobj'),
    'XFA' => pdf('5 0 obj << /AcroForm << /XFA 6 0 R >> >> endobj'),
    'Launch' => pdf('5 0 obj << /S /Launch /Win << /F (cmd.exe) >> >> endobj'),
    'JS בזרם Flate רגיל' => pdf("5 0 obj\n<< /Filter /FlateDecode >>\nstream\n" . gzcompress(str_repeat('q ', 5000) . '<< /S /JavaScript /JS (x) >>') . "\nendstream\nendobj"),
    'JS עם הערה לפני הערך' => pdf("5 0 obj << /JS %c\n (x) >> endobj"),
    'RichMedia' => pdf('5 0 obj << /Subtype /RichMedia >> endobj'),
];
$got = array_map(fn($b) => code(up($avi, $b, ['case' => $C])), $evasions);
eq([array_keys(array_filter($got, fn($c) => $c !== 'file_active')), $nfiles() - $n], [[], 0], '06-B2 (סבב 2) כל עקיפה שנמנתה בביקורת נדחית');
$benign = ['יעד פתיחה (מערך)' => pdf('5 0 obj << /Type /Catalog /OpenAction [3 0 R /Fit] >> endobj', 'b1'), 'קישור רגיל' => pdf('5 0 obj << /Type /Annot /Subtype /Link /A << /S /URI /URI (https://example.org/JS/AA) >> >> endobj', 'b2'),
    'זרם Flate תמים' => pdf("5 0 obj\n<< /Filter /FlateDecode >>\nstream\n" . gzcompress(str_repeat('BT (JS AA text) Tj ET ', 3000)) . "\nendstream\nendobj", 'b3')];
eq(array_map(fn($b) => up($avi, $b, ['case' => $D])[0], $benign), array_fill_keys(array_keys($benign), 200), 'PDF תמים עם יעד פתיחה, קישור וזרם דחוס — מתקבל');
// 🔴1: זמן ליניארי. הקלט מהביקורת — אלפי זרמים קטנים — ועוד שניים שמנסים לגרום לפריסה חוזרת
$t0 = microtime(true);
$tmpf = tempnam(apr_upload_tmp_dir(), 'dos');
foreach (["1 0 obj\n<< /Length 1 >>\nstream\nx\nendstream\nendobj\n", "stream\n\x78\x9c" . 'aaaa', "stream\n" . substr(gzcompress(str_repeat('A', 3_000_000)), 0, 40)] as $unit) {
    file_put_contents($tmpf, '%PDF-1.4' . "\n" . str_repeat($unit, intdiv(4 << 20, strlen($unit))) . "\n%%EOF\n");
    try { apr_file_sniff($tmpf); } catch (AprFileRejected) {}
}
file_put_contents($tmpf, '%PDF-1.4' . "\n" . str_repeat("stream\n" . gzcompress(str_repeat("\0", 20_000_000)) . "\n", 12) . "%%EOF\n");   // "פצצה": 240MB אחרי פריסה
try { apr_file_sniff($tmpf); } catch (AprFileRejected) {}
unlink($tmpf);
$took = microtime(true) - $t0;
check('🔴1 בדיקת PDF ליניארית: ארבעה קלטים עוינים של 4MB (אלפי זרמים, פריסה חוזרת, פצצת דחיסה) — פחות מ-6 שניות יחד', $took < 6.0, round($took, 2));

// 🟡 (ביקורת 06, אחרי המיזוג): הסריקה אינה נעצרת בשקט. מאות זרמים ריקים ואחריהם JS; ריפוד של 2.5MB בתוך זרם אחד; שם שנחתך בגבול חלון
$sn = function (string $body): string {
    $f = tempnam(apr_upload_tmp_dir(), 'pb');
    file_put_contents($f, '%PDF-1.4' . "\n" . $body . "\n%%EOF\n");
    try { apr_file_sniff($f); $r = 'ok'; } catch (AprFileRejected $e) { $r = (string)$e->why; } finally { unlink($f); }
    return $r;
};
$empty = "5 0 obj\n<< /Filter /FlateDecode >>\nstream\n" . gzcompress('') . "\nendstream\nendobj\n";
$js = "stream\n" . gzcompress('<< /S /Foo /JS (app.alert(1)) >>') . "\nendstream\n";
eq([$sn(str_repeat($empty, 800) . $js), $sn(str_repeat($empty, 5000) . $js), $sn(str_repeat($empty, 5000))], ['pdf:JS', 'pdf:JS', 'ok'], '🟡 800 ו-5,000 זרמים ריקים ואחריהם זרם עם JS ← נדחה; בלי ה-JS ← מתקבל');
mt_srand(7);
$noise = ''; for ($i = 0; $i < 2_600_000; $i++) $noise .= chr(97 + mt_rand(0, 25));      // אותיות אקראיות: נפרסות בנתחים קטנים, כמו זרם אמיתי
eq([$sn("stream\n" . gzcompress($noise . '<< /JS (x) >>') . "\n"), $sn("stream\n" . gzcompress($noise) . "\n")], ['pdf:JS', 'ok'], '🟡 JS אחרי 2.6MB של ריפוד באותו זרם ← נדחה (אין תקרה שקטה לזרם)');
$gap = fn(string $g) => $sn("stream\n" . gzcompress(substr($noise, 0, 700_000) . '/JS' . $g . '(x)' . substr($noise, 0, 900_000)) . "\n");
eq([$gap("%" . substr($noise, 0, 600_000) . "\n"), $gap("%" . substr($noise, 0, 1_400_000) . "\n"), $sn("stream\n" . gzcompress(substr($noise, 0, 700_000) . '/JS' . "%" . substr($noise, 0, 600_000) . "\n" . 'x') . "\n")],
    ['pdf:JS', 'pdf:gap', 'ok'], '🟡 שם שהערה ארוכה מפרידה בינו לערך, על פני גבול חלון הסריקה ← נדחה; בלי ערך ← מתקבל');
$tail = function (string $w): string { try { return (string)strlen(apr_pdf_tail($w)); } catch (AprFileRejected $e) { return (string)$e->why; } };
eq([$tail(str_repeat('q', 9000)), $tail(str_repeat('q', 9000) . '/J#53' . str_repeat(' ', 7000)), $tail(str_repeat('q', 9000) . '/JS' . str_repeat(' ', 7000) . 'x'), $tail('/AA' . str_repeat("\n", (1 << 20)))],
    ['4096', '7005', '4096', 'pdf:gap'], '🟡 הזנב שעובר לחלון הבא: 4KB, או מהשם שבסוף החלון; רווח של יותר מ-1MB אחרי שם ← נדחה');
$GLOBALS['apr_pdf_budget'] = 3_000_000;
$mid = "stream\n" . gzcompress(str_repeat('BT (x) Tj ET ', 40_000)) . "\nendstream\n";       // ~520KB אחרי פריסה
eq([$sn(str_repeat($mid, 4)), $sn(str_repeat($mid, 8)), $sn(str_repeat($mid, 8) . $js), $sn(str_repeat($empty, 2800)), $sn(str_repeat($empty, 3100))], ['ok', 'pdf:budget', 'pdf:budget', 'ok', 'pdf:budget'],
    '🟡 התקציב נמדד במה שנפרס בפועל (+1KB לזרם), ומי שחורג ממנו נדחה (pdf:budget) — לא מתקבל בלי סריקה');
unset($GLOBALS['apr_pdf_budget']);
$t0 = microtime(true);
eq($sn(str_repeat("5 0 obj\n<< /Filter /FlateDecode >>\nstream\n" . gzcompress(str_repeat('BT /F1 12 Tf (hello) Tj ET ', 150)) . "\nendstream\nendobj\n", 20_000)), 'ok', '🟡 PDF רגיל עם 20,000 זרמים קטנים — נסרק עד הסוף ומתקבל');
check('🟡 … ובפחות משתי שניות', microtime(true) - $t0 < 2.0, round(microtime(true) - $t0, 2));
$n = $nfiles();
eq([code(up($avi, '%PDF-1.4' . "\n" . str_repeat($empty, 800) . $js . "%%EOF\n", ['case' => $C])), $nfiles() - $n], ['file_active', 0], '🟡 … וגם דרך file-upload: נדחה, ואין קובץ');

// ── ביקורת 07, 🟡2 + 🟡3: הסורק נגיש עכשיו לנמען אנונימי ──
$jsObj = '<< /S /JavaScript /JS (app.alert(1)) >>';
$z9 = function (string $d, int $wbits): string { $c = deflate_init(ZLIB_ENCODING_DEFLATE, ['window' => $wbits]); return deflate_add($c, $d, ZLIB_FINISH); };
$small = $z9($jsObj, 9);
eq([ord($small[0]) !== 0x78, $sn("stream\n" . $small . "\nendstream"), $sn("stream \n" . gzcompress($jsObj) . "\nendstream"), $sn("stream\t \r\n" . gzcompress($jsObj) . "\nendstream"),
    $sn("stream\n" . gzcompress(gzcompress($jsObj)) . "\nendstream"), $sn("5 0 obj << /Filter [/FlateDecode /FlateDecode] >> endobj"), $sn("5 0 obj << /Filter [ /Fl /DCTDecode /F#6c ] >> endobj"),
    $sn("stream\n" . gzcompress(gzcompress(str_repeat('q ', 3_000_000) . $jsObj)) . "\nendstream"), $sn("stream \n" . $z9(str_repeat('BT (x) Tj ET ', 500), 10) . "\nendstream"), $sn("5 0 obj << /Filter [/FlateDecode /DCTDecode] >> endobj")],
    [true, 'pdf:JavaScript', 'pdf:JavaScript', 'pdf:JavaScript', 'pdf:nested', 'pdf:Fl' . 'ateDecode', 'pdf:Fl', 'pdf:nested', 'ok', 'ok'],
    '🟡3 JavaScript בזרם Flate עם חלון zlib קטן, עם רווח אחרי stream, או ב-Flate כפול ← נדחה; זרם תמים באותן צורות ← מתקבל');
$ref = fn(int $before, string $gap) => $sn("stream\n" . gzcompress(substr($noise, 0, $before) . '/JS' . $gap . '12 0 R ' . substr($noise, 0, 900_000)) . "\n");
eq([$ref(900_000, str_repeat(' ', 200_000)), $ref(1_048_560, ' '), $ref(1_048_565, '   '), $tail(str_repeat('q', 9000) . '/JS  ' . str_repeat(' ', 6000) . '12 0'), $tail(str_repeat('q', 9000) . '/JS 12 ' . str_repeat(' ', 6000))],
    ['pdf:JS', 'pdf:JS', 'pdf:JS', '6009', '6007'], '🟡3 ‎/JS + רווחים + הפניה (12 0 R) על גבול חלון הסריקה ← נדחה: הזנב כולל הפניה שנחתכה');
$bombFile = tempnam(apr_upload_tmp_dir(), 'bm');
file_put_contents($bombFile, '%PDF-1.4' . "\nstream\n" . gzcompress(str_repeat("\0", 900_000_000 > 0 ? 400_000_000 : 0), 9) . "\nendstream\n%%EOF\n");
$child = 'require ' . var_export(dirname(__DIR__) . '/lib/errors.php', true) . '; require ' . var_export(dirname(__DIR__) . '/lib/files.php', true) . '; $GLOBALS["apr_pdf_budget"] = 2000000000;'
    . ' try { apr_file_sniff($argv[1]); echo "ok ", memory_get_peak_usage() >> 20; } catch (AprFileRejected $e) { echo $e->why, " ", memory_get_peak_usage() >> 20; }';
$outB = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=48M -r ' . escapeshellarg($child) . ' ' . escapeshellarg($bombFile) . ' 2>&1');
check('🟡2 פצצת דחיסה (פחות מ-1MB שנפרס ל-400MB) תחת memory_limit=48M: נסרקת עד הסוף בלי להפיל את PHP', preg_match('/^ok \d+$/', $outB) === 1 && filesize($bombFile) < 1_000_000, [$outB, filesize($bombFile)]);
unlink($bombFile);
eq([code(up($avi, '%PDF-1.4' . "\nstream\n" . gzcompress(gzcompress('x')) . "\nendstream\n%%EOF\n", ['case' => $C])), APR_ERRORS['file_unscannable'][0]], ['file_unscannable', 415], 'קובץ שאי אפשר לבדוק נדחה בנוסח משלו ("לא ניתן לבדוק"), לא כ"תוכן פעיל"');

// בלי .user.ini משלנו: התקרה נגזרת מ-post_max_size של השרת, פחות מרווח — והמסך, ההודעה ובדיקת התקינות אומרים את האמת
$GLOBALS['apr_post_max'] = 8 << 20;
$h8 = apr_health()['checks'] ?? [];
$h8 = array_values(array_filter($h8, fn($c) => ($c['id'] ?? '') === 'files'))[0] ?? [];
eq([apr_file_max(), $docs($avi, $C)[1]['limits']['max_size'], $h8['state'] ?? null, str_contains($h8['detail'] ?? '', 'post_max_size=') && str_contains($h8['detail'] ?? '', 'memory_limit=') && str_contains($h8['detail'] ?? '', 'קובץ עד 7MB')],
    [(8 << 20) - 65536, (8 << 20) - 65536, 'warn', true], 'שרת עם post_max_size = 8M ← התקרה 8MB פחות 64KB, המסך מקבל אותה, ובדיקת התקינות מדווחת את ערכי השרת בפועל');
$GLOBALS['apr_post_max'] = 64 << 20;
$big8 = [apr_file_max()];
$GLOBALS['apr_post_max'] = 0;
$big8[] = apr_file_max();
unset($GLOBALS['apr_post_max']);
eq([$big8, is_file(dirname(__DIR__) . '/.user.ini')], [[APR_FILE_MAX, APR_FILE_MAX], false], 'שרת נדיב או בלי הגבלה ← 25MB; ואין .user.ini בתיקיית האפליקציה (השער יוצר אותו בפריסה)');

// ── 06-B3: גודל ──
$n = $nfiles(); $d = $disk();
$GLOBALS['apr_file_max'] = 400;
$big = up($avi, pdf(str_repeat('% padding ', 100)), ['case' => $C, 'requirement' => $R['plan']]);
unset($GLOBALS['apr_file_max']);
$pre = up($avi, pdf(), ['case' => $C], ['upload_error' => 'file_too_large']);
eq([$big[0], code($big), code($pre), $nfiles() - $n, $disk() - $d, $rrow($R['plan'])['status'], is_file($GLOBALS['t_tmp'])], [413, 'file_too_large', 'file_too_large', 0, 0, 'required', false], '06-B3 קובץ מעל המגבלה ← נדחה לפני שנשמר');
check('06-B3 ההודעה נושאת את הגודל המותר', str_contains($pre[1]['error']['message'], intdiv(apr_file_max(), 1048576) . 'MB') && apr_file_max() <= APR_FILE_MAX && APR_FILE_MAX === 25 * 1024 * 1024, $pre[1]['error']['message']);
eq([code(call('POST', 'file-upload', ['case' => $C], $avi)), code(call('POST', 'file-upload', ['case' => $C], $avi, [], ['env' => ['upload' => '/nonexistent/x']])), code(up($avi, pdf(), ['case' => $C], ['upload_error' => 'storage_full']))],
    ['file_missing', 'file_missing', 'storage_full'], 'בלי קובץ ← file_missing; אין מקום בדיסק (מקרה 10) ← הודעה ברורה');

// ── 🔴2: גוף הבקשה נקרא רק אחרי זהות, CSRF, הרשאה בתיק ומכסה ──
$read = 0;
$reader = function () use (&$read) { $read++; $t = tempnam(apr_upload_tmp_dir(), 'up'); file_put_contents($t, pdf('', 'reader' . $read)); return ['upload' => $t]; };
$via = fn(?string $as, array $q, array $opt = []) => call('POST', 'file-upload', $q + ['name' => 'r.pdf'], $as, [], $opt + ['env' => ['upload_reader' => $reader]]);
$n = $nfiles();
$tries = [$via(null, ['case' => $C])[0], $via($avi, ['case' => $C], ['csrf' => 'wrong'])[0], code($via($boaz, ['case' => $C])), code($via($gil, ['case' => $C])), code($via($ron, ['case' => $C])),
    code($via($avi, ['case' => 'zzzzzzzzzzzzzzzz'])), code($via($avi, ['case' => ['x']]))];
eq([$tries, $read, $nfiles() - $n], [[401, 403, 'not_found', 'not_found', 'forbidden', 'not_found', 'not_found'], 0, 0], '🔴2 בלי זהות, בלי CSRF, משרד אחר, לא משתתף, בלי documents, תיק שלא קיים — הגוף לא נקרא כלל');
$GLOBALS['apr_quota'] = [(int)apr_val('SELECT SUM(size) FROM files WHERE case_id = ?', [$crow($C)['id']]), PHP_INT_MAX];
$qc = code($via($avi, ['case' => $C]));
$GLOBALS['apr_quota'] = [PHP_INT_MAX, (int)apr_val('SELECT SUM(size) FROM files')];
$qo = code($via($avi, ['case' => $C]));
$GLOBALS['apr_quota'] = [(int)apr_val('SELECT SUM(size) FROM files WHERE case_id = ?', [$crow($C)['id']]) + 50, PHP_INT_MAX];
$qs = [code($via($avi, ['case' => $C])), $read];        // יש מקום לבית אחד, אבל לא לקובץ: הגוף נקרא, והקובץ נדחה
unset($GLOBALS['apr_quota']);
eq([$qc, $qo, $qs, $nfiles() - $n, $disk() >= 0], ['quota_case', 'quota_office', ['quota_case', 1], 0, true], '🟡4 תקרת נפח לתיק ולמשרד: מלא ← נדחה לפני קריאת הגוף; קובץ שחורג ← נדחה ולא נשמר');
$GLOBALS['apr_upload_rate'] = [(int)apr_val("SELECT count FROM login_attempts WHERE key = ?", ['upload:' . $crow($C)['responsible_user_id']]) + 1, 600];
$ra = $via($avi, ['case' => $C]);
$rb = $via($avi, ['case' => $C]);
$rc = code($via($tal, ['case' => $C, 'dup' => '1']));
$GLOBALS['apr_time'] = time() + 601;
$rd = $via($avi, ['case' => $C, 'dup' => '1'])[0];
unset($GLOBALS['apr_time']);
$GLOBALS['apr_upload_rate'] = [100000, 600];
eq([$ra[0], $rb[0], code($rb), $rc, $rd, $read], [200, 429, 'upload_rate', null, 200, 4], '🟡4 הגבלת קצב למשתמש: מעבר לתקרה ← 429 בלי לקרוא את הגוף; משתמש אחר אינו מושפע; אחרי החלון — שוב מותר');
// 🟡6: הטרנזקציה נכשלת אחרי שהקובץ כבר נשמר לדיסק — לא נשאר קובץ יתום
$n = $nfiles(); $d = $disk();
$GLOBALS['apr_after_store'] = function () { throw new AprError('storage'); };
$boom = up($avi, pdf('', 'orphan'), ['case' => $C, 'requirement' => $R['plan']]);
unset($GLOBALS['apr_after_store']);
eq([code($boom), $nfiles() - $n, $disk() - $d, $rrow($R['plan'])['status'], is_file($GLOBALS['t_tmp']), count(glob(apr_upload_tmp_dir() . '/*') ?: [])], ['storage', 0, 0, 'required', false, 0], '🟡6 כישלון בתוך הטרנזקציה אחרי השמירה לדיסק ← אין שורה, אין קובץ יתום, אין קובץ זמני');

// ── 06-B4: כפילות באותו תיק ──
$n = $nfiles(); $d = $disk();
$dup = up($tal, pdf(), ['case' => $C, 'requirement' => $R['plan'], 'name' => 'שוב.pdf']);
eq([$dup[0], $dup[1]['duplicate']['file'] ?? null, $dup[1]['duplicate']['name'] ?? null, $dup[1]['duplicate']['requirement'] ?? null, isset($dup[1]['file']), $nfiles() - $n, $disk() - $d, $rrow($R['plan'])['status'], $frow($F1)['deleted_at']],
    [200, $F1, 'נסח טאבו.pdf', 'נסח טאבו', false, 0, 0, 'required', null], '06-B4 אותו קובץ פעמיים באותו תיק ← duplicate, מחכה להחלטה; שום קובץ לא נוצר ולא נמחק');
$dup2 = up($tal, pdf(), ['case' => $C, 'requirement' => $R['plan'], 'name' => 'שוב.pdf', 'dup' => '1']);
eq([$dup2[0], isset($dup2[1]['duplicate']), $nfiles() - $n, $rrow($R['plan'])['status'], $frow($F1)['deleted_at'], $frow($dup2[1]['file'])['sha256'] === $frow($F1)['sha256']], [200, false, 1, 'received', null, true], '06-B4 "להעלות בכל זאת" ← נשמר; הקובץ הקודם לא נגע');
$Lplan = $dup2[1]['link'];

// ── 06-B5: אותו קובץ בתיק אחר ──
$other = up($avi, pdf(), ['case' => $D, 'requirement' => $RD['rights']]);
eq([$other[0], isset($other[1]['duplicate']), isset($other[1]['file'])], [200, false, true], '06-B5 אותו קובץ בתיק אחר ← אין התרעה');

// ── 06-B6: אותו מזהה פעולה ──
$n = $nfiles(); $d = $disk();
$k1 = up($tal, pdf('', 'idem'), ['case' => $C, 'key' => 'op-abc12345']);
$k2 = up($tal, pdf('', 'idem'), ['case' => $C, 'key' => 'op-abc12345']);
$k3 = up($tal, pdf('', 'idem-other-content'), ['case' => $C, 'key' => 'op-abc12345']);
eq([$k1[0], $k2[0], $k1[1]['file'] === $k2[1]['file'], $k3[1]['file'] === $k1[1]['file'], $k2[1]['repeat'] ?? null, $nfiles() - $n, $disk() - $d, isset($k2[1]['duplicate'])], [200, 200, true, true, true, 1, 1, false], '06-B6 העלאה חוזרת עם אותו מזהה פעולה ← שורה אחת');
eq([code(up($tal, pdf('', 'k'), ['case' => $C, 'key' => 'short'])), code(up($tal, pdf('', 'k'), ['case' => $C, 'key' => "op-abc12345\n"])), code(up($avi, pdf('', 'idem'), ['case' => $D, 'key' => 'op-abc12345', 'dup' => '1'])),
    up($dana, pdf('', 'idem-dana'), ['case' => $C, 'key' => 'op-abc12345'])[1]['file'] !== $k1[1]['file']], ['content_invalid', 'content_invalid', null, true], '06-B6 מזהה פגום נדחה (גם עם שורה חדשה בסופו); מזהה של משתמש אחר אינו מתנגש');
$Funassigned = $k1[1]['file'];
$kx = up($avi, pdf('', 'key-c'), ['case' => $C, 'key' => 'op-crosscase1']);
$n = $nfiles();
eq([$kx[0], code(up($avi, pdf('', 'key-d'), ['case' => $D, 'key' => 'op-crosscase1'])), $nfiles() - $n],
    [200, 'content_invalid', 0], '06-B6 אותו מזהה פעולה בתיק אחר אינו מחזיר את הקובץ של התיק הראשון');

// ── 06-B7: שם הקובץ אינו נתיב ──
$out = dirname(apr_data_dir());
$r = up($tal, pdf('', 'trav'), ['case' => $C, 'name' => str_repeat('../', 3) . 'evil.php']);
$f = $frow($r[1]['file']);
$real = realpath(apr_files_dir() . '/' . $f['storage_key']);
eq([preg_match('~^[a-f0-9]{2}/[a-f0-9]{32}\z~', $f['storage_key']), str_starts_with((string)$real, realpath(apr_files_dir()) . '/'), $f['original_name'], str_contains($f['storage_key'], 'evil'), pathinfo($f['storage_key'], PATHINFO_EXTENSION)],
    [1, true, 'evil.php', false, ''], '06-B7 storage_key אקראי, בלי סיומת, ואינו נגזר מהשם; ../ אינו יוצא מ-data/files');
check('06-B7 שום קובץ לא נוצר מחוץ לתיקיית הקבצים', !glob("$out/evil*") && !glob(apr_data_dir() . '/evil*') && !glob(sys_get_temp_dir() . '/evil.php'));
$r = up($tal, pdf('', 'name2'), ['case' => $C, 'name' => "..\\..\\a\x00b\x1F\u{202E}gpj.exe" . str_repeat('ש', 300)]);
$nm = $frow($r[1]['file'])['original_name'];
eq([mb_strlen($nm), preg_match('/[\x00-\x1F\x{202E}\\\\\/]/u', $nm), str_starts_with($nm, 'abgpj.exe')], [150, 0, true], 'מקרה 11: תווי בקרה, תו היפוך כיוון ונתיב מנוקים; עד 150 תווים');
eq([$frow(up($tal, pdf('', 'name3'), ['case' => $C, 'name' => ['a']])[1]['file'])['original_name'], $frow(up($tal, pdf('', 'name4'), ['case' => $C, 'name' => '...'])[1]['file'])['original_name']], ['קובץ', 'קובץ'], 'שם חסר או ריק ← "קובץ"');
foreach (['data', 'files'] as $k) eq(code(call('GET', 'file', [], $avi, ['file' => '../' . $f['pid']])), 'not_found', "מזהה קובץ עם נתיב ← 404 ($k)");
$thrown = null;
try { apr_file_path(str_repeat('../', 2) . 'etc/passwd'); } catch (AprError $e) { $thrown = $e->errorCode; }
eq($thrown, 'not_found', '06-B7 מפתח אחסון שאינו בצורה שהשרת יוצר ← לא נמצא');

// ── 06-C3: כותרות ההגשה ──
$dl = call('GET', 'file', [], $ron, ['file' => $F1])[1]['_file'];
$h = $dl['headers'];
eq([$h['X-Content-Type-Options'], str_starts_with($h['Content-Disposition'], 'attachment; filename="'), str_contains($h['Content-Disposition'], "filename*=UTF-8''" . rawurlencode('נסח טאבו.pdf')), $h['Content-Type'], $h['Content-Length'],
    file_get_contents($dl['path']) === pdf(), str_starts_with($h['Content-Security-Policy'], 'sandbox')], ['nosniff', true, true, 'application/pdf', (string)strlen(pdf()), true, true], '06-C3 הורדה: nosniff, Content-Disposition: attachment, sandbox — גם למשתתף עם צפייה בלבד');
$pv = call('GET', 'file', [], $avi, ['file' => $F1, 'inline' => '1'])[1]['_file']['headers'];
eq([str_starts_with($pv['Content-Disposition'], 'inline; '), str_starts_with($pv['Content-Security-Policy'], 'sandbox'), $pv['X-Content-Type-Options']], [true, true, 'nosniff'], '06-C3 תצוגה מקדימה: inline עם sandbox ו-nosniff');
$dx = call('GET', 'file', [], $avi, ['file' => $r1[1]['file'], 'inline' => '1'])[1]['_file']['headers'];
$ev = call('GET', 'file', [], $avi, ['file' => $f['pid'], 'inline' => ['1']])[1]['_file']['headers'];
eq([str_starts_with($dx['Content-Disposition'], 'attachment'), str_contains($ev['Content-Disposition'], 'filename="evil.pdf"'), str_starts_with($ev['Content-Disposition'], 'attachment')], [true, true, true],
    '06-C3 DOCX אינו מוצג inline לעולם; שם ההורדה מקבל את הסיומת של הסוג שזוהה (evil.php ← evil.pdf)');
$th = call('GET', 'file-thumb', [], $ron, ['file' => apr_val('SELECT f.pid FROM files f JOIN requirement_files rf ON rf.file_id = f.id WHERE rf.pid = ?', [$Lid])]);
$ti = @getimagesize($th[1]['_file']['path'] ?? '');
eq([$th[0], $th[1]['_file']['headers']['Content-Type'] ?? null, $th[1]['_file']['headers']['X-Content-Type-Options'] ?? null, $ti[2] ?? null, str_starts_with(realpath($th[1]['_file']['path']), realpath(apr_data_dir()) . '/thumbs/'),
    code(call('GET', 'file-thumb', [], $avi, ['file' => $F1])), (int)apr_val('SELECT COUNT(*) FROM file_thumbs')], [200, 'image/jpeg', 'nosniff', IMAGETYPE_JPEG, true, 'not_found', 1], 'תמונה ממוזערת: JPEG שנוצר בשרת, נשמר כמטמון; ל-PDF — אין (אייקון)');

// ── 06-C1: בידוד ──
$linkD = up($avi, pdf('', 'd-only'), ['case' => $D, 'requirement' => $RD['contract']])[1];
$probe = function (string $as, string $case, string $file, string $link, string $reqPid) {
    $out = [];
    foreach ([['GET', 'case-docs', ['case' => $case]], ['GET', 'file', ['file' => $file]], ['GET', 'file', ['file' => $file, 'inline' => '1']], ['GET', 'file-thumb', ['file' => $file]],
        ['POST', 'file-update', ['file' => $file, 'name' => 'x']], ['POST', 'file-delete', ['file' => $file, 'reason' => 'x']], ['POST', 'file-assign', ['file' => $file, 'requirement' => $reqPid]],
        ['POST', 'file-review', ['link' => $link, 'do' => 'reject', 'reason' => 'x']], ['POST', 'requirement-status', ['requirement' => $reqPid, 'status' => 'not_relevant', 'reason' => 'x']],
        ['POST', 'requirement-split', ['requirement' => $reqPid, 'parts' => []]], ['POST', 'requirement-order', ['case' => $case, 'items' => [['requirement' => $reqPid]]]],
        ['POST', 'requirement-update', ['requirement' => $reqPid, 'title' => 'x']], ['POST', 'requirement-add', ['case' => $case, 'title' => 'x']]] as [$m, $a, $p]) {
        $r = $m === 'GET' ? call('GET', $a, [], $as, $p) : call('POST', $a, $p, $as);
        $out[$a . (isset($p['inline']) ? '-inline' : '')] = [$r[0], $r[1]['error']['code'] ?? null, $r[1]['error']['message'] ?? null];
    }
    $r = up($as, pdf('', 'probe'), ['case' => $case, 'requirement' => $reqPid]);
    $out['file-upload'] = [$r[0], $r[1]['error']['code'] ?? null, $r[1]['error']['message'] ?? null];
    return $out;
};
$before = [$nfiles(), $disk(), apr_val('SELECT COUNT(*) FROM audit_log')];
$ghost = $probe($avi, 'aaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbb', 'cccccccccccccccc', 'dddddddddddddddd');
eq(array_values(array_unique(array_map(fn($x) => $x[0], $ghost))), [404], 'מזהים שלא קיימים ← 404 בכל הפעולות');
foreach (['משרד אחר' => $boaz, 'חבר משרד שאינו משתתף' => $gil, 'מנהל המערכת' => $owner] as $who => $as) {
    $got = $probe($as, $D, $linkD['file'], $linkD['link'], $RD['contract']);
    check("06-C1 $who: הורדה, תצוגה מקדימה, תמונה ממוזערת וכל פעולה אחרת ← " . ($who === 'מנהל המערכת' ? 'אין גישה' : '404'), $who === 'מנהל המערכת' ? array_values(array_unique(array_map(fn($x) => $x[1], $got))) === ['no_office'] : $got === $ghost, $got);
}
// דנה משתתפת ב-C ולא ב-D: מזהי בן של D דרך C
$got = $probe($dana, $D, $linkD['file'], $linkD['link'], $RD['contract']);
check('06-C1 משתתפת בתיק אחר באותו משרד ← אותו 404', $got === $ghost, $got);
eq([$nfiles(), $disk(), apr_val('SELECT COUNT(*) FROM audit_log')], $before, '06-C1 ושום דבר לא נכתב: לא קובץ, לא שורה, לא יומן');
eq([code(call('POST', 'file-assign', ['file' => $linkD['file'], 'requirement' => $R['plan']], $avi)), code(call('POST', 'file-assign', ['file' => $F1, 'requirement' => $RD['plan']], $avi)),
    code(up($avi, pdf('', 'x-case'), ['case' => $C, 'requirement' => $RD['plan']])), code(call('POST', 'requirement-order', ['case' => $C, 'items' => [['requirement' => $R['plan']], ['requirement' => $RD['plan']]]], $avi)),
    code(call('GET', 'file', [], $dana, ['file' => $linkD['file']])), $rrow($RD['plan'])['status'], (int)apr_val('SELECT COUNT(*) FROM requirement_files WHERE file_id = ?', [$frow($linkD['file'])['id']])],
    ['not_found', 'not_found', 'not_found', 'not_found', 'not_found', 'required', 1], '06-C1 מזהה בן של תיק אחר (קובץ, דרישה) ← 404 גם למי שמשתתף בשני התיקים');
$sortBefore = (int)$rrow($R['plan'])['sort'];
call('POST', 'requirement-order', ['case' => $C, 'items' => [['requirement' => $R['id']], ['requirement' => $RD['plan']]]], $avi);
eq((int)$rrow($R['plan'])['sort'], $sortBefore, 'סידור שנכשל באמצע אינו משנה דבר (טרנזקציה אחת)');

// ── 06-C2: data/ אינה מוגשת ──
$ht = (string)file_get_contents(dirname(__DIR__) . '/data/.htaccess');
check('06-C2 הקבצים יושבים רק תחת data/, ו-data/.htaccess חוסם כל בקשה ישירה', str_starts_with(apr_files_dir(), apr_data_dir() . '/') && str_starts_with(apr_upload_tmp_dir(), apr_data_dir() . '/')
    && str_contains($ht, 'Require all denied') && str_contains($ht, 'Deny from all'));

// ── הרשאות ──
eq([code(call('POST', 'file-review', ['link' => $Lplan, 'do' => 'approve', 'chk_readable' => true, 'chk_complete' => true, 'chk_matches' => true], $tal)), code(call('POST', 'file-review', ['link' => $Lplan, 'do' => 'return', 'return_code' => 'unreadable'], $tal)),
    code(call('POST', 'file-review', ['link' => $Lplan, 'do' => 'reject', 'reason' => 'x'], $tal)), code(call('POST', 'file-delete', ['file' => $F1, 'reason' => 'x'], $tal)), $lrow($Lplan)['review_state'], $frow($F1)['deleted_at']],
    ['forbidden', 'forbidden', 'forbidden', 'forbidden', 'pending', null], 'בלי review_docs: אין אישור, החזרה, דחייה או מחיקה');
eq([code(up($ron, pdf('', 'ron'), ['case' => $C])), code(call('POST', 'file-assign', ['file' => $Funassigned, 'requirement' => $R['plan']], $ron)), code(call('POST', 'file-update', ['file' => $F1, 'name' => 'x'], $ron)),
    code(call('POST', 'requirement-order', ['case' => $C, 'items' => [['requirement' => $R['plan']]]], $ron)), code(call('POST', 'requirement-split', ['requirement' => $R['plan'], 'parts' => []], $ron)),
    code(call('POST', 'requirement-add', ['case' => $C, 'title' => 'x'], $ron)), $docs($ron, $C)[0], call('GET', 'file', [], $ron, ['file' => $F1])[0]],
    ['forbidden', 'forbidden', 'forbidden', 'forbidden', 'forbidden', 'forbidden', 200, 200], 'בלי documents: אין העלאה, שיוך, עריכה, סדר, פיצול או הוספה — צפייה והורדה כן');
// הורדת "חובה"
eq([code(call('POST', 'requirement-update', ['requirement' => $R['rights'], 'mandatory' => false, 'reason' => 'x'], $tal)), code(call('POST', 'requirement-update', ['requirement' => $R['rights'], 'mandatory' => false], $dana)),
    (int)$rrow($R['rights'])['mandatory'], call('POST', 'requirement-update', ['requirement' => $R['plan'], 'mandatory' => true], $tal)[0], call('POST', 'requirement-update', ['requirement' => $R['plan'], 'mandatory' => false, 'reason' => 'טעות'], $dana)[0], (int)$rrow($R['plan'])['mandatory']],
    ['forbidden', 'reason_required', 1, 200, 200, 0], 'הורדת "חובה" — רק review_docs ועם נימוק; העלאה ל"חובה" — documents');

// ── 06-D2 / מקרה 9: אותו קובץ בשתי דרישות ──
$as1 = call('POST', 'file-assign', ['file' => $F1, 'requirement' => $R['id']], $tal);
eq([$as1[0], $as1[1]['version'] ?? null, $rrow($R['id'])['status'], code(call('POST', 'file-assign', ['file' => $F1, 'requirement' => $R['id']], $tal)), (int)apr_val("SELECT COUNT(*) FROM requirement_files WHERE file_id = ? AND review_state <> 'rejected'", [$frow($F1)['id']])],
    [200, 2, 'received', 'file_linked', 2], '06-D2 אותו קובץ בשתי דרישות ← שני שיוכים; שיוך כפול לאותה דרישה נדחה');
$approve($dana, $as1[1]['link']);
eq([$lrow($as1[1]['link'])['review_state'], $lrow($Lid)['review_state'], $lrow($L1)['review_state'], $rrow($R['id'])['status'], $rrow($R['rights'])['status']], ['approved', 'pending', 'approved', 'approved', 'approved'], '06-D2 לכל שיוך בדיקה משלו');
call('POST', 'requirement-status', ['requirement' => $R['rights'], 'status' => 'received', 'reason' => 'בדיקה מחדש'], $dana);
eq([$lrow($L1)['review_state'], $lrow($as1[1]['link'])['review_state'], $rrow($R['id'])['status']], ['pending', 'approved', 'approved'], '06-D2 פתיחה מחדש בדרישה אחת אינה נוגעת בבדיקה של השנייה; אישור באחת אינו מאשר בשנייה');
$approve($dana, $L1);

// ── דחייה ──
eq([code(call('POST', 'file-review', ['link' => $Lplan, 'do' => 'reject'], $dana)), $lrow($Lplan)['review_state']], ['reason_required', 'pending'], 'דחייה בלי נימוק ← נדחית');
$rj = call('POST', 'file-review', ['link' => $Lplan, 'do' => 'reject', 'reason' => 'מסמך של נכס אחר'], $dana);
$un = array_column($docs($avi, $C)[1]['unassigned'], 'pid');
eq([$rj[0], $rj[1]['status'], $rrow($R['plan'])['status'], $lrow($Lplan)['review_state'], in_array($dup2[1]['file'], $un, true), in_array($Funassigned, $un, true), in_array($F1, $un, true)],
    [200, 'required', 'required', 'rejected', true, true, false], 'דחייה: הקובץ מנותק ונשאר ב"קבצים שלא שויכו"; הדרישה חוזרת למצב שלפניו');
$re = call('POST', 'file-assign', ['file' => $dup2[1]['file'], 'requirement' => $R['plan']], $tal);
eq([$re[0], $re[1]['version'] ?? null, $rrow($R['plan'])['status']], [200, 2, 'received'], 'קובץ שנדחה אפשר לשייך שוב — כגרסה חדשה');
call('POST', 'file-review', ['link' => $re[1]['link'], 'do' => 'return', 'return_code' => 'outdated'], $dana);
$v3 = up($tal, pdf('', 'plan-3'), ['case' => $C, 'requirement' => $R['plan']]);
call('POST', 'file-review', ['link' => $v3[1]['link'], 'do' => 'reject', 'reason' => 'לא קשור'], $dana);
eq([$rrow($R['plan'])['status'], apr_req_current((int)$rrow($R['plan'])['id'])], ['returned', 2], 'דחיית גרסה 3 ← הדרישה חוזרת למצב של גרסה 2 (returned)');

// גרסה שהוחלפה לפני שנבדקה אינה נבדקת עוד; ומחיקת קובץ של דרישה "לא רלוונטי" אינה מחזירה אותה לרשימה
$sA = up($tal, pdf('', 'super-1'), ['case' => $C, 'requirement' => $R['plan']])[1]['link'];
$sB = up($tal, pdf('', 'super-2'), ['case' => $C, 'requirement' => $R['plan']])[1];
eq([code($approve($dana, $sA)), $lrow($sA)['review_state'], $rrow($R['plan'])['status']], ['req_state', 'pending', 'received'], 'גרסה שהוחלפה לפני שנבדקה — אינה נבדקת; בודקים את הנוכחית');
call('POST', 'file-review', ['link' => $sB['link'], 'do' => 'return', 'return_code' => 'unreadable'], $dana);
call('POST', 'requirement-status', ['requirement' => $R['plan'], 'status' => 'not_relevant', 'reason' => 'ויתרנו'], $dana);
call('POST', 'file-delete', ['file' => $sB['file'], 'reason' => 'לא נחוץ'], $dana);
eq($rrow($R['plan'])['status'], 'not_relevant', 'מחיקת קובץ של דרישה "לא רלוונטי" משאירה אותה כך');
call('POST', 'requirement-status', ['requirement' => $R['plan'], 'status' => 'required', 'reason' => 'חזרה'], $dana);

// ── 06-D3, עריכת פרטי קובץ, תגיות ──
$fo = fn(string $as, string $case, string $pid) => array_column(array_merge($docs($as, $case)[1]['unassigned'], ...array_map(fn($r) => array_column($r['files'], 'file'), $docs($as, $case)[1]['requirements'])), null, 'pid')[$pid] ?? null;
eq([array_key_exists('doc_date', $fo($avi, $C, $Funassigned)), $fo($avi, $C, $Funassigned)['doc_date'], $frow($Funassigned)['doc_date']], [true, null, null], '06-D3 doc_date שלא הוזן מוחזר null (ונשמר NULL)');
eq([code(call('POST', 'file-update', ['file' => $Funassigned, 'doc_date' => '2026-02-30'], $tal)), call('POST', 'file-update', ['file' => $Funassigned, 'doc_date' => '2026-02-11', 'source_note' => 'נמסר מהלקוח', 'tags' => ['נסח', ' היתר ', 'נסח', '']], $tal)[0],
    $fo($avi, $C, $Funassigned)['doc_date'], $fo($avi, $C, $Funassigned)['source_note'], $fo($avi, $C, $Funassigned)['tags'], $fo($avi, $C, $Funassigned)['received_at'] !== '2026-02-11'],
    ['date_invalid', 200, '2026-02-11', 'נמסר מהלקוח', ['היתר', 'נסח'], true], '06-D3 תאריך המסמך נפרד מתאריך הקבלה; תגיות נשמרות בלי כפילויות');
call('POST', 'file-update', ['file' => $Funassigned, 'doc_date' => ''], $tal);
eq([$frow($Funassigned)['doc_date'], code(call('POST', 'file-update', ['file' => $Funassigned, 'tags' => 'x'], $tal)), code(call('POST', 'file-update', ['file' => $Funassigned, 'tags' => array_fill(0, 11, 'a')], $tal))], [null, 'content_invalid', 'content_invalid'], 'ואפשר לחזור ל"לא ידוע"');
$rv = up($tal, pdf('', 'with-date'), ['case' => $C, 'doc_date' => '2025-12-01', 'source_note' => 'מהעירייה']);
eq([$frow($rv[1]['file'])['doc_date'], $frow($rv[1]['file'])['source_note'], code(up($tal, pdf('', 'bad-date'), ['case' => $C, 'doc_date' => 'אתמול']))], ['2025-12-01', 'מהעירייה', 'date_invalid'], 'תאריך המסמך והמקור בהעלאה');

// ── 06-C4: חיפוש ──
call('POST', 'file-update', ['file' => $linkD['file'], 'name' => 'נסח טאבו סודי.pdf', 'tags' => ['נסח']], $avi);
$sr = fn(string $as, mixed $q, array $more = []) => call('GET', 'docs-search', [], $as, ['q' => $q] + $more)[1]['results'] ?? null;
$cases = fn(array $res) => array_values(array_unique(array_map(fn($x) => $x['case']['pid'], $res)));
eq([$cases($sr($dana, 'נסח')), in_array($D, $cases($sr($avi, 'נסח')), true), $sr($boaz, 'נסח'), $sr($gil, 'נסח'), $sr($owner, 'נסח')], [[$C], true, [], [], null], '06-C4 החיפוש אינו מחזיר קובץ, תגית או דרישה מתיק שאינני משתתף בו');
$types = fn(array $res) => array_count_values(array_column($res, 'type'));
eq([isset($types($sr($dana, 'היתר'))['file']), isset($types($sr($dana, 'היתר'))['requirement']), $sr($dana, '%'), $sr($dana, 'נ'), $sr($dana, ['x']), $cases($sr($avi, 'נסח', ['case' => $D])), $sr($dana, 'נסח', ['case' => $D])],
    [true, true, [], [], [], [$D], []], '06-C4 חיפוש לפי שם קובץ, תגית וכותרת דרישה; % אינו תו כללי; סינון לתיק');
call('POST', 'file-delete', ['file' => $rv[1]['file'], 'reason' => 'הועלה בטעות'], $dana);
eq($sr($dana, 'מסמך.pdf') === [] || !in_array($rv[1]['file'], array_column($sr($dana, 'מסמך'), 'file'), true), true, 'קובץ שנמחק אינו בחיפוש');

// ── הלוח הראשי: ממתינים לבדיקה ──
$pend = fn(string $as) => array_column(call('GET', 'docs-pending', [], $as)[1]['pending'], 'requirement');
up($tal, pdf('', 'pend'), ['case' => $C, 'requirement' => $R['permit']]);   // not_relevant → נדחה
call('POST', 'requirement-status', ['requirement' => $R['permit'], 'status' => 'required', 'reason' => 'חזר'], $dana);
up($tal, pdf('', 'pend2'), ['case' => $C, 'requirement' => $R['permit']]);
eq([in_array($R['permit'], $pend($dana), true), in_array($RD['contract'], $pend($dana), true), in_array($RD['contract'], $pend($avi), true), $pend($tal), $pend($boaz), $pend($gil)], [true, false, true, [], [], []],
    'הלוח: "ממתינים לבדיקה" — רק בתיקים שיש לי בהם review_docs');

// 🟡6: חבר שהוסר מהתיק, ומי שהמשרד הפעיל שלו אחר — אינם מוצאים דבר
$uri = ident('9', 'אורי', 'uri@example.com');
$uriPid = join_office($avi, $uri, 'uri@example.com', 'appraiser');
call('POST', 'case-member-add', ['case' => $C, 'user' => $uriPid], $avi);
$in = [$cases($sr($uri, 'נסח')), call('GET', 'docs-pending', [], $uri)[1]['pending'] !== []];
join_office($boaz, $uri, 'uri@example.com', 'appraiser');
$offB = array_column(call('GET', 'me', [], $uri)[1]['offices'], 'pid', 'name')['משרד ב'];
call('POST', 'office-switch', ['office' => $offB], $uri);
$other = [$sr($uri, 'נסח'), call('GET', 'docs-pending', [], $uri)[1]['pending']];
call('POST', 'office-switch', ['office' => array_column(call('GET', 'me', [], $uri)[1]['offices'], 'pid', 'name')['משרד א']], $uri);
call('POST', 'case-member-remove', ['case' => $C, 'user' => $uriPid], $avi);
eq([$in, $other, $sr($uri, 'נסח'), call('GET', 'docs-pending', [], $uri)[1]['pending']], [[[$C], true], [[], []], [], []], '06-C4 (סבב 2) משתתף מוצא; כשהמשרד הפעיל אחר — לא; אחרי שהוסר מהתיק — לא (חיפוש ו"ממתינים לבדיקה")');

// ── 06-D1: פיצול לגורמים ──
$lawyer = call('POST', 'case-party-add', ['case' => $C, 'name' => 'עו"ד כהן', 'kind' => 'lawyer'], $avi)[1]['party'];
$client = apr_val("SELECT pid FROM case_parties WHERE case_id = ? AND kind = 'client'", [$crow($C)['id']]);
$partyD = apr_val('SELECT pid FROM case_parties WHERE case_id = ?', [$crow($D)['id']]);
$add = call('POST', 'requirement-add', ['case' => $C, 'title' => 'אישור זכויות', 'mandatory' => true], $tal)[1]['requirement'];
$open = fn(string $as) => array_column(call('GET', 'cases', [], $as)[1]['cases'], 'open_mandatory', 'pid')[$C];
$o0 = $open($avi);
eq([code(call('POST', 'requirement-split', ['requirement' => $add, 'parts' => [['party' => $client]]], $tal)), code(call('POST', 'requirement-split', ['requirement' => $add, 'parts' => [['party' => $client], ['party' => $client]]], $tal)),
    code(call('POST', 'requirement-split', ['requirement' => $add, 'parts' => [['party' => $client], ['party' => $partyD]]], $tal)), code(call('POST', 'requirement-split', ['requirement' => $add, 'parts' => 'x'], $tal)),
    code(call('POST', 'requirement-split', ['requirement' => $R['rights'], 'parts' => [['party' => $client], ['party' => $lawyer]]], $tal)), $rrow($add)['split_at']],
    ['split_parts', 'split_parts', 'not_found', 'split_parts', 'req_state', null], '06-D1 פיצול: שני גורמים שונים של התיק הזה; רק דרישה שעוד לא נקלט בה קובץ');
$sp = call('POST', 'requirement-split', ['requirement' => $add, 'parts' => [['party' => $client], ['party' => $lawyer, 'title' => 'אישור זכויות — מעורך הדין']]], $tal);
$new = array_map($rrow, $sp[1]['requirements'] ?? []);
eq([$sp[0], count($new), array_column($new, 'title'), array_unique(array_column($new, 'split_from_id')) === [$rrow($add)['id']], array_column($new, 'status'), array_column($new, 'mandatory'), $rrow($add)['split_at'] !== null, count(array_unique(array_column($new, 'party_id')))],
    [200, 2, ['אישור זכויות — משה לקוח', 'אישור זכויות — מעורך הדין'], true, ['required', 'required'], [1, 1], true, 2], '06-D1 פיצול לשני גורמים ← שתי דרישות חדשות, כל אחת עם גורם משלה');
eq([$open($avi) - $o0, code(up($tal, pdf('', 'split'), ['case' => $C, 'requirement' => $add])), code(call('POST', 'requirement-status', ['requirement' => $add, 'status' => 'not_relevant', 'reason' => 'x'], $dana)),
    code(call('POST', 'requirement-update', ['requirement' => $add, 'title' => 'x'], $tal)), code(call('POST', 'requirement-split', ['requirement' => $add, 'parts' => [['party' => $client], ['party' => $lawyer]]], $tal)),
    array_column($docs($avi, $C)[1]['requirements'], 'split', 'pid')[$add]],
    [1, 'req_state', 'req_state', 'req_state', 'req_state', true], '06-D1 המקורית אינה נספרת כפתוחה (שתיים במקום אחת), ואינה מקבלת קובץ, מצב או עריכה');
// "דרישת חובה פתוחה" = כל מה שלא אושר ולא סומן לא רלוונטי
$m = $sp[1]['requirements'][0];
$cnt = [$open($avi)];
$ml = up($tal, pdf('', 'mand'), ['case' => $C, 'requirement' => $m])[1]['link'];
$cnt[] = $open($avi);
$approve($dana, $ml);
$cnt[] = $open($avi);
call('POST', 'requirement-status', ['requirement' => $sp[1]['requirements'][1], 'status' => 'not_relevant', 'reason' => 'אין עו"ד'], $dana);
$cnt[] = $open($avi);
eq([$cnt[1] - $cnt[0], $cnt[2] - $cnt[0], $cnt[3] - $cnt[0]], [0, -1, -2], 'דרישת חובה שהתקבלה ועוד לא אושרה — עדיין פתוחה; מאושרת או "לא רלוונטי" — לא');

// מעבר שאינו שינוי אינו נרשם
$na = count($log($C, 'requirement.status'));
$same = call('POST', 'requirement-status', ['requirement' => $sp[1]['requirements'][1], 'status' => 'not_relevant', 'reason' => 'שוב'], $dana);
eq([$same[0], count($log($C, 'requirement.status')) - $na], [200, 0], 'מעבר למצב הנוכחי (not_relevant ← not_relevant) אינו רושם שורת יומן');
// תמונה מעל 16 מגה-פיקסל: בלי תמונה ממוזערת (אייקון), והקובץ עצמו מתקבל
$bigPng = img('png');
$bigPng = substr($bigPng, 0, 16) . pack('NN', 5000, 4000) . substr($bigPng, 24);
$bp = up($tal, $bigPng, ['case' => $C, 'name' => 'ענק.png']);
eq([$bp[0], code(call('GET', 'file-thumb', [], $avi, ['file' => $bp[1]['file'] ?? '']))], [200, 'not_found'], '🟡5 תמונה של 20 מגה-פיקסל: מתקבלת, בלי תמונה ממוזערת');
$hdr = call('GET', 'file', [], $avi, ['file' => $bp[1]['file']])[1]['_file']['headers']['Content-Disposition'];
check('שם עברי: filename="file.png" (ולא "_.png"), ו-filename* עם השם המלא', str_contains($hdr, 'filename="file.png"') && str_contains($hdr, rawurlencode('ענק.png')), $hdr);

// ── סדר וקבוצה ──
$ord = call('POST', 'requirement-order', ['case' => $C, 'items' => [['requirement' => $R['permit'], 'group' => 'legal'], ['requirement' => $R['rights']], ['requirement' => $R['id'], 'group' => null]]], $tal);
eq([$ord[0], (int)$rrow($R['permit'])['sort'], (int)$rrow($R['rights'])['sort'], (int)$rrow($R['id'])['sort'], $rrow($R['permit'])['group_key'], $rrow($R['id'])['group_key'], array_slice(array_column($docs($avi, $C)[1]['requirements'], 'pid'), 0, 3)],
    [200, 10, 20, 30, 'legal', null, [$R['permit'], $R['rights'], $R['id']]], 'שינוי סדר, בתוך קבוצה ובין קבוצות');
eq([code(call('POST', 'requirement-order', ['case' => $C, 'items' => [['requirement' => $R['permit'], 'group' => 'nope']]], $tal)), code(call('POST', 'requirement-order', ['case' => $C, 'items' => []], $tal)),
    code(call('POST', 'requirement-order', ['case' => $C, 'items' => ['x']], $tal))], ['content_invalid', 'content_invalid', 'content_invalid'], 'קבוצה שאינה בפרופיל, רשימה ריקה או פגומה ← נדחות');

// ── מקרה 8: מחיקת הקובץ היחיד של דרישה מאושרת; ומזהה נכס שאומת מולו (04-C3) ──
$addr = call('POST', 'case-identifier-add', ['case' => $C, 'kind' => 'address', 'locality' => 'תל אביב', 'street' => 'הרצל', 'house_no' => '1'], $avi)[1]['identifier'];
$idu = fn(array $p, string $as = '') => code(call('POST', 'case-identifier-update', ['case' => $C, 'identifier' => $addr] + $p, $as ?: $GLOBALS['t_avi']));
$GLOBALS['t_avi'] = $avi;
$gone = up($tal, pdf('', 'gone'), ['case' => $C])[1]['file'];
call('POST', 'file-delete', ['file' => $gone, 'reason' => 'טעות'], $dana);
$istate = fn() => apr_row('SELECT state, source_kind, source_ref FROM case_identifiers WHERE pid = ?', [$addr]);
eq([$idu(['state' => 'verified']), $idu(['state' => 'verified', 'source_kind' => 'visit']), $idu(['state' => 'verified', 'source_kind' => 'source_check', 'source_ref' => $F1]), $idu(['state' => 'verified', 'source_kind' => 'document']),
    $idu(['state' => 'verified', 'source_kind' => 'document', 'source_ref' => 'zzzzzzzzzzzzzzzz']), $idu(['state' => 'verified', 'source_kind' => 'document', 'source_ref' => $linkD['file']]),
    $idu(['state' => 'verified', 'source_kind' => 'document', 'source_ref' => $gone]), $idu(['source_kind' => 'document', 'source_ref' => ['x']]), $istate()['state']],
    ['address_not_verifiable', 'address_not_verifiable', 'address_not_verifiable', 'source_file_required', 'source_file_required', 'source_file_required', 'source_file_required', 'source_file_required', 'entered'],
    '04-C3 כתובת אינה מאומתת בלי מסמך: לא בהצהרה, לא בקובץ שלא קיים, לא בקובץ של תיק אחר ולא בקובץ שנמחק');
eq([$idu(['state' => 'verified', 'source_kind' => 'document', 'source_ref' => $F1]), $istate()], [null, ['state' => 'verified', 'source_kind' => 'document', 'source_ref' => $F1]], '04-C3 מול קובץ אמיתי של התיק ← verified');
eq([code(call('POST', 'case-identifier-add', ['case' => $C, 'kind' => 'parcel', 'gush' => '1', 'helka' => '2', 'source_kind' => 'document'], $avi)), code(call('POST', 'case-identifier-add', ['case' => $C, 'kind' => 'parcel', 'gush' => '1', 'helka' => '2', 'source_kind' => 'document', 'source_ref' => $linkD['file']], $avi)),
    call('POST', 'case-identifier-add', ['case' => $C, 'kind' => 'parcel', 'gush' => '1', 'helka' => '2', 'source_kind' => 'document', 'source_ref' => $F1], $avi)[0],
    apr_val("SELECT source_ref FROM case_identifiers WHERE case_id = ? AND gush = '1' AND source_kind = 'user'", [$crow($C)['id']])], ['source_file_required', 'source_file_required', 200, false], '04-C3 מקור "מסמך" בהוספת מזהה — רק קובץ של התיק');
eq([code(call('POST', 'file-delete', ['file' => $F1], $dana)), code(call('POST', 'file-delete', ['file' => $F1, 'reason' => ''], $dana)), $frow($F1)['deleted_at']], ['reason_required', 'reason_required', null], 'מחיקה בלי נימוק ← נדחית');
$del = call('POST', 'file-delete', ['file' => $F1, 'reason' => 'הנסח הוחלף'], $dana);
$f = $frow($F1);
eq([$del[0], $f['deleted_at'] !== null, $f['delete_reason'], is_file(apr_files_dir() . '/' . $f['storage_key']), $rrow($R['rights'])['status'], $rrow($R['id'])['status'], $del[1]['requirements']],
    [200, true, 'הנסח הוחלף', true, 'required', 'received', [$R['rights'] => 'required', $R['id'] => 'received']], 'מקרה 8: מחיקה רכה של הקובץ היחיד של דרישה מאושרת ← required; דרישה שנשארה לה גרסה קודמת שממתינה — received');
eq([$istate()['state'], code(call('GET', 'file', [], $avi, ['file' => $F1])), code(call('GET', 'file-thumb', [], $avi, ['file' => $F1])), code(call('POST', 'file-delete', ['file' => $F1, 'reason' => 'שוב'], $dana)),
    code(call('POST', 'file-assign', ['file' => $F1, 'requirement' => $R['plan']], $tal)), code($approve($dana, $L1)), $fo($avi, $C, $F1)['deleted'], count($log($C, 'file.delete'))],
    ['needs_check', 'not_found', 'not_found', 'not_found', 'not_found', 'not_found', true, 4], 'קובץ שנמחק: אין הורדה, שיוך או בדיקה; נשאר בהיסטוריה כמחוק; המזהה שאומת מולו חוזר ל"לבדיקה"');
eq([up($tal, pdf('', 'gone'), ['case' => $C])[1]['duplicate'] ?? null, isset(up($tal, pdf('', 'gone'), ['case' => $C])[1]['duplicate'])], [null, true], 'קובץ שנמחק אינו נחשב כפילות (והעותק החדש שלו — כן)');

// ── קישור של משימה ──
eq([code(call('POST', 'task-create', ['case' => $C, 'title' => 'לבדוק', 'link_type' => 'requirement', 'link_pid' => $RD['plan']], $avi)), code(call('POST', 'task-create', ['case' => $C, 'title' => 'לבדוק', 'link_type' => 'identifier', 'link_pid' => 'zzzzzzzzzzzzzzzz'], $avi)),
    call('POST', 'task-create', ['case' => $C, 'title' => 'לבדוק', 'link_type' => 'requirement', 'link_pid' => $R['plan']], $avi)[0], call('POST', 'task-create', ['case' => $C, 'title' => 'בלי קישור', 'link_type' => 'identifiers'], $avi)[0]],
    ['link_invalid', 'link_invalid', 200, 200], 'משימה מקושרת רק לדרישה או למזהה של אותו תיק');

// ── מקרה 13: תיק סגור ──
foreach (apr_rows("SELECT pid FROM tasks WHERE case_id = ? AND status IN ('open', 'in_progress')", [$crow($D)['id']]) as $t) call('POST', 'task-update', ['task' => $t['pid'], 'status' => 'done'], $avi);
$cl = call('POST', 'case-status', ['case' => $D, 'status' => 'closed', 'reason' => 'הסתיים'], $avi);
eq([$cl[0], code(up($avi, pdf('', 'closed'), ['case' => $D])), code(call('POST', 'file-review', ['link' => $linkD['link'], 'do' => 'reject', 'reason' => 'x'], $avi)), code(call('POST', 'file-assign', ['file' => $linkD['file'], 'requirement' => $RD['plan']], $avi)),
    code(call('POST', 'file-update', ['file' => $linkD['file'], 'name' => 'x'], $avi)), code(call('POST', 'file-delete', ['file' => $linkD['file'], 'reason' => 'x'], $avi)),
    code(call('POST', 'requirement-status', ['requirement' => $RD['plan'], 'status' => 'not_relevant', 'reason' => 'x'], $avi)), code(call('POST', 'requirement-order', ['case' => $D, 'items' => [['requirement' => $RD['plan']]]], $avi)),
    code(call('POST', 'requirement-split', ['requirement' => $RD['plan'], 'parts' => []], $avi)), code(call('POST', 'requirement-update', ['requirement' => $RD['plan'], 'title' => 'x'], $avi)),
    $docs($avi, $D)[0], call('GET', 'file', [], $avi, ['file' => $linkD['file']])[0], in_array($RD['contract'], $pend($avi), true)],
    [200, 'case_closed', 'case_closed', 'case_closed', 'case_closed', 'case_closed', 'case_closed', 'case_closed', 'case_closed', 'case_closed', 200, 200, false], 'מקרה 13: תיק סגור — צפייה והורדה כן; העלאה, בדיקה ועריכה לא');

// ── קלט מסוג שגוי: בלי 500 ובלי אזהרות ──
$junk = [null, [], ['a' => ['b']], 0, 1.5, true, "\x00", str_repeat('a', 17), '../x', "aaaaaaaaaaaaaaaa\n"];
$worst = 0;
foreach ($junk as $j) {
    foreach ([['file-review', ['link' => $j, 'do' => $j, 'chk_readable' => $j, 'return_code' => $j, 'reason' => $j, 'doc_date' => $j]], ['file-review', ['link' => $Lid, 'do' => 'return', 'return_code' => $j, 'return_reason' => $j, 'note' => $j]],
        ['file-assign', ['file' => $j, 'requirement' => $j, 'relation' => $j]], ['file-assign', ['file' => $Funassigned, 'requirement' => $j, 'relation' => $j]], ['file-update', ['file' => $Funassigned, 'name' => $j, 'doc_date' => $j === null ? '' : $j, 'tags' => $j, 'source_note' => $j]],
        ['file-delete', ['file' => $j, 'reason' => $j]], ['requirement-status', ['requirement' => $j, 'status' => $j, 'reason' => $j]], ['requirement-status', ['requirement' => $R['plan'], 'status' => $j, 'reason' => $j]],
        ['requirement-order', ['case' => $C, 'items' => $j]], ['requirement-order', ['case' => $C, 'items' => [$j]]], ['requirement-order', ['case' => $C, 'items' => [['requirement' => $j, 'group' => $j]]]],
        ['requirement-split', ['requirement' => $R['plan'], 'parts' => $j]], ['requirement-split', ['requirement' => $R['plan'], 'parts' => [$j, $j]]], ['requirement-split', ['requirement' => $R['plan'], 'parts' => [['party' => $j, 'title' => $j], ['party' => $client]]]],
        ['requirement-update', ['requirement' => $R['plan'], 'mandatory' => $j, 'reason' => $j]], ['case-identifier-update', ['case' => $C, 'identifier' => $addr, 'source_kind' => 'document', 'source_ref' => $j]]] as [$a, $p]) {
        $worst = max($worst, call('POST', $a, $p, $avi)[0]);
    }
    foreach ([['file', ['file' => $j, 'inline' => $j]], ['file-thumb', ['file' => $j]], ['case-docs', ['case' => $j]], ['docs-search', ['q' => $j, 'case' => $j]]] as [$a, $p]) $worst = max($worst, call('GET', $a, [], $avi, $p)[0]);
    $worst = max($worst, up($avi, pdf('', 'fuzz'), ['case' => $C, 'requirement' => $j, 'relation' => $j, 'key' => $j, 'dup' => $j, 'doc_date' => $j === null ? '' : $j, 'source_note' => $j, 'name' => $j])[0]);
}
check('קלט מסוג שגוי בכל הפעולות: אף 500', $worst < 500, $worst);
eq($warnings, [], 'ובלי אף אזהרה של PHP לאורך כל הקובץ');

// ── מרוצים: שני אישורים במקביל; שתי העלאות עם אותו מזהה פעולה ──
$dir = sys_get_temp_dir() . '/apr-conc-' . bin2hex(random_bytes(5));
mkdir($dir, 0700, true);
$setup = 'define("APR_DATA_DIR", ' . var_export($dir, true) . '); define("APR_DB_PATH", ' . var_export("$dir/app.sqlite", true) . '); $_SERVER["argv"] = []; require ' . var_export(__DIR__ . '/lib.php', true) . ';
    $as = ident("2", "אבי", "avi@example.com"); office_for($as, "משרד");
    $p = call("POST", "profile-create", ["scope" => "office", "name" => "p"], $as)[1]["profile"];
    [, $d] = call("POST", "profile-draft-open", ["profile" => $p], $as);
    $it = fn($k) => ["key" => $k, "group" => "", "doc_type" => "rights", "title" => $k, "priority" => "normal", "mandatory" => false, "party_kind" => "client", "due" => ["mode" => "relative", "days" => 3], "needs_review" => true, "when" => null, "sort" => 1];
    $s = call("POST", "profile-draft-save", ["profile" => $p, "content" => ["flags" => [], "requirements" => ["groups" => [], "items" => [$it("a"), $it("b")]], "tasks" => ["items" => []], "rules" => []], "revision" => $d["draft"]["revision"]], $as);
    call("POST", "profile-publish", ["profile" => $p, "note" => "", "revision" => $s[1]["revision"]], $as);
    $c = call("POST", "case-create", ["profile" => $p], $as)[1]["case"];
    call("POST", "case-update", ["case" => $c, "title" => "t", "purpose" => "purchase"], $as);
    call("POST", "case-identifier-add", ["case" => $c, "kind" => "parcel", "gush" => "1", "helka" => "2"], $as);
    call("POST", "case-party-add", ["case" => $c, "name" => "x", "kind" => "client", "is_orderer" => true], $as);
    call("POST", "case-start", ["case" => $c], $as);
    $r = array_column(apr_rows("SELECT pid, origin_key FROM requirements"), "pid", "origin_key");
    $tmp = tempnam(apr_upload_tmp_dir(), "up"); file_put_contents($tmp, "%PDF-1.4\n1 0 obj << /Type /Page >> endobj\n%first\n%%EOF\n");
    $u = call("POST", "file-upload", ["case" => $c, "requirement" => $r["a"], "name" => "a.pdf"], $as, [], ["env" => ["upload" => $tmp]]);
    echo json_encode([$c, $r["a"], $r["b"], $u[1]["link"] ?? $u]);';
[$kc, $ka, $kb, $kl] = json_decode(trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($setup))), true) ?? [null, null, null, null];
$race = function (array $args) use ($dir): array {
    $procs = $pipes = [];
    $go = (string)(microtime(true) + 1.5);
    for ($i = 0; $i < 6; $i++) $procs[] = proc_open([PHP_BINARY, __FILE__, $args[0], $dir, $args[1], $go, $args[2] ?? ''], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    $out = [];
    foreach ($procs as $i => $proc) { $out[] = trim((string)stream_get_contents($pipes[$i][1])); proc_close($proc); }
    sort($out);
    return $out;
};
$codes = array_map('intval', $race(['reviewer', (string)$kl]));
$pdo = new PDO("sqlite:$dir/app.sqlite");
eq([$codes, (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'file.approve'")->fetchColumn(), $pdo->query('SELECT status FROM requirements WHERE pid = ' . $pdo->quote((string)$ka))->fetchColumn()],
    [[200, 409, 409, 409, 409, 409], 1, 'approved'], 'מרוץ: שש בקשות אישור במקביל ← אחת מצליחה, חמש "כבר נבדק", שורת יומן אחת');
$ups = $race(['uploader', (string)$kc, (string)$kb]);
$n = 0;
if (is_dir("$dir/files")) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$dir/files", FilesystemIterator::SKIP_DOTS)) as $x) $n++;
eq([count(array_unique($ups)), str_starts_with($ups[0], '200 '), (int)$pdo->query("SELECT COUNT(*) FROM files WHERE idempotency_key IS NOT NULL")->fetchColumn(),
    (int)$pdo->query('SELECT COUNT(*) FROM requirement_files rf JOIN requirements r ON r.id = rf.requirement_id WHERE r.pid = ' . $pdo->quote((string)$kb))->fetchColumn(), $n, count(glob("$dir/tmp/*") ?: [])],
    [1, true, 1, 1, 2, 0], '06-B6 מרוץ: שש העלאות עם אותו מזהה פעולה במקביל ← קובץ אחד, שיוך אחד, וכולן מקבלות אותו מזהה');
$pdo = null;
$rm = function (string $d) use (&$rm) { foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $f) is_dir("$d/$f") ? $rm("$d/$f") : @unlink("$d/$f"); @rmdir($d); };
$rm($dir);

// ── מיגרציה 006 על מסד בגרסה 5, עם נתונים ──
$old = new PDO('sqlite::memory:');
$old->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$tmpDir = sys_get_temp_dir() . '/apr-test-mig-' . bin2hex(random_bytes(4));
mkdir($tmpDir);
foreach (array_slice(glob(dirname(__DIR__) . '/lib/migrations/*.sql'), 0, 5) as $m) copy($m, "$tmpDir/" . basename($m));
apr_migrate($old, $tmpDir);
$v5 = apr_schema_version($old);
$cols = array_column($old->query('PRAGMA table_info(requirements)')->fetchAll(PDO::FETCH_ASSOC), 'name');
apr_migrate($old);
$cols2 = array_column($old->query('PRAGMA table_info(requirements)')->fetchAll(PDO::FETCH_ASSOC), 'name');
eq([$v5, in_array('split_at', $cols, true), apr_schema_version($old), array_values(array_diff($cols2, $cols)), (int)$old->query("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('files', 'requirement_files', 'file_tags', 'file_thumbs')")->fetchColumn(), apr_schema_expected()],
    [5, false, apr_schema_expected(), ['status_by', 'status_at', 'split_from_id', 'split_at'], 4, apr_schema_expected()], 'מיגרציה 006 (והבאות) על מסד בגרסה 5: העמודות והטבלאות נוספות');
foreach (glob("$tmpDir/*") as $m) unlink($m);
rmdir($tmpDir);

done();
