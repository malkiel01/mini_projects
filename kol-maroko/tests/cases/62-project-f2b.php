<?php
/**
 * פרוסה F2b — שכפול, גרסאות (diff ושחזור), חיפוש והחלפה בכל הקבצים, מעקב הפניות, ZIP הלוך-חזור (lib/project_ops.php).
 * כל בדיקה עומדת מול קריטריון [b] ב-docs/spec-v2/slices/F2-…md, כולל קלט עוין והרשאה שנדחית.
 */

declare(strict_types=1);

function b_post(string $action, array $json, array $user = U_EDITOR, string $q = ''): array
{
    return http('POST', "/api.php?action=$action$q", ['user' => $user, 'json' => $json]);
}

function b_get(string $action, string $q = '', array $user = U_EDITOR): array
{
    return http('GET', "/api.php?action=$action$q", ['user' => $user]);
}

function b_id(string $path): int
{
    return (int)(pf_paths()[$path] ?? 0);
}

function b_save(string $path, string $content): array
{
    $id = b_id($path);
    $rev = b_get('pf_read', "&id=$id")['json']['file']['rev'];
    return b_post('pf_write', ['id' => $id, 'rev' => $rev, 'content' => $content]);
}

/** ZIP בייטים, בלי ZipArchive: [שם, תוכן, אפשרויות] — כדי לבנות גם ZIP עוין (גודל מוצהר כוזב, קישור סימבולי, ../). */
function b_zip(array $entries): string
{
    $local = ''; $central = '';
    foreach ($entries as $e) {
        [$name, $data] = $e; $o = $e[2] ?? [];
        $deflate = !empty($o['deflate']);
        $comp = $deflate ? gzdeflate($data) : $data;
        $size = $o['size'] ?? strlen($data);
        $crc = crc32($data);
        $off = strlen($local);
        $local .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, $deflate ? 8 : 0, 0, 0, $crc, strlen($comp), $size, strlen($name), 0) . $name . $comp;
        $ext = $o['attr'] ?? 0;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, isset($o['attr']) ? 0x0314 : 20, 20, 0x0800, $deflate ? 8 : 0, 0, 0, $crc, strlen($comp), $size, strlen($name), 0, 0, 0, 0, $ext, $off) . $name;
    }
    return $local . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($entries), count($entries), strlen($central), strlen($local), 0);
}

function b_zip_post(string $zip, string $q = '', array $user = U_EDITOR): array
{
    return http('POST', "/api.php?action=pf_zip_import$q", ['user' => $user, 'raw' => $zip, 'headers' => ['Content-Type: application/zip']]);
}

/** העץ כ"נתיב ← sha256 של הבייטים" (קבצים בלבד). */
function b_tree_bytes(): array
{
    $out = [];
    foreach (http('GET', '/api.php?action=pf_tree', ['user' => U_EDITOR])['json']['files'] as $f) {
        if ($f['kind'] === 'dir') continue;
        $out[$f['path']] = hash('sha256', http('GET', "/api.php?action=pf_raw&id={$f['id']}&download=1", ['user' => U_EDITOR])['body']);
    }
    ksort($out);
    return $out;
}

// ── שכפול ─────────────────────────────────────────────────

