<?php
/**
 * צירוף קבצים ומכסות — שלב 6.
 *
 * קובץ שמצורף לשדה נשמר מוצפן (store_file), עם שם מקורי מוצפן. ערך השדה הוא
 * רשימת מזהי הקבצים (JSON). הסרה מהשדה לא מוחקת את הקובץ — הוא חלק מתיעוד
 * המסמך, כמו היומן — אבל הוא כבר לא חלק מהמסמך.
 *
 * מי רואה קובץ: מנהל הטופס — כל קובץ במסמך שלו. ממלא חיצוני — רק קבצים
 * שהוא עצמו העלה. (ת"ז של הערב היא לא עניינו של השוכר.)
 *
 * מכסות: נקבעות ע"י המפתח לכל חשבון; ברירת מחדל לחשבון בלי הגדרה. הנפח
 * נמדד בבתים המוצפנים בפועל — קבצי תבניות + קבצים מצורפים של מסמכי החשבון.
 * קובץ שממלא חיצוני מעלה נספר אצל בעל המסמך.
 */

declare(strict_types=1);

require_once __DIR__ . '/participants.php';

const QUOTA_DEFAULTS = ['storage_mb' => 200, 'max_file_mb' => 10, 'file_retention_days' => null,
    'ai_enabled' => false, 'ai_credits' => 0, 'sms_credits' => 0];

// סוג קובץ ← סוגי MIME שמותרים בו. נבדק מהתוכן (finfo), לא מהשם.
const FILE_MIME = [
    'pdf' => ['application/pdf'],
    'image' => ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'],
    'office' => [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/msword', 'application/vnd.ms-excel',
    ],
];

// ── מכסות ────────────────────────────────────────────────────

function quota_of(int $userId): array
{
    $st = db()->prepare('SELECT * FROM quotas WHERE user_id = ?');
    $st->execute([$userId]);
    $q = $st->fetch() ?: [];
    $out = QUOTA_DEFAULTS;
    foreach ($out as $k => $v) if (isset($q[$k]) && $q[$k] !== null) $out[$k] = is_bool($v) ? (bool)$q[$k] : (int)$q[$k];
    return $out;
}

/** נפח בשימוש, בבתים. קובץ רקע שמשותף לכמה גרסאות נספר פעם אחת. */
function usage_bytes(int $userId): int
{
    $st = db()->prepare(
        'SELECT COALESCE(SUM(size), 0) FROM (
           SELECT DISTINCT a.path, a.size FROM template_assets a
           JOIN template_versions v ON v.id = a.template_version_id
           JOIN templates t ON t.id = v.template_id WHERE t.owner_id = ?
         )'
    );
    $st->execute([$userId]);
    $tpl = (int)$st->fetchColumn();
    $st = db()->prepare('SELECT COALESCE(SUM(a.size), 0) FROM attachments a JOIN documents d ON d.id = a.document_id WHERE d.owner_id = ?');
    $st->execute([$userId]);
    return $tpl + (int)$st->fetchColumn();
}

function usage_view(int $userId): array
{
    $q = quota_of($userId);
    return ['used_mb' => round(usage_bytes($userId) / 1048576, 2), 'storage_mb' => $q['storage_mb'], 'max_file_mb' => $q['max_file_mb']];
}

/** נזרק לפני שמירה, אם הקובץ החדש יחרוג מהנפח. */
function assert_room(int $ownerId, int $bytes): void
{
    $q = quota_of($ownerId);
    if (usage_bytes($ownerId) + $bytes > $q['storage_mb'] * 1048576) {
        throw new ApiError('נגמר מקום האחסון בחשבון (' . $q['storage_mb'] . 'MB). פנו למפתח להגדלה', 507);
    }
}

