// העורך — שלב הקנבס: מסמך מהספרייה או תמונה מהמכשיר, זום והזזה, והוספת תמונה כשכבה.
// הכלים (מכחול, בחירה…) והשמירה — בשלבים הבאים.

import { api, errText, toast, dims, uploadMbe, fetchMbe } from './api.js';
import { idbGet, idbDel } from './idb.js';
import { RasterDocument } from './engine/doc.js';
import { WebGL2Renderer, decodeImage } from './engine/webgl2.js';
import { WebGPURenderer } from './engine/webgpu.js';
import * as V from './engine/view.js';
import { deviceBudget, suggestSize, megapixels, perfFactor, perfLabel, perfCacheValid } from './engine/budget.js';
import { createLayersPanel } from './layers-panel.js';
import { History } from './engine/history.js';
import { createTools } from './tools.js';
import { createOverlay } from './overlay.js';
import { createAdjustPanel } from './adjust-panel.js';
import { createFxPanel } from './fx-panel.js';
import { createAIPanel } from './ai-panel.js';
import { createDrives } from './drives/drives.js';
import { mountAccount, enginePref } from './account-ui.js';
import { exportPNG, exportJPG, exportORA, download, fileName } from './export.js';
import { saveDraft, loadDraft, restoreDraft, clearDraft, draftKey } from './draft.js';
import { gapsFor, parseOraStack } from './engine/formats.js';
import { unzip } from './engine/unzip.js';
import { buildMbe, openMbe, sniffZip, MbeOpenError } from './mbe-io.js';
import { activeHandoff, returnHandoff, endHandoff } from './handoff.js';
import { keyOf } from './ui-core/keys.js';
import { $ } from './ui-core/dom.js';
import { initDialogs, confirm } from './ui-core/dialog.js';
import { bindViewport } from './ui-core/gestures.js';

// חלונות: --vvh / --kb, ✕ ולחיצה ברקע סוגרים — ui-core
initDialogs();

const stage = $('#stage');
let canvas = $('#canvas');
const msg = $('#msg');
const overlay = createOverlay($('#overlay'), { doc: () => doc, view: () => view });

const coarse = matchMedia('(pointer: coarse)').matches;
const mobile = coarse || /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
// מדידת הביצועים — מהמטמון אם יש; אחרת נמדדת אחרי שהמסמך נפתח (פעם אחת למכשיר)
const PERF_KEY = 'raster-studio:perf';
let perfCache = null;
try { perfCache = JSON.parse(localStorage.getItem(PERF_KEY) || 'null'); } catch { /* פרטי / חסום */ }
let budget = deviceBudget({ deviceMemory: navigator.deviceMemory, mobile, perf: perfFactor(perfCache?.score) });

async function measurePerf() {
  const key = renderer.gpuKey() + '|' + navigator.userAgent;
  if (perfCacheValid(perfCache, key)) return;
  const score = await renderer.benchmark(RasterDocument);
  perfCache = { v: 1, key, score, at: Date.now() };
  try { localStorage.setItem(PERF_KEY, JSON.stringify(perfCache)); } catch { /* לא נורא — יימדד שוב */ }
  budget = deviceBudget({ deviceMemory: navigator.deviceMemory, mobile, perf: perfFactor(score) });
  if (history) history.limitBytes = budget.bytes / 4;
  updateMetaTitle();
}

function updateMetaTitle() {
  $('#meta').title = `מנוע: ${renderer?.name ?? '—'} · ביצועים: ${perfLabel(perfCache?.score)} · תמונה עד ~${megapixels(budget.maxPixels(), 1)} מגה-פיקסל`;
}

