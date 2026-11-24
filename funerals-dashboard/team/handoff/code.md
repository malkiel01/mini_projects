# מה צ'אט הקוד הבא צריך לדעת


## אחרי פרוסה 00 (סקר) — 2026-11-24, PR ‏#1834

- **קודם לקרוא:** `dev/audit.md`. יש בו מצב 12 הכללים ו-D1–D8, מה שומרים ומה מחליפים (סעיף ג׳), ותוכנית המעבר (סעיף ד׳).
- **הקוד:** חמישה קבצים, כ-1,200 שורות. `lib.php` הוא הליבה. `api.php` משרת את הדפדפן ו-`ingest.php` עובד עם Bearer. ה-JS מוטמע בתוך `index.html`, ו-`tests/run.sh` מחלץ אותו משם (שורה 13).
- **החוסר שחוסם הכול:** אין משתמשים ואין ארגונים. פרוסת הקוד הראשונה היא הסכמה והמיגרציה.
- **נתונים חיים:** 22 תיקים ב-`data/funerals.sqlite`, ו-hash של אסימון ב-`data/config.php`. לא נוגעים ב-`funerals`, ב-`audit` וב-`config.php`. לפני המיגרציה גיבוי ל-`data/backup/`. האסימון הישן חייב להמשיך לעבוד, ובדיקה ב-`run.sh` מוודאת זאת.
- **נתיבים חדשים רק תחת `data/`.** כך ההחרגה הקיימת ב-`deploy.yml:360` מכסה אותם ולא צריך `--exclude` חדש.
- **מזהי התיקים הקיימים (`fd_…`) נשמרים.** המזהה מחושב היום משם, חודש ותאריך (`lib.php:127`), ולכן תיקון שם בייבוא יוצר תיק כפול.
- **בדיקות לפני push:** `php platform/check-apps.php` ו-`bash funerals-dashboard/tests/run.sh`. שניהם רצים בכ-10 שניות.

## אחרי פרוסה 01 (תשתית) — 2026-11-24, PR ‏#1839 (מוזג)

- **המבנה:** `lib.php` (ליבה, `VERSION`, `store()` / `existing()` / `normalize($v, $orgId)`), ותחת `lib/`: `schema.php` (מיגרציות), `access.php` (`resolveAccess`, `can`, `scope`, `need`, `ctx`, `PERMS`), `events.php` (`recordEvent`, `caseDiff`, `logRequest`), `secrets.php` (`secretPut` / `secretGet`), `devtasks.php` (חיבור DevLoop). הממשק ב-`assets/js/app.js`; `index.html` הוא HTML בלבד.
- **מיגרציה חדשה:** `migrateV2()` ב-`lib/schema.php` + העלאת `SCHEMA_VERSION`. הגיבוי ל-`data/backup/` קורה לבד לפני כל קפיצה. מוסיפים בלבד; `hasColumn()` לעמודות. 02/03/05 נבנו במקביל — מי שממוזג שני ממספר מחדש.
- **הרשאות:** הרשאה חדשה = שורה ב-`PERMS`; כל פעולה ב-`api.php` קוראת ל-`need()`. תיק של ארגון אחר → 404 (`existing`). עובד שטח: רק `TRACK_FIELDS` (checks, notes, grave), בהשוואה מחמירה מול `normalize($old)`.
- **פעיל:** `members.active` — השבתה לארגון (מנהל); `users.active` — חסימה גלובלית (מפתח, `user_active`). חבר פעיל = שניהם 1. מפתח (`platform_admin`) לא נחסם.
- **זהות:** קישור לפי מייל ומימוש הזמנה רק עם `email_verified === true`. הזמנה תמיד ממתינה עד הכניסה הבאה.
- **מקורות ב-events:** `ui | import | api | system` (`EVENT_SOURCES`). מקור חדש (dropbox, gpt, ai) — להוסיף שם.
- **ציבורי:** `ingest.php` ו-`api.php?action=devapi-*`. `tests/run.sh` נועל את רשימת `public` — כתובת חדשה מעדכנים גם שם.
- **סודות:** `secret.key` נוצר בשימוש הראשון; בלי sodium נופלת רק פעולת סוד.
- **בדיקות:** `tests/run.sh` מתחיל ממסד v0 עם 22 תיקים (`tests/fixture-v0.php`), כ-15 שניות. CI: `.github/workflows/funerals-dashboard-ci.yml` (`coverage: none`, sodium) — הבדיקה נכשלת על כל אזהרת PHP בלוג השרת.
- **פתוח:** A15/A16 ידניים בשרת (מטלה חדשה תקבל FD-008 — מפת הדרכים תופסת FD-001…007). שורת `.devloop.json` — אצל הבקרה. בטלפון הכותרת של המפתח צפופה (6 כפתורים) — לאחד 📥/📤 לתפריט.
