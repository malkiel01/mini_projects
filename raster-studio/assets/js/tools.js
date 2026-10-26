// הכלים: ✋ יד · בחירות (מלבן, אליפסה, לאסו, 🪄 מטה קסם) · 🖌️ מכחול · 🧽 מחק · 🧬 חותמת שכפול · 🪣 דלי · 💧 טפטפת, הצבע,
// ולוח האפשרויות של הכלי הפעיל.
// הציור עצמו: משיכה במנוע (beginStroke → dab → endStroke), ובסופה רשומה אחת בהיסטוריה.

import { colorRange, dabsAlong, floodFill, hexToRgb, rgbToHex } from './engine/fill.js';
import { combine, isEmptyShape, maskShape, selectionBox } from './engine/selection.js';
import * as XF from './engine/transform.js';
import { FONTS, LINE_HEIGHT, cssFont, renderText, textDirection } from './engine/text.js';

export const TOOLS = {
  hand: { label: 'יד', icon: '✋', key: 'h', hint: 'גרירה — הזזה' },
  crop: { label: 'חיתוך', icon: '✂️', key: 'c', hint: 'גרירה — אזור (גם מעבר לקנבס, להגדלה) · ידיות — כיוונון · Enter — חיתוך · Esc — ביטול' },
  move: { label: 'הזזה ושינוי צורה', icon: '✥', key: 'v', hint: 'גרירה — הזזה · ידיות — גודל (Shift — חופשי) · ליד פינה — סיבוב · Enter — החלה · Esc — ביטול' },
  rect: { label: 'בחירה מלבנית', icon: '⬚', key: 'm', hint: 'Shift — הוספה · Alt — חיסור · Ctrl+D — ביטול הבחירה' },
  ellipse: { label: 'בחירה אליפטית', icon: '◯', key: null, hint: 'Shift — הוספה · Alt — חיסור · M — מעבר בין מלבן לאליפסה' },
  lasso: { label: 'לאסו', icon: '➰', key: 'l', hint: 'גרירה סביב האזור · Shift — הוספה · Alt — חיסור' },
  wand: { label: 'מטה קסם', icon: '🪄', key: 'w', hint: 'לחיצה — האזור בצבע דומה · בלי "רציף" — כל הצבע בתמונה · Shift — הוספה · Alt — חיסור' },
  brush: { label: 'מכחול', icon: '🖌️', key: 'b', hint: 'Alt+לחיצה — דגימת צבע · [ ] — גודל' },
  eraser: { label: 'מחק', icon: '🧽', key: 'e', hint: '[ ] — גודל' },
  clone: { label: 'חותמת שכפול', icon: '🧬', key: 's', hint: 'Alt+לחיצה (או 🎯) — נקודת המקור · ציור — מעתיק ממנה, באותו מרחק · [ ] — גודל' },
  bucket: { label: 'דלי', icon: '🪣', key: 'g', hint: 'לחיצה — מילוי אזור בשכבה הפעילה' },
  text: { label: 'טקסט', icon: '𝐓', key: 't', hint: 'לחיצה — תיבת טקסט · Ctrl+Enter או לחיצה בחוץ — אישור · Esc — ביטול' },
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
  const state = {
    tool: 'brush', color: '#1b1e26', size: 12, hardness: 0.8, opacity: 1, tolerance: 32, selMode: 'new',
    contiguous: true, sampleAll: false,
    font: FONTS[0].family, fontSize: 48, bold: false, align: 'start',
  };
  let stroke = null;   // { layer, last, carry, pen }
  let lasso = null;    // גרירת בחירה: { type, mode, start, pts, shape }
  let xf = null;       // גרירה בכלי ההזזה: { kind, handle, t0, start }
  let grabPending = null; // לחיצה בכלי ההזזה שמחכה לתוכן הצף: { last, up, cancelled }
  // פעולות שמחכות לקריאה מהכרטיס (דלי, טפטפת, הזזה) — settle מחכה שכולן יסתיימו
  const ops = new Set();
  const track = p => { ops.add(p); const del = () => ops.delete(p); p.then(del, del); return p; };
  let floatSel = null; // הבחירה כשהתוכן הצף התחיל — זזה איתו
  let groupMove = null; // הזזת קבוצה: { layers, start, dx, dy } — כל השכבות שבה, בפיקסלים שלמים
  let cropRect = null; // { x0, y0, x1, y1 } — אזור החיתוך המוצע
  let cropDrag = null; // { kind: 'new' | 'move' | 'handle', handle, r0, start }
  let textEdit = null; // { anchor } — תיבת טקסט פתוחה
  // חותמת שכפול: anchor — נקודת המקור שנבחרה; offset — המקור פחות היעד, נקבע במשיכה הראשונה אחרי הבחירה ונשמר
  // למשיכות הבאות (כמו "Aligned" בפוטושופ). picking — הלחיצה הבאה קובעת מקור (🎯, למסך מגע בלי Alt)
  const cloneSrc = { anchor: null, offset: null, picking: false };
  const textBox = $('#text-edit');

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
  colorIn.addEventListener('input', () => { state.color = colorIn.value; syncColor(); styleText(); });
  // טקסט: גופן, גודל, מודגש, יישור
  const fontSel = $('#opt-font');
  fontSel.replaceChildren(...FONTS.map(f => { const o = new Option(f.label, f.family); o.style.fontFamily = `"${f.family}"`; return o; }));
  fontSel.addEventListener('change', () => { state.font = fontSel.value; styleText(); });
  $('#opt-font-size').addEventListener('input', e => {
    const v = Math.round(Number(e.target.value));
    if (v >= 4 && v <= 1000) { state.fontSize = v; styleText(); }
  });
  $('#opt-bold').addEventListener('click', e => {
    state.bold = !state.bold;
    e.currentTarget.setAttribute('aria-pressed', String(state.bold));
    styleText();
  });
  for (const r of opts.querySelectorAll('input[name="align"]')) {
    r.addEventListener('change', () => { if (r.checked) { state.align = r.value; styleText(); } });
  }
  for (const r of opts.querySelectorAll('input[name="selmode"]')) {
    r.addEventListener('change', () => { if (r.checked) state.selMode = r.value; });
  }
  $('#opt-contiguous').addEventListener('change', e => { state.contiguous = e.target.checked; });
  $('#opt-sample-all').addEventListener('change', e => { state.sampleAll = e.target.checked; });
  opts.addEventListener('click', e => {
    const b = e.target.closest('[data-sel]');
    if (b) selAction(b.dataset.sel);
    const x = e.target.closest('[data-xf]');
    if (x) { if (x.dataset.xf === 'apply') commitFloat(); else cancelFloat(); }
    const tb = e.target.closest('[data-text]');
    if (tb) { if (tb.dataset.text === 'apply') commitText(); else cancelText(); }
    if (e.target.closest('[data-clone="source"]')) setClonePicking(!cloneSrc.picking);
    const c = e.target.closest('[data-crop]');
    if (c) { if (c.dataset.crop === 'apply') applyCrop(); else cancelCrop(); }
  });
  function syncColor() {
    colorIn.value = state.color;
    $('#color-wrap').style.setProperty('--swatch', state.color);
  }

  function setTool(id) {
    if (!TOOLS[id]) return;
    cancel();
    if (id !== 'move') commitFloat();
    if (id !== 'crop') cancelCrop();
    if (id !== 'text') commitText();
    state.tool = id;
    setClonePicking(false);
    showCloneMark(null);
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
    if (!e) styleText();   // זום / הזזה — התיבה עוקבת
    if (e) lastHover = e;
    const show = lastHover && painting() && lastHover.pointerType !== 'touch';
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
      // על מסכה: מחיקה — שחור (מסתיר), מילוי — האפור של הצבע
      const t = target(layer);
      const p = onMask(layer) && a === 'delete' ? { mode: 'paint', color: [0, 0, 0] } : paintOn(layer, a === 'delete' ? 'erase' : 'paint');
      const before = renderer.fillSelection(d, t, p.mode, p.color, 1);
      if (before.size) history.pixels(d, t, before, (a === 'delete' ? 'מחיקת הבחירה' : 'מילוי הבחירה') + (t !== layer ? ' (מסכה)' : ''));
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

  /**
   * מטה קסם: האזור בצבע דומה לנקודה — בשכבה הפעילה, או בתמונה כולה (sampleAll). בלי contiguous — כל הפיקסלים
   * בצבע הזה (טווח צבעים). התוצאה — צורת מסכה בבחירה, עם אותם מצבים (חדשה / הוספה / חיסור).
   */
  let wanding = false;
  async function wand([x, y], mode) {
    const d = doc(), layer = d.active;
    const xi = Math.floor(x), yi = Math.floor(y);
    if (wanding || xi < 0 || yi < 0 || xi >= d.width || yi >= d.height) return;
    const merged = state.sampleAll || !layer || layer.type === 'group';
    wanding = true;
    let px;
    try {
      px = merged ? await renderer.readPixels(d, 0, 0, d.width, d.height) : await renderer.readLayerPixels(layer, 0, 0, d.width, d.height);
    } finally { wanding = false; }
    if (doc() !== d || fl()) return;
    const r = (state.contiguous ? floodFill : colorRange)(px, d.width, d.height, xi, yi, state.tolerance);
    const shape = maskShape(r.mask, d.width, r.box);
    if (!shape) { if (mode === 'new') setSelection(null, 'ביטול הבחירה'); return; }
    setSelection(combine(d.selection, shape, mode), mode === 'sub' ? 'חיסור מהבחירה' : mode === 'add' ? 'הוספה לבחירה' : 'מטה קסם');
  }

  // ---- הזזה ושינוי צורה ----
  const selectionBoxOf = d => selectionBox(d.selection, d.width, d.height);
  const fl = () => doc()?._float;

  function showBox() {
    const f = fl();
    overlay.box(f ? XF.corners(f.t, f.w, f.h) : null, f ? XF.HANDLES.map(h => XF.handlePos(f.t, f.w, f.h, h)) : null);
    const info = $('#xf-info');
    if (info) {
      info.textContent = f ? `${Math.round(Math.abs(f.t.sx) * 100)}% × ${Math.round(Math.abs(f.t.sy) * 100)}% · ${Math.round(f.t.angle * 180 / Math.PI)}°` : '';
    }
    for (const b of opts.querySelectorAll('[data-xf]')) b.disabled = !f;
  }

  /**
   * מתחיל תוכן צף מהבחירה (או מכל השכבה). מחזיר Promise — אם הצליח. גבולות השכבה נקראים מהכרטיס (אסינכרוני),
   * ולכן קריאה שנייה בזמן ההמתנה מקבלת את אותה הבטחה.
   */
  let floatPending = null;
  function startFloat() {
    if (fl()) return Promise.resolve(true);
    floatPending ??= beginFloatFrom().finally(() => { floatPending = null; });
    return floatPending;
  }

  async function beginFloatFrom() {
    const d = doc(), layer = d.active;
    if (!canEdit(layer)) return false;
    let rect;
    if (d.selection) {
      const b = selectionBoxOf(d);
      if (!b) { toast('הבחירה ריקה'); return false; }
      rect = [b[0], b[1], b[2] - b[0], b[3] - b[1]];
    } else {
      rect = await renderer.layerBounds(layer);
      // בזמן ההמתנה המסמך או השכבה הפעילה התחלפו — לא מתחילים
      if (doc() !== d || d.active !== layer || fl()) return !!fl();
      if (!rect) { toast('השכבה ריקה — אין מה להזיז'); return false; }
    }
    try {
      renderer.beginFloat(d, layer, rect, XF.identity(...rect));
    } catch (err) {
      toast(err.message === 'too_big' ? 'האזור גדול מדי להזזה בבת אחת — בחרו חלק ממנו' : 'לא הצלחתי להתחיל הזזה', true);
      return false;
    }
    floatSel = d.selection ?? null;
    showBox();
    draw();
    return true;
  }

  function commitFloat() {
    const d = doc(), f = d?._float;
    if (!f) return;
    xf = null;
    if (XF.isIdentityAt(f.t, f.x, f.y, f.w, f.h)) { renderer.cancelFloat(d); finishFloat(); return; }
    const m = XF.docMatrix(f.t, f.w, f.h, f.x, f.y);
    const before = renderer.commitFloat(d);
    let sel = null;
    if (floatSel) {
      const moved = { ...floatSel, shapes: floatSel.shapes.map(s => XF.transformShape(s, m)) };
      d.selection = moved;
      renderer.selectionChanged(d);
      sel = { before: floatSel, after: moved };
    }
    const pure = f.t.sx === 1 && f.t.sy === 1 && f.t.angle === 0;
    history.pixels(d, f.layer, before, pure ? 'הזזה' : 'שינוי צורה', sel);
    finishFloat();
    onChange('transform');
  }

  function cancelFloat() {
    const d = doc();
    if (!d?._float) return;
    xf = null;
    renderer.cancelFloat(d);
    finishFloat();
  }

  function finishFloat() {
    floatSel = null;
    showBox();
    draw();
  }

  async function nudge(dx, dy) {
    if (!await startFloat()) return;
    const f = fl();
    renderer.setFloatTransform(doc(), { ...f.t, cx: f.t.cx + dx, cy: f.t.cy + dy });
    showBox();
    draw();
  }

  // ---- חיתוך ----
  const CROP_HANDLES = [[-1, -1], [0, -1], [1, -1], [1, 0], [1, 1], [0, 1], [-1, 1], [-1, 0]];
  const norm = r => ({ x0: Math.min(r.x0, r.x1), y0: Math.min(r.y0, r.y1), x1: Math.max(r.x0, r.x1), y1: Math.max(r.y0, r.y1) });
  const cropHandlePos = (r, [hx, hy]) => [hx < 0 ? r.x0 : hx > 0 ? r.x1 : (r.x0 + r.x1) / 2, hy < 0 ? r.y0 : hy > 0 ? r.y1 : (r.y0 + r.y1) / 2];

  function showCrop() {
    const r = cropRect;
    overlay.crop(r);
    overlay.box(r ? [[r.x0, r.y0], [r.x1, r.y0], [r.x1, r.y1], [r.x0, r.y1]] : null, r ? CROP_HANDLES.map(h => cropHandlePos(r, h)) : null);
    const info = $('#crop-info');
    if (info) info.textContent = r ? `${r.x1 - r.x0} × ${r.y1 - r.y0}` : '';
    for (const b of opts.querySelectorAll('[data-crop]')) b.disabled = !r;
  }

  function cropDown(e, pt) {
    const tol = (e.pointerType === 'touch' ? 22 : 10) / view().zoom;
    const r = cropRect;
    if (r) {
      for (const h of CROP_HANDLES) {
        const q = cropHandlePos(r, h);
        if (Math.hypot(q[0] - pt[0], q[1] - pt[1]) <= tol) { cropDrag = { kind: 'handle', handle: h, r0: r, start: pt }; return; }
      }
      if (pt[0] > r.x0 && pt[0] < r.x1 && pt[1] > r.y0 && pt[1] < r.y1) { cropDrag = { kind: 'move', r0: r, start: pt }; return; }
    }
    cropDrag = { kind: 'new', start: pt };
  }

  function cropMove(p) {
    const g = cropDrag, R = Math.round;
    if (g.kind === 'new') cropRect = norm({ x0: R(g.start[0]), y0: R(g.start[1]), x1: R(p[0]), y1: R(p[1]) });
    else if (g.kind === 'move') {
      const dx = R(p[0] - g.start[0]), dy = R(p[1] - g.start[1]);
      cropRect = { x0: g.r0.x0 + dx, y0: g.r0.y0 + dy, x1: g.r0.x1 + dx, y1: g.r0.y1 + dy };
    } else {
      const [hx, hy] = g.handle, r = { ...g.r0 };
      if (hx < 0) r.x0 = R(p[0]); if (hx > 0) r.x1 = R(p[0]);
      if (hy < 0) r.y0 = R(p[1]); if (hy > 0) r.y1 = R(p[1]);
      cropRect = norm(r);
    }
    showCrop();
  }

  function cancelCrop() {
    cropRect = null;
    cropDrag = null;
    if (overlay) showCrop();
  }

  /** חיתוך הקנבס למלבן — כל השכבות זזות, הגודל משתנה. אזור מעבר לקנבס — הגדלה (שקוף). */
  function applyCrop() {
    const r = cropRect, d = doc();
    if (!r) return;
    const w = r.x1 - r.x0, h = r.y1 - r.y0;
    if (w < 1 || h < 1) { toast('האזור ריק'); return; }
    if (r.x0 === 0 && r.y0 === 0 && w === d.width && h === d.height) { cancelCrop(); return; }
    const all = withMasks(d.rasters());
    const before = { w: d.width, h: d.height, maps: new Map(all.map(l => [l, l.tiles])), sel: d.selection ?? null };
    const maps = new Map(all.map(l => [l, renderer.shiftTiles(l.tiles, -r.x0, -r.y0)]));
    const m = [1, 0, 0, 1, -r.x0, -r.y0];
    const sel = d.selection ? { ...d.selection, shapes: d.selection.shapes.map(s => XF.transformShape(s, m)) } : null;
    const after = { w, h, maps, sel };
    d.width = w; d.height = h;
    for (const [l, t] of maps) { l.tiles = t; l.rev = (l.rev ?? 0) + 1; }
    d.selection = sel;
    renderer.resetComposite(d);
    history.crop(d, before, after, 'חיתוך');
    cancelCrop();
    onChange('crop');
  }

  // ---- טקסט ----
  /** התיבה בגודל, בגופן ובמקום האמיתיים, לפי הזום */
  function styleText() {
    if (!textEdit) return;
    const v = view(), z = v.zoom;
    const r = $('#stage').getBoundingClientRect();
    const sx = v.x + textEdit.anchor[0] * z, sy = v.y + textEdit.anchor[1] * z;
    const dir = textDirection(textBox.value || 'א');
    const side = state.align === 'center' ? 'center' : (state.align === 'start') === (dir === 'rtl') ? 'right' : 'left';
    Object.assign(textBox.style, {
      font: cssFont({ family: state.font, size: state.fontSize * z, bold: state.bold }),
      lineHeight: String(LINE_HEIGHT), color: state.color, textAlign: side, direction: dir,
      top: sy + 'px', left: '', right: '', transform: '',
    });
    if (side === 'right') textBox.style.right = (r.width - sx) + 'px';
    else textBox.style.left = sx + 'px';
    if (side === 'center') textBox.style.transform = 'translateX(-50%)';
    // גודל התיבה לפי התוכן
    textBox.style.width = '0px';
    textBox.style.height = '0px';
    textBox.style.width = Math.max(state.fontSize * z, textBox.scrollWidth + 4) + 'px';
    textBox.style.height = textBox.scrollHeight + 'px';
    for (const b of opts.querySelectorAll('[data-text]')) b.disabled = false;
  }

  function startText(pt) {
    textEdit = { anchor: pt };
    textBox.value = '';
    textBox.hidden = false;
    styleText();
    textBox.focus();
    requestAnimationFrame(() => textEdit && textBox.focus());   // ליתר ביטחון — אחרי שהלחיצה הסתיימה
  }

  function closeText() {
    textEdit = null;
    textBox.hidden = true;
    textBox.value = '';
    for (const b of opts.querySelectorAll('[data-text]')) b.disabled = true;
  }

  function cancelText() { if (textEdit) closeText(); }

  async function commitText() {
    if (!textEdit) return;
    const text = textBox.value, anchor = textEdit.anchor;
    closeText();
    const d = doc();
    const r = await renderText(text, { family: state.font, size: state.fontSize, bold: state.bold, color: state.color, align: state.align }, anchor);
    if (!r) return;
    const layer = d.addLayer({ name: text.trim().split('\n')[0].slice(0, 24) || 'טקסט', index: d.indexOf(d.active) + 1 });
    await renderer.drawImage(d, layer, r.bitmap, r.x, r.y);
    r.bitmap.close();
    history.addLayer(d, layer, 'טקסט');
    onChange('text');
  }

  textBox.addEventListener('input', styleText);
  textBox.addEventListener('keydown', e => {
    if (e.key === 'Escape') { e.preventDefault(); cancelText(); }
    else if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); commitText(); }
  });

  // ---- ציור ----
  const painting = () => state.tool === 'brush' || state.tool === 'eraser' || state.tool === 'clone';

  function setClonePicking(on) {
    cloneSrc.picking = on;
    $('[data-clone="source"]')?.setAttribute('aria-pressed', String(on));
  }

  /** נקודת המקור של החותמת: עיגול עם צלב, לפני המשיכה בעוגן ובזמן המשיכה — במקום שממנו מעתיקים */
  function showCloneMark(p) {
    if (!overlay) return;
    if (state.tool !== 'clone') { overlay.mark(null); return; }
    const o = cloneSrc.offset;
    overlay.mark(p && o ? [p[0] + o[0], p[1] + o[1]] : cloneSrc.anchor, state.size / 2);
  }

  /** אפשר לצייר על השכבה? אחרת — הודעה, ולא מציירים. */
  function canEdit(layer) {
    if (!layer) return false;
    if (layer.foreign) { toast(`שכבה של אפליקציה אחרת (${layer.foreign.type}) — נשמרת כמו שהיא, ולא נערכת כאן`, true); return false; }
    if (layer.type === 'group') { toast('בחרו שכבה בתוך הקבוצה — על קבוצה לא מציירים', true); return false; }
    const up = doc().ancestors(layer);
    if (up.some(g => g.locked)) { toast('הקבוצה נעולה — 🔓 בלוח השכבות', true); return false; }
    if (up.some(g => !g.visible)) { toast('הקבוצה מוסתרת — 👁️ בלוח השכבות', true); return false; }
    if (layer.locked) { toast('השכבה נעולה — 🔓 בלוח השכבות', true); return false; }
    if (!layer.visible) { toast('השכבה מוסתרת — 👁️ בלוח השכבות', true); return false; }
    return true;
  }

  // ---- מסכת שכבה ----
  // layer.editMask — הציור, הדלי, המילוי והמחיקה הולכים למסכה: בגווני אפור של הצבע (לבן — נראה, שחור — מוסתר)
  const target = layer => (layer.mask && layer.editMask ? layer.mask : layer);
  const onMask = layer => target(layer) !== layer;
  const gray = rgb => { const y = Math.round(0.299 * rgb[0] + 0.587 * rgb[1] + 0.114 * rgb[2]); return [y, y, y]; };
  /** הצבע לציור על היעד: על מסכה — אפור; mode 'erase' על מסכה — לבן (מחזיר את מה שהוסתר) */
  const paintOn = (layer, mode) => {
    if (!onMask(layer)) return { mode, color: hexToRgb(state.color) };
    return mode === 'erase' ? { mode: 'paint', color: [255, 255, 255] } : { mode, color: gray(hexToRgb(state.color)) };
  };
  /** השכבות והמסכות שלהן — לחיתוך ולהזזת קבוצה (המסכה זזה עם השכבה) */
  const withMasks = layers => layers.flatMap(l => (l.mask ? [l, l.mask] : [l]));

  async function pick([x, y]) {
    const d = doc();
    const xi = Math.floor(x), yi = Math.floor(y);
    if (xi < 0 || yi < 0 || xi >= d.width || yi >= d.height) return;
    const p = await renderer.readPixels(d, xi, yi, 1, 1);
    if (doc() !== d) return;
    if (!p[3]) { toast('כאן שקוף — אין צבע לדגום'); return; }
    state.color = rgbToHex([p[0], p[1], p[2]]);
    syncColor();
  }

  let filling = false;
  async function bucket([x, y]) {
    const d = doc(), layer = d.active;
    if (filling || !canEdit(layer)) return;
    const xi = Math.floor(x), yi = Math.floor(y);
    if (xi < 0 || yi < 0 || xi >= d.width || yi >= d.height) return;
    filling = true;
    let px;
    const t = target(layer);
    try { px = await renderer.readLayerPixels(t, 0, 0, d.width, d.height); } finally { filling = false; }
    // בזמן הקריאה: מסמך אחר, שכבה אחרת, משיכה או תוכן צף — הפיקסלים כבר לא מה שנקרא
    if (doc() !== d || d.active !== layer || target(layer) !== t || stroke || fl() || !canEdit(layer)) return;
    const r = floodFill(px, d.width, d.height, xi, yi, state.tolerance);
    if (!r.count) return;
    renderer.beginStroke(d, t, { ...paintOn(layer, 'paint'), opacity: state.opacity });
    renderer.maskFromAlpha(d, r.mask, 0, 0, d.width, d.height, r.box);
    const before = renderer.endStroke(d);
    if (before.size) history.pixels(d, t, before, t !== layer ? 'דלי (מסכה)' : 'דלי');
    onChange('bucket');
  }

  /** e — אירוע ה-pointerdown; pt — הנקודה במסמך. מחזיר true אם הכלי לקח את המצביע. */
  function down(e, pt) {
    if (state.tool === 'hand') return false;
    if (state.tool === 'crop') { cropDown(e, pt); return true; }
    if (state.tool === 'text') {
      // ברירת המחדל של הלחיצה מעבירה את הפוקוס לגוף הדף — היא חוסמת את ההקלדה בתיבה
      e.preventDefault();
      // לחיצה בזמן עריכה — אישור; אחרת — תיבה חדשה בנקודה
      if (textEdit) commitText(); else startText(pt);
      return true;
    }
    if (state.tool === 'move' && doc().active?.type === 'group') {
      const g = doc().active;
      if (g.locked || doc().ancestors(g).some(a => a.locked)) { toast('הקבוצה נעולה — 🔓 בלוח השכבות', true); return true; }
      const layers = [...doc().walk(g.children)].map(w => w.layer).filter(l => l.type !== 'group');
      if (!layers.some(l => l.tiles.size)) { toast('הקבוצה ריקה — אין מה להזיז'); return true; }
      groupMove = { layers, start: pt, dx: 0, dy: 0 };
      renderer.setShift(doc(), layers, 0, 0);
      return true;
    }
    if (state.tool === 'move') {
      const tol = (e.pointerType === 'touch' ? 22 : 10) / view().zoom;
      const grab = () => {
        const f = fl();
        const h = XF.hit(f.t, f.w, f.h, pt, tol) ?? { kind: 'move' };
        xf = { ...h, t0: f.t, start: pt };
      };
      if (fl()) { grab(); return true; }
      // התוכן הצף עוד לא מוכן (גבולות השכבה נקראים): התנועות וההרמה נשמרות עד שהוא מוכן
      const g = grabPending = { last: null, up: false, cancelled: false };
      track(startFloat().then(ok => {
        if (grabPending === g) grabPending = null;
        if (!ok || g.cancelled || !fl()) return;
        grab();
        if (g.last) move(...g.last);
        if (g.up) up();
      }, err => { if (grabPending === g) grabPending = null; console.warn('float', err); }));
      return true;
    }
    if (selecting()) {
      const mode = e.shiftKey ? 'add' : e.altKey ? 'sub' : state.selMode;
      lasso = { type: state.tool, mode, start: pt, pts: [pt] };
      overlay.preview(lassoShape());
      return true;
    }
    if (state.tool === 'wand') { track(wand(pt, e.shiftKey ? 'add' : e.altKey ? 'sub' : state.selMode)); return true; }
    if (state.tool === 'clone' && (e.altKey || cloneSrc.picking)) {
      cloneSrc.anchor = [Math.round(pt[0]), Math.round(pt[1])];
      cloneSrc.offset = null;
      setClonePicking(false);
      showCloneMark(null);
      return true;
    }
    if (state.tool === 'picker' || (e.altKey && painting())) { track(pick(pt)); return true; }
    if (state.tool === 'bucket') { track(bucket(pt)); return true; }
    const d = doc(), layer = d.active;
    if (!canEdit(layer)) return true;
    if (state.tool === 'clone') {
      if (!cloneSrc.anchor) { toast('קודם נקודת המקור: Alt+לחיצה, או 🎯 ואז הקשה'); return true; }
      cloneSrc.offset ??= [cloneSrc.anchor[0] - Math.round(pt[0]), cloneSrc.anchor[1] - Math.round(pt[1])];
    }
    const pen = e.pointerType === 'pen';
    const t = target(layer);
    renderer.beginStroke(d, t, {
      ...paintOn(layer, state.tool === 'eraser' ? 'erase' : state.tool === 'clone' ? 'clone' : 'paint'),
      opacity: state.opacity, offset: cloneSrc.offset ?? [0, 0],
    });
    if (state.tool === 'clone') showCloneMark(pt);
    const p = { x: pt[0], y: pt[1], p: pen ? e.pressure || 0.5 : 1 };
    stroke = { layer: t, last: p, carry: 0, pen };
    dabAt(p);
    draw();
    return true;
  }

  function dabAt(p) {
    renderer.dab(doc(), p.x, p.y, state.size / 2 * Math.max(0.1, p.p), state.hardness);
  }

  /** points — נקודות במסמך עם לחץ: [{x, y, pressure}] (כולל האירועים המאוחדים) */
  function move(points, e) {
    if (grabPending) { grabPending.last = [points, e]; return; }
    if (groupMove) {
      const q = points.at(-1);
      groupMove.dx = Math.round(q.x - groupMove.start[0]);
      groupMove.dy = Math.round(q.y - groupMove.start[1]);
      renderer.setShift(doc(), groupMove.layers, groupMove.dx, groupMove.dy);
      draw();
      return;
    }
    if (cropDrag) { const q = points.at(-1); cropMove([q.x, q.y]); return; }
    if (xf) {
      const q = points.at(-1), p = [q.x, q.y];
      let t;
      if (xf.kind === 'move') {
        // הזזה בפיקסלים שלמים — התוכן נשאר חד
        t = { ...xf.t0, cx: xf.t0.cx + Math.round(p[0] - xf.start[0]), cy: xf.t0.cy + Math.round(p[1] - xf.start[1]) };
      } else if (xf.kind === 'handle') {
        t = XF.dragHandle(xf.t0, fl().w, fl().h, xf.handle, p, !!e?.shiftKey);
      } else {
        t = XF.dragRotate(xf.t0, xf.start, p, !!e?.shiftKey);
      }
      renderer.setFloatTransform(doc(), t);
      showBox();
      draw();
      return;
    }
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
    if (state.tool === 'clone') showCloneMark([stroke.last.x, stroke.last.y]);
    draw();
  }

  /** סיום הזזת קבוצה: כל שכבה עוברת באותה הזזה שלמה; צעד ביטול אחד */
  function commitGroupMove(dx, dy, layers) {
    const d = doc();
    renderer.setShift(d, null);
    if (!dx && !dy) return;
    const all = withMasks(layers);
    const before = { w: d.width, h: d.height, maps: new Map(all.map(l => [l, l.tiles])), sel: d.selection ?? null };
    const maps = new Map(all.map(l => [l, renderer.shiftTiles(l.tiles, dx, dy)]));
    for (const [l, t] of maps) { l.tiles = t; l.rev = (l.rev ?? 0) + 1; }
    renderer.resetComposite(d);
    history.crop(d, before, { ...before, maps }, 'הזזת קבוצה');
    onChange('group-move');
  }

  function up() {
    if (grabPending) { grabPending.up = true; grabPending = null; return; }
    if (groupMove) { const g = groupMove; groupMove = null; commitGroupMove(g.dx, g.dy, g.layers); return; }
    if (cropDrag) { cropDrag = null; return; }
    if (xf) { xf = null; return; }
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
    if (before.size) history.pixels(d, s.layer, before, ({ eraser: 'מחק', clone: 'חותמת שכפול' }[state.tool] ?? 'מכחול') + (s.layer.owner ? ' (מסכה)' : ''));
    showCloneMark(null);
    onChange('stroke');
  }

  /** משיכה פתוחה בוטלה (אצבע שנייה → צביטה). */
  function cancel() {
    if (grabPending) { grabPending.cancelled = true; grabPending = null; return; }
    if (groupMove) { groupMove = null; renderer.setShift(doc(), null); draw(); return; }
    if (lasso) { lasso = null; overlay.preview(null); return; }
    if (!stroke) return;
    stroke = null;
    renderer.cancelStroke(doc());
    showCloneMark(null);
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
    if (fl()) {
      if (k === 'enter') { commitFloat(); return true; }
      if (k === 'escape') { cancelFloat(); return true; }
    }
    if (cropRect) {
      if (k === 'enter') { applyCrop(); return true; }
      if (k === 'escape') { cancelCrop(); return true; }
    }
    if (state.tool === 'move' && k.startsWith('arrow') && doc().active?.type === 'group') {
      const step = e.shiftKey ? 10 : 1, g = doc().active;
      const layers = [...doc().walk(g.children)].map(w => w.layer).filter(l => l.type !== 'group');
      commitGroupMove(k === 'arrowleft' ? -step : k === 'arrowright' ? step : 0, k === 'arrowup' ? -step : k === 'arrowdown' ? step : 0, layers);
      return true;
    }
    if (state.tool === 'move' && k.startsWith('arrow')) {
      const step = e.shiftKey ? 10 : 1;
      track(nudge(k === 'arrowleft' ? -step : k === 'arrowright' ? step : 0, k === 'arrowup' ? -step : k === 'arrowdown' ? step : 0));
      return true;
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
  return {
    state, setTool, setColor(hex) { if (/^#[0-9a-f]{6}$/i.test(hex)) { state.color = hex; syncColor(); } }, down, move, up, cancel, key, updateCursor, hideCursor, selAction, commitFloat, cancelFloat, commitText,
    get editingText() { return !!textEdit; },
    get stroking() { return !!stroke || !!lasso || !!xf || !!cropDrag || !!groupMove || !!grabPending; },
    get floating() { return !!fl(); },
    /** מחכה שכל הפעולות שממתינות לקריאה מהכרטיס יסתיימו (גם אלה שנוספו בזמן ההמתנה) */
    async settle() { while (ops.size) await Promise.allSettled([...ops]); },
  };
}
