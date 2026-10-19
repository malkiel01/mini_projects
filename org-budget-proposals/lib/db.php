<?php
/**
 * המסד — SQLite, של האפליקציה בלבד (data/app.sqlite, מוחרג מהפריסה).
 *
 * CREATE TABLE IF NOT EXISTS: פריסה על מסד קיים אינה דורסת דבר.
 * עמודה חדשה בטבלה קיימת — דרך add_columns(), לא בשינוי ה-CREATE.
 */

declare(strict_types=1);

if (!defined('OB_DATA_DIR')) define('OB_DATA_DIR', __DIR__ . '/../data');
if (!defined('OB_DB_FILE')) define('OB_DB_FILE', OB_DATA_DIR . '/app.sqlite');

const OB_DATA_HTACCESS = <<<TXT
    # נוצר אוטומטית. data/ מכילה את המסד — מוגש רק דרך api.php.
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
    TXT;

function now_iso(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function iso_in(int $seconds): string
{
    return gmdate('Y-m-d\TH:i:s\Z', time() + $seconds);
}

function ensure_data_dir(string $dir): void
{
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("התיקייה $dir אינה ניתנת ליצירה");
    }
    $guard = $dir . '/.htaccess';
    if (!is_file($guard)) @file_put_contents($guard, OB_DATA_HTACCESS);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    ensure_data_dir(dirname(OB_DB_FILE));
    $pdo = new PDO('sqlite:' . OB_DB_FILE, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 15000');
    db_migrate($pdo);
    if (function_exists('orgs_migrate')) orgs_migrate($pdo);        // שלב 2 — lib/orgs.php
    if (function_exists('budgets_migrate')) budgets_migrate($pdo);  // שלב 3 — lib/budgets.php
    return $pdo;
}

function db_migrate(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        -- ── חשבונות ──────────────────────────────────────────────
        CREATE TABLE IF NOT EXISTS users (
            id                INTEGER PRIMARY KEY,
            email             TEXT NOT NULL UNIQUE COLLATE NOCASE,
            password_hash     TEXT NOT NULL,
            display_name      TEXT NOT NULL,
            is_developer      INTEGER NOT NULL DEFAULT 0,
            status            TEXT NOT NULL DEFAULT 'unverified'
                              CHECK (status IN ('unverified','pending','active','blocked','deleted')),
            email_verified_at TEXT,
            created_at        TEXT NOT NULL,
            last_login_at     TEXT,
            last_seen_at      TEXT
        );
        CREATE TABLE IF NOT EXISTS sessions (
            token_hash   TEXT PRIMARY KEY,
            user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            csrf         TEXT NOT NULL,
            device       TEXT,
            ip           TEXT,
            created_at   TEXT NOT NULL,
            last_seen_at TEXT NOT NULL,
            expires_at   TEXT NOT NULL
        );
        -- אימות מייל ואיפוס סיסמה: אסימון חד-פעמי, נשמר רק כ-hash.
        CREATE TABLE IF NOT EXISTS email_tokens (
            token_hash TEXT PRIMARY KEY,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            purpose    TEXT NOT NULL CHECK (purpose IN ('verify','reset')),
            payload    TEXT,
            created_at TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            used_at    TEXT
        );
        CREATE TABLE IF NOT EXISTS login_attempts (
            key          TEXT PRIMARY KEY,
            failures     INTEGER NOT NULL DEFAULT 0,
            locked_until TEXT
        );

        -- ── אזור הפיתוח ──────────────────────────────────────────
        -- לוג מעקב: כל בקשה ל-API — פעולה, תוצאה, משך, מי. בלי ערכים.
        CREATE TABLE IF NOT EXISTS dev_log (
            id          INTEGER PRIMARY KEY,
            at          TEXT NOT NULL,
            user_id     INTEGER,
            action      TEXT NOT NULL,
            method      TEXT,
            status      INTEGER,
            duration_ms INTEGER,
            ref         TEXT,
            ip          TEXT,
            message     TEXT,
            trace       TEXT,
            page        TEXT,
            req         TEXT,
            ua          TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_dev_log_at ON dev_log(at);
        -- יומן ביקורת: כל פעולת מפתח (אכיפה), עם סיבה. אין פעולה שמוחקת ממנו.
        CREATE TABLE IF NOT EXISTS dev_audit (
            id          INTEGER PRIMARY KEY,
            at          TEXT NOT NULL,
            dev_id      INTEGER NOT NULL REFERENCES users(id),
            op          TEXT NOT NULL,
            target_type TEXT NOT NULL,
            target_id   INTEGER NOT NULL,
            reason      TEXT NOT NULL,
            detail      TEXT
        );
        SQL);
}
