# מה צ'אט הקוד הבא צריך לדעת


## אחרי פרוסה 00 (סקר) — 2026-11-24, PR ‏#1834

- **קודם לקרוא:** `dev/audit.md`. יש בו מצב 12 הכללים ו-D1–D8, מה שומרים ומה מחליפים (סעיף ג׳), ותוכנית המעבר (סעיף ד׳).
- **הקוד:** חמישה קבצים, כ-1,200 שורות. `lib.php` הוא הליבה. `api.php` משרת את הדפדפן ו-`ingest.php` עובד עם Bearer. ה-JS מוטמע בתוך `index.html`, ו-`tests/run.sh` מחלץ אותו משם (שורה 13).
- **החוסר שחוסם הכול:** אין משתמשים ואין ארגונים. פרוסת הקוד הראשונה היא הסכמה והמיגרציה.
- **נתונים חיים:** 22 תיקים ב-`data/funerals.sqlite`, ו-hash של אסימון ב-`data/config.php`. לא נוגעים ב-`funerals`, ב-`audit` וב-`config.php`. לפני המיגרציה גיבוי ל-`data/backup/`. האסימון הישן חייב להמשיך לעבוד, ובדיקה ב-`run.sh` מוודאת זאת.
- **נתיבים חדשים רק תחת `data/`.** כך ההחרגה הקיימת ב-`deploy.yml:360` מכסה אותם ולא צריך `--exclude` חדש.
- **מזהי התיקים הקיימים (`fd_…`) נשמרים.** המזהה מחושב היום משם, חודש ותאריך (`lib.php:127`), ולכן תיקון שם בייבוא יוצר תיק כפול.
- **בדיקות לפני push:** `php platform/check-apps.php` ו-`bash funerals-dashboard/tests/run.sh`. שניהם רצים בכ-10 שניות.
## פרוסה 05 — API חיצוני (ענף `claude/funerals-dashboard-05-external-api`, PR #1840)

