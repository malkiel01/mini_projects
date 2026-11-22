-- פרוסה 17 — הדוח: פרקים, טיוטה אוטומטית, בקרה ואישור (docs/spec/17-report.md §3).
-- הדוח הוא תוכן ונתונים בתוך התיק. גרסה שאושרה אינה משתנה — לא בקוד ולא במסד: הטריגרים כאן הם הקו האחרון (17-C5).
-- עמודות שאינן ב-§3 (מוסברות ב-README, "17 · סטיות"): report_versions.deleted_*, report_versions.data_hash, report_versions.reopen_reason,
-- report_sections.build / ai_allowed / auto_hash / ai_wait / deleted_*, section_proposals.rev.

CREATE TABLE reports (
    id                  INTEGER PRIMARY KEY,
    pid                 TEXT NOT NULL UNIQUE,
    office_id           INTEGER NOT NULL REFERENCES offices (id),
    case_id             INTEGER NOT NULL UNIQUE REFERENCES cases (id),       -- דוח אחד לתיק
    current_version_id  INTEGER REFERENCES report_versions (id),
    created_at          TEXT NOT NULL
);
CREATE TRIGGER reports_no_delete BEFORE DELETE ON reports BEGIN SELECT RAISE(ABORT, 'reports: no delete'); END;

CREATE TABLE report_versions (
    id                    INTEGER PRIMARY KEY,
    pid                   TEXT NOT NULL UNIQUE,
    report_id             INTEGER NOT NULL REFERENCES reports (id),
    office_id             INTEGER NOT NULL REFERENCES offices (id),
    case_id               INTEGER NOT NULL REFERENCES cases (id),
    number                INTEGER NOT NULL CHECK (number >= 1),
    state                 TEXT NOT NULL DEFAULT 'draft' CHECK (state IN ('draft', 'in_review', 'approved', 'delivered', 'superseded')),
    origin                TEXT NOT NULL CHECK (origin IN ('auto', 'manual', 'from_version', 'from_upload')),
    based_on_id           INTEGER REFERENCES report_versions (id),
    reopen_reason         TEXT,                                               -- הנימוק לפתיחת גרסה חדשה אחרי אישור
    template              TEXT NOT NULL,                                      -- JSON: צילום מקטע "דוח" של הפרופיל בזמן היצירה
    created_by            INTEGER REFERENCES users (id),                      -- NULL = המערכת (הטריגר)
    created_at            TEXT NOT NULL,
    submitted_by          INTEGER REFERENCES users (id),
    submitted_at          TEXT,
    approved_by           INTEGER REFERENCES users (id),
    approved_at           TEXT,
    approval_notes        TEXT,                                               -- JSON: הנימוקים לאזהרות שאושרו
    snapshot              TEXT,                                               -- JSON: צילום הנתונים בזמן האישור (§8)
    content_hash          TEXT,
    data_hash             TEXT,                                               -- hash של הנתונים שהגרסה נשענת עליהם, בזמן האישור — להשוואה אחר כך
    stale_after_approval  INTEGER NOT NULL DEFAULT 0 CHECK (stale_after_approval IN (0, 1)),
    rev                   INTEGER NOT NULL DEFAULT 1,
    deleted_at            TEXT,
    deleted_by            INTEGER REFERENCES users (id),
    delete_reason         TEXT,
    UNIQUE (report_id, number),
    CHECK ((state IN ('approved', 'delivered', 'superseded')) = (approved_at IS NOT NULL)),
    CHECK (approved_at IS NULL OR (approved_by IS NOT NULL AND snapshot IS NOT NULL AND content_hash IS NOT NULL)),
    CHECK (deleted_at IS NULL OR (approved_at IS NULL AND delete_reason IS NOT NULL))     -- רק טיוטה שלא אושרה נמחקת, ובנימוק
);
CREATE INDEX report_versions_case ON report_versions (case_id);
CREATE TRIGGER report_versions_no_delete BEFORE DELETE ON report_versions BEGIN SELECT RAISE(ABORT, 'report_versions: no delete'); END;

CREATE TRIGGER report_versions_rev BEFORE UPDATE ON report_versions
WHEN NEW.rev <> OLD.rev + 1 OR NEW.pid <> OLD.pid OR NEW.report_id <> OLD.report_id OR NEW.case_id <> OLD.case_id OR NEW.number <> OLD.number
    OR NEW.created_at <> OLD.created_at OR NEW.origin <> OLD.origin OR OLD.deleted_at IS NOT NULL
BEGIN SELECT RAISE(ABORT, 'report_versions: rev / identity / frozen'); END;

