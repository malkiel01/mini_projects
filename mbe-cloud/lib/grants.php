<?php
/**
 * ענן MBE — שלב 3: היקפי קבצים. האפיון: docs/spec-3-files-scopes.md.
 *
 *   files           — כל הכונן, קריאה וכתיבה (כמו קודם). דורש אישור מנהל (files_full, הכרעה 5).
 *   files.readonly  — כל הכונן, קריאה בלבד. כתיבה — 403 read_only.
 *   files.app       — רק מה שהוענק לאפליקציה: מה שיצרה (created) ומה שהמשתמש בחר בבורר של הענן (picked).
 *                     הענקה על תיקייה חלה על כל מה שבתוכה. מחוץ להענקה — 404, אף פעם לא 403.
 * הענקת created נרשמת בכל יצירה, בכל רמה — כך אפליקציה שתצמצם ל-files.app ממשיכה לראות את מה שיצרה.
 */

declare(strict_types=1);

require_once __DIR__ . '/drive.php';

const FILES_SCOPES = ['files' => 'full', 'files.readonly' => 'readonly', 'files.app' => 'app'];
const FILES_WRITES = ['mkdir', 'write', 'upload_start', 'upload_chunk', 'upload_finish', 'rename', 'move', 'trash', 'restore'];

function migrateGrants(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grants (
            client      TEXT NOT NULL,
            account     TEXT NOT NULL,
            node        TEXT NOT NULL REFERENCES nodes(id),
            via         TEXT NOT NULL CHECK (via IN ('created','picked')),
            created_at  TEXT NOT NULL,
            PRIMARY KEY (client, account, node)
        );
        CREATE INDEX IF NOT EXISTS grants_node ON grants(node);
        -- התיקייה של כל אפליקציה אצל כל משתמש (כתיבה בלי parent ב-files.app). לפי id — שינוי שם או העברה לא יוצרים חדשה
        CREATE TABLE IF NOT EXISTS appfolders (client TEXT NOT NULL, account TEXT NOT NULL, node TEXT NOT NULL, PRIMARY KEY (client, account));
    ");
    // files_full — "📁 גישה לכל הכונן", באישור מנהל. הגירה: רק שלוש אפליקציות החבילה (שהיו רשומות לפני שלב 3) — 1.
    // לקוח אחר שכבר רשום (למשל "אסטרטגיות") נשאר 0 — files המלא שלו רק באישור מנהל
    $cols = array_column($pdo->query('PRAGMA table_info(clients)')->fetchAll(), 'name');
    if (!in_array('files_full', $cols, true)) {
        $pdo->exec('ALTER TABLE clients ADD COLUMN files_full INTEGER NOT NULL DEFAULT 0');
        $pdo->exec("UPDATE clients SET files_full = 1 WHERE id IN ('greeting-cards','layout-studio','raster-studio')");
    }
}

// ─── ההצהרה של הלקוח ─────────────────────────────────────────────────────

/** ההיקף של שכבת הקבצים שהלקוח הצהיר ב-drives/apps.json (files_scope). בלי — files, כמו לפני שלב 3. */
function clientFilesScope(string $clientId): string {
    static $file = null;
    $file ??= json_decode((string) @file_get_contents(__DIR__ . '/../drives/apps.json'), true) ?: [];
    $apps = $GLOBALS['CLOUD_APPS'] ?? $file;
    $s = $apps[$clientId]['files_scope'] ?? 'files';
    return isset(FILES_SCOPES[$s]) ? $s : 'files';
}

/** לקוח שמצהיר files המלא בלי אישור מנהל — pending (הכרעה 5). */
function clientNeedsFullApproval(string $clientId): bool {
    if (!in_array('files', clientScopes($clientId), true)) return false;
    $c = getClient($clientId);
    return !$c || (int) ($c['files_full'] ?? 0) !== 1;
}

// ─── הענקות ──────────────────────────────────────────────────────────────

function grantAdd(string $client, string $account, string $node, string $via): void {
    db()->prepare('INSERT OR IGNORE INTO grants (client, account, node, via, created_at) VALUES (?,?,?,?,?)')
        ->execute([$client, $account, $node, $via, nowIso()]);
}

