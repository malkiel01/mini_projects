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

**מצב:** מיושר מול 01 (מוזגה, #1839). E1–E10 אוטומטיים ב-`tests/ext.sh` (נקרא מ-`run.sh`). E11 ידני אחרי פריסה.
ממתין למיזוג 02 (`finalAllowed`) — עד אז `extFinalAllowed()` ב-`ext-lib.php` מחזיר את אותו כלל (ui/field/import בלבד).

**איך 05 יושבת על 01:**
- `ext.php`: האסימון → `extMember()` (users.active=1 וגם members.active=1, ברגע הבקשה) → `extSetContext()` בונה את `fd_ctx`,
  וכל הרשאה היא `can()` דרך `EXT_PERM_MAP` (view→case.view, update→case.track, details/payments/create→case.edit). אף פעם לא `dev`.
- כתיבה: `existing()` (זר → 404) ו-`store(..., ctx()['actor'], 'api', ...)` — התיק והאירוע יחד. יומן: `logRequest('ext:…')`.
- `tokens.php`: `resolveAccess()` — הארגון הפעיל של 01. מפתח בלי תפקיד בארגון לא יוצר אסימון (אין תפקיד לתת לו).
- טבלת `tokens` — `extEnsureSchema()` לפי שם ואידמפוטנטית, **בלי** מספר גרסה — כדי לא להתנגש במיגרציה של 03 (הצעת הבקרה).

**כש-02 תמוזג:** לבדוק ש-`finalAllowed('api')` מחזירה false, ושהשדות `by/via/at` של 02 תואמים למה ש-05 כותבת.
**כש-03 תמוזג:** השמות `dropboxCaseFiles($pdo, $case)` / `dropboxFileText($pdo, $orgId, $fileId)` — לבדוק מול 03 (שאלתי, טרם נענה).
**PATH_INFO:** OpenAPI משתמש ב-`ext.php/<action>`; `?action=` נתמך גם. אם לא עובד בשרת (E11) — `ext/<action>.php` או rewrite.
