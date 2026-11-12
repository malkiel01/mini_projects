<?php
/**
 * פרוסה F2a — מאגר הפרויקט (lib/project.php). כל בדיקה כאן עומדת מול קריטריון [a] ב-docs/spec-v2/slices/F2-…md.
 */

declare(strict_types=1);

function pf_new(string $path, string $bytes, array $user = U_EDITOR): array
{
    $json = mb_check_encoding($bytes, 'UTF-8') ? ['path' => $path, 'content' => $bytes] : ['path' => $path, 'content_b64' => base64_encode($bytes)];
    return http('POST', '/api.php?action=pf_create', ['user' => $user, 'json' => $json]);
}

function pf_get(int $id, array $user = U_EDITOR): string
{
    $j = http('GET', "/api.php?action=pf_read&id=$id", ['user' => $user])['json'];
    return isset($j['b64']) ? (string)base64_decode($j['b64']) : (string)$j['content'];
}

function pf_paths(): array
{
    return array_column(http('GET', '/api.php?action=pf_tree', ['user' => U_EDITOR])['json']['files'], 'id', 'path');
}

const PF_ME = ['sub' => 'u-editor', 'name' => 'עורך בדיקה', 'role' => 'editor'];

test('פרויקט: נוצר עם שלד ברירת המחדל ועם from-current/, לכל קובץ שם שורת מקור', function () {
    $t = http('GET', '/api.php?action=pf_tree', ['user' => U_EDITOR]);
    eq(200, $t['status']);
    $paths = array_column($t['json']['files'], 'id', 'path');
    foreach (['index.html', 'css/tokens.css', 'css/base.css', 'css/page.css', 'js/page.js', 'kol.json'] as $p) ok(isset($paths[$p]), "חסר $p");
    foreach (['lexicon.html', 'lexicon.css', 'lexicon.js', 'lexicon-template.html', 'theme-style.css', 'imported.css', 'preview.css', 'page-base.css'] as $p) {
        ok(isset($paths["from-current/$p"]), "חסר from-current/$p");
        ok(str_contains(strtok(pf_get($paths["from-current/$p"]), "\n"), 'from-current · '), "שורת המקור של $p");
    }
    ok(str_contains(pf_get($paths['from-current/lexicon-template.html']), LEX_TPL), 'התבנית המובנית הועתקה כמו שהיא');
    ok(str_contains(pf_get($paths['from-current/page-base.css']), '.kol-page'), 'ה-CSS הבסיסי של העמוד');
    eq(true, $t['json']['kol']['valid'], 'kol.json תקין');
    eq('index.html', $t['json']['kol']['config']['entry']);
    eq(count($paths), count(pf_paths()), 'קריאה שנייה אינה זורעת שוב');
});

test('פרויקט: מה שנשמר הוא מה שנקרא, בייט בבייט — ביצירה ובשמירה, ובלי שום סינון', function () {
    $cases = [
        'script' => "<script>fetch('api.php?action=me')</script>",
        'onclick' => '<a href="javascript:alert(1)" onclick="x()" onerror=y>k</a>',
        'iframe' => '<iframe src="https://example.com" srcdoc="<b>x</b>"></iframe>',
        'form' => '<form action="https://evil.example/post" method="post"><input name=a></form>',
        'style' => "<style>@import url(http://x.example/a.css); body{position:fixed;background:url(http://x.example/i.png)}</style>",
        'php' => "<?php system(\$_GET['c']); ?>\n<?= 1 ?>",
        'bom' => "\xEF\xBB\xBF<!doctype html>\r\n<p>שורות CRLF</p>\r\n",
        'hebrew' => "שלום עולם · קול מרוקו · \u{05E9}\u{05B8}\u{05C1}",
        'emoji' => "🎙️👨‍👩‍👧‍👦🇲🇦 \u{200F}\u{202E}",
        'empty' => '',
        'mustache' => '{{#words}}{{{raw}}}{{/words}} {{ לא סגור',
        'binary' => "\x00\x01\xFF\xFE\x80 not utf8 \xC3\x28",
        'trailing' => "  רווחים בסוף   \n\n\n\t",
    ];
    foreach ($cases as $name => $bytes) {
        $r = pf_new("bytes/$name.html", $bytes);
        eq(200, $r['status'], "יצירה · $name");
        $id = $r['json']['file']['id'];
        eq(bin2hex($bytes), bin2hex(pf_get($id)), "קריאה אחרי יצירה · $name");
        eq(strlen($bytes), $r['json']['file']['size'], "גודל · $name");
        eq(hash('sha256', $bytes), hash_file('sha256', pf_blob_path(hash('sha256', $bytes))), "הבייטים בדיסק · $name");
        // ועכשיו שמירה של כל שאר המקרים לתוך אותו קובץ
        $all = implode("\n", $cases) . $bytes;
        $w = http('POST', '/api.php?action=pf_write', ['user' => U_EDITOR, 'json' => ['id' => $id, 'rev' => 1, 'content_b64' => base64_encode($all)]]);
        eq(200, $w['status'], "שמירה · $name");
        eq(bin2hex($all), bin2hex(pf_get($id)), "קריאה אחרי שמירה · $name");
    }
    $src = (string)file_get_contents(KOL_ROOT . '/lib/project.php');
    foreach (['tpl_clean_html(', 'css_scope(', 'sanitize_', 'strip_tags(', 'htmlspecialchars('] as $fn) ok(!str_contains($src, $fn), "$fn במסלול המאגר");
});

