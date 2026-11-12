<?php
/**
 * פרוסה M1 — בנייה ופרסום של פרויקט הקבצים (lib/build.php). כל בדיקה עומדת מול שורה ב-docs/spec-v2/slices/M1-…md §2–§3.
 * השליחה — מול tests/fake-wp.php: התוסף האמיתי 1.x (ה-ping), והמקבל המדומה v2 (kol_v2_mock) — החוזה של M2.
 * בדיקת הדפדפן (הרצת kol-data.js, החבילה מתיקייה סטטית, JS כבוי) — tests/browser/m1.cjs.
 */

declare(strict_types=1);

require_once KOL_ROOT . '/lib/build.php';

const M1_PAGE = "<!doctype html>\n<html lang=\"he\" dir=\"rtl\">\n<head>\n<meta charset=\"utf-8\">\n<title>רשימה</title>\n<link rel=\"stylesheet\" href=\"style.css\">\n"
    . "<link rel=\"stylesheet\" href=\"https://fonts.googleapis.com/css2?family=Assistant\">\n</head>\n<body>\n<h1>מילים</h1>\n"
    . "<ul class=\"list\" data-kol-list=\"words\" data-kol-sort=\"manual\">\n  <template><li data-kol-text=\"word\"></li></template>\n  <li class=\"static\">קבוע</li>\n</ul>\n"
    . "<div><div data-kol-list=\"words\" data-kol-prerender=\"none\"><template><b data-kol-text=\"word\"></b></template><div>פנימי</div></div></div>\n"
    . "<img src=\"missing.png\" alt=\"\">\n<script src=\"app.js\"></script>\n</body>\n</html>\n";

function m1_post(string $action, array $json, array $user = U_EDITOR): array
{
    return http('POST', "/api.php?action=$action", ['user' => $user, 'json' => $json]);
}

function m1_get(string $action, string $q = '', array $user = U_EDITOR): array
{
    return http('GET', "/api.php?action=$action$q", ['user' => $user]);
}

function m1_wp_opt(string $k, mixed $v): void
{
    $f = $GLOBALS['WP_DIR'] . '/options.json';
    $o = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    if ($v === null) unset($o[$k]); else $o[$k] = $v;
    file_put_contents($f, json_encode($o));
}

function m1_wp_get(string $k): mixed
{
    $o = json_decode((string)@file_get_contents($GLOBALS['WP_DIR'] . '/options.json'), true) ?: [];
    return $o[$k] ?? null;
}

/** קבצי הבדיקה בפרויקט — פעם אחת. */
function m1_files(): array
{
    static $ids;
    if ($ids) return $ids;
    http('GET', '/api.php?action=pf_tree', ['user' => U_EDITOR]);
    $ids = [];
    if (!pf_by_path(BUILD_KD)) m1_post('pf_create', ['path' => BUILD_KD, 'content' => (string)file_get_contents(BUILD_SEED_KD)]);
    foreach (['m1/list.html' => M1_PAGE, 'm1/style.css' => ".list { color: red; }\n.bg { background: url(\"../m1/none.png\"); }\n", 'm1/app.js' => "// שורה 1\ntry { localStorage.x = 1; } catch (e) {}\n"] as $p => $c) {
        $r = m1_post('pf_create', ['path' => $p, 'content' => $c]);
        if ($r['status'] !== 200) throw new TestFail("pf_create $p: " . $r['body']);
        $ids[$p] = $r['json']['file']['id'];
    }
    return $ids;
}

/** בנייה מלאה כמו שהדפדפן עושה: לכל עמוד — קטע אחד בכל מכל (חוץ מ-"none"), ואז סיום. */
function m1_build(array $user = U_EDITOR, array $opts = []): array
{
    m1_files();
    $s = m1_post('build_start', $opts, $user);
    if ($s['status'] !== 200) throw new TestFail('build_start: ' . $s['body']);
    foreach ($s['json']['pages'] as $page) {
        $src = pf_blob_get(pf_by_path($page)['sha']);
        $parts = array_map(fn($c) => str_contains($c['attrs'], 'data-kol-prerender="none"') ? null : ['list' => $c['list'], 'html' => "<li data-kol-pre=\"\">כתוב-מראש</li>"], build_containers($src));
        $r = m1_post('build_page', ['id' => $s['json']['id'], 'path' => $page, 'containers' => $parts], $user);
        if ($r['status'] !== 200) throw new TestFail("build_page $page: " . $r['body']);
    }
    $f = m1_post('build_finish', ['id' => $s['json']['id'], 'externals' => ['https://cdn.example.test/x.js', 'not a url']], $user);
    if ($f['status'] !== 200) throw new TestFail('build_finish: ' . $f['body']);
    return $s['json'] + ['build' => $f['json']['build']];
}

