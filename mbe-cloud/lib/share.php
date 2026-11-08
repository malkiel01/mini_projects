<?php
/**
 * שלב 2 — שיתוף לקריאה, חשבון שירות ואסימון אישי (PAT). האפיון: docs/spec-2-share-token.md.
 *
 * - שיתוף: צומת (תיקייה או קובץ) לחשבון אחר, תפקיד 'read' בלבד, ביטול מיידי.
 * - חשבון שירות: accounts.kind = 'service'. אין לו כניסה מהשער, אין לו כונן (quota 0).
 * - PAT: "mbe_pat_<id>_<secret>". במסד נשמר רק sha256 של הסוד. ההיקף היחיד: shared.read.
 * - הרשאה: נבדקת מחדש בכל קריאה (sharedNode), בלי מטמון. תמיד 404 ולא 403 על פריט שאינו נגיש.
 * - 429: 10 כישלונות (401) מאותה כתובת ב-10 דקות → חסימה של 15 דקות מהכישלון העשירי, רק לבקשות עם PAT.
 */

declare(strict_types=1);

require_once __DIR__ . '/drive.php';

const PAT_PREFIX = 'mbe_pat_';
const PAT_ACTIONS = ['shared_roots', 'list', 'stat', 'read'];   // רשימה סגורה: כל פעולה אחרת עם PAT → 403
const PAT_SCOPE = ['shared.read'];
const PAT_FAIL_LIMIT = 10;
const PAT_FAIL_WINDOW = 600;
const PAT_BLOCK = 900;
const PAT_LOG_DAYS = 90;
const PAT_DEFAULT_DAYS = 90;

