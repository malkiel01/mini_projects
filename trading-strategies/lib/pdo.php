<?php
/**
 * חיבור SQLite לשני המסדים (data/app.sqlite, data/cache.sqlite) — עם TsStmt, שסוגר את הסמן אחרי קריאה של שורה אחת.
 *
 * **למה (תקלת 500 ב-16.11, "תקלה בשרת"):** ב-WAL, שאילתה שנקראה ממנה שורה אחת (fetch / fetchColumn) ולא נסגרה משאירה
 * את החיבור בתוך טרנזקציית קריאה, על "תמונת מצב" של הרגע שבו רצה. אם בקשה אחרת כתבה בינתיים, הכתיבה הבאה **באותו חיבור**
 * נכשלת מיד ב-"database is locked" (SQLITE_BUSY_SNAPSHOT) — busy_timeout לא עוזר, כי SQLite לא מחכה במקרה הזה.
 * בטלפון זה קורה כשמפעילים שיטה: chart_refresh, analysis (בדיקה לאחור ארוכה) ו-method_colors_save רצים יחד, וכל אחד כותב
 * (rate_hits, provider_usage, backtests). tests/sqlite-lock.php מדגים.
 *
 * **הכלל:** בקוד הזה fetch() ו-fetchColumn() הן תמיד קריאה של שורה אחת (אין לולאת while על fetch) — ולכן סגירה מיד
 * אחריהן לא משנה תוצאה, ומשחררת את הקריאה. רשימה — fetchAll() או foreach, שקוראים עד הסוף.
 */

declare(strict_types=1);

class TsStmt extends PDOStatement
{
    protected function __construct() {}

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $r = parent::fetch($mode, $cursorOrientation, $cursorOffset);
        $this->closeCursor();
        return $r;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $r = parent::fetchColumn($column);
        $this->closeCursor();
        return $r;
    }
}

/** PDO ל-SQLite: שגיאות כחריגות, שורות כמערך אסוציאטיבי, TsStmt, busy_timeout ו-WAL */
function ts_sqlite_open(string $path, array $extra = []): PDO
{
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    $db = new PDO('sqlite:' . $path, null, null, $extra + [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STATEMENT_CLASS => [TsStmt::class],
    ]);
    $db->exec('PRAGMA busy_timeout = 5000; PRAGMA journal_mode = WAL;');
    return $db;
}
