-- פרוסה 10 (docs/spec/10-facts-conflicts.md §2): קטלוג מאפיינים, נתונים עם מקור ומצב, סתירות, הכרעות ותלויות.
-- אינה נוגעת באף טבלה קיימת. מה שהאפיון דורש שלא ישתנה ולא יימחק — נאכף כאן בטריגרים, לא רק בקוד.
-- מספר נשמר כשלם במאיות (78.5 מ"ר = 7850; כסף — באגורות): השוואה מדויקת, בלי נקודה צפה.

CREATE TABLE attributes (
    id              INTEGER PRIMARY KEY,
    key             TEXT NOT NULL UNIQUE,
    label           TEXT NOT NULL,
    grp             TEXT NOT NULL CHECK (grp IN ('identity', 'features', 'deal', 'renovation', 'rights', 'rent')),
    value_type      TEXT NOT NULL CHECK (value_type IN ('number', 'money', 'date', 'text', 'choice', 'bool')),
    unit_kind       TEXT NOT NULL DEFAULT 'none' CHECK (unit_kind IN ('area', 'length', 'money', 'count', 'none')),
    has_area_kind   INTEGER NOT NULL DEFAULT 0 CHECK (has_area_kind IN (0, 1)),
    choices         TEXT,                                   -- JSON: [{"key": "…", "label": "…"}]
    multi           INTEGER NOT NULL DEFAULT 0 CHECK (multi IN (0, 1)),
    decimals        INTEGER NOT NULL DEFAULT 2 CHECK (decimals IN (0, 1, 2)),
    allow_negative  INTEGER NOT NULL DEFAULT 0 CHECK (allow_negative IN (0, 1)),
    domain          TEXT NOT NULL DEFAULT 'real_estate',
    status          TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'hidden')),
    is_example      INTEGER NOT NULL DEFAULT 1,             -- "דוגמה" עד אישור שמאי (§3)
    sort            INTEGER NOT NULL DEFAULT 0,
    created_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    CHECK (has_area_kind = 0 OR unit_kind = 'area'),
    CHECK ((value_type = 'money') = (unit_kind = 'money')),
    CHECK (unit_kind = 'none' OR value_type IN ('number', 'money')),
    CHECK ((value_type = 'choice') = (choices IS NOT NULL))
);

CREATE TABLE facts (
    id               INTEGER PRIMARY KEY,
    pid              TEXT NOT NULL UNIQUE,
    office_id        INTEGER NOT NULL REFERENCES offices (id),
    case_id          INTEGER NOT NULL REFERENCES cases (id),
    attribute_key    TEXT NOT NULL REFERENCES attributes (key),
    value_num        INTEGER,                               -- במאיות; bool: 0 / 100
    value_text       TEXT,
    value_date       TEXT,
    unit             TEXT,
    area_kind        TEXT CHECK (area_kind IN ('registered', 'built', 'gross', 'net', 'primary', 'other', 'unknown')),
    estimated        INTEGER NOT NULL DEFAULT 0 CHECK (estimated IN (0, 1)),
    state            TEXT NOT NULL CHECK (state IN ('proposed', 'manual', 'needs_clarification', 'verified', 'rejected')),
    source_kind      TEXT NOT NULL CHECK (source_kind IN ('document', 'visit', 'source_check', 'user', 'ai')),
    source_file_id   INTEGER REFERENCES files (id),
    source_page      INTEGER CHECK (source_page IS NULL OR source_page >= 1),
    source_locator   TEXT,
    source_ref_pid   TEXT,
    source_date      TEXT,
    source_note      TEXT,                                  -- "נמסר בעל פה מ-…"
    method           TEXT NOT NULL DEFAULT 'manual' CHECK (method IN ('manual', 'ai', 'ocr', 'import')),
    original_num     INTEGER,
    original_text    TEXT,
    original_date    TEXT,
    original_unit    TEXT,
    original_area_kind TEXT,
    corrected_by     INTEGER REFERENCES users (id),
    corrected_at     TEXT,
    correction_note  TEXT,
    entered_by       INTEGER REFERENCES users (id),
    entered_at       TEXT NOT NULL,
    verified_by      INTEGER REFERENCES users (id),
    verified_at      TEXT,
    rejected_reason  TEXT,
    note             TEXT,
    rev              INTEGER NOT NULL DEFAULT 1,
    updated_by       INTEGER REFERENCES users (id),
    updated_at       TEXT NOT NULL,
    deleted_at       TEXT,
    deleted_by       INTEGER REFERENCES users (id),
    delete_reason    TEXT,
    -- "אומת" הוא תמיד פעולת אדם; נתון מ-AI אינו מאומת בלי מקור שאפשר לפתוח (§4, 10-B4)
    CHECK (state <> 'verified' OR (verified_by IS NOT NULL AND verified_at IS NOT NULL)),
    CHECK (NOT (state = 'verified' AND source_kind = 'ai' AND source_file_id IS NULL AND source_ref_pid IS NULL)),
    -- נתון בלי ערך קיים רק כ"דורש בירור" (או כנתון שנדחה); "לא ידוע" אינו שורה ואינו אפס (§2)
    CHECK (state IN ('needs_clarification', 'rejected') OR value_num IS NOT NULL OR value_text IS NOT NULL OR value_date IS NOT NULL),
    CHECK (state <> 'rejected' OR rejected_reason IS NOT NULL),
    CHECK (source_kind <> 'document' OR source_file_id IS NOT NULL),
    CHECK (deleted_at IS NULL OR delete_reason IS NOT NULL)
);
CREATE INDEX facts_case ON facts (case_id, attribute_key);
CREATE INDEX facts_file ON facts (source_file_id);

