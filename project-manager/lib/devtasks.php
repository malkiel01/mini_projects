<?php
/**
 * מטלות ושיח — אזור הפיתוח (הגדרות ← 📋 מטלות, רק למפתח). README: "מטלות ושיח".
 * מועתק ממסמכי התבניות (document-templates/lib/devtasks.php), בקוד PM-001.
 *
 * שני מקורות שמתמזגים לרשימה אחת:
 *   dev/open-items.json — מה ש-Claude מנהל בקוד, ומתעדכן עם כל שינוי שנכנס ל-main.
 *   הטבלה dev_tasks     — מטלה שהמפתח פתח מהמסך.
 * אותו קוד בשניהם? הקובץ גובר (Claude העביר את המטלה לקוד).
 *
 * בכל מטלה — שיח: המפתח כותב, Claude עונה. Claude קורא בלי חיבור לחשבון: "העתקה לצ'אט",
 * או אסימון אישי (X-Dev-Token) — api.php?action=devapi-feed / devapi-comment. האסימון נשמר רק
 * כגיבוב, מוצג פעם אחת, ופותח רק את המטלות.
 */

declare(strict_types=1);

// i18n-none-start — אזור הפיתוח: למפתח ול-Claude בלבד, בעברית

const PM_DEV_ITEMS_FILE = __DIR__ . '/../dev/open-items.json';
const PM_DEV_PREFIX = 'PM-';
const PM_DEV_TOKEN_PREFIX = 'pmd_';
const PM_DEV_COMMENT_MAX = 4000;
// triage לבירור (שגיאה שנפתחה לבד) · open פתוח · in_progress בעבודה · verify ממתין לאימות שלך · question שאלה אליך · closed סגור
const PM_DEV_STATUS = ['triage', 'open', 'in_progress', 'verify', 'question', 'closed'];
const PM_DEV_KINDS = ['bug', 'feature', 'question', 'research', 'task'];
const PM_DEV_PRIORITY = ['high', 'normal', 'low'];
const PM_DEV_FILE_MAX = 8 * 1048576;
const PM_DEV_EVIDENCE = ['pr', 'commit', 'deploy', 'version', 'test', 'note'];
const PM_DEV_RATE = 300; // בקשות בשעה לאסימון

