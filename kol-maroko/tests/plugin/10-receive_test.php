<?php
/**
 * M2 §2 "הפרוטוקול" ו"אחסון" — מול קובץ התוסף האמיתי (tests/plugin/wp.php). כל דחייה: הקוד, והגרסה שבאוויר לא נגעה.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

/** המצב שבאוויר: [v2_live, v2_last, הבייטים של live.json]. */
function kp_state(): array
{
    return [kp_get('kol_maroko_v2_live'), kp_get('kol_maroko_v2_last'), @file_get_contents(kp_v2() . '/live.json')];
}

test('M2 ping: protocol 2, plugin, הגרסה האחרונה ושבאוויר של v2, המפסק — ושדות 1.x נשמרים', function () {
    kp_site();
    kp_reset(['kol_maroko_last' => 7, 'kol_maroko_live' => 6, 'kol_maroko_enabled' => true, 'kol_maroko_page_id' => 12]);
    $r = kp_send('ping');
    eq(200, $r['status'], $r['body']);
    $j = $r['json'];
    eq([true, 2, 7, 6, true, true, 0, 0, false], [$j['ok'], $j['protocol'], $j['version'], $j['live'], $j['enabled'], $j['page_set'], $j['v2_version'], $j['v2_live'], $j['v2_on']]);
    ok(is_string($j['plugin']) && $j['plugin'] !== '', 'plugin');
});

test('M2 חתימה מזויפת: מפתח אחר, גוף ששונה אחרי החתימה, kid לא מוכר — לכל פעולה של 2.0', function () {
    kp_site();
    kp_reset();
    $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    foreach (['asset_has' => ['sha256' => []], 'asset_put' => ['sha256' => str_repeat('a', 64), 'size' => 1, 'part' => 0, 'parts' => 1, 'data' => 'eA=='],
        'manifest' => ['manifest' => '{}', 'manifest_sig' => '', 'kid' => 'k-m2'], 'activate' => ['version' => 1]] as $a => $p) {
        eq('bad_signature', kp_send($a, $p, ['secret' => $other])['json']['code'] ?? null, "$a — מפתח אחר");
        $s = kp_signed($a, $p);
        $tampered = str_replace('"page":"lexicon"', '"page":"lexicon","x":1', $s['body']);
        eq('bad_signature', json_decode(kp_http('POST', "/wp-json/kol-maroko/v1/$a", $s['headers'], $tampered)['body'], true)['code'] ?? null, "$a — גוף ששונה");
        eq('bad_signature', kp_send($a, $p, ['kid' => 'nope'])['json']['code'] ?? null, "$a — kid לא מוכר");
        eq('wrong_site', kp_send($a, ['site' => 'evil.example'] + $p)['json']['code'] ?? null, "$a — site אחר");
        eq('stale', kp_send($a, ['time' => time() - 3600] + $p)['json']['code'] ?? null, "$a — זמן ישן");
    }
    $s = kp_signed('asset_has', ['sha256' => []]);
    eq(200, kp_http('POST', '/wp-json/kol-maroko/v1/asset_has', $s['headers'], $s['body'])['status']);
    eq('replay', json_decode(kp_http('POST', '/wp-json/kol-maroko/v1/asset_has', $s['headers'], $s['body'])['body'], true)['code'], 'אותה בקשה פעמיים');
    eq('bad_request', kp_send('asset_has', ['action' => 'manifest'])['json']['code'] ?? null, 'פעולה בגוף שאינה הנתיב');
});

