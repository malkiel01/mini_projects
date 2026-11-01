// ביטול / ביצוע. כל פעולה = רשומה עם undo() ו-redo().
//
// פיקסלים: לפני שינוי מצלמים רק את האריחים שעומדים להשתנות (renderer.snapshot). ביטול וביצוע **מחליפים**
// מרקמים בין השכבה לרשומה — בלי להעתיק פיקסלים. כך ביטול של משיכת מכחול על תמונה ענקית הוא מיידי.
// זיכרון: לכל רשומה גודל בבתים; כשעוברים את התקרה — הישנות נזרקות (והמרקמים שלהן משתחררים).

import { TILE } from './doc.js';
import { History as Base } from '../ui-core/history.js';

export const TILE_BYTES = TILE * TILE * 8;   // אריח float16

/** משחרר את כל האריחים של שכבה, או של כל השכבות שבקבוצה */
function freeTree(r, layer) {
  if (layer.type === 'group') for (const c of layer.children) freeTree(r, c);
  else r.freeLayer(layer);
}
const tileCount = l => (l.type === 'group' ? l.children.reduce((n, c) => n + tileCount(c), 0) : l.tiles.size);

/**
 * ביטול / ביצוע של הרסטר: הבסיס (מחסניות, תקרה בבתים, קבוצות, מיזוג, undoLabel) — ui-core/history.js;
 * כאן — סוגי הרשומות של הרסטר (מאפיינים, פיקסלים, שכבות, מסכות, בחירה, חיתוך), מעל המנוע.
 */
