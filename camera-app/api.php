<?php
/**
 * ה-API של אפליקציית המצלמות.
 *
 * כל בקשה היא POST עם JSON, פרט ל-GET של `me`. ה-action בגוף או ב-query.
 * החלוקה לפי action ולא לפי נתיב — כך שכל הקובץ נקרא במבט אחד.
 *
 * ארבע קבוצות: חשבונות · מצלמות ותיקיות · הקלטות וקטלוג · גשרים ופקודות.
 * מה שמגיע מהגשר עצמו (heartbeat, העלאות) עובר ב-bridge-* וב-upload.php,
 * עם טוקן גשר במקום סשן.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/cameras.php';
require_once __DIR__ . '/lib/recordings.php';
require_once __DIR__ . '/lib/ingest.php';
require_once __DIR__ . '/lib/bridge.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

function fail(string $message, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function ok(array $payload = []): never {
    echo json_encode(['success' => true] + $payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** מפתח ה-cron ל-HTTP; נוצר פעם אחת. */
function cronKey(): string {
    $k = settingGet('cron_key', '');
    if ($k === '') { $k = bin2hex(random_bytes(12)); settingSet('cron_key', $k); }
    return $k;
}

function intOrNull($v): ?int {
    return ($v === null || $v === '' ) ? null : (int) $v;
}

/** ה-?v= של app.js כפי שכתוב ב-index.html — הדפדפן משווה לשלו ומרענן. */
function assetsVersion(): string {
    $html = @file_get_contents(__DIR__ . '/index.html') ?: '';
    return preg_match('/app\.js\?v=([^"\']+)/', $html, $m) ? $m[1] : '';
}

$in     = body();
$action = $_GET['action'] ?? (is_string($in['action'] ?? null) ? $in['action'] : '');

// בלי סשן: מה שמוביל לכניסה. הגשר מזדהה בטוקן משלו ולא בסשן.
$public        = ['me', 'setup', 'login'];
$bridgeActions = ['bridge-pair', 'bridge-heartbeat', 'bridge-command-done'];

