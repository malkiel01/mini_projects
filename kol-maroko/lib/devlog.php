<?php
/**
 * יומן הבקשות והשגיאות (פרוסה F1). טבלה dev_log ב-data/kol.sqlite.
 *
 * מה נרשם: שורה לכל בקשה (פעולה, תוצאה, משך, מי), כל חריגה עם מחסנית, כל תשובת 5xx, כל אזהרת PHP (גם קטלנית),
 * ושגיאות מהדפדפן. מזהה הבקשה (X-Request-Id) משותף לכל השורות של אותה בקשה, ומופיע בהודעה שהמשתמש רואה.
 *
 * מה לעולם לא נרשם: גוף של בקשה או של קובץ, סיסמאות, אסימונים, מפתחות, ותוכן של secrets.json. היומן אינו קורא
 * את הגוף כלל; ומה שכן מגיע אליו (הודעת שגיאה, מחסנית מהדפדפן) עובר devlog_scrub.
 *
 * השורות נצברות בזיכרון ונכתבות בסוף הבקשה — אחרי ביטול של טרנזקציה שנשארה פתוחה, כדי שהשגיאה לא תימחק איתה.
 * שום דבר כאן לא זורק: תקלה ברישום נכתבת ל-error_log, והבקשה ממשיכה.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const DEVLOG_MAX_ROWS = 5000;
const DEVLOG_DAYS = 30;
const DEVLOG_CLEAN_EVERY = 3600;
const DEVLOG_FOLD_WINDOW = 86400;          // שגיאת דפדפן עם אותה חתימה בתוך יממה — שורה אחת עם מונה
const DEVLOG_PER_REQUEST = 25;             // כמה שורות לכל היותר מבקשה אחת (אזהרה בלולאה לא מציפה)
// בקשות תכופות שהצלחתן אינה מעניינת (פעימת נעילה, רינדור אחרי כל הקשה, חלקי קובץ). כישלון שלהן נרשם תמיד.
const DEVLOG_QUIET = ['lock', 'render', 'upload_chunk', 'media', 'file', 'dev-client-error', 'dev-log', 'dev-log-links'];
const DEVLOG_HIDDEN = '[הוסתר]';

function devlog_req(): string
{
    static $id = null;
    return $id ??= bin2hex(random_bytes(5));
}

/** מצב היומן בבקשה הזו. */
function &devlog_state(): array
{
    static $s = ['rows' => [], 'user' => null, 'error' => '', 'started' => false, 't0' => 0.0, 'entry' => 'api', 'n' => 0];
    return $s;
}

/**
 * הסתרה: ערך תחת מפתח רגיש, ומחרוזות שנראות כמו סוד (אסימון, hex ארוך, base64 ארוך, "password=…").
 * נתיבים מקוצרים ליחסיים, כדי שלא ייראו כמו base64 ולא יחשפו את מבנה השרת.
 */
function devlog_scrub(mixed $v, string $key = ''): mixed
{
    if (is_array($v)) {
        $out = [];
        foreach ($v as $k => $x) $out[$k] = devlog_scrub($x, (string)$k);
        return $out;
    }
    if ($key !== '' && is_scalar($v) && preg_match('/pass|secret|token|csrf|authorization|cookie|signature|סיסמ|(^|_)(key|sig)$/iu', $key)) return DEVLOG_HIDDEN;
    if (!is_string($v)) return $v;
    $v = str_replace([dirname(__DIR__) . '/', dirname(__DIR__)], '', $v);
    $v = preg_replace('/\b(kmd_|kol_|sk-|Bearer\s+)[A-Za-z0-9._-]{6,}/', '$1' . DEVLOG_HIDDEN, $v);
    $v = preg_replace('/((?:pass(?:word|wd)?|secret|token|api[_-]?key|authorization|csrf|סיסמה)["\']?\s*[:=]\s*["\']?)[^\s"\'&,;]+/iu', '$1' . DEVLOG_HIDDEN, $v);
    $v = preg_replace('/\b[0-9a-f]{32,}\b/i', DEVLOG_HIDDEN, $v);
    $v = preg_replace('/(?<![\w.\/])[A-Za-z0-9+\/_-]{40,}={0,2}/', DEVLOG_HIDDEN, $v);
    return (string)$v;
}

