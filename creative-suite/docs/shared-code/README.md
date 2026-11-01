# קוד משותף לחבילת היצירה — מיפוי ותוכנית

**הבעלים:** הצ'אט של הקוד המשותף (ארכיטקט) — `session_018g4NYGvmE6cvaDDu2KAMoP`.
**המטרה (בעל המערכת):** "קוד אחד, כתוב בצורה אחידה ובמקום אחד, שכל אפליקציה מושכת ממנו — אפילו ברמת תפריט,
חלונות, אופציות לעיצוב פונטים, שליטה וגרירה של תמונות".

**המצב:** שלבים 1–3 בנויים ופרוסים ([`creative-suite/ui-core/`](../../ui-core/)). ענן החבילה עבר (הטייס); רסטר, וקטורי וסטודיו העיצוב — תיקון המקלדת העברית; הנחיות ה-CSS והחלונות נשלחו לעימוד, לווקטורי, לרסטר ולעורך התמלילים. שלבים 4 (זום), 5 (ידיות) 6 (ביטול), 7 (צבע), 8 (גופנים) ו-9 (שכבות) בנויים. הבא: שלב 10 — שמירה אוטומטית.

---

## התקדמות

- [x] מיפוי של שש האפליקציות (2026-10-31)
- [x] תוכנית: מודולים, סדר, ממשק, מעבר (המסמך הזה)
- [x] אישור בעל המערכת לתוכנית ולהכרעות (2026-10-31)
- [x] הכרעת הצ'אט המתכלל על הבעלות — `ui-core` מחליף את "מעטפת ממשק"; הרסטר נשאר המקור ללוח השכבות ול-`History` (#917)
- [x] שלב 1 — `ui-core`: תשתית (מקור, `apps.json`, `sync.php`, שער בפריסה) + `ui.css` + דף תצוגה
- [x] שלב 2 — ערכת הממשק (`dom`, `toast`, `dialog`, `menu`, `tabs`, `files`, `store`)
- [x] שלב 3 — `keys` (קיצורים לפי `e.code`)
- [x] טייס — ענן החבילה עבר לשלבים 1–2 (#924)
- [x] תיקון המקלדת העברית — רסטר (#923), סטודיו העיצוב (#927), וקטורי (#928)
- [ ] מעבר שלבים 1–2 — עימוד, וקטורי, רסטר, עורך התמלילים, סטודיו העיצוב (אחרון)
- [x] שלב 4 — `viewport` + `gestures` (מעבר: `migrate/viewport.md` — רסטר, וקטורי)
- [x] 👤 `account.js` + `php/account` — תפריט המשתמש, הגדרות אישיות, ייצוא ומחיקת חשבון (`requests/account-menu.md`; `migrate/account.md`)
- [x] 📁 `files-window.js` — "המסמכים שלי" (שורה 20 ב-`integration.md`): לשונית "באפליקציה" + ענן החבילה
- [x] שלב 5 — `transform` + `snap` + `handles` (`migrate/handles.md`)
- [x] שלב 6 — `history` (`migrate/history.md`)
- [x] שלב 7 — `color` + בורר צבעים (`migrate/color.md`)
- [x] שלב 8 — `fonts` + בורר גופנים (`migrate/fonts.md`)
- [x] שלב 9 — לוח שכבות (`migrate/layers.md`)
- [ ] שלב 10 — `autosave`

## ממתין לבעל המערכת

- [x] לאשר את התוכנית ואת ההכרעות (2026-10-31)

---

## 1. המיפוי — מה חוזר, איפה, וכמה

קיצורים: **WP** עורך התמלילים · **RS** רסטר · **VS** וקטורי · **LS** עימוד · **GC** סטודיו העיצוב
(`greeting-cards/editor`) · **SC** ענן החבילה.

### 1.1 העיצוב (CSS) — אותו קובץ, מועתק חמש פעמים

| אפליקציה | שורות CSS | מתוכן מילה במילה מ-`tokens.css` של הסקיל |
| --- | --- | --- |
| WP `assets/css/app.css` | 294 | 185 |
| RS `assets/css/app.css` | 375 | 191 |
| VS `assets/css/app.css` | 283 | 177 |
| LS `assets/css/app.css` | 228 | 171 |
| SC `assets/css/app.css` | 211 | 183 |
| GC `editor/css/editor.css` | 335 | 35 — **אותם שמות מחלקה, CSS אחר** |

**~900 שורות זהות.** שינוי בשפת העיצוב היום = חמש עריכות. GC שונה: `--tap: 44px`, גופן Heebo 16px, ו-`.seg`,
`.tile`, `.toast`, `.sheet-*`, `.list`, `.card` — אותו שם, התנהגות אחרת. **זה ניגוד, לא רק כפילות.**

### 1.2 מעטפת הממשק

| נושא | WP | RS | VS | LS | GC | SC |
| --- | --- | --- | --- | --- | --- | --- |
| **חלון / גיליון** | `sheet(title, fill, onSave)` בונה `<dialog>` (`ui.js:38`, 28 ש') | `<dialog>` סטטי | `<dialog>` סטטי | `<dialog>` סטטי | **div משלו** `sheet(title, body, {actions})` עם מלכודת פוקוס, החזרת פוקוס, סגירה ברקע (`ui.js:47`, 40 ש') | `<dialog>` סטטי |
| סגירת `[data-close]` | — | ×2 | ×2 | ×2 | — | ×1 |
| `--vvh` (גובה מעל המקלדת) | יש | רק בדף הבית | **אין** (ה-CSS קורא לו) | ×2 | — | יש |
| **אישור / שאלה** | `choose()` + `confirm`/`prompt` מקוריים ×10 | `confirm` ×5 | `confirm`/`prompt`/`alert` ×8 | `confirm` ×7 | `confirmDialog()` → Promise | `confirm` ×1 |
| **טוסט** | `toast(msg, err, action)` 2.8ש' | 2.6ש' | 3.5ש' | ×2, 2.6ש' | `toast(msg, kind)` 3/6ש', מחלקת `.show` | 2.6ש' |
| **תפריט** | `popMenu(anchor, items)` — בלי Esc, בלי חצים, היסט קבוע ‎−160px | — | שני פופאובר `role=menu` — בלי Esc | — | תפריטים כגיליון `.menu-item` | — |
| **לשוניות** | סרט מלא, ARIA, חצים הפוכים ל-RTL | `.seg-tabs` בלי מקלדת | כנ"ל | כנ"ל | `tabs()` — ARIA, RTL, זוכר | `.tabs` |
| בונה DOM | `h('tag.cls', attrs, …kids)` | `$` ×5 | `$=getElementById` | `$` ×2 + innerHTML | `h(tag, props, …kids)` | `el()` + `$` |
| הורדת קובץ | `downloadLocalCopy` | `download`, `fileName` | `download` (בתוך ה-dialog הפתוח) | `download` | `download` + גשר אנדרואיד | `a[download]` |
| בחירת קובץ | input נסתר | input נסתר | input נסתר | ×3 inputs | `pickFiles(accept, multiple)` → Promise | input + גרירה |
| גרירת קובץ לדף | — | — | — | — | רק באזור ייבוא | יש |

### 1.3 קנבס ועריכה

| נושא | RS | VS | LS | GC | WP |
| --- | --- | --- | --- | --- | --- |
| **זום / הזזה** | `engine/view.js` — **מודול טהור** (58 ש'): `fit`, `zoomTo`, `screenToDoc`, `pinch`, `zoomLabel` | בתוך `editor.js` (~60 ש'), `{x,y,z}` | `PageView` — גלילה של הדפדפן (scroll + spacer) | Konva, `{zoom,x,y}` | `transform: scale` + pinch במגע |
| גלגלת | `deltaMode`, Shift אופקי | Ctrl=זום, בלי `deltaMode` | רק Ctrl | Ctrl=זום | **אין** |
| צביטה | pointer Map | pointer Map | touch events | pointer Map | touch events |
| **ידיות** | `engine/transform.js` (154 ש') — מטריצה, `hit`, `dragHandle`, `dragRotate` + **עותק נפרד** לחיתוך (`tools.js:336`) | `render.js` + `editor.js:910-1068` (~90 ש') | **אין** — רק מספרים בחלון | `Konva.Transformer` | ידית פינה אחת לתמונה (`extensions.js:821`) |
| Shift בשינוי גודל | **משחרר** יחס | **נועל** יחס | — | יחס נעול תמיד | יחס נעול |
| סיבוב | גרירה | גרירה, Shift=15° | — | הצמדה ל-45° | כפתורים, 90° |
| **הצמדה** | — | `snap.js` (74 ש', טהור) + קווי עזר | — | `snap()` (~30 ש') + קווים ורודים | — |
| בחירה מרובה | — | Shift + מלבן | — | Shift/Ctrl + תיבה בשכבות | — |
| **ביטול / ביצוע** | `History` — פקודות + תקציב בתים (230 ש') | תמונות JSON, 200 (~50 ש') | — (רק ProseMirror) | `History` — תמונות JSON, 200 (51 ש') | ProseMirror |
| חיצי ביטול | ↷ (מראה RTL) | ↶ | — | ? | — |
| **לוח שכבות** | `layers-panel.js` (404 ש') — גרירה לסידור, שינוי שם במקום, קבוצות, תמונות ממוזערות | `renderLayers` (40 ש') — ▲▼, `prompt` | — | `layersSheet` (34 ש') — ▲▼ | — |

### 1.4 צבע וגופנים

| נושא | WP | RS | VS | LS | GC |
| --- | --- | --- | --- | --- | --- |
| **בורר צבע** | `type=color` מקורי | `type=color` בעיגול | `type=color` + דוגמיות שמורות (68 ש') | — | **בורר מלא** `colorpicker.js` (205 ש'): ריבוע SV, גוון, שקיפות, hex/RGB, אחרונים, מועדפים, צבעי העיצוב, טפטפת |
| המרות צבע | — | `hexToRgb`/`rgbToHex` | `withAlpha`, `parseColor` | — | `parseHex`, `toHex`, HSV |
| **רשימת גופנים** | 15 (+ כינויי Word) | 3 | 7 | 2 | 10 |
| טעינה | `@font-face` סטטי woff2 | `@font-face` woff2 | **FontFace + TTF** | TTF ל-HarfBuzz | `@font-face` woff2 + `ensureFont()` |
| גופן של המשתמש | — | — | — | — | `registerFont`, `readFontInfo` (קורא טבלאות name/OS2) |
| **בורר גופן** | `<select>` עם תצוגה | `<select>` | `<select>` | רדיו | רשימה, כל שורה בגופן שלה, + סרגל משקל |

**חמש רשימות גופנים שונות, ושלוש דרכים לטעון אותם.** אותם קובצי woff2 (Heebo, Rubik, Assistant, Frank Ruhl…) יושבים
בכמה אפליקציות במקביל.

### 1.5 מקלדת

| | WP | RS | VS | LS | GC |
| --- | --- | --- | --- | --- | --- |
| רישום | `COMMANDS` (מחרוזת תצוגה) + שרשרת if | 3 מאזינים | שרשרת if + `TOOL_KEYS` | שרשרת if | שרשרת if |
| התאמה | **`e.code`** ✅ | `e.key` | `e.key` | לא תלוי פריסה | `e.key` |

**באג משותף כנראה:** ב-RS, VS ו-GC הקיצורים (Ctrl+Z, אותיות כלים) נבדקים לפי `e.key`. **במקלדת עברית** `e.key` הוא
אות עברית (ז, ט…), ולכן הקיצור לא נורה. WP כבר פתר את זה עם `e.code`. *(נבדק בסימולציה בדפדפן בשלב 3, ותוקן בשלוש האפליקציות.)*

### 1.6 שמירה וכלי עזר

| נושא | איפה | הערה |
| --- | --- | --- |
| שמירה אוטומטית | WP `createSaver` (140 ש', מצבים + נסיונות חוזרים), VS `persist` (1.2ש'), LS `save` (0.8ש'), GC `scheduleSave` (0.8ש'), RS טיוטה (1.5ש') | חמש מימושים לאותו דבר |
| IndexedDB | WP `drafts`, RS `idb.js` (33 ש'), GC `db.js` | שלושה עטיפות |
| localStorage בטוח | WP `store` ×3, VS `drafts.js`/`pref`, GC מפתחות `gc-*` | |
| עטיפת API | WP, RS, VS, LS ×2, GC, SC — **שמונה**, חוזים שונים (`{success}` / `{ok}`, GET / POST, כותרת CSRF שונה) | **לא נכלל כאן** — כל אפליקציה וחוזה השרת שלה |
| בידוד LTR בעברית | RS `dims()` (`<bdi>`), LS `ltrDims()` (LRI/PDI), GC ×4 | |
| `handoff.js` | RS ו-VS — **זהים חוץ משתי שורות** | של ליבת הפורמט / ההטמעה — לא של `ui-core` |

---

## 2. העיקרון

1. **מקור אחד, עותק מסונכרן** — בדיוק כמו `mbe-core` ו-`pdf-core`. אפליקציה עצמאית (CLAUDE.md): אין `import` מחוץ
   לתיקייה שלה. עורכים רק ב-`creative-suite/ui-core/src`, `sync.php` מעתיק, `sync.php --check` עוצר פריסה של עותק ששונה.
2. **מודולים קטנים, לא מסגרת.** JS טהור, ES modules, בלי בנייה ובלי תלויות. כל מודול עומד בפני עצמו (תלוי לכל
   היותר ב-`dom.js`). אפליקציה לוקחת את מה שהיא צריכה.
3. **מבחוץ — ממשק; מבפנים — של האפליקציה.** המודול לא יודע מה זה מסמך, שכבה או `.mbe`. הוא מקבל callbacks
   (`onMove`, `onCommit`) ומחזיר אובייקט. לכן הוא מתאים גם ל-Konva של GC וגם ל-WebGL של RS.
4. **נבנה מהקוד הטוב שכבר קיים** — לא מאפס. לכל מודול יש "מקור" באחת האפליקציות (הטבלה בסעיף 3).
5. **RTL, מקלדת עברית, טלפון ונגישות — בפנים**, פעם אחת: חצים הפוכים, `e.code`, `--vvh`, פוקוס, `aria-*`.
6. **לא שוברים:** כל מודול נכנס לצד הקוד הקיים, אפליקציה אחר אפליקציה, וכל מעבר הוא PR נפרד של הצ'אט שלה.

## 3. המודולים — לפי סדר (הכי הרבה כפילות × הכי פחות סיכון קודם)

| # | מודול | מה | נבנה מ- | יחליף | סיכון |
| --- | --- | --- | --- | --- | --- |
| 1 | `css/ui.css` | משתנים, בסיס, רכיבים, חלון, טוסט, תפריט | `tokens.css` של הסקיל | ~900 ש' ב-5 אפליקציות | נמוך — CSS בלבד |
| 2 | `dom.js` `toast.js` `dialog.js` `menu.js` `tabs.js` `files.js` `store.js` | ערכת הממשק | WP `ui.js`, GC `ui.js`, RS `export.js`/`idb.js` | ~30 `confirm`/`prompt`, 7 טוסטים, 6 `--vvh`, 5 `download` | נמוך |
| 3 | `keys.js` | רישום קיצורים לפי `e.code` | WP `commands.js` | 6 שרשראות if; תיקון מקלדת עברית | נמוך |
| 4 | `viewport.js` `gestures.js` | זום, הזזה, צביטה, גלגלת | RS `engine/view.js` + צביטה של RS | VS, GC, (LS, WP — מתאם) | בינוני |
| 5 | `transform.js` `snap.js` `handles.js` | ידיות, גרירה, שינוי גודל, סיבוב, הצמדה | RS `transform.js` + VS `snap.js` | RS (×2), VS, WP | בינוני-גבוה |
| 6 | `history.js` | ביטול / ביצוע | RS `History` + ממשק התמונות של GC | VS, GC | בינוני |
| 7 | `color.js` `colorpicker.js` | המרות + בורר מלא | GC `colorpicker.js` | `type=color` בכולן | נמוך |
| 8 | `fonts.js` `fontpicker.js` + `fonts/` | קטלוג גופנים עבריים, טעינה, גופן משתמש, בורר | GC `fonts.js` + `fonts.css` | 5 רשימות, 3 טוענים | בינוני (קבצים בינאריים) |
| 9 | `layers-panel.js` | לוח שכבות | RS `layers-panel.js` | VS, GC | בינוני |
| 10 | `autosave.js` | שמירה אוטומטית + טיוטה מקומית | WP `saver.js` | VS, LS, GC, RS | בינוני |

**מחוץ ל-`ui-core`:** עטיפות API (חוזה שרת של כל אפליקציה), `handoff.js` (פרוטוקול — ליבה/הטמעה), עיצוב טקסט
ו-`shapeLine` (בעלים: עימוד), הרכבת שכבות ומיזוג (בעלים: רסטר), גאומטריית נתיבים (בעלים: וקטורי).

## 4. ה-API

כל הפונקציות — `import { … } from './ui-core/<module>.js'` (הנתיב היחסי לפי `apps.json` של האפליקציה).

### 4.1 `ui.css`
- המשתנים של הסקיל (`--bg … --head`) + מצב כהה. **מותג:** אפליקציה דורסת רק `--brand`/`--brand-soft` בקובץ שלה.
- כל הרכיבים של הסקיל (`.top`, `.btn`, `.seg`, `.fields`, `.list`, `.tile`, `dialog`, `.toast`…) + `.menu`, `.sheet`
  (גיליון תחתון לא-מודאלי), `.handles`, `.swatch`.
- האפליקציה טוענת `ui-core/ui.css` ראשון, ואז את ה-`app.css` שלה — שבו נשאר **רק מה ששלה**.
- הסקיל `phone-first-design` ממשיך להיות התיעוד; `tokens.css` שלו יפנה ל-`ui-core` כמקור (בתיאום).

### 4.2 ערכת הממשק
```js
// dom.js
h('button.btn.primary', { onclick, 'aria-label': 'שמירה' }, 'שמירה')   // → Element
$(sel, root?)  $$(sel, root?)                                         // querySelector / All
ltr(text)                                                             // LRI…PDI — מספרים ומידות בתוך עברית

// toast.js
toast(msg, { kind: 'ok'|'error', action: { label, run }, ms })        // אלמנט יחיד, נוצר לבד

// dialog.js — <dialog> מקורי (חלון במחשב, גיליון מלא בטלפון), עם מה שחסר בו:
//   החזרת פוקוס, סגירה ברקע (רשות), Esc, --vvh, [data-close], שגיאה ב-.error
const d = sheet({ title, body: el | (bodyEl, dlg) => void, submit: 'שמירה',
                  onSubmit: async (form) => false|void, onClose })   // → { el, close() }
await confirm(text, { ok: 'מחיקה', danger: true })                    // → boolean
await prompt(label, { value, select: 'name' })                        // → string | null
await choose(title, text, [['keep','לשמור'], ['drop','לוותר','danger']]) // → key | null
initDialogs()                                                         // פעם אחת: [data-close] + --vvh

// menu.js — תפריט קופץ ותפריט הקשר
menu(anchor, [{ icon, label, run, danger, disabled } | '-'], { align: 'start' })
contextMenu(el, (e) => items)            // לחיצה ימנית + לחיצה ארוכה במגע (500ms)
// Esc, חצים (הפוכים ב-RTL), Home/End, מיקום לוגי שנשאר במסך, פוקוס חוזר

// tabs.js
tabs(root, { onChange, remember: 'key' })   // role=tablist, roving tabindex, חצים RTL

// files.js
download(blob, name)        // a[download] — גם בתוך <dialog> פתוח, גם בגשר אנדרואיד אם קיים
fileName(title, ext)        // ניקוי תווים, 80 תווים
pickFiles({ accept, multiple }) // → Promise<File[]>
dropZone(el, { accept, onFiles })  // גרירת קובץ לדף

// store.js
pref(ns) → { get(k, def), set(k, v) }      // localStorage בטוח (try/catch), מרחב שמות
kv(dbName) → { get, set, del }             // IndexedDB פשוט
```

### 4.3 `keys.js`
```js
const keys = shortcuts(root, { skipInFields: true });
keys.add('Mod+KeyZ', undo, { label: 'ביטול' });   // לפי e.code — עובד במקלדת עברית
keys.add('KeyV', () => setTool('select'));
keys.label('Mod+KeyZ')   // → "Ctrl+Z" / "⌘Z" לטולטיפ ול-aria-keyshortcuts
```

### 4.4 `viewport.js` + `gestures.js`
```js
// viewport.js — טהור, בלי DOM (RS view.js מורחב)
fit(docW, docH, vw, vh, { pad, inset, max })  zoomTo(view, z, sx, sy)  zoomBy  nextStep
screenToDoc(view, x, y)  docToScreen(view, x, y)  pinch(start, a0, b0, a1, b1)  zoomLabel(z)

// gestures.js — מחבר אלמנט לתצוגה
const g = bindViewport(el, { get: () => view, set: v => { view = v; draw(); },
    wheel: 'zoom-with-ctrl', panWith: ['space', 'middle', 'two-fingers'],
    onTap, onDoubleTap, onLongPress });
```

### 4.5 `transform.js` + `snap.js` + `handles.js`
```js
// transform.js — טהור (RS transform.js): מטריצות, corners, bounds, hit, dragHandle, dragRotate
// snap.js — טהור (VS snap.js): snapper({ targets, tol, grid }) → { box(b), point(p) } + guides
// handles.js — מחבר: ציור ידיות על שכבת overlay וגרירה עם pointer capture
const t = handles(overlay, { box, view, rotate: true, keepRatio: 'default'|'shift',
    snap, onPreview(box), onCommit(box), onCancel });
```

### 4.6 `history.js`
```js
const hist = new History({ maxSteps: 200, maxBytes, onChange });
hist.snapshot(state)                       // הדרך הפשוטה (VS, GC)
hist.push({ label, undo(), redo(), bytes }) // הדרך המלאה (RS)
hist.undo()  hist.redo()  hist.canUndo  hist.canRedo  hist.label('undo')
```

### 4.7 צבע וגופנים
```js
// color.js
parse(css) → {r,g,b,a}  toHex(c, alpha)  rgbToHsv  hsvToRgb  contrast(a, b)  isLight(c)
// colorpicker.js
colorPicker({ value, alpha, onInput, onCommit, docColors, presets, eyedropper, storeKey })

// fonts.js — קטלוג אחד של גופנים עבריים חופשיים (woff2, עברית+לטינית)
FONTS            // [{ family, label, weights, category }]
ensureFont(family, weight) → Promise
registerFont({ family, weight, blob })   readFontInfo(file)
// fontpicker.js
fontPicker({ value, weight, fonts, onChange, allowUpload })
```

## 5. התשתית — כמו `mbe-core`

```
creative-suite/ui-core/
  README.md          ← ה-API המלא, כללים, היסטוריית גרסאות
  apps.json          ← { "<app>": { "js": "assets/js/ui-core", "css": "assets/css/ui-core" } }
  src/js/…           ← המודולים
  src/css/ui.css
  src/fonts/…        ← שלב 8 בלבד
  tools/sync.php     ← מעתיק; --check = שער
  tests/             ← בדיקות node לחלקים הטהורים (viewport, transform, snap, color, history, keys)
  demo/index.html    ← כל הרכיבים בדף אחד — לבדיקה בטלפון
```

שער חדש ב-`.github/workflows/deploy.yml`: `php creative-suite/ui-core/tools/sync.php --check` (ליד שני הקיימים).
**`--exclude`:** לא צריך — אין נתונים בשרת.

## 6. איך כל אפליקציה עוברת — בלי לשבור

**כלל:** אני לא עורך קוד של אפליקציה. לכל אפליקציה — הודעה ישירה לצ'אט שלה עם "מה להחליף ואיך", והיא מצטרפת
ל-`apps.json` בהסכמתה, ב-PR שלה.

**צעדים לכל אפליקציה, לכל מודול:**
1. **הצטרפות:** הצ'אט מוסיף שורה ב-`creative-suite/ui-core/apps.json`, מריץ `sync.php`. העותק נכנס — אף קובץ קיים לא
   משתנה. **הפריסה עוברת והאפליקציה זהה.**
2. **החלפה:** מחליף import אחד בכל פעם (למשל `toast`), מוחק את המימוש שלו, בודק בטלפון. PR נפרד אם הוא גדול.
3. **ניקוי:** מוחק מה-`app.css` את מה שכבר ב-`ui.css`.
4. **אימות:** `conclusion: success` + בדיקה בטלפון לפי DevLoop של האפליקציה.

**כתיבת ההנחיות (לקחי הטייס):** מפנים **לשמות פונקציות**, לא למספרי שורות — אלה זזים. כל הנחיה — עם דוגמה לתנאי
שנשאר באפליקציה (למשל מכסה ב-`dropZone`). ו-`apps.json` — שורה לכל אפליקציה, לפי א"ב, ולמזג `main` לפני ה-PR.

**סדר האפליקציות (לשלבים 1–2):** ענן החבילה (הכי קטן — טייס) → עימוד → וקטורי → רסטר → עורך התמלילים → סטודיו
העיצוב (אחרון — ה-CSS שלו שונה, ויש לו את הבורר והגיליון הטובים ביותר שמהם נבנים המודולים).

**לפי מודול — מי מושך:**

| מודול | SC | LS | VS | RS | WP | GC |
| --- | --- | --- | --- | --- | --- | --- |
| 1–2 CSS + ערכה | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ (אחרון) |
| 3 קיצורים | — | ✅ | ✅ | ✅ | ✅ | ✅ |
| 4 זום | — | מתאם | ✅ | ✅ | — | — (Konva) |
| 5 ידיות | — | ✅ (חדש — היום אין) | ✅ | ✅ | ✅ תמונה | הצמדה בלבד |
| 6 היסטוריה | — | — | ✅ | ✅ | — | ✅ |
| 7 צבע | — | ✅ | ✅ | ✅ | ✅ | ✅ (מקור) |
| 8 גופנים | — | בורר בלבד | ✅ | ✅ | ✅ | ✅ (מקור) |
| 9 שכבות | — | — | ✅ | ✅ (מקור) | — | ✅ |
| 10 שמירה | — | ✅ | ✅ | ✅ | ✅ (מקור) | ✅ |

## 7. בעלות — לתיאום עם הצ'אט המתכלל

סעיף 4 ב-`master-spec.md` כבר נותן בעלים לחלק מזה:

| בסעיף 4 | בעלים היום | ההצעה |
| --- | --- | --- |
| מעטפת ממשק (סרגל, שכבות, מאפיינים, ייצוא) | רסטר | עוברת ל-`ui-core`. לרסטר יש את לוח השכבות וה-`History` הטובים ביותר — הם המקור, והוא מאשר כל שינוי בהם |
| גאומטריה ונתיבים | וקטורי | **נשארת אצלו.** ב-`ui-core` רק ידיות והצמדה (`snap.js` שלו — המקור), בתיאום |
| שכבות וצבע (הרכבה, מיזוג) | רסטר | **נשארת אצלו.** ב-`ui-core` רק המרות צבע לממשק והבורר |
| גופנים עבריים בדפדפן (`shapeLine`) | עימוד | **נשאר אצלו.** ב-`ui-core` רק קטלוג, טעינה ובורר — `shapeLine` מקבל מהם קובץ |

**את שינוי הבעלות מכריע הצ'אט המתכלל** — הבקשה: `creative-suite/docs/requests/shared-code-ui-core.md`.

## 7א. מה שהבעלים אמרו (לשלבים הבאים)

- **רסטר (2026-10-31), לשלבים 6 ו-9:** לפנות אליו לפני כל שינוי בלוח השכבות וב-`History`. שני מבנים חדשים שצריך לכבד:
  `layer.mask` (`{ tiles, rev, enabled, owner }` — ל-`History` יש לו רשומה משלו, `mask`, לצד `pixels` ו-`crop`), ו-`layer.adjust`
  (שכבות התאמה).
- **רסטר:** ה-service worker מונה את קובצי ui-core — לכן קובץ ב-`src` לא נמחק ולא משנה שם בלי הודעה (`ui-core/README.md`, "הכללים").
- **סטודיו העיצוב (2026-10-31), לשלבים 5, 7, 8:** בורר הצבע (`js/colorpicker.js`, #913) וגשר הגופנים (`js/fonts.js`, #904)
  עודכנו השבוע — לקחת מ-main. הידיות שלו השתנו (#931 — ידיות צד בצורת פס ושורת מצב בזמן גרירה; #932 — "גרירת פינה: רק
  את התיבה" לטקסט). מבקש תיאום לפני כל שינוי מראה בסטודיו.

## 8. ההכרעות

| תאריך | שאלה | הכרעת בעל המערכת |
| --- | --- | --- |
| 2026-10-31 | התוכנית והסדר | **מאושר בסדר שבסעיף 3** — עיצוב → חלונות, תפריטים, טוסט → קיצורים → זום → ידיות → ביטול → צבע → גופנים → שכבות → שמירה |
| 2026-10-31 | איזה חלון לכולן | **הגיליון של סטודיו העיצוב** — עולה מלמטה בטלפון, במרכז במחשב, נסגר בלחיצה מחוצה לו, מחזיר את הפוקוס. *ביצוע:* המראה וההתנהגות של הסטודיו, על אלמנט `<dialog>` של הדפדפן (שכבה עליונה, Esc ונגישות בחינם) — כך חמש האפליקציות מקבלות את המראה החדש בלי לשנות סימון |
| 2026-10-31 | Shift בשינוי גודל | **הפרופורציה נשמרת כברירת מחדל, Shift משחרר** (כמו Canva; בטלפון אין Shift) |
| 2026-10-31 | גופנים | **רשימה אחת וקבצים אחד לכולן** — קטלוג woff2 בתוך `ui-core`, עותק בכל אפליקציה, והעותקים הכפולים יוצאים |
| — | חיצי ביטול / ביצוע; זמן הטוסט | לא נשאל — לפי ההמלצה: ↶ ביטול, ↷ ביצוע (כמו במחשב); טוסט 3 שניות, שגיאה 6, עם כפתור פעולה. אפשר לשנות |

## יומן

- **2026-11-01** — קו הגמר: שלב 9 — `layers.js` (לוח השכבות של הרסטר, מוכלל במתאם), `migrate/layers.md`.
- **2026-11-01** — קו הגמר: שלב 8 — `fonts.js` + `src/fonts/` (קבצים אחד לכולן, סוג `fonts`), בורר גופנים, `migrate/fonts.md`.
- **2026-11-01** — קו הגמר: שלב 7 — `color.js` (בורר הצבעים של הסטודיו, מוכלל; ניגוד WCAG), `migrate/color.md`.
- **2026-11-01** — קו הגמר: שלב 6 — `history.js` (רשומות + צילומי מצב, תוויות, מיזוג, קבוצה), `migrate/history.md`.
- **2026-10-31** — נפתח. מיפוי של שש האפליקציות, תוכנית, ובקשה לבעל המערכת ולצ'אט המתכלל (#915).
- **2026-10-31** — הנחיות ה-CSS והחלונות (`migrate/css-dialogs.md`, #935) נשלחו לעימוד, לווקטורי, לרסטר ולעורך התמלילים. שלב 4 — `viewport` + `gestures`.
- **2026-10-31** — הטייס הצליח: ענן החבילה ב-ui-core (#924). תיקון המקלדת העברית מוזג ברסטר (#923), בסטודיו (#927) ובווקטורי (#928).
- **2026-10-31** — שלבים 1–3 מוזגו (#916, #918 ועוד). טייס: ענן החבילה (`migrate/suite-cloud.md`). תיקון המקלדת העברית: `migrate/keys.md` — לרסטר, לווקטורי ולסטודיו.
- **2026-10-31** — בעל המערכת אישר את התוכנית ואת ההכרעות (סעיף 8). שלב 1: `ui-core` — תשתית, `ui.css`, דף תצוגה.
