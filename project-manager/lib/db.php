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
        -- מנהלי פרויקט: משתמשי הפלטפורמה שנכנסו לאפליקציה. המזהה הוא ה-id
        -- שלהם בפלטפורמה; השם מתעדכן בכל כניסה.
        CREATE TABLE IF NOT EXISTS managers (
            platform_user_id INTEGER PRIMARY KEY,
            display_name     TEXT NOT NULL,
            phone            TEXT NOT NULL DEFAULT '',
            email            TEXT NOT NULL DEFAULT '',
            created_at       TEXT NOT NULL,
            last_seen_at     TEXT NOT NULL
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
        SQL);

    if (!$pdo->query("SELECT 1 FROM settings WHERE key = 'trades_seeded'")->fetchColumn()) {
        $ins = $pdo->prepare('INSERT OR IGNORE INTO trades (name, sort) VALUES (?, ?)');
        foreach (PM_DEFAULT_TRADES as $i => $name) $ins->execute([$name, ($i + 1) * 10]);
        $pdo->exec("INSERT INTO settings (key, value) VALUES ('trades_seeded', '1')");
    }
}

/** רישום ביומן הביקורת. $actor — "user:<id>" או "portal:<contact>" בהמשך. */
function pm_audit(string $actor, string $action, string $refKind, ?int $refId, array $data = []): void
{
    pm_db()->prepare('INSERT INTO audit_log (at, actor, action, ref_kind, ref_id, data_json) VALUES (?,?,?,?,?,?)')
        ->execute([pm_now(), $actor, $action, $refKind, $refId, json_encode($data, JSON_UNESCAPED_UNICODE)]);
}
