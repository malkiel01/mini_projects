<?php
/**
 * שכבת האחסון — SQLite דרך PDO.
 *
 * SQLite ולא MySQL, מאותה סיבה שבמתכונים: אין פרטי התחברות לנהל, הקובץ
 * נוסע עם התיקייה, וזה מה שכבר עובד בשרת הזה. הווידאו עצמו לא במסד —
 * הוא קבצים ב-data/media/, והמסד מחזיק רק את הקטלוג.
 *
 * המיגרציות הן CREATE TABLE IF NOT EXISTS: פריסה על מסד קיים אינה נופלת
 * ואינה דורסת, ואין שלב התקנה שמישהו צריך לזכור.
 */

declare(strict_types=1);

require_once __DIR__ . '/errors.php';

// ניתן לדריסה לפני הטעינה — כך הבדיקות רצות על מסד זמני.
if (!defined('DB_FILE'))   define('DB_FILE',   __DIR__ . '/../data/camera-app.sqlite');
if (!defined('MEDIA_DIR')) define('MEDIA_DIR', __DIR__ . '/../data/media');
if (!defined('INBOX_DIR')) define('INBOX_DIR', __DIR__ . '/../data/inbox');
if (!defined('LIVE_DIR'))  define('LIVE_DIR',  __DIR__ . '/../data/live');

/** חתימת זמן אחידה. ISO-8601 ב-UTC — נשמר כטקסט, ומסתדר לקסיקוגרפית. */
function nowIso(): string {
    return gmdate('Y-m-d\TH:i:s\Z');
}

