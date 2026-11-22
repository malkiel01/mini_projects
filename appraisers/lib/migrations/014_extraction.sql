-- פרוסה 12 (docs/spec/12-ai-extraction.md §3): חילוץ וסיווג בבינה — הטקסט שחולץ, מצב העיבוד של קובץ, ורישום כל הפעלה.
-- טבלאות חדשות, ועמודות חדשות ל-facts ול-case_ai (ALTER … ADD COLUMN בלבד — שום שורה קיימת אינה משתנה).
-- **אין כאן תוכן של תשובת מודל מחוץ ל-facts ול-file_ai:** file_ai_ops שומרת מצב, סיבה טכנית ומונים בלבד.

-- נתון שהוצע בבינה: מאיזו הפעלה, באיזו רמת ביטחון, והאם הציטוט אותר בטקסט העמוד (NULL — לא נבדק: אין טקסט מקומי)
ALTER TABLE facts ADD COLUMN ai_run_id INTEGER REFERENCES ai_runs (id);
ALTER TABLE facts ADD COLUMN ai_confidence TEXT CHECK (ai_confidence IS NULL OR ai_confidence IN ('low', 'medium', 'high'));
ALTER TABLE facts ADD COLUMN ai_quote_found INTEGER CHECK (ai_quote_found IS NULL OR ai_quote_found IN (0, 1));

-- נתון ממקור 'ai' נכנס רק כ"הצעה", ותמיד עם הקובץ שממנו חולץ ועם ההפעלה שיצרה אותו. "מאומת" הוא תמיד פעולת אדם (12-A1) —
-- גם אם קוד עתידי ינסה אחרת
CREATE TRIGGER facts_ai_proposed BEFORE INSERT ON facts
WHEN NEW.source_kind = 'ai' AND (NEW.state <> 'proposed' OR NEW.source_file_id IS NULL OR NEW.ai_run_id IS NULL)
BEGIN SELECT RAISE(ABORT, 'facts: ai fact must be a proposal with a file and a run'); END;

-- הקובץ של הצעת בינה אינו מוחלף ואינו מנותק
CREATE TRIGGER facts_ai_source BEFORE UPDATE ON facts
WHEN OLD.source_kind = 'ai' AND (NEW.source_file_id IS NOT OLD.source_file_id OR NEW.source_page IS NOT OLD.source_page OR NEW.source_locator IS NOT OLD.source_locator)
BEGIN SELECT RAISE(ABORT, 'facts: ai source is immutable'); END;

-- מה שהבינה קבעה על ההצעה אינו נערך אחר כך (האדם משנה את הערך ואת המצב — לא את רמת הביטחון של המודל)
CREATE TRIGGER facts_ai_frozen BEFORE UPDATE ON facts
WHEN NEW.ai_run_id IS NOT OLD.ai_run_id OR NEW.ai_confidence IS NOT OLD.ai_confidence OR NEW.ai_quote_found IS NOT OLD.ai_quote_found
BEGIN SELECT RAISE(ABORT, 'facts: ai fields are immutable'); END;

-- החילוץ האוטומטי בתיק: דלוק כברירת מחדל (§4); השמאי האחראי מכבה
ALTER TABLE case_ai ADD COLUMN auto_classify INTEGER NOT NULL DEFAULT 1 CHECK (auto_classify IN (0, 1));
ALTER TABLE case_ai ADD COLUMN auto_extract INTEGER NOT NULL DEFAULT 1 CHECK (auto_extract IN (0, 1));

-- טקסט שחולץ מקובץ, לעמוד — לחיפוש ולאימות ציטוטים. נמחק כשהקובץ נמחק.
CREATE TABLE file_texts (
    file_id     INTEGER NOT NULL REFERENCES files (id),
    page        INTEGER NOT NULL CHECK (page >= 1),
    text        TEXT NOT NULL,
    method      TEXT NOT NULL CHECK (method IN ('pdf_text', 'ai')),
    ai_run_id   INTEGER REFERENCES ai_runs (id),
    created_at  TEXT NOT NULL,
    PRIMARY KEY (file_id, page)
);

