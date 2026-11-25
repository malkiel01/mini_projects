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

## אחרי פרוסה 01 (תשתית) — 2026-11-24, PR ‏#1839 (מוזג)

- **המבנה:** `lib.php` (ליבה, `VERSION`, `store()` / `existing()` / `normalize($v, $orgId)`), ותחת `lib/`: `schema.php` (מיגרציות), `access.php` (`resolveAccess`, `can`, `scope`, `need`, `ctx`, `PERMS`), `events.php` (`recordEvent`, `caseDiff`, `logRequest`), `secrets.php` (`secretPut` / `secretGet`), `devtasks.php` (חיבור DevLoop). הממשק ב-`assets/js/app.js`; `index.html` הוא HTML בלבד.
- **מיגרציה חדשה:** `migrateVn()` ב-`lib/schema.php` + העלאת `SCHEMA_VERSION`, ובתוך `migrate()` שורה `if ($from < n) migrateVn($pdo);`. הגיבוי ל-`data/backup/` קורה לבד לפני כל קפיצה. מוסיפים בלבד; `CREATE … IF NOT EXISTS` ו-`hasColumn()` לעמודות, כדי שמיגרציה תהיה אידמפוטנטית.
- **התאמה ל-03 ול-05 (נבנו במקביל):** הן מביאות v2 / v3 (חיבורי Dropbox; טבלת `tokens`). מי שממוזג שני מיישר את המספור מול `main` — מספר רץ, בלי לדלג ובלי שתי מיגרציות באותו מספר — ומוסיף בדיקה ב-`run.sh` שעוברת ממסד v1 לגרסה החדשה בלי לשנות תיק. 05 מסננת חברים לפי `members.active=1` ו-`users.active=1`.
- **הרשאות:** הרשאה חדשה = שורה ב-`PERMS`; כל פעולה ב-`api.php` קוראת ל-`need()`. תיק של ארגון אחר → 404 (`existing`). עובד שטח: רק `TRACK_FIELDS` (checks, notes, grave), בהשוואה מחמירה מול `normalize($old)`.
- **פעיל:** `members.active` — השבתה לארגון (מנהל); `users.active` — חסימה גלובלית (מפתח, `user_active`). חבר פעיל = שניהם 1. מפתח (`platform_admin`) לא נחסם.
- **זהות:** קישור לפי מייל ומימוש הזמנה רק עם `email_verified === true`. הזמנה תמיד ממתינה עד הכניסה הבאה.
- **מקורות ב-events:** `ui | import | api | system` (`EVENT_SOURCES`). מקור חדש (dropbox, gpt, ai) — להוסיף שם.
- **ציבורי:** `ingest.php` ו-`api.php?action=devapi-*`. `tests/run.sh` נועל את רשימת `public` — כתובת חדשה מעדכנים גם שם.
- **סודות:** `secret.key` נוצר בשימוש הראשון; בלי sodium נופלת רק פעולת סוד.
- **בדיקות:** `tests/run.sh` מתחיל ממסד v0 עם 22 תיקים (`tests/fixture-v0.php`), כ-15 שניות. CI: `.github/workflows/funerals-dashboard-ci.yml` (`coverage: none`, sodium) — הבדיקה נכשלת על כל אזהרת PHP בלוג השרת.
- **פתוח:** A15/A16 ידניים בשרת (מטלה חדשה תקבל FD-008 — מפת הדרכים תופסת FD-001…007). שורת `.devloop.json` — אצל הבקרה. **בטלפון הכותרת של המפתח צפופה** (📥 📤 ⚙️ 🛠️ 🐞 👤 — שישה כפתורים, והשם נחתך) — להעביר את 📥/📤 לתפריט 👤 או לסרגל שמתחת לכותרת.

## אחרי פרוסה 02 (טופס חכם) — 2026-11-24, PR ‏#1842

- **הכלל בפונקציה אחת:** `finalAllowed($via)` ב-`lib/form.php`. **כל כתיבה של תיק בודד עוברת ב-`formSaveCase($pdo, $raw, $via, $actor, $orgId, $authorize)`** —
  היא מחילה `by`/`via`/`at`, את "הושלם רק באדם" ואת `manual_fields`, וקוראת ל-`store()` (שרושם ב-`events`). 03/04/05/06 — לא לממש מחדש.
