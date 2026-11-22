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
    return reply(['blocks' => array_map(fn($id) => ['text' => 'ניסוח של הפריט ' . $id . ' בלי מספרים נוספים', 'src' => $id], array_slice(array_values(array_unique($m[1])), 0, 3))]);
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

// ═══ SMOKE ═══
$P = make_profile($avi);
$C = make_case($avi, $P);
[$V, $fin] = $visit($C);
fwrite(STDERR, json_encode([$fin[0], $fin[1]['error'] ?? null, apr_rows("SELECT kind, status, dedupe_key FROM jobs WHERE kind = 'report.draft'")], JSON_UNESCAPED_UNICODE) . "\n");
apr_jobs_run(5.0);
fwrite(STDERR, json_encode([apr_rows("SELECT kind, status, last_error FROM jobs WHERE kind = 'report.draft'"), array_map(fn($v) => [$v['number'], $v['state'], $v['origin']], $versions($C))], JSON_UNESCAPED_UNICODE) . "\n");
$v1 = $versions($C)[0]['pid'] ?? null;
$view = $ver((string)$v1);
fwrite(STDERR, json_encode([$view[0], $view[1]['error'] ?? null, array_map(fn($s) => [$s['key'], $s['state'], $s['origin'], $s['ai_wait'], $types($s['blocks'])], $view[1]['sections'] ?? []), array_column($view[1]['findings'] ?? [], 'level', 'key')], JSON_UNESCAPED_UNICODE) . "\n");

eq($warnings, [], 'אין אזהרות PHP לאורך כל הבדיקות');
done();