test('פרויקט: שמות חופשיים — x.php, .htaccess, a.b.c, עברית, ושם שהוא HTML — נשמרים כטקסט דומם', function () {
    foreach (['x.php', '.htaccess', 'a.b.c', 'בלי-סיומת', 'תיקייה בעברית/קובץ עם רווח.html', 'names/<img src=x onerror=alert(1)>.html', 'names/a&b"c\'.css', 'web.config', 'index.PHP5'] as $p) {
        $r = pf_new($p, "<?php echo 'דומם'; ?>");
        eq(200, $r['status'], $p);
        eq($p, $r['json']['file']['path'], 'הנתיב נשמר בדיוק');
    }
    ok(isset(pf_paths()['names/<img src=x onerror=alert(1)>.html']), 'מופיע בעץ בשמו');
});

test('פרויקט: נתיב עם .., מוחלט, \\, תו בקרה או רכיב ריק — נדחה ואינו כותב דבר', function () {
    $rows = (int)db()->query('SELECT COUNT(*) FROM pf_files')->fetchColumn();
    $blobs = iterator_count(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(pf_dir(), FilesystemIterator::SKIP_DOTS)));
    $bad = ['../x.html', 'a/../../x', '..', './x', 'a/./b', '/etc/passwd', '/x.html', 'a\\b.html', '..\\x', "a\x01b", "a\nb", "a\x7Fb", "a\0b", 'a//b', 'a/', '', "\xFF\xFE"];
    foreach ($bad as $p) {
        $body = ['path' => $p, 'content' => 'תוכן-שלא-אמור-להיכתב-' . bin2hex(random_bytes(4))];
        if (!mb_check_encoding($p, 'UTF-8')) { throws(fn() => pf_create(PF_ME, $body), 'נתיב לא UTF-8'); continue; }
        eq(400, http('POST', '/api.php?action=pf_create', ['user' => U_EDITOR, 'json' => $body])['status'], 'יצירה · ' . json_encode($p));
        eq(400, http('POST', '/api.php?action=pf_mkdir', ['user' => U_EDITOR, 'json' => ['path' => $p]])['status'], 'תיקייה · ' . json_encode($p));
        eq(400, http('POST', '/api.php?action=pf_rename', ['user' => U_EDITOR, 'json' => ['id' => pf_paths()['index.html'], 'to' => $p]])['status'], 'שינוי שם · ' . json_encode($p));
    }
    eq($rows, (int)db()->query('SELECT COUNT(*) FROM pf_files')->fetchColumn(), 'לא נוספה שורה');
    eq($blobs, iterator_count(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(pf_dir(), FilesystemIterator::SKIP_DOTS))), 'לא נכתב תוכן');
    ok(isset(pf_paths()['index.html']), 'index.html נשאר במקומו');
});

test('פרויקט: רישיות, קובץ במקום תיקייה ותיקייה במקום קובץ — נדחים', function () {
    eq(200, pf_new('case/Page.html', 'a')['status']);
    eq(409, pf_new('case/page.html', 'b')['status'], 'נבדל רק ברישיות');
    eq(409, pf_new('CASE/other.html', 'b')['status'], 'תיקייה שנבדלת רק ברישיות');
    eq(409, http('POST', '/api.php?action=pf_mkdir', ['user' => U_EDITOR, 'json' => ['path' => 'Case']])['status'], 'גם ביצירת תיקייה');
    eq(200, pf_new('case/other.html', 'b')['status'], 'באותה תיקייה, באותו איות — מותר');
    eq(409, pf_new('case/Page.html', 'b')['status'], 'אותו נתיב');
    eq(409, pf_new('case/Page.html/inner.css', 'b')['status'], 'קובץ בתוך קובץ');
    eq(409, pf_new('case', 'b')['status'], 'קובץ בשם של תיקייה');
});

