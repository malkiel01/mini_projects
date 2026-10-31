# ענן החבילה → ui-core (שלבים 1–2) — הטייס

**סטטוס:** ✅ בוצע — #924, נבדק ב-390px, בלי באגים ב-ui-core. הלקחים — ב-`creative-suite/ui-core/README.md`.
**מאת:** הקוד המשותף · **אל:** ענן החבילה · **תאריך:** 2026-10-31
**למה אתה ראשון:** האפליקציה הקטנה בחבילה, וה-CSS שלך הוא `tokens.css` של הסקיל מילה במילה — המעבר הכי נקי. מה
שנלמד כאן נכנס להנחיות של חמש האחרות.
**ה-API:** `creative-suite/ui-core/README.md` והתיעוד בראש כל קובץ ב-`creative-suite/ui-core/src/js/`.
**תצוגה:** `creative-suite/ui-core/demo/`.

## מה משתנה למשתמש

**רק החלונות** — בטלפון הם עולים מלמטה (גיליון) ולא מכסים את כל המסך, ונסגרים בלחיצה מחוצה להם (הכרעת בעל המערכת
2026-10-31). השאר נראה בדיוק אותו דבר.

## הצעדים — PR אחד, בענף שלך

### 1. הצטרפות
ב-`creative-suite/ui-core/apps.json` (הקובץ שלי — שורה אחת שלך, בהסכמתך):
```json
{ "suite-cloud": { "css": "assets/css/ui-core", "js": "assets/js/ui-core" } }
```
ואז `php creative-suite/ui-core/tools/sync.php`. נוצרות `suite-cloud/assets/css/ui-core/` ו-`assets/js/ui-core/` — **לא
לערוך בהן** (שער בפריסה).

### 2. CSS
- `index.html:9` — לפני `app.css`: `<link rel="stylesheet" href="assets/css/ui-core/ui.css">`
- `assets/css/app.css` — למחוק את שורות 1–199 (מ-`שפת העיצוב "הטלפון קודם"` עד סוף `הדפסה`). נשאר רק `/* ===== ענן החבילה ===== */`.

### 3. JS — `assets/js/app.js`
| שורות היום | להחליף ב- |
| --- | --- |
| `$` (שורה 3) | `import { $, h as el } from './ui-core/dom.js';` |
| `el()` (49–57) | למחוק — `h` מקבל אותה חתימה (`el('div', {class}, …)`). הבדל אחד: מאפיינים מוכרים (`value`, `checked`, `href`) נכנסים כ-property ולא כ-attribute — זהה בדפדפן |
| `toast()` (59–62) | `import { toast } from './ui-core/toast.js';` — `toast(msg, true)` עדיין עובד (שגיאה) |
| IIFE של `--vvh` (66–68) + מאזין `[data-close]` (70) | `import { initDialogs, confirm } from './ui-core/dialog.js'; initDialogs();` |
| `confirm('למחוק לצמיתות…')` (307) | `if (!await confirm('למחוק לצמיתות את כל מה שבסל? אי אפשר לבטל.', { ok: 'מחיקה', danger: true })) return;` |
| גרירת קבצים ל-`#v-files` (227–230) | רשות: `dropZone(filesView, { onFiles: f => state.quota?.quota > 0 ? uploadAll(f) : toast('אין מכסה', true) })`. המחלקה בזמן גרירה — `dropping` (ב-`ui.css`); את `.drop` שלך אפשר למחוק |

ה-`<dialog>` שב-`index.html` נשארים כמו שהם — `ui.css` נותן להם את המראה החדש.

### 4. בדיקה
- `php creative-suite/ui-core/tools/sync.php --check`
- בטלפון: פתיחה של כל חלון (פריט, שם, העברה, מכסה) — עולה מלמטה, ✕ ולחיצה ברקע סוגרים, המקלדת לא מסתירה שדה.
- ריקון הסל — חלון אישור במקום החלון של הדפדפן.
- `conclusion: success`.

## כשסיימת

הודעה אליי (`session_018g4NYGvmE6cvaDDu2KAMoP`): מה עבר, ומה היה מסורבל — זה ייכנס להנחיות של האחרות. מצאת באג
ב-ui-core? אל תתקן בעותק — שלח לי, אני מתקן במקור.
