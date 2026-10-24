<?php
/**
 * האחסון — SQLite דרך PDO.
 *
 * המיגרציות הן CREATE TABLE IF NOT EXISTS: פריסה על מסד קיים לא נופלת ולא דורסת, ואין שלב התקנה.
 * data/vector.sqlite ו-data/files/ אינם בגיט, ו-deploy.yml מחריג אותם — בלי זה פריסה הייתה דורסת
 * את המשתמשים והציורים.
 */

declare(strict_types=1);

// ניתן לדריסה לפני הטעינה — כך הבדיקות רצות על מסד זמני.
if (!defined('DB_FILE')) define('DB_FILE', __DIR__ . '/../data/vector.sqlite');

final class AppError extends RuntimeException {
    public function __construct(string $message, public readonly int $status = 400) {
        parent::__construct($message);
    }
}

/** ISO-8601 ב-UTC — נשמר כטקסט ומסתדר לקסיקוגרפית. */
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
            platform_sub  TEXT    UNIQUE,
            email         TEXT    NOT NULL UNIQUE COLLATE NOCASE,
            name          TEXT    NOT NULL,
            role          TEXT    NOT NULL DEFAULT 'editor' CHECK (role IN ('admin','editor')),
            blocked       INTEGER NOT NULL DEFAULT 0,
            created_at    TEXT    NOT NULL,
            last_login_at TEXT
        );
    ");
}
