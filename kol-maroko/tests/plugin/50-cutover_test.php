<?php
/**
 * M2b — החלק של M3 בתוסף (slices/M3-cutover.md §2 "כתובת הבדיקה", "ההדלקה", "המונה"; §3 שורות התוסף).
 * ההגשה, כתובת הבדיקה, המונה וה-ping — דרך tests/fake-wp.php (האתר המדומה של הכלי, עם התוסף האמיתי).
 * ההדלקה, הכיבוי, האסימון והיומן בעמוד התוסף — דרך tests/plugin/wp.php (שבו תחליפי מסך הניהול של וורדפרס).
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

/** מעביר את עזרי kp_* אל fake-wp (המפתח של הכלי), מריץ, ומחזיר. */
function kp_on_fakewp(callable $fn): void
{
    kp_site();
    $save = $GLOBALS['KP'];
    $k = pub_key();
    $secret = base64_decode($k['secret']);
    $GLOBALS['KP'] = ['dir' => $GLOBALS['WP_DIR'], 'kid' => $k['kid'], 'secret' => $secret, 'public' => sodium_crypto_sign_publickey_from_secretkey($secret),
        'base' => $GLOBALS['WP'], 'host' => (string)parse_url($GLOBALS['WP'], PHP_URL_HOST)];
    try { kp_reset(); $fn(); } finally { $GLOBALS['KP'] = $save; }
}

function kp_is_v2(array $r): bool { return kp_h($r, 'content-security-policy') === KP_CSP; }

/** ה-Set-Cookie של כתובת הבדיקה, אם יש. */
function kp_cookie(array $r): ?string
{
    foreach ($r['headers']['set-cookie'] ?? [] as $c) if (str_starts_with($c, 'kol_preview=')) return $c;
    return null;
}

test('M2b כתובת הבדיקה (fake-wp): עם האסימון — v2 בכותרות של M2 + noindex + private; העוגייה לתחום בלבד; בלי / שגוי — הישן', function () {
    kp_on_fakewp(function () {
        $b = kp_bundle('P');
        kp_live($b, 1, false);
        $tok = bin2hex(random_bytes(16));
        kp_opt('kol_maroko_v2_preview_hash', hash('sha256', $tok));
        ok(!str_contains((string)file_get_contents(kp_site()['dir'] . '/options.json'), $tok), 'האסימון עצמו אינו נשמר — רק ה-hash');

        $r = kp_http('GET', '/lexicon/?kol_preview=' . $tok);
        eq([200, $b['index.html']], [$r['status'], $r['body']], 'עם האסימון ← העמוד החדש');
        $noplugin = $r;
        $noplugin['headers']['set-cookie'] = array_values(array_filter($r['headers']['set-cookie'] ?? [], fn($c) => !str_starts_with($c, 'kol_preview=')));
        if (!$noplugin['headers']['set-cookie']) unset($noplugin['headers']['set-cookie']);
        eq([], kp_iso($noplugin), 'אותן כותרות של M2 (I1–I7); עוגייה אחת בלבד — של התוסף');
        eq('noindex, nofollow', kp_h($r, 'x-robots-tag'));
        eq('private, no-store', kp_h($r, 'cache-control'), 'לעולם לא במטמון משותף');
        eq('no-cache', kp_h($r, 'x-litespeed-cache-control'));
        $c = kp_cookie($r);
        eq("kol_preview=$tok; Path=/lexicon/; Max-Age=86400; HttpOnly; Secure; SameSite=None", $c, 'העוגייה: של התוסף, לתחום בלבד, HttpOnly');
        eq(1, count($r['headers']['set-cookie'] ?? []), 'רק העוגייה הזו — העוגייה של "תוסף אחר" הוסרה');

        $r = kp_http('GET', '/lexicon/a.css', ["Cookie: kol_preview=$tok"]);
        eq([200, $b['a.css'], 'private, no-store'], [$r['status'], $r['body'], kp_h($r, 'cache-control')], 'עם העוגייה — כל נתיב בתחום');
        ok(!isset($r['headers']['set-cookie']), 'עם העוגייה — לא קובעים אותה שוב');
        $r = kp_http('GET', '/lexicon/media/w.mp3?kol_preview=' . $tok, ['Range: bytes=0-3']);
        eq(206, $r['status'], 'Range גם בכתובת הבדיקה');

        foreach (['' => 'בלי אסימון', '?kol_preview=' . str_repeat('0', 32) => 'אסימון שגוי', '?kol_preview=' . strtoupper($tok) => 'אותיות גדולות',
            '?kol_preview=' . $tok . '%0d%0aSet-Cookie:%20x=1' => 'הזרקת שורת כותרת', '?kol_preview[]=' . $tok => 'מערך', '?kol_preview=' . substr($tok, 0, 31) => 'קצר'] as $q => $name) {
            $r = kp_http('GET', '/lexicon/' . $q);
            ok(!kp_is_v2($r), "$name ← העמוד הישן");
            ok(!isset($r['headers']['x-robots-tag']) && kp_cookie($r) === null, "$name — בלי עוגייה");
        }
        ok(!kp_is_v2(kp_http('GET', '/lexicon/', ['Cookie: kol_preview=' . str_repeat('a', 32)])), 'עוגייה שגויה ← הישן');
        ok(!kp_is_v2(kp_http('GET', '/other/?kol_preview=' . $tok)), 'מחוץ לתחום — לא נתפס');
        // אסימון חדש מבטל את הקודם
        kp_opt('kol_maroko_v2_preview_hash', hash('sha256', 'x'));
        ok(!kp_is_v2(kp_http('GET', '/lexicon/?kol_preview=' . $tok)), 'אחרי החלפה — האסימון הישן כבר לא עובד');
        kp_opt('kol_maroko_v2_preview_hash', null);
        ok(!kp_is_v2(kp_http('GET', '/lexicon/?kol_preview=' . $tok)), 'בלי כתובת פעילה — כלום');
        // המפסק דולק: כולם מקבלים את החדש, במטמון הרגיל ובלי noindex
        kp_opt('kol_maroko_v2_preview_hash', hash('sha256', $tok));
        kp_opt('kol_maroko_v2_on', true);
        $r = kp_http('GET', '/lexicon/?kol_preview=' . $tok);
        ok(kp_is_v2($r) && kp_h($r, 'x-robots-tag') === null && kp_h($r, 'cache-control') === 'public, max-age=0, must-revalidate' && kp_cookie($r) === null, 'דולק — הגשה רגילה');
    });
});

