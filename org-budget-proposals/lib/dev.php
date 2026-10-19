<?php
/**
 * אזור הפיתוח — למפתח בלבד:
 *   👥 משתמשים   אישור, דחייה, חסימה, ניתוק, שליחת איפוס
 *   🪵 לוג מעקב  כל בקשה: פעולה, תוצאה, משך, מי, מזהים, שגיאה (בלי ערכים). 30 יום
 *   🛡️ אכיפה     כל פעולת מפתח נרשמת ביומן הביקורת, עם סיבה — ואין פעולה שמוחקת ממנו
 *   🩺 מצב        גרסאות, נפח, ספירות, שגיאות ביממה
 */

declare(strict_types=1);

require_once __DIR__ . '/platform.php';

const DEV_LOG_DAYS = 30;

/** מזהה הבקשה — בלוג ובכותרת X-Request-Id של התשובה. */
function dev_req_id(): string
{
    return $GLOBALS['ob_req'] ??= bin2hex(random_bytes(6));
}

// ── לוג מעקב ─────────────────────────────────────────────────

/** רשומה ללוג. ref — מזהים מהבקשה (ארגון, הצעה, משתמש), לא ערכים. */
function dev_track(?array $session, string $action, float $started, int $status, ?Throwable $e = null, array $extra = []): void
{
    try {
        $ref = [];
        foreach (['id', 'org_id', 'budget_id', 'fund_id', 'user_id'] as $k) {
            $v = $_GET[$k] ?? ($GLOBALS['ob_body'][$k] ?? null);
            if (is_numeric($v)) $ref[] = "$k=" . (int)$v;
        }
        $trace = null;
        if ($e && !($e instanceof ApiError)) $trace = basename($e->getFile()) . ':' . $e->getLine() . ' ' . get_class($e);
        db()->prepare('INSERT INTO dev_log (at, user_id, action, method, status, duration_ms, ref, ip, message, trace, page, req, ua)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([now_iso(), $session ? (int)$session['id'] : null, mb_substr($action, 0, 60),
                $_SERVER['REQUEST_METHOD'] ?? 'GET', $status, (int)((microtime(true) - $started) * 1000),
                $ref ? implode(' ', $ref) : null, client_ip(),
                $e ? mb_substr($e->getMessage(), 0, 500) : ($extra['message'] ?? null), $extra['trace'] ?? $trace,
                $extra['page'] ?? null, dev_req_id(), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)]);
        if (random_int(1, 200) === 1) {
            db()->prepare('DELETE FROM dev_log WHERE at < ?')->execute([gmdate('Y-m-d\TH:i:s\Z', time() - DEV_LOG_DAYS * 86400)]);
        }
    } catch (Throwable) {
        // לוג שנכשל לא מפיל את הבקשה
    }
}

/** שגיאת JavaScript בדפדפן — נשלחת מהדף, נרשמת ללוג. */
function client_error(?array $session, array $b): array
{
    dev_track($session, 'client_error', microtime(true), 0, null, [
        'message' => mb_substr((string)($b['message'] ?? ''), 0, 500),
        'trace' => mb_substr((string)($b['where'] ?? ''), 0, 300),
        'page' => mb_substr((string)($b['page'] ?? ''), 0, 120),
    ]);
    return ['ok' => true];
}

