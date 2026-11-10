-- יישור הסכימה לאפיון (docs/spec/01-foundation.md §4; team/decisions.md, 2026-11-10).
-- מיגרציה 001 נכנסה ל-main ונפרסה לפני שהאפיון נכתב, ולכן אינה משתנה. כאן מוחקים את הטבלאות שלה ויוצרים את המבנה
-- המיושר. עובדת משני המצבים: מסד ריק (001 ואז 002 ברצף) ומסד שכבר ב-user_version = 1. אין בטבלאות האלה נתונים
-- אמיתיים — האפליקציה חסומה (restricted) ועוד לא נפתחה למשתמשים. טבלאות DevLoop (dev_*) אינן נוגעות.

DROP TABLE IF EXISTS sessions;
DROP TABLE IF EXISTS memberships;
DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS service_state;
DROP TABLE IF EXISTS offices;
DROP TABLE IF EXISTS users;

--
-- מוסכמות לכל הטבלאות, גם בפרוסות הבאות:
--   id   — מספר רץ פנימי. לא יוצא מהשרת.
--   pid  — מזהה ציבורי אקראי (16 תווים, אותיות קטנות וספרות). רק הוא מופיע בכתובות וב-API.
--   זמנים — UTC, "YYYY-MM-DDTHH:MM:SSZ" (ממוינים כמחרוזת).
--   מחיקה רכה (deleted_at) בישויות עסקיות; לא מוחקים שורות שיומן הפעולות מפנה אליהן.

CREATE TABLE users (
    id              INTEGER PRIMARY KEY,
    pid             TEXT NOT NULL UNIQUE,
    platform_sub    TEXT UNIQUE,                       -- ה-sub מהמעטפת (מחרוזת ספרות)
    email           TEXT,                              -- באותיות קטנות
    email_verified  INTEGER NOT NULL DEFAULT 0,        -- כפי שהגיע מהמעטפת
    name            TEXT NOT NULL DEFAULT '',
    status          TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended')),
    is_sysadmin     INTEGER NOT NULL DEFAULT 0,        -- מנהל המערכת
    is_developer    INTEGER NOT NULL DEFAULT 0,        -- רואה את מסך המפתח
    last_seen_at    TEXT,
    created_at      TEXT NOT NULL,
    updated_at      TEXT NOT NULL
);
CREATE UNIQUE INDEX users_email ON users (email) WHERE email IS NOT NULL;

CREATE TABLE offices (
    id          INTEGER PRIMARY KEY,
    pid         TEXT NOT NULL UNIQUE,
    name        TEXT NOT NULL,
    status      TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended')),
    settings    TEXT NOT NULL DEFAULT '{}',            -- JSON; ריק בפרוסה הזו
    created_by  INTEGER REFERENCES users (id),
    created_at  TEXT NOT NULL,
    updated_at  TEXT NOT NULL
);

-- העוגייה apr_session: במסד רק ה-hash של האסימון
CREATE TABLE sessions (
    id                INTEGER PRIMARY KEY,
    user_id           INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    token_hash        TEXT NOT NULL UNIQUE,
    csrf              TEXT NOT NULL,
    active_office_id  INTEGER REFERENCES offices (id) ON DELETE SET NULL,
    platform_sub      TEXT NOT NULL,                   -- למי ה-session שייך
    ua                TEXT NOT NULL DEFAULT '',
    created_at        TEXT NOT NULL,
    last_used_at      TEXT NOT NULL,
    expires_at        TEXT NOT NULL
);
CREATE INDEX sessions_user ON sessions (user_id);