function m1_files_of(int $id): array
{
    return json_decode(build_row($id)['files'], true);
}

test('בנייה: עורך בונה; כל קובץ בחבילה בייט בבייט מהמאגר — חוץ מ-data/words.json ומהעמודים שנכתבו מראש; kol.json יוצא', function () {
    $b = m1_build();
    eq('built', $b['build']['status']);
    ok(in_array('m1/list.html', $b['pages'], true), 'העמוד עם הרשימה ברשימת הכתיבה מראש');
    $files = m1_files_of($b['id']);
    ok(!isset($files['kol.json']), 'kol.json אינו נשלח (ברירת המחדל)');
    ok(in_array('kol.json', array_column($b['build']['report']['excluded'], 'path'), true), 'ויוצא בגלוי — ברשימת "יצאו"');
    ok(isset($files['data/words.json']), 'data/words.json בחבילה');
    $rows = db()->query("SELECT path, kind, sha FROM pf_files WHERE deleted_at IS NULL AND kind = 'file'")->fetchAll();
    $checked = 0;
    foreach ($rows as $r) {
        if (!isset($files[$r['path']])) { ok(build_is_config($r['path']), "יצא רק קובץ הגדרות: {$r['path']}"); continue; }
        $f = $files[$r['path']];
        if ($r['path'] === 'data/words.json') continue;
        if ($f['src'] === 'prerender') { eq($r['sha'], $f['orig'], "המקור של {$r['path']}"); continue; }
        eq($r['sha'], $f['sha'], "בייט בבייט: {$r['path']}");
        eq($r['sha'], hash_file('sha256', pf_blob_path($f['sha'])), "sha256 מחושב מחדש: {$r['path']}");
        $checked++;
    }
    ok($checked >= 3, "נבדקו $checked קבצים");
    eq('prerender', $files['m1/list.html']['src'], 'העמוד עם הרשימה נכתב מראש');
    // עם "פרסם גם אותם"
    $all = m1_build(U_EDITOR, ['include_config' => true]);
    ok(isset(m1_files_of($all['id'])['kol.json']), 'kol.json בחבילה כשביקשו');
    // קובץ שהבונה הוציא — מופיע ב"יצאו"
    $ex = m1_build(U_EDITOR, ['exclude' => ['m1/app.js']]);
    ok(!isset(m1_files_of($ex['id'])['m1/app.js']), 'הוצא');
    eq('הוצא בידי הבונה', array_column($ex['build']['report']['excluded'], 'why', 'path')['m1/app.js'] ?? null);
});

test('בנייה: כתיבה מראש — רק בתוך המכל, המקור בייט בבייט מסביב; "none" — המכל לא נגע', function () {
    $b = m1_build();
    $f = m1_files_of($b['id'])['m1/list.html'];
    $out = pf_blob_get($f['sha']);
    $want = str_replace('data-kol-sort="manual">', 'data-kol-sort="manual" data-kol-prerendered>', M1_PAGE);
    $want = str_replace("  <li class=\"static\">קבוע</li>\n</ul>", "  <li class=\"static\">קבוע</li>\n<li data-kol-pre=\"\">כתוב-מראש</li></ul>", $want);
    eq($want, $out, 'העמוד הבנוי = המקור + סימון + הקטע');
    eq(1, $f['lists'], 'מכל אחד נכתב (השני — "none")');
});

