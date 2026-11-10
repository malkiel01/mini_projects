<?php
// פרוסה 07 — בקשות ופורטל לנמען (docs/spec/07-requests-portal.md §13): 07-A1 … 07-C4, מקרי הקצה, ההרשאות, הבידוד וההגנות על הפורטל.
require __DIR__ . '/lib.php';

$warnings = [];
$GLOBALS['apr_upload_rate'] = [100000, 600];
set_error_handler(function (int $no, string $msg, string $file, int $line) use (&$warnings) { if (!(error_reporting() & $no)) return true; $warnings[] = "$msg @" . basename($file) . ":$line"; return true; });
// המיילים של הבדיקה — עם שם השולח וכתובת התשובה
$GLOBALS['t_mail_ok'] = true;
$GLOBALS['apr_mail'] = function (string $to, string $subject, string $text, array $opt = []) {
    if (!$GLOBALS['t_mail_ok']) return false;
    $GLOBALS['t_mail'][] = ['to' => $to, 'subject' => $subject, 'text' => $text] + $opt;
    return true;
};

$owner = ident('1', 'הבעלים', 'owner@example.com', ['platform_admin' => true]);
$avi = ident('2', 'אבי', 'avi@example.com');       // מנהל משרד א, שמאי — האחראי על התיקים
$tal = ident('5', 'טל', 'tal@example.com');        // עובדת משרד: contact, בלי review_docs
$gil = ident('7', 'גיל', 'gil@example.com');       // שמאי במשרד א שאינו משתתף בתיקים
$ron = ident('8', 'רון', 'ron@example.com');       // מבקר: view בלבד
$boaz = ident('4', 'בועז', 'boaz@example.com');    // משרד ב
call('GET', 'me', [], $owner);
$OA = office_for($avi, 'משרד אבי שמאים');
office_for($boaz, 'משרד ב');
$talPid = join_office($avi, $tal, 'tal@example.com', 'staff');
join_office($avi, $gil, 'gil@example.com', 'appraiser');
$ronPid = join_office($avi, $ron, 'ron@example.com', 'staff');

function req(string $key, array $more = []): array
{
    return $more + ['key' => $key, 'group' => '', 'doc_type' => 'rights', 'title' => "דרישה $key", 'priority' => 'normal', 'mandatory' => false, 'party_kind' => 'client',
        'due' => ['mode' => 'relative', 'days' => 3], 'needs_review' => true, 'when' => null, 'sort' => 1];
}
$P = call('POST', 'profile-create', ['scope' => 'office', 'name' => 'דירה לרכישה'], $avi)[1]['profile'];
$content = ['flags' => [], 'requirements' => ['groups' => [['key' => 'legal', 'title' => 'משפטי']], 'items' => [
    req('rights', ['mandatory' => true, 'title' => 'נסח טאבו', 'explain_recipient' => 'נסח מהחודש האחרון', 'explain_internal' => 'סוד-פנימי-לבדוק-עיקולים']),
    req('contract', ['title' => 'הסכם מכר', 'sort' => 2, 'party_kind' => 'lawyer']), req('plan', ['title' => 'תשריט', 'sort' => 3]),
    req('id', ['title' => 'צילום תעודת זהות', 'sort' => 4]), req('permit', ['title' => 'היתר בנייה', 'sort' => 5, 'party_kind' => 'lawyer'])]], 'tasks' => ['items' => []], 'rules' => []];
[, $d] = call('POST', 'profile-draft-open', ['profile' => $P], $avi);
$s = call('POST', 'profile-draft-save', ['profile' => $P, 'content' => $content, 'revision' => $d['draft']['revision']], $avi);
$pub = call('POST', 'profile-publish', ['profile' => $P, 'note' => '', 'revision' => $s[1]['revision'] ?? ''], $avi);
if ($pub[0] !== 200) throw new RuntimeException('publish: ' . json_encode([$s[1], $pub[1]], JSON_UNESCAPED_UNICODE));

function make_case(string $as, string $profile, string $title): string
{
    $c = call('POST', 'case-create', ['profile' => $profile], $as)[1]['case'];
    call('POST', 'case-update', ['case' => $c, 'title' => $title, 'purpose' => 'purchase'], $as);
    call('POST', 'case-identifier-add', ['case' => $c, 'kind' => 'parcel', 'gush' => '6158', 'helka' => '12'], $as);
    call('POST', 'case-party-add', ['case' => $c, 'name' => 'משה לקוח', 'email' => 'moshe@client.example', 'kind' => 'client', 'is_orderer' => true], $as);
    $s = call('POST', 'case-start', ['case' => $c], $as);
    if ($s[0] !== 200) throw new RuntimeException('case-start: ' . json_encode($s[1], JSON_UNESCAPED_UNICODE));
    return $c;
}
function pdf(string $mark = '', string $extra = ''): string
{
    return "%PDF-1.4\n%$mark\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R >>\nendobj\n$extra\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
}

$crow = fn(string $pid) => apr_row('SELECT * FROM cases WHERE pid = ?', [$pid]);
$rrow = fn(string $pid) => apr_row('SELECT * FROM requirements WHERE pid = ?', [$pid]);
$qrow = fn(string $pid) => apr_row('SELECT * FROM requests WHERE pid = ?', [$pid]);
$count = fn(string $t, string $w = '1 = 1') => (int)apr_val("SELECT COUNT(*) FROM $t WHERE $w");
$log = fn(string $case, string $action) => apr_rows('SELECT * FROM audit_log WHERE case_id = ? AND action = ? ORDER BY id', [$crow($case)['id'], $action]);
$party = fn(string $case, string $kind) => apr_val('SELECT pid FROM case_parties WHERE case_id = ? AND kind = ? AND removed_at IS NULL', [$crow($case)['id'], $kind]);
$rq = fn(string $case) => array_column(apr_rows('SELECT * FROM requirements WHERE case_id = ? ORDER BY id', [$crow($case)['id']]), 'pid', 'origin_key');
$statuses = fn(array $pids) => array_map(fn($p) => $rrow($p)['status'], $pids);

$C = make_case($avi, $P, 'דירה ברחוב הרצל 7');
$D = make_case($avi, $P, 'תיק אחר');
call('POST', 'case-member-add', ['case' => $C, 'user' => $talPid], $avi);
call('POST', 'case-member-add', ['case' => $C, 'user' => $ronPid, 'role' => 'visitor'], $avi);
call('POST', 'case-party-add', ['case' => $C, 'name' => 'עו"ד רות כהן', 'email' => 'ruth@law.example', 'kind' => 'lawyer'], $avi);
call('POST', 'case-party-add', ['case' => $C, 'name' => 'בנק בלי מייל', 'kind' => 'bank'], $avi);
$R = $rq($C);
$RD = $rq($D);
[$client, $lawyer, $bank] = [$party($C, 'client'), $party($C, 'lawyer'), $party($C, 'bank')];

/** הרכבה → תצוגה מקדימה → שליחה, כמו שהמסך עושה. מחזיר את תשובת השליחה. */
function send_request(string $as, array $content, ?string $op = null): array
{
    $pv = call('POST', 'request-preview', $content, $as);
    if ($pv[0] !== 200) return $pv;
    return call('POST', isset($content['request']) ? 'request-resend' : 'request-send', $content + ['preview' => $pv[1]['preview'], 'op' => $op ?? 'op-' . bin2hex(random_bytes(6))], $as);
}
/** האסימון מהמייל האחרון (או מקישור). */
function token_of(string $text): string
{
    return preg_match('~/portal/#([A-Za-z0-9_-]{43})(?![A-Za-z0-9_-])~', $text, $m) === 1 ? $m[1] : '';
}
/** קריאה לפורטל, בלי זהות. $opt: cookie (apr_portal), as (זהות של משתמש — הפורטל אמור להתעלם), keep (לא לאפס מוני קצב), env. */
function portal(string $action, array $in = [], array $opt = []): array
{
    if (empty($opt['keep'])) apr_exec("DELETE FROM login_attempts WHERE key LIKE 'portal:%' OR key LIKE 'upload:p%'");
    unset($GLOBALS['apr_portal_cookie_out']);
    return call('POST', $action, $in, $opt['as'] ?? null, [], ['env' => ['portal_cookie' => $opt['cookie'] ?? ''] + ($opt['env'] ?? [])]);
}
function portal_up(string $token, string $bytes, array $q, array $opt = []): array
{
    $tmp = tempnam(apr_upload_tmp_dir(), 'up');
    file_put_contents($tmp, $bytes);
    $GLOBALS['t_tmp'] = $tmp;
    return portal('portal-upload', $q + ['name' => 'מסמך.pdf', 'key' => 'k-' . bin2hex(random_bytes(6))], $opt + ['env' => ['portal_token' => $token, 'upload' => $tmp]]);
}
/** מאמת מכשיר בקוד חד-פעמי; מחזיר את העוגייה. */
function verify_device(string $token): string
{
    $n = count($GLOBALS['t_mail']);
    portal('portal-otp-send', ['token' => $token]);
    preg_match('/הקוד שלכם: (\d{6})/', $GLOBALS['t_mail'][$n]['text'] ?? '', $m);
    portal('portal-otp-verify', ['token' => $token, 'code' => $m[1] ?? '']);
    return (string)($GLOBALS['apr_portal_cookie_out'] ?? '');
}
$base = ['case' => $C, 'party' => $client, 'requirements' => [$R['rights'], $R['plan']], 'channel' => 'email', 'contact_ok' => true];

