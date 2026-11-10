# העברה — צ'אט הקוד

נכתב בידי הבקרה ב-2026-11-10, אחרי מיזוג פרוסה 01 (#1374). הצ'אט הקודם
(`session_018g4NYFYs5G6v9s2CqJNEPh`) הגיע ל-612K טוקנים בהקשר; כל תור שלו קרא את כולם, וזה שרף את מכסת
השימוש של החשבון. הוא מפסיק לפעול.

## מה יש ב-main (גרסה 0.2)

פרוסה 01 — התשתית, מיושרת ל-`docs/spec/01-foundation.md` (כולל שינוי 1 ו-2): מסד SQLite עם מיגרציות
`001` + `002` (לא משנים מיגרציה שב-main — רק קובץ חדש), התחברות מהשער (`lib/platform.php`, `lib/auth.php`),
הרשאות במקום אחד (`lib/access.php` — טבלת `APR_ACCESS`), משרדים / חברות / הזמנות (`lib/offices.php`),
מנהל המערכת (`lib/system.php`), יומן פעולות (`lib/audit.php`), שירותי רקע (`lib/jobs.php`, `cron.php`),
בדיקות תקינות, DevLoop (עותק מסונכרן — **לא עורכים**), מסכים ב-`assets/js/screens/`, מחרוזות ב-
`assets/js/strings.js`, בדיקות ב-`tests/` (`php appraisers/tests/run.php`).

## מה לקרוא לפני שמתחילים

1. `CLAUDE.md`, `appraisers/team/PROTOCOL.md`, `appraisers/team/STATE.md`, `appraisers/team/board.md`.
2. `appraisers/README.md`, `appraisers/team/log/code.md` (היומן של קודמך), `appraisers/team/decisions.md`.
3. `appraisers/docs/spec/00-overview.md`, ואז האפיון של הפרוסה שבונים.
4. שתי הביקורות: `team/reviews/02-foundation.md`, `team/reviews/01-foundation-align.md` — כדי לדעת מה
   הבקרה בודקת: בדיקה שנכשלת כשמוחקים את השורה שהיא מגינה עליה; מייל לא מאומת; אסימונים שלא מגיעים ליומן.
5. הסקילים `phone-first-design` ו-`devloop`.

## הצעד הבא

**פרוסה 02** (`docs/spec/02-invites-detached.md`), ואחריה 03 → 04 → 06 … לפי "הסדר שנקבע" בלוח.

## כללי עבודה שנלמדו בדרך הקשה

- **לפני כל push: `git fetch origin main` ולבדוק אם ה-PR שלך עדיין פתוח.** PR שמוזג — מתחילים מ-main
  (`git merge origin/main`; force-push חסום), ופותחים PR **חדש**.
- **תורות קצרים.** הודעות מהבקרה מגיעות רק בסוף תור; תור של שעה = שעה בלי לשמוע. commit ו-push אחרי כל
  יחידה, ו-PR לכל פרוסה (לא כמה פרוסות ב-PR אחד).
- כל PR: בתיאור מיפוי קריטריון קבלה → קובץ בדיקה; להעלות גרסה ב-`app.json` ואת ה-`?v=` כשקובץ שמוגש
  לדפדפן השתנה; להודיע לבקרה במספר ה-PR ולהמשיך לפרוסה הבאה בלי לחכות למיזוג (על ענף שיוצא מהקודם —
  ואחרי המיזוג `git merge origin/main`).
- מחוץ ל-`appraisers/` — רק הבקרה. שאלות אפיון — ישירות לצ'אט האפיון (`team/sessions.json`).
- חיסכון במכסה: לא לקרוא קבצים גדולים שוב ושוב; בדיקות ממוקדות בזמן הפיתוח, והרצה מלאה לפני push.
