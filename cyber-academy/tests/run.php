<?php
/**
 * הבדיקות של אקדמיית סייבר — php cyber-academy/tests/run.php
 * בלי תלויות. יוצא בקוד 1 אם בדיקה אחת נכשלה.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../lib/api.php';

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; return; }
    $fail++;
    fwrite(STDERR, "✗ $name" . ($detail !== '' ? " — $detail" : '') . "\n");
}
function fixture(string $rel): mixed {
    return json_decode((string) file_get_contents(__DIR__ . '/fixtures/' . $rel), true);
}

/** מקור מוזרק — מחזיר נתון קבוע. */
final class FixedSource implements AcademySource {
    public function __construct(private mixed $data, private string $n = 'local', private bool $sample = true) {}
    public function name(): string { return $this->n; }
    public function isSample(): bool { return $this->sample; }
    public function fetch(): mixed { return $this->data; }
    public function info(): array { return ['commit' => null, 'collectedAt' => null]; }
}

// ---------- אימות מול החוזה ----------
$valid = fixture('valid-v1.json');
check('fixture תקין עובר', academy_validate($valid) === [], implode(' | ', academy_validate($valid)));
check('תבנית ריקה עוברת', academy_validate(fixture('empty-template.json')) === []);
check('נתוני הדוגמה עוברים', academy_validate(json_decode((string) file_get_contents(__DIR__ . '/../samples/demo-snapshot.json'), true)) === []);
check('תבנית הדוגמה הריקה עוברת', academy_validate(json_decode((string) file_get_contents(__DIR__ . '/../samples/empty-snapshot.json'), true)) === []);

$broken = glob(__DIR__ . '/fixtures/broken/*.json');
check('יש fixtures שבורים', count($broken) >= 15);
foreach ($broken as $f) {
    $errs = academy_validate(json_decode((string) file_get_contents($f), true));
    check('שבור נכשל: ' . basename($f), $errs !== []);
    foreach ($errs as $e) check('הודעה עם נתיב JSON: ' . basename($f), str_starts_with($e, '$'), $e);
}
check('null נכשל', academy_validate(null) !== []);
check('מחרוזת נכשלת', academy_validate('x') !== []);
check('הודעה ברורה לשדה זר', in_array('$.competencies[0]: שדה "score" אינו קיים בחוזה v1',
    academy_validate(fixture('broken/extra-competency-property.json')), true));
check('date-time עם אזור זמן עובר', academy_is_datetime('2026-10-01T09:00:00+03:00'));
check('date-time בלי אזור זמן נכשל', !academy_is_datetime('2026-10-01T09:00:00'));
check('תאריך לא קיים נכשל', !academy_is_datetime('2026-02-30T09:00:00Z'));

// ---------- המודל ----------
$m = academy_model($valid);
check('מסלולים לפי order', array_column($m['tracks'], 'id') === ['t-c', 't-a', 't-b']);
check('נלמד ≠ הוכח — ספירה נפרדת', $m['measure']['learned'] === 2 && $m['measure']['proven'] === 2 && $m['measure']['total'] === 7,
    json_encode($m['measure']));
check('אין אחוז אחד במודל', !str_contains(json_encode($m), 'percent') && !str_contains(json_encode($m), 'pct'));
check('פערים = gap + reassessment', array_column($m['gaps'], 'id') === ['c-3', 'c-5']);
check('ספירת משימות לפי 4 מצבים', $m['taskCounts'] === ['planned' => 1, 'active' => 1, 'blocked' => 1, 'completed' => 1]);
$tb = array_values(array_filter($m['tracks'], fn($t) => $t['id'] === 't-b'))[0];
check('דרישת קדם לא הוכחה (t-a לא כולו מוכח)', $tb['prerequisites'][0]['met'] === false && !$tb['prerequisitesMet']);
check('כל ההמלצות מסומנות נגזר', $m['recommendations'] && !array_filter($m['recommendations'], fn($r) => $r['derived'] !== true));
check('המלצה להערכה מחדש קודמת', $m['recommendations'][0]['kind'] === 'reassess');
check('ציר זמן: updatedAt + lastAssessedAt, חדש קודם', array_column($m['timeline'], 'kind') === ['snapshot', 'assessment']);
$e = academy_model(fixture('empty-template.json'));
check('מודל ריק מסומן empty', $e['empty'] === true && $e['measure']['total'] === 0 && $e['recommendations'] === [] && $e['timeline'] === []);

