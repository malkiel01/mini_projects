# תיקון: קיצורי מקלדת במקלדת עברית — רסטר, וקטורי, סטודיו העיצוב

**סטטוס:** ✅ בוצע — רסטר #923, סטודיו העיצוב #927, וקטורי #928.
**מאת:** הקוד המשותף · **תאריך:** 2026-10-31 · **המודול:** `creative-suite/ui-core/src/js/keys.js`
**עדיפות:** ראשונה (המלצת הצ'אט המתכלל) — באג שמשתמשים מרגישים היום.

## הבאג

הקיצורים נבדקים לפי `e.key`. כשהמקלדת בעברית, Ctrl+Z שולח `e.key = 'ז'`, ו-`k === 'z'` לא מתקיים — **ביטול, ביצוע,
שכפול, קיבוץ ושמירה לא עובדים**, וגם אותיות הכלים (V, P, T…). עורך התמלילים כבר בודק לפי `e.code` (המקש הפיזי) ולא
סובל מזה. *(נבדק בסימולציה בדפדפן — `tests/browser.cjs`; כדאי לאשר במקלדת אמיתית.)*

## התיקון — שורה אחת בכל מקום

`keyOf(e)` מחזיר את האות הלטינית של המקש הפיזי: `z` גם במקלדת עברית; ספרות וסימנים (`=`, `-`, `[`) — אותו דבר; כל מקש
אחר (`escape`, `arrowleft`, `delete`, `' '`) — כמו `e.key.toLowerCase()` היום. **שאר הקוד לא משתנה.**

### 1. הצטרפות (רק `js`)
שורה שלך ב-`creative-suite/ui-core/apps.json`, ואז `php creative-suite/ui-core/tools/sync.php`:

| אפליקציה | השורה |
| --- | --- |
| רסטר | `"raster-studio": { "js": "assets/js/ui-core" }` |
| וקטורי | `"vector-studio": { "js": "assets/js/ui-core" }` |
| סטודיו העיצוב | `"greeting-cards": { "js": "editor/js/ui-core" }` |

(`css` — בשלב המעבר של ה-CSS, לא עכשיו. אפשר להוסיף כבר אם רוצים.)

### 2. ההחלפה

**רסטר**
- `assets/js/editor.js:619` — `const k = e.key.toLowerCase();` → `const k = keyOf(e);`
- `assets/js/tools.js:727` — אותו דבר.
- `import { keyOf } from './ui-core/keys.js';` בשני הקבצים.

**וקטורי** — `assets/js/editor.js`
- `1175` — `const k = e.key.toLowerCase();` → `const k = keyOf(e);`
- `1197` — `TOOL_KEYS[e.key]` (פעמיים) → `TOOL_KEYS[k]` (`\\` של כלי הקו — `keyOf` מחזיר `\\`).
- `import { keyOf } from './ui-core/keys.js';`

**סטודיו העיצוב** — `editor/js/main.js`, ב-`onStageKey` (‏1649)
- `const k = e.key` **נשאר** (ההשוואות שם הן `'Tab'`, `'Delete'`, `'ArrowLeft'` — לא תלויות שפה).
- שלוש ההשוואות `k.toLowerCase() === 'z'` / `'y'` / `'d'` (1663, 1664, 1669) → `keyOf(e) === 'z'` / `'y'` / `'d'`.
- `import { keyOf } from './ui-core/keys.js';`

**עימוד** — לא צריך: הקיצורים שלו (`=`, `-`, `0`) זהים בשתי השפות.

### 3. בדיקה
- `php creative-suite/ui-core/tools/sync.php --check`
- במחשב, **מקלדת בעברית**: Ctrl+Z / Ctrl+Shift+Z / Ctrl+Y, Ctrl+D, Ctrl+G, ואותיות הכלים.
- בתוך שדה טקסט (שם שכבה) — Ctrl+Z עדיין של השדה.
- `conclusion: success`.

## הצעד הבא (רשות, לא עכשיו)

במקום שרשרת `if`: `shortcuts().add('Mod+Z', undo, { label: 'ביטול' })` — רישום אחד, `title` ו-`aria-keyshortcuts`
לכפתורים (`keys.title(btn, 'Mod+Z')`), ובלי לבדוק "האם אני בתוך שדה" בכל קיצור. התיעוד — בראש `keys.js`.
