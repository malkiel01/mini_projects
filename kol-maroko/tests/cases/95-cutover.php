<?php
/**
 * פרוסה M3 — המעבר (lib/cutover.php). כל בדיקה מול שורה ב-docs/spec-v2/slices/M3-cutover.md §2–§3:
 *   א. cutover_eval — כל תנאי ✓ / ✗ / ⏳ מול עובדות בדיקה (הקלטות חסרות, אין v2, תוסף 1.x, נקודת שחזור ישנה…), ו"מוכן" רק בכולם ✓.
 *   ב. הרשאות בשרת: קריאה — מפרסם; כל שינוי — בעלים. עורך / מפרסם / זר — נדחים.
 *   ג. ראיה: אישור, קישור http(s) בלבד, קובץ קיים; ביטול; ✓ שנפל — אדום, הודעה לבעלים ושורה ביומן.
 *   ד. "מוכן למעבר" — נדחה כשלא הכול ✓; המטלה נפתחת פעם אחת, עם צעדי §2 וכלל הכיבוי. המפסק — נקרא בלבד.
 *   ה. ה-ping האמיתי מול tests/fake-wp.php: תוסף 1.x ← ✗; המקבל המדומה v2 ← protocol 2.
 */

declare(strict_types=1);

require_once KOL_ROOT . '/lib/cutover.php';

const M3_OWNER = ['sub' => 'u-owner', 'name' => 'בעלים בדיקה', 'role' => 'site_owner'];

/** עובדות שבהן הכול ✓; כל בדיקה משנה שדה אחד. */
function m3_facts(array $over = []): array
{
    $now = time();
    $at = gmdate('c', $now - 3600);
    $ev = fn() => [['id' => 1, 'note' => 'עבר', 'url' => '', 'file' => null, 'by' => 'בעלים', 'at' => $at]];
    $f = [
        'now' => $now,
        'rec' => ['total' => 1494, 'tool' => 1494, 'missing' => [], 'missing_n' => 0, 'images' => []],
        'build' => ['version' => 7, 'sent_at' => $at, 'sent_by' => 'מפרסם', 'rev' => 40, 'current' => true],
        'rev' => 40,
        'ping' => ['plugin' => '2.0.0', 'protocol' => 2, 'v2_live' => 7, 'v2_on' => false, 'stats' => null],
        'feedback' => ['inbox' => ['ok' => true, 'detail' => ''], 'forms' => ['feedback'], 'live' => ['feedback']],
        'snap' => ['id' => 3, 'at' => $at],
        'snap_verified' => null,
        'evidence' => array_fill_keys(CUTOVER_EVIDENCE, null),
    ];
    foreach (CUTOVER_EVIDENCE as $k) $f['evidence'][$k] = $ev();
    $shot = fn(int $id, string $n) => ['id' => $id, 'note' => '', 'url' => '', 'file' => ['id' => str_repeat('a', 32), 'name' => $n, 'missing' => false], 'by' => 'בעלים', 'at' => $at];
    $f['evidence']['site_compare'] = [$shot(3, 'phone.png'), $shot(2, 'desktop.png')];
    return array_replace_recursive($f, $over);
}

function m3_row(array $ev, string $key): array
{
    foreach ($ev['rows'] as $r) if ($r['key'] === $key) return $r;
    throw new TestFail("אין שורה $key");
}

function m3_post(string $action, array $json, array $user = U_OWNER): array
{
    return http('POST', "/api.php?action=$action", ['user' => $user, 'json' => $json]);
}

// ── א. ההערכה ─────────────────────────────────────────────

test('M3 · eval: הכול ✓ ← "מוכן למעבר"; הסדר של 09 §6.1, ולכל שורה מקור', function () {
    $ev = cutover_eval(m3_facts());
    eq(true, $ev['ready'], 'מוכן');
    eq(CUTOVER_ORDER, array_column($ev['rows'], 'key'), 'הסדר');
    foreach ($ev['rows'] as $r) { eq('ok', $r['status'], $r['key']); ok($r['source'] !== '', "מקור ל-{$r['key']}"); eq('', $r['todo'], "אין 'מה לעשות' ל-{$r['key']}"); }
});