test('בנייה: קטע עוין נדחה — יציאה מהמכל, הערה פתוחה, סקריפט פתוח; מספר מכלים שגוי; בונה אחר; עמוד שאינו ברשימה', function () {
    foreach (['</ul><script>top.x=1</script><ul>', '<li>', '</li>', '<!-- <li>', '<script>alert(1)', '<li></ul>', '<textarea></li>'] as $bad) {
        ok(!build_fragment_ok($bad), "נדחה: $bad");
    }
    ok(build_fragment_ok('<li data-kol-pre=""><img src="a.png"><b>x</b><script>if (a < b) {}</script><!-- הערה --></li>'), 'קטע סגור עובר');
    m1_files();
    $s = m1_post('build_start', [])['json'];
    $one = fn(string $html) => ['list' => 'words', 'html' => $html];
    $r = m1_post('build_page', ['id' => $s['id'], 'path' => 'm1/list.html', 'containers' => [$one('</ul><script>top.x=1</script><ul>'), null]]);
    eq([422, 'fragment'], [$r['status'], $r['json']['code'] ?? null], 'יציאה מהמכל');
    $r = m1_post('build_page', ['id' => $s['id'], 'path' => 'm1/list.html', 'containers' => [$one('<li>x</li>')]]);
    eq([422, 'containers'], [$r['status'], $r['json']['code'] ?? null], 'מספר מכלים שגוי');
    $r = m1_post('build_page', ['id' => $s['id'], 'path' => 'm1/list.html', 'containers' => [['list' => 'other', 'html' => '<li>x</li>'], null]]);
    eq(422, $r['status'], 'שם רשימה אחר');
    eq(403, m1_post('build_page', ['id' => $s['id'], 'path' => 'm1/list.html', 'containers' => [$one('<li>x</li>'), null]], U_PUBLISHER)['status'], 'בונה אחר אינו כותב לבנייה שלי');
    eq(422, m1_post('build_page', ['id' => $s['id'], 'path' => 'm1/style.css', 'containers' => []])['status'], 'קובץ שאינו ברשימה');
    eq(PV_WORDS, array_key_last(array_filter(m1_files_of($s['id']), fn($f) => $f['src'] === 'data')), 'הנתונים — רק מהשרת');
    eq(200, m1_post('build_fail', ['id' => $s['id'], 'error' => ['msg' => 'x']])['status']);
});

test('בנייה: המסגרת (p) מגישה את המקור עם הסקריפט של הבנייה; התצוגה הבנויה (v) — הבייטים בדיוק, בארגז חול; אסימון מזויף — 403', function () {
    m1_files();
    $s = m1_post('build_start', [])['json'];
    $p = pv_get('m1/list.html', $s['token']);
    eq(200, $p['status']);
    pv_isolated($p, 'מסגרת הבנייה');
    ok(str_contains($p['body'], '__kol/bridge.js') && !str_contains($p['body'], 'data-kol-prerendered'), 'המקור, עם הסקריפט');
    $js = pv_get('__kol/bridge.js', $s['token']);
    ok(str_contains($js['body'], 'KolBuild'), 'הסקריפט של הבנייה (assets/js/build.js)');
    $w = json_decode(pv_get('data/words.json', $s['token'])['body'], true);
    ok(is_array($w['items'] ?? null), 'data/words.json מהחבילה');
    foreach ($w['items'] as $it) ok(!str_contains((string)$it['audio'], 'api.php'), 'אין כתובת של הכלי בנתונים');
    m1_post('build_page', ['id' => $s['id'], 'path' => 'm1/list.html', 'containers' => [['list' => 'words', 'html' => '<li data-kol-pre="">a</li>'], null]]);
    foreach (array_diff($s['pages'], ['m1/list.html']) as $page) m1_post('build_page', ['id' => $s['id'], 'path' => $page, 'unsupported' => true]);
    m1_post('build_finish', ['id' => $s['id']]);
    eq(403, pv_get('m1/list.html', $s['token'])['status'], 'מסגרת הבנייה נסגרת כשהבנייה הסתיימה');
    $view = m1_get('build_get', '&id=' . $s['id'])['json']['view'];
    $v = pv_get('m1/list.html', $view);
    pv_isolated($v, 'התצוגה הבנויה');
    eq(pf_blob_get(m1_files_of($s['id'])['m1/list.html']['sha']), $v['body'], 'בדיוק מה שייצא — בלי גשר');
    eq(404, pv_get('kol.json', $view)['status'], 'קובץ שיצא מהחבילה — 404');
    eq(404, pv_get('../../lib/db.php', $view)['status'], 'טיפוס מחוץ לחבילה — 404');
    eq(403, pv_get('m1/list.html', 'b' . base_convert((string)$s['id'], 10, 36) . '-v-' . base_convert((string)(time() + 60), 10, 36) . '.' . str_repeat('A', 43))['status'], 'חתימה מזויפת');
    $tok = build_token(999999, 'v');
    eq(403, pv_get('index.html', $tok)['status'], 'בנייה שאינה קיימת');
});

