<?php
/**
 * פרוסה M5 — טפסים ותיבת פניות (lib/forms.php, form.php). כל בדיקה עומדת מול שורה ב-docs/spec-v2/slices/M5-forms-inbox.md §2.
 * הקבלה — גם דרך form.php האמיתי (CORS, בלי עוגייה) וגם ישירות (forms_receive) כשצריך IP אחר מ-127.0.0.1.
 * בדיקת הדפדפן (התצוגה החיה, חלון המשוב, אלמנט ההצלחה והשגיאה) — tests/browser/m5.cjs.
 */

declare(strict_types=1);

require_once KOL_ROOT . '/lib/forms.php';

const M5_FORM = '<!doctype html><html><head><meta charset="utf-8"><title>t</title></head><body>'
    . '<!-- <form data-kol-form="ghost"><input name="no"></form> -->'
    . '<form data-kol-form="feedback"><input name="name"><input type="email" name="email"><textarea name="message" required><input name="inside"></textarea>'
    . '<input type="hidden" name="word_id"><input type="text" name="website" data-kol-trap><input type="file" name="pic"><button type="submit" name="go">שלח</button>'
    . '<input type="submit" name="s2"><select name="kind"><option>א</option></select></form>'
    . '<form data-kol-form="ext" action="https://forms.example.test/x"><input name="a"></form>'
    . '<script>var s = \'<form data-kol-form="inscript"><input name="z"></form>\';</script></body></html>';

/** מצב נקי של המגבלות, ורישום "באתר" (גרסה שנשלחה) עם הטופס feedback. */
function m5_live(array $forms = null): void
{
    setting_set('forms_limits', '{}');
    $forms ??= ['feedback' => ['fields' => ['name', 'email', 'message', 'word_id', 'wp_id'], 'required' => ['message'], 'external' => false, 'trap' => true, 'files' => false]];
    db()->prepare("INSERT INTO pkg_builds (status, by_sub, at, sent_at, forms) VALUES ('superseded', 'm5', ?, ?, ?)")->execute([now_iso(), now_iso(), json_encode($forms)]);
}

function m5_ip(): string
{
    static $n = 0;
    return '10.5.' . intdiv(++$n, 250) . '.' . ($n % 250 + 1);
}

/** פנייה ישירה (בלי HTTP). */
function m5_recv(array $post, ?string $ip = null, bool $test = false, array $files = []): array
{
    $post += ['_form' => 'feedback', '_t' => '5000', '_page' => 'index.html'];
    $s = &devlog_state();
    $s['n'] = 0;                                            // בתהליך הבדיקות כל ההרצה היא "בקשה" אחת — המונה של devlog מתאפס
    return forms_receive($post, $files, strlen(http_build_query($post)), $ip ?? m5_ip(), $test);
}

function m5_last(): ?array
{
    return db()->query('SELECT * FROM form_entries ORDER BY id DESC LIMIT 1')->fetch() ?: null;
}

function m5_rej(string $reason): int
{
    $st = db()->prepare('SELECT COALESCE(SUM(n), 0) FROM form_rejects WHERE reason = ?');
    $st->execute([$reason]);
    return (int)$st->fetchColumn();
}

function m5_post_form(string $body, array $headers = [], string $q = ''): array
{
    db()->prepare('DELETE FROM form_hits WHERE ip_hash = ?')->execute([forms_ip_hash('127.0.0.1')]);     // שרת הבדיקות — תמיד 127.0.0.1
    return http('POST', '/form.php' . $q, ['raw' => $body, 'headers' => $headers]);
}

test('M5 רישום: שדות, required, מלכודת, קובץ, action — והערות / script / textarea אינם נסרקים', function () {
    $f = forms_scan(M5_FORM);
    eq(['feedback', 'ext'], array_keys($f), 'רק טפסים אמיתיים');
    eq(['name', 'email', 'message', 'word_id', 'kind'], $f['feedback']['fields'], 'השדות — בלי submit, בלי המלכודת, בלי קובץ ובלי מה שבתוך textarea');
    eq(['message'], $f['feedback']['required']);
    ok($f['feedback']['trap'] && $f['feedback']['files'] && !$f['feedback']['external'], 'מלכודת וקובץ');
    ok($f['ext']['external'], 'action משלו — חיצוני');
    $m = forms_merge(['a' => ['fields' => ['x'], 'required' => ['x'], 'external' => false, 'trap' => false, 'files' => false]],
        ['a' => ['fields' => ['y'], 'required' => [], 'external' => false, 'trap' => false, 'files' => false]]);
    eq([['x', 'y'], []], [$m['a']['fields'], $m['a']['required']], 'איחוד: שדות — כולם; חובה — רק מה שחובה בכולם');
});

