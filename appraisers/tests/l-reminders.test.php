<?php
// פרוסה 08 — תזכורות, ערוצים וקליטה נכנסת (docs/spec/08-reminders-channels.md §11): 08-A1 … 08-D4, מקרי הקצה, ההרשאות והבידוד.
require __DIR__ . '/lib.php';

$warnings = [];
$GLOBALS['apr_upload_rate'] = [100000, 600];
set_error_handler(function (int $no, string $msg, string $file, int $line) use (&$warnings) { if (!(error_reporting() & $no)) return true; $warnings[] = "$msg @" . basename($file) . ":$line"; return true; });
$GLOBALS['apr_mail'] = function (string $to, string $subject, string $text, array $opt = []) { $GLOBALS['t_mail'][] = ['to' => $to, 'subject' => $subject, 'text' => $text] + $opt; return true; };

// ספק מדומה: רושם כל קריאה ל-send, ועונה לפי $GLOBALS['t_mode'] (sent / failed / throw / כל דבר אחר = תשובה לא ברורה)
const SECRET_KEY = 'KEY-sup3r-s3cret-api-key-9f31';
const SECRET_HOOK = 'HOOK-s3cret-0123456789abcdefghijklmnopqrstuv';
final class MockAdapter implements AprChannelAdapter
{
    public function __construct(private array $conn, private array $config) {}
    public function send(array $m): array
    {
        $GLOBALS['t_sent'][] = $m + ['conn' => $this->conn['pid']];
        if ($GLOBALS['t_mode'] === 'throw') throw new RuntimeException('provider down, key=' . $this->config['api_key'] . ' to=' . $m['to']);
        return ['status' => $GLOBALS['t_mode'], 'ref' => 'ref-' . count($GLOBALS['t_sent'])];
    }
    public function status(string $ref): ?string { return $GLOBALS['t_status'] ?? null; }
    public function capabilities(): array { return []; }
    public function health(): array { return ['ok', 'מדומה']; }
    public function parseInbound(string $raw): ?array { return null; }
}
$mock = fn(array $caps) => ['channel' => 'email', 'label' => 'ספק מדומה', 'builtin' => false, 'scopes' => ['office', 'system'], 'fields' => ['api_key' => [8, 100]],
    'caps' => $caps + ['send' => true] + APR_CAPS_NONE, 'adapter' => fn(array $c, array $cfg) => new MockAdapter($c, $cfg)];
$GLOBALS['apr_channel_providers'] = ['mock' => $mock([]), 'mock_dr' => $mock(['delivery_reports' => true]), 'mock_sms' => ['channel' => 'sms'] + $mock(['free_text' => false, 'max_len' => 134])];
$GLOBALS['t_sent'] = [];
$GLOBALS['t_mode'] = 'sent';
$sent = fn() => count($GLOBALS['t_sent']);

$T0 = strtotime('2026-11-15T08:00:00Z');           // יום ראשון, 10:00 בישראל
$GLOBALS['apr_time'] = $T0;
$at = function (string $utc) { $GLOBALS['apr_time'] = strtotime($utc); };

$owner = ident('1', 'הבעלים', 'owner@example.com', ['platform_admin' => true]);
$avi = ident('2', 'אבי', 'avi@example.com');       // מנהל משרד א
$tal = ident('5', 'טל', 'tal@example.com');        // עובדת משרד א: בתיק C, לא בתיק D
$gil = ident('7', 'גיל', 'gil@example.com');       // חבר משרד א, לא משתתף בשום תיק
$ron = ident('8', 'רון', 'ron@example.com');       // מבקר בתיק C: view בלבד
$boaz = ident('4', 'בועז', 'boaz@example.com');    // מנהל משרד ב
call('GET', 'me', [], $owner);
$OA = office_for($avi, 'משרד אבי שמאים');
$OB = office_for($boaz, 'משרד ב');
$talPid = join_office($avi, $tal, 'tal@example.com', 'staff');
join_office($avi, $gil, 'gil@example.com', 'appraiser');
$ronPid = join_office($avi, $ron, 'ron@example.com', 'staff');

// כל תשובת API נאספת — 08-D1 סורק אותן בסוף
$GLOBALS['t_out'] = '';
function api(string $method, string $action, array $in = [], ?string $as = null, array $get = [], array $opt = []): array
{
    $r = call($method, $action, $in, $as, $get, $opt);
    $GLOBALS['t_out'] .= json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    return $r;
}
function req(string $key, string $title, string $party = 'client', int $sort = 1): array
{
    return ['key' => $key, 'group' => '', 'doc_type' => 'rights', 'title' => $title, 'priority' => 'normal', 'mandatory' => false, 'party_kind' => $party,
        'due' => ['mode' => 'relative', 'days' => 10], 'needs_review' => true, 'when' => null, 'sort' => $sort];
}
function profile(string $as, string $name, array $contact, array $items): array
{
    $P = call('POST', 'profile-create', ['scope' => 'office', 'name' => $name], $as)[1]['profile'];
    $content = ['flags' => [], 'requirements' => ['groups' => [], 'items' => $items], 'tasks' => ['items' => []], 'rules' => [], 'contact' => $contact];
    [, $d] = call('POST', 'profile-draft-open', ['profile' => $P], $as);
    $s = call('POST', 'profile-draft-save', ['profile' => $P, 'content' => $content, 'revision' => $d['draft']['revision']], $as);
    return [$P, call('POST', 'profile-publish', ['profile' => $P, 'note' => '', 'revision' => $s[1]['revision'] ?? ''], $as), $s];
}
$items = [req('rights', 'נסח טאבו'), req('plan', 'תשריט', 'client', 2), req('id', 'צילום תעודת זהות', 'client', 3), req('contract', 'הסכם מכר', 'lawyer', 4), req('permit', 'היתר בנייה', 'lawyer', 5)];
$reminders = ['enabled' => true, 'after_days' => [3, 7], 'max' => 3, 'days' => ['sun', 'mon', 'tue', 'wed', 'thu'], 'hours' => ['from' => '09:00', 'to' => '18:00'], 'stop_when' => APR_STOP_WHEN];
[$P, $pub] = profile($avi, 'דירה לרכישה', ['reminders' => $reminders], $items);
eq($pub[0], 200, 'פרופיל עם מקטע "קשר ותזכורות" מתפרסם');
[$PB] = profile($boaz, 'פרופיל ב', ['reminders' => $reminders], $items);

function make_case(string $as, string $profile, string $title, array $parties, bool $start = true): string
{
    $c = call('POST', 'case-create', ['profile' => $profile], $as)[1]['case'];
    call('POST', 'case-update', ['case' => $c, 'title' => $title, 'purpose' => 'purchase'], $as);
    call('POST', 'case-identifier-add', ['case' => $c, 'kind' => 'parcel', 'gush' => '6158', 'helka' => (string)random_int(1, 900)], $as);
    foreach ($parties as $i => $p) call('POST', 'case-party-add', ['case' => $c, 'is_orderer' => $i === 0] + $p, $as);
    if ($start && ($s = call('POST', 'case-start', ['case' => $c], $as))[0] !== 200) throw new RuntimeException('case-start: ' . json_encode($s[1], JSON_UNESCAPED_UNICODE));
    return $c;
}
$moshe = ['name' => 'משה לקוח', 'email' => 'moshe@client.example', 'kind' => 'client'];
$ruth = ['name' => 'עו"ד רות כהן', 'email' => 'ruth@law.example', 'kind' => 'lawyer'];
$crow = fn(string $pid) => apr_row('SELECT * FROM cases WHERE pid = ?', [$pid]);
$qrow = fn(string $pid) => apr_row('SELECT * FROM requests WHERE pid = ?', [$pid]);
$count = fn(string $t, string $w = '1 = 1') => (int)apr_val("SELECT COUNT(*) FROM $t WHERE $w");
$party = fn(string $case, string $kind) => apr_val('SELECT pid FROM case_parties WHERE case_id = ? AND kind = ? AND removed_at IS NULL', [$crow($case)['id'], $kind]);
$rq = fn(string $case) => array_column(apr_rows('SELECT * FROM requirements WHERE case_id = ? ORDER BY id', [$crow($case)['id']]), 'pid', 'origin_key');
$plan = fn(string $req) => apr_row('SELECT * FROM reminder_plans WHERE request_id = ?', [$qrow($req)['id']]);
$msgs = fn(string $req, string $w = '1 = 1') => apr_rows("SELECT * FROM messages WHERE request_id = ? AND $w ORDER BY id", [$qrow($req)['id']]);
$run = fn(string $req) => apr_reminder_run((int)$plan($req)['id']);
$token_of = fn(string $text) => preg_match('~/portal/#([A-Za-z0-9_-]{43})(?![A-Za-z0-9_-])~', $text, $m) === 1 ? $m[1] : '';
function portal(string $action, array $in = []): array
{
    apr_exec("DELETE FROM login_attempts WHERE key LIKE 'portal:%'");
    return call('POST', $action, $in, null);
}
/** תצוגה מקדימה ושליחה. */
function send_req(string $as, string $case, string $party, array $reqs, array $more = []): array
{
    $in = $more + ['case' => $case, 'party' => $party, 'requirements' => $reqs, 'channel' => 'email', 'contact_ok' => true];
    $pv = api('POST', 'request-preview', $in, $as);
    if ($pv[0] !== 200) return $pv;
    return api('POST', 'request-send', $in + ['preview' => $pv[1]['preview'], 'op' => 'op-' . bin2hex(random_bytes(6))], $as);
}