test('מה ישתנה: פרסום ראשון; הפניה שבורה, אחסון בדפדפן, משאב חיצוני — עם קובץ ושורה; כשל בנייה — אין גרסה', function () {
    $b = m1_build();
    $rep = $b['build']['report'];
    eq(true, $rep['diff']['first'], 'אין עדיין גרסה במסלול החדש');
    $w = array_map(fn($x) => [$x['kind'], $x['file'] ?? null, $x['line'] ?? null], $rep['warnings']);
    ok(in_array(['broken', 'm1/list.html', 16], $w, true), 'הפניה שבורה: missing.png, שורה 16 · ' . json_encode($w, JSON_UNESCAPED_UNICODE));
    ok(in_array(['broken', 'm1/style.css', 2], $w, true), 'url() שבור ב-CSS');
    ok(in_array(['sandbox', 'm1/app.js', 2], $w, true), 'localStorage — שורה 2');
    $ext = array_map(fn($x) => [$x['url'], $x['file'], $x['line']], $rep['externals']);
    ok(in_array(['https://fonts.googleapis.com/css2?family=Assistant', 'm1/list.html', 7], $ext, true), 'גופן חיצוני מהסריקה');
    ok(in_array(['https://cdn.example.test/x.js', null, null], $ext, true), 'מה שהמסגרת ביקשה בפועל');
    eq(false, in_array('not a url', array_column($rep['externals'], 'url'), true), 'קלט זבל מהדפדפן אינו נשמר');
    ok($rep['data']['total'] >= 0 && isset($rep['data']['audio']), 'נתונים: מילים והקלטות');
    // כשל בנייה
    $s = m1_post('build_start', [])['json'];
    $r = m1_post('build_fail', ['id' => $s['id'], 'error' => ['msg' => 'ReferenceError: x is not defined', 'file' => 'js/kol-data.js', 'line' => 12]]);
    eq(['failed', 'js/kol-data.js', 12], [$r['json']['build']['status'], $r['json']['build']['error']['file'], $r['json']['build']['error']['line']]);
    eq(409, m1_post('build_finish', ['id' => $s['id']])['status'], 'בנייה שנכשלה אינה נעשית גרסה');
    // "בלי כתיבה מראש"
    $n = m1_build(U_EDITOR, ['prerender' => false]);
    eq([], $n['pages']);
    eq('repo', m1_files_of($n['id'])['m1/list.html']['src'], 'העמוד כמו שהוא');
});

test('הרשאות: אורח 401; זר 403; עורך בונה ואינו שולח; מפרסם אינו שולח גרסה שבנה אחר', function () {
    eq(401, http('POST', '/api.php?action=build_start', ['json' => []])['status'], 'אורח');
    eq(403, m1_post('build_start', [], U_STRANGER)['status'], 'זר');
    $b = m1_build();
    eq(403, m1_post('build_send', ['id' => $b['id']])['status'], 'עורך אינו שולח');
    $r = m1_post('build_send', ['id' => $b['id']], U_PUBLISHER);
    eq([403, 'not_builder'], [$r['status'], $r['json']['code'] ?? null], 'מפרסם — רק גרסה שבנה בעצמו');
    eq(403, http('POST', '/api.php?action=build_send', ['user' => U_PUBLISHER, 'json' => ['id' => $b['id']], 'csrf' => false])['status'], 'בלי CSRF');
    eq(403, m1_post('kd_update', [])['status'], 'עדכון kol-data.js — בעלים בלבד');
    eq(403, m1_get('notices')['status'], 'הודעות — בעלים בלבד');
});

test('שליחה: תוסף 1.x ← "נדרש 2.0"; הגרסה נשמרת built, ושום דבר לא נשלח', function () {
    wp_site_ready();
    m1_wp_opt('kol_v2_mock', null);
    $b = m1_build(U_PUBLISHER);
    $r = m1_post('build_send', ['id' => $b['id']], U_PUBLISHER);
    eq([409, 'plugin_v1'], [$r['status'], $r['json']['code'] ?? null], $r['body']);
    ok(str_contains($r['json']['error'], 'נדרש 2.0'), $r['json']['error']);
    eq('built', build_row($b['id'])['status']);
    eq(null, build_row($b['id'])['version'], 'לא נתפס מספר גרסה');
});

