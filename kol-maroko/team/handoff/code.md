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
