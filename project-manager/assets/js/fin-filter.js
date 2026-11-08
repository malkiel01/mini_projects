/**
 * סינון וחלוקה לפי בעל מקצוע / ספק — בכספים ("👷 ספקים") ובהצעת התקציב ("📐 תקציב").
 * בחירה מרובה: בתוך קבוצה — "או" (חשמל או אינסטלציה), בין הקבוצות — "וגם" (חשמל של הספק הזה).
 * הבחירה נשמרת לכל פרויקט ולכל מסך בנפרד (sessionStorage), כך שהיא לא נעלמת בכל רענון.
 * בעל המקצוע של שורה — התחום של השלב; שלב בלי תחום — התחומים של הספק (כרטיס איש הקשר).
 */
const FinFilter = (() => {
    const key = (scope, pid) => `pm.ff.${scope}.${pid}`;
    const openAt = {}; // לכל מסך — האם הבורר פתוח (כדי שבנייה מחדש לא תסגור אותו באמצע העבודה)

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

    /** אפשרויות ייחודיות, ממוינות לפי שם: list — מזהים, name(id) — השם. */
    const uniq = (list, name) => [...new Map(list.map(x => [x, { id: x, name: name(x) }])).values()].sort((a, b) => a.name.localeCompare(b.name, I18n.locale()));

    /** בחירה שמורה של מה שכבר אינו באפשרויות (למשל אחרי שינוי שיוך) — נשמטת, אחרת היא מסננת בלי צ'יפ לביטול. */
    function prune(scope, pid, f, opts) {
        const drop = (set, list) => { const ok = new Set(list.map(x => x.id)); let n = 0; for (const id of [...set]) if (!ok.has(id)) { set.delete(id); n++; } return n; };
        if (drop(f.trades, opts.trades) + drop(f.suppliers, opts.suppliers)) put(scope, pid, f);
        return f;
    }

    /** לאיזה בעל מקצוע נזקפת שורה בחלוקה: כשמסננים לפי בעל מקצוע — לראשון מהתחומים שלה שנבחר. */
    const tradeKey = (f, trades) => (f.trades.size && trades.find(id => f.trades.has(id))) || trades[0] || 0;

    /** צבירה לקבוצות: key(x) — המזהה, init(x, id) — קבוצה חדשה, add(g, x) — הוספה. ממוין לפי sort. */
    function group(items, key, init, add, sort) {
        const m = new Map();
        for (const x of items) { const id = key(x); if (!m.has(id)) m.set(id, init(x, id)); add(m.get(id), x); }
        return [...m.values()].sort(sort);
    }

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
        return `<details class="ff" data-ff-scope="${scope}"${openAt[scope] ?? n ? ' open' : ''}>
            <summary>🔎 ${esc(t('סינון לפי בעל מקצוע / ספק'))}${n ? ` <span class="chip">${esc(t('{n} נבחרו', { n }))}</span>` : ''}</summary>
            ${group('trade', t('בעלי מקצוע'), opts.trades, f.trades)}
            ${group('supplier', t('ספקים'), opts.suppliers, f.suppliers)}
            ${n ? `<button type="button" class="btn ghost sm" data-ff-clear>✖ ${esc(t('ניקוי הסינון'))}</button>` : ''}
        </details>`;
    }

    /** האזנה לבורר (פעם אחת לכל שורש). onChange(f) — אחרי כל שינוי, עם הבחירה החדשה. */
    function bind(root, scope, pidOf, onChange) {
        // הבנייה מחדש מחליפה את התיבה — מחזירים את הפוקוס לאותו צ'יפ (מקלדת, קורא מסך)
        const refocus = sel => { const el = root.querySelector(`[data-ff-scope="${scope}"] ${sel}`) || root.querySelector(`[data-ff-scope="${scope}"] summary`); el?.focus(); };
        root.addEventListener('toggle', e => { if (e.target.matches?.(`details[data-ff-scope="${scope}"]`)) openAt[scope] = e.target.open; }, true);
        root.addEventListener('change', e => {
            const i = e.target.closest('[data-ff], [data-ff-by]');
            if (!i || !i.closest(`[data-ff-scope="${scope}"]`)) return;
            const f = get(scope, pidOf());
            if (i.dataset.ffBy !== undefined) f.by = i.value;
            else { const set = i.dataset.ff === 'trade' ? f.trades : f.suppliers; i.checked ? set.add(Number(i.value)) : set.delete(Number(i.value)); }
            put(scope, pidOf(), f);
            onChange(f);
            refocus(i.dataset.ffBy !== undefined ? `[data-ff-by][value="${i.value}"]` : `[data-ff="${i.dataset.ff}"][value="${i.value}"]`);
        });
        root.addEventListener('click', e => {
            if (!e.target.closest(`[data-ff-scope="${scope}"] [data-ff-clear]`)) return;
            const f = get(scope, pidOf());
            f.trades.clear(); f.suppliers.clear();
            put(scope, pidOf(), f);
            onChange(f);
            refocus('summary');
        });
    }

    /** בורר החלוקה: לפי בעל מקצוע / לפי ספק. */
    const byHtml = (scope, f) => `<div class="seg ff-by" role="radiogroup" aria-label="${esc(t('חלוקה'))}" data-ff-scope="${scope}">
        <label><input type="radio" name="ff-by-${scope}" data-ff-by value="trade"${f.by !== 'supplier' ? ' checked' : ''}><span>${esc(t('לפי בעל מקצוע'))}</span></label>
        <label><input type="radio" name="ff-by-${scope}" data-ff-by value="supplier"${f.by === 'supplier' ? ' checked' : ''}><span>${esc(t('לפי ספק'))}</span></label></div>`;

    return { get, active, match, tradesOf, tradeName, uniq, prune, tradeKey, group, html, bind, byHtml };
})();