$C = make_case($avi, $P, 'דירה ברחוב הרצל 7', [$moshe, $ruth]);
$D = make_case($avi, $P, 'תיק אחר של משה', [$moshe]);
call('POST', 'case-member-add', ['case' => $C, 'user' => $talPid], $avi);
call('POST', 'case-member-add', ['case' => $C, 'user' => $ronPid, 'role' => 'visitor'], $avi);
$R = $rq($C);
apr_exec("INSERT INTO holidays (day, name, eve) VALUES ('2026-12-01', 'חג בדיקה', 0), ('2026-11-30', 'ערב חג בדיקה', 1)");

// ── §9 ו-08-D1: חיבור של המשרד — פעולה רגישה, רק מנהל, והסוד אינו חוזר ──
$save = ['provider' => 'mock', 'label' => 'ספק המשרד', 'config' => ['api_key' => SECRET_KEY]];
eq([code(api('POST', 'office-channel-save', $save, $avi)), code(api('POST', 'office-channel-save', $save + ['confirm' => 'אישור'], $tal)), code(api('POST', 'sys-channels', [], $avi)), code(api('GET', 'sys-channels', [], $avi)),
    code(api('POST', 'office-channel-save', ['provider' => 'server_mail', 'label' => 'x', 'confirm' => 'אישור'], $avi)), code(api('POST', 'office-channel-save', ['config' => ['api_key' => 'short']] + $save + ['confirm' => 'אישור'], $avi)),
    code(api('POST', 'office-channel-save', ['provider' => 'nope', 'label' => 'x', 'confirm' => 'אישור'], $avi))],
    ['confirm_required', 'forbidden', 'not_found', 'not_found', 'channel_config', 'channel_config', 'channel_config'],
    '§9 חיבור של המשרד: בלי אישור חוזר ← נדחה; חבר שאינו מנהל ← 403; מסכי המערכת למי שאינו מנהל המערכת ← 404; ספק בנוי, סוד קצר או ספק לא קיים ← נדחה');
$r = api('POST', 'office-channel-save', $save + ['confirm' => 'אישור'], $avi);
$MC = $r[1]['connection']['pid'] ?? '';
eq([$r[0], $r[1]['connection']['status'] ?? null, $r[1]['connection']['secret'] ?? null, array_key_exists('config', $r[1]['connection'] ?? []), array_key_exists('config_enc', $r[1]['connection'] ?? [])],
    [200, 'active', ['set' => true, 'tail' => substr(SECRET_KEY, -4)], false, false], '08-D1 חיבור נשמר: התשובה נושאת רק "מוגדר" ו-4 תווים אחרונים');
$stored = (string)apr_val('SELECT config_enc FROM channel_connections WHERE pid = ?', [$MC]);
eq([str_contains($stored, SECRET_KEY), str_contains((string)base64_decode($stored), SECRET_KEY), apr_conn_config(apr_row('SELECT * FROM channel_connections WHERE pid = ?', [$MC]))['api_key'] ?? null],
    [false, false, SECRET_KEY], '08-D1 במסד הסוד מוצפן (לא בטקסט גלוי ולא ב-base64), והמתאם מקבל אותו מפוענח');
eq([code(api('POST', 'office-channel-status', ['connection' => $MC, 'status' => 'disabled'], $boaz)), code(api('POST', 'office-channel-test', ['connection' => $MC], $boaz)),
    code(api('POST', 'office-channel-save', ['connection' => $MC, 'label' => 'גנוב', 'confirm' => 'אישור'], $boaz)), code(api('POST', 'sys-channel-status', ['connection' => $MC, 'status' => 'disabled', 'confirm' => 'אישור'], $owner)),
    api('POST', 'office-channel-test', ['connection' => $MC], $avi)[1]['health']['state'] ?? null, in_array($MC, array_column(api('GET', 'office-channels', [], $boaz)[1]['connections'], 'pid'), true)],
    ['not_found', 'not_found', 'not_found', 'not_found', 'ok', false], '§9 בידוד: חיבור של משרד א אינו נגיש למשרד ב (404 אחיד), ואינו חיבור מערכת');
$r = api('POST', 'office-channel-save', ['connection' => $MC, 'label' => 'ספק המשרד (עודכן)', 'confirm' => 'אישור'], $avi);
eq([$r[1]['connection']['label'] ?? null, $r[1]['connection']['secret']['tail'] ?? null, $r[1]['connection']['status'] ?? null], ['ספק המשרד (עודכן)', substr(SECRET_KEY, -4), 'active'], 'עדכון שם בלי לשלוח סוד — הסוד נשאר');

// ── 08-A: מנוע התזכורות ──
$r = send_req($avi, $C, $party($C, 'client'), [$R['rights'], $R['plan'], $R['id']], ['connection' => $MC]);
$Q1 = $r[1]['request'] ?? '';
$first = $GLOBALS['t_sent'][0] ?? [];
$tok0 = $token_of($first['text'] ?? '');
eq([$r[1]['message'] ?? null, $sent(), $first['conn'] ?? null, $plan($Q1)['state'] ?? null, $plan($Q1)['next_at'] ?? null, preg_match('/מזהה פנייה: APR-[0-9A-F]{20}/', $first['text'] ?? ''), $msgs($Q1)[0]['kind'] ?? null],
    ['sent', 1, $MC, 'active', apr_iso($T0 + 3 * 86400), 1, 'request'], '§5 בקשה שנשלחה דרך החיבור שנבחר ← תוכנית תזכורות עם צילום הכללים, מועד ראשון אחרי 3 ימים, ומזהה שיוך בהודעה');
apr_jobs_run(5.0, 'test');
eq([$sent(), $run($Q1)], [1, 'noop'], 'תזכורת שמועדה עוד לא הגיע — אינה נשלחת, גם בקריאה ישירה למנוע');

// 08-A3: מחוץ לחלון השעות — נדחית לתחילת החלון הבא, ואינה אובדת
$at('2026-11-18T20:00:00Z');                        // יום רביעי 22:00
eq([$run($Q1), $sent(), $plan($Q1)['next_at'], $plan($Q1)['state'], $count('messages', "kind = 'reminder'")], ['deferred', 1, '2026-11-19T07:00:00Z', 'active', 0], '08-A3 תזכורת ב-22:00 ← נדחית ל-09:00 של מחר; אין קריאה למתאם ואין שורת הודעה');
// 08-A4: רק הדרישות שעדיין פתוחות
apr_exec("UPDATE requirements SET status = 'approved' WHERE pid = ?", [$R['plan']]);
$at('2026-11-19T07:00:00Z');
apr_jobs_run(5.0, 'test');                          // דרך שירותי הרקע עצמם (reminders.tick)
$m1 = $GLOBALS['t_sent'][1] ?? [];
$tok1 = $token_of($m1['text'] ?? '');
$row = $msgs($Q1, "kind = 'reminder'")[0] ?? [];
eq([$sent(), str_contains($m1['text'] ?? '', 'נסח טאבו'), str_contains($m1['text'] ?? '', 'צילום תעודת זהות'), str_contains($m1['text'] ?? '', 'תשריט'), $m1['to'] ?? null, $m1['kind'] ?? null],
    [2, true, true, false, 'moshe@client.example', 'reminder'], '08-A3 / 08-A4 בתחילת החלון התזכורת נשלחת (דרך reminders.tick), ומונה רק את הדרישות שעדיין פתוחות');
eq([$row['status'] ?? null, (int)($row['reminder_no'] ?? 0), $row['idempotency_key'] ?? null, str_contains($row['body'] ?? '', $tok1), str_contains($row['body'] ?? '', 'נסח טאבו'), (int)$plan($Q1)['sent_count'], $plan($Q1)['next_at']],
    ['sent', 1, 'reminder:' . $qrow($Q1)['id'] . ':1', false, true, 1, '2026-11-22T08:00:00Z'], '§5 ההודעה נרשמת עם מזהה הפעולה, הנוסח נשמר כפי שנשלח — בלי האסימון — והתזכורת הבאה נקבעת ליום ה-7');
eq([$tok1 !== '' && $tok1 !== $tok0, portal('portal-open', ['token' => $tok1])[0], code(portal('portal-open', ['token' => $tok0])), $count('portal_links', 'revoked_at IS NULL AND request_id = ' . (int)$qrow($Q1)['id'])],
    [true, 200, 'portal_invalid', 1], 'תזכורת אינה שולחת אסימון ישן: קישור חדש, והקודם מבוטל רק אחרי שהיא נשלחה — לבקשה קישור חי אחד');
