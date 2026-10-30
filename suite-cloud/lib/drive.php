<?php
/**
 * הכונן הפנימי — העץ במסד (nodes), התוכן בדיסק (data/files/ab/cd/<sha256>).
 *
 * - מזהה node יציב גם אחרי שינוי שם והעברה — האפליקציות שומרות אותו.
 * - אותו תוכן פעמיים — קובץ אחד בדיסק, אבל המכסה סופרת כל קובץ (גם בסל) בנפרד: מה שהמשתמש רואה = מה שנספר.
 * - סל: trashed_at על ה-node שהועבר לסל בלבד; מה שבתוכו מוסתר יחד איתו. אחרי 30 יום — מחיקה לצמיתות.
 * - rev עולה בכל כתיבה; if_rev = "שמור רק אם לא השתנה מאז שקראתי" — בלי דריסה שקטה בין אפליקציות.
 * האפיון: suite-cloud/docs/spec-1-skeleton.md, סעיפים 5–6.
 */

declare(strict_types=1);

require_once __DIR__ . '/account.php';

const CHUNK = 8 * 1024 * 1024;          // העלאה בחלקים — עוקף את upload_max_filesize של השרת
const WRITE_MAX = 4 * 1024 * 1024;      // write בקריאה אחת (base64 בגוף JSON)
const TRASH_DAYS = 30;
const UPLOAD_TTL = 86400;               // העלאה שלא הושלמה — נמחקת אחרי 24 שעות

// ─── שמות ─────────────────────────────────────────────────────────────────

function cleanName(mixed $name): string {
    if (!is_string($name)) throw new AppError('חסר שם', 400, 'bad_name');
    $name = trim($name);
    if (class_exists('Normalizer')) $name = (string) Normalizer::normalize($name, Normalizer::FORM_C);
    if ($name === '' || $name === '.' || $name === '..') throw new AppError('שם לא תקין', 400, 'bad_name');
    if (mb_strlen($name) > 200) throw new AppError('השם ארוך מ-200 תווים', 400, 'bad_name');
    if (preg_match('/[\/\\\\:*?"<>|\x00-\x1F\x7F]/u', $name) || !mb_check_encoding($name, 'UTF-8')) {
        throw new AppError('בשם יש תו אסור (/ \\ : * ? " < > |)', 400, 'bad_name');
    }
    return $name;
}

/** "ספר.mbe" → "ספר (2).mbe", "(3)"… עד שם פנוי. */
function freeName(string $account, ?string $parent, string $name, ?string $except = null): string {
    if (!nameTaken($account, $parent, $name, $except)) return $name;
    $dot = mb_strrpos($name, '.');
    [$base, $ext] = $dot > 0 ? [mb_substr($name, 0, $dot), mb_substr($name, $dot)] : [$name, ''];
    for ($i = 2; ; $i++) {
        $try = "$base ($i)$ext";
        if (!nameTaken($account, $parent, $try, $except)) return $try;
    }
}

/** שם תפוס באותה תיקייה, בין מה שלא בסל. בלי תלות באותיות גדולות/קטנות — כמו ברוב מערכות הקבצים. */
function siblingByName(string $account, ?string $parent, string $name, ?string $except = null): ?array {
    $st = db()->prepare('SELECT * FROM nodes WHERE account = ? AND parent IS ? AND trashed_at IS NULL AND lower(name) = lower(?) AND id IS NOT ?');
    $st->execute([$account, $parent, $name, $except]);
    return $st->fetch() ?: null;
}

function nameTaken(string $account, ?string $parent, string $name, ?string $except = null): bool {
    return siblingByName($account, $parent, $name, $except) !== null;
}

function conflictMode(mixed $v, string $default = 'rename'): string {
    $v = is_string($v) ? $v : $default;
    if (!in_array($v, ['rename', 'replace', 'fail'], true)) throw new AppError('on_conflict לא מוכר', 400, 'bad_request');
    return $v;
}

// ─── nodes ────────────────────────────────────────────────────────────────

function nodeRow(array $a, mixed $id): array {
    if (!is_string($id) || $id === '') throw new AppError('חסר מזהה', 400, 'bad_request');
    $st = db()->prepare('SELECT * FROM nodes WHERE id = ? AND account = ?');
    $st->execute([$id, $a['id']]);
    $n = $st->fetch();
    if (!$n) throw new AppError('לא נמצא', 404, 'not_found');   // גם של חשבון אחר — 404, לא 403
    return $n;
}