-- מצב העיבוד של קובץ. שורה אחת לקובץ; נוצרת בקליטה.
CREATE TABLE file_ai (
    file_id                   INTEGER PRIMARY KEY REFERENCES files (id),
    case_id                   INTEGER NOT NULL REFERENCES cases (id),
    text_state                TEXT NOT NULL DEFAULT 'none' CHECK (text_state IN ('none', 'done', 'empty', 'unavailable')),
    classify_state            TEXT NOT NULL DEFAULT 'none' CHECK (classify_state IN ('none', 'queued', 'running', 'done', 'failed', 'blocked', 'skipped')),
    classify_reason           TEXT,                       -- קוד טכני: quota, policy, bad_json, truncated … — לא תוכן
    extract_state             TEXT NOT NULL DEFAULT 'none' CHECK (extract_state IN ('none', 'queued', 'running', 'done', 'failed', 'blocked', 'skipped')),
    extract_reason            TEXT,
    summary_run_state         TEXT NOT NULL DEFAULT 'none' CHECK (summary_run_state IN ('none', 'queued', 'running', 'done', 'failed', 'blocked', 'skipped')),
    summary_reason            TEXT,
    suggested_doc_type        TEXT,
    suggested_requirement_id  INTEGER REFERENCES requirements (id),
    suggestion_confidence     TEXT CHECK (suggestion_confidence IS NULL OR suggestion_confidence IN ('low', 'medium', 'high')),
    suggestion_state          TEXT CHECK (suggestion_state IS NULL OR suggestion_state IN ('pending', 'accepted', 'rejected')),
    suggestion_by             INTEGER REFERENCES users (id),
    suggestion_at             TEXT,
    summary                   TEXT,
    summary_state             TEXT CHECK (summary_state IS NULL OR summary_state IN ('pending', 'reviewed')),
    summary_by                INTEGER REFERENCES users (id),
    summary_at                TEXT,
    quality_flags             TEXT NOT NULL DEFAULT '{}',  -- JSON: לכל סוג הפעלה — הדגלים שהמודל החזיר בה
    not_found                 TEXT NOT NULL DEFAULT '[]',  -- JSON: [{"attribute": "area", "status": "not_found"}]
    dropped                   INTEGER NOT NULL DEFAULT 0,  -- כמה שדות נזרקו באימות בחילוץ האחרון
    updated_at                TEXT NOT NULL,
    -- סיכום קיים תמיד עם מצב; "נבדק" הוא פעולת אדם
    CHECK ((summary IS NULL) = (summary_state IS NULL)),
    CHECK (summary_state IS NOT 'reviewed' OR summary_by IS NOT NULL)
);
CREATE INDEX file_ai_case ON file_ai (case_id);

-- כל הפעלה של הבינה על קובץ: פעם אחת לכל (קובץ, סוג, דור). op הוא מזהה הפעולה שנמסר ל-ai_run — ניסיון חוזר עם אותו
-- מזהה מחזיר את מה שנשמר כאן, ואינו מחייב שוב. UNIQUE + BEGIN IMMEDIATE: שתי הפעלות מקבילות על אותו קובץ — אחת בלבד.
CREATE TABLE file_ai_ops (
    id          INTEGER PRIMARY KEY,
    file_id     INTEGER NOT NULL REFERENCES files (id),
    case_id     INTEGER NOT NULL REFERENCES cases (id),
    what        TEXT NOT NULL CHECK (what IN ('classify', 'fields', 'summary')),
    gen         INTEGER NOT NULL CHECK (gen >= 1),
    prompt_ver  INTEGER NOT NULL,
    op          TEXT NOT NULL UNIQUE,
    client_key  TEXT UNIQUE,                               -- "<מזהה המשתמש>:<מזהה הפעולה מהדפדפן>"
    state       TEXT NOT NULL CHECK (state IN ('running', 'done', 'failed', 'blocked')),
    reason      TEXT,
    ai_run_pid  TEXT,
    saved       INTEGER NOT NULL DEFAULT 0,
    dropped     INTEGER NOT NULL DEFAULT 0,
    auto        INTEGER NOT NULL DEFAULT 0 CHECK (auto IN (0, 1)),
    started_by  INTEGER REFERENCES users (id),
    created_at  TEXT NOT NULL,
    finished_at TEXT,
    UNIQUE (file_id, what, gen)
);
CREATE INDEX file_ai_ops_file ON file_ai_ops (file_id, what, state);