let renderer = null;
let doc = null;
let panel = null;
let history = null;
let tools = null;
let adjust = null;
let fx = null;
let aiPanel = null;
let view = { zoom: 1, x: 0, y: 0 };
let fitted = false;
let changed = false;   // יש שינוי שעוד לא נכתב לטיוטה במכשיר
let unsaved = false;   // יש שינוי שעוד לא נשמר בשרת

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
    updateMeta();
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
/** מה שמכסה את הבמה: פס הכלים (בצד) ולוח האפשרויות (למעלה) — "התאמה" שמה את המסמך בשטח הפנוי */
function stageInsets() {
  const s = stage.getBoundingClientRect(), out = { l: 0, r: 0, t: 0, b: 0 };
  const tb = $('#tools')?.getBoundingClientRect();
  if (tb?.width) {
    if (tb.left - s.left < s.right - tb.right) out.l = tb.right - s.left; else out.r = s.right - tb.left;
  }
  const ob = $('#tool-opts');
  if (ob && !ob.hidden) { const r = ob.getBoundingClientRect(); if (r.height) out.t = r.bottom - s.top; }
  return out;
}
const fitView = () => doc && V.fit(doc.width, doc.height, stage.clientWidth, stage.clientHeight, 16, stageInsets());
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
  updateMeta();
  $('#open-btn').disabled = false;
  $('#layers-btn').disabled = false;
  $('#adjust-btn').disabled = false;
  $('#ai-btn').disabled = false;
  $('#export-btn').disabled = false;
  $('#save-btn').disabled = false;
  $('#versions-btn').disabled = !d.serverVersion;
  $('#versions-open').hidden = !d.serverVersion;
  panel.render();
  showMsg('');
  if (stage.clientWidth) { fitted = true; fit(); }
  if (d.width * d.height > budget.maxPixels()) {
    toast(`המסמך גדול לזיכרון של המכשיר (${megapixels(d.width, d.height)} מגה-פיקסל) — ייתכן שיהיה איטי`);
  }
}

function updateMeta() {
  if (!doc) return;
  const key = doc.width + 'x' + doc.height;
  if (updateMeta.last === key) return;
  const resized = updateMeta.last != null;
  updateMeta.last = key;
  $('#meta').replaceChildren(dims([doc.width, doc.height]), ' · ' + renderer.name);
  updateMetaTitle();
  if (resized) fit();   // הגודל השתנה (חיתוך / ביטול שלו) — המסמך כולו בתמונה
}

/** מפענח קובץ; אם הוא גדול מתקציב הזיכרון — מציע להקטין (הכרעה: "אפשר להקטין או להמשיך"). */
async function decodeWithBudget(blob) {
  let bmp;
  try { bmp = await decodeImage(blob); } catch { throw new Error('decode'); }
  const s = suggestSize(bmp.width, bmp.height, budget.maxPixels());
  if (s && await confirm(`התמונה גדולה לזיכרון של המכשיר הזה (${megapixels(bmp.width, bmp.height)} מגה-פיקסל). ` +
      `להקטין ל-${s[0]}×${s[1]}?`, { title: 'תמונה גדולה', ok: 'הקטנה', cancel: 'בגודל המלא' })) {
    bmp.close();
    try { bmp = await decodeImage(blob, s); } catch { throw new Error('decode'); }
  }
  return bmp;
}

// ‎?mbe-handoff= נשאר בכתובת: גם אחרי טעינה מחדש העורך יודע שנפתח מאפליקציה אחרת (ו"↩ חזרה" נשאר)
const history_replace = q => {
  const h = new URLSearchParams(location.search).get('mbe-handoff');
  window.history.replaceState(null, '', location.pathname + q + (h ? (q ? '&' : '?') + 'mbe-handoff=' + encodeURIComponent(h) : ''));
};
const baseName = name => (name || '').replace(/\.[a-z0-9]+$/i, '') || 'תמונה';

const isOra = blob => /\.ora$/i.test(blob.name || '') || blob.type === 'image/openraster';

/** ORA (GIMP / Krita / ההורדה שלנו): השכבות עם השם, המיקום, האטימות, המיזוג, ההסתרה והנעילה. */
async function openOra(blob) {
  let files;
  try { files = await unzip(await blob.arrayBuffer()); } catch (e) { throw new Error(e.message === 'zip-unsafe' || e.message === 'zip-too-big' ? e.message : 'decode'); }
  const xml = files.get('stack.xml');
  if (!xml) throw new Error('decode');
  const text = new TextDecoder().decode(xml);
  const st = parseOraStack(text);
  if (!(st.w > 0 && st.h > 0)) throw new Error('decode');
  const d = new RasterDocument(st.w, st.h, baseName(blob.name));
  const walk = async (list, parent) => {
    for (const l of list) {
      const props = { name: l.name, opacity: l.opacity, blend: l.blend, visible: l.visible, locked: l.locked, parent };
      if (l.type === 'group') { await walk(l.children, d.addGroup(props)); continue; }
      const layer = d.addLayer(props);
      const png = files.get(l.src);
      if (!png) continue;
      const bmp = await decodeImage(new Blob([png], { type: 'image/png' }));
      await renderer.drawImage(d, layer, bmp, l.x, l.y);
      bmp.close();
    }
  };
  await walk(st.layers, undefined);
  if (!d.rasters().length) throw new Error('decode');
  setDoc(d);
}

