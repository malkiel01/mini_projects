// העורך: מצב, קלט (עכבר, מגע, עט, מקלדת), היסטוריה ושמירת טיוטה.
//
// התצוגה: view = { zoom, x, y } (ui-core/viewport.js) — נקודה במרחב העבודה (pt) מגיעה למסך ב-(p·zoom + x, p·zoom + y) פיקסלי CSS.
// מגע: אצבע על רקע — הזזת התצוגה; שתיים — זום וגלילה. עכבר: גרירה על רקע — בחירה במלבן; גלגלת — גלילה,
// ועם Ctrl — זום. רווח + גרירה — הזזת התצוגה.

import { scaleAt, translate, rotateAt } from './geometry/matrix.js';
import { shapeFromDrag, DEFAULTS } from './geometry/shapes.js';
import {
  SIZES, DEFAULT_STYLE, newDocument, nextId, layout, objects, findPath, selectionBox, applyMatrix, setShape, clone,
  leaves, cloneItem, ids, worldBox, pathMatrix,
} from './model.js';
import { group, ungroup, reorder, align, distribute, addLayer, removeLayer, moveLayer, moveToLayer } from './arrange.js';
import { render, hitTest, inRect, handlePoints, readColors, HANDLE, extraPages } from './render.js';
import { renderProps, renderLayers } from './props.js';
import { createPenTools } from './pen.js';
import { pencilD, brushD, widths } from './freehand.js';
import { needsPages, sourcePdf, renderPages, assign as assignPages } from './pages.js';
import { activeHandoff, returnHandoff, endHandoff } from './handoff.js';
import { keyOf } from './ui-core/keys.js';
import { fit as fitView, zoomTo, nextStep, screenToDoc } from './ui-core/viewport.js';
import { bindViewport } from './ui-core/gestures.js';
import { toast } from './ui-core/toast.js';
import { download } from './ui-core/files.js';
import { initDialogs, confirm, prompt } from './ui-core/dialog.js';
import { snapper } from './snap.js';
import { parse, bbox, serialize, transform as transformSegs } from './geometry/path.js';
const pathOps = { parse, serialize, transform: transformSegs };
import { FONTS, TEXT_DEFAULT, fontCss, setMeasure, relayout, lineHeight } from './text.js';
import * as drafts from './drafts.js';
import * as library from './library.js';

const $ = (id) => document.getElementById(id);
const canvas = $('canvas'), stage = $('stage');

const S = {
  id: null, rev: 0, name: 'ציור', doc: null, media: new Map(), pageImgs: null, // pageImgs — עמודי המקור (pages.js)
  view: { x: 0, y: 0, zoom: 1 }, dpr: 1,
  sel: new Set(), hover: null, marquee: null, tool: 'select', active: null, board: null, // board — הלוח הנוכחי (בלי בחירה) guides: null,
  snap: pref('snap', true), grid: pref('grid', false), editingText: null, // active — השכבה לאובייקטים חדשים
  undo: [], redo: [],
};

// העדפות תצוגה (הצמדה, רשת) — נזכרות במכשיר
function pref(k, d) { try { const v = localStorage.getItem('vector-studio:' + k); return v === null ? d : v === '1'; } catch { return d; } }
function setPref(k, v) { try { localStorage.setItem('vector-studio:' + k, v ? '1' : '0'); } catch { /* חסום */ } }

// עיפרון ומברשת: צבע ועובי — נזכרים במכשיר, נפרד לכל כלי
const FREE_DEFAULT = { pencil: { color: '#16192b', width: 2 }, brush: { color: '#2d4a8a', width: 12 } };
function freePrefs() {
  try { return { ...FREE_DEFAULT, ...JSON.parse(localStorage.getItem('vector-studio:free') || '{}') }; } catch { return { ...FREE_DEFAULT }; }
}
function saveFree() { try { localStorage.setItem('vector-studio:free', JSON.stringify(S.free)); } catch { /* חסום */ } }
S.free = freePrefs();

/** הצמדה לגרירה אחת. Ctrl / ⌘ בזמן הגרירה — בלי הצמדה. */
function makeSnap(exclude, surface, touch) {
  if (!S.snap && !S.grid) return null;
  const sf = surface || S.doc.surfaces[0];
  const sn = snapper({ doc: S.doc, exclude, tol: (touch ? 10 : 6) / S.view.zoom, grid: S.grid, origin: layout(S.doc).get(sf.id) });
  if (S.snap) return sn;
  // רשת בלבד — בלי קווים חכמים: מסננים את היעדים
  return snapper({ doc: { surfaces: [] }, exclude, tol: (touch ? 10 : 6) / S.view.zoom, grid: true, origin: layout(S.doc).get(sf.id) });
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
  S.mediaReady = loadMedia();
  library.prefs().then(p => { S.prefs = p; updatePanel(); }).catch(() => { /* בלי חיבור — הפלטה הראשונה */ });
  // גיבוי שנשאר במכשיר משמירה שנכשלה — חדש יותר ממה שבשרת? מציעים לשחזר
  const backup = drafts.loadBackup(S.id);
  showName();
  resize();
  fit();
  updatePanel();
  // הציור כבר על המסך — שואלים מעליו
  if (backup?.doc && backup.rev === S.rev && JSON.stringify(backup.doc) !== JSON.stringify(S.doc)
      && await confirm('יש במכשיר שינויים שלא הגיעו לשרת. לשחזר אותם?', { ok: 'שחזור', cancel: 'לא, מהשרת' })) {
    S.doc = backup.doc; dirty = true; persist(); fit(); updatePanel();
  }
  drafts.removeBackup(S.id);
  // הערות מפתיחת ‎.mbe (בדף הספרייה או בחלון הקובץ) — כאן, אחרי הניווט
  let notes = null;
  try { notes = sessionStorage.getItem('vs-notes'); sessionStorage.removeItem('vs-notes'); } catch { /* חסום */ }
  if (notes) toast('נפתח. שימו לב: ' + notes, { ms: 9000 });
  fontsReady.then(() => { if (!S.rev || S.rev === 1) persist(); }); // תמונה ממוזערת לציור חדש
}

