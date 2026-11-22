# מה צ'אט הקוד הבא צריך לדעת

עודכן: 2026-11-22, אחרי מיזוג פרוסה 03 (PR #1775). לפניה — 02 (PR #1769), 01 (PR #1766).

## מה בנוי

פרוסות 01 (תשתית), 02 (מנוע התוכן) ו-03 (ייבוא), כולן. הפירוט — `README.md`; ההכרעות ⚙️ — `team/log/code.md`.

| קובץ | תפקיד |
| --- | --- |
| `api.php` | הנתב היחיד (HTTP דק) → `lib/app.php` `sf_dispatch()`. גוף JSON עד 1MB; פעולה עם `'max'` — עד הערך שלה |
| `lib/access.php` | `SF_ACTIONS` — כל פעולה: שיטה, מי (`public` / `reader` / `staff` / `dev`), `raw`, `body`, `max`. **פעולה חדשה = שורה כאן + `match` ב-`sf_run`** |
| `lib/db.php` | `sf_db`, `sf_q/rows/row/val/exec/insert`, `sf_tx` (BEGIN IMMEDIATE), `sf_meta`, מיגרציות; `sf_ddl_types()` כולל `long` |
| `lib/migrations/NNN-name.php` | 001 תשתית, 002 תוכן. **לא משנים מיגרציה שמוזגה — קובץ חדש** |
| `lib/errors.php` | `sf_fail(code, msg, details)` — `details` יוצא ב-`error.details` (רשימת שגיאות תוכן, מי שינה) |
| `lib/auth.php`, `platform.php` | הזהות מהשער, `staff`, `staff_sessions`, העוגייה `sf_staff` |
| `lib/audit.php` | `sf_audit(action, entity_type, entity_pid, meta)` — בתוך `sf_tx` של השינוי |
| `lib/jobs.php`, `lib/health.php` | תור משימות ובדיקות תקינות (01) |
| `lib/markup.php` | **שפת הסימון**: `SF_MK_TAGS` (רכיבים ותכונות), `SF_MK_STYLES`; `sf_mk_clean(html, ctx)` → html/errors/warnings; `sf_mk_segments`; `sf_norm` |
| `lib/import.php` | ייבוא (03): בדיקת קובץ, zip בטוח (`sf_zip_open/read/resolve`), XML בלי ישויות, הבונה `SfImpDoc`, Word/EPUB/HTML/PDF, תמונות ב-GD, `import-*`, `content-file` |
| `lib/jobs.php` | גם `sf_job_exec` (משימה אחת) ו-`sf_job_run_now(pid)` — הרצה מיידית באותה נעילה של cron |
| `assets/js/screens/import.js` | מסך הייבוא: העלאה (גוף = הקובץ), חילוץ PDF ב-pdf.js, מיפוי, תצוגה מקדימה |
| `assets/vendor/pdfjs/` | pdf.js 3.11.174 (Apache-2.0) — לא עורכים |
| `lib/content.php` | כותרים, מהדורות, גרסאות; `sf_content_save/publish/restore/discard` (כולם עם `base` = `draft_v`); `read-*` |
| `assets/js/render.js` | `SFRender.render(html, settings)` + `bind` + `normalize` — **המנוע של הקורא (04)**. משתמש רק ב-API DOM מצומצם (ראו ראש הקובץ) |
| `assets/js/screens/content.js` | ניהול התוכן והעורך |
| `assets/js/router.js` | `SF.route`, `SF.onLeave(fn)` (ניקוי לפני המסך הבא), `SF.leaveGuard` (שאלה לפני יציאה) |
| `tests/` | `run.php` מריץ כל `*.test.php` בתהליך משלו; `lib.php` (`call`, `ident`, `eq`); `render.mjs` — render.js ב-node עם DOM זעיר; `fixtures/` — snapshots |

## מלכודות

- **קריטריון 15 של 01 נבדק בטסט:** אין המחרוזת `mini_` + `projects` בשום `.php`/`.js`; אין `../` ב-HTML/JS; `require` רק `__DIR__ . '/…'`.
- **PHP ומחרוזות עם ‹›:** `"‹$name›"` — PHP קורא את `›` כחלק משם המשתנה! תמיד `{$name}›`.
- **כלי הכתיבה הופך `\uXXXX` בקוד JS לתו עצמו** (ו-U+2028 שובר regex). אחרי כתיבת regex עם escapes — לבדוק בבתים.
- **`render.js` / `content.js` בלי `innerHTML =`** — טסט ב-`e-render` בודק (גם בהערות של render.js המילה אסורה).
- **snapshot של render.js** (`tests/fixtures/render-*.snap.html`): שינוי מכוון בפלט — `SF_UPDATE_SNAPSHOTS=1 php sforno/tests/run.php`, ולבדוק את ה-diff.
- **`sf_audit_clean`** מסנן מפתחות meta שמכילים token/secret/pass/csrf/body/content/note/text/name.
- **`audit_log` ו-`revisions`** חסומים במסד (טריגר): יומן — לא מתעדכן ולא נמחק; גרסה — לא נמחקת, ותוכן של גרסה שאינה טיוטה לא משתנה.
- **פעולה ציבורית חדשה** = גם ב-`"public"` של `app.json` (טסט משווה). **`reader` (read-*) — בכוונה לא שם** עד ההשקה (11); טסט שומר.
- **בבדיקה ב-Playwright:** `text=נשמר` תופס גם את השבב "לא נשמר" — לחכות לתשובה (`waitForResponse`).
- `customs` — 4 כללי דוגמה (`sample = 1`). 04 בונה את הניהול שלה; תנאים "סותרים" = אותה `grp`.
- **ייבוא:** קבצים רק ב-`data/sources/<pid>/` (`original.*`, `pages.json`, `files/`). תמונה בסימון = `files/<pid>/N.ext`;
  `content-file` מגיש **למנהלים בלבד** — 04 צריך הגשה לקוראים (רק של תוכן שפורסם). `imports.text_checked` — מתי מותר
  להציג PDF כטקסט זורם; `kind = pdf_scan / image` — עמודים בלבד.
- **`import-upload` בבדיקות:** `call(..., ['upload' => $path])` → `$env['upload']`. api.php לא מעביר את זה.
- **libxml:** איך הוא פותר `‹b›‹p›` משתנה בין גרסאות — לא לבדוק מחרוזת מדויקת של פלט כזה (ראו `f-import`, `d-content`).
- `dev/open-items.json`: SF-001…SF-011. לסגור — רק המפתח (✅ ב-DevLoop). ל-SF-002 ו-SF-003 נוספו צעדי בדיקה.

## מה נשאר

- מ-01: cron ב-cPanel (מטלה לבעלים), `dev/server-report.md`, קריטריונים 👁 17–23.
- מ-02: קריטריון 👁 11 אצל הבעלים (SF-002).
- מ-03: קריטריונים 👁 5–6 אצל הבעלים (SF-003); 📦 קובצי ייבוא אמיתיים (§24); 11 ההכרעות ⚙️ ב-`team/log/code.md` — לרישום
  ב-`team/decisions.md` (הבקרה).
- הבאה לפי הלוח: 04 (הקורא על `render.js` ו-`read-*`; הגשת תמונות `files/…` לקוראים; עמודים של PDF סרוק / תמונה).
