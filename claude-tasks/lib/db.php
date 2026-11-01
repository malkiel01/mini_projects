<?php
/**
 * שכבת האחסון — SQLite.
 *
 * נבחר SQLite ולא MySQL כי אין כאן פרטי התחברות לנהל, והקובץ נוסע עם
 * התיקייה. חשוב מזה: התור נשען על טרנזקציות אמיתיות. שני עובדים (סשן
 * Claude Code ו-cron בשרת) מושכים מאותו תור, ובלי נעילה אטומית שניהם
 * היו תופסים את אותה מטלה.
 */

declare(strict_types=1);

require_once __DIR__ . '/crypto.php';

// ניתן לדריסה לפני הטעינה — כך הבדיקות רצות על מסד זמני ולא נוגעות
// בנתונים האמיתיים.
if (!defined('DB_FILE')) define('DB_FILE', __DIR__ . '/../data/tasks.sqlite');

/** משך ההחזקה של מטלה שנתפסה. עובד שקרס משחרר אותה מעצמו בתום הזמן. */
const CLAIM_MINUTES = 30;

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    ensureDataDir(dirname(DB_FILE));

    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // WAL מאפשר קוראים במקביל לכותב — נחוץ כשה-UI מרענן בזמן שעובד כותב.
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    // בלי זה, שני כותבים מתנגשים מיד ב-SQLITE_BUSY במקום להמתין בתור.
    $pdo->exec('PRAGMA busy_timeout = 5000');

    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            username      TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            display_name  TEXT NOT NULL,
            role          TEXT NOT NULL DEFAULT 'member',
            created_at    INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS topics (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            name        TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            repo        TEXT NOT NULL DEFAULT '',
            created_by  INTEGER REFERENCES users(id),
            created_at  INTEGER NOT NULL,
            archived    INTEGER NOT NULL DEFAULT 0
        );

        CREATE TABLE IF NOT EXISTS tasks (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            topic_id    INTEGER REFERENCES topics(id) ON DELETE SET NULL,
            title       TEXT NOT NULL,
            body        TEXT NOT NULL DEFAULT '',
            kind        TEXT NOT NULL DEFAULT 'question',
            status      TEXT NOT NULL DEFAULT 'open',
            priority    TEXT NOT NULL DEFAULT 'normal',
            repo        TEXT NOT NULL DEFAULT '',
            branch      TEXT NOT NULL DEFAULT '',
            seq         INTEGER NOT NULL DEFAULT 0,
            created_by  INTEGER REFERENCES users(id),
            created_at  INTEGER NOT NULL,
            updated_at  INTEGER NOT NULL,
            claimed_by  TEXT NOT NULL DEFAULT '',
            claim_until INTEGER NOT NULL DEFAULT 0
        );

        CREATE TABLE IF NOT EXISTS notes (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id    INTEGER NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
            author     TEXT NOT NULL,
            kind       TEXT NOT NULL DEFAULT 'comment',
            body       TEXT NOT NULL,
            created_at INTEGER NOT NULL
        );

        CREATE INDEX IF NOT EXISTS idx_tasks_pick  ON tasks(status, topic_id, seq);
        CREATE INDEX IF NOT EXISTS idx_tasks_topic ON tasks(topic_id);
        CREATE INDEX IF NOT EXISTS idx_notes_task  ON notes(task_id, created_at);
    ");

    // תוספות הקטלוג האוטומטי. מסדים שנוצרו לפני הפיצ'ר משודרגים בהרצה
    // הבאה בלי לגעת בנתונים שבהם.
    addColumn($pdo, 'topics', 'keywords',         "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'tasks',  'topic_source',     "TEXT NOT NULL DEFAULT 'manual'");
    addColumn($pdo, 'tasks',  'topic_hint',       "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'tasks',  'topic_confidence', 'REAL NOT NULL DEFAULT 0');

    // קישור לסשן שעבד על המטלה. הלוח מחליף את הצ'אט, אבל כשצריך לחזור
    // ולראות איך משהו נעשה — זה השביל חזרה.
    addColumn($pdo, 'tasks',  'session_url',      "TEXT NOT NULL DEFAULT ''");

    /*
     * שכבת הפלטפורמה: פרויקטים שקשורים לריפו, חברות והרשאות, ויומן
     * פעולות. הסודות של המשתמש (טוקן גיטהאב, מפתח Anthropic) יושבים
     * עליו ומוצפנים — ראה lib/crypto.php.
     */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS projects (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            name           TEXT NOT NULL,
            repo_owner     TEXT NOT NULL DEFAULT '',
            repo_name      TEXT NOT NULL DEFAULT '',
            default_branch TEXT NOT NULL DEFAULT 'main',
            description    TEXT NOT NULL DEFAULT '',
            created_by     INTEGER REFERENCES users(id),
            created_at     INTEGER NOT NULL,
            archived       INTEGER NOT NULL DEFAULT 0,
            -- אסימון עובד משלו לכל פרויקט: הוא נשלח לתוך הריפו כסוד,
            -- ולכן אסור שיהיה המפתח לכל הלוח.
            agent_token    TEXT NOT NULL DEFAULT '',
            check_command  TEXT NOT NULL DEFAULT '',
            engine_at      INTEGER NOT NULL DEFAULT 0
        );

        CREATE TABLE IF NOT EXISTS project_members (
            project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            user_id    INTEGER NOT NULL REFERENCES users(id)    ON DELETE CASCADE,
            level      TEXT NOT NULL DEFAULT 'read',
            added_at   INTEGER NOT NULL,
            PRIMARY KEY (project_id, user_id)
        );

        CREATE TABLE IF NOT EXISTS events (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER REFERENCES users(id),
            actor      TEXT NOT NULL DEFAULT '',
            action     TEXT NOT NULL,
            target     TEXT NOT NULL DEFAULT '',
            ok         INTEGER NOT NULL DEFAULT 1,
            detail     TEXT NOT NULL DEFAULT '',
            ip         TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS user_providers (
            user_id  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            provider TEXT NOT NULL,
            api_key  TEXT NOT NULL DEFAULT '',
            model    TEXT NOT NULL DEFAULT '',
            added_at INTEGER NOT NULL,
            PRIMARY KEY (user_id, provider)
        );

        CREATE TABLE IF NOT EXISTS skills (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            -- NULL = סקיל גלובלי, זמין בכל הפרויקטים
            project_id  INTEGER REFERENCES projects(id) ON DELETE CASCADE,
            name        TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            body        TEXT NOT NULL DEFAULT '',
            always      INTEGER NOT NULL DEFAULT 0,
            created_by  INTEGER REFERENCES users(id),
            created_at  INTEGER NOT NULL,
            updated_at  INTEGER NOT NULL
        );

        CREATE UNIQUE INDEX IF NOT EXISTS idx_skill_name ON skills(IFNULL(project_id, 0), name);
        CREATE INDEX IF NOT EXISTS idx_events_time   ON events(created_at DESC);
        CREATE INDEX IF NOT EXISTS idx_members_user  ON project_members(user_id);
    ");

    addColumn($pdo, 'users', 'github_token',  "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'users', 'github_login',  "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'users', 'github_scopes', "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'users', 'default_provider', "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'tasks', 'project_id',        'INTEGER');

    // כניסה דרך הפלטפורמה (lib/platform.php). platform_sub ריק = לא מקושר.
    // link_offer = נפתח דרך הפלטפורמה, ועוד אפשר לחבר אליו חשבון ישן.
    addColumn($pdo, 'users', 'platform_sub', "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'users', 'email',        "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'users', 'link_offer',   'INTEGER NOT NULL DEFAULT 0');
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_sub ON users(platform_sub) WHERE platform_sub <> ''");
    $pdo->exec('CREATE TABLE IF NOT EXISTS login_locks (
                    lock_key     TEXT PRIMARY KEY,
                    fails        INTEGER NOT NULL DEFAULT 0,
                    locked_until INTEGER NOT NULL DEFAULT 0
                )');

    // מי מבצע את המטלה. ריק = ברירת המחדל של מי שפתח אותה.
    addColumn($pdo, 'tasks', 'provider',          "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'tasks', 'model',             "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'tasks', 'run_url',           "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'tasks', 'pr_url',            "TEXT NOT NULL DEFAULT ''");

    // פרויקטים שנוצרו לפני המנוע.
    addColumn($pdo, 'projects', 'agent_token',   "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'projects', 'check_command', "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'projects', 'engine_at',     'INTEGER NOT NULL DEFAULT 0');

    // היררכיה: פרויקט אב (ריפו) ותתי-פרויקטים לפי תיקייה בתוכו. נושא יכול
    // לשבת בתוך פרויקט — השלבים של אותו פרויקט.
    addColumn($pdo, 'projects', 'parent_id', 'INTEGER REFERENCES projects(id)');
    addColumn($pdo, 'projects', 'path',      "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'projects', 'icon',      "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'topics',   'project_id', 'INTEGER REFERENCES projects(id)');

    /*
     * מי כתב הערה — נקבע בשרת בלבד, ואין שדה שהלקוח שולח (docs/spec.md, 2.3).
     * owner = מנהל הלוח, member = משתמש אחר, worker = אסימון. הערות שנכתבו
     * לפני העמודה מקבלות legacy: אין דרך לדעת היום מי כתב אותן.
     */
    addColumn($pdo, 'notes', 'author_role',    "TEXT NOT NULL DEFAULT 'legacy'");
    addColumn($pdo, 'notes', 'author_user_id', 'INTEGER');

    // ענף שרק בעל הלוח עונה ומחליט בו. עובר בירושה לכל תתי-הפרויקטים.
    addColumn($pdo, 'projects', 'owner_only', 'INTEGER NOT NULL DEFAULT 0');

    /*
     * הגשר החי (docs/spec.md, 5): ערוץ לכל צ'אט, והודעות בשני הכיוונים.
     * ‏in = לוח ← צ'אט (ממתינה למסירה); out = צ'אט ← לוח (שיקוף).
     * האסימון של הערוץ נשמר רק כגיבוב.
     */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS channels (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id      INTEGER NOT NULL REFERENCES projects(id),
            name            TEXT NOT NULL,
            role            TEXT NOT NULL DEFAULT '',
            session_id      TEXT NOT NULL UNIQUE,
            session_url     TEXT NOT NULL DEFAULT '',
            token_hash      TEXT NOT NULL DEFAULT '',
            route           TEXT NOT NULL DEFAULT '',
            status          TEXT NOT NULL DEFAULT 'active',
            last_seen_at    INTEGER NOT NULL DEFAULT 0,
            listening_until INTEGER NOT NULL DEFAULT 0,
            created_at      INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS messages (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            channel_id     INTEGER NOT NULL REFERENCES channels(id) ON DELETE CASCADE,
            task_id        INTEGER REFERENCES tasks(id) ON DELETE SET NULL,
            direction      TEXT NOT NULL,
            author_role    TEXT NOT NULL,
            author_user_id INTEGER,
            kind           TEXT NOT NULL DEFAULT 'text',
            body           TEXT NOT NULL,
            reply_to       INTEGER,
            answered_by    TEXT NOT NULL DEFAULT '',
            delivered_at   INTEGER,
            created_at     INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_channels_token ON channels(token_hash);
        CREATE INDEX IF NOT EXISTS idx_channels_project ON channels(project_id);
        CREATE INDEX IF NOT EXISTS idx_messages_channel ON messages(channel_id, id);
        CREATE INDEX IF NOT EXISTS idx_messages_inbox ON messages(channel_id, direction, delivered_at);
    ");
    // הערוץ שבו נשאלה השאלה על המטלה — לשם תגיע התשובה
    addColumn($pdo, 'tasks', 'asked_channel_id', 'INTEGER');

    // חיבורים: כמה גיטהאב וכמה מפתחות לספק (lib/connections.php)
    migrateConnections($pdo);
    // אישורים ומדיניות (lib/approvals.php)
    migrateApprovals($pdo);
    // מסלול אחד: מי עובד ומי עונה (lib/routes.php)
    migrateRoutes($pdo);
    // מכסה ושימוש (lib/usage.php)
    migrateUsage($pdo);
}

/** ALTER TABLE ADD COLUMN אינו מכיר IF NOT EXISTS ב-SQLite — בודקים לבד. */
function addColumn(PDO $pdo, string $table, string $column, string $definition): void {
    foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll() as $col) {
        if ($col['name'] === $column) return;
    }
    $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
}

/* ── מצבי מטלה ─────────────────────────────────────────────────────
 *
 *   open        ממתינה שעובד ייקח אותה
 *   in_progress עובד מחזיק בה כרגע
 *   blocked     Claude שאל שאלה וממתין לתשובת אדם
 *   awaiting_approval  Claude ביקש אישור לפעולה רגישה (lib/approvals.php)
 *   answered    אדם ענה — מוכנה שייקחו אותה שוב
 *   done        הושלמה
 *   cancelled   בוטלה
 *
 * ‏answered הוא הלב של הרעיון: במקום שמטלה חסומה תישכח, מענה של אדם
 * מחזיר אותה לתור אוטומטית (ראה addNote).
 */
const STATUSES  = ['open', 'in_progress', 'blocked', 'awaiting_approval', 'answered', 'done', 'cancelled'];
const PICKABLE  = ['answered', 'open'];   // הסדר קובע עדיפות: קודם מה שנענה
const KINDS     = ['code', 'question', 'research'];
/** איך הנושא נקבע: אדם בחר, מילות מפתח התאימו, מודל החליט, או שאין. */
const TOPIC_SOURCES = ['manual', 'keyword', 'llm', 'none'];
const PRIORITIES = ['high', 'normal', 'low'];
/** מי כתב הערה. legacy — רק להערות שנכתבו לפני שהשדה נוסף. */
const NOTE_ROLES = ['owner', 'member', 'worker', 'platform'];

function nowTs(): int { return time(); }

/**
 * משחרר מטלות שההחזקה עליהן פגה. נקרא לפני כל משיכה מהתור, כדי שעובד
 * שקרס לא ינעל מטלה לנצח ובלי להיות תלוי ב-cron.
 */
function releaseExpiredClaims(PDO $pdo): int {
    $st = $pdo->prepare(
        "UPDATE tasks SET status = 'open', claimed_by = '', claim_until = 0
          WHERE status = 'in_progress' AND claim_until > 0 AND claim_until < ?"
    );
    $st->execute([nowTs()]);
    return $st->rowCount();
}

/**
 * מושך את המטלה הבאה ונועל אותה, הכל בטרנזקציה אחת.
 *
 * ‏BEGIN IMMEDIATE נוטל את נעילת הכתיבה כבר בפתיחה. בלעדיו שני עובדים
 * היו קוראים את אותה שורה ושניהם "מנצחים" בעדכון.
 */
/**
 * ‏$projectIds — תחום האסימון: הפרויקט שלו וכל הענף שמתחתיו (null = כל הלוח).
 * ‏$skipProjectIds — פרויקטים שהמשיכה מדלגת עליהם.
 * ‏$route — רק מטלות שהמסלול האפקטיבי שלהן הוא זה (lib/routes.php), והמטלה
 * שנלקחה זוכרת אותו (tasks.route). null — בלי סינון (בדיקות ישנות).
 */
function claimNextTask(PDO $pdo, string $worker, ?int $topicId = null, ?array $projectIds = null,
                       array $skipProjectIds = [], ?string $route = null): ?array {
    releaseExpiredClaims($pdo);

    $pdo->beginTransaction();
    try {
        $sql = "SELECT * FROM tasks WHERE status IN ('answered','open')";
        $args = [];
        if ($topicId !== null)   { $sql .= " AND topic_id = ?";   $args[] = $topicId; }
        // עובד עם אסימון של פרויקט אינו רואה מטלות של פרויקטים אחרים.
        if ($projectIds !== null) {
            if (!$projectIds) { $pdo->commit(); return null; }
            $sql .= ' AND project_id IN (' . implode(',', array_fill(0, count($projectIds), '?')) . ')';
            $args = array_merge($args, $projectIds);
        }
        if ($skipProjectIds) {
            $sql .= ' AND (project_id IS NULL OR project_id NOT IN ('
                  . implode(',', array_fill(0, count($skipProjectIds), '?')) . '))';
            $args = array_merge($args, $skipProjectIds);
        }
        // המסלול: מטלה בלי פרויקט — chat; אחרת לפי המסלול האפקטיבי של הפרויקט שלה
        if ($route !== null) {
            $on = projectIdsOnRoute($route);
            $in = $on ? 'project_id IN (' . implode(',', array_fill(0, count($on), '?')) . ')' : '0';
            $sql .= $route === 'chat' ? " AND (project_id IS NULL OR $in)" : " AND $in";
            $args = array_merge($args, $on);
        }
        // מטלה שנענתה קודמת לחדשה: אדם כבר ממתין לה.
        $sql .= " ORDER BY CASE status WHEN 'answered' THEN 0 ELSE 1 END,
                           CASE priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END,
                           seq, id
                  LIMIT 1";

        $st = $pdo->prepare($sql);
        $st->execute($args);
        $task = $st->fetch();

        if (!$task) { $pdo->commit(); return null; }

        $upd = $pdo->prepare(
            "UPDATE tasks SET status='in_progress', claimed_by=?, claim_until=?, updated_at=?, route=?
              WHERE id = ? AND status IN ('answered','open')"
        );
        $until = nowTs() + CLAIM_MINUTES * 60;
        $upd->execute([$worker, $until, nowTs(), $route ?? '', $task['id']]);

        if ($upd->rowCount() === 0) { $pdo->rollBack(); return null; }

        $pdo->commit();
        $task['status'] = 'in_progress';
        $task['claimed_by'] = $worker;
        $task['claim_until'] = $until;
        $task['route'] = $route ?? '';
        return $task;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function getTask(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM tasks WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function taskNotes(PDO $pdo, int $taskId): array {
    $st = $pdo->prepare('SELECT * FROM notes WHERE task_id = ? ORDER BY created_at, id');
    $st->execute([$taskId]);
    return $st->fetchAll();
}

/**
 * מוסיף הערה, ומחזיר מטלה חסומה לתור כשאדם ענה.
 *
 * זו הנקודה שבה "עניתי לו" הופך אוטומטית ל"יש לו מה לעשות" — בלי שאיש
 * צריך לזכור לשנות סטטוס ידנית.
 */
function addNote(PDO $pdo, int $taskId, string $author, string $kind, string $body,
                 string $role = 'worker', ?int $userId = null): array {
    if (!in_array($role, NOTE_ROLES, true)) $role = 'worker';
    $pdo->prepare('INSERT INTO notes (task_id, author, kind, body, created_at, author_role, author_user_id)
                   VALUES (?,?,?,?,?,?,?)')
        ->execute([$taskId, $author, $kind, $body, nowTs(), $role, $userId]);
    $noteId = (int) $pdo->lastInsertId();

    $task = getTask($pdo, $taskId);
    $reopened = false;

    if ($task && $kind === 'answer' && $task['status'] === 'blocked') {
        $pdo->prepare("UPDATE tasks SET status='answered', updated_at=? WHERE id=?")
            ->execute([nowTs(), $taskId]);
        $reopened = true;
    }

    return ['note_id' => $noteId, 'reopened' => $reopened, 'kind' => $kind, 'author_role' => $role];
}

/** ספירה לפי סטטוס — מזין את המונים בראש הממשק. */
function statusCounts(PDO $pdo, ?int $topicId = null, bool $unassignedOnly = false): array {
    $sql = 'SELECT status, COUNT(*) c FROM tasks';
    $args = [];
    if ($unassignedOnly)          { $sql .= ' WHERE topic_id IS NULL'; }
    elseif ($topicId !== null)    { $sql .= ' WHERE topic_id = ?'; $args[] = $topicId; }
    $sql .= ' GROUP BY status';

    $st = $pdo->prepare($sql);
    $st->execute($args);

    $out = array_fill_keys(STATUSES, 0);
    foreach ($st->fetchAll() as $row) $out[$row['status']] = (int) $row['c'];
    return $out;
}

require_once __DIR__ . '/connections.php';
require_once __DIR__ . '/approvals.php';
require_once __DIR__ . '/routes.php';
require_once __DIR__ . '/usage.php';
