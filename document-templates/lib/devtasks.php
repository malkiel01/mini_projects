<?php
/**
 * מטלות: לכל מטלה קוד (DT-001), ושיח — הערות של המפתח ותשובות של Claude.
 *
 * שני מקורות שמתמזגים לרשימה אחת:
 *   dev/open-items.json — מה ש-Claude מנהל בקוד, עם כל שינוי שנכנס.
 *   הטבלה dev_tasks     — מטלה שהמפתח פתח מהמסך.
 * אותו קוד בשניהם? הקובץ גובר (Claude העביר את המטלה לקוד).
 *
 * Claude קורא את השיח בלי חיבור ישיר לחשבון: או שהמפתח מעתיק אותו לצ'אט
 * (כפתור "העתקה לצ'אט"), או באסימון אישי — devapi_feed / devapi_comment,
 * בכותרת X-Dev-Token. האסימון נשמר רק כגיבוב, ומוצג פעם אחת ביצירה.
 */

declare(strict_types=1);

const DEV_TOKEN_PREFIX = 'dtd_';
const DEV_COMMENT_MAX = 4000;
const DEV_TASK_STATUS = ['open', 'question', 'closed'];

function devtasks_migrate(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
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
        CREATE INDEX IF NOT EXISTS idx_dev_comments_code ON dev_comments(code);
        CREATE TABLE IF NOT EXISTS dev_tokens (
            id           INTEGER PRIMARY KEY,
            token_hash   TEXT NOT NULL UNIQUE,
            label        TEXT NOT NULL,
            created_by   INTEGER NOT NULL REFERENCES users(id),
            created_at   TEXT NOT NULL,
            last_used_at TEXT,
            revoked_at   TEXT,
            read_upto    INTEGER NOT NULL DEFAULT 0   -- ההערה האחרונה ש-Claude סימן כנקראה
        );
        SQL);
}

// ── הרשימה ───────────────────────────────────────────────────

function dev_items_file(): array
{
    return is_file(DEV_ITEMS_FILE) ? (json_decode((string)file_get_contents(DEV_ITEMS_FILE), true) ?: []) : [];
}

function dev_code_num(string $code): int
{
    return preg_match('/^DT-(\d+)$/', $code, $m) ? (int)$m[1] : 0;
}

/** הרשימה המאוחדת, עם השיח של כל מטלה. */
function dev_task_list(): array
{
    $file = dev_items_file();
    $items = [];
    foreach ($file['items'] ?? [] as $x) {
        if (empty($x['code'])) continue;
        $items[$x['code']] = $x + ['source' => 'code'];
    }
    foreach (db()->query('SELECT * FROM dev_tasks ORDER BY id DESC')->fetchAll() as $t) {
        if (isset($items[$t['code']])) continue;
        $items[$t['code']] = ['code' => $t['code'], 'area' => $t['area'], 'status' => $t['status'], 'title' => $t['title'],
            'detail' => $t['detail'], 'opened' => substr($t['created_at'], 0, 10), 'questions' => [], 'source' => 'user'];
    }
    $names = [];
    foreach (db()->query('SELECT id, display_name FROM users')->fetchAll() as $u) $names[(int)$u['id']] = $u['display_name'];
    $byCode = [];
    foreach (db()->query('SELECT * FROM dev_comments ORDER BY id')->fetchAll() as $c) {
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
    // החדשים קודם (לפי הקוד), כמו בקובץ
    uasort($items, fn($a, $b) => dev_code_num($b['code']) <=> dev_code_num($a['code']));
    return ['updated' => $file['updated'] ?? null, 'note' => $file['note'] ?? null, 'items' => array_values($items)];
}

function dev_items(): array
{
    return ['ok' => true] + dev_task_list();
}

function dev_task_get(string $code): array
{
    foreach (dev_task_list()['items'] as $x) if ($x['code'] === $code) return $x;
    throw new ApiError('אין מטלה ' . $code, 404);
}

/** הקוד הבא — אחרי הגבוה שבקובץ ובטבלה. */
function dev_next_code(): string
{
    $max = 0;
    foreach (dev_items_file()['items'] ?? [] as $x) $max = max($max, dev_code_num((string)($x['code'] ?? '')));
    foreach (db()->query('SELECT code FROM dev_tasks')->fetchAll(PDO::FETCH_COLUMN) as $c) $max = max($max, dev_code_num($c));
    return sprintf('DT-%03d', $max + 1);
}

function dev_task_create(array $dev, array $b): array
{
    $title = mb_substr(trim((string)($b['title'] ?? '')), 0, 200);
    if ($title === '') throw new ApiError('חסרה כותרת', 422);
    $code = dev_next_code();
    $now = now_iso();
    db()->prepare('INSERT INTO dev_tasks (code, area, title, detail, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$code, mb_substr(trim((string)($b['area'] ?? '')), 0, 40), $title,
            mb_substr(trim((string)($b['detail'] ?? '')), 0, DEV_COMMENT_MAX), 'open', $dev['id'], $now, $now]);
    return ['ok' => true, 'code' => $code];
}

