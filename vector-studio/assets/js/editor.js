// העורך: מצב, קלט (עכבר, מגע, עט, מקלדת), היסטוריה ושמירת טיוטה.
//
// התצוגה: view = { x, y, z } — נקודה במרחב העבודה (pt) מגיעה למסך ב-(p·z + x, p·z + y) פיקסלי CSS.
// מגע: אצבע על רקע — הזזת התצוגה; שתיים — זום וגלילה. עכבר: גרירה על רקע — בחירה במלבן; גלגלת — גלילה,
// ועם Ctrl — זום. רווח + גרירה — הזזת התצוגה.

import { apply, invert, scaleAt, translate, rotateAt } from './geometry/matrix.js';
import { shapeFromDrag, DEFAULTS } from './geometry/shapes.js';
import {
  SIZES, DEFAULT_STYLE, newDocument, nextId, layout, objects, findPath, selectionBox, applyMatrix, setShape, clone,
  leaves, cloneItem, ids, worldBox,
} from './model.js';
import { group, ungroup, reorder, align, distribute, addLayer, removeLayer, moveLayer, moveToLayer } from './arrange.js';
import { render, hitTest, inRect, handlePoints, readColors, HANDLE, viewMatrix } from './render.js';
import { renderProps, renderLayers } from './props.js';
import { createPenTools } from './pen.js';
import { snapper } from './snap.js';
import { parse, bbox } from './geometry/path.js';
import { FONTS, TEXT_DEFAULT, fontCss, setMeasure, relayout, lineHeight } from './text.js';
import * as drafts from './drafts.js';
import * as library from './library.js';

const $ = (id) => document.getElementById(id);
const canvas = $('canvas'), stage = $('stage');

