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
$save = fn(string $section, array $content, string $as = null, ?int $rev = null) => $post('report-section-save', ['section' => $section, 'rev' => $rev ?? (int)apr_val('SELECT rev FROM report_sections WHERE pid = ?', [$section]), 'content' => $content], $as);
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
eq([$origins($CAI), $perCall, array_filter($sent, fn($t) => str_contains($t, $titles['methods']) || str_contains($t, $titles['comps']) || str_contains($t, $titles['assume']) || str_contains($t, $titles['conclusion']) || (str_contains($t, 'משה לקוח') && !str_contains($t, $titles['purpose']))),
    [str_contains($areaCall, '85.5'), str_contains($areaCall, 'חדרים: 4'), str_contains($areaCall, '6158'), str_contains($areaCall, 'tools')], [$aiSec['origin'], $aiSec['ai_reviewed'], $aiSec['ai_wait'], $aiSec['user_edited']],
    array_unique(array_map(fn($b) => $b['ai'] ?? false, array_filter($aiSec['blocks'], fn($b) => $b['type'] === 'p'))), $aiSec['blocks'][0]['src'] ?? null,
    in_array('ai_unreviewed', $blocking($VAI), true), (int)apr_val("SELECT COUNT(*) FROM ai_runs WHERE purpose = 'report.draft' AND status = 'done'"), array_column(array_filter($va['sections'], fn($s) => $s['ai_allowed']), 'key')],
    [['purpose' => 'ai', 'property' => 'ai', 'area_desc' => 'ai', 'visit' => 'ai', 'planning' => 'ai', 'documents' => 'ai', 'methods' => 'auto', 'comps' => 'auto', 'assume' => 'auto', 'conclusion' => 'manual', 'appendix' => 'auto'],
        [['purpose'], ['property'], ['area_desc'], ['visit'], ['planning'], ['documents']], [], [true, true, false, false], ['ai', false, null, false], [true], ['type' => 'fact', 'pid' => $FA], true, 6, ['purpose', 'property', 'area_desc', 'visit', 'planning', 'documents', 'appendix']],
    '17-A7 הפרקים methods / comps / assume / conclusion אינם נשלחים למתאם גם כשהפרופיל מבקש בינה לכולם; 17-D2 כל בקשה נושאת את הנתונים של פרק אחד בלבד (בלי מזהי הנכס בפרק התיאור, שם המזמין רק בפרק שמציג אותו, בלי tools); פרק בלי נתונים אינו נשלח; הטקסט מסומן כבינה, לא נסקר, ומקושר לנתון');

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
$fake->next = $echo;

// ═══ NEXT ═══
eq($warnings, [], 'אין אזהרות PHP לאורך כל הבדיקות');
done();
