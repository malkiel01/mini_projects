-- פרוסה 15 (docs/spec/15-comparables.md §3): חיפושים, עסקאות השוואה, התאמות וקבוצות השוואה.
-- אינה נוגעת באף טבלה קיימת.
-- מספרים נשמרים כשלמים, בלי נקודה צפה: כסף — באגורות; שטח וחדרים — במאיות (כמו facts); אחוז ומשקל — במיליוניות (6 ספרות אחרי הנקודה).
-- מה שהאפיון דורש שלא ישתנה — נאכף כאן בטריגרים, לא רק בקוד. recursive_triggers דולק: אף טריגר כאן אינו כותב לטבלה של עצמו.

CREATE TABLE comp_searches (
    id            INTEGER PRIMARY KEY,
    pid           TEXT NOT NULL UNIQUE,
    office_id     INTEGER NOT NULL REFERENCES offices (id),
    case_id       INTEGER NOT NULL REFERENCES cases (id),
    source_id     INTEGER REFERENCES sources (id),                  -- המקור מהקטלוג (09)
    source_text   TEXT,                                             -- מקור אחר, בטקסט
    criteria      TEXT NOT NULL DEFAULT '{}',                       -- JSON
    performed_by  INTEGER REFERENCES users (id),
    performed_at  TEXT NOT NULL,
    result_count  INTEGER CHECK (result_count IS NULL OR result_count >= 0),   -- NULL = לא נרשם; 0 = המקור הציג אפס תוצאות
    limitations   TEXT,
    file_id       INTEGER REFERENCES files (id),                    -- קובץ שיובא (XLSX)
    import_name   TEXT,                                             -- ייבוא מטקסט (CSV / הדבקה): שם הקובץ, טביעת התוכן ומספר השורות
    import_sha256 TEXT,
    import_rows   INTEGER,
    rights_by     INTEGER REFERENCES users (id),                    -- מי אישר "יש לי זכות להשתמש בנתונים האלה" (§4.2)
    rights_at     TEXT,
    created_at    TEXT NOT NULL,
    updated_at    TEXT NOT NULL,
    CHECK (source_id IS NOT NULL OR source_text IS NOT NULL),
    CHECK ((rights_by IS NULL) = (rights_at IS NULL)),
    -- אין ייבוא בלי אישור הזכות
    CHECK ((file_id IS NULL AND import_sha256 IS NULL) OR rights_by IS NOT NULL)
);
CREATE INDEX comp_searches_case ON comp_searches (case_id);

CREATE TRIGGER comp_searches_no_delete BEFORE DELETE ON comp_searches
BEGIN SELECT RAISE(ABORT, 'comp_searches: no delete'); END;

CREATE TRIGGER comp_searches_identity BEFORE UPDATE ON comp_searches
WHEN NEW.pid <> OLD.pid OR NEW.office_id <> OLD.office_id OR NEW.case_id <> OLD.case_id OR NEW.performed_at <> OLD.performed_at
    OR NEW.performed_by IS NOT OLD.performed_by OR NEW.created_at <> OLD.created_at
BEGIN SELECT RAISE(ABORT, 'comp_searches: identity'); END;

