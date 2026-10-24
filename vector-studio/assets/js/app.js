// וקטורי — דף הפתיחה: מי נכנס, הטיוטות במכשיר, וציור חדש.

import { SIZES } from './model.js';
import * as drafts from './drafts.js';
import * as library from './library.js';

const $ = id => document.getElementById(id);
const esc = (s) => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

async function api(action) {
  const res = await fetch('api.php?action=' + encodeURIComponent(action), { credentials: 'same-origin' });
  const data = await res.json().catch(() => ({ success: false, error: 'תשובה לא תקינה מהשרת' }));
  if (!data.success) throw Object.assign(new Error(data.error || 'שגיאה'), { status: res.status });
  return data;
}

function initials(name) {
  return name.trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('');
}

function when(iso) {
  const d = new Date(iso);
  if (isNaN(d)) return '';
  const today = new Date().toDateString() === d.toDateString();
  return today ? d.toLocaleTimeString('he-IL', { hour: '2-digit', minute: '2-digit' }) : d.toLocaleDateString('he-IL');
}

async function showLibrary() {
  const ul = $('drafts');
  const local = drafts.list();
  let items;
  try { items = await library.list(); } catch (e) {
    ul.innerHTML = `<li class="empty error">${esc(e.message)}</li>`;
    return;
  }
  const rows = items.map(d => `<li class="card" data-id="${d.id}">
      <a class="use-row" href="editor.html#d${d.id}">
        ${d.thumb ? `<img class="thumb" src="${library.thumbUrl(d.id, d.rev)}" alt="" loading="lazy">` : '<span class="use-ic" aria-hidden="true">✒️</span>'}
        <span class="use-tx"><b class="one">${esc(d.name)}</b><small>${esc(when(d.updated))}</small></span>
      </a>
      <button class="icon-btn" data-del aria-label="מחיקת ${esc(d.name)}">🗑️</button>
    </li>`);
  // טיוטות ישנות מהמכשיר — עוברות לספרייה כשפותחים אותן
  rows.push(...local.map(d => `<li class="card" data-local="${esc(d.id)}">
      <a class="use-row" href="editor.html#${encodeURIComponent(d.id)}">
        <span class="use-ic" aria-hidden="true">📱</span>
        <span class="use-tx"><b class="one">${esc(d.name)}</b><small>במכשיר בלבד · ייכנס לספרייה בפתיחה</small></span>
      </a>
      <button class="icon-btn" data-del aria-label="מחיקת ${esc(d.name)}">🗑️</button>
    </li>`));
  ul.innerHTML = rows.length ? rows.join('') : '<li class="empty">עוד אין ציורים. "+ ציור חדש" כדי להתחיל.</li>';
}

function newDrawing(hash) {
  location.href = 'editor.html#' + hash;
}

function setupNew() {
  const dlg = $('new-dlg');
  $('sizes').innerHTML = SIZES.map(s => `<button class="tile" type="button" data-size="${s.id}"><span class="tile-ic" aria-hidden="true">${s.icon}</span>${esc(s.name)}</button>`).join('');
  $('new-btn').addEventListener('click', () => dlg.showModal());
  $('open-btn').addEventListener('click', () => $('open-file').click());
  $('open-file').addEventListener('change', async () => {
    const f = $('open-file').files[0];
    $('open-file').value = '';
    if (!f) return;
    $('open-err').hidden = true;
    try {
      const { id, notes } = await library.importMbe(f);
      if (notes.length) alert('נפתח. שימו לב:\n' + notes.join('\n'));
      location.href = 'editor.html#d' + id;
    } catch (e) {
      $('open-err').textContent = 'הפתיחה נכשלה: ' + e.message;
      $('open-err').hidden = false;
    }
  });
  $('sizes').addEventListener('click', e => { const b = e.target.closest('[data-size]'); if (b) newDrawing('new:' + b.dataset.size); });
  $('custom-go').addEventListener('click', () => {
    const w = Number($('cw').value), h = Number($('ch').value);
    if (!(w > 0 && w <= 20000 && h > 0 && h <= 20000)) { $('cw').focus(); return; }
    newDrawing(`new:custom:${w}:${h}`);
  });
  document.addEventListener('click', e => { const b = e.target.closest('[data-close]'); if (b) b.closest('dialog').close(); });
  $('drafts').addEventListener('click', async e => {
    const b = e.target.closest('[data-del]');
    if (!b) return;
    const li = b.closest('.card');
    if (li.dataset.local) {
      if (!confirm('למחוק את הטיוטה מהמכשיר?')) return;
      drafts.remove(li.dataset.local);
    } else {
      if (!confirm('למחוק את הציור? הוא והגרסאות שלו יימחקו מהשרת.')) return;
      try { await library.remove(Number(li.dataset.id)); } catch (err) { alert('המחיקה נכשלה: ' + err.message); return; }
    }
    showLibrary();
  });
}

async function start() {
  try {
    const { user } = await api('me');
    const me = $('me');
    me.textContent = initials(user.name);
    me.title = user.name;
    me.setAttribute('aria-label', 'מחובר: ' + user.name);
    me.hidden = false;
    setupNew();
    await showLibrary();
    $('home').hidden = false;
  } catch (e) {
    $('denied-msg').textContent = e.message;
    $('denied').hidden = false;
  } finally {
    $('loading').hidden = true;
  }
}

start();
