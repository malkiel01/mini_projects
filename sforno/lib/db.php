<?php
/**
 * שכבת המסד של ספורנו (אפיון 01 §4, 00 §3.1, הכרעה ש2).
 *
 * ברירת המחדל: SQLite אחד ב-data/sforno.sqlite (WAL, מפתחות זרים). מעבר ל-MySQL = החלפת חיבור בלבד: קובץ
 * data/db.json עם {"dsn": "mysql:host=…;dbname=…;charset=utf8mb4", "user": "…", "pass": "…"}. לכן:
 *   - השאילתות מחוץ לקובץ הזה כתובות ב-SQL שעובד בשניהם (בלי INSERT OR …, בלי ON CONFLICT, בלי PRAGMA).
 *   - ההבדלים (סוגי עמודות ב-DDL, פתיחת טרנזקציה נועלת, בדיקות המסד) — רק כאן ובמיגרציות, דרך sf_driver().
 *
 * מיגרציות: lib/migrations/NNN-name.php, כל אחת מחזירה function (PDO $db, string $driver): void. רצות לבד בבקשה
 * הראשונה, לפי הסדר, ונרשמות ב-meta.schema_version. מיגרציה שנכנסה ל-main לא משתנה — שינוי = קובץ חדש.
 * **אין מיגרציה שמוחקת נתוני משתמשים.**
 *
 * בבדיקות (CLI): SF_DATA_DIR — תיקייה זמנית, SF_DB_PATH — ':memory:' או קובץ זמני.
 */

declare(strict_types=1);

require_once __DIR__ . '/errors.php';

function sf_root(): string
{
    return dirname(__DIR__);
}

function sf_data_dir(): string
{
    return defined('SF_DATA_DIR') ? SF_DATA_DIR : sf_root() . '/data';
}

function sf_db_path(): string
{
    return defined('SF_DB_PATH') ? SF_DB_PATH : sf_data_dir() . '/sforno.sqlite';
}

/** השעון. בבדיקות — $GLOBALS['sf_time'] מזיז אותו. */
function sf_time(): int
{
    return (int)($GLOBALS['sf_time'] ?? time());
}

function sf_iso(int $ts): string
{
    return gmdate('Y-m-d\\TH:i:s\\Z', $ts);
}

function sf_now(): string
{
    return sf_iso(sf_time());
}

/** מזהה ציבורי: 16 תווים אקראיים, [a-z0-9]. רק הוא יוצא מהשרת; id נשאר פנימי. */
function sf_pid(): string
{
    $abc = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $out = '';
    for ($i = 0; $i < 16; $i++) $out .= $abc[random_int(0, 35)];
    return $out;
}

function sf_is_pid(mixed $v): bool
{
    return is_string($v) && preg_match('/^[a-z0-9]{16}\z/', $v) === 1;
}

/** החיבור: data/db.json אם קיים (MySQL), אחרת SQLite. */
function sf_db_config(): array
{
    $f = sf_data_dir() . '/db.json';
    if (!defined('SF_DB_PATH') && is_file($f)) {
        $c = json_decode((string)file_get_contents($f), true);
        if (is_array($c) && is_string($c['dsn'] ?? null)) return ['dsn' => $c['dsn'], 'user' => $c['user'] ?? null, 'pass' => $c['pass'] ?? null];
    }
    return ['dsn' => 'sqlite:' . sf_db_path(), 'user' => null, 'pass' => null];
}

function sf_db(): PDO
{
    if (isset($GLOBALS['sf_pdo'])) return $GLOBALS['sf_pdo'];
    $cfg = sf_db_config();
    try {
        $sqlite = str_starts_with($cfg['dsn'], 'sqlite:');
        if ($sqlite && sf_db_path() !== ':memory:') {
            $dir = dirname(sf_db_path());
            if (!is_dir($dir) && !@mkdir($dir, 0700, true)) throw new RuntimeException('data/ לא קיימת ואי אפשר ליצור אותה');
            if (!is_writable($dir)) throw new RuntimeException('data/ לא ניתנת לכתיבה');
        }
        $db = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        if ($sqlite) {
            $db->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
            if (sf_db_path() !== ':memory:') $db->exec('PRAGMA journal_mode = WAL;');
        }
    } catch (Throwable $e) {
        error_log('sforno: המסד לא נפתח — ' . $e->getMessage());   // ליומן של PHP; המשתמש מקבל 503
        throw new SfError('storage');
    }
    try {
        sf_migrate($db);
    } catch (Throwable $e) {
        error_log('sforno: מיגרציה נכשלה — ' . $e->getMessage());
        throw new SfError('upgrading');
    }
    return $GLOBALS['sf_pdo'] = $db;
}

/** 'sqlite' או 'mysql'. */
function sf_driver(?PDO $db = null): string
{
    return (string)($db ?? sf_db())->getAttribute(PDO::ATTR_DRIVER_NAME);
}

