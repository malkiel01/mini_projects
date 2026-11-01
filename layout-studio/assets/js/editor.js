// עורך המסמך: תצוגה, זום, דפדוף; הגדרות (גודל, שוליים, עמודים כפולים, מספר עמודים, מספור, עמוד אב, טקסט);
// הקשה על עמוד — בחירה ("עמוד N": עמוד אב, מסגרת טקסט, זרימה אוטומטית); הקשה על מסגרת — "מסגרת" (טקסט, טורים, גודל).
// הטקסט נפרס מחדש בכל שינוי (flow.js); שטף עם זרימה אוטומטית שלא נכנס — מוסיף עמודים.
// כל שינוי נשמר לשרת אחרי רגע (doc_save עם rev — שמירה מלשונית ישנה נדחית במקום לדרוס).

import { zoomLabel } from './ui-core/viewport.js';
import { PageView } from './view.js';
import { pagesOf, pageSize, setPageCount, setPageSize, marginsFit, mmToPt, ptToMm,
  mastersOf, masterOf, setMaster, updateMaster, ensureMasters, spreadOfPage, contentBox } from './pages.js';
import { addFrame, autoflow, growFlow, removeFrame, pruneFlows, updateFrame, setFlow, flowText, thread,
  allFrames, allItems, drawingsOnPage, bodyStyle, styleOf, setStyle, STYLE_KEYS, FONTS, addImageFrame, isImage } from './frames.js';
import { placeDrawing, moveDrawing, isDrawing, drawItems, imageRefs, drawingToDoc, pruneSymbols } from './vector.js';
import { openIn } from './mbe/handoff.js';
import { layoutFlows } from './flow.js';
import { imagePlacement } from './frames.js';
import { loadFont, fontVariant } from './shaper.js';
import { drawList } from './pdf.js';
import { toast } from './ui-core/toast.js';
import { initDialogs, confirm } from './ui-core/dialog.js';
import { download as saveFile } from './ui-core/files.js';
import { shortcuts } from './ui-core/keys.js';
import { mountAccount } from './account-ui.js';

const $ = (s, el = document) => el.querySelector(s);

const SIZES = { a4: [210, 297], a5: [148, 210], b5: [176, 250], '17x24': [170, 240] };
const ERRORS = {
  network: 'אין חיבור לשרת', not_found: 'המסמך לא נמצא', no_user: 'אין משתמש — צריך להיכנס דרך הכלים הדיגיטליים',
  conflict: 'המסמך נשמר בינתיים ממקום אחר', too_big: 'המסמך גדול מדי',
};
const errText = c => ERRORS[c] || (c.startsWith('bad_model') ? 'השרת דחה את המסמך (' + c + ')' : 'משהו השתבש (' + c + ')');

async function api(action, data = {}) {
  let res;
  try {
    res = await fetch('api.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Layout-Studio': '1' },
      body: JSON.stringify({ action, ...data }),
    });
  } catch { throw new Error('network'); }
  const out = await res.json().catch(() => ({ ok: false, error: 'server' }));
  if (!out.ok) throw new Error(out.error || 'server');
  return out;
}

initDialogs();   // [data-close], סגירה ברקע, --vvh / --kb (המקלדת בטלפון)

const newId = (prefix = 'p') => prefix + Array.from(crypto.getRandomValues(new Uint8Array(6)), b => b.toString(16).padStart(2, '0')).join('');

// ---- מצב ----
const id = Number(new URLSearchParams(location.search).get('id'));
let doc = null, model = null, rev = 0;
const view = new PageView($('#stage'), { onChange: updateBar, onPick: updatePageBtn });

// ---- שמירה ----
const saveState = $('#save-state');
let dirty = false, saving = false, saveTimer = 0, blocked = false;

function setState(text) { saveState.textContent = text; }

// ---- הטקסט: גופנים, פריסה וזרימה אוטומטית ----
const loaded = {};   // שם גופן → גופן טעון
let fontsReady = null;
function ensureFonts() {
  return (fontsReady ||= Promise.all(Object.entries(FONTS).map(async ([name, file]) => {
    loaded[name] = await loadFont('assets/fonts/' + file, name);
  })));
}
// גופן במשקל (wght — הגופנים משתנים, אז מודגש בא מאותו קובץ), במטמון
const variants = new Map();
const weighted = (f, weight) => {
  if (!weight || weight === 400) return f;
  const k = f.name + '@' + weight;
  if (!variants.has(k)) variants.set(k, fontVariant(f, { wght: weight }));
  return variants.get(k);
};
// הגופן של הסגנון, ו-Noto כחלופי (טעמים שאין ב-Frank Ruhl Libre) — באותו משקל
const fontsFor = (family, weight = 400) => {
  const noto = weighted(loaded['Noto Serif Hebrew'], weight);
  const main = loaded[family] && family !== 'Noto Serif Hebrew' ? weighted(loaded[family], weight) : noto;
  return main === noto ? [noto] : [main, noto];
};
let text = null;

function relayout() {
  if (!loaded['Noto Serif Hebrew']) return;
  text = layoutFlows(model, fontsFor);
  view.setText(text, fontsFor(bodyStyle(model).font, bodyStyle(model).weight));
  updatePageBtn();
}

/**
 * זרימה אוטומטית: שטף עם autogrow שלא נכנס — מוסיפים עמודים לפי הערכה (כמה מהטקסט נכנס בעמודים הקיימים), ופורסים
 * שוב, עד שנכנס. עמודים מיותרים שנוספו כאן ונשארו ריקים — יורדים. מחזיר את המודל (אולי עם עמודים חדשים).
 */
function grow(m) {
  if (!loaded['Noto Serif Hebrew']) return m;
  const startCount = pagesOf(m).length;
  const step = m.layout.facing ? 2 : 1;
  for (let round = 0; round < 30; round++) {
    const t = layoutFlows(m, fontsFor);
    const need = [...t.flows].find(([fid, r]) => r.overset && m.flows[fid].autogrow);
    if (!need) break;
    const [fid, r] = need;
    const used = Math.max(1, thread(m, fid).length);
    const done = Math.max(0.5, r.rest.paragraph + (r.rest.word ? 0.5 : 0));
    let add = Math.ceil(used * r.rest.of / done) - used;
    add = Math.max(step, Math.min(400, Math.ceil(add / step) * step));
    if (pagesOf(m).length + add > 2000) { toast('הטקסט ארוך מדי — הגעתי ל-2000 עמודים', true); break; }
    m = growFlow(m, fid, add, newId);
  }
  // עמודים שנוספו עכשיו ונשארו ריקים (ההערכה הייתה גבוהה מדי) — יורדים מהסוף, בזוגות בעמודים כפולים
  if (pagesOf(m).length > startCount) {
    const t = layoutFlows(m, fontsFor);
    let n = pagesOf(m).length;
    const emptyPage = i => allFrames(m).filter(x => x.pageIndex === i).every(x => !t.frames.get(x.frame.id)?.lines.length);
    while (n - step >= startCount && Array.from({ length: step }, (_, k) => n - 1 - k).every(emptyPage)) n -= step;
    if (n < pagesOf(m).length) m = pruneFlows(setPageCount(m, n, () => newId('p')));
  }
  return m;
}