// 08-B2: הרצה חוזרת אחרי sent — אפס קריאות למתאם
apr_exec("UPDATE reminder_plans SET sent_count = 0, next_at = ? WHERE request_id = ?", [apr_now(), $qrow($Q1)['id']]);      // כאילו ההרצה נהרגה אחרי השליחה ולפני עדכון התוכנית
eq([$run($Q1), $sent(), (int)$plan($Q1)['sent_count'], $count('messages', "kind = 'reminder'")], ['noop', 2, 1, 1], '08-B2 הרצה חוזרת של אותה תזכורת אחרי sent ← אפס קריאות למתאם, ואין הודעה שנייה');
// 08-A5: אחרי המספר המרבי — אין נוספת
$at('2026-11-22T08:00:00Z');
eq([$run($Q1), $sent(), $plan($Q1)['state'], $plan($Q1)['stopped_reason'], $plan($Q1)['next_at']], ['sent', 3, 'stopped', 'max_reached', null], '08-A5 התזכורת האחרונה נשלחת, והתוכנית נעצרת');
apr_exec("UPDATE reminder_plans SET state = 'active', stopped_reason = NULL, next_at = ? WHERE request_id = ?", [apr_now(), $qrow($Q1)['id']]);
$at('2026-12-06T08:00:00Z');
eq([$run($Q1), $sent(), $plan($Q1)['state']], ['stopped:max_reached', 3, 'stopped'], '08-A5 גם תוכנית שהופעלה מחדש אינה שולחת מעבר ל-max');

// 08-A1: כל הדרישות אושרו רגע לפני המועד
$at('2026-11-15T08:00:00Z');
$Q2 = send_req($avi, $C, $party($C, 'lawyer'), [$R['contract'], $R['permit']], ['connection' => $MC])[1]['request'];
$n = $sent();
apr_exec("UPDATE requirements SET status = 'approved' WHERE pid IN (?, ?)", [$R['contract'], $R['permit']]);
$at('2026-11-18T08:00:00Z');
$out = $run($Q2);
$sk = $msgs($Q2, "kind = 'reminder'");
eq([$out, $sent() - $n, count($sk), $sk[0]['status'] ?? null, $sk[0]['skip_reason'] ?? null, $plan($Q2)['state'], $plan($Q2)['stopped_reason'], $run($Q2)],
    ['skipped:requirement_closed', 0, 1, 'skipped', 'requirement_closed', 'stopped', 'requirement_closed', 'noop'], '08-A1 תזכורת שמועדה הגיע וכל הדרישות אושרו ← לא נשלחת; נרשמת skipped עם הסיבה; התוכנית נעצרת');

// 08-A2: כל אחת מהבדיקות של §5 — כשהיא נכשלת, אין קריאה למתאם
$E = make_case($avi, $P, 'תיק הבדיקות', [$moshe]);
$RE = $rq($E);
$at('2026-11-15T08:00:00Z');
$Q3 = send_req($avi, $E, $party($E, 'client'), [$RE['rights'], $RE['plan']], ['connection' => $MC])[1]['request'];
$q3 = (int)$qrow($Q3)['id'];
$reset = function () use ($q3) {
    apr_exec("DELETE FROM messages WHERE request_id = ? AND kind = 'reminder'", [$q3]);
    apr_exec("UPDATE reminder_plans SET state = 'active', stopped_reason = NULL, sent_count = 0, next_at = ? WHERE request_id = ?", [apr_now(), $q3]);
};
$mcId = (int)apr_val('SELECT id FROM channel_connections WHERE pid = ?', [$MC]);
$hash = apr_mail_hash('moshe@client.example');
$checks = [
    '1 בקשה שבוטלה' => ["UPDATE requests SET status = 'revoked' WHERE id = $q3", "UPDATE requests SET status = 'active' WHERE id = $q3", 'skipped:request_revoked'],
    '1 קישור שבוטל' => ["UPDATE portal_links SET revoked_at = '2026-01-01T00:00:00Z' WHERE request_id = $q3", "UPDATE portal_links SET revoked_at = NULL WHERE request_id = $q3", 'skipped:request_inactive'],
    '1 בקשה שפגה' => ["UPDATE requests SET expires_at = '2026-11-16T00:00:00Z' WHERE id = $q3", "UPDATE requests SET expires_at = '2027-06-01T00:00:00Z' WHERE id = $q3", 'skipped:request_inactive'],
    '2 אין דרישה פתוחה' => ["UPDATE requirements SET status = 'received' WHERE pid IN ('{$RE['rights']}', '{$RE['plan']}')", "UPDATE requirements SET status = 'requested' WHERE pid IN ('{$RE['rights']}', '{$RE['plan']}')", 'skipped:requirement_closed'],
    '3 הנמען ענה' => ["INSERT INTO requirement_responses (pid, requirement_id, party_id, request_id, kind, text, created_at) SELECT 'resp-test', ri.requirement_id, q.party_id, q.id, 'comment', 'x', '2026-11-17T00:00:00Z' FROM requests q JOIN request_items ri ON ri.request_id = q.id WHERE q.id = $q3 LIMIT 1",
        "DELETE FROM requirement_responses WHERE pid = 'resp-test'", 'skipped:recipient_answered'],
    '4 הגורם הוסר' => ["UPDATE case_parties SET removed_at = '2026-11-16T00:00:00Z' WHERE id = (SELECT party_id FROM requests WHERE id = $q3)", "UPDATE case_parties SET removed_at = NULL WHERE id = (SELECT party_id FROM requests WHERE id = $q3)", 'skipped:party_removed'],
    '4 התיק נסגר' => ["UPDATE cases SET status = 'closed' WHERE pid = '$E'", "UPDATE cases SET status = 'collecting' WHERE pid = '$E'", 'skipped:case_closed'],
    '5 החיבור מושבת' => ["UPDATE channel_connections SET status = 'disabled' WHERE id = $mcId", "UPDATE channel_connections SET status = 'active' WHERE id = $mcId", 'skipped:connection_unavailable'],
    '5 החיבור נכשל' => ["UPDATE channel_connections SET status = 'failed' WHERE id = $mcId", "UPDATE channel_connections SET status = 'active' WHERE id = $mcId", 'skipped:connection_unavailable'],
    '5 הנמען ביקש להפסיק' => ["INSERT INTO channel_optouts (office_id, channel, to_hash, created_at) SELECT office_id, 'email', '$hash', 'x' FROM requests WHERE id = $q3", 'DELETE FROM channel_optouts', 'skipped:recipient_opted_out'],
    '6 עברנו את max' => ["UPDATE reminder_plans SET sent_count = 2 WHERE request_id = $q3", 'SELECT 1', 'stopped:max_reached'],
];
$got = $want = [];
foreach ($checks as $name => [$do, $undo, $expect]) {
    $at('2026-11-18T08:00:00Z');
    $reset();
    apr_exec($do);
    $n = $sent();
    $got[$name] = [$run($Q3), $sent() - $n, $plan($Q3)['state']];
    $want[$name] = [$expect, 0, 'stopped'];
    apr_exec($undo);
}
foreach (['7 מחוץ לשעות' => '2026-11-18T02:00:00Z', '7 יום שאינו בחלון (שישי)' => '2026-11-20T08:00:00Z', '8 שבת' => '2026-11-21T08:00:00Z', '8 חג' => '2026-12-01T08:00:00Z', '8 ערב חג אחרי 13:00' => '2026-11-30T12:00:00Z'] as $name => $when) {
    $at($when);
    $reset();
    $n = $sent();
    $got[$name] = [$run($Q3), $sent() - $n, $plan($Q3)['state']];
    $want[$name] = ['deferred', 0, 'active'];
}
eq($got, $want, '08-A2 כל אחת מהבדיקות של §5 (בקשה, דרישות, תשובת הנמען, גורם ותיק, חיבור, max, שעות, שבת וחג) — כשהיא נכשלת, אין קריאה למתאם');
eq([$count('tasks', "origin_key = 'reminders-stopped:$Q3' AND kind = 'system'"), $count('messages', "request_id = $q3 AND kind = 'reminder'")], [1, 0], 'חיבור שאינו זמין ← משימה אחת לאחראי (לא כפולה); דחייה אינה רושמת הודעה');
$at('2026-11-30T10:00:00Z');                        // ערב חג, 12:00 — עדיין בתוך החלון
$reset();
apr_exec("UPDATE requests SET expires_at = '2027-06-01T00:00:00Z' WHERE id = $q3");
$n = $sent();
eq([$run($Q3), $sent() - $n], ['sent', 1], 'בקרה: אחרי שכל המצבים הוחזרו, אותה תוכנית שולחת (ערב חג לפני 13:00 — בתוך החלון)');

