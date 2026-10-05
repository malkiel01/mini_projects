/* ממשק המנהל: כניסה, התראות, אישור/סירוב, רשימת הזמנות לפי לקוח, מעקב "קיים / לא
   קיים" לכל פריט, יומן עם זמני היום, והגדרות. */
(() => {
    'use strict';

    const API = '../api.php';
    const STATUS = {
        pending:     'לא בוצעה',
        in_progress: 'בתהליך',
        done:        'בוצעה',
    };
    const APPROVAL = {
        new:      'ממתינה לאישור',
        approved: 'אושרה',
        rejected: 'נדחתה',
    };
    const $ = (sel, root = document) => root.querySelector(sel);

    let orders = [];
    let settings = null;            // הגדרות המנהל מהשרת (מיקום, מגבלות, מיילים)
    let knownUnread = null;         // מזהי ההזמנות שלא נקראו בטעינה הקודמת
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
        $('#topActions').hidden = true;
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
        $('#topActions').hidden = false;
        try { settings = (await api('settings')).settings; } catch { /* נטען שוב בפתיחת ההגדרות */ }
        await load();
    }

    /* ── נתונים ──────────────────────────────────────────────────── */

    async function load(quiet = false) {
        try {
            orders = (await api('orders')).orders;
            for (const o of orders) {
                o.approval ??= 'new';
                o.read ??= false;
            }
            announceNew();
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

    const isOpen = o => o.approval !== 'rejected' && o.status !== 'done';

    function matches(o) {
        if (filter === 'open' && !isOpen(o)) return false;
        if (filter === 'new' && o.approval !== 'new') return false;
        if (filter === 'rejected' && o.approval !== 'rejected') return false;
        if (STATUS[filter] && (o.status !== filter || o.approval === 'rejected')) return false;
        const q = keyName($('#search').value);
        return !q || o.customer.name.includes(q) || o.customer.phone.includes(q);
    }

    /* ── ציור הלוח ───────────────────────────────────────────────── */

    function render() {
        renderBell();
        renderFilters();
        renderTodo();
        renderGroups();
        renderCalendar();
    }

    function renderFilters() {
        const live = orders.filter(o => o.approval !== 'rejected');
        const count = s => live.filter(o => o.status === s).length;
        const tabs = [
            ['open', 'פתוחות', orders.filter(isOpen).length],
            ['new', 'ממתינות לאישור', orders.filter(o => o.approval === 'new').length],
            ['pending', STATUS.pending, count('pending')],
            ['in_progress', STATUS.in_progress, count('in_progress')],
            ['done', STATUS.done, count('done')],
            ['rejected', 'נדחו', orders.filter(o => o.approval === 'rejected').length],
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
            // רק הזמנות שאושרו נכנסות לתכנון המטבח
            if (o.status === 'done' || o.approval !== 'approved') continue;
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
        return el('button', { type: 'button', class: `order-card ${o.status} appr-${o.approval}${o.read ? '' : ' unread'}`, onclick: () => openOrder(o.id) },
            el('span', { class: 'when' }, o.read ? null : el('i', { class: 'new-dot', title: 'לא נקראה' }),
                o.customer.deliveryAt ? '📅 ' + o.customer.deliveryAt : 'ללא מועד'),
            approvalPill(o),
            el('span', { class: 'sub' }, sub.join(' · ')),
            progressBar(o));
    }

    /* ── מסך הזמנה ───────────────────────────────────────────────── */

    const current = () => orders.find(o => o.id === openId);

    function approvalPill(o) {
        return o.approval === 'approved'
            ? el('span', { class: 'pill ' + o.status }, STATUS[o.status])
            : el('span', { class: 'pill appr-' + o.approval }, APPROVAL[o.approval]);
    }

    function openOrder(id) {
        openId = id;
        $('#notesSheet').close();
        renderOrder();
        const o = current();
        if (!o.read) update({ read: true });
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
        renderApproval(o);
        const meta = [];
        if (c.deliveryAt) meta.push('📅 ' + c.deliveryAt);
        if (c.guests) meta.push(`${c.guests} סועדים`);
        meta.push('\u2066#' + o.id + '\u2069');   // בידוד כיווני — שהמזהה לא יתהפך בעברית
        $('#oMeta').textContent = meta.join(' · ');

        const digits = c.phone.replace(/\D/g, '');
        const intl = digits.startsWith('0') ? '972' + digits.slice(1) : digits;
        const contact = $('#oContact');
        contact.textContent = '';
        contact.append(
            el('a', { class: 'btn btn-ghost', href: 'tel:' + digits }, '📞 ' + c.phone),
            c.email ? el('a', { class: 'btn btn-ghost', href: 'mailto:' + c.email }, '✉️ מייל') : null,
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
        if ('read' in change) o.read = change.read;
        if (change.approval) { o.approval = change.approval; o.read = true; }
        if (openId === o.id) renderOrder();
        render();

        try {
            const res = await api('update', { id: o.id, ...change });
            Object.assign(o, res.order);
            if (openId === o.id) renderOrder();
            render();
            if (res.mail === 'sent') toast('✉️ המייל נשלח ללקוח');
            if (res.mail === 'failed') toast('שליחת המייל נכשלה — כדאי לעדכן את הלקוח בטלפון או בוואטסאפ');
            return res;
        } catch (ex) {
            Object.assign(o, before);
            if (openId === o.id) renderOrder();
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

    /* ── אישור / סירוב ───────────────────────────────────────────── */

    function renderApproval(o) {
        const box = $('#oApproval');
        box.textContent = '';
        box.className = 'approval appr-' + o.approval;
        if (o.approval === 'new') {
            box.append(
                el('p', {}, '⏳ ההזמנה ממתינה לאישור שלך'),
                el('div', { class: 'approval-btns' },
                    el('button', { type: 'button', class: 'btn btn-ok', onclick: () => decide('approved') }, '✓ אישור'),
                    el('button', { type: 'button', class: 'btn btn-danger', onclick: () => decide('rejected') }, '✕ סירוב')));
        } else {
            const when = o.decidedAt ? ' · ' + fmtCreated(o.decidedAt) : '';
            const last = (o.mailLog || []).at(-1);
            const mail = last ? (last.result === 'sent' ? ' · ✉️ נשלח מייל' : last.result === 'failed' ? ' · ⚠️ המייל נכשל' : '') : '';
            box.append(
                el('p', {}, (o.approval === 'approved' ? '✓ ההזמנה אושרה' : '✕ ההזמנה נדחתה') + when + mail));
            if (o.rejectReason) box.append(el('p', { class: 'reason' }, 'סיבה: ' + o.rejectReason));
            box.append(
                el('button', { type: 'button', class: 'link-btn', onclick: () => update({ approval: 'new' }) }, 'ביטול ההחלטה'));
        }
    }

    let deciding = null;

    function decide(approval) {
        const o = current();
        if (!o) return;
        deciding = approval;
        const ok = approval === 'approved';
        const hasMail = !!o.customer.email;
        $('#decideTitle').textContent = ok ? `אישור ההזמנה של ${o.customer.name}` : `סירוב להזמנה של ${o.customer.name}`;
        $('#decideText').textContent = ok
            ? 'ההזמנה תיכנס ליומן ולתכנון המטבח.'
            : 'ההזמנה תוסר מהיומן ומהרשימה הפתוחה (היא נשמרת תחת "נדחו", ואפשר גם למחוק אותה).';
        $('#reasonWrap').hidden = ok;
        $('#decideReason').value = '';
        $('#decideNotify').checked = hasMail;
        $('#decideNotify').disabled = !hasMail;
        $('#decideNotifyLabel').textContent = hasMail
            ? `לשלוח מייל ללקוח (${o.customer.email})`
            : 'הלקוח לא השאיר מייל — אפשר לעדכן אותו בוואטסאפ אחרי השמירה';
        $('#decideSubmit').textContent = ok ? '✓ אישור' : '✕ סירוב';
        $('#decideSubmit').className = 'btn btn-wide ' + (ok ? 'btn-ok' : 'btn-danger-solid');
        $('#decideSheet').showModal();
    }

    async function submitDecision(e) {
        e.preventDefault();
        const o = current();
        $('#decideSheet').close();
        if (!o || !deciding) return;
        const res = await update({
            approval: deciding,
            reason: $('#decideReason').value.trim(),
            notify: $('#decideNotify').checked,
        });
        // בלי מייל — הודעת וואטסאפ מוכנה
        if (res && !o.customer.email) {
            const text = deciding === 'approved'
                ? `שלום ${o.customer.name}, ההזמנה שלך${o.customer.deliveryAt ? ' ל-' + o.customer.deliveryAt : ''} אושרה. תודה, ניחוחות`
                : `שלום ${o.customer.name}, לצערנו לא נוכל לקבל את ההזמנה${o.customer.deliveryAt ? ' ל-' + o.customer.deliveryAt : ''}.` +
                  ($('#decideReason').value.trim() ? ' ' + $('#decideReason').value.trim() : '');
            const digits = o.customer.phone.replace(/\D/g, '');
            const intl = digits.startsWith('0') ? '972' + digits.slice(1) : digits;
            window.open(`https://wa.me/${intl}?text=${encodeURIComponent(text)}`, '_blank', 'noopener');
        }
        deciding = null;
    }

    /* ── התראות ──────────────────────────────────────────────────── */

    function renderBell() {
        const unread = orders.filter(o => !o.read).length;
        $('#bellBadge').hidden = !unread;
        $('#bellBadge').textContent = unread > 99 ? '99+' : unread;
        document.title = (unread ? `(${unread}) ` : '') + 'ניחוחות — ניהול הזמנות';
        if ($('#notesSheet').open) renderNotes();
    }

    function noteRow(o) {
        return el('button', { type: 'button', class: 'note' + (o.read ? '' : ' unread'), onclick: () => openOrder(o.id) },
            el('span', { class: 'note-main' },
                el('b', {}, o.customer.name),
                el('span', {}, ` · ${o.items.length} מנות` + (o.customer.deliveryAt ? ` · 📅 ${o.customer.deliveryAt}` : ''))),
            el('span', { class: 'note-side' },
                el('span', { class: 'pill appr-' + o.approval }, APPROVAL[o.approval]),
                el('small', {}, fmtCreated(o.createdAt))));
    }

    function renderNotes() {
        const unread = orders.filter(o => !o.read);
        const read = orders.filter(o => o.read).slice(0, 30);
        $('#unreadCount').textContent = unread.length ? `(${unread.length})` : '';
        const ul = $('#unreadList');
        ul.textContent = '';
        ul.append(...(unread.length ? unread.map(noteRow) : [el('p', { class: 'empty' }, 'אין התראות חדשות 🎉')]));
        const rl = $('#readList');
        rl.textContent = '';
        rl.append(...(read.length ? read.map(noteRow) : [el('p', { class: 'empty' }, '—')]));
        $('#markAllRead').hidden = !unread.length;
        $('#enablePush').hidden = !('Notification' in window) || Notification.permission === 'granted';
    }

    /** הזמנה חדשה שהגיעה בזמן שהממשק פתוח: צליל, הודעה, והתראת דפדפן. */
    function announceNew() {
        const unread = new Set(orders.filter(o => !o.read).map(o => o.id));
        if (knownUnread) {
            const fresh = orders.filter(o => unread.has(o.id) && !knownUnread.has(o.id));
            if (fresh.length) {
                chime();
                const text = fresh.length === 1 ? `הזמנה חדשה מ${fresh[0].customer.name}` : `${fresh.length} הזמנות חדשות`;
                toast('🔔 ' + text);
                if ('Notification' in window && Notification.permission === 'granted') {
                    try { new Notification('ניחוחות', { body: text, icon: '../assets/icon.svg', tag: 'catering-new' }); } catch { /* */ }
                }
            }
        }
        knownUnread = unread;
    }

    function chime() {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            [660, 880].forEach((f, i) => {
                const o = ctx.createOscillator(), g = ctx.createGain();
                o.frequency.value = f;
                g.gain.setValueAtTime(0.0001, ctx.currentTime + i * 0.18);
                g.gain.exponentialRampToValueAtTime(0.2, ctx.currentTime + i * 0.18 + 0.02);
                g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + i * 0.18 + 0.3);
                o.connect(g).connect(ctx.destination);
                o.start(ctx.currentTime + i * 0.18);
                o.stop(ctx.currentTime + i * 0.18 + 0.32);
            });
        } catch { /* אין שמע — לא נורא */ }
    }

    async function markAllRead() {
        const unread = orders.filter(o => !o.read);
        unread.forEach(o => { o.read = true; });
        render();
        await Promise.all(unread.map(o => api('update', { id: o.id, read: true }).catch(() => {})));
    }

    /* ── הגדרות ──────────────────────────────────────────────────── */

    const loc = () => {
        const l = settings?.location || { lat: 31.778, lon: 35.235, candleOffset: 40, name: 'ירושלים' };
        return { ...l, havdalah: { mode: l.havdalahMode, minutes: l.havdalahMinutes } };
    };

    async function openSettings() {
        try { settings = (await api('settings')).settings; } catch (ex) { toast(ex.message); return; }
        const l = settings.location;
        const sel = $('#setPlace');
        sel.textContent = '';
        for (const p of HebCal.PLACES) sel.append(el('option', { value: p.id }, p.name));
        sel.append(el('option', { value: 'custom' }, 'מיקום אחר (קואורדינטות)'));
        sel.value = HebCal.PLACES.some(p => p.id === l.id) ? l.id : 'custom';
        $('#setLat').value = l.lat;
        $('#setLon').value = l.lon;
        $('#setCandle').value = l.candleOffset;
        $('#setHavdalahMode').value = l.havdalahMode;
        $('#setHavdalahMin').value = l.havdalahMinutes;

        const box = $('#setLimits');
        box.textContent = '';
        const menu = await fetch('../assets/menu.json', { cache: 'no-cache' }).then(r => r.json());
        for (const c of menu.categories) {
            box.append(el('label', {}, `${c.name} (${c.items.length})`,
                el('input', { type: 'number', min: '1', max: '99', inputmode: 'numeric', 'data-cat': c.id,
                    placeholder: 'ללא', value: settings.limits[c.id] ?? '' })));
        }
        $('#setAdminMail').value = settings.email.adminNotify;
        $('#setReplyTo').value = settings.email.replyTo;
        $('#setFrom').value = settings.email.from;
        $('#setError').hidden = true;
        syncSettingsForm();
        $('#settingsSheet').showModal();
    }

    /** מעדכן שדות תלויים ותצוגה מקדימה של זמני השבת הקרובה. */
    function syncSettingsForm(e) {
        const sel = $('#setPlace');
        const place = HebCal.PLACES.find(p => p.id === sel.value);
        if (e?.target === sel && place) {
            $('#setLat').value = place.lat;
            $('#setLon').value = place.lon;
            $('#setCandle').value = place.candleOffset;
        }
        $('#setCoords').hidden = !!place;
        $('#setHavdalahMinWrap').hidden = $('#setHavdalahMode').value !== 'minutes';

        const l = formLocation();
        let fri = new Date();
        while (fri.getDay() !== 5) fri = addDays(fri, 1);
        const sat = addDays(fri, 1);
        const zf = HebCal.zmanim(fri.getFullYear(), fri.getMonth() + 1, fri.getDate(), { ...l, havdalah: { mode: l.havdalahMode, minutes: l.havdalahMinutes } });
        const zs = HebCal.zmanim(sat.getFullYear(), sat.getMonth() + 1, sat.getDate(), { ...l, havdalah: { mode: l.havdalahMode, minutes: l.havdalahMinutes } });
        const par = HebCal.parasha(sat.getFullYear(), sat.getMonth() + 1, sat.getDate());
        const prev = $('#setPreview');
        prev.textContent = `השבת הקרובה ב${l.name}${par ? ' (פרשת ' + par + ')' : ''}: `;
        prev.append(icon('candles'), ` הדלקה ${zf.candles || '—'} · `, icon('stars'), ` צאת ${zs.havdalah || '—'} · `,
            icon('sunset'), ` שקיעה ביום שישי ${zf.sunset}`);
    }

    function formLocation() {
        const sel = $('#setPlace');
        const place = HebCal.PLACES.find(p => p.id === sel.value);
        return {
            id: sel.value,
            name: place ? place.name : 'מיקום מותאם',
            lat: Number($('#setLat').value) || 31.778,
            lon: Number($('#setLon').value) || 35.235,
            candleOffset: Number($('#setCandle').value) || 0,
            havdalahMode: $('#setHavdalahMode').value,
            havdalahMinutes: Number($('#setHavdalahMin').value) || 40,
        };
    }

    async function saveSettings(e) {
        e.preventDefault();
        const limits = {};
        document.querySelectorAll('#setLimits input').forEach(i => { if (Number(i.value) > 0) limits[i.dataset.cat] = Number(i.value); });
        try {
            settings = (await api('saveSettings', {
                location: formLocation(),
                limits,
                email: { adminNotify: $('#setAdminMail').value.trim(), replyTo: $('#setReplyTo').value.trim(), from: $('#setFrom').value.trim() },
            })).settings;
            $('#settingsSheet').close();
            toast('ההגדרות נשמרו');
            renderCalendar();
        } catch (ex) {
            $('#setError').textContent = ex.message;
            $('#setError').hidden = false;
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
            if (!d || o.approval === 'rejected') continue;
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
        $('#calSub').textContent = (calMode === 'day' ? heb(a).full : hebRange(a, b));
        $('#calPlace').textContent = `זמני היום לפי ${loc().name} · שינוי בהגדרות ⚙️`;

        // סיכום הטווח: כמה אירועים, וכמה בכל מצב
        const inRange = [];
        for (let d = a; d <= b; d = addDays(d, 1)) inRange.push(...(byDay.get(ymd(d)) || []));
        const stats = $('#calStats');
        stats.textContent = '';
        if (inRange.length) {
            stats.append(`${inRange.length} אירועים`);
            for (const st of ['done', 'in_progress', 'pending']) {
                const n = inRange.filter(o => o.status === st && o.approval === 'approved').length;
                if (n) stats.append(' · ', el('i', { class: 'dot ' + st }), ` ${n} ${STATUS_SHORT[st]}`);
            }
            const waiting = inRange.filter(o => o.approval === 'new').length;
            if (waiting) stats.append(' · ', el('i', { class: 'dot new' }), ` ${waiting} ממתינות לאישור`);
        } else {
            stats.textContent = 'אין אירועים בטווח הזה';
        }

        const undated = orders.filter(o => !eventDate(o) && o.approval !== 'rejected').length;
        $('#calUndated').hidden = !undated;
        $('#calUndated').textContent = `${undated} הזמנות בלי תאריך — לא מופיעות ביומן. אפשר לקבוע תאריך מתוך ההזמנה ברשימה.`;

        const body = $('#calBody');
        body.textContent = '';
        body.append(calMode === 'month' ? monthGrid(a, byDay) : agenda(a, b, byDay));
    }

    function holidayTags(d, short = false) {
        const list = hols(d);
        const par = HebCal.parasha(d.getFullYear(), d.getMonth() + 1, d.getDate());
        if (par) list.push({ name: 'פרשת ' + par, type: 'parasha' });
        else if (d.getDay() === 6 && !list.some(h => h.type === 'yomtov')) list.push({ name: 'שבת', type: 'shabbat' });
        return list.map(h => el('span', { class: 'hol ' + h.type, title: h.name },
            short ? h.name.replace(/ · .*/, '') : h.name));
    }

    /* סמלים: שני נרות שבת להדלקה, שלושה כוכבים קטנים לצאת שבת, ושמש שוקעת.
       SVG ולא אימוג׳י — כדי שייראו אותו דבר בכל טלפון. */
    const ICONS = {
        candles: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7.5 2.2c1.3 1.5 1.6 2.6.9 3.5a1.2 1.2 0 0 1-1.8 0c-.7-.9-.4-2 .9-3.5z" fill="#f2a83b"/>' +
                 '<path d="M16.5 2.2c1.3 1.5 1.6 2.6.9 3.5a1.2 1.2 0 0 1-1.8 0c-.7-.9-.4-2 .9-3.5z" fill="#f2a83b"/>' +
                 '<rect x="6" y="7.5" width="3" height="11" rx=".8" fill="#fbf3df" stroke="#c98f2e" stroke-width=".9"/>' +
                 '<rect x="15" y="7.5" width="3" height="11" rx=".8" fill="#fbf3df" stroke="#c98f2e" stroke-width=".9"/>' +
                 '<path d="M3.5 19.2h17v1.6a1.2 1.2 0 0 1-1.2 1.2H4.7a1.2 1.2 0 0 1-1.2-1.2z" fill="#c98f2e"/></svg>',
        stars:   '<svg viewBox="0 0 24 24" aria-hidden="true">' +
                 '<path d="M8 3l1.3 3.4L12.8 7.7 9.3 9 8 12.4 6.7 9 3.2 7.7 6.7 6.4z" fill="#4b5fb0"/>' +
                 '<path d="M17.5 9l.9 2.3 2.3.9-2.3.9-.9 2.3-.9-2.3-2.3-.9 2.3-.9z" fill="#6f83d6"/>' +
                 '<path d="M10 15.5l.7 1.8 1.8.7-1.8.7-.7 1.8-.7-1.8-1.8-.7 1.8-.7z" fill="#8c9de0"/></svg>',
        sunset:  '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 16a7 7 0 0 1 14 0z" fill="#f0a24a"/>' +
                 '<path d="M2.5 16.5h19M6 19.5h12" stroke="#c98f2e" stroke-width="1.6" stroke-linecap="round"/></svg>',
    };
    function icon(name) {
        const i = el('i', { class: 'ico ico-' + name });
        i.innerHTML = ICONS[name];      // מחרוזת קבועה מהקוד — לא קלט משתמש
        return i;
    }

    /** שקיעה בכל יום; הדלקת נרות בערב שבת/חג; צאת במוצאי שבת/חג. */
    function zmanLine(d, compact) {
        const z = HebCal.zmanim(d.getFullYear(), d.getMonth() + 1, d.getDate(), loc());
        const parts = [];
        if (z.candles) parts.push(el('span', { class: 'z candles', title: 'הדלקת נרות ' + z.candles }, icon('candles'), compact ? z.candles : `הדלקת נרות ${z.candles}`));
        if (z.havdalah) parts.push(el('span', { class: 'z havdalah', title: `${z.havdalahLabel} ${z.havdalah}` }, icon('stars'), compact ? z.havdalah : `${z.havdalahLabel} ${z.havdalah}`));
        parts.push(el('span', { class: 'z sunset', title: 'שקיעה ' + z.sunset }, icon('sunset'), compact ? z.sunset : `שקיעה ${z.sunset}`));
        return el('div', { class: 'zmanim' + (compact ? ' compact' : '') }, ...parts);
    }

    function eventChip(o) {
        const t = eventTime(o);
        return el('button', {
            type: 'button', class: `chip ${o.status} appr-${o.approval}`,
            title: `${o.customer.name} · ${o.approval === 'approved' ? STATUS[o.status] : APPROVAL[o.approval]}`,
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
            el('div', { class: 'day-hols' }, ...holidayTags(d, true)),
            zmanLine(d, true));

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
            el('span', { class: 'ag-hols' }, ...holidayTags(d)),
            zmanLine(d, false));
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
        return el('button', { type: 'button', class: `order-card ${o.status} appr-${o.approval}`, onclick: () => openOrder(o.id) },
            el('span', { class: 'when' }, (eventTime(o) ? eventTime(o) + ' · ' : '') + o.customer.name),
            approvalPill(o),
            el('span', { class: 'sub' }, sub.join(' · ')),
            progressBar(o));
    }

    /* ── אתחול ───────────────────────────────────────────────────── */

    async function init() {
        $('#authForm').addEventListener('submit', submitAuth);
        $('#bell').addEventListener('click', () => { renderNotes(); $('#notesSheet').showModal(); });
        $('#gear').addEventListener('click', openSettings);
        $('#markAllRead').addEventListener('click', markAllRead);
        $('#enablePush').addEventListener('click', async () => {
            const p = await Notification.requestPermission();
            toast(p === 'granted' ? 'התראות הדפדפן הופעלו — כל עוד הממשק פתוח בלשונית' : 'הדפדפן לא אישר התראות');
            renderNotes();
        });
        $('#decideForm').addEventListener('submit', submitDecision);
        $('#settingsForm').addEventListener('submit', saveSettings);
        $('#settingsForm').addEventListener('input', syncSettingsForm);
        $('#setPlace').addEventListener('change', syncSettingsForm);
        $('#logout').addEventListener('click', async () => {
            await api('logout', {}).catch(() => {});
            orders = [];
            knownUnread = null;
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
        $('#legendCandles').prepend(icon('candles'));
        $('#legendStars').prepend(icon('stars'));
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
        document.querySelectorAll('dialog:not(#orderSheet)').forEach(dlg =>
            dlg.addEventListener('click', e => { if (e.target === dlg) dlg.close(); }));
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