/** שורש ההענקה שמכסה את הצומת (הוא עצמו או הורה), או null. כולל פריטים בסל — restore צריך אותם. */
function grantRootOf(string $client, array $a, array $n): ?string {
    $st = db()->prepare('SELECT 1 FROM grants WHERE client = ? AND account = ? AND node = ?');
    for ($guard = 0; $n && $guard < 1000; $guard++) {
        $st->execute([$client, $a['id'], $n['id']]);
        if ($st->fetchColumn()) return $n['id'];
        if ($n['parent'] === null) return null;
        $n = db()->query('SELECT * FROM nodes WHERE id = ' . db()->quote($n['parent']))->fetch() ?: null;
    }
    return null;
}

/** הצומת, רק אם הוא בתוך הענקה של האפליקציה. אחרת 404 — בדיוק כמו פריט שלא קיים. */
function grantedNode(array $x, mixed $id): array {
    $n = nodeRow($x['account'], $id);   // 404 אם לא קיים או של חשבון אחר
    if (grantRootOf($x['app'], $x['account'], $n) === null) throw new AppError('לא נמצא', 404, 'not_found');
    return $n;
}

/** תיקיית האפליקציה בשורש הכונן — "📁 <שם האפליקציה>". נוצרת בפעם הראשונה; אחר כך לפי id (גם אם שונה שמה או הועברה). */
function appFolder(array $x): string {
    $st = db()->prepare('SELECT n.* FROM appfolders f JOIN nodes n ON n.id = f.node WHERE f.client = ? AND f.account = ?');
    $st->execute([$x['app'], $x['account']['id']]);
    $n = $st->fetch();
    if ($n && $n['kind'] === 'folder' && !isTrashed($n)) return $n['id'];
    $f = makeFolder($x['account'], ['name' => (string) ($x['client']['name'] ?? $x['app'])], $x['app']);
    grantAdd($x['app'], $x['account']['id'], $f['id'], 'created');
    db()->prepare('INSERT OR REPLACE INTO appfolders (client, account, node) VALUES (?,?,?)')->execute([$x['app'], $x['account']['id'], $f['id']]);
    return $f['id'];
}

/** פריט שהאפליקציה יצרה — הענקת created (בכל רמה). */
function grantCreated(array $x, array $node): array {
    if (($x['app'] ?? '') !== '' && (int) ($node['rev'] ?? 0) === 1) grantAdd($x['app'], $x['account']['id'], $node['id'], 'created');
    return $node;
}

/** האם ההורה של הצומת מכוסה בהענקה — כלומר הצומת אינו שורש בעיני האפליקציה. */
function parentCovered(array $x, array $n): bool {
    if ($n['parent'] === null) return false;
    $p = db()->query('SELECT * FROM nodes WHERE id = ' . db()->quote($n['parent']))->fetch();
    return $p && grantRootOf($x['app'], $x['account'], $p) !== null;
}

/** publicNode בעיני האפליקציה: מעל שורש ההענקה לא נחשף — parent של השורש (ההענקה העליונה) הוא null. */
function appNode(array $x, array $n): array {
    $p = publicNode($n);
    if (!parentCovered($x, $n)) $p['parent'] = null;
    return $p;
}

/** הנתיב מהשורש העליון של ההענקה בלבד. */
function appPath(array $x, string $folder): array {
    $path = [];
    for ($id = $folder, $guard = 0; $id !== null && $guard < 1000; $guard++) {
        $n = nodeRow($x['account'], $id);
        array_unshift($path, ['id' => $n['id'], 'name' => $n['name']]);
        if (!parentCovered($x, $n)) break;
        $id = $n['parent'];
    }
    return $path;
}