/** בסל — אם הוא או אחד ההורים שלו בסל. */
function isTrashed(array $n): bool {
    for ($guard = 0; $n && $guard < 1000; $guard++) {
        if ($n['trashed_at'] !== null) return true;
        if ($n['parent'] === null) return false;
        $st = db()->prepare('SELECT * FROM nodes WHERE id = ?');
        $st->execute([$n['parent']]);
        $n = $st->fetch();
    }
    return false;
}

function liveNode(array $a, mixed $id): array {
    $n = nodeRow($a, $id);
    if (isTrashed($n)) throw new AppError('הפריט בסל', 404, 'not_found');
    return $n;
}

/** התיקייה שאליה כותבים: null = שורש הכונן. */
function targetFolder(array $a, mixed $parent): ?string {
    if ($parent === null || $parent === '') return null;
    $p = liveNode($a, $parent);
    if ($p['kind'] !== 'folder') throw new AppError('היעד אינו תיקייה', 400, 'bad_request');
    return $p['id'];
}

function publicNode(array $n): array {
    [$by, $app] = array_pad(explode('|', (string) $n['updated_by'], 2), 2, '');
    $out = [
        'id' => $n['id'], 'kind' => $n['kind'], 'name' => $n['name'], 'parent' => $n['parent'],
        'rev' => (int) $n['rev'], 'created_at' => $n['created_at'], 'updated_at' => $n['updated_at'],
        'updated_by' => ['name' => $by, 'app' => $app],
    ];
    if ($n['kind'] === 'file') $out += ['size' => (int) $n['size'], 'sha256' => $n['sha256'], 'mime' => $n['mime']];
    if ($n['trashed_at'] !== null) $out['trashed_at'] = $n['trashed_at'];
    return $out;
}

/** "שם|אפליקציה" — מי כתב ומאיזו אפליקציה ('' = הדף של הענן). */
function author(array $a, string $app): string {
    return str_replace('|', ' ', $a['name']) . '|' . $app;
}

function pathOf(array $a, ?string $folder): array {
    $path = [];
    for ($guard = 0; $folder !== null && $guard < 1000; $guard++) {
        $n = nodeRow($a, $folder);
        array_unshift($path, ['id' => $n['id'], 'name' => $n['name']]);
        $folder = $n['parent'];
    }
    return $path;
}

// ─── מכסה ─────────────────────────────────────────────────────────────────

function usedBytes(string $account): int {
    $st = db()->prepare("SELECT COALESCE(SUM(size), 0) FROM nodes WHERE account = ? AND kind = 'file'");
    $st->execute([$account]);
    return (int) $st->fetchColumn();
}

function quotaInfo(array $a): array {
    return ['quota' => accountQuota($a), 'used' => usedBytes($a['id']), 'max_file' => (int) setting('max_file')];
}

/** האם אפשר להוסיף $add בתים (ולהחליף קובץ בגודל $replacing). */
function assertRoom(array $a, int $add, int $replacing = 0): void {
    $max = (int) setting('max_file');
    if ($add > $max) throw new AppError('הקובץ גדול מהמותר (' . humanBytes($max) . ')', 413, 'too_large', ['max_file' => $max]);
    $q = quotaInfo($a);
    if ($q['used'] - $replacing + $add > $q['quota']) {
        throw new AppError($q['quota'] === 0 ? 'עוד לא הוקצה לך מקום בענן — פנו למנהל' : 'אין מספיק מקום בענן', 413, 'quota_exceeded', $q);
    }
}

function humanBytes(int $b): string {
    foreach (['ג"ב' => 1 << 30, 'מ"ב' => 1 << 20, 'ק"ב' => 1 << 10] as $u => $s) {
        if ($b >= $s) return rtrim(rtrim(number_format($b / $s, 1, '.', ''), '0'), '.') . " $u";
    }
    return "$b בתים";
}

// ─── התוכן בדיסק ──────────────────────────────────────────────────────────

function blobPath(string $sha): string {
    if (!preg_match('/^[0-9a-f]{64}$/', $sha)) throw new AppError('גיבוב לא תקין', 400, 'bad_checksum');
    return DATA_DIR . '/files/' . substr($sha, 0, 2) . '/' . substr($sha, 2, 2) . '/' . $sha;
}

