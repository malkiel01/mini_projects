/*
 * החנות (אפיון 09 §3) — #/store (הקטלוג), #/store/p/<מזהה> (עמוד מוצר), #/store/favs (המועדפים).
 *
 *   **WooCommerce הוא מקור האמת.** כאן אין סל, אין כמות ואין תשלום: "לרכישה באתר" פותח את עמוד המוצר בחנות בלשונית חדשה,
 *   ונספר כ"מעבר לחנות" (store-out). המחיר שמוצג הוא מה שהשרת שמר מהחנות — שום מחיר לא מחושב כאן.
 *   מחיר "מאומת" (p.fresh) — רק אם הסנכרון מלפני פחות מ-24 שעות; אחרת הוא מוצג כ"מחיר אחרון שנבדק" ובלי מלאי (§4).
 *   שדה שהשרת לא שלח (לא מופה / אין ערך) — לא מוצג בכלל, בלי "לא ידוע" (§2).
 *   הטקסטים מהחנות (תיאור, ביקורות, תוכן עניינים) מגיעים כפסקאות של טקסט פשוט — מוצגים כטקסט, בלי HTML.
 *   מועדפים — "storefavs" ב-SF.store: אורח — במכשיר; רשום — מסתנכרן (05, store_favorite).
 */
'use strict';

