<?php
// C1 — כותרות האבטחה ככותרת שרת, ודוחות ההפרה ליומן (docs/spec-v2/slices/C1-studio-csp.md).

declare(strict_types=1);

require_once KOL_ROOT . '/lib/csp.php';

function csp_rep(array $r, string $p = 'studio', ?array $u = U_EDITOR, string $ct = 'application/csp-report'): array
{
    return http('POST', '/csp.php?p=' . $p, ['user' => $u, 'csrf' => false, 'raw' => json_encode(['csp-report' => $r]), 'headers' => ['Content-Type: ' . $ct]]);
}

function csp_rows(): array
{
    return csp_table()->query('SELECT * FROM csp_reports ORDER BY id')->fetchAll();
}

test('C1 שלב א: הסטודיו (/, index.php) — nosniff, Referrer-Policy, שלוש הנחיות נאכפות והמדיניות המלאה בדיווח בלבד', function () {
    foreach (['/', '/index.php'] as $u) {
        $r = http('GET', $u, ['user' => U_EDITOR]);
        eq(200, $r['status'], $u);
        ok(str_contains($r['body'], '<script src="assets/js/app.js'), "$u: גוף הדף הוא studio.html");
        eq('nosniff', $r['headers']['x-content-type-options'] ?? null, "$u nosniff");
        eq('strict-origin-when-cross-origin', $r['headers']['referrer-policy'] ?? null, "$u Referrer-Policy");
        $csp = $r['headers']['content-security-policy'] ?? '';
        ok(str_starts_with($csp, CSP_PHASE_A . '; report-uri /csp.php?p=studio'), "$u: נאכף — $csp");
        ok(!str_contains($csp, 'script-src') && !str_contains($csp, 'default-src'), "$u: שלב א אינו אוכף script-src (התצוגה הישנה היא srcdoc)");
        $ro = $r['headers']['content-security-policy-report-only'] ?? '';
        $full = editor_csp(parse_url($GLOBALS['BASE'], PHP_URL_HOST) . ':' . parse_url($GLOBALS['BASE'], PHP_URL_PORT), csp_site());
        eq($full . '; report-uri /csp.php?p=studio', $ro, "$u: Report-Only — המדיניות המלאה של העורך, מילה במילה");
    }
});

test('C1: studio.html (המעטפת) בכתובת ישירה — 403; index.html (הכתובת הישנה) — 302 ל-index.php עם השאילתה; app.json → index.php', function () {
    eq(403, http('GET', '/studio.html', ['user' => U_EDITOR])['status']);
    $r = http('GET', '/index.html?pwa-launch=1', ['user' => U_EDITOR]);
    eq([302, '/index.php?pwa-launch=1'], [$r['status'], $r['headers']['location'] ?? null], 'קיצור דרך / PWA ישן');
    ok(!is_file(KOL_ROOT . '/index.html'), 'אין index.html על הדיסק — הכללים של השער (שדורשים קובץ קיים) אינם תופסים אותו');
    $ht = (string)file_get_contents(KOL_ROOT . '/.htaccess');
    ok(preg_match('/^DirectoryIndex index\.php$/m', $ht), 'DirectoryIndex index.php — התיקייה נפתחת ב-index.php');
    ok(preg_match('~RewriteCond %\{REQUEST_URI\} \^\(\.\*/\)index\\\.html\$\s*RewriteRule \^index\\\.html\$ %1index\.php \[R=302,L,QSA\]~', $ht), 'הכלל: index.html → index.php, 302, מהנתיב המבוקש');
    ok(preg_match('~<Files "studio\.html">\s*<IfModule mod_authz_core\.c>\s*Require all denied~', $ht), 'studio.html: Require all denied');
    ok(preg_match('~<Files "studio\.html">\s*<IfModule mod_authz_core\.c>\s*Require all granted~', (string)file_get_contents(KOL_ROOT . '/preview/.htaccess')), 'preview/: studio.html של פרויקט מותר במפורש');
    $app = json_decode((string)file_get_contents(KOL_ROOT . '/app.json'), true);
    eq('index.php', $app['entry'], 'app.json: הכניסה היא index.php');
    // שום קובץ אמיתי בשם studio.html מתחת לתיקייה, חוץ מהמעטפת (החסימה חלה גם בתת-תיקיות)
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(KOL_ROOT, FilesystemIterator::SKIP_DOTS));
    $found = [];
    foreach ($it as $f) if ($f->getFilename() === 'studio.html') $found[] = substr($f->getPathname(), strlen(KOL_ROOT) + 1);
    eq(['studio.html'], $found, 'studio.html יחיד');
});

