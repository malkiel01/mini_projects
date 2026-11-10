-- פרוסה 04 (docs/spec/04-cases.md §3): תיקים, מזהי נכס, אנשי קשר וגורמים, משתתפים, משימות ודרישות.

CREATE TABLE cases (
    id                   INTEGER PRIMARY KEY,
    pid                  TEXT NOT NULL UNIQUE,
    office_id            INTEGER NOT NULL REFERENCES offices (id),
    number               TEXT NOT NULL,                      -- '2026-0001' — רץ למשרד, לא משתנה לעולם
    domain               TEXT NOT NULL DEFAULT 'real_estate',
    status               TEXT NOT NULL DEFAULT 'draft' CHECK (status IN
                         ('draft', 'collecting', 'awaiting_reply', 'checking', 'visit', 'analysis', 'review', 'approved', 'delivered', 'closed')),
    title                TEXT NOT NULL DEFAULT '',
    purpose              TEXT,                               -- NULL = לא ידוע, להשלמה
    purpose_note         TEXT,
    property_type        TEXT,
    key_date             TEXT,                               -- YYYY-MM-DD; NULL = לא ידוע
    due_date             TEXT,
    flags                TEXT NOT NULL DEFAULT '{}',         -- JSON: הדגלים שסומנו
    profile_id           INTEGER NOT NULL REFERENCES profiles (id),
    profile_version_id   INTEGER NOT NULL REFERENCES profile_versions (id),
    plan_snapshot        TEXT NOT NULL DEFAULT '{}',         -- JSON: מה נוצר מהגרסה, ומה הוחל אחר כך
    responsible_user_id  INTEGER REFERENCES users (id),      -- NULL — האחראי עזב ועוד לא הוחלף
    created_by           INTEGER NOT NULL REFERENCES users (id),
    created_at           TEXT NOT NULL,
    updated_at           TEXT NOT NULL,
    closed_at            TEXT,
    close_reason         TEXT,
    no_ai                INTEGER NOT NULL DEFAULT 0,
    deleted_at           TEXT,
    UNIQUE (office_id, number)
);
CREATE INDEX cases_office ON cases (office_id, status);

-- מונה מספרי התיק: מתעדכן בטרנזקציה. מספר שהוקצה אינו חוזר לשימוש.
CREATE TABLE case_counters (
    office_id  INTEGER NOT NULL REFERENCES offices (id),
    year       INTEGER NOT NULL,
    last       INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (office_id, year)
);

CREATE TABLE case_identifiers (
    id            INTEGER PRIMARY KEY,
    pid           TEXT NOT NULL UNIQUE,
    case_id       INTEGER NOT NULL REFERENCES cases (id),
    kind          TEXT NOT NULL CHECK (kind IN ('address', 'parcel', 'apartment', 'building_file', 'other')),
    locality      TEXT, municipality TEXT, street TEXT, house_no TEXT, apartment_no TEXT,
    gush          TEXT, helka TEXT, tat_helka TEXT,           -- tat_helka ריק = NULL; מספר דירה אינו תת-חלקה
    value_text    TEXT,
    source_kind   TEXT NOT NULL DEFAULT 'user' CHECK (source_kind IN ('user', 'document', 'source_check', 'visit')),
    source_ref    TEXT,
    source_note   TEXT,                                       -- "נמסר מהלקוח", "מהסכם" …
    state         TEXT NOT NULL DEFAULT 'entered' CHECK (state IN ('entered', 'needs_check', 'verified', 'conflicting')),
    note          TEXT,
    match_key     TEXT,                                       -- מנורמל, לאיתור "מזהה דומה" במשרד
    created_by    INTEGER NOT NULL REFERENCES users (id),
    created_at    TEXT NOT NULL,
    removed_at    TEXT
);
CREATE INDEX case_identifiers_case ON case_identifiers (case_id);
CREATE INDEX case_identifiers_match ON case_identifiers (match_key);

CREATE TABLE contacts (
    id          INTEGER PRIMARY KEY,
    pid         TEXT NOT NULL UNIQUE,
    office_id   INTEGER NOT NULL REFERENCES offices (id),
    name        TEXT NOT NULL,
    org         TEXT, email TEXT, phone TEXT, note TEXT,
    created_at  TEXT NOT NULL,
    updated_at  TEXT NOT NULL,
    deleted_at  TEXT
);
CREATE INDEX contacts_office ON contacts (office_id);