/** תמונות של שכבות מאפליקציות אחרות (רסטר, מסגרות) — מתוך ה-‎.mbe המקורי. תצוגה בלבד. */
async function loadMedia() {
  const srcs = new Set();
  const walk = (layers) => { for (const l of layers || []) { if (l.src) srcs.add(l.src); if (l.children) walk(l.children); } };
  for (const s of S.doc.surfaces) walk(s.layers);
  for (const { path } of objects(S.doc)) for (const p of leaves(path)) if (p.type === 'image' && p.src) srcs.add(p.src);
  const paged = S.container && needsPages(S.doc);
  if (!srcs.size && !paged) return;
  try {
    const { open } = await import('./mbe/mbe.js');
    const f = S.container ? await open(await library.container(S.id)) : null;
    for (const src of srcs) {
      // מה-‎.mbe המקורי, ואם אין שם — תמונה שהועלתה לציור הזה
      const bytes = f?.getMedia(src) || await library.media(src);
      if (!bytes) continue;
      try { S.media.set(src, await createImageBitmap(new Blob([bytes]))); } catch { /* לא תמונה — מסגרת אפורה */ }
    }
    draw();
    // מסמך של אפליקציה אחרת (מעבד תמלילים, עימוד): העמודים כמו שהיא ציירה אותם — מה-PDF שבתוך הקובץ
    const pdf = paged && sourcePdf(f);
    if (pdf) {
      status('טוען את עמודי המקור…');
      S.sourcePdf = pdf;
      S.pageImgs = await renderPages(pdf);
      status('נשמר');
      fit();
      toast(`מסמך של אפליקציה אחרת — ${S.pageImgs.length} עמודים כמו שהיא ציירה אותם. אפשר לצייר מעליהם; את הטקסט עורכים שם.`);
    }
  } catch (e) { toast('התמונות של הקובץ לא נטענו: ' + e.message, true); }
}

/** תמונה מהמכשיר או מהלוח → מועלית לשרת (לפי sha256) ונכנסת כאובייקט באמצע המסך, עד 80% מהלוח. */
async function placeImage(file) {
  if (!/^image\/(png|jpeg|webp)$/.test(file.type)) { toast('רק PNG, JPG או WebP', true); return; }
  if (file.size > 12 * 1024 * 1024) { toast('התמונה גדולה מדי (מעל 12MB)', true); return; }
  status('מעלה תמונה…');
  try {
    const bytes = new Uint8Array(await file.arrayBuffer());
    const [src, bmp] = await Promise.all([library.putMedia(bytes), createImageBitmap(new Blob([bytes]))]);
    S.media.set(src, bmp);
    const r = stage.getBoundingClientRect();
    const [cx, cy] = toWorld(r.width / 2, r.height / 2);
    const surface = surfaceAt(cx, cy);
    const [ox, oy] = layout(S.doc).get(surface.id);
    const k = Math.min(1, surface.size[0] * 0.8 / bmp.width, surface.size[1] * 0.8 / bmp.height);
    const w = Math.round(bmp.width * k * 100) / 100, h = Math.round(bmp.height * k * 100) / 100;
    // באמצע המסך, אבל בתוך הלוח
    const x = Math.max(0, Math.min(surface.size[0] - w, cx - ox - w / 2)), y = Math.max(0, Math.min(surface.size[1] - h, cy - oy - h / 2));
    let id = null;
    change(() => {
      id = nextId(S.doc, 'i');
      targetLayer(surface).paths.push({ id, type: 'image', src, box: [x, y, w, h].map(v => Math.round(v * 100) / 100) });
    });
    setTool('select');
    select([id]);
    status(dirty ? 'לא נשמר…' : 'נשמר');
  } catch (e) {
    status('');
    toast('התמונה לא נכנסה: ' + e.message, true);
  }
}

/** "יחס מקורי": הגובה לפי הרוחב, ביחס של התמונה עצמה. */
function imageRatio(item) {
  const bmp = S.media.get(item.src);
  if (!bmp) return;
  change(() => {
    const o = findPath(S.doc, item.id);
    if (o) o.path.box[3] = Math.round(o.path.box[2] * bmp.height / bmp.width * 100) / 100;
  });
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
  render(c, { doc: { ...S.doc, surfaces: [s] }, view: { x: 0, y: 0, zoom: k }, dpr: 1, sel: new Set(), exportMode: true, bg: true, media: S.media, pages: pagesNow() });
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
  if (tools.drawing) { tools.undoPoint(); return; } // באמצע נתיב — כמו Ctrl+Z בעט
  if (!S.undo.length) return;
  S.redo.push(JSON.stringify(S.doc));
  S.doc = JSON.parse(S.undo.pop());
  afterChange();
  tools.reload();
}
function redo() {
  if (tools.drawing) tools.finish();
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
      ...S, pages: pagesNow(), selBox: S.sel.size && !pathMode && !S.editingText ? selectionBox(S.doc, S.sel) : null, handles: !op || op.type !== 'move',
      overlay: pathMode ? tools.overlay : null,
    });
    $('zoom-pct').textContent = Math.round(S.view.zoom * 100) + '%';
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
/** עמודי המקור מול הלוחות הנוכחיים (לפי הסדר — לוח שנוסף כאן בסוף נשאר בלי רקע). */
const pagesNow = () => (S.pageImgs ? assignPages(S.doc, S.pageImgs) : null);

