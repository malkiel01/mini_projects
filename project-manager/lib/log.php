<?php
/**
 * יומן הפעילות — כל מה שקורה באפליקציה, כדי שאפשר יהיה להבין כל תקלה בדיעבד.
 *
 * נשמר בקובץ נפרד, data/log.sqlite (מוחרג בפריסה ב-`project-manager/data/*.sqlite*`),
 * כדי שהיומן לא ינפח את המסד הראשי ולא ינעל אותו.
 *
 * מה נרשם:
 *   api     — כל בקשה ל-api.php: פעולה, משתמש, קוד תשובה, משך, שגיאה
 *   auth    — כניסה (הצלחה / כשל והסיבה), נעילה, הרשמה, הקמה, יציאה, איפוס סיסמה
 *   admin   — פעולות המפתח: אישור, חסימה, הגדרות
 *   data    — יצירה ושינוי של פרויקטים, שלבים, אנשי קשר (גם ביומן הביקורת)
 *   client  — שגיאות JavaScript בדפדפן, שהדף שולח לבד
 *   php     — חריגות, אזהרות ושגיאות קטלניות של PHP
 *
 * מה **לא** נרשם: סיסמאות, אסימונים, עוגיות. pm_log_clean מסנן מפתחות כאלה.
 *
 * שמירה: 90 יום, ועד 200,000 רשומות. הניקוי רץ מדי פעם, לא בכל בקשה.
 *
 * קריאה: הגדרות ← "יומן" (המפתח), וגם log.php עם אסימון שמתחלף אחת לשבוע —
 * לחיבור לצ'אט.
 */

declare(strict_types=1);

if (!defined('PM_LOG_DB')) define('PM_LOG_DB', __DIR__ . '/../data/log.sqlite');

const PM_LOG_DAYS = 90;
const PM_LOG_MAX_ROWS = 200000;
const PM_LOG_SECRET_KEYS = ['password', 'password2', 'current', 'token', 'csrf', 'pm_session', 'mp_session', 'cookie'];

/** מזהה לבקשה הנוכחית — כל השורות של אותה בקשה חולקות אותו. */
function pm_request_id(): string
{
    static $id = null;
    return $id ??= substr(bin2hex(random_bytes(6)), 0, 10);
}

function pm_log_db(): ?PDO
{
    static $pdo = false;
    if ($pdo !== false) return $pdo;
    try {
        $dir = dirname(PM_LOG_DB);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $pdo = new PDO('sqlite:' . PM_LOG_DB, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 3000');
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS log (
                id        INTEGER PRIMARY KEY,
                at        TEXT NOT NULL,
                level     TEXT NOT NULL,          -- info · warn · error
                category  TEXT NOT NULL,          -- api · auth · admin · data · client · php
                event     TEXT NOT NULL,
                message   TEXT NOT NULL DEFAULT '',
                user_id   INTEGER,
                username  TEXT NOT NULL DEFAULT '',
                ip        TEXT NOT NULL DEFAULT '',
                ua        TEXT NOT NULL DEFAULT '',
                method    TEXT NOT NULL DEFAULT '',
                action    TEXT NOT NULL DEFAULT '',
                status    INTEGER,
                ms        INTEGER,
                req       TEXT NOT NULL DEFAULT '',
                data_json TEXT NOT NULL DEFAULT '{}'
            );
            CREATE INDEX IF NOT EXISTS log_at ON log (at);
            CREATE INDEX IF NOT EXISTS log_level ON log (level, at);
            CREATE INDEX IF NOT EXISTS log_cat ON log (category, at);
            CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);
            SQL);
    } catch (Throwable $e) {
        error_log('project-manager log: ' . $e->getMessage());
        $pdo = null; // היומן לעולם לא מפיל את האפליקציה
    }
    return $pdo;
}

/** מסיר סיסמאות ואסימונים, ומקצר ערכים ארוכים. */
function pm_log_clean($data, int $depth = 0)
{
    if (!is_array($data)) return is_string($data) && mb_strlen($data) > 2000 ? mb_substr($data, 0, 2000) . '…' : $data;
    if ($depth > 4) return '…';
    $out = [];
    foreach ($data as $k => $v) {
        $out[$k] = in_array(strtolower((string)$k), PM_LOG_SECRET_KEYS, true) ? '***' : pm_log_clean($v, $depth + 1);
    }
    return $out;
}

