-- פרוסה 03 (docs/spec/03-profiles.md §3): פרופילים — ספרייה, גרסאות, שיתוף, וסוגי מסמך.

CREATE TABLE profiles (
    id                  INTEGER PRIMARY KEY,
    pid                 TEXT NOT NULL UNIQUE,
    scope               TEXT NOT NULL CHECK (scope IN ('recommended', 'office', 'personal')),
    office_id           INTEGER REFERENCES offices (id),          -- NULL רק ל-recommended
    owner_user_id       INTEGER REFERENCES users (id),            -- מי יצר; ב-personal — הבעלים. NULL — נזרע בידי המערכת
    domain              TEXT NOT NULL DEFAULT 'real_estate',
    name                TEXT NOT NULL,
    description         TEXT NOT NULL DEFAULT '',
    purposes            TEXT NOT NULL DEFAULT '[]',               -- JSON
    property_types      TEXT NOT NULL DEFAULT '[]',               -- JSON
    status              TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'active', 'archived')),
    current_version_id  INTEGER REFERENCES profile_versions (id), -- הגרסה המפורסמת האחרונה
    source_version_id   INTEGER REFERENCES profile_versions (id), -- ממה שוכפל
    is_sample           INTEGER NOT NULL DEFAULT 0,               -- "דוגמה — טעון אישור מקצועי"
    created_at          TEXT NOT NULL,
    updated_at          TEXT NOT NULL,
    deleted_at          TEXT,
    CHECK ((scope = 'recommended') = (office_id IS NULL))
);
CREATE INDEX profiles_office ON profiles (office_id, scope, status);
CREATE INDEX profiles_owner ON profiles (owner_user_id);

-- גרסה מפורסמת אינה נערכת ואינה נמחקת — לעולם (תיקים מפנים אליה). הטריגרים אוכפים את זה גם מול קוד עתידי.
CREATE TABLE profile_versions (
    id            INTEGER PRIMARY KEY,
    pid           TEXT NOT NULL UNIQUE,
    profile_id    INTEGER NOT NULL REFERENCES profiles (id),
    number        INTEGER,                                        -- 1, 2, 3 … רק למפורסמות; הטיוטה = NULL
    state         TEXT NOT NULL DEFAULT 'draft' CHECK (state IN ('draft', 'published')),
    content       TEXT NOT NULL,                                  -- JSON
    change_note   TEXT,
    published_by  INTEGER REFERENCES users (id),
    published_at  TEXT,
    updated_by    INTEGER REFERENCES users (id),
    created_at    TEXT NOT NULL,
    updated_at    TEXT NOT NULL,
    UNIQUE (profile_id, number),
    CHECK ((state = 'published') = (number IS NOT NULL))
);
CREATE UNIQUE INDEX profile_one_draft ON profile_versions (profile_id) WHERE state = 'draft';
CREATE TRIGGER profile_version_frozen BEFORE UPDATE ON profile_versions WHEN OLD.state = 'published'
    BEGIN SELECT RAISE(ABORT, 'published profile version is immutable'); END;
CREATE TRIGGER profile_version_kept BEFORE DELETE ON profile_versions WHEN OLD.state = 'published'
    BEGIN SELECT RAISE(ABORT, 'published profile version is never deleted'); END;

CREATE TABLE profile_shares (
    id            INTEGER PRIMARY KEY,
    pid           TEXT NOT NULL UNIQUE,
    profile_id    INTEGER NOT NULL REFERENCES profiles (id),
    grantee_kind  TEXT NOT NULL CHECK (grantee_kind IN ('user', 'office')),
    grantee_id    INTEGER NOT NULL,
    can           TEXT NOT NULL CHECK (can IN ('view', 'duplicate', 'edit')),
    granted_by    INTEGER NOT NULL REFERENCES users (id),
    created_at    TEXT NOT NULL,
    revoked_at    TEXT,
    revoked_by    INTEGER REFERENCES users (id)
);
CREATE INDEX profile_shares_grantee ON profile_shares (grantee_kind, grantee_id, revoked_at);
CREATE INDEX profile_shares_profile ON profile_shares (profile_id);

-- סוגי מסמך: מנהל המערכת מוסיף ועורך; סוג אינו נמחק — רק מוסתר.
CREATE TABLE doc_types (
    key          TEXT PRIMARY KEY,
    name         TEXT NOT NULL,
    description  TEXT NOT NULL DEFAULT '',
    hidden       INTEGER NOT NULL DEFAULT 0,
    sort         INTEGER NOT NULL DEFAULT 0
);
INSERT INTO doc_types (key, name, description, sort) VALUES
    ('contract',       'הסכם ונספחים',            'הסכם מכר או הסכם אחר, כולל הנספחים', 1),
    ('rights',         'מסמך זכויות',             'נסח רישום או אישור זכויות', 2),
    ('permit',         'היתר בנייה',              '', 3),
    ('plans',          'תוכניות',                 'תוכניות בנייה או תשריט', 4),
    ('survey',         'מסמך מדידה',              '', 5),
    ('purchase',       'מסמכי רכישה',             '', 6),
    ('works_quote',    'הצעת מחיר לשיפוץ',        '', 7),
    ('quantities',     'פירוט כמויות',            '', 8),
    ('lease',          'חוזה שכירות',             '', 9),
    ('client_docs',    'מסמכי מזמין',             'דרישות והנחיות של המזמין או הבנק', 10),
    ('other',          'אחר',                     '', 99);
