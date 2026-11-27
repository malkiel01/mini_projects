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

$ac = fn(array $checks) => array_map(fn($c) => $c + ['status' => 'unknown', 'value' => '', 'source' => '', 'note' => ''], $checks);   // כמו אחרי normalize()

// ---- B1: מהממשק — by/via/at רק לבדיקה ששונתה; by/via/at מהקלט לא נכנסים (normalize זורק אותם; כאן — לא מועתקים)
$first = formApplyChecks($ac(['license_number' => ['status' => 'document_present', 'value' => '1'], 'hall_service' => []]), null, 'ui', 'a@example.com');
ok(($first['license_number']['via'] ?? '') === 'ui' && $first['license_number']['by'] === 'a@example.com', 'B1: via=ui, by מהשרת');
ok(preg_match('/^\d{4}-\d\d-\d\dT/', $first['license_number']['at'] ?? '') === 1, 'B1: at');
ok(!isset($first['hall_service']['via']), 'B1: בדיקה ריקה שלא נגעו בה — בלי מקור');
ok($first['license_number']['source'] === 'עדכון ידני', 'B1: source "עדכון ידני" כמו קודם (הגנה מייבוא חוזר)');
$second = formApplyChecks($ac(['license_number' => ['status' => 'document_present', 'value' => '1'], 'identity_document' => ['status' => 'verified', 'value' => 'ת"ז']]),
    $first, 'ui', 'b@example.com', '2026-11-24T10:00:00+00:00');
ok($second['license_number'] === $first['license_number'], 'B1: בדיקה שלא שונתה — המקור הישן נשאר');
ok($second['identity_document']['by'] === 'b@example.com' && $second['identity_document']['at'] === '2026-11-24T10:00:00+00:00', 'B1: רק השונה מקבל מקור חדש');
$n = normalize(['month' => '2026-10', 'name' => 'x', 'checks' => ['a_b' => ['status' => 'verified', 'by' => 'h', 'via' => 'ai', 'at' => '1999']]]);
ok(array_keys($n['checks']['a_b']) === ['status', 'value', 'source', 'note'], 'B1: normalize() זורק by/via/at מהקלט');

// ---- B2: מקור אוטומטי שמסמן "הושלם" → document_present, וההצעה ב-note
foreach (['api', 'ai', 'dropbox'] as $via) {
    $c = formApplyChecks($ac(['license_number' => ['status' => 'verified', 'value' => '12345', 'note' => 'מהקובץ X']]), null, $via, 'gpt')['license_number'];
    ok($c['status'] === 'document_present' && $c['via'] === $via, "B2($via): נשמר document_present");
    ok(str_contains($c['note'], 'מאומת') && str_contains($c['note'], '12345') && str_contains($c['note'], 'מהקובץ X'), "B2($via): הערך המוצע ב-note");
    $c = formApplyChecks($ac(['k_k' => ['status' => 'not_applicable']]), null, $via, 'gpt')['k_k'];
    ok($c['status'] === 'document_present', "B2($via): not_applicable → לאימות");
    $c = formApplyChecks($ac(['k_k' => ['status' => 'missing']]), null, $via, 'gpt')['k_k'];
    ok($c['status'] === 'missing', "B2($via): סטטוס לא סופי — כמו שהוא");
}
// אדם כבר סגר — מקור אוטומטי לא פותח מחדש ולא מחליף ערך; ההצעה בהערה
$human = formApplyChecks($ac(['license_number' => ['status' => 'verified', 'value' => '111']]), null, 'ui', 'human');
$c = formApplyChecks($ac(['license_number' => ['status' => 'verified', 'value' => '222']]), $human, 'api', 'gpt')['license_number'];
ok($c['status'] === 'verified' && $c['value'] === '111' && $c['by'] === 'human' && str_contains($c['note'], '222'), 'B2: אדם אישר — API לא דורס');

// ---- B3: ממשק / שטח / ייבוא → verified
foreach (['ui', 'field', 'import'] as $via) {
    $c = formApplyChecks($ac(['k_k' => ['status' => 'verified', 'value' => '9']]), null, $via, 'x')['k_k'];
    ok($c['status'] === 'verified' && $c['via'] === $via, "B3($via): verified");
}
$threw = false;
try { formApplyChecks([], null, 'hacker', 'x'); } catch (InvalidArgumentException) { $threw = true; }
ok($threw, 'via לא מוכר נדחה');
ok(formEventSource('field') === 'ui' && formEventSource('ai') === 'api' && formEventSource('dropbox') === 'system' && formEventSource('import') === 'import', 'via → מקור אירוע של 01');