/** שורשי ההענקות החיים: פריטים שהוענקו ושאף הורה שלהם לא הוענק, ממוינים לפי שם. */
function grantRoots(array $x): array {
    $st = db()->prepare('SELECT n.* FROM grants g JOIN nodes n ON n.id = g.node WHERE g.client = ? AND g.account = ?');
    $st->execute([$x['app'], $x['account']['id']]);
    $out = [];
    foreach ($st->fetchAll() as $n) {
        if (isTrashed($n)) continue;
        if ($n['parent'] !== null) {
            $p = db()->query('SELECT * FROM nodes WHERE id = ' . db()->quote($n['parent']))->fetch();
            if ($p && grantRootOf($x['app'], $x['account'], $p) !== null) continue;   // מכוסה בהענקה של הורה
        }
        $out[] = $n;
    }
    usort($out, fn($a, $b) => [$a['kind'] === 'file', mb_strtolower($a['name'])] <=> [$b['kind'] === 'file', mb_strtolower($b['name'])]);
    return $out;
}

// ─── פעולות הקבצים, לפי הרמה ───────────────────────────────────────────────

/**
 * כל פעולת קבצים של אפליקציה עוברת כאן ($x מ-actor: files_mode = full | readonly | app).
 * מחזיר את התשובה, או ['__read' => [path, node]] לקריאה (api.php שולח את הקובץ).
 */
function filesAction(string $action, array $x, array $in, array $get): mixed {
    $mode = $x['files_mode'] ?? 'full';
    $a = $x['account'];
    if ($mode === 'readonly' && (in_array($action, FILES_WRITES, true) || in_array($action, ['purge', 'trash_empty'], true))) {
        throw new AppError('לאפליקציה הזו יש גישת קריאה בלבד לקבצים', 403, 'read_only');
    }
    if ($mode !== 'app') {
        return match ($action) {
            'quota' => quotaInfo($a),
            'list' => listFolder($a, $get['folder'] ?? $in['folder'] ?? null),
            'stat' => publicNode(liveNode($a, $get['id'] ?? $in['id'] ?? null)),
            'read' => ['__read' => readTarget($a, $get['id'] ?? null)],
            'mkdir' => grantCreated($x, makeFolder($a, $in, $x['app'])),
            'write' => grantCreated($x, writeFile($a, $in, $x['app'])),
            'upload_start' => uploadStart($a, $in),
            'upload_chunk' => uploadChunk($a, $get['upload'] ?? null, (int) ($get['index'] ?? -1), 'php://input'),
            'upload_finish' => grantCreated($x, uploadFinish($a, $in['upload'] ?? null, $x['app'])),
            'rename' => renameNode($a, $in, $x['app']),
            'move' => moveNode($a, $in, $x['app']),
            'trash' => trashNode($a, $in),
            'restore' => restoreNode($a, $in),
            'trash_list' => trashList($a),
            'purge' => (function () use ($a, $in) { purgeNode($a, $in); return ['ok' => true]; })(),
            'trash_empty' => ['removed' => emptyTrash($a)],
        };
    }
    // files.app — רק בתוך הענקות
    $live = function (mixed $id) use ($x): array {
        $n = grantedNode($x, $id);
        if (isTrashed($n)) throw new AppError('לא נמצא', 404, 'not_found');
        return $n;
    };
    $parentIn = function (array $in) use ($x, $live): array {   // כתיבה בלי parent — לתיקיית האפליקציה
        $p = $in['parent'] ?? null;
        $in['parent'] = ($p === null || $p === '') ? appFolder($x) : $live($p)['id'];
        return $in;
    };
    switch ($action) {
        case 'quota': return quotaInfo($a);
        case 'list': {
            $folder = $get['folder'] ?? $in['folder'] ?? null;
            if ($folder === null || $folder === '') {
                return ['folder' => null, 'path' => [], 'items' => array_map(fn($n) => appNode($x, $n), grantRoots($x))];
            }
            $f = $live($folder);
            if ($f['kind'] !== 'folder') throw new AppError('היעד אינו תיקייה', 400, 'bad_request');
            $r = listFolder($a, $f['id']);
            $r['path'] = appPath($x, $f['id']);
            return $r;
        }
        case 'stat': return appNode($x, $live($get['id'] ?? $in['id'] ?? null));
        case 'read': { $live($get['id'] ?? null); return ['__read' => readTarget($a, $get['id'] ?? null)]; }
        case 'mkdir': return appNode($x, nodeRow($a, grantCreated($x, makeFolder($a, $parentIn($in), $x['app']))['id']));
        case 'write': return appNode($x, nodeRow($a, grantCreated($x, writeFile($a, $parentIn($in), $x['app']))['id']));
        case 'upload_start': return uploadStart($a, $parentIn($in));
        case 'upload_chunk': return uploadChunk($a, $get['upload'] ?? null, (int) ($get['index'] ?? -1), 'php://input');
        case 'upload_finish': return appNode($x, nodeRow($a, grantCreated($x, uploadFinish($a, $in['upload'] ?? null, $x['app']))['id']));
        case 'rename': $live($in['id'] ?? null); return appNode($x, nodeRow($a, renameNode($a, $in, $x['app'])['id']));
        case 'move': {
            $live($in['id'] ?? null);
            $to = $in['parent'] ?? null;
            if ($to === null || $to === '') throw new AppError('לא נמצא', 404, 'not_found');   // שורש הכונן — מחוץ להענקה
            $live($to);
            return appNode($x, nodeRow($a, moveNode($a, $in, $x['app'])['id']));
        }
        case 'trash': $live($in['id'] ?? null); return appNode($x, nodeRow($a, trashNode($a, $in)['id']));
        case 'restore': grantedNode($x, $in['id'] ?? null); return appNode($x, nodeRow($a, restoreNode($a, $in)['id']));
        case 'trash_list': {
            $r = trashList($a);
            $r['items'] = array_values(array_filter($r['items'], fn($p) => grantRootOf($x['app'], $a, nodeRow($a, $p['id'])) !== null));
            return $r;
        }
        case 'purge': case 'trash_empty':
            throw new AppError('מחיקה לצמיתות — רק בדף הענן', 403, 'forbidden');
    }
    throw new AppError('פעולה לא מוכרת', 404, 'unknown_action');
}

