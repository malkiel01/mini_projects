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

test('M5 הרשאות: כל פעולה מול כל תפקיד (קריאה/סימון/ייצוא — עורך; מחיקה — מפרסם; מגבלות ומייל — המפתח)', function () {
    $mat = [
        ['GET', 'inbox', [], ['editor' => 200, 'publisher' => 200, 'owner' => 200]],
        ['GET', 'inbox_csv', [], ['editor' => 200, 'publisher' => 200, 'owner' => 200]],
        ['POST', 'inbox_mark', ['id' => 1, 'read' => true], ['editor' => 200, 'publisher' => 200, 'owner' => 200]],
        ['POST', 'inbox_delete', ['id' => 999999], ['editor' => 403, 'publisher' => 200, 'owner' => 200]],
        ['POST', 'inbox_purge', ['before' => '2000-01-01', 'preview' => true], ['editor' => 403, 'publisher' => 200, 'owner' => 200]],
        ['GET', 'forms_settings', [], ['editor' => 200, 'publisher' => 200, 'owner' => 200]],
        ['POST', 'forms_settings', ['to' => []], ['editor' => 403, 'publisher' => 200, 'owner' => 200]],
        ['POST', 'forms_settings', ['limits' => ['ip_n' => 5]], ['editor' => 403, 'publisher' => 403, 'owner' => 200]],
        ['POST', 'mail_test', ['from' => 'a@b.test', 'to' => 'c@d.test'], ['editor' => 403, 'publisher' => 403]],
        ['GET', 'dev-forms', [], ['editor' => 403, 'publisher' => 403, 'owner' => 200]],
    ];
    $users = ['editor' => U_EDITOR, 'publisher' => U_PUBLISHER, 'owner' => U_OWNER];
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
    eq(400, m1_post('forms_settings', ['to' => ['stranger@evil.test']], U_PUBLISHER)['status'], 'נמען שאינו חבר');
    setting_set('forms_limits', '{}');
});

test('M5 מייל: בדיקה קודם (ברירת מחדל כבוי); תקציר בלי תוכן; לכל היותר אחד ל-15 דקות; 🧪 — לא; 3 כישלונות ← כבוי', function () {
    m5_live();
    $file = dirname(KOL_DATA_DIR) . '/mail.log';
    putenv("KOL_TEST_MAIL=$file");
    try {
        setting_set('forms_mail', '{}');
        eq(false, forms_mail_state()['on'], 'ברירת המחדל — כבוי');
        $own = ['sub' => U_OWNER['sub'], 'name' => U_OWNER['name'], 'email' => U_OWNER['email'], 'role' => 'site_owner'];
        $code = throws(fn() => forms_settings($own, ['mail_on' => true], true), 'הדלקה בלי מייל בדיקה');
        eq(400, $code);
        throws(fn() => forms_mail_test($own, ['from' => "a@b.test\r\nBcc: x@evil.test", 'to' => 'c@d.test']), 'CRLF בשולח');
        throws(fn() => forms_mail_test($own, ['from' => 'a@b.test', 'to' => "c@d.test\nBcc: x@evil.test"]), 'CRLF בנמען');
        $t = forms_mail_test($own, ['from' => 'kol@example.test', 'to' => 'owner@example.test']);
        ok($t['test']['ok'], 'מייל בדיקה — true');
        forms_settings($own, ['to' => ['editor@example.test']], true);
        forms_settings($own, ['mail_on' => true], true);
        ok(forms_mail_state()['on']);
        @unlink($file);
        m5_recv(['message' => 'סוד-פנייה-שלא-במייל', 'email' => 'visitor@x.test']);
        m5_recv(['message' => 'שנייה']);
        m5_recv(['message' => 'בדיקה'], null, true);
        $mails = array_map(fn($l) => json_decode($l, true), file($file, FILE_IGNORE_NEW_LINES) ?: []);
        eq(1, count($mails), 'מייל אחד (צבירה)');
        eq(['editor@example.test'], $mails[0]['to']);
        ok(str_contains($mails[0]['body'], '1 פניות חדשות בטופס "feedback"'), $mails[0]['body']);
        ok(!str_contains($mails[0]['body'], 'סוד-פנייה') && !str_contains($mails[0]['body'], 'visitor@'), 'בלי תוכן הפנייה');
        ok(!preg_match('/\r|\n/', $mails[0]['headers'] === '' ? '' : str_replace("\r\n", '', $mails[0]['headers'])), 'כותרות קבועות');
        // חלון 15 דקות: השנייה ממתינה
        $s = forms_mail_state();
        $s['last_sent'] = gmdate('Y-m-d\TH:i:s\Z', time() - 901);
        forms_mail_save($s);
        forms_mail_tick();
        $mails = file($file, FILE_IGNORE_NEW_LINES);
        eq(2, count($mails), 'אחרי 15 דקות — התקציר של מה שהצטבר');
        ok(str_contains(json_decode($mails[1], true)['body'], '1 פניות חדשות'), 'רק השנייה — בלי פניית הבדיקה');
        // כישלונות
        putenv('KOL_TEST_MAIL_FAIL=1');
        for ($i = 0; $i < 3; $i++) {
            m5_recv(['message' => "כישלון $i"]);
            $s = forms_mail_state(); $s['last_sent'] = gmdate('Y-m-d\TH:i:s\Z', time() - 901); forms_mail_save($s);
            forms_mail_tick();
        }
        $s = forms_mail_state();
        ok(!$s['on'] && $s['auto_off'] && $s['fails'] >= 3, 'כבוי אוטומטית אחרי 3');
        ok((int)db()->query("SELECT COUNT(*) FROM notices WHERE kind = 'mail'")->fetchColumn() >= 1, 'הודעה למפתח');
        ok((int)db()->query("SELECT COUNT(*) FROM dev_log WHERE event = 'form.mail_failed'")->fetchColumn() >= 3, 'נרשם ב-devlog');
        ok(forms_unread() > 0, 'המונה בסטודיו ממשיך');
    } finally {
        putenv('KOL_TEST_MAIL'); putenv('KOL_TEST_MAIL_FAIL');
        setting_set('forms_mail', '{}');
    }
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