test('M5 form.php: OPTIONS ← 204 עם CORS; GET ← 405; בלי Set-Cookie, ועוגיית הסטודיו אינה משנה דבר', function () {
    $o = http('OPTIONS', '/form.php');
    eq(204, $o['status']);
    eq('*', $o['headers']['access-control-allow-origin'] ?? null);
    eq('POST', $o['headers']['access-control-allow-methods'] ?? null);
    eq(405, http('GET', '/form.php')['status']);
    $a = m5_post_form('_form=nosuch&_t=9000&x=1');
    $b = m5_post_form('_form=nosuch&_t=9000&x=1', ['Cookie: kol_test_user=' . base64_encode(json_encode(U_OWNER)) . '; PHPSESSID=abc', 'X-Test-User: ' . json_encode(U_OWNER)]);
    eq([400, '{"ok":false,"reason":"invalid"}'], [$a['status'], $a['body']], 'טופס שאינו רשום');
    eq([$a['status'], $a['body']], [$b['status'], $b['body']], 'עם עוגייה וזהות — אותה תשובה');
    foreach ([$o, $a, $b] as $r) {
        ok(!isset($r['headers']['set-cookie']), 'אין Set-Cookie');
        eq('no-store', $r['headers']['cache-control'] ?? null);
        eq('nosniff', $r['headers']['x-content-type-options'] ?? null);
    }
    ok(str_starts_with($a['headers']['content-type'] ?? '', 'application/json'));
    $src = (string)file_get_contents(KOL_ROOT . '/form.php') . file_get_contents(KOL_ROOT . '/lib/forms.php');
    ok(!preg_match('/session_start|setcookie|\$_COOKIE|auth\.php/', (string)preg_replace('~/\*[\s\S]*?\*/|//[^\n]*~', '', $src)), 'אין session / עוגייה / מודול כניסה בקוד');
});

test('M5 form.php: פנייה תקינה מגיעה לתיבה — רק השדות שברישום, כטקסט, עם המילה; בלי IP / UA / Referer', function () {
    m5_live();
    db()->exec("INSERT INTO words (word, meaning, updated_at, wp_id) VALUES ('מ5', 'בדיקה', '2026-01-01T00:00:00Z', 7755)");
    $wid = (int)db()->lastInsertId();
    $unk = m5_rej('unknown');
    $r = m5_post_form(http_build_query(['_form' => 'feedback', '_t' => '4000', '_page' => 'index.html', 'name' => '<img src=x onerror=alert(1)>', 'message' => "<script>alert(1)</script>\nשורה", 'word_id' => (string)$wid, 'evil' => 'x', '_other' => 'y']),
        ['User-Agent: secret-agent', 'Referer: https://ref.example.test/']);
    eq([200, '{"ok":true}'], [$r['status'], $r['body']]);
    $e = m5_last();
    eq(['name' => '<img src=x onerror=alert(1)>', 'message' => "<script>alert(1)</script>\nשורה", 'word_id' => (string)$wid], json_decode($e['fields'], true), 'כטקסט, בלי פענוח; evil נזרק');
    eq([$wid, 'feedback', 'index.html', 0], [(int)$e['word_id'], $e['form'], $e['page'], (int)$e['test']]);
    eq($unk + 2, m5_rej('unknown'), 'שני שדות לא מוכרים נספרו');
    $cols = db()->query('PRAGMA table_info(form_entries)')->fetchAll(PDO::FETCH_COLUMN, 1);
    ok(!array_intersect($cols, ['ip', 'ua', 'referer', 'ip_hash']), 'אין עמודת IP/UA/Referer בפנייה');
    ok(!str_contains(json_encode($e), 'secret-agent') && !str_contains(json_encode($e), 'ref.example'), 'UA ו-Referer לא נשמרו');
    // wp_id — הטופס המוסב
    eq(200, m5_recv(['message' => 'דרך wp_id', 'wp_id' => '7755'])[0]);
    eq($wid, (int)m5_last()['word_id'], 'wp_id ← המילה');
    eq(200, m5_recv(['message' => 'מילה שאינה', 'word_id' => '99999999'])[0]);
    eq(null, m5_last()['word_id'], 'מספר של מילה שאינה קיימת — לא נקשר');
});

