// חיבור הכול: טופס → מודל → תלת מימד, רשימת חיתוך ואזהרות.
//
// שלבים 1–3 מהאפיון: תבנית ספרייה, טופס שנבנה מהפרמטרים, תלת מימד עם
// הקשה על חלק, אזהרות, רשימת חיתוך ראשונית, וספריית חומרים דינמית עם
// טקסטורות ותמונות. אין עדיין שרת — הכול נשמר בדפדפן (store.js).

import { TEMPLATES, template, defaults, build, cutList, hardwareList } from './model/index.js';
import { cutSize } from './model/blocks.js';
import * as M from './model/materials.js';
import { renderForm } from './form.js';
import { createViewer } from './viewer.js';
import { createMaterialsUI } from './materials-ui.js';
import { loadDraft, saveDraft, loadMaterialsDiff, loadImages } from './store.js';

const $ = (s) => document.querySelector(s);

const state = { templateKey: 'bookcase', values: null };
const saved = loadDraft();
if (saved && TEMPLATES[saved.templateKey]) Object.assign(state, saved);
if (!state.values) state.values = defaults(template(state.templateKey));

let viewer = null;
let form = null;
let model = null;
let materialsUI = null;

async function init() {
  // הספרייה: הזריעה, מעליה השינויים שנשמרו, ומעליהם התמונות.
  M.load(loadMaterialsDiff());
  const images = await loadImages();
  for (const [id, v] of Object.entries(images || {})) M.setImage(id, v.image, v.imageMm);

  const tpl = template(state.templateKey);
  $('#template-name').textContent = tpl.name;
  $('#template-desc').textContent = tpl.description;

  form = renderForm($('#form'), tpl, state.values, () => rebuild());

  viewer = createViewer($('#stage'), { onPick: showPart });
  materialsUI = createMaterialsUI($('#mlib'), {
    onChange() {
      // הספרייה השתנתה: הטופס נבנה מחדש (רשימות הבחירה), והמודל מצויר מחדש (טקסטורות).
      form = renderForm($('#form'), tpl, state.values, () => rebuild());
      rebuild();
    },
  });
  $('#btn-materials').addEventListener('click', () => materialsUI.open());
  document.querySelectorAll('[data-view]').forEach((b) => b.addEventListener('click', () => viewer.view(b.dataset.view)));
  $('#btn-reset').addEventListener('click', () => {
    state.values = defaults(tpl);
    form = renderForm($('#form'), tpl, state.values, () => rebuild());
    rebuild(true);
  });
  document.querySelectorAll('.tabs button').forEach((b) => b.addEventListener('click', () => {
    if (b.dataset.tab === 'materials') { materialsUI.open(); return; }
    document.body.dataset.tab = b.dataset.tab;
    document.querySelectorAll('.tabs button').forEach((x) => x.classList.toggle('is-active', x === b));
    viewer.fit();
  }));
  $('#btn-output').addEventListener('click', () => $('#output').classList.toggle('is-open'));
  $('#btn-output-close').addEventListener('click', () => $('#output').classList.remove('is-open'));

  rebuild(true);
}

function rebuild(reframe = false) {
  model = build(state.templateKey, state.values);
  // הטופס מחזיק את אותו אובייקט ערכים — מעדכנים בתוכו, לא מחליפים אותו.
  Object.assign(state.values, model.values);
  form.sync(model.values);
  saveDraft(state);

  viewer.setModel(model);
  if (reframe) viewer.frame(model.bounds);

  renderWarnings(model.warnings);
  renderSummary(model);
  renderOutput(model);
}

function renderWarnings(list) {
  const box = $('#warnings');
  box.hidden = list.length === 0;
  box.innerHTML = list.map((w) => `<div class="warn">⚠ ${w}</div>`).join('');
  $('#tab-warn-count').textContent = list.length ? `(${list.length})` : '';
}