/** ‎.mbe: השכבות, הקבוצות ומצב התצוגה; מה שלא שלנו נשמר לשמירה הבאה */
async function openMbeFile(blob) {
  const { doc: d, view: v, warnings } = await openMbe(renderer, await blob.arrayBuffer(), baseName(blob.name));
  setDoc(d);
  if (v) {
    if (Number.isFinite(v.zoom) && Number.isFinite(v.x) && Number.isFinite(v.y)) setView({ zoom: V.clampZoom(v.zoom), x: v.x, y: v.y });
    if (typeof v.tool === 'string') tools.setTool(v.tool);
    if (typeof v.color === 'string') tools.setColor(v.color);
  }
  const foreign = d.rasters().filter(l => l.foreign).length;
  if (foreign) toast(`${foreign} שכבות של אפליקציות אחרות — נשמרות כמו שהן, ולא מוצגות כאן`);
  else if (warnings.length) toast('נפתח, עם הערות: ' + warnings.map(w => w.message).join(' · '));
}

/** מצב התצוגה — נשמר ב-‎.mbe (apps/raster-studio/view.json) */
const viewState = () => ({ zoom: view.zoom, x: view.x, y: view.y, tool: tools.state.tool, color: tools.state.color });

/** מסמך חדש מקובץ: בגודל התמונה, והתמונה — השכבה היחידה (ORA ו-‎.mbe — עם כל השכבות). */
async function openAsDocument(blob) {
  const zip = await sniffZip(blob);
  if (zip === 'mbe' || (!zip && /\.mbe$/i.test(blob.name || ''))) {
    await openMbeFile(blob);
    history_replace('?draft=local');
    scheduleDraft(0);
    return;
  }
  if (zip === 'ora' || isOra(blob)) {
    await openOra(blob);
    history_replace('?draft=local');
    scheduleDraft(0);
    return;
  }
  const bmp = await decodeWithBudget(blob);
  const d = new RasterDocument(bmp.width, bmp.height, baseName(blob.name));
  const layer = d.addLayer({ name: baseName(blob.name) });
  await renderer.drawImage(d, layer, bmp, 0, 0);
  bmp.close();
  setDoc(d);
  // טעינה מחדש (גם אחרי שהגרפיקה אופסה) — משחזרת מהטיוטה, לא מקובץ שכבר נמחק מהתור
  history_replace('?draft=local');
  scheduleDraft(0);
}