- `via` ∈ `ui` · `field` · `dropbox` · `api` · `ai` · `import`. ממופה למקור של `events` ב-`formEventSource()` (field→ui, ai→api, dropbox→system).
- מקור אוטומטי **לא פותח מחדש** בדיקה שאדם סגר; ההצעה נכנסת להערה (הכרעה של 02, ב-PR).
- הממשק: הלוגיקה (מצב תיק, "הבא בתור", סינונים, מקור) ב-`assets/js/form.js` — טהורה, נבדקת ב-node (`tests/form.test.js`). `app.js` רק מצייר.
- היסטוריה: `action=history&id=…` → `formHistory()`; `formDiffChanges()` מסנן רעש של `caseDiff()` (402 מול 402.0 — ממצא שעוד פתוח אצל 01).
- בדיקות: `tests/form.test.php` (בלי `data/`), `tests/form.test.js`, וקטע "פרוסה 02" ב-`run.sh`. `tests/fixtures/legacy-cases.json` — 22 תיקים בצורות ישנות.
- **פתוח:**
  - B8 ידני בטלפון (מטלה לבעלים).
  - **רעש ב-`events`:** `caseDiff()` (`lib/events.php`) רושם `payments` כשינוי בכל save, כי סכום שחוזר מ-JSON כ-402.0 מול 402 נכשל ב-`!==`.
    התיקון: השוואה רופפת לשדות מספריים (כמו `caseChangeNeeds`). ההיסטוריה של 02 מסננת את זה בתצוגה בלבד — ב-`events` זה נשאר.
  - 05 מימש fallback משלו ל-`finalAllowed` — הבקרה העבירה לו לעבור דרך `formSaveCase()` (ראה `team/log/code.md`).
- **הכרעות 02 (אושרו בבקרה):** אוטומטי לא פותח מחדש בדיקה שאדם סגר; מיפוי `via` → מקור `events`; בדיקה בלי מקור ועם תוכן = "מייבוא ישן".

## אחרי מיזוג 05 (#1840, d7f0063)

**05 מוזגה.** פתוח אחרי המיזוג:
1. **E11** (ידני, הבעלים): GPT מותאם עם הפרומפט, קובץ ה-API והאסימון — "תוסיף ל<שם>: הערה בדיקה". במטלות.
   כאן ייבדק גם ש-`ext.php/<action>` (PATH_INFO) עובד בשרת; אם לא — `ext/<action>.php` או rewrite.
2. **אחרי מיזוג 03:** `tests/ext-dbx.php` ידלג (03 קיימת). לבדוק את `files`/`file_text` מול ה-Dropbox המדומה של 03
   (`tests/dropbox-mock.php`, `DBX_API_BASE=http://127.0.0.1:PORT`, כמו ב-`tests/dropbox-test.php`).
3. **אחרי מיזוג 03:** להעביר את `tokens` / `ext_fail` מ-`extEnsureSchema()` למיגרציה ממוספרת (migrateV3), אחרי v2 של 03.
4. **06:** קורא ל-`extDo(..., 'ai')`. פונקציות הליבה עונות ב-`reply()` ולא זורקות — אם 06 צריך, זה שינוי של 01.

## פרוסה 03 — Dropbox דו-כיווני (PR #1844, מוזג — f3064a0)

**מצב:** C1–C11, C13 אוטומטיים ב-`tests/dropbox-test.php` מול Dropbox מדומה (`tests/dropbox-mock.php`), נקרא בסוף `run.sh`.
**C12 ידני בשרת** — אחרי המטלה לבעלים (App Console + App key/secret ב-⚙️ ← Dropbox). פירוט מלא: `dropbox/README.md`.

- **קבצים:** `dropbox/lib.php` (לקוח, סנכרון, תיקיות), `dropbox/actions.php` (פעולות api.php + הממשק היציב), `dropbox/compat.php`
  (נקודת החיבור היחידה ל-01: secrets, recordEvent, ctx/can/scope), `dropbox-webhook.php` (ציבורי), `bin/dropbox-sync.php` (cron, CLI בלבד).
- **מסד:** `migrateV2` (SCHEMA_VERSION=2): `dbx_orgs`, `dbx_folders`, `case_folders`, `files` (UNIQUE(org_id, dbx_id), `upload_id`),
  `dbx_oauth`. מיגרציה הבאה — v3.
