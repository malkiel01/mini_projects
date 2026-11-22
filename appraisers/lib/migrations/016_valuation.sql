-- פרוסה 16 (docs/spec/16-valuation-methods.md §3): חלופות שווי, הנחות, גישות, גיליונות חישוב, תבניות גיליון, ורכישה ושיפוץ.
-- ועוד: שיוך עסקה לקבוצת השוואה ומשקל — **לכל קבוצה בנפרד** (הכרעת הבקרה ל-16; ב-15 כל עסקה שנבחרה השתייכה לכל הקבוצות).
-- אינה משנה אף טבלה קיימת: comps.weight נשאר כפי שהוא ואינו נקרא עוד — המשקל עובר ל-comp_set_members.
-- מספרים — שלמים בלבד: כסף באגורות; משקל במיליוניות; ערך של שורת גיליון במיליוניות (6 ספרות אחרי הנקודה). אין REAL.

-- ── שיוך עסקה לקבוצה ──────────────────────────────────────────
CREATE TABLE comp_set_members (
    set_id      INTEGER NOT NULL REFERENCES comp_sets (id),
    comp_id     INTEGER NOT NULL REFERENCES comps (id),
    active      INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),      -- 0 = הוצאה מהקבוצה במפורש (אינה חוזרת לבד)
    weight      INTEGER CHECK (weight IS NULL OR weight >= 0),            -- במיליוניות; NULL = השמאי לא קבע
    reason      TEXT,                                                     -- נימוק לשיוך / להוצאה (חובה: עסקה שמצבה לא ידוע בקבוצה של "אחרי שיפוץ")
    updated_by  INTEGER REFERENCES users (id),
    updated_at  TEXT NOT NULL,
    PRIMARY KEY (set_id, comp_id)
);
CREATE INDEX comp_set_members_comp ON comp_set_members (comp_id);

CREATE TRIGGER comp_set_members_no_delete BEFORE DELETE ON comp_set_members
BEGIN SELECT RAISE(ABORT, 'comp_set_members: no delete'); END;

-- מה שהיה נכון ב-0.15 נשאר נכון: כל עסקה שנבחרה (או שנקבע לה משקל) שייכת לכל קבוצה של התיק שלה, עם המשקל שהיה לה
INSERT INTO comp_set_members (set_id, comp_id, active, weight, updated_by, updated_at)
SELECT g.id, k.id, 1, k.weight, k.updated_by, k.updated_at
FROM comp_sets g JOIN comps k ON k.case_id = g.case_id
WHERE g.deleted_at IS NULL AND k.deleted_at IS NULL AND (k.status = 'selected' OR k.weight IS NOT NULL);

-- ── חלופות שווי ───────────────────────────────────────────────
CREATE TABLE alternatives (
    id                INTEGER PRIMARY KEY,
    pid               TEXT NOT NULL UNIQUE,
    office_id         INTEGER NOT NULL REFERENCES offices (id),
    case_id           INTEGER NOT NULL REFERENCES cases (id),
    name              TEXT NOT NULL CHECK (name <> ''),
    name_key          TEXT NOT NULL,                                      -- השם מנורמל — לאיסור שתי חלופות עם אותו שם (§10.3)
    sort              INTEGER NOT NULL DEFAULT 0,
    key_date          TEXT,                                               -- המועד הקובע של החלופה; NULL = לא ידוע
    state_kind        TEXT NOT NULL CHECK (state_kind IN ('existing', 'after_works', 'hypothetical', 'other')),
    state_note        TEXT,
    is_primary        INTEGER NOT NULL DEFAULT 0 CHECK (is_primary IN (0, 1)),
    conclusion_value  INTEGER CHECK (conclusion_value IS NULL OR conclusion_value >= 0),   -- באגורות — מקליד השמאי
    conclusion_reason TEXT,
    conclusion_by     INTEGER REFERENCES users (id),
    conclusion_at     TEXT,
    stale             INTEGER NOT NULL DEFAULT 0 CHECK (stale IN (0, 1)),  -- "העבודות השתנו — נדרשת סקירה מחדש" (§7)
    stale_at          TEXT,
    stale_why         TEXT,
    rev               INTEGER NOT NULL DEFAULT 1,
    created_by        INTEGER REFERENCES users (id),
    created_at        TEXT NOT NULL,
    updated_by        INTEGER REFERENCES users (id),
    updated_at        TEXT NOT NULL,
    deleted_at        TEXT,
    deleted_by        INTEGER REFERENCES users (id),
    delete_reason     TEXT,
    -- המסקנה היא של השמאי: אין מסקנה בלי נימוק ובלי מי שקבע אותה (16-A4)
    CHECK (conclusion_value IS NULL OR (conclusion_reason IS NOT NULL AND conclusion_reason <> '' AND conclusion_by IS NOT NULL AND conclusion_at IS NOT NULL)),
    CHECK (deleted_at IS NULL OR is_primary = 0)
);
CREATE INDEX alternatives_case ON alternatives (case_id);
CREATE UNIQUE INDEX alternatives_name ON alternatives (case_id, name_key) WHERE deleted_at IS NULL;
-- תמיד לכל היותר חלופה ראשית אחת (§10.4); "בדיוק אחת" — בקוד, בתוך הטרנזקציה
CREATE UNIQUE INDEX alternatives_primary ON alternatives (case_id) WHERE is_primary = 1 AND deleted_at IS NULL;

