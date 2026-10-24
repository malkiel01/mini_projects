// העורך: מצב, קלט (עכבר, מגע, עט, מקלדת), היסטוריה ושמירת טיוטה.
//
// התצוגה: view = { x, y, z } — נקודה במרחב העבודה (pt) מגיעה למסך ב-(p·z + x, p·z + y) פיקסלי CSS.
// מגע: אצבע על רקע — הזזת התצוגה; שתיים — זום וגלילה. עכבר: גרירה על רקע — בחירה במלבן; גלגלת — גלילה,
// ועם Ctrl — זום. רווח + גרירה — הזזת התצוגה.

import { apply, invert, scaleAt, translate } from './geometry/matrix.js';
import { shapeFromDrag, DEFAULTS } from './geometry/shapes.js';
import {
  SIZES, DEFAULT_STYLE, newDocument, nextId, layout, objects, findPath, selectionBox, applyMatrix, setShape, clone,
  leaves, cloneItem, ids,
} from './model.js';
import { group, ungroup, reorder, align, distribute, addLayer, removeLayer, moveLayer, moveToLayer } from './arrange.js';
import { render, hitTest, inRect, handlePoints, readColors, HANDLE, viewMatrix } from './render.js';
import { renderProps, renderLayers } from './props.js';
import { createPenTools } from './pen.js';
import { snapper } from './snap.js';
import * as drafts from './drafts.js';

const $ = (id) => document.getElementById(id);
const canvas = $('canvas'), stage = $('stage');

const S = {
  id: null, name: 'ציור', doc: null,
  view: { x: 0, y: 0, z: 1 }, dpr: 1,
  sel: new Set(), hover: null, marquee: null, tool: 'select', active: null, guides: null,
  snap: pref('snap', true), grid: pref('grid', false), // active — השכבה לאובייקטים חדשים
  undo: [], redo: [],
};

// העדפות תצוגה (הצמדה, רשת) — נזכרות במכשיר
function pref(k, d) { try { const v = localStorage.getItem('vector-studio:' + k); return v === null ? d : v === '1'; } catch { return d; } }
function setPref(k, v) { try { localStorage.setItem('vector-studio:' + k, v ? '1' : '0'); } catch { /* חסום */ } }

/** הצמדה לגרירה אחת. Ctrl / ⌘ בזמן הגרירה — בלי הצמדה. */
function makeSnap(exclude, surface, touch) {
  if (!S.snap && !S.grid) return null;
  const sf = surface || S.doc.surfaces[0];
  const sn = snapper({ doc: S.doc, exclude, tol: (touch ? 10 : 6) / S.view.z, grid: S.grid, origin: layout(S.doc).get(sf.id) });
  if (S.snap) return sn;
  // רשת בלבד — בלי קווים חכמים: מסננים את היעדים
  return snapper({ doc: { surfaces: [] }, exclude, tol: (touch ? 10 : 6) / S.view.z, grid: true, origin: layout(S.doc).get(sf.id) });
}

// ---------- טעינה ----------

function boot() {
  const hash = decodeURIComponent(location.hash.slice(1));
  if (hash.startsWith('new:')) {
    const [, sizeId, w, h] = hash.split(':');
    const preset = SIZES.find(s => s.id === sizeId);
    const size = preset ? preset.size : [Number(w), Number(h)];
    if (!(size[0] > 0 && size[1] > 0)) return leave();
    S.id = drafts.newId();
    S.name = 'ציור ' + new Date().toLocaleDateString('he-IL');
    S.doc = newDocument(size);
    history.replaceState(null, '', '#' + S.id);
    persist();
  } else {
    const d = drafts.load(hash);
    if (!d || !d.doc) return leave();
    S.id = hash; S.name = d.name || 'ציור'; S.doc = d.doc;
  }
  $('doc-name').textContent = S.name;
  document.title = S.name + ' — וקטורי';
  resize();
  fit();
  updatePanel();
}

function leave() { location.replace('./'); }

// ---------- שמירת טיוטה ----------

let saveTimer = 0;
function persist() {
  clearTimeout(saveTimer);
  saveTimer = setTimeout(() => {
    const ok = drafts.save(S.id, S.name, { name: S.name, doc: S.doc, saved: new Date().toISOString() });
    $('doc-status').textContent = ok ? 'טיוטה במכשיר · נשמר' : 'לא נשמר — האחסון במכשיר חסום';
  }, 400);
}

