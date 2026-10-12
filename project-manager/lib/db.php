<?php
/**
 * המסד של ניהול הפרויקטים — SQLite ב-data/pm.sqlite.
 *
 * הקובץ אינו בגיט, ו-deploy.yml מחריג אותו (`project-manager/data/*.sqlite*`).
 * המיגרציות הן CREATE TABLE IF NOT EXISTS: פריסה על מסד קיים לא דורסת דבר.
 * עמודה חדשה בטבלה קיימת — דרך pm_add_column, לא בשינוי ה-CREATE.
 *
 * שמות הפונקציות מתחילים ב-pm_: הקוד רץ אחרי gate-prepend של הפלטפורמה,
 * וכלים אחרים בריפו כבר מגדירים db().
 */

declare(strict_types=1);

if (!defined('PM_DB')) define('PM_DB', __DIR__ . '/../data/pm.sqlite');

/** תחומי ברירת מחדל — נזרעים פעם אחת, ומאז המפתח עורך אותם. */
const PM_DEFAULT_TRADES = [
    'אדריכלות', 'עיצוב פנים', 'הנדסה', 'פיקוח', 'קבלן ראשי', 'שלד', 'הריסה',
    'חשמל', 'אינסטלציה', 'גז', 'איטום', 'טיח', 'ריצוף', 'אלומיניום', 'נגרות',
    'צבע', 'מיזוג אוויר', 'גבס', 'מסגרות', 'ניקיון',
];

/** תבניות שלבים ראשונות — [שם, [[שלב, תחום], …]]. התחום לפי שם, כי המפתח עשוי לשנות מזהים. */
const PM_DEFAULT_TEMPLATES = [
    ['שיפוץ דירה', [
        ['תכנון ועיצוב', 'אדריכלות'], ['הריסה ופינוי', 'הריסה'], ['אינסטלציה', 'אינסטלציה'],
        ['חשמל', 'חשמל'], ['מיזוג אוויר', 'מיזוג אוויר'], ['גבס', 'גבס'], ['איטום', 'איטום'],
        ['טיח', 'טיח'], ['ריצוף וחיפוי', 'ריצוף'], ['אלומיניום', 'אלומיניום'], ['נגרות', 'נגרות'],
        ['צבע', 'צבע'], ['ניקיון ומסירה', 'ניקיון'],
    ]],
    ['בנייה', [
        ['תכנון ואדריכלות', 'אדריכלות'], ['הנדסה וקונסטרוקציה', 'הנדסה'], ['היתרים', ''],
        ['עבודות עפר ויסודות', 'שלד'], ['שלד', 'שלד'], ['אינסטלציה', 'אינסטלציה'], ['חשמל', 'חשמל'],
        ['גז', 'גז'], ['איטום', 'איטום'], ['טיח', 'טיח'], ['ריצוף וחיפוי', 'ריצוף'],
        ['אלומיניום', 'אלומיניום'], ['מסגרות', 'מסגרות'], ['נגרות', 'נגרות'], ['צבע', 'צבע'],
        ['פיקוח ומסירה', 'פיקוח'],
    ]],
    ['תוספת בנייה', [
        ['תכנון והיתר', 'אדריכלות'], ['קונסטרוקציה', 'הנדסה'], ['שלד', 'שלד'], ['חשמל', 'חשמל'],
        ['אינסטלציה', 'אינסטלציה'], ['איטום', 'איטום'], ['טיח', 'טיח'], ['ריצוף', 'ריצוף'],
        ['אלומיניום', 'אלומיניום'], ['צבע', 'צבע'], ['מסירה', 'ניקיון'],
    ]],
];

/** שגיאה שמוצגת למשתמש, עם קוד HTTP. */
final class PmError extends RuntimeException {}

