---
name: devloop
description: DevLoop — a work loop between a developer and Claude that lives inside the app being built — every request is an item with a code (PM-017), a thread with screenshots, "how to test" steps, evidence (PR, deploy, commit), and a log that is always connected — and it closes only when the developer verifies it. Use this whenever the user says "תקרא את המטלות", "מה פתוח", "תענה לי במטלות", "יש הערות במערכת", "עניתי לך שם", "תפתח מטלה", mentions an item code like PM-012, pastes text copied with "📋 העתקה לצ'אט", reports a bug from the 🐞 button, asks why something failed ("תבדוק ביומן"), asks to add a tasks / bug-report / dev-log system to an app (any project, not only this repo), or after deploying a change to an app that has DevLoop — to hand it over for verification. Never close items yourself; the developer's ✅ is what closes an item.
---

# DevLoop — בקשה → עבודה → פריסה → אימות של המפתח

המטלות חיות **בתוך האפליקציה** (מסך "📋 מטלות" למפתח), לא בצ'אט: לכל מטלה קוד קבוע, שיח עם צילומים,
"✔ איך יודעים שזה עובד", "🧪 איך בודקים", ראיות, ומצב — ו**יומן מחובר**: מכל מטלה רואים מה קרה בשרת ובדפדפן,
ומכל שגיאה ביומן מגיעים למטלה שלה (או פותחים אחת). **"סגור" = המפתח אימת אצלו** — לא "נפרס" ולא "אמור לעבוד".

## קודם: איפה DevLoop בפרויקט הזה

קרא את **`.devloop.json`** בשורש הריפו — אילו אפליקציות, הקידומת (`PM-`), כתובת ה-API, מסך המטלות והיומן, קובץ
המטלות בקוד, ושם משתנה הסביבה של האסימון. אין קובץ כזה?
- המשתמש מבקש להוסיף DevLoop לאפליקציה → **`references/install.md`** (הקוד ב-`assets/`).
- אחרת — שאל איפה האפליקציה, ואל תנחש.

הוראות של הפרויקט עצמו (CLAUDE.md, README) גוברות על הסקיל בכל מה שנוגע לענפים, פריסה, ואיפה עורכים את המודול.

## המצבים — ומי מזיז כל אחד

| מצב | המשמעות | מי מעביר לשם |
| --- | --- | --- |
| ⚠️ `triage` לבירור | שגיאה מהיומן שנפתחה לבד (אחת לכל חתימה, עם מונה) | המערכת. המפתח: 👍 לטפל / 🙈 להתעלם |
| 🟡 `open` פתוח | מחכה לעבודה | המפתח (מטלה, 👍, "+ מטלה" מהיומן), או Claude |
| 🔵 `in_progress` בעבודה | Claude עובד; או ↩️ — המפתח החזיר | Claude, או ↩️ |
| ❓ `question` שאלה | Claude צריך החלטה | Claude — **עם השאלה בשיח** |
| 🧪 `verify` ממתין לאימות | נפרס, עם צעדי בדיקה | הפריסה (קוד בהודעת ה-commit), או Claude |
| ✅ `closed` סגור | **המפתח אימת** | רק המפתח (✅ עובד אצלי) |

אל תעביר ל-`closed`. אל תעביר ל-`verify` בלי צעדי בדיקה.

## הלולאה

1. **לקרוא** — `devapi-feed` (רק הערות חדשות), `&all=1` (הכול), `&code=PM-012` (אחת — **כולל השורות שלה ביומן**).
   `&mark=1` — רק אחרי שענית. פקודות: `references/api.md`. אין אסימון? בקש: "📋 מטלות ← 🔑 גישה ל-Claude ← +
   אסימון חדש", או את הטקסט מ"📋 העתקה לצ'אט".
2. **לסדר** — ↩️ קודם (שבור אצלו), אחר כך תשובות לשאלות שלך, ואז לפי עדיפות. ⚠️ לבירור — לא לפני 👍 שלו (אפשר
   להציע בשיח מה הסיבה, לפי היומן).
3. **להבין מהיומן, לא לנחש** — בדיווח 🐞 ובשגיאה יש מזהי בקשה (`X-Request-Id`); השורות שלהם — בפיד של המטלה
   (`log`): הודעה, קובץ:שורה, משתמש, משך. `references/log.md`.
4. **לעבוד** לפי הוראות הפרויקט. **קוד המטלה בכותרת ה-commit / PR** (`… (PM-018)`) — זה מה שמחבר פריסה למטלה.
5. **לתעד** — מטלה מהקובץ: `title`, `detail`, `acceptance`, `steps`, `status` + `status_at` (`references/items-file.md`).
6. **להעביר לאימות** — אחרי פריסה מוצלחת: שלב ה-CI (`assets/ci/github-actions.yml`) מצרף ראיות ומעביר ל-🧪; בלעדיו —
   `devapi-comment` עם `status: "verify"`, `evidence`, `steps`. ותמיד **תגובה בשיח**: מה השתנה ואיך בודקים בטלפון.
7. **↩️ חזר?** ההערה שלו (וצילום — `devapi-file`) היא דיווח באג: יומן → שחזור → תיקון → שלב 4.

## "איך בודקים" ותשובה בשיח

- צעדים **שהמפתח עושה במסך**, 2–5, והאחרון — מה אמור לקרות. `acceptance` — משפט אחד מנקודת המבט שלו.
- בשפה שלו, קצר, בלי ז'רגון: מה עשיתי · מה לבדוק · מה עוד פתוח. שאלה — אחת, עם ההמלצה שלך.
- כל תגובה שולחת לו התראה (מייל) — תגובה אחת מסודרת, לא כמה קטנות.

## להוסיף DevLoop לאפליקציה

`references/install.md`: הקוד (`assets/devloop.php`, `devloop.js`, `devloop.css`), דוגמה עובדת (`assets/example/`),
החיבור (`devloop_setup` / `DevLoop.setup`), **היומן** (המובנה, או היומן שכבר יש לאפליקציה), ה-CI, ו-`.devloop.json`.
מערכת שאינה PHP — `references/protocol.md` (החוזה ב-HTTP, כדי לממש אותו בכל שפה).
