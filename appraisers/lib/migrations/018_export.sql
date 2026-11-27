-- פרוסה 18 — יצוא ל-Word ול-PDF, ומסירה (docs/spec/18-export-delivery.md §3).
-- הקובץ שהופק נשאר משויך לגרסה שממנה הופק, ואינו משתנה ואינו נמחק; המסירה מתועדת, והקישור שלה מוסר רק את הקבצים שנבחרו בה.
-- סטיות מ-§3 (מוסברות ב-README, "18 · סטיות"): report_exports.kind / created_at / started_at / idempotency_key / office_id / case_id;
-- delivery_files במקום deliveries.export_ids (כך המסד אוכף שהקובץ שייך לגרסה); deliveries.to_hash / otp_required / mail_status במקום
-- message_id; האסימון, הנעילה והמונים של הקישור — על deliveries עצמה (קישור אחד למסירה); delivery_otps ו-delivery_devices כמו בפורטל (07).

CREATE TABLE report_exports (
    id               INTEGER PRIMARY KEY,
    pid              TEXT NOT NULL UNIQUE,
    office_id        INTEGER NOT NULL REFERENCES offices (id),
    case_id          INTEGER NOT NULL REFERENCES cases (id),
    version_id       INTEGER NOT NULL REFERENCES report_versions (id),
    kind             TEXT NOT NULL DEFAULT 'generated' CHECK (kind IN ('generated', 'upload')),   -- upload: הקובץ המתוקן שהועלה, המקור של גרסת from_upload (§5)
    format           TEXT NOT NULL CHECK (format IN ('docx', 'pdf')),
    file_id          INTEGER UNIQUE REFERENCES files (id),                                         -- NULL עד שההפקה הסתיימה
    state_at_export  TEXT NOT NULL CHECK (state_at_export IN ('draft', 'in_review', 'approved', 'delivered', 'superseded')),
    content_hash     TEXT,                                                                         -- ה-hash של תוכן הגרסה שממנו נכתב הקובץ
    engine           TEXT,                                                                         -- שם וגרסת המנוע שהפיק
    generated_by     INTEGER REFERENCES users (id),
    created_at       TEXT NOT NULL,
    started_at       TEXT,
    generated_at     TEXT,
    status           TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'done', 'failed')),
    error            TEXT,                                                                         -- קוד טכני בלבד — בלי תוכן של התיק
    idempotency_key  TEXT UNIQUE,
    CHECK ((status = 'done') = (file_id IS NOT NULL)),
    CHECK ((status = 'done') = (generated_at IS NOT NULL)),
    CHECK (status <> 'failed' OR error IS NOT NULL),
    CHECK (kind <> 'upload' OR (status = 'done' AND state_at_export = 'draft'))
);
CREATE INDEX report_exports_version ON report_exports (version_id);
CREATE TRIGGER report_exports_no_delete BEFORE DELETE ON report_exports BEGIN SELECT RAISE(ABORT, 'report_exports: no delete'); END;

-- הפקה נולדת "ממתינה" ובלי קובץ, בתיק ובמשרד של הגרסה שלה
CREATE TRIGGER report_exports_born BEFORE INSERT ON report_exports
WHEN (NEW.kind = 'generated' AND (NEW.status <> 'queued' OR NEW.file_id IS NOT NULL))
    OR (SELECT case_id FROM report_versions WHERE id = NEW.version_id AND deleted_at IS NULL) IS NOT NEW.case_id
    OR (SELECT office_id FROM cases WHERE id = NEW.case_id) IS NOT NEW.office_id
    OR (NEW.file_id IS NOT NULL AND (SELECT case_id FROM files WHERE id = NEW.file_id AND deleted_at IS NULL) IS NOT NEW.case_id)
BEGIN SELECT RAISE(ABORT, 'report_exports: born queued, in the case of its version'); END;

-- הפקה שהסתיימה (מוכנה או נכשלה) אינה משתנה; הזהות אינה משתנה לעולם; הכיוון: queued → running → done | failed
CREATE TRIGGER report_exports_frozen BEFORE UPDATE ON report_exports
WHEN OLD.status IN ('done', 'failed')
    OR NEW.pid <> OLD.pid OR NEW.version_id <> OLD.version_id OR NEW.case_id <> OLD.case_id OR NEW.office_id <> OLD.office_id
    OR NEW.format <> OLD.format OR NEW.kind <> OLD.kind OR NEW.state_at_export <> OLD.state_at_export OR NEW.created_at <> OLD.created_at
    OR NEW.generated_by IS NOT OLD.generated_by OR NEW.idempotency_key IS NOT OLD.idempotency_key
    OR NOT ((OLD.status = 'queued' AND NEW.status IN ('queued', 'running', 'failed')) OR (OLD.status = 'running' AND NEW.status IN ('running', 'done', 'failed')))
    OR (NEW.file_id IS NOT NULL AND (SELECT case_id FROM files WHERE id = NEW.file_id AND deleted_at IS NULL) IS NOT NEW.case_id)
