// ייבוא מאנשי הקשר במכשיר — מסך משלו: #/contacts/<kind>/import.
//
// מסך ולא חלון, בכוונה: בורר אנשי הקשר של אנדרואיד נפתח מעל הדפדפן, והחזרה ממנו
// (או מחוות "חזרה") סגרה חלון פתוח — והבחירה אבדה. מסך עם כתובת שורד את זה, ומה
// שנבחר נשמר גם ב-sessionStorage למקרה שהטלפון טוען את הדף מחדש בזמן הבורר.
//
// אנדרואיד (כרום): "📱 בחירה מאנשי הקשר" — Contact Picker API.
// אייפון ומחשב: הדפדפן לא נותן גישה לאנשי הקשר — "📄 קובץ vCard" (‎.vcf).
// מי שכבר קיים אצלך (אותו טלפון או אימייל) מסומן "כבר קיים" ולא מיובא שוב.
'use strict';

const DeviceContacts = (() => {
    const supported = 'contacts' in navigator && 'select' in (navigator.contacts || {});
    const KEY = 'pm.import';
    let picked = [], known = { phones: new Set(), emails: new Set() }, bound = false;

    const norm = p => { const d = String(p || '').replace(/\D/g, ''); return d.startsWith('972') ? '0' + d.slice(3) : d; };
    const cleanPhone = p => String(p || '').replace(/[^0-9+ \-]/g, '').trim();
    const kindNow = () => $('#view-import [name=ikind]:checked')?.value || 'client';
    const persist = () => { try { sessionStorage.setItem(KEY, JSON.stringify(picked)); } catch { /* לא חובה */ } };

    function bind() {
        if (bound) return;
        bound = true;
        $('#import-pick').addEventListener('click', pick);
        $('#import-vcf').addEventListener('change', e => { readVcf([...e.target.files]); e.target.value = ''; });
        $('#import-go').addEventListener('click', importNow);
        $('#import-clear').addEventListener('click', () => { picked = []; persist(); render(); });
        $$('#view-import [name=ikind]').forEach(r => r.addEventListener('change', () => {
            // הכתובת עוקבת אחרי הסוג — כך "חזרה" מחזירה ללשונית הנכונה
            history.replaceState(null, '', `#/contacts/${r.value}/import`);
            $('#back').href = `#/contacts/${r.value}`;
            kindChanged();
        }));
        $('#import-all').addEventListener('change', e => { picked.forEach(c => { if (!c.exists) c.on = e.target.checked; }); persist(); render(); });
        $('#import-list').addEventListener('change', e => {
            const cb = e.target.closest('[data-i]');
            if (cb) { picked[Number(cb.dataset.i)].on = cb.checked; persist(); count(); }
        });
    }

    async function show(kind) {
        bind();
        $('#title').textContent = 'ייבוא אנשי קשר';
        document.title = 'ייבוא אנשי קשר';
        $(`#view-import [name=ikind][value=${KIND_LABEL[kind] ? kind : 'client'}]`).checked = true;
        $('#import-trade').innerHTML = options(state.trades.map(t => ({ value: t.id, label: t.name })), '', 'ללא');
        $('#import-pick').hidden = !supported;
        $('#import-hint').textContent = supported
            ? 'נפתחים אנשי הקשר של המכשיר — מסמנים, וחוזרים לכאן. אפשר לבחור כמה פעמים, ולהוסיף גם מקובץ.'
            : 'הדפדפן הזה לא נותן גישה ישירה לאנשי הקשר. באייפון: אנשי הקשר ← איש קשר ← "שיתוף איש קשר" ← "שמירה בקבצים", ואז "📄 קובץ vCard". באנדרואיד אפשר גם לייצא מאנשי הקשר קובץ ‎.vcf.';
        kindChanged();
        try { picked = JSON.parse(sessionStorage.getItem(KEY) || '[]'); } catch { picked = []; }
        await loadKnown();
        picked.forEach(c => { c.exists = isKnown(c); if (c.exists) c.on = false; });
        render();
    }

    function kindChanged() {
        $('#import-trade-box').hidden = kindNow() !== 'supplier';
        count();
    }

    /** הטלפונים והאימיילים שכבר אצלך — מכל הסוגים. */
    async function loadKnown() {
        known = { phones: new Set(), emails: new Set() };
        for (const k of Object.keys(KIND_LABEL)) {
            invalidateContacts(k);
            let list = [];
            try { list = await contactsOf(k); } catch { /* ריק */ }
            for (const c of list) {
                [c.phone, c.whatsapp].map(norm).filter(Boolean).forEach(p => known.phones.add(p));
                if (c.email) known.emails.add(c.email.toLowerCase());
            }
        }
    }

    const isKnown = c => (c.phone && known.phones.has(norm(c.phone))) || (c.email && known.emails.has(c.email.toLowerCase()));

    function add(list) {
        for (const c of list) {
            if (!c.name && !c.phone && !c.email) continue;
            const key = norm(c.phone) || c.email.toLowerCase() || c.name;
            if (picked.some(x => (norm(x.phone) || x.email.toLowerCase() || x.name) === key)) continue;
            c.exists = isKnown(c);
            c.on = !c.exists;
            picked.push(c);
        }
        persist();
        render();
    }

    async function pick() {
        try {
            const want = ['name', 'tel', 'email', 'address'];
            const have = await navigator.contacts.getProperties?.().catch(() => want) || want;
            const res = await navigator.contacts.select(want.filter(p => have.includes(p)), { multiple: true });
            add(res.map(fromPicker));
        } catch (e) {
            if (e?.name !== 'AbortError' && e?.name !== 'InvalidStateError') toast('אין גישה לאנשי הקשר: ' + (e?.message || e), true);
        }
    }

    function fromPicker(c) {
        const a = c.address?.[0];
        return {
            name: (c.name?.[0] || '').trim(), phone: cleanPhone(c.tel?.[0]), email: (c.email?.[0] || '').trim(), company: '',
            address: a ? [a.addressLine?.join(' '), a.city].filter(Boolean).join(', ') : '',
        };
    }

    async function readVcf(files) {
        for (const f of files) {
            try { add(parseVcf(await f.text())); }
            catch { toast(`${f.name}: לא קובץ vCard תקין`, true); }
        }
    }

    /** vCard 2.1 / 3.0 / 4.0 — שם, טלפון, אימייל, ארגון, כתובת. כולל QUOTED-PRINTABLE (ייצוא ישן מאנדרואיד). */
    function parseVcf(text) {
        const lines = text.replace(/\r\n/g, '\n').replace(/\n[ \t]/g, '').replace(/=\n/g, '').split('\n');
        const out = [];
        let cur = null;
        const qp = s => { try { return new TextDecoder().decode(new Uint8Array((s.match(/=[0-9A-F]{2}|[^=]/gi) || []).map(t => t.length === 3 ? parseInt(t.slice(1), 16) : t.charCodeAt(0)))); } catch { return s; } };
        const unesc = s => s.replace(/\\n/gi, ' ').replace(/\\([,;\\])/g, '$1').trim();
        for (const line of lines) {
            const m = line.match(/^(?:item\d+\.)?([A-Z-]+)((?:;[^:]*)?):(.*)$/i);
            if (!m) continue;
            const [, key, params, raw] = m;
            const k = key.toUpperCase();
            if (k === 'BEGIN') { cur = { name: '', n: '', phone: '', email: '', company: '', address: '' }; continue; }
            if (!cur) continue;
            if (k === 'END') { cur.name = cur.name || cur.n; delete cur.n; if (cur.name || cur.phone || cur.email) out.push(cur); cur = null; continue; }
            const val = unesc(/QUOTED-PRINTABLE/i.test(params) ? qp(raw) : raw);
            if (k === 'FN') cur.name = val;
            else if (k === 'N' && !cur.n) cur.n = val.split(';').slice(0, 2).reverse().filter(Boolean).join(' ').trim();
            else if (k === 'TEL' && !cur.phone) cur.phone = cleanPhone(val);
            else if (k === 'EMAIL' && !cur.email) cur.email = val;
            else if (k === 'ORG' && !cur.company) cur.company = val.split(';').filter(Boolean).join(' ');
            else if (k === 'ADR' && !cur.address) cur.address = val.split(';').filter(Boolean).join(', ');
        }
        return out;
    }

    function render() {
        $('#import-list').innerHTML = picked.map((c, i) => `<li class="card static">
            <input type="checkbox" class="pick" data-i="${i}" ${c.on ? 'checked' : ''} ${c.exists ? 'disabled' : ''} aria-label="ייבוא ${esc(c.name || c.phone)}">
            ${avatar(c.name || c.phone)}
            <div class="body"><div class="l1"><span class="one">${esc(c.name || c.phone || c.email)}</span>${c.exists ? '<span class="chip">כבר קיים</span>' : ''}</div>
                <div class="l2 one">${[c.phone && `<span dir="ltr">${esc(c.phone)}</span>`, c.email && esc(c.email), c.company && esc(c.company)].filter(Boolean).join('<i class="sep" aria-hidden="true">·</i>') || '&nbsp;'}</div></div>
        </li>`).join('');
        $('#import-all-box').hidden = !picked.some(c => !c.exists);
        $('#import-bar').hidden = !picked.length;
        count();
    }

    function count() {
        const n = picked.filter(c => c.on).length;
        $('#import-count').textContent = `${n} מתוך ${picked.filter(c => !c.exists).length} נבחרו`;
        $('#import-go').disabled = !n;
        $('#import-go').textContent = n ? `ייבוא ${n} ${KIND_LABEL[kindNow()] === 'לקוח' ? 'לקוחות' : KIND_LABEL[kindNow()] === 'ספק' ? 'ספקים' : 'עורכי דין'}` : 'ייבוא';
    }

    async function importNow() {
        const kind = kindNow();
        const contacts = picked.filter(c => c.on).map(c => ({ name: c.name, phone: c.phone, email: c.email, company: c.company, address: c.address }));
        if (!contacts.length) return;
        const trade = Number($('#import-trade').value) || 0;
        await busy($('#import-go'), null, async () => {
            const r = await api('contacts-import', { body: { kind, trades: kind === 'supplier' && trade ? [trade] : [], contacts } });
            invalidateContacts(kind);
            picked = [];
            persist();
            toast([`יובאו ${r.created}`, r.skipped ? `${r.skipped} כבר היו` : '', r.errors.length ? `לא יובאו: ${r.errors.join(', ')}` : ''].filter(Boolean).join(' · '), !!r.errors.length);
            // חזרה לרשימה — במקום מסך הייבוא, כך ש"חזרה" לא מחזירה אליו
            location.replace('#/contacts/' + kind);
        });
    }

    return { show, parseVcf };
})();
