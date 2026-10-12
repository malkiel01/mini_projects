<?php
/**
 * פעולות המנהל: אישור וחסימת משתמשים, הרשאות, קטגוריות ושיוך אפליקציות.
 *
 * כל פעולה משנה דורשת מנהל מחובר ואסימון CSRF תקין (require_admin(true)).
 * שתי הגנות מפני נעילה עצמית: מנהל לא יכול לחסום, למחוק או להוריד את עצמו,
 * ולא ניתן להשאיר את המערכת בלי מנהל פעיל.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/access.php';

function require_admin(bool $mutating = false): array
{
    $user = current_user();
    if (!$user) throw new ApiError('נדרשת כניסה', 401);
    if ($user['role'] !== 'admin') throw new ApiError('למנהל בלבד', 403);
    if ($mutating && !hash_equals($user['csrf'], $_SERVER['HTTP_X_CSRF'] ?? '')) {
        throw new ApiError('בקשה לא תקינה — רעננו את הדף', 403);
    }
    return $user;
}

function admin_state(array $admin): never
{
    $users = db()->query(
        "SELECT id, username, display_name, role, status, created_at, last_login_at FROM users
          ORDER BY CASE status WHEN 'pending' THEN 0 ELSE 1 END, created_at"
    )->fetchAll();
    foreach ($users as &$u) {
        $u['id'] = (int)$u['id'];
        $u['grants'] = grants_of($u['id']);
        $u['self'] = $u['id'] === (int)$admin['id'];
    }
    unset($u);

    json_out([
        'users'      => $users,
        'categories' => categories_list(),
        'apps'       => array_map(fn($a) => [
            'id' => $a['id'], 'name' => $a['name'], 'icon' => $a['icon'],
            'category' => $a['category'], 'default_category' => $a['default_category'],
            'hidden' => $a['hidden'], 'install_ok' => $a['install_ok'], 'restricted' => $a['restricted'],
            'can_install' => $a['install']['pwa'] || $a['install']['apk'] || $a['install']['store'],
        ], apps_effective()),
    ]);
}

function admin_dispatch(string $action, array $body, array $admin): never
{
    match ($action) {
        'admin_user_update'     => admin_user_update($body, $admin),
        'admin_user_delete'     => admin_user_delete($body, $admin),
        'admin_grants_set'      => admin_grants_set($body),
        'admin_category_save'   => admin_category_save($body),
        'admin_category_move'   => admin_category_move($body),
        'admin_category_delete' => admin_category_delete($body),
        'admin_app_update'      => admin_app_update($body),
        default => throw new ApiError('פעולה לא מוכרת', 404),
    };
}

// ---------- משתמשים ----------

function target_user(array $body): array
{
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([(int)($body['id'] ?? 0)]);
    $u = $st->fetch();
    if (!$u) throw new ApiError('המשתמש לא נמצא', 404);
    return $u;
}

function active_admins(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn();
}

/** {id, status?: active|blocked, role?: admin|user} */
function admin_user_update(array $body, array $admin): never
{
    $u = target_user($body);
    $status = $body['status'] ?? $u['status'];
    $role   = $body['role'] ?? $u['role'];
    if (!in_array($status, ['active', 'blocked'], true) && $status !== $u['status']) throw new ApiError('מצב לא תקין', 422);
    if (!in_array($role, ['admin', 'user'], true)) throw new ApiError('תפקיד לא תקין', 422);

    if ((int)$u['id'] === (int)$admin['id'] && ($status !== 'active' || $role !== 'admin')) {
        throw new ApiError('אי אפשר לחסום או להוריד את עצמך', 409);
    }
    $wasActiveAdmin = $u['role'] === 'admin' && $u['status'] === 'active';
    $staysActiveAdmin = $role === 'admin' && $status === 'active';
    if ($wasActiveAdmin && !$staysActiveAdmin && active_admins() <= 1) {
        throw new ApiError('חייב להישאר לפחות מנהל פעיל אחד', 409);
    }

    db()->prepare('UPDATE users SET status = ?, role = ? WHERE id = ?')->execute([$status, $role, $u['id']]);
    // חסימה מנתקת מיד, בכל המכשירים.
    if ($status === 'blocked') db()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$u['id']]);
    json_out(['ok' => true]);
}

function admin_user_delete(array $body, array $admin): never
{
    $u = target_user($body);
    if ((int)$u['id'] === (int)$admin['id']) throw new ApiError('אי אפשר למחוק את עצמך', 409);
    if ($u['role'] === 'admin' && $u['status'] === 'active' && active_admins() <= 1) {
        throw new ApiError('חייב להישאר לפחות מנהל פעיל אחד', 409);
    }
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$u['id']]); // sessions ו-grants נמחקים ב-CASCADE
    json_out(['ok' => true]);
}

/**
 * מחליף את כל ההרשאות של משתמש.
 * {id, categories: [catId…], apps: {appId: true|false}} — אפליקציה שלא מופיעה ב-apps
 * הולכת לפי הקטגוריה שלה.
 */
