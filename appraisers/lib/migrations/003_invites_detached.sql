-- פרוסה 02 (docs/spec/02-invites-detached.md §3): הזמנה שפותחת חשבון במעטפת, מצב מנותק, ואישור חוזר.

-- מפתח החתימה של המערכת מול המעטפת (Ed25519). הפרטי — מוצפן במפתח הסודות (data/secret.key), לעולם לא בגיט.
CREATE TABLE platform_keys (
    id           INTEGER PRIMARY KEY,
    kid          TEXT NOT NULL UNIQUE,
    alg          TEXT NOT NULL DEFAULT 'Ed25519',
    public_key   TEXT NOT NULL,                        -- base64
    private_key  BLOB NOT NULL,                        -- nonce + secretbox
    status       TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'retired')),
    created_at   TEXT NOT NULL
);

ALTER TABLE invitations ADD COLUMN name TEXT NOT NULL DEFAULT '';        -- שם המוזמן, לפתיחת החשבון במעטפת
ALTER TABLE invitations ADD COLUMN op TEXT;                              -- מזהה פעולה: שליחה כפולה = הזמנה אחת
ALTER TABLE invitations ADD COLUMN invitee_sub TEXT;                     -- ה-sub שהמעטפת החזירה
ALTER TABLE invitations ADD COLUMN provision_status TEXT
    CHECK (provision_status IS NULL OR provision_status IN ('invited', 'granted', 'blocked', 'failed', 'skipped'));
ALTER TABLE invitations ADD COLUMN email_sent_at TEXT;
ALTER TABLE invitations ADD COLUMN email_status TEXT CHECK (email_status IS NULL OR email_status IN ('sent', 'failed'));
CREATE UNIQUE INDEX invitations_op ON invitations (office_id, op) WHERE op IS NOT NULL;
CREATE INDEX invitations_sub ON invitations (invitee_sub, status) WHERE invitee_sub IS NOT NULL;

-- סיסמה משלה — רק למצב מנותק
ALTER TABLE users ADD COLUMN password_hash TEXT;
ALTER TABLE users ADD COLUMN must_change_pw INTEGER NOT NULL DEFAULT 0;

-- אישור חוזר במצב מנותק: עד מתי ה-session מאושר לפעולות רגישות
ALTER TABLE sessions ADD COLUMN confirmed_until TEXT;

CREATE TABLE confirm_codes (
    session_id  INTEGER PRIMARY KEY REFERENCES sessions (id) ON DELETE CASCADE,
    code_hash   TEXT NOT NULL,
    attempts    INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT NOT NULL,
    expires_at  TEXT NOT NULL
);

-- key: "e:<hash של המייל>" או "i:<hash של ה-IP>" — בלי כתובת גלויה
CREATE TABLE login_attempts (
    key           TEXT PRIMARY KEY,
    count         INTEGER NOT NULL DEFAULT 0,
    updated_at    TEXT NOT NULL,
    locked_until  TEXT
);

CREATE TABLE sso_states (
    state_hash  TEXT PRIMARY KEY,
    created_at  TEXT NOT NULL,
    expires_at  TEXT NOT NULL,
    used_at     TEXT
);

CREATE TABLE sso_jti (
    jti  TEXT PRIMARY KEY,
    exp  INTEGER NOT NULL
);

CREATE TABLE password_resets (
    id          INTEGER PRIMARY KEY,
    user_id     INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    token_hash  TEXT NOT NULL UNIQUE,
    created_at  TEXT NOT NULL,
    expires_at  TEXT NOT NULL,
    used_at     TEXT
);

-- כל מייל שהמערכת שולחת. to_hash — hash של הכתובת, לא הכתובת: יומן המפתח לא חושף נמענים.
CREATE TABLE mail_log (
    id               INTEGER PRIMARY KEY,
    at               TEXT NOT NULL,
    kind             TEXT NOT NULL,                    -- invite | reset | otp
    to_hash          TEXT NOT NULL,
    status           TEXT NOT NULL CHECK (status IN ('sent', 'failed')),
    error            TEXT,                             -- טכני, בלי כתובת ובלי תוכן
    idempotency_key  TEXT NOT NULL UNIQUE,
    office_id        INTEGER REFERENCES offices (id)
);
CREATE INDEX mail_log_at ON mail_log (at);
