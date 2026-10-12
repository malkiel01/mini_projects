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

require_once __DIR__ . '/log.php';

if (!defined('PM_DB')) define('PM_DB', __DIR__ . '/../data/pm.sqlite');

/** תחומי ברירת מחדל — נזרעים פעם אחת, ומאז המפתח עורך אותם. */
const PM_DEFAULT_TRADES = [
    'מודד מוסמך', 'אדריכלות', 'עיצוב פנים', 'הנדסה', 'פיקוח', 'קבלן ראשי', 'שלד', 'הריסה',
    'חשמל', 'אינסטלציה', 'גז', 'איטום', 'טיח', 'ריצוף', 'אלומיניום', 'נגרות',
    'צבע', 'מיזוג אוויר', 'גבס', 'מסגרות', 'ניקיון',
];

/**
 * כלל קבוע: **מודד מוסמך תמיד לפני אדריכלות** (2026-10-12). שלב בתחום אדריכלות
 * בלי שלב מדידה לפניו — מקבל אחד לפניו, בתבניות ובפרויקטים (lib/projects.php).
 */
const PM_TRADE_SURVEYOR = 'מודד מוסמך';
const PM_TRADE_ARCHITECT = 'אדריכלות';
const PM_SURVEY_STAGE = 'מדידה';

