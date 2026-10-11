<?php
/**
 * שכבת האחסון — SQLite דרך PDO.
 *
 * נבחר SQLite ולא MySQL כי אין פרטי התחברות לנהל והקובץ נוסע עם התיקייה,
 * וזה מה שכבר עובד בשרת הזה ב-claude-tasks. הסכימה כאן זהה ל-SCHEMA.sql
 * שבתיקיית האב; אותו קובץ הוא התיעוד, וזה המימוש. שינוי באחד מחייב את השני.
 *
 * המיגרציות הן CREATE TABLE IF NOT EXISTS בכוונה: פריסה על מסד קיים אינה
 * נופלת ואינה דורסת, ואין כאן שלב התקנה נפרד שמישהו צריך לזכור להריץ.
 */

declare(strict_types=1);

require_once __DIR__ . '/errors.php';

// ניתן לדריסה לפני הטעינה — כך הבדיקות רצות על מסד זמני ולא נוגעות
// בנתונים האמיתיים.
if (!defined('DB_FILE'))   define('DB_FILE',   __DIR__ . '/../data/recipes.sqlite');
if (!defined('MEDIA_DIR')) define('MEDIA_DIR', __DIR__ . '/../data/media');

// המגבלות (תקרת סרטון, מקצב, תקרת תמונה) אינן קבועים יותר. הן הגדרות
// ב-lib/settings.php: ציבוריות בטבלת app_settings, עם דריסה לכל משתמש.
// מי שצריך מגבלה שואל effectiveLimit() — לא קורא מספר מהקוד.

/** חתימת זמן אחידה. ISO-8601 ב-UTC — נשמר כטקסט, ומסתדר לקסיקוגרפית. */
function nowIso(): string {
    return gmdate('Y-m-d\TH:i:s\Z');
}

/**
 * PDO שבו כל טרנזקציה של כתיבה היא BEGIN IMMEDIATE.
 *
 * למה: BEGIN רגיל (DEFERRED) לוקח את נעילת הכתיבה רק בכתיבה הראשונה. אם
 * בינתיים תהליך אחר כתב — SQLite מחזיר "database is locked" מיד, בלי
 * busy_timeout, כי תמונת המסד שהטרנזקציה ראתה כבר ישנה. זה קרה כשהייבוא
 * ברקע (scout-worker.php) כתב במקביל לבקשות מהדפדפן. IMMEDIATE לוקח את
 * הנעילה בהתחלה, ושם busy_timeout כן ממתין.
 *
 * commit/rollBack ממומשים כאן ולא ב-PDO, כי PDO לא יודע על BEGIN שלא הוא שלח.
 */
