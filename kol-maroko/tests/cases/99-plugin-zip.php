<?php
/**
 * T1 — ⬇️ הורדת התוסף (lib/plugin_zip.php): רק קבצים רגילים מתוך wordpress-plugin/kol-maroko/, תחת kol-maroko/ ב-ZIP;
 * בעל האתר בלבד (המטריצה ב-21-roles.php מכסה את שאר התפקידים).
 */

declare(strict_types=1);

require_once KOL_ROOT . '/lib/plugin_zip.php';

test('T1 plugin_zip: ההורדה — בעל האתר מקבל ZIP שזהה בייט-בייט לתיקיית התוסף, תחת kol-maroko/, עם הגרסה; בעל העמוד — 403', function () {
    $res = http('GET', '/api.php?action=plugin_zip', ['user' => U_OWNER]);
    eq(200, $res['status'], 'בעל האתר');
    eq('application/zip', $res['headers']['content-type'] ?? '');
    $ver = plugin_zip_version();
    ok(preg_match('/^\d+\.\d+/', $ver) === 1, "גרסה מכותרת התוסף ($ver)");
    ok(str_contains($res['headers']['content-disposition'] ?? '', "kol-maroko-$ver.zip"), 'שם הקובץ עם הגרסה');
    eq($ver, $res['headers']['x-kol-plugin-version'] ?? '');
    $tmp = tempnam(sys_get_temp_dir(), 'kolplugt');
    file_put_contents($tmp, $res['body']);
    $z = new ZipArchive();
    eq(true, $z->open($tmp, ZipArchive::RDONLY), 'ZIP תקין');
    $got = [];
    for ($i = 0; $i < $z->numFiles; $i++) { $n = $z->getNameIndex($i); if (!str_ends_with($n, '/')) $got[$n] = $z->getFromIndex($i); }
    $z->close(); @unlink($tmp);
    $want = [];
    foreach (glob(KOL_ROOT . '/wordpress-plugin/kol-maroko/*') as $f) if (is_file($f)) $want['kol-maroko/' . basename($f)] = file_get_contents($f);
    ksort($got); ksort($want);
    eq(array_keys($want), array_keys($got), 'כל קובצי התוסף, ורק הם');
    foreach ($want as $k => $v) ok($got[$k] === $v, "$k — זהה בייט-בייט");
    eq(403, http('GET', '/api.php?action=plugin_zip', ['user' => U_PAGE])['status'], 'בעל העמוד — 403');
    eq(403, http('GET', '/api.php?action=plugin_zip', ['user' => U_EDITOR])['status'], 'עורך — 403');
});

test('T1 plugin_zip: רק קבצים רגילים מתוך התיקייה — לא קישור, לא קובץ נסתר, לא תיקייה שהיא קישור; תיקייה חסרה — 503', function () {
    $base = sys_get_temp_dir() . '/kol-plug-' . bin2hex(random_bytes(4));
    $d = "$base/kol-maroko"; $out = "$base/outside";
    mkdir("$d/sub", 0775, true); mkdir($out, 0775, true);
    file_put_contents("$d/kol-maroko.php", "<?php\n/**\n * Plugin Name: x\n * Version: 9.8.7\n */\n");
    file_put_contents("$d/sub/a.php", 'a');
    file_put_contents("$d/.htaccess", 'deny');
    file_put_contents("$d/sub/.DS_Store", 'x');
    file_put_contents("$out/secret.txt", 'SECRET');
    symlink("$out/secret.txt", "$d/link.txt");
    symlink($out, "$d/outdir");
    try {
        eq(['kol-maroko/kol-maroko.php', 'kol-maroko/sub/a.php'], array_keys(plugin_zip_files($d)));
        eq('9.8.7', plugin_zip_version($d));
        $zip = "$base/p.zip";
        eq([2, '9.8.7'], plugin_zip_build($zip, $d));
        $z = new ZipArchive(); $z->open($zip, ZipArchive::RDONLY);
        $all = ''; for ($i = 0; $i < $z->numFiles; $i++) $all .= $z->getNameIndex($i) . '=' . $z->getFromIndex($i) . "\n";
        $z->close();
        ok(!str_contains($all, 'SECRET') && !str_contains($all, 'deny'), 'שום דבר מחוץ לתיקייה ושום קובץ נסתר');
        eq(503, throws(fn() => plugin_zip_files("$base/missing")), 'תיקייה חסרה');
        unlink("$d/kol-maroko.php");
        eq(500, throws(fn() => plugin_zip_files($d)), 'בלי kol-maroko.php');
    } finally {
        rrmdir($base);
    }
});