// ─── "🔗 חיבורים" — מה האפליקציה רואה ──────────────────────────────────────

/** שורשי ההענקות של אפליקציה אצל בעל החשבון, עם הסוג. */
function grantsList(array $a, mixed $client): array {
    if (!is_string($client) || $client === '') throw new AppError('חסר לקוח', 400, 'bad_request');
    $x = ['app' => $client, 'account' => $a];
    $via = db()->prepare('SELECT via, created_at FROM grants WHERE client = ? AND account = ? AND node = ?');
    $items = [];
    foreach (grantRoots($x) as $n) {
        $via->execute([$client, $a['id'], $n['id']]);
        $g = $via->fetch();
        $items[] = ['node' => $n['id'], 'name' => $n['name'], 'kind' => $n['kind'], 'via' => $g['via'], 'created_at' => $g['created_at']];
    }
    return ['items' => $items];
}

function grantRevoke(array $a, mixed $client, mixed $node): array {
    if (!is_string($client) || !is_string($node)) throw new AppError('חסר לקוח או פריט', 400, 'bad_request');
    db()->prepare('DELETE FROM grants WHERE client = ? AND account = ? AND node = ?')->execute([$client, $a['id'], $node]);
    return ['ok' => true];
}

/** ניתוק מלא: ההענקות מסוג picked נמחקות; created נשארות (spec-3 סעיף 3.1). */
function grantsOnDisconnect(string $client, string $account): void {
    db()->prepare("DELETE FROM grants WHERE client = ? AND account = ? AND via = 'picked'")->execute([$client, $account]);
}

// ─── בורר הקבצים של הענן (picker.php, spec-3 סעיף 4) ─────────────────────────

/**
 * הבקשה מהאפליקציה: client, state, return, sub/exp/sig (חתום, purpose "pick" — אותו כלל "רק אותו חשבון"),
 * kind (file | folder | any), multiple, mime (רשימה, למשל application/pdf או image/*).
 */