BEGIN SELECT RAISE(ABORT, 'report_exports: frozen / identity / flow'); END;

-- קובץ של הפקה (וגם קובץ מתוקן שהועלה) אינו נמחק — גם לא מחיקה רכה — ואינו מוחלף (18-C7, §6.1). לא רק קובץ שנמסר: ההפקה נשארת
-- משויכת לגרסה שלה (מקרי קצה 1, 10), ואין פעולה שמוחקת הפקה.
CREATE TRIGGER files_export_keep_u BEFORE UPDATE ON files
WHEN EXISTS (SELECT 1 FROM report_exports e WHERE e.file_id = OLD.id)
    AND (NEW.deleted_at IS NOT NULL OR NEW.storage_key <> OLD.storage_key OR NEW.sha256 <> OLD.sha256 OR NEW.size <> OLD.size OR NEW.mime <> OLD.mime
        OR NEW.case_id <> OLD.case_id OR NEW.office_id <> OLD.office_id OR NEW.pid <> OLD.pid)
BEGIN SELECT RAISE(ABORT, 'files: a report file is kept as it is'); END;
CREATE TRIGGER files_export_keep_d BEFORE DELETE ON files
WHEN EXISTS (SELECT 1 FROM report_exports e WHERE e.file_id = OLD.id)
BEGIN SELECT RAISE(ABORT, 'files: a report file is kept'); END;

CREATE TABLE deliveries (
    id               INTEGER PRIMARY KEY,
    pid              TEXT NOT NULL UNIQUE,
    office_id        INTEGER NOT NULL REFERENCES offices (id),
    case_id          INTEGER NOT NULL REFERENCES cases (id),
    version_id       INTEGER NOT NULL REFERENCES report_versions (id),
    party_id         INTEGER REFERENCES case_parties (id),
    recipient_text   TEXT,                                                    -- נמען שאינו גורם בתיק
    channel          TEXT NOT NULL CHECK (channel IN ('portal_link', 'email_link', 'manual')),
    otp_required     INTEGER NOT NULL DEFAULT 1 CHECK (otp_required IN (0, 1)),
    to_hash          TEXT,                                                    -- hash של המייל של הנמען בזמן המסירה: קוד נשלח רק לכתובת הזו
    mail_status      TEXT CHECK (mail_status IS NULL OR mail_status IN ('planned', 'sent', 'failed', 'unknown')),
    note             TEXT,
    delivered_by     INTEGER NOT NULL REFERENCES users (id),
    delivered_at     TEXT NOT NULL,
    token_hash       TEXT UNIQUE,                                             -- sha256 של האסימון; האסימון עצמו אינו נשמר. NULL למסירה ידנית
    link_expires_at  TEXT,
    revoked_at       TEXT,
    revoked_by       INTEGER REFERENCES users (id),
    last_used_at     TEXT,
    use_count        INTEGER NOT NULL DEFAULT 0,
    otp_fails        INTEGER NOT NULL DEFAULT 0,
    locked_until     TEXT,
    idempotency_key  TEXT NOT NULL UNIQUE,
    CHECK ((party_id IS NULL) <> (recipient_text IS NULL)),
    CHECK ((channel = 'manual') = (token_hash IS NULL)),
    CHECK ((token_hash IS NULL) = (link_expires_at IS NULL)),
    CHECK (channel <> 'email_link' OR (to_hash IS NOT NULL AND party_id IS NOT NULL)),
    -- קוד חד-פעמי למייל תמיד; בלי קוד — רק קישור להעתקה לנמען שאין לו מייל (§6, מקרה קצה 9)
    CHECK (channel = 'manual' OR otp_required = 1 OR (channel = 'portal_link' AND to_hash IS NULL)),
    CHECK (otp_required = 0 OR to_hash IS NOT NULL),
    CHECK ((revoked_at IS NULL) = (revoked_by IS NULL))
);
CREATE INDEX deliveries_version ON deliveries (version_id);
CREATE INDEX deliveries_case ON deliveries (case_id);
CREATE TRIGGER deliveries_no_delete BEFORE DELETE ON deliveries BEGIN SELECT RAISE(ABORT, 'deliveries: no delete'); END;

-- רק גרסה שאושרה נמסרת (18-C1): approved, או delivered — מסירה נוספת של אותה גרסה לנמען אחר
CREATE TRIGGER deliveries_born BEFORE INSERT ON deliveries
WHEN COALESCE((SELECT state FROM report_versions WHERE id = NEW.version_id AND case_id = NEW.case_id AND deleted_at IS NULL), '') NOT IN ('approved', 'delivered')
    OR (SELECT office_id FROM cases WHERE id = NEW.case_id) IS NOT NEW.office_id
    OR (NEW.party_id IS NOT NULL AND (SELECT case_id FROM case_parties WHERE id = NEW.party_id) IS NOT NEW.case_id)
    OR NEW.revoked_at IS NOT NULL
BEGIN SELECT RAISE(ABORT, 'deliveries: only an approved version is delivered'); END;

