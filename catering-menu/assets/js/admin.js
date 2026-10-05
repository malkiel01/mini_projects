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
        renderCalendar();
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
        $('#oDate').value = eventDate(o);
        $('#oTime').value = eventTime(o);
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

    /* ── יומן ─────────────────────────────────────────────────────── */

    const DAY_NAMES = ['ראשון', 'שני', 'שלישי', 'רביעי', 'חמישי', 'שישי', 'שבת'];
    const MONTH_NAMES = ['ינואר', 'פברואר', 'מרץ', 'אפריל', 'מאי', 'יוני', 'יולי',
                         'אוגוסט', 'ספטמבר', 'אוקטובר', 'נובמבר', 'דצמבר'];
    const STATUS_SHORT = { pending: 'טרם', in_progress: 'בתהליך', done: 'מוכן' };

    let view = 'list';
    let calMode = 'month';
    let cursor = new Date();        // היום שהיומן ממוקד בו

    const ymd = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const addDays = (d, n) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);
    const sameDay = (a, b) => ymd(a) === ymd(b);
    const heb = d => HebCal.hebrew(d.getFullYear(), d.getMonth() + 1, d.getDate());
    const hols = d => HebCal.holidays(d.getFullYear(), d.getMonth() + 1, d.getDate());

    /** מועד האירוע כ-YYYY-MM-DD. הזמנות ישנות שמרו רק "20/10/2026 19:00". */
    function eventDate(o) {
        const c = o.customer;
        if (c.deliveryDate) return c.deliveryDate;
        const m = /^(\d{1,2})\/(\d{1,2})\/(\d{4})/.exec(c.deliveryAt || '');
        return m ? `${m[3]}-${m[2].padStart(2, '0')}-${m[1].padStart(2, '0')}` : '';
    }
    function eventTime(o) {
        const c = o.customer;
        return c.deliveryTime || (/(\d{1,2}:\d{2})/.exec(c.deliveryAt || '') || [])[1] || '';
    }

    /** אירועים לפי יום, ממוינים לפי שעה. */
    function eventsByDay() {
        const map = new Map();
        for (const o of orders) {
            const d = eventDate(o);
            if (!d) continue;
            if (!map.has(d)) map.set(d, []);
            map.get(d).push(o);
        }
        for (const list of map.values()) list.sort((a, b) => eventTime(a).localeCompare(eventTime(b)));
        return map;
    }

    function setView(v) {
        view = v;
        try { localStorage.setItem('catering-menu:view', JSON.stringify({ view, calMode })); } catch { /* */ }
        document.querySelectorAll('.view-switch [data-view]').forEach(b =>
            b.setAttribute('aria-selected', String(b.dataset.view === v)));
        $('#cal').hidden = v !== 'calendar';
        $('#listView').hidden = v !== 'list';
        if (v === 'calendar') renderCalendar();
    }

    function setMode(mode, date) {
        calMode = mode;
        if (date) cursor = date;
        setView('calendar');
    }

    function move(dir) {
        if (calMode === 'month') cursor = new Date(cursor.getFullYear(), cursor.getMonth() + dir, 1);
        else cursor = addDays(cursor, dir * (calMode === 'week' ? 7 : 1));
        renderCalendar();
    }

    /** הטווח המוצג: [התחלה, סוף] כולל. */
    function range() {
        if (calMode === 'day') return [cursor, cursor];
        if (calMode === 'week') {
            const start = addDays(cursor, -cursor.getDay());
            return [start, addDays(start, 6)];
        }
        const first = new Date(cursor.getFullYear(), cursor.getMonth(), 1);
        const last = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0);
        return [first, last];
    }

    /** "תשרי – חשוון תשפ״ז" — החודשים העבריים שבטווח. */
    function hebRange(a, b) {
        const ha = heb(a), hb = heb(b);
        if (ha.month === hb.month && ha.year === hb.year) return `${ha.monthHe} ${ha.yearHe}`;
        if (ha.year === hb.year) return `${ha.monthHe} – ${hb.monthHe} ${hb.yearHe}`;
        return `${ha.monthHe} ${ha.yearHe} – ${hb.monthHe} ${hb.yearHe}`;
    }

    function renderCalendar() {
        if (view !== 'calendar') return;
        document.querySelectorAll('.cal-modes [data-mode]').forEach(b =>
            b.setAttribute('aria-checked', String(b.dataset.mode === calMode)));

        const [a, b] = range();
        const byDay = eventsByDay();

        if (calMode === 'month') {
            $('#calTitle').textContent = `${MONTH_NAMES[a.getMonth()]} ${a.getFullYear()}`;
        } else if (calMode === 'week') {
            $('#calTitle').textContent = `${a.getDate()}.${a.getMonth() + 1} – ${b.getDate()}.${b.getMonth() + 1}.${b.getFullYear()}`;
        } else {
            $('#calTitle').textContent = `יום ${DAY_NAMES[a.getDay()]}, ${a.getDate()} ב${MONTH_NAMES[a.getMonth()]} ${a.getFullYear()}`;
        }
        $('#calSub').textContent = calMode === 'day' ? heb(a).full : hebRange(a, b);

        // סיכום הטווח: כמה אירועים, וכמה בכל מצב
        const inRange = [];
        for (let d = a; d <= b; d = addDays(d, 1)) inRange.push(...(byDay.get(ymd(d)) || []));
        const stats = $('#calStats');
        stats.textContent = '';
        if (inRange.length) {
            stats.append(`${inRange.length} אירועים`);
            for (const st of ['done', 'in_progress', 'pending']) {
                const n = inRange.filter(o => o.status === st).length;
                if (n) stats.append(' · ', el('i', { class: 'dot ' + st }), ` ${n} ${STATUS_SHORT[st]}`);
            }
        } else {
            stats.textContent = 'אין אירועים בטווח הזה';
        }

        const undated = orders.filter(o => !eventDate(o)).length;
        $('#calUndated').hidden = !undated;
        $('#calUndated').textContent = `${undated} הזמנות בלי תאריך — לא מופיעות ביומן. אפשר לקבוע תאריך מתוך ההזמנה ברשימה.`;

        const body = $('#calBody');
        body.textContent = '';
        body.append(calMode === 'month' ? monthGrid(a, byDay) : agenda(a, b, byDay));
    }

    function holidayTags(d, short = false) {
        const list = hols(d);
        if (d.getDay() === 6 && !list.some(h => h.type === 'yomtov')) list.push({ name: 'שבת', type: 'shabbat' });
        return list.map(h => el('span', { class: 'hol ' + h.type, title: h.name },
            short ? h.name.replace(/ · .*/, '') : h.name));
    }

    function eventChip(o) {
        const t = eventTime(o);
        return el('button', {
            type: 'button', class: 'chip ' + o.status,
            title: `${o.customer.name} · ${STATUS[o.status]}`,
            onclick: e => { e.stopPropagation(); openOrder(o.id); },
        }, t ? el('b', {}, t) : null, ' ', o.customer.name);
    }

    function monthGrid(first, byDay) {
        const grid = el('div', { class: 'month' });
        for (const n of ['א׳', 'ב׳', 'ג׳', 'ד׳', 'ה׳', 'ו׳', 'ש׳']) grid.append(el('div', { class: 'month-dow' }, n));

        const start = addDays(first, -first.getDay());
        const last = new Date(first.getFullYear(), first.getMonth() + 1, 0);
        const end = addDays(last, 6 - last.getDay());
        const today = new Date();

        for (let d = start; d <= end; d = addDays(d, 1)) {
            const events = byDay.get(ymd(d)) || [];
            const h = heb(d);
            const cls = ['day'];
            if (d.getMonth() !== first.getMonth()) cls.push('out');
            if (sameDay(d, today)) cls.push('today');
            if (d.getDay() === 6 || hols(d).some(x => x.type === 'yomtov')) cls.push('rest');
            if (events.length) cls.push('busy');

            const cell = el('div', {
                class: cls.join(' '), role: 'button', tabindex: '0',
                'aria-label': `${d.getDate()} ${MONTH_NAMES[d.getMonth()]}, ${h.label}, ${events.length} אירועים`,
                onclick: () => setMode('day', d),
                onkeydown: e => { if (e.key === 'Enter') setMode('day', d); },
            },
            el('div', { class: 'day-nums' },
                el('span', { class: 'g' }, String(d.getDate())),
                el('span', { class: 'h' }, h.day === 1 ? h.label : h.dayHe)),
            el('div', { class: 'day-hols' }, ...holidayTags(d, true)));

            const shown = events.slice(0, 3);
            cell.append(el('div', { class: 'day-events' }, ...shown.map(eventChip),
                events.length > 3 ? el('span', { class: 'more' }, `+${events.length - 3}`) : null));
            // בטלפון הצ׳יפים מתכווצים לנקודות צבע
            cell.append(el('div', { class: 'day-dots' }, ...events.slice(0, 5).map(o => el('i', { class: 'dot ' + o.status }))));
            grid.append(cell);
        }
        return grid;
    }

    /** שבוע או יום: רשימה לפי ימים, עם כרטיס מלא לכל אירוע. */
    function agenda(a, b, byDay) {
        const box = el('div', { class: 'agenda' });
        const today = new Date();
        for (let d = a; d <= b; d = addDays(d, 1)) {
            const events = byDay.get(ymd(d)) || [];
            const head = el('button', {
                type: 'button', class: 'ag-head' + (sameDay(d, today) ? ' today' : ''),
                onclick: () => setMode('day', d), disabled: calMode === 'day',
            },
            el('span', { class: 'ag-date' }, `${DAY_NAMES[d.getDay()]} ${d.getDate()}.${d.getMonth() + 1}`),
            el('span', { class: 'ag-heb' }, heb(d).label),
            el('span', { class: 'ag-hols' }, ...holidayTags(d)));
            const list = events.length
                ? el('div', { class: 'ag-events' }, ...events.map(eventCard))
                : el('p', { class: 'ag-empty' }, calMode === 'day' ? 'אין אירועים ביום הזה' : '—');
            box.append(el('section', { class: 'ag-day' + (events.length ? ' busy' : '') }, head, list));
        }
        return box;
    }

    function eventCard(o) {
        const sub = [`${o.items.length} מנות`];
        if (o.customer.guests) sub.push(`${o.customer.guests} סועדים`);
        return el('button', { type: 'button', class: 'order-card ' + o.status, onclick: () => openOrder(o.id) },
            el('span', { class: 'when' }, (eventTime(o) ? eventTime(o) + ' · ' : '') + o.customer.name),
            el('span', { class: 'pill ' + o.status }, STATUS[o.status]),
            el('span', { class: 'sub' }, sub.join(' · ')),
            progressBar(o));
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
        $('#oDate').addEventListener('change', e => update({ deliveryDate: e.target.value }));
        $('#oTime').addEventListener('change', e => update({ deliveryTime: e.target.value }));
        document.querySelectorAll('.view-switch [data-view]').forEach(b =>
            b.addEventListener('click', () => setView(b.dataset.view)));
        document.querySelectorAll('.cal-modes [data-mode]').forEach(b =>
            b.addEventListener('click', () => setMode(b.dataset.mode)));
        $('#calPrev').addEventListener('click', () => move(-1));
        $('#calNext').addEventListener('click', () => move(1));
        $('#calToday').addEventListener('click', () => { cursor = new Date(); renderCalendar(); });
        try {
            const saved = JSON.parse(localStorage.getItem('catering-menu:view'));
            if (saved?.calMode) calMode = saved.calMode;
            if (saved?.view) setView(saved.view);
        } catch { /* */ }
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
