<?php
/**
 * המסד של האפליקציה — SQLite אחד ב-data/app.sqlite (WAL, מפתחות זרים). משלה בלבד: שום קובץ מחוץ ל-appraisers/.
 *
 * מיגרציות: lib/migrations/NNN_name.sql, רצות לבד בבקשה הראשונה, לפי הסדר, כל אחת בטרנזקציה, ונרשמות ב-
 * schema_migrations. גרסת הסכימה — PRAGMA user_version (נבדקת בבדיקות התקינות). מיגרציה שנכנסה ל-main לא משתנה
 * עוד — שינוי = קובץ חדש עם המספר הבא.
 *
 * בבדיקות (CLI): APR_DB_PATH = ':memory:' ו-APR_DATA_DIR זמנית.
 */

declare(strict_types=1);

require_once __DIR__ . '/errors.php';

function apr_data_dir(): string
{
    return defined('APR_DATA_DIR') ? APR_DATA_DIR : dirname(__DIR__) . '/data';
}

function apr_db_path(): string
{
    return defined('APR_DB_PATH') ? APR_DB_PATH : apr_data_dir() . '/app.sqlite';
}

/** השעון — בבדיקות אפשר להזיז אותו ($GLOBALS['apr_time']). */
function apr_time(): int
{
    return (int)($GLOBALS['apr_time'] ?? time());
}

function apr_iso(int $ts): string
{
    return gmdate('Y-m-d\\TH:i:s\\Z', $ts);
}

function apr_now(): string
{
    return apr_iso(apr_time());
}

/** מזהה ציבורי: 16 תווים אקראיים, אותיות קטנות וספרות (כ-82 סיביות). */
function apr_pid(): string
{
    $abc = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $out = '';
    for ($i = 0; $i < 16; $i++) $out .= $abc[random_int(0, 35)];
    return $out;
}

function apr_is_pid(mixed $v): bool
{
    return is_string($v) && preg_match('/^[a-z0-9]{16}\z/', $v) === 1;
}

function apr_db(): PDO
{
    if (isset($GLOBALS['apr_pdo'])) return $GLOBALS['apr_pdo'];
    $path = apr_db_path();
    try {
        if ($path !== ':memory:') {
            $dir = dirname($path);
            if (!is_dir($dir) && !@mkdir($dir, 0700, true)) throw new RuntimeException('data/ לא קיימת ואי אפשר ליצור אותה');
            if (!is_writable($dir)) throw new RuntimeException('data/ לא ניתנת לכתיבה');
        }
        $db = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        // recursive_triggers: בלעדיו INSERT OR REPLACE מוחק את השורה הישנה בלי להפעיל את טריגרי ה-DELETE — ועוקף את "שורה אינה נמחקת"
        $db->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000; PRAGMA recursive_triggers = ON;');
        if ($path !== ':memory:') $db->exec('PRAGMA journal_mode = WAL;');
    } catch (Throwable $e) {
        error_log('appraisers: המסד לא נפתח — ' . $e->getMessage());   // ליומן השגיאות של PHP; המשתמש מקבל 503
        throw new AprError('storage');
    }
    try {
        apr_migrate($db);
    } catch (Throwable $e) {
        error_log('appraisers: מיגרציה נכשלה — ' . $e->getMessage());
        $GLOBALS['apr_migration_error'] = $e->getMessage();
        throw new AprError(apr_is_busy($e) ? 'busy' : 'upgrading');
    }
    return $GLOBALS['apr_pdo'] = $db;
}

function apr_is_busy(Throwable $e): bool
{
    return $e instanceof PDOException && (str_contains($e->getMessage(), 'database is locked') || str_contains($e->getMessage(), 'SQLITE_BUSY'));
}

/** @return array<int, array{version:int, name:string, file:string}> המיגרציות שבקוד, לפי הסדר */
function apr_migration_files(?string $dir = null): array
{
    $out = [];
    foreach (glob(($dir ?? __DIR__ . '/migrations') . '/*.sql') ?: [] as $f) {
        if (!preg_match('/^(\d{3})_([a-z0-9_]+)\.sql$/', basename($f), $m)) {
            throw new LogicException('שם מיגרציה לא תקין: ' . basename($f));
        }
        $v = (int)$m[1];
        if (isset($out[$v])) throw new LogicException("שתי מיגרציות עם המספר $v");
        $out[$v] = ['version' => $v, 'name' => $m[2], 'file' => $f];
    }
    ksort($out);
    if ($out && array_keys($out) !== range(1, count($out))) throw new LogicException('מספרי המיגרציות אינם רצופים מ-001');
    return $out;
}

/** הגרסה שהקוד מצפה לה. */
function apr_schema_expected(): int
{
    $f = apr_migration_files();
    return $f ? (int)array_key_last($f) : 0;
}

function apr_schema_version(PDO $db): int
{
    return (int)$db->query('PRAGMA user_version')->fetchColumn();
}

/** מריץ את המיגרציות החסרות. כל אחת בטרנזקציה: כישלון מבטל אותה כולה, והגרסה לא מתקדמת. */
function apr_migrate(PDO $db, ?string $dir = null): void
{
    $files = apr_migration_files($dir);
    if (apr_schema_version($db) >= count($files)) return;
    $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version INTEGER PRIMARY KEY, name TEXT NOT NULL, applied_at TEXT NOT NULL)');
    foreach ($files as $v => $m) {
        // BEGIN IMMEDIATE נועל: שתי בקשות ראשונות במקביל — השנייה מחכה, ואז רואה שהמיגרציה כבר רצה
        $db->exec('BEGIN IMMEDIATE');
        try {
            if (apr_schema_version($db) < $v) {
                $db->exec((string)file_get_contents($m['file']));
                $db->prepare('INSERT INTO schema_migrations (version, name, applied_at) VALUES (?, ?, ?)')->execute([$v, $m['name'], apr_now()]);
                $db->exec('PRAGMA user_version = ' . $v);
            }
            $db->exec('COMMIT');
        } catch (Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
    }
}

