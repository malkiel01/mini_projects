<?php
/**
 * רשימת קניות ומצב בישול — סעיף 8 באפיון.
 *
 * רשימת קניות: בוחרים מתכונים, ומקבלים רשימה אחת מאוחדת שנשמרת. האיחוד
 * נעשה על **השכבה המחושבת** של הרכיב (כמות + יחידה + מוצר): "2 כוסות קמח"
 * שנרשם גם כ-300 גרם ו"קמח 200 גרם" ממתכון אחר מתחברים ל-500 גרם. מה
 * שאינו ניתן לחיבור — יחידות שונות של אותו מוצר, או רכיב בלי שכבה
 * מחושבת — נשאר שורות נפרדות תחת אותו מוצר. רשימה שמודה שאינה יודעת
 * עדיפה על רשימה שטועה בביטחון.
 *
 * השורות נשמרות (לא מחושבות בכל פתיחה): המשתמש מסמן מה נקנה, ומתכון
 * שנערך בינתיים אינו משנה רשימה שהוא כבר בסופר איתה. הוספת מתכון לרשימה
 * קיימת בונה מחדש את השורות המחושבות ושומרת את הסימונים ואת השורות הידניות.
 *
 * מצב בישול: "בוצע" לכל שלב הוא מצב ריצה של המשתמש (cooking_progress),
 * לא נתון של המתכון.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/recipes.php';

const SHOPPING_MAX_LISTS = 30;
const SHOPPING_MAX_RECIPES_PER_LIST = 20;

// ─────────────────────────────────────────────────────────────
// איחוד רכיבים
// ─────────────────────────────────────────────────────────────

/** מפתח קיבוץ: המוצר מהקטלוג, ואם אין — הטקסט החופשי מנורמל. */
function shoppingKey(array $ing): string {
    if (!empty($ing['product_id'])) return 'p:' . (int) $ing['product_id'];
    $name = trim((string) ($ing['product'] ?? ''));
    return 'f:' . normalizeText($name !== '' ? $name : (string) $ing['free_text']);
}

/**
 * רכיבים (מכמה מתכונים, כל אחד עם factor להמרת מנות) → שורות הרשימה.
 * רכיב: product_id, product, free_text, amount_min, amount_max, unit, optional, recipe_id.
 */
function shoppingMerge(array $ingredients): array {
    $groups = [];
    foreach ($ingredients as $ing) {
        $key = shoppingKey($ing);
        $g = &$groups[$key];
        if ($g === null) {
            $g = ['label' => trim((string) ($ing['product'] ?? '')) ?: trim((string) $ing['free_text']),
                  'product_id' => $ing['product_id'] ?? null, 'units' => [], 'free' => [], 'recipes' => [], 'optional' => true];
        }
        $factor = (float) ($ing['factor'] ?? 1);
        $g['recipes'][(int) ($ing['recipe_id'] ?? 0)] = true;
        if (empty($ing['optional'])) $g['optional'] = false;
        if ($ing['amount_min'] !== null && !empty($ing['unit'])) {
            $u = &$g['units'][$ing['unit']];
            if ($u === null) $u = ['min' => 0.0, 'max' => 0.0, 'has_max' => false];
            $u['min'] += (float) $ing['amount_min'] * $factor;
            $u['max'] += (float) ($ing['amount_max'] ?? $ing['amount_min']) * $factor;
            if ($ing['amount_max'] !== null) $u['has_max'] = true;
            unset($u);
        } else {
            $g['free'][] = trim((string) $ing['free_text']) . ($factor != 1.0 ? ' ×' . rtrim(rtrim(number_format($factor, 2, '.', ''), '0'), '.') : '');
        }
        unset($g);
    }

    $rows = [];
    foreach ($groups as $g) {
        $base = ['label' => $g['label'], 'product_id' => $g['product_id'], 'optional' => $g['optional'],
                 'recipe_count' => count($g['recipes'])];
        foreach ($g['units'] as $unit => $u) {
            $rows[] = $base + ['amount' => round($u['min'], 3), 'amount_max' => $u['has_max'] ? round($u['max'], 3) : null,
                               'unit' => $unit, 'free_text' => null];
        }
        if ($g['free']) {
            $rows[] = $base + ['amount' => null, 'amount_max' => null, 'unit' => null, 'free_text' => implode(' + ', $g['free'])];
        }
    }
    // מיון: לפי שם, ובתוך שם — שורות עם כמות לפני טקסט חופשי
    usort($rows, fn($a, $b) => [$a['optional'], $a['label'], $a['unit'] === null] <=> [$b['optional'], $b['label'], $b['unit'] === null]);
    return $rows;
}