CREATE TABLE comps (
    id            INTEGER PRIMARY KEY,
    pid           TEXT NOT NULL UNIQUE,
    office_id     INTEGER NOT NULL REFERENCES offices (id),
    case_id       INTEGER NOT NULL REFERENCES cases (id),
    search_id     INTEGER REFERENCES comp_searches (id),
    origin        TEXT NOT NULL CHECK (origin IN ('manual', 'import')),
    source_ref    TEXT,                                             -- מזהה העסקה במקור
    raw           TEXT NOT NULL,                                    -- JSON: השורה כפי שהגיעה — אינה נערכת לעולם
    deal_date     TEXT,
    price         INTEGER CHECK (price IS NULL OR price >= 0),      -- באגורות
    currency      TEXT NOT NULL DEFAULT 'ILS' CHECK (currency IN ('ILS', 'USD', 'EUR', 'other')),
    address_text  TEXT,
    locality      TEXT,
    gush          TEXT,
    helka         TEXT,
    tat_helka     TEXT,
    location_precision TEXT NOT NULL DEFAULT 'unknown' CHECK (location_precision IN ('exact', 'street', 'block', 'locality', 'unknown')),
    distance_m    INTEGER CHECK (distance_m IS NULL OR distance_m >= 0),
    property_type TEXT,
    area          INTEGER CHECK (area IS NULL OR area >= 0),        -- במאיות מ"ר. 0 נשמר כפי שהגיע — ואינו מתחלק (§10.2)
    area_kind     TEXT NOT NULL DEFAULT 'unknown' CHECK (area_kind IN ('registered', 'built', 'gross', 'net', 'primary', 'other', 'unknown')),
    rooms         INTEGER CHECK (rooms IS NULL OR rooms >= 0),      -- במאיות (2.5 חדרים = 250)
    floor         INTEGER,
    floors_total  INTEGER CHECK (floors_total IS NULL OR floors_total >= 0),
    build_year    INTEGER CHECK (build_year IS NULL OR build_year BETWEEN 1500 AND 2200),
    condition     TEXT,
    attachments   TEXT,
    extra         TEXT NOT NULL DEFAULT '{}',                       -- JSON: מאפיינים נוספים שהמקור סיפק
    corrected     TEXT NOT NULL DEFAULT '{}',                       -- JSON: { שדה: { from, to, by, at, reason } }
    status        TEXT NOT NULL DEFAULT 'candidate' CHECK (status IN ('candidate', 'selected', 'excluded', 'pending')),
    status_reason TEXT,
    status_by     INTEGER REFERENCES users (id),
    status_at     TEXT,
    weight        INTEGER CHECK (weight IS NULL OR weight >= 0),    -- במיליוניות; NULL = השמאי לא קבע
    note          TEXT,
    dup_of        INTEGER REFERENCES comps (id),                    -- חשד לכפילות: התרעה בלבד
    rev           INTEGER NOT NULL DEFAULT 1,
    created_by    INTEGER REFERENCES users (id),
    created_at    TEXT NOT NULL,
    updated_by    INTEGER REFERENCES users (id),
    updated_at    TEXT NOT NULL,
    deleted_at    TEXT,
    deleted_by    INTEGER REFERENCES users (id),
    delete_reason TEXT,
    -- מרחק רק כשהמיקום ידוע ברמה מספקת (15-B3)
    CHECK (distance_m IS NULL OR location_precision IN ('exact', 'street')),
    -- החרגה והמתנה לבירור — רק עם נימוק (15-A4)
    CHECK (status NOT IN ('excluded', 'pending') OR (status_reason IS NOT NULL AND status_reason <> '')),
    CHECK (dup_of IS NULL OR dup_of <> id)
);
CREATE INDEX comps_case ON comps (case_id, status);
CREATE INDEX comps_search ON comps (search_id);

CREATE TRIGGER comps_no_delete BEFORE DELETE ON comps
BEGIN SELECT RAISE(ABORT, 'comps: no delete'); END;

-- כל שינוי מקדם את rev באחד בדיוק; הזהות אינה משתנה
CREATE TRIGGER comps_rev BEFORE UPDATE ON comps
WHEN NEW.rev <> OLD.rev + 1 OR NEW.pid <> OLD.pid OR NEW.office_id <> OLD.office_id OR NEW.case_id <> OLD.case_id
    OR NEW.origin <> OLD.origin OR NEW.created_at <> OLD.created_at
BEGIN SELECT RAISE(ABORT, 'comps: rev / identity'); END;

-- ערכי המקור אינם נערכים לעולם (15-B1)
CREATE TRIGGER comps_raw_frozen BEFORE UPDATE ON comps
WHEN NEW.raw IS NOT OLD.raw
BEGIN SELECT RAISE(ABORT, 'comps: raw is immutable'); END;

-- עסקה שנמחקה נשארת כמו שהיא
CREATE TRIGGER comps_deleted_frozen BEFORE UPDATE ON comps
WHEN OLD.deleted_at IS NOT NULL
BEGIN SELECT RAISE(ABORT, 'comps: frozen'); END;

