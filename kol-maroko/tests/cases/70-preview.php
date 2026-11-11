<?php
/**
 * פרוסה F4 — התצוגה החיה המבודדת (lib/preview.php): ההגשה, כותרות הבידוד והאסימון.
 * כל בדיקה כאן עומדת מול קריטריון "ההגשה" ב-docs/spec-v2/slices/F4-live-preview.md. בדיקות הדפדפן — tests/browser/f4.cjs.
 */

declare(strict_types=1);

require_once KOL_ROOT . '/lib/preview.php';

/** בקשה לתצוגה — **בלי זהות ובלי עוגייה** (כך מגיעה בקשה ממסגרת במקור אטום), ובלי שהלקוח ינרמל את הנתיב. */
function pv_get(string $path, ?string $token = null, array $headers = [], string $method = 'GET'): array
{
    $token ??= pv_tok();
    $ch = curl_init($GLOBALS['BASE'] . '/preview/' . $token . '/' . $path);
    $rh = [];
    $cookies = [];
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_NOBODY => $method === 'HEAD', CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_PATH_AS_IS => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$rh, &$cookies) {
            if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $rh[strtolower(trim($k))] = trim($v); if (strtolower(trim($k)) === 'set-cookie') $cookies[] = $v; }
            return strlen($line);
        }]);
    $body = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $status, 'headers' => $rh, 'body' => $body, 'cookies' => $cookies];
}

function pv_tok(): string
{
    static $t;
    return $t ??= http('GET', '/api.php?action=pv_token', ['user' => U_EDITOR])['json']['token'];
}

/** I1 + I2 + I4 + I7 על תשובה אחת. */
function pv_isolated(array $r, string $what): void
{
    eq(PV_SANDBOX, $r['headers']['content-security-policy'] ?? '', "I1 · כותרת ארגז החול · $what");
    ok(!str_contains($r['headers']['content-security-policy'], 'allow-same-origin'), "I1 · בלי allow-same-origin · $what");
    eq('nosniff', $r['headers']['x-content-type-options'] ?? '', "I2 · nosniff · $what");
    eq('*', $r['headers']['access-control-allow-origin'] ?? '', "I4 · פתוח לקריאה · $what");
    eq([], $r['cookies'], "I7 · בלי עוגייה · $what");
}

test('תצוגה: האסימון ניתן רק למי שמורשה לקרוא את הפרויקט, ומפתח החתימה ב-secrets.json', function () {
    $t = http('GET', '/api.php?action=pv_token', ['user' => U_EDITOR]);
    eq(200, $t['status']);
    ok(preg_match('/^[0-9a-z]+\.[A-Za-z0-9_-]{43}$/', $t['json']['token']) === 1, 'צורת האסימון');
    ok($t['json']['exp'] - time() <= PV_TTL && $t['json']['exp'] - time() > PV_TTL - 30, 'קצר-מועד');
    eq('index.html', $t['json']['entry']);
    eq(['tablet', 'mobile'], array_column($t['json']['breakpoints'], 'id'), 'מצבי המכשיר מ-kol.json');
    ok(strlen((string)(secrets_load()['preview_key'] ?? '')) === 64, 'המפתח נשמר ב-data/secrets.json');
    ok(!str_contains((string)file_get_contents(KOL_ROOT . '/lib/preview.php'), secrets_load()['preview_key']), 'ולא בקוד');
    ok(!str_contains(json_encode(http('GET', '/api.php?action=dev-health', ['user' => U_OWNER])['json']), secrets_load()['preview_key']), 'ואינו חוזר לדפדפן');
});

test('תצוגה: בלי אסימון, עם אסימון שגוי או שפג — 403, ועדיין בארגז חול', function () {
    $good = pv_tok();
    [$exp, $sig] = explode('.', $good);
    $cases = [
        'ריק' => '', 'זבל' => 'abc', 'חתימה אחרת' => $exp . '.' . str_repeat('A', 43),
        'תפוגה שהוזזה' => base_convert((string)(time() + 900), 10, 36) . '.' . $sig,
        'פג תוקף (חתום כהלכה)' => pv_token_make(time() - 5),
        'רחוק מדי (חתום כהלכה)' => pv_token_make(time() + 86400),
    ];
    foreach ($cases as $name => $tok) {
        $r = pv_get('index.html', $tok);
        eq(403, $r['status'], $name);
        pv_isolated($r, "403 · $name");
        ok(!str_contains($r['body'], '<h1>'), "אין תוכן של הפרויקט · $name");
    }
    $direct = http('GET', '/preview/index.php');
    eq(403, $direct['status'], 'פנייה ישירה ל-index.php');
    eq(200, pv_get('index.html', $good)['status'], 'ביקורת חיובית: עם אסימון תקין — 200');
});

