<?php
/**
 * מתכונים — יצירה, קריאה, עדכון, מחיקה.
 *
 * שתי החלטות מהאפיון שולטות בקובץ הזה:
 *
 * 1. מתכון הוא **רשימת חלקים** (3.1), גם כשיש חלק אחד. מתכון פשוט הוא
 *    חלק אחד בלי שם, והממשק אינו מציג לו חלוקה. בקוד אין שני מסלולים.
 *
 * 2. לכל רכיב **שתי שכבות** (3.2): טקסט חופשי שהמבשל קורא, וערך מחושב
 *    שהאפליקציה מחשבת בו. הראשונה חובה, השנייה רשות — ורכיב בלי שכבה
 *    מחושבת פשוט אינו משתתף בהמרת מנות וברשימת הקניות.
 *
 * שמירה מחליפה את כל תוכן המתכון בטרנזקציה אחת. זה פשוט יותר מלגזור
 * הפרשים בין מה שהיה למה שנשלח, והוא גם מה שמונע מצב ביניים שבו נשמרו
 * חצי מהשלבים.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/media.php';

const UNITS = ['gram','kg','ml','liter','cup','tbsp','tsp','unit','package','pinch'];
const DIFFICULTIES = ['easy','medium','hard'];

/** מוצר מהקטלוג לפי שם, ויוצר אותו אם אינו קיים. הקטלוג גדל מעצמו (3.2). */
function productId(?string $name): ?int {
    $name = trim((string) $name);
    if ($name === '') return null;

    $st = db()->prepare('SELECT id FROM products WHERE name = ?');
    $st->execute([$name]);
    $row = $st->fetch();
    if ($row) return (int) $row['id'];

    $st = db()->prepare('INSERT INTO products (name, name_norm, created_at) VALUES (?,?,?)');
    $st->execute([$name, normalizeText($name), nowIso()]);
    return (int) db()->lastInsertId();
}

/**
 * הטקסט שהחיפוש רץ עליו (5): שם, רכיבים, הכנה וטיפים — לא תגובות.
 * נבנה בשמירה ולא בכל חיפוש, כי חיפוש קורה הרבה יותר מכתיבה.
 */
function buildSearchText(array $recipe, array $sections): string {
    $parts = [$recipe['title'] ?? '', $recipe['tips'] ?? ''];
    foreach ($sections as $section) {
        $parts[] = $section['name'] ?? '';
        foreach ($section['ingredients'] ?? [] as $ing) {
            $parts[] = $ing['free_text'] ?? '';
            $parts[] = $ing['product'] ?? '';
        }
        foreach ($section['steps'] ?? [] as $step) {
            $parts[] = $step['text'] ?? '';
        }
    }
    return normalizeText(implode(' ', array_filter($parts)));
}

/** בודק שהמשתמש רשאי לגעת במתכון. המנהל אינו נכנס למתכון פרטי (2). */
function requireOwnRecipe(int $recipeId, array $user): array {
    $st = db()->prepare('SELECT * FROM recipes WHERE id = ?');
    $st->execute([$recipeId]);
    $recipe = $st->fetch();
    if (!$recipe) throw new AppError('המתכון אינו קיים', 404);

    $isOwner = (int) $recipe['owner_id'] === (int) $user['id'];
    // המנהל מוחק מתכון ציבורי פוגעני, אך אינו עורך מתכון של אחר ואינו
    // רואה פרטי. זו ההרשאה המדויקת שהאפיון נתן לו.
    if (!$isOwner) throw new AppError('המתכון אינו שלך', 403);
    return $recipe;
}

function canView(array $recipe, ?array $user): bool {
    if ($recipe['visibility'] === 'public') return true;
    return $user && (int) $recipe['owner_id'] === (int) $user['id'];
}

/**
 * שומר מתכון — חדש או קיים — על כל תוכנו, בטרנזקציה אחת.
 * מחזיר את המזהה.
 */