function admin_grants_set(array $body): never
{
    $u = target_user($body);
    $catIds = array_column(categories_list(), 'id');
    $appIds = array_column(apps_effective(), 'id');

    $cats = array_values(array_unique(array_filter((array)($body['categories'] ?? []),
        fn($c) => is_string($c) && in_array($c, $catIds, true))));
    $apps = [];
    foreach ((array)($body['apps'] ?? []) as $id => $allowed) {
        if (in_array($id, $appIds, true) && is_bool($allowed)) $apps[$id] = $allowed;
    }

    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM grants WHERE user_id = ?')->execute([$u['id']]);
    $ins = $pdo->prepare('INSERT INTO grants (user_id, kind, target, allowed) VALUES (?,?,?,?)');
    foreach ($cats as $c) $ins->execute([$u['id'], 'category', $c, 1]);
    foreach ($apps as $id => $allowed) $ins->execute([$u['id'], 'app', $id, $allowed ? 1 : 0]);
    $pdo->commit();
    json_out(['ok' => true]);
}

// ---------- קטגוריות ----------

/** {id?, name, icon} — בלי id: קטגוריה חדשה בסוף. */
function admin_category_save(array $body): never
{
    $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
    $icon = is_string($body['icon'] ?? null) ? trim($body['icon']) : '';
    if ($name === '' || mb_strlen($name) > 40) throw new ApiError('שם קטגוריה: 1–40 תווים', 422);
    if ($icon === '') $icon = '📁';
    if (mb_strlen($icon) > 8) throw new ApiError('אייקון: אימוג׳י אחד', 422);

    $id = $body['id'] ?? null;
    if ($id === null) {
        $id = 'c-' . bin2hex(random_bytes(4));
        $sort = 1 + (int)db()->query('SELECT COALESCE(MAX(sort), 0) FROM categories')->fetchColumn();
        db()->prepare('INSERT INTO categories (id, name, icon, sort) VALUES (?,?,?,?)')->execute([$id, $name, $icon, $sort]);
    } else {
        $st = db()->prepare('UPDATE categories SET name = ?, icon = ? WHERE id = ?');
        $st->execute([$name, $icon, $id]);
        if (!$st->rowCount()) throw new ApiError('הקטגוריה לא נמצאה', 404);
    }
    json_out(['ok' => true, 'id' => $id]);
}

/** {id, dir: -1|1} — הזזה של מקום אחד ברשימה. */
function admin_category_move(array $body): never
{
    $ids = array_column(categories_list(), 'id');
    $i = array_search($body['id'] ?? null, $ids, true);
    $j = $i === false ? false : $i + (($body['dir'] ?? 0) < 0 ? -1 : 1);
    if ($i === false || $j < 0 || $j >= count($ids)) throw new ApiError('אי אפשר להזיז', 422);
    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];

    $st = db()->prepare('UPDATE categories SET sort = ? WHERE id = ?');
    foreach ($ids as $n => $id) $st->execute([$n + 1, $id]);
    json_out(['ok' => true]);
}

/** {id} — רק קטגוריה ריקה. מוחק גם את ההרשאות אליה. */
function admin_category_delete(array $body): never
{
    $id = $body['id'] ?? null;
    $inUse = array_filter(apps_effective(), fn($a) => $a['category'] === $id);
    if ($inUse) throw new ApiError('יש בקטגוריה אפליקציות — העבירו אותן קודם', 409);
    $st = db()->prepare('DELETE FROM categories WHERE id = ?');
    $st->execute([$id]);
    if (!$st->rowCount()) throw new ApiError('הקטגוריה לא נמצאה', 404);
    db()->prepare("DELETE FROM grants WHERE kind = 'category' AND target = ?")->execute([$id]);
    json_out(['ok' => true]);
}

// ---------- אפליקציות ----------

/** {id, category?, hidden?, install_ok?} */
function admin_app_update(array $body): never
{
    $apps = array_column(apps_effective(), null, 'id');
    $app = $apps[$body['id'] ?? ''] ?? null;
    if (!$app) throw new ApiError('האפליקציה לא נמצאה', 404);

    $category = $body['category'] ?? $app['category'];
    if (!in_array($category, array_column(categories_list(), 'id'), true)) throw new ApiError('קטגוריה לא מוכרת', 422);
    $hidden = (bool)($body['hidden'] ?? $app['hidden']);
    if (isset($body['install_ok'])) {
        db()->prepare($body['install_ok'] ? 'INSERT OR IGNORE INTO install_approvals (app_id) VALUES (?)'
                                          : 'DELETE FROM install_approvals WHERE app_id = ?')->execute([$app['id']]);
    }

    // קטגוריה זהה לברירת המחדל של app.json לא נשמרת — כך שינוי עתידי ב-app.json ייכנס.
    db()->prepare('INSERT INTO app_overrides (app_id, category, hidden) VALUES (?,?,?)
                   ON CONFLICT(app_id) DO UPDATE SET category = excluded.category, hidden = excluded.hidden')
        ->execute([$app['id'], $category === $app['default_category'] ? null : $category, $hidden ? 1 : 0]);
    json_out(['ok' => true]);
}