// חלון השעות — החישוב עצמו, כולל מעבר לשעון קיץ
$win = fn(string $utc) => gmdate('Y-m-d\TH:i', apr_reminder_window(strtotime($utc), $reminders, 'Asia/Jerusalem'));
eq([$win('2026-11-18T10:00:00Z'), $win('2026-11-19T17:00:00Z'), $win('2026-11-21T10:00:00Z'), $win('2027-04-22T08:00:00Z'), $win('2027-04-21T09:00:00Z'), $win('2027-04-21T11:30:00Z'), $win('2027-03-25T17:00:00Z'), $win('2027-03-28T05:30:00Z')],
    ['2026-11-18T10:00', '2026-11-22T07:00', '2026-11-22T07:00', '2027-04-25T06:00', '2027-04-21T09:00', '2027-04-25T06:00', '2027-03-28T06:00', '2027-03-28T06:00'],
    '08-A3 חלון השעות לפי שעון ישראל: בתוך החלון — מיד; חמישי בערב ושבת ← ראשון 09:00; חג וערב חג אחרי 13:00 ← היום המותר הבא; אחרי המעבר לשעון קיץ 09:00 הוא 06:00 UTC');
eq($win('2026-11-18T10:00:00Z') === gmdate('Y-m-d\TH:i', apr_reminder_window(strtotime('2026-11-18T10:00:00Z'), $reminders, 'Not/AZone')), true, 'אזור זמן לא תקין ← ברירת המחדל (ישראל), בלי חריגה');

// ── 08-B: כשל, לא ידוע, ניסיון חוזר ──
$F = make_case($avi, $P, 'תיק הכשלים', [$moshe]);
$RF = $rq($F);
$at('2026-11-15T08:00:00Z');
$r = send_req($avi, $F, $party($F, 'client'), [$RF['rights']], ['connection' => $MC]);
$Q4 = $r[1]['request'];
$tokF = $token_of(end($GLOBALS['t_sent'])['text']);
$at('2026-11-18T08:00:00Z');
$GLOBALS['t_mode'] = 'throw';
$n = $sent();
$out = $run($Q4);
$fm = $msgs($Q4, "kind = 'reminder'")[0];
eq([$out, $sent() - $n, $fm['status'], $fm['error'], (int)$fm['attempts'], $count('messages', "request_id = {$fm['request_id']} AND kind = 'reminder' AND status = 'sent'"), portal('portal-open', ['token' => $tokF])[0],
    in_array($Q4, array_column(api('GET', 'requests-failed', [], $avi)[1]['failed'], 'request'), true), (int)$plan($Q4)['sent_count'], $plan($Q4)['next_at']],
    ['failed', 1, 'failed', 'RuntimeException', 1, 0, 200, true, 0, '2026-11-18T08:05:00Z'],
    '08-B1 המתאם זורק חריגה ← failed (לא sent); ביומן סוג החריגה בלבד; התיק מציג כשל; והקישור שכבר אצל הנמען נשאר בתוקף');
eq([$run($Q4), $sent() - $n], ['deferred', 1], '08-B4 לפני שעברה ההשהיה — אין ניסיון חוזר');
$at('2026-11-18T08:05:00Z');
$GLOBALS['t_mode'] = 'sent';
$out = $run($Q4);
$again = $msgs($Q4, "kind = 'reminder'");
eq([$out, $sent() - $n, count($again), $again[0]['id'] === $fm['id'], $again[0]['idempotency_key'] === $fm['idempotency_key'], $again[0]['status'], (int)$again[0]['attempts'], end($GLOBALS['t_sent'])['idem'], (int)$plan($Q4)['sent_count']],
    ['sent', 2, 1, true, true, 'sent', 2, 'msg:' . $fm['pid'] . ':2', 1], '08-B4 ניסיון חוזר אחרי failed: אותה שורה ואותו מזהה פעולה — לא הודעה שנייה');
// שלושה כשלים רצופים: התוכנית נעצרת, והחיבור עובר ל-failed
$at('2026-11-22T08:00:00Z');
$GLOBALS['t_mode'] = 'failed';
$n = $sent();
$outs = [];
foreach (['2026-11-22T08:00:00Z', '2026-11-22T08:05:00Z', '2026-11-22T08:35:00Z', '2026-11-22T10:35:00Z'] as $when) { $at($when); $outs[] = $run($Q4); }
$conn = apr_row('SELECT * FROM channel_connections WHERE pid = ?', [$MC]);
eq([$outs, $sent() - $n, $plan($Q4)['stopped_reason'], $conn['status'], $count('audit_log', "action = 'channel.failed'"), $count('messages', "request_id = {$fm['request_id']} AND reminder_no = 2")],
    [['failed', 'failed', 'failed', 'noop'], 3, 'send_failed', 'failed', 1, 1], '§5 / §6.3 failed ברור: עד 3 ניסיונות בהשהיה גדלה, באותה שורה; 3 כשלים רצופים ← החיבור עובר ל-failed עם רישום ביומן');
$GLOBALS['t_mode'] = 'sent';
eq([code(send_req($avi, $F, $party($F, 'client'), [$RF['plan']], ['connection' => $MC])), api('POST', 'office-channel-status', ['connection' => $MC, 'status' => 'active'], $avi)[0],
    code(api('POST', 'office-channel-status', ['connection' => $MC, 'status' => 'active'], $avi)), in_array('link_copy', ['link_copy'], true)], ['channel_unavailable', 200, 'channel_state', true],
    '§6.3 / 08-E2 חיבור שנכשל אינו שולח (channel_unavailable — המסך מציע העתקת קישור), ומנהל המשרד מפעיל אותו מחדש');
$r = send_req($avi, $F, $party($F, 'client'), [$RF['plan']], ['channel' => 'link_copy']);
eq([$r[0], is_string($r[1]['link'] ?? null), apr_val('SELECT 1 FROM reminder_plans WHERE request_id = ?', [$qrow($r[1]['request'])['id']])], [200, true, false], '17.2 כשל בחיבור אינו חוסם עבודה ידנית: "העתקת קישור" זמינה, בלי תוכנית תזכורות');

// 08-B3: תשובה לא ברורה ← unknown, בלי ניסיון חוזר
$G = make_case($avi, $P, 'תיק הלא-ידוע', [$moshe]);
$RG = $rq($G);
$at('2026-11-15T08:00:00Z');
$Q5 = send_req($avi, $G, $party($G, 'client'), [$RG['rights']], ['connection' => $MC])[1]['request'];
$at('2026-11-18T08:00:00Z');
$GLOBALS['t_mode'] = 'timeout';
$n = $sent();
$out = $run($Q5);
$um = $msgs($Q5, "kind = 'reminder'")[0];
apr_exec('UPDATE reminder_plans SET next_at = ? WHERE request_id = ?', [apr_now(), $qrow($Q5)['id']]);
$GLOBALS['t_mode'] = 'sent';
apr_exec('UPDATE reminder_plans SET sent_count = 0 WHERE request_id = ?', [$qrow($Q5)['id']]);
eq([$out, $um['status'], $run($Q5), $sent() - $n, $msgs($Q5, "kind = 'reminder'")[0]['status'], in_array($Q5, array_column(api('GET', 'requests-failed', [], $avi)[1]['unknown'], 'request'), true),
    $count('portal_links', 'revoked_at IS NULL AND request_id = ' . (int)$qrow($Q5)['id'])],
    ['unknown', 'unknown', 'noop', 1, 'unknown', true, 2], '08-B3 המתאם מחזיר תשובה לא ברורה ← unknown; הרצה חוזרת אינה שולחת שוב; הבקשה מופיעה ב"לא ידוע אם נשלחו"; ושני הקישורים נשארים');
// 08-B5: ספק בלי delivery_reports — לעולם לא delivered
$GLOBALS['t_status'] = 'delivered';
$c1 = api('POST', 'message-check', ['message' => $um['pid']], $avi);
eq([$c1[1]['status'] ?? null, $msgs($Q5, "kind = 'reminder'")[0]['status'], code(api('POST', 'message-check', ['message' => $um['pid']], $ron)), code(api('POST', 'message-check', ['message' => $um['pid']], $boaz))],
    ['sent', 'sent', 'not_found', 'not_found'], '08-B5 הספק עונה "נמסר" בערוץ בלי delivery_reports ← ההודעה נשארת sent; "בדיקה" — רק למי שיש לו contact בתיק');
$DR = api('POST', 'office-channel-save', ['provider' => 'mock_dr', 'label' => 'ספק עם דיווח', 'config' => ['api_key' => SECRET_KEY . '-2'], 'confirm' => 'אישור'], $avi)[1]['connection']['pid'];
$Q6 = send_req($avi, $G, $party($G, 'client'), [$RG['plan']], ['connection' => $DR])[1]['request'];
$dm = $msgs($Q6)[0];
eq([api('POST', 'message-check', ['message' => $dm['pid']], $avi)[1]['status'] ?? null, $count('messages', "status = 'delivered'")], ['delivered', 1], '08-B5 בקרה: בערוץ שמדווח מסירה אותה תשובה כן קובעת delivered');
unset($GLOBALS['t_status']);

