<?php
// הגבולות של האפליקציה, בבדיקה סטטית: עצמאית מהמעטפת, נתונים חסומים, ורישום תקין בפלטפורמה.
require __DIR__ . '/lib.php';

$root = dirname(__DIR__);
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($root) + 1);
    if (preg_match('#^(docs|team|data)/#', $rel)) continue;
    $files[$rel] = $f->getPathname();
}

// ── בלי require לקובץ מחוץ ל-appraisers/ ──
// lib/devloop.php הוא העותק המסונכרן של המודול המשותף; הוא לא טוען שום קובץ.
foreach ($files as $rel => $path) {
    if (!str_ends_with($rel, '.php') || str_starts_with($rel, 'tests/')) continue;
    $src = file_get_contents($path);
    preg_match_all('/\b(?:require|include)(?:_once)?\s*\(?\s*([^;]+);/', $src, $m);
    foreach ($m[1] as $expr) {
        check("$rel: טוען רק קבצים של האפליקציה — $expr", str_contains($expr, '__DIR__') && !preg_match('#\.\./\.\.|dirname\(__DIR__,\s*[2-9]#', $expr)
            && !(str_starts_with($rel, 'lib/') && str_contains($expr, "'/../") && !str_contains($expr, "'/../lib/")));
    }
    if ($rel === 'lib/devloop.php') continue;
    check("$rel: לא קורא קבצים מחוץ לתיקייה", !preg_match('#dirname\(__DIR__,\s*[2-9]\)|\.\./\.\./|/platform/|DOCUMENT_ROOT#', $src));
    check("$rel: declare(strict_types=1)", str_contains($src, 'declare(strict_types=1);'));
}

// ── בלי קישור "חזרה לכלים", ובלי שום כתובת מחוץ לתיקייה ──
foreach ($files as $rel => $path) {
    if (!preg_match('/\.(html|js|css)$/', $rel) || preg_match('#devloop\.(js|css)$#', $rel)) continue;
    $src = file_get_contents($path);
    check("$rel: אין קישור אל מחוץ לאפליקציה", !preg_match('#(href|src|action)\s*=\s*["\'](\.\./|/)#', $src) && !preg_match('#["\'`]\.\./#', $src));
    check("$rel: אין כתובת חיצונית (CDN, גופנים)", !preg_match('#https?://(?!www\.w3\.org/)#', $src));
}
$html = file_get_contents("$root/index.html");
check('index.html: עברית מימין לשמאל', str_contains($html, 'lang="he"') && str_contains($html, 'dir="rtl"'));

// ── app.json ──
$app = json_decode(file_get_contents("$root/app.json"), true);
eq($app['id'] ?? null, basename($root), 'app.json: id = שם התיקייה');
eq($app['gate'] ?? null, true, 'app.json: מחוברת לשער');
check('app.json: יש גרסה', preg_match('/^\d+\.\d+(\.\d+)?$/', (string)($app['version'] ?? '')) === 1);
$public = implode("\n", $app['public'] ?? []);
foreach (APR_TOKEN_ACTIONS as $a) check("app.json: $a ב-public (Claude ניגש בלי להתחבר)", (bool)preg_match('/api\.php\?action=[^\n]*\b' . preg_quote($a, '/') . '\b/', $public));
preg_match('/api\.php\?action=([^\n]*)/', $public, $m);
eq(array_values(array_diff(explode('|', $m[1] ?? ''), APR_TOKEN_ACTIONS)), [], 'app.json: רק פעולות האסימון ציבוריות — שום פעולה אחרת');
check('app.json: שום דף או נכס אינו ציבורי', !preg_match('/\.(html|js|css)|\*\*/', $public));

// ── הנתונים והקוד חסומים מהדפדפן ──
foreach (['data', 'lib', 'tests', 'dev'] as $d) {
    check("$d/.htaccess חוסם", is_file("$root/$d/.htaccess") && str_contains(file_get_contents("$root/$d/.htaccess"), 'Require all denied'));
}
$tracked = array_filter(array_map('basename', glob("$root/data/{,.}*", GLOB_BRACE) ?: []), fn($n) => !in_array($n, ['.', '..', '.htaccess'], true));
check('בתיקיית data/ שבריפו — רק .htaccess', !array_filter($tracked, fn($n) => !preg_match('/^(app\.sqlite|devloop)/', $n)), array_values($tracked));

// ── העוגייה: שם משלה ──
eq(APR_COOKIE, 'apr_session', 'עוגייה בשם משלה');

// ── המיגרציות: רצופות, ושמות תקינים ──
eq(array_keys(apr_migration_files()), range(1, apr_schema_expected()), 'מיגרציות רצופות מ-001');

done();
