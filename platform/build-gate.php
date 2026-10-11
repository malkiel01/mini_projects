<?php
/**
 * בונה את השער בזמן הפריסה, לכל אפליקציה שב-app.json שלה "gate": true.
 *
 *   php platform/build-gate.php --root=<DEPLOY_PATH>
 *
 * רץ ב-deploy.yml על העותק שבמכונת הפריסה, לפני rsync — הקבצים שנוצרים לא
 * נכנסים לגיט ואינם נוגעים בתיקיות של צ'אטים אחרים בריפו:
 *   <app>/.user.ini   auto_prepend_file → platform/gate-prepend.php (נתיב מוחלט בשרת)
 *   <app>/.htaccess   בלוק בראש הקובץ: כל קובץ שאינו PHP עובר דרך platform/gate.php
 *
 * ‏--root הוא הנתיב בשרת (DEPLOY_PATH). נתיב ה-URL נגזר ממנו: מה שאחרי public_html.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/lib/registry.php';

$opts = getopt('', ['root:']);
$serverRoot = rtrim((string)($opts['root'] ?? ''), '/');
if ($serverRoot === '' || $serverRoot[0] !== '/') {
    fwrite(STDERR, "::error::build-gate: חסר --root (DEPLOY_PATH)\n");
    exit(1);
}
if (!preg_match('#/public_html(/.*)?$#', $serverRoot, $m)) {
    fwrite(STDERR, "::error::build-gate: לא ניתן לגזור את נתיב ה-URL מ-$serverRoot\n");
    exit(1);
}
$base = rtrim($m[1] ?? '', '/') . '/';                    // /mini_projects/
$prepend = "$serverRoot/platform/gate-prepend.php";

// סיומות שעוברות דרך השער. PHP — לא: עליו שומר ה-prepend.
$ext = 'html?|m?js|css|json|webmanifest|map|svg|png|jpe?g|gif|webp|ico|woff2?|ttf|otf|pdf|zip|txt|csv'
     . '|mp4|webm|mov|mp3|m3u8|ts|wasm|glb|gltf';

$gated = 0;
foreach (glob(REGISTRY_ROOT . '/*/app.json') as $file) {
    $dir = dirname($file);
    $app = basename($dir);
    $cfg = json_decode((string)file_get_contents($file), true);
    if (empty($cfg['gate'])) continue;

    if (file_exists("$dir/.user.ini")) {
        fwrite(STDERR, "::error::build-gate: ל-$app כבר יש .user.ini — צריך למזג ידנית\n");
        exit(1);
    }
    file_put_contents("$dir/.user.ini",
        "; נוצר בפריסה (platform/build-gate.php) — השער של הפלטפורמה.\nauto_prepend_file = \"$prepend\"\n");

    $gate = "{$base}platform/gate.php?__gate_app=$app&__gate_path=";
    $block = <<<HT
        # >>> platform-gate — נוצר בפריסה (platform/build-gate.php). לא לערוך כאן.
        <IfModule mod_rewrite.c>
            RewriteEngine On
            # תיקייה עם index.html (ובלי index.php) — הדף שלה עובר דרך השער.
            RewriteCond %{REQUEST_URI} /$
            RewriteCond %{REQUEST_FILENAME} -d
            RewriteCond %{REQUEST_FILENAME}/index.html -f
            RewriteCond %{REQUEST_FILENAME}/index.php !-f
            RewriteRule ^((?:.*/)?)$ {$gate}\$1index.html [L,QSA]
            # כל קובץ קיים שאינו PHP.
            RewriteCond %{REQUEST_FILENAME} -f
            RewriteRule ^(.+\.(?:$ext))$ {$gate}\$1 [L,QSA,NC]
        </IfModule>
        # .user.ini מכיל נתיב מוחלט בשרת — לא להגיש אותו (וגם לא את הקובץ הזה).
        <FilesMatch "^\.(htaccess|user\.ini)$">
            <IfModule mod_authz_core.c>
                Require all denied
            </IfModule>
        </FilesMatch>
        # <<< platform-gate

        HT;
    $existing = is_file("$dir/.htaccess") ? (string)file_get_contents("$dir/.htaccess") : '';
    file_put_contents("$dir/.htaccess", $block . $existing);

    // תת-תיקייה עם RewriteEngine משלה מבטלת את הכללים של התיקייה שמעליה.
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getFilename() === '.htaccess' && $f->getPath() !== $dir
            && preg_match('/RewriteEngine\s+On/i', (string)file_get_contents($f->getPathname()))) {
            echo "::warning::build-gate: {$f->getPathname()} מגדיר RewriteEngine — קבצים סטטיים בו לא עוברים בשער\n";
        }
    }
    echo "שער: $app\n";
    $gated++;
}
echo "השער נבנה ל-$gated אפליקציות (בסיס $base)\n";
