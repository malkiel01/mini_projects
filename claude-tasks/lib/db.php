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

// ניתן לדריסה לפני הטעינה — כך הבדיקות רצות על מסד זמני ולא נוגעות
// בנתונים האמיתיים.
if (!defined('DB_FILE')) define('DB_FILE', __DIR__ . '/../data/tasks.sqlite');

/** משך ההחזקה של מטלה שנתפסה. עובד שקרס משחרר אותה מעצמו בתום הזמן. */
const CLAIM_MINUTES = 30;

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dir = dirname(DB_FILE);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('תיקיית data/ אינה ניתנת ליצירה');
    }

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
            archived       INTEGER NOT NULL DEFAULT 0
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

        CREATE INDEX IF NOT EXISTS idx_events_time   ON events(created_at DESC);
        CREATE INDEX IF NOT EXISTS idx_members_user  ON project_members(user_id);
    ");

    addColumn($pdo, 'users', 'github_token',  "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'users', 'github_login',  "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'users', 'github_scopes', "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'users', 'default_provider', "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'tasks', 'project_id',        'INTEGER');

    // מי מבצע את המטלה. ריק = ברירת המחדל של מי שפתח אותה.
    addColumn($pdo, 'tasks', 'provider',          "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'tasks', 'model',             "TEXT NOT NULL DEFAULT ''");
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
 *   answered    אדם ענה — מוכנה שייקחו אותה שוב
 *   done        הושלמה
 *   cancelled   בוטלה
 *
 * ‏answered הוא הלב של הרעיון: במקום שמטלה חסומה תישכח, מענה של אדם
 * מחזיר אותה לתור אוטומטית (ראה addNote).
 */
const STATUSES  = ['open', 'in_progress', 'blocked', 'answered', 'done', 'cancelled'];
const PICKABLE  = ['answered', 'open'];   // הסדר קובע עדיפות: קודם מה שנענה
const KINDS     = ['code', 'question', 'research'];
/** איך הנושא נקבע: אדם בחר, מילות מפתח התאימו, מודל החליט, או שאין. */
const TOPIC_SOURCES = ['manual', 'keyword', 'llm', 'none'];
const PRIORITIES = ['high', 'normal', 'low'];

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
function claimNextTask(PDO $pdo, string $worker, ?int $topicId = null): ?array {
    releaseExpiredClaims($pdo);

    $pdo->beginTransaction();
    try {
        $sql = "SELECT * FROM tasks WHERE status IN ('answered','open')";
        $args = [];
        if ($topicId !== null) { $sql .= " AND topic_id = ?"; $args[] = $topicId; }
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
            "UPDATE tasks SET status='in_progress', claimed_by=?, claim_until=?, updated_at=?
              WHERE id = ? AND status IN ('answered','open')"
        );
        $until = nowTs() + CLAIM_MINUTES * 60;
        $upd->execute([$worker, $until, nowTs(), $task['id']]);

        if ($upd->rowCount() === 0) { $pdo->rollBack(); return null; }

        $pdo->commit();
        $task['status'] = 'in_progress';
        $task['claimed_by'] = $worker;
        $task['claim_until'] = $until;
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
function addNote(PDO $pdo, int $taskId, string $author, string $kind, string $body): array {
    $pdo->prepare('INSERT INTO notes (task_id, author, kind, body, created_at) VALUES (?,?,?,?,?)')
        ->execute([$taskId, $author, $kind, $body, nowTs()]);

    $task = getTask($pdo, $taskId);
    $reopened = false;

    if ($task && $kind === 'answer' && $task['status'] === 'blocked') {
        $pdo->prepare("UPDATE tasks SET status='answered', updated_at=? WHERE id=?")
            ->execute([nowTs(), $taskId]);
        $reopened = true;
    }

    return ['note_id' => (int) $pdo->lastInsertId(), 'reopened' => $reopened];
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
