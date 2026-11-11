-- פרוסה 09 (docs/spec/09-sources.md §3): קטלוג מקורות, בדיקות בתיק, ורשימת תכנון והיתרים — המסלול הידני בלבד.
-- אינה נוגעת באף טבלה קיימת. מה שהאפיון דורש שלא ישתנה — נאכף כאן בטריגרים, לא רק בקוד:
--   · source_checks היא יומן: שורה אינה נמחקת ואינה נערכת. בדיקה חדשה, אישור, "התחלתי" — כולם שורה חדשה (09-A7).
--     מותר לעדכן בשורה קיימת רק: found → verified (עם מי ומתי), והוצאה שנרשמת פעם אחת.
--   · planning_items: kind אינו משתנה לעולם — בקשה אינה הופכת להיתר (09-A4). אין מחיקה, רק deleted_at.
--   · אין בשום טבלה כאן עמודה בוליאנית של "עבירה" / "חוקי" / "אין היתר" (09-A6), ואין עמודה לסיסמה או לקוד של אתר חיצוני (09-C5).

CREATE TABLE sources (
    id               INTEGER PRIMARY KEY,
    pid              TEXT NOT NULL UNIQUE,
    key              TEXT NOT NULL UNIQUE,
    name             TEXT NOT NULL,
    owner            TEXT NOT NULL,                          -- הגוף שמחזיק במקור
    url              TEXT,                                   -- הכתובת הרשמית; NULL בכרטיס כללי ("אתר הוועדה המקומית")
    info_kinds       TEXT NOT NULL DEFAULT '[]',             -- JSON: deals | planning | permits | rights | map | enforcement | stats
    region           TEXT NOT NULL DEFAULT 'national' CHECK (region IN ('national', 'municipality')),
    municipality     TEXT,                                   -- NULL ב-municipality = כרטיס כללי לכל רשות שאין לה כרטיס
    access_mode      TEXT NOT NULL DEFAULT 'manual' CHECK (access_mode IN ('connector', 'guided', 'manual', 'unavailable')),
    feasibility      TEXT NOT NULL DEFAULT 'manual_only' CHECK (feasibility IN ('approved', 'needs_inquiry', 'manual_only', 'unavailable')),
    auth_required    TEXT NOT NULL DEFAULT 'none' CHECK (auth_required IN ('none', 'personal_login', 'national_id', 'payment', 'agreement')),
    cost_note        TEXT,
    terms_url        TEXT,
    license          TEXT NOT NULL DEFAULT '{"display":"unknown","store":"unknown","offline":"unknown","in_report":"unknown","distribute":"unknown","ai":"unknown"}',
    attribution      TEXT,
    coverage_note    TEXT NOT NULL DEFAULT '',
    limitations      TEXT NOT NULL DEFAULT '',
    how_to           TEXT NOT NULL DEFAULT '',
    research_ref     TEXT,                                   -- מקור הכרטיס במחקר הרשויות
    last_verified_at TEXT,                                   -- מתי נבדק הכרטיס עצמו; NULL = מעולם
    verified_by      INTEGER REFERENCES users (id),
    last_incident    TEXT,
    status           TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'hidden')),
    sort             INTEGER NOT NULL DEFAULT 0,
    created_at       TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    updated_at       TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    CHECK (region = 'municipality' OR municipality IS NULL)
);
CREATE TRIGGER sources_no_delete BEFORE DELETE ON sources BEGIN SELECT RAISE(ABORT, 'source is never deleted, only hidden'); END;
CREATE TRIGGER sources_identity BEFORE UPDATE ON sources WHEN NEW.id IS NOT OLD.id OR NEW.pid IS NOT OLD.pid OR NEW.key IS NOT OLD.key
BEGIN SELECT RAISE(ABORT, 'source identity is fixed'); END;

