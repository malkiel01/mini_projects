/*
 * הניהול של 07: #/admin/halacha — לוח לפי תאריך עברי (חודש + יום), עם התאריך הלועזי שהוא נופל בו השנה (§4);
 * #/admin/halacha/<חודש>/<יום> — העורך: סימון ספורנו (HTML), תצוגה מקדימה במנוע של הקורא, טיוטה / פרסום / הסרת פרסום.
 * הניקוי — בשרת, sf_mk_clean של 02 (אותו ניקוי). #/admin/zmanim — השיטות: אישור, ברירת מחדל, "לבדיקה בלבד";
 * #/admin/zmanim/<pid|new> — עורך ההגדרה (כל שמירה = גרסה), עם תצוגה של הזמנים לירושלים היום.
 */
'use strict';

(function (SF) {
    const { h } = SF;
    const Cal = window.SFCal;
    const STATE = { none: ['', 'ריק'], draft: ['warn', 'טיוטה'], changed: ['warn', 'פורסם · יש טיוטה'], published: ['ok', 'פורסם'] };
    const gDate = iso => (iso ? iso.split('-').reverse().map(Number).join('.') : 'לא חל השנה');

    // ── הלכה: הלוח ─────────────────────────────────────────────
    SF.route('#/admin/halacha', async main => {
        const r = await SF.api('halacha-list');
        const by = {};
        for (const it of r.items) by[it.month + '-' + it.day] = it;
        let month = +(sessionStorage.getItem('sf:hl-month') || r.today.month);
        const months = Cal.ORDER;
        const sel = h('select', { 'aria-label': 'חודש' }, months.map(m => h('option', { value: m }, Cal.monthName(m, null) + (m === 12 ? ' (שנה מעוברת)' : m === 13 ? ' / אדר' : ''))));
        sel.value = String(month);
        const grid = h('div', { class: 'hl-grid' });
        function draw() {
            sessionStorage.setItem('sf:hl-month', String(month));
            const len = Cal.monthLen(r.year, month);
            SF.put(grid, Array.from({ length: 30 }, (_, i) => {
                const d = i + 1;
                const it = by[month + '-' + d];
                const st = it ? it.state : 'none';
                const g = Cal.fromHebrew(r.year, month, d);
                const today = r.today.month === month && r.today.day === d;
                return h('a', { class: 'hl-day ' + st + (today ? ' today' : '') + (d > len ? ' off' : ''), href: '#/admin/halacha/' + month + '/' + d,
                    'aria-label': Cal.gem(d) + ' ' + Cal.monthName(month, null) + ' — ' + STATE[st][1] + (g ? ', ' + g.d + '.' + g.m : ', לא חל השנה') },
                h('b', null, Cal.gem(d)), h('small', null, g ? g.d + '.' + g.m : '—'));
            }));
        }
        sel.addEventListener('change', () => { month = +sel.value; draw(); });
        const hy = { y: r.year, m: r.today.month, d: r.today.day, leap: r.leap };
        SF.put(main,
            h('div', { class: 'summary' }, h('div', { class: 'line' }, 'היום (בשרת): ', h('b', null, Cal.hebText(hy))),
                h('div', { class: 'line hint' }, 'ההלכה משויכת לתאריך עברי בלי שנה. התאריך הלועזי — השנה (' + Cal.gem(r.year) + '). אדר בשנה פשוטה = אדר ב\'.')),
            h('label', { class: 'f' }, h('span', null, 'חודש'), sel),
            grid,
            h('p', { class: 'hint' }, 'ירוק — פורסם · צהוב — טיוטה · מסגרת — היום. יום בלי הלכה — הקורא לא רואה דבר (לא הודעה ולא הלכה אחרת). תוכן עתידי לא נשלח לקוראים לפני מועדו.'));
        draw();
    }, { title: 'הלכה יומית', parent: '#/admin' });

    // ── הלכה: העורך ────────────────────────────────────────────
    SF.route('#/admin/halacha/:m/:d', async (main, { m, d }) => {
        const month = +m, day = +d;
        const { item } = await SF.api('halacha-get', { params: { month, day } });
        const st = { item, dirty: false };
        const when = it => (it.greg ? 'חל השנה ב-' + gDate(it.greg) + ' (יום ' + SF.daily.WEEKDAYS[new Date(it.greg + 'T12:00:00Z').getUTCDay()] + ')' : 'לא חל השנה');
        const title = Cal.gem(day) + ' ' + Cal.monthName(month, null);
        document.title = title + ' · הלכה יומית · ספורנו';
        const ta = h('textarea', { class: 'sf-html code', dir: 'rtl', rows: 14, 'aria-label': 'ההלכה — סימון ספורנו', spellcheck: 'false' });
        ta.value = item.draft_html || item.published_html || '<p></p>';
        const pv = h('div', { class: 'hl-preview' });
        const head = h('div', { class: 'summary' });
        const issues = h('div', { class: 'issues', 'aria-live': 'polite' });
        const preview = () => SF.put(pv, SFRender.render(ta.value, { conds: 'all', marks: true, filesBase: 'api.php?action=content-file&path=' }));
        let pt = 0;
        ta.addEventListener('input', () => { if (!st.dirty) { st.dirty = true; status(); } clearTimeout(pt); pt = setTimeout(preview, 300); });
        const err = (list, note) => SF.put(issues, (list || []).length ? [note ? h('p', { class: 'hint' }, note) : null,
            h('ul', { class: 'issue-list' }, list.map(x => h('li', { class: 'issue ' + (x.blocks === false ? 'warn' : 'bad') }, h('span', { 'aria-hidden': 'true' }, x.blocks === false ? '⚠️' : '❌'),
                h('span', null, x.line ? h('b', null, 'שורה ' + x.line + ' · ') : null, x.msg))))] : []);
        function status() {
            const it = st.item;
            const [cls, label] = STATE[it.state];
            SF.put(head,
                h('div', { class: 'line' }, h('b', null, title), h('span', { class: 'chip ' + cls }, label), st.dirty ? h('span', { class: 'chip warn' }, 'לא נשמר') : null),
                h('div', { class: 'line hint' }, when(it) + (it.published_at ? ' · פורסם ' + SF.when(it.published_at) : '')));
            pubBtn.disabled = !it.draft_html && !st.dirty;
            unBtn.hidden = !it.published_html;
        }
        async function save() {
            try {
                const r = await SF.api('halacha-save', { body: { month, day, html: ta.value, base: st.item.v } });
                st.item = r.item; st.dirty = false;
                ta.value = r.item.draft_html;
                err((r.warnings || []).map(w => Object.assign({ blocks: !!w.blocks }, w)));
                status(); preview(); SF.toast('נשמר');
                return true;
            } catch (e) {
                if (e.code === 'invalid') err(e.details && e.details.errors, 'לא נשמר:');
                else if (e.code === 'conflict' && confirm(e.message)) SF.render();
                SF.toast(e.message, true);
                return false;
            }
        }
        const saveBtn = h('button', { class: 'btn primary', type: 'button', onclick: save }, 'שמירה');
        const pubBtn = h('button', { class: 'btn ghost', type: 'button', onclick: async () => {
            if (st.dirty && !(await save())) return;
            if (!confirm('לפרסם את ההלכה ל' + title + '? ' + when(st.item) + '.')) return;
            try {
                st.item = (await SF.api('halacha-publish', { body: { month, day, base: st.item.v } })).item;
                status(); SF.toast('פורסם');
            } catch (e) {
                if (e.code === 'invalid') err([...((e.details && e.details.errors) || []), ...((e.details && e.details.warnings) || [])], 'צריך לתקן לפני פרסום:');
                SF.toast(e.message, true);
            }
        } }, '🚀 פרסום');
        const unBtn = h('button', { class: 'btn ghost danger', type: 'button', onclick: async () => {
            if (!confirm('להסיר את הפרסום? הקוראים לא יראו הלכה לתאריך הזה. התוכן נשאר כטיוטה.')) return;
            try { st.item = (await SF.api('halacha-unpublish', { body: { month, day } })).item; status(); SF.toast('הפרסום הוסר'); } catch (e) { SF.toast(e.message, true); }
        } }, 'הסרת פרסום');
        SF.leaveGuard = () => !st.dirty || confirm('יש שינויים שלא נשמרו. לצאת בלי לשמור?');
        SF.put(main, head, h('div', { class: 'row-btns' }, saveBtn, pubBtn, unBtn), issues,
            h('label', { class: 'f' }, h('span', null, 'הטקסט (סימון ספורנו — כמו בעורך התוכן)'), ta),
            h('h3', { class: 'sec-title' }, 'תצוגה מקדימה'), pv);
        status(); preview();
    }, { title: 'הלכה יומית', parent: '#/admin/halacha' });

    // ── שיטות הזמנים ───────────────────────────────────────────
    const ST = { approved: ['ok', 'מאושרת'], draft: ['warn', 'טיוטה'], retired: ['', 'לא מוצעת'] };
    SF.route('#/admin/zmanim', async main => {
        const { methods } = await SF.api('zmanim-method-list');
        const act = async (action, body, msg) => { try { await SF.api(action, { body }); SF.toast(msg); SF.render(); } catch (e) { SF.toast(e.message, true); } };
        const hasDefault = methods.some(m => m.is_default);
        SF.put(main,
            h('div', { class: 'summary' },
                h('div', { class: 'line' }, h('b', null, hasDefault ? 'ברירת המחדל: ' + methods.find(m => m.is_default).name : 'אין ברירת מחדל')),
                h('div', { class: 'line hint' }, hasDefault ? 'קורא שלא בחר רואה את הזמנים לפיה.' : 'קורא שלא בחר שיטה יתבקש לבחור לפני שיראה שעה. לא ממציאים ברירת מחדל הלכתית.')),
            h('a', { class: 'btn primary', href: '#/admin/zmanim/new' }, '+ שיטה חדשה'),
            h('ul', { class: 'list' }, methods.map(m => h('li', { class: 'card', style: 'cursor:default' },
                h('a', { class: 'body', href: '#/admin/zmanim/' + m.pid },
                    h('div', { class: 'l1' }, h('b', { class: 'one' }, m.name), m.test ? h('span', { class: 'chip warn' }, 'לבדיקה בלבד') : h('span', { class: 'chip ' + ST[m.status][0] }, ST[m.status][1]),
                        m.is_default ? h('span', { class: 'chip ok' }, 'ברירת מחדל') : null),
                    h('div', { class: 'l2' }, 'גרסה ' + m.version + ' · ' + m.def.zmanim.length + ' זמנים · עודכנה ' + SF.when(m.updated_at))),
                m.test ? null : m.status === 'approved'
                    ? h('button', { class: 'btn sm ghost', type: 'button', onclick: () => act('zmanim-method-default', { method: m.is_default ? null : m.pid }, m.is_default ? 'ברירת המחדל בוטלה' : 'נקבעה ברירת מחדל') }, m.is_default ? 'ביטול ברירת מחדל' : 'ברירת מחדל')
                    : h('button', { class: 'btn sm primary', type: 'button', onclick: () => { if (confirm('לאשר את "' + m.name + '" (גרסה ' + m.version + ')? קוראים יוכלו לבחור בה.')) act('zmanim-method-status', { method: m.pid, status: 'approved' }, 'אושרה'); } }, 'אישור')))),
            h('p', { class: 'hint' }, 'שינוי בהגדרה של שיטה מאושרת יוצר גרסה חדשה ומחזיר אותה לטיוטה — צריך לאשר שוב. הורדות של קוראים שומרות את הגרסה, ויראו "נדרש עדכון".'));
    }, { title: 'שיטות זמני היום', parent: '#/admin' });

    const TYPES = { angle: 'זווית השמש', offset: 'דקות מזמן אחר', hours: 'שעות זמניות' };
    SF.route('#/admin/zmanim/:pid', async (main, { pid }) => {
        const isNew = pid === 'new';
        const { methods } = isNew ? { methods: [] } : await SF.api('zmanim-method-list');
        const m = isNew ? { pid: null, name: '', note: '', version: 0, status: 'draft', test: false, def: { day_end: 'tzeit', zmanim: [
            { key: 'sunrise', name: 'זריחה', type: 'angle', deg: 0.833, dir: 'am' }, { key: 'sunset', name: 'שקיעה', type: 'angle', deg: 0.833, dir: 'pm' },
            { key: 'tzeit', name: 'צאת הכוכבים', type: 'angle', deg: 8.5, dir: 'pm' }] } } : methods.find(x => x.pid === pid);
        if (!m) return SF.go('#/admin/zmanim');
        document.title = (m.name || 'שיטה חדשה') + ' · ספורנו';
        const z = JSON.parse(JSON.stringify(m.def.zmanim));
        let dayEnd = m.def.day_end;
        const name = h('input', { value: m.name, maxlength: 80, 'aria-label': 'שם השיטה' });
        const note = h('input', { value: m.note || '', maxlength: 500, 'aria-label': 'הערה' });
        const rowsBox = h('div', { class: 'zm-edit' });
        const out = h('div');
        const errs = h('div', { class: 'issues', 'aria-live': 'polite' });
        const num = v => (v === '' || v === null || isNaN(+v) ? v : +v);
        const def = () => ({ day_end: dayEnd, zmanim: z });
        function field(label, el) { return h('label', { class: 'f' }, h('span', null, label), el); }
        function inp(o, k, attrs) {
            const el = h('input', Object.assign({ value: o[k] === undefined ? '' : o[k], 'aria-label': k }, attrs || {}));
            el.addEventListener('input', () => { o[k] = attrs && attrs.inputmode ? num(el.value) : el.value; check(); });
            return el;
        }
        function sel(o, k, opts) {
            const el = h('select', { 'aria-label': k }, Object.entries(opts).map(([v, l]) => h('option', { value: v }, l)));
            el.value = o[k] || '';
            el.addEventListener('change', () => { o[k] = el.value; if (k === 'type') draw(); else check(); });
            return el;
        }
        function draw() {
            const keys = Object.fromEntries(z.map(x => [x.key, x.name || x.key]));
            SF.put(rowsBox, z.map((r, i) => h('div', { class: 'zm-edit-row' },
                h('div', { class: 'fields two' }, field('שם', inp(r, 'name', { maxlength: 60 })), field('מזהה (באנגלית)', inp(r, 'key', { dir: 'ltr', maxlength: 32 }))),
                h('div', { class: 'fields two' }, field('סוג', sel(r, 'type', TYPES)),
                    r.type === 'angle' ? [field('מעלות מתחת לאופק', inp(r, 'deg', { inputmode: 'decimal', dir: 'ltr' })), field('מתי', sel(r, 'dir', { am: 'בוקר', pm: 'ערב' }))] : null,
                    r.type === 'offset' ? [field('מ-', sel(r, 'base', keys)), field('דקות (מינוס = לפני)', inp(r, 'min', { inputmode: 'decimal', dir: 'ltr' }))] : null,
                    r.type === 'hours' ? [field('תחילת היום', sel(r, 'from', keys)), field('סוף היום', sel(r, 'to', keys)), field('שעות זמניות', inp(r, 'hours', { inputmode: 'decimal', dir: 'ltr' }))] : null),
                h('div', { class: 'row-btns' },
                    h('button', { class: 'icon-btn', type: 'button', disabled: i === 0, 'aria-label': 'למעלה', onclick: () => { z.splice(i - 1, 0, z.splice(i, 1)[0]); draw(); } }, '↑'),
                    h('button', { class: 'icon-btn', type: 'button', disabled: i === z.length - 1, 'aria-label': 'למטה', onclick: () => { z.splice(i + 1, 0, z.splice(i, 1)[0]); draw(); } }, '↓'),
                    h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'מחיקת הזמן', onclick: () => { z.splice(i, 1); draw(); } }, '🗑️')))),
                h('button', { class: 'btn ghost', type: 'button', onclick: () => { z.push({ key: 'z' + (z.length + 1), name: '', type: 'offset', base: (z[z.length - 1] || {}).key, min: 0 }); draw(); } }, '+ זמן'),
                field('היום מתחלף ב… (צאת הכוכבים)', sel({ get v() { return dayEnd; }, set v(x) { dayEnd = x; } }, 'v', keys)));
            check();
        }
        function check() {
            const e = Cal.methodErrors(def());
            SF.put(errs, e.length ? h('ul', { class: 'issue-list' }, e.map(x => h('li', { class: 'issue bad' }, h('span', { 'aria-hidden': 'true' }, '❌'), h('span', null, x)))) : null);
            if (e.length) { SF.put(out); return; }
            const jer = { lat: 31.769, lon: 35.2163, tz: 'Asia/Jerusalem' };
            const t = Cal.localParts(jer.tz, Date.now());
            SF.put(out, h('h3', { class: 'sec-title' }, 'בדיקה: ירושלים, היום'),
                h('ul', { class: 'list zm-list' }, Cal.zmanim(def(), jer, t.y, t.m, t.d).map(x => h('li', { class: 'zm-row' + (x.ms === null ? ' none' : '') },
                    h('span', { class: 'zm-name' }, x.name + (x.key === dayEnd ? ' · היום מתחלף' : '')),
                    x.ms === null ? h('span', { class: 'zm-none' }, 'לא ניתן לחישוב') : h('b', { class: 'zm-time', dir: 'ltr' }, Cal.hhmm(jer.tz, x.ms))))));
        }
        const save = async () => {
            if (m.status === 'approved' && !confirm('שינוי בהגדרה יחזיר את השיטה לטיוטה (צריך לאשר שוב). להמשיך?')) return;
            try {
                const r = await SF.api('zmanim-method-save', { body: { method: m.pid, name: name.value, note: note.value, def: def(), base: m.version } });
                SF.toast(r.unapproved ? 'נשמר כגרסה ' + r.method.version + ' — השיטה חזרה לטיוטה' : 'נשמר');
                SF.leaveGuard = null;
                SF.go('#/admin/zmanim/' + r.method.pid);
            } catch (e) {
                if (e.code === 'invalid' && e.details) SF.put(errs, h('ul', { class: 'issue-list' }, e.details.errors.map(x => h('li', { class: 'issue bad' }, x))));
                SF.toast(e.message, true);
            }
        };
        const status = st => async () => { try { await SF.api('zmanim-method-status', { body: { method: m.pid, status: st } }); SF.toast('עודכן'); SF.render(); } catch (e) { SF.toast(e.message, true); } };
        SF.put(main,
            h('div', { class: 'summary' }, h('div', { class: 'line' }, h('b', null, m.name || 'שיטה חדשה'),
                m.test ? h('span', { class: 'chip warn' }, 'לבדיקה בלבד') : h('span', { class: 'chip ' + ST[m.status][0] }, ST[m.status][1]), m.version ? h('span', { class: 'chip' }, 'גרסה ' + m.version) : null),
                m.test ? h('div', { class: 'line hint' }, 'שיטת בדיקה — לא הלכתית, לא ניתנת לאישור לציבור. מנהלים יכולים לבחור בה כדי לבדוק את המסכים.') : null),
            h('div', { class: 'fields two' }, field('שם השיטה', name), field('הערה (מוצגת לקורא)', note)),
            h('h3', { class: 'sec-title' }, 'הזמנים'), rowsBox, errs,
            h('div', { class: 'row-btns' }, h('button', { class: 'btn primary', type: 'button', onclick: save }, 'שמירה'),
                !isNew && !m.test && m.status !== 'approved' ? h('button', { class: 'btn ghost', type: 'button', onclick: status('approved') }, 'אישור') : null,
                !isNew && m.status === 'approved' ? h('button', { class: 'btn ghost', type: 'button', onclick: status('draft') }, 'ביטול האישור') : null,
                !isNew && !m.test && m.status !== 'retired' ? h('button', { class: 'btn ghost danger', type: 'button', onclick: status('retired') }, 'להפסיק להציע') : null),
            out);
        draw();
    }, { title: 'שיטה', parent: '#/admin/zmanim' });
})(window.SF);