/** סוגי עמודות ב-DDL, לפי המנוע. 'id' — מפתח רץ; 'key' — טקסט שנכנס לאינדקס ייחודי. */
function sf_ddl_types(string $driver): array
{
    return $driver === 'mysql'
        ? ['id' => 'BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY', 'ref' => 'BIGINT', 'int' => 'INT', 'key' => 'VARCHAR(191)', 'text' => 'TEXT', 'ts' => 'VARCHAR(20)', 'tail' => ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4']
        : ['id' => 'INTEGER PRIMARY KEY AUTOINCREMENT', 'ref' => 'INTEGER', 'int' => 'INTEGER', 'key' => 'TEXT', 'text' => 'TEXT', 'ts' => 'TEXT', 'tail' => ''];
}

// ── שאילתות ──────────────────────────────────────────────────

function sf_q(string $sql, array $args = []): PDOStatement
{
    $st = sf_db()->prepare($sql);
    $st->execute(array_values($args));
    return $st;
}

function sf_rows(string $sql, array $args = []): array
{
    return sf_q($sql, $args)->fetchAll();
}

function sf_row(string $sql, array $args = []): ?array
{
    $r = sf_q($sql, $args)->fetch();
    return $r === false ? null : $r;
}

function sf_val(string $sql, array $args = []): mixed
{
    $v = sf_q($sql, $args)->fetchColumn();
    return $v === false ? null : $v;
}

function sf_exec(string $sql, array $args = []): int
{
    return sf_q($sql, $args)->rowCount();
}

/** INSERT מתוך מערך עמודה => ערך. מחזיר את ה-id. */
function sf_insert(string $table, array $row): int
{
    $cols = array_keys($row);
    sf_q('INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', $row);
    return (int)sf_db()->lastInsertId();
}

/**
 * טרנזקציה: הכול או כלום. מקוננת — רצה בתוך החיצונית. ב-SQLite נפתחת ב-BEGIN IMMEDIATE (נועלת לכתיבה מיד),
 * כדי ששתי בקשות שבודקות "האם נשאר מנהל" לא יראו שתיהן את אותו מצב.
 */
function sf_tx(callable $fn): mixed
{
    $db = sf_db();
    if (!empty($GLOBALS['sf_in_tx'])) return $fn();
    sf_driver($db) === 'sqlite' ? $db->exec('BEGIN IMMEDIATE') : $db->beginTransaction();
    $GLOBALS['sf_in_tx'] = true;
    try {
        $out = $fn();
        $db->inTransaction() ? $db->commit() : $db->exec('COMMIT');
        return $out;
    } catch (Throwable $e) {
        try {
            $db->inTransaction() ? $db->rollBack() : $db->exec('ROLLBACK');
        } catch (Throwable) {
            // אין טרנזקציה פתוחה — אין מה לבטל
        }
        throw $e;
    } finally {
        $GLOBALS['sf_in_tx'] = false;
    }
}

// ── meta: מפתח / ערך ─────────────────────────────────────────

function sf_meta(string $key, ?string $default = null): ?string
{
    $v = sf_val('SELECT v FROM meta WHERE k = ?', [$key]);
    return $v === null ? $default : (string)$v;
}

/** בלי upsert ייחודי למנוע: קודם בודקים, ואז UPDATE או INSERT. */
function sf_meta_set(string $key, string $value, ?PDO $db = null): void
{
    $db ??= sf_db();
    $has = $db->prepare('SELECT 1 FROM meta WHERE k = ?');
    $has->execute([$key]);
    $db->prepare($has->fetchColumn() ? 'UPDATE meta SET v = ? WHERE k = ?' : 'INSERT INTO meta (v, k) VALUES (?, ?)')->execute([$value, $key]);
}

// ── מיגרציות ─────────────────────────────────────────────────

/** @return array<int, array{version:int, name:string, file:string}> */
function sf_migration_files(?string $dir = null): array
{
    $out = [];
    foreach (glob(($dir ?? __DIR__ . '/migrations') . '/*.php') ?: [] as $f) {
        if (!preg_match('/^(\d{3})-([a-z0-9-]+)\.php$/', basename($f), $m)) throw new LogicException('שם מיגרציה לא תקין: ' . basename($f));
        $v = (int)$m[1];
        if (isset($out[$v])) throw new LogicException("שתי מיגרציות עם המספר $v");
        $out[$v] = ['version' => $v, 'name' => $m[2], 'file' => $f];
    }
    ksort($out);
    if ($out && array_keys($out) !== range(1, count($out))) throw new LogicException('מספרי המיגרציות אינם רצופים מ-001');
    return $out;
}

function sf_schema_expected(): int
{
    $f = sf_migration_files();
    return $f ? (int)array_key_last($f) : 0;
}

function sf_schema_version(PDO $db): int
{
    try {
        $st = $db->query("SELECT v FROM meta WHERE k = 'schema_version'");
        return (int)($st ? $st->fetchColumn() : 0);
    } catch (PDOException) {
        return 0;   // אין עדיין טבלת meta
    }
}

/** מריץ את המיגרציות החסרות. כל אחת בטרנזקציה נועלת: שתי בקשות ראשונות במקביל — השנייה מחכה ורואה שכבר רצה. */
function sf_migrate(PDO $db, ?string $dir = null): void
{
    $files = sf_migration_files($dir);
    $want = $files ? (int)array_key_last($files) : 0;
    if (sf_schema_version($db) >= $want) return;
    $driver = sf_driver($db);
    $t = sf_ddl_types($driver);
    $db->exec("CREATE TABLE IF NOT EXISTS meta (k {$t['key']} NOT NULL PRIMARY KEY, v {$t['text']}){$t['tail']}");
    foreach ($files as $v => $m) {
        $driver === 'sqlite' ? $db->exec('BEGIN IMMEDIATE') : $db->beginTransaction();
        try {
            if (sf_schema_version($db) < $v) {
                $fn = require $m['file'];
                $fn($db, $driver);
                sf_meta_set('schema_version', (string)$v, $db);
            }
            $db->inTransaction() ? $db->commit() : $db->exec('COMMIT');
        } catch (Throwable $e) {
            try { $db->inTransaction() ? $db->rollBack() : $db->exec('ROLLBACK'); } catch (Throwable) {}
            throw $e;
        }
    }
}
