-- פרוסה 08 (docs/spec/08-reminders-channels.md §3): חיבורי ערוצים, תוכניות תזכורת, הודעות נכנסות ותור שיוך.
-- סוד של חיבור נשמר רק ב-config_enc (מוצפן במפתח של data/secret.key); secret_tail — 4 התווים האחרונים, לתצוגה.

CREATE TABLE channel_connections (
    id            INTEGER PRIMARY KEY,
    pid           TEXT NOT NULL UNIQUE,
    scope         TEXT NOT NULL CHECK (scope IN ('system', 'office')),
    office_id     INTEGER REFERENCES offices (id),
    channel       TEXT NOT NULL CHECK (channel IN ('email', 'sms', 'whatsapp', 'storage')),
    provider      TEXT NOT NULL,
    label         TEXT NOT NULL,
    status        TEXT NOT NULL DEFAULT 'needs_auth' CHECK (status IN ('active', 'needs_auth', 'disabled', 'failed')),
    config_enc    BLOB,
    secret_tail   TEXT,
    all_offices   INTEGER NOT NULL DEFAULT 0,          -- חיבור מערכת שמותר לכל המשרדים, בלי שורות ב-channel_grants
    capabilities  TEXT NOT NULL DEFAULT '{}',
    fail_count    INTEGER NOT NULL DEFAULT 0,
    last_ok_at    TEXT,
    last_error    TEXT,                                -- קוד טכני בלבד
    created_by    INTEGER REFERENCES users (id),
    created_at    TEXT NOT NULL,
    CHECK ((scope = 'office') = (office_id IS NOT NULL))
);
CREATE INDEX channel_connections_office ON channel_connections (office_id);

CREATE TABLE channel_grants (
    connection_id  INTEGER NOT NULL REFERENCES channel_connections (id),
    office_id      INTEGER NOT NULL REFERENCES offices (id),
    PRIMARY KEY (connection_id, office_id)
);

-- מייל דרך השרת: חיבור מערכת בנוי, מותר לכל המשרדים (§6.2)
INSERT INTO channel_connections (pid, scope, channel, provider, label, status, all_offices, capabilities, created_at)
VALUES (lower(hex(randomblob(8))), 'system', 'email', 'server_mail', 'מייל דרך השרת', 'active', 1,
    '{"send":true,"attach_files":false,"delivery_reports":false,"inbound":false,"free_text":true}', strftime('%Y-%m-%dT%H:%M:%SZ', 'now'));

-- messages: כיוון נכנס, ערוצים חדשים, סוג, מספר תזכורת, דילוג. SQLite אינו משנה CHECK — הטבלה נבנית מחדש.
CREATE TABLE messages_new (
    id               INTEGER PRIMARY KEY,
    pid              TEXT NOT NULL UNIQUE,
    office_id        INTEGER NOT NULL REFERENCES offices (id),
    case_id          INTEGER NOT NULL REFERENCES cases (id),
    request_id       INTEGER REFERENCES requests (id),
    party_id         INTEGER REFERENCES case_parties (id),
    direction        TEXT NOT NULL DEFAULT 'out' CHECK (direction IN ('out', 'in')),
    channel          TEXT NOT NULL CHECK (channel IN ('email', 'sms', 'whatsapp', 'link_copy')),
    connection_id    INTEGER REFERENCES channel_connections (id),
    kind             TEXT NOT NULL DEFAULT 'request' CHECK (kind IN ('request', 'reminder', 'resend', 'inbound')),
    reminder_no      INTEGER,
    to_hash          TEXT,
    status           TEXT NOT NULL DEFAULT 'planned' CHECK (status IN ('planned', 'sent', 'delivered', 'answered', 'failed', 'unknown', 'skipped', 'received')),
    skip_reason      TEXT,
    attempts         INTEGER NOT NULL DEFAULT 0,
    retry_at         TEXT,
    subject          TEXT,                             -- הנוסח כפי שנשלח (תזכורת) או התקבל (נכנסת); הקישור אינו נשמר
    body             TEXT,
    provider_ref     TEXT,
    error            TEXT,
    idempotency_key  TEXT NOT NULL UNIQUE,
    created_by       INTEGER REFERENCES users (id),
    created_at       TEXT NOT NULL,
    sent_at          TEXT
);
INSERT INTO messages_new (id, pid, office_id, case_id, request_id, party_id, direction, channel, to_hash, status, provider_ref, error, idempotency_key, created_by, created_at, sent_at)
    SELECT id, pid, office_id, case_id, request_id, party_id, direction, channel, to_hash, status, provider_ref, error, idempotency_key, created_by, created_at, sent_at FROM messages;
DROP TABLE messages;
ALTER TABLE messages_new RENAME TO messages;
CREATE INDEX messages_request ON messages (request_id);
CREATE INDEX messages_case ON messages (case_id, status);
UPDATE messages SET connection_id = (SELECT id FROM channel_connections WHERE provider = 'server_mail') WHERE channel = 'email';