-- נתון אינו נמחק מהמסד לעולם — רק מחיקה רכה (מקרה קצה 12)
CREATE TRIGGER facts_no_delete BEFORE DELETE ON facts
BEGIN SELECT RAISE(ABORT, 'facts: no delete'); END;

-- כל שינוי מקדם את rev באחד בדיוק; הזהות ומי שהזין אינם משתנים
CREATE TRIGGER facts_rev BEFORE UPDATE ON facts
WHEN NEW.rev <> OLD.rev + 1 OR NEW.pid <> OLD.pid OR NEW.office_id <> OLD.office_id OR NEW.case_id <> OLD.case_id
    OR NEW.attribute_key <> OLD.attribute_key OR NEW.entered_at <> OLD.entered_at OR NEW.entered_by IS NOT OLD.entered_by
    OR NEW.source_kind <> OLD.source_kind OR NEW.method <> OLD.method
BEGIN SELECT RAISE(ABORT, 'facts: rev / identity'); END;

-- ערך המקור נכתב פעם אחת — בתיקון הראשון — ואינו משתנה עוד (17.1, 10-B1)
CREATE TRIGGER facts_original_once BEFORE UPDATE ON facts
WHEN OLD.corrected_at IS NOT NULL AND (NEW.original_num IS NOT OLD.original_num OR NEW.original_text IS NOT OLD.original_text
    OR NEW.original_date IS NOT OLD.original_date OR NEW.original_unit IS NOT OLD.original_unit
    OR NEW.original_area_kind IS NOT OLD.original_area_kind OR NEW.corrected_at IS NULL)
BEGIN SELECT RAISE(ABORT, 'facts: original is immutable'); END;

-- שינוי ערך בלי לשמור את המקורי — נחסם: ערך משתנה רק יחד עם corrected_at
CREATE TRIGGER facts_value_needs_original BEFORE UPDATE ON facts
WHEN (NEW.value_num IS NOT OLD.value_num OR NEW.value_text IS NOT OLD.value_text OR NEW.value_date IS NOT OLD.value_date
    OR NEW.unit IS NOT OLD.unit OR NEW.area_kind IS NOT OLD.area_kind) AND NEW.corrected_at IS NULL
BEGIN SELECT RAISE(ABORT, 'facts: value change without original'); END;