test('M2 asset_put: חלקים בסדר; גוש שלם נשמר — טקסט בלי סיומת, מדיה עם; פגום ← bad_hash והחלק נמחק; קיים ← have', function () {
    kp_site();
    kp_reset();
    $html = str_repeat('<p>שלום</p>', 3000);
    $r = kp_put($html, 'text/html', 7000);
    eq(true, $r['json']['done'] ?? null, $r['body']);
    $sha = hash('sha256', $html);
    ok(is_file(kp_v2() . "/blobs/$sha"), 'טקסט — בשם ה-hash, בלי סיומת');
    $mp3 = 'ID3' . random_bytes(5000);
    kp_put($mp3, 'audio/mpeg', 2000);
    ok(is_file(kp_v2() . '/blobs/' . hash('sha256', $mp3) . '.mp3'), 'מדיה — <hash>.mp3');
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
    kp_put($svg, 'image/svg+xml');
    ok(is_file(kp_v2() . '/blobs/' . hash('sha256', $svg)), 'SVG הוא טקסט — בלי סיומת');
    $odd = 'x' . random_bytes(10);
    kp_put($odd, 'application/x-php');
    ok(is_file(kp_v2() . '/blobs/' . hash('sha256', $odd)), 'סוג לא מוכר — בלי סיומת');
    eq(true, kp_put($html, 'text/html')['json']['have'] ?? null, 'גוש קיים ← have בלי כתיבה');

    // פגום: ה-hash לא תואם
    $bad = kp_put('hello world', 'text/plain', 0, str_repeat('b', 64));
    eq([422, 'bad_hash'], [$bad['status'], $bad['json']['code']]);
    ok(!glob(kp_v2() . '/blobs/' . str_repeat('b', 64) . '*') && !glob(kp_v2() . '/parts/' . str_repeat('b', 64) . '*.part'), 'לא נשמר, והחלק נמחק');
    // גודל שהוכרז קטן מהבייטים שהגיעו
    $r = kp_send('asset_put', ['sha256' => hash('sha256', 'abcdef'), 'size' => 3, 'part' => 0, 'parts' => 1, 'data' => base64_encode('abcdef')]);
    eq('bad_hash', $r['json']['code'] ?? null, 'יותר בייטים מהגודל שהוכרז');
    // חלקים שלא בסדר
    $big = random_bytes(3000);
    kp_send('asset_put', ['sha256' => hash('sha256', $big), 'size' => 3000, 'part' => 0, 'parts' => 3, 'data' => base64_encode(substr($big, 0, 1000))]);
    $r = kp_send('asset_put', ['sha256' => hash('sha256', $big), 'size' => 3000, 'part' => 2, 'parts' => 3, 'data' => base64_encode(substr($big, 2000))]);
    eq([409, 'bad_part', 1], [$r['status'], $r['json']['code'], $r['json']['expect']]);
    // קלט עוין בשדות
    foreach ([['sha256' => '../../wp-config', 'size' => 1, 'part' => 0, 'parts' => 1, 'data' => 'eA=='], ['sha256' => str_repeat('A', 64), 'size' => 1, 'part' => 0, 'parts' => 1, 'data' => 'eA=='],
        ['sha256' => str_repeat('a', 64), 'size' => '1', 'part' => 0, 'parts' => 1, 'data' => 'eA=='], ['sha256' => str_repeat('a', 64), 'size' => 1, 'part' => 1, 'parts' => 1, 'data' => 'eA=='],
        ['sha256' => str_repeat('a', 64), 'size' => 1, 'part' => 0, 'parts' => 1, 'data' => '%%%']] as $i => $p) {
        eq('bad_request', kp_send('asset_put', $p)['json']['code'] ?? null, "קלט עוין $i");
    }
    ok(!glob(kp_site()['dir'] . '/wp-config*') && !glob(kp_v2() . '/blobs/*A*'), 'שום דבר לא נכתב מחוץ לתיקייה');
});

test('M2 asset_has: רק מה שיש; עד 10,000; ערכים עוינים מתעלמים', function () {
    kp_site();
    kp_reset();
    kp_put('one', 'text/plain');
    kp_put('ID3two', 'audio/mpeg');
    $list = [hash('sha256', 'one'), hash('sha256', 'ID3two'), hash('sha256', 'three'), '../x', 5, ['a']];
    eq([hash('sha256', 'one'), hash('sha256', 'ID3two')], kp_send('asset_has', ['sha256' => $list])['json']['have']);
    $many = array_map(fn($i) => hash('sha256', "n$i"), range(1, 10000));
    $many[17] = hash('sha256', 'one');
    eq([hash('sha256', 'one')], kp_send('asset_has', ['sha256' => $many])['json']['have'], '10,000 בבקשה');
    $many[] = hash('sha256', 'x');
    eq('bad_request', kp_send('asset_has', ['sha256' => $many])['json']['code'] ?? null, '10,001 — נדחה');
});

