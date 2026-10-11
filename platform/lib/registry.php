<?php
/**
 * רישום האפליקציות: כל תיקייה בשורש שיש בה app.json היא אפליקציה.
 *
 * אין שלב בנייה ואין רשימה מרכזית — הקבצים נסרקים בכל בקשה. כך כלי חדש
 * נכנס לפלטפורמה בלי לגעת בקובץ משותף, ושני צ'אטים לא מתנגשים.
 */

const REGISTRY_ROOT = __DIR__ . '/../..';

/** הקטגוריות, בסדר ההצגה. */
function registry_categories(): array
{
    $raw = file_get_contents(__DIR__ . '/../categories.json');
    $list = json_decode((string)$raw, true);
    return is_array($list) ? $list : [];
}

/**
 * כל האפליקציות התקינות, וכל השגיאות שנמצאו בדרך.
 * אפליקציה פגומה לא מפילה את הדף — היא נשמטת ונרשמת ב-errors.
 *
 * @return array{apps: array, errors: string[]}
 */
function registry_load(): array
{
    $categoryIds = array_column(registry_categories(), 'id');
    $apps = [];
    $errors = [];

    foreach (glob(REGISTRY_ROOT . '/*/app.json') ?: [] as $file) {
        $folder = basename(dirname($file));
        $app = json_decode((string)file_get_contents($file), true);
        $problem = registry_validate($folder, $app, $categoryIds);
        if ($problem !== null) {
            $errors[] = "$folder/app.json: $problem";
            continue;
        }
        $apps[] = [
            'id'          => $app['id'],
            'name'        => $app['name'],
            'icon'        => $app['icon'],
            'description' => $app['description'],
            'category'    => $app['category'],
            'href'        => $folder . '/' . ($app['entry'] ?? ''),
            'install'     => registry_install($folder, $app['install'] ?? []),
            'links'       => array_map(
                fn($l) => ['label' => $l['label'], 'href' => $folder . '/' . $l['href']],
                $app['links'] ?? []
            ),
        ];
    }

    // סדר קבוע ובלתי תלוי במערכת הקבצים — לפי השם.
    usort($apps, fn($a, $b) => strcmp($a['name'], $b['name']));
    return ['apps' => $apps, 'errors' => $errors];
}

/**
 * מה אפשר לעשות עם האפליקציה מחוץ לדף (שלב 5):
 *   pwa     — ניתנת להתקנה כאפליקציה נפרדת. ברירת מחדל: יש לה manifest.webmanifest
 *   offline — "הורדת המשפחה" שומרת אותה במכשיר, לשימוש בלי רשת
 *   apk     — קישור להורדת APK (https, או נתיב יחסי לתיקיית הכלי)
 *   store   — קישור לדף ב-Google Play
 */
function registry_install(string $folder, array $install): array
{
    $apk = $install['apk'] ?? null;
    if (is_string($apk) && !str_starts_with($apk, 'https://')) $apk = "$folder/$apk";
    return [
        'pwa'     => $install['pwa'] ?? is_file(REGISTRY_ROOT . "/$folder/manifest.webmanifest"),
        'offline' => $install['offline'] ?? false,
        'apk'     => $apk,
        'store'   => $install['store'] ?? null,
    ];
}

/** null כשהרישום תקין, אחרת תיאור הבעיה. */
function registry_validate(string $folder, $app, array $categoryIds): ?string
{
    if (!is_array($app)) return 'JSON לא תקין';
    if (($app['id'] ?? null) !== $folder) return "id חייב להיות שם התיקייה ('$folder')";
    foreach (['name', 'icon', 'description', 'category'] as $key) {
        if (!is_string($app[$key] ?? null) || trim($app[$key]) === '') return "חסר '$key'";
    }
    if (!in_array($app['category'], $categoryIds, true)) return "קטגוריה לא מוכרת: '{$app['category']}'";
    if (isset($app['entry']) && !registry_safe_path($app['entry'])) return "entry לא תקין";
    if (isset($app['gate']) && !is_bool($app['gate'])) return "gate חייב להיות true או false";
    $install = $app['install'] ?? [];
    if (!is_array($install)) return 'install חייב להיות אובייקט';
    foreach (['pwa', 'offline'] as $k) {
        if (isset($install[$k]) && !is_bool($install[$k])) return "install.$k חייב להיות true או false";
    }
    if (isset($install['apk']) && !preg_match('#^https://#', (string)$install['apk']) && !registry_safe_path($install['apk'])) {
        return 'install.apk: כתובת https או נתיב יחסי';
    }
    if (isset($install['store']) && !preg_match('#^https://play\.google\.com/#', (string)$install['store'])) {
        return 'install.store: קישור ל-play.google.com';
    }
    foreach ($app['public'] ?? [] as $p) {
        if (!is_string($p) || !registry_safe_path(explode('?', $p, 2)[0])) return 'נתיב לא תקין ב-public';
    }
    foreach ($app['links'] ?? [] as $l) {
        if (!is_string($l['label'] ?? null) || !is_string($l['href'] ?? null) || !registry_safe_path($l['href'])) {
            return 'קישור לא תקין ב-links';
        }
    }
    return null;
}

/** נתיב יחסי בתוך תיקיית הכלי בלבד: בלי סכמה, בלי '..' ובלי '/' בהתחלה. */
function registry_safe_path($path): bool
{
    return is_string($path)
        && !preg_match('#^[a-z][a-z0-9+.-]*:#i', $path)
        && !str_starts_with($path, '/')
        && !in_array('..', explode('/', $path), true);
}
