# מעבר ללוח השכבות של ui-core (שלב 9) — רסטר, וקטורי, סטודיו העיצוב

**מאת:** הקוד המשותף · **תאריך:** 2026-11-01
**המודול:** `creative-suite/ui-core/src/js/layers.js` + `ui.css` (`.layer-*` — שמות הרסטר). **המקור:** `layers-panel.js` של הרסטר.
**תצוגה:** `creative-suite/ui-core/demo/` — "לוח שכבות". **שורה ב-`integration.md`:** 15.

## מה יש

`layersPanel(ul, adapter)` — הרשימה (העליונה למעלה), קבוצות מקופלות עם הזחה, תמונה ממוזערת, 👁️ / 🔒, שינוי שם במקום (לחיצה
שנייה על השם, F2), גרירה ב-⋮⋮ (לפני / אחרי, ולשליש האמצעי של קבוצה — לתוכה; קבוצה לא לתוך עצמה), מקלדת (↑ ↓ F2 Delete
רווח), תפריט בלחיצה ימנית / ארוכה. `moveNode(root, id, parentId, index)` — עזר למודל של מערכים.

**מה נשאר באפליקציה (דרך המתאם):** המודל, ההיסטוריה (`history.js`), אטימות / מיזוג / מסכות / סגנונות (הלוח לא יודע עליהם —
אפשר `badges` לשורה, וסרגל מאפיינים משלך מעל הרשימה), ומה ש-`thumb(node, canvas)` מצייר.

```js
const panel = layersPanel($('.layer-list'), {
    items: () => doc.layers, active: () => doc.activeId,
    select: (id, e) => { doc.activeId = id; panel.render(); },
    toggle: (id, key) => setProp(doc.layer(id), key, !doc.layer(id)[key]),
    rename: (id, name) => setProp(doc.layer(id), 'name', name),
    move: (id, parentId, i) => { moveLayer(id, parentId, i); panel.render(); },
    remove: id => act.remove(id),
    menu: id => [{ label: 'שכפול', run: () => act.duplicate(id) }, …],
    thumb: (node, canvas) => drawThumb(node, canvas),
});
```

## רסטר — המקור

`layers-panel.js`: `row()`, `rename()`, `drag()` ותחילת `render()` → `layersPanel(list, …)`. נשארים אצלך: `.layer-props`
(אטימות, מיזוג), המסכות (`mask-thumb`, `vmask-thumb` — דרך `badges`), `fillCss`, הפעולות, וההיסטוריה (`history.reparent` /
`moveLayer` בתוך `move`). מ-CSS — הבלוק `.layer-list` … `.lock.off` (עבר ל-`ui.css`, אותם שמות, תחת `.layer-list` — לא נוגע בשורות של מי שעוד לא עבר).

## וקטורי

הרשימה של השכבות / האובייקטים → `layersPanel`; `move` → שינוי הסדר במודל + `hist.commit('סדר השכבות')`.

## סטודיו העיצוב

לוח השכבות שלך (Konva) → `layersPanel` עם `items: () => [...doc.layers]` (בלי קבוצות — רשימה שטוחה עובדת), `thumb` — אייקון
לפי סוג (`icon`), `move` → `moveNode` + `commit`.

## עימוד, עורך התמלילים

לא רלוונטי עכשיו (אין שכבות).

## אחרי המעבר

- `php creative-suite/ui-core/tools/sync.php --check`
- לסמן את התא שלך בשורה 15 של `creative-suite/docs/integration.md` באותו PR.