// ביטול / ביצוע מחדש: המודל לא משתנה במקום (כל שינוי יוצר מודל חדש), לכן מחסנית של מודלים מספיקה
const UNDO_MAX = 100;
const past = [], future = [];
function show(next) {
  model = next;
  view.setModel(model);
  relayout();
  dirty = true;
  setState('לא נשמר');
  clearTimeout(saveTimer);
  saveTimer = setTimeout(save, 800);
  $('#undo-btn').disabled = !past.length;
  $('#redo-btn').disabled = !future.length;
}
function changed(next) {
  const grown = grow(next);
  if (grown === model) return;
  past.push(model);
  if (past.length > UNDO_MAX) past.shift();
  future.length = 0;
  show(grown);
}
function undo() { if (past.length) { future.push(model); show(past.pop()); toast('בוטל'); } }
function redo() { if (future.length) { past.push(model); show(future.pop()); toast('בוצע שוב'); } }

async function save() {
  if (saving || !dirty || blocked) return;
  saving = true; dirty = false;
  setState('שומר…');
  try {
    rev = (await api('doc_save', { id, rev, model: JSON.stringify(model) })).rev;
    setState(dirty ? 'לא נשמר' : 'נשמר');
  } catch (e) {
    dirty = true;
    if (e.message === 'conflict') {
      blocked = true;
      setState('לא נשמר — המסמך השתנה במקום אחר');
      confirm('המסמך נשמר בינתיים מלשונית או ממכשיר אחר. לטעון אותו מחדש? (השינויים האחרונים כאן לא יישמרו)', { ok: 'טעינה מחדש' })
        .then(yes => { if (yes) location.reload(); });
    } else if (e.message === 'network' || e.message === 'server') {
      setState('לא נשמר — מנסה שוב');
      saveTimer = setTimeout(save, 4000);
    } else {
      blocked = true;
      setState('לא נשמר');
      toast(errText(e.message), true);
    }
  } finally {
    saving = false;
    if (dirty && !blocked) { clearTimeout(saveTimer); saveTimer = setTimeout(save, 800); }
  }
}

window.addEventListener('beforeunload', e => { if (dirty || saving) { save(); e.preventDefault(); } });

// ---- סרגל: דפדוף וזום ----
function updateBar() {
  if (!view.lay) return;
  const s = view.lay.spreads[view.currentSpread()];
  const nums = s.pages.map(p => p.num).sort((a, b) => a - b);
  const total = pagesOf(model).length;
  const range = nums.join('–');
  // "2–3" בתוך טקסט עברי מתהפך ל-"3–2" — מבודדים כ-LTR (LRI … PDI). בטלפון — קצר: "2–3 / 6"
  $('#pos').textContent = '\u2066' + range + ' / ' + total + '\u2069';
  $('#pos').title = `עמודים ${range} מתוך ${total}`;
  $('#zoom-pct').textContent = zoomLabel(view.zoom);
}

$('#prev').addEventListener('click', () => view.goSpread(view.currentSpread() - 1));
$('#next').addEventListener('click', () => view.goSpread(view.currentSpread() + 1));
$('#zoom-in').addEventListener('click', () => view.step(1));
$('#zoom-out').addEventListener('click', () => view.step(-1));
$('#zoom-pct').addEventListener('click', () => view.fit());

// קיצורים — לפי המקש הפיזי (ui-core/keys.js), כך שהם עובדים גם כשהמקלדת בעברית; לא בתוך שדה ולא בתוך חלון
const keys = shortcuts();
keys.add('PageDown', () => view.goSpread(view.currentSpread() + 1));
keys.add('PageUp', () => view.goSpread(view.currentSpread() - 1));
keys.add('Mod+Shift?+=', () => view.step(1));
keys.add('Mod+-', () => view.step(-1));
keys.add('Mod+0', () => view.fit());
keys.add('Mod+Z', undo, { label: 'ביטול' });
keys.add('Mod+Shift+Z', redo, { label: 'ביצוע מחדש' });
keys.add('Mod+Y', redo, { label: 'ביצוע מחדש' });
$('#undo-btn').addEventListener('click', undo);
$('#redo-btn').addEventListener('click', redo);
keys.title($('#undo-btn'), 'Mod+Z');
keys.title($('#redo-btn'), 'Mod+Y');

// הוספת עמודים: שניים בעמודים כפולים (פריסה שלמה), אחד בלי
$('#add-page').addEventListener('click', () => {
  const n = pagesOf(model).length + (model.layout.facing ? 2 : 1);
  changed(pruneFlows(setPageCount(model, n, () => newId('p'))));
  view.goSpread(view.lay.spreads.length - 1);
  toast(`${pagesOf(model).length} עמודים`);
});

// ---- עמוד נבחר: עמוד אב ----
const pageBtn = $('#page-btn'), pdlg = $('#page-dlg'), pform = $('form', pdlg);

function updatePageBtn() {
  const i = view.selected;
  pageBtn.hidden = i < 0;
  $('#add-page').hidden = i >= 0;
  if (i < 0) return;
  if (view.frameSel) {
    const fr = allItems(model).find(x => x.frame.id === view.frameSel)?.frame;
    const over = text?.frames.get(view.frameSel)?.overset;
    pageBtn.textContent = fr && isDrawing(fr) ? '🎨 ציור' : fr && isImage(fr) ? '🖼 תמונה' : over ? '▭ מסגרת · ⚠ גולש' : '▭ מסגרת';
  } else pageBtn.textContent = `📄 עמוד ${i + 1}`;
}

pageBtn.addEventListener('click', () => {
  const i = view.selected;
  if (i < 0) return;
  if (view.frameSel) return openFrame();
  const page = pagesOf(model)[i];
  $('#page-title').textContent = `עמוד ${i + 1}`;
  const seg = $('#pm-seg');
  seg.replaceChildren();
  for (const [value, label] of [...mastersOf(model).map(m => [m.id, m.name]), ['', 'ללא']]) {
    const l = document.createElement('label');
    l.innerHTML = '<input type="radio" name="master"><span></span>';
    l.firstChild.value = value;
    l.lastChild.textContent = label;
    seg.append(l);
  }
  pform.master.value = page.master || '';
  const s = view.lay.spreads[spreadOfPage(view.lay, i)];
  $('#pm-both-row').hidden = s.pages.length < 2;
  pform.both.checked = false;
  pform.rest.checked = false;
  pdlg.showModal();
});

pform.addEventListener('submit', e => {
  e.preventDefault();
  const i = view.selected;
  const s = view.lay.spreads[spreadOfPage(view.lay, i)];
  let idx = [i];
  if (pform.both.checked) idx = s.pages.map(p => p.index);
  if (pform.rest.checked) for (let k = Math.min(...idx); k < pagesOf(model).length; k++) idx.push(k);
  pdlg.close();
  changed(setMaster(model, idx, pform.master.value || null));
});

