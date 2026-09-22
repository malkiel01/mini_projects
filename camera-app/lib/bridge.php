<?php
/**
 * הגשר — הצד של השרת.
 *
 * הגשר הוא סקריפט (bridge/bridge.py) שרץ ברשת הביתית. הוא היחיד שמגיע
 * למצלמות, ולכן כל מה שדורש מגע במצלמה — חי, PTZ, הקלטה יזומה, צילום —
 * עובר דרכו. הכיוון תמיד ממנו אלינו: הוא מתקשר, אנחנו עונים. השרת לא
 * יכול (ולא צריך) להגיע אליו.
 *
 * צימוד: המנהל יוצר גשר ומקבל קוד בן 6 תווים לרבע שעה. הסקריפט שולח את
 * הקוד ומקבל טוקן קבוע. מרגע זה כל בקשה שלו נושאת X-Bridge-Token.
 */

declare(strict_types=1);

require_once __DIR__ . '/cameras.php';
require_once __DIR__ . '/crypto.php';

const PAIR_TTL_SECONDS = 15 * 60;
const COMMAND_TTL_SECONDS = 120;

function listBridges(): array {
    $rows = db()->query('SELECT id, name, pair_code, pair_expires_at, paired_at, last_seen_at, version, local_ip, info, created_at
                         FROM bridges ORDER BY id')->fetchAll();
    $now = nowIso();
    foreach ($rows as &$b) {
        $b['id'] = (int) $b['id'];
        $b['online'] = $b['last_seen_at'] !== null && $b['last_seen_at'] > isoFromTs(time() - 180);
        $b['pair_code_valid'] = $b['pair_code'] !== null && $b['paired_at'] === null && $b['pair_expires_at'] > $now;
        if (!$b['pair_code_valid']) $b['pair_code'] = null;
        $b['info'] = $b['info'] ? (json_decode($b['info'], true) ?: []) : [];
        $st = db()->prepare('SELECT COUNT(*) FROM cameras WHERE bridge_id = ?');
        $st->execute([$b['id']]);
        $b['cameras'] = (int) $st->fetchColumn();
    }
    return $rows;
}

function createBridge(string $name): array {
    $name = trim($name) !== '' ? trim($name) : 'גשר';
    $code = pairCode();
    db()->prepare('INSERT INTO bridges (name, pair_code, pair_expires_at, created_at) VALUES (?,?,?,?)')
        ->execute([$name, $code, isoFromTs(time() + PAIR_TTL_SECONDS), nowIso()]);
    $id = (int) db()->lastInsertId();
    logEvent('bridge.create', $name, [], 'info', null, $id);
    return ['id' => $id, 'pair_code' => $code];
}

/** קוד חדש לגשר קיים — כשהקודם פג, או כשמתקינים מחדש את הקופסה. */
function renewPairCode(int $id): string {
    $code = pairCode();
    $st = db()->prepare('UPDATE bridges SET pair_code = ?, pair_expires_at = ?, paired_at = NULL, token_hash = NULL WHERE id = ?');
    $st->execute([$code, isoFromTs(time() + PAIR_TTL_SECONDS), $id]);
    if ($st->rowCount() === 0) throw new AppError('אין גשר כזה', 404);
    return $code;
}

function pairCode(): string {
    // בלי 0/O/1/I — הקוד מוקלד ביד.
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < 6; $i++) $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $s;
}

function renameBridge(int $id, string $name): void {
    $name = trim($name);
    if ($name === '') throw new AppError('לגשר צריך שם');
    db()->prepare('UPDATE bridges SET name = ? WHERE id = ?')->execute([$name, $id]);
}

function deleteBridge(int $id): void {
    db()->prepare('DELETE FROM bridges WHERE id = ?')->execute([$id]);
    logEvent('bridge.delete', '', [], 'warn', null, $id);
}