function dev_quota(array $b): array
{
    $st = db()->prepare("SELECT id FROM users WHERE id = ? AND status != 'deleted'");
    $st->execute([(int)($b['user_id'] ?? 0)]);
    $uid = (int)$st->fetchColumn();
    if (!$uid) throw new ApiError('המשתמש לא נמצא', 404);
    $int = fn($k, $min, $max) => isset($b[$k]) && $b[$k] !== '' ? max($min, min($max, (int)$b[$k])) : null;
    db()->prepare(
        'INSERT INTO quotas (user_id, storage_mb, max_file_mb, file_retention_days, ai_enabled, ai_credits, sms_credits)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT(user_id) DO UPDATE SET storage_mb = excluded.storage_mb, max_file_mb = excluded.max_file_mb,
           file_retention_days = excluded.file_retention_days, ai_enabled = excluded.ai_enabled,
           ai_credits = excluded.ai_credits, sms_credits = excluded.sms_credits'
    )->execute([$uid, $int('storage_mb', 1, 100000), $int('max_file_mb', 1, 50), $int('file_retention_days', 1, 36500),
        !empty($b['ai_enabled']) ? 1 : 0, $int('ai_credits', 0, 10000000) ?? 0, $int('sms_credits', 0, 10000000) ?? 0]);
    return ['ok' => true, 'quota' => quota_of($uid) + ['used_mb' => round(usage_bytes($uid) / 1048576, 2)]];
}

// ── העלאה ────────────────────────────────────────────────────

/**
 * שומר קובץ לשדה. $actor — 'user:<id>' או 'participant:<id>'. מחזיר את
 * רשומת הקובץ ואת ערך השדה החדש (כבר שמור, וכבר ביומן).
 */
