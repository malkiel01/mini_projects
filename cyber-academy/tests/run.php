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
$tmp = sys_get_temp_dir() . '/ca-test-' . getmypid();
@mkdir($tmp);
file_put_contents("$tmp/config.json", json_encode(['source' => 'github', 'github_enabled' => true, 'privacy_confirmed' => true,
    'github' => ['token' => 'github_pat_SECRETSECRETSECRET1234567890']]));
$cfg = academy_load_config("$tmp/config.json");
check('קונפיג נטען על גבי ברירת מחדל', $cfg['github']['repo'] === 'offensive-cyber-academy' && $cfg['github']['token'] !== '');
file_put_contents("$tmp/bad.json", '{not json');
check('קונפיג פגום → ברירת מחדל', @academy_load_config("$tmp/bad.json") === $def);
check('קונפיג חסר → ברירת מחדל', academy_load_config("$tmp/missing.json") === $def);
check('local מסומן דוגמה', (new LocalAcademySource())->isSample() === true && (new LocalAcademySource())->name() === 'local');

// ---------- האסימון לא יוצא ----------
$token = 'github_pat_SECRETSECRETSECRET1234567890';
[$st, $body] = academy_api_snapshot($cfg);
$out = json_encode($body, JSON_UNESCAPED_UNICODE);
check('API: 200 עם נתוני דוגמה', $st === 200 && $body['ok'] === true);
check('API: source + isSample', $body['source'] === 'local' && $body['isSample'] === true);
check('API: האסימון לא בפלט', !str_contains($out, $token) && !str_contains($out, 'SECRETSECRET'));
check('API: אין שדה token בפלט', !str_contains($out, '"token"'));
[$st, $body] = academy_api_snapshot($cfg, ['demo' => 'empty']);
check('API: demo=empty → מודל ריק', $st === 200 && $body['model']['empty'] === true && $body['isSample'] === true);
[$st, $body] = academy_api_snapshot($def, [], new FixedSource(fixture('broken/bad-competency-status.json')));
check('API: snapshot שבור → 422 עם errors', $st === 422 && $body['ok'] === false && $body['errors'] !== [] && !isset($body['model']));

// מקור github עם transport מזויף — הודעות שגיאה ופלט בלי אסימון
$fake = fn(int $status, string $b, ?array &$seen = null) => function (string $url, array $headers) use ($status, $b, &$seen) {
    $seen = ['url' => $url, 'headers' => $headers];
    return ['status' => $status, 'body' => $b];
};
$gh = ['owner' => 'malkiel01', 'repo' => 'offensive-cyber-academy', 'ref' => 'main', 'path' => 'dashboard/data/snapshot.json', 'token' => $token];
$seen = null;
$src = new GithubAcademySource($gh, "$tmp/cache", 0, $fake(200, json_encode($valid), $seen));
check('github: לא דוגמה', $src->isSample() === false && $src->name() === 'github');
check('github: קורא snapshot', $src->fetch() === $valid);
check('github: GET ל-Contents API של המאגר', str_starts_with($seen['url'], 'https://api.github.com/repos/malkiel01/offensive-cyber-academy/contents/dashboard/data/snapshot.json?ref=main'));
check('github: האסימון רק בכותרת Authorization', in_array("Authorization: Bearer $token", $seen['headers'], true) && !str_contains($seen['url'], $token));
foreach ([401, 403, 404, 500] as $code) {
    $src = new GithubAcademySource($gh, "$tmp/cache", 0, $fake($code, '{"message":"bad ' . $token . '"}'));
    [$st, $body] = academy_api_snapshot($cfg, [], $src);
    $out = json_encode($body, JSON_UNESCAPED_UNICODE);
    check("github $code: 502 בלי אסימון", $st === 502 && !str_contains($out, $token), $out);
}
$src = new GithubAcademySource($gh, "$tmp/cache", 0, function () use ($token) { throw new RuntimeException("curl failed for $token"); });
[$st, $body] = academy_api_snapshot($cfg, [], $src);
check('github: חריגה עם אסימון בהודעה — מוסתר', $st === 502 && !str_contains(json_encode($body, JSON_UNESCAPED_UNICODE), $token));
$src = new GithubAcademySource(['token' => ''] + $gh, "$tmp/cache", 0, $fake(200, '{}'));
try { $src->fetch(); check('github: בלי אסימון — נכשל', false); } catch (AcademySourceError $e) { check('github: בלי אסימון — נכשל', true); }
$src = new GithubAcademySource(['path' => '../../etc/passwd'] + $gh, "$tmp/cache", 0, $fake(200, '{}'));
try { $src->fetch(); check('github: נתיב עם .. נדחה', false); } catch (AcademySourceError $e) { check('github: נתיב עם .. נדחה', true); }
// מטמון: פנייה שנייה לא יוצאת לרשת
$calls = 0;
$counting = function () use (&$calls, $valid) { $calls++; return ['status' => 200, 'body' => json_encode($valid)]; };
$src = new GithubAcademySource($gh, "$tmp/cache", 60, $counting);
$src->fetch(); $src->fetch();
check('github: מטמון קצר', $calls === 1);
check('github: האסימון לא במטמון', !str_contains(implode('', array_map('file_get_contents', glob("$tmp/cache/*"))), $token));
check('redact: תבנית ghp_ מוסתרת גם בלי קונפיג', !str_contains(academy_redact('x ghp_ABCDEFGHIJKLMNOPQRSTUVWX y', ''), 'ghp_'));

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
