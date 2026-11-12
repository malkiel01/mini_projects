<?php
/**
 * M2 §2 "שדרוג" ו"מה התוסף אינו עושה": אפשרויות 1.4 / 1.6 נקראות כמו שהן ואינן משתנות; פעולות 1.x ממשיכות;
 * הסרה אינה מוחקת; ובקריאת הקוד — בלי include/eval של קובץ חבילה, בלי פנייה לשרת אחר, בלי post_content.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

/** טוען קובץ קלט של אפשרויות 1.x, עם המפתח של הבדיקה. */
function kp_fixture(string $name): array
{
    $k = kp_site();
    $o = json_decode(str_replace('{{KEY}}', $k['kid'] . ':' . base64_encode($k['public']), (string)file_get_contents(__DIR__ . "/fixtures/$name")), true);
    unset($o['_note']);
    kp_reset();
    file_put_contents($k['dir'] . '/options.json', json_encode($o));
    return $o;
}

foreach (['options-1.4.json' => '1.4.0', 'options-1.6.json' => '1.6.0'] as $fx => $ver) {
    test("M2 שדרוג $ver ← 2.0: ping, פרסום v1 והגשת v1 זהים; אפשרויות 1.x לא השתנו; התקנה אינה משנה דבר לגולשים", function () use ($fx) {
        $o = kp_fixture($fx);
        $p = kp_send('ping')['json'];
        eq([true, $o['kol_maroko_last'], $o['kol_maroko_live'], $o['kol_maroko_enabled'], true], [$p['ok'], $p['version'], $p['live'], $p['enabled'], $p['page_set']], 'שדות 1.x');
        eq([2, 0, false], [$p['protocol'], $p['v2_live'], $p['v2_on']]);
        // שני מפתחות (החלפה) — הישן אינו תקין לחתימה שלנו, החדש כן: ה-ping עבר
        ok(kp_site_page_all(), 'התקנה: שום כתובת בתחום אינה נתפסת');
        // אף אפשרות של 1.x לא נכתבה מחדש (חוץ מ-nonces, שכל בקשה חתומה מוסיפה לו — כמו ב-1.x)
        $after = kp_opts();
        foreach ($o as $k => $v) if ($k !== 'kol_maroko_nonces') eq($v, $after[$k] ?? null, "האפשרות $k לא השתנתה");
        // פרסום v1 — כמו היום
        $n = $o['kol_maroko_last'] + 1;
        $r = kp_send('publish', ['version' => $n, 'html' => '<p>v1 עדיין עובד</p><script>x</script>', 'css' => 'p{}']);
        eq([200, $n], [$r['status'], $r['json']['version'] ?? null], $r['body']);
        eq('<p>v1 עדיין עובד</p>', kp_get("kol_maroko_v_$n")['html'] ?? null, 'v1: הסינון של 1.x עדיין פועל על המסלול הישן');
        eq([$n, $n], [kp_get('kol_maroko_last'), kp_get('kol_maroko_live')]);
        eq(200, kp_send('switch', ['enabled' => false])['status'], 'switch של 1.x');
        // v2 אחרי v1 — המונה משותף
        eq('old_version', kp_publish(kp_manifest(kp_bundle(), $n))['json']['code'] ?? null);
        eq(200, kp_publish(kp_manifest(kp_bundle(), $n + 1, [], false))['status']);
        eq($n, kp_get('kol_maroko_last'), 'v2 לא נגע במונה של 1.x');
        ok(kp_site_page_all(), 'גם אחרי פרסום v2 — המפסק כבוי, שום דבר לא נתפס');
    });
}

function kp_site_page_all(): bool
{
    foreach (['/lexicon/', '/lexicon/index.html', '/lexicon/a.css', '/lexicon'] as $p) if (!str_contains(kp_http('GET', $p)['body'], 'WP-SITE-PAGE')) return false;
    return true;
}

test('M2 הסרת התוסף אינה מוחקת: אין uninstall.php ואין register_uninstall_hook / register_deactivation_hook שמוחקים', function () {
    $dir = dirname(__DIR__, 2) . '/wordpress-plugin/kol-maroko';
    ok(!is_file("$dir/uninstall.php"), 'uninstall.php');
    foreach (glob("$dir/*.php") as $f) {
        $c = (string)file_get_contents($f);
        ok(!preg_match('/register_(uninstall|deactivation)_hook/', $c), basename($f));
    }
    ok(str_contains((string)file_get_contents("$dir/v2-admin.php"), "\$op === 'wipe' && !empty(\$_POST['confirm'])"), 'מחיקה — רק בכפתור מפורש עם אישור');
});

test('M2 מה התוסף אינו עושה (07 §5.3): בקבצים החדשים — בלי include/require עם משתנה, eval, wp_remote_ (חוץ מהבדיקה העצמית לאתר עצמו), post_content', function () {
    $dir = dirname(__DIR__, 2) . '/wordpress-plugin/kol-maroko';
    foreach (['v2-store.php', 'v2-serve.php', 'v2-admin.php'] as $name) {
        $c = (string)file_get_contents("$dir/$name");
        // הקוד בלי הערות ובלי מחרוזות (tokenizer) — כדי שמילה בהערה לא תסתיר ולא תדמה
        $code = '';
        foreach (token_get_all($c) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) { $code .= ' '; continue; }
            if (is_array($t) && in_array($t[0], [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_EVAL], true)) throw new TestFail("$name — " . $t[1] . ' בשורה ' . $t[2]);
            $code .= is_array($t) ? $t[1] : $t;
        }
        ok(!preg_match('/\b(eval|assert|create_function|unserialize|extract)\s*\(|\bpreg_replace\s*\([^)]*\/e[\'"]|`/i', $code), "$name — eval וחבריו");
        ok(!preg_match('/\b(post_content|wp_update_post|wp_insert_post|\$wpdb)\b/', $code), "$name — post_content");
        ok(!preg_match('/\b(curl_|fsockopen|file_get_contents\s*\(\s*[\'"]https?:|stream_socket_client)/', $code), "$name — פנייה לשרת");
        ok(!preg_match('/\b(setcookie|setrawcookie|session_start)\b/', $code), "$name — עוגיות");
        $remote = preg_match_all('/\bwp_remote_(get|post|request|head)\s*\(/', $code);
        if ($name !== 'v2-admin.php') eq(0, $remote, "$name — wp_remote_");
        else eq(1, $remote, 'v2-admin.php — wp_remote_ רק במקום אחד');
    }
    $admin = (string)file_get_contents("$dir/v2-admin.php");
    ok(preg_match('/function kol_maroko_v2_loopback.*?wp_parse_url\(\$url, PHP_URL_HOST\) !== \$host\) return null;.*?wp_remote_request/s', $admin) === 1,
        'הבקשה היחידה — רק למארח של האתר, נבדק לפני השליחה');
    ok(str_contains($admin, "'redirection' => 0"), 'בלי הפניות (שלא תוביל לשרת אחר)');
    // ההגשה מוציאה בייטים — לא מריצה ולא מדפיסה לתוך מסמך של האתר
    $serve = (string)file_get_contents("$dir/v2-serve.php");
    $serve = implode('', array_map(fn($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t, token_get_all($serve)));
    ok(str_contains($serve, 'fread($fh') && !preg_match('/\b(get_header|wp_head|get_footer|wp_footer|do_shortcode|apply_filters|the_content)\b/', $serve), 'בלי ערכת הנושא בהגשה');
});
