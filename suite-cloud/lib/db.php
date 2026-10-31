<?php
/**
 * האחסון — SQLite דרך PDO, ותוכן הקבצים בדיסק.
 *
 * המיגרציות הן CREATE TABLE IF NOT EXISTS: פריסה על מסד קיים לא נופלת ולא דורסת, ואין שלב התקנה.
 * data/ אינה בגיט (חוץ מ-.htaccess), ו-deploy.yml מחריג אותה — בלי זה פריסה הייתה דורסת את הקבצים של המשתמשים.
 */

declare(strict_types=1);

// ניתנים לדריסה לפני הטעינה — כך הבדיקות רצות על תיקייה זמנית.
if (!defined('DATA_DIR')) define('DATA_DIR', __DIR__ . '/../data');
if (!defined('DB_FILE')) define('DB_FILE', DATA_DIR . '/cloud.sqlite');

final class AppError extends RuntimeException {
    public function __construct(string $message, public readonly int $status = 400, public readonly string $codeName = 'bad_request', public readonly array $extra = []) {
        parent::__construct($message);
    }
}

/** ISO-8601 ב-UTC — נשמר כטקסט ומסתדר לקסיקוגרפית. */
function nowIso(int $t = 0): string {
    return gmdate('Y-m-d\TH:i:s\Z', $t ?: time());
}

/** מזהה אקראי עם קידומת: n_ קובץ / תיקייה, a_ חשבון, u_ העלאה. */
function randomId(string $prefix): string {
    $abc = 'abcdefghijkmnpqrstuvwxyz23456789';
    $s = '';
    foreach (str_split(random_bytes(16)) as $b) $s .= $abc[ord($b) % 32];
    return $prefix . $s;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    if (!is_dir(DATA_DIR) && !@mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
        throw new AppError('לא ניתן ליצור את תיקיית הנתונים', 500, 'server');
    }
    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS accounts (
            id            TEXT    PRIMARY KEY,
            platform_sub  TEXT    UNIQUE,
            email         TEXT    NOT NULL UNIQUE COLLATE NOCASE,
            name          TEXT    NOT NULL,
            role          TEXT    NOT NULL DEFAULT 'user' CHECK (role IN ('admin','user')),
            quota         INTEGER,
            blocked       INTEGER NOT NULL DEFAULT 0,
            created_at    TEXT    NOT NULL,
            last_login_at TEXT
        );
        CREATE TABLE IF NOT EXISTS nodes (
            id          TEXT    PRIMARY KEY,
            account     TEXT    NOT NULL REFERENCES accounts(id),
            parent      TEXT    REFERENCES nodes(id),
            kind        TEXT    NOT NULL CHECK (kind IN ('folder','file')),
            name        TEXT    NOT NULL,
            size        INTEGER NOT NULL DEFAULT 0,
            sha256      TEXT,
            mime        TEXT,
            rev         INTEGER NOT NULL DEFAULT 1,
            created_at  TEXT    NOT NULL,
            updated_at  TEXT    NOT NULL,
            updated_by  TEXT    NOT NULL DEFAULT '',
            trashed_at  TEXT
        );
        CREATE INDEX IF NOT EXISTS nodes_parent ON nodes(account, parent);
        CREATE INDEX IF NOT EXISTS nodes_sha ON nodes(sha256);
        CREATE TABLE IF NOT EXISTS uploads (
            id          TEXT    PRIMARY KEY,
            account     TEXT    NOT NULL REFERENCES accounts(id),
            parent      TEXT,
            name        TEXT    NOT NULL,
            size        INTEGER NOT NULL,
            sha256      TEXT    NOT NULL,
            mime        TEXT    NOT NULL,
            on_conflict TEXT    NOT NULL,
            if_rev      INTEGER,
            received    INTEGER NOT NULL DEFAULT 0,
            created_at  TEXT    NOT NULL
        );
    ");
    require_once __DIR__ . '/clients.php';
    migrateClients($pdo);
}

const DEFAULTS = [
    'default_quota' => '0',                 // הכרעה 1: 0 עד שהמנהל מאשר
    'max_file'      => '524288000',         // הכרעה 2: 500MB
];

function setting(string $key): string {
    $st = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    if ($v !== false) return (string) $v;
    if ($key === 'cloud_id') {
        // מזהה אקראי של הענן הזה — נכנס ל-lib.cloud בקבצים. לא כתובת ולא מייל.
        $v = 'cl_' . bin2hex(random_bytes(12));
        db()->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)')->execute([$key, $v]);
        return setting($key);
    }
    return DEFAULTS[$key] ?? '';
}

function setSetting(string $key, string $value): void {
    db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
}
