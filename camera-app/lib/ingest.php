<?php
/**
 * קליטה מתיבת ה-FTP, תמונות ממוזערות, ומחיקה לפי מדיניות.
 *
 * המצלמה (Reolink ודומותיה) מעלה ב-FTP ל-data/inbox/<key>/ — בעצמה, בלי
 * גשר. הסריקה כאן לוקחת כל קובץ שהעלאתו הסתיימה, מזהה מתי צולם (מתוך
 * השם, ואם אין — מזמן הקובץ), מעבירה אותו ל-data/media/ ורושמת בקטלוג.
 *
 * "העלאה הסתיימה" = הקובץ לא השתנה 45 שניות. FTP לא מודיע על סיום;
 * קובץ שעדיין נכתב היה נכנס לקטלוג חצי.
 */

declare(strict_types=1);

require_once __DIR__ . '/recordings.php';
require_once __DIR__ . '/cameras.php';

const INGEST_SETTLE_SECONDS = 45;
const VIDEO_EXT = ['mp4', 'mov', 'mkv', 'ts', 'm4v'];
const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp'];

/**
 * מפרש שם קובץ של מצלמה. Reolink: "<שם>_<ערוץ>_<YYYYMMDDHHMMSS>.mp4" ולעיתים
 * עם קידומת של סוג האירוע ("MD" תנועה, "PEOPLE", "VEHICLE"). מצלמות אחרות
 * שמות אחרת, ולכן החוקים רופפים: כל 14 ספרות רצופות הן חותמת זמן, וכל
 * מילה מוכרת היא טריגר. מה שלא זוהה — NULL, והמתקשר ישלים מזמן הקובץ.
 */
function parseCameraFilename(string $name): array {
    $out = ['started_at' => null, 'trigger' => ''];
    if (preg_match('/(20\d{2})(\d{2})(\d{2})[_\-T]?(\d{2})(\d{2})(\d{2})/', $name, $m)) {
        $ts = gmmktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]);
        // המצלמה כותבת שעה מקומית. ההיסט המקומי של השרת הוא הניחוש הטוב
        // ביותר שיש בלי הגדרה; מנהל יכול לדרוס ב-settings (camera_tz_offset_min).
        $offset = (int) settingGet('camera_tz_offset_min', (string) cameraTzOffsetDefault());
        $ts -= $offset * 60;
        if ($ts > 946684800 && $ts < time() + 86400) $out['started_at'] = isoFromTs($ts);
    }
    $u = strtoupper($name);
    foreach (['PEOPLE' => 'person', 'PERSON' => 'person', 'HUMAN' => 'person', 'VEHICLE' => 'vehicle',
              'CAR' => 'vehicle', 'PET' => 'pet', 'DOG' => 'pet', 'ANIMAL' => 'pet', 'MD' => 'motion',
              'MOTION' => 'motion', 'ALARM' => 'motion', 'TIMER' => 'schedule', 'SCHEDULE' => 'schedule',
              'MANUAL' => 'manual'] as $needle => $trigger) {
        if (preg_match('/(^|[_\-\s.])' . $needle . '([_\-\s.]|$)/', $u)) { $out['trigger'] = $trigger; break; }
    }
    return $out;
}

/** ההיסט של ישראל בדקות, לפי שעון הקיץ הנוכחי. */
function cameraTzOffsetDefault(): int {
    try {
        $tz = new DateTimeZone('Asia/Jerusalem');
        return intdiv($tz->getOffset(new DateTime('now', $tz)), 60);
    } catch (Throwable) {
        return 120;
    }
}

/** סורק את כל תיבות ה-FTP. מחזיר כמה נקלטו. */
function scanInbox(): array {
    $result = ['ingested' => 0, 'skipped' => 0, 'errors' => []];
    if (!is_dir(INBOX_DIR)) return $result;
    foreach (scandir(INBOX_DIR) ?: [] as $key) {
        if ($key[0] === '.' || !is_dir(INBOX_DIR . '/' . $key)) continue;
        $camera = cameraByKey($key);
        if (!$camera) { $result['skipped']++; continue; }   // תיקייה של מצלמה שנמחקה — משאירים
        ingestDir(INBOX_DIR . '/' . $key, $camera, $result);
    }
    if ($result['ingested'] > 0) logEvent('ingest', "נקלטו {$result['ingested']} קבצים מה-FTP");
    return $result;
}

