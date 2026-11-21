-- פרוסה 11 (docs/spec/11-ai-core.md §3): בינה — קטלוג ספקים ומודלים, חיבורים (כספת), הקצאות, מכסות ושריון, רישום שימוש ומדיניות.
-- אינה נוגעת באף טבלה קיימת ("בלי בינה" של תיק — בטבלה משלו, case_ai).
-- מספרים שלמים בלבד, בלי נקודה צפה: מחיר — במיליוניות דולר למיליון טוקנים; שער המרה — במיליוניות ש"ח לדולר; עלות ומכסה — באגורות.
-- ב-ai_runs אין תוכן: לא מה שנשלח, לא מה שהתקבל, ולא הודעת שגיאה של הספק — רק קוד טכני.

CREATE TABLE ai_providers (
    key                TEXT PRIMARY KEY,
    name               TEXT NOT NULL,
    adapter            TEXT NOT NULL,
    status             TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'hidden')),
    privacy_note       TEXT,                                      -- היכן מעובד, שמירה, אימון — כפי שמנהל המערכת תיעד
    privacy_url        TEXT,
    privacy_checked_at TEXT,
    privacy_rev        INTEGER NOT NULL DEFAULT 1                 -- עולה בשינוי מהותי; אישור של משרד תקף רק לגרסה שאושרה
);

CREATE TABLE ai_models (
    id                INTEGER PRIMARY KEY,
    provider_key      TEXT NOT NULL REFERENCES ai_providers (key),
    model_id          TEXT NOT NULL,
    label             TEXT NOT NULL,
    capabilities      TEXT NOT NULL DEFAULT '{}',                 -- JSON: text, vision, pdf, json_output
    price_in          INTEGER CHECK (price_in IS NULL OR price_in BETWEEN 0 AND 1000000000),     -- NULL = לא הוזן
    price_out         INTEGER CHECK (price_out IS NULL OR price_out BETWEEN 0 AND 1000000000),
    currency          TEXT NOT NULL DEFAULT 'USD' CHECK (currency = 'USD'),
    price_checked_at  TEXT,
    context_tokens    INTEGER NOT NULL CHECK (context_tokens > 0),
    max_output_tokens INTEGER NOT NULL CHECK (max_output_tokens > 0),
    status            TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'hidden')),
    UNIQUE (provider_key, model_id)
);

CREATE TABLE fx_rates (
    currency    TEXT PRIMARY KEY CHECK (currency = 'USD'),
    rate_to_ils INTEGER NOT NULL CHECK (rate_to_ils BETWEEN 1 AND 1000000000),   -- מיליוניות ש"ח למטבע אחד
    set_by      INTEGER REFERENCES users (id),
    set_at      TEXT NOT NULL
);

CREATE TABLE ai_connections (
    id            INTEGER PRIMARY KEY,
    pid           TEXT NOT NULL UNIQUE,
    track         TEXT NOT NULL CHECK (track IN ('managed', 'personal')),
    office_id     INTEGER REFERENCES offices (id),                -- NULL למסלול המנוהל
    provider_key  TEXT NOT NULL REFERENCES ai_providers (key),
    label         TEXT NOT NULL,
    key_enc       BLOB,                                           -- מוצפן (secretbox) במפתח שנגזר מ-data/secret.key
    key_hint      TEXT,                                           -- 4 תווים אחרונים
    default_model TEXT,                                           -- מסלול אישי: ברירת המחדל של המשרד
    status        TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'invalid', 'revoked')),
    fail_count    INTEGER NOT NULL DEFAULT 0,                     -- כשלי אימות רצופים
    last_ok_at    TEXT,
    last_error    TEXT,                                           -- קוד טכני בלבד
    created_by    INTEGER REFERENCES users (id),
    created_at    TEXT NOT NULL,
    revoked_at    TEXT,
    CHECK ((track = 'managed') = (office_id IS NULL)),
    -- ביטול מוחק את המפתח מהמסד; חיבור שאינו מבוטל — תמיד עם מפתח
    CHECK ((status = 'revoked') = (key_enc IS NULL)),
    CHECK ((status = 'revoked') = (revoked_at IS NOT NULL))
);
-- למשרד — חיבור אישי אחד שאינו מבוטל
CREATE UNIQUE INDEX ai_connections_personal ON ai_connections (office_id) WHERE track = 'personal' AND status <> 'revoked';

-- חיבור שבוטל אינו חוזר, ואינו מקבל מפתח
CREATE TRIGGER ai_connections_revoked_final BEFORE UPDATE ON ai_connections
WHEN OLD.status = 'revoked'
BEGIN SELECT RAISE(ABORT, 'ai_connections: חיבור שבוטל אינו משתנה'); END;

CREATE TRIGGER ai_connections_frozen BEFORE UPDATE OF pid, track, office_id, provider_key, created_by, created_at ON ai_connections
BEGIN SELECT RAISE(ABORT, 'ai_connections: שדה שאינו משתנה'); END;

