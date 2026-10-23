<?php
/**
 * שכבת האחסון — SQLite דרך PDO, כמו בשאר האפליקציות.
 *
 * המיגרציות הן CREATE TABLE IF NOT EXISTS: פריסה על מסד קיים לא נופלת ולא דורסת. כל שלב פיתוח
 * מוסיף את הטבלאות שלו (האפיון: docs/v1-screens-data.md, פרק 7).
 *
 * data/*.sqlite* אינו בגיט, ו-deploy.yml מחריג אותו — בלי ההחרגה פריסה הייתה דורסת את הנתונים.
 */

declare(strict_types=1);

require_once __DIR__ . '/errors.php';

// ניתן לדריסה לפני הטעינה — כך הבדיקות רצות על מסד זמני.
if (!defined('DB_FILE')) define('DB_FILE', __DIR__ . '/../data/time-clock.sqlite');

/** חתימת זמן אחידה: ISO-8601 ב-UTC. התצוגה בשעון ישראל — בדפדפן. */
function nowIso(): string {
    // שעון לבדיקות בלבד (tests/run.php) — כך אפשר לבדוק "עתיד" ו"עבר" בלי לחכות.
    if (PHP_SAPI === 'cli' && isset($GLOBALS['TC_NOW'])) return $GLOBALS['TC_NOW'];
    return gmdate('Y-m-d\TH:i:s\Z');
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
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void {
    $pdo->exec("
        -- אדם אחד בכל החברות. מנהל מקושר לחשבון הפלטפורמה (platform_sub); עובד — לא חייב.
        CREATE TABLE IF NOT EXISTS people (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            name         TEXT NOT NULL,
            email        TEXT,
            phone        TEXT,
            platform_sub TEXT UNIQUE,
            created_at   TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS people_email ON people (email COLLATE NOCASE);

        CREATE TABLE IF NOT EXISTS companies (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            name            TEXT NOT NULL,
            tax_id          TEXT,
            tz              TEXT NOT NULL DEFAULT 'Asia/Jerusalem',
            days_per_week   INTEGER NOT NULL DEFAULT 5 CHECK (days_per_week IN (5, 6)),
            daily_minutes   INTEGER NOT NULL DEFAULT 504,
            created_by      INTEGER NOT NULL REFERENCES people(id),
            created_at      TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS sites (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL REFERENCES companies(id),
            name       TEXT NOT NULL,
            created_at TEXT NOT NULL
        );

        -- חברוּת = אדם בחברה, עם תפקיד. מנהל סניף — עם site_id.
        CREATE TABLE IF NOT EXISTS members (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id  INTEGER NOT NULL REFERENCES companies(id),
            person_id   INTEGER NOT NULL REFERENCES people(id),
            role        TEXT NOT NULL CHECK (role IN ('owner','manager','branch_manager','payroll','employee')),
            site_id     INTEGER REFERENCES sites(id),
            employee_no TEXT,
            start_date  TEXT,
            end_date    TEXT,
            status      TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','left')),
            pin_hash    TEXT,
            created_at  TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS members_company ON members (company_id, status);
        CREATE INDEX IF NOT EXISTS members_person ON members (person_id, status);

        -- תנאי העסקה בגרסאות: התקף בתאריך = השורה האחרונה עם effective_from עד אותו תאריך.
        CREATE TABLE IF NOT EXISTS work_terms (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id      INTEGER NOT NULL REFERENCES members(id),
            effective_from TEXT NOT NULL,
            days_per_week  INTEGER NOT NULL CHECK (days_per_week IN (5, 6)),
            daily_minutes  INTEGER NOT NULL,
            pay_type       TEXT NOT NULL DEFAULT 'monthly' CHECK (pay_type IN ('monthly','hourly')),
            created_by     INTEGER,
            created_at     TEXT NOT NULL,
            UNIQUE (member_id, effective_from)
        );

        -- קישור הזמנה אישי: חד-פעמי, 7 ימים. נשמר רק ה-hash.
        CREATE TABLE IF NOT EXISTS invites (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id  INTEGER NOT NULL REFERENCES members(id),
            token_hash TEXT NOT NULL UNIQUE,
            created_by INTEGER,
            created_at TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            used_at    TEXT,
            revoked_at TEXT
        );

        -- מכשיר של עובד: אסימון לכל חברוּת (כך ביטול בחברה אחת לא נוגע באחרת). נשמר רק ה-hash.
        CREATE TABLE IF NOT EXISTS devices (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id  INTEGER NOT NULL REFERENCES members(id),
            token_hash TEXT NOT NULL UNIQUE,
            user_agent TEXT,
            created_at TEXT NOT NULL,
            last_seen  TEXT,
            revoked_at TEXT
        );

        -- דיווח נוכחות. לא משתנה ולא נמחק אף פעם: תיקון (שלב 5) הוא רשומה נפרדת.
        CREATE TABLE IF NOT EXISTS punches (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id  INTEGER NOT NULL REFERENCES members(id),
            kind       TEXT NOT NULL CHECK (kind IN ('in','out')),
            at_utc     TEXT NOT NULL,
            source     TEXT NOT NULL CHECK (source IN ('phone','station','manager')),
            station_id INTEGER,
            device_id  INTEGER,
            created_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS punches_member ON punches (member_id, at_utc);

        -- תיקון לדיווח: add (דיווח חדש), move (שעה אחרת לדיווח קיים), void (ביטול דיווח). הדיווח המקורי לא
        -- משתנה; החישוב מחיל רק תיקונים שאושרו. עובד מבקש (by_member), מנהל מתקן ישירות (by_person, מאושר מיד).
        CREATE TABLE IF NOT EXISTS corrections (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id   INTEGER NOT NULL REFERENCES members(id),
            op          TEXT NOT NULL CHECK (op IN ('add','move','void')),
            punch_id    INTEGER REFERENCES punches(id),
            new_kind    TEXT CHECK (new_kind IN ('in','out')),
            new_at      TEXT,
            reason      TEXT NOT NULL,
            by_member   INTEGER,
            by_person   INTEGER,
            status      TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected','cancelled')),
            decided_by  INTEGER,
            decided_at  TEXT,
            note        TEXT,
            created_at  TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS corrections_member ON corrections (member_id, status);

        -- כללי שעות נוספות של החברה, בגרסאות (JSON; ברירות המחדל — lib/overtime.php). גרסה 2.
        CREATE TABLE IF NOT EXISTS ot_rules (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id     INTEGER NOT NULL REFERENCES companies(id),
            effective_from TEXT NOT NULL,
            rules          TEXT NOT NULL,
            created_by     INTEGER,
            created_at     TEXT NOT NULL,
            UNIQUE (company_id, effective_from)
        );

        -- ימי חג של החברה (גרסה 2): לכל הדתות או לחלקן. החגים היהודיים — מובנים (lib/holidays/jewish.json).
        CREATE TABLE IF NOT EXISTS company_holidays (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL REFERENCES companies(id),
            date       TEXT NOT NULL,
            name       TEXT NOT NULL,
            religions  TEXT NOT NULL DEFAULT 'all',
            created_by INTEGER,
            created_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS company_holidays_date ON company_holidays (company_id, date);

        -- מסלול האישור החודשי של החברה: שלבים לפי סדר, כל שלב — תפקיד. ברירת מחדל: עובד ← מנהל.
        CREATE TABLE IF NOT EXISTS approval_steps (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL REFERENCES companies(id),
            ord        INTEGER NOT NULL,
            role       TEXT NOT NULL CHECK (role IN ('employee','manager','payroll','owner'))
        );

        -- חודש של חברה: open (רגיל) ← review (נפתח לאישור; השלבים נקבעים ברגע הפתיחה) ← locked.
        CREATE TABLE IF NOT EXISTS periods (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL REFERENCES companies(id),
            month      TEXT NOT NULL,
            status     TEXT NOT NULL DEFAULT 'review' CHECK (status IN ('review','locked')),
            steps      TEXT NOT NULL,
            opened_by  INTEGER,
            opened_at  TEXT NOT NULL,
            locked_by  INTEGER,
            locked_at  TEXT,
            UNIQUE (company_id, month)
        );

        -- אישור של עובד בחודש בשלב. revoked_at — בוטל (תיקון אחרי האישור, או פתיחה מחדש); נשמר לתיעוד.
        CREATE TABLE IF NOT EXISTS period_signoffs (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            period_id  INTEGER NOT NULL REFERENCES periods(id),
            member_id  INTEGER NOT NULL REFERENCES members(id),
            step       INTEGER NOT NULL,
            by_person  INTEGER,
            by_member  INTEGER,
            on_behalf  INTEGER NOT NULL DEFAULT 0,
            note       TEXT,
            at         TEXT NOT NULL,
            revoked_at TEXT
        );
        CREATE INDEX IF NOT EXISTS signoffs_period ON period_signoffs (period_id, member_id);

        -- עמדה בעסק: מכשיר משותף, מוצמד לחברה (ולסניף) בקוד חד-פעמי. נשמר רק ה-hash של האסימון.
        CREATE TABLE IF NOT EXISTS stations (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL REFERENCES companies(id),
            site_id    INTEGER REFERENCES sites(id),
            name       TEXT NOT NULL,
            token_hash TEXT UNIQUE,
            pair_hash  TEXT,
            pair_expires TEXT,
            created_by INTEGER,
            created_at TEXT NOT NULL,
            paired_at  TEXT,
            last_seen  TEXT,
            revoked_at TEXT
        );

        -- ניסיונות שגויים: קוד אישי (לכל חברוּת) וקוד הצמדה (לכל כתובת IP).
        CREATE TABLE IF NOT EXISTS throttle (
            k            TEXT PRIMARY KEY,
            fails        INTEGER NOT NULL DEFAULT 0,
            first_at     TEXT NOT NULL,
            locked_until TEXT
        );

        -- הגדרות ההיעדרויות של החברה (גרסה 3): JSON — היחידה ועוד (ברירות המחדל: lib/absences.php).
        CREATE TABLE IF NOT EXISTS absence_settings (
            company_id INTEGER PRIMARY KEY REFERENCES companies(id),
            settings   TEXT NOT NULL,
            updated_by INTEGER,
            updated_at TEXT NOT NULL
        );

        -- סוגי היעדרות של החברה, בנוסף לחופשה, מחלה ומילואים. deducts — מאיזו יתרה הסוג מוריד (או לא).
        CREATE TABLE IF NOT EXISTS absence_types (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL REFERENCES companies(id),
            name       TEXT NOT NULL,
            icon       TEXT NOT NULL DEFAULT '📌',
            deducts    TEXT CHECK (deducts IN ('vacation','sick')),
            active     INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL
        );

        -- היעדרות: טווח ימים, יום מלא / חצי / שעות ליום. עובד מבקש (by_member), מנהל רושם (by_person, מאושר מיד).
        -- type: vacation | sick | reserve | t<id> (סוג של החברה).
        CREATE TABLE IF NOT EXISTS absences (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id   INTEGER NOT NULL REFERENCES members(id),
            type        TEXT NOT NULL,
            from_date   TEXT NOT NULL,
            to_date     TEXT NOT NULL,
            part        TEXT NOT NULL DEFAULT 'full' CHECK (part IN ('full','half','hours')),
            minutes     INTEGER,
            note        TEXT,
            by_member   INTEGER,
            by_person   INTEGER,
            status      TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected','cancelled')),
            decided_by  INTEGER,
            decided_at  TEXT,
            decision_note TEXT,
            created_at  TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS absences_member ON absences (member_id, from_date);

        -- יתרת פתיחה (גרסה 3, שלב 2): היתרה בתחילת as_of, בימים. האחרונה (לפי תאריך) היא נקודת המוצא לחישוב;
        -- הקודמות נשמרות לתיעוד.
        CREATE TABLE IF NOT EXISTS absence_balances (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id  INTEGER NOT NULL REFERENCES members(id),
            kind       TEXT NOT NULL CHECK (kind IN ('vacation','sick')),
            as_of      TEXT NOT NULL,
            days       REAL NOT NULL,
            note       TEXT,
            by_person  INTEGER,
            created_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS absence_balances_member ON absence_balances (member_id, kind, as_of);

        -- כל שינוי: מי, מה, לפני ואחרי.
        CREATE TABLE IF NOT EXISTS audit (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER,
            actor      INTEGER,
            action     TEXT NOT NULL,
            entity     TEXT,
            before     TEXT,
            after      TEXT,
            at         TEXT NOT NULL
        );
    ");
    // עמודות שנוספו אחרי שהטבלה כבר קיימת בשרת — ALTER, פעם אחת.
    addColumn($pdo, 'members', 'religion', "TEXT CHECK (religion IN ('jewish','muslim','christian','druze','other'))");
    addColumn($pdo, 'members', 'rest_day', 'INTEGER CHECK (rest_day BETWEEN 0 AND 6)');
}

function addColumn(PDO $pdo, string $table, string $column, string $type): void {
    foreach ($pdo->query("PRAGMA table_info($table)") as $c) if ($c['name'] === $column) return;
    $pdo->exec("ALTER TABLE $table ADD COLUMN $column $type");
}

function audit(?int $companyId, ?int $actor, string $action, string $entity, ?array $before, ?array $after): void {
    db()->prepare('INSERT INTO audit (company_id, actor, action, entity, before, after, at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$companyId, $actor, $action, $entity,
                   $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
                   $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE), nowIso()]);
}
