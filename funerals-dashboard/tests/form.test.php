<?php
declare(strict_types=1);
/**
 * פרוסה 02 — בדיקות יחידה ל-lib/form.php (B1, B2, B3, B6, B7 בצד השרת). רץ מ-tests/run.sh: php tests/form.test.php
 * מסד בזיכרון בלבד — לא נוגע ב-data/.
 */
require __DIR__ . '/../lib.php';

$fails = 0;
function ok(bool $cond, string $what): void {
    global $fails;
    if (!$cond) { $fails++; fwrite(STDERR, "FAIL: $what\n"); }
}
function mem(bool $withOrgs = false): PDO {
    $p = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $p->exec('CREATE TABLE funerals (id TEXT PRIMARY KEY, month TEXT NOT NULL, name TEXT NOT NULL, body TEXT NOT NULL, updated_at TEXT NOT NULL' . ($withOrgs ? ', org_id INTEGER' : '') . ')');
    $p->exec('CREATE TABLE audit (id INTEGER PRIMARY KEY AUTOINCREMENT, funeral_id TEXT NOT NULL, at TEXT NOT NULL, actor TEXT NOT NULL, action TEXT NOT NULL)');
    return $p;
}
$base = ['id' => 'fd_test_case_0001', 'month' => '2026-10', 'name' => 'בדיקה', 'date' => '2026-10-01'];

// ---- finalAllowed (הכרעה ה7)
foreach (['ui' => true, 'field' => true, 'import' => true, 'dropbox' => false, 'api' => false, 'ai' => false, 'x' => false] as $via => $want) {
    ok(finalAllowed($via) === $want, "finalAllowed($via)");
}

// ---- B1: שמירה מהממשק — by/via/at רק לבדיקה ששונתה; קלט עם by/via/at מתעלם מהם
$pdo = mem();
$first = formSaveCase($pdo, $base + ['checks' => [
    'license_number' => ['status' => 'document_present', 'value' => '1'],
    'hall_service' => ['status' => 'unknown', 'value' => '', 'note' => ''],
]], 'ui', 'a@example.com');
ok(($first['checks']['license_number']['via'] ?? '') === 'ui', 'B1: via=ui לבדיקה שנקבעה');
ok(($first['checks']['license_number']['by'] ?? '') === 'a@example.com', 'B1: by מהשרת');
ok(preg_match('/^\d{4}-\d\d-\d\dT/', $first['checks']['license_number']['at'] ?? '') === 1, 'B1: at');
ok(!isset($first['checks']['hall_service']['via']), 'B1: בדיקה ריקה שלא נגעו בה — בלי מקור');
$before = $first['checks']['license_number'];
$second = formSaveCase($pdo, $base + ['checks' => [
    'license_number' => ['status' => 'document_present', 'value' => '1', 'by' => 'hacker', 'via' => 'import', 'at' => '1999-01-01'],
    'identity_document' => ['status' => 'verified', 'value' => 'ת"ז', 'by' => 'hacker', 'via' => 'ai', 'at' => '1999-01-01'],
]], 'ui', 'b@example.com');
ok($second['checks']['license_number'] === $before, 'B1: בדיקה שלא שונתה נשארה עם המקור הישן (וקלט by/via/at התעלם)');
$c = $second['checks']['identity_document'];
ok($c['status'] === 'verified' && $c['by'] === 'b@example.com' && $c['via'] === 'ui' && $c['at'] !== '1999-01-01', 'B1: קלט by/via/at לא נשמר');
ok($c['source'] === 'עדכון ידני', 'B1: source "עדכון ידני" נשמר כמו קודם (הגנה מייבוא)');
$stored = existing($pdo, $base['id']);
ok(($stored['checks']['identity_document']['via'] ?? '') === 'ui', 'B1: נשמר במסד');

