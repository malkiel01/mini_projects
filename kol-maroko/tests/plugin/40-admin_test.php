<?php
/**
 * M2 §2 "מפסק, אבחון": עמוד התוסף — בעל האתר בלבד (manage_options) ו-nonce של וורדפרס, בשרת. המפסק, "החזר לגרסה זו",
 * מכסות, הגשה ישירה (נדלקת רק אם הבדיקה העצמית מוכיחה), מחיקה מפורשת, והבדיקה העצמית — בקשה לאתר עצמו בלבד.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

const KP_ADMIN = 'Cookie: test_admin=1';

function kp_admin(string $op, array $fields = [], array $o = []): array
{
    $f = $fields + ['action' => 'kol_maroko_v2', 'op' => $op, '_wpnonce' => $o['nonce'] ?? 'nonce-kol_maroko_v2'];
    return kp_http('POST', '/wp-admin/admin-post.php', array_merge($o['admin'] ?? true ? [KP_ADMIN] : [], ['Content-Type: application/x-www-form-urlencoded']), http_build_query($f));
}

test('M2 עמוד התוסף: בלי manage_options או בלי nonce נכון — נדחה בשרת, ושום דבר לא משתנה', function () {
    kp_site();
    kp_reset();
    kp_live(kp_bundle('A'), 1, false);
    kp_live(kp_bundle('B'), 2, false);
    $before = kp_opts();
    foreach (['on' => [], 'activate' => ['n' => 1], 'quota' => ['q' => ['files' => 1]], 'wipe' => ['confirm' => 1], 'direct' => ['direct' => 1], 'selftest' => []] as $op => $f) {
        $r = kp_admin($op, $f, ['admin' => false]);
        eq(403, $r['status'], "$op — בלי הרשאה");
        $r = kp_admin($op, $f, ['nonce' => 'nonce-other']);
        eq(403, $r['status'], "$op — nonce של פעולה אחרת");
        $r = kp_admin($op, $f, ['nonce' => '']);
        eq(403, $r['status'], "$op — בלי nonce");
    }
    eq($before, kp_opts(), 'שום אפשרות לא השתנתה');
    ok(is_file(kp_v2() . '/manifest-1.json'), 'שום דבר לא נמחק');
    eq(403, kp_http('GET', '/wp-admin/options-general.php')['status'], 'העמוד עצמו — בלי הרשאה');
});

test('M2 עמוד התוסף: המפסק (ברירת מחדל כבוי), "החזר לגרסה זו", מכסות, ומחיקה רק עם אישור', function () {
    kp_site();
    kp_reset();
    kp_live(kp_bundle('A'), 1, false);
    kp_live(kp_bundle('B'), 2, false);
    $page = kp_http('GET', '/wp-admin/options-general.php', [KP_ADMIN])['body'];
    ok(str_contains($page, '⚪ כבוי') && str_contains($page, 'גרסה 2') && substr_count($page, 'החזר לגרסה זו') === 1, 'כבוי; גרסה 2 באוויר; "החזר" לגרסה 1 בלבד');
    eq(302, kp_admin('on', ['confirm' => 1, 'version' => 2])['status']);
    eq(true, kp_get('kol_maroko_v2_on'));
    ok(str_contains(kp_http('GET', '/lexicon/')['body'], 'VERSION-B'), 'דולק ← החדש מוגש');
    kp_admin('activate', ['n' => 1]);
    eq(1, kp_get('kol_maroko_v2_live'));
    ok(str_contains(kp_http('GET', '/lexicon/')['body'], 'VERSION-A'), '"החזר לגרסה זו" ← מיד');
    kp_admin('activate', ['n' => 77]);
    eq(1, kp_get('kol_maroko_v2_live'), 'גרסה שאינה שמורה — לא זז');
    kp_admin('off');
    ok(str_contains(kp_http('GET', '/lexicon/')['body'], 'WP-SITE-PAGE'), 'כבוי ← העמוד המקורי');
    kp_admin('quota', ['q' => ['file_mb' => 7, 'total_mb' => -3, 'files' => 'x', 'keep' => 4, 'rate' => 99]]);
    eq(['file_mb' => 7, 'total_mb' => 500, 'files' => 5000, 'keep' => 4, 'rate' => 99], kp_get('kol_maroko_v2_quota'), 'ערך לא חוקי ← ברירת המחדל');
    kp_admin('wipe');
    ok(is_file(kp_v2() . '/manifest-2.json'), 'מחיקה בלי סימון האישור — לא נמחק');
    kp_opt('kol_maroko_last', 9);
    kp_admin('wipe', ['confirm' => 1]);
    clearstatcache();
    ok(!glob(kp_v2() . '/manifest-*.json') && !is_file(kp_v2() . '/live.json') && !kp_get('kol_maroko_v2_live'), 'עם אישור — חבילות 2.0 נמחקו');
    eq(9, kp_get('kol_maroko_last'), 'ההגדרות של 1.x לא נגעו');
    ok(is_file(kp_v2() . '/blobs/.htaccess'), 'התיקייה נשארה נעולה');
});

test('M2 בדיקה עצמית: entry, CSS, שמע עם Range, 404, טיפוס — ✓ לכל שורה; blobs/ חסומה; רק לאתר עצמו; עובדת גם כשהמפסק כבוי', function () {
    kp_site();
    kp_reset();
    kp_live(kp_bundle('A'), 1, false);
    kp_opt('test_remote', null);
    eq(302, kp_admin('selftest')['status']);
    $res = kp_get('kol_maroko_v2_selftest_result');
    $rows = array_column($res['rows'] ?? [], null, 'id');
    foreach (['entry', '404', 'traverse', 'css', 'audio'] as $id) ok(($rows[$id]['ok'] ?? false) === true, "$id · " . json_encode($rows[$id] ?? null, JSON_UNESCAPED_UNICODE));
    ok(isset($rows['blobs']), 'שורת blobs/ קיימת (כאן ✗: השרת המדומה אינו Apache ואינו קורא .htaccess — נבדק באתר)');
    eq('206', substr($rows['audio']['text'], 0, 3), 'שמע — 206');
    $base = kp_site()['base'];
    $remote = (array)kp_get('test_remote');
    ok($remote && array_filter($remote, fn($u) => !str_starts_with($u, $base . '/')) === [], 'כל הבקשות — לאתר עצמו: ' . implode(' ', $remote));
    eq(null, kp_get('kol_maroko_v2_selftest'), 'האסימון נמחק בסוף');
    ok(str_contains(kp_http('GET', '/lexicon/')['body'], 'WP-SITE-PAGE'), 'אחרי הבדיקה — המפסק עדיין כבוי, העמוד הישן מוגש');
    $page = kp_http('GET', '/wp-admin/options-general.php', [KP_ADMIN])['body'];
    ok(substr_count($page, '<td>✓</td>') >= 5, 'הטבלה במסך — ✓');
    // הגשה ישירה: השרת המדומה אינו מגיש קבצים בעצמו (כמו שרת בלי הכותרות) ← לא נדלקת
    kp_admin('direct', ['direct' => 1]);
    eq(false, kp_get('kol_maroko_v2_direct_media'), 'הבדיקה העצמית לא הוכיחה ← ההגשה הישירה חזרה לכבוי');
    ok(!str_contains((string)file_get_contents(kp_v2() . '/blobs/.htaccess'), 'granted'), 'וה-.htaccess חזר לחסום');
    ok(str_contains((string)kp_get('_transient_kol_maroko_v2_msg'), 'לא הודלקה'), 'הודעה במסך: לא הודלקה');
});

test('M2 הבדיקה העצמית מגלה כותרת חסרה (ביקורת חיובית): בלי גרסה — ✗; ו-check_headers מזהה Set-Cookie ו-allow-same-origin', function () {
    kp_site();
    kp_reset();
    kp_admin('selftest');
    eq(false, kp_get('kol_maroko_v2_selftest_result')['ok'] ?? null, 'בלי גרסה — הבדיקה אינה ✓');
    // הפונקציה עצמה — דרך קריאה ישירה בקובץ (בלי וורדפרס): כותרות פגומות ← ✗
    $src = (string)file_get_contents(dirname(__DIR__, 2) . '/wordpress-plugin/kol-maroko/v2-admin.php');
    ok(str_contains($src, "if (isset(\$h['set-cookie'])) \$bad[] = 'יש Set-Cookie!';") && str_contains($src, "strpos(\$csp, 'allow-same-origin') !== false"), 'הבדיקה העצמית בודקת Set-Cookie ו-allow-same-origin');
});
