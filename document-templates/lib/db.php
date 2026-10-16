<?php
/**
 * המסד — SQLite, של האפליקציה בלבד.
 *
 * עמודות שמסתיימות ב-_enc מחזיקות תוכן מוצפן (seal/unseal). מה שאינו מוצפן
 * הוא מטא-דאטה שהשרת חייב לראות כדי לעבוד: בעלות, מצב, תאריכים, נפחים.
 * זה גם כל מה שמסך הפיתוח רואה — המפתח לעולם אינו רואה תוכן.
 *
 * CREATE TABLE IF NOT EXISTS: פריסה על מסד קיים אינה דורסת דבר.
 */

declare(strict_types=1);

require_once __DIR__ . '/crypto.php';

if (!defined('DT_DB_FILE')) define('DT_DB_FILE', DT_DATA_DIR . '/app.sqlite');

function now_iso(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    ensure_data_dir(dirname(DT_DB_FILE));
    $pdo = new PDO('sqlite:' . DT_DB_FILE, null, null, [
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
            created_at TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            used_at    TEXT
        );
        CREATE TABLE IF NOT EXISTS login_attempts (
            key          TEXT PRIMARY KEY,
            failures     INTEGER NOT NULL DEFAULT 0,
            locked_until TEXT
        );
        -- מכסות שהמפתח קובע לכל חשבון. NULL = ברירת המחדל של המערכת.
        CREATE TABLE IF NOT EXISTS quotas (
            user_id             INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
            storage_mb          INTEGER,
            max_file_mb         INTEGER,
            file_retention_days INTEGER,
            ai_enabled          INTEGER NOT NULL DEFAULT 0,
            ai_credits          INTEGER NOT NULL DEFAULT 0,
            sms_credits         INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS credit_ledger (
            id      INTEGER PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id),
            kind    TEXT NOT NULL CHECK (kind IN ('ai','sms')),
            amount  INTEGER NOT NULL,
            reason  TEXT NOT NULL,          -- תיאור פעולה, לעולם לא תוכן
            at      TEXT NOT NULL
        );

        -- ── תבניות ───────────────────────────────────────────────
        CREATE TABLE IF NOT EXISTS templates (
            id              INTEGER PRIMARY KEY,
            owner_id        INTEGER NOT NULL REFERENCES users(id),
            name_enc        TEXT NOT NULL,
            source          TEXT NOT NULL
                            CHECK (source IN ('docx','pdf_parsed','pdf_locked','image','authored')),
            numbering_json  TEXT,
            tags_enc        TEXT,
            current_version INTEGER NOT NULL DEFAULT 0,
            used            INTEGER NOT NULL DEFAULT 0,   -- 1 = לעולם לא נמחקת באמת
            archived_at     TEXT,
            deleted_at      TEXT,
            created_at      TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS template_versions (
            id          INTEGER PRIMARY KEY,
            template_id INTEGER NOT NULL REFERENCES templates(id),
            version     INTEGER NOT NULL,
            schema_enc  TEXT NOT NULL,       -- שדות, תפקידים, סדר, תנאים, חישובים
            created_at  TEXT NOT NULL,
            UNIQUE (template_id, version)
        );
        CREATE TABLE IF NOT EXISTS template_assets (
            id                  INTEGER PRIMARY KEY,
            template_version_id INTEGER NOT NULL REFERENCES template_versions(id),
            path                TEXT NOT NULL,   -- שם אקראי בתוך data/files, הקובץ מוצפן
            mime                TEXT NOT NULL,
            size                INTEGER NOT NULL,
            sha256              TEXT NOT NULL
        );

        -- ── שיתוף ואנשי קשר ──────────────────────────────────────
        CREATE TABLE IF NOT EXISTS shares (
            object_type TEXT NOT NULL CHECK (object_type IN ('template','document')),
            object_id   INTEGER NOT NULL,
            user_id     INTEGER NOT NULL REFERENCES users(id),
            level       TEXT NOT NULL CHECK (level IN ('view','fill','edit','co_owner')),
            granted_by  INTEGER NOT NULL REFERENCES users(id),
            created_at  TEXT NOT NULL,
            PRIMARY KEY (object_type, object_id, user_id)
        );
        CREATE TABLE IF NOT EXISTS contacts (
            id          INTEGER PRIMARY KEY,
            owner_id    INTEGER NOT NULL REFERENCES users(id),
            data_enc    TEXT NOT NULL,       -- שם, ת"ז, טלפון, מייל, כתובת, תגיות
            archived_at TEXT,
            created_at  TEXT NOT NULL
        );

        -- ── מסמכים ───────────────────────────────────────────────
        CREATE TABLE IF NOT EXISTS documents (
            id                  INTEGER PRIMARY KEY,
            owner_id            INTEGER NOT NULL REFERENCES users(id),
            template_version_id INTEGER NOT NULL REFERENCES template_versions(id),
            number              TEXT,
            title_enc           TEXT,
            contact_id          INTEGER REFERENCES contacts(id),
            status              TEXT NOT NULL DEFAULT 'draft'
                                CHECK (status IN ('draft','sent','in_progress','completed',
                                                  'locked','expired','cancelled','declined')),
            tags_enc            TEXT,
            created_at          TEXT NOT NULL,
            completed_at        TEXT,
            final_path          TEXT,
            final_hash          TEXT,
            archived_at         TEXT,
            deleted_at          TEXT
        );
        CREATE TABLE IF NOT EXISTS participants (
            id           INTEGER PRIMARY KEY,
            document_id  INTEGER NOT NULL REFERENCES documents(id),
            role_key     TEXT NOT NULL,
            contact_enc  TEXT,               -- שם, מייל, טלפון
            auth         TEXT NOT NULL DEFAULT 'none' CHECK (auth IN ('none','email','sms')),
            token_hash   TEXT UNIQUE,
            status       TEXT NOT NULL DEFAULT 'waiting',
            opened_at    TEXT,
            completed_at TEXT,
            expires_at   TEXT
        );
        CREATE TABLE IF NOT EXISTS field_values (
            document_id INTEGER NOT NULL REFERENCES documents(id),
            field_key   TEXT NOT NULL,
            row_index   INTEGER NOT NULL DEFAULT 0,
            value_enc   TEXT,
            updated_by  TEXT NOT NULL,       -- user:<id> | participant:<id>
            updated_at  TEXT NOT NULL,
            PRIMARY KEY (document_id, field_key, row_index)
        );
        CREATE TABLE IF NOT EXISTS attachments (
            id          INTEGER PRIMARY KEY,
            document_id INTEGER NOT NULL REFERENCES documents(id),
            field_key   TEXT NOT NULL,
            path        TEXT NOT NULL,
            name_enc    TEXT NOT NULL,
            mime        TEXT NOT NULL,
            size        INTEGER NOT NULL,
            sha256      TEXT NOT NULL,
            uploaded_by TEXT NOT NULL,
            at          TEXT NOT NULL
        );
        -- היומן: הוספה בלבד. כל רשומה נושאת hash של הקודמת — מחיקה או שינוי נראים.
        CREATE TABLE IF NOT EXISTS events (
            id          INTEGER PRIMARY KEY,
            document_id INTEGER NOT NULL REFERENCES documents(id),
            actor       TEXT NOT NULL,
            type        TEXT NOT NULL,
            detail_enc  TEXT,
            ip          TEXT,
            user_agent  TEXT,
            prev_hash   TEXT,
            hash        TEXT NOT NULL,
            at          TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS counters (
            template_id INTEGER NOT NULL REFERENCES templates(id),
            period      TEXT NOT NULL,
            last_number INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (template_id, period)
        );

        -- ── מסך הפיתוח — בלי תוכן ────────────────────────────────
        CREATE TABLE IF NOT EXISTS dev_log (
            id          INTEGER PRIMARY KEY,
            at          TEXT NOT NULL,
            user_id     INTEGER,
            action      TEXT NOT NULL,
            error_code  TEXT,
            duration_ms INTEGER
        );

        CREATE INDEX IF NOT EXISTS idx_templates_owner ON templates(owner_id);
        CREATE INDEX IF NOT EXISTS idx_documents_owner ON documents(owner_id, status);
        CREATE INDEX IF NOT EXISTS idx_events_doc ON events(document_id, id);
        CREATE INDEX IF NOT EXISTS idx_shares_user ON shares(user_id);
        SQL);

    // שלב 2: אסימון האימות נושא את הסיסמה שנבחרה בהרשמה (מוצפנת). כך מי
    // שלוחץ על הקישור במייל מפעיל את הסיסמה *שלו*, גם אם מישהו אחר ניסה
    // להירשם עם אותה כתובת אחריו.
    $cols = array_column($pdo->query('PRAGMA table_info(email_tokens)')->fetchAll(), 'name');
    if (!in_array('payload_enc', $cols, true)) {
        $pdo->exec('ALTER TABLE email_tokens ADD COLUMN payload_enc TEXT');
    }

    // שלב 5: סדר המשתתפים, וקוד חד-פעמי במייל לאימות זהות.
    $cols = array_column($pdo->query('PRAGMA table_info(participants)')->fetchAll(), 'name');
    foreach (['sort_order INTEGER NOT NULL DEFAULT 0', 'otp_hash TEXT', 'otp_expires_at TEXT',
              'otp_attempts INTEGER NOT NULL DEFAULT 0', 'verified_at TEXT', 'notified_at TEXT'] as $def) {
        if (!in_array(strtok($def, ' '), $cols, true)) $pdo->exec("ALTER TABLE participants ADD COLUMN $def");
    }

    // שיתוף למילוי: מי ממלא כל שדה במסמך הזה (גובר על התבנית) — lib/participants.php, doc_assign
    $cols = array_column($pdo->query('PRAGMA table_info(documents)')->fetchAll(), 'name');
    if (!in_array('assign_enc', $cols, true)) $pdo->exec('ALTER TABLE documents ADD COLUMN assign_enc TEXT');

    // חיבורים: מקורות נתונים, מפתחות API (lib/integrations.php)
    if (function_exists('integrations_migrate')) integrations_migrate($pdo);
    // אזור הפיתוח: לוג מעקב מורחב, יומן ביקורת של פעולות מפתח (lib/devzone.php)
    if (function_exists('devzone_migrate')) devzone_migrate($pdo);
    if (function_exists('fonts_migrate')) fonts_migrate($pdo);
    if (function_exists('contacts_migrate')) contacts_migrate($pdo);   // ממלא ← איש קשר
    if (function_exists('fmt_migrate')) fmt_migrate($pdo);
    if (function_exists('lists_migrate')) lists_migrate($pdo);         // רשימות ערכים             // מבנים שמורים

    // היומן: אי אפשר לשנות או למחוק רשומה — גם לא בטעות בקוד עתידי.
    $pdo->exec(<<<'SQL'
        CREATE TRIGGER IF NOT EXISTS events_no_update BEFORE UPDATE ON events
        BEGIN SELECT RAISE(ABORT, 'events is append-only'); END;
        CREATE TRIGGER IF NOT EXISTS events_no_delete BEFORE DELETE ON events
        BEGIN SELECT RAISE(ABORT, 'events is append-only'); END;
        SQL);
}
