-- סכימת אפליקציית המתכונים — SQLite דרך PDO.
-- מקור האמת לאפיון: SPEC.md. כל טבלה כאן מצביעה על הסעיף שחייב אותה.
--
-- הרצה: הקובץ הזה הוא התיעוד. בפועל אותן פקודות יורדות ב-lib/db.php עם
-- CREATE TABLE IF NOT EXISTS, כמו ב-claude-tasks — כדי שפריסה על מסד קיים
-- לא תיפול ולא תדרוס.
--
-- הגדרות החיבור (נדרשות, לא נחמדות):
--   PRAGMA journal_mode = WAL;     קוראים במקביל לכותב
--   PRAGMA foreign_keys = ON;      בלעדיו כל ה-CASCADE שלמטה אינו קיים
--   PRAGMA busy_timeout = 5000;    כותב שני ממתין ולא נכשל
--
-- AUTOINCREMENT על כל המפתחות: בלעדיו SQLite מחזיר מזהה שהתפנה, וכתובת
-- של מתכון שנמחק הייתה מובילה למתכון חדש אחר. באתר שמשתפים ממנו קישורים
-- זו תקלה שקטה, ולכן המזהים כאן לעולם אינם חוזרים.

-- ═══════════════════════════════════════════════════════════
-- משתמשים  (סעיף 2)
-- ═══════════════════════════════════════════════════════════

CREATE TABLE users (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  username       TEXT    NOT NULL UNIQUE,
  email          TEXT    NOT NULL UNIQUE,   -- נדרש: אימות ואיפוס סיסמה
  password_hash  TEXT    NOT NULL,          -- password_hash(PASSWORD_DEFAULT)
  display_name   TEXT    NOT NULL,          -- ברירת מחדל: username
  role           TEXT    NOT NULL DEFAULT 'user'
                 CHECK (role IN ('user','admin')),
  email_verified INTEGER NOT NULL DEFAULT 0,
  blocked        INTEGER NOT NULL DEFAULT 0,  -- המנהל חוסם (2)
  storage_used   INTEGER NOT NULL DEFAULT 0,  -- בייטים. המקצב עצמו הוא הגדרה
  limit_video_bytes INTEGER,   -- דריסה אישית. NULL = יורש מ-app_settings
  limit_quota_bytes INTEGER,   -- דריסה אישית. NULL = יורש מ-app_settings
  last_mail_at   TEXT,         -- מתי נשלח לאחרונה דוא״ל למשתמש
  last_mail_ok   INTEGER,      -- האם ה-MTA קיבל אותו. NULL = לא נשלח
  created_at     TEXT    NOT NULL
);

-- הגדרות ציבוריות של המפתח: תקרת סרטון, מקצב, תקרת תמונה. מה שאינו כאן
-- נופל לברירת המחדל שבקוד (lib/settings.php). המשתמש יכול לדרוס כל אחת
-- דרך העמודות שלמעלה, והפתרון הוא: דריסה → ציבורי → ברירת מחדל.
CREATE TABLE app_settings (
  key        TEXT PRIMARY KEY,
  value      INTEGER NOT NULL,
  updated_at TEXT    NOT NULL
);

-- יומן: כל בקשה ואירוע (lib/log.php). נמחק אחרי 30 יום / 50k שורות.
-- user_id בלי FK בכוונה: היומן צריך לשרוד מחיקת משתמש.
CREATE TABLE app_log (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  at          TEXT    NOT NULL,
  level       TEXT    NOT NULL CHECK (level IN ('info','warn','error')),
  request_id  TEXT,
  user_id     INTEGER,
  username    TEXT,
  action      TEXT    NOT NULL,
  message     TEXT    NOT NULL DEFAULT '',
  meta        TEXT,                  -- JSON, שדות מרשימה סגורה בלבד
  ip          TEXT,
  user_agent  TEXT,
  duration_ms INTEGER
);
CREATE INDEX idx_app_log_at     ON app_log(at);
CREATE INDEX idx_app_log_level  ON app_log(level, at);
CREATE INDEX idx_app_log_action ON app_log(action, at);

-- טוקן צפייה ביומן שהמפתח מעביר למי שמפתח. נשמר רק ה-hash, עם תוקף וביטול.
CREATE TABLE log_tokens (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  token_hash   TEXT    NOT NULL UNIQUE,
  label        TEXT    NOT NULL,
  created_by   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  created_at   TEXT    NOT NULL,
  expires_at   TEXT    NOT NULL,
  revoked_at   TEXT,
  last_used_at TEXT,
  uses         INTEGER NOT NULL DEFAULT 0
);