export class History extends Base {
  /**
   * @param {object} o
   * @param {object} o.renderer   snapshot / freeTextures / freeLayer / invalidate / invalidateTiles
   * @param {number} o.limitBytes תקרת הזיכרון של ההיסטוריה
   * @param {number} [o.maxSteps]
   * @param {(h: History) => void} [o.onChange]
   */
  constructor({ renderer, ...rest }) {
    super(rest);
    this.renderer = renderer;
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
  pixels(doc, layer, before, label, sel = null) {
    const r = this.renderer;
    const stored = before;
    // sel = { before, after } — בחירה שזזה יחד עם הפיקסלים (הזזה / שינוי צורה): אותו צעד ביטול
    let selNow = sel?.after;
    const swap = () => {
      if (sel) {
        selNow = selNow === sel.after ? sel.before : sel.after;
        doc.selection = selNow;
        r.selectionChanged(doc);
      }
      for (const [k, t] of stored) {
        const cur = layer.tiles.get(k) ?? null;
        if (t) layer.tiles.set(k, t); else layer.tiles.delete(k);
        stored.set(k, cur);
      }
      layer.rev = (layer.rev ?? 0) + 1;
      if (layer.owner) layer.owner.rev = (layer.owner.rev ?? 0) + 1;   // מסכה: גם השכבה שלה "השתנתה" (ייצוא, טיוטה)
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

  /** שכבה או קבוצה חדשה (גם שכפול, תמונה שנוספה, טקסט). הפריט כבר בעץ. */
  addLayer(doc, layer, label) {
    const r = this.renderer;
    const list = doc.listOf(layer);
    let index = list.indexOf(layer);
    this.push({
      label, bytes: 0,
      undo: () => { index = list.indexOf(layer); list.splice(index, 1);
        if (doc.activeId === layer.id) doc.activeId = (list[Math.min(index, list.length - 1)] ?? doc.layers.at(-1))?.id ?? null;
        r.invalidate(doc); },
      redo: () => { list.splice(index, 0, layer); doc.activeId = layer.id; r.invalidate(doc); },
      // נזרקת כשהיא מבוטלת (הפריט מחוץ למסמך) — האריחים משתחררים
      discard: applied => { if (!applied) freeTree(r, layer); },
    });
  }

  /** מחיקת שכבה / קבוצה. הפריט כבר הוצא; list ו-index — איפה היה. האריחים נשמרים עד שהרשומה נזרקת. */
  removeLayer(doc, layer, index, label, list = doc.layers) {
    const r = this.renderer;
    this.push({
      label, bytes: tileCount(layer) * TILE_BYTES,
      undo: () => { list.splice(index, 0, layer); doc.activeId = layer.id; r.invalidate(doc); },
      redo: () => { doc.removeLayer(layer); r.invalidate(doc); },
      discard: applied => { if (applied) freeTree(r, layer); },
    });
  }

  /** פריט עבר בין מערכים (לתוך קבוצה / החוצה). */
  reparent(doc, layer, from, fromIdx, to, toIdx, label) {
    const r = this.renderer;
    const go = (list, i) => { const cur = doc.listOf(layer); cur.splice(cur.indexOf(layer), 1); list.splice(i, 0, layer); doc.activeId = layer.id; r.invalidate(doc); };
    this.push({ label, bytes: 0, undo: () => go(from, fromIdx), redo: () => go(to, toIdx) });
  }

  /**
   * מבנה העץ השתנה בבת אחת (קיבוץ / פירוק קבוצה): snapshot של מערכים — [מערך, תוכן לפני, תוכן אחרי].
   * גם הפריטים עצמם לא משתנים — רק איפה הם יושבים.
   */
  structure(doc, lists, label) {
    const r = this.renderer;
    const set = which => { for (const [arr, before, after] of lists) arr.splice(0, arr.length, ...(which ? after : before)); r.invalidate(doc); };
    const active = [doc.activeId];
    this.push({ label, bytes: 0, undo: () => { set(false); }, redo: () => { set(true); doc.activeId = active[0]; } });
  }

  /** הבחירה השתנתה (before / after — אובייקטי בחירה או null). */
  selection(doc, before, after, label) {
    const r = this.renderer;
    const set = v => { doc.selection = v; r.selectionChanged(doc); };
    this.push({ label, bytes: 0, undo: () => set(before), redo: () => set(after) });
  }

  /**
   * חיתוך / שינוי גודל הקנבס. state = { w, h, maps: Map(layer → tiles), sel, vec? } — לפני ואחרי.
   * ביטול וביצוע מחליפים את מפות האריחים של כל השכבות ואת הגודל.
   */
  crop(doc, before, after, label) {
    const r = this.renderer;
    const set = st => {
      doc.width = st.w; doc.height = st.h;
      for (const [layer, tiles] of st.maps) { layer.tiles = tiles; layer.rev = (layer.rev ?? 0) + 1; }
      // מסכות וקטוריות זזות יחד (vec: Map(layer → vectorMask), רשות)
      if (st.vec) for (const [layer, vm] of st.vec) layer.vectorMask = vm;
      doc.selection = st.sel;
      r.resetComposite(doc);
    };
    const count = st => [...st.maps.values()].reduce((n, m) => n + m.size, 0);
    this.push({
      label, bytes: count(before) * TILE_BYTES,
      undo: () => set(before), redo: () => set(after),
      // נזרקת: המצב שאינו פעיל משתחרר
      discard: applied => { for (const m of (applied ? before : after).maps.values()) r.freeTextures(m.values()); },
    });
  }

  /**
   * מסכת שכבה נוספה / הוסרה / הוחלה: layer.mask עובר מ-before ל-after (אובייקט מסכה או null).
   * pixels — before מ-renderer.applyMask (החלה: הפיקסלים השתנו באותו צעד), מוחלף כמו ב-pixels.
   */
  mask(doc, layer, before, after, label, pixels = null) {
    const r = this.renderer;
    const set = (m, swap) => {
      layer.mask = m;
      if (!m) layer.editMask = false;
      if (swap) {
        for (const [k, t] of pixels) {
          const cur = layer.tiles.get(k) ?? null;
          if (t) layer.tiles.set(k, t); else layer.tiles.delete(k);
          pixels.set(k, cur);
        }
      }
      layer.rev = (layer.rev ?? 0) + 1;
      r.invalidate(doc);
    };
    const tiles = m => (m ? [...m.tiles.values()] : []);
    this.push({
      label, bytes: Math.max(1, tiles(before).length + tiles(after).length + (pixels?.size ?? 0)) * TILE_BYTES,
      undo: () => set(before, !!pixels), redo: () => set(after, !!pixels),
      // נזרקת: המסכה שאינה בשכבה משתחררת, ואריחי הפיקסלים שברשומה (אף פעם לא בשכבה)
      discard: applied => {
        const out = applied ? before : after;
        if (out && out !== (applied ? after : before)) r.freeTextures(out.tiles.values());
        if (pixels) r.freeTextures(pixels.values());
      },
    });
  }

  /** שכבה עברה מ-from ל-to. */
  moveLayer(doc, layer, from, to, label) {
    const r = this.renderer;
    const go = i => { doc.moveLayer(layer, i); doc.activeId = layer.id; r.invalidate(doc); };
    this.push({ label, bytes: 0, undo: () => go(from), redo: () => go(to) });
  }
}
