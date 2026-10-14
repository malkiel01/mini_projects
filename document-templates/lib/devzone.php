<?php
/**
 * אזור הפיתוח: לוג מעקב לדיבוג, סמכויות אכיפה של המפתח, מצב המערכת,
 * רשימת "פתוח / סגור" — והגדרות המשתמש (שם, סיסמה).
 *
 * עיקרון שנשאר: המפתח רואה מטא-דאטה בלבד — מספר, מצב, בעלים, תאריכים, כמויות.
 * לא כותרות, לא ערכים, לא קבצים. כל פעולת אכיפה נרשמת ביומן הביקורת של
 * המפתח, ובמסמך עצמו — ביומן המסמך (שרשרת שאי אפשר למחוק ממנה).
 */

declare(strict_types=1);

const DEV_LOG_DAYS = 30;
const DEV_ITEMS_FILE = __DIR__ . '/../dev/open-items.json';

function devzone_migrate(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS dev_audit (
            id          INTEGER PRIMARY KEY,
            at          TEXT NOT NULL,
            dev_id      INTEGER NOT NULL REFERENCES users(id),
            op          TEXT NOT NULL,
            target_type TEXT NOT NULL,
            target_id   INTEGER NOT NULL,
            reason      TEXT NOT NULL,
            detail      TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_dev_log_at ON dev_log(at);
        SQL);
    devtasks_migrate($pdo);
    // לוג המעקב: מה שצריך כדי לשחזר תקלה — בלי תוכן של משתמשים
    $cols = array_column($pdo->query('PRAGMA table_info(dev_log)')->fetchAll(), 'name');
    foreach (['method TEXT', 'status INTEGER', 'actor TEXT', 'ref TEXT', 'ip TEXT', 'message TEXT', 'trace TEXT', 'page TEXT'] as $def) {
        if (!in_array(strtok($def, ' '), $cols, true)) $pdo->exec("ALTER TABLE dev_log ADD COLUMN $def");
    }
}

// ── לוג מעקב ─────────────────────────────────────────────────

/**
 * רשומה ללוג. $e — השגיאה (אם הייתה). ref — מזהים מהבקשה (מסמך, תבנית),
 * לא ערכים. בשגיאת שרת — גם הקובץ והשורה.
 */
function dev_track(?array $session, string $action, float $started, int $status, ?Throwable $e = null, array $extra = []): void
{
    try {
        $ref = [];
        foreach (['id', 'template_id', 'document_id', 'participant_id', 'user_id', 'att'] as $k) {
            $v = $_GET[$k] ?? ($GLOBALS['dt_body'][$k] ?? null);
            if (is_numeric($v)) $ref[] = "$k=" . (int)$v;
        }
        $actor = $session ? 'user' : (str_starts_with($action, 'ext_') ? 'api' : (str_starts_with($action, 'fill_') ? 'fill' : (str_starts_with($action, 'devapi_') ? 'claude' : 'guest')));
        $trace = null;
        if ($e && !($e instanceof ApiError)) $trace = basename($e->getFile()) . ':' . $e->getLine() . ' ' . get_class($e);
        db()->prepare('INSERT INTO dev_log (at, user_id, action, error_code, duration_ms, method, status, actor, ref, ip, message, trace, page)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([now_iso(), $session ? (int)$session['id'] : null, mb_substr($action, 0, 60), $status >= 400 ? (string)$status : null,
                (int)((microtime(true) - $started) * 1000), $_SERVER['REQUEST_METHOD'] ?? 'GET', $status, $actor,
                $ref ? implode(' ', $ref) : null, client_ip(),
                $e ? mb_substr($e->getMessage(), 0, 500) : ($extra['message'] ?? null), $extra['trace'] ?? $trace, $extra['page'] ?? null]);
        if (random_int(1, 200) === 1) {
            db()->prepare('DELETE FROM dev_log WHERE at < ?')->execute([gmdate('Y-m-d\TH:i:s\Z', time() - DEV_LOG_DAYS * 86400)]);
        }
    } catch (Throwable) {
        // לוג שנכשל לא מפיל את הבקשה
    }
}