CREATE TABLE source_checks (
    id                INTEGER PRIMARY KEY,
    pid               TEXT NOT NULL UNIQUE,
    office_id         INTEGER NOT NULL REFERENCES offices (id),
    case_id           INTEGER NOT NULL REFERENCES cases (id),
    source_id         INTEGER NOT NULL REFERENCES sources (id),
    query             TEXT NOT NULL DEFAULT '{"identifiers":[],"text":null}',   -- JSON: המזהים ששימשו, כפי שהיו ברגע הבדיקה
    performed_by_kind TEXT NOT NULL CHECK (performed_by_kind IN ('user', 'connector', 'system')),
    performed_by      INTEGER REFERENCES users (id),
    performed_at      TEXT NOT NULL,
    status            TEXT NOT NULL CHECK (status IN ('not_checked', 'planned', 'awaiting_identification', 'needs_approval', 'in_progress', 'found', 'partial',
                          'not_found', 'unavailable', 'needs_login', 'needs_clarification', 'failed', 'verified')),
    coverage_note     TEXT,                                  -- מה נבדק ומה לא
    result_summary    TEXT,
    link_url          TEXT,
    file_id           INTEGER REFERENCES files (id),
    limitation        TEXT,
    approved_by       INTEGER REFERENCES users (id),         -- שורת planned שנוצרה מאישור של שמאי (ask / הוצאה)
    approved_at       TEXT,
    verified_by       INTEGER REFERENCES users (id),
    verified_at       TEXT,
    cost_amount       INTEGER CHECK (cost_amount IS NULL OR cost_amount >= 0),   -- באגורות; 0 = ללא עלות; NULL = לא נרשם
    cost_note         TEXT,
    idempotency_key   TEXT UNIQUE,
    created_at        TEXT NOT NULL,
    updated_at        TEXT NOT NULL,
    -- "לא אותר" בלי לומר מה חיפשו אינו נרשם (09-A3); "הושלם חלקית" — אותו דבר
    CHECK (status NOT IN ('not_found', 'partial') OR (coverage_note IS NOT NULL AND length(trim(coverage_note)) > 0)),
    CHECK (status NOT IN ('found', 'needs_clarification') OR (result_summary IS NOT NULL AND length(trim(result_summary)) > 0)),
    CHECK ((status = 'verified') = (verified_at IS NOT NULL)),
    CHECK ((verified_by IS NULL) = (verified_at IS NULL)),
    CHECK ((approved_by IS NULL) = (approved_at IS NULL)),
    CHECK (performed_by_kind <> 'user' OR performed_by IS NOT NULL)
);
CREATE INDEX source_checks_case ON source_checks (case_id, source_id, id);
CREATE INDEX source_checks_file ON source_checks (file_id);

CREATE TRIGGER source_checks_no_delete BEFORE DELETE ON source_checks BEGIN SELECT RAISE(ABORT, 'source check is never deleted'); END;
-- נולדת מאומתת? לא: "נבדק ואומת" הוא תמיד פעולה נפרדת של אדם על בדיקה קיימת
CREATE TRIGGER source_checks_born BEFORE INSERT ON source_checks WHEN NEW.status = 'verified' OR NEW.verified_at IS NOT NULL
BEGIN SELECT RAISE(ABORT, 'a check is not born verified'); END;
CREATE TRIGGER source_checks_frozen BEFORE UPDATE ON source_checks
WHEN NEW.id IS NOT OLD.id OR NEW.pid IS NOT OLD.pid OR NEW.office_id IS NOT OLD.office_id OR NEW.case_id IS NOT OLD.case_id OR NEW.source_id IS NOT OLD.source_id
    OR NEW.query IS NOT OLD.query OR NEW.performed_by_kind IS NOT OLD.performed_by_kind OR NEW.performed_by IS NOT OLD.performed_by OR NEW.performed_at IS NOT OLD.performed_at
    OR NEW.coverage_note IS NOT OLD.coverage_note OR NEW.result_summary IS NOT OLD.result_summary OR NEW.link_url IS NOT OLD.link_url OR NEW.file_id IS NOT OLD.file_id
    OR NEW.limitation IS NOT OLD.limitation OR NEW.approved_by IS NOT OLD.approved_by OR NEW.approved_at IS NOT OLD.approved_at
    OR NEW.idempotency_key IS NOT OLD.idempotency_key OR NEW.created_at IS NOT OLD.created_at
BEGIN SELECT RAISE(ABORT, 'a recorded check is not edited - record a new one'); END;
CREATE TRIGGER source_checks_status BEFORE UPDATE ON source_checks
WHEN (NEW.status IS NOT OLD.status OR NEW.verified_by IS NOT OLD.verified_by OR NEW.verified_at IS NOT OLD.verified_at)
    AND NOT (OLD.status = 'found' AND NEW.status = 'verified' AND NEW.verified_by IS NOT NULL AND NEW.verified_at IS NOT NULL)
