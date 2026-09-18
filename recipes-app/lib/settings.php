<?php
/**
 * הגדרות — שתי שכבות, ופתרון אחד לכל מגבלה.
 *
 * עד עכשיו תקרת הסרטון והמקצב היו קבועים בקוד. עכשיו הם הגדרות, בשתי
 * רמות שנקבעו על ידי המפתח:
 *
 *   ציבוריות — הגדרות של האפליקציה כולה, בטבלת app_settings. רק המפתח
 *              (חשבון ה-admin הראשון) משנה אותן.
 *   פרטיות  — הגדרות של משתמש בודד. חלקן הוא קובע לעצמו (בעתיד);
 *              המגבלות שלו קובע המפתח, ממסך ניהול המשתמשים.
 *
 * הכלל היחיד שחשוב: **כל מי שצריך מגבלה שואל את effectiveLimit()**, ולא
 * קורא קבוע. הפתרון הוא: דריסה של המשתמש → הגדרה ציבורית → ברירת המחדל
 * שבקוד, שהיא רק רשת ביטחון למסד שעוד לא נזרע.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';

/**
 * ההגדרות הציבוריות והמשמעות שלהן. הרשימה הזאת היא מקור האמת: מה שלא
 * כאן אינו הגדרה, ומי שינסה לכתוב מפתח אחר יידחה.
 *   default — הערך אם המפתח לא קבע אחרת
 *   unit    — לתצוגה ולולידציה (bytes נכתבים ב-MB במסך)
 *   min/max — גבולות סבירות. מונעים "0" ששובר העלאה ו-"1TB" בטעות.
 */
const APP_SETTINGS = [
    'video_max_bytes' => ['default' => 20 * 1024 * 1024,  'unit' => 'bytes',
                          'min' => 1024 * 1024, 'max' => 2 * 1024 * 1024 * 1024,
                          'label' => 'גודל מרבי לסרטון'],
    'quota_bytes'     => ['default' => 200 * 1024 * 1024, 'unit' => 'bytes',
                          'min' => 10 * 1024 * 1024, 'max' => 50 * 1024 * 1024 * 1024,
                          'label' => 'אחסון כולל לחשבון סטנדרטי'],
    'image_max_bytes' => ['default' => 5 * 1024 * 1024,   'unit' => 'bytes',
                          'min' => 256 * 1024, 'max' => 100 * 1024 * 1024,
                          'label' => 'גודל מרבי לתמונה'],
];

/** אילו מגבלות אפשר לדרוס לכל משתמש, ובאיזו עמודה. */
const USER_LIMIT_COLUMNS = [
    'video_max_bytes' => 'limit_video_bytes',
    'quota_bytes'     => 'limit_quota_bytes',
];

/**
 * המטמון חי לאורך בקשה אחת, ומתאפס בכל כתיבה. בלי האיפוס, כתיבה ואחריה
 * קריאה באותה בקשה — בדיוק מה ש-settings-public-save עושה — הייתה מחזירה
 * את הערך הישן. הבדיקה תפסה את זה: "אחרי הגדרה ציבורית 50MB: 20MB".
 */
function settingsCache(?array $set = null, bool $clear = false): array {
    static $cache = null;
    if ($clear) { $cache = null; return []; }
    if ($set !== null) $cache = $set;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT key, value FROM app_settings') as $row) {
            $cache[$row['key']] = (int) $row['value'];
        }
    }
    return $cache;
}

function appSetting(string $key): int {
    if (!isset(APP_SETTINGS[$key])) throw new AppError("הגדרה לא מוכרת: $key", 400);
    return settingsCache()[$key] ?? APP_SETTINGS[$key]['default'];
}

function allAppSettings(): array {
    $out = [];
    foreach (APP_SETTINGS as $key => $meta) {
        $out[$key] = ['value' => appSetting($key)] + $meta;
    }
    return $out;
}