/** הסקריפט שולח את הקוד; מקבל טוקן. הקוד נשרף. */
function pairBridge(string $code, array $info): array {
    $code = strtoupper(trim($code));
    $st = db()->prepare('SELECT * FROM bridges WHERE pair_code = ? AND paired_at IS NULL');
    $st->execute([$code]);
    $b = $st->fetch();
    if (!$b || $b['pair_expires_at'] < nowIso()) throw new AppError('קוד צימוד לא תקף. יש ליצור קוד חדש במסך הגשרים', 401);
    $token = randomToken(32);
    db()->prepare('UPDATE bridges SET token_hash = ?, pair_code = NULL, paired_at = ?, last_seen_at = ?, version = ?, local_ip = ?, info = ? WHERE id = ?')
        ->execute([hash('sha256', $token), nowIso(), nowIso(), (string) ($info['version'] ?? ''),
                   (string) ($info['local_ip'] ?? ''), json_encode($info, JSON_UNESCAPED_UNICODE), (int) $b['id']]);
    logEvent('bridge.paired', $b['name'], ['version' => $info['version'] ?? ''], 'info', null, (int) $b['id']);
    return ['bridge_id' => (int) $b['id'], 'token' => $token, 'name' => $b['name']];
}

function bridgeByToken(?string $token): ?array {
    if (!$token) return null;
    $st = db()->prepare('SELECT * FROM bridges WHERE token_hash = ?');
    $st->execute([hash('sha256', $token)]);
    return $st->fetch() ?: null;
}

function requireBridge(): array {
    $token = $_SERVER['HTTP_X_BRIDGE_TOKEN'] ?? ($_GET['bridge_token'] ?? ($_POST['bridge_token'] ?? null));
    $b = bridgeByToken(is_string($token) ? $token : null);
    if (!$b) throw new AppError('גשר לא מזוהה', 401);
    return $b;
}

/**
 * heartbeat: הגשר מדווח על עצמו ועל המצלמות, ומקבל בחזרה את התצורה
 * המלאה (כולל סיסמאות) ואת הפקודות שמחכות לו. פעם ב-5 שניות.
 */
