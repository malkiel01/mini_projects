<?php
declare(strict_types=1);

/**
 * ה-API לדפדפן. הזהות מהשער של הפלטפורמה בלבד ($_SERVER['PLATFORM_USER'] עם granted === true) — אין כניסה בסיסמה.
 * מי המשתמש באפליקציה, הארגון הפעיל ומה מותר לו — lib/access.php (resolveAccess, can, scope). אין בדיקת תפקיד כאן.
 * ה-session — ל-CSRF ולארגון הפעיל, קשור ל-sub מהשער. כל בקשה נרשמת ב-req_log (בלי גוף הבקשה).
 * חריג אחד: devapi-* (DevLoop, אסימון fdd_) — בלי השער, ציבוריות ב-app.json.
 * מערכות חיצוניות עם Authorization: Bearer עובדות מול ingest.php, לא מול הקובץ הזה.
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/lib/devtasks.php';

$t0 = microtime(true);
$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
register_shutdown_function(function () use ($t0, $action, $method) {
    logRequest($method, $action, http_response_code() ?: 200, (int)round((microtime(true) - $t0) * 1000),
        $GLOBALS['fd_ctx']['user']['id'] ?? null, (string)($GLOBALS['fd_err'] ?? ''));
});
devloop_log_begin(null);

/** פעולת DevLoop: FdError (מהמודול) → תשובה עם הסטטוס שלה. פעולות שמגישות קובץ יוצאות לבד. */
function runDevloop(callable $fn): never {
    try {
        reply(200, $fn());
    } catch (FdError $e) {
        devloop_log_error($e);
        $code = $e->getCode();
        reply($code >= 400 && $code < 600 ? $code : 400, ['error' => $e->getMessage()]);
    } catch (Throwable $e) {
        devloop_log_error($e);
        reply(500, ['error' => 'הפעולה נכשלה']);
    }
}
if (in_array($action, DEVAPI_ACTIONS, true)) runDevloop(fn() => devapiAction($action));

$pu = platformUser();
if ($pu === null) reply(401, ['error' => 'אין הרשאה לאפליקציה. יש להיכנס דרך הפלטפורמה ולקבל אישור לאפליקציה.']);

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('fd_session');
session_set_cookie_params([
    'lifetime' => 0, 'path' => rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/',
    'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict'
]);
session_start();
// session של משתמש אחר (או בלי sub) — מתחילים חדש, עם CSRF חדש
if (($_SESSION['fd_sub'] ?? null) !== $pu['sub']) {
    session_regenerate_id(true);
    $_SESSION = ['fd_sub' => $pu['sub'], 'fd_csrf' => bin2hex(random_bytes(24))];
}

$pdo = db();
$ctx = resolveAccess($pu);
devloop_log_user(['id' => $ctx['user']['id'], 'name' => $ctx['user']['name'] ?: $ctx['user']['email']]);
$cfg = config();

if ($action === 'me') {
    if ($ctx['org'] === null) reply(403, noOrgBody());
    $perms = [];
    foreach (array_keys(PERMS) as $p) $perms[$p] = can($p);
    reply(200, ['ok' => true, 'csrf' => $_SESSION['fd_csrf'], 'version' => VERSION,
        'user' => ['name' => $ctx['user']['name'], 'email' => $ctx['user']['email']],
        'dev' => $ctx['dev'], 'role' => $ctx['role'], 'role_label' => $ctx['role'] ? ROLES[$ctx['role']] : ($ctx['dev'] ? 'מפתח' : ''),
        'org' => ['id' => $ctx['org']['id'], 'name' => $ctx['org']['name']],
        'orgs' => array_map(fn($o) => ['id' => $o['id'], 'name' => $o['name']], $ctx['orgs']),
        'perms' => $perms,
        'has_api_token' => can('token.manage') && ($cfg['api_token_sha256'] ?? '') !== '',
        'embed_key' => cleanText($cfg['dropbox_app_key'] ?? '', 100)]);
}
if ($method !== 'GET' &&
    (!isset($_SERVER['HTTP_X_CSRF_TOKEN']) || !hash_equals((string)$_SESSION['fd_csrf'], (string)$_SERVER['HTTP_X_CSRF_TOKEN']))) {
    reply(403, ['error' => 'אימות בקשה נכשל']);
}
if ($action === 'logout') {
    if ($method !== 'POST') reply(405, ['error' => 'שיטה לא מורשית']);
    $_SESSION = [];
    session_destroy();
    reply(200, ['ok' => true]);
}
// מכאן — רק משתמש פעיל עם ארגון (או מפתח)
if ($ctx['org'] === null) reply(403, noOrgBody());
$org = scope();
$actor = $ctx['actor'];
$post = function () use ($method): array {
    if ($method !== 'POST') reply(405, ['error' => 'שיטה לא מורשית']);
    return body();
};
$get = function () use ($method): void { if ($method !== 'GET') reply(405, ['error' => 'שיטה לא מורשית']); };

