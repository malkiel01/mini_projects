<?php
/**
 * העלאות בינאריות — multipart, לא JSON.
 *
 * שלושה מקורות:
 *   kind=recording  הגשר: מקטע וידאו/צילום גמור, עם מטא-דאטה.
 *   kind=live       הגשר: מקטע HLS או פלייליסט לשידור חי.
 *   kind=poster     הדפדפן: פוסטר שיצר לסרטון (אין ffmpeg באחסון משותף).
 *   kind=screenshot הדפדפן: צילום מסך מתוך הנגן — נרשם כצילום עם parent_id.
 *
 * הגשר מזדהה ב-X-Bridge-Token; הדפדפן — בסשן.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/ingest.php';
require_once __DIR__ . '/lib/bridge.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function fail(string $m, int $c = 400): never {
    http_response_code($c);
    echo json_encode(['success' => false, 'error' => $m], JSON_UNESCAPED_UNICODE);
    exit;
}
function ok(array $p = []): never {
    echo json_encode(['success' => true] + $p, JSON_UNESCAPED_UNICODE);
    exit;
}

/** הקובץ שהועלה, אחרי בדיקות בסיס. */
function uploaded(string $field = 'file'): array {
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $code = $f['error'] ?? UPLOAD_ERR_NO_FILE;
        fail($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE
             ? 'הקובץ גדול מהמותר בשרת (upload_max_filesize)' : 'לא התקבל קובץ', 400);
    }
    return $f;
}

/** finfo אומר מה הקובץ באמת — לא הסיומת שהצד השני בחר. */
function realExt(string $path, array $allowed): string {
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
    $map = ['video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/x-matroska' => 'mkv', 'video/mp2t' => 'ts',
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
            'application/octet-stream' => 'bin'];
    $ext = $map[$mime] ?? '';
    // MP4 מקוטע (fMP4) ומקטעי TS לפעמים מזוהים כ-octet-stream; מקבלים לפי הסיומת המוצהרת.
    if ($ext === 'bin' || $ext === '') {
        $declared = strtolower(pathinfo($_FILES['file']['name'] ?? '', PATHINFO_EXTENSION));
        if (in_array($declared, ['mp4', 'ts', 'm4s'], true)) $ext = $declared;
    }
    if (!in_array($ext, $allowed, true)) fail('סוג קובץ לא נתמך (' . $mime . ')', 415);
    return $ext;
}

try {
    $kind = (string) ($_POST['kind'] ?? $_GET['kind'] ?? '');

    switch ($kind) {

    case 'recording': {
        $bridge = requireBridge();
        $camera = cameraByKey((string) ($_POST['camera'] ?? ''));
        if (!$camera || (int) $camera['bridge_id'] !== (int) $bridge['id']) fail('המצלמה אינה של הגשר הזה', 403);
        $f = uploaded();
        $ext = realExt($f['tmp_name'], ['mp4', 'mov', 'mkv', 'jpg', 'png', 'webp']);
        $rkind = in_array($ext, ['jpg', 'png', 'webp'], true) ? 'snapshot' : 'video';
        // הקובץ הזמני נעלם בסוף הבקשה; מעבירים למקום שלנו ומקלטים משם.
        $tmp = INBOX_DIR . '/.upload_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $tmp) && !rename($f['tmp_name'], $tmp)) fail('לא ניתן לשמור', 500);
        $meta = [
            'started_at' => isset($_POST['started_at']) && strtotime((string) $_POST['started_at']) ? isoFromTs(strtotime((string) $_POST['started_at'])) : null,
            'trigger'    => (string) ($_POST['trigger'] ?? ''),
            'duration_s' => isset($_POST['duration_s']) ? (float) $_POST['duration_s'] : null,
            'width'      => isset($_POST['width']) ? (int) $_POST['width'] : null,
            'height'     => isset($_POST['height']) ? (int) $_POST['height'] : null,
        ];
        $meta = array_filter($meta, fn($v) => $v !== null);
        $id = ingestFile($tmp, $camera, $rkind, $ext, 'bridge', $meta);
        // פוסטר שהגשר צירף (ffmpeg אצלו כן קיים).
        if (!empty($_FILES['poster']) && ($_FILES['poster']['error'] ?? 1) === UPLOAD_ERR_OK && $rkind === 'video') {
            $data = @file_get_contents($_FILES['poster']['tmp_name']);
            if ($data) { try { saveVideoPoster($id, $data); } catch (Throwable) {} }
        }
        ok(['id' => $id]);
    }

    case 'live': {
        $bridge = requireBridge();
        $camera = cameraByKey((string) ($_POST['camera'] ?? ''));
        if (!$camera) fail('אין מצלמה כזו', 404);
        // כמה קבצים בבקשה אחת: file[] + name[]
        $names = (array) ($_POST['name'] ?? []);
        $files = $_FILES['file'] ?? null;
        if (!$files) fail('לא התקבל קובץ');
        $multi = is_array($files['name']);
        $n = $multi ? count($files['name']) : 1;
        for ($i = 0; $i < $n; $i++) {
            $err = $multi ? $files['error'][$i] : $files['error'];
            if ($err !== UPLOAD_ERR_OK) continue;
            $tmp  = $multi ? $files['tmp_name'][$i] : $files['tmp_name'];
            $name = (string) ($names[$i] ?? ($multi ? $files['name'][$i] : $files['name']));
            storeLiveFile($bridge, $camera, basename($name), $tmp);
        }
        ok(['live_wanted' => $camera['live_wanted_until'] !== null && $camera['live_wanted_until'] > nowIso()]);
    }

    case 'poster': {
        $user = currentUser();
        if (!$user) fail('נדרשת התחברות', 401);
        $f = uploaded();
        realExt($f['tmp_name'], ['jpg', 'png', 'webp']);
        $thumb = saveVideoPoster((int) ($_POST['recording_id'] ?? 0), (string) file_get_contents($f['tmp_name']));
        ok(['thumb_path' => $thumb]);
    }

    case 'screenshot': {
        $user = currentUser();
        if (!$user) fail('נדרשת התחברות', 401);
        if (!in_array($user['role'], ['admin', 'operator'], true)) fail('אין לך הרשאה לפעולה הזו', 403);
        $parent = recordingById((int) ($_POST['recording_id'] ?? 0));
        $camera = cameraById($parent['camera_id']);
        $f = uploaded();
        $ext = realExt($f['tmp_name'], ['jpg', 'png', 'webp']);
        $tmp = INBOX_DIR . '/.shot_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $tmp) && !rename($f['tmp_name'], $tmp)) fail('לא ניתן לשמור', 500);
        $at = (float) ($_POST['at'] ?? 0);
        $startedTs = strtotime($parent['started_at']) ?: time();
        $id = ingestFile($tmp, $camera, 'snapshot', $ext, 'player', [
            'started_at' => isoFromTs($startedTs + (int) floor($at)),
            'trigger'    => 'manual',
            'parent_id'  => $parent['id'],
            'created_by' => (int) $user['id'],
            'title'      => (string) ($_POST['title'] ?? ''),
        ]);
        ok(['id' => $id, 'recording' => recordingById($id)]);
    }

    default:
        fail('kind לא מוכר', 404);
    }
} catch (AppError $e) {
    fail($e->getMessage(), $e->status);
} catch (Throwable $e) {
    error_log('camera-app upload: ' . $e->getMessage());
    fail('שגיאת שרת', 500);
}