test('C1: build-gate.php (הפריסה) על עותק — הבלוק של השער בראש, הכללים של C1 נשמרים אחריו, ושום כלל של השער אינו תופס את index.html', function () {
    $tmp = sys_get_temp_dir() . '/kol-bg-' . bin2hex(random_bytes(4)) . '/home/u/public_html/mini_projects';
    mkdir("$tmp/platform/lib", 0775, true); mkdir("$tmp/kol-maroko/preview", 0775, true);
    copy(dirname(KOL_ROOT) . '/platform/build-gate.php', "$tmp/platform/build-gate.php");
    copy(dirname(KOL_ROOT) . '/platform/lib/registry.php', "$tmp/platform/lib/registry.php");
    foreach (['app.json', '.htaccess', 'preview/.htaccess'] as $f) copy(KOL_ROOT . "/$f", "$tmp/kol-maroko/$f");
    try {
        exec(PHP_BINARY . ' ' . escapeshellarg("$tmp/platform/build-gate.php") . ' --root=/home/u/public_html/mini_projects --host=mbe-plus.com 2>&1', $out, $code);
        eq(0, $code, implode(' ', $out));
        $ht = (string)file_get_contents("$tmp/kol-maroko/.htaccess");
        $gate = strpos($ht, '# <<< platform-gate'); $mine = strpos($ht, 'RewriteRule ^index\.html$');
        ok(str_starts_with($ht, '# >>> platform-gate') && $gate !== false && $mine > $gate, 'הסדר: השער, ואחריו C1');
        ok(str_contains($ht, (string)file_get_contents(KOL_ROOT . '/.htaccess')), 'הכללים של C1 — כמו שהם');
        // כל כלל של השער שמגיש קובץ דורש שהוא קיים (-f), ושל התיקייה — index.html קיים ו-index.php לא
        ok(str_contains($ht, "RewriteCond %{REQUEST_FILENAME}/index.php !-f") && preg_match_all('/RewriteCond %\{REQUEST_FILENAME\} -f\s*\n\s*RewriteRule/', $ht) === 1, 'כללי השער תלויים בקובץ קיים');
    } finally {
        rrmdir(dirname($tmp, 4));
    }
});

test('C1: כל תשובת API — default-src \'none\'; frame-ancestors \'none\'; sandbox, nosniff, Referrer-Policy (גם שגיאה, גם בלי משתמש)', function () {
    $cases = [
        ['GET', '/api.php?action=me', U_EDITOR], ['GET', '/api.php?action=me', null], ['GET', '/api.php?action=nosuch', U_EDITOR],
        ['POST', '/api.php?action=word_save', U_EDITOR], ['GET', '/api.php?action=embed_key', null], ['POST', '/agent.php', null],
        ['GET', '/csp.php', U_EDITOR], ['POST', '/csp.php?p=studio', U_EDITOR],
    ];
    foreach ($cases as [$m, $u, $who]) {
        $r = http($m, $u, ['user' => $who, 'csrf' => false, 'raw' => $m === 'POST' ? '{}' : null]);
        eq(CSP_API, $r['headers']['content-security-policy'] ?? null, "$m $u ({$r['status']})");
        eq('nosniff', $r['headers']['x-content-type-options'] ?? null, "$m $u nosniff");
        eq('strict-origin-when-cross-origin', $r['headers']['referrer-policy'] ?? null, "$m $u Referrer-Policy");
    }
});

