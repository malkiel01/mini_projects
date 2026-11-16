# יומן — code


## 2026-11-16 — פרוסה 01 (ענף `claude/grave-prices-01`)

נבנה: מסד ומיגרציות (נתוני ההתחלה רק כשאין מחירונים), התחברות מחוברת/מנותקת, יומן פעולות (login, print), רשימת
מחירונים עם "בתוקף היום", מסך מחירון עם מתג בטלפון, `print.html` בארבע התצורות (עמוד A4, הקטנת גופן עד 80%),
מסך מפתח (DevLoop, יומן, פעולות, תקינות), `dev/open-items.json`, `tests/run.php` (32 בדיקות).
מחוץ לתיקייה — לפי הכרעת הבקרה: הרישום ב-`devloop/apps.json` + sync, ושלב הבדיקות ב-`deploy.yml`.

## 2026-11-16 — פרוסה 02 (ענף `claude/grave-prices-02`)

נבנה: מיגרציה 3 (`rows.pid`, מוסיפה בלבד), `lib/edit.php` — `price-set`, `row-save/delete/move`, `signatures-set`,
`edition-create` (עם אחוזים), `edition-update`, `edition-delete` (עתידי בלבד), `history`; 409 `need_confirm` למחירון
בתוקף. במסך: ✏️ מצב עריכה עם שמירה בעזיבת שדה, חלון שורה, חתימות, 📜 שינויים, ➕ מחירון חדש (`#/new`). גרסה 0.2;
GP-02 → ממתין לאימות. בדיקות: 95 (מ-32). ההחלטות הטכניות — `team/handoff/code.md`.