// ---------- המקור: github כבוי כברירת מחדל ----------
check('קבוע השלב: github אסור בשלב הזה', ACADEMY_GITHUB_PHASE_ALLOWED === false);
$def = academy_default_config();
check('ברירת מחדל: github_enabled=false', $def['github_enabled'] === false && $def['privacy_confirmed'] === false);
check('ברירת מחדל: מקור local', academy_source($def) instanceof LocalAcademySource);
$on = ['source' => 'github', 'github_enabled' => true, 'privacy_confirmed' => true] + $def;
check('גם עם שני הדגלים דולקים — עדיין local בשלב הזה', academy_source($on) instanceof LocalAcademySource);
check('github_enabled בלי אישור פרטיות — לא מותר', !academy_github_allowed(['github_enabled' => true, 'privacy_confirmed' => false]));
check('"true" כמחרוזת אינו הדלקה', !academy_github_allowed(['github_enabled' => 'true', 'privacy_confirmed' => 'true']));
define('FAKE_TOKEN', 'github' . '_pat_' . 'SECRET' . str_repeat('x9', 12));
$tmp = sys_get_temp_dir() . '/ca-test-' . getmypid();
@mkdir($tmp);
file_put_contents("$tmp/config.json", json_encode(['source' => 'github', 'github_enabled' => true, 'privacy_confirmed' => true,
    'github' => ['token' => FAKE_TOKEN]]));
$cfg = academy_load_config("$tmp/config.json");
check('קונפיג נטען על גבי ברירת מחדל', $cfg['github']['repo'] === 'offensive-cyber-academy' && $cfg['github']['token'] !== '');
file_put_contents("$tmp/bad.json", '{not json');
check('קונפיג פגום → ברירת מחדל', @academy_load_config("$tmp/bad.json") === $def);
check('קונפיג חסר → ברירת מחדל', academy_load_config("$tmp/missing.json") === $def);
check('local מסומן דוגמה', (new LocalAcademySource())->isSample() === true && (new LocalAcademySource())->name() === 'local');

// ---------- האסימון לא יוצא ----------
$token = FAKE_TOKEN;
[$st, $body] = academy_api_snapshot($cfg);
$out = json_encode($body, JSON_UNESCAPED_UNICODE);
check('API: 200 עם נתוני דוגמה', $st === 200 && $body['ok'] === true);
check('API: source + isSample', $body['source'] === 'local' && $body['isSample'] === true);
check('API: האסימון לא בפלט', !str_contains($out, $token) && !str_contains($out, 'SECRETx9'));
check('API: אין שדה token בפלט', !str_contains($out, '"token"'));
[$st, $body] = academy_api_snapshot($cfg, ['demo' => 'empty']);
check('API: demo=empty → מודל ריק', $st === 200 && $body['model']['empty'] === true && $body['isSample'] === true);
[$st, $body] = academy_api_snapshot($def, [], new FixedSource(fixture('broken/bad-competency-status.json')));
check('API: snapshot שבור → 422 עם errors', $st === 422 && $body['ok'] === false && $body['errors'] !== [] && !isset($body['model']));

// מקור github עם transport מזויף — הודעות שגיאה ופלט בלי אסימון
const FAKE_SHA = '0123456789abcdef0123456789abcdef01234567';
/** stub ל-GitHub: commits/<ref> → sha; contents → $status/$body. רושם כל פנייה ב-$seen. */
function gh_stub(int $status, string $body, array &$seen = null, array $headers = [], int $commitStatus = 200): callable {
    $seen = [];
    return function (string $url, array $h) use ($status, $body, $headers, $commitStatus, &$seen) {
        $seen[] = ['url' => $url, 'headers' => $h];
        if (str_contains($url, '/commits/')) return ['status' => $commitStatus, 'body' => $commitStatus === 200 ? FAKE_SHA : $body, 'headers' => $headers];
        return ['status' => $status, 'body' => $body, 'headers' => $headers];
    };
}
$gh = ['owner' => 'malkiel01', 'repo' => 'offensive-cyber-academy', 'ref' => 'main', 'path' => 'dashboard/data/snapshot.json', 'token' => $token];
$seen = [];
$src = new GithubAcademySource($gh, "$tmp/cache", 0, gh_stub(200, json_encode($valid), $seen));
check('github: לא דוגמה', $src->isSample() === false && $src->name() === 'github');
check('github: קורא snapshot', $src->fetch() === $valid);
check('github: קודם ה-ref לקומיט', $seen[0]['url'] === 'https://api.github.com/repos/malkiel01/offensive-cyber-academy/commits/main');
check('github: הקובץ מאותו קומיט', $seen[1]['url'] === 'https://api.github.com/repos/malkiel01/offensive-cyber-academy/contents/dashboard/data/snapshot.json?ref=' . FAKE_SHA);
check('github: info עם קומיט ומועד', $src->info()['commit'] === FAKE_SHA && academy_is_datetime((string) $src->info()['collectedAt']));
foreach ($seen as $call) {
    check('github: האסימון רק בכותרת Authorization', in_array("Authorization: Bearer $token", $call['headers'], true) && !str_contains($call['url'], $token));
}
[$st, $body] = academy_api_snapshot($cfg, [], new GithubAcademySource($gh, "$tmp/cache", 0, gh_stub(200, json_encode($valid))));
check('API github: isSample=false + sourceInfo.commit', $st === 200 && $body['isSample'] === false && $body['source'] === 'github'
    && $body['sourceInfo']['commit'] === FAKE_SHA);