// ---------- היסטוריה ----------

/** עוטף שינוי: לפני — צילום, אחרי — אם השתנה, נכנס לביטול. */
function change(fn) {
  const before = JSON.stringify(S.doc);
  fn();
  commitFrom(before);
}

function commitFrom(before) {
  if (before === JSON.stringify(S.doc)) return;
  S.undo.push(before);
  if (S.undo.length > 200) S.undo.shift();
  S.redo = [];
  afterChange();
}

const tools = createPenTools({
  S, DEFAULT_STYLE,
  surfaceAt: (...a) => surfaceAt(...a), targetLayer: (...a) => targetLayer(...a),
  commitFrom: (...a) => commitFrom(...a), toWorld: (...a) => toWorld(...a),
  hitTest: (sx, sy, t) => hitTest(S, sx, sy, t),
  draw: () => draw(), updateBar: () => updateBar(),
  refresh: () => { updatePanel(); draw(); },
});

function afterChange() {
  // נתיב שנמחק לא נשאר בבחירה
  for (const id of [...S.sel]) if (!findPath(S.doc, id)) S.sel.delete(id);
  $('undo').disabled = !S.undo.length;
  $('redo').disabled = !S.redo.length;
  persist();
  updatePanel();
  draw();
}

function undo() {
  if (!S.undo.length) return;
  S.redo.push(JSON.stringify(S.doc));
  S.doc = JSON.parse(S.undo.pop());
  afterChange();
  tools.reload();
}
function redo() {
  if (!S.redo.length) return;
  S.undo.push(JSON.stringify(S.doc));
  S.doc = JSON.parse(S.redo.pop());
  afterChange();
  tools.reload();
}

// ---------- ציור ----------

let frame = 0;
function draw() {
  if (frame) return;
  frame = requestAnimationFrame(() => {
    frame = 0;
    const pathMode = S.tool === 'pen' || S.tool === 'node';
    render(canvas, {
      ...S, selBox: S.sel.size && !pathMode ? selectionBox(S.doc, S.sel) : null, handles: !op || op.type !== 'move',
      overlay: pathMode ? tools.overlay : null,
    });
    $('zoom-pct').textContent = Math.round(S.view.z * 100) + '%';
  });
}

let lastSize = null;
function resize() {
  const r = stage.getBoundingClientRect();
  // הבמה השתנתה (לוח המאפיינים נפתח, סיבוב מסך): מה שהיה במרכז נשאר במרכז
  if (lastSize) S.view = { ...S.view, x: S.view.x + (r.width - lastSize[0]) / 2, y: S.view.y + (r.height - lastSize[1]) / 2 };
  lastSize = [r.width, r.height];
  S.dpr = Math.min(window.devicePixelRatio || 1, 3);
  canvas.width = Math.max(1, Math.round(r.width * S.dpr));
  canvas.height = Math.max(1, Math.round(r.height * S.dpr));
  canvas.style.width = r.width + 'px';
  canvas.style.height = r.height + 'px';
  draw();
}

/** התאמה: כל הלוחות במסך, עם שוליים. */
function fit() {
  const r = stage.getBoundingClientRect();
  const lay = layout(S.doc);
  let x1 = 0, y1 = 0;
  for (const s of S.doc.surfaces) { const [ox, oy] = lay.get(s.id); x1 = Math.max(x1, ox + s.size[0]); y1 = Math.max(y1, oy + s.size[1]); }
  const pad = 32;
  const z = Math.max(0.02, Math.min((r.width - pad * 2) / x1, (r.height - pad * 2) / y1, 8));
  S.view = { z, x: (r.width - x1 * z) / 2, y: (r.height - y1 * z) / 2 };
  draw();
}

const toWorld = (sx, sy) => apply(invert(viewMatrix(S.view)), sx, sy);

function zoomAt(z, sx, sy) {
  z = Math.max(0.02, Math.min(64, z));
  const [wx, wy] = toWorld(sx, sy);
  S.view = { z, x: sx - wx * z, y: sy - wy * z };
  draw();
}

function zoomStep(dir) {
  const r = stage.getBoundingClientRect();
  const steps = [0.05, 0.1, 0.25, 0.5, 0.75, 1, 1.5, 2, 3, 4, 6, 8, 12, 16, 32, 64];
  const z = S.view.z;
  const next = dir > 0 ? steps.find(s => s > z * 1.01) : [...steps].reverse().find(s => s < z / 1.01);
  zoomAt(next || z, r.width / 2, r.height / 2);
}

