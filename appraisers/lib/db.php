<?php
/**
 * המסד של האפליקציה — SQLite ב-data/app.sqlite. משלה בלבד: שום קובץ מחוץ ל-appraisers/.
 *
 * מיגרציות: קובצי lib/migrations/NNN_name.sql, רצים לפי הסדר, כל אחד בטרנזקציה, ונרשמים ב-schema_migrations.
 * מיגרציה שנכנסה ל-main לא משתנה עוד — שינוי = קובץ חדש עם המספר הבא.
 * בבדיקות: APR_DB_PATH = ':memory:' ו-apr_db_reset() בין בדיקה לבדיקה.
 */

declare(strict_types=1);

final class AprError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message, $status);
    }
}

function apr_data_dir(): string
{
    return defined('APR_DATA_DIR') ? APR_DATA_DIR : dirname(__DIR__) . '/data';
}

function apr_db_path(): string
{
    return defined('APR_DB_PATH') ? APR_DB_PATH : apr_data_dir() . '/app.sqlite';
}

function apr_now(): string
{
    return gmdate('Y-m-d\\TH:i:s\\Z', apr_time());
}

/** השעון — בבדיקות אפשר להזיז אותו ($GLOBALS['apr_time']). */
function apr_time(): int
{
    return (int)($GLOBALS['apr_time'] ?? time());
}

function apr_db(): PDO
{
    if (isset($GLOBALS['apr_pdo'])) return $GLOBALS['apr_pdo'];
    $path = apr_db_path();
    if ($path !== ':memory:' && !is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    $db = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    $db->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    if ($path !== ':memory:') $db->exec('PRAGMA journal_mode = WAL;');
    apr_migrate($db);
    return $GLOBALS['apr_pdo'] = $db;
}

/** בדיקות: מסד חדש וריק. */
function apr_db_reset(): void
{
    unset($GLOBALS['apr_pdo']);
}

/** @return array<int, array{version:int, name:string, file:string}> המיגרציות שבקוד, לפי הסדר */
function apr_migration_files(): array
{
    $out = [];
    foreach (glob(__DIR__ . '/migrations/*.sql') ?: [] as $f) {
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

function apr_migrate(PDO $db): void
{
    $have = apr_schema_version($db);
    $files = apr_migration_files();
    if ($have >= count($files)) return;
    $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version INTEGER PRIMARY KEY, name TEXT NOT NULL, applied_at TEXT NOT NULL)');
    foreach ($files as $v => $m) {
        if ($v <= $have) continue;
        // BEGIN IMMEDIATE — שתי בקשות ראשונות במקביל: השנייה מחכה, ואז רואה שהמיגרציה כבר רצה
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

/** טרנזקציה: הכול או כלום. */
function apr_tx(callable $fn): mixed
{
    $db = apr_db();
    if ($db->inTransaction()) return $fn($db);
    $db->beginTransaction();
    try {
        $out = $fn($db);
        $db->commit();
        return $out;
    } catch (Throwable $e) {
        $db->rollBack();
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

function apr_exec(string $sql, array $args = []): int
{
    $st = apr_db()->prepare($sql);
    $st->execute($args);
    return $st->rowCount();
}