function fit() {
  const r = stage.getBoundingClientRect();
  const lay = layout(S.doc);
  let x1 = 0, y1 = 0;
  for (const s of S.doc.surfaces) { const [ox, oy] = lay.get(s.id); x1 = Math.max(x1, ox + s.size[0]); y1 = Math.max(y1, oy + s.size[1]); }
  for (const e of extraPages(S.doc, pagesNow())) { x1 = Math.max(x1, e.x + e.page.w); y1 = Math.max(y1, e.page.h); }
  S.view = fitBox(0, 0, x1, y1);
  draw();
}

const FIT_PAD = 32, FIT_MAX = 8;
/** המלבן (ב-pt) באמצע המסך. fit של ui-core עוצר ב-100%; כאן — עד ×8, כדי שאייקון קטן ימלא את המסך. */
function fitBox(x0, y0, w, h) {
  const r = stage.getBoundingClientRect();
  const v = fitView(w, h, r.width, r.height, FIT_PAD);
  const up = Math.min(FIT_MAX, (r.width - FIT_PAD * 2) / w, (r.height - FIT_PAD * 2) / h);
  const z = up > v.zoom ? zoomTo(v, up, r.width / 2, r.height / 2) : v;
  return { ...z, x: z.x - x0 * z.zoom, y: z.y - y0 * z.zoom };
}

const toWorld = (sx, sy) => screenToDoc(S.view, sx, sy);

function zoomAt(z, sx, sy) {
  S.view = zoomTo(S.view, z, sx, sy);
  draw();
}

function zoomStep(dir) {
  const r = stage.getBoundingClientRect();
  zoomAt(nextStep(S.view.zoom, dir), r.width / 2, r.height / 2);
}

// ---------- בחירה וממשק ----------

const SHAPES = ['rect', 'ellipse', 'polygon', 'star', 'line'];
let lastShape = 'rect';
const FREE = ['pencil', 'brush'];
let lastFree = 'pencil';

function setTool(t) {
  S.tool = t;
  if (SHAPES.includes(t)) {
    lastShape = t;
    const src = document.querySelector(`[data-shape="${t}"]`);
    $('shape-icon').innerHTML = src.querySelector('svg').innerHTML;
    document.querySelector('[data-tool="shape"]').setAttribute('aria-label', 'צורות: ' + src.getAttribute('aria-label'));
  }
  if (FREE.includes(t)) {
    lastFree = t;
    const src = document.querySelector(`[data-free="${t}"]`);
    $('free-icon').innerHTML = src.querySelector('svg').innerHTML;
    document.querySelector('[data-tool="draw"]').setAttribute('aria-label', 'ציור חופשי: ' + src.getAttribute('aria-label'));
  }
  document.querySelectorAll('.tool').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.tool === t
    || (b.dataset.tool === 'shape' && SHAPES.includes(t)) || (b.dataset.tool === 'draw' && FREE.includes(t)))));
  canvas.style.cursor = t === 'select' || t === 'node' ? 'default' : t === 'text' ? 'text' : 'crosshair';
  showShapePick(false);
  showFreePick(false);
  tools.activate(t);
  updateBar();
  draw();
}

function showShapePick(open) {
  $('shape-pick').hidden = !open;
  document.querySelector('[data-tool="shape"]').setAttribute('aria-expanded', String(open));
}
function showFreePick(open) {
  $('free-pick').hidden = !open;
  document.querySelector('[data-tool="draw"]').setAttribute('aria-expanded', String(open));
}

/** סרגל צף לעט ולעריכת נקודות — בטלפון אין Enter ו-Delete. */
function updateBar() {
  const drawing = tools.drawing, ed = tools.editing;
  const show = (id, on) => { $(id).hidden = !on; };
  show('pen-done', drawing);
  $('undo').disabled = !S.undo.length && !drawing; // באמצע נתיב ↶ מוחק נקודה
  show('pen-cancel', drawing);
  show('node-smooth', !!ed?.node);
  show('node-del', !!ed?.node);
  const free = FREE.includes(S.tool);
  $('pen-hint').textContent = drawing ? '' : S.tool === 'pen' ? 'הקשה — נקודה, גרירה — עקומה'
    : S.tool === 'node' ? (ed ? (ed.node ? '' : 'גוררים נקודה או ידית; הקשה על הקו — נקודה חדשה') : 'בוחרים צורה לעריכה') : '';
  show('free-opts', free);
  if (free) { $('free-color').value = S.free[S.tool].color; $('free-width').value = S.free[S.tool].width; }
  $('pen-bar').hidden = !(S.tool === 'pen' || S.tool === 'node' || free);
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
    prefs: S.prefs,
    savePrefs: (next) => {
      S.prefs = next; updatePanel();
      library.putPrefs(next).then(p => { S.prefs = p; updatePanel(); }).catch(e => toast('הצבעים לא נשמרו: ' + e.message, true));
    },
    refresh: () => updatePanel(),
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
      : a === 'image-ratio' ? imageRatio(picked[0].path)
      : a === 'copy' ? copySelection() : a === 'paste' ? (clipboardItems() && pasteItems(clipboardItems())) : duplicateSelection(),
    canPaste: !!clipboardItems(),
    fonts: FONTS, relayout,
    // התיבה של נתיב במרחב שלו (בלי היסט הלוח) — לנקודות של מעבר צבע
    pathBox: (p) => (p.box && (p.type === 'text' || p.type === 'image') ? p.box : (() => { try { return bbox(parse(p.d)) || [0, 0, 1, 1]; } catch { return [0, 0, 1, 1]; } })()),
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
async function boardAction(a, id) {
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
    if (S.doc.surfaces.length < 2 || (n && !await confirm(`בלוח ${n} אובייקטים. למחוק אותו יחד איתם?`, { ok: 'מחיקה', danger: true }))) return;
    change(() => { S.doc.surfaces = S.doc.surfaces.filter(x => x.id !== id); });
    if (S.board === id) S.board = null;
    fit();
  }
}