CREATE TRIGGER alternatives_no_delete BEFORE DELETE ON alternatives
BEGIN SELECT RAISE(ABORT, 'alternatives: no delete'); END;

CREATE TRIGGER alternatives_rev BEFORE UPDATE ON alternatives
WHEN NEW.rev <> OLD.rev + 1 OR NEW.pid <> OLD.pid OR NEW.office_id <> OLD.office_id OR NEW.case_id <> OLD.case_id
    OR NEW.created_at <> OLD.created_at OR OLD.deleted_at IS NOT NULL
BEGIN SELECT RAISE(ABORT, 'alternatives: rev / identity / frozen'); END;

-- תיקים שכבר נפתחו לפני 0.16: חלופה אחת "מצב קיים" עם המועד הקובע של התיק; ועם הדגל "מתוכנן שיפוץ" — גם "בהנחת השלמת השיפוץ" (§4)
INSERT INTO alternatives (pid, office_id, case_id, name, name_key, sort, key_date, state_kind, is_primary, created_at, updated_at)
SELECT lower(hex(randomblob(8))), c.office_id, c.id, 'מצב קיים', 'מצב קיים', 1, c.key_date, 'existing', 1,
    strftime('%Y-%m-%dT%H:%M:%SZ', 'now'), strftime('%Y-%m-%dT%H:%M:%SZ', 'now')
FROM cases c WHERE c.deleted_at IS NULL AND c.status <> 'draft';

INSERT INTO alternatives (pid, office_id, case_id, name, name_key, sort, key_date, state_kind, is_primary, created_at, updated_at)
SELECT lower(hex(randomblob(8))), c.office_id, c.id, 'בהנחת השלמת השיפוץ', 'בהנחת השלמת השיפוץ', 2, c.key_date, 'after_works', 0,
    strftime('%Y-%m-%dT%H:%M:%SZ', 'now'), strftime('%Y-%m-%dT%H:%M:%SZ', 'now')
FROM cases c WHERE c.deleted_at IS NULL AND c.status <> 'draft' AND json_extract(c.flags, '$.renovation_planned') = 1;

-- ── הנחות: של חלופה, או של התיק כולו ──────────────────────────
CREATE TABLE assumptions (
    id              INTEGER PRIMARY KEY,
    pid             TEXT NOT NULL UNIQUE,
    office_id       INTEGER NOT NULL REFERENCES offices (id),
    case_id         INTEGER NOT NULL REFERENCES cases (id),
    alternative_id  INTEGER REFERENCES alternatives (id),               -- NULL = הנחה של התיק כולו
    text            TEXT NOT NULL CHECK (text <> ''),
    kind            TEXT NOT NULL CHECK (kind IN ('assumption', 'limitation', 'condition')),
    basis           TEXT,
    fact_id         INTEGER REFERENCES facts (id),
    file_id         INTEGER REFERENCES files (id),                      -- המסמך שההנחה מתייחסת אליו (תוכנית העבודות — §4)
    approval        TEXT NOT NULL DEFAULT 'unverified' CHECK (approval IN ('stated', 'supported', 'unverified')),
    sort            INTEGER NOT NULL DEFAULT 0,
    rev             INTEGER NOT NULL DEFAULT 1,
    created_by      INTEGER REFERENCES users (id),
    created_at      TEXT NOT NULL,
    updated_by      INTEGER REFERENCES users (id),
    updated_at      TEXT NOT NULL,
    deleted_at      TEXT
);
CREATE INDEX assumptions_case ON assumptions (case_id, alternative_id);