test('פרויקט: המגבלות הן הגדרות של המפתח; חריגה נדחית בהודעה שאומרת מה המגבלה, מה הערך ומי משנה', function () {
    eq(403, http('POST', '/api.php?action=pf_limits_set', ['user' => U_EDITOR, 'json' => ['key' => 'text', 'value' => 2000]])['status'], 'עורך אינו משנה מגבלה');
    $l = http('GET', '/api.php?action=pf_limits', ['user' => U_OWNER])['json']['limits'];
    eq([200, 2097152, 2000, 200], array_slice(array_column($l, 'value'), 0, 4), 'ברירות המחדל של 02 §3');
    $src = (string)file_get_contents(KOL_ROOT . '/lib/project.php');
    eq(0, preg_match('/(2097152|2 \* 1024 \* 1024|2000|200)\b/', preg_replace('/const PF_LIMITS = \[.*?\];/s', '', $src)), 'אין מספר מגבלה בקוד מחוץ לטבלת ברירות המחדל');

    eq(200, http('POST', '/api.php?action=pf_limits_set', ['user' => U_OWNER, 'json' => ['key' => 'text', 'value' => 2000]])['status']);
    $r = pf_new('limits/big.txt', str_repeat('x', 2001));
    eq(413, $r['status'], 'קובץ גדול מהמגבלה');
    foreach (['2000', '2001', 'המפתח'] as $s) ok(str_contains($r['json']['error'], $s), "בהודעה: $s");
    eq(2000, $r['json']['limit']);
    $ok = pf_new('limits/fits.txt', str_repeat('x', 2000));
    eq(200, $ok['status'], 'בדיוק במגבלה');
    eq(413, http('POST', '/api.php?action=pf_write', ['user' => U_EDITOR, 'json' => ['id' => $ok['json']['file']['id'], 'rev' => 1, 'content' => str_repeat('y', 2001)]])['status'], 'גם בשמירה');
    http('POST', '/api.php?action=pf_limits_set', ['user' => U_OWNER, 'json' => ['key' => 'text', 'value' => 2097152]]);
    eq(200, pf_new('limits/big.txt', str_repeat('x', 2001))['status'], 'אחרי שהמפתח שינה — עובר');
    eq(200, pf_new('limits/2mb.txt', str_repeat("שורה ארוכה\n", 95000))['status'], 'קובץ של ~2MB נשמר');

    http('POST', '/api.php?action=pf_limits_set', ['user' => U_OWNER, 'json' => ['key' => 'path', 'value' => 30]]);
    $r = pf_new('limits/' . str_repeat('א', 30), 'x');
    eq(413, $r['status'], 'נתיב ארוך מהמגבלה');
    ok(str_contains($r['json']['error'], '30') && str_contains($r['json']['error'], '37'), 'המגבלה והערך בהודעה');
    http('POST', '/api.php?action=pf_limits_set', ['user' => U_OWNER, 'json' => ['key' => 'path', 'value' => 200]]);

    $n = pf_count();
    http('POST', '/api.php?action=pf_limits_set', ['user' => U_OWNER, 'json' => ['key' => 'files', 'value' => $n]]);
    $r = pf_new('limits/one-too-many.txt', 'x');
    eq(413, $r['status'], 'יותר מדי קבצים');
    ok(str_contains($r['json']['error'], (string)$n), 'המגבלה בהודעה');
    http('POST', '/api.php?action=pf_limits_set', ['user' => U_OWNER, 'json' => ['key' => 'files', 'value' => 2000]]);
    eq(400, http('POST', '/api.php?action=pf_limits_set', ['user' => U_OWNER, 'json' => ['key' => 'files', 'value' => 1]])['status'], 'ערך מחוץ לטווח');
});