test('M2 manifest: תקין ← מצביע חדש; כל דחייה בקוד שלה, והגרסה שבאוויר לא נגעה', function () {
    kp_site();
    kp_reset(['kol_maroko_last' => 4]);
    $files = kp_bundle('A');
    $r = kp_publish(kp_manifest($files, 5));
    eq([200, 5], [$r['status'], $r['json']['version'] ?? null], $r['body']);
    $live = kp_state();
    eq([5, 5], [$live[0], $live[1]]);
    ok(is_file(kp_v2() . '/manifest-5.json') && $live[2] === file_get_contents(kp_v2() . '/manifest-5.json'), 'live.json = manifest-5.json');

    $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    $m = fn(array $over = [], array $f = null) => kp_manifest($f ?? $files, 6, $over, false);
    $ghost = $files + ['ghost.js' => 'never sent'];
    $cases = [
        'חתימה במפתח אחר' => [kp_publish($m(), ['secret' => $other]), 'bad_signature'],
        'מניפסט ששונה אחרי החתימה' => [kp_publish($m(), ['bytes' => json_encode($m(['entry' => 'a.css']))]), 'bad_signature'],
        'חתימה ריקה' => [kp_publish($m(), ['sig' => '']), 'bad_signature'],
        'site אחר' => [kp_publish($m(['site' => 'evil.example'])), 'wrong_site'],
        'page אחר' => [kp_publish($m(['page' => 'about'])), 'wrong_page'],
        'זמן ישן' => [kp_publish($m(['time' => time() - 3600])), 'stale'],
        'גרסה יורדת' => [kp_publish(kp_manifest($files, 3, [], false)), 'old_version'],
        'אותה גרסה' => [kp_publish(kp_manifest($files, 5, [], false)), 'old_version'],
        'קובץ שלא נשלח' => [kp_publish(kp_manifest($ghost, 6, [], false)), 'asset_missing'],
        'entry שאינו בחבילה' => [kp_publish($m(['entry' => 'nope.html'])), 'bad_request'],
        'files כרשימה' => [kp_publish($m(['files' => [['sha256' => str_repeat('a', 64), 'size' => 1]]])), 'bad_request'],
        'גרסה לא מספר' => [kp_publish($m(['version' => '6'])), 'bad_request'],
    ];
    foreach ($cases as $name => [$res, $code]) {
        eq($code, $res['json']['code'] ?? null, $name . ' · ' . $res['body']);
        eq($live, kp_state(), "$name — הגרסה שבאוויר לא נגעה");
    }
    eq(5, kp_publish(kp_manifest($files, 3, [], false))['json']['version'] ?? null, 'old_version מחזיר את הגרסה האחרונה');

    // גודל או hash של קובץ שלא תואמים את הגוש
    $bad = $m();
    $bad['files']['a.css']['size']++;
    eq('bad_hash', kp_publish($bad)['json']['code'] ?? null, 'גודל לא תואם');
    $bad = $m();
    $bad['files']['a.css']['sha256'] = hash('sha256', $files['index.html']);
    eq('bad_hash', kp_publish($bad)['json']['code'] ?? null, 'sha של קובץ אחר (גודל אחר)');
    // גוש ששונה על הדיסק אחרי שהתקבל
    $sha = hash('sha256', 'tamper-me!');
    kp_put('tamper-me!', 'text/plain');
    file_put_contents(kp_v2() . "/blobs/$sha", 'TAMPERED!!');
    eq('bad_hash', kp_publish(kp_manifest($files + ['t.txt' => 'tamper-me!'], 6, [], false))['json']['code'] ?? null, 'גוש ששונה על הדיסק');
    eq($live, kp_state(), 'אחרי הכול — הגרסה שבאוויר לא נגעה');

    // nonce חוזר: אותו מניפסט פעמיים (מעטפת חדשה), אחרי שהראשון עבר
    $ok = kp_manifest($files, 6, [], false);
    eq(200, kp_publish($ok)['status']);
    $ok['version'] = 7;
    eq('replay', kp_publish($ok)['json']['code'] ?? null, 'nonce של מניפסט שכבר התקבל');
    eq(6, kp_get('kol_maroko_v2_live'));
});