test('שליחה (מקבל v2): הכול נשלח; שינוי בקובץ אחד ← גוש אחד; מונה משותף; הודעה לבעלים', function () {
    wp_site_ready();
    m1_wp_opt('kol_v2_mock', true);
    m1_wp_opt('v2_puts', []);
    $old = (int)db()->query('SELECT COALESCE(MAX(n), 0) FROM versions')->fetchColumn();
    $b = m1_build(U_PUBLISHER);
    $r = m1_post('build_send', ['id' => $b['id']], U_PUBLISHER);
    eq(200, $r['status'], $r['body']);
    eq('live', $r['json']['build']['status']);
    $n = $r['json']['build']['version'];
    ok($n > $old, "מספר הגרסה ($n) אחרי הגרסאות של המסלול הישן ($old)");
    $uniq = count(array_unique(array_column(m1_files_of($b['id']), 'sha')));
    eq($uniq, count(m1_wp_get('v2_puts')), 'כל הגושים נשלחו, כל אחד פעם אחת');
    $man = json_decode((string)file_get_contents($GLOBALS['WP_DIR'] . '/v2/live.json'), true);
    eq($n, $man['version']);
    eq(array_keys(m1_files_of($b['id'])), array_keys($man['files']), 'המניפסט באתר = החבילה');
    // שינוי בקובץ אחד
    $id = m1_files()['m1/style.css'];
    $rev = http('GET', "/api.php?action=pf_read&id=$id", ['user' => U_EDITOR])['json']['file']['rev'];
    eq(200, m1_post('pf_write', ['id' => $id, 'rev' => $rev, 'content' => ".list { color: blue; }\n"])['status']);
    eq(true, m1_get('build_get', '&id=' . $b['id'], U_PUBLISHER)['json']['build']['stale'], 'הגרסה הבנויה מסומנת "הפרויקט השתנה מאז"');
    $b2 = m1_build(U_PUBLISHER);
    $d = $b2['build']['report']['diff'];
    eq([false, ['m1/style.css'], [], []], [$d['first'], array_column($d['changed'], 'path'), $d['added'], $d['removed']], 'מה ישתנה: קובץ אחד');
    m1_wp_opt('v2_puts', []);
    $r2 = m1_post('build_send', ['id' => $b2['id']], U_PUBLISHER);
    eq([200, 1], [$r2['status'], $r2['json']['sent'] ?? null], $r2['body']);
    eq(1, count(m1_wp_get('v2_puts')), 'גוש אחד בלבד');
    eq('superseded', build_row($b['id'])['status'], 'הקודמת הוחלפה');
    // המסלול הישן אחריה — מספר גבוה עוד יותר
    eq($r2['json']['build']['version'] + 1, pub_next_n(), 'מונה משותף');
    // הודעה לבעלים
    $no = m1_get('notices', '', U_OWNER)['json']['notices'];
    ok($no && $no[0]['build'] === $b2['id'] && str_contains($no[0]['text'], 'מפרסם בדיקה'), 'הודעה: מי, איזו גרסה');
    $after = m1_post('notice_read', ['ids' => array_column($no, 'id')], U_OWNER)['json']['notices'];
    eq([], $after, 'נקראה — אינה מוצגת שוב');
    ok((int)db()->query("SELECT COUNT(*) FROM audit WHERE action = 'pkg_publish'")->fetchColumn() >= 2, 'ביומן הפעולות');
});

test('שליחה: כשל באמצע ← "המשך" אינו שולח שוב מה שהאתר אישר; עד המניפסט — דבר באתר לא השתנה', function () {
    wp_site_ready();
    m1_wp_opt('kol_v2_mock', true);
    $live = (int)m1_wp_get('v2_live');
    foreach (['a', 'b', 'c'] as $i => $x) m1_post('pf_create', ['path' => "m1/new-$x.txt", 'content' => "קובץ חדש $x " . microtime()]);
    $b = m1_build(U_PUBLISHER);
    m1_wp_opt('v2_puts', []);
    m1_wp_opt('v2_fail_after', 1);                 // הגוש הראשון עובר, השני נכשל
    $r = m1_post('build_send', ['id' => $b['id']], U_PUBLISHER);
    eq(502, $r['status'], 'הכשל מגיע לכלי');
    eq('sending', build_row($b['id'])['status'], 'הגרסה "בשליחה", לא באוויר');
    eq($live, (int)m1_wp_get('v2_live'), 'באתר — לא השתנה');
    $first = count(m1_wp_get('v2_puts') ?? []);
    $r = m1_post('build_send', ['id' => $b['id']], U_PUBLISHER);
    eq(200, $r['status'], $r['body']);
    $puts = m1_wp_get('v2_puts');
    eq(count($puts), count(array_unique($puts)), 'שום גוש לא נשלח פעמיים');
    eq([1, 3], [$first, count($puts)], 'אחד לפני הכשל; שלושת הקבצים החדשים — בדיוק, ובלי כפילות');
});

