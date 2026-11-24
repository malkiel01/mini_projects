# יומן — code


## 2026-11-24 13:26 (נרשם בידי הבקרה)
- פרוסה 00: dev/audit.md, PR ‏#1834 מוזג; העברה ב-PR ‏#1835. הצ'אט רדום.

## 2026-11-24 — פרוסה 01 (תשתית), ענף `claude/funerals-dashboard-01-infra`
- נבנה לפי `docs/spec/01-infrastructure.md`: מיגרציה v1 עם גיבוי, ארגונים/משתמשים/חברים/הזמנות, `can()`/`scope()`, `events`,
  `req_log`, סודות, מסך מפתח, DevLoop (`FD-`), מפת דרכים, VERSION 1.0.0. `tests/run.sh` מכסה A1–A14, A16 (חלקית), A17.
- ה-JS עבר מ-`index.html` ל-`assets/js/app.js` (audit ג.7).
- נדרש מהבקרה: שורת `.devloop.json` (בתיאור ה-PR), ביקורת, מיזוג.
