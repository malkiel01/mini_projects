/*
 * ניהול תפעולי (אפיון 10):
 *   #/admin/ops            לוח הבקרה — מה דורש תשומת לב (החנות, המייל, התזכורות, המתזמן, הגיבוי), מה פורסם, דיווחים, היום
 *   #/admin/reports[/pid]  הדיווחים: רשימה לפי מצב, ודיווח אחד — מצב, הערה פנימית, קפיצה לקטע בקורא ובעורך
 *   #/admin/metrics        המדדים לפי יום, והפתיחות לפי כותר ומקטע עליון — ספירות בלבד
 *   #/admin/support        קורא אחד, לתמיכה: כניסות, מכשירים, מצב הסנכרון ושגיאות — **בלי** תוכן אישי
 *   #/contact              טופס קשר לקוראים, וקישור לוואטסאפ / מייל אם הוגדרו (📦)
 * ובשביל הקורא: SF.reportSheet(ctx) — "דיווח על טעות" מתוך קטע; SF.metricsOpen(edition, sec) — מונה פתיחות.
 * **מה שנשלח בדיווח:** רק kind, category, edition, revision, sid, text, contact (טסט ב-n-ops בודק את הקריאה). לא הערות,
 * לא שמות לתפילה, לא המייל של החשבון.
 */
'use strict';