BEGIN SELECT RAISE(ABORT, 'the only status change of a check is found -> verified'); END;
CREATE TRIGGER source_checks_cost_once BEFORE UPDATE ON source_checks
WHEN OLD.cost_amount IS NOT NULL AND (NEW.cost_amount IS NOT OLD.cost_amount OR NEW.cost_note IS NOT OLD.cost_note)
BEGIN SELECT RAISE(ABORT, 'a recorded cost is not rewritten'); END;

CREATE TABLE planning_items (
    id                 INTEGER PRIMARY KEY,
    pid                TEXT NOT NULL UNIQUE,
    office_id          INTEGER NOT NULL REFERENCES offices (id),
    case_id            INTEGER NOT NULL REFERENCES cases (id),
    kind               TEXT NOT NULL CHECK (kind IN ('plan', 'application', 'decision', 'permit', 'building_doc', 'enforcement')),
    ref_number         TEXT,
    title              TEXT NOT NULL,
    status_reported    TEXT,                                 -- הסטטוס כפי שדווח במקור, בלשונו — המערכת אינה מתרגמת אותו
    item_date          TEXT,
    source_id          INTEGER REFERENCES sources (id),
    check_id           INTEGER REFERENCES source_checks (id),
    url                TEXT,
    file_id            INTEGER REFERENCES files (id),
    related_id         INTEGER REFERENCES planning_items (id),   -- קישור בין פריטים (היתר ← הבקשה שלו); אינו משנה אף אחד מהם
    applicability      TEXT NOT NULL DEFAULT 'unknown' CHECK (applicability IN ('unknown', 'applies', 'partial', 'not_applies')),
    applicability_by   INTEGER REFERENCES users (id),
    applicability_at   TEXT,
    applicability_note TEXT,
    note               TEXT,
    created_by         INTEGER REFERENCES users (id),
    created_at         TEXT NOT NULL,
    updated_by         INTEGER REFERENCES users (id),
    updated_at         TEXT NOT NULL,
    deleted_at         TEXT,
    deleted_by         INTEGER REFERENCES users (id),
    delete_reason      TEXT,
    -- תחולה נקבעת רק בידי אדם, עם הערה; "לא ידוע" היא נקודת ההתחלה, לא קביעה
    CHECK (applicability = 'unknown' OR (applicability_by IS NOT NULL AND applicability_at IS NOT NULL AND applicability_note IS NOT NULL)),
    CHECK (deleted_at IS NULL OR delete_reason IS NOT NULL),
    CHECK (related_id IS NULL OR related_id <> id)
);
CREATE INDEX planning_items_case ON planning_items (case_id, kind);
CREATE INDEX planning_items_file ON planning_items (file_id);

CREATE TRIGGER planning_items_no_delete BEFORE DELETE ON planning_items BEGIN SELECT RAISE(ABORT, 'planning item is never deleted'); END;
-- בקשה אינה היתר: סוג של פריט אינו משתנה. היתר הוא פריט חדש.
CREATE TRIGGER planning_items_kind BEFORE UPDATE ON planning_items
WHEN NEW.kind IS NOT OLD.kind OR NEW.id IS NOT OLD.id OR NEW.pid IS NOT OLD.pid OR NEW.case_id IS NOT OLD.case_id OR NEW.office_id IS NOT OLD.office_id
BEGIN SELECT RAISE(ABORT, 'the kind of a planning item never changes - an application is not a permit'); END;

-- הקטלוג (§4) — לפי מחקר הרשויות; כל כרטיס מציין את מקורו במחקר. INSERT OR IGNORE לפי key: זריעה חוזרת אינה משנה ואינה מכפילה.
-- אף כרטיס אינו נזרע connector או approved, וכל שדות license נשארים unknown (ברירת המחדל של העמודה) — שום רישיון אינו מונח.
INSERT OR IGNORE INTO sources (pid, key, name, owner, url, info_kinds, region, municipality, access_mode, feasibility, auth_required, cost_note, coverage_note, limitations, how_to, research_ref, sort) VALUES
(lower(hex(randomblob(8))), 'tax_authority_deals', 'רשות המסים — מאגר מידע נדל"ן', 'רשות המסים', 'https://www.nadlan.gov.il/', '["deals"]', 'national', NULL, 'guided', 'needs_inquiry', 'none', NULL,
 'חיפוש לציבור קיים. ממשק מורשה לא אומת.', 'אין להציג את תוצאת החיפוש כרשימה מלאה של העסקאות. שדות, עדכניות והיקף — לא אומתו.',
 'פותחים את אתר המאגר, מחפשים לפי גוש וחלקה או לפי כתובת, ורושמים כאן אילו מזהים נבדקו ומה נמצא.', 'מחקר הרשויות, סעיף 5', 10),
