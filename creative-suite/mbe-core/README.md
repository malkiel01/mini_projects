# ליבת ‎.mbe (`mbe-core`)

הספרייה היחידה שקוראת וכותבת `.mbe` — בדפדפן (JS) ובשרת (PHP), עם אותו API, אותם כללים ואותם קודי בעיות.
האפיון המחייב: [`creative-suite/docs/mbe-format.md`](../docs/mbe-format.md). ההנחיות: [`docs/projects/mbe-core.md`](../docs/projects/mbe-core.md).

**בודק קבצים:** [`inspector/`](inspector/) — גוררים `.mbe` ורואים manifest, עץ שכבות, ייצוגים (עדכני/מיושן) ובעיות.

## מבנה

```
src/js/        mbe.js (ה-API) · face.js (פנים: stampFace, identify) · zip.js · canonical.js · schema.js · schemas.js (נבנה) · vendor/fflate.js
src/php/       mbe.php (ה-API) · face.php (MbeFace) · zip.php · canonical.php · schema.php · schemas.php (נבנה)
schema/        manifest-1.json · document-1.json — JSON Schema, המקור לשתי השפות
tools/sync.php בונה את schemas.* ומעתיק את src לאפליקציות שב-apps.json; ‎--check — שער בפריסה
apps.json      ריק. אפליקציה נכנסת רק בהסכמת הצ'אט שלה
inspector/     דף הבדיקה
tests/         קבצי דוגמה לכל kind, 50 קבצים עוינים, בדיקות JS ו-PHP ובדיקות בין השפות
```

## שימוש

**דפדפן** (ES module, בלי בנייה):

```js
import { open, save, create, PREVIEW } from './mbe/mbe.js';

const f = await open(bytes);            // לא זורק על קובץ פגום
if (!f.ok) showIssues(f.issues);        // [{ level: 'error'|'warning', code, message, path }]
f.document.flows.f1.doc = editorJson;   // עורכים רק את מה שמבינים — כל השאר נשמר בית-לבית
f.setRendition(PREVIEW, png, { type: 'image/png' });              // חובה בכל שמירה
f.setRendition('renditions/document.pdf', pdf, { gaps: ['…'] });  // ייצוג: bytes + gaps
const out = await save(f, { app: 'word-processor', appVersion: '2.3' });
```

**שרת:**

```php
require_once __DIR__ . '/lib/mbe/mbe.php';
$f = Mbe::open($bytes);                  // $f->ok(), $f->issues
$f->setRendition('renditions/document.pdf', $pdf, ['gaps' => []]);
$out = Mbe::save($f, ['app' => 'word-processor', 'appVersion' => '2.3']);
```

| פעולה | JS | PHP |
| --- | --- | --- |
| פתיחה | `await open(bytes, { limits })` | `Mbe::open($bytes, $limits)` |
| אימות בלבד | `await validate(bytes)` | `Mbe::validate($bytes)` |
| חדש | `create(kind, document)` | `Mbe::create($kind, $document)` |
| שמירה | `await save(f, { app, appVersion, now, lite })` | `Mbe::save($f, [...])` |
| משאבים | `f.media()` · `await f.addMedia(bytes, type)` → `media/<sha256>.png` | `$f->media()` · `$f->addMedia()` |
| ייצוגים | `f.renditions()` (עם `stale`) · `f.setRendition()` · `f.removeRendition()` | אותו דבר |
| מידע פרטי | `f.apps(id)` · `f.setApp(id, name, bytes)` | אותו דבר |
| גרסאות | `migrate(doc, manifest, toMajor)` · `MIGRATIONS` | `Mbe::migrate()` |
| החתמת פנים (אחרי `save`) | `await stampFace(f, 'docx'\|'png', bytes)` · `await xmpPacket(f)` (ל-PDF) — מ-`face.js` | `MbeFace::stamp()` · `MbeFace::xmpPacket()` — `face.php` |
| "מה הקובץ הזה?" | `await identify(bytes)` → `{ type, format, docId, base, model, document?, issues }` | `MbeFace::identify($bytes)` |

### פנים — docx, png, PDF (`docs/spec/hub-api.md` 2.1–2.2)

- **מחתימים אחרי `save`**: ‏`mbe-doc-id` = `manifest.id`, ‏`mbe-base` = הגיבוב של `document.json` כפי שנשמר. מסמך
  ששונה מאז השמירה, או שעוד אין לו id — `face-not-saved`. כל החתמה מוסיפה שלב `export` ליומן (בשמירה הבאה, פעם
  אחת לכל פורמט).
