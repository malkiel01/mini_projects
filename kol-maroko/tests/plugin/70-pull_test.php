<?php
/**
 * M7 — התוסף מושך (v2-pull.php), מול "כלי" מדומה (tests/plugin/fake-pull.php) שחותם במפתח של האתר המדומה.
 * ערכת ההתקפה של 07 §6, השורות החדשות: H18–H23, H27, H28 בצד התוסף (H24–H26 — בצד הכלי, tests/cases/99-outbox.php).
 * כל בדיקה מנסה לשבור: פקודה ששודרה שוב, שפג תוקפה, גוש פגום, שרת זדוני, ערוץ לא נכון, הפניה, ריצה שנקטעה, שתי ריצות במקביל.
 */

declare(strict_types=1);

function kpp(): array
{
    if (isset($GLOBALS['KPP'])) return $GLOBALS['KPP'];
    kp_site();
    $dir = $GLOBALS['TMP'] . '/pull-m7';
    @mkdir($dir . '/blobs', 0775, true);
    [$proc, $base] = serve(__DIR__ . '/fake-pull.php', ['KOL_TEST_PULL' => $dir, 'PHP_CLI_SERVER_WORKERS' => '4']);
    $GLOBALS['procs'][] = $proc;
    return $GLOBALS['KPP'] = ['dir' => $dir, 'base' => $base, 'url' => $base . '/kol-maroko/pull.php', 'token' => 'tok_' . bin2hex(random_bytes(16))];
}

/** אתר נקי + שורת חיבור לשרת המדומה; התור — $cfg (נכתב ל-pull.json). */
function kpp_reset(array $cfg = [], array $opts = []): void
{
    $p = kpp();
    kp_reset($opts + ['kol_maroko_pull_url' => $p['url'], 'kol_maroko_pull_token' => $p['token']]);
    foreach (['pull-log.json', 'reports.json'] as $f) @unlink($p['dir'] . "/$f");
    kpp_cfg($cfg);
}

function kpp_cfg(array $cfg): void
{
    $p = kpp();
    file_put_contents($p['dir'] . '/pull.json', json_encode($cfg + ['token' => $p['token'], 'items' => [], 'blobs' => []]));
}

function kpp_cfg_get(): array { return json_decode((string)file_get_contents(kpp()['dir'] . '/pull.json'), true); }
function kpp_log(): array { return json_decode((string)@file_get_contents(kpp()['dir'] . '/pull-log.json'), true) ?: []; }
function kpp_reports(): array { return json_decode((string)@file_get_contents(kpp()['dir'] . '/reports.json'), true) ?: []; }