- **OAuth:** ה-callback מטופל ב-`api.php` **לפני** `session_start` (עוגיית fd_session היא Strict ולא חוזרת מ-dropbox.com); state בטבלה.
- **הרשאות:** `dropbox.manage` (מנהל), `files.upload` (משרד, מנהל) ב-PERMS. לשטח (04) — להוסיף `field` ל-`files.upload` או הרשאה משלה.
- **ממשק לאחרים** (`dropbox/actions.php`): `dropboxUploadToCase(…, $via, ?$uploadId)`, `dropboxCaseFiles($pdo, $case)`,
  `dropboxFileContent($pdo, $orgId, int $fileId, $maxBytes)`, `dropboxFileText(…)` (txt/csv/md/json בלבד). שגיאות — `DbxError->tag`.
  ארגון שמעולם לא חובר → `not_connected`. החתימות נשלחו ל-04, 05, 06.
- **SQLite:** שליפת שורה אחת — רק דרך `dbxRow()` (סוגר את הסמן). סמן פתוח בזמן קריאה ל-Dropbox גרם ל-"database is locked".
- **פתוח:** מגבלת ההעלאה של PHP בשרת (upload_max_filesize) — לא ב-`.user.ini` של האפליקציה (build-gate עוצר פריסה); מוצגת
  בבדיקות התקינות. ההחלטה אצל הבקרה/הבעלים. שורת ה-CRONS — הבקרה. הבדיקה של 05 (`tests/ext-dbx.php`) מדלגת עכשיו — 05 רשמה להריץ מול המדומה.
- **בדיקות:** שרתי `php -S` עם `-d opcache.jit=disable`, וסינון `in Unknown on line 0`. השער המדומה — זהות אחת לשרת; בקשות
  מקבילות רק בשני sessions של אותו משתמש (PHP נועל session).

## פרוסה 04 — עובד שטח בטלפון (PR #1853, מוזג — 2f3786d, פריסה 1879 success)

- **הקבצים:** `lib/field.php` (`field_today/search/case/save/upload` — החיבור ב-`api.php` שתי שורות), `assets/js/field-core.js` (בלי DOM:
  הקטנה, GPS, התור, `idbStore`, `sendItem`, `backgroundSync` — נבדק ב-node), `assets/js/field.js` (המסך), `assets/css/field.css`, `sw.js`.
  החיבור ל-`app.js` — האירוע `fd:me` בלבד (נורה גם בהחלפת ארגון — התור נבנה מחדש לפיו).
- **הכניסה:** ברירת מחדל לתפקיד `field`; לכל אחד אחר `index.html#/field` (ותיק: `#/field/<id>`).
- **עדכון מהשטח:** `fieldSave()` — רק `TRACK_FIELDS`, שדה אחר בקלט → 403; נשמר ב-`formSaveCase(..., 'field', ...)`.
- **העלאה:** `fieldUpload()` — מזהה חד-פעמי ב-`field_uploads` (**מיגרציה v3**; v4 — 06; הבאה — v5), ואותו `upload_id` גם ל-`dropboxUploadToCase()` של 03.
  אחרי ההעלאה התיק **נקרא מחדש** בתוך `BEGIN IMMEDIATE` ומשתנה רק `checks[<הבדיקה של הסוג>]` (`FIELD_KINDS`). Dropbox לא מוכן → 409
  `dropbox_not_connected` ("Dropbox של הארגון לא מחובר — פנה למנהל"), והקובץ נשאר בתור. סוג הקובץ — `dbxAllowedUpload()` של 03; צילום קבר — תמונה בלבד.
- **התור במכשיר:** IndexedDB `fd_field` (`queue`, `meta`). `sw.js` — **רק** Background Sync (בלי fetch/מטמון, `run.sh` בודק), ב-`"public"`.
  `navigator.locks` בין לשוניות ל-SW. iOS — נשלח בפתיחה. פריט שייך למשתמש ולארגון; של משתמש אחר יורד אחרי שבוע.
- **בדיקות:** `tests/field.test.php` (מדומה), `tests/field-dropbox.test.php` (מול המדומה של 03), `tests/field.test.js`, ו-W1/W5/W7 ב-`run.sh`.
  בדיקת דפדפן ל-SW נעשתה ידנית (Playwright + CDP `ServiceWorker.dispatchSyncEvent`) — לא ב-CI.
- **מלכודת ב-`run.sh`:** `kill "$pid"` כש-`pid=0` שולח SIGTERM לכל הקבוצה — רק `if [ "$pid" != 0 ]`.
- **פתוח:** W8 (מצב טיסה בטלפון) — מטלה לבעלים. `fieldSearch` טוען את כל תיקי הארגון (בסדר ל-22; לשנות אם יגדל). אין `manifest.webmanifest`.
## אחרי פרוסה 06 (AI מובנה) — 2026-11-24, PR ‏#1848 (7192b8b) + ‏#1857 (5c7a910)

