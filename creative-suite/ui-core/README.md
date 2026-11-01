# ui-core — ערכת הממשק המשותפת של חבילת היצירה

קוד ממשק אחד — עיצוב, חלונות, תפריטים, טוסט, קיצורים, זום, ידיות, צבע, גופנים — **במקום אחד**, וכל אפליקציה מקבלת
**עותק מסונכרן** (כמו `mbe-core` ו-`pdf-core`), כי אפליקציה בחבילה עצמאית ולא טוענת קבצים מחוץ לתיקייה שלה.

**הבעלים:** הצ'אט של הקוד המשותף (`creative-suite/team/roster.json`, `role: shared-code`).
**התוכנית, המיפוי וההכרעות:** [`../docs/shared-code/README.md`](../docs/shared-code/README.md).
**תצוגה של כל הרכיבים:** [`demo/`](demo/) — לבדיקה בטלפון.

## מה יש עכשיו

| קובץ | מה | שלב |
| --- | --- | --- |
| `src/css/ui.css` | המשתנים, הבסיס והרכיבים של `phone-first-design` + החלון של סטודיו העיצוב + `.menu`, `.sr` | 1 ✅ |
| `src/js/dom.js` | `h()` (בלי innerHTML), `append`, `$`, `$$`, `ltr()` (LRI…PDI), `debounce` | 2 ✅ |
| `src/js/toast.js` | `toast(msg, { kind, action, ms })` — 3ש', שגיאה 6ש', עם כפתור 8ש'; `hide()` | 2 ✅ |
| `src/js/dialog.js` | `initDialogs()`, `sheet({…})`, `confirm()`, `prompt()`, `choose()` | 2 ✅ |
| `src/js/menu.js` | `menu(anchor \| {x,y}, items)`, `closeMenu()`, `contextMenu(el, e => items)` | 2 ✅ |
| `src/js/tabs.js` | `tabs(root, { onChange, remember })` | 2 ✅ |
| `src/js/files.js` | `download`, `fileName`, `pickFiles`, `dropZone`, `setSaveBridge` | 2 ✅ |
| `src/js/store.js` | `pref(ns)` (localStorage בטוח), `kv(db)` (IndexedDB) | 2 ✅ |
| `src/js/viewport.js` | חשבון זום / הזזה טהור — `fit`, `zoomTo`, `zoomBy`, `nextStep`, `screenToDoc`, `pinch`, `wheel`, `zoomLabel` (המקור: `engine/view.js` של הרסטר) | 4 ✅ |
| `src/js/gestures.js` | `bindViewport(el, { get, set, pointer, hand, ignore, panOnEmpty, onTap })` — גלגלת, צביטה, רווח / גלגלת לחוצה, אצבע שנייה מבטלת את הכלי, הזזה על רקע ריק, הקשה | 4 ✅ |
| `src/js/account.js` | 👤 `accountMenu(bar, { user, server, prefs, docs, drives, sections, shortcuts, logout, … })` — העיגול, התפריט (גיליון בטלפון), הגדרות אישיות; `accountServer(call)` — החיבור לצד השרת; `ui.css` — ערכה כהה / בהירה ידנית ו-`--ui-scale` | ✅ |
| `src/js/files-window.js` | 📁 "המסמכים שלי" — `openDocuments` / `pickFile` / `saveFile(sources, …)`: חלון אחד לניהול, פתיחה ושמירה; לשונית לכל מקור (מתאם), `suiteSource(drives)` לענן החבילה | ✅ |
| `src/php/account/account.php` | צד השרת של תפריט המשתמש — `uic_account_api($action, $input, $hooks)`: הגדרות (טבלה `uic_prefs` במסד של האפליקציה), ייצוא, מחיקת חשבון | ✅ |
| `src/js/transform.js` | חשבון הטרנספורמציה — `dragHandle` (פינה עם יחס, צד, Alt מהמרכז, קו שטוח), `dragRotate` + `snapAngle` (מגנט 45°), `handlePos`, `bounds` (המקור: הרסטר) | 5 ✅ |
| `src/js/snap.js` | `snapper({ boxes, points, tol, grid })` → `box()` / `point()` עם קווי עזר (המקור: הווקטורי) | 5 ✅ |
| `src/js/layers.js` | 🗂️ `layersPanel(ul, adapter)` — לוח השכבות של הרסטר: קבוצות, 👁️ 🔒, שינוי שם במקום, גרירה (גם לתוך קבוצה), מקלדת, תפריט | 9 ✅ |
| `src/js/fonts.js` + `src/fonts/` | 🔤 קטלוג אחד (10 גופנים, OFL) + הקבצים, `ensureFont`, `userFonts` (מכשיר + ענן החבילה), `fontPicker` | 8 ✅ |
| `src/js/color.js` | 🎨 `colorPicker(label, value, onInput, onCommit, { alpha })` — הבורר של סטודיו העיצוב; המרות, ניגוד WCAG, אחרונים ומועדפים | 7 ✅ |
| `src/js/history.js` | ביטול / ביצוע — `History` (רשומות, תקרת זיכרון, `group`, מיזוג; המקור: הרסטר), `snapshots({ get, set })` למסמך JSON, `undoButtons()` | 6 ✅ |
| `src/js/handles.js` | `handles(overlay, { view, keepRatio, snap, onPreview, onCommit })` — הידיות על המסך, סיבוב, שורת מצב, קווי עזר; מצב `rect` לחיתוך | 5 ✅ |
| `src/js/keys.js` | `shortcuts()`, `matches(e, combo)`, `keyOf(e)`, `label`, `ariaKeys` — לפי `e.code`, עובד במקלדת עברית | 3 ✅ |

