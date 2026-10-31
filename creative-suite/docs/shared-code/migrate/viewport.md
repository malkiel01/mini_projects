# מעבר לזום ולהזזה של ui-core (שלב 4) — רסטר, וקטורי

**מאת:** הקוד המשותף · **תאריך:** 2026-10-31
**המודולים:** `creative-suite/ui-core/src/js/viewport.js` (חשבון טהור) ו-`gestures.js` (החיבור לקנבס).
**תצוגה:** `creative-suite/ui-core/demo/` — "קנבס — זום, הזזה, צביטה".

## מי — ולמה רק שתיים

| אפליקציה | היום | שלב 4 |
| --- | --- | --- |
| רסטר | `engine/view.js` (טהור) + מצביעים ב-`editor.js` | **המקור.** מעבר — כמעט רק מחיקה |
| וקטורי | ~60 שורות בתוך `editor.js`, תצוגה `{x, y, z}` | מעבר מלא |
| סטודיו העיצוב | Konva — זום והזזה של Konva | לא עכשיו: הזום שלו חלק מ-Konva. אפשר לקחת רק את `wheel()` ואת `zoomLabel()` |
| עימוד | גלילה של הדפדפן (`.scroller` + `.spacer`) | לא: מודל אחר (גלילה ולא טרנספורמציה). אפשר לקחת `nextStep` / `zoomLabel` לאחידות |
| עורך התמלילים | `transform: scale` על עמודים | לא |

## רסטר

1. `assets/js/engine/view.js` → שורה אחת: `export * from '../ui-core/viewport.js';` — אותם שמות בדיוק (`fit`, `zoomTo`,
   `zoomBy`, `nextStep`, `screenToDoc`, `docToScreen`, `pinch`, `zoomLabel`, `STEPS`, `ZOOM_MIN/MAX`, `clampZoom`).
   כל מי שמייבא מ-`engine/view.js` — לא משתנה.
2. `editor.js` — מאזיני `wheel`, `pointerdown/move/up/cancel`, `startGesture`, רווח לחוץ, `dblclick`, `gesturestart`
   → `bindViewport(stage, {…})`:
   ```js
   import { bindViewport } from './ui-core/gestures.js';
   bindViewport(stage, {
       get: () => view, set: setView,
       ignore: '.tools, .tool-opts, .adjust, .text-edit',
       hand: () => tools?.state.tool === 'hand',
       pointer: {
           down: (e, pt) => { if (adjust?.open) adjust.close(); return tools.down(e, pt) && tools.stroking; },
           move: (e, list) => tools.move(list, e),
           up: () => tools.up(),
           cancel: () => tools.cancel(),
       },
       onDoubleClick: (e, p) => { if (tools?.state.tool === 'hand') toggleFit(p); },
   });
   ```
   - **דלי / טפטפת** (לחיצה אחת, בלי משיכה): `down` מריץ אותם ומחזיר `false` — כמו היום, המצביע לא נשמר.
   - `tools.updateCursor` / `hideCursor` — נשארים אצלך (`pointermove` / `pointerleave` נפרדים); הם לא תצוגה.
   - המחלקה `panning` על הבמה — כמו היום. רווח לחוץ מוסיף `hand` (היום: `data-tool="hand"`) — ב-CSS: `.stage.hand`.
3. `service-worker.js` — להוסיף `viewport.js` ו-`gestures.js` לרשימה, ולהעלות את `CACHE_VERSION`.

## וקטורי

התצוגה שלך `{x, y, z}`; של ui-core `{zoom, x, y}`. הכי פשוט — לעבור לשם `zoom` (חיפוש `S.view.z` / `view.z`). אם לא:
`get: () => ({ zoom: S.view.z, x: S.view.x, y: S.view.y })`, `set: v => { S.view = { x: v.x, y: v.y, z: v.zoom }; draw(); }`.

- `fit`, `zoomAt`, `zoomStep` → `fit`, `zoomTo`, `zoomTo(view, nextStep(view.zoom, dir), …)`. **שים לב:** `fit` של
  ui-core מגביל ל-100%; היום אצלך עד ×8 — `fit(...)` ואז `zoomTo` אם רוצים אחרת.
- `toWorld` → `screenToDoc`.
- מאזיני הקנבס: **צביטה והזזה** (היום בתוך מכונת המצבים `op`) → `bindViewport`. הכלים שלך (בחירה, עט, צורות, ידיות) נכנסים
  דרך `pointer.down/move/up/cancel`; `down` מחזיר `true` כשכלי לקח את המצביע.
- **אצבע אחת על רקע ריק מזיזה, הקשה מבטלת בחירה** (כמו היום) — `panOnEmpty` ו-`onTap` (נוספו לבקשתך):
  ```js
  bindViewport(canvas, { get, set, pointer: { down: (e, pt) => /* false על רקע ריק */ },
      panOnEmpty: e => e.pointerType === 'touch',     // מגע — הזזה; עכבר — נשאר למלבן הבחירה של הכלי
      onTap: () => select([]) });                    // הקשה בלי תזוזה שהכלי לא לקח
  ```
- גלגלת — אותו דבר כמו היום, ועכשיו גם `deltaMode` (עכבר עם "שורות") ו-Shift לרוחב.

## בדיקה

- `php creative-suite/ui-core/tools/sync.php --check`
- בטלפון: אצבע אחת מציירת / בוחרת; שתיים — צביטה; אצבע שנייה באמצע קו — הקו נעלם; חזרה לאצבע אחת — בלי קפיצה.
- במחשב: גלגלת, Ctrl+גלגלת, רווח+גרירה, גלגלת לחוצה.
- `conclusion: success`.