// ---- B2: via=api שמסמנת verified → document_present, וההצעה ב-note
foreach (['api', 'ai', 'dropbox'] as $via) {
    $pdo = mem();
    $r = formSaveCase($pdo, $base + ['checks' => ['license_number' => ['status' => 'verified', 'value' => '12345', 'note' => 'מהקובץ X']]], $via, 'gpt@example.com');
    $c = $r['checks']['license_number'];
    ok($c['status'] === 'document_present', "B2($via): נשמר document_present");
    ok(str_contains($c['note'], 'מאומת') && str_contains($c['note'], '12345') && str_contains($c['note'], 'מהקובץ X'), "B2($via): הערך המוצע ב-note");
    ok($c['via'] === $via, "B2($via): via נשמר");
    $r = formSaveCase($pdo, $base + ['checks' => ['license_number' => ['status' => 'not_applicable']]], $via, 'gpt');
    ok($r['checks']['license_number']['status'] === 'document_present', "B2($via): not_applicable → לאימות");
    $r = formSaveCase($pdo, $base + ['checks' => ['license_number' => ['status' => 'missing']]], $via, 'gpt');
    ok($r['checks']['license_number']['status'] === 'missing', "B2($via): סטטוס לא סופי נשמר כמו שהוא");
}
// מקור אוטומטי לא פותח מחדש בדיקה שאדם סגר
$pdo = mem();
formSaveCase($pdo, $base + ['checks' => ['license_number' => ['status' => 'verified', 'value' => '111']]], 'ui', 'human');
$r = formSaveCase($pdo, $base + ['checks' => ['license_number' => ['status' => 'verified', 'value' => '222']]], 'api', 'gpt');
$c = $r['checks']['license_number'];
ok($c['status'] === 'verified' && $c['value'] === '111' && $c['by'] === 'human' && str_contains($c['note'], '222'), 'B2: אדם אישר — API לא דורס, ההצעה בהערה');

// ---- B3: אותו מעבר בממשק או בייבוא → verified
$pdo = mem();
$r = formSaveCase($pdo, $base + ['checks' => ['license_number' => ['status' => 'verified', 'value' => '1']]], 'ui', 'human');
ok($r['checks']['license_number']['status'] === 'verified', 'B3: ui → verified');
$r = formSaveCase($pdo, $base + ['checks' => ['license_number' => ['status' => 'verified', 'value' => '1']]], 'field', 'field-worker');
ok($r['checks']['license_number']['status'] === 'verified', 'B3: field → verified');
$c = formApplyChecks(['x_key' => ['status' => 'verified', 'value' => '9', 'source' => '', 'note' => '']], null, 'import', 'api');
ok($c['x_key']['status'] === 'verified' && $c['x_key']['via'] === 'import' && $c['x_key']['by'] === 'api', 'B3: import → verified, via=import');
// מקור לא מוכר — חריגה, לא שמירה שקטה
$threw = false;
try { formApplyChecks([], null, 'hacker', 'x'); } catch (InvalidArgumentException) { $threw = true; }
ok($threw, 'via לא מוכר נדחה');