function pickRequest(array $q): array {
    $c = activeClient(cleanClientId($q['client'] ?? null));
    $return = (string) ($q['return'] ?? '');
    if (!in_array($return, json_decode($c['returns'], true) ?: [], true)) throw new AppError('כתובת החזרה לא רשומה ללקוח', 400, 'bad_request');
    $state = (string) ($q['state'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_\-.~]{8,200}$/', $state)) throw new AppError('state לא תקין', 400, 'bad_request');
    $kind = (string) ($q['kind'] ?? 'file');
    if (!in_array($kind, ['file', 'folder', 'any'], true)) throw new AppError('kind לא תקין', 400, 'bad_request');
    $mime = is_array($q['mime'] ?? null) ? $q['mime'] : preg_split('/[\s,]+/', (string) ($q['mime'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
    $mime = array_values(array_unique(array_map('strtolower', array_map('strval', $mime))));
    if (count($mime) > 10 || array_filter($mime, fn($m) => !preg_match('#^[a-z0-9.+-]+/(\*|[a-z0-9.+-]+)$#', $m))) {
        throw new AppError('mime לא תקין', 400, 'bad_request');
    }
    return ['client' => $c, 'return' => $return, 'state' => $state, 'kind' => $kind, 'mime' => $mime,
            'multiple' => in_array((string) ($q['multiple'] ?? ''), ['1', 'true'], true),
            'sub' => connectSub($c, $q, $return, $state, 'pick')];
}

/** הבורר רק לאפליקציה ב-files.app עם חיבור פעיל של המשתמש הזה. אחרת 400 — היא כבר רואה הכול, או לא מחוברת. */
function pickAllowed(array $a, array $req): void {
    $conn = liveConnection($a['id'], $req['client']['id']);
    $scope = $conn ? (json_decode($conn['scope'], true) ?: []) : [];
    if (clientFilesScope($req['client']['id']) !== 'files.app' || !in_array('files.app', $scope, true)) {
        throw new AppError('הבורר זמין רק לאפליקציה מחוברת עם גישה לקבצים שבוחרים (files.app)', 400, 'bad_request');
    }
}

/** האם הפריט מתאים לסינון של הבקשה (סוג ו-mime). */
function pickFits(array $req, array $n): bool {
    if ($req['kind'] !== 'any' && $n['kind'] !== $req['kind']) return false;
    if ($n['kind'] !== 'file' || !$req['mime']) return true;
    foreach ($req['mime'] as $m) {
        if ($m === strtolower((string) $n['mime'])) return true;
        if (str_ends_with($m, '/*') && str_starts_with(strtolower((string) $n['mime']), substr($m, 0, -1))) return true;
    }
    return false;
}

/** "אישור" בבורר: הענקות picked, state חד-פעמי, וכתובת החזרה עם ה-ids (לא סוד — ההענקה כבר בשרת). */
function pickApprove(array $a, array $req, mixed $ids): string {
    $ids = is_array($ids) ? array_values(array_unique(array_map('strval', $ids))) : [];
    if (!$ids || (!$req['multiple'] && count($ids) > 1) || count($ids) > 100) throw new AppError('בחירה לא תקינה', 400, 'bad_request');
    $nodes = [];
    foreach ($ids as $id) {
        $n = liveNode($a, $id);
        if (!pickFits($req, $n)) throw new AppError('הפריט לא מתאים למה שהאפליקציה ביקשה', 400, 'bad_request');
        $nodes[] = $n;
    }
    $pdo = db();
    $once = $pdo->prepare('INSERT OR IGNORE INTO nonces (client, nonce, at) VALUES (?,?,?)');
    $once->execute([$req['client']['id'], 'pick:' . $req['state'], time()]);
    if ($once->rowCount() === 0) throw new AppError('הבחירה כבר נשלחה — פתחו את הבורר שוב מהאפליקציה', 400, 'bad_state');
    foreach ($nodes as $n) grantAdd($req['client']['id'], $a['id'], $n['id'], 'picked');
    return withQuery($req['return'], ['state' => $req['state'], 'picked' => implode(',', array_column($nodes, 'id'))]);
}