document.addEventListener('keydown', e => { if (e.key === 'Escape' && !e.target.closest('dialog')) view.select(-1); });

// מסגרת טקסט בעמוד הנבחר (שטף חדש), או זרימה אוטומטית מכאן ועד הסוף — ומיד לעריכת הטקסט
$('#pm-frame').addEventListener('click', async () => {
  pdlg.close();
  await ensureFonts();
  const i = view.selected;
  const r = addFrame(model, i, { newId });
  changed(r.model);
  view.select(i, r.frameId);
  openStory(r.flowId);
});
$('#pm-flow').addEventListener('click', async () => {
  pdlg.close();
  await ensureFonts();
  const i = view.selected;
  const r = autoflow(model, i, { newId });
  changed(r.model);
  view.select(i, thread(model, r.flowId)[0]?.frame.id || null);
  openStory(r.flowId);
});

// ---- מסגרת נבחרת ----
const fdlg = $('#frame-dlg'), fform = $('form', fdlg), fErr = $('.error[role=alert]', fdlg);
const selFrame = () => allItems(model).find(x => x.frame.id === view.frameSel);

function openFrame() {
  const x = selFrame();
  if (!x) return;
  const { frame, pageIndex } = x;
  const kind = isDrawing(frame) ? 'vector' : isImage(frame) ? 'image' : 'text';
  $('#frame-title').textContent = `${{ vector: 'ציור', image: 'תמונה', text: 'מסגרת טקסט' }[kind]} · עמוד ${pageIndex + 1}`;
  for (const el of fdlg.querySelectorAll('[data-kind]')) el.hidden = el.dataset.kind !== kind;
  if (kind === 'image') {
    fform.fit.value = frame.fit || 'contain';
    const [fx, fy] = frame.focus || [0.5, 0.5];
    fform.focx.value = String(fx); fform.focy.value = String(fy);
    fform.zoom.value = Math.round((frame.zoom || 1) * 100);
    $('#zoom-out-v').textContent = fform.zoom.value + '%';
    fform.wrap.checked = frame.wrap === 'box';
    fform.wrapgap.value = ptToMm(frame.wrapGap ?? 8);
    // רזולוציה להדפסה: פיקסלים לאינץ' בגודל שבו התמונה מצוירת
    const r = imagePlacement(frame);
    const ppi = Math.round(frame.px[0] / (r.w / 72));
    $('#img-info').textContent = `${frame.px[0]}×${frame.px[1]} פיקסלים · ${ppi} לאינץ' בגודל הזה` + (ppi < 200 ? ' — ⚠ נמוך להדפסה (מומלץ 300)' : '');
  }
  fform.columns.value = String(frame.columns || 1);
  fform.gutter.value = ptToMm(frame.gutter || 0);
  // x בטופס — מימין (מתאים לעברית): המרחק מקצה העמוד הימני לקצה המסגרת הימני
  const [w] = pageSize(model);
  const [bx, by, bw, bh] = frame.box;
  fform.fx.value = ptToMm(w - bx - bw); fform.fy.value = ptToMm(by); fform.fw.value = ptToMm(bw); fform.fh.value = ptToMm(bh);
  const fl = text?.flows.get(frame.flow);
  const ov = $('#fr-overset');
  ov.hidden = !fl?.overset;
  if (fl?.overset) ov.textContent = `⚠ הטקסט לא נכנס — נשארו עוד ${fl.rest.of - fl.rest.paragraph} פסקאות. "עמודים עד סוף הטקסט" — מוסיף עמודים.`;
  if (kind === 'text') $('#fr-grow').hidden = !!model.flows[frame.flow]?.autogrow && !fl?.overset;
  fErr.hidden = true;
  fdlg.showModal();
}

$('#fr-fill').addEventListener('click', () => {
  const x = selFrame();
  const [w] = pageSize(model);
  const [bx, by, bw, bh] = contentBox(model, x.pageIndex + 1);
  fform.fx.value = ptToMm(w - bx - bw); fform.fy.value = ptToMm(by); fform.fw.value = ptToMm(bw); fform.fh.value = ptToMm(bh);
});

fform.addEventListener('submit', e => {
  e.preventDefault();
  const x = selFrame();
  const fail = msg => { fErr.textContent = msg; fErr.hidden = false; };
  const [w, h] = pageSize(model);
  const v = ['fx', 'fy', 'fw', 'fh', 'gutter'].map(k => num(fform[k].value));
  if (v.some(n => !(n >= 0))) return fail('מספרים, 0 או יותר');
  const [fx, fy, fw, fh, gut] = v.map(mmToPt);
  if (fw < 10 || fh < 10) return fail('המסגרת קטנה מדי');
  if (fx + fw > w + 0.5 || fy + fh > h + 0.5) return fail('המסגרת יוצאת מהעמוד');
  const patch = { box: [w - fx - fw, fy, fw, fh].map(n => Math.round(n * 100) / 100) };
  if (isDrawing(x.frame)) {   // ציור: כל הנתיבים זזים ונמתחים יחד עם המסגרת
    fdlg.close();
    changed(updateFrame(model, x.frame.id, moveDrawing(x.frame, patch.box)));
    return;
  }
  if (isImage(x.frame)) {
    const gap = num(fform.wrapgap.value);
    if (!(gap >= 0 && gap <= 100)) return fail('מרווח מהטקסט — 0 עד 100 מ"מ');
    Object.assign(patch, { fit: fform.fit.value, focus: [Number(fform.focx.value), Number(fform.focy.value)],
      zoom: Number(fform.zoom.value) / 100, wrap: fform.wrap.checked ? 'box' : 'none', wrapGap: mmToPt(gap) });
  } else Object.assign(patch, { columns: Number(fform.columns.value), gutter: gut });
  fdlg.close();
  changed(updateFrame(model, x.frame.id, patch));
});

fform.zoom.addEventListener('input', () => { $('#zoom-out-v').textContent = fform.zoom.value + '%'; });

// התאמת המסגרת ליחס התמונה: שומרים את הרוחב ואת הצד הימני, משנים את הגובה
$('#fr-fitframe').addEventListener('click', () => {
  const { frame } = selFrame();
  const fw = num(fform.fw.value);
  if (fw > 0) fform.fh.value = Math.round(fw * frame.px[1] / frame.px[0] * 10) / 10;
  fform.fit.value = 'contain';
  fform.zoom.value = 100; $('#zoom-out-v').textContent = '100%';
});