CREATE TABLE comp_adjustments (
    id          INTEGER PRIMARY KEY,
    pid         TEXT NOT NULL UNIQUE,
    comp_id     INTEGER NOT NULL REFERENCES comps (id),
    sort        INTEGER NOT NULL,                                   -- סדר החישוב
    label       TEXT NOT NULL CHECK (label <> ''),
    kind        TEXT NOT NULL CHECK (kind IN ('amount', 'percent')),
    value       INTEGER NOT NULL CHECK (value >= 0),                -- תמיד חיובי: סכום באגורות, או אחוז במיליוניות
    sign        TEXT NOT NULL CHECK (sign IN ('plus', 'minus')),
    basis       TEXT NOT NULL DEFAULT 'base' CHECK (basis IN ('base', 'running')),   -- לאחוז: על מה הוא חל
    -- על איזה בסיס השוואה ההתאמה חלה: סכום שייך לבסיס אחד (₪ לעסקה, או ₪ למ"ר); אחוז — לשניהם
    applies_to  TEXT NOT NULL CHECK (applies_to IN ('total', 'per_sqm', 'both')),
    reason      TEXT NOT NULL CHECK (reason <> ''),                 -- אין התאמה בלי נימוק (15-A4)
    created_by  INTEGER REFERENCES users (id),
    created_at  TEXT NOT NULL,
    updated_at  TEXT NOT NULL,
    deleted_at  TEXT,
    CHECK (kind = 'percent' OR applies_to <> 'both'),
    CHECK (kind = 'percent' OR basis = 'base')
);
CREATE INDEX comp_adjustments_comp ON comp_adjustments (comp_id, sort);

CREATE TRIGGER comp_adjustments_no_delete BEFORE DELETE ON comp_adjustments
BEGIN SELECT RAISE(ABORT, 'comp_adjustments: no delete'); END;

CREATE TRIGGER comp_adjustments_identity BEFORE UPDATE ON comp_adjustments
WHEN NEW.pid <> OLD.pid OR NEW.comp_id <> OLD.comp_id OR OLD.deleted_at IS NOT NULL
BEGIN SELECT RAISE(ABORT, 'comp_adjustments: identity / frozen'); END;

CREATE TABLE comp_sets (
    id            INTEGER PRIMARY KEY,
    pid           TEXT NOT NULL UNIQUE,
    office_id     INTEGER NOT NULL REFERENCES offices (id),
    case_id       INTEGER NOT NULL REFERENCES cases (id),
    name          TEXT NOT NULL CHECK (name <> ''),
    unit_basis    TEXT NOT NULL CHECK (unit_basis IN ('total', 'per_sqm')),
    subject_area_fact_id INTEGER REFERENCES facts (id),             -- השטח של הנכס הנישום, אם משווים למ"ר
    area_kind     TEXT CHECK (area_kind IN ('registered', 'built', 'gross', 'net', 'primary', 'other')),
    method        TEXT NOT NULL CHECK (method IN ('mean', 'weighted', 'median', 'range_only', 'manual')),
    rounding      TEXT NOT NULL CHECK (rounding IN ('none', '1', '100', '1000', '10000')),
    conclusion_value  INTEGER,                                      -- המסקנה שהשמאי קבע — באגורות (למ"ר: אגורות למ"ר)
    conclusion_reason TEXT,
    conclusion_by INTEGER REFERENCES users (id),
    conclusion_at TEXT,
    rev           INTEGER NOT NULL DEFAULT 1,
    created_by    INTEGER REFERENCES users (id),
    created_at    TEXT NOT NULL,
    updated_by    INTEGER REFERENCES users (id),
    updated_at    TEXT NOT NULL,
    deleted_at    TEXT,
    -- קבוצה שמשווה למ"ר חייבת לדעת איזה שטח משווים (§6.3)
    CHECK (unit_basis <> 'per_sqm' OR area_kind IS NOT NULL),
    -- המסקנה היא של השמאי: אין מסקנה בלי נימוק ובלי מי שקבע אותה (15-B6)
    CHECK (conclusion_value IS NULL OR (conclusion_reason IS NOT NULL AND conclusion_reason <> '' AND conclusion_by IS NOT NULL AND conclusion_at IS NOT NULL))
);
CREATE INDEX comp_sets_case ON comp_sets (case_id);

CREATE TRIGGER comp_sets_no_delete BEFORE DELETE ON comp_sets
BEGIN SELECT RAISE(ABORT, 'comp_sets: no delete'); END;

CREATE TRIGGER comp_sets_rev BEFORE UPDATE ON comp_sets
WHEN NEW.rev <> OLD.rev + 1 OR NEW.pid <> OLD.pid OR NEW.office_id <> OLD.office_id OR NEW.case_id <> OLD.case_id
    OR NEW.created_at <> OLD.created_at OR OLD.deleted_at IS NOT NULL
BEGIN SELECT RAISE(ABORT, 'comp_sets: rev / identity / frozen'); END;

-- מיפוי העמודות של ייבוא, לפי חתימת הכותרות — "נשמר לפעם הבאה" (§4.2). של המשרד בלבד.
CREATE TABLE comp_import_maps (
    office_id   INTEGER NOT NULL REFERENCES offices (id),
    sig         TEXT NOT NULL,                                      -- sha256 של שמות העמודות
    mapping     TEXT NOT NULL,                                      -- JSON
    updated_by  INTEGER REFERENCES users (id),
    updated_at  TEXT NOT NULL,
    PRIMARY KEY (office_id, sig)
);