-- מזהה השיוך של בקשה (מוטמע בהודעות היוצאות): כאן רק ה-hash שלו
ALTER TABLE requests ADD COLUMN ref_hash TEXT;
CREATE INDEX requests_ref ON requests (ref_hash);

CREATE TABLE reminder_plans (
    id              INTEGER PRIMARY KEY,
    request_id      INTEGER NOT NULL UNIQUE REFERENCES requests (id),
    connection_id   INTEGER REFERENCES channel_connections (id),
    rules           TEXT NOT NULL,                     -- צילום הכללים והנוסח בזמן השליחה
    state           TEXT NOT NULL DEFAULT 'active' CHECK (state IN ('active', 'stopped')),
    stopped_reason  TEXT,
    sent_count      INTEGER NOT NULL DEFAULT 0,
    next_at         TEXT,
    created_at      TEXT NOT NULL
);
CREATE INDEX reminder_plans_due ON reminder_plans (state, next_at);

CREATE TABLE inbound_items (
    id                INTEGER PRIMARY KEY,
    pid               TEXT NOT NULL UNIQUE,
    connection_id     INTEGER NOT NULL REFERENCES channel_connections (id),
    office_id         INTEGER REFERENCES offices (id), -- NULL: לא מזוהה — אצל מנהל המערכת, בלי תוכן
    channel           TEXT NOT NULL,
    from_hash         TEXT NOT NULL,
    from_display      TEXT NOT NULL,
    received_at       TEXT NOT NULL,
    subject           TEXT,
    text              TEXT,
    file_ids          TEXT NOT NULL DEFAULT '[]',      -- JSON: קבצים בהסגר (key, name, size, mime), קבצים שנדחו (rejected), ואחרי שיוך — pid
    candidates        TEXT NOT NULL DEFAULT '[]',      -- JSON: מזהי תיקים אפשריים, מהמשרד של החיבור בלבד
    why               TEXT,                            -- למה בתור: no_ref / sender_mismatch / request_inactive / shared / unknown_sender
    status            TEXT NOT NULL DEFAULT 'unassigned' CHECK (status IN ('unassigned', 'assigned', 'dismissed')),
    assigned_case_id  INTEGER REFERENCES cases (id),
    assigned_by       INTEGER REFERENCES users (id),
    assigned_at       TEXT,
    dismiss_reason    TEXT,
    replied_at        TEXT
);
CREATE INDEX inbound_items_office ON inbound_items (office_id, status);

-- כל הודעה נכנסת שהתקבלה, לפי מזהה ההודעה של הספק: אותה הודעה אינה נרשמת פעמיים (מקרה קצה 12, והגנה מפני שידור חוזר)
CREATE TABLE inbound_receipts (
    connection_id  INTEGER NOT NULL REFERENCES channel_connections (id),
    message_id     TEXT NOT NULL,
    received_at    TEXT NOT NULL,
    PRIMARY KEY (connection_id, message_id)
);

-- נמען שביקש להפסיק (מקרה קצה 6): אין שליחה אוטומטית ליעד הזה בערוץ הזה עד שהשמאי שולח בעצמו
CREATE TABLE channel_optouts (
    office_id   INTEGER NOT NULL REFERENCES offices (id),
    channel     TEXT NOT NULL,
    to_hash     TEXT NOT NULL,
    created_at  TEXT NOT NULL,
    PRIMARY KEY (office_id, channel, to_hash)
);

-- לוח החגים שהמנוע מתחשב בו (§5, בדיקה 8). eve = 1: ערב חג — מחוץ לחלון מ-13:00.
CREATE TABLE holidays (
    day   TEXT PRIMARY KEY,
    name  TEXT NOT NULL,
    eve   INTEGER NOT NULL DEFAULT 0
);
INSERT INTO holidays (day, name, eve) VALUES
    ('2027-04-21', 'ערב פסח', 1), ('2027-04-22', 'פסח', 0), ('2027-04-27', 'ערב שביעי של פסח', 1), ('2027-04-28', 'שביעי של פסח', 0),
    ('2027-05-11', 'ערב יום העצמאות', 1), ('2027-05-12', 'יום העצמאות', 0), ('2027-06-10', 'ערב שבועות', 1), ('2027-06-11', 'שבועות', 0),
    ('2027-10-01', 'ערב ראש השנה', 1), ('2027-10-02', 'ראש השנה', 0), ('2027-10-03', 'ראש השנה', 0), ('2027-10-10', 'ערב יום כיפור', 1),
    ('2027-10-11', 'יום כיפור', 0), ('2027-10-15', 'ערב סוכות', 1), ('2027-10-16', 'סוכות', 0), ('2027-10-22', 'ערב שמחת תורה', 1),
    ('2027-10-23', 'שמחת תורה', 0);

ALTER TABLE offices ADD COLUMN timezone TEXT NOT NULL DEFAULT 'Asia/Jerusalem';
-- "מטפל בתור": חבר משרד שמנהל המשרד הסמיך לראות את תור השיוך
ALTER TABLE memberships ADD COLUMN inbox_handler INTEGER NOT NULL DEFAULT 0;