-- אסימונים חד־פעמיים: אימות דוא"ל ואיפוס סיסמה. שורה אחת לשני השימושים,
-- כי המחזור זהה — נוצר, נשלח, נצרך פעם אחת, פג.
CREATE TABLE user_tokens (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  kind       TEXT    NOT NULL CHECK (kind IN ('verify_email','reset_password')),
  token_hash TEXT    NOT NULL UNIQUE,   -- נשמר כ-hash, לא כטקסט
  expires_at TEXT    NOT NULL,          -- איפוס: שעה
  used_at    TEXT,
  created_at TEXT    NOT NULL
);
CREATE INDEX idx_tokens_user ON user_tokens(user_id, kind);

-- ═══════════════════════════════════════════════════════════
-- מתכונים, חלקים, רכיבים, שלבים  (סעיף 3)
-- ═══════════════════════════════════════════════════════════

CREATE TABLE recipes (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  owner_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  title          TEXT    NOT NULL,
  visibility     TEXT    NOT NULL DEFAULT 'private'
                 CHECK (visibility IN ('private','public')),   -- מתג דו־כיווני (1)
  servings       INTEGER,                        -- בסיס להמרה (3.3)
  difficulty     TEXT    CHECK (difficulty IN ('easy','medium','hard')),  -- (4)
  work_minutes   INTEGER,                        -- זמן עבודה (4)
  wait_minutes   INTEGER,                        -- זמן המתנה (4)
  tips           TEXT,                           -- אזור טיפים והערות
  comments_open  INTEGER NOT NULL DEFAULT 1,     -- הכותב סוגר תגובות (7)
  main_media_id  INTEGER,                        -- תמונה ראשית (3.4). FK נוסף למטה
  search_text    TEXT,                           -- מנורמל לחיפוש (5)
  created_at     TEXT    NOT NULL,
  updated_at     TEXT    NOT NULL                -- "עודכן לאחרונה ב—" (6)
);
CREATE INDEX idx_recipes_owner      ON recipes(owner_id);
CREATE INDEX idx_recipes_public     ON recipes(visibility, updated_at);
CREATE INDEX idx_recipes_difficulty ON recipes(difficulty);

-- חלק = תת־מתכון (3.1). מתכון פשוט הוא שורה אחת עם name = NULL,
-- והממשק אינו מציג לו חלוקה בכלל.
CREATE TABLE sections (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  recipe_id INTEGER NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
  name      TEXT,                -- NULL = החלק היחיד, בלי שם
  position  INTEGER NOT NULL
);
CREATE INDEX idx_sections_recipe ON sections(recipe_id, position);

-- קטלוג מוצרים שגדל מעצמו (3.2). המפתח שרשימת הקניות מאחדת לפיו.
CREATE TABLE products (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  name         TEXT NOT NULL UNIQUE,
  name_norm    TEXT NOT NULL,      -- מנורמל להשלמה אוטומטית ולחיפוש
  grams_per_cup REAL,              -- להצעת המרה (3.2). NULL = אין הצעה
  created_at   TEXT NOT NULL
);
CREATE INDEX idx_products_norm ON products(name_norm);

-- רכיב = שתי שכבות (3.2). free_text הוא מה שהמבשל קורא;
-- amount/unit/product_id הם מה שהאפליקציה מחשבת בו. שכבה מחושבת
-- חסרה היא מצב תקף — הרכיב פשוט לא משתתף בחישובים.
CREATE TABLE ingredients (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  section_id INTEGER NOT NULL REFERENCES sections(id) ON DELETE CASCADE,
  free_text  TEXT    NOT NULL,     -- "2 כוסות", "קצת"
  amount_min REAL,                 -- טווח: "2-3 ביצים" (3.2)
  amount_max REAL,
  unit       TEXT    CHECK (unit IN ('gram','kg','ml','liter','cup',
                                     'tbsp','tsp','unit','package','pinch')),
  product_id INTEGER REFERENCES products(id) ON DELETE SET NULL,
  optional   INTEGER NOT NULL DEFAULT 0,   -- רכיב לא־חובה (3.2)
  position   INTEGER NOT NULL
);
CREATE INDEX idx_ingredients_section ON ingredients(section_id, position);
CREATE INDEX idx_ingredients_product ON ingredients(product_id);

CREATE TABLE steps (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  section_id INTEGER NOT NULL REFERENCES sections(id) ON DELETE CASCADE,
  position   INTEGER NOT NULL,
  text       TEXT    NOT NULL,
  media_id   INTEGER,              -- תמונה לשלב (3.1). FK נוסף למטה
  CHECK (position > 0)
);
CREATE INDEX idx_steps_section ON steps(section_id, position);

