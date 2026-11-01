# מעבר לביטול / ביצוע של ui-core (שלב 6) — רסטר, וקטורי, עימוד, סטודיו העיצוב

**מאת:** הקוד המשותף · **תאריך:** 2026-11-01
**המודול:** `creative-suite/ui-core/src/js/history.js` — `History` (רשומות), `snapshots()` (צילומי מצב), `undoButtons()`.
**שורה ב-`integration.md`:** 12.

## מה יש

| | מה | למי |
| --- | --- | --- |
| `new History({ maxSteps, limitBytes, mergeMs, onChange })` | רשומות `{ label, bytes, undo, redo, discard }` — `push`, `undo`, `redo`, `clear`, `group(label, fn)`; תקרה לפי מספר ולפי זיכרון | רסטר (המקור) |
| `snapshots({ get, set, maxSteps, onChange })` | צילום JSON של כל המסמך — `reset`, `commit(label, { merge })`, `change(fn, label)`, `undo`, `redo` | וקטורי, עימוד, סטודיו |
| `undoButtons(h, undoBtn, redoBtn)` | `disabled` ו-`title` ("ביטול: הזזה") — לקרוא מ-`onChange` | כולם |

**חדש לעומת מה שיש היום אצל כולם:**
- **תווית לכל צעד** — "ביטול: הזזה" בכפתור ובטוסט.
- **מיזוג** — `commit('צבע', { merge: 'fill' })`: מחוון שזז או הקלדה רצופה — צעד ביטול אחד (עד `mergeMs`, ברירת מחדל שנייה).
- **קבוצה** — `h.group('יישור', () => …)`: כמה פעולות, צעד אחד.
- **`set` מקבל עותק חדש** — לא את האובייקט שבהיסטוריה (באג נפוץ: עריכה במקום ששינתה את העבר).

## רסטר — המקור (re-export)

`engine/history.js`: `class History extends Base` מ-`../ui-core/history.js` — הבנאי, `push`, `undo`, `redo`, `clear` והתקרה עוברים
לבסיס; **סוגי הרשומות שלך** (`props`, `pixels`, `addLayer`, `removeLayer`, `reparent`, `structure`, `selection`, `crop`, `mask`,
`moveLayer`) נשארים אצלך, מעל `this.push(...)`. אותה חתימה בדיוק: `{ maxSteps, limitBytes, onChange }`, `canUndo`, `canRedo`,
`bytes`. מה שמתווסף בחינם: `undoLabel` / `redoLabel`, `group()`, `{ merge }`.

```js
import { History as Base } from '../ui-core/history.js';
export class History extends Base {
    constructor({ renderer, ...o }) { super(o); this.renderer = renderer; }
    // props / pixels / … — בלי שינוי
}
```

## וקטורי

`S.undo` / `S.redo` + `change(fn)` / `commitFrom(before)` →
```js
import { snapshots, undoButtons } from './ui-core/history.js';
const hist = snapshots({ get: () => S.doc, set: d => { S.doc = d; afterChange(); tools.reload(); }, onChange: h => sync() });
const sync = undoButtons(hist, $('undo'), $('redo'));
// change(fn) → hist.change(fn, 'הזזה'); commitFrom(before) → hist.commit(label)
// טעינת מסמך → hist.reset()
```
העט באמצע נתיב (`tools.drawing`) — נשאר אצלך לפני `hist.undo()`.

## עימוד

`past` / `future` של מודלים → `snapshots({ get: () => model, set: m => show(m) })`. אם המודל לא משתנה במקום, אפשר גם
`serialize: m => m, parse: m => m` (בלי JSON) — אבל אז אסור לשנות מודל שכבר נרשם.

## סטודיו העיצוב

`history.js` שלך כמעט זהה ל-`snapshots`: `reset(doc)` → `reset()`, `commit(doc)` → `commit(label)`, `undo()` מחזיר מסמך → `set`
מקבל אותו. הרווח: תוויות, מיזוג (מחוון שקיפות / צבע), `undoButtons`.

## עורך התמלילים

לא רלוונטי — הביטול של TipTap.

## אחרי המעבר

- `php creative-suite/ui-core/tools/sync.php --check`
- לסמן את התא שלך בשורה 12 של `creative-suite/docs/integration.md` באותו PR.
- באג או חסר — הודעה אליי, לא תיקון בעותק.
