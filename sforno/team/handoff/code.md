# מה צ'אט הקוד הבא צריך לדעת

עודכן: 2026-11-22, אחרי מיזוג פרוסה 01 (PR #1766).

## מה בנוי

פרוסה 01 — תשתית (`docs/spec/01-foundation.md`), כולה. הפירוט — `README.md`; הסטיות מ-⚙️ — `team/log/code.md`.

| קובץ | תפקיד |
| --- | --- |
| `api.php` | הנתב היחיד (HTTP דק) → `lib/app.php` `sf_dispatch()` |
| `lib/access.php` | `SF_ACTIONS` — כל פעולה: שיטה, מי (`public` / `staff` / `dev`), `raw`, `body`. **פעולה חדשה = שורה כאן + `match` ב-`sf_run`** |
| `lib/db.php` | `sf_db`, `sf_q/rows/row/val/exec/insert`, `sf_tx` (BEGIN IMMEDIATE), `sf_meta`, מיגרציות |
| `lib/migrations/NNN-name.php` | מחזירה `function (PDO $db, string $driver)`; סוגי עמודות מ-`sf_ddl_types()`. **לא משנים מיגרציה שמוזגה — קובץ חדש** |
| `lib/auth.php`, `platform.php` | הזהות מהשער, `staff`, `staff_sessions`, העוגייה `sf_staff` |
| `lib/audit.php` | `sf_audit(action, entity_type, entity_pid, meta)` — בתוך `sf_tx` של השינוי |
| `lib/jobs.php` | `sf_job_register(kind, label, handler, daily)`, `sf_job_add(...)` |
| `lib/health.php` | `sf_health_register(id, label, fn, s24)` |
| `assets/js/` | `api.js` (`SF.api`, `SF.h`), `router.js` (`SF.route`), `screens/*.js`, `app.js` |
| `tests/` | `run.php` מריץ כל `*.test.php` בתהליך משלו; כלים ב-`lib.php` (`call`, `ident`, `eq`) |

## מלכודות

- **קריטריון 15 נבדק בטסט:** אין המחרוזת `mini_` + `projects` בשום `.php`/`.js` — גם לא בהערה. אין `../` ב-HTML/JS
  (ה-`@font-face` ב-`index.html` מאותה סיבה). `require` רק `__DIR__ . '/…'`.
- **`sf_audit_clean`** מסנן מפתחות meta שמכילים token/secret/pass/csrf/body/content/note/text/name.
- **`audit_log` חסום לעדכון ומחיקה בטריגר** — בדיקה שמשנה אותו תיכשל; משתמשים ב-`ALTER TABLE … RENAME` כדי לדמות כשל.
- `devloop_req_id()` סטטי לתהליך — בבדיקות כל הקריאות חולקות מזהה.
- PDOException עם קוד `HY000` — DevLoop רושם אותה לבד (השוואת מחרוזת ל-500). `sf_log_exception` מכסה את שאר המקרים.
- פעולה ציבורית חדשה = גם ב-`"public"` של `app.json`, וטסט ב-`c-standalone` משווה את שתי הרשימות.
- `dev/open-items.json`: SF-001…SF-011, אחת לכל פרוסה. לסגור — רק המפתח (✅ ב-DevLoop).

## מה נשאר מפרוסה 01 (אחרי הפריסה)

- מטלה לבעלים (צ'אט המטלות): cron ב-cPanel כל 5 דקות — הפקודה בלשונית ⚙️ רקע.
- `dev/server-report.md` — להעתיק את תוצאות 🩺 בדיקות מהשרת (קריטריון 19). זה הבסיס להחלטות של 03, 08, 09.
- קריטריונים 👁 17–23 — בדיקה ידנית.