/** התצוגה על לוח אחד — כולו במסך. */
function showBoard(id) {
  const s = S.doc.surfaces.find(x => x.id === id);
  if (!s) return;
  const [ox, oy] = layout(S.doc).get(id);
  S.view = fitBox(ox, oy, s.size[0], s.size[1]);
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

// ---------- קווי מתאר ופעולות צורה ----------

/** הטקסטים שבבחירה → נתיבים (אותו מילוי, מתאר וסיבוב). הצורות כבר קיימות — נשארות. */
async function outlineTexts(ids) {
  const { textToD } = await import('./outline.js');
  await fontsReady;
  const ds = new Map();
  for (const id of ids) {
    const o = findPath(S.doc, id);
    if (!o) continue;
    for (const p of leaves(o.path)) if (p.type === 'text') ds.set(p.id, await textToD(p, getFont));
  }
  return ds;
}

function replaceTexts(ds) {
  const swap = (items) => items.map(it => {
    if (it.type === 'group') return { ...it, children: swap(it.children || []) };
    if (it.type !== 'text' || !ds.has(it.id)) return it;
    const p = { id: it.id, name: it.text.slice(0, 60), d: ds.get(it.id), fill: it.fill || null, stroke: it.stroke || null };
    if (it.transform) p.transform = it.transform;
    return p;
  }).filter(it => it.type === 'group' || it.d !== '');
  for (const s of S.doc.surfaces) for (const l of s.layers || []) if (Array.isArray(l.paths)) l.paths = swap(l.paths);
}

async function outlineSelection() {
  const ds = await outlineTexts([...S.sel]);
  if (!ds.size) return;
  change(() => replaceTexts(ds));
  tools.reload();
  toast('הטקסט הומר לקווי מתאר — אפשר לערוך אותו בעט');
}

/** איחוד / חיסור / חיתוך / הפרדה בין הנבחרים (טקסט הופך קודם לקווי מתאר). חיסור: התחתון פחות כל השאר. */
async function booleanSelection(kind) {
  if (S.sel.size < 2) return;
  const ds = await outlineTexts([...S.sel]);
  const { region, combine, toD } = await import('./geometry/boolean.js');
  let made = null;
  const untouched = JSON.stringify(S.doc);
  change(() => {
    replaceTexts(ds);
    const lay = layout(S.doc);
    const picked = [...objects(S.doc)].filter(o => S.sel.has(o.path.id));
    const reg = new Map(); // מאיזו עקומה באה כל נקודה — כדי שהעקומות יחזרו בתוצאה
    const regions = picked.map(o => combine('union', [...leaves(o.path)].filter(p => p.d).map(p => region(p.d, pathMatrix(p, lay.get(o.surface.id)), reg))));
    const result = toD(combine(kind, regions), reg);
    if (!result) {
      S.doc = JSON.parse(untouched); // גם הטקסט נשאר טקסט — לא קרה כלום
      toast('לא נשאר שום שטח — הצורות לא נחתכות', true);
      return;
    }
    const ref = kind === 'subtract' ? picked[0] : picked[picked.length - 1];
    const styleFrom = [...leaves(ref.path)].find(p => p.d) || {};
    const [ox, oy] = lay.get(ref.surface.id);
    const { parse: P, serialize: Z, transform: T } = pathOps;
    const item = { id: nextId(S.doc, 'p'), d: Z(T(P(result), translate(-ox, -oy))), fill: clone(styleFrom.fill ?? null), stroke: clone(styleFrom.stroke ?? null) };
    if (styleFrom.fillRule) item.fillRule = styleFrom.fillRule;
    const at = ref.layer.paths.indexOf(ref.path);
    ref.layer.paths.splice(at, 0, item);
    for (const o of picked) o.layer.paths = o.layer.paths.filter(p => p !== o.path);
    made = item.id;
  });
  if (made) select([made]);
}

function arrangeAction(a, arg) {
  if (!S.sel.size) return;
  if (a === 'bool') return booleanSelection(arg);
  if (a === 'outline') return outlineSelection();
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
  // תמונה מהלוח (צילום מסך, "העתק תמונה") — נכנסת לציור
  const img = [...(e.clipboardData?.files || [])].find(f => /^image\/(png|jpeg|webp)$/.test(f.type));
  if (img) { e.preventDefault(); placeImage(img); return; }
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

let lastTap = null;
let op = null;   // הפעולה הנוכחית
let spaceDown = false;

function local(e) {
  const r = canvas.getBoundingClientRect();
  return [e.clientX - r.left, e.clientY - r.top];
}

/** דגימות של ציור חופשי — גם הנקודות שהדפדפן איחד לאירוע אחד (קו חלק גם בתנועה מהירה). */
function addSamples(e) {
  const list = e.getCoalescedEvents?.().length ? e.getCoalescedEvents() : [e];
  for (const ev of list) {
    const [wx, wy] = toWorld(...local(ev));
    op.samples.push({ x: wx - op.off[0], y: wy - op.off[1], p: op.pressure ? ev.pressure : null, t: ev.timeStamp });
  }
  const o = findPath(S.doc, op.id);
  if (o) o.path.d = freeD(op, false);
  draw();
}

/** הנתיב של ציור חופשי. בזמן הציור — עיפרון כקו גולמי (מהיר); בסיום — מוחלק. tol: ~1.5 פיקסלי מסך. */
function freeD(o, final) {
  const tol = 1.5 / S.view.zoom;
  if (o.kind === 'brush') {
    const st = S.free.brush;
    return brushD(o.samples, widths(o.samples, st.width, { pressure: o.pressure, scale: 1 / S.view.zoom }), tol);
  }
  if (!final) return o.samples.length > 1 ? 'M' + o.samples.map(p => `${p.x} ${p.y}`).join(' L') : '';
  return pencilD(o.samples, tol, 10 / S.view.zoom);
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
});

/**
 * לחיצה של הכלי (bindViewport קורא לזה רק לאצבע / עכבר / עט יחיד, לא ברווח ולא בגלגלת לחוצה).
 * true — הכלי לקח את המצביע (גם כשהפעולה כבר נעשתה, כמו פתיחת טקסט); false — רקע ריק: מגע מזיז, הקשה מבטלת בחירה.
 */
function toolDown(e) {
  const [sx, sy] = local(e);
  const touch = e.pointerType === 'touch';

  let [wx, wy] = toWorld(sx, sy);
  if (S.tool === 'pen' || S.tool === 'node') {
    const r = tools.down(e, sx, sy, wx, wy);
    op = r === 'background' ? null : { type: 'tool' };
    return !!op;
  }
  if (FREE.includes(S.tool)) {
    const surface = surfaceAt(wx, wy);
    const off = layout(S.doc).get(surface.id);
    const before = JSON.stringify(S.doc);
    const st = S.free[S.tool];
    const path = S.tool === 'brush'
      ? { id: nextId(S.doc, 'p'), d: '', fill: { color: st.color }, stroke: null }
      : { id: nextId(S.doc, 'p'), d: '', fill: null, stroke: { color: st.color, width: st.width, cap: 'round', join: 'round', dash: [] } };
    targetLayer(surface).paths.push(path);
    op = { type: 'free', before, id: path.id, off, kind: S.tool, pressure: e.pointerType === 'pen', samples: [] };
    addSamples(e);
    return true;
  }
  if (S.tool === 'text') {
    const hitT = hitTest(S, sx, sy, touch ? 12 : 5);
    const o = hitT && findPath(S.doc, hitT);
    if (o && o.path.type === 'text') { openTextEditor(hitT); return true; }
    const surface = surfaceAt(wx, wy);
    const [ox, oy] = layout(S.doc).get(surface.id);
    op = { type: 'text', before: JSON.stringify(S.doc), surface, p0: [wx - ox, wy - oy], off: [ox, oy], s0: [sx, sy] };
    return true;
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
    return true;
  }

  const handle = handleAt(sx, sy);
  if (handle === 'rot') {
    const box = selectionBox(S.doc, S.sel);
    const c = [box[0] + box[2] / 2, box[1] + box[3] / 2];
    op = { type: 'rotate', c, a0: Math.atan2(wy - c[1], wx - c[0]), before: JSON.stringify(S.doc), box0: box };
    return true;
  }
  if (handle) {
    op = { type: 'scale', handle, box0: selectionBox(S.doc, S.sel), before: JSON.stringify(S.doc), s0: [sx, sy], snap: makeSnap(S.sel, findPath(S.doc, [...S.sel][0])?.surface, touch) };
    return true;
  }
  const hit = hitTest(S, sx, sy, touch ? 12 : 5);
  // הקשה כפולה על טקסט — עריכה
  const now = performance.now();
  if (hit && lastTap && lastTap.id === hit && now - lastTap.t < 350 && findPath(S.doc, hit)?.path.type === 'text') {
    lastTap = null;
    openTextEditor(hit);
    return true;
  }
  lastTap = hit ? { id: hit, t: now } : null;
  if (hit) {
    if (e.shiftKey) {
      const next = new Set(S.sel);
      next.has(hit) ? next.delete(hit) : next.add(hit);
      select(next);
      if (!next.has(hit)) return true;
    } else if (!S.sel.has(hit)) select([hit]);
    op = { type: 'move', before: JSON.stringify(S.doc), w0: [wx, wy], s0: [sx, sy], moved: false, box0: selectionBox(S.doc, S.sel), snap: makeSnap(S.sel, findPath(S.doc, hit).surface, touch) };
    return true;
  }
  const under = S.doc.surfaces.find(s => { const [ox, oy] = layout(S.doc).get(s.id); return wx >= ox && wx <= ox + s.size[0] && wy >= oy && wy <= oy + s.size[1]; });
  if (under && under.id !== S.board) { S.board = under.id; if (!S.sel.size) updatePanel(); }
  if (touch) return false;   // רקע ריק במגע — הזזה (panOnEmpty), והקשה מבטלת את הבחירה (onTap)
  op = { type: 'marquee', s0: [sx, sy], add: e.shiftKey ? new Set(S.sel) : new Set() };
  return true;
}

function afterChangeSilent() { updatePanel(); draw(); }

// ריחוף (עכבר / עט בלי לחיצה): ידיות, הדגשה, תצוגה מקדימה של העט. בזמן פעולה או הזזה — לא.
canvas.addEventListener('pointermove', (e) => {
  if (op || gestures.panning || e.buttons) return;
  const [sx, sy] = local(e);
  {
    if (S.tool === 'pen') tools.hover(sx, sy);
    if (e.pointerType === 'mouse' && S.tool === 'select') {
      const h = handleAt(sx, sy);
      canvas.style.cursor = h === 'rot' ? 'grab' : h ? ({ n: 'ns', s: 'ns', e: 'ew', w: 'ew', nw: 'nwse', se: 'nwse', ne: 'nesw', sw: 'nesw' }[h] + '-resize') : (spaceDown ? 'grab' : 'default');
      const hov = h ? null : hitTest(S, sx, sy, 5);
      if (hov !== S.hover) { S.hover = hov; draw(); }
    }
  }
});

function toolMove(e) {
  if (!op) return;
  const [sx, sy] = local(e);
  switch (op.type) {
    case 'tool': {
      const [wx, wy] = toWorld(sx, sy);
      tools.move(sx, sy, wx, wy);
      break;
    }
    case 'free':
      addSamples(e);
      break;
    case 'text': {
      const x = Math.min(op.s0[0], sx), y = Math.min(op.s0[1], sy);
      S.marquee = [x, y, Math.abs(sx - op.s0[0]), Math.abs(sy - op.s0[1])];
      draw();
      break;
    }
    case 'marquee': {
      const x = Math.min(op.s0[0], sx), y = Math.min(op.s0[1], sy);
      S.marquee = [x, y, Math.abs(sx - op.s0[0]), Math.abs(sy - op.s0[1])];
      const [wx, wy] = toWorld(x, y);
      const ids = inRect(S.doc, [wx, wy, S.marquee[2] / S.view.zoom, S.marquee[3] / S.view.zoom]);
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
      // טקסט נקודה לבד — תמיד ביחס קבוע (הגופן גדל כולו), כדי שהפינה / הצלע הנגדית יישארו במקום
      const one = S.sel.size === 1 ? findPath(S.doc, [...S.sel][0])?.path : null;
      const pointText = one?.type === 'text' && one.auto !== false;
      if (pointText && hd.length === 1) { const s = hd === 'e' || hd === 'w' ? sxF : syF; sxF = syF = s; }
      if ((e.shiftKey || pointText) && hd.length === 2) { const s = Math.max(Math.abs(sxF), Math.abs(syF)); sxF = Math.sign(sxF || 1) * s; syF = Math.sign(syF || 1) * s; }
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
}

function endPointer(e) {
  S.guides = null;
  if (!op) return;
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
    case 'free': {
      const o = findPath(S.doc, op.id);
      const d = o && freeD(op, true);
      if (d) { o.path.d = d; commitFrom(op.before); } else { S.doc = JSON.parse(op.before); afterChangeSilent(); }
      break;
    }
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
/** אצבע שנייה (צביטה) או pointercancel: הפעולה מתבטלת בלי עקבות. */
function toolCancel() {
  if (op?.before) { S.doc = JSON.parse(op.before); afterChangeSilent(); }
  if (op?.type === 'tool') tools.abortDown();
  op = null;
  S.marquee = null; S.guides = null;
  draw();
}

// זום, הזזה וצביטה — ui-core: גלגלת (Ctrl — זום), רווח / גלגלת לחוצה, שתי אצבעות; מגע על רקע ריק מזיז
const gestures = bindViewport(canvas, {
  get: () => S.view,
  set: (v) => { S.view = v; draw(); },
  pointer: { down: toolDown, move: toolMove, up: endPointer, cancel: toolCancel },
  panOnEmpty: (e) => e.pointerType === 'touch',
  onTap: () => { if (S.tool === 'select') select([]); },
});
canvas.addEventListener('dblclick', (e) => {
  if (S.tool !== 'select') return;
  const [sx, sy] = local(e);
  const hit = hitTest(S, sx, sy, 5);
  if (hit && findPath(S.doc, hit)?.path.type === 'text') openTextEditor(hit);
});
canvas.addEventListener('pointerleave', () => { if (S.hover && !op) { S.hover = null; draw(); } });


// ---------- מקלדת ----------

const TOOL_KEYS = { v: 'select', a: 'node', p: 'pen', t: 'text', m: 'rect', l: 'ellipse', '\\': 'line', n: 'pencil', b: 'brush' };

document.addEventListener('keydown', (e) => {
  if (e.target.closest('input, textarea, select')) return;
  if (tools.key(e)) { e.preventDefault(); return; }
  const mod = e.ctrlKey || e.metaKey;
  const k = keyOf(e); // המקש הפיזי — גם כשהמקלדת בעברית (Ctrl+Z שולח 'ז')
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
  if (TOOL_KEYS[k]) setTool(TOOL_KEYS[k]);
});
document.addEventListener('keyup', (e) => { if (e.key === ' ') { spaceDown = false; canvas.style.cursor = 'default'; } });

// ---------- כפתורים ----------

document.querySelectorAll('.tool').forEach(b => b.addEventListener('click', () => {
  const t = b.dataset.tool;
  if (t === 'draw') {
    // ציור חופשי: לחיצה ראשונה — הכלי האחרון; כשכבר בו — בחירה בין עיפרון למברשת
    if (FREE.includes(S.tool)) showFreePick($('free-pick').hidden); else setTool(lastFree);
    return;
  }
  if (t !== 'shape') return setTool(t);
  // צורות: לחיצה ראשונה — הצורה האחרונה; כשכבר בצורה — בחירת צורה אחרת
  if (SHAPES.includes(S.tool)) showShapePick($('shape-pick').hidden);
  else setTool(lastShape);
}));
document.querySelectorAll('[data-shape]').forEach(b => b.addEventListener('click', () => setTool(b.dataset.shape)));
document.addEventListener('pointerdown', (e) => {
  if (!e.target.closest('#shape-pick, [data-tool="shape"]')) showShapePick(false);
  if (!e.target.closest('#free-pick, [data-tool="draw"]')) showFreePick(false);
});
document.querySelectorAll('[data-free]').forEach(b => b.addEventListener('click', () => setTool(b.dataset.free)));
$('free-color').addEventListener('input', () => { S.free[S.tool].color = $('free-color').value; saveFree(); });
$('free-width').addEventListener('change', () => {
  const v = Number($('free-width').value);
  if (v > 0 && v <= 200) { S.free[S.tool].width = v; saveFree(); } else $('free-width').value = S.free[S.tool].width;
});
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
  const [x, y, w, h] = t.box, z = S.view.zoom;
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
  persist(); // נשמר גם תוך כדי הקלדה — סגירת הלשונית או מעבר לאפליקציה אחרת לא מאבדים טקסט (ההיסטוריה — ב-blur)
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
      const { loadFont } = await import('./outline.js');
      const res = await fetch('assets/fonts/' + file);
      if (!res.ok) throw new Error('הגופן לא נטען: ' + file);
      return loadFont(opentypeLib, await res.arrayBuffer());
    })());
  }
  return fontCache.get(file);
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
  if (!b || !await confirm('לשחזר את הגרסה הזו? הנוכחית תישמר בגרסאות, ואפשר לחזור אליה.', { ok: 'שחזור' })) return;
  b.disabled = true;
  try {
    clearTimeout(saveTimer);
    if (dirty) { await flush(); }
    const d = await library.restore(S.id, Number(b.dataset.ver), S.rev);
    S.doc = JSON.parse(d.doc); S.rev = d.rev; S.undo = []; S.redo = []; S.sel = new Set();
    $('file-dlg').close();
    afterChangeQuiet();
    fit();
    persist(); // התמונה הממוזערת בספרייה — של הגרסה המשוחזרת (השרת מחליף רק את המסמך)
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
  render(c, { doc: { ...S.doc, surfaces: [surface] }, view: { x: 0, y: 0, zoom: k }, dpr: 1, sel: new Set(), exportMode: true, bg, media: S.media, pages: pagesNow() });
  const blob = await new Promise(res => c.toBlob(res, 'image/png'));
  if (!blob) throw new Error('יצירת התמונה נכשלה');
  return new Uint8Array(await blob.arrayBuffer());
}

