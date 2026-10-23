<?php
/**
 * המסד של עורך התמלילים — SQLite אחד ב-data/ (מוחרג בפריסה: data/*.sqlite*, data/media/).
 * אין כאן שום require לקובץ מחוץ לתיקייה (CLAUDE.md: אפליקציה עצמאית מהמעטפת).
 */

const WP_SCHEMA_VERSION = 4;

function wp_data_dir(): string
{
    $dir = getenv('WP_DATA_DIR') ?: dirname(__DIR__) . '/data';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    return $dir;
}

function wp_media_dir(): string
{
    $dir = wp_data_dir() . '/media';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    return $dir;
}

/**
 * חשוב: אחרי fetch() של שורה אחת — closeCursor(). בלעדיו הקריאה נשארת פתוחה, וכתיבה באותה פונקציה נכשלת מיד
 * ("database is locked") אם בקשה אחרת כתבה בינתיים — busy_timeout לא עוזר במקרה הזה.
 */
function db(): PDO
{
    static $pdo = null;
    static $path = null;
    $want = wp_data_dir() . '/word-processor.sqlite';
    if ($pdo && $path === $want) return $pdo;
    $path = $want;
    $pdo = new PDO('sqlite:' . $want, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON');
    wp_migrate($pdo);
    return $pdo;
}

function wp_migrate(PDO $pdo): void
{
    $v = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($v >= WP_SCHEMA_VERSION) return;
    $pdo->exec(<<<SQL
        -- משתמשי האפליקציה עצמה. מקושרים לזהות הפלטפורמה (sub), המייל קובע (docs/sso.md, סעיף 4)
        CREATE TABLE IF NOT EXISTS accounts (
            id INTEGER PRIMARY KEY,
            sub TEXT UNIQUE,
            email TEXT,
            name TEXT NOT NULL DEFAULT '',
            role TEXT NOT NULL DEFAULT 'user',
            created_at INTEGER NOT NULL,
            last_seen INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS accounts_email ON accounts(email COLLATE NOCASE);

        -- סשן של האפליקציה כשהיא מנותקת מהפלטפורמה (עוגייה wp_session)
        CREATE TABLE IF NOT EXISTS app_sessions (
            hash TEXT PRIMARY KEY,
            account INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
            expires INTEGER NOT NULL
        );

        -- לקוחות ההטמעה (embed.md, פרק 1)
        CREATE TABLE IF NOT EXISTS clients (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL DEFAULT '',
            key_url TEXT NOT NULL,
            origins TEXT NOT NULL,
            status TEXT NOT NULL,
            keys TEXT,
            keys_at INTEGER NOT NULL DEFAULT 0,
            refetch_at INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS nonces (
            client TEXT NOT NULL,
            nonce TEXT NOT NULL,
            t INTEGER NOT NULL,
            PRIMARY KEY (client, nonce)
        );

        -- המסמכים. space = 'a:<account>' (משתמש האפליקציה) או 'c:<client>' (לקוח הטמעה)
        CREATE TABLE IF NOT EXISTS docs (
            id TEXT PRIMARY KEY,
            space TEXT NOT NULL,
            title TEXT NOT NULL DEFAULT '',
            json TEXT NOT NULL,
            page TEXT NOT NULL DEFAULT '{}',
            version INTEGER NOT NULL DEFAULT 1,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            updated_by TEXT NOT NULL DEFAULT '',
            updated_by_id TEXT NOT NULL DEFAULT '',
            deleted_at INTEGER              -- סל המחזור: מתי הועבר לסל (NULL — לא בסל)
        );
        CREATE INDEX IF NOT EXISTS docs_space ON docs(space, updated_at);

        -- גרסאות: כל שמירה, מאוחדות לאחת לכל משתמש בכל 10 דקות, עד 100 למסמך (embed.md, פרק 0)
        CREATE TABLE IF NOT EXISTS versions (
            id INTEGER PRIMARY KEY,
            doc TEXT NOT NULL REFERENCES docs(id) ON DELETE CASCADE,
            version INTEGER NOT NULL,
            title TEXT NOT NULL DEFAULT '',
            json TEXT NOT NULL,
            page TEXT NOT NULL DEFAULT '{}',
            user_id TEXT NOT NULL DEFAULT '',
            user_name TEXT NOT NULL DEFAULT '',
            started_at INTEGER NOT NULL,
            saved_at INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS versions_doc ON versions(doc, id);

        -- נעילה לעריכה: 2 דקות בלי דופק
        CREATE TABLE IF NOT EXISTS locks (
            doc TEXT PRIMARY KEY REFERENCES docs(id) ON DELETE CASCADE,
            user_id TEXT NOT NULL,
            user_name TEXT NOT NULL DEFAULT '',
            holder TEXT NOT NULL,
            since INTEGER NOT NULL,
            beat INTEGER NOT NULL
        );

        -- טוקן חד-פעמי ל-iframe (60 שניות) וסשן העריכה שנפתח ממנו (Bearer, 8 שעות)
        CREATE TABLE IF NOT EXISTS embed_tokens (
            hash TEXT PRIMARY KEY,
            client TEXT NOT NULL,
            doc TEXT NOT NULL,
            user_id TEXT NOT NULL,
            user_name TEXT NOT NULL,
            can TEXT NOT NULL,
            mode TEXT NOT NULL,
            return_origin TEXT NOT NULL,
            expires INTEGER NOT NULL,
            used INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS embed_sessions (
            hash TEXT PRIMARY KEY,
            client TEXT NOT NULL,
            doc TEXT NOT NULL,
            user_id TEXT NOT NULL,
            user_name TEXT NOT NULL,
            can TEXT NOT NULL,
            mode TEXT NOT NULL,
            return_origin TEXT NOT NULL,
            expires INTEGER NOT NULL
        );

        -- תמונות: הקובץ ב-data/media/<id>, שייך למסמך
        CREATE TABLE IF NOT EXISTS media (
            id TEXT PRIMARY KEY,
            doc TEXT NOT NULL,
            type TEXT NOT NULL,
            size INTEGER NOT NULL,
            width INTEGER NOT NULL DEFAULT 0,
            height INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS media_doc ON media(doc);

        -- שיתוף (שלב 5): מסמך של חשבון אחד — למייל אחר, בתפקיד view / comment / edit. לפי מייל: פעיל גם לפני
        -- שבעל המייל נכנס בפעם הראשונה (החשבון שלו נוצר אז, עם אותו מייל — lib/share.php)
        CREATE TABLE IF NOT EXISTS shares (
            doc TEXT NOT NULL,
            email TEXT NOT NULL COLLATE NOCASE,
            role TEXT NOT NULL,
            by_account INTEGER NOT NULL,
            created_at INTEGER NOT NULL,
            PRIMARY KEY (doc, email)
        );
        CREATE INDEX IF NOT EXISTS shares_email ON shares(email COLLATE NOCASE);
        CREATE TABLE IF NOT EXISTS settings (
            k TEXT PRIMARY KEY,
            v TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS log (
            id INTEGER PRIMARY KEY,
            t INTEGER NOT NULL,
            what TEXT NOT NULL,
            detail TEXT NOT NULL DEFAULT ''
        );
    SQL);
    // גרסה 2: סל מחזור — עמודה במסד קיים
    $cols = array_column($pdo->query('PRAGMA table_info(docs)')->fetchAll(), 'name');
    if (!in_array('deleted_at', $cols, true)) $pdo->exec('ALTER TABLE docs ADD COLUMN deleted_at INTEGER');
    // גרסה 3: סוג גרסה — '' רגילה (מתאחדת בחלון 10 הדקות), 'restore' — שחזור (לא מתאחדת, וגם לא מתאחדים לתוכה)
    $vcols = array_column($pdo->query('PRAGMA table_info(versions)')->fetchAll(), 'name');
    if (!in_array('kind', $vcols, true)) $pdo->exec("ALTER TABLE versions ADD COLUMN kind TEXT NOT NULL DEFAULT ''");
    $pdo->exec('PRAGMA user_version = ' . WP_SCHEMA_VERSION);
}

function setting(string $k, ?callable $init = null): ?string
{
    $v = db()->prepare('SELECT v FROM settings WHERE k = ?');
    $v->execute([$k]);
    $val = $v->fetchColumn(); $v->closeCursor();
    if ($val !== false) return $val;
    if (!$init) return null;
    $val = $init();
    db()->prepare('INSERT OR IGNORE INTO settings (k, v) VALUES (?, ?)')->execute([$k, $val]);
    return setting($k);
}

function set_setting(string $k, string $v): void
{
    db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')->execute([$k, $v]);
}

/** מפתח סודי של העורך (חתימת כתובות התמונות). במסד — לא בקובץ, כי רק המסד מוחרג בפריסה. */
function app_secret(): string
{
    return base64_decode(setting('secret', fn() => base64_encode(random_bytes(32))));
}

function wp_log(string $what, string $detail = ''): void
{
    try {
        db()->prepare('INSERT INTO log (t, what, detail) VALUES (?, ?, ?)')->execute([time(), $what, mb_substr($detail, 0, 500)]);
        if (random_int(1, 50) === 1) db()->exec('DELETE FROM log WHERE id < (SELECT MAX(id) - 5000 FROM log)');
    } catch (Throwable $e) {
        error_log('word-processor log: ' . $e->getMessage());
    }
}

function rand_id(int $len = 20): string
{
    $abc = 'abcdefghijkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) $out .= $abc[random_int(0, strlen($abc) - 1)];
    return $out;
}