CREATE TRIGGER ai_connections_no_delete BEFORE DELETE ON ai_connections
BEGIN SELECT RAISE(ABORT, 'ai_connections: חיבור אינו נמחק — מבטלים אותו'); END;

CREATE TABLE ai_assignments (
    id            INTEGER PRIMARY KEY,
    connection_id INTEGER NOT NULL REFERENCES ai_connections (id),
    scope         TEXT NOT NULL CHECK (scope IN ('office', 'user')),
    scope_id      INTEGER NOT NULL,
    models        TEXT NOT NULL DEFAULT '[]',                     -- JSON: רשימת model_id מותרים; ריק = כל הפעילים
    default_model TEXT,
    set_by        INTEGER REFERENCES users (id),
    created_at    TEXT NOT NULL,
    UNIQUE (scope, scope_id)                                      -- הקצאה אחת לכל משרד ולכל משתמש — הבחירה דטרמיניסטית
);

CREATE TABLE ai_quotas (
    id           INTEGER PRIMARY KEY,
    pid          TEXT NOT NULL UNIQUE,
    track        TEXT NOT NULL CHECK (track IN ('managed', 'personal')),
    owner        TEXT NOT NULL CHECK (owner IN ('system', 'office')),          -- מי קבע: מנהל המערכת, או מנהל המשרד (מסלול אישי בלבד)
    office_id    INTEGER REFERENCES offices (id),                 -- המשרד שבו המכסה חלה (למכסת משתמש במסלול המנוהל — NULL: בכל משרד)
    scope        TEXT NOT NULL CHECK (scope IN ('user', 'office', 'case')),
    scope_id     INTEGER NOT NULL,
    period       TEXT NOT NULL CHECK (period IN ('month', 'day', 'total')),
    limit_money  INTEGER CHECK (limit_money IS NULL OR limit_money >= 0),      -- אגורות
    limit_tokens INTEGER CHECK (limit_tokens IS NULL OR limit_tokens >= 0),
    hard         INTEGER NOT NULL DEFAULT 1 CHECK (hard IN (0, 1)),
    set_by       INTEGER REFERENCES users (id),
    created_at   TEXT NOT NULL,
    updated_at   TEXT NOT NULL,
    deleted_at   TEXT,
    CHECK (limit_money IS NOT NULL OR limit_tokens IS NOT NULL),
    CHECK (owner = 'system' OR track = 'personal'),
    CHECK (scope = 'user' OR office_id IS NOT NULL)
);
CREATE UNIQUE INDEX ai_quotas_one ON ai_quotas (track, owner, scope, scope_id, period, COALESCE(office_id, 0)) WHERE deleted_at IS NULL;
CREATE INDEX ai_quotas_scope ON ai_quotas (scope, scope_id) WHERE deleted_at IS NULL;

CREATE TABLE ai_usage_counters (
    quota_id        INTEGER NOT NULL REFERENCES ai_quotas (id),
    period_key      TEXT NOT NULL,                                -- '2026-11' / '2026-11-21' / 'total' — שעון ישראל
    used_money      INTEGER NOT NULL DEFAULT 0 CHECK (used_money >= 0),
    used_tokens     INTEGER NOT NULL DEFAULT 0 CHECK (used_tokens >= 0),
    reserved_money  INTEGER NOT NULL DEFAULT 0 CHECK (reserved_money >= 0),
    reserved_tokens INTEGER NOT NULL DEFAULT 0 CHECK (reserved_tokens >= 0),
    UNIQUE (quota_id, period_key)
);

CREATE TABLE ai_runs (
    id              INTEGER PRIMARY KEY,
    pid             TEXT NOT NULL UNIQUE,
    office_id       INTEGER NOT NULL REFERENCES offices (id),
    case_id         INTEGER REFERENCES cases (id),
    user_id         INTEGER REFERENCES users (id),                -- NULL = שירות רקע
    quota_user_id   INTEGER REFERENCES users (id),                -- על שם מי נספרת מכסת המשתמש (ברקע: השמאי האחראי)
    track           TEXT CHECK (track IN ('managed', 'personal')),
    connection_id   INTEGER REFERENCES ai_connections (id),
    provider_key    TEXT,
    model_id        TEXT,
    purpose         TEXT NOT NULL,
    status          TEXT NOT NULL CHECK (status IN ('reserved', 'running', 'done', 'failed', 'blocked', 'unknown')),
    block_reason    TEXT CHECK (block_reason IS NULL OR block_reason IN ('quota', 'policy', 'no_connection', 'no_assignment', 'case_no_ai', 'model_not_allowed')),
    in_tokens       INTEGER,                                      -- כפי שהספק דיווח; NULL אם לא דיווח
    out_tokens      INTEGER,
    est_in_tokens   INTEGER,
    est_out_tokens  INTEGER,
    est_cost        INTEGER,                                      -- אגורות; NULL כשאין מחיר או שער
    reported_cost   INTEGER,                                      -- לפי הטוקנים שדווחו והמחירון שהיה בזמן השריון
    cost_basis      TEXT CHECK (cost_basis IS NULL OR cost_basis IN ('reported', 'estimated')),
    price_in        INTEGER,                                      -- המחירון והשער ששימשו — בקשה ישנה שומרת את העלות שחושבה אז
    price_out       INTEGER,
    fx_rate         INTEGER,
    images          INTEGER,
    pages           INTEGER,
    idempotency_key TEXT UNIQUE,                                  -- משרד:משתמש:מזהה הפעולה; NULL לבקשה שנחסמה
    request_hash    TEXT,                                         -- HMAC של הבקשה — כדי לדחות אותו מזהה פעולה עם תוכן אחר; אינו התוכן
    stop_reason     TEXT,
    error_code      TEXT,                                         -- קוד טכני, בלי תוכן
    created_at      TEXT NOT NULL,
    started_at      TEXT,
    finished_at     TEXT,
    CHECK ((status = 'blocked') = (block_reason IS NOT NULL))
);
CREATE INDEX ai_runs_office ON ai_runs (office_id, created_at);
CREATE INDEX ai_runs_user ON ai_runs (user_id, created_at);
CREATE INDEX ai_runs_open ON ai_runs (status, created_at) WHERE status IN ('reserved', 'running');

