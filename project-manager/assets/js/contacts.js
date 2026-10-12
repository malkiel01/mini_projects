// אנשי קשר: לקוחות, ספקים ועורכי דין — רשימה ועורך (שלב 1).
'use strict';

const Contacts = (() => {
    let kind = 'client', list = [], editing = null, rating = 0, onSaved = null, bound = false;

    function bind() {
        if (bound) return;
        bound = true;
        let timer;
        $('#q').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(load, 250); });
        $('#show-archived').addEventListener('click', e => {
            const b = e.currentTarget;
            b.setAttribute('aria-pressed', String(b.getAttribute('aria-pressed') !== 'true'));
            load();
        });
        $('#trade-filter').addEventListener('change', render);
        $('#add').addEventListener('click', () => open(null, kind));
        $('#list').addEventListener('click', e => {
            if (e.target.closest('a')) return; // קישור טלפון / וואטסאפ / מייל
            const card = e.target.closest('.card');
            if (card) open(Number(card.dataset.id));
        });
        $('#list').addEventListener('keydown', e => {
            const card = e.target.closest('.card');
            if (card && e.target === card && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); open(Number(card.dataset.id)); }
        });
        $('#form').addEventListener('submit', e => { e.preventDefault(); save(); });
        $('#archive').addEventListener('click', toggleArchive);
        $('#stars').addEventListener('click', e => {
            const b = e.target.closest('button');
            if (!b) return;
            const n = Number(b.dataset.n);
            rating = rating === n ? 0 : n;
            renderStars();
        });
    }

    function show(k) {
        bind();
        kind = KIND_LABEL[k] ? k : 'client';
        $$('#kinds [data-kind]').forEach(a => a.setAttribute('aria-selected', String(a.dataset.kind === kind)));
        $('#trade-filter').hidden = kind !== 'supplier';
        $('#add').setAttribute('aria-label', KIND_LABEL[kind] + ' חדש');
        fillTradeFilter();
        load();
    }

    async function load() {
        const k = kind;
        try {
            const { contacts } = await api('contacts', { params: {
                kind: k, q: $('#q').value.trim(), archived: $('#show-archived').getAttribute('aria-pressed') === 'true' ? 1 : 0,
            } });
            if (k !== kind) return; // הלשונית התחלפה בזמן הטעינה
            list = contacts;
            render();
        } catch (e) { toast(e.message, true); }
    }

    const tradeName = id => state.trades.find(t => t.id === id)?.name || '';

    function fillTradeFilter() {
        const sel = $('#trade-filter');
        const cur = sel.value;
        sel.innerHTML = options(state.trades.map(t => ({ value: t.id, label: t.name })), cur, 'כל התחומים');
    }

    function render() {
        const tradeId = Number($('#trade-filter').value) || 0;
        const rows = list.filter(c => !tradeId || c.trades.includes(tradeId));
        if (!rows.length) {
            const q = $('#q').value.trim();
            $('#list').innerHTML = `<p class="empty">${q || tradeId ? 'לא נמצאו תוצאות' : 'עוד אין כרטיסים כאן. לחץ "+ חדש" כדי להתחיל.'}</p>`;
            return;
        }
        $('#list').innerHTML = rows.map(c => {
            const l2 = [
                ...c.trades.map(id => esc(tradeName(id))),
                c.kind === 'client' && c.lawyer_name ? 'עו"ד ' + esc(c.lawyer_name.replace(/^עו"ד\s*/, '')) : '',
                c.phone ? `<span dir="ltr">${esc(c.phone)}</span>` : (c.email ? esc(c.email) : ''),
                isDev() && c.owner_name && c.owner_id !== state.user.id ? 'של ' + esc(c.owner_name) : '',
            ].filter(Boolean).join('<i class="sep" aria-hidden="true">·</i>');
            const stars = c.rating ? `<span class="rating" aria-label="דירוג ${c.rating} מתוך 5">${'★'.repeat(c.rating)}</span>` : '';
            return `<article class="card" data-id="${c.id}" tabindex="0" role="button" aria-label="${esc(c.name)}">
                ${avatar(c.name)}
                <div class="body">
                    <div class="l1">${esc(c.name)}${c.company ? `<span class="sub">${esc(c.company)}</span>` : ''}${stars}</div>
                    ${l2 ? `<div class="l2">${l2}</div>` : ''}
                </div>
                <div class="acts">${contactActs(c)}</div>
            </article>`;
        }).join('');
    }

    // ---- עורך ----

    /** פתיחת העורך. k — סוג לכרטיס חדש; after — נקרא עם הכרטיס אחרי שמירה. */
    async function open(id, k = kind, after = null) {
        bind();
        const form = $('#form');
        form.reset();
        formError(form, '');
        onSaved = after;
        let c = null;
        if (id) {
            try { ({ contact: c } = await api('contact', { params: { id } })); }
            catch (e) { toast(e.message, true); return; }
        }
        const kk = c?.kind || k;
        editing = c || { kind: kk };
        $('#form-title').textContent = (c ? 'עריכת ' : '') + KIND_LABEL[kk] + (c ? '' : ' חדש');
        $$('.k-client, .k-supplier, .k-lawyer', form).forEach(el => { el.hidden = !el.classList.contains('k-' + kk); });
        $('.lbl-tax', form).textContent = kk === 'client' ? 'ת"ז' : kk === 'lawyer' ? 'מספר רישיון / ח.פ.' : 'ח.פ. / ע.מ.';
        for (const f of ['name', 'company', 'tax_id', 'phone', 'whatsapp', 'email', 'address', 'notes']) form.elements[f].value = c?.[f] || '';
        for (const f of ['bank', 'branch', 'account', 'holder']) form.elements['bank.' + f].value = c?.bank?.[f] || '';
        if (kk === 'supplier') {
            const mine = new Set(c?.trades || []);
            $('#trade-chips').innerHTML = state.trades.map(t =>
                `<label><input type="checkbox" value="${t.id}" ${mine.has(t.id) ? 'checked' : ''}><span>${esc(t.name)}</span></label>`).join('');
            rating = c?.rating || 0;
            renderStars();
        }
        if (kk === 'client') await fillClientSettings(c);
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
        let lawyers = [];
        try { lawyers = await contactsOf('lawyer'); } catch { /* הרשימה תישאר ריקה */ }
        const owner = c?.owner_id ?? state.user.id;
        form.elements['settings.lawyer_id'].innerHTML = options(
            lawyers.filter(l => l.owner_id === owner).map(l => ({ value: l.id, label: l.name + (l.company ? ' · ' + l.company : '') })),
            s.lawyer_id, 'ללא');
        form.querySelector(`[name="settings.approval_method"][value="${s.approval_method}"]`).checked = true;
        form.querySelector(`[name="settings.default_payer"][value="${s.default_payer}"]`).checked = true;
        $$('[name="settings.channels"]', form).forEach(cb => { cb.checked = s.channels.includes(cb.value); });
    }

    function renderStars() {
        $('#stars').innerHTML = [1, 2, 3, 4, 5].map(n =>
            `<button type="button" role="radio" data-n="${n}" aria-checked="${n <= rating}" aria-label="${n} מתוך 5">★</button>`).join('');
    }

    function collect() {
        const form = $('#form');
        const k = editing.kind;
        const v = name => form.elements[name].value.trim();
        const body = { kind: k, id: editing.id || undefined };
        for (const f of ['name', 'company', 'tax_id', 'phone', 'whatsapp', 'email', 'address', 'notes']) body[f] = v(f);
        if (k === 'supplier') {
            body.bank = Object.fromEntries(['bank', 'branch', 'account', 'holder'].map(f => [f, v('bank.' + f)]));
            body.trades = $$('#trade-chips input:checked').map(cb => Number(cb.value));
            body.rating = rating;
        }
        if (k === 'client') {
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
        const form = $('#form');
        const body = collect();
        if (!body.name) { formError(form, 'שם: שדה חובה'); return; }
        await busy($('#save'), form, async () => {
            const { contact } = await api('contact', { body });
            invalidateContacts(body.kind);
            $('#editor').close();
            toast(body.id ? 'נשמר' : KIND_LABEL[body.kind] + ' נוסף');
            if (onSaved) onSaved(contact);
            else load();
        });
    }

    async function toggleArchive() {
        const c = editing;
        if (!c?.id) return;
        if (!c.archived && !confirm(`להעביר את "${c.name}" לארכיון? אפשר להחזיר בכל עת.`)) return;
        await busy(null, $('#form'), async () => {
            await api('archive', { body: { id: c.id, archived: !c.archived } });
            invalidateContacts(c.kind);
            $('#editor').close();
            toast(c.archived ? 'הוחזר מהארכיון' : 'הועבר לארכיון');
            load();
        });
    }

    return { show, open, fillTradeFilter };
})();

/** כפתורי חיוג / וואטסאפ / מייל לשורה של איש קשר. */
function contactActs(c) {
    const wa = c.whatsapp || c.phone;
    return [
        c.phone ? `<a class="icon-btn" href="tel:${esc(digits(c.phone))}" aria-label="חיוג ל${esc(c.name)}">📞</a>` : '',
        wa ? `<a class="icon-btn" href="https://wa.me/${esc(waNumber(wa))}" target="_blank" rel="noopener" aria-label="וואטסאפ ל${esc(c.name)}">💬</a>` : '',
        !c.phone && c.email ? `<a class="icon-btn" href="mailto:${esc(c.email)}" aria-label="אימייל ל${esc(c.name)}">✉️</a>` : '',
    ].join('');
}