/** חתימה של שגיאה: אותה תקלה גם כשהמספרים שבה משתנים. */
function devlog_sig(string $category, string $event, string $message, string $where = ''): string
{
    return substr(sha1($category . '|' . $event . '|' . preg_replace('/\d+/', '#', $message) . '|' . $where), 0, 16);
}

/**
 * שורה ליומן. $extra: message, status, ms, action, method, data (מערך), user (['sub','name']), fold (שורה אחת עם מונה).
 * לא זורקת לעולם.
 */
function devlog_write(string $level, string $category, string $event, array $extra = []): void
{
    try {
        $s = &devlog_state();
        if (++$s['n'] > DEVLOG_PER_REQUEST) return;
        $u = $extra['user'] ?? $s['user'];
        $msg = mb_substr((string)devlog_scrub((string)($extra['message'] ?? '')), 0, 2000);
        $data = devlog_scrub(is_array($extra['data'] ?? null) ? $extra['data'] : []);
        $where = (string)($data['file'] ?? $data['source'] ?? '') . ':' . (string)($data['line'] ?? '');
        $s['rows'][] = [
            'at' => now_iso(), 'level' => in_array($level, ['info', 'warn', 'error'], true) ? $level : 'info',
            'category' => mb_substr($category, 0, 30), 'event' => mb_substr($event, 0, 60), 'message' => $msg,
            'sub' => mb_substr((string)($u['sub'] ?? ''), 0, 120), 'username' => mb_substr((string)($u['name'] ?? $u['username'] ?? ''), 0, 80),
            'ua' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
            'method' => (string)($extra['method'] ?? ($_SERVER['REQUEST_METHOD'] ?? '')),
            'action' => mb_substr((string)($extra['action'] ?? ($_GET['action'] ?? '')), 0, 60),
            'status' => isset($extra['status']) ? (int)$extra['status'] : null, 'ms' => isset($extra['ms']) ? (int)$extra['ms'] : null,
            'req' => devlog_req(),
            'data_json' => mb_substr(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}', 0, 12000),
            'sig' => $level === 'info' ? '' : devlog_sig($category, $event, $msg, $where),
            'fold' => !empty($extra['fold']),
        ];
        if (!$s['started']) devlog_flush();        // מחוץ לבקשת API (CLI, בדיקות) — כותבים מיד
    } catch (Throwable $e) {
        error_log('kol-maroko devlog: ' . $e->getMessage());
    }
}

