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
require_once __DIR__ . '/lib/order.php';
require_once __DIR__ . '/lib/github.php';
require_once __DIR__ . '/lib/projects.php';

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

/**
 * כתובת חיצונית שהמערכת תציג כקישור.
 *
 * מוגבלת ל-http/https בכוונה: הערך הזה נכתב לתוך href, ו-javascript:
 * שם הוא הרצת קוד בדפדפן של מי שלוחץ.
 */
function url_field(array $in, string $key): ?string {
    $v = $in[$key] ?? null;
    if (!is_string($v)) return null;
    $v = trim($v);
    if ($v === '') return '';
    if (!preg_match('~^https?://[^\s<>"\']{3,290}$~', $v)) fail("הערך בשדה $key אינו כתובת http/https תקינה");
    return $v;
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

    case 'topics': {
        $rows = db()->query(
            'SELECT t.*, (SELECT COUNT(*) FROM tasks k
                           WHERE k.topic_id = t.id AND k.status NOT IN ("done","cancelled")) open_count
               FROM topics t WHERE archived = 0 ORDER BY t.name'
        )->fetchAll();
        // מטלות שלא שויכו — הן שמזינות את כפתור "קטלג את מה שנשאר".
        $un = (int) db()->query(
            'SELECT COUNT(*) c FROM tasks WHERE topic_id IS NULL AND status NOT IN ("done","cancelled")'
        )->fetch()['c'];
        ok(['topics' => $rows, 'unassigned' => $un, 'ai' => aiStatus()]);
    }

    case 'create-topic':
        $me   = requireUser($user);
        $name = str_field($in, 'name', 80);
        if ($name === '') fail('חסר שם לנושא');
        db()->prepare('INSERT INTO topics (name, description, repo, keywords, created_by, created_at)
                       VALUES (?,?,?,?,?,?)')
            ->execute([$name, str_field($in, 'description', 2000), str_field($in, 'repo', 120),
                       str_field($in, 'keywords', 1000), $me['id'], nowTs()]);
        ok(['id' => (int) db()->lastInsertId()]);

    /**
     * עריכת נושא. מילות המפתח הן הכלי שבידי המשתמש לכוון את הקטלוג
     * האוטומטי בלי מודל ובלי עלות.
     */
    case 'update-topic': {
        requireUser($user);
        $id = (int) ($in['id'] ?? 0);
        $st = db()->prepare('SELECT id FROM topics WHERE id = ?');
        $st->execute([$id]);
        if (!$st->fetch()) fail('נושא לא נמצא', 404);

        $sets = []; $args = [];
        foreach (['name' => 80, 'description' => 2000, 'repo' => 120, 'keywords' => 1000] as $f => $max) {
            if (array_key_exists($f, $in)) { $sets[] = "$f = ?"; $args[] = str_field($in, $f, $max); }
        }
        if (array_key_exists('archived', $in)) { $sets[] = 'archived = ?'; $args[] = $in['archived'] ? 1 : 0; }
        if (!$sets) fail('אין מה לעדכן');

        $args[] = $id;
        db()->prepare('UPDATE topics SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
        ok();
    }

    /* ── מטלות ─────────────────────────────────────────────────── */

    case 'tasks': {
        $sql  = 'SELECT k.*, t.name topic_name, u.display_name author_name,
                        (SELECT COUNT(*) FROM notes n WHERE n.task_id = k.id) note_count
                   FROM tasks k
                   LEFT JOIN topics t ON t.id = k.topic_id
                   LEFT JOIN users  u ON u.id = k.created_by
                  WHERE 1=1';
        $args = [];

        // 'none' = דווקא מה שלא שויך. זה המסך שבו מתקנים את מה שהקטלוג
        // לא הצליח לסווג, ולכן הוא צריך סינון משלו.
        $unassigned = ($in['topic_id'] ?? null) === 'none';
        $tid = $unassigned ? null : int_or_null($in, 'topic_id');

        if ($unassigned)          { $sql .= ' AND k.topic_id IS NULL'; }
        elseif ($tid !== null)    { $sql .= ' AND k.topic_id = ?'; $args[] = $tid; }

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
        ok(['tasks' => $st->fetchAll(), 'counts' => statusCounts(db(), $tid, $unassigned)]);
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
        $body  = str_field($in, 'body', 20000);
        if ($title === '') fail('חסרה כותרת למטלה');

        /*
         * זה הלב של הקטלוג האוטומטי: כשלא נבחר נושא, המערכת בוחרת אחד.
         * אם היא לא בטוחה — היא לא מנחשת, אלא משאירה בלי נושא ורושמת
         * הצעה. מטלה בלי נושא בולטת ומתוקנת בקליק; מטלה בנושא הלא נכון
         * פשוט נעלמת.
         */
        // שיוך לפרויקט מחייב שהמשתמש חבר בו — גם רק כדי לפתוח מטלה.
        $projectId = int_or_null($in, 'project_id');
        if ($projectId !== null) requireProject($projectId, $me, 'read');

        // ספק שנבחר למטלה חייב להיות מחובר לחשבון של מי שפתח אותה.
        $provider = str_field($in, 'provider', 30);
        if ($provider !== '') {
            if (!providerExists($provider)) fail('ספק לא מוכר');
            if (!isset(userProviders((int) $me['id'])[$provider])) {
                fail('הספק "' . $provider . '" אינו מחובר לחשבון שלך');
            }
        }

        $topicId = int_or_null($in, 'topic_id');
        $auto    = $topicId === null && ($in['auto_topic'] ?? true) !== false;
        $verdict = null;

        if ($auto) {
            // החיבור של מי שפתח את המטלה, לא של המערכת: כל אחד נחסם
            // ומחויב על החשבון שלו.
            $verdict = classifyTask(db(), $title, $body, [
                'conn' => aiAuto() ? userConn($me) : [],
            ]);
            $topicId = $verdict['topic_id'];
        }

        db()->prepare(
            'INSERT INTO tasks (topic_id, project_id, provider, model, title, body, kind, priority,
                                repo, branch, seq, topic_source, topic_hint, topic_confidence,
                                created_by, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $topicId,
            $projectId,
            $provider,
            str_field($in, 'model', 80),
            $title,
            $body,
            pick_field($in, 'kind', KINDS, 'question'),
            pick_field($in, 'priority', PRIORITIES, 'normal'),
            str_field($in, 'repo', 120),
            str_field($in, 'branch', 120),
            (int) ($in['seq'] ?? 0),
            $verdict ? $verdict['source'] : 'manual',
            $verdict ? (string) ($verdict['hint'] ?? '') : '',
            $verdict ? (float) $verdict['confidence'] : 0,
            $me['id'], nowTs(), nowTs(),
        ]);
        $id = (int) db()->lastInsertId();

        // סידור לפי כללים בלבד: זה קורה בכל הוספה, ואסור שיעלה כסף או
        // ישהה את התגובה. סידור עם מודל מופעל ידנית מהמגירה.
        $order = reorderTopic(db(), $topicId);

        ok([
            'id'         => $id,
            'topic_id'   => $topicId,
            'project_id' => $projectId,
            'topic_name' => $verdict['topic_name'] ?? null,
            'source'     => $verdict['source'] ?? 'manual',
            'confidence' => $verdict['confidence'] ?? null,
            'hint'       => $verdict['hint'] ?? null,
            'fallback'   => $verdict['fallback'] ?? null,
            'position'   => array_search($id, $order['order'] ?? [], true),
        ]);
    }

    /* ── קטלוג אוטומטי ─────────────────────────────────────────── */

    /**
     * תצוגה מקדימה בזמן הקלדה. מילות מפתח בלבד — נקרא על כל הקשה
     * (מושהית), ואסור שיפנה למודל בתשלום.
     */
    case 'classify': {
        requireUser($user);
        $title = str_field($in, 'title', 200);
        if ($title === '') ok(['topic_id' => null, 'source' => 'none']);
        ok(classifyTask(db(), $title, str_field($in, 'body', 20000), ['conn' => []]));
    }

    /**
     * מקטלג מטלות שנשארו בלי נושא. מוגבל למנה אחת בכל קריאה, כדי
     * שעשרות מטלות לא ייתרגמו לעשרות פניות למודל בבקשה אחת.
     */
    case 'catalog-backlog': {
        $me = requireUser($user);
        $limit = max(1, min(25, (int) ($in['limit'] ?? 15)));
        $st = db()->prepare(
            'SELECT id, title, body FROM tasks
              WHERE topic_id IS NULL AND status NOT IN ("done","cancelled")
              ORDER BY id DESC LIMIT ?'
        );
        $st->execute([$limit]);
        $rows = $st->fetchAll();

        $conn     = userConn($me);
        $assigned = 0; $hints = 0; $touched = [];
        $results  = [];

        foreach ($rows as $r) {
            $v = classifyTask(db(), (string) $r['title'], (string) $r['body'], ['conn' => $conn]);
            db()->prepare('UPDATE tasks SET topic_id = ?, topic_source = ?, topic_hint = ?,
                                            topic_confidence = ?, updated_at = ? WHERE id = ?')
                ->execute([$v['topic_id'], $v['source'], (string) ($v['hint'] ?? ''),
                           (float) $v['confidence'], nowTs(), $r['id']]);

            if ($v['topic_id'] !== null) { $assigned++; $touched[$v['topic_id']] = true; }
            elseif (!empty($v['hint']))  { $hints++; }

            $results[] = ['id' => (int) $r['id'], 'title' => $r['title'],
                          'topic_id' => $v['topic_id'], 'topic_name' => $v['topic_name'],
                          'hint' => $v['hint'] ?? null, 'confidence' => $v['confidence'],
                          'source' => $v['source']];
        }
        foreach (array_keys($touched) as $tid) reorderTopic(db(), (int) $tid);

        ok(['checked' => count($rows), 'assigned' => $assigned, 'hints' => $hints, 'results' => $results]);
    }

    /**
     * הופך הצעת נושא לנושא אמיתי, ומושך אליו את כל המטלות שקיבלו את
     * אותה הצעה — אחרת המשתמש היה מאשר את אותו נושא חמש פעמים.
     */
    case 'apply-hint': {
        $me   = requireUser($user);
        $id   = (int) ($in['id'] ?? 0);
        $task = getTask(db(), $id);
        if (!$task) fail('מטלה לא נמצאה', 404);

        $name = str_field($in, 'name', 80) ?: (string) $task['topic_hint'];
        if ($name === '') fail('אין שם לנושא');

        $st = db()->prepare('SELECT id FROM topics WHERE name = ? AND archived = 0');
        $st->execute([$name]);
        $existing = $st->fetch();

        if ($existing) {
            $tid = (int) $existing['id'];
        } else {
            db()->prepare('INSERT INTO topics (name, description, repo, keywords, created_by, created_at)
                           VALUES (?,?,?,?,?,?)')
                ->execute([$name, 'נוצר מהצעת הקטלוג האוטומטי', '', '', $me['id'], nowTs()]);
            $tid = (int) db()->lastInsertId();
        }

        $hint = (string) $task['topic_hint'];
        $upd  = db()->prepare("UPDATE tasks SET topic_id = ?, topic_source = 'manual', topic_hint = '',
                                               updated_at = ? WHERE id = ?");
        $upd->execute([$tid, nowTs(), $id]);

        $also = 0;
        if ($hint !== '') {
            $sib = db()->prepare('SELECT id FROM tasks WHERE topic_id IS NULL AND topic_hint = ? AND id <> ?');
            $sib->execute([$hint, $id]);
            foreach ($sib->fetchAll() as $row) { $upd->execute([$tid, nowTs(), $row['id']]); $also++; }
        }
        reorderTopic(db(), $tid);
        ok(['topic_id' => $tid, 'name' => $name, 'also_moved' => $also]);
    }

    /** סידור מחדש של נושא. עם מודל רק כשהמשתמש ביקש זאת במפורש. */
    case 'reorder-topic': {
        $me = requireUser($user);
        $useModel = ($in['use_model'] ?? false) === true;
        $r = reorderTopic(db(), int_or_null($in, 'topic_id'), [
            'conn' => $useModel ? userConn($me) : [],
        ]);
        ok($r);
    }

    /** מצב הקטלוג. המפתח עצמו לא יוצא מכאן לעולם. */
    case 'ai-status':
        requireUser($user);
        ok(['ai' => aiStatus()]);

    case 'set-ai': {
        $me = requireUser($user);
        if ($me['role'] !== 'admin') fail('רק מנהל יכול לשנות את הגדרות המערכת', 403);

        $patch = [];
        if (array_key_exists('provider', $in)) {
            $prov = (string) $in['provider'];
            if (!providerExists($prov)) fail('ספק לא מוכר');
            $patch['ai_provider'] = $prov;
        }
        if (array_key_exists('key', $in)) {
            $key = trim((string) $in['key']);
            // מחרוזת ריקה = ניתוק, וחזרה לקטלוג לפי מילות מפתח בלבד.
            if ($key !== '') {
                try { checkProviderKey($patch['ai_provider'] ?? aiConn()['provider'], $key); }
                catch (InvalidArgumentException $e) { fail($e->getMessage()); }
            }
            $patch['ai_key'] = $key !== '' ? $key : null;
        }
        if (array_key_exists('ai_model', $in))     $patch['ai_model']     = str_field($in, 'ai_model', 80);
        if (array_key_exists('auto_catalog', $in)) $patch['auto_catalog'] = (bool) $in['auto_catalog'];
        if (!$patch) fail('אין מה לעדכן');

        configSet($patch);
        logEvent($me, 'system.ai', (string) ($patch['ai_provider'] ?? ''));
        ok(['ai' => aiStatus()]);
    }

    /** בדיקת חיבור למודל. פנייה זעירה, כדי שהמשתמש ידע מיד אם המפתח טוב. */
    case 'test-ai': {
        $me = requireUser($user);
        if ($me['role'] !== 'admin') fail('רק מנהל יכול לבדוק את החיבור', 403);
        $conn = aiConn();
        if ($conn['key'] === '') fail('לא הוגדר מפתח');
        try {
            aiComplete($conn, 'ענה קצר.', 'ענה במילה אחת: אישור', 16);
            ok(['message' => 'החיבור ל' . (PROVIDERS[$conn['provider']]['label'] ?? '') . ' תקין']);
        } catch (Throwable $e) {
            fail($e->getMessage());
        }
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
        if (array_key_exists('session_url', $in)) { $sets[] = 'session_url = ?'; $args[] = url_field($in, 'session_url'); }
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

    /* ── ספקי בינה מלאכותית של המשתמש ──────────────────────────
     *
     * המערכת אינה קשורה לספק אחד: כל משתמש מחבר את מי שיש לו, ובוחר
     * לכל מטלה מי יבצע אותה.
     */

    case 'providers': {
        $me   = requireUser($user);
        $mine = userProviders((int) $me['id']);

        $catalog = [];
        foreach (PROVIDERS as $id => $meta) {
            $catalog[] = [
                'provider'  => $id,
                'label'     => $meta['label'],
                'hint'      => $meta['hint'],
                'prefix'    => $meta['prefix'],
                'default_model' => $meta['default'],
                'connected' => $mine[$id]['connected'] ?? false,
                'model'     => $mine[$id]['model'] ?? $meta['default'],
                'tail'      => $mine[$id]['tail'] ?? '',
            ];
        }
        ok(['providers' => $catalog, 'default' => (string) ($me['default_provider'] ?? ''),
            'system' => aiStatus()]);
    }

    case 'connect-provider': {
        $me = requireUser($user);
        setUserProvider($me, str_field($in, 'provider', 30), trim((string) ($in['key'] ?? '')),
                        str_field($in, 'model', 80));
        ok(['providers' => userProviders((int) $me['id'])]);
    }

    case 'disconnect-provider': {
        $me = requireUser($user);
        removeUserProvider($me, str_field($in, 'provider', 30));
        ok();
    }

    case 'set-default-provider': {
        $me = requireUser($user);
        setDefaultProvider($me, str_field($in, 'provider', 30));
        ok();
    }

    /** בדיקת חיבור אמיתית לספק של המשתמש — פנייה זעירה, לא ניחוש. */
    case 'test-provider': {
        $me   = requireUser($user);
        $prov = str_field($in, 'provider', 30);
        try {
            $conn = userConn($me, $prov);
            if ($conn['key'] === '') fail('הספק אינו מחובר');
            $reply = aiComplete($conn, 'ענה קצר מאוד.', 'ענה במילה אחת: אישור', 16);
            logEvent($me, 'provider.test', $prov, true, $conn['model']);
            ok(['message' => 'החיבור תקין', 'model' => $conn['model'],
                'reply' => mb_substr(trim($reply), 0, 80)]);
        } catch (Throwable $e) {
            logEvent($me, 'provider.test', $prov, false, $e->getMessage());
            fail($e->getMessage());
        }
    }

    /* ── חיבורים אישיים ────────────────────────────────────────
     *
     * כל משתמש מביא את הגיטהאב שלו ואת מפתח קלוד שלו. הסודות מוצפנים
     * במנוחה ולעולם לא חוזרים ב-API — רק "מחובר" וארבע ספרות.
     */

    case 'my-connections': {
        $me = requireUser($user);
        $gh = userSecret((int) $me['id'], 'github_token');
        $an = userSecret((int) $me['id'], 'anthropic_key');
        ok(['connections' => [
            'github' => [
                'connected' => $gh !== '',
                'login'     => (string) ($me['github_login'] ?? ''),
                'scopes'    => array_values(array_filter(explode(',', (string) ($me['github_scopes'] ?? '')))),
                'tail'      => secretTail($gh),
            ],
            'anthropic' => ['connected' => $an !== '', 'tail' => secretTail($an)],
        ]]);
    }

    case 'connect-github': {
        $me    = requireUser($user);
        $token = trim((string) ($in['token'] ?? ''));
        if ($token === '') fail('חסר טוקן');

        // מאמתים מול גיטהאב לפני השמירה. טוקן פסול שנשמר היה מתגלה רק
        // בפעולה הבאה, ושם כבר לא ברור שהבעיה היא בחיבור.
        try {
            $who = ghWhoAmI($token);
        } catch (Throwable $e) {
            logEvent($me, 'github.connect', '', false, $e->getMessage());
            fail($e->getMessage());
        }

        setUserSecret((int) $me['id'], 'github_token', $token);
        db()->prepare('UPDATE users SET github_login = ?, github_scopes = ? WHERE id = ?')
            ->execute([$who['login'], implode(',', $who['scopes']), $me['id']]);

        logEvent($me, 'github.connect', $who['login'], true, implode(',', $who['scopes']));
        ok(['github' => $who]);
    }

    case 'disconnect-github': {
        $me = requireUser($user);
        setUserSecret((int) $me['id'], 'github_token', '');
        db()->prepare("UPDATE users SET github_login = '', github_scopes = '' WHERE id = ?")
            ->execute([$me['id']]);
        logEvent($me, 'github.disconnect');
        ok();
    }

    /** מפתח קלוד אישי. גובר על המפתח הכללי בפעולות של המשתמש הזה. */
    case 'set-my-anthropic-key': {
        $me  = requireUser($user);
        $key = trim((string) ($in['key'] ?? ''));
        if ($key !== '' && !preg_match('/^sk-ant-[A-Za-z0-9_\\-]{20,200}$/', $key)) {
            fail('המפתח אינו בפורמט של מפתח Anthropic (sk-ant-…)');
        }
        setUserSecret((int) $me['id'], 'anthropic_key', $key);
        logEvent($me, $key === '' ? 'anthropic.disconnect' : 'anthropic.connect');
        ok(['connected' => $key !== '', 'tail' => secretTail($key)]);
    }

    /* ── גיטהאב ────────────────────────────────────────────────── */

    case 'repos': {
        $me = requireUser($user);
        ok(['repos' => ghRepos(requireGithubToken($me), (int) ($in['page'] ?? 1), 50)]);
    }

    case 'create-repo': {
        $me   = requireUser($user);
        $name = str_field($in, 'name', 100);
        $repo = ghCreateRepo(requireGithubToken($me), $name, ($in['private'] ?? true) !== false,
                             str_field($in, 'description', 300));
        logEvent($me, 'repo.create', $repo['full_name']);
        ok(['repo' => $repo]);
    }

    /* ── פרויקטים ──────────────────────────────────────────────── */

    case 'projects':
        ok(['projects' => listProjects(requireUser($user))]);

    case 'create-project': {
        $me = requireUser($user);
        $id = createProject($me,
            str_field($in, 'name', 80),
            str_field($in, 'repo_owner', 100),
            str_field($in, 'repo_name', 100),
            str_field($in, 'default_branch', 100, 'main'),
            str_field($in, 'description', 2000));
        ok(['id' => $id]);
    }

    case 'project': {
        $me      = requireUser($user);
        $project = requireProject((int) ($in['id'] ?? 0), $me, 'read');
        ok(['project' => $project, 'members' => projectMembers((int) $project['id'])]);
    }

    /** רשימת המשתמשים לבחירת חברים. שם ומזהה בלבד — לא כתובות ולא סודות. */
    case 'people': {
        requireUser($user);
        ok(['people' => db()->query('SELECT id, username, display_name FROM users ORDER BY display_name')
                            ->fetchAll()]);
    }

    case 'set-member': {
        $me = requireUser($user);
        requireProject((int) ($in['project_id'] ?? 0), $me, 'admin');
        setMember($me, (int) $in['project_id'], (int) ($in['user_id'] ?? 0),
                  (string) ($in['level'] ?? 'read'));
        ok();
    }

    case 'remove-member': {
        $me = requireUser($user);
        requireProject((int) ($in['project_id'] ?? 0), $me, 'admin');
        removeMember($me, (int) $in['project_id'], (int) ($in['user_id'] ?? 0));
        ok();
    }

    /* ── עיון בקוד ─────────────────────────────────────────────── */

    case 'repo-tree': {
        $me      = requireUser($user);
        $project = requireProject((int) ($in['project_id'] ?? 0), $me, 'read');
        if ($project['repo_name'] === '') fail('לפרויקט לא מקושר ריפו');

        ok(['tree' => ghContents(requireGithubToken($me), $project['repo_owner'], $project['repo_name'],
                                 str_field($in, 'path', 400),
                                 str_field($in, 'ref', 100, (string) $project['default_branch']))]);
    }

    case 'repo-write': {
        $me      = requireUser($user);
        $project = requireProject((int) ($in['project_id'] ?? 0), $me, 'write');
        if ($project['repo_name'] === '') fail('לפרויקט לא מקושר ריפו');

        $path = str_field($in, 'path', 400);
        if ($path === '') fail('חסר נתיב לקובץ');

        $r = ghPutFile(requireGithubToken($me), $project['repo_owner'], $project['repo_name'],
                       $path, (string) ($in['content'] ?? ''),
                       str_field($in, 'message', 300, 'עדכון דרך לוח המטלות'),
                       str_field($in, 'branch', 100, (string) $project['default_branch']),
                       str_field($in, 'sha', 100));

        logEvent($me, 'repo.write', "{$project['repo_owner']}/{$project['repo_name']}:$path");
        ok(['result' => $r]);
    }

    /* ── ניהול ─────────────────────────────────────────────────── */

    case 'admin-users': {
        $me = requireUser($user);
        if ($me['role'] !== 'admin') fail('למנהל בלבד', 403);
        ok(['users' => db()->query(
            'SELECT id, username, display_name, role, created_at, github_login,
                    (github_token <> "") has_github, (anthropic_key <> "") has_key,
                    (SELECT COUNT(*) FROM project_members m WHERE m.user_id = users.id) projects
               FROM users ORDER BY id'
        )->fetchAll()]);
    }

    case 'admin-events': {
        $me = requireUser($user);
        if ($me['role'] !== 'admin') fail('למנהל בלבד', 403);

        $sql  = 'SELECT * FROM events WHERE 1=1';
        $args = [];
        if (($uid = int_or_null($in, 'user_id')) !== null) { $sql .= ' AND user_id = ?'; $args[] = $uid; }
        if (($act = str_field($in, 'action', 60)) !== '')  { $sql .= ' AND action LIKE ?'; $args[] = "$act%"; }
        if (($in['failed_only'] ?? false) === true)        { $sql .= ' AND ok = 0'; }
        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(500, (int) ($in['limit'] ?? 100)));

        $st = db()->prepare($sql);
        $st->execute($args);
        ok(['events' => $st->fetchAll()]);
    }

    /**
     * בדיקת סביבה. נועדה לענות על "למה זה לא עובד אצלי בשרת" בלי גישת
     * SSH: מה קיים, מה חסר, ולאן אפשר לצאת מכאן.
     */
    case 'admin-diagnostics': {
        $me = requireUser($user);
        if ($me['role'] !== 'admin') fail('למנהל בלבד', 403);

        $reach = function (string $url): array {
            if (!function_exists('curl_init')) return ['ok' => false, 'detail' => 'cURL חסר'];
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
                                    CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_NOBODY => true,
                                    CURLOPT_HTTPHEADER => ['User-Agent: ' . GH_UA]]);
            $okc = curl_exec($ch) !== false;
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            return ['ok' => $okc, 'detail' => $okc ? "HTTP $code" : $err];
        };

        $keyPerms = is_file(SECRET_KEY_FILE) ? substr(sprintf('%o', fileperms(SECRET_KEY_FILE)), -3) : '—';

        ok(['diagnostics' => [
            'php'            => PHP_VERSION,
            'pdo_sqlite'     => extension_loaded('pdo_sqlite'),
            'curl'           => function_exists('curl_init'),
            'sodium'         => function_exists('sodium_crypto_box_seal'),
            'mbstring'       => function_exists('mb_substr'),
            'data_writable'  => is_writable(dirname(DB_FILE)),
            'secret_key'     => ['exists' => is_file(SECRET_KEY_FILE), 'perms' => $keyPerms],
            'db_size'        => is_file(DB_FILE) ? filesize(DB_FILE) : 0,
            'reach_github'   => $reach('https://api.github.com'),
            'reach_anthropic' => $reach('https://api.anthropic.com'),
            'counts'         => [
                'users'    => (int) db()->query('SELECT COUNT(*) c FROM users')->fetch()['c'],
                'projects' => (int) db()->query('SELECT COUNT(*) c FROM projects')->fetch()['c'],
                'tasks'    => (int) db()->query('SELECT COUNT(*) c FROM tasks')->fetch()['c'],
                'events'   => (int) db()->query('SELECT COUNT(*) c FROM events')->fetch()['c'],
            ],
        ]]);
    }

    /* ── עובדים ────────────────────────────────────────────────── */

    case 'claim': {
        if (!$worker) fail('פעולה לעובדים בלבד', 403);
        $url  = url_field($in, 'session_url');
        $task = claimNextTask(db(), $worker, int_or_null($in, 'topic_id'));
        if (!$task) ok(['task' => null, 'message' => 'אין מטלות ממתינות']);

        // הסשן שמחזיק במטלה עכשיו. נרשם במשיכה ולא בסיום, כדי שגם
        // מטלה שנתקעה באמצע תשאיר שביל חזרה.
        if ($url) {
            db()->prepare('UPDATE tasks SET session_url = ? WHERE id = ?')->execute([$url, $task['id']]);
            $task['session_url'] = $url;
        }
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
        if ($url = url_field($in, 'session_url')) {
            db()->prepare('UPDATE tasks SET session_url = ? WHERE id = ?')->execute([$url, $id]);
        }
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
        if ($url = url_field($in, 'session_url')) {
            db()->prepare('UPDATE tasks SET session_url = ? WHERE id = ?')->execute([$url, $id]);
        }
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