/** מצב משתנה כאן רק במטלה שנפתחה מהמסך; את שבקוד — Claude סוגר בקוד. */
function dev_task_status(string $code, string $status): void
{
    if (!in_array($status, DEV_TASK_STATUS, true)) throw new ApiError('מצב לא מוכר', 422);
    $st = db()->prepare('UPDATE dev_tasks SET status = ?, updated_at = ? WHERE code = ?');
    $st->execute([$status, now_iso(), $code]);
    if (!$st->rowCount()) throw new ApiError('את המצב של מטלה מהקוד משנים בקוד — כתבו הערה, ו-Claude יעדכן', 409);
}

function dev_task_set(array $dev, array $b): array
{
    dev_task_status((string)($b['code'] ?? ''), (string)($b['status'] ?? ''));
    return ['ok' => true];
}

function dev_comment_add(string $code, string $author, ?int $userId, string $body): array
{
    dev_task_get($code);
    $body = trim($body);
    if ($body === '') throw new ApiError('ההערה ריקה', 422);
    if (mb_strlen($body) > DEV_COMMENT_MAX) throw new ApiError('הערה עד ' . DEV_COMMENT_MAX . ' תווים', 422);
    db()->prepare('INSERT INTO dev_comments (code, at, author, user_id, body) VALUES (?, ?, ?, ?, ?)')
        ->execute([$code, now_iso(), $author, $userId, $body]);
    return ['ok' => true, 'id' => (int)db()->lastInsertId()];
}

function dev_comment(array $dev, array $b): array
{
    return dev_comment_add((string)($b['code'] ?? ''), 'user', (int)$dev['id'], (string)($b['body'] ?? ''));
}

/** מחיקה — רק הערה שלי, ורק כל עוד לא נענתה. */
function dev_comment_delete(array $dev, array $b): array
{
    $st = db()->prepare("SELECT * FROM dev_comments WHERE id = ? AND author = 'user' AND user_id = ?");
    $st->execute([(int)($b['id'] ?? 0), $dev['id']]);
    $c = $st->fetch() ?: throw new ApiError('ההערה לא נמצאה', 404);
    $later = db()->prepare("SELECT COUNT(*) FROM dev_comments WHERE code = ? AND id > ? AND author = 'claude'");
    $later->execute([$c['code'], $c['id']]);
    if ((int)$later->fetchColumn()) throw new ApiError('כבר נענתה — אפשר להוסיף הערה חדשה', 409);
    db()->prepare('DELETE FROM dev_comments WHERE id = ?')->execute([$c['id']]);
    return ['ok' => true];
}

// ── אסימונים לקריאה בידי Claude ─────────────────────────────