// ── הרשאות ובידוד בצד המשרד ──
eq([code(call('POST', 'request-preview', $base, $ron)), code(call('POST', 'request-preview', $base, $gil)), code(call('POST', 'request-preview', $base, $boaz)), call('POST', 'request-preview', $base, $tal)[0]],
    ['forbidden', 'not_found', 'not_found', 200], '§11 תצוגה מקדימה: view בלבד ← 403; לא משתתף / משרד אחר ← 404; contact ← מותר');
eq([code(call('POST', 'request-preview', ['requirements' => [$RD['rights']]] + $base, $avi)), code(call('POST', 'request-preview', ['party' => $party($D, 'client')] + $base, $avi)),
    code(call('POST', 'request-preview', ['requirements' => []] + $base, $avi)), code(call('POST', 'request-preview', ['contact_ok' => false] + $base, $avi)),
    code(call('POST', 'request-preview', ['party' => $bank] + $base, $avi)), call('POST', 'request-preview', ['party' => $bank, 'channel' => 'link_copy'] + $base, $avi)[0], code(call('POST', 'request-preview', ['days' => 5] + $base, $avi))],
    ['not_found', 'not_found', 'request_items', 'contact_unconfirmed', 'recipient_no_email', 200, 'content_invalid'],
    '§4 דרישה או גורם של תיק אחר ← 404; בלי דרישות; בלי אישור הכתובת; מייל לנמען בלי מייל; תוקף שאינו ברשימה');

// ── 07-A1: אין שליחה בלי תצוגה מקדימה של אותו תוכן ──
$pv = call('POST', 'request-preview', $base, $avi)[1];
$n = [$count('requests'), $count('messages'), count($GLOBALS['t_mail'])];
$GLOBALS['apr_time'] = time() + APR_REQUEST_PREVIEW_TTL + 5;
$late = code(call('POST', 'request-send', $base + ['preview' => $pv['preview'], 'op' => 'op-late-0001'], $avi));
unset($GLOBALS['apr_time']);
eq([code(call('POST', 'request-send', $base + ['op' => 'op-nopreview1'], $avi)), code(call('POST', 'request-send', $base + ['preview' => 'x', 'op' => 'op-nopreview2'], $avi)),
    code(call('POST', 'request-send', ['body' => $pv['body'] . ' ועוד משפט'] + $base + ['preview' => $pv['preview'], 'op' => 'op-nopreview3'], $avi)),
    code(call('POST', 'request-send', ['requirements' => [$R['rights'], $R['plan'], $R['id']]] + $base + ['preview' => $pv['preview'], 'op' => 'op-nopreview4'], $avi)),
    code(call('POST', 'request-send', ['perms' => ['download_own' => true]] + $base + ['preview' => $pv['preview'], 'op' => 'op-nopreview5'], $avi)),
    code(call('POST', 'request-send', $base + ['preview' => $pv['preview'], 'op' => 'op-nopreview6'], $tal)), $late,
    code(call('POST', 'request-send', $base + ['preview' => $pv['preview']], $avi)),
    [$count('requests'), $count('messages'), count($GLOBALS['t_mail'])] === $n, $statuses([$R['rights'], $R['plan']])],
    ['preview_required', 'preview_required', 'preview_required', 'preview_required', 'preview_required', 'preview_required', 'preview_expired', 'content_invalid', true, ['required', 'required']],
    '07-A1 שליחה בלי מזהה תצוגה מקדימה, עם מזהה של תוכן אחר (נוסח, דרישות, הרשאות), של משתמש אחר, או שפג ← נדחית; דבר לא נוצר');
eq([$pv['sender'], $pv['recipient']['name'], $pv['recipient']['email'], array_column($pv['requirements'], 'title'), $pv['requirements'][0]['explain'], $pv['perms'], $pv['days'], str_contains(json_encode($pv, JSON_UNESCAPED_UNICODE), 'סוד-פנימי')],
    [['office' => 'משרד אבי שמאים', 'name' => 'אבי', 'reply_to' => 'avi@example.com'], 'משה לקוח', 'moshe@client.example', ['נסח טאבו', 'תשריט'], 'נסח מהחודש האחרון',
        ['upload' => true, 'reply' => true, 'view_own' => true, 'download_own' => false], 30, false],
    '§4 התצוגה המקדימה: השולח, הנמען, הדרישות עם ההסבר לנמען (בלי ההסבר הפנימי), ברירות המחדל של הפעולות והתוקף');

$fixed = ['subject' => 'נושא קבוע', 'body' => 'נוסח קבוע'] + $base;
$pvAvi = call('POST', 'request-preview', $fixed, $avi)[1]['preview'];
eq([code(call('POST', 'request-send', $fixed + ['preview' => $pvAvi, 'op' => 'op-other-user1'], $tal)), code(call('POST', 'request-send', $fixed + ['preview' => $pvAvi, 'op' => 'op-other-user2'], $ron)),
    code(call('POST', 'request-send', $fixed + ['preview' => $pvAvi, 'op' => 'op-other-user3'], $gil)), $count('requests')], ['preview_required', 'forbidden', 'not_found', 0],
    '07-A1 / §11 מזהה תצוגה מקדימה של משתמש אחר — גם לתוכן זהה לגמרי — אינו תקף; שליחה בלי contact ← 403, גם עם מזהה תקף של אחר');

// ── 07-A2: שליחה ──
$sent = call('POST', 'request-send', $base + ['preview' => $pv['preview'], 'op' => 'op-first-0001'], $avi);
$Q1 = $sent[1]['request'] ?? '';
$mail = end($GLOBALS['t_mail']);
$T1 = token_of($mail['text']);
eq([$sent[0], $sent[1]['message'] ?? null, $sent[1]['link'] ?? null, $statuses([$R['rights'], $R['plan'], $R['id']]), $count('messages'), $qrow($Q1)['status'] ?? null, strlen($T1)],
    [200, 'sent', null, ['requested', 'requested', 'required'], 1, 'active', 43], '07-A2 שליחה ← הדרישות requested, הודעה אחת; הקישור אינו חוזר למשתמש כשנשלח במייל');
$again = call('POST', 'request-send', $base + ['preview' => $pv['preview'], 'op' => 'op-first-0001'], $avi);
eq([$again[0], $again[1]['request'] ?? null, $again[1]['repeat'] ?? null, $count('messages'), $count('requests'), count($GLOBALS['t_mail']) - $n[2], $count('portal_links')],
    [200, $Q1, true, 1, 1, 1, 1], '07-A2 שליחה חוזרת עם אותו מזהה פעולה ← אין הודעה שנייה, אין בקשה שנייה ואין מייל שני');
eq([$mail['to'], $mail['from_name'], $mail['reply_to'], str_contains($mail['text'], apr_app_url('portal/') . '#' . $T1), substr_count($mail['text'], 'http')],
    ['moshe@client.example', 'משרד אבי שמאים', 'avi@example.com', true, 1], '07-D4 המייל: לנמען, שם המשרד כשולח, "השב" לשמאי, וקישור אחד — מ-apr_app_url');