test('M2 manifest: נתיבים עוינים ← bad_path; שום נתיב לא נכתב לדיסק', function () {
    kp_site();
    kp_reset();
    $files = kp_bundle('A');
    $paths = ['../../wp-config.php', '/etc/passwd', 'a/../b.html', './a.html', 'a//b.html', 'a\\b.html', "a\0.html", "a\n.html", "a\x7f.html", 'a/', 'a/./b', '..',
        str_repeat('a', 1025), "a\u{2028}.html", 'SURROGATE'];
    foreach ($paths as $i => $p) {
        $man = kp_manifest($files, 10 + $i, [], $i === 0);
        $man['files'][$p] = $man['files']['a.css'];
        $bytes = json_encode($man, JSON_UNESCAPED_SLASHES);
        if ($p === 'SURROGATE') $bytes = str_replace('"SURROGATE"', '"a\\ud800.html"', $bytes);     // UTF-16 שבור בתוך ה-JSON
        $sig = base64_encode(sodium_crypto_sign_detached($bytes, kp_site()['secret']));
        $r = kp_send('manifest', ['manifest' => $bytes, 'manifest_sig' => $sig, 'kid' => 'k-m2']);
        ok(in_array($r['json']['code'] ?? null, ['bad_path', 'bad_request'], true), 'נתיב ' . json_encode($p) . ' · ' . $r['body']);
    }
    eq(null, kp_get('kol_maroko_v2_live'), 'שום גרסה לא עלתה');
    ok(!is_file(kp_v2() . '/live.json'), 'אין live.json');
    $ok = kp_manifest($files + [str_repeat('a', 1019) . '.html' => 'long'], 40);
    eq(200, kp_publish($ok)['status'], 'נתיב של 1,024 — מתקבל');
});

test('M2 מכסות (H8): קובץ, חבילה, מספר קבצים, קצב — quota (413) עם המכסה והערך; דבר לא נכתב', function () {
    kp_site();
    kp_reset(['kol_maroko_v2_quota' => ['file_mb' => 1, 'total_mb' => 2, 'files' => 6, 'keep' => 10, 'rate' => 600]]);
    $files = kp_bundle('A');
    eq(200, kp_publish(kp_manifest($files, 1))['status']);
    $live = kp_state();

    $big = random_bytes(1048577);
    $r = kp_send('asset_put', ['sha256' => hash('sha256', $big), 'size' => strlen($big), 'part' => 0, 'parts' => 2, 'data' => base64_encode('x')]);
    eq([413, 'quota', 'file_mb', 1, 1048577], [$r['status'], $r['json']['code'], $r['json']['quota'], $r['json']['limit'], $r['json']['value']]);
    ok(!glob(kp_v2() . '/parts/' . hash('sha256', $big) . '*.part'), 'גוש שחורג — לא נכתב אפילו חלק');

    $f = $files + ['b1.bin' => random_bytes(900000), 'b2.bin' => random_bytes(900000)];
    unset($f['x/index.html'], $f['מילים/דף.html']);
    $f['b3.bin'] = random_bytes(900000);
    $r = kp_publish(kp_manifest($f, 2));
    eq(['quota', 'total_mb', 2], [$r['json']['code'] ?? null, $r['json']['quota'] ?? null, $r['json']['limit'] ?? null], $r['body']);
    $r = kp_publish(kp_manifest($files + ['c1' => '1', 'c2' => '2'], 2));
    eq(['quota', 'files', 6, 7], [$r['json']['code'] ?? null, $r['json']['quota'] ?? null, $r['json']['limit'] ?? null, $r['json']['value'] ?? null]);
    $m = kp_manifest($files, 2, [], false);
    $m['files']['a.css']['size'] = 1048577;
    eq('quota', kp_publish($m)['json']['code'] ?? null, 'קובץ במניפסט מעל המכסה');
    eq($live, kp_state(), 'הגרסה שבאוויר לא נגעה');
    ok(!is_file(kp_v2() . '/manifest-2.json'), 'שום מניפסט לא נכתב');

    kp_opt('kol_maroko_v2_quota', ['rate' => 5]);
    @unlink(kp_v2() . '/rate.json');
    $codes = [];
    for ($i = 0; $i < 7; $i++) $codes[] = kp_send('asset_has', ['sha256' => []])['json']['code'] ?? 'ok';
    eq(['ok', 'ok', 'ok', 'ok', 'ok', 'quota', 'quota'], $codes, 'קצב: 5 בדקה');
    eq('ok', kp_send('ping')['json']['ok'] ? 'ok' : 'x', 'ping אינו נספר');
});