/** תמונה כשכבה חדשה מעל, באמצע הקנבס. מה שגולש מהקנבס נשמר בשכבה (להזזה בהמשך). */
async function addImageLayer(blob) {
  const bmp = await decodeWithBudget(blob);
  const layer = doc.addLayer({ name: baseName(blob.name), index: doc.indexOf(doc.active) + 1 });
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

// ---------- טיוטה במכשיר ----------
let draftTimer = 0, draftBusy = false;
function scheduleDraft(ms = 1500) {
  changed = true;
  unsaved = true;
  clearTimeout(draftTimer);
  draftTimer = setTimeout(flushDraft, ms);
}
async function flushDraft() {
  clearTimeout(draftTimer);
  if (!doc || !renderer || renderer.lost || draftBusy || !changed) return;
  draftBusy = true;
  try {
    tools?.commitFloat();
    await saveDraft(renderer, doc);
    changed = false;
  } catch (e) {
    console.warn('draft', e);
  } finally {
    draftBusy = false;
  }
}
document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') flushDraft(); });

/**
 * המנוע: WebGPU היכן שיש (הכי מתקדם), אחרת WebGL2. ‎?renderer=webgl2 מכריח את הגיבוי.
 * WebGPU נבנה על עותק של הקנבס — קנבס שקיבל הקשר webgpu לא יכול לקבל webgl2, וכך הגיבוי נשאר אפשרי.
 */
async function createRenderer() {
  // ?renderer=webgl2 (בדיקות), או הבחירה בהגדרות האישיות של המכשיר
  const forced = new URLSearchParams(location.search).get('renderer') === 'webgl2' || enginePref() === 'webgl2';
  if (!forced && navigator.gpu) {
    const c = canvas.cloneNode();
    try {
      const r = await WebGPURenderer.create(c);
      canvas.replaceWith(c);
      canvas = c;
      return r;
    } catch (err) { console.warn('WebGPU לא זמין — WebGL2', err); }
  }
  if (!WebGL2Renderer.supported()) throw new Error('no_webgl2');
  // הגרפיקה אופסה וחזרה — הכי בטוח לטעון מחדש מהטיוטה
  canvas.addEventListener('webglcontextrestored', () => location.reload());
  return new WebGL2Renderer(canvas);
}

addEventListener('beforeunload', e => { if (changed) { flushDraft(); e.preventDefault(); } });

// ---------- שמירה בשרת (גרסאות) ----------
// ה-‎.mbe נבנה כאן (אותו קובץ כמו בהורדה), והשרת בודק אותו שוב בעותק הליבה ושומר כגרסה חדשה.
// doc.serverVersion — הגרסה שהעורך התחיל ממנה: אם בינתיים נשמרה אחרת (מכשיר אחר) — שואלים לפני שדורסים.
let saving = false;
let maxUpload = null;
async function saveToServer() {
  if (!doc || saving) return;
  saving = true;
  const btn = $('#save-btn');
  btn.setAttribute('aria-busy', 'true');
  try {
    tools.commitFloat(); tools.commitText(); adjust.close(); fx.close();
    if (!doc.serverId) {
      // מסמך מהמכשיר — נכנס לספרייה בשרת
      const { doc: meta } = await api('doc_create', { title: doc.title || 'תמונה', size: 'custom', px: [doc.width, doc.height] });
      await clearDraft(draftKey(doc));
      doc.serverId = meta.id;
      doc.serverVersion = 0;
      history_replace('?doc=' + meta.id);
    }
    const bytes = await buildMbe(renderer, doc, { view: viewState() });
    // התקרה של השרת (זיכרון ו-post_max_size שלו) — לפני ההעלאה, עם הודעה ברורה ודרך אחרת
    maxUpload ??= (await api('me')).maxUpload ?? Infinity;
    if (bytes.length > maxUpload) {
      const mb = n => (n / 1024 / 1024).toFixed(1);
      toast(`הקובץ (${mb(bytes.length)}MB) גדול מהתקרה של השרת (${mb(maxUpload)}MB) — אפשר להוריד אותו כ-‎.mbe (⬇️)`, true);
      return;
    }
    let r;
    try {
      r = await uploadMbe(doc.serverId, bytes, doc.serverVersion ?? 0);
    } catch (e) {
      if (e.message !== 'conflict') throw e;
      if (!(await confirm('בינתיים נשמרה בשרת גרסה אחרת (אולי ממכשיר אחר). לשמור את שלך כגרסה חדשה מעליה? הקודמת נשארת בגרסאות.',
        { title: 'גרסה אחרת בשרת', ok: 'שמירה כגרסה חדשה' }))) return;
      r = await uploadMbe(doc.serverId, bytes, doc.serverVersion ?? 0, true);
    }
    doc.serverVersion = r.version;
    changed = false;
    unsaved = false;
    clearTimeout(draftTimer);
    await clearDraft(draftKey(doc));   // נשמר — בפתיחה הבאה מהשרת
    $('#versions-btn').disabled = false;
    $('#versions-open').hidden = false;
    toast('נשמר בשרת — גרסה ' + r.version);
  } catch (e) {
    console.error(e);
    toast(errText(e.message) + (e.issues?.length ? ': ' + e.issues.map(i => i.code).join(', ') : ''), true);
  } finally {
    saving = false;
    btn.removeAttribute('aria-busy');
  }
}
$('#save-btn').addEventListener('click', saveToServer);

/** גרסה מהשרת → המסמך בעורך. serverVersion נשאר האחרונה — שמירה ממנה היא גרסה חדשה מעל, לא התנגשות */
async function openServerVersion(meta, v = null) {
  const bytes = await fetchMbe(meta.id, v);
  const { doc: d, view: vs } = await openMbe(renderer, bytes, meta.title);
  d.serverId = meta.id;
  d.serverVersion = meta.version;
  setDoc(d);
  if (vs && Number.isFinite(vs.zoom) && Number.isFinite(vs.x) && Number.isFinite(vs.y)) setView({ zoom: V.clampZoom(vs.zoom), x: vs.x, y: vs.y });
  return d;
}

const versionsDlg = $('#versions-dlg');
const timeFmt = new Intl.DateTimeFormat('he-IL', { dateStyle: 'short', timeStyle: 'short' });
$('#versions-open').addEventListener('click', () => { exportDlg.close(); showVersions(); });
$('#versions-btn').addEventListener('click', () => showVersions());
async function showVersions() {
  if (!doc?.serverId) return;
  const ul = $('#versions-list');
  ul.replaceChildren();
  versionsDlg.showModal();
  try {
    const { versions } = await api('doc_versions', { id: doc.serverId });
    if (!versions.length) { ul.innerHTML = '<li class="hint">עוד לא נשמרה גרסה</li>'; return; }
    for (const v of versions) {
      const li = document.createElement('li');
      li.innerHTML = '<span class="v-tx"><b></b><small></small></span><button type="button" class="btn"></button>';
      li.querySelector('b').textContent = 'גרסה ' + v.n + (v.n === doc.serverVersion ? ' · האחרונה' : '');
      li.querySelector('small').textContent = timeFmt.format(new Date(v.created_at)) + ' · ' + (v.bytes / 1024 / 1024).toFixed(1) + 'MB';
      const b = li.querySelector('button');
      b.textContent = 'פתיחה';
      b.dataset.v = v.n;
      ul.append(li);
    }
  } catch (e) {
    ul.innerHTML = '';
    toast(errText(e.message), true);
  }
}
versionsDlg.addEventListener('click', async e => {
  const b = e.target.closest('[data-v]');
  if (!b) return;
  if (unsaved && !(await confirm('יש שינויים שלא נשמרו בשרת. לפתוח את הגרסה בכל זאת? השינויים יאבדו.', { ok: 'פתיחת הגרסה', danger: true }))) return;
  try {
    const { doc: meta } = await api('doc_get', { id: doc.serverId });
    await openServerVersion(meta, Number(b.dataset.v));
    versionsDlg.close();
    await clearDraft(draftKey(doc));
    changed = unsaved = false;
    toast('נפתחה גרסה ' + b.dataset.v + ' — שמירה ממנה תהיה גרסה חדשה');
  } catch (err) {
    toast(errText(err.message), true);
  }
});

// ---------- הורדה ----------
const exportDlg = $('#export-dlg');
$('#export-btn').addEventListener('click', () => {
  tools.commitFloat(); tools.commitText(); adjust.close(); fx.close();
  const g = ['png', 'ora'].flatMap(k => gapsFor(k, doc.layers).map(x => k.toUpperCase() + ': ' + gapText(x)));
  $('#export-gaps').textContent = g.length ? 'שימו לב — ' + g.join(' · ') : '';
  exportDlg.showModal();
});
const GAP_TEXT = {
  'layers → flattened': 'השכבות מתמזגות לתמונה אחת',
  'hidden-layers → omitted': 'שכבות מוסתרות לא נכנסות',
  'blend-space:linear → srgb': 'GIMP / Krita ממזגים ב-sRGB — שכבות שקופות ומצבי מיזוג עשויים להיראות מעט שונה',
  'alpha → white': 'בלי שקיפות',
};
const gapText = x => {
  const m = /^layer:(.+) → omitted$/.exec(x);
  return GAP_TEXT[x] ?? (m ? `שכבות ${m[1]} של אפליקציה אחרת לא נכנסות (ב-‎.mbe הן נשמרות)` : x);
};
exportDlg.addEventListener('click', async e => {
  const b = e.target.closest('[data-export]');
  if (!b || b.disabled) return;
  const kind = b.dataset.export;
  b.setAttribute('aria-busy', 'true');
  try {
    const blob = kind === 'png' ? await exportPNG(renderer, doc) : kind === 'jpg' ? await exportJPG(renderer, doc)
      : kind === 'mbe' ? new Blob([await buildMbe(renderer, doc, { view: viewState() })], { type: 'application/vnd.mbe+zip' })
      : await exportORA(renderer, doc);
    download(blob, fileName(doc.title, kind));
    exportDlg.close();
    toast('ירד: ' + fileName(doc.title, kind));
  } catch (err) {
    console.error(err);
    toast('ההורדה נכשלה' + (err.issues?.length ? ': ' + err.issues.map(i => i.code).join(', ') : ''), true);
  } finally {
    b.removeAttribute('aria-busy');
  }
});

// ---------- זום והזזה ----------

$('#zoom-in').addEventListener('click', () => setView(V.zoomTo(view, V.nextStep(view.zoom, 1), ...center())));
$('#zoom-out').addEventListener('click', () => setView(V.zoomTo(view, V.nextStep(view.zoom, -1), ...center())));
$('#zoom-lbl').addEventListener('click', () => setView(V.zoomTo(view, 1, ...center())));
$('#fit-btn').addEventListener('click', fit);

// גלגלת, צביטה, הזזה (רווח / גלגלת לחוצה / כלי היד) ולחיצה כפולה — bindViewport של ui-core (נבנה מהקוד שהיה כאן).
// אצבע / עט / עכבר אחד — של הכלי; אצבע שנייה — צביטה, והמשיכה שהתחילה מתבטלת בלי עקבות.
bindViewport(stage, {
  get: () => view,
  set: setView,
  ignore: '.tools, .tool-opts, .adjust, .text-edit',
  hand: () => tools?.state.tool === 'hand',
  pointer: {
    down: (e, pt) => {
      if (!doc) return false;
      // ציור בזמן שתיקון / סגנונות פתוחים — הם נסגרים בלי להחיל (אחרת התצוגה מטעה)
      adjust?.close();
      fx?.close();
      // דלי / טפטפת — לחיצה אחת: רצים כאן, והמצביע לא נשמר לכלי
      return tools.down(e, pt) && tools.stroking;
    },
    move: (e, list) => tools.move(list, e),
    up: () => tools.up(),
    cancel: () => tools.cancel(),
  },
  // לחיצה כפולה בכלי היד: מ"התאמה למסך" ל-100% בנקודה, ובחזרה
  onDoubleClick: (e, p) => {
    if (tools && tools.state.tool !== 'hand') return;
    const f = fitView();
    if (f && Math.abs(view.zoom - f.zoom) < 1e-6) setView(V.zoomTo(view, 1, ...p));
    else fit();
  },
});
// עיגול המכחול — לא חלק מהתצוגה
stage.addEventListener('pointermove', e => tools?.updateCursor(e));
stage.addEventListener('pointerleave', () => tools?.hideCursor());

// ---------- ביטול / ביצוע ----------

const undoBtn = $('#undo-btn'), redoBtn = $('#redo-btn');
function syncHistory(h) {
  // מסמך שרק נפתח (היסטוריה ריקה) — אין מה לשמור; אחרת — טיוטה
  if (doc && (h.canUndo || h.canRedo)) scheduleDraft();
  undoBtn.disabled = !h.canUndo;
  redoBtn.disabled = !h.canRedo;
  undoBtn.title = h.canUndo ? 'ביטול: ' + h.undoStack.at(-1).label : 'ביטול';
  redoBtn.title = h.canRedo ? 'ביצוע מחדש: ' + h.redoStack.at(-1).label : 'ביצוע מחדש';
}
function undo() {
  if (tools?.floating) { tools.cancelFloat(); return; }   // באמצע שינוי צורה — הביטול מבטל אותו
  if (adjust?.open) { adjust.close(); return; }
  if (fx?.open) { fx.close(); return; }
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
  const k = keyOf(e);   // המקש הפיזי: Ctrl+Z עובד גם כשהמקלדת בעברית
  // בתוך שדה טקסט (שם שכבה) — הביטול של השדה עצמו
  const typing = e.target.closest?.('input:not([type=range]), textarea, select');
  if (k === 's') { e.preventDefault(); saveToServer(); return; }
  if ((k === 'z' || k === 'y') && !typing) {
    e.preventDefault();
    if (k === 'y' || e.shiftKey) redo(); else undo();
    return;
  }
  if (k === 'g' && !typing && panel) {
    e.preventDefault();
    tools.commitFloat();
    if (e.shiftKey) panel.act.ungroup(); else panel.act.group();
    draw(); panel.render();
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
    renderer = await createRenderer();
    // ההיסטוריה — עד רבע מתקציב הזיכרון של המכשיר
    history = new History({ renderer, limitBytes: budget.bytes / 4, onChange: syncHistory });
    tools = createTools({
      doc: () => doc, renderer, history, view: () => view, toast, draw, overlay,
      onChange: () => { changed = true; draw(); panel.render(); },
    });
    adjust = createAdjustPanel({
      root: $('#adjust'), doc: () => doc, renderer, history, toast, draw,
      color: () => tools.state.color,
      beforeOpen: () => { tools.commitFloat(); fx.close(); },
      onChange: () => { changed = true; draw(); panel.render(); },
    });
    fx = createFxPanel({
      root: $('#fx'), doc: () => doc, renderer, history, toast, draw,
      beforeOpen: () => { tools.commitFloat(); tools.commitText(); adjust.close(); },
      onChange: () => { changed = true; draw(); panel.render(); },
    });
    $('#adjust-btn').addEventListener('click', () => (adjust.open ? adjust.close() : adjust.show()));
    // ✨ — דרך ענן החבילה (מודול הכוננים): בלי חיבור / ספק — הסבר וחיבור
    const drives = createDrives({ endpoint: 'api.php' });
    // 👤 העיגול בסרגל — כמו בספרייה, ועם שם המנוע שרץ
    api('me').then(({ me }) => mountAccount($('header.top'), { api, me, drives, engine: renderer.name })).catch(() => {});
    aiPanel = createAIPanel({
      doc: () => doc, renderer, history, toast, cloud: drives,
      beforeOpen: () => { tools.commitFloat(); tools.commitText(); adjust.close(); fx.close(); },
      onChange: () => { changed = true; draw(); panel.render(); },
    });
    $('#ai-btn').addEventListener('click', () => aiPanel.show());
    panel = createLayersPanel({
      root: layersEl, doc: () => doc, renderer, history, toast,
      beforeChange: () => { tools.commitFloat(); adjust.close(); fx.close(); },
      onChange: () => { changed = true; draw(); },
      // ✨ / fx בשורה: פותח (או סוגר) את לוח הסגנונות על השכבה הפעילה
      onEffects: () => (fx.open ? fx.close() : fx.show()),
    });
    renderer.onLost = () => showMsg('הגרפיקה של הדפדפן אופסה, והתמונה שבזיכרון אבדה. טענו את הדף מחדש.', true);

    const q = new URLSearchParams(location.search);
    if (q.get('doc')) {
      const { doc: meta } = await api('doc_get', { id: Number(q.get('doc')) });
      let draft = await loadDraft('draft:doc:' + meta.id);
      // בטיוטה יש שינויים שלא נשמרו, ובשרת בינתיים גרסה חדשה יותר (ממכשיר אחר) — המשתמש בוחר
      if (draft && meta.version > (draft.serverVersion ?? 0) &&
          !(await confirm(`בשרת יש גרסה חדשה יותר (גרסה ${meta.version}), ובמכשיר הזה — שינויים שלא נשמרו. עם מה להמשיך?`,
            { title: 'שתי גרסאות', ok: 'השינויים מהמכשיר', cancel: 'הגרסה מהשרת' }))) {
        await clearDraft('draft:doc:' + meta.id);
        draft = null;
      }
      if (draft) {
        const d = await restoreDraft(renderer, draft);
        d.title = meta.title;
        d.serverId = meta.id;
        setDoc(d);
        unsaved = true;   // הטיוטה עוד לא בשרת
        toast('שוחזרה הטיוטה מהמכשיר — ' + new Date(draft.savedAt).toLocaleString('he-IL', { dateStyle: 'short', timeStyle: 'short' }));
      } else if (meta.version) {
        await openServerVersion(meta);
      } else {
        const d = new RasterDocument(meta.size[0], meta.size[1], meta.title);
        d.serverId = meta.id;
        d.serverVersion = 0;
        renderer.fill(d, d.addLayer({ name: 'רקע' }), [255, 255, 255, 255]);
        setDoc(d);
      }
    } else if (q.get('launch') !== null && 'launchQueue' in window) {
      // "פתיחה באמצעות" מהמכשיר (PWA מותקן, file_handlers)
      const file = await new Promise(resolve => {
        launchQueue.setConsumer(async p => resolve(p.files?.[0] ? await p.files[0].getFile() : null));
        setTimeout(() => resolve(null), 3000);
      });
      if (!file) throw new Error('no_file');
      await openAsDocument(file);
    } else if (q.get('draft') === 'local') {
      const draft = await loadDraft('draft:local');
      if (!draft) throw new Error('no_file');
      setDoc(await restoreDraft(renderer, draft));
    } else if (q.get('open') === 'pending') {
      const blob = await idbGet('pending-open');
      await idbDel('pending-open');
      if (!blob) throw new Error('no_file');
      await openAsDocument(blob);
    } else {
      throw new Error('no_file');
    }
    // נפתח מאפליקציה אחרת בחבילה — "↩ חזרה" (גם אחרי טעינה מחדש, כל עוד החלון שפתח אותנו קיים)
    const h = activeHandoff();
    if (h) showHandoff(h);
    // אחרי שהמסמך על המסך — מדידה קצרה בזמן שקט (פעם אחת למכשיר)
    (window.requestIdleCallback || setTimeout)(() => { measurePerf().then(draw, err => console.warn('perf', err)); }, { timeout: 2000 });
  } catch (e) {
    const code = e.message;
    showMsg(code === 'no_file' ? 'לא נבחרה תמונה. חזרו לרשימה ופתחו תמונה או צרו חדשה.' : errText(code) + (e.detail ? ' — ' + e.detail : ''), true);
  }
})();

