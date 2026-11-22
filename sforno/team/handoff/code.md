# מה צ'אט הקוד הבא צריך לדעת

עודכן: 2026-11-22, עם פרוסה 06 (הורדות, גרסאות ו-PWA). לפניה — 05 (#1784/#1785), 04 (#1778), 03 (#1775), 02 (#1769), 01 (#1766).

## מה בנוי

פרוסות 01 (תשתית), 02 (מנוע התוכן), 03 (ייבוא), 04 (הקורא, הסידור והתהילים), 05 (חשבונות קוראים וסנכרון) ו-06 (הורדות, גרסאות ו-PWA), כולן. הפירוט — `README.md`; ההכרעות ⚙️ — `team/log/code.md`.

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
| `lib/reader.php`, `screens/reader.js`, `siddur.js`, `reader-core.js` | הקורא (04): `read-*`, הקורא, הסידור, התהילים, החיפוש |
| `assets/js/store.js` | המידע האישי במכשיר (IndexedDB `kv`). `set/del` → גם `SF.sync.note`; `setRaw/delRaw` — בלי סנכרון; `keys()` |
| `lib/readers.php` | **חשבונות הקוראים (05)**: עוגייה `sf_reader`, `sf_reader_auth`, נעילה (`login_attempts`), מיילים, Google (JWKS → PEM), `sf_seal/unseal` (`data/secret.key`) |
| `lib/sync.php` | `sync-push` / `sync-pull`: `rev` לקורא, tombstone, `same` / `kept` / `gone` / `bad`; note + prayer_name מוצפנים |
| `assets/js/sync-core.js` | הלוגיקה של הסנכרון במכשיר (בלי DOM, נבדקת ב-node): רשומה ↔ פריטים, התור, מיזוג אורח |
| `assets/js/sync.js`, `screens/account.js` | התור והתזמון, החיווי בכותרת (`#sync-chip`); האזור האישי `#/account` |
| `sw.js`, `manifest.webmanifest` | **PWA (06)**: מעטפת במטמון `sforno-shell-<VERSION>-<BUILD>`; בלי skipWaiting — פס "עדכון זמין". `SHELL` = כל מה ש-index.html טוען (טסט) |
| `assets/js/offline-core.js` | **ההורדות והגרסאות (06), בלי DOM** (נבדק ב-node): `download` (hash לכל קובץ), `load`, `gc`, `remove`, `pinned`, `migrate`, `unmatched`, `visible` |
| `assets/js/offline.js` | הדפדפן: `SF.offline` (Cache API `sforno-content`), `SF.version` (`ver:<ed>` = `{rev, prev}`, `move`), רישום ה-SW ופס העדכון, `#net-chip` |
| `assets/js/screens/downloads.js` | `#/downloads` ו-`SF.dlLine(ed)` — שורת "בלי אינטרנט" (סידור, תהילים, כלי הקורא) |
| `lib/reader.php` `sf_read_package` | `read-package`: רשימה + sha256 (טקסט, תמונות, עמודים) — אותם תנאים כמו `read-revision` |
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
- **שני עולמות:** פעולה חדשה של קורא — רמה `guest` / `account` (לא `staff`), והיא מקבלת את הקורא מ-`$env['_reader']`.
  **כל שאילתה על מידע אישי — עם `reader_id = ?`** (טסט ב-i-sync סורק את sync.php). פריט / מכשיר של אחר — `not_found`.
- **בבדיקות של קורא:** `rcall($m, $action, $in, '<מכשיר>', $get, $opt)` — צנצנת `sf_reader` לכל "מכשיר"; ה-CSRF מ-account-me.
  מיילים — `$GLOBALS['sf_mail_out'] = []` לפני; הקישור בגוף. **`$x['k'] ?? 'x'` על ערך null נותן 'x'** — `array_key_exists`.
- **סוג חדש של מידע אישי (08 תזכורות):** `reminder` כבר ב-`SF_SYNC_KINDS`; במכשיר — להוסיף ל-`syncable/itemsFor/keyOf/build`
  ב-`sync-core.js` ובדיקה ב-`sync-core.mjs`. מוצפן? — `SF_SYNC_SEALED`.
- **CSRF בעולם הקוראים** — `sf_same_site` (Sec-Fetch-Site / Origin) וסוג מדיה מדויק. בדיקה עם `rcall` שולחת `same-origin` כברירת מחדל.
- **sync-push:** תקרה 5MB לקורא (`full` → `held` במכשיר) ו-120 שליחות בדקה. מיילים — בלי טקסט חופשי של משתמש.
- **`data/secret.key`** — בלעדיו ההערות והשמות לא מתפענחים. לא למחוק, לכלול בגיבוי (10).
- **מחיקה מלאה (10):** `readers.status = 'deleted'` + `deleted_at` — אחרי 30 יום למחוק `personal_items`, `devices`,
  `reader_tokens`, ולהחליף את המייל (UNIQUE) — אחרת אי אפשר להירשם שוב באותו מייל.
- **כל שינוי בקובץ מעטפת (JS/CSS/index.html/פונט/אייקון) ← `SF_UPDATE_SW=1 php sforno/tests/run.php`** (מעדכן `BUILD` ו-`VERSION`
  ב-sw.js). בלי זה j-offline נכשל — **והפריסה של כל הריפו נעצרת**. קובץ JS חדש ב-index.html — גם ל-`SHELL` ב-sw.js.
- **הקורא מציג את הגרסה שנבחרה, לא את הנוכחית:** `SF.textFor(ed)` (מההורדה / מהרשת). פריט אישי חדש שקשור למקום בטקסט — עם
  `rev: shown`; תצוגה — `O.visible(list, ed, shown)`. סוג חדש כזה (08?) — להוסיף גם ל-`SF.version.move`.
- **`dl:*`, `catalog:last`, `verseen:*` — setRaw (לא מסתנכרנים)**; `ver:*` — כן (`sync-core.js`).
- **שום דבר לא יורד מעצמו** — טסט סופר את הקריאות ל-`SF.offline.download(`; קריאה חדשה = לעדכן את הטסט (ולהצדיק).
- **בלי רשת `SF.api` זורק `network`** (גם אחרי 20 שניות ב-GET). מסך שתלוי ברשת — להציג הודעה, לא להשאיר "טוען…".
- `dev/open-items.json`: SF-001…SF-011. לסגור — רק המפתח (✅ ב-DevLoop). ל-SF-002 ו-SF-003 נוספו צעדי בדיקה.

## מה נשאר

- מ-01: cron ב-cPanel (מטלה לבעלים), `dev/server-report.md`, קריטריונים 👁 17–23.
- מ-02: קריטריון 👁 11 אצל הבעלים (SF-002).
- מ-03: קריטריונים 👁 5–6 אצל הבעלים (SF-003); 📦 קובצי ייבוא אמיתיים (§24); 11 ההכרעות ⚙️ ב-`team/log/code.md` — לרישום
  ב-`team/decisions.md` (הבקרה).
- מ-04: 👁 1, 4, 8, 11 אצל הבעלים (SF-004).
- מ-05: 📦 מזהה לקוח של Google (מטלה לבעלים) → 🔑 Google במסך המפתח; בדיקת מייל אמיתי (SF-005); 15 ההכרעות ⚙️ ב-
  `team/log/code.md` — לרישום ב-`team/decisions.md` (הבקרה). המחיקה המלאה אחרי 30 יום — 10.
- מ-06: 👁 1, 6, 8, 9 בטלפון אמיתי (SF-006 — הצעדים שם); הורדת זמנים — כש-07 ייבנה (להוסיף ל-`read-package` או חבילה נפרדת);
  12 ההכרעות ⚙️ ב-`team/log/code.md` — לרישום ב-`team/decisions.md` (הבקרה).
- הבאה לפי הלוח: 07 (לוח שנה, זמנים והלכה). הזמנים צריכים לעבוד בלי רשת — מסך ההורדות (`SF.dlLine` / `downloads.js`) הוא המקום.
