// העימוד — דף הפתיחה: מי אני, והספרייה בשרת (רשימה, מסמך חדש, מחיקה). מסמך נפתח ב-editor.html.

import { $ } from './ui-core/dom.js';
import { toast } from './ui-core/toast.js';
import { initDialogs, confirm } from './ui-core/dialog.js';
import { mountAccount } from './account-ui.js';

initDialogs();   // [data-close], סגירה ברקע, --vvh / --kb (המקלדת בטלפון)

const ERRORS = {
  title_required: 'צריך שם למסמך',
  title_too_long: 'השם ארוך מדי',
  bad_size: 'גודל העמוד לא תקין — בין 50 ל-1200 מ"מ',
  no_user: 'אין משתמש — צריך להיכנס דרך הכלים הדיגיטליים',
  not_found: 'המסמך לא נמצא',
  network: 'אין חיבור לשרת',
  too_big: 'הקובץ גדול מדי',
  'mbe_kind:text': 'זה מסמך של עורך התמלילים. פתח מסמך עימוד וייבא אותו דרך "טקסט מ-Word או ‎.mbe"',
};
const errText = code => ERRORS[code]
  || (code.startsWith('mbe_kind:') ? 'זה קובץ ‎.mbe מסוג אחר (' + code.slice(9) + ') — העימוד פותח רק מסמכי עימוד'
  : code.startsWith('mbe_invalid') ? 'קובץ ה-‎.mbe פגום (' + code.slice(12) + ')'
  : code.startsWith('bad_model') ? 'העימוד עוד לא יודע לפתוח את המסמך הזה (' + code.slice(10) + ')'
  : 'משהו השתבש (' + code + ')');

async function api(action, data = {}) {
  let res;
  try {
    res = await fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Layout-Studio': '1' },
      body: JSON.stringify({ action, ...data }),
      credentials: 'same-origin',
    });
  } catch {
    throw new Error('network');
  }
  const out = await res.json().catch(() => ({ ok: false, error: 'server' }));
  if (!out.ok) throw new Error(out.error || 'server');
  return out;
}

// ---- גודל עמוד: pt → תווית ----
const PT_PER_MM = 72 / 25.4;
const SIZES = { A4: [210, 297], A5: [148, 210], B5: [176, 250], '17×24': [170, 240] };
function sizeLabel([wPt, hPt]) {
  const w = Math.round(wPt / PT_PER_MM), h = Math.round(hPt / PT_PER_MM);
  for (const [name, [a, b]] of Object.entries(SIZES)) {
    if (a === w && b === h) return name;
    if (a === h && b === w) return name + ' לרוחב';
  }
  return w + '×' + h + ' מ"מ';
}
// "170×240" בתוך טקסט עברי מתהפך ל-"240×170" — מבודדים אותו כ-LTR (LRI … PDI)
const ltrDims = s => s.replace(/\d+×\d+/g, m => '\u2066' + m + '\u2069');

const dateFmt = new Intl.DateTimeFormat('he-IL', { day: 'numeric', month: 'short', year: 'numeric' });

// ---- הרשימה ----
const list = $('#docs');

function render(docs) {
  list.replaceChildren();
  list.setAttribute('aria-busy', 'false');
  if (!docs.length) {
    const li = document.createElement('li');
    li.className = 'empty';
    li.textContent = 'עוד אין מסמכים. "+ מסמך" כדי להתחיל.';
    list.append(li);
    return;
  }
  for (const d of docs) {
    const li = document.createElement('li');
    li.className = 'doc-row';
    li.innerHTML = `
      <a class="use-row">
        <span class="page-ic" aria-hidden="true"></span>
        <span class="use-tx"><b class="one"></b><small></small></span>
        <span class="use-go" aria-hidden="true">‹</span>
      </a>
      <button class="icon-btn" type="button" aria-label="מחיקה">🗑️</button>`;
    const ic = $('.page-ic', li);
    const short = sizeLabel(d.page).split(' ')[0];
    ic.textContent = short.length <= 3 ? short : '';   // A4 / A5 / B5 — מידה ארוכה לא נכנסת בצלמית
    if (d.page[0] > d.page[1]) ic.classList.add('wide');   // לרוחב — הצלמית שוכבת
    $('a', li).href = 'editor.html?id=' + d.id;
    $('b', li).textContent = d.title;
    // bdi — כדי ש-"A5" או "17×24" לא יתערבבו עם התאריך העברי
    const size = document.createElement('bdi');
    size.textContent = ltrDims(sizeLabel(d.page));
    $('small', li).append(size, ' · ' + dateFmt.format(new Date(d.updated_at)));
    $('button', li).addEventListener('click', () => remove(d, li));
    list.append(li);
  }
}