CREATE TRIGGER ai_runs_no_delete BEFORE DELETE ON ai_runs
BEGIN SELECT RAISE(ABORT, 'ai_runs: רישום שימוש אינו נמחק'); END;

-- בקשה שהסתיימה אינה משתנה עוד
CREATE TRIGGER ai_runs_final BEFORE UPDATE ON ai_runs
WHEN OLD.status IN ('done', 'failed', 'blocked', 'unknown')
BEGIN SELECT RAISE(ABORT, 'ai_runs: בקשה שהסתיימה אינה משתנה'); END;

-- מה שוריין לכל בקשה, ובאיזו תקופה — היישוב חוזר לאותה תקופה גם אם המכסה התאפסה בינתיים
CREATE TABLE ai_run_reservations (
    run_id     INTEGER NOT NULL REFERENCES ai_runs (id),
    quota_id   INTEGER NOT NULL REFERENCES ai_quotas (id),
    period_key TEXT NOT NULL,
    money      INTEGER NOT NULL DEFAULT 0,
    tokens     INTEGER NOT NULL DEFAULT 0,
    settled    INTEGER NOT NULL DEFAULT 0 CHECK (settled IN (0, 1)),
    PRIMARY KEY (run_id, quota_id)
);

CREATE TABLE ai_policies (
    office_id             INTEGER PRIMARY KEY REFERENCES offices (id),
    enabled               INTEGER NOT NULL DEFAULT 0 CHECK (enabled IN (0, 1)),   -- כבוי עד אישור
    approved_by           INTEGER REFERENCES users (id),
    approved_at           TEXT,
    approved_text_version INTEGER,
    approved_providers    TEXT NOT NULL DEFAULT '{}',             -- JSON: ספק => privacy_rev שאושר
    allowed_tracks        TEXT NOT NULL DEFAULT '["managed","personal"]',
    prefer_managed        INTEGER NOT NULL DEFAULT 0 CHECK (prefer_managed IN (0, 1)),   -- בחירה מפורשת של המשרד, לא נפילה
    updated_at            TEXT NOT NULL
);

CREATE TABLE case_ai (
    case_id INTEGER PRIMARY KEY REFERENCES cases (id),
    no_ai   INTEGER NOT NULL DEFAULT 0 CHECK (no_ai IN (0, 1)),
    set_by  INTEGER REFERENCES users (id),
    set_at  TEXT NOT NULL
);

-- הקטלוג ההתחלתי: ספק אחד, והמודלים הנוכחיים שלו. **המחירים ריקים** — מודל בלי מחיר ותאריך בדיקה אינו ניתן לשימוש במסלול המנוהל.
-- הרשימה נערכת במסך המערכת (הוספה, הסתרה, עדכון מזהה) — היא נתון, לא קוד.
INSERT INTO ai_providers (key, name, adapter, status) VALUES ('anthropic', 'Anthropic (Claude)', 'anthropic', 'active');
INSERT INTO ai_models (provider_key, model_id, label, capabilities, context_tokens, max_output_tokens) VALUES
    ('anthropic', 'claude-opus-5-5', 'Claude Opus 5.5', '{"text":true,"vision":true,"pdf":true,"json_output":true}', 1000000, 128000),
    ('anthropic', 'claude-sonnet-5-5', 'Claude Sonnet 5.5', '{"text":true,"vision":true,"pdf":true,"json_output":true}', 1000000, 128000),
    ('anthropic', 'claude-haiku-5-5', 'Claude Haiku 5.5', '{"text":true,"vision":true,"pdf":true,"json_output":true}', 1000000, 128000);