// ---------- בחירה וממשק ----------

const SHAPES = ['rect', 'ellipse', 'polygon', 'star', 'line'];
let lastShape = 'rect';

function setTool(t) {
  S.tool = t;
  if (SHAPES.includes(t)) {
    lastShape = t;
    const src = document.querySelector(`[data-shape="${t}"]`);
    $('shape-icon').innerHTML = src.querySelector('svg').innerHTML;
    document.querySelector('[data-tool="shape"]').setAttribute('aria-label', 'צורות: ' + src.getAttribute('aria-label'));
  }
  document.querySelectorAll('.tool').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.tool === t || (b.dataset.tool === 'shape' && SHAPES.includes(t)))));
  canvas.style.cursor = t === 'select' || t === 'node' ? 'default' : 'crosshair';
  showShapePick(false);
  tools.activate(t);
  draw();
}

function showShapePick(open) {
  $('shape-pick').hidden = !open;
  document.querySelector('[data-tool="shape"]').setAttribute('aria-expanded', String(open));
}

/** סרגל צף לעט ולעריכת נקודות — בטלפון אין Enter ו-Delete. */
function updateBar() {
  const drawing = tools.drawing, ed = tools.editing;
  const show = (id, on) => { $(id).hidden = !on; };
  show('pen-done', drawing);
  show('pen-cancel', drawing);
  show('node-smooth', !!ed?.node);
  show('node-del', !!ed?.node);
  $('pen-hint').textContent = drawing ? '' : S.tool === 'pen' ? 'הקשה — נקודה, גרירה — עקומה'
    : S.tool === 'node' ? (ed ? (ed.node ? '' : 'גוררים נקודה או ידית; הקשה על הקו — נקודה חדשה') : 'בוחרים צורה לעריכה') : '';
  $('pen-bar').hidden = !(S.tool === 'pen' || S.tool === 'node');
}

function select(ids) {
  S.sel = new Set(ids);
  updatePanel();
  draw();
}

/** הלוח שבו הנקודה, או הראשון. */
function surfaceAt(wx, wy) {
  const lay = layout(S.doc);
  for (const s of S.doc.surfaces) {
    const [ox, oy] = lay.get(s.id);
    if (wx >= ox && wx <= ox + s.size[0] && wy >= oy && wy <= oy + s.size[1]) return s;
  }
  return S.doc.surfaces[0];
}

/** השכבה שאליה נכנסים אובייקטים חדשים: העליונה שאינה נעולה. */
function targetLayer(surface) {
  const layers = (surface.layers ||= []);
  const usable = (l) => l.type === 'vector' && !l.locked && l.visible !== false;
  let l = layers.find(l => l.id === S.active && usable(l)) || [...layers].reverse().find(usable);
  if (!l) {
    l = { id: nextId(S.doc, 'L'), name: 'שכבה ' + (layers.length + 1), type: 'vector', opacity: 1, blend: 'normal', visible: true, locked: false, paths: [] };
    layers.push(l);
  }
  return l;
}