-- ═══════════════════════════════════════════════════════════
-- מדיה  (סעיף 3.4, ומגבלות בסעיף 10)
-- ═══════════════════════════════════════════════════════════

-- קישור והעלאה יושבים באותה טבלה, כי שניהם "מדיה של מתכון" מבחינת התצוגה.
-- ההבדל היחיד הוא מי מאחסן: source='link' שומר URL, source='upload'
-- שומר נתיב בתוך data/media/ ותופס נפח במקצב של המעלה.
CREATE TABLE media (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  recipe_id   INTEGER NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
  uploader_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  kind        TEXT    NOT NULL CHECK (kind IN ('image','video')),
  source      TEXT    NOT NULL CHECK (source IN ('upload','link')),
  path_or_url TEXT    NOT NULL,   -- upload: שם שהשרת חילל. link: כתובת
  mime        TEXT,               -- מ-finfo, לא מהסיומת (10)
  bytes       INTEGER NOT NULL DEFAULT 0,   -- 0 לקישור
  position    INTEGER NOT NULL DEFAULT 0,
  created_at  TEXT    NOT NULL
);
CREATE INDEX idx_media_recipe ON media(recipe_id, position);
CREATE INDEX idx_media_uploader ON media(uploader_id);

-- ═══════════════════════════════════════════════════════════
-- קטלוג ותגים  (סעיף 4)
-- ═══════════════════════════════════════════════════════════

-- הצירים הסגורים. המנהל מנהל אותם מתוך האפליקציה, ולכן הם שורות ולא קוד.
CREATE TABLE tags (
  id       INTEGER PRIMARY KEY AUTOINCREMENT,
  axis     TEXT    NOT NULL CHECK (axis IN ('topic','method','kosher',
                                            'suitable','cuisine')),
  name     TEXT    NOT NULL,
  position INTEGER NOT NULL DEFAULT 0,
  UNIQUE (axis, name)
);

CREATE TABLE recipe_tags (
  recipe_id INTEGER NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
  tag_id    INTEGER NOT NULL REFERENCES tags(id)    ON DELETE CASCADE,
  PRIMARY KEY (recipe_id, tag_id)
);
CREATE INDEX idx_recipe_tags_tag ON recipe_tags(tag_id);
-- "אחד למתכון" בכשרות ובמטבח נאכף בקוד ולא במסד: SQLite אינו יכול
-- לבטא "לכל היותר תג אחד מציר X" באילוץ, וזו אכיפה שכן צריכה להתריע
-- למשתמש בהודעה ולא ליפול בשגיאת מסד.

-- תג חופשי — פרטי למשתמש (4). לכן user_id כאן ולא בטבלת tags.
CREATE TABLE user_tags (
  id      INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  name    TEXT    NOT NULL,
  UNIQUE (user_id, name)
);

CREATE TABLE recipe_user_tags (
  recipe_id   INTEGER NOT NULL REFERENCES recipes(id)    ON DELETE CASCADE,
  user_tag_id INTEGER NOT NULL REFERENCES user_tags(id)  ON DELETE CASCADE,
  PRIMARY KEY (recipe_id, user_tag_id)
);

-- ═══════════════════════════════════════════════════════════
-- מועדפים ופתקים  (סעיפים 6, 7)
-- ═══════════════════════════════════════════════════════════

-- מועדף מצביע על המקור (6). אין ON DELETE CASCADE על recipe_id בכוונה:
-- מחיקת המתכון לא אמורה למחוק את המועדף בשקט — המשתמש צריך לראות
-- "הוסר על ידי הכותב". לכן ה-FK עם SET NULL ושדה כותרת־צל.
CREATE TABLE favorites (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  recipe_id      INTEGER REFERENCES recipes(id) ON DELETE SET NULL,
  title_snapshot TEXT    NOT NULL,   -- מה שיוצג כשהמקור נעלם
  created_at     TEXT    NOT NULL,
  UNIQUE (user_id, recipe_id)
);
CREATE INDEX idx_favorites_user ON favorites(user_id);

