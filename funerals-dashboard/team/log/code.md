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
