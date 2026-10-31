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
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/invites.php';

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
        "SELECT id, username, display_name, role, status, created_at, last_login_at, must_change_pw, email, email_verified_at FROM users
          ORDER BY CASE status WHEN 'pending' THEN 0 ELSE 1 END, created_at"
    )->fetchAll();
    foreach ($users as &$u) {
        $u['id'] = (int)$u['id'];
        $u['grants'] = grants_of($u['id']);
        $u['self'] = $u['id'] === (int)$admin['id'];
        $u['must_change'] = (bool)$u['must_change_pw'];
        $u['sso'] = sso_consents_of($u['id']);
        $u['devices'] = installs_devices_of($u['id']);
        $u['email_verified'] = empty($u['email']) || !empty($u['email_verified_at']);
        unset($u['must_change_pw'], $u['email_verified_at']);
    }
    unset($u);

    json_out([
        'users'      => $users,
        'invites'    => invites_list(),
        'settings'   => admin_settings_state(),
        'sso'        => sso_clients_state(),
        'categories' => categories_list(),
        'groups'     => groups_list(),
        'apps'       => array_map(fn($a) => [
            'id' => $a['id'], 'name' => $a['name'], 'icon' => $a['icon'], 'icon_svg' => $a['icon_svg'] ?? null,
            'category' => $a['category'], 'default_category' => $a['default_category'], 'placements' => $a['placements'],
            'hidden' => $a['hidden'], 'install_ok' => $a['install_ok'], 'catalog' => $a['catalog'], 'restricted' => $a['restricted'], 'plugin' => $a['plugin'],
            'gate' => (bool)$a['gate'], 'external' => $a['external'], 'detached' => $a['detached'], 'connected' => $a['connected'],
            'login' => $a['login'],
            'can_install' => $a['install']['pwa'] || $a['install']['apk'] || $a['install']['store'],
            'description' => $a['description'], 'href' => $a['href'], 'links' => $a['links'],
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
        'admin_invite_create'   => admin_invite_create($body, $admin),
        'admin_invite_renew'    => admin_invite_renew($body),
        'admin_invite_revoke'   => admin_invite_revoke($body),
        'admin_category_save'   => admin_category_save($body),
        'admin_category_move'   => admin_category_move($body),
        'admin_category_delete' => admin_category_delete($body),
        'admin_app_update'      => admin_app_update($body),
        'admin_placements_set'  => admin_placements_set($body),
        'admin_group_save'      => admin_group_save($body),
        'admin_group_move'      => admin_group_move($body),
        'admin_group_delete'    => admin_group_delete($body),
        'admin_push_subscribe'  => admin_push_subscribe($body, $admin),
        'admin_push_unsubscribe'=> admin_push_unsubscribe($body, $admin),
        'admin_push_test'       => admin_push_test($admin, $body),
        'admin_settings'        => admin_settings($body),
        'admin_sso_toggle'      => admin_sso_toggle($body),
        'admin_sso_revoke'      => admin_sso_revoke($body),
        default => throw new ApiError('פעולה לא מוכרת', 404),
    };
}

// ---------- הגדרות ----------

/** require_approval: האם הרשמה חדשה (אחרי אימות המייל) ממתינה לאישור המנהל. */
function admin_settings(array $body): never
{
    if (array_key_exists('require_approval', $body)) {
        setting_set('require_approval', $body['require_approval'] ? '1' : '0');
    }
    // מומלצות: רשימת אפליקציות לפי הסדר (רק כאלה שקיימות)
    if (array_key_exists('featured', $body)) {
        $ids = array_column(apps_effective(), 'id');
        $f = array_values(array_unique(array_filter((array)$body['featured'], fn($x) => is_string($x) && in_array($x, $ids, true))));
        setting_set('featured', json_encode($f));
    }
    // הסל של מי שנרשם לבד — אותו מבנה כמו הרשאות משתמש
    if (array_key_exists('default_grants', $body)) {
        setting_set('default_grants', json_encode(grants_clean((array)$body['default_grants']), JSON_UNESCAPED_UNICODE));
    }
    json_out(['ok' => true, 'settings' => admin_settings_state()]);
}

function admin_settings_state(): array
{
    return ['require_approval' => approval_required(), 'featured' => featured_apps(), 'default_grants' => default_grants()];
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
    $pdo = db();
    $pdo->beginTransaction();
    grants_write((int)$u['id'], grants_clean($body), true);
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
    if (array_key_exists('icon_app', $body)) db()->prepare('UPDATE categories SET icon_app = ? WHERE id = ?')->execute([icon_app_value($body['icon_app']), $id]);
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
    $inUse = array_filter(apps_effective(), fn($a) => in_array($id, array_column($a['placements'], 'category'), true));
    if ($inUse) throw new ApiError('יש בקטגוריה אפליקציות — העבירו אותן קודם', 409);
    $st = db()->prepare('DELETE FROM categories WHERE id = ?');
    $st->execute([$id]);
    if (!$st->rowCount()) throw new ApiError('הקטגוריה לא נמצאה', 404);
    db()->prepare("DELETE FROM grants WHERE kind = 'category' AND target = ?")->execute([$id]);
    json_out(['ok' => true]);
}

/** אייקון מצויר לקטגוריה / קבוצה — מזהה של אפליקציה עם icon_svg, או ריק (רק האימוג'י). */
function icon_app_value($v): ?string
{
    if ($v === null || $v === '') return null;
    foreach (registry_load()['apps'] as $a) if ($a['id'] === $v && $a['icon_svg']) return $v;
    throw new ApiError('לאפליקציה הזו אין אייקון מצויר', 422);
}

// ---------- קבוצות ----------
// קבוצה — שורה אחת ברשימה שנפתחים ממנה הילדים (כמו חבילת היצירה). אותה קבוצה יכולה להופיע בכמה קטגוריות.

/** {id?, name, icon} — בלי id: קבוצה חדשה בסוף. */
function admin_group_save(array $body): never
{
    $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
    $icon = is_string($body['icon'] ?? null) ? trim($body['icon']) : '';
    if ($name === '' || mb_strlen($name) > 40) throw new ApiError('שם קבוצה: 1–40 תווים', 422);
    if ($icon === '') $icon = '🧰';
    if (mb_strlen($icon) > 8) throw new ApiError('אייקון: אימוג׳י אחד', 422);

    access_migrate();
    $id = $body['id'] ?? null;
    if ($id === null) {
        $id = 'g-' . bin2hex(random_bytes(4));
        $sort = 1 + (int)db()->query('SELECT COALESCE(MAX(sort), 0) FROM groups')->fetchColumn();
        db()->prepare('INSERT INTO groups (id, name, icon, sort) VALUES (?,?,?,?)')->execute([$id, $name, $icon, $sort]);
    } else {
        $st = db()->prepare('UPDATE groups SET name = ?, icon = ? WHERE id = ?');
        $st->execute([$name, $icon, $id]);
        if (!$st->rowCount()) throw new ApiError('הקבוצה לא נמצאה', 404);
    }
    if (array_key_exists('icon_app', $body)) db()->prepare('UPDATE groups SET icon_app = ? WHERE id = ?')->execute([icon_app_value($body['icon_app']), $id]);
    json_out(['ok' => true, 'id' => $id]);
}

/** {id, dir: -1|1} */
function admin_group_move(array $body): never
{
    $ids = array_column(groups_list(), 'id');
    $i = array_search($body['id'] ?? null, $ids, true);
    $j = $i === false ? false : $i + (($body['dir'] ?? 0) < 0 ? -1 : 1);
    if ($i === false || $j < 0 || $j >= count($ids)) throw new ApiError('אי אפשר להזיז', 422);
    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
    $st = db()->prepare('UPDATE groups SET sort = ? WHERE id = ?');
    foreach ($ids as $n => $id) $st->execute([$n + 1, $id]);
    json_out(['ok' => true]);
}

/** {id} — האפליקציות שבה חוזרות לראשי של אותה קטגוריה (לא נעלמות). מוחק גם את ההרשאות אליה. */
function admin_group_delete(array $body): never
{
    $id = $body['id'] ?? null;
    access_migrate();
    $pdo = db();
    $pdo->beginTransaction();
    $st = $pdo->prepare('DELETE FROM groups WHERE id = ?');
    $st->execute([$id]);
    if (!$st->rowCount()) { $pdo->rollBack(); throw new ApiError('הקבוצה לא נמצאה', 404); }
    $pdo->prepare("INSERT OR IGNORE INTO placements (app_id, category, grp, sort) SELECT app_id, category, '', sort FROM placements WHERE grp = ?")->execute([$id]);
    $pdo->prepare('DELETE FROM placements WHERE grp = ?')->execute([$id]);
    $pdo->prepare("DELETE FROM grants WHERE kind = 'group' AND target = ?")->execute([$id]);
    $pdo->commit();
    json_out(['ok' => true]);
}

/**
 * {id, placements: [{category, group|null}]} — איפה האפליקציה מוצגת. לפחות מיקום אחד, ובכל אחד קטגוריה;
 * group ריק — בראשי של הקטגוריה. הסדר ברשימה — הסדר בתוך הקבוצה.
 */
function admin_placements_set(array $body): never
{
    $apps = array_column(apps_effective(), null, 'id');
    $app = $apps[$body['id'] ?? ''] ?? null;
    if (!$app) throw new ApiError('האפליקציה לא נמצאה', 404);
    if ($app['plugin']) throw new ApiError('תוסף לא מוצג ברשימה — אין לו מיקום', 422);
    $cats = array_column(categories_list(), 'id');
    $groups = array_column(groups_list(), 'id');
    $list = [];
    foreach ((array)($body['placements'] ?? []) as $p) {
        $c = is_array($p) ? ($p['category'] ?? null) : null;
        $g = is_array($p) ? ($p['group'] ?? null) : null;
        if (!is_string($c) || !in_array($c, $cats, true)) throw new ApiError('בכל שורה צריך לבחור קטגוריה', 422);
        if ($g !== null && $g !== '' && !in_array($g, $groups, true)) throw new ApiError('קבוצה לא מוכרת', 422);
        $list["$c|" . ($g ?? '')] = [$c, (string)($g ?? '')];
    }
    if (!$list) throw new ApiError('אפליקציה צריכה לפחות מקום אחד — קטגוריה', 422);
    if (count($list) > 20) throw new ApiError('עד 20 מקומות לאפליקציה', 422);

    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM placements WHERE app_id = ?')->execute([$app['id']]);
    $ins = $pdo->prepare('INSERT INTO placements (app_id, category, grp, sort) VALUES (?,?,?,?)');
    $n = 0;
    foreach ($list as [$c, $g]) $ins->execute([$app['id'], $c, $g, $n++]);
    $pdo->prepare('INSERT OR IGNORE INTO app_placed (app_id) VALUES (?)')->execute([$app['id']]);
    $pdo->commit();
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
    // שיטות הכניסה כשהאפליקציה מנותקת (docs/auth.md, סעיף 6). נשמרות גם כשהיא מחוברת — לניתוק הבא.
    if (isset($body['login_password']) || isset($body['login_google'])) {
        $cur = $app['connected'] ? ['password' => true, 'google' => false] : $app['login'];
        db()->prepare('INSERT INTO app_login (app_id, password, google) VALUES (?,?,?)
                       ON CONFLICT(app_id) DO UPDATE SET password = excluded.password, google = excluded.google')
            ->execute([$app['id'], (int)(bool)($body['login_password'] ?? $cur['password']), (int)(bool)($body['login_google'] ?? $cur['google'])]);
    }
    if (isset($body['catalog'])) {
        db()->prepare($body['catalog'] ? 'INSERT OR IGNORE INTO app_catalog (app_id) VALUES (?)'
                                       : 'DELETE FROM app_catalog WHERE app_id = ?')->execute([$app['id']]);
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

/**
 * GET admin_diag&limit=300[&user=<id>][&app=<id>][&device=<id>][&q=טקסט] — יומן האבחון (lib/diag.php), החדש
 * למעלה. ⚙️ ← 📜 יומן.
 */
function admin_diag(array $admin, array $q): never
{
    \Platform\Diag\ensure(db());
    $limit = max(20, min(1000, (int)($q['limit'] ?? 300)));
    $where = ['1'];
    $args = [];
    if (($q['user'] ?? '') !== '') { $where[] = 'd.user_id = ?'; $args[] = (int)$q['user']; }
    if (($q['app'] ?? '') !== '') { $where[] = 'd.app = ?'; $args[] = (string)$q['app']; }
    if (($q['device'] ?? '') !== '') { $where[] = 'd.device = ?'; $args[] = (string)$q['device']; }
    if (($q['q'] ?? '') !== '') {
        $where[] = '(d.event LIKE ? OR d.path LIKE ? OR d.ua LIKE ? OR d.info LIKE ? OR d.referrer LIKE ?)';
        array_push($args, ...array_fill(0, 5, '%' . (string)$q['q'] . '%'));
    }
    $st = db()->prepare('SELECT d.id, d.at, d.event, d.app, d.user_id, d.device, d.ua, d.path, d.referrer, d.info,
                                u.display_name AS user_name
                           FROM diag_log d LEFT JOIN users u ON u.id = d.user_id
                          WHERE ' . implode(' AND ', $where) . ' ORDER BY d.id DESC LIMIT ' . $limit);
    $st->execute($args);
    $rows = array_map(function ($r) {
        $r['info'] = json_decode((string)$r['info'], true) ?: [];
        return $r;
    }, $st->fetchAll());
    json_out(['rows' => $rows]);
}
