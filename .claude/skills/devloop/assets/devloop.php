<?php
/**
 * DevLoop — מטלות, שיח, אימות, ראיות ושגיאות שנפתחות לבד. מודול משותף לכל האפליקציות בריפו.
 *
 * ⚠️ קובץ מסונכרן — לא עורכים אותו בתוך אפליקציה. המקור: devloop/src/ בשורש הריפו;
 * אחרי שינוי שם — php devloop/tools/sync.php (מעתיק לכל אפליקציה שמשתמשת). שער בפריסה בודק שהעותקים זהים.
 *
 * האפליקציה מחברת את המודול ב-devloop_setup([...]) — מסד, שעה, שגיאה, משתמשים ויומן משלה (devloop/README.md).
 * שני מקורות שמתמזגים לרשימה אחת:
 *   dev/open-items.json — מה ש-Claude מנהל בקוד, ומתעדכן עם כל שינוי שנכנס ל-main.
 *   הטבלה dev_tasks     — מטלה שהמפתח פתח מהמסך, 🐞 דיווח, ושגיאה מהיומן (⚠️ לבירור).
 * בכל מטלה — שיח, צעדי בדיקה, ראיות וצילומים. Claude קורא באסימון אישי (X-Dev-Token) — devapi-feed / devapi-comment.
 */

declare(strict_types=1);

// i18n-none-start — אזור הפיתוח: למפתח ול-Claude בלבד, בעברית

const DEVLOOP_VERSION = '1.0';
const DEVLOOP_COMMENT_MAX = 4000;
// triage לבירור (שגיאה שנפתחה לבד) · open פתוח · in_progress בעבודה · verify ממתין לאימות שלך · question שאלה אליך · closed סגור
const DEVLOOP_STATUS = ['triage', 'open', 'in_progress', 'verify', 'question', 'closed'];
const DEVLOOP_KINDS = ['bug', 'feature', 'question', 'research', 'task'];
const DEVLOOP_PRIORITY = ['high', 'normal', 'low'];
const DEVLOOP_FILE_MAX = 8 * 1048576;
const DEVLOOP_EVIDENCE = ['pr', 'commit', 'deploy', 'version', 'test', 'note'];
const DEVLOOP_RATE = 300; // בקשות בשעה לאסימון
const DEVLOOP_LOG_DAYS = 30; // היומן המובנה
const DEVLOOP_LOG_HITS = 50; // כמה מופעים של שגיאה נשמרים לכל מטלה (הקישור מהמטלה ליומן)

/**
 * החיבור לאפליקציה. חובה: db (fn(): PDO), items_file, data_dir, prefix ('PM-'), token_prefix ('pmd_'),
 * error (fn(string $msg, int $status, array $vars): Throwable), user_names (fn(): [id => שם]),
 * is_developer (fn(int $userId): bool).
 *
 * יומן — חלק מחייב של DevLoop: בלי יומן אין "⚠️ לבירור", אין עקבות לדיווח 🐞, ואין קישור בין מטלה למה שקרה.
 * בלי log — היומן המובנה (devloop_log_*: טבלה ב-data_dir/log.sqlite, 30 יום). לאפליקציה שיש לה יומן משלה —
 * log => ['rows' => fn(array $q): array, 'max_id' => fn(): int, 'write' => fn($level, $cat, $event, $extra)] (q ב-devloop_log_rows).
 *
 * רשות: now, audit, as_claude, notify (fn(string $subject, string $body)) — מייל למפתח כשמשהו מחכה לו;
 * item_url (fn(string $code): string) — קישור ישר למטלה, להתראה.
 */
function devloop_setup(array $cfg): void
{
    foreach (['db', 'items_file', 'data_dir', 'prefix', 'token_prefix', 'error', 'user_names', 'is_developer'] as $k) {
        if (!isset($cfg[$k])) throw new LogicException("devloop_setup: חסר $k");
    }
    if (isset($cfg['log']) && (!is_array($cfg['log']) || !isset($cfg['log']['rows'], $cfg['log']['max_id'], $cfg['log']['write']))) {
        throw new LogicException('devloop_setup: log — rows, max_id ו-write (או בלי log — היומן המובנה)');
    }
    $GLOBALS['__devloop'] = $cfg + ['now' => fn() => gmdate('Y-m-d\\TH:i:s\\Z')];
}

function devloop_cfg(string $k): mixed
{
    return $GLOBALS['__devloop'][$k] ?? null;
}

function devloop_db(): PDO { return (devloop_cfg('db'))(); }
function devloop_now(): string { return (devloop_cfg('now'))(); }
function devloop_error(string $msg, int $status = 400, array $vars = []): Throwable { return (devloop_cfg('error'))($msg, $status, $vars); }

/** קריאה לאפליקציה, אם חיברה (audit, as_claude). */
function devloop_hook(string $name, mixed ...$args): void
{
    $f = devloop_cfg($name);
    if ($f) $f(...$args);
}

/**
 * התראה למפתח (מייל — לפי האפליקציה): מטלה עברה לאימות / שאלה, Claude ענה, שגיאות חדשות לבירור.
 * לא זורקת לעולם — התראה שנכשלה לא מפילה את הפעולה.
 */
function devloop_notify(string $subject, string $body, string $code = ''): void
{
    $f = devloop_cfg('notify');
    if (!$f) return;
    try {
        $url = $code !== '' && devloop_cfg('item_url') ? (devloop_cfg('item_url'))($code) : '';
        $f($subject, $body . ($url !== '' ? "\n\n$url" : ''));
    } catch (Throwable $e) {
        devloop_log_write('warn', 'data', 'devtasks.notify', ['message' => $e->getMessage()]);
    }
}

// ── היומן ────────────────────────────────────────────────────

/** מזהה הבקשה — אותו מזהה ביומן, בכותרת X-Request-Id, ובהקשר של 🐞. */
function devloop_req_id(): string
{
    static $id = null;
    return $id ??= bin2hex(random_bytes(5));
}

function devloop_log_builtin(): bool
{
    return devloop_cfg('log') === null;
}

/** היומן המובנה — קובץ SQLite נפרד, כדי שהיומן לא ינפח את מסד האפליקציה. */
function devloop_log_db(): ?PDO
{
    static $db = false;
    if ($db !== false) return $db;
    try {
        $dir = rtrim((string)devloop_cfg('data_dir'), '/');
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $db = new PDO('sqlite:' . $dir . '/log.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec('PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 3000;');
        $db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS log (
                id INTEGER PRIMARY KEY, at TEXT NOT NULL, level TEXT NOT NULL, category TEXT NOT NULL, event TEXT NOT NULL,
                message TEXT NOT NULL DEFAULT '', user_id INTEGER, username TEXT NOT NULL DEFAULT '', ua TEXT NOT NULL DEFAULT '',
                method TEXT NOT NULL DEFAULT '', action TEXT NOT NULL DEFAULT '', status INTEGER, ms INTEGER,
                req TEXT NOT NULL DEFAULT '', data_json TEXT NOT NULL DEFAULT '{}'
            );
            CREATE INDEX IF NOT EXISTS log_at ON log (at);
            CREATE INDEX IF NOT EXISTS log_level ON log (level, id);
            CREATE INDEX IF NOT EXISTS log_req ON log (req);
            SQL);
    } catch (Throwable $e) {
        error_log('devloop log: ' . $e->getMessage());
        $db = null;
    }
    return $db;
}

