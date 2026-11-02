# מעבר ל-`geometry-core` — לכל אפליקציה, בתור שלה (שלב ב)

**מאת:** וקטורי (בעלי המודול) · **תאריך:** 2026-11-02 · **המודול:** `creative-suite/geometry-core/` (ה-API ב-README שלו).
**כבר עבר:** וקטורי — `assets/js/geometry` הוא העותק המסונכרן.

**לא עכשיו.** בשלב א אף אפליקציה לא מצטרפת (`docs/spec/work-order.md` §3). המדריך מראה מה כל אחת תחליף כשיגיע
התור שלה, כדי שהמעבר יהיה בעיקר מחיקה. כל צ'אט מחליט בקוד שלו. הרשימה מבוססת על קריאה, לא על הרצה.

## הצעדים, בכל אפליקציה

1. שורה ב-`creative-suite/geometry-core/apps.json`: `"<app>": { "js": "<תיקייה>/geometry" }`.
2. `php creative-suite/geometry-core/tools/sync.php`.
3. מחליפים את הכפילויות (למטה), מריצים את הבדיקות של האפליקציה.
4. ✅ בשורה 22 של `docs/integration.md`, באותו PR.

## מה כל אחת מחליפה

| אפליקציה | הקוד שלה היום | ב-geometry-core |
| --- | --- | --- |
| סטודיו העיצוב | `editor/js/pathnorm.js`: `absolutize`, `toMbePath`, `mul`, `layerMatrix` | `parse` (path), `toMbePath` (svg), `multiply` (matrix). `layerMatrix(x, y, deg, sx, sy)` = `multiply(translate(x, y), multiply(rotateAt(deg·π/180), [sx, 0, 0, sy, 0, 0]))` |
| סטודיו העיצוב | `editor/js/svg-import.js`: `parseTransform`, `shapeD`, `NUMS` | `parseTransform`, `elementToD(tag, k => el.getAttribute(k))` (svg). צבע דרך הקנבס (`color`) — יכול להישאר; `parseColor` לא מכיר hsl ושמות נדירים |
| עימוד | `assets/js/vector.js`: `mul`, `ID`, טרנספורמציה של `d` בפריטים | `multiply`, `IDENTITY`, `transform` + `serialize` (path) |
| רסטר | `engine/vmask.js`: `mapPath` / `translatePath` / `scalePath` | `serialize(transform(parse(d), translate(dx, dy)))` / `scaleAt(k, k)`. שימו לב: `mapPath` מעגל ל-2 ספרות, המודול ל-3 |
| עורך התמלילים | אין גאומטריה היום | כשיגיעו צורות — `shapes.js` ו-`path.js` |

## מה לבדוק

- עיגול: המודול כותב עד 3 ספרות אחרי הנקודה. מי שמשווה מחרוזות `d` בבדיקות — יעדכן את הציפייה.
- `boolean.js` מושך את polygon-clipping (`geometry/vendor`). מי שלא צריך פעולות צורה — פשוט לא מייבא אותו.
