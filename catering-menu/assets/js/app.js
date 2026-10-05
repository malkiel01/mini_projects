/* התפריט הציבורי: טעינת התפריט, סימון מנות, ושליחת ההזמנה לשרת.
   הסימון נשמר בדפדפן עד השליחה, כדי שרענון לא ימחק אותו. */
(() => {
    'use strict';

    const DRAFT_KEY = 'catering-menu:draft';
    const $ = (sel, root = document) => root.querySelector(sel);

    let menu = null;
    const byId = new Map();          // מזהה מנה → { item, cat }
    let selected = new Set();

    /* ── אחסון מקומי (עלול להיחסם במצב פרטי — לכן try) ─────────── */

    function loadDraft() {
        try { return JSON.parse(localStorage.getItem(DRAFT_KEY)) || {}; } catch { return {}; }
    }
    function saveDraft(extra = {}) {
        try {
            const prev = loadDraft();
            localStorage.setItem(DRAFT_KEY, JSON.stringify({ ...prev, ...extra, items: [...selected] }));
        } catch { /* לא נורא — רק נוחות */ }
    }
    function clearDraft() {
        try { localStorage.removeItem(DRAFT_KEY); } catch { /* */ }
    }

    function el(tag, attrs = {}, ...children) {
        const node = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs)) {
            if (k === 'class') node.className = v;
            else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
            else node.setAttribute(k, v);
        }
        for (const c of children) if (c != null) node.append(c);
        return node;
    }

    function toast(message) {
        const t = el('div', { class: 'toast', role: 'status' }, message);
        document.body.append(t);
        setTimeout(() => t.remove(), 2600);
    }

    /* ── ציור התפריט ─────────────────────────────────────────────── */

    function renderBrand() {
        const b = menu.business;
        const contact = $('#contact');
        contact.append(b.owner + ' · ');
        b.phones.forEach((p, i) => {
            if (i) contact.append(' / ');
            contact.append(el('a', { href: 'tel:' + p.replace(/\D/g, '') }, p));
        });
        if (menu.categories.some(c => c.items.some(i => i.extra))) {
            const note = $('#extraNote');
            note.textContent = '* ' + menu.extraNote;
            note.hidden = false;
        }
    }

    function renderMenu() {
        const main = $('#menu');
        const nav = $('#catNav');
        main.textContent = '';

        for (const cat of menu.categories) {
            nav.append(el('a', { class: 'cat-chip', href: '#cat-' + cat.id, 'data-cat': cat.id },
                cat.name, el('span', { class: 'badge', hidden: '' })));

            const list = el('ul', { class: 'items' });
            for (const item of cat.items) {
                byId.set(item.id, { item, cat });
                list.append(el('li', { class: 'item' },
                    el('button', {
                        type: 'button', class: 'item-btn', 'data-id': item.id,
                        'aria-pressed': String(selected.has(item.id)),
                    },
                    el('span', { class: 'tick', 'aria-hidden': 'true' }, '✓'),
                    el('span', { class: 'item-name' }, item.name),
                    item.extra ? el('span', { class: 'extra-tag' }, '* ' + menu.extraNote) : null)));
            }
            main.append(el('section', { class: 'cat', id: 'cat-' + cat.id },
                el('h2', { class: 'cat-title' }, cat.name), list));
        }

        main.addEventListener('click', e => {
            const btn = e.target.closest('.item-btn');
            if (!btn) return;
            toggle(btn.dataset.id);
        });
        refreshCounts();
        watchActiveCategory();
    }

    function toggle(id) {
        if (selected.has(id)) selected.delete(id); else selected.add(id);
        const btn = $(`.item-btn[data-id="${CSS.escape(id)}"]`);
        if (btn) btn.setAttribute('aria-pressed', String(selected.has(id)));
        if (navigator.vibrate) navigator.vibrate(8);
        saveDraft();
        refreshCounts();
    }

    function refreshCounts() {
        for (const cat of menu.categories) {
            const n = cat.items.filter(i => selected.has(i.id)).length;
            const badge = $(`.cat-chip[data-cat="${cat.id}"] .badge`);
            badge.textContent = n;
            badge.hidden = n === 0;
        }
        const total = selected.size;
        $('#orderBar').hidden = total === 0;
        $('#orderCount').innerHTML = `נבחרו <b>${total}</b> מנות`;
    }

    /** מדגיש בניווט את הקטגוריה שנמצאת כרגע במסך. */
    function watchActiveCategory() {
        const chips = [...document.querySelectorAll('.cat-chip')];
        const obs = new IntersectionObserver(entries => {
            for (const e of entries) {
                if (!e.isIntersecting) continue;
                const id = e.target.id.replace('cat-', '');
                chips.forEach(c => c.classList.toggle('active', c.dataset.cat === id));
                const active = chips.find(c => c.dataset.cat === id);
                active?.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' });
            }
        }, { rootMargin: '-40% 0px -55% 0px' });
        document.querySelectorAll('.cat').forEach(s => obs.observe(s));
    }

    /* ── גיליון ההזמנה ───────────────────────────────────────────── */

    function renderSummary() {
        const box = $('#summary');
        box.textContent = '';
        for (const cat of menu.categories) {
            const picked = cat.items.filter(i => selected.has(i.id));
            if (!picked.length) continue;
            box.append(el('div', { class: 'summary-cat' },
                el('h3', {}, `${cat.name} (${picked.length})`),
                el('ul', {}, ...picked.map(i => el('li', {},
                    i.name + (i.extra ? ' *' : ''),
                    el('button', {
                        type: 'button', 'aria-label': 'הסרת ' + i.name,
                        onclick: () => { toggle(i.id); selected.size ? renderSummary() : $('#orderSheet').close(); },
                    }, '✕'))))));
        }
    }

    function openOrder() {
        renderSummary();
        const form = $('#orderForm');
        const draft = loadDraft();
        for (const name of ['name', 'phone', 'deliveryDate', 'deliveryTime', 'guests', 'notes']) {
            if (draft[name] && !form.elements[name].value) form.elements[name].value = draft[name];
        }
        $('#formError').hidden = true;
        $('#orderSheet').showModal();
    }

    function formData() {
        const f = $('#orderForm').elements;
        return {
            name: f.name.value.trim(),
            phone: f.phone.value.trim(),
            deliveryDate: f.deliveryDate.value,
            deliveryTime: f.deliveryTime.value,
            guests: f.guests.value,
            notes: f.notes.value.trim(),
            website: f.website.value,
        };
    }

    function deliveryLabel(d) {
        if (!d.deliveryDate) return d.deliveryTime || '';
        const [y, m, day] = d.deliveryDate.split('-');
        return `${day}/${m}/${y}` + (d.deliveryTime ? ' ' + d.deliveryTime : '');
    }

    function whatsappText(d, orderId) {
        const lines = [`הזמנה חדשה מ${d.name} (${d.phone})`];
        if (orderId) lines.push(`מספר הזמנה: ${orderId}`);
        if (d.deliveryDate) lines.push(`מועד: ${deliveryLabel(d)}`);
        if (d.guests) lines.push(`סועדים: ${d.guests}`);
        for (const cat of menu.categories) {
            const picked = cat.items.filter(i => selected.has(i.id));
            if (!picked.length) continue;
            lines.push('', `*${cat.name}*`, ...picked.map(i => '• ' + i.name + (i.extra ? ' (' + menu.extraNote + ')' : '')));
        }
        if (d.notes) lines.push('', 'הערות: ' + d.notes);
        return lines.join('\n');
    }

    async function submitOrder(e) {
        e.preventDefault();
        const d = formData();
        const err = $('#formError');
        const fail = msg => { err.textContent = msg; err.hidden = false; };

        if (!d.name) return fail('נא למלא שם');
        if (d.phone.replace(/\D/g, '').length < 9) return fail('נא למלא מספר טלפון תקין');
        if (!selected.size) return fail('לא נבחרו מנות');

        const btn = $('#submitOrder');
        btn.disabled = true;
        btn.textContent = 'שולח…';
        try {
            const res = await fetch('api.php?action=order', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ...d, items: [...selected] }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json.success) throw new Error(json.error || 'השליחה נכשלה');
            showDone(d, json.id);
        } catch (ex) {
            fail((ex.message || 'השליחה נכשלה') + '. אפשר לנסות שוב, או לשלוח בוואטסאפ.');
            const link = el('a', {
                href: `https://wa.me/${menu.business.whatsapp}?text=${encodeURIComponent(whatsappText(d))}`,
                target: '_blank', rel: 'noopener', class: 'btn btn-whatsapp btn-wide',
            }, 'שליחה בוואטסאפ');
            err.append(el('br'), link);
        } finally {
            btn.disabled = false;
            btn.textContent = 'שליחת ההזמנה';
        }
    }

    function showDone(d, orderId) {
        $('#doneText').textContent = `תודה ${d.name}! קיבלנו ${selected.size} מנות. נחזור אליך לטלפון ${d.phone} לאישור.`;
        $('#doneWhatsapp').href = `https://wa.me/${menu.business.whatsapp}?text=${encodeURIComponent(whatsappText(d, orderId))}`;
        $('#orderSheet').close();
        $('#doneSheet').showModal();
        // פרטי הלקוח נשמרים להזמנה הבאה; המנות מתאפסות.
        selected = new Set();
        clearDraft();
        saveDraft({ name: d.name, phone: d.phone });
        document.querySelectorAll('.item-btn').forEach(b => b.setAttribute('aria-pressed', 'false'));
        refreshCounts();
    }

    /* ── אתחול ───────────────────────────────────────────────────── */

    async function init() {
        try {
            const res = await fetch('assets/menu.json', { cache: 'no-cache' });
            menu = await res.json();
        } catch {
            $('#menu').innerHTML = '<p class="empty">לא הצלחנו לטעון את התפריט. נסו לרענן את הדף.</p>';
            return;
        }
        const known = new Set(menu.categories.flatMap(c => c.items.map(i => i.id)));
        selected = new Set((loadDraft().items || []).filter(id => known.has(id)));

        renderBrand();
        renderMenu();

        $('#openOrder').addEventListener('click', openOrder);
        $('#orderForm').addEventListener('submit', submitOrder);
        $('#orderForm').addEventListener('input', () => {
            const d = formData();
            delete d.website;
            saveDraft(d);
        });
        document.querySelectorAll('[data-close]').forEach(b =>
            b.addEventListener('click', () => b.closest('dialog').close()));
        $('#newOrder').addEventListener('click', () => {
            $('#doneSheet').close();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
        // הקשה על הרקע סוגרת את הגיליון
        document.querySelectorAll('dialog').forEach(dlg =>
            dlg.addEventListener('click', e => { if (e.target === dlg) dlg.close(); }));
        if (known.size && selected.size) toast(`שמרנו את הבחירה הקודמת שלך (${selected.size} מנות)`);
    }

    init();
})();