- **docx**: ‏`docProps/custom.xml` (שני מאפיינים), ‏`customXml/itemN.xml` עם `document.json` (בלי media) תחת
  `urn:mbe:document:1`, ‏`itemPropsN.xml`, ה-rels ו-`[Content_Types].xml`. שאר החלקים — בית-לבית. החתמה חוזרת
  מחליפה ולא מכפילה. ‏`identify` מחזיר את המודל ב-`document` — הבסיס למיזוג כשאין זיכרון.
- **png**: שני `iTXt` (‏`mbe-doc-id`, ‏`mbe-base`) מיד אחרי IHDR. הפיקסלים לא נגעים.
- **PDF**: הליבה **לא** מחתימה PDF קיים — `xmpPacket(f)` נותן חבילת XMP (‏`urn:mbe:face:1`), והכותב מטמיע אותה
  כ-Metadata **לא דחוס** בזמן היצירה. ‏`identify` קורא אותה.
- **סימניות הפסקה** כותבת האפליקציה (`paragraphBookmark(id)` → `_mbe_p<id>`).
- LibreOffice (שורת פקודה, פתיחה ושמירה מחדש של docx) שומר את `custom.xml`, את `customXml` ואת הסימניות — ניסוי נ3.
- קודים: `face-not-saved`, ‏`face-stamp-failed` (לא docx / png, או PDF); ב-`identify` (אזהרות) — `face-unknown-doc`
  (מזהה פגום / קובץ לא קריא), ‏`face-base-missing` (המודל ב-customXml פגום).

### כללים שהליבה אוכפת

- **יומן שלבים וגרסאות (`history/`, מ-1.1):** `save(f, { app, who })` רושם שלב לבד — `create` / `edit`; שמירות רצופות
  של אותה אפליקציה ואותו `who` מעדכנות את השלב האחרון (ולא נשאר עותק לכל הקשה); שמירה בלי שינוי — לא נוגעת. שלב
  מפורש — `f.stage({ kind, who, note })`; חזרה — `f.restore('s3')` (נרשם `restore`, לא מוחק); `f.version(sha)`,
  `f.journal()`, `f.prune({ keep })`. ב-PHP: `$f->stage($kind, $who, $note)`, `$f->restore()`, `$f->prune($keep)`.
  היסטוריה פגומה — אזהרות בלבד; יומן פגום נשמר כמו שהוא.
- **מזהה פסקה (`attrs.id`, מ-1.1):** `save` משלים לכל `paragraph` / `heading` בשטף `prosemirror` — חסר, פגום או כפול
  מקבל `paraId()` (`p` + 10 אלפאנומריים); תקין לא משתנה לעולם. `[A-Za-z0-9_]{1,32}`, בלי מקף — כי שם סימנייה ב-Word
  לא מקבל מקף. סימנייה ב-docx: `paragraphBookmark(id)` → `_mbe_p<id>`, וחזרה ב-`bookmarkParaId`.
- **מזהה המסמך (`manifest.id`, מגרסת פורמט 1.1):** UUID v4 שנוצר בשמירה הראשונה ולא משתנה; `f.id` ב-JS, `$f->id()`
  ב-PHP. "שמירה כעותק" — `save(f, { asCopy: true })`: מזהה חדש ו-`created` חדש. קובץ 1.0 מקבל מזהה בשמירה הבאה.

- **`save` לעולם לא מייצר קובץ פגום.** היא פותחת ובודקת את מה שכתבה, וזורקת `MbeError` (עם `issues`) אם יש
  שגיאה. בלי `renditions/preview.png` — לא נשמר.
- **שמירה בית-לבית:** שדות לא מוכרים ב-manifest וב-document, חלקים לא מוכרים ב-ZIP, ותיקיות `apps/` של אחרים.
  minor חדש מ-1.0 (למשל 1.7) — נפתח, ונשמר בגרסה שלו.
- **ייצוגים:** ייצוג שסופק מאז הפתיחה מקבל `sourceSha256` של המסמך שנשמר. ייצוג שלא חודש — נשאר עם הגיבוב
  הישן, ואם המסמך השתנה הוא `stale` ("מיושן").
- **`document.json` נכתב קנוני (RFC 8785)** — מפתחות ממוינים, בלי רווחים. כך הגיבוב זהה ב-JS וב-PHP, וייצוג
  לא מסומן מיושן רק כי שפה אחרת שמרה.
- **שכבת `vector` (מגרסה 1.1):** כל פריט ב-`paths` עם `id` ייחודי בכל המסמך; נתיב — `d` חובה, מתחיל ב-`M`, רק
  פקודות מוחלטות `M L C Q Z` ולכל אחת כפולות של מספר הארגומנטים שלה (`pathProblem`); קבוצה — בלי `d` ו-`transform`
  משלה; `transform` — 6 מספרים, `box` — 4. פריט עם `type` לא מוכר — נשמר ולא נבדק.