(lower(hex(randomblob(8))), 'planning_info', 'מינהל התכנון — מידע תכנוני', 'מינהל התכנון', 'https://mavat.iplan.gov.il/', '["planning"]', 'national', NULL, 'guided', 'needs_inquiry', 'none', NULL,
 'תוכניות בסמכות מקומית תלויות במה שהוועדה המקומית העבירה.', 'תוכנית שאינה מופיעה אינה בהכרח תוכנית שאינה קיימת.',
 'פותחים את אתר המידע התכנוני, מחפשים לפי גוש וחלקה או מספר תוכנית, ורושמים כאן אילו מזהים נבדקו ומה נמצא.', 'מחקר הרשויות, סעיף 4', 20),
(lower(hex(randomblob(8))), 'xplan', 'מינהל התכנון — XPLAN', 'מינהל התכנון', 'https://ags.iplan.gov.il/xplan/', '["planning", "map"]', 'national', NULL, 'guided', 'needs_inquiry', 'none', NULL,
 'אינו כולל תוכניות שהוגשו לפני 2011.', 'אין להציג תוצאת XPLAN כרשימה מלאה של כל התוכניות החלות.',
 'פותחים את XPLAN, מאתרים את החלקה במפה או לפי גוש וחלקה, ורושמים כאן אילו תוכניות הוצגו. תוכניות שלפני 2011 בודקים במקור אחר.', 'מחקר הרשויות, סעיף 4.2', 30),
(lower(hex(randomblob(8))), 'govmap', 'מפ"י / GovMap — איתור גוש-חלקה וכתובת', 'המרכז למיפוי ישראל', 'https://www.govmap.gov.il/', '["map"]', 'national', NULL, 'guided', 'needs_inquiry', 'none', NULL,
 'אינו אסמכתא חוקית לשטח הרשום.', 'לכל שכבה ותצלום יש לבדוק תנאי שימוש בנפרד.',
 'פותחים את GovMap, מאתרים לפי כתובת או לפי גוש וחלקה, ורושמים כאן מה אותר. לשטח הרשום — רק נסח.', 'מחקר הרשויות, סעיף 3', 40),
(lower(hex(randomblob(8))), 'tabu_extract', 'רישום מקרקעין — נסח טאבו', 'משרד המשפטים — הרשות לרישום והסדר זכויות מקרקעין', 'https://mekarkein-online.justice.gov.il/', '["rights"]', 'national', NULL, 'manual', 'manual_only', 'payment',
 'הפקת נסח בתשלום באתר המקור. המשתמש משלם שם בעצמו — המערכת אינה משלמת.', 'בתשלום באתר המקור. הנסח משקף את מועד ההפקה.', 'נסח ישן אינו מעיד על המצב היום.',
 'מפיקים נסח באתר המקור לפי גוש, חלקה ותת-חלקה, מצרפים אותו כאן, ורושמים את מועד ההפקה.', 'מחקר הרשויות, סעיף 8.1', 50),
(lower(hex(randomblob(8))), 'rmi_rights', 'רמ"י — אישור זכויות', 'רשות מקרקעי ישראל', 'https://www.gov.il/he/departments/israel_land_authority', '["rights"]', 'national', NULL, 'manual', 'manual_only', 'national_id', NULL,
 'נדרשת הזדהות לאומית. הודעה על רישום אינה אישור זכויות.', 'האישור מופק לבעל הזכות או למיופה כוחו.',
 'בעל הזכות מפיק את האישור באתר המקור אחרי הזדהות, ומעביר אותו. מצרפים אותו כאן.', 'מחקר הרשויות, סעיף 8.2', 60),
