// עיצוב רסטר — דף הפתיחה: מי אני, והספרייה בשרת (רשימה, תמונה חדשה, מחיקה).

import { api, errText, toast, dims } from './api.js';
import { idbSet } from './idb.js';
import { acceptHandoff } from './handoff.js';
import { mountAccount } from './account-ui.js';
import { createDrives } from './drives/drives.js';
import { $ } from './ui-core/dom.js';
import { initDialogs, confirm } from './ui-core/dialog.js';

// נפתחנו מאפליקציה אחרת בחבילה (?mbe-handoff=): ready יוצא מיד, והקובץ שמגיע נפתח בעורך כמו קובץ מהמכשיר
acceptHandoff(async ({ id, file }) => {
  try {
    await idbSet('pending-open', file);
    location.href = 'editor.html?open=pending&mbe-handoff=' + encodeURIComponent(id);
  } catch {
    toast('לא הצלחתי להעביר את הקובץ לעורך', true);
  }
});

// חלונות: --vvh / --kb (המקלדת), ✕ ולחיצה ברקע סוגרים — ui-core
initDialogs();

// ---- גודל קנבס ----
const PRESETS = { screen: [1920, 1080], square: [1080, 1080], story: [1080, 1920], a4: [1240, 1754] };
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
      <a class="use-row">
        <span class="canvas-ic" aria-hidden="true"><i></i></span>
        <span class="use-tx"><b class="one"></b><small></small></span>
        <span class="use-go" aria-hidden="true">‹</span>
      </a>
      <button class="icon-btn" type="button" aria-label="מחיקה">🗑️</button>`;
    // הצלמית ביחס הקנבס: הצלע הארוכה 30px
    const [w, h] = d.size;
    const k = 30 / Math.max(w, h);
    const ic = $('.canvas-ic i', li);
    ic.style.inlineSize = Math.max(4, Math.round(w * k)) + 'px';
    ic.style.blockSize = Math.max(4, Math.round(h * k)) + 'px';
    // נשמר בשרת — התמונה הממוזערת מתוך ה-‎.mbe (preview.png של הגרסה האחרונה)
    if (d.version) {
      ic.style.backgroundImage = `url("api.php?action=doc_preview&id=${d.id}&v=${d.version}")`;
      ic.classList.add('has-preview');
    }
    $('a', li).href = 'editor.html?doc=' + d.id;
    $('b', li).textContent = d.title;
    $('small', li).append(dims(d.size), ' · ' + dateFmt.format(new Date(d.updated_at)) + (d.version ? ' · גרסה ' + d.version : ' · לא נשמר'));
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
  if (!(await confirm(`למחוק את "${doc.title}"?`, { ok: 'מחיקה', danger: true }))) return;
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

// ---- פתיחת תמונה מהמכשיר ----
// הקובץ עובר לעורך דרך IndexedDB (קובץ גדול לא נכנס לכתובת או ל-sessionStorage)
$('#open-btn').addEventListener('click', () => $('#file').click());
$('#file').addEventListener('change', async e => {
  const f = e.target.files[0];
  e.target.value = '';
  if (!f) return;
  try {
    await idbSet('pending-open', f);
    location.href = 'editor.html?open=pending';
  } catch {
    toast('לא הצלחתי להעביר את הקובץ לעורך', true);
  }
});

// ---- התחלה ----
(async () => {
  try {
    const { me } = await api('me');
    $('#who').textContent = me.name || me.email;
    $('#new-btn').disabled = false;
    load();
    // 👤 העיגול בסרגל: הגדרות אישיות, "המסמכים שלי" (הספרייה + ענן החבילה), החשבון
    mountAccount($('header.top'), { api, me, drives: createDrives({ endpoint: 'api.php' }) }).catch(() => {});
  } catch (e) {
    $('#load-err').textContent = errText(e.message);
    $('#load-err').hidden = false;
    list.setAttribute('aria-busy', 'false');
  }
})();