const S = {
  id: null, rev: 0, name: 'ציור', doc: null, media: new Map(),
  view: { x: 0, y: 0, z: 1 }, dpr: 1,
  sel: new Set(), hover: null, marquee: null, tool: 'select', active: null, board: null, // board — הלוח הנוכחי (בלי בחירה) guides: null,
  snap: pref('snap', true), grid: pref('grid', false), editingText: null, // active — השכבה לאובייקטים חדשים
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

/**
 * כתובות: #new:<גודל> — ציור חדש (נוצר בשרת); #d<מספר> — ציור מהספרייה; כל דבר אחר — טיוטה ישנה מהמכשיר,
 * שעוברת עכשיו לשרת (ונמחקת מהמכשיר רק אחרי שנשמרה שם).
 */
async function boot() {
  const hash = decodeURIComponent(location.hash.slice(1));
  try {
    if (hash.startsWith('new:')) {
      const [, sizeId, w, h] = hash.split(':');
      const preset = SIZES.find(s => s.id === sizeId);
      const size = preset ? preset.size : [Number(w), Number(h)];
      if (!(size[0] > 0 && size[1] > 0)) return leave();
      S.name = 'ציור ' + new Date().toLocaleDateString('he-IL');
      S.doc = newDocument(size);
      const r = await library.create(S.name, S.doc, null);
      S.id = r.id; S.rev = r.rev;
    } else if (/^d\d+$/.test(hash)) {
      const d = await library.get(Number(hash.slice(1)));
      S.id = d.id; S.rev = d.rev; S.name = d.name; S.doc = JSON.parse(d.doc); S.container = d.container;
    } else {
      const d = drafts.load(hash);
      if (!d || !d.doc) return leave();
      S.name = d.name || 'ציור'; S.doc = d.doc;
      const r = await library.create(S.name, S.doc, thumbnail());
      S.id = r.id; S.rev = r.rev;
      drafts.remove(hash);
      toast('הטיוטה מהמכשיר עברה לספרייה בשרת');
    }
  } catch (e) {
    document.body.classList.add('failed');
    $('doc-status').textContent = e.message;
    toast('הציור לא נטען: ' + e.message, true);
    return;
  }
  history.replaceState(null, '', '#d' + S.id);
  if (S.container) loadMedia();
  // גיבוי שנשאר במכשיר משמירה שנכשלה — חדש יותר ממה שבשרת? מציעים לשחזר
  const backup = drafts.loadBackup(S.id);
  if (backup?.doc && backup.rev === S.rev && JSON.stringify(backup.doc) !== JSON.stringify(S.doc)
      && confirm('יש במכשיר שינויים שלא הגיעו לשרת. לשחזר אותם?')) {
    S.doc = backup.doc; dirty = true; persist();
  }
  drafts.removeBackup(S.id);
  showName();
  resize();
  fit();
  updatePanel();
  fontsReady.then(() => { if (!S.rev || S.rev === 1) persist(); }); // תמונה ממוזערת לציור חדש
}

/** תמונות של שכבות מאפליקציות אחרות (רסטר, מסגרות) — מתוך ה-‎.mbe המקורי. תצוגה בלבד. */
async function loadMedia() {
  const srcs = new Set();
  const walk = (layers) => { for (const l of layers || []) { if (l.src) srcs.add(l.src); if (l.children) walk(l.children); } };
  for (const s of S.doc.surfaces) walk(s.layers);
  if (!srcs.size) return;
  try {
    const { open } = await import('./mbe/mbe.js');
    const f = await open(await library.container(S.id));
    for (const src of srcs) {
      const bytes = f.getMedia(src);
      if (!bytes) continue;
      try { S.media.set(src, await createImageBitmap(new Blob([bytes]))); } catch { /* לא תמונה — מסגרת אפורה */ }
    }
    draw();
  } catch (e) { toast('התמונות של הקובץ לא נטענו: ' + e.message, true); }
}

function showName() {
  $('doc-name').textContent = S.name;
  document.title = S.name + ' — וקטורי';
}

function leave() { location.replace('./'); }

// ---------- שמירה בשרת ----------

let saveTimer = 0, saving = false, dirty = false, stopped = false;

/** תמונה ממוזערת של הלוח הראשון, עד 240px, ל-PNG. */
function thumbnail() {
  const s = S.doc.surfaces[0];
  const k = Math.min(240 / s.size[0], 240 / s.size[1], 1);
  const c = document.createElement('canvas');
  c.width = Math.max(1, Math.round(s.size[0] * k)); c.height = Math.max(1, Math.round(s.size[1] * k));
  render(c, { doc: { ...S.doc, surfaces: [s] }, view: { x: 0, y: 0, z: k }, dpr: 1, sel: new Set(), exportMode: true, bg: true, media: S.media });
  return c.toDataURL('image/png');
}

function status(text) { $('doc-status').textContent = text; }

/** שמירה אחרי שקט קצר; שמירה אחת בכל רגע, ואם היו שינויים בזמן השמירה — עוד אחת אחריה. */
function persist() {
  dirty = true;
  if (stopped || !S.id) return;
  clearTimeout(saveTimer);
  status('לא נשמר…');
  saveTimer = setTimeout(flush, 1200);
}

async function flush() {
  if (saving || !dirty || stopped) return;
  saving = true; dirty = false;
  status('שומר…');
  const doc = S.doc;
  try {
    const r = await library.save(S.id, S.rev, { doc, name: S.name, thumb: thumbnail() });
    S.rev = r.rev;
    drafts.removeBackup(S.id);
    status(dirty ? 'לא נשמר…' : 'נשמר');
  } catch (e) {
    if (e.status === 409) {
      stopped = true;
      status('נשמר מחלון אחר — פתחו מחדש');
      toast('הציור נשמר בינתיים מחלון אחר. השינויים כאן לא נשמרו — פתחו אותו מחדש מהספרייה.', true);
    } else {
      dirty = true;
      // בלי חיבור: גיבוי במכשיר, ועוד ניסיון בעוד 15 שניות
      const ok = drafts.saveBackup(S.id, { name: S.name, doc, rev: S.rev });
      status(e.offline ? (ok ? 'אין חיבור — נשמר במכשיר' : 'אין חיבור — לא נשמר') : 'השמירה נכשלה: ' + e.message);
      clearTimeout(saveTimer);
      saveTimer = setTimeout(flush, 15000);
    }
  } finally {
    saving = false;
    if (dirty && !stopped) { clearTimeout(saveTimer); saveTimer = setTimeout(flush, 1200); }
  }
}

window.addEventListener('beforeunload', (e) => { if ((dirty || saving) && !stopped) { flush(); e.preventDefault(); } });
document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') flush(); });

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
    if (!S.doc) return; // עוד נטען מהשרת
    const pathMode = S.tool === 'pen' || S.tool === 'node';
    render(canvas, {
      ...S, selBox: S.sel.size && !pathMode && !S.editingText ? selectionBox(S.doc, S.sel) : null, handles: !op || op.type !== 'move',
      overlay: pathMode ? tools.overlay : null,
    });
    $('zoom-pct').textContent = Math.round(S.view.z * 100) + '%';
    positionTextEditor();
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
  canvas.style.cursor = t === 'select' || t === 'node' ? 'default' : t === 'text' ? 'text' : 'crosshair';
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
  if (!S.doc) return;
  const picked = [...S.sel].map(id => findPath(S.doc, id)).filter(Boolean);
  const surface = picked[0]?.surface || S.doc.surfaces.find(s => s.id === S.board) || S.doc.surfaces[0];
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
    action: (a) => a === 'delete' ? removeSelection() : a === 'edit-text' ? openTextEditor(picked[0].path.id)
      : a === 'copy' ? copySelection() : a === 'paste' ? (clipboardItems() && pasteItems(clipboardItems())) : duplicateSelection(),
    canPaste: !!clipboardItems(),
    fonts: FONTS, relayout,
    // התיבה של נתיב במרחב שלו (בלי היסט הלוח) — לנקודות של מעבר צבע
    pathBox: (p) => (p.type === 'text' ? p.box : (() => { try { return bbox(parse(p.d)) || [0, 0, 1, 1]; } catch { return [0, 0, 1, 1]; } })()),
    arrange: (a, arg) => arrangeAction(a, arg),
    surfaces: S.doc.surfaces,
    boards: (a, id) => boardAction(a, id),
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

/** לוחות: הוספה (באותו גודל, מימין בפריסה), בחירה (והתצוגה עוברת אליו), מחיקה. */
function boardAction(a, id) {
  if (a === 'select') { S.board = id; showBoard(id); updatePanel(); return; }
  if (a === 'add') {
    const cur = S.doc.surfaces.find(s => s.id === S.board) || S.doc.surfaces[S.doc.surfaces.length - 1];
    let made;
    change(() => {
      const sid = nextId(S.doc, 's');
      const lid = ids(S.doc, 'L')();
      made = { id: sid, type: 'artboard', name: 'לוח ' + (S.doc.surfaces.length + 1), size: cur.size.slice(),
        layers: [{ id: lid, name: 'שכבה 1', type: 'vector', opacity: 1, blend: 'normal', visible: true, locked: false, paths: [] }] };
      S.doc.surfaces.push(made);
    });
    S.board = made.id;
    showBoard(made.id);
    updatePanel();
    return;
  }
  if (a === 'remove') {
    const s = S.doc.surfaces.find(x => x.id === id);
    const n = (s.layers || []).reduce((k, l) => k + (l.paths?.length || 0), 0);
    if (S.doc.surfaces.length < 2 || (n && !confirm(`בלוח ${n} אובייקטים. למחוק אותו יחד איתם?`))) return;
    change(() => { S.doc.surfaces = S.doc.surfaces.filter(x => x.id !== id); });
    if (S.board === id) S.board = null;
    fit();
  }
}

/** התצוגה על לוח אחד — כולו במסך. */
function showBoard(id) {
  const s = S.doc.surfaces.find(x => x.id === id);
  if (!s) return;
  const r = stage.getBoundingClientRect();
  const [ox, oy] = layout(S.doc).get(id);
  const pad = 32;
  const z = Math.max(0.02, Math.min((r.width - pad * 2) / s.size[0], (r.height - pad * 2) / s.size[1], 8));
  S.view = { z, x: (r.width - s.size[0] * z) / 2 - ox * z, y: (r.height - s.size[1] * z) / 2 - oy * z };
  draw();
}

/** אחרי הזזה: פריט שהמרכז שלו עבר ללוח אחר — עובר ללוח ההוא (לשכבה הפעילה שבו), באותו מקום על המסך. */
function reparentMoved() {
  const lay = layout(S.doc);
  const inside = (wx, wy) => S.doc.surfaces.find(s => { const [ox, oy] = lay.get(s.id); return wx >= ox && wx <= ox + s.size[0] && wy >= oy && wy <= oy + s.size[1]; });
  for (const id of S.sel) {
    const o = findPath(S.doc, id);
    if (!o) continue;
    const b = worldBox(o.path, lay.get(o.surface.id));
    const to = b && inside(b[0] + b[2] / 2, b[1] + b[3] / 2);
    if (!to || to === o.surface) continue;
    const [ax, ay] = lay.get(o.surface.id), [bx, by] = lay.get(to.id);
    o.layer.paths = o.layer.paths.filter(p => p !== o.path);
    applyMatrix(o.path, translate(ax - bx, ay - by));
    targetLayer(to).paths.push(o.path);
  }
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
    if (a === 'rotate' || a === 'flip') {
      const b = selectionBox(S.doc, S.sel);
      const c = [b[0] + b[2] / 2, b[1] + b[3] / 2];
      if (a === 'rotate') rotateSelection(Number(arg) * Math.PI / 180, c);
      else {
        const lay = layout(S.doc);
        for (const id of S.sel) {
          const o = findPath(S.doc, id);
          const [ox, oy] = lay.get(o.surface.id);
          applyMatrix(o.path, scaleAt(arg === 'h' ? -1 : 1, arg === 'v' ? -1 : 1, c[0] - ox, c[1] - oy));
        }
      }
    }
  });
  if (next) select(next);
}
let editBase = null;

