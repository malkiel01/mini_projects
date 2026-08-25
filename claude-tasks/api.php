<?php
/**
 * API של "מטלות לקלוד".
 *
 * שני קהלים באותו קובץ:
 *   בני אדם — פותחים מטלות, מקטלגים לנושאים, עונים על שאלות של Claude.
 *   עובדים  — סשן Claude Code או cron בשרת: מושכים מטלה, עובדים, כותבים
 *             חזרה, ועוברים לבאה.
 *
 * כל בקשה היא POST עם JSON, פרט ל-GET למי-אני.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function fail(string $message, int $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function ok(array $payload = []) {
    echo json_encode(['success' => true] + $payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function str_field(array $in, string $key, int $max, string $default = ''): string {
    $v = $in[$key] ?? $default;
    if (!is_string($v)) return $default;
    return mb_substr(trim($v), 0, $max);
}

function pick_field(array $in, string $key, array $allowed, string $default): string {
    $v = $in[$key] ?? '';
    return in_array($v, $allowed, true) ? $v : $default;
}

function int_or_null(array $in, string $key): ?int {
    $v = $in[$key] ?? null;
    return ($v === null || $v === '') ? null : (int) $v;
}

$in     = body();
$action = $_GET['action'] ?? (is_string($in['action'] ?? null) ? $in['action'] : '');

// נקרא ללא תנאי כדי שאסימון העובד ייווצר בפנייה הראשונה. אחרת הוא היה
// נוצר רק כשעובד מציג אסימון — ואין דרך להציג אסימון שטרם קיים.
config();

$user   = currentUser();
$worker = workerName();

/*
 * פעולות פתוחות: כניסה, והרשמה כל עוד אין אף משתמש. ההרשמה נסגרת מאליה
 * ברגע שקיים משתמש ראשון, כדי שהמערכת לא תישאר פתוחה להרשמה לכל עובר.
 */
$public = ['login', 'bootstrap-status', 'register-first'];

if (!in_array($action, $public, true) && !$user && !$worker) {
    fail('נדרשת התחברות', 401);
}

/** פעולות שמותרות רק לבני אדם מחוברים. */
function requireUser(?array $user): array {
    if (!$user) fail('הפעולה זמינה למשתמש מחובר בלבד', 403);
    return $user;
}