test('M3 · eval: הקלטות — N מתוך הכול; ✓ רק כשכולן בכלי, ובלי תמונה באתר', function () {
    $r = m3_row(cutover_eval(m3_facts(['rec' => ['tool' => 1490, 'missing_n' => 4, 'missing' => [['word_id' => 5, 'word' => 'שלום', 'state' => 'site']]]])), 'recordings');
    eq('bad', $r['status']);
    ok(str_contains($r['detail'], '1,490 מתוך 1,494'), $r['detail']);
    eq('#/files', $r['link'], 'קישור לספרייה');
    eq(4, $r['missing_n']);
    ok($r['todo'] !== '', 'מה לעשות');
    $r = m3_row(cutover_eval(m3_facts(['rec' => ['images' => [['url' => 'https://x/bg.jpg', 'label' => 'רקע']]]])), 'recordings');
    eq('bad', $r['status'], 'תמונה באתר');
    eq('bad', m3_row(cutover_eval(m3_facts(['rec' => ['total' => 0, 'tool' => 0]])), 'recordings')['status'], 'אפס הקלטות אינו "הכול"');
    eq(false, cutover_eval(m3_facts(['rec' => ['tool' => 1]]))['ready'], 'לא מוכן');
});

test('M3 · eval: פרסום בפורמט החדש — ✗ בלי גרסה, ✗ כשהפרויקט השתנה מאז', function () {
    $f = m3_facts(); $f['build'] = null;
    eq('bad', m3_row(cutover_eval($f), 'build')['status'], 'אין גרסה');
    $r = m3_row(cutover_eval(m3_facts(['build' => ['current' => false], 'rev' => 44])), 'build');
    eq('bad', $r['status']);
    ok(str_contains($r['detail'], '40 ← 44'), $r['detail']);
});

test('M3 · eval: התוסף — 1.x ✗, אין v2 ✗, גרסה אחרת מהכלי ✗, האתר לא ענה ⏳', function () {
    $r = m3_row(cutover_eval(m3_facts(['ping' => ['plugin' => '1.4.0', 'protocol' => 0, 'v2_live' => 0]])), 'plugin');
    eq('bad', $r['status']); ok(str_contains($r['todo'], '2.0'), $r['todo']);
    eq('bad', m3_row(cutover_eval(m3_facts(['ping' => ['v2_live' => 0]])), 'plugin')['status'], 'אין v2 באוויר');
    $r = m3_row(cutover_eval(m3_facts(['ping' => ['v2_live' => 6]])), 'plugin');
    eq('bad', $r['status'], 'v2 באתר אינה האחרונה שנשלחה'); ok(str_contains($r['todo'], 'לשלוח שוב'));
    $f = m3_facts(); $f['ping'] = ['error' => 'האתר לא ענה: timeout'];
    $ev = cutover_eval($f);
    eq('wait', m3_row($ev, 'plugin')['status']);
    eq(false, $ev['ready'], '⏳ אינו ✓');
    $r = m3_row(cutover_eval(m3_facts(['ping' => ['v2_on' => true]])), 'plugin');
    ok(str_contains($r['detail'], 'המפסק: דולק'), 'המפסק מוצג');
});

test('M3 · eval: המשוב (M5) — ✓ רק כשהטופס בפרויקט רשום גם בגרסה שבאתר; בלי טופס ✗; בלי M5 ⏳', function () {
    eq('ok', m3_row(cutover_eval(m3_facts()), 'feedback')['status']);
    $f = m3_facts(); $f['feedback']['inbox'] = null;
    eq('wait', m3_row(cutover_eval($f), 'feedback')['status'], 'בלי M5');
    $f = m3_facts(); $f['feedback']['forms'] = []; $f['feedback']['live'] = [];
    $r = m3_row(cutover_eval($f), 'feedback');
    eq('bad', $r['status'], 'אין טופס'); ok(str_contains($r['todo'], 'data-kol-form'), $r['todo']);
    $f = m3_facts(); $f['feedback']['live'] = [];
    $r = m3_row(cutover_eval($f), 'feedback');
    eq('bad', $r['status'], 'הגרסה שבאתר אינה רושמת'); ok(str_contains($r['todo'], 'לבנות ולשלוח'), $r['todo']);
});

