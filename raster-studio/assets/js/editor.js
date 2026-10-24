// העורך — שלב הקנבס: מסמך מהספרייה או תמונה מהמכשיר, זום והזזה, והוספת תמונה כשכבה.
// הכלים (מכחול, בחירה…) והשמירה — בשלבים הבאים.

import { api, errText, toast, dims } from './api.js';
import { idbGet, idbDel } from './idb.js';
import { RasterDocument } from './engine/doc.js';
import { WebGL2Renderer, decodeImage } from './engine/webgl2.js';
import * as V from './engine/view.js';
import { deviceBudget, suggestSize, megapixels } from './engine/budget.js';
import { createLayersPanel } from './layers-panel.js';
import { History } from './engine/history.js';
import { createTools } from './tools.js';
import { createOverlay } from './overlay.js';

const $ = s => document.querySelector(s);
const stage = $('#stage');
const canvas = $('#canvas');
const msg = $('#msg');
const overlay = createOverlay($('#overlay'), { doc: () => doc, view: () => view });

const coarse = matchMedia('(pointer: coarse)').matches;
const mobile = coarse || /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
const budget = deviceBudget({ deviceMemory: navigator.deviceMemory, mobile });

let renderer = null;
let doc = null;
let panel = null;
let history = null;
let tools = null;
let view = { zoom: 1, x: 0, y: 0 };
let fitted = false;
let changed = false;   // יש תוכן שלא נשמר (השמירה — בשלב הבא)

function showMsg(text, error = false) {
  msg.textContent = text;
  msg.classList.toggle('error', error);
  msg.hidden = !text;
}

// ---------- ציור ----------

let raf = 0;
function draw() {
  if (raf) return;
  raf = requestAnimationFrame(() => {
    raf = 0;
    renderer?.render(doc, view, devicePixelRatio || 1);
    overlay.draw();
    const lbl = $('#zoom-lbl');
    lbl.textContent = V.zoomLabel(view.zoom);
    lbl.setAttribute('aria-label', 'זום ' + lbl.textContent + ' — לחיצה: גודל אמיתי (100%)');
  });
}

function setView(v) {
  view = v;
  draw();
  tools?.updateCursor();
}

const center = () => [stage.clientWidth / 2, stage.clientHeight / 2];
const fitView = () => doc && V.fit(doc.width, doc.height, stage.clientWidth, stage.clientHeight);
const fit = () => doc && setView(fitView());

// הקנבס בגודל הבמה, בפיקסלים של המסך
new ResizeObserver(() => {
  const dpr = devicePixelRatio || 1;
  const w = Math.max(1, Math.round(stage.clientWidth * dpr)), h = Math.max(1, Math.round(stage.clientHeight * dpr));
  if (canvas.width !== w || canvas.height !== h) { canvas.width = w; canvas.height = h; }
  if (doc && !fitted) { fitted = true; fit(); } else draw();
}).observe(stage);

// ---------- מסמך ----------

function setDoc(d) {
  doc = d;
  history.clear();
  fitted = false;
  document.title = d.title + ' — עיצוב רסטר';
  $('#title').textContent = d.title;
  $('#meta').replaceChildren(dims([d.width, d.height]), ' · ' + renderer.name);
  $('#open-btn').disabled = false;
  $('#layers-btn').disabled = false;
  panel.render();
  showMsg('');
  if (stage.clientWidth) { fitted = true; fit(); }
  if (d.width * d.height > budget.maxPixels()) {
    toast(`המסמך גדול לזיכרון של המכשיר (${megapixels(d.width, d.height)} מגה-פיקסל) — ייתכן שיהיה איטי`);
  }
}

/** מפענח קובץ; אם הוא גדול מתקציב הזיכרון — מציע להקטין (הכרעה: "אפשר להקטין או להמשיך"). */
async function decodeWithBudget(blob) {
  let bmp;
  try { bmp = await decodeImage(blob); } catch { throw new Error('decode'); }
  const s = suggestSize(bmp.width, bmp.height, budget.maxPixels());
  if (s && confirm(`התמונה גדולה לזיכרון של המכשיר הזה (${megapixels(bmp.width, bmp.height)} מגה-פיקסל).\n` +
      `להקטין ל-${s[0]}×${s[1]}?\n\n"ביטול" — להמשיך בגודל המלא.`)) {
    bmp.close();
    try { bmp = await decodeImage(blob, s); } catch { throw new Error('decode'); }
  }
  return bmp;
}

