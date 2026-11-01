<?php
/**
 * חיבורים — גיטהאב וספקי בינה, כמה לכל משתמש (docs/spec.md §10.1).
 *
 * טבלה אחת במקום users.github_* ו-user_providers. לכל משתמש:
 *   - כמה חשבונות גיטהאב. פרויקט בוחר — לכל משתמש בנפרד — באיזה מהם הוא עובד.
 *   - כמה מפתחות בינה, גם לאותו ספק, בסדר עדיפות (priority). נפל הראשון — עוברים לבא.
 *
 * הכלל שלא משתנה: פעולה יוצאת תמיד עם חיבור של מי שמבצע אותה.
 * הסוד מוצפן (lib/crypto.php) ולא חוזר מה-API לעולם — רק ארבע ספרות אחרונות.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/ai.php';

const CONN_KINDS = ['github', 'anthropic', 'openai', 'google'];
const AI_KINDS   = ['anthropic', 'openai', 'google'];
/** כמה זמן מדלגים על חיבור שנכשל, לפני שמנסים אותו שוב. */
const FAILING_SECONDS = 600;

/* ── מיגרציה ──────────────────────────────────────────────────────
 *
 * רצה פעם אחת (meta: connections_migrated). מעתיקה את הצפנים כמו שהם — אותו
 * מפתח, אותו פורמט, בלי פענוח והצפנה מחדש. גיבוי של המסד לפני. העמודות הישנות
 * נשארות לקריאה בלבד לגרסה אחת.
 */
