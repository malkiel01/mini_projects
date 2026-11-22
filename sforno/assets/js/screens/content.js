/*
 * ניהול התוכן (אפיון 02): #/admin/content — הכותרים; #/admin/content/t/<pid> — כותר ומהדורות;
 * #/admin/content/e/<pid> — העורך (עריכה · תצוגה מקדימה · גרסאות); #/admin/content/aliases — מילון החיפוש.
 *
 * העורך: חזותי (contenteditable + כפתורי סימון) ו-HTML ידני, על אותה טיוטה. השרת מנקה ובודק בכל שמירה
 * (lib/markup.php) — כאן רק נוחות: התוכן נטען לעורך דרך DOMParser (מסמך שלא מריץ דבר) ורשימת היתרים, הדבקה —
 * כטקסט בלבד, ו-sid כפול שהדפדפן שכפל (Enter בפסקה) נמחק לפני השליחה — והשרת נותן מזהה חדש.
 * התצוגה המקדימה — SFRender.render, אותו מנוע של הקורא (04).
 */
'use strict';

(function (SF) {
    const { h } = SF;
    const KINDS = { siddur: 'סידור', tehillim: 'תהילים', book: 'ספר' };
    const ACCESS = { free: 'חופשי', future_paid: 'בתשלום (עתידי, לא מוגש)' };
    const STATUS = { draft: ['טיוטה', 'warn'], published: ['פורסמה', 'ok'], superseded: ['הוחלפה', ''], discarded: ['בוטלה', ''] };
    const STYLE_NAMES = { large: 'גדול', small: 'קטן', bold: 'מודגש', red: 'אדום', gray: 'אפור', center: 'ממורכז' };
    const MARKS = {
        'sf-section': ['§', 'מקטע'], 'sf-anchor': ['📍', 'נקודת ציון'], 'sf-style': ['🎨', 'סגנון'], 'sf-cond': ['🔀', 'תנאי'],
        'sf-note': ['📝', 'הערה'], 'sf-link': ['🔗', 'קישור'],
    };
    const chip = (status) => h('span', { class: 'chip ' + (STATUS[status] || ['', ''])[1] }, (STATUS[status] || [status])[0]);
    const row = (icon, title, sub, href, extra) => h('li', null, h('a', { class: 'use-row', href },
        h('span', { class: 'use-ic', 'aria-hidden': 'true' }, icon),
        h('span', { class: 'use-tx' }, h('b', { class: 'one' }, title), h('small', null, sub)),
        extra || null,
        h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹')));

    /** גיליון עריכה (phone-first-design §5): fields — רשימת שדות; onSave(values) → Promise. */
    function sheet(title, fields, onSave) {
        const err = h('p', { class: 'error', role: 'alert', hidden: true });
        const save = h('button', { type: 'submit', class: 'btn primary' }, 'שמירה');
        const form = h('form', { method: 'dialog', novalidate: true },
            h('header', { class: 'sheet-head' },
                h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'סגירה', onclick: () => dlg.close() }, '✕'),
                h('h2', null, title), save),
            h('div', { class: 'sheet-body' }, h('div', { class: 'fields' }, fields), err));
        const dlg = h('dialog', { 'aria-label': title }, form);
        form.addEventListener('submit', async e => {
            e.preventDefault();
            const vals = Object.fromEntries(new FormData(form));
            save.disabled = true;
            err.hidden = true;
            try { await onSave(vals); dlg.close(); } catch (x) { err.textContent = x.message; err.hidden = false; } finally { save.disabled = false; }
        });
        dlg.addEventListener('close', () => dlg.remove());
        document.body.append(dlg);
        dlg.showModal();
        return dlg;
    }
    const field = (label, input, wide) => h('label', { class: 'f' + (wide ? ' wide' : '') }, h('span', null, label), input);
    const select = (name, opts, value) => h('select', { name }, Object.entries(opts).map(([k, v]) => h('option', { value: k, selected: k === value }, v)));

    function titleSheet(t) {
        sheet(t ? 'עריכת כותר' : 'כותר חדש', [
            field('שם *', h('input', { name: 'name', required: true, maxlength: 120, value: t ? t.name : '' }), true),
            field('סוג', select('kind', KINDS, t ? t.kind : 'siddur')),
            field('גישה', select('access', ACCESS, t ? t.access : 'free')),
            field('מחבר / עורך', h('input', { name: 'author', maxlength: 120, value: (t && t.author) || '' }), true),
            field('תיאור', h('textarea', { name: 'description', rows: 3, maxlength: 1000 }, (t && t.description) || ''), true),
        ], async v => {
            const res = await SF.api('content-title-save', { body: { title: t ? t.pid : null, ...v } });
            SF.toast('נשמר');
            if (t) SF.render(); else SF.go('#/admin/content/t/' + res.title.pid);
        });
    }

    // ── הכותרים ─────────────────────────────────────────────
    SF.route('#/admin/content', async main => {
        const { titles } = await SF.api('content-titles');
        main.replaceChildren(
            h('div', { class: 'toolbar' }, h('p', { class: 'hint', style: 'flex:1;margin:0' }, 'כותר ← מהדורות ← גרסאות. טיוטה לא נראית לקוראים עד שמפרסמים.'),
                h('button', { class: 'btn primary', type: 'button', onclick: () => titleSheet(null) }, '+ כותר')),
            h('ul', { class: 'list' }, titles.length ? titles.map(t => row(t.kind === 'tehillim' ? '📜' : t.kind === 'siddur' ? '📖' : '📚', t.name,
                [KINDS[t.kind], t.editions.length + ' מהדורות', t.access === 'future_paid' ? 'בתשלום (עתידי)' : null].filter(Boolean).join(' · '),
                '#/admin/content/t/' + t.pid, h('span', { class: 'chip ' + (t.public_allowed ? 'ok' : '') }, t.public_allowed ? 'ציבורי' : 'סגור')))
                : h('li', { class: 'empty' }, 'עדיין אין כותרים')),
            h('h3', { class: 'sec-title' }, 'כלים'),
            h('ul', { class: 'list' }, row('🔤', 'מילון חיפוש', 'כתיב מלא/חסר וקיצורים — לחיפוש בקורא', '#/admin/content/aliases')));
    }, { title: 'תוכן', parent: '#/admin' });

    // ── כותר ────────────────────────────────────────────────
    SF.route('#/admin/content/t/:pid', async (main, { pid }) => {
        const { title: t } = await SF.api('content-title', { params: { title: pid } });
        document.title = t.name + ' · ספורנו';
        const sw = h('input', { type: 'checkbox', checked: t.public_allowed, disabled: !t.can_public && !t.public_allowed });
        sw.addEventListener('change', async () => {
            sw.disabled = true;
            try {
                await SF.api('content-title-public', { body: { title: t.pid, on: sw.checked } });
                SF.toast(sw.checked ? 'הכותר פתוח לקוראים' : 'הכותר נסגר לקוראים');
                SF.render();
            } catch (e) { SF.toast(e.message, true); sw.checked = !sw.checked; sw.disabled = false; }
        });
        main.replaceChildren(
            h('section', { class: 'summary' },
                h('div', { class: 'line' }, h('b', { class: 'one', style: 'flex:1' }, t.name),
                    h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'עריכת פרטי הכותר', onclick: () => titleSheet(t) }, '✏️')),
                h('div', { class: 'line muted' }, [KINDS[t.kind], ACCESS[t.access], t.author].filter(Boolean).join(' · ')),
                t.description ? h('div', { class: 'line muted' }, t.description) : null,
                h('label', { class: 'switch' }, sw, h('span', null, 'ציבורי — הקוראים רואים את הגרסה שפורסמה')),
                !t.can_public ? h('p', { class: 'hint' }, "בשלב א' רק סידור ותהילים נפתחים לציבור.") : null),
            h('div', { class: 'toolbar' }, h('h3', { class: 'sec-title', style: 'flex:1;margin:0' }, 'מהדורות'),
                h('button', { class: 'btn primary', type: 'button', onclick: () => sheet('מהדורה חדשה',
                    [field('שם המהדורה *', h('input', { name: 'label', required: true, maxlength: 120, placeholder: 'למשל: נוסח עדות המזרח, הוצאה ראשונה' }), true)],
                    async v => { const r = await SF.api('content-edition-save', { body: { title: t.pid, label: v.label } }); SF.go('#/admin/content/e/' + r.edition.pid); }) }, '+ מהדורה')),
            h('ul', { class: 'list' }, t.editions.length ? t.editions.map(e => row('🗂️', e.label,
                [e.published_n ? 'פורסמה גרסה ' + e.published_n : 'לא פורסמה', e.draft_n ? 'טיוטה ' + e.draft_n : null].filter(Boolean).join(' · '),
                '#/admin/content/e/' + e.pid, e.draft_n ? chip('draft') : null)) : h('li', { class: 'empty' }, 'אין מהדורות — מוסיפים אחת ומתחילים לערוך')));
    }, { title: 'כותר', parent: '#/admin/content' });

    // ── העורך ───────────────────────────────────────────────
    const ED_TAGS = {
        'sf-section': ['sid', 'title'], 'sf-anchor': ['sid', 'title'], 'sf-style': ['v'], 'sf-cond': ['rule'], 'sf-note': ['kind'], 'sf-link': ['to'],
        'sf-audio': ['sid'], p: ['sid'], h2: ['sid'], h3: ['sid'], h4: ['sid'], li: ['sid'], br: [], b: [], strong: [], i: [], em: [], small: [],
        span: [], ul: [], ol: [], table: [], thead: [], tbody: [], tr: [], td: ['colspan', 'rowspan'], th: ['colspan', 'rowspan'], img: ['src', 'alt'],
    };
    const TEXT_BLOCKS = ['P', 'H2', 'H3', 'H4', 'LI', 'TD', 'TH'];
    const FLOW = ['SF-SECTION', 'SF-COND', 'SF-NOTE', 'SF-STYLE', 'TD', 'TH'];

    /** HTML → צמתים חיים בעורך, רק מרשימת ההיתרים (בלי innerHTML: DOMParser לא מריץ סקריפטים ולא טוען דבר). */
    function importInto(target, html) {
        const src = new DOMParser().parseFromString('<!doctype html><html><body>' + html, 'text/html').body;
        const walk = (from, to) => {
            for (const n of from.childNodes) {
                if (n.nodeType === 3) { to.append(document.createTextNode(n.nodeValue)); continue; }
                if (n.nodeType !== 1) continue;
                const tag = n.localName;
                if (!ED_TAGS[tag]) { walk(n, to); continue; }
                const el = document.createElement(tag);
                for (const a of ED_TAGS[tag].concat(['dir', 'lang'])) if (n.hasAttribute(a)) el.setAttribute(a, n.getAttribute(a));
                to.append(el);
                walk(n, el);
            }
        };
        target.replaceChildren();
        walk(src, target);
    }

    SF.route('#/admin/content/e/:pid', async (main, { pid }) => {
        document.body.classList.add('wide');
        const data = await SF.api('content-edition', { params: { edition: pid } });
        const back = document.getElementById('back');
        back.dataset.to = '#/admin/content/t/' + data.title.pid;
        document.title = data.edition.label + ' · ' + data.title.name + ' · ספורנו';
        const st = {
            draft: data.draft, pubN: data.published ? data.published.n : 0,
            mode: 'visual', dirty: false, current: null,
        };
        const base = () => (st.draft ? st.draft.v : 0);
        const customs = data.customs;
        const ruleName = r => (customs.find(c => c.rule === r) || {}).name || r;

        // ── המשטח
        const ed = h('div', { class: 'sf-edit', contenteditable: 'true', role: 'textbox', 'aria-multiline': 'true', 'aria-label': 'עורך התוכן', lang: 'he', dir: 'rtl', spellcheck: 'false' });
        const ta = h('textarea', { class: 'sf-html code', dir: 'ltr', rows: 18, 'aria-label': 'עריכת HTML', spellcheck: 'false', hidden: true });
        const mark = () => { if (!st.dirty) { st.dirty = true; status(); } };
        ed.addEventListener('input', mark);
        ta.addEventListener('input', mark);
        ed.addEventListener('paste', e => {   // הדבקה — כטקסט בלבד
            e.preventDefault();
            const text = (e.clipboardData || window.clipboardData).getData('text/plain');
            document.execCommand('insertText', false, text);
        });
        ed.addEventListener('focus', () => { try { document.execCommand('defaultParagraphSeparator', false, 'p'); document.execCommand('styleWithCSS', false, false); } catch (x) { /* לא נתמך */ } });

        /** ה-HTML של העורך החזותי. sid שהופיע כבר (הדפדפן שכפל בפיצול פסקה) — נמחק, והשרת ייתן חדש. */
        function serialize() {
            const seen = new Set();
            for (const el of ed.querySelectorAll('[sid]')) {
                const s = el.getAttribute('sid');
                if (seen.has(s)) el.removeAttribute('sid'); else seen.add(s);
            }
            return ed.innerHTML;
        }
        const currentHtml = () => (st.mode === 'html' ? ta.value : serialize());
        function load(html) {
            if (st.mode === 'html') ta.value = html; else importInto(ed, html);
            st.current = null;
            props();
        }

        // ── שורת המצב והבעיות
        const statusEl = h('div', { class: 'ed-status', role: 'status' });
        function status() {
            const d = st.draft;
            statusEl.replaceChildren(
                d ? chip('draft') : (st.pubN ? chip('published') : h('span', { class: 'chip' }, 'ריק')),
                h('span', { class: 'one' }, d ? `טיוטה ${d.n} · נשמרה ${SF.when(d.updated_at)}${d.updated_by ? ' · ' + d.updated_by : ''}`
                    : st.pubN ? `גרסה ${st.pubN} פורסמה — שמירה תפתח טיוטה חדשה` : 'שמירה ראשונה תפתח טיוטה'),
                st.dirty ? h('span', { class: 'chip warn' }, 'לא נשמר') : null);
            pubBtn.disabled = !d;
        }
        const issuesEl = h('div', { class: 'issues', 'aria-live': 'polite' });
        function issues(errors, warnings, note) {
            const li = (ic, cls, x) => h('li', { class: 'issue ' + cls }, h('span', { 'aria-hidden': 'true' }, ic),
                h('span', null, x.line ? h('b', null, 'שורה ' + x.line + (x.col ? ':' + x.col : '') + ' · ') : null, x.msg,
                    x.blocks ? h('small', { class: 'chip bad' }, 'חוסם פרסום') : null));
            const all = [...(errors || []).map(x => li('❌', 'bad', x)), ...(warnings || []).map(x => li(x.blocks ? '⛔' : '⚠️', x.blocks ? 'bad' : 'warn', x))];
            issuesEl.replaceChildren(...(all.length ? [note ? h('p', { class: 'hint' }, note) : '', h('ul', { class: 'issue-list' }, all)] : []));
        }

        // ── שמירה ופרסום
        async function save() {
            const html = currentHtml();
            try {
                const r = await SF.api('content-save', { body: { edition: pid, html, base: base(), from: st.pubN } });
                st.draft = r.draft;
                st.dirty = false;
                load(r.draft.html);
                issues([], r.warnings);
                status();
                SF.toast('נשמר');
                return true;
            } catch (e) {
                if (e.code === 'invalid') {
                    issues(e.details && e.details.errors, [], st.mode === 'visual' ? 'מספרי השורות — לפי תצוגת ה-HTML.' : null);
                    SF.toast(e.message, true);
                } else if (e.code === 'conflict') {
                    if (confirm(e.message)) { stash[pid] = html; SF.render(); }
                } else SF.toast(e.message, true);
                return false;
            }
        }
        const saveBtn = h('button', { class: 'btn primary', type: 'button', onclick: save }, 'שמירה');
        const pubBtn = h('button', { class: 'btn ghost', type: 'button', onclick: async () => {
            if (st.dirty && !(await save())) return;
            if (!st.draft) return;
            if (!confirm(`לפרסם את גרסה ${st.draft.n}?` + (st.pubN ? ` היא תחליף את גרסה ${st.pubN}.` : ''))) return;
            try {
                await SF.api('content-publish', { body: { edition: pid, base: base() } });
                SF.toast('פורסם');
                SF.render();
            } catch (e) {
                if (e.code === 'invalid') issues(e.details && e.details.errors, e.details && e.details.warnings, 'צריך לתקן לפני פרסום:');
                else if (e.code === 'conflict' && confirm(e.message)) SF.render();
                SF.toast(e.message, true);
            }
        } }, '🚀 פרסום');
        window.onbeforeunload = () => (st.dirty ? 'יש שינויים שלא נשמרו' : undefined);
        SF.leaveGuard = () => !st.dirty || confirm('יש שינויים שלא נשמרו. לצאת בלי לשמור?');

        // ── כפתורי הסימון
        function range() {
            const s = getSelection();
            if (!s.rangeCount) return null;
            const r = s.getRangeAt(0);
            return ed.contains(r.commonAncestorContainer) ? r : null;
        }
        const blockOf = node => { for (let n = node.nodeType === 1 ? node : node.parentNode; n && n !== ed; n = n.parentNode) if (TEXT_BLOCKS.includes(n.nodeName)) return n; return null; };
        const childOf = (parent, node) => { let n = node; while (n && n.parentNode !== parent) n = n.parentNode; return n; };
        function mk(tag, attrs) {
            const el = document.createElement(tag);
            for (const [k, v] of Object.entries(attrs || {})) if (v !== null && v !== undefined) el.setAttribute(k, v);
            return el;
        }
        /** עוטף בלוקים שלמים (מהבלוק של תחילת הבחירה עד זה של סופה) ברכיב חדש. */
        function wrapBlocks(tag, attrs, r) {
            // בחירה שמתחילה או נגמרת על העורך עצמו (למשל "בחר הכול") — הילד שבמקום הזה
            const at = (c, o, end) => (c === ed ? ed.childNodes[end ? Math.max(0, o - 1) : Math.min(o, ed.childNodes.length - 1)] : c);
            const sc = at(r.startContainer, r.startOffset, false), ec = at(r.endContainer, r.endOffset, true);
            if (!sc || !ec) return null;
            const a = blockOf(sc) || sc;
            const b = blockOf(ec) || ec;
            let p = a.parentNode;
            while (p !== ed && (!p.contains(b) || !(FLOW.includes(p.nodeName)))) p = p.parentNode;
            const first = childOf(p, a), last = childOf(p, b);
            if (!first || !last) return null;
            const el = mk(tag, attrs);
            p.insertBefore(el, first);
            for (let n = first; n;) { const next = n.nextSibling; el.append(n); if (n === last) break; n = next; }
            return el;
        }
        /** עוטף את הבחירה בתוך פסקה אחת; בחירה על פני כמה פסקאות → null. */
        function wrapInline(tag, attrs, r) {
            if (blockOf(r.startContainer) !== blockOf(r.endContainer) || !blockOf(r.startContainer)) return null;
            const el = mk(tag, attrs);
            el.append(r.extractContents());
            for (const x of el.querySelectorAll('p,h2,h3,h4,li')) x.replaceWith(...x.childNodes);   // לא בלוק בתוך שורה
            r.insertNode(el);
            return el;
        }
        function apply(tag, attrs, how) {
            const r = range();
            if (!r) return SF.toast('מסמנים קודם טקסט בעורך', true);
            let el = null;
            if (how === 'block') el = wrapBlocks(tag, attrs, r);
            else {
                if (r.collapsed) return SF.toast('מסמנים את הטקסט שהסימון יחול עליו', true);
                el = wrapInline(tag, attrs, r) || (how === 'inline' ? null : wrapBlocks(tag, attrs, r));
                if (!el) return SF.toast('קישור — בתוך פסקה אחת', true);
            }
            if (!el) return;
            mark();
            getSelection().removeAllRanges();
            pick(el, true);
        }
        /** נקודת ציון: עוטפת בחירה בתוך פסקה אחת; אחרת — סמן ריק בתחילת הבחירה. */
        const canInline = r => blockOf(r.startContainer) && blockOf(r.startContainer) === blockOf(r.endContainer);
        function anchorAt() {
            const r = range();
            if (!r) return SF.toast('מסמנים קודם טקסט בעורך', true);
            const text = r.toString().trim();
            let el;
            if (!r.collapsed && canInline(r)) el = wrapInline('sf-anchor', { title: text.slice(0, 60) }, r);
            else { const c = r.cloneRange(); c.collapse(true); el = mk('sf-anchor', { title: text.slice(0, 60) || null }); c.insertNode(el); }
            mark();
            getSelection().removeAllRanges();
            pick(el, true);
        }
        const tool = (icon, label, fn) => h('button', { type: 'button', class: 'tool', onmousedown: e => e.preventDefault(), onclick: fn },
            h('span', { 'aria-hidden': 'true' }, icon), label);
        const tools = h('div', { class: 'tools', role: 'toolbar', 'aria-label': 'סימון' },
            tool('§', 'מקטע', () => apply('sf-section', {}, 'block')),
            tool('📍', 'נקודת ציון', anchorAt),
            tool('🎨', 'סגנון', () => apply('sf-style', { v: 'bold' })),
            tool('🔀', 'תנאי', () => apply('sf-cond', { rule: customs[0] ? customs[0].rule : '' })),
            tool('📝', 'הוראה', () => apply('sf-note', { kind: 'instruction' })),
            tool('💬', 'פירוש', () => apply('sf-note', { kind: 'commentary' })),
            tool('🔗', 'קישור', () => apply('sf-link', { to: '' }, 'inline')));

        // ── תכונות הסימון שתחת הסמן
        const propsEl = h('div', { class: 'props', hidden: true });
        function pick(el, focus) { st.current = el; props(focus); }
        function props(focus) {
            const el = st.current;
            if (!el || !ed.contains(el)) { propsEl.hidden = true; return; }
            const tag = el.localName;
            const set = (k, v) => { if (v) el.setAttribute(k, v); else el.removeAttribute(k); mark(); };
            const inp = (k, label) => { const x = h('input', { value: el.getAttribute(k) || '', maxlength: 200, 'aria-label': label, placeholder: label }); x.addEventListener('input', () => set(k, x.value.trim())); return x; };
            const sel = (k, opts, label) => {
                const x = h('select', { 'aria-label': label }, Object.entries(opts).map(([v, t]) => h('option', { value: v, selected: v === (el.getAttribute(k) || '') }, t)));
                x.addEventListener('change', () => set(k, x.value));
                return x;
            };
            let f = null;
            if (tag === 'sf-section' || tag === 'sf-anchor') f = inp('title', tag === 'sf-section' ? 'כותרת המקטע' : 'שם לתוכן העניינים');
            else if (tag === 'sf-style') f = sel('v', Object.fromEntries(data.styles.map(s => [s, STYLE_NAMES[s] || s])), 'סגנון');
            else if (tag === 'sf-cond') f = sel('rule', Object.fromEntries([['', '— בחירת כלל —'], ...customs.map(c => [c.rule, c.name + (c.sample ? ' (דוגמה)' : '')])]), 'כלל');
            else if (tag === 'sf-note') f = sel('kind', { instruction: 'הוראה', commentary: 'פירוש' }, 'סוג');
            else if (tag === 'sf-link') {
                const targets = [...ed.querySelectorAll('sf-section[sid], sf-anchor[sid]')].map(x => [x.getAttribute('sid'), (x.localName === 'sf-section' ? '§ ' : '📍 ') + (x.getAttribute('title') || x.getAttribute('sid'))]);
                f = sel('to', Object.fromEntries([['', '— יעד —'], ...targets, ...(el.getAttribute('to') && !targets.some(t => t[0] === el.getAttribute('to')) ? [[el.getAttribute('to'), el.getAttribute('to')]] : [])]), 'יעד הקישור');
            }
            const [ic, name] = MARKS[tag] || ['', tag];
            propsEl.replaceChildren(
                h('span', { class: 'chip' }, ic + ' ' + name),
                f,
                el.getAttribute('sid') ? h('small', { class: 'muted', dir: 'ltr', title: 'המזהה היציב' }, el.getAttribute('sid')) : h('small', { class: 'muted' }, 'מזהה — בשמירה'),
                h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'הסרת הסימון (הטקסט נשאר)', title: 'הסרת הסימון', onclick: () => { el.replaceWith(...el.childNodes); st.current = null; mark(); props(); } }, '🧹'));
            propsEl.hidden = false;
            if (focus && f) f.focus();
        }
        const near = () => {
            const s = getSelection();
            if (!s.rangeCount || !ed.contains(s.anchorNode)) return;
            const n = s.anchorNode.nodeType === 1 ? s.anchorNode : s.anchorNode.parentNode;
            const el = n.closest && n.closest(Object.keys(MARKS).join(','));
            if (el !== st.current && (!el || ed.contains(el))) { st.current = el; props(); }
        };
        document.addEventListener('selectionchange', near);
        ed.addEventListener('click', near);
        SF.onLeave(() => { document.removeEventListener('selectionchange', near); window.onbeforeunload = null; document.body.classList.remove('wide'); });

        // ── מעבר בין חזותי ל-HTML
        const modeSeg = h('div', { class: 'seg', role: 'radiogroup', 'aria-label': 'מצב עריכה' },
            ['visual', 'html'].map(m => h('label', null, h('input', { type: 'radio', name: 'ed-mode', value: m, checked: m === 'visual', onchange: () => setMode(m) }),
                h('span', null, m === 'visual' ? '👁 חזותי' : '</> HTML'))));
        async function setMode(m) {
            if (m === st.mode) return;
            if (m === 'html') { ta.value = serialize(); st.mode = 'html'; }
            else {
                try {
                    const r = await SF.api('content-check', { body: { edition: pid, html: ta.value } });
                    if (r.errors.length) { issues(r.errors, [], 'צריך לתקן את ה-HTML לפני המעבר לעורך החזותי:'); modeSeg.querySelector('[value=html]').checked = true; return; }
                    st.mode = 'visual';
                    importInto(ed, r.html);
                    issues([], r.warnings);
                } catch (e) { SF.toast(e.message, true); modeSeg.querySelector('[value=html]').checked = true; return; }
            }
            ed.hidden = st.mode !== 'visual';
            tools.hidden = st.mode !== 'visual';
            ta.hidden = st.mode !== 'html';
            st.current = null;
            props();
        }

        // ── התצוגה המקדימה — SFRender.render, כמו בקורא
        const pvEl = h('div', { class: 'pv' });
        let pvWidth = sessionStorage.getItem('sf-pv') || 'phone';
        async function preview() {
            pvEl.replaceChildren(h('p', { class: 'loading' }, 'טוען…'));
            let html;
            try {
                const r = await SF.api('content-check', { body: { edition: pid, html: currentHtml() } });
                if (r.errors.length) { issues(r.errors, []); pvEl.replaceChildren(h('p', { class: 'error' }, 'יש שגיאות מבנה — ראו בלשונית העריכה')); return; }
                html = r.html;
            } catch (e) { pvEl.replaceChildren(h('p', { class: 'error' }, e.message)); return; }
            pvEl.replaceChildren(previewFrames(html, pvWidth, w => { pvWidth = w; sessionStorage.setItem('sf-pv', w); preview(); }));
        }

        // ── גרסאות
        const histEl = h('div');
        function history() {
            histEl.replaceChildren(h('ul', { class: 'list' }, data.revisions.map(r => h('li', { class: 'card', style: 'cursor:default' },
                h('div', { class: 'body' },
                    h('div', { class: 'l1' }, h('span', null, 'גרסה ' + r.n), chip(r.status)),
                    h('div', { class: 'l2' }, [r.published_at ? 'פורסמה ' + SF.when(r.published_at) + (r.published_by ? ' · ' + r.published_by : '') : 'נוצרה ' + SF.when(r.created_at) + (r.created_by ? ' · ' + r.created_by : ''),
                        r.based_on_n ? 'מבוססת על ' + r.based_on_n : null].filter(Boolean).join(' · '))),
                h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'תצוגה של גרסה ' + r.n, onclick: () => showRevision(r) }, '👁'),
                r.status !== 'draft' ? h('button', { class: 'btn sm ghost', type: 'button', onclick: () => restore(r) }, '↩️ שחזור') : null))),
                st.draft ? h('div', { class: 'more' }, h('button', { class: 'btn ghost danger', type: 'button', onclick: discard }, 'ביטול הטיוטה')) : '');
        }
        async function showRevision(r) {
            const { revision } = await SF.api('content-revision', { params: { revision: r.pid } });
            const dlg = h('dialog', { class: 'pv-dlg', 'aria-label': 'גרסה ' + r.n },
                h('form', { method: 'dialog' },
                    h('header', { class: 'sheet-head' }, h('button', { class: 'icon-btn', 'aria-label': 'סגירה' }, '✕'), h('h2', null, 'גרסה ' + r.n + ' · ' + STATUS[r.status][0])),
                    h('div', { class: 'sheet-body' }, previewFrames(revision.html, 'phone', null))));
            dlg.addEventListener('close', () => dlg.remove());
            document.body.append(dlg);
            dlg.showModal();
        }
        async function restore(r) {
            if (st.dirty && !confirm('יש שינויים שלא נשמרו בעורך. לשחזר בכל זאת?')) return;
            if (!confirm(`לפתוח טיוטה חדשה מגרסה ${r.n}?` + (st.draft ? ` הטיוטה הפתוחה (${st.draft.n}) תישמר בהיסטוריה כ"בוטלה".` : '') + ' שום גרסה לא נמחקת.')) return;
            try {
                await SF.api('content-restore', { body: { revision: r.pid, base: base() } });
                st.dirty = false;
                SF.toast('נפתחה טיוטה מגרסה ' + r.n);
                SF.render();
            } catch (e) { if (e.code === 'conflict' && confirm(e.message)) SF.render(); else SF.toast(e.message, true); }
        }
        async function discard() {
            if (!confirm(`לבטל את טיוטה ${st.draft.n}? היא תישמר בהיסטוריה, ואפשר לשחזר אותה.`)) return;
            try { await SF.api('content-discard', { body: { edition: pid, base: base() } }); st.dirty = false; SF.render(); } catch (e) { SF.toast(e.message, true); }
        }

        // ── לשוניות
        const panes = { edit: null, preview: pvEl, history: histEl };
        const tabs = h('div', { class: 'seg-tabs', role: 'tablist' }, [['edit', '✏️ עריכה'], ['preview', '👁 תצוגה מקדימה'], ['history', '🕘 גרסאות']].map(([k, label]) =>
            h('button', { type: 'button', role: 'tab', 'aria-selected': String(k === 'edit'), dataset: { tab: k }, onclick: () => tab(k) }, label, k === 'history' ? h('small', null, String(data.revisions.length)) : null)));
        function tab(k) {
            for (const b of tabs.children) b.setAttribute('aria-selected', String(b.dataset.tab === k));
            for (const [name, el] of Object.entries(panes)) el.hidden = name !== k;
            if (k === 'preview') preview();
            if (k === 'history') history();
        }
        const stashed = stash[pid];
        delete stash[pid];
        panes.edit = h('div', { class: 'ed-pane' },
            h('div', { class: 'ed-bar' }, modeSeg, saveBtn, pubBtn),
            statusEl,
            stashed ? h('details', { class: 'stash', open: true }, h('summary', null, 'הנוסח שלך שלא נשמר (כאן, כדי שלא יאבד)'),
                h('textarea', { class: 'code', dir: 'ltr', rows: 6, readonly: true }, stashed),
                h('button', { class: 'btn sm ghost', type: 'button', onclick: () => navigator.clipboard.writeText(stashed).then(() => SF.toast('הועתק')) }, '📋 העתקה')) : null,
            tools, propsEl, ed, ta, issuesEl);
        main.replaceChildren(
            h('p', { class: 'hint' }, data.title.name + ' · ' + data.edition.label),
            tabs, panes.edit, pvEl, histEl);
        pvEl.hidden = true;
        histEl.hidden = true;
        load(st.draft ? st.draft.html : data.published ? data.published.html : '<p><br></p>');
        status();
        if (st.draft && st.draft.warnings) issues([], st.draft.warnings);
    }, { title: 'עורך', parent: '#/admin/content' });

    const stash = {};   // עריכה שלא נשמרה בגלל התנגשות — נשמרת כאן עד הטעינה הבאה של העורך

    /** שני רוחבים: טלפון (360) ומחשב (1024). במסך רחב — זה לצד זה; אחרת — מתג. */
    function previewFrames(html, which, onSwitch) {
        const settings = { conds: 'all', marks: true };
        const frame = (w, label) => {
            const doc = SFRender.render(html, settings);
            SFRender.bind(doc);
            const inner = h('div', { class: 'pv-inner', style: `width:${w}px` }, doc);
            const box = h('figure', { class: 'pv-frame', 'aria-label': label }, h('figcaption', null, label + ' · ' + w + 'px'), inner);
            const fit = () => { const avail = box.clientWidth - 2; inner.style.zoom = avail > 0 && avail < w ? String(avail / w) : ''; };
            new ResizeObserver(fit).observe(box);
            return box;
        };
        const both = h('div', { class: 'pv-both' }, frame(360, '📱 טלפון'), frame(1024, '🖥️ מחשב'));
        if (!onSwitch) return both;
        const seg = h('div', { class: 'seg pv-switch', role: 'radiogroup', 'aria-label': 'רוחב התצוגה' },
            [['phone', '📱 טלפון'], ['desktop', '🖥️ מחשב']].map(([k, t]) => h('label', null,
                h('input', { type: 'radio', name: 'pv-w', value: k, checked: k === which, onchange: () => onSwitch(k) }), h('span', null, t))));
        both.dataset.show = which;
        return h('div', null, seg, both);
    }

    // ── מילון החיפוש ─────────────────────────────────────────
    SF.route('#/admin/content/aliases', async main => {
        const draw = list => {
            const term = h('input', { name: 'term', required: true, maxlength: 60, placeholder: 'מצוה', 'aria-label': 'צורה אחת' });
            const alias = h('input', { name: 'alias', required: true, maxlength: 60, placeholder: 'מצווה', 'aria-label': 'צורה שנייה' });
            const form = h('form', { class: 'toolbar', onsubmit: async e => {
                e.preventDefault();
                try { draw((await SF.api('content-alias-add', { body: { term: term.value, alias: alias.value } })).aliases); SF.toast('נוסף'); } catch (x) { SF.toast(x.message, true); }
            } }, term, alias, h('button', { class: 'btn primary', type: 'submit' }, '+ צמד'));
            main.replaceChildren(
                h('p', { class: 'hint' }, 'צורות שהחיפוש בקורא יחשיב כזהות (כתיב מלא/חסר, קיצורים). נשמרות בלי ניקוד.'),
                form,
                h('ul', { class: 'list' }, list.length ? list.map(a => h('li', { class: 'card', style: 'cursor:default' },
                    h('div', { class: 'body' }, h('div', { class: 'l1' }, h('span', null, a.term + ' ⇄ ' + a.alias))),
                    h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'הסרה: ' + a.term + ' ⇄ ' + a.alias, onclick: async () => {
                        try { draw((await SF.api('content-alias-remove', { body: { term: a.term, alias: a.alias } })).aliases); } catch (x) { SF.toast(x.message, true); }
                    } }, '🗑️'))) : h('li', { class: 'empty' }, 'המילון ריק')));
            term.focus();
        };
        draw((await SF.api('content-aliases')).aliases);
    }, { title: 'מילון חיפוש', parent: '#/admin/content' });
})(window.SF);