final class AppPDO extends PDO {
    private bool $inTx = false;
    public function beginTransaction(): bool { $this->exec('BEGIN IMMEDIATE'); return $this->inTx = true; }
    public function commit(): bool { $this->exec('COMMIT'); $this->inTx = false; return true; }
    public function rollBack(): bool { if ($this->inTx) { $this->inTx = false; $this->exec('ROLLBACK'); } return true; }
    public function inTransaction(): bool { return $this->inTx; }
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dir = dirname(DB_FILE);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new AppError('לא ניתן ליצור את תיקיית הנתונים', 500);
    }

    $pdo = new AppPDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // WAL מאפשר קוראים במקביל לכותב — נחוץ כשכמה אנשים מעיינים בעוד אחד שומר.
    $pdo->exec('PRAGMA journal_mode = WAL');
    // בלעדיו כל ה-CASCADE וה-SET NULL שבסכימה אינם קיימים. SQLite מכבה
    // מפתחות זרים כברירת מחדל, וזו הנקודה שהכי קל לשכוח.
    $pdo->exec('PRAGMA foreign_keys = ON');
    // בלי זה, כותב שני נכשל מיד ב-SQLITE_BUSY במקום להמתין בתור.
    $pdo->exec('PRAGMA busy_timeout = 5000');

    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            username       TEXT    NOT NULL UNIQUE,
            email          TEXT    NOT NULL UNIQUE,
            password_hash  TEXT    NOT NULL,
            display_name   TEXT    NOT NULL,
            role           TEXT    NOT NULL DEFAULT 'user'
                           CHECK (role IN ('user','admin')),
            email_verified INTEGER NOT NULL DEFAULT 0,
            blocked        INTEGER NOT NULL DEFAULT 0,
            storage_used   INTEGER NOT NULL DEFAULT 0,
            limit_video_bytes INTEGER,   -- NULL = יורש מההגדרה הציבורית
            limit_quota_bytes INTEGER,   -- NULL = יורש מההגדרה הציבורית
            last_mail_at   TEXT,         -- מתי נשלח לאחרונה דוא״ל למשתמש
            last_mail_ok   INTEGER,      -- האם ה-MTA קיבל אותו. NULL = לא נשלח
            created_at     TEXT    NOT NULL
        );

        CREATE TABLE IF NOT EXISTS app_settings (
            key        TEXT PRIMARY KEY,
            value      INTEGER NOT NULL,
            updated_at TEXT    NOT NULL
        );

        -- יומן: כל בקשה ואירוע (lib/log.php). נמחק אחרי 30 יום / 50k שורות.
        CREATE TABLE IF NOT EXISTS app_log (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            at          TEXT    NOT NULL,
            level       TEXT    NOT NULL CHECK (level IN ('info','warn','error')),
            request_id  TEXT,                  -- קושר שורות מאותה בקשה
            user_id     INTEGER,               -- בלי FK: היומן שורד מחיקת משתמש
            username    TEXT,
            action      TEXT    NOT NULL,
            message     TEXT    NOT NULL DEFAULT '',
            meta        TEXT,                  -- JSON, שדות מרשימה סגורה בלבד
            ip          TEXT,
            user_agent  TEXT,
            duration_ms INTEGER
        );
        CREATE INDEX IF NOT EXISTS idx_app_log_at     ON app_log(at);
        CREATE INDEX IF NOT EXISTS idx_app_log_level  ON app_log(level, at);
        CREATE INDEX IF NOT EXISTS idx_app_log_action ON app_log(action, at);

        -- טוקן צפייה ביומן, שהמפתח מעביר. נשמר רק ה-hash.
        CREATE TABLE IF NOT EXISTS log_tokens (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            token_hash   TEXT    NOT NULL UNIQUE,
            label        TEXT    NOT NULL,
            created_by   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            created_at   TEXT    NOT NULL,
            expires_at   TEXT    NOT NULL,
            revoked_at   TEXT,
            last_used_at TEXT,
            uses         INTEGER NOT NULL DEFAULT 0
        );

        -- תיעוד פרטי של ייבוא: מה בדיוק חולץ מהמקור ברגע הייבוא. רואה רק
        -- המייבא. לא מוצג לציבור לעולם, ולא נספר במקצב. נמחק עם המתכון.
        CREATE TABLE IF NOT EXISTS import_snapshots (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            recipe_id    INTEGER NOT NULL UNIQUE REFERENCES recipes(id) ON DELETE CASCADE,
            user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            source_url   TEXT    NOT NULL,
            extracted_by TEXT    NOT NULL,   -- json-ld / microdata / headings
            raw          TEXT    NOT NULL,   -- JSON: title, ingredients[], sections[], yield, times, images, author…
            fetched_at   TEXT    NOT NULL
        );

        -- יומן ייבוא (lib/import_log.php): כל הבאה של דף מהרשת — מה היה בדף
        -- (אבחון) ומה חולץ ממנו (כלשונו), עם קישור למקור. לאבחון החילוץ.
        -- user_id בלי FK: היומן שורד מחיקת משתמש. נמחק אחרי 60 יום / 3000 שורות.
        CREATE TABLE IF NOT EXISTS import_log (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            at            TEXT    NOT NULL,
            user_id       INTEGER,
            username      TEXT,
            kind          TEXT    NOT NULL,   -- preview / scout-preview / scout-import / refresh / editor-save
            source_url    TEXT    NOT NULL,
            final_url     TEXT,               -- אחרי הפניות, כשהשתנתה
            host          TEXT    NOT NULL,
            ok            INTEGER NOT NULL DEFAULT 1,
            error         TEXT,
            method        TEXT,               -- json-ld / microdata / headings
            title         TEXT,
            ingredients_n INTEGER NOT NULL DEFAULT 0,
            steps_n       INTEGER NOT NULL DEFAULT 0,
            sections_n    INTEGER NOT NULL DEFAULT 0,
            images_n      INTEGER NOT NULL DEFAULT 0,
            videos_n      INTEGER NOT NULL DEFAULT 0,
            warnings      TEXT,               -- JSON
            diag          TEXT,               -- JSON: מה היה בדף
            extract       TEXT,               -- JSON: מה חולץ, כלשונו, והפענוח
            duration_ms   INTEGER,
            recipe_id     INTEGER,            -- בלי FK: הרשומה נשארת גם אחרי מחיקת המתכון
            scout_id      INTEGER
        );
        CREATE INDEX IF NOT EXISTS idx_import_log_at   ON import_log(at);
        CREATE INDEX IF NOT EXISTS idx_import_log_host ON import_log(host, at);

        -- סורק אתרים (כלי פרטי של המפתח): מועמדים שנמצאו בדפי רשימה / sitemap,
        -- מה סומן לייבוא ומה לדילוג, ומה כבר יובא (lib/scout.php).
        CREATE TABLE IF NOT EXISTS scout_items (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            url        TEXT    NOT NULL UNIQUE,
            title      TEXT    NOT NULL DEFAULT '',
            site       TEXT    NOT NULL,
            found_on   TEXT    NOT NULL,     -- הדף שבו נמצא הקישור
            status     TEXT    NOT NULL DEFAULT 'new'
                       CHECK (status IN ('new','wanted','skipped','imported','error')),
            recipe_id  INTEGER REFERENCES recipes(id) ON DELETE SET NULL,
            error      TEXT,
            created_at TEXT    NOT NULL,
            decided_at TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_scout_status ON scout_items(status, site);

        -- ייבוא ברקע מהסורק (lib/scout_job.php): שורה אחת. העובד בשרת רץ גם
        -- כשהדפדפן סגור, עד שהרשימה נגמרת או שעוצרים אותו.
        CREATE TABLE IF NOT EXISTS scout_job (
            id           INTEGER PRIMARY KEY CHECK (id = 1),
            state        TEXT    NOT NULL DEFAULT 'idle'
                         CHECK (state IN ('idle','running','stopped','done')),
            user_id      INTEGER,
            gap_sec      INTEGER NOT NULL DEFAULT 25,
            rewrite      INTEGER NOT NULL DEFAULT 0,
            started_at   TEXT,
            ended_at     TEXT,
            next_at      INTEGER NOT NULL DEFAULT 0,   -- unix: לא לפני — מרווח, או המתנה לסוף חסימה
            last_tick_at INTEGER NOT NULL DEFAULT 0,   -- unix: העובד חי? (שומר-סף מעיר אותו)
            done_n       INTEGER NOT NULL DEFAULT 0,
            fail_n       INTEGER NOT NULL DEFAULT 0,
            blocks_n     INTEGER NOT NULL DEFAULT 0,
            message      TEXT,
            recent       TEXT,                         -- JSON: 30 התוצאות האחרונות
            worker_key   TEXT    NOT NULL,             -- לקריאה לעובד (ומ-cron); קבוע
            base_url     TEXT                          -- הכתובת של האפליקציה, כדי שהעובד יעיר את עצמו
        );

        -- חיפוש מתכונים ברשת (lib/web_index.php): אינדקס משלנו של אתרים שהמפתח
        -- בחר. נבנה מקובצי ה-sitemap שלהם, מתעדכן פעם בשבוע — ורק חלקים שהשתנו.
        CREATE TABLE IF NOT EXISTS index_sites (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            host          TEXT    NOT NULL UNIQUE,
            name          TEXT    NOT NULL,
            start_url     TEXT    NOT NULL,      -- דף הבית או ה-sitemap שהמפתח הדביק
            enabled       INTEGER NOT NULL DEFAULT 1,
            added_at      TEXT    NOT NULL,
            last_crawl_at TEXT,
            last_error    TEXT,
            entries_n     INTEGER NOT NULL DEFAULT 0,
            next_crawl_at INTEGER NOT NULL DEFAULT 0,  -- unix
            crawl_state   TEXT                       -- JSON בזמן עדכון: התור, מתי התחיל
        );
        CREATE TABLE IF NOT EXISTS index_sitemaps (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            site_id    INTEGER NOT NULL REFERENCES index_sites(id) ON DELETE CASCADE,
            url        TEXT    NOT NULL,
            lastmod    TEXT,                         -- כפי שהאינדקס של האתר מדווח; לא השתנה — לא מביאים
            fetched_at TEXT,
            entries_n  INTEGER NOT NULL DEFAULT 0,
            UNIQUE (site_id, url)
        );
        CREATE TABLE IF NOT EXISTS index_entries (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            site_id    INTEGER NOT NULL REFERENCES index_sites(id) ON DELETE CASCADE,
            sitemap_id INTEGER,
            url        TEXT    NOT NULL UNIQUE,
            title      TEXT    NOT NULL,
            title_fixed INTEGER NOT NULL DEFAULT 0,    -- השם האמיתי מהדף (אחרי פתיחה) — העדכון השבועי לא דורס
            needs_title INTEGER NOT NULL DEFAULT 0,    -- בכתובת אין שם עברי (מספר, אנגלית) — שם יובא מהדף, ברקע
            skip       INTEGER NOT NULL DEFAULT 0,     -- הדף נבדק ואינו מתכון — לא בחיפוש, ולא חוזר בעדכון
            stems      TEXT    NOT NULL,             -- גזעים מופרדים ברווח, לחיפוש לפי תחילת מילה
            image      TEXT,                         -- קישור לתמונה באתר המקור (מה-sitemap או מהדף); לא עותק
            opens      INTEGER NOT NULL DEFAULT 0,   -- כמה פעמים נפתח אצלנו — לסדר בחיפוש
            lastmod    TEXT,
            seen_at    INTEGER NOT NULL DEFAULT 0    -- unix: העדכון האחרון שראה אותו
        );
        CREATE INDEX IF NOT EXISTS idx_index_entries_site ON index_entries(site_id, seen_at);
        -- רשת: שיתוף מתכון עם אנשים מסוימים, ומעקב אחרי מי שמפרסם (lib/network.php).
        -- מתכון פרטי שמשותף עם מישהו — גלוי לו (canView), ולא לאחרים.
        CREATE TABLE IF NOT EXISTS recipe_shares (
            recipe_id  INTEGER NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            created_at TEXT    NOT NULL,
            seen_at    TEXT,                                  -- הנמען פתח אותו; עד אז מסומן כחדש
            PRIMARY KEY (recipe_id, user_id)
        );
        CREATE INDEX IF NOT EXISTS idx_shares_user ON recipe_shares(user_id, created_at);
        CREATE TABLE IF NOT EXISTS follows (
            follower_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            followee_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            created_at  TEXT    NOT NULL,
            PRIMARY KEY (follower_id, followee_id)
        );
        CREATE INDEX IF NOT EXISTS idx_follows_followee ON follows(followee_id);
        -- המלצה: משתמש שולח לאחר מתכון מהרשת לעיון (קישור, לא העתק) — lib/network.php
        CREATE TABLE IF NOT EXISTS recommendations (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            from_user  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            to_user    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            entry_id   INTEGER,                              -- index_entries.id, כשהדף באינדקס
            url        TEXT    NOT NULL,
            title      TEXT    NOT NULL,
            site       TEXT,
            image      TEXT,
            note       TEXT,
            created_at TEXT    NOT NULL,
            seen_at    TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_reco_to ON recommendations(to_user, id);
        -- פרטיות ושליטה (lib/privacy.php, SPEC 15.7)
        CREATE TABLE IF NOT EXISTS user_prefs (
            user_id      INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
            discoverable INTEGER NOT NULL DEFAULT 1,       -- מופיע בחיפוש אנשים
            share_from   TEXT    NOT NULL DEFAULT 'all',   -- מי משתף וממליץ לי: all / following / none
            personalize  INTEGER NOT NULL DEFAULT 1,       -- התאמה אישית בהמלצות
            updated_at   TEXT
        );
        CREATE TABLE IF NOT EXISTS blocks (
            blocker_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            blocked_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            created_at TEXT    NOT NULL,
            PRIMARY KEY (blocker_id, blocked_id)
        );
        CREATE INDEX IF NOT EXISTS idx_blocks_blocked ON blocks(blocked_id);
        CREATE TABLE IF NOT EXISTS reports (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            reporter_id  INTEGER REFERENCES users(id) ON DELETE SET NULL,
            kind         TEXT    NOT NULL,                 -- recipe / comment / user
            target_id    INTEGER NOT NULL,
            target_owner INTEGER,                          -- של מי התוכן, ברגע הדיווח
            reason       TEXT    NOT NULL,
            note         TEXT,
            snapshot     TEXT,                             -- השם/הטקסט כפי שהיה
            status       TEXT    NOT NULL DEFAULT 'open',  -- open / handled / dismissed
            resolution   TEXT,
            created_at   TEXT    NOT NULL,
            resolved_at  TEXT,
            resolved_by  INTEGER
        );
        CREATE INDEX IF NOT EXISTS idx_reports_status ON reports(status, id);
        -- סריקת מתכון מתמונה (lib/scan.php): ממתינה לאישור המפתח, עד שתהיה טעינת קרדיטים.
        -- התמונות ב-data/scans/; נמחקות כשהסריקה טופלה.
        CREATE TABLE IF NOT EXISTS scans (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            status     TEXT    NOT NULL DEFAULT 'pending',   -- pending / done / rejected
            visibility TEXT    NOT NULL DEFAULT 'private',   -- מה שמי שסרק בחר
            note       TEXT,                                 -- הערה של מי שסרק
            attach     INTEGER NOT NULL DEFAULT 1,           -- לצרף את התמונות למתכון
            images     TEXT    NOT NULL,                     -- JSON: שמות הקבצים, לפי הסדר
            recipe_id  INTEGER REFERENCES recipes(id) ON DELETE SET NULL,
            recipe_ids TEXT,                                 -- JSON: כשנוצרו כמה מתכונים
            message    TEXT,                                 -- סיבת דחייה
            method     TEXT,                                 -- ai / manual
            created_at TEXT    NOT NULL,
            done_at    TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_scans_user ON scans(user_id, id);
        CREATE INDEX IF NOT EXISTS idx_scans_status ON scans(status, id);
        -- מתכון שנפתח מהחיפוש, אחרי חילוץ וניסוח מחדש — שבוע, כדי שפתיחה שנייה
        -- (של אותו משתמש או של אחר) לא תביא את הדף ולא תנסח שוב.
        CREATE TABLE IF NOT EXISTS web_cache (
            url        TEXT    PRIMARY KEY,
            draft      TEXT    NOT NULL,
            rewritten  INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL
        );

        -- מה יש לי בבית: המזווה של כל משתמש, ומוצרי היסוד שכיבה (lib/pantry.php)
        CREATE TABLE IF NOT EXISTS pantry_items (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            name       TEXT    NOT NULL,
            norm       TEXT    NOT NULL,     -- מנורמל, להשוואה ולמניעת כפילות
            source     TEXT    NOT NULL DEFAULT 'text' CHECK (source IN ('text','photo')),
            created_at TEXT    NOT NULL,
            UNIQUE (user_id, norm)
        );
        CREATE TABLE IF NOT EXISTS pantry_staples_off (
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            name       TEXT    NOT NULL,
            PRIMARY KEY (user_id, name)
        );

        CREATE TABLE IF NOT EXISTS user_tokens (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            kind       TEXT    NOT NULL CHECK (kind IN ('verify_email','reset_password')),
            token_hash TEXT    NOT NULL UNIQUE,
            expires_at TEXT    NOT NULL,
            used_at    TEXT,
            created_at TEXT    NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_tokens_user ON user_tokens(user_id, kind);
        -- להישאר מחובר: עוגייה ארוכה (selector:validator) במקום סשן שנמחק אחרי כ-24 דקות בשרת.
        -- במסד רק hash של ה-validator. נמחק ביציאה, באיפוס סיסמה, בחסימה ועם החשבון.
        CREATE TABLE IF NOT EXISTS login_tokens (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            selector     TEXT    NOT NULL UNIQUE,
            token_hash   TEXT    NOT NULL,
            created_at   TEXT    NOT NULL,
            last_used_at TEXT    NOT NULL,
            expires_at   TEXT    NOT NULL,
            user_agent   TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_login_tokens_user ON login_tokens(user_id);

        CREATE TABLE IF NOT EXISTS recipes (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            title         TEXT    NOT NULL,
            visibility    TEXT    NOT NULL DEFAULT 'private'
                          CHECK (visibility IN ('private','public')),
            servings      INTEGER,               -- מספר מנות — בסיס להמרה. NULL = אין
            yield_text    TEXT,                  -- עוגה אחת / תבנית 26 — כשמספר לא מתאים
            difficulty    TEXT    CHECK (difficulty IN ('easy','medium','hard')),
            work_minutes  INTEGER,
            wait_minutes  INTEGER,
            tips          TEXT,
            comments_open INTEGER NOT NULL DEFAULT 1,
            source_url    TEXT,                  -- מתכון שיובא מהרשת: הקישור למקור (קרדיט)
            source_name   TEXT,                  -- שם האתר
            source_author TEXT,                  -- שם הכותב במקור
            imported_at   TEXT,
            source_rewritten INTEGER NOT NULL DEFAULT 0,   -- השלבים נוסחו מחדש: הקרדיט הוא ״מבוסס על״
            main_media_id INTEGER,
            search_text   TEXT,
            created_at    TEXT    NOT NULL,
            updated_at    TEXT    NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_recipes_owner      ON recipes(owner_id);
        CREATE INDEX IF NOT EXISTS idx_recipes_public     ON recipes(visibility, updated_at);
        CREATE INDEX IF NOT EXISTS idx_recipes_difficulty ON recipes(difficulty);

        CREATE TABLE IF NOT EXISTS sections (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            recipe_id INTEGER NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
            name      TEXT,
            position  INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_sections_recipe ON sections(recipe_id, position);

        CREATE TABLE IF NOT EXISTS products (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            name          TEXT NOT NULL UNIQUE,
            name_norm     TEXT NOT NULL,
            grams_per_cup REAL,
            created_at    TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_products_norm ON products(name_norm);

        CREATE TABLE IF NOT EXISTS ingredients (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            section_id INTEGER NOT NULL REFERENCES sections(id) ON DELETE CASCADE,
            free_text  TEXT    NOT NULL,
            amount_min REAL,
            amount_max REAL,
            unit       TEXT    CHECK (unit IN ('gram','kg','ml','liter','cup',
                                              'tbsp','tsp','unit','package','pinch')),
            product_id INTEGER REFERENCES products(id) ON DELETE SET NULL,
            optional   INTEGER NOT NULL DEFAULT 0,
            position   INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_ingredients_section ON ingredients(section_id, position);
        CREATE INDEX IF NOT EXISTS idx_ingredients_product ON ingredients(product_id);

        CREATE TABLE IF NOT EXISTS steps (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            section_id INTEGER NOT NULL REFERENCES sections(id) ON DELETE CASCADE,
            position   INTEGER NOT NULL,
            text       TEXT    NOT NULL,
            media_id   INTEGER,
            CHECK (position > 0)
        );
        CREATE INDEX IF NOT EXISTS idx_steps_section ON steps(section_id, position);

        CREATE TABLE IF NOT EXISTS media (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            recipe_id   INTEGER NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
            uploader_id INTEGER NOT NULL REFERENCES users(id)   ON DELETE CASCADE,
            kind        TEXT    NOT NULL CHECK (kind IN ('image','video')),
            source      TEXT    NOT NULL CHECK (source IN ('upload','link')),
            path_or_url TEXT    NOT NULL,
            mime        TEXT,
            bytes       INTEGER NOT NULL DEFAULT 0,
            position    INTEGER NOT NULL DEFAULT 0,
            created_at  TEXT    NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_media_recipe   ON media(recipe_id, position);
        CREATE INDEX IF NOT EXISTS idx_media_uploader ON media(uploader_id);

        CREATE TABLE IF NOT EXISTS tags (
            id       INTEGER PRIMARY KEY AUTOINCREMENT,
            axis     TEXT    NOT NULL CHECK (axis IN ('topic','method','kosher',
                                                      'suitable','cuisine')),
            name     TEXT    NOT NULL,
            position INTEGER NOT NULL DEFAULT 0,
            UNIQUE (axis, name)
        );

        CREATE TABLE IF NOT EXISTS recipe_tags (
            recipe_id INTEGER NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
            tag_id    INTEGER NOT NULL REFERENCES tags(id)    ON DELETE CASCADE,
            PRIMARY KEY (recipe_id, tag_id)
        );
        CREATE INDEX IF NOT EXISTS idx_recipe_tags_tag ON recipe_tags(tag_id);

        -- אוספים (SPEC 15.3, lib/collections.php): רשימה בעלת שם, ממתכונים מכל מקור.
        -- is_saved: האוסף שמורים — אחד למשתמש, לא נמחק; ♡ מוסיף אליו. המועדפים עברו אליו.
        -- visibility: private / public (בפרופיל). שיתוף עם אנשים — בנפרד, ב-collection_shares.
        CREATE TABLE IF NOT EXISTS collections (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            name        TEXT    NOT NULL,
            icon        TEXT    NOT NULL DEFAULT '📁',
            description TEXT    NOT NULL DEFAULT '',
            visibility  TEXT    NOT NULL DEFAULT 'private' CHECK (visibility IN ('private', 'public')),
            is_saved    INTEGER NOT NULL DEFAULT 0,
            created_at  TEXT    NOT NULL,
            updated_at  TEXT    NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_collections_owner ON collections(owner_id);
        CREATE UNIQUE INDEX IF NOT EXISTS idx_collections_saved ON collections(owner_id) WHERE is_saved = 1;
        -- פריט: מתכון באפליקציה (הפניה; נמחק — recipe_id ריק והשם נשאר), או מתכון מהרשת בלי
        -- לייבא (קישור + שם + תמונה, כמו המלצה; entry_id — לפתיחה דרך החיפוש ברשת).
        CREATE TABLE IF NOT EXISTS collection_items (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            collection_id INTEGER NOT NULL REFERENCES collections(id) ON DELETE CASCADE,
            kind          TEXT    NOT NULL CHECK (kind IN ('recipe', 'web')),
            recipe_id     INTEGER REFERENCES recipes(id) ON DELETE SET NULL,
            entry_id      INTEGER REFERENCES index_entries(id) ON DELETE SET NULL,
            url           TEXT,
            title         TEXT    NOT NULL,
            image         TEXT,
            site          TEXT,
            position      INTEGER NOT NULL,
            added_at      TEXT    NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_collection_items ON collection_items(collection_id, position);
        CREATE INDEX IF NOT EXISTS idx_collection_items_recipe ON collection_items(recipe_id);
        CREATE UNIQUE INDEX IF NOT EXISTS idx_collection_items_r ON collection_items(collection_id, recipe_id) WHERE recipe_id IS NOT NULL;
        CREATE UNIQUE INDEX IF NOT EXISTS idx_collection_items_w ON collection_items(collection_id, url) WHERE kind = 'web';
        -- שיתוף אוסף: הנמען רואה את האוסף, ואת המתכונים הפרטיים של בעל האוסף שבתוכו (החלטה 3)
        CREATE TABLE IF NOT EXISTS collection_shares (
            collection_id INTEGER NOT NULL REFERENCES collections(id) ON DELETE CASCADE,
            user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            created_at    TEXT    NOT NULL,
            seen_at       TEXT,
            PRIMARY KEY (collection_id, user_id)
        );
        CREATE INDEX IF NOT EXISTS idx_collection_shares_user ON collection_shares(user_id);

        -- מעגלים (SPEC 15.4, lib/circles.php): רשימת אנשים בעלת שם שרק הבעלים רואה.
        -- שיתוף עם מעגל = עם מי שבו עכשיו (החלטה 4): הקישור נשמר ב-recipe_circles /
        -- collection_circles, ולכל חבר נכתבת שורת שיתוף רגילה (direct = 0) שמתעדכנת
        -- כשהמעגל משתנה. כך כל הקוד שקורא שיתופים — רשימות, חדש, חסימה — לא השתנה.
        CREATE TABLE IF NOT EXISTS circles (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_id   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            name       TEXT    NOT NULL,
            icon       TEXT    NOT NULL DEFAULT '👥',
            created_at TEXT    NOT NULL,
            updated_at TEXT    NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_circles_owner ON circles(owner_id);
        CREATE TABLE IF NOT EXISTS circle_members (
            circle_id INTEGER NOT NULL REFERENCES circles(id) ON DELETE CASCADE,
            user_id   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            added_at  TEXT    NOT NULL,
            PRIMARY KEY (circle_id, user_id)
        );
        CREATE INDEX IF NOT EXISTS idx_circle_members_user ON circle_members(user_id);
        CREATE TABLE IF NOT EXISTS recipe_circles (
            recipe_id  INTEGER NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
            circle_id  INTEGER NOT NULL REFERENCES circles(id) ON DELETE CASCADE,
            created_at TEXT    NOT NULL,
            PRIMARY KEY (recipe_id, circle_id)
        );
        CREATE INDEX IF NOT EXISTS idx_recipe_circles_circle ON recipe_circles(circle_id);
        CREATE TABLE IF NOT EXISTS collection_circles (
            collection_id INTEGER NOT NULL REFERENCES collections(id) ON DELETE CASCADE,
            circle_id     INTEGER NOT NULL REFERENCES circles(id) ON DELETE CASCADE,
            created_at    TEXT    NOT NULL,
            PRIMARY KEY (collection_id, circle_id)
        );
        CREATE INDEX IF NOT EXISTS idx_collection_circles_circle ON collection_circles(circle_id);
        -- מי שהסיר את עצמו משיתוף (הסר מהרשימה שלי) — לא יחזור דרך מעגל. שיתוף ישיר חדש מבטל.
        -- kind: recipe / collection; target_id בלי מפתח זר (שתי טבלאות). מזהים לא חוזרים (AUTOINCREMENT).
        CREATE TABLE IF NOT EXISTS share_optouts (
            kind       TEXT    NOT NULL CHECK (kind IN ('recipe', 'collection')),
            target_id  INTEGER NOT NULL,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            created_at TEXT    NOT NULL,
            PRIMARY KEY (kind, target_id, user_id)
        );

        -- בשבילך (SPEC 15.5, lib/foryou.php): מה המשתמש עשה — רק בשביל ההמלצות. נרשם רק כשהתאמה
        -- אישית דלוקה, נמחק אחרי 180 יום ועם החשבון. kind: open / cook / shop / foryou_click /
        -- not_interested (מתכון או ערך מהרשת) / less_topic (value = מזהה תג נושא).
        CREATE TABLE IF NOT EXISTS user_events (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            kind       TEXT    NOT NULL,
            recipe_id  INTEGER REFERENCES recipes(id) ON DELETE CASCADE,
            entry_id   INTEGER REFERENCES index_entries(id) ON DELETE CASCADE,
            value      TEXT,
            created_at TEXT    NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_user_events ON user_events(user_id, kind, created_at);
        -- מה אני אוהב (התחלה קרה): נושאים — מעדיפים; תזונה (ציר מתאים ל) — מסננת (החלטה 6)
        CREATE TABLE IF NOT EXISTS user_taste (
            user_id    INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
            topics     TEXT    NOT NULL DEFAULT '[]',
            diet       TEXT    NOT NULL DEFAULT '[]',
            updated_at TEXT    NOT NULL
        );

        -- טעם מהניתוח היומי (lib/taste_job.php): צ'אט עם שעון קורא את יומן השימוש פעם ביום
        -- וכותב לכל לקוח תקציר, משקלות ובחירות. רק כשהתאמה אישית דלוקה; כיבוי מוחק.
        CREATE TABLE IF NOT EXISTS ai_taste (
            user_id    INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
            summary    TEXT    NOT NULL DEFAULT '',
            profile    TEXT    NOT NULL DEFAULT '{}',
            picks      TEXT    NOT NULL DEFAULT '[]',
            updated_at TEXT    NOT NULL
        );

        -- קהילות (lib/communities.php, SPEC 15.4ב): הצטרפות בהזמנה ובאישור. מתכון שבעליו הוסיף
        -- לקבוצה משותף עם החברים הפעילים — שיתוף ממומש (recipe_shares direct = 0, circleSync).
        CREATE TABLE IF NOT EXISTS communities (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            name        TEXT    NOT NULL,
            icon        TEXT    NOT NULL DEFAULT '',
            description TEXT    NOT NULL DEFAULT '',
            created_at  TEXT    NOT NULL,
            updated_at  TEXT    NOT NULL
        );
        CREATE TABLE IF NOT EXISTS community_members (
            community_id INTEGER NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
            user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            role         TEXT    NOT NULL CHECK (role IN ('owner', 'admin', 'member')),
            status       TEXT    NOT NULL CHECK (status IN ('invited', 'active')),
            invited_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at   TEXT    NOT NULL,
            joined_at    TEXT,
            PRIMARY KEY (community_id, user_id)
        );
        CREATE INDEX IF NOT EXISTS idx_community_members_user ON community_members(user_id, status);
        CREATE TABLE IF NOT EXISTS community_recipes (
            community_id INTEGER NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
            recipe_id    INTEGER NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
            added_by     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            added_at     TEXT    NOT NULL,
            PRIMARY KEY (community_id, recipe_id)
        );
        CREATE INDEX IF NOT EXISTS idx_community_recipes_recipe ON community_recipes(recipe_id);

        -- התראות לטלפון (lib/push.php): המכשירים, עד איפה צלצלנו ונמסר, ומפתח VAPID של השרת.
        -- הצלצול ריק — התוכן נשאל מהשרת (push-pending) — ולכן אין כאן מפתחות הצפנה של המכשיר.
        CREATE TABLE IF NOT EXISTS push_subs (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            endpoint   TEXT    NOT NULL UNIQUE,
            fails      INTEGER NOT NULL DEFAULT 0,
            last_ok_at TEXT,
            created_at TEXT    NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_push_subs_user ON push_subs(user_id);
        CREATE TABLE IF NOT EXISTS push_state (
            user_id      INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
            pinged_at    TEXT,
            delivered_at TEXT
        );
        CREATE TABLE IF NOT EXISTS push_vapid (
            id          INTEGER PRIMARY KEY CHECK (id = 1),
            private_pem TEXT    NOT NULL,
            public_key  TEXT    NOT NULL,
            created_at  TEXT    NOT NULL
        );

        -- מרכז פעילות (SPEC 15.6, lib/activity.php): מתי פתחתי אותו לאחרונה — מה שאחרי זה חדש.
        -- הפעילות עצמה נגזרת מהטבלאות הקיימות (שיתופים, המלצות, מעקב, מתכונים, תגובות).
        CREATE TABLE IF NOT EXISTS activity_seen (
            user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
            seen_at TEXT    NOT NULL
        );

        -- מיגרציות חד־פעמיות על נתונים: שם ומתי רצה. מה שרשום כאן — לא ירוץ שוב,
        -- גם אם המצב שהוא תיקן חזר (משתמש שהסיר נושא לא יקבל אותו בחזרה).
        CREATE TABLE IF NOT EXISTS migrations (
            name    TEXT PRIMARY KEY,
            done_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS favorites (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            recipe_id      INTEGER REFERENCES recipes(id) ON DELETE SET NULL,
            title_snapshot TEXT    NOT NULL,
            created_at     TEXT    NOT NULL,
            UNIQUE (user_id, recipe_id)
        );
        CREATE INDEX IF NOT EXISTS idx_favorites_user ON favorites(user_id);

        CREATE TABLE IF NOT EXISTS comments (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            recipe_id  INTEGER NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
            user_id    INTEGER NOT NULL REFERENCES users(id)   ON DELETE CASCADE,
            parent_id  INTEGER REFERENCES comments(id) ON DELETE CASCADE,
            visibility TEXT    NOT NULL DEFAULT 'public'
                       CHECK (visibility IN ('public','private_note')),
            text       TEXT    NOT NULL,
            media_id   INTEGER REFERENCES media(id) ON DELETE SET NULL,
            created_at TEXT    NOT NULL,
            edited_at  TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_comments_recipe ON comments(recipe_id, visibility, created_at);
        CREATE INDEX IF NOT EXISTS idx_comments_user   ON comments(user_id, visibility);

        CREATE TABLE IF NOT EXISTS shopping_lists (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            name       TEXT,
            created_at TEXT    NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_lists_user ON shopping_lists(user_id, created_at);

        CREATE TABLE IF NOT EXISTS shopping_list_recipes (
            list_id   INTEGER NOT NULL REFERENCES shopping_lists(id) ON DELETE CASCADE,
            recipe_id INTEGER REFERENCES recipes(id) ON DELETE SET NULL,
            factor    REAL    NOT NULL DEFAULT 1,   -- המרת מנות של המתכון ברשימה הזאת
            PRIMARY KEY (list_id, recipe_id)
        );

        CREATE TABLE IF NOT EXISTS shopping_list_items (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            list_id    INTEGER NOT NULL REFERENCES shopping_lists(id) ON DELETE CASCADE,
            product_id INTEGER REFERENCES products(id) ON DELETE SET NULL,
            label      TEXT    NOT NULL,
            amount     REAL,
            unit       TEXT,
            free_text  TEXT,
            optional   INTEGER NOT NULL DEFAULT 0,
            manual     INTEGER NOT NULL DEFAULT 0,
            checked    INTEGER NOT NULL DEFAULT 0,
            position   INTEGER NOT NULL DEFAULT 0,
            amount_max REAL,                        -- טווח: 300–400
            recipe_count INTEGER NOT NULL DEFAULT 1 -- מכמה מתכונים הגיעה השורה
        );
        CREATE INDEX IF NOT EXISTS idx_list_items ON shopping_list_items(list_id, position);

        CREATE TABLE IF NOT EXISTS cooking_progress (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            step_id INTEGER NOT NULL REFERENCES steps(id) ON DELETE CASCADE,
            done_at TEXT    NOT NULL,
            PRIMARY KEY (user_id, step_id)
        );
    ");

    seedTags($pdo);
    addColumnIfMissing($pdo, 'users', 'limit_video_bytes', 'INTEGER');
    addColumnIfMissing($pdo, 'users', 'limit_quota_bytes', 'INTEGER');
    addColumnIfMissing($pdo, 'users', 'last_mail_at', 'TEXT');
    addColumnIfMissing($pdo, 'users', 'last_mail_ok', 'INTEGER');
    addColumnIfMissing($pdo, 'recipes', 'yield_text', 'TEXT');
    addColumnIfMissing($pdo, 'index_entries', 'needs_title', 'INTEGER NOT NULL DEFAULT 0');
    addColumnIfMissing($pdo, 'index_entries', 'skip', 'INTEGER NOT NULL DEFAULT 0');
    // תמונה לתוצאות החיפוש. כשהעמודה נוספת — כל חלקי ה-sitemap נקראים מחדש
    // פעם אחת (בלי זה חלק שלא השתנה מדולג, והמתכונים שבו נשארים בלי תמונה).
    if (addColumnIfMissing($pdo, 'index_entries', 'image', 'TEXT')) {
        $pdo->exec('UPDATE index_sitemaps SET lastmod = NULL');
        $pdo->exec('UPDATE index_sites SET next_crawl_at = 0 WHERE crawl_state IS NULL');
    }
    addColumnIfMissing($pdo, 'index_entries', 'opens', 'INTEGER NOT NULL DEFAULT 0');
    // נבדק שיש בדף מתכון (1), או עוד לא (NULL). מה שאינו מתכון — skip = 1. כשהעמודה נוספת:
    // מה שכבר חולץ בהצלחה (השם תוקן מהדף, או נפתח ונשמר במטמון) — נחשב נבדק.
    if (addColumnIfMissing($pdo, 'index_entries', 'verified', 'INTEGER')) {
        $pdo->exec('UPDATE index_entries SET verified = 1 WHERE skip = 0 AND (title_fixed = 1 OR url IN (SELECT url FROM web_cache))');
    }
    foreach (['source_url', 'source_name', 'source_author', 'imported_at'] as $col) {
        addColumnIfMissing($pdo, 'recipes', $col, 'TEXT');
    }
    addColumnIfMissing($pdo, 'recipes', 'source_rewritten', 'INTEGER NOT NULL DEFAULT 0');
    // מתכון מהרשת — פרטי בלבד: מה שפורסם כציבורי לפני הכלל הזה הופך לפרטי.
    if ($pdo->query("SELECT EXISTS (SELECT 1 FROM recipes WHERE visibility = 'public' AND COALESCE(source_url, '') != '')")->fetchColumn()) {
        $pdo->exec("UPDATE recipes SET visibility = 'private' WHERE visibility = 'public' AND COALESCE(source_url, '') != ''");
    }
    // הורדות לחיפוש ברשת: כמה משתמשים שמרו את אותו דף (recipes.source_url)
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_recipes_source ON recipes(source_url)');
    // שיתוף ישיר (1) או דרך מעגל בלבד (0) — lib/circles.php
    addColumnIfMissing($pdo, 'recipe_shares', 'direct', 'INTEGER NOT NULL DEFAULT 1');
    addColumnIfMissing($pdo, 'collection_shares', 'direct', 'INTEGER NOT NULL DEFAULT 1');
    addColumnIfMissing($pdo, 'shopping_list_recipes', 'factor', 'REAL NOT NULL DEFAULT 1');
    addColumnIfMissing($pdo, 'shopping_list_items', 'amount_max', 'REAL');
    addColumnIfMissing($pdo, 'shopping_list_items', 'recipe_count', 'INTEGER NOT NULL DEFAULT 1');

    // ריפוי חד־כיווני: מנהל שנוצר לפני שהמשתמש הראשון אומת אוטומטית.
    // בלי זה, אם mail() לא פעל בשרת, המנהל היחיד נעול בחוץ לתמיד.
    // העדכון אידמפוטנטי — בפריסות הבאות הוא לא משנה דבר.
    $pdo->exec("UPDATE users SET email_verified = 1 WHERE role = 'admin' AND email_verified = 0");

    // ── מיגרציות חד־פעמיות על נתונים (טבלת migrations) ──
    // נושא לפי השם למתכונים קיימים שאין להם נושא (SPEC 15.1). כשרות — לא.
    runOnce($pdo, 'topics-backfill', function (PDO $pdo) {
        require_once __DIR__ . '/catalog.php';
        catalogBackfillTopics($pdo);
    });
    // שוב, פעם אחת: מתכונים שנוספו בעורך אחרי הסבב הראשון — שם לא ניחשו נושא עד עכשיו
    runOnce($pdo, 'topics-backfill-2', function (PDO $pdo) {
        require_once __DIR__ . '/catalog.php';
        catalogBackfillTopics($pdo);
    });
    // המועדפים עוברים לאוסף "שמורים" של כל משתמש (SPEC 15.3, החלטה 2), עם הסדר והשם שנשמר.
    runOnce($pdo, 'favorites-to-saved', function (PDO $pdo) {
        $users = $pdo->query('SELECT DISTINCT user_id FROM favorites')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($users as $uid) {
            $cid = savedCollectionId($pdo, (int) $uid);
            $st = $pdo->prepare('SELECT recipe_id, title_snapshot, created_at FROM favorites WHERE user_id = ? ORDER BY created_at, id');
            $st->execute([$uid]);
            $ins = $pdo->prepare("INSERT OR IGNORE INTO collection_items (collection_id, kind, recipe_id, title, position, added_at) VALUES (?, 'recipe', ?, ?, ?, ?)");
            foreach ($st->fetchAll() as $i => $f) $ins->execute([$cid, $f['recipe_id'], $f['title_snapshot'], $i + 1, $f['created_at']]);
        }
    });
    // דפי קבוצה (טקסונומיה) שנכנסו לאינדקס כמתכונים — מחלבות גד: /recipe_quantity/100-עוגיות/.
    // מעכשיו INDEX_SKIP_URL לא מכניס אותם; מה שכבר בפנים — יוצא (לא מתכון, ולא נבדק שוב)
    runOnce($pdo, 'index-drop-taxonomy', function (PDO $pdo) {
        require_once __DIR__ . '/web_index.php';
        $up = $pdo->prepare('UPDATE index_entries SET skip = 1, verified = 0, needs_title = 0 WHERE id = ?');
        foreach ($pdo->query('SELECT id, url FROM index_entries WHERE skip = 0')->fetchAll() as $e) {
            if (preg_match(INDEX_SKIP_URL, urldecode((string) parse_url($e['url'], PHP_URL_PATH)))) $up->execute([(int) $e['id']]);
        }
    });
    // תוויות אישיות — לא ייבנו (SPEC 15.10, החלטה 1: רק אוספים). הטבלאות ריקות ונמחקות.
    runOnce($pdo, 'drop-user-tags', function (PDO $pdo) {
        $has = fn($t) => (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = '$t'")->fetchColumn();
        foreach (['recipe_user_tags', 'user_tags'] as $t) {
            if ($has($t) && !(int) $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn()) $pdo->exec("DROP TABLE $t");
        }
    });

    // ריפוי שני: מערכת עם משתמשים אך בלי אף מנהל. לא אמור לקרות — הראשון
    // נוצר כמנהל — אבל אם קרה (שחזור מסד, עריכה ידנית), אין מי שיתקן.
    // הוותיק ביותר הופך למנהל, ולכן גם למפתח. אידמפוטנטי: ברגע שיש מנהל
    // השאילתה אינה נוגעת בדבר.
    $pdo->exec("UPDATE users SET role = 'admin'
                 WHERE id = (SELECT MIN(id) FROM users)
                   AND NOT EXISTS (SELECT 1 FROM users WHERE role = 'admin')");
}

/**
 * מריץ מיגרציה על נתונים פעם אחת בחיי המסד, בטרנזקציה: נכשלה — לא נרשמה, ותנוסה
 * שוב בבקשה הבאה. הבדיקה זולה (שורה אחת במפתח ראשי) ולכן בטוחה בכל בקשה.
 */
function runOnce(PDO $pdo, string $name, callable $fn): void {
    $st = $pdo->prepare('SELECT 1 FROM migrations WHERE name = ?');
    $st->execute([$name]);
    $done = (bool) $st->fetchColumn();
    $st->closeCursor();
    if ($done) return;
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        // שוב, בתוך הנעילה: בקשה מקבילה אולי הקדימה אותנו
        $st->execute([$name]);
        $done = (bool) $st->fetchColumn();
        $st->closeCursor();
        if (!$done) $fn($pdo);
        $pdo->prepare('INSERT OR IGNORE INTO migrations (name, done_at) VALUES (?, ?)')->execute([$name, nowIso()]);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

/** האוסף "שמורים" של משתמש — נוצר בפעם הראשונה שצריך אותו. */
function savedCollectionId(PDO $pdo, int $userId): int {
    $st = $pdo->prepare('SELECT id FROM collections WHERE owner_id = ? AND is_saved = 1');
    $st->execute([$userId]);
    $id = $st->fetchColumn();
    $st->closeCursor();
    if ($id) return (int) $id;
    $now = nowIso();
    $pdo->prepare("INSERT INTO collections (owner_id, name, icon, is_saved, created_at, updated_at) VALUES (?, 'שמורים', '♡', 1, ?, ?)")
        ->execute([$userId, $now, $now]);
    return (int) $pdo->lastInsertId();
}

/**
 * מוסיף עמודה לטבלה קיימת, פעם אחת. CREATE TABLE IF NOT EXISTS אינו נוגע
 * בטבלה שכבר קיימת, ולכן עמודה חדשה חייבת מסלול משלה — אחרת מסד שנוצר
 * לפני השינוי היה נשאר בלעדיה, והשאילתה הראשונה הייתה נופלת בשרת.
 */
function addColumnIfMissing(PDO $pdo, string $table, string $column, string $type): bool {
    foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll() as $col) {
        if ($col['name'] === $column) return false;
    }
    $pdo->exec("ALTER TABLE $table ADD COLUMN $column $type");
    return true;
}

/**
 * הצירים הסגורים (סעיף 4 באפיון) נזרעים פעם אחת ומנוהלים אחר כך על ידי
 * המנהל מתוך האפליקציה. הזריעה מדלגת על מה שקיים, ולכן שינוי או מחיקה
 * שהמנהל עשה אינם חוזרים בפריסה הבאה — ותג שנמחק בכוונה לא יצוץ שוב.
 */
function seedTags(PDO $pdo): void {
    $seed = [
        'topic'    => ['עוגות','עוגיות','מאפים','בצקים','מנה עיקרית','מנת פתיחה',
                       'מרקים','סלטים','תוספות','קינוחים','שתייה'],
        'method'   => ['אפייה','בישול','טיגון','ללא בישול','גריל','אידוי'],
        'kosher'   => ['חלבי','בשרי','פרווה'],
        'suitable' => ['פסח','טבעוני','צמחוני','ללא גלוטן','ללא לקטוז'],
        'cuisine'  => ['מרוקאי','איטלקי','אסייתי','מזרחי','אשכנזי','אמריקאי'],
    ];

    $seeded = (int) $pdo->query('SELECT COUNT(*) c FROM tags')->fetch()['c'];
    if ($seeded > 0) return;

    $st = $pdo->prepare('INSERT INTO tags (axis, name, position) VALUES (?,?,?)');
    foreach ($seed as $axis => $names) {
        foreach (array_values($names) as $i => $name) {
            $st->execute([$axis, $name, $i + 1]);
        }
    }
}

/**
 * נרמול טקסט לחיפוש (סעיף 5): ניקוד, גרשיים ופיסוק מוסרים, רווחים מכווצים.
 *
 * זה מה שמאפשר למצוא "גבינה 5%" כשמקלידים "גבינה 5", ו"עוגיות" בתוך
 * "עוגיות שוקולד". מה שזה לא עושה — ולא מתיימר — הוא נטיות: "עוגייה"
 * לא יימצא מחיפוש "עוגיות". זה כתוב בפרק 5 באפיון, ולא נשבר כאן בשקט.
 */
function normalizeText(string $text): string {
    // ניקוד עברי: U+0591..U+05C7
    $text = preg_replace('/[\x{0591}-\x{05C7}]/u', '', $text) ?? $text;
    $text = str_replace(['״', '׳', '"', "'", '`'], '', $text);
    $text = preg_replace('/[^\p{L}\p{N}%]+/u', ' ', $text) ?? $text;
    return trim(preg_replace('/\s+/u', ' ', mb_strtolower($text, 'UTF-8')) ?? $text);
}

/** מחזיר את צריכת האחסון בפועל, מחושבת ולא נסמכת על השדה שבשורה. */
function storageUsedReal(int $userId): int {
    $st = db()->prepare("SELECT COALESCE(SUM(bytes),0) b FROM media
                          WHERE uploader_id = ? AND source = 'upload'");
    $st->execute([$userId]);
    return (int) $st->fetch()['b'];
}
