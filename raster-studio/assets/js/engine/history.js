// ביטול / ביצוע. כל פעולה = רשומה עם undo() ו-redo().
//
// פיקסלים: לפני שינוי מצלמים רק את האריחים שעומדים להשתנות (renderer.snapshot). ביטול וביצוע **מחליפים**
// מרקמים בין השכבה לרשומה — בלי להעתיק פיקסלים. כך ביטול של משיכת מכחול על תמונה ענקית הוא מיידי.
// זיכרון: לכל רשומה גודל בבתים; כשעוברים את התקרה — הישנות נזרקות (והמרקמים שלהן משתחררים).

import { TILE } from './doc.js';

export const TILE_BYTES = TILE * TILE * 8;   // אריח float16

export class History {
  /**
   * @param {object} o
   * @param {object} o.renderer   snapshot / freeTextures / freeLayer / invalidate / invalidateTiles
   * @param {number} o.limitBytes תקרת הזיכרון של ההיסטוריה
   * @param {number} [o.maxSteps]
   * @param {(h: History) => void} [o.onChange]
   */
  constructor({ renderer, limitBytes, maxSteps = 100, onChange = () => {} }) {
    this.renderer = renderer;
    this.limitBytes = limitBytes;
    this.maxSteps = maxSteps;
    this.onChange = onChange;
    this.undoStack = [];
    this.redoStack = [];
  }

  get canUndo() { return this.undoStack.length > 0; }
  get canRedo() { return this.redoStack.length > 0; }
  get bytes() { return [...this.undoStack, ...this.redoStack].reduce((n, e) => n + (e.bytes || 0), 0); }

  push(entry) {
    for (const e of this.redoStack) e.discard?.(false);
    this.redoStack = [];
    this.undoStack.push(entry);
    // הישנות ביותר נזרקות — לפי מספר ולפי זיכרון (את האחרונה תמיד שומרים)
    while (this.undoStack.length > 1 && (this.undoStack.length > this.maxSteps || this.bytes > this.limitBytes)) {
      this.undoStack.shift().discard?.(true);
    }
    this.onChange(this);
  }

  undo() {
    const e = this.undoStack.pop();
    if (!e) return null;
    e.undo();
    this.redoStack.push(e);
    this.onChange(this);
    return e;
  }

  redo() {
    const e = this.redoStack.pop();
    if (!e) return null;
    e.redo();
    this.undoStack.push(e);
    this.onChange(this);
    return e;
  }

  /** מסמך חדש — ההיסטוריה של הקודם נזרקת. */
  clear() {
    for (const e of this.undoStack) e.discard?.(true);
    for (const e of this.redoStack) e.discard?.(false);
    this.undoStack = [];
    this.redoStack = [];
    this.onChange(this);
  }

  // ---------- סוגי רשומות ----------

  /** שינוי מאפיינים של שכבה (שם, אטימות, מיזוג, הסתרה, נעילה). before / after — רק השדות שהשתנו. */
  props(doc, layer, before, after, label) {
    const r = this.renderer;
    const apply = v => { Object.assign(layer, v); r.invalidate(doc); };
    this.push({ label, bytes: 0, undo: () => apply(before), redo: () => apply(after) });
  }

  /**
   * שינוי פיקסלים בשכבה. before — מה ש-renderer.snapshot החזיר *לפני* השינוי.
   * ביטול וביצוע — אותה פעולה: החלפה של מרקמי האריחים בין השכבה לרשומה.
   */
  pixels(doc, layer, before, label) {
    const r = this.renderer;
    const stored = before;
    const swap = () => {
      for (const [k, t] of stored) {
        const cur = layer.tiles.get(k) ?? null;
        if (t) layer.tiles.set(k, t); else layer.tiles.delete(k);
        stored.set(k, cur);
      }
      layer.rev = (layer.rev ?? 0) + 1;
      r.invalidateTiles(doc, stored.keys());
    };
    const count = [...stored.values()].filter(Boolean).length;
    this.push({
      label, bytes: Math.max(1, count) * TILE_BYTES,
      undo: swap, redo: swap,
      // המרקמים שברשומה אף פעם אינם בשכבה — משחררים תמיד
      discard: () => r.freeTextures(stored.values()),
    });
  }

  /** שכבה חדשה (גם שכפול, וגם תמונה שנוספה כשכבה). השכבה כבר במסמך במקום index. */
  addLayer(doc, layer, label) {
    const r = this.renderer;
    let index = doc.indexOf(layer);
    this.push({
      label, bytes: 0,
      undo: () => { index = doc.indexOf(layer); doc.layers.splice(index, 1);
        if (doc.activeId === layer.id) doc.activeId = doc.layers[Math.min(index, doc.layers.length - 1)]?.id ?? null;
        r.invalidate(doc); },
      redo: () => { doc.layers.splice(index, 0, layer); doc.activeId = layer.id; r.invalidate(doc); },
      // נזרקת כשהיא מבוטלת (השכבה מחוץ למסמך) — האריחים משתחררים
      discard: applied => { if (!applied) r.freeLayer(layer); },
    });
  }

  /** מחיקת שכבה. השכבה כבר הוצאה מהמסמך; index — המקום שבו הייתה. האריחים נשמרים עד שהרשומה נזרקת. */
  removeLayer(doc, layer, index, label) {
    const r = this.renderer;
    this.push({
      label, bytes: layer.tiles.size * TILE_BYTES,
      undo: () => { doc.layers.splice(index, 0, layer); doc.activeId = layer.id; r.invalidate(doc); },
      redo: () => { doc.removeLayer(layer); r.invalidate(doc); },
      discard: applied => { if (applied) r.freeLayer(layer); },
    });
  }

  /** שכבה עברה מ-from ל-to. */
  moveLayer(doc, layer, from, to, label) {
    const r = this.renderer;
    const go = i => { doc.moveLayer(layer, i); doc.activeId = layer.id; r.invalidate(doc); };
    this.push({ label, bytes: 0, undo: () => go(from), redo: () => go(to) });
  }
}
