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
require_once __DIR__ . '/push.php';
require_once __DIR__ . '/sso.php';
require_once __DIR__ . '/installs.php';

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
        "SELECT id, username, display_name, role, status, created_at, last_login_at, must_change_pw FROM users
          ORDER BY CASE status WHEN 'pending' THEN 0 ELSE 1 END, created_at"
    )->fetchAll();
    foreach ($users as &$u) {
        $u['id'] = (int)$u['id'];
        $u['grants'] = grants_of($u['id']);
        $u['self'] = $u['id'] === (int)$admin['id'];
        $u['must_change'] = (bool)$u['must_change_pw'];
        $u['sso'] = sso_consents_of($u['id']);
        $u['devices'] = installs_devices_of($u['id']);
        unset($u['must_change_pw']);
    }
    unset($u);

    json_out([
        'users'      => $users,
        'sso'        => sso_clients_state(),
        'categories' => categories_list(),
        'apps'       => array_map(fn($a) => [
            'id' => $a['id'], 'name' => $a['name'], 'icon' => $a['icon'],
            'category' => $a['category'], 'default_category' => $a['default_category'],
            'hidden' => $a['hidden'], 'install_ok' => $a['install_ok'], 'restricted' => $a['restricted'],
            'gate' => (bool)$a['gate'], 'external' => $a['external'], 'detached' => $a['detached'], 'connected' => $a['connected'],
            'can_install' => $a['install']['pwa'] || $a['install']['apk'] || $a['install']['store'],
        ], apps_effective()),
    ]);
}

