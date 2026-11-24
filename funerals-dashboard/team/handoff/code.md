# מה צ'אט הקוד הבא צריך לדעת


## אחרי פרוסה 00 (סקר) — 2026-11-24, PR ‏#1834

- **קודם לקרוא:** `dev/audit.md`. יש בו מצב 12 הכללים ו-D1–D8, מה שומרים ומה מחליפים (סעיף ג׳), ותוכנית המעבר (סעיף ד׳).
- **הקוד:** חמישה קבצים, כ-1,200 שורות. `lib.php` הוא הליבה. `api.php` משרת את הדפדפן ו-`ingest.php` עובד עם Bearer. ה-JS מוטמע בתוך `index.html`, ו-`tests/run.sh` מחלץ אותו משם (שורה 13).
- **החוסר שחוסם הכול:** אין משתמשים ואין ארגונים. פרוסת הקוד הראשונה היא הסכמה והמיגרציה.
- **נתונים חיים:** 22 תיקים ב-`data/funerals.sqlite`, ו-hash של אסימון ב-`data/config.php`. לא נוגעים ב-`funerals`, ב-`audit` וב-`config.php`. לפני המיגרציה גיבוי ל-`data/backup/`. האסימון הישן חייב להמשיך לעבוד, ובדיקה ב-`run.sh` מוודאת זאת.
- **נתיבים חדשים רק תחת `data/`.** כך ההחרגה הקיימת ב-`deploy.yml:360` מכסה אותם ולא צריך `--exclude` חדש.
- **מזהי התיקים הקיימים (`fd_…`) נשמרים.** המזהה מחושב היום משם, חודש ותאריך (`lib.php:127`), ולכן תיקון שם בייבוא יוצר תיק כפול.
- **בדיקות לפני push:** `php platform/check-apps.php` ו-`bash funerals-dashboard/tests/run.sh`. שניהם רצים בכ-10 שניות.

## אחרי פרוסה 02 (טופס חכם) — 2026-11-24, PR ‏#1842

- **הכלל בפונקציה אחת:** `finalAllowed($via)` ב-`lib/form.php`. **כל כתיבה של תיק בודד עוברת ב-`formSaveCase($pdo, $raw, $via, $actor, $orgId, $authorize)`** —
  היא מחילה `by`/`via`/`at`, את "הושלם רק באדם" ואת `manual_fields`, וקוראת ל-`store()` (שרושם ב-`events`). 03/04/05/06 — לא לממש מחדש.
- `via` ∈ `ui` · `field` · `dropbox` · `api` · `ai` · `import`. ממופה למקור של `events` ב-`formEventSource()` (field→ui, ai→api, dropbox→system).
- מקור אוטומטי **לא פותח מחדש** בדיקה שאדם סגר; ההצעה נכנסת להערה (הכרעה של 02, ב-PR).
- הממשק: הלוגיקה (מצב תיק, "הבא בתור", סינונים, מקור) ב-`assets/js/form.js` — טהורה, נבדקת ב-node (`tests/form.test.js`). `app.js` רק מצייר.
- היסטוריה: `action=history&id=…` → `formHistory()`; `formDiffChanges()` מסנן רעש של `caseDiff()` (402 מול 402.0 — ממצא שעוד פתוח אצל 01).
- בדיקות: `tests/form.test.php` (בלי `data/`), `tests/form.test.js`, וקטע "פרוסה 02" ב-`run.sh`. `tests/fixtures/legacy-cases.json` — 22 תיקים בצורות ישנות.
- **פתוח:** B8 ידני בטלפון. 05 מימש fallback משלו ל-`finalAllowed` — מי שממוזג שני מאחד ל-`formApplyChecks()` (ראה `team/log/code.md`).