/** המשתמש שאליו משייכים את השורה — נקבע אחרי זיהוי (pm_log_user). */
function pm_log_user(?array $u = null, bool $set = false): ?array
{
    static $user = null;
    if ($set) $user = $u;
    return $user;
}

/**
 * כתיבת שורה. $extra: message, status, ms, action, user, data.
 * לא זורק לעולם — תקלה ביומן נכתבת ל-error_log של השרת בלבד.
 */
function pm_log(string $level, string $category, string $event, array $extra = []): void
{
    $db = pm_log_db();
    if (!$db) return;
    try {
        $u = $extra['user'] ?? pm_log_user();
        $db->prepare(
            'INSERT INTO log (at, level, category, event, message, user_id, username, ip, ua, method, action, status, ms, req, data_json)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            gmdate('Y-m-d\TH:i:s\Z'), $level, $category, $event,
            mb_substr((string)($extra['message'] ?? ''), 0, 4000),
            isset($u['id']) ? (int)$u['id'] : null,
            (string)($extra['username'] ?? $u['username'] ?? ''),
            $_SERVER['REMOTE_ADDR'] ?? '',
            mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
            $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            (string)($extra['action'] ?? ($_GET['action'] ?? '')),
            isset($extra['status']) ? (int)$extra['status'] : null,
            isset($extra['ms']) ? (int)$extra['ms'] : null,
            pm_request_id(),
            json_encode(pm_log_clean($extra['data'] ?? []), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
        ]);
        if (random_int(1, 500) === 1) pm_log_prune($db);
    } catch (Throwable $e) {
        error_log('project-manager log write: ' . $e->getMessage());
    }
}

function pm_log_prune(PDO $db): void
{
    $db->prepare('DELETE FROM log WHERE at < ?')->execute([gmdate('Y-m-d\TH:i:s\Z', time() - PM_LOG_DAYS * 86400)]);
    $db->exec('DELETE FROM log WHERE id < (SELECT MAX(id) FROM log) - ' . PM_LOG_MAX_ROWS);
}

/** שגיאות PHP — גם אזהרות, וגם קטלניות (shutdown). */
function pm_log_install_handlers(): void
{
    set_error_handler(function (int $no, string $msg, string $file, int $line) {
        if (!(error_reporting() & $no)) return false;
        pm_log($no & (E_WARNING | E_USER_WARNING | E_NOTICE | E_USER_NOTICE | E_DEPRECATED) ? 'warn' : 'error', 'php', 'php.error',
            ['message' => $msg, 'data' => ['file' => basename($file), 'line' => $line, 'code' => $no]]);
        return false; // ממשיכים לטיפול הרגיל של PHP
    });
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            pm_log('error', 'php', 'php.fatal', ['message' => $e['message'], 'data' => ['file' => basename($e['file']), 'line' => $e['line']]]);
        }
    });
}

// ---- קריאה ----

/**
 * $f: level (info|warn|error — וכל מה שחמור ממנו), category, q (טקסט חופשי),
 *     user, since (ISO), before_id, limit (עד 1000).
 */
function pm_log_query(array $f): array
{
    $db = pm_log_db();
    if (!$db) return [];
    $where = ['1 = 1'];
    $args = [];
    $levels = ['info' => ['info', 'warn', 'error'], 'warn' => ['warn', 'error'], 'error' => ['error']];
    if (isset($levels[$f['level'] ?? ''])) {
        $where[] = 'level IN (' . implode(',', array_fill(0, count($levels[$f['level']]), '?')) . ')';
        array_push($args, ...$levels[$f['level']]);
    }
    if (!empty($f['category'])) { $where[] = 'category = ?'; $args[] = (string)$f['category']; }
    if (!empty($f['user'])) { $where[] = '(username = ? OR user_id = ?)'; array_push($args, (string)$f['user'], (int)$f['user']); }
    if (!empty($f['since'])) { $where[] = 'at >= ?'; $args[] = (string)$f['since']; }
    if (!empty($f['before_id'])) { $where[] = 'id < ?'; $args[] = (int)$f['before_id']; }
    if (!empty($f['req'])) { $where[] = 'req = ?'; $args[] = (string)$f['req']; }
    if (!empty($f['q'])) {
        $where[] = '(event LIKE ? OR message LIKE ? OR data_json LIKE ? OR action LIKE ? OR ip LIKE ?)';
        $like = '%' . strtr((string)$f['q'], ['%' => '', '_' => '']) . '%';
        array_push($args, $like, $like, $like, $like, $like);
    }
    $limit = max(1, min(1000, (int)($f['limit'] ?? 200)));
    $st = $db->prepare('SELECT * FROM log WHERE ' . implode(' AND ', $where) . " ORDER BY id DESC LIMIT $limit");
    $st->execute($args);
    return array_map(function ($r) {
        $r['id'] = (int)$r['id'];
        $r['data'] = json_decode($r['data_json'], true) ?: (object)[];
        unset($r['data_json']);
        return $r;
    }, $st->fetchAll());
}

