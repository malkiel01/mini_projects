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
| `src/js/keys.js` | `shortcuts()`, `matches(e, combo)`, `keyOf(e)`, `label`, `ariaKeys` — לפי `e.code`, עובד במקלדת עברית | 3 ✅ |

התיעוד המלא של כל פונקציה — בראש הקובץ שלה.

## בדיקות

`node creative-suite/ui-core/tests/browser.cjs` — 83 בדיקות ב-Chromium, בטלפון (390px) ובמחשב (1280px), על דף התצוגה.
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

## איך אפליקציה מצטרפת

1. **הסכמה:** רק הצ'אט של האפליקציה מוסיף אותה ל-`apps.json`:
   ```json
   { "vector-studio": { "css": "assets/css/ui-core", "js": "assets/js/ui-core" } }
   ```
   כל סוג רשות. התיקייה שבצד ימין **כולה של ui-core** — הסנכרון מוחק בה כל קובץ שאינו במקור.
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

## היסטוריה

- **2026-10-31 — שלב 1:** תשתית (`apps.json`, `tools/sync.php`, שער בפריסה), `ui.css`, דף תצוגה.
- **2026-10-31 — שלב 2:** ערכת הממשק — `dom`, `toast`, `dialog`, `menu`, `tabs`, `files`, `store`; בדיקות דפדפן.
- **2026-10-31 — שלב 3:** `keys.js` — קיצורים לפי `e.code`; `keyOf(e)` לתיקון של שורה אחת (`docs/shared-code/migrate/keys.md`).