(function (SF) {
    const { h } = SF;
    const LEVEL = { bad: ['❌', 'bad'], warn: ['⚠️', 'warn'], info: ['ℹ️', ''] };
    const STATUS = { new: ['חדש', 'warn'], working: ['בטיפול', ''], closed: ['נסגר', 'ok'] };
    const card = (kids, extra) => h('li', { class: 'card', style: 'cursor:default' }, kids, extra || null);
    const body = (l1, l2, t) => h('div', { class: 'body' }, h('div', { class: 'l1' }, h('span', null, l1), t ? h('span', { class: 't' }, t) : null), l2 ? h('div', { class: 'l2' }, l2) : null);
    const go = (href, ic, b, sm) => h('li', null, h('a', { class: 'use-row', href }, h('span', { class: 'use-ic', 'aria-hidden': 'true' }, ic),
        h('span', { class: 'use-tx' }, h('b', null, b), sm ? h('small', null, sm) : null), h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹')));
    const mb = n => (n / 1048576).toFixed(1) + 'MB';

    // ── לוח הבקרה ───────────────────────────────────────────
    SF.route('#/admin/ops', async main => {
        const d = await SF.api('ops-dashboard');
        const open = (d.reports.counts.new || 0) + (d.reports.counts.working || 0);
        const rem = d.reminders.day || {};
        const sum = k => Object.values(rem).reduce((a, r) => a + (r[k] || 0), 0);
        const st = d.store.status || {};
        SF.put(main,
            h('div', { class: 'summary' },
                h('div', { class: 'line' }, h('b', null, d.alerts.some(a => a.level === 'bad') ? '❌ יש תקלות' : d.alerts.length ? '⚠️ יש מה לבדוק' : '✅ הכול תקין'),
                    h('span', { class: 'muted' }, '· היום: ' + d.today.readers + ' קוראים · ' + d.today.guests + ' אורחים · ' + d.today.opens + ' פתיחות'))),
            d.alerts.length ? h('ul', { class: 'list', 'aria-label': 'מה דורש תשומת לב' }, d.alerts.map(a => card([
                h('span', { class: 'use-ic', role: 'img', 'aria-label': a.level === 'bad' ? 'תקלה' : 'אזהרה' }, LEVEL[a.level][0]), body(a.text)],
                a.href && (SF.isDev() || !a.href.startsWith('#/dev')) ? h('a', { class: 'btn sm ghost', href: a.href }, 'לפרטים') : null))) : null,
            h('ul', { class: 'list' },
                go('#/admin/reports', '📮', 'דיווחים' + (open ? ' · ' + open + ' פתוחים' : ''), 'טעויות בתוכן ופניות מטופס הקשר'),
                go('#/admin/metrics', '📊', 'מדדים', 'משתמשים, פתיחות, החנות, תזכורות ושגיאות — לפי יום'),
                go('#/admin/support', '🧑‍💼', 'תמיכה בקורא', 'כניסות, מכשירים וסנכרון — בלי תוכן אישי'),
                SF.isDev() ? go('#/dev/backup', '💾', 'גיבויים', d.backup ? 'אחרון: ' + SF.when(d.backup.created_at) + ' · ' + mb(d.backup.bytes) : 'עוד אין גיבוי') : null),
            h('h3', { class: 'sec-title' }, 'מצב השירותים'),
            h('ul', { class: 'list' },
                card([h('span', { class: 'use-ic', 'aria-hidden': 'true' }, '🛒'), body('החנות', !d.store.ready ? 'עוד אין חיבור' + (st.sample ? ' · נתוני דוגמה' : '')
                    : 'עודכן ' + SF.when(st.verified_at) + (d.store.run ? ' · סנכרון אחרון ' + ({ ok: 'הצליח', failed: 'נכשל', running: 'רץ' })[d.store.run.status] : ''))],
                h('span', { class: 'chip ' + (d.store.run && d.store.run.status === 'failed' ? 'bad' : st.stale ? 'warn' : d.store.ready ? 'ok' : '') }, d.store.run && d.store.run.status === 'failed' ? 'נכשל' : st.stale ? 'ישן' : d.store.ready ? 'תקין' : '—')),
                card([h('span', { class: 'use-ic', 'aria-hidden': 'true' }, '⏱️'), body('המתזמן', 'הצליח ' + SF.when(d.cron.last_ok_at) + ' · ' + d.cron.pending + ' ממתינות' + (d.cron.failed_jobs ? ' · ' + d.cron.failed_jobs + ' נכשלו' : ''))],
                    h('span', { class: 'chip ' + (d.cron.failed_jobs ? 'bad' : d.cron.last_ok_at ? 'ok' : 'warn') }, d.cron.failed_jobs ? 'כשלים' : d.cron.last_ok_at ? 'רץ' : 'לא רץ')),
                card([h('span', { class: 'use-ic', 'aria-hidden': 'true' }, '⏰'), body('תזכורות — 24 שעות', sum('sent') + ' נשלחו · ' + (sum('failed') + sum('gone')) + ' נכשלו · ' + d.reminders.pending + ' ממתינות')],
                    h('span', { class: 'chip ' + (sum('failed') + sum('gone') ? 'bad' : 'ok') }, sum('failed') + sum('gone') ? 'כשלים' : 'תקין')),
                card([h('span', { class: 'use-ic', 'aria-hidden': 'true' }, '💾'), body('גיבוי', d.backup ? SF.when(d.backup.created_at) + ' · ' + mb(d.backup.bytes) + ' · בדיקת שחזור: ' + (d.backup.verified_at ? SF.when(d.backup.verified_at) : 'עוד לא') : 'עוד אין')],
                    h('span', { class: 'chip ' + (!d.backup ? 'warn' : d.backup.verify_ok === false ? 'bad' : 'ok') }, !d.backup ? 'אין' : d.backup.verify_ok === false ? 'נכשל' : 'תקין')),
                card([h('span', { class: 'use-ic', 'aria-hidden': 'true' }, '🩺'), body('בדיקות התקינות', d.health.issues.length ? d.health.issues.map(i => i.label).join(' · ') : 'הכול תקין', SF.when(d.health.at))],
                    SF.isDev() ? h('a', { class: 'btn sm ghost', href: '#/dev/health' }, 'לבדיקות') : h('span', { class: 'chip ' + (d.health.state === 'ok' ? 'ok' : d.health.state) }, d.health.state === 'ok' ? 'תקין' : d.health.issues.length))),
            d.reports.latest.length ? [h('h3', { class: 'sec-title' }, 'דיווחים פתוחים'), h('ul', { class: 'list' }, d.reports.latest.map(reportRow))] : null,
            h('h3', { class: 'sec-title' }, 'פורסם לאחרונה'),
            h('ul', { class: 'list' }, d.published.length ? d.published.map(p => h('li', null, h('a', { class: 'use-row', href: '#/admin/content/e/' + p.edition },
                h('span', { class: 'use-ic', 'aria-hidden': 'true' }, '📗'), h('span', { class: 'use-tx' }, h('b', null, p.title + ' · ' + p.edition_label),
                    h('small', null, 'גרסה ' + p.n + ' · ' + SF.when(p.at) + (p.by ? ' · ' + p.by : ''))), h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹'))))
                : h('li', { class: 'empty' }, 'עוד לא פורסם דבר')));
    }, { title: 'לוח הבקרה', parent: '#/admin' });

    // ── הדיווחים ────────────────────────────────────────────
    function reportRow(r) {
        const w = r.where;
        return h('li', null, h('a', { class: 'use-row', href: '#/admin/reports/' + r.pid },
            h('span', { class: 'use-ic', 'aria-hidden': 'true' }, r.kind === 'content' ? '✏️' : '✉️'),
            h('span', { class: 'use-tx' }, h('b', { class: 'one' }, r.category_label + (w && w.title ? ' · ' + w.title : '')),
                h('small', { class: 'one' }, (r.body || (w ? 'קטע ' + (w.sid_title || w.sid) : '')) + ' · ' + SF.when(r.created_at))),
            h('span', { class: 'chip ' + STATUS[r.status][1] }, STATUS[r.status][0])));
    }

    SF.route('#/admin/reports', async main => {
        const tabs = [['open', 'פתוחים'], ['new', 'חדשים'], ['working', 'בטיפול'], ['closed', 'נסגרו'], ['all', 'הכול']];
        let cur = 'open';
        const list = h('ul', { class: 'list' });
        const bar = h('div', { class: 'seg-tabs', role: 'tablist', 'aria-label': 'מצב' });
        async function load() {
            SF.put(bar, tabs.map(([k, l]) => h('button', { type: 'button', role: 'tab', 'aria-selected': String(k === cur), onclick: () => { cur = k; load(); } }, l)));
            const d = await SF.api('reports-list', { params: { status: cur } });
            SF.put(list, d.reports.length ? d.reports.map(reportRow) : h('li', { class: 'empty' }, 'אין דיווחים כאן'));
        }
        main.replaceChildren(h('p', { class: 'hint' }, 'דיווחים מבחוץ: טעויות בתוכן (עם המיקום המדויק) ופניות מטופס הקשר. תקלות תוכנה — ב-🐞 של המפתח.'), bar, list);
        await load();
    }, { title: 'דיווחים', parent: '#/admin/ops' });

    SF.route('#/admin/reports/:pid', async (main, { pid }) => {
        const { report: r, statuses } = await SF.api('report-get', { params: { report: pid } });
        const w = r.where;
        const note = h('textarea', { rows: 3, maxlength: 1000, 'aria-label': 'הערה פנימית' }, r.staff_note || '');
        const set = async (status, btn) => {
            btn.disabled = true;
            try { await SF.api('report-set', { body: { report: r.pid, status, note: note.value } }); SF.toast('נשמר'); SF.render(); }
            catch (e) { SF.toast(e.message, true); btn.disabled = false; }
        };
        SF.put(main,
            h('div', { class: 'summary' },
                h('div', { class: 'line' }, h('b', null, (r.kind === 'content' ? 'טעות בתוכן · ' : 'פנייה · ') + r.category_label), h('span', { class: 'chip ' + STATUS[r.status][1] }, STATUS[r.status][0])),
                h('div', { class: 'line muted' }, 'התקבל ' + SF.when(r.created_at) + (r.notified_at ? ' · נשלחה התראה' : '') + (r.closed_at ? ' · נסגר ' + SF.when(r.closed_at) : ''))),
            w ? h('section', null, h('h3', { class: 'sec-title' }, 'המיקום'),
                h('p', null, [w.title, w.edition_label, w.revision_n ? 'גרסה ' + w.revision_n : null, 'קטע ' + (w.sid_title || w.sid)].filter(Boolean).join(' · ')),
                h('p', { class: 'muted', dir: 'ltr', style: 'text-align:end' }, 'edition ' + w.edition + ' · revision ' + (w.revision || '—') + ' · sid ' + w.sid),
                h('div', { class: 'row-btns wrap' }, w.reader_href ? h('a', { class: 'btn ghost', href: w.reader_href }, '📖 בקורא') : null,
                    w.editor_href ? h('a', { class: 'btn ghost', href: w.editor_href }, '✏️ בעורך') : null)) : null,
            h('h3', { class: 'sec-title' }, 'מה נכתב'),
            h('p', { style: 'white-space:pre-wrap' }, r.body || h('span', { class: 'muted' }, '(בלי טקסט)')),
            r.contact ? [h('h3', { class: 'sec-title' }, 'לחזרה'), h('p', { dir: 'auto' }, r.contact)] : null,
            h('h3', { class: 'sec-title' }, 'טיפול'),
            h('label', { class: 'f wide' }, h('span', null, 'הערה פנימית (לא נשלחת)'), note),
            h('div', { class: 'row-btns wrap', style: 'margin-top:8px' }, Object.entries(statuses).map(([k, l]) =>
                h('button', { class: 'btn ' + (k === r.status ? 'primary' : 'ghost'), type: 'button', onclick: e => set(k, e.currentTarget) }, k === r.status ? 'שמירה (' + l + ')' : l))));
    }, { title: 'דיווח', parent: '#/admin/reports' });

    // ── מדדים ───────────────────────────────────────────────
    const COLS = [['reader', 'קוראים'], ['guest', 'אורחים'], ['login', 'כניסות'], ['register', 'נרשמו'], ['open', 'פתיחות'], ['store_view', 'צפיות במוצר'],
        ['store_out', 'מעברים לחנות'], ['rem_sent', 'תזכורות'], ['rem_fail', 'תזכורות שנכשלו'], ['sync_err', 'שגיאות סנכרון'], ['store_err', 'שגיאות חנות'],
        ['mail_fail', 'מיילים שנכשלו'], ['report', 'דיווחים']];
    SF.route('#/admin/metrics', async main => {
        let days = 30;
        const out = h('div');
        async function load() {
            const d = await SF.api('ops-metrics', { params: { days } });
            const tot = Object.fromEntries(COLS.map(([k]) => [k, d.by_day.reduce((a, r) => a + (r[k] || 0), 0)]));
            SF.put(out,
                h('div', { class: 'seg-tabs', role: 'tablist', 'aria-label': 'תקופה' }, [7, 30, 90, 365].map(n =>
                    h('button', { type: 'button', role: 'tab', 'aria-selected': String(n === days), onclick: () => { days = n; load(); } }, n === 365 ? 'שנה' : n + ' ימים'))),
                h('div', { class: 'summary' }, h('div', { class: 'line' }, COLS.filter(([k]) => tot[k]).map(([k, l]) =>
                    h('span', { class: 'chip' + (/fail|err/.test(k) ? ' bad' : '') }, l + ' ' + tot[k].toLocaleString('he-IL'))))),
                h('div', { class: 'tbl-wrap' }, h('table', { class: 'tbl' },
                    h('thead', null, h('tr', null, h('th', { scope: 'col' }, 'יום'), COLS.map(([, l]) => h('th', { scope: 'col' }, l)))),
                    h('tbody', null, d.by_day.length ? d.by_day.map(r => h('tr', null, h('th', { scope: 'row' }, r.day.slice(5).split('-').reverse().join('.')),
                        COLS.map(([k]) => h('td', null, r[k] ? String(r[k]) : '')))) : h('tr', null, h('td', { colspan: COLS.length + 1 }, 'אין נתונים בתקופה'))))),
                h('h3', { class: 'sec-title' }, 'פתיחות לפי כותר'),
                h('ul', { class: 'list' }, d.titles.length ? d.titles.map(t => card(body((t.title || t.edition) + (t.edition_label ? ' · ' + t.edition_label : ''), null, String(t.n)))) : h('li', { class: 'empty' }, 'אין')),
                h('h3', { class: 'sec-title' }, 'פתיחות לפי מקטע עליון'),
                h('ul', { class: 'list' }, d.sections.length ? d.sections.map(s => card(body(s.title || s.sid, null, String(s.n)))) : h('li', { class: 'empty' }, 'אין')),
                h('h3', { class: 'sec-title' }, 'החנות — לפי מוצר'),
                h('ul', { class: 'list' }, d.products.length ? d.products.map(p => card(body(p.name || '#' + p.product, p.view + ' צפיות · ' + p.out + ' מעברים לחנות'))) : h('li', { class: 'empty' }, 'אין')),
                h('p', { class: 'hint' }, 'ספירות בלבד, לפי יום (UTC). אורח נספר לפי מזהה אנונימי שהמכשיר מחליף כל יום. המצרפיים נשמרים לתמיד; הפעילות לפי קורא — 90 יום.'));
        }
        main.replaceChildren(out);
        await load();
    }, { title: 'מדדים', parent: '#/admin/ops' });

    // ── תמיכה בקורא ─────────────────────────────────────────
    SF.route('#/admin/support', main => {
        const q = h('input', { type: 'search', dir: 'auto', placeholder: 'מייל או מזהה של קורא', 'aria-label': 'מייל או מזהה', autocomplete: 'off' });
        const out = h('div');
        const KINDS = { pref: 'הגדרות', custom: 'מנהגים', bookmark: 'סימניות', position: 'מקומות', highlight: 'הדגשות', note: 'הערות', favorite: 'מועדפים',
            tehillim_list: 'רשימות תהילים', prayer_name: 'שמות לתפילה', reminder: 'תזכורות', store_favorite: 'מועדפים בחנות' };
        const find = async e => {
            e.preventDefault();
            out.replaceChildren(h('p', { class: 'loading' }, 'מחפש…'));
            try {
                const d = await SF.api('ops-reader', { params: { q: q.value.trim() } });
                const r = d.reader;
                SF.put(out,
                    h('div', { class: 'summary' },
                        h('div', { class: 'line' }, h('b', null, r.name || '(נמחק)'), h('span', { class: 'chip ' + (r.status === 'active' ? 'ok' : 'warn') },
                            ({ active: 'פעיל', deleted: 'ביקש מחיקה', purged: 'נמחק לגמרי' })[r.status] || r.status)),
                        h('div', { class: 'line muted' }, [r.email, r.email_verified ? 'מייל מאומת' : 'מייל לא מאומת', r.google ? 'Google' : null, r.password ? 'סיסמה' : null].filter(Boolean).join(' · ')),
                        h('div', { class: 'line muted' }, 'נרשם ' + SF.when(r.created_at) + (r.locked_until ? ' · נעול עד ' + SF.when(r.locked_until) : '') + (r.purge_at ? ' · יימחק לגמרי ' + SF.when(r.purge_at) : ''))),
                    h('h3', { class: 'sec-title' }, 'הסנכרון'),
                    h('p', null, 'מונה ' + d.sync.rev + ' · עודכן ' + SF.when(d.sync.last_at) + ' · ' + mb(d.sync.bytes) + ' · מכשירי התראות ' + d.push + (d.reminder_failures_30d ? ' · ' + d.reminder_failures_30d + ' תזכורות נכשלו ב-30 יום' : '')),
                    h('div', { class: 'line' }, Object.entries(d.sync.kinds).map(([k, n]) => h('span', { class: 'chip' }, (KINDS[k] || k) + ' ' + n))),
                    h('h3', { class: 'sec-title' }, 'מכשירים'),
                    h('ul', { class: 'list' }, d.devices.length ? d.devices.map(x => card(body(x.label, 'נכנס ' + SF.when(x.created_at) + ' · נראה ' + SF.when(x.last_seen_at)),
                        h('span', { class: 'chip ' + (x.revoked_at ? '' : 'ok') }, x.revoked_at ? 'בוטל' : 'פעיל'))) : h('li', { class: 'empty' }, 'אין')),
                    h('h3', { class: 'sec-title' }, 'פעילות — 90 יום'),
                    h('ul', { class: 'list' }, d.activity.length ? d.activity.map(a => card(body(a.day, [a.login ? a.login + ' כניסות' : null, a.open ? a.open + ' פתיחות' : null,
                        a.sync ? a.sync + ' סנכרונים' : null, a.sync_err ? a.sync_err + ' שגיאות סנכרון' : null].filter(Boolean).join(' · ')))) : h('li', { class: 'empty' }, 'אין פעילות')),
                    h('p', { class: 'hint' }, 'ספירות בלבד: תוכן ההערות, השמות לתפילה וכל פריט אישי לא מוצגים כאן (§3, §19). הצפייה נרשמת ביומן הפעולות.'));
            } catch (err) {
                out.replaceChildren(h('p', { class: 'hint' }, err.message));
            }
        };
        main.replaceChildren(h('form', { class: 'toolbar', onsubmit: find }, q, h('button', { class: 'btn primary', type: 'submit' }, 'חיפוש')), out);
        q.focus();
    }, { title: 'תמיכה בקורא', parent: '#/admin/ops' });

    // ── הדיווח מתוך הקורא, וטופס הקשר ───────────────────────
    let support = null;
    const supportInfo = () => support || (support = SF.api('read-support').catch(e => { support = null; throw e; }));

    /** הגיליון "דיווח על טעות". ctx = {edition, revision, sid, where} — מזהים בלבד; where — מה שמוצג למשתמש. */
    SF.reportSheet = async function reportSheet(ctx) {
        let info;
        try { info = await supportInfo(); } catch (e) { SF.toast(SF.isOffline && SF.isOffline() ? 'אין חיבור — אפשר לדווח כשהרשת תחזור' : e.message, true); return; }
        const cat = h('select', { 'aria-label': 'מה הבעיה' }, Object.entries(info.categories.content).map(([k, l]) => h('option', { value: k }, l)));
        const text = h('textarea', { rows: 4, maxlength: 2000, 'aria-label': 'מה צריך לתקן', placeholder: 'למשל: חסר ניקוד במילה…' });
        const contact = h('input', { dir: 'auto', maxlength: 200, 'aria-label': 'לחזרה אליי', placeholder: 'לא חובה: מייל או טלפון' });
        const send = h('button', { class: 'btn primary', type: 'button', onclick: async () => {
            send.disabled = true;
            try {
                await SF.api('report-create', { body: { kind: 'content', category: cat.value, edition: ctx.edition, revision: ctx.revision || null, sid: ctx.sid,
                    text: text.value, contact: contact.value } });
                dlg.close();
                SF.toast('תודה! הדיווח נשלח');
            } catch (e) { SF.toast(e.message, true); send.disabled = false; }
        } }, 'שליחה');
        const dlg = SF.sheet('דיווח על טעות', h('div', { class: 'fields' },
            h('p', { class: 'hint wide' }, 'המקום מצורף לבד' + (ctx.where ? ': ' + ctx.where : '') + '. ההערות, הסימונים והחשבון שלך לא נשלחים.'),
            h('label', { class: 'f wide' }, h('span', null, 'מה הבעיה'), cat),
            h('label', { class: 'f wide' }, h('span', null, 'פירוט'), text),
            h('label', { class: 'f wide' }, h('span', null, 'לחזרה אליי (לא חובה)'), contact)), { action: send });
        text.focus();
    };

    /** מונה פתיחות (מצרפי). אורח — מזהה אנונימי שמתחלף כל יום (לא נשלח שום דבר אחר). בלי רשת — לא נספר. */
    SF.metricsOpen = async function metricsOpen(edition, sec) {
        try {
            const day = new Date().toISOString().slice(0, 10);
            let a = await SF.store.get('anon:day', null);
            if (!a || a.day !== day) {
                const b = crypto.getRandomValues(new Uint8Array(20));
                a = { day, id: [...b].map(x => (x % 36).toString(36)).join('') };
                await SF.store.setRaw('anon:day', a);
            }
            await SF.api('metrics-open', { body: { edition, sec: sec || null, anon: a.id } });
        } catch (e) { /* מדד — לא מפריע לקריאה */ }
    };

    SF.route('#/contact', async main => {
        const info = await supportInfo();
        const cat = h('select', { 'aria-label': 'נושא' }, Object.entries(info.categories.contact).map(([k, l]) => h('option', { value: k }, l)));
        const text = h('textarea', { rows: 5, maxlength: 2000, 'aria-label': 'ההודעה', required: true });
        const contact = h('input', { dir: 'auto', maxlength: 200, 'aria-label': 'לחזרה אליי', placeholder: 'מייל או טלפון — אם תרצו תשובה' });
        const send = h('button', { class: 'btn primary', type: 'submit' }, 'שליחה');
        const form = h('form', { class: 'fields', onsubmit: async e => {
            e.preventDefault();
            if (!text.value.trim()) { SF.toast('לכתוב את ההודעה', true); return; }
            send.disabled = true;
            try {
                await SF.api('report-create', { body: { kind: 'contact', category: cat.value, text: text.value, contact: contact.value } });
                SF.put(main, h('section', { class: 'soon' }, h('div', { class: 'ic', 'aria-hidden': 'true' }, '✉️'), h('h2', null, 'תודה! ההודעה נשלחה'),
                    h('a', { class: 'btn ghost', href: '#/' }, 'למסך הבית')));
            } catch (err) { SF.toast(err.message, true); send.disabled = false; }
        } },
            h('label', { class: 'f wide' }, h('span', null, 'נושא'), cat),
            h('label', { class: 'f wide' }, h('span', null, 'ההודעה'), text),
            h('label', { class: 'f wide' }, h('span', null, 'לחזרה אליי (לא חובה)'), contact),
            h('div', { class: 'wide' }, send));
        SF.put(main,
            info.whatsapp || info.email ? h('div', { class: 'row-btns wrap', style: 'margin-bottom:12px' },
                info.whatsapp ? h('a', { class: 'btn ghost', href: 'https://wa.me/' + encodeURIComponent(info.whatsapp), target: '_blank', rel: 'noopener noreferrer' }, '💬 וואטסאפ') : null,
                info.email ? h('a', { class: 'btn ghost', href: 'mailto:' + info.email }, '✉️ ' + info.email) : null) : null,
            h('p', { class: 'hint' }, 'טעות בתוכן? עדיף מתוך הקורא: לסמן את הקטע ← ⚠️ — המיקום יצורף לבד.'),
            form);
    }, { title: 'יצירת קשר', parent: '#/' });
})(window.SF);
