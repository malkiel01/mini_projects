<?php
/**
 * מאגר הנרות (spec-stage-a 3.6) — מסד נפרד, data/cache.sqlite: הוא גדל ומתנקה בקצב משלו, ולא נוגע בנתוני הסוחרים.
 *
 *   candles    נר לכל (provider, symbol, tf, t, cred_scope). t = זמן הפתיחה בשניות UTC. מחירים — מחרוזות כמו מהספק.
 *   coverage   אילו טווחים כבר נמשכו לכל מפתח — כדי לפנות לספק רק על החסר. מכסה רק נרות סגורים.
 *   tails      מתי נמשך לאחרונה הקצה (הנר הפתוח) — התוקף שלו לפי מסגרת הזמן.
 *   search     תוצאות חיפוש ואינדקס סימולים — 24 שעות.
 *
 * cred_scope: '' — מאגר משותף (ספק בלי מפתח שתנאיו מתירים); 'u:<user_id>' — נמשך במפתח אישי, רק של אותו משתמש;
 * 'c:<cred_id>' — ספק מהמנהל (ב), משותף למי שקיבל אותו.
 */

declare(strict_types=1);

function ts_cache_path(): string
{
    return defined('TS_CACHE_PATH') ? TS_CACHE_PATH : dirname(__DIR__) . '/data/cache.sqlite';
}

function ts_cache(): PDO
{
    static $db = null;
    if ($db) return $db;
    $path = ts_cache_path();
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    $db = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA busy_timeout = 5000; PRAGMA journal_mode = WAL;');
    if ((int)$db->query('PRAGMA user_version')->fetchColumn() < 1) {
        $db->exec(<<<'SQL'
            BEGIN;
            CREATE TABLE IF NOT EXISTS candles (
                provider TEXT NOT NULL, symbol TEXT NOT NULL, tf TEXT NOT NULL, t INTEGER NOT NULL,
                o TEXT NOT NULL, h TEXT NOT NULL, l TEXT NOT NULL, c TEXT NOT NULL, v TEXT NOT NULL,
                fetched_at INTEGER NOT NULL, cred_scope TEXT NOT NULL DEFAULT '',
                PRIMARY KEY (provider, symbol, tf, cred_scope, t)
            ) WITHOUT ROWID;
            CREATE INDEX IF NOT EXISTS candles_t ON candles(t);
            CREATE TABLE IF NOT EXISTS coverage (
                provider TEXT NOT NULL, symbol TEXT NOT NULL, tf TEXT NOT NULL, cred_scope TEXT NOT NULL,
                from_t INTEGER NOT NULL, to_t INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS coverage_key ON coverage(provider, symbol, tf, cred_scope);
            CREATE TABLE IF NOT EXISTS tails (
                provider TEXT NOT NULL, symbol TEXT NOT NULL, tf TEXT NOT NULL, cred_scope TEXT NOT NULL,
                fetched_at INTEGER NOT NULL,
                PRIMARY KEY (provider, symbol, tf, cred_scope)
            );
            CREATE TABLE IF NOT EXISTS search (
                provider TEXT NOT NULL, cred_scope TEXT NOT NULL, q TEXT NOT NULL,
                result TEXT NOT NULL, fetched_at INTEGER NOT NULL,
                PRIMARY KEY (provider, cred_scope, q)
            );
            -- פרטי נכס מהחיפוש (שם, שוק, מטבע, בורסה) — מידע תיאורי, לא נתוני מחיר
            CREATE TABLE IF NOT EXISTS asset_meta (
                asset TEXT PRIMARY KEY, name TEXT NOT NULL, market TEXT NOT NULL, currency TEXT NOT NULL,
                exchange TEXT NOT NULL, tz TEXT NOT NULL, updated_at INTEGER NOT NULL
            );
            PRAGMA user_version = 1;
            COMMIT;
            SQL);
    }
    return $db;
}
