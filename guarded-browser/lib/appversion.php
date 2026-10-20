<?php
/**
 * גרסת אפליקציית הילד: מה מותקן בכל מכשיר, מה האחרונה, ועדכון לפי בקשת ההורה.
 *
 *   האפליקציה שולחת את מספר הגרסה בכותרת X-Migdalor-App (מ-1.43), והשרת שומר אותו במכשיר
 *   (userByToken). הגרסה האחרונה — מ-latest.json שה-CI מעלה ל-Release, במטמון של חצי שעה.
 *
 *   אפליקציה שמדווחת גרסה ואינה האחרונה — מקבלת update (חובה) בכל תשובה, בלי שההורה יבקש.
 *   ההורה לוחץ "עדכון" ← devices.update_requested_at — נשאר בשביל האפליקציות שלא מדווחות:
 *     • אפליקציה שלא מדווחת (לפני 1.43, או "דפדפן מנוהל" הישן) — אינה מכירה update. היא כן מציגה
 *       סיבת חסימה, ולכן היא מקבלת חסימה עם הוראות המעבר (upgradeBlock). זו הדרך היחידה להגיע אליה.
 *       "דפדפן מנוהל" נחתם במפתח זמני — אי אפשר להתקין עליו; צריך למחוק ולחבר מחדש בקוד.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/platforms.php';

// ההורדה עוברת דרך השרת שלנו (download.php) — ההפניה של GitHub נתקעה בטלפונים.
const APK_URL = 'https://mbe-plus.com/mini_projects/guarded-browser/download.php';
const APK_LATEST_URL = 'https://github.com/malkiel01/mini_projects/releases/download/guarded-browser-apk/latest.json';
const APK_LATEST_TTL = 1800;

/**
 * הגרסה האחרונה שפורסמה: ['code' => 45, 'name' => '1.45'], או null כשלא ידוע. ‏$fetch — להחלפה בבדיקות.
 * ‏$ttl — כמה זמן לסמוך על המטמון. ההורדה עצמה (download.php) שואלת כמעט תמיד מחדש.
 */
function latestApp(?callable $fetch = null, int $ttl = APK_LATEST_TTL): ?array {
    $row = one("SELECT value, updated_at FROM app_meta WHERE key = 'apk_latest'");
    $fresh = $row && strtotime((string) $row['updated_at']) > time() - $ttl;
    if (!$fresh) {
        $r = ($fetch ?? fn() => httpGet(APK_LATEST_URL, 4000))();
        $j = ($r['status'] ?? 0) === 200 ? json_decode((string) $r['body'], true) : null;
        if (is_array($j) && (int) ($j['version_code'] ?? 0) > 0) {
            $val = json_encode(['code' => (int) $j['version_code'], 'cert' => (string) ($j['cert_sha256'] ?? '')]);
            upsert('app_meta', ['key' => 'apk_latest'], ['value' => $val, 'updated_at' => nowIso()]);
            $row = ['value' => $val];
        } elseif ($row) {
            // השליפה נכשלה — ממשיכים עם הישן, ולא מנסים שוב בכל בקשה.
            q("UPDATE app_meta SET updated_at = ? WHERE key = 'apk_latest'", [nowIso()]);
        }
    }
    $data = json_decode((string) ($row['value'] ?? ''), true) ?: [];
    $code = (int) ($data['code'] ?? 0);
    return $code > 0 ? ['code' => $code, 'name' => '1.' . $code, 'cert' => (string) ($data['cert'] ?? '')] : null;
}

/**
 * תצורת ההקצאה (QR provisioning) של "בעל מכשיר": מה שאנדרואיד מצפה לו ב-QR במסך הפתיחה של
 * מכשיר מאופס. ‏null אם עדיין אין טביעת אצבע של תעודת החתימה (בנייה ישנה לפני cert_sha256).
 */
function provisionConfig(): ?array {
    $latest = latestApp();
    if (!$latest || ($latest['cert'] ?? '') === '') return null;
    return [
        'android.app.extra.PROVISIONING_DEVICE_ADMIN_COMPONENT_NAME'
            => 'com.mbeplus.guardedbrowser/com.mbeplus.guardedbrowser.MigdalorAdmin',
        'android.app.extra.PROVISIONING_DEVICE_ADMIN_PACKAGE_DOWNLOAD_LOCATION' => (serverUrl() ?: 'https://mbe-plus.com/mini_projects/guarded-browser/') . 'download.php',
        'android.app.extra.PROVISIONING_DEVICE_ADMIN_SIGNATURE_CHECKSUM' => $latest['cert'],
        'android.app.extra.PROVISIONING_LEAVE_ALL_SYSTEM_APPS_ENABLED' => true,
        'android.app.extra.PROVISIONING_SKIP_ENCRYPTION' => false,
    ];
}

