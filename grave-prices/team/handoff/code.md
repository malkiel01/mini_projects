# מה צ'אט הקוד הבא צריך לדעת


## אחרי פרוסה 01 (#1654, מוזג 2026-11-16)

**המבנה**
- `api.php` — נתב יחיד (`match "$method $action"`); הלוגיקה ב-`lib/`: `db.php` (מסד, מיגרציות, `gp_audit`),
  `auth.php` (התחברות), `editions.php` (קריאה + `gp_label_html`). `lib/`, `tests/`, `dev/` — `.htaccess` חוסם.
- **מיגרציה חדשה** = מפתח חדש בסוף `gp_migrations()` (מספר עוקב, `PRAGMA user_version`). לעולם לא מוחקים.
- `GP_DATA_DIR` (env) — תיקיית נתונים חלופית; הבדיקות ושרת מקומי משתמשים בה, כך ש-`data/` לא נגעת.
- **התוויות** מפוענחות בשרת בלבד (`gp_label_html`); ה-API מחזיר גם `label` (גולמי — לעריכה ב-02) וגם `html`.
- מחירים: `rows[].prices` הוא `null` לשורה בלי מחיר (`section/subsection/heading/note`), אחרת `{il, abroad}`.
- POST — כותרת `X-CSRF` (מ-`me.csrf`). שגיאה — `ApiError(msg, status)`.

**המסכים**
- `assets/js/app.js` — ניתוב `#/`, `#/e/<pid>`, `#/dev/<tab>/<arg>`. עיצוב מ-`phone-first-design` (`app.css`).
- `print.html` + `assets/js/print.js` + `assets/css/print.css`. `&preview=1` — בלי חלון ההדפסה (לבדיקות).
  `rowsFor()` — ההסתרה בהדפסה של קבוצה אחת; `fit()` — הקטנה עד 80%.

**הכרעות (team/decisions.md)**: הערות נשארות בהדפסה של קבוצה אחת; `indent` לא מוזח בהדפסה (כמו במקור);
`((…))` עם סוגריים; `.devloop.json` — אצל הבקרה.

**בדיקה מקומית**: `php -S` עם router שקובע `$_SERVER['PLATFORM_USER']` ועושה `require` ל-`.php`
(חשבון מפתח: `platform_admin: true`), `GP_DATA_DIR` לתיקייה זמנית, ו-Playwright ל-`print.html?…&preview=1` → `page.pdf()`.
בקונטיינר אין גופן עברי מודגש — העברית בצילומים נראית דקה; זה לא באג.

**מחוץ לתיקייה**: הרישום ב-`devloop/apps.json` ושלב `grave-prices tests` ב-`deploy.yml` כבר קיימים.