function dev_log_list(array $q): array
{
    $where = ['1 = 1'];
    $args = [];
    if (!empty($q['errors'])) $where[] = "(l.status >= 400 OR l.action = 'client_error')";
    if (!empty($q['act'])) { $where[] = 'l.action LIKE ?'; $args[] = '%' . $q['act'] . '%'; }
    // בלי הבקשות של אזור הפיתוח עצמו (הוא מתרענן וממלא את הרשימה), חוץ משגיאות
    if (!empty($q['nodev'])) $where[] = "(l.action NOT LIKE 'dev\_%' ESCAPE '\\' OR l.status >= 400)";
    if (!empty($q['user_id'])) { $where[] = 'l.user_id = ?'; $args[] = (int)$q['user_id']; }
    if (!empty($q['before'])) { $where[] = 'l.id < ?'; $args[] = (int)$q['before']; }
    $st = db()->prepare('SELECT l.*, u.display_name, u.email FROM dev_log l LEFT JOIN users u ON u.id = l.user_id
        WHERE ' . implode(' AND ', $where) . ' ORDER BY l.id DESC LIMIT 200');
    $st->execute($args);
    $day = gmdate('Y-m-d\TH:i:s\Z', time() - 86400);
    $stats = db()->query("SELECT COUNT(*) AS n, COALESCE(SUM(status >= 500), 0) AS e5, COALESCE(SUM(status >= 400 AND status < 500), 0) AS e4,
        COALESCE(SUM(action = 'client_error'), 0) AS js, COALESCE(ROUND(AVG(duration_ms)), 0) AS avg_ms FROM dev_log WHERE at > '$day'")->fetch();
    return ['ok' => true, 'rows' => array_map(fn($r) => [
        'id' => (int)$r['id'], 'at' => $r['at'], 'action' => $r['action'], 'method' => $r['method'],
        'status' => $r['status'] === null ? null : (int)$r['status'], 'ms' => (int)$r['duration_ms'],
        'user' => $r['display_name'], 'email' => $r['email'], 'user_id' => $r['user_id'] === null ? null : (int)$r['user_id'],
        'ref' => $r['ref'], 'ip' => $r['ip'], 'message' => $r['message'], 'trace' => $r['trace'], 'page' => $r['page'], 'req' => $r['req'],
    ], $st->fetchAll()), 'day' => array_map('intval', $stats)];
}

// ── משתמשים ──────────────────────────────────────────────────

function dev_users(): array
{
    $st = db()->prepare(
        "SELECT u.id, u.email, u.display_name, u.is_developer, u.status, u.created_at, u.last_login_at, u.last_seen_at,
                (SELECT COUNT(*) FROM sessions s WHERE s.user_id = u.id AND s.expires_at > ?) AS devices
         FROM users u WHERE u.status != 'deleted'
         ORDER BY CASE u.status WHEN 'pending' THEN 0 ELSE 1 END, u.created_at DESC"
    );
    $st->execute([now_iso()]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['is_developer'] = (bool)$r['is_developer'];
        $r['devices'] = (int)$r['devices'];
        $r['online'] = $r['devices'] > 0 && $r['last_seen_at'] && time() - strtotime($r['last_seen_at']) < ONLINE_SECONDS;
    }
    return ['ok' => true, 'users' => $rows];
}

/** הפעולות על חשבון, ובאילו מצבים הן מותרות. "סיבה" — חובה בפעולה שפוגעת במשתמש. */
const DEV_USER_OPS = [
    'approve' => ['states' => ['pending'],                     'reason' => false, 'label' => 'אישור'],
    'reject'  => ['states' => ['pending', 'unverified'],       'reason' => true,  'label' => 'דחייה'],
    'block'   => ['states' => ['active', 'pending'],           'reason' => true,  'label' => 'חסימה'],
    'unblock' => ['states' => ['blocked'],                     'reason' => false, 'label' => 'ביטול חסימה'],
    'logout'  => ['states' => ['active', 'pending', 'blocked'], 'reason' => true, 'label' => 'ניתוק מכל המכשירים'],
    'reset'   => ['states' => ['active', 'pending'],           'reason' => false, 'label' => 'שליחת קישור איפוס'],
];

function dev_user_action(array $b, array $dev): array
{
    $st = db()->prepare("SELECT * FROM users WHERE id = ? AND status != 'deleted'");
    $st->execute([(int)($b['user_id'] ?? 0)]);
    $u = $st->fetch() ?: throw new ApiError('המשתמש לא נמצא', 404);
    if ((int)$u['id'] === (int)$dev['id']) throw new ApiError('אי אפשר לבצע פעולה זו על החשבון שלך', 409);

    $op = (string)($b['op'] ?? '');
    $def = DEV_USER_OPS[$op] ?? throw new ApiError('פעולה לא מוכרת', 422);
    if (!in_array($u['status'], $def['states'], true)) throw new ApiError('הפעולה אינה מתאימה למצב החשבון', 409);
    $reason = mb_substr(trim((string)($b['reason'] ?? '')), 0, 300);
    if ($def['reason'] && mb_strlen($reason) < 3) throw new ApiError('חובה לכתוב סיבה — היא נשמרת ביומן הביקורת', 422);

    $pdo = db();
    $kill = fn() => $pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$u['id']]);
    $set  = fn(string $s) => $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$s, $u['id']]);
    $message = $def['label'] . ' — בוצע';

    switch ($op) {
        case 'approve': $set('active'); break;
        // דחייה = מחיקה רכה. הכתובת לא תוכל להירשם שוב.
        case 'reject':  $set('deleted'); $kill(); break;
        case 'block':   $set('blocked'); $kill(); break;
        case 'unblock': $set('active'); break;
        case 'logout':  $kill(); break;
        case 'reset':
            if (!send_reset((int)$u['id'])) throw new ApiError('שליחת המייל נכשלה', 502);
            $message = 'נשלח קישור איפוס ל-' . $u['email'];
            break;
    }
    dev_audit($dev, $op, 'user', (int)$u['id'], $reason, ['email' => $u['email'], 'from' => $u['status']]);
    return ['ok' => true, 'message' => $message];
}

