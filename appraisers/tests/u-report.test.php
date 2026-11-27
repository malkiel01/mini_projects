<?php
// פרוסה 17 — הדוח: פרקים, טיוטה אוטומטית, בקרה ואישור (docs/spec/17-report.md §13): 17-A1 … 17-D3, מקרי הקצה של §12, ההרשאות, הבידוד, rev
$dir = sys_get_temp_dir() . '/apr-test-' . bin2hex(random_bytes(5));
mkdir($dir, 0700, true);
define('APR_DATA_DIR', $dir);
define('APR_DB_PATH', $dir . '/app.sqlite');
require __DIR__ . '/lib.php';

$warnings = [];
$GLOBALS['apr_upload_rate'] = [100000, 600];
set_error_handler(function (int $no, string $msg, string $file, int $line) use (&$warnings) { if (!(error_reporting() & $no)) return true; $warnings[] = "$msg @" . basename($file) . ":$line"; return true; });
$GLOBALS['apr_time'] = 1794400000;      // שעון קפוא

/** מבטל את הטרנזקציה של סריקת הסכימה (סבב 3): מה שנשתל בה אינו נשאר במסד. */
final class SweepRollback extends RuntimeException {}

/** המתאם המדומה: רושם כל בקשה ומחזיר את התשובה שנקבעה. */
final class FakeR implements AprAiAdapter
{
    public array $calls = [];
    public mixed $next = null;          // fn(array $request): array
    public function complete(#[\SensitiveParameter] string $key, #[\SensitiveParameter] array $request): array
    {
        $this->calls[] = $request;
        return ($this->next)($request);
    }
    public function count_tokens(#[\SensitiveParameter] string $key, #[\SensitiveParameter] array $request): int { return 1; }
    public function health(#[\SensitiveParameter] string $key): string { return 'ok'; }
    public function capabilities(string $model): array { return ['text' => true, 'vision' => true, 'pdf' => true]; }
}
function reply(array|string $body, string $stop = 'end_turn'): array
{
    return ['text' => is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE), 'in_tokens' => 900, 'out_tokens' => 200, 'stop_reason' => $stop];
}
$fake = new FakeR();
$GLOBALS['apr_ai_adapters'] = ['anthropic' => $fake];
/** תשובה "טובה": פסקה אחת לכל פריט שנשלח, עם ה-src שלו, ובלי מספר שלא נשלח. */
$echo = function (array $request): array {
    $text = json_encode($request, JSON_UNESCAPED_UNICODE);
    preg_match_all('/(d\d+)\. /', (string)$text, $m);
    return reply(['blocks' => array_map(fn($id) => ['text' => 'ניסוח של פריט, בלי מספרים', 'src' => $id], array_slice(array_values(array_unique($m[1])), 0, 3))]);
};
$fake->next = $echo;

$owner = ident('1', 'הבעלים', 'owner@example.com', ['platform_admin' => true]);
$avi = ident('2', 'אבי', 'avi@example.com');       // מנהל משרד א, שמאי — האחראי על התיקים (היחיד עם approve_report)
$dana = ident('3', 'דנה', 'dana@example.com');     // שמאית, שותפה בתיק — בלי approve_report
$tal = ident('5', 'טל', 'tal@example.com');        // עובדת משרד עם edit — אינה שמאית
$noa = ident('9', 'נועה', 'noa@example.com');      // צפייה בלבד
$boaz = ident('4', 'בועז', 'boaz@example.com');    // משרד ב
call('GET', 'me', [], $owner);
office_for($avi, 'משרד א');
office_for($boaz, 'משרד ב');
$danaPid = join_office($avi, $dana, 'dana@example.com', 'appraiser');
$talPid = join_office($avi, $tal, 'tal@example.com', 'staff');
$noaPid = join_office($avi, $noa, 'noa@example.com', 'trainee');

function make_profile(string $as, ?array $report = null): string
{
    $pid = call('POST', 'profile-create', ['scope' => 'office', 'name' => 'פרופיל ' . bin2hex(random_bytes(3))], $as)[1]['profile'];
    $it = ['key' => 'contract', 'group' => '', 'doc_type' => 'rights', 'title' => 'הסכם מכר', 'priority' => 'normal', 'mandatory' => false, 'party_kind' => 'client', 'due' => ['mode' => 'relative', 'days' => 3], 'needs_review' => true, 'when' => null, 'sort' => 1];
    [, $d] = call('POST', 'profile-draft-open', ['profile' => $pid], $as);
    $content = ['flags' => [], 'requirements' => ['groups' => [], 'items' => [$it]], 'tasks' => ['items' => []], 'rules' => [], 'visit' => ['groups' => [], 'fields' => []]] + ($report !== null ? ['report' => $report] : []);
    $s = call('POST', 'profile-draft-save', ['profile' => $pid, 'content' => $content, 'revision' => $d['draft']['revision']], $as);
    $r = call('POST', 'profile-publish', ['profile' => $pid, 'note' => 'ראשון', 'revision' => $s[1]['revision'] ?? ''], $as);
    if ($r[0] !== 200) throw new RuntimeException('publish: ' . json_encode([$s[1], $r[1]], JSON_UNESCAPED_UNICODE));
    return $pid;
}
function make_case(string $as, string $profile, ?string $keyDate = '2026-09-01'): string
{
    $c = call('POST', 'case-create', ['profile' => $profile], $as)[1]['case'];
    call('POST', 'case-update', ['case' => $c, 'title' => 'דירה ברחוב הרצל', 'purpose' => 'purchase', 'property_type' => 'apartment', 'key_date' => $keyDate], $as);
    call('POST', 'case-identifier-add', ['case' => $c, 'kind' => 'parcel', 'gush' => '6158', 'helka' => '12'], $as);
    call('POST', 'case-party-add', ['case' => $c, 'name' => 'משה לקוח', 'email' => 'moshe@client.example', 'kind' => 'client', 'is_orderer' => true], $as);
    if (($s = call('POST', 'case-start', ['case' => $c], $as))[0] !== 200) throw new RuntimeException('case-start: ' . json_encode($s[1], JSON_UNESCAPED_UNICODE));
    return $c;
}
$post = fn(string $action, array $body, string $as = null) => call('POST', $action, $body, $as ?? $GLOBALS['avi']);
$caseId = fn(string $pid) => (int)apr_val('SELECT id FROM cases WHERE pid = ?', [$pid]);
$userPid = fn(string $email) => apr_val('SELECT pid FROM users WHERE email = ?', [$email]);
$vrev = fn(string $visit) => (int)apr_val('SELECT rev FROM visits WHERE pid = ?', [$visit]);
/** ביקור שלם: תכנון, התחלה וסיום בתוצאה שנבחרה. מחזיר [pid, תשובת הסיום]. */
$visit = function (string $case, string $result = 'completed') use ($post, $vrev, $userPid) {
    $p = $post('visit-plan', ['case' => $case, 'visitor' => $userPid('avi@example.com')]);
    $v = $p[1]['visit']['pid'] ?? $p[1]['visit'] ?? throw new RuntimeException('visit-plan: ' . json_encode($p[1], JSON_UNESCAPED_UNICODE));
    if ($result !== 'postponed') $post('visit-start', ['visit' => $v, 'rev' => $vrev($v)]);
    return [$v, $post('visit-finish', ['visit' => $v, 'rev' => $vrev($v), 'result' => $result] + ($result === 'completed' ? [] : ['note' => 'לא התאפשר']))];
};
$report = fn(string $case, string $as = null) => call('GET', 'case-report', [], $as ?? $GLOBALS['avi'], ['case' => $case]);
$ver = fn(string $version, string $as = null) => call('GET', 'report-version', [], $as ?? $GLOBALS['avi'], ['version' => $version]);
$versions = fn(string $case) => apr_rows('SELECT * FROM report_versions WHERE case_id = ? AND deleted_at IS NULL ORDER BY number', [$caseId($case)]);
$sec = fn(array $view, string $key) => array_column($view['sections'], null, 'key')[$key] ?? null;
$srow = fn(string $pid) => apr_row('SELECT * FROM report_sections WHERE pid = ?', [$pid]);
$types = fn(array $blocks) => array_column($blocks, 'type');
$fact = fn(string $case, string $attr, string $value, array $more = [], string $as = null) => $post('fact-add', ['case' => $case, 'attribute' => $attr, 'value' => $value, 'source' => ['kind' => 'user', 'note' => 'בדיקה']] + $more, $as);

function profile_report(string $as, string $pid, array $report): void
{
    [, $d] = call('POST', 'profile-draft-open', ['profile' => $pid], $as);
    $s = call('POST', 'profile-draft-save', ['profile' => $pid, 'content' => ['report' => $report] + $d['content'], 'revision' => $d['draft']['revision']], $as);
    $r = call('POST', 'profile-publish', ['profile' => $pid, 'note' => 'דוח', 'revision' => $s[1]['revision'] ?? ''], $as);
    if ($r[0] !== 200) throw new RuntimeException('publish: ' . json_encode([$s[1], $r[1]], JSON_UNESCAPED_UNICODE));
}
$jobs = fn(string $like = 'report.%') => apr_rows('SELECT dedupe_key, status FROM jobs WHERE dedupe_key LIKE ? ORDER BY id', [$like]);
$frev = fn(string $pid) => (int)apr_val('SELECT rev FROM facts WHERE pid = ?', [$pid]);
$vvrev = fn(string $pid) => (int)apr_val('SELECT rev FROM report_versions WHERE pid = ?', [$pid]);
$save = fn(string $section, mixed $content, string $as = null, ?int $rev = null) => $post('report-section-save', ['section' => $section, 'rev' => $rev ?? (int)apr_val('SELECT rev FROM report_sections WHERE pid = ?', [$section]), 'content' => $content], $as);
$P_TEXT = [['type' => 'p', 'text' => 'טקסט שכתב השמאי']];
/** ממלא כל פרק שאין בו תוכן של ממש, כדי שלא יישאר "פרק חובה ריק". */
$fill = function (string $version) use ($ver, $save, $P_TEXT) {
    foreach ($ver($version)[1]['sections'] as $s) if (!array_filter($s['blocks'], fn($b) => !in_array($b['type'], ['note_missing', 'h'], true))) $save($s['pid'], $P_TEXT);
};
$acksAll = fn(string $version) => array_fill_keys(array_column(array_filter($ver($version)[1]['findings'], fn($f) => $f['level'] === 'warn'), 'key'), 'נבדק ואושר בידי השמאי');
$approve = fn(string $version, array $acks, string $as = null) => $post('report-approve', ['version' => $version, 'rev' => $vvrev($version), 'acks' => $acks, 'confirm' => 'אישור'], $as);
$submit = fn(string $version, string $as = null) => $post('report-submit', ['version' => $version, 'rev' => $vvrev($version)], $as);
$blocking = fn(string $version) => array_values(array_unique(array_column(array_filter($ver($version)[1]['findings'], fn($f) => $f['level'] === 'block'), 'kind')));

$P = make_profile($avi);                                              // ברירת המחדל: trigger = visit_completed
$PM = make_profile($avi, ['trigger' => 'manual']);
$PB = make_profile($boaz);
$members = function (string $case) use ($avi, $danaPid, $talPid, $noaPid) {
    foreach ([[$danaPid, null], [$talPid, ['view' => true, 'edit' => true]], [$noaPid, ['view' => true]]] as [$u, $perms]) {
        call('POST', 'case-member-add', ['case' => $case, 'user' => $u], $avi);
        if ($perms) call('POST', 'case-member-update', ['case' => $case, 'user' => $u, 'perms' => $perms], $avi);
    }
};

// ═══ מקטע "דוח" בפרופיל (§4) ═══════════════════════════════════
eq(array_column(apr_pc_report(['sections' => [['key' => 'purpose', 'title' => 'מטרה', 'ai' => true], ['key' => 'documents', 'title' => 'מסמכים', 'ai' => true], ['key' => 'appendix', 'title' => 'נספחים', 'ai' => true],
    ['key' => 'my_section', 'title' => 'שלי', 'ai' => true], ['key' => 'property', 'title' => 'זיהוי', 'ai' => true], ['key' => 'planning', 'title' => 'תכנון', 'ai' => true, 'build' => 'manual']]])['sections'], 'ai', 'key'),
    ['purpose' => false, 'documents' => false, 'appendix' => false, 'my_section' => false, 'property' => true, 'planning' => false],
    'סטייה 7: בפרופיל, "מותר לנסח בבינה" נשמר רק לפרק שברשימה הסגורה ונבנה מהנתונים — לא purpose, documents, appendix או פרק שהמשתמש הוסיף');
$d = apr_pc_report_default();
$forced = apr_pc_report(['sections' => [['key' => 'methods', 'title' => 'שיטות', 'ai' => true], ['key' => 'comps', 'title' => 'עסקאות', 'ai' => true], ['key' => 'assume', 'title' => 'הנחות', 'ai' => true],
    ['key' => 'conclusion', 'title' => 'מסקנה', 'ai' => true, 'build' => 'manual'], ['key' => 'free', 'title' => 'חופשי', 'ai' => true, 'build' => 'manual'], ['key' => 'visit', 'title' => 'ביקור', 'ai' => true, 'mandatory' => true]],
    'findings' => ['no_approver' => 'warn', 'mandatory_empty' => 'warn', 'ai_unreviewed' => 'warn', 'image_not_report' => 'warn', 'ai_number' => 'warn', 'task_open' => 'block'], 'trigger' => 'manual', 'include_unverified' => 'omit']);
$bad = [];
foreach ([['trigger' => 'x'], ['sections' => 'x'], ['sections' => [['key' => 'a b', 'title' => 'x']]], ['sections' => [['key' => 'a', 'title' => 'x'], ['key' => 'a', 'title' => 'y']]],
    ['findings' => ['nope' => 'warn']], ['findings' => ['task_open' => 'maybe']], ['include_excluded_comps' => 'yes'], ['sections' => array_fill(0, 31, ['key' => 'k', 'title' => 't'])]] as $i => $x) {
    try { apr_pc_report($x); $bad[] = $i; } catch (AprError $e) { if ($e->errorCode !== 'content_invalid') $bad[] = $e->errorCode; }
}
eq([count($d['sections']), $d['trigger'], array_column($d['sections'], 'ai', 'key'), array_column($forced['sections'], 'ai', 'key'), array_intersect_key($forced['findings'], ['no_approver' => 1, 'mandatory_empty' => 1, 'ai_unreviewed' => 1, 'image_not_report' => 1, 'ai_number' => 1, 'task_open' => 1]),
    [$forced['trigger'], $forced['include_unverified']], $bad, array_keys(APR_REPORT_FINDINGS) === array_keys($d['findings'])],
    [11, 'visit_completed', ['purpose' => false, 'property' => true, 'area_desc' => true, 'visit' => true, 'planning' => true, 'documents' => false, 'methods' => false, 'comps' => false, 'assume' => false, 'conclusion' => false, 'appendix' => false],
        ['methods' => false, 'comps' => false, 'assume' => false, 'conclusion' => false, 'free' => false, 'visit' => true],
        ['no_approver' => 'block', 'mandatory_empty' => 'block', 'ai_unreviewed' => 'block', 'image_not_report' => 'block', 'ai_number' => 'warn', 'task_open' => 'block'], ['manual', 'omit'], [], true],
    '§4 מקטע "דוח": אחד-עשר הפרקים לדוגמה; methods / comps / assume / conclusion ופרק ידני — ai נכפה ל-false גם כשהפרופיל מבקש (ש-23); ארבעת הממצאים הראשונים אינם יורדים מ"חוסם", האחרים — לפי הפרופיל; מבנה שגוי נדחה');

// ═══ 17-A: יצירת הטיוטה ═════════════════════════════════════════
$C = make_case($avi, $P);
$members($C);
[$Vp] = $visit($C, 'postponed');
$C0 = make_case($avi, $P);
[$Vn] = $visit($C0, 'no_access');
eq([$jobs(), $versions($C), $versions($C0)], [[], [], []], '17-A2 ביקור שנדחה (postponed) או שלא התאפשרה בו גישה (no_access) אינו מפעיל את הטריגר: אין משימה ואין גרסה');
[$V1, $fin] = $visit($C);
$j1 = $jobs();
apr_jobs_run(5.0);
apr_report_job(['case' => $C]);                                       // ניסיון חוזר של המשימה
apr_tx(fn() => apr_report_on_visit(apr_row('SELECT * FROM cases WHERE pid = ?', [$C]), apr_row('SELECT * FROM visits WHERE pid = ?', [$V1])));     // הטריגר שוב, על אותו ביקור
apr_jobs_run(5.0);
$vs = $versions($C);
$VER = $vs[0]['pid'];
$view = $ver($VER)[1];
eq([$fin[0], $j1, count($vs), [(int)$vs[0]['number'], $vs[0]['state'], $vs[0]['origin'], $vs[0]['created_by'], $vs[0]['snapshot'], $vs[0]['approved_at']], array_column($view['sections'], 'key'),
    json_decode($vs[0]['template'], true)['trigger'], (int)apr_val("SELECT COUNT(*) FROM audit_log WHERE action = 'report.draft_create' AND actor_user_id IS NULL"), $report($C)[1]['versions'][0]['state'], $report($C)[1]['can_create']],
    [200, [['dedupe_key' => 'report.draft:' . $C, 'status' => 'pending']], 1, [1, 'draft', 'auto', null, null, null], array_column($d['sections'], 'key'), 'visit_completed', 1, 'draft', null],
    '17-A1 ביקור שהושלם, בפרופיל visit_completed ← גרסה 1, draft, origin = auto, בשם המערכת, עם צילום התבנית; 17-A6 המשימה פעמיים והטריגר פעמיים ← גרסה אחת');

// 17-A4: תיק בלי נתונים — הטיוטה נוצרת, עם "לא ידוע", ובלי 0 במקום ערך חסר
$byKey = array_column($view['sections'], null, 'key');
$allBlocks = array_merge(...array_column($view['sections'], 'blocks'));
$zeros = array_filter($allBlocks, fn($b) => $b['type'] === 'fact' || preg_match('/(?<![\d.,-])0(?![\d.,-])/', json_encode(array_diff_key($b, ['src' => 1]), JSON_UNESCAPED_UNICODE)) === 1);
eq([array_map(fn($k) => in_array('note_missing', $types($byKey[$k]['blocks']), true), ['property', 'area_desc', 'planning', 'documents', 'methods', 'comps', 'assume']), $byKey['conclusion']['blocks'], $byKey['conclusion']['state'], array_values($zeros),
    $types($byKey['purpose']['blocks']), $byKey['purpose']['blocks'][1]['text'], $types($byKey['visit']['blocks']), $byKey['visit']['blocks'][0]['src']['pid'], array_column($byKey['visit']['sources'], 'pid'),
    in_array('note_missing', array_column($view['findings'], 'kind'), true), in_array('לא ידוע: תיאור הנכס', array_column($view['findings'], 'text'), true)],
    [array_fill(0, 7, true), [], 'empty', [], ['p', 'p', 'p'], 'המועד הקובע לשומה: 2026-09-01', ['p'], $V1, [$V1], true, true],
    '17-A4 תיק בלי נתונים: הטיוטה נוצרת; בכל פרק נתונים יש note_missing; **אין בשום בלוק 0 במקום ערך חסר**; פרק ידני נשאר ריק; 17-C4 החוסר מופיע ברשימת הממצאים; פרק הביקור מפנה לביקור כמקור');

// 17-A3: פרופיל manual — לא נוצרת לבד; נוצרת בלחיצה. שינוי הפרופיל אינו משנה תיק קיים
$CM = make_case($avi, $PM);
$CA2 = make_case($avi, $P);                                          // נפתח כשהפרופיל אוטומטי
profile_report($avi, $P, ['trigger' => 'manual']);                   // ועכשיו הפרופיל ידני — לתיקים חדשים
$CN = make_case($avi, $P);
$visit($CM); $visit($CA2); $visit($CN);
apr_jobs_run(5.0);
$auto = [count($versions($CM)), count($versions($CA2)), count($versions($CN)), $report($CM)[1]['trigger'], $report($CA2)[1]['trigger'], $report($CM)[1]['can_create']];
$mk = $post('report-draft-create', ['case' => $CM]);
$mk2 = $post('report-draft-create', ['case' => $CM]);
$vm = $versions($CM);
profile_report($avi, $P, ['trigger' => 'visit_completed']);
eq([$auto, $mk[0], code($mk2), count($vm), [$vm[0]['origin'], (int)$vm[0]['created_by'] > 0, $vm[0]['state']]], [[0, 1, 0, 'manual', 'visit_completed', 'draft'], 200, 'report_exists', 1, ['manual', true, 'draft']],
    '17-A3 פרופיל manual ← אין טיוטה אחרי ביקור; "יצירת טיוטה" יוצרת אחת (ולא שתיים); תיק שנפתח לפני שינוי הפרופיל ממשיך אוטומטי, ותיק חדש — ידני');

// ═══ 17-A5, 17-A7, 17-D1, 17-D2: הבינה ═══════════════════════════
const K_OFFICE = 'sk-test-REPORT-key-0000-1111-aaaa-Zq7M';
$waits = fn(string $case) => array_column(apr_rows('SELECT s.key, s.ai_wait FROM report_sections s JOIN report_versions v ON v.id = s.version_id WHERE v.case_id = ? AND s.ai_allowed = 1 ORDER BY s.sort', [$caseId($case)]), 'ai_wait', 'key');
$origins = fn(string $case) => array_column(apr_rows('SELECT s.key, s.origin FROM report_sections s JOIN report_versions v ON v.id = s.version_id WHERE v.case_id = ? ORDER BY s.sort', [$caseId($case)]), 'origin', 'key');
$autoCase = function () use ($avi, $P, $visit) { $c = make_case($avi, $P); $visit($c); return $c; };
$w = [];
$w['policy'] = $waits($C);                                           // המשרד עוד לא הדליק בינה
$post('office-ai-connection-save', ['provider' => 'anthropic', 'label' => 'המשרד', 'key' => K_OFFICE, 'confirm' => 'אישור']);
$post('office-ai-policy', ['enabled' => true, 'text_version' => 1, 'providers' => ['anthropic' => 1], 'confirm' => 'אישור']);
$CX = make_case($avi, $P);
$post('case-ai-set', ['case' => $CX, 'no_ai' => true]);
$visit($CX);
apr_jobs_run(5.0);
$w['case_no_ai'] = $waits($CX);
$officePid = apr_val('SELECT o.pid FROM offices o JOIN cases c ON c.office_id = o.id WHERE c.pid = ?', [$C]);
$q = $post('office-ai-quota-save', ['track' => 'personal', 'scope' => 'office', 'target' => $officePid, 'period' => 'month', 'limit_tokens' => 10, 'hard' => true, 'confirm' => 'אישור']);
$CQ = $autoCase();
apr_jobs_run(5.0);
$w['quota'] = $waits($CQ);
$post('office-ai-quota-delete', ['quota' => $q[1]['quota']['pid'] ?? $q[1]['quota'] ?? '', 'confirm' => 'אישור']);
apr_exec('DELETE FROM ai_quotas');
$conn = apr_val('SELECT pid FROM ai_connections ORDER BY id DESC LIMIT 1');
$post('office-ai-connection-revoke', ['connection' => $conn, 'confirm' => 'אישור']);
$CR = $autoCase();
apr_jobs_run(5.0);
$w['no_connection'] = $waits($CR);
$all4 = fn(string $why) => ['property' => $why, 'area_desc' => $why, 'visit' => $why, 'planning' => $why];
eq([$q[0], $w, count($fake->calls), array_map(fn($c) => count($versions($c)), [$CX, $CQ, $CR]), array_values(array_unique(array_values($origins($CQ)))), (int)apr_val("SELECT COUNT(*) FROM ai_runs WHERE purpose = 'report.draft' AND status <> 'blocked'"),
    $sec($ver($versions($CQ)[0]['pid'])[1], 'visit')['ai_wait'], $types($sec($ver($versions($CQ)[0]['pid'])[1], 'visit')['blocks'])],
    [200, ['policy' => $all4('policy'), 'case_no_ai' => $all4('case_no_ai'), 'quota' => $all4('quota'), 'no_connection' => $all4('no_connection')], 0, [1, 1, 1], ['auto', 'manual'], 0, 'quota', ['p']],
    '17-A5 בינה לא זמינה — בינה כבויה למשרד, תיק "בלי בינה", מכסה, אין חיבור: בכל אחת הטיוטה נוצרת מהנתונים, הפרקים מסומנים "ממתין" עם הסיבה, ו**אפס** קריאות למתאם');

// בינה זמינה: הפרקים שמותר לנסח מנוסחים, מסומנים, ולא נסקרו. רק נתוני הפרק נשלחים, ושום פרק אסור אינו נשלח
$post('office-ai-connection-save', ['provider' => 'anthropic', 'label' => 'המשרד 2', 'key' => K_OFFICE, 'confirm' => 'אישור']);
profile_report($avi, $P, ['trigger' => 'visit_completed', 'sections' => array_map(fn($s) => ['ai' => true] + $s, $d['sections'])]);     // הפרופיל מבקש בינה לכל הפרקים
$CAI = make_case($avi, $P);
$FA = $fact($CAI, 'area', '85.5', ['unit' => 'sqm', 'area_kind' => 'registered'])[1]['fact']['pid'];
$fact($CAI, 'rooms', '4');
$visit($CAI);
apr_jobs_run(5.0);
$VAI = $versions($CAI)[0]['pid'];
$va = $ver($VAI)[1];
$sent = array_map(fn($r) => json_encode($r, JSON_UNESCAPED_UNICODE), $fake->calls);
$titles = array_column($d['sections'], 'title', 'key');
$perCall = array_map(fn($t) => array_keys(array_filter($titles, fn($title) => str_contains($t, 'כותרת הפרק: ' . $title))), $sent);
$areaCall = array_values(array_filter($sent, fn($t) => str_contains($t, $titles['area_desc'])))[0] ?? '';
$aiSec = $sec($va, 'area_desc');
eq([$origins($CAI), $perCall, array_filter($sent, fn($t) => str_contains($t, $titles['methods']) || str_contains($t, $titles['comps']) || str_contains($t, $titles['assume']) || str_contains($t, $titles['conclusion']) || str_contains($t, $titles['purpose']) || str_contains($t, $titles['documents']) || str_contains($t, 'משה לקוח')),
    [str_contains($areaCall, '85.5'), str_contains($areaCall, 'חדרים: 4'), str_contains($areaCall, '6158'), str_contains($areaCall, 'tools')], [$aiSec['origin'], $aiSec['ai_reviewed'], $aiSec['ai_wait'], $aiSec['user_edited']],
    array_unique(array_map(fn($b) => $b['ai'] ?? false, array_filter($aiSec['blocks'], fn($b) => $b['type'] === 'p'))), $aiSec['blocks'][0]['src'] ?? null,
    in_array('ai_unreviewed', $blocking($VAI), true), (int)apr_val("SELECT COUNT(*) FROM ai_runs WHERE purpose = 'report.draft' AND status = 'done'"), array_column(array_filter($va['sections'], fn($s) => $s['ai_allowed']), 'key')],
    [['purpose' => 'auto', 'property' => 'ai', 'area_desc' => 'ai', 'visit' => 'ai', 'planning' => 'ai', 'documents' => 'auto', 'methods' => 'auto', 'comps' => 'auto', 'assume' => 'auto', 'conclusion' => 'manual', 'appendix' => 'auto'],
        [['property'], ['area_desc'], ['visit'], ['planning']], [], [true, true, false, false], ['ai', false, null, false], [true], ['type' => 'fact', 'pid' => $FA], true, 4, ['property', 'area_desc', 'visit', 'planning']],
    '17-A7 רק ארבעת הפרקים שברשימה הסגורה נשלחים למתאם, גם כשהפרופיל מבקש בינה לכולם — לא methods / comps / assume / conclusion, ולא purpose / documents (שם המזמין אינו נשלח כלל); 17-D2 כל בקשה נושאת את הנתונים של פרק אחד בלבד (בלי מזהי הנכס בפרק התיאור, שם המזמין רק בפרק שמציג אותו, בלי tools); פרק בלי נתונים אינו נשלח; הטקסט מסומן כבינה, לא נסקר, ומקושר לנתון');

// הכרעת הבקרה (סטייה 7): פרט מזהה של גורם בתיק אינו נשלח למודל — גם כשהוא מופיע בתוך נתון של פרק מותר; שם המבקר נמחק מהשורה של הביקור
$CP = make_case($avi, $PM);
$fact($CP, 'actual_use', 'מושכר למשה לקוח עד סוף השנה');
$fact($CP, 'area', '90', ['unit' => 'sqm', 'area_kind' => 'registered']);
$visit($CP);
$cpRow = apr_row('SELECT * FROM cases WHERE pid = ?', [$CP]);
$pii = apr_report_ai_pii($cpRow);
$inA = apr_report_ai_input(['user' => null, 'office' => null], $cpRow, ['key' => 'area_desc', 'title' => 'סביבת הנכס ותיאורו'], apr_pc_report_default());
$inV = apr_report_ai_input(['user' => null, 'office' => null], $cpRow, ['key' => 'visit', 'title' => 'הביקור'], apr_pc_report_default());
$visitor = apr_val('SELECT u.name FROM visits v JOIN users u ON u.id = v.visitor_user_id WHERE v.case_id = ?', [$cpRow['id']]);
// תבנית ישנה (או שורה שנכתבה ישירות) שמסמנת ai לפרק שאינו ברשימה הסגורה: גם אז אינו נשלח — לא ברקע ולא ב"ניסוח עכשיו"
$forceAi = function (string $key) use ($caseId, $CAI) {
    $s = apr_row("SELECT s.* FROM report_sections s JOIN report_versions v ON v.id = s.version_id WHERE v.case_id = ? AND s.key = ?", [$caseId($CAI), $key]);
    try { apr_exec('UPDATE report_sections SET ai_allowed = 1, rev = rev + 1 WHERE id = ?', [$s['id']]); $refused = false; } catch (PDOException) { $refused = true; }
    return ['ai_allowed' => 1, 'refused' => $refused] + $s;       // המסד מסרב (CHECK); השורה שבזיכרון מדמה תבנית ישנה, לבדיקת ההגנה שבקוד
};
$sPurpose = $forceAi('purpose');
$sMethods = $forceAi('methods');
$sDocs = $forceAi('documents');
$callsBefore = count($fake->calls);
$caiRow = apr_row('SELECT * FROM cases WHERE pid = ?', [$CAI]) + ['perms' => [], 'my_role' => 'system', 'share' => null];
$direct = array_map(fn($s) => array_intersect_key(apr_report_ai_section(null, $caiRow, $s, apr_pc_report_default(), 'rd:test:' . $s['key']), ['state' => 1, 'reason' => 1]), [$sPurpose, $sMethods, $sDocs]);
$nowAi = array_map(fn($s) => code($post('report-section-ai', ['section' => $s['pid'], 'rev' => (int)$s['rev']])), [$sPurpose, $sMethods]);
eq([in_array('משה לקוח', $pii['parties'], true), in_array('moshe@client.example', $pii['parties'], true), str_contains($inA['message'], 'משה'), str_contains($inA['message'], '90'), $inA['omitted'],
    is_string($visitor) && $visitor !== '', str_contains($inV['message'], (string)$visitor), str_contains($inV['message'], 'בידי'), str_contains($inV['message'], 'ביקור בנכס נערך ביום'),
    apr_report_ai_scrub('טלפון לתיאום: 050-1234567', $pii), apr_report_ai_scrub('נמסר בטלפון +972 3 555 1234', $pii), apr_report_ai_scrub('ת"ז 123456782', $pii), apr_report_ai_scrub('דוא"ל a.b@c.co.il', $pii),
    apr_report_ai_scrub('נמסר על ידי MOSHE@client.example', $pii), apr_report_ai_scrub('שטח רשום: 85.5 מ"ר, גוש 6158', $pii), apr_report_ai_scrub('מחיר: 1,500,000', $pii),
    $direct, $nowAi, count($fake->calls) - $callsBefore, [$sPurpose['refused'], $sMethods['refused'], $sDocs['refused']]],
    [true, true, false, true, 1, true, false, false, true, null, null, null, null, null, 'שטח רשום: 85.5 מ"ר, גוש 6158', 'מחיר: 1,500,000',
        array_fill(0, 3, ['state' => 'failed', 'reason' => 'not_allowed']), ['report_no_ai', 'report_no_ai'], 0, [true, true, true]],
    'סטייה 7: שם / מייל / טלפון / ת"ז של גורם אינם נשלחים למודל — הפריט שמכיל אותם מושמט מהקלט; שם המבקר נמחק מהשורה; נתון רגיל נשאר; פרק שאינו ברשימה הסגורה (purpose, methods, documents): המסד מסרב לסמן לו ai (CHECK), הקוד אינו שולח אותו גם אם סומן, ו"ניסוח עכשיו" נדחה');

// B1 — רשת הביטחון: מה שעדיין מקליד אדם ונשלח הוא **כתובת מובנית** (רחוב / יישוב). כל אחת מהצורות האלה, בתוך שדה הרחוב של מזהה,
// **אינה מגיעה ל-payload שהמתאם מקבל** — והמזהים התמימים שלידן כן. (נתון מסוג טקסט וכל עמודת טקסט חופשי אינם נשלחים כלל — הבדיקה שבסוף הקובץ.)
call('POST', 'case-party-add', ['case' => $CP, 'name' => 'ישראל ישראלי', 'org' => 'בנק הדוגמה', 'phone' => '054-7654321', 'email' => 'isr@bank.example', 'kind' => 'bank'], $avi);
$cpRow = apr_row('SELECT * FROM cases WHERE pid = ?', [$CP]) + ['perms' => [], 'my_role' => 'system', 'share' => null];
$cpSec = ['key' => 'area_desc', 'title' => 'סביבת הנכס ותיאורו', 'ai_allowed' => 1, 'pid' => 'x', 'rev' => 1];
$cpProp = ['key' => 'property', 'title' => 'זיהוי הנכס והזכויות', 'ai_allowed' => 1, 'pid' => 'x', 'rev' => 1];
$samples = ['ת"ז השוכר 123456789.' => '123456789', 'ת"ז 123456789, נמסרה' => '123456789', 'ת.ז 01234567-8 של השוכר' => '01234567', 'מספר זהות 12345678 בלבד' => '12345678', 'ת.ז 1234567-8 בספח' => '1234567',
    'ת"ז 123 456 789 של הדייר' => '456 789', 'טלפון 054.765.4321 לתיאום' => '4321', 'טלפון +972 54 765 4321' => '4321', 'טלפון +972-(0)54-7654321' => '7654321', 'נייד 052.111.2233' => '2233', 'טל׳ 03-5551234' => '5551234',
    'נמסר בידי מר ישראלי' => 'ישראלי', 'לדברי ישראל  ישראלי (רווח כפול)' => 'ישראל', 'לדברי ישראל־ישראלי' => 'ישראל', 'לדברי יִשְׂרָאֵל' => 'לדברי', 'נמסר לישראלי בכתב' => 'לישראלי',
    'מכתב מבנק הדוגמה' => 'הדוגמה', 'אישור של הבנק: הדוגמה' => 'הדוגמה', 'isr [at] bank.example' => 'bank', 'isr(at)bank.example' => 'bank', 'ISR@BANK.example' => 'BANK'];
$notSaved = [];
foreach (array_keys($samples) as $text) if (call('POST', 'case-identifier-add', ['case' => $CP, 'kind' => 'address', 'street' => $text, 'locality' => 'חיפה'], $avi)[0] !== 200) $notSaved[] = $text;
call('POST', 'case-identifier-add', ['case' => $CP, 'kind' => 'address', 'street' => 'הרצל', 'house_no' => '5', 'locality' => 'תל אביב'], $avi);
$fake->calls = [];
apr_report_ai_section(null, $cpRow, $cpProp, apr_pc_report_default(), 'rd:b1-test:address');
$sent = json_encode($fake->calls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$leaks = array_values(array_unique(array_filter($samples, fn($needle) => str_contains($sent, $needle))));
$viewProp = json_encode(apr_report_resolve(['user' => null, 'office' => null], $cpRow, apr_report_build($cpRow, 'property', apr_pc_report_default())['blocks'], apr_pc_report_default()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
// 🟡4: שם של חבר צוות ("אבי") נמחק רק כמילה שלמה; 🟡7: ארגון וטלפון שמור של גורם
$staff = ['parties' => ['ישראל ישראלי', 'בנק הדוגמה', '054-7654321', '+1 212 555 0199'], 'staff' => ['אבי', 'טל', 'דנה לוי']];
eq([$notSaved, count($fake->calls), $leaks, str_contains($sent, 'חיפה'), str_contains($sent, 'הרצל 5, תל אביב'), str_contains($sent, 'גוש 6158, חלקה 12'), str_contains($viewProp, '123456789') && str_contains($viewProp, 'ישראלי'),
    apr_report_ai_scrub('הנכס בתל אביב, ליד רחוב אבידן', $staff), apr_report_ai_scrub('טל׳ המשרד נמסר, והטל ירד', $staff), apr_report_ai_scrub('הביקור נערך בידי אבי.', $staff), apr_report_ai_scrub('נבדק על ידי דנה־לוי ואבי', $staff),
    apr_report_ai_scrub('אישור מבנק אחר', $staff), apr_report_ai_scrub('חוות דעת של הדוגמה בע"מ', $staff), apr_report_ai_scrub('לתיאום: 0547654321', $staff), apr_report_ai_scrub('לתיאום: 972547654321+', $staff), apr_report_ai_scrub('משרד בחו"ל: 1-212-555-0199', $staff),
    apr_report_ai_scrub('ת"ז 123 456 789', $staff), apr_report_ai_scrub('שטח 85.5 מ"ר, קומה 3 מתוך 12, נבנה בשנת 1998', $staff), apr_report_ai_scrub('מחיר: 2,100,000 ₪ ביום 2026-09-01', $staff), apr_report_ai_scrub('גוש 6158, חלקה 12, תת-חלקה 3', $staff)],
    [[], 1, [], false, true, true, true, 'הנכס בתל אביב, ליד רחוב אבידן', 'טל׳ המשרד נמסר, והטל ירד', 'הביקור נערך.', 'נבדק על ידי', null, null, null, null, null,
        null, 'שטח 85.5 מ"ר, קומה 3 מתוך 12, נבנה בשנת 1998', 'מחיר: 2,100,000 ₪ ביום 2026-09-01', 'גוש 6158, חלקה 12, תת-חלקה 3'],
    'B1 (רשת הביטחון): ת"ז (9 ספרות לפני נקודה / פסיק, 8 ספרות, עם מקף, ברווחים), טלפון (נקודות, ‎+972, סוגריים), חלק משם של גורם / רווח כפול / מקף / ניקוד / אות שימוש, ארגון, ומייל ב-[at] — בתוך כתובת מובנית, אינם ב-payload של המתאם (השורה כולה מושמטת); כתובת וגוש-חלקה תמימים נשלחים; בדוח עצמו הכול נשאר. 🟡4: שם של חבר צוות נמחק כמילה שלמה בלבד ("תל אביב", "טל׳" נשארים)');

// B1, העיקרון (סבב 3): טקסט חופשי שאדם הקליד אינו נשלח כלל, בשום קבוצה — הנתון נשאר בפרק כהפניה; טקסט חופשי של מזהה "אחר" אינו נשלח, ונשאר בדוח עצמו
$fact($CP, 'rent_term', 'עד סוף 2027, השוכר הוא פלוני אלמוני');
call('POST', 'case-identifier-add', ['case' => $CP, 'kind' => 'other', 'value_text' => 'הדירה של משפחת פלמוני'], $avi);
$inA2 = apr_report_ai_input(['user' => null, 'office' => null], $cpRow, $cpSec, apr_pc_report_default());
$inP2 = apr_report_ai_input(['user' => null, 'office' => null], $cpRow, ['key' => 'property', 'title' => 'זיהוי הנכס והזכויות'], apr_pc_report_default());
$builtP = json_encode(apr_report_resolve(['user' => null, 'office' => null], $cpRow, apr_report_build($cpRow, 'property', apr_pc_report_default())['blocks'], apr_pc_report_default()), JSON_UNESCAPED_UNICODE);
eq([str_contains($inA2['message'], 'פלוני'), str_contains($inA2['message'], 'תקופת השכירות'), in_array(['type' => 'fact', 'attr' => 'rent_term'], $inA2['kept'], true), in_array(['type' => 'fact', 'attr' => 'actual_use'], $inA2['kept'], true),
    str_contains($inA2['message'], 'שטח: 90'), array_filter(array_map(fn($k) => apr_report_ai_input(['user' => null, 'office' => null], $cpRow, ['key' => $k, 'title' => 'x'], apr_pc_report_default())['items'], ['purpose', 'documents', 'methods', 'comps', 'assume', 'conclusion', 'appendix', 'custom_x'])),
    str_contains($inP2['message'], 'פלמוני'), str_contains($inP2['message'], '6158'), str_contains($builtP, 'פלמוני')],
    [false, false, true, true, true, [], false, true, true],
    'B1: נתון מסוג טקסט אינו נשלח באף קבוצה (שכירות, תיאור הנכס) ונשאר בפרק כהפניה; נתון מספרי נשלח; פרק שאינו ברשימה הסגורה אינו מרכיב קלט; טקסט חופשי של מזהה "אחר" אינו נשלח — אבל נשאר בדוח עצמו; גוש וחלקה נשלחים');

// 17-D1: src שלא נשלח ← הבלוק נזרק; מספר שאינו בנתונים ← ממצא חוסם. מקרה קצה 10: טקסט עם שווי
$fake->next = fn() => reply(['blocks' => [['text' => 'שטח הדירה 85.5 מ"ר', 'src' => 'd1'], ['text' => 'פסקה עם מקור שלא נשלח', 'src' => 'd99'], ['text' => 'בלי מקור בכלל'],
    ['text' => 'שווי הנכס מוערך ב-2,150,000 ₪, ובדירה 4 חדרים', 'src' => 'd2'], ['text' => 'בשנת 2019 שופצה הדירה', 'src' => 'd1']]]);
$sA = $sec($ver($VAI)[1], 'area_desc');
$again = $post('report-section-ai', ['section' => $sA['pid'], 'rev' => $sA['rev']]);
$sA2 = $sec($ver($VAI)[1], 'area_desc');
$ps = array_values(array_filter($sA2['blocks'], fn($b) => $b['type'] === 'p'));
$numFind = array_values(array_filter($ver($VAI)[1]['findings'], fn($f) => $f['kind'] === 'ai_number'));
$noAi = $sec($ver($VAI)[1], 'comps');
eq([$again[0], $again[1]['state'] ?? null, array_column($ps, 'text'), array_map(fn($b) => $b['num_unknown'] ?? null, $ps), count($numFind), array_unique(array_column($numFind, 'level')), $numFind[0]['section'] ?? null,
    code($post('report-section-ai', ['section' => $noAi['pid'], 'rev' => $noAi['rev']])), code($post('report-section-ai', ['section' => $sA2['pid'], 'rev' => $sA2['rev']], $noa)), code($post('report-section-ai', ['section' => $sA2['pid'], 'rev' => 1])),
    apr_report_numbers('ביום 2026-09-01 נמכרו 1,250,000.50 ₪ ו-04 חדרים, 3.0 קומות'), apr_report_ai_parse('שלום', ['d1' => ['text' => 'x', 'src' => null]]), apr_report_ai_parse('{"blocks":"x"}', [])],
    [200, 'done', ['שטח הדירה 85.5 מ"ר', 'שווי הנכס מוערך ב-2,150,000 ₪, ובדירה 4 חדרים', 'בשנת 2019 שופצה הדירה'], [null, ['2150000'], ['2019']], 2, ['block'], $sA['pid'], 'report_no_ai', 'not_found', 'report_changed',
        ['2026', '9', '1', '1250000.5', '4', '3'], null, null],
    '17-D1 תשובת בינה: בלוק עם src שלא נשלח (או בלי src) נזרק; מספר שאינו בנתונים שנשלחו ← הבלוק מסומן, וממצא חוסם בבקרה (גם "שווי" — מקרה קצה 10); פרק שאינו מותר לבינה ← report_no_ai; מי שאינו בתיק — 404; rev ישן ← 409');
// num_unknown נשאר על פסקה שהטקסט שלה לא השתנה, גם בשמירה חוזרת של הפרק בידי אדם — אי אפשר "לנקות" את הממצא בלי לשנות את הטקסט
$sA3 = $sec($ver($VAI)[1], 'area_desc');
$resave = $save($sA3['pid'], [...array_map(fn($b) => array_intersect_key($b, ['type' => 1, 'text' => 1, 'ai' => 1, 'src' => 1]), array_values(array_filter($sA3['blocks'], fn($b) => $b['type'] === 'p'))), ['type' => 'p', 'text' => 'תוספת של השמאי']]);
$psAfter = array_values(array_filter($sec($ver($VAI)[1], 'area_desc')['blocks'], fn($b) => $b['type'] === 'p'));
eq([$resave[0], array_map(fn($b) => $b['num_unknown'] ?? null, $psAfter), array_map(fn($b) => $b['ai'] ?? false, $psAfter), in_array('ai_number', $blocking($VAI), true)],
    [200, [null, ['2150000'], ['2019'], null], [true, true, true, false], true],
    '17-D1 שמירה חוזרת של פרק בינה בלי לשנות את הטקסט: "מספר שלא נמצא בנתונים" וסימון הבינה נשארים, והממצא החוסם נשאר');
$fake->next = $echo;

// 17-B1 לטקסט בינה: פרק שנוסח בבינה (ולא נערך) + נתון חדש ← התוכן אינו נבנה מחדש מעל הטקסט; נוצרת הצעת עדכון. ועריכה של טקסט הבינה מורידה את הסימון
$CB = make_case($avi, $P);
$fact($CB, 'area', '60', ['unit' => 'sqm', 'area_kind' => 'registered']);
$visit($CB);
apr_jobs_run(5.0);
$VB = $versions($CB)[0]['pid'];
$b0 = $sec($ver($VB)[1], 'area_desc');
$raw0 = $srow($b0['pid'])['content'];
$fact($CB, 'rooms', '3');
$b1 = $sec($ver($VB)[1], 'area_desc');
$same = $srow($b0['pid'])['content'] === $raw0;
$rew = $save($b0['pid'], [['type' => 'p', 'text' => 'טקסט שהשמאי כתב מחדש', 'ai' => true]]);
$b2 = $srow($b0['pid']);
eq([$b0['origin'], $same, $b1['origin'], $b1['proposal'] !== null, $rew[0], $b2['origin'], array_column(json_decode($b2['content'], true), 'ai')],
    ['ai', true, 'ai', true, 200, 'manual', []],
    '17-B1 פרק שנוסח בבינה + נתון חדש ← התוכן זהה בית-בבית, ונוצרה הצעת עדכון (הבינה אינה מופעלת שוב לבד); טקסט ששוכתב אינו נושא עוד סימון בינה, גם אם הלקוח שלח ai');

// ═══ 17-B: עריכה, ואיסור הדריסה השקטה ═══════════════════════════
// 17-B2: פרק שלא נגעו בו + נתון חדש ← מתעדכן לבד, ואין הצעה
$props = fn(string $section) => apr_rows('SELECT p.* FROM section_proposals p JOIN report_sections s ON s.id = p.section_id WHERE s.pid = ? ORDER BY p.id', [$section]);
$S = fn(string $key) => $sec($ver($VER)[1], $key);
$before = $S('area_desc');
$F1 = $fact($C, 'area', '85.5', ['unit' => 'sqm', 'area_kind' => 'registered'])[1]['fact']['pid'];
$after = $S('area_desc');
eq([$types($before['blocks']), $types($after['blocks']), $after['blocks'][1]['value']['text'] ?? null, $after['blocks'][1]['value']['area_kind'] ?? null, $after['blocks'][1]['verified'] ?? null, $after['user_edited'], $after['changed_note'] !== null,
    $props($after['pid']), $after['rev'] > $before['rev'], array_column($after['sources'], 'pid'), in_array('fact_unverified', array_column($ver($VER)[1]['findings'], 'kind'), true)],
    [['note_missing'], ['h', 'fact'], '85.5', 'registered', false, false, true, [], true, [$F1], true],
    '17-B2 פרק עם user_edited = 0 + נתון חדש ← הפרק נבנה מחדש עם הנתון (מסומן "לא אומת"), מציג "עודכן", ואין הצעת עדכון');

// 17-B1: פרק שנערך בידי אדם + נתון חדש ← התוכן זהה בית-בבית, ונוצרת הצעה
$mine = [['type' => 'p', 'text' => 'הדירה מרווחת ומוארת, <b>לדברי</b> השמאי'], ['type' => 'fact', 'attr' => 'area'], ['type' => 'list', 'items' => ['סלון', 'מטבח']], ['type' => 'table', 'rows' => [['חדר', 'מצב'], ['סלון', 'טוב']]],
    ['type' => 'note_missing', 'what' => 'מצב האינסטלציה'], ['type' => 'h', 'text' => 'כותרת משנה'], ['type' => 'data_table', 'table' => 'identifiers']];
$stale = $save($after['pid'], $mine, null, $before['rev']);
$sv = $save($after['pid'], $mine, $tal);                              // עובדת משרד עם edit — רשאית לערוך
check('17-B4 שמירת פרק עם rev ישן ← 409 report_changed', code($stale) === 'report_changed', code($stale));
$stored = $srow($after['pid'])['content'];
$fact($C, 'rooms', '4');
$ed = $S('area_desc');
$pr = $props($after['pid']);
eq([code($stale), array_key_exists('by', $stale[1]['error'] ?? []) ? $stale[1]['error']['by'] : 'x', $sv[0], [(int)$srow($after['pid'])['user_edited'], $srow($after['pid'])['origin'], $srow($after['pid'])['state']], $srow($after['pid'])['content'] === $stored,
    count($pr), [$pr[0]['state'] ?? null, $pr[0]['origin'] ?? null], $ed['proposal'] !== null, array_values(array_filter($ed['proposal']['lines'] ?? [], fn($l) => str_contains($l, 'חדרים'))), $ed['blocks'][0]['text'], $types($ed['blocks']),
    $ed['blocks'][6]['rows'] ?? null, in_array('proposal_pending', $blocking($VER), true), count($props($after['pid'])) === count($pr) && $ver($VER)[0] === 200 && count($props($after['pid'])) === 1],
    ['report_changed', null, 200, [1, 'manual', 'edited'], true, 1, ['pending', 'auto'], true, ['חדרים: 4'], 'הדירה מרווחת ומוארת, <b>לדברי</b> השמאי', ['p', 'fact', 'list', 'table', 'note_missing', 'h', 'data_table'],
        [['גוש / חלקה', 'גוש 6158, חלקה 12']], true, true],
    '17-B4 שמירה עם rev ישן ← 409; 17-B1 פרק שנערך בידי אדם + נתון חדש ← content זהה בית-בבית, נוצרה section_proposals אחת (גם אחרי קריאות חוזרות), והיא חוסמת אישור; טקסט נשמר כטקסט — תגית אינה HTML; טבלה מהתיק נפתרת בזמן ההצגה');

// "הערך השתנה: היה X" — הפניה לנתון בפרק שנערך ממשיכה להציג ערך עדכני, ומסומנת
$post('fact-correct', ['fact' => $F1, 'rev' => $frev($F1), 'value' => '90', 'correction_note' => 'מדידה חוזרת']);
$chg = $S('area_desc');
$chgFind = array_values(array_filter($ver($VER)[1]['findings'], fn($f) => $f['kind'] === 'fact_changed'));
eq([$chg['blocks'][1]['value']['text'], $chg['blocks'][1]['changed'], $chg['blocks'][1]['was'], count($chgFind), $chgFind[0]['level'] ?? null, $srow($after['pid'])['content'] === $stored], ['90', true, '85.5 מ"ר (רשום)', 1, 'warn', true],
    '§7 בלוק fact בפרק שנערך מציג את הערך העדכני, מסומן "הערך השתנה: היה X", ועולה כאזהרה בבקרה — והתוכן השמור אינו משתנה');

// הכרעה בהצעה: דחייה, קבלה ומיזוג — נרשמות; התוכן משתנה רק בקבלה / במיזוג
$decide = fn(string $prop, string $decision, array $more = [], string $as = null) => $post('report-proposal-decide', ['proposal' => $prop, 'decision' => $decision] + $more, $as);
$rj = $decide($pr[0]['pid'], 'reject');
$afterRj = [$srow($after['pid'])['content'] === $stored, $props($after['pid'])[0]['state'], $S('area_desc')['proposal'], code($decide($pr[0]['pid'], 'accept'))];
$fact($C, 'floor', '3');
$ver($VER);                                                         // הרענון קורה בקריאה של הגרסה (או במשימת הרקע)
$p2 = $props($after['pid'])[1] ?? null;
$mg = $decide($p2['pid'] ?? '', 'merge', ['content' => [['type' => 'p', 'text' => 'נוסח ממוזג'], ['type' => 'fact', 'attr' => 'floor']]]);
$afterMg = [$S('area_desc')['blocks'][0]['text'], (int)$srow($after['pid'])['user_edited'], $props($after['pid'])[1]['state']];
$fact($C, 'year_built', '1995');
$ver($VER);
$p3 = $props($after['pid'])[2] ?? null;
$ac = $decide($p3['pid'] ?? '', 'accept', [], $tal);
$afterAc = [$types($S('area_desc')['blocks']), (int)$srow($after['pid'])['user_edited'], $srow($after['pid'])['origin'], $props($after['pid'])[2]['state'], (int)$props($after['pid'])[2]['decided_by'] > 0];
eq([$rj[0], $afterRj, $mg[0], $afterMg, $ac[0], $afterAc, code($decide($p3['pid'] ?? '', 'reject')), code($decide($pr[0]['pid'], 'accept', [], $noa)), code($decide('aaaaaaaaaaaaaaaa', 'accept')),
    (int)apr_val("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'report.proposal_%'")],
    [200, [true, 'rejected', null, 'nothing_to_change'], 200, ['נוסח ממוזג', 1, 'merged'], 200, [['h', 'fact', 'fact', 'fact', 'fact'], 0, 'auto', 'accepted', true], 'nothing_to_change', 'forbidden', 'not_found', 3],
    '17-E2 (חלק השרת) הצעת עדכון: דחייה משאירה את התוכן; מיזוג שומר את הנוסח של השמאי; קבלה מחליפה בתוכן המוצע; הצעה שהוכרעה אינה מוכרעת שוב; כל הכרעה ביומן');

// 17-B3: "יצירה מחדש" בברירת המחדל אינה נוגעת בפרקים שנערכו ידנית
$pu = $S('purpose');
$save($pu['pid'], [['type' => 'p', 'text' => 'מטרת השומה בניסוח של השמאי']]);
$keep = $srow($pu['pid'])['content'];
$rb = $post('report-rebuild', ['version' => $VER]);
$kept = $srow($pu['pid'])['content'] === $keep;
$rb2 = $post('report-rebuild', ['version' => $VER, 'sections' => [$pu['pid']], 'include_edited' => true]);
eq([$rb[0], in_array($pu['pid'], array_column($rb[1]['skipped'], 'pid'), true), $kept, in_array($pu['pid'], $rb[1]['rebuilt'], true), $rb2[1]['rebuilt'], $types($S('purpose')['blocks']), (int)$srow($pu['pid'])['user_edited'], code($post('report-rebuild', ['version' => $VER], $noa))],
    [200, true, true, false, [$pu['pid']], ['p', 'p', 'p'], 0, 'forbidden'],
    '17-B3 "יצירה מחדש" בברירת המחדל מדלגת על פרק שנערך ידנית (ומחזירה אותו ברשימה, לאזהרה); רק include_edited מפורש בונה אותו מחדש');

// תוכן שאינו תקין נדחה, ושום דבר לא נשמר
$CB = make_case($boaz, $PB);
$post('report-draft-create', ['case' => $CB], $boaz);
$VB = $versions($CB)[0]['pid'];
$bSection = $sec($ver($VB, $boaz)[1], 'purpose');
$bVisitFact = $fact($CB, 'area', '50', ['unit' => 'sqm', 'area_kind' => 'registered'], $boaz)[1]['fact']['pid'];
$pc = $S('documents');
$badBlocks = [[['type' => 'script', 'text' => 'x']], [['type' => 'p']], [['type' => 'p', 'text' => str_repeat('א', 5001)]], [['type' => 'fact', 'attr' => 'no_such_attr']], [['type' => 'data_table', 'table' => 'users']],
    [['type' => 'p', 'text' => 'x', 'src' => ['type' => 'fact', 'pid' => $bVisitFact]]], [['type' => 'image', 'photo' => 'aaaaaaaaaaaaaaaa']], [['type' => 'table', 'rows' => [['a', 'b'], ['c']]]], 'x', array_fill(0, 201, ['type' => 'p', 'text' => 'x'])];
$codes = array_map(fn($b) => code($save($pc['pid'], $b)), $badBlocks);
eq([array_values(array_unique($codes)), $srow($pc['pid'])['rev'] == $pc['rev'], code($save($pc['pid'], [['type' => 'p', 'text' => 'ok', 'src' => ['type' => 'fact', 'pid' => $F1]]])), $S('documents')['blocks'][0]['src'] ?? null],
    [['report_block', 'text_too_long', 'attr_invalid'], true, null, ['type' => 'fact', 'pid' => $F1]],
    '§5 בלוק לא מוכר, טקסט חסר או ארוך מדי, מאפיין שאינו קיים, טבלה שאינה ברשימה, מקור או תמונה של תיק אחר, טבלה עם שורות לא שוות — נדחים, ודבר לא נשמר; מקור של התיק עצמו נשמר');

// ═══ הרשאות (§11) ═══════════════════════════════════════════════
$s1 = $S('assume');
$roleTry = fn(string $as) => [code($save($s1['pid'], $P_TEXT, $as, 999999)), code($post('report-section-reviewed', ['section' => $s1['pid'], 'rev' => 999999], $as)), code($post('report-submit', ['version' => $VER, 'rev' => 999999], $as)),
    code($post('report-return', ['version' => $VER, 'rev' => 999999], $as)), code($post('report-approve', ['version' => $VER, 'rev' => 999999, 'acks' => [], 'confirm' => 'אישור'], $as)),
    code($post('report-version-new', ['case' => $C, 'reason' => 'x'], $as)), code($post('report-version-delete', ['version' => $VER, 'rev' => 999999, 'reason' => 'x'], $as)),
    code($post('report-comment-add', ['version' => $VER, 'text' => 'x' . bin2hex(random_bytes(2))], $as)), code($post('report-section-add', ['version' => $VER], $as)), $ver($VER, $as)[0], $report($C, $as)[0]];
eq([$roleTry($noa), $roleTry($tal), $roleTry($dana), $roleTry($boaz), $roleTry($owner)],
    [['forbidden', 'forbidden', 'forbidden', 'forbidden', 'forbidden', 'forbidden', 'forbidden', 'forbidden', 'forbidden', 200, 200],
        ['report_changed', 'forbidden', 'forbidden', 'forbidden', 'forbidden', 'forbidden', 'forbidden', null, 'report_block', 200, 200],
        ['report_changed', 'nothing_to_change', 'report_changed', 'report_state', 'forbidden', 'forbidden', 'forbidden', null, 'report_block', 200, 200],
        array_merge(array_fill(0, 9, 'not_found'), [404, 404]), array_merge(array_fill(0, 9, 'no_office'), [404, 404])],
    '§11 צופה — קריאה בלבד; עובדת משרד עם edit — עריכה והערות, לא "נסקר", לא העברה לבדיקה ולא אישור; שמאית שותפה — גם העברה לבדיקה, **לא** אישור, גרסה חדשה או מחיקה (17-C1); משרד אחר — 404 בכל פעולה (17-D3), ומנהל המערכת — נדחה בכל פעולה (אינו חבר משרד)');

// ═══ 17-C: בקרה, אישור וגרסאות ═══════════════════════════════════
$errKinds = fn(array $r) => array_values(array_unique(array_column($r[1]['error']['findings'] ?? [], 'kind')));
/** תיק מוכן: טיוטה ידנית, כל הפרקים מלאים, בבדיקה. מחזיר [תיק, גרסה]. */
$ready = function () use ($avi, $PM, $post, $versions, $fill) {
    $c = make_case($avi, $PM);
    $post('case-ai-set', ['case' => $c, 'no_ai' => true]);            // הטיוטה נבנית מהנתונים בלבד; הבינה נדלקת בבדיקה שצריכה אותה
    $post('report-draft-create', ['case' => $c]);
    $post('case-ai-set', ['case' => $c, 'no_ai' => false]);
    $v = $versions($c)[0]['pid'];
    $fill($v);
    return [$c, $v];
};
$submit = fn(string $version, string $as = null) => $post('report-submit', ['version' => $version, 'rev' => $vvrev($version)], $as);
$tryApprove = function (string $v) use ($submit, $approve, $acksAll, $post, $vvrev) {
    $submit($v);
    $r = $approve($v, $acksAll($v));
    if ($r[0] !== 200) $post('report-return', ['version' => $v, 'rev' => $vvrev($v)]);
    return $r;
};
// 17-C2: כל אחד מששת הממצאים החוסמים — כשהוא קיים, האישור נדחה
$blocked = [];
[$CK, $VK] = $ready();
$empty = $sec($ver($VK)[1], 'conclusion');
$save($empty['pid'], []);
$r = $tryApprove($VK); $blocked['mandatory_empty'] = [code($r), $errKinds($r)];
$save($empty['pid'], $P_TEXT);
$submit($VK);
apr_exec("UPDATE memberships SET role = 'staff' WHERE user_id = (SELECT id FROM users WHERE email = 'avi@example.com')");     // האחראי כבר אינו שמאי במשרד
$r = $approve($VK, $acksAll($VK)); $blocked['no_approver'] = [code($r), array_values(array_intersect(['no_approver'], $blocking($VK)))];     // 🟡2: מי שאינו שמאי אינו מאשר — והממצא מוצג בבקרה
apr_exec("UPDATE memberships SET role = 'appraiser' WHERE user_id = (SELECT id FROM users WHERE email = 'avi@example.com')");
$post('report-return', ['version' => $VK, 'rev' => $vvrev($VK)]);
$aiS = $sec($ver($VK)[1], 'visit');
$fake->next = fn() => reply(['blocks' => [['text' => 'לא נערך ביקור בנכס', 'src' => 'd1']]]);
$post('report-section-ai', ['section' => $aiS['pid'], 'rev' => $aiS['rev'], 'replace' => true]);
$r = $tryApprove($VK); $blocked['ai_unreviewed'] = [code($r), $errKinds($r)];
$aiS = $sec($ver($VK)[1], 'visit');
$rvw = $post('report-section-reviewed', ['section' => $aiS['pid'], 'rev' => $aiS['rev']]);
$fake->next = fn() => reply(['blocks' => [['text' => 'בנכס נערכו 7 ביקורים', 'src' => 'd1']]]);
$pl = $sec($ver($VK)[1], 'planning');
$post('report-rebuild', ['version' => $VK, 'sections' => [$pl['pid']], 'include_edited' => true]);
$pl = $sec($ver($VK)[1], 'planning');
$post('report-section-ai', ['section' => $pl['pid'], 'rev' => $pl['rev']]);
$pl = $sec($ver($VK)[1], 'planning');
$post('report-section-reviewed', ['section' => $pl['pid'], 'rev' => $pl['rev']]);
$r = $tryApprove($VK); $blocked['ai_number'] = [code($r), $errKinds($r)];
$pl = $sec($ver($VK)[1], 'planning');
$save($pl['pid'], $P_TEXT);
// הצעה שלא הוכרעה: פרק שנערך, ואז נתון חדש
$ad = $sec($ver($VK)[1], 'area_desc');
$fact($CK, 'rooms', '3');
$r = $tryApprove($VK); $blocked['proposal_pending'] = [code($r), $errKinds($r)];
$rejectAll = function (string $v) use ($ver, $post) { foreach ($ver($v)[1]['sections'] as $x) if ($x['proposal']) $post('report-proposal-decide', ['proposal' => $x['proposal']['pid'], 'decision' => 'reject']); };
$rejectAll($VK);
// תמונה בדוח בלי הרשאת שימוש "דוח"
[$VKv] = $visit($CK);
apr_jobs_run(5.0);
$rejectAll($VK);
$fid = apr_insert('files', ['pid' => apr_pid(), 'office_id' => apr_val('SELECT office_id FROM cases WHERE pid = ?', [$CK]), 'case_id' => $caseId($CK), 'sha256' => hash('sha256', 'x'), 'size' => 1, 'mime' => 'image/jpeg', 'ext' => 'jpg',
    'original_name' => 'a.jpg', 'storage_key' => 'k' . bin2hex(random_bytes(4)), 'uploader_kind' => 'user', 'uploader_id' => 1, 'channel' => 'internal', 'received_at' => apr_now(), 'scan_state' => 'clean']);
$PH = apr_pid();
apr_insert('visit_photos', ['pid' => $PH, 'visit_id' => apr_val('SELECT id FROM visits WHERE pid = ?', [$VKv]), 'file_id' => $fid, 'field_key' => 'x', 'row_no' => 0, 'sort' => 1, 'usage' => 'case', 'created_by' => 1, 'created_at' => apr_now(), 'updated_at' => apr_now()]);
$vs0 = $sec($ver($VK)[1], 'visit');
$svImg = $save($vs0['pid'], [['type' => 'p', 'text' => 'תיאור הביקור'], ['type' => 'image', 'photo' => $PH, 'caption' => 'חזית']]);
$r = $tryApprove($VK); $blocked['image_not_report'] = [code($r), $errKinds($r)];
$imgState = $sec($ver($VK)[1], 'visit')['blocks'][1]['state'] ?? null;
apr_exec("UPDATE visit_photos SET usage = 'report', rights_by = 1, rights_at = ?, rev = rev + 1 WHERE pid = ?", [apr_now(), $PH]);
eq([$blocked, $rvw[0], $svImg[0], $imgState, $sec($ver($VK)[1], 'visit')['blocks'][1]['state'] ?? null, $blocking($VK), $versions($CK)[0]['state'], $versions($CK)[0]['approved_at']],
    [['mandatory_empty' => ['report_blocked', ['mandatory_empty']], 'no_approver' => ['forbidden', ['no_approver']], 'ai_unreviewed' => ['report_blocked', ['ai_unreviewed']], 'ai_number' => ['report_blocked', ['ai_number']],
        'proposal_pending' => ['report_blocked', ['proposal_pending']], 'image_not_report' => ['report_blocked', ['image_not_report']]], 200, 200, 'not_report', 'ok', [], 'draft', null],
    '17-C2 כל אחד מששת הממצאים החוסמים — פרק חובה ריק, אין שמאי מורשה, טקסט בינה שלא נסקר, מספר שלא נמצא בנתונים, הצעה שלא הוכרעה, תמונה בלי הרשאת "דוח" — דוחה את האישור, והתשובה נושאת את הממצא; כשכולם תוקנו — אין חוסם');
$fake->next = $echo;

// 17-C3: אזהרה בלי נימוק ← נדחה; נימוק לכל אזהרה ← מאושר, והנימוקים שמורים. 17-C1: מי שאינו מורשה
$F2 = $fact($CK, 'area', '70', ['unit' => 'sqm', 'area_kind' => 'registered'])[1]['fact']['pid'];
$adS = $sec($ver($VK)[1], 'area_desc');
$save($adS['pid'], [['type' => 'p', 'text' => 'תיאור'], ['type' => 'fact', 'attr' => 'area'], ['type' => 'data_table', 'table' => 'identifiers']]);
$ver($VK);
$rejectAll($VK);
$notReview = code($approve($VK, []));
$submit($VK);
$frozenEdit = code($save($adS['pid'], $P_TEXT));
$acks = $acksAll($VK);
$some = array_slice($acks, 0, max(1, count($acks) - 1), true);
$noAck = $approve($VK, $some);
$blank = $approve($VK, array_fill_keys(array_keys($acks), '   '));
call('POST', 'case-member-add', ['case' => $CK, 'user' => $danaPid], $avi);
$CD = make_case($dana, $PM);                                         // האחראית: דנה. אבי — מנהל המשרד — משתתף בלי approve_report
call('POST', 'case-member-add', ['case' => $CD, 'user' => $userPid('avi@example.com')], $dana);
$post('report-draft-create', ['case' => $CD], $dana);
$VD = $versions($CD)[0]['pid'];
$denied = [code($approve($VK, $acks, $dana)), code($approve($VK, $acks, $owner)), code($approve($VD, [], $avi)), code($post('report-approve', ['version' => $VK, 'rev' => $vvrev($VK), 'acks' => $acks]))];
$stateBefore = $versions($CK)[0]['state'];
$ok = $approve($VK, $acks);
$twice = code($approve($VK, $acks));
$row = $versions($CK)[0];
$snap = json_decode((string)$row['snapshot'], true);
eq([$notReview, $frozenEdit, count($acks) >= 3, code($noAck), count($noAck[1]['error']['findings'] ?? []), code($blank), $denied, $stateBefore, $ok[0], $twice, [$row['state'], (int)$row['approved_by'] > 0, $row['content_hash'] === hash('sha256', $row['snapshot']), $ok[1]['content_hash'] ?? null],
    json_decode($row['approval_notes'], true) == $acks, (int)apr_val('SELECT COUNT(*) FROM report_findings_ack WHERE version_id = ?', [$row['id']]) === count($acks), apr_val('SELECT status FROM cases WHERE pid = ?', [$CK]),
    array_keys($snap), array_keys($snap['data']['tables']), $snap['data']['facts']['area']['value']['text'] ?? null, (int)apr_val("SELECT COUNT(*) FROM audit_log WHERE action = 'report.approve' AND entity_pid = ?", [$VK])],
    ['report_state', 'report_state', true, 'report_ack_required', 1, 'report_ack_required', ['forbidden', 'no_office', 'forbidden', 'confirm_required'], 'in_review', 200, 'report_approved', ['approved', true, true, $row['content_hash']], true, true, 'approved',
        ['at', 'approved_by', 'template', 'findings', 'sections', 'data'], APR_REPORT_TABLES, '70', 1],
    '17-C3 אזהרה בלי נימוק (או בנימוק ריק) ← נדחה; נימוק לכל אזהרה ← מאושר, והנימוקים שמורים עם הגרסה; 17-C1 שמאית בלי approve_report, מנהל משרד שאינו האחראי ומנהל המערכת — נדחים; בלי אימות של פעולה רגישה — נדחה; טיוטה אינה מאושרת בלי "בבדיקה"; אישור שני ← "הגרסה כבר אושרה"; מצב התיק ← approved; ה-snapshot מלא');

// 17-C5: אחרי האישור — כל פעולת עדכון על הגרסה, על פרקיה ועל ה-snapshot נדחית; ה-hash אינו משתנה
$raises = function (string $sql, array $args = []): bool { try { apr_exec($sql, $args); return false; } catch (PDOException) { return true; } };
$anyS = $sec($ver($VK)[1], 'area_desc');
$sp = $snap['sections'][2]['pid'];
$after = [code($save($sp, $P_TEXT)), code($post('report-section-add', ['version' => $VK, 'title' => 'x'])), code($post('report-section-delete', ['section' => $sp, 'rev' => 1])), code($post('report-rebuild', ['version' => $VK, 'include_edited' => true])),
    code($post('report-section-ai', ['section' => $sp, 'rev' => 1])), code($post('report-section-reviewed', ['section' => $sp, 'rev' => 1])), code($submit($VK)), code($post('report-return', ['version' => $VK, 'rev' => $vvrev($VK)])),
    code($post('report-version-delete', ['version' => $VK, 'rev' => $vvrev($VK), 'reason' => 'x'])), code($post('report-comment-add', ['version' => $VK, 'text' => 'x'])), code($post('report-draft-create', ['case' => $CK]))];
$sql = [$raises("UPDATE report_versions SET snapshot = '{}', rev = rev + 1 WHERE pid = ?", [$VK]), $raises("UPDATE report_versions SET content_hash = 'x', rev = rev + 1 WHERE pid = ?", [$VK]), $raises("UPDATE report_versions SET state = 'draft', rev = rev + 1 WHERE pid = ?", [$VK]),
    $raises("UPDATE report_versions SET approved_by = NULL, rev = rev + 1 WHERE pid = ?", [$VK]), $raises("UPDATE report_versions SET deleted_at = 'now', delete_reason = 'x', rev = rev + 1 WHERE pid = ?", [$VK]),
    $raises("UPDATE report_sections SET content = '[]', rev = rev + 1 WHERE pid = ?", [$sp]), $raises('DELETE FROM report_sections WHERE pid = ?', [$sp]), $raises('DELETE FROM report_versions WHERE pid = ?', [$VK]),
    $raises("INSERT INTO report_sections (pid, version_id, key, title, sort, updated_at) VALUES ('zzzzzzzzzzzzzzzz', ?, 'zz', 'x', 99, 'now')", [$row['id']]), $raises('UPDATE report_findings_ack SET reason = ? WHERE version_id = ?', ['x', $row['id']]),
    $raises("INSERT INTO report_versions (pid, report_id, office_id, case_id, number, state, origin, template, created_at, approved_at, approved_by, snapshot, content_hash) VALUES ('yyyyyyyyyyyyyyyy', ?, ?, ?, 9, 'approved', 'manual', '{}', 'now', 'now', 1, '{}', 'x')", [$row['report_id'], $row['office_id'], $row['case_id']]),
    $raises("UPDATE report_versions SET state = 'approved', approved_at = 'now', approved_by = 1, snapshot = '{}', content_hash = 'x', rev = rev + 1 WHERE pid = ?", [$VD])];
$libs = array_map(fn($f) => (string)file_get_contents($f), glob(__DIR__ . '/../lib/*.php'));
eq([$after, $sql, $versions($CK)[0]['content_hash'] === $row['content_hash'], $versions($CK)[0]['snapshot'] === $row['snapshot'], count(array_filter($libs, fn($t) => preg_match("/'state' => 'approved'|SET state = 'approved'/", $t) === 1))],
    [['report_state', 'report_state', 'report_state', 'report_state', 'report_state', 'report_state', 'report_state', 'report_state', 'report_state', 'report_state', 'report_exists'], array_fill(0, 12, true), true, true, 1],
    '17-C5 אחרי האישור כל פעולת API על הגרסה ועל פרקיה נדחית, וגם עדכון ישיר במסד (snapshot, hash, מצב, מאשר, פרק, מחיקה, נימוק); 17-C8 אין דרך ליצור גרסה approved שלא דרך האישור — לא בהוספה ולא בדילוג על "בבדיקה"; רק קובץ אחד בקוד כותב approved');

// 17-C6: נתון משתנה אחרי האישור ← stale_after_approval; הקריאה מחזירה את הערך הישן, מה-snapshot. מקרה קצה 7: האחראי הוחלף — האישור נשאר על שם המאשר
$staleSet = (int)$versions($CK)[0]['stale_after_approval'];
$post('fact-correct', ['fact' => $F2, 'rev' => $frev($F2), 'value' => '72', 'correction_note' => 'תיקון']);
$old = $ver($VK)[1];
$oldArea = array_values(array_filter($sec($old, 'area_desc')['blocks'], fn($b) => $b['type'] === 'fact'))[0];
eq([$old['frozen'], $old['version']['state'], $old['version']['stale_after_approval'], $oldArea['value']['text'], $old['version']['approved_by'], $versions($CK)[0]['content_hash'] === $row['content_hash'], count($old['findings']) === count($acks), $old['findings'][0]['ack'] ?? null,
    (int)apr_val("SELECT COUNT(*) FROM audit_log WHERE action = 'report.stale' AND entity_pid = ?", [$VK]), $report($CK)[1]['can_create'], $staleSet, $ver($VK)[1]['version']['stale_after_approval'],
    $raises('UPDATE report_versions SET stale_after_approval = 0, rev = rev + 1 WHERE pid = ?', [$VK])],
    [true, 'approved', true, '70', 'אבי', true, true, 'נבדק ואושר בידי השמאי', 1, 'new_version', 0, true, true],
    '17-C6 אחרי אישור, שינוי נתון שבדוח ← stale_after_approval = 1 (פעם אחת, ביומן); הגרסה מחזירה את **הערך הישן** מה-snapshot, עם הנימוקים, וה-hash אינו משתנה');

// 17-C7: "גרסה חדשה" ← טיוטה במספר הבא, עם הערך החדש; הקודמת עדיין approved; אישור החדשה ← הקודמת superseded, ותוכנה נשמר
$noReason = code($post('report-version-new', ['case' => $CK]));
check('§8.2 גרסה חדשה בלי נימוק ← reason_required', $noReason === 'reason_required', $noReason);
$nv = $post('report-version-new', ['case' => $CK, 'reason' => 'השטח תוקן אחרי מדידה']);
$V2 = $nv[1]['version'] ?? '';
$new = $ver($V2)[1];
$newArea = array_values(array_filter($sec($new, 'area_desc')['blocks'], fn($b) => $b['type'] === 'fact'))[0];
$cmp = call('GET', 'report-compare', [], $avi, ['version' => $V2, 'with' => $VK])[1];
$cmpArea = array_column($cmp['sections'], null, 'key')['area_desc'];
$mid = [$versions($CK)[0]['state'], $versions($CK)[1]['state'], (int)$versions($CK)[1]['number'], $versions($CK)[1]['origin'], $versions($CK)[1]['reopen_reason'], code($post('report-version-new', ['case' => $CK, 'reason' => 'x']))];
$submit($V2);
$ok2 = $approve($V2, $acksAll($V2));
$oldAgain = $ver($VK)[1];
eq([$noReason, $nv[0], $newArea['value']['text'], $newArea['changed'] ?? null, $mid, [$cmpArea['status'], $cmpArea['removed'], $cmpArea['added']], $ok2[0], [$versions($CK)[0]['state'], $versions($CK)[1]['state']],
    array_values(array_filter($sec($oldAgain, 'area_desc')['blocks'], fn($b) => $b['type'] === 'fact'))[0]['value']['text'], $oldAgain['version']['state'], $versions($CK)[0]['content_hash'] === $row['content_hash'], $report($CK)[1]['versions'][0]['number']],
    ['reason_required', 200, '72', true, ['approved', 'draft', 2, 'from_version', 'השטח תוקן אחרי מדידה', 'report_exists'], ['changed', ['שטח: 70 מ"ר (רשום)'], ['שטח: 72 מ"ר (רשום)']], 200, ['superseded', 'approved'], '70', 'superseded', true, 2],
    '17-C7 "גרסה חדשה" (בנימוק) ← draft במספר הבא עם הערך **החדש**, והקודמת עדיין approved; ההשוואה מציגה מה השתנה; אישור החדשה ← הקודמת superseded, וקריאתה עדיין מחזירה את תוכנה ואת ה-hash שלה');

// ═══ 17-D3: שותף ממשרד אחר — טיוטה רק עם see_draft; קריאה בלבד ═════
$share = function (string $case, array $perms) use ($avi, $boaz) {
    $p = ['kind' => 'cross_office', 'email' => 'boaz@example.com', 'scope' => 'all', 'perms' => $perms, 'days' => 0];
    $pv = call('POST', 'share-preview', ['case' => $case] + $p, $avi);
    $mk = call('POST', 'share-create', ['case' => $case, 'preview' => $pv[1]['preview'] ?? ''] + $p, $avi);
    return [$mk[1]['share'] ?? '', call('POST', 'share-respond', ['share' => $mk[1]['share'] ?? '', 'accept' => true], $boaz)[0]];
};
[$CS, $VS] = $ready();                                               // טיוטה בלבד
[, $acc1] = $share($CS, []);
$noDraft = [$report($CS, $boaz)[0], array_column($report($CS, $boaz)[1]['versions'] ?? [], 'state'), $ver($VS, $boaz)[0]];
[, $acc2] = $share($CK, []);                                         // לתיק הזה יש גרסה מאושרת, גרסה שהוחלפה — ואין טיוטה
$finalOnly = [array_column($report($CK, $boaz)[1]['versions'] ?? [], 'state'), $ver($V2, $boaz)[0], $ver($V2, $boaz)[1]['findings'] ?? null, $ver($V2, $boaz)[1]['comments'] ?? null,
    isset($ver($V2, $boaz)[1]['sections'][0]['sources']), $report($CK, $boaz)[1]['can_create'], $report($CK, $boaz)[1]['perms']];
[$CS2, $VS2] = $ready();
[, $acc3] = $share($CS2, ['see_draft' => true]);
$pd = $ver($VS2, $boaz);
$s0 = $pd[1]['sections'][0] ?? [];
$pw = [code($save($s0['pid'] ?? '', $P_TEXT, $boaz, 1)), code($post('report-submit', ['version' => $VS2, 'rev' => 1], $boaz)), code($post('report-approve', ['version' => $VS2, 'rev' => 1, 'acks' => [], 'confirm' => 'אישור'], $boaz)),
    code($post('report-comment-add', ['version' => $VS2, 'text' => 'x'], $boaz)), code($post('report-draft-create', ['case' => $CS], $boaz)), code(call('GET', 'report-compare', [], $boaz, ['version' => $VS2, 'with' => $VS2]))];
eq([[$acc1, $acc2, $acc3], $noDraft, $finalOnly, $pd[0], array_column($report($CS2, $boaz)[1]['versions'], 'state'), array_keys($s0), $pd[1]['findings'], $pd[1]['lists'] ?? null, $pw],
    [[200, 200, 200], [200, [], 404], [['approved', 'superseded'], 200, [], [], false, null, ['edit' => false, 'judge' => false, 'approve' => false]], 200, ['draft'],
        ['pid', 'key', 'title', 'mandatory', 'blocks'], [], null, array_fill(0, 6, 'not_found')],
    '17-D3 שותף ממשרד אחר: בלי see_draft — הטיוטה אינה ברשימה ואינה נקראת (404), גרסה מאושרת כן (בלי בקרה, הערות ומקורות); עם see_draft — הטיוטה נקראת, הפרקים בלבד; כל פעולה משנה — 404');

// 🟡7: שותף ממשרד אחר — בלי הערות הבודק, בלי פרטי האישור ובלי ספירת הבקרה; גם כשיש הערה על הטיוטה
$post('report-comment-add', ['version' => $VS2, 'text' => 'הערה פנימית לבודק']);
$pdc = $ver($VS2, $boaz)[1];
$pfin = $ver($V2, $boaz)[1];
eq([count($ver($VS2)[1]['comments']), $pdc['comments'] ?? null, $pdc['internal'] ?? null, $report($CS2, $boaz)[1]['check'] ?? null, $report($CS2)[1]['check'] !== null, array_key_exists('approval', $pfin) ? $pfin['approval'] : 'x', $ver($V2)[1]['approval']['by'] ?? null,
    $report($CK, $boaz)[1]['check'] ?? null, array_key_exists('reopen_reason', $pfin['version']), $ver($V2)[1]['version']['reopen_reason']],
    [1, [], false, null, true, null, 'אבי', null, false, 'השטח תוקן אחרי מדידה'],
    '🟡7 / 🟡6: שותף אינו מקבל את הערות הבודק (comments), את פרטי האישור (approval), את ספירת הבקרה (check) ואת נימוק הפתיחה של גרסה — והמשתתף הפנימי כן');

// ═══ מקרי קצה ═════════════════════════════════════════════════
// 11: הסרת פרק חובה מהגרסה — מותרת, ונרשמת. 12: מחיקת טיוטה — רק approve_report, בנימוק, מחיקה רכה. הערות לבודק
[$CE, $VE] = $ready();
call('POST', 'case-member-add', ['case' => $CE, 'user' => $danaPid], $avi);
$m = $sec($ver($VE)[1], 'methods');
$del = $post('report-section-delete', ['section' => $m['pid'], 'rev' => $m['rev']], $dana);
$addS = $post('report-section-add', ['version' => $VE, 'title' => 'פרק נוסף של השמאי']);
$keysAfter = array_column($ver($VE)[1]['sections'], 'title');
$cm = $post('report-comment-add', ['version' => $VE, 'section' => $sec($ver($VE)[1], 'purpose')['pid'], 'text' => 'לבדוק את המועד הקובע'], $dana);
$cmBad = code($post('report-comment-add', ['version' => $VE, 'section' => $m['pid'], 'text' => 'x']));
$rs = $post('report-comment-resolve', ['comment' => $cm[1]['comment'] ?? '']);
$cview = $ver($VE)[1]['comments'];
$delTry = [code($post('report-version-delete', ['version' => $VE, 'rev' => $vvrev($VE), 'reason' => 'x'], $dana)), code($post('report-version-delete', ['version' => $VE, 'rev' => $vvrev($VE)])), code($post('report-version-delete', ['version' => $VE, 'rev' => 999, 'reason' => 'x']))];
$dl = $post('report-version-delete', ['version' => $VE, 'rev' => $vvrev($VE), 'reason' => 'נוצרה בטעות']);
$gone = [$ver($VE)[0], $report($CE)[1]['versions'], $report($CE)[1]['can_create'], apr_val('SELECT delete_reason FROM report_versions WHERE pid = ?', [$VE])];
$re = $post('report-draft-create', ['case' => $CE]);
eq([$del[0], $addS[0], in_array('שיטות השומה', $keysAfter, true), end($keysAfter), apr_val("SELECT json_extract(meta, '$.mandatory') FROM audit_log WHERE action = 'report.section_delete' AND entity_pid = ?", [$m['pid']]),
    $cm[0], $cmBad, $rs[0], [$cview[0]['text'], $cview[0]['by'], $cview[0]['resolved_by'], $cview[0]['section'] !== null], $delTry, $dl[0], $gone, $re[0], (int)$versions($CE)[0]['number'], code($post('report-comment-resolve', ['comment' => $cm[1]['comment'] ?? '']))],
    [200, 200, false, 'פרק נוסף של השמאי', 1, 200, 'not_found', 200, ['לבדוק את המועד הקובע', 'דנה', 'אבי', true], ['forbidden', 'reason_required', 'report_changed'], 200, [404, [], 'draft', 'נוצרה בטעות'], 200, 2, 'nothing_to_change'],
    'מקרה קצה 11: פרק חובה מוסר מהגרסה בידי שמאי בתיק, וההסרה נרשמת; פרק נוסף נכנס בסוף; הערות לבודק — מי כתב, מי סגר; מקרה קצה 12: טיוטה נמחקת רק בידי approve_report ובנימוק (מחיקה רכה), ואחריה אפשר ליצור טיוטה חדשה');

// 13: תיק בלי ביקור — הטריגר לא הופעל, "יצירת טיוטה" זמינה, ופרק הביקור מסומן. ביקור נוסף אחרי שיש טיוטה — רענון והצעות, לא גרסה שנייה
$CNV = make_case($avi, $PM);
$post('report-draft-create', ['case' => $CNV]);
$VNV = $versions($CNV)[0]['pid'];
$n0 = count($versions($C));
[$V2nd] = $visit($C);
apr_jobs_run(5.0);
$visitSec = $sec(json_decode(json_encode(['sections' => array_map(fn($s) => ['key' => $s['key'], 'blocks' => json_decode($s['content'], true)], apr_rows('SELECT s.key, s.content FROM report_sections s JOIN report_versions v ON v.id = s.version_id WHERE v.pid = ?', [$VER]))]), true), 'visit');
eq([in_array('לא ידוע: לא נערך ביקור בנכס', array_column($ver($VNV)[1]['findings'], 'text'), true), count($versions($C)), $n0, count(array_filter($visitSec['blocks'], fn($b) => $b['type'] === 'p')),
    (int)apr_val("SELECT COUNT(*) FROM jobs WHERE dedupe_key LIKE 'report.refresh:%' AND status = 'done'") >= 1, apr_report_job(['case' => 'aaaaaaaaaaaaaaaa']), apr_report_job([])],
    [true, 1, 1, 2, true, 'done', 'done'],
    'מקרה קצה 13: תיק בלי ביקור — פרק הביקור מסומן "לא ידוע", והטיוטה נוצרת ידנית; ביקור נוסף כשכבר יש טיוטה ← אין גרסה שנייה: משימת רענון מעדכנת את פרק הביקור (שלא נערך) בשני הביקורים');

// ═══ מה שהמסכים נשענים עליו (§10) ═══════════════════════════════
$crK = $report($CK)[1];
$crN = $report($CNV)[1];
$fN = $ver($VNV)[1]['findings'];
$cvK = call('GET', 'case', [], $avi, ['case' => $CK])[1];
$delPh = $post('visit-photo-delete', ['photo' => $PH, 'rev' => (int)apr_val('SELECT rev FROM visit_photos WHERE pid = ?', [$PH])]);
[$CZ, $VZ] = $ready();
$share($CZ, []);
$cvZ = call('GET', 'case', [], $boaz, ['case' => $CZ]);
eq([$cvK['report']['versions'], $cvK['report']['state'] === $versions($CK)[count($versions($CK)) - 1]['state'], $crK['check'], $crN['check'],
    $ver($VNV)[1]['internal'], in_array('area', array_column($sec($ver($VAI)[1], 'area_desc')['sources'], 'attr'), true),
    code($delPh), apr_val('SELECT deleted_at FROM visit_photos WHERE pid = ?', [$PH]), $cvZ[0], $cvZ[1]['report'] ?? 'x', array_key_exists('report', $cvZ[1])],
    [count($versions($CK)), true, null, ['version' => $VNV, 'number' => 1, 'state' => 'draft', 'block' => count(array_filter($fN, fn($f) => $f['level'] === 'block')), 'warn' => count(array_filter($fN, fn($f) => $f['level'] === 'warn'))],
        true, true, 'report_photo_locked', null, 200, 'x', true],
    'למסכים: כרטיס התיק נושא את סיכום הדוח (לשותף בלי see_draft — null); case-report נושא את ספירת הבקרה של הגרסה הפתוחה; מקור מסוג נתון נושא את המאפיין; מקרה קצה 8 — תמונה שגרסה שאושרה מפנה אליה אינה נמחקת');

// שותף בשיתוף פריטים (לא כל התיק): גם גרסה שאושרה אינה נקראת
$gil = ident('7', 'גיל', 'gil@example.com');
office_for($gil, 'משרד ג');
$pg = ['kind' => 'cross_office', 'email' => 'gil@example.com', 'scope' => 'items', 'items' => [['type' => 'visit', 'pid' => $VKv]], 'perms' => [], 'days' => 0];
$pvg = call('POST', 'share-preview', ['case' => $CK] + $pg, $avi);
$mkg = call('POST', 'share-create', ['case' => $CK, 'preview' => $pvg[1]['preview'] ?? ''] + $pg, $avi);
$accg = call('POST', 'share-respond', ['share' => $mkg[1]['share'] ?? '', 'accept' => true], $gil);
$approvedK = array_values(array_filter($versions($CK), fn($v) => $v['state'] === 'approved'))[0]['pid'] ?? '';
// ארבעת הממצאים הנעולים חוסמים גם אם התבנית של הגרסה אומרת אחרת (תבנית ישנה)
$cnvRow = apr_row('SELECT * FROM cases WHERE pid = ?', [$CNV]) + ['perms' => [], 'my_role' => 'system', 'share' => null];
$vnvRow = $versions($CNV)[0];
$vnvRow['template'] = json_encode(['findings' => ['mandatory_empty' => 'warn', 'no_approver' => 'warn', 'note_missing' => 'block']] + apr_pc_report_default(), JSON_UNESCAPED_UNICODE);
$lvl = array_column(apr_report_findings($cnvRow, $vnvRow, apr_report_sections(['user' => null, 'office' => null], $cnvRow, $vnvRow)), 'level', 'kind');
// include_unverified = omit: נתון שלא אומת אינו נכנס לפרק
$cpB = fn(string $mode) => in_array('fact', array_column(apr_report_build($cpRow, 'area_desc', ['include_unverified' => $mode] + apr_pc_report_default())['blocks'], 'type'), true);
$delMsg = '';
try { apr_exec('DELETE FROM report_versions WHERE pid = ?', [$VNV]); } catch (PDOException $e) { $delMsg = $e->getMessage(); }
eq([[$pvg[0], $mkg[0], $accg[0]], call('GET', 'case', [], $gil, ['case' => $CK])[0], $ver($approvedK, $gil)[0], code($report($CK, $gil)), [$lvl['mandatory_empty'] ?? null, $lvl['note_missing'] ?? null],
    [$cpB('marked'), $cpB('omit')], code($post('report-version-new', ['case' => $CNV, 'reason' => 'אין מה לפתוח'])), str_contains($delMsg, 'report_versions: no delete')],
    [[200, 200, 200], 200, 404, 'not_found', ['block', 'block'], [true, false], 'report_state', true],
    'שותף בשיתוף פריטים אינו קורא גם גרסה שאושרה; ממצא נעול חוסם גם כשהתבנית אומרת "אזהרה"; include_unverified = omit; "גרסה חדשה" רק כשיש גרסה שאושרה; גרסה אינה נמחקת מהמסד (טריגר)');

// ═══ סבב 2 ═══════════════════════════════════════════════════
// B2: עריכה ידנית שנשמרה **בזמן** הקריאה לספק אינה נדרסת — הניסוח נשמר כהצעה (origin = ai), והפרק נשאר כפי שהאדם שמר אותו
$CR = make_case($avi, $PM);
$members($CR);
$FR = $fact($CR, 'area', '85.5', ['unit' => 'sqm', 'area_kind' => 'registered'])[1]['fact']['pid'];
$post('case-ai-set', ['case' => $CR, 'no_ai' => true]);
$post('report-draft-create', ['case' => $CR]);
$post('case-ai-set', ['case' => $CR, 'no_ai' => false]);
$VR = $versions($CR)[0]['pid'];
$fill($VR);
$adR = $sec($ver($VR)[1], 'area_desc');
$during = null;
$fake->next = function () use (&$during, $save, $adR) {
    $during = $save($adR['pid'], [['type' => 'p', 'text' => 'עריכה ידנית שנשמרה בזמן הקריאה לספק']]);
    return reply(['blocks' => [['text' => 'שטח הדירה 85.5 מ"ר (רשום)', 'src' => 'd1']]]);
};
$aiR = $post('report-section-ai', ['section' => $adR['pid'], 'rev' => $adR['rev']]);
$adR2 = $sec($ver($VR)[1], 'area_desc');
$propRow = apr_row("SELECT p.* FROM section_proposals p JOIN report_sections s ON s.id = p.section_id WHERE s.pid = ? AND p.state = 'pending'", [$adR['pid']]);
// "ניסוח עכשיו" על פרק שנערך ידנית: רק עם replace: true מפורש — ובלי זה אין קריאה לספק (🟡8)
$callsR = count($fake->calls);
$noReplace = code($post('report-section-ai', ['section' => $adR2['pid'], 'rev' => $adR2['rev']]));
$callsR2 = count($fake->calls);
$accR = $post('report-proposal-decide', ['proposal' => $adR2['proposal']['pid'] ?? '', 'decision' => 'accept']);
$adR3 = $sec($ver($VR)[1], 'area_desc');
eq([$during[0] ?? null, $aiR[1]['state'] ?? null, $aiR[1]['as_proposal'] ?? null, $adR2['blocks'][0]['text'] ?? null, $adR2['origin'], $adR2['user_edited'], $adR2['proposal']['origin'] ?? null, $propRow['ai_run_id'] !== null, $propRow['ai_input_hash'] !== null,
    $noReplace, $callsR2 - $callsR, $accR[0], $adR3['origin'], $adR3['ai_reviewed'], $adR3['blocks'][0]['text'] ?? null, $adR3['blocks'][0]['ai'] ?? null, in_array('ai_unreviewed', $blocking($VR), true)],
    [200, 'done', true, 'עריכה ידנית שנשמרה בזמן הקריאה לספק', 'manual', true, 'ai', true, true, 'report_replace', 0, 200, 'ai', false, 'שטח הדירה 85.5 מ"ר (רשום)', true, true],
    'B2: שמירה ידנית מתוך הקריאה לספק ← הטקסט של האדם נשאר, והניסוח נשמר כהצעה שמקורה בבינה; קבלת ההצעה ← טקסט בינה שלא נסקר (חוסם); 🟡8: "ניסוח עכשיו" על פרק שנערך בלי replace ← 409 ובלי קריאה לספק');

// B3: 85.5 ← טקסט בינה ← "נבדק" ← הנתון מתוקן ל-120 ← הסקירה מתבטלת, נוצרת הצעה, והבקרה חוסמת ("טקסט בינה נכתב לפני שינוי בנתונים") — האישור נדחה
$rvR = $post('report-section-reviewed', ['section' => $adR3['pid'], 'rev' => $adR3['rev']]);
$cleanR = $blocking($VR);
$post('fact-correct', ['fact' => $FR, 'rev' => $frev($FR), 'value' => '120', 'correction_note' => 'מדידה חוזרת']);
$adR4 = $sec($ver($VR)[1], 'area_desc');
$blockR = $blocking($VR);
$rejectAll($VR);
$r = $tryApprove($VR);
$blockR2 = [code($r), $errKinds($r)];
// דחיית ההצעה אינה מנקה את הממצא; סקירה מחדש (מול הנתונים של הרגע) — כן. ואז שמירה חוזרת של אותו טקסט אינה מסירה את סימון הבינה (🟡3)
$adR5 = $sec($ver($VR)[1], 'area_desc');
$post('report-section-reviewed', ['section' => $adR5['pid'], 'rev' => $adR5['rev']]);
$blockR3 = $blocking($VR);
$adR6 = $sec($ver($VR)[1], 'area_desc');
$strip = fn(array $bl) => array_map(fn($b) => array_intersect_key($b, ['type' => 1, 'text' => 1, 'attr' => 1, 'table' => 1, 'what' => 1, 'photo' => 1]), $bl);
$reSave = $save($adR6['pid'], [...$strip($adR6['blocks']), ['type' => 'p', 'text' => 'תוספת של עובדת המשרד']], $tal);          // בלי ai: true, ובידי מי שאינה שמאית
$adR7 = $sec($ver($VR)[1], 'area_desc');
$aviSave = $save($adR7['pid'], [...$strip($adR7['blocks']), ['type' => 'p', 'text' => 'תוספת של השמאי']]);                      // שמאי, והטקסט של הבינה לא השתנה
$adR8 = $sec($ver($VR)[1], 'area_desc');
$talRewrite = $save($adR8['pid'], [['type' => 'p', 'text' => 'עובדת המשרד כתבה את הפרק מחדש']], $tal);                               // מי שאינה שמאית אינה מורידה את הסימון, גם כשלא נשאר טקסט בינה
$adR8b = $sec($ver($VR)[1], 'area_desc');
$adR8x = $adR8;
$adR8 = $adR8b;
$aviEdit = $save($adR8['pid'], [['type' => 'p', 'text' => 'שטח הדירה 120 מ"ר (רשום) — נוסח מחדש בידי השמאי']]);                 // שמאי שינה בפועל את טקסט הבינה
$adR9 = $sec($ver($VR)[1], 'area_desc');
eq([$rvR[0], $cleanR, $adR4['ai_reviewed'], $adR4['proposal']['origin'] ?? null, $blockR, $blockR2, $blockR3,
    [$reSave[0], $adR7['origin'], $adR7['ai_reviewed'], $adR7['blocks'][0]['ai'] ?? null], [$aviSave[0], $adR8x['origin'], $adR8x['blocks'][0]['ai'] ?? null], [$talRewrite[0], $adR8b['origin'], $adR8b['ai_reviewed']], [$aviEdit[0], $adR9['origin']]],
    [200, [], false, 'auto', ['ai_unreviewed', 'proposal_pending', 'ai_stale'], ['report_blocked', ['ai_unreviewed', 'ai_stale']], [],
        [200, 'ai', false, true], [200, 'ai', true], [200, 'ai', false], [200, 'manual']],
    'B3: נתון שהשתנה אחרי שטקסט הבינה נסקר ← הסקירה מתבטלת, נוצרת הצעת עדכון, והאישור נחסם ב-ai_stale גם אחרי דחיית ההצעה; סקירה מחדש מנקה. 🟡3: שמירה חוזרת של טקסט הבינה (בלי ai: true, בידי מי שאינה שמאית) משאירה את הפרק "בינה" ומבטלת את הסקירה; שמאי שלא שינה את טקסט הבינה — הסימון נשאר; שמאי ששינה אותו — יורד');

// B3 + 17-C6: גרסה שאושרה עם פרק בינה — שינוי בנתון שעליו נכתב הטקסט מדליק "נתונים השתנו אחרי האישור"
[$CS, $VS] = [make_case($avi, $PM), null];
$FS = $fact($CS, 'area', '64', ['unit' => 'sqm', 'area_kind' => 'registered'])[1]['fact']['pid'];
$fake->next = fn() => reply(['blocks' => [['text' => 'תיאור שנוסח בבינה, בלי מספרים', 'src' => 'd1']]]);
$post('report-draft-create', ['case' => $CS]);
$VS = $versions($CS)[0]['pid'];
$fill($VS);
foreach ($ver($VS)[1]['sections'] as $x) if ($x['origin'] === 'ai' && !$x['ai_reviewed']) $post('report-section-reviewed', ['section' => $x['pid'], 'rev' => $x['rev']]);
$submit($VS);
$okS = $approve($VS, $acksAll($VS));
$staleS0 = $ver($VS)[1]['version']['stale_after_approval'] ?? null;
$post('fact-correct', ['fact' => $FS, 'rev' => $frev($FS), 'value' => '66', 'correction_note' => 'תיקון אחרי האישור']);
$viewS = $ver($VS)[1];
eq([$okS[0], $sec($viewS, 'area_desc')['origin'], in_array('fact', $types($sec($viewS, 'area_desc')['blocks']), true), $staleS0, $viewS['version']['stale_after_approval'], $sec($viewS, 'area_desc')['blocks'][0]['text']],
    [200, 'ai', false, false, true, 'תיאור שנוסח בבינה, בלי מספרים'],
    'B3 / 17-C6: פרק בינה (בלי בלוק fact) בגרסה שאושרה — שינוי הנתון אחרי האישור מדליק stale_after_approval; הטקסט שאושר אינו משתנה');
$fake->next = $echo;

// 🟡7: הממצאים שנשענים על עסקאות, שומה, מקורות, משימות, דרישות וסתירות — ומאפיינים רבי-ערכים בדוח (§6.2: "נתונים שאינם rejected")
$CF = make_case($avi, $PM);
$members($CF);
$fact($CF, 'area', '85.5', ['unit' => 'sqm', 'area_kind' => 'registered']);
$fact($CF, 'rooms', '3');
$fact($CF, 'rooms', '4');                                                                  // סתירה פתוחה
$fact($CF, 'registered_owner', 'פלוני בן פלוני');
$fact($CF, 'registered_owner', 'אלמונית בת פלוני');
$fact($CF, 'encumbrance', 'משכנתה מדרגה ראשונה');
$FD = $fact($CF, 'floor', '3')[1]['fact']['pid'];
$post('task-create', ['case' => $CF, 'title' => 'לברר היתר']);
apr_exec('UPDATE requirements SET mandatory = 1 WHERE case_id = ?', [$caseId($CF)]);        // דרישת חובה שלא הושלמה
$altOf = fn(string $case) => apr_val('SELECT pid FROM alternatives WHERE case_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$caseId($case)]) ?: ($post('alt-save', ['case' => $case, 'name' => 'חלופה לבדיקה', 'key_date' => '2026-09-01', 'state_kind' => 'other'])[1]['alt'] ?? null);
$AF = $altOf($CF);
$KF = $post('comp-add', ['case' => $CF, 'values' => ['price' => '2000000', 'deal_date' => '2026-01-10', 'area' => '80', 'area_kind' => 'registered', 'condition' => 'טוב']])[1]['comp'] ?? null;
$KF2 = $post('comp-add', ['case' => $CF, 'values' => ['price' => '3000000', 'deal_date' => '2026-02-10', 'area' => '90', 'area_kind' => 'registered', 'condition' => 'טוב']])[1]['comp'] ?? null;
foreach ([$KF, $KF2] as $k) $post('comp-status', ['comp' => $k, 'rev' => (int)apr_val('SELECT rev FROM comps WHERE pid = ?', [$k]), 'status' => 'selected']);
$GF = $post('comp-set-save', ['case' => $CF, 'name' => 'קבוצה א', 'unit_basis' => 'total', 'method' => 'weighted', 'rounding' => 'none'])[1]['set'] ?? null;
$gfrev = fn() => (int)apr_val('SELECT rev FROM comp_sets WHERE pid = ?', [$GF]);
foreach ([$KF, $KF2] as $k) { $post('comp-set-member', ['set' => $GF, 'comp' => $k, 'rev' => $gfrev(), 'member' => true]); $post('comp-weight', ['set' => $GF, 'comp' => $k, 'rev' => $gfrev(), 'weight' => '1']); }
$APF = $post('approach-save', ['alt' => $AF, 'kind' => 'comparison', 'comp_set' => $GF, 'reasoning' => 'שוק פעיל'])[1]['approach'] ?? null;
$srcKey = apr_val("SELECT key FROM sources ORDER BY id LIMIT 1");
$recF = call('POST', 'source-check-record', ['case' => $CF, 'source' => $srcKey, 'status' => 'not_found', 'coverage_note' => 'נבדק לפי גוש וחלקה', 'query_text' => 'גוש 6158 חלקה 12'], $avi);
$post('case-ai-set', ['case' => $CF, 'no_ai' => true]);
$post('report-draft-create', ['case' => $CF]);
$VF = $versions($CF)[0]['pid'];
// המסמך שהנתון נשען עליו נמחק
$fidF = apr_insert('files', ['pid' => apr_pid(), 'office_id' => apr_val('SELECT office_id FROM cases WHERE pid = ?', [$CF]), 'case_id' => $caseId($CF), 'sha256' => hash('sha256', 'f'), 'size' => 1, 'mime' => 'application/pdf', 'ext' => 'pdf',
    'original_name' => 'f.pdf', 'storage_key' => 'k' . bin2hex(random_bytes(4)), 'uploader_kind' => 'user', 'uploader_id' => 1, 'channel' => 'internal', 'received_at' => apr_now(), 'scan_state' => 'clean']);
$docGone = '';
try { apr_exec('UPDATE facts SET source_file_id = ?, rev = rev + 1 WHERE pid = ?', [$fidF, $FD]); apr_exec('UPDATE files SET deleted_at = ? WHERE id = ?', [apr_now(), $fidF]); } catch (PDOException $e) { $docGone = $e->getMessage(); }
$viewF = $ver($VF)[1];
$kindsF = array_values(array_unique(array_column($viewF['findings'], 'kind')));
$propF = array_column(array_filter($sec($viewF, 'property')['blocks'], fn($b) => $b['type'] === 'fact'), null, 'attr');
$keysF = array_column($viewF['findings'], 'key');
eq([[$AF !== null, $KF !== null, $GF !== null, $APF !== null, $recF[0], $docGone],
    array_values(array_intersect(['conflict_open', 'task_open', 'req_open', 'doc_gone', 'alt_no_conclusion', 'small_sample', 'source_unchecked'], $kindsF)),
    $propF['registered_owner']['value']['text'] ?? null, $propF['encumbrance']['value']['text'] ?? null, count($keysF) === count(array_unique($keysF))],
    [[true, true, true, true, 200, ''], ['conflict_open', 'task_open', 'req_open', 'doc_gone', 'alt_no_conclusion', 'small_sample', 'source_unchecked'],
        'פלוני בן פלוני; אלמונית בת פלוני', 'משכנתה מדרגה ראשונה', true],
    '🟡7: סתירה פתוחה, משימה פתוחה, דרישת חובה פתוחה, מסמך שנמחק, חלופה בלי מסקנה, מדגם קטן ומקור שלא נמצא — כולם עולים בבקרה; מאפיין רב-ערכים (בעלים רשומים, הערות ושעבודים) נכנס לפרק הזכויות עם כל ערכיו; אין שני ממצאים באותו מפתח');

// staleness משינוי שיוך / משקל של עסקה. שיוך: שורות הטבלה "עסקאות השוואה שנבחרו" משתנות ← הפרק (שלא נערך) נבנה מחדש ומסומן "עודכן".
// משקל: **אינו מוצג בדוח** (לא בטבלת העסקאות, ותוצאת הגישה היא המסקנה שהשמאי הקליד לקבוצה) — ולכן שינוי משקל אינו משנה דבר בדוח, ואינו מדליק דבר
$compsRow = fn() => apr_row("SELECT s.* FROM report_sections s JOIN report_versions v ON v.id = s.version_id WHERE v.pid = ? AND s.key = 'comps'", [$VF]);
$rowsOf = function () use ($ver, $VF, $sec) { foreach ($sec($ver($VF)[1], 'comps')['blocks'] as $b) if ($b['type'] === 'data_table' && $b['table'] === 'comps') return count($b['rows']); return null; };
$n0 = $rowsOf();
$m0 = $compsRow();
$wF = $post('comp-weight', ['set' => $GF, 'comp' => $KF2, 'rev' => $gfrev(), 'weight' => '3']);
$n1 = $rowsOf();
$m1 = $compsRow();
$mF = $post('comp-set-member', ['set' => $GF, 'comp' => $KF2, 'rev' => $gfrev(), 'member' => false, 'reason' => 'אינה דומה']);
$n2 = $rowsOf();
$m2 = $compsRow();
eq([$wF[0], $n0, $n1, $m1['auto_hash'] === $m0['auto_hash'], $mF[0], $n2, $m2['auto_hash'] !== $m1['auto_hash'], $m2['changed_note'] !== null, (int)$m2['rev'] > (int)$m1['rev']], [200, 2, 2, true, 200, 1, true, true, true],
    'staleness: הוצאת עסקה מקבוצה (comp-set-member) משנה את טבלת העסקאות בדוח, והפרק נבנה מחדש ומסומן "עודכן"; שינוי משקל (comp-weight) אינו משנה את הדוח — המשקל אינו מוצג בו');

// 🟡1: שני "לא ידוע" זהים באותו פרק הם ממצא אחד — והאישור אינו נופל על UNIQUE; 🟡9: מסקנה עם אגורות מוצגת כפי שהמנוע מחזיר אותה
[$CU, $VU] = $ready();
$members($CU);
$AU = $altOf($CU);
$concU = $post('alt-conclude', ['alt' => $AU, 'rev' => (int)apr_val('SELECT rev FROM alternatives WHERE pid = ?', [$AU]), 'value' => '2100000.55', 'reason' => 'שיקול דעת']);
$cuS = $sec($ver($VU)[1], 'conclusion');
$save($cuS['pid'], [['type' => 'p', 'text' => 'מסקנה'], ['type' => 'note_missing', 'what' => 'נתון חסר'], ['type' => 'note_missing', 'what' => 'נתון חסר'], ['type' => 'data_table', 'table' => 'alternatives']]);
$dupKeys = array_count_values(array_column($ver($VU)[1]['findings'], 'key'));
$altCell = null;
foreach ($sec($ver($VU)[1], 'conclusion')['blocks'] as $b) if ($b['type'] === 'data_table') $altCell = $b['rows'][0][3] ?? null;
// 🟡2: עובדת משרד שקיבלה approve_report אינה מאשרת — רק שמאי בתיק
call('POST', 'case-member-update', ['case' => $CU, 'user' => $talPid, 'perms' => ['view' => true, 'edit' => true, 'approve_report' => true]], $avi);
$submit($VU);
$talApprove = code($approve($VU, $acksAll($VU), $tal));
$talPerm = $ver($VU, $tal)[1]['perms']['approve'] ?? null;
// 🟡5: נתון שמגיע בזמן "בבדיקה" — הפרקים קפואים; הבקרה חוסמת עד החזרה לעריכה
$fact($CU, 'rooms', '5');
$inRev = $blocking($VU);
$apprNew = code($approve($VU, $acksAll($VU)));
$post('report-return', ['version' => $VU, 'rev' => $vvrev($VU)]);
$afterReturn = $blocking($VU);
$rejectAll($VU);
$fill($VU);
$submit($VU);
$okU = $approve($VU, $acksAll($VU));
// 🟡8: הערה על גרסה שאושרה אינה נסגרת
$cmU = apr_insert('report_comments', ['pid' => $cmPid = apr_pid(), 'version_id' => (int)apr_val('SELECT id FROM report_versions WHERE pid = ?', [$VU]), 'text' => 'הערה פתוחה', 'created_by' => 1, 'created_at' => apr_now()]);
$resU = code($post('report-comment-resolve', ['comment' => $cmPid]));
eq([$concU[0], max($dupKeys), $altCell, $talApprove, $talPerm, in_array('data_new', $inRev, true), $apprNew, in_array('data_new', $afterReturn, true), $okU[0], $resU, apr_val('SELECT resolved_at FROM report_comments WHERE id = ?', [$cmU])],
    [200, 1, '2100000.55', 'forbidden', false, true, 'report_blocked', false, 200, 'report_approved', null],
    '🟡1: ממצא אחד למפתח, והאישור עובר; 🟡9: מסקנה 2100000.55 מוצגת כך; 🟡2: approve_report בלי תפקיד שמאי אינו מאשר; 🟡5: נתון בזמן "בבדיקה" ← ממצא חוסם data_new, ואחרי החזרה — רענון; 🟡8: הערה על גרסה שאושרה אינה נסגרת');

// 🟡5: "גרסה חדשה" אינה מסתירה נתון שהגיע אחרי האישור — פרק שלא נערך מתעדכן ברענון הראשון
$fact($CU, 'floors_total', '8');
$nvU = $post('report-version-new', ['case' => $CU, 'reason' => 'נתון נוסף']);
$adU = $sec($ver($nvU[1]['version'] ?? '')[1] ?? ['sections' => []], 'area_desc');
// 🟡6: מה ששותף ממשרד אחר מקבל — בלי נימוק הפתיחה, ובלי מזהים פנימיים וסימוני עריכה
$verRowU = apr_row('SELECT * FROM report_versions WHERE pid = ?', [$nvU[1]['version'] ?? '']) + ['created_name' => 'א', 'submitted_name' => null, 'approved_name' => null];
$headP = apr_report_version_head($verRowU, false);
$secP = apr_report_partner_section(['pid' => 'p', 'key' => 'k', 'title' => 't', 'mandatory' => true, 'build' => 'data', 'origin' => 'ai', 'state' => 'drafted', 'ai_reviewed' => true, 'rev' => 4, 'sources' => [['type' => 'fact', 'pid' => 'x']],
    'blocks' => [['type' => 'p', 'text' => 'ט', 'ai' => true, 'src' => ['type' => 'fact', 'pid' => 'x'], 'num_unknown' => ['7']], ['type' => 'fact', 'attr' => 'area', 'label' => 'שטח', 'value' => ['text' => '85'], 'fact' => 'fpid', 'was' => '80', 'changed' => true],
        ['type' => 'data_table', 'table' => 'comps', 'cols' => ['א'], 'rows' => [['1']], 'refs' => [['type' => 'comp', 'pid' => 'c']]]]]);
eq([$nvU[0], in_array('floors_total', array_column(array_filter($adU['proposal']['blocks'] ?? [], fn($b) => $b['type'] === 'fact'), 'attr'), true),
    array_key_exists('reopen_reason', $headP), array_key_exists('rev', $headP), apr_report_version_head($verRowU)['reopen_reason'], array_keys($secP), array_map('array_keys', $secP['blocks'])],
    [200, true, false, false, 'נתון נוסף', ['pid', 'key', 'title', 'mandatory', 'blocks'], [['type', 'text'], ['type', 'attr', 'label', 'value'], ['type', 'table', 'cols', 'rows']]],
    '🟡5: בגרסה חדשה, נתון שהגיע אחרי האישור אינו נבלע — לפרק שנערך הוא מגיע כהצעת עדכון; 🟡6: שותף אינו מקבל את reopen_reason / rev, ולא origin / ai_reviewed / src / fact / refs / was / changed / ai / num_unknown');

// ═══ סבב 3 — B1: טקסט חופשי שאדם הקליד אינו מגיע לספק הבינה. הבדיקה נגזרת מהסכימה, לא מרשימה של שדות ═══
// סמן ייחודי (אותיות בלבד, 10 תווים — שורד גם substr של תאריך) לכל מאפיין ולכל עמודה. סמן ב-payload = דליפה.
$mk = fn(string $name) => 'ZQ' . strtr(substr(md5($name), 0, 6), '0123456789', 'ghijklmnop') . 'QZ';
$anyMark = fn(mixed $x) => preg_match('/ZQ[a-p]{6}QZ/', (string)json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === 1;
$nobody = ['user' => null, 'office' => null];
$CT = make_case($avi, $PM);
$ctId = $caseId($CT);
$members($CT);
// נתון לכל מאפיין שבמערכת, לפי הסוג שלו: מאפיין מסוג טקסט (וכל סוג שיתווסף) מקבל סמן; השאר — ערך מובנה. בלי רשימה של מפתחות
$attrMarks = [];
$factFail = [];
foreach (apr_attrs_all() as $a) {
    if ($a['status'] !== 'active') continue;
    $value = match ($a['value_type']) { 'number', 'money' => '7', 'bool' => true, 'date' => '2026-01-05', 'choice' => (json_decode((string)$a['choices'], true) ?: [])[0]['key'] ?? null, default => null };
    if ($value === null) $value = ($attrMarks[$a['key']] = $mk('attr.' . $a['key'])) . ' — טקסט שאדם הקליד: בעלים אברהם אבינו';
    $more = (APR_UNITS[$a['unit_kind']] ? ['unit' => array_key_first(APR_UNITS[$a['unit_kind']])] : []) + ((int)$a['has_area_kind'] ? ['area_kind' => 'registered'] : []);
    if ($post('fact-add', ['case' => $CT, 'attribute' => $a['key'], 'value' => $value, 'source' => ['kind' => 'user', 'note' => 'בדיקה']] + $more)[0] !== 200) $factFail[] = $a['key'];
}
// וכל מקום אחר שבו אדם מקליד טקסט שארבעת הפרקים מציגים: מזהה "אחר" / תיק בניין, ממצא של בדיקת מקור, כיתוב של תמונה
$apiMarks = ['other' => $mk('api.ident.other'), 'file' => $mk('api.ident.file'), 'summary' => $mk('api.check.summary'), 'caption' => $mk('api.photo.caption')];
$setupT = [call('POST', 'case-identifier-add', ['case' => $CT, 'kind' => 'address', 'street' => 'הרצל', 'house_no' => '5', 'apartment_no' => '3', 'locality' => 'תל אביב', 'municipality' => 'תל אביב-יפו', 'note' => $mk('api.ident.note')], $avi)[0],
    call('POST', 'case-identifier-add', ['case' => $CT, 'kind' => 'other', 'value_text' => $apiMarks['other'] . ' הדירה של משפחת זילברמן'], $avi)[0],
    call('POST', 'case-identifier-add', ['case' => $CT, 'kind' => 'building_file', 'value_text' => $apiMarks['file']], $avi)[0],
    call('POST', 'source-check-record', ['case' => $CT, 'source' => $srcKey, 'status' => 'found', 'result_summary' => $apiMarks['summary'] . ' נסח טאבו: בעלים אברהם אבינו, הערת אזהרה לטובת שרה אמנו',
        'coverage_note' => $mk('api.check.coverage'), 'query_text' => $mk('api.check.query'), 'limitation' => $mk('api.check.limitation')], $avi)[0]];
[$VTv] = $visit($CT);
$fidT = apr_insert('files', ['pid' => apr_pid(), 'office_id' => apr_val('SELECT office_id FROM cases WHERE pid = ?', [$CT]), 'case_id' => $ctId, 'sha256' => hash('sha256', 't'), 'size' => 1, 'mime' => 'image/jpeg', 'ext' => 'jpg',
    'original_name' => 't.jpg', 'storage_key' => 'k' . bin2hex(random_bytes(4)), 'uploader_kind' => 'user', 'uploader_id' => 1, 'channel' => 'internal', 'received_at' => apr_now(), 'scan_state' => 'clean']);
apr_insert('visit_photos', ['pid' => apr_pid(), 'visit_id' => apr_val('SELECT id FROM visits WHERE pid = ?', [$VTv]), 'file_id' => $fidT, 'field_key' => 'x', 'row_no' => 0, 'sort' => 1, 'usage' => 'report', 'for_report' => 1, 'rights_by' => 1, 'rights_at' => apr_now(),
    'caption' => $apiMarks['caption'] . ' חזית הבית של משפחת כהן', 'created_by' => 1, 'created_at' => apr_now(), 'updated_at' => apr_now()]);
$ctRow = fn() => apr_row('SELECT * FROM cases WHERE id = ?', [$ctId]) + ['perms' => [], 'my_role' => 'system', 'share' => null];
/** מה שארבעת הפרקים **מציגים** בדוח (כולל הכותרת שנשמרה), ומה ש**נשלח** עליהם לבינה — מהמסד כפי שהוא ברגע הקריאה. */
$stateT = function () use ($ctId, $ctRow, $nobody) {
    $c = $ctRow();
    $v = apr_row('SELECT * FROM report_versions WHERE case_id = ? ORDER BY id DESC LIMIT 1', [$ctId]);
    $tpl = $v ? apr_report_tpl($v) : apr_report_profile($c);
    $view = [];
    $msgs = [];
    foreach (APR_REPORT_AI_KEYS as $key) {
        $s = ($v ? apr_row('SELECT * FROM report_sections WHERE version_id = ? AND key = ?', [$v['id'], $key]) : null) ?? ['key' => $key, 'title' => ''];
        $view[] = [$s['title'], apr_report_resolve($nobody, $c, apr_report_build($c, $key, $tpl)['blocks'], $tpl)];
        $msgs[] = apr_report_ai_input($nobody, $c, $s, $tpl)['message'];
    }
    return [(string)json_encode($view, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $msgs];
};
/** כל בקשה שהמתאם קיבל היא בדיוק: מודל, תקרת פלט, הנחיית המערכת הקבועה, וחלק טקסט אחד — אחת מההודעות ש-apr_report_ai_input הרכיב. שום שדה אחר. */
$shapeOk = function (array $calls, array $msgs): bool {
    foreach ($calls as $r) {
        if (array_keys($r) !== ['model', 'max_tokens', 'system', 'parts'] || !is_string($r['model']) || $r['max_tokens'] !== APR_REPORT_AI_MAX_OUTPUT || $r['system'] !== APR_REPORT_AI_SYSTEM
            || count($r['parts']) !== 1 || array_keys($r['parts'][0]) !== ['type', 'text'] || $r['parts'][0]['type'] !== 'text' || !in_array($r['parts'][0]['text'], $msgs, true)) return false;
    }
    return true;
};
/** שלושת המסלולים אל הספק: "הכול" ($all — ai_all), "פרק בודד" (report-section-ai), וניסוח שנשמר כהצעה (הפרק נערך בזמן הקריאה). מחזיר את הבקשות שהמתאם קיבל. */
$VT = null;
$pathsT = function (callable $all) use ($fake, $echo, $ver, $sec, $post, $save, $dana, &$VT) {
    $out = [];
    $fake->next = $echo;
    $fake->calls = [];
    $all();
    $out['all'] = $fake->calls;
    $fake->calls = [];
    foreach ($ver($VT)[1]['sections'] as $s) if (in_array($s['key'], APR_REPORT_AI_KEYS, true)) $post('report-section-ai', ['section' => $s['pid'], 'rev' => $s['rev'], 'replace' => true], $dana);
    $out['single'] = $fake->calls;
    $fake->calls = [];
    $sp = $sec($ver($VT)[1], 'planning');
    $fake->next = function (array $req) use ($echo, $save, $sp) { $save($sp['pid'], [['type' => 'p', 'text' => 'עריכה ידנית בזמן הניסוח ' . bin2hex(random_bytes(3))]]); return $echo($req); };
    $out['as_proposal'] = $post('report-section-ai', ['section' => $sp['pid'], 'rev' => $sp['rev'], 'replace' => true], $dana)[1]['as_proposal'] ?? null;
    $out['proposal'] = $fake->calls;
    $fake->next = $echo;
    $fake->calls = [];
    return $out;
};
$allCalls = fn(array $p) => [...$p['all'], ...$p['single'], ...$p['proposal']];

// שלב א — דרך ה-API, כמו משתמש: כל מאפיין טקסט נושא סמן. שלושת המסלולים; שום סמן אינו ב-payload, והנתונים עצמם נשארים בפרקים (כהפניה)
[, $msgsA] = $stateT();
$pA = $pathsT(function () use ($post, $CT, $versions, &$VT) { $post('report-draft-create', ['case' => $CT]); $VT = $versions($CT)[0]['pid'] ?? null; });
$viewA = (string)json_encode([$ver($VT)[1]['sections'] ?? null, apr_rows('SELECT p.content FROM section_proposals p JOIN report_sections s ON s.id = p.section_id JOIN report_versions v ON v.id = s.version_id WHERE v.pid = ?', [$VT])], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$viewBuilt = $stateT()[0];
eq([$factFail, $setupT, count($attrMarks) >= 5, [count($pA['all']), count($pA['single']), count($pA['proposal']), $pA['as_proposal']], $shapeOk($allCalls($pA), $msgsA), $anyMark($pA),
    array_keys(array_filter($attrMarks, fn($m) => !str_contains($viewBuilt, $m))), array_keys(array_filter($apiMarks, fn($m) => !str_contains($viewBuilt, $m))),
    str_contains(implode("\n", $msgsA), 'שטח: 7 מ"ר'), str_contains(implode("\n", $msgsA), 'הרצל 5, דירה 3, תל אביב'), str_contains(implode("\n", $msgsA), 'מצב: נמצא'), str_contains(implode("\n", $msgsA), 'סוג: אחר')],
    [[], [200, 200, 200, 200], true, [4, 4, 1, true], true, false, [], [], true, true, true, false],
    'B1 (סבב 3), דרך ה-API: נתון עם סמן **לכל מאפיין טקסט שבמערכת** (נגזר מ-apr_attrs_all — מאפיין שיתווסף ייבדק לבד), ממצא של בדיקת מקור, מזהה "אחר", תיק בניין וכיתוב תמונה — בשלושת המסלולים (הכול / פרק בודד / ניסוח שנשמר כהצעה) אף סמן אינו ב-payload של המתאם; כל בקשה היא בדיוק ההודעה שהורכבה ותו לא; כל הטקסטים מוצגים בפרקים עצמם; הנתונים המובנים (מספר, בחירה, כתובת, מצב ומועד של בדיקה) נשלחים');

// שלב ב — סריקת הסכימה: לכל עמודה שאינה מספרית, בכל טבלה שבמסד, שותלים סמן (בטרנזקציה שמתבטלת, בלי הטריגרים של הטבלה) ובודקים אם הוא
// **מוצג** בארבעת הפרקים ואם הוא **נשלח**. עמודה שתתווסף למסד נבדקת בלי לגעת בבדיקה; ומה שנשלח חייב להיות ברשימת העמודות המובנות שלמטה.
$noTriggers = function (string $t, callable $fn) {
    $trg = apr_rows("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name = ?", [$t]);
    foreach ($trg as $g) apr_db()->exec('DROP TRIGGER "' . $g['name'] . '"');
    try { return $fn(); } finally { foreach ($trg as $g) apr_db()->exec($g['sql']); }
};
$plant = function (string $t, string $col, string $where = null) use ($mk): int {       // כל השורות; אם המסד מסרב (CHECK בין עמודות) — רק שורות שכבר יש בהן ערך
    foreach ($where !== null ? [$where] : ['', " WHERE \"$col\" IS NOT NULL"] as $w) {
        try { return apr_exec("UPDATE \"$t\" SET \"$col\" = ? || rowid$w", [$mk("$t.$col")]); } catch (PDOException) {}
    }
    return 0;
};
$triggersBefore = (int)apr_val("SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger'");
$base = $stateT();
$sweep = ['tables' => 0, 'relevant' => 0, 'planted' => 0, 'shown' => [], 'sent' => [], 'errors' => []];
foreach (array_column(apr_rows("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"), 'name') as $t) {
    $cols = array_column(array_filter(apr_rows("PRAGMA table_info(\"$t\")"), fn($col) => preg_match('/INT|REAL|FLOA|DOUB|NUM|BLOB|BOOL/i', (string)$col['type']) !== 1), 'name');
    if (!$cols || !apr_val("SELECT 1 FROM \"$t\" LIMIT 1")) continue;
    $sweep['tables']++;
    try {
        apr_tx(function () use ($t, $cols, $mk, $plant, $stateT, $noTriggers, $base, &$sweep) {
            $noTriggers($t, function () use ($t, $cols, $mk, $plant, $stateT, $base, &$sweep) {
                $try = function (callable $do) use ($stateT) {
                    apr_db()->exec('SAVEPOINT sweep');
                    try { return $do() > 0 ? $stateT() : null; } catch (Throwable $e) { return $e; } finally { apr_db()->exec('ROLLBACK TO sweep'); apr_db()->exec('RELEASE sweep'); }
                };
                // קודם כל העמודות של הטבלה יחד: שום דבר לא זז — הטבלה אינה נוגעת לארבעת הפרקים, ואין מה לבדוק בה עמודה-עמודה
                $allAtOnce = $try(function () use ($t, $cols, $plant) { $n = 0; foreach ($cols as $col) $n += $plant($t, $col); return $n; });
                if ($allAtOnce === null || $allAtOnce === $base) return;
                $sweep['relevant']++;
                foreach ($cols as $col) {
                    $s = $try(fn() => $plant($t, $col));
                    if ($s === null) continue;
                    $sweep['planted']++;
                    if ($s instanceof Throwable) { $sweep['errors'][] = "$t.$col: " . get_class($s) . ' ' . mb_substr($s->getMessage(), 0, 80); continue; }
                    if (str_contains($s[0], $mk("$t.$col"))) $sweep['shown'][] = "$t.$col";
                    if (str_contains(implode("\n", $s[1]), $mk("$t.$col"))) $sweep['sent'][] = "$t.$col";
                }
            });
            throw new SweepRollback();
        });
    } catch (SweepRollback) {}
}
// העמודות ש**מותר** להן להגיע לספק — מובנות בלבד (הכרעת הבקרה, סבב 3). עמודה אחרת שתגיע ל-payload מכשילה את הבדיקה, עד שתיבדק ותתווסף לכאן במודע
$STRUCTURED = ['attributes.label',                                                                                              // שם המאפיין — מהקטלוג של המשרד
    'case_identifiers.apartment_no', 'case_identifiers.gush', 'case_identifiers.helka', 'case_identifiers.house_no', 'case_identifiers.locality', 'case_identifiers.street', 'case_identifiers.tat_helka',     // כתובת מובנית, גוש-חלקה
    'source_checks.performed_at', 'sources.name', 'visits.completed_at'];                                                       // מועד בדיקה, שם המקור מהקטלוג, מועד הביקור
$sortedOf = function (array $a) { sort($a); return $a; };
$freeCols = array_values(array_diff($sweep['shown'], $sweep['sent']));
// עמודות טקסט חופשי שהפרקים מציגים — חייבות להימצא בסריקה (אחרת היא ריקה מתוכן), ואסור שיישלחו
$mustFree = ['case_identifiers.value_text', 'facts.value_text', 'facts.value_date', 'facts.unit', 'report_sections.title', 'source_checks.result_summary', 'users.name', 'visit_photos.caption'];
eq([$sweep['errors'], $sortedOf($sweep['sent']), array_values(array_diff($mustFree, $freeCols)), $sweep['tables'] >= 40, $sweep['relevant'] >= 8, $sweep['planted'] >= 60, $stateT() === $base,
    (int)apr_val("SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger'") === $triggersBefore, !empty($GLOBALS['apr_in_tx'])],
    [[], $sortedOf($STRUCTURED), [], true, true, true, true, true, false],
    'B1 (סבב 3), סריקת הסכימה: סמן בכל עמודה לא-מספרית של כל טבלה במסד — לספק מגיעות **רק** העמודות המובנות שברשימה (שם מאפיין ושם מקור מהקטלוג, כתובת מובנית וגוש-חלקה, מועד ביקור ומועד בדיקה); ערך טקסט / תאריך / יחידה של נתון שאינם מובנים, ממצא של בדיקת מקור, טקסט של מזהה, כותרת שנשמרה בפרק, שם המבקר וכיתוב תמונה — מוצגים בדוח ואינם נשלחים; הסריקה אינה משאירה דבר במסד');

// שלב ג — כל העמודות החופשיות שהסריקה מצאה, שתולות **יחד**, ושלושת המסלולים עד המתאם (בטרנזקציה שמתבטלת)
$pB = null;
$stateB = null;
try {
    apr_tx(function () use ($freeCols, $noTriggers, $plant, $stateT, $pathsT, $post, $ctRow, &$VT, &$pB, &$stateB) {
        foreach ($freeCols as $tc) { [$t, $col] = explode('.', $tc, 2); $noTriggers($t, fn() => $plant($t, $col, " WHERE \"$col\" IS NOT NULL")); }
        $stateB = $stateT();
        $pB = $pathsT(function () use ($post, $ctRow, &$VT) {
            $post('report-rebuild', ['version' => $VT, 'include_edited' => true]);
            apr_report_ai_all(null, $ctRow(), apr_row('SELECT * FROM report_versions WHERE pid = ?', [$VT]));
        });
        throw new SweepRollback();
    });
} catch (SweepRollback) {}
eq([[count($pB['all'] ?? []), count($pB['single'] ?? []), count($pB['proposal'] ?? []), $pB['as_proposal'] ?? null], $shapeOk($allCalls($pB), $stateB[1]), $anyMark($pB),
    array_values(array_filter($mustFree, fn($tc) => !str_contains($stateB[0], $mk($tc)))), str_contains(implode("\n", $stateB[1]), 'הרצל 5, דירה 3, תל אביב'), str_contains(implode("\n", $stateB[1]), 'קומה: 7'),
    apr_val("SELECT name FROM users WHERE email = 'avi@example.com'"), $stateT() === $base, !empty($GLOBALS['apr_in_tx'])],
    [[4, 4, 1, true], true, false, [], true, true, 'אבי', true, false],
    'B1 (סבב 3), כל עמודות הטקסט החופשי שהפרקים מציגים שתולות יחד: בשלושת המסלולים (הכול / פרק בודד / ניסוח שנשמר כהצעה) אף סמן אינו ב-payload של המתאם, כל בקשה היא בדיוק ההודעה שהורכבה, והסמנים כולם מוצגים בדוח; הנתונים המובנים ממשיכים להישלח; אחרי הבדיקה המסד כפי שהיה');
$fake->next = $echo;

// 🟡7: הסרת פרק דורשת שמאי בתיק; תמונה של תיק אחר אינה נכנסת לבלוק; תשובת בינה קטועה (stop_reason) נדחית; הגבלת הקצב נאכפת לפני השער
$prR = $sec($ver($VR)[1], 'property');
$talDel = code($post('report-section-delete', ['section' => $prR['pid'], 'rev' => $prR['rev']], $tal));
$alienPhoto = code($save($prR['pid'], [['type' => 'image', 'photo' => $PH]]));                       // $PH שייכת לתיק אחר ($CK)
$fake->next = fn() => reply(['blocks' => [['text' => 'תשובה שנקטעה באמצע', 'src' => 'd1']]], 'max_tokens');
$cut = $post('report-section-ai', ['section' => $prR['pid'], 'rev' => $prR['rev']]);
$prR2 = $sec($ver($VR)[1], 'property');
$fake->next = $echo;
$rate = null;
$callsAtLimit = null;
for ($i = 0; $i < APR_REPORT_RATE_AI[0] + 2 && $rate === null; $i++) {
    $x = $sec($ver($VR)[1], 'property');
    $before = count($fake->calls);
    $r = $post('report-section-ai', ['section' => $x['pid'], 'rev' => $x['rev'], 'replace' => true]);
    if (code($r) === 'ai_rate') { $rate = $r[0]; $callsAtLimit = count($fake->calls) - $before; }
}
eq([$talDel, $alienPhoto, $cut[1]['state'] ?? null, $cut[1]['reason'] ?? null, $prR2['origin'], $prR2['ai_wait'], $rate, $callsAtLimit],
    ['forbidden', 'report_block', 'wait', 'failed', 'auto', 'failed', 429, 0],
    '🟡7: עובדת משרד (edit, לא שמאית) אינה מסירה פרק; תמונה של תיק אחר נדחית; תשובה שנקטעה (stop_reason ≠ end_turn) אינה נכתבת — הפרק "ממתין"; מעבר למגבלת הקצב ← 429, בלי קריאה לספק');

eq($warnings, [], 'אין אזהרות PHP לאורך כל הבדיקות');
done();