$leak = fn(string $t) => array_values(array_filter(['הרצל', '6158', 'רות', 'ruth@', $crow($C)['number'], $C, 'הסכם מכר', 'סוד-פנימי'], fn($x) => str_contains($t, (string)$x)));
eq($leak($mail['text'] . $mail['subject']), [], '§4 במייל אין כתובת הנכס, מספר התיק, גורמים אחרים או דרישות של אחרים');
eq([count($log($C, 'request.send')), json_decode($log($C, 'request.send')[0]['meta'], true)['channel'] ?? null, (int)apr_val('SELECT actor_user_id FROM audit_log WHERE action = ? ORDER BY id DESC LIMIT 1', ['request.send']) > 0],
    [1, 'email', true], 'השליחה ביומן הפעולות של התיק');

// ── 07-C4: במסד אין אסימון ואין קוד בטקסט גלוי ──
$dump = function (): string {
    $out = '';
    foreach (apr_rows("SELECT name FROM sqlite_master WHERE type = 'table'") as $t) $out .= json_encode(apr_rows('SELECT * FROM "' . $t['name'] . '"'));
    return $out;
};
$cookie1 = verify_device($T1);
preg_match('/הקוד שלכם: (\d{6})/', end($GLOBALS['t_mail'])['text'], $cm);
$db = $dump();
eq([str_contains($db, $T1), str_contains($db, $cookie1), (bool)preg_match('/"code_hash":"' . $cm[1] . '"/', $db), apr_val('SELECT token_hash FROM portal_links LIMIT 1') === hash('sha256', 'portal:' . $T1),
    strlen($cookie1), strlen((string)apr_val('SELECT code_hash FROM portal_otps LIMIT 1'))], [false, false, false, true, 43, 64],
    '07-C4 במסד (כל הטבלאות, כולל יומנים) אין אסימון פורטל, עוגיית מכשיר או קוד חד-פעמי בטקסט גלוי — רק hash');

// ── בקשה שנייה, לעורכת הדין ──
$sentL = send_request($tal, ['case' => $C, 'party' => $lawyer, 'requirements' => [$R['contract'], $R['permit']], 'channel' => 'email', 'contact_ok' => true, 'perms' => ['download_own' => true]]);
$Q2 = $sentL[1]['request'];
$T2 = token_of(end($GLOBALS['t_mail'])['text']);
eq([$sentL[1]['message'], end($GLOBALS['t_mail'])['reply_to'], $T2 !== $T1], ['sent', 'tal@example.com', true], 'בקשה שנייה (עובדת משרד עם contact) — אסימון אחר');

// ── 07-B1: מה הנמען רואה ──
$open = portal('portal-open', ['token' => $T1]);
$json = json_encode($open[1], JSON_UNESCAPED_UNICODE);
eq([$open[0], $open[1]['state'], $open[1]['office'], $open[1]['sender'], array_column($open[1]['items'], 'title'), array_column($open[1]['items'], 'state'), $open[1]['items'][0]['explain'],
    array_column($open[1]['items'], 'id') === [$R['rights'], $R['plan']], $open[1]['can'], $open[1]['otp']],
    [200, 'open', 'משרד אבי שמאים', 'אבי', ['נסח טאבו', 'תשריט'], ['waiting', 'waiting'], 'נסח מהחודש האחרון', true,
        ['upload' => true, 'reply' => true, 'view' => true, 'download' => false], ['available' => true, 'verified' => false, 'required' => false]],
    '07-B1 portal-open באסימון של הלקוח: רק הדרישות שלו, במצב "ממתין למסמך"');
eq([$leak($json), str_contains($json, 'moshe@'), str_contains($json, $client), str_contains($json, $R['contract']), array_keys($open[1])],
    [[], false, false, false, ['ok', 'state', 'office', 'sender', 'intro', 'expires_at', 'can', 'otp', 'items', 'limits']],
    '07-B1 בתשובה אין מספר תיק, מזהה תיק, כתובת, גורמים אחרים, דרישות של אחרים, הסבר פנימי או המייל של הנמען');
eq([(int)apr_val('SELECT use_count FROM portal_links WHERE request_id = ?', [$qrow($Q1)['id']]) >= 1, call('GET', 'case-requests', [], $avi, ['case' => $C])[1]['requests'][1]['last_used_at'] !== null], [true, true], 'שימוש אחרון בקישור נרשם ומוצג למשרד');

// ── 07-B9: הפורטל מתעלם מזהות של משתמש ──
$sess = $count('sessions');
$bad = str_repeat('A', 43);
$asUser = portal('portal-open', ['token' => $bad], ['as' => $avi]);
$asUser2 = portal('portal-respond', ['token' => $bad, 'requirement' => $R['rights'], 'kind' => 'comment', 'text' => 'x'], ['as' => $avi]);
$asUser3 = portal_up($bad, pdf('b9'), ['requirement' => $R['rights']], ['as' => $avi]);
eq([code($asUser), code($asUser2), code($asUser3), code(portal('portal-file', ['token' => $bad, 'file' => 'x'], ['as' => $avi])), $count('sessions') - $sess, isset($GLOBALS['apr_cookie_out'])],
    ['portal_invalid', 'portal_invalid', 'portal_invalid', 'portal_invalid', 0, false], '07-B9 זהות של שמאי + אסימון שגוי ← נדחית בכל portal-*; לא נוצר session');
$okAs = portal('portal-open', ['token' => $T1], ['as' => $ron]);
eq([$okAs[0], array_column($okAs[1]['items'], 'title'), $count('sessions') - $sess], [200, ['נסח טאבו', 'תשריט'], 0], 'מקרה 10: משתמש מחובר שפותח קישור רואה את הפורטל כמו נמען; ה-session שלו אינו נגוע');
$GLOBALS['t_origin'] = 'https://evil.example';
$x1 = code(portal('portal-open', ['token' => $T1]));
unset($GLOBALS['t_origin']);
$GLOBALS['t_fetch_site'] = 'cross-site';
$x2 = code(portal_up($T1, pdf('xs'), ['requirement' => $R['rights']]));
unset($GLOBALS['t_fetch_site']);
$GLOBALS['t_content_type'] = 'text/plain';
$x3 = code(portal('portal-open', ['token' => $T1]));
unset($GLOBALS['t_content_type']);
eq([$x1, $x2, $x3, code(call('GET', 'portal-open', [], null, ['token' => $T1])), $count('files')], ['csrf', 'csrf', 'csrf', 'method', 0], 'בקשה לפורטל מאתר אחר, או שאינה JSON, או ב-GET ← נדחית');

// ── 07-C1: העלאה בפורטל ──
$reads = 0;
$reader = function () use (&$reads) { $reads++; $t = tempnam(apr_upload_tmp_dir(), 'up'); file_put_contents($t, pdf('never')); return ['upload' => $t]; };
$noTok = portal('portal-upload', ['requirement' => $R['rights'], 'name' => 'a.pdf', 'key' => 'key-notoken1'], ['env' => ['portal_token' => $bad, 'upload_reader' => $reader]]);
$noTok2 = portal('portal-upload', ['token' => $T1, 'requirement' => $R['rights'], 'name' => 'a.pdf', 'key' => 'key-notoken2'], ['env' => ['upload_reader' => $reader]]);
$wrongReq = portal('portal-upload', ['requirement' => $R['contract'], 'name' => 'a.pdf', 'key' => 'key-notoken3'], ['env' => ['portal_token' => $T1, 'upload_reader' => $reader]]);
eq([code($noTok), code($noTok2), code($wrongReq), $reads, $count('files')], ['portal_invalid', 'portal_invalid', 'not_found', 0, 0],
    '§9 הגוף אינו נקרא לדיסק לפני שהאסימון אומת ושהדרישה שייכת לבקשה; אסימון בכתובת במקום בכותרת אינו מתקבל');
$up = portal_up($T1, pdf('rights-1'), ['requirement' => $R['rights'], 'name' => '<img src=x onerror=alert(1)>.pdf', 'key' => 'key-rights-001']);
$f1 = apr_row('SELECT * FROM files WHERE pid = ?', [$up[1]['file'] ?? '']);
$l1 = apr_row('SELECT * FROM requirement_files WHERE file_id = ?', [$f1['id'] ?? 0]);
eq([$up[0], $up[1]['received'] ?? null, $rrow($R['rights'])['status'], $f1['uploader_kind'] ?? null, $f1['channel'] ?? null, $f1 ? $f1['uploader_id'] : 'x', (int)($f1['request_id'] ?? 0) === (int)$qrow($Q1)['id'],
    $l1['review_state'] ?? null, $l1 ? $l1['reviewed_by'] : 'x', $rrow($R['rights'])['status_by'], is_file($GLOBALS['t_tmp'])],
    [200, true, 'received', 'recipient', 'portal', null, true, 'pending', null, null, false], '07-C1 העלאה בפורטל ← received, uploader_kind = recipient, channel = portal, pending — לא approved');