CREATE TRIGGER assumptions_no_delete BEFORE DELETE ON assumptions
BEGIN SELECT RAISE(ABORT, 'assumptions: no delete'); END;

CREATE TRIGGER assumptions_rev BEFORE UPDATE ON assumptions
WHEN NEW.rev <> OLD.rev + 1 OR NEW.pid <> OLD.pid OR NEW.case_id <> OLD.case_id OR NEW.alternative_id IS NOT OLD.alternative_id OR OLD.deleted_at IS NOT NULL
BEGIN SELECT RAISE(ABORT, 'assumptions: rev / identity / frozen'); END;

-- ── גיליון חישוב ──────────────────────────────────────────────
CREATE TABLE sheets (
    id          INTEGER PRIMARY KEY,
    pid         TEXT NOT NULL UNIQUE,
    office_id   INTEGER NOT NULL REFERENCES offices (id),
    case_id     INTEGER NOT NULL REFERENCES cases (id),
    title       TEXT NOT NULL CHECK (title <> ''),
    rounding    TEXT NOT NULL DEFAULT 'none' CHECK (rounding IN ('none', '1', '100', '1000', '10000')),
    next_key    INTEGER NOT NULL DEFAULT 1,                             -- מפתח שורה אינו חוזר — גם אחרי מחיקה
    rev         INTEGER NOT NULL DEFAULT 1,
    created_by  INTEGER REFERENCES users (id),
    created_at  TEXT NOT NULL,
    updated_by  INTEGER REFERENCES users (id),
    updated_at  TEXT NOT NULL,
    deleted_at  TEXT
);
CREATE INDEX sheets_case ON sheets (case_id);

CREATE TRIGGER sheets_no_delete BEFORE DELETE ON sheets
BEGIN SELECT RAISE(ABORT, 'sheets: no delete'); END;

CREATE TRIGGER sheets_rev BEFORE UPDATE ON sheets
WHEN NEW.rev <> OLD.rev + 1 OR NEW.pid <> OLD.pid OR NEW.office_id <> OLD.office_id OR NEW.case_id <> OLD.case_id
    OR NEW.created_at <> OLD.created_at OR NEW.next_key < OLD.next_key OR OLD.deleted_at IS NOT NULL
BEGIN SELECT RAISE(ABORT, 'sheets: rev / identity / frozen'); END;

CREATE TABLE sheet_rows (
    id           INTEGER PRIMARY KEY,
    sheet_id     INTEGER NOT NULL REFERENCES sheets (id),
    sort         INTEGER NOT NULL,
    key          TEXT NOT NULL CHECK (key GLOB 'r[1-9]*' AND key NOT GLOB 'r*[^0-9]*'),
    label        TEXT NOT NULL CHECK (label <> ''),
    kind         TEXT NOT NULL CHECK (kind IN ('input', 'calc', 'note')),
    value        INTEGER,                                               -- ל-input, במיליוניות; NULL = ריק ("לא ידוע" — לא אפס)
    unit         TEXT,
    time_unit    TEXT NOT NULL DEFAULT 'none' CHECK (time_unit IN ('none', 'month', 'year')),
    source_note  TEXT,
    fact_id      INTEGER REFERENCES facts (id),
    file_id      INTEGER REFERENCES files (id),
    origin       TEXT CHECK (origin IN ('provided', 'estimated', 'assumed')),   -- סופק / הוערך / הנחת השמאי (24.3)
    formula      TEXT,                                                  -- JSON, ל-calc — מבנה, לא טקסט חופשי (§5)
    reasoning    TEXT,
    updated_by   INTEGER REFERENCES users (id),
    updated_at   TEXT NOT NULL,
    UNIQUE (sheet_id, key),
    CHECK (kind = 'input' OR value IS NULL),
    CHECK ((kind = 'calc') = (formula IS NOT NULL)),
    -- ערך שהוזן נושא תמיד את מקורו: סופק, הוערך או הנחה (16-D3)
    CHECK (value IS NULL OR origin IS NOT NULL),
    CHECK (kind = 'input' OR (fact_id IS NULL AND file_id IS NULL AND origin IS NULL))
);
CREATE INDEX sheet_rows_sheet ON sheet_rows (sheet_id, sort);