// הרצה שנהרגה בין הרישום לתשובת הספק, ושתי הרצות במקביל (מקרה קצה 4)
$at('2026-11-15T08:00:00Z');
$Q7 = send_req($avi, $G, $party($G, 'client'), [$RG['id']], ['connection' => $MC])[1]['request'];
$at('2026-11-18T08:00:00Z');
$n = $sent();
$inner = null;
$GLOBALS['apr_before_reminder_send'] = function () use (&$inner, $run, $Q7) { unset($GLOBALS['apr_before_reminder_send']); $inner = $run($Q7); };
$out = $run($Q7);
eq([$out, $inner, $sent() - $n, count($msgs($Q7, "kind = 'reminder'"))], ['sent', 'noop', 1, 1], 'מקרה 4: הרצה שנייה של אותה תזכורת באמצע הראשונה ← אינה שולחת; הודעה אחת');
$at('2026-11-22T08:00:00Z');
$GLOBALS['apr_before_reminder_send'] = function () { unset($GLOBALS['apr_before_reminder_send']); throw new RuntimeException('killed'); };
$n = $sent();
try { $run($Q7); $killed = false; } catch (RuntimeException) { $killed = true; }
$st1 = $msgs($Q7, 'reminder_no = 2')[0]['status'];
$again = $run($Q7);
$at('2026-11-22T08:11:00Z');
eq([$killed, $st1, $again, $run($Q7), $msgs($Q7, 'reminder_no = 2')[0]['status'], $sent() - $n, $run($Q7)], [true, 'planned', 'noop', 'unknown', 'unknown', 0, 'noop'],
    '§5 הרצה שנהרגה אחרי רישום ההודעה: 10 דקות — ממתינים; אחר כך "לא ידוע", בלי שליחה חוזרת');

// "עצירת תזכורות" — contact בתיק; תיק סגור — קריאה בלבד
$at('2026-11-15T08:00:00Z');
$Q8 = send_req($avi, $C, $party($C, 'client'), [$R['rights']], ['connection' => $MC])[1]['request'];
eq([code(api('POST', 'reminders-stop', ['request' => $Q8], $ron)), code(api('POST', 'reminders-stop', ['request' => $Q8], $gil)), code(api('POST', 'reminders-stop', ['request' => $Q8], $boaz)),
    api('POST', 'reminders-stop', ['request' => $Q8], $tal)[0], $plan($Q8)['stopped_reason'], code(api('POST', 'reminders-stop', ['request' => $Q8], $avi))],
    ['forbidden', 'not_found', 'not_found', 200, 'manual', 'request_state'], '§9 עצירת תזכורות: view בלבד ← 403; לא משתתף / משרד אחר ← 404; contact ← נעצרת; פעם שנייה ← 409');
$at('2026-11-18T08:00:00Z');
$n = $sent();
eq([$run($Q8), $sent() - $n], ['noop', 0], 'תוכנית שנעצרה ידנית אינה שולחת');
$tl = api('GET', 'case-requests', [], $ron, ['case' => $C])[1];
$q1 = array_values(array_filter($tl['requests'], fn($x) => $x['pid'] === $Q1))[0];
$q2 = array_values(array_filter($tl['requests'], fn($x) => $x['pid'] === $Q2))[0];
eq([array_column($q1['messages'], 'kind'), $q2['messages'][0]['status'], $q2['messages'][0]['skip_reason'], $q2['reminders']['stopped_reason'], $q1['messages'][0]['delivery'], in_array($MC, array_column($tl['connections'], 'pid'), true)],
    [['reminder', 'reminder', 'request'], 'skipped', 'requirement_closed', 'requirement_closed', false, true], '08-E1 ציר הזמן של בקשה: נשלחה, תזכורות, דילוג עם הסיבה, ומצב התוכנית');
$at('2026-11-15T08:00:00Z');
$Q9 = send_req($avi, $D, $party($D, 'client'), [$rq($D)['rights']], ['connection' => $MC])[1]['request'];
call('POST', 'case-status', ['case' => $D, 'status' => 'closed', 'reason' => 'סיום'], $avi);
$at('2026-11-18T08:00:00Z');
$n = $sent();
eq([$crow($D)['status'], code(api('POST', 'reminders-stop', ['request' => $Q9], $avi)), $run($Q9), $sent() - $n], ['closed', 'case_closed', 'skipped:case_closed', 0], 'תיק סגור: קריאה בלבד, ובלי תזכורות');
call('POST', 'case-status', ['case' => $D, 'status' => 'collecting', 'reason' => 'פתיחה מחדש'], $avi);

// מסרון (ספק מדומה): נוסח קצר, והקישור תמיד שלם
$link = 'https://example.test/mini_projects/appraisers/portal/#' . str_repeat('a', 43);
$short = apr_channel_short_text(str_repeat('מילה ארוכה מאוד ', 40) . "\n$link\nסוף", $link, 134);
eq([mb_strlen($short) <= 134, str_ends_with($short, $link), substr_count($short, 'portal/#')], [true, true, 1], '§6.2 ערוץ מוגבל באורך: ההודעה נחתכת, והקישור נשאר שלם');

// ── 08-D2: חיבור מערכת והקצאה למשרדים ──
$CB = make_case($boaz, $PB, 'תיק של משרד ב', [$moshe]);
$RB = $rq($CB);
$sys = api('GET', 'sys-channels', [], $owner)[1];
$SM = array_values(array_filter($sys['connections'], fn($k) => $k['provider'] === 'server_mail'))[0]['pid'];
$at('2026-11-15T08:00:00Z');
$r = send_req($boaz, $CB, $party($CB, 'client'), [$RB['rights']]);
$QB = $r[1]['request'] ?? '';
eq([$r[1]['message'] ?? null, end($GLOBALS['t_mail'])['to'] ?? null, $plan($QB)['state'] ?? null, array_column($sys['catalog']['channels'], 'state', 'channel')],
    ['sent', 'moshe@client.example', 'active', ['email' => 'available', 'sms' => 'needs_provider', 'whatsapp' => 'needs_provider', 'storage' => 'unavailable']],
    '§6.2 מייל דרך השרת: חיבור מערכת שמותר לכל המשרדים; מסרון ו-WhatsApp בקטלוג כ"נדרש חשבון ספק" (08-E3)');
eq([code(api('POST', 'sys-channel-grant', ['connection' => $SM, 'all_offices' => false], $avi)), api('POST', 'sys-channel-grant', ['connection' => $SM, 'all_offices' => false], $owner)[0]], ['not_found', 200], 'הקצאה — רק מנהל המערכת');
eq([code(send_req($boaz, $CB, $party($CB, 'client'), [$RB['plan']])), code(send_req($boaz, $CB, $party($CB, 'client'), [$RB['plan']], ['connection' => $SM])), code(send_req($boaz, $CB, $party($CB, 'client'), [$RB['plan']], ['connection' => $MC])),
    $plan($QB)['state'], $plan($QB)['stopped_reason'], $count('tasks', "origin_key = 'reminders-stopped:$QB'"), in_array($SM, array_column(api('GET', 'office-channels', [], $boaz)[1]['connections'], 'pid'), true)],
    ['channel_unavailable', 'not_found', 'not_found', 'stopped', 'connection_unavailable', 1, false],
    '08-D2 משרד בלי הרשאה לחיבור מערכת אינו שולח דרכו — גם לא כשהוא נוקב במזהה שלו, ולא בחיבור של משרד אחר; התוכניות שנשענו עליו נעצרות עם משימה (מקרה 8)');
api('POST', 'sys-channel-grant', ['connection' => $SM, 'office' => $OB, 'on' => true], $owner);
eq([send_req($boaz, $CB, $party($CB, 'client'), [$RB['plan']])[1]['message'] ?? null, code(send_req($avi, $C, $party($C, 'client'), [$R['id']], ['connection' => $SM]))], ['sent', 'not_found'], '08-D2 אחרי הקצאה למשרד ב — הוא שולח; משרד א, בלי הקצאה, אינו רואה את החיבור');
api('POST', 'sys-channel-grant', ['connection' => $SM, 'all_offices' => true], $owner);

// ── 08-D3: נוסחים ──
$bad = ['reminders' => $reminders, 'templates' => ['reminder' => ['subject' => 'תזכורת', 'body' => 'שלום {שם_הנמען}, הנכס ב-{כתובת_הנכס}']]];
[, $pubBad] = profile($avi, 'פרופיל עם משתנה אסור', $bad, $items);
[, $pubOk] = profile($avi, 'פרופיל תקין', ['templates' => ['reminder' => ['subject' => 'תזכורת מ-{שם_המשרד}', 'body' => "שלום {שם_הנמען}\n{רשימת_הדרישות}\n{קישור}\n{תאריך_יעד}\n{שם_השמאי}"]]] + $bad, $items);
[, , $saveBad] = profile($avi, 'מבנה שבור', ['reminders' => ['after_days' => ['x']]], $items);
eq([code($pubBad), array_column($pubBad[1]['error']['checks']['errors'] ?? [], 'code'), $pubOk[0], code($saveBad)], ['publish_blocked', ['bad_variable'], 200, 'content_invalid'], '08-D3 נוסח עם משתנה שאינו ברשימה הסגורה ← הפרסום נחסם; כל ששת המשתנים המוכרים ← מתפרסם');
$evil = "רעה\r\nBcc: attacker@evil.example\n{שם_המשרד}";
eq([apr_tpl_subject('שלום {שם_הנמען}', ['שם_הנמען' => $evil]), apr_tpl_render('{שם_הנמען} / {שם_המשרד} / {לא_קיים}', ['שם_הנמען' => '{שם_המשרד}', 'שם_המשרד' => 'משרד']), apr_tpl_val($evil),
    apr_tpl_unknown('{שם_הנמען} {x} {__construct} {${a}}')],
    ['שלום רעה Bcc: attacker@evil.example {שם_המשרד}', '{שם_המשרד} / משרד / {לא_קיים}', 'רעה Bcc: attacker@evil.example {שם_המשרד}', ['x', '__construct', 'a']],
    '08-D3 הצבה: בלי CR/LF בנושא, ערך שהוצב אינו נסרק שוב, ומשתנה לא מוכר אינו מוחלף');