/** המפתח בלבד. הערך נבדק מול הגבולות לפני שנכתב. */
function setAppSetting(string $key, int $value, array $user): void {
    requireDeveloper($user);
    if (!isset(APP_SETTINGS[$key])) throw new AppError("הגדרה לא מוכרת: $key", 400);
    $meta = APP_SETTINGS[$key];
    if ($value < $meta['min'] || $value > $meta['max']) {
        throw new AppError(sprintf('%s חייב להיות בין %s ל-%s',
            $meta['label'], humanBytes($meta['min']), humanBytes($meta['max'])));
    }
    $st = db()->prepare('INSERT INTO app_settings (key, value, updated_at) VALUES (?,?,?)
                         ON CONFLICT(key) DO UPDATE SET value = excluded.value,
                                                       updated_at = excluded.updated_at');
    $st->execute([$key, $value, nowIso()]);
    settingsCache(null, true);
}

/**
 * המגבלה שחלה על משתמש נתון: דריסה אישית אם יש, אחרת ההגדרה הציבורית.
 * $user יכול להיות שורה מלאה או רק ['id' => …] — השדות נשלפים בעצמנו,
 * כדי שקורא שלא טען את עמודות המגבלה לא יקבל בטעות את ברירת המחדל.
 */
function effectiveLimit(array $user, string $key): int {
    if (isset(USER_LIMIT_COLUMNS[$key]) && !empty($user['id'])) {
        $col = USER_LIMIT_COLUMNS[$key];
        $st = db()->prepare("SELECT $col v FROM users WHERE id = ?");
        $st->execute([$user['id']]);
        $row = $st->fetch();
        if ($row && $row['v'] !== null) return (int) $row['v'];
    }
    return appSetting($key);
}

/**
 * המפתח = ה-admin הראשון. יש admin אחד היום, אבל ההבחנה נשמרת בכוונה:
 * "מנהל" ימחק תגובות פוגעניות; "מפתח" משנה את כללי המשחק לכולם.
 */
function isDeveloper(array $user): bool {
    if (($user['role'] ?? '') !== 'admin') return false;
    $first = db()->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetch();
    return $first && (int) $first['id'] === (int) $user['id'];
}

function requireDeveloper(array $user): void {
    if (!isDeveloper($user)) throw new AppError('רק חשבון המפתח רשאי לשנות הגדרות ציבוריות', 403);
}

// ─────────────────────────────────────────────────────────────
// ניהול משתמשים — למפתח
// ─────────────────────────────────────────────────────────────

/** כל המשתמשים, עם המגבלות בפועל והשימוש, לרשימת הניהול. */
function listUsers(array $developer): array {
    requireDeveloper($developer);
    $rows = db()->query('SELECT u.id, u.username, u.email, u.display_name, u.role,
                                u.email_verified, u.blocked, u.created_at,
                                u.limit_video_bytes, u.limit_quota_bytes,
                                (SELECT COUNT(*) FROM recipes r WHERE r.owner_id = u.id) AS recipes,
                                (SELECT COALESCE(SUM(m.bytes),0) FROM media m
                                  WHERE m.uploader_id = u.id AND m.source = \'upload\') AS used
                           FROM users u ORDER BY u.id')->fetchAll();
    $pubVideo = appSetting('video_max_bytes');
    $pubQuota = appSetting('quota_bytes');
    return array_map(fn($u) => [
        'id'             => (int) $u['id'],
        'username'       => $u['username'],
        'email'          => $u['email'],
        'display_name'   => $u['display_name'],
        'role'           => $u['role'],
        'is_developer'   => isDeveloper(['id' => $u['id'], 'role' => $u['role']]),
        'email_verified' => (bool) $u['email_verified'],
        'blocked'        => (bool) $u['blocked'],
        'created_at'     => $u['created_at'],
        'recipes'        => (int) $u['recipes'],
        'used'           => (int) $u['used'],
        // null = יורש מההגדרה הציבורית. המסך מציג את זה כ"ברירת מחדל".
        'limit_video'    => $u['limit_video_bytes'] !== null ? (int) $u['limit_video_bytes'] : null,
        'limit_quota'    => $u['limit_quota_bytes'] !== null ? (int) $u['limit_quota_bytes'] : null,
        'effective_video' => $u['limit_video_bytes'] !== null ? (int) $u['limit_video_bytes'] : $pubVideo,
        'effective_quota' => $u['limit_quota_bytes'] !== null ? (int) $u['limit_quota_bytes'] : $pubQuota,
    ], $rows);
}

/**
 * קובע מגבלה למשתמש. null מחזיר אותו לברירת המחדל הציבורית.
 * המפתח אינו יכול להוריד את המקצב של עצמו מתחת לשימוש הנוכחי — לא
 * מבטיחים את זה לאחרים (המפתח מחליט), אבל נועלים את הדלת של המפתח בפניו.
 */
function setUserLimit(int $userId, string $key, ?int $value, array $developer): void {
    requireDeveloper($developer);
    if (!isset(USER_LIMIT_COLUMNS[$key])) throw new AppError("מגבלה לא מוכרת: $key", 400);
    if ($value !== null) {
        $meta = APP_SETTINGS[$key];
        if ($value < $meta['min'] || $value > $meta['max']) {
            throw new AppError(sprintf('%s חייב להיות בין %s ל-%s',
                $meta['label'], humanBytes($meta['min']), humanBytes($meta['max'])));
        }
    }
    $st = db()->prepare('SELECT id FROM users WHERE id = ?');
    $st->execute([$userId]);
    if (!$st->fetch()) throw new AppError('המשתמש אינו קיים', 404);

    $col = USER_LIMIT_COLUMNS[$key];
    $st = db()->prepare("UPDATE users SET $col = ? WHERE id = ?");
    $st->execute([$value, $userId]);
}

function setUserBlocked(int $userId, bool $blocked, array $developer): void {
    requireDeveloper($developer);
    if ($userId === (int) $developer['id']) throw new AppError('אי אפשר לחסום את חשבון המפתח', 400);
    $st = db()->prepare('UPDATE users SET blocked = ? WHERE id = ?');
    $st->execute([$blocked ? 1 : 0, $userId]);
}

function setUserVerified(int $userId, array $developer): void {
    requireDeveloper($developer);
    $st = db()->prepare('UPDATE users SET email_verified = 1 WHERE id = ?');
    $st->execute([$userId]);
}
