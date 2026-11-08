// 🔒 גישות לאפליקציות חסומות — הרשאות ישירות לאפליקציות restricted (lib/restricted-access.php).
// נטען בלחיצה, מ-renderSystem ב-assets/admin.js. שתי קבוצות: ממתינות להחלטה, ונבדקו (מקופלת).

export async function open(P, onChange = () => {}) {
    const { el, toast, api } = P;
    let rows = [];
    const picked = new Set();               // "user_id|app" — רק מהממתינות
    const key = (r) => `${r.user_id}|${r.app}`;
    let showReviewed = false;

    const close = el('button', { type: 'button', className: 'icon-btn', textContent: '✕', ariaLabel: 'סגירה' });
    const body = el('div', { className: 'sheet-body' }, [el('p', { className: 'status', textContent: 'טוען…' })]);
    const dlg = el('dialog', { className: 'user-sheet' }, [el('div', { className: 'sheet' }, [
        el('div', { className: 'sheet-head' }, [el('div', { className: 'tx' }, [
            el('h2', { textContent: '🔒 גישות לאפליקציות חסומות' }),
            el('small', { textContent: 'מי קיבל הרשאה ישירה לאפליקציה מוגבלת — להשאיר או לבטל' }),
        ]), close]),
        body,
    ])]);
    close.addEventListener('click', () => dlg.close());
    dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });
    dlg.addEventListener('close', () => dlg.remove());
    document.body.append(dlg);
    dlg.showModal();

    const btn = (text, cls, onClick, extra = {}) => {
        const b = el('button', { type: 'button', className: `btn sm ${cls}`, textContent: text, ...extra });
        b.addEventListener('click', onClick);
        return b;
    };
    const fmt = (iso) => new Date(iso).toLocaleDateString('he-IL', { day: 'numeric', month: 'numeric', year: 'numeric' });

    async function load() {
        try { rows = (await api('admin_restricted')).rows; }
        catch (err) { body.replaceChildren(el('p', { className: 'status error', textContent: err.message })); return; }
        for (const k of [...picked]) if (!rows.some(r => !r.reviewed_at && key(r) === k)) picked.delete(k);
        draw();
    }

    async function decide(list, decision) {
        if (!list.length) return;
        if (decision === 'revoke' && !confirm(list.length === 1
            ? `לבטל את ההרשאה של ${list[0].display_name} ל${list[0].app_name}? הגישה תיחסם מיד.`
            : `לבטל ${list.length} הרשאות ישירות? המשתמשים יאבדו מיד את הגישה.`)) return;
        try {
            await api('admin_restricted_decide', { decisions: list.map(r => ({ user_id: r.user_id, app: r.app, decision })) });
            toast(decision === 'revoke' ? `🗑️ בוטלו ${list.length} הרשאות` : `✅ ${list.length} סומנו כנבדקו`);
            list.forEach(r => picked.delete(key(r)));
        } catch (err) { toast('⚠️ ' + err.message); }
        await load();
        onChange();
    }

    // שורה: מי, המייל, האפליקציה, ותגים. ממתינה — תיבת סימון, להשאיר / לבטל, והצ'יפ הכתום. נבדקה — מתי ומי, ולבטל.
    function row(r) {
        const pending = !r.reviewed_at;
        const chips = [el('span', { className: 'chip', textContent: `${r.app_icon} ${r.app_name}` })];
        if (pending && r.catalog) chips.push(el('span', { className: 'chip warn', textContent: '🛍️ בחנות לכולם — כנראה דרך הפרצה', title: 'אין מקור שמור להרשאה; זה רק רמז' }));
        if (r.via_group) chips.push(el('span', { className: 'chip', textContent: '👥 פתוחה לו גם דרך קבוצה', title: 'גם אחרי ביטול ההרשאה הישירה הוא יראה אותה' }));
        if (r.role === 'admin') chips.push(el('span', { className: 'chip brand', textContent: 'מנהל' }));
        if (r.status !== 'active') chips.push(el('span', { className: 'chip bad', textContent: r.status === 'blocked' ? 'חסום' : 'ממתין' }));
        if (!pending) chips.push(el('span', { className: 'chip ok', textContent: `✅ נבדקה ${fmt(r.reviewed_at)}${r.reviewed_by ? ' · ' + r.reviewed_by : ''}` }));
        const lead = [];
        if (pending) {
            const cb = el('input', { type: 'checkbox', checked: picked.has(key(r)), ariaLabel: `בחירת ${r.display_name} · ${r.app_name}` });
            cb.addEventListener('change', () => { cb.checked ? picked.add(key(r)) : picked.delete(key(r)); drawBar(); });
            lead.push(cb);
        }
        const actions = pending
            ? [btn('✅ להשאיר', '', () => decide([r], 'keep')), btn('🗑️ לבטל', 'danger', () => decide([r], 'revoke'))]
            : [btn('🗑️ לבטל', 'danger', () => decide([r], 'revoke'))];
        return el('div', { className: 'notify', style: 'flex-wrap:wrap;min-width:0;margin:0' }, [
            ...lead,
            el('div', { className: 'notify-tx' }, [
                el('b', { textContent: r.display_name }),
                el('small', { textContent: r.email || r.username }),
                el('div', { className: 'chips-row', style: 'white-space:normal' }, chips),
            ]),
            el('div', { className: 'notify-btns', style: 'flex-basis:100%;justify-content:flex-end' }, actions),
        ]);
    }

    const bar = el('div', { className: 'chips-row', style: 'min-width:0' });
    function drawBar() {
        const pend = rows.filter(r => !r.reviewed_at);
        const sel = pend.filter(r => picked.has(key(r)));
        bar.replaceChildren(
            btn('בחירת הכול', 'chip-btn', () => { pend.forEach(r => picked.add(key(r))); draw(); }),
            btn('ניקוי הבחירה', 'chip-btn', () => { picked.clear(); draw(); }, { disabled: !sel.length }),
            btn(`✅ להשאיר (${sel.length})`, '', () => decide(sel, 'keep'), { disabled: !sel.length }),
            btn(`🗑️ לבטל (${sel.length})`, 'danger', () => decide(sel, 'revoke'), { disabled: !sel.length }),
        );
    }

    function draw() {
        const pend = rows.filter(r => !r.reviewed_at);
        const done = rows.filter(r => r.reviewed_at);
        const parts = [el('div', { className: 'sheet-sec', textContent: `⚠️ ממתינות להחלטה · ${pend.length}` })];
        if (pend.length) {
            drawBar();
            parts.push(el('p', { className: 'small muted', style: 'min-width:0', textContent: 'אין במסד מקור להרשאה — "בחנות לכולם" הוא רק רמז שהיא נוצרה דרך הפרצה.' }),
                bar, ...pend.map(row));
        } else {
            parts.push(el('p', { className: 'muted', textContent: '✓ הכול נבדק' }));
        }
        if (done.length) {
            const fold = el('details', { className: 'fold', open: showReviewed }, [
                el('summary', { textContent: `✅ נבדקו · ${done.length}` }),
                el('div', { style: 'display:grid;gap:var(--s2);min-width:0' }, done.map(row)),
            ]);
            fold.addEventListener('toggle', () => { showReviewed = fold.open; });
            parts.push(fold);
        }
        body.replaceChildren(...parts);
    }

    await load();
}