// stub לכל תשובת HTTP: code, הודעה, בלי אסימון
$leak = '{"message":"Bad credentials ' . $token . '"}';
$cases = [
    '401'            => [401, $leak, [], 'source_unauthorized', 502, 'לא תקף'],
    '403'            => [403, $leak, [], 'source_unauthorized', 502, 'הרשאת קריאה'],
    '403 rate limit' => [403, '{"message":"API rate limit exceeded"}', ['X-RateLimit-Remaining' => '0'], 'source_unavailable', 502, 'מכסת'],
    '429'            => [429, '', [], 'source_unavailable', 502, 'מכסת'],
    '404'            => [404, $leak, [], 'source_not_found', 502, 'לא נמצאו'],
    '500'            => [500, $leak, [], 'source_unavailable', 502, 'לא זמין'],
    '503'            => [503, '', [], 'source_unavailable', 502, 'לא זמין'],
    'JSON שבור'      => [200, '{"schemaVersion": "1.0.0", ' . $token, [], 'contract_invalid', 422, 'JSON'],
];
foreach ($cases as $name => [$code, $b, $hdr, $want, $wantHttp, $word]) {
    $src = new GithubAcademySource($gh, "$tmp/cache", 0, gh_stub($code, $b, $seen, $hdr));
    [$st, $body] = academy_api_snapshot($cfg, [], $src);
    $out = json_encode($body, JSON_UNESCAPED_UNICODE);
    check("github $name: code=$want", ($body['code'] ?? '') === $want, $out);
    check("github $name: HTTP $wantHttp", $st === $wantHttp, (string) $st);
    check("github $name: הודעה בעברית", str_contains($body['error'] ?? '', $word), $out);
    check("github $name: בלי אסימון", !str_contains($out, $token) && !str_contains($out, 'SECRETx9'));
    check("github $name: isSample=false", $body['isSample'] === false);
}
$src = new GithubAcademySource($gh, "$tmp/cache", 0, gh_stub(200, '{}', $seen, [], 401));
check('github: 401 כבר בשלב הקומיט', ($e = academy_api_snapshot($cfg, [], $src)[1])['code'] === 'source_unauthorized' && count($seen) === 1);
$src = new GithubAcademySource($gh, "$tmp/cache", 0, function () use ($token) { throw new RuntimeException("curl failed for $token"); });
[$st, $body] = academy_api_snapshot($cfg, [], $src);
check('github: תקלת רשת → source_unavailable בלי אסימון', $st === 502 && $body['code'] === 'source_unavailable'
    && !str_contains(json_encode($body, JSON_UNESCAPED_UNICODE), $token));
