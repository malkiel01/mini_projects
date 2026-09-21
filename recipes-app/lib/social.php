<?php
/**
 * תגובות, פתקים פרטיים ומועדפים — סעיפים 6 ו-7 באפיון.
 *
 * שלושתם יושבים על אותן שתי טבלאות: comments (עם visibility שמבדיל תגובה
 * ציבורית מפתק פרטי) ו-favorites. ההכרעות שכבר נפלו ונשמרות כאן:
 * - תגובה רק במתכון ציבורי, ורק כשהכותב לא סגר תגובות.
 * - שתי רמות בלבד: תגובה ותשובות עליה. תשובה לתשובה נדחית.
 * - מי מוחק: הכותב את שלו, בעל המתכון כל תגובה אצלו, המנהל בכל מקום.
 *   מי עורך: רק הכותב.
 * - "נכתבה לפני עדכון המתכון" מחושב: created_at < recipes.updated_at.
 * - פתק פרטי: אחד לכל משתמש לכל מתכון (שלו או של אחר). טקסט ריק מוחק.
 * - מועדף מצביע על המקור. כשהמקור נמחק נשאר title_snapshot ו"הוסר על ידי
 *   הכותב"; כשהפך לפרטי — "אינו זמין כרגע".
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/recipes.php';

const COMMENT_MAX_CHARS = 2000;
const NOTE_MAX_CHARS    = 4000;

/** המתכון, או 404 אחיד — אותה תשובה לפרטי של אחר ולשאינו קיים. */
function viewableRecipeRow(int $recipeId, ?array $user): array {
    $st = db()->prepare('SELECT * FROM recipes WHERE id = ?');
    $st->execute([$recipeId]);
    $row = $st->fetch();
    if (!$row || !canView($row, $user)) throw new AppError('המתכון אינו קיים או שאינו זמין לך', 404);
    return $row;
}

// ─────────────────────────────────────────────────────────────
// תגובות
// ─────────────────────────────────────────────────────────────

/**
 * התגובות של מתכון, משורשרות לשתי רמות. למתכון פרטי מחזיר רשימה ריקה —
 * התגובות נשמרות ומוסתרות, וחוזרות כשהוא שוב ציבורי (סעיף 6).
 */
