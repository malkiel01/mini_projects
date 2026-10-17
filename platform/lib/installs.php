<?php
/**
 * מעקב התקנות: אילו אפליקציות מותקנות באיזה מכשיר.
 *
 * אתר לא יכול לראות לבד מה מותקן בטלפון (פרטיות — הדפדפן לא חושף את זה). לכן הפלטפורמה זוכרת:
 *   - "התקנה" / "הורדת APK" / "Google Play" מתוך הפלטפורמה → מסומן כמותקן במכשיר הזה.
 *   - המשתמש יכול לסמן ולבטל בעצמו (גיליון האפליקציה).
 *   - הפלטפורמה עצמה — מזוהה לבד (נפתחה מהאייקון, או appinstalled), ומסומנת "_platform".
 * מכשיר = מזהה אקראי ב-localStorage (app.js), משותף לאפליקציה המותקנת ולדפדפן באותו טלפון.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const INSTALL_PLATFORM_ID = '_platform';

function installs_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS installs (
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            device_id  TEXT NOT NULL,
            app_id     TEXT NOT NULL,
            method     TEXT NOT NULL DEFAULT 'manual',   -- pwa | apk | store | manual | auto
            device     TEXT NOT NULL DEFAULT '',        -- "iPhone · Safari"
            updated_at TEXT NOT NULL,
            PRIMARY KEY (user_id, device_id, app_id)
        );
        SQL);
}

function installs_valid_device($id): string
{
    if (!is_string($id) || !preg_match('/^[a-f0-9]{16,64}$/', $id)) throw new ApiError('מזהה מכשיר לא תקין', 422);
    return $id;
}

/** מה מותקן במכשיר הזה אצל המשתמש: {app_id: method}. */
function installs_on_device(int $userId, string $deviceId): array
{
    installs_migrate();
    $st = db()->prepare('SELECT app_id, method FROM installs WHERE user_id = ? AND device_id = ?');
    $st->execute([$userId, $deviceId]);
    return array_column($st->fetchAll(), 'method', 'app_id');
}

function installs_mark(int $userId, array $body): void
{
    installs_migrate();
    $device = installs_valid_device($body['device'] ?? null);
    $app = is_string($body['app'] ?? null) ? $body['app'] : '';
    if (!preg_match('/^(_platform|[a-z0-9][a-z0-9-]*)$/', $app)) throw new ApiError('אפליקציה לא תקינה', 422);
    $label = is_string($body['label'] ?? null) ? mb_substr(trim($body['label']), 0, 60) : '';
    if (empty($body['installed'])) {
        db()->prepare('DELETE FROM installs WHERE user_id = ? AND device_id = ? AND app_id = ?')->execute([$userId, $device, $app]);
        return;
    }
    $method = in_array($body['method'] ?? '', ['pwa', 'apk', 'store', 'manual', 'auto'], true) ? $body['method'] : 'manual';
    db()->prepare(
        'INSERT INTO installs (user_id, device_id, app_id, method, device, updated_at) VALUES (?,?,?,?,?,?)
         ON CONFLICT(user_id, device_id, app_id) DO UPDATE SET method = excluded.method, device = excluded.device,
             updated_at = excluded.updated_at'
    )->execute([$userId, $device, $app, $method, $label, now_iso()]);
    // שם המכשיר מתעדכן בכל השורות שלו — כדי שיוצג אחיד אצל המנהל.
    if ($label !== '') db()->prepare('UPDATE installs SET device = ? WHERE user_id = ? AND device_id = ?')->execute([$label, $userId, $device]);
}

/** למנהל: המכשירים של משתמש, ומה מותקן בכל אחד. */
function installs_devices_of(int $userId): array
{
    installs_migrate();
    $st = db()->prepare('SELECT device_id, device, app_id, method, updated_at FROM installs WHERE user_id = ? ORDER BY updated_at DESC');
    $st->execute([$userId]);
    $devices = [];
    foreach ($st->fetchAll() as $r) {
        $d = &$devices[$r['device_id']];
        $d ??= ['device_id' => $r['device_id'], 'device' => $r['device'] ?: 'מכשיר', 'updated_at' => $r['updated_at'], 'apps' => []];
        $d['apps'][] = ['app_id' => $r['app_id'], 'method' => $r['method'], 'updated_at' => $r['updated_at']];
        unset($d);
    }
    return array_values($devices);
}
