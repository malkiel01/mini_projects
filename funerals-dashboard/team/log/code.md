# יומן — code


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