function dev_token_list(): array
{
    $rows = db()->query('SELECT t.id, t.label, t.created_at, t.last_used_at, t.revoked_at, u.display_name
        FROM dev_tokens t JOIN users u ON u.id = t.created_by ORDER BY t.id DESC')->fetchAll();
    return ['ok' => true, 'tokens' => array_map(fn($t) => ['id' => (int)$t['id'], 'label' => $t['label'], 'by' => $t['display_name'],
        'created_at' => $t['created_at'], 'last_used_at' => $t['last_used_at'], 'revoked' => (bool)$t['revoked_at']], $rows)];
}

function dev_token_create(array $dev, array $b): array
{
    $label = mb_substr(trim((string)($b['label'] ?? '')), 0, 60) ?: 'Claude';
    $token = DEV_TOKEN_PREFIX . bin2hex(random_bytes(24));
    db()->prepare('INSERT INTO dev_tokens (token_hash, label, created_by, created_at) VALUES (?, ?, ?, ?)')
        ->execute([token_hash($token), $label, $dev['id'], now_iso()]);
    return ['ok' => true, 'id' => (int)db()->lastInsertId(), 'token' => $token];
}

function dev_token_revoke(array $dev, array $b): array
{
    $st = db()->prepare('UPDATE dev_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL');
    $st->execute([now_iso(), (int)($b['id'] ?? 0)]);
    if (!$st->rowCount()) throw new ApiError('האסימון לא נמצא', 404);
    return ['ok' => true];
}

/** האסימון שבכותרת X-Dev-Token — של מפתח פעיל, ולא בוטל. */
function devapi_auth(): array
{
    $t = trim((string)($_SERVER['HTTP_X_DEV_TOKEN'] ?? ''));
    if (!preg_match('/^' . DEV_TOKEN_PREFIX . '[0-9a-f]{48}$/', $t)) throw new ApiError('חסר אסימון (X-Dev-Token)', 401);
    $st = db()->prepare('SELECT t.*, u.status AS user_status, u.is_developer FROM dev_tokens t JOIN users u ON u.id = t.created_by WHERE t.token_hash = ?');
    $st->execute([token_hash($t)]);
    $k = $st->fetch();
    if (!$k || $k['revoked_at'] || $k['user_status'] !== 'active' || !$k['is_developer']) throw new ApiError('האסימון אינו תקף', 401);
    $win = 'devapi:' . $k['id'] . ':' . gmdate('YmdH');
    db()->prepare('INSERT INTO login_attempts (key, failures, locked_until) VALUES (?, 1, NULL) ON CONFLICT(key) DO UPDATE SET failures = failures + 1')->execute([$win]);
    $n = db()->prepare('SELECT failures FROM login_attempts WHERE key = ?');
    $n->execute([$win]);
    if ((int)$n->fetchColumn() > 300) throw new ApiError('יותר מדי בקשות — נסו בעוד שעה', 429);
    db()->prepare('UPDATE dev_tokens SET last_used_at = ? WHERE id = ?')->execute([now_iso(), $k['id']]);
    return $k;
}

/**
 * מה ש-Claude קורא. ?code=DT-012 — מטלה אחת. ?all=1 — כל המטלות.
 * ברירת המחדל: רק מטלות שיש בהן הערה חדשה מאז הסימון האחרון (mark=1 מסמן).
 */
function devapi_feed(array $k, array $q): array
{
    $list = dev_task_list();
    $upto = (int)$k['read_upto'];
    $items = $list['items'];
    if (!empty($q['code'])) $items = array_values(array_filter($items, fn($x) => $x['code'] === $q['code']));
    elseif (empty($q['all'])) $items = array_values(array_filter($items,
        fn($x) => array_filter($x['comments'], fn($c) => $c['author'] === 'user' && $c['id'] > $upto)));
    foreach ($items as &$x) foreach ($x['comments'] as &$c) $c['new'] = $c['author'] === 'user' && $c['id'] > $upto;
    unset($x, $c);
    $max = (int)db()->query('SELECT COALESCE(MAX(id), 0) FROM dev_comments')->fetchColumn();
    if (!empty($q['mark'])) db()->prepare('UPDATE dev_tokens SET read_upto = ? WHERE id = ?')->execute([$max, $k['id']]);
    return ['ok' => true, 'updated' => $list['updated'], 'read_upto' => !empty($q['mark']) ? $max : $upto,
        'next_code' => dev_next_code(), 'items' => $items];
}

/** תשובה של Claude במטלה; במטלה שנפתחה מהמסך — אפשר גם לשנות מצב. */
function devapi_comment(array $k, array $b): array
{
    $code = (string)($b['code'] ?? '');
    $r = dev_comment_add($code, 'claude', null, (string)($b['body'] ?? ''));
    if (!empty($b['status'])) dev_task_status($code, (string)$b['status']);
    return $r;
}