/** שגיאת JavaScript בדפדפן — נשלחת מהדף, נרשמת ללוג. */
function client_error(array $session, array $b): array
{
    static $n = 0;
    if (++$n > 1) return ['ok' => true];
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
    if (!empty($q['act'])) { $where[] = 'l.action LIKE ?'; $args[] = '%' . $q['act'] . '%'; }   // לא action — זה שם הפעולה של ה-API
    // בלי הבקשות של אזור הפיתוח עצמו (הלוג מתרענן — הוא היה ממלא את הרשימה), חוץ משגיאות
    if (!empty($q['nodev'])) $where[] = "(l.action NOT LIKE 'dev\_%' ESCAPE '\\' OR l.status >= 400)";
    if (!empty($q['user_id'])) { $where[] = 'l.user_id = ?'; $args[] = (int)$q['user_id']; }
    if (!empty($q['ref'])) { $where[] = 'l.ref LIKE ?'; $args[] = '%' . $q['ref'] . '%'; }
    if (!empty($q['before'])) { $where[] = 'l.id < ?'; $args[] = (int)$q['before']; }
    $st = db()->prepare('SELECT l.*, u.display_name, u.email FROM dev_log l LEFT JOIN users u ON u.id = l.user_id
        WHERE ' . implode(' AND ', $where) . ' ORDER BY l.id DESC LIMIT 200');
    $st->execute($args);
    $rows = $st->fetchAll();
    $day = gmdate('Y-m-d\TH:i:s\Z', time() - 86400);
    $stats = db()->query("SELECT COUNT(*) AS n, SUM(status >= 500) AS e5, SUM(status >= 400 AND status < 500) AS e4,
        SUM(action = 'client_error') AS js, ROUND(AVG(duration_ms)) AS avg_ms FROM dev_log WHERE at > '$day'")->fetch();
    return ['ok' => true, 'rows' => array_map(fn($r) => [
        'id' => (int)$r['id'], 'at' => $r['at'], 'action' => $r['action'], 'method' => $r['method'], 'status' => $r['status'] === null ? null : (int)$r['status'],
        'ms' => (int)$r['duration_ms'], 'actor' => $r['actor'], 'user' => $r['display_name'], 'email' => $r['email'], 'user_id' => $r['user_id'],
        'ref' => $r['ref'], 'ip' => $r['ip'], 'message' => $r['message'], 'trace' => $r['trace'], 'page' => $r['page'],
    ], $rows), 'day' => array_map('intval', $stats)];
}

// ── רשימת "פתוח / סגור" ──────────────────────────────────────

// הרשימה, הקודים והשיח — lib/devtasks.php

// ── מצב המערכת ───────────────────────────────────────────────

function dev_health(): array
{
    $count = fn(string $sql) => (int)db()->query($sql)->fetchColumn();
    $day = gmdate('Y-m-d\TH:i:s\Z', time() - 86400);
    $dbFile = DT_DB_FILE;
    return ['ok' => true, 'health' => [
        'php' => PHP_VERSION, 'sqlite' => db()->query('SELECT sqlite_version()')->fetchColumn(),
        'db_mb' => is_file($dbFile) ? round(filesize($dbFile) / 1048576, 2) : 0,
        'files_mb' => round(array_sum(array_map('filesize', glob(DT_DATA_DIR . '/files/*') ?: [])) / 1048576, 2),
        'disk_free_mb' => ($f = @disk_free_space(DT_DATA_DIR)) ? (int)($f / 1048576) : null,
        'data_writable' => is_writable(DT_DATA_DIR), 'data_protected' => is_file(DT_DATA_DIR . '/.htaccess'),
        'crypto' => unseal(seal('בדיקה')) === 'בדיקה', 'curl' => function_exists('curl_init'), 'zip' => class_exists('ZipArchive'),
        'mail' => function_exists('mail'), 'https' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'counts' => [
            'users' => $count("SELECT COUNT(*) FROM users WHERE status = 'active'"),
            'templates' => $count('SELECT COUNT(*) FROM templates WHERE deleted_at IS NULL'),
            'documents' => $count('SELECT COUNT(*) FROM documents WHERE deleted_at IS NULL'),
            'completed' => $count("SELECT COUNT(*) FROM documents WHERE status = 'completed' AND deleted_at IS NULL"),
            'api_keys' => $count('SELECT COUNT(*) FROM api_keys WHERE revoked_at IS NULL'),
            'sources' => $count('SELECT COUNT(*) FROM data_sources WHERE archived_at IS NULL'),
        ],
        'day' => [
            'requests' => $count("SELECT COUNT(*) FROM dev_log WHERE at > '$day'"),
            'errors' => $count("SELECT COUNT(*) FROM dev_log WHERE at > '$day' AND status >= 500"),
            'js_errors' => $count("SELECT COUNT(*) FROM dev_log WHERE at > '$day' AND action = 'client_error'"),
        ],
        'chain_broken' => array_values(array_filter(
            array_map('intval', db()->query('SELECT id FROM documents WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 300')->fetchAll(PDO::FETCH_COLUMN)),
            fn($id) => !doc_verify_chain($id))),
    ]];
}

// ── אכיפה: מסמכים ותבניות, במטא-דאטה בלבד ─────────────────────

function dev_docs(array $q): array
{
    $where = ['1 = 1'];
    $args = [];
    if (($s = trim((string)($q['q'] ?? ''))) !== '') {
        $where[] = '(d.number LIKE ? OR u.email LIKE ? OR u.display_name LIKE ? OR d.id = ? OR d.external_ref = ?)';
        array_push($args, "%$s%", "%$s%", "%$s%", (int)$s, $s);
    }
    if (!empty($q['status'])) { $where[] = 'd.status = ?'; $args[] = (string)$q['status']; }
    if (empty($q['deleted'])) $where[] = 'd.deleted_at IS NULL';
    $st = db()->prepare("SELECT d.id, d.number, d.status, d.created_at, d.completed_at, d.archived_at, d.deleted_at, d.final_hash,
            d.external_ref, d.api_key_id, v.template_id, v.version, u.display_name AS owner, u.email,
            (SELECT COUNT(*) FROM field_values f WHERE f.document_id = d.id) AS values_n,
            (SELECT COUNT(*) FROM participants p WHERE p.document_id = d.id) AS parts_n,
            (SELECT COUNT(*) FROM attachments a WHERE a.document_id = d.id) AS files_n
        FROM documents d JOIN template_versions v ON v.id = d.template_version_id JOIN users u ON u.id = d.owner_id
        WHERE " . implode(' AND ', $where) . ' ORDER BY d.id DESC LIMIT 100');
    $st->execute($args);
    return ['ok' => true, 'documents' => array_map(fn($d) => [
        'id' => (int)$d['id'], 'number' => $d['number'], 'status' => $d['status'], 'owner' => $d['owner'], 'email' => $d['email'],
        'template_id' => (int)$d['template_id'], 'version' => (int)$d['version'], 'created_at' => $d['created_at'],
        'completed_at' => $d['completed_at'], 'archived' => $d['archived_at'] !== null, 'deleted' => $d['deleted_at'] !== null,
        'locked' => $d['final_hash'] !== null, 'api' => $d['api_key_id'] !== null, 'ref' => $d['external_ref'],
        'values' => (int)$d['values_n'], 'participants' => (int)$d['parts_n'], 'files' => (int)$d['files_n'],
        'chain_ok' => doc_verify_chain((int)$d['id']),
    ], $st->fetchAll())];
}

function dev_tpls(array $q): array
{
    $where = ['1 = 1'];
    $args = [];
    if (($s = trim((string)($q['q'] ?? ''))) !== '') {
        $where[] = '(u.email LIKE ? OR u.display_name LIKE ? OR t.id = ?)';
        array_push($args, "%$s%", "%$s%", (int)$s);
    }
    if (empty($q['deleted'])) $where[] = 't.deleted_at IS NULL';
    $st = db()->prepare("SELECT t.id, t.source, t.current_version, t.used, t.archived_at, t.deleted_at, t.created_at,
            u.display_name AS owner, u.email,
            (SELECT COUNT(*) FROM documents d JOIN template_versions v ON v.id = d.template_version_id
              WHERE v.template_id = t.id AND d.deleted_at IS NULL) AS docs_n
        FROM templates t JOIN users u ON u.id = t.owner_id WHERE " . implode(' AND ', $where) . ' ORDER BY t.id DESC LIMIT 100');
    $st->execute($args);
    return ['ok' => true, 'templates' => array_map(fn($t) => [
        'id' => (int)$t['id'], 'kind' => KINDS[$t['source']] ?? $t['source'], 'version' => (int)$t['current_version'],
        'used' => (bool)$t['used'], 'archived' => $t['archived_at'] !== null, 'deleted' => $t['deleted_at'] !== null,
        'created_at' => $t['created_at'], 'owner' => $t['owner'], 'email' => $t['email'], 'documents' => (int)$t['docs_n'],
    ], $st->fetchAll())];
}

/** הפעולות שהמפתח יכול לכפות — מעבר לכללים של המשתמשים. */
const DEV_OPS = [
    'document' => [
        'delete'  => 'מחיקה (גם מסמך שמולא או הושלם)',
        'purge'   => 'מחיקה לצמיתות: ערכים, קבצים ופרטי הממלאים',
        'unlock'  => 'ביטול נעילה של מסמך שהושלם',
        'revoke'  => 'ביטול כל הקישורים של הממלאים',
        'restore' => 'שחזור (ממחיקה או מארכיון)',
    ],
    'template' => [
        'delete'  => 'מחיקה (גם תבנית שהונפקו ממנה מסמכים)',
        'restore' => 'שחזור (ממחיקה או מארכיון)',
    ],
];

function dev_force(array $dev, array $b): array
{
    $type = (string)($b['type'] ?? '');
    $op = (string)($b['op'] ?? '');
    $id = (int)($b['id'] ?? 0);
    if (!isset(DEV_OPS[$type][$op])) throw new ApiError('פעולה לא מוכרת', 422);
    $reason = mb_substr(trim((string)($b['reason'] ?? '')), 0, 300);
    if (mb_strlen($reason) < 3) throw new ApiError('חובה לכתוב סיבה — היא נשמרת ביומן הביקורת', 422);
    if ((string)($b['confirm'] ?? '') !== (string)$id) throw new ApiError('לאישור — הקלידו את המספר המזהה', 422);

    $pdo = db();
    $now = now_iso();
    $actor = 'dev:' . $dev['id'];
    $detail = [];
    if ($type === 'document') {
        $st = $pdo->prepare('SELECT * FROM documents WHERE id = ?');
        $st->execute([$id]);
        $d = $st->fetch() ?: throw new ApiError('המסמך לא נמצא', 404);
        $detail = ['number' => $d['number'], 'status' => $d['status']];
        $pdo->beginTransaction();
        try {
            switch ($op) {
                case 'delete':
                    if ($d['deleted_at']) throw new ApiError('המסמך כבר מחוק', 409);
                    $pdo->prepare('UPDATE documents SET deleted_at = ? WHERE id = ?')->execute([$now, $id]);
                    break;
                case 'purge':
                    $files = $pdo->prepare('SELECT path FROM attachments WHERE document_id = ?');
                    $files->execute([$id]);
                    $detail['files'] = count($paths = $files->fetchAll(PDO::FETCH_COLUMN));
                    $pdo->prepare('DELETE FROM attachments WHERE document_id = ?')->execute([$id]);
                    $pdo->prepare('DELETE FROM field_values WHERE document_id = ?')->execute([$id]);
                    $pdo->prepare("UPDATE participants SET token_hash = NULL, contact_enc = NULL, otp_hash = NULL WHERE document_id = ?")->execute([$id]);
                    $pdo->prepare('UPDATE documents SET deleted_at = COALESCE(deleted_at, ?), title_enc = NULL WHERE id = ?')->execute([$now, $id]);
                    break;
                case 'unlock':
                    if ($d['status'] !== 'completed') throw new ApiError('המסמך לא נעול', 409);
                    $pdo->prepare("UPDATE documents SET status = 'in_progress', final_hash = NULL, completed_at = NULL WHERE id = ?")->execute([$id]);
                    $detail['hash'] = $d['final_hash'];
                    break;
                case 'revoke':
                    $pdo->prepare('UPDATE participants SET token_hash = NULL WHERE document_id = ?')->execute([$id]);
                    break;
                case 'restore':
                    $pdo->prepare('UPDATE documents SET deleted_at = NULL, archived_at = NULL WHERE id = ?')->execute([$id]);
                    break;
            }
            // ביומן המסמך — השרשרת מראה שמפתח התערב, מתי ולמה
            log_event($id, $actor, 'dev_override', ['op' => $op, 'reason' => $reason]);
            dev_audit($dev, $op, $type, $id, $reason, $detail);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        if ($op === 'purge') foreach ($paths as $p) delete_file($p);
    } else {
        $st = $pdo->prepare('SELECT * FROM templates WHERE id = ?');
        $st->execute([$id]);
        $t = $st->fetch() ?: throw new ApiError('התבנית לא נמצאה', 404);
        $detail = ['used' => (bool)$t['used']];
        if ($op === 'delete') {
            if ($t['deleted_at']) throw new ApiError('התבנית כבר מחוקה', 409);
            // מחיקה רכה: מסמכים שכבר הונפקו ממנה ממשיכים לעבוד (הם על גרסה קבועה)
            $pdo->prepare('UPDATE templates SET deleted_at = ? WHERE id = ?')->execute([$now, $id]);
        } else {
            $pdo->prepare('UPDATE templates SET deleted_at = NULL, archived_at = NULL WHERE id = ?')->execute([$id]);
        }
        dev_audit($dev, $op, $type, $id, $reason, $detail);
    }
    return ['ok' => true, 'message' => DEV_OPS[$type][$op] . ' — בוצע ונרשם ביומן הביקורת'];
}

function dev_audit(array $dev, string $op, string $type, int $id, string $reason, array $detail): void
{
    db()->prepare('INSERT INTO dev_audit (at, dev_id, op, target_type, target_id, reason, detail) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([now_iso(), $dev['id'], $op, $type, $id, $reason, json_encode($detail, JSON_UNESCAPED_UNICODE)]);
}

function dev_audit_list(): array
{
    $rows = db()->query('SELECT a.*, u.display_name FROM dev_audit a JOIN users u ON u.id = a.dev_id ORDER BY a.id DESC LIMIT 200')->fetchAll();
    return ['ok' => true, 'ops' => DEV_OPS, 'audit' => array_map(fn($a) => [
        'at' => $a['at'], 'by' => $a['display_name'], 'op' => $a['op'], 'type' => $a['target_type'], 'id' => (int)$a['target_id'],
        'reason' => $a['reason'], 'detail' => json_decode((string)$a['detail'], true),
    ], $rows)];
}

// ── הגדרות המשתמש ────────────────────────────────────────────

function me_update(array $s, array $b): array
{
    $name = valid_name($b['name'] ?? '');
    db()->prepare('UPDATE users SET display_name = ? WHERE id = ?')->execute([$name, $s['id']]);
    return ['ok' => true, 'user' => public_user(['display_name' => $name] + $s)];
}

/** החלפת סיסמה: דורש את הנוכחית; מנתק את שאר המכשירים. */
function me_password(array $s, array $b): array
{
    $keys = ['pw:' . $s['id']];
    if (($wait = lock_remaining($keys)) > 0) throw new ApiError("יותר מדי ניסיונות. נסו בעוד $wait שניות", 429);
    if (!password_verify((string)($b['current'] ?? ''), (string)$s['password_hash'])) {
        record_failure($keys, 5);
        throw new ApiError('הסיסמה הנוכחית שגויה', 403);
    }
    clear_failures($keys);
    $new = valid_password($b['new'] ?? '');
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $s['id']]);
    $st = db()->prepare('DELETE FROM sessions WHERE user_id = ? AND token_hash != ?');
    $st->execute([$s['id'], $s['token_hash']]);
    return ['ok' => true, 'other_sessions' => $st->rowCount()];
}

function me_sessions(array $s): array
{
    $st = db()->prepare('SELECT COUNT(*) FROM sessions WHERE user_id = ? AND expires_at > ?');
    $st->execute([$s['id'], now_iso()]);
    return ['ok' => true, 'email' => $s['email'], 'name' => $s['display_name'], 'created_at' => $s['created_at'],
        'last_login_at' => $s['last_login_at'], 'devices' => (int)$st->fetchColumn(), 'is_developer' => (bool)$s['is_developer']];
}

function me_logout_others(array $s): array
{
    $st = db()->prepare('DELETE FROM sessions WHERE user_id = ? AND token_hash != ?');
    $st->execute([$s['id'], $s['token_hash']]);
    return ['ok' => true, 'count' => $st->rowCount()];
}

require_once __DIR__ . '/devtasks.php';   // מטלות: קודים, שיח, אסימון לקריאה בידי Claude
