<?php
/**
 * הקטלוג: הקלטות וצילומים, תיקיות נושא, סימניות.
 *
 * הקובץ הוא האמת על הווידאו; המסד הוא האמת על מה שאנחנו יודעים עליו —
 * מאיזו מצלמה, מתי, למה (תנועה/אדם/ידני), ומה המשתמש כתב עליו.
 * שני מקורות מכניסים לכאן: סריקת תיבת ה-FTP (ingest.php) והגשר (upload.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const TRIGGERS = ['', 'motion', 'person', 'pet', 'vehicle', 'manual', 'continuous', 'schedule'];

/* ───────── תיקיות נושא ───────── */

function listTopics(): array {
    return db()->query('SELECT t.id, t.parent_id, t.name, t.description,
                               (SELECT COUNT(*) FROM recording_topics rt WHERE rt.topic_id = t.id) AS count
                        FROM topics t ORDER BY t.name')->fetchAll();
}

function saveTopic(?int $id, string $name, ?int $parentId, string $description = ''): int {
    $name = trim($name);
    if ($name === '') throw new AppError('לתיקייה צריך שם');
    if ($parentId !== null && !folderExists('topics', $parentId)) throw new AppError('תיקיית האב לא קיימת');
    if ($id === null) {
        db()->prepare('INSERT INTO topics (parent_id, name, description, created_at) VALUES (?,?,?,?)')
            ->execute([$parentId, $name, trim($description), nowIso()]);
        return (int) db()->lastInsertId();
    }
    if ($parentId !== null && isDescendant('topics', $parentId, $id)) throw new AppError('אי אפשר להעביר תיקייה לתוך צאצא שלה');
    db()->prepare('UPDATE topics SET name = ?, parent_id = ?, description = ? WHERE id = ?')
        ->execute([$name, $parentId, trim($description), $id]);
    return $id;
}

function deleteTopic(int $id): void {
    $st = db()->prepare('SELECT parent_id FROM topics WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) throw new AppError('אין תיקייה כזו', 404);
    db()->prepare('UPDATE topics SET parent_id = ? WHERE parent_id = ?')->execute([$row['parent_id'], $id]);
    db()->prepare('DELETE FROM topics WHERE id = ?')->execute([$id]);
}

/* ───────── הכנסה לקטלוג ───────── */

/**
 * רושם קובץ שכבר יושב ב-MEDIA_DIR. מחזיר את המזהה, או את הקיים אם הנתיב
 * כבר רשום (סריקה כפולה אינה יוצרת כפילות).
 */
function registerRecording(int $cameraId, string $kind, string $relPath, array $meta = []): int {
    $st = db()->prepare('SELECT id FROM recordings WHERE path = ?');
    $st->execute([$relPath]);
    if ($existing = $st->fetchColumn()) return (int) $existing;

    $full = MEDIA_DIR . '/' . $relPath;
    $trigger = (string) ($meta['trigger'] ?? '');
    if (!in_array($trigger, TRIGGERS, true)) $trigger = '';
    $source = (string) ($meta['source'] ?? 'ftp');
    if (!in_array($source, ['ftp', 'bridge', 'player', 'upload'], true)) $source = 'ftp';

    db()->prepare('INSERT INTO recordings
        (camera_id, kind, source, trigger_kind, path, thumb_path, size_bytes, started_at, duration_s,
         width, height, title, description, parent_id, created_by, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([
        $cameraId, $kind, $source, $trigger, $relPath, $meta['thumb_path'] ?? null,
        is_file($full) ? filesize($full) : 0,
        $meta['started_at'] ?? nowIso(),
        isset($meta['duration_s']) ? (float) $meta['duration_s'] : null,
        $meta['width'] ?? null, $meta['height'] ?? null,
        (string) ($meta['title'] ?? ''), (string) ($meta['description'] ?? ''),
        $meta['parent_id'] ?? null, $meta['created_by'] ?? null, nowIso(),
      ]);
    $id = (int) db()->lastInsertId();
    if ($kind === 'snapshot') {
        db()->prepare('UPDATE cameras SET last_snapshot_id = ? WHERE id = ?')->execute([$id, $cameraId]);
    }
    return $id;
}

/** נתיב יעד תחת MEDIA_DIR: <key>/<yyyy>/<mm>/<dd>/<HHMMSS>_<kind>_<rand>.<ext> */
function mediaPathFor(string $cameraKey, string $kind, string $startedAtIso, string $ext): string {
    $ts = strtotime($startedAtIso) ?: time();
    $dir = sprintf('%s/%s', $cameraKey, gmdate('Y/m/d', $ts));
    @mkdir(MEDIA_DIR . '/' . $dir, 0775, true);
    return sprintf('%s/%s_%s_%s.%s', $dir, gmdate('His', $ts), $kind === 'video' ? 'v' : 's',
                   substr(bin2hex(random_bytes(3)), 0, 5), $ext);
}

/* ───────── שליפה ───────── */

function recordingById(int $id): array {
    $st = db()->prepare('SELECT * FROM recordings WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) throw new AppError('אין הקלטה כזו', 404);
    return recordingPublic($r);
}

function recordingPublic(array $r): array {
    foreach (['id','camera_id','size_bytes','width','height','starred','locked','parent_id','created_by'] as $k) {
        if ($r[$k] !== null) $r[$k] = (int) $r[$k];
    }
    if ($r['duration_s'] !== null) $r['duration_s'] = (float) $r['duration_s'];
    $r['tags'] = $r['tags'] === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $r['tags']))));
    $st = db()->prepare('SELECT topic_id FROM recording_topics WHERE recording_id = ?');
    $st->execute([$r['id']]);
    $r['topics'] = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    return $r;
}

/**
 * רשימה עם מסננים. כולם אופציונליים:
 * camera_id, kind, topic_id, from, to (ISO), starred, q (חיפוש בכותרת/תיאור/תגיות),
 * trigger, limit, before_id (דפדוף אחורה).
 */
function listRecordings(array $f): array {
    $where = []; $args = [];
    if (!empty($f['camera_id']))  { $where[] = 'r.camera_id = ?';   $args[] = (int) $f['camera_id']; }
    if (!empty($f['camera_ids']) && is_array($f['camera_ids'])) {
        $ids = array_map('intval', $f['camera_ids']);
        if ($ids) { $where[] = 'r.camera_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; array_push($args, ...$ids); }
    }
    if (!empty($f['kind']))       { $where[] = 'r.kind = ?';        $args[] = (string) $f['kind']; }
    if (!empty($f['trigger']))    { $where[] = 'r.trigger_kind = ?'; $args[] = (string) $f['trigger']; }
    if (!empty($f['from']))       { $where[] = 'r.started_at >= ?'; $args[] = (string) $f['from']; }
    if (!empty($f['to']))         { $where[] = 'r.started_at < ?';  $args[] = (string) $f['to']; }
    if (!empty($f['starred']))    { $where[] = 'r.starred = 1'; }
    if (!empty($f['topic_id']))   { $where[] = 'EXISTS (SELECT 1 FROM recording_topics rt WHERE rt.recording_id = r.id AND rt.topic_id = ?)'; $args[] = (int) $f['topic_id']; }
    if (!empty($f['before_id']))  { $where[] = 'r.id < ?'; $args[] = (int) $f['before_id']; }
    if (!empty($f['q'])) {
        $q = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $f['q']) . '%';
        $where[] = "(r.title LIKE ? ESCAPE '\\' OR r.description LIKE ? ESCAPE '\\' OR r.tags LIKE ? ESCAPE '\\')";
        array_push($args, $q, $q, $q);
    }
    $limit = max(1, min(500, (int) ($f['limit'] ?? 100)));
    $order = ($f['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
    $sql = 'SELECT r.* FROM recordings r' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . " ORDER BY r.started_at $order, r.id $order LIMIT $limit";
    $st = db()->prepare($sql);
    $st->execute($args);
    return array_map('recordingPublic', $st->fetchAll());
}

/** ציר הזמן של יום אחד: מקטעים (התחלה, משך, טריגר) — קל מרשימה מלאה. */
function dayTimeline(int $cameraId, string $dayIso): array {
    $ts = strtotime($dayIso . 'T00:00:00Z');
    if ($ts === false) throw new AppError('תאריך לא תקין');
    $from = isoFromTs($ts); $to = isoFromTs($ts + 86400);
    $st = db()->prepare('SELECT id, kind, trigger_kind, started_at, duration_s, starred, thumb_path
                         FROM recordings WHERE camera_id = ? AND started_at >= ? AND started_at < ?
                         ORDER BY started_at');
    $st->execute([$cameraId, $from, $to]);
    return $st->fetchAll();
}

/** באילו ימים יש הקלטות (ללוח השנה). */
function daysWithRecordings(int $cameraId, string $monthIso): array {
    $st = db()->prepare("SELECT substr(started_at,1,10) AS day, COUNT(*) AS n,
                                SUM(CASE WHEN trigger_kind IN ('person','pet','vehicle') THEN 1 ELSE 0 END) AS ai
                         FROM recordings WHERE camera_id = ? AND started_at LIKE ?
                         GROUP BY day ORDER BY day");
    $st->execute([$cameraId, $monthIso . '%']);
    return $st->fetchAll();
}

/* ───────── עריכה ───────── */

function updateRecording(int $id, array $in): void {
    recordingById($id);
    $sets = []; $args = [];
    if (array_key_exists('title', $in))       { $sets[] = 'title = ?';       $args[] = mb_substr(trim((string) $in['title']), 0, 200); }
    if (array_key_exists('description', $in)) { $sets[] = 'description = ?'; $args[] = mb_substr(trim((string) $in['description']), 0, 5000); }
    if (array_key_exists('tags', $in)) {
        $tags = is_array($in['tags']) ? $in['tags'] : explode(',', (string) $in['tags']);
        $tags = array_values(array_unique(array_filter(array_map(fn($t) => mb_substr(trim((string) $t), 0, 40), $tags))));
        $sets[] = 'tags = ?'; $args[] = implode(',', $tags);
    }
    if (array_key_exists('starred', $in)) { $sets[] = 'starred = ?'; $args[] = $in['starred'] ? 1 : 0; }
    if (array_key_exists('locked', $in))  { $sets[] = 'locked = ?';  $args[] = $in['locked'] ? 1 : 0; }
    if (array_key_exists('trigger_kind', $in) && in_array((string) $in['trigger_kind'], TRIGGERS, true)) {
        $sets[] = 'trigger_kind = ?'; $args[] = (string) $in['trigger_kind'];
    }
    if ($sets) {
        $args[] = $id;
        db()->prepare('UPDATE recordings SET ' . implode(',', $sets) . ' WHERE id = ?')->execute($args);
    }
    if (array_key_exists('topics', $in) && is_array($in['topics'])) {
        db()->prepare('DELETE FROM recording_topics WHERE recording_id = ?')->execute([$id]);
        $ins = db()->prepare('INSERT OR IGNORE INTO recording_topics (recording_id, topic_id) VALUES (?,?)');
        foreach (array_unique(array_map('intval', $in['topics'])) as $t) {
            if ($t > 0 && folderExists('topics', $t)) $ins->execute([$id, $t]);
        }
    }
}

function deleteRecording(int $id, ?int $userId = null, bool $force = false): void {
    $r = recordingById($id);
    if ($r['locked'] && !$force) throw new AppError('ההקלטה נעולה. יש לשחרר את הנעילה לפני מחיקה');
    foreach ([$r['path'], $r['thumb_path']] as $p) {
        if ($p) @unlink(MEDIA_DIR . '/' . $p);
    }
    db()->prepare('DELETE FROM recordings WHERE id = ?')->execute([$id]);
    logEvent('recording.delete', basename($r['path']), ['kind' => $r['kind']], 'info', $r['camera_id'], null, $userId);
}

/* ───────── סימניות ───────── */

function listBookmarks(int $recordingId): array {
    $st = db()->prepare('SELECT id, at_seconds, note, created_at FROM bookmarks WHERE recording_id = ? ORDER BY at_seconds');
    $st->execute([$recordingId]);
    return array_map(fn($b) => ['id' => (int) $b['id'], 'at_seconds' => (float) $b['at_seconds'],
                                'note' => $b['note'], 'created_at' => $b['created_at']], $st->fetchAll());
}

function addBookmark(int $recordingId, float $at, string $note, ?int $userId): int {
    recordingById($recordingId);
    db()->prepare('INSERT INTO bookmarks (recording_id, at_seconds, note, created_by, created_at) VALUES (?,?,?,?,?)')
        ->execute([$recordingId, max(0, $at), mb_substr(trim($note), 0, 500), $userId, nowIso()]);
    return (int) db()->lastInsertId();
}

function deleteBookmark(int $id): void {
    db()->prepare('DELETE FROM bookmarks WHERE id = ?')->execute([$id]);
}

/* ───────── סטטיסטיקה ───────── */

function storageStats(): array {
    $row = db()->query('SELECT COUNT(*) AS n, COALESCE(SUM(size_bytes),0) AS bytes,
                               SUM(kind = \'video\') AS videos, SUM(kind = \'snapshot\') AS snapshots,
                               MIN(started_at) AS oldest FROM recordings')->fetch();
    $per = db()->query('SELECT camera_id, COUNT(*) AS n, COALESCE(SUM(size_bytes),0) AS bytes
                        FROM recordings GROUP BY camera_id')->fetchAll();
    return [
        'count'     => (int) $row['n'],
        'bytes'     => (int) $row['bytes'],
        'videos'    => (int) $row['videos'],
        'snapshots' => (int) $row['snapshots'],
        'oldest'    => $row['oldest'],
        'per_camera'=> array_map(fn($p) => ['camera_id' => (int) $p['camera_id'], 'count' => (int) $p['n'], 'bytes' => (int) $p['bytes']], $per),
        'cap_bytes' => (int) settingGet('storage_cap_bytes', '0'),
    ];
}
