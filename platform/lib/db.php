<?php
/**
 * אחסון הפלטפורמה — SQLite דרך PDO, כמו שאר הכלים בריפו.
 *
 * data/platform.sqlite אינו בגיט, ו-deploy.yml מחריג אותו. המיגרציות הן
 * CREATE TABLE IF NOT EXISTS: פריסה על מסד קיים אינה דורסת דבר.
 */

declare(strict_types=1);

if (!defined('PLATFORM_DB')) define('PLATFORM_DB', __DIR__ . '/../data/platform.sqlite');

function now_iso(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $pdo = new PDO('sqlite:' . PLATFORM_DB, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    db_migrate($pdo);
    return $pdo;
}

function db_migrate(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS users (
            id            INTEGER PRIMARY KEY,
            username      TEXT NOT NULL UNIQUE COLLATE NOCASE,
            password_hash TEXT NOT NULL,
            display_name  TEXT NOT NULL,
            role          TEXT NOT NULL DEFAULT 'user'    CHECK (role IN ('admin','user')),
            status        TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','active','blocked')),
            created_at    TEXT NOT NULL,
            last_login_at TEXT
        );
        -- ההתחברות נשמרת כאן ולא ב-session של PHP: בשרת משותף ה-session
        -- נמחק אחרי 24 דקות בלי פעילות, ומשתמש היה מתנתק באמצע היום.
        CREATE TABLE IF NOT EXISTS sessions (
            token_hash TEXT PRIMARY KEY,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            csrf       TEXT NOT NULL,
            created_at TEXT NOT NULL,
            expires_at TEXT NOT NULL
        );
        -- כניסה לאפליקציה — נרשמת בשער (lib/gate.php, record_visit). אותה הגדרה
        -- כתובה גם שם; שינוי באחת — לשנות גם בשנייה.
        CREATE TABLE IF NOT EXISTS visits (
            id      INTEGER PRIMARY KEY,
            app     TEXT NOT NULL,
            user_id INTEGER,
            at      TEXT NOT NULL,
            device  TEXT NOT NULL DEFAULT '',
            ip_hash TEXT NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS visits_app_at  ON visits (app, at);
        CREATE INDEX IF NOT EXISTS visits_user_at ON visits (user_id, at);
        CREATE INDEX IF NOT EXISTS visits_at      ON visits (at);
        CREATE TABLE IF NOT EXISTS login_attempts (
            key          TEXT PRIMARY KEY,
            failures     INTEGER NOT NULL DEFAULT 0,
            locked_until TEXT
        );
        SQL);

    // עמודות שנוספו אחרי שהמסד כבר נוצר — ALTER רק כשהן חסרות.
    $cols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
    if (!in_array('must_change_pw', $cols, true)) {
        // סיסמה זמנית שהמנהל קבע: המשתמש חייב להחליף אותה בכניסה הבאה.
        $pdo->exec('ALTER TABLE users ADD COLUMN must_change_pw INTEGER NOT NULL DEFAULT 0');
    }
}