function updatePanel() {
  const picked = [...S.sel].map(id => findPath(S.doc, id)).filter(Boolean);
  const surface = picked[0]?.surface || S.doc.surfaces[0];
  const off = layout(S.doc).get(surface.id);
  const wb = picked.length ? selectionBox(S.doc, S.sel) : null;
  const leafIds = () => picked.flatMap(o => [...leaves(o.path)].map(p => p.id));
  renderProps($('props-body'), {
    doc: S.doc, surface, items: picked.map(o => o.path), paths: picked.flatMap(o => [...leaves(o.path)]),
    layerId: picked[0]?.layer.id,
    box: wb ? [wb[0] - off[0], wb[1] - off[1], wb[2], wb[3]] : null,
    edit: (fn, commit) => {
      const before = editBase ?? JSON.stringify(S.doc);
      // הנתיבים שבתוך הקבוצות — לפי מזהה, כי המסמך עשוי להתחלף בין קריאה לקריאה
      const want = new Set(leafIds());
      for (const { path: item } of objects(S.doc)) for (const p of leaves(item)) if (want.has(p.id)) fn(p);
      if (commit) { editBase = null; commitFrom(before); }
      else { editBase = before; draw(); }
    },
    setBox: (box) => change(() => {
      // תיבה חדשה לבחירה: הזזה + קנה מידה מהתיבה הנוכחית, במרחב הלוח
      const [x, y, w, h] = [wb[0] - off[0], wb[1] - off[1], wb[2], wb[3]];
      const sx = w ? box[2] / w : 1, sy = h ? box[3] / h : 1;
      transformSelection(translate(box[0] - x * sx, box[1] - y * sy), sx, sy);
    }),
    setShape: (patch) => change(() => {
      const o = findPath(S.doc, picked[0].path.id);
      setShape(o.path, { ...o.path.shape, ...patch });
    }),
    setArtboard: ({ name, w, h }) => change(() => {
      if (name) surface.name = name;
      if (w) surface.size[0] = w;
      if (h) surface.size[1] = h;
    }),
    action: (a) => a === 'delete' ? removeSelection() : duplicateSelection(),
    arrange: (a, arg) => arrangeAction(a, arg),
  });
  const counts = new Map();
  for (const l of surface.layers || []) counts.set(l.id, (l.paths || []).length);
  const active = targetLayerId(surface);
  renderLayers($('layers-body'), {
    surface, activeId: active, counts,
    setActive: (id) => { S.active = id; updatePanel(); },
    toggle: (id, k) => change(() => {
      const l = surface.layers.find(x => x.id === id);
      if (k === 'visible') l.visible = l.visible === false;
      else l.locked = !l.locked;
      // מה שנבחר בשכבה שננעלה או הוסתרה — יוצא מהבחירה
      for (const p of l.paths || []) if (l.locked || l.visible === false) S.sel.delete(p.id);
    }),
    rename: (id, name) => change(() => { surface.layers.find(x => x.id === id).name = name; }),
    move: (id, dir) => change(() => moveLayer(surface, id, dir)),
    add: () => change(() => { S.active = addLayer(S.doc, surface).id; }),
    remove: (id) => change(() => { removeLayer(surface, id); if (S.active === id) S.active = null; }),
  });
}

/** השכבה הפעילה בלוח (בלי ליצור חדשה). */
function targetLayerId(surface) {
  const layers = surface.layers || [];
  const usable = (l) => l.type === 'vector' && !l.locked && l.visible !== false;
  return (layers.find(l => l.id === S.active && usable(l)) || [...layers].reverse().find(usable))?.id || null;
}

function arrangeAction(a, arg) {
  if (!S.sel.size) return;
  let next = null;
  change(() => {
    if (a === 'align') align(S.doc, S.sel, arg);
    if (a === 'distribute') distribute(S.doc, S.sel, arg === 'dh' ? 'h' : 'v');
    if (a === 'order') reorder(S.doc, S.sel, arg);
    if (a === 'group') { const g = group(S.doc, S.sel); if (g) next = [g]; }
    if (a === 'ungroup') next = ungroup(S.doc, S.sel);
    if (a === 'layer') moveToLayer(S.doc, S.sel, arg);
  });
  if (next) select(next);
}
let editBase = null;

/** מטריצה בצורת translate·scale (במרחב הלוח של כל נתיב) על כל הנבחרים. */
function transformSelection(t, sx, sy) {
  const m = [sx, 0, 0, sy, t[4], t[5]];
  for (const id of S.sel) { const o = findPath(S.doc, id); if (o) applyMatrix(o.path, m); }
}

function removeSelection() {
  if (!S.sel.size) return;
  change(() => {
    for (const { layer } of objects(S.doc)) layer.paths = layer.paths.filter(p => !S.sel.has(p.id));
  });
}

function duplicateSelection() {
  if (!S.sel.size) return;
  const fresh = [];
  change(() => {
    const next = ids(S.doc, 'p');
    for (const s of S.doc.surfaces) for (const l of s.layers || []) {
      if (!Array.isArray(l.paths)) continue;
      for (let i = 0; i < l.paths.length; i++) {
        if (!S.sel.has(l.paths[i].id)) continue;
        const c = cloneItem(l.paths[i], next);
        applyMatrix(c, translate(10, 10));
        l.paths.splice(++i, 0, c); // מעל המקור, ומדלגים עליו
        fresh.push(c.id);
      }
    }
  });
  select(fresh);
}

function nudge(dx, dy) {
  if (!S.sel.size) return;
  change(() => transformSelection(translate(dx, dy), 1, 1));
}

// ---------- קלט מצביע ----------

const pointers = new Map();
let op = null;   // הפעולה הנוכחית
let spaceDown = false;

