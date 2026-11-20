<?php
// פרוסה 13 — ביקור: טופס מהפרופיל, תמונות, כמה ביקורים (docs/spec/13-visit.md §12): 13-A1 … 13-C6, מקרי הקצה, ההרשאות, הבידוד, המרוץ והמיגרציה.
if (($argv[1] ?? '') === 'finisher') {
    // תהליך-בן למרוץ: כולם מסמנים "הושלם" באותו רגע, על אותו קובץ מסד
    define('APR_DATA_DIR', $argv[2] . '/child-' . getmypid());     // תיקייה משלו: הניקוי בסוף התהליך לא ימחק את המסד של ההורה
    define('APR_DB_PATH', $argv[2] . '/app.sqlite');
    require __DIR__ . '/lib.php';
    $GLOBALS['apr_time'] = (int)$argv[6];
    $as = ident('2', 'אבי', 'avi@example.com');
    call('GET', 'me', [], $as);
    while (microtime(true) < (float)$argv[4]) usleep(200);
    $r = call('POST', 'visit-finish', ['visit' => $argv[3], 'rev' => (int)$argv[5], 'result' => 'completed'], $as);
    echo $r[0];
    exit(0);
}
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

$owner = ident('1', 'הבעלים', 'owner@example.com', ['platform_admin' => true]);
$avi = ident('2', 'אבי', 'avi@example.com');       // מנהל משרד א, שמאי — האחראי על התיקים
$dana = ident('3', 'דנה', 'dana@example.com');     // שמאית, שותפה בתיק: יש לה visit, ואינה המבקרת
$noa = ident('9', 'נועה', 'noa@example.com');      // מתמחה — המבקרת
$tal = ident('5', 'טל', 'tal@example.com');        // עובדת משרד: view, בלי visit
$gil = ident('7', 'גיל', 'gil@example.com');       // שמאי במשרד א שאינו משתתף בתיק
$boaz = ident('4', 'בועז', 'boaz@example.com');    // משרד ב
call('GET', 'me', [], $owner);
office_for($avi, 'משרד א');
office_for($boaz, 'משרד ב');
$danaPid = join_office($avi, $dana, 'dana@example.com', 'appraiser');
$noaPid = join_office($avi, $noa, 'noa@example.com', 'trainee');
$talPid = join_office($avi, $tal, 'tal@example.com', 'staff');
join_office($avi, $gil, 'gil@example.com', 'appraiser');
$aviPid = call('GET', 'me', [], $avi)[1]['user']['pid'];

function vfield(string $key, string $group, string $type, int $sort, array $more = []): array
{
    return $more + ['key' => $key, 'group' => $group, 'label' => "שדה $key", 'type' => $type, 'required' => false, 'when' => null, 'sort' => $sort];
}
function visit_form(array $more = []): array
{
    return $more + ['groups' => [['key' => 'main', 'title' => 'עיקרי', 'sort' => 1], ['key' => 'rooms', 'title' => 'חדרים', 'sort' => 2, 'repeat' => true], ['key' => 'photos', 'title' => 'תצלומים', 'sort' => 3]],
        'fields' => [
            vfield('addr', 'main', 'text', 1, ['required' => true]),
            vfield('has_elev', 'main', 'yesno', 2),
            vfield('elev_count', 'main', 'number', 3, ['required' => true, 'when' => ['field' => 'answer.has_elev', 'op' => 'is', 'value' => true]]),
            vfield('area', 'main', 'area', 4, ['required' => true, 'maps_to' => 'area']),
            vfield('floor', 'main', 'number', 5, ['maps_to' => 'floor']),
            vfield('use', 'main', 'text', 6, ['maps_to' => 'actual_use']),
            vfield('state', 'main', 'choice', 7, ['options' => [['key' => 'good', 'label' => 'טוב'], ['key' => 'bad', 'label' => 'רע']]]),
            vfield('mismatch', 'main', 'longtext', 8, ['creates_task' => true]),
            vfield('room_name', 'rooms', 'text', 1, ['required' => true]),
            vfield('room_len', 'rooms', 'length', 2),
            vfield('front', 'photos', 'photo', 1, ['required' => true, 'min' => 1]),
            vfield('other', 'photos', 'photo', 2)],
        'completion' => ['block_on_required' => true]];
}
function publish(string $as, string $pid, array $visit): array
{
    $it = ['key' => 'contract', 'group' => '', 'doc_type' => 'rights', 'title' => 'הסכם מכר', 'priority' => 'normal', 'mandatory' => false, 'party_kind' => 'client', 'due' => ['mode' => 'relative', 'days' => 3], 'needs_review' => true, 'when' => null, 'sort' => 1];
    [, $d] = call('POST', 'profile-draft-open', ['profile' => $pid], $as);
    $s = call('POST', 'profile-draft-save', ['profile' => $pid, 'content' => ['flags' => [], 'requirements' => ['groups' => [], 'items' => [$it]], 'tasks' => ['items' => []], 'rules' => [], 'visit' => $visit], 'revision' => $d['draft']['revision']], $as);
    return call('POST', 'profile-publish', ['profile' => $pid, 'note' => 'שינוי', 'revision' => $s[1]['revision'] ?? ''], $as);
}
function make_profile(string $as, array $visit): string
{
    $pid = call('POST', 'profile-create', ['scope' => 'office', 'name' => 'פרופיל'], $as)[1]['profile'];
    $r = publish($as, $pid, $visit);
    if ($r[0] !== 200) throw new RuntimeException('publish: ' . json_encode($r[1], JSON_UNESCAPED_UNICODE));
    return $pid;
}
function make_case(string $as, string $profile): string
{
    $c = call('POST', 'case-create', ['profile' => $profile], $as)[1]['case'];
    call('POST', 'case-update', ['case' => $c, 'title' => 'דירה ברחוב הרצל', 'purpose' => 'purchase', 'property_type' => 'apartment'], $as);
    call('POST', 'case-identifier-add', ['case' => $c, 'kind' => 'parcel', 'gush' => '6158', 'helka' => '12'], $as);
    call('POST', 'case-party-add', ['case' => $c, 'name' => 'משה לקוח', 'email' => 'moshe@client.example', 'kind' => 'client', 'is_orderer' => true], $as);
    $s = call('POST', 'case-start', ['case' => $c], $as);
    if ($s[0] !== 200) throw new RuntimeException('case-start: ' . json_encode($s[1], JSON_UNESCAPED_UNICODE));
    return $c;
}
/** JPEG אמיתי עם EXIF: יצרן, כיוון 6, ו-GPS (32°05′ צפון); וגם הערה, ומידע אחרי סוף התמונה. */
function jpeg_gps(string $mark = 'a'): string
{
    $im = imagecreatetruecolor(40, 20);
    imagefill($im, 0, 0, imagecolorallocate($im, crc32($mark) & 255, 90, 160));
    ob_start(); imagejpeg($im, null, 85); $j = (string)ob_get_clean();
    $tiff = "MM\0\x2A\0\0\0\x08" . "\0\x03"
        . "\x01\x0F\0\x02\0\0\0\x0A\0\0\0\x32" . "\x01\x12\0\x03\0\0\0\x01\0\x06\0\0" . "\x88\x25\0\x04\0\0\0\x01\0\0\0\x3C" . "\0\0\0\0"
        . "SECRETCAM\0"
        . "\0\x02" . "\0\x01\0\x02\0\0\0\x02N\0\0\0" . "\0\x02\0\x05\0\0\0\x03\0\0\0\x5A" . "\0\0\0\0"
        . pack('N6', 32, 1, 5, 1, 0, 1);
    $app1 = "\xFF\xE1" . pack('n', strlen($tiff) + 8) . "Exif\0\0" . $tiff;
    $com = "\xFF\xFE" . pack('n', 16) . 'SECRET-COMMENT';
    return "\xFF\xD8" . $app1 . $com . substr($j, 2) . 'TRAILING-SECRET-DATA';
}
function png_text(): string
{
    $im = imagecreatetruecolor(8, 8);
    ob_start(); imagepng($im); $p = (string)ob_get_clean();
    $chunk = fn(string $t, string $d) => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
    return substr($p, 0, 33) . $chunk('tEXt', "Location\0SECRET-PLACE") . substr($p, 33);
}
function photo_up(string $as, string $visit, string $bytes, array $q = [], array $env = []): array
{
    $tmp = tempnam(apr_upload_tmp_dir(), 'up');
    file_put_contents($tmp, $bytes);
    return call('POST', 'visit-photo-upload', $q + ['visit' => $visit, 'name' => 'IMG_1.jpg', 'key' => 'k-' . bin2hex(random_bytes(6))], $as, [], ['env' => $env + ['upload' => $tmp]]);
}

$count = fn(string $t, string $w = '1', array $a = []) => (int)apr_val("SELECT COUNT(*) FROM $t WHERE $w", $a);
$caseId = fn(string $pid) => (int)apr_val('SELECT id FROM cases WHERE pid = ?', [$pid]);
$vrow = fn(string $pid) => apr_row('SELECT * FROM visits WHERE pid = ?', [$pid]);
$raises = function (string $sql, array $args = []): bool { try { apr_exec($sql, $args); return false; } catch (PDOException) { return true; } };
$view = fn(string $as, string $visit) => call('GET', 'visit', [], $as, ['visit' => $visit]);
$rev = fn(string $visit) => (int)apr_val('SELECT rev FROM visits WHERE pid = ?', [$visit]);
$ans = fn(string $as, string $visit, string $field, mixed $value, int $r = 0, array $more = []) => call('POST', 'visit-answer', $more + ['visit' => $visit, 'field' => $field, 'rev' => $r, 'value' => $value], $as);
$arow = fn(string $visit, string $field, int $row = 0) => apr_row('SELECT a.* FROM visit_answers a JOIN visits v ON v.id = a.visit_id WHERE v.pid = ? AND a.field_key = ? AND a.row_no = ?', [$visit, $field, $row]);
$plan = fn(string $as, string $case, string $visitor, array $more = []) => call('POST', 'visit-plan', $more + ['case' => $case, 'visitor' => $visitor], $as);
$start = fn(string $as, string $visit) => call('POST', 'visit-start', ['visit' => $visit, 'rev' => $rev($visit)], $as);
$finish = fn(string $as, string $visit, string $result, array $more = []) => call('POST', 'visit-finish', $more + ['visit' => $visit, 'rev' => $rev($visit), 'result' => $result], $as);
$facts = fn(string $case, string $w = '1') => apr_rows("SELECT * FROM facts WHERE case_id = ? AND $w ORDER BY id", [$caseId($case)]);
$flat = function (mixed $v) use (&$flat): array {
    if (!is_array($v)) return is_string($v) ? [$v] : [];
    $out = [];
    foreach ($v as $k => $x) { if (is_string($k)) $out[] = $k; $out = array_merge($out, $flat($x)); }
    return $out;
};
/** ממלא את שדות החובה של הטופס ומעלה תמונת חזית — כך שאפשר לסמן "הושלם". */
$fill = function (string $as, string $visit, string $area = '75') use ($ans, $arow) {
    $ans($as, $visit, 'addr', 'הרצל 7', (int)($arow($visit, 'addr')['rev'] ?? 0));
    $ans($as, $visit, 'area', $area, (int)($arow($visit, 'area')['rev'] ?? 0), ['unit' => 'sqm', 'area_kind' => 'built']);
    return photo_up($as, $visit, jpeg_gps('f' . $visit), ['field' => 'front']);
};

