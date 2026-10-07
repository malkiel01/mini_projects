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
    /* העדפות אישיות — נשמרות רק במכשיר הזה (localStorage), כל מנהל לעצמו */
    const PREFS_KEY = 'catering-menu:prefs';
    const prefs = (() => {
        const def = { start: 'last', calMode: '', filter: 'open', chime: true, bigText: false };
        try { return { ...def, ...JSON.parse(localStorage.getItem(PREFS_KEY) || '{}') }; } catch { return def; }
    })();
    function savePrefs() {
        try { localStorage.setItem(PREFS_KEY, JSON.stringify(prefs)); } catch { /* מצב פרטי — לא נורא */ }
    }

    let filter = prefs.filter || 'open';   // ברירת מחדל: מה שעוד דורש עבודה
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
        refreshPushState();
        // לחיצה על התראה פותחת את הממשק עם ?order=… — פותחים את ההזמנה
        const wanted = new URLSearchParams(location.search).get('order');
        if (wanted && orders.some(o => o.id === wanted)) openOrder(wanted);
        if (wanted) history.replaceState(null, '', location.pathname);
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
        $('#oRemOff').checked = !!o.remindersOff;
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
        contact.append(...[       // append(null) היה כותב "null" — מסננים
            el('a', { class: 'btn btn-ghost', href: 'tel:' + digits }, '📞 ' + c.phone),
            c.email ? el('a', { class: 'btn btn-ghost', href: 'mailto:' + c.email }, '✉️ מייל') : null,
            el('a', { class: 'btn btn-whatsapp', href: 'https://wa.me/' + intl, target: '_blank', rel: 'noopener' }, 'וואטסאפ'),
        ].filter(Boolean));

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
        renderPushCard();
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
        if (prefs.chime === false) return;
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

    /* ── התראות לטלפון (Web Push) ────────────────────────────────── */

    // כל מכשיר מקבל הסבר לפי מה שהוא באמת יכול:
    // אנדרואיד/מחשב — הפעלה ישירה. אייפון — רק מאפליקציה במסך הבית (דרישה של אפל).
    const ua = navigator.userAgent;
    const isIOS = /iPhone|iPad|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const isAndroid = /Android/.test(ua);
    const isStandalone = matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
    const pushSupported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    const deviceName = isIOS ? 'אייפון' : isAndroid ? 'אנדרואיד' : /Windows/.test(ua) ? 'מחשב Windows' : /Mac/.test(ua) ? 'מק' : 'מכשיר';

    let swReg = null;
    let pushSub = null;            // ההרשמה של המכשיר הזה, אם יש
    let installPrompt = null;      // אנדרואיד/מחשב: אפשרות "התקנה כאפליקציה"

    async function initPush() {
        window.addEventListener('beforeinstallprompt', e => { e.preventDefault(); installPrompt = e; renderPushCard(); });
        window.addEventListener('appinstalled', () => { markInstalled(); installPrompt = null; renderPushCard(); });
        if (isStandalone) markInstalled();
        if (!('serviceWorker' in navigator)) return;
        try {
            swReg = await navigator.serviceWorker.register('sw.js');
            navigator.serviceWorker.addEventListener('message', e => {
                if (e.data?.type !== 'open-order') return;
                const id = new URL(e.data.url).searchParams.get('order');
                if (id) load(true).then(() => { if (orders.some(o => o.id === id)) openOrder(id); });
            });
        } catch { /* בלי service worker — אין התראות ברקע, השאר עובד */ }
        refreshPushState();
    }

    let pushReport = null;         // תוצאת הבדיקה האחרונה — מוצגת בכרטיס, לאבחון
    let resynced = false;

    async function refreshPushState() {
        try {
            if (swReg && 'PushManager' in window) pushSub = await swReg.pushManager.getSubscription();
        } catch { pushSub = null; }
        // המכשיר רשום אצלו — מוודאים שגם השרת מכיר אותו (אם נמחק שם, נרשם מחדש)
        if (pushSub && Notification.permission === 'granted' && !resynced && !$('#board').hidden) {
            resynced = true;
            api('pushSubscribe', { subscription: pushSub.toJSON(), device: deviceName }).catch(() => { resynced = false; });
        }
        renderPushCard();
    }

    const pushOn = () => !!pushSub && Notification.permission === 'granted';

    function renderPushCard() {
        let banner = false;
        document.querySelectorAll('.push-slot').forEach(card => { banner = fillPushCard(card) || banner; });
        renderInstallBox();
        renderSoundHelp();
        let dismissed = false;
        try { dismissed = localStorage.getItem('catering-menu:push-banner') === 'no'; } catch { /* */ }
        $('#pushBanner').hidden = !banner || dismissed || $('#board').hidden;
    }

    /** ממלא כרטיס התראות אחד. מחזיר true אם כדאי להציג את פס התזכורת. */
    function fillPushCard(card) {
        card.textContent = '';
        let banner = false;

        // המספר בעיגול, והטקסט בבלוק אחד — אחרת כל חלק מודגש נהיה עמודה נפרדת
        const step = (n, html) => {
            const text = el('span');
            text.innerHTML = html;       // מחרוזות קבועות מהקוד — לא קלט משתמש
            return el('li', {}, el('b', {}, n), text);
        };

        if (pushOn()) {
            card.className = 'push-card push-slot on';
            card.append(
                el('p', { class: 'push-title' }, `✓ ההתראות פעילות ב${deviceName} הזה`),
                el('p', { class: 'push-text' }, isIOS
                    ? 'כל הזמנה חדשה תופיע כהתראה עם צליל, גם כשהאפליקציה סגורה.'
                    : 'כל הזמנה חדשה תופיע כהתראה עם צליל ורטט, גם כשהדפדפן סגור.'),
                el('div', { class: 'push-btns' },
                    el('button', { type: 'button', class: 'btn btn-gold', onclick: testPush }, 'שליחת התראת בדיקה'),
                    el('button', { type: 'button', class: 'btn btn-ghost', onclick: localTest }, 'בדיקה בלי שרת'),
                    el('button', { type: 'button', class: 'btn btn-ghost', onclick: disablePush }, 'כיבוי')));
            if (pushReport) card.append(pushReport.cloneNode(true));   // עותק לכל כרטיס — אלמנט אחד לא יכול לשבת בשני מקומות
        } else if (isIOS && !isStandalone) {
            // באייפון אפל מאפשרת התראות רק לאתר שהותקן למסך הבית
            banner = true;
            card.className = 'push-card push-slot ios';
            if (card.closest('.set-panel')) {
                // בהגדרות ההוראות כבר מופיעות למעלה, ב"אפליקציה במסך הבית"
                card.append(
                    el('p', { class: 'push-title' }, '📱 באייפון — קודם מתקינים את האפליקציה'),
                    el('p', { class: 'push-text' },
                        'אפל מאפשרת התראות רק מאפליקציה במסך הבית. התקינו לפי ההוראות שלמעלה, פתחו את "ניחוחות" ממסך הבית — ושם יופיע כאן כפתור "הפעלת התראות".'));
                return banner;
            }
            card.append(
                el('p', { class: 'push-title' }, '📱 התראות באייפון — הגדרה חד־פעמית'),
                el('p', { class: 'push-text' }, 'באייפון ההתראות עובדות רק מאפליקציה במסך הבית (כך אפל קבעה):'),
                el('ol', { class: 'push-steps' },
                    step('1', 'לחצו על כפתור <b>השיתוף</b> <span class="ios-share">⬆️</span> בתחתית ספארי'),
                    step('2', 'בחרו <b>"הוסף למסך הבית"</b> ← <b>"הוסף"</b>'),
                    step('3', 'פתחו את <b>"ניחוחות"</b> ממסך הבית, היכנסו עם הסיסמה'),
                    step('4', 'פתחו שוב את 🔔 ולחצו <b>"הפעלת התראות"</b>')),
                el('p', { class: 'push-note' }, 'דרוש iOS 16.4 ומעלה. ההתחברות באפליקציה נשמרת 4 חודשים.'));
        } else if (!pushSupported) {
            card.className = 'push-card push-slot off';
            card.append(
                el('p', { class: 'push-title' }, 'הדפדפן הזה לא תומך בהתראות ברקע'),
                el('p', { class: 'push-text' }, isAndroid
                    ? 'פתחו את ממשק המנהל ב-Chrome — שם ההתראות עובדות גם כשהדפדפן סגור.'
                    : 'מומלץ Chrome, Edge או Firefox. ההתראות בתוך הממשק (צליל כשהוא פתוח) ממשיכות לעבוד.'));
        } else if (Notification.permission === 'denied') {
            card.className = 'push-card push-slot off';
            card.append(
                el('p', { class: 'push-title' }, '🔕 ההתראות חסומות במכשיר הזה'),
                el('p', { class: 'push-text' }, isIOS
                    ? 'כדי לאפשר: הגדרות הטלפון ← התראות ← ניחוחות ← לאפשר התראות. ואז לחזור לכאן.'
                    : isAndroid
                        ? 'כדי לאפשר: בכרום לוחצים על הסמל שליד הכתובת ← הרשאות ← התראות ← לאפשר. ואז לרענן.'
                        : 'כדי לאפשר: לוחצים על המנעול שליד הכתובת ← התראות ← לאפשר. ואז לרענן.'));
        } else {
            banner = true;
            card.className = 'push-card push-slot';
            card.append(
                el('p', { class: 'push-title' }, '🔔 התראות על הזמנות חדשות'),
                el('p', { class: 'push-text' }, isIOS
                    ? 'הפעילו כדי לקבל התראה עם צליל על כל הזמנה — גם כשהאפליקציה סגורה.'
                    : isAndroid
                        ? 'הפעילו כדי לקבל התראה עם צליל ורטט על כל הזמנה — גם כשכרום סגור.'
                        : 'הפעילו כדי לקבל התראה על כל הזמנה — גם כשהלשונית סגורה (כל עוד הדפדפן פועל).'),
                el('button', { type: 'button', class: 'btn btn-gold btn-wide', onclick: enablePush }, `🔔 הפעלת התראות ב${deviceName} הזה`));
        }

        return banner;
    }

    /* ── התקנה כאפליקציה: מסומן כשהותקנה ─────────────────────────── */

    const INSTALLED_KEY = 'catering-menu:installed';
    function markInstalled() {
        try { localStorage.setItem(INSTALLED_KEY, new Date().toISOString()); } catch { /* */ }
    }
    function installedAt() {
        try { return localStorage.getItem(INSTALLED_KEY); } catch { return null; }
    }

    async function installApp() {
        if (!installPrompt) return;
        installPrompt.prompt();
        const { outcome } = await installPrompt.userChoice;
        installPrompt = null;
        if (outcome === 'accepted') { markInstalled(); toast('✓ האפליקציה הותקנה — "ניחוחות" במסך הבית'); }
        renderPushCard();
    }

    function renderInstallBox() {
        const box = $('#installBox');
        if (!box) return;
        box.textContent = '';
        const line = (cls, title, text) => box.append(el('p', { class: 'install-title ' + cls }, title), text ? el('p', { class: 'push-text' }, text) : '');

        if (isStandalone) {
            box.className = 'install-box done';
            line('', '✓ פתוח עכשיו כאפליקציה', 'ההתחברות נשמרת 4 חודשים, וההתראות מגיעות גם כשהאפליקציה סגורה.');
        } else if (installedAt()) {
            box.className = 'install-box done';
            line('', `✓ האפליקציה הותקנה ב${deviceName} הזה`,
                `הותקנה ב-${new Date(installedAt()).toLocaleDateString('he-IL')}. פתחו את "ניחוחות" ממסך הבית.`);
        } else if (installPrompt) {
            box.className = 'install-box';
            line('', 'אפשר להתקין את ממשק המנהל כאפליקציה', 'אייקון במסך הבית, נפתח במסך מלא, ובאנדרואיד אפשר לבחור לו צליל התראות משלו.');
            box.append(el('button', { type: 'button', class: 'btn btn-gold btn-wide', onclick: installApp }, '📲 התקנה כאפליקציה'));
        } else if (isIOS) {
            box.className = 'install-box';
            line('', 'התקנה באייפון — דרך ספארי', '');
            box.append(el('ol', { class: 'push-steps' },
                el('li', {}, el('b', {}, '1'), el('span', {}, 'לחצו על כפתור השיתוף ⬆️ בתחתית ספארי')),
                el('li', {}, el('b', {}, '2'), el('span', {}, 'בחרו "הוסף למסך הבית" ← "הוסף"')),
                el('li', {}, el('b', {}, '3'), el('span', {}, 'פתחו את "ניחוחות" ממסך הבית'))));
        } else {
            box.className = 'install-box';
            line('', 'התקנה כאפליקציה', isAndroid
                ? 'בכרום: תפריט ⋮ (למעלה) ← "התקנת אפליקציה" או "הוספה למסך הבית". אם האפשרות לא מופיעה — כנראה שהאפליקציה כבר מותקנת.'
                : 'בכרום או Edge: לוחצים על סמל ההתקנה בשורת הכתובת (מסך עם חץ).');
            box.append(el('button', { type: 'button', class: 'btn btn-ghost btn-wide', onclick: () => { markInstalled(); renderPushCard(); } },
                'כבר התקנתי — לסמן'));
        }
    }

    /** איך מפעילים צליל — לכל סוג מכשיר, כי הצליל נקבע בהגדרות הטלפון ולא באתר. */
    function renderSoundHelp() {
        const box = $('#soundHelp');
        if (!box) return;
        box.textContent = '';
        const steps = list => el('ol', { class: 'push-steps' },
            ...list.map((t, i) => el('li', {}, el('b', {}, String(i + 1)), el('span', {}, t))));
        const app = isStandalone || installedAt();
        if (isIOS) {
            box.append(el('p', { class: 'push-text' }, 'באייפון הצליל נקבע בהגדרות הטלפון:'), steps([
                'הגדרות ← התראות ← ניחוחות',
                'להפעיל "צלילים" ו"באנרים" (ומומלץ "מסך נעילה")',
                'לוודא שמתג השקט בצד הטלפון כבוי, ושמצב "ריכוז" / "נא לא להפריע" כבוי',
            ]));
        } else if (isAndroid) {
            box.append(el('p', { class: 'push-text' }, 'באנדרואיד הצליל נקבע בהגדרות הטלפון:'), steps(app ? [
                'הגדרות ← אפליקציות ← ניחוחות ← התראות',
                'לבחור את הקטגוריה של ההתראות ← "צליל" (אפשר לבחור צליל משלה) ו"הצגה במסך"',
            ] : [
                'הגדרות ← אפליקציות ← Chrome ← התראות',
                'לחפש את האתר (mbe-plus.com) ← "צליל" ו"הצגה במסך"',
                'טיפ: אחרי התקנה כאפליקציה (למעלה) — "ניחוחות" מופיעה כאפליקציה נפרדת עם צליל משלה',
            ]));
        } else {
            box.append(el('p', { class: 'push-text' },
                'במחשב: בהגדרות ההתראות של מערכת ההפעלה — לאפשר צליל להתראות של הדפדפן.'));
        }
        box.append(el('p', { class: 'push-note' }, 'לבדיקה: "בדיקה בלי שרת" בכרטיס ההתראות שלמעלה מקפיצה התראה מיד.'));
    }

    async function enablePush() {
        try {
            const perm = await Notification.requestPermission();
            if (perm !== 'granted') { renderPushCard(); return toast('לא אושרו התראות'); }
            swReg ??= await navigator.serviceWorker.register('sw.js');
            await navigator.serviceWorker.ready;
            const { key } = await api('pushKey');
            const raw = Uint8Array.from(atob(key.replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0));
            pushSub = await swReg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: raw });
            await api('pushSubscribe', { subscription: pushSub.toJSON(), device: deviceName });
            renderPushCard();
            toast('✓ ההתראות הופעלו — שולחים התראת בדיקה…');
            testPush();
        } catch (ex) {
            toast('הפעלת ההתראות נכשלה: ' + ex.message);
            refreshPushState();
        }
    }

    /** מה ענה שירות ההתראות (גוגל/אפל/מוזילה) לכל מכשיר — בעברית, עם הקוד המקורי. */
    function explainPush(d) {
        const svc = /apple/.test(d.service) ? 'אפל' : /google|fcm/.test(d.service) ? 'גוגל' : /mozilla/.test(d.service) ? 'מוזילה' : d.service;
        if (d.code >= 200 && d.code < 300) return [true, `${svc} קיבל את ההתראה (${d.code}) — היא בדרך לטלפון`];
        const why = {
            0: 'השרת לא הצליח להתחבר לשירות ההתראות',
            400: 'הבקשה נדחתה כפגומה',
            401: 'האימות (VAPID) נדחה',
            403: 'האימות (VAPID) נדחה — מפתח לא תואם או אסימון לא תקין',
            404: 'ההרשמה של המכשיר כבר לא קיימת — צריך להפעיל מחדש',
            410: 'ההרשמה של המכשיר בוטלה — צריך להפעיל מחדש',
            413: 'ההודעה גדולה מדי',
            429: 'יותר מדי בקשות — לנסות שוב בעוד דקה',
        }[d.code] || 'שגיאה בשירות ההתראות';
        return [false, `${svc}: ${why} (${d.code}${d.info ? ' · ' + d.info : ''})`];
    }

    async function testPush() {
        try {
            const r = await api('pushTest', {});
            const box = el('div', { class: 'push-report' });
            if (!r.details?.length) {
                box.append(el('p', {}, '⚠️ השרת לא מכיר אף מכשיר רשום. לחצו "כיבוי" ואז הפעילו שוב.'));
            }
            for (const d of r.details || []) {
                const [ok, text] = explainPush(d);
                box.append(el('p', { class: ok ? 'ok' : 'bad' }, `${ok ? '✓' : '✕'} ${d.device || 'מכשיר'}: ${text}`));
            }
            if (r.sent) box.append(el('p', { class: 'hint' },
                'אם ההתראה לא הופיעה תוך דקה — לחצו "בדיקה בלי שרת". אם גם היא לא מופיעה, ההתראות חסומות בהגדרות הטלפון.'));
            pushReport = box;
            renderPushCard();
        } catch (ex) { toast(ex.message); }
    }

    /** מציג התראה ישירות מהטלפון, בלי שרת ובלי גוגל/אפל — בודק רק שהטלפון מציג התראות. */
    async function localTest() {
        try {
            const reg = swReg || await navigator.serviceWorker.ready;
            await reg.showNotification('🔔 בדיקה מקומית — ניחוחות', {
                body: 'אם רואים את זה — הטלפון מציג התראות של האתר.',
                icon: '../assets/icon-192.png', badge: '../assets/badge-96.png',
                tag: 'local-test', renotify: true, vibrate: [200, 100, 200], lang: 'he', dir: 'rtl',
            });
            toast('נשלחה התראה מקומית — אמורה להופיע עכשיו');
        } catch (ex) { toast('התראה מקומית נכשלה: ' + ex.message); }
    }

    async function disablePush() {
        if (!pushSub) return;
        const endpoint = pushSub.endpoint;
        try { await pushSub.unsubscribe(); } catch { /* */ }
        try { await api('pushUnsubscribe', { endpoint }); } catch { /* */ }
        pushSub = null;
        renderPushCard();
        toast('ההתראות כובו במכשיר הזה');
    }

    /* ── הגדרות ──────────────────────────────────────────────────── */

    const loc = () => {
        const l = settings?.location || { lat: 31.778, lon: 35.235, candleOffset: 40, name: 'ירושלים' };
        return { ...l, havdalah: { mode: l.havdalahMode, minutes: l.havdalahMinutes } };
    };

    function setSettingsTab(tab) {
        document.querySelectorAll('.set-tabs [data-tab]').forEach(b => b.setAttribute('aria-selected', String(b.dataset.tab === tab)));
        document.querySelectorAll('.set-panel').forEach(p => { p.hidden = p.dataset.panel !== tab; });
        // העדפות אישיות ואבטחה נשמרות בפני עצמן — בלי כפתור "שמירת ההגדרות"
        $('#setSaveBar').hidden = tab === 'personal' || tab === 'security';
        $('#setError').hidden = true;
        $('.set-tabs [aria-selected="true"]')?.scrollIntoView({ inline: 'nearest', block: 'nearest' });
        if (tab === 'reminders') loadUpcoming();
    }

    async function openSettings(tab = 'calendar') {
        try {
            const res = await api('settings');
            settings = res.settings;
            logoInfo = res.logo;
        } catch (ex) { toast(ex.message); return; }
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
        const { menu } = await api('menuAdmin');
        for (const c of menu.categories) {
            box.append(el('label', {}, `${c.name} (${c.items.length})`,
                el('input', { type: 'number', min: '1', max: '99', inputmode: 'numeric', 'data-cat': c.id,
                    placeholder: 'ללא', value: settings.limits[c.id] ?? '' })));
        }
        $('#setAdminMail').value = settings.email.adminNotify;
        $('#setReplyTo').value = settings.email.replyTo;
        $('#setFrom').value = settings.email.from;
        for (const k of ['showShabbat', 'showParasha', 'showSunset', 'showRc']) {
            $('#set' + k.slice(4)).checked = settings.calendar[k] !== false;
        }
        $('#setSticky').checked = settings.notify.sticky !== false;
        fillReminders(settings.reminders);
        fillBusiness(settings.business);
        // אישי
        $('#prefStart').value = prefs.start;
        $('#prefCalMode').value = prefs.calMode || calMode;
        $('#prefFilter').value = prefs.filter;
        $('#prefChime').checked = prefs.chime !== false;
        $('#prefBigText').checked = !!prefs.bigText;
        $('#prefSaved').hidden = true;
        // אבטחה
        for (const id of ['#pwCurrent', '#pwNew', '#pwConfirm']) $(id).value = '';
        $('#secError').hidden = true;
        renderPushCard();
        syncSettingsForm();
        setSettingsTab(tab);
        $('#settingsSheet').showModal();
    }

    function savePersonal() {
        prefs.start = $('#prefStart').value;
        prefs.calMode = $('#prefCalMode').value;
        prefs.filter = $('#prefFilter').value;
        prefs.chime = $('#prefChime').checked;
        prefs.bigText = $('#prefBigText').checked;
        savePrefs();
        applyPrefs();
        $('#prefSaved').hidden = false;
    }

    function applyPrefs() {
        document.documentElement.classList.toggle('big-text', !!prefs.bigText);
    }

    async function changePassword() {
        const err = $('#secError');
        err.hidden = true;
        const fail = m => { err.textContent = m; err.hidden = false; };
        if ($('#pwNew').value.length < 6) return fail('הסיסמה החדשה צריכה לפחות 6 תווים');
        if ($('#pwNew').value !== $('#pwConfirm').value) return fail('הסיסמאות החדשות אינן תואמות');
        try {
            await api('changePassword', { current: $('#pwCurrent').value, password: $('#pwNew').value, logoutOthers: $('#pwLogoutOthers').checked });
            for (const id of ['#pwCurrent', '#pwNew', '#pwConfirm']) $(id).value = '';
            toast($('#pwLogoutOthers').checked ? '✓ הסיסמה שונתה, ושאר המכשירים נותקו' : '✓ הסיסמה שונתה');
        } catch (ex) { fail(ex.message); }
    }

    async function logoutOthers() {
        if (!confirm('לנתק את כל שאר המכשירים? הם יצטרכו להיכנס שוב עם הסיסמה. המכשיר הזה נשאר מחובר.')) return;
        try {
            await api('logoutOthers', {});
            toast('✓ כל שאר המכשירים נותקו');
        } catch (ex) { $('#secError').textContent = ex.message; $('#secError').hidden = false; }
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
                calendar: {
                    showShabbat: $('#setShabbat').checked, showParasha: $('#setParasha').checked,
                    showSunset: $('#setSunset').checked, showRc: $('#setRc').checked,
                },
                notify: { sticky: $('#setSticky').checked },
                reminders: formReminders(),
                business: formBusiness(),
            })).settings;
            $('#settingsSheet').close();
            toast('ההגדרות נשמרו');
            renderCalendar();
        } catch (ex) {
            $('#setError').textContent = ex.message;
            $('#setError').hidden = false;
        }
    }

    /* ── עסק: לוגו ופרטי קשר ─────────────────────────────────────── */

    let logoInfo = { custom: false, version: 0 };
    const logoUrl = () => '../api.php?action=logo&v=' + logoInfo.version;

    function fillBusiness(b) {
        $('#bizName').value = b.name || '';
        $('#bizOwner').value = b.owner || '';
        ['#bizPhone1', '#bizPhone2', '#bizPhone3'].forEach((id, i) => { $(id).value = b.phones?.[i] || ''; });
        $('#bizWhatsapp').value = b.whatsapp || '';
        $('#bizExtraNote').value = b.extraNote || '';
        renderLogoBox();
        previewContact();
    }

    function formBusiness() {
        return {
            name: $('#bizName').value.trim(),
            tagline: settings.business?.tagline || '',
            owner: $('#bizOwner').value.trim(),
            phones: ['#bizPhone1', '#bizPhone2', '#bizPhone3'].map(id => $(id).value.trim()).filter(Boolean),
            whatsapp: $('#bizWhatsapp').value.replace(/\D/g, ''),
            extraNote: $('#bizExtraNote').value.trim(),
        };
    }

    /** כך תיראה השורה שמתחת ללוגו בתפריט להדפסה. */
    function previewContact() {
        const b = formBusiness();
        const line = [b.owner ? b.owner + '.' : '', b.phones.join(' / ')].filter(Boolean).join(' ');
        $('#bizPreview').textContent = line ? 'בתפריט להדפסה: ' + line : 'אין פרטי קשר — השורה שמתחת ללוגו תישאר ריקה.';
    }

    function renderLogoBox() {
        $('#bizLogo').src = logoUrl();
        $('#bizLogoState').textContent = logoInfo.custom ? 'לוגו שהועלה' : 'הלוגו המקורי';
        $('#bizLogoReset').hidden = !logoInfo.custom;
        for (const img of document.querySelectorAll('.brand-logo')) img.src = logoUrl();
    }

    /** תמונה גדולה מוקטנת בדפדפן (עד 1600 פיקסלים לרוחב) — כדי שההעלאה תהיה קלה. */
    async function shrinkImage(file) {
        const img = await new Promise((resolve, reject) => {
            const i = new Image();
            i.onload = () => resolve(i);
            i.onerror = () => reject(new Error('הקובץ אינו תמונה שאפשר לפתוח'));
            i.src = URL.createObjectURL(file);
        });
        const k = Math.min(1, 1600 / img.naturalWidth);
        if (k === 1 && file.size < 2.5 * 1024 * 1024) {
            return new Promise(r => { const fr = new FileReader(); fr.onload = () => r(fr.result); fr.readAsDataURL(file); });
        }
        const c = el('canvas');
        c.width = Math.round(img.naturalWidth * k);
        c.height = Math.round(img.naturalHeight * k);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        return file.type === 'image/jpeg' ? c.toDataURL('image/jpeg', 0.9) : c.toDataURL('image/png');
    }

    async function uploadLogo(e) {
        const file = e.target.files[0];
        e.target.value = '';
        if (!file) return;
        try {
            const data = await shrinkImage(file);
            logoInfo = (await api('saveLogo', { data })).logo;
            renderLogoBox();
            toast('✓ הלוגו הוחלף');
        } catch (ex) { toast(ex.message); }
    }

    async function resetLogo() {
        if (!confirm('לחזור ללוגו המקורי? הלוגו שהועלה יימחק.')) return;
        try {
            logoInfo = (await api('resetLogo', {})).logo;
            renderLogoBox();
            toast('✓ חזרנו ללוגו המקורי');
        } catch (ex) { toast(ex.message); }
    }

    /* ── התפריט להדפסה ──────────────────────────────────────────────── */

    let pdfData = null;             // { menu, logo } — נטען בפתיחת החלון
    let pdfStyle = null;            // עיצוב הכתב הנוכחי
    let pdfOut = null;              // הקובץ המוכן להורדה / שיתוף
    let pdfVersion = 0;             // מונה שינויים — כדי שציור ישן לא ידרוס חדש
    let previewTimer = 0, fullTimer = 0, saveTimer = 0;

    async function openPdf() {
        if (dirty()) {
            if (!confirm('יש בתפריט שינויים שלא נשמרו. לשמור אותם עכשיו? (ה-PDF נוצר מהתפריט השמור)')) return;
            await saveMenu();
            if (dirty()) return;           // השמירה נכשלה
        }
        pdfOut = null;
        $('#pdfInfo').textContent = 'מכין את התפריט…';
        $('#pdfImg').removeAttribute('src');
        $('#pdfDownload').disabled = true;
        $('#pdfShare').hidden = true;
        $('#pdfStyleSaved').hidden = true;
        $('#pdfSheet').showModal();
        try {
            const [{ menu }, res, { fonts }, logo] = await Promise.all([
                api('menuAdmin'),
                api('settings'),
                api('fonts'),
                new Promise(resolve => {
                    const i = new Image();
                    i.onload = () => resolve(i);
                    i.onerror = () => resolve(null);
                    i.src = '../api.php?action=logo&t=' + Date.now();
                }),
            ]);
            pdfData = { menu, logo };
            setFonts(fonts);
            pdfStyle = MenuPdf.cleanStyle(res.settings.print);
            fillStyleForm();
            await refreshPdf();
        } catch (ex) {
            $('#pdfInfo').textContent = 'יצירת התפריט נכשלה: ' + ex.message;
        }
    }

    /** הטופס של עיצוב הכתב, לפי pdfStyle. */
    function fillStyleForm() {
        for (const box of document.querySelectorAll('.pdf-part')) {
            const s = pdfStyle[box.dataset.part];
            const font = box.querySelector('[data-k=font]');
            if (font.options.length !== Object.keys(MenuPdf.FONTS).length) {
                font.textContent = '';
                for (const [id, f] of Object.entries(MenuPdf.FONTS)) font.append(el('option', { value: id }, f.name));
            }
            font.value = s.font;
            const weight = box.querySelector('[data-k=weight]');
            weight.textContent = '';
            const ws = MenuPdf.weightsOf(s.font);
            for (const w of ws) weight.append(el('option', { value: w }, MenuPdf.WEIGHT_NAMES[w] || String(w)));
            weight.value = s.weight;
            weight.disabled = ws.length < 2;
            for (const k of ['size', 'width']) {
                const r = box.querySelector(`[data-k=${k}]`);
                r.value = s[k];
                r.nextElementSibling.textContent = s[k] + '%';
            }
        }
    }

    function readStyleForm(e) {
        const box = e.target.closest('.pdf-part');
        if (!box || !e.target.dataset.k) return;
        const s = pdfStyle[box.dataset.part];
        const k = e.target.dataset.k;
        s[k] = k === 'font' ? e.target.value : Number(e.target.value);
        pdfStyle = MenuPdf.cleanStyle(pdfStyle);   // גופן חדש — העובי הקרוב שקיים בו
        fillStyleForm();
        $('#pdfStyleSaved').hidden = true;
        schedulePdf();
        clearTimeout(saveTimer);
        saveTimer = setTimeout(savePdfStyle, 800);
    }

    async function savePdfStyle() {
        try {
            pdfStyle = MenuPdf.cleanStyle((await api('savePrint', pdfStyle)).print);
            $('#pdfStyleSaved').hidden = false;
        } catch (ex) { toast('שמירת העיצוב נכשלה: ' + ex.message); }
    }

    function resetPdfStyle() {
        pdfStyle = MenuPdf.cleanStyle(JSON.parse(JSON.stringify(MenuPdf.DEFAULT_STYLE)));
        fillStyleForm();
        schedulePdf();
        savePdfStyle();
    }

    /* ── גופנים מותאמים ─────────────────────────────────────────── */

    let customFonts = [];

    function setFonts(list) {
        customFonts = list;
        MenuPdf.setCustomFonts(list.map(f => ({ id: f.id, name: f.name, url: '../api.php?action=font&id=' + f.id })));
        const ul = $('#fontList');
        ul.textContent = '';
        if (!list.length) ul.append(el('li', { class: 'rem-empty' }, 'עוד לא הועלו גופנים.'));
        for (const f of list) {
            ul.append(el('li', {},
                el('span', {}, '⭐ ' + f.name, el('small', {}, ` · ${f.ext.toUpperCase()} · ${Math.round(f.size / 1024)}KB`)),
                smallBtn('🗑', 'מחיקת הגופן', () => deleteFont(f))));
        }
    }

    async function uploadFont(e) {
        const file = e.target.files[0];
        e.target.value = '';
        if (!file) return;
        if (file.size > 8 * 1024 * 1024) { toast('קובץ הגופן גדול מדי (עד 8MB)'); return; }
        const name = $('#fontName').value.trim() || file.name.replace(/\.[^.]+$/, '').replace(/[-_]+/g, ' ');
        try {
            const data = await new Promise((resolve, reject) => {
                const fr = new FileReader();
                fr.onload = () => resolve(fr.result);
                fr.onerror = () => reject(new Error('קריאת הקובץ נכשלה'));
                fr.readAsDataURL(file);
            });
            const res = await api('saveFont', { name, data });
            $('#fontName').value = '';
            setFonts(res.fonts);
            // בדיקה שהדפדפן מצליח לפתוח את הגופן — ורק אז הוא נבחר לכותרות
            try {
                await new FontFace('test-' + res.font.id, `url(../api.php?action=font&id=${res.font.id})`).load();
            } catch {
                toast('הגופן הועלה, אבל הדפדפן לא מצליח לפתוח אותו — כדאי לנסות קובץ אחר');
                fillStyleForm();
                return;
            }
            pdfStyle.title.font = res.font.id;
            pdfStyle = MenuPdf.cleanStyle(pdfStyle);
            fillStyleForm();
            schedulePdf();
            clearTimeout(saveTimer);
            saveTimer = setTimeout(savePdfStyle, 300);
            toast(`✓ הגופן "${res.font.name}" הועלה, והוחל על הכותרות`);
        } catch (ex) { toast(ex.message); }
    }

    async function deleteFont(f) {
        const inUse = ['title', 'item'].some(p => pdfStyle[p].font === f.id);
        if (!confirm(`למחוק את הגופן "${f.name}"?` + (inUse ? ' הוא בשימוש בתפריט, ובמקומו יחזור הגופן המקורי.' : ''))) return;
        try {
            setFonts((await api('deleteFont', { id: f.id })).fonts);
            pdfStyle = MenuPdf.cleanStyle(pdfStyle);
            fillStyleForm();
            if (inUse) { schedulePdf(); savePdfStyle(); }
        } catch (ex) { toast(ex.message); }
    }

    /** תצוגה מקדימה מהירה מיד, והקובץ המלא (300dpi) כשמפסיקים לכוונן. */
    function schedulePdf() {
        const v = ++pdfVersion;
        pdfOut = null;
        $('#pdfDownload').disabled = true;
        clearTimeout(previewTimer);
        clearTimeout(fullTimer);
        previewTimer = setTimeout(() => renderPreview(v), 120);
        fullTimer = setTimeout(() => buildPdf(v), 1200);
    }

    async function refreshPdf() {
        const v = ++pdfVersion;
        await renderPreview(v);
        await buildPdf(v);
    }

    async function renderPreview(v) {
        const out = await MenuPdf.render(pdfData.menu, pdfData.logo, '../assets/', pdfStyle, true);
        if (v !== pdfVersion) return;
        $('#pdfImg').src = out.canvas.toDataURL('image/jpeg', 0.85);
        const cm = Math.round(out.height / MenuPdf.A4 * 29.7 * 10) / 10;
        $('#pdfInfo').textContent = out.height > MenuPdf.A4
            ? `התפריט ארוך מ-A4, ולכן הדף הוארך: 21 × ${cm} ס"מ. שום דבר לא נדחס.`
            : 'דף A4 אחד (21 × 29.7 ס"מ). מתעדכן לבד לפי התפריט, הלוגו ופרטי הקשר.';
    }

    async function buildPdf(v) {
        const out = await MenuPdf.render(pdfData.menu, pdfData.logo, '../assets/', pdfStyle);
        if (v !== pdfVersion) return;
        const blob = await MenuPdf.toPdf(out, 'תפריט ' + (pdfData.menu.business?.name || ''));
        if (v !== pdfVersion) return;
        // שם באנגלית: דפדפנים מסוימים (כרום) מחליפים שם קובץ בעברית ב-"download"
        const name = `menu-${new Date().toISOString().slice(0, 10)}.pdf`;
        pdfOut = { blob, file: new File([blob], name, { type: 'application/pdf' }) };
        $('#pdfDownload').disabled = false;
        $('#pdfShare').hidden = !(navigator.canShare && navigator.canShare({ files: [pdfOut.file] }));
    }

    function downloadPdf() {
        if (!pdfOut) return;
        const a = el('a', { href: URL.createObjectURL(pdfOut.blob), download: pdfOut.file.name });
        document.body.append(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(a.href), 60000);
    }

    async function sharePdf() {
        if (!pdfOut) return;
        try { await navigator.share({ files: [pdfOut.file], title: pdfOut.file.name }); }
        catch (ex) { if (ex.name !== 'AbortError') toast('השיתוף נכשל — אפשר להוריד את הקובץ'); }
    }

    /* ── תזכורות ─────────────────────────────────────────────────── */

    const TICK_URL = new URL('../api.php?action=tick', location.href).href;
    const MAX_RULES = 6;
    let remRules = [];              // עותק העבודה של רשימת התזכורות

    /** "יומיים לפני ב-09:00", "3 שעות לפני" — כדי שכל שורה תיקרא כמשפט. */
    function ruleText(r) {
        if (r.type === 'hours') return r.hours === 1 ? 'שעה לפני האירוע' : `${r.hours} שעות לפני האירוע`;
        const day = { 0: 'ביום האירוע', 1: 'יום לפני', 2: 'יומיים לפני' }[r.days] ?? `${r.days} ימים לפני`;
        return `${day} ב-${r.time}`;
    }

    function renderRemRules() {
        const box = $('#remRules');
        box.textContent = '';
        if (!remRules.length) box.append(el('p', { class: 'rem-empty' }, 'אין תזכורות לפני אירועים — הוסיפו אחת.'));
        remRules.forEach((r, i) => {
            const num = el('input', {
                type: 'number', inputmode: 'numeric', 'aria-label': r.type === 'hours' ? 'שעות' : 'ימים',
                min: r.type === 'hours' ? '1' : '0', max: r.type === 'hours' ? '72' : '30',
                value: r.type === 'hours' ? r.hours : r.days,
                oninput: e => {
                    const v = Math.round(Number(e.target.value));
                    if (Number.isFinite(v) && e.target.value !== '') r[r.type === 'hours' ? 'hours' : 'days'] = v;
                    row.title = ruleText(r);
                },
            });
            const kind = el('select', {
                'aria-label': 'סוג התזכורת',
                onchange: e => {
                    remRules[i] = e.target.value === 'hours' ? { type: 'hours', hours: 3 } : { type: 'days', days: 1, time: '09:00' };
                    renderRemRules();
                },
            }, el('option', { value: 'days' }, 'ימים לפני'), el('option', { value: 'hours' }, 'שעות לפני'));
            kind.value = r.type;
            const time = r.type === 'days' ? el('input', {
                type: 'time', value: r.time, 'aria-label': 'שעה',
                oninput: e => { if (e.target.value) r.time = e.target.value; row.title = ruleText(r); },
            }) : null;
            const row = el('div', { class: 'rem-rule' + (time ? '' : ' no-time'), title: ruleText(r) }, el('span', { class: 'rem-bell' }, '🔔'), num, kind, time,
                smallBtn('✕', 'הסרת התזכורת', () => { remRules.splice(i, 1); renderRemRules(); }, 'rem-del'));
            box.append(row);
        });
        $('#remAdd').hidden = remRules.length >= MAX_RULES;
    }

    function fillReminders(r) {
        remRules = r.rules.map(x => ({ ...x }));
        $('#remOn').checked = r.on;
        $('#remWho').value = r.who;
        $('#remSkipDone').checked = r.skipDone;
        $('#remMail').checked = r.mail;
        $('#remDigestOn').checked = r.digest.on;
        $('#remDigestTime').value = r.digest.time;
        $('#remNagOn').checked = r.nag.on;
        $('#remNagHours').value = r.nag.hours;
        $('#remQuietOn').checked = r.quiet.on;
        $('#remQuietFrom').value = r.quiet.from;
        $('#remQuietTo').value = r.quiet.to;
        renderRemRules();
        syncReminders();
    }

    /** חלקים שתלויים במתג — מוסתרים או מושבתים כשהמתג כבוי. */
    function syncReminders() {
        $('#remBox').hidden = !$('#remOn').checked;
        $('#remDigestTime').disabled = !$('#remDigestOn').checked;
        $('#remNagHours').disabled = !$('#remNagOn').checked;
        $('#remQuietFrom').disabled = $('#remQuietTo').disabled = !$('#remQuietOn').checked;
    }

    function formReminders() {
        return {
            on: $('#remOn').checked,
            rules: remRules,
            who: $('#remWho').value,
            skipDone: $('#remSkipDone').checked,
            mail: $('#remMail').checked,
            digest: { on: $('#remDigestOn').checked, time: $('#remDigestTime').value || '08:00' },
            nag: { on: $('#remNagOn').checked, hours: Number($('#remNagHours').value) || 3 },
            quiet: { on: $('#remQuietOn').checked, from: $('#remQuietFrom').value || '22:00', to: $('#remQuietTo').value || '07:00' },
        };
    }

    /** "מחר 09:00" — תמיד בשעון ישראל, כמו שהשרת מתזמן, גם בטלפון שמכוון לחו"ל. */
    function fmtWhen(iso) {
        const TZ = 'Asia/Jerusalem';
        const ymd = d => d.toLocaleDateString('en-CA', { timeZone: TZ });
        const d = new Date(iso);
        const days = Math.round((Date.parse(ymd(d)) - Date.parse(ymd(new Date()))) / 864e5);
        const hm = d.toLocaleTimeString('he-IL', { hour: '2-digit', minute: '2-digit', timeZone: TZ });
        const day = { 0: 'היום', 1: 'מחר', 2: 'מחרתיים' }[days]
            ?? d.toLocaleDateString('he-IL', { weekday: 'short', day: 'numeric', month: 'numeric', timeZone: TZ });
        return `${day} ${hm}`;
    }

    function ago(iso) {
        const min = Math.round((Date.now() - new Date(iso)) / 60000);
        if (min < 1) return 'עכשיו';
        if (min < 60) return `לפני ${min} דק׳`;
        if (min < 48 * 60) return `לפני ${Math.round(min / 60)} שע׳`;
        return `לפני ${Math.round(min / 1440)} ימים`;
    }

    /** מה ייצא בקרוב, ומצב "השעון" — האם cron של השרת מתקתק. */
    async function loadUpcoming() {
        const box = $('#remUpcoming');
        box.textContent = 'טוען…';
        let data;
        try { data = await api('reminders'); } catch (ex) { box.textContent = ex.message; return; }
        box.textContent = '';
        if (!data.list.length) {
            box.append(el('p', { class: 'rem-empty' }, 'אין תזכורות מתוכננות ב-14 הימים הקרובים.'));
        }
        for (const r of data.list) {
            box.append(el('button', {
                type: 'button', class: 'rem-item',
                onclick: () => { $('#settingsSheet').close(); openOrder(r.order); },
            }, el('span', {}, `⏰ ${r.name}`, el('small', {}, ` · האירוע ${fmtWhen(r.event)}`)),
               el('small', {}, fmtWhen(r.at) + (r.quiet ? ' 🌙' : ''))));
        }
        if (data.list.length) box.append(el('p', { class: 'set-hint' }, 'לפי ההגדרות השמורות. שיניתם משהו? שמרו כדי לראות את הרשימה המעודכנת.'));

        const clock = $('#remClock');
        clock.textContent = '';
        const cronOk = data.lastCron && Date.now() - new Date(data.lastCron) < 30 * 60000;
        clock.classList.toggle('ok', !!cronOk);
        if (cronOk) {
            clock.append(el('b', {}, '✓ השעון האוטומטי של השרת פועל'),
                el('span', {}, `בדיקה אחרונה ${ago(data.lastCron)}. התזכורות ייצאו בזמן גם כשאף אחד לא נכנס לאתר.`));
            return;
        }
        clock.append(
            el('b', {}, '⚠️ כדי שהתזכורות ייצאו בדיוק בזמן — צריך להפעיל פעם אחת שעון בשרת'),
            el('span', {}, 'בינתיים התזכורות נבדקות כשהממשק הזה פתוח או כשלקוח נכנס לתפריט'
                + (data.lastTick ? ` (בדיקה אחרונה ${ago(data.lastTick)})` : '') + '. אם אף אחד לא נכנס — התזכורת תתעכב עד הכניסה הבאה.'),
            el('span', {}, 'ב-cPanel: Cron Jobs ← Common Settings: Once Per Five Minutes ← בשדה Command להדביק:'),
            el('code', {}, `curl -s "${TICK_URL}" >/dev/null`),
            el('button', {
                type: 'button', class: 'btn btn-ghost',
                onclick: async e => {
                    try { await navigator.clipboard.writeText(`curl -s "${TICK_URL}" >/dev/null`); e.target.textContent = '✓ הועתק'; }
                    catch { e.target.textContent = 'סמנו והעתיקו ידנית'; }
                },
            }, '📋 העתקת הפקודה'),
            el('span', { class: 'set-hint' }, 'אחרי ההוספה, תוך כמה דקות יופיע כאן ✓.'));
    }

    async function testReminder() {
        try {
            const r = await api('reminderTest', {});
            toast(r.sent ? `✓ נשלחה ל-${r.sent} מכשירים` : 'אין מכשיר רשום להתראות — מפעילים בלשונית "אפליקציה והתראות"');
        } catch (ex) { toast(ex.message); }
    }

    function wireReminders() {
        $('#remAdd').addEventListener('click', () => {
            if (remRules.length >= MAX_RULES) return;
            remRules.push({ type: 'days', days: 1, time: '09:00' });
            renderRemRules();
        });
        for (const id of ['#remOn', '#remDigestOn', '#remNagOn', '#remQuietOn']) $(id).addEventListener('change', syncReminders);
        $('#remTest').addEventListener('click', testReminder);
    }

    /* ── עריכת התפריט ────────────────────────────────────────────── */

    let edit = null;                // עותק העבודה של התפריט
    let savedJson = '';             // התפריט כפי שנשמר — להשוואה (יש שינויים?)
    let customMenu = false;         // האם יש בשרת תפריט ערוך (ולא המקורי)

    const dirty = () => edit && JSON.stringify(edit) !== savedJson;

    async function loadMenuEditor() {
        try {
            const res = await api('menuAdmin');
            setEdit(res.menu, res.custom);
        } catch (ex) {
            $('#editorBody').textContent = ex.message;
        }
    }

    function setEdit(menu, custom) {
        edit = JSON.parse(JSON.stringify(menu));
        savedJson = JSON.stringify(edit);
        customMenu = custom;
        renderEditor();
    }

    function markDirty() {
        $('#saveBar').hidden = !dirty();
    }

    function moveIn(list, i, dir) {
        const j = i + dir;
        if (j < 0 || j >= list.length) return;
        [list[i], list[j]] = [list[j], list[i]];
        renderEditor();
    }

    function smallBtn(label, title, onclick, cls = '') {
        return el('button', { type: 'button', class: 'mini ' + cls, title, 'aria-label': title, onclick }, label);
    }

    function renderEditor() {
        const body = $('#editorBody');
        const y = window.scrollY;
        body.textContent = '';

        edit.categories.forEach((cat, ci) => {
            const list = el('ul', { class: 'ed-items' });
            cat.items.forEach((item, ii) => list.append(editorItem(cat, item, ii)));

            const nameInput = el('input', { class: 'ed-cat-name', value: cat.name, maxlength: '60', 'aria-label': 'שם הקטגוריה' });
            nameInput.addEventListener('input', () => { cat.name = nameInput.value; markDirty(); });

            body.append(el('section', { class: 'ed-cat' },
                el('header', { class: 'ed-cat-head' },
                    nameInput,
                    el('span', { class: 'ed-count' }, `${cat.items.filter(i => !i.hidden).length}/${cat.items.length}`),
                    smallBtn('↑', 'הזזת הקטגוריה למעלה', () => moveIn(edit.categories, ci, -1)),
                    smallBtn('↓', 'הזזת הקטגוריה למטה', () => moveIn(edit.categories, ci, 1)),
                    smallBtn('✕', 'מחיקת הקטגוריה', () => {
                        if (cat.items.length && !confirm(`למחוק את "${cat.name}" ואת ${cat.items.length} המנות שבה?`)) return;
                        edit.categories.splice(ci, 1);
                        renderEditor();
                    }, 'danger')),
                list,
                el('button', {
                    type: 'button', class: 'ed-add', onclick: () => {
                        cat.items.push({ name: '' });
                        renderEditor();
                        const inputs = document.querySelectorAll(`.ed-cat:nth-of-type(${ci + 1}) .ed-name`);
                        inputs[inputs.length - 1]?.focus();
                    },
                }, '+ מנה חדשה ב' + (cat.name || 'קטגוריה'))));
        });

        $('#resetMenu').hidden = !customMenu;
        markDirty();
        window.scrollTo(0, y);
    }

    function editorItem(cat, item, ii) {
        const name = el('input', { class: 'ed-name', value: item.name, maxlength: '120', placeholder: 'שם המנה', 'aria-label': 'שם המנה' });
        name.addEventListener('input', () => { item.name = name.value; markDirty(); });

        const extra = el('label', { class: 'tog' + (item.extra ? ' on' : '') },
            el('input', { type: 'checkbox', checked: item.extra ? true : null }), '* תוספת');
        extra.querySelector('input').addEventListener('change', e => {
            item.extra = e.target.checked || undefined;
            extra.classList.toggle('on', e.target.checked);
            markDirty();
        });

        const hasOpts = !!item.options?.length;
        const optsPanel = el('div', { class: 'ed-opts', hidden: hasOpts ? null : true });
        const optLabel = el('input', { value: item.optionLabel || '', placeholder: 'כותרת, למשל: סוג הדג', maxlength: '40' });
        const optList = el('input', { value: (item.options || []).join(', '), placeholder: 'אפשרויות מופרדות בפסיק: דניס, מושט, בורי', maxlength: '300' });
        const syncOpts = () => {
            const opts = optList.value.split(/[,،]/).map(x => x.trim()).filter(Boolean);
            if (opts.length) { item.options = opts; item.optionLabel = optLabel.value.trim() || undefined; }
            else { delete item.options; delete item.optionLabel; }
            markDirty();
        };
        optLabel.addEventListener('input', syncOpts);
        optList.addEventListener('input', syncOpts);
        optsPanel.append(
            el('label', {}, 'כותרת הבחירה', optLabel),
            el('label', {}, 'אפשרויות (לפחות 2) — הלקוח יחויב לבחור אחת', optList));

        return el('li', { class: 'ed-item' + (item.hidden ? ' is-hidden' : '') },
            name,
            el('div', { class: 'ed-tools' },
                extra,
                smallBtn(item.hidden ? '🙈 מוסתרת' : '👁', item.hidden ? 'מוסתרת — לחצו כדי להציג ללקוחות' : 'מוצגת — לחצו כדי להסתיר מהלקוחות', () => {
                    item.hidden = !item.hidden || undefined;
                    renderEditor();
                }, 'tog-btn' + (item.hidden ? ' off' : '')),
                smallBtn(hasOpts ? `☰ ${item.options.length}` : '☰', 'אפשרויות בחירה למנה (למשל סוג הדג)', () => {
                    optsPanel.hidden = !optsPanel.hidden;
                    if (!optsPanel.hidden) optList.focus();
                }, hasOpts ? 'tog-btn on' : 'tog-btn'),
                el('span', { class: 'spacer' }),
                smallBtn('↑', 'למעלה', () => moveIn(cat.items, ii, -1)),
                smallBtn('↓', 'למטה', () => moveIn(cat.items, ii, 1)),
                smallBtn('✕', 'מחיקת המנה', () => {
                    if (item.name && !confirm(`למחוק את "${item.name}"?`)) return;
                    cat.items.splice(ii, 1);
                    renderEditor();
                }, 'danger')),
            optsPanel);
    }

    async function saveMenu() {
        const btn = $('#saveMenu');
        btn.disabled = true;
        try {
            const res = await api('saveMenu', edit);
            setEdit(res.menu, res.custom);
            toast('✓ התפריט נשמר — הלקוחות כבר רואים את הגרסה החדשה');
        } catch (ex) {
            toast('השמירה נכשלה: ' + ex.message);
        } finally {
            btn.disabled = false;
        }
    }

    function wireMenuEditor() {
        $('#addCategory').addEventListener('click', () => {
            edit.categories.push({ name: 'קטגוריה חדשה', items: [{ name: '' }] });
            renderEditor();
            const names = document.querySelectorAll('.ed-cat-name');
            names[names.length - 1].select();
        });
        $('#bizLink').addEventListener('click', () => openSettings('business'));
        $('#openPdf').addEventListener('click', openPdf);
        $('#saveMenu').addEventListener('click', saveMenu);
        $('#discardMenu').addEventListener('click', () => {
            if (confirm('לבטל את כל השינויים שלא נשמרו?')) setEdit(JSON.parse(savedJson), customMenu);
        });
        $('#resetMenu').addEventListener('click', async () => {
            if (!confirm('לחזור לתפריט המקורי? כל העריכות יימחקו.')) return;
            try {
                const res = await api('resetMenu', {});
                setEdit(res.menu, res.custom);
                toast('התפריט חזר למקור');
            } catch (ex) { toast(ex.message); }
        });
        window.addEventListener('beforeunload', e => { if (dirty()) { e.preventDefault(); e.returnValue = ''; } });
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
        $('#menuEditor').hidden = v !== 'menu';
        if (v === 'calendar') renderCalendar();
        if (v === 'menu' && !edit) loadMenuEditor();
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

    const calShow = k => settings?.calendar?.[k] !== false;

    function holidayTags(d, short = false) {
        const list = hols(d).filter(h => h.type !== 'rc' || calShow('showRc'));
        const par = calShow('showParasha') ? HebCal.parasha(d.getFullYear(), d.getMonth() + 1, d.getDate()) : null;
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
        const shabbat = calShow('showShabbat');
        if (shabbat && z.candles) parts.push(el('span', { class: 'z candles', title: 'הדלקת נרות ' + z.candles }, icon('candles'), compact ? z.candles : `הדלקת נרות ${z.candles}`));
        if (shabbat && z.havdalah) parts.push(el('span', { class: 'z havdalah', title: `${z.havdalahLabel} ${z.havdalah}` }, icon('stars'), compact ? z.havdalah : `${z.havdalahLabel} ${z.havdalah}`));
        if (calShow('showSunset')) parts.push(el('span', { class: 'z sunset', title: 'שקיעה ' + z.sunset }, icon('sunset'), compact ? z.sunset : `שקיעה ${z.sunset}`));
        return parts.length ? el('div', { class: 'zmanim' + (compact ? ' compact' : '') }, ...parts) : null;
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
        wireMenuEditor();
        $('#bell').addEventListener('click', () => { renderNotes(); $('#notesSheet').showModal(); });
        $('#gear').addEventListener('click', () => openSettings());
        document.querySelectorAll('.set-tabs [data-tab]').forEach(b => b.addEventListener('click', () => setSettingsTab(b.dataset.tab)));
        document.querySelectorAll('[data-panel="personal"] select, [data-panel="personal"] input').forEach(i => i.addEventListener('change', savePersonal));
        $('#pwSave').addEventListener('click', changePassword);
        $('#logoutOthers').addEventListener('click', logoutOthers);
        applyPrefs();
        $('#markAllRead').addEventListener('click', markAllRead);
        $('#pushBanner').addEventListener('click', e => {
            if (e.target.id === 'pushBannerX') {
                try { localStorage.setItem('catering-menu:push-banner', 'no'); } catch { /* */ }
                $('#pushBanner').hidden = true;
                return;
            }
            openSettings('app');
        });
        initPush();
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
        $('#oRemOff').addEventListener('change', e => update({ remindersOff: e.target.checked }));
        wireReminders();
        $('#bizLogoFile').addEventListener('change', uploadLogo);
        $('#bizLogoReset').addEventListener('click', resetLogo);
        document.querySelectorAll('[data-panel="business"] input:not([type=file])').forEach(i => i.addEventListener('input', previewContact));
        $('#pdfDownload').addEventListener('click', downloadPdf);
        $('#pdfShare').addEventListener('click', sharePdf);
        $('#pdfStyle').addEventListener('input', readStyleForm);
        $('#pdfStyleReset').addEventListener('click', resetPdfStyle);
        $('#fontFile').addEventListener('change', uploadFont);
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
            // מסך הפתיחה: לפי ההעדפה האישית, או המסך האחרון
            const saved = JSON.parse(localStorage.getItem('catering-menu:view') || 'null');
            if (saved?.calMode) calMode = saved.calMode;
            if (prefs.calMode) calMode = prefs.calMode;
            const start = prefs.start === 'last' ? saved?.view : prefs.start;
            if (start) setView(start);
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
