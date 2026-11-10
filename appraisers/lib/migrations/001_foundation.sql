-- תשתית: משתמשים, session, משרדים, חברות במשרד, יומן פעולות ומצב שירותי רקע.
-- זמנים — UTC בצורה "YYYY-MM-DDTHH:MM:SSZ" (ממוינת כמחרוזת); sessions.last_seen — שניות unix.

-- משתמש של האפליקציה. platform_sub ריק = הוזמן במייל ועוד לא נכנס דרך הפלטפורמה.
CREATE TABLE users (
    id                  INTEGER PRIMARY KEY,
    platform_sub        TEXT UNIQUE,
    email               TEXT COLLATE NOCASE,
    display_name        TEXT NOT NULL DEFAULT '',
    status              TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended')),
    is_platform_manager INTEGER NOT NULL DEFAULT 0,
    is_developer        INTEGER NOT NULL DEFAULT 0,
    created_at          TEXT NOT NULL,
    last_login_at       TEXT
);
CREATE INDEX users_email ON users (email);

-- העוגייה apr_session: במסד רק ה-hash של האסימון. office_id — המשרד שהמשתמש עובד בו עכשיו.
CREATE TABLE sessions (
    token_hash  TEXT PRIMARY KEY,
    user_id     INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    csrf        TEXT NOT NULL,
    office_id   INTEGER REFERENCES offices (id) ON DELETE SET NULL,
    created_at  TEXT NOT NULL,
    last_seen   INTEGER NOT NULL
);
CREATE INDEX sessions_user ON sessions (user_id);

CREATE TABLE offices (
    id          INTEGER PRIMARY KEY,
    name        TEXT NOT NULL,
    status      TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended')),
    created_by  INTEGER REFERENCES users (id) ON DELETE SET NULL,
    created_at  TEXT NOT NULL
);

-- חברות במשרד. אדם יכול להיות חבר בכמה משרדים; ההקשר נבחר במפורש (sessions.office_id).
CREATE TABLE memberships (
    id          INTEGER PRIMARY KEY,
    office_id   INTEGER NOT NULL REFERENCES offices (id) ON DELETE CASCADE,
    user_id     INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    role        TEXT NOT NULL CHECK (role IN ('office_admin', 'appraiser', 'associate', 'staff')),
    status      TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended')),
    invited_by  INTEGER REFERENCES users (id) ON DELETE SET NULL,
    created_at  TEXT NOT NULL,
    UNIQUE (office_id, user_id)
);
CREATE INDEX memberships_user ON memberships (user_id);

-- יומן פעולות (audit): מי עשה מה, מתי ובאיזה משרד. בלי תוכן אישי ובלי סודות.
CREATE TABLE audit_log (
    id          INTEGER PRIMARY KEY,
    at          TEXT NOT NULL,
    office_id   INTEGER,
    user_id     INTEGER,
    actor       TEXT NOT NULL DEFAULT '',
    action      TEXT NOT NULL,
    target      TEXT NOT NULL DEFAULT '',
    data_json   TEXT NOT NULL DEFAULT '{}',
    req         TEXT NOT NULL DEFAULT ''
);
CREATE INDEX audit_office ON audit_log (office_id, id);

-- שירותי רקע: כל שירות מדווח כשרץ; מסך המפתח מראה מי תקוע.
CREATE TABLE service_state (
    name        TEXT PRIMARY KEY,
    label       TEXT NOT NULL DEFAULT '',
    every_sec   INTEGER NOT NULL DEFAULT 0,
    last_run_at TEXT,
    last_ok_at  TEXT,
    last_error  TEXT NOT NULL DEFAULT ''
);