$P = make_profile($avi, visit_form());
$C = make_case($avi, $P);
$D = make_case($avi, $P);                                   // תיק אחר באותו משרד
$B = make_case($boaz, make_profile($boaz, visit_form()));   // תיק במשרד ב
call('POST', 'case-member-add', ['case' => $C, 'user' => $danaPid], $avi);
call('POST', 'case-member-add', ['case' => $C, 'user' => $noaPid], $avi);
call('POST', 'case-member-add', ['case' => $C, 'user' => $talPid], $avi);
$perms = fn(string $as) => call('GET', 'case', [], $as, ['case' => $C])[1]['me']['perms'];
eq([$perms($noa)['visit'], $perms($tal)['visit'], $perms($dana)['visit']], [true, false, true], 'הכנה: המתמחה והשותפה עם visit; עובדת המשרד בלי');

// ── תיאום (§5.1) ──
eq([code($plan($tal, $C, $noaPid)), code($plan($gil, $C, $noaPid)), code($plan($boaz, $C, $noaPid)), code($plan($avi, $C, $talPid)), code($plan($avi, $C, $noaPid, ['scheduled_at' => '2026-13-40T10:00'])),
    code($plan($avi, $C, $noaPid, ['scheduled_at' => "2026-11-20T10:00\n"]))],
    ['forbidden', 'not_found', 'not_found', 'visit_visitor', 'visit_when', 'visit_when'], '§10 תיאום: בלי edit ← 403; לא משתתף / משרד אחר ← 404; מבקר בלי visit; מועד לא תקין (גם עם ירידת שורה)');
$r = $plan($avi, $C, $noaPid, ['scheduled_at' => '2026-11-20T10:00', 'access_note' => 'לצלצל לשכנה']);
$V1 = $r[1]['visit']['pid'];
$task = apr_row("SELECT t.*, u.pid AS upid FROM tasks t JOIN users u ON u.id = t.assignee_user_id WHERE t.origin_key = ?", ['visit:' . $V1]);
eq([$r[0], $r[1]['visit']['seq'], $r[1]['visit']['status'], $r[1]['visit']['done'], $task['upid'], $task['link_type'], $task['status']], [200, 1, 'planned', false, $noaPid, 'visit', 'open'],
    '§5.1 תיאום: ביקור 1 במצב planned, ונוצרת משימה למבקר');
eq(array_column(call('GET', 'my-visits', [], $noa)[1]['visits'], 'pid'), [$V1], 'לוח ראשי: "ביקורים קרובים שלי" — למבקר');
eq([call('GET', 'my-visits', [], $avi)[1]['visits'], code(call('GET', 'my-visits', [], $owner))], [[], 'no_office'], 'לוח ראשי: מי שאינו המבקר אינו רואה את הביקור ברשימה שלו');

// ── 13-A1: פתיחת ביקור מצלמת את הטופס ──
eq([$view($noa, $V1)[1]['visit']['snapshot'], $vrow($V1)['form_snapshot'], code($ans($noa, $V1, 'addr', 'x'))], [false, null, 'visit_not_open'], '13-A1 לפני "התחלת ביקור": אין צילום, ואי אפשר למלא');
eq([code($start($tal, $V1)), code($start($dana, $V1)), code($start($gil, $V1))], ['forbidden', 'forbidden', 'not_found'], '13-C6 התחלת ביקור: רק המבקר או האחראי');
$r = $start($noa, $V1);
$snap = $vrow($V1)['form_snapshot'];
eq([$r[0], $r[1]['visit']['status'], count(json_decode($snap, true)['fields']), (int)$vrow($V1)['form_version_id'] > 0], [200, 'in_progress', 12, true], '13-A1 "התחלת ביקור": in_progress, והטופס מצולם');
$form2 = visit_form();
$form2['fields'] = [vfield('totally_new', 'main', 'text', 1)];
$pub = publish($avi, $P, $form2);
$formKeys = fn(string $as, string $v) => array_column($view($as, $v)[1]['form']['fields'], 'key');
eq([$pub[0], $vrow($V1)['form_snapshot'] === $snap, in_array('addr', $formKeys($noa, $V1), true), in_array('totally_new', $formKeys($noa, $V1), true)], [200, true, true, false],
    '13-A1 פרסום גרסת פרופיל חדשה אינו משנה את form_snapshot, והמסך ממשיך להציג את הטופס שצולם');
eq([$raises('UPDATE visits SET form_snapshot = ?, rev = rev + 1 WHERE pid = ?', ['{}', $V1]), $raises('UPDATE visits SET form_version_id = 999, rev = rev + 1 WHERE pid = ?', [$V1]),
    $raises('UPDATE visits SET attendees = ? WHERE pid = ?', ['x', $V1]), $raises('DELETE FROM visits WHERE pid = ?', [$V1]), $vrow($V1)['form_snapshot'] === $snap],
    [true, true, true, true, true], '13-A1 SQL ישיר: הצילום אינו משתנה; שינוי בלי rev + 1 ומחיקה — נחסמים בטריגר');
publish($avi, $P, visit_form());

// ── 13-A3: "לא מולא" אינו 0 ואינו false ──
$v = $view($noa, $V1)[1];
eq([$v['answers'], $count('visit_answers')], [[], 0], '13-A3 ביקור שנפתח: אין אף תשובה ואין אף שורה');
$r = $ans($noa, $V1, 'has_elev', null);
$r2 = $ans($noa, $V1, 'floor', '');
eq([$r[0], $r[1]['answer'], $r2[1]['answer'], $count('visit_answers')], [200, null, null, 0], '13-A3 שמירת שדה ריק אינה יוצרת שורה, והתשובה null');
$r = $ans($noa, $V1, 'floor', '0');
eq([$r[1]['answer']['value'], $arow($V1, 'floor')['value_num']], ['0', 0], '13-A3 אפס שהמבקר הקליד הוא תשובה — ונשמר כאפס');
$r = $ans($noa, $V1, 'has_elev', false, 0);
eq([$r[1]['answer']['value'], $r[1]['answer']['rev']], [false, 1], '13-A3 "לא" שהמבקר בחר הוא תשובה');
$r = $ans($noa, $V1, 'has_elev', null, 1);
$a = $arow($V1, 'has_elev');
$got = array_values(array_filter($view($noa, $V1)[1]['answers'], fn($x) => $x['field'] === 'has_elev'))[0];
eq([$r[1]['answer']['value'], array_key_exists('value', $got) && $got['value'] === null, $a['value_num'], $a['value_text'], (int)$a['rev']], [null, true, null, null, 2],
    '13-A3 שדה שנוקה: null מקצה לקצה (לא false ולא 0), והגרסה ממשיכה לעלות');
eq([code($ans($noa, $V1, 'has_elev', 'yes', 2)), code($ans($noa, $V1, 'has_elev', 0, 2)), code($ans($noa, $V1, 'floor', '1e3', 1)), code($ans($noa, $V1, 'floor', '3.123', 1)), code($ans($noa, $V1, 'state', 'nope')),
    code($ans($noa, $V1, 'nosuch', 'x')), code($ans($noa, $V1, 'front', 'x')), code($ans($noa, $V1, 'addr', 'x', 0, ['row' => 2]))],
    ['visit_value', 'visit_value', 'fact_value', 'fact_value', 'visit_value', 'visit_field', 'visit_value', 'visit_field'], '§6.2 ערך שאינו מתאים לסוג השדה, שדה שאינו בטופס ושורה שאינה קיימת — נדחים');

// ── 13-A4: שטח בלי יחידה או בלי סוג שטח ──
$n = $count('visit_answers');
eq([code($ans($noa, $V1, 'area', '75')), code($ans($noa, $V1, 'area', '75', 0, ['unit' => 'sqm'])), code($ans($noa, $V1, 'area', '75', 0, ['area_kind' => 'built'])),
    code($ans($noa, $V1, 'area', '75', 0, ['unit' => 'cm', 'area_kind' => 'built'])), code($ans($noa, $V1, 'area', '75', 0, ['unit' => 'sqm', 'area_kind' => ''])), $count('visit_answers') - $n],
    ['fact_unit', 'area_kind_required', 'fact_unit', 'fact_unit', 'area_kind_required', 0], '13-A4 שטח בלי יחידה או בלי סוג שטח ← נדחה, ושום דבר לא נשמר');
$r = $ans($noa, $V1, 'area', '75.5', 0, ['unit' => 'sqm', 'area_kind' => 'unknown']);
eq([$r[0], $r[1]['answer']['value'], $r[1]['answer']['area_kind'], $r[1]['answer']['obtained']], [200, '75.5', 'unknown', 'measured'], '13-A4 סוג שטח "לא ידוע" מותר; ברירת המחדל לשטח: "נמדד"');

// ── 13-A2: rev לכל שדה, שני מכשירים על אותו ביקור ──
$r1 = $ans($noa, $V1, 'addr', 'הרצל 7');
$r2 = $ans($noa, $V1, 'addr', 'הרצל 7א', 1);
eq([$r1[1]['answer']['rev'], $r2[1]['answer']['rev']], [1, 2], '13-A2 שמירת שדה מעלה את ה-rev שלו');
$before = $arow($V1, 'addr');
$r = $ans($avi, $V1, 'addr', 'הרצל 9', 1);            // האחראי, במכשיר אחר, עם הגרסה שקרא קודם
eq([$r[0], code($r), $r[1]['error']['current']['value'] ?? null, $r[1]['error']['current']['rev'] ?? null, str_contains($r[1]['error']['message'], 'נועה'), $arow($V1, 'addr') === $before],
    [409, 'visit_changed', 'הרצל 7א', 2, true, true], '13-A2 שמירה עם rev ישן ← 409 עם הערך שבשרת ומי כתב אותו, ושום דבר לא נכתב');
eq([code($ans($avi, $V1, 'addr', 'x', 0)), code($ans($avi, $V1, 'addr', 'x', 99)), code($ans($avi, $V1, 'use', 'x', 3)), code(call('POST', 'visit-answer', ['visit' => $V1, 'field' => 'addr', 'value' => 'x'], $avi)),
    code(call('POST', 'visit-answer', ['visit' => $V1, 'field' => 'addr', 'value' => 'x', 'rev' => '2'], $avi)), $arow($V1, 'addr') === $before],
    ['visit_changed', 'visit_changed', 'rev_required', 'rev_required', 'rev_required', true], '13-A2 rev 0 על שדה שכבר קיים, rev עתידי, rev לשדה שאין לו שורה, בלי rev ו-rev כמחרוזת — נדחים');
$r = $ans($avi, $V1, 'addr', 'הרצל 9', 2);
eq([$r[0], $r[1]['answer']['rev'], $r[1]['answer']['updated_by']], [200, 3, 'אבי'], '13-A2 עם ה-rev הנוכחי — האחראי שומר, והשדה נושא את שמו');
// שני מכשירים שיוצרים את אותו שדה בבת אחת: השני מקבל 409 (UNIQUE), לא שורה כפולה
$a = $ans($noa, $V1, 'use', 'מגורים', 0, ['op' => 'op-use-00001']);
$b = $ans($avi, $V1, 'use', 'משרד', 0);
$again = $ans($noa, $V1, 'use', 'מגורים', 0, ['op' => 'op-use-00001']);     // אותה פעולה נשלחה שוב אחרי תקלת רשת
eq([$a[0], code($b), $b[1]['error']['current']['value'] ?? null, $again[0], $again[1]['answer']['rev'] ?? null, $count('visit_answers', "field_key = 'use'")], [200, 'visit_changed', 'מגורים', 200, 1, 1],
    '13-A2 שני מכשירים על שדה חדש: השני ← 409 עם הערך של הראשון; שליחה חוזרת של אותה פעולה מצליחה בלי לכתוב שוב');