function local(e) {
  const r = canvas.getBoundingClientRect();
  return [e.clientX - r.left, e.clientY - r.top];
}

function handleAt(sx, sy) {
  if (!S.sel.size) return null;
  const box = selectionBox(S.doc, S.sel);
  if (!box) return null;
  const touch = op?.pointerType === 'touch' || matchMedia('(pointer: coarse)').matches;
  const r = (touch ? 22 : HANDLE) / 2 + 2;
  for (const [name, [hx, hy]] of Object.entries(handlePoints(box, S.view))) {
    if (Math.abs(sx - hx) <= r && Math.abs(sy - hy) <= r) return name;
  }
  return null;
}

canvas.addEventListener('pointerdown', (e) => {
  canvas.setPointerCapture(e.pointerId);
  const [sx, sy] = local(e);
  pointers.set(e.pointerId, [sx, sy]);

  if (pointers.size === 2) {           // שתי אצבעות: זום וגלילה, ומבטלים את מה שהתחיל
    if (op && op.before) { S.doc = JSON.parse(op.before); afterChangeSilent(); }
    if (op?.type === 'tool') tools.cancelDrag();
    const [a, b] = [...pointers.values()];
    op = { type: 'pinch', d0: Math.hypot(a[0] - b[0], a[1] - b[1]) || 1, m0: [(a[0] + b[0]) / 2, (a[1] + b[1]) / 2], view0: { ...S.view } };
    S.marquee = null;
    return;
  }
  if (pointers.size > 2) return;

  const touch = e.pointerType === 'touch';
  if (e.button === 1 || spaceDown) { op = { type: 'pan', s0: [sx, sy], view0: { ...S.view } }; return; }
  if (e.button !== 0 && !touch) return;

  let [wx, wy] = toWorld(sx, sy);
  if (S.tool === 'pen' || S.tool === 'node') {
    const r = tools.down(e, sx, sy, wx, wy);
    if (r === 'background' && touch) { op = { type: 'pan', s0: [sx, sy], view0: { ...S.view } }; return; }
    op = r === 'background' ? null : { type: 'tool' };
    return;
  }
  if (S.tool !== 'select') {
    const surface = surfaceAt(wx, wy);
    const [ox, oy] = layout(S.doc).get(surface.id);
    const snap = makeSnap(new Set(), surface, touch);
    if (snap && !(e.ctrlKey || e.metaKey)) [wx, wy] = (({ x, y }) => [x, y])(snap.point(wx, wy));
    const before = JSON.stringify(S.doc);
    const layer = targetLayer(surface);
    const path = { id: nextId(S.doc, 'p'), d: 'M0 0', ...clone(DEFAULT_STYLE) };
    if (S.tool === 'line') path.fill = null;
    layer.paths.push(path);
    op = { type: 'create', id: path.id, before, p0: [wx - ox, wy - oy], off: [ox, oy], s0: [sx, sy], pointerType: e.pointerType, snap };
    setShape(path, shapeFromDrag(S.tool, wx - ox, wy - oy, wx - ox, wy - oy));
    S.sel = new Set([path.id]);
    draw();
    return;
  }

  const handle = handleAt(sx, sy);
  if (handle) {
    op = { type: 'scale', handle, box0: selectionBox(S.doc, S.sel), before: JSON.stringify(S.doc), s0: [sx, sy], snap: makeSnap(S.sel, findPath(S.doc, [...S.sel][0])?.surface, touch) };
    return;
  }
  const hit = hitTest(S, sx, sy, touch ? 12 : 5);
  if (hit) {
    if (e.shiftKey) {
      const next = new Set(S.sel);
      next.has(hit) ? next.delete(hit) : next.add(hit);
      select(next);
      if (!next.has(hit)) return;
    } else if (!S.sel.has(hit)) select([hit]);
    op = { type: 'move', before: JSON.stringify(S.doc), w0: [wx, wy], s0: [sx, sy], moved: false, box0: selectionBox(S.doc, S.sel), snap: makeSnap(S.sel, findPath(S.doc, hit).surface, touch) };
    return;
  }
  if (touch) { op = { type: 'pan', s0: [sx, sy], view0: { ...S.view }, tap: true }; return; }
  op = { type: 'marquee', s0: [sx, sy], add: e.shiftKey ? new Set(S.sel) : new Set() };
});

function afterChangeSilent() { updatePanel(); draw(); }