function ingestDir(string $dir, array $camera, array &$result, int $depth = 0): void {
    if ($depth > 6) return;
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry[0] === '.') continue;
        $full = $dir . '/' . $entry;
        if (is_dir($full)) {
            // Reolink יוצרת תת-תיקיות לפי תאריך. נכנסים, ומנקים תיקייה ריקה.
            ingestDir($full, $camera, $result, $depth + 1);
            if (count(scandir($full) ?: []) <= 2) @rmdir($full);
            continue;
        }
        $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        $kind = in_array($ext, VIDEO_EXT, true) ? 'video' : (in_array($ext, IMAGE_EXT, true) ? 'snapshot' : null);
        if ($kind === null) { $result['skipped']++; continue; }
        if (time() - filemtime($full) < INGEST_SETTLE_SECONDS) { $result['skipped']++; continue; }
        if (filesize($full) === 0) { @unlink($full); continue; }
        try {
            ingestFile($full, $camera, $kind, $ext, 'ftp');
            $result['ingested']++;
        } catch (Throwable $e) {
            $result['errors'][] = $entry . ': ' . $e->getMessage();
            logEvent('ingest.error', $entry . ': ' . $e->getMessage(), [], 'error', (int) $camera['id']);
        }
    }
}

/**
 * מעביר קובץ אחד ל-media/ ורושם. משמש גם את הגשר (upload.php) — שם
 * המידע כבר ידוע ומגיע ב-$meta במקום להיות מנוחש מהשם.
 */
function ingestFile(string $full, array $camera, string $kind, string $ext, string $source, array $meta = []): int {
    $parsed = parseCameraFilename(basename($full));
    $startedAt = $meta['started_at'] ?? $parsed['started_at'] ?? isoFromTs(filemtime($full) ?: time());
    $trigger   = $meta['trigger'] ?? $parsed['trigger'];
    if ($ext === 'jpeg') $ext = 'jpg';
    $rel = mediaPathFor($camera['key'], $kind, $startedAt, $ext);
    $dest = MEDIA_DIR . '/' . $rel;
    if (!@rename($full, $dest)) {
        // בין מערכות קבצים rename נכשל; מעתיקים ומוחקים.
        if (!@copy($full, $dest)) throw new AppError('לא ניתן להעביר את הקובץ ל-media', 500);
        @unlink($full);
    }
    $info = $kind === 'snapshot' ? imageInfo($dest) : [];
    $thumb = $kind === 'snapshot' ? makeImageThumb($rel) : null;
    return registerRecording((int) $camera['id'], $kind, $rel, [
        'source'      => $source,
        'trigger'     => $trigger,
        'started_at'  => $startedAt,
        'duration_s'  => $meta['duration_s'] ?? null,
        'width'       => $info['w'] ?? ($meta['width'] ?? null),
        'height'      => $info['h'] ?? ($meta['height'] ?? null),
        'thumb_path'  => $thumb,
        'title'       => $meta['title'] ?? '',
        'parent_id'   => $meta['parent_id'] ?? null,
        'created_by'  => $meta['created_by'] ?? null,
    ]);
}

function imageInfo(string $file): array {
    $i = @getimagesize($file);
    return $i ? ['w' => $i[0], 'h' => $i[1]] : [];
}

/** ממוזערת 480px לתמונה, ב-GD. מחזיר נתיב יחסי או NULL אם GD חסר. */
function makeImageThumb(string $rel): ?string {
    if (!function_exists('imagecreatefromstring')) return null;
    $src = MEDIA_DIR . '/' . $rel;
    $data = @file_get_contents($src);
    if ($data === false) return null;
    $im = @imagecreatefromstring($data);
    if (!$im) return null;
    $w = imagesx($im); $h = imagesy($im);
    $tw = 480; $th = (int) round($h * $tw / max(1, $w));
    if ($w <= $tw) { imagedestroy($im); return null; }   // כבר קטנה — המקור משמש כממוזערת
    $t = imagecreatetruecolor($tw, $th);
    imagecopyresampled($t, $im, 0, 0, 0, 0, $tw, $th, $w, $h);
    $thumbRel = preg_replace('/\.[a-z0-9]+$/i', '', $rel) . '_t.jpg';
    $ok = imagejpeg($t, MEDIA_DIR . '/' . $thumbRel, 80);
    imagedestroy($im); imagedestroy($t);
    return $ok ? $thumbRel : null;
}

