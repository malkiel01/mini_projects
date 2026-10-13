// אנשי קשר: לקוחות, ספקים ועורכי דין — רשימה ועורך (שלב 1).
'use strict';

const Contacts = (() => {
    // משפטים שלמים לכל סוג — בשפות אחרות המין והסדר משתנים
    // i18n-ok-start
    const NEW_LABEL = { client: 'לקוח חדש', supplier: 'ספק חדש', lawyer: 'עורך דין חדש' };
    const EDIT_LABEL = { client: 'עריכת לקוח', supplier: 'עריכת ספק', lawyer: 'עריכת עורך דין' };
    const ADDED_LABEL = { client: 'לקוח נוסף', supplier: 'ספק נוסף', lawyer: 'עורך דין נוסף' };
    // i18n-ok-end
    let kind = 'client', list = [], editing = null, rating = 0, onSaved = null, bound = false;

    function bind() {
        if (bound) return;
        bound = true;
        let timer;
        $('#q').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(load, 250); });
        $('#show-archived').addEventListener('change', load);
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
        $('#contact-ledger').addEventListener('click', () => Finance.ledger(null, editing.id)); // כ5 — כל הפרויקטים
        langField();
        $('#stars').addEventListener('click', e => {
            const b = e.target.closest('button');
            if (!b) return;
            const n = Number(b.dataset.n);
            rating = rating === n ? 0 : n;
            renderStars();
        });
    }

    /** בחירת השפה של איש הקשר (הפורטל והמיילים שלו) — נבנית כאן, אחרי שדה האימייל. */
    function langField() {
        const form = $('#form');
        if (form.elements.lang) return;
        const label = document.createElement('label');
        label.className = 'f';
        label.innerHTML = `<span>${esc(t('שפה'))}</span><select name="lang" data-no-i18n>${
            Object.entries(I18n.LANGS).map(([k, v]) => `<option value="${k}">${esc(v.name)}</option>`).join('')}</select>`;
        const after = form.elements.email?.closest('label');
        if (after) after.after(label);
        else $('.fields', form)?.appendChild(label);
    }

    function show(k) {
        bind();
        kind = KIND_LABEL[k] ? k : 'client';
        $$('#kinds [data-kind]').forEach(a => a.setAttribute('aria-selected', String(a.dataset.kind === kind)));
        $('#trade-filter').hidden = kind !== 'supplier';
        $('#add').setAttribute('aria-label', t(NEW_LABEL[kind]));
        $('#device-contacts').href = `#/contacts/${kind}/import`;
        fillTradeFilter();
        load();
    }

    async function load() {
        const k = kind;
        try {
            const { contacts } = await api('contacts', { params: {
                kind: k, q: $('#q').value.trim(), archived: $('#show-archived').checked ? 1 : 0,
            } });
            if (k !== kind) return; // הלשונית התחלפה בזמן הטעינה
            list = contacts;
            render();
        } catch (e) { toast(e.message, true); }
    }

    const LAWYER_PREFIX = /^עו"ד\s*/; // i18n-none: קידומת שהמשתמש אולי כתב בשם
    const tradeName = id => t(state.trades.find(tr => tr.id === id)?.name || '');

    function fillTradeFilter() {
        const sel = $('#trade-filter');
        const cur = sel.value;
        sel.innerHTML = options(state.trades.map(tr => ({ value: tr.id, label: t(tr.name) })), cur, t('כל התחומים'));
    }

    function render() {
        const tradeId = Number($('#trade-filter').value) || 0;
        const rows = list.filter(c => !tradeId || c.trades.includes(tradeId));
        if (!rows.length) {
            const q = $('#q').value.trim();
            const archived = $('#show-archived').checked;
            $('#list').innerHTML = `<p class="empty">${esc(q || tradeId ? t('לא נמצאו תוצאות') : archived ? t('אין כרטיסים בארכיון.')
                : t('עוד אין כרטיסים — "+ חדש" או "📱 ייבוא".'))}</p>`;
            return;
        }
        $('#list').innerHTML = rows.map(c => {
            const l2 = [
                ...c.trades.map(id => esc(tradeName(id))),
                c.kind === 'client' && c.lawyer_name ? esc(t('עורך דין {name}', { name: c.lawyer_name.replace(LAWYER_PREFIX, '') })) : '',
                c.phone ? `<span dir="ltr">${esc(c.phone)}</span>` : (c.email ? esc(c.email) : ''),
            ].filter(Boolean).join('<i class="sep" aria-hidden="true">·</i>');
            const stars = c.rating ? `<span class="rating" aria-label="${esc(t('דירוג {n} מתוך 5', { n: c.rating }))}">${'★'.repeat(c.rating)}</span>` : '';
            return `<article class="card" data-id="${c.id}" tabindex="0" role="button" aria-label="${esc(c.name)}">
                ${avatar(c.name)}
                <div class="body">
                    <div class="l1">${esc(c.name)}${c.company ? `<span class="sub">${esc(c.company)}</span>` : ''}${stars}</div>
                    ${l2 ? `<div class="l2">${l2}</div>` : ''}
                    ${c.shared?.length ? `<div class="l2 shared-from">🤝 ${esc(t('מפרויקט משותף: {names}', { names: c.shared.join(', ') }))}</div>` : ''}
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
        $('#form-title').textContent = t(c ? EDIT_LABEL[kk] : NEW_LABEL[kk]);
        $$('.k-client, .k-supplier, .k-lawyer', form).forEach(el => { el.hidden = !el.classList.contains('k-' + kk); });
        $('.lbl-tax', form).textContent = t(kk === 'client' ? 'תעודת זהות' : kk === 'lawyer' ? 'מספר רישיון / ח.פ.' : 'ח.פ. / ע.מ.');
        for (const f of ['name', 'company', 'tax_id', 'phone', 'whatsapp', 'email', 'address', 'notes']) form.elements[f].value = c?.[f] || '';
        form.elements.lang.value = I18n.LANGS[c?.lang] ? c.lang : I18n.lang();
        for (const f of ['bank', 'branch', 'account', 'holder']) form.elements['bank.' + f].value = c?.bank?.[f] || '';
        form.elements.vat_exempt.checked = !!c?.vat_exempt;
        if (kk === 'supplier') {
            const mine = new Set(c?.trades || []);
            $('#trade-chips').innerHTML = state.trades.map(tr =>
                `<label><input type="checkbox" value="${tr.id}" ${mine.has(tr.id) ? 'checked' : ''}><span>${esc(t(tr.name))}</span></label>`).join('');
            rating = c?.rating || 0;
            renderStars();
        }
        if (kk === 'client') await fillClientSettings(c);
        $('#contact-ledger').hidden = !(c && kk === 'supplier');
        const arch = $('#archive');
        // כרטיס מפרויקט משותף: רואים ועורכים; רק מי שיצר אותו מעביר לארכיון
        const foreign = !!c && c.owner_id !== state.user.id;
        $('#contact-shared').hidden = !foreign;
        if (foreign) $('#contact-shared').textContent = '🤝 ' + (c.shared?.length ? t('מפרויקט משותף: {names}. מוצג כאן כל עוד יש לך גישה לפרויקט.', { names: c.shared.join(', ') }) : t('כרטיס של מנהל אחר'));
        arch.hidden = !c || foreign;
        if (c) {
            arch.textContent = c.archived ? t('החזרה מהארכיון') : t('העברה לארכיון');
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
            s.lawyer_id, t('ללא'));
        form.querySelector(`[name="settings.approval_method"][value="${s.approval_method}"]`).checked = true;
        form.querySelector(`[name="settings.default_payer"][value="${s.default_payer}"]`).checked = true;
        $$('[name="settings.channels"]', form).forEach(cb => { cb.checked = s.channels.includes(cb.value); });
    }

    function renderStars() {
        $('#stars').innerHTML = [1, 2, 3, 4, 5].map(n =>
            `<button type="button" role="radio" data-n="${n}" aria-checked="${n <= rating}" aria-label="${esc(t('{n} מתוך 5', { n }))}">★</button>`).join('');
    }

    function collect() {
        const form = $('#form');
        const k = editing.kind;
        const v = name => form.elements[name].value.trim();
        const body = { kind: k, id: editing.id || undefined };
        for (const f of ['name', 'company', 'tax_id', 'phone', 'whatsapp', 'email', 'address', 'notes']) body[f] = v(f);
        body.lang = v('lang');
        if (k === 'supplier') {
            body.bank = Object.fromEntries(['bank', 'branch', 'account', 'holder'].map(f => [f, v('bank.' + f)]));
            body.trades = $$('#trade-chips input:checked').map(cb => Number(cb.value));
            body.rating = rating;
            body.vat_exempt = form.elements.vat_exempt.checked;
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
        if (!body.name) { formError(form, t('שם: שדה חובה')); return; }
        await busy($('#save'), form, async () => {
            const { contact } = await api('contact', { body });
            invalidateContacts(body.kind);
            $('#editor').close();
            toast(t(body.id ? 'נשמר' : ADDED_LABEL[body.kind]));
            if (onSaved) onSaved(contact);
            else load();
        });
    }

    async function toggleArchive() {
        const c = editing;
        if (!c?.id) return;
        if (!c.archived && !confirm(t('להעביר את "{name}" לארכיון? אפשר להחזיר בכל עת.', { name: c.name }))) return;
        await busy(null, $('#form'), async () => {
            await api('archive', { body: { id: c.id, archived: !c.archived } });
            invalidateContacts(c.kind);
            $('#editor').close();
            toast(c.archived ? t('הוחזר מהארכיון') : t('הועבר לארכיון'));
            load();
        });
    }

    return { show, open, fillTradeFilter };
})();

/** כפתורי חיוג / וואטסאפ / מייל לשורה של איש קשר. */
function contactActs(c) {
    const wa = c.whatsapp || c.phone;
    return [
        c.phone ? `<a class="icon-btn" href="tel:${esc(digits(c.phone))}" aria-label="${esc(t('חיוג ל{name}', { name: c.name }))}">📞</a>` : '',
        wa ? `<a class="icon-btn" href="https://wa.me/${esc(waNumber(wa))}" target="_blank" rel="noopener" aria-label="${esc(t('וואטסאפ ל{name}', { name: c.name }))}">💬</a>` : '',
        !c.phone && c.email ? `<a class="icon-btn" href="mailto:${esc(c.email)}" aria-label="${esc(t('אימייל ל{name}', { name: c.name }))}">✉️</a>` : '',
    ].join('');
}
