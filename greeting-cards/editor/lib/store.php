<?php
/**
 * עיצובים וקבצים בשרת. כל גישה בודקת בעלות; מזהה שאינו בתבנית — נדחה לפני שהוא נוגע בדיסק.
 *
 * תמונות מפוענחות מחדש ב-GD לפני שמירה: זה מוכיח שזו תמונה, מסיר EXIF (מיקום GPS מהטלפון) ומקטין
 * תמונה ענקית. SVG לא מתקבל — הוא נושא סקריפט.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const ID_PATTERN = '/^[A-Za-z0-9_-]{6,40}$/';
const MAX_UPLOAD = 25 * 1024 * 1024;
const MAX_SIDE = 4096;
const MAX_DOC = 2 * 1024 * 1024;          // JSON של עיצוב — בלי תמונות בתוכו, אז זה הרבה
const USER_QUOTA = 1024 * 1024 * 1024;    // 1GB לכל משתמש

function checkId(mixed $id): string {
    if (!is_string($id) || !preg_match(ID_PATTERN, $id)) throw new ApiError('מזהה לא תקין', 422);
    return $id;
}

function filesDir(int $userId): string {
    $dir = STUDIO_DATA . '/files/' . $userId;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new ApiError('לא ניתן ליצור תיקייה', 500);
    return $dir;
}

function filePath(int $userId, string $id, string $mime): string {
    return filesDir($userId) . '/' . $id . ($mime === 'image/png' ? '.png' : ($mime === 'image/webp' ? '.webp' : '.jpg'));
}

// ─── קבצים ─────────────────────────────────────────────────────────────────

/** מקבל תמונה (מ-$_FILES או ממחרוזת בינארית), מפענח מחדש ושומר. אותה תמונה פעמיים — אותו מזהה. */
function storeImage(int $userId, string $binary): array {
    if (strlen($binary) > MAX_UPLOAD) throw new ApiError('הקובץ גדול מ-25MB', 413);
    $sha = hash('sha256', $binary);
    $st = db()->prepare('SELECT id, mime, width, height FROM files WHERE user_id = ? AND sha256 = ?');
    $st->execute([$userId, $sha]);
    if ($row = $st->fetch()) return ['id' => $row['id'], 'mime' => $row['mime'], 'width' => (int)$row['width'], 'height' => (int)$row['height']];

    $info = @getimagesizefromstring($binary);
    $allowed = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp', IMAGETYPE_GIF => 'image/png'];
    if (!$info || !isset($allowed[$info[2]])) throw new ApiError('הקובץ אינו תמונה נתמכת (JPG, PNG, WebP)', 415);
    if ($info[0] * $info[1] > 60_000_000) throw new ApiError('התמונה גדולה מדי', 413);
    $img = @imagecreatefromstring($binary);
    if (!$img) throw new ApiError('התמונה פגומה', 415);

    $w = imagesx($img); $h = imagesy($img);
    $k = min(1, MAX_SIDE / max($w, $h));
    if ($k < 1) {
        $nw = (int)round($w * $k); $nh = (int)round($h * $k);
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false); imagesavealpha($dst, true);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $img = $dst; $w = $nw; $h = $nh;
    }
    $mime = $allowed[$info[2]];
    if ($mime !== 'image/jpeg') { imagealphablending($img, false); imagesavealpha($img, true); }

    $used = (int)db()->query('SELECT COALESCE(SUM(bytes), 0) FROM files WHERE user_id = ' . $userId)->fetchColumn();
    $id = 'f' . bin2hex(random_bytes(10));
    $path = filePath($userId, $id, $mime);
    $ok = match ($mime) {
        'image/png' => imagepng($img, $path, 6),
        'image/webp' => imagewebp($img, $path, 90),
        default => imagejpeg($img, $path, 90),
    };
    imagedestroy($img);
    if (!$ok) throw new ApiError('השמירה נכשלה', 500);
    $bytes = filesize($path);
    if ($used + $bytes > USER_QUOTA) { @unlink($path); throw new ApiError('נגמר מקום האחסון שלך (1GB)', 507); }

    db()->prepare('INSERT INTO files (id, user_id, mime, bytes, width, height, sha256, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, $userId, $mime, $bytes, $w, $h, $sha, nowIso()]);
    return ['id' => $id, 'mime' => $mime, 'width' => $w, 'height' => $h];
}

function sendFile(int $userId, string $id): never {
    $st = db()->prepare('SELECT mime FROM files WHERE id = ? AND user_id = ?');
    $st->execute([checkId($id), $userId]);
    $mime = $st->fetchColumn();
    if (!$mime) throw new ApiError('הקובץ לא נמצא', 404);
    $path = filePath($userId, $id, $mime);
    if (!is_file($path)) throw new ApiError('הקובץ חסר', 404);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    // המזהה אינו משתנה לעולם — אפשר לשמור במטמון לתמיד (פרטי: רק לדפדפן של המשתמש).
    header('Cache-Control: private, max-age=31536000, immutable');
    readfile($path);
    exit;
}

// ─── עיצובים ───────────────────────────────────────────────────────────────

function listDesigns(int $userId, int $since = 0): array {
    $st = db()->prepare('SELECT id, title, width, height, thumb_id, version, updated_at, deleted_at FROM designs WHERE user_id = ? AND updated_at > ? ORDER BY updated_at DESC');
    $st->execute([$userId, $since]);
    return array_map(fn($r) => [
        'id' => $r['id'], 'title' => $r['title'], 'w' => (int)$r['width'], 'h' => (int)$r['height'], 'thumb' => $r['thumb_id'],
        'version' => (int)$r['version'], 'updatedAt' => (int)$r['updated_at'], 'deleted' => $r['deleted_at'] !== null,
    ], $st->fetchAll());
}

