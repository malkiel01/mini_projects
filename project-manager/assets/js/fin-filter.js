/**
 * סינון וחלוקה לפי בעל מקצוע / ספק — בכספים ("👷 ספקים") ובהצעת התקציב ("📐 תקציב").
 * בחירה מרובה: בתוך קבוצה — "או" (חשמל או אינסטלציה), בין הקבוצות — "וגם" (חשמל של הספק הזה).
 * הבחירה נשמרת לכל פרויקט ולכל מסך בנפרד (sessionStorage), כך שהיא לא נעלמת בכל רענון.
 * בעל המקצוע של שורה — התחום של השלב; שלב בלי תחום — התחומים של הספק (כרטיס איש הקשר).
 */
const FinFilter = (() => {
    const key = (scope, pid) => `pm.ff.${scope}.${pid}`;

    function get(scope, pid) {
        try {
            const v = JSON.parse(sessionStorage.getItem(key(scope, pid)) || '{}');
            return { trades: new Set(v.trades || []), suppliers: new Set(v.suppliers || []), by: v.by === 'supplier' ? 'supplier' : 'trade' };
        } catch { return { trades: new Set(), suppliers: new Set(), by: 'trade' }; }
    }
    function put(scope, pid, f) {
        try { sessionStorage.setItem(key(scope, pid), JSON.stringify({ trades: [...f.trades], suppliers: [...f.suppliers], by: f.by })); } catch { /* מצב פרטי */ }
    }

    const active = f => f.trades.size + f.suppliers.size > 0;

    /** האם שורה עוברת את הסינון. trades — מערך מזהי תחום (0 = בלי תחום), supplier — מזהה (0 = בלי ספק). */
    function match(f, trades, supplier) {
        if (f.trades.size && !(trades.length ? trades : [0]).some(id => f.trades.has(id))) return false;
        if (f.suppliers.size && !f.suppliers.has(supplier || 0)) return false;
        return true;
    }

    /** תחומי השורה: של השלב, ואם אין — של הספק (contacts — מ-contactsOf('supplier')). */
    function tradesOf(stage, supplierId, contacts) {
        if (stage?.trade_id) return [stage.trade_id];
        return contacts?.find(c => c.id === supplierId)?.trades || [];
    }

    const tradeName = id => (id ? t(state.trades.find(x => x.id === id)?.name || '') || t('תחום לא מוכר') : t('בלי בעל מקצוע'));

    /**
     * הבורר: שתי קבוצות של צ'יפים (בעלי מקצוע · ספקים) בתוך "🔎 סינון", וכפתור ניקוי.
     * opts: { trades: [{id, name}], suppliers: [{id, name}] } — רק מה שיש בפרויקט.
     */
    function html(scope, f, opts) {
        const n = f.trades.size + f.suppliers.size;
        const group = (kind, label, list, sel) => list.length ? `<fieldset class="ff-group"><legend>${esc(label)}</legend><div class="chips">${list.map(x =>
            `<label><input type="checkbox" data-ff="${kind}" value="${x.id}"${sel.has(x.id) ? ' checked' : ''}><span>${esc(x.name)}</span></label>`).join('')}</div></fieldset>` : '';
        return `<details class="ff" data-ff-scope="${scope}"${n ? ' open' : ''}>
            <summary>🔎 ${esc(t('סינון לפי בעל מקצוע / ספק'))}${n ? ` <span class="chip">${esc(t('{n} נבחרו', { n }))}</span>` : ''}</summary>
            ${group('trade', t('בעלי מקצוע'), opts.trades, f.trades)}
            ${group('supplier', t('ספקים'), opts.suppliers, f.suppliers)}
            ${n ? `<button type="button" class="btn ghost sm" data-ff-clear>✖ ${esc(t('ניקוי הסינון'))}</button>` : ''}
        </details>`;
    }

    /** האזנה לבורר (פעם אחת לכל שורש). onChange(f) — אחרי כל שינוי, עם הבחירה החדשה. */
    function bind(root, scope, pidOf, onChange) {
        root.addEventListener('change', e => {
            const i = e.target.closest('[data-ff], [data-ff-by]');
            if (!i || !i.closest(`[data-ff-scope="${scope}"]`)) return;
            const f = get(scope, pidOf());
            if (i.dataset.ffBy !== undefined) f.by = i.value;
            else { const set = i.dataset.ff === 'trade' ? f.trades : f.suppliers; i.checked ? set.add(Number(i.value)) : set.delete(Number(i.value)); }
            put(scope, pidOf(), f);
            onChange(f);
        });
        root.addEventListener('click', e => {
            if (!e.target.closest(`[data-ff-scope="${scope}"] [data-ff-clear]`)) return;
            const f = get(scope, pidOf());
            f.trades.clear(); f.suppliers.clear();
            put(scope, pidOf(), f);
            onChange(f);
        });
    }

    /** בורר החלוקה: לפי בעל מקצוע / לפי ספק. */
    const byHtml = (scope, f) => `<div class="seg ff-by" role="radiogroup" aria-label="${esc(t('חלוקה'))}" data-ff-scope="${scope}">
        <label><input type="radio" name="ff-by-${scope}" data-ff-by value="trade"${f.by !== 'supplier' ? ' checked' : ''}><span>${esc(t('לפי בעל מקצוע'))}</span></label>
        <label><input type="radio" name="ff-by-${scope}" data-ff-by value="supplier"${f.by === 'supplier' ? ' checked' : ''}><span>${esc(t('לפי ספק'))}</span></label></div>`;

    return { get, active, match, tradesOf, tradeName, html, bind, byHtml };
})();
