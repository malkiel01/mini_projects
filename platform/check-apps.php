<?php
/**
 * שער הפריסה של הרישום: כל app.json חייב להיות תקין.
 * רץ ב-deploy.yml לפני ההעלאה; app.json פגום עוצר את הפריסה, אחרת הכלי
 * פשוט היה נעלם מהדף בלי שאיש ישים לב.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/lib/registry.php';

$registry = registry_load();
foreach ($registry['errors'] as $e) {
    fwrite(STDERR, "::error::$e\n");
}

// תיקיית כלי בלי app.json — לא תופיע בדף. מסומנת כאזהרה, לא כשגיאה.
foreach (glob(REGISTRY_ROOT . '/*', GLOB_ONLYDIR) as $dir) {
    $name = basename($dir);
    if ($name === 'platform' || file_exists("$dir/app.json")) continue;
    if (file_exists("$dir/index.html") || file_exists("$dir/index.php")) {
        echo "::warning::$name: אין app.json — הכלי לא יופיע בדף\n";
    }
}

echo count($registry['apps']) . " אפליקציות רשומות\n";
exit($registry['errors'] ? 1 : 0);