test('תצוגה: כל קובץ בנתיב שלו, סוג תוכן מרשימה סגורה, והגשר באותה שורה של <head>', function () {
    $r = pv_get('');
    eq(200, $r['status']);
    pv_isolated($r, 'שורש');
    eq('text/html; charset=utf-8', $r['headers']['content-type']);
    $src = pf_blob_get(pf_by_path('index.html')['sha']);
    eq(substr_count($src, "\n"), substr_count($r['body'], "\n"), 'ההזרקה אינה מזיזה אף שורה');
    ok(preg_match('#<head><script src="/preview/[^"]+/__kol/bridge\.js"></script>#', $r['body']) === 1, 'הגשר מוזרק אחרי <head>');
    $back = str_replace('<script crossorigin src=', '<script src=', (string)preg_replace('#<script src="/preview/[^"]+/__kol/bridge\.js"></script>#', '', $r['body'], 1));
    eq($src, $back, 'חוץ מהגשר ומ-crossorigin על <script src> — בייט בבייט');
    ok(str_contains($r['body'], '<script crossorigin src="js/page.js">'), 'crossorigin נוסף ל-<script src> — כדי ששגיאות יגיעו עם קובץ ושורה');
    pf_new('pv/co.html', '<script src=a.js crossorigin="anonymous"></script><script>var s = "<script>";</script><script type=module src=m.js></script>');
    $co = pv_get('pv/co.html')['body'];
    ok(str_ends_with($co, '<script src=a.js crossorigin="anonymous"></script><script>var s = "<script>";</script><script crossorigin type=module src=m.js></script>'), 'מי שכבר הגדיר crossorigin, וסקריפט בתוך הדף — אינם משתנים');
    eq($r['body'], pv_get('index.html')['body'], 'השורש הוא קובץ הכניסה');

    $css = pv_get('css/page.css');
    eq('text/css; charset=utf-8', $css['headers']['content-type']);
    eq(pf_blob_get(pf_by_path('css/page.css')['sha']), $css['body'], 'CSS — בייט בבייט');
    pv_isolated($css, 'css');
    eq('text/javascript; charset=utf-8', pv_get('js/page.js')['headers']['content-type']);
    eq(200, pv_get('css/page.css?kolv=1')['status'], 'מחרוזת שאילתה אינה חלק מהנתיב');
    $b = pv_get('__kol/bridge.js');
    eq('text/javascript; charset=utf-8', $b['headers']['content-type']);
    pv_isolated($b, 'הגשר');

    pf_new('pages/צור קשר.html', '<p>שלום</p>');
    $he = pv_get('pages/' . rawurlencode('צור קשר.html'));
    eq(200, $he['status'], 'נתיב בעברית ועם רווח');
    ok(str_ends_with($he['body'], '<p>שלום</p>'), 'בלי <head> — הגשר בתחילת המסמך');
    eq(200, pv_get('index.html', null, [], 'HEAD')['status'], 'HEAD');
    eq(405, pv_get('index.html', null, [], 'POST')['status'], 'רק קריאה');
});