/** zlib (deflate) — מה ש-FlateDecode ב-PDF קורא. */
async function deflate(bytes) {
  const out = new Response(new Blob([bytes]).stream().pipeThrough(new CompressionStream('deflate')));
  return new Uint8Array(await out.arrayBuffer());
}

/** תמונה של שכבה מאפליקציה אחרת לייצוא: PNG ל-SVG, ופיקסלים דחוסים (צבע + אטימות) ל-PDF. עד 4096 לצלע. */
async function exportImage(src) {
  await S.mediaReady;
  const bmp = S.media.get(src);
  return bmp ? bitmapExport(bmp) : null;
}

/** עמוד המקור של לוח (אם יש) — כתמונה לייצוא. */
async function exportPage(surfaceId) {
  await S.mediaReady;
  const page = pagesNow()?.bySurface.get(surfaceId);
  return page ? bitmapExport(page.bmp) : null;
}

/** העמודים העודפים של המקור — עמודי PDF שכולם תמונה. */
async function extraPdfPages() {
  await S.mediaReady;
  const { pageOnly } = await import('./export.js');
  const out = [];
  for (const e of extraPages(S.doc, pagesNow())) out.push(pageOnly(await bitmapExport(e.page.bmp), e.page.w, e.page.h, e.n));
  return out;
}