// ── 08-D4: שליחה אוטומטית ──
[$PA] = profile($avi, 'פרופיל אוטומטי', ['send_mode' => 'auto', 'reminders' => $reminders], [req('rights', 'נסח טאבו'), req('loan', 'אישור יתרת הלוואה', 'bank', 2)]);
$n = $count('messages');
$mails = $sent();
$H = make_case($avi, $PA, 'תיק אוטומטי', [$moshe, ['name' => 'בנק בלי מייל', 'kind' => 'bank']], false);
$st = api('POST', 'case-start', ['case' => $H], $avi);
$hid = (int)$crow($H)['id'];
$bank = apr_row("SELECT * FROM case_parties WHERE case_id = ? AND kind = 'bank'", [$hid]);
eq([$st[0], $count('messages') - $n, $sent() - $mails, end($GLOBALS['t_sent'])['to'], $count('messages', "party_id = {$bank['id']}"), $count('requests', "party_id = {$bank['id']}"),
    array_column(apr_rows("SELECT title, kind FROM tasks WHERE case_id = ? AND origin_key LIKE 'auto-send:%'", [$hid]), 'kind', 'title'), apr_val("SELECT status FROM requirements WHERE case_id = ? AND origin_key = 'loan'", [$hid])],
    [200, 1, 1, 'moshe@client.example', 0, 0, ['לשלוח בקשה ידנית — בנק בלי מייל' => 'system'], 'required'], '08-D4 פרופיל auto: לגורם עם מייל נשלחת בקשה; לגורם בלי מייל — משימה ידנית, ואפס הודעות');
api('POST', 'sys-channel-grant', ['connection' => $SM, 'all_offices' => false], $owner);
api('POST', 'sys-channel-grant', ['connection' => $SM, 'office' => $OB, 'on' => false], $owner);
[$PAB] = profile($boaz, 'פרופיל אוטומטי ב', ['send_mode' => 'auto'], [req('rights', 'נסח טאבו')]);
$n = $count('messages');
$H2 = make_case($boaz, $PAB, 'תיק אוטומטי בלי חיבור', [$moshe], false);
api('POST', 'case-start', ['case' => $H2], $boaz);
eq([$count('messages') - $n, $count('tasks', "case_id = " . (int)$crow($H2)['id'] . " AND origin_key LIKE 'auto-send:%'"), $crow($H2)['status']], [0, 1, 'collecting'], '08-D4 auto בלי חיבור פעיל ← משימה ידנית, שום הודעה, והתיק נפתח כרגיל');
api('POST', 'sys-channel-grant', ['connection' => $SM, 'all_offices' => true], $owner);

// ── 08-C: קליטה נכנסת ──
$relay = ['provider' => 'relay', 'label' => 'מייל נכנס', 'config' => ['webhook_secret' => SECRET_HOOK], 'confirm' => 'אישור'];
$RL = api('POST', 'office-channel-save', $relay, $avi)[1]['connection']['pid'];
$SR = api('POST', 'sys-channel-save', ['label' => 'מייל נכנס של המערכת'] + $relay, $owner)[1]['connection']['pid'];
api('POST', 'sys-channel-grant', ['connection' => $SR, 'all_offices' => true], $owner);
/** webhook: גוף גולמי וחתימה. $o: secret, t, sig (לחתימה מוכנה), body (גוף אחר מזה שנחתם), max. */
function hook(string $conn, array $payload, array $o = []): array
{
    apr_exec("DELETE FROM login_attempts WHERE key LIKE 'inbound:%'");
    $raw = json_encode($payload + ['id' => 'm-' . bin2hex(random_bytes(6))], JSON_UNESCAPED_UNICODE);
    $t = $o['t'] ?? apr_time();
    $sig = $o['sig'] ?? "t=$t,v1=" . hash_hmac('sha256', "$t.$raw", $o['secret'] ?? SECRET_HOOK);
    $body = $o['body'] ?? $raw;
    return api('POST', 'inbound-relay', [], $o['as'] ?? null, ['c' => $conn], ['env' => ['signature' => $sig, 'raw_reader' => fn(int $max) => strlen($body) <= ($o['max'] ?? $max) ? $body : null]]);
}
function pdf(string $mark = ''): string
{
    return "%PDF-1.4\n%$mark\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
}
$at('2026-11-16T08:00:00Z');
$snap = fn() => [$count('messages'), $count('files'), $count('audit_log', 'case_id IS NOT NULL'), $count('requirement_files'), $count('tasks')];
$inbox = fn(string $as) => api('GET', 'inbox', [], $as)[1]['items'] ?? null;

// 08-C6: חתימה
$before = [$snap(), $count('inbound_items'), $count('inbound_receipts')];
$msg = ['from' => 'moshe@client.example', 'subject' => 'מסמכים', 'text' => 'מצורף'];
$denied = [
    'חתימה בסוד אחר' => hook($RL, $msg, ['secret' => str_repeat('x', 40)]),
    'בלי חתימה' => hook($RL, $msg, ['sig' => '']),
    'חתימה במבנה אחר' => hook($RL, $msg, ['sig' => 'sha256=' . str_repeat('a', 64)]),
    'גוף שונה מזה שנחתם' => hook($RL, $msg, ['body' => '{"id":"zzz","from":"moshe@client.example","text":"הוחלף"}']),
    'חותמת זמן ישנה' => hook($RL, $msg, ['t' => apr_time() - 301]),
    'חותמת זמן עתידית' => hook($RL, $msg, ['t' => apr_time() + 301]),
    'חיבור שאינו קיים' => hook('0123456789abcdef', $msg),
    'מזהה חיבור שאינו מזהה' => hook("x' OR '1'='1", $msg),
    'חיבור מספק אחר' => hook($MC, $msg, ['secret' => SECRET_KEY]),
    'גוף מעל התקרה' => hook($RL, $msg, ['max' => 10]),
];
api('POST', 'office-channel-status', ['connection' => $RL, 'status' => 'disabled'], $avi);
$denied['חיבור מושבת, חתימה נכונה'] = hook($RL, $msg);
api('POST', 'office-channel-status', ['connection' => $RL, 'status' => 'active'], $avi);
$bodies = array_unique(array_map(fn($r) => json_encode([$r[0], array_diff_key($r[1], ['req' => 1])], JSON_UNESCAPED_UNICODE), $denied));
eq([count($bodies), array_values($bodies)[0] ?? null, [$snap(), $count('inbound_items'), $count('inbound_receipts')] === $before],
    [1, '[404,{"ok":false,"error":{"code":"not_found","message":"לא נמצא"}}]', true],
    '08-C6 webhook: חתימה שגויה, גוף שהוחלף, חותמת ישנה, חיבור שאינו קיים / מושבת / מספק אחר, גוף גדול מדי ← אותה תשובת 404 בדיוק, ואין פריט חדש');
$GLOBALS['t_fetch_site'] = 'cross-site';
$x = hook($RL, $msg);
unset($GLOBALS['t_fetch_site']);
$GLOBALS['t_content_type'] = 'text/plain';
$y = hook($RL, $msg);
unset($GLOBALS['t_content_type']);
eq([code($x), code($y), code(api('GET', 'inbound-relay', [], null, ['c' => $RL])), code(hook($RL, ['from' => 5]))], ['csrf', 'csrf', 'method', 'inbound_invalid'], 'webhook: רק POST, רק JSON, לא מדפדפן של אתר אחר; גוף חתום שאינו בפורמט ← 422');

// 08-C1: בלי מזהה שיוך ← לתור, ואפס שורות בתיק
$before = $snap();
$r = hook($RL, $msg + ['files' => [['name' => '../../evil.php', 'data' => base64_encode(pdf('c1'))]]]);
$it = apr_row('SELECT * FROM inbound_items ORDER BY id DESC LIMIT 1');
$f0 = json_decode($it['file_ids'], true)[0] ?? [];
eq([$r[0], $snap() === $before, $count('inbound_items'), $it['why'], (int)$it['office_id'] === (int)apr_val('SELECT id FROM offices WHERE pid = ?', [$OA]), $it['status'], $f0['name'] ?? null,
    preg_match('/^[a-f0-9]{32}\z/', $f0['key'] ?? ''), is_file(apr_inbound_dir() . '/' . ($f0['key'] ?? 'x')), glob(APR_DATA_DIR . '/*.php') + glob(dirname(APR_DATA_DIR) . '/evil.php')],
    [200, true, 1, 'no_ref', true, 'unassigned', 'evil.php', 1, true, []], '08-C1 הודעה בלי מזהה שיוך ← inbound_items; אפס שורות חדשות בשום תיק (הודעות, קבצים, יומן, משימות); שם הקובץ אינו מגיע לנתיב בדיסק');