$src = new GithubAcademySource(['token' => ''] + $gh, "$tmp/cache", 0, gh_stub(200, '{}'));
try { $src->fetch(); check('github: בלי אסימון — נכשל', false); }
catch (AcademySourceError $e) { check('github: בלי אסימון — source_unauthorized', $e->errorCode === 'source_unauthorized'); }
$src = new GithubAcademySource(['path' => '../../etc/passwd'] + $gh, "$tmp/cache", 0, gh_stub(200, '{}'));
try { $src->fetch(); check('github: נתיב עם .. נדחה', false); } catch (AcademySourceError $e) { check('github: נתיב עם .. נדחה', true); }
// מטמון: פנייה שנייה לא יוצאת לרשת, והקומיט נשמר איתו
$calls = 0;
$counting = function (string $url) use (&$calls, $valid) { $calls++; return ['status' => 200, 'body' => str_contains($url, '/commits/') ? FAKE_SHA : json_encode($valid)]; };
$src = new GithubAcademySource($gh, "$tmp/cache", 60, $counting);
$src->fetch(); $src2 = new GithubAcademySource($gh, "$tmp/cache", 60, $counting); $src2->fetch();
check('github: מטמון קצר', $calls === 2, (string) $calls);
check('github: הקומיט נשמר במטמון', $src2->info()['commit'] === FAKE_SHA);
check('github: האסימון לא במטמון', !str_contains(implode('', array_map('file_get_contents', glob("$tmp/cache/*"))), $token));
check('redact: תבנית ghp_ מוסתרת גם בלי קונפיג', !str_contains(academy_redact('x ' . 'gh' . 'p_' . str_repeat('A', 24) . ' y', ''), 'ghp_'));

// ---------- מקור: בחירה רק מהשרת ----------
$ghOn = ['source' => 'github', 'github_enabled' => true, 'privacy_confirmed' => true] + $def;
check('מעבר למנעול: שני הדגלים + שלב → github', academy_source($ghOn, '', true) instanceof GithubAcademySource);
check('מעבר למנעול: demo=empty לא מחליף github לדוגמה', academy_source($ghOn, 'empty', true) instanceof GithubAcademySource);
foreach ([['source' => 'github'], ['isSample' => '0'], ['isSample' => 'false'], ['demo' => 'real'], ['demo' => 'empty', 'source' => 'github', 'sample' => '0']] as $q) {
    [$st, $body] = academy_api_snapshot($def, $q);
    check('פרמטר לא הופך דוגמה לאמת: ' . http_build_query($q), $st === 200 && $body['isSample'] === true && $body['source'] === 'local');
}
[$st, $body] = academy_api_snapshot(['source' => 'github', 'fallback_to_sample' => false] + $def);
check('github מבוקש, כבוי, בלי fallback → source_disabled', $st === 503 && $body['code'] === 'source_disabled' && !isset($body['model']));
[$st, $body] = academy_api_snapshot(['source' => 'github'] + $def);
check('github מבוקש, כבוי, עם fallback → דוגמה מסומנת', $st === 200 && $body['isSample'] === true && $body['source'] === 'local');
check('local: אין קומיט ב-sourceInfo', $body['sourceInfo']['commit'] === null);

// ---------- קודי כשל של החוזה ----------
$v2 = $valid; $v2['schemaVersion'] = '2.0.0';
[$st, $body] = academy_api_snapshot($def, [], new FixedSource($v2));
check('גרסת חוזה אחרת → contract_version (לא contract_invalid)', $st === 422 && $body['code'] === 'contract_version' && $body['receivedVersion'] === '2.0.0');
[$st, $body] = academy_api_snapshot($def, [], new FixedSource(fixture('broken/unknown-track-ref.json')));
check('הפניה שבורה → contract_invalid', $st === 422 && $body['code'] === 'contract_invalid' && $body['errorCount'] === 1);
[$st, $body] = academy_api_snapshot($def, [], new FixedSource(null));
check('null → contract_invalid', $st === 422 && $body['code'] === 'contract_invalid');

// ---------- API: קריאה בלבד ----------
foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $mth) {
    [$st, $body] = academy_api_handle($mth, $def);
    check("API מסרב ל-$mth", $st === 405 && $body['code'] === 'method_not_allowed' && !isset($body['model']));
}
check('API: GET עובד', academy_api_handle('GET', $def)[0] === 200);
check('API: HEAD עובד', academy_api_handle('head', $def)[0] === 200);

// ---------- מוצע מול מאושר ----------
check('v1: כל מסלול "proposed" ממקור not_in_contract_v1', !array_filter($m['tracks'], fn($t) =>
    $t['curriculumStatus'] !== 'proposed' || $t['curriculumStatusSource'] !== 'not_in_contract_v1'));