/** מטריצה בצורת translate·scale (במרחב הלוח של כל נתיב) על כל הנבחרים. */
function transformSelection(t, sx, sy) {
  const m = [sx, 0, 0, sy, t[4], t[5]];
  for (const id of S.sel) { const o = findPath(S.doc, id); if (o) applyMatrix(o.path, m); }
}

/** סיבוב הנבחרים סביב נקודה במרחב העבודה (כל אחד במרחב הלוח שלו). */
function rotateSelection(rad, c) {
  const lay = layout(S.doc);
  for (const id of S.sel) {
    const o = findPath(S.doc, id);
    if (!o) continue;
    const [ox, oy] = lay.get(o.surface.id);
    applyMatrix(o.path, rotateAt(rad, c[0] - ox, c[1] - oy));
  }
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

// ---------- העתקה והדבקה ----------
// הלוח של העורך ב-localStorage — עובר גם בין ציורים ובין חלונות. הדבקה של SVG מתוכנה אחרת — דרך אירוע paste.

const CLIP = 'vector-studio:clipboard';
let pasteCount = 0;

function copySelection(cut = false) {
  if (!S.sel.size) return false;
  const items = [...objects(S.doc)].filter(o => S.sel.has(o.path.id)).map(o => clone(o.path));
  try { localStorage.setItem(CLIP, JSON.stringify({ from: S.id, items })); } catch { toast('ההעתקה נכשלה — האחסון במכשיר חסום', true); return false; }
  pasteCount = 0;
  if (cut) removeSelection();
  toast(cut ? `נגזרו ${items.length}` : `הועתקו ${items.length}`);
  updatePanel();
  return true;
}

function clipboardItems() {
  try { const c = JSON.parse(localStorage.getItem(CLIP) || 'null'); return c?.items?.length ? c : null; } catch { return null; }
}

/** הדבקה ללוח הנוכחי, לשכבה הפעילה. מאותו ציור — מוזז ב-10pt בכל הדבקה, כדי לא להסתיר את המקור. */
function pasteItems(clip) {
  const fresh = [];
  const surface = S.doc.surfaces.find(s => s.id === S.board) || findPath(S.doc, [...S.sel][0] || '')?.surface || S.doc.surfaces[0];
  change(() => {
    const next = ids(S.doc, 'p');
    const layer = targetLayer(surface);
    const shift = clip.from === S.id ? 10 * ++pasteCount : 0;
    for (const it of clip.items) {
      const c = cloneItem(it, next);
      if (shift) applyMatrix(c, translate(shift, shift));
      layer.paths.push(c);
      fresh.push(c.id);
    }
  });
  setTool('select');
  select(fresh);
}

document.addEventListener('copy', (e) => { if (!e.target.closest?.('input, textarea') && copySelection()) e.preventDefault(); });
document.addEventListener('cut', (e) => { if (!e.target.closest?.('input, textarea') && copySelection(true)) e.preventDefault(); });
document.addEventListener('paste', async (e) => {
  if (e.target.closest?.('input, textarea') || !S.doc) return;
  const text = e.clipboardData?.getData('text/plain') || '';
  if (/^\s*(<\?xml[^>]*>\s*)?(<!--[\s\S]*?-->\s*)?<svg[\s>]/i.test(text)) {
    e.preventDefault();
    const { importSVG } = await import('./import.js');
    try {
      const r = importSVG(text, ids(S.doc, 'p'));
      if (r.items.length) pasteItems({ from: null, items: r.items });
      if (r.gaps.length) toast('הודבק · לא עבר: ' + r.gaps.join(' · '));
    } catch (err) { toast('ההדבקה נכשלה: ' + err.message, true); }
    return;
  }
  const clip = clipboardItems();
  if (clip) { e.preventDefault(); pasteItems(clip); }
});

function nudge(dx, dy) {
  if (!S.sel.size) return;
  change(() => transformSelection(translate(dx, dy), 1, 1));
}

// ---------- קלט מצביע ----------

const pointers = new Map();
let lastTap = null;
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
  // בלי אירועי עכבר "תואמים" אחרי מגע: ה-mousedown שלהם היה מוציא את הפוקוס משדה הטקסט שנפתח עכשיו
  e.preventDefault();
  // ...ולכן מוציאים את הפוקוס בעצמנו: שדה בלוח המאפיינים נשמר ב-change, ועריכת טקסט נסגרת ב-blur
  if (document.activeElement?.matches('input, select, textarea')) document.activeElement.blur();
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
  if (S.tool === 'text') {
    const hitT = hitTest(S, sx, sy, touch ? 12 : 5);
    const o = hitT && findPath(S.doc, hitT);
    if (o && o.path.type === 'text') { openTextEditor(hitT); return; }
    const surface = surfaceAt(wx, wy);
    const [ox, oy] = layout(S.doc).get(surface.id);
    op = { type: 'text', before: JSON.stringify(S.doc), surface, p0: [wx - ox, wy - oy], off: [ox, oy], s0: [sx, sy] };
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
  if (handle === 'rot') {
    const box = selectionBox(S.doc, S.sel);
    const c = [box[0] + box[2] / 2, box[1] + box[3] / 2];
    op = { type: 'rotate', c, a0: Math.atan2(wy - c[1], wx - c[0]), before: JSON.stringify(S.doc), box0: box };
    return;
  }
  if (handle) {
    op = { type: 'scale', handle, box0: selectionBox(S.doc, S.sel), before: JSON.stringify(S.doc), s0: [sx, sy], snap: makeSnap(S.sel, findPath(S.doc, [...S.sel][0])?.surface, touch) };
    return;
  }
  const hit = hitTest(S, sx, sy, touch ? 12 : 5);
  // הקשה כפולה על טקסט — עריכה
  const now = performance.now();
  if (hit && lastTap && lastTap.id === hit && now - lastTap.t < 350 && findPath(S.doc, hit)?.path.type === 'text') {
    lastTap = null;
    openTextEditor(hit);
    return;
  }
  lastTap = hit ? { id: hit, t: now } : null;
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
  const under = S.doc.surfaces.find(s => { const [ox, oy] = layout(S.doc).get(s.id); return wx >= ox && wx <= ox + s.size[0] && wy >= oy && wy <= oy + s.size[1]; });
  if (under && under.id !== S.board) { S.board = under.id; if (!S.sel.size) updatePanel(); }
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
      canvas.style.cursor = h === 'rot' ? 'grab' : h ? ({ n: 'ns', s: 'ns', e: 'ew', w: 'ew', nw: 'nwse', se: 'nwse', ne: 'nesw', sw: 'nesw' }[h] + '-resize') : (spaceDown ? 'grab' : 'default');
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
    case 'text': {
      const x = Math.min(op.s0[0], sx), y = Math.min(op.s0[1], sy);
      S.marquee = [x, y, Math.abs(sx - op.s0[0]), Math.abs(sy - op.s0[1])];
      draw();
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
    case 'rotate': {
      const [wx, wy] = toWorld(sx, sy);
      let a = Math.atan2(wy - op.c[1], wx - op.c[0]) - op.a0;
      if (e.shiftKey) a = Math.round(a / (Math.PI / 12)) * (Math.PI / 12); // Shift — קפיצות של 15°
      S.doc = JSON.parse(op.before);
      rotateSelection(a, op.c);
      $('doc-status').textContent = `סיבוב ${Math.round(((a * 180 / Math.PI) % 360 + 540) % 360 - 180)}°`;
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
    case 'text': {
      const [wx, wy] = toWorld(sx, sy);
      S.marquee = null;
      const p0 = op.p0, before = op.before, surface = op.surface, off = op.off;
      op = null;
      createText(surface, p0[0], p0[1], wx - off[0], wy - off[1], before);
      setTool('select');
      return;
    }
    case 'pan':
      if (op.tap) select([]);
      break;
    case 'marquee':
      if (!S.marquee) select(op.add.size ? [...op.add] : []);
      S.marquee = null;
      updatePanel();
      break;
    case 'move':
      if (op.moved && S.doc.surfaces.length > 1) reparentMoved();
      commitFrom(op.before);
      break;
    case 'scale':
    case 'rotate':
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
canvas.addEventListener('dblclick', (e) => {
  if (S.tool !== 'select') return;
  const [sx, sy] = local(e);
  const hit = hitTest(S, sx, sy, 5);
  if (hit && findPath(S.doc, hit)?.path.type === 'text') openTextEditor(hit);
});
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

const TOOL_KEYS = { v: 'select', a: 'node', p: 'pen', t: 'text', m: 'rect', l: 'ellipse', '\\': 'line' };

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

// ---------- טקסט ----------

const measureCtx = document.createElement('canvas').getContext('2d');
setMeasure((str, font) => { measureCtx.font = fontCss(font); measureCtx.direction = 'rtl'; return measureCtx.measureText(str).width; });

/** הגופנים מקומיים (assets/fonts). כשגופן נטען — המידות משתנות, אז מחשבים מחדש את כל הטקסטים (בלי היסטוריה). */
const fontsReady = Promise.all(FONTS.flatMap(f => f.files.map(({ file, weight }) => {
  const face = new FontFace(f.family, `url(assets/fonts/${file})`, { weight, display: 'swap' });
  document.fonts.add(face);
  return face.load().catch(() => null);
}))).then(() => {
  for (const { path: item } of objects(S.doc || { surfaces: [] })) for (const p of leaves(item)) if (p.type === 'text') relayout(p);
  updatePanel(); draw();
});

const textEl = $('text-edit');
let textEdit = null; // { id, before }

function positionTextEditor() {
  if (!textEdit) return;
  const o = findPath(S.doc, textEdit.id);
  if (!o) return;
  const t = o.path, [ox, oy] = layout(S.doc).get(o.surface.id);
  const [x, y, w, h] = t.box, z = S.view.z;
  const sx = (x + ox) * z + S.view.x, sy = (y + oy) * z + S.view.y;
  const font = t.font || TEXT_DEFAULT;
  const pad = 40; // מקום לתו הבא לפני שהתיבה מתעדכנת
  // iOS מגדיל את הדף כשמתמקדים בשדה מתחת ל-16px — אז השדה בגודל 16px ומוקטן ב-transform
  const fs = font.size * z, k = Math.min(1, fs / 16), u = z / k;
  const width = t.auto === false ? w * z : w * z + pad;
  const left = t.auto === false || t.align === 'left' ? sx : t.align === 'center' ? sx - pad / 2 : sx - pad;
  Object.assign(textEl.style, {
    font: fontCss({ ...font, size: font.size * u }),
    lineHeight: lineHeight(t) * u + 'px',
    textAlign: t.align === 'left' ? 'left' : t.align === 'center' ? 'center' : 'right',
    whiteSpace: t.auto === false ? 'pre-wrap' : 'pre',
    color: t.fill?.color || '#16192b',
    top: sy + 'px', left: left + 'px',
    width: width / k + 'px', height: Math.max(h, lineHeight(t)) * u + 'px',
    transform: k < 1 ? `scale(${k})` : '', transformOrigin: '0 0',
  });
}

function openTextEditor(id, before) {
  const o = findPath(S.doc, id);
  if (!o || o.path.type !== 'text') return;
  if (textEdit) closeTextEditor();
  textEdit = { id, before: before ?? JSON.stringify(S.doc) };
  S.editingText = id;
  S.sel = new Set([id]);
  textEl.value = o.path.text || '';
  textEl.hidden = false;
  positionTextEditor();
  textEl.focus();
  textEl.select();
  updatePanel(); draw();
}

function closeTextEditor() {
  if (!textEdit) return;
  const { id, before } = textEdit;
  textEdit = null;
  S.editingText = null;
  textEl.hidden = true;
  const o = findPath(S.doc, id);
  if (o && !String(o.path.text || '').trim()) { // טקסט ריק — לא נשאר
    o.layer.paths = o.layer.paths.filter(p => p.id !== id);
    S.sel.delete(id);
  }
  commitFrom(before);
  updatePanel(); draw(); // גם כשלא השתנה דבר (טקסט חדש שנשאר ריק)
}

textEl.addEventListener('input', () => {
  const o = textEdit && findPath(S.doc, textEdit.id);
  if (!o) return;
  o.path.text = textEl.value;
  relayout(o.path);
  positionTextEditor();
  draw();
});
textEl.addEventListener('blur', () => closeTextEditor());
textEl.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' || (e.key === 'Enter' && (e.ctrlKey || e.metaKey))) { e.preventDefault(); textEl.blur(); }
});

/** טקסט חדש: הקשה — טקסט נקודה שהקצה הימני שלו בנקודה; גרירה — טקסט בתיבה. */
function createText(surface, x0, y0, x1, y1, before) {
  const layer = targetLayer(surface);
  const area = Math.abs(x1 - x0) > 8 && Math.abs(y1 - y0) > 8;
  const item = {
    id: nextId(S.doc, 't'), type: 'text', text: '', font: { ...TEXT_DEFAULT }, align: 'right', lineHeight: 1.25, auto: !area,
    box: area ? [Math.min(x0, x1), Math.min(y0, y1), Math.abs(x1 - x0), 0] : [x0, y0 - TEXT_DEFAULT.size * 0.625, 0, 0],
    fill: { color: '#16192b' }, stroke: null,
  };
  layer.paths.push(item);
  relayout(item);
  openTextEditor(item.id, before);
}

// ---------- קובץ: ייצוא וייבוא ----------

let opentypeLib = null;
const fontCache = new Map();
/** גופן כאובייקט opentype — הספרייה והקובץ נטענים רק בייצוא הראשון. */
async function getFont(file) {
  if (!fontCache.has(file)) {
    fontCache.set(file, (async () => {
      opentypeLib ||= await import('./vendor/opentype.min.mjs');
      const res = await fetch('assets/fonts/' + file);
      if (!res.ok) throw new Error('הגופן לא נטען: ' + file);
      return opentypeLib.parse(await res.arrayBuffer());
    })());
  }
  return fontCache.get(file);
}

function toast(msg, err = false) {
  const el = document.querySelector('.toast');
  el.textContent = msg; el.classList.toggle('err', err); el.hidden = false;
  clearTimeout(toast.t); toast.t = setTimeout(() => { el.hidden = true; }, 3500);
}

function download(blob, name) {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = name;
  a.hidden = true;
  // בתוך החלון הפתוח (שכבה עליונה) — אחרת יש דפדפנים שמתעלמים מהשם
  ($('file-dlg').open ? $('file-dlg') : document.body).appendChild(a);
  a.click();
  setTimeout(() => { a.remove(); URL.revokeObjectURL(a.href); }, 30000);
}

const fileName = (ext, surface) => {
  const base = (S.name || 'ציור').replace(/[\\/:*?"<>|]+/g, '-').trim() || 'ציור';
  return (surface && S.doc.surfaces.length > 1 ? `${base} - ${surface.name || surface.id}` : base) + '.' + ext;
};

function openFileDialog() {
  const many = S.doc.surfaces.length > 1;
  $('exp-board-wrap').hidden = !many;
  $('exp-board').innerHTML = S.doc.surfaces.map(s => `<option value="${s.id}">${(s.name || s.id).replace(/[<&]/g, '')}</option>`).join('');
  $('pdf-note').textContent = many ? `(${S.doc.surfaces.length} עמודים)` : '';
  $('exp-note').hidden = ![...objects(S.doc)].some(o => [...leaves(o.path)].some(p => p.type === 'text'));
  $('file-err').hidden = true;
  $('file-dlg').showModal();
  showVersions();
}

async function showVersions() {
  const ul = $('versions');
  ul.innerHTML = '<li class="empty">טוען…</li>';
  try {
    const vs = await library.versions(S.id);
    if (!vs.length) { ul.innerHTML = '<li class="empty">עוד אין גרסאות קודמות.</li>'; return; }
    ul.innerHTML = vs.map(v => `<li class="card"><span class="body"><span class="l1"><span>${new Date(v.saved).toLocaleString('he-IL', { dateStyle: 'short', timeStyle: 'short' })}</span></span>
      <span class="l2">${v.name.replace(/[<&]/g, '')} · שמירה ${v.rev}</span></span>
      <button class="btn sm ghost" type="button" data-ver="${v.id}">שחזור</button></li>`).join('');
  } catch (e) {
    ul.innerHTML = `<li class="empty">${e.message.replace(/[<&]/g, '')}</li>`;
  }
}

$('versions').addEventListener('click', async (e) => {
  const b = e.target.closest('[data-ver]');
  if (!b || !confirm('לשחזר את הגרסה הזו? הנוכחית תישמר בגרסאות, ואפשר לחזור אליה.')) return;
  b.disabled = true;
  try {
    clearTimeout(saveTimer);
    if (dirty) { await flush(); }
    const d = await library.restore(S.id, Number(b.dataset.ver), S.rev);
    S.doc = JSON.parse(d.doc); S.rev = d.rev; S.undo = []; S.redo = []; S.sel = new Set();
    $('file-dlg').close();
    afterChangeQuiet();
    fit();
    toast('הגרסה שוחזרה');
  } catch (err) {
    $('file-err').textContent = 'השחזור נכשל: ' + err.message;
    $('file-err').hidden = false;
    b.disabled = false;
  }
});

/** אחרי שהמסמך הוחלף מבחוץ (שחזור) — רענון בלי שמירה. */
function afterChangeQuiet() {
  $('undo').disabled = true; $('redo').disabled = true;
  status('נשמר');
  tools.reload();
  updatePanel(); draw();
}

/** הלוח כ-PNG בקנה מידה k (בייטים). */
async function pngOf(surface, k, bg) {
  const c = document.createElement('canvas');
  c.width = Math.max(1, Math.round(surface.size[0] * k));
  c.height = Math.max(1, Math.round(surface.size[1] * k));
  render(c, { doc: { ...S.doc, surfaces: [surface] }, view: { x: 0, y: 0, z: k }, dpr: 1, sel: new Set(), exportMode: true, bg, media: S.media });
  const blob = await new Promise(res => c.toBlob(res, 'image/png'));
  if (!blob) throw new Error('יצירת התמונה נכשלה');
  return new Uint8Array(await blob.arrayBuffer());
}

async function doExport(kind) {
  const btn = document.querySelector(`[data-export="${kind}"]`);
  btn.disabled = true;
  $('file-err').hidden = true;
  try {
    const surface = S.doc.surfaces.find(s => s.id === $('exp-board').value) || S.doc.surfaces[0];
    const { prepare, toSVG, toPDF } = await import('./export.js');
    let gaps = [];
    if (kind === 'mbe') {
      clearTimeout(saveTimer);
      if (dirty) await flush();
      await fontsReady;
      const { buildMbe } = await import('./mbefile.js');
      const base = S.container ? await library.container(S.id) : null; // נפתח מ-‎.mbe — שומרים את מה שלא הבנו
      const r = await buildMbe({
        doc: S.doc, base, getFont,
        fontBytes: async (file) => new Uint8Array(await (await fetch('assets/fonts/' + file)).arrayBuffer()),
        preview: (surface, max) => pngOf(surface, Math.min(max / surface.size[0], max / surface.size[1], 4), true),
      });
      gaps = r.gaps;
      download(new Blob([r.bytes], { type: 'application/vnd.mbe+zip' }), fileName('mbe'));
    } else if (kind === 'svg') {
      const prep = await prepare(S.doc, surface.id, getFont);
      gaps = prep.gaps;
      download(new Blob([toSVG(prep)], { type: 'image/svg+xml' }), fileName('svg', surface));
    } else if (kind === 'pdf') {
      const pages = [];
      for (const s of S.doc.surfaces) { const p = await prepare(S.doc, s.id, getFont); pages.push(p); gaps.push(...p.gaps); }
      download(new Blob([toPDF(pages)], { type: 'application/pdf' }), fileName('pdf'));
    } else {
      await fontsReady;
      const scale = Number(document.querySelector('[name="png-scale"]:checked').value);
      // קנבס עד ~16 מיליון פיקסלים — יותר מזה הטלפון נכשל בשקט
      const k = Math.min(scale, Math.sqrt(16e6 / (surface.size[0] * surface.size[1])));
      const png = await pngOf(surface, k, document.querySelector('[name="png-bg"]:checked').value === '1');
      download(new Blob([png], { type: 'image/png' }), fileName('png', surface));
      if (k < scale) gaps.push(`גודל מוקטן ל-×${Math.round(k * 10) / 10} (מגבלת זיכרון)`);
    }
    $('file-dlg').close();
    toast(gaps.length ? `יוצא ${kind.toUpperCase()} · ${[...new Set(gaps)].map(g => g === 'text → outlines' ? 'טקסט → קווי מתאר' : g).join(' · ')}` : `יוצא ${kind.toUpperCase()}`);
  } catch (e) {
    $('file-err').textContent = 'הייצוא נכשל: ' + e.message;
    $('file-err').hidden = false;
  } finally {
    btn.disabled = false;
  }
}

async function importFile(file) {
  $('file-err').hidden = true;
  try {
    if (file.size > 20 * 1024 * 1024) throw new Error('הקובץ גדול מ-20MB');
    const { importSVG } = await import('./import.js');
    const text = await file.text();
    let made = null, gaps = [];
    change(() => {
      const r = importSVG(text, ids(S.doc, 'p'));
      gaps = r.gaps;
      if (!r.items.length) return;
      const surface = findPath(S.doc, [...S.sel][0] || '')?.surface || S.doc.surfaces[0];
      const layer = targetLayer(surface);
      const item = r.items.length === 1 ? r.items[0] : { id: nextId(S.doc, 'g'), type: 'group', name: file.name.replace(/\.svg$/i, ''), children: r.items };
      layer.paths.push(item);
      made = item.id;
    });
    if (!made) throw new Error('לא נמצאו בקובץ צורות שאפשר לייבא' + (gaps.length ? ': ' + gaps.join(' · ') : ''));
    $('file-dlg').close();
    setTool('select');
    select([made]);
    toast(gaps.length ? 'יובא · לא עבר: ' + gaps.join(' · ') : 'יובא');
  } catch (e) {
    $('file-err').textContent = 'הייבוא נכשל: ' + e.message;
    $('file-err').hidden = false;
  }
}

$('file-btn').addEventListener('click', openFileDialog);
// שינוי שם: הקשה על הכותרת
$('doc-name').addEventListener('keydown', (e) => { if (e.key === 'Enter') $('doc-name').click(); });
$('doc-name').addEventListener('click', () => {
  const name = prompt('שם הציור', S.name);
  if (!name || !name.trim() || name.trim() === S.name) return;
  S.name = name.trim().slice(0, 120);
  showName();
  persist();
});
document.querySelectorAll('[data-export]').forEach(b => b.addEventListener('click', () => doExport(b.dataset.export)));
$('imp-svg').addEventListener('click', () => $('imp-file').click());
$('open-mbe').addEventListener('click', () => $('mbe-file').click());
$('mbe-file').addEventListener('change', async () => {
  const f = $('mbe-file').files[0];
  $('mbe-file').value = '';
  if (!f) return;
  try {
    const { id, notes } = await library.importMbe(f);
    if (notes.length) alert('נפתח. שימו לב:\n' + notes.join('\n'));
    location.href = 'editor.html#d' + id;
    location.reload();
  } catch (e) {
    $('file-err').textContent = 'הפתיחה נכשלה: ' + e.message;
    $('file-err').hidden = false;
  }
});
$('imp-file').addEventListener('change', () => { const f = $('imp-file').files[0]; $('imp-file').value = ''; if (f) importFile(f); });
document.addEventListener('click', e => { const b = e.target.closest('[data-close]'); if (b) b.closest('dialog').close(); });

// לבדיקות (Playwright): גישה למצב בלי לחשוף אותו בממשק
window.__vs = { S, select, setTool, fit, fontsReady };

boot();
