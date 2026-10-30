<?php
/**
 * הזמנת לקוח בקישור — מהמנהל.
 *
 * המנהל רושם את הוואטסאפ של הלקוח, מסמן מה נפתח לו, ואם רוצה — נועל את הקישור למייל אחד.
 * הלקוח פותח את הקישור (#join=…), ממלא שם, מייל וסיסמה, ונכנס מיד — בלי אישור — עם מה שסומן.
 * מי שכבר יש לו חשבון מצרף את ההרשאות לחשבון שלו.
 *
 * הקישור חד-פעמי, ובמסד נשמר רק ה-hash שלו. "שליחה מחדש" מנפיקה קישור חדש ומבטלת את הקודם.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/email.php';

const JOIN_DAYS = 14;

function invites_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS admin_invites (
            id         INTEGER PRIMARY KEY,
            token_hash TEXT NOT NULL UNIQUE,
            phone      TEXT NOT NULL,             -- ספרות בלבד, בפורמט בינלאומי (לקישור wa.me)
            name       TEXT NOT NULL DEFAULT '',  -- השם שהמנהל רשם — ממולא מראש בטופס
            email      TEXT,                      -- נעילה: רק המייל הזה נרשם בקישור
            grants     TEXT NOT NULL,             -- {categories: [], groups: [], apps: {id: bool}}
            created_by INTEGER,
            created_at TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            used_at    TEXT,
            used_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
            revoked_at TEXT
        );
        SQL);
}

/** מספר וואטסאפ: 050-1234567 / +972501234567 → 972501234567. */
function invite_phone($p): string
{
    $raw = is_string($p) ? trim($p) : '';
    $d = preg_replace('/\D/', '', $raw);
    if (str_starts_with($raw, '00')) $d = substr($d, 2);
    elseif (preg_match('/^0\d{8,9}$/', $d)) $d = '972' . substr($d, 1);   // מספר ישראלי מקומי
    if (!preg_match('/^[1-9]\d{7,14}$/', $d)) throw new ApiError('מספר הוואטסאפ לא תקין', 422);
    return $d;
}

/** ההרשאות מהבקשה — רק קטגוריות, קבוצות ואפליקציות שקיימות. */
function grants_clean(array $body): array
{
    $catIds = array_column(categories_list(), 'id');
    $groupIds = array_column(groups_list(), 'id');
    $appIds = array_column(apps_effective(), 'id');
    $apps = [];
    foreach ((array)($body['apps'] ?? []) as $id => $allowed) {
        if (in_array($id, $appIds, true) && is_bool($allowed)) $apps[$id] = $allowed;
    }
    return [
        'categories' => array_values(array_unique(array_filter((array)($body['categories'] ?? []),
            fn($c) => is_string($c) && in_array($c, $catIds, true)))),
        'groups' => array_values(array_unique(array_filter((array)($body['groups'] ?? []),
            fn($g) => is_string($g) && in_array($g, $groupIds, true)))),
        'apps' => $apps,
    ];
}

/** כותב הרשאות. replace — במקום כל הקיימות; אחרת מצרף (מה שבהזמנה גובר על אותה שורה). */
function grants_write(int $userId, array $g, bool $replace): void
{
    $pdo = db();
    if ($replace) $pdo->prepare('DELETE FROM grants WHERE user_id = ?')->execute([$userId]);
    $ins = $pdo->prepare('INSERT OR REPLACE INTO grants (user_id, kind, target, allowed) VALUES (?,?,?,?)');
    foreach ($g['categories'] as $c) $ins->execute([$userId, 'category', $c, 1]);
    foreach ($g['groups'] as $x) $ins->execute([$userId, 'group', $x, 1]);
    foreach ($g['apps'] as $id => $allowed) $ins->execute([$userId, 'app', $id, $allowed ? 1 : 0]);
}

function invite_url(string $token): string
{
    return site_url() . "#join=$token";
}