$a = $log($C, 'file.upload');
eq([count($a), $a[0]['actor_kind'] ?? null, $a ? $a[0]['actor_user_id'] : 'x', json_decode($a[0]['meta'] ?? '{}', true)['channel'] ?? null], [1, 'recipient', null, 'portal'], '07-C1 ההעלאה ביומן התיק: בידי נמען, בערוץ הפורטל');
$dbl = portal_up($T1, pdf('rights-1'), ['requirement' => $R['rights'], 'key' => 'key-rights-001']);
$same = portal_up($T1, pdf('rights-1'), ['requirement' => $R['rights'], 'key' => 'key-rights-002']);
eq([$dbl[1]['file'] ?? null, $dbl[1]['repeat'] ?? null, $same[1]['file'] ?? null, $same[1]['repeat'] ?? null, $count('files')], [$up[1]['file'], true, $up[1]['file'], true, 1],
    'מקרה 8: לחיצה כפולה (אותו מזהה פעולה), או אותו קובץ שוב לאותה דרישה ← קובץ אחד');
eq([code(portal_up($T1, pdf('nokey'), ['requirement' => $R['rights'], 'key' => ''])), code(portal_up($T1, pdf('nokey'), ['requirement' => 'nope'])), code(portal_up($T1, pdf('nokey'), ['requirement' => $RD['rights']])), $count('files')],
    ['content_invalid', 'not_found', 'not_found', 1], 'העלאה בלי מזהה פעולה, בלי דרישה, או לדרישה של תיק אחר ← נדחית');
$more = portal_up($T1, pdf('rights-2'), ['requirement' => $R['rights']]);
eq([$more[0], apr_val('SELECT relation FROM requirement_files rf JOIN files f ON f.id = rf.file_id WHERE f.pid = ?', [$more[1]['file']]), $rrow($R['rights'])['status']], [200, 'attachment', 'received'], 'קובץ נוסף לדרישה שממתינה לבדיקה ← נלווה לאותה גרסה');
$pend = call('GET', 'docs-pending', [], $avi)[1]['pending'];
eq(array_column($pend, 'requirement'), [$R['rights']], '§6 "מסמכים שהתקבלו" בלוח הראשי');

// ── 07-B3 / 07-B2: הלקוח מול עורכת הדין ──
$n = $count('files');
$b3 = portal_up($T1, pdf('b3'), ['requirement' => $R['contract']]);
eq([$b3[0], code($b3), $count('files') - $n, $rrow($R['contract'])['status']], [404, 'not_found', 0, 'requested'], '07-B3 אסימון של הלקוח + דרישה של עורכת הדין ← ההעלאה נדחית');
$upL = portal_up($T2, pdf('contract-1'), ['requirement' => $R['contract']]);
$cookie1 = verify_device($T1);
$cookie2 = verify_device($T2);
// קובץ פנימי של התיק, שאינו שייך לשום בקשה
$tmp = tempnam(apr_upload_tmp_dir(), 'up');
file_put_contents($tmp, pdf('internal'));
$internal = call('POST', 'file-upload', ['case' => $C, 'name' => 'פנימי.pdf'], $avi, [], ['env' => ['upload' => $tmp]])[1]['file'];
$own = portal('portal-file', ['token' => $T1, 'file' => $up[1]['file'], 'inline' => true], ['cookie' => $cookie1]);
eq([code(portal('portal-file', ['token' => $T1, 'file' => $upL[1]['file'], 'inline' => true], ['cookie' => $cookie1])), code(portal('portal-file', ['token' => $T1, 'file' => $internal, 'inline' => true], ['cookie' => $cookie1])),
    code(portal('portal-file', ['token' => $T2, 'file' => $up[1]['file']], ['cookie' => $cookie2])), $own[0], isset($own[1]['_file']), str_starts_with($own[1]['_file']['headers']['Content-Security-Policy'] ?? '', 'sandbox'), $own[1]['_file']['headers']['X-Content-Type-Options'] ?? null],
    ['not_found', 'not_found', 'not_found', 200, true, true, 'nosniff'], '07-B2 אסימון של הלקוח + pid של קובץ שעורכת הדין העלתה (או קובץ פנימי של התיק) ← 404; את שלו — מקבל');
$oL = portal('portal-open', ['token' => $T2], ['cookie' => $cookie2])[1];
$o1 = portal('portal-open', ['token' => $T1], ['cookie' => $cookie1])[1];
eq([array_column($oL['items'], 'title'), array_column($oL['items'][0]['files'], 'id'), array_column($o1['items'][0]['files'], 'name'), $o1['items'][0]['state'], $o1['otp']['verified'],
    str_contains(json_encode($oL, JSON_UNESCAPED_UNICODE), 'נסח')], [['הסכם מכר', 'היתר בנייה'], [$upL[1]['file']], ['<img src=x onerror=alert(1)>.pdf', 'מסמך.pdf'], 'checking', true, false],
    '07-D5 כל אחד רואה רק את הדרישות והקבצים שלו; המצב "התקבל — בבדיקה"');

// ── 07-B4: קובץ רק ממכשיר מאומת, ורק לפי ההרשאה ──
eq([code(portal('portal-file', ['token' => $T1, 'file' => $up[1]['file'], 'inline' => true])), code(portal('portal-file', ['token' => $T1, 'file' => $up[1]['file'], 'inline' => true], ['cookie' => $cookie2])),
    code(portal('portal-file', ['token' => $T1, 'file' => $up[1]['file'], 'inline' => true], ['cookie' => str_repeat('B', 43)])), code(portal('portal-file', ['token' => $T1, 'file' => $up[1]['file']], ['cookie' => $cookie1])),
    portal('portal-file', ['token' => $T2, 'file' => $upL[1]['file']], ['cookie' => $cookie2])[0], isset(portal('portal-open', ['token' => $T1])[1]['items'][0]['files']), portal('portal-open', ['token' => $T1])[1]['items'][0]['files_count']],
    ['portal_otp_required', 'portal_otp_required', 'portal_otp_required', 'portal_forbidden', 200, false, 2],
    '07-B4 portal-file בלי מכשיר מאומת (או עם עוגייה של קישור אחר) ← נדחה, גם עם view_own; הורדה רק עם download_own; בלי אימות — רק כמה קבצים התקבלו');

// ── 07-C3: קובץ שנדחה ──
$n = $count('files');
$rej = portal_up($T1, pdf('js', '5 0 obj << /S /JavaScript /JS (app.alert(1)) >> endobj'), ['requirement' => $R['plan']]);
$exe = portal_up($T1, "MZ\x90\x00" . str_repeat("\0", 200), ['requirement' => $R['plan'], 'name' => 'a.pdf']);
$rl = $log($C, 'file.rejected');
eq([code($rej), code($exe), $count('files') - $n, $rrow($R['plan'])['status'], count($rl) >= 1, $rl[0]['actor_kind'] ?? null, array_column(call('GET', 'docs-pending', [], $avi)[1]['pending'], 'requirement')],
    ['file_active', 'file_type', 0, 'requested', true, 'recipient', [$R['rights'], $R['contract']]], '07-C3 קובץ שנדחה בפורטל (תוכן פעיל, סוג) ← אין קובץ, הדרישה לא שינתה מצב, והמשרד אינו מקבל "מסמך שהתקבל"');
// אותו קובץ שכבר קיים בתיק כקובץ פנימי — מתקבל כחדש, בלי לחשוף שהוא קיים
$dupI = portal_up($T1, pdf('internal'), ['requirement' => $R['plan']]);
eq([$dupI[0], array_keys($dupI[1]), $rrow($R['plan'])['status']], [200, ['ok', 'received', 'file', 'repeat'], 'received'], 'כפילות מול קובץ פנימי של התיק אינה נחשפת לנמען — הקובץ פשוט מתקבל');