function pm_devtasks_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    pm_db()->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS dev_tasks (
            id          INTEGER PRIMARY KEY,
            code        TEXT NOT NULL UNIQUE,
            area        TEXT NOT NULL DEFAULT '',
            title       TEXT NOT NULL,
            detail      TEXT NOT NULL DEFAULT '',
            status      TEXT NOT NULL DEFAULT 'open',
            created_by  INTEGER REFERENCES users(id),
            created_at  TEXT NOT NULL,
            updated_at  TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS dev_comments (
            id          INTEGER PRIMARY KEY,
            code        TEXT NOT NULL,
            at          TEXT NOT NULL,
            author      TEXT NOT NULL,          -- user | claude
            user_id     INTEGER REFERENCES users(id),
            body        TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS dev_comments_code ON dev_comments (code);
        CREATE TABLE IF NOT EXISTS dev_tokens (
            id           INTEGER PRIMARY KEY,
            token_hash   TEXT NOT NULL UNIQUE,
            label        TEXT NOT NULL,
            created_by   INTEGER NOT NULL REFERENCES users(id),
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
        SQL);
    // שדות במטלה שנפתחה מהמסך: סוג, עדיפות, איך יודעים שזה עובד, צעדי בדיקה, ההקשר של דיווח 🐞
    $db = pm_db();
    foreach (['kind' => "TEXT NOT NULL DEFAULT 'task'", 'priority' => "TEXT NOT NULL DEFAULT 'normal'",
              'acceptance' => "TEXT NOT NULL DEFAULT ''", 'steps' => "TEXT NOT NULL DEFAULT '[]'", 'context' => "TEXT NOT NULL DEFAULT ''"] as $col => $def) {
        pm_add_column($db, 'dev_tasks', $col, $def);
    }
}

function pm_dev_files_dir(): string
{
    $d = __DIR__ . '/../data/files/_dev';
    if (!is_dir($d)) mkdir($d, 0755, true);
    return $d;
}

// ── הרשימה ───────────────────────────────────────────────────

function pm_dev_items_file(): array
{
    return is_file(PM_DEV_ITEMS_FILE) ? (json_decode((string)file_get_contents(PM_DEV_ITEMS_FILE), true) ?: []) : [];
}

function pm_dev_code_num(string $code): int
{
    return preg_match('/^PM-(\d+)$/', $code, $m) ? (int)$m[1] : 0;
}

/**
 * DevLoop 2: שגיאות חדשות ביומן (level=error — שרת ודפדפן) → מטלות "לבירור", אחת לכל חתימה (קטגוריה, אירוע,
 * פעולה והודעה בלי מספרים), עם מונה. שגיאה שחוזרת אחרי שהמטלה נסגרה — המטלה נפתחת שוב "לבירור".
 * רץ כשהמפתח פותח את המטלות וכש-Claude קורא (devapi-feed). עד 20 מטלות חדשות בריצה — לא מציפים.
 */
function pm_dev_errors_sync(): int
{
    pm_devtasks_migrate();
    $log = function_exists('pm_log_db') ? pm_log_db() : null;
    if (!$log) return 0;
    $db = pm_db();
    $upto = (int)($db->query("SELECT value FROM dev_meta WHERE key = 'err_upto'")->fetchColumn() ?: 0);
    if (!$upto) {
        // פעם ראשונה: מתחילים מעכשיו — לא פותחים מטלות על כל ההיסטוריה
        $upto = (int)$log->query('SELECT COALESCE(MAX(id), 0) FROM log')->fetchColumn();
        $db->prepare("INSERT OR REPLACE INTO dev_meta (key, value) VALUES ('err_upto', ?)")->execute([(string)$upto]);
        return 0;
    }
    $st = $log->prepare("SELECT * FROM log WHERE id > ? AND level = 'error' ORDER BY id LIMIT 500");
    $st->execute([$upto]);
    $rows = $st->fetchAll();
    $max = $upto;
    $made = 0;
    foreach ($rows as $r) {
        $max = max($max, (int)$r['id']);
        $norm = preg_replace(['/\b[0-9a-f]{8,}\b/i', '/\d+/', '/"[^"]*"/u', "/'[^']*'/u"], ['#', '#', '"…"', "'…'"], (string)$r['message']);
        $sig = substr(hash('sha1', $r['category'] . '|' . $r['event'] . '|' . $r['action'] . '|' . mb_substr($norm, 0, 200)), 0, 16);
        $g = $db->prepare('SELECT * FROM dev_err_groups WHERE sig = ?');
        $g->execute([$sig]);
        $grp = $g->fetch();
        if ($grp) {
            $db->prepare('UPDATE dev_err_groups SET count = count + 1, last_at = ?, last_req = ? WHERE sig = ?')->execute([$r['at'], $r['req'], $sig]);
            $t = $db->prepare('SELECT status FROM dev_tasks WHERE code = ?');
            $t->execute([$grp['code']]);
            if (!$grp['ignored'] && $t->fetchColumn() === 'closed') {
                $db->prepare("UPDATE dev_tasks SET status = 'triage', updated_at = ? WHERE code = ?")->execute([pm_now(), $grp['code']]);
                pm_dev_comment_add($grp['code'], 'claude', null, "⚠️ השגיאה חזרה אחרי שהמטלה נסגרה ({$r['at']}, בקשה {$r['req']}) — חזרה לבירור.");
            }
            continue;
        }
        if ($made >= 20) continue;
        $data = json_decode((string)$r['data_json'], true) ?: [];
        $where = $r['category'] === 'client' ? ($data['page'] ?? '') : trim($r['method'] . ' ' . $r['action']);
        $title = mb_substr('⚠️ ' . ($r['category'] === 'client' ? 'דפדפן' : 'שרת') . ': ' . ($r['message'] ?: $r['event']) . ($where !== '' ? " ($where)" : ''), 0, 200);
        $code = pm_dev_next_code();
        $now = pm_now();
        $ctx = json_encode(['url' => $data['page'] ?? '', 'ua' => $r['ua'], 'requests' => [['action' => $r['action'], 'method' => $r['method'],
            'status' => (int)$r['status'], 'id' => $r['req'], 'ms' => (int)$r['ms'], 'at' => $r['at']]],
            'errors' => [['message' => $r['message'], 'source' => $data['source'] ?? ($data['file'] ?? ''), 'line' => $data['line'] ?? '']]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $detail = "נפתח לבד מהיומן ({$r['category']} · {$r['event']}). בקשה {$r['req']} ב-{$r['at']}."
            . (!empty($data['trace']) ? "\n\n" . implode("\n", array_slice((array)$data['trace'], 0, 8)) : '')
            . (!empty($data['stack']) ? "\n\n" . mb_substr((string)$data['stack'], 0, 1200) : '');
        $db->prepare("INSERT INTO dev_tasks (code, area, title, detail, status, created_by, created_at, updated_at, kind, priority, acceptance, steps, context)
                      VALUES (?, 'שגיאה אוטומטית', ?, ?, 'triage', NULL, ?, ?, 'bug', 'normal', '', '[]', ?)")
           ->execute([$code, $title, mb_substr($detail, 0, PM_DEV_COMMENT_MAX), $now, $now, mb_substr($ctx, 0, 20000)]);
        $db->prepare('INSERT INTO dev_err_groups (sig, code, count, first_at, last_at, last_req) VALUES (?,?,1,?,?,?)')->execute([$sig, $code, $r['at'], $r['at'], $r['req']]);
        $made++;
    }
    if ($max > $upto) $db->prepare("INSERT OR REPLACE INTO dev_meta (key, value) VALUES ('err_upto', ?)")->execute([(string)$max]);
    return $made;
}

/** הרשימה המאוחדת, עם השיח של כל מטלה. */
function pm_dev_task_list(): array
{
    pm_devtasks_migrate();
    static $synced = false;
    if (!$synced) { $synced = true; try { pm_dev_errors_sync(); } catch (Throwable $e) { pm_log('warn', 'data', 'devtasks.errors_sync', ['message' => $e->getMessage()]); } }
    $db = pm_db();
    $file = pm_dev_items_file();
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
        $fileAt = min((string)($items[$o['code']]['status_at'] ?? ''), pm_now());
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
    foreach ($db->query('SELECT id, display_name FROM users')->fetchAll() as $u) $names[(int)$u['id']] = $u['display_name'];
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
    uasort($items, fn($a, $b) => pm_dev_code_num($b['code']) <=> pm_dev_code_num($a['code']));
    return ['updated' => $file['updated'] ?? null, 'note' => $file['note'] ?? null, 'items' => array_values($items)];
}

function pm_dev_task_get(string $code): array
{
    foreach (pm_dev_task_list()['items'] as $x) if ($x['code'] === $code) return $x;
    throw new PmError('אין מטלה ' . $code, 404);
}

/** הקוד הבא — אחרי הגבוה שבקובץ ובטבלה. */
function pm_dev_next_code(): string
{
    pm_devtasks_migrate();
    $max = 0;
    foreach (pm_dev_items_file()['items'] ?? [] as $x) $max = max($max, pm_dev_code_num((string)($x['code'] ?? '')));
    foreach (pm_db()->query('SELECT code FROM dev_tasks')->fetchAll(PDO::FETCH_COLUMN) as $c) $max = max($max, pm_dev_code_num($c));
    return sprintf('%s%03d', PM_DEV_PREFIX, $max + 1);
}

function pm_dev_task_create(array $dev, array $b): array
{
    $title = mb_substr(trim((string)($b['title'] ?? '')), 0, 200);
    if ($title === '') throw new PmError('חסרה כותרת', 422);
    $kind = in_array($b['kind'] ?? '', PM_DEV_KINDS, true) ? $b['kind'] : 'task';
    $prio = in_array($b['priority'] ?? '', PM_DEV_PRIORITY, true) ? $b['priority'] : 'normal';
    $steps = array_values(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 300), is_array($b['steps'] ?? null) ? $b['steps'] : []), 'strlen'));
    $code = pm_dev_next_code();
    $now = pm_now();
    pm_db()->prepare('INSERT INTO dev_tasks (code, area, title, detail, status, created_by, created_at, updated_at, kind, priority, acceptance, steps) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$code, mb_substr(trim((string)($b['area'] ?? '')), 0, 40), $title,
            mb_substr(trim((string)($b['detail'] ?? '')), 0, PM_DEV_COMMENT_MAX), 'open', $dev['id'], $now, $now,
            $kind, $prio, mb_substr(trim((string)($b['acceptance'] ?? '')), 0, 2000), json_encode(array_slice($steps, 0, 20), JSON_UNESCAPED_UNICODE)]);
    return ['ok' => true, 'code' => $code];
}