/** כתיבת השורות שנצברו. טרנזקציה שנשארה פתוחה (בקשה שנפלה באמצע) מתבטלת קודם. */
function devlog_flush(): void
{
    $s = &devlog_state();
    if (!$s['rows']) return;
    $rows = $s['rows'];
    $s['rows'] = [];
    try {
        $pdo = db();
        if ($pdo->inTransaction()) $pdo->rollBack();
        $ins = $pdo->prepare('INSERT INTO dev_log (at, level, category, event, message, sub, username, ua, method, action, status, ms, req, data_json, sig, count, last_at)
                              VALUES (:at, :level, :category, :event, :message, :sub, :username, :ua, :method, :action, :status, :ms, :req, :data_json, :sig, 1, :at)');
        $fold = $pdo->prepare('UPDATE dev_log SET count = count + 1, last_at = ? WHERE id = (SELECT MAX(id) FROM dev_log WHERE sig = ? AND at > ?)');
        foreach ($rows as $r) {
            $isFold = $r['fold'];
            unset($r['fold']);
            if ($isFold && $r['sig'] !== '') {
                $fold->execute([$r['at'], $r['sig'], gmdate('Y-m-d\TH:i:s\Z', time() - DEVLOG_FOLD_WINDOW)]);
                if ($fold->rowCount()) continue;
            }
            $ins->execute($r);
        }
        if ((int)(setting('devlog_clean_at') ?? 0) < time() - DEVLOG_CLEAN_EVERY) devlog_clean();
    } catch (Throwable $e) {
        error_log('kol-maroko devlog flush: ' . $e->getMessage());
    }
}

/**
 * ניקוי: מה שישן מ-30 יום, ואז עד 5,000 שורות — קודם שורות "info" הישנות (כדי שבקשות רגילות לא ידחקו שגיאות),
 * ואחר כך הישנות מכל סוג. מתועד כשירות רקע (dev_runs).
 */
function devlog_clean(): array
{
    $pdo = db();
    setting_set('devlog_clean_at', (string)time());
    try {
        $old = $pdo->prepare('DELETE FROM dev_log WHERE at < ?');
        $old->execute([gmdate('Y-m-d\TH:i:s\Z', time() - DEVLOG_DAYS * 86400)]);
        $removed = $old->rowCount();
        $over = (int)$pdo->query('SELECT COUNT(*) FROM dev_log')->fetchColumn() - DEVLOG_MAX_ROWS;
        if ($over > 0) {
            $removed += $pdo->exec("DELETE FROM dev_log WHERE id IN (SELECT id FROM dev_log WHERE level = 'info' ORDER BY id LIMIT $over)");
            $over = (int)$pdo->query('SELECT COUNT(*) FROM dev_log')->fetchColumn() - DEVLOG_MAX_ROWS;
            if ($over > 0) $removed += $pdo->exec("DELETE FROM dev_log WHERE id IN (SELECT id FROM dev_log ORDER BY id LIMIT $over)");
        }
        $left = (int)$pdo->query('SELECT COUNT(*) FROM dev_log')->fetchColumn();
        dev_run_note('log_clean', true, "נמחקו $removed · נשארו $left");
        return ['removed' => $removed, 'rows' => $left];
    } catch (Throwable $e) {
        dev_run_note('log_clean', false, $e->getMessage());
        return ['removed' => 0, 'rows' => -1];
    }
}

/** ריצה של שירות רקע או של בדיקת תקינות — לטבלה dev_runs. נשמרות 50 האחרונות לכל שם. לא זורקת. */
function dev_run_note(string $name, bool $ok, string $note = ''): void
{
    try {
        $pdo = db();
        $pdo->prepare('INSERT INTO dev_runs (name, at, ok, note) VALUES (?, ?, ?, ?)')->execute([$name, now_iso(), $ok ? 1 : 0, mb_substr((string)devlog_scrub($note), 0, 500)]);
        $pdo->prepare('DELETE FROM dev_runs WHERE name = ? AND id NOT IN (SELECT id FROM dev_runs WHERE name = ? ORDER BY id DESC LIMIT 50)')->execute([$name, $name]);
    } catch (Throwable $e) {
        error_log('kol-maroko dev_runs: ' . $e->getMessage());
    }
}

function devlog_user(?array $user): void
{
    devlog_state()['user'] = $user ? ['sub' => (string)($user['sub'] ?? ''), 'name' => (string)($user['name'] ?? '')] : null;
}

/** מחסנית בלי ארגומנטים (ארגומנט יכול להיות סוד): "lib/files.php:120 file_store". */
function devlog_trace(Throwable $e): array
{
    $out = [];
    foreach (array_slice($e->getTrace(), 0, 15) as $f) {
        $out[] = str_replace(dirname(__DIR__) . '/', '', (string)($f['file'] ?? '?')) . ':' . ($f['line'] ?? '?') . ' ' . (isset($f['class']) ? $f['class'] . '::' : '') . ($f['function'] ?? '');
    }
    return $out;
}

/** חריגה ב-catch של נקודת הכניסה. ApiError מתחת ל-500 היא תשובה רגילה למשתמש — רק ההודעה נכנסת לשורת הבקשה. */
function devlog_exception(Throwable $e): void
{
    try {
        devlog_state()['error'] = mb_substr($e->getMessage(), 0, 500);
        if ($e instanceof ApiError && ($e->getCode() ?: 400) < 500) return;
        devlog_write('error', 'php', 'exception', ['message' => get_class($e) . ': ' . $e->getMessage(), 'data' => [
            'file' => str_replace(dirname(__DIR__) . '/', '', $e->getFile()), 'line' => $e->getLine(), 'trace' => devlog_trace($e)]]);
    } catch (Throwable $x) {
        error_log('kol-maroko devlog: ' . $x->getMessage());
    }
}

/**
 * בראש נקודת כניסה (api.php, agent.php): מזהה בקשה בכותרת, לכידת אזהרות PHP, ובסוף — שורת הבקשה והכתיבה.
 * שגיאה קטלנית שלא הספיקה להחזיר תשובה — מחזירה JSON עם מזהה הבקשה.
 */
function devlog_begin(string $entry = 'api'): void
{
    $s = &devlog_state();
    if ($s['started']) return;
    $s['started'] = true;
    $s['t0'] = microtime(true);
    $s['entry'] = $entry;
    if (!headers_sent()) header('X-Request-Id: ' . devlog_req());
    set_error_handler(function (int $no, string $msg, string $file, int $line): bool {
        if (!(error_reporting() & $no)) return false;
        $soft = $no & (E_WARNING | E_USER_WARNING | E_NOTICE | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED);
        $trace = [];
        foreach (array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 9), 1) as $f) {
            $trace[] = str_replace(dirname(__DIR__) . '/', '', (string)($f['file'] ?? '?')) . ':' . ($f['line'] ?? '?') . ' ' . ($f['function'] ?? '');
        }
        devlog_write($soft ? 'warn' : 'error', 'php', 'php.error', ['message' => $msg,
            'data' => ['file' => str_replace(dirname(__DIR__) . '/', '', $file), 'line' => $line, 'trace' => $trace]]);
        return false;
    });
    register_shutdown_function(function (): void {
        try {
            $s = &devlog_state();
            $e = error_get_last();
            $fatal = $e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
            if ($fatal) {
                $s['n'] = 0;
                devlog_write('error', 'php', 'php.fatal', ['message' => $e['message'], 'data' => ['file' => str_replace(dirname(__DIR__) . '/', '', $e['file']), 'line' => $e['line']]]);
                $s['error'] = $s['error'] ?: 'שגיאה קטלנית';
                if (!headers_sent()) {
                    http_response_code(500);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => false, 'error' => 'תקלה בשרת · מזהה ' . devlog_req(), 'req' => devlog_req()], JSON_UNESCAPED_UNICODE);
                }
            }
            $status = http_response_code() ?: 200;
            $action = (string)($_GET['action'] ?? '');
            if ($status >= 400 || !in_array($action, DEVLOG_QUIET, true)) {
                $s['n'] = min($s['n'], DEVLOG_PER_REQUEST - 1);          // שורת הבקשה נכנסת תמיד
                devlog_write($status >= 500 ? 'error' : ($status >= 400 ? 'warn' : 'info'), $s['entry'], 'request',
                    ['status' => $status, 'ms' => (int)round((microtime(true) - $s['t0']) * 1000), 'message' => $s['error']]);
            }
            devlog_flush();
        } catch (Throwable $x) {
            error_log('kol-maroko devlog shutdown: ' . $x->getMessage());
        }
    });
}

