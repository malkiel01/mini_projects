# יומן — code


## 2026-11-24 — פרוסה 03 (Dropbox), ענף `claude/funerals-dashboard-03-dropbox`

- נבנה במקביל ל-01, בקבצים חדשים (`dropbox/`, `dropbox-webhook.php`, `bin/dropbox-sync.php`, `assets/js/dropbox.js`).
  נקודות חיבור: `api.php` (שתי שורות), `index.html` (קטע קבצים, ☁️, דיאלוג, `window.FD`, אירועי `fd:case`/`fd:ready`),
  `app.json` (`dropbox-webhook.php` ב-`"public"`), `tests/run.sh`.
- מול 01 — `dropbox/compat.php`, עד היישור אחרי מיזוג 01.
- בדיקות: C1–C11, C13 מול Dropbox מדומה — עוברות. C12 ידני בשרת.
- נדרש מהבקרה: השורה `funerals-dashboard-dropbox|*/5 * * * *|funerals-dashboard/bin/dropbox-sync.php|funerals-dashboard/data/dropbox-sync.log` ב-`CRONS`.