/** הדפדפן שלח פוסטר לסרטון (אין ffmpeg באחסון משותף; הלקוח מייצר). */
function saveVideoPoster(int $recordingId, string $jpegData): string {
    $r = recordingById($recordingId);
    if (strlen($jpegData) > 2 * 1024 * 1024) throw new AppError('הפוסטר גדול מדי');
    $im = @imagecreatefromstring($jpegData);
    if (!$im) throw new AppError('הפוסטר אינו תמונה');
    $w = imagesx($im); $h = imagesy($im);
    $thumbRel = preg_replace('/\.[a-z0-9]+$/i', '', $r['path']) . '_t.jpg';
    imagejpeg($im, MEDIA_DIR . '/' . $thumbRel, 80);
    imagedestroy($im);
    db()->prepare('UPDATE recordings SET thumb_path = ?, width = COALESCE(width, ?), height = COALESCE(height, ?) WHERE id = ?')
        ->execute([$thumbRel, $w, $h, $recordingId]);
    return $thumbRel;
}

/* ───────── מדיניות מחיקה ───────── */

/**
 * שני כללים, לפי הסדר:
 * 1. ימי שמירה של המצלמה (retention_days; 0 = לנצח).
 * 2. תקרת נפח כללית (storage_cap_bytes; 0 = ללא) — מוחקים מהישן לחדש.
 * הקלטה נעולה או מסומנת בכוכב לא נמחקת בשום כלל.
 */
function applyRetention(): array {
    $deleted = 0; $freed = 0;
    foreach (db()->query('SELECT id, retention_days FROM cameras')->fetchAll() as $c) {
        $days = (int) $c['retention_days'];
        if ($days <= 0) continue;
        $st = db()->prepare('SELECT id, size_bytes FROM recordings WHERE camera_id = ? AND started_at < ?
                             AND locked = 0 AND starred = 0 LIMIT 500');
        $st->execute([(int) $c['id'], isoFromTs(time() - $days * 86400)]);
        foreach ($st->fetchAll() as $r) {
            deleteRecording((int) $r['id']); $deleted++; $freed += (int) $r['size_bytes'];
        }
    }
    $cap = (int) settingGet('storage_cap_bytes', '0');
    if ($cap > 0) {
        $total = (int) db()->query('SELECT COALESCE(SUM(size_bytes),0) FROM recordings')->fetchColumn();
        if ($total > $cap) {
            $st = db()->query('SELECT id, size_bytes FROM recordings WHERE locked = 0 AND starred = 0
                               ORDER BY started_at LIMIT 500');
            foreach ($st->fetchAll() as $r) {
                if ($total <= $cap) break;
                deleteRecording((int) $r['id']); $deleted++; $freed += (int) $r['size_bytes']; $total -= (int) $r['size_bytes'];
            }
        }
    }
    if ($deleted) logEvent('retention', "נמחקו $deleted הקלטות ישנות", ['freed' => $freed]);
    return ['deleted' => $deleted, 'freed' => $freed];
}

/** מקטעי שידור חי ישנים מדקתיים — זבל. */
function pruneLive(): void {
    if (!is_dir(LIVE_DIR)) return;
    foreach (scandir(LIVE_DIR) ?: [] as $key) {
        if ($key[0] === '.') continue;
        $d = LIVE_DIR . '/' . $key;
        if (!is_dir($d)) continue;
        foreach (scandir($d) ?: [] as $f) {
            if ($f[0] === '.') continue;
            if (time() - filemtime("$d/$f") > 120) @unlink("$d/$f");
        }
    }
}

/**
 * ריצת התחזוקה. cron קורא לזה כל דקה; ה-API קורא לזה "בדרך אגב" אם
 * עברה דקה — כך גם בלי cron מוגדר המערכת חיה, רק איטית יותר.
 */
function runMaintenance(bool $force = false): ?array {
    $last = (int) settingGet('maintenance_last_ts', '0');
    if (!$force && time() - $last < 60) return null;
    settingSet('maintenance_last_ts', (string) time());
    $out = ['inbox' => scanInbox()];
    // מחיקה — פעם בשעה מספיק.
    if ($force || time() - (int) settingGet('retention_last_ts', '0') > 3600) {
        settingSet('retention_last_ts', (string) time());
        $out['retention'] = applyRetention();
        pruneEvents();
    }
    pruneLive();
    markStaleOffline();
    return $out;
}

/** גשר שלא דיווח 3 דקות — המצלמות שלו לא זמינות. */
function markStaleOffline(): void {
    $cut = isoFromTs(time() - 180);
    $st = db()->query("SELECT id, name FROM bridges WHERE paired_at IS NOT NULL AND last_seen_at IS NOT NULL AND last_seen_at < '$cut'");
    foreach ($st->fetchAll() as $b) {
        $cams = db()->prepare("SELECT id FROM cameras WHERE bridge_id = ? AND status = 'online'");
        $cams->execute([(int) $b['id']]);
        foreach ($cams->fetchAll(PDO::FETCH_COLUMN) as $cid) setCameraStatus((int) $cid, 'offline');
    }
}