/**
 * שגיאה מהדפדפן (POST dev-client-error): error, unhandledrejection, ובקשת API שנכשלה. אותה חתימה — שורה אחת עם מונה.
 * נשמרים רק שדות ידועים, מקוצרים ואחרי הסתרה.
 */
function devlog_client(array $user, array $b): array
{
    $kind = in_array($b['kind'] ?? '', ['error', 'promise', 'api'], true) ? $b['kind'] : 'error';
    $str = fn(string $k, int $n) => mb_substr(is_scalar($b[$k] ?? null) ? (string)$b[$k] : '', 0, $n);
    $source = preg_replace('~^https?://[^/]+/~', '', strtok($str('source', 300), '?') ?: '');
    devlog_write($kind === 'api' && (int)($b['status'] ?? 0) < 500 && (int)($b['status'] ?? 0) !== 0 ? 'warn' : 'error', 'client', 'client.' . $kind, [
        'message' => $str('message', 1000), 'user' => $user, 'fold' => true,
        'method' => 'CLIENT', 'action' => $str('action', 60), 'status' => isset($b['status']) ? (int)$b['status'] : null,
        'data' => array_filter([
            'source' => $source, 'line' => (int)($b['line'] ?? 0) ?: null, 'col' => (int)($b['col'] ?? 0) ?: null,
            'stack' => $str('stack', 3000), 'page' => strtok($str('page', 200), '?') ?: '', 'last_action' => $str('last_action', 80),
            'viewport' => $str('viewport', 20), 'version' => $str('version', 20), 'failed_req' => preg_replace('/[^a-f0-9]/', '', $str('failed_req', 20)),
            'times' => max(1, min(1000, (int)($b['times'] ?? 1))),
        ], fn($x) => $x !== null && $x !== ''),
    ]);
    return ['logged' => true];
}