try {
    $user = null;
    if (!in_array($action, $bridgeActions, true)) {
        $user = currentUser();
        if (!in_array($action, $public, true) && !$user) fail('נדרשת התחברות', 401);
        // תחזוקה "בדרך אגב": גם בלי cron המערכת קולטת מה-FTP — אחת לדקה.
        if ($user) runMaintenance();
    }
    $uid = $user ? (int) $user['id'] : null;

    switch ($action) {

    /* ───────── חשבונות ───────── */

    case 'me':
        ok(['user' => $user ? ['id' => (int) $user['id'], 'username' => $user['username'],
                               'display_name' => $user['display_name'], 'role' => $user['role']] : null,
            'needs_setup' => userCount() === 0,
            'assets_version' => assetsVersion()]);

    case 'setup': {
        $id = setupFirstAdmin((string) ($in['username'] ?? ''), (string) ($in['password'] ?? ''), (string) ($in['display_name'] ?? ''));
        $u = login((string) $in['username'], (string) $in['password']);
        ok(['user' => ['id' => $id, 'username' => $u['username'], 'display_name' => $u['display_name'], 'role' => 'admin']]);
    }

    case 'login': {
        $u = login((string) ($in['username'] ?? ''), (string) ($in['password'] ?? ''));
        ok(['user' => ['id' => (int) $u['id'], 'username' => $u['username'], 'display_name' => $u['display_name'], 'role' => $u['role']]]);
    }

    case 'logout':
        logout();
        ok();

    case 'change-password':
        changePassword($uid, (string) ($in['current'] ?? ''), (string) ($in['new'] ?? ''));
        ok();

    case 'users':
        requireAdmin();
        ok(['users' => listUsers()]);

    case 'user-create':
        requireAdmin();
        ok(['id' => createUser((string) ($in['username'] ?? ''), (string) ($in['password'] ?? ''),
                                (string) ($in['display_name'] ?? ''), (string) ($in['role'] ?? 'viewer'))]);

    case 'user-update':
        requireAdmin();
        updateUser($uid, (int) ($in['id'] ?? 0), $in);
        ok();

    case 'user-delete':
        requireAdmin();
        deleteUser($uid, (int) ($in['id'] ?? 0));
        ok();

    /* ───────── מצלמות ותיקיות ───────── */

    case 'overview':
        // מסך הבית: הכול במכה אחת.
        ok(['cameras' => listCameras(), 'folders' => listCameraFolders(), 'topics' => listTopics(),
            'bridges' => $user['role'] === 'admin' ? listBridges() : [],
            'storage' => storageStats()]);

    case 'camera':
        ok(['camera' => cameraPublic(cameraById((int) ($in['id'] ?? 0)))]);

    case 'camera-save':
        requireAdmin();
        $id = saveCamera(intOrNull($in['id'] ?? null), $in);
        ok(['camera' => cameraPublic(cameraById($id))]);

    case 'camera-delete':
        requireAdmin();
        deleteCamera((int) ($in['id'] ?? 0));
        ok();

    case 'camera-key-suggest':
        requireAdmin();
        ok(['key' => suggestCameraKey((string) ($in['name'] ?? ''))]);

    case 'folder-save':
        requireAdmin();
        ok(['id' => saveCameraFolder(intOrNull($in['id'] ?? null), (string) ($in['name'] ?? ''), intOrNull($in['parent_id'] ?? null))]);

    case 'folder-delete':
        requireAdmin();
        deleteCameraFolder((int) ($in['id'] ?? 0));
        ok();

    case 'record':
        // כפתור REC. עובד רק דרך גשר; בלי גשר — הודעה ברורה.
        requireOperator();
        $c = cameraById((int) ($in['id'] ?? 0));
        if ($c['bridge_id'] === null) fail('הקלטה יזומה דורשת גשר ברשת של המצלמה. בלי גשר, המצלמה מעלה לבד לפי ההגדרות שלה (FTP)', 409);
        setManualRecording((int) $c['id'], !empty($in['on']), $uid);
        ok(['camera' => cameraPublic(cameraById((int) $c['id']))]);

    case 'live':
        // הדפדפן קורא לזה כל 10 שניות בזמן צפייה; זה מה שמחזיק את השידור.
        $c = cameraById((int) ($in['id'] ?? 0));
        if ($c['bridge_id'] !== null) touchLive((int) $c['id']);
        ok(['live' => liveStatus($c)]);

    case 'command': {
        requireOperator();
        $type = (string) ($in['type'] ?? '');
        $payload = is_array($in['payload'] ?? null) ? $in['payload'] : [];
        $id = enqueueCommand((int) ($in['id'] ?? 0), $type, $payload, $uid);
        ok(['command_id' => $id]);
    }

    case 'command-status':
        ok(['command' => commandStatus((int) ($in['id'] ?? 0))]);

    /* ───────── הקלטות וקטלוג ───────── */

    case 'recordings':
        ok(['recordings' => listRecordings($in)]);

    case 'recording':
        $r = recordingById((int) ($in['id'] ?? 0));
        ok(['recording' => $r, 'bookmarks' => listBookmarks($r['id'])]);

    case 'recording-update':
        requireOperator();
        updateRecording((int) ($in['id'] ?? 0), $in);
        ok(['recording' => recordingById((int) $in['id'])]);

    case 'recording-delete':
        requireAdmin();
        deleteRecording((int) ($in['id'] ?? 0), $uid);
        ok();

    case 'recordings-bulk': {
        // פעולה על כמה: להוסיף לנושא, כוכב, נעילה, מחיקה.
        requireOperator();
        $ids = array_map('intval', (array) ($in['ids'] ?? []));
        $op  = (string) ($in['op'] ?? '');
        $n = 0;
        foreach ($ids as $id) {
            try {
                switch ($op) {
                    case 'topic-add':
                        $r = recordingById($id);
                        updateRecording($id, ['topics' => array_unique([...$r['topics'], (int) ($in['topic_id'] ?? 0)])]); break;
                    case 'topic-remove':
                        $r = recordingById($id);
                        updateRecording($id, ['topics' => array_values(array_diff($r['topics'], [(int) ($in['topic_id'] ?? 0)]))]); break;
                    case 'star':   updateRecording($id, ['starred' => !empty($in['value'])]); break;
                    case 'lock':   updateRecording($id, ['locked' => !empty($in['value'])]); break;
                    case 'delete': requireAdmin(); deleteRecording($id, $uid); break;
                    default: fail('פעולה לא מוכרת');
                }
                $n++;
            } catch (AppError $e) { /* נעולה/נמחקה — ממשיכים */ }
        }
        ok(['done' => $n]);
    }

    case 'timeline':
        ok(['segments' => dayTimeline((int) ($in['camera_id'] ?? 0), (string) ($in['day'] ?? gmdate('Y-m-d')))]);

    case 'calendar':
        ok(['days' => daysWithRecordings((int) ($in['camera_id'] ?? 0), (string) ($in['month'] ?? gmdate('Y-m')))]);

    case 'topics':
        ok(['topics' => listTopics()]);

    case 'topic-save':
        requireOperator();
        ok(['id' => saveTopic(intOrNull($in['id'] ?? null), (string) ($in['name'] ?? ''), intOrNull($in['parent_id'] ?? null), (string) ($in['description'] ?? ''))]);

    case 'topic-delete':
        requireAdmin();
        deleteTopic((int) ($in['id'] ?? 0));
        ok();

    case 'bookmark-add':
        requireOperator();
        ok(['id' => addBookmark((int) ($in['recording_id'] ?? 0), (float) ($in['at'] ?? 0), (string) ($in['note'] ?? ''), $uid)]);

    case 'bookmark-delete':
        requireOperator();
        deleteBookmark((int) ($in['id'] ?? 0));
        ok();

    case 'events': {
        $limit = max(1, min(500, (int) ($in['limit'] ?? 100)));
        $where = []; $args = [];
        if (!empty($in['camera_id'])) { $where[] = 'camera_id = ?'; $args[] = (int) $in['camera_id']; }
        if (!empty($in['before_id'])) { $where[] = 'id < ?'; $args[] = (int) $in['before_id']; }
        if (!empty($in['kind']))      { $where[] = 'kind LIKE ?'; $args[] = (string) $in['kind'] . '%'; }
        $st = db()->prepare('SELECT * FROM events' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY id DESC LIMIT $limit");
        $st->execute($args);
        ok(['events' => $st->fetchAll()]);
    }

    case 'maintenance':
        // כפתור "סרוק עכשיו" במסך ההגדרות.
        requireAdmin();
        ok(['result' => runMaintenance(true)]);

    case 'settings':
        requireAdmin();
        ok(['settings' => [
            'storage_cap_bytes'   => (int) settingGet('storage_cap_bytes', '0'),
            'camera_tz_offset_min'=> (int) settingGet('camera_tz_offset_min', (string) cameraTzOffsetDefault()),
            'segment_seconds'     => (int) settingGet('segment_seconds', '60'),
            'live_segment_seconds'=> (int) settingGet('live_segment_seconds', '2'),
            'maintenance_last_ts' => (int) settingGet('maintenance_last_ts', '0'),
            'retention_last_ts'   => (int) settingGet('retention_last_ts', '0'),
            'inbox_dir'           => realpath(INBOX_DIR) ?: INBOX_DIR,
            'cron_path'           => realpath(__DIR__ . '/cron.php') ?: (__DIR__ . '/cron.php'),
            'cron_key'            => cronKey(),
            'php_version'         => PHP_VERSION,
            'gd'                  => function_exists('imagecreatefromstring'),
            'sodium'              => function_exists('sodium_crypto_secretbox'),
        ]]);

    case 'settings-save':
        requireAdmin();
        foreach (['storage_cap_bytes', 'camera_tz_offset_min', 'segment_seconds', 'live_segment_seconds'] as $k) {
            if (array_key_exists($k, $in)) settingSet($k, (string) (int) $in[$k]);
        }
        ok();

    /* ───────── גשרים (צד המנהל) ───────── */

    case 'bridge-list':
        requireAdmin();
        ok(['bridges' => listBridges()]);

    case 'bridge-create':
        requireAdmin();
        ok(createBridge((string) ($in['name'] ?? '')));

    case 'bridge-renew':
        requireAdmin();
        ok(['pair_code' => renewPairCode((int) ($in['id'] ?? 0))]);

    case 'bridge-rename':
        requireAdmin();
        renameBridge((int) ($in['id'] ?? 0), (string) ($in['name'] ?? ''));
        ok();

    case 'bridge-delete':
        requireAdmin();
        deleteBridge((int) ($in['id'] ?? 0));
        ok();

    /* ───────── גשרים (צד הסקריפט, בטוקן) ───────── */

    case 'bridge-pair':
        ok(pairBridge((string) ($in['code'] ?? ''), is_array($in['info'] ?? null) ? $in['info'] : []));

    case 'bridge-heartbeat': {
        $b = requireBridge();
        runMaintenance();   // הגשר מדבר כל 5 שניות — גם הוא מזיז את התחזוקה
        ok(bridgeHeartbeat($b, $in));
    }

    case 'bridge-command-done': {
        $b = requireBridge();
        completeCommand($b, (int) ($in['id'] ?? 0), !empty($in['ok']), $in['result'] ?? null);
        ok();
    }

    case '':
        fail('חסר action');
    default:
        fail('action לא מוכר: ' . $action, 404);
    }
} catch (AppError $e) {
    fail($e->getMessage(), $e->status);
} catch (Throwable $e) {
    error_log('camera-app: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    try { logEvent('server.error', $e->getMessage(), ['action' => $action], 'error'); } catch (Throwable) {}
    fail('שגיאת שרת', 500);
}
