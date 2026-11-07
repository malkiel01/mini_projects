<?php
/**
 * 🛠️ דשבורד פיתוח לאפליקציה — על המנוע של DevLoop (devloop/), בתוך הפלטפורמה.
 *
 * המנהל מסמן לאיזו אפליקציה יש דשבורד (devboard_apps), ומוסיף לה עוקבים (devboard_followers).
 *   מפתח  = מנהל פעיל בפלטפורמה — DevLoop המלא: מטלות, מצבים, אימות, אסימונים ל-Claude.
 *   עוקב  = משתמש שהמנהל הוסיף לאפליקציה — רואה הכול, מעיר, מצרף צילומים ופותח 💡 המלצה. לא משנה מטלות.
 *
 * המקורות של כל דשבורד:
 *   <app>/dev/open-items.json         — מפת הדרכים, שהצ'אט של האפליקציה מתחזק (מה נעשה, מה בעבודה, מה מתוכנן).
 *   platform/data/devboard/<app>/     — ההערות, ההמלצות, הצילומים והאסימונים (dev.sqlite). מוחרג בפריסה.
 * הפלטפורמה רק קוראת את open-items.json — לא כותבת לתיקייה של האפליקציה.
 */

declare(strict_types=1);

require_once __DIR__ . '/devloop.php';

const DEVBOARD_DIR = __DIR__ . '/../data/devboard';
const DEVBOARD_TOKEN_PREFIX = 'pfd_';

function devboard_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS devboard_apps (
            app_id     TEXT PRIMARY KEY,
            prefix     TEXT NOT NULL,
            enabled_at TEXT NOT NULL
        );
        -- מטלה שהמפתח הסתיר ממשתמש מסוים (ברירת המחדל: כל עוקב רואה כל מטלה)
        CREATE TABLE IF NOT EXISTS devboard_hidden (
            app_id  TEXT NOT NULL,
            code    TEXT NOT NULL,
            user_id INTEGER NOT NULL,
            PRIMARY KEY (app_id, code, user_id)
        );
        CREATE TABLE IF NOT EXISTS devboard_followers (
            app_id   TEXT NOT NULL,
            user_id  INTEGER NOT NULL,
            added_at TEXT NOT NULL,
            PRIMARY KEY (app_id, user_id)
        );
        SQL);
}

/** [app_id => ['prefix', 'enabled_at', 'followers' => [user_id...]]] */
function devboard_all(): array
{
    devboard_migrate();
    $out = [];
    foreach (db()->query('SELECT * FROM devboard_apps') as $r) $out[$r['app_id']] = $r + ['followers' => []];
    foreach (db()->query('SELECT app_id, user_id FROM devboard_followers ORDER BY added_at') as $f) {
        if (isset($out[$f['app_id']])) $out[$f['app_id']]['followers'][] = (int)$f['user_id'];
    }
    return $out;
}

/** 'dev' | 'follower' | null — מה המשתמש רשאי בדשבורד של האפליקציה. */
function devboard_role(?array $user, string $appId): ?string
{
    if (!$user || $user['status'] !== 'active') return null;
    $b = devboard_all()[$appId] ?? null;
    if (!$b) return null;
    if ($user['role'] === 'admin') return 'dev';
    return in_array((int)$user['id'], $b['followers'], true) ? 'follower' : null;
}

/** הדשבורדים שהמשתמש רואה — לדף הבית. */
function devboards_for(array $user): array
{
    $apps = array_column(apps_effective(), null, 'id');
    $out = [];
    foreach (devboard_all() as $id => $b) {
        if (!isset($apps[$id]) || !($role = devboard_role($user, $id))) continue;
        $a = $apps[$id];
        $out[] = ['app' => $id, 'name' => $a['name'], 'icon' => $a['icon'], 'icon_svg' => $a['icon_svg'] ?? null, 'role' => $role];
    }
    return $out;
}

/** קוד המטלות: מהקובץ של האפליקציה אם יש בו (TS-001 → "TS-"), אחרת ראשי התיבות של השם (trading-strategies → "TS-"). */
function devboard_prefix(string $appId): string
{
    $f = devboard_items_path($appId);
    $data = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    foreach ($data['items'] ?? [] as $x) {
        if (preg_match('/^([A-Z]{1,5}-)\d+$/', (string)($x['code'] ?? ''), $m)) return $m[1];
    }
    $p = implode('', array_map(fn($w) => strtoupper($w[0]), array_filter(explode('-', $appId))));
    return substr($p ?: 'DEV', 0, 4) . '-';
}

function devboard_items_path(string $appId): string
{
    return dirname(__DIR__, 2) . '/' . $appId . '/dev/open-items.json';
}