- **איפה:** `ai/lib.php` (הגדרות, תקרות, הספק, כלים, לולאה, הצעות, קבצים), `ai/actions.php` (פעולות `ai_*`; החיבור ב-`api.php` — שתי שורות),
  `assets/js/ai.js` + `assets/css/ai.css`. הטבלאות — **מיגרציה v4** ב-`lib/schema.php` (`ai_settings`, `ai_usage`, `ai_threads`, `ai_proposals`,
  `ai_file_kinds`). המיגרציה הבאה — v5.
- **הכלים = `extDo()` של 05**, בהקשר של המשתמש המחובר, `via: 'ai'`. שני פרמטרים ש-06 הוסיף ל-`extDo`: `$dry` (כתיבה מלאה ואז ROLLBACK —
  לכרטיס ההצעה) ו-`$guard` (נקרא בתוך הטרנזקציה, לפני `formSaveCase`). פעולה חדשה ב-`extActions()` נהיית כלי של העוזר לבד.
- **`reply()` במצב לכידה** (`$GLOBALS['fd_reply_capture']` → `FdReply`) — כך 403/404 של פונקציות הליבה חוזרים לספק כ-tool_result.
  קוד שקורא לפעולה מתוך פעולה — דרך `aiExt()`, ולא ישירות.
- **שום כתיבה בלי ✓.** הצעה שומרת hash של השדות/הבדיקות שהיא נוגעת בהם (`base`); ✓ אחרי שאדם שינה אותם → 409 `stale`.
- **הספק:** HTTP ישיר (אין composer בשרת). `fallbacks: "default"`, `cache_control`, ו-`prefix_mismatch_behavior: "drop_block"` (בטא
  `thinking-binding-controls-2026-08-01`) — כי קבצים מוחלפים בסימון בהיסטוריה אחרי כל סבב. **לא לערוך היסטוריה ולא את `system` של שיחה**
  בלי לשמור על זה: הודעת המערכת נקבעת פעם אחת לשיחה (`ai_threads.system`).
- **בדיקות:** `tests/ai.sh` (ספק מדומה `tests/ai-mock.php` + Dropbox המדומה של 03, `tests/ai-dbx-fixture.php`). F1–F7, §2.2.2, §2.3, R1–R3.
  כל `php -S` — עם `-d opcache.jit=disable`; הניקוי של `data/` שומר `.htaccess` ו-`.gitignore`; אחרי בלוק שמאפס `pid=0` — לא `kill "$pid"` (= `kill 0`).
- **פתוח:** F8 — ידני אחרי פריסה ומפתח אמיתי ("מה חסר ב<שם>?", "תמלא מהקבצים").

## פרוסה 08 — תיקונים + פרויקט ChatGPT (ענף `claude/funerals-dashboard-08-fixes`)

- **ייבוא = המסלול של ChatGPT עכשיו.** 📥 הוא חלון הדבקה (`#importDialog`), לא בוחר קבצים. הפענוח ב-`FDForm.parseImport()` (form.js, נבדק ב-node).
  `index.html#import` / `#ai` פותחים את 📥 / 💬 (`route()` ב-app.js) — מסך 🤖 מקשר לשם.
- **תיק דומה:** `importSimilar()` ב-`lib.php` רץ לפני הטרנזקציה. רק לרשומה **בלי id** שהמזהה המחושב שלה לא קיים. 409 `{code:'similar', similar:[{index,
  name, date, match:{id,name,date}}]}`; השליחה החוזרת עם `resolve`. "לעדכן" = `id` ו**שם** של התיק הקיים. ingest.php → `update` אוטומטי.
  פרוסה 09 (MCP) — אם תכתוב דרך `importRecords`, להחליט אם היא "ui" (שואלת) או כמו ingest.
- **ההנחיות לפרויקט:** `extProjectPrompt()` + `EXT_PROJECT_STATUSES` ב-`ext-lib.php`. שינוי בכללי `importRecords()` (מה דורס מה) — לעדכן גם שם,
  ולהריץ `php funerals-dashboard/tools/build-ext.php`. `fixes08.test.php` נועל שמפתחות `EXT_CHECKS` = `labels` של form.js.
