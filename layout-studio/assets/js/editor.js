// עורך המסמך — שלב העמודים: תצוגה, זום, דפדוף, והגדרות (גודל, שוליים, עמודים כפולים, מספר עמודים).
// כל שינוי נשמר לשרת אחרי רגע (doc_save עם rev — שמירה מלשונית ישנה נדחית במקום לדרוס).

import { PageView } from './view.js';
import { pagesOf, pageSize, setPageCount, setPageSize, marginsFit, mmToPt, ptToMm } from './pages.js';

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

function toast(msg, err = false) {
  const el = $('.toast');
  el.textContent = msg; el.classList.toggle('err', err); el.hidden = false;
  clearTimeout(toast.t); toast.t = setTimeout(() => { el.hidden = true; }, 2600);
}

(() => {   // --vvh: הגובה הנראה בלי המקלדת
  const vv = window.visualViewport; if (!vv) return;
  const set = () => document.documentElement.style.setProperty('--vvh', Math.round(vv.height) + 'px');
  vv.addEventListener('resize', set); set();
})();
document.addEventListener('click', e => { const b = e.target.closest('[data-close]'); if (b) b.closest('dialog').close(); });

const newId = () => 'p' + Array.from(crypto.getRandomValues(new Uint8Array(6)), b => b.toString(16).padStart(2, '0')).join('');

// ---- מצב ----
const id = Number(new URLSearchParams(location.search).get('id'));
let doc = null, model = null, rev = 0;
const view = new PageView($('#stage'), { onChange: updateBar });

// ---- שמירה ----
const saveState = $('#save-state');
let dirty = false, saving = false, saveTimer = 0, blocked = false;

function setState(text) { saveState.textContent = text; }

function changed(next) {
  model = next;
  view.setModel(model);
  dirty = true;
  setState('לא נשמר');
  clearTimeout(saveTimer);
  saveTimer = setTimeout(save, 800);
}

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
      if (confirm('המסמך נשמר בינתיים מלשונית או ממכשיר אחר. לטעון אותו מחדש? (השינויים האחרונים כאן לא יישמרו)')) location.reload();
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
  $('#zoom-pct').textContent = Math.round(view.zoom * 100) + '%';
}

$('#prev').addEventListener('click', () => view.goSpread(view.currentSpread() - 1));
$('#next').addEventListener('click', () => view.goSpread(view.currentSpread() + 1));
$('#zoom-in').addEventListener('click', () => view.setZoom(view.zoom * 1.25));
$('#zoom-out').addEventListener('click', () => view.setZoom(view.zoom / 1.25));
$('#zoom-pct').addEventListener('click', () => view.fit());

document.addEventListener('keydown', e => {
  if (e.target.closest('input, textarea, dialog')) return;
  if (e.key === 'PageDown') { e.preventDefault(); view.goSpread(view.currentSpread() + 1); }
  else if (e.key === 'PageUp') { e.preventDefault(); view.goSpread(view.currentSpread() - 1); }
  else if ((e.ctrlKey || e.metaKey) && (e.key === '=' || e.key === '+')) { e.preventDefault(); view.setZoom(view.zoom * 1.25); }
  else if ((e.ctrlKey || e.metaKey) && e.key === '-') { e.preventDefault(); view.setZoom(view.zoom / 1.25); }
  else if ((e.ctrlKey || e.metaKey) && e.key === '0') { e.preventDefault(); view.fit(); }
});

// הוספת עמודים: שניים בעמודים כפולים (פריסה שלמה), אחד בלי
$('#add-page').addEventListener('click', () => {
  const n = pagesOf(model).length + (model.layout.facing ? 2 : 1);
  changed(setPageCount(model, n, newId));
  view.goSpread(view.lay.spreads.length - 1);
  toast(`${pagesOf(model).length} עמודים`);
});

// ---- הגדרות ----
const dlg = $('#set-dlg'), form = $('form', dlg), dlgErr = $('.error', dlg);
const num = v => { const n = parseFloat(String(v).replace(',', '.')); return Number.isFinite(n) ? n : NaN; };

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
  dlgErr.hidden = true;
}

form.addEventListener('change', e => {
  if (e.target.name === 'size' && SIZES[form.size.value]) [form.w.value, form.h.value] = SIZES[form.size.value];
  if (e.target.name === 'w' || e.target.name === 'h') form.size.value = 'custom';
});

$('#settings-btn').addEventListener('click', () => { fillSettings(); dlg.showModal(); });

form.addEventListener('submit', e => {
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

  const cur = pagesOf(model).length;
  if (count < cur && !confirm(`להוריד ${cur - count} עמודים מסוף המסמך?`)) return;

  let next = setPageSize(model, [mmToPt(w), mmToPt(h)]);
  next = { ...next, layout: { ...next.layout, facing: form.facing.checked, binding: form.binding.value, margins } };
  next = setPageCount(next, count, newId);
  if (!marginsFit(next)) return fail('השוליים גדולים מדי לעמוד — צריך להישאר לפחות 1 ס"מ לתוכן');
  dlg.close();
  changed(next);
  view.fit();
});

// ---- טעינה ----
(async () => {
  if (!id) { location.replace('index.html'); return; }
  try {
    const got = await api('doc_get', { id });
    doc = got.doc; model = got.model; rev = got.rev;
    document.title = doc.title + ' — עימוד';
    $('#doc-title').textContent = doc.title;
    setState('נשמר');
    view.setModel(model, { keep: false });
    view.fit();
    view.goSpread(0);
    $('#settings-btn').disabled = false;
    $('#add-page').disabled = false;
  } catch (e) {
    const el = $('#load-err');
    el.textContent = errText(e.message); el.hidden = false;
  }
})();
