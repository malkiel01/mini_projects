<?php
/**
 * תיקיות ומועדפים (שלב 5).
 *  - תיקיות בתוך תיקיות, לכל חשבון (folders.space = 'a:<id>'). מסמך בתיקייה אחת (docs.folder) — או בראש הרשימה.
 *    מסמך ששותף איתי — לא בתיקיות שלי (התיקייה היא של הבעלים); הוא ב"משותף איתי".
 *  - מחיקת תיקייה: מה שבתוכה (מסמכים ותיקיות) עובר לתיקייה שמעליה — שום מסמך לא נמחק בגלל תיקייה.
 *  - מועדפים: כוכב למסמך, לכל חשבון (גם למסמך ששותף איתי) — מקטע "מועדפים" בראש הרשימה.
 */

const FOLDERS_MAX = 500;      // לחשבון
const FOLDER_DEPTH = 10;      // תיקייה בתוך תיקייה — עד כמה רמות

function folders_of(string $space): array
{
    $st = db()->prepare('SELECT id, parent, name FROM folders WHERE space = ? ORDER BY name COLLATE NOCASE');
    $st->execute([$space]);
    return array_map(fn($r) => ['id' => $r['id'], 'parent' => $r['parent'], 'name' => $r['name']], $st->fetchAll());
}

/** התיקייה — רק של המרחב הזה; null / '' — ראש הרשימה. */
function folder_get(string $space, $id): ?array
{
    if ($id === null || $id === '') return null;
    if (!is_string($id) || !preg_match('/^[a-z0-9]{6,40}$/', $id)) throw new ApiError('התיקייה לא נמצאה', 'not_found', 404);
    $st = db()->prepare('SELECT * FROM folders WHERE id = ? AND space = ?');
    $st->execute([$id, $space]);
    $f = $st->fetch();
    $st->closeCursor();
    if (!$f) throw new ApiError('התיקייה לא נמצאה', 'not_found', 404);
    return $f;
}

function folder_name($name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', (string)$name));
    if ($name === '' || mb_strlen($name) > 100) throw new ApiError('שם תיקייה — 1 עד 100 תווים', 'bad_name');
    return $name;
}

/** העומק של תיקייה (ראש הרשימה — 0), וכל האבות שלה. */
function folder_ancestors(string $space, ?string $id): array
{
    $out = [];
    while ($id !== null && count($out) <= FOLDER_DEPTH + 1) {
        $f = folder_get($space, $id);
        $out[] = $f['id'];
        $id = $f['parent'];
    }
    return $out;
}

function folder_create(string $space, $name, $parent): string
{
    $p = folder_get($space, $parent);
    if ($p && count(folder_ancestors($space, $p['id'])) >= FOLDER_DEPTH) throw new ApiError('אפשר עד ' . FOLDER_DEPTH . ' רמות של תיקיות', 'too_deep');
    $st = db()->prepare('SELECT COUNT(*) FROM folders WHERE space = ?');
    $st->execute([$space]);
    if ((int)$st->fetchColumn() >= FOLDERS_MAX) throw new ApiError('הגעת למספר התיקיות המרבי (' . FOLDERS_MAX . ')', 'too_many');
    $id = rand_id(16);
    db()->prepare('INSERT INTO folders (id, space, parent, name, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$id, $space, $p['id'] ?? null, folder_name($name), time()]);
    return $id;
}

function folder_rename(string $space, $id, $name): void
{
    $f = folder_get($space, $id) ?? throw new ApiError('התיקייה לא נמצאה', 'not_found', 404);
    db()->prepare('UPDATE folders SET name = ? WHERE id = ?')->execute([folder_name($name), $f['id']]);
}

/** העברת תיקייה (עם כל מה שבתוכה) — לא לתוך עצמה או לתוך תיקייה שבתוכה. */
function folder_move(string $space, $id, $parent): void
{
    $f = folder_get($space, $id) ?? throw new ApiError('התיקייה לא נמצאה', 'not_found', 404);
    $p = folder_get($space, $parent);
    if ($p && in_array($f['id'], folder_ancestors($space, $p['id']), true)) throw new ApiError('אי אפשר להעביר תיקייה לתוך עצמה', 'cycle');
    // העומק אחרי ההעברה: מעל — עד היעד; מתחת — העומק של התת-עץ
    $depth = function (string $fid) use ($space, &$depth): int {
        $st = db()->prepare('SELECT id FROM folders WHERE space = ? AND parent = ?');
        $st->execute([$space, $fid]);
        $m = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) $m = max($m, $depth($c));
        return $m + 1;
    };
    if (($p ? count(folder_ancestors($space, $p['id'])) : 0) + $depth($f['id']) > FOLDER_DEPTH) throw new ApiError('אפשר עד ' . FOLDER_DEPTH . ' רמות של תיקיות', 'too_deep');
    db()->prepare('UPDATE folders SET parent = ? WHERE id = ?')->execute([$p['id'] ?? null, $f['id']]);
}

/** מחיקה: המסמכים והתיקיות שבתוכה — לתיקייה שמעליה. */
function folder_delete(string $space, $id): void
{
    $f = folder_get($space, $id) ?? throw new ApiError('התיקייה לא נמצאה', 'not_found', 404);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE docs SET folder = ? WHERE space = ? AND folder = ?')->execute([$f['parent'], $space, $f['id']]);
        $pdo->prepare('UPDATE folders SET parent = ? WHERE space = ? AND parent = ?')->execute([$f['parent'], $space, $f['id']]);
        $pdo->prepare('DELETE FROM folders WHERE id = ?')->execute([$f['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** העברת מסמך לתיקייה (רק הבעלים — המסמך במרחב שלו). */
function doc_move(array $row, $folder): void
{
    $f = folder_get($row['space'], $folder);
    db()->prepare('UPDATE docs SET folder = ? WHERE id = ?')->execute([$f['id'] ?? null, $row['id']]);
}

function favs_of(array $acc): array
{
    $st = db()->prepare('SELECT doc FROM favorites WHERE account = ? ORDER BY created_at DESC');
    $st->execute([(int)$acc['id']]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

function fav_set(array $acc, string $doc, bool $on): void
{
    if ($on) db()->prepare('INSERT OR IGNORE INTO favorites (account, doc, created_at) VALUES (?, ?, ?)')->execute([(int)$acc['id'], $doc, time()]);
    else db()->prepare('DELETE FROM favorites WHERE account = ? AND doc = ?')->execute([(int)$acc['id'], $doc]);
}