-- אחרי האישור: מותר רק approved → delivered / superseded, delivered → superseded, והדלקת stale_after_approval. כל השאר — קפוא.
CREATE TRIGGER report_versions_approved BEFORE UPDATE ON report_versions
WHEN OLD.approved_at IS NOT NULL AND (
       NEW.snapshot IS NOT OLD.snapshot OR NEW.content_hash IS NOT OLD.content_hash OR NEW.data_hash IS NOT OLD.data_hash OR NEW.template <> OLD.template
    OR NEW.approved_by IS NOT OLD.approved_by OR NEW.approved_at IS NOT OLD.approved_at OR NEW.approval_notes IS NOT OLD.approval_notes
    OR NEW.submitted_by IS NOT OLD.submitted_by OR NEW.submitted_at IS NOT OLD.submitted_at OR NEW.based_on_id IS NOT OLD.based_on_id
    OR NEW.deleted_at IS NOT NULL OR NEW.stale_after_approval < OLD.stale_after_approval
    OR NOT (NEW.state = OLD.state OR (OLD.state = 'approved' AND NEW.state IN ('delivered', 'superseded')) OR (OLD.state = 'delivered' AND NEW.state = 'superseded')))
BEGIN SELECT RAISE(ABORT, 'report_versions: approved version is frozen'); END;

-- אין דרך להגיע ל"מאושר" שלא מ"בבדיקה" (17-C8), ואין חזרה ממאושר
CREATE TRIGGER report_versions_flow BEFORE UPDATE OF state ON report_versions
WHEN NEW.state <> OLD.state AND NOT (
       (OLD.state = 'draft' AND NEW.state = 'in_review') OR (OLD.state = 'in_review' AND NEW.state IN ('draft', 'approved'))
    OR (OLD.state = 'approved' AND NEW.state IN ('delivered', 'superseded')) OR (OLD.state = 'delivered' AND NEW.state = 'superseded'))
BEGIN SELECT RAISE(ABORT, 'report_versions: state flow'); END;

CREATE TRIGGER report_versions_insert BEFORE INSERT ON report_versions
WHEN NEW.state <> 'draft' OR NEW.approved_at IS NOT NULL OR NEW.snapshot IS NOT NULL
BEGIN SELECT RAISE(ABORT, 'report_versions: a version is born a draft'); END;

CREATE TABLE report_sections (
    id              INTEGER PRIMARY KEY,
    pid             TEXT NOT NULL UNIQUE,
    version_id      INTEGER NOT NULL REFERENCES report_versions (id),
    key             TEXT NOT NULL,
    title           TEXT NOT NULL,
    sort            INTEGER NOT NULL,
    mandatory       INTEGER NOT NULL DEFAULT 0 CHECK (mandatory IN (0, 1)),
    build           TEXT NOT NULL DEFAULT 'data' CHECK (build IN ('data', 'manual')),
    ai_allowed      INTEGER NOT NULL DEFAULT 0 CHECK (ai_allowed IN (0, 1)),
    content         TEXT NOT NULL DEFAULT '[]',                               -- JSON: בלוקים (§5)
    origin          TEXT NOT NULL DEFAULT 'auto' CHECK (origin IN ('auto', 'ai', 'manual')),
    ai_run_id       INTEGER REFERENCES ai_runs (id),
    ai_reviewed     INTEGER NOT NULL DEFAULT 0 CHECK (ai_reviewed IN (0, 1)),
    ai_reviewed_by  INTEGER REFERENCES users (id),
    ai_reviewed_at  TEXT,
    ai_wait         TEXT,                                                     -- "ניסוח בבינה ממתין": קוד הסיבה (§6.3); NULL = לא ממתין
    user_edited     INTEGER NOT NULL DEFAULT 0 CHECK (user_edited IN (0, 1)),
    state           TEXT NOT NULL DEFAULT 'empty' CHECK (state IN ('empty', 'drafted', 'edited', 'reviewed')),
    sources         TEXT NOT NULL DEFAULT '[]',                               -- JSON: [{type, pid, label}]
    auto_hash       TEXT,                                                     -- hash של התוכן שנבנה מהנתונים בפעם האחרונה — כך מזהים "הגיע מידע חדש"
    changed_note    TEXT,                                                     -- "עודכן: <מה השתנה>" לפרק שלא נערך
    rev             INTEGER NOT NULL DEFAULT 1,
    updated_by      INTEGER REFERENCES users (id),
    updated_at      TEXT NOT NULL,
    deleted_at      TEXT,
    deleted_by      INTEGER REFERENCES users (id),
    UNIQUE (version_id, key),
    CHECK (ai_reviewed = 0 OR ai_reviewed_by IS NOT NULL),
    CHECK (origin <> 'ai' OR ai_allowed = 1),
    CHECK (ai_allowed = 0 OR key IN ('property', 'area_desc', 'visit', 'planning'))        -- רשימה סגורה (APR_REPORT_AI_KEYS): רק אלה מנוסחים בבינה; ארבעת הפרקים של ש-23 ו-purpose (שם המזמין) — לעולם לא
);
CREATE TRIGGER report_sections_no_delete BEFORE DELETE ON report_sections BEGIN SELECT RAISE(ABORT, 'report_sections: no delete'); END;
CREATE TRIGGER report_sections_rev BEFORE UPDATE ON report_sections
WHEN NEW.rev <> OLD.rev + 1 OR NEW.pid <> OLD.pid OR NEW.version_id <> OLD.version_id OR NEW.key <> OLD.key OR OLD.deleted_at IS NOT NULL
BEGIN SELECT RAISE(ABORT, 'report_sections: rev / identity / frozen'); END;
-- פרק משתנה רק כשהגרסה שלו טיוטה: לא בבדיקה, ולא אחרי האישור
CREATE TRIGGER report_sections_frozen_u BEFORE UPDATE ON report_sections
WHEN (SELECT state FROM report_versions WHERE id = OLD.version_id) <> 'draft'
BEGIN SELECT RAISE(ABORT, 'report_sections: version is not a draft'); END;
CREATE TRIGGER report_sections_frozen_i BEFORE INSERT ON report_sections
WHEN (SELECT state FROM report_versions WHERE id = NEW.version_id) <> 'draft'
BEGIN SELECT RAISE(ABORT, 'report_sections: version is not a draft'); END;