test('M2 מונה משותף: גרסה v2 חייבת לעבור גם את האחרונה של 1.x; ופרסום 1.x אחרי v2 — גם', function () {
    kp_site();
    kp_reset(['kol_maroko_last' => 20]);
    $r = kp_publish(kp_manifest(kp_bundle(), 20));
    eq(['old_version', 20], [$r['json']['code'] ?? null, $r['json']['version'] ?? null]);
    eq(200, kp_publish(kp_manifest(kp_bundle(), 21, [], false))['status']);
    eq(21, kp_get('kol_maroko_v2_last'));
    eq(20, kp_get('kol_maroko_last'), 'המונה של 1.x לא נגע');
});

test('M2 activate: חזרה לגרסה שמורה — מיידית, בלי שליחה; גרסה שאינה שמורה ← not_found', function () {
    kp_site();
    kp_reset();
    kp_publish(kp_manifest(kp_bundle('A'), 1));
    kp_publish(kp_manifest(kp_bundle('B'), 2));
    eq(2, kp_get('kol_maroko_v2_live'));
    $r = kp_send('activate', ['version' => 1]);
    eq([200, 1], [$r['status'], $r['json']['live'] ?? null], $r['body']);
    eq([1, 2], [kp_get('kol_maroko_v2_live'), kp_get('kol_maroko_v2_last')], 'live זז, last לא');
    eq(file_get_contents(kp_v2() . '/manifest-1.json'), file_get_contents(kp_v2() . '/live.json'));
    eq('not_found', kp_send('activate', ['version' => 9])['json']['code'] ?? null);
    eq('bad_request', kp_send('activate', ['version' => '../2'])['json']['code'] ?? null);
    eq(1, kp_get('kol_maroko_v2_live'), 'not_found — לא זז');
    // הגרסה הבאה עדיין חייבת לעבור את 2 (last), לא את 1 (live)
    eq('old_version', kp_publish(kp_manifest(kp_bundle('C'), 2, [], false))['json']['code'] ?? null);
});