/** הרכיבים של מתכונים נתונים (עם factor לכל אחד), רק מה שהמשתמש רואה. */
function shoppingIngredientsOf(array $recipeFactors, array $user): array {
    $out = [];
    $st = db()->prepare('SELECT i.free_text, i.amount_min, i.amount_max, i.unit, i.product_id, i.optional, p.name AS product
                           FROM ingredients i JOIN sections s ON s.id = i.section_id
                           LEFT JOIN products p ON p.id = i.product_id
                          WHERE s.recipe_id = ? ORDER BY s.position, i.position');
    foreach ($recipeFactors as $rid => $factor) {
        $r = db()->prepare('SELECT * FROM recipes WHERE id = ?'); $r->execute([(int) $rid]);
        $row = $r->fetch();
        if (!$row || !canView($row, $user)) continue;
        $st->execute([(int) $rid]);
        foreach ($st->fetchAll() as $ing) $out[] = $ing + ['recipe_id' => (int) $rid, 'factor' => max(0.1, min(20, (float) $factor))];
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────
// רשימות
// ─────────────────────────────────────────────────────────────

function shoppingLists(array $user): array {
    $st = db()->prepare('SELECT l.*, (SELECT COUNT(*) FROM shopping_list_items i WHERE i.list_id = l.id) AS items,
                                (SELECT COUNT(*) FROM shopping_list_items i WHERE i.list_id = l.id AND i.checked = 1) AS checked,
                                (SELECT COUNT(*) FROM shopping_list_recipes r WHERE r.list_id = l.id) AS recipes
                           FROM shopping_lists l WHERE l.user_id = ? ORDER BY l.id DESC');
    $st->execute([$user['id']]);
    return array_map(fn($l) => ['id' => (int) $l['id'], 'name' => $l['name'], 'created_at' => $l['created_at'],
        'items' => (int) $l['items'], 'checked' => (int) $l['checked'], 'recipes' => (int) $l['recipes']], $st->fetchAll());
}

function shoppingOwnList(int $listId, array $user): array {
    $st = db()->prepare('SELECT * FROM shopping_lists WHERE id = ? AND user_id = ?');
    $st->execute([$listId, $user['id']]);
    $l = $st->fetch();
    if (!$l) throw new AppError('הרשימה אינה קיימת', 404);
    return $l;
}

/** רשימה חדשה ממתכונים. $recipes: [recipe_id => factor]. */
function shoppingCreate(array $recipes, ?string $name, array $user): array {
    $count = (int) db()->query('SELECT COUNT(*) FROM shopping_lists WHERE user_id = ' . (int) $user['id'])->fetchColumn();
    if ($count >= SHOPPING_MAX_LISTS) throw new AppError('יותר מדי רשימות (עד ' . SHOPPING_MAX_LISTS . '). אפשר למחוק ישנות');
    $name = mb_substr(trim((string) $name), 0, 60);
    db()->prepare('INSERT INTO shopping_lists (user_id, name, created_at) VALUES (?,?,?)')
        ->execute([$user['id'], $name !== '' ? $name : null, nowIso()]);
    $id = (int) db()->lastInsertId();
    if ($recipes) shoppingAddRecipes($id, $recipes, $user);
    return shoppingGet($id, $user);
}

/** מוסיף מתכונים לרשימה ובונה מחדש את השורות המחושבות; סימונים ושורות ידניות נשמרים. */
function shoppingAddRecipes(int $listId, array $recipes, array $user): array {
    shoppingOwnList($listId, $user);
    $pdo = db();
    $ins = $pdo->prepare('INSERT OR REPLACE INTO shopping_list_recipes (list_id, recipe_id, factor) VALUES (?,?,?)');
    foreach ($recipes as $rid => $factor) {
        $rid = (int) $rid;
        $r = $pdo->prepare('SELECT * FROM recipes WHERE id = ?'); $r->execute([$rid]);
        $row = $r->fetch();
        if (!$row || !canView($row, $user)) throw new AppError('המתכון אינו קיים או שאינו זמין לך', 404);
        $ins->execute([$listId, $rid, max(0.1, min(20, (float) $factor))]);
    }
    $n = (int) $pdo->query("SELECT COUNT(*) FROM shopping_list_recipes WHERE list_id = $listId")->fetchColumn();
    if ($n > SHOPPING_MAX_RECIPES_PER_LIST) throw new AppError('עד ' . SHOPPING_MAX_RECIPES_PER_LIST . ' מתכונים ברשימה');
    shoppingRebuild($listId, $user);
    return shoppingGet($listId, $user);
}

function shoppingRemoveRecipe(int $listId, int $recipeId, array $user): array {
    shoppingOwnList($listId, $user);
    db()->prepare('DELETE FROM shopping_list_recipes WHERE list_id = ? AND recipe_id = ?')->execute([$listId, $recipeId]);
    shoppingRebuild($listId, $user);
    return shoppingGet($listId, $user);
}

/** בנייה מחדש של השורות המחושבות. מפתח לשימור סימון: label + unit. */
function shoppingRebuild(int $listId, array $user): void {
    $pdo = db();
    $st = $pdo->prepare('SELECT recipe_id, factor FROM shopping_list_recipes WHERE list_id = ? AND recipe_id IS NOT NULL');
    $st->execute([$listId]);
    $factors = [];
    foreach ($st->fetchAll() as $r) $factors[(int) $r['recipe_id']] = (float) $r['factor'];

    $old = $pdo->prepare('SELECT label, unit, checked FROM shopping_list_items WHERE list_id = ? AND manual = 0');
    $old->execute([$listId]);
    $checked = [];
    foreach ($old->fetchAll() as $o) if ((int) $o['checked'] === 1) $checked[$o['label'] . '|' . ($o['unit'] ?? '')] = true;

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM shopping_list_items WHERE list_id = ? AND manual = 0')->execute([$listId]);
        $rows = shoppingMerge(shoppingIngredientsOf($factors, $user));
        $ins = $pdo->prepare('INSERT INTO shopping_list_items (list_id, product_id, label, amount, amount_max, unit, free_text,
                                                              optional, manual, checked, recipe_count, position)
                              VALUES (?,?,?,?,?,?,?,?,0,?,?,?)');
        foreach ($rows as $i => $row) {
            $ins->execute([$listId, $row['product_id'], $row['label'], $row['amount'], $row['amount_max'], $row['unit'],
                           $row['free_text'], $row['optional'] ? 1 : 0, isset($checked[$row['label'] . '|' . ($row['unit'] ?? '')]) ? 1 : 0,
                           $row['recipe_count'], $i]);
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

function shoppingGet(int $listId, array $user): array {
    $l = shoppingOwnList($listId, $user);
    $st = db()->prepare('SELECT r.recipe_id, r.factor, rc.title FROM shopping_list_recipes r
                           LEFT JOIN recipes rc ON rc.id = r.recipe_id WHERE r.list_id = ?');
    $st->execute([$listId]);
    $recipes = array_map(fn($r) => ['id' => $r['recipe_id'] !== null ? (int) $r['recipe_id'] : null,
        'title' => $r['title'] ?? '(מתכון שנמחק)', 'factor' => (float) $r['factor']], $st->fetchAll());
    $st = db()->prepare('SELECT * FROM shopping_list_items WHERE list_id = ? ORDER BY checked, manual, position, id');
    $st->execute([$listId]);
    $items = array_map(fn($i) => [
        'id' => (int) $i['id'], 'label' => $i['label'],
        'amount' => $i['amount'] !== null ? (float) $i['amount'] : null,
        'amount_max' => $i['amount_max'] !== null ? (float) $i['amount_max'] : null,
        'unit' => $i['unit'], 'free_text' => $i['free_text'],
        'optional' => (bool) $i['optional'], 'manual' => (bool) $i['manual'], 'checked' => (bool) $i['checked'],
        'recipe_count' => (int) $i['recipe_count'],
    ], $st->fetchAll());
    return ['id' => (int) $l['id'], 'name' => $l['name'], 'created_at' => $l['created_at'], 'recipes' => $recipes, 'items' => $items];
}

function shoppingOwnItem(int $itemId, array $user): array {
    $st = db()->prepare('SELECT i.* FROM shopping_list_items i JOIN shopping_lists l ON l.id = i.list_id WHERE i.id = ? AND l.user_id = ?');
    $st->execute([$itemId, $user['id']]);
    $i = $st->fetch();
    if (!$i) throw new AppError('השורה אינה קיימת', 404);
    return $i;
}

function shoppingCheck(int $itemId, bool $checked, array $user): void {
    $i = shoppingOwnItem($itemId, $user);
    db()->prepare('UPDATE shopping_list_items SET checked = ? WHERE id = ?')->execute([$checked ? 1 : 0, $i['id']]);
}

function shoppingAddManual(int $listId, string $label, array $user): array {
    shoppingOwnList($listId, $user);
    $label = mb_substr(trim($label), 0, 80);
    if ($label === '') throw new AppError('השורה ריקה');
    $pos = (int) db()->query("SELECT COALESCE(MAX(position),0)+1 FROM shopping_list_items WHERE list_id = $listId")->fetchColumn();
    db()->prepare('INSERT INTO shopping_list_items (list_id, label, manual, position) VALUES (?,?,1,?)')->execute([$listId, $label, $pos]);
    return shoppingGet($listId, $user);
}

function shoppingRemoveItem(int $itemId, array $user): void {
    $i = shoppingOwnItem($itemId, $user);
    db()->prepare('DELETE FROM shopping_list_items WHERE id = ?')->execute([$i['id']]);
}

function shoppingClearChecked(int $listId, array $user): array {
    shoppingOwnList($listId, $user);
    // מחושב: מבטלים סימון (המוצר עדיין צריך להופיע ברשימה הבאה); ידני: נמחק
    db()->prepare('DELETE FROM shopping_list_items WHERE list_id = ? AND manual = 1 AND checked = 1')->execute([$listId]);
    db()->prepare('UPDATE shopping_list_items SET checked = 0 WHERE list_id = ?')->execute([$listId]);
    return shoppingGet($listId, $user);
}

function shoppingRename(int $listId, string $name, array $user): array {
    shoppingOwnList($listId, $user);
    $name = mb_substr(trim($name), 0, 60);
    db()->prepare('UPDATE shopping_lists SET name = ? WHERE id = ?')->execute([$name !== '' ? $name : null, $listId]);
    return shoppingGet($listId, $user);
}

function shoppingDelete(int $listId, array $user): void {
    shoppingOwnList($listId, $user);
    db()->prepare('DELETE FROM shopping_lists WHERE id = ?')->execute([$listId]);
}

/** הרשימה כטקסט — לשיתוף בוואטסאפ או להדבקה. */
function shoppingAsText(int $listId, array $user): string {
    $l = shoppingGet($listId, $user);
    $units = ['gram' => 'גרם', 'kg' => 'ק"ג', 'ml' => 'מ"ל', 'liter' => 'ליטר', 'cup' => 'כוס', 'tbsp' => 'כף', 'tsp' => 'כפית',
              'unit' => 'יח׳', 'package' => 'חבילה', 'pinch' => 'קורט'];
    $lines = ['🛒 ' . ($l['name'] ?: 'רשימת קניות')];
    foreach ($l['items'] as $i) {
        $qty = $i['amount'] !== null
            ? rtrim(rtrim(number_format($i['amount'], 2, '.', ''), '0'), '.') . ($i['amount_max'] !== null ? '–' . rtrim(rtrim(number_format($i['amount_max'], 2, '.', ''), '0'), '.') : '') . ' ' . ($units[$i['unit']] ?? $i['unit'])
            : ($i['free_text'] ?? '');
        $lines[] = ($i['checked'] ? '✅ ' : '◻️ ') . $i['label'] . ($qty !== '' ? ' — ' . $qty : '') . ($i['optional'] ? ' (לא חובה)' : '');
    }
    return implode("\n", $lines) . "\n";
}

// ─────────────────────────────────────────────────────────────
// מצב בישול
// ─────────────────────────────────────────────────────────────

function cookingProgress(int $recipeId, array $user): array {
    $st = db()->prepare('SELECT cp.step_id FROM cooking_progress cp
                           JOIN steps st ON st.id = cp.step_id JOIN sections s ON s.id = st.section_id
                          WHERE cp.user_id = ? AND s.recipe_id = ?');
    $st->execute([$user['id'], $recipeId]);
    return array_map('intval', array_column($st->fetchAll(), 'step_id'));
}

function cookingSetDone(int $stepId, bool $done, array $user): void {
    $st = db()->prepare('SELECT r.* FROM steps st JOIN sections s ON s.id = st.section_id JOIN recipes r ON r.id = s.recipe_id WHERE st.id = ?');
    $st->execute([$stepId]);
    $r = $st->fetch();
    if (!$r || !canView($r, $user)) throw new AppError('השלב אינו קיים', 404);
    if ($done) db()->prepare('INSERT OR REPLACE INTO cooking_progress (user_id, step_id, done_at) VALUES (?,?,?)')->execute([$user['id'], $stepId, nowIso()]);
    else db()->prepare('DELETE FROM cooking_progress WHERE user_id = ? AND step_id = ?')->execute([$user['id'], $stepId]);
}

function cookingReset(int $recipeId, array $user): void {
    db()->prepare('DELETE FROM cooking_progress WHERE user_id = ? AND step_id IN (
                     SELECT st.id FROM steps st JOIN sections s ON s.id = st.section_id WHERE s.recipe_id = ?)')
        ->execute([$user['id'], $recipeId]);
}