/**
 * מצב: מטלה מהמסך — בטבלה; מטלה מהקוד — שכבה מעל הקובץ (dev_state), שגוברת עד ש-Claude מעדכן את הקובץ
 * (status_at חדש יותר). $by — user:<id> / claude / github.
 */
function pm_dev_task_status(string $code, string $status, string $by = 'user'): void
{
    pm_devtasks_migrate();
    if (!in_array($status, PM_DEV_STATUS, true)) throw new PmError('מצב לא מוכר', 422);
    $x = pm_dev_task_get($code);
    if ($x['source'] === 'user') {
        pm_db()->prepare('UPDATE dev_tasks SET status = ?, updated_at = ? WHERE code = ?')->execute([$status, pm_now(), $code]);
    } else {
        pm_db()->prepare('INSERT INTO dev_state (code, status, at, by) VALUES (?,?,?,?) ON CONFLICT(code) DO UPDATE SET status = excluded.status, at = excluded.at, by = excluded.by')
            ->execute([$code, $status, pm_now(), $by]);
    }
}

/**
 * שינוי מצב מהמסך. במטלת שגיאה "לבירור": 👍 לטפל (open) — עוברת ל-Claude; 🙈 להתעלם (ignore) — נסגרת, וגם
 * כשהשגיאה חוזרת היא רק נספרת ולא נפתחת שוב. החזרה למצב אחר מבטלת את ההתעלמות.
 */
