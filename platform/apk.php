<?php
/**
 * הדופק היומי של אפליקציות האנדרואיד שלנו (PlatformHeartbeat; האפיון "גרסאות וחשבונות", סעיף 3.4).
 *
 *   POST platform/apk.php  {install, package, version, apps: [{p: <חבילה>, v: <versionCode>}]}
 *
 * install — מזהה ההתקנה, שהשער קשר למשתמש ולמכשיר כשהאפליקציה נפתחה מהאייקון (lib/gate.php, apk_seen).
 * לפיו: האפליקציה ששלחה — "מותקנת, גרסה N"; כל אפליקציה שלנו ב-apps — מותקנת באותו מכשיר; אפליקציה שלנו
 * שנרשמה במכשיר הזה ואינה ב-apps — הוסרה. מזהה שלא נקשר עדיין — לא נרשם דבר (204).
 * אין כאן התחברות: המזהה הוא הסוד, והוא משפיע רק על המכשיר שהוא קשור אליו.
 */

declare(strict_types=1);

require __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/registry.php';
require_once __DIR__ . '/lib/installs.php';

header('Cache-Control: no-store');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new ApiError('שיטה לא נתמכת', 405);
    $body = json_decode((string)file_get_contents('php://input'), true);
    $install = is_array($body) && is_string($body['install'] ?? null) ? $body['install'] : '';
    if (!preg_match('/^[a-f0-9]{32}$/', $install)) throw new ApiError('בקשה לא תקינה', 400);
    installs_migrate();

    // אילו אפליקציות שלנו הן אנדרואיד, ובאיזו חבילה (meta.json של תיקיית החנות — registry_install)
    $byPkg = [];
    foreach (registry_load()['apps'] as $a) {
        $pkg = $a['install']['android']['package'] ?? null;
        if (is_string($pkg)) $byPkg[$pkg] = $a['id'];
    }
    $st = db()->prepare('SELECT user_id, device_id, app_id, device FROM device_apps WHERE install_id = ?');
    $st->execute([$install]);
    $row = $st->fetch();
    if (!$row || ($byPkg[(string)($body['package'] ?? '')] ?? null) !== $row['app_id']) {
        http_response_code(204);
        exit;
    }

    $now = gmdate('Y-m-d\TH:i:s\Z');
    $seen = [];
    foreach ((array)($body['apps'] ?? []) as $x) {
        $pkg = is_array($x) && is_string($x['p'] ?? null) ? $x['p'] : '';
        $ver = is_array($x) && is_numeric($x['v'] ?? null) ? (int)$x['v'] : null;
        if (isset($byPkg[$pkg])) $seen[$byPkg[$pkg]] = $ver;
    }
    $seen[$row['app_id']] = is_numeric($body['version'] ?? null) ? (int)$body['version'] : ($seen[$row['app_id']] ?? null);

    $up = db()->prepare(
        "INSERT INTO device_apps (user_id, device_id, app_id, state, how, device, checked_at, version, source)
         VALUES (?, ?, ?, 'installed', 'apk', ?, ?, ?, 'heartbeat')
         ON CONFLICT(user_id, device_id, app_id) DO UPDATE SET state = 'installed', how = 'apk',
             checked_at = excluded.checked_at, version = excluded.version, source = 'heartbeat'"
    );
    foreach ($seen as $app => $ver) $up->execute([$row['user_id'], $row['device_id'], $app, $row['device'], $now, $ver]);

    // אפליקציה שלנו שנרשמה במכשיר הזה כ-APK ואינה בדיווח — הוסרה
    $gone = db()->prepare("UPDATE device_apps SET state = 'not_installed', checked_at = ?, source = 'heartbeat'
                           WHERE user_id = ? AND device_id = ? AND app_id = ? AND how = 'apk' AND state = 'installed'");
    foreach (array_diff(array_values($byPkg), array_keys($seen)) as $app) {
        $gone->execute([$now, $row['user_id'], $row['device_id'], $app]);
    }
    json_out(['ok' => true, 'apps' => count($seen)]);
} catch (ApiError $e) {
    json_out(['error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log('platform apk heartbeat: ' . $e);
    json_out(['error' => 'שגיאה בשרת'], 500);
}