function isoFromTs(int $ts): string {
    return gmdate('Y-m-d\TH:i:s\Z', $ts);
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dir = dirname(DB_FILE);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new AppError('לא ניתן ליצור את תיקיית הנתונים', 500);
    }

    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // WAL: קוראים במקביל לכותב. הגשר מעלה מקטעים בזמן שמישהו מדפדף.
    $pdo->exec('PRAGMA journal_mode = WAL');
    // בלעדיו כל ה-CASCADE שבסכימה אינם קיימים.
    $pdo->exec('PRAGMA foreign_keys = ON');
    // כותב שני ממתין בתור במקום ליפול ב-SQLITE_BUSY.
    $pdo->exec('PRAGMA busy_timeout = 5000');

    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            username      TEXT    NOT NULL UNIQUE,
            password_hash TEXT    NOT NULL,
            display_name  TEXT    NOT NULL,
            role          TEXT    NOT NULL DEFAULT 'viewer'
                          CHECK (role IN ('admin','operator','viewer')),
            blocked       INTEGER NOT NULL DEFAULT 0,
            created_at    TEXT    NOT NULL,
            last_login_at TEXT
        );

        -- תיקיות למצלמות. עץ: parent_id NULL = שורש.
        CREATE TABLE IF NOT EXISTS camera_folders (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            parent_id  INTEGER REFERENCES camera_folders(id) ON DELETE SET NULL,
            name       TEXT    NOT NULL,
            sort       INTEGER NOT NULL DEFAULT 0,
            created_at TEXT    NOT NULL
        );

        -- גשר: התוכנה שיושבת ברשת הביתית ליד המצלמות ומדברת עם השרת.
        -- מזוהה בטוקן (נשמר רק ה-hash). ה-pair_code הוא חד-פעמי ולזמן קצר.
        CREATE TABLE IF NOT EXISTS bridges (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            name            TEXT    NOT NULL,
            token_hash      TEXT    UNIQUE,
            pair_code       TEXT,
            pair_expires_at TEXT,
            paired_at       TEXT,
            last_seen_at    TEXT,
            version         TEXT,
            local_ip        TEXT,
            info            TEXT,              -- JSON: מערכת, ffmpeg, עומס
            created_at      TEXT    NOT NULL
        );

        CREATE TABLE IF NOT EXISTS cameras (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            key            TEXT    NOT NULL UNIQUE,   -- מזהה קצר: שם תיקיית ה-FTP ושם בגשר
            name           TEXT    NOT NULL,
            folder_id      INTEGER REFERENCES camera_folders(id) ON DELETE SET NULL,
            bridge_id      INTEGER REFERENCES bridges(id) ON DELETE SET NULL,
            brand          TEXT    NOT NULL DEFAULT 'reolink'
                           CHECK (brand IN ('reolink','onvif','rtsp')),
            host           TEXT    NOT NULL DEFAULT '',
            rtsp_port      INTEGER NOT NULL DEFAULT 554,
            http_port      INTEGER NOT NULL DEFAULT 80,
            onvif_port     INTEGER NOT NULL DEFAULT 8000,
            username       TEXT    NOT NULL DEFAULT 'admin',
            password_enc   TEXT    NOT NULL DEFAULT '',
            stream_main    TEXT    NOT NULL DEFAULT '',  -- דריסה ידנית של כתובת ה-RTSP
            stream_sub     TEXT    NOT NULL DEFAULT '',
            has_ptz        INTEGER NOT NULL DEFAULT 0,
            has_audio      INTEGER NOT NULL DEFAULT 1,
            record_audio   INTEGER NOT NULL DEFAULT 0,   -- שמע רגיש חוקית; כבוי כברירת מחדל
            record_mode    TEXT    NOT NULL DEFAULT 'motion'
                           CHECK (record_mode IN ('manual','continuous','motion','schedule')),
            schedule       TEXT    NOT NULL DEFAULT '[]', -- JSON: חלונות [{days:[0-6],from:'22:00',to:'06:00'}]
            pre_seconds    INTEGER NOT NULL DEFAULT 10,
            post_seconds   INTEGER NOT NULL DEFAULT 20,
            retention_days INTEGER NOT NULL DEFAULT 30,
            manual_recording INTEGER NOT NULL DEFAULT 0, -- כפתור REC לחוץ
            live_wanted_until TEXT,                      -- מישהו צופה חי עד...
            status         TEXT    NOT NULL DEFAULT 'unknown'
                           CHECK (status IN ('unknown','online','offline')),
            last_seen_at   TEXT,
            last_snapshot_id INTEGER,
            notes          TEXT    NOT NULL DEFAULT '',
            sort           INTEGER NOT NULL DEFAULT 0,
            created_at     TEXT    NOT NULL
        );

        -- תיקיות נושא להקלטות. גם עץ. הקלטה יכולה להיות בכמה נושאים.
        CREATE TABLE IF NOT EXISTS topics (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            parent_id   INTEGER REFERENCES topics(id) ON DELETE SET NULL,
            name        TEXT    NOT NULL,
            description TEXT    NOT NULL DEFAULT '',
            created_at  TEXT    NOT NULL
        );

        -- הקטלוג. הקובץ עצמו ב-data/media/<path>.
        CREATE TABLE IF NOT EXISTS recordings (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            camera_id    INTEGER NOT NULL REFERENCES cameras(id) ON DELETE CASCADE,
            kind         TEXT    NOT NULL CHECK (kind IN ('video','snapshot')),
            source       TEXT    NOT NULL DEFAULT 'ftp'
                         CHECK (source IN ('ftp','bridge','player','upload')),
            trigger_kind TEXT    NOT NULL DEFAULT ''
                         CHECK (trigger_kind IN ('','motion','person','pet','vehicle','manual','continuous','schedule')),
            path         TEXT    NOT NULL UNIQUE,
            thumb_path   TEXT,
            size_bytes   INTEGER NOT NULL DEFAULT 0,
            started_at   TEXT    NOT NULL,   -- מתי צולם, לא מתי הגיע
            duration_s   REAL,
            width        INTEGER,
            height       INTEGER,
            title        TEXT    NOT NULL DEFAULT '',
            description  TEXT    NOT NULL DEFAULT '',
            tags         TEXT    NOT NULL DEFAULT '',  -- מופרדות בפסיק
            starred      INTEGER NOT NULL DEFAULT 0,
            locked       INTEGER NOT NULL DEFAULT 0,  -- לא נמחקת אוטומטית
            parent_id    INTEGER REFERENCES recordings(id) ON DELETE SET NULL, -- צילום מסך מתוך הקלטה
            created_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at   TEXT    NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_rec_camera_time ON recordings(camera_id, started_at);
        CREATE INDEX IF NOT EXISTS idx_rec_time        ON recordings(started_at);
        CREATE INDEX IF NOT EXISTS idx_rec_kind        ON recordings(kind, started_at);

        CREATE TABLE IF NOT EXISTS recording_topics (
            recording_id INTEGER NOT NULL REFERENCES recordings(id) ON DELETE CASCADE,
            topic_id     INTEGER NOT NULL REFERENCES topics(id)     ON DELETE CASCADE,
            PRIMARY KEY (recording_id, topic_id)
        );

        CREATE TABLE IF NOT EXISTS bookmarks (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            recording_id INTEGER NOT NULL REFERENCES recordings(id) ON DELETE CASCADE,
            at_seconds   REAL    NOT NULL,
            note         TEXT    NOT NULL DEFAULT '',
            created_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at   TEXT    NOT NULL
        );

        -- פקודות מהממשק אל הגשר. הגשר מושך אותן בכל heartbeat ומדווח תוצאה.
        CREATE TABLE IF NOT EXISTS commands (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            bridge_id  INTEGER NOT NULL REFERENCES bridges(id) ON DELETE CASCADE,
            camera_id  INTEGER REFERENCES cameras(id) ON DELETE CASCADE,
            type       TEXT    NOT NULL,      -- ptz, snapshot, record, preset, ...
            payload    TEXT    NOT NULL DEFAULT '{}',
            status     TEXT    NOT NULL DEFAULT 'pending'
                       CHECK (status IN ('pending','taken','done','failed','expired')),
            result     TEXT,
            created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at TEXT    NOT NULL,
            taken_at   TEXT,
            done_at    TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_cmd_bridge ON commands(bridge_id, status);

        -- יומן אירועים: תנועה, מצלמה נפלה, גשר התחבר, הקלטה נמחקה...
        CREATE TABLE IF NOT EXISTS events (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            at        TEXT    NOT NULL,
            kind      TEXT    NOT NULL,
            level     TEXT    NOT NULL DEFAULT 'info' CHECK (level IN ('info','warn','error')),
            camera_id INTEGER,   -- בלי FK: היומן שורד מחיקה
            bridge_id INTEGER,
            user_id   INTEGER,
            message   TEXT    NOT NULL DEFAULT '',
            meta      TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_events_at ON events(at);

        CREATE TABLE IF NOT EXISTS settings (
            key        TEXT PRIMARY KEY,
            value      TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );
    ");
}

function settingGet(string $key, string $default = ''): string {
    $st = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string) $v;
}

function settingSet(string $key, string $value): void {
    db()->prepare('INSERT INTO settings (key, value, updated_at) VALUES (?,?,?)
                   ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at')
        ->execute([$key, $value, nowIso()]);
}

function logEvent(string $kind, string $message = '', array $meta = [], string $level = 'info',
                  ?int $cameraId = null, ?int $bridgeId = null, ?int $userId = null): void {
    db()->prepare('INSERT INTO events (at, kind, level, camera_id, bridge_id, user_id, message, meta)
                   VALUES (?,?,?,?,?,?,?,?)')
        ->execute([nowIso(), $kind, $level, $cameraId, $bridgeId, $userId, $message,
                   $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null]);
}

function pruneEvents(): void {
    db()->exec("DELETE FROM events WHERE at < '" . isoFromTs(time() - 60 * 86400) . "'");
}
