// העימוד — דף הפתיחה: מי אני, והספרייה בשרת (רשימה, מסמך חדש, מחיקה).

const $ = (s, el = document) => el.querySelector(s);

const ERRORS = {
  title_required: 'צריך שם למסמך',
  title_too_long: 'השם ארוך מדי',
  bad_size: 'גודל העמוד לא תקין — בין 50 ל-1200 מ"מ',
  no_user: 'אין משתמש — צריך להיכנס דרך הכלים הדיגיטליים',
  not_found: 'המסמך לא נמצא',
  network: 'אין חיבור לשרת',
};
const errText = code => ERRORS[code] || 'משהו השתבש (' + code + ')';

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

function toast(msg, err = false) {
  const el = $('.toast');
  el.textContent = msg;
  el.classList.toggle('err', err);
  el.hidden = false;
  clearTimeout(toast.t);
  toast.t = setTimeout(() => { el.hidden = true; }, 2600);
}

// --vvh: הגובה הנראה בלי המקלדת — הגיליון מתקצר והכול נגלל
(() => {
  const vv = window.visualViewport;
  if (!vv) return;
  const set = () => document.documentElement.style.setProperty('--vvh', Math.round(vv.height) + 'px');
  vv.addEventListener('resize', set);
  set();
})();

document.addEventListener('click', e => {
  const b = e.target.closest('[data-close]');
  if (b) b.closest('dialog').close();
});

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
      <div class="use-row">
        <span class="page-ic" aria-hidden="true"></span>
        <span class="use-tx"><b class="one"></b><small></small></span>
      </div>
      <button class="icon-btn" type="button" aria-label="מחיקה">🗑️</button>`;
    const ic = $('.page-ic', li);
    const short = sizeLabel(d.page).split(' ')[0];
    ic.textContent = short.length <= 3 ? short : '';   // A4 / A5 / B5 — מידה ארוכה לא נכנסת בצלמית
    if (d.page[0] > d.page[1]) ic.classList.add('wide');   // לרוחב — הצלמית שוכבת
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
  if (!confirm(`למחוק את "${doc.title}"?`)) return;
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
    await api('doc_create', data);
    dlg.close();
    toast('המסמך נוצר');
    load();
  } catch (err) {
    dlgErr.textContent = errText(err.message);
    dlgErr.hidden = false;
  } finally {
    btn.disabled = false;
  }
});

// ---- התחלה ----
(async () => {
  try {
    const { me } = await api('me');
    $('#who').textContent = me.name || me.email;
    $('#new-btn').disabled = false;
    load();
  } catch (e) {
    $('#load-err').textContent = errText(e.message);
    $('#load-err').hidden = false;
    list.setAttribute('aria-busy', 'false');
  }
})();