test('M2b הבדיקה העצמית — גם היא לעולם לא במטמון משותף (אחרת LiteSpeed היה מגיש את החדש כשהמפסק כבוי)', function () {
    kp_on_fakewp(function () {
        kp_live(kp_bundle('S'), 1, false);
        kp_opt('kol_maroko_v2_selftest', ['t' => str_repeat('c', 32), 'exp' => time() + 60]);
        foreach (['/lexicon/', '/lexicon/a.css', '/lexicon/media/w.mp3', '/lexicon/nope'] as $p) {
            $r = kp_http('GET', $p, ['X-Kol-Selftest: ' . str_repeat('c', 32)]);
            ok(kp_is_v2($r) && kp_h($r, 'cache-control') === 'private, no-store' && kp_h($r, 'x-litespeed-cache-control') === 'no-cache', "$p — private");
        }
    });
});

test('M2b המונה אחרי ההדלקה: בקשות, 404 עם 20 האחרונים, שגיאות; בלי שאילתה ובלי IP; תצוגה מוקדמת אינה נספרת; ב-ping', function () {
    kp_on_fakewp(function () {
        kp_live(kp_bundle('C'), 1, false);
        $tok = bin2hex(random_bytes(16));
        kp_opt('kol_maroko_v2_preview_hash', hash('sha256', $tok));
        kp_http('GET', '/lexicon/?kol_preview=' . $tok);
        kp_http('GET', '/lexicon/nope?kol_preview=' . $tok);
        ok(!is_file(kp_v2() . '/stats.json'), 'כבוי (תצוגה מוקדמת) — לא נספר');
        eq(null, kp_send('ping')['json']['v2_stats'], 'ping כשכבוי — בלי מונה');

        file_put_contents(kp_v2() . '/stats.json', json_encode(['since' => time(), 'requests' => 0, 'not_found' => 0, 'errors' => 0, 'last404' => [], 'last_errors' => []]));
        kp_opt('kol_maroko_v2_on', true);
        for ($i = 0; $i < 5; $i++) kp_http('GET', '/lexicon/');
        for ($i = 1; $i <= 25; $i++) kp_http('GET', "/lexicon/missing-$i.html?email=secret@x.test&i=$i");
        kp_http('GET', '/lexicon/%3Cscript%3Ealert(1)%3C%2Fscript%3E%0d%0aX-Evil:%201');
        // שגיאה: המניפסט מפנה לגוש שנמחק מהדיסק
        unlink(kp_v2() . '/blobs/' . hash('sha256', kp_bundle('C')['a.css']));
        $r = kp_http('GET', '/lexicon/a.css');
        eq(500, $r['status'], 'גוש חסר ← 500');
        eq([], kp_iso($r), '500 — עם כל הכותרות');

        $st = kp_send('ping')['json']['v2_stats'];
        eq([32, 26, 1], [$st['requests'], $st['not_found'], $st['errors']], 'בקשות, 404, שגיאות');
        eq(20, count($st['last404']), '20 האחרונים בלבד');
        eq('/lexicon/%3Cscript%3Ealert(1)%3C%2Fscript%3E%0d%0aX-Evil:%201', $st['last404'][0]['path'], 'האחרון ראשון; הנתיב כמו שהגיע (מקודד) — מוצג כטקסט');
        eq('/lexicon/missing-25.html', $st['last404'][1]['path'], 'בלי מחרוזת השאילתה');
        eq('a.css', $st['last_errors'][0]['path']);
        $raw = (string)file_get_contents(kp_v2() . '/stats.json');
        ok(!str_contains($raw, 'secret@') && !str_contains($raw, '127.0.0.1') && !str_contains($raw, 'Cookie'), 'בלי שאילתה, בלי IP, בלי עוגיות');
        // קובץ מונה משובש לא שובר את ההגשה ואת ה-ping
        file_put_contents(kp_v2() . '/stats.json', '{"requests":"<x>","last404":[{"path":["a"]}],"since":"y"}');
        eq(200, kp_http('GET', '/lexicon/')['status']);
        $st = kp_send('ping')['json']['v2_stats'];
        ok(is_int($st['requests']) && is_int($st['since']), 'ping — מספרים בלבד גם מקובץ משובש: ' . json_encode($st));
    });
});