(lower(hex(randomblob(8))), 'housing_company', 'חברה משכנת', 'החברה המשכנת של הנכס', NULL, '["rights"]', 'national', NULL, 'manual', 'manual_only', 'none', NULL,
 'לפי הנכס.', 'כל חברה משכנת מנפיקה אישור בדרך משלה.',
 'מבררים מי החברה המשכנת של הנכס, מבקשים ממנה אישור זכויות, ומצרפים אותו כאן.', 'מחקר הרשויות, סעיף 8.2', 70),
(lower(hex(randomblob(8))), 'jerusalem_permits', 'עיריית ירושלים — תיקי רישוי', 'עיריית ירושלים', 'https://www.jerusalem.muni.il/', '["permits", "enforcement"]', 'municipality', 'ירושלים', 'guided', 'needs_inquiry', 'none', NULL,
 'שליפה אוטומטית לא נבדקה.', 'תיק שאינו סרוק אינו מופיע. היעדר רישום פיקוח אינו מעיד שאין הליך.',
 'פותחים את אתר העירייה, מאתרים את תיק הבניין לפי כתובת או גוש וחלקה, ורושמים כאן אילו בקשות, החלטות והיתרים נמצאו — כל אחד כפריט נפרד.', 'מחקר הרשויות, סעיף 6', 80),
(lower(hex(randomblob(8))), 'jerusalem_gis', 'עיריית ירושלים — GIS', 'עיריית ירושלים', 'https://www.jerusalem.muni.il/', '["map"]', 'municipality', 'ירושלים', 'guided', 'needs_inquiry', 'none', NULL,
 '', 'שכבות המפה אינן אסמכתא.', 'פותחים את מערכת המפות של העירייה, מאתרים את הנכס, ורושמים כאן מה אותר.', 'מחקר הרשויות, סעיף 6.1', 90),
(lower(hex(randomblob(8))), 'tlv_permits', 'עיריית תל אביב-יפו — בקשות והיתרים', 'עיריית תל אביב-יפו', 'https://www.tel-aviv.gov.il/', '["permits"]', 'municipality', 'תל אביב-יפו', 'guided', 'needs_inquiry', 'none', NULL,
 'שכבה ציבורית. תוצאה חיה לא אומתה.', 'בקשה להיתר אינה היתר — רושמים כל אחד כפריט נפרד.',
 'פותחים את אתר העירייה, מאתרים לפי כתובת או גוש וחלקה, ורושמים כאן אילו בקשות והיתרים נמצאו.', 'מחקר הרשויות, סעיף 7.1', 100),
(lower(hex(randomblob(8))), 'tlv_archive', 'עיריית תל אביב-יפו — ארכיון הנדסי', 'עיריית תל אביב-יפו', 'https://www.tel-aviv.gov.il/', '["permits"]', 'municipality', 'תל אביב-יפו', 'guided', 'needs_inquiry', 'none', NULL,
 'ייתכנו מידע ישן ושגיאות סריקה.', 'חלק מהחומרים מוגנים בזכויות. שגיאות תיוק אפשריות.',
 'פותחים את הארכיון ההנדסי, מאתרים את תיק הבניין, ורושמים כאן אילו מסמכים נמצאו.', 'מחקר הרשויות, סעיף 7.2', 110),
(lower(hex(randomblob(8))), 'cbs_indices', 'הלמ"ס — מדדים', 'הלשכה המרכזית לסטטיסטיקה', 'https://www.cbs.gov.il/', '["stats"]', 'national', NULL, 'guided', 'needs_inquiry', 'none', NULL,
 'מדדים, לא עסקאות פרטניות.', 'מדד אינו מחיר של נכס.', 'פותחים את אתר הלמ"ס, מאתרים את המדד והתקופה, ורושמים כאן את הערך ואת מועד הפרסום.', 'מחקר הרשויות, סעיף 10', 120),
(lower(hex(randomblob(8))), 'other_municipality', 'רשות מקומית אחרת', 'הוועדה המקומית לתכנון ולבנייה', NULL, '["permits", "planning"]', 'municipality', NULL, 'manual', 'manual_only', 'none', NULL,
 'כרטיס כללי: אתר הוועדה המקומית.', 'לכל רשות אתר ונוהל משלה — רושמים בבדיקה באיזה אתר ומה נבדק.',
 'מאתרים את אתר הוועדה המקומית של היישוב, בודקים שם את תיק הבניין, ורושמים כאן באיזה אתר נבדק ומה נמצא.', 'מחקר הרשויות, סעיף 10', 130);