const baseName = name => (name || '').replace(/\.[a-z0-9]+$/i, '') || 'תמונה';

/** מסמך חדש מקובץ: בגודל התמונה, והתמונה — השכבה היחידה. */
async function openAsDocument(blob) {
  const bmp = await decodeWithBudget(blob);
  const d = new RasterDocument(bmp.width, bmp.height, baseName(blob.name));
  const layer = d.addLayer({ name: baseName(blob.name) });
  await renderer.drawImage(d, layer, bmp, 0, 0);
  bmp.close();
  changed = true;
  setDoc(d);
}

/** תמונה כשכבה חדשה מעל, באמצע הקנבס. מה שגולש מהקנבס נשמר בשכבה (להזזה בהמשך). */
async function addImageLayer(blob) {
  const bmp = await decodeWithBudget(blob);
  const layer = doc.addLayer({ name: baseName(blob.name) });
  await renderer.drawImage(doc, layer, bmp, Math.round((doc.width - bmp.width) / 2), Math.round((doc.height - bmp.height) / 2));
  bmp.close();
  history.addLayer(doc, layer, 'הוספת תמונה');
  changed = true;
  draw();
  panel.render();
  toast('נוספה שכבה: ' + layer.name);
}

$('#open-btn').addEventListener('click', () => $('#file').click());
$('#file').addEventListener('change', async e => {
  const f = e.target.files[0];
  e.target.value = '';
  if (!f || !doc) return;
  try { await addImageLayer(f); } catch (err) { toast(errText(err.message), true); }
});

// ---------- לוח השכבות ----------

const layersEl = $('#layers');
function toggleLayers(open = !layersEl.classList.contains('open')) {
  layersEl.classList.toggle('open', open);
  $('#layers-btn').setAttribute('aria-expanded', String(open));
}
$('#layers-btn').addEventListener('click', () => toggleLayers());
$('.panel-close').addEventListener('click', () => toggleLayers(false));

addEventListener('beforeunload', e => { if (changed) e.preventDefault(); });

// ---------- זום והזזה ----------

$('#zoom-in').addEventListener('click', () => setView(V.zoomTo(view, V.nextStep(view.zoom, 1), ...center())));
$('#zoom-out').addEventListener('click', () => setView(V.zoomTo(view, V.nextStep(view.zoom, -1), ...center())));
$('#zoom-lbl').addEventListener('click', () => setView(V.zoomTo(view, 1, ...center())));
$('#fit-btn').addEventListener('click', fit);

const local = e => {
  const r = stage.getBoundingClientRect();
  return [e.clientX - r.left, e.clientY - r.top];
};

// גלגלת: הזזה; Ctrl / ⌘ + גלגלת (וצביטה במשטח מגע, שמגיעה כ-ctrlKey) — זום סביב הסמן
stage.addEventListener('wheel', e => {
  e.preventDefault();
  const unit = e.deltaMode === 1 ? 16 : e.deltaMode === 2 ? stage.clientHeight : 1;
  if (e.ctrlKey || e.metaKey) {
    setView(V.zoomBy(view, Math.exp(-e.deltaY * unit * 0.01), ...local(e)));
  } else {
    const dx = (e.shiftKey && !e.deltaX ? e.deltaY : e.deltaX) * unit;
    const dy = (e.shiftKey && !e.deltaX ? 0 : e.deltaY) * unit;
    setView({ ...view, x: view.x - dx, y: view.y - dy });
  }
}, { passive: false });

// מצביעים: כלי הציור — אצבע / עט / עכבר אחד. יד, רווח או גלגלת לחוצה — הזזה. שתי אצבעות — צביטה והזזה
// (ואם משיכה כבר התחילה באצבע הראשונה — היא מבוטלת, בלי עקבות).
const pointers = new Map();
let gesture = null;
let painter = null;   // pointerId של המשיכה
let spaceDown = false;