canvas.addEventListener('pointermove', (e) => {
  const [sx, sy] = local(e);
  if (pointers.has(e.pointerId)) pointers.set(e.pointerId, [sx, sy]);

  if (!op) {
    if (S.tool === 'pen') tools.hover(sx, sy);
    if (e.pointerType === 'mouse' && S.tool === 'select') {
      const h = handleAt(sx, sy);
      canvas.style.cursor = h ? ({ n: 'ns', s: 'ns', e: 'ew', w: 'ew', nw: 'nwse', se: 'nwse', ne: 'nesw', sw: 'nesw' }[h] + '-resize') : (spaceDown ? 'grab' : 'default');
      const hov = h ? null : hitTest(S, sx, sy, 5);
      if (hov !== S.hover) { S.hover = hov; draw(); }
    }
    return;
  }

  switch (op.type) {
    case 'tool': {
      const [wx, wy] = toWorld(sx, sy);
      tools.move(sx, sy, wx, wy);
      break;
    }
    case 'pinch': {
      const [a, b] = [...pointers.values()];
      const d = Math.hypot(a[0] - b[0], a[1] - b[1]);
      const m = [(a[0] + b[0]) / 2, (a[1] + b[1]) / 2];
      const z = Math.max(0.02, Math.min(64, op.view0.z * d / op.d0));
      const wx = (op.m0[0] - op.view0.x) / op.view0.z, wy = (op.m0[1] - op.view0.y) / op.view0.z;
      S.view = { z, x: m[0] - wx * z, y: m[1] - wy * z };
      draw();
      break;
    }
    case 'pan':
      if (Math.hypot(sx - op.s0[0], sy - op.s0[1]) > 4) op.tap = false;
      S.view = { ...op.view0, x: op.view0.x + sx - op.s0[0], y: op.view0.y + sy - op.s0[1] };
      draw();
      break;
    case 'marquee': {
      const x = Math.min(op.s0[0], sx), y = Math.min(op.s0[1], sy);
      S.marquee = [x, y, Math.abs(sx - op.s0[0]), Math.abs(sy - op.s0[1])];
      const [wx, wy] = toWorld(x, y);
      const ids = inRect(S.doc, [wx, wy, S.marquee[2] / S.view.z, S.marquee[3] / S.view.z]);
      S.sel = new Set([...op.add, ...ids]);
      draw();
      break;
    }
    case 'move': {
      if (!op.moved && Math.hypot(sx - op.s0[0], sy - op.s0[1]) < (e.pointerType === 'touch' ? 6 : 3)) return;
      op.moved = true;
      const [wx, wy] = toWorld(sx, sy);
      let dx = wx - op.w0[0], dy = wy - op.w0[1];
      let lock = '';
      if (e.shiftKey) { if (Math.abs(dx) > Math.abs(dy)) { dy = 0; lock = 'y'; } else { dx = 0; lock = 'x'; } }
      S.guides = null;
      if (op.snap && op.box0 && !(e.ctrlKey || e.metaKey)) {
        const [x, y, w, h] = op.box0;
        const r = op.snap.box([x + dx, y + dy, w, h]);
        if (lock !== 'x') dx += r.dx;
        if (lock !== 'y') dy += r.dy;
        S.guides = r.guides.filter(g => g.axis !== lock);
      }
      S.doc = JSON.parse(op.before);
      transformSelection(translate(dx, dy), 1, 1);
      draw();
      break;
    }
    case 'scale': {
      let [wx, wy] = toWorld(sx, sy);
      const [x, y, w, h] = op.box0;
      const hd = op.handle;
      S.guides = null;
      // הצמדה של הקצה שזז (לא ביחס קבוע — שם הקצה נקבע מהציר השני)
      if (op.snap && !(e.ctrlKey || e.metaKey) && !(e.shiftKey && hd.length === 2)) {
        const axes = (/[ew]/.test(hd) ? 'x' : '') + (/[ns]/.test(hd) ? 'y' : '');
        const r = op.snap.point(wx, wy, axes);
        wx = r.x; wy = r.y; S.guides = r.guides;
      }
      const ax = hd.includes('w') ? x + w : hd.includes('e') ? x : x + w / 2;
      const ay = hd.includes('n') ? y + h : hd.includes('s') ? y : y + h / 2;
      let sxF = hd.includes('e') || hd.includes('w') ? (wx - ax) / ((hd.includes('w') ? x : x + w) - ax) : 1;
      let syF = hd.includes('n') || hd.includes('s') ? (wy - ay) / ((hd.includes('n') ? y : y + h) - ay) : 1;
      if (!Number.isFinite(sxF)) sxF = 1;
      if (!Number.isFinite(syF)) syF = 1;
      if (e.shiftKey && hd.length === 2) { const s = Math.max(Math.abs(sxF), Math.abs(syF)); sxF = Math.sign(sxF || 1) * s; syF = Math.sign(syF || 1) * s; }
      // לא מכווצים לאפס: לפחות 0.5pt
      if (Math.abs(sxF * w) < 0.5) sxF = Math.sign(sxF || 1) * 0.5 / (w || 1);
      if (Math.abs(syF * h) < 0.5) syF = Math.sign(syF || 1) * 0.5 / (h || 1);
      S.doc = JSON.parse(op.before);
      const lay = layout(S.doc);
      for (const id of S.sel) {
        const o = findPath(S.doc, id);
        if (!o) continue;
        const [ox, oy] = lay.get(o.surface.id);
        applyMatrix(o.path, scaleAt(sxF, syF, ax - ox, ay - oy));
      }
      draw();
      break;
    }
    case 'create': {
      let [wx, wy] = toWorld(sx, sy);
      S.guides = null;
      if (op.snap && !(e.ctrlKey || e.metaKey) && !e.shiftKey) {
        const r = op.snap.point(wx, wy);
        wx = r.x; wy = r.y; S.guides = r.guides;
      }
      const o = findPath(S.doc, op.id);
      setShape(o.path, shapeFromDrag(S.tool, op.p0[0], op.p0[1], wx - op.off[0], wy - op.off[1], e.shiftKey));
      draw();
      break;
    }
  }
});