-- עד התיקון הראשון original_* ריק; בתיקון הראשון הוא חייב להיות בדיוק הערך שהיה — כולו (ערך, יחידה וסוג שטח)
CREATE TRIGGER facts_original_first BEFORE UPDATE ON facts
WHEN OLD.corrected_at IS NULL AND (
    (NEW.corrected_at IS NULL AND (NEW.original_num IS NOT NULL OR NEW.original_text IS NOT NULL OR NEW.original_date IS NOT NULL
        OR NEW.original_unit IS NOT NULL OR NEW.original_area_kind IS NOT NULL))
    OR (NEW.corrected_at IS NOT NULL AND (NEW.original_num IS NOT OLD.value_num OR NEW.original_text IS NOT OLD.value_text
        OR NEW.original_date IS NOT OLD.value_date OR NEW.original_unit IS NOT OLD.unit OR NEW.original_area_kind IS NOT OLD.area_kind)))
BEGIN SELECT RAISE(ABORT, 'facts: original must be the previous value'); END;

-- נתון שנדחה או נמחק נשאר להיסטוריה כמו שהוא
CREATE TRIGGER facts_frozen BEFORE UPDATE ON facts
WHEN OLD.deleted_at IS NOT NULL OR (OLD.state = 'rejected' AND NEW.deleted_at IS NULL)
BEGIN SELECT RAISE(ABORT, 'facts: frozen'); END;

CREATE TABLE conflicts (
    id             INTEGER PRIMARY KEY,
    pid            TEXT NOT NULL UNIQUE,
    office_id      INTEGER NOT NULL REFERENCES offices (id),
    case_id        INTEGER NOT NULL REFERENCES cases (id),
    attribute_key  TEXT NOT NULL REFERENCES attributes (key),
    scope          TEXT NOT NULL,                           -- מה משווים: 'built|sqm', '|ILS' … ; '*' — אי אפשר להשוות
    kind           TEXT NOT NULL CHECK (kind IN ('value', 'incomparable')),
    status         TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'resolved', 'dismissed')),
    close_kind     TEXT CHECK (close_kind IN ('resolution', 'dismissed', 'auto')),
    close_reason   TEXT,
    closed_by      INTEGER REFERENCES users (id),
    closed_at      TEXT,
    task_id        INTEGER REFERENCES tasks (id),
    opened_count   INTEGER NOT NULL DEFAULT 1,
    needs_human    INTEGER NOT NULL DEFAULT 0,            -- נפתחה מחדש כי הכרעה איבדה תוקף: נשארת פתוחה עד שאדם מכריע (מקרה קצה 8)
    rev            INTEGER NOT NULL DEFAULT 1,
    created_at     TEXT NOT NULL,
    updated_at     TEXT NOT NULL,
    -- סתירה אחת לכל מאפיין והיקף השוואה בתיק: שתי בקשות מקבילות אינן יוצרות שתיים (10-B2)
    UNIQUE (case_id, attribute_key, scope),
    CHECK ((kind = 'incomparable') = (scope = '*')),
    CHECK ((status = 'open') = (close_kind IS NULL)),
    CHECK (status <> 'dismissed' OR close_reason IS NOT NULL)
);
CREATE INDEX conflicts_case ON conflicts (case_id, status);
CREATE TRIGGER conflicts_no_delete BEFORE DELETE ON conflicts
BEGIN SELECT RAISE(ABORT, 'conflicts: no delete'); END;
CREATE TRIGGER conflicts_rev BEFORE UPDATE ON conflicts
WHEN NEW.rev <> OLD.rev + 1 OR NEW.pid <> OLD.pid OR NEW.case_id <> OLD.case_id OR NEW.office_id <> OLD.office_id
    OR NEW.attribute_key <> OLD.attribute_key OR NEW.scope <> OLD.scope
BEGIN SELECT RAISE(ABORT, 'conflicts: rev / identity'); END;