// 08-C2: מזהה תקף + שולח תואם ← בתיק
$ref = apr_request_ref($Q8);
$before = $snap();
$items0 = $count('inbound_items');
$r = hook($RL, ['from' => 'Moshe <MOSHE@client.example>', 'subject' => "Re: בקשה [$ref]", 'text' => "<script>alert(1)</script>\nמצרף את הנסח", 'files' => [['name' => 'נסח.pdf', 'data' => base64_encode(pdf('c2'))]]]);
$in = apr_row("SELECT * FROM messages WHERE direction = 'in' ORDER BY id DESC LIMIT 1");
$file = apr_row("SELECT * FROM files WHERE channel = 'email' ORDER BY id DESC LIMIT 1");
eq([$r[0], $count('inbound_items') - $items0, $in['case_id'] === $crow($C)['id'], $in['request_id'] === $qrow($Q8)['id'], $in['kind'], $in['status'], str_contains($in['body'], '<script>'),
    $file['case_id'] === $crow($C)['id'], $file['uploader_kind'], $count('requirement_files', "file_id = {$file['id']}"), $file['original_name'], $msgs($Q8, "direction = 'out'")[0]['status'],
    apr_val("SELECT actor_kind FROM audit_log WHERE action = 'inbound.received' ORDER BY id DESC LIMIT 1")],
    [200, 0, true, true, 'inbound', 'received', true, true, 'system', 0, 'נסח.pdf', 'answered', 'recipient'],
    '08-C2 מזהה שיוך תקף + השולח הוא הנמען ← ההודעה נרשמת בתיק (התוכן נשמר כטקסט), הקובץ ב"לא שויכו" — בלי דרישה ובלי אישור — וההודעה היוצאת "נענתה"');
$docs = api('GET', 'case-docs', [], $avi, ['case' => $C])[1];
check('08-C2 הקובץ הנכנס מופיע בתיק כקובץ שלא שויך', str_contains(json_encode($docs, JSON_UNESCAPED_UNICODE), $file['pid']));
// 08-C3, מקרים 9 ו-10: מזהה תקף משולח אחר, או לבקשה שבוטלה ← לתור
$before = $snap();
hook($RL, ['from' => 'ruth@law.example', 'subject' => $ref, 'text' => 'שולח אחר']);
$w1 = apr_val('SELECT why FROM inbound_items ORDER BY id DESC LIMIT 1');
call('POST', 'request-revoke', ['request' => $Q8], $avi);
$before2 = $snap();
hook($RL, ['from' => 'moshe@client.example', 'subject' => $ref, 'text' => 'לבקשה שבוטלה']);
$w2 = apr_val('SELECT why FROM inbound_items ORDER BY id DESC LIMIT 1');
hook($RL, ['from' => 'moshe@client.example', 'subject' => 'APR-' . str_repeat('A', 20), 'text' => 'מזהה שאינו קיים']);
hook($RL, ['from' => 'moshe@client.example', 'subject' => apr_request_ref($QB), 'text' => 'מזהה של בקשה במשרד אחר']);
eq([$w1, $w2, $snap()[0] === $before[0], $snap() === $before2, array_column(apr_rows('SELECT why FROM inbound_items ORDER BY id DESC LIMIT 2'), 'why'), $count('inbound_items')],
    ['sender_mismatch', 'request_inactive', true, true, ['no_ref', 'no_ref'], 5], '08-C3 מזהה תקף משולח אחר, לבקשה שבוטלה, מזהה מומצא, או מזהה של משרד אחר ← לתור; אפס שורות בתיק');
// מקרה 12: אותה הודעה פעמיים; מקרה 11: קובץ שנדחה
$n = $count('inbound_items');
$r1 = hook($RL, ['id' => 'same-1', 'from' => 'moshe@client.example', 'text' => 'פעם אחת']);
$r2 = hook($RL, ['id' => 'same-1', 'from' => 'moshe@client.example', 'text' => 'פעם אחת']);
hook($RL, ['from' => 'moshe@client.example', 'text' => 'קובץ רע', 'files' => [['name' => 'run.exe', 'data' => base64_encode("MZ\x90\x00" . str_repeat("\0", 200))], ['name' => 'ok.pdf', 'data' => base64_encode(pdf('ok'))]]]);
$fl = json_decode(apr_val('SELECT file_ids FROM inbound_items ORDER BY id DESC LIMIT 1'), true);
eq([$r1[1]['repeat'] ?? false, $r2[1]['repeat'] ?? false, $count('inbound_items') - $n, isset($fl[0]['rejected']), isset($fl[0]['key']), isset($fl[1]['key'])], [false, true, 2, true, false, true],
    'מקרה 12: אותו מזהה הודעה פעמיים ← נרשמת פעם אחת (גם הגנה מפני שידור חוזר); מקרה 11: קובץ שנדחה — הפריט מציג "נדחה" עם הסיבה, בלי הקובץ');

// 08-C5: בידוד התור
$A = $inbox($avi);
$itemPid = apr_val("SELECT pid FROM inbound_items WHERE why = 'no_ref' AND file_ids LIKE '%evil%'");
$mine = array_values(array_filter($A, fn($x) => $x['pid'] === $itemPid))[0] ?? [];
$officeA = (int)apr_val('SELECT id FROM offices WHERE pid = ?', [$OA]);
$allCand = array_merge(...array_map(fn($j) => json_decode($j, true), array_column(apr_rows('SELECT candidates FROM inbound_items'), 'candidates')));
eq([count($A), $inbox($boaz), code(api('GET', 'inbox', [], $gil)), code(api('GET', 'inbox', [], $tal)), code(api('POST', 'inbox-assign', ['item' => $itemPid, 'case' => $CB], $boaz)),
    code(api('POST', 'inbox-dismiss', ['item' => $itemPid, 'reason' => 'x'], $boaz)), code(api('POST', 'inbox-assign', ['item' => $itemPid, 'case' => $CB], $avi)), code(api('POST', 'inbox-assign', ['item' => $itemPid, 'case' => $C], $gil)),
    $count('cases', 'id IN (' . implode(',', $allCand ?: [0]) . ") AND office_id <> $officeA"), array_key_exists('key', $mine['files'][0] ?? ['key' => 1]), $mine['from'] ?? null],
    [7, [], 'forbidden', 'forbidden', 'not_found', 'not_found', 'not_found', 'forbidden', 0, false, 'moshe@client.example'],
    '08-C5 בידוד: התור של משרד א אינו נגיש למשרד ב (רשימה ריקה, 404 על פריט); חבר שלא הוסמך ← 403; שיוך לתיק של משרד אחר ← 404; candidates רק מתיקי משרד החיבור; מפתח האחסון אינו יוצא');
api('POST', 'office-inbox-handler', ['user' => $talPid, 'on' => true], $avi);
$T = $inbox($tal);
$tm = array_values(array_filter($T, fn($x) => $x['pid'] === $itemPid))[0] ?? [];
$am = array_column($mine['candidates'] ?? [], 'pid');
eq([code(api('POST', 'office-inbox-handler', ['user' => $talPid, 'on' => true], $tal)), count($T), array_column($tm['candidates'] ?? [], 'pid'), in_array($D, $am, true) && in_array($C, $am, true),
    code(api('POST', 'inbox-assign', ['item' => $itemPid, 'case' => $D], $tal)), api('GET', 'requests-failed', [], $tal)[1]['inbox'], api('GET', 'requests-failed', [], $gil)[1]['inbox']],
    ['forbidden', 7, [$C], true, 'not_found', 7, null], '08-C5 "מטפל בתור" (הסמכה של מנהל המשרד): רואה את התור, אבל candidates מציג רק תיקים שהוא משתתף בהם, ושיוך לתיק שאינו שלו ← 404');
// שיוך ודחייה
$before = $snap();
$r = api('POST', 'inbox-assign', ['item' => $itemPid, 'case' => $C, 'requirement' => $R['id']], $tal);
$af = apr_row('SELECT f.*, rf.review_state FROM files f JOIN requirement_files rf ON rf.file_id = f.id WHERE f.pid = ?', [$r[1]['files'][0]['file'] ?? '']);
eq([$r[0], $snap()[0] - $before[0], $af['review_state'] ?? null, $af['original_name'] ?? null, apr_val('SELECT status FROM requirements WHERE pid = ?', [$R['id']]), apr_val('SELECT status FROM inbound_items WHERE pid = ?', [$itemPid]),
    is_file(apr_inbound_dir() . '/' . $f0['key']), code(api('POST', 'inbox-assign', ['item' => $itemPid, 'case' => $C], $avi)), $count('audit_log', "action = 'inbound.assign'"), count(api('GET', 'case-requests', [], $avi, ['case' => $C])[1]['unassigned_messages'])],
    [200, 1, 'pending', 'evil.php', 'received', 'assigned', false, 'not_found', 1, 1], '§7 שיוך לתיק ולדרישה: ההודעה נרשמת בתיק, הקובץ עובר את צינור הקליטה כ"ממתין לבדיקה" (לא מאושר), נרשם ביומן, ושיוך שני ← 404');
