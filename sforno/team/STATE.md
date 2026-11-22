# מצב — הזיכרון הנייד של הבקרה

- **איפה עומדים (22.11 ~21:55 UTC, בקרה 2 → 3):** 01–09 באתר (כל אחת `conclusion: success`). **10 תפעול — בביקורת:** #1801 (ענף
  `claude/sforno-10-operations`), סבב 1 → שלושה 🟡 (`team/reviews/10-operations.md`); קוד 10 (`session_018g4NYDbzXpfJJokyWCbTxY`) מתקן
  בענף `claude/sforno-10-fix` ויודיע. נשארה **11 השקה**.
- **הצעד הבא, לפי הסדר:**
  1. כשמגיע PR התיקון של 10: לאמת בעצמך (סבב 2 קטן — בלי סוקר), להריץ run.php/check-apps/sync --check/php -l/node --check.
  2. מיזוג אחד דרך PR של `claude/sforno-control` (#1799 פתוח, טיוטה): `git merge origin/main`, `origin/claude/sforno-10-operations`,
     `origin/claude/sforno-10-fix`, `origin/claude/sforno-tasks` → הכרעות 10 ל-decisions (16 + של התיקון, ב-`team/log/code.md`) → ready → merge (merge commit).
  3. `send_later` 8 דקות → `conclusion: success` → STATE → PR טיוטה חדש מהענף → למטלות: SF-010 + בדיקת 403 ל-`sforno/data/backups/<name>.zip`.
  4. קוד 11 (`docs/spec/11-launch.md`, ענף `claude/sforno-11-launch`): השער לא עובד בסאב-דומיין — בקשה לפלטפורמה ב-`.claude/teams/requests/`
     (ש1: "כן, עם אופציה להפרדה"); פתיחת `read-*`/`guest`/`account` ל-public. סוקר עומק (פתיחה לציבור). פרומפט כמו של 10 (ראו sessions — קוד 10).
  5. המחזור לכל פרוסה: ביקורת (סוקר עומק כשיש public/סודות/ספק/מחיקה) → 🟡 לענף `-fix` → מיזוג אחד → success → הבאה.
- **מה פתוח:** #1799 (בקרה, טיוטה) + PR טיוטה של `claude/sforno-tasks` — מצטרפים ל-10. #1801 (קוד 10) + התיקון.
- **ממתין לבעלים (מטלות 1–9, אין תשובה עדיין):** 🔴 cron (בלעדיו אין תזכורות בזמן); WooCommerce (מפתח Read — ניהול ← חנות ← 🔑); Google (מזהה לקוח); בדיקות SF-002…SF-009. חומרי §24 — עד שיגיעו, נתוני דוגמה.
- **לקחים:** (1) בדיקה שמשווה מחרוזת libxml נכשלת ב-runner — לבדוק דרך DOM. (2) מיזוג אחד לכמה PRs = פריסה אחת. (3) force-push חסום — תמיד `git merge origin/main` לענף ו-push רגיל. (6) צ'אטי קוד לא תמיד שולחים הודעה כשה-PR מוכן — `get_session` של הקוד בכל סבב. (5) בדיקה שתלויה בגרסת ICU/ספרייה של הסביבה — לשמור לפי גרסה (07). (4) מ-06: שינוי בקובץ מעטפת של ספורנו = `SF_UPDATE_SW=1 php sforno/tests/run.php`, אחרת j-offline חוסם את הפריסה של כל הריפו.
- **ההשקה (11):** השער לא עובד בסאב-דומיין — דרושה בקשה לפלטפורמה (`.claude/teams/requests/`) כשמגיעים לשם; ש1: "כן, עם אופציה להפרדה".
- **רקע:** הריפו הישן `malkiel01/sforno` — קוד ישן כרקע בלבד, לא נמחק. `sforno.mbe-plus.com` קיים.
- **מכסה:** אזהרה שבועית עד ~27.11. Opus בלבד, מעט סוכני משנה, מעט מיזוגים.
- **צוות:** בקרה (3) `session_018g4NYFaERcGPs7d3j8QKsZ` (מ-21:52; בקרה 2 `session_018g4NYE5chJ7yafGbETDiNn` — לארכב אחרי שהשעון עבר); בקרה 1 `session_018g4NYGA2fhgWP6PkcJ8ZCx` — לארכב); אפיון `session_018g4NYF1YtreoYJt48ZHQ52` (רדום);
  מטלות `session_018g4NYFB9byAhqYVJfEq9nW`; קוד 01/02/03 — סיימו; קוד 04 — סיים; קוד 05 — סיים; קוד 06 — סיים; קוד 07 — סיים; קוד 08 — סיים; קוד 09 — סיים; קוד 10 `session_018g4NYDbzXpfJJokyWCbTxY`. שעון `trig_01K2CyS2tym5Vs811ugPEx73` (דקה 51, עדיין לבקרה 2 — מנהל הצוותים מעביר); הישן `trig_01VBKifEEXot2izcpMcFX6Ct` כבוי.