test('C1: editor.php ו-live.php — המדיניות המלאה עם report-uri משלהן; editor_csp לא השתנתה מ-V1b', function () {
    $e = http('GET', '/editor.php', ['user' => U_EDITOR])['headers']['content-security-policy'] ?? '';
    ok(str_ends_with($e, '; report-uri /csp.php?p=editor') && str_contains($e, "script-src 'self';") && str_contains($e, 'https://fonts.gstatic.com'), 'editor.php: ' . $e);
    $l = http('GET', '/live.php', ['user' => U_EDITOR])['headers']['content-security-policy'] ?? '';
    ok(str_ends_with($l, '; report-uri /csp.php?p=live') && str_contains($l, "default-src 'none'"), 'live.php: ' . $l);
    eq("default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; img-src 'self' blob: data: https://s.example; media-src 'self' blob: data: https://s.example; font-src 'self' data: https://fonts.gstatic.com; connect-src 'self'; worker-src 'self' blob:; frame-src h/d/live.php h/d/preview/ https://mbe-plus.com/mini_projects/word-processor/; frame-ancestors 'self'; base-uri 'self'; object-src 'none'; form-action 'self'",
        editor_csp('h/d', 'https://s.example'), 'המדיניות של V1b, תו בתו (X1: + התיקייה של עורך התמלילים ב-frame-src)');
    // X1: frame-src — בדיוק live.php, preview/ והתיקייה המלאה של עורך התמלילים; שום מקור אחר
    preg_match('/frame-src ([^;]+)/', editor_csp('h/d', ''), $m);
    eq(['h/d/live.php', 'h/d/preview/', 'https://mbe-plus.com/mini_projects/word-processor/'], explode(' ', $m[1]));
});

test('C1: דוח הפרה נצבר — שורה אחת לכל (מדיניות, אכיפה, הנחיה, מקור, מסמך) עם מונה; ושורה מקופלת אחת ביומן המפתח', function () {
    csp_table()->exec('DELETE FROM csp_reports');
    $before = (int)db()->query("SELECT COUNT(*) FROM dev_log WHERE category = 'csp'")->fetchColumn();
    $r = ['document-uri' => $GLOBALS['BASE'] . '/index.php?x=1#/studio', 'effective-directive' => 'img-src', 'violated-directive' => 'img-src',
          'blocked-uri' => 'https://cdn.evil.example/a/b.png?u=1', 'disposition' => 'report', 'source-file' => $GLOBALS['BASE'] . '/assets/js/studio.js', 'line-number' => 12];
    for ($i = 0; $i < 5; $i++) eq(204, csp_rep($r + ['blocked-uri' => 'https://cdn.evil.example/x' . $i . '.png'])['status']);
    $rows = csp_rows();
    eq(1, count($rows), 'חמש הפרות של אותו מקור — שורה אחת');
    eq(['studio', 'report', 'img-src', 'https://cdn.evil.example', "'self':index.php", 5, 0], [$rows[0]['policy'], $rows[0]['disposition'], $rows[0]['directive'], $rows[0]['blocked'], $rows[0]['doc'], (int)$rows[0]['count'], (int)$rows[0]['expected']]);
    eq("'self':assets/js/studio.js:12", $rows[0]['source']);
    $log = db()->query("SELECT level, event, count FROM dev_log WHERE category = 'csp'")->fetchAll();
    eq($before + 1, count($log), 'ביומן המפתח — שורה אחת, לא שורה לכל הפרה');
    eq(['warn', 'csp.img-src', 5], [end($log)['level'], end($log)['event'], (int)end($log)['count']]);
    // הנחיה אחרת / אכיפה — קבוצה משלה; הפרה נאכפת היא שגיאה
    csp_rep(['document-uri' => $GLOBALS['BASE'] . '/index.php', 'effective-directive' => 'object-src', 'blocked-uri' => 'https://x.example/f.swf', 'disposition' => 'enforce']);
    csp_rep(['document-uri' => $GLOBALS['BASE'] . '/index.php', 'violated-directive' => 'script-src-elem', 'blocked-uri' => 'inline', 'disposition' => 'report', 'script-sample' => 'alert(1)']);
    eq(3, count(csp_rows()));
    eq('error', db()->query("SELECT level FROM dev_log WHERE event = 'csp.object-src' ORDER BY id DESC")->fetchColumn(), 'הפרה של מדיניות שנאכפת — error');
    eq('inline', csp_rows()[2]['blocked']);
});