eq([$raises('UPDATE visit_answers SET value_text = ? WHERE id = ?', ['x', $before['id']]), $raises('UPDATE visit_answers SET rev = rev + 2 WHERE id = ?', [$before['id']]),
    $raises('DELETE FROM visit_answers WHERE id = ?', [$before['id']]), $raises('INSERT OR REPLACE INTO visit_answers (id, visit_id, field_key, row_no, value_text, obtained, updated_at) VALUES (?, ?, ?, 0, ?, ?, ?)', [$before['id'], $before['visit_id'], 'addr', 'x', 'observed', 'x'])],
    [true, true, true, true], '13-A2 SQL ישיר: שינוי בלי rev + 1, דילוג ב-rev, מחיקה ו-INSERT OR REPLACE — נחסמים');

// ── 13-C6: מי ממלא ──
$n = [$count('visit_answers'), $arow($V1, 'addr')];
eq([code($ans($tal, $V1, 'addr', 'x', 3)), code($ans($dana, $V1, 'addr', 'x', 3)), code($ans($gil, $V1, 'addr', 'x', 3)), code($ans($boaz, $V1, 'addr', 'x', 3)), [$count('visit_answers'), $arow($V1, 'addr')] === $n],
    ['forbidden', 'forbidden', 'not_found', 'not_found', true], '13-C6 שמירת תשובה: בלי visit ← 403; עם visit ואינה המבקרת ← 403; לא משתתף / משרד אחר ← 404; דבר לא נכתב');
call('POST', 'case-member-update', ['case' => $C, 'user' => $noaPid, 'perms' => ['view' => true, 'visit' => false]], $avi);
eq([code($ans($noa, $V1, 'addr', 'x', 3)), code(photo_up($noa, $V1, jpeg_gps()))], ['forbidden', 'forbidden'], '13-C6 המבקרת בלי visit ← נדחית, גם בתמונה');
call('POST', 'case-member-update', ['case' => $C, 'user' => $noaPid, 'perms' => ['view' => true, 'visit' => true]], $avi);

// ── קבוצה חוזרת (מקרה קצה 12) ──
$r = call('POST', 'visit-row-add', ['visit' => $V1, 'group' => 'rooms'], $noa);
eq([$r[1]['row'], code(call('POST', 'visit-row-add', ['visit' => $V1, 'group' => 'main'], $noa)), code(call('POST', 'visit-row-add', ['visit' => $V1, 'group' => 'rooms'], $tal)),
    in_array('room_name', array_column($r[1]['missing'], 'field'), true)], [1, 'visit_field', 'forbidden', true], 'מקרה 12: שורה חדשה בקבוצה חוזרת — ושדה החובה שלה נדרש');
$ans($noa, $V1, 'room_name', 'סלון', 0, ['row' => 1]);
$r = $ans($noa, $V1, 'room_len', '4.2', 0, ['row' => 1, 'unit' => 'm']);
eq([$r[1]['answer']['row'], $r[1]['answer']['unit'], code($ans($noa, $V1, 'room_len', '4', 0, ['row' => 1, 'unit' => 'm'])), code($ans($noa, $V1, 'room_len', '4', 1, ['row' => 1])), code($ans($noa, $V1, 'room_name', 'x', 0, ['row' => 7]))], [1, 'm', 'visit_changed', 'fact_unit', 'visit_field'],
    'קבוצה חוזרת: התשובה נושאת מספר שורה ויחידה; אורך בלי יחידה נדחה');
call('POST', 'visit-row-add', ['visit' => $V1, 'group' => 'rooms'], $noa);
$r = call('POST', 'visit-row-remove', ['visit' => $V1, 'group' => 'rooms', 'row' => 2], $noa);
eq([(array)$r[1]['rows'], in_array('room_name', array_column($r[1]['missing'], 'field'), true)], [['rooms' => [1]], false], 'מקרה 12: שורה שהוסרה אינה נדרשת עוד');

// ── 13-A5: סימון השלמה ──
$n = [$count('facts'), $vrow($V1)['status']];
$r = $finish($noa, $V1, 'completed');
$miss = array_column($r[1]['error']['missing'] ?? [], 'why', 'field');
eq([$r[0], code($r), $miss, $r[1]['error']['can_override'] ?? null, [$count('facts'), $vrow($V1)['status']] === $n], [422, 'visit_incomplete', ['front' => 'photo'], false, true],
    '13-A5 completed עם שדה חובה גלוי ריק ← נדחה עם רשימת החסרים; שדה חובה שמוסתר בתנאי (מספר מעליות) אינו נספר; דבר לא השתנה');
$ans($noa, $V1, 'has_elev', true, 2);
$r = $finish($noa, $V1, 'completed', ['override_reason' => 'אין לי זמן']);
eq([array_column($r[1]['error']['missing'] ?? [], 'field'), code($r)], [['elev_count', 'front'], 'visit_incomplete'], '13-A5 התנאי התקיים ← השדה המותנה נדרש; נימוק אינו עוקף כשהפרופיל חוסם');
$ans($noa, $V1, 'elev_count', '2');
$ans($noa, $V1, 'has_elev', false, 3);       // מקרה קצה 4: התנאי חזר ל"לא" — התשובה נשמרת, מוסתרת, ואינה נדרשת
eq([array_column($finish($noa, $V1, 'completed')[1]['error']['missing'], 'field'), $arow($V1, 'elev_count')['value_num']], [['front'], 200], 'מקרה קצה 4: תנאי שהשתנה אחרי שהשדה מולא — התשובה נשמרת ואינה נדרשת');

// ── 13-C4: תמונה — בלי EXIF בקובץ השמור ──
$src = jpeg_gps('front');
$tmpSrc = tempnam(apr_upload_tmp_dir(), 'x');
file_put_contents($tmpSrc, $src);
$ex = @exif_read_data($tmpSrc, null, true);
eq([isset($ex['GPS']['GPSLatitude']), $ex['IFD0']['Make'] ?? null, (int)($ex['IFD0']['Orientation'] ?? 0), str_contains($src, 'SECRET-COMMENT')], [true, 'SECRETCAM', 6, true], '13-C4 הכנה: קובץ המקור מכיל GPS, יצרן, כיוון והערה');
$reads = 0;
$noRead = ['upload_reader' => function () use (&$reads) { $reads++; return ['upload_error' => 'file_missing']; }];
$nf = $count('files');
eq([code(photo_up($tal, $V1, $src, [], $noRead)), code(photo_up($dana, $V1, $src, [], $noRead)), code(photo_up($gil, $V1, $src, [], $noRead)), code(photo_up($boaz, $V1, $src, [], $noRead)),
    code(photo_up($noa, $V1, $src, ['field' => 'addr'], $noRead)), code(photo_up($noa, $V1, $src, ['field' => 'nosuch'], $noRead)), code(photo_up($noa, 'zzzzzzzzzzzzzzzz', $src, [], $noRead)), $reads, $count('files') - $nf],
    ['forbidden', 'forbidden', 'not_found', 'not_found', 'visit_field', 'visit_field', 'not_found', 0, 0], '§7 העלאה: הרשאה, ביקור ושדה נבדקים **לפני** שהגוף נקרא — הקורא לא נקרא אף פעם');
