<?php
/**
 * מה באמת מותקן באיזה מכשיר — לפי דיווחים מהמכשיר עצמו, לא לפי "לחצו על הורדה".
 *
 * המדווח: platform/assets/pwa-beacon.js, שהשער מוסיף לכל דף של אפליקציה מחוברת (lib/pwa.php),
 * והדף הראשי — על הפלטפורמה עצמה ("_platform"). מה נחשב:
 *   installed / opened     — הדף נפתח במצב אפליקציה (מהאייקון). ודאי.
 *   installed / detected   — getInstalledRelatedApps של הדפדפן מצא אותה.
 *   installed / installed  — appinstalled: הותקנה עכשיו.
 *   not_installed / prompt — הדפדפן מציע להתקין, כלומר היא לא מותקנת.
 * אפליקציית אנדרואיד (APK) — נרשמת בשער כשהיא נפתחת (lib/gate.php, apk_seen): how=apk, עם הגרסה.
 * מכשיר = מזהה אקראי ב-localStorage (`platform:device`), משותף לכל הדפים באתר באותו דפדפן.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function installs_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec(<<<SQL
        -- לשעבר (#375): סימון לפי לחיצה, בלי בדיקה. בוטל — לא אמין.
        DROP TABLE IF EXISTS installs;
        CREATE TABLE IF NOT EXISTS device_apps (
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            device_id  TEXT NOT NULL,
            app_id     TEXT NOT NULL,
            state      TEXT NOT NULL CHECK (state IN ('installed', 'not_installed')),
            how        TEXT NOT NULL,                  -- opened | detected | installed | prompt
            device     TEXT NOT NULL DEFAULT '',
            checked_at TEXT NOT NULL,
            PRIMARY KEY (user_id, device_id, app_id)
        );
        SQL);
    // אפליקציית אנדרואיד שנפתחה (lib/gate.php, apk_seen): הגרסה, ואיך זוהתה (twa / webview). אותה הגדרה גם שם.
    $cols = array_column(db()->query('PRAGMA table_info(device_apps)')->fetchAll(), 'name');
    if (!in_array('version', $cols, true)) db()->exec('ALTER TABLE device_apps ADD COLUMN version INTEGER');
    if (!in_array('source', $cols, true)) db()->exec("ALTER TABLE device_apps ADD COLUMN source TEXT NOT NULL DEFAULT ''");
}

function installs_valid_device($id): string
{
    if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/', $id)) throw new ApiError('מזהה מכשיר לא תקין', 422);
    return $id;
}

/** מה ידוע על המכשיר הזה: {app_id: {state, how, checked_at, version}} — version רק לאפליקציית אנדרואיד (how=apk). */
function installs_on_device(int $userId, string $deviceId): array
{
    installs_migrate();
    $st = db()->prepare('SELECT app_id, state, how, checked_at, version FROM device_apps WHERE user_id = ? AND device_id = ?');
    $st->execute([$userId, $deviceId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[$r['app_id']] = ['state' => $r['state'], 'how' => $r['how'], 'checked_at' => $r['checked_at'],
            'version' => $r['version'] !== null ? (int)$r['version'] : null];
    }
    return $out;
}

/** דיווח מהמכשיר. "מותקנת" לא נדרס ב"לא מותקנת" מאותו רגע — beforeinstallprompt מגיע רק כשבאמת לא. */
function installs_report(int $userId, array $body): void
{
    installs_migrate();
    $device = installs_valid_device($body['device'] ?? null);
    $app = is_string($body['app'] ?? null) ? $body['app'] : '';
    if (!preg_match('/^(_platform|[a-z0-9][a-z0-9-]{0,60})$/', $app)) throw new ApiError('אפליקציה לא תקינה', 422);
    $state = $body['state'] ?? '';
    $how = $body['how'] ?? '';
    $valid = ['installed' => ['opened', 'detected', 'installed'], 'not_installed' => ['prompt']];
    if (!in_array($how, $valid[$state] ?? [], true)) throw new ApiError('דיווח לא תקין', 422);
    $label = is_string($body['label'] ?? null) ? mb_substr(trim($body['label']), 0, 60) : '';
    db()->prepare(
        'INSERT INTO device_apps (user_id, device_id, app_id, state, how, device, checked_at) VALUES (?,?,?,?,?,?,?)
         ON CONFLICT(user_id, device_id, app_id) DO UPDATE SET state = excluded.state, how = excluded.how,
             device = excluded.device, checked_at = excluded.checked_at'
    )->execute([$userId, $device, $app, $state, $how, $label, now_iso()]);
}

/** למנהל: המכשירים של משתמש, ומה ידוע על כל אחד. */
function installs_devices_of(int $userId): array
{
    installs_migrate();
    $st = db()->prepare('SELECT device_id, device, app_id, state, how, checked_at FROM device_apps WHERE user_id = ? ORDER BY checked_at DESC');
    $st->execute([$userId]);
    $devices = [];
    foreach ($st->fetchAll() as $r) {
        $d = &$devices[$r['device_id']];
        $d ??= ['device_id' => $r['device_id'], 'device' => $r['device'] ?: 'מכשיר', 'checked_at' => $r['checked_at'], 'apps' => []];
        $d['apps'][] = ['app_id' => $r['app_id'], 'state' => $r['state'], 'how' => $r['how'], 'checked_at' => $r['checked_at']];
        unset($d);
    }
    return array_values($devices);
}
