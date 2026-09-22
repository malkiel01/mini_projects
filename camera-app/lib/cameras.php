<?php
/**
 * מצלמות ותיקיות המצלמות.
 *
 * מצלמה היא רשומה: איך מגיעים אליה (כתובת, פורטים, משתמש וסיסמה מוצפנת),
 * מה היא יודעת (PTZ, שמע), ואיך מקליטים ממנה. ה"מפתח" (key) הוא מזהה
 * קצר באנגלית שמשמש לשני דברים: שם תיקיית ה-FTP שהמצלמה מעלה אליה
 * (data/inbox/<key>/), והשם שהגשר מכיר אותה בו.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';

const RECORD_MODES = ['manual', 'continuous', 'motion', 'schedule'];
const BRANDS       = ['reolink', 'onvif', 'rtsp'];

/* ───────── תיקיות ───────── */

function listCameraFolders(): array {
    return db()->query('SELECT id, parent_id, name, sort FROM camera_folders ORDER BY sort, name')->fetchAll();
}

function saveCameraFolder(?int $id, string $name, ?int $parentId): int {
    $name = trim($name);
    if ($name === '') throw new AppError('לתיקייה צריך שם');
    if ($parentId !== null && $parentId === $id) throw new AppError('תיקייה לא יכולה להכיל את עצמה');
    if ($parentId !== null && !folderExists('camera_folders', $parentId)) throw new AppError('תיקיית האב לא קיימת');
    if ($id === null) {
        db()->prepare('INSERT INTO camera_folders (parent_id, name, created_at) VALUES (?,?,?)')
            ->execute([$parentId, $name, nowIso()]);
        return (int) db()->lastInsertId();
    }
    if ($parentId !== null && isDescendant('camera_folders', $parentId, $id)) {
        throw new AppError('אי אפשר להעביר תיקייה לתוך צאצא שלה');
    }
    db()->prepare('UPDATE camera_folders SET name = ?, parent_id = ? WHERE id = ?')->execute([$name, $parentId, $id]);
    return $id;
}

function deleteCameraFolder(int $id): void {
    // המצלמות והתת-תיקיות עולות לאב (ON DELETE SET NULL היה שולח לשורש —
    // עדיף לאב, כדי שהעץ לא יתפזר).
    $st = db()->prepare('SELECT parent_id FROM camera_folders WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) throw new AppError('אין תיקייה כזו', 404);
    $parent = $row['parent_id'];
    db()->prepare('UPDATE camera_folders SET parent_id = ? WHERE parent_id = ?')->execute([$parent, $id]);
    db()->prepare('UPDATE cameras SET folder_id = ? WHERE folder_id = ?')->execute([$parent, $id]);
    db()->prepare('DELETE FROM camera_folders WHERE id = ?')->execute([$id]);
}

function folderExists(string $table, int $id): bool {
    $st = db()->prepare("SELECT 1 FROM $table WHERE id = ?");
    $st->execute([$id]);
    return (bool) $st->fetch();
}

/** האם $candidate נמצא בתת-העץ של $root (כולל שווה לו). */
function isDescendant(string $table, int $candidate, int $root): bool {
    $seen = 0;
    $cur = $candidate;
    while ($cur !== null && $seen++ < 100) {
        if ($cur === $root) return true;
        $st = db()->prepare("SELECT parent_id FROM $table WHERE id = ?");
        $st->execute([$cur]);
        $p = $st->fetchColumn();
        $cur = ($p === false || $p === null) ? null : (int) $p;
    }
    return false;
}

/* ───────── מצלמות ───────── */

function validCameraKey(string $k): bool {
    return (bool) preg_match('/^[a-z0-9][a-z0-9\-_]{1,31}$/', $k);
}

/** מייצר מפתח מהשם: "מצלמת חצר" → cam-1, "Front Door" → front-door. */
function suggestCameraKey(string $name): string {
    $k = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '');
    $k = trim($k, '-');
    if (!validCameraKey($k)) {
        $n = (int) db()->query('SELECT COUNT(*) FROM cameras')->fetchColumn() + 1;
        $k = 'cam-' . $n;
    }
    $base = $k; $i = 2;
    while (cameraByKey($k)) $k = $base . '-' . $i++;
    return $k;
}

