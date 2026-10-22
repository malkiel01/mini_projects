<?php
/**
 * בדיקות לבקשות היוצאות של הסורק (lib/safefetch.php) — בלי רשת: DNS ובקשות מדומים.
 *
 *   php guarded-browser/tests/safefetch.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/safefetch.php';
$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}

// DNS מדומה.
$dns = [
    'example.org'       => ['93.184.216.34'],
    'v6.example.org'    => ['2606:2800:220:1:248:1893:25c8:1946'],
    'internal.corp'     => ['10.1.2.3'],
    'rebind.example'    => ['93.184.216.34', '127.0.0.1'],   // ציבורית + פרטית
    'meta.example'      => ['169.254.169.254'],
    'cgnat.example'     => ['100.64.1.1'],
    'ula.example'       => ['fd00::1'],
    'mapped.example'    => ['::ffff:192.168.1.1'],
];
$resolver = fn(string $h) => $dns[$h] ?? (filter_var($h, FILTER_VALIDATE_IP) ? [$h] : []);
$ok = fn(string $u) => safeUrlCheck($u, $resolver)['ok'];

echo "\n— כתובות IP —\n";
foreach (['127.0.0.1', '10.0.0.1', '172.16.5.5', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0',
          '::1', 'fe80::1', 'fd12:3456::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '224.0.0.1'] as $ip) {
    check("פנימית/שמורה: $ip", ipIsPublic($ip), false);
}
foreach (['93.184.216.34', '8.8.8.8', '2606:2800:220:1:248:1893:25c8:1946'] as $ip) {
    check("ציבורית: $ip", ipIsPublic($ip), true);
}

echo "\n— כתובות —\n";
check('אתר ציבורי', $ok('https://example.org/page'), true);
check('אתר ציבורי ב-IPv6', $ok('https://v6.example.org/'), true);
check('loopback ישיר', $ok('http://127.0.0.1/'), false);
check('loopback ב-IPv6', $ok('http://[::1]/'), false);
check('שם שמתפרש לרשת פנימית', $ok('https://internal.corp/'), false);
check('שרת המטא-דאטה של הענן', $ok('http://meta.example/latest/'), false);
check('CGNAT', $ok('http://cgnat.example/'), false);
check('IPv6 פרטי (ULA)', $ok('http://ula.example/'), false);
check('IPv4 ממופה ב-IPv6', $ok('http://mapped.example/'), false);
check('שם עם כתובת ציבורית ופרטית — נדחה', $ok('http://rebind.example/'), false);
check('סכימות אחרות', [$ok('file:///etc/passwd'), $ok('ftp://example.org/'), $ok('gopher://example.org/')], [false, false, false]);
check('פורט לא סטנדרטי', $ok('http://example.org:22/'), false);
check('פרטי כניסה בכתובת', $ok('http://user:pass@example.org/'), false);
check('שם שלא קיים', $ok('http://nope.invalid/'), false);

echo "\n— הפניות וחיבור —\n";
$calls = [];
$exec = function (string $url, array $c, int $max) use (&$calls) {
    $calls[] = [$url, $c['ip']];
    return match ($url) {
        'https://example.org/to-internal' => ['status' => 302, 'location' => 'http://internal.corp/admin'],
        'https://example.org/to-meta'     => ['status' => 301, 'location' => 'http://169.254.169.254/latest/meta-data/'],
        'https://example.org/relative'    => ['status' => 302, 'location' => '/final'],
        'https://example.org/final'       => ['status' => 200, 'body' => 'ok'],
        'https://example.org/loop'        => ['status' => 302, 'location' => '/loop'],
        default                           => ['status' => 200, 'body' => 'page'],
    };
};
$r = safeHttpGet('https://example.org/page', 1000, $resolver, $exec);
check('אתר ציבורי נסרק', [$r['status'], $r['body']], [200, 'page']);
check('...והחיבור ננעל לכתובת שנבדקה', end($calls)[1], '93.184.216.34');
$calls = [];
$r = safeHttpGet('https://example.org/to-internal', 1000, $resolver, $exec);
check('הפניה לרשת פנימית — נחסמת לפני החיבור', [$r['status'], $r['error'], count($calls)], [0, 'יעד פנימי', 1]);
$r = safeHttpGet('https://example.org/to-meta', 1000, $resolver, $exec);
check('הפניה לשרת המטא-דאטה — נחסמת', $r['error'], 'יעד פנימי');
$r = safeHttpGet('https://example.org/relative', 1000, $resolver, $exec);
check('הפניה יחסית לאותו אתר — עובדת', [$r['status'], $r['body']], [200, 'ok']);
$r = safeHttpGet('https://example.org/loop', 1000, $resolver, $exec);
check('לולאת הפניות — נעצרת', $r['error'], 'יותר מדי הפניות');
$calls = [];
$r = safeHttpGet('http://127.0.0.1/', 1000, $resolver, $exec);
check('יעד אסור — אין חיבור בכלל', [$r['status'], count($calls)], [0, 0]);

echo "\n— צירוף כתובות —\n";
check('נתיב יחסי', safeJoinUrl('https://a.org/x/y', 'z'), 'https://a.org/x/z');
check('נתיב מוחלט', safeJoinUrl('https://a.org/x/y', '/z'), 'https://a.org/z');
check('בלי סכימה', safeJoinUrl('https://a.org/x', '//b.org/z'), 'https://b.org/z');

echo "\n$pass עברו, $fail נכשלו\n";
exit($fail ? 1 : 0);
