# מנוע ה-PDF המשותף (`pdf-core`)

כותב PDF בלי תלויות (PHP + zlib, ו-GD לתמונות שאינן JPEG): עמודים, גופני TrueType מוטמעים עם עברית בת-חיפוש,
תמונות, ורשימת ציור למי שמעמד בעצמו. נחלץ ממעבד התמלילים (שבירת השורות שלו נשארת אצלו). הבקשה:
[`docs/requests/layout-studio-pdf.md`](../docs/requests/layout-studio-pdf.md). הכרעת המיקום — בעל המערכת, 2026-10-25.

## מבנה

```
src/php/       writer.php (PdfWriter) · font.php (TtfFont) · subset.php (TtfSubset) · draw.php (pdf_draw)
src/js/        draw.js (pdfDraw) — אותה רשימת ציור בדפדפן, בלי שרת (שלב 3)
tests/         draw.test.mjs — JS מול PHP: אותה רשימה, אותו PDF במבנה (רץ ב-CI של עורך התמלילים)
tools/sync.php מעתיק את src/php ו-src/js לאפליקציות שב-apps.json; ‎--check — שער בפריסה
apps.json      האפליקציות שמשתמשות. אפליקציה נכנסת רק בהסכמת הצ'אט שלה
```

**עורכים רק כאן**, מריצים `php creative-suite/pdf-core/tools/sync.php`, ומעלים את המקור ואת העותקים באותו commit.
עותק ששונה בתוך אפליקציה — הפריסה נעצרת.

## הצטרפות

1. מוסיפים ל-`apps.json`: `"layout-studio": { "php": "lib/pdf-core" }`, ו/או `"js": "assets/js/pdf-core"` — כל אחת
   תיקייה שכולה של המנוע (הסנכרון מוחק בה קבצים זרים).
2. `php creative-suite/pdf-core/tools/sync.php`.
3. בקוד: `require_once __DIR__ . '/lib/pdf-core/draw.php';` — או `import { pdfDraw } from './pdf-core/draw.js';`. בלי
   `require` / `import` מחוץ לתיקיית האפליקציה.

## רשימת ציור → PDF

```php
$pdf = pdf_draw($list, fn(string $key) => file_get_contents(...)); // $key — מה שברשימה ב-font / src; null — חסר
```

יחידות: נקודות (`pt`). y — מלמעלה למטה, מהפינה העליונה של העמוד (TrimBox). צבע: `"#rrggbb"`.

```json
{ "title": "…", "pages": [ { "size": [w, h], "bleed": 9, "ops": [
    { "op": "glyphs", "font": "media/….ttf", "size": 12, "color": "#000000", "x": 0, "y": 0,
      "glyphs": [[gid, dx, dy, adv]], "text": "…" },
    { "op": "image", "src": "media/….png", "box": [x, y, w, h], "clip": [x, y, w, h], "opacity": 1, "transform": [a, b, c, d, e, f] },
    { "op": "rect", "box": [x, y, w, h], "fill": "#…", "stroke": "#…", "width": 1 },
    { "op": "line", "from": [x, y], "to": [x, y], "color": "#…", "width": 1 },
    { "op": "path", "d": "M10 10 L90 10 C95 10 100 15 100 20 Q 50 50 10 10 Z", "transform": [a, b, c, d, e, f],
      "fill": "#…" | null, "fillOpacity": 1, "fillRule": "nonzero" | "evenodd",
      "stroke": "#…" | null, "strokeOpacity": 1, "width": 1, "cap": "butt", "join": "miter", "miter": 4, "dash": [] },
    { "op": "clip", "d": "M…", "fillRule": "nonzero", "transform": [a, b, c, d, e, f] },
    { "op": "restore" } ] } ] }
```

- **`size` / `bleed`:** `size` הוא העמוד (TrimBox). `bleed` מרחיב את MediaBox ו-BleedBox לכל צד; מותר לצייר בגלישה (x, y שליליים).
- **`glyphs`:** (x, y) — קו הבסיס. כל גליף: מזהה בגופן, היסט (dx ימינה, **dy למעלה** — כמו HarfBuzz) והתקדמות, בנקודות.
  הגליפים בסדר חזותי (משמאל לימין). `text` — הטקסט הלוגי, ל-`ActualText` (חיפוש והעתקה).