$second = $A[0]['pid'];
eq([code(api('POST', 'inbox-dismiss', ['item' => $second], $avi)), api('POST', 'inbox-dismiss', ['item' => $second, 'reason' => 'ספאם'], $avi)[0], apr_val('SELECT text FROM inbound_items WHERE pid = ?', [$second]), count($inbox($avi)),
    count(glob(apr_inbound_dir() . '/*'))], ['reason_required', 200, null, 5, 0], '§7 דחייה — עם נימוק; התוכן והקבצים שבהסגר נמחקים');

// מקרה 6: הנמען ביקש להפסיק
$at('2026-11-15T08:00:00Z');
$Q10 = send_req($avi, $E, $party($E, 'client'), [$RE['id']], ['connection' => $MC])[1]['request'];
$at('2026-11-16T08:00:00Z');
hook($RL, ['from' => 'moshe@client.example', 'subject' => 'Re: ' . apr_request_ref($Q10), 'text' => ' הסר. ']);
eq([$plan($Q10)['stopped_reason'], $count('channel_optouts'), $count('tasks', "origin_key = 'optout:$Q10'")], ['recipient_opted_out', 1, 1], 'מקרה 6: הנמען השיב "הסר" ← התוכנית נעצרת, השמאי מקבל משימה, והיעד מסומן');
$Q11 = send_req($avi, $E, $party($E, 'client'), [$RE['id']], ['connection' => $MC]);
eq([$Q11[1]['message'] ?? null, $count('channel_optouts')], ['sent', 0], 'מקרה 6: שליחה ביוזמת השמאי ("הכתובת נכונה") מסירה את הסימון');

// 08-C4: חיבור מערכת
$n = $count('inbound_items');
$before = $snap();
hook($SR, ['from' => 'moshe@client.example', 'subject' => 'נושא-סודי-של-לקוח', 'text' => 'תוכן-סודי-של-לקוח', 'files' => [['name' => 'שם-קובץ-סודי.pdf', 'data' => base64_encode(pdf('c4'))]]]);
hook($SR, ['from' => 'stranger@nowhere.example', 'subject' => 'נושא-סודי-של-זר', 'text' => 'x']);
hook($SR, ['from' => 'ruth@law.example', 'subject' => 'מעורכת הדין', 'text' => 'רק במשרד א']);
$sysBox = api('GET', 'sys-inbox', [], $owner);
$sj = json_encode($sysBox[1], JSON_UNESCAPED_UNICODE);
$shared = array_values(array_filter($sysBox[1]['items'], fn($x) => $x['why'] === 'shared'))[0] ?? [];
$unk = array_values(array_filter($sysBox[1]['items'], fn($x) => $x['why'] === 'unknown_sender'))[0] ?? [];
eq([$count('inbound_items') - $n, $snap() === $before, count($sysBox[1]['items']), count($inbox($avi)), $inbox($boaz), str_contains($sj, 'סודי'), str_contains($sj, 'moshe@'), $shared['from'] ?? null,
    array_column($shared['offices'] ?? [], 'pid') == [$OA, $OB] || array_column($shared['offices'] ?? [], 'pid') == [$OB, $OA], $unk['offices'] ?? null, code(api('GET', 'sys-inbox', [], $avi)),
    array_keys($shared)],
    [3, true, 2, 6, [], false, false, 'mo…@client.example', true, [], 'not_found', ['pid', 'channel', 'connection', 'from', 'received_at', 'why', 'offices', 'can_reply']],
    '08-C4 חיבור מערכת: שולח שתואם לגורמים בשני משרדים, או לאף אחד ← אינו בתור של אף משרד, ותוכנו (נושא, גוף, שם קובץ, כתובת מלאה) אינו בתשובה למנהל המערכת; שולח של משרד אחד ← לתור של אותו משרד');
eq([code(api('POST', 'sys-inbox-route', ['item' => $unk['pid'], 'office' => $OA], $owner)), code(api('POST', 'sys-inbox-route', ['item' => $shared['pid'], 'office' => $OA], $avi)), code(api('POST', 'inbox-assign', ['item' => $shared['pid'], 'case' => $C], $avi)),
    api('POST', 'sys-inbox-route', ['item' => $shared['pid'], 'office' => $OA], $owner)[0], count($inbox($avi)), $inbox($boaz), count(api('GET', 'sys-inbox', [], $owner)[1]['items']),
    code(api('POST', 'sys-inbox-dismiss', ['item' => $unk['pid']], $owner))],
    ['not_found', 'not_found', 'not_found', 200, 7, [], 1, 'reason_required'], '08-C4 עד שמנהל המערכת מנתב — אף משרד אינו מגיע לפריט; ניתוב רק למשרד שהשולח מוכר בו; דחייה — עם נימוק');
$mails = count($GLOBALS['t_mail']);
$rep = api('POST', 'sys-inbox-reply', ['item' => $unk['pid']], $owner);
$last = end($GLOBALS['t_mail']);
eq([$rep[1]['message'] ?? null, count($GLOBALS['t_mail']) - $mails, $last['to'], str_contains($last['subject'] . $last['text'], 'סודי'), code(api('POST', 'sys-inbox-reply', ['item' => $unk['pid']], $owner)),
    api('POST', 'sys-inbox-dismiss', ['item' => $unk['pid'], 'reason' => 'לא מזוהה'], $owner)[0]], ['sent', 1, 'stranger@nowhere.example', false, 'request_state', 200],
    '§7 מנהל המערכת: תשובה אוטומטית בנוסח קבוע (פעם אחת, בלי תוכן מההודעה), או דחייה');

// הגבלת קצב ל-webhook
apr_exec("DELETE FROM login_attempts WHERE key LIKE 'inbound:%'");
$codes = [];
for ($i = 0; $i < 22; $i++) {
    $raw = '{"id":"r' . $i . '","from":"a@b.example"}';
    $codes[] = code(call('POST', 'inbound-relay', [], null, ['c' => $RL], ['env' => ['signature' => 't=' . apr_time() . ',v1=' . str_repeat('0', 64), 'raw_reader' => fn() => $raw]]));
}
eq([array_count_values($codes), $count('inbound_items', "from_display = 'a@b.example'")], [['not_found' => 20, 'inbound_rate' => 2], 0], 'webhook: 20 בקשות שנדחו מ-IP אחד ← 429 (הגבלת קצב לפי IP), ואין פריט');
apr_exec("DELETE FROM login_attempts WHERE key LIKE 'inbound:%'");

// ── 08-D1: הסודות אינם בשום תשובת API, ביומן הפעולות או בקובץ בתיקיית הנתונים ──
api('GET', 'office-channels', [], $avi);
api('GET', 'sys-channels', [], $owner);
api('GET', 'dev-audit', [], $owner);
api('GET', 'office-audit', [], $avi);
api('GET', 'dev-health', [], $owner);
$dump = '';
foreach (array_column(apr_rows("SELECT name FROM sqlite_master WHERE type = 'table'"), 'name') as $t) $dump .= json_encode(apr_rows("SELECT * FROM \"$t\""), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
$filesHit = [];
$scan = function (string $d) use (&$scan, &$filesHit) {
    foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $f) {
        if (is_dir("$d/$f")) $scan("$d/$f");
        elseif (str_contains((string)file_get_contents("$d/$f"), SECRET_KEY) || str_contains((string)file_get_contents("$d/$f"), SECRET_HOOK)) $filesHit[] = $f;
    }
};
$scan(APR_DATA_DIR);
eq([strlen($GLOBALS['t_out']) > 50000, str_contains($GLOBALS['t_out'], SECRET_KEY), str_contains($GLOBALS['t_out'], SECRET_HOOK), str_contains($GLOBALS['t_out'], 'config_enc'), str_contains($dump, SECRET_KEY), str_contains($dump, SECRET_HOOK),
    $filesHit, str_contains($dump, 'provider down')],
    [true, false, false, false, false, false, [], false], '08-D1 אחרי שמירה, שליחה, כשל ו-webhook: הסוד אינו בשום תשובת API, בשום טבלה (גם לא ביומן הפעולות או בשגיאת ההודעה), ולא בקובץ בתיקיית הנתונים');
eq(array_values(array_filter(apr_job_kinds(), fn($k) => $k['every'] === 300))[0]['label'] ?? null, 'תזכורות: שולח תזכורות שמועדן הגיע, אחרי בדיקה מחודשת של כל בקשה', 'reminders.tick רשום בשירותי הרקע, כל 5 דקות');
eq($warnings, [], 'אין אזהרות PHP');
done();
