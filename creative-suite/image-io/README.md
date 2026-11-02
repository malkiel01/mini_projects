# קובצי תמונה המשותפים (`image-io`)

קריאה וכתיבה של קובצי תמונה לכל החבילה: PNG (כולל חותמת "פנים"), TIFF, ‏PSD בשכבות, ZIP, ‏ORA, זיהוי סוג לפי הבתים,
וקידוד JPEG / WebP / AVIF בדפדפן. **בלי תלויות** — חשבון על מערכים, ומה שאפשר נבדק ב-node.

נחלץ מהרסטר (`raster-studio/assets/js/engine/{png,psd,tiff,zip,unzip}.js` + ה-ORA מ-`formats.js`). פריט **מ3** ב-
`docs/spec/work-order.md`. **‎`.mbe` לא נקרא ולא נכתב כאן** — רק דרך ליבת הפורמט (`mbe-core`).

## מבנה

```
src/js/index.js       נקודת כניסה אחת (re-export של הכול)
src/js/png.js         encodePNG, crc32, sha256Hex
src/js/tiff.js        encodeTIFF
src/js/psd.js         encodePSD, decodePSD, isPSD, PSDError
src/js/zip.js         zipStore (כתיבה, שיטת "שמור")
src/js/unzip.js       unzip (קריאה, שמור / deflate, עם הגנות), LIMITS
src/js/ora.js         oraStack, parseOraStack (stack.xml של OpenRaster)
src/js/sniff.js       sniff, MIME
src/js/canvas.js      canEncode, encodeImage — רק בדפדפן (OffscreenCanvas)
tests/*.test.mjs      node --test creative-suite/image-io/tests/*.test.mjs
tools/sync.php        מעתיק את src/js לאפליקציות שב-apps.json; ‎--check — שער בפריסה
apps.json             מי משתמש
```

**עורכים רק כאן**, מריצים `php creative-suite/image-io/tools/sync.php`, ומעלים את המקור ואת העותקים באותו commit.
עותק ששונה בתוך אפליקציה — הפריסה נעצרת.

## הצטרפות

1. `apps.json`: `"<app>": { "js": "assets/js/image-io" }` — תיקייה שכולה של המודול (הסנכרון מוחק בה קבצים זרים).
2. `php creative-suite/image-io/tools/sync.php`.
3. `import { encodePNG, sniff } from './image-io/index.js';` (או קובץ בודד, למשל `./image-io/png.js`) — בלי `import`
   מחוץ לתיקיית האפליקציה.
4. יש service worker? להוסיף את הקבצים לרשימת השלד ולהעלות את גרסת המטמון.
5. לסמן את התא בשורה של `image-io` ב-`docs/integration.md`.

## ה-API

**פיקסלים:** `rgba` — `Uint8Array` / `Uint8ClampedArray` של `w×h×4`, אלפא **ישר** (לא מוכפל), שורה 0 למעלה.
**עץ שכבות** (PSD, ORA) — מלמטה למעלה, כמו במסמך; קבוצה = `{ type: 'group', …, children }`.
**מה חוזר:** הכול מחזיר `Uint8Array`, חוץ מ-`encodeImage`, שמחזיר `Blob` (כי זה מה שהקנבס נותן). `download` של ui-core
(`files.js`) מקבל `Blob` בלבד. לכן `Uint8Array` עוטפים כך: `download(new Blob([bytes], { type: MIME.png }), name)`.
**שגיאות:** `message` בעברית, ומתחיל ב-`image-io:`. `code` הוא קוד קבוע באנגלית (`psd-depth`, `avif-unsupported`…).
האפליקציה בוחרת מה להציג **לפי `code`**, לא לפי ההודעה.

