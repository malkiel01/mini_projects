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

echo "\n— אתגר של שירות הגנה —\n";
$res = fn(int $status, array $headers = [], string $body = '') =>
    ['status' => $status, 'headers' => $headers, 'body' => $body];

check('403 של Cloudflare לפי הכותרת',
      looksLikeChallenge($res(403, ['server' => 'cloudflare'])), true);
check('לפי cf-ray',
      looksLikeChallenge($res(403, ['cf-ray' => 'abc'])), true);
check('לפי cf-mitigated גם ב-200',
      looksLikeChallenge($res(200, ['cf-mitigated' => 'challenge'])), true);
check('לפי גוף הדף',
      looksLikeChallenge($res(403, [], '<title>Just a moment...</title>')), true);
check('checking your browser',
      looksLikeChallenge($res(503, [], 'Checking your browser before accessing')), true);

// אלה דווקא לא אתגר, וחשוב שלא ייחשבו ככזה
check('403 רגיל בלי סימנים', looksLikeChallenge($res(403, ['server' => 'nginx'])), false);
check('404 אינו אתגר',       looksLikeChallenge($res(404, ['server' => 'cloudflare'])), false);
check('200 תקין',            looksLikeChallenge($res(200, ['server' => 'cloudflare'])), false);
check('500 אינו אתגר',       looksLikeChallenge($res(500, ['server' => 'nginx'])), false);

echo "\n— ‏403 אינו איסור הטמעה —\n";
// זו הטעות שתוקנה: תשובת שגיאה אינה אומרת דבר על הטמעה, והכותרות כן.
check('403 בלי כותרות חוסמות — עדיין מותר',
      framingVerdict($res(403, ['server' => 'cloudflare']), 'https://x.com')[0], true);
check('403 עם DENY — חסום',
      framingVerdict($res(403, ['server' => 'cloudflare', 'x-frame-options' => 'DENY']), 'https://x.com')[0], false);

echo "\n— חילוץ מסגרות פנימיות —\n";
$base = 'https://liveball.sx/team/772';

$html = '<iframe src="https://player.example.com/embed/abc" allowfullscreen></iframe>';
check('iframe פשוט', findFrameCandidates($html, $base), ['https://player.example.com/embed/abc']);

check('נתיב יחסי הופך למוחלט',
      findFrameCandidates('<iframe src="/play/9">', $base), ['https://liveball.sx/play/9']);
check('כתובת ללא סכימה',
      findFrameCandidates('<iframe src="//cdn.x.com/embed/1">', $base), ['https://cdn.x.com/embed/1']);
check('data-src נתפס גם הוא',
      findFrameCandidates('<iframe data-src="https://p.com/player/5">', $base), ['https://p.com/player/5']);

check('פרסום ומדידה מסוננים',
      findFrameCandidates('<iframe src="https://www.googletagmanager.com/ns.html?id=1">', $base), []);
check('data: מסונן',
      findFrameCandidates('<iframe src="data:text/html,x">', $base), []);
check('כפילויות מאוחדות',
      count(findFrameCandidates('<iframe src="https://a.com/embed/1"><iframe src="https://a.com/embed/1">', $base)), 1);

// נגן קודם לשאר — זה מה שהמשתמש מחפש
$mixed = '<iframe src="https://ads.co/banner"></iframe><iframe src="https://s.tv/embed/77"></iframe>';
check('נגן ראשון ברשימה', findFrameCandidates($mixed, $base)[0], 'https://s.tv/embed/77');

check('כתובת נגן גם בלי תגית iframe',
      in_array('https://cdn.tv/player/22.html',
               findFrameCandidates('var u = "https://cdn.tv/player/22.html";', $base), true), true);
check('דף בלי כלום', findFrameCandidates('<p>שלום</p>', $base), []);

echo "\n— תמצית ה-CSP —\n";
check('החלק הרלוונטי בלבד',
      frameAncestorsOf("default-src \'self\'; frame-ancestors \'none\'; img-src *"), "\'none\'");
check('בלי frame-ancestors', frameAncestorsOf("default-src \'self\'"), '');
check('CSP ריק', frameAncestorsOf(''), '');

echo "\n— מסקנת האבחון —\n";
$att = fn(string $v) => [['verdict' => $v]];
check('מסגרת פתוחה גוברת על הכול',
      str_contains(probeConclusion($att('blocked'), [['framable' => true]]), 'מסגרת פנימית'), true);
check('הצלחה בשיטה כלשהי',
      str_contains(probeConclusion($att('ok'), []), 'הצליחה'), true);
check('חסימה מפורשת',
      str_contains(probeConclusion($att('blocked'), []), 'אוסר הטמעה'), true);
check('שירות הגנה',
      str_contains(probeConclusion($att('challenge'), []), 'שירות הגנה'), true);
check('כישלון מוחלט',
      str_contains(probeConclusion($att('failed'), []), 'אינו זמין'), true);

echo "\n════ עברו: $pass · נכשלו: $fail ════\n";
exit($fail === 0 ? 0 : 1);