// ---- ייבוא מ-Word (docx) ----
const docxInput = $('#docx-file');
let docxTarget = null;   // { page } — זרימה אוטומטית מהעמוד; { flowId } — החלפת הטקסט של שטף
$('#pm-docx').addEventListener('click', () => { docxTarget = { page: view.selected }; pdlg.close(); docxInput.click(); });
$('#fr-docx').addEventListener('click', async () => {
  const x = selFrame();
  if (flowText(model, x.frame.flow).trim() && !await confirm('הטקסט במסגרת (ובכל השרשור שלה) יוחלף בטקסט מהקובץ. להמשיך?', { ok: 'החלפה' })) return;
  docxTarget = { flowId: x.frame.flow };
  fdlg.close();
  docxInput.click();
});
docxInput.addEventListener('change', async () => {
  const file = docxInput.files[0];
  docxInput.value = '';
  if (!file || !docxTarget) return;
  toast('קורא את הקובץ…');
  try {
    const bytes = new Uint8Array(await file.arrayBuffer());
    const io = await import('./mbe-io.js');
    let doc, sum, lines;
    if (io.isMbe(bytes)) {   // ‎.mbe — בדרך כלל של עורך התמלילים: השטף שלו, דרך הליבה
      const [r] = await Promise.all([io.readTextMbe(bytes), ensureFonts()]);
      doc = r.doc;
      lines = io.textReportLines(r.report);
      sum = `${r.report.paragraphs} פסקאות יובאו מ-"${file.name}"`;
    } else {
      const [{ docxToDoc, reportLines }] = await Promise.all([import('./docx.js'), ensureFonts()]);
      const r = await docxToDoc(bytes);
      doc = r.doc;
      lines = reportLines(r.report);
      sum = `${r.report.paragraphs} פסקאות יובאו מ-"${file.name}"`;
    }
    if (docxTarget.flowId) changed(setFlow(model, docxTarget.flowId, { doc }));
    else {
      const r = autoflow(model, docxTarget.page, { newId });
      changed(setFlow(r.model, r.flowId, { doc }));
      view.select(docxTarget.page, thread(model, r.flowId)[0]?.frame.id || null);
    }
    // דוח: כמה עבר, ומה לא
    $('#report-sum').textContent = sum + (lines.length ? '. מה שלא עבר במדויק:' : ' — הכול עבר.');
    $('#report-list').replaceChildren(...lines.map(t => Object.assign(document.createElement('li'), { textContent: t })));
    $('#report-dlg').showModal();
  } catch (err) {
    const m = err.message;
    toast(m === 'not_zip' || m === 'not_docx' ? 'זה לא קובץ Word (docx) ולא ‎.mbe'
      : m === 'no_text' ? 'אין בקובץ הזה טקסט לייבוא'
      : m.startsWith('mbe_invalid') ? 'קובץ ה-‎.mbe פגום (' + m.slice(12) + ')' : 'הייבוא נכשל: ' + m, true);
  }
});

// ---- ציור מהווקטורי: ‎.mbe עם שכבות vector → שכבה מוצבת בעמוד (vector.js) ----
const vecInput = $('#vec-file');
let vecPage = -1;
$('#pm-vector').addEventListener('click', () => { vecPage = view.selected; pdlg.close(); vecInput.click(); });
vecInput.addEventListener('change', async () => {
  const file = vecInput.files[0];
  vecInput.value = '';
  if (!file || vecPage < 0) return;
  toast('קורא את הציור…');
  try {
    const { open } = await import('./mbe/mbe.js');
    const f = await open(new Uint8Array(await file.arrayBuffer()));
    if (!f.ok) throw new Error('הקובץ פגום (' + f.issues.filter(i => i.level === 'error').map(i => i.code).slice(0, 3).join(', ') + ')');
    const r = await placeFromMbe(f, contentBox(model, vecPage + 1), file.name.replace(/\.mbe$/i, ''));
    let m = Object.keys(r.symbols).length ? { ...model, symbols: { ...(model.symbols || {}), ...r.symbols } } : model;
    m = { ...m, surfaces: m.surfaces.map((sf, i) => sf === pagesOf(m)[vecPage] ? { ...sf, layers: [...(sf.layers || []), r.layer] } : sf) };
    changed(m);
    view.select(vecPage, r.layer.id);
    toast(gapsNote('הציור הוצב', r.gaps));
  } catch (e) {
    toast(e.message === 'no_vector' ? 'אין בקובץ שכבות וקטור' : e.message === 'no_surface' ? 'אין בקובץ משטח לציור' : 'ההצבה נכשלה: ' + e.message, true);
  }
});

/** ‎.mbe פתוח → שכבה מוצבת ב-box; התמונות שבתוך הציור עולות לאחסון של המשתמש, באותו שם (הגיבוב). */
async function placeFromMbe(f, box, name) {
  const r = placeDrawing(f.document, box, () => newId('v'), name);
  for (const src of r.images) {
    const data = f.getMedia(src);
    if (!data) continue;
    const type = /\.png$/.test(src) ? 'image/png' : /\.webp$/.test(src) ? 'image/webp' : 'image/jpeg';
    const got = await uploadImage(new File([data], src.slice(6), { type }), { raw: true });
    if (got.src !== src) throw new Error('תמונה בציור לא נשמרה');
  }
  return r;
}
const gapsNote = (done, g) => {
  const miss = [g.text && `${g.text} טקסטים`, g.effects && `${g.effects} אפקטים`, g.unknown && `${g.unknown} פריטים מסוג לא מוכר`].filter(Boolean);
  return miss.length ? done + '. עוד לא מוצגים כאן: ' + miss.join(', ') : done;
};

// ---- ✒️ עריכה בווקטורי: הציור נפתח בחלון של הווקטורי (mbe-handoff, ‏mbe/handoff.js של הליבה) וחוזר לאותה מסגרת ----
const VECTOR_URL = '../vector-studio/';
const FROM = { app: 'layout-studio', name: 'העימוד' };
async function drawingMbe(layer) {
  const [{ create, save, PREVIEW, PREVIEW_MAX_PX }, { APP, APP_VERSION }] = await Promise.all([import('./mbe/mbe.js'), import('./mbe-io.js')]);
  const doc = drawingToDoc(layer, model.symbols || {});
  const f = create('vector', doc);
  const srcs = new Set([...imageRefs(layer.paths), ...Object.values(doc.symbols || {}).flatMap(sy => [...imageRefs(sy.paths)])]);
  for (const src of srcs) {
    const r = await fetch('api.php?media=' + encodeURIComponent(src.slice(6)), { credentials: 'same-origin' });
    if (!r.ok) throw new Error('תמונה בציור חסרה בשרת');
    const type = /\.png$/.test(src) ? 'image/png' : /\.webp$/.test(src) ? 'image/webp' : 'image/jpeg';
    if (await f.addMedia(new Uint8Array(await r.arrayBuffer()), type) !== src) throw new Error('תמונה בציור לא תואמת');
  }
  // תמונה מקדימה (חובה בקובץ): הלוח על רקע לבן
  const [w, h] = doc.surfaces[0].size;
  const k = Math.min(PREVIEW_MAX_PX / w, PREVIEW_MAX_PX / h, 4);
  const c = new OffscreenCanvas(Math.max(1, Math.round(w * k)), Math.max(1, Math.round(h * k)));
  const g = c.getContext('2d');
  g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height);
  g.scale(k, k);
  const imgs = new Map();
  for (const src of srcs) imgs.set(src, await loadImg(src));
  drawItems(g, doc.surfaces[0].layers[0].paths, { symbols: doc.symbols || {}, image: src => imgs.get(src) || null });
  f.setRendition(PREVIEW, new Uint8Array(await (await c.convertToBlob({ type: 'image/png' })).arrayBuffer()), { type: 'image/png' });
  return { bytes: await save(f, { app: APP, appVersion: APP_VERSION }), name: (layer.name || 'ציור') + '.mbe' };
}

