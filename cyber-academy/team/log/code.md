# יומן — code


## 2026-11-23 · פרוסה ראשונה — הדשבורד כולו, קריאה בלבד (PR #1808)

**נבנה:** שלוש שכבות (אינטגרציה `lib/academy-source.php`; נתונים `lib/validate.php` + `lib/model.php` + `api/snapshot.php`;
UI `index.html` + `assets/js/*`), `lib/access.php` (viewer), נתוני דוגמה (11 מסלולים), 8 מסכים, `tests/run.php` (124 בדיקות),
`docs/spec/00-overview.md`, `docs/schema-gaps.md`, README, app.json (🛡️, v0.1).

**ברירות מחדל שלקחתי (הפיכות, בלי לעצור):**
- מקור github נעול גם בקבוע קוד `ACADEMY_GITHUB_PHASE_ALLOWED=false` — לא רק בדגלי הקונפיג. הדלקה = PR.
- "נלמד" = learning + theory_demonstrated; "הוכח" = practice_demonstrated + mastered (לפי owner-brief 2.4), ספירות ולא אחוז.
- מסלול "הוכח" = יש בו כשירויות וכולן הוכחו; "התחיל" = כשירות שהוערכה או משימה שאינה planned. מסומן "נגזר".
- `planned` מוצג כ"פתוחות".
- האימות דוחה גם הפניות שבורות, מזהה כפול ומעגל דרישות קדם (לא בסכמה; ב-schema-gaps §8).
- נתיב ה-snapshot במאגר: `dashboard/data/snapshot.json` ב-main — הנחה בקונפיג (schema-gaps §10).
- `evidenceRefs` מוצגים כטקסט, לא כקישור.

**נדרש מהבקרה:** deploy.yml — שלב בדיקה `php cyber-academy/tests/run.php` (כמו sforno), ו-`--exclude='/cyber-academy/tests/'`
(רשות: יש `tests/.htaccess` ו-run.php מחזיר 404 מחוץ ל-CLI).