test('M3 · המשוב מול M5 האמיתי: forms_draft / forms_live / form_entries נקראים בלי שגיאה', function () {
    $fb = cutover_feedback_facts();
    ok(is_array($fb['inbox']) && $fb['inbox']['ok'] === true, 'התיבה קיימת (M5)');
    ok(is_array($fb['forms']) && is_array($fb['live']), 'רשימות');
    ok(str_contains($fb['inbox']['detail'], 'פניות בתיבה'), $fb['inbox']['detail']);
});

test('M3 · eval: נקודת שחזור — מעל 24 שעות ✗; ישנה שאומתה כזהה עכשיו ✓; אין ✗', function () {
    $old = gmdate('c', time() - 30 * 3600);
    $r = m3_row(cutover_eval(m3_facts(['snap' => ['at' => $old]])), 'backup');
    eq('bad', $r['status']); ok(str_contains($r['todo'], 'צור עכשיו'));
    eq('ok', m3_row(cutover_eval(m3_facts(['snap' => ['at' => $old], 'snap_verified' => ['id' => 3, 'at' => gmdate('c')]])), 'backup')['status'], 'אומתה');
    eq('bad', m3_row(cutover_eval(m3_facts(['snap' => ['at' => $old], 'snap_verified' => ['id' => 2, 'at' => gmdate('c')]])), 'backup')['status'], 'אימות של נקודה אחרת');
    $f = m3_facts(); $f['snap'] = null;
    eq('bad', m3_row(cutover_eval($f), 'backup')['status']);
});

test('M3 · eval: ההשוואה מול האתר — ✓ רק עם שני צילומים (טלפון + מחשב); הערה בלבד או צילום אחד ✗; צילום שנמחק אינו נספר', function () {
    $f = m3_facts();
    $note = ['id' => 9, 'note' => 'נראה זהה', 'url' => '', 'file' => null, 'by' => 'בעלים', 'at' => gmdate('c')];
    $f['evidence']['site_compare'] = [$note];
    $r = m3_row(cutover_eval($f), 'site_compare');
    eq('bad', $r['status'], 'הערה בלבד'); ok(str_contains($r['detail'], 'טלפון ומחשב'), $r['detail']);
    $f = m3_facts(); array_pop($f['evidence']['site_compare']);
    eq('bad', m3_row(cutover_eval($f), 'site_compare')['status'], 'צילום אחד');
    $f = m3_facts(); $f['evidence']['site_compare'][1]['file']['missing'] = true;
    eq('bad', m3_row(cutover_eval($f), 'site_compare')['status'], 'צילום שנמחק');
    eq('ok', m3_row(cutover_eval(m3_facts()), 'site_compare')['status'], 'שני צילומים');
});

test('M3 · eval: כל ראיה חסרה ← ✗ עם "מה לעשות"; [ש-4] אומר מראש מה אינו בעמוד החדש', function () {
    foreach (CUTOVER_EVIDENCE as $k) {
        $f = m3_facts(); $f['evidence'][$k] = [];
        $ev = cutover_eval($f);
        $r = m3_row($ev, $k);
        eq('bad', $r['status'], $k); eq('ראיה מהבעלים', $r['source']); ok($r['todo'] !== '', "מה לעשות ל-$k"); eq(false, $ev['ready'], "לא מוכן בלי $k");
    }
    $f = m3_facts(); $f['evidence']['q4'] = [];
    ok(str_contains(m3_row(cutover_eval($f), 'q4')['todo'], 'אינם'), '[ש-4]: הרכיבים של האתר אינם בעמוד החדש');
});

// ── ב. הרשאות ────────────────────────────────────────────