eq([code(photo_up($noa, $V1, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n")), code(photo_up($noa, $V1, "\xFF\xD8\xFF\xE1\x00")), code(photo_up($noa, $V1, 'GIF89a' . str_repeat("\0", 40))), $count('files') - $nf],
    ['photo_type', 'photo_broken', 'photo_type', 0], '§7 קובץ שאינו תמונה, או JPEG קטוע ← נדחה, ואין שורה ב-files');
$up = photo_up($noa, $V1, $src, ['field' => 'front', 'caption' => 'חזית', 'place' => 'חזית', 'lat' => '32.08', 'lng' => '34.78', 'consent' => '1']);
$PH = $up[1]['photo']['pid'];
$frow = apr_row('SELECT f.* FROM files f JOIN visit_photos p ON p.file_id = f.id WHERE p.pid = ?', [$PH]);
$stored = apr_file_path($frow['storage_key']);
$bytes = (string)file_get_contents($stored);
$ex = @exif_read_data($stored, null, true) ?: [];
eq([$up[0], isset($ex['GPS']), isset($ex['IFD0']['Make']), (int)($ex['IFD0']['Orientation'] ?? 0), str_contains($bytes, 'SECRETCAM'), str_contains($bytes, 'SECRET-COMMENT'), str_contains($bytes, 'TRAILING'),
    str_ends_with($bytes, "\xFF\xD9"), (bool)@imagecreatefromjpeg($stored), $frow['sha256'] === hash('sha256', $bytes), (int)$frow['size'] === strlen($bytes), $frow['mime']],
    [200, false, false, 6, false, false, false, true, true, true, true, 'image/jpeg'], '13-C4 הקובץ השמור: בלי GPS, בלי יצרן, בלי הערה ובלי מידע אחרי סוף התמונה; כיוון התמונה נשמר; ה-hash והגודל הם של הקובץ הנקי');
$th = call('GET', 'visit-photo', [], $noa, ['photo' => $PH, 'thumb' => '1']);
$thBytes = (string)file_get_contents($th[1]['_file']['path'] ?? '/nonexistent');
$thEx = @exif_read_data($th[1]['_file']['path'] ?? '', null, true) ?: [];
eq([isset($th[1]['_file']), isset($thEx['GPS']), str_contains($thBytes, 'SECRETCAM'), getimagesize($th[1]['_file']['path'])[0] < getimagesize($th[1]['_file']['path'])[1]], [true, false, false, true],
    '13-C4 גם התמונה הממוזערת נקייה — ומסובבת לפי הכיוון (לא שוכבת)');
$prow = apr_row('SELECT * FROM visit_photos WHERE pid = ?', [$PH]);
eq([$prow['usage'], (int)$prow['for_report'], $prow['geo_lat'], $up[1]['geo_recorded'], $frow['channel'], $frow['request_id']], ['private', 0, null, false, 'other', null],
    '§7 תמונה נולדת private, לא לדוח; §6.3 המיקום כבוי — לא נשמר גם כשנשלח');
$pu = photo_up($noa, $V1, png_text(), ['name' => 'a.png']);
$pb = (string)file_get_contents(apr_file_path(apr_val('SELECT f.storage_key FROM files f JOIN visit_photos p ON p.file_id = f.id WHERE p.pid = ?', [$pu[1]['photo']['pid']])));
eq([$pu[0], str_contains($pb, 'SECRET-PLACE'), (bool)@imagecreatefromstring($pb)], [200, false, true], '13-C4 PNG: מקטעי הטקסט מוסרים, והתמונה תקינה');
$PNG = $pu[1]['photo']['pid'];
// מיקום: רק כשהגדרת המשרד דולקת וגם המבקר הסכים
eq([code(call('POST', 'office-visit-geo', ['on' => true], $noa)), call('POST', 'office-visit-geo', ['on' => true], $avi)[1]['visit_geo'], $view($noa, $V1)[1]['geo']], ['forbidden', true, true], '§6.3 הגדרת המשרד "לצרף מיקום" — מנהל המשרד בלבד');
$g1 = photo_up($noa, $V1, jpeg_gps('g1'), ['lat' => '32.08', 'lng' => '34.78']);
$g2 = photo_up($noa, $V1, jpeg_gps('g2'), ['lat' => '32.08', 'lng' => '34.78', 'consent' => '1']);
$g3 = photo_up($noa, $V1, jpeg_gps('g3'), ['lat' => '999', 'lng' => '34.78', 'consent' => '1']);
eq([$g1[1]['photo']['geo'], $g2[1]['photo']['geo'], $g2[1]['geo_recorded'], $g3[1]['photo']['geo']], [null, ['lat' => 32.08, 'lng' => 34.78], true, null], '§6.3 מיקום נשמר רק בהסכמת המבקר; ואף פעם לא מתוך הקובץ');
call('POST', 'office-visit-geo', ['on' => false], $avi);
// העלאה חוזרת עם אותו מזהה פעולה
$k = ['key' => 'same-photo-key-1'];
$d1 = photo_up($noa, $V1, jpeg_gps('dup'), $k);
$d2 = photo_up($noa, $V1, jpeg_gps('dup'), $k);
eq([$d1[1]['photo']['pid'] === $d2[1]['photo']['pid'], $d2[1]['repeat'], $count('visit_photos', 'client_op_id = ?', ['same-photo-key-1'])], [true, true, 1], '§7 העלאה חוזרת עם אותו מזהה פעולה — אותה תמונה, לא שתיים');

// ── 13-C1: תמונה פרטית ──
$FP = $frow['pid'];
$get = fn(string $as, array $q) => call('GET', 'visit-photo', [], $as, $q);
$sees = fn(string $as) => [$get($as, ['photo' => $PH])[0], $get($as, ['photo' => $PH, 'thumb' => '1'])[0], call('GET', 'file', [], $as, ['file' => $FP])[0], call('GET', 'file-thumb', [], $as, ['file' => $FP])[0],
    in_array($PH, array_column($view($as, $V1)[1]['photos'] ?? [], 'pid'), true)];
eq([$sees($noa), $sees($avi)], [[200, 200, 200, 200, true], [200, 200, 200, 200, true]], '13-C1 תמונה private: המבקרת והאחראי רואים');
eq([$sees($tal), $sees($dana)], [[404, 404, 404, 404, false], [404, 404, 404, 404, false]], '13-C1 תמונה private ← 404 למשתתף שאינו המבקר או האחראי — גם בממוזערת, ב-file / file-thumb הכלליים, וברשימת התמונות');
$leaks = function (string $as) use ($flat, $C, $PH, $FP) {
    $all = array_merge($flat(call('GET', 'case-docs', [], $as, ['case' => $C])[1]), $flat(call('GET', 'docs-search', [], $as, ['q' => 'IMG'])[1]), $flat(call('GET', 'docs-search', [], $as, ['q' => 'IMG', 'case' => $C])[1]),
        $flat(call('GET', 'case-activity', [], $as, ['case' => $C])[1]), $flat(call('GET', 'case', [], $as, ['case' => $C])[1]), $flat(call('GET', 'case-visits', [], $as, ['case' => $C])[1]),
        $flat(call('GET', 'docs-pending', [], $as)[1]), $flat(call('GET', 'case-facts', [], $as, ['case' => $C])[1]));
    return [in_array($PH, $all, true), in_array($FP, $all, true), in_array('IMG_1.jpg', $all, true)];
};
eq([$leaks($tal), $leaks($dana)], [[false, false, false], [false, false, false]], '13-C1 המזהה והשם של תמונה פרטית אינם מופיעים ב-case-docs, בחיפוש, ביומן הפעילות, בכרטיס התיק וברשימות');
eq([$leaks($avi)[2], in_array($FP, $flat(call('GET', 'case-activity', [], $avi, ['case' => $C])[1]), true), in_array($FP, $flat(call('GET', 'case-docs', [], $avi, ['case' => $C])[1]), true)], [false, true, false],
    '§7 תמונת ביקור אינה "מסמך": גם מי שרואה אותה אינו מוצא אותה ב"קבצים שלא שויכו" או בחיפוש; ביומן הפעילות — רק מי שרשאי');
$reqPid = call('GET', 'case', [], $avi, ['case' => $C])[1]['requirements'][0]['pid'];
eq([code(call('POST', 'file-assign', ['file' => $FP, 'requirement' => $reqPid], $avi)), code(call('POST', 'file-update', ['file' => $FP, 'name' => 'x.jpg'], $avi)), code(call('POST', 'file-delete', ['file' => $FP, 'reason' => 'x'], $avi)),
    code(call('POST', 'fact-add', ['case' => $C, 'attribute' => 'actual_use', 'value' => 'x', 'source' => ['kind' => 'document', 'file' => $FP]], $tal)), apr_val('SELECT deleted_at FROM files WHERE pid = ?', [$FP])],
    ['not_found', 'not_found', 'not_found', 'source_file_required', null], '§7 הפעולות הכלליות על קבצים אינן חלות על תמונת ביקור; 13-C1 תמונה פרטית אינה מקור לנתון בידי מי שאינו רואה אותה');

// ── 13-C2: "לדוח" והרשאת שימוש ──
$pup = fn(string $as, string $photo, array $set) => call('POST', 'visit-photo-update', $set + ['photo' => $photo, 'rev' => (int)apr_val('SELECT rev FROM visit_photos WHERE pid = ?', [$photo])], $as);
eq([code($pup($tal, $PH, ['caption' => 'x'])), code($pup($dana, $PH, ['caption' => 'x'])), code($pup($gil, $PH, ['caption' => 'x'])), code($pup($boaz, $PH, ['caption' => 'x']))], ['not_found', 'not_found', 'not_found', 'not_found'],
    '13-C1 / C5 עדכון תמונה פרטית בידי מי שאינו רואה אותה ← 404');
$before = apr_row('SELECT * FROM visit_photos WHERE pid = ?', [$PH]);
eq([code($pup($noa, $PH, ['for_report' => true])), code($pup($noa, $PH, ['usage' => 'case', 'for_report' => true])), code($pup($noa, $PH, ['usage' => 'report'])), code($pup($noa, $PH, ['usage' => 'report', 'confirm_rights' => 'yes'])),
    code($pup($noa, $PH, ['usage' => 'public'])), code($pup($noa, $PH, ['for_report' => 1])), apr_row('SELECT * FROM visit_photos WHERE pid = ?', [$PH]) === $before],
    ['photo_report_usage', 'photo_report_usage', 'photo_rights', 'photo_rights', 'photo_usage', 'photo_usage', true], '13-C2 for_report עם usage שאינו report ← נדחה; usage = report בלי אישור הזכויות ← נדחה; דבר לא נכתב');
eq([$raises('UPDATE visit_photos SET for_report = 1, rev = rev + 1 WHERE pid = ?', [$PH]), $raises("UPDATE visit_photos SET usage = 'report', rev = rev + 1 WHERE pid = ?", [$PH]), $raises('DELETE FROM visit_photos WHERE pid = ?', [$PH])],
    [true, true, true], '13-C2 גם במסד: "לדוח" בלי usage = report, ו-report בלי מי שאישר — נחסמים ב-CHECK; תמונה אינה נמחקת מהמסד');
$r = $pup($noa, $PH, ['usage' => 'case']);
eq([$r[1]['photo']['usage'], $sees($tal), $sees($dana)[0], code($pup($tal, $PH, ['caption' => 'x'])), code($pup($dana, $PH, ['caption' => 'x']))], ['case', [200, 200, 200, 200, true], 200, 'forbidden', 'forbidden'],
    '§7 usage = case ← כל מי שרואה את התיק רואה את התמונה; לשנות — רק המבקר או האחראי');
$r = $pup($noa, $PH, ['usage' => 'report', 'confirm_rights' => true, 'for_report' => true]);
$prow = apr_row('SELECT p.*, u.pid AS by_pid FROM visit_photos p JOIN users u ON u.id = p.rights_by WHERE p.pid = ?', [$PH]);
eq([$r[1]['photo']['for_report'], $r[1]['photo']['usage'], $prow['by_pid'], code($pup($noa, $PH, ['usage' => 'case'])), $pup($noa, $PH, ['usage' => 'case', 'for_report' => false])[1]['photo']['for_report'] ?? null],
    [true, 'report', $noaPid, 'photo_report_usage', false], '13-C2 עם אישור הזכויות — "לדוח" מתקבל, ונרשם מי אישר; הורדת ההרשאה בלי להוריד "לדוח" ← נדחית');
$stale = call('POST', 'visit-photo-update', ['photo' => $PH, 'rev' => 1, 'caption' => 'ישן'], $noa);
eq([code($stale), isset($stale[1]['error']['current']['rev'])], ['visit_changed', true], '13-A2 גם לתמונה: rev ישן ← 409 עם המצב שבשרת');
$pup($noa, $PH, ['usage' => 'private']);

// ── 13-C3: אף פעולת portal-* אינה מחזירה תמונת ביקור ──
$GLOBALS['t_mail'] = [];
$party = apr_val('SELECT pid FROM case_parties WHERE case_id = ?', [$caseId($C)]);
$base = ['case' => $C, 'party' => $party, 'requirements' => [$reqPid], 'channel' => 'email', 'contact_ok' => true];
$pv = call('POST', 'request-preview', $base, $avi);
$sent = call('POST', 'request-send', $base + ['preview' => $pv[1]['preview'] ?? '', 'op' => 'op-visit-portal1'], $avi);
$token = preg_match('~/portal/#([A-Za-z0-9_-]{43})(?![A-Za-z0-9_-])~', end($GLOBALS['t_mail'])['text'] ?? '', $m) === 1 ? $m[1] : '';
$pup($noa, $PNG, ['usage' => 'report', 'confirm_rights' => true, 'for_report' => true]);    // גם תמונה "לדוח" אינה מגיעה לפורטל
$pngFile = apr_val('SELECT f.pid FROM files f JOIN visit_photos p ON p.file_id = f.id WHERE p.pid = ?', [$PNG]);
$portalActions = array_values(array_filter(array_keys(APR_ACCESS), fn($a) => str_starts_with($a, 'portal-')));
$portalLeak = [];
$allPids = array_merge(array_column(apr_rows('SELECT pid FROM visit_photos'), 'pid'), array_column(apr_rows('SELECT f.pid FROM files f JOIN visit_photos p ON p.file_id = f.id'), 'pid'));
foreach ($portalActions as $act) {
    foreach ([$FP, $pngFile, $PH, $PNG] as $id) {
        apr_exec("DELETE FROM login_attempts WHERE key LIKE 'portal:%' OR key LIKE 'upload:p%'");
        $r = call('POST', $act, ['token' => $token, 'file' => $id, 'photo' => $id, 'visit' => $V1, 'requirement' => $reqPid, 'inline' => true, 'kind' => 'note', 'text' => 'שלום', 'code' => '000000', 'name' => 'a.jpg'], null, [],
            ['env' => ['portal_token' => $token]]);
        if (isset($r[1]['_file']) || array_intersect($allPids, $flat($r[1])) || in_array('IMG_1.jpg', $flat($r[1]), true)) $portalLeak[] = $act;
    }
}
$open = call('POST', 'portal-open', ['token' => $token], null);
eq([$sent[0], strlen($token), $open[0], count($portalActions) >= 7, $portalLeak, call('POST', 'portal-file', ['token' => $token, 'file' => $FP, 'inline' => true], null)[0] >= 400], [200, 43, 200, true, [], true],
    '13-C3 אף פעולת portal-* אינה מחזירה תמונת ביקור — לא קובץ, לא מזהה ולא שם; portal-file על תמונה נדחה');

// ── 13-A6: "נדחה" ו"לא הייתה גישה" אינם ביקור שבוצע ──
$n = $count('facts');
$V2 = $plan($avi, $C, $noaPid)[1]['visit']['pid'];
eq([code($finish($noa, $V2, 'no_access')), code($finish($noa, $V2, 'postponed')), code($finish($noa, $V2, 'done')), code($finish($tal, $V2, 'no_access', ['note' => 'x']))], ['visit_note_required', 'visit_note_required', 'visit_result', 'forbidden'],
    '§5.4 "נדחה" ו"לא הייתה גישה" דורשים תיאור; תוצאה שאינה ברשימה נדחית');
$r = $finish($noa, $V2, 'postponed', ['note' => 'הדייר ביטל', 'scheduled_at' => '2026-11-25T09:00']);
eq([$r[1]['visit']['status'], $r[1]['visit']['done'], $r[1]['visit']['scheduled_at'], $r[1]['visit']['seq'], $count('visits', 'case_id = ?', [$caseId($C)])], ['postponed', false, '2026-11-25T09:00', 2, 2],
    '§5.4 נדחה: מועד חדש **באותה רשומה**');
$start($noa, $V2);
$ans($noa, $V2, 'addr', 'נצפה מבחוץ');
$ans($noa, $V2, 'area', '70', 0, ['unit' => 'sqm', 'area_kind' => 'built']);
$r = $finish($noa, $V2, 'no_access', ['note' => 'אף אחד לא פתח']);
$sum = call('GET', 'case', [], $avi, ['case' => $C])[1]['visits'];
eq([$r[1]['visit']['status'], $r[1]['visit']['done'], $r[1]['visit']['completed_at'], $count('facts') - $n, $count('visit_facts'), array_column(apr_visits_completed($caseId($C)), 'pid'), $sum['completed'], $sum['no_access'], $sum['last_completed_at'],
    $arow($V2, 'addr')['value_text'], $count('tasks', "origin_key = ? AND status = 'done'", ['visit:' . $V2])],
    ['no_access', false, null, 0, 0, [], 0, 1, null, 'נצפה מבחוץ', 1], '13-A6 no_access: אינו "ביקור שבוצע" בשום שאילתה, אינו יוצר facts; מה שנצפה נשמר');
eq([code(call('POST', 'fact-add', ['case' => $C, 'attribute' => 'actual_use', 'value' => 'מגורים', 'source' => ['kind' => 'visit', 'ref' => $V2]], $avi)),
    code(call('POST', 'fact-add', ['case' => $C, 'attribute' => 'actual_use', 'value' => 'מגורים', 'source' => ['kind' => 'visit', 'ref' => $V1]], $avi))], ['fact_source', 'fact_source'],
    '13-A6 ביקור שלא הייתה בו גישה, וביקור שעוד לא הושלם, אינם מקור לנתון');

// ── 13-A7: ביקור שני אינו משנה אף שורה של הראשון ──
$dump1 = fn() => hash('sha256', json_encode([apr_rows('SELECT * FROM visits WHERE pid = ?', [$V1]), apr_rows('SELECT a.* FROM visit_answers a JOIN visits v ON v.id = a.visit_id WHERE v.pid = ? ORDER BY a.id', [$V1]),
    apr_rows('SELECT p.* FROM visit_photos p JOIN visits v ON v.id = p.visit_id WHERE v.pid = ? ORDER BY p.id', [$V1]), apr_rows('SELECT r.* FROM visit_rows r JOIN visits v ON v.id = r.visit_id WHERE v.pid = ? ORDER BY r.id', [$V1])]));
$h1 = $dump1();
$V3 = $plan($avi, $C, $noaPid, ['scheduled_at' => '2026-11-22T08:00'])[1]['visit']['pid'];
$start($noa, $V3);
$ans($noa, $V3, 'addr', 'הרצל 7 — ביקור שלישי');
call('POST', 'visit-row-add', ['visit' => $V3, 'group' => 'rooms'], $noa);
photo_up($noa, $V3, jpeg_gps('v3'), ['field' => 'front']);
eq([$dump1() === $h1, $view($noa, $V3)[1]['visit']['seq'], count($view($noa, $V3)[1]['answers']), $view($noa, $V1)[1]['visit']['status']], [true, 3, 1, 'in_progress'],
    '13-A7 ביקור נוסף = רשומה חדשה שמתחילה ריקה; אף שורה של הביקור הראשון לא השתנתה (מקרה קצה 9: שני ביקורים פתוחים)');
// "העתקת תשובות מביקור N" — מפורשת, מסומנת, ואינה נחשבת מולאה עד שהמבקר מאשר
$V1h = $dump1();
$r = call('POST', 'visit-copy', ['visit' => $V3, 'from' => $V1], $noa);
$copied = array_column(array_filter($view($noa, $V3)[1]['answers'], fn($a) => $a['copied_from'] === 1), 'field');
sort($copied);
$miss3 = array_column($view($noa, $V3)[1]['missing'], 'why', 'field');
eq([$r[0], $r[1]['copied'] >= 3, in_array('area', $copied, true), in_array('addr', $copied, true), $miss3['area'] ?? null, $arow($V3, 'addr')['value_text'], $dump1() === $V1h,
    code(call('POST', 'visit-copy', ['visit' => $V3, 'from' => apr_val('SELECT pid FROM visits WHERE case_id = ?', [$caseId($B)]) ?: 'zzzzzzzzzzzzzzzz'], $noa)), code(call('POST', 'visit-copy', ['visit' => $V3, 'from' => $V1], $tal))],
    [200, true, true, false, 'copied', 'הרצל 7 — ביקור שלישי', true, 'not_found', 'forbidden'], '§5.5 העתקה: מסומן "הועתק מביקור 1 — לאמת" ונחשב חסר עד אישור; שדה שכבר מולא אינו נדרס; הביקור המקורי אינו משתנה');
$r = $ans($noa, $V3, 'area', '75.5', 1, ['unit' => 'sqm', 'area_kind' => 'built']);
eq([$r[1]['answer']['copied_from'], in_array('area', array_column($r[1]['missing'], 'field'), true)], [null, false], '§5.5 שמירה של המבקר מאשרת את מה שהועתק');
eq(call('POST', 'visit-cancel', ['visit' => $V3, 'rev' => $rev($V3), 'reason' => 'x'], $avi)[0], 409, '§5 ביקור פתוח אינו מתבטל (רק מתוכנן או נדחה)');

// ── 13-B1: מהביקור לנתונים ──
call('POST', 'fact-add', ['case' => $C, 'attribute' => 'area', 'value' => '80', 'unit' => 'sqm', 'area_kind' => 'built', 'source' => ['kind' => 'user', 'note' => 'מההסכם']], $avi);
$ans($noa, $V1, 'area', '75.5', 1, ['unit' => 'sqm', 'area_kind' => 'built']);
$ans($noa, $V1, 'mismatch', 'נראית סגירת מרפסת שאינה בתשריט', 0, ['obtained' => 'reported', 'obtained_from' => 'השכן']);
$ans($noa, $V1, 'use', 'מגורים', 1, ['obtained' => 'reported', 'obtained_from' => 'הדייר', 'note' => 'מושכר']);
$ans($noa, $V1, 'floor', '3', 1, ['obtained' => 'estimated']);
$nf = $count('facts');
$nc = $count('conflicts', "status = 'open'");
eq([code($finish($tal, $V1, 'completed')), code($finish($dana, $V1, 'completed')), $count('facts') - $nf], ['forbidden', 'forbidden', 0], '13-C6 סיום: רק המבקר או האחראי');
$stale = call('POST', 'visit-finish', ['visit' => $V1, 'rev' => 1, 'result' => 'completed'], $noa);
$r = $finish($noa, $V1, 'completed');
$vf = $facts($C, "source_kind = 'visit'");
$byAttr = array_column($vf, null, 'attribute_key');
eq([code($stale), $r[0], $r[1]['visit']['status'], $r[1]['visit']['done'], $r[1]['facts'], $r[1]['skipped'], count($vf), array_unique(array_column($vf, 'state')), array_unique(array_column($vf, 'source_ref_pid')),
    array_unique(array_column($vf, 'method')), array_filter(array_column($vf, 'verified_by')), $byAttr['area']['value_num'], $byAttr['area']['area_kind'], $byAttr['area']['source_date'],
    $byAttr['actual_use']['note'], (int)$byAttr['floor']['estimated'], (int)$byAttr['area']['estimated'], $count('visit_facts')],
    ['visit_changed', 200, 'completed', true, 3, [], 3, ['manual'], [$V1], ['manual'], [], 7550, 'built', apr_visit_local($T0, 'Y-m-d'), 'נמסר בביקור מ-הדייר. מושכר', 1, 0, 3],
    '13-B1 completed ← לכל תשובה עם maps_to נוצר fact במצב manual, source_kind = visit + ה-pid של הביקור; אף אחד אינו verified; "נמסר" ו"הוערך" מסומנים');
$fv = call('GET', 'fact', [], $avi, ['fact' => $byAttr['area']['pid']])[1]['fact']['source'];
eq([$fv['kind'], $fv['visit'] ?? null, $fv['visit_done'] ?? null, $fv['visit_seq'] ?? null], ['visit', $V1, true, 1], '13-B1 המקור של הנתון נפתח: הביקור ומצבו');
eq([array_column(apr_visits_completed($caseId($C)), 'pid'), call('GET', 'case', [], $avi, ['case' => $C])[1]['visits']['completed'], $count('tasks', "origin_key = ? AND status = 'done'", ['visit:' . $V1])], [[$V1], 1, 1],
    '13-A6 רק הביקור שהושלם נספר כ"בוצע"; משימת המבקר נסגרה');
// אידמפוטנטי
$nf = [$count('facts'), $count('visit_facts'), $count('conflicts'), $count('tasks')];
eq([code($finish($noa, $V1, 'completed')), code(call('POST', 'visit-finish', ['visit' => $V1, 'rev' => $rev($V1) - 1, 'result' => 'completed'], $noa)), [$count('facts'), $count('visit_facts'), $count('conflicts'), $count('tasks')] === $nf],
    ['visit_state', 'visit_changed', true], '13-B1 completed פעמיים ← הפעם השנייה נדחית, ואין נתונים כפולים');
eq([code($ans($noa, $V1, 'addr', 'x', 3)), code(photo_up($noa, $V1, jpeg_gps('late'))), code(call('POST', 'visit-row-add', ['visit' => $V1, 'group' => 'rooms'], $noa)),
    $raises('UPDATE visit_answers SET value_text = ?, rev = rev + 1 WHERE id = ?', ['x', $arow($V1, 'addr')['id']]), $raises("INSERT INTO visit_answers (visit_id, field_key, row_no, value_text, obtained, updated_at) VALUES (?, 'zz', 0, 'x', 'observed', 'x')", [$vrow($V1)['id']])],
    ['visit_not_open', 'visit_not_open', 'visit_not_open', true, true], '13-A7 ביקור שהושלם: תשובות, שורות ותמונות אינן נכתבות — גם לא ב-SQL ישיר');
eq([code($finish($noa, $V1, 'no_access', ['note' => 'x'])), code($finish($noa, $V1, 'postponed', ['note' => 'x'])), code(call('POST', 'visit-cancel', ['visit' => $V1, 'rev' => $rev($V1), 'reason' => 'x'], $avi)),
    $raises("UPDATE visits SET status = 'no_access', status_note = 'x', rev = rev + 1 WHERE pid = ?", [$V1]), $raises('UPDATE visits SET first_completed_at = NULL, rev = rev + 1 WHERE pid = ?', [$V1]), $vrow($V1)['status']],
    ['visit_state', 'visit_state', 'visit_state', true, true, 'completed'], '13-A6 ביקור שהושלם אינו הופך ל"נדחה", ל"לא הייתה גישה" או ל"בוטל" — גם לא ב-SQL ישיר');

// ── 13-B2: שטח שנמדד ושונה משטח קיים ← סתירה, במנגנון של פרוסה 10 ──
$cf = apr_rows("SELECT c.*, (SELECT COUNT(*) FROM conflict_facts x WHERE x.conflict_id = c.id) AS n FROM conflicts c WHERE c.case_id = ? AND c.attribute_key = 'area' AND c.status = 'open'", [$caseId($C)]);
eq([$count('conflicts', "status = 'open'") - $nc, count($cf), $cf[0]['scope'] ?? null, (int)($cf[0]['n'] ?? 0), $cf[0]['task_id'] !== null, call('GET', 'case', [], $avi, ['case' => $C])[1]['open_conflicts']], [1, 1, 'built|area', 2, true, 1],
    '13-B2 שטח שנמדד בביקור (75.5) ושונה משטח קיים מאותו סוג (80) ← נפתחת סתירה אחת, עם משימת בירור');

// ── 13-B3: "אי התאמה אפשרית" ← משימה; אין שדה בוליאני של עבירה ──
$t = apr_rows("SELECT * FROM tasks WHERE case_id = ? AND origin_key LIKE 'visit-finding:%'", [$caseId($C)]);
$cols = [];
foreach (['visits', 'visit_answers', 'visit_photos', 'visit_rows', 'visit_facts', 'office_visit_settings'] as $tb) foreach (apr_rows("PRAGMA table_info($tb)") as $col) $cols[] = $col['name'];
$bad = '/violation|illegal|offen[cs]e|unlawful|infring|עביר|חוקי|חריגת/iu';
$resp = array_merge($flat($view($avi, $V1)[1]), $flat(call('GET', 'case-visits', [], $avi, ['case' => $C])[1]), $flat($r[1]));
eq([count($t), $t[0]['kind'], $t[0]['link_type'], $t[0]['link_pid'], str_contains((string)$t[0]['detail'], 'סגירת מרפסת'), str_contains((string)$t[0]['detail'], 'אינה קביעה'), $r[1]['tasks'],
    preg_grep($bad, $cols), preg_grep($bad, array_filter($resp, fn($s) => !str_contains($s, 'סגירת מרפסת'))), preg_grep($bad, array_keys(apr_visit_default_form()['fields'][0])), preg_grep($bad, array_column(apr_visit_default_form()['fields'], 'key'))],
    [1, 'clarification', 'visit', $V1, true, true, 1, [], [], [], []], '13-B3 "אי התאמה אפשרית" יוצרת משימת בדיקה עם הטקסט; אין שום עמודה, שדה או מפתח של עבירה — במסד, בתשובות ובטופס ברירת המחדל');

// ── פתיחה מחדש (§5.6, §8): מי שאינו שמאי אינו משנה קביעה של שמאי, גם בעקיפין ──
$areaFact = $byAttr['area'];
$ver = call('POST', 'fact-state', ['fact' => $areaFact['pid'], 'rev' => (int)$areaFact['rev'], 'do' => 'verify'], $avi);
eq([$ver[0], apr_val('SELECT state FROM facts WHERE id = ?', [$areaFact['id']])], [200, 'verified'], 'הכנה: השמאי מאמת את השטח שנמדד בביקור');
eq([code(call('POST', 'visit-reopen', ['visit' => $V1, 'rev' => $rev($V1), 'reason' => 'x'], $tal)), code(call('POST', 'visit-reopen', ['visit' => $V1, 'rev' => $rev($V1), 'reason' => 'x'], $dana)),
    code(call('POST', 'visit-reopen', ['visit' => $V1, 'rev' => $rev($V1)], $noa)), code(call('POST', 'visit-reopen', ['visit' => $V2, 'rev' => 1, 'reason' => 'x'], $noa))],
    ['forbidden', 'forbidden', 'reason_required', 'visit_changed'], '§5.6 פתיחה מחדש: המבקר או האחראי, בנימוק, עם rev נוכחי');
$r = call('POST', 'visit-reopen', ['visit' => $V1, 'rev' => $rev($V1), 'reason' => 'נמדד שוב'], $noa);
$src = call('GET', 'fact', [], $avi, ['fact' => $areaFact['pid']])[1]['fact']['source'];
eq([$r[1]['visit']['status'], $r[1]['visit']['done'], $src['visit_done'], array_column(apr_visits_completed($caseId($C)), 'pid'), $vrow($V1)['form_snapshot'] === $snap], ['in_progress', false, false, [], true],
    '§5.6 ביקור שנפתח מחדש אינו "ביקור שבוצע"; הנתון שנוצר ממנו מציג זאת; הטופס שצולם נשאר');
$nf = $count('facts');
$again = $finish($noa, $V1, 'completed');
eq([$again[0], $again[1]['facts'], $count('facts') - $nf, $count('visit_facts')], [200, 0, 0, 3], '13-B1 השלמה חוזרת בלי שינוי: אין נתון חדש ואין עדכון');
call('POST', 'visit-reopen', ['visit' => $V1, 'rev' => $rev($V1), 'reason' => 'תיקון מדידה'], $noa);
$ans($noa, $V1, 'area', '77', (int)$arow($V1, 'area')['rev'], ['unit' => 'sqm', 'area_kind' => 'built']);
$ans($noa, $V1, 'floor', '4', (int)$arow($V1, 'floor')['rev']);
$r = $finish($noa, $V1, 'completed');
$old = apr_row('SELECT * FROM facts WHERE id = ?', [$areaFact['id']]);
$new = apr_row("SELECT f.* FROM visit_facts vf JOIN facts f ON f.id = vf.fact_id JOIN visits v ON v.id = vf.visit_id WHERE v.pid = ? AND vf.field_key = 'area'", [$V1]);
$floor = apr_row("SELECT * FROM facts WHERE case_id = ? AND attribute_key = 'floor' AND source_kind = 'visit'", [$caseId($C)]);
eq([$r[0], $old['state'], (int)$old['value_num'], $old['verified_by'] !== null, (int)$old['rev'] === (int)$areaFact['rev'] + 1, $new['id'] !== $old['id'], $new['state'], (int)$new['value_num'], $new['source_ref_pid'],
    (int)$floor['value_num'], (int)$floor['original_num'], $floor['state'], $count('facts', "case_id = ? AND attribute_key = 'floor'", [$caseId($C)])],
    [200, 'verified', 7550, true, true, true, 'manual', 7700, $V1, 400, 300, 'manual', 1],
    '§8 / 09-C4 מתמחה שמשלימה מחדש עם ערך אחר: הנתון **המאומת אינו משתנה** — נוצר נתון חדש (manual) והסתירה נפתחת; נתון לא מאומת מתעדכן עם שמירת הערך המקורי');
eq((int)apr_val("SELECT COUNT(*) FROM conflict_facts x JOIN conflicts c ON c.id = x.conflict_id WHERE c.case_id = ? AND c.attribute_key = 'area' AND c.status = 'open'", [$caseId($C)]), 3, '13-B2 הנתון החדש מצטרף לסתירה הפתוחה (80 / 75.5 / 77)');
// שמאי שמשלים מחדש — מעדכן גם נתון מאומת, והאימות יורד
$nv = call('POST', 'fact-state', ['fact' => $new['pid'], 'rev' => (int)$new['rev'], 'do' => 'verify'], $avi);
call('POST', 'visit-reopen', ['visit' => $V1, 'rev' => $rev($V1), 'reason' => 'האחראי מתקן'], $avi);
$ans($avi, $V1, 'area', '78', (int)$arow($V1, 'area')['rev'], ['unit' => 'sqm', 'area_kind' => 'built']);
$nf = $count('facts');
$finish($avi, $V1, 'completed');
$after = apr_row('SELECT * FROM facts WHERE id = ?', [$new['id']]);
eq([$nv[0], $after['state'], (int)$after['value_num'], (int)$after['original_num'], $after['verified_by'], $count('facts') - $nf], [200, 'manual', 7800, 7700, null, 0], '§8 שמאי שרשאי לאמת משלים מחדש: הנתון המאומת מתעדכן, חוזר ל-manual, והערך המקורי נשמר');
// תשובה שהוסתרה / נוקתה אינה עוברת; נתון שנדחה בידי שמאי אינו קם לתחייה
$useFact = apr_row("SELECT * FROM facts WHERE case_id = ? AND attribute_key = 'actual_use' AND source_kind = 'visit'", [$caseId($C)]);
call('POST', 'fact-state', ['fact' => $useFact['pid'], 'rev' => (int)$useFact['rev'], 'do' => 'reject', 'reason' => 'לא נכון'], $avi);
call('POST', 'visit-reopen', ['visit' => $V1, 'rev' => $rev($V1), 'reason' => 'עוד תיקון'], $noa);
$nf = $count('facts');
$r = $finish($noa, $V1, 'completed');
eq([$r[0], $count('facts') - $nf, apr_val('SELECT state FROM facts WHERE id = ?', [$useFact['id']])], [200, 0, 'rejected'], '09-C4 נתון מביקור שהשמאי דחה אינו נוצר מחדש בהשלמה חוזרת עם אותה תשובה');

// ── מרוץ: שישה תהליכים מסמנים "הושלם" באותו רגע ──
$V4 = $plan($avi, $D, $aviPid)[1]['visit']['pid'];
$start($avi, $V4);
$fill($avi, $V4, '60');
$ans($avi, $V4, 'floor', '2');
$nf = $count('facts');
$go = microtime(true) + 0.6;
$procs = $pipes = [];
for ($i = 0; $i < 6; $i++) $procs[] = proc_open([PHP_BINARY, __FILE__, 'finisher', $dir, $V4, (string)$go, (string)$rev($V4), (string)$T0], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
$outs = [];
foreach ($procs as $i => $p) { $outs[] = trim((string)stream_get_contents($pipes[$i][1])); proc_close($p); }
sort($outs);
eq([array_count_values($outs)['200'] ?? 0, count(array_filter($outs, fn($o) => $o === '409')), $count('facts') - $nf, $count('visit_facts', 'visit_id = ?', [$vrow($V4)['id']]), $count('audit_log', "action = 'visit.completed' AND entity_pid = ?", [$V4])],
    [1, 5, 2, 2, 1], '13-B1 מרוץ: שישה תהליכים מסמנים "הושלם" יחד ← אחד מצליח, חמישה 409, והנתונים נוצרים פעם אחת');

// ── השלמה עם נימוק, כשהפרופיל אינו חוסם ──
$P2 = make_profile($avi, visit_form(['completion' => ['block_on_required' => false]]));
$E = make_case($avi, $P2);
$V5 = $plan($avi, $E, $aviPid)[1]['visit']['pid'];
$start($avi, $V5);
$r1 = $finish($avi, $V5, 'completed');
$r2 = $finish($avi, $V5, 'completed', ['override_reason' => 'אין גישה לחזית — בניין בשיפוץ']);
eq([code($r1), $r1[1]['error']['can_override'] ?? null, count($r1[1]['error']['missing'] ?? []), $r2[0], $r2[1]['visit']['status'], $r2[1]['visit']['status_note'], $r2[1]['facts']], ['visit_incomplete', true, 3, 200, 'completed', 'אין גישה לחזית — בניין בשיפוץ', 0],
    '§5.4 block_on_required כבוי: בלי נימוק ← רשימת החסרים; עם נימוק ← הושלם, והנימוק נשמר (מקרה קצה 8: בלי תמונות)');

// ── מקרה קצה 11: no_access ואז ביקור מוצלח — שתי רשומות ──
$V6 = $plan($avi, $E, $aviPid)[1]['visit']['pid'];
$finish($avi, $V6, 'no_access', ['note' => 'נעול']);
$list = array_column(call('GET', 'case-visits', [], $avi, ['case' => $E])[1]['visits'], 'done', 'status');
eq([$list, array_column(apr_visits_completed($caseId($E)), 'pid')], [['no_access' => false, 'completed' => true], [$V5]], 'מקרה קצה 11: שתי רשומות; רק אחת "הושלם"');
eq([call('POST', 'visit-reopen', ['visit' => $V6, 'rev' => $rev($V6), 'reason' => 'חזרנו ונכנסנו'], $avi)[1]['visit']['status'], $vrow($V6)['form_snapshot'] !== null], ['in_progress', true], '§5.6 "לא הייתה גישה" שנפתח מחדש מצלם את הטופס');

// ── מחיקת תמונה (מקרה קצה 10) ──
$delRev = (int)apr_val('SELECT rev FROM visit_photos WHERE pid = ?', [$PNG]);
eq([code(call('POST', 'visit-photo-delete', ['photo' => $PNG, 'rev' => $delRev], $tal)), code(call('POST', 'visit-photo-delete', ['photo' => $PNG, 'rev' => 1], $noa))], ['forbidden', 'visit_changed'], 'מחיקת תמונה: רק המבקר או האחראי, עם rev נוכחי');
$r = call('POST', 'visit-photo-delete', ['photo' => $PNG, 'rev' => $delRev], $noa);
$prow = apr_row('SELECT * FROM visit_photos WHERE pid = ?', [$PNG]);
eq([$r[0], $prow['deleted_at'] !== null, (int)$prow['for_report'], $get($noa, ['photo' => $PNG])[0], $get($avi, ['photo' => $PNG, 'thumb' => '1'])[0], call('GET', 'file', [], $avi, ['file' => $pngFile])[0],
    in_array($PNG, array_column($view($avi, $V1)[1]['photos'], 'pid'), true), $raises('UPDATE visit_photos SET deleted_at = NULL, rev = rev + 1 WHERE pid = ?', [$PNG])],
    [200, true, 0, 404, 404, 404, false, true], 'מקרה קצה 10: מחיקה רכה — התמונה אינה מוגשת עוד לאיש, יורדת מ"לדוח", ואינה חוזרת');
$ord = array_column($view($noa, $V1)[1]['photos'], 'pid');
$r = call('POST', 'visit-photo-order', ['visit' => $V1, 'order' => array_reverse($ord)], $noa);
eq([array_column($r[1]['photos'], 'pid'), code(call('POST', 'visit-photo-order', ['visit' => $V1, 'order' => [$PNG]], $noa)), code(call('POST', 'visit-photo-order', ['visit' => $V1, 'order' => $ord], $tal))], [array_reverse($ord), 'not_found', 'forbidden'],
    '§7 סדר התמונות: המבקר או האחראי; תמונה שנמחקה או שאינה של הביקור ← 404');

// ── 13-C5: בידוד — משרד אחר, לא משתתף, ומזהי בן ──
$VB = $plan($boaz, $B, call('GET', 'me', [], $boaz)[1]['user']['pid'])[1]['visit']['pid'];
$start($boaz, $VB);
$PB = photo_up($boaz, $VB, jpeg_gps('b'))[1]['photo']['pid'];
$FB = apr_val('SELECT f.pid FROM files f JOIN visit_photos p ON p.file_id = f.id WHERE p.pid = ?', [$PB]);
$none = 'zzzzzzzzzzzzzzzz';
$probe = fn(string $as, string $visit, string $photo, string $file) => [
    $view($as, $visit)[0], call('GET', 'case-visits', [], $as, ['case' => $B])[0], $get($as, ['photo' => $photo])[0], $get($as, ['photo' => $photo, 'thumb' => '1'])[0], call('GET', 'file', [], $as, ['file' => $file])[0],
    call('POST', 'visit-answer', ['visit' => $visit, 'field' => 'addr', 'rev' => 0, 'value' => 'x'], $as)[0], call('POST', 'visit-start', ['visit' => $visit, 'rev' => 1], $as)[0],
    call('POST', 'visit-update', ['visit' => $visit, 'rev' => 1, 'attendees' => 'x'], $as)[0], call('POST', 'visit-finish', ['visit' => $visit, 'rev' => 1, 'result' => 'completed'], $as)[0],
    call('POST', 'visit-reopen', ['visit' => $visit, 'rev' => 1, 'reason' => 'x'], $as)[0], call('POST', 'visit-cancel', ['visit' => $visit, 'rev' => 1, 'reason' => 'x'], $as)[0],
    call('POST', 'visit-row-add', ['visit' => $visit, 'group' => 'rooms'], $as)[0], call('POST', 'visit-row-remove', ['visit' => $visit, 'group' => 'rooms', 'row' => 1], $as)[0],
    call('POST', 'visit-copy', ['visit' => $visit, 'from' => $visit], $as)[0], call('POST', 'visit-photo-update', ['photo' => $photo, 'rev' => 1, 'caption' => 'x'], $as)[0],
    call('POST', 'visit-photo-delete', ['photo' => $photo, 'rev' => 1], $as)[0], call('POST', 'visit-photo-order', ['visit' => $visit, 'order' => [$photo]], $as)[0], photo_up($as, $visit, jpeg_gps('x'))[0]];
$all404 = array_fill(0, 18, 404);
$hb = hash('sha256', json_encode([apr_rows('SELECT * FROM visits WHERE pid = ?', [$VB]), apr_rows('SELECT * FROM visit_photos WHERE pid = ?', [$PB])]));
eq([$probe($avi, $VB, $PB, $FB), $probe($avi, $none, $none, $none), $probe($gil, $V1, $PH, $FP), hash('sha256', json_encode([apr_rows('SELECT * FROM visits WHERE pid = ?', [$VB]), apr_rows('SELECT * FROM visit_photos WHERE pid = ?', [$PB])])) === $hb],
    [$all404, $all404, $all404, true], '13-C5 בידוד: ביקור, תשובות ותמונות של משרד אחר, של מזהה שלא קיים, ושל תיק שאינני משתתף בו ← אותו 404 ב-18 פעולות; דבר לא השתנה');
// מזהי בן חוצי תיק, בתוך אותו משרד
eq([code(call('POST', 'fact-add', ['case' => $D, 'attribute' => 'actual_use', 'value' => 'מגורים', 'source' => ['kind' => 'visit', 'ref' => $V1]], $avi)),
    call('POST', 'fact-add', ['case' => $C, 'attribute' => 'actual_use', 'value' => 'מגורים', 'source' => ['kind' => 'visit', 'ref' => $V1]], $avi)[0],
    code(call('POST', 'fact-add', ['case' => $C, 'attribute' => 'actual_use', 'value' => 'מגורים', 'source' => ['kind' => 'visit', 'ref' => $V4]], $avi)),
    code(call('POST', 'fact-add', ['case' => $C, 'attribute' => 'actual_use', 'value' => 'מגורים', 'source' => ['kind' => 'visit', 'ref' => $none]], $avi)),
    code(call('POST', 'visit-copy', ['visit' => $V3, 'from' => $V4], $noa)), code($plan($avi, $C, $noaPid, ['access_contact' => apr_val('SELECT pid FROM contacts WHERE office_id <> (SELECT office_id FROM cases WHERE pid = ?) LIMIT 1', [$C])]))],
    ['fact_source', 200, 'fact_source', 'fact_source', 'not_found', 'not_found'], '13-C5 מזהה בן מתיק אחר: ביקור של תיק אחר כמקור לנתון ← נדחה (וביקור של התיק הזה מתקבל); העתקה מביקור של תיק אחר ואיש קשר של משרד אחר ← 404');

// ── המבקר הוסר מהתיק (מקרה קצה 7), ותיק סגור ──
$n = $count('visit_answers');
$keep = $arow($V3, 'addr');
call('POST', 'case-member-remove', ['case' => $C, 'user' => $noaPid], $avi);
eq([code($ans($noa, $V3, 'use', 'x')), $view($noa, $V3)[0], code(photo_up($noa, $V3, jpeg_gps('rm'))), $count('visit_answers') - $n, $arow($V3, 'addr') === $keep, call('GET', 'my-visits', [], $noa)[1]['visits']],
    ['not_found', 404, 'not_found', 0, true, []], 'מקרה קצה 7: המבקר הוסר מהתיק — השמירה הבאה נדחית, ומה שנשמר נשאר');
call('POST', 'case-member-add', ['case' => $C, 'user' => $noaPid], $avi);
$st = call('POST', 'case-status', ['case' => $C, 'status' => 'closed', 'reason' => 'סיום'], $avi);
$h = hash('sha256', json_encode([apr_rows('SELECT * FROM visits ORDER BY id'), apr_rows('SELECT * FROM visit_answers ORDER BY id'), apr_rows('SELECT * FROM visit_photos ORDER BY id')]));
eq([$st[0], code($ans($noa, $V3, 'use', 'x')), code($plan($avi, $C, $noaPid)), code($finish($noa, $V3, 'completed')), code(photo_up($noa, $V3, jpeg_gps('cl'))), code($pup($noa, $PH, ['caption' => 'x'])),
    code(call('POST', 'visit-reopen', ['visit' => $V1, 'rev' => $rev($V1), 'reason' => 'x'], $avi)), code(call('POST', 'visit-update', ['visit' => $V3, 'rev' => $rev($V3), 'attendees' => 'x'], $avi)),
    $view($noa, $V3)[0], $view($noa, $V3)[1]['perms'], hash('sha256', json_encode([apr_rows('SELECT * FROM visits ORDER BY id'), apr_rows('SELECT * FROM visit_answers ORDER BY id'), apr_rows('SELECT * FROM visit_photos ORDER BY id')])) === $h],
    [200, 'case_closed', 'case_closed', 'case_closed', 'case_closed', 'case_closed', 'case_closed', 'case_closed', 200, ['plan' => false, 'fill' => false], true], 'תיק סגור = קריאה בלבד: כל פעולה משנה ← 409, והצפייה נשארת');

// ── בדיקות הפרסום של מקטע "ביקור" (§4) ──
$chk = function (array $form): array {
    $r = apr_profile_checks(apr_profile_content_clean(['visit' => $form]), apr_doc_type_keys());
    return [array_values(array_unique(array_filter(array_column($r['errors'], 'code'), fn($c) => str_starts_with($c, 'visit') || in_array($c, ['duplicate_key', 'unknown_group', 'no_title'], true)))),
        array_values(array_filter(array_column($r['warnings'], 'code'), fn($c) => str_starts_with($c, 'visit')))];
};
$g = [['key' => 'g', 'title' => 'ק', 'sort' => 1], ['key' => 'rep', 'title' => 'ח', 'sort' => 2, 'repeat' => true]];
$fwd = ['field' => 'answer.b', 'op' => 'is', 'value' => true];
eq([$chk(visit_form()), $chk(['groups' => $g, 'fields' => [vfield('a', 'g', 'text', 1), vfield('a', 'g', 'text', 2)]])[0], $chk(['groups' => $g, 'fields' => [vfield('a', 'g', 'text', 1, ['when' => $fwd]), vfield('b', 'g', 'yesno', 2)]])[0],
    $chk(['groups' => $g, 'fields' => [vfield('a', 'g', 'text', 1, ['when' => ['field' => 'answer.zz', 'op' => 'is', 'value' => 'x']])]])[0], $chk(['groups' => $g, 'fields' => [vfield('a', 'g', 'text', 1, ['when' => ['field' => 'answer.a', 'op' => 'is', 'value' => 'x']])]])[0],
    $chk(['groups' => $g, 'fields' => [vfield('a', 'g', 'area', 1, ['maps_to' => 'no_such_attr'])]])[0], $chk(['groups' => $g, 'fields' => [vfield('a', 'g', 'text', 1, ['maps_to' => 'area'])]])[0],
    $chk(['groups' => $g, 'fields' => [vfield('a', 'rep', 'area', 1, ['maps_to' => 'area'])]])[0], $chk(['groups' => $g, 'fields' => [vfield('a', 'g', 'area', 1)]]), $chk(['groups' => $g, 'fields' => [vfield('a', 'nog', 'text', 1)]])[0],
    $chk(['groups' => $g, 'fields' => [vfield('a', 'g', 'choice', 1)]])[0], $chk(apr_visit_default_form())[0], apr_visit_default_form()['example']],
    [[[], []], ['duplicate_key'], ['visit_when_order'], ['visit_when_unknown'], ['visit_when_order'], ['visit_maps_unknown'], ['visit_maps_type'], ['visit_maps_repeat'], [[], ['visit_area_unmapped']], ['unknown_group'], ['visit_no_options'], [], true],
    '§4 בדיקות הפרסום: מפתח כפול ❌; when לשדה שלא קיים או שמופיע אחריו ❌; maps_to למאפיין לא קיים / לא מתאים / בקבוצה חוזרת ❌; שדה area בלי maps_to ⚠️; טופס ברירת המחדל (דוגמה) עובר');
$badForm = fn(array $f) => code(call('POST', 'profile-draft-save', ['profile' => $P, 'content' => ['visit' => $f], 'revision' => call('POST', 'profile-draft-open', ['profile' => $P], $avi)[1]['draft']['revision']], $avi));
eq([$badForm(['groups' => $g, 'fields' => [vfield('a', 'g', 'money', 1)]]), $badForm(['groups' => $g, 'fields' => [vfield("a\n", 'g', 'text', 1)]]), $badForm(['groups' => $g, 'fields' => [vfield('a', 'g', 'text', 1, ['when' => ['field' => 'answer.A b', 'op' => 'is', 'value' => 'x']])]]),
    $badForm(['groups' => $g, 'fields' => [vfield('a', 'g', 'text', 1, ['maps_to' => "area\n"])]]), $badForm(['groups' => [['key' => 'g', 'title' => 'x', 'repeat' => true, 'min_rows' => 99]], 'fields' => []])],
    ['content_invalid', 'content_invalid', 'condition_invalid', 'content_invalid', 'content_invalid'], '§4 שמירת טיוטה: סוג שדה לא מוכר, מפתח עם ירידת שורה, תנאי לא תקין — נדחים');
$pubBad = publish($avi, $P, ['groups' => $g, 'fields' => [vfield('a', 'g', 'text', 1), vfield('a', 'g', 'text', 2)]]);
eq([$pubBad[0], in_array('duplicate_key', array_column($pubBad[1]['error']['checks']['errors'] ?? [], 'code'), true)], [422, true], '§4 פרסום עם שני שדות באותו מפתח ← נחסם');
call('POST', 'profile-draft-discard', ['profile' => $P], $avi);
$old = apr_profile_content_read('{"schema":1,"flags":[],"requirements":{"groups":[],"items":[]},"tasks":{"items":[]},"rules":[]}');
eq([count($old['visit']['groups']), $old['visit']['example']], [7, true], '§4 פרופיל שנוצר לפני הפרוסה מקבל את טופס ברירת המחדל (שש הקבוצות + חדרים), מסומן "דוגמה"');

// ── min_rows בקבוצה חוזרת (מקרה קצה 12) ──
$fm = visit_form();
$fm['groups'][1]['min_rows'] = 1;
$fm['fields'] = array_values(array_filter($fm['fields'], fn($f) => !in_array($f['key'], ['addr', 'area', 'front'], true)));
$F = make_case($avi, make_profile($avi, $fm));
$V7 = $plan($avi, $F, $aviPid)[1]['visit']['pid'];
$start($avi, $V7);
$m1 = array_column($finish($avi, $V7, 'completed')[1]['error']['missing'] ?? [], 'why');
call('POST', 'visit-row-add', ['visit' => $V7, 'group' => 'rooms'], $avi);
$m2 = array_column($finish($avi, $V7, 'completed')[1]['error']['missing'] ?? [], 'field');
$ans($avi, $V7, 'room_name', 'חדר', 0, ['row' => 1]);
eq([$m1, $m2, $finish($avi, $V7, 'completed')[0]], [['min_rows'], ['room_name'], 200], 'מקרה קצה 12: קבוצה חוזרת עם min_rows — בלי שורות חסר; עם שורה — שדה החובה שלה נדרש');

// ── עדכון פרטי ביקור ──
$V8 = $plan($avi, $F, $aviPid)[1]['visit']['pid'];
call('POST', 'case-member-add', ['case' => $F, 'user' => $noaPid], $avi);
$r = call('POST', 'visit-update', ['visit' => $V8, 'rev' => 1, 'visitor' => $noaPid, 'scheduled_at' => '2026-12-01T14:30'], $avi);
$t8 = apr_row("SELECT t.*, u.pid AS upid FROM tasks t JOIN users u ON u.id = t.assignee_user_id WHERE t.origin_key = ? AND t.status = 'open'", ['visit:' . $V8]);
eq([$r[1]['visit']['visitor']['pid'], $t8['upid'], $t8['due_at'], code(call('POST', 'visit-update', ['visit' => $V8, 'rev' => 2, 'visitor' => $aviPid], $noa)), call('POST', 'visit-update', ['visit' => $V8, 'rev' => 2, 'attendees' => 'בעל הדירה'], $noa)[1]['visit']['attendees'],
    code(call('POST', 'visit-update', ['visit' => $V8, 'rev' => 2, 'attendees' => 'x'], $avi)), code(call('POST', 'visit-update', ['visit' => $V8, 'rev' => 3], $avi))],
    [$noaPid, $noaPid, '2026-12-01T00:00:00Z', 'forbidden', 'בעל הדירה', 'visit_changed', 'nothing_to_change'], '§10 שינוי מבקר ומועד — edit בתיק (המשימה עוברת למבקר החדש); נוכחים — גם המבקר; rev ישן ← 409');
$r = call('POST', 'visit-cancel', ['visit' => $V8, 'rev' => $rev($V8), 'reason' => 'הלקוח ביטל'], $avi);
eq([$r[1]['visit']['status'], $r[1]['visit']['done'], apr_val('SELECT status FROM tasks WHERE origin_key = ?', ['visit:' . $V8]), code($start($noa, $V8))], ['cancelled', false, 'cancelled', 'visit_state'], '§5 ביטול ביקור שלא התקיים: המשימה מתבטלת, והביקור אינו נפתח');

// ── מיגרציה 011 על מסד v10 מאוכלס ──
$m10 = sys_get_temp_dir() . '/apr-m10-' . bin2hex(random_bytes(5));
mkdir($m10, 0700, true);
foreach (glob(__DIR__ . '/../lib/migrations/*.sql') as $mf) if ((int)basename($mf) <= 10) copy($mf, $m10 . '/' . basename($mf));
$pdoOpt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$v10 = new PDO("sqlite:$dir/v10.sqlite", null, null, $pdoOpt);
apr_migrate($v10, $m10);
$v10->exec('PRAGMA foreign_keys = OFF');
$v10->exec('ATTACH ' . $v10->quote("$dir/app.sqlite") . ' AS live');
$tables = array_column($v10->query("SELECT name FROM main.sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name <> 'schema_migrations'")->fetchAll(), 'name');
foreach ($tables as $t) {
    // sources: שורות הקטלוג אינן נמחקות (טריגר של 010) — מעדכנים במקום, לפי המפתח
    try { $v10->exec("DELETE FROM main.\"$t\""); } catch (PDOException) { continue; }
    $v10->exec("INSERT INTO main.\"$t\" SELECT * FROM live.\"$t\"");
}
$v10->exec('DETACH live');
$v10->exec('PRAGMA foreign_keys = ON');
$dump = fn(PDO $d) => array_map(fn($t) => [$t, hash('sha256', json_encode($d->query("SELECT * FROM \"$t\" ORDER BY 1")->fetchAll()))], $tables);
$d10 = $dump($v10);
$rows10 = array_sum(array_map(fn($t) => (int)$v10->query("SELECT COUNT(*) FROM \"$t\"")->fetchColumn(), $tables));
$had = [apr_schema_version($v10), in_array('visits', $tables, true), (int)$v10->query('SELECT COUNT(*) FROM facts')->fetchColumn() > 3, (int)$v10->query('SELECT COUNT(*) FROM files')->fetchColumn() > 3];
apr_migrate($v10);
$freshDb = new PDO('sqlite::memory:', null, null, $pdoOpt);
apr_migrate($freshDb);
$shape = fn(PDO $d) => json_encode($d->query("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' AND name <> 'schema_migrations' ORDER BY name")->fetchAll());
eq([apr_schema_version($v10), apr_schema_expected(), $had, $rows10 > 150, $dump($v10) === $d10, $shape($v10) === $shape($freshDb), (int)$v10->query('SELECT COUNT(*) FROM visits')->fetchColumn(), count($v10->query('PRAGMA foreign_key_check')->fetchAll()),
    trim((string)shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' diff --stat origin/main -- lib/migrations/00[1-9]_*.sql lib/migrations/010_*.sql 2>/dev/null'))],
    [11, 11, [10, false, true, true], true, true, true, 0, 0, ''], 'מיגרציה 011 על מסד v10 מאוכלס: כל שורה בכל טבלה קיימת נשמרת כפי שהיא, המבנה זהה למסד חדש, אין ביקורים; 001–010 לא נגעו');
$v10 = null;
$rm = function (string $d) use (&$rm) { foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $f) is_dir("$d/$f") ? $rm("$d/$f") : @unlink("$d/$f"); @rmdir($d); };
$rm($m10);

eq($warnings, [], 'אין אזהרות PHP בכל הקובץ');
done();