-- מסירה היא רשומה: מה נמסר, למי, בידי מי ומתי אינם משתנים. משתנים רק ביטול הקישור (פעם אחת), מצב המייל, והמונים של הקישור
CREATE TRIGGER deliveries_frozen BEFORE UPDATE ON deliveries
WHEN NEW.pid <> OLD.pid OR NEW.version_id <> OLD.version_id OR NEW.case_id <> OLD.case_id OR NEW.office_id <> OLD.office_id
    OR NEW.party_id IS NOT OLD.party_id OR NEW.recipient_text IS NOT OLD.recipient_text OR NEW.channel <> OLD.channel
    OR NEW.otp_required <> OLD.otp_required OR NEW.to_hash IS NOT OLD.to_hash OR NEW.note IS NOT OLD.note
    OR NEW.delivered_by <> OLD.delivered_by OR NEW.delivered_at <> OLD.delivered_at OR NEW.token_hash IS NOT OLD.token_hash
    OR NEW.link_expires_at IS NOT OLD.link_expires_at OR NEW.idempotency_key <> OLD.idempotency_key
    OR (OLD.revoked_at IS NOT NULL AND (NEW.revoked_at IS NOT OLD.revoked_at OR NEW.revoked_by IS NOT OLD.revoked_by))
BEGIN SELECT RAISE(ABORT, 'deliveries: a delivery is a record'); END;

-- בדיוק אילו קבצים נמסרו (export_ids שב-§3). רק קובץ מוכן של אותה גרסה, ורק הנוסח המחייב שלה: הפקה מה-snapshot (אחרי האישור,
-- עם ה-content_hash של הגרסה) — או, כשהשמאי קבע באישור שהקובץ שהועלה הוא הנוסח המחייב, הקובץ שהועלה. הפקה של טיוטה אינה נמסרת.
CREATE TABLE delivery_files (
    delivery_id  INTEGER NOT NULL REFERENCES deliveries (id),
    export_id    INTEGER NOT NULL REFERENCES report_exports (id),
    PRIMARY KEY (delivery_id, export_id)
);
CREATE TRIGGER delivery_files_born BEFORE INSERT ON delivery_files
WHEN NOT EXISTS (
    SELECT 1 FROM report_exports e JOIN deliveries d ON d.id = NEW.delivery_id JOIN report_versions v ON v.id = e.version_id
    WHERE e.id = NEW.export_id AND e.version_id = d.version_id AND e.status = 'done'
      AND ((e.kind = 'generated' AND e.state_at_export IN ('approved', 'delivered') AND e.content_hash = v.content_hash
                AND COALESCE(json_extract(v.snapshot, '$.binding'), 'sections') = 'sections')
        OR (e.kind = 'upload' AND json_extract(v.snapshot, '$.binding') = 'file')))
BEGIN SELECT RAISE(ABORT, 'delivery_files: only the binding file of the delivered version'); END;
CREATE TRIGGER delivery_files_no_update BEFORE UPDATE ON delivery_files BEGIN SELECT RAISE(ABORT, 'delivery_files: append only'); END;
CREATE TRIGGER delivery_files_no_delete BEFORE DELETE ON delivery_files BEGIN SELECT RAISE(ABORT, 'delivery_files: append only'); END;

CREATE TABLE delivery_otps (
    id           INTEGER PRIMARY KEY,
    delivery_id  INTEGER NOT NULL REFERENCES deliveries (id),
    code_hash    TEXT NOT NULL,                        -- HMAC במפתח השרת; הקוד עצמו אינו נשמר
    created_at   TEXT NOT NULL,
    expires_at   TEXT NOT NULL,
    used_at      TEXT
);
CREATE INDEX delivery_otps_delivery ON delivery_otps (delivery_id);

CREATE TABLE delivery_devices (
    id           INTEGER PRIMARY KEY,
    delivery_id  INTEGER NOT NULL REFERENCES deliveries (id),
    device_hash  TEXT NOT NULL,
    verified_at  TEXT NOT NULL,
    expires_at   TEXT NOT NULL,
    UNIQUE (delivery_id, device_hash)
);

-- מי פתח את הקישור, ומה הורד (18-C8). רק מוסיפים.
CREATE TABLE delivery_access (
    id           INTEGER PRIMARY KEY,
    delivery_id  INTEGER NOT NULL REFERENCES deliveries (id),
    at           TEXT NOT NULL,
    kind         TEXT NOT NULL CHECK (kind IN ('open', 'download')),
    export_id    INTEGER REFERENCES report_exports (id),
    device_hash  TEXT,
    CHECK ((kind = 'download') = (export_id IS NOT NULL))
);
CREATE INDEX delivery_access_delivery ON delivery_access (delivery_id);
CREATE TRIGGER delivery_access_no_update BEFORE UPDATE ON delivery_access BEGIN SELECT RAISE(ABORT, 'delivery_access: append only'); END;
CREATE TRIGGER delivery_access_no_delete BEFORE DELETE ON delivery_access BEGIN SELECT RAISE(ABORT, 'delivery_access: append only'); END;
