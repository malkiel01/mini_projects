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
    { "op": "image", "src": "media/….png", "box": [x, y, w, h], "clip": [x, y, w, h] },
    { "op": "rect", "box": [x, y, w, h], "fill": "#…", "stroke": "#…", "width": 1 },
    { "op": "line", "from": [x, y], "to": [x, y], "color": "#…", "width": 1 } ] } ] }
```

- **`size` / `bleed`:** `size` הוא העמוד (TrimBox). `bleed` מרחיב את MediaBox ו-BleedBox לכל צד; מותר לצייר בגלישה (x, y שליליים).
- **`glyphs`:** (x, y) — קו הבסיס. כל גליף: מזהה בגופן, היסט (dx ימינה, **dy למעלה** — כמו HarfBuzz) והתקדמות, בנקודות.
  הגליפים בסדר חזותי (משמאל לימין). `text` — הטקסט הלוגי, ל-`ActualText` (חיפוש והעתקה).
- **גופן:** TrueType (TTF או WOFF 1), **אותו קובץ** שעוצב איתו — מוטמע עם subset, ומזהי הגליפים נשמרים.
- **שגיאה:** `InvalidArgumentException` עם המקום ("עמוד 1, פעולה 3: …").

## עוד לא

- **CMYK והפרדות** — לפי `mbe-format.md` סעיף 9.
- **subset לגופני CFF** (OTF עם קווי מתאר PostScript) — מוטמעים בשלמותם.

## בדיקות

במעבד התמלילים: `php word-processor/tests/run.php` — הקטע "רשימת ציור → PDF" (תיבות, subset, מיקומי גליפים,
ציור אמיתי ב-`pdftoppm`, גליף מורכב, שגיאות), וכל בדיקות ה-PDF של העורך עוברות דרך אותו כותב.