test('תצוגה: x.php וכל סיומת לא מוכרת — כטקסט להורדה, ואינם מורצים', function () {
    $code = '<?php echo "RAN-" . (6 * 7); ?>';
    pf_new('pv/x.php', $code);
    $r = pv_get('pv/x.php');
    eq(200, $r['status']);
    eq($code, $r['body'], 'הקוד חוזר כמו שהוא');
    ok(!str_contains($r['body'], 'RAN-42'), 'לא הורץ');
    eq('application/octet-stream', $r['headers']['content-type']);
    ok(str_starts_with($r['headers']['content-disposition'] ?? '', 'attachment'), 'הורדה');
    pv_isolated($r, 'x.php');
    pf_new('pv/a.xyz', 'zz');
    eq('application/octet-stream', pv_get('pv/a.xyz')['headers']['content-type'], 'סיומת לא מוכרת');
    pf_new('pv/evil.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    $svg = pv_get('pv/evil.svg');
    eq('image/svg+xml', $svg['headers']['content-type']);
    pv_isolated($svg, 'svg עם סקריפט — מוגש, ובארגז חול');
});

test('תצוגה: נתיב שאינו בפרויקט — 404; אין טיפוס מחוץ לפרויקט (I5)', function () {
    ok(is_file(KOL_ROOT . '/api.php') && is_file(KOL_DATA_DIR . '/kol.sqlite'), 'ביקורת חיובית: הקבצים שמנסים להגיע אליהם קיימים');
    $tries = ['nope.html', '../api.php', '../../api.php', '..%2Fapi.php', '%2e%2e/api.php', '%2e%2e%2f%2e%2e%2fapi.php', '..\\api.php', 'css/../../api.php', 'css/../page.css',
        '../data/kol.sqlite', '../data/secrets.json', '/etc/passwd', '//etc/passwd', 'css//page.css', './index.html', 'css/', "index.html%00.png", 'INDEX.HTML', '__kol/other.js', '../lib/preview.php'];
    foreach ($tries as $p) {
        $r = pv_get($p);
        eq(404, $r['status'], "404 · $p");
        pv_isolated($r, "404 · $p");
        ok(!str_contains($r['body'], '<?php') && !str_contains($r['body'], 'SQLite') && !str_contains($r['body'], 'preview_key'), "לא דלף קובץ · $p");
    }
    pf_new('404.html', "<!doctype html>\n<title>אין</title><h1>העמוד לא נמצא</h1>");
    $r = pv_get('nope.html');
    eq(404, $r['status']);
    ok(str_contains($r['body'], 'העמוד לא נמצא') && str_contains($r['body'], '__kol/bridge.js'), 'ה-404.html של הפרויקט');
    pv_isolated($r, '404.html של הפרויקט');
});

test('תצוגה: מדיה מהספרייה ושמע מוגשים עם Range', function () {
    $r = pv_get('media/hero.png');
    eq(200, $r['status']);
    eq('image/png', $r['headers']['content-type']);
    eq('bytes', $r['headers']['accept-ranges']);
    pv_isolated($r, 'מדיה');
    $part = pv_get('media/hero.png', null, ['Range: bytes=1-3']);
    eq(206, $part['status']);
    eq(substr($r['body'], 1, 3), $part['body']);
    eq('bytes 1-3/' . strlen($r['body']), $part['headers']['content-range']);
    pv_isolated($part, '206');

    $bytes = random_bytes(5000);
    pf_new('media/audio/6.mp3', $bytes);
    $full = pv_get('media/audio/6.mp3');
    eq('audio/mpeg', $full['headers']['content-type']);
    ok($full['body'] === $bytes, 'שמע — בייט בבייט');
    $tail = pv_get('media/audio/6.mp3', null, ['Range: bytes=-100']);
    eq(206, $tail['status']);
    ok($tail['body'] === substr($bytes, -100), 'סוף הקובץ');
    $open = pv_get('media/audio/6.mp3', null, ['Range: bytes=4000-']);
    eq('bytes 4000-4999/5000', $open['headers']['content-range']);
    eq(416, pv_get('media/audio/6.mp3', null, ['Range: bytes=9000-'])['status'], 'טווח מחוץ לקובץ');
});

test('תצוגה: data/words.json נבנה מטבלת המילים ברגע הבקשה', function () {
    $now = now_iso();
    db()->prepare('INSERT INTO words (word, meaning, topics, audio, external_id, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute(['בדיקת-תצוגה', '<p>פירוש</p>', json_encode(['אוכל'], JSON_UNESCAPED_UNICODE), 'https://site.example/a.mp3', 'pv-test', $now, 'test']);
    $id = (int)db()->lastInsertId();
    try {
        $r = pv_get('data/words.json');
        eq(200, $r['status']);
        eq('application/json; charset=utf-8', $r['headers']['content-type']);
        pv_isolated($r, 'words.json');
        $j = json_decode($r['body'], true);
        $mine = array_values(array_filter($j['items'], fn($w) => $w['id'] === $id))[0] ?? null;
        ok($mine !== null, 'המילה שנוספה לטבלה מופיעה מיד');
        eq(['id', 'word', 'meaning', 'topics', 'audio'], array_keys($mine), 'השדות של 02 §8.1');
        eq('https://site.example/a.mp3', $mine['audio']);
        $topic = array_values(array_filter($j['topics'], fn($t) => $t['label'] === 'אוכל'))[0];
        eq([$topic['id']], $mine['topics'], 'הנושא לפי המזהה שלו');
    } finally {
        db()->prepare('DELETE FROM words WHERE id = ?')->execute([$id]);
    }
    $after = json_decode(pv_get('data/words.json')['body'], true);
    eq([], array_filter($after['items'], fn($w) => $w['id'] === $id), 'ונעלמת מיד כשנמחקה');
});

test('תצוגה: התשובה אינה תלויה בסשן — זהה עם זהות של בעלים ובלעדיה', function () {
    $anon = pv_get('index.html');
    $owner = pv_get('index.html', null, ['X-Test-User: ' . json_encode(U_OWNER), 'Cookie: kol_session=abc; platform_session=' . str_repeat('a', 64)]);
    eq($anon['body'], $owner['body']);
    eq(200, $owner['status']);
    eq([], $owner['cookies'], 'גם כשהגיעה עוגייה — לא נקבעת עוגייה');
    $src = (string)file_get_contents(KOL_ROOT . '/lib/preview.php') . file_get_contents(KOL_ROOT . '/preview/index.php');
    $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $src);
    foreach (['current_user', 'identity(', '$_COOKIE', 'setcookie', 'session_start', 'PLATFORM_USER'] as $bad) ok(!str_contains($code, $bad), "הקוד של התצוגה אינו נוגע ב-$bad");
    ok(in_array('preview/index.php', json_decode((string)file_get_contents(KOL_ROOT . '/app.json'), true)['public'], true), 'הכתובת רשומה ב-public של app.json');
});