- **גופן:** TrueType (TTF או WOFF 1), **אותו קובץ** שעוצב איתו — מוטמע עם subset, ומזהי הגליפים נשמרים.
- **`path`** (שלב 1, #971)**:** `d` — פקודות **מוחלטות** בלבד, `M L C Q Z` (כמו בפורמט). `Q` הופך ל-`C`; זוגות נוספים אחרי
  פקודה — חזרה עליה (אחרי `M` — `L`). `transform` — מטריצה של SVG על הנקודות, ביחידות הרשימה (y למטה); העובי והמקפים
  עוברים בה, כמו ב-SVG. לפחות `fill` או `stroke`. `cap`: `butt` / `round` / `square`; `join`: `miter` / `round` / `bevel`.
- **`image` עם `transform`** (בקשת וקטורי)**:** מטריצה של SVG ביחידות הרשימה, כמו ב-`path` — חלה על `box` ועל `clip`
  (סיבוב והיפוך של התמונה). בלי `transform` — הפלט כמו קודם.
- **שקיפות:** `fillOpacity` / `strokeOpacity` (ב-`path`) ו-`opacity` (ב-`image`), 0–1 — דרך ExtGState. בלי שקיפות אין
  ExtGState במשאבים, כך שהפלט של מי שלא משתמש בה לא משתנה.
- **`clip` … `restore`** (שלב 2)**:** הפעולות שאחרי `clip` מוגבלות לנתיב שלו עד `restore`; מקונן (חיתוך בתוך חיתוך —
  המשותף). `transform` של ה-clip חל רק על הנתיב שלו. `restore` בלי `clip` — שגיאה; `clip` שלא נסגר — נסגר בסוף העמוד.
- **מעבר צבע** (שלב 2)**:** `fill` / `stroke` של `path` יכולים להיות
  `{ "gradient": { "type": "linear", "x1", "y1", "x2", "y2", "stops": [{ "offset", "color" }] } }` או
  `{ "type": "radial", "cx", "cy", "r", "fx", "fy", "stops" }` — בקואורדינטות של הנתיב (אחרי `transform`, כמו
  `userSpaceOnUse`), עם המשך מעבר לקצוות. כמה עצירות שרוצים. **עצירה יכולה לשאת `opacity`** (0–1) — מסכה רכה לפי
  בהירות (מעבר אפור באותן קואורדינטות, בטופס); נתיב עם מילוי וקו כשאחד מהם עם מסכה — נצבע בשני מעברים, כדי שהמסכה
  לא תחול על השני. שקיפות לכל המעבר — `fillOpacity` / `strokeOpacity`. Pattern ומסכה נכנסים למשאבים רק כשבשימוש.
- **פעולה לא מוכרת — שגיאה**, כמו קודם. ההרחבות — תוספת בלבד.
- **שגיאה:** `InvalidArgumentException` עם המקום ("עמוד 1, פעולה 3: …").

## ב-JS (`src/js/draw.js`)

```js
import { pdfDraw } from './pdf-core/draw.js';
const bytes = await pdfDraw(list, key => bytesOf(key));   // Uint8Array; resolve מחזיר Uint8Array / ArrayBuffer / Promise / null
```

- **אותה רשימה ואותן הודעות שגיאה** (`TypeError`), וזרם התוכן של כל עמוד **זהה** לזה של PHP — נבדק ב-`tests/draw.test.mjs`
  על כל הפעולות, ועל 20 רשימות פגומות. ציור ב-`pdftoppm` של שני הקבצים — אותו דבר.
- **`glyphs` — עוד לא ב-JS** (גופנים ו-subset — רק כשמישהו יצטרך): שגיאה ברורה. טקסט — כקווי מתאר (`path`), או ציור בשרת.
- **תמונות:** PNG (8 / 16 סיביות, פלטה, אפור; בלי interlace) ו-JPEG. שונה מ-PHP: PNG לא מוקטן (ב-PHP — עד 1600 בצלע),
  והשקיפות שלו מדויקת (ב-GD — 7 סיביות). דורש `CompressionStream` ו-`crypto.subtle` (דפדפן מודרני, https; Node 18+).

## עוד לא

- **`glyphs` ב-JS** — גופנים ו-subset, כשמישהו יצטרך.

- **CMYK והפרדות** — לפי `mbe-format.md` סעיף 9.
- **subset לגופני CFF** (OTF עם קווי מתאר PostScript) — מוטמעים בשלמותם.

## בדיקות

במעבד התמלילים: `php word-processor/tests/run.php` — הקטע "רשימת ציור → PDF" (תיבות, subset, מיקומי גליפים,
ציור אמיתי ב-`pdftoppm`, גליף מורכב, שגיאות) ו-"pdf-core, שלב 1" (path: cm, Q → C, evenodd, מקפים,
שקיפות, ציור ב-`pdftoppm`, שגיאות) ו-"שלב 2" (clip מקונן ועם transform, מעבר צבע לינארי בשלוש עצירות / רדיאלי / בקו —
כולם גם בציור ב-`pdftoppm`), וכל בדיקות ה-PDF של העורך עוברות דרך אותו כותב.