function endPointer(e) {
  pointers.delete(e.pointerId);
  S.guides = null;
  if (!op) return;
  if (op.type === 'pinch') {
    if (pointers.size === 0) op = null;
    return; // אצבע אחת שנשארה — לא מתחילה פעולה חדשה
  }
  const [sx, sy] = local(e);
  switch (op.type) {
    case 'tool':
      tools.up();
      updatePanel();
      break;
    case 'pan':
      if (op.tap) select([]);
      break;
    case 'marquee':
      if (!S.marquee) select(op.add.size ? [...op.add] : []);
      S.marquee = null;
      updatePanel();
      break;
    case 'move':
    case 'scale':
      commitFrom(op.before);
      break;
    case 'create': {
      const o = findPath(S.doc, op.id);
      // הקשה בלי גרירה — צורה בגודל ברירת מחדל סביב הנקודה
      if (Math.hypot(sx - op.s0[0], sy - op.s0[1]) < 4) {
        const [x, y] = op.p0;
        const size = 100;
        if (S.tool === 'line') setShape(o.path, { type: 'line', from: [x - size / 2, y], to: [x + size / 2, y] });
        else setShape(o.path, { type: S.tool, box: [x - size / 2, y - size / 2, size, size], ...DEFAULTS[S.tool] });
      }
      S.sel = new Set([op.id]);
      commitFrom(op.before);
      setTool('select');
      break;
    }
  }
  op = null;
  draw();
}
canvas.addEventListener('pointerup', endPointer);
canvas.addEventListener('pointercancel', endPointer);
canvas.addEventListener('pointerleave', () => { if (S.hover && !op) { S.hover = null; draw(); } });

canvas.addEventListener('wheel', (e) => {
  e.preventDefault();
  const [sx, sy] = local(e);
  if (e.ctrlKey || e.metaKey) zoomAt(S.view.z * Math.exp(-e.deltaY * 0.01), sx, sy);
  else { S.view = { ...S.view, x: S.view.x - e.deltaX, y: S.view.y - e.deltaY }; draw(); }
}, { passive: false });

// ספארי: מחוות צביטה של משטח מגע — לא להגדיל את הדף
['gesturestart', 'gesturechange'].forEach(t => document.addEventListener(t, e => e.preventDefault()));

// ---------- מקלדת ----------

const TOOL_KEYS = { v: 'select', a: 'node', p: 'pen', m: 'rect', l: 'ellipse', '\\': 'line' };