/* ── הרשאות המכשיר (מסלול ההקמה) — אותם ביטים כמו Setup.kt ── */
const PERM_NOTIF = 1, PERM_BATTERY = 2, PERM_ADMIN = 4, PERM_INSTALL = 8, PERM_ACCESS = 16, PERM_OWNER = 32;
// כל ההרשאות שאפליקציה יכולה לבקש — חובה (בעל המערכת, 2026-10-20). "בעל מכשיר" אינו נכלל: אנדרואיד
// מאפשר אותו רק בהקמה של מכשיר מאופס, ולכן הוא "מומלץ" ומוצג להורה כ"אכיפה חלקית" כשהוא חסר.
const PERM_REQUIRED = 31;  // NOTIF | BATTERY | ADMIN | INSTALL | ACCESS
const PERM_NAMES = [PERM_NOTIF => 'התראות', PERM_BATTERY => 'ריצה רציפה', PERM_ACCESS => 'ניהול אפליקציות', PERM_ADMIN => 'ניהול מכשיר', PERM_INSTALL => 'עדכונים'];

/** מצב ההרשאות של מכשיר: מה ניתן, מה חסר, והאם החיוני כולו קיים. */
function devicePermsState(int $perms): array {
    $missing = [];
    foreach (PERM_NAMES as $bit => $name) if (!($perms & $bit)) $missing[$bit] = $name;
    $requiredMissing = array_values($missing);
    return [
        'ok'       => ($perms & PERM_REQUIRED) === PERM_REQUIRED,
        'owner'    => (bool) ($perms & PERM_OWNER),
        'complete' => count($missing) === 0,
        'missing'  => array_values($missing),
        'required_missing' => $requiredMissing,
        'admin'    => (bool) ($perms & PERM_ADMIN),
    ];
}

/** מה ההורה רואה על מכשיר: גרסה, האם יש חדשה, האם כבר ביקש. */
function deviceAppState(array $d, ?array $latest): array {
    $v = (int) ($d['app_version'] ?? 0);
    $status = $v === 0 ? 'unknown' : ($latest && $latest['code'] > $v ? 'outdated' : 'current');
    return ['version' => $v, 'version_name' => $v ? '1.' . $v : '', 'status' => $status,
            'requested' => (string) ($d['update_requested_at'] ?? '') !== '' && $status !== 'current'];
}

/** ההורה ביקש לעדכן מכשיר. מוחזרת שגיאה, או ''. */
function requestDeviceUpdate(int $childId, int $deviceId): string {
    $d = one('SELECT * FROM devices WHERE id = ? AND user_id = ?', [$deviceId, $childId]);
    if (!$d) return 'המכשיר לא נמצא';
    if (deviceAppState($d, latestApp())['status'] === 'current') return 'המכשיר כבר בגרסה האחרונה';
    q('UPDATE devices SET update_requested_at = ? WHERE id = ?', [nowIso(), $deviceId]);
    return '';
}

function cancelDeviceUpdate(int $childId, int $deviceId): void {
    q("UPDATE devices SET update_requested_at = '' WHERE id = ? AND user_id = ?", [$deviceId, $childId]);
}

/**
 * למכשיר שמדווח גרסה: העדכון שהוא חייב להתקין, או null. מכשיר שכבר עודכן — הבקשה נמחקת.
 *
 * עדכון חובה, תמיד — גם בלי בקשה מההורה: כל גרסה ישנה מקבלת אותו בכל פנייה (מדיניות ופעימה), ומהגרסה
 * שבה נוסף עדכון החובה האפליקציה ננעלת עד ההתקנה (Updater.kt). ‏required — לגרסאות שמכירות אותו; ישנות יותר רק מציעות.
 */
function deviceUpdateOffer(int $deviceRowId): ?array {
    $d = one('SELECT * FROM devices WHERE id = ?', [$deviceRowId]);
    if (!$d || (int) $d['app_version'] === 0) return null;
    $latest = latestApp();
    if (!$latest || $latest['code'] <= (int) $d['app_version']) {
        if ($latest && (string) $d['update_requested_at'] !== '') q("UPDATE devices SET update_requested_at = '' WHERE id = ?", [$deviceRowId]);
        return null;
    }
    return ['version_code' => $latest['code'], 'version_name' => $latest['name'], 'required' => true,
            'url' => (serverUrl() ?: '') !== '' ? serverUrl() . 'download.php' : APK_URL];
}

/**
 * לאפליקציה שלא מדווחת גרסה ושההורה ביקש לעדכן: סיבת חסימה עם הוראות המעבר, או ''.
 * היא מציגה את הסיבה בדיוק כמו "נגמר הזמן" — זה הערוץ היחיד אליה.
 */
function upgradeBlock(int $deviceRowId): string {
    $d = one('SELECT app_version, update_requested_at FROM devices WHERE id = ?', [$deviceRowId]);
    if (!$d || (int) $d['app_version'] > 0 || (string) $d['update_requested_at'] === '') return '';
    return 'ההורה ביקש לעדכן את האפליקציה לגרסה החדשה של "מגדלור". '
         . 'מורידים ומתקינים מכאן: mbe-plus.com/mini_projects (בכרטיס "מגדלור" ← הורדה). '
         . 'אם כתוב "האפליקציה לא הותקנה" — מוחקים קודם את הישנה, מתקינים, ומחברים מחדש עם הקוד מההורה.';
}
