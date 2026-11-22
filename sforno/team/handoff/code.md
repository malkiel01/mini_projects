# מה צ'אט הקוד הבא צריך לדעת

עודכן: 2026-11-22, אחרי מיזוג פרוסה 04 (PR #1780). לפניה — 03 (PR #1775), 02 (PR #1769), 01 (PR #1766).

## מה בנוי

פרוסות 01 (תשתית), 02 (מנוע התוכן), 03 (ייבוא) ו-04 (הקורא, הסידור והתהילים), כולן. הפירוט — `README.md`; ההכרעות ⚙️ — `team/log/code.md`.

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
| `lib/reader.php` | (04) `read-catalog/toc/revision/search/file/pages` (רמת `reader`); מנהגים, `siddur_items`, `custom_checks`, `content-pages-set`, `content-sample`. `sf_read_text_ok` — PDF שלא נבדק לא מוגש כטקסט |
| `lib/migrations/004-reader.php` | `siddur_items`, `custom_checks` (רק מתווספת), `editions.pages_import_id` |
| `assets/js/reader-core.js` | לוגיקה טהורה של הקורא (נבדקת ב-node): `mergeOrder`, `conds` (קבוע/זמני), `pickTop`, `shareLink`, `copyText`, `wordHit`, תהילים, `wakeLock` |
| `assets/js/store.js` | `SF.store` — IndexedDB במכשיר, רשומה `{key, value, at}`. **05 מוסיף כאן `rev` וסנכרון.** המפתחות — בראש הקובץ |
| `assets/js/screens/reader.js` | הקורא `#/r/<ed>[/<sid>]`; `SF.reader` חשוף לבדיקות בדפדפן; `SF.sheet`, `SF.loadPdfjs`, `SF.catalog` |
| `assets/js/screens/siddur.js` | `#/siddur`, `#/tehillim`, `#/search`; `SF.localNotice` |
| `assets/js/screens/reader-admin.js` | `#/admin/customs`, `#/admin/siddur`, `#/admin/checks` |
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
- **04 — המקום:** `sid` + `off` + `mode` + `rev` ב-`pos:<edition>`. כניסה עם sid → הכתובת מוחלפת ל-`#/r/<ed>` (רענון חוזר למקום).
  ריווח בסוף הטקסט (`100dvh − 140px`) — בלעדיו בלוק קצר לא מגיע לראש והמקום "זז".
- **04 — `render.js`:** `condCollapsed`/`condNames` (תנאי שלא נבחר → כפתור `.sf-cond-btn`), `strip` + `stripMap` (הסתרת ניקוד
  ומיפוי בחזרה למקור להעתקה), `bind` עם `onJump`. snapshots: `reader-collapsed`, `reader-strip`.
- **04 — חיפוש:** טוקן זהה או עם 1–3 תחיליות `ובכלמשה`, בלי סיומות; JS `wordHit` = PHP `sf_search_word_hit` (טסט). מקרה חדש
  במילון → מקרה ב-`tests/search-cases.json`.
- **04 — בדיקה בדפדפן:** `NODE_PATH=/opt/node-tools/node_modules node sforno/tests/browser/reader.cjs` (Playwright, מרים `php -S`
  עם מסד זמני). לא ב-run.php. מסך שמשתמש בה — לעדכן גם אותה.
- **`SF.put(el, …)`** (api.js) — `replaceChildren` בלי null; `replaceChildren` עם null כותב "null" למסך.
- `dev/open-items.json`: SF-001…SF-011. לסגור — רק המפתח (✅ ב-DevLoop). ל-SF-002 ו-SF-003 נוספו צעדי בדיקה.

## מה נשאר

- מ-01: cron ב-cPanel (מטלה לבעלים), `dev/server-report.md`, קריטריונים 👁 17–23.
- מ-02: קריטריון 👁 11 אצל הבעלים (SF-002). מ-03: 👁 5–6 (SF-003); 📦 קובצי ייבוא אמיתיים.
- מ-04: 👁 1, 4, 8, 11 אצל הבעלים (SF-004); 📦 סידור ותהילים מוגהים, רשימת מנהגים (עד אז — "🧪 תוכן דוגמה"); ⚪ צופה העמודים
  בלי צביטה / מסך מלא; "של היום" בתהילים — לפי חצות עד 07.
- הבאה לפי הלוח: 05 (חשבונות קוראים וסנכרון — על `store.js`: להוסיף `rev` ושרת לכל המפתחות; `pos`, `marks`, `favorites`,
  `order:*`, `customs`, `settings`, `tehillim` — **`tehillim.names` לא יוצאים מהמכשיר בלי הכרעה**, §7/§19).