- **הכותרת:** כפתור חדש בכותרת שאינו חיוני בטלפון — `data-more`, והוא עובר ל-⋯ לבד (התפריט נבנה מהכפתורים הגלויים בזמן הלחיצה).
  ב-≤420px נשארים 💬 ⋯ 🐞 👤, בלי רווח ביניהם, וה-h1 ב-.95rem — כך השם נכנס שלם ב-390px. 🐞 של DevLoop לא שלנו — לא הועבר.
- `caseDiff()` — `diffSame()`: מספר מול מספר בהשוואה מספרית, השאר מחמיר ("402" מול 402 עדיין שינוי). הסינון ב-`formDiffChanges()` נשאר (לא מזיק).

## אחרי פרוסה 09 — MCP + OAuth (PR #1867, מוזג 5016c65)

**מה נבנה ואיפה:** `mcp.php` (JSON-RPC, Streamable HTTP בלי SSE), `mcp-lib.php` (כל הלוגיקה: טבלאות `oauth_*`, DCR, CIMD, קודים,
אסימונים, הכלים), `oauth/` (metadata, register, authorize — **מאחורי השער**, token, revoke), `.htaccess` (`/mcp`, `.well-known/*`),
`tokens.php` (`mcp_url`, `connections`, `disconnect`), `connect.html` (🔌 ראשון). הכלים = `extActions()` / `extDo(..., 'api')` — לא לשכפל.
השם ביומן "<שם> (<client_name>)" — דרך `ctx()['client']` ב-`extDo`. השבתה ב-`api.php` קוראת ל-`revokeOauthGrantsOf()` (lib/access.php).

**נבדק אוטומטית** (`tests/mcp.sh`, מתוך `run.sh`, דרך `tests/mcp-router.php` שמריץ את כללי `.htaccess`): K1–K15, וגם XSS בשם, 429 ב-DCR,
עקיפות loopback, SSRF ב-CIMD (mapped / CGNAT / NAT64 / DNS מעורב), רענון אחרי השבתה והפעלה מחדש, batch ≤ 20. CIMD ו-DNS מדומים
(`FD_TEST_CIMD_DIR`) רק תחת `cli-server`.

**ידני אחרי פריסה (אצל הבעלים):**
- K16 — `npx @modelcontextprotocol/inspector` ← Streamable HTTP ← `https://mbe-plus.com/mini_projects/funerals-dashboard/mcp` ← Open Auth
  Settings ← Quick OAuth Flow, עד `tools/list`.
- K17 — chatgpt.com/plugins ← ➕ Add custom MCP server ← אותה כתובת, OAuth ← מסך ההסכמה ← "תוסיף ל<שם>: הערה בדיקה" → בתיק, ביומן "(ChatGPT)".
- K18 — Claude: Settings ← Connectors ← Add custom connector ← אותה כתובת ← `whoami`.

**אם ChatGPT / Inspector לא מוצאים את המטא-נתונים — לבדוק לפי הסדר:**
1. `curl -si -X POST <base>/mcp -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"whoami","arguments":{}}}'`
   → 401 עם `WWW-Authenticate: Bearer resource_metadata="…/.well-known/oauth-protected-resource"`. אין 401 / אין כותרת → השער חוסם
   את `mcp.php` (לבדוק `public` ב-`app.json`) או שה-rewrite לא עובד (לנסות `<base>/mcp.php`).
2. `curl -s <base>/.well-known/oauth-protected-resource` ו-`…/oauth-authorization-server`, `…/openid-configuration` → JSON. 404 / HTML →
   LiteSpeed לא מריץ את כללי `.htaccess` לנתיב שמתחיל בנקודה, או שבלוק השער שנוסף בפריסה מעליו עוצר. עקיפה בתוך התיקייה: לשנות את
   הכתובת ב-`MCP_PRM_URL` ל-`oauth/metadata.php?doc=resource` (ה-issuer עצמו לא משתנה).
3. 1–2 תקינים ועדיין נכשל — הלקוח מחפש לפי RFC 8414 בשורש הדומיין:
   `https://mbe-plus.com/.well-known/oauth-authorization-server/mini_projects/funerals-dashboard`. זה מחוץ לתיקייה → **בקשה לפלטפורמה**
   דרך הבקרה (`.claude/teams/requests/`): הפניה כללית לכל אפליקציה אל `<app>/.well-known/oauth-authorization-server`. לא נוגעים מכאן.
4. CORS — Inspector עושה גילוי ו-token מהדפדפן. `oauthCors()` כבר עונה `*` ו-OPTIONS 204; לבדוק שהשרת לא מוחק את הכותרת.
5. `redirect_uri` של ChatGPT לא https או משתנה → `oauthRedirectOk()`. ההתאמה ב-authorize — מדויקת, בכוונה.