function admin_dispatch(string $action, array $body, array $admin): never
{
    match ($action) {
        'admin_user_update'     => admin_user_update($body, $admin),
        'admin_user_delete'     => admin_user_delete($body, $admin),
        'admin_user_password'   => admin_user_password($body, $admin),
        'admin_grants_set'      => admin_grants_set($body),
        'admin_category_save'   => admin_category_save($body),
        'admin_category_move'   => admin_category_move($body),
        'admin_category_delete' => admin_category_delete($body),
        'admin_app_update'      => admin_app_update($body),
        'admin_push_subscribe'  => admin_push_subscribe($body, $admin),
        'admin_push_unsubscribe'=> admin_push_unsubscribe($body, $admin),
        'admin_push_test'       => admin_push_test($admin, $body),
        'admin_sso_toggle'      => admin_sso_toggle($body),
        'admin_sso_revoke'      => admin_sso_revoke($body),
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

/**
 * איפוס סיסמה: {id, password} — סיסמה זמנית שהמנהל מוסר למשתמש. המשתמש מתנתק
 * מכל המכשירים, ובכניסה הבאה חייב לבחור סיסמה חדשה.
 */
function admin_user_password(array $body, array $admin): never
{
    $u = target_user($body);
    if ((int)$u['id'] === (int)$admin['id']) throw new ApiError('את הסיסמה שלך מחליפים מהתפריט האישי', 409);
    $password = validate_password($body['password'] ?? null);
    db()->prepare('UPDATE users SET password_hash = ?, must_change_pw = 1 WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    db()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$u['id']]);
    clear_failures(['u:' . mb_strtolower($u['username'])]); // נעילה מניסיונות קודמים לא תחסום את הסיסמה החדשה
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

/** {id, category?, hidden?, install_ok?, detached?} */
function admin_app_update(array $body): never
{
    $apps = array_column(apps_effective(), null, 'id');
    $app = $apps[$body['id'] ?? ''] ?? null;
    if (!$app) throw new ApiError('האפליקציה לא נמצאה', 404);

    $category = $body['category'] ?? $app['category'];
    if (!in_array($category, array_column(categories_list(), 'id'), true)) throw new ApiError('קטגוריה לא מוכרת', 422);
    $hidden = (bool)($body['hidden'] ?? $app['hidden']);
    if (isset($body['detached'])) {
        if (empty($app['gate'])) throw new ApiError('האפליקציה הזו כבר עומדת לבד — אין לה שער', 409);
        db()->prepare($body['detached'] ? 'INSERT OR IGNORE INTO app_detached (app_id, detached_at) VALUES (?, ?)'
                                        : 'DELETE FROM app_detached WHERE app_id = ? AND ? IS NOT NULL')
            ->execute([$app['id'], now_iso()]);
    }
    if (isset($body['install_ok'])) {
        db()->prepare($body['install_ok'] ? 'DELETE FROM install_hidden WHERE app_id = ?'
                                          : 'INSERT OR IGNORE INTO install_hidden (app_id) VALUES (?)')->execute([$app['id']]);
    }

    // קטגוריה זהה לברירת המחדל של app.json לא נשמרת — כך שינוי עתידי ב-app.json ייכנס.
    db()->prepare('INSERT INTO app_overrides (app_id, category, hidden) VALUES (?,?,?)
                   ON CONFLICT(app_id) DO UPDATE SET category = excluded.category, hidden = excluded.hidden')
        ->execute([$app['id'], $category === $app['default_category'] ? null : $category, $hidden ? 1 : 0]);
    json_out(['ok' => true]);
}

// ---------- התראות (Web Push) ----------

/** המפתח הציבורי להרשמה, והמכשירים שכבר רשומים אצל המנהל הזה. */
function admin_push_state(array $admin): never
{
    json_out(['key' => push_vapid()['public'], 'devices' => push_devices((int)$admin['id'])]);
}

/** {subscription, device} */
function admin_push_subscribe(array $body, array $admin): never
{
    push_subscribe((int)$admin['id'], $body['subscription'] ?? null, $body['device'] ?? '');
    json_out(['ok' => true]);
}

/** {endpoint} */
function admin_push_unsubscribe(array $body, array $admin): never
{
    push_unsubscribe((int)$admin['id'], $body['endpoint'] ?? '');
    json_out(['ok' => true]);
}

/**
 * התראת בדיקה. delay (עד 60 שניות) — עונים מיד ושולחים אחר כך, כדי שאפשר יהיה לסגור את האפליקציה
 * ולראות שההתראה מגיעה גם כשהיא סגורה (כמו התראה אמיתית על הרשמה).
 */
function admin_push_test(array $admin, array $body): never
{
    $delay = max(0, min(60, (int)($body['delay'] ?? 0)));
    if ($delay > 0) {
        respond_then_continue(['scheduled' => $delay]);
        set_time_limit($delay + 60);
        sleep($delay);
    }
    [$sent, $failed] = push_send([
        'title' => '🔔 בדיקה — כלים דיגיטליים',
        'body'  => 'ההתראות עובדות. כך תדעו על כל הרשמה חדשה.',
        'tag'   => 'test',
        'url'   => '#admin',
    ], [(int)$admin['id']]);
    if ($delay > 0) exit;
    json_out(['sent' => $sent, 'failed' => $failed]);
}

// ---------- מעקב כניסות ----------
// הכניסות נרשמות בשער (lib/gate.php, record_visit). כאן רק קוראים ומסכמים.
// ימים נספרים לפי שעון ישראל, לא UTC — כניסה ב-01:00 שייכת ליום שלה.

const USAGE_TZ = 'Asia/Jerusalem';

/**
 * GET admin_usage&days=30                — סיכום: סך כניסות, משתמשים ייחודיים, גרף יומי, לכל אפליקציה
 * GET admin_usage&days=30&app=<id>       — אפליקציה אחת: גרף, כניסות אחרונות, מי נכנס
 * GET admin_usage&days=30&user=<id>      — משתמש אחד: לאילו אפליקציות נכנס
 */
function admin_usage(array $admin, array $q): never
{
    $days = in_array((int)($q['days'] ?? 30), [7, 30, 90, 365], true) ? (int)$q['days'] : 30;
    $tz = new DateTimeZone(USAGE_TZ);
    $from = (new DateTimeImmutable('today', $tz))->modify('-' . ($days - 1) . ' days');
    $since = $from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

    $where = 'v.at >= ?';
    $args = [$since];
    if (isset($q['app'])) { $where .= ' AND v.app = ?'; $args[] = (string)$q['app']; }
    if (isset($q['user'])) { $where .= ' AND v.user_id = ?'; $args[] = (int)$q['user']; }

    $st = db()->prepare("SELECT v.app, v.user_id, v.at, v.device, u.display_name, u.username
                           FROM visits v LEFT JOIN users u ON u.id = v.user_id
                          WHERE $where ORDER BY v.at DESC");
    $st->execute($args);
    $rows = $st->fetchAll();

    // גרף יומי: כל הימים בטווח, גם ימים בלי כניסות.
    $daily = [];
    for ($d = $from, $i = 0; $i < $days; $i++, $d = $d->modify('+1 day')) $daily[$d->format('Y-m-d')] = 0;
    $apps = [];
    $users = [];
    foreach ($rows as $r) {
        $day = (new DateTimeImmutable($r['at']))->setTimezone($tz)->format('Y-m-d');
        if (isset($daily[$day])) $daily[$day]++;
        $a = &$apps[$r['app']];
        $a ??= ['app' => $r['app'], 'n' => 0, 'users' => [], 'guests' => 0, 'last' => $r['at']];
        $a['n']++;
        if ($r['user_id'] === null) $a['guests']++; else $a['users'][$r['user_id']] = true;
        unset($a);
        if ($r['user_id'] !== null) {
            $u = &$users[$r['user_id']];
            $u ??= ['id' => (int)$r['user_id'], 'name' => $r['display_name'] ?? 'משתמש שנמחק', 'username' => $r['username'] ?? '', 'n' => 0, 'last' => $r['at']];
            $u['n']++;
            unset($u);
        }
    }
    $appList = array_values(array_map(fn($a) => [
        'app' => $a['app'], 'n' => $a['n'], 'users' => count($a['users']), 'guests' => $a['guests'], 'last' => $a['last'],
    ], $apps));
    usort($appList, fn($x, $y) => $y['n'] <=> $x['n']);
    $userList = array_values($users);
    usort($userList, fn($x, $y) => $y['n'] <=> $x['n']);

    $out = [
        'days'   => $days,
        'total'  => count($rows),
        'unique' => count($users),
        'guests' => count(array_filter($rows, fn($r) => $r['user_id'] === null)),
        'daily'  => array_map(fn($d, $n) => ['d' => $d, 'n' => $n], array_keys($daily), $daily),
        'apps'   => $appList,
        'users'  => array_slice($userList, 0, 50),
    ];
    if (isset($q['app'])) {
        $out['recent'] = array_map(fn($r) => [
            'at' => $r['at'], 'device' => $r['device'],
            'name' => $r['user_id'] === null ? null : ($r['display_name'] ?? 'משתמש שנמחק'),
            'user_id' => $r['user_id'] === null ? null : (int)$r['user_id'],
        ], array_slice($rows, 0, 50));
    }
    json_out($out);
}

// ---------- כניסה מאוחדת: הלקוחות וההסכמות ----------

/** {id, disabled} — כניסה מאוחדת לאפליקציה: פעילה / כבויה. לחיצה אחת. */
function admin_sso_toggle(array $body): never
{
    sso_set_disabled((string)($body['id'] ?? ''), !empty($body['disabled']));
    json_out(['ok' => true]);
}

/** {user_id, client_id} — ניתוק משתמש מאפליקציה. בכניסה הבאה יתבקש לאשר שוב. */
function admin_sso_revoke(array $body): never
{
    sso_revoke((int)($body['user_id'] ?? 0), (string)($body['client_id'] ?? ''), 'admin');
    json_out(['ok' => true]);
}
