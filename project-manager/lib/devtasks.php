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
const PM_DEV_STATUS = ['open', 'question', 'closed'];
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
        SQL);
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

/** הרשימה המאוחדת, עם השיח של כל מטלה. */
function pm_dev_task_list(): array
{
    pm_devtasks_migrate();
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
            'detail' => $t['detail'], 'opened' => substr($t['created_at'], 0, 10), 'questions' => [], 'source' => 'user'];
    }
    $names = [];
    foreach ($db->query('SELECT id, display_name FROM users')->fetchAll() as $u) $names[(int)$u['id']] = $u['display_name'];
    $byCode = [];
    foreach ($db->query('SELECT * FROM dev_comments ORDER BY id')->fetchAll() as $c) {
        $byCode[$c['code']][] = ['id' => (int)$c['id'], 'at' => $c['at'], 'author' => $c['author'],
            'name' => $c['author'] === 'claude' ? 'Claude' : ($names[(int)$c['user_id']] ?? '—'), 'body' => $c['body']];
    }
    foreach ($items as $code => &$x) {
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
    $code = pm_dev_next_code();
    $now = pm_now();
    pm_db()->prepare('INSERT INTO dev_tasks (code, area, title, detail, status, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$code, mb_substr(trim((string)($b['area'] ?? '')), 0, 40), $title,
            mb_substr(trim((string)($b['detail'] ?? '')), 0, PM_DEV_COMMENT_MAX), 'open', $dev['id'], $now, $now]);
    return ['ok' => true, 'code' => $code];
}

/** מצב משתנה כאן רק במטלה שנפתחה מהמסך; את שבקוד — Claude סוגר בקוד. */
function pm_dev_task_status(string $code, string $status): void
{
    pm_devtasks_migrate();
    if (!in_array($status, PM_DEV_STATUS, true)) throw new PmError('מצב לא מוכר', 422);
    $st = pm_db()->prepare('UPDATE dev_tasks SET status = ?, updated_at = ? WHERE code = ?');
    $st->execute([$status, pm_now(), $code]);
    if (!$st->rowCount()) throw new PmError('את המצב של מטלה מהקוד משנים בקוד — כתבו הערה, ו-Claude יעדכן', 409);
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

/** תשובה של Claude במטלה; במטלה שנפתחה מהמסך — אפשר גם לשנות מצב. */
function pm_devapi_comment(array $k, array $b): array
{
    $code = (string)($b['code'] ?? '');
    $r = pm_dev_comment_add($code, 'claude', null, (string)($b['body'] ?? ''));
    if (!empty($b['status'])) pm_dev_task_status($code, (string)$b['status']);
    return $r;
}
// i18n-none-end