// ── יומן הביקורת ─────────────────────────────────────────────

function dev_audit(array $dev, string $op, string $type, int $id, string $reason, array $detail = []): void
{
    db()->prepare('INSERT INTO dev_audit (at, dev_id, op, target_type, target_id, reason, detail) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([now_iso(), $dev['id'], $op, $type, $id, $reason, json_encode($detail, JSON_UNESCAPED_UNICODE)]);
}

function dev_audit_list(): array
{
    $rows = db()->query('SELECT a.*, u.display_name FROM dev_audit a JOIN users u ON u.id = a.dev_id ORDER BY a.id DESC LIMIT 200')->fetchAll();
    $labels = array_map(fn($d) => $d['label'], DEV_USER_OPS) + (defined('DEV_ORG_OPS') ? DEV_ORG_OPS : []);
    return ['ok' => true, 'audit' => array_map(fn($a) => [
        'at' => $a['at'], 'by' => $a['display_name'], 'op' => $a['op'], 'label' => $labels[$a['op']] ?? $a['op'],
        'type' => $a['target_type'], 'id' => (int)$a['target_id'], 'reason' => $a['reason'],
        'detail' => json_decode((string)$a['detail'], true),
    ], $rows)];
}

// ── מצב המערכת ───────────────────────────────────────────────

function dev_health(): array
{
    $count = fn(string $sql) => (int)db()->query($sql)->fetchColumn();
    $day = gmdate('Y-m-d\TH:i:s\Z', time() - 86400);
    return ['ok' => true, 'health' => [
        'php' => PHP_VERSION,
        'sqlite' => db()->query('SELECT sqlite_version()')->fetchColumn(),
        'db_mb' => is_file(OB_DB_FILE) ? round(filesize(OB_DB_FILE) / 1048576, 2) : 0,
        'disk_free_mb' => ($f = @disk_free_space(OB_DATA_DIR)) ? (int)($f / 1048576) : null,
        'data_writable' => is_writable(OB_DATA_DIR),
        'data_protected' => is_file(OB_DATA_DIR . '/.htaccess'),
        'mail' => function_exists('mail'),
        'zip' => class_exists('ZipArchive'),
        'https' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'counts' => [
            'users' => $count("SELECT COUNT(*) FROM users WHERE status = 'active'"),
            'pending' => $count("SELECT COUNT(*) FROM users WHERE status = 'pending'"),
        ],
        'day' => [
            'requests' => $count("SELECT COUNT(*) FROM dev_log WHERE at > '$day'"),
            'errors' => $count("SELECT COUNT(*) FROM dev_log WHERE at > '$day' AND status >= 500"),
            'js_errors' => $count("SELECT COUNT(*) FROM dev_log WHERE at > '$day' AND action = 'client_error'"),
        ],
    ]];
}
