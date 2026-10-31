# מעבר לעיצוב ולחלונות של ui-core (שלבים 1–2) — עימוד, וקטורי, רסטר, עורך התמלילים

**מאת:** הקוד המשותף · **תאריך:** 2026-10-31
**הטייס:** ענן החבילה עבר בהצלחה (#924) — `migrate/suite-cloud.md`. הלקחים נכנסו לכאן.
**ה-API:** `creative-suite/ui-core/README.md` והתיעוד בראש כל קובץ ב-`creative-suite/ui-core/src/js/`.
**סטודיו העיצוב** — לא כאן: ה-CSS שלו שונה, והוא אחרון (בתיאום נפרד).

## מה משתנה למשתמש

**רק החלונות** — בטלפון הם עולים מלמטה ולא מכסים את כל המסך, ונסגרים בלחיצה מחוצה להם (הכרעת בעל המערכת). חלונות
`confirm` / `prompt` של הדפדפן מוחלפים בחלונות שלנו. השאר — בדיוק אותו דבר.

## לפני שמתחילים

- **למזג `main`** לענף שלך — `apps.json` משתנה כשאפליקציות מצטרפות.
- **מספרי שורות לא מופיעים כאן בכוונה** (הם זזים). כל הפניה — לשם פונקציה או לסימן בקובץ.
- **PR אחד או שניים**, כרצונך: CSS לבד הוא שינוי בטוח; ה-JS יכול לבוא אחריו.

## לקחים מהעימוד (#941)

- **התנגשות שמות בייבוא:** `import { download as save }` התנגש עם `save()` של העורך → `SyntaxError` שהפיל את כל העורך, ו-`node --check`
  לא תפס (ESM). **לפני כל `import` — חיפוש השם בקובץ.** אחרי ההחלפה — לפתוח את הדף בדפדפן, לא רק `--check`.
- **חלון עם טקסט שהוקלד** (עורך, הגדרות ארוכות): `<dialog class="full" data-static>` — כל הגובה בטלפון, ולא נסגר בלחיצה ברקע.
- **`confirm` בתוך `catch` של פונקציה שאינה `async`** — `confirm(…).then(ok => …)` במקום `await`.

## 1. הצטרפות — שורה אחת ב-`creative-suite/ui-core/apps.json`

לפי א"ב, שורה לכל אפליקציה. מי שכבר שם עם `js` (רסטר, וקטורי) — מוסיף `css` לאותה שורה:

| אפליקציה | השורה |
| --- | --- |
| עימוד | `"layout-studio": { "css": "assets/css/ui-core", "js": "assets/js/ui-core" },` |
| רסטר | `"raster-studio": { "css": "assets/css/ui-core", "js": "assets/js/ui-core" },` |
| וקטורי | `"vector-studio": { "css": "assets/css/ui-core", "js": "assets/js/ui-core" },` |
| עורך התמלילים | `"word-processor": { "css": "assets/css/ui-core", "js": "assets/js/ui-core" },` |

ואז `php creative-suite/ui-core/tools/sync.php`.

## 2. CSS — זהה בארבע

1. בכל דף HTML שטוען את `app.css` — **לפניו**: `<link rel="stylesheet" href="assets/css/ui-core/ui.css">`
   (עימוד: `index.html`, `editor.html`; וקטורי ורסטר: `index.html`, `editor.html`; עורך התמלילים: `index.html`,
   `guest.html`. `layout-studio/lab/` — רשות.)
2. ב-`assets/css/app.css` — למחוק את הבלוק שהועתק מ-`tokens.css` של הסקיל: מ-`/* ===== משתנים =====` ועד סוף
   `/* ===== הדפסה … */` (כולל). **מה שאינו בסקיל — נשאר.** כדי לראות בדיוק מה שלך בתוך הבלוק:
   ```bash
   grep -vxFf <(sed 's/^ *//' .claude/skills/phone-first-design/references/tokens.css) <(sed 's/^ *//' <app>/assets/css/app.css) | grep -v '^$'
   ```
   כל שורה שהפקודה מדפיסה — של האפליקציה, ונשארת (ידוע: הרסטר — `.handoff-bar`; העימוד — `.doc-row`, `.page-ic`,
   `.stage { --paste… }`; עורך התמלילים — `.import-report`, `.merge-questions`; וקטורי — `.state`, `.sr`, `body.editor`).
3. **`dialog` / `.sheet-head` / `.sheet-body` שלך** — אם דרסת אותם ב-`app.css`, בדוק שהם לא מבטלים את הגיליון החדש
   (`width`, `height`, `border-radius`, `margin`). מה שדורס בכוונה — נשאר.

## 3. JS — לפי אפליקציה

בכל המקומות: `import … from './ui-core/<module>.js'` (הנתיב — יחסית לקובץ שמייבא).

### משותף לכולן
| מה יש היום | מה במקום |
| --- | --- |
| IIFE שקובע `--vvh` מ-`visualViewport` | `initDialogs()` פעם אחת בטעינה (מ-`dialog.js`) — קובע גם `--kb` (גובה המקלדת), שהגיליון צריך |
| מאזין `click` ל-`[data-close]` | נמחק — `initDialogs()` עושה את זה, ומוסיף סגירה בלחיצה על הרקע |
| `toast(msg, err)` משלך | `import { toast } from './ui-core/toast.js'` — `toast(msg, true)` עדיין שגיאה |
| `confirm('…')` של הדפדפן | `await confirm('…', { ok: 'מחיקה', danger: true })` — הפונקציה הקוראת צריכה להיות `async` |
| `prompt('…', v)` של הדפדפן | `await prompt('…', { value: v })` — `null` בביטול, כמו היום |
| `alert('…')` | `toast('…', true)` |
| `download(blob, name)` משלך | `import { download } from './ui-core/files.js'` — כבר נכנס לתוך `<dialog>` פתוח (הווקטורי פתר את זה; עכשיו כולם) |

**חלון שלא צריך להיסגר בלחיצה על הרקע** (טופס ארוך) — `<dialog data-static>`.
**חלון שצריך את כל הגובה** (הגדרות עמוד) — `<dialog class="full">`.

### עימוד
- `app.js` ו-`editor.js` — **שני העותקים** של `toast`, של ה-IIFE של `--vvh` ושל מאזין `[data-close]` → `initDialogs()` ו-`toast`
  מ-ui-core.
- `$` — אפשר להשאיר, או `import { $ } from './ui-core/dom.js'` (אותה חתימה).
- `confirm` — 7 מקומות (שינוי שם / מחיקה / התנגשות גרסה) → `confirm` של ui-core.
- `download(bytes, type, name)` → `download(new Blob([bytes], { type }), name)`.

### וקטורי
- **`$` שלך מקבל id** (`$('stage')`) — **לא להחליף** ב-`$` של ui-core (שמקבל בורר). להשאיר כמו שהוא.
- `editor.js` — `toast`, `download` ומאזין `[data-close]` → ui-core. `app.js` — מאזין `[data-close]` → `initDialogs()`.
- `--vvh` — **לא נקבע היום בכלל** (ה-CSS קורא לו). `initDialogs()` מתקן את זה: החלונות לא ייחתכו מתחת למקלדת.
- `confirm` / `prompt` / `alert` — 12 מקומות ב-`app.js`, `editor.js`, `props.js` (שינוי שם מסמך / שכבה / סגנון, מחיקה,
  שחזור) → `confirm` / `prompt` / `toast`. **הפופאובר של הצורות והמכחולים** (`showShapePick` / `showFreePick`) — רשות:
  `menu(button, items)` נותן Esc וחצים, שאין היום.

### רסטר
- `api.js` — `toast` → `export { toast } from './ui-core/toast.js';` (כל מי שמייבא מ-`api.js` ממשיך לעבוד).
- `export.js` — `download` ו-`fileName` → `export { download, fileName } from './ui-core/files.js';` (אותה חתימה).
- `app.js` — ה-IIFE של `--vvh` ומאזין `[data-close]` → `initDialogs()`. **בעורך (`editor.js`) `--vvh` לא נקבע היום** —
  `initDialogs()` גם שם.
- `editor.js` — שני המאזינים הייעודיים לסגירת `versionsDlg` ו-`exportDlg` → נמחקים (`initDialogs()`).
- `confirm` — 5 מקומות (הקטנת תמונה, התנגשות, גרסה חדשה, שינויים שלא נשמרו) → `confirm` של ui-core.
- `$` — 5 עותקים זהים (`app.js`, `editor.js`, `tools.js`, `layers-panel.js`, `adjust-panel.js`) → `import { $ } from './ui-core/dom.js'`.

### עורך התמלילים
`ui.js` שלך הוא כמעט המקור של ui-core. הדרך הבטוחה — **`ui.js` הופך למתאם דק**, וכל 25 הקוראים ל-`sheet` לא משתנים:
```js
// word-processor/assets/js/ui.js
import { sheet as uiSheet } from './ui-core/dialog.js';
import { toast as uiToast } from './ui-core/toast.js';
export { h } from './ui-core/dom.js';                        // אותו בורר 'tag.cls'
export const toast = (msg, err = false, action = null) => uiToast(msg, { kind: err ? 'error' : '', action });
export const sheet = (title, fill, onSave, saveLabel = 'אישור') =>
    uiSheet({ title, body: fill, onSubmit: onSave, submit: onSave ? saveLabel : null }).el;   // מחזיר את ה-<dialog>, כמו היום
// field, seg, sw, numInput, fontSelect, when — נשארים שלך (fontSelect יעבור לשלב 8, הגופנים)
```
- `app.js` — `choose()` שלך → `import { choose } from './ui-core/dialog.js'` (אותה חתימה: `title, text, [[key, label, kind]]`).
- `app.js` — `popMenu(anchor, items)` → `menu(anchor, items.map(([icon, label, run]) => ({ icon, label, run })))` —
  מקבל Esc, חצים, ומיקום שלא נחתך (היום: היסט קבוע של ‎−160px).
- `app.js` — ה-IIFE של `--vvh` → `initDialogs()`.
- `confirm` / `prompt` — 10 מקומות (`app.js`, `dialogs-styles.js`) → `confirm` / `prompt` של ui-core.
- **הסרט, סרגל הכלים בטלפון ו-`--kb` שלך** — לא נוגעים (`--kb` של ui-core נקבע על `<html>`; אם אתה קובע `--kb` על
  אלמנט אחר — אין התנגשות).

## 4. בדיקה

- `php creative-suite/ui-core/tools/sync.php --check`
- בטלפון (390px): כל חלון — עולה מלמטה, ✕ ולחיצה ברקע סוגרים, שדה לא מוסתר מתחת למקלדת.
- כל `confirm` שהוחלף — חלון שלנו, וביטול באמת מבטל.
- במחשב: אותו מראה כמו היום (חוץ מהחלון).
- `conclusion: success`.

## כשסיימת

הודעה אליי (`session_018g4NYGvmE6cvaDDu2KAMoP`): מה עבר, מה היה מסורבל, וסמן `[x]` ב"הצטרפות" במסמך הפרויקט שלך.
באג ב-ui-core — לא בעותק; אליי, ואני מתקן במקור.
