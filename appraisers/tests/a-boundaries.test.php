<?php
// הגבולות של האפליקציה, בבדיקה סטטית — 01-A3, 01-A4, 01-A5: עצמאית מהמעטפת, נתונים חסומים, רישום תקין.
require __DIR__ . '/lib.php';

$root = dirname(__DIR__);
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($root) + 1);
    if (preg_match('#^(docs|team|data)/#', $rel)) continue;
    $files[$rel] = $f->getPathname();
}
$php = array_filter(array_keys($files), fn($r) => str_ends_with($r, '.php'));
check('יש קובצי PHP לבדוק', count($php) >= 15);

// ── 01-A3: אין require / include לנתיב שיוצא מ-appraisers/ ──
foreach ($php as $rel) {
    if ($rel === 'tests/a-boundaries.test.php') continue;   // הקובץ הזה מכיל את הביטויים עצמם
    $src = file_get_contents($files[$rel]);
    // מסירים הערות ומחרוזות-תיעוד כדי לבדוק קוד בלבד
    $code = implode('', array_map(fn($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all($src)));
    preg_match_all('/\b(?:require|include)(?:_once)?\b\s*\(?\s*([^;]+);/', $code, $m);
    $depth = substr_count($rel, '/');
    foreach ($m[1] as $expr) {
        $up = substr_count($expr, '/..') + (preg_match('/dirname\(__DIR__(?:,\s*(\d+))?\)/', $expr, $d) ? (int)($d[1] ?? 1) : 0);
        check("01-A3 $rel: טוען רק מתוך האפליקציה — $expr", str_contains($expr, '__DIR__') && $up <= $depth && !preg_match('/\$_|getenv|DOCUMENT_ROOT/', $expr));
    }
    if ($rel === 'lib/devloop.php') continue;   // העותק המסונכרן של המודול המשותף; אינו טוען שום קובץ
    check("01-A3 $rel: אין נתיב קובץ שיוצא מהתיקייה", !preg_match('#dirname\(__DIR__,\s*[2-9]\)|\.\./\.\./|DOCUMENT_ROOT#', $code)
        && !(str_starts_with($rel, 'lib/') === false && !str_starts_with($rel, 'tests/') && str_contains($code, "'/../")));
    if (!str_ends_with($rel, '.test.php')) check("$rel: declare(strict_types=1)", str_contains($src, 'declare(strict_types=1);'));
}

// ── 01-A4: אין ב-HTML וב-JS קישור שמתחיל ב-../, ואין קישור "חזרה לכלים" ──
$front = array_filter(array_keys($files), fn($r) => preg_match('/\.(html|js|css|webmanifest)$/', $r) && !preg_match('#devloop\.(js|css)$#', $r));
check('יש קובצי ממשק לבדוק', count($front) >= 3);
foreach ($front as $rel) {
    $src = file_get_contents($files[$rel]);
    check("01-A4 $rel: אין ../", !str_contains($src, '../'));
    check("01-A4 $rel: אין קישור לשורש האתר", !preg_match('#(href|src|action)\s*=\s*["\']/#', $src));
    check("$rel: אין כתובת חיצונית (CDN, גופנים)", !preg_match('#https?://(?!www\.w3\.org/)#', $src));
    check("$rel: אין \"חזרה לכלים\"", !preg_match('/חזרה לכלים|לכלים הדיגיטליים<\/a>/u', $src));
}
$html = file_get_contents("$root/index.html");
check('index.html: עברית מימין לשמאל', str_contains($html, 'lang="he"') && str_contains($html, 'dir="rtl"'));
preg_match_all('#(?:src|href)="((?:assets/|manifest)[^"?]+)#', $html, $m);
foreach ($m[1] as $asset) check("index.html: $asset קיים", is_file("$root/$asset"));

// ── 01-A5: תחביר ורישום ──
foreach ($php as $rel) {
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($files[$rel]) . ' 2>&1', $out, $rc);
    check("01-A5 php -l $rel", $rc === 0);
}
$app = json_decode(file_get_contents("$root/app.json"), true);
eq($app['id'] ?? null, basename($root), 'app.json: id = שם התיקייה');
eq([$app['gate'] ?? null, $app['restricted'] ?? null], [true, true], 'app.json: מחוברת לשער, וחסומה כברירת מחדל');
check('app.json: גרסה', preg_match('/^\d+\.\d+(\.\d+)?$/', (string)($app['version'] ?? '')) === 1);
eq($app['public'] ?? null, ['manifest.webmanifest', 'cron.php', 'api.php?action=health|devapi-feed|devapi-comment|devapi-file|devapi-attach'], 'app.json: public בדיוק לפי האפיון (§10)');

// כל פעולה ציבורית ב-app.json היא public / token בטבלת ההרשאות — ולהפך
preg_match('/api\.php\?action=(.*)$/', $app['public'][2], $pm);
$open = array_keys(array_filter(APR_ACCESS, fn($r) => $r === 'public' || $r === 'token'));
sort($open);
$listed = explode('|', $pm[1]);
sort($listed);
eq($listed, $open, 'הפעולות הציבוריות ב-app.json = הפעולות בלי משתמש ב-lib/access.php');

// ── טבלת ההרשאות שלמה: לכל פעולה יש מימוש, ולכל מימוש — שורה בטבלה ──
$router = file_get_contents("$root/lib/app.php");
preg_match_all("/^\s+'([a-z][a-z-]+)'\s+=>/m", substr($router, strpos($router, 'function apr_dispatch')), $arms);
$arms = array_unique($arms[1]);
eq(array_values(array_diff(array_keys(APR_ACCESS), $arms)), [], 'לכל פעולה בטבלת ההרשאות יש מימוש בנתב');
eq(array_values(array_diff($arms, array_keys(APR_ACCESS))), [], 'ואין בנתב פעולה בלי שורה בטבלת ההרשאות');
eq(array_values(array_diff(APR_READS, array_keys(APR_ACCESS))), [], 'כל פעולת קריאה רשומה בטבלה');

// ── הנתונים והקוד חסומים מהדפדפן ──
foreach (['data', 'lib', 'tests', 'dev'] as $d) {
    check("$d/.htaccess חוסם", is_file("$root/$d/.htaccess") && str_contains(file_get_contents("$root/$d/.htaccess"), 'Require all denied'));
}
exec('git -C ' . escapeshellarg($root) . ' ls-files data 2>/dev/null', $tracked);
eq($tracked, ['data/.htaccess'], 'בגיט, תחת data/ — רק .htaccess');
eq(APR_COOKIE, 'apr_session', 'עוגייה בשם משלה');

// ── השגיאות: לכל קוד נוסח בעברית, בלי פרטים טכניים ──
foreach (APR_ERRORS as $code => [$status, $msg]) {
    check("שגיאה $code: מצב HTTP ונוסח בעברית", $status >= 400 && $status < 600 && preg_match('/[א-ת]/u', $msg) === 1 && !preg_match('/SQL|\.php|[a-z]\/[a-z]|Exception/i', $msg));
}
preg_match_all("/apr_fail\('([a-z_]+)'\)|new AprError\('([a-z_]+)'/", implode('', array_map(fn($r) => file_get_contents($files[$r]), array_filter($php, fn($r) => !str_starts_with($r, 'tests/')))), $used);
$used = array_unique(array_filter(array_merge($used[1], $used[2])));
eq(array_values(array_diff($used, array_keys(APR_ERRORS), ['devloop'])), [], 'כל קוד שגיאה שבשימוש מוגדר ב-lib/errors.php');

done();