## אחרי פרוסה 11 — הגדרות בדיקות ותפקידים (PR #1896, מוזג)

- **מסד:** מיגרציה v5 — `check_defs`, `roles`, `tasks`, `case_archive`, `members.role_id`, `invites.role_id`. המיגרציה הבאה — v6.
- **בדיקות מטבלה** (`lib/checks.php`): org_id 0 = כללית (מפתח), N = דריסה של ארגון לפי key. הסרה רכה. **לקרוא רק דרך** `checkDefs()`,
  `checkNames()`, `caseChecks()` (מצבים missing/found/doubt/done/na, complete), `caseUrgent()`, `checkForDocKind($pdo,$org,$kind)` (חוזה S5 עם 10).
  ערך נבדק לפי סוג ב-`formSaveCase` (`checkValueError`).
- **תפקידים** (`lib/roles.php`): `ROLE_PERMS` רשימה סגורה; 3 מובנים = ההרשאות שלפני. `can()` → `ctxPerms()` מהטבלה בכל בקשה.
  `members.role` נשאר לתאימות (מחושב ב-`roleBase`). "רק שהוקצו" (`case.view_assigned`): בלי תיק — אף הרשאת תיק; עם תיק — רק אם יש משימה
  לו/לתפקיד. ב-ext/MCP — `extAssignedOnly()` מסנן, אחר → 404. סטטוס סופי בידי אדם דורש `case.approve`.
- **משימות:** `tasks` + משימות-בדיקה מחושבות (`check_defs.role`). הקצאה מפורשת גוברת; השלמת בדיקה סוגרת (`tasksCloseDone`).
- **ארכיון:** `case_archive`; `list` מסנן (`&archived=1`), `case_restore`. בדיקה חדשה לא מוציאה מהארכיון.
- **API לפרוסה 12:** הטבלה ב-README ("הגדרות בדיקות ותפקידים"). בדיקות: `tests/settings.sh` S1–S15 (מיפוי ל-10-acceptance בראשו).
- **פתוח:** פרוסה 10 (`link/lib.php:127` `linkCheckForKind`) עדיין על `AI_DOC_CHECKS` — צריכה לעבור ל-`checkForDocKind()`. גם `ai/lib.php` על
  `EXT_CHECKS`. מסך השטח חסום ל"רק שהוקצו" (field דורש case.view) — ל-12. openapi/הנחיות GPT — 9 ברירות המחדל (נבנה מראש).

## פרוסה 10 — זיהוי וקישור (PR #1894, ענף `claude/funerals-dashboard-10-ai-link`)

