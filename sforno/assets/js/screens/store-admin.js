/*
 * ניהול החנות (אפיון 09 §2, §4) — #/admin/store: מצב הסנכרון, המיפוי, קישור כותר ↔ מוצר, נתוני דוגמה.
 * החיבור עצמו (כתובת ומפתחות) — רק למפתח (dev-store-set). **המפתחות לא חוזרים מהשרת אף פעם:** השדות ריקים תמיד,
 * ו"נשמר" אומר רק שיש. שדה ריק בשמירה = נשאר מה שהיה.
 */
'use strict';

(function (SF) {
    const { h } = SF;
    const TYPES = [['', 'לא ממופה'], ['attribute', 'מאפיין (Attribute)'], ['meta', 'שדה (Meta)'], ['tag', 'תגית — לפי תחילית']];
    const RUN = { running: ['רצה', 'warn'], ok: ['הצליחה', 'ok'], failed: ['נכשלה', 'bad'] };

    const row = (ic, b, sm, extra) => h('li', { class: 'card', style: 'cursor:default' },
        h('span', { class: 'use-ic', 'aria-hidden': 'true' }, ic),
        h('div', { class: 'body' }, h('div', { class: 'l1' }, h('span', null, b)), sm ? h('div', { class: 'l2' }, sm) : null), extra || null);

    async function act(btn, fn, done) {
        btn.disabled = true;
        try { const r = await fn(); if (done) done(r); } catch (e) { SF.toast(e.message, true); } finally { btn.disabled = false; }
    }

    SF.route('#/admin/store', async main => {
        const d = await SF.api('store-admin');
        const c = d.connection;
        const st = d.status;
        const btn = (label, cls, fn) => h('button', { class: 'btn ' + cls, type: 'button', onclick: e => act(e.currentTarget, fn, () => SF.render()) }, label);
        const sync = kind => async () => {
            const r = await SF.api('store-sync-now', { body: { kind } });
            SF.toast(r.run.status === 'ok' ? `הסנכרון הסתיים — ${r.run.products} מוצרים` : r.run.status === 'failed' ? 'הסנכרון נכשל: ' + r.run.error : 'הסנכרון רץ ברקע');
        };
        const stat = k => Number((d.stats.find(s => s.kind === k) || {}).n || 0);
        SF.put(main,
            h('div', { class: 'summary' },
                h('div', { class: 'line' }, h('b', null, c.url ? c.url.replace(/^https:\/\//, '') : 'עוד אין חיבור לחנות'),
                    h('span', { class: 'chip ' + (c.has_key ? 'ok' : 'warn') }, c.has_key ? 'מחובר' : 'בלי מפתחות'),
                    st.sample ? h('span', { class: 'chip warn' }, 'נתוני דוגמה') : null,
                    st.stale ? h('span', { class: 'chip warn' }, 'לא עודכן 24 שעות') : null),
                h('div', { class: 'line muted' }, `${d.counts.products} מוצרים · ${d.counts.categories} קטגוריות · ${d.counts.reviews} ביקורות` +
                    (d.counts.removed ? ` · ${d.counts.removed} הוסרו` : '')),
                h('div', { class: 'line muted' }, 'עודכן לאחרונה: ' + SF.when(st.verified_at) + ' · מלא אחרון: ' + SF.when(d.full_at)),
                h('div', { class: 'line muted' }, `30 יום: ${stat('view')} צפיות במוצרים · ${stat('out')} מעברים לחנות`)),
            h('div', { class: 'row-btns wrap' },
                c.has_key ? btn('🔄 סנכרון שינויים', 'primary', sync('delta')) : null,
                c.has_key ? btn('סנכרון מלא', 'ghost', sync('full')) : null,
                !c.has_key || st.sample ? btn(st.sample ? 'הסרת נתוני הדוגמה' : '🧪 טעינת נתוני דוגמה', 'ghost', async () => {
                    const r = await SF.api('store-sample', { body: { on: !st.sample } });
                    SF.toast(r.sample ? 'נתוני הדוגמה נטענו' : 'נתוני הדוגמה הוסרו');
                }) : null,
                SF.isDev() ? h('button', { class: 'btn ghost', type: 'button', onclick: () => connSheet(c) }, '🔑 חיבור לחנות') : null,
                h('a', { class: 'btn ghost', href: '#/store' }, '🛒 לחנות')),
            !c.has_key ? h('p', { class: 'hint' }, SF.isDev() ? 'כדי לסנכרן: כתובת החנות ומפתח קריאה (Read) מ-WooCommerce ← הגדרות ← Advanced ← REST API — ב"🔑 חיבור לחנות".'
                : 'החיבור לחנות מוגדר בידי המפתח.') : null,
            h('h3', { class: 'sec-title' }, 'סנכרונים אחרונים'),
            h('ul', { class: 'list' }, d.runs.length ? d.runs.map(r => row(r.source === 'sample' ? '🧪' : r.kind === 'full' ? '🔁' : '🔄',
                (r.kind === 'full' ? 'מלא' : 'שינויים') + ' · ' + SF.when(r.started_at),
                r.status === 'failed' ? r.error : `${r.products} מוצרים · ${r.requests} בקשות` + (r.status === 'running' ? ' · שלב ' + r.stage : ''),
                h('span', { class: 'chip ' + RUN[r.status][1] }, RUN[r.status][0]))) : h('li', { class: 'empty' }, 'עוד לא היה סנכרון')),
            mapping(d),
            links(d));
    }, { title: 'חנות', parent: '#/admin' });

    /** המיפוי: איזה attribute / meta / tag של WooCommerce הוא כל שדה. שדה שלא מופה — לא מוצג לקונה */
    function mapping(d) {
        const lists = { attribute: h('datalist', { id: 'st-attrs' }, d.seen.attributes.map(a => h('option', { value: a }))),
            meta: h('datalist', { id: 'st-meta' }, d.seen.meta.map(a => h('option', { value: a }))) };
        const rows = Object.entries(d.fields).map(([f, label]) => {
            const cur = d.map[f] || {};
            const key = h('input', { value: cur.key || '', 'aria-label': 'השם ב-WooCommerce: ' + label, dir: 'auto', list: cur.type === 'meta' ? 'st-meta' : 'st-attrs', placeholder: 'השם בחנות' });
            const type = h('select', { 'aria-label': 'סוג המקור: ' + label, onchange: () => { key.disabled = !type.value; key.setAttribute('list', type.value === 'meta' ? 'st-meta' : 'st-attrs'); } },
                TYPES.map(([v, l]) => h('option', { value: v, selected: (cur.type || '') === v }, l)));
            key.disabled = !type.value;
            return { f, type, key, el: h('div', { class: 'st-map-row' }, h('b', null, label), type, key) };
        });
        const save = h('button', { class: 'btn primary', type: 'button', onclick: e => act(e.currentTarget, async () => {
            const map = Object.fromEntries(rows.filter(r => r.type.value && r.key.value.trim()).map(r => [r.f, { type: r.type.value, key: r.key.value.trim() }]));
            await SF.api('store-map-save', { body: { map } });
            SF.toast('המיפוי נשמר');
        }, () => SF.render()) }, 'שמירת המיפוי');
        return h('section', null,
            h('h3', { class: 'sec-title' }, 'מיפוי שדות'),
            h('p', { class: 'hint' }, 'איפה כל פרט שמור בחנות. שדה שלא מופה — לא מוצג לקונה (לא "לא ידוע"). תגית — למשל "מחבר:" ← "מחבר: הרב כהן".'),
            lists.attribute, lists.meta,
            h('div', { class: 'st-map' }, rows.map(r => r.el)), h('div', { class: 'row-btns' }, save));
    }

    /** "יש גם ספר דיגיטלי": כותר בספורנו ↔ מוצר בחנות */
    function links(d) {
        const titleSel = h('select', { 'aria-label': 'כותר' }, h('option', { value: '' }, 'בחירת כותר…'));
        SF.api('content-titles').then(r => r.titles.forEach(t => titleSel.append(h('option', { value: t.pid }, t.name)))).catch(() => null);
        const q = h('input', { type: 'search', placeholder: 'חיפוש מוצר בקטלוג', 'aria-label': 'חיפוש מוצר' });
        const found = h('ul', { class: 'list' });
        const link = async (title, product, on) => {
            await SF.api('store-link-set', { body: { title, product, on } });
            SF.toast(on ? 'קושר' : 'הקישור הוסר');
            SF.render();
        };
        let t = 0;
        q.addEventListener('input', () => {
            clearTimeout(t);
            t = setTimeout(async () => {
                if (!q.value.trim()) return found.replaceChildren();
                const r = await SF.api('read-store-catalog', { params: { q: q.value.trim() } }).catch(() => ({ products: [] }));
                SF.put(found, r.products.slice(0, 8).map(p => row('📕', p.name, p.author || '', h('button', { class: 'btn sm ghost', type: 'button', onclick: e => {
                    if (!titleSel.value) return SF.toast('קודם לבחור כותר', true);
                    act(e.currentTarget, () => link(titleSel.value, p.id, true));
                } }, 'קישור'))));
            }, 300);
        });
        return h('section', null,
            h('h3', { class: 'sec-title' }, 'ספר דיגיטלי ↔ מוצר'),
            h('p', { class: 'hint' }, 'בעמוד המוצר יופיע "יש גם ספר דיגיטלי" — רק לכותר שפורסם לקוראים.'),
            h('ul', { class: 'list' }, d.links.length ? d.links.map(l => row('🔗', l.title_name, l.product_name || ('מוצר ' + l.product + ' (לא בקטלוג)'),
                h('button', { class: 'icon-btn ghost', type: 'button', 'aria-label': 'הסרת הקישור', onclick: e => act(e.currentTarget, () => link(l.title, l.product, false)) }, '✕')))
                : h('li', { class: 'empty' }, 'אין קישורים')),
            h('div', { class: 'fields two' }, h('label', { class: 'f' }, h('span', null, 'כותר'), titleSel), h('label', { class: 'f' }, h('span', null, 'מוצר'), q)),
            found);
    }

    /** החיבור (מפתח בלבד): כתובת, מפתחות קריאה, סוד ה-webhook. השדות לעולם לא מתמלאים מהשרת */
    function connSheet(c) {
        const url = h('input', { dir: 'ltr', value: c.url || '', placeholder: 'https://shop.example.com', inputmode: 'url', autocomplete: 'off' });
        const sec = (ph) => h('input', { dir: 'ltr', type: 'password', autocomplete: 'new-password', spellcheck: 'false', placeholder: ph });
        const key = sec(c.has_key ? 'נשמר — להחלפה בלבד' : 'ck_…');
        const secret = sec(c.has_key ? 'נשמר — להחלפה בלבד' : 'cs_…');
        const hook = sec(c.has_webhook ? 'נשמר — להחלפה בלבד' : 'הסוד שהוגדר ב-webhook בחנות');
        const out = h('p', { class: 'hint wide', 'aria-live': 'polite' });
        const save = h('button', { class: 'btn primary', type: 'button', onclick: e => act(e.currentTarget, async () => {
            const r = await SF.api('dev-store-set', { body: { url: url.value, key: key.value, secret: secret.value, webhook_secret: hook.value } });
            [key, secret, hook].forEach(i => { i.value = ''; });
            SF.toast(r.connection.has_key ? 'נשמר' : 'נשמר — חסרים מפתחות');
            dlg.close();
            SF.render();
        }) }, 'שמירה');
        const test = h('button', { class: 'btn ghost', type: 'button', onclick: e => act(e.currentTarget, async () => {
            const r = await SF.api('dev-store-test', { body: {} });
            out.textContent = r.ok ? `✅ מחובר · ${r.products ?? '?'} מוצרים · ${r.ms}ms` + (r.version ? ' · WooCommerce ' + r.version : '') : '❌ ' + r.message;
        }) }, 'בדיקת חיבור');
        const clear = h('button', { class: 'btn ghost danger', type: 'button', onclick: e => {
            if (!confirm('למחוק את החיבור לחנות (כתובת ומפתחות)? הקטלוג השמור נשאר.')) return;
            act(e.currentTarget, async () => { await SF.api('dev-store-set', { body: { clear: true } }); dlg.close(); SF.render(); });
        } }, 'ניתוק');
        const dlg = SF.sheet('חיבור לחנות', h('div', { class: 'fields' },
            h('label', { class: 'f wide' }, h('span', null, 'כתובת החנות'), url),
            h('label', { class: 'f' }, h('span', null, 'Consumer key'), key),
            h('label', { class: 'f' }, h('span', null, 'Consumer secret'), secret),
            h('p', { class: 'hint wide' }, 'ב-WooCommerce ← הגדרות ← Advanced ← REST API ← Add key, עם הרשאה Read בלבד. המפתחות נשמרים בשרת (data/) ולא מוצגים שוב.'),
            h('label', { class: 'f wide' }, h('span', null, 'סוד ה-webhook (לא חובה)'), hook),
            h('p', { class: 'hint wide' }, 'לעדכון מיידי של מוצר: ב-WooCommerce ← Advanced ← Webhooks — נושא Product updated / created / deleted, כתובת:'),
            h('div', { class: 'copy-row wide' }, h('div', { class: 'code', dir: 'ltr' }, c.webhook_url)),
            h('div', { class: 'row-btns wide' }, test, clear), out), { action: save });
        url.focus();
    }
})(window.SF);