// ---- B6: היסטוריה מ-events — 3 אחרונים בסדר הפוך, עם מקור; ארגון אחר → null (404)
$pdo = mem(true);
$pdo->exec('CREATE TABLE events (id INTEGER PRIMARY KEY AUTOINCREMENT, org_id INTEGER, case_id TEXT, at TEXT, actor_user_id INTEGER, actor TEXT, source TEXT, action TEXT, diff TEXT)');
$pdo->prepare('INSERT INTO funerals(id,month,name,body,updated_at,org_id) VALUES(?,?,?,?,?,?)')->execute([$base['id'], '2026-10', 'x', '{}', 'now', 1]);
$ins = $pdo->prepare('INSERT INTO events(org_id,case_id,at,actor,source,action,diff) VALUES(1,?,?,?,?,?,?)');
$ins->execute([$base['id'], '2026-10-01T10:00:00Z', 'a', 'import', 'import', '{"name":["",  "א"]}']);
$ins->execute([$base['id'], '2026-10-02T10:00:00Z', 'b', 'ui', 'save', '{"checks.license_number.status":["unknown","verified"]}']);
$ins->execute([$base['id'], '2026-10-03T10:00:00Z', 'c', 'api', 'save', '{"notes":{"from":"","to":"הערה"}}']);
$ins->execute([$base['id'], '2026-10-04T10:00:00Z', 'd', 'ui', 'save', '{"checks.hall_service":[null,{"status":"missing","value":"","note":""}]}']);
$ins->execute(['fd_other_case_000', '2026-10-05T10:00:00Z', 'z', 'ui', 'save', '{}']);
$h = formHistory($pdo, $base['id'], 1);
ok(is_array($h) && count($h) === 4, 'B6: 4 אירועים לתיק (ולא של תיק אחר)');
ok(array_column(array_slice($h, 0, 3), 'actor') === ['d', 'c', 'b'], 'B6: שלושת האחרונים בסדר הפוך');
ok(array_column(array_slice($h, 0, 3), 'source') === ['ui', 'api', 'ui'], 'B6: עם מקור');
ok($h[0]['changes'] === [['field' => 'checks.hall_service.status', 'before' => null, 'after' => 'missing']], 'B6: diff של בדיקה (caseDiff של 01)');
ok($h[1]['changes'] === [['field' => 'notes', 'before' => '', 'after' => 'הערה']], 'B6: diff from/to');
ok($h[2]['changes'] === [['field' => 'checks.license_number.status', 'before' => 'unknown', 'after' => 'verified']], 'B6: diff [לפני,אחרי]');
ok(formDiffChanges(['payments' => [[['amount' => 402]], [['amount' => 402.0]]], 'checks.x' => [null, ['status' => 'unknown', 'value' => '', 'note' => '']]]) === [], 'B6: בלי רעש (402 מול 402.0, בדיקה ריקה חדשה)');
ok(formHistory($pdo, $base['id'], 2) === null, 'B6: תיק של ארגון אחר → null (404)');
ok(formHistory($pdo, 'fd_no_such_case_0', 1) === null, 'B6: תיק שלא קיים → null');
for ($i = 0; $i < 60; $i++) $ins->execute([$base['id'], '2026-11-01T00:00:00Z', 'n' . $i, 'ui', 'save', '{}']);
ok(count(formHistory($pdo, $base['id'], 1)) === 50, 'B6: 50 אחרונים בלבד');
// בלי events — היסטוריה מ-audit, בלי לקרוס
$pdo = mem();
$pdo->exec("INSERT INTO funerals VALUES ('{$base['id']}','2026-10','x','{}','now')");
$pdo->exec("INSERT INTO audit(funeral_id,at,actor,action) VALUES ('{$base['id']}','2026-11-01','human','save')");
$h = formHistory($pdo, $base['id'], null);
ok(is_array($h) && count($h) === 1 && $h[0]['actor'] === 'human' && $h[0]['action'] === 'save', 'B6: בלי events — מ-audit');

// ---- B7: 22 תיקים בצורה הישנה (בלי by/via/at) — normalize עובר, ושמירה בלי שינוי לא מוסיפה מקור
$legacy = json_decode((string)file_get_contents(__DIR__ . '/fixtures/legacy-cases.json'), true);
ok(is_array($legacy) && count($legacy) === 22, 'B7: 22 תיקים בקובץ');
foreach ($legacy as $rec) {
    $item = normalize($rec);
    $checks = formApplyChecks($item['checks'], $rec['checks'], 'ui', 'human');
    foreach ($checks as $k => $c) ok(!isset($c['via']), "B7: {$rec['id']}.$k — בלי שינוי, בלי מקור חדש");
}

if ($fails) { fwrite(STDERR, "form.test.php: $fails נכשלו\n"); exit(1); }
echo "PASS form.test.php: finalAllowed, B1 (מקור רק לבדיקה ששונתה, קלט מתעלם), B2 (api/ai/dropbox → לאימות + הצעה בהערה), B3 (ui/field/import → verified), B6 (היסטוריה הפוכה עם מקור, ארגון אחר → 404, 50), B7 (22 תיקים ישנים)\n";