test('C1: Reporting API (מערך, camelCase) — נקלט באותה טבלה', function () {
    csp_table()->exec('DELETE FROM csp_reports');
    $body = json_encode([['type' => 'csp-violation', 'body' => ['documentURL' => $GLOBALS['BASE'] . '/editor.php', 'effectiveDirective' => 'connect-src', 'blockedURL' => 'https://api.evil.example/x', 'disposition' => 'enforce']]]);
    eq(204, http('POST', '/csp.php?p=editor', ['user' => U_EDITOR, 'raw' => $body, 'csrf' => false, 'headers' => ['Content-Type: application/reports+json']])['status']);
    $rows = csp_rows();
    eq(['editor', 'enforce', 'connect-src', 'https://api.evil.example', 0], [$rows[0]['policy'], $rows[0]['disposition'], $rows[0]['directive'], $rows[0]['blocked'], (int)$rows[0]['expected']], 'connect-src בעורך אינו "צפוי" — שגיאה');
});

test('C1 §2א: משאב חיצוני בקנבס של העורך — נספר בנפרד (expected), ואינו שגיאה ביומן המפתח', function () {
    csp_table()->exec('DELETE FROM csp_reports');
    $before = (int)db()->query("SELECT COUNT(*) FROM dev_log WHERE category = 'csp'")->fetchColumn();
    foreach (['img-src', 'font-src', 'media-src', 'style-src-elem'] as $d) csp_rep(['document-uri' => 'about:srcdoc', 'effective-directive' => $d, 'blocked-uri' => 'https://cdn.site.example/f', 'disposition' => 'enforce'], 'editor');
    $rows = csp_rows();
    eq(4, count($rows));
    ok(array_sum(array_column($rows, 'expected')) === 4, 'ארבעתן צפויות');
    eq('about:srcdoc', $rows[0]['doc']);
    eq($before, (int)db()->query("SELECT COUNT(*) FROM dev_log WHERE category = 'csp'")->fetchColumn(), 'אין שורה ביומן המפתח');
    // אותה חסימה בסטודיו (דיווח בלבד) — אינה "צפויה": המידע הזה הוא בדיוק מה ששלב א אוסף
    csp_rep(['document-uri' => 'about:srcdoc', 'effective-directive' => 'img-src', 'blocked-uri' => 'https://cdn.site.example/f', 'disposition' => 'report'], 'studio');
    eq(0, (int)csp_rows()[4]['expected']);
});

