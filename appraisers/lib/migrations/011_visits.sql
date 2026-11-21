-- פרוסה 13 (docs/spec/13-visit.md §3): ביקורים בתיק, תשובות הטופס, תמונות, והקישור מתשובה לנתון.
-- אינה נוגעת באף טבלה קיימת.
-- מה שהאפיון דורש שלא ישתנה — נאכף כאן בטריגרים, לא רק בקוד. recursive_triggers דולק: אף טריגר כאן אינו כותב לטבלה של עצמו.

-- הגדרת משרד "לצרף מיקום לתמונות" (§6.3): אין שורה = כבוי
CREATE TABLE office_visit_settings (
    office_id   INTEGER PRIMARY KEY REFERENCES offices (id),
    photo_geo   INTEGER NOT NULL DEFAULT 0 CHECK (photo_geo IN (0, 1)),
    updated_at  TEXT NOT NULL
);

CREATE TABLE visits (
    id                 INTEGER PRIMARY KEY,
    pid                TEXT NOT NULL UNIQUE,
    office_id          INTEGER NOT NULL REFERENCES offices (id),
    case_id            INTEGER NOT NULL REFERENCES cases (id),
    seq                INTEGER NOT NULL CHECK (seq >= 1),            -- ביקור 1, 2, 3 … בתיק
    visitor_user_id    INTEGER NOT NULL REFERENCES users (id),
    scheduled_at       TEXT,                                          -- שעון ישראל: YYYY-MM-DDTHH:MM
    started_at         TEXT,
    completed_at       TEXT,
    first_completed_at TEXT,                                          -- נכתב פעם אחת: ביקור שהושלם אי פעם אינו הופך ל"נדחה" או ל"לא הייתה גישה"
    status             TEXT NOT NULL DEFAULT 'planned' CHECK (status IN ('planned', 'in_progress', 'completed', 'postponed', 'no_access', 'cancelled')),
    status_note        TEXT,
    access_contact_id  INTEGER REFERENCES contacts (id),
    access_note        TEXT,
    attendees          TEXT,
    form_snapshot      TEXT,                                          -- JSON: הטופס כפי שהיה ב"התחלת ביקור"
    form_version_id    INTEGER REFERENCES profile_versions (id),
    rev                INTEGER NOT NULL DEFAULT 1,
    created_by         INTEGER REFERENCES users (id),
    created_at         TEXT NOT NULL,
    updated_at         TEXT NOT NULL,
    UNIQUE (case_id, seq),
    CHECK (status NOT IN ('in_progress', 'completed') OR (form_snapshot IS NOT NULL AND started_at IS NOT NULL)),
    CHECK (status <> 'completed' OR (completed_at IS NOT NULL AND first_completed_at IS NOT NULL)),
    CHECK (status NOT IN ('postponed', 'no_access', 'cancelled') OR status_note IS NOT NULL)
);
CREATE INDEX visits_case ON visits (case_id, status);
CREATE INDEX visits_visitor ON visits (visitor_user_id, status);

CREATE TRIGGER visits_no_delete BEFORE DELETE ON visits
BEGIN SELECT RAISE(ABORT, 'visits: no delete'); END;

-- כל שינוי מקדם את rev באחד בדיוק; הזהות אינה משתנה
CREATE TRIGGER visits_rev BEFORE UPDATE ON visits
WHEN NEW.rev <> OLD.rev + 1 OR NEW.pid <> OLD.pid OR NEW.office_id <> OLD.office_id OR NEW.case_id <> OLD.case_id
    OR NEW.seq <> OLD.seq OR NEW.created_at <> OLD.created_at
BEGIN SELECT RAISE(ABORT, 'visits: rev / identity'); END;

-- הטופס מצולם פעם אחת — ב"התחלת ביקור" — ואינו משתנה עוד (13-A1)
CREATE TRIGGER visits_snapshot_once BEFORE UPDATE ON visits
WHEN OLD.form_snapshot IS NOT NULL AND (NEW.form_snapshot IS NOT OLD.form_snapshot OR NEW.form_version_id IS NOT OLD.form_version_id)
BEGIN SELECT RAISE(ABORT, 'visits: snapshot is immutable'); END;

-- ביקור שהושלם אי פעם אינו הופך ל"נדחה", ל"לא הייתה גישה" או ל"בוטל" (13-A6), והסימון עצמו אינו נמחק
CREATE TRIGGER visits_once_completed BEFORE UPDATE ON visits
WHEN OLD.first_completed_at IS NOT NULL AND (NEW.first_completed_at IS NOT OLD.first_completed_at OR NEW.status IN ('postponed', 'no_access', 'cancelled', 'planned'))
BEGIN SELECT RAISE(ABORT, 'visits: was completed'); END;

-- שורות של קבוצה חוזרת (חדר 1, חדר 2 …)
CREATE TABLE visit_rows (
    id          INTEGER PRIMARY KEY,
    visit_id    INTEGER NOT NULL REFERENCES visits (id),
    group_key   TEXT NOT NULL,
    row_no      INTEGER NOT NULL CHECK (row_no >= 1),
    created_by  INTEGER REFERENCES users (id),
    created_at  TEXT NOT NULL,
    deleted_at  TEXT,
    UNIQUE (visit_id, group_key, row_no)
);