CREATE TABLE conflict_facts (
    conflict_id  INTEGER NOT NULL REFERENCES conflicts (id),
    fact_id      INTEGER NOT NULL REFERENCES facts (id),
    added_at     TEXT NOT NULL,
    PRIMARY KEY (conflict_id, fact_id)
);
CREATE INDEX conflict_facts_fact ON conflict_facts (fact_id);
CREATE TRIGGER conflict_facts_no_delete BEFORE DELETE ON conflict_facts
BEGIN SELECT RAISE(ABORT, 'conflict_facts: no delete'); END;
CREATE TRIGGER conflict_facts_no_update BEFORE UPDATE ON conflict_facts
BEGIN SELECT RAISE(ABORT, 'conflict_facts: no update'); END;

-- הכרעה: אחת תקפה לכל מטרה. הכרעה אינה נמחקת ואינה נערכת — רק מסומנת "לא תקפה" (הוחלפה, או שהנתון שנבחר נדחה)
CREATE TABLE conflict_resolutions (
    id              INTEGER PRIMARY KEY,
    pid             TEXT NOT NULL UNIQUE,
    conflict_id     INTEGER NOT NULL REFERENCES conflicts (id),
    purpose         TEXT NOT NULL CHECK (length(trim(purpose)) > 0),
    chosen_fact_id  INTEGER NOT NULL REFERENCES facts (id),
    reasoning       TEXT NOT NULL CHECK (length(trim(reasoning)) > 0),
    resolved_by     INTEGER NOT NULL REFERENCES users (id),
    resolved_at     TEXT NOT NULL,
    voided_at       TEXT,
    void_reason     TEXT CHECK (void_reason IN ('superseded', 'fact_rejected', 'fact_deleted', 'fact_changed')),
    CHECK ((voided_at IS NULL) = (void_reason IS NULL))
);
CREATE UNIQUE INDEX conflict_resolutions_valid ON conflict_resolutions (conflict_id, purpose) WHERE voided_at IS NULL;
CREATE TRIGGER conflict_resolutions_no_delete BEFORE DELETE ON conflict_resolutions
BEGIN SELECT RAISE(ABORT, 'conflict_resolutions: no delete'); END;
CREATE TRIGGER conflict_resolutions_frozen BEFORE UPDATE ON conflict_resolutions
WHEN OLD.voided_at IS NOT NULL OR NEW.voided_at IS NULL OR NEW.pid <> OLD.pid OR NEW.conflict_id <> OLD.conflict_id
    OR NEW.purpose <> OLD.purpose OR NEW.chosen_fact_id <> OLD.chosen_fact_id OR NEW.reasoning <> OLD.reasoning
    OR NEW.resolved_by <> OLD.resolved_by OR NEW.resolved_at <> OLD.resolved_at
BEGIN SELECT RAISE(ABORT, 'conflict_resolutions: frozen'); END;

CREATE TABLE fact_uses (
    id            INTEGER PRIMARY KEY,
    fact_id       INTEGER NOT NULL REFERENCES facts (id),
    used_in_type  TEXT NOT NULL CHECK (used_in_type IN ('calc', 'comp', 'report_section', 'alternative')),
    used_in_pid   TEXT NOT NULL,
    stale         INTEGER NOT NULL DEFAULT 0 CHECK (stale IN (0, 1)),
    stale_at      TEXT,
    created_at    TEXT NOT NULL,
    UNIQUE (fact_id, used_in_type, used_in_pid)
);
CREATE INDEX fact_uses_target ON fact_uses (used_in_type, used_in_pid);