/** מעביר קובץ זמני למקומו לפי הגיבוב. אותו תוכן כבר קיים — רק מוחקים את הזמני. */
function storeBlob(string $tmp, string $sha): void {
    $dest = blobPath($sha);
    if (is_file($dest)) { @unlink($tmp); return; }
    $dir = dirname($dest);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new AppError('לא ניתן לשמור את הקובץ', 500, 'server');
    if (!@rename($tmp, $dest)) throw new AppError('לא ניתן לשמור את הקובץ', 500, 'server');
}

/** קובץ בדיסק שאף node כבר לא מפנה אליו — נמחק. */
function dropBlobIfUnused(?string $sha): void {
    if ($sha === null) return;
    $st = db()->prepare('SELECT 1 FROM nodes WHERE sha256 = ? LIMIT 1');
    $st->execute([$sha]);
    if (!$st->fetchColumn()) @unlink(blobPath($sha));
}

function stagingFile(): string {
    $dir = DATA_DIR . '/uploads';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new AppError('לא ניתן ליצור תיקייה זמנית', 500, 'server');
    return $dir . '/' . randomId('t_') . '.tmp';
}

function cleanMime(mixed $mime, string $name): string {
    if (is_string($mime) && preg_match('#^[a-z0-9][a-z0-9!\#$&^_.+-]{0,63}/[a-z0-9][a-z0-9!\#$&^_.+-]{0,126}$#i', $mime)) return strtolower($mime);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return [
        'mbe' => 'application/vnd.mbe+zip', 'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        'txt' => 'text/plain', 'json' => 'application/json', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf', 'otf' => 'font/otf', 'zip' => 'application/zip',
    ][$ext] ?? 'application/octet-stream';
}

// ─── פעולות ───────────────────────────────────────────────────────────────

