# מה צ'אט הקוד הבא צריך לדעת


## אחרי הפרוסה הראשונה (2026-11-23, PR #1808)

- **מבנה:** `lib/` (צד שרת, `.htaccess` חוסם) · `api/snapshot.php` (GET בלבד) · `assets/js/` סקריפטים רגילים עם `window.CA`
  (לא ES modules — כדי ש-`node --check` והשער יעבדו פשוט). סדר הטעינה ב-`index.html`: api → render → screens → router → app.
- **כל חישוב בשרת** (`lib/model.php`). ה-UI רק מציג. מדד חדש — שם, ובדיקה ב-`tests/run.php`.
- **חוזה חדש (v2):** `lib/validate.php` (קבועים + `ACADEMY_SCHEMA_COMMIT`), fixtures ב-`tests/fixtures/` (+ README שם), מסכי
  "אין בחוזה" ב-`assets/js/screens.js` (`noContract(...)`).
- **להדליק github:** רק אחרי אישור בעלים שהמאגר פרטי ונבדק — `ACADEMY_GITHUB_PHASE_ALLOWED=true` ב-PR, ואז `data/config.json`
  בשרת עם שני הדגלים ואסימון. המטמון ב-`data/cache/` (מוחרג מהפריסה).
- **בדיקה בדפדפן:** `php -S 127.0.0.1:8765 -t .` משורש הריפו → `/cyber-academy/` (וגם `?demo=empty`). אין שער מקומית.
- **בדיקות:** `php cyber-academy/tests/run.php` — 124.
