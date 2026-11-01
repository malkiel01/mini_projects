// וקטורי — דף הפתיחה: מי נכנס, הטיוטות במכשיר, וציור חדש.

import { SIZES } from './model.js';
import * as drafts from './drafts.js';
import * as library from './library.js';
import { acceptHandoff } from './handoff.js';
import { initDialogs, confirm } from './ui-core/dialog.js';
import { toast } from './ui-core/toast.js';
import { accountMenu, accountServer } from './ui-core/account.js';

const $ = id => document.getElementById(id);
const esc = (s) => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

async function api(action, body) {
  const opt = { credentials: 'same-origin' };
  if (body) Object.assign(opt, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  const res = await fetch('api.php?action=' + encodeURIComponent(action), opt);
  const data = await res.json().catch(() => ({ success: false, error: 'תשובה לא תקינה מהשרת' }));
  if (!data.success) throw Object.assign(new Error(data.error || 'שגיאה'), { status: res.status });
  return data;
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
  $('open-file').addEventListener('change', () => {
    const f = $('open-file').files[0];
    $('open-file').value = '';
    if (f) openFile(f);
  });
  $('sizes').addEventListener('click', e => { const b = e.target.closest('[data-size]'); if (b) newDrawing('new:' + b.dataset.size); });
  $('custom-go').addEventListener('click', () => {
    const w = Number($('cw').value), h = Number($('ch').value);
    if (!(w > 0 && w <= 20000 && h > 0 && h <= 20000)) { $('cw').focus(); return; }
    newDrawing(`new:custom:${w}:${h}`);
  });
  initDialogs();
  $('drafts').addEventListener('click', async e => {
    const b = e.target.closest('[data-del]');
    if (!b) return;
    const li = b.closest('.card');
    if (li.dataset.local) {
      if (!await confirm('למחוק את הטיוטה מהמכשיר?', { ok: 'מחיקה', danger: true })) return;
      drafts.remove(li.dataset.local);
    } else {
      if (!await confirm('למחוק את הציור? הוא והגרסאות שלו יימחקו מהשרת.', { ok: 'מחיקה', danger: true })) return;
      try { await library.remove(Number(li.dataset.id)); } catch (err) { toast('המחיקה נכשלה: ' + err.message, true); return; }
    }
    showLibrary();
  });
}

/** קובץ ‎.mbe שנפתח "באמצעות" האפליקציה המותקנת (file_handlers), או מכפתור הפתיחה. */
async function openFile(f, handoff = null) {
  $('open-err').hidden = true;
  try {
    const { id, notes } = await library.importMbe(f);
    if (notes.length && !handoff) try { sessionStorage.setItem('vs-notes', notes.join(' · ')); } catch { /* חסום */ }
    // מאפליקציה אחרת — העורך יציע "↩ חזרה" (הפרמטר נשאר בכתובת, ו-opener נשאר בניווט באותו חלון)
    location.href = 'editor.html' + (handoff ? '?mbe-handoff=' + encodeURIComponent(handoff.id) : '') + '#d' + id;
  } catch (e) {
    $('open-err').textContent = 'הפתיחה נכשלה: ' + e.message;
    $('open-err').hidden = false;
  }
}

function setupInstall() {
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('service-worker.js').catch(() => { /* בלי מטמון — עדיין עובד ברשת */ });
  if ('launchQueue' in window) {
    window.launchQueue.setConsumer(async (params) => {
      for (const h of params.files || []) { await openFile(await h.getFile()); break; }
    });
  }
  if (location.hash === '#new') { history.replaceState(null, '', location.pathname); $('new-dlg').showModal(); }
}

async function start() {
  try {
    const { user } = await api('me');
    // 👤 תפריט המשתמש (ui-core): הגדרות אישיות וערכה כהה, ייצוא כל הנתונים ומחיקת החשבון. היציאה — מהכלים
    // הדיגיטליים (השער), לא מכאן.
    const server = await accountServer((action, body) => api(action, body || {})).catch(() => null);
    accountMenu(document.querySelector('header.top'), {
      user: () => ({ name: user.name, email: user.email, admin: user.role === 'admin' }),
      server,
      // 📁 "המסמכים שלי" — עם files-window של ui-core (שורה 20); הספרייה היא הדף הזה עצמו
    });
    setupNew();
    await showLibrary();
    setupInstall();
    $('home').hidden = false;
  } catch (e) {
    $('denied-msg').textContent = e.message;
    $('denied').hidden = false;
  } finally {
    $('loading').hidden = true;
  }
}

// נפתחנו מאפליקציה אחרת (?mbe-handoff=<id>) — ready מיד, לפני כל דבר כבד; הקובץ נפתח כמו מ-📂
acceptHandoff((h) => openFile(h.file, h));
start();