try {
    switch ($action) {

    /* ── כניסה וחשבונות ────────────────────────────────────────── */

    case 'bootstrap-status':
        ok(['has_users' => userCount() > 0, 'user' => $user]);

    case 'register-first':
        if (userCount() > 0) fail('ההרשמה סגורה — בקשו ממנהל המערכת לפתוח לכם חשבון', 403);
        $created = createUser(
            str_field($in, 'username', 32),
            (string) ($in['password'] ?? ''),
            str_field($in, 'display_name', 60)
        );
        login($in['username'], $in['password']);
        ok(['user' => $created]);

    case 'login':
        $u = login(str_field($in, 'username', 32), (string) ($in['password'] ?? ''));
        if (!$u) fail('שם משתמש או סיסמה שגויים', 401);
        ok(['user' => $u]);

    case 'logout':
        logout();
        ok();

    case 'me':
        ok(['user' => $user, 'worker' => $worker]);

    /**
     * מציג את אסימון העובד למנהל, כדי שיוכל לחבר סשן Claude או cron.
     * למנהל בלבד: מי שמחזיק באסימון יכול למשוך ולסגור מטלות.
     */
    case 'worker-token': {
        $me = requireUser($user);
        if ($me['role'] !== 'admin') fail('רק מנהל רשאי לראות את אסימון העובד', 403);
        ok(['worker_token' => config()['worker_token']]);
    }

    case 'create-user':
        $me = requireUser($user);
        if ($me['role'] !== 'admin') fail('רק מנהל יכול ליצור משתמשים', 403);
        ok(['user' => createUser(
            str_field($in, 'username', 32),
            (string) ($in['password'] ?? ''),
            str_field($in, 'display_name', 60)
        )]);

    /* ── נושאים ────────────────────────────────────────────────── */

    case 'topics':
        $rows = db()->query(
            'SELECT t.*, (SELECT COUNT(*) FROM tasks k
                           WHERE k.topic_id = t.id AND k.status NOT IN ("done","cancelled")) open_count
               FROM topics t WHERE archived = 0 ORDER BY t.name'
        )->fetchAll();
        ok(['topics' => $rows]);

    case 'create-topic':
        $me   = requireUser($user);
        $name = str_field($in, 'name', 80);
        if ($name === '') fail('חסר שם לנושא');
        db()->prepare('INSERT INTO topics (name, description, repo, created_by, created_at) VALUES (?,?,?,?,?)')
            ->execute([$name, str_field($in, 'description', 2000), str_field($in, 'repo', 120), $me['id'], nowTs()]);
        ok(['id' => (int) db()->lastInsertId()]);

    /* ── מטלות ─────────────────────────────────────────────────── */

    case 'tasks': {
        $sql  = 'SELECT k.*, t.name topic_name, u.display_name author_name,
                        (SELECT COUNT(*) FROM notes n WHERE n.task_id = k.id) note_count
                   FROM tasks k
                   LEFT JOIN topics t ON t.id = k.topic_id
                   LEFT JOIN users  u ON u.id = k.created_by
                  WHERE 1=1';
        $args = [];

        if (($tid = int_or_null($in, 'topic_id')) !== null) { $sql .= ' AND k.topic_id = ?'; $args[] = $tid; }

        $status = $in['status'] ?? '';
        if (is_string($status) && in_array($status, STATUSES, true)) {
            $sql .= ' AND k.status = ?'; $args[] = $status;
        } elseif ($status === 'active') {
            $sql .= ' AND k.status NOT IN ("done","cancelled")';
        }

        $search = str_field($in, 'search', 120);
        if ($search !== '') {
            $sql .= ' AND (k.title LIKE ? OR k.body LIKE ?)';
            $args[] = "%$search%"; $args[] = "%$search%";
        }

        $sql .= " ORDER BY CASE k.status WHEN 'blocked' THEN 0 WHEN 'answered' THEN 1
                                         WHEN 'in_progress' THEN 2 WHEN 'open' THEN 3 ELSE 4 END,
                           CASE k.priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END,
                           k.seq, k.id DESC
                  LIMIT 400";

        $st = db()->prepare($sql);
        $st->execute($args);
        ok(['tasks' => $st->fetchAll(), 'counts' => statusCounts(db(), int_or_null($in, 'topic_id'))]);
    }

    case 'task': {
        $id = (int) ($in['id'] ?? 0);
        $task = getTask(db(), $id);
        if (!$task) fail('מטלה לא נמצאה', 404);
        ok(['task' => $task, 'notes' => taskNotes(db(), $id)]);
    }

    case 'create-task': {
        $me    = requireUser($user);
        $title = str_field($in, 'title', 200);
        if ($title === '') fail('חסרה כותרת למטלה');

        db()->prepare(
            'INSERT INTO tasks (topic_id, title, body, kind, priority, repo, branch, seq,
                                created_by, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            int_or_null($in, 'topic_id'),
            $title,
            str_field($in, 'body', 20000),
            pick_field($in, 'kind', KINDS, 'question'),
            pick_field($in, 'priority', PRIORITIES, 'normal'),
            str_field($in, 'repo', 120),
            str_field($in, 'branch', 120),
            (int) ($in['seq'] ?? 0),
            $me['id'], nowTs(), nowTs(),
        ]);
        ok(['id' => (int) db()->lastInsertId()]);
    }

    case 'update-task': {
        requireUser($user);
        $id = (int) ($in['id'] ?? 0);
        if (!getTask(db(), $id)) fail('מטלה לא נמצאה', 404);

        $sets = []; $args = [];
        foreach (['title' => 200, 'body' => 20000, 'repo' => 120, 'branch' => 120] as $f => $max) {
            if (array_key_exists($f, $in)) { $sets[] = "$f = ?"; $args[] = str_field($in, $f, $max); }
        }
        foreach (['kind' => KINDS, 'priority' => PRIORITIES, 'status' => STATUSES] as $f => $allowed) {
            if (array_key_exists($f, $in)) {
                if (!in_array($in[$f], $allowed, true)) fail("ערך לא חוקי בשדה $f");
                $sets[] = "$f = ?"; $args[] = $in[$f];
            }
        }
        if (array_key_exists('topic_id', $in)) { $sets[] = 'topic_id = ?'; $args[] = int_or_null($in, 'topic_id'); }
        if (array_key_exists('seq', $in))      { $sets[] = 'seq = ?';      $args[] = (int) $in['seq']; }

        if (!$sets) fail('אין מה לעדכן');
        $sets[] = 'updated_at = ?'; $args[] = nowTs(); $args[] = $id;

        db()->prepare('UPDATE tasks SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
        ok();
    }

    case 'add-note': {
        $id = (int) ($in['id'] ?? 0);
        if (!getTask(db(), $id)) fail('מטלה לא נמצאה', 404);

        $text = str_field($in, 'body', 20000);
        if ($text === '') fail('הערה ריקה');

        $author = $user ? $user['display_name'] : ($worker ?? 'unknown');
        $kind   = pick_field($in, 'kind', ['comment', 'question', 'answer', 'result'], 'comment');

        // תשובה של אדם היא מה שמחזיר מטלה חסומה לתור.
        if ($user && $kind === 'comment') {
            $task = getTask(db(), $id);
            if ($task['status'] === 'blocked') $kind = 'answer';
        }
        ok(addNote(db(), $id, $author, $kind, $text));
    }

    /* ── עובדים ────────────────────────────────────────────────── */

    case 'claim': {
        if (!$worker) fail('פעולה לעובדים בלבד', 403);
        $task = claimNextTask(db(), $worker, int_or_null($in, 'topic_id'));
        if (!$task) ok(['task' => null, 'message' => 'אין מטלות ממתינות']);
        ok(['task' => $task, 'notes' => taskNotes(db(), (int) $task['id'])]);
    }

    case 'block': {
        if (!$worker) fail('פעולה לעובדים בלבד', 403);
        $id = (int) ($in['id'] ?? 0);
        $q  = str_field($in, 'question', 20000);
        if (!getTask(db(), $id)) fail('מטלה לא נמצאה', 404);
        if ($q === '') fail('חסרה השאלה');

        addNote(db(), $id, $worker, 'question', $q);
        db()->prepare("UPDATE tasks SET status='blocked', claimed_by='', claim_until=0, updated_at=? WHERE id=?")
            ->execute([nowTs(), $id]);
        ok();
    }

    case 'complete': {
        if (!$worker) fail('פעולה לעובדים בלבד', 403);
        $id = (int) ($in['id'] ?? 0);
        if (!getTask(db(), $id)) fail('מטלה לא נמצאה', 404);

        $result = str_field($in, 'result', 20000);
        if ($result !== '') addNote(db(), $id, $worker, 'result', $result);

        db()->prepare("UPDATE tasks SET status='done', claimed_by='', claim_until=0, updated_at=? WHERE id=?")
            ->execute([nowTs(), $id]);
        ok();
    }

    case 'release': {
        if (!$worker) fail('פעולה לעובדים בלבד', 403);
        $id = (int) ($in['id'] ?? 0);
        db()->prepare("UPDATE tasks SET status='open', claimed_by='', claim_until=0, updated_at=?
                        WHERE id=? AND status='in_progress'")->execute([nowTs(), $id]);
        ok();
    }

    /** מאריך את ההחזקה. מטלה ארוכה לא תשוחרר באמצע העבודה. */
    case 'heartbeat': {
        if (!$worker) fail('פעולה לעובדים בלבד', 403);
        $id = (int) ($in['id'] ?? 0);
        db()->prepare("UPDATE tasks SET claim_until=? WHERE id=? AND claimed_by=?")
            ->execute([nowTs() + CLAIM_MINUTES * 60, $id, $worker]);
        ok(['claim_until' => nowTs() + CLAIM_MINUTES * 60]);
    }

    default:
        fail('פעולה לא מוכרת: ' . $action, 404);
    }
} catch (InvalidArgumentException $e) {
    fail($e->getMessage());
} catch (Throwable $e) {
    error_log('claude-tasks: ' . $e->getMessage());
    fail('שגיאת שרת', 500);
}
