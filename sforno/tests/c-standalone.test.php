<?php
/** עצמאות ושערי הריפו — קריטריונים 15–16, ומבנה הקבצים (§3). */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$root = dirname(__DIR__);
$repo = dirname($root);

/** כל הקבצים בתיקייה לפי סיומות (בלי data/ ובלי team/docs). */
function t_files(string $dir, array $ext): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = str_replace('\\', '/', $f->getPathname());
        if (preg_match('~/(data|team|docs)/~', $p)) continue;
        if (in_array(strtolower($f->getExtension()), $ext, true)) $out[] = $p;
    }
    return $out;
}

// ── K15 ──────────────────────────────────────────────────────
$php = array_merge(t_files("$root/lib", ['php']), ["$root/api.php", "$root/cron.php"]);
$bad = [];
foreach ($php as $f) foreach (file($f) as $n => $l) if (preg_match('/(require|include)[^;]*\.\.\//', $l)) $bad[] = basename($f) . ':' . ($n + 1);
eq($bad, [], 'K15 אין require / include שעולה מחוץ לתיקייה (lib, api.php, cron.php)');
eq(sf_standalone_scan(), [], 'K15 והבדיקה במסך מסכימה');
$bad = [];
foreach (t_files($root, ['php', 'js']) as $f) if (str_contains((string)file_get_contents($f), 'mini_' . 'projects')) $bad[] = substr($f, strlen($root) + 1);
eq($bad, [], 'K15 אין נתיב קבוע של הריפו בקוד (php, js)');
$bad = [];
foreach (t_files($root, ['html', 'js']) as $f) if (str_contains((string)file_get_contents($f), '../')) $bad[] = substr($f, strlen($root) + 1);
eq($bad, [], 'K15 אין ../ ב-HTML וב-JS');
$bad = [];
foreach ($php as $f) {
    foreach (file($f) as $n => $l) {
        if (!preg_match('/^\s*(?:\$\w+\s*=\s*)?(require|include)(_once)?\b\s*(.+);/', $l, $m)) continue;
        if (!preg_match('~^(__DIR__ \. \'/[a-z0-9/._-]+\'|\$m\[\'file\'\])$~', trim($m[3]))) $bad[] = basename($f) . ':' . ($n + 1);
    }
}
eq($bad, [], 'כל require יחסי ל-__DIR__ בלבד');

// ── K16 ──────────────────────────────────────────────────────
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$repo/platform/check-apps.php") . ' 2>&1', $o1, $c1);
eq($c1, 0, 'K16 php platform/check-apps.php עובר' . ($c1 ? ': ' . implode(' | ', array_slice($o1, -3)) : ''));
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$repo/devloop/tools/sync.php") . ' --check 2>&1', $o2, $c2);
eq($c2, 0, 'K16 php devloop/tools/sync.php --check עובר' . ($c2 ? ': ' . implode(' | ', array_slice($o2, -3)) : ''));

// ── המבנה ────────────────────────────────────────────────────
foreach (['lib', 'tests', 'dev', 'data'] as $d) {
    $ht = "$root/$d/.htaccess";
    check("$d/.htaccess חוסם", is_file($ht) && str_contains((string)file_get_contents($ht), 'Require all denied'));
}
$app = json_decode((string)file_get_contents("$root/app.json"), true);
eq([$app['gate'] ?? null, $app['restricted'] ?? null, $app['version'] ?? null], [true, true, '0.8'], 'app.json: gate, restricted, version');
eq($app['public'] ?? null, ['cron.php', 'api.php?action=health|devapi-feed|devapi-comment|devapi-file|devapi-attach'], 'app.json: public — רק מה ש-§9 מתיר');
$publicActs = array_keys(array_filter(SF_ACTIONS, fn($s) => $s['who'] === 'public'));
eq($publicActs, ['health', 'devapi-feed', 'devapi-comment', 'devapi-file', 'devapi-attach'], 'הנתב: אותן פעולות ציבוריות כמו ב-app.json');
foreach (SF_ACTIONS as $a => $s) if (str_starts_with($a, 'dev-')) eq($s['who'], 'dev', "$a — רק למפתח");
foreach (SF_ACTIONS as $a => $s) if ($s['who'] === 'reader') check("$a (reader) — read-*, GET, ולא ב-public של app.json (השער מגביל לצוות בפיתוח)",
    str_starts_with($a, 'read-') && $s['m'] === 'GET' && !str_contains(implode(' ', $app['public'] ?? []), $a));
foreach (SF_ACTIONS as $a => $s) if (str_starts_with($a, 'content-')) eq($s['who'], 'staff', "$a — רק למנהל");
$items = json_decode((string)file_get_contents("$root/dev/open-items.json"), true);
eq(array_column($items['items'] ?? [], 'code'), array_map(fn($n) => sprintf('SF-%03d', $n), range(1, 11)), 'dev/open-items.json: מטלה לכל פרוסה, SF-001 עד SF-011');
$html = (string)file_get_contents("$root/index.html");
check('index.html: lang="he" dir="rtl"', str_contains($html, 'lang="he"') && str_contains($html, 'dir="rtl"'));
foreach (['DavidLibre-Regular.ttf', 'DavidLibre-Bold.ttf', 'OFL-davidlibre.txt'] as $f) check("הגופן החלופי: $f", is_file("$root/assets/fonts/$f"));

done();