// ── 07-C2: "אין ברשותי" והערה ──
$nt = $count('tasks');
$nd = portal('portal-respond', ['token' => $T2, 'requirement' => $R['permit'], 'kind' => 'no_document', 'text' => '<b>אבד</b> בשריפה']);
$task = apr_row("SELECT * FROM tasks WHERE kind = 'followup' ORDER BY id DESC LIMIT 1");
$nd2 = portal('portal-respond', ['token' => $T2, 'requirement' => $R['permit'], 'kind' => 'no_document']);
eq([$nd[0], $count('tasks') - $nt, $rrow($R['permit'])['status'], $task['link_pid'] ?? null, $task['status'] ?? null, (int)($task['assignee_user_id'] ?? 0) === (int)$crow($C)['responsible_user_id'],
    str_contains($task['title'] ?? '', 'היתר בנייה'), $task['detail'] ?? null, $nd2[0], $count('requirement_responses')],
    [200, 1, 'requested', $R['permit'], 'open', true, true, '<b>אבד</b> בשריפה', 200, 2], '07-C2 "אין ברשותי" ← משימת המשך אחת לאחראי (גם בלחיצה חוזרת); הדרישה נשארת requested');
$cm = portal('portal-respond', ['token' => $T2, 'requirement' => $R['permit'], 'kind' => 'comment', 'text' => 'אשלח בשבוע הבא']);
eq([$cm[0], code(portal('portal-respond', ['token' => $T2, 'requirement' => $R['permit'], 'kind' => 'comment', 'text' => '  '])), code(portal('portal-respond', ['token' => $T2, 'requirement' => $R['rights'], 'kind' => 'comment', 'text' => 'x'])),
    code(portal('portal-respond', ['token' => $T2, 'requirement' => $R['permit'], 'kind' => 'approve', 'text' => 'x'])), code(portal('portal-respond', ['token' => $T2, 'requirement' => $R['permit'], 'kind' => 'comment', 'text' => str_repeat('א', 1001)])),
    $count('tasks') - $nt, $count('requirement_responses'), portal('portal-open', ['token' => $T2])[1]['items'][1]['no_document']],
    [200, 'content_invalid', 'not_found', 'content_invalid', 'text_too_long', 1, 3, true], 'הערה נשמרת; הערה ריקה, ארוכה מדי, סוג לא מוכר, או דרישה של בקשה אחרת ← נדחית');
$resp = call('GET', 'case-requests', [], $ron, ['case' => $C])[1];
eq([$resp['can_send'], array_column($resp['requests'][0]['responses'], 'kind'), $resp['requests'][0]['responses'][0]['text'], $resp['requests'][0]['party_name'], $resp['requests'][0]['messages'][0]['status'],
    code(call('GET', 'case-requests', [], $gil, ['case' => $C])), code(call('GET', 'case-requests', [], $boaz, ['case' => $C])), $log($C, 'portal.response')[0]['actor_kind'] ?? null],
    [false, ['comment', 'no_document', 'no_document'], 'אשלח בשבוע הבא', 'עו"ד רות כהן', 'sent', 'not_found', 'not_found', 'recipient'],
    '§10 המשרד רואה את הבקשות, ההודעות ותשובות הנמען (view); לא משתתף / משרד אחר ← 404');

// ── 07-B5: קודים שגויים ──
$n = count($GLOBALS['t_mail']);
portal('portal-otp-send', ['token' => $T2]);
preg_match('/הקוד שלכם: (\d{6})/', $GLOBALS['t_mail'][$n]['text'], $cm);
$wrongCode = $cm[1] === '000000' ? '000001' : '000000';
$tries = [];
for ($i = 0; $i < 5; $i++) $tries[] = code(portal('portal-otp-verify', ['token' => $T2, 'code' => $wrongCode]));
$sixth = code(portal('portal-otp-verify', ['token' => $T2, 'code' => $cm[1]]));
eq([$tries, $sixth, code(portal('portal-open', ['token' => $T2])), code(portal_up($T2, pdf('locked'), ['requirement' => $R['permit']])), isset($GLOBALS['apr_portal_cookie_out']), count($log($C, 'portal.locked')),
    portal('portal-open', ['token' => $T1])[0]],
    [array_fill(0, 5, 'portal_otp_wrong'), 'portal_locked', 'portal_locked', 'portal_locked', false, 1, 200], '07-B5 חמישה קודים שגויים, והשישי — גם אם נכון — נדחה: הקישור נחסם (ורק הוא)');
$GLOBALS['apr_time'] = time() + APR_PORTAL_LOCK - 30;
$still = code(portal('portal-open', ['token' => $T2]));
$GLOBALS['apr_time'] = time() + APR_PORTAL_LOCK + 5;
$after = portal('portal-open', ['token' => $T2])[0];
$stale = code(portal('portal-otp-verify', ['token' => $T2, 'code' => $cm[1]]));
unset($GLOBALS['apr_time']);
apr_exec('UPDATE portal_links SET locked_until = NULL, otp_fails = 0');
eq([$still, $after, $stale], ['portal_locked', 200, 'portal_otp_wrong'], '07-B5 החסימה לרבע שעה; אחריה הקישור עובד, והקוד הישן כבר אינו תקף');
// קוד: שש ספרות בלבד, חד-פעמי, ופג אחרי 10 דקות
$n = count($GLOBALS['t_mail']);
portal('portal-otp-send', ['token' => $T2]);
preg_match('/הקוד שלכם: (\d{6})/', $GLOBALS['t_mail'][$n]['text'], $cm);
$GLOBALS['apr_time'] = time() + APR_PORTAL_OTP_TTL + 5;
$expiredCode = code(portal('portal-otp-verify', ['token' => $T2, 'code' => $cm[1]]));
unset($GLOBALS['apr_time']);
$okCode = portal('portal-otp-verify', ['token' => $T2, 'code' => $cm[1]]);
$reuse = code(portal('portal-otp-verify', ['token' => $T2, 'code' => $cm[1]]));
eq([$expiredCode, $okCode[0], $reuse, code(portal('portal-otp-verify', ['token' => $T2, 'code' => $cm[1] . '0'])), code(portal('portal-otp-verify', ['token' => $T2, 'code' => ['a']])), $GLOBALS['t_mail'][$n]['to'], $GLOBALS['t_mail'][$n]['from_name']],
    ['portal_otp_wrong', 200, 'portal_otp_wrong', 'portal_otp_wrong', 'portal_otp_wrong', 'ruth@law.example', 'משרד אבי שמאים'], '§5.1 הקוד פג אחרי 10 דקות, חד-פעמי, ונשלח למייל שבספר הכתובות');
apr_exec('UPDATE portal_links SET locked_until = NULL, otp_fails = 0');
$sends = [];
for ($i = 0; $i < 4; $i++) $sends[] = code(portal('portal-otp-send', ['token' => $T1], ['keep' => true]));
eq($sends, [null, null, null, 'too_many_codes'], '§9 עד שלושה קודים לרבע שעה לקישור');