התיעוד המלא של כל פונקציה — בראש הקובץ שלה.

## בדיקות

`node creative-suite/ui-core/tests/browser.cjs` — 200 בדיקות ב-Chromium, בטלפון (390px) ובמחשב (1280px), על דף התצוגה; `php creative-suite/ui-core/tests/account.php` — צד השרת (רץ גם בשער הפריסה).
**מריצים לפני כל שינוי ב-src.**

## מה מחליפים בכל אפליקציה (שלב 2)

| היום | ב-ui-core |
| --- | --- |
| `confirm()` / `prompt()` / `alert()` של הדפדפן | `await confirm(…)`, `await prompt(…)`, `toast(…)` |
| `toast` משלה | `import { toast } from './ui-core/toast.js'` |
| מאזין `[data-close]` + IIFE של `--vvh` | `initDialogs()` פעם אחת |
| `sheet` / `choose` / `confirmDialog` משלה | `sheet`, `choose`, `confirm` |
| `popMenu` / פופאובר `role=menu` | `menu(button, items)` |
| `h` / `el` / `$` משלה | `dom.js` |
| `download`, `fileName`, `pickFiles` משלה | `files.js` |
| `localStorage` עם try/catch, `idb.js` | `pref`, `kv` |

## מי הצטרף

הטבלה המלאה, לכל יכולת ולכל אפליקציה — **`creative-suite/docs/integration.md`** (המקום היחיד שבו מסמנים). כאן — רק תקציר.

| אפליקציה | מה | מתי |
| --- | --- | --- |
| ענן החבילה | `css` + `js` — הכול משלבים 1–2 (הטייס) | #924 |
| רסטר | `js` — `keyOf` (מקלדת עברית) | #923 |
| סטודיו העיצוב | `js` — `keyOf` (מקלדת עברית) | #927 |
| וקטורי | `js` — `keyOf` (מקלדת עברית); `css` + שלבים 1–2 | #928, #942 |
| עימוד | `css` + `js` — שלבים 1–2 | #941 |

## דוגמאות שחזרו במעבר

```js
// גרירת קבצים עם תנאי של האפליקציה (מכסה, סוג) — התנאי בתוך ה-callback; dropZone לא יודע עליו
dropZone(filesView, {
    accept: ['image/', '.mbe'],
    onFiles: files => state.quota?.quota > 0 ? uploadAll(files) : toast('אין מכסה', true),
    onReject: files => toast(`לא נתמך: ${files[0].name}`, true),
});

// el קיים באפליקציה — h מקבל את אותן קריאות, בלי לשנות אף אחת
import { $, h as el } from './ui-core/dom.js';
```

## איך אפליקציה מצטרפת

1. **הסכמה:** רק הצ'אט של האפליקציה מוסיף או משנה את השורה שלה ב-`apps.json`:
   ```json
   "vector-studio": { "css": "assets/css/ui-core", "js": "assets/js/ui-core" },
   ```
   **שורה אחת לכל אפליקציה, לפי סדר א"ב** — כך שתי אפליקציות שמצטרפות יחד נוגעות בשורות שונות. **למזג `main` לפני
   ה-PR** (לקח מהטייס: הרסטר וענן החבילה הצטרפו באותה שעה). כל סוג רשות. התיקייה שבצד ימין **כולה של ui-core** —
   הסנכרון מוחק בה כל קובץ שאינו במקור.