// ---- נפתח מאפליקציה אחרת (mbe-handoff): "↩ חזרה" מחזיר את ה-‎.mbe אליה ----
function showHandoff(h) {
  const bar = $('#handoff-bar'), btn = $('#handoff-btn');
  $('#handoff-from').textContent = `נפתח מ${h.from.name}`;
  btn.textContent = `↩ חזרה ל${h.from.name}`;
  bar.hidden = false;
  fit();
  btn.onclick = async () => {
    if (!doc) return;
    tools.commitFloat(); tools.commitText(); adjust.close(); fx.close();
    btn.disabled = true;
    try {
      await returnHandoff(h, await buildMbe(renderer, doc, { view: viewState() }));
      endHandoff();
      $('#handoff-from').textContent = `הוחזר ל${h.from.name} ✓`;
      btn.hidden = true;
      toast(`הוחזר ל${h.from.name}`);
      setTimeout(() => window.close(), 600);
    } catch (e) {
      toast(e.message === 'closed' ? `החלון של ${h.from.name} נסגר — אפשר להוריד ‎.mbe (⬇️) ולייבא שם` : `${h.from.name} לא אישר את הקבלה — נסו שוב, או הורידו ‎.mbe (⬇️)`, true);
    } finally {
      btn.disabled = false;
    }
  };
}

// לבדיקות האוטומטיות (tests/browser.mjs) — גישה למנוע ולתצוגה
window.__rs = {
  get doc() { return doc; },
  get renderer() { return renderer; },
  get view() { return view; },
  get panel() { return panel; },
  get history() { return history; },
  get tools() { return tools; },
  get adjust() { return adjust; },
  get fx() { return fx; },
  get ai() { return aiPanel; },
  undo, redo,
  toggleLayers,
  setView,
  fit,
  openAsDocument,
  flushDraft,
  get changed() { return changed; },
  get unsaved() { return unsaved; },
  addImageLayer,
  get budget() { return budget; },
  get perf() { return perfCache; },
  measurePerf,
  buildMbe: opts => buildMbe(renderer, doc, { view: viewState(), ...opts }),
  saveToServer,
};
