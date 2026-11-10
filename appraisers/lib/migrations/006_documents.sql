-- פרוסה 06 (docs/spec/06-documents.md §3): מצבי דרישה, קבצים, שיוך קובץ לדרישה (גרסאות ובדיקה), תגיות ותמונות ממוזערות.

ALTER TABLE requirements ADD COLUMN status_by INTEGER REFERENCES users (id);
ALTER TABLE requirements ADD COLUMN status_at TEXT;
ALTER TABLE requirements ADD COLUMN split_from_id INTEGER REFERENCES requirements (id);
-- המקורית שפוצלה לגורמים: סגורה כ"פוצלה", אינה נספרת ואינה מקבלת קבצים
ALTER TABLE requirements ADD COLUMN split_at TEXT;

-- הקובץ עצמו יושב ב-data/files/<storage_key>, בלי סיומת. original_name להצגה בלבד — לעולם לא נתיב.
CREATE TABLE files (
    id               INTEGER PRIMARY KEY,
    pid              TEXT NOT NULL UNIQUE,
    office_id        INTEGER NOT NULL REFERENCES offices (id),
    case_id          INTEGER NOT NULL REFERENCES cases (id),
    sha256           TEXT NOT NULL,
    size             INTEGER NOT NULL,
    mime             TEXT NOT NULL,                       -- כפי שזוהה מהתוכן
    ext              TEXT NOT NULL,                       -- נגזר מ-mime
    original_name    TEXT NOT NULL,
    storage_key      TEXT NOT NULL UNIQUE,
    uploader_kind    TEXT NOT NULL DEFAULT 'user' CHECK (uploader_kind IN ('user', 'recipient', 'system')),
    uploader_id      INTEGER REFERENCES users (id),
    channel          TEXT NOT NULL DEFAULT 'internal' CHECK (channel IN ('internal', 'portal', 'email', 'other')),
    source_note      TEXT,
    doc_date         TEXT,                                -- תאריך הפקת המסמך; NULL = לא ידוע
    received_at      TEXT NOT NULL,
    scan_state       TEXT NOT NULL DEFAULT 'not_scanned' CHECK (scan_state IN ('not_scanned', 'clean', 'rejected')),
    pages            INTEGER,
    is_encrypted     INTEGER NOT NULL DEFAULT 0,          -- PDF מוגן בסיסמה: נשמר, והבודק רואה
    idempotency_key  TEXT UNIQUE,                         -- "<מזהה המשתמש>:<מזהה הפעולה>"
    deleted_at       TEXT,
    deleted_by       INTEGER REFERENCES users (id),
    delete_reason    TEXT
);
CREATE INDEX files_case ON files (case_id, deleted_at);
CREATE INDEX files_sha ON files (case_id, sha256);

-- שיוך קובץ לדרישה. לכל שיוך בדיקה משלו — אותו קובץ בשתי דרישות נבדק פעמיים.
CREATE TABLE requirement_files (
    id              INTEGER PRIMARY KEY,
    pid             TEXT NOT NULL UNIQUE,
    case_id         INTEGER NOT NULL REFERENCES cases (id),
    requirement_id  INTEGER NOT NULL REFERENCES requirements (id),
    file_id         INTEGER NOT NULL REFERENCES files (id),
    relation        TEXT NOT NULL DEFAULT 'version' CHECK (relation IN ('version', 'attachment')),
    version_no      INTEGER NOT NULL,                     -- לגרסה: מספרה; לנלווה: הגרסה שאליה הצטרף
    review_state    TEXT NOT NULL DEFAULT 'pending' CHECK (review_state IN ('pending', 'approved', 'returned', 'rejected')),
    chk_readable    INTEGER, chk_complete INTEGER, chk_matches INTEGER,    -- NULL = לא נבדק
    review_note     TEXT,                                 -- פנימי
    return_code     TEXT,
    return_reason   TEXT,                                 -- מוצג לנמען
    reviewed_by     INTEGER REFERENCES users (id),
    reviewed_at     TEXT,
    status_before   TEXT NOT NULL DEFAULT 'required',     -- מצב הדרישה לפני השיוך — לשם חוזרים בדחייה
    created_by      INTEGER REFERENCES users (id),
    created_at      TEXT NOT NULL
);
CREATE INDEX requirement_files_req ON requirement_files (requirement_id);
CREATE INDEX requirement_files_file ON requirement_files (file_id);
CREATE UNIQUE INDEX requirement_files_active ON requirement_files (requirement_id, file_id) WHERE review_state <> 'rejected';
CREATE UNIQUE INDEX requirement_files_version ON requirement_files (requirement_id, version_no) WHERE relation = 'version';

CREATE TABLE file_tags (
    file_id  INTEGER NOT NULL REFERENCES files (id),
    tag      TEXT NOT NULL,
    PRIMARY KEY (file_id, tag)
);

-- מטמון; נבנה מחדש לפי דרישה
CREATE TABLE file_thumbs (
    file_id      INTEGER PRIMARY KEY REFERENCES files (id),
    storage_key  TEXT NOT NULL,
    w            INTEGER NOT NULL,
    h            INTEGER NOT NULL
);
