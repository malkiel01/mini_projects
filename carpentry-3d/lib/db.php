<?php
/**
 * שכבת האחסון — SQLite דרך PDO.
 *
 * אותה בחירה כמו ב-recipes-app וב-claude-tasks: אין פרטי התחברות לנהל,
 * והקובץ נוסע עם התיקייה. המיגרציות הן CREATE TABLE IF NOT EXISTS בכוונה:
 * פריסה על מסד קיים אינה נופלת ואינה דורסת, ואין שלב התקנה נפרד.
 *
 * data/carpentry.sqlite ו-data/media/ אינם בגיט, ו-deploy.yml מחריג אותם
 * — ראו README. בלי ההחרגה פריסה הייתה דורסת את הפרויקטים.
 */

declare(strict_types=1);

require_once __DIR__ . '/errors.php';

// ניתן לדריסה לפני הטעינה — כך הבדיקות רצות על מסד זמני.
if (!defined('DB_FILE'))   define('DB_FILE',   __DIR__ . '/../data/carpentry.sqlite');
if (!defined('MEDIA_DIR')) define('MEDIA_DIR', __DIR__ . '/../data/media');

/** חתימת זמן אחידה. ISO-8601 ב-UTC — נשמר כטקסט, ומסתדר לקסיקוגרפית. */
function nowIso(): string {
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
        CREATE TABLE IF NOT EXISTS users (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            email         TEXT    NOT NULL UNIQUE,
            name          TEXT    NOT NULL,
            role          TEXT    NOT NULL DEFAULT 'carpenter'
                          CHECK (role IN ('admin','carpenter','viewer')),
            password_hash TEXT    NOT NULL,
            blocked       INTEGER NOT NULL DEFAULT 0,
            created_at    TEXT    NOT NULL
        );

        -- סוג מוצר = תבנית בקוד + שם + ברירות מחדל משלו (JSON של ערכים).
        CREATE TABLE IF NOT EXISTS product_types (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            template_key TEXT    NOT NULL,
            name         TEXT    NOT NULL,
            description  TEXT    NOT NULL DEFAULT '',
            defaults     TEXT    NOT NULL DEFAULT '{}',
            active       INTEGER NOT NULL DEFAULT 1,
            created_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at   TEXT    NOT NULL,
            updated_at   TEXT    NOT NULL
        );

        -- ספריית החומרים: רק מה ששונה מהזריעה שבקוד (diff), שורה לחומר.
        CREATE TABLE IF NOT EXISTS materials (
            id         TEXT PRIMARY KEY,
            data       TEXT NOT NULL,
            image_mm   INTEGER,
            has_image  INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL
        );

        -- תעריפי הנגר: labor_hour, markup, או material:<id> (מחיר שדורס).
        CREATE TABLE IF NOT EXISTS rates (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            key     TEXT    NOT NULL,
            value   REAL    NOT NULL,
            PRIMARY KEY (user_id, key)
        );

        CREATE TABLE IF NOT EXISTS projects (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            product_type_id INTEGER REFERENCES product_types(id) ON DELETE SET NULL,
            template_key    TEXT    NOT NULL,
            name            TEXT    NOT NULL,
            client          TEXT    NOT NULL DEFAULT '',
            values_json     TEXT    NOT NULL DEFAULT '{}',
            status          TEXT    NOT NULL DEFAULT 'draft'
                            CHECK (status IN ('draft','quoted','approved','done')),
            share_token     TEXT    UNIQUE,
            created_at      TEXT    NOT NULL,
            updated_at      TEXT    NOT NULL
        );
        CREATE INDEX IF NOT EXISTS projects_owner ON projects(owner_id, updated_at);

        -- לקוחות: רשומה, לא רק שם. משותפת לכל הנגרים.
        CREATE TABLE IF NOT EXISTS clients (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT    NOT NULL,
            phone      TEXT    NOT NULL DEFAULT '',
            email      TEXT    NOT NULL DEFAULT '',
            address    TEXT    NOT NULL DEFAULT '',
            notes      TEXT    NOT NULL DEFAULT '',
            created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at TEXT    NOT NULL,
            updated_at TEXT    NOT NULL
        );

        -- הרכבה: כמה פרויקטים של לקוח יחד, כל אחד עם מיקום וסיבוב (items_json).
        CREATE TABLE IF NOT EXISTS assemblies (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            client_id   INTEGER REFERENCES clients(id) ON DELETE SET NULL,
            name        TEXT    NOT NULL,
            items_json  TEXT    NOT NULL DEFAULT '[]',
            joined      INTEGER NOT NULL DEFAULT 0,
            share_token TEXT    UNIQUE,
            created_at  TEXT    NOT NULL,
            updated_at  TEXT    NOT NULL
        );

        -- מעבדת המוצרים: התאמות של המנהל מעל התבניות שבקוד — לכל תבנית (key = מפתח
        -- התבנית) פרמטרים ושעות עבודה, ו-'_limits' לגבולות הכלליים. data = JSON.
        CREATE TABLE IF NOT EXISTS template_rules (
            key        TEXT PRIMARY KEY,
            data       TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );
        -- מתכונים: מוצרים שהמנהל הגדיר במעבדה (רכיבים ונוסחאות), key = 'r-…' —
        -- נרשמים בדפדפן כתבניות לכל דבר. data = המתכון כולו (JSON).
        CREATE TABLE IF NOT EXISTS recipes (
            key        TEXT PRIMARY KEY,
            data       TEXT NOT NULL,
            active     INTEGER NOT NULL DEFAULT 1,
            updated_at TEXT NOT NULL
        );
    ");

    // עמודה שנוספה אחרי שהטבלה כבר הייתה בשרת: מוסיפים רק אם חסרה.
    $cols = array_column($pdo->query('PRAGMA table_info(projects)')->fetchAll(), 'name');
    if (!in_array('client_id', $cols, true)) {
        $pdo->exec('ALTER TABLE projects ADD COLUMN client_id INTEGER REFERENCES clients(id) ON DELETE SET NULL');
    }
    // תוקף חשבון (תאריכים YYYY-MM-DD, ריק = בלי הגבלה) וכניסה אחרונה — נוספו אחרי שהטבלה הייתה בשרת.
    $ucols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
    foreach (['valid_from', 'valid_until', 'last_login_at'] as $c) {
        if (!in_array($c, $ucols, true)) $pdo->exec("ALTER TABLE users ADD COLUMN $c TEXT");
    }
    // הסבה: פרויקטים ישנים עם שם לקוח כטקסט מקבלים רשומת לקוח (אותו שם = אותו לקוח).
    $orphans = $pdo->query("SELECT id, owner_id, client FROM projects WHERE client_id IS NULL AND client <> ''")->fetchAll();
    foreach ($orphans as $o) {
        $st = $pdo->prepare('SELECT id FROM clients WHERE name = ? COLLATE NOCASE');
        $st->execute([$o['client']]);
        $cid = $st->fetchColumn();
        if (!$cid) {
            $now = nowIso();
            $pdo->prepare('INSERT INTO clients (name, created_by, created_at, updated_at) VALUES (?,?,?,?)')->execute([$o['client'], $o['owner_id'], $now, $now]);
            $cid = $pdo->lastInsertId();
        }
        $pdo->prepare('UPDATE projects SET client_id = ? WHERE id = ?')->execute([(int) $cid, $o['id']]);
    }
}

/** JSON שמור → מערך; טקסט שבור → מערך ריק, לא קריסה. */
function jsonArr(?string $s): array {
    if ($s === null || $s === '') return [];
    $v = json_decode($s, true);
    return is_array($v) ? $v : [];
}

function jsonStr(array $a): string {
    return json_encode($a, JSON_UNESCAPED_UNICODE) ?: '{}';
}
