# מעבר לידיות של ui-core (שלב 5) — וקטורי, רסטר, סטודיו העיצוב, עימוד

**מאת:** הקוד המשותף · **תאריך:** 2026-10-31
**המודולים:** `creative-suite/ui-core/src/js/transform.js` (חשבון), `snap.js` (הצמדה), `handles.js` (הידיות על המסך) +
`ui.css` (`.uic-handles`, `.uic-status`). **ההכרעות והתשובות שלכם:** [`../stage5-handles.md`](../stage5-handles.md).
**תצוגה:** `creative-suite/ui-core/demo/` — "ידיות — גודל, סיבוב, הצמדה" (גם מצב חיתוך).

## הכללים (לכל החבילה)

| מה | איך |
| --- | --- |
| פינה | יחס נשמר; **Shift משחרר** (בעל המערכת). Alt — מהמרכז |
| צד | ציר אחד |
| סיבוב | ידית 28px מעל; נצמד ל-0/45/90… בטווח 6°; Shift — מדרגות 15°; שורת המצב: "סיבוב 90° ✓" |
| הצמדה | רק הקצה שזז, בצירים של הידית; Ctrl / ⌘ — בלי |
| מגע | 22px באצבע, 10px בעכבר; גודל קבוע במסך בכל זום |
| אצבע שנייה | `cancel()` — חזרה ל-t0 בלי עקבות |
| עריכת טקסט / נקודות | `hide()` מלא |

## החיבור (כל מי שלוקח `handles.js`)

```js
import { handles } from './ui-core/handles.js';
import { snapper } from './ui-core/snap.js';

const hd = handles(overlay, {                       // overlay — position: absolute; inset: 0 מעל הקנבס
    view: () => view,                               // { zoom, x, y }
    keepRatio: 'corners',                           // או (handle) => bool — טקסט נקודה 'always', טקסט תיבה 'never'
    snap: () => snapper({ boxes: otherBoxes(), tol: 6 / view.zoom }),
    onPreview: t => drawPreview(t),
    onCommit: (t, t0) => history.push(applyTransform(t, t0)),
    onCancel: t0 => drawPreview(t0),
});

bindViewport(stage, { get, set, pointer: {
    down: (e, p) => hd.down(e, screenPoint(e)) || tool.down(e, p),   // הידיות לפני ה-hitTest של האפליקציה
    move: (e, pts) => hd.dragging ? hd.move(e, screenPoint(e)) : tool.move(e, pts),
    up: e => hd.dragging ? hd.up(e) : tool.up(e),
    cancel: () => hd.dragging ? hd.cancel() : tool.cancel(),
} });

// בכל ציור / זום: hd.update(). בחירה השתנתה: hd.show(t, w, h). אין בחירה / עריכת טקסט: hd.hide().
```

`down` / `move` מקבלים נקודה **במסך** (פיקסלים יחסית ל-overlay), לא במסמך. `hd.at(point, e)` — לסמן העכבר.

## וקטורי — שני PR

1. **`snap`:** `assets/js/snap.js` → `snapper({ boxes, points, tol: 6 / view.z })`: התיבות — לוחות ואובייקטים גלויים
   שאינם בבחירה; `points` — עוגנים. `s.box([x, y, w, h])` בהזזה, `s.point(x, y, axes)` בשינוי גודל.
2. **`handles` + `transform`:** `handlePoints` / `handleAt` → `handles(...)`. הבחירה תמיד מלבן ישר: אחרי סיבוב —
   `hd.show({ cx, cy, sx: 1, sy: 1, angle: 0 }, bbox.w, bbox.h)`. קו שטוח (w או h אפס) — `dragHandle` כבר מטפל.
   `view` אצלך `{x, y, z}` → `view: () => ({ zoom: view.z, x: view.x, y: view.y })`.

## רסטר

1. `engine/transform.js` → `export * from '../ui-core/transform.js';` — אותם שמות; `dragHandle(t0, w, h, hd, p, true)`
   נשאר תקף (`true` = חופשי).
2. **הטרנספורמציה החופשית:** הידיות ב-`overlay.js` → `handles(overlay, { view, onPreview, onCommit })`.
3. **החיתוך:** `handles(overlay, { mode: 'rect', round: true, view, onPreview: r => …, onCommit: r => … })`,
   `hd.show({ x, y, w, h })`. חופשי, Shift נועל יחס, מתנרמל, יוצא מהקנבס. הזזה של המלבן וציור חדש — נשארים אצלך
   (`hd.down` מחזיר `false` → הכלי שלך).
4. ה-service worker: שלושה קבצים **חדשים** ב-`assets/js/ui-core/` — להוסיף לרשימת המטמון ולהעלות גרסה.

## סטודיו העיצוב — רק `snap.js`

`snap()` שלך → `snapper({ boxes, tol: 6 / zoom })`; הדף נשלח כ-box (קצה, מרכז, קצה). Konva ממשיך לצייר את הידיות —
לפי הכללים שבטבלה למעלה. כשעבר — לסמן את שורה 11 ב-`integration.md` (🟡: snap בלבד).

## עימוד — חדש לך (לא דחוף)

מסגרות זזות היום רק במספרים. `handles.js` נותן גרירה, שינוי גודל וסיבוב על הדף: `view` מהגלילה
(`{ zoom, x: -scrollLeft + offset, y: -scrollTop + offset }`). כשתרצה — הודעה, ונעבור יחד.

## אחרי המעבר

- `php creative-suite/ui-core/tools/sync.php --check`
- לסמן את התא שלך בשורה 11 של `creative-suite/docs/integration.md` באותו PR.
- באג או חסר — הודעה אליי, לא תיקון בעותק.
