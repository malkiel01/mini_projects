// וקטורי — דף הפתיחה: מי נכנס, הטיוטות במכשיר, וציור חדש.

import { SIZES } from './model.js';
import * as drafts from './drafts.js';

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

function showDrafts() {
  const list = drafts.list();
  const ul = $('drafts');
  if (!list.length) {
    ul.innerHTML = '<li class="empty">עוד אין ציורים. "+ ציור חדש" כדי להתחיל.</li>';
    return;
  }
  ul.innerHTML = list.map(d => {
    const doc = drafts.load(d.id)?.doc;
    const s = doc?.surfaces?.[0];
    const size = s ? `${Math.round(s.size[0])}×${Math.round(s.size[1])}` : '';
    const count = doc ? doc.surfaces.reduce((n, s) => n + (s.layers || []).reduce((m, l) => m + (l.paths?.length || 0), 0), 0) : 0;
    return `<li class="card" data-id="${esc(d.id)}">
      <a class="use-row" href="editor.html#${encodeURIComponent(d.id)}">
        <span class="use-ic" aria-hidden="true">✒️</span>
        <span class="use-tx"><b class="one">${esc(d.name)}</b><small>${esc(size)} · ${count} אובייקטים · ${esc(when(d.updated))}</small></span>
      </a>
      <button class="icon-btn" data-del aria-label="מחיקת ${esc(d.name)}">🗑️</button>
    </li>`;
  }).join('');
}

function newDrawing(hash) {
  location.href = 'editor.html#' + hash;
}

function setupNew() {
  const dlg = $('new-dlg');
  $('sizes').innerHTML = SIZES.map(s => `<button class="tile" type="button" data-size="${s.id}"><span class="tile-ic" aria-hidden="true">${s.icon}</span>${esc(s.name)}</button>`).join('');
  $('new-btn').addEventListener('click', () => dlg.showModal());
  $('sizes').addEventListener('click', e => { const b = e.target.closest('[data-size]'); if (b) newDrawing('new:' + b.dataset.size); });
  $('custom-go').addEventListener('click', () => {
    const w = Number($('cw').value), h = Number($('ch').value);
    if (!(w > 0 && w <= 20000 && h > 0 && h <= 20000)) { $('cw').focus(); return; }
    newDrawing(`new:custom:${w}:${h}`);
  });
  document.addEventListener('click', e => { const b = e.target.closest('[data-close]'); if (b) b.closest('dialog').close(); });
  $('drafts').addEventListener('click', e => {
    const b = e.target.closest('[data-del]');
    if (!b) return;
    const li = b.closest('[data-id]');
    if (!confirm('למחוק את הציור מהמכשיר?')) return;
    drafts.remove(li.dataset.id);
    showDrafts();
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
    showDrafts();
    $('home').hidden = false;
  } catch (e) {
    $('denied-msg').textContent = e.message;
    $('denied').hidden = false;
  } finally {
    $('loading').hidden = true;
  }
}

start();
