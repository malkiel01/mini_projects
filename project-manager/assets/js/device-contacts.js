// אנשי הקשר במכשיר — ייבוא לאנשי הקשר של האפליקציה.
//
// אנדרואיד (כרום): בורר אנשי הקשר של המכשיר (Contact Picker API) — רואים את כל
// אנשי הקשר בטלפון, מסמנים, ומייבאים בתור לקוחות / ספקים / עורכי דין.
// אייפון ומחשב: הדפדפן לא נותן גישה לאנשי הקשר — מייבאים קובץ vCard (‎.vcf):
// באייפון, באנשי הקשר ← איש קשר ← "שיתוף איש קשר" ← "שמירה בקבצים", ואז "📄 מקובץ".
// מי שכבר קיים אצלך (אותו טלפון או אימייל) — מסומן "קיים" ולא מיובא שוב.
'use strict';

const DeviceContacts = (() => {
    const supported = 'contacts' in navigator && 'select' in (navigator.contacts || {});
    let picked = [], known = { phones: new Set(), emails: new Set() }, bound = false;

    const norm = p => { const d = String(p || '').replace(/\D/g, ''); return d.startsWith('972') ? '0' + d.slice(3) : d; };
    const cleanPhone = p => String(p || '').replace(/[^0-9+ \-]/g, '').trim();

    function bind() {
        if (bound) return;
        bound = true;
        $('#import-pick').addEventListener('click', pick);
        $('#import-vcf').addEventListener('change', e => { readVcf([...e.target.files]); e.target.value = ''; });
        $('#import-dlg form').addEventListener('submit', e => { e.preventDefault(); importNow(); });
        $$('#import-dlg [name=ikind]').forEach(r => r.addEventListener('change', kindChanged));
        $('#import-all').addEventListener('change', e => { picked.forEach(c => { if (!c.exists) c.on = e.target.checked; }); render(); });
        $('#import-list').addEventListener('change', e => {
            const cb = e.target.closest('[data-i]');
            if (cb) { picked[Number(cb.dataset.i)].on = cb.checked; count(); }
        });
        // בעורך: מילוי כרטיס חדש מאיש קשר אחד במכשיר
        $('#fill-from-device').addEventListener('click', fillEditor);
    }

    /** הכפתור 📱 בלשונית אנשי הקשר. בטלפון עם בורר — נפתח ישר. */
    async function open() {
        bind();
        const dlg = $('#import-dlg');
        picked = [];
        formError($('form', dlg), '');
        const kind = location.hash.split('/')[2] || 'client';
        $(`#import-dlg [name=ikind][value=${KIND_LABEL[kind] ? kind : 'client'}]`).checked = true;
        $('#import-trades').innerHTML = state.trades.map(t => `<label><input type="checkbox" value="${t.id}"><span>${esc(t.name)}</span></label>`).join('');
        $('#import-pick').hidden = !supported;
        $('#import-hint').textContent = supported
            ? 'נפתחים אנשי הקשר של המכשיר — מסמנים ומייבאים. אפשר לבחור כמה פעמים.'
            : 'הדפדפן הזה לא נותן גישה לאנשי הקשר. מייבאים קובץ vCard: באייפון — אנשי הקשר ← איש קשר ← "שיתוף איש קשר" ← "שמירה בקבצים"; באנדרואיד — אנשי הקשר ← ייצוא.';
        kindChanged();
        await loadKnown();
        render();
        dlg.showModal();
        if (supported) pick();
    }

    function kindChanged() {
        $('#import-trades-box').hidden = $('#import-dlg [name=ikind]:checked').value !== 'supplier';
    }

    /** הטלפונים והאימיילים שכבר אצלך — מכל הסוגים. */
    async function loadKnown() {
        known = { phones: new Set(), emails: new Set() };
        for (const k of Object.keys(KIND_LABEL)) {
            let list = [];
            try { list = await contactsOf(k); } catch { /* ריק */ }
            for (const c of list) {
                if (c.owner_id !== state.user.id) continue;
                [c.phone, c.whatsapp].map(norm).filter(Boolean).forEach(p => known.phones.add(p));
                if (c.email) known.emails.add(c.email.toLowerCase());
            }
        }
    }

    function add(list) {
        for (const c of list) {
            if (!c.name && !c.phone && !c.email) continue;
            const key = norm(c.phone) || c.email.toLowerCase() || c.name;
            if (picked.some(x => (norm(x.phone) || x.email.toLowerCase() || x.name) === key)) continue;
            c.exists = (c.phone && known.phones.has(norm(c.phone))) || (c.email && known.emails.has(c.email.toLowerCase()));
            c.on = !c.exists;
            picked.push(c);
        }
        render();
    }

    async function pick() {
        try {
            const want = ['name', 'tel', 'email'];
            const have = await navigator.contacts.getProperties?.().catch(() => want) || want;
            const props = want.concat(have.includes('address') ? ['address'] : []).filter(p => have.includes(p));
            const res = await navigator.contacts.select(props, { multiple: true });
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
            more: (c.tel?.length || 0) > 1 ? c.tel.slice(1).map(cleanPhone).join(', ') : '',
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
            if (k === 'BEGIN') { cur = { name: '', n: '', phone: '', email: '', company: '', address: '', more: '' }; continue; }
            if (!cur) continue;
            if (k === 'END') { cur.name = cur.name || cur.n; if (cur.name || cur.phone || cur.email) out.push(cur); cur = null; continue; }
            const val = unesc(/QUOTED-PRINTABLE/i.test(params) ? qp(raw) : raw);
            if (k === 'FN') cur.name = val;
            else if (k === 'N' && !cur.n) cur.n = val.split(';').slice(0, 2).reverse().filter(Boolean).join(' ').trim();
            else if (k === 'TEL') { if (!cur.phone) cur.phone = cleanPhone(val); else cur.more = [cur.more, cleanPhone(val)].filter(Boolean).join(', '); }
            else if (k === 'EMAIL' && !cur.email) cur.email = val;
            else if (k === 'ORG' && !cur.company) cur.company = val.split(';').filter(Boolean).join(' ');
            else if (k === 'ADR' && !cur.address) cur.address = val.split(';').filter(Boolean).join(', ');
        }
        return out;
    }

    function render() {
        $('#import-list').innerHTML = picked.map((c, i) => `<li class="card static">
            <input type="checkbox" class="pick" data-i="${i}" ${c.on ? 'checked' : ''} aria-label="ייבוא ${esc(c.name || c.phone)}">
            ${avatar(c.name || c.phone)}
            <div class="body"><div class="l1"><span class="one">${esc(c.name || c.phone || c.email)}</span>${c.exists ? '<span class="chip">כבר קיים</span>' : ''}</div>
                <div class="l2 one">${[c.phone && `<span dir="ltr">${esc(c.phone)}</span>`, c.email && esc(c.email), c.company && esc(c.company)].filter(Boolean).join('<i class="sep" aria-hidden="true">·</i>') || '&nbsp;'}</div></div>
        </li>`).join('');
        $('#import-all-box').hidden = !picked.length;
        count();
    }

    function count() {
        const n = picked.filter(c => c.on).length;
        $('#import-count').textContent = `${n} מתוך ${picked.length} נבחרו`;
        $('#import-go').disabled = !n;
        $('#import-go').textContent = n ? `ייבוא ${n}` : 'ייבוא';
    }

    async function importNow() {
        const form = $('#import-dlg form');
        const kind = $('#import-dlg [name=ikind]:checked').value;
        const contacts = picked.filter(c => c.on).map(c => ({ name: c.name, phone: c.phone, email: c.email, company: c.company, address: c.address }));
        if (!contacts.length) return;
        const trades = $$('#import-trades input:checked').map(cb => Number(cb.value));
        await busy($('#import-go'), form, async () => {
            const r = await api('contacts-import', { body: { kind, trades, contacts } });
            invalidateContacts(kind);
            $('#import-dlg').close();
            toast([`יובאו ${r.created}`, r.skipped ? `${r.skipped} כבר היו` : '', r.errors.length ? `${r.errors.length} לא יובאו` : ''].filter(Boolean).join(' · '), !!r.errors.length);
            if (r.errors.length) console.warn(r.errors);
            location.hash = '#/contacts/' + kind;
            Contacts.show(kind);
        });
    }

    /** עורך כרטיס חדש: איש קשר אחד מהמכשיר ממלא שם, טלפון, אימייל וכתובת. */
    async function fillEditor() {
        try {
            const [c] = await navigator.contacts.select(['name', 'tel', 'email'], { multiple: false });
            if (!c) return;
            const x = fromPicker(c), el = $('#form').elements;
            if (x.name) el.name.value = x.name;
            if (x.phone) el.phone.value = x.phone;
            if (x.email) el.email.value = x.email;
        } catch { /* בוטל */ }
    }

    /** בעורך — הכפתור מוצג רק בכרטיס חדש ורק כשיש בורר. */
    function editorReady(isNew) {
        bind();
        $('#fill-from-device').hidden = !(isNew && supported);
    }

    return { open, editorReady, parseVcf };
})();
