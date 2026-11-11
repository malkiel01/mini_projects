-- פרוסה 07 (docs/spec/07-requests-portal.md §3): בקשות לגורם חיצוני, קישורי פורטל, קוד חד-פעמי, הודעות יוצאות ותשובות הנמען.
-- אסימון הפורטל, עוגיית המכשיר והקוד החד-פעמי נשמרים כ-hash בלבד.

CREATE TABLE requests (
    id          INTEGER PRIMARY KEY,
    pid         TEXT NOT NULL UNIQUE,
    office_id   INTEGER NOT NULL REFERENCES offices (id),
    case_id     INTEGER NOT NULL REFERENCES cases (id),
    party_id    INTEGER NOT NULL REFERENCES case_parties (id),
    perms       TEXT NOT NULL,                        -- JSON: upload, reply, view_own, download_own
    subject     TEXT NOT NULL,
    body        TEXT NOT NULL,                        -- הנוסח כפי שנשלח
    days        INTEGER NOT NULL DEFAULT 30,
    expires_at  TEXT NOT NULL,
    status      TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('draft', 'active', 'revoked', 'expired', 'completed')),
    created_by  INTEGER REFERENCES users (id),
    created_at  TEXT NOT NULL,
    revoked_at  TEXT,
    revoked_by  INTEGER REFERENCES users (id)
);
CREATE INDEX requests_case ON requests (case_id);
CREATE INDEX requests_party ON requests (party_id);

CREATE TABLE request_items (
    request_id      INTEGER NOT NULL REFERENCES requests (id),
    requirement_id  INTEGER NOT NULL REFERENCES requirements (id),
    PRIMARY KEY (request_id, requirement_id)
);
CREATE INDEX request_items_req ON request_items (requirement_id);

-- אסימון הגישה. לבקשה קישור חי אחד: "שליחה מחדש" מבטלת את הקודם.
CREATE TABLE portal_links (
    id            INTEGER PRIMARY KEY,
    request_id    INTEGER NOT NULL REFERENCES requests (id),
    token_hash    TEXT NOT NULL UNIQUE,               -- sha256 של האסימון; האסימון עצמו אינו נשמר
    created_at    TEXT NOT NULL,
    expires_at    TEXT NOT NULL,
    revoked_at    TEXT,
    last_used_at  TEXT,
    use_count     INTEGER NOT NULL DEFAULT 0,
    otp_fails     INTEGER NOT NULL DEFAULT 0,         -- קודים שגויים ברצף
    locked_until  TEXT                                -- אחרי 5 קודים שגויים — רבע שעה
);
CREATE INDEX portal_links_request ON portal_links (request_id);

-- מכשיר שעבר קוד חד-פעמי (העוגייה apr_portal)
CREATE TABLE portal_devices (
    id           INTEGER PRIMARY KEY,
    link_id      INTEGER NOT NULL REFERENCES portal_links (id),
    device_hash  TEXT NOT NULL,
    verified_at  TEXT NOT NULL,
    expires_at   TEXT NOT NULL,
    UNIQUE (link_id, device_hash)
);

CREATE TABLE portal_otps (
    id          INTEGER PRIMARY KEY,
    link_id     INTEGER NOT NULL REFERENCES portal_links (id),
    code_hash   TEXT NOT NULL,                        -- HMAC במפתח השרת; הקוד עצמו אינו נשמר
    attempts    INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT NOT NULL,
    expires_at  TEXT NOT NULL,
    used_at     TEXT
);
CREATE INDEX portal_otps_link ON portal_otps (link_id);

-- כל הודעה יוצאת (בפרוסה 08 — גם נכנסת). היעד עצמו נקרא מאיש הקשר; כאן רק hash שלו.
CREATE TABLE messages (
    id               INTEGER PRIMARY KEY,
    pid              TEXT NOT NULL UNIQUE,
    office_id        INTEGER NOT NULL REFERENCES offices (id),
    case_id          INTEGER NOT NULL REFERENCES cases (id),
    request_id       INTEGER REFERENCES requests (id),
    party_id         INTEGER REFERENCES case_parties (id),
    direction        TEXT NOT NULL DEFAULT 'out' CHECK (direction IN ('out')),
    channel          TEXT NOT NULL CHECK (channel IN ('email', 'link_copy')),
    to_hash          TEXT,
    status           TEXT NOT NULL DEFAULT 'planned' CHECK (status IN ('planned', 'sent', 'delivered', 'failed', 'unknown')),
    provider_ref     TEXT,
    error            TEXT,
    idempotency_key  TEXT NOT NULL UNIQUE,
    created_by       INTEGER REFERENCES users (id),
    created_at       TEXT NOT NULL,
    sent_at          TEXT
);
CREATE INDEX messages_request ON messages (request_id);
CREATE INDEX messages_case ON messages (case_id, status);

-- מה הנמען ענה בפורטל
CREATE TABLE requirement_responses (
    id              INTEGER PRIMARY KEY,
    pid             TEXT NOT NULL UNIQUE,
    requirement_id  INTEGER NOT NULL REFERENCES requirements (id),
    party_id        INTEGER NOT NULL REFERENCES case_parties (id),
    request_id      INTEGER NOT NULL REFERENCES requests (id),
    kind            TEXT NOT NULL CHECK (kind IN ('no_document', 'comment')),
    text            TEXT,
    created_at      TEXT NOT NULL
);
CREATE INDEX requirement_responses_req ON requirement_responses (requirement_id);
CREATE INDEX requirement_responses_request ON requirement_responses (request_id);

-- קובץ שהועלה בפורטל שייך לבקשה שדרכה הועלה: הנמען רואה רק את אלה
ALTER TABLE files ADD COLUMN request_id INTEGER REFERENCES requests (id);
CREATE INDEX files_request ON files (request_id);

-- הגדרת משרד: "תמיד לדרוש קוד" בפורטל (כבויה כברירת מחדל)
ALTER TABLE offices ADD COLUMN portal_always_otp INTEGER NOT NULL DEFAULT 0;