// ---- B6: היסטוריה מ-events — 3 אחרונים בסדר הפוך, עם מקור; ארגון אחר → null (404)
$pdo = mem(true);
$pdo->exec('CREATE TABLE events (id INTEGER PRIMARY KEY AUTOINCREMENT, org_id INTEGER, case_id TEXT, at TEXT, actor_user_id INTEGER, actor TEXT, source TEXT, action TEXT, diff TEXT)');
$pdo->prepare('INSERT INTO funerals(id,month,name,body,updated_at,org_id) VALUES(?,?,?,?,?,?)')->execute([$base['id'], '2026-10', 'x', '{}', 'now', 1]);
$ins = $pdo->prepare('INSERT INTO events(org_id,case_id,at,actor,source,action,diff) VALUES(1,?,?,?,?,?,?)');
$ins->execute([$base['id'], '2026-10-01T10:00:00Z', 'a', 'import', 'import', '{"name":["",  "א"]}']);
$ins->execute([$base['id'], '2026-10-02T10:00:00Z', 'b', 'ui', 'save', '{"checks.license_number.status":["unknown","verified"]}']);
$ins->execute([$base['id'], '2026-10-03T10:00:00Z', 'c', 'api', 'save', '{"notes":{"from":"","to":"הערה"}}']);
$ins->execute([$base['id'], '2026-10-04T10:00:00Z', 'd', 'ui', 'save', '{"checks":{"hall_service":{"status":["unknown","missing"]}}}']);
$ins->execute(['fd_other_case_000', '2026-10-05T10:00:00Z', 'z', 'ui', 'save', '{}']);
$h = formHistory($pdo, $base['id'], 1);
ok(is_array($h) && count($h) === 4, 'B6: 4 אירועים לתיק (ולא של תיק אחר)');
ok(array_column(array_slice($h, 0, 3), 'actor') === ['d', 'c', 'b'], 'B6: שלושת האחרונים בסדר הפוך');
ok(array_column(array_slice($h, 0, 3), 'source') === ['ui', 'api', 'ui'], 'B6: עם מקור');
ok($h[0]['changes'] === [['field' => 'checks.hall_service.status', 'before' => 'unknown', 'after' => 'missing']], 'B6: diff מקונן');
ok($h[1]['changes'] === [['field' => 'notes', 'before' => '', 'after' => 'הערה']], 'B6: diff from/to');
ok($h[2]['changes'] === [['field' => 'checks.license_number.status', 'before' => 'unknown', 'after' => 'verified']], 'B6: diff [לפני,אחרי]');
ok(formHistory($pdo, $base['id'], 2) === null, 'B6: תיק של ארגון אחר → null (404)');
ok(formHistory($pdo, 'fd_no_such_case_0', 1) === null, 'B6: תיק שלא קיים → null');
for ($i = 0; $i < 60; $i++) $ins->execute([$base['id'], '2026-11-01T00:00:00Z', 'n' . $i, 'ui', 'save', '{}']);
ok(count(formHistory($pdo, $base['id'], 1)) === 50, 'B6: 50 אחרונים בלבד');
// לפני 01: אין events — היסטוריה מ-audit, בלי לקרוס
$pdo = mem();
formSaveCase($pdo, $base, 'ui', 'human');
$h = formHistory($pdo, $base['id'], null);
ok(is_array($h) && count($h) === 1 && $h[0]['actor'] === 'human' && $h[0]['action'] === 'save', 'B6: בלי events — מ-audit');

// ---- B7: 22 תיקים בצורה הישנה (בלי by/via/at) — נשמרים בלי שגיאה, בדיקה שלא נגעו בה נשארת "בלי מקור"
$pdo = mem();
$legacy = json_decode((string)file_get_contents(__DIR__ . '/fixtures/legacy-cases.json'), true);
ok(is_array($legacy) && count($legacy) === 22, 'B7: 22 תיקים בקובץ');
foreach ($legacy as $i => $rec) {
    $pdo->prepare('INSERT INTO funerals(id,month,name,body,updated_at) VALUES(?,?,?,?,?)')
        ->execute([$rec['id'], $rec['month'], $rec['name'], json_encode($rec, JSON_UNESCAPED_UNICODE), 'old']);
}
foreach ($legacy as $rec) {
    $r = formSaveCase($pdo, $rec, 'ui', 'human');       // שמירה בלי שינוי, כמו "שמירה" על תיק שנפתח
    foreach ($r['checks'] as $k => $c) ok(!isset($c['via']), "B7: {$rec['id']}.$k — בלי שינוי, בלי מקור חדש");
    ok(formHistory($pdo, $rec['id'], null) !== null, "B7: היסטוריה ל-{$rec['id']}");
}

if ($fails) { fwrite(STDERR, "form.test.php: $fails נכשלו\n"); exit(1); }
echo "PASS form.test.php: finalAllowed, B1 (מקור רק לבדיקה ששונתה, קלט מתעלם), B2 (api/ai/dropbox → לאימות + הצעה בהערה), B3 (ui/field/import → verified), B6 (היסטוריה הפוכה עם מקור, ארגון אחר → 404, 50), B7 (22 תיקים ישנים)\n";