test('C1: דוחות — עקיפה: בלי זהות, מדיניות לא מוכרת, GET, גוף ענק, JSON שבור, ומאה דוחות בבקשה — אינם נקלטים או מוגבלים', function () {
    csp_table()->exec('DELETE FROM csp_reports');
    $r = ['document-uri' => 'about:srcdoc', 'effective-directive' => 'img-src', 'blocked-uri' => 'https://a.example', 'disposition' => 'report'];
    eq(204, csp_rep($r, 'studio', null)['status'], 'בלי זהות — 204 בשקט');
    csp_rep($r, 'evil');
    csp_rep($r, rawurlencode("studio' OR 1=1 --"));
    eq(405, http('GET', '/csp.php?p=studio', ['user' => U_EDITOR])['status']);
    http('POST', '/csp.php?p=studio', ['user' => U_EDITOR, 'csrf' => false, 'raw' => json_encode(['csp-report' => $r + ['x' => str_repeat('a', CSP_MAX_BODY)]])]);
    http('POST', '/csp.php?p=studio', ['user' => U_EDITOR, 'csrf' => false, 'raw' => '{"csp-report": ']);
    http('POST', '/csp.php?p=studio', ['user' => U_EDITOR, 'csrf' => false, 'raw' => '{"csp-report": {"effective-directive": "<img src=x onerror=alert(1)>"}}']);
    eq(0, count(csp_rows()), 'שום דבר לא נקלט');
    $many = json_encode(array_fill(0, 100, ['body' => $r]));
    http('POST', '/csp.php?p=studio', ['user' => U_EDITOR, 'csrf' => false, 'raw' => $many]);
    eq(CSP_MAX_PER_REQUEST, (int)csp_rows()[0]['count'], 'לכל היותר ' . CSP_MAX_PER_REQUEST . ' דוחות מבקשה אחת');
});

test('C1: דוחות — קיבוץ חסום: כתובת עם שאילתה/אסימון נשמרת כמקור בלבד; תקרת קבוצות; סוד בדגימה מוסתר', function () {
    csp_table()->exec('DELETE FROM csp_reports');
    csp_rep(['document-uri' => $GLOBALS['BASE'] . '/editor.php?token=kmd_abcdefgh123', 'effective-directive' => 'img-src', 'blocked-uri' => 'https://u:p@leak.example:8443/p?token=SECRET', 'disposition' => 'report',
             'script-sample' => 'token=kol_abcdef1234567']);
    $row = csp_rows()[0];
    eq(['https://leak.example:8443', "'self':editor.php"], [$row['blocked'], $row['doc']]);
    ok(!str_contains(json_encode(csp_rows()), 'SECRET') && !str_contains($row['sample'], 'abcdef1234567'), 'השאילתה, הסיסמה והאסימון אינם נשמרים');
    // תקרה: 500 קבוצות, ומעבר להן — קבוצה "*" אחת
    $pdo = csp_table();
    $ins = $pdo->prepare("INSERT INTO csp_reports (policy, disposition, directive, blocked, doc, expected, count, first_at, last_at) VALUES ('studio', 'report', 'img-src', ?, 'x', 0, 1, 'a', 'a')");
    for ($i = 0; $i < CSP_MAX_GROUPS; $i++) $ins->execute(['https://h' . $i . '.example']);
    for ($i = 0; $i < 3; $i++) csp_rep(['document-uri' => 'about:srcdoc', 'effective-directive' => 'img-src', 'blocked-uri' => 'https://new' . $i . '.example', 'disposition' => 'report']);
    eq(CSP_MAX_GROUPS + 2, count(csp_rows()), 'שורה אחת נוספת בלבד — "*"');
    eq(3, (int)$pdo->query("SELECT count FROM csp_reports WHERE directive = '*'")->fetchColumn());
});

test('C1: csp-reports — רק למפתח; מחזיר את הקבוצות, הכי תכופות קודם', function () {
    csp_table()->exec('DELETE FROM csp_reports');
    for ($i = 0; $i < 3; $i++) csp_rep(['document-uri' => 'about:srcdoc', 'effective-directive' => 'font-src', 'blocked-uri' => 'https://f.example', 'disposition' => 'report']);
    csp_rep(['document-uri' => 'about:srcdoc', 'effective-directive' => 'img-src', 'blocked-uri' => 'https://f.example', 'disposition' => 'report']);
    eq(403, http('GET', '/api.php?action=csp-reports', ['user' => U_EDITOR])['status']);
    eq(401, http('GET', '/api.php?action=csp-reports')['status']);
    $r = http('GET', '/api.php?action=csp-reports', ['user' => U_OWNER])['json'];
    eq([4, 'font-src', 3], [$r['total'], $r['reports'][0]['directive'], $r['reports'][0]['count']]);
});
