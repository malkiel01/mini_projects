/*
 * הסידור (§2), התהילים (§3) והחיפוש (§4) — בצד הקורא. בלי חשבון: כל המידע האישי במכשיר (SF.store).
 *
 *   #/siddur          — רשימת התפילות (הבסיס של המנהל + הסדר האישי, שלא נדרס), קיצורים, מועדפים, מנהגים קבועים
 *   #/tehillim        — פרקים, "של היום" (⏳ עד 07 — לפי חצות מקומית), הקבוצה שלי, שמות והקדשות (רק במכשיר)
 *   #/search[/<ed>]   — חיפוש בכל הספרייה הציבורית, או בספר אחד. בהדרגה (20 בכל פעם)
 */
'use strict';

(function (SF) {
    const { h } = SF;
    const C = window.SFReaderCore;
    const TEH = { done: {}, group: [], names: [] };
    const NAME_KINDS = ['לרפואת', 'לעילוי נשמת', 'להצלחת', 'לזכות'];

    /** ההסבר על שמירה מקומית — פעם אחת (§3 של האפיון: "שמירה מקומית עלולה להימחק…"). */
    SF.localNotice = async function localNotice(into) {
        if (await SF.store.get('seen:local', false)) return;
        const card = h('div', { class: 'notice', role: 'note' },
            h('p', null, h('b', null, 'נשמר רק במכשיר הזה. '), 'ההעדפות, המקום, הסימניות, ההערות והשמות לתפילה נשמרים בדפדפן הזה בלבד. ',
                'שמירה מקומית עלולה להימחק בפינוי נתוני האתר, ואינה מסונכרנת לחשבון.'),
            h('button', { class: 'btn sm primary', type: 'button', onclick: async () => { await SF.store.set('seen:local', true); card.remove(); } }, 'הבנתי'));
        into.prepend(card);
    };

    const row = (href, icon, title, sub, extra, disabled) => h('li', null, h(disabled ? 'div' : 'a', { class: 'use-row' + (disabled ? ' off' : ''), href: disabled ? null : href },
        h('span', { class: 'use-ic', 'aria-hidden': 'true' }, icon),
        h('span', { class: 'use-tx' }, h('b', { class: 'one' }, title), sub ? h('small', { class: 'one' }, sub) : null),
        extra || null,
        disabled ? null : h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹')));

    /** המהדורות מסוג אחד, והמהדורה הנבחרת (נשמרת במכשיר). */
    async function pickEdition(kind) {
        const cat = await SF.catalog(true);
        const eds = [];
        for (const t of cat.titles) if (t.kind === kind) for (const e of t.editions) eds.push({ title: t, ed: e });
        const want = await SF.store.get(kind + ':edition', null);
        return { cat, eds, cur: eds.find(x => x.ed.pid === want) || eds[0] || null };
    }

    function editionSwitch(kind, eds, cur) {
        if (eds.length < 2) return null;
        return h('label', { class: 'f' }, h('span', null, 'מהדורה'), h('select', { onchange: async e => { await SF.store.set(kind + ':edition', e.target.value); SF.render(); } },
            eds.map(x => h('option', { value: x.ed.pid, selected: x === cur }, x.title.name + ' · ' + x.ed.label))));
    }

    async function emptyState(main, what) {
        const staff = SF.isStaff && SF.isStaff();
        SF.put(main, h('div', { class: 'soon' }, h('div', { class: 'ic', 'aria-hidden': 'true' }, '📭'),
            h('p', null, 'עוד לא פורסם ' + what + ' לקוראים.'),
            staff ? h('p', { class: 'hint' }, 'מנהל: אפשר ליצור תוכן דוגמה קצר (מסומן "דוגמה") כדי לבדוק את הקורא.') : null,
            staff ? h('button', { class: 'btn primary', type: 'button', onclick: async e => {
                e.currentTarget.disabled = true;
                try { await SF.api('content-sample', { body: {} }); SF.toast('נוצר תוכן דוגמה'); SF.render(); } catch (x) { SF.toast(x.message, true); e.currentTarget.disabled = false; }
            } }, '🧪 ליצור תוכן דוגמה') : null));
    }

    // ── הסידור ───────────────────────────────────────────────
    SF.route('#/siddur', async main => {
        const { cat, eds, cur } = await pickEdition('siddur');
        if (!cur) return emptyState(main, 'סידור');
        const ed = cur.ed;
        const groups = cat.customs || [];
        const base = (ed.items || []).filter(i => i.kind === 'prayer');
        const shortcuts = (ed.items || []).filter(i => i.kind === 'shortcut');
        let merged = C.mergeOrder(await SF.store.get('order:' + ed.pid, null), base);
        await SF.store.set('order:' + ed.pid, { order: merged.order, names: merged.names });
        const pos = await SF.store.get('pos:' + ed.pid, null);
        let favs = (await SF.store.get('favorites', [])).filter(f => f.edition === ed.pid);
        let perm = C.permanent(groups, await SF.store.get('customs', {}));
        let editing = false;
        const listBox = h('ul', { class: 'list' });
        const favBox = h('div');
        const customsBtn = h('button', { class: 'use-row', type: 'button', onclick: customsSheet });
        const link = it => '#/r/' + ed.pid + (it.sid ? '/' + it.sid : '');

        function drawList() {
            listBox.replaceChildren(...merged.list.map((it, i) => {
                if (!editing) return row(link(it), '📖', it.name, it.available ? null : 'לא זמין — הוסר מהרשימה', it.available ? null : h('span', { class: 'chip' }, 'לא זמין'), !it.available);
                const mv = (dir, dis) => h('button', { class: 'icon-btn', type: 'button', disabled: dis, 'aria-label': (dir === 'up' ? 'למעלה: ' : 'למטה: ') + it.name,
                    onclick: async () => { merged = C.mergeOrder({ order: C.move(merged.order, it.pid, dir), names: merged.names }, base); await SF.store.set('order:' + ed.pid, { order: merged.order, names: merged.names }); drawList(); } }, dir === 'up' ? '↑' : '↓');
                return h('li', { class: 'card' }, h('div', { class: 'body' }, h('div', { class: 'l1' }, h('b', { class: 'one' }, it.name)), it.available ? null : h('div', { class: 'l2' }, 'לא זמין')),
                    mv('up', i === 0), mv('down', i === merged.list.length - 1),
                    it.available ? null : h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'הסרה מהרשימה שלי: ' + it.name, onclick: async () => {
                        const order = merged.order.filter(p => p !== it.pid);
                        merged = C.mergeOrder({ order, names: merged.names }, base);
                        await SF.store.set('order:' + ed.pid, { order: merged.order, names: merged.names });
                        drawList();
                    } }, '🗑️'));
            }));
            if (!merged.list.length) listBox.replaceChildren(h('li', { class: 'empty' }, 'אין עדיין תפילות ברשימה'));
        }
        function drawFavs() {
            favBox.replaceChildren(...(!favs.length ? [] : [h('h3', { class: 'sec-title' }, '⭐ מועדפים'), h('ul', { class: 'list' }, favs.map(f =>
                row('#/r/' + ed.pid + '/' + f.sid, '⭐', f.name, null, h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'הסרה מהמועדפים: ' + f.name, onclick: async e => {
                    e.preventDefault();
                    const all = await SF.store.update('favorites', [], l => l.filter(x => x.id !== f.id));
                    favs = all.filter(x => x.edition === ed.pid);
                    drawFavs();
                } }, '✕'))))]));
        }
        function drawCustoms() {
            const names = groups.map(g => (g.customs.find(c => c.rule === perm[g.grp]) || {}).name).filter(Boolean);
            customsBtn.replaceChildren(h('span', { class: 'use-ic', 'aria-hidden': 'true' }, '🔀'),
                h('span', { class: 'use-tx' }, h('b', null, 'המנהגים שלי'), h('small', { class: 'one' }, names.join(' · ') || '—')), h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹'));
        }
        function customsSheet() {
            SF.sheet('המנהגים שלי', h('div', null, h('p', { class: 'hint' }, 'ההגדרה הקבועה — בכל כניסה לתפילה. אין שינוי אוטומטי לפי תאריך.'),
                groups.map(g => h('fieldset', { class: 'group' }, h('legend', { class: 'sec-title' }, g.customs.map(c => c.name).join(' / ')),
                    h('div', { class: 'seg' }, g.customs.map(c => h('label', null,
                        h('input', { type: 'radio', name: 'p-' + g.grp, value: c.rule, checked: perm[g.grp] === c.rule, onchange: async () => { perm = Object.assign({}, perm, { [g.grp]: c.rule }); await SF.store.set('customs', perm); drawCustoms(); } }),
                        h('span', null, c.name))))))));
        }
        const editBtn = h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'סידור הרשימה שלי', 'aria-pressed': 'false', onclick: () => {
            editing = !editing; editBtn.setAttribute('aria-pressed', String(editing)); editBtn.textContent = editing ? '✓' : '✏️'; drawList();
        } }, '✏️');

        main.replaceChildren(...[
            editionSwitch('siddur', eds, cur),
            pos ? h('ul', { class: 'list' }, row('#/r/' + ed.pid, '▶️', 'להמשיך מהמקום שעצרתי', cur.title.name)) : null,
            shortcuts.length ? h('nav', { class: 'tiles', 'aria-label': 'קיצורים' }, shortcuts.map(s => h('a', { class: 'tile', href: link(s) }, h('span', { class: 'tile-ic', 'aria-hidden': 'true' }, '↪'), s.name))) : null,
            h('div', { class: 'toolbar' }, h('h3', { class: 'sec-title', style: 'flex:1' }, 'תפילות'), editBtn,
                h('a', { class: 'icon-btn', href: '#/search/' + ed.pid, 'aria-label': 'חיפוש בסידור' }, '🔍')),
            listBox, favBox,
            groups.length ? h('ul', { class: 'list', style: 'margin-top:var(--s3)' }, h('li', null, customsBtn)) : null].filter(Boolean));
        drawList();
        drawFavs();
        drawCustoms();
        SF.localNotice(main);
    }, { title: 'סידור' });

    // ── התהילים ──────────────────────────────────────────────
    const heb = n => {
        const ones = ['', 'א', 'ב', 'ג', 'ד', 'ה', 'ו', 'ז', 'ח', 'ט'], tens = ['', 'י', 'כ', 'ל', 'מ', 'נ', 'ס', 'ע', 'פ', 'צ'];
        let s = n >= 100 ? 'ק' : '';
        const r = n % 100;
        if (r === 15) return s + 'טו';
        if (r === 16) return s + 'טז';
        return s + tens[Math.floor(r / 10)] + ones[r % 10];
    };

    SF.route('#/tehillim', async main => {
        const { eds, cur } = await pickEdition('tehillim');
        if (!cur) return emptyState(main, 'תהילים');
        const ed = cur.ed;
        const toc = ed.text ? (await SF.api('read-toc', { params: { edition: ed.pid } })).toc : [];
        const chs = C.chapters(toc);
        const byN = {};
        for (const c of chs) byN[c.n] = c;
        let st = Object.assign({}, TEH, await SF.store.get('tehillim', TEH));
        const save = async () => { st = await SF.store.set('tehillim', st); };
        const tabKey = await SF.store.get('tehillim:tab', 'chapters');
        const panes = { chapters: h('div'), today: h('div'), group: h('div'), names: h('div') };
        const tabs = h('div', { class: 'seg-tabs', role: 'tablist' }, [['chapters', 'פרקים'], ['today', 'של היום'], ['group', 'הקבוצה שלי'], ['names', 'שמות']].map(([k, l]) =>
            h('button', { type: 'button', role: 'tab', 'aria-selected': String(k === tabKey), dataset: { tab: k }, onclick: () => tab(k) }, l)));
        function tab(k) {
            for (const b of tabs.children) b.setAttribute('aria-selected', String(b.dataset.tab === k));
            for (const [n, el] of Object.entries(panes)) el.hidden = n !== k;
            SF.store.set('tehillim:tab', k);
            ({ chapters: drawChapters, today: drawToday, group: drawGroup, names: drawNames })[k]();
        }
        const open = c => '#/r/' + ed.pid + '/' + c.sid;
        const chapBtn = c => h('a', { class: 'th-ch' + (st.done[c.sid] ? ' done' : ''), href: open(c), 'aria-label': 'פרק ' + heb(c.n) + (st.done[c.sid] ? ', הושלם' : '') }, heb(c.n));
        const list = ns => h('div', { class: 'th-grid' }, ns.map(n => (byN[n] ? chapBtn(byN[n]) : h('span', { class: 'th-ch off', 'aria-label': 'פרק ' + heb(n) + ' — לא במהדורה הזו' }, heb(n)))));

        function drawChapters() {
            const doneN = chs.filter(c => st.done[c.sid]).length;
            const q = h('input', { type: 'search', placeholder: 'מספר פרק (למשל 23 או כג), או מילה לחיפוש', 'aria-label': 'חיפוש פרק', enterkeyhint: 'search' });
            const form = h('form', { class: 'toolbar', onsubmit: e => {
                e.preventDefault();
                const v = q.value.trim();
                const n = /^\d+$/.test(v) ? +v : chs.findIndex((c, i) => heb(i + 1) === v.replace(/['"׳״]/g, '')) + 1;
                if (byN[n]) SF.go(open(byN[n]));
                else if (v) SF.go('#/search/' + ed.pid + '?q=' + encodeURIComponent(v));
            } }, q, h('button', { class: 'btn primary', type: 'submit' }, 'פתיחה'));
            SF.put(panes.chapters, form,
                h('div', { class: 'summary' }, h('div', { class: 'line' }, h('span', null, 'הושלמו ', h('b', null, String(doneN)), ' מתוך ', String(chs.length)),
                    doneN ? h('button', { class: 'btn sm ghost', type: 'button', onclick: async () => { if (!confirm('לאפס את סימוני "סיימתי"?')) return; st.done = {}; await save(); drawChapters(); } }, 'איפוס') : null),
                    h('div', { class: 'track' }, h('span', { style: 'width:' + (chs.length ? Math.round(100 * doneN / chs.length) : 0) + '%' }))),
                chs.length ? h('div', { class: 'th-grid' }, chs.map(chapBtn)) : h('p', { class: 'empty' }, 'במהדורה הזו אין פרקים בטקסט'),
                h('p', { class: 'hint' }, 'פתיחת פרק אינה מסמנת אותו. "סיימתי" — בקורא: הקשה על שורה, ואז ✓.'));
        }
        function drawToday() {
            const t = C.today(new Date(), C.hebDayIntl);
            const days = ['ראשון', 'שני', 'שלישי', 'רביעי', 'חמישי', 'שישי', 'שבת'];
            SF.put(panes.today, 
                h('p', { class: 'notice warn' }, '⏳ מתחלף בחצות לפי השעון של המכשיר. ההחלפה בצאת הכוכבים — כשיתווספו זמני היום.'),
                h('h3', { class: 'sec-title' }, 'יום ' + days[t.weekday] + ' — חלוקה לימי השבוע'), list(t.week),
                h('h3', { class: 'sec-title' }, 'יום ' + heb(t.day) + ' בחודש — חלוקה לימי החודש'), list(t.month),
                h('details', { class: 'more' }, h('summary', null, 'כל החלוקה לשבוע ולחודש'),
                    h('ul', { class: 'list' }, C.WEEK.map(([a, b], i) => h('li', { class: 'card' }, h('div', { class: 'body' }, h('div', { class: 'l1' }, h('b', null, 'יום ' + days[i]), h('span', { class: 'muted' }, heb(a) + '–' + heb(b))))))),
                    h('ul', { class: 'list' }, C.MONTH.map(([a, b], i) => h('li', { class: 'card' }, h('div', { class: 'body' }, h('div', { class: 'l1' }, h('b', null, heb(i + 1) + ' בחודש'),
                        h('span', { class: 'muted' }, a === b ? 'קיט' + (i === 24 ? ' (א)' : ' (ב)') : heb(a) + '–' + heb(b)))))))));
        }
        function drawGroup() {
            const sel = h('select', { 'aria-label': 'פרק להוספה' }, chs.filter(c => !st.group.includes(c.sid)).map(c => h('option', { value: c.sid }, 'פרק ' + heb(c.n))));
            const mine = st.group.map(sd => chs.find(c => c.sid === sd)).filter(Boolean);
            SF.put(panes.group, 
                h('p', { class: 'hint' }, 'קבוצת פרקים קבועה משלך, בסדר שתבחר. נשמרת במכשיר.'),
                h('form', { class: 'toolbar', onsubmit: async e => { e.preventDefault(); if (!sel.value) return; st.group = st.group.concat([sel.value]); await save(); drawGroup(); } },
                    sel, h('button', { class: 'btn primary', type: 'submit', disabled: !sel.options.length }, '+ פרק')),
                mine.length ? h('ul', { class: 'list' }, mine.map((c, i) => h('li', { class: 'card' },
                    h('a', { class: 'body', href: open(c) }, h('div', { class: 'l1' }, h('b', null, 'פרק ' + heb(c.n)), st.done[c.sid] ? h('span', { class: 'chip ok' }, '✓') : null)),
                    h('button', { class: 'icon-btn', type: 'button', disabled: i === 0, 'aria-label': 'למעלה: פרק ' + heb(c.n), onclick: async () => { st.group = C.move(st.group, c.sid, 'up'); await save(); drawGroup(); } }, '↑'),
                    h('button', { class: 'icon-btn', type: 'button', disabled: i === mine.length - 1, 'aria-label': 'למטה: פרק ' + heb(c.n), onclick: async () => { st.group = C.move(st.group, c.sid, 'down'); await save(); drawGroup(); } }, '↓'),
                    h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'הסרה: פרק ' + heb(c.n), onclick: async () => { st.group = st.group.filter(x => x !== c.sid); await save(); drawGroup(); } }, '🗑️')))) :
                    h('p', { class: 'empty' }, 'הקבוצה ריקה'));
        }
        function drawNames() {
            const name = h('input', { maxlength: 80, placeholder: 'שם (למשל: פלוני בן פלונית)', 'aria-label': 'שם' });
            const kind = h('select', { 'aria-label': 'הקדשה' }, NAME_KINDS.map(k => h('option', { value: k }, k)));
            const edit = n => {
                const nm = h('input', { maxlength: 80, value: n.name, 'aria-label': 'שם' });
                const kd = h('select', { 'aria-label': 'הקדשה' }, NAME_KINDS.map(k => h('option', { value: k, selected: k === n.note }, k)));
                const dlg = SF.sheet('עריכת שם', h('div', { class: 'fields' }, h('label', { class: 'f wide' }, h('span', null, 'שם'), nm), h('label', { class: 'f wide' }, h('span', null, 'הקדשה'), kd)),
                    { action: h('button', { class: 'btn primary', type: 'button', onclick: async () => {
                        if (!nm.value.trim()) return;
                        st.names = st.names.map(x => (x.id === n.id ? Object.assign({}, x, { name: nm.value.trim(), note: kd.value, updated_at: new Date().toISOString() }) : x));
                        await save(); dlg.close(); drawNames();
                    } }, 'שמירה') });
            };
            SF.put(panes.names, 
                h('p', { class: 'hint' }, '🔒 השמות נשמרים רק במכשיר הזה: לא נשלחים לשרת, לא בחיפוש, לא בקישורים ולא בשום דיווח. הם מוצגים בראש הקריאה.'),
                h('form', { class: 'toolbar', onsubmit: async e => {
                    e.preventDefault();
                    const v = name.value.trim();
                    if (!v) return;
                    const now = new Date().toISOString();
                    st.names = st.names.concat([{ id: SF.store.id(), name: v, note: kind.value, created_at: now, updated_at: now }]);
                    await save(); drawNames();
                } }, kind, name, h('button', { class: 'btn primary', type: 'submit' }, '+ שם')),
                st.names.length ? h('ul', { class: 'list' }, st.names.map(n => h('li', { class: 'card' },
                    h('div', { class: 'body' }, h('div', { class: 'l1' }, h('b', { class: 'one' }, n.name)), h('div', { class: 'l2' }, n.note || '')),
                    h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'עריכה: ' + n.name, onclick: () => edit(n) }, '✏️'),
                    h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'מחיקה: ' + n.name, onclick: async () => { st.names = st.names.filter(x => x.id !== n.id); await save(); drawNames(); } }, '🗑️')))) :
                    h('p', { class: 'empty' }, 'אין שמות'));
        }

        const pos = await SF.store.get('pos:' + ed.pid, null);
        main.replaceChildren(...[editionSwitch('tehillim', eds, cur),
            pos ? h('ul', { class: 'list' }, row('#/r/' + ed.pid, '▶️', 'להמשיך מהמקום שעצרתי', cur.title.name)) : null,
            tabs, ...Object.values(panes)].filter(Boolean));
        tab(panes[tabKey] ? tabKey : 'chapters');
        SF.localNotice(main);
    }, { title: 'תהילים' });

    // ── החיפוש ───────────────────────────────────────────────
    async function search(main, params) {
        const edition = params.edition ? params.edition.split('?')[0] : null;
        const pre = new URLSearchParams((location.hash.split('?')[1] || '')).get('q') || '';
        const cat = await SF.catalog().catch(() => ({ titles: [] }));
        let book = null;
        for (const t of cat.titles) for (const e of t.editions) if (e.pid === edition) book = t.name + ' · ' + e.label;
        if (edition) document.getElementById('back').dataset.to = '#/r/' + edition;
        const q = h('input', { type: 'search', value: pre, placeholder: 'מילה או צירוף, בלי ניקוד', 'aria-label': 'חיפוש', enterkeyhint: 'search' });
        const out = h('ul', { class: 'list' });
        const more = h('button', { class: 'btn ghost', type: 'button', hidden: true }, 'עוד תוצאות');
        const status = h('p', { class: 'hint', 'aria-live': 'polite' });
        let offset = 0, words = [], token = 0;
        async function run(append) {
            const my = ++token;
            if (!append) { offset = 0; out.replaceChildren(); }
            status.textContent = 'מחפש…';
            more.hidden = true;
            try {
                const r = await SF.api('read-search', { params: Object.assign({ q: q.value, offset }, edition ? { edition } : {}) });
                if (my !== token) return;
                words = r.words;
                for (const x of r.results) {
                    out.append(h('li', null, h('a', { class: 'use-row', href: '#/r/' + x.edition + '/' + x.sid, onclick: () => { SF.pendingMark = { edition: x.edition, sid: x.sid, words: x.words }; } },
                        h('span', { class: 'use-ic', 'aria-hidden': 'true' }, x.kind === 'tehillim' ? '📜' : '📖'),
                        h('span', { class: 'use-tx' }, h('b', { class: 'one' }, x.title + (x.section ? ' · ' + x.section : '')), h('small', null, x.snippet)),
                        h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹'))));
                }
                offset += r.results.length;
                more.hidden = !r.more;
                status.textContent = !offset ? (q.value.trim() ? 'לא נמצא' : '') : offset + (r.more ? '+' : '') + ' תוצאות' + (r.variants && r.variants.length > 1 ? ' · כולל: ' + r.variants.slice(1).join(', ') : '');
            } catch (e) {
                status.textContent = e.message;
            }
        }
        more.addEventListener('click', () => run(true));
        main.replaceChildren(
            book ? h('p', { class: 'hint' }, 'חיפוש ב' + book) : h('p', { class: 'hint' }, 'חיפוש בכל מה שפורסם בספורנו. בלי ניקוד וטעמים; כתיב מלא וחסר לפי המילון.'),
            h('form', { class: 'toolbar', onsubmit: e => { e.preventDefault(); run(false); } }, q, h('button', { class: 'btn primary', type: 'submit' }, 'חיפוש')),
            status, out, more);
        if (pre) run(false);
        q.focus();
    }
    SF.route('#/search', search, { title: 'חיפוש' });
    SF.route('#/search/:edition', search, { title: 'חיפוש' });
})(window.SF);
