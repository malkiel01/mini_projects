<?php
/**
 * מדיה — תמונות וסרטונים של מתכון.
 *
 * זה המקום המסוכן בפרויקט. ההרשמה חופשית, ולכן מי שמעלה קובץ לכאן הוא
 * אדם שאיני מכיר, והקובץ מוגש אחר כך לדפדפנים של אחרים מהשרת שלנו.
 * מכאן ארבע ההחלטות שכל הקוד נשען עליהן (סעיף 10 באפיון):
 *
 *   1. הסוג נקבע ב-finfo מתוך הבייטים, לא מהסיומת ולא ממה שהדפדפן טען.
 *   2. שם הקובץ בדיסק מחולל כאן. השם שנשלח לא נוגע בדיסק בכלל.
 *   3. המקצב (200MB לחשבון) נבדק בצד השרת — הדפדפן רק מציג אותו.
 *   4. התקרה לקובץ היא המינימום בין האפיון לבין מה ש-PHP מרשה. לא מבטיחים
 *      20MB כשהשרת חוסם ב-8.
 *
 * קישור (יוטיוב וכדומה) יושב באותה טבלה עם source='link': אותה מדיה
 * מבחינת התצוגה, בלי בייטים ובלי מקצב.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/diag.php';
require_once __DIR__ . '/settings.php';

const MAX_IMAGES_PER_RECIPE = 10;
const MAX_VIDEOS_PER_RECIPE = 3;

/** סוגים מותרים, וסיומת קבועה לכל אחד. הסיומת נגזרת מהסוג — לא להפך. */
const MEDIA_TYPES = [
    'image/jpeg' => ['kind' => 'image', 'ext' => 'jpg'],
    'image/png'  => ['kind' => 'image', 'ext' => 'png'],
    'image/webp' => ['kind' => 'image', 'ext' => 'webp'],
    'image/gif'  => ['kind' => 'image', 'ext' => 'gif'],
    'video/mp4'  => ['kind' => 'video', 'ext' => 'mp4'],
];

/** התקרה בפועל לקובץ, לפי הסוג ולפי המשתמש (דריסה → ציבורי → ברירת מחדל). */
function mediaMaxBytes(string $kind, array $user): int {
    $spec   = $kind === 'video' ? effectiveLimit($user, 'video_max_bytes')
                                : effectiveLimit($user, 'image_max_bytes');
    $upload = iniBytes((string) ini_get('upload_max_filesize')) ?: PHP_INT_MAX;
    $post   = iniBytes((string) ini_get('post_max_size')) ?: PHP_INT_MAX;
    return min($spec, $upload, $post);
}

/** כמה כבר יש למתכון מסוג מסוים. */
function mediaCount(int $recipeId, string $kind): int {
    $st = db()->prepare('SELECT COUNT(*) c FROM media WHERE recipe_id = ? AND kind = ?');
    $st->execute([$recipeId, $kind]);
    return (int) $st->fetch()['c'];
}

/** מוודא שהמתכון קיים ושייך למשתמש. אף אחד מלבד הבעלים אינו מעלה אליו. */
function requireOwnedRecipe(int $recipeId, array $user): array {
    $st = db()->prepare('SELECT id, owner_id, main_media_id FROM recipes WHERE id = ?');
    $st->execute([$recipeId]);
    $r = $st->fetch();
    if (!$r) throw new AppError('המתכון אינו קיים', 404);
    if ((int) $r['owner_id'] !== (int) $user['id']) throw new AppError('המתכון אינו שלך', 403);
    return $r;
}

/**
 * מקבל קובץ שהועלה ($_FILES['file']) ומצרף אותו למתכון.
 * מחזיר את שורת המדיה החדשה.
 */