/** קישור חדש להזמנה (הקודם, אם היה, מפסיק לעבוד). מחזיר את האסימון. */
function invite_token(int $id): string
{
    $token = bin2hex(random_bytes(24));
    db()->prepare('UPDATE admin_invites SET token_hash = ?, expires_at = ? WHERE id = ?')
        ->execute([hash('sha256', $token), gmdate('Y-m-d\TH:i:s\Z', time() + JOIN_DAYS * 86400), $id]);
    return $token;
}

/** ההזמנות למסך המנהל — האחרונות קודם. האסימון עצמו לא נשמר, ולכן לא מוחזר. */
function invites_list(): array
{
    invites_migrate();
    $rows = db()->query(
        'SELECT i.id, i.phone, i.name, i.email, i.grants, i.created_at, i.expires_at, i.used_at, i.revoked_at,
                u.display_name AS used_name, u.email AS used_email
           FROM admin_invites i LEFT JOIN users u ON u.id = i.used_by
          ORDER BY i.id DESC LIMIT 100'
    )->fetchAll();
    $now = now_iso();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['grants'] = json_decode($r['grants'], true);
        $r['state'] = $r['used_at'] ? 'used' : ($r['revoked_at'] ? 'revoked' : ($r['expires_at'] < $now ? 'expired' : 'open'));
    }
    return $rows;
}

/** {phone, name?, email?, categories, groups, apps} → {id, url} */
function admin_invite_create(array $body, array $admin): never
{
    invites_migrate();
    $phone = invite_phone($body['phone'] ?? null);
    $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
    if (mb_strlen($name) > 60) throw new ApiError('שם: עד 60 תווים', 422);
    $email = ($body['email'] ?? '') === '' || ($body['email'] ?? null) === null ? null : validate_email($body['email']);
    $g = grants_clean($body);
    if (!$g['categories'] && !$g['groups'] && !in_array(true, $g['apps'], true)) {
        throw new ApiError('סמנו לפחות דבר אחד שייפתח ללקוח', 422);
    }
    db()->prepare('INSERT INTO admin_invites (token_hash, phone, name, email, grants, created_by, created_at, expires_at)
                   VALUES (?,?,?,?,?,?,?,?)')
        ->execute([bin2hex(random_bytes(16)), $phone, $name, $email, json_encode($g, JSON_UNESCAPED_UNICODE),
            (int)$admin['id'], now_iso(), now_iso()]);
    $id = (int)db()->lastInsertId();
    json_out(['ok' => true, 'id' => $id, 'url' => invite_url(invite_token($id))]);
}

/** {id} — קישור חדש לאותה הזמנה (ההקודם מבוטל), עוד JOIN_DAYS ימים. */
function admin_invite_renew(array $body): never
{
    invites_migrate();
    $st = db()->prepare('SELECT used_at FROM admin_invites WHERE id = ?');
    $st->execute([(int)($body['id'] ?? 0)]);
    $row = $st->fetch();
    if (!$row) throw new ApiError('ההזמנה לא נמצאה', 404);
    if ($row['used_at']) throw new ApiError('ההזמנה כבר נוצלה', 409);
    $id = (int)$body['id'];
    db()->prepare('UPDATE admin_invites SET revoked_at = NULL WHERE id = ?')->execute([$id]);
    json_out(['ok' => true, 'url' => invite_url(invite_token($id))]);
}

/** {id} — הקישור מפסיק לעבוד. */
function admin_invite_revoke(array $body): never
{
    invites_migrate();
    db()->prepare('UPDATE admin_invites SET revoked_at = ? WHERE id = ? AND used_at IS NULL')
        ->execute([now_iso(), (int)($body['id'] ?? 0)]);
    json_out(['ok' => true]);
}

/** ההזמנה הפתוחה של האסימון — או ApiError. */
function invite_open(string $token): array
{
    invites_migrate();
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) throw new ApiError('הקישור לא תקין', 400);
    $st = db()->prepare('SELECT * FROM admin_invites WHERE token_hash = ?');
    $st->execute([hash('sha256', $token)]);
    $row = $st->fetch();
    if (!$row || $row['revoked_at']) throw new ApiError('הקישור לא תקף — בקשו קישור חדש ממי ששלח אותו', 410);
    if ($row['used_at']) throw new ApiError('הקישור כבר נוצל — נכנסים במייל ובסיסמה', 410);
    if ($row['expires_at'] < now_iso()) throw new ApiError('תוקף הקישור פג — בקשו קישור חדש ממי ששלח אותו', 410);
    $row['grants'] = json_decode($row['grants'], true);
    return $row;
}

