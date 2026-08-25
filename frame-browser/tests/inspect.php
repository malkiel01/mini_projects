<?php
/**
 * בדיקות לבדיקת הקישור.
 *
 *   php frame-browser/tests/inspect.php
 *
 * הדגש הוא על שתי נקודות: שהמערכת אינה הופכת לשער לרשת הפנימית של
 * השרת, ושהיא מזהה נכון אתר שאוסר הטמעה.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../lib/inspect.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}
function blocked(string $name, string $url, string $kind = '') {
    try {
        checkUrl($url);
        check($name, 'עבר', 'נחסם');
    } catch (InspectError $e) {
        check($name, $kind === '' || $e->kind === $kind, true);
    }
}

echo "\n— רשתות אסורות —\n";
check('לולאה מקומית',      isForbiddenIp('127.0.0.1'), true);
check('רשת פרטית 10',      isForbiddenIp('10.0.0.5'), true);
check('רשת פרטית 192.168', isForbiddenIp('192.168.1.1'), true);
check('רשת פרטית 172.16',  isForbiddenIp('172.16.0.1'), true);
check('link-local',        isForbiddenIp('169.254.169.254'), true);   // שרת המטא-דאטה של ענן
check('לולאה IPv6',        isForbiddenIp('::1'), true);
check('פרטית IPv6',        isForbiddenIp('fd00::1'), true);
check('כתובת ציבורית',     isForbiddenIp('8.8.8.8'), false);
check('ציבורית IPv6',      isForbiddenIp('2001:4860:4860::8888'), false);

echo "\n— כתובות שנדחות —\n";
blocked('לולאה מקומית',        'http://127.0.0.1/admin', 'private');
blocked('localhost בשם',       'http://localhost/', 'private');
blocked('רשת פנימית',          'http://192.168.1.1/', 'private');
blocked('מטא-דאטה של ענן',     'http://169.254.169.254/latest/meta-data/', 'private');
blocked('סכימת file',          'file:///etc/passwd', 'bad_scheme');
blocked('סכימת gopher',        'gopher://evil/', 'bad_scheme');
blocked('יציאה לא סטנדרטית',   'http://example.com:22/', 'bad_port');
blocked('יציאה 6379 (redis)',  'http://example.com:6379/', 'bad_port');
blocked('כתובת ריקה',          'https://', 'bad_url');

echo "\n— כתובות שמתקבלות —\n";
$ok = checkUrl('https://8.8.8.8/');
check('IP ציבורי ישיר', $ok['host'], '8.8.8.8');
check('יציאה נגזרת מהסכימה', $ok['port'], 443);
check('http = 80', checkUrl('http://8.8.8.8/')['port'], 80);

echo "\n— הכרעת הטמעה —\n";
$origin = 'https://mbe-plus.com';
$verdict = fn(array $headers) => framingVerdict(['headers' => $headers], $origin);

check('בלי כותרות — מותר',      $verdict([])[0], true);
check('DENY',                    $verdict(['x-frame-options' => 'DENY'])[0], false);
check('deny באותיות קטנות',      $verdict(['x-frame-options' => 'deny'])[0], false);
check('SAMEORIGIN',              $verdict(['x-frame-options' => 'SAMEORIGIN'])[0], false);
check('ALLOW-FROM',              $verdict(['x-frame-options' => 'ALLOW-FROM https://x.com'])[0], false);
check('frame-ancestors none',    $verdict(['content-security-policy' => "frame-ancestors 'none'"])[0], false);
check('frame-ancestors self',    $verdict(['content-security-policy' => "frame-ancestors 'self'"])[0], false);
check('frame-ancestors *',       $verdict(['content-security-policy' => 'frame-ancestors *'])[0], true);
check('frame-ancestors אותנו',
      $verdict(['content-security-policy' => 'frame-ancestors https://mbe-plus.com'])[0], true);
check('frame-ancestors אתר אחר',
      $verdict(['content-security-policy' => 'frame-ancestors https://other.com'])[0], false);

// CSP גובר על X-Frame-Options גם כשהוא מתיר וגם כשהוא אוסר.
check('CSP מתיר גובר על XFO אוסר',
      $verdict(['x-frame-options' => 'DENY', 'content-security-policy' => 'frame-ancestors *'])[0], true);
check('CSP אוסר גובר על XFO שותק',
      $verdict(['content-security-policy' => "default-src 'self'; frame-ancestors 'none'"])[0], false);
check('CSP בלי frame-ancestors אינו חוסם',
      $verdict(['content-security-policy' => "default-src 'self'"])[0], true);
check('הסיבה מנוסחת', str_contains($verdict(['x-frame-options' => 'DENY'])[1], 'DENY'), true);

echo "\n— כתובות יחסיות בהפניה —\n";
check('מוחלטת נשארת', resolveUrl('https://a.com/x/y', 'https://b.com/z'), 'https://b.com/z');
check('משורש',        resolveUrl('https://a.com/x/y', '/z'), 'https://a.com/z');
check('יחסית לתיקייה', resolveUrl('https://a.com/x/y', 'z'), 'https://a.com/x/z');
check('שומרת יציאה',   resolveUrl('https://a.com:443/x/y', '/z'), 'https://a.com:443/z');

echo "\n— כותרת הדף —\n";
check('כותרת פשוטה',   pageTitle('<html><head><title>שלום</title>'), 'שלום');
check('רווחים מתכווצים', pageTitle("<title>a\n   b</title>"), 'a b');
check('ישויות מפוענחות', pageTitle('<title>a &amp; b</title>'), 'a & b');
check('עם תכונות',      pageTitle('<title dir="rtl">כותרת</title>'), 'כותרת');
check('בלי כותרת',      pageTitle('<html><body>x</body>'), '');
check('עברית ב-UTF-8 נשמרת', pageTitle('<title>עיריית תל אביב</title>'), 'עיריית תל אביב');

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exit($fail === 0 ? 0 : 1);