function listFolder(array $a, mixed $folder): array {
    purgeTrashMaybe();
    $fid = targetFolder($a, $folder);
    $st = db()->prepare("SELECT * FROM nodes WHERE account = ? AND parent IS ? AND trashed_at IS NULL
                         ORDER BY kind = 'file', name COLLATE NOCASE");
    $st->execute([$a['id'], $fid]);
    return ['folder' => $fid, 'path' => pathOf($a, $fid), 'items' => array_map('publicNode', $st->fetchAll())];
}

function makeFolder(array $a, array $in, string $app = ''): array {
    $parent = targetFolder($a, $in['parent'] ?? null);
    $name = cleanName($in['name'] ?? null);
    $mode = conflictMode($in['on_conflict'] ?? null);
    $same = siblingByName($a['id'], $parent, $name);
    if ($same) {
        if ($mode === 'fail') throw new AppError('כבר יש פריט בשם הזה', 409, 'name_taken');
        if ($mode === 'replace' && $same['kind'] === 'folder') return publicNode($same);   // "ודא שקיימת"
        $name = freeName($a['id'], $parent, $name);
    }
    $id = randomId('n_');
    $now = nowIso();
    db()->prepare("INSERT INTO nodes (id, account, parent, kind, name, created_at, updated_at, updated_by) VALUES (?,?,?,'folder',?,?,?,?)")
        ->execute([$id, $a['id'], $parent, $name, $now, $now, author($a, $app)]);
    return publicNode(nodeRow($a, $id));
}

/**
 * הצבת תוכן שכבר בדיסק (לפי sha) כקובץ בעץ. משותף ל-write ול-upload_finish.
 * replace — כותב על הקובץ הקיים באותו שם (אותו id, rev+1); if_rev — רק אם הוא עדיין באותה גרסה.
 */
function placeFile(array $a, ?string $parent, string $name, int $size, string $sha, string $mime, string $mode, ?int $ifRev, string $app): array {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $same = siblingByName($a['id'], $parent, $name);
        if ($same && $same['kind'] === 'folder' && $mode === 'replace') $mode = 'rename';
        if ($ifRev !== null && (!$same || $mode !== 'replace' || (int) $same['rev'] !== $ifRev)) {
            throw new AppError('הקובץ השתנה מאז שנפתח', 409, 'conflict', ['rev' => $same ? (int) $same['rev'] : null]);
        }
        $now = nowIso();
        if ($same && $mode === 'replace') {
            assertRoom($a, $size, (int) $same['size']);
            $pdo->prepare('UPDATE nodes SET size = ?, sha256 = ?, mime = ?, rev = rev + 1, updated_at = ?, updated_by = ? WHERE id = ?')
                ->execute([$size, $sha, $mime, $now, author($a, $app), $same['id']]);
            $id = $same['id'];
            $old = $same['sha256'];
        } else {
            if ($same && $mode === 'fail') throw new AppError('כבר יש פריט בשם הזה', 409, 'name_taken');
            assertRoom($a, $size);
            $id = randomId('n_');
            $pdo->prepare("INSERT INTO nodes (id, account, parent, kind, name, size, sha256, mime, created_at, updated_at, updated_by)
                           VALUES (?,?,?,'file',?,?,?,?,?,?,?)")
                ->execute([$id, $a['id'], $parent, $same ? freeName($a['id'], $parent, $name) : $name, $size, $sha, $mime, $now, $now, author($a, $app)]);
            $old = null;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        dropBlobIfUnused($sha);
        throw $e;
    }
    if ($old !== null && $old !== $sha) dropBlobIfUnused($old);
    return publicNode(nodeRow($a, $id));
}

/** קובץ קטן בקריאה אחת: content_b64 עד 4MB. */
function writeFile(array $a, array $in, string $app = ''): array {
    $parent = targetFolder($a, $in['parent'] ?? null);
    $name = cleanName($in['name'] ?? null);
    $bytes = is_string($in['content_b64'] ?? null) ? base64_decode($in['content_b64'], true) : false;
    if ($bytes === false) throw new AppError('תוכן לא תקין (base64)', 400, 'bad_request');
    if (strlen($bytes) > WRITE_MAX) throw new AppError('קובץ גדול מ-4MB — בהעלאה בחלקים', 413, 'too_large');
    $ifRev = isset($in['if_rev']) ? (int) $in['if_rev'] : null;
    $mode = conflictMode($in['on_conflict'] ?? null, $ifRev !== null ? 'replace' : 'rename');
    assertRoom($a, strlen($bytes));   // בדיקה מוקדמת, לפני הכתיבה לדיסק
    $sha = hash('sha256', $bytes);
    $tmp = stagingFile();
    if (file_put_contents($tmp, $bytes) !== strlen($bytes)) { @unlink($tmp); throw new AppError('לא ניתן לשמור את הקובץ', 500, 'server'); }
    storeBlob($tmp, $sha);
    return placeFile($a, $parent, $name, strlen($bytes), $sha, cleanMime($in['mime'] ?? null, $name), $mode, $ifRev, $app);
}

function uploadStart(array $a, array $in): array {
    purgeUploadsMaybe();
    $parent = targetFolder($a, $in['parent'] ?? null);
    $name = cleanName($in['name'] ?? null);
    $size = $in['size'] ?? null;
    if (!is_int($size) || $size < 0) throw new AppError('חסר גודל', 400, 'bad_request');
    // sha256 — רשות: נשלח → נבדק בסיום, ותוכן שכבר אצלנו לא מועלה שוב. בלי (קובץ גדול בטלפון) — השרת מחשב בסיום.
    $sha = strtolower((string) ($in['sha256'] ?? ''));
    if ($sha !== '') blobPath($sha);   // בודק פורמט
    $ifRev = isset($in['if_rev']) ? (int) $in['if_rev'] : null;
    $mode = conflictMode($in['on_conflict'] ?? null, $ifRev !== null ? 'replace' : 'rename');
    $same = siblingByName($a['id'], $parent, $name);
    assertRoom($a, $size, $same && $mode === 'replace' ? (int) $same['size'] : 0);
    if ($mode === 'fail' && $same) throw new AppError('כבר יש פריט בשם הזה', 409, 'name_taken');

    $id = randomId('u_');
    db()->prepare('INSERT INTO uploads (id, account, parent, name, size, sha256, mime, on_conflict, if_rev, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id, $a['id'], $parent, $name, $size, $sha, cleanMime($in['mime'] ?? null, $name), $mode, $ifRev, nowIso()]);
    // אותו תוכן כבר אצלנו — אין מה להעלות; upload_finish מציב אותו מיד.
    $have = $sha !== '' && is_file(blobPath($sha));
    return ['upload' => $id, 'chunk' => CHUNK, 'received' => $have ? $size : 0];
}

function uploadRow(array $a, mixed $id): array {
    $st = db()->prepare('SELECT * FROM uploads WHERE id = ? AND account = ?');
    $st->execute([is_string($id) ? $id : '', $a['id']]);
    $u = $st->fetch();
    if (!$u) throw new AppError('ההעלאה לא נמצאה (אולי פגה)', 404, 'not_found');
    return $u;
}

function partFile(string $upload): string {
    $dir = DATA_DIR . '/uploads';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new AppError('לא ניתן ליצור תיקייה זמנית', 500, 'server');
    return "$dir/$upload.part";
}

/**
 * חלק N של העלאה (גוף בינארי). חלקים בסדר בלבד; חלק שכבר התקבל — מתעלמים (חידוש אחרי ניתוק).
 * $body — נתיב לקובץ עם הגוף (php://input בשרת, קובץ זמני בבדיקות).
 */
function uploadChunk(array $a, mixed $upload, int $index, string $body): array {
    $u = uploadRow($a, $upload);
    $part = partFile($u['id']);
    clearstatcache(true, $part);
    $have = is_file($part) ? (int) filesize($part) : 0;
    $at = $index * CHUNK;
    if ($at < $have) return ['received' => $have];                                     // כבר יש
    if ($at !== $have) throw new AppError('חלק לא בסדר', 409, 'bad_request', ['received' => $have]);
    $in = fopen($body, 'rb');
    $out = fopen($part, 'ab');
    if (!$in || !$out) throw new AppError('לא ניתן לשמור את החלק', 500, 'server');
    $n = stream_copy_to_stream($in, $out, CHUNK + 1);
    fclose($in);
    fclose($out);
    $have += (int) $n;
    if ($n > CHUNK || $have > (int) $u['size']) {
        $fh = fopen($part, 'r+b');
        ftruncate($fh, $at);
        fclose($fh);
        throw new AppError('החלק גדול מהמותר', 413, 'too_large');
    }
    db()->prepare('UPDATE uploads SET received = ? WHERE id = ?')->execute([$have, $u['id']]);
    return ['received' => $have];
}

function uploadFinish(array $a, mixed $upload, string $app = ''): array {
    $u = uploadRow($a, $upload);
    $part = partFile($u['id']);
    clearstatcache(true, $part);
    $sha = $u['sha256'];
    if ($sha === '' || !is_file(blobPath($sha))) {
        if (!is_file($part) || (int) filesize($part) !== (int) $u['size']) {
            if ((int) $u['size'] === 0 && !is_file($part)) touch($part);
            else throw new AppError('ההעלאה לא הושלמה', 409, 'bad_request', ['received' => is_file($part) ? (int) filesize($part) : 0]);
        }
        $got = hash_file('sha256', $part);
        if ($sha === '') $sha = $got;
        if ($got !== $sha) {
            @unlink($part);
            db()->prepare('DELETE FROM uploads WHERE id = ?')->execute([$u['id']]);
            throw new AppError('הקובץ הגיע פגום — העלו שוב', 422, 'bad_checksum');
        }
        storeBlob($part, $sha);
    }
    @unlink($part);
    db()->prepare('DELETE FROM uploads WHERE id = ?')->execute([$u['id']]);
    $parent = $u['parent'];
    if ($parent !== null) $parent = targetFolder($a, $parent);   // התיקייה עדיין קיימת?
    return placeFile($a, $parent, $u['name'], (int) $u['size'], $sha, $u['mime'], $u['on_conflict'],
                     $u['if_rev'] !== null ? (int) $u['if_rev'] : null, $app);
}

function renameNode(array $a, array $in, string $app = ''): array {
    $n = liveNode($a, $in['id'] ?? null);
    $name = cleanName($in['name'] ?? null);
    if ($name === $n['name']) return publicNode($n);
    if (nameTaken($a['id'], $n['parent'], $name, $n['id'])) {
        if (conflictMode($in['on_conflict'] ?? null, 'fail') === 'fail') throw new AppError('כבר יש פריט בשם הזה', 409, 'name_taken');
        $name = freeName($a['id'], $n['parent'], $name, $n['id']);
    }
    db()->prepare('UPDATE nodes SET name = ?, updated_at = ?, updated_by = ? WHERE id = ?')->execute([$name, nowIso(), author($a, $app), $n['id']]);
    return publicNode(nodeRow($a, $n['id']));
}

function moveNode(array $a, array $in, string $app = ''): array {
    $n = liveNode($a, $in['id'] ?? null);
    $to = targetFolder($a, $in['parent'] ?? null);
    if ($to === $n['parent']) return publicNode($n);
    for ($p = $to; $p !== null; $p = nodeRow($a, $p)['parent']) {
        if ($p === $n['id']) throw new AppError('אי אפשר להעביר תיקייה לתוך עצמה', 400, 'bad_request');
    }
    $name = freeName($a['id'], $to, $n['name'], $n['id']);
    db()->prepare('UPDATE nodes SET parent = ?, name = ?, updated_at = ?, updated_by = ? WHERE id = ?')
        ->execute([$to, $name, nowIso(), author($a, $app), $n['id']]);
    return publicNode(nodeRow($a, $n['id']));
}

function trashNode(array $a, array $in): array {
    $n = liveNode($a, $in['id'] ?? null);
    db()->prepare('UPDATE nodes SET trashed_at = ? WHERE id = ?')->execute([nowIso(), $n['id']]);
    return publicNode(nodeRow($a, $n['id']));
}

/** מחזיר מהסל לתיקייה המקורית; אם היא נמחקה או בסל — לשורש. שם תפוס — "(2)". */
function restoreNode(array $a, array $in): array {
    $n = nodeRow($a, $in['id'] ?? null);
    if ($n['trashed_at'] === null) throw new AppError('הפריט לא בסל', 400, 'bad_request');
    $parent = $n['parent'];
    if ($parent !== null) {
        try { liveNode($a, $parent); } catch (AppError) { $parent = null; }
    }
    $name = freeName($a['id'], $parent, $n['name'], $n['id']);
    db()->prepare('UPDATE nodes SET trashed_at = NULL, parent = ?, name = ? WHERE id = ?')->execute([$parent, $name, $n['id']]);
    return publicNode(nodeRow($a, $n['id']));
}

function trashList(array $a): array {
    purgeTrashMaybe();
    $st = db()->prepare('SELECT * FROM nodes WHERE account = ? AND trashed_at IS NOT NULL ORDER BY trashed_at DESC');
    $st->execute([$a['id']]);
    return ['items' => array_map('publicNode', $st->fetchAll()), 'days' => TRASH_DAYS];
}

/** מחיקה לצמיתות של פריט שבסל, עם כל מה שבתוכו. */
function purgeNode(array $a, array $in): void {
    $n = nodeRow($a, $in['id'] ?? null);
    if ($n['trashed_at'] === null) throw new AppError('מוחקים לצמיתות רק מהסל', 400, 'bad_request');
    deleteTree($n['id']);
}

function emptyTrash(array $a): int {
    $st = db()->prepare('SELECT id FROM nodes WHERE account = ? AND trashed_at IS NOT NULL');
    $st->execute([$a['id']]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) deleteTree($id);
    return count($ids);
}

function deleteTree(string $root): void {
    $pdo = db();
    $st = $pdo->prepare('WITH RECURSIVE t(id) AS (SELECT ? UNION ALL SELECT n.id FROM nodes n JOIN t ON n.parent = t.id) SELECT id FROM t');
    $st->execute([$root]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) return;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $sh = $pdo->prepare("SELECT DISTINCT sha256 FROM nodes WHERE id IN ($in) AND sha256 IS NOT NULL");
    $sh->execute($ids);
    $shas = $sh->fetchAll(PDO::FETCH_COLUMN);
    $pdo->beginTransaction();
    // מהעלים לשורש — כך מפתח ההורה לא נשבר באמצע
    foreach (array_reverse($ids) as $id) $pdo->prepare('DELETE FROM nodes WHERE id = ?')->execute([$id]);
    $pdo->commit();
    foreach ($shas as $sha) dropBlobIfUnused($sha);
}

/** ניקוי עצל: מה שבסל יותר מ-30 יום — לצמיתות. לכל היותר פעם בשעה. */
function purgeTrashMaybe(bool $force = false): void {
    $last = (int) setting('last_purge');
    if (!$force && $last > time() - 3600) return;
    setSetting('last_purge', (string) time());
    $st = db()->prepare('SELECT id FROM nodes WHERE trashed_at IS NOT NULL AND trashed_at < ?');
    $st->execute([nowIso(time() - TRASH_DAYS * 86400)]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) deleteTree($id);
}

function purgeUploadsMaybe(): void {
    $st = db()->prepare('SELECT id FROM uploads WHERE created_at < ?');
    $st->execute([nowIso(time() - UPLOAD_TTL)]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        @unlink(partFile($id));
        db()->prepare('DELETE FROM uploads WHERE id = ?')->execute([$id]);
    }
    foreach (glob(DATA_DIR . '/uploads/*.tmp') ?: [] as $f) if (filemtime($f) < time() - UPLOAD_TTL) @unlink($f);
}

/** לקריאה: [נתיב בדיסק, node]. */
function readTarget(array $a, mixed $id): array {
    $n = liveNode($a, $id);
    if ($n['kind'] !== 'file') throw new AppError('זו תיקייה', 400, 'bad_request');
    $path = blobPath($n['sha256']);
    if (!is_file($path)) throw new AppError('תוכן הקובץ חסר בשרת', 500, 'server');
    return [$path, $n];
}

// ─── ניהול ────────────────────────────────────────────────────────────────

function adminState(): array {
    $rows = db()->query("SELECT a.*, COALESCE((SELECT SUM(size) FROM nodes n WHERE n.account = a.id AND n.kind = 'file'), 0) AS used
                         FROM accounts a ORDER BY a.name COLLATE NOCASE")->fetchAll();
    $disk = 0;
    foreach (db()->query("SELECT DISTINCT sha256, size FROM nodes WHERE kind = 'file'")->fetchAll() as $r) $disk += (int) $r['size'];
    return [
        'settings' => ['default_quota' => (int) setting('default_quota'), 'max_file' => (int) setting('max_file')],
        'disk' => $disk,
        'accounts' => array_map(fn($r) => publicAccount($r) + [
            'quota' => $r['quota'] !== null ? (int) $r['quota'] : null, 'effective_quota' => accountQuota($r),
            'used' => (int) $r['used'], 'blocked' => (int) $r['blocked'] === 1, 'last_login_at' => $r['last_login_at'],
        ], $rows),
    ];
}

function bytesIn(mixed $v, string $what): int {
    if (!is_int($v) || $v < 0 || $v > (1 << 50)) throw new AppError("$what לא תקין", 400, 'bad_request');
    return $v;
}

function adminSettings(array $in): array {
    if (array_key_exists('default_quota', $in)) setSetting('default_quota', (string) bytesIn($in['default_quota'], 'נפח ברירת המחדל'));
    if (array_key_exists('max_file', $in)) {
        $m = bytesIn($in['max_file'], 'התקרה לקובץ');
        if ($m < 1 << 20) throw new AppError('תקרה לקובץ — לפחות 1MB', 400, 'bad_request');
        setSetting('max_file', (string) $m);
    }
    return adminState();
}

function adminAccount(array $admin, array $in): array {
    $a = getAccount((string) ($in['account'] ?? ''));
    if (array_key_exists('quota', $in)) {
        $q = $in['quota'] === null ? null : bytesIn($in['quota'], 'המכסה');
        db()->prepare('UPDATE accounts SET quota = ? WHERE id = ?')->execute([$q, $a['id']]);
    }
    if (array_key_exists('role', $in)) {
        if (!in_array($in['role'], ['admin', 'user'], true)) throw new AppError('תפקיד לא מוכר', 400, 'bad_request');
        if ($a['id'] === $admin['id'] && $in['role'] !== 'admin') throw new AppError('אי אפשר להוריד את עצמך מניהול', 400, 'bad_request');
        db()->prepare('UPDATE accounts SET role = ? WHERE id = ?')->execute([$in['role'], $a['id']]);
    }
    if (array_key_exists('blocked', $in)) {
        if ($a['id'] === $admin['id']) throw new AppError('אי אפשר לחסום את עצמך', 400, 'bad_request');
        db()->prepare('UPDATE accounts SET blocked = ? WHERE id = ?')->execute([$in['blocked'] ? 1 : 0, $a['id']]);
    }
    return adminState();
}