function getDesign(int $userId, string $id): array {
    $st = db()->prepare('SELECT * FROM designs WHERE id = ? AND user_id = ? AND deleted_at IS NULL');
    $st->execute([checkId($id), $userId]);
    $r = $st->fetch();
    if (!$r) throw new ApiError('העיצוב לא נמצא', 404);
    return ['id' => $r['id'], 'title' => $r['title'], 'doc' => json_decode($r['doc'], true), 'version' => (int)$r['version'], 'updatedAt' => (int)$r['updated_at'], 'thumb' => $r['thumb_id']];
}

/**
 * שמירה. ‏baseVersion — הגרסה שהלקוח התחיל ממנה. אם בשרת יש גרסה חדשה יותר (נשמר ממכשיר אחר), לא דורסים:
 * מחזירים 409 עם הגרסה שבשרת, והלקוח שומר את שלו כעותק. כך אף פעם לא הולכת עבודה לאיבוד.
 */
function putDesign(int $userId, array $in): array {
    $id = checkId($in['id'] ?? null);
    $doc = $in['doc'] ?? null;
    if (!is_array($doc) || !isset($doc['page'], $doc['layers'])) throw new ApiError('עיצוב לא תקין', 422);
    // בשרת אין מה לעשות עם מזהי IndexedDB של המכשיר.
    foreach (($doc['assets'] ?? []) as $k => $a) {
        if (!is_array($a)) { unset($doc['assets'][$k]); continue; }
        unset($doc['assets'][$k]['src']);
        if (isset($a['remote'])) checkOwnedFile($userId, $a['remote']);
    }
    $json = json_encode($doc, JSON_UNESCAPED_UNICODE);
    if (strlen($json) > MAX_DOC) throw new ApiError('העיצוב גדול מדי', 413);
    $title = mb_substr(trim((string)($in['title'] ?? $doc['title'] ?? 'עיצוב')), 0, 120) ?: 'עיצוב';
    $w = (int)($doc['page']['width'] ?? 0); $h = (int)($doc['page']['height'] ?? 0);
    if ($w < 16 || $h < 16 || $w > 10000 || $h > 10000) throw new ApiError('גודל דף לא תקין', 422);
    $thumb = isset($in['thumb']) && $in['thumb'] !== null ? checkOwnedFile($userId, $in['thumb']) : null;
    $base = (int)($in['baseVersion'] ?? 0);
    $updated = min(nowMs(), max(0, (int)($in['updatedAt'] ?? nowMs())));

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT user_id, version, doc, deleted_at, thumb_id FROM designs WHERE id = ?');
        $st->execute([$id]);
        $cur = $st->fetch();
        if ($cur && (int)$cur['user_id'] !== $userId) throw new ApiError('העיצוב לא נמצא', 404);
        if ($cur && $cur['deleted_at'] === null && $base !== (int)$cur['version'] && empty($in['force'])) {
            $pdo->rollBack();
            throw new ApiError('העיצוב השתנה במכשיר אחר', 409, ['serverVersion' => (int)$cur['version']]);
        }
        if ($cur) {
            // הגרסה הקודמת נשמרת להיסטוריה (עד 30 אחרונות).
            $pdo->prepare('INSERT INTO design_versions (design_id, doc, created_at) VALUES (?, ?, ?)')->execute([$id, $cur['doc'], nowMs()]);
            $pdo->prepare('DELETE FROM design_versions WHERE design_id = ? AND id NOT IN (SELECT id FROM design_versions WHERE design_id = ? ORDER BY id DESC LIMIT 30)')->execute([$id, $id]);
            $version = (int)$cur['version'] + 1;
            $pdo->prepare('UPDATE designs SET title = ?, doc = ?, width = ?, height = ?, thumb_id = COALESCE(?, thumb_id), version = ?, updated_at = ?, deleted_at = NULL WHERE id = ?')
                ->execute([$title, $json, $w, $h, $thumb, $version, $updated, $id]);
            if ($thumb && $cur['thumb_id'] && $cur['thumb_id'] !== $thumb) $oldThumb = $cur['thumb_id'];
        } else {
            $version = 1;
            $pdo->prepare('INSERT INTO designs (id, user_id, title, doc, width, height, thumb_id, version, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)')
                ->execute([$id, $userId, $title, $json, $w, $h, $thumb, $updated]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    // תמונה ממוזערת מתחלפת בכל יציאה מהעורך — הקודמת נמחקת, כדי שלא תתפוס מקום.
    if (!empty($oldThumb)) deleteFile($userId, $oldThumb);
    return ['id' => $id, 'version' => $version, 'updatedAt' => $updated];
}

function deleteDesign(int $userId, string $id): void {
    db()->prepare('UPDATE designs SET deleted_at = ?, updated_at = ? WHERE id = ? AND user_id = ?')
        ->execute([nowMs(), nowMs(), checkId($id), $userId]);
}

function deleteFile(int $userId, string $id): void {
    $st = db()->prepare('SELECT mime FROM files WHERE id = ? AND user_id = ?');
    $st->execute([$id, $userId]);
    $mime = $st->fetchColumn();
    if (!$mime) return;
    db()->prepare('DELETE FROM files WHERE id = ?')->execute([$id]);
    @unlink(filePath($userId, $id, $mime));
}

function checkOwnedFile(int $userId, mixed $id): string {
    $id = checkId($id);
    $st = db()->prepare('SELECT 1 FROM files WHERE id = ? AND user_id = ?');
    $st->execute([$id, $userId]);
    if (!$st->fetchColumn()) throw new ApiError('קובץ לא שייך לך', 403);
    return $id;
}
