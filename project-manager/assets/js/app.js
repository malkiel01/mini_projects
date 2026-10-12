// ניהול פרויקטים — ממשק המנהלים. שלב 1: כרטיסי לקוחות, ספקים ועורכי דין.
// הזהות מהפלטפורמה; אסימון ה-CSRF מגיע מ-api.php?action=me.
'use strict';

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

const KIND_LABEL = { client: 'לקוח', supplier: 'ספק', lawyer: 'עורך דין' };
const state = { user: null, trades: [], tab: 'client', contacts: [], editing: null, rating: 0 };

// ---- שרת ----

async function api(action, { params = {}, body = null } = {}) {
    const qs = new URLSearchParams({ action, ...params });
    const opts = { headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    if (body) {
        opts.method = 'POST';
        opts.headers['Content-Type'] = 'application/json';
        opts.headers['X-CSRF'] = state.user?.csrf || '';
        opts.body = JSON.stringify(body);
    }
    const res = await fetch('api.php?' + qs, opts);
    let data = {};
    try { data = await res.json(); } catch { /* תשובה שאינה JSON */ }
    if (res.status === 401) { location.href = '../?next=' + encodeURIComponent(location.pathname); throw new Error(data.error || 'נדרשת כניסה'); }
    if (!res.ok) throw new Error(data.error || `תקלה (${res.status})`);
    return data;
}

function toast(msg, isErr = false) {
    const t = $('#toast');
    t.textContent = msg;
    t.classList.toggle('err', isErr);
    t.hidden = false;
    clearTimeout(toast.timer);
    toast.timer = setTimeout(() => { t.hidden = true; }, 3000);
}

const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const digits = s => String(s || '').replace(/[^\d+]/g, '');
// וואטסאפ דורש מספר בינלאומי: 050… → 97250…
const waNumber = s => { const d = digits(s).replace(/^\+/, ''); return d.startsWith('0') ? '972' + d.slice(1) : d; };

// ---- התחלה ----

async function init() {
    try {
        const { user, trades } = await api('me');
        state.user = user;
        state.trades = trades;
    } catch (e) {
        $('#main').innerHTML = `<p class="empty">${esc(e.message)}</p>`;
        return;
    }
    const isDev = state.user.role === 'developer';
    $('#who').innerHTML = `<span class="name">${esc(state.user.display_name)}</span>`
        + `<span class="badge">${isDev ? 'מפתח' : 'מנהל פרויקט'}</span>`;
    $$('.dev-only').forEach(el => { el.hidden = !isDev; });
    fillTradeFilter();
    bind();
    const saved = (() => { try { return localStorage.getItem('pm.tab'); } catch { return null; } })();
    showTab(saved && $(`[data-tab="${saved}"]`) && (saved !== 'trades' || isDev) ? saved : 'client');
}

function bind() {
    $('#tabs').addEventListener('click', e => {
        const b = e.target.closest('button[data-tab]');
        if (b) showTab(b.dataset.tab);
    });
    let timer;
    $('#q').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(loadContacts, 250); });
    $('#show-archived').addEventListener('click', e => {
        const b = e.currentTarget;
        b.setAttribute('aria-pressed', String(b.getAttribute('aria-pressed') !== 'true'));
        loadContacts();
    });
    $('#trade-filter').addEventListener('change', renderList);
    $('#add').addEventListener('click', () => openEditor(null));
    $('#list').addEventListener('click', e => {
        if (e.target.closest('a')) return; // קישור טלפון / וואטסאפ / מייל
        const card = e.target.closest('.card');
        if (card) openEditor(Number(card.dataset.id));
    });
    $('#list').addEventListener('keydown', e => {
        const card = e.target.closest('.card');
        if (card && e.target === card && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openEditor(Number(card.dataset.id)); }
    });
    $('#form').addEventListener('submit', e => { e.preventDefault(); save(); });
    $('#cancel').addEventListener('click', () => $('#editor').close());
    $('#archive').addEventListener('click', toggleArchive);
    $('#stars').addEventListener('click', e => {
        const b = e.target.closest('button');
        if (!b) return;
        const n = Number(b.dataset.n);
        state.rating = state.rating === n ? 0 : n;
        renderStars();
    });
    $('#trade-add').addEventListener('submit', async e => {
        e.preventDefault();
        const name = $('#trade-name').value.trim();
        if (!name) return;
        try {
            ({ trades: state.trades } = await api('trade', { body: { name } }));
            $('#trade-name').value = '';
            renderTrades();
        } catch (err) { toast(err.message, true); }
    });
    $('#trade-list').addEventListener('click', tradeAction);
}

