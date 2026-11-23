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

## אחרי פרוסה 02

- **קוד כשל חדש** — ב-`lib/api.php` (או `academy_github_error`), מסך ב-`assets/js/states.js` (`STATES`), ומצב ב-`tests/browser/check.js`.
- **`approval`** — כשיגיע חוזה v2: לאפשר בשדות של `lib/validate.php`; `academy_curriculum_status` כבר קורא אותו.
- **בדיקת דפדפן** — `php -S 127.0.0.1:8765 -t .` ואז `node cyber-academy/tests/browser/check.js <dir>`. אין בפריסה (אין Node בשרת).

## אחרי תשתית v2

- `schemaVersion` מנתב: v1 → `validate.php`/`model.php`/`screens.js`; v2 → `validate-v2.php`/`model-v2.php`/`screens-v2.js` (`CA.screensV2`, `#/module/<id>`).
- מפרט השדות של v2 — `academy_v2_spec()`. שינוי בחוזה = שם + fixture ב-`tests/fixtures/v2/` + בדיקת jsonschema על ה-schema המקורי.
- github + v2: `contract: "v2"` + `github.v2_path`. הסינון `academy_v2_curriculum_only` — רק סילבוס מאושר, בלי נתוני לומד. לא להסיר בלי החלטה.
