<?php
/**
 * הספרייה בשרת: הציורים של כל משתמש, עם גרסאות.
 *
 * המסמך נשמר כמחרוזת ה-JSON שהעורך שלח, בלי לפענח ולכתוב מחדש — כך `{}` לא הופך ל-`[]` ושום שדה של אפליקציה
 * אחרת לא משתנה (mbe-format, "שמור את מה שלא הבנת"). בשלב ה-‎.mbe זה יהיה document.json של הקובץ.
 *
 * התנגשות: כל שמירה מביאה את `rev` שהעורך טען. לא תואם — 409, ולא דורסים עבודה שנשמרה מחלון אחר.
 * גרסאות: לפני שמירה, אם הגרסה האחרונה ישנה מ-10 דקות — העותק הנוכחי נכנס להיסטוריה; נשמרות 30 אחרונות.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const DOC_MAX = 8 * 1024 * 1024;   // 8MB של JSON
const THUMB_MAX = 300 * 1024;      // תמונה ממוזערת PNG
const VERSION_EVERY = 600;         // שניות בין גרסאות
const VERSIONS_KEEP = 30;

function migrateDrawings(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS drawings (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            name        TEXT    NOT NULL,
            doc         TEXT    NOT NULL,
            thumb       BLOB,
            rev         INTEGER NOT NULL DEFAULT 1,
            created_at  TEXT    NOT NULL,
            updated_at  TEXT    NOT NULL
        );
        CREATE INDEX IF NOT EXISTS drawings_user ON drawings (user_id, updated_at);
        CREATE TABLE IF NOT EXISTS drawing_versions (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            drawing_id  INTEGER NOT NULL REFERENCES drawings(id) ON DELETE CASCADE,
            rev         INTEGER NOT NULL,
            name        TEXT    NOT NULL,
            doc         TEXT    NOT NULL,
            saved_at    TEXT    NOT NULL
        );
        CREATE INDEX IF NOT EXISTS versions_drawing ON drawing_versions (drawing_id, id);
    ");
}

function drawingName(mixed $v): string {
    $n = is_string($v) ? trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $v) ?? '') : '';
    if ($n === '') throw new AppError('חסר שם לציור');
    return mb_substr($n, 0, 120);
}

/** בדיקת המסמך: JSON תקין, אובייקט עם surfaces. לא מפענחים לכתיבה — רק מוודאים. */
function checkDoc(mixed $json): string {
    if (!is_string($json) || $json === '') throw new AppError('חסר מסמך');
    if (strlen($json) > DOC_MAX) throw new AppError('הציור גדול מדי (מעל 8MB)', 413);
    $d = json_decode($json, false, 512);
    if (!($d instanceof stdClass) || !isset($d->surfaces) || !is_array($d->surfaces) || !$d->surfaces) {
        throw new AppError('המסמך פגום');
    }
    return $json;
}

/** תמונה ממוזערת: data:image/png;base64,… → בתים של PNG, או null. */
function checkThumb(mixed $v): ?string {
    if ($v === null || $v === '') return null;
    if (!is_string($v) || !str_starts_with($v, 'data:image/png;base64,')) throw new AppError('תמונה ממוזערת לא תקינה');
    $b = base64_decode(substr($v, 22), true);
    if ($b === false || !str_starts_with($b, "\x89PNG\r\n\x1a\n")) throw new AppError('תמונה ממוזערת לא תקינה');
    if (strlen($b) > THUMB_MAX) throw new AppError('תמונה ממוזערת גדולה מדי');
    return $b;
}

function ownDrawing(array $user, int $id): array {
    $st = db()->prepare('SELECT * FROM drawings WHERE id = ? AND user_id = ?');
    $st->execute([$id, $user['id']]);
    $d = $st->fetch();
    if (!$d) throw new AppError('הציור לא נמצא', 404);   // גם של משתמש אחר — לא מגלים שקיים
    return $d;
}

function listDrawings(array $user): array {
    $st = db()->prepare('SELECT id, name, rev, updated_at, thumb IS NOT NULL AS has_thumb FROM drawings WHERE user_id = ? ORDER BY updated_at DESC, id DESC');
    $st->execute([$user['id']]);
    return array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'rev' => (int) $r['rev'],
        'updated' => $r['updated_at'], 'thumb' => (bool) $r['has_thumb']], $st->fetchAll());
}

function getDrawing(array $user, int $id): array {
    $d = ownDrawing($user, $id);
    return ['id' => (int) $d['id'], 'name' => $d['name'], 'rev' => (int) $d['rev'], 'updated' => $d['updated_at'], 'doc' => $d['doc']];
}