function pm_dev_task_set(array $dev, array $b): array
{
    $code = (string)($b['code'] ?? '');
    $ignore = !empty($b['ignore']);
    pm_dev_task_status($code, $ignore ? 'closed' : (string)($b['status'] ?? ''), 'user:' . $dev['id']);
    pm_db()->prepare('UPDATE dev_err_groups SET ignored = ? WHERE code = ?')->execute([$ignore ? 1 : 0, $code]);
    if ($ignore) pm_dev_comment_add($code, 'claude', null, '🙈 סומנה "להתעלם" — מופעים חדשים רק נספרים.');
    elseif (($b['from'] ?? '') === 'triage') pm_dev_comment_add($code, 'user', (int)$dev['id'], '👍 לטפל');
    return ['ok' => true];
}

/**
 * האימות שלך: ✅ עובד אצלי — נסגר; ↩️ לא עובד — חוזר לעבודה, וההערה (חובה) נכנסת לשיח וממתינה ל-Claude.
 */
function pm_dev_verify(array $dev, array $b): array
{
    $code = (string)($b['code'] ?? '');
    $ok = !empty($b['ok']);
    $note = trim((string)($b['note'] ?? ''));
    if (!$ok && $note === '') throw new PmError('מה לא עובד? כמה מילים — Claude יתקן לפיהן', 422);
    pm_dev_task_status($code, $ok ? 'closed' : 'in_progress', 'user:' . $dev['id']);
    $r = pm_dev_comment_add($code, 'user', (int)$dev['id'], ($ok ? '✅ אומת — עובד אצלי' : '↩️ לא עובד') . ($note !== '' ? ': ' . $note : ''));
    pm_db()->prepare('INSERT INTO dev_evidence (code, kind, ref, url, note, at, by) VALUES (?,?,?,?,?,?,?)')
        ->execute([$code, 'note', $ok ? 'verified' : 'rejected', '', $note, pm_now(), 'user:' . $dev['id']]);
    return $r;
}