-- תבנית גיליון: מבנה ותיאורים בלבד — לעולם לא ערכים (§5, 16-B6). owner_user_id NULL = של המשרד.
CREATE TABLE sheet_templates (
    id             INTEGER PRIMARY KEY,
    pid            TEXT NOT NULL UNIQUE,
    office_id      INTEGER NOT NULL REFERENCES offices (id),
    owner_user_id  INTEGER REFERENCES users (id),
    name           TEXT NOT NULL CHECK (name <> ''),
    rounding       TEXT NOT NULL CHECK (rounding IN ('none', '1', '100', '1000', '10000')),
    rows           TEXT NOT NULL CHECK (rows NOT LIKE '%"value"%' AND rows NOT LIKE '%"source_note"%'),
    created_by     INTEGER REFERENCES users (id),
    created_at     TEXT NOT NULL,
    deleted_at     TEXT
);
CREATE INDEX sheet_templates_office ON sheet_templates (office_id, owner_user_id);

-- ── גישות בתוך חלופה ──────────────────────────────────────────
CREATE TABLE approaches (
    id              INTEGER PRIMARY KEY,
    pid             TEXT NOT NULL UNIQUE,
    office_id       INTEGER NOT NULL REFERENCES offices (id),
    case_id         INTEGER NOT NULL REFERENCES cases (id),
    alternative_id  INTEGER NOT NULL REFERENCES alternatives (id),
    kind            TEXT NOT NULL CHECK (kind IN ('comparison', 'income', 'cost', 'other')),
    title           TEXT NOT NULL CHECK (title <> ''),
    comp_set_id     INTEGER REFERENCES comp_sets (id),
    sheet_id        INTEGER REFERENCES sheets (id),
    weight          INTEGER CHECK (weight IS NULL OR weight >= 0),       -- במיליוניות
    result_value    INTEGER CHECK (result_value IS NULL OR result_value >= 0),   -- באגורות — רק כשהשמאי הקליד (entered); תוצאה מחושבת אינה נשמרת
    result_source   TEXT NOT NULL DEFAULT 'computed' CHECK (result_source IN ('computed', 'entered')),
    result_reason   TEXT,
    result_by       INTEGER REFERENCES users (id),
    result_at       TEXT,
    reasoning       TEXT NOT NULL CHECK (reasoning <> ''),               -- למה הגישה הזו, ולמה במשקל הזה
    sort            INTEGER NOT NULL DEFAULT 0,
    rev             INTEGER NOT NULL DEFAULT 1,
    created_by      INTEGER REFERENCES users (id),
    created_at      TEXT NOT NULL,
    updated_by      INTEGER REFERENCES users (id),
    updated_at      TEXT NOT NULL,
    deleted_at      TEXT,
    CHECK (kind = 'comparison' OR comp_set_id IS NULL),
    CHECK (kind <> 'comparison' OR sheet_id IS NULL),
    -- גישת ההשוואה נגזרת מקבוצת ההשוואה בלבד (§6)
    CHECK (kind <> 'comparison' OR result_source = 'computed'),
    CHECK ((result_source = 'entered') = (result_value IS NOT NULL)),
    -- תוצאה מוקלדת — רק עם נימוק ועם מי שהקליד (16-A4)
    CHECK (result_value IS NULL OR (result_reason IS NOT NULL AND result_reason <> '' AND result_by IS NOT NULL AND result_at IS NOT NULL))
);
CREATE INDEX approaches_alt ON approaches (alternative_id);
CREATE INDEX approaches_sheet ON approaches (sheet_id);
CREATE INDEX approaches_set ON approaches (comp_set_id);