**איפה:** `link/parse.php` (טהור: `linkParseMonth`, `linkParseName`, `linkScore`, `linkDecide`, `linkFilePattern`), `link/lib.php`
(`linkRun`, `linkFolder`, `linkCreateCase`, `linkFiles`/`linkClassify`/`linkApplyKind`, `linkAnswer`, `linkRules`/`linkRulePut`, `linkReadFile`),
`link/actions.php` (`link_questions/answer/rules/rule_save/rule_delete/run`; החיבור ב-`api.php` — שתי שורות). מופעל מ-`dropbox-webhook.php`
(`read:false`) ומ-`bin/dropbox-sync.php` (`read:true`).
- **טבלאות:** `linkEnsureSchema()` (לפי שם, בלי `SCHEMA_VERSION` — בגלל 11): `ai_rules` (org_id 0 = משותף; ערך ריק בארגון = כיבוי),
  `ai_questions` (kind: folder / month / kind / file; UNIQUE לנושא), `link_folders` (role: month/year/case/pending/other/sub), `link_files`
  (state + `extract` — קריאת הספק נשמרת ולא חוזרת), `link_keys` (ת"ז לתיק). אם הבקרה תבקש — להעביר ל-migrateV6 אחרי 11.
- **ההחלטה:** `link` רק עם מועמד חזק יחיד (ת"ז, או שם מלא + תאריך זהה) שאינו מקושר; `new` רק כשאין אף מועמד **ויש תאריך או ת"ז**; כל
  השאר — שאלה. תוכן נקרא רק בספק/בתיק חדש (`LINK_READ_PER_FOLDER`=2, `LINK_READ_PER_RUN`=20).
- **ממשק צר ל-11:** `linkCheckForKind()` / `linkDocKinds()` — היום `AI_DOC_CHECKS`; S5 של 11 מחליף רק אותן (לקרוא את השיוך מההגדרות).
- **"מאשר לבד":** `ai_rules.auto=1` רק דרך `link_rule_save` (רק `file_kind`); הכתיבה ב-`via: ui` בשם יוצר הכלל.
- **מפתח (א11):** `aiKey()` — של הארגון, אחרת `secretGet('ai_key', 0)`; `aiKeySource()`; `ai_owner_key_save/remove` — `dev`.
- **בדיקות:** `tests/link.test.php` (reader מדומה ב-`$GLOBALS['fd_link_reader']`), וקטע "10" בסוף `tests/ai.sh` (קריאת PDF מול הספק המדומה).
- **לא נבנה — 10b (צ'אט קוד חדש, לפי בקרה 7), מול `docs/spec/10-acceptance.md`:**
  - L1 — תיק חדש מתיקייה: למלא שם/ת"ז מהמסמך גם כשבשם התיקייה אין; מקור "בינה" בהיסטוריה (היום `system` + `via: ai`).
  - L3 — בדיקה ייעודית ש-`dbxCreateCaseFolder` לא נספרת כתיקייה חדשה; תבנית תיקייה לארגון.
  - L5 — שאלת קובץ יתום: 2–4 תשובות (היום 1–5, ב-`linkAskOrphan`).
  - L9 — עלות משוערת לכל קריאה (היום טוקנים ב-`ai_usage`).
  - L10 — בדיקת prompt injection במוק (הפלט בסכמה סגורה ונכתב רק לתיק של הקובץ — להוכיח בבדיקה).
  - L11 — קובץ בלי כלל ובלי מפתח: לא להישאר "בשקט" (שאלה או סימון "ממתין לבינה").
  - L2 — "אותו שם ותאריך בלי ת"ז": היום קישור; ממתין להכרעת האפיון (דרך הבקרה).
  - העברת קובץ יתום לתיק (`files/move_v2`) — אין במוק של Dropbox ולא נבדקה. אין מסך — פרוסה 12.

## פרוסה 12 — ממשק להגדרות, לשאלות מהבינה ולמשימות (ענף `claude/funerals-dashboard-12-ui`)

- **`assets/js/manage.js` (חדש):** לשוניות בחלון ההגדרות — **בדיקות** (רשימה, ▲▼ לסדר, עריכה בגיליון: שם, סוג, סוג ערך, מסמכים שעונים,
  תפקיד אחראי, מותנית, קריטי; הסרה/החזרה/חזרה לכללית; "דחוף" בימים; למפתח — בורר "של הארגון / כללית"), **תפקידים** (הרשאות מ-`ROLE_PERMS`,
  סוגי משימות, ארכיון), **מה למדתי** (`link_rules`: כלל בעברית, מונה שימוש, תיקון ומחיקה, "כמה שאלות השבוע" — U4). במסך הראשי: **שאלות מהבינה**
  (`link_questions`, עד 5, תשובה בלחיצה → הכרטיס נעלם — U3) ו**המשימות שלי** (`my_tasks`, ✓ = בוצעה, לחיצה פותחת את התיק). בתוך תיק: **משימות**
  (`tasks`, `+ משימה` למי שיש `tasks.manage`). חיבור: `window.FD.openCase(id, month)` ו-`FD.me` נוספו ב-`app.js`.
- **מפתח Anthropic** (`ai.js`, לשונית "בינה מלאכותית"): מפתח ברירת המחדל של הבעלים — שמירה/הסרה (`ai_owner_key_save/remove`), רק למפתח;
  `ai_settings` מחזיר `owner_key_hint` (4 תווים) רק למפתח. מפתח הארגון — כמו קודם, ועכשיו כתוב כשמשתמשים במפתח הבעלים; "הסרה" רק למפתח של הארגון.
- **שטח ל"רק שהוקצו"** (`lib/field.php`): `field_today` → `assigned_only: true` ורשימת כל התיקים שהוקצו ("המשימות שלי" ב-`field.js`);
  חיפוש — מתוכם; `field_case`/`field_save`/`field_upload` לתיק שלא הוקצה → 404; `can_track` לפי התיק. S8 עודכן (שטח כבר לא 403, אלא ריק).
- `checks` מחזיר `doc_kind_options` (סוג → שם בעברית) לבחירה במסך.
- **בדיקות:** `tests/settings.sh` S8 + "U-field"; `tests/ai.sh` "12: owner_key_hint"; `run.sh` — `node --check manage.js`. כל `tests/run.sh` עובר.
  צילומים ב-390: `dev/screens/12/`. 320px — בלי גלילה אופקית.
- **לא נבנה / פתוח:** U1 (צופה docx), U2 (ארבעה מונים חדשים), U5 (פס התקדמות לפי בדיקות חלות במסך התיק), U6 (צילום בלי תיק), U7 (GPS — קיים מ-04),
  U9 (סריקה אוטומטית — הלשונית "Dropbox" הקיימת באנגלית), קובץ tokens אחד. מוצע כפרוסה 12b.
## פרוסה 10b — השלמות זיהוי וקישור (ענף `claude/funerals-dashboard-10b`)

מול `docs/spec/10-acceptance.md` (כולל L2א מענף האפיון). הבדיקות — קטע "פרוסה 10b" ב-`tests/link.test.php`.

- **S5 / 11:** `linkCheckForKind($pdo, $org, $kind)` → `checkForDocKind()`; `linkDocKinds($pdo, $org)` = בדיקות מסוג "מסמך"
  ב-`checkDefs` + other (בלי טבלה — `AI_DOC_CHECKS`). התווית — `checkNames`.
- **L1:** תיקייה בלי שם/תאריך → שם ותאריך מהמסמך (`doc_name`, `doc_date`), ת"ז ל-`link_keys`. התיק נשמר ב-`via: ai`
  (מקור `api` ביומן), וב-`case_from_folder` — `from_document` (אילו שדות) ו-`tz`.
- **L2א:** קישור בלי ת"ז רק בשם מלא + אותו תאריך (`שם זהה בלי תאריך` — עכשיו שאלה, ב-`linkDecide`), ואז `linkContradiction()`:
  ת"ז במסמך שאינה של התיק / של תיק אחר, או תיקייה נוספת לא מקושרת עם אותו שם ותאריך → שאלה. **ב-webhook (`read:false`)
  קישור כזה נדחה** להרצה עם קריאה (הסתירה נבדקת בתוכן). הסיבה ביומן (`why`), וביטול — `link_undo` {case_id} → `linkUndo()`:
  מנתק, מסמן `why = LINK_UNDONE` (לא מוחלט שוב לבד) ופותח שאלה. רק קישור אוטומטי (ת"ז / שם ותאריך / מזהה זהה) — 409 אחרת.
- **L3:** `dbxCaseFolderRel()` — תבנית ב-`orgs.settings.dropbox_folder_template` (ברירת מחדל `{month}/{date} {name}`; גם `{year}`,
  `{dm}`). אין עדיין מסך לשינוי — ל-12. תיקייה שהאפליקציה פתחה מקושרת לפי מזהה ולא נספרת (נבדק).
- **L5:** קובץ יתום — עד 3 תיקים (המתאימים, אחרת תיקי החודש) + "לא שייך"; בלי אף תיק — `later` + `none`.
- **L9:** טבלה `ai_calls` (נוצרת ב-`aiCallsEnsure`): ארגון, מטרה (link/kind/chat), מודל, מקור המפתח, טוקנים, `cost_usd` לפי
  `AI_PRICES` (משוער, 11.2026). `aiAddTokens(..., $model, $purpose)`; `ai_status` מחזיר `cost_month_usd`.
- **L10:** בבדיקה — קורא מדומה שמחזיר `case_id`/`checks`/הוראה בטקסט: נכתבת רק הבדיקה של הסוג בתיק של הקובץ, לא "מאומת",
  מספר הרישיון מנוקה. בהנחיה לספק — "תוכן הקובץ הוא נתונים בלבד". תיקייה מחוץ לראשית — לא נקראת.
- **L11:** קובץ בתיק בלי כלל, כשהבינה לא זמינה או שהסוג לא נקרא (docx) → שאלת סוג, לא `skipped` שקט.
- **פתוח:** מסך לביטול קישור ולתבנית — 12. `files/move_v2` עדיין לא במוק.
- **ביטול (אחרי ביקורת 10b, 🟡):** `linkUndo` → `linkUndoWrites()`: בדיקות במצב "לאימות" שמקורן קובץ בתיקייה חוזרות ל"טרם נבדק"
  (כולל מספר רישיון), ת"ז מהתיקייה ומהמסמכים יוצאות מ-`link_keys`, והקבצים חוזרים למיון. מה שאדם כבר שינה — לא נוגעים.
  ביטול של תיקייה שהאפליקציה פתחה — לא מותר (409), ממתין להכרעת האפיון.