test('M3 · הרשאות: עורך וזר — לא קוראים; מפרסם קורא בלי עריכה; כל שינוי — בעלים בלבד', function () {
    eq(403, http('GET', '/api.php?action=cutover', ['user' => U_EDITOR])['status'], 'עורך — קריאה');
    ok(in_array(http('GET', '/api.php?action=cutover', ['user' => U_STRANGER])['status'], [401, 403], true), 'זר');
    eq(401, http('GET', '/api.php?action=cutover')['status'], 'בלי זהות');
    $r = http('GET', '/api.php?action=cutover', ['user' => U_PUBLISHER]);
    eq(200, $r['status'], 'מפרסם — קריאה');
    eq(false, $r['json']['can_edit']);
    eq(count(CUTOVER_ORDER), count($r['json']['rows']));
    foreach (['cutover_evidence' => ['cond' => 'q15', 'confirm' => true, 'note' => 'x'], 'cutover_evidence_revoke' => ['id' => 1], 'cutover_snapshot' => [], 'cutover_ready' => []] as $a => $b) {
        eq(403, m3_post($a, $b, U_PUBLISHER)['status'], "מפרסם — $a");
        eq(403, m3_post($a, $b, U_EDITOR)['status'], "עורך — $a");
    }
    eq(405, http('GET', '/api.php?action=cutover_ready', ['user' => U_OWNER])['status'], 'GET לפעולה משנה');
    eq(403, http('POST', '/api.php?action=cutover_evidence', ['user' => U_OWNER, 'json' => ['cond' => 'q15', 'confirm' => true, 'note' => 'x'], 'csrf' => false])['status'], 'בלי CSRF');
    $r = http('GET', '/api.php?action=cutover', ['user' => U_OWNER]);
    eq(200, $r['status']); eq(true, $r['json']['can_edit']); eq(false, $r['json']['ready']);
});

// ── ג. ראיה ───────────────────────────────────────────────

test('M3 · ראיה: בלי אישור / תנאי מחושב / javascript: / קובץ שאינו קיים / ריקה — נדחות', function () {
    eq(422, m3_post('cutover_evidence', ['cond' => 'q15', 'note' => 'x'])['status'], 'בלי אישור');
    eq(422, m3_post('cutover_evidence', ['cond' => 'q15', 'confirm' => 'yes', 'note' => 'x'])['status'], 'אישור שאינו true');
    eq(422, m3_post('cutover_evidence', ['cond' => 'recordings', 'confirm' => true, 'note' => 'x'])['status'], 'תנאי שהכלי מחשב');
    eq(422, m3_post('cutover_evidence', ['cond' => 'q15', 'confirm' => true, 'url' => 'javascript:alert(1)'])['status'], 'javascript:');
    eq(422, m3_post('cutover_evidence', ['cond' => 'q15', 'confirm' => true, 'url' => 'ftp://x'])['status'], 'ftp');
    ok(m3_post('cutover_evidence', ['cond' => 'q15', 'confirm' => true, 'file_id' => str_repeat('a', 32)])['status'] >= 400, 'קובץ שאינו קיים');
    eq(422, m3_post('cutover_evidence', ['cond' => 'q15', 'confirm' => true, 'file_id' => '../x'])['status'], 'מזהה קובץ לא חוקי');
    eq(422, m3_post('cutover_evidence', ['cond' => 'q15', 'confirm' => true])['status'], 'ריקה');
    eq(0, (int)db()->query("SELECT COUNT(*) FROM cutover_evidence")->fetchColumn(), 'לא נשמר דבר');
});

test('M3 · ראיה: נשמרת עם שם ותאריך ← ✓ וביומן; ביטול ← ✗, אדום בראש המסך, הודעה לבעלים', function () {
    db()->exec("DELETE FROM notices WHERE kind = 'cutover'");
    $r = m3_post('cutover_evidence', ['cond' => 'q15', 'confirm' => true, 'note' => 'כל ההבדלים הוכרעו', 'url' => 'https://example.test/q15']);
    eq(200, $r['status'], $r['body']);
    $row = m3_row($r['json'], 'q15');
    eq('ok', $row['status']);
    eq('בעלים בדיקה', $row['evidence'][0]['by']);
    eq('https://example.test/q15', $row['evidence'][0]['url']);
    ok($row['evidence'][0]['at'] !== '', 'תאריך');
    ok((int)db()->query("SELECT COUNT(*) FROM audit WHERE action = 'cutover_evidence'")->fetchColumn() >= 1, 'יומן: ראיה');
    ok((int)db()->query("SELECT COUNT(*) FROM audit WHERE action = 'cutover_cond' AND detail LIKE '%ש-15%← ok%'")->fetchColumn() >= 1, 'יומן: התנאי ← ✓');

    $r = m3_post('cutover_evidence_revoke', ['id' => $row['evidence'][0]['id']]);
    eq(200, $r['status'], $r['body']);
    eq('bad', m3_row($r['json'], 'q15')['status'], 'אחרי ביטול');
    ok(in_array('q15', $r['json']['fell'], true), 'נפל ← בראש המסך');
    ok(m3_row($r['json'], 'q15')['fell_at'] !== null, 'fell_at');
    ok((int)db()->query("SELECT COUNT(*) FROM notices WHERE kind = 'cutover' AND role_to = 'owner' AND text LIKE '%ש-15%'")->fetchColumn() === 1, 'הודעה לבעלים — אחת');
    ok((int)db()->query("SELECT COUNT(*) FROM audit WHERE action = 'cutover_evidence_revoke'")->fetchColumn() === 1, 'יומן: ביטול');
    eq(404, m3_post('cutover_evidence_revoke', ['id' => $row['evidence'][0]['id']])['status'], 'ביטול שני');
    // פתיחה נוספת — אין הודעה שנייה; ראיה חדשה — האדום נעלם
    http('GET', '/api.php?action=cutover', ['user' => U_OWNER]);
    eq(1, (int)db()->query("SELECT COUNT(*) FROM notices WHERE kind = 'cutover' AND text LIKE '%ש-15%'")->fetchColumn(), 'אין הודעה כפולה');
    $r = m3_post('cutover_evidence', ['cond' => 'q15', 'confirm' => true, 'note' => 'שוב']);
    eq([], $r['json']['fell']);
});