function createDrawing(array $user, array $in): array {
    $name = drawingName($in['name'] ?? '');
    $doc = checkDoc($in['doc'] ?? null);
    $thumb = checkThumb($in['thumb'] ?? null);
    $now = nowIso();
    $st = db()->prepare('INSERT INTO drawings (user_id, name, doc, thumb, rev, created_at, updated_at) VALUES (?,?,?,?,1,?,?)');
    $st->bindValue(1, $user['id'], PDO::PARAM_INT);
    $st->bindValue(2, $name);
    $st->bindValue(3, $doc);
    $st->bindValue(4, $thumb, $thumb === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
    $st->bindValue(5, $now);
    $st->bindValue(6, $now);
    $st->execute();
    return ['id' => (int) db()->lastInsertId(), 'rev' => 1, 'updated' => $now];
}

/** שמירה: rev חייב להיות זה שנטען. מחזיר את ה-rev החדש. */
function saveDrawing(array $user, int $id, array $in): array {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $d = ownDrawing($user, $id);
        if ((int) ($in['rev'] ?? 0) !== (int) $d['rev']) {
            throw new AppError('הציור נשמר בינתיים מחלון אחר — פתחו אותו מחדש', 409);
        }
        $name = array_key_exists('name', $in) ? drawingName($in['name']) : $d['name'];
        $doc = array_key_exists('doc', $in) ? checkDoc($in['doc']) : $d['doc'];
        $thumb = array_key_exists('thumb', $in) ? checkThumb($in['thumb']) : $d['thumb'];
        $now = nowIso();

        $last = $pdo->prepare('SELECT saved_at FROM drawing_versions WHERE drawing_id = ? ORDER BY id DESC LIMIT 1');
        $last->execute([$id]);
        $lastAt = $last->fetchColumn();
        if ($doc !== $d['doc'] && (!$lastAt || strtotime($now) - strtotime((string) $lastAt) >= VERSION_EVERY)) {
            $pdo->prepare('INSERT INTO drawing_versions (drawing_id, rev, name, doc, saved_at) VALUES (?,?,?,?,?)')
                ->execute([$id, $d['rev'], $d['name'], $d['doc'], $now]);
            $pdo->prepare('DELETE FROM drawing_versions WHERE drawing_id = ? AND id NOT IN (SELECT id FROM drawing_versions WHERE drawing_id = ? ORDER BY id DESC LIMIT ' . VERSIONS_KEEP . ')')
                ->execute([$id, $id]);
        }
        $rev = (int) $d['rev'] + 1;
        $st = $pdo->prepare('UPDATE drawings SET name = ?, doc = ?, thumb = ?, rev = ?, updated_at = ? WHERE id = ?');
        $st->bindValue(1, $name);
        $st->bindValue(2, $doc);
        $st->bindValue(3, $thumb, $thumb === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $st->bindValue(4, $rev, PDO::PARAM_INT);
        $st->bindValue(5, $now);
        $st->bindValue(6, $id, PDO::PARAM_INT);
        $st->execute();
        $pdo->commit();
        return ['id' => $id, 'rev' => $rev, 'updated' => $now];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function deleteDrawing(array $user, int $id): void {
    ownDrawing($user, $id);
    db()->prepare('DELETE FROM drawings WHERE id = ?')->execute([$id]);
}

function drawingThumb(array $user, int $id): ?string {
    $d = ownDrawing($user, $id);
    return $d['thumb'] === null ? null : (string) $d['thumb'];
}

function listVersions(array $user, int $id): array {
    ownDrawing($user, $id);
    $st = db()->prepare('SELECT id, rev, name, saved_at FROM drawing_versions WHERE drawing_id = ? ORDER BY id DESC');
    $st->execute([$id]);
    return array_map(fn ($r) => ['id' => (int) $r['id'], 'rev' => (int) $r['rev'], 'name' => $r['name'], 'saved' => $r['saved_at']], $st->fetchAll());
}

/** שחזור גרסה: הנוכחית נכנסת להיסטוריה (תמיד), והגרסה הישנה הופכת לנוכחית. */
function restoreVersion(array $user, int $id, int $versionId, int $rev): array {
    $pdo = db();
    $d = ownDrawing($user, $id);
    $st = $pdo->prepare('SELECT * FROM drawing_versions WHERE id = ? AND drawing_id = ?');
    $st->execute([$versionId, $id]);
    $v = $st->fetch();
    if (!$v) throw new AppError('הגרסה לא נמצאה', 404);
    if ($rev !== (int) $d['rev']) throw new AppError('הציור נשמר בינתיים מחלון אחר — פתחו אותו מחדש', 409);
    $pdo->prepare('INSERT INTO drawing_versions (drawing_id, rev, name, doc, saved_at) VALUES (?,?,?,?,?)')
        ->execute([$id, $d['rev'], $d['name'], $d['doc'], nowIso()]);
    $now = nowIso();
    $pdo->prepare('UPDATE drawings SET doc = ?, rev = rev + 1, updated_at = ? WHERE id = ?')->execute([$v['doc'], $now, $id]);
    return getDrawing($user, $id);
}