test('M2 ניקוי: מעבר ל-keep גרסאות — נמחקות (לא זו שבאוויר); גוש שאף מניפסט שמור אינו מפנה אליו — נמחק; חלק ישן — נמחק', function () {
    kp_site();
    kp_reset(['kol_maroko_v2_quota' => ['keep' => 3]]);
    kp_publish(kp_manifest(['index.html' => 'v1-only', 'shared.css' => 'S'], 1));
    kp_send('activate', ['version' => 1]);
    for ($n = 2; $n <= 6; $n++) kp_publish(kp_manifest(['index.html' => "v$n-only", 'shared.css' => 'S'], $n));
    eq([6, 5, 4], array_slice(array_map(fn($f) => (int)preg_replace('/\D/', '', basename($f)), array_reverse(glob(kp_v2() . '/manifest-*.json'))), 0, 3));
    ok(!is_file(kp_v2() . '/manifest-2.json') && !is_file(kp_v2() . '/manifest-1.json'), 'מעבר ל-3 נמחקו');
    ok(!is_file(kp_v2() . '/blobs/' . hash('sha256', 'v2-only')), 'גוש של גרסה שנמחקה — נמחק');
    ok(is_file(kp_v2() . '/blobs/' . hash('sha256', 'v6-only')) && is_file(kp_v2() . '/blobs/' . hash('sha256', 'S')), 'גושים בשימוש — נשארו');

    // הגרסה שבאוויר נשמרת גם כשהיא ישנה
    kp_send('activate', ['version' => 4]);
    kp_publish(kp_manifest(['index.html' => 'v7-only', 'shared.css' => 'S'], 7));
    kp_send('activate', ['version' => 5]);
    kp_publish(kp_manifest(['index.html' => 'v8-only', 'shared.css' => 'S'], 8));
    kp_publish(kp_manifest(['index.html' => 'v9-only', 'shared.css' => 'S'], 9));
    eq(9, kp_get('kol_maroko_v2_live'));
    kp_send('activate', ['version' => 7]);
    kp_publish(kp_manifest(['index.html' => 'v10-only', 'shared.css' => 'S'], 10));
    ok(is_file(kp_v2() . '/manifest-8.json') && !is_file(kp_v2() . '/manifest-7.json'), 'ניקוי רגיל');

    // חלק שלא נשלם: עד 24 שעות נשאר, אחרי — נמחק בניקוי הבא
    $sha = hash('sha256', 'abcdef');
    kp_send('asset_put', ['sha256' => $sha, 'size' => 6, 'part' => 0, 'parts' => 2, 'data' => base64_encode('abc')]);
    ok(is_file(kp_v2() . "/parts/$sha.part"), 'חלק בדרך');
    kp_publish(kp_manifest(['index.html' => 'v11-only'], 11));
    ok(is_file(kp_v2() . "/parts/$sha.part"), 'חלק צעיר — נשאר');
    touch(kp_v2() . "/parts/$sha.part", time() - 90000);
    touch(kp_v2() . "/parts/$sha.n", time() - 90000);
    $r = kp_publish(kp_manifest(['index.html' => 'v12-only'], 12));
    eq(200, $r['status'], $r['body']);
    clearstatcache();
    ok(!is_file(kp_v2() . "/parts/$sha.part"), 'חלק מלפני 25 שעות — נמחק');
    eq(array_values(array_map('intval', array_keys(kp_get('kol_maroko_v2_versions')))), [10, 11, 12], 'רשימת הגרסות במצב');
});

test('M2 אחסון נעול: .htaccess (Require all denied, php engine off), index.html ריק, web.config — בכל תיקייה, ונכתבים מחדש אם חסרים', function () {
    kp_site();
    kp_reset();
    kp_put('x', 'text/plain');
    foreach (['', '/blobs', '/parts'] as $d) {
        $ht = (string)@file_get_contents(kp_v2() . "$d/.htaccess");
        ok(str_contains($ht, 'Require all denied') && str_contains($ht, 'php_flag engine off') && str_contains($ht, 'RemoveHandler .php'), ".htaccess ב-v2$d");
        ok(is_file(kp_v2() . "$d/index.html") && filesize(kp_v2() . "$d/index.html") === 0, "index.html ב-v2$d");
        ok(str_contains((string)@file_get_contents(kp_v2() . "$d/web.config"), 'accessType="Deny"'), "web.config ב-v2$d");
    }
    unlink(kp_v2() . '/blobs/.htaccess');
    file_put_contents(kp_v2() . '/.htaccess', 'Require all granted');
    kp_send('asset_has', ['sha256' => []]);
    ok(str_contains((string)@file_get_contents(kp_v2() . '/blobs/.htaccess'), 'Require all denied'), 'נכתב מחדש כשנמחק');
    ok(str_contains((string)@file_get_contents(kp_v2() . '/.htaccess'), 'Require all denied'), 'נכתב מחדש כששונה');
    ok(!str_contains((string)file_get_contents(kp_v2() . '/blobs/.htaccess'), 'granted'), 'בלי הגשה ישירה כברירת מחדל');
});
