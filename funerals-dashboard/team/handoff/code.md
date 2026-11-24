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

**05 מוזגה (#1840, d7f0063).** פתוח אחרי המיזוג:
1. **E11** (ידני, הבעלים): GPT מותאם עם הפרומפט, קובץ ה-API והאסימון — "תוסיף ל<שם>: הערה בדיקה". במטלות.
   כאן ייבדק גם ש-`ext.php/<action>` (PATH_INFO) עובד בשרת; אם לא — `ext/<action>.php` או rewrite.
2. **אחרי מיזוג 03:** `tests/ext-dbx.php` ידלג (03 קיימת). לבדוק את `files`/`file_text` מול ה-Dropbox המדומה של 03
   (`tests/dropbox-mock.php`, `DBX_API_BASE=http://127.0.0.1:PORT`, כמו ב-`tests/dropbox-test.php`).
3. **אחרי מיזוג 03:** להעביר את `tokens` / `ext_fail` מ-`extEnsureSchema()` למיגרציה ממוספרת (migrateV3), אחרי v2 של 03.
4. **06:** קורא ל-`extDo(..., 'ai')`. פונקציות הליבה עונות ב-`reply()` ולא זורקות — אם 06 צריך, זה שינוי של 01.