test('M3 · ראיה: קובץ מהמאגר הקיים (data/files/) — ושם הקובץ מוצג', function () {
    $tmp = KOL_DATA_DIR . '/m3-ev.txt';
    file_put_contents($tmp, "attack kit: 3/3 green\n");
    $fid = file_store($tmp, 'attack-kit.txt', U_OWNER, 'text/plain');
    $r = m3_post('cutover_evidence', ['cond' => 'attack_kit', 'confirm' => true, 'file_id' => $fid]);
    eq(200, $r['status'], $r['body']);
    $e = m3_row($r['json'], 'attack_kit')['evidence'][0];
    eq('attack-kit.txt', $e['file']['name']); eq(false, $e['file']['missing']);
    ok(is_file(file_path(file_row($fid))), 'הקובץ במאגר הקבצים');
});

// ── ד. "מוכן למעבר", המטלה, המפסק ───────────────────────────

test('M3 · "מוכן למעבר": נדחה בשרת כשלא הכול ✓ — ולא נפתחת מטלה', function () {
    $r = m3_post('cutover_ready', []);
    eq(409, $r['status']);
    eq('not_ready', $r['json']['code']);
    ok(str_contains($r['json']['error'], 'ההקלטות'), $r['json']['error']);
    eq(null, setting('cutover_task'));
});

test('M3 · המטלה: צעדי §2 בסדר, כלל הכיבוי, ופעם אחת', function () {
    $a = cutover_open_task(M3_OWNER);
    eq(false, $a['existing']);
    eq(CUTOVER_SWITCH_PATH, $a['instruction']);
    $t = devloop_task_get($a['code']);
    eq('high', $t['priority']);
    $steps = is_array($t['steps']) ? $t['steps'] : json_decode((string)$t['steps'], true);
    eq(5, count($steps));
    ok(str_starts_with($steps[0], '1. פרסום אחרון') && str_starts_with($steps[2], '3. בעל האתר') && str_starts_with($steps[4], '5. אחרי יום'), 'הסדר');
    ok(str_contains((string)$t['detail'], 'מכבים') && str_contains((string)$t['detail'], 'הכלי אינו מדליק'), 'כלל הכיבוי, ו"אינו מדליק"');
    $b = cutover_open_task(M3_OWNER);
    eq(true, $b['existing']); eq($a['code'], $b['code'], 'אותה מטלה');
    ok((int)db()->query("SELECT COUNT(*) FROM audit WHERE action = 'cutover_ready'")->fetchColumn() === 1, 'יומן');
    $s = http('GET', '/api.php?action=cutover', ['user' => U_OWNER])['json'];
    eq($a['code'], $s['task']);
});

