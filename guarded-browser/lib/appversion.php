<?php
/**
 * גרסת אפליקציית הילד: מה מותקן בכל מכשיר, מה האחרונה, ועדכון לפי בקשת ההורה.
 *
 *   האפליקציה שולחת את מספר הגרסה בכותרת X-Migdalor-App (מ-1.42), והשרת שומר אותו במכשיר
 *   (userByToken). הגרסה האחרונה — מ-latest.json שה-CI מעלה ל-Release, במטמון של חצי שעה.
 *
 *   ההורה לוחץ "עדכון" ← devices.update_requested_at. ומשם:
 *     • אפליקציה שמדווחת גרסה — מקבלת update בתשובה, ומציעה לילד להוריד ולהתקין.
 *     • אפליקציה שלא מדווחת (לפני 1.42, או "דפדפן מנוהל" הישן) — אינה מכירה update. היא כן מציגה
 *       סיבת חסימה, ולכן היא מקבלת חסימה עם הוראות המעבר (upgradeBlock). זו הדרך היחידה להגיע אליה.
 *       "דפדפן מנוהל" נחתם במפתח זמני — אי אפשר להתקין עליו; צריך למחוק ולחבר מחדש בקוד.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/platforms.php';

const APK_URL = 'https://github.com/malkiel01/mini_projects/releases/download/guarded-browser-apk/migdalor.apk';
const APK_LATEST_URL = 'https://github.com/malkiel01/mini_projects/releases/download/guarded-browser-apk/latest.json';
const APK_LATEST_TTL = 1800;

/** הגרסה האחרונה שפורסמה: ['code' => 45, 'name' => '1.45'], או null כשלא ידוע. ‏$fetch — להחלפה בבדיקות. */
function latestApp(?callable $fetch = null): ?array {
    $row = one("SELECT value, updated_at FROM app_meta WHERE key = 'apk_latest'");
    $fresh = $row && strtotime((string) $row['updated_at']) > time() - APK_LATEST_TTL;
    if (!$fresh) {
        $r = ($fetch ?? fn() => httpGet(APK_LATEST_URL, 4000))();
        $j = ($r['status'] ?? 0) === 200 ? json_decode((string) $r['body'], true) : null;
        if (is_array($j) && (int) ($j['version_code'] ?? 0) > 0) {
            upsert('app_meta', ['key' => 'apk_latest'], ['value' => json_encode(['code' => (int) $j['version_code']]), 'updated_at' => nowIso()]);
            $row = ['value' => json_encode(['code' => (int) $j['version_code']])];
        } elseif ($row) {
            // השליפה נכשלה — ממשיכים עם הישן, ולא מנסים שוב בכל בקשה.
            q("UPDATE app_meta SET updated_at = ? WHERE key = 'apk_latest'", [nowIso()]);
        }
    }
    $code = (int) (json_decode((string) ($row['value'] ?? ''), true)['code'] ?? 0);
    return $code > 0 ? ['code' => $code, 'name' => '1.' . $code] : null;
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

/** למכשיר שמדווח גרסה: מה להציע לו, או null. מכשיר שכבר עודכן — הבקשה נמחקת. */
function deviceUpdateOffer(int $deviceRowId): ?array {
    $d = one('SELECT * FROM devices WHERE id = ?', [$deviceRowId]);
    if (!$d || (string) $d['update_requested_at'] === '' || (int) $d['app_version'] === 0) return null;
    $latest = latestApp();
    if (!$latest || $latest['code'] <= (int) $d['app_version']) {
        if ($latest) q("UPDATE devices SET update_requested_at = '' WHERE id = ?", [$deviceRowId]);
        return null;
    }
    return ['version_code' => $latest['code'], 'version_name' => $latest['name'], 'url' => APK_URL];
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
