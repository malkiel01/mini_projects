// הכלים: ✋ יד · 🖌️ מכחול · 🧽 מחק · 🪣 דלי · 💧 טפטפת, הצבע, ולוח האפשרויות של הכלי הפעיל.
// הציור עצמו: משיכה במנוע (beginStroke → dab → endStroke), ובסופה רשומה אחת בהיסטוריה.

import { dabsAlong, floodFill, hexToRgb, rgbToHex } from './engine/fill.js';
import { combine, isEmptyShape } from './engine/selection.js';

export const TOOLS = {
  hand: { label: 'יד', icon: '✋', key: 'h', hint: 'גרירה — הזזה' },
  rect: { label: 'בחירה מלבנית', icon: '⬚', key: 'm', hint: 'Shift — הוספה · Alt — חיסור · Ctrl+D — ביטול הבחירה' },
  ellipse: { label: 'בחירה אליפטית', icon: '◯', key: null, hint: 'Shift — הוספה · Alt — חיסור · M — מעבר בין מלבן לאליפסה' },
  lasso: { label: 'לאסו', icon: '➰', key: 'l', hint: 'גרירה סביב האזור · Shift — הוספה · Alt — חיסור' },
  brush: { label: 'מכחול', icon: '🖌️', key: 'b', hint: 'Alt+לחיצה — דגימת צבע · [ ] — גודל' },
  eraser: { label: 'מחק', icon: '🧽', key: 'e', hint: '[ ] — גודל' },
  bucket: { label: 'דלי', icon: '🪣', key: 'g', hint: 'לחיצה — מילוי אזור בשכבה הפעילה' },
  picker: { label: 'טפטפת', icon: '💧', key: 'i', hint: 'לחיצה — הצבע מהתמונה' },
};

const SIZE_MAX = 500;
// מחוון הגודל לוגריתמי: 0–100 → 1–500 פיקסלים (דיוק בגדלים הקטנים, ועדיין מגיע לגדולים)
export const sliderToSize = v => Math.max(1, Math.round(SIZE_MAX ** (v / 100)));
export const sizeToSlider = s => Math.round(Math.log(Math.max(1, s)) / Math.log(SIZE_MAX) * 100);

const $ = (s, el = document) => el.querySelector(s);

/**
 * @param {object} o
 * @param {() => object} o.doc
 * @param {object} o.renderer
 * @param {object} o.history
 * @param {() => {zoom: number}} o.view
 * @param {(what: string) => void} o.onChange  אחרי שינוי בפיקסלים (לצייר, לרענן תמונות ממוזערות)
 * @param {() => void} o.draw
 * @param {(m: string, err?: boolean) => void} o.toast
 */
