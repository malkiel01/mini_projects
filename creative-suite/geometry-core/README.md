# מנוע הגאומטריה המשותף (`geometry-core`)

נתיבי בזייה, מטריצות, צורות חיות, נקודות עיגון, פעולות צורה (בוליאניות), פגיעה, וקריאת SVG עם נרמול נתיבים לפורמט
‎`.mbe` (M L C Q Z מוחלטים). JS בלבד, **בלי DOM** — רץ בדפדפן, בעובד רקע וב-Node. נחלץ מהווקטורי (הבעלים), פריט מ1
ב-`docs/spec/work-order.md`. המודל שהוא כותב: `docs/mbe-format.md` סעיף 3, "תוכן שכבה `vector`".

## מבנה

```
src/js/        path · matrix · shapes · nodes · boolean · hit · measure · svg  (+ vendor/polygon-clipping, MIT — רק ל-boolean)
tests/         geometry.test.mjs · svg-hit.test.mjs · measure.test.mjs — node --test creative-suite/geometry-core/tests/*.test.mjs
tools/sync.php מעתיק את src/js לאפליקציות שב-apps.json; ‎--check — שער בפריסה (וגם הבדיקות רצות שם)
apps.json      האפליקציות שמשתמשות. אפליקציה נכנסת בתור שלה (שלב ב), בהסכמת הצ'אט שלה
```

**עורכים רק כאן**, מריצים `php creative-suite/geometry-core/tools/sync.php`, ומעלים את המקור ואת העותקים באותו commit.
עותק ששונה בתוך אפליקציה — הפריסה נעצרת.

## הצטרפות

1. `apps.json`: `"<app>": { "js": "assets/js/geometry" }` — תיקייה שכולה של המנוע (הסנכרון מוחק בה קבצים זרים).
2. `php creative-suite/geometry-core/tools/sync.php`.
3. בקוד: `import { parse, bbox } from './geometry/path.js';` — כל קובץ לבד, וטוענים רק מה שצריך. `boolean.js` כבד
   (polygon-clipping) — כדאי `await import()` רק כשמפעילים פעולת צורה.
4. מוחקים את הקוד הכפול באפליקציה. המדריך: `docs/shared-code/migrate/geometry.md`.

## המוסכמות

- **מטריצה** — `[a, b, c, d, e, f]` כמו ב-SVG: `x' = a·x + c·y + e`, `y' = b·x + d·y + f`.
- **מקטע** — `{ c: 'M'|'L'|'C'|'Q'|'Z', p: [...] }`, נקודות מוחלטות. `segs` — מערך מקטעים.
- **`d`** — מחרוזת SVG. מה שהמודול כותב תמיד M L C Q Z מוחלטים, עד 3 ספרות אחרי הנקודה (`num`).
- **תיבה** — `[x, y, w, h]`. ציר y למטה, יחידה = pt.
- שגיאה (נתיב פגום, מטריצה לא הפיכה) — `throw new Error('geometry: …')` בעברית, עם שם המודול בהתחלה, כמו ב-ui-core.
- **`invert` זורק** על מטריצה לא הפיכה. `invert` של `ui-core/transform.js` מחזיר Infinity. אל תייבאו את שתיהן באותו
  קובץ באותו שם.
- `parseColor` (svg.js) מחזיר `'#rrggbb'` ונועד לקריאת SVG. צבע בממשק — `parseHex` של `ui-core/color.js` (`{r, g, b, a}`).

## ה-API

### `path.js` — נתיבים

| פונקציה | מה |
| --- | --- |
| `parse(d) → segs` | `d` כלשהו (יחסי, H V S T A) → מקטעים מוחלטים M L C Q Z. קשת → בזייה. זורק על תחביר שגוי |
| `serialize(segs) → d` | כתיבה |
| `normalize(d) → d` | `serialize(parse(d))` |
| `transform(segs, m) → segs` | מטריצה על כל הנקודות |
| `bbox(segs) → box \| null` | תיבה חוסמת מדויקת, כולל קיצוני העקומות |
| `unionBox(boxes) → box \| null` | איחוד תיבות (null מתעלמים) |
| `num(v) → string` | מספר קצר: 3 ספרות, בלי `-0` |

### `matrix.js` — מטריצות

`IDENTITY` · `multiply(m1, m2)` (קודם m2) · `apply(m, x, y) → [x, y]` · `invert(m)` · `translate(tx, ty)` ·
`scaleAt(sx, sy, ox, oy)` · `rotateAt(rad, cx, cy)` · `hasRotation(m)` · `isIdentity(m)`

### `shapes.js` — צורות חיות

| פונקציה | מה |
| --- | --- |
| `DEFAULTS` | פרמטרי ברירת מחדל: `rect` (radius), `ellipse`, `polygon` (sides), `star` (points, inner), `line` |
| `shapeToD(shape) → d` | `{ type, box, … }` (קו — `from` / `to`) → `d` |
| `shapeFromDrag(type, x0, y0, x1, y1, square)` | צורה מגרירה (Shift — ריבוע / עיגול) |
| `transformShape(shape, m)` | הזזה וקנה מידה על הפרמטרים (סיבוב — אופים ל-`d`) |
| `normBox(box)` | רוחב / גובה חיוביים אחרי גרירה הפוכה |