test('M2b ping (שורה 26 ב-M3): protocol 2, גרסת התוסף, האם יש v2 באוויר, האם המפסק דולק, האם יש כתובת בדיקה', function () {
    kp_on_fakewp(function () {
        $p = kp_send('ping')['json'];
        eq([2, '2.0.0', false, false, 0, false], [$p['protocol'], $p['plugin'], $p['v2_has_live'], $p['v2_on'], $p['v2_live'], $p['v2_preview']]);
        kp_live(kp_bundle('G'), 1);
        kp_opt('kol_maroko_v2_preview_hash', hash('sha256', 'z'));
        $p = kp_send('ping')['json'];
        eq([true, true, 1, 1, true], [$p['v2_has_live'], $p['v2_on'], $p['v2_live'], $p['v2_version'], $p['v2_preview']]);
        ok(!str_contains(json_encode($p), hash('sha256', 'z')), 'ה-hash של האסימון אינו יוצא');
    });
});

test('M2b עמוד התוסף: הדלקה באישור כפול (סימון + הגרסה שאושרה), כיבוי בלחיצה, ניקוי מטמון ויומן; הכול manage_options + nonce', function () {
    kp_site();
    kp_reset();
    kp_live(kp_bundle('A'), 1, false);
    kp_live(kp_bundle('B'), 2, false);
    foreach (['on' => ['confirm' => 1, 'version' => 2], 'off' => [], 'preview_new' => [], 'preview_off' => []] as $op => $f) {
        eq(403, kp_admin($op, $f, ['admin' => false])['status'], "$op — בלי הרשאה");
        eq(403, kp_admin($op, $f, ['nonce' => 'nonce-kol_maroko_save'])['status'], "$op — nonce של טופס אחר");
    }
    eq(false, kp_get('kol_maroko_v2_on'));
    eq(null, kp_get('kol_maroko_v2_preview_hash'));
    $page = kp_http('GET', '/wp-admin/options-general.php', [KP_ADMIN])['body'];
    ok(str_contains($page, 'הגולשים יראו מעכשיו את הגרסה 2, שפורסמה ב-') && str_contains($page, 'ע&quot;י בודק'), 'נוסח האישור: גרסה, מתי, מי');
    kp_admin('on');
    eq(false, kp_get('kol_maroko_v2_on'), 'בלי הסימון — לא נדלק');
    kp_admin('on', ['confirm' => 1, 'version' => 1]);
    eq(false, kp_get('kol_maroko_v2_on'), 'אישור על גרסה אחרת מזו שבאוויר — לא נדלק');
    kp_opt('test_actions', null);
    kp_admin('on', ['confirm' => 1, 'version' => 2]);
    eq(true, kp_get('kol_maroko_v2_on'), 'עם שניהם — דולק');
    ok(in_array('litespeed_purge_url', array_column((array)kp_get('test_actions'), 0), true), 'ניקוי מטמון');
    $st = json_decode((string)file_get_contents(kp_v2() . '/stats.json'), true);
    eq([0, 0, 0], [$st['requests'], $st['not_found'], $st['errors']], 'המונה התחיל מאפס');
    $log = kp_get('kol_maroko_v2_log');
    eq(['on', 2, 'admin"'], [$log[0]['op'], $log[0]['version'], $log[0]['who']], 'יומן: מה, איזו גרסה, מי (מנוקה)');
    $page = kp_http('GET', '/wp-admin/options-general.php', [KP_ADMIN])['body'];
    ok(str_contains($page, 'מאז ההדלקה') && str_contains($page, 'admin&quot;') && !str_contains($page, 'admin<b>'), 'המונה והיומן במסך; שם המשתמש מוצג כטקסט');
    kp_opt('test_actions', null);
    kp_admin('off');
    eq(false, kp_get('kol_maroko_v2_on'), 'כיבוי — לחיצה אחת');
    ok(in_array('litespeed_purge_url', array_column((array)kp_get('test_actions'), 0), true), 'ניקוי מטמון בכיבוי');
    eq('off', kp_get('kol_maroko_v2_log')[0]['op']);
});

