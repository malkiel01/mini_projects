<?php
/**
 * שכבת האחסון — SQLite.
 *
 * ‏SQLite ולא MySQL: אין פרטי התחברות לנהל, והקובץ נוסע עם התיקייה.
 * הכלי הזה מחזיק את הדבר הרגיש ביותר בכל הריפו — היסטוריית ההודעות
 * הפרטית של הבעלים — ולכן גוף ההודעה נשמר **מוצפן** (ראו crypto.php),
 * ומסד הנתונים כולו יושב ב-data/ שחסומה לגישה ישירה.
 *
 * הבחירה לשמור בשרת ולא רק בטלפון נעשתה במפורש: כך אפשר לשאול שאלות
 * מכל מכשיר. המחיר הוא שההתכתבויות יושבות על אחסון משותף, ומכאן
 * ההצפנה במנוחה והמפתח בקובץ נפרד מהמסד.
 */

declare(strict_types=1);

require_once __DIR__ . '/crypto.php';

// ניתן לדריסה לפני הטעינה — הבדיקות רצות על מסד זמני.
if (!defined('DB_FILE')) define('DB_FILE', __DIR__ . '/../data/scanner.sqlite');

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    ensureDataDir(dirname(DB_FILE));

    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // WAL: הבליעה מהטלפון כותבת בזמן שה-UI קורא כדי לענות על שאלה.
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void {
    $pdo->exec("
        -- הגדרות הבעלים: סיסמת הכניסה, אסימון הצימוד לגשר, ומפתח ה-AI.
        -- שורה יחידה (id=1). כלי אישי לבעלים אחד.
        CREATE TABLE IF NOT EXISTS owner (
            id             INTEGER PRIMARY KEY CHECK (id = 1),
            password_hash  TEXT NOT NULL DEFAULT '',
            pair_token     TEXT NOT NULL DEFAULT '',
            ai_provider    TEXT NOT NULL DEFAULT 'anthropic',
            ai_model       TEXT NOT NULL DEFAULT '',
            ai_key_enc     TEXT NOT NULL DEFAULT '',
            created_at     INTEGER NOT NULL DEFAULT 0,
            updated_at     INTEGER NOT NULL DEFAULT 0
        );

        -- חשבונות הוואטסאפ שהבעלים הגדיר. 'kind' מבדיל ביזנס מהפרטי,
        -- כדי שאפשר יהיה לצמצם שאלה לחשבון אחד.
        CREATE TABLE IF NOT EXISTS accounts (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            label       TEXT NOT NULL,
            kind        TEXT NOT NULL DEFAULT 'personal',
            active      INTEGER NOT NULL DEFAULT 1,
            created_at  INTEGER NOT NULL
        );

        -- הודעות שנקלטו. גוף ההודעה ושם השולח מוצפנים במנוחה.
        -- ‏ext_id הוא מזהה יציב שהגשר מחשב לכל הודעה, כדי שסריקה חוזרת
        -- לא תיצור כפילויות (ראו INSERT OR IGNORE ב-ingest.php).
        CREATE TABLE IF NOT EXISTS messages (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id    INTEGER REFERENCES accounts(id) ON DELETE CASCADE,
            ext_id        TEXT NOT NULL,
            chat_name_enc TEXT NOT NULL DEFAULT '',
            sender_enc    TEXT NOT NULL DEFAULT '',
            direction     TEXT NOT NULL DEFAULT 'in',   -- in / out
            body_enc      TEXT NOT NULL DEFAULT '',
            sent_at       INTEGER NOT NULL DEFAULT 0,    -- חותמת זמן ההודעה
            ingested_at   INTEGER NOT NULL DEFAULT 0,
            UNIQUE(account_id, ext_id)
        );

        CREATE INDEX IF NOT EXISTS idx_msg_account_time ON messages(account_id, sent_at);
        CREATE INDEX IF NOT EXISTS idx_msg_time         ON messages(sent_at);

        -- טבלת חיפוש חופשי בטקסט גלוי הייתה מפרה את ההצפנה במנוחה,
        -- ולכן החיפוש נעשה על גוף מפוענח בזיכרון (query.php). idx על
        -- הזמן מספיק כדי לצמצם את החלון לפני הפענוח.
    ");
    ensureOwnerRow($pdo);
}

/** יוצר את שורת הבעלים בפעם הראשונה. */
function ensureOwnerRow(PDO $pdo): void {
    $exists = $pdo->query('SELECT 1 FROM owner WHERE id = 1')->fetchColumn();
    if ($exists) return;
    $now = time();
    $pdo->prepare('INSERT INTO owner (id, created_at, updated_at) VALUES (1, ?, ?)')
        ->execute([$now, $now]);
}

function owner(): array {
    return db()->query('SELECT * FROM owner WHERE id = 1')->fetch() ?: [];
}

/** האם הבעלים כבר הגדיר סיסמה. לפני כן — מסך התקנה. */
function ownerConfigured(): bool {
    return (owner()['password_hash'] ?? '') !== '';
}

function updateOwner(array $fields): void {
    if (!$fields) return;
    $fields['updated_at'] = time();
    $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
    $st = db()->prepare("UPDATE owner SET $sets WHERE id = 1");
    $st->execute(array_values($fields));
}