function pm_dev_evidence_add(string $code, string $kind, string $ref, string $url, string $note, string $by): array
{
    pm_dev_task_get($code);
    if (!in_array($kind, PM_DEV_EVIDENCE, true)) throw new PmError('סוג ראיה לא מוכר', 422);
    if ($url !== '' && !preg_match('#^https?://#i', $url)) throw new PmError('קישור — רק http/https', 422);
    pm_db()->prepare('INSERT INTO dev_evidence (code, kind, ref, url, note, at, by) VALUES (?,?,?,?,?,?,?)')
        ->execute([$code, $kind, mb_substr($ref, 0, 100), mb_substr($url, 0, 500), mb_substr($note, 0, 1000), pm_now(), $by]);
    return ['ok' => true, 'id' => (int)pm_db()->lastInsertId()];
}

/** קובץ לשיח (צילום מסך, PDF) — הגוף הוא הקובץ; ?code=…&name=…&comment_id=… */
function pm_dev_attach(string $by, array $q): array
{
    $code = (string)($q['code'] ?? '');
    pm_dev_task_get($code);
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len <= 0) throw new PmError('לא נבחר קובץ', 422);
    if ($len > PM_DEV_FILE_MAX) throw new PmError('הקובץ גדול מדי — עד 8MB', 413);
    $tmp = pm_dev_files_dir() . '/.up_' . bin2hex(random_bytes(6));
    $in = fopen('php://input', 'rb');
    $out = fopen($tmp, 'wb');
    $n = stream_copy_to_stream($in, $out, PM_DEV_FILE_MAX + 1);
    fclose($in); fclose($out);
    $mime = pm_detect_mime($tmp, '');
    if ($n <= 0 || $n > PM_DEV_FILE_MAX || !preg_match('#^(image/(jpeg|png|webp|gif)|application/pdf|text/plain)$#', $mime)) {
        @unlink($tmp);
        throw new PmError('צילום, PDF או טקסט — עד 8MB', 415);
    }
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'application/pdf' => 'pdf', 'text/plain' => 'txt'][$mime];
    $storage = bin2hex(random_bytes(12)) . '.' . $ext;
    rename($tmp, pm_dev_files_dir() . '/' . $storage);
    $name = mb_substr(trim((string)($q['name'] ?? '')) ?: 'file.' . $ext, 0, 120);
    pm_db()->prepare('INSERT INTO dev_files (code, comment_id, name, mime, size, storage, at, by) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$code, (int)($q['comment_id'] ?? 0) ?: null, $name, $mime, $n, $storage, pm_now(), $by]);
    return ['ok' => true, 'id' => (int)pm_db()->lastInsertId()];
}

