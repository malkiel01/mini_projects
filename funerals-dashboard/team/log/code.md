# יומן — code


## 2026-11-24 13:26 (נרשם בידי הבקרה)
- פרוסה 00: dev/audit.md, PR ‏#1834 מוזג; העברה ב-PR ‏#1835. הצ'אט רדום.

## 2026-11-24 — פרוסה 01 (תשתית), ענף `claude/funerals-dashboard-01-infra`
- נבנה לפי `docs/spec/01-infrastructure.md`: מיגרציה v1 עם גיבוי, ארגונים/משתמשים/חברים/הזמנות, `can()`/`scope()`, `events`,
  `req_log`, סודות, מסך מפתח, DevLoop (`FD-`), מפת דרכים, VERSION 1.0.0. `tests/run.sh` מכסה A1–A14, A16 (חלקית), A17.
- ה-JS עבר מ-`index.html` ל-`assets/js/app.js` (audit ג.7).
- נדרש מהבקרה: שורת `.devloop.json` (בתיאור ה-PR), ביקורת, מיזוג.
## 2026-11-24 — פרוסה 02 (טופס חכם), ענף `claude/funerals-dashboard-02-smart-form` — במקביל ל-01 (טורבו)
- `lib/form.php` (חדש): `finalAllowed($via)`, `formApplyChecks()` (by/via/at לבדיקה ששונתה בלבד, אוטומטי → לאימות + הצעה ב-note,
  לא פותח מחדש בדיקה שאדם סגר), `formSaveCase()` (ה-save של api.php עבר לכאן), `formHistory()` (events של 01; עד אז — audit).
- `assets/js/form.js` (חדש): `caseState` ("הושלם" רק עם בדיקה מאומתת), `nextUp`, `matches`, `provenance` ("מייבוא ישן").
- נגיעות קטנות בקבצים של 01: `lib.php` (require + שורה ב-importRecords), `api.php` (save → formSaveCase, action=history).
- ממשק: "הבא בתור" כפתור בראש התיק, 6 סינונים (להשלמה/חסר/לאימות/טרם נבדק/הושלם/הכול), מקור לכל בדיקה, לשונית היסטוריה.
- בדיקות: `tests/form.test.php`, `tests/form.test.js`, `tests/fixtures/legacy-cases.json` (22 תיקים בצורה הישנה) + B1/B3/B6/B7 מול השרת ב-`run.sh`. B8 — ידני.
- **פתוח ליישור אחרי 01:** `formHistoryAction($pdo,$method,null)` → הארגון הפעיל של 01 (404 לארגון אחר); מבנה `diff` ב-`events`
  (`formDiffChanges` סובלני ל-[לפני,אחרי] / from-to / מקונן); לוודא ש-`recordEvent` נקרא גם ב-`formSaveCase`.