function pm_log_stats(): array
{
    $db = pm_log_db();
    if (!$db) return [];
    $day = gmdate('Y-m-d\TH:i:s\Z', time() - 86400);
    $st = $db->prepare('SELECT level, COUNT(*) AS n FROM log WHERE at >= ? GROUP BY level');
    $st->execute([$day]);
    $last24 = array_column($st->fetchAll(), 'n', 'level');
    return [
        'total'   => (int)$db->query('SELECT COUNT(*) FROM log')->fetchColumn(),
        'oldest'  => $db->query('SELECT MIN(at) FROM log')->fetchColumn() ?: null,
        'last24h' => ['info' => (int)($last24['info'] ?? 0), 'warn' => (int)($last24['warn'] ?? 0), 'error' => (int)($last24['error'] ?? 0)],
    ];
}

/** שורה אחת כטקסט — לקריאה מהירה בצ'אט. */
function pm_log_line(array $r): string
{
    $who = $r['username'] !== '' ? $r['username'] : ($r['user_id'] ? '#' . $r['user_id'] : '-');
    $data = json_encode($r['data'], JSON_UNESCAPED_UNICODE);
    return sprintf('%s %-5s %-6s %-22s %s %s %s%s%s%s req=%s',
        $r['at'], strtoupper($r['level']), $r['category'], $r['event'], $who, $r['ip'],
        $r['method'] . ($r['action'] !== '' ? ' ' . $r['action'] : ''),
        $r['status'] !== null ? ' →' . $r['status'] : '',
        $r['ms'] !== null ? ' ' . $r['ms'] . 'ms' : '',
        ($r['message'] !== '' ? ' | ' . $r['message'] : '') . ($data !== '{}' && $data !== '[]' ? ' ' . $data : ''),
        $r['req']);
}

// ---- אסימון הקריאה לצ'אט: מתחלף לבד אחת לשבוע ----

const PM_LOG_TOKEN_DAYS = 7;

/** האסימון הנוכחי ותאריך התפוגה שלו — יוצר חדש אם פג או אם $rotate. */
function pm_log_token(bool $rotate = false): array
{
    $db = pm_log_db();
    if (!$db) throw new RuntimeException('היומן לא זמין'); // i18n-none — חריגה פנימית (ביומן; למשתמש "תקלה בשרת")
    $get = fn($k) => ($s = $db->prepare('SELECT value FROM meta WHERE key = ?')) && $s->execute([$k]) ? $s->fetchColumn() : false;
    $token = $get('read_token');
    $created = $get('read_token_at');
    if ($rotate || !$token || !$created || strtotime($created) < time() - PM_LOG_TOKEN_DAYS * 86400) {
        $token = bin2hex(random_bytes(24));
        $created = gmdate('Y-m-d\TH:i:s\Z');
        $up = $db->prepare('INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
        $up->execute(['read_token', $token]);
        $up->execute(['read_token_at', $created]);
        pm_log('info', 'admin', 'log.token_rotated', ['message' => $rotate ? 'הוחלף ידנית' : 'הוחלף אוטומטית (שבוע)']);
    }
    return ['token' => $token, 'created_at' => $created,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($created) + PM_LOG_TOKEN_DAYS * 86400)];
}

function pm_log_token_valid(string $given): bool
{
    if (strlen($given) < 32) return false;
    $cur = pm_log_token();
    return hash_equals($cur['token'], $given);
}