-- role — התפקיד המקצועי; is_admin — ניהול המשרד, דגל נפרד. "שמאי אחראי" הוא תפקיד בתיק, לא כאן.
CREATE TABLE memberships (
    id          INTEGER PRIMARY KEY,
    office_id   INTEGER NOT NULL REFERENCES offices (id),
    user_id     INTEGER NOT NULL REFERENCES users (id),
    role        TEXT NOT NULL CHECK (role IN ('appraiser', 'trainee', 'staff')),
    is_admin    INTEGER NOT NULL DEFAULT 0,
    status      TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended')),
    title       TEXT,                                  -- תיאור חופשי ("מזכירה", "שמאי בכיר")
    license_no  TEXT,                                  -- מספר רישיון — מוצג "לא אומת"
    invited_by  INTEGER REFERENCES users (id),
    created_at  TEXT NOT NULL,
    updated_at  TEXT NOT NULL,
    deleted_at  TEXT,                                  -- הסרה = מחיקה רכה; החשבון נשאר
    UNIQUE (office_id, user_id)
);
CREATE INDEX memberships_user ON memberships (user_id);

CREATE TABLE invitations (
    id           INTEGER PRIMARY KEY,
    pid          TEXT NOT NULL UNIQUE,
    office_id    INTEGER NOT NULL REFERENCES offices (id),
    email        TEXT NOT NULL,                        -- באותיות קטנות
    role         TEXT NOT NULL CHECK (role IN ('appraiser', 'trainee', 'staff')),
    is_admin     INTEGER NOT NULL DEFAULT 0,
    token_hash   TEXT NOT NULL UNIQUE,                 -- רק ה-hash; האסימון עצמו רק בקישור
    status       TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'accepted', 'revoked', 'expired')),
    invited_by   INTEGER NOT NULL REFERENCES users (id),
    accepted_by  INTEGER REFERENCES users (id),
    expires_at   TEXT NOT NULL,
    created_at   TEXT NOT NULL,
    accepted_at  TEXT,
    revoked_at   TEXT
);
CREATE INDEX invitations_office ON invitations (office_id, status);
CREATE INDEX invitations_email ON invitations (email, status);

-- יומן הפעולות: רק מוסיפים. meta — מזהים ומצבים בלבד, לא תוכן.
CREATE TABLE audit_log (
    id             INTEGER PRIMARY KEY,
    at             TEXT NOT NULL,
    office_id      INTEGER,                            -- NULL = פעולה ברמת המערכת
    actor_user_id  INTEGER,                            -- NULL = המערכת / שירות רקע
    actor_kind     TEXT NOT NULL DEFAULT 'user' CHECK (actor_kind IN ('user', 'system', 'job', 'recipient')),
    action         TEXT NOT NULL,
    entity_type    TEXT NOT NULL DEFAULT '',
    entity_pid     TEXT NOT NULL DEFAULT '',
    meta           TEXT NOT NULL DEFAULT '{}',
    reason         TEXT,
    req            TEXT NOT NULL DEFAULT ''            -- מזהה הבקשה — מקשר ליומן הטכני
);
CREATE INDEX audit_office ON audit_log (office_id, id);
CREATE INDEX audit_action ON audit_log (action, id);
CREATE TRIGGER audit_no_update BEFORE UPDATE ON audit_log BEGIN SELECT RAISE(ABORT, 'audit_log is append-only'); END;
CREATE TRIGGER audit_no_delete BEFORE DELETE ON audit_log BEGIN SELECT RAISE(ABORT, 'audit_log is append-only'); END;

CREATE TABLE jobs (
    id            INTEGER PRIMARY KEY,
    pid           TEXT NOT NULL UNIQUE,
    office_id     INTEGER REFERENCES offices (id),
    kind          TEXT NOT NULL,
    payload       TEXT NOT NULL DEFAULT '{}',
    dedupe_key    TEXT UNIQUE,                         -- אותה משימה לא נכנסת פעמיים
    status        TEXT NOT NULL DEFAULT 'pending'
                  CHECK (status IN ('pending', 'running', 'done', 'partial', 'failed', 'needs_action', 'cancelled')),
    attempts      INTEGER NOT NULL DEFAULT 0,
    max_attempts  INTEGER NOT NULL DEFAULT 3,
    run_after     TEXT NOT NULL,
    started_at    TEXT,
    finished_at   TEXT,
    last_error    TEXT,                                -- הודעה טכנית, בלי תוכן
    created_at    TEXT NOT NULL
);
CREATE INDEX jobs_due ON jobs (status, run_after);

CREATE TABLE meta (
    key    TEXT PRIMARY KEY,
    value  TEXT NOT NULL
);
