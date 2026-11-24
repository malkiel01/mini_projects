# יומן — code


## 2026-11-24 — פרוסה 05 (API חיצוני), ענף `claude/funerals-dashboard-05-external-api`

- נבנה בקבצים חדשים: `ext.php` (Bearer בלבד), `ext-lib.php` (טבלת הפעולות, אסימונים, הרשאות, ADAPTER ל-01/02/03),
  `tokens.php` + `connect.html` + `assets/js/connect.js` (מסך "חיבור ל-ChatGPT", שלושה כפתורי העתקה),
  `tools/build-ext.php` → `openapi.json` + `gpt-prompt.md`. בדיקות: `tests/ext.sh` (E1–E10), `tests/openapi-check.php`.
- נגיעות בקבצים קיימים: `app.json` (public), `tests/run.sh` (public + קריאה ל-ext.sh), `index.html` (שורה אחת: 🤖), README.
- 01/02/03 עוד לא בענף ראשי — ממתין למיזוג 01 ואז 02 ליישור (ראה handoff).
