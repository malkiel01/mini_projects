<?php
/**
 * המסד של האפליקציה — SQLite ב-data/app.sqlite (אפיון פרק 4). משלה בלבד, בלי קבצים מחוץ לתיקייה.
 *
 * מיגרציות לפי PRAGMA user_version. מחירים, כמויות וסכומים — TEXT עשרוני (lib/decimal.php), לא REAL.
 * זמנים של עסקה — UTC בצורה "YYYY-MM-DDTHH:MM:SSZ" (ממוינת כמחרוזת); זמני מערכת — שניות unix.
 */

declare(strict_types=1);

require_once __DIR__ . '/pdo.php';

function ts_db_path(): string
{
    return defined('TS_DB_PATH') ? TS_DB_PATH : dirname(__DIR__) . '/data/app.sqlite';
}

function ts_db(): PDO
{
    static $db = null;
    if ($db) return $db;
    $db = ts_sqlite_open(ts_db_path(), [PDO::ATTR_STRINGIFY_FETCHES => false]);   // סוגר סמן אחרי שורה אחת (lib/pdo.php)
    $db->exec('PRAGMA foreign_keys = ON;');
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
    if ($v < 2) {
        // שלב א' (spec-stage-a.md): מנהלים, ספקי נתונים, מעקב, תקרות. הנרות — במסד נפרד (lib/cache.php)
        $db->exec(<<<'SQL'
            BEGIN;
            -- מנהלים נוספים (1.1). המנהל הראשי — platform_admin מהשער, לא כאן. מיגרציה 18: chief / editor (פרק 24)
            CREATE TABLE app_roles (
                user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
                role TEXT NOT NULL CHECK (role IN ('admin')),
                granted_by INTEGER NOT NULL,
                granted_at INTEGER NOT NULL
            );
            CREATE TABLE admin_log (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                action TEXT NOT NULL,
                target TEXT NOT NULL DEFAULT '',
                at INTEGER NOT NULL
            );
            CREATE TABLE settings (k TEXT PRIMARY KEY, v TEXT NOT NULL);
            -- הגדרות מנהל לכל מתאם (3.2): מופעל, ודריסות לתקרות
            CREATE TABLE providers (
                id TEXT PRIMARY KEY,
                enabled INTEGER NOT NULL DEFAULT 1,
                overrides TEXT NOT NULL DEFAULT '{}'
            );
            -- מפתחות (3.3–3.4): owner=user — ספק משלי; owner=admin — ספק לחלוקה (ב). secret — מוצפן תמיד
            CREATE TABLE provider_creds (
                id INTEGER PRIMARY KEY,
                owner TEXT NOT NULL CHECK (owner IN ('user','admin')),
                user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
                provider_id TEXT NOT NULL,
                secret TEXT NOT NULL,
                last4 TEXT NOT NULL,
                label TEXT NOT NULL DEFAULT '',
                created_at INTEGER NOT NULL,
                last_test_at INTEGER,
                last_test_ok INTEGER
            );
            CREATE UNIQUE INDEX creds_user_provider ON provider_creds(user_id, provider_id) WHERE owner = 'user';
            CREATE TABLE provider_grants (
                cred_id INTEGER NOT NULL REFERENCES provider_creds(id) ON DELETE CASCADE,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                daily_limit INTEGER NOT NULL,
                PRIMARY KEY (cred_id, user_id)
            );
            -- שימוש לפי יום: key = "cred:<id>" או "provider:<id>" (ספק בלי מפתח); user_id 0 = סך הכול
            CREATE TABLE provider_usage (
                key TEXT NOT NULL,
                user_id INTEGER NOT NULL DEFAULT 0,
                day TEXT NOT NULL,
                calls INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (key, user_id, day)
            );
            CREATE TABLE watchlist (
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                asset TEXT NOT NULL,
                sort INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (user_id, asset)
            );
            -- הספק המועדף לכל סימול (2): norm = סימול מנורמל, בלי ספק
            CREATE TABLE symbol_prefs (
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                norm TEXT NOT NULL,
                provider_id TEXT NOT NULL,
                PRIMARY KEY (user_id, norm)
            );
            -- תקרת בקשות הנתונים לדקה (3.5, ✅3)
            CREATE TABLE rate_hits (
                user_id INTEGER NOT NULL,
                minute INTEGER NOT NULL,
                hits INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (user_id, minute)
            );
            PRAGMA user_version = 2;
            COMMIT;
            SQL);
    }
    if ($v < 3) {
        // ציור על הגרף (spec-stage-a פרק 7): לכל משתמש ונכס; tf NULL = בכל מסגרת זמן
        $db->exec(<<<'SQL'
            BEGIN;
            CREATE TABLE drawings (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                asset TEXT NOT NULL,
                tf TEXT,
                kind TEXT NOT NULL,
                points TEXT NOT NULL,
                style TEXT NOT NULL DEFAULT '{}',
                updated_at INTEGER NOT NULL
            );
            CREATE INDEX drawings_user_asset ON drawings(user_id, asset);
            PRAGMA user_version = 3;
            COMMIT;
            SQL);
    }
    if ($v < 4) {
        // פרופיל, שיטות ובדיקה לאחור (spec-stage-a פרקים 5–6, 11)
        $db->exec(<<<'SQL'
            BEGIN;
            -- הפרופיל (5). risk_pct נשמר גם ב-users.default_risk_pct (שלב 1) — שני המסכים כותבים את שניהם יחד
            CREATE TABLE user_profile (
                user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
                horizon TEXT NOT NULL DEFAULT 'medium' CHECK (horizon IN ('short','medium','long')),
                risk_level TEXT NOT NULL DEFAULT 'medium' CHECK (risk_level IN ('low','medium','high')),
                risk_pct TEXT NOT NULL DEFAULT '1',
                min_rr TEXT NOT NULL DEFAULT '2',
                min_certainty INTEGER NOT NULL DEFAULT 55 CHECK (min_certainty BETWEEN 50 AND 80),
                preferred_methods TEXT NOT NULL DEFAULT '[]',
                alert_channels TEXT NOT NULL DEFAULT '["inbox"]',
                quiet_hours TEXT,
                max_alerts_per_day INTEGER NOT NULL DEFAULT 10,
                updated_at INTEGER NOT NULL
            );
            -- הגדרות מנהל לכל שיטה שבקוד (6.7). שיטה בלי שורה — כבויה
            CREATE TABLE methods (
                id TEXT PRIMARY KEY,
                enabled INTEGER NOT NULL DEFAULT 0,
                markets TEXT NOT NULL DEFAULT '[]',
                timeframes TEXT NOT NULL DEFAULT '[]',
                params TEXT NOT NULL DEFAULT '{}',
                note TEXT NOT NULL DEFAULT ''
            );
            -- תוצאות בדיקה לאחור (6.2). שורה אחת לכל (שיטה, ספק, נכס, מסגרת, היקף מפתח); גרסה או פרמטרים אחרים →
            -- חישוב מלא שמחליף אותה. state — מצב המנוע, לחישוב מצטבר. ההיסטוריה עצמה לא נשמרת
            CREATE TABLE backtests (
                method_id TEXT NOT NULL,
                method_version INTEGER NOT NULL,
                provider TEXT NOT NULL,
                asset TEXT NOT NULL,
                tf TEXT NOT NULL,
                params_hash TEXT NOT NULL,
                cred_scope TEXT NOT NULL DEFAULT '',
                n INTEGER NOT NULL,
                wins INTEGER NOT NULL,
                expectancy_r REAL,
                max_dd REAL NOT NULL DEFAULT 0,
                from_t INTEGER,
                to_t INTEGER,
                state TEXT NOT NULL,
                computed_at INTEGER NOT NULL,
                PRIMARY KEY (method_id, provider, asset, tf, cred_scope)
            );
            PRAGMA user_version = 4;
            COMMIT;
            SQL);
    }
    if ($v < 5) {
        // התראות (spec-stage-a פרק 8): ההתראה, האירועים (התיבה), מנויי פוש
        $db->exec(<<<'SQL'
            BEGIN;
            -- last_state: המצב בבדיקה הקודמת ('0'/'1', ובמחיר 'above'/'below') — נשלח רק במעבר, לא שוב עד שהתנאי
            -- התבטל והתקיים מחדש (8.2)
            CREATE TABLE alerts (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                asset TEXT NOT NULL,
                tf TEXT NOT NULL,
                side TEXT NOT NULL CHECK (side IN ('long','short','both')),
                kind TEXT NOT NULL CHECK (kind IN ('method','blend','price')),
                method_id TEXT,
                methods TEXT NOT NULL DEFAULT '[]',
                level TEXT,
                status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','paused','expired')),
                last_state TEXT,
                last_checked_at INTEGER,
                last_fired_at INTEGER,
                expires_at INTEGER NOT NULL,
                created_at INTEGER NOT NULL
            );
            CREATE INDEX alerts_user ON alerts(user_id);
            CREATE INDEX alerts_status ON alerts(status);
            CREATE TABLE alert_events (
                id INTEGER PRIMARY KEY,
                alert_id INTEGER REFERENCES alerts(id) ON DELETE SET NULL,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                fired_at INTEGER NOT NULL,
                payload TEXT NOT NULL,
                delivered TEXT NOT NULL DEFAULT '{}',
                read_at INTEGER
            );
            CREATE INDEX alert_events_user ON alert_events(user_id, fired_at);
            CREATE TABLE push_subs (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                endpoint TEXT NOT NULL UNIQUE,
                keys TEXT NOT NULL DEFAULT '{}',
                created_at INTEGER NOT NULL
            );
            PRAGMA user_version = 5;
            COMMIT;
            SQL);
    }
    if ($v < 6) {
        // חיבור הניתוח ליומן (spec-stage-a פרק 11) והמרת מטבע לפי ECB (✅12)
        $db->exec(<<<'SQL'
            BEGIN;
            -- עסקה שנפתחה מכרטיס ניתוח: השיטה, ותמונת הניתוח (כולל provider:symbol המקורי)
            ALTER TABLE trades ADD COLUMN method_id TEXT;
            ALTER TABLE trades ADD COLUMN analysis_snapshot TEXT;
            CREATE INDEX trades_method ON trades(user_id, method_id);
            -- שערי ECB: כמה יחידות quote ליחידת base ביום (ימי עסקים). נשמרים — שער של יום שעבר לא משתנה
            CREATE TABLE fx_rates (
                base TEXT NOT NULL,
                quote TEXT NOT NULL,
                day TEXT NOT NULL,
                rate TEXT NOT NULL,
                PRIMARY KEY (base, quote, day)
            );
            -- איזה טווח ימים כבר נמשך לכל זוג (כדי לא לפנות שוב על ימים בלי שער — סופי שבוע, חגים)
            CREATE TABLE fx_cover (
                base TEXT NOT NULL,
                quote TEXT NOT NULL,
                from_day TEXT NOT NULL,
                to_day TEXT NOT NULL,
                fetched_at INTEGER NOT NULL,
                PRIMARY KEY (base, quote)
            );
            PRAGMA user_version = 6;
            COMMIT;
            SQL);
    }
    if ($v < 7) {
        // בדיקה לאחור על העותק האישי בענן (spec-stage-a 3.7, ✅14): source במפתח התוצאה. source=copy — רק בתחום של
        // המשתמש עצמו (u:<id>), בלי מצב מצטבר (חישוב מלא בכל ריצה), ועם כמה נרות מכל מקור לסימון
        $db->exec(<<<'SQL'
            BEGIN;
            CREATE TABLE backtests_v7 (
                method_id TEXT NOT NULL,
                method_version INTEGER NOT NULL,
                provider TEXT NOT NULL,
                asset TEXT NOT NULL,
                tf TEXT NOT NULL,
                params_hash TEXT NOT NULL,
                cred_scope TEXT NOT NULL DEFAULT '',
                source TEXT NOT NULL DEFAULT 'provider' CHECK (source IN ('provider','copy')),
                n INTEGER NOT NULL,
                wins INTEGER NOT NULL,
                expectancy_r REAL,
                max_dd REAL NOT NULL DEFAULT 0,
                from_t INTEGER,
                to_t INTEGER,
                state TEXT,
                n_copy INTEGER,
                n_provider INTEGER,
                computed_at INTEGER NOT NULL,
                PRIMARY KEY (method_id, provider, asset, tf, cred_scope, source),
                CHECK (source = 'provider' OR (cred_scope LIKE 'u:%' AND state IS NULL))
            );
            INSERT INTO backtests_v7 (method_id, method_version, provider, asset, tf, params_hash, cred_scope, n, wins, expectancy_r,
                                      max_dd, from_t, to_t, state, computed_at)
                SELECT method_id, method_version, provider, asset, tf, params_hash, cred_scope, n, wins, expectancy_r,
                       max_dd, from_t, to_t, state, computed_at FROM backtests;
            DROP TABLE backtests;
            ALTER TABLE backtests_v7 RENAME TO backtests;
            -- התוצאה מהעותק נכנסת לשקלול רק כשהמשתמש בחר בה (ברירת מחדל — הרגילה)
            CREATE TABLE copy_blend (
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                asset TEXT NOT NULL,
                tf TEXT NOT NULL,
                method_id TEXT NOT NULL,
                PRIMARY KEY (user_id, asset, tf, method_id)
            );
            PRAGMA user_version = 7;
            COMMIT;
            SQL);
    }
    if ($v < 8) {
        // בונה השיטות (spec-stage-a 17.2.4, ✅19): שיטה מוגדרת — הגדרת JSON במסד, לצד שיטות הקוד. כל שמירה של הגדרה
        // מעלה את definition_version (והבדיקות לאחור שלה מחושבות מחדש — 6.7), וההיסטוריה ב-method_versions.
        // שורות קיימות — kind = 'code', בלי הגדרה: שום שינוי בהן.
        $db->exec(<<<'SQL'
            BEGIN;
            ALTER TABLE methods ADD COLUMN kind TEXT NOT NULL DEFAULT 'code' CHECK (kind IN ('code','defined'));
            ALTER TABLE methods ADD COLUMN definition TEXT;
            ALTER TABLE methods ADD COLUMN definition_version INTEGER NOT NULL DEFAULT 0;
            CREATE TABLE method_versions (
                method_id TEXT NOT NULL,
                version INTEGER NOT NULL,
                definition TEXT NOT NULL,
                saved_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
                saved_at INTEGER NOT NULL,
                PRIMARY KEY (method_id, version)
            );
            PRAGMA user_version = 8;
            COMMIT;
            SQL);
    }
    if ($v < 9) {
        // לשוניות במסך השוק (spec-stage-a 18.1.4, S1.2): עמודה חדשה, ריקה — null = ברירת המחדל. לא נוגעת בשום נתון קיים
        $db->exec(<<<'SQL'
            BEGIN;
            ALTER TABLE users ADD COLUMN market_tabs TEXT;
            PRAGMA user_version = 9;
            COMMIT;
            SQL);
    }
    if ($v < 10) {
        // צבע לכל שיטה (spec-stage-a 18.5, S5): עמודה חדשה, ריקה — null = עוד לא נבחר צבע. ALTER בלבד, לא נוגעת בשום נתון קיים
        $db->exec(<<<'SQL'
            BEGIN;
            ALTER TABLE users ADD COLUMN method_colors TEXT;
            PRAGMA user_version = 10;
            COMMIT;
            SQL);
    }
    if ($v < 11) {
        // יומן השיטה (spec-stage-a 18.6, S6, ✅27): טבלה חדשה בלבד — לא נוגעת בשום נתון קיים. במסד של האפליקציה ולא
        // במאגר הנרות, כי המאגר מתרוקן ומתנקה ("ריקון הכול", ניקוי יומי) — והיומן הוא היסטוריה שלא נמחקת.
        // רשומה אחת לכל (שיטה, גרסה, נכס, מסגרת, היקף מפתח, נר סגור, סוג) — UNIQUE, ונכתבת ב-INSERT OR IGNORE: לא משוכתבת.
        // בלי user_id — ברמת המערכת, בלי לציין מי ביקש (✅27). cred_scope — מי רשאי לראות (✅2).
        // outcome: NULL = עוד לא ידוע; zone / invalidated / neither — נקבע ב-cron מנרות סגורים; none — אין תרחיש לבדוק.
        $db->exec(<<<'SQL'
            BEGIN;
            CREATE TABLE method_journal (
                id INTEGER PRIMARY KEY,
                method_id TEXT NOT NULL,
                method_version INTEGER NOT NULL,
                asset TEXT NOT NULL,
                tf TEXT NOT NULL,
                cred_scope TEXT NOT NULL DEFAULT '',
                as_of INTEGER NOT NULL,
                kind TEXT NOT NULL CHECK (kind IN ('analysis','backtest')),
                said TEXT NOT NULL,
                zone_lo TEXT,
                zone_hi TEXT,
                invalidation TEXT,
                until_t INTEGER,
                outcome TEXT CHECK (outcome IN ('zone','invalidated','neither','none')),
                outcome_t INTEGER,
                outcome_note TEXT,
                resolved_at INTEGER,
                created_at INTEGER NOT NULL,
                UNIQUE (method_id, method_version, asset, tf, cred_scope, as_of, kind)
            );
            CREATE INDEX method_journal_pending ON method_journal(until_t) WHERE outcome IS NULL;
            CREATE INDEX method_journal_method ON method_journal(method_id, method_version);
            PRAGMA user_version = 11;
            COMMIT;
            SQL);
    }
    if ($v < 12) {
        // שכלול שיטה (spec-stage-a 18.7, S7): עמודה וטבלה חדשות בלבד — לא נוגעת בשום נתון קיים.
        // methods.tune — כמה פעמים "החל" שינה פרמטר של השיטה. הגרסה של השיטה = גרסת הקוד (או ההגדרה) + tune, ולכן כל
        // החלה מעלה את meta().version (6.7), והבדיקות לאחור והיומן (18.6) עוברים לגרסה החדשה.
        // method_proposals — הצעת שינוי פרמטר: הערך הנוכחי, הערך המועמד, והביצועים של שניהם בתוך הדגימה ומחוצה לה
        // (results, JSON). status: open → applied, או stale כשהגרסה או הערך זזו לפני ההחלה. לא נמחקת לעולם.
        $db->exec(<<<'SQL'
            BEGIN;
            ALTER TABLE methods ADD COLUMN tune INTEGER NOT NULL DEFAULT 0;
            CREATE TABLE method_proposals (
                id INTEGER PRIMARY KEY,
                method_id TEXT NOT NULL,
                method_version INTEGER NOT NULL,
                param TEXT NOT NULL,
                current_value TEXT NOT NULL,
                candidate_value TEXT NOT NULL,
                asset TEXT NOT NULL,
                tf TEXT NOT NULL,
                split_t INTEGER NOT NULL,
                results TEXT NOT NULL,
                note TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open','applied','stale')),
                created_by INTEGER NOT NULL,
                created_at INTEGER NOT NULL,
                applied_by INTEGER,
                applied_at INTEGER,
                new_version INTEGER
            );
            CREATE INDEX method_proposals_method ON method_proposals(method_id, id);
            PRAGMA user_version = 12;
            COMMIT;
            SQL);
    }
    if ($v < 13) {
        // DevLoop (A1, PROTOCOL 7): מי המפתח — המנהל הראשי (platform_admin מהשער). השער אומר את זה רק בבקשה עצמה, ואסימון
        // של Claude (devapi-…) מגיע בלי משתמש — לכן נשמר כאן, ומתעדכן בכל כניסה (ts_user_for). ALTER בלבד, לא נוגעת בשום נתון.
        // טבלאות המטלות עצמן (dev_tasks, dev_comments…) — של המודול המשותף, CREATE IF NOT EXISTS (devloop_migrate).
        $db->exec(<<<'SQL'
            BEGIN;
            ALTER TABLE users ADD COLUMN platform_admin INTEGER NOT NULL DEFAULT 0;
            PRAGMA user_version = 13;
            COMMIT;
            SQL);
    }
    if ($v < 14) {
        // A2 — מחוברת או מנותקת (spec-stage-a 20.1; lib/auth.php, lib/sso.php). ALTER ו-CREATE בלבד: לא נוגעת בשום
        // משתמש ובשום נתון. sessions.via — מאיפה נפתח: 'gate' (השער; כל session קיים), 'sso' או 'password' (מנותקת);
        // grant_checked_at — מתי grant_status אישר. users.password_hash — "סיסמה לאפליקציה" (NULL — אין). sso_states /
        // sso_jti — state חד-פעמי וזהות שנוצלה. login_locks — 5 שגיאות → רבע שעה, לפי מייל ולפי IP.
        $db->exec(<<<'SQL'
            BEGIN;
            ALTER TABLE sessions ADD COLUMN via TEXT NOT NULL DEFAULT 'gate';
            ALTER TABLE sessions ADD COLUMN grant_checked_at INTEGER NOT NULL DEFAULT 0;
            ALTER TABLE users ADD COLUMN password_hash TEXT;
            ALTER TABLE users ADD COLUMN password_set_at INTEGER;
            CREATE TABLE sso_states (
                state_hash TEXT PRIMARY KEY,
                created_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL,
                used_at INTEGER
            );
            CREATE TABLE sso_jti (jti TEXT PRIMARY KEY, exp INTEGER NOT NULL);
            CREATE TABLE login_locks (lock_key TEXT PRIMARY KEY, fails INTEGER NOT NULL, locked_until INTEGER NOT NULL);
            CREATE INDEX users_email ON users(lower(email));
            PRAGMA user_version = 14;
            COMMIT;
            SQL);
    }
    if ($v < 15) {
        // מילוי איטי ברקע (spec-stage-a 21.2, ✅30): מתי מולא כל נכס בכל היקף, ומתי נכשל (לסוף התור). והמשתמש יכול
        // לכבות את המילוי במפתח שלו. עמודה וטבלה חדשות בלבד — לא נוגע בשום נתון קיים
        $db->exec(<<<'SQL'
            BEGIN;
            ALTER TABLE users ADD COLUMN prefill_off INTEGER NOT NULL DEFAULT 0;
            CREATE TABLE prefill_state (
                provider TEXT NOT NULL,
                symbol TEXT NOT NULL,
                cred_scope TEXT NOT NULL DEFAULT '',
                filled_at INTEGER NOT NULL DEFAULT 0,
                failed_at INTEGER NOT NULL DEFAULT 0,
                empty INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (provider, symbol, cred_scope)
            );
            PRAGMA user_version = 15;
            COMMIT;
            SQL);
    }
    if ($v < 16) {
        // Twelve Data — מאגר משותף (spec-stage-a 21.3 (2), ✅31): התור של המילוי במפתחות אישיים לא בשימוש יותר. רק
        // שורות prefill_state של Twelve Data ב-u:% נמחקות; בדיקות לאחור ויומן השיטה ב-u:% — נשארים כמו שהם.
        // users.prefill_off נשארת (לא מוחקים עמודה של מיגרציה שרצה)
        $db->exec(<<<'SQL'
            BEGIN;
            DELETE FROM prefill_state WHERE provider = 'twelvedata' AND cred_scope LIKE 'u:%';
            PRAGMA user_version = 16;
            COMMIT;
            SQL);
    }
    if ($v < 17) {
        // למידה עצמית, שלב א' (spec-stage-a 23.3, L1): שורה לכל עסקה שהסתיימה — לא נמחקת ולא משתנה. CREATE / ADD בלבד.
        // המפתח הייחודי (23.3) — חישוב חוזר לא מכפיל; trade_id = 0 כשאינה עסקה של משתמש (NULL היה נחשב שונה בכל שורה).
        // users.learn_off — הכיבוי של עסקאות המשתמש (✅36, ל3); נקרא ב-L2, כשנכתבות שורות trade
        $db->exec(<<<'SQL'
            BEGIN;
            CREATE TABLE method_outcomes (
                id INTEGER PRIMARY KEY,
                source TEXT NOT NULL CHECK (source IN ('backtest','live','trade')),
                method_id TEXT NOT NULL,
                method_version INTEGER NOT NULL,
                params_hash TEXT NOT NULL,
                provider TEXT NOT NULL,
                asset TEXT NOT NULL,
                tf TEXT NOT NULL,
                cred_scope TEXT NOT NULL DEFAULT '',
                t_signal INTEGER NOT NULL,
                t_entry INTEGER,
                t_exit INTEGER NOT NULL,
                side TEXT NOT NULL CHECK (side IN ('long','short')),
                entry TEXT,
                stop TEXT,
                exit_level TEXT,
                exit TEXT,
                result TEXT NOT NULL CHECK (result IN ('win','loss','horizon','skipped_gap')),
                win INTEGER NOT NULL DEFAULT 0,
                r REAL,
                mae_r REAL,
                mfe_r REAL,
                atr TEXT,
                market TEXT NOT NULL,
                month INTEGER NOT NULL,
                quarter INTEGER NOT NULL,
                weekday INTEGER NOT NULL,
                hour INTEGER,
                holidays TEXT NOT NULL DEFAULT '[]',
                features TEXT,
                user_id INTEGER,
                trade_id INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL
            );
            CREATE UNIQUE INDEX method_outcomes_key ON method_outcomes
                (source, method_id, method_version, params_hash, provider, asset, tf, t_signal, trade_id);
            CREATE INDEX method_outcomes_method ON method_outcomes (method_id, method_version, source);
            ALTER TABLE users ADD COLUMN learn_off INTEGER NOT NULL DEFAULT 0;
            PRAGMA user_version = 17;
            COMMIT;
            SQL);
    }
    if ($v < 18) {
        // עורך ראשי ועורך משני (spec-stage-a 24.4, ✅38): ב-SQLite אי אפשר לשנות CHECK — לכן הטבלה נבנית מחדש, כמו
        // backtests_v7. כל שורה עוברת: admin → editor, ואותו granted_by ו-granted_at. בטרנזקציה אחת — נכשל באמצע,
        // הכול חוזר. העורך מהשער (platform_admin) לא שורה כאן. תפקיד אחד למשתמש (user_id — המפתח)
        $db->exec(<<<'SQL'
            BEGIN;
            CREATE TABLE app_roles_v18 (
                user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
                role TEXT NOT NULL CHECK (role IN ('chief', 'editor')),
                granted_by INTEGER NOT NULL,
                granted_at INTEGER NOT NULL
            );
            INSERT INTO app_roles_v18 (user_id, role, granted_by, granted_at)
                SELECT user_id, CASE role WHEN 'admin' THEN 'editor' ELSE role END, granted_by, granted_at FROM app_roles
                WHERE user_id IN (SELECT id FROM users);
            DROP TABLE app_roles;
            ALTER TABLE app_roles_v18 RENAME TO app_roles;
            PRAGMA user_version = 18;
            COMMIT;
            SQL);
    }
    if ($v < 19) {
        // למידה, L4 (spec-stage-a 23.8א, ✅37): ממצא חריג → פריט DevLoop אחד. המפתח (שיטה, גרסה, מקור, סוג, פלח, פרמטר) → הקוד,
        // כדי שאותו ממצא שוב יגיב בפריט הקיים ולא יפתח חדש. sig — המספרים בדיווח האחרון; total — כמה עסקאות היו אז. CREATE בלבד
        $db->exec(<<<'SQL'
            BEGIN;
            CREATE TABLE IF NOT EXISTS learning_findings (
                k TEXT PRIMARY KEY,
                method_id TEXT NOT NULL,
                method_version INTEGER NOT NULL,
                source TEXT NOT NULL,
                kind TEXT NOT NULL,
                dim TEXT NOT NULL,
                value TEXT NOT NULL,
                code TEXT NOT NULL,
                scope TEXT NOT NULL,
                sig TEXT NOT NULL,
                total INTEGER NOT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS learning_findings_method ON learning_findings (method_id, method_version);
            PRAGMA user_version = 19;
            COMMIT;
            SQL);
    }
    if ($v < 20) {
        // למידה, L5 (spec-stage-a 23.9): ערך בתוך הטווח ← הצעת S7, גם לפלח שוק × מסגרת. ADD / CREATE בלבד.
        // methods.seg_params — {"forex|h1": {"stop_atr": 0.6}}: ערך של פלח שהוחל; השיטה משתמשת בו כשהוא קיים, ובכללי בשאר.
        // method_proposals.segment ('' = כללי), .origin (admin = הוזן ביד, learning = מהסטטיסטיקה), .stats (הסטטיסטיקה, רווח
        // הסמך ו-N). learning_values — כמה שורות היו כשהסטטיסטיקה חושבה לאחרונה (הצעה כל 30 שורות חדשות לפלח)
        // אידמפוטנטית: ADD COLUMN רק לעמודה שאין (SQLite בלי IF NOT EXISTS לעמודה; roles-api מריץ שוב מ-17)
        // טבלה שאין בכלל (מסד בדיקה חלקי) — לא נוגעים בה
        $has = function (string $t, string $c) use ($db): bool {
            $cols = array_column($db->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
            return !$cols || in_array($c, $cols, true);
        };
        $add = ($has('methods', 'seg_params') ? '' : "ALTER TABLE methods ADD COLUMN seg_params TEXT NOT NULL DEFAULT '{}';\n")
            . ($has('method_proposals', 'segment') ? '' : "ALTER TABLE method_proposals ADD COLUMN segment TEXT NOT NULL DEFAULT '';\n")
            . ($has('method_proposals', 'origin') ? '' : "ALTER TABLE method_proposals ADD COLUMN origin TEXT NOT NULL DEFAULT 'admin';\n")
            . ($has('method_proposals', 'stats') ? '' : "ALTER TABLE method_proposals ADD COLUMN stats TEXT;\n");
        $db->exec("BEGIN;\n" . $add . <<<'SQL'
            CREATE TABLE IF NOT EXISTS learning_values (
                method_id TEXT NOT NULL,
                method_version INTEGER NOT NULL,
                source TEXT NOT NULL,
                segment TEXT NOT NULL,
                param TEXT NOT NULL,
                n_at INTEGER NOT NULL,
                result TEXT NOT NULL,
                at INTEGER NOT NULL,
                PRIMARY KEY (method_id, method_version, source, segment, param)
            );
            PRAGMA user_version = 20;
            COMMIT;
            SQL);
    }
    if ($v < 21) {
        // למידה, L5b (spec-stage-a 23.9, 2.37): השוואת שכנים. learning_values.detail — הפירוט של ההשוואה האחרונה (t_cut, N בכל
        // חלק, ולכל שכן — תוחלת R בתוך הדגימה ומחוצה לה, מספר ההרצות), למסך "למידה" (23.10). ADD בלבד, אידמפוטנטית (כמו 20)
        // learning_values.deferred — כמה פעמים ברצף ההשוואה נדחתה בגלל זמן (3 → too_big, ביקורת L5b 🟡)
        $cols = array_column($db->query('PRAGMA table_info(learning_values)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        $db->exec("BEGIN;\n" . ($cols && !in_array('detail', $cols, true) ? "ALTER TABLE learning_values ADD COLUMN detail TEXT;\n" : '')
            . ($cols && !in_array('deferred', $cols, true) ? "ALTER TABLE learning_values ADD COLUMN deferred INTEGER NOT NULL DEFAULT 0;\n" : '')
            . "PRAGMA user_version = 21;\nCOMMIT;");
    }
    if ($v < 22) {
        // למידה — מקור live (23.4, L2): ההמשך של מנוע הבדיקה לאחור על נרות סגורים חדשים, בשביל אותות שקרו **אחרי** הבדיקה.
        // שורה לכל בדיקה לאחור שמורה (אותו מפתח כמו backtests). base_t — ה-to_t של הבדיקה כשהתחלנו ממנה: עסקה שהאות שלה
        // עד base_t שייכת לבדיקה לאחור, ולא נכתבת כ-live. גרסה או פרמטרים אחרים — מתחילים מחדש מהבדיקה העדכנית.
        // CREATE בלבד — לא נוגע בשום נתון קיים. ב-data/app.sqlite (כבר מוחרג); אין --exclude חדש
        $db->exec(<<<'SQL'
            BEGIN;
            CREATE TABLE IF NOT EXISTS learn_live (
                method_id TEXT NOT NULL,
                provider TEXT NOT NULL,
                asset TEXT NOT NULL,
                tf TEXT NOT NULL,
                cred_scope TEXT NOT NULL DEFAULT '',
                method_version INTEGER NOT NULL,
                params_hash TEXT NOT NULL,
                base_t INTEGER NOT NULL,
                last_t INTEGER NOT NULL,
                state TEXT NOT NULL,
                written INTEGER NOT NULL DEFAULT 0,
                at INTEGER NOT NULL,
                PRIMARY KEY (method_id, provider, asset, tf, cred_scope)
            );
            PRAGMA user_version = 22;
            COMMIT;
            SQL);
    }
}

/** הגדרה כללית של האפליקציה (טבלת settings) */
function ts_setting(string $k, ?string $default = null): ?string
{
    $st = ts_db()->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string)$v;
}

function ts_setting_set(string $k, string $v): void
{
    ts_db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')->execute([$k, $v]);
}