/** הציור חזר מהווקטורי: נכנס לאותה מסגרת (אותו מזהה — הבחירה נשארת), וסמלים שכבר לא בשימוש יוצאים. */
async function drawingReturned(layerId, bytes) {
  try {
    const { open } = await import('./mbe/mbe.js');
    const f = await open(bytes);
    if (!f.ok) throw new Error('הקובץ שחזר פגום (' + f.issues.filter(i => i.level === 'error').map(i => i.code).slice(0, 3).join(', ') + ')');
    const cur = allItems(model).find(x => x.frame.id === layerId);
    if (!cur) throw new Error('הציור כבר לא בעמוד');
    const r = await placeFromMbe(f, cur.frame.box, cur.frame.name || 'ציור');
    // הלוח נכנס למסגרת ביחס שלו (צמוד לימין ולמעלה); אם גודל הלוח לא שונה — בדיוק במקום
    const layer = { ...r.layer, id: layerId };
    let m = Object.keys(r.symbols).length ? { ...model, symbols: { ...(model.symbols || {}), ...r.symbols } } : model;
    m = updateFrame(m, layerId, layer);
    changed(pruneSymbols(m));
    view.select(cur.pageIndex, layerId);
    toast(gapsNote('הציור חזר מהווקטורי', r.gaps));
  } catch (e) {
    toast(e.message === 'no_vector' ? 'בציור שחזר אין שכבות וקטור' : e.message === 'no_surface' ? 'בקובץ שחזר אין לוח' : 'החזרה נכשלה: ' + e.message, true);
  }
}

$('#fr-vector').addEventListener('click', () => {
  const x = selFrame();
  if (!x || !isDrawing(x.frame)) return;
  fdlg.close();
  const layer = x.frame;
  // openIn פותח את החלון כבר בתוך הלחיצה (אחרת חוסם החלונות הקופצים עוצר); הקובץ נבנה בינתיים
  const built = drawingMbe(layer);
  const ok = openIn(VECTOR_URL, built, {
    from: FROM,
    onReturn: bytes => drawingReturned(layer.id, bytes),
    onFallback: why => toast(why === 'blocked' ? 'הדפדפן חסם את החלון החדש — אפשרו חלונות קופצים לאתר ונסו שוב'
      : why === 'no-answer' ? 'הווקטורי לא נפתח (אין תשובה). נסו שוב' : 'הכנת הציור נכשלה', true),
  });
  if (ok) toast('הציור נפתח בווקטורי. כשתסיימו — "↩ חזרה לעימוד" שם');
});

// ---- תמונות: בחירה, הקטנה (אם צריך) והעלאה ----
const MAX_SIDE = 3000, MAX_BYTES = 6 * 1024 * 1024;
async function prepareImage(file) {
  if (!/^image\/(png|jpeg|webp)$/.test(file.type)) throw new Error('רק PNG, JPEG או WebP');
  const bmp = await createImageBitmap(file);
  const big = Math.max(bmp.width, bmp.height);
  if (file.size <= MAX_BYTES && big <= MAX_SIDE) { bmp.close?.(); return file; }
  // גדולה מדי לשרת — מקטינים ל-3000 פיקסלים בצד הארוך (300 לאינץ' עד ~25 ס"מ) ושומרים כ-JPEG
  const k = Math.min(1, MAX_SIDE / big);
  const c = new OffscreenCanvas(Math.round(bmp.width * k), Math.round(bmp.height * k));
  const g = c.getContext('2d');
  g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height);
  g.drawImage(bmp, 0, 0, c.width, c.height);
  bmp.close?.();
  return c.convertToBlob({ type: 'image/jpeg', quality: 0.9 });
}
async function uploadImage(file, { raw = false } = {}) {
  const body = raw ? file : await prepareImage(file);   // raw — כמו שהוא (תמונה מתוך ‎.mbe: השם הוא הגיבוב שלה)
  let res;
  try {
    res = await fetch('api.php?action=media_upload', { method: 'POST', credentials: 'same-origin',
      headers: { 'X-Layout-Studio': '1', 'Content-Type': body.type || 'application/octet-stream' }, body });
  } catch { throw new Error('אין חיבור לשרת'); }
  const out = await res.json().catch(() => ({ ok: false, error: res.status === 413 ? 'too_big' : 'server' }));
  if (!out.ok) throw new Error(out.error === 'too_big' ? 'התמונה גדולה מדי' : out.error === 'bad_image' ? 'הקובץ אינו תמונה תקינה' : 'ההעלאה נכשלה');
  return out;   // { src, px }
}
let imageTarget = null;   // { page } — תמונה חדשה; { frameId } — החלפה
const imgInput = $('#img-file');
$('#pm-image').addEventListener('click', () => { imageTarget = { page: view.selected }; pdlg.close(); imgInput.click(); });
$('#fr-replace').addEventListener('click', () => { imageTarget = { frameId: view.frameSel }; fdlg.close(); imgInput.click(); });
imgInput.addEventListener('change', async () => {
  const file = imgInput.files[0];
  imgInput.value = '';
  if (!file || !imageTarget) return;
  toast('מעלה תמונה…');
  try {
    const { src, px } = await uploadImage(file);
    if (imageTarget.frameId) {
      changed(updateFrame(model, imageTarget.frameId, { src, px }));
    } else {
      const r = addImageFrame(model, imageTarget.page, { src, px, newId });
      changed(r.model);
      view.select(imageTarget.page, r.frameId);
    }
    toast('התמונה נוספה');
  } catch (err) {
    toast(err.message, true);
  }
});

$('#fr-edit').addEventListener('click', () => { const x = selFrame(); fdlg.close(); openStory(x.frame.flow); });

// המשך בעמוד הבא: מסגרת חדשה בעמוד הבא שממשיכה את השרשור (אין עמוד — מוסיפים)
$('#fr-next').addEventListener('click', () => {
  const x = selFrame();
  fdlg.close();
  let m = model, next = x.pageIndex + 1;
  if (next >= pagesOf(m).length) m = setPageCount(m, next + 1, () => newId('p'));
  const r = addFrame(m, next, { flowId: x.frame.flow, newId });
  changed(r.model);
  view.select(next, r.frameId);
  view.goSpread(spreadOfPage(view.lay, next));
});