/** פקודה חתומה כמו שהכלי מכניס לתור. $o: site, expires, secret, kid, item_seq, action (בגוף), body (בייטים אחרים). */
function kpp_item(string $action, array $payload, int $seq, array $o = []): array
{
    $k = kp_site();
    $body = json_encode($payload + ['action' => $o['action'] ?? $action, 'site' => $o['site'] ?? $k['host'], 'page' => KP_PAGE, 'time' => time() - 3600,
        'nonce' => bin2hex(random_bytes(16)), 'seq' => $seq, 'expires' => $o['expires'] ?? time() + 3600], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $sig = base64_encode(sodium_crypto_sign_detached($body, $o['secret'] ?? $k['secret']));
    return ['seq' => $o['item_seq'] ?? $seq, 'action' => $action, 'body' => base64_encode($o['body'] ?? $body), 'sig' => $sig, 'kid' => $o['kid'] ?? $k['kid']];
}

/** מניפסט בתור: הגושים נכתבים לשרת המדומה (ולא נשלחים לאתר). מחזיר את הפריט; $blobs מתמלא sha ← נתיב. */
function kpp_manifest(array $files, int $version, int $seq, array &$blobs, array $o = []): array
{
    $k = kp_site();
    $exp = $o['expires'] ?? time() + 7 * 86400;
    $list = [];
    foreach ($files as $p => $bytes) {
        $sha = hash('sha256', $bytes);
        file_put_contents(kpp()['dir'] . "/blobs/$sha", $bytes);
        $blobs[$sha] = kpp()['dir'] . "/blobs/$sha";
        $list[$p] = ['sha256' => $sha, 'size' => strlen($bytes), 'type' => kp_mime($p)];
    }
    $man = ($o['man'] ?? []) + ['site' => $k['host'], 'page' => KP_PAGE, 'version' => $version, 'time' => time() - 7200, 'nonce' => bin2hex(random_bytes(16)),
        'entry' => 'index.html', 'published_by' => 'בודק', 'seq' => $seq, 'expires' => $exp, 'files' => $list];
    $bytes = json_encode($man, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return kpp_item('manifest', ['manifest' => $bytes, 'manifest_sig' => base64_encode(sodium_crypto_sign_detached($bytes, $k['secret'])), 'kid' => $k['kid']], $seq, ['expires' => $exp] + $o);
}

function kpp_run(): array
{
    $r = kp_http('GET', '/__pull');
    $j = json_decode($r['body'], true);
    if (!is_array($j)) throw new TestFail('הריצה לא החזירה JSON: ' . mb_substr($r['body'], 0, 300));
    return $j;
}

/** הדיווח האחרון: seq ← [state, code]. */
function kpp_last_report(): array
{
    $all = kpp_reports();
    $last = end($all) ?: [];
    $out = [];
    foreach ($last['results'] ?? [] as $r) $out[$r['seq']] = [$r['state'], $r['code']];
    return $out;
}

function kpp_admin(array $post): array
{
    return kp_http('POST', '/wp-admin/admin-post.php', ['Cookie: test_admin=1', 'Content-Type: application/x-www-form-urlencoded'],
        http_build_query($post + ['action' => 'kol_maroko_v2', '_wpnonce' => 'nonce-kol_maroko_v2']));
}

test('M7 שורת החיבור: kol-maroko:2:<kid>:<מפתח>:<כתובת>:<אסימון> — נשמרת ומפורקת; שורה פגומה / http לדומיין / כתובת עם משתמש — נדחית', function () {
    $p = kpp();
    kp_reset();
    $k = kp_site();
    $line = 'kol-maroko:2:' . $k['kid'] . ':' . base64_encode($k['public']) . ':' . $p['url'] . ':' . $p['token'];
    foreach ([
        'kol-maroko:2:' . $k['kid'] . ':' . base64_encode($k['public']) . ':http://evil.example/pull.php:' . $p['token'],        // http לדומיין
        'kol-maroko:2:' . $k['kid'] . ':' . base64_encode($k['public']) . ':https://u:p@mbe-plus.com/pull.php:' . $p['token'],   // משתמש בכתובת
        'kol-maroko:2:' . $k['kid'] . ':' . base64_encode($k['public']) . ':https://mbe-plus.com/pull.php?x=1:' . $p['token'],  // query
        'kol-maroko:2:' . $k['kid'] . ':' . base64_encode($k['public']) . ':javascript://x/:' . $p['token'],
        'kol-maroko:2:' . $k['kid'] . ':' . base64_encode($k['public']) . ':' . $p['url'] . ':short',                          // אסימון קצר
        'kol-maroko:2:' . $k['kid'] . ':AAAA:' . $p['url'] . ':' . $p['token'],                                                 // מפתח לא תקין
        $k['kid'] . ':' . base64_encode($k['public']),                                                                          // השורה הישנה — לא שורת חיבור
    ] as $bad) {
        kpp_admin(['op' => 'pull_line', 'line' => $bad]);
        eq(null, kp_get('kol_maroko_pull_token'), 'נדחתה: ' . mb_substr($bad, 0, 60));
        ok(str_contains((string)kp_get('_transient_kol_maroko_v2_msg'), 'השורה לא תקינה'), 'הודעה: ' . kp_get('_transient_kol_maroko_v2_msg'));
    }
    kpp_cfg([]);
    kpp_admin(['op' => 'pull_line', 'line' => $line]);
    eq([$p['url'], $p['token']], [kp_get('kol_maroko_pull_url'), kp_get('kol_maroko_pull_token')], 'נשמרה');
    ok(str_contains((string)kp_get('kol_maroko_keys'), $k['kid'] . ':' . base64_encode($k['public'])), 'המפתח נוסף');
    ok(isset(kp_get('test_cron')['kol_maroko_pull_cron']), 'wp-cron נקבע');
    eq('kol_maroko_pull', kp_get('test_cron')['kol_maroko_pull_cron']['rec'], 'המרווח של התוסף');
    ok(str_contains((string)kp_get('_transient_kol_maroko_v2_msg'), '✓ הכלי ענה'), 'בדוק חיבור אחרי השמירה: ' . kp_get('_transient_kol_maroko_v2_msg'));
    eq(['redirection' => 0, 'sslverify' => true], kp_get('test_remote_args'), 'בלי הפניות, עם בדיקת תעודה');
    // בלי manage_options / nonce — לא נשמר
    kp_opt('kol_maroko_pull_token', null);
    kp_http('POST', '/wp-admin/admin-post.php', ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['action' => 'kol_maroko_v2', '_wpnonce' => 'nonce-kol_maroko_v2', 'op' => 'pull_line', 'line' => $line]));
    eq(null, kp_get('kol_maroko_pull_token'), 'בלי מנהל');
    // הקטע בעמוד התוסף
    kpp_admin(['op' => 'pull_line', 'line' => $line]);
    $page = kp_http('GET', '/wp-admin/options-general.php', ['Cookie: test_admin=1'])['body'];
    foreach (['📥 משיכה מהכלי', '🔄 עדכן עכשיו', 'בדוק חיבור', 'ה-seq האחרון שהוחל', 'Cron Jobs'] as $s) ok(str_contains($page, $s), "בעמוד: $s");
    ok(!str_contains($page, $p['token']), 'האסימון אינו מוצג בעמוד');
});

test('M7 קצה לקצה בתוסף: manifest (גושים לפי hash) + mount — מוחלים, הדיווח applied, הסמן מתקדם, ההגשה מהגרסה החדשה', function () {
    $blobs = [];
    $files = kp_bundle('P');
    $items = [kpp_manifest($files, 5, 3, $blobs), kpp_item('mount', ['mount' => 'bar'], 4)];
    kpp_reset(['items' => $items, 'blobs' => $blobs]);
    $r = kpp_run();
    eq([true, 2, 0], [$r['ok'], $r['applied'], $r['failed']], json_encode($r, JSON_UNESCAPED_UNICODE));
    eq(5, (int)kp_get('kol_maroko_v2_live'), 'באוויר');
    eq('bar', kp_get('kol_maroko_v2_mount'), 'האופן');
    eq(4, (int)kp_get('kol_maroko_pull_cursor'), 'הסמן');
    eq([3 => ['applied', ''], 4 => ['applied', '']], kpp_last_report(), 'הדיווח');
    $reps = kpp_reports();
    $st = end($reps)['state'];
    eq([KOL_PLUGIN_EXPECTED, 5, 4, 'bar'], [$st['plugin'], $st['v2_live'], $st['cursor'], $st['v2_mount']], 'מצב התוסף בדיווח');
    $blobReqs = array_values(array_filter(kpp_log(), fn($l) => str_starts_with($l['query'], 'blob=')));
    eq(count(array_unique(array_map(fn($b) => hash('sha256', $b), $files))), count($blobReqs), 'כל גוש — בקשה אחת');
    ok(array_reduce(kpp_log(), fn($c, $l) => $c && $l['auth'] && str_starts_with($l['ua'], 'KolMaroko-Plugin/'), true), 'כל בקשה עם האסימון');
    kp_opt('kol_maroko_v2_on', true);
    kp_opt('kol_maroko_v2_mount', 'full');
    ok(str_contains(kp_http('GET', '/lexicon/')['body'], 'VERSION-P'), 'מוגש מהגרסה שנמשכה');
    // ריצה שנייה — אין חדש, ושום גוש לא יורד שוב
    $n = count(kpp_log());
    $r = kpp_run();
    eq([0, 0], [$r['applied'], $r['failed']]);
    eq(2, count(kpp_log()) - $n, 'רק משיכה + דיווח');
    // גרסה 6 שחולקת גושים עם 5 — רק החדש יורד
    $f2 = $files;
    $f2['index.html'] = '<p>VERSION-Q</p>';
    kpp_cfg(['items' => [kpp_manifest($f2, 6, 7, $blobs)], 'blobs' => $blobs]);
    $n = count(kpp_log());
    eq(1, kpp_run()['applied']);
    eq(1, count(array_filter(array_slice(kpp_log(), $n), fn($l) => str_starts_with($l['query'], 'blob='))), 'רק הגוש החדש');
});

test('M7 H18: פקודה ששודרה שוב (אותו seq, אותה חתימה), גם אחרי 10 דקות — אינה מוחלת שוב', function () {
    $item = kpp_item('mount', ['mount' => 'framed'], 10);
    kpp_reset(['items' => [$item]]);
    eq(1, kpp_run()['applied']);
    kp_opt('kol_maroko_v2_mount', 'full');                // מישהו שינה בינתיים; שידור חוזר אסור להחזיר את framed
    kp_opt('kol_maroko_v2_nonces', null);                 // "אחרי 10 דקות" — חלון ה-nonce כבר שכח
    kpp_cfg(['items' => [$item + ['always' => true]]]);   // השרת מגיש אותה שוב, למרות after
    $r = kpp_run();
    eq([0, 0], [$r['applied'], $r['failed']], json_encode($r, JSON_UNESCAPED_UNICODE));
    eq('full', kp_get('kol_maroko_v2_mount'), 'לא הוחלה שוב');
    eq(10, (int)kp_get('kol_maroko_pull_cursor'));
    // seq נמוך יותר (פקודה ישנה ותקפה) — גם היא לא
    kpp_cfg(['items' => [kpp_item('mount', ['mount' => 'bar'], 9) + ['always' => true]]]);
    kpp_run();
    eq('full', kp_get('kol_maroko_v2_mount'), 'seq ישן');
});

test('M7 H19: פקודה שפג תוקפה — נדחית expired, הסמן מתקדם, הדיווח expired; expires רחוק מדי — נדחה', function () {
    kpp_reset(['items' => [kpp_item('mount', ['mount' => 'framed'], 3, ['expires' => time() - 5]), kpp_item('mount', ['mount' => 'bar'], 4, ['expires' => time() + 30 * 86400])]]);
    $r = kpp_run();
    eq([0, 2], [$r['applied'], $r['failed']]);
    eq(null, kp_get('kol_maroko_v2_mount'), 'שום אופן לא הוחל');
    eq([3 => ['failed', 'expired'], 4 => ['failed', 'bad_request']], kpp_last_report());
    eq(4, (int)kp_get('kol_maroko_pull_cursor'), 'חתומות — הסמן עבר אותן');
});

test('M7 H20: גוש שה-sha256 שלו אינו תואם (שרת זדוני) — נמחק, נמשך שוב, אחרי 3 — failed: blob_mismatch; הגרסה שבאוויר לא נגעה', function () {
    kpp_reset();
    kp_live(kp_bundle('L'), 2);                           // באוויר — גרסה 2 (בדחיפה)
    $blobs = [];
    $files = kp_bundle('M');
    $item = kpp_manifest($files, 3, 5, $blobs);
    $bad = hash('sha256', $files['index.html']);
    kpp_cfg(['items' => [$item], 'blobs' => $blobs, 'corrupt' => [$bad => true]]);
    $r = kpp_run();
    eq([0, 1], [$r['applied'], $r['failed']], json_encode($r, JSON_UNESCAPED_UNICODE));
    eq([5 => ['failed', 'blob_mismatch']], kpp_last_report());
    eq(3, count(array_filter(kpp_log(), fn($l) => $l['query'] === "blob=$bad")), 'שלוש הורדות של הגוש הפגום');
    eq(2, (int)kp_get('kol_maroko_v2_live'), 'הגרסה שבאוויר לא נגעה');
    ok(!is_file(kp_v2() . "/parts/$bad.pull"), 'הגוש הפגום נמחק');
    ok(kp_v2_blob_missing($bad), 'ולא נשמר');
});

function kp_v2_blob_missing(string $sha): bool { return !glob(kp_v2() . "/blobs/$sha*"); }

test('M7 H21: שרת משיכה שמחזיר פקודה לא חתומה / במפתח לא ידוע / ששונתה / לאתר אחר / action אחר — נדחית ונרשמת בדיווח; לא חתומה אינה מקדמת את הסמן', function () {
    $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    $ok = kpp_item('mount', ['mount' => 'bar'], 20);
    $tampered = $ok;
    $tampered['body'] = base64_encode(str_replace('"bar"', '"framed"', base64_decode($ok['body'])));
    kpp_reset(['items' => [
        kpp_item('mount', ['mount' => 'framed'], 100, ['secret' => $other]),                 // מפתח אחר, seq גבוה — ניסיון "לקפוץ" את הסמן
        kpp_item('mount', ['mount' => 'framed'], 101, ['kid' => 'k-unknown']),
        ['seq' => 102] + $tampered,
        kpp_item('mount', ['mount' => 'framed'], 103, ['site' => 'evil.example']),
        kpp_item('mount', ['mount' => 'framed'], 104, ['action' => 'switch']),               // הפריט אומר mount, הגוף — switch
        kpp_item('mount', ['mount' => 'framed'], 105, ['item_seq' => 999]),                  // ה-seq שבפריט אינו זה שבחתימה
        ['seq' => 106, 'action' => 'mount', 'body' => '!!', 'sig' => '', 'kid' => 'k-m2'],
    ]]);
    $r = kpp_run();
    eq(0, $r['applied'], json_encode($r, JSON_UNESCAPED_UNICODE));
    eq(null, kp_get('kol_maroko_v2_mount'), 'שום דבר לא הוחל');
    $rep = kpp_last_report();
    eq(['failed', 'bad_signature'], $rep[100] ?? null, 'מפתח אחר');
    eq(['failed', 'bad_signature'], $rep[101] ?? null, 'kid לא ידוע');
    eq(['failed', 'bad_signature'], $rep[102] ?? null, 'גוף ששונה');
    eq(['failed', 'wrong_site'], $rep[103] ?? null, 'אתר אחר');
    eq(['failed', 'bad_request'], $rep[104] ?? null, 'action אחר');
    eq(['failed', 'bad_request'], $rep[999] ?? null, 'seq בפריט ≠ seq חתום');
    eq(103, (int)kp_get('kol_maroko_pull_cursor'), 'רק החתומה (אתר אחר) קידמה את הסמן — 100/101/102 לא');
    // הפקודה האמיתית אחריהן — עדיין מוחלת
    kpp_cfg(['items' => [kpp_item('mount', ['mount' => 'bar'], 110)]]);
    eq(1, kpp_run()['applied']);
    eq('bar', kp_get('kol_maroko_v2_mount'));
    // מניפסט שהחתימה הפנימית שלו של מפתח אחר / ששייך ל-seq אחר / עם נתיב ../ — נדחה, ושום גוש לא יורד
    $blobs = [];
    $m1 = kpp_manifest(['index.html' => 'A'], 9, 120, $blobs, ['man' => ['seq' => 7]]);
    $m2 = kpp_manifest(['../x.html' => 'B', 'index.html' => 'C'], 9, 121, $blobs);
    kpp_cfg(['items' => [$m1, $m2], 'blobs' => $blobs]);
    $n = count(kpp_log());
    kpp_run();
    eq([120 => ['failed', 'bad_request'], 121 => ['failed', 'bad_path']], kpp_last_report());
    eq(0, count(array_filter(array_slice(kpp_log(), $n), fn($l) => str_starts_with($l['query'], 'blob='))), 'לא ירד שום גוש');
});

test('M7 H22: פקודה עם seq שנשלחת בערוץ הדחיפה — wrong_channel; מניפסט מהתור בדחיפה — wrong_channel', function () {
    kpp_reset();
    $r = kp_send('mount', ['mount' => 'framed', 'seq' => 5, 'expires' => time() + 3600]);
    eq([409, 'wrong_channel'], [$r['status'], $r['json']['code'] ?? null]);
    eq(null, kp_get('kol_maroko_v2_mount'));
    $man = kp_manifest(kp_bundle('W'), 4) + ['seq' => 5, 'expires' => time() + 3600];
    $r = kp_publish($man);
    eq([409, 'wrong_channel'], [$r['status'], $r['json']['code'] ?? null]);
    eq(0, (int)kp_get('kol_maroko_v2_live'));
});

test('M7 H23: הפניה (302) מ-pull.php לדומיין אחר — לא נעקבת; "בדוק חיבור" מראה אותה', function () {
    kpp_reset(['redirect' => 'http://127.0.0.1:9/evil', 'items' => [kpp_item('mount', ['mount' => 'bar'], 1)]]);
    $r = kpp_run();
    eq([false, 'redirect'], [$r['ok'], $r['code']], json_encode($r, JSON_UNESCAPED_UNICODE));
    ok(str_contains($r['text'], 'לא נעקבת'), $r['text']);
    eq(null, kp_get('kol_maroko_v2_mount'));
    $c = json_decode(kp_http('GET', '/__pull_check')['body'], true);
    eq([false, 302], [$c['ok'], $c['status']]);
    ok(str_contains($c['error'], 'evil'), $c['error']);
    // אסימון שגוי — "בדוק חיבור" אומר 401
    kpp_cfg(['token' => 'tok_other_other_other_other']);
    $c = json_decode(kp_http('GET', '/__pull_check')['body'], true);
    eq([false, 401], [$c['ok'], $c['status']]);
    ok(str_contains($c['error'], 'אסימון'), $c['error']);
});

test('M7 H27: ריצה שנקטעה באמצע גוש גדול — הבאה ממשיכה מאותו בייט (Range), והמצביע מוחלף רק בסוף', function () {
    $blobs = [];
    $big = random_bytes(200000);
    $files = ['index.html' => '<p>VERSION-BIG</p>', 'media/big.mp3' => $big];
    $item = kpp_manifest($files, 8, 30, $blobs);
    kpp_reset(['items' => [$item], 'blobs' => $blobs], ['kol_maroko_pull_budget' => 0, 'kol_maroko_pull_chunk' => 65536]);
    $sha = hash('sha256', $big);
    $ranges = [];
    for ($i = 0; $i < 10; $i++) {
        $r = kpp_run();
        if ($r['applied'] ?? 0) break;
        ok($r['pending'], 'ממשיך בריצה הבאה: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
        eq(0, (int)kp_get('kol_maroko_v2_live'), "ריצה $i — המצביע לא הוחלף");
    }
    eq(1, $r['applied'] ?? 0, 'הוחל בסוף');
    eq(8, (int)kp_get('kol_maroko_v2_live'));
    foreach (kpp_log() as $l) if ($l['query'] === "blob=$sha") $ranges[] = $l['range'];
    eq(['bytes=0-65535', 'bytes=65536-131071', 'bytes=131072-196607', 'bytes=196608-262143'], $ranges, 'כל ריצה — מהבייט שאחרי');
    ok(is_file(kp_v2() . "/blobs/$sha.mp3") && hash_file('sha256', kp_v2() . "/blobs/$sha.mp3") === $sha, 'הגוש שלם ותואם');
    ok(!is_file(kp_v2() . "/parts/$sha.pull"), 'החלק נמחק');
    // שרת שמתעלם מ-Range באמצע — מתחילים מהתחלה, והגוש עדיין תקין
    $big2 = random_bytes(150000);
    $blobs = [];
    kpp_reset(['items' => [kpp_manifest(['index.html' => '<p>x</p>', 'b.mp3' => $big2], 9, 31, $blobs)], 'blobs' => $blobs], ['kol_maroko_pull_budget' => 0, 'kol_maroko_pull_chunk' => 65536]);
    kpp_run();
    $c = kpp_cfg_get();
    $c['ignore_range'] = true;
    kpp_cfg($c);
    for ($i = 0; $i < 5 && !(($r = kpp_run())['applied'] ?? 0); $i++);
    eq(9, (int)kp_get('kol_maroko_v2_live'), 'הוחל גם כשהשרת התעלם מה-Range');
});

test('M7 קצב: 429 מהכלי באמצע ההורדה — לא נכשל; ממשיך (משיכה נוספת בעוד דקה), ובסוף מוחל', function () {
    $blobs = [];
    $files = ['index.html' => '<p>R</p>', 'a.css' => 'p{}', 'b.js' => '1;', 'c.txt' => 'c'];
    kpp_reset(['items' => [kpp_manifest($files, 4, 50, $blobs)], 'blobs' => $blobs, 'rate_after' => 1]);
    $r = kpp_run();
    eq([0, 0, true], [$r['applied'], $r['failed'], $r['pending']], json_encode($r, JSON_UNESCAPED_UNICODE));
    eq([], kpp_last_report(), 'לא דווח כשל');
    ok(isset(((array)kp_get('test_cron'))['kol_maroko_pull_now']), 'משיכה נוספת תוזמנה');
    eq(0, (int)kp_get('kol_maroko_pull_cursor'), 'הסמן לא זז');
    $c = kpp_cfg_get();
    unset($c['rate_after']);
    kpp_cfg($c);
    eq(1, kpp_run()['applied']);
    eq(4, (int)kp_get('kol_maroko_v2_live'));
});

test('M7 H28: שתי משיכות במקביל — אחת רצה, השנייה יוצאת מיד; אין החלה כפולה', function () {
    kpp_reset(['items' => [kpp_item('mount', ['mount' => 'framed'], 40)], 'slow' => 700000]);
    // נעילה שמוחזקת (ריצה אחרת באמצע) — יוצאת מיד
    @mkdir(kp_v2(), 0775, true);
    $fh = fopen(kp_v2() . '/pull.lock', 'c');
    flock($fh, LOCK_EX);
    $t = microtime(true);
    $r = kpp_run();
    flock($fh, LOCK_UN);
    fclose($fh);
    eq([false, 'busy'], [$r['ok'], $r['code']]);
    ok(microtime(true) - $t < 0.5, 'יצאה מיד');
    eq([], kpp_log(), 'לא פנתה לכלי');
    // שתיים באמת במקביל (cron + "עדכן עכשיו")
    $mh = curl_multi_init();
    $hs = [];
    // שני תהליכי שרת נפרדים על אותו אתר (php -S עלול לתת את שני החיבורים לאותו worker — אז זה לא באמת במקביל)
    $GLOBALS['KPP_TWIN'] ??= (function () { [$proc, $base] = serve(__DIR__ . '/wp.php', ['KOL_TEST_WP' => kp_site()['dir']]); $GLOBALS['procs'][] = $proc; return $base; })();
    foreach (['cron' => kp_site()['base'], 'manual' => $GLOBALS['KPP_TWIN']] as $why => $base) {
        $h = curl_init($base . '/__pull?why=' . $why);
        curl_setopt($h, CURLOPT_RETURNTRANSFER, true);
        curl_multi_add_handle($mh, $h);
        $hs[] = $h;
    }
    do { curl_multi_exec($mh, $run); curl_multi_select($mh, 0.1); } while ($run);
    $out = array_map(fn($h) => json_decode(curl_multi_getcontent($h), true), $hs);
    curl_multi_close($mh);
    eq(['busy'], array_values(array_filter(array_map(fn($o) => $o['code'] ?? '', $out))), 'אחת busy: ' . json_encode($out, JSON_UNESCAPED_UNICODE));
    eq(1, array_sum(array_map(fn($o) => $o['applied'] ?? 0, $out)), 'החלה אחת');
    eq(1, count(array_filter((array)kp_get('kol_maroko_v2_log'), fn($l) => $l['op'] === 'pull_mount_applied')), 'ביומן התוסף — פעם אחת');
});

test('M7 הדפיקה: GET ?kol-maroko-wake=1 מתזמנת משיכה — לכל היותר אחת לדקה; בלי שורת חיבור — כלום; העמוד מוגש כרגיל', function () {
    kp_reset();
    $r = kp_http('GET', '/__site/page?kol-maroko-wake=1');
    eq(200, $r['status']);
    ok(!isset(((array)kp_get('test_cron'))['kol_maroko_pull_now']), 'בלי שורת חיבור — לא תוזמן');
    kpp_reset();
    $r = kp_http('GET', '/__site/page?kol-maroko-wake=1');
    ok(str_contains($r['body'], 'WP-SITE-PAGE'), 'העמוד מוגש כרגיל');
    ok(isset(((array)kp_get('test_cron'))['kol_maroko_pull_now']), 'תוזמנה משיכה');
    kp_opt('test_cron', []);
    kp_http('GET', '/?kol-maroko-wake=1');
    eq([], (array)kp_get('test_cron'), 'פעם שנייה בתוך דקה — לא');
    eq([], kpp_log(), 'הדפיקה עצמה אינה פונה לכלי');
});

test('M7 הכתיבה בתוסף: רק בתוך uploads/kol-maroko/v2, בשמות של sha256 — גם כשהשרת מגיש sha מוזר', function () {
    $blobs = [];
    $item = kpp_manifest(['index.html' => '<p>ok</p>'], 3, 1, $blobs);
    $env = json_decode(base64_decode($item['body']), true);
    $man = json_decode($env['manifest'], true);
    $man['files']['index.html']['sha256'] = '../../../../evil';
    $bytes = json_encode($man, JSON_UNESCAPED_SLASHES);
    $k = kp_site();
    $evil = kpp_item('manifest', ['manifest' => $bytes, 'manifest_sig' => base64_encode(sodium_crypto_sign_detached($bytes, $k['secret'])), 'kid' => $k['kid']], 1, ['expires' => $env['expires']]);
    kpp_reset(['items' => [$evil], 'blobs' => $blobs]);
    $before = kp_tree(kp_site()['dir']);
    kpp_run();
    eq([1 => ['failed', 'bad_request']], kpp_last_report(), 'sha לא תקין — נדחה לפני כל הורדה');
    $new = array_diff(kp_tree(kp_site()['dir']), $before);
    eq([], array_values(array_filter($new, fn($f) => !str_starts_with($f, 'uploads/kol-maroko/') && !str_starts_with($f, 'options.json'))), 'שום קובץ מחוץ ל-uploads/kol-maroko');
});

function kp_tree(string $root): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) $out[] = substr((string)$f, strlen($root) + 1);
    return $out;
}

test('M7 מטמון ה-CDN (Cloudflare של Bluehost): המעטפת וכל מה שאינו לפי hash — CDN-Cache-Control: no-store; Cache-Control עם ETag', function () {
    kpp_reset();
    kp_live(kp_bundle('C'), 3);
    foreach (['full', 'framed', 'bar'] as $m) {
        kp_opt('kol_maroko_v2_mount', $m);
        $r = kp_http('GET', '/lexicon/', ['Sec-Fetch-Dest: document']);
        eq([200, 'no-store', 'no-store'], [$r['status'], kp_h($r, 'cdn-cache-control'), kp_h($r, 'cloudflare-cdn-cache-control')], "המסמך ב-$m");
        ok(!str_contains((string)kp_h($r, 'cache-control'), 'immutable') && str_contains((string)kp_h($r, 'cache-control'), 'max-age=0'), "$m: " . kp_h($r, 'cache-control'));
    }
});
