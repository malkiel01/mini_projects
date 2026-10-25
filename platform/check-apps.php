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

// תת-קטגוריות (subcategories.json): אב שאינו קטגוריה — שגיאה; אפליקציה שלא קיימת — אזהרה
$cats = array_column(registry_categories(), 'id');
$ids = array_column($registry['apps'], 'id');
$raw = json_decode((string)@file_get_contents(__DIR__ . '/subcategories.json'), true);
if (!is_array($raw)) $registry['errors'][] = 'platform/subcategories.json: JSON לא תקין';
$subs = registry_subcategories();
if (is_array($raw) && count($subs) !== count($raw)) $registry['errors'][] = 'platform/subcategories.json: קבוצה בלי id / parent / name / apps';
foreach ($subs as $s) {
    if (!in_array($s['parent'], $cats, true)) $registry['errors'][] = "subcategories.json: {$s['id']} — קטגוריית אב לא מוכרת '{$s['parent']}'";
    foreach (array_diff($s['apps'], $ids) as $missing) echo "::warning::subcategories.json: {$s['id']} — אין אפליקציה '$missing'\n";
}
foreach (array_slice($registry['errors'], count(registry_load()['errors'])) as $e) fwrite(STDERR, "::error::$e\n");

echo count($registry['apps']) . " אפליקציות רשומות\n";
exit($registry['errors'] ? 1 : 0);
