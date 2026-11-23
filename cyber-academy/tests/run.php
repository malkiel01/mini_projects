<?php
/**
 * הבדיקות של אקדמיית סייבר — php cyber-academy/tests/run.php
 * בלי תלויות. יוצא בקוד 1 אם בדיקה אחת נכשלה.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../lib/api.php';
require_once __DIR__ . '/../lib/progress-api.php';

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
check('מודל ריק מסומן empty', $e['empty'] === true && $e['measure'] === null && $e['recommendations'] === [] && $e['timeline'] === []);

// ---------- המקור: github כבוי כברירת מחדל ----------
check('מנעול הקוד פתוח (מנהל 00) — ההדלקה רק בקונפיג השרת', ACADEMY_GITHUB_PHASE_ALLOWED === true);
$def = academy_default_config();
// קובץ אסימון תקין לבדיקות — מחוץ לריפו, 0600. הערך נבנה בזמן ריצה (סריקת הסודות לא תמצא אותו בקובץ הזה).
$tokDir = sys_get_temp_dir() . '/ca-secret-' . getmypid();
@mkdir($tokDir, 0700);
$tokFile = "$tokDir/github-token";
define('FILE_TOKEN', 'github' . '_pat_' . 'FILE' . str_repeat('k7', 14));
file_put_contents($tokFile, FILE_TOKEN . "\n");
chmod($tokFile, 0600);
$withTok = fn(array $c) => array_replace_recursive($c, ['github' => ['token_file' => $tokFile]]);
check('ברירת מחדל: github_enabled=false', $def['github_enabled'] === false && $def['privacy_confirmed'] === false);
check('ברירת מחדל: מקור local', academy_source($def) instanceof LocalAcademySource);
$on = ['source' => 'github', 'github_enabled' => true, 'privacy_confirmed' => true] + $def;
try { academy_source($on); check('דגלים דולקים + contract v1 → נחסם (v1 לא נקרא מ-github)', false); }
catch (AcademySourceError $e) { check('דגלים דולקים + contract v1 → נחסם (v1 לא נקרא מ-github)', $e->errorCode === 'source_disabled' && $e->reason === 'v1_github_blocked'); }
check('github_enabled בלי אישור פרטיות — לא מותר', !academy_github_allowed(['github_enabled' => true, 'privacy_confirmed' => false]));
check('"true" כמחרוזת אינו הדלקה', !academy_github_allowed(['github_enabled' => 'true', 'privacy_confirmed' => 'true']));
define('FAKE_TOKEN', 'github' . '_pat_' . 'SECRET' . str_repeat('x9', 12));
$tmp = sys_get_temp_dir() . '/ca-test-' . getmypid();
@mkdir($tmp);
// קונפיג "ישן" עם token — לבדיקת ההסתרה. github_enabled=false, כדי שהמקור יישאר דוגמה (מנעול הקוד פתוח).
file_put_contents("$tmp/config.json", json_encode(['source' => 'github', 'github_enabled' => false, 'privacy_confirmed' => true,
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
$ghOn = ['source' => 'github', 'github_enabled' => true, 'privacy_confirmed' => true, 'contract' => 'v2'] + $def;
check('מעבר למנעול: שני הדגלים + שלב → github', academy_source($withTok($ghOn), '', true) instanceof GithubAcademySource);
check('מעבר למנעול: demo=empty לא מחליף github לדוגמה', academy_source($withTok($ghOn), 'empty', true) instanceof GithubAcademySource);
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
$v2 = $valid; $v2['schemaVersion'] = '3.0.0';
[$st, $body] = academy_api_snapshot($def, [], new FixedSource($v2));
check('גרסת חוזה אחרת → contract_version (לא contract_invalid)', $st === 422 && $body['code'] === 'contract_version' && $body['receivedVersion'] === '3.0.0');
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
check('config.example: אין אסימון, ושני הדגלים כבויים', !array_key_exists('token', $ex['github']) && $ex['github_enabled'] === false && $ex['privacy_confirmed'] === false);
check('index.html: פס הדוגמה גלוי כברירת מחדל (עד שהשרת אומר אחרת)', (bool) preg_match('/id="sample-bar"(?![^>]*hidden)/', (string) file_get_contents("$root/index.html")));

// ---------- snapshot אמת ריק (c4e7a8d) ----------
$realEmpty = fixture('academy-snapshot-c4e7a8d.json');
check('c4e7a8d: עובר אימות', academy_validate($realEmpty) === []);
foreach (['updatedAt null' => null, 'updatedAt תאריך' => '2026-11-23T12:00:00Z'] as $label => $upd) {
    $snap = ['updatedAt' => $upd] + $realEmpty;
    $src = new GithubAcademySource($gh, "$tmp/cache", 0, gh_stub(200, json_encode($snap)));
    [$st, $body] = academy_api_snapshot($cfg, [], $src);
    $mm = $body['model'] ?? [];
    check("אמת ריק ($label): ok, לא דוגמה, empty", $st === 200 && $body['ok'] === true && $body['isSample'] === false
        && $body['source'] === 'github' && ($mm['empty'] ?? null) === true);
    check("אמת ריק ($label): הקומיט מוחזר", $body['sourceInfo']['commit'] === FAKE_SHA);
    check("אמת ריק ($label): אין מדד התקדמות", array_key_exists('measure', $mm) && $mm['measure'] === null);
    check("אמת ריק ($label): אין מסלולים, המלצות או פערים", $mm['tracks'] === [] && $mm['recommendations'] === []
        && $mm['gaps'] === [] && $mm['unmetPrerequisites'] === []);
    check("אמת ריק ($label): ציר זמן רק מ-updatedAt", array_column($mm['timeline'], 'kind') === ($upd ? ['snapshot'] : []));
    check("אמת ריק ($label): אין learned/proven בפלט", !preg_match('/"(learned|proven)"/', json_encode($body)));
}
check('דוגמה ריקה: גם בלי מדד', academy_api_snapshot($def, ['demo' => 'empty'])[1]['model']['measure'] === null);
check('דוגמה מלאה: יש מדד', is_array(academy_api_snapshot($def)[1]['model']['measure']));

// ---------- חוזה v2 ----------
$fx2 = fn(string $n) => json_decode((string) file_get_contents(__DIR__ . "/fixtures/v2/$n.json"), true);
$ex2 = $fx2('excerpt-49f4b2d');
$full2 = $fx2('synthetic-full');
check('v2: קטע 49f4b2d עובר', academy_validate_v2($ex2) === [], implode(' | ', academy_validate_v2($ex2)));
check('v2: fixture מלא עובר', academy_validate_v2($full2) === [], implode(' | ', academy_validate_v2($full2)));
$b2 = glob(__DIR__ . '/fixtures/v2/broken/*.json');
check('v2: יש fixtures שבורים לכל כלל', count(glob(__DIR__ . '/fixtures/v2/broken/schema--*.json')) >= 20 && count(glob(__DIR__ . '/fixtures/v2/broken/rule--*.json')) >= 30);
foreach ($b2 as $f) {
    $errs = academy_validate_v2(json_decode((string) file_get_contents($f), true));
    check('v2 שבור נכשל: ' . basename($f), $errs !== []);
    foreach ($errs as $e) check('v2: נתיב JSON בהודעה: ' . basename($f), str_starts_with($e, '$'), $e);
}
// כל כלל נכשל מהסיבה הנכונה
$why = [
    'rule--task-dependsOn-cycle' => 'מחזור בתלויות', 'rule--task-dependsOn-self' => 'בעצמה', 'rule--prerequisite-cycle' => 'מעגל בדרישות',
    'rule--curriculum-approved-no-approvedBy' => 'approvedAt ו-approvedBy', 'rule--curriculum-proposed-with-approval' => 'יהיו null',
    'rule--mastered-no-evidence' => 'ראיה תקינה', 'rule--mastered-assessment-not-approved' => 'הערכה מתועדת',
    'rule--module-lessonIds-other-module' => 'שייך למודול אחר', 'rule--stage-trackIds-other-stage' => 'שייך ל-stage אחר',
    'rule--evidence-reference-token' => 'הפניה לא בטוחה', 'rule--evidence-reference-absolute' => 'נתיב מוחלט',
    'rule--duplicate-track-id' => 'ייחודי', 'schema--track-extra-property' => 'אינו קיים בחוזה v2', 'schema--bad-id-pattern' => 'מזהה לא תקין',
    'schema--ids-not-unique' => 'כפול', 'schema--lesson-no-objectives' => 'פריט אחד לפחות',
];
foreach ($why as $n => $word) {
    $errs = academy_validate_v2($fx2("broken/$n"));
    check("v2 $n: הסיבה \"$word\"", (bool) array_filter($errs, fn($e) => str_contains($e, $word)), implode(' | ', $errs));
}
check('v2: הפניה יחסית ו-https מותרות', academy_v2_unsafe_reference('evidence/a.md') === '' && academy_v2_unsafe_reference('https://x.org/a') === '');

// API: ניתוב לפי גרסה
[$st, $body] = academy_api_snapshot($def, [], new FixedSource($full2));
check('API v2: 200 + מודל v2', $st === 200 && $body['model']['version'] === 2 && $body['contract']['schemaVersion'] === '2.0.0');
[$st, $body] = academy_api_snapshot($def, [], new FixedSource($fx2('broken/rule--task-dependsOn-cycle')));
check('API v2: שבור → contract_invalid', $st === 422 && $body['code'] === 'contract_invalid' && str_contains($body['error'], 'v2'));
[$st, $body] = academy_api_snapshot($def, [], new FixedSource($fx2('broken/schema--wrong-schema-version')));
check('API: 2.1.0 → contract_version', $st === 422 && $body['code'] === 'contract_version' && $body['receivedVersion'] === '2.1.0');
check('API: v1 עדיין עובד', academy_api_snapshot($def)[1]['model']['schemaVersion'] === '1.0.0');

// מודל v2
$m2 = academy_model_v2($full2);
check('מודל v2: stage → track → module → lesson', array_column($m2['stages'], 'id') === ['st-a', 'st-b']
    && $m2['stages'][0]['trackIds'] === ['tr-1', 'tr-2'] && array_column($m2['modules'][0]['lessons'], 'id') === ['l-1', 'l-2']);
check('מודל v2: topics ו-skills תחת המודול', array_column($m2['modules'][0]['topics'], 'id') === ['tp-1'] && array_column($m2['modules'][0]['skills'], 'id') === ['sk-1']);
check('מודל v2: נלמד ≠ הוכח, ספירות', $m2['measure']['learned'] === 1 && $m2['measure']['proven'] === 1 && $m2['measure']['total'] === 3);
check('מודל v2: אין אחוזים', !preg_match('/percent|pct|ratio|weighted/i', json_encode($m2)));
check('מודל v2: מסלול מוצע לא "approved" גם כשהסילבוס מאושר', array_column($m2['tracks'], 'curriculumStatus', 'id') === ['tr-1' => 'approved', 'tr-2' => 'proposed', 'tr-3' => 'approved']);
$prop = $full2; $prop['curriculum'] = ['status' => 'proposed', 'version' => 'x', 'approvedAt' => null, 'approvedBy' => null];
check('מודל v2: סילבוס מוצע → כל המסלולים "proposed"', !array_filter(academy_model_v2($prop)['tracks'], fn($t) => $t['curriculumStatus'] !== 'proposed'));
$mex = academy_model_v2($ex2);
check('מודל v2 (49f4b2d): אין הערכות → measure null, לא 0', $mex['measure'] === null && $mex['assessmentsRecorded'] === 0
    && $mex['tracks'][0]['measure'] === null && $mex['modules'][0]['measure'] === null);
check('מודל v2 (49f4b2d): שיעור lab עם authorizedLabRequired', $mex['modules'][0]['lessons'][2]['kind'] === 'lab' && $mex['modules'][0]['lessons'][2]['authorizedLabRequired'] === true);
check('מודל v2: משימה ממתינה לתלות', array_column(array_column($m2['tasks'], 'waitingOn', 'id')['tk-3'], 'id') === ['tk-2']);

// github v2: רק סילבוס מאושר, בלי נתוני לומד
$co = academy_v2_curriculum_only($full2);
check('github v2: רק מסלולים מאושרים', array_column($co['tracks'], 'id') === ['tr-1', 'tr-3']);
check('github v2: בלי נתוני לומד', $co['competencies'] === [] && $co['tasks'] === [] && $co['assessments'] === [] && $co['evidence'] === [] && $co['timeline'] === []);
check('github v2: המסונן עדיין תקין', academy_validate_v2($co) === [], implode(' | ', academy_validate_v2($co)));
check('github v2: מודול של מסלול מוצע נשמט', !in_array('m-3', array_column($co['modules'], 'id'), true) && !in_array('tp-2', array_column($co['topics'], 'id'), true));
check('github v2: 49f4b2d (הכול מוצע) → 0 מסלולים', academy_v2_curriculum_only($ex2)['tracks'] === [] && academy_v2_curriculum_only($ex2)['lessons'] === []);
$src = new GithubAcademySource($gh, "$tmp/cache", 0, gh_stub(200, json_encode($full2)));
[$st, $body] = academy_api_snapshot($cfg, [], $src);
check('API github v2: מסונן, בלי מדד ובלי לומד', $st === 200 && $body['filter'] === 'approved_curriculum_only' && $body['isSample'] === false
    && $body['model']['measure'] === null && $body['model']['tasks'] === [] && $body['model']['competencies'] === []
    && !str_contains(json_encode($body), 'evidence/tr-1'));
$src = new GithubAcademySource($gh, "$tmp/cache", 0, gh_stub(200, json_encode($ex2)));
[$st, $body] = academy_api_snapshot($cfg, [], $src);
check('API github v2 (49f4b2d): 0 מסלולים → ריק', $st === 200 && $body['model']['empty'] === true && $body['model']['counts']['tracks'] === 0);
// נתיבים: curriculum_path / snapshot_path; ריק = fail-closed; דגלים כבויים = source_disabled
$ghV2 = ['source' => 'github', 'github_enabled' => true, 'privacy_confirmed' => true, 'contract' => 'v2'] + $def;
$ghV2['github'] = ['curriculum_path' => ''] + $def['github'];
try { academy_source($ghV2, '', true); check('curriculum_path ריק → source_disabled', false); }
catch (AcademySourceError $e) { check('curriculum_path ריק → source_disabled', $e->errorCode === 'source_disabled'); }
$ghV1 = ['source' => 'github', 'github_enabled' => true, 'privacy_confirmed' => true] + $def;
$ghV1['github'] = ['snapshot_path' => ''] + $def['github'];
try { academy_source($ghV1, '', true); check('v1 מ-github → source_disabled', false); }
catch (AcademySourceError $e) { check('v1 מ-github → source_disabled (גם בלי snapshot_path)', $e->errorCode === 'source_disabled' && $e->reason === 'v1_github_blocked'); }
$ghV2['github'] = $def['github'];
$ghV2 = $withTok($ghV2);
check('curriculum_path (מעבר למנעול) → github', academy_source($ghV2, '', true) instanceof GithubAcademySource);
$seen = [];
$s2 = academy_source($ghV2 + [], '', true);
$rp = new ReflectionProperty($s2, 'gh');
check('v2 קורא את curriculum-v2-approved.json', $rp->getValue($s2)['path'] === 'dashboard/data/curriculum-v2-approved.json');
$reads = [];
$recv1 = function (string $p) use (&$reads) { $reads[] = $p; return file_get_contents($p); };
try { academy_source($withTok(['source' => 'github', 'github_enabled' => true, 'privacy_confirmed' => true] + $def), '', true, $recv1); check('v1 עם אסימון תקין → עדיין חסום', false); }
catch (AcademySourceError $e) { check('v1 עם אסימון תקין → עדיין חסום, האסימון לא נקרא', $e->reason === 'v1_github_blocked' && $reads === []); }
try { academy_source(['fallback_to_sample' => false] + $ghV2, '', false); check('v2: מנעול קוד סגור (פרמטר) → source_disabled', false); }
catch (AcademySourceError $e) { check('v2: מנעול קוד סגור (פרמטר) → source_disabled', $e->errorCode === 'source_disabled'); }
check('ברירת מחדל: contract v1 + נתיבי מנהל 00', $def['contract'] === 'v1'
    && $def['github']['snapshot_path'] === 'dashboard/data/snapshot.json'
    && $def['github']['curriculum_path'] === 'dashboard/data/curriculum-v2-approved.json'
    && $def['github']['contract_v2_path'] === 'dashboard/data/schema/v2/academy-with-lessons-proposal.schema.json');
$ex = json_decode((string) file_get_contents(__DIR__ . '/../samples/config.example.json'), true);
check('config.example: אותם נתיבים, הכול כבוי', $ex['github']['curriculum_path'] === $def['github']['curriculum_path']
    && $ex['github']['snapshot_path'] === $def['github']['snapshot_path'] && $ex['github_enabled'] === false && $ex['privacy_confirmed'] === false);

// timeout
$src = new GithubAcademySource($gh, "$tmp/cache", 0, function () { throw new AcademySourceError('תם הזמן לפנייה למאגר האקדמיה', 'source_unavailable'); });
[$st, $body] = academy_api_snapshot($cfg, [], $src);
check('github timeout → source_unavailable', $st === 502 && $body['code'] === 'source_unavailable');

// "secrets" בטקסט של תוכנית הלימודים אינו סוד — הסינון רק על evidence.reference
$sec = $full2; $sec['topics'][0]['title'] = 'least privilege ו-secrets'; $sec['modules'][0]['title'] = 'Docker, רשתות ו-secrets';
$sec['lessons'][0]['learningObjectives'] = ['ניהול secrets ו-token בצורה בטוחה'];
check('v2: "secrets"/"token" בטקסט הסילבוס עובר', academy_validate_v2($sec) === [], implode(' | ', academy_validate_v2($sec)));

// מאושר רק מהקובץ: approved + approvedAt + approvedBy, ומסלול approved
$appr = $ex2; $appr['curriculum'] = ['status' => 'approved', 'version' => '1.0.0', 'approvedAt' => '2026-11-23T15:06:23Z', 'approvedBy' => 'academy-manager-00'];
$appr['tracks'][0]['approvalStatus'] = 'approved';
$ma = academy_model_v2($appr);
check('v2 מאושר: הסילבוס והמסלול "approved"', academy_validate_v2($appr) === [] && $ma['curriculum']['curriculumStatus'] === 'approved' && $ma['tracks'][0]['curriculumStatus'] === 'approved');
check('v2 מאושר בלי הערכות: measure null', $ma['measure'] === null && $ma['tracks'][0]['measure'] === null);
$co2 = academy_v2_curriculum_only($appr);
check('github v2 מאושר: המסלול והשיעורים עוברים', count($co2['tracks']) === 1 && count($co2['lessons']) === 3);

// endpoint תוכנית האקדמיה
foreach (['POST', 'PUT', 'DELETE'] as $mth) check("curriculum מסרב ל-$mth", academy_api_curriculum($mth, $def)[0] === 405);
[$st, $body] = academy_api_curriculum('GET', $def);
check('curriculum: המקור כבוי → source_disabled (אין דוגמה)', $st === 503 && $body['code'] === 'source_disabled' && !isset($body['curriculum']));
[$st, $body] = academy_api_curriculum('GET', $cfg, new GithubAcademySource($gh, "$tmp/cache", 0, gh_stub(200, json_encode($appr))));
$out = json_encode($body, JSON_UNESCAPED_UNICODE);
check('curriculum: מאושר → 200 עם תוכנית בלבד', $st === 200 && $body['curriculum']['counts']['lessons'] === 3 && $body['isSample'] === false
    && $body['curriculum']['curriculum']['curriculumStatus'] === 'approved');
check('curriculum: בלי נתוני לומד ובלי measure', !preg_match('/"(competencies|tasks|assessments|evidence|timeline|measure|learned|proven)"/', $out));
[$st, $body] = academy_api_curriculum('GET', $cfg, new GithubAcademySource($gh, "$tmp/cache", 0, gh_stub(200, json_encode($full2))));
check('curriculum: רק מסלולים מאושרים', $st === 200 && array_column($body['curriculum']['tracks'], 'id') === ['tr-1', 'tr-3']);
[$st, $body] = academy_api_curriculum('GET', $cfg, new GithubAcademySource($gh, "$tmp/cache", 0, gh_stub(200, json_encode($valid))));
check('curriculum: snapshot v1 → contract_version', $st === 422 && $body['code'] === 'contract_version');
foreach ([401, 403, 404, 429, 500] as $code) {
    [$st, $body] = academy_api_curriculum('GET', $cfg, new GithubAcademySource($gh, "$tmp/cache", 0, gh_stub($code, '{"message":"' . $token . '"}')));
    check("curriculum $code: fail-closed בלי אסימון", $st >= 500 && !isset($body['curriculum']) && !str_contains(json_encode($body), $token));
}
[$st, $body] = academy_api_curriculum('GET', $cfg, new GithubAcademySource($gh, "$tmp/cache", 0, gh_stub(200, '{"schemaVersion":"2.0.0",' . $token)));
check('curriculum JSON שבור: 422 בלי אסימון', $st === 422 && !str_contains(json_encode($body), $token));

// מחובר ומורשה בלבד (בשרת אמיתי — לא CLI ולא php -S)
check('שרת: בלי PLATFORM_USER → אין תפקיד', academy_role([], 'litespeed') === null);
check('שרת: PLATFORM_USER בלי הרשאה → אין תפקיד', academy_role(['PLATFORM_USER' => json_encode(['sub' => '1', 'granted' => false])], 'litespeed') === null);
check('שרת: PLATFORM_USER פגום → אין תפקיד', academy_role(['PLATFORM_USER' => '{bad'], 'litespeed') === null);
check('שרת: מחובר ומורשה → viewer', academy_role(['PLATFORM_USER' => json_encode(['sub' => '1', 'granted' => true])], 'litespeed') === 'viewer');
check('CLI ו-php -S → viewer (פיתוח)', academy_role([], 'cli') === 'viewer' && academy_role([], 'cli-server') === 'viewer');
check('viewer קורא תוכנית', academy_can('curriculum.read', 'viewer') && !academy_can('curriculum.read', 'nobody'));
foreach ([401, 403, 404, 429, 500] as $code) {
    $src = new GithubAcademySource(['path' => 'x/v2.json'] + $gh, "$tmp/cache", 0, gh_stub($code, '{"message":"' . $token . '"}'));
    $out = json_encode(academy_api_snapshot($cfg, [], $src), JSON_UNESCAPED_UNICODE);
    check("github v2 $code: בלי אסימון", !str_contains($out, $token) && str_contains($out, '"code":"source_'));
}
$src = new GithubAcademySource($gh, "$tmp/cache", 0, gh_stub(200, '{"schemaVersion":"2.0.0",' . $token));
$out = json_encode(academy_api_snapshot($cfg, [], $src), JSON_UNESCAPED_UNICODE);
check('github v2 JSON שבור: contract_invalid בלי אסימון', str_contains($out, 'contract_invalid') && !str_contains($out, $token));
check('אין endpoint ל-issues', !glob(__DIR__ . '/../api/*issue*') && !preg_match('/\/issues/', (string) file_get_contents(__DIR__ . '/../lib/academy-source.php')));

// ---------- האסימון: רק מקובץ מחוץ לאתר ----------
$on = $withTok(['source' => 'github', 'github_enabled' => true, 'privacy_confirmed' => true, 'fallback_to_sample' => false, 'contract' => 'v2'] + $def);
$reads = [];
$rec = function (string $p) use (&$reads) { $reads[] = $p; return file_get_contents($p); };
$srcOk = academy_source($on, '', true, $rec);
check('token_file תקין מחוץ לעץ → נקרא פעם אחת', $srcOk instanceof GithubAcademySource && count($reads) === 1);
$seen = [];
$srcOk2 = new GithubAcademySource((new ReflectionProperty($srcOk, 'gh'))->getValue($srcOk), "$tmp/cache", 0, gh_stub(200, json_encode($valid), $seen));
$srcOk2->fetch();
check('ה-stub מקבל את האסימון מהקובץ (בכותרת בלבד)', in_array('Authorization: Bearer ' . FILE_TOKEN, $seen[0]['headers'], true));
check('token_file לא נשמר באובייקט המקור', !array_key_exists('token_file', (new ReflectionProperty($srcOk, 'gh'))->getValue($srcOk)));
$why = function (array $cfgX, ?callable $reader = null) {
    try { academy_source($cfgX, '', true, $reader); return ['ok', null]; }
    catch (AcademySourceError $e) { return [$e->errorCode, $e->reason, $e->getMessage()]; }
};
// בתוך עץ הריפו
$inRepo = __DIR__ . '/fixtures/ca-test.token';
file_put_contents($inRepo, FILE_TOKEN); chmod($inRepo, 0600);
$reads = [];
$r = $why(array_replace_recursive($on, ['github' => ['token_file' => $inRepo]]), $rec);
check('token_file בתוך עץ הריפו → secret_in_webroot, לא נקרא', $r[0] === 'source_disabled' && $r[1] === 'secret_in_webroot' && $reads === []);
check('הודעה בלי נתיב', !str_contains($r[2] ?? '', $inRepo) && !str_contains($r[2] ?? '', 'fixtures'));
unlink($inRepo);
check('נתיב עם public_html → בתוך האתר', academy_path_in_webroot('/home2/u/public_html/x/t') && !academy_path_in_webroot($tokFile));
// קישור סימבולי מחוץ לעץ שמצביע לתוך העץ
$inRepo2 = __DIR__ . '/fixtures/ca-test2.token';
file_put_contents($inRepo2, FILE_TOKEN); chmod($inRepo2, 0600);
@symlink($inRepo2, "$tokDir/link-token");
$r = $why(array_replace_recursive($on, ['github' => ['token_file' => "$tokDir/link-token"]]), $rec);
check('קישור סימבולי אל תוך העץ → secret_in_webroot', $r[1] === 'secret_in_webroot');
@unlink("$tokDir/link-token"); unlink($inRepo2);
// token בקונפיג
$reads = [];
$withCfgTok = array_replace_recursive($on, ['github' => ['token' => FAKE_TOKEN]]);
$r = $why($withCfgTok, $rec);
check('token בקונפיג → token_in_config, הקובץ לא נקרא', $r[0] === 'source_disabled' && $r[1] === 'token_in_config' && $reads === []);
$r = $why(array_replace_recursive($on, ['github' => ['token' => '']]), $rec);
check('גם token ריק בקונפיג → נחסם', $r[1] === 'token_in_config');
[$st, $body] = academy_api_snapshot($withCfgTok);
$out = json_encode($body, JSON_UNESCAPED_UNICODE);
check('token בקונפיג: API → source_disabled בלי הערך', $st === 503 && $body['code'] === 'source_disabled' && !str_contains($out, FAKE_TOKEN));
// world-readable
$wr = "$tokDir/world-readable"; file_put_contents($wr, FILE_TOKEN); chmod($wr, 0644);
$reads = [];
$r = $why(array_replace_recursive($on, ['github' => ['token_file' => $wr]]), $rec);
check('קובץ 0644 → secret_insecure_perms, לא נקרא', $r[1] === 'secret_insecure_perms' && $reads === []);
// חסר / יחסי / ריק בקונפיג
$r = $why(array_replace_recursive($on, ['github' => ['token_file' => "$tokDir/missing"]]));
check('קובץ חסר → source_disabled בלי נתיב', $r[0] === 'source_disabled' && $r[1] === 'token_file_missing' && !str_contains($r[2], $tokDir));
check('token_file יחסי → נחסם', $why(array_replace_recursive($on, ['github' => ['token_file' => 'secrets/t']]))[1] === 'token_file_missing');
check('token_file ריק → נחסם', $why(array_replace_recursive($on, ['github' => ['token_file' => '']]))[1] === 'token_file_missing');
// תוכן קצר / ריק / עם רווח
foreach (['קצר' => 'abc', 'ריק' => "  \n", 'רווח באמצע' => substr(FILE_TOKEN, 0, 15) . ' ' . substr(FILE_TOKEN, 15)] as $label => $val) {
    $f = "$tokDir/bad-" . md5($label); file_put_contents($f, $val); chmod($f, 0600);
    $r = $why(array_replace_recursive($on, ['github' => ['token_file' => $f]]));
    check("תוכן $label → source_unauthorized", $r[0] === 'source_unauthorized' && $r[1] === 'token_invalid' && !str_contains($r[2], trim($val) ?: '§'));
}
$nl = "$tokDir/newline"; file_put_contents($nl, "  " . FILE_TOKEN . "\n\n"); chmod($nl, 0600);
check('רווחים ושורות בקצוות מקוצצים', academy_github_token(['token_file' => $nl]) === FILE_TOKEN);
// דגלים כבויים: הקובץ לא נפתח בכלל
foreach ([['github_enabled' => false], ['privacy_confirmed' => false]] as $off) {
    $reads = [];
    academy_source(array_replace($on, $off, ['fallback_to_sample' => true]), '', true, $rec);
    check('דגל כבוי → הקובץ לא נפתח: ' . key($off), $reads === []);
}
$reads = [];
academy_source(array_replace($on, ['fallback_to_sample' => true]), '', false, $rec);
check('מנעול השלב כבוי → הקובץ לא נפתח', $reads === []);
// מנעול הקוד פתוח, אבל הקונפיג כמו בתבנית / ברירת המחדל → שום דבר לא נדלק והקובץ לא נפתח
$exTpl = json_decode((string) file_get_contents(__DIR__ . '/../samples/config.example.json'), true);
foreach (['ברירת המחדל' => $def, 'התבנית' => $exTpl] as $label => $base) {
    $reads = [];
    $srcD = academy_source($withTok($base), '', ACADEMY_GITHUB_PHASE_ALLOWED, $rec);
    check("$label + מנעול פתוח → דוגמה, הקובץ לא נפתח", $srcD instanceof LocalAcademySource && $reads === []);
    [$st, $body] = academy_api_curriculum('GET', $withTok($base), null, ['reader' => $rec]);
    check("$label: curriculum.php → source_disabled, הקובץ לא נפתח", $st === 503 && $body['code'] === 'source_disabled' && $reads === []);
}
// כל מתג לבדו לא מדליק
foreach (['source' => 'github', 'github_enabled' => true, 'privacy_confirmed' => true, 'contract' => 'v2'] as $k => $v) {
    $reads = [];
    $only = $withTok(array_replace($def, [$k => $v]));
    [$st] = academy_api_curriculum('GET', $only, null, ['reader' => $rec]);
    check("רק $k לבדו → כבוי, הקובץ לא נפתח", $st === 503 && $reads === []);
}
// קונפיג ותבנית
check('ברירת מחדל: אין "token", יש token_file ריק', !array_key_exists('token', $def['github']) && $def['github']['token_file'] === '');
$exT = json_decode((string) file_get_contents(__DIR__ . '/../samples/config.example.json'), true);
check('config.example: אין "token", token_file ריק', !array_key_exists('token', $exT['github']) && $exT['github']['token_file'] === '');
check('config.example: שאר הקונפיג נשמר', $exT['source'] === 'local' && $exT['github_enabled'] === false && $exT['contract'] === 'v1'
    && $exT['github']['curriculum_path'] === 'dashboard/data/curriculum-v2-approved.json' && $exT['cache_ttl'] === 120);
$gi = (string) @file_get_contents(__DIR__ . '/../.gitignore');
check('.gitignore: config, cache, *.token', str_contains($gi, 'data/config.json') && str_contains($gi, 'data/cache/') && str_contains($gi, '*.token'));
// ---------- הפעלת מקור GitHub (מנעול הקוד פתוח): api/curriculum.php מקצה לקצה, עם stub ל-HTTP ----------
// הכול דרך academy_source → GithubAcademySource → transport מדומה. אין פנייה אמיתית לרשת.
// cache_ttl 0 — בלי מטמון (אחרת התשובה הראשונה נשמרת ב-data/cache האמיתי ומוחזרת לכל השאר)
$live = $withTok(array_replace($def, ['source' => 'github', 'contract' => 'v2', 'github_enabled' => true, 'privacy_confirmed' => true, 'cache_ttl' => 0]));
$authOk = ['server' => ['PLATFORM_USER' => json_encode(['sub' => '1', 'granted' => true])], 'sapi' => 'litespeed'];
$ALLOWED_URLS = [];
$urlStub = function (int $status, string $body, bool $throw = false) use (&$ALLOWED_URLS) {
    return function (string $url, array $h) use ($status, $body, $throw, &$ALLOWED_URLS) {
        $ALLOWED_URLS[] = $url;
        if ($throw) throw new AcademySourceError('תם הזמן לפנייה למאגר האקדמיה', 'source_unavailable');
        if (str_contains($url, '/commits/')) return ['status' => 200, 'body' => FAKE_SHA];
        return ['status' => $status, 'body' => $body];
    };
};
$allowedRe = '~^https://api\.github\.com/repos/malkiel01/offensive-cyber-academy/(commits/main|contents/dashboard/data/curriculum-v2-approved\.json\?ref=' . FAKE_SHA . ')$~';
$call = fn(callable $t, array $opts = []) => academy_api_curriculum('GET', $live, null, $opts + $authOk + ['transport' => $t]);
// 200 — מאושר
$ALLOWED_URLS = [];
[$st, $body] = $call($urlStub(200, json_encode($appr)));
check('הפעלה: 200 עם תוכנית מאושרת, לא דוגמה', $st === 200 && $body['ok'] === true && $body['isSample'] === false && $body['curriculum']['counts']['lessons'] === 3);
check('הפעלה: בדיוק שתי פניות — קומיט ואז הקובץ באותו קומיט', count($ALLOWED_URLS) === 2
    && str_ends_with($ALLOWED_URLS[0], '/commits/main') && str_ends_with($ALLOWED_URLS[1], '?ref=' . FAKE_SHA), implode(' | ', $ALLOWED_URLS));
check('הפעלה: כל URL ברשימה המותרת', !array_filter($ALLOWED_URLS, fn($u) => !preg_match($allowedRe, $u)), implode(' | ', $ALLOWED_URLS));
check('הפעלה: אין issues/comments ב-URL', !array_filter($ALLOWED_URLS, fn($u) => preg_match('~/(issues|comments|pulls)~', $u)));
check('הפעלה: האסימון מהקובץ לא בפלט', !str_contains(json_encode($body), FILE_TOKEN));
// רק מסלולים מאושרים
$ALLOWED_URLS = [];
[$st, $body] = $call($urlStub(200, json_encode($full2)));
check('הפעלה: מסלול שאינו approved לא בתשובה', $st === 200 && array_column($body['curriculum']['tracks'], 'id') === ['tr-1', 'tr-3']
    && !str_contains(json_encode($body, JSON_UNESCAPED_UNICODE), 'fixture · track 2'));
check('הפעלה: בלי נתוני לומד', !preg_match('/"(competencies|tasks|assessments|evidence|timeline|measure)"/', json_encode($body)));
$propAll = $full2; $propAll['curriculum'] = ['status' => 'proposed', 'version' => 'x', 'approvedAt' => null, 'approvedBy' => null];
[$st, $body] = $call($urlStub(200, json_encode($propAll)));
check('הפעלה: סילבוס proposed → 0 מסלולים', $st === 200 && $body['curriculum']['tracks'] === [] && $body['curriculum']['modules'] === []);
// כל כשל → שגיאה, בלי תוכן ובלי דוגמה
$fails = ['401' => [401, '{"message":"' . FILE_TOKEN . '"}'], '403' => [403, FILE_TOKEN], '404' => [404, FILE_TOKEN],
          '429' => [429, FILE_TOKEN], '500' => [500, FILE_TOKEN], 'JSON שבור' => [200, '{"schemaVersion":"2.0.0",' . FILE_TOKEN]];
foreach ($fails as $label => [$code, $b]) {
    [$st, $body] = $call($urlStub($code, $b));
    $out = json_encode($body, JSON_UNESCAPED_UNICODE);
    check("הפעלה $label: שגיאה בלי תוכן ובלי דוגמה", $st >= 400 && $body['ok'] === false && !isset($body['curriculum']) && $body['isSample'] === false && !isset($body['model']), $out);
    check("הפעלה $label: בלי אסימון", !str_contains($out, FILE_TOKEN));
}
[$st, $body] = $call($urlStub(200, '', true));
check('הפעלה timeout: source_unavailable בלי תוכן', $st === 502 && $body['code'] === 'source_unavailable' && !isset($body['curriculum']));
// אימות לפני שימוש
foreach (['rule--task-dependsOn-cycle', 'rule--evidence-reference-token', 'schema--lesson-bad-kind'] as $n) {
    [$st, $body] = $call($urlStub(200, json_encode($fx2("broken/$n"))));
    check("הפעלה: קובץ לא תקין ($n) → contract_invalid בלי תוכן", $st === 422 && $body['code'] === 'contract_invalid' && !isset($body['curriculum']));
}
[$st, $body] = $call($urlStub(200, json_encode($valid)));
check('הפעלה: snapshot v1 בנתיב → contract_version', $st === 422 && $body['code'] === 'contract_version');
// הרשאה
foreach (['בלי PLATFORM_USER' => [], 'granted=false' => ['PLATFORM_USER' => json_encode(['sub' => '1', 'granted' => false])]] as $label => $srv) {
    $ALLOWED_URLS = []; $reads = [];
    [$st, $body] = academy_api_curriculum('GET', $live, null, ['server' => $srv, 'sapi' => 'litespeed', 'transport' => $urlStub(200, json_encode($appr)), 'reader' => $rec]);
    check("הפעלה $label → 403, בלי פנייה ובלי קריאת אסימון", $st === 403 && $body['code'] === 'forbidden' && $ALLOWED_URLS === [] && $reads === []);
}
// אין קוד שפונה ל-issues
$codeFiles = array_merge(glob(__DIR__ . '/../lib/*.php'), glob(__DIR__ . '/../api/*.php'), glob(__DIR__ . '/../assets/js/*.js'));
$issueHits = array_filter($codeFiles, fn($f) => preg_match('~/issues|/comments|/pulls|issues\?~i', (string) file_get_contents($f)));
check('אין קריאה ל-issues/comments/pulls בקוד', $issueHits === [], implode(', ', array_map('basename', $issueHits)));

check('הבדיקות לא השאירו מטמון ב-data/ של הריפו', !is_dir(__DIR__ . '/../data/cache'));
array_map('unlink', glob("$tokDir/*") ?: []); @rmdir($tokDir);

// ---------- התקדמות לומד (progress-1) ----------
$pfx = fn(string $n) => json_decode((string) file_get_contents(__DIR__ . "/fixtures/progress/$n.json"), true);
$pv = $pfx('sample-learner');
check('progress: לומד הדוגמה תקין', academy_progress_validate($pv) === [], implode(' | ', academy_progress_validate($pv)));
$pb = glob(__DIR__ . '/fixtures/progress/broken/*.json');
check('progress: יש fixtures שבורים', count($pb) >= 15);
foreach ($pb as $f) check('progress שבור נכשל: ' . basename($f), academy_progress_validate(json_decode((string) file_get_contents($f), true)) !== []);
$pwhy = ['mastered-no-evidence' => 'ראיה מאומתת', 'mastered-evidence-not-verified' => 'ראיה מאומתת', 'mastered-no-approvedBy' => 'מאושרת',
    'mastered-assisted' => 'assistance: none', 'practice-without-assessment' => 'מאושרת', 'evidence-reference-dotdot' => 'הפניה לא בטוחה',
    'evidence-reference-absolute' => 'נתיב מוחלט', 'lesson-completed-no-approval' => 'אישור מנהל', 'lesson-completed-no-evidence' => 'ראיה מאומתת',
    'lesson-completed-unverified-evidence' => 'ראיה מאומתת', 'report-with-status' => 'אינו קיים בחוזה'];
foreach ($pwhy as $n => $w) {
    $e = academy_progress_validate($pfx("broken/$n"));
    check("progress $n: הסיבה \"$w\"", (bool) array_filter($e, fn($x) => str_contains($x, $w)), implode(' | ', $e));
}
// שלוש קטגוריות, לא מתמזגות; בלי אחוז
$view = academy_progress_view($pv, ['sk-1', 'sk-2', 'sk-3', 'sk-4', 'sk-5']);
check('progress: verified / reported / not_assessed', $view['skills']['sk-1']['category'] === 'verified' && $view['skills']['sk-4']['category'] === 'reported'
    && $view['skills']['sk-5']['category'] === 'not_assessed' && $view['skills']['sk-3']['category'] === 'not_assessed');
check('progress: דיווח מורה לא משנה סטטוס', $view['skills']['sk-4']['status'] === 'not_assessed' && $view['skills']['sk-4']['pendingReports'] === 1);
check('progress: נלמד והוכח נפרדים, רק ממאומת', $view['learned'] === 1 && $view['proven'] === 1);
check('progress: אין אחוז', !preg_match('/percent|pct|ratio|%/', json_encode($view)));
check('progress: השיעור שהושלם — רק עם ראיה ואישור', $view['lessonsCompleted'] === ['l-1']);

// מצב הריפו לפני — כדי לוודא שהבדיקות לא יצרו בו שום קובץ
$repoRoot = realpath(__DIR__ . '/..');
$listRepo = function () use ($repoRoot) {
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repoRoot, FilesystemIterator::SKIP_DOTS)) as $f) $out[] = $f->getPathname();
    sort($out);
    return $out;
};
$repoBefore = $listRepo();
// כותרות של בקשה מאותו מקור (F5) — כמו ש-fetch בדפדפן שולח
$SAME = ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_HOST' => 'mbe-plus.com', 'HTTP_ORIGIN' => 'https://mbe-plus.com'];
$userSrv = fn(string $sub = 'mgr-1') => ['server' => ['PLATFORM_USER' => json_encode(['sub' => $sub, 'granted' => true])] + $SAME, 'sapi' => 'litespeed'];
$regBody = json_encode(['action' => 'register', 'learnerId' => 'lrn-x', 'sub' => 'u-x']);
$blocked = function (array $cfgX, string $reason, string $label, ?string $secret = null) use ($userSrv, $regBody) {
    [$st, $body] = academy_api_progress_read('GET', $cfgX, [], $userSrv('mgr-1'));
    [$stL, $bodyL] = academy_api_progress_read('GET', $cfgX, [], $userSrv('u-x'));
    [$st2, $body2] = academy_api_progress_write('POST', $cfgX, 'application/json', $regBody, $userSrv('mgr-1'));
    $out = json_encode([$body, $bodyL, $body2], JSON_UNESCAPED_UNICODE);
    check("progress_dir $label → קריאה חסומה (503 $reason)", $st === 503 && $body['code'] === 'progress_unavailable' && $body['reason'] === $reason, $out);
    check("progress_dir $label → לומד: 503 בלי reason (F8)", $stL === 503 && $bodyL['code'] === 'progress_unavailable' && !isset($bodyL['reason']), $out);
    check("progress_dir $label → כתיבה חסומה", $st2 === 503 && $body2['code'] === 'progress_unavailable' && $body2['reason'] === $reason, $out);
    if ($secret !== null) check("progress_dir $label → בלי נתיב בתשובה", !str_contains($out, $secret));
};
$mgrCfg = fn(array $extra) => array_replace($def, ['progress' => ['managers' => ['mgr-1']]], $extra);

// ברירת מחדל — אין progress_dir: חסום, ושום דבר לא נוצר
$blocked($mgrCfg([]), 'progress_dir_missing', 'חסר (ברירת מחדל)');
check('ברירת מחדל: progress_dir ריק', $def['progress_dir'] === '');
check('אין ברירת מחדל ל-data/progress בקוד', !preg_match('~data/progress~', (string) file_get_contents(__DIR__ . '/../lib/progress.php') . file_get_contents(__DIR__ . '/../lib/progress-api.php') . file_get_contents(__DIR__ . '/../lib/academy-source.php')));
check('.gitignore: data/progress/ לא מוזכר כנתיב פעיל', !str_contains((string) file_get_contents(__DIR__ . '/../.gitignore'), 'data/progress'));
check('config.example: progress_dir ריק', json_decode((string) file_get_contents(__DIR__ . '/../samples/config.example.json'), true)['progress_dir'] === '');
// יחסי
$blocked($mgrCfg(['progress_dir' => 'secret-progress/x']), 'progress_dir_not_absolute', 'יחסי', 'secret-progress');
// בתוך העץ: שורש הריפו, cyber-academy, public_html, DOCUMENT_ROOT מדומה
$blocked($mgrCfg(['progress_dir' => realpath(__DIR__ . '/../..')]), 'progress_dir_unsafe', 'שורש mini_projects');
$blocked($mgrCfg(['progress_dir' => $repoRoot . '/data']), 'progress_dir_unsafe', 'בתוך cyber-academy', $repoRoot);
$blocked($mgrCfg(['progress_dir' => '/home2/u/public_html/academy-progress']), 'progress_dir_unsafe', 'public_html', 'academy-progress');
$blocked($mgrCfg(['progress_dir' => '/srv/www/p']), 'progress_dir_unsafe', '/www/');
$blocked($mgrCfg(['progress_dir' => '/tmp/../etc']), 'progress_dir_unsafe', 'עם ..');
$fakeDocRoot = sys_get_temp_dir() . '/ca-docroot-' . getmypid();
@mkdir("$fakeDocRoot/site/p", 0700, true);
$oldDocRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
$_SERVER['DOCUMENT_ROOT'] = "$fakeDocRoot/site";
$blocked($mgrCfg(['progress_dir' => "$fakeDocRoot/site/p"]), 'progress_dir_unsafe', 'DOCUMENT_ROOT', $fakeDocRoot);
if ($oldDocRoot === null) unset($_SERVER['DOCUMENT_ROOT']); else $_SERVER['DOCUMENT_ROOT'] = $oldDocRoot;
@rmdir("$fakeDocRoot/site/p"); @rmdir("$fakeDocRoot/site"); @rmdir($fakeDocRoot);
// קישור סימבולי מחוץ לאתר אל תוך העץ
$outside = sys_get_temp_dir() . '/ca-out-' . getmypid();
@mkdir($outside, 0700);
@symlink($repoRoot . '/tests', "$outside/link");
$blocked($mgrCfg(['progress_dir' => "$outside/link"]), 'progress_dir_unsafe', 'קישור סימבולי לתוך העץ', $outside);
@unlink("$outside/link");
// תיקייה חסרה — נחסם, ולא נוצרת
$blocked($mgrCfg(['progress_dir' => "$outside/missing"]), 'progress_dir_missing', 'חסרה', $outside);
check('תיקייה חסרה לא נוצרה', !file_exists("$outside/missing"));
// הרשאות פתוחות
foreach ([0o755 => 'o+r (0755)', 0o644 => '0644', 0o666 => '0666', 0o703 => 'o+w (0703)'] as $mode => $lbl) {
    @mkdir("$outside/perm", 0700); chmod("$outside/perm", $mode);
    $blocked($mgrCfg(['progress_dir' => "$outside/perm"]), 'progress_dir_insecure', "הרשאות $lbl", $outside);
    chmod("$outside/perm", 0700);
    check("הרשאות $lbl: לא נכתב שום קובץ", glob("$outside/perm/*") === []);
    @rmdir("$outside/perm");
}
@rmdir($outside);
check('progress: managers ו-progress_dir נטענים מקונפיג השרת', (function () use ($tmp) {
    file_put_contents("$tmp/pcfg.json", json_encode(['progress' => ['managers' => ['mgr-9']], 'progress_dir' => '/srv/x']));
    $lc = academy_load_config("$tmp/pcfg.json");
    return $lc['progress']['managers'] === ['mgr-9'] && $lc['progress_dir'] === '/srv/x';
})());

// תיקייה חוקית: 0700, מחוץ לעץ — קריאה וכתיבה עובדות
$pdir = sys_get_temp_dir() . '/ca-progress-' . getmypid();
@mkdir($pdir, 0700);
chmod($pdir, 0700);
$pcfg = $mgrCfg(['progress_dir' => $pdir]);
$as = fn(?string $sub, bool $granted = true) => ['server' => ($sub === null ? [] : ['PLATFORM_USER' => json_encode(['sub' => $sub, 'granted' => $granted])]) + $SAME,
    'sapi' => 'litespeed', 'now' => '2026-11-23T12:00:00Z'];
$W = fn(array $body, ?string $sub = 'mgr-1') => academy_api_progress_write('POST', $pcfg, 'application/json', json_encode($body, JSON_UNESCAPED_UNICODE), $as($sub));
$R = fn(?string $sub, array $q = [], bool $granted = true) => academy_api_progress_read('GET', $pcfg, $q, $as($sub, $granted));

check('progress: אנונימי → 403', $R(null)[0] === 403);
check('progress: granted=false → 403', $R('u-1', [], false)[0] === 403);
[$st, $body] = $R('u-1');
check('progress: לומד בלי רישום → ריק, לא שגיאה', $st === 200 && $body['ok'] === true && $body['progress'] === null);
check('progress: אין מנהל כברירת מחדל', ($def['progress']['managers'] ?? []) === [] && academy_progress_actor($def, ['PLATFORM_USER' => json_encode(['sub' => 'mgr-1', 'granted' => true])], 'litespeed')['role'] === 'viewer');
// כתיבה: רק מנהל, רק JSON, רק POST
check('progress-write: לומד → 403', $W(['action' => 'register', 'learnerId' => 'lrn-a', 'sub' => 'u-1'], 'u-1')[0] === 403);
check('progress-write: אנונימי → 403', $W(['action' => 'register', 'learnerId' => 'lrn-a', 'sub' => 'u-1'], null)[0] === 403);
check('progress-write: GET → 405', academy_api_progress_write('GET', $pcfg, 'application/json', '{}', $as('mgr-1'))[0] === 405);
check('progress-write: טופס (לא JSON) → 415', academy_api_progress_write('POST', $pcfg, 'application/x-www-form-urlencoded', 'a=1', $as('mgr-1'))[0] === 415);
check('progress read: POST → 405', academy_api_progress_read('POST', $pcfg, [], $as('u-1'))[0] === 405);
check('progress-write: רישום', $W(['action' => 'register', 'learnerId' => 'lrn-a', 'sub' => 'u-1'])[0] === 200
    && $W(['action' => 'register', 'learnerId' => 'lrn-b', 'sub' => 'u-2'])[0] === 200);
[$st, $body] = $R('u-1');
check('progress: רשום בלי קובץ → ריק', $st === 200 && $body['learnerId'] === 'lrn-a' && $body['progress']['counts'] === ['verified' => 0, 'reported' => 0, 'notAssessed' => 0]);
// לומד א' לא רואה את ב'
check('progress: לומד מנסה לקרוא לומד אחר → 403', $R('u-1', ['learner' => 'lrn-b'])[0] === 403);
check('progress: לומד קורא את עצמו במפורש → 200', $R('u-1', ['learner' => 'lrn-a'])[0] === 200);
check('progress: מנהל קורא כל לומד', $R('mgr-1', ['learner' => 'lrn-b'])[0] === 200);
check('progress: מזהה לא תקין → 400', $R('mgr-1', ['learner' => '../x'])[0] === 400);
// דיווח מורה — לא משנה סטטוס
$rep = ['id' => 'rp-1', 'at' => '2026-11-22T10:00:00Z', 'reporter' => 'מורה דוגמה', 'skillIds' => ['sk-1'], 'assistanceLevel' => 'unknown', 'summary' => 'דוגמה'];
[$st, $body] = $W(['action' => 'report', 'learnerId' => 'lrn-a', 'report' => $rep]);
check('progress: דיווח נשמר, הסטטוס לא השתנה', $st === 200 && $body['progress']['skills']['sk-1']['category'] === 'reported'
    && $body['progress']['skills']['sk-1']['status'] === 'not_assessed');
[$st] = $W(['action' => 'report', 'learnerId' => 'lrn-a', 'report' => $rep + ['status' => 'mastered']]);
check('progress: דיווח עם status → נדחה', $st === 422);
// שדות חדשים בדיווח: lessonIds, assistanceLevel
$rep2 = ['id' => 'rp-2', 'at' => '2026-11-22T10:10:00Z', 'reporter' => 'מורה דוגמה', 'skillIds' => ['sk-2'], 'lessonIds' => ['l-1', 'l-2'],
    'assistanceLevel' => 'none', 'summary' => 'דוגמה · ביצע לבד לפי המורה'];
[$st, $body] = $W(['action' => 'report', 'learnerId' => 'lrn-a', 'report' => $rep2]);
check('דיווח עם lessonIds + assistanceLevel מתקבל', $st === 200, json_encode($body, JSON_UNESCAPED_UNICODE));
check('דיווח none לא משנה סטטוס', $body['progress']['skills']['sk-2']['status'] === 'not_assessed' && $body['progress']['skills']['sk-2']['category'] === 'reported');
check('unknown לא נספר כ-none', $body['progress']['reportsByAssistance']['unknown'] === 1 && $body['progress']['reportsByAssistance']['none'] === 1
    && $body['progress']['skills']['sk-1']['reportAssistance'] === ['unknown']);
foreach (['hints' => 'hints', 'בלי assistanceLevel' => null, 'lessonIds לא תקין' => 'bad-lesson'] as $lbl => $val) {
    $bad = ['id' => 'rp-x', 'assistanceLevel' => 'hint'] + $rep2;
    if ($lbl === 'בלי assistanceLevel') unset($bad['assistanceLevel']);
    elseif ($lbl === 'lessonIds לא תקין') $bad['lessonIds'] = ['../x'];
    else $bad['assistanceLevel'] = $val;
    check("דיווח $lbl → 422", $W(['action' => 'report', 'learnerId' => 'lrn-a', 'report' => $bad])[0] === 422);
}
// reports[].at — תאריך בלבד או תאריך-שעה, נשמר כמו שנשלח, ולא משפיע על סטטוס
foreach (['2026-11-20' => 'rp-d1', '2026-11-20T00:00:00Z' => 'rp-d2', '2026-11-20T09:30:00+02:00' => 'rp-d3'] as $at => $rid) {
    [$st, $body] = $W(['action' => 'report', 'learnerId' => 'lrn-a', 'report' => ['id' => $rid, 'at' => $at] + $rep2]);
    $stored = array_values(array_filter(json_decode((string) file_get_contents("$pdir/learner-lrn-a.json"), true)['reports'], fn($r) => $r['id'] === $rid));
    check("reports.at \"$at\" מתקבל ונשמר כמו שנשלח", $st === 200 && ($stored[0]['at'] ?? null) === $at);
    check("reports.at \"$at\" לא משנה סטטוס", $body['progress']['skills']['sk-2']['status'] === 'not_assessed');
}
foreach (['2026-13-45', '2026-02-30', 'יום שלישי', '20.11.2026', '2026-11-20T09:30:00', 20261120, null] as $bad) {
    check('reports.at ' . json_encode($bad, JSON_UNESCAPED_UNICODE) . ' → 422', $W(['action' => 'report', 'learnerId' => 'lrn-a', 'report' => ['id' => 'rp-bad', 'at' => $bad] + $rep2])[0] === 422);
}
check('date_or_dt רק ב-reports.at: שדות זמן אחרים נשארו dt', substr_count((string) file_get_contents(__DIR__ . '/../lib/progress.php'), "'date_or_dt'") === 1);
$asmDate = ['id' => 'as-d', 'skillIds' => ['sk-3'], 'status' => 'planned', 'assistance' => 'hint', 'assessor' => 'x', 'assessedAt' => '2026-11-20', 'evidenceIds' => []];
check('assessedAt עם תאריך בלבד → עדיין 422 (dt)', $W(['action' => 'apply', 'learnerId' => 'lrn-a', 'assessments' => [$asmDate]])[0] === 422);
check('evidence.createdAt עם תאריך בלבד → עדיין 422 (dt)', $W(['action' => 'apply', 'learnerId' => 'lrn-a', 'evidence' => [['id' => 'ev-d', 'kind' => 'code', 'reference' => 'evidence/x.md', 'assessor' => 'x', 'createdAt' => '2026-11-20']]])[0] === 422);
check('enum ההערכה: hint (לא hints)', ACADEMY_PROGRESS_ASSISTANCE === ['none', 'hint', 'guided', 'full']);
$asmHints = ['id' => 'as-h', 'skillIds' => ['sk-3'], 'status' => 'planned', 'assistance' => 'hints', 'assessor' => 'x', 'assessedAt' => null, 'evidenceIds' => []];
check('הערכה עם hints → 422', $W(['action' => 'apply', 'learnerId' => 'lrn-a', 'assessments' => [$asmHints]])[0] === 422);
check('הערכה עם unknown → 422 (רק בדיווח)', $W(['action' => 'apply', 'learnerId' => 'lrn-a', 'assessments' => [['assistance' => 'unknown'] + $asmHints]])[0] === 422);
// mastered מדיווח בלבד — נדחה
[$st, $body] = $W(['action' => 'apply', 'learnerId' => 'lrn-a', 'skills' => ['sk-2' => ['status' => 'mastered', 'updatedAt' => null, 'assessmentIds' => [], 'evidenceIds' => []]]]);
check('mastered מדיווח בלבד (none) → 422', $st === 422 && (bool) array_filter($body['errors'], fn($e) => str_contains($e, 'assistance: none')));
// mastered בלי ראיה / בלי אישור → נדחה, והקובץ לא משתנה
$fileA = "$pdir/learner-lrn-a.json";
$snap = file_get_contents($fileA);
$asm = ['id' => 'as-1', 'skillIds' => ['sk-1'], 'status' => 'evaluated', 'assistance' => 'none', 'assessor' => 'מורה דוגמה',
    'assessedAt' => '2026-11-22T11:00:00Z', 'evidenceIds' => [], 'approvedBy' => 'role-manager', 'approvedAt' => '2026-11-22T12:00:00Z'];
$master = ['sk-1' => ['status' => 'mastered', 'updatedAt' => '2026-11-22T12:00:00Z', 'assessmentIds' => ['as-1'], 'evidenceIds' => []]];
[$st, $body] = $W(['action' => 'apply', 'learnerId' => 'lrn-a', 'assessments' => [$asm], 'skills' => $master]);
check('progress: mastered בלי ראיה → 422', $st === 422 && (bool) array_filter($body['errors'], fn($e) => str_contains($e, 'ראיה מאומתת')));
$ev = ['id' => 'ev-1', 'kind' => 'lab_report', 'reference' => 'evidence/lrn-a/lab-1.md', 'assessor' => 'מורה דוגמה', 'createdAt' => '2026-11-22T10:30:00Z',
    'approvedBy' => 'role-manager', 'approvedAt' => '2026-11-22T12:00:00Z'];
[$st] = $W(['action' => 'apply', 'learnerId' => 'lrn-a', 'evidence' => [$ev], 'assessments' => [['approvedBy' => null] + $asm], 'skills' => $master]);
check('progress: mastered בלי approvedBy → 422', $st === 422);
[$st] = $W(['action' => 'apply', 'learnerId' => 'lrn-a', 'evidence' => [['reference' => '../../etc/passwd'] + $ev], 'assessments' => [$asm], 'skills' => $master]);
check('progress: ראיה עם .. → 422', $st === 422);
[$st] = $W(['action' => 'apply', 'learnerId' => 'lrn-a', 'evidence' => [['reference' => '/home/u/x.md'] + $ev], 'assessments' => [$asm], 'skills' => $master]);
check('progress: ראיה עם נתיב מוחלט → 422', $st === 422);
check('progress: אחרי דחיות הקובץ לא השתנה', file_get_contents($fileA) === $snap);
check('progress: שיעור לא מסומן "הושלם" בלי אישור', $W(['action' => 'apply', 'learnerId' => 'lrn-a',
    'lessons' => ['l-1' => ['completed' => true, 'evidenceIds' => [], 'approvedBy' => null, 'approvedAt' => null]]])[0] === 422);
// תקין: הערכה עצמאית + ראיה מאומתת + אישור
[$st, $body] = $W(['action' => 'apply', 'learnerId' => 'lrn-a', 'evidence' => [$ev], 'assessments' => [['evidenceIds' => ['ev-1']] + $asm],
    'skills' => ['sk-1' => ['evidenceIds' => ['ev-1']] + $master['sk-1']]]);
check('progress: mastered תקין → 200, verified', $st === 200 && $body['progress']['skills']['sk-1']['category'] === 'verified'
    && $body['progress']['skills']['sk-1']['status'] === 'mastered', json_encode($body, JSON_UNESCAPED_UNICODE));
// התצוגה של הלומד: רק מאומת, בלי נתיב / מעריך / דיווחים
$evUnverified = ['id' => 'ev-2', 'kind' => 'code', 'reference' => 'evidence/lrn-a/draft.md', 'assessor' => 'מורה דוגמה', 'createdAt' => '2026-11-22T13:00:00Z'];
$asPlanned = ['id' => 'as-p', 'skillIds' => ['sk-3'], 'status' => 'planned', 'assistance' => 'hint', 'assessor' => 'מורה דוגמה', 'assessedAt' => null, 'evidenceIds' => []];
check('הוספת ראיה לא מאומתת והערכה מתוכננת', $W(['action' => 'apply', 'learnerId' => 'lrn-a', 'evidence' => [$evUnverified], 'assessments' => [$asPlanned]])[0] === 200);
[$st, $body] = $R('u-1');
$lv = $body['progress'];
$allKeys = [];
$walkK = function ($a) use (&$walkK, &$allKeys) { foreach ($a as $k => $v) { $allKeys[] = (string) $k; if (is_array($v)) $walkK($v); } };
$walkK($body);
$forbidden = ['reference', 'assessor', 'reports', 'assistanceLevel', 'reportAssistance', 'reportsByAssistance', 'pendingReports',
    'assistance', 'approvedBy', 'reporter', 'summary', 'evidenceIds', 'assessmentIds'];
check('לומד: אין מפתח אסור (רקורסיבי)', $st === 200 && !array_intersect($forbidden, $allKeys), implode(',', array_intersect($forbidden, $allKeys)));
$lvJson = json_encode($body, JSON_UNESCAPED_UNICODE);
check('לומד: אין נתיב ראיה ואין שם מעריך בשום ערך', !str_contains($lvJson, 'evidence/lrn-a') && !str_contains($lvJson, 'מורה דוגמה'));
check('לומד: רק הערכות מאומתות', array_column($lv['assessments'], 'id') === ['as-1']);
check('לומד: הערכה = id, skillIds, at, result, approvedAt', array_keys($lv['assessments'][0]) === ['id', 'skillIds', 'at', 'result', 'approvedAt']
    && $lv['assessments'][0]['result'] === ['sk-1' => 'mastered'] && $lv['assessments'][0]['at'] === '2026-11-22T11:00:00Z');
check('לומד: רק ראיות מאומתות', array_column($lv['evidence'], 'id') === ['ev-1']);
check('לומד: ראיה = id, kind, createdAt', array_keys($lv['evidence'][0]) === ['id', 'kind', 'createdAt']);
check('לומד: מיומנות = category, status, updatedAt', array_keys($lv['skills']['sk-1']) === ['category', 'status', 'updatedAt']);
[$st, $body] = $R('mgr-1', ['learner' => 'lrn-a']);
check('מנהל: התצוגה המלאה נשארה (כולל דיווחים)', $st === 200 && isset($body['progress']['reportsByAssistance']) && isset($body['progress']['skills']['sk-1']['pendingReports']));

// יומן ביקורת
$log = array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents("$pdir/audit.log"))));
$chg = array_values(array_filter($log, fn($e) => $e['action'] === 'skill_status'));
check('audit: שינוי סטטוס נרשם — מי, מתי, ממה למה, אילו ראיות', count($chg) === 1 && $chg[0]['by'] === 'mgr-1' && $chg[0]['at'] === '2026-11-23T12:00:00Z'
    && $chg[0]['from'] === 'not_assessed' && $chg[0]['to'] === 'mastered' && $chg[0]['evidenceIds'] === ['ev-1'] && $chg[0]['target'] === 'sk-1');
check('audit: גם רישום ודיווח נרשמו', (bool) array_filter($log, fn($e) => $e['action'] === 'report') && (bool) array_filter($log, fn($e) => $e['action'] === 'register'));
$rawLog = (string) file_get_contents("$pdir/audit.log");
check('audit: בלי תוכן ראיה (reference)', !str_contains($rawLog, 'evidence/lrn-a') && !str_contains($rawLog, 'reference'));
check('audit: שום שורה לדחיות', count(array_filter($log, fn($e) => $e['action'] === 'skill_status')) === 1);
check('progress: הרשאות קבצים 0640', (fileperms($fileA) & 0o777) === 0o640 && (fileperms("$pdir/audit.log") & 0o777) === 0o640);
check('תיקייה חוקית: הקבצים נוצרו שם', is_file("$pdir/learner-lrn-a.json") && is_file("$pdir/learners.json") && is_file("$pdir/audit.log"));
// כשל כתיבה — לא נשאר קובץ חלקי: יעד ה-rename הוא תיקייה, ולכן ההחלפה נכשלת
mkdir("$pdir/learner-lrn-c.json");
[$st, $body] = $W(['action' => 'report', 'learnerId' => 'lrn-c', 'report' => $rep]);
check('יעד שאינו קובץ → 503 (פגום, F1) בלי נתיב', $st === 503 && $body['code'] === 'progress_unavailable' && !str_contains(json_encode($body), $pdir));
check('כשל כתיבה → אין קובץ זמני שנשאר', glob("$pdir/*.tmp-*") === []);
$threw = false;
try { academy_progress_write_atomic("$pdir/learner-lrn-c.json", '{"x":1}'); } catch (AcademyProgressError $e) { $threw = $e->http === 500; }
check('כתיבה אטומית שנכשלת (rename) → חריגה, בלי קובץ זמני', $threw && glob("$pdir/*.tmp-*") === []);
rmdir("$pdir/learner-lrn-c.json");
// שגיאות בלי נתוני תלמיד: תוכן מזויף בקובץ לא תקין לא יוצא ללומד
file_put_contents("$pdir/learner-lrn-a.json", json_encode(['schema' => 'progress-1', 'learnerId' => 'lrn-a', 'updatedAt' => null, 'skills' => [],
    'lessons' => [], 'assessments' => [], 'evidence' => [['id' => 'ev-9', 'kind' => 'other', 'reference' => '/home/SECRET-STUDENT-NOTE', 'assessor' => 'x', 'createdAt' => '2026-01-01T00:00:00Z']], 'reports' => []]));
[$st, $body] = $R('u-1');
check('קובץ לא תקין → 422 ללומד בלי פירוט ובלי תוכן', $st === 422 && !isset($body['errors']) && !str_contains(json_encode($body, JSON_UNESCAPED_UNICODE), 'SECRET-STUDENT'));
array_map('unlink', glob("$pdir/*") ?: []); @rmdir($pdir);
// ---------- תיקוני ביקורת צוות 1 (F1–F10) ----------
$sdir = sys_get_temp_dir() . '/ca-audit-' . getmypid();
@mkdir($sdir, 0700); chmod($sdir, 0700);
$scfg = $mgrCfg(['progress_dir' => $sdir]);
$W2 = fn(array $body, string $sub = 'mgr-1', array $hdr = []) => academy_api_progress_write('POST', $scfg, 'application/json',
    json_encode($body, JSON_UNESCAPED_UNICODE), ['server' => ['PLATFORM_USER' => json_encode(['sub' => $sub, 'granted' => true])] + $hdr + $SAME, 'sapi' => 'litespeed', 'now' => '2026-11-23T12:00:00Z']);
$R2 = fn(string $sub, array $q = []) => academy_api_progress_read('GET', $scfg, $q, ['server' => ['PLATFORM_USER' => json_encode(['sub' => $sub, 'granted' => true])], 'sapi' => 'litespeed']);
$repN = fn(string $id) => ['id' => $id, 'at' => '2026-11-22', 'reporter' => 'מורה דוגמה', 'skillIds' => ['sk-1'], 'assistanceLevel' => 'unknown', 'summary' => 'דוגמה'];
check('F: רישום התחלתי', $W2(['action' => 'register', 'learnerId' => 'lrn-1', 'sub' => 'u-1'])[0] === 200
    && $W2(['action' => 'register', 'learnerId' => 'lrn-2', 'sub' => 'u-2'])[0] === 200);

// F1 — קובץ פגום: לא ריק, לא נדרס
$corrupt = '{"schema":"progress-1", "learnerId": "lrn-1", BROKEN';
file_put_contents("$sdir/learner-lrn-1.json", $corrupt);
[$st, $body] = $R2('mgr-1', ['learner' => 'lrn-1']);
check('F1: קריאה של קובץ פגום → 503, הסיבה למנהל', $st === 503 && $body['reason'] === 'progress_file_corrupt');
[$st, $body] = $R2('u-1');
check('F1: קריאה של קובץ פגום → 503 ללומד, בלי סיבה', $st === 503 && !isset($body['reason']));
foreach (['report' => ['report' => $repN('rp-c1')], 'apply' => ['skills' => ['sk-9' => ['status' => 'learning', 'updatedAt' => null, 'assessmentIds' => [], 'evidenceIds' => []]]]] as $act => $extra) {
    [$st, $body] = $W2(['action' => $act, 'learnerId' => 'lrn-1'] + $extra);
    check("F1: $act על קובץ פגום → 503, הקובץ לא נדרס", $st === 503 && $body['code'] === 'progress_unavailable' && file_get_contents("$sdir/learner-lrn-1.json") === $corrupt);
}
file_put_contents("$sdir/learner-lrn-1.json", '[1,2,3]');
check('F1: JSON שאינו אובייקט → 503, לא נדרס', $W2(['action' => 'report', 'learnerId' => 'lrn-1', 'report' => $repN('rp-c2')])[0] === 503
    && file_get_contents("$sdir/learner-lrn-1.json") === '[1,2,3]');
unlink("$sdir/learner-lrn-1.json");
$idxOk = file_get_contents("$sdir/learners.json");
file_put_contents("$sdir/learners.json", '{bad');
check('F1: learners.json פגום → קריאה 503 (לא "לא רשום")', $R2('u-1')[0] === 503);
check('F1: learners.json פגום → register 503, לא נדרס', $W2(['action' => 'register', 'learnerId' => 'lrn-3', 'sub' => 'u-3'])[0] === 503
    && file_get_contents("$sdir/learners.json") === '{bad');
file_put_contents("$sdir/learners.json", $idxOk);
check('F1: קובץ שאינו קיים — עדיין ריק, לא שגיאה', $R2('u-1')[0] === 200 && $R2('u-1')[1]['progress']['counts']['verified'] === 0);

// F2 — נעילה: כתיבות רצופות ומקבילות לא מאבדות זו את זו
check('F2: שתי כתיבות רצופות', $W2(['action' => 'report', 'learnerId' => 'lrn-1', 'report' => $repN('rp-s1')])[0] === 200
    && $W2(['action' => 'report', 'learnerId' => 'lrn-1', 'report' => $repN('rp-s2')])[0] === 200
    && count(academy_progress_load($sdir, 'lrn-1')['reports']) === 2);
$child = "$sdir/child.php";
file_put_contents($child, '<?php require ' . var_export(realpath(__DIR__ . '/../lib/progress-api.php'), true) . ';
[$cfgJson, $prefix, $n] = [$argv[1], $argv[2], (int) $argv[3]];
$cfg = json_decode($cfgJson, true);
for ($i = 0; $i < $n; $i++) {
    $r = ["id" => "$prefix-$i", "at" => "2026-11-22", "reporter" => "x", "skillIds" => ["sk-1"], "assistanceLevel" => "unknown", "summary" => "x"];
    [$st] = academy_api_progress_write("POST", $cfg, "application/json", json_encode(["action" => "report", "learnerId" => "lrn-2", "report" => $r]),
        ["server" => ["PLATFORM_USER" => json_encode(["sub" => "mgr-1", "granted" => true]), "HTTP_SEC_FETCH_SITE" => "same-origin"], "sapi" => "litespeed"]);
    if ($st !== 200) { fwrite(STDERR, "fail $st\n"); exit(1); }
}');
$procs = [];
foreach (['pa', 'pb', 'pc'] as $pfx) {
    $procs[] = proc_open([PHP_BINARY, $child, json_encode($scfg), $pfx, '20'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
}
$codes = array_map(fn($pr) => proc_close($pr), $procs);
$reportsB = academy_progress_load($sdir, 'lrn-2')['reports'] ?? [];
check('F2: 3 תהליכים × 20 כתיבות במקביל — אף דיווח לא אבד', $codes === [0, 0, 0] && count($reportsB) === 60, count($reportsB) . ' / codes ' . implode(',', $codes));
check('F2: יומן — 60 שורות report ל-lrn-2', count(array_filter(file("$sdir/audit.log"), fn($l) => str_contains($l, '"lrn-2"') && str_contains($l, '"report"'))) === 60);
unlink($child);

// F3 — כשל ביומן → השינוי לא נשמר
$beforeF3 = file_get_contents("$sdir/learner-lrn-1.json");
rename("$sdir/audit.log", "$sdir/audit.keep");
mkdir("$sdir/audit.log");
[$st, $body] = $W2(['action' => 'report', 'learnerId' => 'lrn-1', 'report' => $repN('rp-f3')]);
check('F3: יומן לא נכתב → 500, הקובץ לא השתנה', $st === 500 && file_get_contents("$sdir/learner-lrn-1.json") === $beforeF3);
[$st] = $W2(['action' => 'register', 'learnerId' => 'lrn-9', 'sub' => 'u-9']);
check('F3: register עם יומן שנכשל → 500, המיפוי לא נשמר', $st === 500 && !isset(academy_progress_index($sdir)['u-9']));
rmdir("$sdir/audit.log");
rename("$sdir/audit.keep", "$sdir/audit.log");
check('F3: אין @ שבולע את כתיבת היומן', !preg_match('/@file_put_contents\(\$f, \$lines/', (string) file_get_contents(__DIR__ . '/../lib/progress.php')));

// F4 — register לא דורס
[$st, $body] = $W2(['action' => 'register', 'learnerId' => 'lrn-2', 'sub' => 'u-1']);
check('F4: sub ממופה ללומד אחר → 409, בלי שינוי', $st === 409 && $body['code'] === 'conflict' && academy_progress_index($sdir)['u-1'] === 'lrn-1');
[$st] = $W2(['action' => 'register', 'learnerId' => 'lrn-1', 'sub' => 'u-new']);
check('F4: לומד שכבר ממופה ל-sub אחר → 409', $st === 409 && !isset(academy_progress_index($sdir)['u-new']));
check('F4: אותו מיפוי שוב → 200 בלי שינוי', $W2(['action' => 'register', 'learnerId' => 'lrn-1', 'sub' => 'u-1'])[1]['unchanged'] === true);
[$st] = $W2(['action' => 'register', 'learnerId' => 'lrn-2', 'sub' => 'u-1', 'force' => 'yes']);
check('F4: force שאינו true → עדיין 409', $st === 409);
[$st] = $W2(['action' => 'register', 'learnerId' => 'lrn-2', 'sub' => 'u-1', 'force' => true]);
$idxNow = academy_progress_index($sdir);
check('F4: force: true → המיפוי הוחלף, והמיפוי הישן של lrn-2 הוסר', $st === 200 && $idxNow['u-1'] === 'lrn-2' && !isset($idxNow['u-2']));
$regLog = array_values(array_filter(array_map(fn($l) => json_decode($l, true), file("$sdir/audit.log")), fn($e) => $e['action'] === 'register' && $e['to'] === 'lrn-2' && $e['target'] === 'u-1'));
check('F4: היומן מתעד את המקור (from, fromSub)', count($regLog) === 1 && $regLog[0]['from'] === 'lrn-1' && $regLog[0]['fromSub'] === 'u-2');

// F5 — רק same-origin
$noHdr = fn(array $hdr, string $sapi = 'litespeed') => academy_api_progress_write('POST', $scfg, 'application/json', json_encode(['action' => 'report', 'learnerId' => 'lrn-1', 'report' => $repN('rp-o')]),
    ['server' => ['PLATFORM_USER' => json_encode(['sub' => 'mgr-1', 'granted' => true])] + $hdr, 'sapi' => $sapi]);
$beforeF5 = file_get_contents("$sdir/learner-lrn-1.json");
foreach (['Origin זר' => ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_HOST' => 'mbe-plus.com'],
          'Origin תת-דומיין' => ['HTTP_ORIGIN' => 'https://x.mbe-plus.com', 'HTTP_HOST' => 'mbe-plus.com', 'HTTP_SEC_FETCH_SITE' => 'same-site'],
          'Sec-Fetch-Site cross-site' => ['HTTP_SEC_FETCH_SITE' => 'cross-site'],
          'Sec-Fetch-Site same-site' => ['HTTP_SEC_FETCH_SITE' => 'same-site'],
          'Origin null' => ['HTTP_ORIGIN' => 'null', 'HTTP_HOST' => 'mbe-plus.com'],
          'בלי כותרות בשרת' => []] as $lbl => $hdr) {
    check("F5: $lbl → 403", $noHdr($hdr)[0] === 403);
}
check('F5: אחרי כל הדחיות — הקובץ לא השתנה', file_get_contents("$sdir/learner-lrn-1.json") === $beforeF5);
check('F5: Origin תואם בלי Sec-Fetch-Site → עובר', $noHdr(['HTTP_ORIGIN' => 'https://mbe-plus.com', 'HTTP_HOST' => 'mbe-plus.com'])[0] === 200);
check('F5: בלי כותרות ב-CLI (בדיקות) → עובר', $noHdr([], 'cli')[0] !== 403);
check('F5: Content-Type application/json-foo → 415', academy_api_progress_write('POST', $scfg, 'application/json-foo', '{}', ['server' => $SAME, 'sapi' => 'litespeed'])[0] === 415);
check('F5: application/json; charset=utf-8 → מתקבל', academy_api_progress_write('POST', $scfg, 'application/json; charset=utf-8',
    json_encode(['action' => 'report', 'learnerId' => 'lrn-1', 'report' => $repN('rp-cs')]), ['server' => ['PLATFORM_USER' => json_encode(['sub' => 'mgr-1', 'granted' => true])] + $SAME, 'sapi' => 'litespeed'])[0] === 200);

// F6 — json_encode נבדק
$threw = false;
try { academy_progress_encode(['x' => "\xB1\x31"]); } catch (AcademyProgressError $e) { $threw = true; }
check('F6: קידוד שנכשל → חריגה (לא קובץ ריק)', $threw);
// F7 — assessmentId מחרוזת
$asmA = ['id' => 'as-1', 'skillIds' => ['sk-2'], 'status' => 'evaluated', 'assistance' => 'hint', 'assessor' => 'x', 'assessedAt' => '2026-11-22T11:00:00Z',
    'evidenceIds' => [], 'approvedBy' => 'role-manager', 'approvedAt' => '2026-11-22T12:00:00Z'];
$W2(['action' => 'apply', 'learnerId' => 'lrn-1', 'assessments' => [$asmA], 'skills' => ['sk-2' => ['status' => 'theory_demonstrated', 'updatedAt' => null, 'assessmentIds' => ['as-1'], 'evidenceIds' => []]]]);
$st7 = array_values(array_filter(array_map(fn($l) => json_decode($l, true), file("$sdir/audit.log")), fn($e) => $e['action'] === 'skill_status' && $e['target'] === 'sk-2'));
check('F7: assessmentId ביומן — מחרוזת', count($st7) === 1 && $st7[0]['assessmentId'] === 'as-1');
// F9 — learnerId לא נכתב ללוג
$elog = "$sdir/php-error.log";
$oldLog = ini_set('error_log', $elog);
file_put_contents("$sdir/learner-lrn-2.json", json_encode(['schema' => 'progress-1', 'learnerId' => 'lrn-2', 'updatedAt' => null, 'skills' => ['sk-1' => ['status' => 'mastered', 'updatedAt' => null, 'assessmentIds' => [], 'evidenceIds' => []]],
    'lessons' => [], 'assessments' => [], 'evidence' => [], 'reports' => []]));
$R2('u-1');
ini_set('error_log', (string) $oldLog);
$logText = (string) @file_get_contents($elog);
check('F9: נרשם ללוג, בלי learnerId', str_contains($logText, 'אינו תקין') && !str_contains($logText, 'lrn-2'));
@unlink($elog);
// F10 — audit.log נוצר 0640 גם עם umask פתוח
$fdir = "$sdir/f10"; mkdir($fdir, 0700); chmod($fdir, 0700);
$oldMask = umask(0);
academy_progress_audit($fdir, [['at' => 'x', 'action' => 'register']]);
umask($oldMask);
check('F10: audit.log נוצר 0640 (umask 0)', (fileperms("$fdir/audit.log") & 0o777) === 0o640);
unlink("$fdir/audit.log"); rmdir($fdir);
check('F: אין קובצי זמני / נעילה מיותרים', glob("$sdir/*.tmp-*") === []);
array_map('unlink', array_merge(glob("$sdir/*") ?: [], glob("$sdir/.lock") ?: [])); @rmdir($sdir);
array_map('unlink', glob("$pdir/.lock") ?: []); @rmdir($pdir);

// הריפו לא השתנה, ואין בו קובץ נתוני לומד
check('הבדיקות לא יצרו שום קובץ בריפו', $listRepo() === $repoBefore, implode(', ', array_diff($listRepo(), $repoBefore)));
$learnerFiles = array_filter($listRepo(), fn($f) => preg_match('~/(learner-[^/]+\.json|learners\.json|audit\.log)$~', $f));
check('אין קובצי לומד בעץ הריפו', $learnerFiles === [], implode(', ', $learnerFiles));

// תוכנית האקדמיה לא מחזירה שדה לומד
[$st, $body] = academy_api_curriculum('GET', $cfg, new GithubAcademySource($gh, "$tmp/cache", 0, gh_stub(200, json_encode($appr))));
$keys = [];
array_walk_recursive($body, function ($v, $k) use (&$keys) { $keys[] = (string) $k; });
$walk = function ($a) use (&$walk, &$keys) { foreach ($a as $k => $v) { $keys[] = (string) $k; if (is_array($v)) $walk($v); } };
$walk($body);
check('curriculum.php: אין מפתח של לומד (קטע)', $st === 200 && !array_intersect(array_unique($keys),
    ['learnerId', 'learner', 'progress', 'competencies', 'assessments', 'evidence', 'reports', 'skillsProgress', 'measure', 'competencyStatus']), implode(',', array_unique($keys)));
$keys = [];
[$st, $body] = academy_api_curriculum('GET', $cfg, new GithubAcademySource($gh, "$tmp/cache", 0, gh_stub(200, json_encode($full2))));
$walk($body);
check('curriculum.php: עם מיומנויות — עדיין בלי מפתח לומד', $st === 200 && count($body['curriculum']['modules'][0]['skills']) > 0 && !array_intersect(array_unique($keys),
    ['learnerId', 'learner', 'progress', 'competencies', 'assessments', 'evidence', 'reports', 'skillsProgress', 'measure', 'competencyStatus']), implode(',', array_unique($keys)));
check('curriculum.php לא נוגע ב-progress', !str_contains((string) file_get_contents(__DIR__ . '/../lib/api.php'), 'progress'));
check('progress לא נוגע ב-GitHub', !preg_match('/GithubAcademySource|api\.github\.com|academy_source\(/', (string) file_get_contents(__DIR__ . '/../lib/progress.php') . file_get_contents(__DIR__ . '/../lib/progress-api.php')));

// ---------- הרשאות ----------
check('viewer קורא', academy_can('snapshot.read', 'viewer'));
check('viewer לא כותב', !academy_can('snapshot.write', 'viewer'));
check('תפקיד לא מוכר — כלום', !academy_can('snapshot.read', 'nobody'));
check('viewer בלי שום הרשאת כתיבה', !preg_grep('/write|edit|delete/', ACADEMY_ROLES['viewer']));
check('הרשאת הכתיבה היחידה במערכת: progress.write של manager', array_values(preg_grep('/write|edit|delete/', array_merge(...array_values(ACADEMY_ROLES)))) === ['progress.write']
    && in_array('progress.write', ACADEMY_ROLES['manager'], true));

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