const exported = new WeakMap(); // אותה תמונה לא נדחסת פעמיים (עמוד מקור — גם ב-PDF וגם בייצוגים)
async function bitmapExport(bmp) {
  if (!exported.has(bmp)) exported.set(bmp, encodeBitmap(bmp));
  return exported.get(bmp);
}

async function encodeBitmap(bmp) {
  const k = Math.min(1, 4096 / Math.max(bmp.width, bmp.height));
  const w = Math.max(1, Math.round(bmp.width * k)), h = Math.max(1, Math.round(bmp.height * k));
  const c = document.createElement('canvas');
  c.width = w; c.height = h;
  const cx = c.getContext('2d');
  cx.drawImage(bmp, 0, 0, w, h);
  const px = cx.getImageData(0, 0, w, h).data;
  const rgb = new Uint8Array(w * h * 3), alpha = new Uint8Array(w * h);
  let opaque = true;
  for (let i = 0, j = 0; i < w * h; i++, j += 4) {
    rgb[i * 3] = px[j]; rgb[i * 3 + 1] = px[j + 1]; rgb[i * 3 + 2] = px[j + 2];
    alpha[i] = px[j + 3];
    if (px[j + 3] !== 255) opaque = false;
  }
  return { href: c.toDataURL('image/png'), w, h, rgb: await deflate(rgb), alpha: opaque ? null : await deflate(alpha) };
}