async function load() {
  try {
    render((await api('docs_list')).docs);
    $('#load-err').hidden = true;
  } catch (e) {
    $('#load-err').textContent = errText(e.message);
    $('#load-err').hidden = false;
    list.setAttribute('aria-busy', 'false');
  }
}

async function remove(doc, li) {
  if (!await confirm(`למחוק את "${doc.title}"?`, { ok: 'מחיקה', danger: true })) return;
  try {
    await api('doc_delete', { id: doc.id });
    li.remove();
    if (!list.children.length) render([]);
    toast('נמחק');
  } catch (e) {
    toast(errText(e.message), true);
  }
}

// ---- מסמך חדש ----
const dlg = $('#new-dlg');
const form = $('form', dlg);
const custom = $('.custom-size', dlg);
const dlgErr = $('.error', dlg);

form.addEventListener('change', () => {
  custom.hidden = form.size.value !== 'custom';
});

$('#new-btn').addEventListener('click', () => {
  form.reset();
  custom.hidden = true;
  dlgErr.hidden = true;
  dlg.showModal();
  form.title.focus();
});

form.addEventListener('submit', async e => {
  e.preventDefault();
  const btn = $('[type=submit]', form);
  const data = { title: form.title.value, size: form.size.value };
  if (data.size === 'custom') data.mm = [form.w.value, form.h.value];
  btn.disabled = true;
  try {
    const { doc } = await api('doc_create', data);
    location.href = 'editor.html?id=' + doc.id;   // מסמך חדש — נפתח מיד
  } catch (err) {
    dlgErr.textContent = errText(err.message);
    dlgErr.hidden = false;
  } finally {
    btn.disabled = false;
  }
});

// ---- פתיחת ‎.mbe מהמכשיר: הקובץ עולה לשרת, נבדק בליבה ונכנס לספרייה כמסמך חדש ----
const mbeInput = $('#mbe-file');
$('#open-btn').addEventListener('click', () => mbeInput.click());
mbeInput.addEventListener('change', async () => {
  const file = mbeInput.files[0];
  mbeInput.value = '';
  if (!file) return;
  toast('פותח את הקובץ…');
  try {
    let res;
    try {
      res = await fetch('api.php?action=doc_import&name=' + encodeURIComponent(file.name), { method: 'POST', credentials: 'same-origin',
        headers: { 'X-Layout-Studio': '1', 'Content-Type': 'application/vnd.mbe+zip' }, body: file });
    } catch { throw new Error('network'); }
    const out = await res.json().catch(() => ({ ok: false, error: res.status === 413 ? 'too_big' : 'server' }));
    if (!out.ok) throw new Error(out.error || 'server');
    location.href = 'editor.html?id=' + out.doc.id;
  } catch (err) {
    toast(errText(err.message), true);
  }
});

// ---- התחלה ----
(async () => {
  try {
    const { me } = await api('me');
    $('#who').textContent = me.name || me.email;
    mountAccount($('header.top'), { api, me }).catch(() => {});   // 👤 — בלי לעכב את הספרייה
    $('#new-btn').disabled = false;
    $('#open-btn').disabled = false;
    load();
  } catch (e) {
    $('#load-err').textContent = errText(e.message);
    $('#load-err').hidden = false;
    list.setAttribute('aria-busy', 'false');
  }
})();