/** המנהל: הפעלה/כיבוי, ורשימת העוקבים (מחליפה את הקיימת). הנתונים של דשבורד שכובה נשמרים — הפעלה מחדש מחזירה אותם. */
function admin_devboard_set(array $body): never
{
    devboard_migrate();
    $apps = array_column(apps_effective(), null, 'id');
    $id = (string)($body['app'] ?? '');
    if (!isset($apps[$id])) throw new ApiError('האפליקציה לא נמצאה', 404);
    if (array_key_exists('enabled', $body)) {
        if ($body['enabled']) {
            db()->prepare('INSERT OR IGNORE INTO devboard_apps (app_id, prefix, enabled_at) VALUES (?,?,?)')
                ->execute([$id, devboard_prefix($id), now_iso()]);
        } else {
            db()->prepare('DELETE FROM devboard_apps WHERE app_id = ?')->execute([$id]);
        }
    }
    if (is_array($body['followers'] ?? null)) {
        $ids = array_values(array_unique(array_map('intval', $body['followers'])));
        $known = $ids ? db()->query('SELECT id FROM users WHERE id IN (' . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_COLUMN) : [];
        $keep = array_map('intval', $known);
        $st = db()->prepare('DELETE FROM devboard_followers WHERE app_id = ?');
        $st->execute([$id]);
        $ins = db()->prepare('INSERT INTO devboard_followers (app_id, user_id, added_at) VALUES (?,?,?)');
        foreach ($keep as $u) $ins->execute([$id, $u, now_iso()]);
    }
    json_out(['ok' => true, 'devboards' => devboard_all()]);
}

/** המנהל, ממסך המשתמש: הוספה / הסרה של עוקב אחד בדשבורד של אפליקציה. */
function admin_devboard_follow(array $body): never
{
    $id = (string)($body['app'] ?? '');
    if (!isset(devboard_all()[$id])) throw new ApiError('הדשבורד כבוי לאפליקציה הזו', 409);
    $uid = (int)($body['user'] ?? 0);
    $st = db()->prepare('SELECT 1 FROM users WHERE id = ?');
    $st->execute([$uid]);
    if (!$st->fetchColumn()) throw new ApiError('המשתמש לא נמצא', 404);
    db()->prepare(!empty($body['on']) ? 'INSERT OR IGNORE INTO devboard_followers (app_id, user_id, added_at) VALUES (?,?,?)'
                                      : 'DELETE FROM devboard_followers WHERE app_id = ? AND user_id = ? AND ? IS NOT NULL')
        ->execute([$id, $uid, now_iso()]);
    json_out(['ok' => true]);
}