function migrateShare(PDO $pdo): void {
    $cols = array_column($pdo->query('PRAGMA table_info(accounts)')->fetchAll(), 'name');
    if (!in_array('kind', $cols, true)) $pdo->exec("ALTER TABLE accounts ADD COLUMN kind TEXT NOT NULL DEFAULT 'person'");
    if (!in_array('owner', $cols, true)) $pdo->exec('ALTER TABLE accounts ADD COLUMN owner TEXT');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS shares (
            id          TEXT PRIMARY KEY,
            node        TEXT NOT NULL REFERENCES nodes(id),
            owner       TEXT NOT NULL REFERENCES accounts(id),
            grantee     TEXT NOT NULL REFERENCES accounts(id),
            role        TEXT NOT NULL CHECK (role IN ('read')),
            created_at  TEXT NOT NULL,
            created_by  TEXT NOT NULL,
            revoked_at  TEXT
        );
        CREATE INDEX IF NOT EXISTS shares_grantee ON shares(grantee, revoked_at);
        CREATE INDEX IF NOT EXISTS shares_node    ON shares(node, revoked_at);
        CREATE TABLE IF NOT EXISTS tokens (
            id          TEXT PRIMARY KEY,
            account     TEXT NOT NULL REFERENCES accounts(id),
            name        TEXT NOT NULL,
            hash        TEXT NOT NULL,
            scope       TEXT NOT NULL,
            created_at  TEXT NOT NULL,
            created_by  TEXT NOT NULL,
            expires_at  TEXT,
            used_at     TEXT,
            revoked_at  TEXT
        );
        CREATE TABLE IF NOT EXISTS token_log (
            id      INTEGER PRIMARY KEY,
            token   TEXT,
            action  TEXT NOT NULL,
            node    TEXT,
            ip      TEXT NOT NULL,
            status  INTEGER NOT NULL,
            at      TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS token_log_tok ON token_log(token, at);
        CREATE INDEX IF NOT EXISTS token_log_ip  ON token_log(ip, status, at);
    ");
}

// ─── האסימון בבקשה ────────────────────────────────────────────────────────

/** האסימון שבכותרת (Authorization / X-Cloud-Token), כפי שהוא. '' — אין. */
function requestBearer(): string {
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    return preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : trim((string) ($_SERVER['HTTP_X_CLOUD_TOKEN'] ?? ''));
}

/** אסימון בכתובת — נדחה ב-400 ולא מתעלמים ממנו, כדי שמי שטעה ישמע על זה מיד (וכדי שלא יישמר ביומני גישה בשקט). */
function rejectTokenInUrl(): void {
    foreach (['token', 'access_token'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') throw new AppError('אסימון נשלח רק בכותרת Authorization, לא בכתובת', 400, 'token_in_url');
    }
}

function isPat(string $token): bool {
    return str_starts_with($token, PAT_PREFIX);
}

/** REMOTE_ADDR בלבד — X-Forwarded-For לא נחשב (אין פרוקסי לפני השרת, ומי שמבחוץ היה מזייף אותו). */
function clientIp(): string {
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

// ─── יומן ו-429 ───────────────────────────────────────────────────────────

/** ההקשר של בקשת ה-PAT הנוכחית: מה כבר נרשם, ואיזה צומת. api.php רושם את הסיום (patLogFinish). */
function &patContext(): array {
    static $ctx = ['token' => null, 'action' => '', 'node' => null, 'logged' => true];
    return $ctx;
}

function patLog(?string $token, string $action, ?string $node, int $status): void {
    db()->prepare('INSERT INTO token_log (token, action, node, ip, status, at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$token, mb_substr($action, 0, 40), $node, clientIp(), $status, nowIso()]);
    $ctx = &patContext();
    $ctx['logged'] = true;
}

/** סוף הבקשה (או AppError): רושם את הקוד שהוחזר, פעם אחת. */
function patLogFinish(int $status): void {
    $ctx = &patContext();
    if ($ctx['logged']) return;
    patLog($ctx['token'], $ctx['action'], $ctx['node'], $status);
}

/** שניות עד שהחסימה של הכתובת נגמרת, או 0. */
function patBlockedFor(string $ip): int {
    $now = time();
    $st = db()->prepare('SELECT at FROM token_log WHERE ip = ? AND status = 401 AND at >= ? ORDER BY at');
    $st->execute([$ip, nowIso($now - PAT_FAIL_WINDOW - PAT_BLOCK)]);
    $ts = array_map('strtotime', $st->fetchAll(PDO::FETCH_COLUMN));
    $until = 0;
    for ($i = PAT_FAIL_LIMIT - 1; $i < count($ts); $i++) {
        if ($ts[$i] - $ts[$i - PAT_FAIL_LIMIT + 1] <= PAT_FAIL_WINDOW) $until = max($until, $ts[$i] + PAT_BLOCK);
    }
    return max(0, $until - $now);
}

/** ניקוי עצל של היומן: מעל 90 יום. לכל היותר פעם בשעה. */
function purgeTokenLogMaybe(): void {
    if ((int) setting('last_token_log_purge') > time() - 3600) return;
    setSetting('last_token_log_purge', (string) time());
    db()->prepare('DELETE FROM token_log WHERE at < ?')->execute([nowIso(time() - PAT_LOG_DAYS * 86400)]);
}

// ─── בדיקת PAT ────────────────────────────────────────────────────────────

/**
 * הגורם של בקשה עם PAT: 429 → צורה → שורה → hash_equals → תוקף → חשבון → פעולה ברשימה הסגורה.
 * כל כישלון נרשם ב-token_log עם הקוד שלו. הסוד לא נרשם בשום מקום.
 * מחזיר ['account', 'app' => 'pat:<id>', 'pat' => row, 'scope' => ['shared.read'], 'client' => null].
 */
function patActor(string $action, string $token): array {
    static $cache = [];
    $key = $action . "\0" . hash('sha256', $token);
    if (isset($cache[$key])) return $cache[$key];

    purgeTokenLogMaybe();
    $ctx = &patContext();
    $ctx = ['token' => null, 'action' => $action, 'node' => null, 'logged' => false];

    $id = preg_match('/^mbe_pat_([a-z0-9]{8})_([A-Za-z0-9_-]{43})$/', $token, $m) ? $m[1] : null;
    $secret = $id !== null ? $m[2] : '';
    $row = null;
    if ($id !== null) {
        $st = db()->prepare('SELECT * FROM tokens WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch() ?: null;
    }

    if (($wait = patBlockedFor(clientIp())) > 0) {
        // השורה נכנסת ליומן של בעל האסימון אם הוא קיים — כתובת משותפת (פרוקסי יציאה) תיראה שם
        patLog($row['id'] ?? null, $action, null, 429);
        if (!headers_sent()) header('Retry-After: ' . $wait);
        throw new AppError('יותר מדי ניסיונות שגויים מהכתובת הזו. נסו שוב מאוחר יותר', 429, 'rate_limited', ['retry_after' => $wait]);
    }

    $fail = function () use ($row, $action): never {
        patLog($row['id'] ?? null, $action, null, 401);
        throw new AppError('האסימון לא תקף', 401, 'unauthorized');
    };
    if ($id === null) $fail();
    // שורה שלא קיימת — עדיין hash_equals מול ערך קבוע, כדי שהזמן לא יגלה אם ה-id קיים
    $ok = hash_equals($row['hash'] ?? str_repeat('0', 64), hash('sha256', $secret));
    if (!$row || !$ok) $fail();
    if ($row['revoked_at'] !== null || ($row['expires_at'] !== null && $row['expires_at'] <= nowIso())) $fail();
    $st = db()->prepare('SELECT * FROM accounts WHERE id = ?');
    $st->execute([$row['account']]);
    $a = $st->fetch();
    if (!$a || (int) $a['blocked'] === 1 || ($a['kind'] ?? 'person') !== 'service') $fail();

    $ctx['token'] = $row['id'];
    if (!in_array($action, PAT_ACTIONS, true)) {
        patLog($row['id'], $action, null, 403);
        throw new AppError('האסימון מאפשר רק קריאה של מה ששותף', 403, 'forbidden');
    }
    if ($row['used_at'] === null || $row['used_at'] < nowIso(time() - 600)) {
        db()->prepare('UPDATE tokens SET used_at = ? WHERE id = ?')->execute([nowIso(), $row['id']]);
    }
    return $cache[$key] = ['account' => $a, 'app' => 'pat:' . $row['id'], 'pat' => $row, 'scope' => PAT_SCOPE, 'client' => null];
}

// ─── הרשאה: האם הצומת נגיש למקבל ─────────────────────────────────────────

/**
 * [node, root] — הצומת, ושורש השיתוף שדרכו הוא נגיש. כל מקרה של "לא נגיש" → אותו 404, באותה הודעה:
 * לא קיים, לא שותף, שיתוף שבוטל, הועבר החוצה, בסל (בכל מקום בשרשרת), בעלים חסום.
 */
function sharedNode(array $grantee, mixed $id): array {
    $notFound = fn() => new AppError('לא נמצא', 404, 'not_found');
    if (!is_string($id) || $id === '') throw $notFound();
    $get = db()->prepare('SELECT * FROM nodes WHERE id = ?');
    $has = db()->prepare('SELECT id FROM shares WHERE node = ? AND grantee = ? AND revoked_at IS NULL LIMIT 1');
    $get->execute([$id]);
    $n = $get->fetch();
    if (!$n) throw $notFound();
    $root = null;
    for ($cur = $n, $guard = 0; $cur && $guard < 1000; $guard++) {
        if ($cur['trashed_at'] !== null) throw $notFound();
        if ($root === null) {
            $has->execute([$cur['id'], $grantee['id']]);
            if ($has->fetchColumn()) $root = $cur;
        }
        if ($cur['parent'] === null) break;
        $get->execute([$cur['parent']]);
        $cur = $get->fetch();
    }
    // השרשרת נבדקת עד השורש גם אחרי שנמצא שיתוף — תיקייה בסל מעל השיתוף מסתירה הכול
    if ($root === null) throw $notFound();
    $st = db()->prepare('SELECT blocked FROM accounts WHERE id = ?');
    $st->execute([$n['account']]);
    if ((int) $st->fetchColumn() === 1) throw $notFound();
    $ctx = &patContext();
    $ctx['node'] = $n['id'];
    return [$n, $root];
}

/** הצומת כפי שמקבל השיתוף רואה אותו: בלי ההורה של שורש השיתוף, ובלי שם הבעלים ב-updated_by. */
function sharedPublic(array $n, array $root): array {
    $p = publicNode($n);
    if ($n['id'] === $root['id']) $p['parent'] = null;
    $p['updated_by'] = ['name' => '', 'app' => $p['updated_by']['app']];
    unset($p['trashed_at']);
    return $p;
}

function sharedStat(array $grantee, mixed $id): array {
    [$n, $root] = sharedNode($grantee, $id);
    return sharedPublic($n, $root);
}

function sharedList(array $grantee, mixed $folder): array {
    if (!is_string($folder) || $folder === '') throw new AppError('חסר folder — הרשימה של מה ששותף: shared_roots', 400, 'bad_request');
    [$f, $root] = sharedNode($grantee, $folder);
    if ($f['kind'] !== 'folder') throw new AppError('זו אינה תיקייה', 400, 'bad_request');
    // הנתיב — משורש השיתוף בלבד. שמות התיקיות של הבעלים מעליו לא נחשפים
    $path = [];
    $get = db()->prepare('SELECT * FROM nodes WHERE id = ?');
    for ($cur = $f, $guard = 0; $cur && $guard < 1000; $guard++) {
        array_unshift($path, ['id' => $cur['id'], 'name' => $cur['name']]);
        if ($cur['id'] === $root['id'] || $cur['parent'] === null) break;
        $get->execute([$cur['parent']]);
        $cur = $get->fetch();
    }
    $st = db()->prepare("SELECT * FROM nodes WHERE parent = ? AND trashed_at IS NULL ORDER BY kind = 'file', name COLLATE NOCASE");
    $st->execute([$f['id']]);
    return ['folder' => $f['id'], 'path' => $path, 'items' => array_map(fn($c) => sharedPublic($c, $root), $st->fetchAll())];
}

function sharedReadTarget(array $grantee, mixed $id): array {
    [$n] = sharedNode($grantee, $id);
    if ($n['kind'] !== 'file') throw new AppError('זו תיקייה', 400, 'bad_request');
    $path = blobPath($n['sha256']);
    if (!is_file($path)) throw new AppError('תוכן הקובץ חסר בשרת', 500, 'server');
    return [$path, $n];
}

/** "שותף איתי": השיתופים הפעילים, רק כשהצומת לא בסל והבעלים לא חסום. */
function sharedRoots(array $grantee): array {
    $st = db()->prepare("SELECT s.id AS share_id, s.created_at AS shared_at, n.*, o.name AS owner_name
                         FROM shares s JOIN nodes n ON n.id = s.node JOIN accounts o ON o.id = s.owner
                         WHERE s.grantee = ? AND s.revoked_at IS NULL AND o.blocked = 0 ORDER BY n.name COLLATE NOCASE");
    $st->execute([$grantee['id']]);
    $items = [];
    $seen = [];
    foreach ($st->fetchAll() as $r) {
        if (isset($seen[$r['id']]) || isTrashed($r)) continue;
        $seen[$r['id']] = true;
        $p = sharedPublic($r, $r);
        $items[] = ['id' => $p['id'], 'name' => $p['name'], 'kind' => $p['kind'], 'size' => $p['size'] ?? null, 'mime' => $p['mime'] ?? null,
                    'updated_at' => $p['updated_at'],
                    'share' => ['id' => $r['share_id'], 'owner_name' => $r['owner_name'], 'created_at' => $r['shared_at']]];
    }
    return ['items' => $items];
}

// ─── הבעלים: שיתוף מהדף של הענן ───────────────────────────────────────────

function grantRow(array $sh): array {
    return ['id' => $sh['id'], 'node' => $sh['node'], 'role' => $sh['role'], 'created_at' => $sh['created_at'],
            'grantee' => ['id' => $sh['grantee'], 'name' => $sh['g_name'], 'email' => ($sh['g_kind'] === 'service' ? '' : $sh['g_email']),
                          'kind' => $sh['g_kind']]];
}

/**
 * grantee: מייל של חשבון קיים, או id של חשבון שירות. לחשבון שירות משתף רק הבעלים שלו או מנהל הענן.
 * כל סירוב בגלל המקבל — אותה הודעה ("לא קיים", "חסום", "שירות של מישהו אחר"): אי אפשר לברר כך אילו מיילים רשומים.
 */
function shareCreate(array $a, array $in): array {
    $n = liveNode($a, $in['node'] ?? null);
    $g = trim((string) ($in['grantee'] ?? ''));
    $refuse = fn() => new AppError('אי אפשר לשתף עם החשבון הזה', 403, 'forbidden');
    if ($g === '') throw $refuse();
    $st = str_contains($g, '@')
        ? db()->prepare("SELECT * FROM accounts WHERE email = ? AND kind = 'person'")
        : db()->prepare("SELECT * FROM accounts WHERE id = ? AND kind = 'service'");
    $st->execute([mb_strtolower($g)]);
    $t = $st->fetch();
    if (!$t || $t['id'] === $a['id'] || (int) $t['blocked'] === 1) throw $refuse();
    if ($t['kind'] === 'service' && $t['owner'] !== $a['id'] && $a['role'] !== 'admin') throw $refuse();

    $ex = db()->prepare('SELECT id FROM shares WHERE node = ? AND grantee = ? AND revoked_at IS NULL');
    $ex->execute([$n['id'], $t['id']]);
    if (!($id = $ex->fetchColumn())) {
        $id = 'sh_' . substr(randomId(''), 0, 16);
        db()->prepare("INSERT INTO shares (id, node, owner, grantee, role, created_at, created_by) VALUES (?, ?, ?, ?, 'read', ?, ?)")
            ->execute([$id, $n['id'], $a['id'], $t['id'], nowIso(), $a['id']]);
    }
    return ['share' => shareById((string) $id)];
}

function shareById(string $id): array {
    $st = db()->prepare('SELECT s.*, g.name AS g_name, g.email AS g_email, g.kind AS g_kind FROM shares s JOIN accounts g ON g.id = s.grantee WHERE s.id = ?');
    $st->execute([$id]);
    return grantRow($st->fetch());
}

function shareList(array $a, mixed $node): array {
    $sql = 'SELECT s.*, g.name AS g_name, g.email AS g_email, g.kind AS g_kind FROM shares s JOIN accounts g ON g.id = s.grantee
            WHERE s.owner = ? AND s.revoked_at IS NULL';
    $args = [$a['id']];
    if (is_string($node) && $node !== '') { $sql .= ' AND s.node = ?'; $args[] = $node; }
    $st = db()->prepare($sql . ' ORDER BY s.created_at');
    $st->execute($args);
    return ['items' => array_map('grantRow', $st->fetchAll())];
}

/** חשבונות השירות שמותר לי לשתף איתם — שלי, או כולם למנהל הענן. לגיליון השיתוף. */
function shareableServices(array $a): array {
    $st = $a['role'] === 'admin'
        ? db()->prepare("SELECT id, name FROM accounts WHERE kind = 'service' AND blocked = 0 AND ? <> '' ORDER BY name COLLATE NOCASE")
        : db()->prepare("SELECT id, name FROM accounts WHERE kind = 'service' AND blocked = 0 AND owner = ? ORDER BY name COLLATE NOCASE");
    $st->execute([$a['id']]);
    return $st->fetchAll();
}

/** הצמתים שלי ששותפו — לסימון 👥 בעץ. */
function sharedNodeIds(array $a): array {
    $st = db()->prepare('SELECT DISTINCT node FROM shares WHERE owner = ? AND revoked_at IS NULL');
    $st->execute([$a['id']]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

function shareRevoke(array $a, mixed $id): array {
    $st = db()->prepare('SELECT id FROM shares WHERE id = ? AND owner = ?');
    $st->execute([is_string($id) ? $id : '', $a['id']]);
    if (!$st->fetchColumn()) throw new AppError('לא נמצא', 404, 'not_found');
    db()->prepare('UPDATE shares SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL')->execute([nowIso(), $id]);
    return ['ok' => true];
}

// ─── מנהל הענן: חשבונות שירות ואסימונים ───────────────────────────────────

function serviceRow(string $id): array {
    $st = db()->prepare("SELECT * FROM accounts WHERE id = ? AND kind = 'service'");
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s) throw new AppError('חשבון השירות לא נמצא', 404, 'not_found');
    return $s;
}

function serviceCreate(array $admin, array $in): array {
    $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 60);
    if ($name === '') throw new AppError('חסר שם לחשבון השירות', 400, 'bad_request');
    $st = db()->prepare("SELECT * FROM accounts WHERE email = ? AND kind = 'person'");
    $st->execute([mb_strtolower(trim((string) ($in['owner'] ?? '')))]);
    $owner = $st->fetch();
    if (!$owner) throw new AppError('הבעלים צריך להיות חשבון קיים בענן (לפי מייל)', 400, 'bad_request');
    $id = randomId('a_');
    db()->prepare("INSERT INTO accounts (id, email, name, role, quota, kind, owner, created_at) VALUES (?, ?, ?, 'user', 0, 'service', ?, ?)")
        ->execute([$id, 'svc_' . $id . '@service.invalid', $name, $owner['id'], nowIso()]);
    return serviceList();
}

function serviceList(): array {
    $rows = db()->query("SELECT s.*, o.name AS owner_name, o.email AS owner_email FROM accounts s LEFT JOIN accounts o ON o.id = s.owner
                         WHERE s.kind = 'service' ORDER BY s.name COLLATE NOCASE")->fetchAll();
    $sh = db()->prepare('SELECT s.id, s.node, s.created_at, n.name AS node_name, n.kind AS node_kind, o.name AS owner_name
                         FROM shares s JOIN nodes n ON n.id = s.node JOIN accounts o ON o.id = s.owner
                         WHERE s.grantee = ? AND s.revoked_at IS NULL ORDER BY n.name');
    $out = [];
    foreach ($rows as $r) {
        $sh->execute([$r['id']]);
        $out[] = ['id' => $r['id'], 'name' => $r['name'], 'created_at' => $r['created_at'], 'blocked' => (int) $r['blocked'] === 1,
                  'owner' => ['id' => $r['owner'], 'name' => $r['owner_name'] ?? '', 'email' => $r['owner_email'] ?? ''],
                  'tokens' => tokenList($r['id'])['items'], 'shares' => $sh->fetchAll()];
    }
    return ['services' => $out];
}

/** מבטל את כל האסימונים ואת כל השיתופים איתו, ואז מוחק. היומן נשאר (בלי FK), למעקב. */
function serviceDelete(mixed $id): array {
    $s = serviceRow(is_string($id) ? $id : '');
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM tokens WHERE account = ?')->execute([$s['id']]);
    $pdo->prepare('DELETE FROM shares WHERE grantee = ?')->execute([$s['id']]);
    $pdo->prepare('DELETE FROM accounts WHERE id = ?')->execute([$s['id']]);
    $pdo->commit();
    return serviceList();
}

/** התשובה היחידה שבה מופיע הסוד. expires_days: מספר ימים (1–365), או null — רק לחשבון שירות (הכרעה 7). */
function tokenCreate(array $admin, array $in): array {
    $s = serviceRow((string) ($in['account'] ?? ''));   // אסימון לאדם — לא בשלב הזה (האפיון, סעיף 1)
    $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 80);
    if ($name === '') throw new AppError('חסר שם לאסימון', 400, 'bad_request');
    $days = array_key_exists('expires_days', $in) ? $in['expires_days'] : PAT_DEFAULT_DAYS;
    if ($days !== null && (!is_int($days) || $days < 1 || $days > 365)) throw new AppError('תוקף לא תקין (1–365 ימים, או ללא תפוגה)', 400, 'bad_request');
    $abc = 'abcdefghijklmnopqrstuvwxyz0123456789';
    do {
        $id = '';
        foreach (str_split(random_bytes(8)) as $b) $id .= $abc[ord($b) % 36];
        $st = db()->prepare('SELECT 1 FROM tokens WHERE id = ?');
        $st->execute([$id]);
    } while ($st->fetchColumn());
    $secret = b64u(random_bytes(32));   // 43 תווים
    $expires = $days === null ? null : nowIso(time() + $days * 86400);
    db()->prepare('INSERT INTO tokens (id, account, name, hash, scope, created_at, created_by, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, $s['id'], $name, hash('sha256', $secret), json_encode(PAT_SCOPE), nowIso(), $admin['id'], $expires]);
    return ['token' => PAT_PREFIX . $id . '_' . $secret, 'id' => $id, 'expires_at' => $expires];
}

function tokenPublic(array $t): array {
    return ['id' => $t['id'], 'account' => $t['account'], 'name' => $t['name'], 'hint' => PAT_PREFIX . $t['id'] . '_…',
            'scope' => json_decode($t['scope'], true), 'created_at' => $t['created_at'], 'expires_at' => $t['expires_at'],
            'used_at' => $t['used_at'], 'revoked_at' => $t['revoked_at'],
            'active' => $t['revoked_at'] === null && ($t['expires_at'] === null || $t['expires_at'] > nowIso())];
}

function tokenList(mixed $account = null): array {
    if (is_string($account) && $account !== '') {
        $st = db()->prepare('SELECT * FROM tokens WHERE account = ? ORDER BY created_at DESC');
        $st->execute([$account]);
    } else {
        $st = db()->query('SELECT * FROM tokens ORDER BY created_at DESC');
    }
    return ['items' => array_map('tokenPublic', $st->fetchAll())];
}

function tokenRevoke(mixed $id): array {
    $st = db()->prepare('SELECT id FROM tokens WHERE id = ?');
    $st->execute([is_string($id) ? $id : '']);
    if (!$st->fetchColumn()) throw new AppError('לא נמצא', 404, 'not_found');
    db()->prepare('UPDATE tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL')->execute([nowIso(), $id]);
    return ['ok' => true];
}

/** 100 השורות האחרונות של אסימון, עם שם הצומת. cursor — ה-id האחרון שהתקבל (דפדוף אחורה). */
function tokenLog(mixed $token, mixed $cursor = null): array {
    $args = [is_string($token) ? $token : ''];
    $sql = 'SELECT l.*, n.name AS node_name FROM token_log l LEFT JOIN nodes n ON n.id = l.node WHERE l.token = ?';
    if (is_numeric($cursor)) { $sql .= ' AND l.id < ?'; $args[] = (int) $cursor; }
    $st = db()->prepare($sql . ' ORDER BY l.id DESC LIMIT 100');
    $st->execute($args);
    $rows = array_map(fn($r) => ['id' => (int) $r['id'], 'action' => $r['action'], 'node' => $r['node'], 'node_name' => $r['node_name'],
        'ip' => $r['ip'], 'status' => (int) $r['status'], 'at' => $r['at']], $st->fetchAll());
    return ['items' => $rows, 'cursor' => count($rows) === 100 ? end($rows)['id'] : null];
}
