-- פרוסה 05 (docs/spec/05-sharing.md §2): שיתוף תיק עם שמאי ממשרד אחר, וגישת תמיכה למנהל המערכת.
-- טבלאות חדשות בלבד — שום שורה קיימת אינה משתנה, ו-case_members נשארת "משתתפים פנימיים בלבד": כל שאילתה שנכתבה עליה
-- בפרוסות 04–15 ממשיכה לראות רק את אנשי המשרד, בלי תנאי נוסף שאפשר לשכוח. ההכרעה לשיתוף — apr_share_for() ב-lib/shares.php.
-- כל הפקודות עם IF NOT EXISTS: הרצה חוזרת אינה משנה דבר.

-- מה הוצג למשתף רגע לפני האישור. params_hash — התוכן המדויק שאושר; שיתוף נוצר רק מתצוגה שלא נוצלה, של אותו משתמש ואותו תיק.
CREATE TABLE IF NOT EXISTS share_previews (
    id            INTEGER PRIMARY KEY,
    pid           TEXT NOT NULL UNIQUE,
    case_id       INTEGER NOT NULL REFERENCES cases (id),
    by_user_id    INTEGER NOT NULL REFERENCES users (id),
    params_hash   TEXT NOT NULL,
    summary       TEXT NOT NULL,                                  -- JSON
    created_at    TEXT NOT NULL,
    confirmed_at  TEXT
);
CREATE INDEX IF NOT EXISTS share_previews_case ON share_previews (case_id, by_user_id);

CREATE TABLE IF NOT EXISTS case_shares (
    id              INTEGER PRIMARY KEY,
    pid             TEXT NOT NULL UNIQUE,
    case_id         INTEGER NOT NULL REFERENCES cases (id),
    office_id       INTEGER NOT NULL REFERENCES offices (id),     -- המשרד הבעלים של התיק
    kind            TEXT NOT NULL CHECK (kind IN ('cross_office', 'support')),
    user_id         INTEGER REFERENCES users (id),                -- cross_office: האדם. support: NULL — מי שמנהל מערכת באותו רגע
    home_office_id  INTEGER REFERENCES offices (id),              -- המשרד של השותף, להצגה בלבד
    scope           TEXT NOT NULL CHECK (scope IN ('all', 'items')),
    perms           TEXT NOT NULL,                                -- JSON: view, download, see_internal, see_draft
    expires_at      TEXT,
    invite_status   TEXT NOT NULL CHECK (invite_status IN ('pending', 'active', 'declined')),
    reason          TEXT,
    preview_id      INTEGER REFERENCES share_previews (id),
    added_by        INTEGER NOT NULL REFERENCES users (id),
    created_at      TEXT NOT NULL,
    updated_at      TEXT NOT NULL,
    responded_at    TEXT,
    warned_at       TEXT,                                         -- ההתראה "3 ימים לפני תפוגה" נשלחה
    revoked_at      TEXT,
    revoked_by      INTEGER REFERENCES users (id),
    CHECK ((kind = 'support') = (user_id IS NULL)),
    -- תמיכה: תמיד עם תוקף, עם נימוק, ורק פריטים נבחרים (§4) — גם אם קוד עתידי ינסה אחרת
    CHECK (kind <> 'support' OR (expires_at IS NOT NULL AND reason IS NOT NULL AND length(trim(reason)) > 0 AND scope = 'items'))
);
-- שורה פעילה אחת לאדם בתיק, ושורת תמיכה פעילה אחת לתיק (§8.7, §8.8)
CREATE UNIQUE INDEX IF NOT EXISTS case_shares_active_user ON case_shares (case_id, user_id) WHERE revoked_at IS NULL AND kind = 'cross_office';
CREATE UNIQUE INDEX IF NOT EXISTS case_shares_active_support ON case_shares (case_id) WHERE revoked_at IS NULL AND kind = 'support';
CREATE INDEX IF NOT EXISTS case_shares_user ON case_shares (user_id, revoked_at);

-- כש-scope = items: מה שותף. item_pid NULL = כל הפריטים מהסוג (גם כאלה שיתווספו אחר כך — §8.6)
CREATE TABLE IF NOT EXISTS case_share_items (
    id         INTEGER PRIMARY KEY,
    share_id   INTEGER NOT NULL REFERENCES case_shares (id),
    item_type  TEXT NOT NULL CHECK (item_type IN ('requirement', 'file', 'visit', 'source_check', 'comps', 'report')),
    item_pid   TEXT
);
CREATE INDEX IF NOT EXISTS case_share_items_share ON case_share_items (share_id);

-- הרשאות של שיתוף אינן כוללות פעולת כתיבה, ניהול משתתפים, אישור דוח או ייצוא — גם לא ב-SQL ישיר (05-A5, 05-B3)
CREATE TRIGGER IF NOT EXISTS case_shares_perms_ins BEFORE INSERT ON case_shares
WHEN EXISTS (SELECT 1 FROM json_each(NEW.perms) WHERE key NOT IN ('view', 'download', 'see_internal', 'see_draft') AND type NOT IN ('false', 'null'))
  OR (NEW.kind = 'support' AND EXISTS (SELECT 1 FROM json_each(NEW.perms) WHERE key NOT IN ('view', 'download') AND type NOT IN ('false', 'null')))
BEGIN SELECT RAISE(ABORT, 'case_shares: perm not grantable'); END;

CREATE TRIGGER IF NOT EXISTS case_shares_perms_upd BEFORE UPDATE OF perms, kind ON case_shares
WHEN EXISTS (SELECT 1 FROM json_each(NEW.perms) WHERE key NOT IN ('view', 'download', 'see_internal', 'see_draft') AND type NOT IN ('false', 'null'))
  OR (NEW.kind = 'support' AND EXISTS (SELECT 1 FROM json_each(NEW.perms) WHERE key NOT IN ('view', 'download') AND type NOT IN ('false', 'null')))
BEGIN SELECT RAISE(ABORT, 'case_shares: perm not grantable'); END;

-- שיתוף אינו נמחק: ביטול הוא revoked_at, וההיסטוריה נשארת
CREATE TRIGGER IF NOT EXISTS case_shares_no_delete BEFORE DELETE ON case_shares
BEGIN SELECT RAISE(ABORT, 'case_shares: rows are revoked, not deleted'); END;