- ניסיון send_message לקוד 01 לתיאום — נחסם בהרשאה. מחכה להודעת הבקרה על מיזוג 01.
- 2026-11-24 (המשך) — קוד 01 שלח את הממשק (PR #1839). יישור מוכן **מקומית** בענף `align-01` (מיזוג של ענף 01 + התאמה), לא נדחף עד ש-01
  ממוזג ל-`main`: `formSaveCase($pdo,$raw,$via,$actor[],$orgId,$authorize)` מעל `store()`/`existing()` של 01; `formEventSource()` (field→ui,
  ai→api, dropbox→system); `history` כ-case ב-api.php עם `need('case.view')` ו-`scope()`; ה-UI הועבר ל-`assets/js/app.js`; B1–B7 ב-`run.sh` של 01.
  הערה ל-01: `caseDiff()` רושם `payments` כשינוי כש-402 מול 402.0 (השוואה קשיחה) — ההיסטוריה שלי מסננת, אבל ב-`events` זה רעש.
- 2026-11-24 — הודעה מקוד 05: קורא ל-`finalAllowed($via)` — **תואם** (אותה חתימה, bool; אותם שדות `by`/`via`/`at`). שני הבדלים ליישור
  אצל מי שממוזג שני (00 §4): (1) ניסוח ההערה — אצלי `הוצע (צ'אט חיצוני): <סטטוס> — <ערך>`, אצל 05 `הוצע בצ'אט: …`; (2) אצלי בדיקה
  שאדם כבר סגר (verified/reported/not_applicable) **לא נפתחת מחדש** בידי מקור אוטומטי — ההצעה נכנסת רק להערה. המלצה: 05 קורא ל-
  `formApplyChecks($new,$old,'api',$by)` (או `formSaveCase`) במקום מימוש משלו. לא יכולתי לענות ל-05 — send_message חסום בהרשאה.
- 2026-11-24 — 01 מוזג (#1839, 8eb9a9f). `main` מוזג לענף 02 (הגרסה של `main` לכל קבצי 01 + ה-diff של 02 מעליה); `caseChangeNeeds`
  מקבל `normalize($old)` כמו ב-`main`. כל A ו-B1–B7 ירוקים. PR טיוטה ל-`main`.

## 2026-11-24 — פרוסה 06 (AI מובנה), ענף `claude/funerals-dashboard-06-ai` — במקביל ל-03 ו-05 (טורבו)
- חדש: `ai/lib.php`, `ai/actions.php`, `assets/js/ai.js`, `assets/css/ai.css`, `tests/ai.sh` (+ mock, fixture). נגיעות: `api.php` (2 שורות),
  `lib.php` (`FdReply` + מצב לכידה ב-`reply()`), `index.html` (2 שורות), `app.js` (שורת `fd:case` — זהה לזו של 03), `run.sh` (שורת ai.sh).
- **השכבה המשותפת עם 05 — `extRun($pdo, $m, $action, $query, $body, $via, $dry)` ב-`ext-lib.php`:** גוף ה-switch של `ext.php` עובר
  לפונקציה (`$_GET`→`$query`, `body()`→`$body`, `extActor($m)`→`ctx()['actor']['actor']`, `formSaveCase(..., $via)`, `COMMIT`→`$dry ? ROLLBACK : COMMIT`),
  ובדיקת `extCan` עוברת פנימה. ה-patch המוכן: `tests/patches/extrun-05.patch` (מול ענף 05 כפי שהוא היום). נבדק בעץ אינטגרציה (main+05+06+patch):
  E1–E10 של 05 ירוקים, F1–F7 ירוקים.
- ניסיון `send_message` לקוד 05 לתיאום השכבה — **נחסם בהרשאה**. לכן: אחרי ש-05 תמוזג, אני מחיל את ה-patch בענף 06 (יישור), ומוחק אותו.
- **מבקש מ-03:** פונקציה שמחזירה תוכן קובץ לספק — `dropboxFileContent(PDO $pdo, int $orgId, string $fileId, int $maxBytes): ?array` →
  `['mime' => ..., 'data' => bytes, 'name' => ...]`, בהרשאה של ctx (case.view, קובץ של הארגון). עד אז — §2.2.2 (קובץ לספק) ו-§2.3 (הצעת סוג) לא נבנו.
- הכרעה טכנית: "שיחה" במונה = הודעה של משתמש (לא שרשור) — חוסם עלות גם בשרשור ארוך. ספק ב-HTTP ישיר (אין composer בשרת).
- PR טיוטה #1848. הודעה לבקרה (send_message) — **נחסמה בהרשאה**; ממתין להודעה על מיזוג 03 ו-05 (דרך הבקרה או הבעלים).
## 2026-11-24 — פרוסה 05 (API חיצוני), ענף `claude/funerals-dashboard-05-external-api`

- נבנה בקבצים חדשים: `ext.php` (Bearer בלבד), `ext-lib.php` (טבלת הפעולות, אסימונים, הרשאות, ADAPTER ל-01/02/03),
  `tokens.php` + `connect.html` + `assets/js/connect.js` (מסך "חיבור ל-ChatGPT", שלושה כפתורי העתקה),
  `tools/build-ext.php` → `openapi.json` + `gpt-prompt.md`. בדיקות: `tests/ext.sh` (E1–E10), `tests/openapi-check.php`.
- נגיעות בקבצים קיימים: `app.json` (public), `tests/run.sh` (public + קריאה ל-ext.sh), `index.html` (שורה אחת: 🤖), README.
- 01/02/03 עוד לא בענף ראשי — ממתין למיזוג 01 ואז 02 ליישור (ראה handoff).

## 2026-11-24 — 05: יישור מול 01 ו-02 (PR #1840, טיוטה)

- מוזג main (01 #1839, 02 #1842). ext.php: can() מ-fd_ctx של האסימון, formSaveCase(via: api) + need(caseChangeNeeds()),
  logRequest, resolveAccess במסך החיבור; `caseChangeNeeds()` הועבר ל-lib/access.php. tokens — לפי שם, בלי מספר גרסה.
- run.sh המלא ירוק (01, 02, 05). נוספה בדיקה: בדיקה שאדם סגר — הצ'אט לא פותח מחדש.

## 2026-11-24 — 05: ביקורת סבב 1 (team/reviews/05-external-api.md)

- 🔴1, 🔴2, 🟡5 — כבר תוקנו ביישור (formSaveCase; run.sh/הוק/fixture; can() מהקשר האסימון בלבד).
- 🟡3 בדיקה סגורה לא משתנה דרך API (גם לא ל"חסר"/"טרם נבדק"); ההצעה בהערות התיק. 🟡4 טיפוס שגוי → 400.
- 🟡6 401 לא נרשם; 20 כושלים בדקה לכתובת → 429. 🟡7 beginTransaction של PDO — logRequest מבטל ורושם את הדחייה.
- ⚪9 סינון סטטוס לפני החיתוך. ⚪10 ברירת מחדל 90 — נבדק. ⚪8 — נדחה עד ש-v2 של 03 ב-main (ראה הודעה לבקרה).
- לכל סעיף בדיקה ב-tests/ext.sh; מוטציה ל-3/6/7 נכשלת כצפוי.

## 2026-11-24 — פרוסה 03 (Dropbox), ענף `claude/funerals-dashboard-03-dropbox`

- נבנה במקביל ל-01, בקבצים חדשים: `dropbox/` (lib, actions, compat), `dropbox-webhook.php`, `bin/dropbox-sync.php`,
  `assets/js/dropbox.js`, `assets/css/dropbox.css`, `tests/dropbox-test.php`, `tests/dropbox-mock.php`.
- **יושר מול 01 ו-02 אחרי המיזוג שלהן** (`git merge origin/main`): `dropbox/compat.php` קורא ל-`secretPut/Get`, `recordEvent`,
  `ctx`/`can`/`scope`. נקודות חיבור: `api.php` (require + שורה אחת, ושורת Dropbox ב-`healthChecks`), `lib/access.php`
  (שתי הרשאות: `dropbox.manage` למנהל, `files.upload` למשרד ומנהל), `app.json` (`dropbox-webhook.php` ב-`"public"`),
  `index.html` (קטע הקבצים בתיק, לשונית Dropbox בהגדרות, שני קבצים), `assets/js/app.js` (אירועי `fd:case`/`fd:ready`,
  `window.FD`, הלשונית, ושורת חיבור חיצוני עם ✅/⛔), `tests/run.sh` (מריץ את `dropbox-test.php` בסוף).
- בדיקות: C1–C11, C13 מול Dropbox מדומה — עוברות, עם משתמשים וארגונים אמיתיים של 01 (הזמנות). C12 ידני בשרת.
- נדרש מהבקרה: השורה `funerals-dashboard-dropbox|*/5 * * * *|funerals-dashboard/bin/dropbox-sync.php|funerals-dashboard/data/dropbox-sync.log` ב-`CRONS`.
- 2026-11-24 14:21 — #1844 מוזג (f3064a0) אחרי סבב ביקורת אחד (🔴1 OAuth בלי session, 🔴2 files לפי ארגון, 🟡3–6, ⚪7–8 — כולם תוקנו עם בדיקה).
  handoff עודכן. צ'אט קוד 03 מסיים.
## פרוסה 04 — עובד שטח (ענף `claude/funerals-dashboard-04-field`)

- 2026-11-24 — נבנה בקבצים חדשים: `lib/field.php` (היום/מחר, חיפוש, תיק, עדכון `via: field`, העלאה עם `upload_id` בטבלה
  `field_uploads`, שיוך סוג קובץ לבדיקה), `assets/js/field-core.js` (הקטנה ל-2,560, GPS נשמר, תור), `assets/js/field.js`,
  `assets/css/field.css`. נגיעות בקבצים משותפים: `api.php` (+3 שורות), `assets/js/app.js` (+1: אירוע `fd:me`), `index.html` (+3), `run.sh`.
- ההעלאה ל-Dropbox — מרכיבה את הפונקציות של 03 (`dbxReadyOrg`, `dbxCreateCaseFolder`, `dbxUploadFile`, `dbxLowerInRoot`,
  `dbxUpsertFile`) מאחורי `function_exists`; עד המיזוג — 409 "לא מחובר". W2–W4 נבדקים עם העלאה מדומה.
- **ניסיון send_message לקוד 03 לתיאום החתימה — נחסם בהרשאה.** לכן לא ביקשתי שינוי ב-03; ה-idempotency אצלי. אחרי מיזוג 03:
  `git merge origin/main`, ובדיקה של `fieldDropboxUploader` מול `tests/dropbox-mock.php` (W2 עם קובץ אמיתי במדומה).
- בדיקות: `tests/field.test.php`, `tests/field.test.js`, W1/W5/W7 ב-`run.sh` — כולן ירוקות. W8 — ידני בטלפון.
- 2026-11-24 — 03 כבר מציע `dropboxUploadToCase($pdo,$orgId,$case,$tmp,$name,$actor,$via,$uploadId)` (בענף 03, 69dadac). עברתי אליו
  (במקום להרכיב את הפונקציות הפנימיות). `tests/field-dropbox.test.php` — W2–W4, W7 מול המדומה של 03; מדלג כל עוד 03 לא בקוד.
  **מיזוג ניסיון מקומי עם ענף 03 (לא נדחף): כל run.sh ירוק, כולל C1–C13 ו-W מול המדומה.** התנגשויות צפויות במיזוג: `index.html`
  (שורת ה-CSS), `tests/run.sh` (רשימת `php -l` והסוף), `team/log/code.md` — כולן הוספה משני הצדדים.
  נמצא ותוקן: `kill "$pid"` עם `pid=0` (אחרי בלוק 03) שולח SIGTERM לכל הקבוצה — אצלי רק אם השרת רץ.
- 2026-11-24 — ביקורת סבב 1 (`team/reviews/04-field.md`): 🔴1 — ההעלאה קוראת את התיק מחדש אחרי ה-uploader בתוך `BEGIN IMMEDIATE`
  ומשנה רק `checks[$check]` (בדיקה: עריכת שם/תשלום/הערה באמצע שורדת). 🔴2 — `sw.js` עם Background Sync בלבד (אותו `fd_field`
  ו-`upload_id`, meta: csrf/משתמש/ארגון), נבדק גם בדפדפן אמיתי (Playwright + CDP `dispatchSyncEvent` אחרי סגירת הדף → השרת קיבל).
  🟡3 ביטול בזמן flush (קריאה מהמחסן לפני ואחרי שליחה); 🟡4 התיק נקבע לפני ההקטנה; 🟡5 חלון "בשליחה" 15 דק'; 🟡6 org בפריט +
  ניקוי פריטים של משתמש אחר אחרי שבוע + תור מחדש בהחלפת ארגון; 🟡7 `dbxAllowedUpload` של 03 + צילום קבר רק תמונה; 🟡8 `field_uploads`
  → מיגרציה v3 (06 עוד על 2 — **מי שממוזג אחרי צריך v4**). ⚪9 org_id ב-UPDATE; ⚪10 אירוע אחד למזהה, `kind` מהרשימה, user_id;
  ⚪11 `taken_at`; ⚪12 README; ⚪14 `navigator.locks`; ⚪15 200 בלי JSON → ממתין.
- 2026-11-24 — **יישור ל-05 (מוזג, #1840):** `main` מוזג לענף. 05 בנה שכבה משותפת משלו — `extDo($pdo, $action, $in, $via)` שזורק `ExtError` —
  והכלים של 06 עברו אליה (ה-patch של extRun נמחק). תוספת אחת ל-05: `extDo(..., bool $dry = false)` — `rollBack` במקום `commit` בשתי
  הכתיבות, לכרטיס ההצעה. השגיאות של פונקציות הליבה (`reply()`) נלכדות ב-`FdReply`, כך שגם הן חוזרות לספק. F1–F7 ירוקים בענף.
  תיקון ב-`run.sh`: הבלוק שלי לא קורא עוד ל-`kill "$pid"` אחרי ש-05 איפס אותו (`kill 0` הרג את כל קבוצת התהליכים).
- הודעה מקוד 03 (PR #1844, עוד לא מוזג): `dropboxFileContent($pdo, int $orgId, int $fileId, $maxBytes)` → `name/mime/size/bytes`;
  `files.kind` ריקה ומתאימה לסיווג. אחרי המיזוג של 03 — §2.2.2 (קובץ לספק) ו-§2.3 (הצעת סוג) נבנים מעליהן.
- 2026-11-24 — **יישור ל-03 (מוזג, #1844):** `main` מוזג. נבנו §2.2.2 — הכלי `file_view` (הקובץ עצמו לספק: image / document, עד 5MB, 10 קבצים
  ו-20MB לשיחה) מעל `dropboxFileContent()`; ו-§2.3 — הצעת סוג לקובץ חדש בלי סוג (structured output, פעם אחת לקובץ, `ai_file_kinds`), ✓ →
  `files.kind` + הבדיקה המתאימה "לאימות" דרך `extDo` (via: ai). נגיעות: `dropbox.js` (`data-file-id` + אירוע `fd:files`), `app.js` (`fd:changed`
  → טעינה מחדש של תיק פתוח, כדי ש"שמירה" לא תדרוס שינוי שאושר מהעוזר). בדיקות: `ai.sh` מול Dropbox המדומה של 03 (`ai-dbx-fixture.php`).
- 2026-11-24 — **ביקורת 06 סבב 1 (בקרה 2, על e37dae4):** 🔴1 הצעה ישנה — hash של השדות/הבדיקות שבהצעה (`ai_proposals.base`), ✓ אחרי שינוי →
  409 `stale`; 🟡2 tool_use פתוח נסגר ב-tool_result is_error; 🟡3 הקובץ לא נשמר ב-`ai_threads` (סימון) + `drop_block` + הודעת מערכת קפואה
  לשיחה; 🟡4 תקרת טוקנים חודשית (`ai_usage.tokens`, `ai_settings.token_cap`) + `cache_control`; 🟡5 `session_write_close()`; 🟡6 ✓ לא מאפס
  טופס עם שינויים; ⚪7 לפי ההכרעה; ⚪8 הסרת מפתח; ⚪9 `#[\SensitiveParameter]`; ⚪10 תשובה מהסבב האחרון; ⚪11 `deciding` תקוע / `failed` →
  ניסיון חוזר; ⚪12 שורת `running` לפני הקריאה; ⚪13 README. עמודות חדשות ב-ALTER אידמפוטנטי (בלי מספר גרסה — v3/v4 לא נגעו).
  בדיקות חדשות ב-`ai.sh`: R1, R2, R3, אין base64, ⚪7, תקרת טוקנים, הסרת מפתח.
