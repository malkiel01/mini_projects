<?php
/**
 * אחסון: SQLite דרך PDO, וקבצים בתיקייה מחוץ לגישה ישירה (spec/07-architecture.md).
 *
 * SQLite ולא MySQL — כמו שאר האפליקציות בשרת: אין פרטי התחברות לנהל, והקובץ נוסע עם התיקייה כשהאפליקציה
 * תצא לדומיין משלה. המיגרציות הן CREATE ... IF NOT EXISTS: פריסה על מסד קיים לא נופלת ולא דורסת.
 *
 * הנתונים ב-greeting-cards/data/studio/ — ‏data/ חסומה לגישה ישירה (.htaccess), ו-deploy.yml מחריג אותה,
 * כך שפריסה לא נוגעת בה.
 */

declare(strict_types=1);

if (!defined('STUDIO_DATA')) define('STUDIO_DATA', dirname(__DIR__, 2) . '/data/studio');

final class ApiError extends Exception {
    public function __construct(string $message, public readonly int $status = 400, public readonly array $extra = []) {
        parent::__construct($message);
    }
}

/** כמו ב-recipes-app: כל טרנזקציה של כתיבה היא BEGIN IMMEDIATE, כדי ש-busy_timeout באמת ימתין. */
final class StudioPDO extends PDO {
    private bool $inTx = false;
    public function beginTransaction(): bool { $this->exec('BEGIN IMMEDIATE'); return $this->inTx = true; }
    public function commit(): bool { $this->exec('COMMIT'); $this->inTx = false; return true; }
    public function rollBack(): bool { if ($this->inTx) { $this->inTx = false; $this->exec('ROLLBACK'); } return true; }
    public function inTransaction(): bool { return $this->inTx; }
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!is_dir(STUDIO_DATA) && !@mkdir(STUDIO_DATA, 0775, true) && !is_dir(STUDIO_DATA)) {
        throw new ApiError('לא ניתן ליצור את תיקיית הנתונים', 500);
    }
    $pdo = new StudioPDO('sqlite:' . STUDIO_DATA . '/studio.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void {
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS users (
            id            INTEGER PRIMARY KEY,
            email         TEXT UNIQUE,
            name          TEXT NOT NULL DEFAULT '',
            role          TEXT NOT NULL DEFAULT 'user',      -- owner | user
            status        TEXT NOT NULL DEFAULT 'active',    -- active | blocked
            platform_sub  TEXT UNIQUE,                       -- רק בזמן שהאפליקציה בתוך הפלטפורמה
            created_at    TEXT NOT NULL,
            last_login_at TEXT
        );
        CREATE TABLE IF NOT EXISTS sessions (
            token_hash TEXT PRIMARY KEY,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            expires_at INTEGER NOT NULL,
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS login_codes (
            email      TEXT PRIMARY KEY,
            code_hash  TEXT NOT NULL,
            expires_at INTEGER NOT NULL,
            attempts   INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS files (
            id         TEXT PRIMARY KEY,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            mime       TEXT NOT NULL,
            bytes      INTEGER NOT NULL,
            width      INTEGER,
            height     INTEGER,
            sha256     TEXT NOT NULL,
            created_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS files_user_sha ON files(user_id, sha256);
        CREATE TABLE IF NOT EXISTS designs (
            id         TEXT PRIMARY KEY,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            title      TEXT NOT NULL,
            doc        TEXT NOT NULL,
            width      INTEGER NOT NULL,
            height     INTEGER NOT NULL,
            thumb_id   TEXT,
            version    INTEGER NOT NULL DEFAULT 1,
            updated_at INTEGER NOT NULL,                     -- מילישניות, כמו בצד הלקוח
            deleted_at INTEGER
        );
        CREATE INDEX IF NOT EXISTS designs_user ON designs(user_id, updated_at);
        CREATE TABLE IF NOT EXISTS design_versions (
            id         INTEGER PRIMARY KEY,
            design_id  TEXT NOT NULL REFERENCES designs(id) ON DELETE CASCADE,
            doc        TEXT NOT NULL,
            created_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS audit (
            id         INTEGER PRIMARY KEY,
            user_id    INTEGER,
            action     TEXT NOT NULL,
            meta       TEXT,
            created_at TEXT NOT NULL
        );
    SQL);
}

function nowIso(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
function nowMs(): int { return (int)floor(microtime(true) * 1000); }

function audit(?int $userId, string $action, array $meta = []): void {
    db()->prepare('INSERT INTO audit (user_id, action, meta, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$userId, $action, $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null, nowIso()]);
}