// ── הגבלת קצב ──
apr_exec("DELETE FROM login_attempts WHERE key LIKE 'portal:%'");
$codes = [];
for ($i = 0; $i < 22; $i++) $codes[] = code(portal('portal-open', ['token' => $bad], ['keep' => true]));
eq([array_count_values($codes), code(portal('portal-open', ['token' => $T1], ['keep' => true]) + [1 => []]) ?? 'ok'], [['portal_invalid' => 20, 'portal_rate' => 2], 'ok'], '§9 עשרים אסימונים שגויים מ-IP אחד, ואחריהם 429');
apr_exec("DELETE FROM login_attempts WHERE key LIKE 'portal:%'");
$codes = [];
for ($i = 0; $i < 62; $i++) $codes[] = portal('portal-open', ['token' => $i % 2 ? $T1 : $T2], ['keep' => true])[0];
eq(array_count_values($codes), [200 => 60, 429 => 2], '§9 60 בקשות לדקה ל-IP — גם כשהן מתחלקות בין שני קישורים');
apr_exec("DELETE FROM login_attempts WHERE key LIKE 'portal:%'");
$codes = [];
for ($i = 0; $i < 62; $i++) $codes[] = portal('portal-open', ['token' => $T1], ['keep' => true])[0];
$GLOBALS['t_ip'] = '198.51.100.9';
$other = [portal('portal-open', ['token' => $T2], ['keep' => true])[0], code(portal('portal-open', ['token' => $T1], ['keep' => true]))];
unset($GLOBALS['t_ip']);
eq([array_count_values($codes), $other], [[200 => 60, 429 => 2], [200, 'portal_rate']], '§9 60 בקשות לדקה לקישור — גם מ-IP אחר הקישור חסום, וקישור אחר פתוח');
apr_exec("DELETE FROM login_attempts WHERE key LIKE 'portal:%' OR key LIKE 'upload:p%'");
$codes = [];
for ($i = 0; $i < 22; $i++) {
    apr_exec("DELETE FROM login_attempts WHERE key LIKE 'portal:%'");
    $codes[] = code(portal_up($T2, 'not a document ' . $i, ['requirement' => $R['permit']], ['keep' => true]));
}
eq([array_count_values($codes), $rrow($R['permit'])['status']], [['file_type' => 20, 'upload_rate' => 2], 'requested'], '§9 עשרים העלאות לשעה לקישור — גם ניסיון שנדחה נספר');
apr_exec("DELETE FROM login_attempts WHERE key LIKE 'portal:%' OR key LIKE 'upload:p%'");
$ce = portal('portal-client-error', ['message' => 'TypeError at https://x/portal/#' . $T1 . ' boom', 'source' => 'https://example.test/appraisers/portal/portal.js?token=' . $T1, 'line' => 12, 'stack' => $T1, 'page' => '#' . $T1]);
$dl = devloop_log_db();
$dlRows = $dl ? json_encode($dl->query("SELECT * FROM log WHERE event = 'portal.client_error'")->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE) : '';
eq([$ce[0], str_contains($dump(), $T1), $dl !== null, str_contains($dlRows, 'TypeError at'), str_contains($dlRows, $T1), str_contains($dlRows, 'portal.js')], [200, false, true, true, false, true],
    '§9 שגיאת דפדפן מהפורטל נרשמת ביומן הטכני — בלי האסימון (גם כשהדף שלח אותו בהודעה, בקובץ המקור או בשדה אחר)');

// ── 07-B6: פג, בוטל, לא קיים — אותה תשובה ──
$sentX = send_request($avi, ['case' => $C, 'party' => $client, 'requirements' => [$R['id']], 'channel' => 'link_copy', 'days' => 7]);
$TX = token_of($sentX[1]['link'] ?? '');
$msgX = apr_row('SELECT * FROM messages ORDER BY id DESC LIMIT 1');
eq([$sentX[1]['message'], strlen($TX), $msgX['channel'], $msgX['status'], $msgX['to_hash'], $rrow($R['id'])['status'], str_starts_with((string)$sentX[1]['link'], apr_app_url('portal/') . '#')],
    ['unknown', 43, 'link_copy', 'unknown', null, 'requested', true], '§4 "קישור להעתקה": הקישור מוצג פעם אחת, ההודעה במצב unknown, והדרישה requested');
$strip = fn(array $r) => [$r[0], array_diff_key($r[1], ['req' => 0])];
$missing = $strip(portal('portal-open', ['token' => $bad]));
$GLOBALS['apr_time'] = time() + 7 * 86400 + 60;
$expired = $strip(portal('portal-open', ['token' => $TX]));
$expUp = code(portal_up($TX, pdf('exp'), ['requirement' => $R['id']]));
$listed = call('GET', 'case-requests', [], $avi, ['case' => $C])[1]['requests'][0]['status'];
unset($GLOBALS['apr_time']);
$q3 = $sentX[1]['request'];
eq([portal('portal-open', ['token' => $TX])[0], code(call('POST', 'request-revoke', ['request' => $q3], $ron)), code(call('POST', 'request-revoke', ['request' => $q3], $gil)), code(call('POST', 'request-revoke', ['request' => $q3], $boaz))],
    [200, 'forbidden', 'not_found', 'not_found'], '§11 ביטול: view בלבד ← 403; לא משתתף / משרד אחר ← 404');
$rev = call('POST', 'request-revoke', ['request' => $q3], $tal);
$revoked = $strip(portal('portal-open', ['token' => $TX]));
$malformed = [$strip(portal('portal-open', ['token' => 'short'])), $strip(portal('portal-open', [])), $strip(portal('portal-open', ['token' => ['a']])), $strip(portal('portal-open', ['token' => $TX . "\n"]))];
eq([$missing, $expired === $missing, $revoked === $missing, array_unique(array_map('json_encode', $malformed)) === [json_encode($missing)], $listed, $expUp],
    [[404, ['ok' => false, 'error' => ['code' => 'portal_invalid', 'message' => 'הקישור אינו בתוקף. לקבלת קישור חדש, פנו למשרד ששלח אותו.']]], true, true, true, 'expired', 'portal_invalid'],
    '07-B6 אסימון שפג, שבוטל, שאינו קיים ושאינו תקין ← אותה תשובה בדיוק, בלי שם המשרד');

// ── 07-B7: אחרי ביטול ──
$rv = $log($C, 'request.revoke');
eq([$rev[0], code(portal_up($TX, pdf('rv'), ['requirement' => $R['id']])), code(portal('portal-respond', ['token' => $TX, 'requirement' => $R['id'], 'kind' => 'comment', 'text' => 'x'])), code(portal('portal-otp-send', ['token' => $TX])),
    code(portal('portal-otp-verify', ['token' => $TX, 'code' => '123456'])), code(portal('portal-file', ['token' => $TX, 'file' => $up[1]['file']])), count($rv), $rv[0]['entity_pid'] ?? null, (int)($rv[0]['actor_user_id'] ?? 0) > 0,
    $rrow($R['id'])['status'], $qrow($q3)['status'], code(call('POST', 'request-revoke', ['request' => $q3], $tal)), code(send_request($avi, ['request' => $q3, 'channel' => 'link_copy']))],
    [200, 'portal_invalid', 'portal_invalid', 'portal_invalid', 'portal_invalid', 'portal_invalid', 1, $q3, true, 'required', 'revoked', 'request_state', 'request_state'],
    '07-B7 אחרי ביטול כל portal-* נדחה; הביטול ביומן; הדרישה חוזרת ל-required; בקשה שבוטלה אינה נשלחת מחדש');

eq([code(call('POST', 'request-resend', ['request' => $Q1, 'channel' => 'link_copy', 'preview' => $pvAvi, 'op' => 'op-resend-ron1'], $ron)), code(call('POST', 'request-preview', ['requirements' => [$R['rights']]] + $base, $avi)),
    $rrow($R['rights'])['status']], ['forbidden', 'req_state', 'received'], '§11 שליחה מחדש בלי contact ← 403; דרישה שכבר התקבל לה מסמך אינה נכנסת לבקשה חדשה');
// הרשאות הבקשה: בלי upload ובלי reply
$sentN = send_request($avi, ['case' => $D, 'party' => $party($D, 'client'), 'requirements' => [$RD['plan']], 'channel' => 'link_copy', 'perms' => ['upload' => false, 'reply' => false]]);
$TN = token_of($sentN[1]['link']);
eq([portal('portal-open', ['token' => $TN])[1]['can'], code(portal_up($TN, pdf('noperm'), ['requirement' => $RD['plan']])), code(portal('portal-respond', ['token' => $TN, 'requirement' => $RD['plan'], 'kind' => 'comment', 'text' => 'x'])),
    $count('files', 'request_id = ' . (int)$qrow($sentN[1]['request'])['id']), $rrow($RD['plan'])['status']],
    [['upload' => false, 'reply' => false, 'view' => true, 'download' => false], 'portal_forbidden', 'portal_forbidden', 0, 'requested'], '§11 בקשה בלי "העלאה" ובלי "תשובה": הפורטל דוחה את שתיהן');
call('POST', 'request-revoke', ['request' => $sentN[1]['request']], $avi);

// ── 07-B8: שליחה מחדש ──
eq([code(call('POST', 'request-resend', ['request' => $Q1, 'channel' => 'email', 'contact_ok' => true, 'op' => 'op-resend-001'], $avi)), code(send_request($ron, ['request' => $Q1, 'channel' => 'email', 'contact_ok' => true])),
    code(send_request($boaz, ['request' => $Q1, 'channel' => 'email', 'contact_ok' => true])), portal('portal-open', ['token' => $T1])[0]],
    ['preview_required', 'forbidden', 'not_found', 200], '07-A1 / §11 גם שליחה מחדש דורשת תצוגה מקדימה והרשאת contact; הקישור הישן עדיין חי');