/**
 * שורות מהיומן. המפתחות של DevLoop: after · before · level (error | warn ומעלה) · ids [] · reqs [] · q · limit (עד 500).
 * ושל מסך המפתח: from / to (ISO) · category · user (sub או חלק משם) · action · req.
 */
function devlog_rows(array $q): array
{
    $w = ['1 = 1'];
    $a = [];
    if (!empty($q['after'])) { $w[] = 'id > ?'; $a[] = (int)$q['after']; }
    if (!empty($q['before'])) { $w[] = 'id < ?'; $a[] = (int)$q['before']; }
    if (($q['level'] ?? '') === 'error') $w[] = "level = 'error'";
    elseif (($q['level'] ?? '') === 'warn') $w[] = "level IN ('warn', 'error')";
    foreach (['ids' => 'id', 'reqs' => 'req'] as $k => $col) {
        if (!array_key_exists($k, $q)) continue;
        $vals = array_values(array_slice(array_filter((array)$q[$k], fn($v) => is_scalar($v) && $v !== ''), 0, 200));
        if (!$vals) return [];
        $w[] = "$col IN (" . implode(',', array_fill(0, count($vals), '?')) . ')';
        array_push($a, ...array_map('strval', $vals));
    }
    foreach (['from' => 'at >= ?', 'to' => 'at <= ?', 'category' => 'category = ?', 'action' => 'action = ?', 'req' => 'req = ?'] as $k => $sql) {
        if (is_scalar($q[$k] ?? null) && (string)$q[$k] !== '') { $w[] = $sql; $a[] = (string)$q[$k]; }
    }
    $like = fn(string $v) => '%' . strtr($v, ['%' => '', '_' => '']) . '%';
    if (is_scalar($q['user'] ?? null) && (string)$q['user'] !== '') { $w[] = '(sub = ? OR username LIKE ?)'; array_push($a, (string)$q['user'], $like((string)$q['user'])); }
    if (is_scalar($q['q'] ?? null) && (string)$q['q'] !== '') {
        $w[] = '(event LIKE ? OR message LIKE ? OR action LIKE ? OR data_json LIKE ? OR req = ?)';
        $l = $like((string)$q['q']);
        array_push($a, $l, $l, $l, $l, (string)$q['q']);
    }
    $limit = max(1, min(500, (int)($q['limit'] ?? 100)));
    $st = db()->prepare('SELECT * FROM dev_log WHERE ' . implode(' AND ', $w) . ' ORDER BY id ' . (!empty($q['after']) ? 'ASC' : 'DESC') . " LIMIT $limit");
    $st->execute($a);
    return array_map(function (array $r): array {
        $r['id'] = (int)$r['id'];
        $r['count'] = (int)$r['count'];
        $r['status'] = $r['status'] === null ? null : (int)$r['status'];
        $r['ms'] = $r['ms'] === null ? null : (int)$r['ms'];
        $r['data'] = json_decode((string)$r['data_json'], true) ?: [];
        unset($r['data_json']);
        return $r;
    }, $st->fetchAll());
}

function devlog_max_id(): int
{
    return (int)db()->query('SELECT COALESCE(MAX(id), 0) FROM dev_log')->fetchColumn();
}