/** תבניות שלבים ראשונות — [שם, [[שלב, תחום], …]]. התחום לפי שם, כי המפתח עשוי לשנות מזהים. */
const PM_DEFAULT_TEMPLATES = [
    ['שיפוץ דירה', [
        ['מדידה', 'מודד מוסמך'], ['תכנון ועיצוב', 'אדריכלות'], ['הריסה ופינוי', 'הריסה'], ['אינסטלציה', 'אינסטלציה'],
        ['חשמל', 'חשמל'], ['מיזוג אוויר', 'מיזוג אוויר'], ['גבס', 'גבס'], ['איטום', 'איטום'],
        ['טיח', 'טיח'], ['ריצוף וחיפוי', 'ריצוף'], ['אלומיניום', 'אלומיניום'], ['נגרות', 'נגרות'],
        ['צבע', 'צבע'], ['ניקיון ומסירה', 'ניקיון'],
    ]],
    ['בנייה', [
        ['מדידה', 'מודד מוסמך'], ['תכנון ואדריכלות', 'אדריכלות'], ['הנדסה וקונסטרוקציה', 'הנדסה'], ['היתרים', ''],
        ['עבודות עפר ויסודות', 'שלד'], ['שלד', 'שלד'], ['אינסטלציה', 'אינסטלציה'], ['חשמל', 'חשמל'],
        ['גז', 'גז'], ['איטום', 'איטום'], ['טיח', 'טיח'], ['ריצוף וחיפוי', 'ריצוף'],
        ['אלומיניום', 'אלומיניום'], ['מסגרות', 'מסגרות'], ['נגרות', 'נגרות'], ['צבע', 'צבע'],
        ['פיקוח ומסירה', 'פיקוח'],
    ]],
    ['תוספת בנייה', [
        ['מדידה', 'מודד מוסמך'], ['תכנון והיתר', 'אדריכלות'], ['קונסטרוקציה', 'הנדסה'], ['שלד', 'שלד'], ['חשמל', 'חשמל'],
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
        -- קישורי איפוס סיסמה חד-פעמיים (lib/auth.php). נשמר רק ה-hash.
        CREATE TABLE IF NOT EXISTS password_resets (
            token_hash TEXT PRIMARY KEY,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            created_by TEXT NOT NULL,             -- self · developer
            created_at TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            used_at    TEXT
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
        -- (owner_id), ורק הוא רואה אותו (גם לא המפתח).
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
        -- שלב 3: קבצים ותיקיות (lib/files.php).
        -- תיקייה: kind = inbox · client · lawyer · contact (ספק / איש צוות) · plans ·
        -- photos · general · custom. המערכתיות נוצרות לבד; custom — בידי המשתמש.
        CREATE TABLE IF NOT EXISTS folders (
            id         INTEGER PRIMARY KEY,
            project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            parent_id  INTEGER REFERENCES folders(id) ON DELETE CASCADE,
            kind       TEXT NOT NULL DEFAULT 'custom',
            contact_id INTEGER REFERENCES contacts(id) ON DELETE SET NULL,
            name       TEXT NOT NULL,
            sort       INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS folders_project ON folders (project_id, parent_id);
        -- קובץ: התוכן בדיסק (data/files/<project>/<storage>), כאן — הפרטים.
        -- type: quote · proof · contract · plan · photo · video · invoice · other
        CREATE TABLE IF NOT EXISTS files (
            id          INTEGER PRIMARY KEY,
            project_id  INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            name        TEXT NOT NULL,
            mime        TEXT NOT NULL DEFAULT '',
            size        INTEGER NOT NULL DEFAULT 0,
            type        TEXT NOT NULL DEFAULT 'other',
            storage     TEXT NOT NULL,
            thumb       TEXT,
            visible     INTEGER NOT NULL DEFAULT 0,   -- גלוי ללקוח / עו"ד / ספק בפורטל
            note        TEXT NOT NULL DEFAULT '',
            uploaded_by INTEGER NOT NULL,
            created_at  TEXT NOT NULL,
            deleted_at  TEXT
        );
        CREATE INDEX IF NOT EXISTS files_project ON files (project_id, deleted_at, created_at);
        -- קובץ בתיקייה. is_home = התיקייה שלו; השאר — קיצורי דרך (לא העתק).
        CREATE TABLE IF NOT EXISTS file_links (
            file_id   INTEGER NOT NULL REFERENCES files(id) ON DELETE CASCADE,
            folder_id INTEGER NOT NULL REFERENCES folders(id) ON DELETE CASCADE,
            is_home   INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (file_id, folder_id)
        );
        CREATE INDEX IF NOT EXISTS file_links_folder ON file_links (folder_id);
        -- העלאה בחלקים: נוצרת ב-upload-start, נמחקת כשהושלמה או אחרי יום.
        CREATE TABLE IF NOT EXISTS uploads (
            id         TEXT PRIMARY KEY,
            project_id INTEGER NOT NULL,
            folder_id  INTEGER NOT NULL,
            user_id    INTEGER NOT NULL,
            name       TEXT NOT NULL,
            mime       TEXT NOT NULL,
            size       INTEGER NOT NULL,
            received   INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL
        );
        -- תבניות שלבים — המפתח עורך. stages_json: [{"name": "...", "trade": "..."}]
        -- שלב 5: הצעות מחיר (lib/quotes.php). סכום באגורות, כולל מע"מ. לא נמחקות —
        -- ביטול הוא status = 'cancelled'. version עולה בכל שינוי מהותי (סכום, קובץ,
        -- תנאים, תוקף), ואישור ישן לא עובר לגרסה חדשה.
        CREATE TABLE IF NOT EXISTS quotes (
            id          INTEGER PRIMARY KEY,
            project_id  INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            stage_id    INTEGER NOT NULL REFERENCES stages(id),
            supplier_id INTEGER NOT NULL REFERENCES contacts(id),
            amount      INTEGER NOT NULL,
            valid_until TEXT NOT NULL DEFAULT '',
            terms       TEXT NOT NULL DEFAULT '',
            notes       TEXT NOT NULL DEFAULT '',
            file_id     INTEGER REFERENCES files(id),
            recommended INTEGER NOT NULL DEFAULT 0,
            status      TEXT NOT NULL DEFAULT 'open',
            version     INTEGER NOT NULL DEFAULT 1,
            created_by  INTEGER NOT NULL,
            created_at  TEXT NOT NULL,
            updated_at  TEXT NOT NULL,
            sent_at     TEXT
        );
        CREATE INDEX IF NOT EXISTS quotes_stage ON quotes (stage_id);
        CREATE INDEX IF NOT EXISTS quotes_project ON quotes (project_id);
        -- אישור או דחייה של הצעה — בידי הלקוח או העו"ד. נשמר לתמיד, עם צילום מצב של
        -- ההצעה כפי שהייתה. method: manual (המנהל רשם, למשל "אישר בטלפון") · click ·
        -- signature (פורטל, שלב 6).
        CREATE TABLE IF NOT EXISTS approvals (
            id            INTEGER PRIMARY KEY,
            project_id    INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            stage_id      INTEGER NOT NULL,
            quote_id      INTEGER NOT NULL REFERENCES quotes(id),
            quote_version INTEGER NOT NULL,
            role          TEXT NOT NULL CHECK (role IN ('client','lawyer')),
            decision      TEXT NOT NULL CHECK (decision IN ('approved','rejected')),
            method        TEXT NOT NULL DEFAULT 'manual',
            by_name       TEXT NOT NULL DEFAULT '',
            recorded_by   INTEGER,
            note          TEXT NOT NULL DEFAULT '',
            snapshot_json TEXT NOT NULL,
            ip            TEXT NOT NULL DEFAULT '',
            ua            TEXT NOT NULL DEFAULT '',
            created_at    TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS approvals_quote ON approvals (quote_id);
        -- שלב 6: קישור אישי לפורטל — ללקוח או לעו"ד של פרויקט. האסימון נגזר מ-nonce
        -- וסוד השרת (data/config.json), כך שהמנהל יכול לקבל את אותו קישור שוב; במסד
        -- רק ה-hash. "קישור חדש" = nonce חדש, והישן מפסיק לעבוד.
        CREATE TABLE IF NOT EXISTS portal_links (
            id           INTEGER PRIMARY KEY,
            project_id   INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            contact_id   INTEGER NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
            role         TEXT NOT NULL CHECK (role IN ('client','lawyer','supplier')),
            nonce        TEXT NOT NULL,
            token_hash   TEXT NOT NULL UNIQUE,
            created_by   INTEGER NOT NULL,
            created_at   TEXT NOT NULL,
            revoked_at   TEXT,
            last_seen_at TEXT,
            views        INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS portal_links_project ON portal_links (project_id, contact_id);
        -- שלב 7: אבני דרך לתשלום (lib/payments.php). האפליקציה רק מתעדת — הלקוח משלם
        -- ישירות לספק, בעצמו או דרך העו"ד. סכומים באגורות, כולל מע"מ.
        -- status: planned · requested · paid (דווח ששולם) · partial · received · cancelled.
        -- אישור העו"ד — בעמודות lawyer_*; בלעדיו (כשנדרש) אין "שילמתי".
        -- payer / payee: מי משלם ולמי — מוכן לתשלום דרך מנהל הפרויקט בהמשך.
        CREATE TABLE IF NOT EXISTS payments (
            id                INTEGER PRIMARY KEY,
            project_id        INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            stage_id          INTEGER REFERENCES stages(id),
            supplier_id       INTEGER NOT NULL REFERENCES contacts(id),
            quote_id          INTEGER REFERENCES quotes(id),
            title             TEXT NOT NULL,
            amount            INTEGER NOT NULL,
            percent           REAL,
            due_date          TEXT NOT NULL DEFAULT '',
            payer             TEXT NOT NULL DEFAULT 'client' CHECK (payer IN ('client','lawyer')),
            payee             TEXT NOT NULL DEFAULT 'supplier',
            needs_lawyer      INTEGER NOT NULL DEFAULT 0,
            lawyer_approved_at TEXT,
            lawyer_by         TEXT NOT NULL DEFAULT '',
            lawyer_method     TEXT NOT NULL DEFAULT '',
            lawyer_note       TEXT NOT NULL DEFAULT '',
            status            TEXT NOT NULL DEFAULT 'planned',
            notes             TEXT NOT NULL DEFAULT '',
            requested_at      TEXT,
            created_by        INTEGER NOT NULL,
            created_at        TEXT NOT NULL,
            updated_at        TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS payments_project ON payments (project_id);
        -- אסמכתא: "שילמתי" — מהפורטל (לקוח / עו"ד) או בידי המנהל. מנהל הפרויקט מאשר
        -- שהכסף התקבל (received_amount — גם חלקי), או מסמן "לא התקבל".
        CREATE TABLE IF NOT EXISTS payment_proofs (
            id              INTEGER PRIMARY KEY,
            payment_id      INTEGER NOT NULL REFERENCES payments(id),
            amount          INTEGER NOT NULL,
            paid_on         TEXT NOT NULL,
            method          TEXT NOT NULL DEFAULT 'transfer',
            file_id         INTEGER REFERENCES files(id),
            note            TEXT NOT NULL DEFAULT '',
            by_role         TEXT NOT NULL,
            by_name         TEXT NOT NULL DEFAULT '',
            portal_id       INTEGER,
            ip              TEXT NOT NULL DEFAULT '',
            status          TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','received','rejected')),
            received_amount INTEGER,
            reviewed_at     TEXT,
            reviewed_by     INTEGER,
            review_note     TEXT NOT NULL DEFAULT '',
            created_at      TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS payment_proofs_payment ON payment_proofs (payment_id);
        -- שלב 8: דיווח מהשטח (lib/reports.php). kind: update (עדכון) · done (השלב בוצע —
        -- חובה לפחות תמונה אחת). visible — הלקוח והעו"ד רואים בפורטל (ברירת מחדל: כן).
        -- הקבצים עצמם ב-files, בתיקייה "תמונות מהשטח / <שלב>". מחיקה = deleted_at.
        CREATE TABLE IF NOT EXISTS reports (
            id          INTEGER PRIMARY KEY,
            project_id  INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            stage_id    INTEGER REFERENCES stages(id) ON DELETE SET NULL,
            kind        TEXT NOT NULL DEFAULT 'update' CHECK (kind IN ('update','done')),
            text        TEXT NOT NULL DEFAULT '',
            visible     INTEGER NOT NULL DEFAULT 1,
            created_by  INTEGER NOT NULL,
            created_at  TEXT NOT NULL,
            deleted_at  TEXT
        );
        CREATE INDEX IF NOT EXISTS reports_project ON reports (project_id, created_at);
        CREATE TABLE IF NOT EXISTS report_files (
            report_id INTEGER NOT NULL REFERENCES reports(id) ON DELETE CASCADE,
            file_id   INTEGER NOT NULL REFERENCES files(id),
            sort      INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (report_id, file_id)
        );
        -- שלב 9: בקשת הצעת מחיר מספק לשלב — מה שהספק רואה בקישור שלו כ"מבקשים ממך הצעה".
        CREATE TABLE IF NOT EXISTS quote_invites (
            project_id  INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            stage_id    INTEGER NOT NULL REFERENCES stages(id) ON DELETE CASCADE,
            supplier_id INTEGER NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
            created_at  TEXT NOT NULL,
            PRIMARY KEY (stage_id, supplier_id)
        );
        -- שלב 10: פגישות וביקורים ביומן (lib/calendar.php). date מקומי (Y-m-d), time HH:MM או ''.
        CREATE TABLE IF NOT EXISTS appointments (
            id           INTEGER PRIMARY KEY,
            project_id   INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            title        TEXT NOT NULL,
            date         TEXT NOT NULL,
            time         TEXT NOT NULL DEFAULT '',
            duration     INTEGER NOT NULL DEFAULT 60,
            location     TEXT NOT NULL DEFAULT '',
            contact_id   INTEGER REFERENCES contacts(id) ON DELETE SET NULL,
            notes        TEXT NOT NULL DEFAULT '',
            created_by   INTEGER NOT NULL,
            created_at   TEXT NOT NULL,
            cancelled_at TEXT
        );
        CREATE INDEX IF NOT EXISTS appointments_date ON appointments (date);
        -- שלב 11: הודעות (lib/messages.php). כל הודעה ללקוח / עו"ד / ספק — אוטומטית
        -- (מייל מהשרת) או ידנית (וואטסאפ / SMS / מייל בלחיצה). status: sent · failed ·
        -- opened (נפתח בוואטסאפ / SMS / מייל במכשיר של המנהל — לא ודאי שנשלח).
        CREATE TABLE IF NOT EXISTS messages (
            id         INTEGER PRIMARY KEY,
            project_id INTEGER REFERENCES projects(id) ON DELETE CASCADE,
            contact_id INTEGER REFERENCES contacts(id) ON DELETE SET NULL,
            channel    TEXT NOT NULL,
            key        TEXT NOT NULL DEFAULT '',
            recipient  TEXT NOT NULL DEFAULT '',
            subject    TEXT NOT NULL DEFAULT '',
            body       TEXT NOT NULL DEFAULT '',
            status     TEXT NOT NULL,
            auto       INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS messages_project ON messages (project_id, id);
        -- נוסחי ההודעות של כל מנהל (ברירת המחדל — בקוד). {שם} {פרויקט} {קישור} …
        CREATE TABLE IF NOT EXISTS message_templates (
            owner_id INTEGER NOT NULL,
            key      TEXT NOT NULL,
            subject  TEXT NOT NULL,
            body     TEXT NOT NULL,
            PRIMARY KEY (owner_id, key)
        );
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

    // נעילה לפי רשת בוטלה (2026-10-12) — משחררים נעילות כאלה שנשארו בשרת
    $pdo->exec("DELETE FROM login_attempts WHERE key LIKE 'ip:%'");

    // עמודות שנוספו אחרי שהטבלה כבר קיימת בשרת
    pm_add_column($pdo, 'users', 'email', "TEXT NOT NULL DEFAULT ''");
    pm_add_column($pdo, 'users', 'notes', "TEXT NOT NULL DEFAULT ''");
    // שלב 6: אישור מהפורטל — מאיזה קישור, והחתימה (תמונה) כשנדרשה
    pm_add_column($pdo, 'approvals', 'portal_id', 'INTEGER');
    pm_add_column($pdo, 'approvals', 'signature', "TEXT NOT NULL DEFAULT ''");
    // שלב 9: ספק בקישור — תפקיד supplier. ב-SQLite אי אפשר לשנות CHECK, ולכן הטבלה
    // נבנית מחדש פעם אחת (הקישורים הקיימים נשמרים כמו שהם).
    $sql = (string)$pdo->query("SELECT sql FROM sqlite_master WHERE name = 'portal_links'")->fetchColumn();
    if ($sql !== '' && !str_contains($sql, "'supplier'")) {
        $pdo->exec('BEGIN');
        $pdo->exec('ALTER TABLE portal_links RENAME TO portal_links_old');
        $pdo->exec(str_replace(["('client','lawyer')", 'portal_links_old'], ["('client','lawyer','supplier')", 'portal_links'], $sql));
        $pdo->exec('INSERT INTO portal_links SELECT * FROM portal_links_old');
        $pdo->exec('DROP TABLE portal_links_old');
        $pdo->exec('CREATE INDEX IF NOT EXISTS portal_links_project ON portal_links (project_id, contact_id)');
        $pdo->exec('COMMIT');
    }
    // דרישת תשלום שהספק ביקש בקישור (המנהל מאשר ושולח), וקובץ מצורף (חשבון / פירוט)
    pm_add_column($pdo, 'payments', 'supplier_request', 'INTEGER NOT NULL DEFAULT 0');
    pm_add_column($pdo, 'payments', 'file_id', 'INTEGER');
    // שלב 10: סנכרון היומן לטלפון (nonce לאסימון), ומייל תזכורות יומי
    pm_add_column($pdo, 'users', 'cal_nonce', "TEXT NOT NULL DEFAULT ''");
    pm_add_column($pdo, 'users', 'digest', 'INTEGER NOT NULL DEFAULT 1');
    pm_add_column($pdo, 'users', 'digest_sent', "TEXT NOT NULL DEFAULT ''");

    if (!$pdo->query("SELECT 1 FROM settings WHERE key = 'trades_seeded'")->fetchColumn()) {
        $ins = $pdo->prepare('INSERT OR IGNORE INTO trades (name, sort) VALUES (?, ?)');
        foreach (PM_DEFAULT_TRADES as $i => $name) $ins->execute([$name, ($i + 1) * 10]);
        $pdo->exec("INSERT INTO settings (key, value) VALUES ('trades_seeded', '1')");
    }
    // מודד מוסמך — נוסף אחרי שהתחומים כבר נזרעו בשרת. ראשון ברשימה, לפני אדריכלות.
    if (!$pdo->query("SELECT 1 FROM settings WHERE key = 'trade_surveyor'")->fetchColumn()) {
        $pdo->prepare('INSERT OR IGNORE INTO trades (name, sort) VALUES (?, 5)')->execute([PM_TRADE_SURVEYOR]);
        $pdo->exec("INSERT INTO settings (key, value) VALUES ('trade_surveyor', '1')");
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

function pm_add_column(PDO $pdo, string $table, string $col, string $def): void
{
    $cols = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
    if (!in_array($col, $cols, true)) $pdo->exec("ALTER TABLE $table ADD COLUMN $col $def");
}

/** רישום ביומן הביקורת. $actor — "user:<id>" או "portal:<contact>" בהמשך. */
function pm_audit(string $actor, string $action, string $refKind, ?int $refId, array $data = []): void
{
    pm_db()->prepare('INSERT INTO audit_log (at, actor, action, ref_kind, ref_id, data_json) VALUES (?,?,?,?,?,?)')
        ->execute([pm_now(), $actor, $action, $refKind, $refId, json_encode($data, JSON_UNESCAPED_UNICODE)]);
    pm_log('info', 'data', $action, ['data' => $data + ['ref' => "$refKind:$refId"]]);
}