function showTab(tab) {
    state.tab = tab;
    try { localStorage.setItem('pm.tab', tab); } catch { /* מצב פרטי */ }
    $$('#tabs button').forEach(b => b.setAttribute('aria-selected', String(b.dataset.tab === tab)));
    const isTrades = tab === 'trades';
    $('#contacts-view').hidden = isTrades;
    $('#trades-view').hidden = !isTrades;
    if (isTrades) { renderTrades(); return; }
    $('#trade-filter').hidden = tab !== 'supplier';
    $('#add').setAttribute('aria-label', KIND_LABEL[tab] + ' חדש');
    loadContacts();
}

// ---- רשימה ----

async function loadContacts() {
    const kind = state.tab;
    if (kind === 'trades') return;
    try {
        const { contacts } = await api('contacts', { params: {
            kind, q: $('#q').value.trim(), archived: $('#show-archived').getAttribute('aria-pressed') === 'true' ? 1 : 0,
        } });
        if (kind !== state.tab) return; // הלשונית התחלפה בזמן הטעינה
        state.contacts = contacts;
        renderList();
    } catch (e) { toast(e.message, true); }
}

function tradeName(id) { return state.trades.find(t => t.id === id)?.name || ''; }

function fillTradeFilter() {
    const sel = $('#trade-filter');
    const cur = sel.value;
    sel.innerHTML = '<option value="">כל התחומים</option>'
        + state.trades.map(t => `<option value="${t.id}">${esc(t.name)}</option>`).join('');
    sel.value = cur;
}