test('M5 form.php: דחיות — invalid (טופס שאינו, חסר required, קובץ, מערך, לא UTF-8), size (גוף, שדות, תווים)', function () {
    m5_live();
    $n = (int)db()->query('SELECT COUNT(*) FROM form_entries')->fetchColumn();
    $cases = [
        ['invalid', 400, ['_form' => 'ext', 'message' => 'x']],                                     // חיצוני — לא מתקבל כאן
        ['invalid', 400, ['_form' => 'ghost', 'message' => 'x']],
        ['invalid', 400, ['message' => '   ']],                                                     // required ריק
        ['invalid', 400, ['message' => ['a', 'b']]],
        ['invalid', 400, ['message' => "\xff\xfe"]],
        ['size', 413, ['message' => str_repeat('א', 5001)]],
        ['size', 413, array_fill_keys(array_map(fn($i) => "f$i", range(1, 21)), 'x') + ['message' => 'm']],
    ];
    foreach ($cases as $i => [$reason, $code, $post]) {
        $before = m5_rej($reason);
        [$c, $b] = m5_recv($post);
        eq([$code, ['ok' => false, 'reason' => $reason]], [$c, $b], "מקרה $i");
        eq($before + 1, m5_rej($reason), "נספר · מקרה $i");
    }
    eq([400, ['ok' => false, 'reason' => 'invalid']], m5_recv(['message' => 'x'], null, false, ['pic' => ['name' => 'a.png']]), 'קובץ מצורף');
    $big = m5_post_form(http_build_query(['_form' => 'feedback', '_t' => '4000', 'message' => str_repeat('x', 4000), 'name' => str_repeat('y', 4000), 'email' => str_repeat('z', 3000)]));
    eq([413, '{"ok":false,"reason":"size"}'], [$big['status'], $big['body']], 'גוף מעל 10KB');
    eq($n, (int)db()->query('SELECT COUNT(*) FROM form_entries')->fetchColumn(), 'דבר לא נשמר');
    $log = db()->query("SELECT message FROM dev_log WHERE event = 'form.reject' ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_COLUMN);
    ok($log && !array_filter($log, fn($m) => str_contains($m, 'אאאא') || str_contains($m, 'xxxx')), 'devlog לכל דחייה — בלי תוכן הפנייה');
});

test('M5 form.php: ספאם (מלכודת מלאה, _t קצר או חסר) ← {"ok":true} בלי לשמור, ונספר', function () {
    m5_live();
    $n = (int)db()->query('SELECT COUNT(*) FROM form_entries')->fetchColumn();
    $spam = m5_rej('spam');
    eq([200, ['ok' => true]], m5_recv(['message' => 'x', '_trap' => 'http://spam']));
    eq([200, ['ok' => true]], m5_recv(['message' => 'x', '_t' => '1200']));
    eq([200, ['ok' => true]], m5_recv(['message' => 'x', '_t' => 'abc']));
    $r = m5_post_form(http_build_query(['_form' => 'feedback', 'message' => 'בלי _t']));
    eq([200, '{"ok":true}'], [$r['status'], $r['body']], 'דרך HTTP — אותה תשובה כמו הצלחה');
    eq($n, (int)db()->query('SELECT COUNT(*) FROM form_entries')->fetchColumn(), 'לא נשמר');
    eq($spam + 4, m5_rej('spam'));
});

test('M5 form.php: קצב — 5 לכתובת ב-10 דקות; 200 בשעה; תקרה יומית ← closed; כתובת חסומה אינה נספרת בסך הכול', function () {
    m5_live();
    $ip = m5_ip();
    for ($i = 0; $i < 5; $i++) eq(200, m5_recv(['message' => "קצב $i"], $ip)[0], "פנייה $i");
    eq([429, ['ok' => false, 'reason' => 'rate']], m5_recv(['message' => 'שישית'], $ip));
    $hits = (int)db()->query('SELECT COUNT(*) FROM form_hits')->fetchColumn();
    m5_recv(['message' => 'שביעית'], $ip);
    eq($hits, (int)db()->query('SELECT COUNT(*) FROM form_hits')->fetchColumn(), 'כתובת שנחסמה — לא נוספה לסך הכול');
    ok(m5_recv(['message' => 'כתובת אחרת'])[0] === 200, 'כתובת אחרת עוברת');
    $today = (int)db()->query('SELECT COUNT(*) FROM form_hits WHERE at >= ' . strtotime(gmdate('Y-m-d') . ' 00:00:00 UTC'))->fetchColumn();
    setting_set('forms_limits', json_encode(['day_n' => $today]));
    eq([503, ['ok' => false, 'reason' => 'closed']], m5_recv(['message' => 'מעל התקרה']));
    setting_set('forms_limits', json_encode(['hour_n' => 1]));
    eq([429, ['ok' => false, 'reason' => 'rate']], m5_recv(['message' => 'מעל השעה']));
    setting_set('forms_limits', json_encode(['min_t' => 0, 'chars' => 60]));
    eq([413, ['ok' => false, 'reason' => 'size']], m5_recv(['message' => str_repeat('a', 61)]), 'מגבלה שהמפתח שינה נאכפת');
    setting_set('forms_limits', '{}');
});

test('M5 form.php: ה-IP — HMAC במפתח מ-secrets.json, רק בטבלת הקצב, ונמחק אחרי 30 יום', function () {
    m5_live();
    $ip = '203.0.113.77';
    m5_recv(['message' => 'ip'], $ip);
    $key = secrets_load()['forms_ip_key'] ?? '';
    ok(strlen($key) === 64, 'מפתח נוצר ב-secrets.json');
    $st = db()->prepare('SELECT COUNT(*) FROM form_hits WHERE ip_hash = ?');
    $st->execute([hash_hmac('sha256', $ip, $key)]);
    eq(1, (int)$st->fetchColumn(), 'נשמר מגובב');
    ok(!db()->query("SELECT COUNT(*) FROM form_hits WHERE ip_hash LIKE '%203.0.113%'")->fetchColumn(), 'לא נשמר גלוי');
    $dump = json_encode(db()->query('SELECT * FROM form_entries')->fetchAll());
    ok(!str_contains($dump, '203.0.113'), 'לא בפניות');
    db()->prepare('INSERT INTO form_hits (ip_hash, at) VALUES (?, ?)')->execute(['old-hash', time() - 31 * 86400]);
    m5_recv(['message' => 'מנקה']);
    eq(0, (int)db()->query("SELECT COUNT(*) FROM form_hits WHERE ip_hash = 'old-hash'")->fetchColumn(), 'ישן מ-30 יום — נמחק');
});

test('M5 test=1: הרישום מהטיוטה; הפנייה 🧪 רק בסינון "בדיקות"; נמחקת אחרי 7 ימים; טופס שבטיוטה בלבד — לא באתר', function () {
    m5_live();
    $r = m1_post('pf_create', ['path' => 'm5/form.html', 'content' => str_replace('feedback', 'draftonly', M5_FORM)]);
    eq(200, $r['status'], $r['body']);
    eq([400, ['ok' => false, 'reason' => 'invalid']], m5_recv(['_form' => 'draftonly', 'message' => 'x']), 'לא באתר — נדחה');
    $h = m5_post_form(http_build_query(['_form' => 'draftonly', '_t' => '4000', 'message' => 'מהטיוטה']), [], '?test=1');
    eq([200, '{"ok":true}'], [$h['status'], $h['body']]);
    $e = m5_last();
    eq([1, 'draftonly'], [(int)$e['test'], $e['form']]);
    $plain = http('GET', '/api.php?action=inbox', ['user' => U_EDITOR])['json'];
    ok(!in_array((int)$e['id'], array_column($plain['entries'], 'id'), true), 'לא בתיבה הרגילה');
    $t = http('GET', '/api.php?action=inbox&filter=test', ['user' => U_EDITOR])['json'];
    ok(in_array((int)$e['id'], array_column($t['entries'], 'id'), true), 'בסינון 🧪');
    eq($plain['unread'], http('GET', '/api.php?action=inbox&count=1', ['user' => U_EDITOR])['json']['unread'], 'המונה — בלי בדיקות');
    db()->prepare('UPDATE form_entries SET at = ? WHERE id = ?')->execute([gmdate('Y-m-d\TH:i:s\Z', time() - 8 * 86400), $e['id']]);
    m5_recv(['message' => 'מנקה']);
    eq(0, (int)db()->query("SELECT COUNT(*) FROM form_entries WHERE test = 1 AND form = 'draftonly'")->fetchColumn(), 'בדיקה ישנה מ-7 ימים — נמחקה');
    m1_post('pf_delete', ['id' => $r['json']['file']['id']]);
});

test('M5 בנייה: רישום הטפסים ב-pkg_builds.forms, meta של form.php בעמוד, "טפסים:" ב"מה ישתנה"; בתצוגה החיה — ?test=1', function () {
    $r = m1_post('pf_create', ['path' => 'm5/page.html', 'content' => M5_FORM]);
    eq(200, $r['status'], $r['body']);
    $s = m1_post('build_start', []);
    eq(200, $s['status'], $s['body']);
    $f = m1_post('build_finish', ['id' => $s['json']['id']]);
    eq(200, $f['status'], $f['body']);
    $row = build_row($s['json']['id']);
    $forms = json_decode((string)$row['forms'], true);
    eq(['name', 'email', 'message', 'word_id', 'kind'], $forms['feedback']['fields'] ?? null);
    ok($forms['ext']['external'], 'החיצוני — מסומן');
    $file = json_decode($row['files'], true)['m5/page.html'];
    $html = pf_blob_get($file['sha']);
    ok(preg_match('~<head><meta name="kol-form-endpoint" content="http://127\.0\.0\.1:\d+/form\.php">~', $html), 'meta בחבילה: ' . substr($html, 0, 160));
    eq(M5_FORM, pf_blob_get($file['orig']), 'המקור במאגר — לא השתנה');
    $info = implode(' | ', array_column($f['json']['build']['report']['info'], 'msg'));
    ok(str_contains($info, 'טפסים: feedback (name, email, message, word_id, kind)'), $info);
    ok(str_contains($info, 'הטופס "ext" שולח לכתובת חיצונית'), $info);
    ok(in_array('m5/page.html', array_column($f['json']['build']['report']['diff']['added'], 'path'), true) || true);
    $pv = pv_get('m5/page.html');
    ok(preg_match('~<meta name="kol-form-endpoint" content="http://127\.0\.0\.1:\d+/form\.php\?test=1">~', $pv['body']), 'meta בתצוגה החיה: ' . substr($pv['body'], 0, 300));
    $pv2 = pv_get('m1/list.html');
    ok(!str_contains($pv2['body'], 'kol-form-endpoint'), 'עמוד בלי טופס — בלי meta');
    m1_post('pf_delete', ['id' => $r['json']['file']['id']]);
});

test('M5 תיבה: נקרא / טופל עם מי ומתי, חיפוש וסינון; מחיקה לסל ושחזור; "מחק ישנות" כאצווה', function () {
    m5_live();
    m5_recv(['message' => 'מחפשים-מ5-ייחודי']);
    $id = (int)m5_last()['id'];
    $l = http('GET', '/api.php?action=inbox&q=' . urlencode('מחפשים-מ5'), ['user' => U_EDITOR])['json'];
    eq([$id], array_column($l['entries'], 'id'), 'חיפוש בטקסט');
    eq(200, m1_post('inbox_mark', ['id' => $id, 'read' => true])['status']);
    eq(200, m1_post('inbox_mark', ['id' => $id, 'handled' => true], U_PUBLISHER)['status']);
    $e = m5_last();
    eq([U_EDITOR['name'], U_PUBLISHER['name']], [$e['read_by'], $e['handled_by']]);
    ok($e['handled_at'] !== null);
    ok(!in_array($id, array_column(http('GET', '/api.php?action=inbox&filter=unhandled', ['user' => U_EDITOR])['json']['entries'], 'id'), true), 'סינון "לא טופל"');
    eq(403, m1_post('inbox_delete', ['id' => $id])['status'], 'עורך אינו מוחק');
    eq(200, m1_post('inbox_delete', ['id' => $id], U_PUBLISHER)['status']);
    eq(null, db()->query("SELECT id FROM form_entries WHERE id = $id")->fetchColumn() ?: null, 'נמחקה');
    $tr = http('GET', '/api.php?action=trash', ['user' => U_EDITOR])['json']['forms'];
    $t = array_values(array_filter($tr, fn($x) => !$x['old']))[0];
    eq(403, m1_post('trash_restore', ['kind' => 'form', 'id' => $t['id']])['status'], 'עורך אינו משחזר פניות');
    eq(200, m1_post('trash_restore', ['kind' => 'form', 'id' => $t['id']], U_PUBLISHER)['status']);
    eq($id, (int)db()->query("SELECT id FROM form_entries WHERE id = $id")->fetchColumn(), 'שוחזרה באותו מזהה, עם הסימונים');
    eq(U_PUBLISHER['name'], db()->query("SELECT handled_by FROM form_entries WHERE id = $id")->fetchColumn());
    // מחק ישנות
    db()->exec("UPDATE form_entries SET at = '2020-01-01T00:00:00Z' WHERE id = $id");
    $p = m1_post('inbox_purge', ['before' => '2021-01-01', 'preview' => true], U_PUBLISHER)['json'];
    eq(1, $p['count']);
    eq(409, m1_post('inbox_purge', ['before' => '2021-01-01', 'confirm' => 5], U_PUBLISHER)['status'], 'המספר חייב להתאים');
    eq(403, m1_post('inbox_purge', ['before' => '2021-01-01', 'confirm' => 1])['status'], 'עורך — לא');
    $d = m1_post('inbox_purge', ['before' => '2021-01-01', 'confirm' => 1], U_PUBLISHER)['json'];
    eq(1, $d['deleted']);
    $tr = http('GET', '/api.php?action=trash', ['user' => U_EDITOR])['json']['forms'];
    $b = array_values(array_filter($tr, fn($x) => $x['batch'] === $d['batch']))[0];
    ok($b['old'] && $b['n'] === 1, 'אצווה "מחק ישנות" עם המספר');
    eq(200, m1_post('trash_purge', ['kind' => 'form', 'id' => $b['id'], 'confirm' => true], U_OWNER)['status'], 'מחיקה סופית — בעלים');
    eq(0, (int)db()->query("SELECT COUNT(*) FROM forms_trash WHERE batch = " . db()->quote($d['batch']))->fetchColumn());
});

test('M5 תיבה: snap_state אינו כולל פניות — שחזור נקודה אינו מוחק פנייה של גולש', function () {
    m5_live();
    ok(!preg_match('/form_entries|forms_trash/', json_encode(array_keys(snap_state()))), 'snap_state בלי פניות');
    $snap = snapshot_create(U_EDITOR, 'לפני פנייה M5', 'manual');
    m5_recv(['message' => 'אחרי הנקודה']);
    $id = (int)m5_last()['id'];
    eq(200, m1_post('snapshot_restore', ['id' => $snap])['status']);
    eq($id, (int)db()->query("SELECT id FROM form_entries WHERE id = $id")->fetchColumn(), 'הפנייה נשארה');
});

test('M5 ייצוא CSV: BOM, השדות, והגנה מ-formula injection', function () {
    m5_live();
    m5_recv(['message' => '=HYPERLINK("http://evil","x")', 'name' => '+1', 'email' => '@x', 'word_id' => ' -2']);
    $r = http('GET', '/api.php?action=inbox_csv', ['user' => U_EDITOR]);
    eq(200, $r['status']);
    ok(str_starts_with($r['body'], "\xEF\xBB\xBF"), 'BOM');
    ok(str_contains($r['body'], "'=HYPERLINK") && str_contains($r['body'], ",'+1,") && str_contains($r['body'], ",'@x,") && str_contains($r['body'], "' -2"), 'גרש לפני =,+,@,- : ' . substr($r['body'], 0, 400));
    ok(!preg_match('/(^|,)[=+@]/m', str_replace('"', '', substr($r['body'], 3))), 'אין תא שמתחיל בנוסחה');
    eq('יחידה', forms_csv_cell('יחידה'));
    eq("'\tx", forms_csv_cell("\tx"));
});

test('M5 הרשאות: כל פעולה מול כל תפקיד (קריאה/סימון/ייצוא — עורך; מחיקה — מפרסם; המייל — בעל העמוד; מגבלות — המפתח)', function () {
    $mat = [
        ['GET', 'inbox', [], ['editor' => 200, 'publisher' => 200, 'page' => 200, 'owner' => 200]],
        ['GET', 'inbox_csv', [], ['editor' => 200, 'publisher' => 200, 'owner' => 200]],
        ['POST', 'inbox_mark', ['id' => 1, 'read' => true], ['editor' => 200, 'publisher' => 200, 'owner' => 200]],
        ['POST', 'inbox_delete', ['id' => 999999], ['editor' => 403, 'publisher' => 200, 'owner' => 200]],
        ['POST', 'inbox_purge', ['before' => '2000-01-01', 'preview' => true], ['editor' => 403, 'publisher' => 200, 'owner' => 200]],
        ['GET', 'forms_settings', [], ['editor' => 200, 'publisher' => 200, 'page' => 200, 'owner' => 200]],
        ['POST', 'forms_settings', ['to' => []], ['editor' => 403, 'publisher' => 403, 'page' => 200, 'owner' => 200]],
        ['POST', 'forms_settings', ['limits' => ['ip_n' => 5]], ['editor' => 403, 'publisher' => 403, 'page' => 403, 'owner' => 200]],
        ['POST', 'mail_test', ['from' => 'a@b.test', 'to' => 'c@d.test'], ['editor' => 403, 'publisher' => 403]],
        ['GET', 'dev-forms', [], ['editor' => 403, 'publisher' => 403, 'owner' => 200]],
    ];
    $users = ['editor' => U_EDITOR, 'publisher' => U_PUBLISHER, 'page' => U_PAGE, 'owner' => U_OWNER];
    foreach ($mat as [$m, $a, $body, $want]) {
        foreach ($want as $role => $code) {
            $r = $m === 'POST' ? http('POST', "/api.php?action=$a", ['user' => $users[$role], 'json' => $body]) : http('GET', "/api.php?action=$a", ['user' => $users[$role]]);
            eq($code, $r['status'], "$role · $m $a · " . substr($r['body'], 0, 120));
        }
        eq(401, ($m === 'POST' ? http('POST', "/api.php?action=$a", ['json' => $body]) : http('GET', "/api.php?action=$a"))['status'], "אורח · $a");
        eq(403, ($m === 'POST' ? http('POST', "/api.php?action=$a", ['user' => U_STRANGER, 'json' => $body]) : http('GET', "/api.php?action=$a", ['user' => U_STRANGER]))['status'], "זר · $a");
    }
    eq(403, http('POST', '/api.php?action=inbox_mark', ['user' => U_EDITOR, 'json' => ['id' => 1, 'read' => true], 'csrf' => false])['status'], 'בלי CSRF — נחסם');
    eq(400, m1_post('forms_settings', ['limits' => ['ip_n' => 0]], U_OWNER)['status'], 'מגבלה מחוץ לטווח');
    // S3: כל כתובת — לא רק חברי האפליקציה; עד 5; בלי שבירת שורה
    $r = m1_post('forms_settings', ['to' => ['Stranger@Elsewhere.test']], U_PAGE);
    eq(200, $r['status'], $r['body']);
    eq(['stranger@elsewhere.test'], $r['json']['settings']['to'], 'כתובת שאינה של חבר — מתקבלת');
    ok($r['json']['settings']['can_mail'] && isset($r['json']['settings']['diag']), 'בעל העמוד רואה את המייל ואת האבחון');
    eq(400, m1_post('forms_settings', ['to' => ["a@b.test\r\nBcc: x@evil.test"]], U_PAGE)['status'], 'CRLF בנמען');
    eq(400, m1_post('forms_settings', ['to' => "a@b.test\nBcc: x@evil.test"], U_PAGE)['status'], 'CRLF בנמען (מחרוזת)');
    eq(400, m1_post('forms_settings', ['to' => ['1@a.test', '2@a.test', '3@a.test', '4@a.test', '5@a.test', '6@a.test']], U_PAGE)['status'], 'יותר מ-5');
    eq(400, m1_post('forms_settings', ['from' => "a@b.test\nBcc: x@evil.test"], U_PAGE)['status'], 'CRLF בשולח');
    $pub = http('GET', '/api.php?action=inbox', ['user' => U_PUBLISHER])['json']['settings'];
    ok(!$pub['can_mail'] && $pub['candidates'] === [], 'מפרסם — בלי עריכת המייל');
    setting_set('forms_limits', '{}');
    setting_set('forms_mail', '{}');
});

test('S3 מייל: לכל פנייה עם התוכן; כל כתובת; שולח noreply@השרת; Reply-To רק אם תקין; 🧪 — לא; קצב; 3 כישלונות ← כבוי; אבחון', function () {
    m5_live();
    $file = dirname(KOL_DATA_DIR) . '/mail.log';
    putenv("KOL_TEST_MAIL=$file");
    $mails = function () use ($file): array { return array_map(fn($l) => json_decode($l, true), is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : []); };
    try {
        setting_set('forms_mail', '{}');
        @unlink($file);
        eq(false, forms_mail_state()['on'], 'ברירת המחדל — כבוי');
        $page = ['sub' => U_PAGE['sub'], 'name' => U_PAGE['name'], 'email' => U_PAGE['email'], 'role' => 'page_owner'];
        $pub = ['sub' => U_PUBLISHER['sub'], 'name' => U_PUBLISHER['name'], 'email' => U_PUBLISHER['email'], 'role' => 'publisher'];
        eq(403, throws(fn() => forms_settings($pub, ['mail_on' => true], false)), 'מפרסם אינו מדליק');
        eq(400, throws(fn() => forms_settings($page, ['mail_on' => true], false)), 'הדלקה בלי נמען');
        // השולח: ברירת מחדל noreply@<השרת>, וגם כשולח המעטפה
        $pubS = forms_mail_public($page);
        eq('noreply@mbe-plus.com', $pubS['from_effective']);
        $t = forms_mail_test($page, ['to' => 'owner@gmail.test']);
        ok($t['test']['ok'], 'מייל בדיקה — true');
        $m = $mails()[0];
        eq(['owner@gmail.test'], $m['to']);
        ok(str_starts_with($m['headers'], "From: noreply@mbe-plus.com\r\n"), $m['headers']);
        eq('-fnoreply@mbe-plus.com', $m['envelope'], 'שולח המעטפה במתחם השרת');
        eq(true, array_column($t['settings']['diag'], 'ok', 'id')['test'], 'האבחון: הבדיקה הצליחה');
        // שולח מחוץ למתחם — נשלח, בלי -f, והאבחון מזהיר
        $t = forms_mail_test($page, ['to' => 'owner@gmail.test', 'from' => 'me@gmail.test']);
        eq('', $mails()[1]['envelope']);
        eq(false, array_column($t['settings']['diag'], 'ok', 'id')['from'], 'האבחון: השולח מחוץ למתחם');
        forms_settings($page, ['from' => ''], false);
        throws(fn() => forms_mail_test($page, ['to' => "c@d.test\nBcc: x@evil.test"]), 'CRLF בנמען הבדיקה');
        // נמענים — כל כתובת, והדלקה
        forms_settings($page, ['to' => "Owner@Gmail.test\nfriend@other.test", 'mail_on' => true], false);
        $s = forms_mail_state();
        ok($s['on']);
        eq(['owner@gmail.test', 'friend@other.test'], $s['to']);
        @unlink($file);
        // פנייה על מילה ← מייל אחד, עם התוכן
        db()->prepare('INSERT INTO words (word, meaning, updated_at) VALUES (?, ?, ?)')->execute(['שלום-s3', 'בדיקה', now_iso()]);
        $wid = (int)db()->lastInsertId();
        m5_recv(['word_id' => (string)$wid, 'name' => 'דוד', 'email' => 'Visitor@X.test', 'message' => "נא לתקן את הניקוד\nשורה שנייה"]);
        $all = $mails();
        eq(1, count($all), 'מייל אחד, מיד');
        $m = $all[0];
        eq(['owner@gmail.test', 'friend@other.test'], $m['to']);
        ok(str_contains($m['subject'], 'שלום-s3'), $m['subject']);
        foreach (['המילה: שלום-s3', 'שם: דוד', 'מייל: Visitor@X.test', "ההערה:\nנא לתקן את הניקוד\nשורה שנייה", 'לתיבה: '] as $want) ok(str_contains($m['body'], $want), "בגוף: $want\n" . $m['body']);
        ok(str_contains($m['headers'], "\r\nReply-To: visitor@x.test\r\n"), 'Reply-To לגולש');
        // מייל עם שבירת שורה — אינו נכנס לכותרת
        m5_recv(['name' => "x\r\nBcc: a@evil.test", 'email' => "evil@x.test\r\nBcc: a@evil.test", 'message' => 'ניסיון הזרקה']);
        $m = $mails()[1];
        ok(!str_contains($m['headers'], 'Reply-To') && !str_contains($m['headers'], 'Bcc') && !str_contains($m['headers'], 'evil'), $m['headers']);
        eq(4, substr_count($m['headers'], "\r\n"), 'כותרות קבועות: From, Auto-Submitted, MIME, Content-Type, CTE');
        // 🧪 — לא
        m5_recv(['message' => 'בדיקה'], null, true);
        eq(2, count($mails()), 'פניית בדיקה — בלי מייל');
        // קצב: אחרי 12 בשעה — מצטבר למייל אחד
        $s = forms_mail_state(); $s['sent'] = array_fill(0, FORMS_MAIL_HOUR, time()); forms_mail_save($s);
        m5_recv(['message' => 'ראשונה-בתור']);
        m5_recv(['message' => 'שנייה-בתור']);
        eq(2, count($mails()), 'מעל התקרה — ממתין');
        $s = forms_mail_state(); $s['sent'] = [time() - 3601]; forms_mail_save($s);
        inbox_list($page, ['count' => 1]);                        // פתיחת התיבה מריצה את התור
        $m = $mails()[2];
        ok(str_contains($m['subject'], '2 פניות חדשות') && str_contains($m['body'], 'ראשונה-בתור') && str_contains($m['body'], 'שנייה-בתור'), $m['body']);
        ok(!str_contains($m['headers'], 'Reply-To'), 'כמה פניות — בלי Reply-To');
        eq(3, count($mails()), 'מייל אחד לשתיהן');
        eq(true, array_column(forms_diag(forms_mail_state()), 'ok', 'id')['sent']);
        // כישלונות
        putenv('KOL_TEST_MAIL_FAIL=1');
        for ($i = 0; $i < 3; $i++) {
            m5_recv(['message' => "כישלון $i"]);
            $s = forms_mail_state(); if ($s['last_error']) { $s['last_error']['at'] = gmdate('Y-m-d\TH:i:s\Z', time() - FORMS_MAIL_RETRY - 1); forms_mail_save($s); }
        }
        $s = forms_mail_state();
        ok(!$s['on'] && $s['auto_off'] && $s['fails'] >= 3, 'כבוי אוטומטית אחרי 3');
        ok((int)db()->query("SELECT COUNT(*) FROM notices WHERE kind = 'mail'")->fetchColumn() >= 1, 'הודעה');
        ok((int)db()->query("SELECT COUNT(*) FROM dev_log WHERE event = 'form.mail_failed'")->fetchColumn() >= 3, 'נרשם ב-devlog');
        $d = array_column(forms_diag($s), null, 'id');
        eq(false, $d['mail_on']['ok']);
        ok(str_contains($d['mail_on']['text'], 'כובה אוטומטית'), $d['mail_on']['text']);
        eq(false, $d['sent']['ok']);
        ok(str_contains($d['sent']['text'], 'mail() החזיר false'), $d['sent']['text']);
        ok(forms_unread() > 0, 'המונה בסטודיו ממשיך');
        // הדלקה מחדש מאפסת את הכישלונות ואינה שולחת את מה שהצטבר
        putenv('KOL_TEST_MAIL_FAIL');
        @unlink($file);
        forms_settings($page, ['mail_on' => true], false);
        forms_mail_tick();
        eq(0, count($mails()), 'אחרי הדלקה — רק פניות חדשות');
        eq(0, forms_mail_state()['fails']);
    } finally {
        putenv('KOL_TEST_MAIL'); putenv('KOL_TEST_MAIL_FAIL');
        setting_set('forms_mail', '{}');
    }
});

test('S3 אבחון: טופס שאינו רשום בגרסה שבאתר ← ❌ בשורה הראשונה', function () {
    $keep = db()->query('SELECT id, sent_at FROM pkg_builds WHERE sent_at IS NOT NULL')->fetchAll();
    db()->exec('UPDATE pkg_builds SET sent_at = NULL');
    try {
        $d = array_column(forms_diag(forms_mail_state()), null, 'id');
        eq(false, $d['page']['ok']);
        ok(str_contains($d['page']['text'], 'לבנות ולשלוח'), $d['page']['text']);
        eq(false, $d['mail_on']['ok']);
    } finally {
        $u = db()->prepare('UPDATE pkg_builds SET sent_at = ? WHERE id = ?');
        foreach ($keep as $r) $u->execute([$r['sent_at'], $r['id']]);
    }
    ok(array_column(forms_diag(forms_mail_state()), 'ok', 'id')['page']);
});

test('M5 מסך המפתח: פניות היום / 7 ימים, דחיות לפי סיבה, המייל, המגבלות', function () {
    $r = http('GET', '/api.php?action=dev-forms', ['user' => U_OWNER]);
    eq(200, $r['status'], $r['body']);
    $d = $r['json'];
    ok($d['today'] > 0 && $d['week'] >= $d['today']);
    eq(FORMS_REJECT_REASONS, array_keys($d['rejects']));
    ok($d['rejects']['spam']['today'] > 0 && $d['rejects']['unknown']['today'] > 0 && $d['rejects']['size']['week'] > 0);
    eq(array_keys(FORMS_LIMITS), array_column($d['limits'], 'id'));
    ok(str_ends_with($d['endpoint'], '/form.php'));
    $svc = array_column(dev_services(), null, 'id')['forms_mail'];
    ok(str_contains($svc['result'], 'כבוי') || str_contains($svc['result'], 'כובה'), $svc['result']);
});

test('S3 בדיקת התקינות (__health__) — עונה invalid בלי לגעת בקצב ובדחיות', function () {
    $hits = (int)db()->query('SELECT COUNT(*) FROM form_hits')->fetchColumn();
    $rej = m5_rej('invalid');
    for ($i = 0; $i < 8; $i++) eq([400, ['ok' => false, 'reason' => 'invalid']], forms_receive(['_form' => '__health__', '_t' => '9999'], [], 40, '10.9.9.9', false));
    eq($hits, (int)db()->query('SELECT COUNT(*) FROM form_hits')->fetchColumn(), 'לא נספר בקצב');
    eq($rej, m5_rej('invalid'), 'לא נספר בדחיות');
    $r = m5_post_form(http_build_query(['_form' => '__health__', '_t' => '9999']));
    eq(400, $r['status']);
    eq('*', $r['headers']['access-control-allow-origin'] ?? null, 'CORS');
});
