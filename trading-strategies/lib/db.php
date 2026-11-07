<?php
/**
 * המסד של האפליקציה — SQLite ב-data/app.sqlite (אפיון פרק 4). משלה בלבד, בלי קבצים מחוץ לתיקייה.
 *
 * מיגרציות לפי PRAGMA user_version. מחירים, כמויות וסכומים — TEXT עשרוני (lib/decimal.php), לא REAL.
 * זמנים של עסקה — UTC בצורה "YYYY-MM-DDTHH:MM:SSZ" (ממוינת כמחרוזת); זמני מערכת — שניות unix.
 */

declare(strict_types=1);

function ts_db_path(): string
{
    return defined('TS_DB_PATH') ? TS_DB_PATH : dirname(__DIR__) . '/data/app.sqlite';
}

function ts_db(): PDO
{
    static $db = null;
    if ($db) return $db;
    $path = ts_db_path();
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    $db = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    $db->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000; PRAGMA journal_mode = WAL;');
    ts_migrate($db);
    return $db;
}

function ts_migrate(PDO $db): void
{
    $v = (int)$db->query('PRAGMA user_version')->fetchColumn();
    if ($v < 1) {
        $db->exec(<<<'SQL'
            BEGIN;
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                platform_sub TEXT NOT NULL UNIQUE,
                display_name TEXT NOT NULL DEFAULT '',
                email TEXT,
                base_currency TEXT NOT NULL DEFAULT 'USD',
                timezone TEXT NOT NULL DEFAULT 'Asia/Jerusalem',
                account_size TEXT,
                default_risk_pct TEXT NOT NULL DEFAULT '1',
                created_at INTEGER NOT NULL,
                last_login_at INTEGER
            );
            -- העוגייה ts_session: במסד רק ה-hash של האסימון
            CREATE TABLE sessions (
                token_hash TEXT PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                csrf TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                last_seen INTEGER NOT NULL
            );
            CREATE TABLE strategies (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                name TEXT NOT NULL,
                summary TEXT NOT NULL DEFAULT '',
                markets TEXT NOT NULL DEFAULT '[]',
                examples TEXT NOT NULL DEFAULT '',
                timeframe TEXT NOT NULL DEFAULT '',
                style TEXT NOT NULL DEFAULT '',
                tags TEXT NOT NULL DEFAULT '[]',
                status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','active','archived')),
                current_version_id INTEGER,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            );
            CREATE INDEX strategies_user ON strategies(user_id);
            CREATE TABLE strategy_versions (
                id INTEGER PRIMARY KEY,
                strategy_id INTEGER NOT NULL REFERENCES strategies(id) ON DELETE CASCADE,
                version_no INTEGER NOT NULL,
                thesis TEXT NOT NULL DEFAULT '',
                setup TEXT NOT NULL DEFAULT '[]',
                entry_rules TEXT NOT NULL DEFAULT '[]',
                exit_rules TEXT NOT NULL DEFAULT '{}',
                risk TEXT NOT NULL DEFAULT '{}',
                notes TEXT NOT NULL DEFAULT '',
                created_at INTEGER NOT NULL,
                UNIQUE (strategy_id, version_no)
            );
            CREATE TABLE instruments (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                symbol TEXT NOT NULL,
                market TEXT NOT NULL,
                currency TEXT NOT NULL,
                multiplier TEXT NOT NULL,
                unit_label TEXT NOT NULL DEFAULT '',
                option_meta TEXT,
                UNIQUE (user_id, market, symbol)
            );
            -- multiplier ו-currency נשמרים גם בעסקה: עריכת "נכסים שלי" לא משנה רווח של עסקה שכבר נרשמה
            CREATE TABLE trades (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                instrument_id INTEGER NOT NULL REFERENCES instruments(id),
                strategy_version_id INTEGER REFERENCES strategy_versions(id),
                direction TEXT NOT NULL CHECK (direction IN ('long','short')),
                opened_at TEXT NOT NULL,
                entry_price TEXT NOT NULL,
                quantity TEXT NOT NULL,
                entry_fees TEXT NOT NULL DEFAULT '0',
                multiplier TEXT NOT NULL,
                currency TEXT NOT NULL,
                planned_stop TEXT,
                planned_target TEXT,
                status TEXT NOT NULL CHECK (status IN ('open','closed')),
                followed_plan TEXT CHECK (followed_plan IN ('yes','no','partial')),
                checklist TEXT NOT NULL DEFAULT '[]',
                mood TEXT NOT NULL DEFAULT '',
                went_well TEXT NOT NULL DEFAULT '',
                to_improve TEXT NOT NULL DEFAULT '',
                rating INTEGER CHECK (rating BETWEEN 1 AND 5),
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            );
            CREATE INDEX trades_user ON trades(user_id, opened_at);
            CREATE INDEX trades_version ON trades(strategy_version_id);
            CREATE TABLE trade_exits (
                id INTEGER PRIMARY KEY,
                trade_id INTEGER NOT NULL REFERENCES trades(id) ON DELETE CASCADE,
                exited_at TEXT NOT NULL,
                price TEXT NOT NULL,
                quantity TEXT NOT NULL,
                fees TEXT NOT NULL DEFAULT '0'
            );
            CREATE INDEX exits_trade ON trade_exits(trade_id);
            PRAGMA user_version = 1;
            COMMIT;
            SQL);
    }
}