/** טרנזקציה: הכול או כלום. היומן (audit) נכתב בתוכה — אם הוא נכשל, הפעולה נכשלת. */
function apr_tx(callable $fn): mixed
{
    $db = apr_db();
    // טרנזקציה בתוך טרנזקציה מצטרפת לחיצונית. הדגל שלנו, כי PDO אינו יודע על BEGIN שנשלח ב-exec.
    if (!empty($GLOBALS['apr_in_tx'])) return $fn($db);
    $db->exec('BEGIN IMMEDIATE');
    $GLOBALS['apr_in_tx'] = true;
    try {
        $out = $fn($db);
        $GLOBALS['apr_in_tx'] = false;
        $db->exec('COMMIT');
        return $out;
    } catch (Throwable $e) {
        $GLOBALS['apr_in_tx'] = false;
        $db->exec('ROLLBACK');
        throw $e;
    }
}

function apr_row(string $sql, array $args = []): ?array
{
    $st = apr_db()->prepare($sql);
    $st->execute($args);
    return $st->fetch() ?: null;
}

function apr_rows(string $sql, array $args = []): array
{
    $st = apr_db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

function apr_val(string $sql, array $args = []): mixed
{
    $st = apr_db()->prepare($sql);
    $st->execute($args);
    return $st->fetchColumn();
}

function apr_exec(string $sql, array $args = []): int
{
    $st = apr_db()->prepare($sql);
    $st->execute($args);
    return $st->rowCount();
}

function apr_insert(string $table, array $row): int
{
    $cols = array_keys($row);
    apr_exec("INSERT INTO $table (" . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', array_values($row));
    return (int)apr_db()->lastInsertId();
}

// ── הגדרות (meta) ────────────────────────────────────────────

function apr_meta(string $key, ?string $default = null): ?string
{
    $v = apr_val('SELECT value FROM meta WHERE key = ?', [$key]);
    return $v === false ? $default : (string)$v;
}

function apr_meta_set(string $key, string $value): void
{
    apr_exec('INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', [$key, $value]);
}

/** הגדרות המערכת שמנהל המערכת משנה: שם => ברירת מחדל. נשמרות ב-meta ("setting.<שם>"). */
const APR_SETTINGS = ['office_self_create' => true, 'invites_per_day' => 200];

/** הגדרה: כן / לא, או מספר — לפי הסוג של ברירת המחדל. */
function apr_setting(string $name): bool|int
{
    $v = apr_meta('setting.' . $name);
    if (is_int(APR_SETTINGS[$name])) return $v === null ? APR_SETTINGS[$name] : (int)$v;
    return $v === null ? APR_SETTINGS[$name] : $v === '1';
}

// ── הגבלת קצב ───────────────────────────────────────────────

/**
 * מונה בחלון זמן (טבלת login_attempts): עד $max פעמים ב-$window שניות לכל מפתח. מחזיר false כשהתקרה הושגה — ואז
 * לא סופר. אטומי (BEGIN IMMEDIATE): בקשות במקביל לא עוקפות את התקרה.
 */
function apr_limit(string $key, int $max, int $window): bool
{
    return apr_tx(function () use ($key, $max, $window) {
        $now = apr_time();
        $r = apr_row('SELECT count, updated_at FROM login_attempts WHERE key = ?', [$key]);
        $fresh = !$r || strtotime($r['updated_at']) <= $now - $window;
        $count = $fresh ? 0 : (int)$r['count'];
        if ($count >= $max) return false;
        apr_exec('INSERT INTO login_attempts (key, count, updated_at) VALUES (?, 1, ?)
            ON CONFLICT(key) DO UPDATE SET count = ?, updated_at = ?, locked_until = NULL', [$key, apr_iso($now), $count + 1, $fresh ? apr_iso($now) : $r['updated_at']]);
        return true;
    });
}

// ── מפתח הסודות ──────────────────────────────────────────────

/**
 * data/secret.key — 32 בתים אקראיים, נוצר בהרצה הראשונה. ממנו נגזרים אסימונים פנימיים (cron), ובו יוצפנו מפתחות
 * שירות בפרוסות הבאות. לא בגיט, ולא נגיש מהדפדפן (data/.htaccess).
 */
function apr_secret(): string
{
    if (isset($GLOBALS['apr_secret'])) return $GLOBALS['apr_secret'];
    $file = apr_data_dir() . '/secret.key';
    $key = is_file($file) ? (string)file_get_contents($file) : '';
    if (strlen($key) !== 32) {
        if (!is_dir(dirname($file))) @mkdir(dirname($file), 0700, true);
        $key = random_bytes(32);
        // "x" — נכשל אם הקובץ כבר קיים: שתי בקשות ראשונות במקביל לא דורסות זו את המפתח של זו
        $h = @fopen($file, 'x');
        if ($h) {
            fwrite($h, $key);
            fclose($h);
            @chmod($file, 0600);
        } else {
            $key = (string)@file_get_contents($file);
            if (strlen($key) !== 32) throw new AprError('storage');
        }
    }
    return $GLOBALS['apr_secret'] = $key;
}

/** אסימון פנימי שנגזר ממפתח הסודות — יציב, ואפשר להציג אותו שוב למפתח בלי לשמור אותו. */
function apr_derived_token(string $purpose): string
{
    return substr(hash_hmac('sha256', 'apr:' . $purpose, apr_secret()), 0, 40);
}