function migrateConnections(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT NOT NULL DEFAULT '');
        CREATE TABLE IF NOT EXISTS connections (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            kind          TEXT NOT NULL,
            label         TEXT NOT NULL DEFAULT '',
            secret        TEXT NOT NULL DEFAULT '',
            login         TEXT NOT NULL DEFAULT '',
            scopes        TEXT NOT NULL DEFAULT '',
            model         TEXT NOT NULL DEFAULT '',
            priority      INTEGER NOT NULL DEFAULT 0,
            status        TEXT NOT NULL DEFAULT 'ok',
            last_error    TEXT NOT NULL DEFAULT '',
            failing_until INTEGER NOT NULL DEFAULT 0,
            last_ok_at    INTEGER NOT NULL DEFAULT 0,
            created_at    INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_conn_user ON connections(user_id, kind, priority);
        CREATE TABLE IF NOT EXISTS project_connections (
            project_id    INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            connection_id INTEGER NOT NULL REFERENCES connections(id) ON DELETE CASCADE,
            PRIMARY KEY (project_id, user_id)
        );
    ");
    addColumn($pdo, 'tasks', 'connection_id', 'INTEGER');

    $done = $pdo->query("SELECT v FROM meta WHERE k = 'connections_migrated'")->fetch();
    if ($done) return;

    $legacy = (int) $pdo->query("SELECT (SELECT COUNT(*) FROM users WHERE github_token <> '')
                                      + (SELECT COUNT(*) FROM user_providers WHERE api_key <> '') n")->fetch()['n'];
    if ($legacy > 0) backupBeforeMigration($pdo);

    $pdo->beginTransaction();
    try {
        $now = nowTs();
        foreach ($pdo->query("SELECT id, github_token, github_login, github_scopes FROM users WHERE github_token <> ''")->fetchAll() as $u) {
            $pdo->prepare("INSERT INTO connections (user_id, kind, label, secret, login, scopes, created_at)
                           VALUES (?, 'github', ?, ?, ?, ?, ?)")
                ->execute([$u['id'], (string) $u['github_login'], $u['github_token'], (string) $u['github_login'],
                           (string) $u['github_scopes'], $now]);
        }
        // סדר העדיפות: ה-★ של היום ראשון, ואחריו לפי סדר הספקים
        $order = array_flip(AI_KINDS);
        $rows  = $pdo->query("SELECT p.user_id, p.provider, p.api_key, p.model, p.added_at, u.default_provider
                                FROM user_providers p JOIN users u ON u.id = p.user_id
                               WHERE p.api_key <> ''")->fetchAll();
        usort($rows, fn ($a, $b) => [$a['user_id'], $a['provider'] === $a['default_provider'] ? 0 : 1, $order[$a['provider']] ?? 9]
                                  <=> [$b['user_id'], $b['provider'] === $b['default_provider'] ? 0 : 1, $order[$b['provider']] ?? 9]);
        $prio = [];
        foreach ($rows as $r) {
            if (!in_array($r['provider'], AI_KINDS, true)) continue;
            $p = $prio[$r['user_id']] = ($prio[$r['user_id']] ?? 0) + 1;
            $pdo->prepare('INSERT INTO connections (user_id, kind, secret, model, priority, created_at) VALUES (?,?,?,?,?,?)')
                ->execute([$r['user_id'], $r['provider'], $r['api_key'], (string) $r['model'], $p, (int) $r['added_at'] ?: $now]);
        }
        $pdo->prepare("INSERT INTO meta (k, v) VALUES ('connections_migrated', ?)")->execute([date('c')]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** עותק של המסד לפני המיגרציה — כמו ב-tools/owner-access.php. */
function backupBeforeMigration(PDO $pdo): void {
    if (!is_file(DB_FILE) || filesize(DB_FILE) === 0) return;
    $to = DB_FILE . '.bak-' . date('Ymd-His') . '-connections';
    try { $pdo->exec('VACUUM INTO ' . $pdo->quote($to)); }
    catch (Throwable $e) { @copy(DB_FILE, $to); }
}

/* ── קריאה ─────────────────────────────────────────────────────── */

/** החיבורים של משתמש — בלי הסוד. גיטהאב קודם, ואחריו הבינה לפי סדר העדיפות. */
function userConnections(int $userId): array {
    $st = db()->prepare("SELECT * FROM connections WHERE user_id = ?
                          ORDER BY kind <> 'github', priority, id");
    $st->execute([$userId]);
    return array_map('publicConnection', $st->fetchAll());
}

function publicConnection(array $c): array {
    $secret = decryptSecret((string) $c['secret']);
    $failing = $c['status'] === 'failing' && (int) $c['failing_until'] > nowTs();
    return [
        'id' => (int) $c['id'], 'kind' => $c['kind'], 'ai' => $c['kind'] !== 'github',
        'kind_label' => $c['kind'] === 'github' ? 'GitHub' : (PROVIDERS[$c['kind']]['label'] ?? $c['kind']),
        'label' => (string) $c['label'], 'login' => (string) $c['login'],
        'scopes' => array_values(array_filter(explode(',', (string) $c['scopes']))),
        'model' => (string) $c['model'], 'priority' => (int) $c['priority'],
        'tail' => secretTail($secret), 'connected' => $secret !== '',
        'status' => $failing ? 'failing' : 'ok', 'last_error' => (string) $c['last_error'],
        'failing_until' => (int) $c['failing_until'], 'last_ok_at' => (int) $c['last_ok_at'],
        'created_at' => (int) $c['created_at'],
    ];
}

/** שורת חיבור של המשתמש, או חריגה. משתמש לא רואה חיבור של אחר. */
function ownConnection(array $user, int $id): array {
    $st = db()->prepare('SELECT * FROM connections WHERE id = ? AND user_id = ?');
    $st->execute([$id, $user['id']]);
    $row = $st->fetch();
    if (!$row) throw new AppError('החיבור לא נמצא', 404);
    return $row;
}

/* ── כתיבה ─────────────────────────────────────────────────────── */

/**
 * חיבור חדש. גיטהאב: אותו login שכבר מחובר — מתעדכן במקום להיכפל. בינה: נכנס
 * אחרון בסדר. ‏$github = ['login' => …, 'scopes' => […]] אחרי בדיקה מול גיטהאב.
 */
function addConnection(array $user, string $kind, string $secret, string $label = '', string $model = '',
                       ?array $github = null): int {
    if (!in_array($kind, CONN_KINDS, true)) throw new InvalidArgumentException('סוג חיבור לא מוכר');
    $secret = trim($secret);
    if ($secret === '') throw new InvalidArgumentException('חסר מפתח');
    $label = mb_substr(trim($label), 0, 40);
    $uid = (int) $user['id'];

    if ($kind === 'github') {
        $login = (string) ($github['login'] ?? '');
        $scopes = implode(',', $github['scopes'] ?? []);
        $st = db()->prepare("SELECT id FROM connections WHERE user_id = ? AND kind = 'github' AND login = ? AND login <> ''");
        $st->execute([$uid, $login]);
        if ($old = $st->fetch()) {
            db()->prepare("UPDATE connections SET secret = ?, scopes = ?, label = CASE WHEN ? <> '' THEN ? ELSE label END,
                                  status = 'ok', last_error = '', failing_until = 0 WHERE id = ?")
                ->execute([encryptSecret($secret), $scopes, $label, $label, $old['id']]);
            logEvent($user, 'connection.update', 'github', true, $login);
            return (int) $old['id'];
        }
        db()->prepare("INSERT INTO connections (user_id, kind, label, secret, login, scopes, created_at)
                       VALUES (?, 'github', ?, ?, ?, ?, ?)")
            ->execute([$uid, $label !== '' ? $label : $login, encryptSecret($secret), $login, $scopes, nowTs()]);
    } else {
        checkProviderKey($kind, $secret);
        $model = trim($model) !== '' ? mb_substr(trim($model), 0, 80) : (PROVIDERS[$kind]['default'] ?? '');
        if ($model === '') throw new InvalidArgumentException('צריך לציין שם מודל עבור ' . PROVIDERS[$kind]['label']);
        $st = db()->prepare("SELECT COALESCE(MAX(priority), 0) + 1 p FROM connections WHERE user_id = ? AND kind <> 'github'");
        $st->execute([$uid]);
        db()->prepare('INSERT INTO connections (user_id, kind, label, secret, model, priority, created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$uid, $kind, $label, encryptSecret($secret), $model, (int) $st->fetch()['p'], nowTs()]);
    }
    $id = (int) db()->lastInsertId();
    logEvent($user, 'connection.add', $kind, true, $label);
    return $id;
}

/** שם, מודל, או מפתח חדש (ריק = בלי שינוי). */
function updateConnection(array $user, int $id, array $patch): void {
    $row = ownConnection($user, $id);
    $sets = []; $args = [];
    if (array_key_exists('label', $patch)) { $sets[] = 'label = ?'; $args[] = mb_substr(trim((string) $patch['label']), 0, 40); }
    if ($row['kind'] !== 'github' && array_key_exists('model', $patch) && trim((string) $patch['model']) !== '') {
        $sets[] = 'model = ?'; $args[] = mb_substr(trim((string) $patch['model']), 0, 80);
    }
    if ($row['kind'] !== 'github' && trim((string) ($patch['secret'] ?? '')) !== '') {
        checkProviderKey($row['kind'], trim((string) $patch['secret']));
        $sets[] = 'secret = ?'; $args[] = encryptSecret(trim((string) $patch['secret']));
        $sets[] = "status = 'ok'"; $sets[] = "last_error = ''"; $sets[] = 'failing_until = 0';
    }
    if (!$sets) return;
    $args[] = $id;
    db()->prepare('UPDATE connections SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
    logEvent($user, 'connection.update', $row['kind'], true, "#$id");
}

/** ניתוק. הפרויקטים שבחרו בחיבור הזה חוזרים לבחירה האוטומטית (ON DELETE CASCADE); מטלות — לסדר. */
function removeConnection(array $user, int $id): array {
    $row = ownConnection($user, $id);
    $st = db()->prepare('SELECT p.id, p.name FROM project_connections pc JOIN projects p ON p.id = pc.project_id
                          WHERE pc.connection_id = ?');
    $st->execute([$id]);
    $projects = $st->fetchAll();
    db()->prepare('DELETE FROM project_connections WHERE connection_id = ?')->execute([$id]);
    db()->prepare('UPDATE tasks SET connection_id = NULL WHERE connection_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM connections WHERE id = ?')->execute([$id]);
    if ($row['kind'] !== 'github') renumberPriorities((int) $user['id']);
    logEvent($user, 'connection.remove', $row['kind'], true, "#$id " . $row['label']);
    return $projects;
}

function renumberPriorities(int $userId): void {
    $st = db()->prepare("SELECT id FROM connections WHERE user_id = ? AND kind <> 'github' ORDER BY priority, id");
    $st->execute([$userId]);
    $up = db()->prepare('UPDATE connections SET priority = ? WHERE id = ?');
    foreach ($st->fetchAll() as $i => $r) $up->execute([$i + 1, $r['id']]);
}

/** ↑ / ↓ בסדר העדיפות. ‏$dir = -1 למעלה, +1 למטה. */
function moveConnection(array $user, int $id, int $dir): void {
    $row = ownConnection($user, $id);
    if ($row['kind'] === 'github') throw new InvalidArgumentException('לחיבור גיטהאב אין סדר עדיפות');
    renumberPriorities((int) $user['id']);
    $st = db()->prepare("SELECT id, priority FROM connections WHERE user_id = ? AND kind <> 'github' ORDER BY priority");
    $st->execute([$user['id']]);
    $rows = $st->fetchAll();
    $i = array_search($id, array_map('intval', array_column($rows, 'id')), true);
    $j = $i + ($dir < 0 ? -1 : 1);
    if ($i === false || $j < 0 || $j >= count($rows)) return;
    $up = db()->prepare('UPDATE connections SET priority = ? WHERE id = ?');
    $up->execute([$rows[$j]['priority'], $rows[$i]['id']]);
    $up->execute([$rows[$i]['priority'], $rows[$j]['id']]);
}

function markConnection(int $id, bool $ok, string $error = ''): void {
    if ($ok) {
        db()->prepare("UPDATE connections SET status = 'ok', last_error = '', failing_until = 0, last_ok_at = ? WHERE id = ?")
            ->execute([nowTs(), $id]);
    } else {
        db()->prepare("UPDATE connections SET status = 'failing', last_error = ?, failing_until = ? WHERE id = ?")
            ->execute([mb_substr($error, 0, 300), nowTs() + FAILING_SECONDS, $id]);
    }
}

/* ── בינה: השרשרת ───────────────────────────────────────────────── */

/**
 * שרשרת החיבורים לפנייה: לפי סדר העדיפות, בלי מה שנכשל בעשר הדקות האחרונות.
 * ‏$connectionId — חיבור שנבחר במפורש: רק הוא, בלי מעבר. ‏$kind — רק חיבורים של ספק אחד.
 * כל פריט: ['provider', 'key', 'model', 'connection_id', 'label', 'source' => 'user'].
 */
function aiChain(array $user, ?int $connectionId = null, string $kind = '', string $model = ''): array {
    if ($connectionId !== null) {
        $rows = [ownConnection($user, $connectionId)];
    } else {
        $sql = "SELECT * FROM connections WHERE user_id = ? AND kind <> 'github'";
        $args = [$user['id']];
        if ($kind !== '') { $sql .= ' AND kind = ?'; $args[] = $kind; }
        $st = db()->prepare($sql . ' ORDER BY priority, id');
        $st->execute($args);
        $all = $st->fetchAll();
        // נכשל — מדלגים עד שתעבור ההשהיה; אם כולם נכשלו, מנסים בכל זאת (עדיף מאשר כלום)
        $rows = array_values(array_filter($all, fn ($r) => !($r['status'] === 'failing' && (int) $r['failing_until'] > nowTs())));
        if (!$rows) $rows = $all;
    }
    $chain = [];
    foreach ($rows as $r) {
        if ($r['kind'] === 'github') continue;
        $key = decryptSecret((string) $r['secret']);
        if ($key === '') continue;
        $chain[] = ['provider' => $r['kind'], 'key' => $key,
                    'model' => ($model !== '' && $connectionId !== null) ? $model : (string) $r['model'],
                    'connection_id' => (int) $r['id'], 'label' => connLabel($r), 'source' => 'user',
                    'user_id' => (int) $r['user_id']];   // מי משלם (§8): בעל החיבור
    }
    return $chain;
}

function connLabel(array $r): string {
    $k = $r['kind'] === 'github' ? 'GitHub' : explode(' — ', PROVIDERS[$r['kind']]['label'] ?? $r['kind'])[0];
    return $r['label'] !== '' ? "$k · {$r['label']}" : $k;
}

/**
 * האם לעבור לחיבור הבא אחרי כישלון. כן: מפתח שנדחה, מכסה/קרדיט, עומס, 5xx,
 * רשת. לא: 400 — הבקשה עצמה שגויה, וספק אחר ייכשל באותו אופן.
 */
function shouldFallOver(Throwable $e): bool {
    $status = $e instanceof AppError ? (int) ($e->aiStatus ?? 0) : 0;
    if ($status === 400 || $status === 404 || $status === 422) return false;
    return true;
}

/** כמו aiComplete, אבל על שרשרת: נכשל אחד → הבא. מחזיר [text, used, fallbacks]. */
function aiCompleteChain(array $chain, string $system, string $prompt, int $maxTokens = 400,
                         ?callable $transport = null, ?array $user = null, ?int $taskId = null): array {
    if (!$chain) throw new AppError('אין חיבור לספק בינה — הגדרות ← חיבורים ← "+ חיבור"');
    $fallbacks = [];
    $last = null;
    foreach ($chain as $i => $conn) {
        try {
            $text = aiComplete($conn, $system, $prompt, $maxTokens, $transport);
            if (!empty($conn['connection_id'])) markConnection((int) $conn['connection_id'], true);
            return [$text, $conn, $fallbacks];
        } catch (Throwable $e) {
            $last = $e;
            if (!empty($conn['connection_id'])) markConnection((int) $conn['connection_id'], false, $e->getMessage());
            $next = $chain[$i + 1] ?? null;
            if (!$next || !shouldFallOver($e)) break;
            $note = "עבר ל-{$next['label']}: {$conn['label']} — {$e->getMessage()}";
            $fallbacks[] = $note;
            logEvent($user, 'ai.fallback', (string) $conn['provider'], false, $note);
            if ($taskId !== null) addNote(db(), $taskId, 'system', 'comment', "↪️ $note", 'platform', null);
        }
    }
    throw $last;
}

/* ── גיטהאב: איזה חיבור ─────────────────────────────────────────── */

/**
 * הטוקן של המשתמש לפרויקט (§10.1): הבחירה שלו בפרויקט (בירושה מהאב) ← החיבור
 * שה-login שלו הוא בעל הריפו ← החיבור הראשון ← שגיאה. בלי פרויקט — הראשון.
 * לעולם לא חיבור של משתמש אחר.
 */
function githubConnectionFor(array $user, ?array $project = null): array {
    $st = db()->prepare("SELECT * FROM connections WHERE user_id = ? AND kind = 'github' ORDER BY id");
    $st->execute([$user['id']]);
    $mine = array_values(array_filter($st->fetchAll(), fn ($r) => decryptSecret((string) $r['secret']) !== ''));
    if (!$mine) throw new AppError('לא חובר חשבון גיטהאב. הגדרות ← חיבורים ← "+ חיבור" ← GitHub');
    if ($project !== null) {
        $pick = db()->prepare('SELECT connection_id FROM project_connections WHERE project_id = ? AND user_id = ?');
        $up = db()->prepare('SELECT parent_id FROM projects WHERE id = ?');
        for ($pid = (int) $project['id'], $i = 0; $pid && $i < 8; $i++) {
            $pick->execute([$pid, $user['id']]);
            if ($c = $pick->fetch()) {
                foreach ($mine as $r) if ((int) $r['id'] === (int) $c['connection_id']) return $r;
            }
            $up->execute([$pid]);
            $pid = (int) ($up->fetch()['parent_id'] ?? 0);
        }
        $owner = strtolower((string) ($project['repo_owner'] ?? ''));
        foreach ($mine as $r) if ($owner !== '' && strtolower((string) $r['login']) === $owner) return $r;
    }
    return $mine[0];
}

function githubTokenFor(array $user, ?array $project = null): string {
    return decryptSecret((string) githubConnectionFor($user, $project)['secret']);
}

/** בחירת חיבור הגיטהאב שלי לפרויקט. null = אוטומטי. */
function setProjectGithub(array $user, int $projectId, ?int $connectionId): void {
    if ($connectionId === null) {
        db()->prepare('DELETE FROM project_connections WHERE project_id = ? AND user_id = ?')->execute([$projectId, $user['id']]);
        return;
    }
    $row = ownConnection($user, $connectionId);
    if ($row['kind'] !== 'github') throw new InvalidArgumentException('זה לא חיבור גיטהאב');
    db()->prepare('INSERT INTO project_connections (project_id, user_id, connection_id) VALUES (?,?,?)
                   ON CONFLICT(project_id, user_id) DO UPDATE SET connection_id = excluded.connection_id')
        ->execute([$projectId, $user['id'], $connectionId]);
}

/** הבחירה שלי בפרויקט: [connection_id|null שנבחר כאן, החיבור שבפועל]. */
function projectGithubChoice(array $user, array $project): array {
    $st = db()->prepare('SELECT connection_id FROM project_connections WHERE project_id = ? AND user_id = ?');
    $st->execute([$project['id'], $user['id']]);
    $chosen = $st->fetch();
    try { $eff = publicConnection(githubConnectionFor($user, $project)); } catch (Throwable $e) { $eff = null; }
    return ['chosen' => $chosen ? (int) $chosen['connection_id'] : null, 'effective' => $eff];
}