function pm_now(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function pm_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dir = dirname(PM_DB);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $pdo = new PDO('sqlite:' . PM_DB, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    pm_migrate($pdo);
    return $pdo;
}

function pm_migrate(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        -- משתמשי האפליקציה (lib/auth.php): המפתח ומנהלי הפרויקט.
        CREATE TABLE IF NOT EXISTS users (
            id            INTEGER PRIMARY KEY,
            username      TEXT NOT NULL UNIQUE COLLATE NOCASE,
            password_hash TEXT NOT NULL,
            display_name  TEXT NOT NULL,
            phone         TEXT NOT NULL DEFAULT '',
            role          TEXT NOT NULL DEFAULT 'manager' CHECK (role IN ('developer','manager')),
            status        TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','active','blocked')),
            created_at    TEXT NOT NULL,
            approved_at   TEXT,
            last_login_at TEXT
        );
        CREATE TABLE IF NOT EXISTS sessions (
            token_hash TEXT PRIMARY KEY,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            csrf       TEXT NOT NULL,
            created_at TEXT NOT NULL,
            expires_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS login_attempts (
            key          TEXT PRIMARY KEY,
            failures     INTEGER NOT NULL DEFAULT 0,
            locked_until TEXT
        );
        CREATE TABLE IF NOT EXISTS trades (
            id   INTEGER PRIMARY KEY,
            name TEXT NOT NULL UNIQUE,
            sort INTEGER NOT NULL DEFAULT 0
        );
        -- כל אנשי הקשר בטבלה אחת: לקוח, ספק, עו"ד. כל כרטיס שייך למנהל אחד
        -- (owner_id), ורק הוא — והמפתח — רואה אותו.
        CREATE TABLE IF NOT EXISTS contacts (
            id         INTEGER PRIMARY KEY,
            owner_id   INTEGER NOT NULL,
            kind       TEXT NOT NULL CHECK (kind IN ('client','supplier','lawyer')),
            name       TEXT NOT NULL,
            company    TEXT NOT NULL DEFAULT '',
            tax_id     TEXT NOT NULL DEFAULT '',
            phone      TEXT NOT NULL DEFAULT '',
            whatsapp   TEXT NOT NULL DEFAULT '',
            email      TEXT NOT NULL DEFAULT '',
            address    TEXT NOT NULL DEFAULT '',
            bank_json  TEXT NOT NULL DEFAULT '{}',
            rating     INTEGER NOT NULL DEFAULT 0 CHECK (rating BETWEEN 0 AND 5),
            notes      TEXT NOT NULL DEFAULT '',
            archived   INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS contacts_owner ON contacts (owner_id, kind, archived);
        CREATE TABLE IF NOT EXISTS contact_trades (
            contact_id INTEGER NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
            trade_id   INTEGER NOT NULL REFERENCES trades(id) ON DELETE CASCADE,
            PRIMARY KEY (contact_id, trade_id)
        );
        -- הגדרות הלקוח — קובע מנהל הפרויקט בעת הקמת הלקוח.
        CREATE TABLE IF NOT EXISTS client_settings (
            contact_id      INTEGER PRIMARY KEY REFERENCES contacts(id) ON DELETE CASCADE,
            lawyer_id       INTEGER REFERENCES contacts(id) ON DELETE SET NULL,
            approval_method TEXT NOT NULL DEFAULT 'click' CHECK (approval_method IN ('click','signature')),
            channels        TEXT NOT NULL DEFAULT 'email,whatsapp',
            default_payer   TEXT NOT NULL DEFAULT 'client' CHECK (default_payer IN ('client','lawyer'))
        );
        -- כל שינוי בעל משמעות נרשם כאן ולא נמחק.
        CREATE TABLE IF NOT EXISTS audit_log (
            id        INTEGER PRIMARY KEY,
            at        TEXT NOT NULL,
            actor     TEXT NOT NULL,
            action    TEXT NOT NULL,
            ref_kind  TEXT NOT NULL,
            ref_id    INTEGER,
            data_json TEXT NOT NULL DEFAULT '{}'
        );
        CREATE TABLE IF NOT EXISTS settings (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );

        -- שלב 2: פרויקטים. פרויקט הוא "צ'אט": last_activity_at ממיין את מסך הבית,
        -- ו-last_event הוא השורה שמוצגת מתחת לשם (כמו ההודעה האחרונה בוואטסאפ).
        CREATE TABLE IF NOT EXISTS projects (
            id               INTEGER PRIMARY KEY,
            owner_id         INTEGER NOT NULL,
            name             TEXT NOT NULL,
            address          TEXT NOT NULL DEFAULT '',
            kind             TEXT NOT NULL DEFAULT 'renovation'
                             CHECK (kind IN ('renovation','construction','addition','other')),
            client_id        INTEGER REFERENCES contacts(id),
            lawyer_id        INTEGER REFERENCES contacts(id),
            budget           INTEGER NOT NULL DEFAULT 0,        -- ₪ כולל מע"מ
            start_date       TEXT NOT NULL DEFAULT '',
            due_date         TEXT NOT NULL DEFAULT '',
            status           TEXT NOT NULL DEFAULT 'active'
                             CHECK (status IN ('active','paused','done','archived')),
            notes            TEXT NOT NULL DEFAULT '',
            created_at       TEXT NOT NULL,
            updated_at       TEXT NOT NULL,
            last_activity_at TEXT NOT NULL,
            last_event       TEXT NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS projects_owner ON projects (owner_id, status, last_activity_at);
        CREATE TABLE IF NOT EXISTS stages (
            id           INTEGER PRIMARY KEY,
            project_id   INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            sort         INTEGER NOT NULL DEFAULT 0,
            name         TEXT NOT NULL,
            trade_id     INTEGER REFERENCES trades(id) ON DELETE SET NULL,
            supplier_id  INTEGER REFERENCES contacts(id) ON DELETE SET NULL,
            status       TEXT NOT NULL DEFAULT 'planned',
            needs_lawyer INTEGER NOT NULL DEFAULT 0,
            plan_start   TEXT NOT NULL DEFAULT '',
            plan_end     TEXT NOT NULL DEFAULT '',
            done_at      TEXT,
            created_at   TEXT NOT NULL,
            updated_at   TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS stages_project ON stages (project_id, sort);
        -- אנשי צוות שאינם משויכים לשלב (אדריכלית, מפקח…). ספקי השלבים נוספים לבד.
        CREATE TABLE IF NOT EXISTS project_members (
            project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            contact_id INTEGER NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
            role       TEXT NOT NULL DEFAULT '',
            PRIMARY KEY (project_id, contact_id)
        );
        -- ציר הזמן של הפרויקט: כל מה שקרה בו, ממוין לפי זמן. לא נמחק.
        CREATE TABLE IF NOT EXISTS events (
            id         INTEGER PRIMARY KEY,
            project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            at         TEXT NOT NULL,
            actor_id   INTEGER NOT NULL,
            actor_name TEXT NOT NULL,
            kind       TEXT NOT NULL,
            text       TEXT NOT NULL,
            data_json  TEXT NOT NULL DEFAULT '{}'
        );
        CREATE INDEX IF NOT EXISTS events_project ON events (project_id, id);
        -- תבניות שלבים — המפתח עורך. stages_json: [{"name": "...", "trade": "..."}]
        CREATE TABLE IF NOT EXISTS stage_templates (
            id          INTEGER PRIMARY KEY,
            name        TEXT NOT NULL UNIQUE,
            stages_json TEXT NOT NULL DEFAULT '[]',
            sort        INTEGER NOT NULL DEFAULT 0
        );
        SQL);

    if (!$pdo->query("SELECT 1 FROM settings WHERE key = 'templates_seeded'")->fetchColumn()) {
        $ins = $pdo->prepare('INSERT OR IGNORE INTO stage_templates (name, stages_json, sort) VALUES (?, ?, ?)');
        foreach (PM_DEFAULT_TEMPLATES as $i => [$name, $stages]) {
            $list = array_map(fn($s) => ['name' => $s[0], 'trade' => $s[1]], $stages);
            $ins->execute([$name, json_encode($list, JSON_UNESCAPED_UNICODE), ($i + 1) * 10]);
        }
        $pdo->exec("INSERT INTO settings (key, value) VALUES ('templates_seeded', '1')");
    }

    if (!$pdo->query("SELECT 1 FROM settings WHERE key = 'trades_seeded'")->fetchColumn()) {
        $ins = $pdo->prepare('INSERT OR IGNORE INTO trades (name, sort) VALUES (?, ?)');
        foreach (PM_DEFAULT_TRADES as $i => $name) $ins->execute([$name, ($i + 1) * 10]);
        $pdo->exec("INSERT INTO settings (key, value) VALUES ('trades_seeded', '1')");
    }
}

function pm_setting(string $key, string $default = ''): string
{
    $st = pm_db()->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string)$v;
}

function pm_setting_set(string $key, string $value): void
{
    pm_db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
}

/** רישום ביומן הביקורת. $actor — "user:<id>" או "portal:<contact>" בהמשך. */
function pm_audit(string $actor, string $action, string $refKind, ?int $refId, array $data = []): void
{
    pm_db()->prepare('INSERT INTO audit_log (at, actor, action, ref_kind, ref_id, data_json) VALUES (?,?,?,?,?,?)')
        ->execute([pm_now(), $actor, $action, $refKind, $refId, json_encode($data, JSON_UNESCAPED_UNICODE)]);
}