test('M3 · המפסק נקרא בלבד: דולק ← "באוויר מאז", ביומן ובהודעה; כבוי ← חוזר; האתר לא ענה — אינו "כבוי"', function () {
    $ping = ['plugin' => '2.0.0', 'protocol' => 2, 'v2_live' => 7, 'v2_on' => true, 'stats' => ['served' => 10, 'e404' => 0]];
    $s = cutover_state(M3_OWNER, $ping);
    eq(true, $s['on']); ok($s['live']['since'] !== '', 'מאז'); eq(7, $s['live']['version']);
    eq(['served' => 10, 'e404' => 0], $s['stats'], 'המונה מה-ping');
    $since = $s['live']['since'];
    eq($since, cutover_state(M3_OWNER, $ping)['live']['since'], 'פתיחה שנייה — אותו "מאז"');
    eq(1, (int)db()->query("SELECT COUNT(*) FROM audit WHERE action = 'cutover_on'")->fetchColumn());
    eq($since, cutover_state(M3_OWNER, ['error' => 'timeout'])['live']['since'], 'האתר לא ענה — לא נרשם כיבוי');
    $s = cutover_state(M3_OWNER, ['v2_on' => false] + $ping);
    eq(null, $s['live']);
    eq(1, (int)db()->query("SELECT COUNT(*) FROM audit WHERE action = 'cutover_off'")->fetchColumn());
    // ואין פעולה בכלי שמדליקה: אין שום קריאה ל-switch / activate בקוד של המעבר
    $src = (string)file_get_contents(KOL_ROOT . '/lib/cutover.php');
    ok(!preg_match("/site_(call|post)\\('(switch|activate|v2_on)/", $src), 'אין קריאה שמדליקה');
});

test('M3 · נקודת שחזור: "צור עכשיו" ← ✓ (גם כשהמצב זהה לנקודה קיימת)', function () {
    $r = m3_post('cutover_snapshot', []);
    eq(200, $r['status'], $r['body']);
    eq('ok', m3_row($r['json'], 'backup')['status']);
    db()->exec("UPDATE snapshots SET at = '2020-01-01T00:00:00+00:00'");
    setting_set('cutover_snap_verified', 'null');
    eq('bad', m3_row(http('GET', '/api.php?action=cutover', ['user' => U_OWNER])['json'], 'backup')['status'], 'ישנה');
    $r = m3_post('cutover_snapshot', []);
    eq('ok', m3_row($r['json'], 'backup')['status'], 'אומתה מחדש');
});

// ── ה. ה-ping האמיתי ──────────────────────────────────────

test('M3 · התוסף האמיתי (2.0, אחרי M2) מול fake-wp: protocol 2; בלי גרסה v2 באוויר ← ✗ "לשלוח"; המקבל המדומה — גם הוא נקרא', function () {
    // תוסף 1.x — נבדק ב-eval (ping מדומה); שדות המפסק והמונה — לפי M2 §2 שורה 26, ובתשובה מדומה עד M2b
    setting_set('site_url', $GLOBALS['WP']);
    $f = $GLOBALS['WP_DIR'] . '/options.json';
    $o = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $keep = $o;
    $o = ['kol_maroko_keys' => pub_key_line(pub_key())];      // נקי: בלי מה שבדיקות אחרות השאירו (שדרוג מ-1.4 וכו')
    file_put_contents($f, json_encode($o));
    $ping = cutover_ping();
    ok(!isset($ping['error']), 'ה-ping ענה: ' . ($ping['error'] ?? ''));
    eq(2, $ping['protocol'], 'protocol');
    ok(str_starts_with($ping['plugin'], '2.'), 'גרסת התוסף: ' . $ping['plugin']);
    eq(false, $ping['v2_on'], 'המפסק כבוי');
    $r = m3_row(cutover_eval(m3_facts(['ping' => $ping + ['v2_live' => 0]])), 'plugin');
    eq('bad', $r['status'], $r['detail']);
    ok(str_contains($r['todo'], 'לשלוח'), $r['todo']);
    ok(str_contains($r['detail'], 'protocol 2') && str_contains($r['detail'], 'המפסק: כבוי'), $r['detail']);
    $o['kol_v2_mock'] = true;
    file_put_contents($f, json_encode($o));
    $ping = cutover_ping();
    eq(2, $ping['protocol'], 'המקבל המדומה');
    file_put_contents($f, json_encode($keep + ['kol_maroko_keys' => $o['kol_maroko_keys']]));
});
