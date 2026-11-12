<?php
/**
 * המסד: data/kol.sqlite (מוחרג בפריסה). מיגרציות לפי user_version — כל שלב מוסיף, לא משנה.
 */

declare(strict_types=1);

if (!defined('KOL_DATA_DIR')) define('KOL_DATA_DIR', dirname(__DIR__) . '/data');

/** גרסת הסכמה שהקוד הזה מצפה לה — השלב האחרון ב-migrate(). בדיקת התקינות משווה אותה ל-user_version. */
const KOL_SCHEMA_VERSION = 20;

final class ApiError extends RuntimeException
{
    public function __construct(string $message, int $code = 400, public readonly array $extra = [])
    {
        parent::__construct($message, $code);
    }
}

function now_iso(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!is_dir(KOL_DATA_DIR) && !@mkdir(KOL_DATA_DIR, 0775, true)) throw new ApiError('אין גישה לתיקיית הנתונים', 500);
    $pdo = new PDO('sqlite:' . KOL_DATA_DIR . '/kol.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $v = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    $steps = [
        1 => <<<'SQL'
            -- החברים בכלי. sub ריק — הוזמן לפי מייל ועוד לא נכנס.
            CREATE TABLE members (
                id        INTEGER PRIMARY KEY,
                sub       TEXT UNIQUE,
                email     TEXT,
                name      TEXT NOT NULL DEFAULT '',
                role      TEXT NOT NULL CHECK (role IN ('editor','publisher')),
                added_by  TEXT NOT NULL,
                added_at  TEXT NOT NULL,
                last_seen TEXT
            );
            CREATE UNIQUE INDEX idx_members_email ON members(email) WHERE sub IS NULL;
            -- מי שעבר את השער ואין לו תפקיד — רשימת "ביקשו גישה" אצל הבעלים.
            CREATE TABLE access_requests (
                sub     TEXT PRIMARY KEY,
                name    TEXT NOT NULL DEFAULT '',
                email   TEXT,
                at      TEXT NOT NULL
            );
            CREATE TABLE audit (
                id     INTEGER PRIMARY KEY,
                at     TEXT NOT NULL,
                sub    TEXT NOT NULL,
                name   TEXT NOT NULL DEFAULT '',
                action TEXT NOT NULL,
                detail TEXT NOT NULL DEFAULT ''
            );
            CREATE INDEX idx_audit_at ON audit(at);
            CREATE TABLE settings (k TEXT PRIMARY KEY, v TEXT NOT NULL);
            SQL,
        // שלב 2: הטיוטה, היסטוריית השמירות ונעילת העריכה (lib/draft.php)
        2 => <<<'SQL'
            CREATE TABLE draft (
                id         INTEGER PRIMARY KEY CHECK (id = 1),
                json       TEXT NOT NULL,
                rev        INTEGER NOT NULL,
                updated_by TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
            CREATE TABLE revisions (
                id   INTEGER PRIMARY KEY,
                at   TEXT NOT NULL,
                ts   INTEGER NOT NULL,
                sub  TEXT NOT NULL,
                name TEXT NOT NULL,
                rev  INTEGER NOT NULL,
                json TEXT NOT NULL,
                note TEXT NOT NULL DEFAULT ''
            );
            CREATE TABLE edit_lock (
                id    INTEGER PRIMARY KEY CHECK (id = 1),
                sub   TEXT NOT NULL,
                name  TEXT NOT NULL,
                since INTEGER NOT NULL,
                beat  INTEGER NOT NULL
            );
            SQL,
        // שלב 3: קבצים והעלאות בחלקים (lib/files.php)
        3 => <<<'SQL'
            CREATE TABLE files (
                id      TEXT PRIMARY KEY,
                name    TEXT NOT NULL,
                type    TEXT NOT NULL,
                kind    TEXT NOT NULL,
                ext     TEXT NOT NULL,
                size    INTEGER NOT NULL,
                sha256  TEXT NOT NULL,
                w       INTEGER,
                h       INTEGER,
                by_sub  TEXT NOT NULL,
                by_name TEXT NOT NULL,
                at      TEXT NOT NULL
            );
            CREATE TABLE uploads (
                id      TEXT PRIMARY KEY,
                name    TEXT NOT NULL,
                size    INTEGER NOT NULL,
                sub     TEXT NOT NULL,
                started INTEGER NOT NULL
            );
            SQL,
        // שלב 4: העותק האחרון של טקסטים ארוכים מעורך התמלילים (lib/longtext.php)
        4 => <<<'SQL'
            CREATE TABLE longtexts (
                doc        TEXT PRIMARY KEY,
                html       TEXT NOT NULL,
                css        TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                updated_by TEXT NOT NULL
            );
            SQL,
        // שלב 6: פרסום — מפתח החתימה, הגרסאות, והקבצים שהאתר כבר אישר (lib/publish.php)
        5 => <<<'SQL'
            CREATE TABLE pub_keys (
                kid        TEXT PRIMARY KEY,
                public     TEXT NOT NULL,
                secret     TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                retired    INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE versions (
                n        INTEGER PRIMARY KEY,
                at       TEXT NOT NULL,
                sub      TEXT NOT NULL,
                name     TEXT NOT NULL,
                note     TEXT NOT NULL DEFAULT '',
                json     TEXT NOT NULL,
                html     TEXT NOT NULL,
                css      TEXT NOT NULL,
                assets   TEXT NOT NULL,
                status   TEXT NOT NULL CHECK (status IN ('pending','live','failed','superseded')),
                response TEXT,
                sent_at  TEXT
            );
            CREATE TABLE site_assets (
                sha  TEXT NOT NULL,
                site TEXT NOT NULL,
                at   TEXT NOT NULL,
                PRIMARY KEY (sha, site)
            );
            SQL,
        // ייבוא העמוד הקיים: העלאה גדולה (ZIP) מסומנת כייבוא
        6 => <<<'SQL'
            ALTER TABLE uploads ADD COLUMN purpose TEXT NOT NULL DEFAULT 'file';
            SQL,
        // שלב 7: עוזר הבינה — כל קריאה (request_id) ומכסה לכל חבר (lib/ai.php)
        7 => <<<'SQL'
            CREATE TABLE ai_usage (
                request_id    TEXT PRIMARY KEY,
                sub           TEXT NOT NULL,
                name          TEXT NOT NULL DEFAULT '',
                at            TEXT NOT NULL,
                model         TEXT NOT NULL,
                input_tokens  INTEGER NOT NULL DEFAULT 0,
                output_tokens INTEGER NOT NULL DEFAULT 0,
                status        TEXT NOT NULL CHECK (status IN ('pending','ok','refused','error')),
                prompt        TEXT NOT NULL DEFAULT '',
                error         TEXT,
                reported_at   TEXT
            );
            CREATE INDEX idx_ai_usage_sub_at ON ai_usage(sub, at);
            ALTER TABLE members ADD COLUMN ai_quota INTEGER;
            SQL,
        // המילון: המילים מנוהלות כאן בלבד (lib/lexicon.php). audio — מזהה קובץ שלנו, או כתובת באתר. לכל גרסה — עותק של המילים
        8 => <<<'SQL'
            CREATE TABLE words (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                wp_id       INTEGER,
                word        TEXT NOT NULL,
                meaning     TEXT NOT NULL DEFAULT '',
                topics      TEXT NOT NULL DEFAULT '[]',
                audio       TEXT NOT NULL DEFAULT '',
                external_id TEXT NOT NULL DEFAULT '',
                updated_at  TEXT NOT NULL,
                updated_by  TEXT NOT NULL DEFAULT ''
            );
            CREATE TABLE topics (
                slug TEXT PRIMARY KEY,
                name TEXT NOT NULL UNIQUE
            );
            ALTER TABLE versions ADD COLUMN words TEXT;
            SQL,
        // עותק בכלי של הקלטה מהאתר: origin — הכתובת שלה שם. בפרסום מפנים אליה, ולא שולחים את הקובץ שוב
        9 => <<<'SQL'
            ALTER TABLE files ADD COLUMN origin TEXT;
            CREATE INDEX idx_files_origin ON files(origin);
            SQL,
        // קבצי הקוד של העמוד (lib/code.php), עם היסטוריה ועותק בכל גרסה; וכניסות של צ'אט (lib/agent.php)
        10 => <<<'SQL'
            CREATE TABLE code_files (
                path       TEXT PRIMARY KEY,
                content    TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                updated_by TEXT NOT NULL DEFAULT ''
            );
            CREATE TABLE code_history (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                path    TEXT NOT NULL,
                content TEXT NOT NULL,
                at      TEXT NOT NULL,
                by_name TEXT NOT NULL DEFAULT ''
            );
            CREATE INDEX idx_code_history_path ON code_history(path, id);
            ALTER TABLE versions ADD COLUMN code TEXT;
            CREATE TABLE agent_tokens (
                id         TEXT PRIMARY KEY,
                sub        TEXT NOT NULL,
                name       TEXT NOT NULL,
                label      TEXT NOT NULL DEFAULT '',
                hash       TEXT NOT NULL,
                created_at TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                last_used  TEXT,
                revoked    INTEGER NOT NULL DEFAULT 0,
                creator_role TEXT NOT NULL DEFAULT 'editor'
            );
            SQL,
        // שחזור (lib/restore.php): נקודות שחזור (רכיבים לפי תוכן, דחוסים), סל מחזור לקבצים, למילים ולנושאים
        11 => <<<'SQL'
            CREATE TABLE blobs (
                sha  TEXT PRIMARY KEY,
                data BLOB NOT NULL,
                size INTEGER NOT NULL
            );
            CREATE TABLE snapshots (
                id     INTEGER PRIMARY KEY AUTOINCREMENT,
                at     TEXT NOT NULL,
                sub    TEXT NOT NULL,
                name   TEXT NOT NULL,
                reason TEXT NOT NULL,
                kind   TEXT NOT NULL,
                parts  TEXT NOT NULL,
                meta   TEXT NOT NULL DEFAULT '{}'
            );
            CREATE TABLE words_trash (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                word_id INTEGER NOT NULL,
                row     TEXT NOT NULL,
                at      TEXT NOT NULL,
                by_name TEXT NOT NULL DEFAULT ''
            );
            CREATE TABLE topic_trash (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                at      TEXT NOT NULL,
                by_name TEXT NOT NULL DEFAULT '',
                op      TEXT NOT NULL,
                name    TEXT NOT NULL,
                to_name TEXT,
                before  TEXT NOT NULL
            );
            ALTER TABLE files ADD COLUMN deleted_at TEXT;
            ALTER TABLE files ADD COLUMN deleted_by TEXT;
            SQL,
        // פרוסה F1 — תשתית המפתח: מזהה מספרי לכל משתמש בשביל DevLoop (lib/dev.php), יומן הבקשות והשגיאות
        // (lib/devlog.php), וריצות של שירותי רקע ובדיקות תקינות. הטבלאות של DevLoop עצמו (dev_tasks…) נוצרות במודול.
        12 => <<<'SQL'
            CREATE TABLE dev_users (
                id        INTEGER PRIMARY KEY,
                sub       TEXT NOT NULL UNIQUE,
                name      TEXT NOT NULL DEFAULT '',
                developer INTEGER NOT NULL DEFAULT 0,
                seen_at   TEXT
            );
            CREATE TABLE dev_log (
                id        INTEGER PRIMARY KEY,
                at        TEXT NOT NULL,
                level     TEXT NOT NULL,
                category  TEXT NOT NULL,
                event     TEXT NOT NULL,
                message   TEXT NOT NULL DEFAULT '',
                sub       TEXT NOT NULL DEFAULT '',
                username  TEXT NOT NULL DEFAULT '',
                ua        TEXT NOT NULL DEFAULT '',
                method    TEXT NOT NULL DEFAULT '',
                action    TEXT NOT NULL DEFAULT '',
                status    INTEGER,
                ms        INTEGER,
                req       TEXT NOT NULL DEFAULT '',
                data_json TEXT NOT NULL DEFAULT '{}',
                sig       TEXT NOT NULL DEFAULT '',
                count     INTEGER NOT NULL DEFAULT 1,
                last_at   TEXT
            );
            CREATE INDEX idx_dev_log_at ON dev_log(at);
            CREATE INDEX idx_dev_log_level ON dev_log(level, id);
            CREATE INDEX idx_dev_log_req ON dev_log(req);
            CREATE INDEX idx_dev_log_sig ON dev_log(sig, id);
            CREATE TABLE dev_runs (
                id   INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                at   TEXT NOT NULL,
                ok   INTEGER NOT NULL,
                note TEXT NOT NULL DEFAULT ''
            );
            CREATE INDEX idx_dev_runs_name ON dev_runs(name, id);
            SQL,
        13 => <<<'SQL'
            -- פרוסה F2a: מאגר הפרויקט (lib/project.php). התוכן עצמו — לפי sha256 תחת data/files/_project/.
            -- kind: file — קובץ במאגר; dir — תיקייה ריקה; media — הפניה לספריית המדיה (file_id).
            CREATE TABLE pf_files (
                id            INTEGER PRIMARY KEY,
                path          TEXT NOT NULL,
                path_key      TEXT NOT NULL,
                kind          TEXT NOT NULL CHECK (kind IN ('file','dir','media')),
                sha           TEXT NOT NULL DEFAULT '',
                size          INTEGER NOT NULL DEFAULT 0,
                file_id       TEXT,
                rev           INTEGER NOT NULL DEFAULT 1,
                created_by    TEXT NOT NULL DEFAULT '',
                created_at    TEXT NOT NULL,
                updated_by    TEXT NOT NULL DEFAULT '',
                updated_name  TEXT NOT NULL DEFAULT '',
                updated_at    TEXT NOT NULL,
                deleted_at    TEXT,
                deleted_by    TEXT,
                deleted_name  TEXT,
                deleted_batch TEXT
            );
            CREATE INDEX idx_pf_files_key ON pf_files(path_key) WHERE deleted_at IS NULL;
            CREATE INDEX idx_pf_files_sha ON pf_files(sha);
            CREATE TABLE pf_history (
                id      INTEGER PRIMARY KEY,
                file_id INTEGER NOT NULL,
                rev     INTEGER NOT NULL,
                path    TEXT NOT NULL,
                sha     TEXT NOT NULL,
                size    INTEGER NOT NULL,
                sub     TEXT NOT NULL DEFAULT '',
                name    TEXT NOT NULL DEFAULT '',
                at      TEXT NOT NULL,
                note    TEXT NOT NULL DEFAULT ''
            );
            CREATE INDEX idx_pf_history_file ON pf_history(file_id, id);
            CREATE INDEX idx_pf_history_sha ON pf_history(sha);
            SQL,
        16 => <<<'SQL'
            -- פרוסה M1: בנייה ופרסום של פרויקט הקבצים (lib/build.php). קובצי החבילה — לפי sha256 במאגר הקיים
            -- (data/files/_project/); כאן רק הרשומות. files: JSON של נתיב ← {sha, size, type, src, file_id?}.
            -- status: building · built · failed · sending · live · superseded. version — רק בשליחה (מונה משותף עם versions).
            CREATE TABLE pkg_builds (
                id        INTEGER PRIMARY KEY,
                status    TEXT NOT NULL,
                rev       INTEGER NOT NULL DEFAULT 0,
                print     TEXT NOT NULL DEFAULT '',
                opts      TEXT NOT NULL DEFAULT '{}',
                files     TEXT NOT NULL DEFAULT '{}',
                pages     TEXT NOT NULL DEFAULT '[]',
                notes     TEXT NOT NULL DEFAULT '[]',
                report    TEXT NOT NULL DEFAULT '{}',
                error     TEXT,
                version   INTEGER,
                manifest  TEXT,
                by_sub    TEXT NOT NULL,
                by_name   TEXT NOT NULL DEFAULT '',
                at        TEXT NOT NULL,
                built_at  TEXT,
                sent_at   TEXT,
                sent_by   TEXT
            );
            CREATE INDEX idx_pkg_builds_status ON pkg_builds(status, id);
            -- גושים שהאתר כבר אישר (asset_has / asset_put) — לא נשלחים שוב
            CREATE TABLE pkg_site_blobs (
                sha  TEXT NOT NULL,
                site TEXT NOT NULL,
                at   TEXT NOT NULL,
                PRIMARY KEY (sha, site)
            );
            -- הודעות בכלי (הודעת פרסום לבעל האתר, [ש-7])
            CREATE TABLE notices (
                id       INTEGER PRIMARY KEY,
                role_to  TEXT NOT NULL,
                kind     TEXT NOT NULL,
                text     TEXT NOT NULL,
                build_id INTEGER,
                by_name  TEXT NOT NULL DEFAULT '',
                at       TEXT NOT NULL,
                read_at  TEXT,
                read_by  TEXT
            );
            SQL,
        // N1: הסדר הידני של המילים. הוספה בלבד; המילוי הראשון — ב-PHP (words_order_fill), כי הוא משחזר את lex_sort (Collator)
        17 => <<<'SQL'
            ALTER TABLE words ADD COLUMN sort_order INTEGER;
            CREATE INDEX idx_words_sort ON words(sort_order, id);
            SQL,
        // N2: שדות מותאמים למילה (word_fields + words.extra), סל לשדה שנמחק, ונושאים מורחבים (סדר, צבע, תמונה, מוסתר).
        // הוספה בלבד. המילוי (שדות מובנים, סדר נושאים) — ב-PHP, ורק בפעולה שמשנה מבנה (lib/lexicon.php, fields_fill / topics_fill)
        18 => <<<'SQL'
            CREATE TABLE word_fields (
                key        TEXT PRIMARY KEY,
                label      TEXT NOT NULL,
                type       TEXT NOT NULL,
                col        TEXT,
                required   INTEGER NOT NULL DEFAULT 0,
                def        TEXT,
                help       TEXT NOT NULL DEFAULT '',
                options    TEXT NOT NULL DEFAULT '[]',
                published  INTEGER NOT NULL DEFAULT 1,
                dir        TEXT NOT NULL DEFAULT 'auto',
                sort_order INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE field_trash (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                at      TEXT NOT NULL,
                by_name TEXT NOT NULL DEFAULT '',
                field   TEXT NOT NULL,
                vals    TEXT NOT NULL
            );
            ALTER TABLE words ADD COLUMN extra TEXT NOT NULL DEFAULT '{}';
            ALTER TABLE topics ADD COLUMN sort_order INTEGER;
            ALTER TABLE topics ADD COLUMN color TEXT NOT NULL DEFAULT '';
            ALTER TABLE topics ADD COLUMN image TEXT NOT NULL DEFAULT '';
            ALTER TABLE topics ADD COLUMN hidden INTEGER NOT NULL DEFAULT 0;
            ALTER TABLE topic_trash ADD COLUMN row TEXT;
            SQL,
        // M5: טפסים ותיבת פניות (lib/forms.php). הוספה בלבד. הפנייה אינה שומרת IP / User-Agent / Referer; ה-IP — רק מגובב, בטבלת הקצב
        19 => <<<'SQL'
            CREATE TABLE form_entries (
                id         INTEGER PRIMARY KEY,
                form       TEXT NOT NULL,
                fields     TEXT NOT NULL DEFAULT '{}',
                word_id    INTEGER,
                page       TEXT NOT NULL DEFAULT '',
                test       INTEGER NOT NULL DEFAULT 0,
                at         TEXT NOT NULL,
                read_at    TEXT,
                read_by    TEXT,
                handled_at TEXT,
                handled_by TEXT
            );
            CREATE INDEX idx_form_entries_at ON form_entries(test, at);
            CREATE TABLE form_hits (
                ip_hash TEXT NOT NULL,
                at      INTEGER NOT NULL
            );
            CREATE INDEX idx_form_hits ON form_hits(ip_hash, at);
            CREATE INDEX idx_form_hits_at ON form_hits(at);
            CREATE TABLE form_rejects (
                day    TEXT NOT NULL,
                reason TEXT NOT NULL,
                n      INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (day, reason)
            );
            CREATE TABLE forms_trash (
                id       INTEGER PRIMARY KEY,
                entry_id INTEGER NOT NULL,
                row      TEXT NOT NULL,
                batch    TEXT NOT NULL DEFAULT '',
                at       TEXT NOT NULL,
                by_name  TEXT NOT NULL DEFAULT ''
            );
            ALTER TABLE pkg_builds ADD COLUMN forms TEXT;
            SQL,
        // N2b: הקלטה לכל "מקום" (רשימה, פריט, שדה) — בונה את recordings מחדש (SQLite אינו מסיר UNIQUE ב-ALTER). ב-PHP:
        // ספירה ו-checksum לפני ואחרי, ואם לא תואם — חריגה, והעסקה כולה מתבטלת (migrate_recordings_slots)
        20 => 'migrate_recordings_slots',
    ];
    foreach ($steps as $n => $sql) {
        if ($v >= $n) continue;
        $pdo->beginTransaction();
        try {
            // בקשה מקבילה הקדימה (קראנו את הגרסה לפני העסקה) — לא מריצים את השלב פעמיים
            if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() >= $n) { $pdo->commit(); continue; }
            str_starts_with($sql, 'migrate_') ? $sql($pdo) : $pdo->exec($sql);
            $pdo->exec("PRAGMA user_version = $n");
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

/** הסכמה של recordings מ-N2b. word_id — מזהה הפריט בתוך list (השם נשאר, כדי שכל שאילתה של A2/A3 תישאר). */
const REC_SCHEMA = <<<'SQL'
    CREATE TABLE %s (
        id            INTEGER PRIMARY KEY,
        list          TEXT NOT NULL DEFAULT 'words',  -- הרשימה ('words' — המילים)
        word_id       INTEGER,                        -- הפריט. הקלטה לא מחוברת — NULL
        field         TEXT NOT NULL DEFAULT 'audio',  -- המפתח של שדה השמע (הראשי — השדה שה-col שלו audio)
        state         TEXT NOT NULL DEFAULT 'site' CHECK (state IN ('site','tool','failed')),
        source        TEXT NOT NULL DEFAULT '',       -- הכתובת באתר. נשמרת תמיד, לעולם אינה מפתח
        file_id       TEXT,                           -- ספריית המדיה (files.id)
        name          TEXT NOT NULL DEFAULT '',
        duration      REAL,                           -- שניות; נמדד בדפדפן, שייך ל-file_id
        how           TEXT NOT NULL DEFAULT '',       -- linked · fetched · imported · replaced · uploaded · restored · deduped · moved
        at            TEXT NOT NULL,
        by_name       TEXT NOT NULL DEFAULT '',
        scan          TEXT NOT NULL DEFAULT '',       -- '' (לא נסרקה) · ok · missing · blocked · error · skipped
        scan_size     INTEGER,
        scan_type     TEXT NOT NULL DEFAULT '',
        scan_note     TEXT NOT NULL DEFAULT '',
        scan_at       TEXT,
        f_code        TEXT NOT NULL DEFAULT '',       -- למה ההבאה נכשלה (REC_REASONS)
        f_note        TEXT NOT NULL DEFAULT '',
        f_at          TEXT,
        deleted_at    TEXT,                           -- A3: סל — המקום שממנו נמחקה נזכר לשחזור
        deleted_by    TEXT NOT NULL DEFAULT '',
        deleted_list  TEXT,
        deleted_word  INTEGER,
        deleted_field TEXT,
        deleted_audio TEXT NOT NULL DEFAULT '',
        UNIQUE (list, word_id, field)
    )
    SQL;

/** ספירה ו-sha256 של עמודות הטבלה, לפי id. */
function migrate_sum(PDO $pdo, string $table, array $cols): array
{
    $h = hash_init('sha256');
    $n = 0;
    $st = $pdo->query('SELECT ' . implode(', ', array_map(fn($c) => "\"$c\"", $cols)) . " FROM $table ORDER BY id");
    while ($r = $st->fetch(PDO::FETCH_NUM)) { hash_update($h, json_encode($r, JSON_UNESCAPED_UNICODE) . "\n"); $n++; }
    return [$n, hash_final($h)];
}

/**
 * שלב 20 (N2b): recordings נבנית מחדש בתוך העסקה של migrate — CREATE חדשה → INSERT … SELECT → DROP הישנה → RENAME.
 * המזהים (id) נשמרים, ולכן rec_versions.rec_id ממשיך להצביע נכון. הרשומות הקיימות — של השדה הראשי (המפתח של השדה
 * שה-col שלו audio; בלי שורה ב-word_fields — 'audio'). לפני ואחרי: מספר השורות ו-checksum של כל העמודות המשותפות, ושל
 * rec_versions; לא תואם — חריגה, והעסקה מתבטלת: המסד נשאר כמו שהיה.
 */
function migrate_recordings_slots(PDO $pdo): void
{
    $has = fn(string $t) => (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = '$t'")->fetchColumn();
    $primary = 'audio';
    if ($has('word_fields')) $primary = (string)($pdo->query("SELECT key FROM word_fields WHERE col = 'audio'")->fetchColumn() ?: 'audio');
    $pdo->exec('DROP TABLE IF EXISTS recordings_n2b');
    $pdo->exec(sprintf(REC_SCHEMA, 'recordings_n2b'));
    if ($has('recordings')) {
        $old = array_column($pdo->query('PRAGMA table_info(recordings)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        $new = array_column($pdo->query('PRAGMA table_info(recordings_n2b)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        $common = array_values(array_intersect($new, $old));
        $vSum = fn() => $has('rec_versions') ? migrate_sum($pdo, 'rec_versions', array_column($pdo->query('PRAGMA table_info(rec_versions)')->fetchAll(PDO::FETCH_ASSOC), 'name')) : [0, ''];
        $before = migrate_sum($pdo, 'recordings', $common);
        $vBefore = $vSum();
        $cl = implode(', ', array_map(fn($c) => "\"$c\"", $common));
        $pdo->exec("INSERT INTO recordings_n2b ($cl) SELECT $cl FROM recordings");
        if (!in_array('field', $old, true)) {
            $pdo->prepare('UPDATE recordings_n2b SET field = ?')->execute([$primary]);
            $pdo->prepare('UPDATE recordings_n2b SET deleted_list = \'words\', deleted_field = ? WHERE deleted_word IS NOT NULL')->execute([$primary]);
        }
        $pdo->exec('DROP TABLE recordings');
        $pdo->exec('ALTER TABLE recordings_n2b RENAME TO recordings');
        $after = migrate_sum($pdo, 'recordings', $common);
        $vAfter = $vSum();
        if ($before !== $after || $vBefore !== $vAfter) {
            throw new RuntimeException("מיגרציה 20 בוטלה: ההקלטות לפני ואחרי אינן זהות (recordings {$before[0]} → {$after[0]}, rec_versions {$vBefore[0]} → {$vAfter[0]}). המסד לא השתנה");
        }
        $GLOBALS['kol_migrate_20'] = ['recordings' => $before[0], 'sha' => $before[1], 'versions' => $vBefore[0], 'versions_sha' => $vBefore[1]];
    } else {
        $pdo->exec('ALTER TABLE recordings_n2b RENAME TO recordings');
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_recordings_file ON recordings(file_id)');
}

function setting(string $k, ?string $default = null): ?string
{
    $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string)$v;
}

function setting_set(string $k, string $v): void
{
    db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')->execute([$k, $v]);
}