function saveRecipe(array $in, array $user, ?int $recipeId = null): int {
    $title = trim((string) ($in['title'] ?? ''));
    if ($title === '') throw new AppError('למתכון חייב להיות שם');
    if (mb_strlen($title) > 120) throw new AppError('שם המתכון ארוך מדי');

    $visibility = in_array($in['visibility'] ?? '', ['private','public'], true)
        ? $in['visibility'] : 'private';
    $difficulty = in_array($in['difficulty'] ?? '', DIFFICULTIES, true)
        ? $in['difficulty'] : null;

    $sections = is_array($in['sections'] ?? null) ? $in['sections'] : [];
    if (!$sections) throw new AppError('למתכון חייב להיות לפחות חלק אחד');

    // null = לא נשלח: בהוספה פתוח, בעדכון נשאר כמו שהיה.
    $commentsOpen = array_key_exists('comments_open', $in) ? (int) (bool) $in['comments_open'] : null;

    $recipe = [
        'title'    => $title,
        'tips'     => trim((string) ($in['tips'] ?? '')),
        'servings' => positiveIntOrNull($in['servings'] ?? null),
        'work'     => positiveIntOrNull($in['work_minutes'] ?? null),
        'wait'     => positiveIntOrNull($in['wait_minutes'] ?? null),
    ];
    $search = buildSearchText($recipe + ['title' => $title], $sections);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($recipeId === null) {
            $st = $pdo->prepare('INSERT INTO recipes (owner_id, title, visibility, servings,
                    difficulty, work_minutes, wait_minutes, tips, search_text, comments_open,
                    created_at, updated_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute([$user['id'], $title, $visibility, $recipe['servings'], $difficulty,
                          $recipe['work'], $recipe['wait'], $recipe['tips'], $search,
                          $commentsOpen ?? 1, nowIso(), nowIso()]);
            $recipeId = (int) $pdo->lastInsertId();
        } else {
            $st = $pdo->prepare('UPDATE recipes SET title=?, visibility=?, servings=?, difficulty=?,
                    work_minutes=?, wait_minutes=?, tips=?, search_text=?,
                    comments_open=COALESCE(?, comments_open), updated_at=? WHERE id=?');
            $st->execute([$title, $visibility, $recipe['servings'], $difficulty, $recipe['work'],
                          $recipe['wait'], $recipe['tips'], $search, $commentsOpen, nowIso(), $recipeId]);
            // החלפה מלאה: החלקים נמחקים, וה-CASCADE גורר איתם רכיבים
            // ושלבים. לכן אין צורך למחוק אותם בנפרד — וגם אסור לשכוח
            // ש-foreign_keys חייב להיות דלוק כדי שזה יקרה.
            $st = $pdo->prepare('DELETE FROM sections WHERE recipe_id = ?');
            $st->execute([$recipeId]);
            $st = $pdo->prepare('DELETE FROM recipe_tags WHERE recipe_id = ?');
            $st->execute([$recipeId]);
        }

        writeSections($pdo, $recipeId, $sections);
        writeTags($pdo, $recipeId, $in['tag_ids'] ?? []);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $recipeId;
}

function positiveIntOrNull($value): ?int {
    if ($value === null || $value === '') return null;
    $n = (int) $value;
    return $n > 0 ? $n : null;
}

function writeSections(PDO $pdo, int $recipeId, array $sections): void {
    $secSt  = $pdo->prepare('INSERT INTO sections (recipe_id, name, position) VALUES (?,?,?)');
    $ingSt  = $pdo->prepare('INSERT INTO ingredients (section_id, free_text, amount_min,
                             amount_max, unit, product_id, optional, position) VALUES (?,?,?,?,?,?,?,?)');
    $stepSt = $pdo->prepare('INSERT INTO steps (section_id, position, text) VALUES (?,?,?)');

    foreach (array_values($sections) as $sIndex => $section) {
        $name = trim((string) ($section['name'] ?? ''));
        $secSt->execute([$recipeId, $name === '' ? null : $name, $sIndex + 1]);
        $sectionId = (int) $pdo->lastInsertId();

        foreach (array_values($section['ingredients'] ?? []) as $iIndex => $ing) {
            $free = trim((string) ($ing['free_text'] ?? ''));
            if ($free === '') continue;   // שורה ריקה בטופס אינה רכיב

            $unit = in_array($ing['unit'] ?? '', UNITS, true) ? $ing['unit'] : null;
            $min  = isset($ing['amount_min']) && $ing['amount_min'] !== ''
                    ? (float) $ing['amount_min'] : null;
            $max  = isset($ing['amount_max']) && $ing['amount_max'] !== ''
                    ? (float) $ing['amount_max'] : null;
            // כמות בלי יחידה אינה ניתנת לחישוב, ולכן היא נשמטת לשכבה
            // החופשית בלבד — במקום להישמר כמספר חסר משמעות.
            if ($unit === null) { $min = null; $max = null; }

            $ingSt->execute([
                $sectionId, $free, $min, $max, $unit,
                productId($ing['product'] ?? null),
                !empty($ing['optional']) ? 1 : 0,
                $iIndex + 1,
            ]);
        }

        foreach (array_values($section['steps'] ?? []) as $stepIndex => $step) {
            $text = trim((string) (is_array($step) ? ($step['text'] ?? '') : $step));
            if ($text === '') continue;
            $stepSt->execute([$sectionId, $stepIndex + 1, $text]);
        }
    }
}

function writeTags(PDO $pdo, int $recipeId, $tagIds): void {
    if (!is_array($tagIds)) return;
    $st = $pdo->prepare('INSERT OR IGNORE INTO recipe_tags (recipe_id, tag_id) VALUES (?,?)');
    foreach ($tagIds as $tagId) {
        $id = (int) $tagId;
        if ($id > 0) $st->execute([$recipeId, $id]);
    }
}

/** מתכון מלא לתצוגה. מחזיר null כשאין הרשאה — הקורא מחליט מה לומר. */
function loadRecipe(int $recipeId, ?array $user): ?array {
    $st = db()->prepare('SELECT r.*, u.display_name AS owner_name
                           FROM recipes r JOIN users u ON u.id = r.owner_id
                          WHERE r.id = ?');
    $st->execute([$recipeId]);
    $recipe = $st->fetch();
    if (!$recipe || !canView($recipe, $user)) return null;

    $st = db()->prepare('SELECT id, name, position FROM sections
                          WHERE recipe_id = ? ORDER BY position');
    $st->execute([$recipeId]);
    $sections = $st->fetchAll();

    $ingSt  = db()->prepare('SELECT i.*, p.name AS product FROM ingredients i
                              LEFT JOIN products p ON p.id = i.product_id
                              WHERE i.section_id = ? ORDER BY i.position');
    $stepSt = db()->prepare('SELECT id, position, text FROM steps
                              WHERE section_id = ? ORDER BY position');

    foreach ($sections as &$section) {
        $ingSt->execute([$section['id']]);
        $section['ingredients'] = array_map(fn($row) => [
            'free_text'  => $row['free_text'],
            'amount_min' => $row['amount_min'] !== null ? (float) $row['amount_min'] : null,
            'amount_max' => $row['amount_max'] !== null ? (float) $row['amount_max'] : null,
            'unit'       => $row['unit'],
            'product'    => $row['product'],
            'optional'   => (bool) $row['optional'],
        ], $ingSt->fetchAll());

        $stepSt->execute([$section['id']]);
        $section['steps'] = array_map(fn($row) => [
            'id'       => (int) $row['id'],
            'position' => (int) $row['position'],
            'text'     => $row['text'],
        ], $stepSt->fetchAll());
    }
    unset($section);

    $st = db()->prepare('SELECT t.id, t.axis, t.name FROM recipe_tags rt
                           JOIN tags t ON t.id = rt.tag_id WHERE rt.recipe_id = ?');
    $st->execute([$recipeId]);

    return [
        'id'            => (int) $recipe['id'],
        'title'         => $recipe['title'],
        'visibility'    => $recipe['visibility'],
        'owner_id'      => (int) $recipe['owner_id'],
        'owner_name'    => $recipe['owner_name'],
        'is_mine'       => $user && (int) $recipe['owner_id'] === (int) $user['id'],
        'servings'      => $recipe['servings'] !== null ? (int) $recipe['servings'] : null,
        'difficulty'    => $recipe['difficulty'],
        'work_minutes'  => $recipe['work_minutes'] !== null ? (int) $recipe['work_minutes'] : null,
        'wait_minutes'  => $recipe['wait_minutes'] !== null ? (int) $recipe['wait_minutes'] : null,
        'tips'          => $recipe['tips'],
        'comments_open' => (bool) $recipe['comments_open'],
        'created_at'    => $recipe['created_at'],
        'updated_at'    => $recipe['updated_at'],
        'sections'      => array_map(fn($s) => [
            'name'        => $s['name'],
            'ingredients' => $s['ingredients'],
            'steps'       => $s['steps'],
        ], $sections),
        'tags'          => $st->fetchAll(),
        'media'         => mediaForRecipe($recipeId),
        'main_media_id' => $recipe['main_media_id'] !== null ? (int) $recipe['main_media_id'] : null,
    ];
}

/**
 * חיפוש (5): תיבה אחת שמחזירה שלי וציבורי יחד, עם סימון לכל תוצאה.
 * הדירוג — התאמה בשם לפני התאמה בשאר הטקסט.
 */
function searchRecipes(?array $user, string $query = '', array $filters = []): array {
    $where  = ['(r.visibility = \'public\'' . ($user ? ' OR r.owner_id = ?' : '') . ')'];
    $params = $user ? [$user['id']] : [];

    $q = normalizeText($query);
    if ($q !== '') {
        $where[]  = '(r.search_text LIKE ? OR ' . 'lower(r.title) LIKE ?)';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }
    if (!empty($filters['difficulty']) && in_array($filters['difficulty'], DIFFICULTIES, true)) {
        $where[]  = 'r.difficulty = ?';
        $params[] = $filters['difficulty'];
    }
    if (!empty($filters['max_minutes'])) {
        $where[]  = '(COALESCE(r.work_minutes,0) + COALESCE(r.wait_minutes,0)) <= ?';
        $params[] = (int) $filters['max_minutes'];
    }
    if (!empty($filters['tag_ids']) && is_array($filters['tag_ids'])) {
        $ids = array_values(array_filter(array_map('intval', $filters['tag_ids'])));
        if ($ids) {
            // AND בין תגים: מי שסינן "עוגות" וגם "פרווה" מצפה לשניהם.
            $where[] = '(SELECT COUNT(DISTINCT tag_id) FROM recipe_tags
                          WHERE recipe_id = r.id AND tag_id IN ('
                     . implode(',', array_fill(0, count($ids), '?')) . ')) = ' . count($ids);
            $params = array_merge($params, $ids);
        }
    }

    // ה-CASE של הדירוג יושב ב-SELECT, כלומר לפני ה-WHERE בטקסט השאילתה —
    // ו-PDO קושר סימני שאלה לפי סדר הופעתם. לכן הפרמטר שלו חייב להיות
    // הראשון ברשימה, לא האחרון. זה נתפס בבדיקה: מלי לא מצאה את המתכון
    // הפרטי של עצמה, כי מזהה המשתמש נקשר ל-LIKE של הדירוג.
    $titleMatch = $q !== '' ? 'CASE WHEN lower(r.title) LIKE ? THEN 0 ELSE 1 END' : '0';
    if ($q !== '') array_unshift($params, '%' . $q . '%');

    $sql = 'SELECT r.id, r.title, r.visibility, r.owner_id, r.difficulty, r.servings,
                   r.work_minutes, r.wait_minutes, r.updated_at, u.display_name AS owner_name,
                   m.path_or_url AS main_path,
                   ' . $titleMatch . ' AS rank_title
              FROM recipes r JOIN users u ON u.id = r.owner_id
              LEFT JOIN media m ON m.id = r.main_media_id AND m.source = \'upload\'
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY rank_title, r.updated_at DESC
             LIMIT 100';

    $st = db()->prepare($sql);
    $st->execute($params);

    $uid = $user ? (int) $user['id'] : 0;
    return array_map(fn($row) => [
        'id'           => (int) $row['id'],
        'title'        => $row['title'],
        'visibility'   => $row['visibility'],
        'is_mine'      => (int) $row['owner_id'] === $uid,
        'owner_name'   => $row['owner_name'],
        'difficulty'   => $row['difficulty'],
        'servings'     => $row['servings'] !== null ? (int) $row['servings'] : null,
        'work_minutes' => $row['work_minutes'] !== null ? (int) $row['work_minutes'] : null,
        'wait_minutes' => $row['wait_minutes'] !== null ? (int) $row['wait_minutes'] : null,
        'updated_at'   => $row['updated_at'],
        'thumb'        => $row['main_path'] ? 'data/media/' . basename($row['main_path']) : null,
    ], $st->fetchAll());
}

function deleteRecipe(int $recipeId, array $user): int {
    $recipe = db()->prepare('SELECT * FROM recipes WHERE id = ?');
    $recipe->execute([$recipeId]);
    $row = $recipe->fetch();
    if (!$row) throw new AppError('המתכון אינו קיים', 404);

    $isOwner = (int) $row['owner_id'] === (int) $user['id'];
    $isAdminOnPublic = $user['role'] === 'admin' && $row['visibility'] === 'public';
    if (!$isOwner && !$isAdminOnPublic) throw new AppError('אין לך הרשאה למחוק את המתכון', 403);

    // כמה תגובות יימחקו — המספר נמסר לממשק כדי שהאישור יאמר את האמת (6).
    $st = db()->prepare('SELECT COUNT(*) c FROM comments WHERE recipe_id = ?');
    $st->execute([$recipeId]);
    $comments = (int) $st->fetch()['c'];

    $st = db()->prepare('DELETE FROM recipes WHERE id = ?');
    $st->execute([$recipeId]);
    return $comments;
}