/**
 * כתיבה ליומן — של האפליקציה, או המובנה. $extra: message, user (['id','username']), method, action, status, ms, data.
 * לא זורקת לעולם.
 */
function devloop_log_write(string $level, string $category, string $event, array $extra = []): void
{
    try {
        $log = devloop_cfg('log');
        if ($log) { ($log['write'])($level, $category, $event, $extra); return; }
        $db = devloop_log_db();
        if (!$db) return;
        $u = $extra['user'] ?? ($GLOBALS['__devloop_user'] ?? null);
        $db->prepare('INSERT INTO log (at, level, category, event, message, user_id, username, ua, method, action, status, ms, req, data_json)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
            gmdate('Y-m-d\\TH:i:s\\Z'), $level, $category, $event, mb_substr((string)($extra['message'] ?? ''), 0, 4000),
            isset($u['id']) ? (int)$u['id'] : null, (string)($u['username'] ?? ''), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
            (string)($extra['method'] ?? ($_SERVER['REQUEST_METHOD'] ?? '')), (string)($extra['action'] ?? ($_GET['action'] ?? '')),
            $extra['status'] ?? null, $extra['ms'] ?? null, devloop_req_id(),
            mb_substr(json_encode($extra['data'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', 0, 20000),
        ]);
        if (random_int(1, 300) === 1) {
            $db->prepare('DELETE FROM log WHERE at < ?')->execute([gmdate('Y-m-d\\TH:i:s\\Z', time() - DEVLOOP_LOG_DAYS * 86400)]);
        }
    } catch (Throwable $e) {
        error_log('devloop log write: ' . $e->getMessage());
    }
}

/**
 * היומן המובנה: מתחילים בראש ה-API של האפליקציה. מזהה בקשה (X-Request-Id), שגיאות PHP (גם קטלניות),
 * ושורה אחת לכל בקשה בסוף — פעולה, תוצאה, משך. $user — מי מחובר (אפשר גם אחר כך: devloop_log_user).
 * באפליקציה עם יומן משלה — לא קוראים לזה (היומן שלה עושה את זה).
 */
function devloop_log_begin(?array $user = null): void
{
    if (!devloop_log_builtin()) return;
    $t0 = microtime(true);
    devloop_log_user($user);
    if (!headers_sent()) header('X-Request-Id: ' . devloop_req_id());
    set_error_handler(function (int $no, string $msg, string $file, int $line) {
        if (!(error_reporting() & $no)) return false;
        devloop_log_write($no & (E_WARNING | E_USER_WARNING | E_NOTICE | E_USER_NOTICE | E_DEPRECATED) ? 'warn' : 'error', 'php', 'php.error',
            ['message' => $msg, 'data' => ['file' => basename($file), 'line' => $line]]);
        return false;
    });
    register_shutdown_function(function () use ($t0) {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            devloop_log_write('error', 'php', 'php.fatal', ['message' => $e['message'], 'data' => ['file' => basename($e['file']), 'line' => $e['line']]]);
        }
        $status = http_response_code() ?: 200;
        devloop_log_write($status >= 500 ? 'error' : ($status >= 400 ? 'warn' : 'info'), 'api', 'request',
            ['status' => $status, 'ms' => (int)round((microtime(true) - $t0) * 1000), 'message' => $GLOBALS['__devloop_err'] ?? '']);
    });
}

function devloop_log_user(?array $user): void
{
    $GLOBALS['__devloop_user'] = $user ? ['id' => $user['id'] ?? null, 'username' => (string)($user['username'] ?? $user['name'] ?? '')] : null;
}

/** הודעת השגיאה של הבקשה — נכנסת לשורת הבקשה ביומן (האפליקציה קוראת לזה ב-catch שלה). */
function devloop_log_error(Throwable $e): void
{
    $GLOBALS['__devloop_err'] = mb_substr($e->getMessage(), 0, 500);
    if (!($e instanceof RuntimeException) || $e->getCode() >= 500) {
        devloop_log_write('error', 'php', 'exception', ['message' => $e->getMessage(), 'data' => ['file' => basename($e->getFile()), 'line' => $e->getLine(),
            'trace' => array_slice(array_map(fn($f) => basename($f['file'] ?? '?') . ':' . ($f['line'] ?? '?') . ' ' . ($f['function'] ?? ''), $e->getTrace()), 0, 8)]]);
    }
}

/** שגיאת JavaScript מהדפדפן (devloop.js שולח לבד כשהיומן מובנה) — POST devloop-client-error. */
function devloop_log_client(array $b): array
{
    devloop_log_write('error', 'client', 'client.error', ['message' => mb_substr((string)($b['message'] ?? ''), 0, 1000),
        'data' => array_intersect_key($b, array_flip(['source', 'line', 'col', 'stack', 'page', 'kind']))]);
    return ['ok' => true];
}

/**
 * שורות מהיומן. $q: after (id, מהישן לחדש) · before (id, מהחדש לישן) · level (error | warn — ומעלה) · ids [] · reqs [] ·
 * q (טקסט) · limit (עד 500). כל שורה: id, at, level, category, event, message, username, method, action, status, ms, req, ua, data.
 */
function devloop_log_rows(array $q): array
{
    $log = devloop_cfg('log');
    if ($log) return ($log['rows'])($q);
    $db = devloop_log_db();
    if (!$db) return [];
    $w = ['1 = 1'];
    $a = [];
    if (!empty($q['after'])) { $w[] = 'id > ?'; $a[] = (int)$q['after']; }
    if (!empty($q['before'])) { $w[] = 'id < ?'; $a[] = (int)$q['before']; }
    if (($q['level'] ?? '') === 'error') $w[] = "level = 'error'";
    elseif (($q['level'] ?? '') === 'warn') $w[] = "level IN ('warn', 'error')";
    foreach (['ids' => 'id', 'reqs' => 'req'] as $k => $col) {
        if (!array_key_exists($k, $q)) continue;
        $vals = array_values(array_slice(array_filter((array)$q[$k], fn($v) => $v !== '' && $v !== null), 0, 200));
        if (!$vals) return [];
        $w[] = "$col IN (" . implode(',', array_fill(0, count($vals), '?')) . ')';
        array_push($a, ...$vals);
    }
    if (!empty($q['q'])) { $w[] = '(event LIKE ? OR message LIKE ? OR action LIKE ? OR data_json LIKE ?)'; $l = '%' . strtr((string)$q['q'], ['%' => '', '_' => '']) . '%'; array_push($a, $l, $l, $l, $l); }
    $limit = max(1, min(500, (int)($q['limit'] ?? 100)));
    $st = $db->prepare('SELECT * FROM log WHERE ' . implode(' AND ', $w) . ' ORDER BY id ' . (!empty($q['after']) ? 'ASC' : 'DESC') . " LIMIT $limit");
    $st->execute($a);
    return array_map(function ($r) {
        $r['id'] = (int)$r['id'];
        $r['data'] = json_decode((string)$r['data_json'], true) ?: [];
        unset($r['data_json']);
        return $r;
    }, $st->fetchAll());
}

function devloop_log_max_id(): int
{
    $log = devloop_cfg('log');
    if ($log) return (int)($log['max_id'])();
    $db = devloop_log_db();
    return $db ? (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM log')->fetchColumn() : 0;
}

/** היומן המובנה, למסך "📜 יומן" של המודול (dev-log). באפליקציה עם יומן משלה — המסך שלה. */
function devloop_log_view(array $q): array
{
    if (!devloop_log_builtin()) throw devloop_error('היומן של האפליקציה — במסך היומן שלה', 404);
    return ['rows' => devloop_log_rows(array_intersect_key($q, array_flip(['before', 'level', 'q'])) + ['limit' => 200]
        + (!empty($q['req']) ? ['reqs' => [(string)$q['req']]] : [])), 'days' => DEVLOOP_LOG_DAYS];
}

function devloop_add_column(PDO $pdo, string $table, string $col, string $def): void
{
    $cols = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (in_array($col, $cols, true)) return;
    try { $pdo->exec("ALTER TABLE $table ADD COLUMN $col $def"); }
    catch (PDOException $e) { if (!str_contains($e->getMessage(), 'duplicate column')) throw $e; }
}

function devloop_mime(string $path): string
{
    $m = function_exists('mime_content_type') ? (string)@mime_content_type($path) : '';
    return $m ?: 'application/octet-stream';
}

function devloop_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    devloop_db()->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS dev_tasks (
            id          INTEGER PRIMARY KEY,
            code        TEXT NOT NULL UNIQUE,
            area        TEXT NOT NULL DEFAULT '',
            title       TEXT NOT NULL,
            detail      TEXT NOT NULL DEFAULT '',
            status      TEXT NOT NULL DEFAULT 'open',
            created_by  INTEGER,
            created_at  TEXT NOT NULL,
            updated_at  TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS dev_comments (
            id          INTEGER PRIMARY KEY,
            code        TEXT NOT NULL,
            at          TEXT NOT NULL,
            author      TEXT NOT NULL,          -- user | claude
            user_id     INTEGER,
            body        TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS dev_comments_code ON dev_comments (code);
        CREATE TABLE IF NOT EXISTS dev_tokens (
            id           INTEGER PRIMARY KEY,
            token_hash   TEXT NOT NULL UNIQUE,
            label        TEXT NOT NULL,
            created_by   INTEGER NOT NULL,
            created_at   TEXT NOT NULL,
            last_used_at TEXT,
            revoked_at   TEXT,
            read_upto    INTEGER NOT NULL DEFAULT 0,  -- ההערה האחרונה ש-Claude סימן כנקראה
            rate_hour    TEXT NOT NULL DEFAULT '',
            rate_n       INTEGER NOT NULL DEFAULT 0
        );
        -- מצב שנקבע מחוץ לקובץ (אימות שלך, Claude באסימון) — גובר על הקובץ אם חדש מ-status_at שבו
        CREATE TABLE IF NOT EXISTS dev_state (
            code   TEXT PRIMARY KEY,
            status TEXT NOT NULL,
            at     TEXT NOT NULL,
            by     TEXT NOT NULL
        );
        -- ראיות: PR, commit, פריסה, גרסה, בדיקה — מ-Claude או אוטומטית מ-GitHub (שלב 2)
        CREATE TABLE IF NOT EXISTS dev_evidence (
            id    INTEGER PRIMARY KEY,
            code  TEXT NOT NULL,
            kind  TEXT NOT NULL,
            ref   TEXT NOT NULL DEFAULT '',
            url   TEXT NOT NULL DEFAULT '',
            note  TEXT NOT NULL DEFAULT '',
            at    TEXT NOT NULL,
            by    TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS dev_evidence_code ON dev_evidence (code);
        -- צילומים וקבצים בשיח (data/files/_dev — לא נדרס בפריסה)
        CREATE TABLE IF NOT EXISTS dev_files (
            id         INTEGER PRIMARY KEY,
            code       TEXT NOT NULL,
            comment_id INTEGER,
            name       TEXT NOT NULL,
            mime       TEXT NOT NULL,
            size       INTEGER NOT NULL,
            storage    TEXT NOT NULL,
            at         TEXT NOT NULL,
            by         TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS dev_files_code ON dev_files (code);
        -- DevLoop 2: שגיאות מהיומן, מקובצות לפי חתימה — כל קבוצה היא מטלה "לבירור"
        CREATE TABLE IF NOT EXISTS dev_err_groups (
            sig       TEXT PRIMARY KEY,
            code      TEXT NOT NULL,
            count     INTEGER NOT NULL DEFAULT 0,
            first_at  TEXT NOT NULL,
            last_at   TEXT NOT NULL,
            last_req  TEXT NOT NULL DEFAULT '',
            ignored   INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS dev_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);
        -- המופעים של כל שגיאה ביומן (עד DEVLOOP_LOG_HITS לחתימה) — מהמטלה ליומן ובחזרה
        CREATE TABLE IF NOT EXISTS dev_err_hits (sig TEXT NOT NULL, log_id INTEGER NOT NULL, at TEXT NOT NULL, req TEXT NOT NULL DEFAULT '');
        CREATE INDEX IF NOT EXISTS dev_err_hits_sig ON dev_err_hits (sig, log_id);
        CREATE INDEX IF NOT EXISTS dev_err_hits_log ON dev_err_hits (log_id);
        SQL);
    // שדות במטלה שנפתחה מהמסך: סוג, עדיפות, איך יודעים שזה עובד, צעדי בדיקה, ההקשר של דיווח 🐞
    $db = devloop_db();
    foreach (['kind' => "TEXT NOT NULL DEFAULT 'task'", 'priority' => "TEXT NOT NULL DEFAULT 'normal'",
              'acceptance' => "TEXT NOT NULL DEFAULT ''", 'steps' => "TEXT NOT NULL DEFAULT '[]'", 'context' => "TEXT NOT NULL DEFAULT ''"] as $col => $def) {
        devloop_add_column($db, 'dev_tasks', $col, $def);
    }
}

function devloop_files_dir(): string
{
    $d = rtrim((string)devloop_cfg('data_dir'), '/');
    if (!is_dir($d)) mkdir($d, 0755, true);
    return $d;
}

// ── הרשימה ───────────────────────────────────────────────────

function devloop_items_file(): array
{
    $f = (string)devloop_cfg('items_file');
    return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
}

function devloop_code_num(string $code): int
{
    return preg_match('/^' . preg_quote((string)devloop_cfg('prefix'), '/') . '(\d+)$/', $code, $m) ? (int)$m[1] : 0;
}

/**
 * DevLoop 2: שגיאות חדשות ביומן (level=error — שרת ודפדפן) → מטלות "לבירור", אחת לכל חתימה (קטגוריה, אירוע,
 * פעולה והודעה בלי מספרים), עם מונה. שגיאה שחוזרת אחרי שהמטלה נסגרה — המטלה נפתחת שוב "לבירור".
 * רץ כשהמפתח פותח את המטלות וכש-Claude קורא (devapi-feed). עד 20 מטלות חדשות בריצה — לא מציפים.
 */
function devloop_errors_sync(): int
{
    devloop_migrate();
    $db = devloop_db();
    $mark = $db->query("SELECT value FROM dev_meta WHERE key = 'err_upto'")->fetchColumn();
    if ($mark === false) {
        // פעם ראשונה: מתחילים מעכשיו — לא פותחים מטלות על כל ההיסטוריה
        $db->prepare("INSERT OR REPLACE INTO dev_meta (key, value) VALUES ('err_upto', ?)")->execute([(string)devloop_log_max_id()]);
        return 0;
    }
    $upto = (int)$mark;
    // שורות: id, at, category (client = דפדפן), event, message, action, method, status, ms, req, ua, data (מערך)
    $rows = devloop_log_rows(['after' => $upto, 'level' => 'error', 'limit' => 500]);
    $max = $upto;
    $made = 0;
    foreach ($rows as $r) {
        $max = max($max, (int)$r['id']);
        $sig = devloop_err_sig($r);
        $g = $db->prepare('SELECT * FROM dev_err_groups WHERE sig = ?');
        $g->execute([$sig]);
        $grp = $g->fetch();
        // כל מופע נשמר מול המטלה — הקישור בשני הכיוונים: מהמטלה למופעים ביומן, ומשורה ביומן למטלה
        $db->prepare('INSERT INTO dev_err_hits (sig, log_id, at, req) VALUES (?,?,?,?)')->execute([$sig, (int)$r['id'], $r['at'], (string)$r['req']]);
        $db->prepare('DELETE FROM dev_err_hits WHERE sig = ? AND log_id NOT IN (SELECT log_id FROM dev_err_hits WHERE sig = ? ORDER BY log_id DESC LIMIT ' . DEVLOOP_LOG_HITS . ')')->execute([$sig, $sig]);
        if ($grp) {
            $db->prepare('UPDATE dev_err_groups SET count = count + 1, last_at = ?, last_req = ? WHERE sig = ?')->execute([$r['at'], $r['req'], $sig]);
            $t = $db->prepare('SELECT status FROM dev_tasks WHERE code = ?');
            $t->execute([$grp['code']]);
            if (!$grp['ignored'] && $t->fetchColumn() === 'closed') {
                $db->prepare("UPDATE dev_tasks SET status = 'triage', updated_at = ? WHERE code = ?")->execute([devloop_now(), $grp['code']]);
                devloop_comment_add($grp['code'], 'claude', null, "⚠️ השגיאה חזרה אחרי שהמטלה נסגרה ({$r['at']}, בקשה {$r['req']}) — חזרה לבירור.");
            }
            continue;
        }
        if ($made >= 20) continue;
        devloop_err_item($r, $sig, 'triage');
        $made++;
    }
    if ($max > $upto) $db->prepare("INSERT OR REPLACE INTO dev_meta (key, value) VALUES ('err_upto', ?)")->execute([(string)$max]);
    if ($made) devloop_notify("⚠️ $made שגיאות חדשות לבירור", "נפתחו לבד מהיומן — 👍 לטפל או 🙈 להתעלם, במסך המטלות.");
    return $made;
}

/** חתימה של שגיאה: קטגוריה, אירוע, פעולה, וההודעה בלי מספרים ומחרוזות. */
function devloop_err_sig(array $r): string
{
    $norm = preg_replace(['/\b[0-9a-f]{8,}\b/i', '/\d+/', '/"[^"]*"/u', "/'[^']*'/u"], ['#', '#', '"…"', "'…'"], (string)$r['message']);
    return substr(hash('sha1', $r['category'] . '|' . $r['event'] . '|' . $r['action'] . '|' . mb_substr($norm, 0, 200)), 0, 16);
}

/** מטלה משגיאה ביומן — לבד ("לבירור") או מהיומן בלחיצה ("פתוח"). */
function devloop_err_item(array $r, string $sig, string $status): string
{
    $db = devloop_db();
    $data = is_array($r['data'] ?? null) ? $r['data'] : [];
    $where = $r['category'] === 'client' ? ($data['page'] ?? '') : trim(($r['method'] ?? '') . ' ' . $r['action']);
    $title = mb_substr('⚠️ ' . ($r['category'] === 'client' ? 'דפדפן' : 'שרת') . ': ' . ($r['message'] ?: $r['event']) . ($where !== '' ? " ($where)" : ''), 0, 200);
    $code = devloop_next_code();
    $now = devloop_now();
    $ctx = json_encode(['url' => $data['page'] ?? '', 'ua' => $r['ua'] ?? '', 'requests' => [['action' => $r['action'], 'method' => $r['method'] ?? '',
        'status' => (int)$r['status'], 'id' => $r['req'], 'ms' => (int)($r['ms'] ?? 0), 'at' => $r['at']]],
        'errors' => [['message' => $r['message'], 'source' => $data['source'] ?? ($data['file'] ?? ''), 'line' => $data['line'] ?? '']]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $detail = "" . ($status === 'triage' ? 'נפתח לבד מהיומן' : 'נפתח מהיומן') . " ({$r['category']} · {$r['event']}). בקשה {$r['req']} ב-{$r['at']}."
        . (!empty($data['trace']) ? "\n\n" . implode("\n", array_slice((array)$data['trace'], 0, 8)) : '')
        . (!empty($data['stack']) ? "\n\n" . mb_substr((string)$data['stack'], 0, 1200) : '');
    $db->prepare("INSERT INTO dev_tasks (code, area, title, detail, status, created_by, created_at, updated_at, kind, priority, acceptance, steps, context)
                  VALUES (?, 'שגיאה מהיומן', ?, ?, ?, NULL, ?, ?, 'bug', 'normal', '', '[]', ?)")
       ->execute([$code, $title, mb_substr($detail, 0, DEVLOOP_COMMENT_MAX), $status, $now, $now, mb_substr($ctx, 0, 20000)]);
    $db->prepare('INSERT INTO dev_err_groups (sig, code, count, first_at, last_at, last_req) VALUES (?,?,1,?,?,?)')->execute([$sig, $code, $r['at'], $r['at'], $r['req']]);
    return $code;
}

/**
 * 📜 מהמטלה ליומן: המופעים של השגיאה (dev_err_hits), והבקשות מההקשר של 🐞 / השגיאה — מהחדש לישן.
 */
function devloop_item_log(string $code): array
{
    $x = devloop_task_get($code);
    $db = devloop_db();
    $st = $db->prepare('SELECT h.log_id FROM dev_err_hits h JOIN dev_err_groups g ON g.sig = h.sig WHERE g.code = ? ORDER BY h.log_id DESC LIMIT ' . DEVLOOP_LOG_HITS);
    $st->execute([$code]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $reqs = array_values(array_unique(array_filter(array_map(fn($r) => (string)($r['id'] ?? ''), $x['context']['requests'] ?? []))));
    $rows = [];
    if ($ids) foreach (devloop_log_rows(['ids' => $ids, 'limit' => 200]) as $r) $rows[$r['id']] = $r;
    if ($reqs) foreach (devloop_log_rows(['reqs' => $reqs, 'limit' => 200]) as $r) $rows[$r['id']] = $r;
    krsort($rows);
    return ['rows' => array_values(array_slice($rows, 0, 100)), 'reqs' => $reqs, 'builtin' => devloop_log_builtin()];
}

/** 🔗 מהיומן למטלה: לכל שורה (ids) — המטלה שלה: מופע של שגיאה, או בקשה מההקשר של 🐞. */
function devloop_log_links(array $ids, array $reqs = []): array
{
    devloop_migrate();
    $db = devloop_db();
    $out = ['ids' => [], 'reqs' => []];
    $ids = array_values(array_slice(array_map('intval', $ids), 0, 500));
    if ($ids) {
        $st = $db->prepare('SELECT h.log_id, g.code FROM dev_err_hits h JOIN dev_err_groups g ON g.sig = h.sig WHERE h.log_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) $out['ids'][(int)$r['log_id']] = $r['code'];
    }
    $want = array_flip(array_slice(array_map('strval', $reqs), 0, 500));
    if ($want) {
        foreach ($db->query("SELECT code, context FROM dev_tasks WHERE context != ''")->fetchAll() as $t) {
            foreach ((json_decode((string)$t['context'], true)['requests'] ?? []) as $r) {
                if (isset($want[(string)($r['id'] ?? '')])) $out['reqs'][(string)$r['id']] = $t['code'];
            }
        }
    }
    return $out;
}

/** "+ מטלה" משורה ביומן: אותה חתימה כבר מטלה — היא; אחרת נפתחת מטלה (פתוח — המפתח בחר בה). */
function devloop_task_from_log(array $dev, array $b): array
{
    devloop_migrate();
    $r = devloop_log_rows(['ids' => [(int)($b['log_id'] ?? 0)], 'limit' => 1])[0] ?? throw devloop_error('השורה לא נמצאה ביומן', 404);
    $sig = devloop_err_sig($r);
    $db = devloop_db();
    $g = $db->prepare('SELECT code FROM dev_err_groups WHERE sig = ?');
    $g->execute([$sig]);
    $code = $g->fetchColumn() ?: null;
    $made = !$code;
    if ($made) $code = devloop_err_item($r, $sig, 'open');
    $db->prepare('INSERT INTO dev_err_hits (sig, log_id, at, req) SELECT ?,?,?,? WHERE NOT EXISTS (SELECT 1 FROM dev_err_hits WHERE sig = ? AND log_id = ?)')
       ->execute([$sig, (int)$r['id'], $r['at'], (string)$r['req'], $sig, (int)$r['id']]);
    if ($made) devloop_comment_add($code, 'user', (int)$dev['id'], '📜 נפתח מהיומן (שורה ' . $r['id'] . ').');
    return ['ok' => true, 'code' => $code, 'created' => $made];
}

/** הרשימה המאוחדת, עם השיח של כל מטלה. */
function devloop_task_list(): array
{
    devloop_migrate();
    static $synced = false;
    if (!$synced) { $synced = true; try { devloop_errors_sync(); } catch (Throwable $e) { devloop_log_write('warn', 'data', 'devtasks.errors_sync', ['message' => $e->getMessage()]); } }
    $db = devloop_db();
    $file = devloop_items_file();
    $items = [];
    foreach ($file['items'] ?? [] as $x) {
        if (empty($x['code'])) continue;
        $items[$x['code']] = $x + ['source' => 'code'];
    }
    foreach ($db->query('SELECT * FROM dev_tasks ORDER BY id DESC')->fetchAll() as $t) {
        if (isset($items[$t['code']])) continue;
        $items[$t['code']] = ['code' => $t['code'], 'area' => $t['area'], 'status' => $t['status'], 'title' => $t['title'],
            'detail' => $t['detail'], 'opened' => substr($t['created_at'], 0, 10), 'questions' => [], 'source' => 'user',
            'kind' => $t['kind'], 'priority' => $t['priority'], 'acceptance' => $t['acceptance'],
            'steps' => json_decode((string)$t['steps'], true) ?: [], 'context' => json_decode((string)$t['context'], true) ?: null];
    }
    // מצב מחוץ לקובץ (אימות, Claude) — גובר אם חדש ממה שבקובץ
    foreach ($db->query('SELECT * FROM dev_state')->fetchAll() as $o) {
        if (!isset($items[$o['code']]) || $items[$o['code']]['source'] !== 'code') continue;
        // status_at בעתיד (טעות בקובץ) — לא חוסם את האימות
        $fileAt = min((string)($items[$o['code']]['status_at'] ?? ''), devloop_now());
        if ($fileAt <= $o['at']) $items[$o['code']]['status'] = $o['status'];
    }
    $ev = [];
    foreach ($db->query('SELECT * FROM dev_evidence ORDER BY id')->fetchAll() as $e) {
        $ev[$e['code']][] = ['id' => (int)$e['id'], 'kind' => $e['kind'], 'ref' => $e['ref'], 'url' => $e['url'], 'note' => $e['note'], 'at' => $e['at'], 'by' => $e['by']];
    }
    $files = [];
    foreach ($db->query('SELECT id, code, comment_id, name, mime, size, at, by FROM dev_files ORDER BY id')->fetchAll() as $f) {
        $files[$f['code']][] = ['id' => (int)$f['id'], 'comment_id' => $f['comment_id'] !== null ? (int)$f['comment_id'] : null,
            'name' => $f['name'], 'mime' => $f['mime'], 'size' => (int)$f['size'], 'at' => $f['at'], 'by' => $f['by']];
    }
    $names = [];
    $names = (devloop_cfg('user_names'))();
    $byCode = [];
    foreach ($db->query('SELECT * FROM dev_comments ORDER BY id')->fetchAll() as $c) {
        $byCode[$c['code']][] = ['id' => (int)$c['id'], 'at' => $c['at'], 'author' => $c['author'],
            'name' => $c['author'] === 'claude' ? 'Claude' : ($names[(int)$c['user_id']] ?? '—'), 'body' => $c['body']];
    }
    $groups = [];
    foreach ($db->query('SELECT code, count, last_at, ignored FROM dev_err_groups')->fetchAll() as $g) $groups[$g['code']] = ['count' => (int)$g['count'], 'last_at' => $g['last_at'], 'ignored' => (bool)$g['ignored']];
    foreach ($items as $code => &$x) {
        if (isset($groups[$code])) $x['occurrences'] = $groups[$code];
        $x += ['kind' => 'task', 'priority' => 'normal', 'acceptance' => '', 'steps' => [], 'context' => null];
        // ראיות: מהקובץ (pr) ומהטבלה
        $x['evidence'] = array_merge(!empty($x['pr']) ? [['kind' => 'pr', 'ref' => '#' . $x['pr'], 'url' => '', 'note' => '', 'at' => $x['closed'] ?? '', 'by' => 'code']] : [], $ev[$code] ?? []);
        $x['files'] = $files[$code] ?? [];
        $x['comments'] = $byCode[$code] ?? [];
        $last = end($x['comments']) ?: null;
        // מי צריך להגיב עכשיו: הערה של המפתח שלא נענתה — ממתינה ל-Claude
        $x['waiting'] = $last ? ($last['author'] === 'claude' ? 'user' : 'claude') : null;
        $x['last_at'] = $last['at'] ?? null;
    }
    unset($x);
    uasort($items, fn($a, $b) => devloop_code_num($b['code']) <=> devloop_code_num($a['code']));
    return ['updated' => $file['updated'] ?? null, 'note' => $file['note'] ?? null, 'items' => array_values($items)];
}

function devloop_task_get(string $code): array
{
    foreach (devloop_task_list()['items'] as $x) if ($x['code'] === $code) return $x;
    throw devloop_error('אין מטלה ' . $code, 404);
}

/** הקוד הבא — אחרי הגבוה שבקובץ ובטבלה. */
function devloop_next_code(): string
{
    devloop_migrate();
    $max = 0;
    foreach (devloop_items_file()['items'] ?? [] as $x) $max = max($max, devloop_code_num((string)($x['code'] ?? '')));
    foreach (devloop_db()->query('SELECT code FROM dev_tasks')->fetchAll(PDO::FETCH_COLUMN) as $c) $max = max($max, devloop_code_num($c));
    return sprintf('%s%03d', devloop_cfg('prefix'), $max + 1);
}

function devloop_task_create(array $dev, array $b): array
{
    $title = mb_substr(trim((string)($b['title'] ?? '')), 0, 200);
    if ($title === '') throw devloop_error('חסרה כותרת', 422);
    $kind = in_array($b['kind'] ?? '', DEVLOOP_KINDS, true) ? $b['kind'] : 'task';
    $prio = in_array($b['priority'] ?? '', DEVLOOP_PRIORITY, true) ? $b['priority'] : 'normal';
    $steps = array_values(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 300), is_array($b['steps'] ?? null) ? $b['steps'] : []), 'strlen'));
    $code = devloop_next_code();
    $now = devloop_now();
    devloop_db()->prepare('INSERT INTO dev_tasks (code, area, title, detail, status, created_by, created_at, updated_at, kind, priority, acceptance, steps) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$code, mb_substr(trim((string)($b['area'] ?? '')), 0, 40), $title,
            mb_substr(trim((string)($b['detail'] ?? '')), 0, DEVLOOP_COMMENT_MAX), 'open', $dev['id'], $now, $now,
            $kind, $prio, mb_substr(trim((string)($b['acceptance'] ?? '')), 0, 2000), json_encode(array_slice($steps, 0, 20), JSON_UNESCAPED_UNICODE)]);
    return ['ok' => true, 'code' => $code];
}

/**
 * מצב: מטלה מהמסך — בטבלה; מטלה מהקוד — שכבה מעל הקובץ (dev_state), שגוברת עד ש-Claude מעדכן את הקובץ
 * (status_at חדש יותר). $by — user:<id> / claude / github.
 */
function devloop_task_status(string $code, string $status, string $by = 'user'): void
{
    devloop_migrate();
    if (!in_array($status, DEVLOOP_STATUS, true)) throw devloop_error('מצב לא מוכר', 422);
    $x = devloop_task_get($code);
    if ($x['source'] === 'user') {
        devloop_db()->prepare('UPDATE dev_tasks SET status = ?, updated_at = ? WHERE code = ?')->execute([$status, devloop_now(), $code]);
    } else {
        devloop_db()->prepare('INSERT INTO dev_state (code, status, at, by) VALUES (?,?,?,?) ON CONFLICT(code) DO UPDATE SET status = excluded.status, at = excluded.at, by = excluded.by')
            ->execute([$code, $status, devloop_now(), $by]);
    }
}

/**
 * שינוי מצב מהמסך. במטלת שגיאה "לבירור": 👍 לטפל (open) — עוברת ל-Claude; 🙈 להתעלם (ignore) — נסגרת, וגם
 * כשהשגיאה חוזרת היא רק נספרת ולא נפתחת שוב. החזרה למצב אחר מבטלת את ההתעלמות.
 */
function devloop_task_set(array $dev, array $b): array
{
    $code = (string)($b['code'] ?? '');
    $ignore = !empty($b['ignore']);
    devloop_task_status($code, $ignore ? 'closed' : (string)($b['status'] ?? ''), 'user:' . $dev['id']);
    devloop_db()->prepare('UPDATE dev_err_groups SET ignored = ? WHERE code = ?')->execute([$ignore ? 1 : 0, $code]);
    if ($ignore) devloop_comment_add($code, 'claude', null, '🙈 סומנה "להתעלם" — מופעים חדשים רק נספרים.');
    elseif (($b['from'] ?? '') === 'triage') devloop_comment_add($code, 'user', (int)$dev['id'], '👍 לטפל');
    return ['ok' => true];
}

/**
 * האימות שלך: ✅ עובד אצלי — נסגר; ↩️ לא עובד — חוזר לעבודה, וההערה (חובה) נכנסת לשיח וממתינה ל-Claude.
 */
function devloop_verify(array $dev, array $b): array
{
    $code = (string)($b['code'] ?? '');
    $ok = !empty($b['ok']);
    $note = trim((string)($b['note'] ?? ''));
    if (!$ok && $note === '') throw devloop_error('מה לא עובד? כמה מילים — Claude יתקן לפיהן', 422);
    devloop_task_status($code, $ok ? 'closed' : 'in_progress', 'user:' . $dev['id']);
    $r = devloop_comment_add($code, 'user', (int)$dev['id'], ($ok ? '✅ אומת — עובד אצלי' : '↩️ לא עובד') . ($note !== '' ? ': ' . $note : ''));
    devloop_db()->prepare('INSERT INTO dev_evidence (code, kind, ref, url, note, at, by) VALUES (?,?,?,?,?,?,?)')
        ->execute([$code, 'note', $ok ? 'verified' : 'rejected', '', $note, devloop_now(), 'user:' . $dev['id']]);
    return $r;
}

function devloop_evidence_add(string $code, string $kind, string $ref, string $url, string $note, string $by): array
{
    devloop_task_get($code);
    if (!in_array($kind, DEVLOOP_EVIDENCE, true)) throw devloop_error('סוג ראיה לא מוכר', 422);
    if ($url !== '' && !preg_match('#^https?://#i', $url)) throw devloop_error('קישור — רק http/https', 422);
    devloop_db()->prepare('INSERT INTO dev_evidence (code, kind, ref, url, note, at, by) VALUES (?,?,?,?,?,?,?)')
        ->execute([$code, $kind, mb_substr($ref, 0, 100), mb_substr($url, 0, 500), mb_substr($note, 0, 1000), devloop_now(), $by]);
    return ['ok' => true, 'id' => (int)devloop_db()->lastInsertId()];
}

/** קובץ לשיח (צילום מסך, PDF) — הגוף הוא הקובץ; ?code=…&name=…&comment_id=… */
function devloop_attach(string $by, array $q): array
{
    $code = (string)($q['code'] ?? '');
    devloop_task_get($code);
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len <= 0) throw devloop_error('לא נבחר קובץ', 422);
    if ($len > DEVLOOP_FILE_MAX) throw devloop_error('הקובץ גדול מדי — עד 8MB', 413);
    $tmp = devloop_files_dir() . '/.up_' . bin2hex(random_bytes(6));
    $in = fopen('php://input', 'rb');
    $out = fopen($tmp, 'wb');
    $n = stream_copy_to_stream($in, $out, DEVLOOP_FILE_MAX + 1);
    fclose($in); fclose($out);
    $mime = devloop_mime($tmp);
    if ($n <= 0 || $n > DEVLOOP_FILE_MAX || !preg_match('#^(image/(jpeg|png|webp|gif)|application/pdf|text/plain)$#', $mime)) {
        @unlink($tmp);
        throw devloop_error('צילום, PDF או טקסט — עד 8MB', 415);
    }
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'application/pdf' => 'pdf', 'text/plain' => 'txt'][$mime];
    $storage = bin2hex(random_bytes(12)) . '.' . $ext;
    rename($tmp, devloop_files_dir() . '/' . $storage);
    $name = mb_substr(trim((string)($q['name'] ?? '')) ?: 'file.' . $ext, 0, 120);
    devloop_db()->prepare('INSERT INTO dev_files (code, comment_id, name, mime, size, storage, at, by) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$code, (int)($q['comment_id'] ?? 0) ?: null, $name, $mime, $n, $storage, devloop_now(), $by]);
    return ['ok' => true, 'id' => (int)devloop_db()->lastInsertId()];
}

/** הקובץ עצמו — למפתח (בעוגייה) ול-Claude (באסימון). */
function devloop_file_out(int $id): never
{
    devloop_migrate();
    $st = devloop_db()->prepare('SELECT * FROM dev_files WHERE id = ?');
    $st->execute([$id]);
    $f = $st->fetch() ?: throw devloop_error('הקובץ לא נמצא', 404);
    $path = devloop_files_dir() . '/' . basename($f['storage']);
    if (!is_file($path)) throw devloop_error('הקובץ לא נמצא', 404);
    header('Content-Type: ' . $f['mime']);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename*=UTF-8\'\'' . rawurlencode($f['name']));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
    readfile($path);
    exit;
}

/**
 * 🐞 דיווח מהמסך: כותרת, מה קרה, וההקשר שנאסף לבד (מסך, גרסה, מכשיר, הבקשות והשגיאות האחרונות).
 * נפתח כמטלה מסוג באג; הצילומים — אחר כך ב-dev-attach.
 */
function devloop_report(array $dev, array $b): array
{
    $ctx = is_array($b['context'] ?? null) ? $b['context'] : [];
    $keep = array_intersect_key($ctx, array_flip(['url', 'screen', 'ua', 'version', 'lang', 'requests', 'errors', 'at']));
    $json = mb_substr(json_encode($keep, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 20000);
    $r = devloop_task_create($dev, $b + ['kind' => 'bug']);
    devloop_db()->prepare('UPDATE dev_tasks SET context = ? WHERE code = ?')->execute([$json, $r['code']]);
    return $r;
}

function devloop_comment_add(string $code, string $author, ?int $userId, string $body): array
{
    devloop_task_get($code);
    $body = trim($body);
    if ($body === '') throw devloop_error('ההערה ריקה', 422);
    if (mb_strlen($body) > DEVLOOP_COMMENT_MAX) throw devloop_error('הערה עד {n} תווים', 422, ['n' => DEVLOOP_COMMENT_MAX]);
    devloop_db()->prepare('INSERT INTO dev_comments (code, at, author, user_id, body) VALUES (?,?,?,?,?)')
        ->execute([$code, devloop_now(), $author, $userId, $body]);
    return ['ok' => true, 'id' => (int)devloop_db()->lastInsertId()];
}

/** מחיקה — רק הערה שלי, ורק כל עוד לא נענתה. */
function devloop_comment_delete(array $dev, array $b): array
{
    devloop_migrate();
    $st = devloop_db()->prepare("SELECT * FROM dev_comments WHERE id = ? AND author = 'user' AND user_id = ?");
    $st->execute([(int)($b['id'] ?? 0), $dev['id']]);
    $c = $st->fetch() ?: throw devloop_error('ההערה לא נמצאה', 404);
    $later = devloop_db()->prepare("SELECT COUNT(*) FROM dev_comments WHERE code = ? AND id > ? AND author = 'claude'");
    $later->execute([$c['code'], $c['id']]);
    if ((int)$later->fetchColumn()) throw devloop_error('כבר נענתה — אפשר להוסיף הערה חדשה', 409);
    devloop_db()->prepare('DELETE FROM dev_comments WHERE id = ?')->execute([$c['id']]);
    return ['ok' => true];
}

// ── אסימונים לקריאה בידי Claude ─────────────────────────────

function devloop_token_list(): array
{
    devloop_migrate();
    $rows = devloop_db()->query('SELECT id, label, created_by, created_at, last_used_at, revoked_at FROM dev_tokens ORDER BY id DESC')->fetchAll();
    $names = (devloop_cfg('user_names'))();
    return ['tokens' => array_map(fn($t) => ['id' => (int)$t['id'], 'label' => $t['label'], 'by' => $names[(int)$t['created_by']] ?? '—',
        'created_at' => $t['created_at'], 'last_used_at' => $t['last_used_at'], 'revoked' => (bool)$t['revoked_at']], $rows)];
}

function devloop_token_create(array $dev, array $b): array
{
    devloop_migrate();
    $label = mb_substr(trim((string)($b['label'] ?? '')), 0, 60) ?: 'Claude';
    $token = devloop_cfg('token_prefix') . bin2hex(random_bytes(24));
    devloop_db()->prepare('INSERT INTO dev_tokens (token_hash, label, created_by, created_at) VALUES (?,?,?,?)')
        ->execute([hash('sha256', $token), $label, $dev['id'], devloop_now()]);
    devloop_hook('audit', "user:{$dev['id']}", 'devtasks.token_create', 'dev_token', (int)devloop_db()->lastInsertId(), ['label' => $label]);
    return ['ok' => true, 'token' => $token];
}

function devloop_token_revoke(array $dev, array $b): array
{
    devloop_migrate();
    $st = devloop_db()->prepare('UPDATE dev_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL');
    $st->execute([devloop_now(), (int)($b['id'] ?? 0)]);
    if (!$st->rowCount()) throw devloop_error('האסימון לא נמצא', 404);
    devloop_hook('audit', "user:{$dev['id']}", 'devtasks.token_revoke', 'dev_token', (int)($b['id'] ?? 0));
    return ['ok' => true];
}

/** האסימון שבכותרת X-Dev-Token — של מפתח פעיל, ולא בוטל; עד DEVLOOP_RATE בקשות בשעה. */
function devloop_api_auth(): array
{
    devloop_migrate();
    $t = trim((string)($_SERVER['HTTP_X_DEV_TOKEN'] ?? ''));
    if (!preg_match('/^' . devloop_cfg('token_prefix') . '[0-9a-f]{48}$/', $t)) throw devloop_error('חסר אסימון (X-Dev-Token)', 401);
    $st = devloop_db()->prepare('SELECT * FROM dev_tokens WHERE token_hash = ?');
    $st->execute([hash('sha256', $t)]);
    $k = $st->fetch();
    // האסימון חי רק כל עוד מי שיצר אותו עדיין מפתח פעיל באפליקציה
    if (!$k || $k['revoked_at'] || !(devloop_cfg('is_developer'))((int)$k['created_by'])) {
        devloop_log_write('warn', 'auth', 'devapi.denied', ['message' => 'אסימון מטלות לא תקף']);
        throw devloop_error('האסימון אינו תקף', 401);
    }
    $hour = gmdate('YmdH');
    $n = $k['rate_hour'] === $hour ? (int)$k['rate_n'] + 1 : 1;
    if ($n > DEVLOOP_RATE) throw devloop_error('יותר מדי בקשות — נסו בעוד שעה', 429);
    devloop_db()->prepare('UPDATE dev_tokens SET last_used_at = ?, rate_hour = ?, rate_n = ? WHERE id = ?')->execute([devloop_now(), $hour, $n, $k['id']]);
    devloop_hook('as_claude', (int)$k['id']);
    return $k;
}

/**
 * מה ש-Claude קורא. ?code=PM-012 — מטלה אחת. ?all=1 — כל המטלות.
 * ברירת המחדל: רק מטלות שיש בהן הערה חדשה מאז הסימון האחרון (mark=1 מסמן).
 */
function devloop_api_feed(array $k, array $q): array
{
    $list = devloop_task_list();
    $upto = (int)$k['read_upto'];
    $items = $list['items'];
    if (!empty($q['code'])) $items = array_values(array_filter($items, fn($x) => $x['code'] === $q['code']));
    elseif (empty($q['all'])) $items = array_values(array_filter($items,
        fn($x) => array_filter($x['comments'], fn($c) => $c['author'] === 'user' && $c['id'] > $upto)));
    foreach ($items as &$x) foreach ($x['comments'] as &$c) $c['new'] = $c['author'] === 'user' && $c['id'] > $upto;
    unset($x, $c);
    $max = (int)devloop_db()->query('SELECT COALESCE(MAX(id), 0) FROM dev_comments')->fetchColumn();
    if (!empty($q['mark'])) devloop_db()->prepare('UPDATE dev_tokens SET read_upto = ? WHERE id = ?')->execute([$max, $k['id']]);
    return ['ok' => true, 'updated' => $list['updated'], 'read_upto' => !empty($q['mark']) ? $max : $upto,
        'next_code' => devloop_next_code(), 'items' => $items]
        // מטלה אחת — גם השורות שלה ביומן, כדי לחפש לפי מזהה הבקשה ולא לנחש
        + (!empty($q['code']) && $items ? ['log' => devloop_item_log((string)$q['code'])['rows']] : []);
}

/**
 * תשובה של Claude במטלה (body), ואפשר באותה בקשה: status (גם במטלה מהקוד — שכבה מעל הקובץ),
 * evidence [{kind, ref, url, note}], ו-steps (צעדי בדיקה — רק במטלה מהמסך). אחרי פריסה: status=verify.
 */
function devloop_api_comment(array $k, array $b): array
{
    $code = (string)($b['code'] ?? '');
    $r = trim((string)($b['body'] ?? '')) !== '' ? devloop_comment_add($code, 'claude', null, (string)$b['body']) : ['ok' => true];
    foreach (is_array($b['evidence'] ?? null) ? $b['evidence'] : [] as $e) {
        devloop_evidence_add($code, (string)($e['kind'] ?? 'note'), (string)($e['ref'] ?? ''), (string)($e['url'] ?? ''), (string)($e['note'] ?? ''), 'claude');
    }
    if (is_array($b['steps'] ?? null)) {
        $steps = array_values(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 300), $b['steps']), 'strlen'));
        devloop_db()->prepare('UPDATE dev_tasks SET steps = ? WHERE code = ?')->execute([json_encode(array_slice($steps, 0, 20), JSON_UNESCAPED_UNICODE), $code]);
    }
    if (!empty($b['status'])) {
        // פריסה (GitHub) לא מחזירה מטלה שכבר אומתה ונסגרה — רק if_open=0 מכריח
        $skip = ($b['if_open'] ?? 1) && devloop_task_get($code)['status'] === 'closed';
        if (!$skip) devloop_task_status($code, (string)$b['status'], (string)($b['by'] ?? '') === 'github' ? 'github' : 'claude');
        $r['status_skipped'] = $skip;
    }
    // התראה אחת לבקשה: מה שמחכה עכשיו למפתח
    $st = !empty($b['status']) && empty($r['status_skipped']) ? (string)$b['status'] : '';
    $title = $st || trim((string)($b['body'] ?? '')) !== '' ? devloop_task_get($code)['title'] : '';
    if ($st === 'verify') devloop_notify("🧪 $code ממתינה לאימות שלך", "$title\n\nנפרס — עברו על \"איך בודקים\" ולחצו ✅ עובד אצלי או ↩️ לא עובד.", $code);
    elseif ($st === 'question') devloop_notify("❓ $code — שאלה אליך", "$title\n\n" . trim((string)($b['body'] ?? '')), $code);
    elseif (trim((string)($b['body'] ?? '')) !== '') devloop_notify("💬 Claude ענה ב-$code", "$title\n\n" . mb_substr(trim((string)$b['body']), 0, 1500), $code);
    return $r;
}
// i18n-none-end