$('#fr-grow').addEventListener('click', () => {
  const x = selFrame();
  fdlg.close();
  const before = pagesOf(model).length;
  changed(setFlow(model, x.frame.flow, { autogrow: true }));
  const added = pagesOf(model).length - before;
  toast(added ? `נוספו ${added} עמודים` : 'הטקסט נכנס');
});

$('#fr-del').addEventListener('click', async () => {
  const x = selFrame();
  if (!await confirm(isDrawing(x.frame) ? 'למחוק את הציור מהעמוד?' : isImage(x.frame) ? 'למחוק את התמונה מהעמוד?' : 'למחוק את המסגרת? הטקסט נשאר וזורם למסגרות האחרות בשרשור (אם יש).', { ok: 'מחיקה', danger: true })) return;
  fdlg.close();
  const fid = x.frame.flow;
  const wasLast = thread(model, fid).length === 1;
  if (wasLast && flowText(model, fid).trim() && !await confirm('זו המסגרת האחרונה של הטקסט — הטקסט יימחק איתה. להמשיך?', { ok: 'מחיקה', danger: true })) return;
  view.select(x.pageIndex, null);
  changed(pruneSymbols(removeFrame(model, x.frame.id)));   // ציור שנמחק — גם הסמלים שרק הוא השתמש בהם
});

// ---- עריכת הטקסט (Story Editor): ProseMirror, בשמות של עורך התמלילים ----
const sdlg = $('#story-dlg'), sform = $('form', sdlg);
let storyFlow = null, story = null;
async function openStory(flowId) {
  const { openStory: open, sanitize } = await import('./story.js');
  const doc = model.flows[flowId]?.doc;
  const { lost } = sanitize(doc);
  if (lost.length && !await confirm(`בטקסט יש רכיבים שהעימוד עוד לא תומך בהם (${lost.join(', ')}). עריכה כאן תהפוך אותם לפסקאות רגילות. להמשיך?`, { ok: 'עריכה' })) return;
  storyFlow = flowId;
  sdlg.showModal();
  story = await open($('.story-host', sdlg), doc, {
    onState({ style, bold }) {
      for (const b of sdlg.querySelectorAll('.story-styles [data-cmd]')) b.setAttribute('aria-pressed', String(b.dataset.cmd === style));
      $('.story-b', sdlg).setAttribute('aria-pressed', String(bold));
    },
  });
  story.view.focus();
}
sdlg.addEventListener('click', e => {
  const b = e.target.closest('[data-cmd]');
  if (b && story) story.command(b.dataset.cmd);
});
sdlg.addEventListener('close', () => { story?.destroy(); story = null; });
sform.addEventListener('submit', e => {
  e.preventDefault();
  const doc = story?.getDoc();
  sdlg.close();
  if (!doc) return;
  changed(setFlow(model, storyFlow, { doc }));
  const r = text?.flows.get(storyFlow);
  if (r?.overset) toast('הטקסט לא נכנס — ➕ אדום בסוף המסגרת האחרונה', true);
});

// ---- הגדרות ----
const dlg = $('#set-dlg'), form = $('form', dlg), dlgErr = $('.error', dlg);
const num = v => { const n = parseFloat(String(v).replace(',', '.')); return Number.isFinite(n) ? n : NaN; };

// עריכת סגנונות: לשונית לכל סגנון; הערכים של כל אחד נשמרים בטיוטה עד "שמירה"
let drafts = {}, curStyle = 'body';
function showStyle(key) {
  curStyle = key;
  const st = drafts[key];
  form.font.value = st.font;
  form.tsize.value = st.size; form.tlead.value = st.leading;
  form.tbefore.value = st.spaceBefore; form.tafter.value = st.spaceAfter; form.tindent.value = st.indent;
  form.tbold.checked = st.weight >= 600;
  form.talign.value = st.align === 'left' ? 'right' : st.align;
  for (const b of $('#st-tabs').children) b.setAttribute('aria-selected', String(b.dataset.key === key));
}
/** קריאת הלשונית הנוכחית לטיוטה. מחזיר הודעת שגיאה, או null. */
function readStyle() {
  const v = { size: num(form.tsize.value), leading: num(form.tlead.value), spaceBefore: num(form.tbefore.value),
    spaceAfter: num(form.tafter.value), indent: num(form.tindent.value) };
  if (!(v.size >= 4 && v.size <= 200)) return 'גודל — 4 עד 200 נקודות';
  if (!(v.leading >= 4 && v.leading <= 400)) return 'מרווח שורות — 4 עד 400 נקודות';
  if (!(v.spaceBefore >= 0 && v.spaceBefore <= 200 && v.spaceAfter >= 0 && v.spaceAfter <= 200)) return 'רווח לפני ואחרי — 0 עד 200 נקודות';
  if (!(v.indent >= 0 && v.indent <= 500)) return 'הזחה — 0 עד 500 נקודות';
  drafts[curStyle] = { ...drafts[curStyle], ...v, font: form.font.value, align: form.talign.value, weight: form.tbold.checked ? 700 : 400 };
  return null;
}
$('#st-tabs').addEventListener('click', e => {
  const b = e.target.closest('[data-key]');
  if (!b || b.dataset.key === curStyle) return;
  const err = readStyle();
  if (err) { dlgErr.textContent = `${drafts[curStyle].name}: ${err}`; dlgErr.hidden = false; return; }
  dlgErr.hidden = true;
  showStyle(b.dataset.key);
});

function fillSettings() {
  const [w, h] = pageSize(model).map(ptToMm);
  const preset = Object.entries(SIZES).find(([, [a, b]]) => Math.abs(a - w) < 0.6 && Math.abs(b - h) < 0.6);
  form.size.value = preset ? preset[0] : 'custom';
  form.w.value = w; form.h.value = h;
  const m = model.layout.margins;
  for (const k of ['top', 'bottom', 'inside', 'outside']) form[k].value = ptToMm(m[k]);
  form.facing.checked = model.layout.facing;
  form.binding.value = model.layout.binding;
  form.count.value = pagesOf(model).length;
  drafts = Object.fromEntries(STYLE_KEYS.map(k => [k, styleOf(model, k)]));
  showStyle('body');
  const nb = model.layout.numbering;
  form.start.value = nb.start;
  form.nstyle.value = nb.style;
  const run = mastersOf(model)[0].running || {};
  const fo = run.folio, hd = run.header;
  form.folio.checked = !!fo;
  form.fpos.value = fo?.position || 'bottom';
  form.falign.value = fo?.align || 'outside';
  form.header.checked = !!hd;
  form.hodd.value = hd?.odd || '';
  form.heven.value = hd?.even || '';
  form.hpos.value = hd?.position || 'top';
  form.halign.value = hd?.align || 'center';
  syncMasterOpts();
  dlgErr.hidden = true;
}

// אפשרויות שתלויות במתג — מוסתרות כשהוא כבוי; בלי עמודים כפולים יש כותרת אחת
function syncMasterOpts() {
  $('.folio-opts', form).hidden = !form.folio.checked;
  $('.header-opts', form).hidden = !form.header.checked;
  $('.h-even', form).hidden = !form.facing.checked;
  $('.h-odd-lbl', form).textContent = form.facing.checked ? 'בעמוד אי-זוגי' : 'הכותרת';
}

