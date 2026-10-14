---
name: devloop
description: The work loop between the user and Claude on the apps in mini_projects (project-manager today, more later) — every request is an item with a code (PM-017), a thread, "how to test" steps and evidence, and it closes only when the user verifies it on their phone. Use this whenever the user says "תקרא את המטלות", "מה פתוח", "תכלס מה פתוח", "תענה לי במטלות", "יש הערות במערכת", "עניתי לך שם", "תפתח מטלה", mentions a code like PM-012, pastes text copied with "📋 העתקה לצ'אט", reports a bug from the 🐞 button, or asks to add the tasks / DevLoop system to another app — and also after finishing and deploying any change to a DevLoop app, to hand it over for verification. Don't close items yourself and don't skip the steps; the user's ✅ is what closes an item.
---

# DevLoop — הלולאה: בקשה → עבודה → פריסה → אימות שלך

כל מה שפתוח בין המשתמש ל-Claude חי **בתוך האפליקציה** (הגדרות ← 📋 מטלות), ולא בצ'אט: לכל מטלה קוד קבוע,
שיח עם צילומים, "✔ איך יודעים שזה עובד", "🧪 איך בודקים", ראיות (PR, פריסה, commit), ומצב. **"סגור" = המשתמש אימת
אצלו** — לא "נפרס", ולא "אמור לעבוד". הקוד: `devloop/` בשורש הריפו (`devloop/README.md`).

## המצבים — ומי מזיז כל אחד

| מצב | המשמעות | מי מעביר לשם |
| --- | --- | --- |
| ⚠️ `triage` לבירור | שגיאה מהיומן שנפתחה לבד | המערכת. המשתמש מחליט: 👍 לטפל / 🙈 להתעלם |
| 🟡 `open` פתוח | מחכה לעבודה | המשתמש (מטלה חדשה, 👍), או Claude |
| 🔵 `in_progress` בעבודה | Claude עובד; או ↩️ — המשתמש החזיר | Claude, או ↩️ של המשתמש |
| ❓ `question` שאלה אליך | Claude צריך החלטה | Claude — **עם השאלה בשיח** |
| 🧪 `verify` ממתין לאימות | נפרס, עם צעדי בדיקה | **אוטומטית אחרי פריסה** (קוד בהודעת ה-commit), או Claude |
| ✅ `closed` סגור | **המשתמש אימת** | רק המשתמש (✅ עובד אצלי) |

אל תעביר ל-`closed` בעצמך. אל תעביר ל-`verify` מטלה בלי צעדי בדיקה.

## הלולאה

1. **לקרוא.** `devapi-feed` — רק מה שחדש מאז הפעם הקודמת (הערות של המשתמש שלא נענו); `?all=1` — הכול;
   `?code=PM-012` — אחת. `&mark=1` מסמן כנקרא — רק אחרי שענית. הפקודות: `references/api.md`.
   אין אסימון? בקש מהמשתמש: "📋 מטלות ← 🔑 גישה ל-Claude ← + אסימון חדש", והוא מדביק לך. או שהוא מדביק
   את הטקסט מ"📋 העתקה לצ'אט".
2. **לסדר.** קודם ↩️ (המשתמש החזיר — משהו שבור אצלו), אחר כך תשובות לשאלות שלך, ואז פתוחות לפי עדיפות.
   ⚠️ לבירור — לא נוגעים לפני 👍 של המשתמש (אפשר להציע בשיח מה לדעתך הסיבה).
3. **לעבוד** לפי `CLAUDE.md` של הריפו: ענף מ-`main`, commit, PR, מיזוג, ואימות `conclusion: success`.
   **קוד המטלה בכותרת ה-commit / PR** (`… (PM-018)`) — זה מה שמחבר את הפריסה למטלה.
4. **לתעד בקוד.** מטלה שמנוהלת בקובץ (`<app>/dev/open-items.json`): עדכן `title`, `detail`, `acceptance`,
   `steps`, ו-`status` + `status_at` (ISO, **לא בעתיד**) — `references/items-file.md`.
5. **להעביר לאימות.** אחרי פריסה מוצלחת, שלב "DevLoop — ראיות פריסה" ב-`deploy.yml` מצרף 🚀 פריסה, 📌 commit ו-🔀 PR
   ומעביר ל-🧪 — כשהסוד `PM_DEVLOOP_TOKEN` מוגדר. בלעדיו: `devapi-comment` עם `status: "verify"`, `evidence`,
   ו-`steps`. בכל מקרה — **תגובה בשיח**: מה השתנה, במילים של המשתמש, ו"איך בודקים" בטלפון, צעד אחר צעד.
6. **↩️ חזר?** ההערה שלו (ולפעמים צילום — `devapi-file?id=`) היא דיווח באג: שחזר, תקן, וחזור לשלב 3.
   אל תתווכח עם "לא עובד אצלי" — הוא רואה מה שאתה לא רואה.

## איך כותבים "איך בודקים"

- צעדים **שהמשתמש עושה בטלפון**, לא פקודות: "פתחו פרויקט ← קבצים ← לחצו על צילום ← …".
- 2–5 צעדים; הצעד האחרון — מה אמור לקרות ("מופיע גם אצל קבלן א' וגם אצל קבלן ב'").
- אם צריך מידע להתחיל (פרויקט, ספק) — כתוב איזה.
- `acceptance` — משפט אחד: איך יודעים שזה עובד, מנקודת המבט שלו.

## לענות בשיח

- בעברית, קצר, בלי ז'רגון. מה עשיתי · מה לבדוק · מה עוד פתוח.
- שאלה אליו — שאלה אחת ברורה עם ההמלצה שלך, ו-`status: "question"`.
- 🐞 דיווח מגיע עם `context` (מסך, גרסה, הבקשות האחרונות עם מזהה `X-Request-Id`, שגיאות): חפש את המזהה ביומן
  (`log.php`) לפני שאתה מנחש.

## אפליקציה חדשה ל-DevLoop

`references/new-app.md` — שורה ב-`devloop/apps.json`, `php devloop/tools/sync.php`, קובץ חיבור (`devloop_setup`),
הפעולות בנתב, `DevLoop.setup` / `mount` / `show`, `--exclude` לצילומים ב-rsync, ו-`#/settings/tasks/<קוד>`.
**לא עורכים את העותקים** (`lib/devloop.php`, `assets/js/devloop.js`, `assets/css/devloop.css`) — רק את `devloop/src`,
ואז sync; שער בפריסה עוצר עותק ששונה.

## התראות

- המשתמש מקבל מייל כשמשהו מחכה לו (🧪, ❓, 💬, ⚠️) — ולכן כל `devapi-comment` עם `body` שולח מייל. תשובה אחת
  מסודרת עדיפה על כמה קטנות.