/* ===== AI מובנה (פרוסה 06) — ai/actions.php ===== */
require_once __DIR__ . '/ai/actions.php';
if (aiHandles($action)) aiAction($pdo, $action, $method);

/* ===== DevLoop (מפתח בלבד) ===== */
if (str_starts_with($action, 'dev-') || $action === 'devloop-client-error') {
    need('dev');
    runDevloop(fn() => devloopAction($action, $method, ['id' => $ctx['user']['id'], 'name' => $ctx['user']['name']])
        ?? throw new FdError('פעולה לא מוכרת', 404));
}

switch ($action) {
    /* ===== תיקים ===== */
    case 'list':
        need('case.view');
        listMonth($pdo, $action, $method, $org);
    case 'export':
        need('case.export');
        listMonth($pdo, $action, $method, $org);
    case 'import':
        need('case.import');
        importRecords($pdo, $method, $org, $actor, 'ui');
    case 'save':
        $payload = $post();
        need('case.view');
        // lib/form.php: manual_fields, מקור לכל בדיקה (via: ui), ו-store() שרושם ב-events. תיק של ארגון אחר → 404
        $item = formSaveCase($pdo, $payload, 'ui', $actor, $org,
            fn(?array $old, array $item) => need(caseChangeNeeds($old === null ? null : normalize($old, $org), $item)));
        reply(200, ['ok' => true, 'record' => $item]);
    case 'history':
        // יומן התיק (פרוסה 02): 50 אחרונים מ-events. תיק של ארגון אחר → 404 (ה5)
        need('case.view');
        formHistoryAction($pdo, $method, $org);

    case 'api_token':
        // אסימון ingest.php — אחד, של ארגון 1 (הכרעה ה9). מוחזר פעם אחת; נשמר רק ה-SHA-256. החלפה מבטלת את הקודם.
        $post();
        need('token.manage');
        if ($org !== 1) reply(403, ['error' => 'האסימון של ingest.php שייך לארגון הראשון. עברו אליו כדי ליצור אסימון.']);
        $token = 'fd_' . bin2hex(random_bytes(32));
        $cfg['api_token_sha256'] = hash('sha256', $token);
        writeConfig($cfg);
        recordEvent($org, null, $actor, 'ui', 'api_token');
        reply(200, ['ok' => true, 'token' => $token]);

    /* ===== חברים והזמנות ===== */
    case 'members':
        $get();
        need('members.manage');
        reply(200, membersOf($org));
    case 'invite':
        $in = $post();
        need('members.manage');
        $email = mb_strtolower(cleanText($in['email'] ?? '', 200));
        $role = (string)($in['role'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) reply(400, ['error' => 'כתובת מייל לא תקינה']);
        if (!isset(ROLES[$role])) reply(400, ['error' => 'תפקיד לא תקין']);
        $st = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $st->execute([$email]);
        $uid = $st->fetchColumn();
        if ($uid !== false && memberRole($org, (int)$uid) !== null) reply(400, ['error' => 'המשתמש כבר חבר בארגון']);
        // ההזמנה תמיד ממתינה — מצטרפים בכניסה הבאה. לא מגלים אם יש חשבון במייל הזה, ולא מצרפים מיד.
        $pdo->prepare('DELETE FROM invites WHERE org_id = ? AND email = ? AND used_at IS NULL')->execute([$org, $email]);
        $pdo->prepare('INSERT INTO invites(org_id, email, role, created_by, created_at) VALUES (?,?,?,?,?)')
            ->execute([$org, $email, $role, $ctx['user']['id'], gmdate('c')]);
        recordEvent($org, null, $actor, 'ui', 'invite', ['email' => [null, $email], 'role' => [null, $role]]);
        reply(200, membersOf($org));
    case 'invite_cancel':
        $in = $post();
        need('members.manage');
        $st = $pdo->prepare('DELETE FROM invites WHERE id = ? AND org_id = ? AND used_at IS NULL');
        $st->execute([(int)($in['id'] ?? 0), $org]);
        if (!$st->rowCount()) reply(404, ['error' => 'ההזמנה לא נמצאה']);
        recordEvent($org, null, $actor, 'ui', 'invite_cancel', ['invite_id' => [(int)$in['id'], null]]);
        reply(200, membersOf($org));
    case 'member_role':
        $in = $post();
        need('members.manage');
        $uid = (int)($in['user_id'] ?? 0);
        $role = (string)($in['role'] ?? '');
        if (!isset(ROLES[$role])) reply(400, ['error' => 'תפקיד לא תקין']);
        $cur = memberRole($org, $uid) ?? reply(404, ['error' => 'החבר לא נמצא']);
        if ($cur === 'admin' && $role !== 'admin' && adminCount($org) <= 1) {
            reply(400, ['error' => 'זה המנהל האחרון של הארגון. קודם למנות מנהל נוסף.']);
        }
        if ($cur !== $role) {
            $pdo->prepare('UPDATE members SET role = ? WHERE org_id = ? AND user_id = ?')->execute([$role, $org, $uid]);
            recordEvent($org, null, $actor, 'ui', 'member_role', ['user_id' => [$uid, $uid], 'role' => [$cur, $role]]);
        }
        reply(200, membersOf($org));
    case 'member_active':
        $in = $post();
        need('members.manage');
        $uid = (int)($in['user_id'] ?? 0);
        $active = !empty($in['active']) ? 1 : 0;
        $cur = memberRole($org, $uid) ?? reply(404, ['error' => 'החבר לא נמצא']);
        if ($uid === $ctx['user']['id'] && !$active) reply(400, ['error' => 'אי אפשר להשבית את עצמך']);
        if (!$active && $cur === 'admin' && adminCount($org) <= 1) reply(400, ['error' => 'זה המנהל הפעיל האחרון של הארגון']);
        // השבתה בארגון הזה בלבד (members.active) — לא נוגעת בחברות שלו בארגונים אחרים
        $pdo->prepare('UPDATE members SET active = ? WHERE org_id = ? AND user_id = ?')->execute([$active, $org, $uid]);
        recordEvent($org, null, $actor, 'ui', $active ? 'member_enabled' : 'member_disabled', ['user_id' => [$uid, $uid], 'active' => [1 - $active, $active]]);
        reply(200, membersOf($org));
    case 'user_active':
        // חסימה גלובלית של משתמש (users.active) — מפתח בלבד
        $in = $post();
        need('dev');
        $uid = (int)($in['user_id'] ?? 0);
        $active = !empty($in['active']) ? 1 : 0;
        if ($uid === $ctx['user']['id']) reply(400, ['error' => 'אי אפשר לחסום את עצמך']);
        $st = $pdo->prepare('UPDATE users SET active = ? WHERE id = ?');
        $st->execute([$active, $uid]);
        if (!$st->rowCount()) reply(404, ['error' => 'המשתמש לא נמצא']);
        recordEvent(null, null, $actor, 'ui', $active ? 'user_enabled' : 'user_disabled', ['user_id' => [$uid, $uid], 'active' => [1 - $active, $active]]);
        reply(200, ['ok' => true]);

    /* ===== ארגונים ===== */
    case 'orgs':
        $get();
        need('orgs.manage');
        reply(200, ['orgs' => allOrgs(), 'active' => $org]);
    case 'org_create':
        $in = $post();
        need('orgs.manage');
        $name = cleanText($in['name'] ?? '', 120);
        if ($name === '') reply(400, ['error' => 'נדרש שם ארגון']);
        $pdo->prepare('INSERT INTO orgs(name, created_at, settings) VALUES (?,?,\'{}\')')->execute([$name, gmdate('c')]);
        $id = (int)$pdo->lastInsertId();
        recordEvent($id, null, $actor, 'ui', 'org_create', ['name' => [null, $name]]);
        reply(200, ['orgs' => allOrgs(), 'active' => $org, 'created' => $id]);
    case 'org_rename':
        $in = $post();
        need('orgs.manage');
        $id = (int)($in['org_id'] ?? 0);
        $name = cleanText($in['name'] ?? '', 120);
        if ($name === '') reply(400, ['error' => 'נדרש שם ארגון']);
        $st = $pdo->prepare('SELECT name FROM orgs WHERE id = ?');
        $st->execute([$id]);
        $was = $st->fetchColumn();
        if ($was === false) reply(404, ['error' => 'הארגון לא נמצא']);
        $pdo->prepare('UPDATE orgs SET name = ? WHERE id = ?')->execute([$name, $id]);
        recordEvent($id, null, $actor, 'ui', 'org_rename', ['name' => [$was, $name]]);
        reply(200, ['orgs' => allOrgs(), 'active' => $org]);
    case 'org_switch':
        $in = $post();
        $id = (int)($in['org_id'] ?? 0);
        if (!in_array($id, array_column($ctx['orgs'], 'id'), true)) reply(404, ['error' => 'הארגון לא נמצא']);
        $_SESSION['fd_org'] = $id;
        reply(200, ['ok' => true, 'org_id' => $id]);

    /* ===== מסך המפתח ===== */
    case 'dev_health':
        $get();
        need('dev');
        reply(200, healthChecks($pdo));
    case 'dev_requests':
        $get();
        need('dev');
        $sql = 'SELECT r.*, COALESCE(NULLIF(u.name, \'\'), u.email) AS user FROM req_log r LEFT JOIN users u ON u.id = r.user_id'
            . (!empty($_GET['errors']) ? ' WHERE r.status >= 400' : '') . ' ORDER BY r.id DESC LIMIT 100';
        reply(200, ['rows' => $pdo->query($sql)->fetchAll()]);
    case 'dev_events':
        $get();
        need('dev');
        $rows = $pdo->query('SELECT e.*, o.name AS org FROM events e LEFT JOIN orgs o ON o.id = e.org_id ORDER BY e.id DESC LIMIT 100')->fetchAll();
        foreach ($rows as &$r) $r['diff'] = json_decode((string)$r['diff'], true);
        unset($r);
        reply(200, ['rows' => $rows]);
}
reply(404, ['error' => 'פעולה לא מוכרת']);

/* ===== עזר ===== */

/** איזו הרשאה השינוי דורש: תיק חדש או שינוי בפרטים/תשלומים/מסמכים — case.edit; רק בדיקות, הערות, מיקום קבר — case.track. */
function caseChangeNeeds(?array $old, array $new): string {
    if ($old === null) return 'case.edit';
    foreach ($new as $k => $v) {
        if (in_array($k, TRACK_FIELDS, true) || $k === 'manual_fields' || $k === 'id') continue;
        $before = $old[$k] ?? (is_array($v) ? [] : '');
        // השוואה מחמירה מול התיק הקודם אחרי normalize() — אותם טיפוסים, כך ש-null→0 או "100"→"1e2" נתפסים כשינוי
        if ($before !== $v) return 'case.edit';
    }
    return 'case.track';
}
function memberRole(int $org, int $uid): ?string {
    $st = db()->prepare('SELECT role FROM members WHERE org_id = ? AND user_id = ?');
    $st->execute([$org, $uid]);
    $r = $st->fetchColumn();
    return $r === false ? null : (string)$r;
}
function adminCount(int $org): int {
    $st = db()->prepare("SELECT COUNT(*) FROM members m JOIN users u ON u.id = m.user_id WHERE m.org_id = ? AND m.role = 'admin' AND m.active = 1 AND u.active = 1");
    $st->execute([$org]);
    return (int)$st->fetchColumn();
}
function membersOf(int $org): array {
    $st = db()->prepare('SELECT u.id, u.name, u.email, m.active, u.last_seen_at, m.role FROM members m JOIN users u ON u.id = m.user_id WHERE m.org_id = ? ORDER BY u.name, u.email');
    $st->execute([$org]);
    $members = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email'], 'active' => (int)$r['active'] === 1,
        'role' => $r['role'], 'last_seen_at' => $r['last_seen_at'], 'me' => (int)$r['id'] === ctx()['user']['id']], $st->fetchAll());
    $st = db()->prepare('SELECT id, email, role, created_at FROM invites WHERE org_id = ? AND used_at IS NULL ORDER BY id DESC');
    $st->execute([$org]);
    return ['members' => $members, 'invites' => $st->fetchAll(), 'roles' => ROLES];
}
function allOrgs(): array {
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'created_at' => $r['created_at'], 'members' => (int)$r['members'], 'cases' => (int)$r['cases']],
        db()->query('SELECT o.*, (SELECT COUNT(*) FROM members m WHERE m.org_id = o.id) AS members, (SELECT COUNT(*) FROM funerals f WHERE f.org_id = o.id) AS cases FROM orgs o ORDER BY o.id')->fetchAll());
}
/** בדיקות התקינות של מסך המפתח (אפיון 01 §2.4.4). כל בדיקה — ok, ופירוט קצר. */
function healthChecks(PDO $pdo): array {
    $c = [];
    $add = function (string $key, string $label, bool $ok, string $detail = '') use (&$c) { $c[] = compact('key', 'label', 'ok', 'detail'); };
    try { $pdo->exec('BEGIN IMMEDIATE'); $pdo->exec('ROLLBACK'); $w = true; } catch (Throwable $e) { $w = false; }
    $add('db', 'המסד נפתח ונכתב', $w && is_writable(dataDir() . '/funerals.sqlite'), (int)$pdo->query('SELECT COUNT(*) FROM funerals')->fetchColumn() . ' תיקים');
    $add('data_dir', 'data/ ניתנת לכתיבה', is_writable(dataDir()));
    $add('backup_dir', 'data/backup/ ניתנת לכתיבה', is_dir(backupDir()) && is_writable(backupDir()));
    $v = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    $add('schema', 'גרסת סכמה', $v === SCHEMA_VERSION, $v . ' (נדרש ' . SCHEMA_VERSION . ')');
    $add('version', 'גרסת האפליקציה', true, VERSION);
    $kp = secretKeyPath();
    $selfTest = secretSelfTest();   // קודם — יוצר את המפתח בשימוש הראשון
    clearstatcache();
    $mode = is_file($kp) ? substr(sprintf('%o', fileperms($kp)), -3) : '';
    $add('secret_key', 'secret.key קיים ותקין', is_file($kp) && $mode === '600' && $selfTest, $mode !== '' ? 'הרשאות ' . $mode : 'חסר');
    $add('sodium', 'sodium', extension_loaded('sodium'));
    $add('pdo_sqlite', 'pdo_sqlite', extension_loaded('pdo_sqlite'), (string)$pdo->query('SELECT sqlite_version()')->fetchColumn());
    $files = glob(backupDir() . '/*.sqlite') ?: [];
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    $add('backup', 'הגיבוי האחרון', (bool)$files, $files ? basename($files[0]) . ' · ' . gmdate('c', (int)filemtime($files[0])) : 'אין גיבוי');
    return ['checks' => $c, 'php' => PHP_VERSION, 'external' => [
        ['name' => 'Dropbox', 'state' => 'פרוסה 03'],
        ['name' => 'API חיצוני (GPT)', 'state' => 'פרוסה 05'],
        ['name' => 'AI מובנה', 'state' => 'פרוסה 06'],
    ]];
}