test('מקבל v2: מניפסט עם קובץ חסר, ששונה אחרי החתימה, nonce חוזר, זמן ישן, גרסה יורדת ונתיב עוין — נדחים; הגרסה שבאוויר לא זזה', function () {
    wp_site_ready();
    m1_wp_opt('kol_v2_mock', true);
    $live = (int)m1_wp_get('v2_live');
    $last = (int)m1_wp_get('v2_last');
    $man = json_decode((string)file_get_contents($GLOBALS['WP_DIR'] . '/v2/live.json'), true);
    $k = pub_key();
    $send = function (array $m, ?string $tamper = null) use ($k) {
        $bytes = json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sig = base64_encode(sodium_crypto_sign_detached($bytes, base64_decode($k['secret'])));
        return wp_send('manifest', wp_signed('manifest', ['manifest' => $tamper ?? $bytes, 'manifest_sig' => $sig, 'kid' => $k['kid']]));
    };
    $fresh = fn(array $over = []) => array_replace($man, ['version' => $last + 1, 'time' => time(), 'nonce' => bin2hex(random_bytes(16))], $over);
    $m = $fresh();
    $m['files']['ghost.js'] = ['sha256' => str_repeat('a', 64), 'size' => 3, 'type' => 'text/javascript'];
    eq('asset_missing', $send($m)['json']['code'] ?? null, 'קובץ שלא הגיע');
    $m = $fresh();
    $bytes = json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    eq('bad_signature', $send($m, str_replace('"entry"', '"entry" ', $bytes))['json']['code'] ?? null, 'מניפסט ששונה אחרי החתימה');
    eq('stale', $send($fresh(['time' => time() - 3600]))['json']['code'] ?? null, 'זמן ישן');
    eq('old_version', $send($fresh(['version' => $last]))['json']['code'] ?? null, 'אותו מספר');
    eq('wrong_site', $send($fresh(['site' => 'evil.example']))['json']['code'] ?? null, 'אתר אחר');
    $m = $fresh();
    $m['files']['../../wp-config.php'] = reset($man['files']);
    eq('bad_path', $send($m)['json']['code'] ?? null, 'נתיב עם ..');
    eq([$live, $last], [(int)m1_wp_get('v2_live'), (int)m1_wp_get('v2_last')], 'הגרסה שבאוויר לא זזה');
    $ok = $fresh();
    eq(200, $send($ok)['status'], 'מניפסט תקין עובר');
    $again = $send(array_replace($ok, ['version' => $last + 2]));
    eq('replay', $again['json']['code'] ?? null, 'nonce חוזר — נדחה');
    m1_wp_opt('kol_v2_mock', null);
});

test('kol-data.js: עדכון ל-1.1.0 — בעלים בלבד, נקודת שחזור לפני, שמירה רגילה בהיסטוריה', function () {
    $r = pf_by_path(BUILD_KD);
    if (!$r) { m1_post('pf_create', ['path' => BUILD_KD, 'content' => "window.KolData = { version: '1.0.0' };\n"]); $r = pf_by_path(BUILD_KD); }
    else {
        $rev = (int)$r['rev'];
        m1_post('pf_write', ['id' => (int)$r['id'], 'rev' => $rev, 'content' => "window.KolData = { version: '1.0.0' };\n"]);
    }
    $st = builds_list(['sub' => U_OWNER['sub'], 'role' => 'owner'])['kd'];
    eq(['1.0.0', false, false], [$st['version'], $st['prerender'], $st['same']]);
    $snaps = (int)db()->query('SELECT COUNT(*) FROM snapshots')->fetchColumn();
    eq(409, m1_post('kd_update', ['rev' => $st['rev'] - 1], U_OWNER)['status'], 'מצב ישן של המסך — לא דורס');
    $u = m1_post('kd_update', ['rev' => $st['rev']], U_OWNER);
    eq(200, $u['status'], $u['body']);
    eq(['1.1.0', true, true], [$u['json']['kd']['version'], $u['json']['kd']['prerender'], $u['json']['kd']['same']]);
    eq($snaps + 1, (int)db()->query('SELECT COUNT(*) FROM snapshots')->fetchColumn(), 'נקודת שחזור לפני');
    eq(file_get_contents(BUILD_SEED_KD), pf_blob_get(pf_by_path(BUILD_KD)['sha']), 'התוכן — הזרע');
    $h = db()->query('SELECT note FROM pf_history WHERE file_id = ' . (int)$st['id'] . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
    ok(str_contains((string)$h, '1.1.0'), "בהיסטוריה: $h");
});