function renderSummary(m) {
  const b = m.bounds;
  $('#summary').innerHTML = `
    <span>${b.w} × ${b.h} × ${b.d} מ"מ</span>
    <span>${m.parts.length} חלקים</span>
    <span>${m.hardware.reduce((n, h) => n + (h.qty || 1), 0)} פריטי פרזול</span>`;
}

function showPart(p) {
  const box = $('#part');
  if (!p) { box.innerHTML = '<p class="muted">הקשה על לוח מציגה את מידותיו. גרירה מסובבת; שתי אצבעות או גלגלת מזמנות.</p>'; return; }
  const c = cutSize(p);
  const m = M.material(p.material);
  const edges = Object.entries(p.edges || {}).filter(([, v]) => v).map(([k]) => ({ front: 'חזית', back: 'אחור', top: 'עליון', bottom: 'תחתון', left: 'שמאל', right: 'ימין' }[k])).join(', ') || 'ללא';
  const grain = { x: 'לרוחב', y: 'לגובה', z: 'לעומק' }[p.grain];
  box.innerHTML = `
    <h3>${p.name}</h3>
    <dl>
      <dt>חיתוך</dt><dd><b>${c.l} × ${c.w}</b> × ${c.t} מ"מ</dd>
      <dt>חומר</dt><dd>${m.name}</dd>
      <dt>סיבים</dt><dd>${grain}</dd>
      <dt>קנט</dt><dd>${edges}</dd>
      <dt>מיקום</dt><dd>x ${Math.round(p.box.x)} · y ${Math.round(p.box.y)} · z ${Math.round(p.box.z)}</dd>
      ${p.note ? `<dt>הערה</dt><dd>${p.note}</dd>` : ''}
    </dl>`;
}

function renderOutput(m) {
  const cl = cutList(m);
  const hw = hardwareList(m);
  const rows = cl.boards
    .sort((a, b) => b.l * b.w - a.l * a.w)
    .map((r) => `<tr><td>${r.name}</td><td>${r.qty}</td><td dir="ltr">${r.l} × ${r.w}</td><td>${r.t}</td><td>${r.material}</td><td>${r.edges}</td><td>${r.note || ''}</td></tr>`)
    .join('');
  const glass = cl.glass.map((g) => `<tr><td>${g.name}</td><td>${g.qty}</td><td dir="ltr">${g.l} × ${g.w}</td><td>${g.t}</td></tr>`).join('');
  const hwRows = hw.map((h) => `<tr><td>${h.name}</td><td>${h.qty}</td></tr>`).join('');
  const area = cl.boards.reduce((s, r) => s + r.qty * r.l * r.w, 0) / 1e6;

  $('#output-body').innerHTML = `
    <h3>רשימת חיתוך <small>${cl.boards.reduce((s, r) => s + r.qty, 0)} לוחות · ${area.toFixed(2)} מ"ר נטו</small></h3>
    <table><thead><tr><th>חלק</th><th>כמות</th><th>אורך × רוחב</th><th>עובי</th><th>חומר</th><th>קנט</th><th>הערה</th></tr></thead><tbody>${rows}</tbody></table>
    ${glass ? `<h3>זכוכית</h3><table><thead><tr><th>סוג</th><th>כמות</th><th>מידה</th><th>עובי</th></tr></thead><tbody>${glass}</tbody></table>` : ''}
    ${hwRows ? `<h3>פרזול</h3><table><thead><tr><th>פריט</th><th>כמות</th></tr></thead><tbody>${hwRows}</tbody></table>` : ''}
    <p class="muted">האורך הוא בכיוון הסיבים. מידות אחרי הורדת הקנט כשנבחר "יורדת מהמידה".</p>`;
}

// three.js נטען בדף לפני המודול; אם אף מקור לא נענה — הודעה במקום קנבס ריק.
if (window.THREE) init();
else {
  $('#stage-error').hidden = false;
}