function bridgeHeartbeat(array $bridge, array $report): array {
    $bid = (int) $bridge['id'];
    $wasOffline = $bridge['last_seen_at'] === null || $bridge['last_seen_at'] < isoFromTs(time() - 180);
    db()->prepare('UPDATE bridges SET last_seen_at = ?, version = COALESCE(NULLIF(?, \'\'), version),
                   local_ip = COALESCE(NULLIF(?, \'\'), local_ip), info = ? WHERE id = ?')
        ->execute([nowIso(), (string) ($report['version'] ?? ''), (string) ($report['local_ip'] ?? ''),
                   json_encode($report['info'] ?? [], JSON_UNESCAPED_UNICODE), $bid]);
    if ($wasOffline && $bridge['paired_at'] !== null) logEvent('bridge.online', $bridge['name'], [], 'info', null, $bid);

    // מצב המצלמות כפי שהגשר רואה אותן.
    foreach ((array) ($report['cameras'] ?? []) as $key => $state) {
        $c = cameraByKey((string) $key);
        if (!$c || (int) $c['bridge_id'] !== $bid) continue;
        setCameraStatus((int) $c['id'], !empty($state['online']) ? 'online' : 'offline');
        foreach ((array) ($state['events'] ?? []) as $ev) {
            $kind = (string) ($ev['kind'] ?? 'motion');
            logEvent('detect.' . $kind, $c['name'], ['state' => $ev['state'] ?? null], 'info', (int) $c['id'], $bid);
        }
    }

    // פקודות ישנות שלא נלקחו — פגו.
    db()->prepare("UPDATE commands SET status = 'expired' WHERE bridge_id = ? AND status = 'pending' AND created_at < ?")
        ->execute([$bid, isoFromTs(time() - COMMAND_TTL_SECONDS)]);
    $st = db()->prepare("SELECT id, camera_id, type, payload, created_at FROM commands WHERE bridge_id = ? AND status = 'pending' ORDER BY id");
    $st->execute([$bid]);
    $cmds = $st->fetchAll();
    if ($cmds) {
        $ids = array_map(fn($c) => (int) $c['id'], $cmds);
        db()->exec("UPDATE commands SET status = 'taken', taken_at = '" . nowIso() . "' WHERE id IN (" . implode(',', $ids) . ')');
    }

    $st = db()->prepare('SELECT * FROM cameras WHERE bridge_id = ? ORDER BY sort, name');
    $st->execute([$bid]);
    $cameras = array_map('cameraForBridge', $st->fetchAll());

    return [
        'bridge'   => ['id' => $bid, 'name' => $bridge['name']],
        'cameras'  => $cameras,
        'commands' => array_map(fn($c) => [
            'id' => (int) $c['id'], 'camera_id' => $c['camera_id'] !== null ? (int) $c['camera_id'] : null,
            'type' => $c['type'], 'payload' => json_decode($c['payload'], true) ?: [],
        ], $cmds),
        'server_time' => nowIso(),
        'settings' => [
            'segment_seconds' => (int) settingGet('segment_seconds', '60'),
            'live_segment_seconds' => (int) settingGet('live_segment_seconds', '2'),
        ],
    ];
}

/** הממשק שם פקודה בתור. מחזיר את המזהה, כדי שהדפדפן יוכל לשאול מה קרה. */
function enqueueCommand(int $cameraId, string $type, array $payload, ?int $userId): int {
    $c = cameraById($cameraId);
    if ($c['bridge_id'] === null) throw new AppError('המצלמה אינה מחוברת לגשר. חי, PTZ והקלטה יזומה דורשים גשר ברשת של המצלמה', 409);
    $allowed = ['ptz', 'ptz_stop', 'zoom', 'preset_goto', 'preset_set', 'snapshot', 'probe', 'reboot', 'talk'];
    if (!in_array($type, $allowed, true)) throw new AppError('פקודה לא מוכרת');
    db()->prepare('INSERT INTO commands (bridge_id, camera_id, type, payload, created_by, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([(int) $c['bridge_id'], $cameraId, $type, json_encode($payload, JSON_UNESCAPED_UNICODE), $userId, nowIso()]);
    return (int) db()->lastInsertId();
}

function commandStatus(int $id): array {
    $st = db()->prepare('SELECT id, type, status, result, created_at, done_at FROM commands WHERE id = ?');
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) throw new AppError('אין פקודה כזו', 404);
    $c['id'] = (int) $c['id'];
    $c['result'] = $c['result'] ? (json_decode($c['result'], true) ?? $c['result']) : null;
    return $c;
}

function completeCommand(array $bridge, int $id, bool $ok, $result): void {
    $st = db()->prepare("UPDATE commands SET status = ?, result = ?, done_at = ? WHERE id = ? AND bridge_id = ?");
    $st->execute([$ok ? 'done' : 'failed', json_encode($result, JSON_UNESCAPED_UNICODE), nowIso(), $id, (int) $bridge['id']]);
}

/* ───────── שידור חי ───────── */

/** נתיב תיקיית החי של מצלמה. מוודא קיום. */
function liveDir(string $cameraKey): string {
    $d = LIVE_DIR . '/' . $cameraKey;
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d;
}

/**
 * הגשר מעלה מקטע HLS (או את הפלייליסט). שמות מוגבלים לתבנית קבועה —
 * זה קלט מבחוץ שנכתב לדיסק.
 */
function storeLiveFile(array $bridge, array $camera, string $name, string $tmpPath): void {
    if ((int) $camera['bridge_id'] !== (int) $bridge['id']) throw new AppError('המצלמה אינה של הגשר הזה', 403);
    if (!preg_match('/^(live\.m3u8|init\.mp4|seg\d{1,8}\.(ts|m4s))$/', $name)) throw new AppError('שם מקטע לא תקין');
    $dest = liveDir($camera['key']) . '/' . $name;
    if (!@move_uploaded_file($tmpPath, $dest) && !@rename($tmpPath, $dest)) throw new AppError('לא ניתן לשמור מקטע', 500);
}

function liveStatus(array $camera): array {
    $d = LIVE_DIR . '/' . $camera['key'];
    $pl = "$d/live.m3u8";
    $fresh = is_file($pl) && time() - filemtime($pl) < 20;
    return [
        'available' => $fresh,
        'bridge_online' => $camera['bridge_id'] !== null && bridgeOnline((int) $camera['bridge_id']),
        'playlist' => $fresh ? "media.php?live=" . rawurlencode($camera['key']) . "&f=live.m3u8" : null,
        'updated_at' => is_file($pl) ? isoFromTs(filemtime($pl)) : null,
    ];
}

function bridgeOnline(int $id): bool {
    $st = db()->prepare('SELECT last_seen_at FROM bridges WHERE id = ?');
    $st->execute([$id]);
    $t = $st->fetchColumn();
    return $t !== false && $t !== null && $t > isoFromTs(time() - 180);
}