/** מה יראה הלקוח: שמות האפליקציות שההזמנה פותחת (כמו can_see, על חשבון ריק). */
function invite_apps(array $g): array
{
    $grants = ['categories' => array_fill_keys($g['categories'], true), 'groups' => array_fill_keys($g['groups'], true),
        'apps' => $g['apps']];
    $out = [];
    foreach (apps_effective() as $app) if (can_see($app, $grants)) $out[] = ['name' => $app['name'], 'icon' => $app['icon']];
    return $out;
}

/**
 * מימוש ההזמנה. $user — המשתמש המחובר: ההרשאות מצורפות לחשבון שלו. בלי — חשבון חדש, פעיל מיד,
 * מ-{display_name, email, password} (במייל נעול — המייל של ההזמנה). מחזיר {user_id, created}.
 */
function invite_join(string $token, ?array $user, array $body): array
{
    $inv = invite_open($token);
    if ($user) {
        if ($user['status'] === 'blocked') throw new ApiError('החשבון חסום', 403);
        if ($inv['email'] !== null && mb_strtolower((string)$user['email']) !== $inv['email']) {
            throw new ApiError("הקישור מיועד ל-{$inv['email']} — צאו מהחשבון הזה והיכנסו בו", 403);
        }
    } else {
        $name = validate_display_name($body['display_name'] ?? null);
        $email = $inv['email'] ?? validate_email($body['email'] ?? null);
        $password = validate_password($body['password'] ?? null);
    }

    // נבדק שוב בתוך הטרנזקציה: שתי לחיצות במקביל לא ינצלו את אותו קישור פעמיים.
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $st = $pdo->prepare('SELECT 1 FROM admin_invites WHERE id = ? AND used_at IS NULL AND revoked_at IS NULL');
        $st->execute([(int)$inv['id']]);
        if (!$st->fetch()) throw new ApiError('הקישור כבר נוצל — נכנסים במייל ובסיסמה', 410);
        if ($user) {
            $id = (int)$user['id'];
            // נרשם בעבר ועוד ממתין לאישור — ההזמנה היא האישור.
            if ($user['status'] === 'pending') $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$id]);
        } else {
            $dup = $pdo->prepare('SELECT 1 FROM users WHERE email = ? OR username = ?');
            $dup->execute([$email, $email]);
            if ($dup->fetch()) throw new ApiError('המייל הזה כבר רשום — היכנסו בו, ואז פתחו שוב את הקישור', 409);
            $pdo->prepare('INSERT INTO users (username, email, password_hash, display_name, role, status, created_at) VALUES (?,?,?,?,?,?,?)')
                ->execute([$email, $email, password_hash($password, PASSWORD_DEFAULT), $name, 'user', 'active', now_iso()]);
            $id = (int)$pdo->lastInsertId();
        }
        grants_write($id, $inv['grants'], !$user);
        $pdo->prepare('UPDATE admin_invites SET used_at = ?, used_by = ? WHERE id = ?')->execute([now_iso(), $id, (int)$inv['id']]);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
    return ['user_id' => $id, 'created' => !$user, 'email' => $email ?? null, 'name' => $name ?? null];
}

/** הסל של מי שנרשם לבד (ההגדרה default_grants): {categories, groups, apps} — מנוקה ממה שכבר לא קיים. */
function default_grants(): array
{
    $v = json_decode(setting('default_grants', ''), true);
    return grants_clean(is_array($v) ? $v : []);
}