function cameraByKey(string $key): ?array {
    $st = db()->prepare('SELECT * FROM cameras WHERE key = ?');
    $st->execute([$key]);
    return $st->fetch() ?: null;
}

function cameraById(int $id): array {
    $st = db()->prepare('SELECT * FROM cameras WHERE id = ?');
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) throw new AppError('אין מצלמה כזו', 404);
    return $c;
}

/** מה שהדפדפן רואה. הסיסמה לעולם לא חוזרת — רק "יש/אין". */
function cameraPublic(array $c): array {
    $c['has_password'] = ($c['password_enc'] ?? '') !== '' ? 1 : 0;
    unset($c['password_enc']);
    foreach (['id','folder_id','bridge_id','rtsp_port','http_port','onvif_port','has_ptz','has_audio',
              'record_audio','pre_seconds','post_seconds','retention_days','manual_recording','sort',
              'last_snapshot_id'] as $k) {
        if ($c[$k] !== null) $c[$k] = (int) $c[$k];
    }
    $c['schedule'] = json_decode($c['schedule'] ?: '[]', true) ?: [];
    $c['live_active'] = $c['live_wanted_until'] !== null && $c['live_wanted_until'] > nowIso();
    return $c;
}

function listCameras(): array {
    $rows = db()->query('SELECT c.*, (c.password_enc <> \'\') AS has_password
                         FROM cameras c ORDER BY c.sort, c.name')->fetchAll();
    return array_map(fn($c) => cameraPublic($c), $rows);
}

function saveCamera(?int $id, array $in): int {
    // עדכון חלקי: שדה שלא נשלח שומר את ערכו. כך "שנה רק את הגשר" לא
    // מאפס את לוח הזמנים.
    if ($id !== null) {
        $cur = cameraById($id);
        $cur['schedule'] = json_decode($cur['schedule'] ?: '[]', true) ?: [];
        unset($cur['password_enc'], $cur['id'], $cur['key'], $cur['created_at']);
        $in = $in + $cur;
    } elseif (!array_key_exists('has_audio', $in)) {
        $in['has_audio'] = 1;   // רוב מצלמות הבית עם מיקרופון
    }
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') throw new AppError('למצלמה צריך שם');

    $brand = (string) ($in['brand'] ?? 'reolink');
    if (!in_array($brand, BRANDS, true)) throw new AppError('סוג מצלמה לא מוכר');
    $mode = (string) ($in['record_mode'] ?? 'motion');
    if (!in_array($mode, RECORD_MODES, true)) throw new AppError('מצב הקלטה לא מוכר');

    $host = trim((string) ($in['host'] ?? ''));
    if ($host !== '' && !preg_match('/^[a-zA-Z0-9.\-:\[\]]{1,253}$/', $host)) throw new AppError('כתובת לא תקינה');

    $schedule = $in['schedule'] ?? [];
    if (!is_array($schedule)) $schedule = [];
    $schedule = array_values(array_filter(array_map('normalizeScheduleWindow', $schedule)));

    $folderId = isset($in['folder_id']) && $in['folder_id'] !== '' && $in['folder_id'] !== null ? (int) $in['folder_id'] : null;
    if ($folderId !== null && !folderExists('camera_folders', $folderId)) throw new AppError('התיקייה לא קיימת');
    $bridgeId = isset($in['bridge_id']) && $in['bridge_id'] !== '' && $in['bridge_id'] !== null ? (int) $in['bridge_id'] : null;
    if ($bridgeId !== null && !folderExists('bridges', $bridgeId)) throw new AppError('הגשר לא קיים');

    $port = fn(string $k, int $d) => max(1, min(65535, (int) ($in[$k] ?? $d)));
    $fields = [
        'name'           => $name,
        'folder_id'      => $folderId,
        'bridge_id'      => $bridgeId,
        'brand'          => $brand,
        'host'           => $host,
        'rtsp_port'      => $port('rtsp_port', 554),
        'http_port'      => $port('http_port', 80),
        'onvif_port'     => $port('onvif_port', 8000),
        'username'       => trim((string) ($in['username'] ?? 'admin')),
        'stream_main'    => trim((string) ($in['stream_main'] ?? '')),
        'stream_sub'     => trim((string) ($in['stream_sub'] ?? '')),
        'has_ptz'        => !empty($in['has_ptz']) ? 1 : 0,
        'has_audio'      => !empty($in['has_audio']) ? 1 : 0,
        'record_audio'   => !empty($in['record_audio']) ? 1 : 0,
        'record_mode'    => $mode,
        'schedule'       => json_encode($schedule, JSON_UNESCAPED_UNICODE),
        'pre_seconds'    => max(0, min(60, (int) ($in['pre_seconds'] ?? 10))),
        'post_seconds'   => max(0, min(300, (int) ($in['post_seconds'] ?? 20))),
        'retention_days' => max(0, min(3650, (int) ($in['retention_days'] ?? 30))),
        'notes'          => trim((string) ($in['notes'] ?? '')),
        'sort'           => (int) ($in['sort'] ?? 0),
    ];
    // סיסמה: שדה ריק = "לא לשנות". מחיקה מפורשת = clear_password.
    $pw = (string) ($in['password'] ?? '');

    if ($id === null) {
        $key = trim((string) ($in['key'] ?? ''));
        if ($key === '') $key = suggestCameraKey($name);
        if (!validCameraKey($key)) throw new AppError('מפתח: 2–32 תווים, אותיות לטיניות קטנות, ספרות, מקף');
        if (cameraByKey($key)) throw new AppError('המפתח תפוס');
        $fields['key'] = $key;
        $fields['password_enc'] = encryptSecret($pw);
        $fields['created_at'] = nowIso();
        $cols = implode(',', array_keys($fields));
        $qs   = implode(',', array_fill(0, count($fields), '?'));
        db()->prepare("INSERT INTO cameras ($cols) VALUES ($qs)")->execute(array_values($fields));
        $newId = (int) db()->lastInsertId();
        @mkdir(INBOX_DIR . '/' . $key, 0775, true);
        logEvent('camera.create', $name, ['key' => $key], 'info', $newId);
        return $newId;
    }

    cameraById($id);
    if ($pw !== '') $fields['password_enc'] = encryptSecret($pw);
    elseif (!empty($in['clear_password'])) $fields['password_enc'] = '';
    $set = implode(',', array_map(fn($k) => "$k = ?", array_keys($fields)));
    db()->prepare("UPDATE cameras SET $set WHERE id = ?")->execute([...array_values($fields), $id]);
    logEvent('camera.update', $name, [], 'info', $id);
    return $id;
}

function normalizeScheduleWindow($w): ?array {
    if (!is_array($w)) return null;
    $days = array_values(array_unique(array_filter(array_map('intval', (array) ($w['days'] ?? [])), fn($d) => $d >= 0 && $d <= 6)));
    $from = (string) ($w['from'] ?? '');
    $to   = (string) ($w['to'] ?? '');
    if (!$days || !preg_match('/^\d{2}:\d{2}$/', $from) || !preg_match('/^\d{2}:\d{2}$/', $to)) return null;
    sort($days);
    return ['days' => $days, 'from' => $from, 'to' => $to];
}

function deleteCamera(int $id): void {
    $c = cameraById($id);
    // ההקלטות נמחקות מהמסד ב-CASCADE; הקבצים — כאן.
    $st = db()->prepare('SELECT path, thumb_path FROM recordings WHERE camera_id = ?');
    $st->execute([$id]);
    foreach ($st->fetchAll() as $r) {
        foreach ([$r['path'], $r['thumb_path']] as $p) {
            if ($p) @unlink(MEDIA_DIR . '/' . $p);
        }
    }
    db()->prepare('DELETE FROM cameras WHERE id = ?')->execute([$id]);
    logEvent('camera.delete', $c['name'], ['key' => $c['key']], 'warn', $id);
}

function setManualRecording(int $id, bool $on, ?int $userId): void {
    cameraById($id);
    db()->prepare('UPDATE cameras SET manual_recording = ? WHERE id = ?')->execute([$on ? 1 : 0, $id]);
    logEvent($on ? 'record.start' : 'record.stop', '', [], 'info', $id, null, $userId);
}

/** מישהו צופה חי: הגשר ישדר עוד 30 שניות מהרגע האחרון שהדפדפן ביקש. */
function touchLive(int $id): void {
    db()->prepare('UPDATE cameras SET live_wanted_until = ? WHERE id = ?')
        ->execute([isoFromTs(time() + 30), $id]);
}

/** מצב חיבור, לפי מה שהגשר דיווח. */
function setCameraStatus(int $id, string $status): void {
    $st = db()->prepare('SELECT status, name FROM cameras WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) return;
    $seen = $status === 'online' ? nowIso() : null;
    if ($seen) db()->prepare('UPDATE cameras SET status = ?, last_seen_at = ? WHERE id = ?')->execute([$status, $seen, $id]);
    else       db()->prepare('UPDATE cameras SET status = ? WHERE id = ?')->execute([$status, $id]);
    if ($row['status'] !== $status && $row['status'] !== 'unknown') {
        logEvent($status === 'online' ? 'camera.online' : 'camera.offline', $row['name'], [],
                 $status === 'online' ? 'info' : 'warn', $id);
    }
}

/**
 * מה שהגשר מקבל על מצלמה: כולל הסיסמה (הוא צריך אותה ל-RTSP) וכתובות
 * הזרם המחושבות. עובר רק על HTTPS ורק לגשר שהזדהה בטוקן.
 */
function cameraForBridge(array $c): array {
    $pw = decryptSecret($c['password_enc']);
    $auth = rawurlencode($c['username']) . ($pw !== '' ? ':' . rawurlencode($pw) : '');
    $base = "rtsp://{$auth}@{$c['host']}:{$c['rtsp_port']}";
    $main = $c['stream_main'] !== '' ? $c['stream_main']
          : ($c['brand'] === 'reolink' ? "$base/h264Preview_01_main" : "$base/");
    $sub  = $c['stream_sub'] !== '' ? $c['stream_sub']
          : ($c['brand'] === 'reolink' ? "$base/h264Preview_01_sub" : '');
    return [
        'id'            => (int) $c['id'],
        'key'           => $c['key'],
        'name'          => $c['name'],
        'brand'         => $c['brand'],
        'host'          => $c['host'],
        'http_port'     => (int) $c['http_port'],
        'onvif_port'    => (int) $c['onvif_port'],
        'username'      => $c['username'],
        'password'      => $pw,
        'stream_main'   => $main,
        'stream_sub'    => $sub,
        'has_ptz'       => (int) $c['has_ptz'],
        'record_audio'  => (int) $c['record_audio'],
        'record_mode'   => $c['record_mode'],
        'schedule'      => json_decode($c['schedule'] ?: '[]', true) ?: [],
        'pre_seconds'   => (int) $c['pre_seconds'],
        'post_seconds'  => (int) $c['post_seconds'],
        'manual_recording' => (int) $c['manual_recording'],
        'live_wanted'   => $c['live_wanted_until'] !== null && $c['live_wanted_until'] > nowIso(),
    ];
}
