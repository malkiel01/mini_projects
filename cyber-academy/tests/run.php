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