### `nodes.js` — נקודות עיגון לעריכה

תת-נתיב = `{ closed, nodes: [{ p, i, o }] }` (`i` / `o` — הידיות, `null` = פינה). Q נהפך ל-C שקול.

`toNodes(segs)` · `fromNodes(subs) → segs` · `segment(sub, k)` · `segmentCount(sub)` · `nearest(subs, pt) → { sub, seg, t, point, dist }`
(הנקודה הקרובה על הנתיב) · `splitAt(sub, k, t)` · `toggleSmooth(sub, idx)` · `removeNode(subs, si, idx)` ·
`moveHandle(node, 'i'|'o', to, keepSmooth)` · `moveNode(node, dx, dy)`

### `boolean.js` — פעולות צורה

| פונקציה | מה |
| --- | --- |
| `region(d, m, reg) → multipolygon` | האזור של נתיב אחרי `m` (xor של הטבעות — חורים נכונים) |
| `combine(kind, regions)` | `'union'` · `'subtract'` (הראשון פחות השאר) · `'intersect'` · `'xor'` |
| `toD(multi, reg) → d` | בחזרה ל-`d`. עם `reg` (Map מ-`region`) העקומות המקוריות חוזרות — עיגול נשאר עיגול |
| `rings(segs, tol, reg)` | שיטוח לטבעות סגורות |

### `hit.js` — פגיעה בלי קנבס

| פונקציה | מה |
| --- | --- |
| `pointInPath(segs, x, y, rule = 'nonzero')` | בתוך המילוי (`'evenodd'` — חורים) |
| `distanceToPath(segs, x, y) → number` | המרחק לקו המתאר |
| `hitPath(segs, x, y, { fill, rule, strokeTol })` | כמו בעורך: בתוך המילוי, או עד `strokeTol` מהמתאר |
| `flatten(segs, tol) → [{ pts, closed }]` | שיטוח לקווים |

בדפדפן עם קנבס `isPointInPath` של Path2D מהיר יותר. `hit.js` — כשאין קנבס, או כשצריך מרחק.

### `measure.js` — מדידה לאורך נתיב (לטקסט על נתיב)

| פונקציה | מה |
| --- | --- |
| `measure(segs) → { length, at(s) }` | לשאלות חוזרות. `at(s) → { x, y, angle, sub } \| null` — נקודה ומשיק במרחק `s` מההתחלה |
| `pathLength(segs) → number` | האורך: כל התת-נתיבים, כולל צלעות הסגירה של Z. הקפיצה של M לא נספרת |
| `pointAtLength(segs, s)` | שאלה אחת |

`angle` ברדיאנים, y למטה: 0 — ימינה, π/2 — למטה. `sub` — מספר התת-נתיב. `s` מחוץ ל-`[0, length]` — `null`, והקורא
מחליט (לחתוך, או להמשיך בקו ישר לאורך המשיק בקצה). עקומות — גאוס-לז'נדר ו-`s → t` בחיפוש בינארי. מדויק לאלפית נקודה.

### `svg.js` — קריאת SVG בלי DOM

| פונקציה | מה |
| --- | --- |
| `parseColor(v) → '#rrggbb' \| null \| undefined` | שם, `#rgb`, `rgb()` (גם באחוזים). `null` — none; `undefined` — לא מובן |
| `parseTransform(str) → m` | translate / scale / rotate (גם סביב נקודה) / skewX / skewY / matrix, לפי הסדר |
| `parseLength(v) → pt \| null` | px pt mm cm in pc. אחוזים — null |
| `viewBoxMatrix(viewBox, width, height, par) → { matrix, size }` | xMidYMid meet, או `none` — מתיחה |
| `elementToD(tag, attr) → d` | path / rect (גם מעוגל) / circle / ellipse / line / polyline / polygon. `attr(name)` — למשל `k => el.getAttribute(k)` |
| `toMbePath(d, m) → d` | `d` כלשהו → M L C Q Z אחרי מטריצה. זהה ל-`toMbePath` של הסטודיו, עד העיגול (3 ספרות במקום 2) |

**מה שנשאר באפליקציה:** הליכה על עץ ה-SVG (`DOMParser`), סגנון ותורשה, מעברי צבע, ואיך פריט נבנה — כי כל אפליקציה
בונה מודל אחר (וקטורי — צורות חיות; הסטודיו — שכבות Konva).

## מי משתמש

| אפליקציה | תיקייה | מאז |
| --- | --- | --- |
| וקטורי | `vector-studio/assets/js/geometry` | החילוץ (שלב א). הבדיקות של הגאומטריה עברו לכאן |

שאר האפליקציות (עימוד, סטודיו העיצוב, רסטר, עורך התמלילים) — בתור שלהן, שלב ב (`docs/spec/work-order.md` §3).
