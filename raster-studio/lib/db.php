<?php
// המסד של עיצוב רסטר — SQLite משלו, בלי שום קשר למסד הפלטפורמה.
// הנתיב ב-deploy.yml מוחרג (data/*.sqlite*), כך שהפריסה לא דורסת אותו.

const RS_DB_VERSION = 2;

function rs_db_path(): string
{
    return getenv('RASTER_STUDIO_DB') ?: __DIR__ . '/../data/raster.sqlite';
}

function rs_db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . rs_db_path(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 5000');
    rs_db_migrate($pdo);
    return $pdo;
}

function rs_db_migrate(PDO $pdo): void
{
    $v = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($v >= RS_DB_VERSION) return;
    $pdo->beginTransaction();
    if ($v < 1) {
        // accounts — משתמשי עיצוב רסטר. sub = המזהה בפלטפורמה (קישור); role: admin | user.
        $pdo->exec("CREATE TABLE accounts (
            id INTEGER PRIMARY KEY,
            sub TEXT UNIQUE,
            email TEXT,
            name TEXT NOT NULL DEFAULT '',
            role TEXT NOT NULL DEFAULT 'user',
            created_at TEXT NOT NULL,
            last_seen_at TEXT
        )");
        $pdo->exec('CREATE INDEX accounts_email ON accounts(email)');
        // docs — מסמכי רסטר. גודל הקנבס בפיקסלים (ב-.mbe: 1pt = 1px). התוכן — בשלב השמירה.
        $pdo->exec("CREATE TABLE docs (
            id INTEGER PRIMARY KEY,
            owner_id INTEGER NOT NULL REFERENCES accounts(id),
            title TEXT NOT NULL,
            width INTEGER NOT NULL,
            height INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            deleted_at TEXT
        )");
        $pdo->exec('CREATE INDEX docs_owner ON docs(owner_id, deleted_at)');
    }
    if ($v < 2) {
        // גרסאות: כל שמירה — קובץ ‎.mbe משלו ב-data/files/<owner>/<doc>/<n>.mbe. docs.version — האחרונה (0 = עוד לא נשמר)
        $pdo->exec('ALTER TABLE docs ADD COLUMN version INTEGER NOT NULL DEFAULT 0');
        $pdo->exec("CREATE TABLE doc_versions (
            id INTEGER PRIMARY KEY,
            doc_id INTEGER NOT NULL REFERENCES docs(id),
            n INTEGER NOT NULL,
            bytes INTEGER NOT NULL,
            sha256 TEXT NOT NULL,
            created_at TEXT NOT NULL,
            UNIQUE (doc_id, n)
        )");
    }
    $pdo->exec('PRAGMA user_version = ' . RS_DB_VERSION);
    $pdo->commit();
}

function rs_now(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}