2. **סנכרון:** `php creative-suite/ui-core/tools/sync.php` — העותק נכנס. אף קובץ של האפליקציה לא משתנה.
3. **CSS:** ב-HTML — `<link rel="stylesheet" href="assets/css/ui-core/ui.css">` **לפני** `app.css`. מ-`app.css` מוחקים את
   הבלוק שהועתק מ-`tokens.css` של הסקיל (משתנים → טוסט). נשאר רק מה ששייך לאפליקציה.
4. **בדיקה:** `php creative-suite/ui-core/tools/sync.php --check`, ובדיקה בטלפון. מה שמשתנה במראה — **רק החלון** (עולה
   מלמטה בטלפון, הכרעת בעל המערכת).
5. **מחזור רגיל:** ענף → PR → מיזוג → `conclusion: success`.

## הכללים

- **לא עורכים את העותק שבתוך אפליקציה.** שער בפריסה (`sync.php --check`) עוצר עותק ששונה.
- תיקון או שינוי ב-ui-core — בבקשה ישירה לבעלים (הסקיל `creative-suite-team`), לא בעותק.
- שינוי שבור (שם מחלקה, חתימת פונקציה) — רק עם הודעה לכל אפליקציה שב-`apps.json` לפני המיזוג.
- ui-core לא יודע מה זה מסמך, שכבה או `.mbe`. מקבל callbacks ומחזיר אובייקט.
- **קובץ ב-`src` לא נמחק ולא משנה שם** בלי הודעה לכל אפליקציה שב-`apps.json`: אפליקציה עם service worker (הרסטר) מונה
  את הקבצים ברשימת המטמון, וקובץ חסר מפיל את ההתקנה. קובץ **חדש** לא שובר — אפליקציה שרוצה אותו גם בלי רשת מוסיפה
  אותו לרשימה ומעלה את גרסת המטמון. (המטמון של הרסטר "רשת קודם" — שינוי בקובץ קיים מגיע מיד.)

## היסטוריה

- **2026-11-01 — שלב 9:** `layers.js` — לוח שכבות (`docs/shared-code/migrate/layers.md`).
- **2026-11-01 — שלב 8:** `fonts.js` + `src/fonts/` — גופנים ובורר גופנים (`docs/shared-code/migrate/fonts.md`).
- **2026-11-01 — שלב 7:** `color.js` — צבע ובורר צבעים (`docs/shared-code/migrate/color.md`).
- **2026-11-01 — שלב 6:** `history.js` — ביטול / ביצוע (`docs/shared-code/migrate/history.md`).
- **2026-11-01 — שלב 5:** `transform.js` + `snap.js` + `handles.js` — ידיות, סיבוב, הצמדה, מצב חיתוך (`docs/shared-code/migrate/handles.md`).
- **2026-10-31 — שלב 1:** תשתית (`apps.json`, `tools/sync.php`, שער בפריסה), `ui.css`, דף תצוגה.
- **2026-10-31 — שלב 2:** ערכת הממשק — `dom`, `toast`, `dialog`, `menu`, `tabs`, `files`, `store`; בדיקות דפדפן.
- **2026-10-31 — 📁 `files-window.js`:** "המסמכים שלי" — ניהול, פתיחה ושמירה, לשונית לכל מקור, ענן החבילה דרך מודול הכוננים.
- **2026-10-31 — 👤 `account.js` + `php/account`:** תפריט המשתמש, הגדרות אישיות, ייצוא ומחיקת חשבון — ממשק וצד שרת, פעם אחת (`docs/shared-code/migrate/account.md`). `sync.php` — סוג `php`.
- **2026-10-31 — `panOnEmpty` + `onTap`** ב-`bindViewport` — לבקשת הווקטורי.
- **2026-10-31 — שלב 4:** `viewport.js` + `gestures.js` — זום, הזזה, צביטה, גלגלת (`docs/shared-code/migrate/viewport.md`).
- **2026-10-31 — טייס:** ענן החבילה עבר לשלבים 1–2 (#924) — בלי באג ב-ui-core. רסטר, וקטורי וסטודיו — תיקון המקלדת העברית.
- **2026-10-31 — שלב 3:** `keys.js` — קיצורים לפי `e.code`; `keyOf(e)` לתיקון של שורה אחת (`docs/shared-code/migrate/keys.md`).
