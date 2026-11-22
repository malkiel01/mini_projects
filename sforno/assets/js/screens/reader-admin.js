/*
 * הניהול של הקורא (אפיון 04 §2): המנהגים (customs), רשימת הבסיס של הסידור וקיצורים, ובדיקת שילובי מנהגים.
 *
 *   #/admin/customs                 — רשימה מובנית: מזהה, שם, קבוצה, ברירת מחדל, זמין / לא. אין מחיקה (התוכן מצביע עליהם)
 *   #/admin/siddur[/<edition>]      — רשימת התפילות והקיצורים של מהדורת סידור. "הסרה" = לא זמין (הסדר האישי של הקוראים לא נשבר)
 *   #/admin/checks[/<edition>]      — בוחרים שילוב, רואים את הטקסט שמתקבל, ומסמנים "אושר" — לכל שילוב: הגרסה והתאריך (§21.4)
 * השרת מכריע (lib/access.php, lib/reader.php); כאן רק התצוגה.
 */
'use strict';

(function (SF) {
    const { h } = SF;
    const field = (label, input, wide) => h('label', { class: 'f' + (wide ? ' wide' : '') }, h('span', null, label), input);

    /** גיליון עם "שמירה" — onSave(form) → Promise; שגיאה מוצגת בתוכו. */
    function formSheet(title, fields, onSave) {
        const err = h('p', { class: 'error', role: 'alert', hidden: true });
        const btn = h('button', { class: 'btn primary', type: 'button', onclick: async () => {
            btn.disabled = true; err.hidden = true;
            try { await onSave(); dlg.close(); } catch (x) { err.textContent = x.message; err.hidden = false; } finally { btn.disabled = false; }
        } }, 'שמירה');
        const dlg = SF.sheet(title, h('div', null, h('div', { class: 'fields' }, fields), err), { action: btn });
        return dlg;
    }

    /** מהדורות הסידור (מהניהול — גם כאלה שעוד לא פורסמו). */
    async function siddurEditions() {
        const { titles } = await SF.api('content-titles');
        const out = [];
        for (const t of titles) if (t.kind === 'siddur') for (const e of t.editions) out.push({ title: t, ed: e });
        return out;
    }
    async function pickScreen(main, base, what) {
        const eds = await siddurEditions();
        if (eds.length === 1) return SF.go(base + '/' + eds[0].ed.pid);
        SF.put(main, h('p', { class: 'hint' }, what),
            eds.length ? h('ul', { class: 'list' }, eds.map(x => h('li', null, h('a', { class: 'use-row', href: base + '/' + x.ed.pid },
                h('span', { class: 'use-ic', 'aria-hidden': 'true' }, '📖'), h('span', { class: 'use-tx' }, h('b', { class: 'one' }, x.title.name), h('small', null, x.ed.label + (x.ed.published_n ? ' · גרסה ' + x.ed.published_n : ' · לא פורסם'))),
                h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹'))))) : h('p', { class: 'empty' }, 'אין עדיין כותר מסוג סידור. יוצרים אותו בתוכן.'));
    }

    // ── המנהגים ──────────────────────────────────────────────
    SF.route('#/admin/customs', async main => {
        const draw = groups => {
            const sheet = c => {
                const rule = h('input', { name: 'rule', maxlength: 40, value: c ? c.rule : '', disabled: !!c, dir: 'ltr', pattern: '[a-z][a-z0-9_-]*' });
                const name = h('input', { name: 'name', maxlength: 60, value: c ? c.name : '' });
                const grp = h('input', { name: 'grp', maxlength: 40, value: c ? c.grp : '', disabled: !!c, dir: 'ltr', list: 'sf-grps' });
                const def = h('input', { type: 'checkbox', checked: !!(c && c.default_on) });
                const av = h('input', { type: 'checkbox', checked: !c || c.available });
                formSheet(c ? 'עריכת מנהג' : 'מנהג חדש', [
                    field('שם *', name, true),
                    field('מזהה (לסימון בתוכן)', rule), field('קבוצה', grp),
                    h('datalist', { id: 'sf-grps' }, groups.map(g => h('option', { value: g.grp }))),
                    h('label', { class: 'switch wide' }, def, h('span', null, 'ברירת המחדל בקבוצה')),
                    h('label', { class: 'switch wide' }, av, h('span', null, 'זמין לבחירה')),
                    h('p', { class: 'hint wide' }, 'מנהגים באותה קבוצה סותרים זה את זה — הקורא בוחר אחד. המזהה והקבוצה לא משתנים אחרי היצירה.'),
                ], async () => {
                    draw((await SF.api('custom-save', { body: { create: !c, rule: c ? c.rule : rule.value.trim(), name: name.value, grp: c ? c.grp : grp.value.trim(), default_on: def.checked, available: av.checked } })).groups);
                    SF.toast('נשמר');
                });
                name.focus();
            };
            SF.put(main,
                h('p', { class: 'hint' }, 'הרשימה ש-‹sf-cond rule› בתוכן מצביע עליה. הקורא בוחר מנהג אחד מכל קבוצה (ברירת המחדל — המסומן ⭐).'),
                h('div', { class: 'toolbar' }, h('span', { style: 'flex:1' }), h('button', { class: 'btn primary', type: 'button', onclick: () => sheet(null) }, '+ מנהג')),
                groups.length ? groups.map(g => h('section', { class: 'group' }, h('h3', null, g.grp),
                    h('ul', { class: 'list' }, g.customs.map(c => h('li', null, h('button', { type: 'button', class: 'use-row', onclick: () => sheet(Object.assign({ grp: g.grp }, c)) },
                        h('span', { class: 'use-ic', 'aria-hidden': 'true' }, c.default_on ? '⭐' : '🔀'),
                        h('span', { class: 'use-tx' }, h('b', { class: 'one' }, c.name), h('small', { dir: 'ltr' }, c.rule)),
                        c.sample ? h('span', { class: 'chip' }, 'דוגמה') : null,
                        c.available ? null : h('span', { class: 'chip warn' }, 'לא זמין'),
                        h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹'))))))) : h('p', { class: 'empty' }, 'אין מנהגים'));
        };
        draw((await SF.api('customs-list')).groups);
    }, { title: 'מנהגים', parent: '#/admin' });

    // ── רשימת הסידור ─────────────────────────────────────────
    SF.route('#/admin/siddur', main => pickScreen(main, '#/admin/siddur', 'רשימת התפילות והקיצורים — לאיזו מהדורה?'), { title: 'רשימת הסידור', parent: '#/admin' });
    SF.route('#/admin/siddur/:edition', async (main, { edition }) => {
        const KIND = { prayer: 'תפילה ברשימה', shortcut: 'קיצור (אריח)' };
        const draw = d => {
            const label = sd => { const t = d.toc.find(x => x.sid === sd); return t ? t.title : sd ? '(יעד שלא נמצא)' : 'תחילת המהדורה'; };
            const sheet = it => {
                const name = h('input', { maxlength: 80, value: it ? it.name : '' });
                const kind = h('select', null, Object.entries(KIND).map(([k, v]) => h('option', { value: k, selected: (it ? it.kind : 'prayer') === k }, v)));
                const sid = h('select', null, h('option', { value: '' }, 'תחילת המהדורה'), d.toc.map(t => h('option', { value: t.sid, selected: !!it && it.sid === t.sid }, (t.kind === 'anchor' ? '📍 ' : '§ ') + (t.title || t.sid))));
                formSheet(it ? 'עריכה' : 'פריט חדש', [field('שם *', name, true), field('סוג', kind), field('יעד', sid)], async () => {
                    draw(await SF.api('siddur-item-save', { body: { edition: it ? null : edition, item: it ? it.pid : null, name: name.value, kind: kind.value, sid: sid.value } }));
                    SF.toast('נשמר');
                });
                name.focus();
            };
            const api = async (action, body) => { try { draw(await SF.api(action, { body })); } catch (x) { SF.toast(x.message, true); } };
            SF.put(main,
                h('p', { class: 'hint' }, 'מה שהקורא רואה בפתיחת הסידור. הסדר כאן — לקורא חדש; קורא שסידר לעצמו שומר על הסדר שלו: פריט חדש נוסף אצלו בסוף, ופריט שהוסר מסומן אצלו "לא זמין".'),
                !d.toc.length ? h('p', { class: 'notice warn' }, 'למהדורה עוד אין גרסה שפורסמה — אפשר להוסיף רק "תחילת המהדורה".') : null,
                h('div', { class: 'toolbar' }, h('b', { style: 'flex:1' }, d.edition.label), h('button', { class: 'btn primary', type: 'button', onclick: () => sheet(null) }, '+ תפילה')),
                h('ul', { class: 'list' }, d.items.length ? d.items.map((it, i) => h('li', { class: 'card' + (it.available ? '' : ' off') },
                    h('button', { type: 'button', class: 'body', onclick: () => sheet(it) }, h('div', { class: 'l1' }, h('b', { class: 'one' }, it.name),
                        h('span', { class: 'chip' }, it.kind === 'shortcut' ? 'קיצור' : 'תפילה'), it.available ? null : h('span', { class: 'chip warn' }, 'לא זמין')),
                        h('div', { class: 'l2 one' }, '→ ' + label(it.sid))),
                    h('button', { class: 'icon-btn', type: 'button', disabled: i === 0, 'aria-label': 'למעלה: ' + it.name, onclick: () => api('siddur-item-move', { item: it.pid, dir: 'up' }) }, '↑'),
                    h('button', { class: 'icon-btn', type: 'button', disabled: i === d.items.length - 1, 'aria-label': 'למטה: ' + it.name, onclick: () => api('siddur-item-move', { item: it.pid, dir: 'down' }) }, '↓'),
                    h('button', { class: 'icon-btn', type: 'button', 'aria-label': (it.available ? 'הסרה (לא זמין): ' : 'החזרה: ') + it.name, onclick: () => api('siddur-item-available', { item: it.pid, on: !it.available }) }, it.available ? '🚫' : '↩️'))) :
                    h('li', { class: 'empty' }, 'הרשימה ריקה')));
        };
        draw(await SF.api('siddur-items', { params: { edition } }));
    }, { title: 'רשימת הסידור', parent: '#/admin/siddur' });

    // ── בדיקת שילובי מנהגים ──────────────────────────────────
    SF.route('#/admin/checks', main => pickScreen(main, '#/admin/checks', 'בדיקת מנהגים — לאיזו מהדורה?'), { title: 'בדיקת מנהגים', parent: '#/admin' });
    SF.route('#/admin/checks/:edition', async (main, { edition }) => {
        let d = await SF.api('customs-checks', { params: { edition } });
        let sel = null;
        const preview = h('div', { class: 'ck-preview' });
        const list = h('ul', { class: 'list' });
        const summary = h('div', { class: 'summary' });
        const nameOf = (grp, rule) => { const g = d.groups.find(x => x.grp === grp); const c = g && g.customs.find(x => x.rule === rule); return c ? c.name : rule; };
        const comboName = c => Object.keys(c.combo).map(g => nameOf(g, c.combo[g])).join(' · ');
        function draw() {
            const ok = d.combos.filter(c => c.approved && c.approved.current).length;
            SF.put(summary, h('div', { class: 'line' }, h('span', null, 'אושרו ', h('b', null, String(ok)), ' מתוך ', String(d.combos.length), ' שילובים'),
                d.published ? h('span', { class: 'chip' }, 'גרסה ' + d.published.n) : h('span', { class: 'chip warn' }, 'אין גרסה שפורסמה')),
                h('div', { class: 'track' }, h('span', { style: 'width:' + (d.combos.length ? Math.round(100 * ok / d.combos.length) : 0) + '%' })));
            list.replaceChildren(...d.combos.map(c => h('li', null, h('button', { type: 'button', class: 'use-row', 'aria-pressed': String(!!sel && sel.key === c.key), onclick: () => { sel = c; show(); draw(); } },
                h('span', { class: 'use-ic', 'aria-hidden': 'true' }, c.approved && c.approved.current ? '✅' : c.approved ? '🕓' : '⬜'),
                h('span', { class: 'use-tx' }, h('b', { class: 'one' }, comboName(c)),
                    h('small', null, c.approved ? 'אושר לגרסה ' + c.approved.n + ' · ' + SF.when(c.approved.at) + (c.approved.by ? ' · ' + c.approved.by : '') + (c.approved.current ? '' : ' — לבדוק שוב בגרסה הנוכחית') : 'לא נבדק')),
                h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹')))));
            if (!d.combos.length) list.replaceChildren(h('li', { class: 'empty' }, 'אין מנהגים זמינים'));
        }
        function show() {
            if (!sel || !d.published) { preview.replaceChildren(d.published ? h('p', { class: 'hint' }, 'בוחרים שילוב ברשימה כדי לראות את הטקסט שמתקבל.') : h('p', { class: 'empty' }, 'אין גרסה שפורסמה')); return; }
            const conds = {};
            for (const g of d.groups) for (const c of g.customs) conds[c.rule] = sel.combo[g.grp] === c.rule;
            const docEl = SFRender.render(d.published.html, { conds, condDefault: false, filesBase: 'api.php?action=content-file&path=' });
            SFRender.bind(docEl);
            const approve = h('button', { class: 'btn primary', type: 'button', onclick: async () => {
                approve.disabled = true;
                try {
                    d = await SF.api('customs-check-approve', { body: { edition, combo: sel.combo, revision: d.published.pid } });
                    sel = d.combos.find(c => c.key === sel.key);
                    SF.toast('אושר'); draw(); show();
                } catch (x) { SF.toast(x.message, true); approve.disabled = false; }
            } }, sel.approved && sel.approved.current ? '✅ אושר — לאשר שוב' : '✅ אושר');
            SF.put(preview, h('div', { class: 'toolbar' }, h('b', { style: 'flex:1' }, comboName(sel)), approve),
                h('p', { class: 'hint' }, 'הטקסט שהקורא יראה עם השילוב הזה (גרסה ' + d.published.n + '). לקרוא, להשוות לטקסט שסופק, ולסמן "אושר".'),
                h('div', { class: 'ck-doc' }, docEl));
        }
        main.replaceChildren(h('p', { class: 'hint' }, 'כל שילוב של מנהג אחד מכל קבוצה. האישור נשמר עם הגרסה והתאריך; גרסה חדשה — בודקים שוב. הרשימה המאושרת היא ראיית הקבלה.'),
            summary, h('div', { class: 'ck-grid' }, list, preview));
        draw();
        show();
    }, { title: 'בדיקת מנהגים', parent: '#/admin/checks' });

})(window.SF);
