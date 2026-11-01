# מנוע ה-PDF המשותף (`pdf-core`)

כותב PDF בלי תלויות (PHP + zlib, ו-GD לתמונות שאינן JPEG): עמודים, גופני TrueType מוטמעים עם עברית בת-חיפוש,
תמונות, ורשימת ציור למי שמעמד בעצמו. נחלץ ממעבד התמלילים (שבירת השורות שלו נשארת אצלו). הבקשה:
[`docs/requests/layout-studio-pdf.md`](../docs/requests/layout-studio-pdf.md). הכרעת המיקום — בעל המערכת, 2026-10-25.

## מבנה

```
src/php/       writer.php (PdfWriter) · font.php (TtfFont) · subset.php (TtfSubset) · draw.php (pdf_draw)
tools/sync.php מעתיק את src/php לאפליקציות שב-apps.json; ‎--check — שער בפריסה
apps.json      האפליקציות שמשתמשות. אפליקציה נכנסת רק בהסכמת הצ'אט שלה
```

**עורכים רק כאן**, מריצים `php creative-suite/pdf-core/tools/sync.php`, ומעלים את המקור ואת העותקים באותו commit.
עותק ששונה בתוך אפליקציה — הפריסה נעצרת.

## הצטרפות

1. מוסיפים ל-`apps.json`: `"layout-studio": { "php": "lib/pdf-core" }` — תיקייה שכולה של המנוע (הסנכרון מוחק בה קבצים זרים).
2. `php creative-suite/pdf-core/tools/sync.php`.
3. בקוד: `require_once __DIR__ . '/lib/pdf-core/draw.php';` — בלי `require` מחוץ לתיקיית האפליקציה.

## רשימת ציור → PDF

```php
$pdf = pdf_draw($list, fn(string $key) => file_get_contents(...)); // $key — מה שברשימה ב-font / src; null — חסר
```

יחידות: נקודות (`pt`). y — מלמעלה למטה, מהפינה העליונה של העמוד (TrimBox). צבע: `"#rrggbb"`.

```json
{ "title": "…", "pages": [ { "size": [w, h], "bleed": 9, "ops": [
    { "op": "glyphs", "font": "media/….ttf", "size": 12, "color": "#000000", "x": 0, "y": 0,
      "glyphs": [[gid, dx, dy, adv]], "text": "…" },
    { "op": "image", "src": "media/….png", "box": [x, y, w, h], "clip": [x, y, w, h], "opacity": 1 },
    { "op": "rect", "box": [x, y, w, h], "fill": "#…", "stroke": "#…", "width": 1 },
    { "op": "line", "from": [x, y], "to": [x, y], "color": "#…", "width": 1 },
    { "op": "path", "d": "M10 10 L90 10 C95 10 100 15 100 20 Q 50 50 10 10 Z", "transform": [a, b, c, d, e, f],
      "fill": "#…" | null, "fillOpacity": 1, "fillRule": "nonzero" | "evenodd",
      "stroke": "#…" | null, "strokeOpacity": 1, "width": 1, "cap": "butt", "join": "miter", "miter": 4, "dash": [] } ] } ] }
```

- **`size` / `bleed`:** `size` הוא העמוד (TrimBox). `bleed` מרחיב את MediaBox ו-BleedBox לכל צד; מותר לצייר בגלישה (x, y שליליים).
- **`glyphs`:** (x, y) — קו הבסיס. כל גליף: מזהה בגופן, היסט (dx ימינה, **dy למעלה** — כמו HarfBuzz) והתקדמות, בנקודות.
  הגליפים בסדר חזותי (משמאל לימין). `text` — הטקסט הלוגי, ל-`ActualText` (חיפוש והעתקה).
- **גופן:** TrueType (TTF או WOFF 1), **אותו קובץ** שעוצב איתו — מוטמע עם subset, ומזהי הגליפים נשמרים.
- **`path`** (שלב 1, #971)**:** `d` — פקודות **מוחלטות** בלבד, `M L C Q Z` (כמו בפורמט). `Q` הופך ל-`C`; זוגות נוספים אחרי
  פקודה — חזרה עליה (אחרי `M` — `L`). `transform` — מטריצה של SVG על הנקודות, ביחידות הרשימה (y למטה); העובי והמקפים
  עוברים בה, כמו ב-SVG. לפחות `fill` או `stroke`. `cap`: `butt` / `round` / `square`; `join`: `miter` / `round` / `bevel`.
- **שקיפות:** `fillOpacity` / `strokeOpacity` (ב-`path`) ו-`opacity` (ב-`image`), 0–1 — דרך ExtGState. בלי שקיפות אין
  ExtGState במשאבים, כך שהפלט של מי שלא משתמש בה לא משתנה.
- **פעולה לא מוכרת — שגיאה**, כמו קודם. ההרחבות — תוספת בלבד.
- **שגיאה:** `InvalidArgumentException` עם המקום ("עמוד 1, פעולה 3: …").

## עוד לא

- **שלב 2:** `clip` (רשימת `d` + `fillRule`) עד `{ "op": "restore" }`, מקונן; `fill` / `stroke` עם מעבר צבע לינארי /
  רדיאלי (Shading 2 / 3).
- **שלב 3:** pdf-core גם ב-JS — אותה רשימת ציור ואותן בדיקות, על בסיס ה-`toPDF` של וקטורי. גופנים ב-JS — רק כשיידרש.

- **CMYK והפרדות** — לפי `mbe-format.md` סעיף 9.
- **subset לגופני CFF** (OTF עם קווי מתאר PostScript) — מוטמעים בשלמותם.

## בדיקות

במעבד התמלילים: `php word-processor/tests/run.php` — הקטע "רשימת ציור → PDF" (תיבות, subset, מיקומי גליפים,
ציור אמיתי ב-`pdftoppm`, גליף מורכב, שגיאות) ו-"pdf-core, שלב 1" (path: cm, Q → C, evenodd, מקפים,
שקיפות, ציור ב-`pdftoppm`, שגיאות), וכל בדיקות ה-PDF של העורך עוברות דרך אותו כותב.