form.addEventListener('change', e => {
  if (e.target.name === 'size' && SIZES[form.size.value]) [form.w.value, form.h.value] = SIZES[form.size.value];
  if (e.target.name === 'w' || e.target.name === 'h') form.size.value = 'custom';
  syncMasterOpts();
});

$('#settings-btn').addEventListener('click', () => { fillSettings(); dlg.showModal(); });
// "+ שם הפרק" — מוסיף {כותרת 1} לשדה הכותרת הרצה האחרון שהיה בו המיקוד (ברירת מחדל: העמוד האי-זוגי)
let headField = 'hodd';
for (const n of ['hodd', 'heven']) form[n].addEventListener('focus', () => { headField = n; });
$('#h-token').addEventListener('click', () => {
  const el = form[headField];
  el.value = (el.value.trim() ? el.value.trim() + ' · ' : '') + '{כותרת 1}';
  el.focus();
});

form.addEventListener('submit', async e => {
  e.preventDefault();
  const fail = msg => { dlgErr.textContent = msg; dlgErr.hidden = false; };
  const w = num(form.w.value), h = num(form.h.value);
  if (!(w >= 50 && w <= 1200 && h >= 50 && h <= 1200)) return fail('גודל העמוד — בין 50 ל-1200 מ"מ');
  const margins = {};
  for (const k of ['top', 'bottom', 'inside', 'outside']) {
    const v = num(form[k].value);
    if (!(v >= 0)) return fail('השוליים — מספר, 0 או יותר');
    margins[k] = mmToPt(v);
  }
  const count = Math.round(num(form.count.value));
  if (!(count >= 1 && count <= 2000)) return fail('מספר העמודים — בין 1 ל-2000');

  const serr = readStyle();
  if (serr) return fail(`${drafts[curStyle].name}: ${serr}`);
  const start = Math.round(num(form.start.value));
  if (!(start >= 1 && start <= 99999)) return fail('מספור העמודים — מתחיל ב-1 או יותר');
  const hodd = form.hodd.value.trim(), heven = form.heven.value.trim();
  if (form.header.checked && !hodd && !(form.facing.checked && heven)) return fail('כותרת רצה — צריך טקסט');

  const cur = pagesOf(model).length;
  if (count < cur && !await confirm(`להוריד ${cur - count} עמודים מסוף המסמך?`, { ok: 'הורדה', danger: true })) return;

  let next = setPageSize(model, [mmToPt(w), mmToPt(h)]);
  next = { ...next, layout: { ...next.layout, facing: form.facing.checked, binding: form.binding.value, margins,
    numbering: { start, style: form.nstyle.value } } };
  const master = mastersOf(next)[0];
  next = updateMaster(next, master.id, { running: {
    folio: form.folio.checked ? { position: form.fpos.value, align: form.falign.value, size: master.running?.folio?.size || 10 } : null,
    header: form.header.checked ? { odd: hodd, even: form.facing.checked ? heven : hodd,
      position: form.hpos.value, align: form.halign.value, size: master.running?.header?.size || 9 } : null,
  } });
  next = pruneFlows(setPageCount(next, count, () => newId('p')));
  for (const k of STYLE_KEYS) next = setStyle(next, k, drafts[k]);
  if (!marginsFit(next)) return fail('השוליים גדולים מדי לעמוד — צריך להישאר לפחות 1 ס"מ לתוכן');
  dlg.close();
  changed(next);
  view.fit();
});

// ---- PDF להדפסה: הדפדפן מכין את רשימת הציור (pdf.js), השרת מריץ את pdf-core ----
async function gzip(str) {
  const cs = new CompressionStream('gzip');
  return new Response(new Blob([str]).stream().pipeThrough(cs)).blob();
}
// רשימת הציור → PDF (bytes). זורק Error עם הודעה למשתמש.
// ציורים (שכבות vector) ב-PDF: pdf-core עוד לא מצייר נתיבים, לכן כל עמוד עם ציורים — תמונה שקופה ב-300 לאינץ' של האזור
// שלהם, שעולה לאחסון ונכנסת לרשימה מתחת לטקסט. (בקשה לעורך התמלילים: פעולת path ב-pdf-core.)
const DRAW_DPI = 300;
async function drawingImages() {
  const out = new Map();
  for (let i = 0; i < pagesOf(model).length; i++) {
    const ds = drawingsOnPage(model, i);
    if (!ds.length) continue;
    const x0 = Math.min(...ds.map(d => d.box[0])), y0 = Math.min(...ds.map(d => d.box[1]));
    const x1 = Math.max(...ds.map(d => d.box[0] + d.box[2])), y1 = Math.max(...ds.map(d => d.box[1] + d.box[3]));
    const k = DRAW_DPI / 72;
    const c = document.createElement('canvas');
    c.width = Math.max(1, Math.ceil((x1 - x0) * k)); c.height = Math.max(1, Math.ceil((y1 - y0) * k));
    const ctx = c.getContext('2d');
    ctx.scale(k, k); ctx.translate(-x0, -y0);
    const imgs = new Map();
    for (const d of ds) for (const src of imageRefs(d.paths)) imgs.set(src, await loadImg(src));
    for (const d of ds) drawItems(ctx, d.paths, { symbols: model.symbols, image: src => imgs.get(src) || null });
    const blob = await new Promise(ok => c.toBlob(ok, 'image/png'));
    const got = await uploadImage(new File([blob], 'drawing.png', { type: 'image/png' }), { raw: true });
    out.set(i, [{ src: got.src, box: [x0, y0, x1 - x0, y1 - y0] }]);
  }
  return out;
}
async function makePdf() {
  await ensureFonts();
  relayout();
  const b = bodyStyle(model);
  const under = await drawingImages();
  const list = drawList(model, text, fontsFor(b.font, b.weight), { title: doc.title, under });
  let res;
  try {
    res = await fetch('api.php?action=pdf', { method: 'POST', credentials: 'same-origin',
      headers: { 'X-Layout-Studio': '1', 'Content-Type': 'application/gzip' }, body: await gzip(JSON.stringify(list)) });
  } catch { throw new Error('אין חיבור לשרת'); }
  if (!res.ok || res.headers.get('Content-Type') !== 'application/pdf') {
    const out = await res.json().catch(() => ({}));
    throw new Error(out.error === 'too_big' ? 'המסמך גדול מדי ל-PDF' : out.detail ? 'ה-PDF לא נוצר: ' + out.detail : 'ה-PDF לא נוצר');
  }
  return new Uint8Array(await res.arrayBuffer());
}
const download = (bytes, type, name) => saveFile(new Blob([bytes], { type }), name);
const pdfBtn = $('#pdf-btn');
pdfBtn.addEventListener('click', async () => {
  pdfBtn.disabled = true;
  toast('מכין PDF…');
  try {
    download(await makePdf(), 'application/pdf', (doc.title || 'עימוד') + '.pdf');
    toast('ה-PDF ירד');
  } catch (e) {
    toast(e.message, true);
  } finally {
    pdfBtn.disabled = false;
  }
});

