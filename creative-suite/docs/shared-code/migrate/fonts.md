# מעבר לגופנים של ui-core (שלב 8) — כל החבילה

**מאת:** הקוד המשותף · **תאריך:** 2026-11-01
**המודול:** `creative-suite/ui-core/src/js/fonts.js` + הקבצים `src/fonts/` (78 קובצי woff2 + `fonts.css`, ‎~1MB) + `ui.css` (`.fp-*`).
**המקור:** `fonts.js` של סטודיו העיצוב (#904, #1005). **הכרעת בעל המערכת:** "רשימה אחת וקבצים אחד לכולן".
**תצוגה:** `creative-suite/ui-core/demo/` — "בורר גופנים". **שורה ב-`integration.md`:** 14.

## מה יש

| | |
| --- | --- |
| `FONTS`, `DEFAULT_FONT`, `nearestWeight`, `fontStack(family)` | הקטלוג — עשרה גופנים עבריים חופשיים (OFL) |
| `ensureFont(family, weight)` | **לפני ציור בקנבס** — אחרת הקנבס מצייר בגופן חלופי ולא מתעדכן |
| `registerFont`, `readFontInfo(file)`, `fontMime` | גופן מקובץ: שם ומשקל מטבלאות name / OS/2 (TTF, OTF, WOFF), אחרת משם הקובץ |
| `userFonts({ key, assets })` | "הגופנים שלי" במכשיר — `load`, `add(file)`, `list`, `remove`, `syncCloud(drives)`, `saveToCloud(drives, id, entry)` מול ספריית הגופנים של ענן החבילה |
| `fontPicker(label, value, onChange, { sample, onUpload })` | בורר: כל גופן כתוב בגופן עצמו, חיפוש, מקלדת, "שלי" (★) למעלה, העלאה |

## איך מצטרפים

1. **`apps.json`** — סוג חדש `"fonts"`: `"vector-studio": { …, "fonts": "assets/fonts/ui-core" }`. התיקייה כולה של ui-core.
2. ב-HTML: `<link rel="stylesheet" href="assets/fonts/ui-core/fonts.css">` — הדפדפן מוריד קובץ רק כשהגופן בשימוש.
3. אפליקציה עם service worker (הרסטר): **לא** להכניס את 78 הקבצים לרשימת ההתקנה — רק `fonts.css`; הקבצים במטמון "רשת קודם"
   כשהם נטענים.
4. **`.htaccess` / סוגי קבצים:** `woff2` → `font/woff2` (בשרת שלנו כבר מוגדר; לבדוק בעין שהגופן נטען בטלפון).

## סטודיו העיצוב — המקור

- `editor/fonts/` + `css/fonts.css` → `"fonts": "editor/fonts/ui-core"` ו-`<link>` אחד. למחוק את העותק שלך.
- `fonts.js` → `export * from './ui-core/fonts.js'` + שורה אחת: `const mine = userFonts({ key: 'gc-my-fonts', assets })`
  (`assets` של `db.js` — כבר `get` / `put`); `myFonts` → `mine.list`, `addMyFont` → `mine.add`, `syncCloudFonts` →
  `mine.syncCloud`, `saveFontToCloud` → `mine.saveToCloud`, `removeMyFont` → `mine.remove`. `ensureDocFonts` ו-`embedDocFonts`
  — שלך (תלויים במבנה העיצוב).

## וקטורי, עימוד, רסטר (טקסט)

`<select>` של גופנים → `fontPicker`. לפני ציור טקסט — `await ensureFont(family, weight)`. גופן שהמשתמש העלה —
`userFonts({ key: '<app>-my-fonts', assets: kv('<app>') })` (store.js).

## עורך התמלילים

`fontPicker` בסרגל העיצוב במקום ה-`<select>`; `fonts.css` במקום ה-@font-face שלך (אם יש). PDF: הקבצים זמינים באותה תיקייה
להטמעה (pdf-core) — `woff2` צריך פריסה ל-TTF בצד השרת; עד אז — אותו מנגנון שיש לך היום.

## אחרי המעבר

- `php creative-suite/ui-core/tools/sync.php --check`
- לסמן את התא שלך בשורה 14 של `creative-suite/docs/integration.md` באותו PR.
