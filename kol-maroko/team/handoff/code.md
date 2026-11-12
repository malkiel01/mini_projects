# קול מרוקו — מסירה בין צ'אטי הקוד

מה שצ'אט הקוד הבא צריך לדעת. מוסיפים בסוף, לפי פרוסה.

## מ-V3 (נמסר דרך בקרה (3), 2026-11-12)

1. כל כתיבת עיצוב — `writeStyle(per, label)` ב-`screen.js`: `Map<אלמנט, [[מאפיין פיזי, ערך | null, יעד]]>`; כל אלמנט / יעד
   מתוכנן מחדש על הטקסט העדכני (`resolveTarget`); כל הקבצים — עסקה אחת (`pushTx`); ביטול / החזרה — `undoTx` / `redoTx`.
   גם `commit()` ו-`remove()` רושמים עסקה.
2. `Styler` (`style.js`): `targets`, `source`, `visible`, `plan(n, t, sets)` ← תיקונים לכל קובץ. **`checkValue` (דוחה `<`,
   `CSS.supports`) — חובה בכל מסלול כתיבה חדש.** כתיבה: `planRule`, `setComponent`, `planNewRule`, `planInline`.
3. תצוגה בזמן גרירה — DOM בלבד (`preview` / `unpreview`, `style` עם `!important` בקנבס, מוחזר בשחרור). לקובץ — רק `writeStyle`.
4. ידיות (`spacing.js`) — רק בלשונית "מתקדם" או 📏 / `M`. להרחבה: `targetFor`, `gapOf`, `whyNode`.
5. בחירה מרובה: `canvas.picked()`, `toggleMulti`.
6. ערך מ-`@media` נערך שם (`Design.effTarget`).
7. פתוח: `url()` בתוך `var()`; הביטול המשותף נשבר אחרי עריכה בעורך הקוד באמצע; Safari / Firefox / iOS לא נבדקו.

## מ-V5 (2026-11-12)

1. רקע — `editor/background.js`: חלק טהור (`KolBg.*`, נבדק ב-`tests/editor/background.mjs`) ו-`Bg` בדפדפן (`S.bg`).
   **כל url() שנכתב — דרך `urlCss` / `checkUrl`**, לעולם לא בשרשור.
2. כתיבה שאינה "מאפיין ← ערך" (הסרת שכבה, מחיקת כלל, HTML + CSS): `Bg.ruleOps(r, fn)` ← `screen.writeOps(list, label)`.
3. `covered('background')` מחזיר עכשיו את כל הרכיבים — `planRule` על רכיב של קיצור כותב הצהרה מפורשת אחריו.
4. `details.collect(n, { pseudo: 'before' })` — הכללים של פסאודו-אלמנט.
5. כשהפאנל סגור (טלפון) `Design.t` לא מאותחל — לפני `D.put` מחוץ לפאנל: `Bg.ready(n)`.

## מ-V6 (2026-11-12, חלקי — ראו team/log/code-V6.md "❌")

1. `editor/position.js` (`S.pos`) — מיקום חופשי ושכבות; `editor/layout.js` (`S.lay`) — פריסה, גודל, ידיות גודל. שניהם
   מוסיפים קבוצות ללשונית "מתקדם" דרך `S.adv.extra`.
2. `screen.joinTx(k, label)` — מאחד k עסקאות אחרונות (HTML שהוזז דרך `act` + עיצוב דרך `writeStyle`).
3. גיאומטריה למיקום — **אחרי** שהאלמנט יצא מהזרימה (preview של position:absolute), כי הייחוס עשוי להתכווץ.
4. ידיות הגודל — `prepend` לשכבה (מתחת ל-`.sp` של V3); אחרת הן מכסות את ידית הרווח (v3.cjs נכשל).