-- הקטלוג (§3) — "דוגמה" עד אישור שמאי. INSERT OR IGNORE לפי key: זריעה חוזרת אינה משנה ואינה מכפילה.
INSERT OR IGNORE INTO attributes (key, label, grp, value_type, unit_kind, has_area_kind, choices, multi, decimals, allow_negative, sort) VALUES
    ('property_type',   'סוג נכס',            'features',   'text',   'none',  0, NULL, 0, 2, 0, 10),
    ('actual_use',      'שימוש בפועל',        'features',   'text',   'none',  0, NULL, 0, 2, 0, 20),
    ('area',            'שטח',                'features',   'number', 'area',  1, NULL, 0, 2, 0, 30),
    ('floor',           'קומה',               'features',   'number', 'count', 0, NULL, 0, 0, 1, 40),
    ('floors_total',    'קומות בבניין',       'features',   'number', 'count', 0, NULL, 0, 0, 0, 50),
    ('rooms',           'חדרים',              'features',   'number', 'count', 0, NULL, 0, 1, 0, 60),
    ('build_state',     'מצב בנייה',          'features',   'choice', 'none',  0, '[{"key":"built","label":"בנוי"},{"key":"under_construction","label":"בבנייה"},{"key":"shell","label":"שלד"},{"key":"land","label":"קרקע"}]', 0, 2, 0, 70),
    ('upkeep_state',    'מצב תחזוקה',         'features',   'choice', 'none',  0, '[{"key":"new","label":"חדש"},{"key":"good","label":"טוב"},{"key":"fair","label":"בינוני"},{"key":"poor","label":"טעון שיפוץ"}]', 0, 2, 0, 80),
    ('year_built',      'שנת בנייה',          'features',   'number', 'count', 0, NULL, 0, 0, 0, 90),
    ('attachment',      'הצמדות',             'features',   'choice', 'none',  0, '[{"key":"parking","label":"חניה"},{"key":"storage","label":"מחסן"},{"key":"balcony","label":"מרפסת"},{"key":"roof","label":"גג"},{"key":"garden","label":"גינה"},{"key":"other","label":"אחר"}]', 1, 2, 0, 100),
    ('attachment_area', 'שטח הצמדה',          'features',   'number', 'area',  1, NULL, 1, 2, 0, 110),
    ('right_type',      'סוג הזכות',          'rights',     'choice', 'none',  0, '[{"key":"ownership","label":"בעלות"},{"key":"lease","label":"חכירה"},{"key":"protected","label":"דיירות מוגנת"},{"key":"other","label":"אחר"}]', 0, 2, 0, 10),
    ('registered_owner','בעלים רשומים',       'rights',     'text',   'none',  0, NULL, 1, 2, 0, 20),
    ('encumbrance',     'הערות ושעבודים',     'rights',     'text',   'none',  0, NULL, 1, 2, 0, 30),
    ('contract_price',  'מחיר חוזי',          'deal',       'money',  'money', 0, NULL, 0, 2, 0, 10),
    ('contract_date',   'תאריך החוזה',        'deal',       'date',   'none',  0, NULL, 0, 2, 0, 20),
    ('deal_party',      'צדדים',              'deal',       'text',   'none',  0, NULL, 1, 2, 0, 30),
    ('payment_terms',   'תנאי תשלום',         'deal',       'text',   'none',  0, NULL, 0, 2, 0, 40),
    ('declared_budget', 'תקציב מוצהר',        'renovation', 'money',  'money', 0, NULL, 0, 2, 0, 10),
    ('price_quote',     'הצעת מחיר',          'renovation', 'money',  'money', 0, NULL, 1, 2, 0, 20),
    ('verified_cost',   'עלות שאומתה',        'renovation', 'money',  'money', 0, NULL, 0, 2, 0, 30),
    ('works_desc',      'תיאור העבודות',      'renovation', 'text',   'none',  0, NULL, 0, 2, 0, 40),
    ('works_stage',     'שלב ביצוע',          'renovation', 'text',   'none',  0, NULL, 0, 2, 0, 50),
    ('rent',            'דמי שכירות',         'rent',       'money',  'money', 0, NULL, 0, 2, 0, 10),
    ('rent_term',       'תקופת השכירות',      'rent',       'text',   'none',  0, NULL, 0, 2, 0, 20),
    ('occupancy',       'תפוסה',              'rent',       'text',   'none',  0, NULL, 0, 2, 0, 30),
    ('rent_expenses',   'הוצאות',             'rent',       'money',  'money', 0, NULL, 0, 2, 0, 40);