CREATE TABLE visit_answers (
    id             INTEGER PRIMARY KEY,
    visit_id       INTEGER NOT NULL REFERENCES visits (id),
    field_key      TEXT NOT NULL,
    row_no         INTEGER NOT NULL DEFAULT 0 CHECK (row_no >= 0),   -- 0, או מספר שורה בקבוצה חוזרת
    value_text     TEXT,
    value_num      INTEGER,                                          -- במאיות; כן/לא: 100 / 0
    value_date     TEXT,
    value_json     TEXT,
    unit           TEXT,
    area_kind      TEXT CHECK (area_kind IN ('registered', 'built', 'gross', 'net', 'primary', 'other', 'unknown')),
    obtained       TEXT NOT NULL CHECK (obtained IN ('observed', 'reported', 'measured', 'estimated', 'not_verified')),
    obtained_from  TEXT,                                             -- "נמסר" — ממי
    note           TEXT,
    copied_from_seq INTEGER,                                         -- "הועתק מביקור N — לאמת"; מתנקה בשמירה של המבקר
    rev            INTEGER NOT NULL DEFAULT 1,
    updated_by     INTEGER REFERENCES users (id),
    updated_at     TEXT NOT NULL,
    client_op_id   TEXT,
    UNIQUE (visit_id, field_key, row_no)
);

-- תשובה נכתבת רק בביקור פתוח: ביקור שנסגר (או ביקור אחר) אינו משתנה (13-A7)
CREATE TRIGGER visit_answers_open_ins BEFORE INSERT ON visit_answers
WHEN (SELECT status FROM visits WHERE id = NEW.visit_id) IS NOT 'in_progress'
BEGIN SELECT RAISE(ABORT, 'visit_answers: visit is not open'); END;

CREATE TRIGGER visit_answers_open_upd BEFORE UPDATE ON visit_answers
WHEN (SELECT status FROM visits WHERE id = OLD.visit_id) IS NOT 'in_progress'
BEGIN SELECT RAISE(ABORT, 'visit_answers: visit is not open'); END;

CREATE TRIGGER visit_answers_rev BEFORE UPDATE ON visit_answers
WHEN NEW.rev <> OLD.rev + 1 OR NEW.visit_id <> OLD.visit_id OR NEW.field_key <> OLD.field_key OR NEW.row_no <> OLD.row_no
BEGIN SELECT RAISE(ABORT, 'visit_answers: rev / identity'); END;

-- תשובה אינה נמחקת: שדה שנוקה נשאר שורה בלי ערך, כדי שמספר הגרסה שלו לא יתחיל מחדש
CREATE TRIGGER visit_answers_no_delete BEFORE DELETE ON visit_answers
BEGIN SELECT RAISE(ABORT, 'visit_answers: no delete'); END;

CREATE TABLE visit_photos (
    id            INTEGER PRIMARY KEY,
    pid           TEXT NOT NULL UNIQUE,
    visit_id      INTEGER NOT NULL REFERENCES visits (id),
    file_id       INTEGER NOT NULL UNIQUE REFERENCES files (id),
    field_key     TEXT,
    row_no        INTEGER NOT NULL DEFAULT 0,
    caption       TEXT NOT NULL DEFAULT '',
    place         TEXT NOT NULL DEFAULT '',
    sort          INTEGER NOT NULL DEFAULT 0,
    for_report    INTEGER NOT NULL DEFAULT 0 CHECK (for_report IN (0, 1)),
    usage         TEXT NOT NULL DEFAULT 'private' CHECK (usage IN ('private', 'case', 'report')),
    rights_by     INTEGER REFERENCES users (id),                     -- מי אישר "צולמה בידי / יש לי רשות להשתמש בה בדוח"
    rights_at     TEXT,
    taken_at      TEXT,
    geo_lat       REAL, geo_lng REAL, geo_acc REAL,                  -- רק בהסכמת המבקר במכשיר, כשהגדרת המשרד דולקת (§6.3)
    rev           INTEGER NOT NULL DEFAULT 1,
    client_op_id  TEXT,
    created_by    INTEGER REFERENCES users (id),
    created_at    TEXT NOT NULL,
    updated_at    TEXT NOT NULL,
    deleted_at    TEXT,
    deleted_by    INTEGER REFERENCES users (id),
    -- "לדוח" רק לתמונה שהמבקר אישר שמותר להשתמש בה בדוח (13-C2)
    CHECK (for_report = 0 OR usage = 'report'),
    CHECK (usage <> 'report' OR (rights_by IS NOT NULL AND rights_at IS NOT NULL)),
    CHECK ((geo_lat IS NULL) = (geo_lng IS NULL))
);
CREATE INDEX visit_photos_visit ON visit_photos (visit_id, sort);

CREATE TRIGGER visit_photos_no_delete BEFORE DELETE ON visit_photos
BEGIN SELECT RAISE(ABORT, 'visit_photos: no delete'); END;

CREATE TRIGGER visit_photos_rev BEFORE UPDATE ON visit_photos
WHEN NEW.rev <> OLD.rev + 1 OR NEW.pid <> OLD.pid OR NEW.visit_id <> OLD.visit_id OR NEW.file_id <> OLD.file_id
BEGIN SELECT RAISE(ABORT, 'visit_photos: rev / identity'); END;

-- תמונה שנמחקה נשארת כמו שהיא
CREATE TRIGGER visit_photos_frozen BEFORE UPDATE ON visit_photos
WHEN OLD.deleted_at IS NOT NULL
BEGIN SELECT RAISE(ABORT, 'visit_photos: frozen'); END;

-- איזה נתון נוצר מאיזו תשובה: סימון השלמה חוזר (או מקביל) אינו יוצר נתון שני לאותה תשובה (13-B1)
CREATE TABLE visit_facts (
    visit_id    INTEGER NOT NULL REFERENCES visits (id),
    field_key   TEXT NOT NULL,
    fact_id     INTEGER NOT NULL REFERENCES facts (id),
    created_at  TEXT NOT NULL,
    PRIMARY KEY (visit_id, field_key)
);
CREATE INDEX visit_facts_fact ON visit_facts (fact_id);