CREATE TRIGGER approaches_no_delete BEFORE DELETE ON approaches
BEGIN SELECT RAISE(ABORT, 'approaches: no delete'); END;

CREATE TRIGGER approaches_rev BEFORE UPDATE ON approaches
WHEN NEW.rev <> OLD.rev + 1 OR NEW.pid <> OLD.pid OR NEW.case_id <> OLD.case_id OR NEW.alternative_id <> OLD.alternative_id OR OLD.deleted_at IS NOT NULL
BEGIN SELECT RAISE(ABORT, 'approaches: rev / identity / frozen'); END;

-- ── רכישה ושיפוץ: ארבעה סוגים, ארבעה מקומות (§7) ──────────────
CREATE TABLE renovation_items (
    id             INTEGER PRIMARY KEY,
    pid            TEXT NOT NULL UNIQUE,
    office_id      INTEGER NOT NULL REFERENCES offices (id),
    case_id        INTEGER NOT NULL REFERENCES cases (id),
    kind           TEXT NOT NULL CHECK (kind IN ('contract_price', 'declared_budget', 'quote', 'verified_cost')),
    amount         INTEGER CHECK (amount IS NULL OR amount >= 0),       -- באגורות; NULL = לא ידוע (הצעה שיש לה רק מסמך)
    item_date      TEXT,
    provider       TEXT,
    version_no     INTEGER NOT NULL DEFAULT 1 CHECK (version_no >= 1),
    supersedes_id  INTEGER REFERENCES renovation_items (id),
    file_id        INTEGER REFERENCES files (id),
    fact_id        INTEGER REFERENCES facts (id),
    scope_note     TEXT NOT NULL DEFAULT '',
    entered_by     INTEGER NOT NULL REFERENCES users (id),              -- כל ערך מקושר למי שהזין אותו
    created_at     TEXT NOT NULL,
    deleted_at     TEXT,
    deleted_by     INTEGER REFERENCES users (id),
    delete_reason  TEXT,
    -- גרסאות — רק להצעת מחיר
    CHECK (supersedes_id IS NULL OR kind = 'quote'),
    CHECK ((supersedes_id IS NULL) = (version_no = 1)),
    CHECK (supersedes_id IS NULL OR supersedes_id <> id)
);
CREATE INDEX renovation_items_case ON renovation_items (case_id, kind);
-- להצעה יש לכל היותר גרסה אחת שמחליפה אותה
CREATE UNIQUE INDEX renovation_items_supersedes ON renovation_items (supersedes_id) WHERE supersedes_id IS NOT NULL AND deleted_at IS NULL;

CREATE TRIGGER renovation_items_no_delete BEFORE DELETE ON renovation_items
BEGIN SELECT RAISE(ABORT, 'renovation_items: no delete'); END;

-- פריט אינו נערך: תיקון = פריט חדש (או גרסה חדשה); מה שהוזן נשאר. מותר רק לסמן מחיקה, פעם אחת.
CREATE TRIGGER renovation_items_frozen BEFORE UPDATE ON renovation_items
WHEN OLD.deleted_at IS NOT NULL OR NEW.deleted_at IS NULL OR NEW.pid <> OLD.pid OR NEW.case_id <> OLD.case_id OR NEW.office_id <> OLD.office_id OR NEW.kind <> OLD.kind
    OR NEW.amount IS NOT OLD.amount OR NEW.item_date IS NOT OLD.item_date OR NEW.provider IS NOT OLD.provider OR NEW.version_no <> OLD.version_no
    OR NEW.supersedes_id IS NOT OLD.supersedes_id OR NEW.file_id IS NOT OLD.file_id OR NEW.fact_id IS NOT OLD.fact_id OR NEW.scope_note <> OLD.scope_note
    OR NEW.entered_by <> OLD.entered_by OR NEW.created_at <> OLD.created_at
BEGIN SELECT RAISE(ABORT, 'renovation_items: frozen'); END;