- **סדר השרשור:** כל מזהה ב-`flows.<id>.frames` — שכבת `frame` קיימת שה-`flow` שלה הוא השטף הזה, פעם אחת
  (`flow-frames`). שטף בלי `frames` — חוקי.
- **משאבים:** כל `src` / `embedded` במסמך — רק `media/<sha256>.<ext>` שקיים בקובץ. קישורים בטקסט (`href`) מותרים.
- **אבטחה** (סעיף 10): zip-slip, zip-bomb (יחס מעל 100 לחלק מעל 1MB), תקרת 10,000 קבצים ו-1GB פתוחים, גודל
  מוצהר שמשקר, CRC, הצפנה, SVG עם סקריפט/`on*`/`foreignObject`/הפניה חיצונית, הפניות חיצוניות במסמך, מייל ב-manifest.
  הכול מהזיכרון — שום דבר לא נכתב לדיסק.

### ייצוג ה-JSON ב-PHP

אובייקט JSON → מערך אסוציאטיבי; מערך JSON → list. **אובייקט ריק (`{}`) ואובייקט שמפתחותיו 0,1,2…** נשארים
`stdClass` — אחרת `{}` היה הופך ל-`[]` בשמירה, והגיבוב משתנה.

## בדיקות

```bash
node creative-suite/mbe-core/tests/make-fixtures.mjs   # יוצר מחדש את tests/fixtures (דטרמיניסטי)
node --test creative-suite/mbe-core/tests/js.test.mjs  # JS + בין השפות (קורא ל-PHP)
php creative-suite/mbe-core/tests/run.php              # PHP לבד
```

מה נבדק: וקטורי RFC 8785 ו-3000 מספרים אקראיים זהים בשתי השפות; קובץ מכל `kind` נפתח ונשמר בשרשרת
JS → PHP → JS → PHP — `document.json` וכל החלקים **זהים בית-לבית**, השדות הלא מוכרים נשמרים, ואף ייצוג לא
מסומן מיושן; 50 קבצים עוינים — כל אחד נדחה, ו-PHP מחזיר בדיוק את אותן בעיות כמו JS; הפלט נפתח ב-`ZipArchive`
וב-`unzip -t`, והחתימה `mimetypeapplication/vnd.mbe+zip` בהיסט 30.

## הפצה לאפליקציה

כמו DevLoop: עורכים **רק** ב-`src` (והסכמות ב-`schema/`), ומריצים `php creative-suite/mbe-core/tools/sync.php`.
ב-`apps.json` — לכל אפליקציה תיקיית יעד שכולה של הליבה:

```json
{ "word-processor": { "js": "assets/js/mbe", "php": "lib/mbe" } }
```

הסנכרון מעתיק ומוחק שם קבצים שאינם במקור; `--check` (שער `mbe-core gate` בפריסה) עוצר עותק ששונה.
מעלים את המקור ואת העותקים באותו commit.

## ספריות

| ספרייה | גרסה | רישיון | למה |
| --- | --- | --- | --- |
| [fflate](https://github.com/101arrowz/fflate) | 0.8.2 | MIT (`src/js/vendor/fflate.LICENSE`) | Deflate בדפדפן. רק הדחיסה — מבנה ה-ZIP שלנו, כדי שיהיה זהה ל-PHP |

ב-PHP — `zlib` המובנה (`inflate_add` בחתיכות, `gzdeflate`). **לא `ZipArchive`** (בניגוד להנחיה המקורית): הוא
דורש קובץ בדיסק, ומפרסר אחר מחזיר בעיות אחרות מה-JS על אותו קובץ עוין. `ZipArchive` משמש בבדיקות כבודק בלתי תלוי.
בודק ה-JSON Schema — קוד שלנו (רק המילים שהסכמות משתמשות בהן; מילה לא מוכרת זורקת).

## מה טרם הוכרע

- **ZIP64** — לא נתמך בגרסה 1 (קובץ כזה נדחה בהודעה ברורה). האפיון אומר "מותר" — בקשה לצ'אט המתכלל.
- סכמת התוכן לכל `kind` — נכנסת ל-`schema/document-1.json` כשמסמכי הפרויקטים נסגרים.
- **ניקוי SVG** — הליבה מזהה SVG לא בטוח ודוחה אותו; הניקוי עצמו — באחריות האפליקציה שמייצרת.
- תקרת הגודל בשרת — היום 1GB פתוח; ייתכן שתרד לפי `memory_limit` כשתהיה אפליקציה שמעלה קבצים.