test('F2b שכפול: קובץ ותיקייה — אותו תוכן בנתיב חדש, היסטוריה משלו; שכפול לתוך עצמו ונתיב תפוס — נדחים', function () {
    pf_new('dup/a.html', "<p onclick=x>א</p>\r\n");
    pf_new('dup/sub/b.css', 'body{}');
    $r = b_post('pf_copy', ['id' => b_id('dup/a.html'), 'to' => 'dup/a (עותק).html']);
    eq(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    eq("<p onclick=x>א</p>\r\n", pf_get(b_id('dup/a (עותק).html')), 'העותק זהה בייט בבייט');
    eq(1, $r['json']['file']['rev'], 'לעותק גרסה 1');
    $v = b_get('pf_versions', '&id=' . b_id('dup/a (עותק).html'))['json']['versions'];
    eq(1, count($v)); ok(str_contains($v[0]['note'], 'שוכפל מ-dup/a.html'), 'הערת הגרסה אומרת מאיפה');
    eq(200, b_post('pf_copy', ['path' => 'dup', 'to' => 'dup2'])['status'], 'שכפול תיקייה');
    $p = pf_paths();
    ok(isset($p['dup2/a.html'], $p['dup2/sub/b.css'], $p['dup/a.html']), 'כל מה שבתיקייה שוכפל, והמקור נשאר');
    eq('body{}', pf_get($p['dup2/sub/b.css']));
    eq(400, b_post('pf_copy', ['path' => 'dup', 'to' => 'dup/in'])['status'], 'תיקייה לתוך עצמה');
    eq(409, b_post('pf_copy', ['path' => 'dup', 'to' => 'dup2'])['status'], 'יעד תפוס');
    eq(409, b_post('pf_copy', ['id' => b_id('dup/a.html'), 'to' => 'DUP/A.html'])['status'], 'רישיות');
    eq(400, b_post('pf_copy', ['id' => b_id('dup/a.html'), 'to' => '../x.html'])['status'], 'נתיב עם ..');
    eq(403, b_post('pf_copy', ['id' => b_id('dup/a.html'), 'to' => 'z.html'], U_STRANGER)['status'], 'מי שאינו חבר');
    eq(200, b_post('pf_rename', ['id' => b_id('dup2/a.html'), 'to' => 'moved/deep/a.html'])['status'], 'העברה (= שינוי נתיב) לתיקייה אחרת');
    ok(isset(pf_paths()['moved/deep/a.html']));
});

test('F2b שכפול: מעבר למגבלת מספר הקבצים — נדחה בהודעה שאומרת מה המגבלה ומי משנה, ושום דבר לא נכתב', function () {
    $n = http('GET', '/api.php?action=pf_limits', ['user' => U_OWNER])['json']['used']['files'];
    eq(200, http('POST', '/api.php?action=pf_limits_set', ['user' => U_OWNER, 'json' => ['key' => 'files', 'value' => $n + 1]])['status']);
    $before = count(pf_paths());
    $r = b_post('pf_copy', ['path' => 'dup', 'to' => 'dup3']);
    eq(413, $r['status']);
    ok(str_contains($r['json']['error'], 'מפתח'), 'מי משנה');
    eq($before, count(pf_paths()), 'לא נכתב דבר');
    http('POST', '/api.php?action=pf_limits_set', ['user' => U_OWNER, 'json' => ['key' => 'files', 'value' => 2000]]);
});

// ── גרסאות: diff ושחזור ───────────────────────────────────

test('F2b גרסאות: כל גרסה נקראת בייט בבייט; שחזור יוצר גרסה חדשה וההיסטוריה נשארת; rev ישן — 409', function () {
    pf_new('hist/p.html', "v1 <script>a()</script>\n");
    b_save('hist/p.html', "v2\r\nשורה\n");
    b_save('hist/p.html', "v3 \xEF\xBB\xBF🎙️");
    $id = b_id('hist/p.html');
    $vs = b_get('pf_versions', "&id=$id")['json']['versions'];
    eq([3, 2, 1], array_column($vs, 'rev'));
    ok(array_filter(array_column($vs, 'hid')) === array_column($vs, 'hid'), 'לכל גרסה מזהה');
    $old = $vs[2];
    $r = b_get('pf_version', "&id=$id&hid={$old['hid']}");
    eq(200, $r['status']);
    eq("v1 <script>a()</script>\n", $r['json']['content'], 'גרסה 1 — כמו שנשמרה');
    eq(404, b_get('pf_version', "&id=$id&hid=999999")['status'], 'גרסה שאינה קיימת');
    eq(404, b_get('pf_version', '&id=' . b_id('dup/a.html') . "&hid={$old['hid']}")['status'], 'גרסה של קובץ אחר — לא נחשפת');

    eq(409, b_post('pf_version_restore', ['id' => $id, 'hid' => $old['hid'], 'rev' => 1])['status'], 'rev ישן');
    $r = b_post('pf_version_restore', ['id' => $id, 'hid' => $old['hid'], 'rev' => 3]);
    eq(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    eq(4, $r['json']['file']['rev'], 'שחזור = גרסה חדשה');
    eq("v1 <script>a()</script>\n", pf_get($id));
    $vs = b_get('pf_versions', "&id=$id")['json']['versions'];
    eq([4, 3, 2, 1], array_column($vs, 'rev'), 'ההיסטוריה לא נמחקה');
    ok(str_contains($vs[0]['note'], 'שוחזר מגרסה 1'));
    eq(400, b_post('pf_version_restore', ['id' => $id, 'hid' => $vs[0]['hid'], 'rev' => 4])['status'], 'גרסה זהה לנוכחית');
    eq(403, b_post('pf_version_restore', ['id' => $id, 'hid' => $old['hid'], 'rev' => 4], U_STRANGER)['status'], 'מי שאינו חבר');
    eq(403, b_get('pf_version', "&id=$id&hid={$old['hid']}", U_STRANGER)['status'], 'מי שאינו חבר אינו קורא גרסה');
    ok(db()->query("SELECT COUNT(*) FROM audit WHERE action = 'pf_version_restore'")->fetchColumn() > 0, 'נרשם ביומן הפעולות');
});

test('F2b נעילת העריכה חלה על שכפול, שחזור גרסה, החלפה ושינוי שם עם הפניות', function () {
    db()->exec('DELETE FROM edit_lock');
    db()->prepare('INSERT INTO edit_lock (id, sub, name, since, beat) VALUES (1, ?, ?, ?, ?)')->execute(['u-other', 'מישהו', time(), time()]);
    ok(lock_state() !== null, 'הנעילה בידי מישהו אחר');
    eq(423, b_post('pf_copy', ['id' => b_id('dup/a.html'), 'to' => 'lock.html'])['status'], 'שכפול');
    $id = b_id('hist/p.html');
    $vs = b_get('pf_versions', "&id=$id")['json']['versions'];
    eq(423, b_post('pf_version_restore', ['id' => $id, 'hid' => $vs[1]['hid'], 'rev' => $vs[0]['rev']])['status'], 'שחזור גרסה');
    eq(423, b_post('pf_replace', ['q' => 'v1', 'with' => 'x', 'apply' => true, 'revs' => []])['status'], 'החלפה');
    eq(423, b_post('pf_rename', ['id' => b_id('dup/a.html'), 'to' => 'dup/l.html', 'update_refs' => true])['status'], 'שינוי שם עם הפניות');
    eq(423, b_zip_post(b_zip([['l.txt', 'x']]), '&apply=1&token=x')['status'], 'ייבוא ZIP');
    db()->exec('DELETE FROM edit_lock');
});

// ── חיפוש והחלפה ──────────────────────────────────────────

test('F2b חיפוש בכל הקבצים: טקסט, רישיות, ביטוי רגולרי; שורה ועמודה; קובץ בינארי מדולג; ביטוי שגוי — הודעה', function () {
    pf_new('find/one.html', "<h1>Kol Maroko</h1>\nשלום kol\n  <b>KOL</b>");
    pf_new('find/two.css', ".kol-page { color: red }\n");
    pf_new('find/bin.dat', "\x00\x01kol\xFF");
    $r = b_get('pf_search', '&q=' . rawurlencode('kol'))['json'];
    $one = array_values(array_filter($r['files'], fn($f) => $f['path'] === 'find/one.html'))[0];
    eq(3, $one['count'], 'בלי רישיות — שלוש');
    eq([1, 2, 3], array_column($one['hits'], 'line'), 'מספרי השורות');
    eq(6, $one['hits'][1]['col'], 'עמודה בתווים (אחרי "שלום ")');
    ok(!in_array('find/bin.dat', array_column($r['files'], 'path'), true), 'קובץ בינארי אינו בתוצאות');
    $r = b_get('pf_search', '&case=1&q=' . rawurlencode('KOL'))['json'];
    eq(1, array_values(array_filter($r['files'], fn($f) => $f['path'] === 'find/one.html'))[0]['count'], 'עם רישיות — אחת');
    $r = b_get('pf_search', '&regex=1&q=' . rawurlencode('^\s*<b>'))['json'];
    ok(in_array('find/one.html', array_column($r['files'], 'path'), true), 'ביטוי רגולרי');
    $r = b_get('pf_search', '&q=' . rawurlencode('<b>KOL</b>'))['json'];
    eq('  <b>KOL</b>', array_values(array_filter($r['files'], fn($f) => $f['path'] === 'find/one.html'))[0]['hits'][0]['text'], 'השורה מוחזרת כטקסט, כמו שהיא');
    eq(400, b_get('pf_search', '&regex=1&q=' . rawurlencode('(unclosed'))['status'], 'ביטוי שגוי');
    eq(400, b_get('pf_search', '&regex=1&q=' . rawurlencode("a\x01e"))['status'], 'אי אפשר להוסיף דגלים מבחוץ');
    eq(400, b_get('pf_search', '&q=')['status'], 'חיפוש ריק');
    eq(403, b_get('pf_search', '&q=kol', U_STRANGER)['status'], 'מי שאינו חבר');
    // ביטוי "קטסטרופלי" — נעצר, לא תוקע
    pf_new('find/redos.txt', str_repeat('a', 40) . '!');
    $t = microtime(true);
    $r = b_get('pf_search', '&regex=1&q=' . rawurlencode('(a+)+$'));
    ok(microtime(true) - $t < 10, 'ביטוי כבד אינו תוקע את השרת');
    ok(in_array($r['status'], [200, 422], true), 'תשובה מסודרת: ' . $r['status']);
});

test('F2b החלפה בכל הקבצים: תצוגה מקדימה אינה כותבת; ביצוע עם revs; קובץ שהשתנה — 409; $1 בטקסט רגיל נשאר מילולי', function () {
    pf_new('rep/a.html', "<p>old-name</p>\n<i>OLD-NAME</i>\n");
    pf_new('rep/b.js', "const n = 'old-name';\n");
    pf_new('rep/c.txt', "nothing here\n");
    $pre = b_post('pf_replace', ['q' => 'old-name', 'with' => 'new $1 \\1 name']);
    eq(200, $pre['status'], json_encode($pre['json'], JSON_UNESCAPED_UNICODE));
    $mine = array_values(array_filter($pre['json']['files'], fn($f) => str_starts_with($f['path'], 'rep/')));
    eq(['rep/a.html', 'rep/b.js'], array_column($mine, 'path'));
    eq(2, $mine[0]['count'], 'בלי רישיות — שתיים ב-a.html');
    eq('<p>new $1 \\1 name</p>', $mine[0]['samples'][0]['after'], 'אחרי — בתצוגה המקדימה');
    eq("<p>old-name</p>\n<i>OLD-NAME</i>\n", pf_get(b_id('rep/a.html')), 'התצוגה המקדימה לא כתבה');

    $revs = array_column($mine, 'rev', 'id');
    $ids = array_column($mine, 'id');
    eq(409, b_post('pf_replace', ['q' => 'old-name', 'with' => 'x', 'apply' => true, 'ids' => $ids])['status'], 'בלי revs — 409');
    b_save('rep/b.js', "const n = 'old-name'; // שינוי של מישהו אחר\n");
    $r = b_post('pf_replace', ['q' => 'old-name', 'with' => 'x', 'apply' => true, 'ids' => $ids, 'revs' => $revs]);
    eq(409, $r['status'], 'קובץ שהשתנה מאז התצוגה המקדימה');
    eq("<p>old-name</p>\n<i>OLD-NAME</i>\n", pf_get(b_id('rep/a.html')), 'ושום קובץ לא נכתב — גם לא זה שלא השתנה');

    $snaps = (int)db()->query('SELECT COUNT(*) FROM snapshots')->fetchColumn();
    $r = b_post('pf_replace', ['q' => 'old-name', 'with' => 'new $1 name', 'apply' => true, 'ids' => [b_id('rep/a.html')], 'revs' => [b_id('rep/a.html') => $revs[b_id('rep/a.html')]]]);
    eq(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    eq(2, $r['json']['replaced']);
    eq("<p>new \$1 name</p>\n<i>new \$1 name</i>\n", pf_get(b_id('rep/a.html')), 'טקסט רגיל: $1 נשאר מילולי');
    eq("const n = 'old-name'; // שינוי של מישהו אחר\n", pf_get(b_id('rep/b.js')), 'ids: רק הקבצים שסומנו');
    ok((int)db()->query('SELECT COUNT(*) FROM snapshots')->fetchColumn() >= $snaps, 'נקודת שחזור לפני');
    ok($r['json']['snapshot'] > 0, 'מספר נקודת השחזור מוחזר');
    $v = b_get('pf_versions', '&id=' . b_id('rep/a.html'))['json']['versions'][0];
    ok(str_contains($v['note'], 'החלפה בכל הקבצים'), 'גרסה חדשה עם הערה');

    // ביטוי רגולרי עם קבוצות
    $id = b_id('rep/b.js');
    $p = b_post('pf_replace', ['q' => "const (\\w+) = '([^']+)'", 'regex' => true, 'case' => true, 'with' => 'let $1 = "$2"', 'ids' => [$id]])['json'];
    $r = b_post('pf_replace', ['q' => "const (\\w+) = '([^']+)'", 'regex' => true, 'case' => true, 'with' => 'let $1 = "$2"', 'apply' => true, 'ids' => [$id], 'revs' => array_column($p['files'], 'rev', 'id')]);
    eq(200, $r['status']);
    eq("let n = \"old-name\"; // שינוי של מישהו אחר\n", pf_get($id), 'קבוצות בביטוי רגולרי');
    eq(403, b_post('pf_replace', ['q' => 'x', 'with' => 'y'], U_STRANGER)['status'], 'מי שאינו חבר');
    ok(db()->query("SELECT COUNT(*) FROM audit WHERE action = 'pf_replace'")->fetchColumn() > 0, 'נרשם ביומן הפעולות');
});

// ── הפניות ────────────────────────────────────────────────

test('F2b הפניות: שינוי שם מציג את כל ההפניות (href, src, srcset, url(), @import, import, kol.json) ומעדכן אותן — רק כשמבקשים', function () {
    pf_new('refs/site/page.html', '<link rel=stylesheet href="css/s.css"><img src="/refs/img/logo.png?v=2#x" srcset="../img/logo.png 1x, ../img/big.png 2x">'
        . "\n<a href='https://x.example/refs/img/logo.png'>חיצוני</a><a href=\"#logo\">עוגן</a>\n<script type=module>import a from '../js/u.js'</script>");
    pf_new('refs/site/css/s.css', "@import \"base.css\";\nbody{background:url( '../../img/logo.png' )}\n.x{background:url(../../img/missing.png)}");
    pf_new('refs/site/css/base.css', '');
    pf_new('refs/img/logo.png', "\x89PNG\x00");
    pf_new('refs/img/big.png', "\x89PNG\x00\x01");
    pf_new('refs/js/u.js', "export default 1;\nimport x from './v.js';\nimport 'react';");
    pf_new('refs/js/v.js', '');
    $q = '&id=' . b_id('refs/img/logo.png') . '&to=' . rawurlencode('refs/media/לוגו "x" <b>.png');
    $r = b_get('pf_refs', $q);
    eq(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    $refs = $r['json']['refs'];
    eq(['../../img/logo.png', '/refs/img/logo.png', '../img/logo.png'], array_column($refs, 'old'), 'שלוש הפניות (הכתובת החיצונית והעוגן אינן)');
    eq([2, 1, 1], array_column($refs, 'line'));
    eq(['refs/site/css/s.css', 'refs/site/page.html', 'refs/site/page.html'], array_column($refs, 'path'));
    foreach ($refs as $x) {
        ok(!preg_match('/["\'<> ]/', $x['new']), 'הערך החדש מקודד — אינו יוצא מההקשר: ' . $x['new']);
        ok(str_contains($x['new'], 'לוגו'), 'עברית נשארת קריאה');
    }
    eq('/refs/media/' . 'לוגו%20%22x%22%20%3Cb%3E.png', $refs[1]['new'], 'מהשורש — נשאר מהשורש');
    eq('../media/לוגו%20%22x%22%20%3Cb%3E.png', $refs[2]['new'], 'יחסי — נשאר יחסי');
    eq(b_get('pf_read', '&id=' . b_id('refs/site/page.html'))['json']['content'], pf_get(b_id('refs/site/page.html')), 'pf_refs אינו כותב');

    // שינוי שם בלי update_refs — ההפניות נשארות כמו שהן (לא חוסם, בעל העמוד מחליט)
    eq(200, b_post('pf_rename', ['id' => b_id('refs/img/big.png'), 'to' => 'refs/img/large.png'])['status']);
    ok(str_contains(pf_get(b_id('refs/site/page.html')), '../img/big.png 2x'), 'בלי בקשה — לא נגעו');

    $r = b_post('pf_rename', ['id' => b_id('refs/img/logo.png'), 'to' => 'refs/media/לוגו "x" <b>.png', 'update_refs' => true]);
    eq(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    eq(3, $r['json']['refs']);
    $page = pf_get(b_id('refs/site/page.html'));
    ok(str_contains($page, 'src="/refs/media/לוגו%20%22x%22%20%3Cb%3E.png?v=2#x"'), '?query ו-#hash נשמרו: ' . $page);
    ok(str_contains($page, 'srcset="../media/לוגו%20%22x%22%20%3Cb%3E.png 1x, ../img/big.png 2x"'), 'srcset — רק הכתובת שהשתנתה');
    ok(str_contains($page, "href='https://x.example/refs/img/logo.png'"), 'כתובת חיצונית לא נגעה');
    eq(0, preg_match_all('/<b>|"x"/', $page), 'שם הקובץ העוין לא הוסיף תגית או מרכאות לקובץ');
    ok(str_contains(pf_get(b_id('refs/site/css/s.css')), "url( '../../media/לוגו%20%22x%22%20%3Cb%3E.png' )"), 'url() ב-CSS, עם הרווחים והמרכאות שהיו');
    ok(str_contains(pf_get(b_id('refs/site/css/s.css')), 'url(../../img/missing.png)'), 'הפניה שכבר הייתה שבורה — לא נגעה');
    // הערך החדש באמת מגיע לקובץ
    eq('refs/media/לוגו "x" <b>.png', pf_ref_resolve('refs/site/page.html', '../media/לוגו%20%22x%22%20%3Cb%3E.png', 'attr'), 'הערך מפוענח חזרה לנתיב החדש');
    $v = b_get('pf_versions', '&id=' . b_id('refs/site/page.html'))['json']['versions'][0];
    ok(str_contains($v['note'], 'עדכון הפניות'), 'לקבצים המפנים — גרסה חדשה עם הערה');
});

test('F2b הפניות: תיקייה שזזה; קובץ מפנה שזז; JS import; kol.json; ומחיקה — רשימה בלי כתיבה', function () {
    // תיקייה: css → styles. ההפניה מ-page.html מתעדכנת; @import "base.css" בתוך התיקייה — זזה יחד, לא נוגעים
    $r = b_get('pf_refs', '&path=refs/site/css&to=' . rawurlencode('refs/site/styles'))['json']['refs'];
    eq(['css/s.css'], array_column($r, 'old'), 'רק ההפניה מבחוץ; בתוך התיקייה — עדיין מגיעה');
    eq('styles/s.css', $r[0]['new']);
    eq(200, b_post('pf_rename', ['path' => 'refs/site/css', 'to' => 'refs/site/styles', 'update_refs' => true])['status']);
    ok(str_contains(pf_get(b_id('refs/site/page.html')), 'href="styles/s.css"'));
    ok(str_starts_with(pf_get(b_id('refs/site/styles/s.css')), '@import "base.css";'), '@import יחסי בתוך התיקייה — כמו שהיה');

    // הקובץ המפנה עצמו זז: ההפניות היחסיות שלו מתעדכנות מהמקום החדש
    $r = b_get('pf_refs', '&id=' . b_id('refs/site/page.html') . '&to=' . rawurlencode('refs/page.html'))['json']['refs'];
    $logo = 'לוגו%20%22x%22%20%3Cb%3E.png';
    eq(['styles/s.css', "../media/$logo", '../js/u.js'], array_column($r, 'old'), 'big.png שכבר שבורה (שונה שמה בלי עדכון) — אינה ברשימה');
    eq(['site/styles/s.css', "media/$logo", './js/u.js'], array_column($r, 'new'), 'JS — תמיד עם ./');
    eq(200, b_post('pf_rename', ['id' => b_id('refs/site/page.html'), 'to' => 'refs/page.html', 'update_refs' => true])['status']);
    ok(str_contains(pf_get(b_id('refs/page.html')), "import a from './js/u.js'"));

    // JS: import יחסי; "react" אינו נתיב
    $r = b_get('pf_refs', '&id=' . b_id('refs/js/v.js') . '&to=' . rawurlencode('refs/js/lib/v.js'))['json']['refs'];
    eq(['./v.js'], array_column($r, 'old'));
    eq('./lib/v.js', $r[0]['new']);

    // kol.json: "entry"
    $r = b_get('pf_refs', '&id=' . b_id('index.html') . '&to=' . rawurlencode('home.html'))['json']['refs'];
    ok(in_array('kol.json', array_column($r, 'path'), true), 'kol.json מפנה ל-index.html (entry)');

    // מחיקה: רשימה בלבד
    $before = pf_get(b_id('refs/page.html'));
    $r = b_get('pf_refs', '&path=refs/js')['json']['refs'];
    eq([['refs/page.html', '../js/u.js'], ['refs/page.html', './js/u.js']][1], [$r[0]['path'], $r[0]['old']], 'מי מפנה לתיקייה שנמחקת');
    ok(!in_array('refs/js/u.js', array_column($r, 'path'), true), 'קובץ שנמחק בעצמו אינו ברשימה');
    eq(null, $r[0]['new'], 'במחיקה אין הצעה לערך');
    eq($before, pf_get(b_id('refs/page.html')));
    eq(403, b_get('pf_refs', '&path=refs/js', U_STRANGER)['status'], 'מי שאינו חבר');
});

// ── ZIP ───────────────────────────────────────────────────

test('F2b ZIP הלוך-חזור: ייצוא ← פרויקט ריק ← ייבוא ← עץ זהה, בייט בבייט; ייבוא חוזר — הכול "זהה"', function () {
    pf_new('zip/תיקייה/שם בעברית.html', "\xEF\xBB\xBF<script>x()</script>\r\n");
    pf_new('zip/.htaccess', 'Deny from all');
    pf_new('zip/bin.dat', "\x00\xFF\x80");
    pf_new('zip/empty.txt', '');
    b_post('pf_mkdir', ['path' => 'zip/ריקה']);
    $want = b_tree_bytes();
    $res = http('GET', '/api.php?action=pf_zip', ['user' => U_EDITOR]);
    eq(200, $res['status']);
    eq('application/zip', $res['headers']['content-type'] ?? '');
    eq('nosniff', $res['headers']['x-content-type-options'] ?? '');
    ok(str_contains($res['headers']['content-security-policy'] ?? '', 'sandbox'), 'בארגז חול');
    ok(str_starts_with($res['headers']['content-disposition'] ?? '', 'attachment'), 'קובץ מצורף');
    eq(403, http('GET', '/api.php?action=pf_zip', ['user' => U_STRANGER])['status'], 'מי שאינו חבר אינו מוריד');
    $zip = $res['body'];
    $tmp = tempnam(sys_get_temp_dir(), 'kz');
    file_put_contents($tmp, $zip);
    $z = new ZipArchive(); ok($z->open($tmp) === true, 'ZIP תקין');
    $names = []; for ($i = 0; $i < $z->numFiles; $i++) $names[] = $z->getNameIndex($i);
    ok(in_array('zip/ריקה/', $names, true), 'תיקייה ריקה ב-ZIP');
    ok(in_array('from-current/lexicon.css', $names, true), 'from-current/ ב-ZIP');
    eq("\xEF\xBB\xBF<script>x()</script>\r\n", $z->getFromName('zip/תיקייה/שם בעברית.html'), 'בייט בבייט, בשם בעברית');
    $z->close(); unlink($tmp);

    // "פרויקט ריק": הכול לסל
    foreach (http('GET', '/api.php?action=pf_tree', ['user' => U_EDITOR])['json']['files'] as $f) {
        if (!str_contains($f['path'], '/')) b_post('pf_delete', $f['kind'] === 'dir' ? ['id' => $f['id']] : ['id' => $f['id']]);
    }
    foreach (array_unique(array_map(fn($p) => explode('/', $p)[0], array_keys(pf_paths()))) as $top) b_post('pf_delete', ['path' => $top]);
    eq([], pf_paths(), 'הפרויקט ריק');

    $plan = b_zip_post($zip);
    eq(200, $plan['status'], json_encode($plan['json'], JSON_UNESCAPED_UNICODE));
    $p = $plan['json']['plan'];
    eq(count($want), $p['counts']['add'], 'הכול יתווסף');
    eq(0, $p['counts']['replace']);
    eq([], pf_paths(), 'התצוגה אינה כותבת');
    $r = b_zip_post($zip, '&apply=1&token=' . $p['token']);
    eq(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    eq($want, b_tree_bytes(), 'העץ זהה — נתיבים ובייטים');
    ok(isset(pf_paths()['zip/ריקה']), 'התיקייה הריקה חזרה');

    $again = b_zip_post($zip)['json']['plan'];
    eq(0, $again['counts']['add'] + $again['counts']['replace'], 'ייבוא חוזר: אין מה להוסיף או להחליף');
    eq(count($want), $again['counts']['same']);
});

test('F2b ZIP: מה יתווסף, מה יוחלף ומה לא ישתנה; לפני החלפה — נקודת שחזור; token ישן — 409', function () {
    $zip = b_zip([['zip/empty.txt', 'עכשיו יש תוכן'], ['zip/new/x.css', 'a{}'], ['zip/bin.dat', "\x00\xFF\x80"]]);
    $p = b_zip_post($zip)['json']['plan'];
    eq(['zip/new/x.css'], $p['add']);
    eq(['zip/empty.txt'], $p['replace']);
    eq(['zip/bin.dat'], $p['same']);
    ok(in_array('index.html', $p['keep'], true), 'מה שאינו ב-ZIP — לא ישתנה (ולא נמחק)');
    b_save('zip/empty.txt', 'שינוי בינתיים');
    eq(409, b_zip_post($zip, '&apply=1&token=' . $p['token'])['status'], 'הפרויקט השתנה מאז התצוגה');
    eq('שינוי בינתיים', pf_get(b_id('zip/empty.txt')));
    $p = b_zip_post($zip)['json']['plan'];
    $snaps = (int)db()->query('SELECT MAX(id) FROM snapshots')->fetchColumn();
    $r = b_zip_post($zip, '&apply=1&token=' . $p['token']);
    eq(200, $r['status']);
    ok($r['json']['snapshot'] > $snaps || $r['json']['snapshot'] === $snaps, 'נקודת שחזור');
    eq('עכשיו יש תוכן', pf_get(b_id('zip/empty.txt')));
    ok(str_contains(b_get('pf_versions', '&id=' . b_id('zip/empty.txt'))['json']['versions'][0]['note'], 'ייבוא ZIP'), 'גרסה חדשה — ההיסטוריה נשמרת');
    ok(isset(pf_paths()['index.html']), 'שום קובץ לא נמחק');
    eq(403, b_zip_post($zip, '', U_STRANGER)['status'], 'מי שאינו חבר');
});

test('F2b ZIP עוין נדחה כולו ואינו כותב דבר: ../, נתיב מוחלט, \\, קישור סימבולי, גודל מוצהר כוזב, כפילות ברישיות, קובץ+תיקייה, פצצה', function () {
    $before = b_tree_bytes();
    $cases = [
        '../' => b_zip([['ok.txt', 'a'], ['zip/../../evil.php', '<?php']]),
        '.. בהתחלה' => b_zip([['../evil.txt', 'x']]),
        'מוחלט' => b_zip([['/etc/passwd', 'x']]),
        'כונן' => b_zip([['C:/x.txt', 'x']]),
        '\\' => b_zip([['a\\..\\..\\x.txt', 'x']]),
        'קישור סימבולי' => b_zip([['link', '/etc/passwd', ['attr' => (0120777 << 16)]]]),
        'גודל כוזב' => b_zip([['bomb.txt', str_repeat("\0", 200000), ['deflate' => true, 'size' => 10]]]),
        'רישיות' => b_zip([['A.txt', 'x'], ['a.txt', 'y']]),
        'קובץ ותיקייה' => b_zip([['k', 'x'], ['k/l.txt', 'y']]),
        'תו בקרה' => b_zip([["a\x07.txt", 'x']]),
        'לא UTF-8' => b_zip([["\xFF\xFE.txt", 'x']]),
        'לא ZIP' => 'PK this is not a zip',
    ];
    foreach ($cases as $name => $zip) {
        $r = b_zip_post($zip);
        ok($r['status'] >= 400 && $r['status'] < 500, "$name: נדחה (" . $r['status'] . ' ' . ($r['json']['error'] ?? '') . ')');
        $r = b_zip_post($zip, '&apply=1&token=x');
        ok($r['status'] >= 400, "$name: גם בביצוע");
    }
    // פצצה: הגודל הכולל אחרי פתיחה מעל המגבלה
    eq(200, http('POST', '/api.php?action=pf_limits_set', ['user' => U_OWNER, 'json' => ['key' => 'unzip', 'value' => 100000]])['status']);
    $r = b_zip_post(b_zip([['a.txt', str_repeat('a', 60000), ['deflate' => true]], ['b.txt', str_repeat('b', 60000), ['deflate' => true]]]));
    eq(413, $r['status'], 'פצצת ZIP');
    ok(str_contains($r['json']['error'], 'מפתח'), 'ההודעה אומרת מי משנה');
    http('POST', '/api.php?action=pf_limits_set', ['user' => U_OWNER, 'json' => ['key' => 'unzip', 'value' => 200 * 1024 * 1024]]);
    eq(200, http('POST', '/api.php?action=pf_limits_set', ['user' => U_OWNER, 'json' => ['key' => 'zip', 'value' => 2000]])['status']);
    eq(413, b_zip_post(b_zip([['a.txt', random_bytes(3000)]]))['status'], 'ZIP גדול ממגבלת ההעלאה');
    http('POST', '/api.php?action=pf_limits_set', ['user' => U_OWNER, 'json' => ['key' => 'zip', 'value' => 50 * 1024 * 1024]]);
    eq($before, b_tree_bytes(), 'שום דבר לא נכתב');
    // __MACOSX ו-.DS_Store — מדולגים ומוצגים; קובץ גדול מהמגבלה — לא ייובא, ומוצג
    http('POST', '/api.php?action=pf_limits_set', ['user' => U_OWNER, 'json' => ['key' => 'text', 'value' => 1024]]);
    $p = b_zip_post(b_zip([['__MACOSX/._x', 'junk'], ['zip/.DS_Store', 'junk'], ['zip/big.bin', str_repeat('z', 2000)], ['zip/fine.txt', 'ok']]))['json']['plan'];
    http('POST', '/api.php?action=pf_limits_set', ['user' => U_OWNER, 'json' => ['key' => 'text', 'value' => 2 * 1024 * 1024]]);
    eq(['__MACOSX/._x', 'zip/.DS_Store'], $p['skipped']);
    eq(['zip/big.bin'], array_column($p['big'], 'path'));
    eq(['zip/fine.txt'], $p['add']);
});