function storeUpload(int $recipeId, array $user, array $file): array {
    $recipe = requireOwnedRecipe($recipeId, $user);

    // שגיאות של PHP עצמו קודמות לכל בדיקה שלנו — UPLOAD_ERR_INI_SIZE אומר
    // שהקובץ נחתך לפני שהגיע לכאן, וזו הודעה אחרת מ"גדול מדי".
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        throw new AppError('הקובץ גדול ממה שהשרת מרשה (' . humanBytes(
            iniBytes((string) ini_get('upload_max_filesize'))) . ')', 413);
    }
    if ($err !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new AppError('הקובץ לא התקבל', 400);
    }

    // 1. הסוג מהבייטים. שם הקובץ והסוג שהדפדפן שלח אינם נכנסים לחשבון.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string) $finfo->file($file['tmp_name']);
    if (!isset(MEDIA_TYPES[$mime])) {
        throw new AppError('סוג קובץ לא נתמך. מותר: JPEG, PNG, WebP, GIF, MP4', 415);
    }
    $kind = MEDIA_TYPES[$mime]['kind'];
    $ext  = MEDIA_TYPES[$mime]['ext'];

    // 2. גודל — מול התקרה בפועל, לא מול המספר שבאפיון.
    $bytes = (int) filesize($file['tmp_name']);
    $max   = mediaMaxBytes($kind, $user);
    if ($bytes > $max) {
        $hint = $kind === 'video'
            ? ' סרטון ארוך יותר אפשר להעלות ליוטיוב ולהדביק כאן קישור.'
            : '';
        throw new AppError('הקובץ גדול מדי — התקרה היא ' . humanBytes($max) . '.' . $hint, 413);
    }

    // 3. כמות למתכון.
    $limit = $kind === 'video' ? MAX_VIDEOS_PER_RECIPE : MAX_IMAGES_PER_RECIPE;
    if (mediaCount($recipeId, $kind) >= $limit) {
        throw new AppError(($kind === 'video' ? 'עד ' . $limit . ' סרטונים' : 'עד ' . $limit . ' תמונות')
                           . ' למתכון', 409);
    }

    // 4. מקצב — מחושב מהמסד, לא מהשדה שבשורת המשתמש. השדה יכול להזדחל.
    $used  = storageUsedReal((int) $user['id']);
    $quota = effectiveLimit($user, 'quota_bytes');
    if ($used + $bytes > $quota) {
        throw new AppError('נגמר מקום האחסון שלך (' . humanBytes($quota) . ' לחשבון). '
                           . 'מחיקת מתכון או מדיה משחררת מקום.', 507);
    }

    // 5. שם בדיסק — מחולל. הסיומת נגזרת מהסוג שזוהה, לא מהשם שנשלח.
    if (!is_dir(MEDIA_DIR) && !@mkdir(MEDIA_DIR, 0775, true) && !is_dir(MEDIA_DIR)) {
        throw new AppError('תיקיית המדיה אינה זמינה', 500);
    }
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = MEDIA_DIR . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new AppError('שמירת הקובץ נכשלה', 500);
    }
    @chmod($dest, 0644);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT COALESCE(MAX(position),0)+1 p FROM media WHERE recipe_id = ?');
        $st->execute([$recipeId]);
        $pos = (int) $st->fetch()['p'];

        $st = $pdo->prepare('INSERT INTO media (recipe_id, uploader_id, kind, source, path_or_url,
                                                mime, bytes, position, created_at)
                             VALUES (?,?,?,?,?,?,?,?,?)');
        $st->execute([$recipeId, $user['id'], $kind, 'upload', $name, $mime, $bytes, $pos, nowIso()]);
        $id = (int) $pdo->lastInsertId();

        $st = $pdo->prepare('UPDATE users SET storage_used = ? WHERE id = ?');
        $st->execute([$used + $bytes, $user['id']]);

        // התמונה הראשונה היא הראשית כברירת מחדל (3.4).
        if ($kind === 'image' && $recipe['main_media_id'] === null) {
            $st = $pdo->prepare('UPDATE recipes SET main_media_id = ? WHERE id = ?');
            $st->execute([$id, $recipeId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        @unlink($dest);   // הקובץ נשמר לפני הטרנזקציה — לא להשאיר אותו יתום
        throw $e;
    }
    return mediaRow($id);
}

/**
 * מצרף קישור לסרטון. http/https בלבד — הערך נכתב לתוך src/href, ו-javascript:
 * שם הוא הרצת קוד אצל מי שצופה.
 */
function storeLink(int $recipeId, array $user, string $url): array {
    requireOwnedRecipe($recipeId, $user);
    $url = trim($url);
    if (!preg_match('~^https?://[^\s<>"\']{8,500}$~', $url)) {
        throw new AppError('הקישור אינו כתובת http/https תקינה');
    }
    if (mediaCount($recipeId, 'video') >= MAX_VIDEOS_PER_RECIPE) {
        throw new AppError('עד ' . MAX_VIDEOS_PER_RECIPE . ' סרטונים למתכון', 409);
    }
    $st = db()->prepare('SELECT COALESCE(MAX(position),0)+1 p FROM media WHERE recipe_id = ?');
    $st->execute([$recipeId]);
    $pos = (int) $st->fetch()['p'];

    $st = db()->prepare('INSERT INTO media (recipe_id, uploader_id, kind, source, path_or_url,
                                            mime, bytes, position, created_at)
                         VALUES (?,?,?,?,?,?,?,?,?)');
    $st->execute([$recipeId, $user['id'], 'video', 'link', $url, null, 0, $pos, nowIso()]);
    return mediaRow((int) db()->lastInsertId());
}

/** מוחק מדיה — קובץ, שורה, ומשחרר מקום. הבעלים או המנהל. */
function deleteMedia(int $mediaId, array $user): void {
    $st = db()->prepare('SELECT m.*, r.owner_id, r.main_media_id FROM media m
                           JOIN recipes r ON r.id = m.recipe_id WHERE m.id = ?');
    $st->execute([$mediaId]);
    $m = $st->fetch();
    if (!$m) throw new AppError('המדיה אינה קיימת', 404);
    if ((int) $m['owner_id'] !== (int) $user['id'] && $user['role'] !== 'admin') {
        throw new AppError('אין לך הרשאה למחוק', 403);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // ה-FK המחזורי אל media נאכף כאן ולא במסד (ראה SCHEMA.sql).
        if ((int) $m['main_media_id'] === $mediaId) {
            $st = $pdo->prepare('UPDATE recipes SET main_media_id = (
                    SELECT id FROM media WHERE recipe_id = ? AND kind = \'image\' AND id <> ?
                    ORDER BY position LIMIT 1) WHERE id = ?');
            $st->execute([$m['recipe_id'], $mediaId, $m['recipe_id']]);
        }
        $st = $pdo->prepare('UPDATE steps SET media_id = NULL WHERE media_id = ?');
        $st->execute([$mediaId]);
        $st = $pdo->prepare('DELETE FROM media WHERE id = ?');
        $st->execute([$mediaId]);
        if ($m['source'] === 'upload') {
            $st = $pdo->prepare('UPDATE users SET storage_used = MAX(0, storage_used - ?) WHERE id = ?');
            $st->execute([(int) $m['bytes'], $m['uploader_id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    // הקובץ נמחק אחרי ה-commit: שורה בלי קובץ היא באג שרואים, קובץ בלי
    // שורה הוא נפח שנעלם בשקט. עדיף הראשון.
    if ($m['source'] === 'upload') @unlink(MEDIA_DIR . '/' . basename($m['path_or_url']));
}

function setMainMedia(int $recipeId, int $mediaId, array $user): void {
    requireOwnedRecipe($recipeId, $user);
    $st = db()->prepare('SELECT id FROM media WHERE id = ? AND recipe_id = ? AND kind = \'image\'');
    $st->execute([$mediaId, $recipeId]);
    if (!$st->fetch()) throw new AppError('התמונה אינה שייכת למתכון הזה', 400);
    $st = db()->prepare('UPDATE recipes SET main_media_id = ? WHERE id = ?');
    $st->execute([$mediaId, $recipeId]);
}

function mediaRow(int $id): array {
    $st = db()->prepare('SELECT * FROM media WHERE id = ?');
    $st->execute([$id]);
    return mediaForClient($st->fetch());
}

/** צורת המדיה כפי שהדפדפן מקבל אותה. הנתיב לקובץ שהועלה הוא יחסי לכלי. */
function mediaForClient(array $m): array {
    return [
        'id'     => (int) $m['id'],
        'kind'   => $m['kind'],
        'source' => $m['source'],
        'url'    => $m['source'] === 'upload'
                    ? 'data/media/' . basename($m['path_or_url'])
                    : $m['path_or_url'],
        'bytes'  => (int) $m['bytes'],
    ];
}

function mediaForRecipe(int $recipeId): array {
    $st = db()->prepare('SELECT * FROM media WHERE recipe_id = ? ORDER BY position');
    $st->execute([$recipeId]);
    return array_map('mediaForClient', $st->fetchAll());
}

/** מה שהעורך צריך להציג לפני העלאה: תקרות בפועל ומקצב. */
function mediaLimits(array $user): array {
    $used  = storageUsedReal((int) $user['id']);
    $quota = effectiveLimit($user, 'quota_bytes');
    return [
        'image_max'   => mediaMaxBytes('image', $user),
        'video_max'   => mediaMaxBytes('video', $user),
        'quota'       => $quota,
        'used'        => $used,
        'free'        => max(0, $quota - $used),
        'max_images'  => MAX_IMAGES_PER_RECIPE,
        'max_videos'  => MAX_VIDEOS_PER_RECIPE,
    ];
}