function attach_file(array $d, array $schema, string $fieldKey, array $file, string $actor): array
{
    $f = null;
    foreach ($schema['fields'] ?? [] as $x) if ($x['key'] === $fieldKey && $x['type'] === 'file') $f = $x;
    if (!$f) throw new ApiError('שדה צירוף לא נמצא', 404);

    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) throw new ApiError('לא נבחר קובץ', 422);
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        throw new ApiError('הקובץ גדול מהמותר בשרת (' . ini_get('upload_max_filesize') . ')', 413);
    }
    if ($file['error'] !== UPLOAD_ERR_OK || (!is_uploaded_file($file['tmp_name']) && PHP_SAPI !== 'cli')) {
        throw new ApiError('ההעלאה נכשלה', 400);
    }
    $q = quota_of((int)$d['owner_id']);
    $maxMb = min((int)($f['maxMb'] ?? 10), $q['max_file_mb']);
    if ($file['size'] > $maxMb * 1048576) throw new ApiError("הקובץ גדול מ-{$maxMb}MB", 413);

    $bytes = (string)file_get_contents($file['tmp_name']);
    $mime = office_mime($bytes, (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes));
    $accept = $f['accept'] ?? ['pdf', 'image'];
    $allowed = array_merge(...array_map(fn($k) => FILE_MIME[$k] ?? [], $accept));
    if (!in_array($mime, $allowed, true)) {
        $names = ['pdf' => 'PDF', 'image' => 'תמונה', 'office' => 'Word/Excel'];
        throw new ApiError('סוג קובץ לא מתאים לשדה. מותר: ' . implode(', ', array_map(fn($k) => $names[$k] ?? $k, $accept)), 415);
    }
    // תקרה לשדה — גם קבצים שהוחלפו נמחקים (purge_attachments), אבל לא נותנים לשדה אחד לגדול בלי סוף
    $st = db()->prepare('SELECT COUNT(*) FROM attachments WHERE document_id = ? AND field_key = ?');
    $st->execute([$d['id'], $fieldKey]);
    if ((int)$st->fetchColumn() >= FILES_PER_FIELD) throw new ApiError('יותר מדי קבצים בשדה הזה (עד ' . FILES_PER_FIELD . ')', 429);
    assert_room((int)$d['owner_id'], strlen($bytes));

    $name = mb_substr(preg_replace('/[\x00-\x1f\/\\\\]+/u', '_', basename((string)($file['name'] ?? 'קובץ'))), 0, 150) ?: 'קובץ';
    $path = store_file($bytes);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        doc_lock($pdo, (int)$d['id'], ['draft', 'sent', 'in_progress']);   // לא מצרפים למסמך שננעל בינתיים (DT-F07)
        $now = now_iso();
        $pdo->prepare(
            'INSERT INTO attachments (document_id, field_key, path, name_enc, mime, size, sha256, uploaded_by, at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$d['id'], $fieldKey, $path, seal($name), $mime, strlen($bytes), hash('sha256', $bytes), $actor, $now]);
        $attId = (int)$pdo->lastInsertId();

        $st = $pdo->prepare('SELECT value_enc FROM field_values WHERE document_id = ? AND field_key = ? AND row_index = 0');
        $st->execute([$d['id'], $fieldKey]);
        $ids = json_decode((string)unseal($st->fetchColumn() ?: null), true) ?: [];
        $ids = $f['multiple'] ? array_merge($ids, [$attId]) : [$attId];
        $pdo->prepare(
            'INSERT INTO field_values (document_id, field_key, row_index, value_enc, updated_by, updated_at) VALUES (?, ?, 0, ?, ?, ?)
             ON CONFLICT(document_id, field_key, row_index) DO UPDATE SET
               value_enc = excluded.value_enc, updated_by = excluded.updated_by, updated_at = excluded.updated_at'
        )->execute([$d['id'], $fieldKey, seal(json_encode($ids)), $actor, $now]);
        $pdo->prepare("UPDATE documents SET status = 'in_progress' WHERE id = ? AND status IN ('draft', 'sent')")->execute([$d['id']]);
        log_event((int)$d['id'], $actor, 'file_uploaded',
            ['label' => $f['label'], 'name' => $name, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        delete_file($path);
        throw $e;
    }
    purge_attachments((int)$d['id']);   // קובץ שהוחלף בשדה של קובץ אחד — נמחק, ולא נספר יותר במכסה
    return ['ok' => true, 'attachment' => ['id' => $attId, 'field_key' => $fieldKey, 'name' => $name, 'mime' => $mime,
        'size' => strlen($bytes), 'mine' => true], 'value' => json_encode($ids)];
}

const FILES_PER_FIELD = 30;

/**
 * Word / Excel / PowerPoint שמזוהים כ-zip (או octet-stream) — לפי מה שבתוכם. אחרת — הסוג כמו שזוהה.
 */
function office_mime(string $bytes, string $mime): string
{
    if (!in_array($mime, ['application/zip', 'application/octet-stream', 'application/x-zip-compressed'], true) || !str_starts_with($bytes, "PK")) return $mime;
    $tmp = tempnam(sys_get_temp_dir(), 'dtz');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive();
    $out = $mime;
    if (class_exists('ZipArchive') && $zip->open($tmp) === true) {
        if ($zip->locateName('word/document.xml') !== false) $out = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        elseif ($zip->locateName('xl/workbook.xml') !== false) $out = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        elseif ($zip->locateName('ppt/presentation.xml') !== false) $out = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
        $zip->close();
    }
    @unlink($tmp);
    return $out;
}

/**
 * קבצים של ממלא חיצוני שכבר לא בשום שדה (הוחלפו או הוסרו) — נמחקים מהדיסק ומהמכסה, כל עוד המסמך
 * לא הושלם. אחרת לקוח יכול למלא את המכסה של בעל המסמך בהחלפות חוזרות. קבצים שבעלי חשבון העלו —
 * נשארים לתיעוד, כמו קודם. במסמך שהושלם לא נוגעים: הקבצים הם חלק ממה שנחתם.
 */
function purge_attachments(int $docId): void
{
    $st = db()->prepare('SELECT status FROM documents WHERE id = ?');
    $st->execute([$docId]);
    if (!in_array($st->fetchColumn(), DOC_EDITABLE, true)) return;
    $used = [];
    $st = db()->prepare('SELECT value_enc FROM field_values WHERE document_id = ?');
    $st->execute([$docId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $enc) {
        $v = json_decode((string)unseal($enc), true);
        if (is_array($v)) foreach ($v as $id) if (is_int($id)) $used[$id] = true;
    }
    $st = db()->prepare("SELECT id, path FROM attachments WHERE document_id = ? AND uploaded_by LIKE 'participant:%'");
    $st->execute([$docId]);
    foreach ($st->fetchAll() as $a) {
        if (isset($used[(int)$a['id']])) continue;
        db()->prepare('DELETE FROM attachments WHERE id = ?')->execute([$a['id']]);
        delete_file($a['path']);
    }
}

function attachments_of(int $docId, ?string $onlyBy = null): array
{
    $st = db()->prepare('SELECT * FROM attachments WHERE document_id = ? ORDER BY id');
    $st->execute([$docId]);
    $out = [];
    foreach ($st->fetchAll() as $a) {
        if ($onlyBy !== null && $a['uploaded_by'] !== $onlyBy) continue;
        $out[] = ['id' => (int)$a['id'], 'field_key' => $a['field_key'], 'name' => unseal($a['name_enc']), 'mime' => $a['mime'],
            'size' => (int)$a['size'], 'at' => $a['at'], 'uploaded_by' => $a['uploaded_by']];
    }
    return $out;
}

function send_attachment(array $a): never
{
    $bytes = load_file($a['path']);
    $name = (string)unseal($a['name_enc']);
    header('Content-Type: ' . $a['mime']);
    header('Content-Length: ' . strlen($bytes));
    header("Content-Disposition: attachment; filename=\"file\"; filename*=UTF-8''" . rawurlencode($name));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    exit;
}

// ── מנהל הטופס ───────────────────────────────────────────────

function doc_upload(array $user, array $post, array $files): array
{
    $d = doc_owned((int)($post['id'] ?? 0), $user, 'fill');
    if (!in_array($d['status'], DOC_EDITABLE, true)) throw new ApiError('המסמך נעול', 409);
    $schema = doc_schema($d);
    $key = (string)($post['field_key'] ?? '');
    foreach ($schema['fields'] as $f) {
        if ($f['key'] !== $key) continue;
        if (in_array('owner', field_roles($f), true)) continue;   // משותף עם המנפיק — המנפיק יכול תמיד
        $st = db()->prepare('SELECT role_key FROM participants WHERE document_id = ? AND notified_at IS NOT NULL');
        $st->execute([$d['id']]);
        if (array_intersect(field_roles($f), $st->fetchAll(PDO::FETCH_COLUMN))) throw new ApiError('השדה כבר נשלח למילוי של המשתתף', 409);
    }
    return attach_file($d, $schema, $key, $files['file'] ?? [], 'user:' . $user['id']);
}

function doc_file(array $user, int $docId, int $attId): never
{
    $d = doc_owned($docId, $user, 'view');
    $st = db()->prepare('SELECT * FROM attachments WHERE id = ? AND document_id = ?');
    $st->execute([$attId, $d['id']]);
    $a = $st->fetch();
    if (!$a) throw new ApiError('הקובץ לא נמצא', 404);
    send_attachment($a);
}

// ── ממלא חיצוני ──────────────────────────────────────────────

function fill_upload(array $post, array $files): array
{
    [$p, $d, $schema] = fill_guard();
    $key = (string)($post['field_key'] ?? '');
    foreach ($schema['fields'] as $f) {
        if ($f['key'] === $key && !in_array($p['role_key'], field_roles($f), true)) throw new ApiError('השדה לא שלך', 403);
    }
    return attach_file($d, $schema, $key, $files['file'] ?? [], 'participant:' . $p['id']);
}

function fill_file(int $attId): never
{
    [$p, $d] = fill_context();
    if (needs_otp($p)) throw new ApiError('נדרש אימות', 401);
    $st = db()->prepare('SELECT * FROM attachments WHERE id = ? AND document_id = ? AND uploaded_by = ?');
    $st->execute([$attId, $d['id'], 'participant:' . $p['id']]);
    $a = $st->fetch();
    if (!$a) throw new ApiError('הקובץ לא נמצא', 404);
    send_attachment($a);
}