| פונקציה | מה |
| --- | --- |
| `await encodePNG(rgba, w, h, { stamp }?)` → `Uint8Array` | 8 ביט RGBA, ‏sRGB, הבתים מדויקים (בלי קנבס שמכפיל באלפא). `stamp` — `(bytes) → bytes` שרץ על הקובץ המוכן; ל"פנים" — ראו למטה |
| `crc32(bytes, crc?)` → מספר | CRC-32 של PNG / ZIP; אפשר להמשיך מ-crc קודם |
| `await sha256Hex(bytes)` → hex | לשמות ב-`media/` של ‎`.mbe` |
| `encodeTIFF(rgba, w, h, dpi = 72)` → `Uint8Array` | משוטחת, RGBA בלי דחיסה, רצועה אחת (פוטושופ, GIMP, Affinity, Preview) |
| `encodePSD({ width, height, layers, composite })` → `Uint8Array` | שכבה: `{ name, x, y, w, h, rgba, opacity, visible, blend }`; קבוצות; שמות בעברית (luni); `composite` — התמונה המשולבת |
| `decodePSD(bytes)` → `{ width, height, layers, composite }` | אותו עץ. בלי דחיסה ו-RLE; 16 / 32 ביט, CMYK, Lab, PSB, ZIP — `PSDError` (`code`: `psd-signature` · `psd-depth` · `psd-mode` · `psd-psb` · `psd-compression` · `psd-truncated` · `psd-layer`). מסכות, סגנונות וטקסט של פוטושופ — לא נקראים (השכבה בפיקסלים שלה) |
| `isPSD(bytes)` | `8BPS` בתחילת הקובץ |
| `zipStore([{ name, data }])` → `Uint8Array` | שיטת "שמור" (התוכן כבר דחוס), לפי הסדר — `mimetype` ראשון ל-ORA. בלי ZIP64 |
| `await unzip(bytes, limits = LIMITS)` | שמור / deflate. נתיב עם `..` או `/` מוביל — נדחה; תקרות: מספר קבצים, גודל פתוח, יחס דחיסה (zip-bomb) |
| `oraStack(w, h, layers)` → XML | `stack.xml`. שכבה: `{ name, src, opacity, visible, blend, locked }` (`src` — נתיב ב-ZIP) |
| `parseOraStack(xml)` → `{ w, h, layers }` | ההפך; שכבה גם עם `x`, `y` |
| `sniff(bytes)` → סוג או `null` | לפי הבתים הראשונים: `png` · `jpeg` · `gif` · `webp` · `avif` · `heic` · `tiff` · `psd` · `bmp` · `zip`. `zip` כולל ORA, ‎`.mbe` ו-docx — מה שבפנים מכריע |
| `MIME[type]` | ה-MIME של מה ש-`sniff` החזיר |
| `await canEncode(type)` → `boolean` | **דפדפן.** האם הדפדפן יודע לכתוב את הסוג (נבדק פעם אחת לכל סוג) |
| `await encodeImage(rgba, w, h, type, { quality, background }?)` → `Blob` | **דפדפן.** `image/jpeg` · `image/webp` · `image/avif`. ל-JPEG רקע לבן כברירת מחדל. סוג שהדפדפן לא כותב — `Error` עם `code: '<סוג>-unsupported'` |

**פענוח** של JPEG / WebP / AVIF / HEIC / PNG רגיל — הדפדפן (`createImageBitmap`), ולא כאן.

### "פנים" ב-PNG

החותמת עצמה נשארת **במקור אחד** — `stampFace` של הליבה (`mbe-core/src/js/face.js`). ‏image-io לא מייבא את הליבה (כל
אפליקציה מחזיקה עותק שלה במקום אחר), אלא מקבל אותה כפונקציה:

```js
import { stampFace } from './mbe/face.js';          // העותק של הליבה באפליקציה
await save(f, { app });                              // קודם שומרים — mbe-base הוא הגיבוב של מה שנשמר
const png = await encodePNG(rgba, w, h, { stamp: b => stampFace(f, 'png', b) });
```

נבדק ב-`tests/image-io.test.mjs`: `identify` של הליבה מזהה את הקובץ, והפיקסלים לא נגעו.

## מי משתמש

| אפליקציה | מה |
| --- | --- |
| `raster-studio` | הכול: ייצוא PNG / JPG / WebP / AVIF / TIFF / PSD / ORA, פתיחת PSD ו-ORA. "פנים" PNG — עוד לא (שורה 5, בתור של הרסטר) |

שאר האפליקציות מצטרפות בתור שלהן (`work-order.md` §3).

## לא כאן (עדיין)

- כתיבת PSD עם מסכות שכבה, וקריאת PSD דחוס ב-ZIP — ר2 של הרסטר.
- 16 / 32 ביט, CMYK ו-Lab — עם `color-core` (שלב א, בהמשך).
- פענוח TIFF.
