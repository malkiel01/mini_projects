# יומן קוד — V10 (אפקטים ותנועה)

- 2026-11-12 · ענף `claude/kol-maroko-v10` מ-main (086967e, V9 בפנים). קראתי CLAUDE.md, PROTOCOL, handoff (V3–V6b, P1; ל-V7–V9 אין
  סעיף ב-handoff — נקרא מהיומנים code-V7/V8/V9), ה-slice ו-03 §4.1–§4.4. אין `team/reviews/V9.md` בענף.

## הכרעות מראש

1. **מה נכתב איפה:** מסגרת / פינות / צל / מסננים / מסיכה / transform / מעבר / נדבק — לכלל היעד דרך `Design.put` (מכשיר V7, מצב V8,
   ⟲, Ctrl+Z — בחינם). "כל הצדדים" — `border: W S C` אחד (וההצהרות של הצדדים ביעד נמחקות); צד / פינה — מאפיין לוגי
   (`border-inline-start-width`, `border-start-start-radius`) — הרחבה של `covered` / `logical` ב-style.js.
2. **אנימציית כניסה:** מחלקה `kol-in kol-in-<שם>` + `--kol-in-dur/-delay/-ease` בכלל היעד; `@keyframes` ב-`css/motion.css` (נוצר
   בשימוש הראשון, `pf_create` + `<link>`; הביטול — לסל). בלי `js/kol-motion.js` — רצה בטעינה (CSS בלבד); עם הקובץ — `.kol-motion-on
   .kol-in:not(.is-in)` מושהית עד `is-in`. אף פעם לא תוכן שקוף. גלילה — `kol-sc` + `--kol-sc-*`, keyframes אחד עם var().
3. **בדיקת הזרקה:** כל ערך מכאן — `KolFx.checkFx` (בלי `< > { } ; \`, הערות, `javascript:`, `expression(`, `@import`; `url(` רק מבחירה
   בספרייה, דרך `urlCss`/`checkUrl`) ואחריו `checkValue`. שמות keyframes / מחלקות / צורות — רק מרשימות קבועות; SVG של צורה — `checkSvg`.
4. **קבצים מחוץ לגבולות ה-slice:** `screen.js` (רשימת הסקריפטים, החיבור, כפתור ▶ — כמו כל פרוסת V), `extra.js` (`CONTROLLED` — לפי
   ה-handoff של V8), `assets/css/editor.css` (מראה הידיות והקבוצות). נאמר לבקרה.

## עבודה

- 2026-11-12 · יחידה 1: `effects.js` (טהור: checkFx, צל, מסננים, transform, עקומות, כניסות, מצגת, motion.css, צורות; ממשק: מסגרת
  ופינות עם נעילה, צל קופסה / טקסט בשכבות (⠿, ☀ + גלגלת), קו מתאר, ידיות רדיוס (Alt — פינה אחת)), `motion.js`, `canvas.js`
  (אנימציות מושהות — `animation: none` של העורך; `setPlay`), `design.js` (רישום), `style.js` (border / radius / outline ב-covered,
  logical, lineParse), 12 צורות ב-`assets/editor-shapes/`, `assets/project-seed/kol-motion.js`. v10.cjs חלק 1 — 19/19.
