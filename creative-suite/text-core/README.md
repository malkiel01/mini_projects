# עיצוב הטקסט המשותף (`text-core`)

עברית בכל החבילה מצוירת אותו דבר: **HarfBuzz** ממקם כל אות, סימן ניקוד וטעם, ו-**bidi** (UAX #9) קובע את הסדר בשורה
מעורבת. הדפדפן לא מודד ולא מצייר טקסט — כל גליף הוא נתיב מהגופן עצמו. כך המסך, ה-PDF וקווי המתאר זהים בכל דפדפן.

נחלץ מהעימוד (`layout-studio/assets/js/shaper.js`, #917). פריט **מ2** ב-`docs/spec/work-order.md`.

## מבנה

```
src/js/shaper.js             המודול (ES module, בלי תלויות חוץ מ-vendor)
src/js/vendor/harfbuzzjs/    HarfBuzz 15.0.0 ב-WASM (harfbuzzjs 1.6.4, MIT) — נטען רק בטעינת הגופן הראשון
src/js/vendor/bidi-js/       bidi-js 1.1.0 (MIT, UAX #9)
tests/*.test.mjs             node --test creative-suite/text-core/tests/*.test.mjs — גופנים מ-suite-cloud/fonts/builtin
tools/sync.php               מעתיק את src/js לאפליקציות שב-apps.json; ‎--check — שער בפריסה
apps.json                    מי משתמש
```

**עורכים רק כאן**, מריצים `php creative-suite/text-core/tools/sync.php`, ומעלים את המקור ואת העותקים באותו commit.
עותק ששונה בתוך אפליקציה — הפריסה נעצרת.

## הצטרפות

1. `apps.json`: `"<app>": { "js": "assets/js/text-core" }` — תיקייה שכולה של המודול (הסנכרון מוחק בה קבצים זרים).
2. `php creative-suite/text-core/tools/sync.php`.
3. `import { loadFont, shapeLine, drawGlyphs } from './text-core/shaper.js';` — בלי `import` מחוץ לתיקיית האפליקציה.
4. אם יש `"gate": true` — שום דבר לא נדרש: הקבצים נטענים מאותו מקור, עם עוגיית המשתמש.

## ה-API

יחידות: **יחידות הגופן** (`upem`) בגליפים; **יחידות של `size`** (נקודות, פיקסלים — מה שתבחרו) בכל מה שמקבל `size`.
סדר הגליפים תמיד **חזותי, משמאל לימין** — גם בעברית.

| פונקציה | מה |
| --- | --- |
| `await loadFont(src, name?)` → `font` | TTF / OTF (לא WOFF2 — HarfBuzz קורא את הטבלאות). `src` — כתובת או `ArrayBuffer`. `name` — מפתח למטמונים |
| `fontVariant(font, { wght: 700 })` → `font` | עותק בציר משתנה אחר (מודגש), מאותו קובץ, בלי טעינה |
| `shapeLine(fonts, text, base = 'rtl', spans = null)` → `glyphs` | **הלב.** שורה עם bidi. `fonts` — גופן או `[ראשי, חלופי…]`: אשכול (אות + סימניה) שחסר בראשי מעוצב כולו בחלופי הראשון שמכסה אותו (טעמים ב-Frank Ruhl → Noto). `spans: [{ start, end, fonts }]` — טווחים בגופן אחר (מודגש), בלי לשבור את ה-bidi. מקף בין ספרות ("3–4") לא מתהפך |
| `shape(font, text, dir?)` → `glyphs` | רצף אחד בכיוון אחד, בלי bidi ובלי גופן חלופי |
| `advance(glyphs)` | רוחב ביחידות הגופן |
| `fontMetrics(font)` → `{ upem, ascender, descender, lineGap }` | יחידות הגופן; `descender` שלילי |
| `measure(fonts, text, size, base?, spans?, glyphs?)` → `{ width, ascent, descent, lineGap, glyphs }` | מדידת שורה ביחידות של `size`; `descent` חיובי. `glyphs` — אם כבר עוצבה |
| `drawGlyphs(ctx, font, glyphs, x, y, size)` | ציור על קנבס. `(x, y)` — תחילת קו הבסיס משמאל |
| `glyphPath(font, gid)` → `Path2D` | נתיב גליף, יחידות הגופן, y למעלה, במטמון (דפדפן בלבד) |
| `glyphOutline(font, gid)` → `[{ type, values }]` | קווי מתאר כפקודות `M L Q C Z`, יחידות הגופן, y למעלה, במטמון. עובד גם ב-Node |
| `glyphsToPath(font, glyphs, x, y, size)` → `d` | **טקסט לקווי מתאר:** `d` של SVG, פקודות **מוחלטות** `M L Q C Z`, **y למטה** — כמו `path` בפורמט וב-`pdf-core`. אותו מיקום בדיוק כמו `drawGlyphs` |
| `textToPath(fonts, text, { size, x, y, base, spans })` → `{ d, width }` | קיצור: `shapeLine` + `glyphsToPath` |

גליף: `{ gid, cluster, ax, dx, dy, f? }` — `cluster` אינדקס בטקסט המקורי; `ax` התקדמות; `dx` / `dy` היסט (**dy למעלה**,
כמו HarfBuzz וכמו `glyphs` של `pdf-core`); `f` — הגופן החלופי, כשיש (המידות כבר ביחידות של הראשי).

### ל-PDF

רשימת הגליפים עוברת ל-`pdf-core` כמו שהיא: `{ op: 'glyphs', font, size, glyphs: glyphs.map(g => [g.gid, g.dx*s, g.dy*s, g.ax*s]) }`
עם `s = size / upem` — **אותו קובץ גופן** שעוצב איתו. גליף מגופן חלופי — ב-op נפרד בגופן שלו (ראו `layout-studio/assets/js/pdf.js`).
טקסט שחייב להיות קווי מתאר (בתוך ציור) — `textToPath` → `{ op: 'path', d }`.

## גופנים מהקטלוג המשותף

WOFF2 לא נטען (HarfBuzz קורא טבלאות TTF). את הקובץ נותן `ttfFile(family, weight)` של `ui-core` (`fonts.js`, הקבצים ב-
`ui-core/src/fonts-ttf`, סוג `"fonts-ttf"` ב-`ui-core/apps.json`, #1074) → `{ file, variable, weight }`. כש-`variable`
(Heebo, Assistant, Rubik, Frank Ruhl) — `loadFont` פעם אחת, ולכל משקל `fontVariant(font, { wght: weight })`.
המעבר: `docs/shared-code/migrate/fonts.md`.

שגיאות מתחילות ב-`text-core:` ובעברית.

## מה עוד לא כאן

- **טקסט על נתיב** — תלוי ב-מ1 (`geometry-core`: אורך, נקודה ומשיק על נתיב). בתיאום עם וקטורי.
- **שבירת שורות ופסקאות** — נשארות אצל כל אפליקציה (העימוד: `flow.js`; עורך התמלילים: שלו).

## מי משתמש

| אפליקציה | יעד | מאז |
| --- | --- | --- |
| `layout-studio` | `assets/js/text-core` | החילוץ (מ2) — העימוד עבר מיד לעותק המסונכרן, כדי שאין שני מקורות |

שאר האפליקציות מצטרפות בתור שלהן (`work-order.md` §3).