/** ה-‎.mbe של הציור כמו שהוא עכשיו (אחרי שמירה) — להורדה ול"↩ חזרה" לאפליקציה שפתחה אותנו. → { bytes, gaps } */
async function currentMbe() {
  clearTimeout(saveTimer);
  if (dirty) await flush();
  await fontsReady;
  const { buildMbe } = await import('./mbefile.js');
  const base = S.container ? await library.container(S.id) : null; // נפתח מ-‎.mbe — שומרים את מה שלא הבנו
  return buildMbe({
    doc: S.doc, base, getFont,
    fontBytes: async (file) => new Uint8Array(await (await fetch('assets/fonts/' + file)).arrayBuffer()),
    preview: (surface, max) => pngOf(surface, Math.min(max / surface.size[0], max / surface.size[1], 4), true),
    getImage: exportImage, getBackground: exportPage, extraPages: await extraPdfPages(), sourcePdf: S.sourcePdf || null,
    mediaBytes: (src) => library.media(src),
  });
}

// ---------- נפתחנו מאפליקציה אחרת (mbe-handoff): "↩ חזרה" שולח לה את ה-‎.mbe ----------

const handoff = activeHandoff();
if (handoff) {
  const b = $('handoff-back');
  b.textContent = '↩ חזרה ל' + handoff.from.name;
  b.title = 'שולח את הציור בחזרה ל' + handoff.from.name;
  b.hidden = false;
  b.addEventListener('click', async () => {
    b.disabled = true;
    status('מחזיר…');
    let r = null;
    try {
      r = await currentMbe();
      await returnHandoff(handoff, r.bytes);
      endHandoff();
      status('הוחזר');
      toast('הציור חזר ל' + handoff.from.name);
      setTimeout(() => window.close(), 400);
    } catch (e) {
      // החלון המקורי נסגר או לא ענה — הקובץ יורד, כדי שאפשר יהיה לייבא אותו שם ידנית
      if (r) download(new Blob([r.bytes], { type: 'application/vnd.mbe+zip' }), fileName('mbe'));
      toast((e.message === 'closed' ? 'החלון של ' + handoff.from.name + ' נסגר' : 'אין תשובה מ' + handoff.from.name)
        + (r ? ' — הקובץ ירד; פתחו אותו שם.' : ': ' + e.message), true);
      status('נשמר');
    } finally {
      b.disabled = false;
    }
  });
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
      const r = await currentMbe();
      gaps = r.gaps;
      download(new Blob([r.bytes], { type: 'application/vnd.mbe+zip' }), fileName('mbe'));
    } else if (kind === 'svg') {
      const prep = await prepare(S.doc, surface.id, getFont, exportImage, await exportPage(surface.id));
      gaps = prep.gaps;
      download(new Blob([toSVG(prep)], { type: 'image/svg+xml' }), fileName('svg', surface));
    } else if (kind === 'pdf') {
      const pages = [];
      for (const s of S.doc.surfaces) { const p = await prepare(S.doc, s.id, getFont, exportImage, await exportPage(s.id)); pages.push(p); gaps.push(...p.gaps); }
      pages.push(...await extraPdfPages());
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
$('doc-name').addEventListener('click', async () => {
  const name = await prompt('שם הציור', { value: S.name });
  if (!name || !name.trim() || name.trim() === S.name) return;
  S.name = name.trim().slice(0, 120);
  showName();
  persist();
});
document.querySelectorAll('[data-export]').forEach(b => b.addEventListener('click', () => doExport(b.dataset.export)));
$('imp-svg').addEventListener('click', () => $('imp-file').click());
$('imp-image').addEventListener('click', () => $('image-file').click());
$('image-file').addEventListener('change', () => {
  const f = $('image-file').files[0];
  $('image-file').value = '';
  if (f) { $('file-dlg').close(); placeImage(f); }
});
$('open-mbe').addEventListener('click', () => $('mbe-file').click());
$('mbe-file').addEventListener('change', async () => {
  const f = $('mbe-file').files[0];
  $('mbe-file').value = '';
  if (!f) return;
  try {
    const { id, notes } = await library.importMbe(f);
    if (notes.length) try { sessionStorage.setItem('vs-notes', notes.join(' · ')); } catch { /* חסום */ }
    location.href = 'editor.html#d' + id;
    location.reload();
  } catch (e) {
    $('file-err').textContent = 'הפתיחה נכשלה: ' + e.message;
    $('file-err').hidden = false;
  }
});
$('imp-file').addEventListener('change', () => { const f = $('imp-file').files[0]; $('imp-file').value = ''; if (f) importFile(f); });
initDialogs();

if ('serviceWorker' in navigator) navigator.serviceWorker.register('service-worker.js').catch(() => { /* בלי מטמון */ });

// לבדיקות (Playwright): גישה למצב בלי לחשוף אותו בממשק
window.__vs = { S, select, setTool, fit, fontsReady };

boot();
