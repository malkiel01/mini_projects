// עיצוב רסטר — דף הפתיחה: מי אני, והספרייה בשרת (רשימה, תמונה חדשה, מחיקה).

const $ = (s, el = document) => el.querySelector(s);

const ERRORS = {
  title_required: 'צריך שם לתמונה',
  title_too_long: 'השם ארוך מדי',
  bad_size: 'גודל הקנבס לא תקין — מספר שלם בין 1 ל-30000 פיקסלים',
  no_user: 'אין משתמש — צריך להיכנס דרך הכלים הדיגיטליים',
  not_found: 'התמונה לא נמצאה',
  network: 'אין חיבור לשרת',
};
const errText = code => ERRORS[code] || 'משהו השתבש (' + code + ')';

async function api(action, data = {}) {
  let res;
  try {
    res = await fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Raster-Studio': '1' },
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

// ---- גודל קנבס ----
const PRESETS = { screen: [1920, 1080], square: [1080, 1080], story: [1080, 1920], a4: [1240, 1754] };
// "1920×1080" בתוך טקסט עברי מתהפך ל-"1080×1920" — מבודדים אותו באלמנט LTR
function dims([w, h]) {
  const el = document.createElement('bdi');
  el.dir = 'ltr';
  el.textContent = w + '×' + h;
  return el;
}

const dateFmt = new Intl.DateTimeFormat('he-IL', { day: 'numeric', month: 'short', year: 'numeric' });

// ---- הרשימה ----
const list = $('#docs');

function render(docs) {
  list.replaceChildren();
  list.setAttribute('aria-busy', 'false');
  if (!docs.length) {
    const li = document.createElement('li');
    li.className = 'empty';
    li.textContent = 'עוד אין תמונות. "+ תמונה" כדי להתחיל.';
    list.append(li);
    return;
  }
  for (const d of docs) {
    const li = document.createElement('li');
    li.className = 'doc-row';
    li.innerHTML = `
      <div class="use-row">
        <span class="canvas-ic" aria-hidden="true"><i></i></span>
        <span class="use-tx"><b class="one"></b><small></small></span>
      </div>
      <button class="icon-btn" type="button" aria-label="מחיקה">🗑️</button>`;
    // הצלמית ביחס הקנבס: הצלע הארוכה 30px
    const [w, h] = d.size;
    const k = 30 / Math.max(w, h);
    const ic = $('.canvas-ic i', li);
    ic.style.inlineSize = Math.max(4, Math.round(w * k)) + 'px';
    ic.style.blockSize = Math.max(4, Math.round(h * k)) + 'px';
    $('b', li).textContent = d.title;
    $('small', li).append(dims(d.size), ' · ' + dateFmt.format(new Date(d.updated_at)));
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

// ---- תמונה חדשה ----
const dlg = $('#new-dlg');
const form = $('form', dlg);
const custom = $('.custom-size', dlg);
const dlgErr = $('.error', dlg);

function syncSize() {
  const p = PRESETS[form.size.value];
  custom.hidden = !!p;
  $('#size-dims').replaceChildren(...(p ? [dims(p), ' פיקסלים'] : []));
}
form.addEventListener('change', syncSize);

$('#new-btn').addEventListener('click', () => {
  form.reset();
  syncSize();
  dlgErr.hidden = true;
  dlg.showModal();
  form.title.focus();
});

form.addEventListener('submit', async e => {
  e.preventDefault();
  const btn = $('[type=submit]', form);
  const data = { title: form.title.value, size: form.size.value };
  if (data.size === 'custom') data.px = [form.w.value.trim(), form.h.value.trim()];
  btn.disabled = true;
  try {
    await api('doc_create', data);
    dlg.close();
    toast('התמונה נוצרה');
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
