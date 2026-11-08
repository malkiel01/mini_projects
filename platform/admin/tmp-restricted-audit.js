// 🔍 מסך בדיקת הגישות החסומות — זמני (2026-11-08, אחרי #1277). השרת וההוראות להסרה:
// platform/admin/tmp-restricted-audit.php (בראש הקובץ). נטען רק בלחיצה, מ-renderSystem ב-assets/admin.js.

const API = new URL('tmp-restricted-audit.php', import.meta.url);
const ME = new URL('../api.php?action=me', import.meta.url);

export async function open(P) {
    const { el, toast } = P;
    let rows = [];
    const picked = new Set();               // "user_id|app"
    const key = (r) => `${r.user_id}|${r.app}`;

    const close = el('button', { type: 'button', className: 'icon-btn', textContent: '✕', ariaLabel: 'סגירה' });
    const body = el('div', { className: 'sheet-body' }, [el('p', { className: 'status', textContent: 'טוען…' })]);
    const dlg = el('dialog', { className: 'user-sheet' }, [el('div', { className: 'sheet' }, [
        el('div', { className: 'sheet-head' }, [el('div', { className: 'tx' }, [
            el('h2', { textContent: '🔍 מסך בדיקת הגישות החסומות' }),
            el('small', { textContent: 'הרשאות ישירות לאפליקציות restricted — להשאיר או לבטל' }),
        ]), close]),
        body,
    ])]);
    close.addEventListener('click', () => dlg.close());
    dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });
    dlg.addEventListener('close', () => dlg.remove());
    document.body.append(dlg);
    dlg.showModal();

    async function call(payload) {
        const opts = { cache: 'no-store', headers: {} };
        if (payload) {
            const me = await (await fetch(ME, { cache: 'no-store' })).json();
            opts.method = 'POST';
            opts.headers = { 'Content-Type': 'application/json', 'X-Platform': '1', 'X-CSRF': me.csrf || '' };
            opts.body = JSON.stringify(payload);
        }
        const res = await fetch(API, opts);
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.error || 'שגיאה בשרת');
        return data;
    }

    async function load() {
        try { rows = (await call()).rows; }
        catch (err) { body.replaceChildren(el('p', { className: 'status error', textContent: err.message })); return; }
        for (const k of [...picked]) if (!rows.some(r => key(r) === k)) picked.delete(k);
        draw();
    }

    async function decide(list, decision) {
        if (!list.length) return;
        if (decision === 'revoke' && !confirm(`לבטל ${list.length} הרשאות ישירות? המשתמשים יאבדו מיד את הגישה לאפליקציות האלה.`)) return;
        try {
            await call({ decisions: list.map(r => ({ user_id: r.user_id, app: r.app, decision })) });
            toast(decision === 'revoke' ? `🗑️ בוטלו ${list.length} הרשאות` : `✅ ${list.length} סומנו כנבדקו`);
            list.forEach(r => picked.delete(key(r)));
        } catch (err) { toast('⚠️ ' + err.message); }
        await load();
    }

    function row(r) {
        const cb = el('input', { type: 'checkbox', checked: picked.has(key(r)), ariaLabel: `בחירת ${r.display_name} · ${r.app_name}` });
        cb.addEventListener('change', () => { cb.checked ? picked.add(key(r)) : picked.delete(key(r)); drawBar(); });
        const chips = [el('span', { className: 'chip', textContent: `${r.app_icon} ${r.app_name}` })];
        if (r.catalog) chips.push(el('span', { className: 'chip warn', textContent: '🛍️ בחנות לכולם — כנראה דרך הפרצה', title: 'אין מקור שמור להרשאה; זה רק רמז' }));
        if (r.via_group) chips.push(el('span', { className: 'chip', textContent: '👥 פתוחה לו גם דרך קבוצה', title: 'גם אחרי ביטול ההרשאה הישירה הוא יראה אותה' }));
        if (r.role === 'admin') chips.push(el('span', { className: 'chip brand', textContent: 'מנהל' }));
        if (r.status !== 'active') chips.push(el('span', { className: 'chip bad', textContent: r.status === 'blocked' ? 'חסום' : 'ממתין' }));
        if (r.kept_at) chips.push(el('span', { className: 'chip ok', textContent: `✅ נבדקה · ${new Date(r.kept_at).toLocaleDateString('he-IL')}` }));
        const keep = el('button', { type: 'button', className: 'btn sm', textContent: '✅ להשאיר' });
        const revoke = el('button', { type: 'button', className: 'btn sm danger', textContent: '🗑️ לבטל' });
        keep.addEventListener('click', () => decide([r], 'keep'));
        revoke.addEventListener('click', () => decide([r], 'revoke'));
        return el('div', { className: 'notify', style: `flex-wrap:wrap;min-width:0;margin:0${r.kept_at ? ';opacity:.7' : ''}` }, [
            cb,
            el('div', { className: 'notify-tx' }, [
                el('b', { textContent: r.display_name }),
                el('small', { textContent: r.email || r.username }),
                el('div', { className: 'chips-row', style: 'white-space:normal' }, chips),
            ]),
            el('div', { className: 'notify-btns', style: 'flex-basis:100%;justify-content:flex-end' }, [keep, revoke]),
        ]);
    }

    const bar = el('div', { className: 'chips-row', style: 'min-width:0' });
    function drawBar() {
        const sel = rows.filter(r => picked.has(key(r)));
        const all = el('button', { type: 'button', className: 'btn sm chip-btn', textContent: 'בחירת כל מה שלא נבדק' });
        all.addEventListener('click', () => { rows.filter(r => !r.kept_at).forEach(r => picked.add(key(r))); draw(); });
        const none = el('button', { type: 'button', className: 'btn sm chip-btn', textContent: 'ניקוי הבחירה', disabled: !sel.length });
        none.addEventListener('click', () => { picked.clear(); draw(); });
        const keep = el('button', { type: 'button', className: 'btn sm', textContent: `✅ להשאיר (${sel.length})`, disabled: !sel.length });
        const revoke = el('button', { type: 'button', className: 'btn sm danger', textContent: `🗑️ לבטל (${sel.length})`, disabled: !sel.length });
        keep.addEventListener('click', () => decide(sel, 'keep'));
        revoke.addEventListener('click', () => decide(sel, 'revoke'));
        bar.replaceChildren(all, none, keep, revoke);
    }

    function draw() {
        if (!rows.length) {
            body.replaceChildren(el('p', { className: 'muted', textContent: '✓ אין הרשאות ישירות לאפליקציות restricted. אפשר לבקש להסיר את המסך.' }));
            return;
        }
        const open = rows.filter(r => !r.kept_at).length;
        drawBar();
        body.replaceChildren(
            el('p', { className: 'small muted', style: 'min-width:0', textContent: `${rows.length} הרשאות · ${open} עוד לא נבדקו. אין במסד מקור להרשאה — "בחנות לכולם" הוא רק רמז.` }),
            bar,
            ...rows.map(row),
        );
    }

    await load();
}