**מצב:** מיושר מול 01 (#1839) ו-02 (#1842). E1–E10 אוטומטיים ב-`tests/ext.sh` (נקרא מ-`run.sh`). E11 ידני אחרי פריסה.

**איך 05 יושבת על 01:**
- `ext.php`: האסימון → `extMember()` (users.active=1 וגם members.active=1, ברגע הבקשה) → `extSetContext()` בונה את `fd_ctx`,
  וכל הרשאה היא `can()` דרך `EXT_PERM_MAP` (view→case.view, update→case.track, details/payments/create→case.edit). אף פעם לא `dev`.
- כתיבה: `formSaveCase($pdo, $raw, 'api', ctx()['actor'], $org, fn => need(caseChangeNeeds(...)))` של 02 — בלי מימוש משלנו:
  "מאומת" → "לאימות", ובדיקה שאדם כבר סגר לא נפתחת מחדש (ההצעה בהערה). `caseChangeNeeds()` הועבר מ-`api.php` ל-`lib/access.php`.
  לפני כן — בדיקה לפי שדה (`EXT_FIELDS`), כדי לומר לצ'אט בדיוק מה אסור. יומן: `logRequest('ext:…')`.
- `tokens.php`: `resolveAccess()` — הארגון הפעיל של 01. מפתח בלי תפקיד בארגון לא יוצר אסימון (אין תפקיד לתת לו).
- טבלת `tokens` — `extEnsureSchema()` לפי שם ואידמפוטנטית, **בלי** מספר גרסה — כדי לא להתנגש במיגרציה של 03 (הצעת הבקרה).

**03:** files/file_text מיושרות לחתימות של 03 (#1844): `dropbox/actions.php` נטען לפי צורך, `dropboxFileText(int)`, DbxError → 422/501/413/502. נבדק בתחליף (`tests/ext-dbx.php`, מדלג כש-03 קיימת). אחרי מיזוג 03 — להריץ שוב.
**PATH_INFO:** OpenAPI משתמש ב-`ext.php/<action>`; `?action=` נתמך גם. אם לא עובד בשרת (E11) — `ext/<action>.php` או rewrite.

**לפרוסה 06 (AI):** הפעולות הן `extDo(PDO $pdo, string $action, array $in, string $via = 'api'): array` ב-`ext-lib.php`.
- הקשר: `ctx()` של 01 — ב-06 אחרי `resolveAccess()` של המשתמש המחובר; ב-ext.php מ-`extSetContext()` של האסימון.
- `$via = 'ai'` → `via: ai` בבדיקות, "<שם> (AI)" ביומן, ו"מאומת" → "לאימות" (finalAllowed('ai') = false).
- שגיאה של הפעולה → `ExtError` (getCode = 400/403/404/501, getMessage בעברית) — להחזיר למודל. **אבל** פונקציות הליבה
  (`normalize`, `cleanText`, `existing`, `need`) עונות בעצמן ב-`reply()` ויוצאות — ב-06 זה מסיים את הבקשה עם JSON שגיאה.
- רשימת הכלים: `extActions()` (שם, שיטה, הרשאה, תיאור he/en, פרמטרים, סכמת גוף) — אותה טבלה של openapi.json.
- בדיקה: `tests/ext-ai.php`, `tests/ext-ai2.php`.

## אחרי פרוסה 01 (תשתית) — 2026-11-24, PR ‏#1839 (מוזג)

- **המבנה:** `lib.php` (ליבה, `VERSION`, `store()` / `existing()` / `normalize($v, $orgId)`), ותחת `lib/`: `schema.php` (מיגרציות), `access.php` (`resolveAccess`, `can`, `scope`, `need`, `ctx`, `PERMS`), `events.php` (`recordEvent`, `caseDiff`, `logRequest`), `secrets.php` (`secretPut` / `secretGet`), `devtasks.php` (חיבור DevLoop). הממשק ב-`assets/js/app.js`; `index.html` הוא HTML בלבד.
- **מיגרציה חדשה:** `migrateVn()` ב-`lib/schema.php` + העלאת `SCHEMA_VERSION`, ובתוך `migrate()` שורה `if ($from < n) migrateVn($pdo);`. הגיבוי ל-`data/backup/` קורה לבד לפני כל קפיצה. מוסיפים בלבד; `CREATE … IF NOT EXISTS` ו-`hasColumn()` לעמודות, כדי שמיגרציה תהיה אידמפוטנטית.
- **התאמה ל-03 ול-05 (נבנו במקביל):** הן מביאות v2 / v3 (חיבורי Dropbox; טבלת `tokens`). מי שממוזג שני מיישר את המספור מול `main` — מספר רץ, בלי לדלג ובלי שתי מיגרציות באותו מספר — ומוסיף בדיקה ב-`run.sh` שעוברת ממסד v1 לגרסה החדשה בלי לשנות תיק. 05 מסננת חברים לפי `members.active=1` ו-`users.active=1`.
- **הרשאות:** הרשאה חדשה = שורה ב-`PERMS`; כל פעולה ב-`api.php` קוראת ל-`need()`. תיק של ארגון אחר → 404 (`existing`). עובד שטח: רק `TRACK_FIELDS` (checks, notes, grave), בהשוואה מחמירה מול `normalize($old)`.
- **פעיל:** `members.active` — השבתה לארגון (מנהל); `users.active` — חסימה גלובלית (מפתח, `user_active`). חבר פעיל = שניהם 1. מפתח (`platform_admin`) לא נחסם.
- **זהות:** קישור לפי מייל ומימוש הזמנה רק עם `email_verified === true`. הזמנה תמיד ממתינה עד הכניסה הבאה.
- **מקורות ב-events:** `ui | import | api | system` (`EVENT_SOURCES`). מקור חדש (dropbox, gpt, ai) — להוסיף שם.
- **ציבורי:** `ingest.php` ו-`api.php?action=devapi-*`. `tests/run.sh` נועל את רשימת `public` — כתובת חדשה מעדכנים גם שם.
- **סודות:** `secret.key` נוצר בשימוש הראשון; בלי sodium נופלת רק פעולת סוד.
- **בדיקות:** `tests/run.sh` מתחיל ממסד v0 עם 22 תיקים (`tests/fixture-v0.php`), כ-15 שניות. CI: `.github/workflows/funerals-dashboard-ci.yml` (`coverage: none`, sodium) — הבדיקה נכשלת על כל אזהרת PHP בלוג השרת.
- **פתוח:** A15/A16 ידניים בשרת (מטלה חדשה תקבל FD-008 — מפת הדרכים תופסת FD-001…007). שורת `.devloop.json` — אצל הבקרה. **בטלפון הכותרת של המפתח צפופה** (📥 📤 ⚙️ 🛠️ 🐞 👤 — שישה כפתורים, והשם נחתך) — להעביר את 📥/📤 לתפריט 👤 או לסרגל שמתחת לכותרת.

## אחרי פרוסה 02 (טופס חכם) — 2026-11-24, PR ‏#1842

- **הכלל בפונקציה אחת:** `finalAllowed($via)` ב-`lib/form.php`. **כל כתיבה של תיק בודד עוברת ב-`formSaveCase($pdo, $raw, $via, $actor, $orgId, $authorize)`** —
  היא מחילה `by`/`via`/`at`, את "הושלם רק באדם" ואת `manual_fields`, וקוראת ל-`store()` (שרושם ב-`events`). 03/04/05/06 — לא לממש מחדש.
- `via` ∈ `ui` · `field` · `dropbox` · `api` · `ai` · `import`. ממופה למקור של `events` ב-`formEventSource()` (field→ui, ai→api, dropbox→system).
- מקור אוטומטי **לא פותח מחדש** בדיקה שאדם סגר; ההצעה נכנסת להערה (הכרעה של 02, ב-PR).
- הממשק: הלוגיקה (מצב תיק, "הבא בתור", סינונים, מקור) ב-`assets/js/form.js` — טהורה, נבדקת ב-node (`tests/form.test.js`). `app.js` רק מצייר.
- היסטוריה: `action=history&id=…` → `formHistory()`; `formDiffChanges()` מסנן רעש של `caseDiff()` (402 מול 402.0 — ממצא שעוד פתוח אצל 01).
- בדיקות: `tests/form.test.php` (בלי `data/`), `tests/form.test.js`, וקטע "פרוסה 02" ב-`run.sh`. `tests/fixtures/legacy-cases.json` — 22 תיקים בצורות ישנות.
- **פתוח:**
  - B8 ידני בטלפון (מטלה לבעלים).
  - **רעש ב-`events`:** `caseDiff()` (`lib/events.php`) רושם `payments` כשינוי בכל save, כי סכום שחוזר מ-JSON כ-402.0 מול 402 נכשל ב-`!==`.
    התיקון: השוואה רופפת לשדות מספריים (כמו `caseChangeNeeds`). ההיסטוריה של 02 מסננת את זה בתצוגה בלבד — ב-`events` זה נשאר.
  - 05 מימש fallback משלו ל-`finalAllowed` — הבקרה העבירה לו לעבור דרך `formSaveCase()` (ראה `team/log/code.md`).
- **הכרעות 02 (אושרו בבקרה):** אוטומטי לא פותח מחדש בדיקה שאדם סגר; מיפוי `via` → מקור `events`; בדיקה בלי מקור ועם תוכן = "מייבוא ישן".
