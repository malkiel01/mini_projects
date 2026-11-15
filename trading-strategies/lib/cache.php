<?php
/**
 * מאגר הנרות (spec-stage-a 3.6) — מסד נפרד, data/cache.sqlite: הוא גדל ומתנקה בקצב משלו, ולא נוגע בנתוני הסוחרים.
 *
 *   candles    נר לכל (provider, symbol, tf, t, cred_scope). t = זמן הפתיחה בשניות UTC. מחירים — מחרוזות כמו מהספק.
 *   coverage   אילו טווחים כבר נמשכו לכל מפתח — כדי לפנות לספק רק על החסר. מכסה רק נרות סגורים.
 *   tails      מתי נמשך לאחרונה הקצה (הנר הפתוח) — התוקף שלו לפי מסגרת הזמן.
 *   search     תוצאות חיפוש ואינדקס סימולים — 24 שעות.
 *   fetch_log  יומן בקשות הנרות (18.2, S2): לכל בקשה — כמה נרות נמשכו מהספק וכמה הוגשו. 7 ימים, עד 2000 שורות.
 *   method_results  תוצאה שמורה של שיטה לכל מפתח חישוב (18.3, S3): נכס, מסגרת, הנרות הסגורים, שיטה וגרסה, פרמטרים.
 *                   חישוב שנגזר מהנרות — נמחק בניקוי היומי אחרי יומיים, ונבנה מחדש לבד.
 *   method_runs     המונה למסך המפתח: כמה פעמים שיטה רצה באמת, וכמה פעמים הוחזרה התוצאה השמורה.
 *
 * cred_scope: '' — מאגר משותף (ספק בלי מפתח שתנאיו מתירים); 'u:<user_id>' — נמשך במפתח אישי, רק של אותו משתמש;
 * 'c:<cred_id>' — ספק מהמנהל (ב), משותף למי שקיבל אותו.
 */

declare(strict_types=1);

/** סוג המאגר לפי cred_scope (3.6, ✅2): משותף / אישי / מהמנהל */
function ts_scope_kind(string $scope): string
{
    return $scope === '' ? 'shared' : (str_starts_with($scope, 'u:') ? 'personal' : 'granted');
}

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
    // גרסה 2 (S2): רק מוסיפה טבלה — לא נוגעת בנרות שכבר במאגר
    if ((int)$db->query('PRAGMA user_version')->fetchColumn() < 2) {
        $db->exec(<<<'SQL'
            BEGIN;
            CREATE TABLE IF NOT EXISTS fetch_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT, at INTEGER NOT NULL, user_id INTEGER NOT NULL,
                provider TEXT NOT NULL, symbol TEXT NOT NULL, tf TEXT NOT NULL, kind TEXT NOT NULL,
                from_t INTEGER NOT NULL, to_t INTEGER NOT NULL,
                provider_calls INTEGER NOT NULL, provider_candles INTEGER NOT NULL, served INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS fetch_log_at ON fetch_log(at);
            PRAGMA user_version = 2;
            COMMIT;
            SQL);
    }
    // גרסה 3 (S3): רק מוסיפה טבלאות — לא נוגעת בנרות ובשום נתון קיים
    if ((int)$db->query('PRAGMA user_version')->fetchColumn() < 3) {
        $db->exec(<<<'SQL'
            BEGIN;
            CREATE TABLE IF NOT EXISTS method_results (
                k TEXT PRIMARY KEY, method_id TEXT NOT NULL, result TEXT NOT NULL, at INTEGER NOT NULL
            ) WITHOUT ROWID;
            CREATE INDEX IF NOT EXISTS method_results_at ON method_results(at);
            CREATE TABLE IF NOT EXISTS method_runs (
                method_id TEXT PRIMARY KEY, runs INTEGER NOT NULL DEFAULT 0, reused INTEGER NOT NULL DEFAULT 0,
                last_run_at INTEGER NOT NULL DEFAULT 0, last_reused_at INTEGER NOT NULL DEFAULT 0
            );
            PRAGMA user_version = 3;
            COMMIT;
            SQL);
    }
    return $db;
}