function renderList() {
    const tradeId = Number($('#trade-filter').value) || 0;
    const rows = state.contacts.filter(c => !tradeId || c.trades.includes(tradeId));
    const isDev = state.user.role === 'developer';
    if (!rows.length) {
        const q = $('#q').value.trim();
        $('#list').innerHTML = `<p class="empty">${q || tradeId ? 'לא נמצאו תוצאות' : 'עוד אין כרטיסים כאן. לחץ "+ חדש" כדי להתחיל.'}</p>`;
        return;
    }
    $('#list').innerHTML = rows.map(c => {
        const wa = c.whatsapp || c.phone;
        const acts = [
            c.phone ? `<a class="icon-btn" href="tel:${esc(digits(c.phone))}" aria-label="חיוג ל${esc(c.name)}">📞</a>` : '',
            wa ? `<a class="icon-btn" href="https://wa.me/${esc(waNumber(wa))}" target="_blank" rel="noopener" aria-label="וואטסאפ ל${esc(c.name)}">💬</a>` : '',
            !c.phone && c.email ? `<a class="icon-btn" href="mailto:${esc(c.email)}" aria-label="אימייל ל${esc(c.name)}">✉️</a>` : '',
        ].join('');
        const l2 = [
            ...c.trades.map(id => esc(tradeName(id))),
            c.kind === 'client' && c.lawyer_name ? 'עו"ד ' + esc(c.lawyer_name.replace(/^עו"ד\s*/, '')) : '',
            c.phone ? `<span dir="ltr">${esc(c.phone)}</span>` : (c.email ? esc(c.email) : ''),
            isDev && c.owner_name && c.owner_id !== state.user.id ? 'של ' + esc(c.owner_name) : '',
        ].filter(Boolean).join('<i class="sep" aria-hidden="true">·</i>');
        const stars = c.rating ? `<span class="rating" aria-label="דירוג ${c.rating} מתוך 5">${'★'.repeat(c.rating)}</span>` : '';
        return `<article class="card" data-id="${c.id}" tabindex="0" role="button" aria-label="${esc(c.name)}">
            <div class="body">
                <div class="l1">${esc(c.name)}${c.company ? `<span class="sub">${esc(c.company)}</span>` : ''}${stars}</div>
                ${l2 ? `<div class="l2">${l2}</div>` : ''}
            </div>
            <div class="acts">${acts}</div>
        </article>`;
    }).join('');
}

// ---- עורך הכרטיס ----

async function openEditor(id) {
    const form = $('#form');
    form.reset();
    $('#form-error').hidden = true;
    let c = null;
    if (id) {
        try { ({ contact: c } = await api('contact', { params: { id } })); }
        catch (e) { toast(e.message, true); return; }
    }
    const kind = c?.kind || state.tab;
    state.editing = c ? c : { kind };
    $('#form-title').textContent = (c ? 'עריכת ' : '') + KIND_LABEL[kind] + (c ? '' : ' חדש');
    $$('.k-client, .k-supplier, .k-lawyer', form).forEach(el => { el.hidden = !el.classList.contains('k-' + kind); });
    $('.lbl-tax', form).textContent = kind === 'client' ? 'ת"ז' : kind === 'lawyer' ? 'מספר רישיון / ח.פ.' : 'ח.פ. / ע.מ.';

    for (const f of ['name', 'company', 'tax_id', 'phone', 'whatsapp', 'email', 'address', 'notes']) {
        form.elements[f].value = c?.[f] || '';
    }
    for (const f of ['bank', 'branch', 'account', 'holder']) form.elements['bank.' + f].value = c?.bank?.[f] || '';

    if (kind === 'supplier') {
        const mine = new Set(c?.trades || []);
        $('#trade-chips').innerHTML = state.trades.map(t =>
            `<label><input type="checkbox" value="${t.id}" ${mine.has(t.id) ? 'checked' : ''}><span>${esc(t.name)}</span></label>`).join('');
        state.rating = c?.rating || 0;
        renderStars();
    }
    if (kind === 'client') await fillClientSettings(c);

    const arch = $('#archive');
    arch.hidden = !c;
    if (c) {
        arch.textContent = c.archived ? 'החזרה מהארכיון' : 'העברה לארכיון';
        arch.classList.toggle('danger', !c.archived);
    }
    $('#editor').showModal();
    form.elements.name.focus();
}

async function fillClientSettings(c) {
    const form = $('#form');
    const s = c?.settings || { lawyer_id: null, approval_method: 'click', channels: ['email', 'whatsapp'], default_payer: 'client' };
    const sel = form.elements['settings.lawyer_id'];
    // רק עורכי הדין של המנהל שהלקוח שייך לו
    let lawyers = [];
    try { ({ contacts: lawyers } = await api('contacts', { params: { kind: 'lawyer' } })); } catch { /* הרשימה תישאר ריקה */ }
    const owner = c?.owner_id ?? state.user.id;
    lawyers = lawyers.filter(l => l.owner_id === owner);
    sel.innerHTML = '<option value="">ללא</option>'
        + lawyers.map(l => `<option value="${l.id}">${esc(l.name)}${l.company ? ' · ' + esc(l.company) : ''}</option>`).join('');
    sel.value = s.lawyer_id ? String(s.lawyer_id) : '';
    form.querySelector(`[name="settings.approval_method"][value="${s.approval_method}"]`).checked = true;
    form.querySelector(`[name="settings.default_payer"][value="${s.default_payer}"]`).checked = true;
    $$('[name="settings.channels"]', form).forEach(cb => { cb.checked = s.channels.includes(cb.value); });
}

function renderStars() {
    $('#stars').innerHTML = [1, 2, 3, 4, 5].map(n =>
        `<button type="button" role="radio" data-n="${n}" aria-checked="${n <= state.rating}" aria-label="${n} מתוך 5">★</button>`).join('');
}

function collect() {
    const form = $('#form');
    const kind = state.editing.kind;
    const v = name => form.elements[name].value.trim();
    const body = { kind, id: state.editing.id || undefined };
    for (const f of ['name', 'company', 'tax_id', 'phone', 'whatsapp', 'email', 'address', 'notes']) body[f] = v(f);
    if (kind === 'supplier') {
        body.bank = Object.fromEntries(['bank', 'branch', 'account', 'holder'].map(f => [f, v('bank.' + f)]));
        body.trades = $$('#trade-chips input:checked').map(cb => Number(cb.value));
        body.rating = state.rating;
    }
    if (kind === 'client') {
        body.settings = {
            lawyer_id: Number(v('settings.lawyer_id')) || null,
            approval_method: form.querySelector('[name="settings.approval_method"]:checked')?.value || 'click',
            default_payer: form.querySelector('[name="settings.default_payer"]:checked')?.value || 'client',
            channels: $$('[name="settings.channels"]:checked', form).map(cb => cb.value),
        };
    }
    return body;
}

async function save() {
    const body = collect();
    if (!body.name) { showFormError('שם: שדה חובה'); return; }
    const btn = $('#save');
    btn.disabled = true;
    try {
        await api('contact', { body });
        $('#editor').close();
        toast(body.id ? 'נשמר' : KIND_LABEL[body.kind] + ' נוסף');
        loadContacts();
    } catch (e) { showFormError(e.message); }
    finally { btn.disabled = false; }
}

function showFormError(msg) {
    const el = $('#form-error');
    el.textContent = msg;
    el.hidden = false;
    el.scrollIntoView({ block: 'nearest' });
}

async function toggleArchive() {
    const c = state.editing;
    if (!c?.id) return;
    if (!c.archived && !confirm(`להעביר את "${c.name}" לארכיון? אפשר להחזיר בכל עת.`)) return;
    try {
        await api('archive', { body: { id: c.id, archived: !c.archived } });
        $('#editor').close();
        toast(c.archived ? 'הוחזר מהארכיון' : 'הועבר לארכיון');
        loadContacts();
    } catch (e) { showFormError(e.message); }
}

// ---- תחומים (מפתח) ----

function renderTrades() {
    fillTradeFilter();
    $('#trade-list').innerHTML = state.trades.map(t => `<li data-id="${t.id}">
        <span class="n">${esc(t.name)}</span><small>${t.used ? t.used + ' ספקים' : ''}</small>
        <button class="icon-btn" data-act="rename" aria-label="שינוי שם ${esc(t.name)}">✏️</button>
        <button class="icon-btn" data-act="delete" aria-label="מחיקת ${esc(t.name)}" ${t.used ? 'disabled' : ''}>🗑️</button></li>`).join('');
}

async function tradeAction(e) {
    const b = e.target.closest('button[data-act]');
    if (!b) return;
    const id = Number(b.closest('li').dataset.id);
    const t = state.trades.find(x => x.id === id);
    try {
        if (b.dataset.act === 'rename') {
            const name = prompt('שם התחום', t.name);
            if (!name || name.trim() === t.name) return;
            ({ trades: state.trades } = await api('trade', { body: { id, name: name.trim() } }));
        } else {
            if (!confirm(`למחוק את התחום "${t.name}"?`)) return;
            ({ trades: state.trades } = await api('trade-delete', { body: { id } }));
        }
        renderTrades();
    } catch (err) { toast(err.message, true); }
}

init();