-- תגובה ציבורית ופתק פרטי באותה טבלה: אותו מבנה, אותה עריכה, אותה
-- תמונה — השוני היחיד הוא מי רואה.
--   visibility='public'       תגובה, מתכון ציבורי בלבד
--   visibility='private_note' פתק שרק הכותב רואה, על כל מתכון
-- parent_id: שתי רמות בלבד (7) — תשובה חייבת parent_id שהוא NULL־parent.
-- העומק נאכף בקוד; SQLite אינו מגביל עומק רקורסיה באילוץ.
CREATE TABLE comments (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  recipe_id  INTEGER NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
  user_id    INTEGER NOT NULL REFERENCES users(id)   ON DELETE CASCADE,
  parent_id  INTEGER REFERENCES comments(id) ON DELETE CASCADE,
  visibility TEXT    NOT NULL DEFAULT 'public'
             CHECK (visibility IN ('public','private_note')),
  text       TEXT    NOT NULL,
  media_id   INTEGER REFERENCES media(id) ON DELETE SET NULL,
  created_at TEXT    NOT NULL,
  edited_at  TEXT
);
CREATE INDEX idx_comments_recipe ON comments(recipe_id, visibility, created_at);
CREATE INDEX idx_comments_user   ON comments(user_id, visibility);
-- הסימון "נכתבה לפני עדכון המתכון" (6) מחושב בשאילתה:
--   comments.created_at < recipes.updated_at
-- ולכן אין לו שדה משלו — שדה כזה היה צריך עדכון בכל עריכת מתכון.

-- ═══════════════════════════════════════════════════════════
-- רשימת קניות  (סעיף 8)
-- ═══════════════════════════════════════════════════════════

CREATE TABLE shopping_lists (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  name       TEXT,
  created_at TEXT    NOT NULL
);
CREATE INDEX idx_lists_user ON shopping_lists(user_id, created_at);

-- מאיזה מתכונים נבנתה הרשימה — לשורה "מ-2 מתכונים" ולבנייה מחדש.
CREATE TABLE shopping_list_recipes (
  list_id   INTEGER NOT NULL REFERENCES shopping_lists(id) ON DELETE CASCADE,
  recipe_id INTEGER REFERENCES recipes(id) ON DELETE SET NULL,
  PRIMARY KEY (list_id, recipe_id)
);

-- השורות עצמן מוחשבות ונשמרות, ולא מחושבות בכל פתיחה: המשתמש מסמן מה
-- נקנה, ומתכון שנערך בינתיים לא אמור לשנות רשימה שהוא כבר בסופר איתה.
-- amount ו-unit הם NULL בשורה שלא ניתנה לאיחוד — היא מוצגת מ-free_text.
CREATE TABLE shopping_list_items (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  list_id    INTEGER NOT NULL REFERENCES shopping_lists(id) ON DELETE CASCADE,
  product_id INTEGER REFERENCES products(id) ON DELETE SET NULL,
  label      TEXT    NOT NULL,     -- שם המוצר, או שורה ידנית
  amount     REAL,
  unit       TEXT,
  free_text  TEXT,                 -- "2 כוסות + קורט" כשאין איחוד
  optional   INTEGER NOT NULL DEFAULT 0,
  manual     INTEGER NOT NULL DEFAULT 0,   -- הוספה ידנית (8)
  checked    INTEGER NOT NULL DEFAULT 0,
  position   INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_list_items ON shopping_list_items(list_id, position);

-- ═══════════════════════════════════════════════════════════
-- מצב בישול  (סעיף 8)
-- ═══════════════════════════════════════════════════════════

-- סימון "בוצע" הוא מצב ריצה ולא נתון של המתכון, ולכן טבלה נפרדת
-- לכל משתמש. שורה נמחקת כשמסיימים או מתחילים מחדש.
CREATE TABLE cooking_progress (
  user_id    INTEGER NOT NULL REFERENCES users(id)  ON DELETE CASCADE,
  step_id    INTEGER NOT NULL REFERENCES steps(id)  ON DELETE CASCADE,
  done_at    TEXT    NOT NULL,
  PRIMARY KEY (user_id, step_id)
);

-- ═══════════════════════════════════════════════════════════
-- מפתחות זרים שנוספים אחרי היצירה
-- ═══════════════════════════════════════════════════════════
-- recipes.main_media_id → media(id) ו-steps.media_id → media(id) הם
-- מחזוריים: media מצביעה על recipes, ו-recipes מצביעה חזרה. SQLite
-- אינו מוסיף FK לטבלה קיימת, ולכן שני אלה נאכפים בקוד:
--   * מחיקת שורת media מאפסת main_media_id ו-steps.media_id שהצביעו עליה.
--   * main_media_id חייב להיות media של אותו מתכון.
-- זו ההתפשרות המודעת היחידה על שלמות הנתונים בסכימה הזאת.