/** הקובץ עצמו — למפתח (בעוגייה) ול-Claude (באסימון). */
function pm_dev_file_out(int $id): never
{
    pm_devtasks_migrate();
    $st = pm_db()->prepare('SELECT * FROM dev_files WHERE id = ?');
    $st->execute([$id]);
    $f = $st->fetch() ?: throw new PmError('הקובץ לא נמצא', 404);
    $path = pm_dev_files_dir() . '/' . basename($f['storage']);
    if (!is_file($path)) throw new PmError('הקובץ לא נמצא', 404);
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
function pm_dev_report(array $dev, array $b): array
{
    $ctx = is_array($b['context'] ?? null) ? $b['context'] : [];
    $keep = array_intersect_key($ctx, array_flip(['url', 'screen', 'ua', 'version', 'lang', 'requests', 'errors', 'at']));
    $json = mb_substr(json_encode($keep, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 20000);
    $r = pm_dev_task_create($dev, $b + ['kind' => 'bug']);
    pm_db()->prepare('UPDATE dev_tasks SET context = ? WHERE code = ?')->execute([$json, $r['code']]);
    return $r;
}

function pm_dev_comment_add(string $code, string $author, ?int $userId, string $body): array
{
    pm_dev_task_get($code);
    $body = trim($body);
    if ($body === '') throw new PmError('ההערה ריקה', 422);
    if (mb_strlen($body) > PM_DEV_COMMENT_MAX) throw new PmError('הערה עד {n} תווים', 422, ['n' => PM_DEV_COMMENT_MAX]);
    pm_db()->prepare('INSERT INTO dev_comments (code, at, author, user_id, body) VALUES (?,?,?,?,?)')
        ->execute([$code, pm_now(), $author, $userId, $body]);
    return ['ok' => true, 'id' => (int)pm_db()->lastInsertId()];
}

/** מחיקה — רק הערה שלי, ורק כל עוד לא נענתה. */
function pm_dev_comment_delete(array $dev, array $b): array
{
    pm_devtasks_migrate();
    $st = pm_db()->prepare("SELECT * FROM dev_comments WHERE id = ? AND author = 'user' AND user_id = ?");
    $st->execute([(int)($b['id'] ?? 0), $dev['id']]);
    $c = $st->fetch() ?: throw new PmError('ההערה לא נמצאה', 404);
    $later = pm_db()->prepare("SELECT COUNT(*) FROM dev_comments WHERE code = ? AND id > ? AND author = 'claude'");
    $later->execute([$c['code'], $c['id']]);
    if ((int)$later->fetchColumn()) throw new PmError('כבר נענתה — אפשר להוסיף הערה חדשה', 409);
    pm_db()->prepare('DELETE FROM dev_comments WHERE id = ?')->execute([$c['id']]);
    return ['ok' => true];
}

// ── אסימונים לקריאה בידי Claude ─────────────────────────────

function pm_dev_token_list(): array
{
    pm_devtasks_migrate();
    $rows = pm_db()->query('SELECT t.id, t.label, t.created_at, t.last_used_at, t.revoked_at, u.display_name
        FROM dev_tokens t JOIN users u ON u.id = t.created_by ORDER BY t.id DESC')->fetchAll();
    return ['tokens' => array_map(fn($t) => ['id' => (int)$t['id'], 'label' => $t['label'], 'by' => $t['display_name'],
        'created_at' => $t['created_at'], 'last_used_at' => $t['last_used_at'], 'revoked' => (bool)$t['revoked_at']], $rows)];
}

function pm_dev_token_create(array $dev, array $b): array
{
    pm_devtasks_migrate();
    $label = mb_substr(trim((string)($b['label'] ?? '')), 0, 60) ?: 'Claude';
    $token = PM_DEV_TOKEN_PREFIX . bin2hex(random_bytes(24));
    pm_db()->prepare('INSERT INTO dev_tokens (token_hash, label, created_by, created_at) VALUES (?,?,?,?)')
        ->execute([hash('sha256', $token), $label, $dev['id'], pm_now()]);
    pm_audit("user:{$dev['id']}", 'devtasks.token_create', 'dev_token', (int)pm_db()->lastInsertId(), ['label' => $label]);
    return ['ok' => true, 'token' => $token];
}

function pm_dev_token_revoke(array $dev, array $b): array
{
    pm_devtasks_migrate();
    $st = pm_db()->prepare('UPDATE dev_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL');
    $st->execute([pm_now(), (int)($b['id'] ?? 0)]);
    if (!$st->rowCount()) throw new PmError('האסימון לא נמצא', 404);
    pm_audit("user:{$dev['id']}", 'devtasks.token_revoke', 'dev_token', (int)($b['id'] ?? 0));
    return ['ok' => true];
}

/** האסימון שבכותרת X-Dev-Token — של מפתח פעיל, ולא בוטל; עד PM_DEV_RATE בקשות בשעה. */
function pm_devapi_auth(): array
{
    pm_devtasks_migrate();
    $t = trim((string)($_SERVER['HTTP_X_DEV_TOKEN'] ?? ''));
    if (!preg_match('/^' . PM_DEV_TOKEN_PREFIX . '[0-9a-f]{48}$/', $t)) throw new PmError('חסר אסימון (X-Dev-Token)', 401);
    $st = pm_db()->prepare('SELECT t.*, u.status AS user_status, u.role AS user_role FROM dev_tokens t JOIN users u ON u.id = t.created_by WHERE t.token_hash = ?');
    $st->execute([hash('sha256', $t)]);
    $k = $st->fetch();
    if (!$k || $k['revoked_at'] || $k['user_status'] !== 'active' || $k['user_role'] !== 'developer') {
        pm_log('warn', 'auth', 'devapi.denied', ['message' => 'אסימון מטלות לא תקף']);
        throw new PmError('האסימון אינו תקף', 401);
    }
    $hour = gmdate('YmdH');
    $n = $k['rate_hour'] === $hour ? (int)$k['rate_n'] + 1 : 1;
    if ($n > PM_DEV_RATE) throw new PmError('יותר מדי בקשות — נסו בעוד שעה', 429);
    pm_db()->prepare('UPDATE dev_tokens SET last_used_at = ?, rate_hour = ?, rate_n = ? WHERE id = ?')->execute([pm_now(), $hour, $n, $k['id']]);
    pm_log_user(['id' => null, 'username' => '🤖 devapi:' . $k['id']], true);
    return $k;
}

/**
 * מה ש-Claude קורא. ?code=PM-012 — מטלה אחת. ?all=1 — כל המטלות.
 * ברירת המחדל: רק מטלות שיש בהן הערה חדשה מאז הסימון האחרון (mark=1 מסמן).
 */
function pm_devapi_feed(array $k, array $q): array
{
    $list = pm_dev_task_list();
    $upto = (int)$k['read_upto'];
    $items = $list['items'];
    if (!empty($q['code'])) $items = array_values(array_filter($items, fn($x) => $x['code'] === $q['code']));
    elseif (empty($q['all'])) $items = array_values(array_filter($items,
        fn($x) => array_filter($x['comments'], fn($c) => $c['author'] === 'user' && $c['id'] > $upto)));
    foreach ($items as &$x) foreach ($x['comments'] as &$c) $c['new'] = $c['author'] === 'user' && $c['id'] > $upto;
    unset($x, $c);
    $max = (int)pm_db()->query('SELECT COALESCE(MAX(id), 0) FROM dev_comments')->fetchColumn();
    if (!empty($q['mark'])) pm_db()->prepare('UPDATE dev_tokens SET read_upto = ? WHERE id = ?')->execute([$max, $k['id']]);
    return ['ok' => true, 'updated' => $list['updated'], 'read_upto' => !empty($q['mark']) ? $max : $upto,
        'next_code' => pm_dev_next_code(), 'items' => $items];
}

/**
 * תשובה של Claude במטלה (body), ואפשר באותה בקשה: status (גם במטלה מהקוד — שכבה מעל הקובץ),
 * evidence [{kind, ref, url, note}], ו-steps (צעדי בדיקה — רק במטלה מהמסך). אחרי פריסה: status=verify.
 */
function pm_devapi_comment(array $k, array $b): array
{
    $code = (string)($b['code'] ?? '');
    $r = trim((string)($b['body'] ?? '')) !== '' ? pm_dev_comment_add($code, 'claude', null, (string)$b['body']) : ['ok' => true];
    foreach (is_array($b['evidence'] ?? null) ? $b['evidence'] : [] as $e) {
        pm_dev_evidence_add($code, (string)($e['kind'] ?? 'note'), (string)($e['ref'] ?? ''), (string)($e['url'] ?? ''), (string)($e['note'] ?? ''), 'claude');
    }
    if (is_array($b['steps'] ?? null)) {
        $steps = array_values(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 300), $b['steps']), 'strlen'));
        pm_db()->prepare('UPDATE dev_tasks SET steps = ? WHERE code = ?')->execute([json_encode(array_slice($steps, 0, 20), JSON_UNESCAPED_UNICODE), $code]);
    }
    if (!empty($b['status'])) {
        // פריסה (GitHub) לא מחזירה מטלה שכבר אומתה ונסגרה — רק if_open=0 מכריח
        $skip = ($b['if_open'] ?? 1) && pm_dev_task_get($code)['status'] === 'closed';
        if (!$skip) pm_dev_task_status($code, (string)$b['status'], (string)($b['by'] ?? '') === 'github' ? 'github' : 'claude');
        $r['status_skipped'] = $skip;
    }
    return $r;
}
// i18n-none-end
