/* ממשק המנהל: כניסה, רשימת הזמנות לפי לקוח, ומעקב "קיים / לא קיים" לכל פריט. */
(() => {
    'use strict';

    const API = '../api.php';
    const STATUS = {
        pending:     'לא בוצעה',
        in_progress: 'בתהליך',
        done:        'בוצעה',
    };
    const $ = (sel, root = document) => root.querySelector(sel);

    let orders = [];
    let filter = 'open';            // ברירת מחדל: מה שעוד דורש עבודה
    let openId = null;              // ההזמנה שפתוחה כרגע במסך
    let configured = true;

    function el(tag, attrs = {}, ...children) {
        const node = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs)) {
            if (v == null || v === false) continue;
            if (k === 'class') node.className = v;
            else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
            else node.setAttribute(k, v === true ? '' : v);
        }
        for (const c of children) if (c != null) node.append(c);
        return node;
    }

    function toast(message) {
        const t = el('div', { class: 'toast', role: 'status' }, message);
        document.body.append(t);
        setTimeout(() => t.remove(), 2600);
    }

    async function api(action, body) {
        const res = await fetch(`${API}?action=${action}`, body === undefined
            ? { credentials: 'same-origin' }
            : {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });
        const json = await res.json().catch(() => ({ success: false, error: 'תשובה לא תקינה מהשרת' }));
        if (res.status === 401 && action !== 'login') {
            showAuth();
            throw new Error(json.error || 'נדרשת התחברות');
        }
        if (!json.success) throw new Error(json.error || 'הפעולה נכשלה');
        return json;
    }

    /* ── כניסה ───────────────────────────────────────────────────── */

    function showAuth() {
        $('#board').hidden = true;
        $('#logout').hidden = true;
        $('#auth').hidden = false;
        $('#authTitle').textContent = configured ? 'כניסת מנהל' : 'קביעת סיסמה';
        $('#authHint').hidden = configured;
        $('#confirmWrap').hidden = configured;
        $('#authSubmit').textContent = configured ? 'כניסה' : 'שמירה וכניסה';
        $('#authForm').elements.password.autocomplete = configured ? 'current-password' : 'new-password';
        $('#authForm').elements.password.focus();
    }

    async function submitAuth(e) {
        e.preventDefault();
        const f = e.target.elements;
        const err = $('#authError');
        err.hidden = true;
        try {
            if (!configured && f.password.value !== f.confirm.value) throw new Error('הסיסמאות אינן תואמות');
            await api(configured ? 'login' : 'setup', { password: f.password.value });
            f.password.value = '';
            f.confirm.value = '';
            configured = true;
            showBoard();
        } catch (ex) {
            err.textContent = ex.message;
            err.hidden = false;
        }
    }

    async function showBoard() {
        $('#auth').hidden = true;
        $('#board').hidden = false;
        $('#logout').hidden = false;
        await load();
    }

    /* ── נתונים ──────────────────────────────────────────────────── */

    async function load(quiet = false) {
        try {
            orders = (await api('orders')).orders;
            render();
            if (openId) renderOrder();
        } catch (ex) {
            if (!quiet) toast(ex.message);
        }
    }

    const readyCount = o => o.items.filter(i => i.ready).length;
    const keyName = s => s.trim().replace(/\s+/g, ' ');

    function fmtCreated(iso) {
        const d = new Date(iso);
        return d.toLocaleDateString('he-IL', { day: 'numeric', month: 'numeric' }) + ' ' +
               d.toLocaleTimeString('he-IL', { hour: '2-digit', minute: '2-digit' });
    }

    function matches(o) {
        if (filter === 'open' && o.status === 'done') return false;
        if (STATUS[filter] && o.status !== filter) return false;
        const q = keyName($('#search').value);
        return !q || o.customer.name.includes(q) || o.customer.phone.includes(q);
    }

    /* ── ציור הלוח ───────────────────────────────────────────────── */

    function render() {
        renderFilters();
        renderTodo();
        renderGroups();
    }

    function renderFilters() {
        const count = s => orders.filter(o => o.status === s).length;
        const tabs = [
            ['open', 'פתוחות', orders.length - count('done')],
            ['pending', STATUS.pending, count('pending')],
            ['in_progress', STATUS.in_progress, count('in_progress')],
            ['done', STATUS.done, count('done')],
            ['all', 'הכול', orders.length],
        ];
        const box = $('#filters');
        box.textContent = '';
        for (const [key, label, n] of tabs) {
            box.append(el('button', {
                type: 'button', class: 'filter', role: 'tab', 'aria-selected': String(filter === key),
                onclick: () => { filter = key; render(); },
            }, STATUS[key] ? el('span', { class: 'dot ' + key }) : null, label, el('span', { class: 'n' }, String(n))));
        }
    }

    /** כל הפריטים שעוד "לא קיים" בהזמנות פתוחות, מצורפים לפי מנה. */
    function renderTodo() {
        const map = new Map();
        for (const o of orders) {
            if (o.status === 'done') continue;
            for (const i of o.items) {
                if (i.ready) continue;
                const row = map.get(i.name) || { name: i.name, who: [] };
                row.who.push(o.customer.name);
                map.set(i.name, row);
            }
        }
        const rows = [...map.values()].sort((a, b) => b.who.length - a.who.length || a.name.localeCompare(b.name, 'he'));
        $('#todo').hidden = rows.length === 0;
        $('#todoCount').textContent = `${rows.length} מנות`;
        const list = $('#todoList');
        list.textContent = '';
        for (const r of rows) {
            list.append(el('li', {},
                el('span', {}, r.name, ' ', el('b', {}, `(${r.who.length})`)),
                el('span', { class: 'who' }, [...new Set(r.who)].join(', '))));
        }
    }

    function renderGroups() {
        const box = $('#groups');
        box.textContent = '';
        const visible = orders.filter(matches);
        if (!visible.length) {
            box.append(el('p', { class: 'empty' }, orders.length ? 'אין הזמנות שמתאימות לסינון.' : 'עדיין לא התקבלו הזמנות.'));
            return;
        }

        // קיבוץ לפי שם הלקוח; הקבוצה עם ההזמנה החדשה ביותר ראשונה.
        const groups = new Map();
        for (const o of visible) {
            const k = keyName(o.customer.name);
            if (!groups.has(k)) groups.set(k, []);
            groups.get(k).push(o);
        }

        for (const [name, list] of groups) {
            const phone = list[0].customer.phone;
            box.append(el('section', { class: 'group' },
                el('div', { class: 'group-head' },
                    el('h2', {}, name, list.length > 1 ? ` (${list.length})` : ''),
                    el('a', { href: 'tel:' + phone.replace(/[^\d+]/g, '') }, phone)),
                ...list.map(orderCard)));
        }
    }

    function progressBar(o) {
        const r = readyCount(o);
        const pct = o.items.length ? Math.round(r / o.items.length * 100) : 0;
        const bar = el('span');
        bar.style.width = pct + '%';
        return el('div', { class: 'progress-wrap' },
            el('div', { class: 'progress' }, bar),
            el('span', { class: 'progress-text' }, `${r}/${o.items.length}`));
    }

    function orderCard(o) {
        const sub = [`${o.items.length} מנות`];
        if (o.customer.guests) sub.push(`${o.customer.guests} סועדים`);
        sub.push(`התקבלה ${fmtCreated(o.createdAt)}`);
        return el('button', { type: 'button', class: 'order-card ' + o.status, onclick: () => openOrder(o.id) },
            el('span', { class: 'when' }, o.customer.deliveryAt ? '📅 ' + o.customer.deliveryAt : 'ללא מועד'),
            el('span', { class: 'pill ' + o.status }, STATUS[o.status]),
            el('span', { class: 'sub' }, sub.join(' · ')),
            progressBar(o));
    }

    /* ── מסך הזמנה ───────────────────────────────────────────────── */

    const current = () => orders.find(o => o.id === openId);

    function openOrder(id) {
        openId = id;
        renderOrder();
        const o = current();
        $('#oAdminNotes').value = o.adminNotes || '';
        $('#orderSheet').showModal();
    }

    function renderOrder() {
        const o = current();
        if (!o) { $('#orderSheet').close(); return; }
        const c = o.customer;

        $('#oTitle').textContent = c.name;
        const meta = [];
        if (c.deliveryAt) meta.push('📅 ' + c.deliveryAt);
        if (c.guests) meta.push(`${c.guests} סועדים`);
        meta.push('#' + o.id);
        $('#oMeta').textContent = meta.join(' · ');

        const digits = c.phone.replace(/\D/g, '');
        const intl = digits.startsWith('0') ? '972' + digits.slice(1) : digits;
        const contact = $('#oContact');
        contact.textContent = '';
        contact.append(
            el('a', { class: 'btn btn-ghost', href: 'tel:' + digits }, '📞 ' + c.phone),
            el('a', { class: 'btn btn-whatsapp', href: 'https://wa.me/' + intl, target: '_blank', rel: 'noopener' }, 'וואטסאפ'));

        const seg = $('#oStatus');
        seg.textContent = '';
        for (const [key, label] of Object.entries(STATUS)) {
            seg.append(el('button', {
                type: 'button', role: 'radio', 'aria-checked': String(o.status === key),
                onclick: () => update({ status: key }),
            }, el('span', { class: 'dot ' + key }), label));
        }

        const r = readyCount(o);
        $('#oBar').style.width = (o.items.length ? r / o.items.length * 100 : 0) + '%';
        $('#oProgress').textContent = `${r} מתוך ${o.items.length} מוכנים`;

        $('#oNotes').hidden = !c.notes;
        $('#oNotes').textContent = c.notes ? '💬 ' + c.notes : '';

        // פריטים לפי קטגוריה, בסדר שבו הגיעו מהתפריט
        const box = $('#oItems');
        box.textContent = '';
        const cats = new Map();
        for (const i of o.items) {
            if (!cats.has(i.category)) cats.set(i.category, []);
            cats.get(i.category).push(i);
        }
        for (const [cat, items] of cats) {
            box.append(el('div', { class: 'o-cat' },
                el('h3', {}, cat),
                el('ul', {}, ...items.map(i => el('li', {},
                    el('button', {
                        type: 'button', class: 'ready-btn', 'aria-pressed': String(!!i.ready),
                        onclick: () => update({ ready: { [i.itemId]: !i.ready } }),
                    },
                    el('span', { class: 'tick', 'aria-hidden': 'true' }, '✓'),
                    el('span', { class: 'name' }, i.name + (i.extra ? ' *' : '')),
                    el('span', { class: 'state' }, i.ready ? 'קיים' : 'לא קיים')))))));
        }
    }

    /** עדכון אופטימי: המסך משתנה מיד, והשרת מחזיר את הגרסה הקובעת. */
    async function update(change) {
        const o = current();
        if (!o) return;
        const before = JSON.parse(JSON.stringify(o));

        if (change.ready) {
            for (const i of o.items) if (i.itemId in change.ready) i.ready = change.ready[i.itemId];
            const r = readyCount(o);
            o.status = r === 0 ? 'pending' : r === o.items.length ? 'done' : 'in_progress';
        }
        if (change.status) o.status = change.status;
        renderOrder();
        render();

        try {
            const res = await api('update', { id: o.id, ...change });
            Object.assign(o, res.order);
            renderOrder();
            render();
        } catch (ex) {
            Object.assign(o, before);
            renderOrder();
            render();
            toast('השמירה נכשלה: ' + ex.message);
        }
    }

    function setAll(ready) {
        const o = current();
        if (!o) return;
        update({ ready: Object.fromEntries(o.items.map(i => [i.itemId, ready])) });
    }

    async function deleteOrder() {
        const o = current();
        if (!o || !confirm(`למחוק את ההזמנה של ${o.customer.name}? אי אפשר לשחזר.`)) return;
        try {
            await api('delete', { id: o.id });
            orders = orders.filter(x => x.id !== o.id);
            $('#orderSheet').close();
            render();
            toast('ההזמנה נמחקה');
        } catch (ex) {
            toast(ex.message);
        }
    }

    /* ── אתחול ───────────────────────────────────────────────────── */

    async function init() {
        $('#authForm').addEventListener('submit', submitAuth);
        $('#logout').addEventListener('click', async () => {
            await api('logout', {}).catch(() => {});
            orders = [];
            showAuth();
        });
        $('#search').addEventListener('input', renderGroups);
        $('#refresh').addEventListener('click', () => load());
        $('#allReady').addEventListener('click', () => setAll(true));
        $('#noneReady').addEventListener('click', () => setAll(false));
        $('#deleteOrder').addEventListener('click', deleteOrder);
        $('#oAdminNotes').addEventListener('change', e => update({ adminNotes: e.target.value }));
        document.querySelectorAll('[data-close]').forEach(b =>
            b.addEventListener('click', () => b.closest('dialog').close()));
        const sheet = $('#orderSheet');
        sheet.addEventListener('close', () => { openId = null; });
        sheet.addEventListener('click', e => { if (e.target === sheet) sheet.close(); });

        // הזמנות חדשות נכנסות מעצמן: כל 30 שניות, וכשחוזרים ללשונית.
        setInterval(() => { if (!$('#board').hidden && !document.hidden) load(true); }, 30000);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && !$('#board').hidden) load(true);
        });

        try {
            const state = await api('state');
            configured = state.configured;
            if (state.loggedIn) showBoard(); else showAuth();
        } catch (ex) {
            $('#auth').hidden = false;
            $('#authError').textContent = 'אין חיבור לשרת: ' + ex.message;
            $('#authError').hidden = false;
        }
    }

    init();
})();