CREATE TABLE case_parties (
    id          INTEGER PRIMARY KEY,
    pid         TEXT NOT NULL UNIQUE,
    case_id     INTEGER NOT NULL REFERENCES cases (id),
    contact_id  INTEGER NOT NULL REFERENCES contacts (id),
    kind        TEXT NOT NULL CHECK (kind IN ('client', 'lawyer', 'bank', 'architect', 'surveyor', 'other')),
    is_orderer  INTEGER NOT NULL DEFAULT 0,
    note        TEXT,
    created_at  TEXT NOT NULL,
    removed_at  TEXT
);
CREATE INDEX case_parties_case ON case_parties (case_id);
CREATE INDEX case_parties_contact ON case_parties (contact_id);

-- אין גישה לתיק בלי שורה פעילה כאן — גם לא למנהל המשרד.
CREATE TABLE case_members (
    id          INTEGER PRIMARY KEY,
    case_id     INTEGER NOT NULL REFERENCES cases (id),
    user_id     INTEGER NOT NULL REFERENCES users (id),
    kind        TEXT NOT NULL DEFAULT 'internal',             -- cross_office, support — פרוסה 05
    role        TEXT NOT NULL CHECK (role IN ('responsible', 'partner', 'visitor', 'staff')),
    perms       TEXT NOT NULL,                                -- JSON
    added_by    INTEGER REFERENCES users (id),
    created_at  TEXT NOT NULL,
    revoked_at  TEXT,
    revoked_by  INTEGER REFERENCES users (id)
);
CREATE UNIQUE INDEX case_members_active ON case_members (case_id, user_id) WHERE revoked_at IS NULL;
CREATE UNIQUE INDEX case_one_responsible ON case_members (case_id) WHERE revoked_at IS NULL AND role = 'responsible';
CREATE INDEX case_members_user ON case_members (user_id, revoked_at);

CREATE TABLE tasks (
    id                INTEGER PRIMARY KEY,
    pid               TEXT NOT NULL UNIQUE,
    office_id         INTEGER NOT NULL REFERENCES offices (id),
    case_id           INTEGER NOT NULL REFERENCES cases (id),
    kind              TEXT NOT NULL DEFAULT 'manual' CHECK (kind IN ('manual', 'profile', 'rule', 'clarification', 'followup', 'system')),
    title             TEXT NOT NULL,
    detail            TEXT,
    assignee_user_id  INTEGER REFERENCES users (id),
    due_at            TEXT,
    status            TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'in_progress', 'done', 'cancelled')),
    link_type         TEXT, link_pid TEXT,
    origin_key        TEXT,
    cancel_reason     TEXT,
    created_by        INTEGER REFERENCES users (id),
    created_at        TEXT NOT NULL,
    updated_at        TEXT NOT NULL,
    done_at           TEXT
);
CREATE INDEX tasks_case ON tasks (case_id, status);
CREATE INDEX tasks_assignee ON tasks (assignee_user_id, status);

-- נוצרות כאן; הטיפול המלא (קבצים, בדיקה, מצבים) — פרוסה 06.
CREATE TABLE requirements (
    id                 INTEGER PRIMARY KEY,
    pid                TEXT NOT NULL UNIQUE,
    office_id          INTEGER NOT NULL REFERENCES offices (id),
    case_id            INTEGER NOT NULL REFERENCES cases (id),
    origin             TEXT NOT NULL CHECK (origin IN ('profile', 'rule', 'manual')),
    origin_key         TEXT, group_key TEXT, doc_type TEXT,
    title              TEXT NOT NULL,
    explain_recipient  TEXT, explain_internal TEXT,
    priority           TEXT NOT NULL DEFAULT 'normal',
    mandatory          INTEGER NOT NULL DEFAULT 0,
    sort               INTEGER NOT NULL DEFAULT 0,
    party_kind         TEXT,                                  -- איזה סוג גורם מספק (מהפרופיל)
    party_id           INTEGER REFERENCES case_parties (id),  -- למי מוקצה בפועל
    due_at             TEXT,
    status             TEXT NOT NULL DEFAULT 'required',
    status_reason      TEXT,
    created_at         TEXT NOT NULL,
    updated_at         TEXT NOT NULL
);
CREATE INDEX requirements_case ON requirements (case_id);

-- לפיד הפעילות של התיק
ALTER TABLE audit_log ADD COLUMN case_id INTEGER;
CREATE INDEX audit_case ON audit_log (case_id, id) WHERE case_id IS NOT NULL;