document.addEventListener('keydown', (e) => {
  if (e.target.closest('input, textarea, select')) return;
  if (tools.key(e)) { e.preventDefault(); return; }
  const mod = e.ctrlKey || e.metaKey;
  const k = e.key.toLowerCase();
  if (mod && k === 'z') { e.preventDefault(); e.shiftKey ? redo() : undo(); return; }
  if (mod && k === 'y') { e.preventDefault(); redo(); return; }
  if (mod && k === 'a') { e.preventDefault(); select([...objects(S.doc)].filter(o => !o.layer.locked && o.layer.visible !== false).map(o => o.path.id)); return; }
  if (mod && k === 'd') { e.preventDefault(); duplicateSelection(); return; }
  if (mod && k === 'g') { e.preventDefault(); arrangeAction(e.shiftKey ? 'ungroup' : 'group'); return; }
  if (mod && (e.code === 'BracketRight' || e.code === 'BracketLeft')) {
    e.preventDefault();
    const up = e.code === 'BracketRight';
    arrangeAction('order', e.shiftKey ? (up ? 'front' : 'back') : (up ? 'forward' : 'backward'));
    return;
  }
  if (mod && k === '0') { e.preventDefault(); fit(); return; }
  if (mod && (k === '=' || k === '+')) { e.preventDefault(); zoomStep(1); return; }
  if (mod && k === '-') { e.preventDefault(); zoomStep(-1); return; }
  if (mod) return;
  if (k === 'delete' || k === 'backspace') { e.preventDefault(); removeSelection(); return; }
  if (k === 'escape') { select([]); setTool('select'); return; }
  if (e.key === ' ') { spaceDown = true; canvas.style.cursor = 'grab'; e.preventDefault(); return; }
  const step = e.shiftKey ? 10 : 1;
  const arrows = { arrowleft: [-step, 0], arrowright: [step, 0], arrowup: [0, -step], arrowdown: [0, step] };
  if (arrows[k]) { e.preventDefault(); nudge(...arrows[k]); return; }
  if (TOOL_KEYS[e.key]) setTool(TOOL_KEYS[e.key]);
});
document.addEventListener('keyup', (e) => { if (e.key === ' ') { spaceDown = false; canvas.style.cursor = 'default'; } });

// ---------- כפתורים ----------

document.querySelectorAll('.tool').forEach(b => b.addEventListener('click', () => {
  const t = b.dataset.tool;
  if (t !== 'shape') return setTool(t);
  // צורות: לחיצה ראשונה — הצורה האחרונה; כשכבר בצורה — בחירת צורה אחרת
  if (SHAPES.includes(S.tool)) showShapePick($('shape-pick').hidden);
  else setTool(lastShape);
}));
document.querySelectorAll('[data-shape]').forEach(b => b.addEventListener('click', () => setTool(b.dataset.shape)));
document.addEventListener('pointerdown', (e) => { if (!e.target.closest('#shape-pick, [data-tool="shape"]')) showShapePick(false); });
$('pen-done').addEventListener('click', () => tools.finish());
$('pen-cancel').addEventListener('click', () => tools.cancel());
$('node-smooth').addEventListener('click', () => tools.toggleSelected());
$('node-del').addEventListener('click', () => tools.deleteNode());
$('undo').addEventListener('click', undo);
$('redo').addEventListener('click', redo);
$('zoom-in').addEventListener('click', () => zoomStep(1));
for (const k of ['snap', 'grid']) {
  const b = $(k + '-btn');
  b.setAttribute('aria-pressed', String(S[k]));
  b.addEventListener('click', () => { S[k] = !S[k]; setPref(k, S[k]); b.setAttribute('aria-pressed', String(S[k])); draw(); });
}
$('zoom-out').addEventListener('click', () => zoomStep(-1));
$('zoom-pct').addEventListener('click', fit);
document.querySelectorAll('[data-tab]').forEach(b => b.addEventListener('click', () => {
  document.querySelectorAll('[data-tab]').forEach(x => x.setAttribute('aria-selected', String(x === b)));
  $('props-body').hidden = b.dataset.tab !== 'props';
  $('layers-body').hidden = b.dataset.tab !== 'layers';
}));
$('props-btn').addEventListener('click', () => {
  const open = document.body.classList.toggle('props-open');
  $('props-btn').setAttribute('aria-expanded', String(open));
  requestAnimationFrame(resize);
});

new ResizeObserver(resize).observe(stage);
matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => { readColors(); draw(); });

// לבדיקות (Playwright): גישה למצב בלי לחשוף אותו בממשק
window.__vs = { S, select, setTool, fit };

boot();