function startGesture() {
  const pts = [...pointers.values()];
  gesture = pts.length >= 2 ? { kind: 'pinch', view, a: pts[0], b: pts[1] }
    : pts.length === 1 ? { kind: 'pan', view, p: pts[0] } : null;
  stage.classList.toggle('panning', !!gesture);
}

const toDoc = p => V.screenToDoc(view, p[0], p[1]);

stage.addEventListener('pointerdown', e => {
  if (!doc) return;
  // הכלים והאפשרויות יושבים מעל הבמה — לחיצה עליהם היא לחיצה על כפתור, לא ציור (ובלי לתפוס את המצביע)
  if (e.target.closest('.tools, .tool-opts')) return;
  if (e.pointerType === 'mouse' && e.button !== 0 && e.button !== 1) return;
  stage.setPointerCapture(e.pointerId);
  const p = local(e);
  // אצבע שנייה בזמן משיכה — זו צביטה: המשיכה מבוטלת
  if (painter !== null && e.pointerType === 'touch') {
    tools.cancel();
    painter = null;
  }
  pointers.set(e.pointerId, p);
  const panOnly = e.button === 1 || spaceDown || pointers.size > 1;
  if (!panOnly && pointers.size === 1 && tools.down(e, toDoc(p))) {
    if (tools.stroking) painter = e.pointerId;
    else pointers.delete(e.pointerId);   // דלי / טפטפת — לחיצה אחת
    return;
  }
  startGesture();
});
stage.addEventListener('pointermove', e => {
  tools?.updateCursor(e);
  if (e.pointerId === painter) {
    const evs = e.getCoalescedEvents?.() || [];
    const list = (evs.length ? evs : [e]).map(ev => {
      const [x, y] = toDoc(local(ev));
      return { x, y, pressure: ev.pressure };
    });
    tools.move(list, e);
    return;
  }
  if (!pointers.has(e.pointerId) || !gesture) return;
  pointers.set(e.pointerId, local(e));
  const pts = [...pointers.values()];
  if (gesture.kind === 'pinch' && pts.length >= 2) {
    setView(V.pinch(gesture.view, gesture.a, gesture.b, pts[0], pts[1]));
  } else if (gesture.kind === 'pan') {
    const [x, y] = pts[0];
    setView({ ...gesture.view, x: gesture.view.x + x - gesture.p[0], y: gesture.view.y + y - gesture.p[1] });
  }
});
const lift = e => {
  if (e.pointerId === painter) {
    painter = null;
    pointers.delete(e.pointerId);
    if (e.type === 'pointercancel') tools.cancel(); else tools.up();
    return;
  }
  if (!pointers.delete(e.pointerId)) return;
  startGesture();   // מצביטה לאצבע אחת — ממשיכים להזיז מהמקום הנוכחי, בלי קפיצה
};
stage.addEventListener('pointerup', lift);
stage.addEventListener('pointercancel', lift);
stage.addEventListener('pointerleave', () => tools?.hideCursor());

// רווח לחוץ — יד זמנית (כמו בכל עורך)
addEventListener('keydown', e => {
  if (e.code === 'Space' && !e.target.closest?.('input, textarea, select, button')) {
    spaceDown = true;
    stage.dataset.tool = 'hand';
    e.preventDefault();
  }
});
addEventListener('keyup', e => {
  if (e.code === 'Space') { spaceDown = false; if (tools) stage.dataset.tool = tools.state.tool; }
});

// לחיצה כפולה: מ"התאמה למסך" ל-100% בנקודה, ובחזרה
stage.addEventListener('dblclick', e => {
  if (tools && tools.state.tool !== 'hand') return;
  const f = fitView();
  if (f && Math.abs(view.zoom - f.zoom) < 1e-6) setView(V.zoomTo(view, 1, ...local(e)));
  else fit();
});

// Safari: צביטה בדף מגדילה את כל הדף — רק המסמך מתקרב
for (const t of ['gesturestart', 'gesturechange']) document.addEventListener(t, e => e.preventDefault());

// ---------- ביטול / ביצוע ----------

