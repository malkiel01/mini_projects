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
            // אייקון מצויר (SVG) בתיקיית הכלי — מוצג במקום האמוג'י; האמוג'י נשאר לגיבוי ולמקומות טקסט
            'icon_svg'    => isset($app['icon_svg']) ? registry_href($folder, $app['icon_svg']) : null,
            'description' => $app['description'],
            'restricted'  => $app['restricted'] ?? false,
            // תוסף (plugin): רכיב משותף לאפליקציות — רק למנהל, באזור "🧩 תוספים" ולא ברשימת הכלים
            'plugin'      => $app['plugin'] ?? false,
            'gate'        => $app['gate'] ?? false,
            'sso_returns' => array_values($app['sso']['return'] ?? []),
            'provision_key_url' => $app['provision']['key_url'] ?? null,
            'category'    => !empty($app['plugin']) ? PLUGIN_CATEGORY : $app['category'],
            'href'        => registry_href($folder, $app['entry'] ?? ''),
            'external'    => registry_is_external($app['entry'] ?? ''),
            'install'     => registry_install($folder, $app['install'] ?? []),
            'links'       => array_map(
                fn($l) => ['label' => $l['label'], 'href' => registry_href($folder, $l['href'])],
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
 *   release — "תיקיית החנות": Release קבוע ב-GitHub (<app>-store) שמתעדכן בכל בנייה. ממנו נגזרים:
 *             APK (אם apk לא הוגדר — <app>.apk), AAB לחנות (אותו שם, .aab), store-listing.zip
 *             (טקסטים, תמונות ותשובות למדיניות של Play Console) ו-assetlinks.json (מאוחד בפריסה לשורש
 *             הדומיין — platform/tools/assetlinks.php). AAB וקובץ ההגדרות מוצגים רק למנהל.
 *             הקבצים מוגשים מהשרת שלנו: הפריסה מעתיקה כל Release ל-platform/downloads/<app>/
 *             (platform/tools/store-mirror.sh) עם meta.json — גרסה וחבילה. בלי העותק — מ-GitHub.
 */
/**
 * זריעה ראשונית של קבוצות (lib/access.php, groups_seed): platform/subcategories.json — {id, parent, name, icon,
 * apps}. כל קבוצה נזרעת למסד פעם אחת, עם מיקום לכל אפליקציה שבה; מאז — הקבוצות והמיקומים בניהול. קבוצה פגומה נשמטת.
 * @return list<array{id:string, parent:string, name:string, icon:string, apps:string[]}>
 */
function registry_subcategories(): array
{
    $list = json_decode((string)@file_get_contents(__DIR__ . '/../subcategories.json'), true);
    $out = [];
    foreach (is_array($list) ? $list : [] as $s) {
        if (!is_array($s) || !is_string($s['id'] ?? null) || !is_string($s['parent'] ?? null)
            || !is_string($s['name'] ?? null) || !is_array($s['apps'] ?? null)) continue;
        $out[] = ['id' => $s['id'], 'parent' => $s['parent'], 'name' => $s['name'],
            'icon' => is_string($s['icon'] ?? null) ? $s['icon'] : '📁',
            'apps' => array_values(array_filter($s['apps'], 'is_string'))];
    }
    return $out;
}

/** הקטגוריה הפנימית של תוספים — לא בטבלת הקטגוריות, ולא מוצגת ברשימת הכלים. */
const PLUGIN_CATEGORY = 'plugins';

const REGISTRY_RELEASES = 'https://github.com/malkiel01/mini_projects/releases/download/';
const REGISTRY_DOWNLOADS = 'platform/downloads';

function registry_install(string $folder, array $install): array
{
    $apk = $install['apk'] ?? null;
    if (is_string($apk) && !str_starts_with($apk, 'https://')) $apk = "$folder/$apk";
    $store = null;
    $android = null;
    if (is_string($install['release'] ?? null)) {
        $base = REGISTRY_RELEASES . $install['release'] . '/';
        $stem = preg_replace('/\.apk$/i', '', basename((string)parse_url($apk ?? "$folder.apk", PHP_URL_PATH)));
        // העותק בשרת (store-mirror.sh): קישורים אצלנו, ומה שצריך כדי לדעת אם מותקנת ובאיזו גרסה
        $local = REGISTRY_DOWNLOADS . "/$folder/";
        $meta = json_decode((string)@file_get_contents(REGISTRY_ROOT . "/{$local}meta.json"), true);
        if (is_array($meta) && is_file(REGISTRY_ROOT . "/$local$stem.apk")) {
            $base = $local;
            $apk = "$local$stem.apk";
            $android = [
                'package' => is_string($meta['package'] ?? null) ? $meta['package'] : null,
                'version' => is_int($meta['versionCode'] ?? null) ? $meta['versionCode'] : null,
            ];
        }
        $apk ??= "$base$stem.apk";
        $store = [
            'release'     => $install['release'],
            'aab'         => "$base$stem.aab",
            'listing'     => "{$base}store-listing.zip",
            'assetlinks'  => "{$base}assetlinks.json",
        ];
    }
    return [
        'pwa'     => $install['pwa'] ?? is_file(REGISTRY_ROOT . "/$folder/manifest.webmanifest"),
        'offline' => $install['offline'] ?? false,
        'apk'     => $apk,
        'android' => $android,
        'store'   => $install['store'] ?? null,
        'release' => $store,
    ];
}

/** null כשהרישום תקין, אחרת תיאור הבעיה. */
function registry_validate(string $folder, $app, array $categoryIds): ?string
{
    if (!is_array($app)) return 'JSON לא תקין';
    if (($app['id'] ?? null) !== $folder) return "id חייב להיות שם התיקייה ('$folder')";
    if (isset($app['plugin']) && !is_bool($app['plugin'])) return 'plugin חייב להיות true או false';
    $plugin = !empty($app['plugin']);
    // לתוסף אין קטגוריה — הוא לא ברשימת הכלים
    foreach ($plugin ? ['name', 'icon', 'description'] : ['name', 'icon', 'description', 'category'] as $key) {
        if (!is_string($app[$key] ?? null) || trim($app[$key]) === '') return "חסר '$key'";
    }
    if (!$plugin && !in_array($app['category'], $categoryIds, true)) return "קטגוריה לא מוכרת: '{$app['category']}'";
    // תוסף בלי שער היה פתוח לכל העולם — והוא אמור להיות רק למנהל
    if ($plugin && empty($app['gate'])) return 'תוסף (plugin) חייב "gate": true — רק המנהל נכנס אליו';
    if (isset($app['icon_svg']) && (!registry_safe_path($app['icon_svg']) || !preg_match('/\.(svg|png|webp)$/i', (string)$app['icon_svg']))) {
        return 'icon_svg: נתיב יחסי לקובץ ‎.svg / ‎.png / ‎.webp בתיקיית הכלי';
    }
    if (isset($app['entry']) && !registry_safe_path($app['entry']) && !registry_is_external($app['entry'])) {
        return "entry: נתיב יחסי בתיקייה, או כתובת https של אפליקציה חיצונית";
    }
    if (registry_is_external($app['entry'] ?? '') && !empty($app['gate'])) {
        return 'אפליקציה חיצונית (entry ב-https) לא יכולה להיות מאחורי השער';
    }
    if (isset($app['gate']) && !is_bool($app['gate'])) return "gate חייב להיות true או false";
    if (isset($app['restricted']) && !is_bool($app['restricted'])) return "restricted חייב להיות true או false";
    // כניסה מאוחדת: "sso": {"return": ["https://…/sso/callback"]} — כתובות החזרה המותרות, התאמה מדויקת.
    if (isset($app['sso'])) {
        $ret = $app['sso']['return'] ?? null;
        if (!is_array($ret) || !$ret || count($ret) > 10) return 'sso.return: רשימה של 1–10 כתובות';
        foreach ($ret as $u) {
            if (!is_string($u) || !preg_match('#^https://[^/?\#@\s]+(/[^\#\s]*)?$#i', $u)) return "sso.return: כתובת https מלאה, בלי # ($u)";
        }
    }
    // הזמנת משתמשים מהאפליקציה (docs/sso.md, סעיף 6): "provision": {"key_url": "https://…"} — המפתח הציבורי שלה.
    if (isset($app['provision'])) {
        $k = $app['provision']['key_url'] ?? null;
        if (!is_string($k) || !preg_match('#^https://[^/?\#@\s]+(/[^\#\s]*)?$#i', $k)) return 'provision.key_url: כתובת https מלאה, בלי #';
    }
    $install = $app['install'] ?? [];
    if (!is_array($install)) return 'install חייב להיות אובייקט';
    foreach (['pwa', 'offline', 'banner'] as $k) {
        if (isset($install[$k]) && !is_bool($install[$k])) return "install.$k חייב להיות true או false";
    }
    if (isset($install['apk']) && !preg_match('#^https://#', (string)$install['apk']) && !registry_safe_path($install['apk'])) {
        return 'install.apk: כתובת https או נתיב יחסי';
    }
    if (isset($install['release']) && !preg_match('/^[a-z0-9][a-z0-9-]*-store$/', (string)$install['release'])) {
        return 'install.release: שם ה-Release של תיקיית החנות, <app>-store';
    }
    if (isset($install['store']) && !preg_match('#^https://play\.google\.com/#', (string)$install['store'])) {
        return 'install.store: קישור ל-play.google.com';
    }
    foreach ($app['public'] ?? [] as $p) {
        if (!is_string($p) || !registry_safe_path(explode('?', $p, 2)[0])) return 'נתיב לא תקין ב-public';
    }
    foreach ($app['links'] ?? [] as $l) {
        if (!is_string($l['label'] ?? null) || !is_string($l['href'] ?? null)
            || (!registry_safe_path($l['href']) && !registry_is_external($l['href']))) {
            return 'קישור לא תקין ב-links';
        }
    }
    return null;
}

/**
 * אפליקציה חיצונית — יושבת בדומיין אחר (למשל kadisha.mbe-plus.com) ורק נרשמת כאן:
 * התיקייה מכילה app.json (ו-README) בלבד, ו-entry / links הם כתובות https מלאות.
 * השער ומעקב הכניסות לא חלים עליה — הבקשות לא עוברות בשרת הזה.
 */
function registry_is_external($url): bool
{
    return is_string($url) && (bool)preg_match('#^https://[a-z0-9.-]+(/|$)#i', $url);
}

/** הכתובת שהדף מקשר אליה: כתובת חיצונית כמו שהיא, ונתיב יחסי — בתוך תיקיית הכלי. */
function registry_href(string $folder, string $path): string
{
    return registry_is_external($path) ? $path : "$folder/$path";
}

/** נתיב יחסי בתוך תיקיית הכלי בלבד: בלי סכמה, בלי '..' ובלי '/' בהתחלה. */
function registry_safe_path($path): bool
{
    return is_string($path)
        && !preg_match('#^[a-z][a-z0-9+.-]*:#i', $path)
        && !str_starts_with($path, '/')
        && !in_array('..', explode('/', $path), true);
}