$re = send_request($avi, ['request' => $Q1, 'channel' => 'email', 'contact_ok' => true]);
$T1b = token_of(end($GLOBALS['t_mail'])['text']);
eq([$re[1]['message'] ?? null, $T1b !== $T1 && strlen($T1b) === 43, $strip(portal('portal-open', ['token' => $T1])) === $missing, portal('portal-open', ['token' => $T1b])[0],
    code(portal('portal-file', ['token' => $T1b, 'file' => $up[1]['file'], 'inline' => true], ['cookie' => $cookie1])), $count('portal_links', 'request_id = ' . (int)$qrow($Q1)['id'] . ' AND revoked_at IS NULL'),
    count($log($C, 'request.resend')), $count('messages', 'request_id = ' . (int)$qrow($Q1)['id'])],
    ['sent', true, true, 200, 'portal_otp_required', 1, 1, 2], '07-B8 "שליחה מחדש" ← האסימון הישן אינו עובד, החדש עובד, ומכשירים שאומתו נשכחים');

// ── 07-A3: המייל נכשל ──
$E = make_case($avi, $P, 'תיק שלישי');
$RE = $rq($E);
$GLOBALS['t_mail_ok'] = false;
$fail = send_request($avi, ['case' => $E, 'party' => $party($E, 'client'), 'requirements' => [$RE['rights'], $RE['plan']], 'channel' => 'email', 'contact_ok' => true]);
$GLOBALS['t_mail_ok'] = true;
$QF = $fail[1]['request'] ?? '';
$failed = call('GET', 'requests-failed', [], $avi)[1]['failed'];
eq([$fail[0], $fail[1]['message'] ?? null, $statuses([$RE['rights'], $RE['plan']]), apr_val('SELECT status FROM messages ORDER BY id DESC LIMIT 1'), array_column($failed, 'request'), $failed[0]['case']['pid'] ?? null,
    call('GET', 'requests-failed', [], $tal)[1]['failed'], code(call('GET', 'requests-failed', [], $boaz)) ?? call('GET', 'requests-failed', [], $boaz)[1]['failed']],
    [200, 'failed', ['required', 'required'], 'failed', [$QF], $E, [], []], '07-A3 mail() נכשל ← failed; הדרישות נשארות required; הבקשה ב"הודעות שנכשלו" — רק למי שמשתתף בתיק');
$fix = send_request($avi, ['request' => $QF, 'channel' => 'link_copy']);
eq([$fix[1]['message'] ?? null, strlen(token_of($fix[1]['link'] ?? '')), $statuses([$RE['rights'], $RE['plan']]), call('GET', 'requests-failed', [], $avi)[1]['failed']], ['unknown', 43, ['requested', 'requested'], []],
    '§6 אחרי הודעה שנכשלה: "העתקת קישור" ← קישור חדש, הדרישות requested, והבקשה יוצאת מ"הודעות שנכשלו"');
$TE = token_of($fix[1]['link']);

// ── מקרה 11: תיק סגור ──
$close = call('POST', 'case-status', ['case' => $E, 'status' => 'closed', 'reason' => 'בדיקה'], $avi);
$oc = portal('portal-open', ['token' => $TE]);
eq([$close[0], $oc[0], $oc[1], code(portal_up($TE, pdf('closed'), ['requirement' => $RE['rights']])), code(portal('portal-respond', ['token' => $TE, 'requirement' => $RE['rights'], 'kind' => 'comment', 'text' => 'x'])),
    code(portal('portal-otp-send', ['token' => $TE])), code(send_request($avi, ['request' => $QF, 'channel' => 'link_copy'])), code(call('POST', 'request-revoke', ['request' => $QF], $avi)),
    call('GET', 'case-requests', [], $avi, ['case' => $E])[1]['can_send']],
    [200, 200, ['ok' => true, 'state' => 'closed'], 'portal_closed', 'portal_closed', 'portal_closed', 'case_closed', 'case_closed', false], 'מקרה 11: תיק סגור ← "הבקשה נסגרה", העלאה ותשובה חסומות; גם המשרד אינו שולח ואינו מבטל');
call('POST', 'case-status', ['case' => $E, 'status' => 'collecting', 'reason' => 'נפתח מחדש'], $avi);
eq(portal('portal-open', ['token' => $TE])[1]['state'] ?? null, 'open', 'התיק נפתח מחדש ← הקישור חוזר לעבוד');

// ── §7: שינוי מייל, הסרת גורם ──
$contact = apr_val('SELECT ct.pid FROM case_parties cp JOIN contacts ct ON ct.id = cp.contact_id WHERE cp.pid = ?', [$party($E, 'client')]);
call('POST', 'contact-save', ['contact' => $contact, 'name' => 'משה לקוח', 'email' => 'moshe@client.example', 'phone' => '050'], $avi);
$sameMail = portal('portal-open', ['token' => $TE])[0];
call('POST', 'contact-save', ['contact' => $contact, 'name' => 'משה לקוח', 'email' => 'new@client.example'], $avi);
$lst = call('GET', 'case-requests', [], $avi, ['case' => $E])[1]['requests'][0];
eq([$sameMail, $strip(portal('portal-open', ['token' => $TE])) === $missing, $lst['status'], $lst['needs_resend'], count($log($E, 'request.link_revoked'))], [200, true, 'active', true, 1],
    '§7 שינוי המייל של הנמען ← הקישורים הפעילים שלו מבוטלים, ונדרשת שליחה מחדש (עדכון בלי שינוי מייל אינו מבטל)');
$re2 = send_request($avi, ['request' => $QF, 'channel' => 'email', 'contact_ok' => true]);
$TE2 = token_of(end($GLOBALS['t_mail'])['text']);
eq([end($GLOBALS['t_mail'])['to'], portal('portal-open', ['token' => $TE2])[0]], ['new@client.example', 200], '… והשליחה מחדש יוצאת לכתובת החדשה');
call('POST', 'case-party-add', ['case' => $E, 'name' => 'מזמין אחר', 'kind' => 'other', 'is_orderer' => true], $avi);
$rm = call('POST', 'case-party-remove', ['case' => $E, 'party' => $party($E, 'client')], $avi);
eq([$rm[0], $qrow($QF)['status'], $strip(portal('portal-open', ['token' => $TE2])) === $missing, $statuses([$RE['rights'], $RE['plan']]), json_decode($log($E, 'request.revoke')[0]['meta'] ?? '{}', true)['why'] ?? null],
    [200, 'revoked', true, ['required', 'required'], 'party_removed'], '§7 הסרת גורם מהתיק ← כל הבקשות שלו מבוטלות, והדרישות חוזרות ל-required');

// ── מקרים 5, 2 ו"הושלמה" ──
$rev1 = fn(string $pid) => call('POST', 'file-review', ['link' => apr_val("SELECT rf.pid FROM requirement_files rf JOIN requirements r ON r.id = rf.requirement_id WHERE r.pid = ? AND rf.review_state = 'pending' ORDER BY rf.id LIMIT 1", [$pid]),
    'do' => 'approve', 'chk_readable' => true, 'chk_complete' => true, 'chk_matches' => true], $avi);
call('POST', 'requirement-status', ['requirement' => $R['permit'], 'status' => 'not_relevant', 'reason' => 'אין היתר'], $avi);
$afterNr = portal('portal-open', ['token' => $T2])[1];
eq([array_column($afterNr['items'], 'title'), code(portal_up($T2, pdf('nr'), ['requirement' => $R['permit']])), code(portal('portal-respond', ['token' => $T2, 'requirement' => $R['permit'], 'kind' => 'comment', 'text' => 'x']))],
    [['הסכם מכר'], 'not_found', 'not_found'], 'מקרה 5: דרישה שסומנה "לא רלוונטי" אחרי השליחה ← נעלמת מהפורטל');
$ret = call('POST', 'file-review', ['link' => apr_val('SELECT rf.pid FROM requirement_files rf JOIN files f ON f.id = rf.file_id WHERE f.pid = ?', [$upL[1]['file']]), 'do' => 'return', 'return_code' => 'missing_pages',
    'return_reason' => 'חסר עמוד 3 <script>', 'note' => 'הערה-פנימית-של-הבודק'], $avi);
