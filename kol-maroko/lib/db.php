<?php
/**
 * המסד: data/kol.sqlite (מוחרג בפריסה). מיגרציות לפי user_version — כל שלב מוסיף, לא משנה.
 */

declare(strict_types=1);

if (!defined('KOL_DATA_DIR')) define('KOL_DATA_DIR', dirname(__DIR__) . '/data');

final class ApiError extends RuntimeException
{
    public function __construct(string $message, int $code = 400, public readonly array $extra = [])
    {
        parent::__construct($message, $code);
    }
}

function now_iso(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!is_dir(KOL_DATA_DIR) && !@mkdir(KOL_DATA_DIR, 0775, true)) throw new ApiError('אין גישה לתיקיית הנתונים', 500);
    $pdo = new PDO('sqlite:' . KOL_DATA_DIR . '/kol.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $v = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    $steps = [
        1 => <<<'SQL'
            -- החברים בכלי. sub ריק — הוזמן לפי מייל ועוד לא נכנס.
            CREATE TABLE members (
                id        INTEGER PRIMARY KEY,
                sub       TEXT UNIQUE,
                email     TEXT,
                name      TEXT NOT NULL DEFAULT '',
                role      TEXT NOT NULL CHECK (role IN ('editor','publisher')),
                added_by  TEXT NOT NULL,
                added_at  TEXT NOT NULL,
                last_seen TEXT
            );
            CREATE UNIQUE INDEX idx_members_email ON members(email) WHERE sub IS NULL;
            -- מי שעבר את השער ואין לו תפקיד — רשימת "ביקשו גישה" אצל הבעלים.
            CREATE TABLE access_requests (
                sub     TEXT PRIMARY KEY,
                name    TEXT NOT NULL DEFAULT '',
                email   TEXT,
                at      TEXT NOT NULL
            );
            CREATE TABLE audit (
                id     INTEGER PRIMARY KEY,
                at     TEXT NOT NULL,
                sub    TEXT NOT NULL,
                name   TEXT NOT NULL DEFAULT '',
                action TEXT NOT NULL,
                detail TEXT NOT NULL DEFAULT ''
            );
            CREATE INDEX idx_audit_at ON audit(at);
            CREATE TABLE settings (k TEXT PRIMARY KEY, v TEXT NOT NULL);
            SQL,
        // שלב 2: הטיוטה, היסטוריית השמירות ונעילת העריכה (lib/draft.php)
        2 => <<<'SQL'
            CREATE TABLE draft (
                id         INTEGER PRIMARY KEY CHECK (id = 1),
                json       TEXT NOT NULL,
                rev        INTEGER NOT NULL,
                updated_by TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
            CREATE TABLE revisions (
                id   INTEGER PRIMARY KEY,
                at   TEXT NOT NULL,
                ts   INTEGER NOT NULL,
                sub  TEXT NOT NULL,
                name TEXT NOT NULL,
                rev  INTEGER NOT NULL,
                json TEXT NOT NULL,
                note TEXT NOT NULL DEFAULT ''
            );
            CREATE TABLE edit_lock (
                id    INTEGER PRIMARY KEY CHECK (id = 1),
                sub   TEXT NOT NULL,
                name  TEXT NOT NULL,
                since INTEGER NOT NULL,
                beat  INTEGER NOT NULL
            );
            SQL,
        // שלב 3: קבצים והעלאות בחלקים (lib/files.php)
        3 => <<<'SQL'
            CREATE TABLE files (
                id      TEXT PRIMARY KEY,
                name    TEXT NOT NULL,
                type    TEXT NOT NULL,
                kind    TEXT NOT NULL,
                ext     TEXT NOT NULL,
                size    INTEGER NOT NULL,
                sha256  TEXT NOT NULL,
                w       INTEGER,
                h       INTEGER,
                by_sub  TEXT NOT NULL,
                by_name TEXT NOT NULL,
                at      TEXT NOT NULL
            );
            CREATE TABLE uploads (
                id      TEXT PRIMARY KEY,
                name    TEXT NOT NULL,
                size    INTEGER NOT NULL,
                sub     TEXT NOT NULL,
                started INTEGER NOT NULL
            );
            SQL,
        // שלב 4: העותק האחרון של טקסטים ארוכים מעורך התמלילים (lib/longtext.php)
        4 => <<<'SQL'
            CREATE TABLE longtexts (
                doc        TEXT PRIMARY KEY,
                html       TEXT NOT NULL,
                css        TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                updated_by TEXT NOT NULL
            );
            SQL,
        // שלב 6: פרסום — מפתח החתימה, הגרסאות, והקבצים שהאתר כבר אישר (lib/publish.php)
        5 => <<<'SQL'
            CREATE TABLE pub_keys (
                kid        TEXT PRIMARY KEY,
                public     TEXT NOT NULL,
                secret     TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                retired    INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE versions (
                n        INTEGER PRIMARY KEY,
                at       TEXT NOT NULL,
                sub      TEXT NOT NULL,
                name     TEXT NOT NULL,
                note     TEXT NOT NULL DEFAULT '',
                json     TEXT NOT NULL,
                html     TEXT NOT NULL,
                css      TEXT NOT NULL,
                assets   TEXT NOT NULL,
                status   TEXT NOT NULL CHECK (status IN ('pending','live','failed','superseded')),
                response TEXT,
                sent_at  TEXT
            );
            CREATE TABLE site_assets (
                sha  TEXT NOT NULL,
                site TEXT NOT NULL,
                at   TEXT NOT NULL,
                PRIMARY KEY (sha, site)
            );
            SQL,
        // ייבוא העמוד הקיים: העלאה גדולה (ZIP) מסומנת כייבוא
        6 => <<<'SQL'
            ALTER TABLE uploads ADD COLUMN purpose TEXT NOT NULL DEFAULT 'file';
            SQL,
        // שלב 7: עוזר הבינה — כל קריאה (request_id) ומכסה לכל חבר (lib/ai.php)
        7 => <<<'SQL'
            CREATE TABLE ai_usage (
                request_id    TEXT PRIMARY KEY,
                sub           TEXT NOT NULL,
                name          TEXT NOT NULL DEFAULT '',
                at            TEXT NOT NULL,
                model         TEXT NOT NULL,
                input_tokens  INTEGER NOT NULL DEFAULT 0,
                output_tokens INTEGER NOT NULL DEFAULT 0,
                status        TEXT NOT NULL CHECK (status IN ('pending','ok','refused','error')),
                prompt        TEXT NOT NULL DEFAULT '',
                error         TEXT,
                reported_at   TEXT
            );
            CREATE INDEX idx_ai_usage_sub_at ON ai_usage(sub, at);
            ALTER TABLE members ADD COLUMN ai_quota INTEGER;
            SQL,
    ];
    foreach ($steps as $n => $sql) {
        if ($v >= $n) continue;
        $pdo->beginTransaction();
        $pdo->exec($sql);
        $pdo->exec("PRAGMA user_version = $n");
        $pdo->commit();
    }
}

function setting(string $k, ?string $default = null): ?string
{
    $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string)$v;
}

function setting_set(string $k, string $v): void
{
    db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')->execute([$k, $v]);
}