function listComments(int $recipeId, ?array $user): array {
    $recipe = viewableRecipeRow($recipeId, $user);
    if ($recipe['visibility'] !== 'public') return [];

    $st = db()->prepare('SELECT c.id, c.user_id, c.parent_id, c.text, c.created_at, c.edited_at,
                                u.display_name AS user_name
                           FROM comments c JOIN users u ON u.id = c.user_id
                          WHERE c.recipe_id = ? AND c.visibility = \'public\'
                          ORDER BY c.created_at, c.id');
    $st->execute([$recipeId]);

    $uid     = $user ? (int) $user['id'] : 0;
    $isOwner = $uid && (int) $recipe['owner_id'] === $uid;
    $isAdmin = $user && ($user['role'] ?? '') === 'admin';

    $byId = [];
    $top  = [];
    foreach ($st->fetchAll() as $c) {
        $mine = (int) $c['user_id'] === $uid;
        $item = [
            'id'            => (int) $c['id'],
            'user_id'       => (int) $c['user_id'],
            'user_name'     => $c['user_name'],
            'text'          => $c['text'],
            'created_at'    => $c['created_at'],
            'edited_at'     => $c['edited_at'],
            'before_update' => $c['created_at'] < $recipe['updated_at'],
            'is_mine'       => $mine,
            'can_edit'      => $mine,
            'can_delete'    => $mine || $isOwner || $isAdmin,
            'replies'       => [],
        ];
        $byId[$item['id']] = $item;
        if ($c['parent_id'] === null) $top[] = $item['id'];
        else $byId[(int) $c['parent_id']]['replies'][] = $item['id'];
    }
    // בניית העץ אחרי שכל השורות נאספו — תשובה יכולה להופיע לפני האב
    // רק אם הזמנים שווים, אבל עדיף לא לסמוך על זה.
    return array_map(function ($id) use ($byId) {
        $c = $byId[$id];
        $c['replies'] = array_map(fn($rid) => $byId[$rid], $c['replies']);
        return $c;
    }, $top);
}

function addComment(int $recipeId, ?int $parentId, string $text, array $user): int {
    $recipe = viewableRecipeRow($recipeId, $user);
    if ($recipe['visibility'] !== 'public') throw new AppError('אפשר להגיב רק במתכון ציבורי', 403);
    if ((int) $recipe['comments_open'] === 0) throw new AppError('הכותב סגר את התגובות במתכון הזה', 403);

    $text = trim($text);
    if ($text === '') throw new AppError('התגובה ריקה');
    if (mb_strlen($text) > COMMENT_MAX_CHARS) throw new AppError('התגובה ארוכה מדי (עד ' . COMMENT_MAX_CHARS . ' תווים)');

    if ($parentId !== null) {
        $st = db()->prepare('SELECT recipe_id, parent_id, visibility FROM comments WHERE id = ?');
        $st->execute([$parentId]);
        $parent = $st->fetch();
        if (!$parent || (int) $parent['recipe_id'] !== $recipeId || $parent['visibility'] !== 'public') {
            throw new AppError('התגובה שמשיבים לה אינה קיימת', 404);
        }
        // שתי רמות בלבד (7): תשובה לתשובה נתלית על התגובה הראשית.
        if ($parent['parent_id'] !== null) $parentId = (int) $parent['parent_id'];
    }

    $st = db()->prepare('INSERT INTO comments (recipe_id, user_id, parent_id, visibility, text, created_at)
                         VALUES (?,?,?,?,?,?)');
    $st->execute([$recipeId, $user['id'], $parentId, 'public', $text, nowIso()]);
    return (int) db()->lastInsertId();
}

function commentRow(int $commentId): array {
    $st = db()->prepare('SELECT c.*, r.owner_id FROM comments c JOIN recipes r ON r.id = c.recipe_id
                          WHERE c.id = ? AND c.visibility = \'public\'');
    $st->execute([$commentId]);
    $c = $st->fetch();
    if (!$c) throw new AppError('התגובה אינה קיימת', 404);
    return $c;
}

function editComment(int $commentId, string $text, array $user): void {
    $c = commentRow($commentId);
    if ((int) $c['user_id'] !== (int) $user['id']) throw new AppError('אפשר לערוך רק תגובה שכתבת', 403);
    $text = trim($text);
    if ($text === '') throw new AppError('התגובה ריקה');
    if (mb_strlen($text) > COMMENT_MAX_CHARS) throw new AppError('התגובה ארוכה מדי (עד ' . COMMENT_MAX_CHARS . ' תווים)');
    $st = db()->prepare('UPDATE comments SET text = ?, edited_at = ? WHERE id = ?');
    $st->execute([$text, nowIso(), $commentId]);
}

/** מחיקה. תשובות נמחקות עם התגובה הראשית (CASCADE על parent_id). */
function deleteComment(int $commentId, array $user): void {
    $c = commentRow($commentId);
    $uid = (int) $user['id'];
    $allowed = (int) $c['user_id'] === $uid
            || (int) $c['owner_id'] === $uid
            || ($user['role'] ?? '') === 'admin';
    if (!$allowed) throw new AppError('אין לך הרשאה למחוק את התגובה', 403);
    $st = db()->prepare('DELETE FROM comments WHERE id = ?');
    $st->execute([$commentId]);
}

/** הכותב סוגר או פותח תגובות במתכון שלו (7). התגובות הקיימות נשארות. */
function setCommentsOpen(int $recipeId, bool $open, array $user): void {
    requireOwnRecipe($recipeId, $user);
    $st = db()->prepare('UPDATE recipes SET comments_open = ? WHERE id = ?');
    $st->execute([$open ? 1 : 0, $recipeId]);
}

// ─────────────────────────────────────────────────────────────
// פתק פרטי
// ─────────────────────────────────────────────────────────────

function getNote(int $recipeId, array $user): ?array {
    viewableRecipeRow($recipeId, $user);
    $st = db()->prepare('SELECT id, text, created_at, edited_at FROM comments
                          WHERE recipe_id = ? AND user_id = ? AND visibility = \'private_note\'');
    $st->execute([$recipeId, $user['id']]);
    $n = $st->fetch();
    return $n ? [
        'text'       => $n['text'],
        'updated_at' => $n['edited_at'] ?? $n['created_at'],
    ] : null;
}

/** פתק אחד לכל משתמש לכל מתכון. טקסט ריק = מחיקה. */
function setNote(int $recipeId, string $text, array $user): ?array {
    viewableRecipeRow($recipeId, $user);
    $text = trim($text);
    if (mb_strlen($text) > NOTE_MAX_CHARS) throw new AppError('הפתק ארוך מדי (עד ' . NOTE_MAX_CHARS . ' תווים)');

    $st = db()->prepare('SELECT id FROM comments
                          WHERE recipe_id = ? AND user_id = ? AND visibility = \'private_note\'');
    $st->execute([$recipeId, $user['id']]);
    $existing = $st->fetch();

    if ($text === '') {
        if ($existing) db()->prepare('DELETE FROM comments WHERE id = ?')->execute([$existing['id']]);
        return null;
    }
    if ($existing) {
        db()->prepare('UPDATE comments SET text = ?, edited_at = ? WHERE id = ?')
            ->execute([$text, nowIso(), $existing['id']]);
    } else {
        db()->prepare('INSERT INTO comments (recipe_id, user_id, visibility, text, created_at)
                       VALUES (?,?,?,?,?)')
            ->execute([$recipeId, $user['id'], 'private_note', $text, nowIso()]);
    }
    return getNote($recipeId, $user);
}

// ─────────────────────────────────────────────────────────────
// מועדפים
// ─────────────────────────────────────────────────────────────

function isFavorite(int $recipeId, ?array $user): bool {
    if (!$user) return false;
    $st = db()->prepare('SELECT 1 FROM favorites WHERE user_id = ? AND recipe_id = ?');
    $st->execute([$user['id'], $recipeId]);
    return (bool) $st->fetch();
}

/** "שמור אצלי": מוסיף אם אין, מסיר אם יש. מחזיר את המצב החדש. */
function toggleFavorite(int $recipeId, array $user): bool {
    $recipe = viewableRecipeRow($recipeId, $user);
    if ((int) $recipe['owner_id'] === (int) $user['id']) throw new AppError('המתכון כבר שלך — הוא ברשימה בלי לשמור');

    if (isFavorite($recipeId, $user)) {
        db()->prepare('DELETE FROM favorites WHERE user_id = ? AND recipe_id = ?')
            ->execute([$user['id'], $recipeId]);
        return false;
    }
    db()->prepare('INSERT INTO favorites (user_id, recipe_id, title_snapshot, created_at) VALUES (?,?,?,?)')
        ->execute([$user['id'], $recipeId, $recipe['title'], nowIso()]);
    return true;
}

/**
 * המועדפים של המשתמש. לכל אחד status:
 *   ok     — המתכון זמין, ויש לו את שדות הרשימה הרגילים
 *   hidden — קיים אך הכותב הפך אותו לפרטי ("אינו זמין כרגע")
 *   gone   — נמחק ("הוסר על ידי הכותב"), נשאר רק השם שנשמר
 */
function listFavorites(array $user): array {
    $st = db()->prepare('SELECT f.id AS fav_id, f.recipe_id, f.title_snapshot, f.created_at AS saved_at,
                                r.title, r.visibility, r.owner_id, r.difficulty, r.work_minutes, r.wait_minutes,
                                u.display_name AS owner_name, m.path_or_url AS main_path
                           FROM favorites f
                           LEFT JOIN recipes r ON r.id = f.recipe_id
                           LEFT JOIN users u ON u.id = r.owner_id
                           LEFT JOIN media m ON m.id = r.main_media_id AND m.source = \'upload\'
                          WHERE f.user_id = ?
                          ORDER BY f.created_at DESC');
    $st->execute([$user['id']]);
    $uid = (int) $user['id'];
    return array_map(function ($row) use ($uid) {
        if ($row['recipe_id'] === null) $status = 'gone';
        elseif ($row['visibility'] !== 'public' && (int) $row['owner_id'] !== $uid) $status = 'hidden';
        else $status = 'ok';
        return [
            'fav_id'       => (int) $row['fav_id'],
            'status'       => $status,
            'id'           => $row['recipe_id'] !== null ? (int) $row['recipe_id'] : null,
            'title'        => $status === 'gone' ? $row['title_snapshot'] : $row['title'],
            'owner_name'   => $status === 'ok' ? $row['owner_name'] : null,
            'difficulty'   => $status === 'ok' ? $row['difficulty'] : null,
            'work_minutes' => $status === 'ok' && $row['work_minutes'] !== null ? (int) $row['work_minutes'] : null,
            'wait_minutes' => $status === 'ok' && $row['wait_minutes'] !== null ? (int) $row['wait_minutes'] : null,
            'thumb'        => $status === 'ok' && $row['main_path'] ? 'data/media/' . basename($row['main_path']) : null,
            'saved_at'     => $row['saved_at'],
        ];
    }, $st->fetchAll());
}

function removeFavorite(int $favId, array $user): void {
    $st = db()->prepare('DELETE FROM favorites WHERE id = ? AND user_id = ?');
    $st->execute([$favId, $user['id']]);
    if ($st->rowCount() === 0) throw new AppError('המועדף אינו קיים', 404);
}