$fx = portal('portal-open', ['token' => $T2])[1];
eq([$ret[0], $fx['items'][0]['state'], $fx['items'][0]['reason'], str_contains(json_encode($fx, JSON_UNESCAPED_UNICODE), 'הערה-פנימית')], [200, 'fix', 'חסר עמוד 3 <script>', false],
    '§5.2 דרישה שהוחזרה: "נדרשת השלמה" עם הסיבה שנכתבה לנמען — בלי ההערה הפנימית של הבודק');
$up2 = portal_up($T2, pdf('contract-2'), ['requirement' => $R['contract']]);
eq([$up2[0], $rrow($R['contract'])['status'], apr_val('SELECT relation FROM requirement_files rf JOIN files f ON f.id = rf.file_id WHERE f.pid = ?', [$up2[1]['file']]), $qrow($Q2)['status']], [200, 'received', 'version', 'active'],
    'העלאה חוזרת לדרישה שהוחזרה ← גרסה חדשה, received');
$ap = $rev1($R['contract']);
$done = portal('portal-open', ['token' => $T2])[1];
eq([$ap[0], $rrow($R['contract'])['status'], $qrow($Q2)['status'], $done['state'], $done['items'][0]['state'], code(portal_up($T2, pdf('after'), ['requirement' => $R['contract']])), $rrow($R['contract'])['status']],
    [200, 'approved', 'completed', 'open', 'done', 'req_state', 'approved'], '§6 כל הדרישות אושרו או לא רלוונטיות ← הבקשה completed; הקישור נשאר לצפייה; הנמען אינו פותח מחדש דרישה שאושרה');
call('POST', 'requirement-status', ['requirement' => $R['permit'], 'status' => 'required', 'reason' => 'בכל זאת נדרש'], $avi);
eq($qrow($Q2)['status'], 'active', '… ודרישה שנפתחה שוב מחזירה את הבקשה לפעילה');
// מקרה 2: אותו אדם בשני תיקים — שני קישורים נפרדים
$sentD = send_request($avi, ['case' => $D, 'party' => $party($D, 'client'), 'requirements' => [$RD['rights']], 'channel' => 'link_copy']);
$TD = token_of($sentD[1]['link']);
$od = portal('portal-open', ['token' => $TD], ['cookie' => $cookie1])[1];
eq([array_column($od['items'], 'id'), $od['otp']['verified'], code(portal_up($TD, pdf('cross'), ['requirement' => $R['rights']])), code(portal('portal-file', ['token' => $TD, 'file' => $up[1]['file'], 'inline' => true], ['cookie' => $cookie1]))],
    [[$RD['rights']], false, 'not_found', 'portal_otp_required'], 'מקרה 2: קישור של תיק אחר מציג רק את שלו; מכשיר שאומת לקישור אחד אינו מאומת לאחר');

// ── "תמיד לדרוש קוד", ונמען בלי מייל ──
$sentB = send_request($avi, ['case' => $C, 'party' => $bank, 'requirements' => [$R['id']], 'channel' => 'link_copy', 'perms' => ['view_own' => true, 'download_own' => true]]);
$TB = token_of($sentB[1]['link']);
$ob = portal('portal-open', ['token' => $TB])[1];
eq([$ob['can'], $ob['otp']['available'], code(portal('portal-otp-send', ['token' => $TB])), json_decode($qrow($sentB[1]['request'])['perms'], true)['view_own']],
    [['upload' => true, 'reply' => true, 'view' => false, 'download' => false], false, 'portal_otp_unavailable', false], '§5.1 נמען בלי מייל: צפייה והורדה אינן זמינות, ואין קוד');
eq([code(call('POST', 'office-portal-otp', ['on' => true], $tal)), call('POST', 'office-portal-otp', ['on' => true], $avi)[1]['always_otp'] ?? null], ['forbidden', true], 'הגדרת משרד "תמיד לדרוש קוד" — למנהל המשרד בלבד');
$oo = portal('portal-open', ['token' => $T1b]);
$nm = count($GLOBALS['t_mail']);
$ckA = verify_device($T1b);
eq([count($GLOBALS['t_mail']) - $nm, strlen($ckA)], [1, 43], 'קוד לקישור חדש אחרי "שליחה מחדש" אכן נשלח (מפתח האידמפוטנטיות של המייל אינו חוזר)');
eq([$oo[1], code(portal_up($T1b, pdf('always'), ['requirement' => $R['plan']])), code(portal('portal-respond', ['token' => $T1b, 'requirement' => $R['plan'], 'kind' => 'comment', 'text' => 'x'])),
    portal('portal-open', ['token' => $T1b], ['cookie' => $ckA])[1]['state'], portal_up($T1b, pdf('always'), ['requirement' => $R['plan']], ['cookie' => $ckA])[0],
    code(portal('portal-open', ['token' => $TB]) + [1 => []]) ?? portal('portal-open', ['token' => $TB])[1]['state'], code(call('POST', 'request-preview', ['party' => $bank, 'channel' => 'link_copy'] + $base, $avi))],
    [['ok' => true, 'state' => 'otp', 'otp' => ['available' => true, 'verified' => false, 'required' => true]], 'portal_otp_required', 'portal_otp_required', 'open', 200, 'otp', 'recipient_no_email'],
    '§5.1 "תמיד לדרוש קוד": בלי מכשיר מאומת הפורטל אינו מציג ואינו מקבל דבר; נמען בלי מייל אינו יכול לקבל בקשה');
call('POST', 'office-portal-otp', ['on' => false], $avi);

// ── מקרה 12: משרד מושעה ──
call('POST', 'sys-office-status', ['office' => $OA, 'status' => 'suspended', 'reason' => 'בדיקה', 'confirm' => APR_CONFIRM_WORD], $owner);
$susp = $strip(portal('portal-open', ['token' => $T1b]));
call('POST', 'sys-office-status', ['office' => $OA, 'status' => 'active', 'reason' => 'בדיקה', 'confirm' => APR_CONFIRM_WORD], $owner);
eq([apr_val('SELECT status FROM offices WHERE pid = ?', [$OA]), $susp === $missing, portal('portal-open', ['token' => $T1b])[0]], ['active', true, 200], 'מקרה 12: משרד מושעה ← "הקישור אינו בתוקף", כמו כל קישור אחר');

// ── מייל: שם השולח וכתובת התשובה אינם פותחים כותרת ──
eq([apr_mail_opts(['from_name' => "משרד\r\nBcc: x@evil.example", 'reply_to' => "a@b.example\r\nBcc: x@evil.example"]), apr_mail_opts(['from_name' => '', 'reply_to' => 'a b@c.example']),
    apr_mail_opts(['from_name' => 'משרד "א" <x>', 'reply_to' => 'ok.name+tag@office.example'])],
    [['משרד Bcc: x@evil.example', null], ['שמאות מקרקעין', null], ['משרד א x', 'ok.name+tag@office.example']], 'שם שולח וכתובת תשובה: בלי CR/LF, בלי מירכאות וסוגריים; כתובת לא תקינה ← בלי Reply-To מיוחד');
$sentHdr = [];
$GLOBALS['apr_mail_fn'] = function (string ...$a) use (&$sentHdr) { $sentHdr = $a; return true; };
$keep = $GLOBALS['apr_mail'];
unset($GLOBALS['apr_mail']);
apr_mail_transport('x@y.example', 'נושא', 'גוף', ['from_name' => "רע\r\nX-Evil: 1", 'reply_to' => 'avi@example.com']);
$GLOBALS['apr_mail'] = $keep;
unset($GLOBALS['apr_mail_fn']);
eq([substr_count($sentHdr[3] ?? '', "\r\n"), str_contains($sentHdr[3] ?? '', 'X-Evil'), str_contains($sentHdr[3] ?? '', "Reply-To: avi@example.com\r\n"), str_starts_with($sentHdr[4] ?? '', '-fnoreply@')],
    [4, false, true, true], 'הכותרות של המייל: חמש שורות בדיוק, Reply-To לשמאי, ומעטפה -f');

// ── בסוף: אין אסימון בשום טבלה, ואין אזהרות ──
$all = $dump();
eq(array_values(array_filter([$T1, $T1b, $T2, $TX, $TE, $TE2, $TD, $TB], fn($t) => str_contains($all, $t))), [], '07-C4 אחרי כל התרחישים: אף אסימון אינו מופיע במסד — לא ביומן הפעולות ולא ביומן המיילים');
eq($warnings, [], 'בלי אזהרות PHP');

done();