export function createTools({ doc, renderer, history, view, onChange, draw, toast, overlay }) {
  const state = { tool: 'brush', color: '#1b1e26', size: 12, hardness: 0.8, opacity: 1, tolerance: 32, selMode: 'new' };
  let stroke = null;   // { layer, last, carry, pen }
  let lasso = null;    // גרירת בחירה: { type, mode, start, pts, shape }

  const strip = $('#tools');
  const opts = $('#tool-opts');
  const colorIn = $('#color');
  const cursor = $('#brush-cursor');

  strip.replaceChildren(...Object.entries(TOOLS).map(([id, t]) => {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'icon-btn tool';
    b.dataset.tool = id;
    b.textContent = t.icon;
    b.setAttribute('aria-label', t.key ? `${t.label} (${t.key.toUpperCase()})` : t.label);
    b.title = b.getAttribute('aria-label');
    b.addEventListener('click', () => setTool(id));
    return b;
  }), $('#color-wrap'));

  // ---- אפשרויות ----
  const field = id => $('#opt-' + id);
  function bindRange(id, toState, fromState, fmt) {
    const el = field(id), out = $(`output[for="opt-${id}"]`);
    const sync = () => { out.textContent = fmt(state); };
    el.addEventListener('input', () => { toState(Number(el.value)); sync(); updateCursor(); });
    return () => { el.value = fromState(state); sync(); };
  }
  const syncers = [
    bindRange('size', v => { state.size = sliderToSize(v); }, s => sizeToSlider(s.size), s => s.size + 'px'),
    bindRange('hardness', v => { state.hardness = v / 100; }, s => Math.round(s.hardness * 100), s => Math.round(s.hardness * 100) + '%'),
    bindRange('opacity', v => { state.opacity = v / 100; }, s => Math.round(s.opacity * 100), s => Math.round(s.opacity * 100) + '%'),
    bindRange('tolerance', v => { state.tolerance = v; }, s => s.tolerance, s => String(s.tolerance)),
  ];
  colorIn.addEventListener('input', () => { state.color = colorIn.value; syncColor(); });
  for (const r of opts.querySelectorAll('input[name="selmode"]')) {
    r.addEventListener('change', () => { if (r.checked) state.selMode = r.value; });
  }
  opts.addEventListener('click', e => {
    const b = e.target.closest('[data-sel]');
    if (b) selAction(b.dataset.sel);
  });
  function syncColor() {
    colorIn.value = state.color;
    $('#color-wrap').style.setProperty('--swatch', state.color);
  }

  function setTool(id) {
    if (!TOOLS[id]) return;
    cancel();
    state.tool = id;
    for (const b of strip.querySelectorAll('.tool')) b.setAttribute('aria-pressed', String(b.dataset.tool === id));
    for (const f of opts.querySelectorAll('[data-for]')) f.hidden = !f.dataset.for.split(' ').includes(id);
    opts.hidden = ![...opts.querySelectorAll('[data-for]')].some(f => !f.hidden);
    $('#stage').dataset.tool = id;
    const hint = $('#hint');
    if (hint && !matchMedia('(pointer: coarse)').matches) hint.textContent = TOOLS[id].hint;
    updateCursor();
  }

  function sync() {
    for (const s of syncers) s();
    syncColor();
  }

  // ---- סמן המכחול: עיגול בגודל האמיתי, עוקב אחרי העכבר / העט ----
  let lastHover = null;
  function updateCursor(e) {
    if (e) lastHover = e;
    const show = lastHover && (state.tool === 'brush' || state.tool === 'eraser') && lastHover.pointerType !== 'touch';
    cursor.hidden = !show;
    if (!show) return;
    const d = Math.max(4, state.size * view().zoom);
    const r = $('#stage').getBoundingClientRect();
    cursor.style.width = cursor.style.height = d + 'px';
    cursor.style.transform = `translate(${lastHover.clientX - r.left - d / 2}px, ${lastHover.clientY - r.top - d / 2}px)`;
  }
  function hideCursor() { lastHover = null; cursor.hidden = true; }

  // ---- בחירה ----
  const selecting = () => state.tool === 'rect' || state.tool === 'ellipse' || state.tool === 'lasso';

  function setSelection(next, label) {
    const d = doc();
    const before = d.selection ?? null;
    if (!before && !next) return;
    d.selection = next;
    renderer.selectionChanged(d);
    history.selection(d, before, next, label);
    onChange('selection');
  }

  /** פעולות על הבחירה — מהכפתורים ומהמקלדת */
  function selAction(a) {
    const d = doc();
    if (a === 'all') setSelection({ shapes: [{ op: 'add', type: 'rect', x: 0, y: 0, w: d.width, h: d.height }], inverted: false }, 'בחירת הכול');
    else if (a === 'none') setSelection(null, 'ביטול הבחירה');
    else if (a === 'invert') {
      if (!d.selection) { toast('אין בחירה להפוך'); return; }
      setSelection({ ...d.selection, inverted: !d.selection.inverted }, 'היפוך הבחירה');
    } else if (a === 'delete' || a === 'fill') {
      const layer = d.active;
      if (!d.selection) { toast('קודם בוחרים אזור'); return; }
      if (!canEdit(layer)) return;
      const before = renderer.fillSelection(d, layer, a === 'delete' ? 'erase' : 'paint', hexToRgb(state.color), 1);
      if (before.size) history.pixels(d, layer, before, a === 'delete' ? 'מחיקת הבחירה' : 'מילוי הבחירה');
      onChange(a);
    }
  }

  function snapRect(a, b) {
    const x0 = Math.round(Math.min(a[0], b[0])), y0 = Math.round(Math.min(a[1], b[1]));
    const x1 = Math.round(Math.max(a[0], b[0])), y1 = Math.round(Math.max(a[1], b[1]));
    return { x: x0, y: y0, w: x1 - x0, h: y1 - y0 };
  }

  function lassoShape() {
    if (lasso.type === 'lasso') return { type: 'poly', pts: lasso.pts };
    return { type: lasso.type, ...snapRect(lasso.start, lasso.pts.at(-1)) };
  }

  // ---- ציור ----
  const painting = () => state.tool === 'brush' || state.tool === 'eraser';

  /** אפשר לצייר על השכבה? אחרת — הודעה, ולא מציירים. */
  function canEdit(layer) {
    if (!layer) return false;
    if (layer.locked) { toast('השכבה נעולה — 🔓 בלוח השכבות', true); return false; }
    if (!layer.visible) { toast('השכבה מוסתרת — 👁️ בלוח השכבות', true); return false; }
    return true;
  }

  function pick([x, y]) {
    const d = doc();
    const xi = Math.floor(x), yi = Math.floor(y);
    if (xi < 0 || yi < 0 || xi >= d.width || yi >= d.height) return;
    const p = renderer.readPixels(d, xi, yi, 1, 1);
    if (!p[3]) { toast('כאן שקוף — אין צבע לדגום'); return; }
    state.color = rgbToHex([p[0], p[1], p[2]]);
    syncColor();
  }

  function bucket([x, y]) {
    const d = doc(), layer = d.active;
    if (!canEdit(layer)) return;
    const xi = Math.floor(x), yi = Math.floor(y);
    if (xi < 0 || yi < 0 || xi >= d.width || yi >= d.height) return;
    const px = renderer.readLayerPixels(layer, 0, 0, d.width, d.height);
    const r = floodFill(px, d.width, d.height, xi, yi, state.tolerance);
    if (!r.count) return;
    renderer.beginStroke(d, layer, { mode: 'paint', color: hexToRgb(state.color), opacity: state.opacity });
    renderer.maskFromAlpha(d, r.mask, 0, 0, d.width, d.height, r.box);
    const before = renderer.endStroke(d);
    if (before.size) history.pixels(d, layer, before, 'דלי');
    onChange('bucket');
  }

  /** e — אירוע ה-pointerdown; pt — הנקודה במסמך. מחזיר true אם הכלי לקח את המצביע. */
  function down(e, pt) {
    if (state.tool === 'hand') return false;
    if (selecting()) {
      const mode = e.shiftKey ? 'add' : e.altKey ? 'sub' : state.selMode;
      lasso = { type: state.tool, mode, start: pt, pts: [pt] };
      overlay.preview(lassoShape());
      return true;
    }
    if (state.tool === 'picker' || (e.altKey && painting())) { pick(pt); return true; }
    if (state.tool === 'bucket') { bucket(pt); return true; }
    const d = doc(), layer = d.active;
    if (!canEdit(layer)) return true;
    const pen = e.pointerType === 'pen';
    renderer.beginStroke(d, layer, {
      mode: state.tool === 'eraser' ? 'erase' : 'paint', color: hexToRgb(state.color), opacity: state.opacity,
    });
    const p = { x: pt[0], y: pt[1], p: pen ? e.pressure || 0.5 : 1 };
    stroke = { layer, last: p, carry: 0, pen };
    dabAt(p);
    draw();
    return true;
  }

  function dabAt(p) {
    renderer.dab(doc(), p.x, p.y, state.size / 2 * Math.max(0.1, p.p), state.hardness);
  }

  /** points — נקודות במסמך עם לחץ: [{x, y, pressure}] (כולל האירועים המאוחדים) */
  function move(points) {
    if (lasso) {
      for (const q of points) {
        const p = [q.x, q.y];
        const last = lasso.pts.at(-1);
        if (lasso.type !== 'lasso') lasso.pts = [lasso.start, p];
        else if (Math.hypot(p[0] - last[0], p[1] - last[1]) * view().zoom >= 2) lasso.pts.push(p);
      }
      overlay.preview(lassoShape());
      return;
    }
    if (!stroke) return;
    const spacing = Math.max(0.5, state.size * 0.1);
    for (const q of points) {
      const p = { x: q.x, y: q.y, p: stroke.pen ? q.pressure || 0.5 : 1 };
      const r = dabsAlong(stroke.last, p, spacing, stroke.carry);
      for (const dab of r.dabs) dabAt(dab);
      stroke.carry = r.carry;
      stroke.last = p;
    }
    draw();
  }

  function up() {
    if (lasso) {
      const shape = lassoShape(), mode = lasso.mode;
      lasso = null;
      overlay.preview(null);
      const d = doc();
      // לחיצה בלי גרירה — ביטול הבחירה (כמו בכל עורך); בהוספה / חיסור — כלום
      if (isEmptyShape(shape)) { if (mode === 'new') setSelection(null, 'ביטול הבחירה'); return; }
      setSelection(combine(d.selection, shape, mode), mode === 'sub' ? 'חיסור מהבחירה' : mode === 'add' ? 'הוספה לבחירה' : 'בחירה');
      return;
    }
    if (!stroke) return;
    const d = doc(), s = stroke;
    stroke = null;
    const before = renderer.endStroke(d);
    if (before.size) history.pixels(d, s.layer, before, state.tool === 'eraser' ? 'מחק' : 'מכחול');
    onChange('stroke');
  }

  /** משיכה פתוחה בוטלה (אצבע שנייה → צביטה). */
  function cancel() {
    if (lasso) { lasso = null; overlay.preview(null); return; }
    if (!stroke) return;
    stroke = null;
    renderer.cancelStroke(doc());
    draw();
  }

  /** קיצורי מקלדת של הכלים. מחזיר true אם טופל. */
  function key(e) {
    const k = e.key.toLowerCase();
    const mod = e.ctrlKey || e.metaKey;
    if (mod && !e.altKey) {
      if (k === 'a' && !e.shiftKey) { selAction('all'); return true; }
      if (k === 'd' && !e.shiftKey) { selAction('none'); return true; }
      if (k === 'i' && e.shiftKey) { selAction('invert'); return true; }
      return false;
    }
    if (k === 'delete' || k === 'backspace') {
      if (!doc().selection) return false;
      selAction(e.altKey ? 'fill' : 'delete');
      return true;
    }
    if (mod || e.altKey) return false;
    if (k === 'm') { setTool(state.tool === 'rect' ? 'ellipse' : 'rect'); return true; }
    const t = Object.entries(TOOLS).find(([, v]) => v.key === k);
    if (t) { setTool(t[0]); return true; }
    if (k === '[' || k === ']') {
      const s = state.size;
      state.size = Math.max(1, Math.min(SIZE_MAX, k === ']' ? Math.ceil(s * 1.2) : Math.floor(s / 1.2)));
      sync();
      updateCursor();
      return true;
    }
    return false;
  }

  sync();
  setTool(state.tool);
  return { state, setTool, down, move, up, cancel, key, updateCursor, hideCursor, selAction, get stroking() { return !!stroke || !!lasso; } };
}