test('M2b עמוד התוסף: כתובת בדיקה — 128 ביט, מוצגת פעם אחת, נשמר רק hash; חדשה מבטלת את הקודמת; ביטול', function () {
    kp_site();
    kp_reset();
    kp_live(kp_bundle('A'), 1, false);
    $r = kp_admin('preview_new');
    ok(preg_match('~\?kol_preview=([a-f0-9]{32})"~', $r['body'], $m) === 1, 'הכתובת מוצגת בתשובה');
    $tok = $m[1];
    eq(hash('sha256', $tok), kp_get('kol_maroko_v2_preview_hash'), 'נשמר ה-hash');
    ok(!str_contains((string)file_get_contents(kp_site()['dir'] . '/options.json'), $tok), 'האסימון עצמו — בשום מקום');
    ok(!str_contains(kp_http('GET', '/wp-admin/options-general.php', [KP_ADMIN])['body'], $tok), 'ובמסך — לא מוצג שוב');
    eq(200, kp_http('GET', '/lexicon/?kol_preview=' . $tok)['status']);
    ok(str_contains(kp_http('GET', '/lexicon/?kol_preview=' . $tok)['body'], 'VERSION-A'), 'הכתובת עובדת');
    preg_match('~\?kol_preview=([a-f0-9]{32})"~', kp_admin('preview_new')['body'], $m2);
    ok($m2[1] !== $tok && str_contains(kp_http('GET', '/lexicon/?kol_preview=' . $tok)['body'], 'WP-SITE-PAGE'), 'כתובת חדשה ← הקודמת מתבטלת');
    kp_admin('preview_off');
    ok(str_contains(kp_http('GET', '/lexicon/?kol_preview=' . $m2[1])['body'], 'WP-SITE-PAGE'), 'ביטול');
    eq(['preview_off', 'preview_new', 'preview_new'], array_slice(array_column(kp_get('kol_maroko_v2_log'), 'op'), 0, 3), 'ביומן');
});
