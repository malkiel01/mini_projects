# מה צ'אט הקוד הבא צריך לדעת


## פרוסה 05 — API חיצוני (ענף `claude/funerals-dashboard-05-external-api`)

**מצב:** בנוי ונבדק מול האפיון (E1–E10 אוטומטיים ב-`tests/ext.sh`). E11 ידני אחרי פריסה. ממתין למיזוג 01 ו-02 ליישור.

**יישור אחרי מיזוג 01/02/03** — כל המקומות מסומנים `ADAPTER` ב-`ext-lib.php`:
1. `extRequireInfra` — טבלת `tokens` נוצרת כאן ב-`CREATE IF NOT EXISTS`; אם 01 מנהלת סכמה ב-`user_version` — להעביר לשם.
2. `extMember` / `extUserBySub` / `extMemberships` — לקרוא לפונקציות של 01 אם יש (או להשאיר: אותן טבלאות).
3. `extRecordEvent` → `recordEvent()` של 01. `extLogRequest` → הפונקציה של 01 ל-`req_log` (בלי האסימון!).
4. `extFinalAllowed` — כבר משתמש ב-`finalAllowed()` של 02 כשהיא קיימת. לבדוק ש-02 שומרת `by/via/at` כמו כאן.
5. `tokens.php` — הארגון הפעיל: היום `?org=` + `$_SESSION['fd_org']`; להחליף בארגון הפעיל של 01.
6. `extDropboxReady('dropboxCaseFiles' / 'dropboxFileText')` — לבדוק את השמות והחתימות האמיתיים של 03.
7. `tests/ext-fixture.php setup` יוצר את טבלאות 01 לפי האפיון רק אם חסרות — אחרי 01 לבדוק שהעמודות תואמות.

**החלטות טכניות:** נתיב `ext.php/<action>` (PATH_INFO) ב-OpenAPI, כי GPT Actions דורש נתיב לכל פעולה ו-OpenAPI אוסר
`?` בנתיב; `?action=` נתמך גם. אם PATH_INFO לא עובד בשרת (E11) — לעבור לקבצים `ext/<action>.php` או rewrite.
ולידציית OpenAPI — מבנית ב-PHP (`tests/openapi-check.php`); התקנת ולידטור חיצוני מ-npm נחסמה בסשן.
