/*
 * מסך המפתח (#/dev, §8) — רק ל-is_developer. חמש לשוניות:
 *   📋 מטלות (DevLoop) · 📜 יומן בקשות ושגיאות (DevLoop) · 🧾 יומן פעולות · 🩺 בדיקות ויכולות השרת · ⚙️ שירותי רקע
 */
'use strict';

(function (SF) {
    const { h } = SF;
    const TABS = [
        { key: 'tasks', icon: '📋', label: 'מטלות' },
        { key: 'log', icon: '📜', label: 'יומן' },
        { key: 'audit', icon: '🧾', label: 'פעולות' },
        { key: 'health', icon: '🩺', label: 'בדיקות' },
        { key: 'jobs', icon: '⚙️', label: 'רקע' },
    ];
    const STATE = { ok: ['✅', 'תקין'], warn: ['⚠️', 'אזהרה'], bad: ['❌', 'תקלה'] };

    function tabs(current) {
        return h('div', { class: 'seg-tabs', role: 'tablist', 'aria-label': 'מסך המפתח' }, TABS.map(t =>
            h('button', { type: 'button', role: 'tab', 'aria-selected': String(t.key === current), onclick: () => SF.go('#/dev/' + t.key) },
                h('span', { 'aria-hidden': 'true' }, t.icon), ' ', t.label)));
    }

    SF.route('#/dev', () => SF.go('#/dev/tasks'), { dev: true, title: 'פיתוח' });

    SF.route('#/dev/:tab*', async (main, { tab, rest }) => {
        const t = TABS.find(x => x.key === tab);
        if (!t) return SF.go('#/dev/tasks');
        document.title = t.label + ' · פיתוח · ספורנו';
        const body = h('div');
        main.replaceChildren(tabs(tab), body);
        const arg = (rest || '').replace(/^\//, '');
        if (tab === 'tasks') return DevLoop.show(body, arg);
        if (tab === 'log') return DevLoop.showLog(body, arg);
        return ({ audit, health, jobs })[tab](body);
    }, { dev: true, title: 'פיתוח' });

    // ── 🧾 יומן פעולות ──────────────────────────────────────
    async function audit(el, filters = {}, page = 0) {
        const data = await SF.api('dev-audit', { params: { ...filters, page } });
        const sel = (name, label, opts) => h('label', { class: 'f' }, h('span', null, label),
            h('select', { name, onchange: () => audit(el, readF(), 0) }, h('option', { value: '' }, 'הכול'),
                opts.map(([v, l]) => h('option', { value: v, selected: filters[name] === v }, l))));
        const date = (name, label) => h('label', { class: 'f' }, h('span', null, label),
            h('input', { type: 'date', name, value: filters[name] || '', onchange: () => audit(el, readF(), 0) }));
        const form = h('form', { class: 'filters', onsubmit: e => e.preventDefault() },
            sel('action', 'פעולה', data.actions.map(a => [a, a])),
            sel('actor', 'מבצע', [['system', 'המערכת'], ...data.actors.map(a => [a.pid, a.name])]),
            date('from', 'מתאריך'), date('to', 'עד תאריך'));
        const readF = () => Object.fromEntries([...new FormData(form)].filter(([, v]) => v));
        const row = r => h('li', { class: 'card audit-row', style: 'cursor:default' },
            h('div', { class: 'body' },
                h('div', { class: 'l1' }, h('span', null, r.action), h('span', { class: 't' }, SF.when(r.at))),
                h('div', { class: 'l2' },
                    r.actor || (r.actor_kind === 'system' ? 'המערכת' : r.actor_kind),
                    r.entity_type ? [h('i', { class: 'sep', 'aria-hidden': 'true' }, '·'), r.entity_type + (r.entity_pid ? ' ' + r.entity_pid : '')] : null,
                    r.meta ? [h('i', { class: 'sep', 'aria-hidden': 'true' }, '·'), Object.entries(r.meta).map(([k, v]) => `${k}: ${typeof v === 'object' ? JSON.stringify(v) : v}`).join(', ')] : null)),
            r.req ? h('a', { class: 'btn sm ghost', href: '#/dev/log/' + encodeURIComponent(r.req), 'aria-label': 'היומן הטכני של הבקשה ' + r.req }, '📜 ' + r.req) : null);
        el.replaceChildren(
            h('p', { class: 'hint' }, 'כל פעולה של מנהל או של המערכת, מהחדשה לישנה. מזהה הבקשה פותח את היומן הטכני.'),
            form,
            h('ul', { class: 'list' }, data.rows.length ? data.rows.map(row) : h('li', { class: 'empty' }, 'אין פעולות בסינון הזה')),
            h('div', { class: 'more' },
                page > 0 ? h('button', { class: 'btn ghost', type: 'button', onclick: () => audit(el, filters, page - 1) }, '› חדשות יותר') : null,
                data.more ? h('button', { class: 'btn ghost', type: 'button', onclick: () => audit(el, filters, page + 1) }, 'ישנות יותר ‹') : null));
    }

    // ── 🩺 בדיקות ───────────────────────────────────────────
    async function health(el) {
        const run = async () => {
            list.replaceChildren(h('li', { class: 'loading' }, 'מריץ…'));
            const d = await SF.api('dev-health');
            const n = s => d.checks.filter(c => c.state === s).length;
            sum.replaceChildren(h('div', { class: 'line' }, h('b', null, STATE[d.state][0] + ' ' + STATE[d.state][1]),
                h('span', { class: 'muted' }, `· ${n('ok')} תקין · ${n('warn')} אזהרות · ${n('bad')} תקלות · גרסה ${d.version} · ${SF.when(d.at)}`)));
            list.replaceChildren(...d.checks.map(c => h('li', { class: 'check' },
                h('span', { class: 'st', role: 'img', 'aria-label': STATE[c.state][1] }, STATE[c.state][0]),
                h('span', { class: 'tx' }, h('b', null, c.label, c.s24 ? h('span', { class: 'chip', style: 'margin-inline-start:6px' }, '§24') : null), h('small', null, c.detail)))));
        };
        const sum = h('div', { class: 'summary' }, h('div', { class: 'line muted' }, 'עוד לא הורץ'));
        const list = h('ul', { class: 'list' });
        el.replaceChildren(
            h('p', { class: 'hint' }, 'הבסיס, ויכולות השרת שהפרוסות הבאות צריכות. השורות המסומנות §24 הן התשובה לשאלות התשתית — להעתיק ל-dev/server-report.md.'),
            h('div', { class: 'toolbar' },
                h('button', { class: 'btn primary', type: 'button', onclick: () => run().catch(e => SF.toast(e.message, true)) }, '▶ הרצה'),
                h('button', { class: 'btn ghost', type: 'button', onclick: async () => {
                    try { const r = await SF.api('dev-mail-test', { body: {} }); SF.toast(r.sent ? 'נשלח ל-' + r.to : 'mail() נכשלה', !r.sent); } catch (e) { SF.toast(e.message, true); }
                } }, '✉️ שליחת ניסיון')),
            sum, list);
        await run();
    }

    // ── ⚙️ שירותי רקע ────────────────────────────────────────
    async function jobs(el) {
        const d = await SF.api('dev-jobs');
        const copy = text => h('button', { class: 'icon-btn ghost', type: 'button', 'aria-label': 'העתקה', onclick: async () => {
            try { await navigator.clipboard.writeText(text); SF.toast('הועתק'); } catch (e) { SF.toast('ההעתקה נכשלה — לסמן ולהעתיק ידנית', true); }
        } }, '📋');
        const counts = d.counts || {};
        const act = (label, action, pid) => h('button', { class: 'btn sm ghost', type: 'button', onclick: async () => {
            try { await SF.api(action, { body: { job: pid } }); SF.toast('בוצע'); jobs(el); } catch (e) { SF.toast(e.message, true); }
        } }, label);
        el.replaceChildren(
            h('div', { class: 'summary' },
                h('div', { class: 'line' }, h('b', null, 'המתזמן'), h('span', { class: 'muted' }, '· רץ לאחרונה ' + SF.when(d.cron.last_run_at) + ' · הצליח ' + SF.when(d.cron.last_ok_at))),
                h('div', { class: 'line' }, ['pending', 'running', 'done', 'failed', 'cancelled'].map(s =>
                    h('span', { class: 'chip' + (s === 'failed' && counts[s] ? ' bad' : '') }, ({ pending: 'ממתינות', running: 'רצות', done: 'הסתיימו', failed: 'נכשלו', cancelled: 'בוטלו' })[s] + ' ' + (counts[s] || 0))))),
            h('h3', { class: 'sec-title' }, 'הגדרה ב-cPanel'),
            h('p', { class: 'hint' }, 'ב-cPanel ← Cron Jobs ← כל 5 דקות (', h('bdi', { dir: 'ltr' }, d.cron.every), '), עם הפקודה הזו. בלי מתזמן אין שירותי רקע, ובדיקת "המתזמן" מתריעה.'),
            h('div', { class: 'copy-row' }, h('div', { class: 'code' }, d.cron.command), copy(d.cron.command)),
            h('div', { class: 'toolbar', style: 'margin-top:8px' }, h('button', { class: 'btn ghost danger', type: 'button', onclick: async () => {
                if (!confirm('אסימון חדש? הפקודה הישנה ב-cPanel תפסיק לעבוד, וצריך להחליף אותה.')) return;
                try { await SF.api('dev-cron-rotate', { body: {} }); SF.toast('נוצר אסימון חדש — לעדכן ב-cPanel'); jobs(el); } catch (e) { SF.toast(e.message, true); }
            } }, '🔑 אסימון חדש')),
            h('h3', { class: 'sec-title' }, 'השירותים'),
            h('ul', { class: 'list' }, d.kinds.map(k => h('li', { class: 'card', style: 'cursor:default' }, h('div', { class: 'body' },
                h('div', { class: 'l1' }, h('span', null, k.kind), h('span', { class: 't' }, k.daily ? 'פעם ביום' : '')),
                h('div', { class: 'l2' }, k.label + ' · הצליחה לאחרונה ' + SF.when(k.last_done_at)))))),
            h('h3', { class: 'sec-title' }, 'המשימות האחרונות'),
            h('ul', { class: 'list' }, d.jobs.length ? d.jobs.map(j => h('li', { class: 'card', style: 'cursor:default' },
                h('div', { class: 'body' },
                    h('div', { class: 'l1' }, h('span', null, j.kind), h('span', { class: 't' }, SF.when(j.finished_at || j.run_after))),
                    h('div', { class: 'l2' }, `ניסיון ${j.attempts} מתוך ${j.max_attempts}` + (j.last_error ? ' · ' + j.last_error : ''))),
                h('span', { class: 'chip ' + ({ done: 'ok', failed: 'bad', running: 'warn' })[j.status] }, j.status),
                ['failed', 'cancelled'].includes(j.status) ? act('ניסיון חוזר', 'dev-job-retry', j.pid) : null,
                ['pending', 'failed'].includes(j.status) ? act('ביטול', 'dev-job-cancel', j.pid) : null))
                : h('li', { class: 'empty' }, 'אין משימות עדיין')));
    }
})(window.SF);