// ---- הורדה כ-‎.mbe: המודל + התמונות + preview + PDF, דרך הליבה; קובץ המקור (אם יש) — נשמר מה שלא שלנו ----
const loadImg = src => new Promise(ok => {
  const im = new Image();
  im.onload = () => ok(im); im.onerror = () => ok(null);
  im.src = 'api.php?media=' + encodeURIComponent(src.slice(6));
});
const mbeBtn = $('#mbe-btn');
mbeBtn.addEventListener('click', async () => {
  mbeBtn.disabled = true;
  toast('מכין קובץ ‎.mbe…');
  try {
    const [io, { renderPreview }] = await Promise.all([import('./mbe-io.js'), import('./preview.js'), ensureFonts()]);
    relayout();
    const b = bodyStyle(model);
    const preview = new Uint8Array(await (await renderPreview(model, text, fontsFor(b.font, b.weight), loadImg)).arrayBuffer());
    let pdf = null;
    try { pdf = await makePdf(); } catch { /* ה-PDF מומלץ, לא חובה — הקובץ יוצא בלעדיו */ }
    const srcRes = await fetch('api.php?mbe_source=' + id, { credentials: 'same-origin' }).catch(() => null);
    const source = srcRes && srcRes.ok ? new Uint8Array(await srcRes.arrayBuffer()) : null;
    const { bytes } = await io.buildMbe({
      model, source, preview, pdf, stages: pendingStages,
      fetchMedia: async path => {
        const r = await fetch('api.php?media=' + encodeURIComponent(path.slice(6)), { credentials: 'same-origin' });
        if (!r.ok) throw new Error('תמונה חסרה בשרת');
        return r.arrayBuffer();
      },
    });
    pendingStages = [];   // נרשמו ביומן של הקובץ שירד
    download(bytes, 'application/vnd.mbe+zip', (doc.title || 'עימוד') + '.mbe');
    // הקובץ שירד הוא המקור של ההורדה הבאה — כך manifest.id ומה שאחרים כתבו נשארים
    fetch('api.php?action=mbe_source&id=' + id, { method: 'POST', credentials: 'same-origin',
      headers: { 'X-Layout-Studio': '1', 'Content-Type': 'application/vnd.mbe+zip' }, body: bytes }).catch(() => {});
    toast(pdf ? 'הקובץ ירד' : 'הקובץ ירד (בלי PDF — לא נוצר)');
  } catch (e) {
    toast('ההורדה נכשלה: ' + e.message, true);
  } finally {
    mbeBtn.disabled = false;
  }
});

// ---- היסטוריית גרסאות: היומן (history/) של קובץ המקור — נרשם בכל הורדה כ-‎.mbe, וגם באפליקציות אחרות ----
let pendingStages = [];   // שלבים שיירשמו בהורדה הבאה (שחזור גרסה)
const hdlg = $('#hist-dlg');
const fmtAt = at => { try { return new Date(at).toLocaleString('he-IL', { dateStyle: 'short', timeStyle: 'short' }); } catch { return at; } };
$('#hist-btn').addEventListener('click', async () => {
  dlg.close();
  $('#hist-list').replaceChildren();
  $('#hist-sum').textContent = 'טוען…';
  hdlg.showModal();
  try {
    const res = await fetch('api.php?mbe_source=' + id, { credentials: 'same-origin' });
    if (res.status === 404) {
      $('#hist-sum').textContent = 'עוד אין היסטוריה. היא נרשמת בכל הורדה של המסמך כקובץ ‎.mbe (⬇️), וגם כשאפליקציה אחרת עורכת אותו.';
      return;
    }
    if (!res.ok) throw new Error('server');
    const src = new Uint8Array(await res.arrayBuffer());
    const io = await import('./mbe-io.js');
    const list = await io.readHistory(src);
    $('#hist-sum').textContent = list.length
      ? 'מהחדש לישן. שחזור טוען את הגרסה לעורך (אפשר לבטל ב-↶), ונרשם ביומן בהורדה הבאה.'
      : 'אין עדיין שלבים ביומן.';
    $('#hist-list').replaceChildren(...list.map(st => {
      const li = document.createElement('li');
      const t = Object.assign(document.createElement('div'), { className: 'hist-txt' });
      t.append(`${st.kindLabel} · ${st.appLabel} · `, Object.assign(document.createElement('bdi'), { textContent: fmtAt(st.at) }));
      const note = [st.who, st.note].filter(Boolean).join(' — ');
      if (note) t.append(Object.assign(document.createElement('div'), { className: 'hist-note', textContent: note }));
      li.append(t);
      if (st.available) {
        const b = Object.assign(document.createElement('button'), { type: 'button', className: 'btn sm ghost', textContent: 'שחזור' });
        b.addEventListener('click', async () => {
          if (!await confirm(`לטעון את הגרסה מ-${fmtAt(st.at)}? המסמך הנוכחי יוחלף בה (אפשר לבטל ב-↶).`, { ok: 'שחזור' })) return;
          const v = await io.historyVersion(src, st.sha);
          if (!v) { toast('הגרסה לא נמצאה בקובץ', true); return; }
          hdlg.close();
          changed(ensureMasters(v));
          view.fit(); view.goSpread(0);
          pendingStages.push({ kind: 'restore', note: 'שוחזר מ-' + st.id });
          toast('הגרסה נטענה');
        });
        li.append(b);
      }
      return li;
    }));
  } catch (e) {
    $('#hist-sum').textContent = 'ההיסטוריה לא נטענה: ' + e.message;
  }
});

// ---- טעינה ----
(async () => {
  if (!id) { location.replace('index.html'); return; }
  try {
    const got = await api('doc_get', { id });
    doc = got.doc; model = ensureMasters(got.model); rev = got.rev;   // מסמך ישן — עמוד אב נוסף מקומית, נשמר בשינוי הבא
    document.title = doc.title + ' — עימוד';
    $('#doc-title').textContent = doc.title;
    setState('נשמר');
    view.setModel(model, { keep: false });
    view.fit();
    view.goSpread(0);
    // הגופנים ו-HarfBuzz — ברקע; כשמוכנים, הטקסט והכותרות מצוירים בהם
    ensureFonts().then(relayout).catch(err => toast('הגופנים לא נטענו: ' + err.message, true));
    api('me').then(({ me }) => mountAccount($('header.top'), { api, me, shortcuts: keys })).catch(() => {});   // 👤
    $('#settings-btn').disabled = false;
    $('#pdf-btn').disabled = false;
    $('#mbe-btn').disabled = false;
    $('#add-page').disabled = false;
  } catch (e) {
    const el = $('#load-err');
    el.textContent = errText(e.message); el.hidden = false;
  }
})();