test('פרויקט: rev עולה בכל שמירה; שמירה עם rev ישן נדחית עם הגרסה שבשרת — לא דריסה שקטה', function () {
    $id = pf_new('rev/a.css', 'v1')['json']['file']['id'];
    $w = http('POST', '/api.php?action=pf_write', ['user' => U_EDITOR, 'json' => ['id' => $id, 'rev' => 1, 'content' => 'v2 של העורך']]);
    eq(2, $w['json']['file']['rev']);
    $stale = http('POST', '/api.php?action=pf_write', ['user' => U_PUBLISHER, 'json' => ['id' => $id, 'rev' => 1, 'content' => 'v2 של המפרסם']]);
    eq(409, $stale['status']);
    eq('הקובץ השתנה בינתיים', $stale['json']['error']);
    eq('v2 של העורך', $stale['json']['conflict']['content'], 'הגרסה שבשרת חוזרת להשוואה');
    eq(2, $stale['json']['conflict']['file']['rev']);
    eq('עורך בדיקה', $stale['json']['conflict']['file']['by']);
    eq('v2 של העורך', pf_get($id), 'לא נדרס');
    eq(409, http('POST', '/api.php?action=pf_write', ['user' => U_EDITOR, 'json' => ['id' => $id, 'content' => 'בלי rev']])['status'], 'בלי rev — נדחה');
    $same = http('POST', '/api.php?action=pf_write', ['user' => U_EDITOR, 'json' => ['id' => $id, 'rev' => 2, 'content' => 'v2 של העורך']]);
    eq(2, $same['json']['file']['rev'], 'אותו תוכן — אין גרסה חדשה');
    eq(3, http('POST', '/api.php?action=pf_write', ['user' => U_PUBLISHER, 'json' => ['id' => $id, 'rev' => 2, 'content' => 'v3']])['json']['file']['rev']);
    eq([1, 2, 3], array_map('intval', db()->query("SELECT rev FROM pf_history WHERE file_id = $id ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)), 'שורת היסטוריה לכל גרסה');
    // שמירות אוטומטיות רצופות של אותו עורך — שורה אחת
    foreach ([3, 4, 5] as $rev) http('POST', '/api.php?action=pf_write', ['user' => U_EDITOR, 'json' => ['id' => $id, 'rev' => $rev, 'content' => "auto $rev", 'auto' => true]]);
    eq([1, 2, 3, 6], array_map('intval', db()->query("SELECT rev FROM pf_history WHERE file_id = $id ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)), 'שמירה אוטומטית מתמזגת');
});

test('פרויקט: מחיקה = סל; שחזור מחזיר את הקובץ, הנתיב וההיסטוריה; מחיקה סופית רק מהסל, לבעלים ובאישור', function () {
    $id = pf_new('bin/keep.js', 'גרסה 1')['json']['file']['id'];
    http('POST', '/api.php?action=pf_write', ['user' => U_EDITOR, 'json' => ['id' => $id, 'rev' => 1, 'content' => 'גרסה 2']]);
    eq(400, http('POST', '/api.php?action=pf_purge', ['user' => U_OWNER, 'json' => ['ids' => [$id], 'confirm' => true]])['status'], 'קובץ חי אינו נמחק סופית');
    eq(200, http('POST', '/api.php?action=pf_delete', ['user' => U_EDITOR, 'json' => ['id' => $id]])['status']);
    ok(!isset(pf_paths()['bin/keep.js']), 'ירד מהעץ');
    eq(404, http('GET', "/api.php?action=pf_read&id=$id", ['user' => U_EDITOR])['status'], 'קובץ בסל אינו נקרא כקובץ חי');
    $trash = http('GET', '/api.php?action=pf_trash', ['user' => U_EDITOR])['json']['items'];
    ok(in_array($id, array_column($trash, 'id'), true), 'מופיע בסל');
    eq('גרסה 2', http('GET', "/api.php?action=pf_read&id=$id&trash=1", ['user' => U_EDITOR])['json']['content'], 'אפשר להציץ בתוכן מהסל');
    eq(200, http('POST', '/api.php?action=pf_restore', ['user' => U_EDITOR, 'json' => ['ids' => [$id]]])['status']);
    eq($id, pf_paths()['bin/keep.js'], 'אותו קובץ, באותו נתיב');
    eq('גרסה 2', pf_get($id));
    eq(2, (int)db()->query("SELECT COUNT(*) FROM pf_history WHERE file_id = $id")->fetchColumn(), 'ההיסטוריה חזרה איתו');

    // נתיב שנתפס בינתיים — השחזור נדחה ואינו דורס
    http('POST', '/api.php?action=pf_delete', ['user' => U_EDITOR, 'json' => ['id' => $id]]);
    $new = pf_new('bin/keep.js', 'קובץ אחר')['json']['file']['id'];
    eq(409, http('POST', '/api.php?action=pf_restore', ['user' => U_EDITOR, 'json' => ['ids' => [$id]]])['status']);
    eq('קובץ אחר', pf_get($new));

    eq(403, http('POST', '/api.php?action=pf_purge', ['user' => U_EDITOR, 'json' => ['ids' => [$id], 'confirm' => true]])['status'], 'עורך אינו מוחק סופית');
    eq(400, http('POST', '/api.php?action=pf_purge', ['user' => U_OWNER, 'json' => ['ids' => [$id]]])['status'], 'בלי אישור מפורש');
    eq(400, http('POST', '/api.php?action=pf_purge', ['user' => U_OWNER, 'json' => ['ids' => [$id], 'confirm' => 'yes']])['status'], 'אישור שאינו true');
    $sha = hash('sha256', 'גרסה 2');
    ok(is_file(pf_blob_path($sha)), 'התוכן בדיסק לפני המחיקה הסופית');
    eq(200, http('POST', '/api.php?action=pf_purge', ['user' => U_OWNER, 'json' => ['ids' => [$id], 'confirm' => true]])['status']);
    clearstatcache();
    ok(!is_file(pf_blob_path($sha)), 'התוכן נמחק מהדיסק');
    eq(0, (int)db()->query("SELECT COUNT(*) FROM pf_files WHERE id = $id")->fetchColumn() + (int)db()->query("SELECT COUNT(*) FROM pf_history WHERE file_id = $id")->fetchColumn());
    eq('קובץ אחר', pf_get($new), 'הקובץ שבאותו נתיב לא נפגע');
});

test('פרויקט: תיקייה — יצירה, שינוי שם שמזיז את כל מה שבתוכה, מחיקה לסל ושחזור יחד', function () {
    eq(200, http('POST', '/api.php?action=pf_mkdir', ['user' => U_EDITOR, 'json' => ['path' => 'dir/ריקה']])['status']);
    eq(409, http('POST', '/api.php?action=pf_mkdir', ['user' => U_EDITOR, 'json' => ['path' => 'dir/ריקה']])['status'], 'פעמיים');
    $a = pf_new('dir/src/a.html', 'A')['json']['file']['id'];
    $b = pf_new('dir/src/deep/b.css', 'B')['json']['file']['id'];
    eq(409, http('POST', '/api.php?action=pf_mkdir', ['user' => U_EDITOR, 'json' => ['path' => 'dir/src']])['status'], 'תיקייה שכבר קיימת מכוח הקבצים שבה');
    eq(400, http('POST', '/api.php?action=pf_rename', ['user' => U_EDITOR, 'json' => ['path' => 'dir/src', 'to' => 'dir/src/inner']])['status'], 'לתוך עצמה');
    eq(200, http('POST', '/api.php?action=pf_rename', ['user' => U_EDITOR, 'json' => ['path' => 'dir/src', 'to' => 'dir/מקור']])['status']);
    $p = pf_paths();
    eq([$a, $b], [$p['dir/מקור/a.html'] ?? null, $p['dir/מקור/deep/b.css'] ?? null], 'הקבצים זזו עם התיקייה');
    eq(200, http('POST', '/api.php?action=pf_rename', ['user' => U_EDITOR, 'json' => ['id' => $a, 'to' => 'dir/מקור/A2.html']])['status'], 'שינוי שם של קובץ');
    eq(409, http('POST', '/api.php?action=pf_rename', ['user' => U_EDITOR, 'json' => ['id' => $a, 'to' => 'dir/מקור/deep/b.css']])['status'], 'על קובץ קיים');
    eq('A', pf_get($a), 'התוכן לא השתנה בשינוי השם');
    eq(1, http('GET', "/api.php?action=pf_read&id=$a", ['user' => U_EDITOR])['json']['file']['rev'], 'rev לא השתנה');
    eq(2, http('POST', '/api.php?action=pf_delete', ['user' => U_EDITOR, 'json' => ['path' => 'dir/מקור']])['json']['deleted']);
    $p = pf_paths();
    ok(!isset($p['dir/מקור/A2.html']) && !isset($p['dir/מקור/deep/b.css']), 'התיקייה ירדה מהעץ');
    $batch = array_unique(array_column(array_filter(http('GET', '/api.php?action=pf_trash', ['user' => U_EDITOR])['json']['items'], fn($i) => in_array($i['id'], [$a, $b], true)), 'batch'));
    eq(1, count($batch), 'אותה קבוצה בסל');
    eq(200, http('POST', '/api.php?action=pf_restore', ['user' => U_EDITOR, 'json' => ['ids' => [$a, $b]]])['status']);
    eq($b, pf_paths()['dir/מקור/deep/b.css']);
});

test('פרויקט: הרשאות, נעילת העריכה ויומן הפעולות', function () {
    $id = pf_paths()['index.html'];
    foreach (['pf_tree', "pf_read&id=$id", "pf_raw&id=$id", 'pf_trash'] as $a) {
        eq(403, http('GET', "/api.php?action=$a", ['user' => U_STRANGER])['status'], "לא-חבר · $a");
        eq(401, http('GET', "/api.php?action=$a")['status'], "בלי זהות · $a");
        eq(200, http('GET', "/api.php?action=$a", ['user' => U_PUBLISHER])['status'], "מפרסם · $a");
    }
    eq(403, pf_new('perm/x.html', 'x', U_STRANGER)['status']);
    eq(403, http('POST', '/api.php?action=pf_create', ['user' => U_EDITOR, 'csrf' => false, 'json' => ['path' => 'perm/x.html']])['status'], 'בלי CSRF');
    foreach ([U_EDITOR, U_PUBLISHER, U_OWNER] as $i => $u) eq(200, pf_new("perm/$i.html", 'x', $u)['status'], 'כל תפקיד כותב');

    // נעילה אצל המפרסם — העורך אינו כותב; אחרי שחרור — כותב
    http('POST', '/api.php?action=lock', ['user' => U_PUBLISHER, 'json' => ['op' => 'take']]);
    $f = pf_paths()['perm/0.html'];
    foreach ([['pf_create', ['path' => 'perm/locked.html']], ['pf_write', ['id' => $f, 'rev' => 1, 'content' => 'y']], ['pf_mkdir', ['path' => 'perm/d']],
              ['pf_rename', ['id' => $f, 'to' => 'perm/z.html']], ['pf_delete', ['id' => $f]], ['pf_restore', ['ids' => [$f]]]] as [$a, $body]) {
        eq(423, http('POST', "/api.php?action=$a", ['user' => U_EDITOR, 'json' => $body])['status'], "נעול · $a");
    }
    eq(200, http('GET', "/api.php?action=pf_read&id=$f", ['user' => U_EDITOR])['status'], 'קריאה מותרת גם כשנעול');
    eq(200, http('POST', '/api.php?action=pf_write', ['user' => U_PUBLISHER, 'json' => ['id' => $f, 'rev' => 1, 'content' => 'של המחזיק בנעילה']])['status']);
    http('POST', '/api.php?action=lock', ['user' => U_PUBLISHER, 'json' => ['op' => 'release']]);

    $before = (int)db()->query('SELECT MAX(id) FROM audit')->fetchColumn();
    $id = pf_new('audit/a.html', 'x')['json']['file']['id'];
    http('POST', '/api.php?action=pf_mkdir', ['user' => U_EDITOR, 'json' => ['path' => 'audit/d']]);
    http('POST', '/api.php?action=pf_rename', ['user' => U_EDITOR, 'json' => ['id' => $id, 'to' => 'audit/b.html']]);
    http('POST', '/api.php?action=pf_delete', ['user' => U_EDITOR, 'json' => ['id' => $id]]);
    http('POST', '/api.php?action=pf_restore', ['user' => U_EDITOR, 'json' => ['ids' => [$id]]]);
    $log = db()->query("SELECT action, detail, sub FROM audit WHERE id > $before ORDER BY id")->fetchAll();
    eq(['pf_create', 'pf_mkdir', 'pf_rename', 'pf_delete', 'pf_restore'], array_column($log, 'action'));
    eq('audit/a.html ← audit/b.html', $log[2]['detail']);
    eq('u-editor', $log[0]['sub']);
});

test('פרויקט: נקודת שחזור כוללת את קבצי הפרויקט, ושחזור מחזיר אותם — וגם ביטול השחזור', function () {
    $keep = pf_new('snap/keep.html', 'לפני הנקודה')['json']['file']['id'];
    $gone = pf_new('snap/deleted-later.css', 'יימחק אחרי הנקודה')['json']['file']['id'];
    $moved = pf_new('snap/old-name.js', 'ישנה שם')['json']['file']['id'];
    $snap = snapshot_create(PF_ME, 'בדיקת פרויקט', 'manual');
    $fp = snap_fingerprint();

    http('POST', '/api.php?action=pf_write', ['user' => U_EDITOR, 'json' => ['id' => $keep, 'rev' => 1, 'content' => 'אחרי הנקודה']]);
    http('POST', '/api.php?action=pf_delete', ['user' => U_EDITOR, 'json' => ['id' => $gone]]);
    http('POST', '/api.php?action=pf_rename', ['user' => U_EDITOR, 'json' => ['id' => $moved, 'to' => 'snap/new-name.js']]);
    $added = pf_new('snap/added-later.html', 'נוסף אחרי הנקודה')['json']['file']['id'];
    ok($fp !== ($fp2 = snap_fingerprint()), 'המצב השתנה');

    $r = snapshot_restore(PF_ME, ['id' => $snap]);
    eq([], $r['missing']);
    $p = pf_paths();
    eq('לפני הנקודה', pf_get($keep), 'התוכן חזר');
    eq($gone, $p['snap/deleted-later.css'] ?? null, 'הקובץ שנמחק חזר');
    eq($moved, $p['snap/old-name.js'] ?? null, 'השם חזר');
    ok(!isset($p['snap/new-name.js']) && !isset($p['snap/added-later.html']), 'מה שנוסף אחרי הנקודה אינו בעץ');
    ok(in_array($added, array_column(http('GET', '/api.php?action=pf_trash', ['user' => U_EDITOR])['json']['items'], 'id'), true), '…הוא בסל, לא נמחק');
    $live = fn() => db()->query('SELECT id, path, sha FROM pf_files WHERE deleted_at IS NULL ORDER BY id')->fetchAll();
    eq(array_map(fn($x) => [$x['id'], $x['path'], $x['sha']], array_values(array_filter(json_decode(blob_get(json_decode(snapshot_row($snap)['parts'], true)['project']), true), fn($x) => $x['deleted_at'] === null))),
       array_map(fn($x) => [$x['id'], $x['path'], $x['sha']], $live()), 'העץ החי זהה בדיוק לעץ שבנקודה');
    eq(3, http('GET', "/api.php?action=pf_read&id=$keep", ['user' => U_EDITOR])['json']['file']['rev'], 'rev רק עולה — עורך עם גרסה ישנה יקבל 409');

    snapshot_restore(PF_ME, ['id' => $r['pre']]);
    eq($fp2, snap_fingerprint(), 'ביטול השחזור מחזיר בדיוק את המצב שלפניו (כולל הסל)');
    eq('אחרי הנקודה', pf_get($keep), 'ביטול השחזור');
    eq($added, pf_paths()['snap/added-later.html'] ?? null);
    eq($moved, pf_paths()['snap/new-name.js'] ?? null);

    // קובץ שנמחק סופית אחרי הנקודה, ותוכנו כבר אינו בדיסק — מדווח כחסר, והשאר משוחזר
    $snap2 = snapshot_create(PF_ME, 'לפני מחיקה סופית', 'manual');
    http('POST', '/api.php?action=pf_delete', ['user' => U_EDITOR, 'json' => ['id' => $added]]);
    http('POST', '/api.php?action=pf_purge', ['user' => U_OWNER, 'json' => ['ids' => [$added], 'confirm' => true]]);
    eq(['snap/added-later.html'], snapshot_restore(PF_ME, ['id' => $snap2])['missing']);
});

test('פרויקט: גיבוי מלא כולל את קבצי הפרויקט — הרשומות במסד והתוכן תחת files/_project/', function () {
    $bytes = "<script>/* בגיבוי */</script>\r\n" . bin2hex(random_bytes(8));
    pf_new('backup/in-zip.html', $bytes);
    $r = http('GET', '/api.php?action=backup', ['user' => U_OWNER]);
    eq(200, $r['status']);
    $zipPath = KOL_DATA_DIR . '/backup-test.zip';
    file_put_contents($zipPath, $r['body']);
    $zip = new ZipArchive();
    ok($zip->open($zipPath) === true, 'ZIP תקין');
    $sha = hash('sha256', $bytes);
    eq($bytes, $zip->getFromName('files/_project/' . substr($sha, 0, 2) . '/' . $sha), 'התוכן בגיבוי, בייט בבייט');
    file_put_contents(KOL_DATA_DIR . '/backup-test.sqlite', $zip->getFromName('kol.sqlite'));
    $zip->close();
    $pdo = new PDO('sqlite:' . KOL_DATA_DIR . '/backup-test.sqlite');
    eq($sha, $pdo->query("SELECT sha FROM pf_files WHERE path = 'backup/in-zip.html'")->fetchColumn(), 'הרשומה במסד שבגיבוי');
    $pdo = null;
    @unlink($zipPath);
    @unlink(KOL_DATA_DIR . '/backup-test.sqlite');
});

test('פרויקט: הנתונים נשמרים רק במסד ותחת data/files/_project/ — שום נתיב חדש תחת data/', function () {
    $top = array_values(array_diff(scandir(KOL_DATA_DIR), ['.', '..']));
    foreach ($top as $f) ok(preg_match('/^kol\.sqlite(-wal|-shm)?$/', $f) || in_array($f, ['files', 'secrets.json', 't.bin', 'x.bin', 'old.sqlite', 'fresh.sqlite'], true) || preg_match('/\.sqlite(-wal|-shm)?$/', $f), "נתיב לא צפוי תחת data/: $f");
    ok(is_dir(KOL_DATA_DIR . '/files/_project'), 'התוכן תחת files/_project');
    eq(403, http('GET', '/data/files/_project/')['status'], 'data/ חסומה מהדפדפן');
    $mig = (string)file_get_contents(KOL_ROOT . '/lib/db.php');
    preg_match('/13 => <<<\'SQL\'(.*?)SQL,/s', $mig, $m);
    eq(0, preg_match('/\b(ALTER|DROP|UPDATE|DELETE)\b/', $m[1]), 'מיגרציה 13 מוסיפה בלבד');
    preg_match_all('/CREATE TABLE (\w+)/', $m[1], $t);
    eq(['pf_files', 'pf_history'], $t[1]);
});

test('בידוד (S4): התוכן מגיע לעורך כ-JSON; הבייטים — תמיד עם CSP sandbox, nosniff וסוג תוכן מרשימה סגורה', function () {
    $evil = "<script>fetch('api.php?action=me')</script>";
    $types = [
        'iso/x.html' => null, 'iso/x.htm' => null, 'iso/x.xhtml' => null, 'iso/x.js' => null, 'iso/x.css' => null, 'iso/x.php' => null, 'iso/x.xml' => null,
        'iso/x.pdf' => null, 'iso/x.json' => null, 'iso/בלי סיומת' => null, 'iso/x.svg.html' => null, 'iso/y.HTML' => null, 'iso/x.shtml' => null, 'iso/x.txt' => null,
        'iso/x.svg' => 'image/svg+xml', 'iso/x.png' => 'image/png', 'iso/x.JPG' => 'image/jpeg', 'iso/x.mp3' => 'audio/mpeg',
    ];
    foreach ($types as $path => $inline) {
        $id = pf_new($path, $evil)['json']['file']['id'];
        $read = http('GET', "/api.php?action=pf_read&id=$id", ['user' => U_EDITOR]);
        ok(str_starts_with($read['headers']['content-type'], 'application/json'), "pf_read מחזיר JSON · $path");
        eq('nosniff', $read['headers']['x-content-type-options']);
        foreach (['', '&download=1'] as $dl) {
            $raw = http('GET', "/api.php?action=pf_raw&id=$id$dl", ['user' => U_EDITOR]);
            eq(200, $raw['status']);
            eq($evil, $raw['body'], 'הבייטים כמו שהם');
            ok(preg_match('/(^|;)\s*sandbox\s*(;|$)/', $raw['headers']['content-security-policy']) === 1, "CSP sandbox · $path");
            ok(!str_contains($raw['headers']['content-security-policy'], 'allow-'), 'בלי allow-scripts / allow-same-origin');
            eq('nosniff', $raw['headers']['x-content-type-options'], "nosniff · $path");
            $want = $dl === '' ? $inline : null;
            eq($want ?? 'application/octet-stream', $raw['headers']['content-type'], "סוג תוכן · $path$dl");
            ok(str_starts_with($raw['headers']['content-disposition'], $want ? 'inline' : 'attachment'), "Content-Disposition · $path$dl");
        }
    }
    foreach (PF_INLINE_TYPES as $ext => $type) ok(preg_match('#^(image|audio|video)/#', $type) === 1, "ברשימה הסגורה רק תמונה, שמע ווידאו · $ext");
});

test('פרויקט: מדיה נכנסת לספריית המדיה, ומופיעה בעץ כהפניה שמוגשת באותן כותרות', function () {
    $png = KOL_DATA_DIR . '/t.bin';
    imagepng(imagecreatetruecolor(4, 4), $png);
    $bytes = (string)file_get_contents($png);
    $fid = file_store($png, 'hero.png', PF_ME);
    $r = http('POST', '/api.php?action=pf_link', ['user' => U_EDITOR, 'json' => ['path' => 'media/hero.png', 'file_id' => $fid]]);
    eq(200, $r['status']);
    eq('media', $r['json']['file']['kind']);
    $row = array_values(array_filter(http('GET', '/api.php?action=pf_tree', ['user' => U_EDITOR])['json']['files'], fn($f) => $f['path'] === 'media/hero.png'))[0];
    eq($fid, $row['media']);
    eq('image', $row['media_info']['kind']);
    ok(!is_file(pf_blob_path(hash('sha256', $bytes))), 'הבייטים נשמרים פעם אחת — בספרייה, לא במאגר');
    $raw = http('GET', '/api.php?action=pf_raw&id=' . $row['id'], ['user' => U_EDITOR]);
    eq(file_row($fid)['type'], $raw['headers']['content-type'], 'הסוג — מהרשימה הסגורה של ספריית המדיה');
    ok(isset(FILE_TYPES[$raw['headers']['content-type']]));
    ok(str_contains($raw['headers']['content-security-policy'], 'sandbox'), 'CSP sandbox גם למדיה');
    eq('nosniff', $raw['headers']['x-content-type-options']);
    eq(404, http('POST', '/api.php?action=pf_link', ['user' => U_EDITOR, 'json' => ['path' => 'media/none.png', 'file_id' => str_repeat('0', 32)]])['status'], 'הפניה לקובץ שאינו בספרייה');
});

test('פרויקט: kol.json לא תקין — מדווח, והמסך ממשיך עם הגרסה התקינה האחרונה', function () {
    $id = pf_paths()['kol.json'];
    $f = http('GET', "/api.php?action=pf_read&id=$id", ['user' => U_EDITOR])['json'];
    $w = http('POST', '/api.php?action=pf_write', ['user' => U_EDITOR, 'json' => ['id' => $id, 'rev' => $f['file']['rev'], 'content' => "{\n  \"entry\": \"index.html\",\n  שבור\n"]]);
    eq(200, $w['status'], 'קובץ שבור נשמר כמו שהוא');
    eq(false, $w['json']['kol']['valid']);
    ok($w['json']['kol']['error'] !== '', 'יש הודעת שגיאה');
    eq('index.html', $w['json']['kol']['config']['entry'], 'ההגדרות — מהגרסה התקינה האחרונה');
    eq($f['file']['rev'], $w['json']['kol']['rev']);
    $w2 = http('POST', '/api.php?action=pf_write', ['user' => U_EDITOR, 'json' => ['id' => $id, 'rev' => $w['json']['file']['rev'], 'content' => '{"version":1,"entry":"home.html"}']]);
    eq(true, $w2['json']['kol']['valid']);
    eq('home.html', $w2['json']['kol']['config']['entry']);
    http('POST', '/api.php?action=pf_write', ['user' => U_EDITOR, 'json' => ['id' => $id, 'rev' => $w2['json']['file']['rev'], 'content' => $f['content']]]);
});

test('בידוד (S6): Monaco מאוחסן באפליקציה — אין CDN בקוד הדפדפן; ומסך הפרויקט אינו כותב HTML מנתונים', function () {
    foreach (array_merge(glob(KOL_ROOT . '/assets/js/*.js'), [KOL_ROOT . '/index.html']) as $f) {
        eq(0, preg_match('#https?://[^\s\'"`]*(jsdelivr|unpkg|cdnjs|cloudflare\.com/ajax|monaco-editor)#i', (string)file_get_contents($f)), 'כתובת CDN ב-' . basename($f));
    }
    foreach (['loader.js', 'editor/editor.main.js', 'editor/editor.main.css', 'base/worker/workerMain.js', 'language/html/htmlMode.js', 'language/css/cssMode.js',
              'language/json/jsonMode.js', 'language/typescript/tsMode.js', 'basic-languages/markdown/markdown.js', 'basic-languages/xml/xml.js'] as $f) {
        ok(is_file(KOL_ROOT . '/assets/vendor/monaco/vs/' . $f), "חסר assets/vendor/monaco/vs/$f");
    }
    $js = (string)preg_replace('#^\s*//.*$#m', '', (string)file_get_contents(KOL_ROOT . '/assets/js/project.js'));
    foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'srcdoc', 'eval('] as $bad) ok(!str_contains($js, $bad), "$bad ב-project.js");
});

test('"העמוד של היום עוד לא יובא": seed_only כל עוד index.html הוא שלד הזריעה; עריכה אחת — כבר לא', function () {
    $t = http('GET', '/api.php?action=pf_tree', ['user' => U_EDITOR]);
    $f = null; foreach ($t['json']['files'] as $x) if ($x['path'] === 'index.html') $f = $x;
    ok($f !== null, 'יש index.html');
    $r = http('GET', '/api.php?action=pf_read&id=' . $f['id'], ['user' => U_EDITOR]);
    eq($r['json']['content'] === pf_seed_files()['index.html'], $t['json']['seed_only'], 'seed_only תואם לתוכן הנוכחי');
    $w = http('POST', '/api.php?action=pf_write', ['user' => U_EDITOR, 'json' => ['id' => $f['id'], 'rev' => $r['json']['file']['rev'], 'content' => pf_seed_files()['index.html']]]);
    eq(true, http('GET', '/api.php?action=pf_tree', ['user' => U_EDITOR])['json']['seed_only'], 'שלד הזריעה ← seed_only');
    $w2 = http('POST', '/api.php?action=pf_write', ['user' => U_EDITOR, 'json' => ['id' => $f['id'], 'rev' => $w['json']['file']['rev'], 'content' => pf_seed_files()['index.html'] . ' ']]);
    eq(false, http('GET', '/api.php?action=pf_tree', ['user' => U_EDITOR])['json']['seed_only'], 'נערך ← לא');
    http('POST', '/api.php?action=pf_write', ['user' => U_EDITOR, 'json' => ['id' => $f['id'], 'rev' => $w2['json']['file']['rev'], 'content' => $r['json']['content']]]);
});