const undoBtn = $('#undo-btn'), redoBtn = $('#redo-btn');
function syncHistory(h) {
  undoBtn.disabled = !h.canUndo;
  redoBtn.disabled = !h.canRedo;
  undoBtn.title = h.canUndo ? 'ביטול: ' + h.undoStack.at(-1).label : 'ביטול';
  redoBtn.title = h.canRedo ? 'ביצוע מחדש: ' + h.redoStack.at(-1).label : 'ביצוע מחדש';
}
function undo() {
  if (tools?.floating) { tools.cancelFloat(); return; }   // באמצע שינוי צורה — הביטול מבטל אותו
  if (history?.undo()) { changed = true; draw(); panel.render(); }
}
function redo() {
  tools?.commitFloat();
  if (history?.redo()) { changed = true; draw(); panel.render(); }
}
undoBtn.addEventListener('click', undo);
redoBtn.addEventListener('click', redo);

addEventListener('keydown', e => {
  const typingAny = e.target.closest?.('input:not([type=range]):not([type=color]), textarea, select');
  if (!typingAny && tools?.key(e)) { e.preventDefault(); return; }
  if (!(e.ctrlKey || e.metaKey) || e.altKey) return;
  const k = e.key.toLowerCase();
  // בתוך שדה טקסט (שם שכבה) — הביטול של השדה עצמו
  const typing = e.target.closest?.('input:not([type=range]), textarea, select');
  if ((k === 'z' || k === 'y') && !typing) {
    e.preventDefault();
    if (k === 'y' || e.shiftKey) redo(); else undo();
    return;
  }
  if (k === '=' || k === '+') setView(V.zoomTo(view, V.nextStep(view.zoom, 1), ...center()));
  else if (k === '-') setView(V.zoomTo(view, V.nextStep(view.zoom, -1), ...center()));
  else if (k === '0') fit();
  else if (k === '1') setView(V.zoomTo(view, 1, ...center()));
  else return;
  e.preventDefault();
});

$('#hint').textContent = coarse ? 'צביטה — זום' : 'גרירה — הזזה · Ctrl+גלגלת — זום';

// ---------- התחלה ----------

(async () => {
  try {
    if (!WebGL2Renderer.supported()) throw new Error('no_webgl2');
    renderer = new WebGL2Renderer(canvas);
    // ההיסטוריה — עד רבע מתקציב הזיכרון של המכשיר
    history = new History({ renderer, limitBytes: budget.bytes / 4, onChange: syncHistory });
    tools = createTools({
      doc: () => doc, renderer, history, view: () => view, toast, draw, overlay,
      onChange: () => { changed = true; draw(); panel.render(); },
    });
    panel = createLayersPanel({
      root: layersEl, doc: () => doc, renderer, history, toast,
      beforeChange: () => tools.commitFloat(),
      onChange: () => { changed = true; draw(); },
    });
    renderer.onLost = () => showMsg('הגרפיקה של הדפדפן אופסה, והתמונה שבזיכרון אבדה. טענו את הדף מחדש.', true);

    const q = new URLSearchParams(location.search);
    if (q.get('doc')) {
      const { doc: meta } = await api('doc_get', { id: Number(q.get('doc')) });
      const d = new RasterDocument(meta.size[0], meta.size[1], meta.title);
      d.serverId = meta.id;
      renderer.fill(d, d.addLayer({ name: 'רקע' }), [255, 255, 255, 255]);
      setDoc(d);
    } else if (q.get('open') === 'pending') {
      const blob = await idbGet('pending-open');
      await idbDel('pending-open');
      if (!blob) throw new Error('no_file');
      await openAsDocument(blob);
    } else {
      throw new Error('no_file');
    }
  } catch (e) {
    const code = e.message;
    showMsg(code === 'no_file' ? 'לא נבחרה תמונה. חזרו לרשימה ופתחו תמונה או צרו חדשה.' : errText(code), true);
  }
})();

// לבדיקות האוטומטיות (tests/browser.mjs) — גישה למנוע ולתצוגה
window.__rs = {
  get doc() { return doc; },
  get renderer() { return renderer; },
  get view() { return view; },
  get panel() { return panel; },
  get history() { return history; },
  get tools() { return tools; },
  undo, redo,
  toggleLayers,
  setView,
  fit,
  openAsDocument,
  addImageLayer,
  budget,
};