check('v1: גם הסילבוס כולו "proposed"', $m['curriculum'] === ['curriculumStatus' => 'proposed', 'curriculumStatusSource' => 'not_in_contract_v1']);
check('v1: approval לא נקרא גם אם מופיע', academy_curriculum_status(['approval' => 'approved'], '1.0.0')['curriculumStatus'] === 'proposed');
check('v1: approval נדחה באימות (לא בחוזה)', academy_validate(array_replace_recursive($valid, ['tracks' => [0 => ['approval' => 'approved']]])) !== []);
check('חוזה עתידי: approval נקרא כמו שהוא', academy_curriculum_status(['approval' => 'approved'], '2.0.0') === ['curriculumStatus' => 'approved', 'curriculumStatusSource' => 'contract']);
check('חוזה עתידי: ערך לא מוכר → proposed', academy_curriculum_status(['approval' => 'yes'], '2.0.0')['curriculumStatus'] === 'proposed');
$demoModel = academy_api_snapshot($def)[1]['model'];
check('דוגמה: אף מסלול לא "approved"', !array_filter($demoModel['tracks'], fn($t) => $t['curriculumStatus'] === 'approved'));

// ---------- נתונים חסרים ≠ אפס ----------
$cm = array_column($m['competencies'], null, 'id');
$tm = array_column($m['tracks'], null, 'id');
check('lastAssessedAt: null מדווח, חסר לא', $cm['c-2']['assessedReported'] === true && $cm['c-3']['assessedReported'] === false);
check('evidenceRefs: [] מדווח, חסר לא', $cm['c-5']['evidenceReported'] === true && $cm['c-3']['evidenceReported'] === false);
check('prerequisites: [] מדווח, חסר לא', $tm['t-c']['prerequisitesReported'] === true && $tm['t-a']['prerequisitesReported'] === false);

// ---------- אין אסימון אמיתי בקבצים ----------
$all = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root = realpath(__DIR__ . '/..'), FilesystemIterator::SKIP_DOTS));
$scanned = 0;
foreach ($all as $f) {
    if (!$f->isFile() || $f->getSize() > 2_000_000) continue;
    $scanned++;
    $txt = (string) file_get_contents($f->getPathname());
    check('אין אסימון GitHub: ' . substr($f->getPathname(), strlen($root) + 1),
        !preg_match('/(gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,})/', $txt));
}
check('נסרקו קבצים (כולל data/, lib/, tests/, samples/)', $scanned > 30);
$ex = json_decode((string) file_get_contents("$root/samples/config.example.json"), true);
check('config.example: אסימון ריק ושני הדגלים כבויים', $ex['github']['token'] === '' && $ex['github_enabled'] === false && $ex['privacy_confirmed'] === false);
check('index.html: פס הדוגמה גלוי כברירת מחדל (עד שהשרת אומר אחרת)', (bool) preg_match('/id="sample-bar"(?![^>]*hidden)/', (string) file_get_contents("$root/index.html")));

// ---------- הרשאות ----------
check('viewer קורא', academy_can('snapshot.read', 'viewer'));
check('viewer לא כותב', !academy_can('snapshot.write', 'viewer'));
check('תפקיד לא מוכר — כלום', !academy_can('snapshot.read', 'nobody'));
check('אין שום הרשאת כתיבה מוגדרת', !preg_grep('/write|edit|delete/', array_merge(...array_values(ACADEMY_ROLES))));

// ---------- גבולות הקוד ----------
$root = realpath(__DIR__ . '/..');
foreach (array_merge(glob("$root/lib/*.php"), glob("$root/api/*.php")) as $f) {
    $src = (string) file_get_contents($f);
    preg_match_all('/require(?:_once)?\s+([^;]+);/', $src, $mm);
    foreach ($mm[1] as $expr) check('require רק בתוך האפליקציה: ' . basename($f), str_starts_with(trim($expr), '__DIR__') && !preg_match("~__DIR__\s*\.\s*'/\.\./\.\.~", $expr), $expr);
    check('אין POST/PUT/DELETE ל-GitHub: ' . basename($f), !preg_match('/CURLOPT_(POST|PUT)\b|CUSTOMREQUEST/', $src));
}
foreach (glob("$root/assets/js/*.js") as $f) {
    $src = (string) file_get_contents($f);
    check('הדפדפן לא פונה ל-GitHub: ' . basename($f), !str_contains($src, 'api.github.com') && !str_contains($src, 'token'));
}
$idx = (string) file_get_contents("$root/index.html");
check('אין קישור חזרה לכלים', !preg_match('~href="\.\./|\.\./index\.html~', $idx));

array_map('unlink', glob("$tmp/cache/*") ?: []); @rmdir("$tmp/cache");
array_map('unlink', glob("$tmp/*") ?: []); @rmdir($tmp);

echo ($fail ? "✗" : "✓") . " $pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