CREATE TABLE section_proposals (
    id          INTEGER PRIMARY KEY,
    pid         TEXT NOT NULL UNIQUE,
    section_id  INTEGER NOT NULL REFERENCES report_sections (id),
    content     TEXT NOT NULL,
    content_hash TEXT NOT NULL,
    origin      TEXT NOT NULL CHECK (origin IN ('auto', 'ai')),
    reason      TEXT NOT NULL,
    state       TEXT NOT NULL DEFAULT 'pending' CHECK (state IN ('pending', 'accepted', 'rejected', 'merged')),
    created_at  TEXT NOT NULL,
    decided_by  INTEGER REFERENCES users (id),
    decided_at  TEXT,
    CHECK ((state = 'pending') = (decided_at IS NULL))
);
CREATE INDEX section_proposals_section ON section_proposals (section_id, state);
CREATE TRIGGER section_proposals_no_delete BEFORE DELETE ON section_proposals BEGIN SELECT RAISE(ABORT, 'section_proposals: no delete'); END;
CREATE TRIGGER section_proposals_decided BEFORE UPDATE ON section_proposals
WHEN OLD.state <> 'pending' OR NEW.content <> OLD.content OR NEW.section_id <> OLD.section_id
BEGIN SELECT RAISE(ABORT, 'section_proposals: decided / frozen'); END;

CREATE TABLE report_comments (
    id           INTEGER PRIMARY KEY,
    pid          TEXT NOT NULL UNIQUE,
    version_id   INTEGER NOT NULL REFERENCES report_versions (id),
    section_id   INTEGER REFERENCES report_sections (id),
    text         TEXT NOT NULL,
    created_by   INTEGER NOT NULL REFERENCES users (id),
    created_at   TEXT NOT NULL,
    resolved_at  TEXT,
    resolved_by  INTEGER REFERENCES users (id)
);
CREATE INDEX report_comments_version ON report_comments (version_id);
CREATE TRIGGER report_comments_no_delete BEFORE DELETE ON report_comments BEGIN SELECT RAISE(ABORT, 'report_comments: no delete'); END;
CREATE TRIGGER report_comments_frozen BEFORE UPDATE ON report_comments
WHEN NEW.text <> OLD.text OR NEW.created_by <> OLD.created_by OR NEW.version_id <> OLD.version_id OR OLD.resolved_at IS NOT NULL
BEGIN SELECT RAISE(ABORT, 'report_comments: frozen'); END;

CREATE TABLE report_findings_ack (
    version_id   INTEGER NOT NULL REFERENCES report_versions (id),
    finding_key  TEXT NOT NULL,
    reason       TEXT NOT NULL CHECK (length(trim(reason)) > 0),
    created_by   INTEGER NOT NULL REFERENCES users (id),
    created_at   TEXT NOT NULL,
    PRIMARY KEY (version_id, finding_key)
);
CREATE TRIGGER report_findings_ack_no_update BEFORE UPDATE ON report_findings_ack BEGIN SELECT RAISE(ABORT, 'report_findings_ack: append only'); END;
CREATE TRIGGER report_findings_ack_no_delete BEFORE DELETE ON report_findings_ack BEGIN SELECT RAISE(ABORT, 'report_findings_ack: append only'); END;