(function (SF) {
    const { h } = SF;
    const FAVS = 'storefavs';
    const STOCK = { instock: ['במלאי', 'ok'], outofstock: ['אזל במלאי', 'bad'], onbackorder: ['בהזמנה מוקדמת', 'warn'] };
    const FIELDS = [['author', 'מחבר'], ['publisher', 'הוצאה'], ['edition', 'מהדורה'], ['binding', 'כריכה']];
    // מצב הקטלוג (חיפוש, סינון, עמוד) — נשמר בין מסכים, כדי שחזרה ממוצר תחזור לאותו מקום
    const state = { q: '', cat: '', author: '', min: '', max: '', sort: 'name', page: 1 };

    const money = (amount, cur) => {
        try { return new Intl.NumberFormat('he-IL', { style: 'currency', currency: cur || 'ILS', maximumFractionDigits: amount % 100 ? 2 : 0 }).format(amount / 100); }
        catch (e) { return (amount / 100).toFixed(2) + ' ' + (cur || ''); }
    };
    const day = iso => { const d = new Date(iso); return isNaN(d) ? iso : d.toLocaleString('he-IL', { dateStyle: 'short', timeStyle: 'short' }); };

    /** המחיר: מבצע — המחיר הקודם מחוק; לא מאומת — "מחיר אחרון שנבדק" */
    function price(p) {
        if (!p.price) return null;
        const pr = p.price;
        return h('div', { class: 'st-price' + (p.fresh ? '' : ' stale') },
            !p.fresh ? h('small', null, 'מחיר אחרון שנבדק') : null,
            h('span', null, h('b', null, (pr.from ? 'החל מ-' : '') + money(pr.amount, pr.currency)),
                pr.on_sale && pr.regular ? h('s', { 'aria-label': 'במקום ' + money(pr.regular, pr.currency) }, money(pr.regular, pr.currency)) : null),
            pr.on_sale && p.fresh ? h('span', { class: 'chip warn' }, 'מבצע') : null);
    }

    const stock = p => (p.stock && STOCK[p.stock] ? h('span', { class: 'chip ' + STOCK[p.stock][1] }, STOCK[p.stock][0]) : null);
    const stars = r => (r ? h('span', { class: 'st-stars', 'aria-label': `דירוג ${r.avg} מתוך 5 (${r.count})` }, '★ ' + r.avg.toFixed(1), h('small', null, ` (${r.count})`)) : null);
    const cover = (img, alt) => (img
        ? h('img', { src: img.src, alt: img.alt || alt, loading: 'lazy', decoding: 'async', referrerpolicy: 'no-referrer' })
        : h('span', { class: 'st-noimg', 'aria-hidden': 'true' }, '📕'));

    /** "המחירים והמלאי עודכנו לאחרונה ב-…" / נתוני דוגמה */
    function statusNotes(st) {
        return [
            st.sample ? h('p', { class: 'notice warn' }, '🧪 נתוני דוגמה — לא מהחנות. החנות האמיתית תוצג כשיוגדר החיבור.') : null,
            st.stale ? h('p', { class: 'notice' }, st.verified_at
                ? `המחירים והמלאי עודכנו לאחרונה ב-${day(st.verified_at)}; המחיר הסופי באתר.`
                : 'המחירים והמלאי עוד לא עודכנו מהחנות; המחיר הסופי באתר.') : null,
        ];
    }

    function card(p) {
        return h('a', { class: 'st-card', href: '#/store/p/' + p.id },
            h('span', { class: 'st-img' }, cover(p.image, p.name)),
            h('span', { class: 'st-tx' },
                h('b', { class: 'st-name' }, p.name),
                p.author ? h('small', null, p.author) : null,
                price(p),
                h('span', { class: 'st-meta' }, stock(p), stars(p.rating))));
    }

    async function favs() { return SF.store.get(FAVS, []); }

    function favButton(p) {
        const btn = h('button', { class: 'icon-btn ghost st-fav', type: 'button', 'aria-pressed': 'false', 'aria-label': 'מועדף: ' + p.name }, '♡');
        const paint = on => { btn.setAttribute('aria-pressed', String(on)); btn.textContent = on ? '♥' : '♡'; };
        favs().then(l => paint(l.some(f => f.id === String(p.id))));
        btn.addEventListener('click', async () => {
            const id = String(p.id);
            const list = await SF.store.update(FAVS, [], l => (l.some(f => f.id === id) ? l.filter(f => f.id !== id)
                : [...l, { id, name: String(p.name).slice(0, 200), created_at: new Date().toISOString() }]));
            const on = (await favs()).some(f => f.id === id);
            paint(on);
            SF.toast(on ? 'נוסף למועדפים' : 'הוסר מהמועדפים');
            return list;
        });
        return btn;
    }

    /** "לרכישה באתר" — הכתובת מהשרת (עמוד המוצר בחנות), לשונית חדשה. הספירה — ברקע, לא מעכבת */
    function buyButton(p) {
        if (!p.buy_url) return h('p', { class: 'hint' }, 'הרכישה — באתר החנות');
        return h('a', { class: 'btn primary st-buy', href: p.buy_url, target: '_blank', rel: 'noopener noreferrer',
            onclick: () => { SF.api('store-out', { body: { id: p.id } }).catch(() => null); } }, 'לרכישה באתר ↗');
    }

    function offlineNote(main, e) {
        if (e && e.code === 'network') {
            SF.put(main, h('div', { class: 'fail', role: 'alert' }, h('div', { class: 'ic', 'aria-hidden': 'true' }, '📡'),
                h('p', null, 'החנות זמינה רק עם אינטרנט'), h('a', { class: 'btn ghost', href: '#/store/favs' }, '♥ המועדפים שלי')));
            return true;
        }
        return false;
    }

    // ── הקטלוג ─────────────────────────────────────────────
    SF.route('#/store', async main => {
        const results = h('div', { class: 'st-results', 'aria-live': 'polite' });
        const notes = h('div');
        const q = h('input', { type: 'search', value: state.q, placeholder: 'חיפוש: שם, מחבר, מק"ט', 'aria-label': 'חיפוש בחנות', enterkeyhint: 'search' });
        const filters = h('div', { class: 'fields two st-filters' });
        const load = async () => {
            const params = Object.fromEntries(Object.entries(state).filter(([, v]) => v !== '' && v !== null).map(([k, v]) => [k, String(v)]));
            let d;
            try { d = await SF.api('read-store-catalog', { params }); }
            catch (e) { if (!offlineNote(main, e)) SF.fail(results, e); return; }
            if (!results.isConnected) return;
            SF.put(notes, statusNotes(d.status));
            buildFilters(d);
            const pager = d.pages > 1 ? h('nav', { class: 'st-pager', 'aria-label': 'עמודים' },
                h('button', { class: 'btn ghost', type: 'button', disabled: d.page <= 1, onclick: () => { state.page = d.page - 1; load(); main.scrollIntoView(); } }, '→ הקודם'),
                h('span', { class: 'muted' }, `עמוד ${d.page} מתוך ${d.pages}`),
                h('button', { class: 'btn ghost', type: 'button', disabled: d.page >= d.pages, onclick: () => { state.page = d.page + 1; load(); main.scrollIntoView(); } }, 'הבא ←')) : null;
            SF.put(results,
                h('p', { class: 'hint' }, d.total ? `${d.total} ספרים` : ''),
                d.products.length ? h('div', { class: 'st-grid' }, d.products.map(card))
                    : h('p', { class: 'empty' }, d.total === 0 && !state.q && !state.cat && !state.author && !state.min && !state.max ? 'החנות עוד ריקה' : 'לא נמצאו ספרים — נסו חיפוש אחר או פחות סינון'),
                pager);
        };
        const set = (k, v) => { state[k] = v; state.page = 1; load(); };
        let built = false;
        function buildFilters(d) {
            if (built) return;
            built = true;
            const sel = (k, label, opts) => h('label', { class: 'f' }, h('span', null, label),
                h('select', { onchange: e => set(k, e.target.value) }, h('option', { value: '' }, 'הכול'),
                    opts.map(([v, l]) => h('option', { value: v, selected: String(state[k]) === String(v) }, l))));
            const num = (k, label) => h('label', { class: 'f' }, h('span', null, label),
                h('input', { type: 'number', inputmode: 'numeric', min: '0', step: '1', value: state[k], onchange: e => set(k, /^\d{1,7}$/.test(e.target.value) ? e.target.value : '') }));
            const byParent = c => (c.parent ? '— ' : '') + c.name + ` (${c.n})`;
            SF.put(filters,
                d.categories.length ? sel('cat', 'קטגוריה', d.categories.map(c => [c.id, byParent(c)])) : null,
                d.authors && d.authors.length ? sel('author', 'מחבר', d.authors.map(a => [a, a])) : null,
                num('min', 'ממחיר (₪)'), num('max', 'עד מחיר (₪)'),
                h('label', { class: 'f' }, h('span', null, 'סדר'), h('select', { onchange: e => set('sort', e.target.value) },
                    [['name', 'לפי שם'], ['price_asc', 'מחיר: מהזול'], ['price_desc', 'מחיר: מהיקר'], ['new', 'חדשים']].map(([v, l]) => h('option', { value: v, selected: state.sort === v }, l)))));
        }
        let t = 0;
        q.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => set('q', q.value.trim()), 350); });
        q.addEventListener('keydown', e => { if (e.key === 'Enter') { clearTimeout(t); set('q', q.value.trim()); } });
        SF.onLeave(() => clearTimeout(t));
        main.replaceChildren(
            h('div', { class: 'toolbar st-search' }, q, h('a', { class: 'btn ghost', href: '#/store/favs' }, '♥ מועדפים')),
            h('details', { class: 'st-more', open: !!(state.cat || state.author || state.min || state.max || state.sort !== 'name') },
                h('summary', null, 'סינון וסדר'), filters),
            notes, results);
        await load();
    }, { title: 'חנות' });

    // ── עמוד מוצר ──────────────────────────────────────────
    SF.route('#/store/p/:id', async (main, { id }) => {
        if (!/^\d{1,12}$/.test(id)) return SF.go('#/store');
        let p;
        try { p = await SF.api('read-store-product', { params: { id } }); }
        catch (e) {
            if (offlineNote(main, e)) return;
            if (e.code === 'not_found') {
                SF.put(main, h('p', { class: 'empty' }, 'הספר הזה כבר לא בחנות'), h('a', { class: 'btn ghost', href: '#/store' }, 'לחנות'));
                return;
            }
            throw e;
        }
        document.title = p.name + ' · חנות · ספורנו';
        document.getElementById('back').dataset.to = '#/store';
        const imgs = p.images.length ? p.images : [null];
        const dl = FIELDS.filter(([k]) => p[k]).map(([k, label]) => h('div', null, h('dt', null, label), h('dd', null, p[k])));
        if (p.categories.length) dl.push(h('div', null, h('dt', null, 'קטגוריה'), h('dd', null, p.categories.map(c => c.name).join(', '))));
        const paras = list => list.map(t => h('p', null, t));
        SF.put(main,
            statusNotes(p.status),
            h('div', { class: 'st-gallery', role: 'list', 'aria-label': 'תמונות' }, imgs.map(i => h('span', { class: 'st-img big', role: 'listitem' }, cover(i, p.name)))),
            h('div', { class: 'st-head' },
                h('h2', { class: 'st-title' }, p.name), favButton(p)),
            dl.length ? h('dl', { class: 'cells st-dl' }, dl) : null,
            h('div', { class: 'summary st-buybox' },
                h('div', { class: 'line' }, price(p), stock(p), stars(p.rating)),
                h('p', { class: 'hint' }, p.fresh ? 'המחיר הסופי והזמינות — באתר החנות' : 'המחיר והמלאי לא עודכנו ב-24 השעות האחרונות; המחיר הסופי והזמינות — באתר החנות'),
                buyButton(p)),
            p.digital.length ? h('div', { class: 'notice st-digital' }, '📖 יש גם ספר דיגיטלי: ',
                p.digital.map((d, i) => [i ? ' · ' : '', h('a', { href: '#/r/' + d.edition }, d.title)])) : null,
            p.short.length ? h('div', { class: 'st-text lead' }, paras(p.short)) : null,
            p.description.length ? h('section', { class: 'st-text' }, h('h3', { class: 'sec-title' }, 'על הספר'), paras(p.description)) : null,
            p.toc ? h('details', { class: 'st-more' }, h('summary', null, 'תוכן עניינים'), h('div', { class: 'st-text' }, paras(p.toc))) : null,
            p.samples ? h('section', null, h('h3', { class: 'sec-title' }, 'דפי דוגמה'), h('div', { class: 'st-samples' }, p.samples.map((u, i) => samplePage(u, i, p.name)))) : null,
            p.related.length ? h('section', null, h('h3', { class: 'sec-title' }, 'ספרים קשורים'), h('div', { class: 'st-grid' }, p.related.map(card))) : null,
            p.reviews.length ? h('section', null, h('h3', { class: 'sec-title' }, `ביקורות (${p.reviews.length})`),
                h('ul', { class: 'list' }, p.reviews.map(r => h('li', { class: 'card st-review', style: 'cursor:default' }, h('div', { class: 'body' },
                    h('div', { class: 'l1' }, h('span', null, r.reviewer || 'קונה'), r.rating ? h('span', { class: 'st-stars', 'aria-label': `${r.rating} מתוך 5` }, '★'.repeat(r.rating)) : null,
                        h('span', { class: 't' }, r.at ? new Date(r.at + 'Z').toLocaleDateString('he-IL') : '')),
                    r.verified ? h('small', { class: 'muted' }, 'קנה את הספר') : null,
                    h('div', { class: 'st-text' }, paras(r.text))))))) : null);
    }, { title: 'חנות', parent: '#/store' });

    /** דף דוגמה: תמונה — נפתחת בתוך האפליקציה; קובץ אחר — קישור לאתר */
    function samplePage(u, i, name) {
        const label = 'דף דוגמה ' + (i + 1);
        if (!/\.(jpe?g|png|webp|gif|avif)(\?|$)/i.test(u)) return h('a', { class: 'btn ghost', href: u, target: '_blank', rel: 'noopener noreferrer' }, '📄 ' + label + ' ↗');
        return h('button', { class: 'st-img', type: 'button', 'aria-label': label, onclick: () => SF.sheet(label + ' · ' + name,
            h('img', { class: 'st-sample-full', src: u, alt: label, referrerpolicy: 'no-referrer' })) },
        h('img', { src: u, alt: label, loading: 'lazy', referrerpolicy: 'no-referrer' }));
    }

    // ── המועדפים ───────────────────────────────────────────
    SF.route('#/store/favs', async main => {
        const list = await favs();
        const row = f => h('li', { class: 'card', onclick: () => SF.go('#/store/p/' + f.id) },
            h('div', { class: 'body' }, h('div', { class: 'l1' }, h('span', null, f.name || ('ספר ' + f.id)))),
            h('button', { class: 'icon-btn ghost', type: 'button', 'aria-label': 'הסרה מהמועדפים: ' + f.name, onclick: async e => {
                e.stopPropagation();
                await SF.store.update(FAVS, [], l => l.filter(x => x.id !== f.id));
                SF.render();
            } }, '✕'),
            h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹'));
        SF.put(main,
            h('p', { class: 'hint' }, SF.sync && SF.sync.active() ? 'מסונכרן לחשבון שלך' : 'נשמר במכשיר הזה. עם חשבון — מסונכרן לכל המכשירים.'),
            h('ul', { class: 'list' }, list.length ? list.slice().reverse().map(row) : h('li', { class: 'empty' }, 'אין עדיין מועדפים — ♡ בעמוד של ספר')),
            h('a', { class: 'btn ghost', href: '#/store' }, 'לחנות'));
    }, { title: 'מועדפים בחנות', parent: '#/store' });
})(window.SF);
