# מה צ'אט הקוד הבא צריך לדעת

עודכן: 2026-11-22, עם פרוסה 10 (ניהול תפעולי). לפניה — 09 (#1798), 08 (#1794/#1795), 07 (#1789), 06 (#1786), 05 (#1784/#1785), 04 (#1778),
03 (#1775), 02 (#1769), 01 (#1766).

## מה בנוי

פרוסות 01 (תשתית), 02 (מנוע התוכן), 03 (ייבוא), 04 (הקורא, הסידור והתהילים), 05 (חשבונות קוראים וסנכרון), 06 (הורדות, גרסאות ו-PWA)
07 (לוח עברי, זמני היום והלכה יומית), 08 (תזכורות), 09 (חנות) ו-10 (ניהול תפעולי), כולן. הפירוט — `README.md`; ההכרעות ⚙️ — `team/log/code.md`.

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
| `lib/calendar.php` ↔ `assets/js/calendar-core.js` | **הלוח העברי וזמני היום (07) — אותו אלגוריתם בשניהם.** שינוי באחד = אותו שינוי בשני; k-calendar משווה במילישנייה. `sf_cal_today` / `SFCal.today` — היום העברי לפי `day_end` |
| `lib/daily.php` | השיטות (`zmanim-*`, גרסאות) וההלכה היומית (`halacha-*`, `read-halacha*`); `sf_hl_visible` — כלל התוכן העתידי |
| `assets/js/daily-core.js` | בלי DOM (node): בחירת שיטה, מיקום, חבילת החודש (`zdl`), `homeHalacha`, `tehillimDay` |
| `assets/js/daily.js` | `SF.daily`: `state()` (ערים, מיקום, שיטה, היום), `setLoc/setMethod`, `halacha`, `download`, `onChange` (ל-08), `todayLine` |
| `screens/zmanim.js`, `halacha.js`, `daily-admin.js` | `#/zmanim` (+ `SF.daily.locSheet` / `methodSheet`), `#/halacha`, `#/halacha/archive`, `#/admin/halacha`, `#/admin/zmanim` |
| `lib/reminders.php` ↔ `assets/js/reminders-core.js` | **התזכורות (08) — אותו תכנון בשניהם** (l-reminders משווה במילישנייה): `clean`, `prefs`, השתקה (`muteWindows`), `plan`. בשרת גם: מופעים (`sf_rem_schedule`), שליחה (`sf_rem_send_due`), Web Push (VAPID + aes128gcm), `push-*`, `reminders-*` |
| `assets/js/reminders.js`, `screens/reminders.js` | `SF.rem` (store, Push בדפדפן, אורח ← `reminders-guest-sync`, באנר בתוך האפליקציה, `.ics`); `#/reminders` |
| `assets/data/cities.json` | 160 ערים (GeoNames, CC BY 4.0), במעטפת. עיר חדשה — `id` של GeoNames, אזור זמן IANA (טסט) |
| `lib/store.php` | **החנות (09)**: `data/woo.json`, `SfWooSource` (`SfWooRest` — GET בלבד / `SfWooSample` — `lib/store-sample.json`), סנכרון בריצות (`store_sync_runs`), webhook, מיפוי, `read-store-*`, `store-*`, `dev-store-*` |
| `assets/js/screens/store.js`, `store-admin.js` | `#/store`, `#/store/p/<id>`, `#/store/favs`; `#/admin/store` (המפתחות — רק במסך הזה, למפתח) |
| `lib/ops.php` | **התפעול (10)**: דיווחים (`report-*`), מדדים (`sf_metric_add` — מפתח = מזהה בלבד), פעילות לתמיכה, `ops-dashboard` + `sf_ops_alerts`, `ops.purge`, `ops.rollup` |
| `lib/backup.php`, `tools/backup.php` | **הגיבוי (10)**: `sf_backup_make` (zip ב-`data/backups/`), `sf_backup_prune`, `sf_backup_verify`, `sf_backup_apply` (CLI); `dev-backup-*` |
| `assets/js/screens/ops.js` | `#/admin/ops`, `#/admin/reports`, `#/admin/metrics`, `#/admin/support`, `#/contact`; `SF.reportSheet`, `SF.metricsOpen` |
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
- **סוג חדש של מידע אישי:** להוסיף ל-`SF_SYNC_KINDS` (שרת) ול-`syncable/itemsFor/keyOf/build` ב-`sync-core.js` + בדיקה ב-`sync-core.mjs`
  (כמו `reminders` / `remprefs` של 08). מוצפן? — `SF_SYNC_SEALED`.
- **CSRF בעולם הקוראים** — `sf_same_site` (Sec-Fetch-Site / Origin) וסוג מדיה מדויק. בדיקה עם `rcall` שולחת `same-origin` כברירת מחדל.
- **sync-push:** תקרה 5MB לקורא (`full` → `held` במכשיר) ו-120 שליחות בדקה. מיילים — בלי טקסט חופשי של משתמש.
- **`data/secret.key`** — בלעדיו ההערות והשמות לא מתפענחים. לא למחוק; בגיבוי (10).
- **מחיקה מלאה (10, נבנתה):** `ops.purge` → `sf_reader_purge` — `status = purged`. טבלה חדשה עם `reader_id` / `owner r:` — להוסיף שם.
- **כל שינוי בקובץ מעטפת (JS/CSS/index.html/פונט/אייקון) ← `SF_UPDATE_SW=1 php sforno/tests/run.php`** (מעדכן `BUILD` ו-`VERSION`
  ב-sw.js). בלי זה j-offline נכשל — **והפריסה של כל הריפו נעצרת**. קובץ JS חדש ב-index.html — גם ל-`SHELL` ב-sw.js.
- **הקורא מציג את הגרסה שנבחרה, לא את הנוכחית:** `SF.textFor(ed)` (מההורדה / מהרשת). פריט אישי חדש שקשור למקום בטקסט — עם
  `rev: shown`; תצוגה — `O.visible(list, ed, shown)`. סוג חדש כזה (08?) — להוסיף גם ל-`SF.version.move`.
- **`dl:*`, `catalog:last`, `verseen:*` — setRaw (לא מסתנכרנים)**; `ver:*` — כן (`sync-core.js`).
- **שום דבר לא יורד מעצמו** — טסט סופר את הקריאות ל-`SF.offline.download(`; קריאה חדשה = לעדכן את הטסט (ולהצדיק).
- **בלי רשת `SF.api` זורק `network`** (גם אחרי 20 שניות ב-GET). מסך שתלוי ברשת — להציג הודעה, לא להשאיר "טוען…".
- **זמני היום — מילישניות (UTC).** תצוגה — `SFCal.hhmm(place.tz, ms)`, תמיד באזור הזמן של **המקום**. null = לא ניתן לחישוב —
  לעולם לא להחליף בשעה. "היום" של משתמש — `SF.daily.state()` (לא `new Date()` ולא Intl של המכשיר).
- **מזהי חודש 1–13** (12 אדר א', 13 אדר ב'; פשוטה — 13). תאריך שלא חל השנה — `fixedFromHeb` מחזיר null.
- **המיקום של הקורא לא נשלח לשרת** — רק `pref:loc` בסנכרון (והחריג של 08: אורח עם Push, `reminders-guest-sync`, מוצפן).
  התזכורות של קורא לוקחות אותו מ-`personal_items`. טסט סורק `SF.api(` עם loc/lat, וסופר את החריג (`loc: where`).
- **`SF.daily.download(`** — רק במסך הזמנים ובמסך ההורדות (טסט סופר). **`getCurrentPosition(`** — מקום אחד (`daily.js`), נקרא רק מכפתור.
- **שיטה מאושרת ששונתה חוזרת לטיוטה** (ויוצאת מברירת המחדל). שיטת הבדיקה (`test = 1`) — לא לאשר ולא לחשוף לקוראים.
- **ICU 74 ב-PHP טועה בשנת ה'תתו** — לא לסמוך על intl של השרת כ"אמת"; k-calendar מסמן את זה.
- **דף שעלה בלי רשת — קורא, גם למנהל**, עד טעינה מחדש (06). בבדיקות דפדפן: אחרי `setOffline(false)` — `reload()`.
- `dev/open-items.json`: SF-001…SF-011. לסגור — רק המפתח (✅ ב-DevLoop). ל-SF-002 ו-SF-003 נוספו צעדי בדיקה.

- **תזכורות (08):** שינוי בתכנון / בהשתקה — ב-`reminders.php` **וגם** ב-`reminders-core.js` (הטסט ייכשל אחרת). שום שם / תוכן
  לא נכנס ל-`reminder_occurrences` ול-`reminder_send_log` (טסט L10 סורק). פעולה שמשנה משהו שהתזכורות תלויות בו — לקרוא ל-
  `sf_rem_schedule(owner)` אחריה (owner = `r:<reader id>` / `g:<push device id>`).
- **`sf_job_register(kind, label, fn, every: N)`** — משימה מחזורית (08). שליחה מתוזמנת — **רק** דרך cron, לא מבקשות של דף (§18).
- **Web Push בבדיקות:** `$GLOBALS['sf_push_http'] = fn($url, $headers, $body) => status`; `rcall` שומר גם עוגיית `sf_push`.
  פעולת `guest` עם `'rcsrf' => true` — קורא מחובר חייב X-Reader-CSRF (rcall שולח לבד).
- **`data/vapid.json`** — המפתחות של Push. מחיקה = כל המנויים צריכים להירשם מחדש. לכלול בגיבוי (10), כמו `secret.key`.
- **שיטת ההשתקה** — קבוע (`MUTE` / `SF_REM_MUTE`, `test: true`) עד 📦; כשתגיע — טבלה + אישור כמו שיטות הזמנים, ואותו שינוי בשני הקבצים.

- **חנות (09):** WooCommerce — **רק GET** (`sf_woo_http` זורק על אחר; טסט סורק). מפתח / סוד — לעולם לא בתשובה, לא ב-audit ולא
  ב-last_error (M3 סורק את כל `data/`). מחיר — אגורות. טקסט מהחנות — `sf_store_text` (פסקאות), לא HTML. בבדיקות:
  `$GLOBALS['sf_woo_http'] = fn($method, $url, $headers) => [status, body, headers]`. שינוי מיפוי ← `sf_store_rederive`.
  "מאומת" — `sf_store_fresh` (24 שעות); מוצר ישן בלי `stock`. 10 צריך: `store_stats` (view / out), מצב הסנכרון (`store-admin`).

- **תפעול (10):** מדד חדש — סוג ב-`SF_METRIC_KINDS`, מפתח **רק מזהה** (טסט N7 סורק את כל הטבלה). דיווח — שדה חדש = עמודה + רשימה
  סגורה ב-`sf_report_create` + הטסט שסורק את הקריאה ב-ops.js. **אסור** ש-`ops.php` / `backup.php` יכילו `personal_items` (K10) —
  דרך `readers.php`. קובץ נתונים חדש ב-`data/` — להוסיף ל-`SF_BACKUP_FILES` (אחרת הוא לא בגיבוי). פעולה שמחזירה `ok` — הנתב דורס.
- **שחזור (10):** `data/maintenance.json` = 503 לכל פעולה ו-cron לא רץ. ההורדה — **רק מוצפנת** (POST עם סיסמה, `.sfbk`);
  `tools/backup.php import` / `decrypt`. קישור במייל מ-cron — `sf_self_url` (הכתובת נשמרת מבקשות מנהל: `meta.public_url`).
- **CLI על data/ אמיתי:** `tools/backup.php` בלי `SF_DATA_DIR` עובד על `sforno/data/` — בבדיקות תמיד עם `SF_DATA_DIR=`.

## מה נשאר

- מ-01: cron ב-cPanel (מטלה לבעלים), `dev/server-report.md`, קריטריונים 👁 17–23.
- מ-02: קריטריון 👁 11 אצל הבעלים (SF-002).
- מ-03: קריטריונים 👁 5–6 אצל הבעלים (SF-003); 📦 קובצי ייבוא אמיתיים (§24); 11 ההכרעות ⚙️ ב-`team/log/code.md` — לרישום
  ב-`team/decisions.md` (הבקרה).
- מ-04: 👁 1, 4, 8, 11 אצל הבעלים (SF-004).
- מ-05: 📦 מזהה לקוח של Google (מטלה לבעלים) → 🔑 Google במסך המפתח; בדיקת מייל אמיתי (SF-005); 15 ההכרעות ⚙️ ב-
  `team/log/code.md` — לרישום ב-`team/decisions.md` (הבקרה). המחיקה המלאה אחרי 30 יום — נבנתה ב-10.
- מ-06: 👁 1, 6, 8, 9 בטלפון אמיתי (SF-006 — הצעדים שם); 12 ההכרעות ⚙️ ב-`team/log/code.md` — לרישום ב-`team/decisions.md` (הבקרה).
  הורדת הזמנים — נבנתה ב-07 (חבילה נפרדת `zdl`, לא דרך `read-package`).
- מ-07: 👁 8, 9 בטלפון אמיתי (SF-007); 📦 שיטות, פרמטרים וברירת מחדל מהבעלים (§24) — עד אז קוראים לא רואים שעות; גובה המקום
  בחישוב — אם השיטות ידרשו; 17 ההכרעות ⚙️ ב-`team/log/code.md` — לרישום ב-`team/decisions.md` (הבקרה).
- מ-08: 👁 1, 2, 9 במכשירים אמיתיים — `dev/push-report.md` (SF-008, הצעדים שם); **cron כל דקה** (מטלה לבעלים; בלעדיו Push
  מתוזמן ומייל לא נשלחים); 📦 שיטת השתקה לשבת וחג; 15 ההכרעות ⚙️ ב-`team/log/code.md` — לרישום ב-`team/decisions.md` (הבקרה).
- מ-09: 📦 הגישה לחנות (§24) — כתובת, גרסה, מפתח Read, ואיפה מחבר / הוצאה / מהדורה / כריכה / תוכן עניינים / דפי דוגמה; ואז
  👁 8 מול החנות האמיתית (SF-009); 18 ההכרעות ⚙️ ב-`team/log/code.md` — לרישום ב-`team/decisions.md` (הבקרה).
- מ-10: 👁 5 — שחזור ידני לפי `dev/restore.md` בסביבת הבדיקה (SF-010); 📦 מדיניות גיבוי, מייל ווואטסאפ לתמיכה, תקופות שימור;
  **מטלה חודשית לבעלים** — להוריד את הגיבוי האחרון (💾) ולשמור מחוץ לשרת; 16 ההכרעות ⚙️ ב-`team/log/code.md` — לרישום ב-`team/decisions.md` (הבקרה).
- מ-11 (חלק א, `claude/sforno-11-launch`): המצב המנותק בנוי ומכובה (`data/launch.json`). 👁 1, 4, 5 — אחרי "עוברים".

## אחרי "עוברים" — מה שדורש פלטפורמה או הכרעת בעלים (11 §2)

1. **`app.json`:** לצאת מהשער (`"gate": false` / רישום כאפליקציה חיצונית כמו `kadisha/`); `"sso": {"return": ["https://sforno.mbe-plus.com/"]}`
   (כתובת הכניסה — `app.js` קולט את `?token=&state=`); `"grant_status": {"key_url": "https://sforno.mbe-plus.com/api.php?action=platform-key"}`.
   **שם הפעולה `platform-key`** (מקף, כמו כל פעולות ספורנו). c-standalone בודק את app.json — לעדכן יחד.
2. **`data/launch.json` בסאב-דומיין:** `{"detached": true, "platform": "https://<הכלים הדיגיטליים>/", "sso_key": "<sso.php?action=key>",
   "return": "<כמו ב-sso.return>"}`. לפני המעבר אפשר `"detached": false` כדי ש-`platform-key` יענה והפלטפורמה תקרא את המפתח.
3. **פריסה לסאב-דומיין** + העתקת `data/` (הכרעת בעלים; חוק "ענף פורס אחד").
4. **הכניסות של הקוראים לפי `config.login`** (11 §1.2) — לא נבנה; לאפיין אם `login.password=false` אמור לחסום גם קוראים (05 הוא עולם נפרד).
5. **פעולות הקורא** (`reader` / `guest` / `account`) — בסאב-דומיין בלי שער הן פתוחות לכולם ממילא; בתוך mini_projects נשארות מאחורי השער.