/** מחבר את DevLoop לדשבורד של אפליקציה אחת — לבקשה הנוכחית. */
function devboard_setup(string $appId): void
{
    $b = devboard_all()[$appId] ?? throw new ApiError('אין דשבורד פיתוח לאפליקציה הזו', 404);
    $dir = DEVBOARD_DIR . '/' . $appId;
    devloop_setup([
        'db' => function () use ($dir): PDO {
            static $pdo = null;
            if ($pdo) return $pdo;
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $pdo = new PDO('sqlite:' . $dir . '/dev.sqlite', null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 5000;');
            return $pdo;
        },
        'items_file' => devboard_items_path($appId),
        'data_dir' => $dir,
        'prefix' => $b['prefix'],
        'token_prefix' => DEVBOARD_TOKEN_PREFIX,
        'error' => function (string $msg, int $status = 400, array $vars = []): Throwable {
            foreach ($vars as $k => $v) $msg = str_replace('{' . $k . '}', (string)$v, $msg);
            return new ApiError($msg, $status);
        },
        'user_names' => fn(): array => array_column(db()->query('SELECT id, display_name FROM users')->fetchAll(), 'display_name', 'id'),
        'is_developer' => function (int $id): bool {
            $st = db()->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'admin' AND status = 'active'");
            $st->execute([$id]);
            return (bool)$st->fetchColumn();
        },
        'now' => fn() => now_iso(),
    ]);
}

/**
 * התראה למפתחים כשעוקב כתב — Web Push למנהלים (lib/push.php). לא זורקת.
 */
function devboard_push_follower(string $appId, array $user, string $code, string $what): void
{
    try {
        require_once __DIR__ . '/push.php';
        $apps = array_column(apps_effective(), null, 'id');
        push_send(['title' => "🛠️ {$apps[$appId]['name']} — $what", 'body' => "{$user['display_name']} · $code",
            'tag' => "devboard-$appId", // יחסית ל-platform/home/ (family-sw.js, notificationclick)
            'url' => "../devboard.php?app=$appId#$code"]);
    } catch (Throwable $e) {
        error_log('devboard push: ' . $e->getMessage());
    }
}

// ── שלוש הרמות: 📋 משימות (לכולם, חוץ ממה שהוסתר), 👥 משתמשים ו-📜 לוגים (למפתח בלבד) ──────────

/** הקודים שהוסתרו מהמשתמש באפליקציה. */
function devboard_hidden_for(string $appId, int $userId): array
{
    devboard_migrate();
    $st = db()->prepare('SELECT code FROM devboard_hidden WHERE app_id = ? AND user_id = ?');
    $st->execute([$appId, $userId]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** המפתח: הסתרה / החזרה של מטלה ממשתמש. */
function devboard_hide(string $appId, array $b): array
{
    devboard_migrate();
    $code = (string)($b['code'] ?? '');
    $uid = (int)($b['user_id'] ?? 0);
    devloop_task_get($code);
    $st = db()->prepare('SELECT 1 FROM users WHERE id = ?');
    $st->execute([$uid]);
    if (!$st->fetchColumn()) throw new ApiError('המשתמש לא נמצא', 404);
    db()->prepare(!empty($b['hidden']) ? 'INSERT OR IGNORE INTO devboard_hidden (app_id, code, user_id) VALUES (?,?,?)'
                                       : 'DELETE FROM devboard_hidden WHERE app_id = ? AND code = ? AND user_id = ?')
        ->execute([$appId, $code, $uid]);
    return ['ok' => true];
}

/**
 * 👥 המשתמשים — העוקבים, וכל מי שנכנס לאפליקציה ב-90 הימים האחרונים (visits, מהשער): כניסות, אחרונה,
 * מכשיר, הערות והמלצות בדשבורד, ומה הוסתר ממנו.
 */
function devboard_users(string $appId): array
{
    $b = devboard_all()[$appId];
    $since = gmdate('Y-m-d\TH:i:s\Z', time() - 90 * 86400);
    $st = db()->prepare('SELECT user_id, COUNT(*) n, MAX(at) last_at FROM visits WHERE app = ? AND user_id IS NOT NULL AND at > ? GROUP BY user_id');
    $st->execute([$appId, $since]);
    $visits = [];
    foreach ($st as $r) $visits[(int)$r['user_id']] = $r;
    $dev = [];
    foreach (devloop_db()->query("SELECT user_id, COUNT(*) n, MAX(at) last_at FROM dev_comments WHERE author = 'user' GROUP BY user_id") as $r) $dev[(int)$r['user_id']]['comments'] = $r;
    foreach (devloop_db()->query('SELECT created_by, COUNT(*) n FROM dev_tasks GROUP BY created_by') as $r) $dev[(int)$r['created_by']]['tasks'] = (int)$r['n'];
    $hidden = [];
    $st = db()->prepare('SELECT user_id, code FROM devboard_hidden WHERE app_id = ?');
    $st->execute([$appId]);
    foreach ($st as $r) $hidden[(int)$r['user_id']][] = $r['code'];
    $ids = array_unique(array_merge($b['followers'], array_keys($visits), array_keys($dev)));
    if (!$ids) return ['users' => []];
    $out = [];
    foreach (db()->query('SELECT id, display_name, username, role, status FROM users WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')') as $u) {
        $id = (int)$u['id'];
        $dv = db()->prepare('SELECT device FROM visits WHERE app = ? AND user_id = ? ORDER BY id DESC LIMIT 1');
        $dv->execute([$appId, $id]);
        $out[] = ['id' => $id, 'name' => $u['display_name'] ?: $u['username'], 'role' => $u['role'], 'status' => $u['status'],
            'follower' => in_array($id, $b['followers'], true),
            'visits' => (int)($visits[$id]['n'] ?? 0), 'last_visit' => $visits[$id]['last_at'] ?? null, 'device' => $dv->fetchColumn() ?: '',
            'comments' => (int)($dev[$id]['comments']['n'] ?? 0), 'last_comment' => $dev[$id]['comments']['last_at'] ?? null,
            'tasks' => $dev[$id]['tasks'] ?? 0, 'hidden' => $hidden[$id] ?? []];
    }
    usort($out, fn($a, $b) => strcmp((string)($b['last_visit'] ?? $b['last_comment'] ?? ''), (string)($a['last_visit'] ?? $a['last_comment'] ?? '')));
    return ['users' => $out];
}

/** 📜 הלוגים של האפליקציה — יומן האבחון של הפלטפורמה (diag_log: ניווט, שגיאות דפדפן, התקנות), עד 300 שורות. */
function devboard_logs(string $appId, array $q): array
{
    require_once __DIR__ . '/diag.php';
    \Platform\Diag\ensure(db());
    $where = ['d.app = ?'];
    $args = [$appId];
    if (($q['q'] ?? '') !== '') {
        $where[] = '(d.event LIKE ? OR d.path LIKE ? OR d.info LIKE ? OR u.display_name LIKE ?)';
        array_push($args, ...array_fill(0, 4, '%' . (string)$q['q'] . '%'));
    }
    if (($q['user'] ?? '') !== '') { $where[] = 'd.user_id = ?'; $args[] = (int)$q['user']; }
    $st = db()->prepare('SELECT d.id, d.at, d.event, d.user_id, d.device, d.path, d.info, u.display_name AS user_name
                           FROM diag_log d LEFT JOIN users u ON u.id = d.user_id
                          WHERE ' . implode(' AND ', $where) . ' ORDER BY d.id DESC LIMIT 300');
    $st->execute($args);
    return ['rows' => array_map(fn($r) => ['info' => json_decode((string)$r['info'], true) ?: []] + $r, $st->fetchAll())];
}

