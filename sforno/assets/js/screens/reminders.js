/*
 * התזכורות (אפיון 08) — #/reminders. הקרובות (48 שעות, עם "מושתק" בשבת וחג ו"ליומן"), התזכורות שלי, הערוצים עם המצב
 * האמיתי שלהם, ושבת וחג (השיטה, וההארכה של המשתמש — רק מאריכה). העורך — גיליון.
 *
 *   Push    — פעיל / חסר אישור / חסום / ב-iPhone רק כשהאפליקציה מותקנת / לא זמין בדפדפן. נשלח מהשרת (cron) — אם המתזמן
 *             לא רץ, המסך אומר זאת.
 *   מייל    — רק לחשבון עם כתובת מאומתת (05). אורח — אין מייל (§2).
 *   יומן    — קובץ .ics חד-פעמי לכל מופע, לא למופע מושתק; "אירוע שנוסף ליומן לא יתעדכן ולא יבוטל מספורנו" (§4).
 */
'use strict';

(function (SF) {
    const { h } = SF;
    const R = window.SFRemCore;
    const Cal = window.SFCal;
    const DAYS = ['א׳', 'ב׳', 'ג׳', 'ד׳', 'ה׳', 'ו׳', 'ש׳'];
    const TYPE = { fixed: 'שעה קבועה', relative: 'יחסי לזמן', before_end: 'לפני סוף זמן', minyan: 'מניין' };
    const PUSH = {
        active: ['פעיל', 'ok'], prompt: ['חסר אישור', 'warn'], denied: ['חסום בדפדפן', 'bad'],
        ios_install: ['ב-iPhone — רק כשהאפליקציה מותקנת', 'warn'], unsupported: ['לא זמין בדפדפן הזה', 'bad'],
    };
    const CAL_LIMIT = 'אירוע שנוסף ליומן לא יתעדכן ולא יבוטל מספורנו';

    const daysText = d => d.length === 7 ? 'כל יום' : d.join() === '0,1,2,3,4' ? 'ימי חול (א׳–ה׳)' : d.join() === '0,1,2,3,4,5' ? 'א׳–ו׳' : d.map(x => DAYS[x]).join(' ');
    function whenText(r, def) {
        if (r.type === 'fixed' || r.type === 'minyan') return r.time;
        const z = def && def.zmanim.find(x => x.key === r.base);
        const zn = z ? z.name : r.base;
        if (r.type === 'before_end') return r.before + ' דק׳ לפני סוף ' + zn;
        return r.offset === 0 ? zn : Math.abs(r.offset) + ' דק׳ ' + (r.offset < 0 ? 'לפני ' : 'אחרי ') + zn;
    }

    async function prayers() {
        try {
            const cat = await SF.catalog();
            const out = [];
            for (const t of cat.titles || []) if (t.kind === 'siddur') for (const ed of t.editions) for (const it of ed.items || []) if (it.kind === 'prayer') out.push({ edition: ed.pid, sid: it.sid, name: it.name, ed: t.name + (t.editions.length > 1 ? ' · ' + ed.label : '') });
            return out;
        } catch (e) { return []; }
    }

    // ── העורך ──────────────────────────────────────────────────
    async function editor(rem, after) {
        const c = await SF.rem.ctx();
        const def = c.def;
        const [prs, devs] = await Promise.all([prayers(), SF.api('push-key').then(k => k.devices).catch(() => [])]);
        const r = Object.assign({ type: 'fixed', name: '', time: '06:30', days: [0, 1, 2, 3, 4], active: true, offset: -20, before: 15, base: '', channels: null, devices: null }, rem || {});
        if (!r.base && def) r.base = (def.zmanim.find(z => /sunset|shkia/.test(z.key)) || def.zmanim[0]).key;
        const box = h('div', { class: 'fields' });
        const msg = h('div', { class: 'wide', 'aria-live': 'polite' });
        const radio = (name, opts, cur, on) => h('div', { class: 'seg wide', role: 'radiogroup' }, opts.map(([v, label]) => h('label', null,
            h('input', { type: 'radio', name, value: v, checked: cur === v, onchange: () => on(v) }), h('span', null, label))));
        const field = (label, el, cls = 'f wide') => h('label', { class: cls }, h('span', null, label), el);
        function draw() {
            const rel = r.type === 'relative' || r.type === 'before_end';
            const name = h('input', { type: 'text', maxlength: 60, value: r.name, placeholder: r.type === 'minyan' ? 'מניין ותיקין בבית הכנסת' : 'למשל: שחרית', oninput: e => { r.name = e.target.value; } });
            const time = h('input', { type: 'time', value: r.time || '06:30', required: true, oninput: e => { r.time = e.target.value; } });
            const zsel = h('select', { onchange: e => { r.base = e.target.value; } }, (def ? def.zmanim : []).map(z => h('option', { value: z.key, selected: z.key === r.base }, z.name)));
            const mins = (v, on) => h('input', { type: 'number', inputmode: 'numeric', min: 0, max: 300, value: String(v), oninput: e => on(Math.max(0, Math.min(300, parseInt(e.target.value, 10) || 0))) });
            const dir = r.offset < 0 ? 'before' : 'after';
            const pr = h('select', { onchange: e => { const p = prs[Number(e.target.value)]; r.prayer = p ? { edition: p.edition, sid: p.sid, name: p.name } : null; } },
                h('option', { value: '' }, 'בלי — לפתוח את מסך התזכורות'),
                prs.map((p, i) => h('option', { value: String(i), selected: !!(r.prayer && r.prayer.edition === p.edition && r.prayer.sid === p.sid) }, p.name + ' · ' + p.ed)));
            const days = h('div', { class: 'chips wide', role: 'group', 'aria-label': 'ימים' }, DAYS.map((d, i) => h('label', null,
                h('input', { type: 'checkbox', checked: r.days.includes(i), onchange: e => { r.days = e.target.checked ? [...new Set(r.days.concat([i]))].sort() : r.days.filter(x => x !== i); } }), h('span', null, d))));
            const custom = !!r.channels;
            const ch = r.channels || c.prefs.channels;
            const chk = (k, label) => h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: !!ch[k], disabled: !custom, onchange: e => { r.channels = Object.assign({}, r.channels, { [k]: e.target.checked }); } }), h('span', null, label));
            const devAll = r.devices === null;
            SF.put(box,
                radio('rt', Object.entries(TYPE), r.type, v => { r.type = v; draw(); }),
                field(r.type === 'minyan' ? 'שם המניין (חובה)' : 'שם (לא חובה)', name),
                rel ? (def ? [field('הזמן', zsel), r.type === 'relative'
                    ? [radio('rd', [['before', 'לפני'], ['after', 'אחרי']], dir, v => { r.offset = (v === 'before' ? -1 : 1) * Math.abs(r.offset || 20); draw(); }),
                        field('דקות', mins(Math.abs(r.offset), v => { r.offset = (r.offset < 0 ? -1 : 1) * v; }), 'f')]
                    : field('דקות לפני סוף הזמן', mins(r.before, v => { r.before = v; }), 'f')]
                    : h('p', { class: 'notice wide' }, 'אין עדיין שיטת חישוב מאושרת. אפשר לשמור, והתזכורת תתחיל לפעול כשתאושר ותיבחר שיטה.'))
                    : field('שעה', time, 'f'),
                h('div', { class: 'wide' }, h('span', { class: 'sec-title' }, 'ימים'), days,
                    h('div', { class: 'row-btns wrap' }, [['ימי חול', [0, 1, 2, 3, 4]], ['א׳–ו׳', [0, 1, 2, 3, 4, 5]], ['כל יום', [0, 1, 2, 3, 4, 5, 6]]].map(([l, v]) =>
                        h('button', { class: 'btn sm ghost', type: 'button', onclick: () => { r.days = v; draw(); } }, l)))),
                h('details', { class: 'wide' }, h('summary', null, 'תאריכים (לא חובה)'), h('div', { class: 'fields two' },
                    field('מתאריך', h('input', { type: 'date', value: r.from || '', oninput: e => { r.from = e.target.value || null; } }), 'f'),
                    field('עד תאריך', h('input', { type: 'date', value: r.to || '', oninput: e => { r.to = e.target.value || null; } }), 'f'))),
                field('תפילה מקושרת — לחיצה על התזכורת פותחת אותה', pr),
                h('fieldset', { class: 'group wide' }, h('legend', { class: 'sec-title' }, 'ערוצים'),
                    h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: !custom, onchange: e => { r.channels = e.target.checked ? null : Object.assign({}, c.prefs.channels); draw(); } }), h('span', null, 'לפי ברירת המחדל שלי')),
                    chk('push', '🔔 התראה כשהאפליקציה סגורה'), chk('inapp', '📱 בתוך האפליקציה'), chk('email', '✉️ מייל')),
                devs.length > 1 ? h('fieldset', { class: 'group wide' }, h('legend', { class: 'sec-title' }, 'מכשירים להתראה'),
                    h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: devAll, onchange: e => { r.devices = e.target.checked ? null : devs.map(d => d.pid); draw(); } }), h('span', null, 'כל המכשירים שלי')),
                    devAll ? null : devs.map(d => h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: r.devices.includes(d.pid),
                        onchange: e => { r.devices = e.target.checked ? r.devices.concat([d.pid]) : r.devices.filter(x => x !== d.pid); } }), h('span', null, d.label + (d.gone ? ' (לא פעיל)' : ''))))) : null,
                msg);
        }
        draw();
        const go = h('button', { class: 'btn sm primary', type: 'button', onclick: async () => {
            const out = { id: r.id, created_at: r.created_at, name: r.name.trim(), type: r.type, prayer: r.prayer || null, days: r.days, from: r.from || null, to: r.to || null,
                active: r.active !== false, channels: r.channels, devices: r.devices };
            if (r.type === 'fixed' || r.type === 'minyan') out.time = r.time;
            else { out.base = r.base; if (r.type === 'relative') out.offset = r.offset; else out.before = r.before; }
            try { await SF.rem.save(out); dlg.close(); SF.toast('התזכורת נשמרה'); if (after) after(); } catch (e) { SF.put(msg, h('p', { class: 'notice' }, e.message)); }
        } }, 'שמירה');
        const dlg = SF.sheet(rem ? 'עריכת תזכורת' : 'תזכורת חדשה', box, { action: go });
    }

    // ── המסך ───────────────────────────────────────────────────
    SF.route('#/reminders', async main => {
        const body = h('div');
        main.replaceChildren(body);
        const stop = SF.rem.onChange(() => draw());
        SF.onLeave(stop);

        async function draw() {
            const [u, rems, ps, status, keyInfo] = await Promise.all([SF.rem.upcoming(48), SF.rem.list(), SF.rem.pushState(),
                SF.api('reminders-status').catch(e => ({ offline: e.code === 'network' })), SF.api('push-key').catch(() => null)]);
            if (!body.isConnected) return;
            const c = u.ctx;
            const reader = SF.account && SF.account.reader();
            const tz = c.tz;
            const now = Date.now();
            const wd = ms => { const p = Cal.localParts(tz, ms); return Cal.weekday(Cal.fixedFromGreg(p.y, p.m, p.d)); };

            // הקרובות
            const up = u.occ.filter(o => o.ms >= now - 60000);
            const upList = up.length ? h('ul', { class: 'list' }, up.slice(0, 30).map(o => {
                const ch = R.channels(o.r, c.prefs);
                return h('li', { class: 'card' + (o.muted ? ' muted-row' : '') },
                    h('a', { class: 'body', href: R.link(o.r) },
                        h('div', { class: 'l1' }, h('b', { class: 'zm-time', dir: 'ltr' }, Cal.hhmm(tz, o.ms)), h('b', { class: 'one' }, o.r.name || (o.r.prayer && o.r.prayer.name) || TYPE[o.r.type]),
                            o.muted ? h('span', { class: 'chip warn' }, '🕯️ מושתק') : null),
                        h('div', { class: 'l2' }, 'יום ' + SF.daily.WEEKDAYS[wd(o.ms)] + ' · '
                            + (o.muted ? 'שבת / חג — לא יישלח בשום ערוץ' : [ch.push ? '🔔' : '', ch.inapp ? '📱' : '', ch.email ? '✉️' : ''].join(' ').trim() || 'אין ערוץ'))),
                    o.muted ? null : h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'הוספה ליומן: ' + (o.r.name || 'תזכורת'), onclick: () => {
                        const blob = SF.rem.icsFile(o);
                        if (!blob) return;
                        const a = h('a', { href: URL.createObjectURL(blob), download: 'sforno-' + o.r.id + '-' + o.ms + '.ics' });
                        document.body.append(a); a.click(); a.remove();
                        SF.toast('קובץ היומן נוצר. ' + CAL_LIMIT);
                    } }, '📅'));
            })) : h('p', { class: 'empty' }, rems.length ? 'אין תזכורות ב-48 השעות הקרובות' : 'עוד אין תזכורות');

            // התזכורות שלי
            const mine = h('ul', { class: 'list' }, rems.length ? rems.map(raw => {
                const r = R.clean(raw);
                if (!r) return null;
                return h('li', { class: 'card' },
                    h('label', { class: 'switch', title: 'פעילה' }, h('input', { type: 'checkbox', checked: r.active, 'aria-label': 'פעילה: ' + (r.name || TYPE[r.type]),
                        onchange: async e => { await SF.rem.save(Object.assign({}, raw, { active: e.target.checked })); } })),
                    h('div', { class: 'body', onclick: () => editor(raw) },
                        h('div', { class: 'l1' }, h('b', { class: 'one' }, r.name || (r.prayer && r.prayer.name) || TYPE[r.type]), h('span', { class: 'chip' }, TYPE[r.type])),
                        h('div', { class: 'l2' }, whenText(r, c.def) + ' · ' + daysText(r.days) + (r.prayer ? ' · 📖 ' + r.prayer.name : ''))),
                    h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'מחיקה: ' + (r.name || TYPE[r.type]), onclick: async () => { if (confirm('למחוק את התזכורת?')) { await SF.rem.remove(r.id); SF.toast('נמחקה'); } } }, '🗑️'));
            }) : h('li', { class: 'empty' }, 'עוד אין תזכורות'));

            // ערוצים
            const [pl, pc] = PUSH[ps] || PUSH.unsupported;
            const serverPush = keyInfo ? !!keyInfo.key : null;
            const pushBtns = ps === 'active'
                ? h('div', { class: 'row-btns wrap' },
                    h('button', { class: 'btn sm ghost', type: 'button', onclick: async e => { e.currentTarget.disabled = true; try { const t = await SF.rem.testPush(); SF.toast(t.sent ? 'נשלחה התראת ניסיון' : 'הספק לא קיבל את ההתראה (' + t.http + ')', !t.sent); } catch (x) { SF.toast(x.message, true); } draw(); } }, 'התראת ניסיון'),
                    h('button', { class: 'btn sm ghost', type: 'button', onclick: async () => { try { await SF.rem.disablePush(); SF.toast('ההתראות כובו במכשיר הזה'); } catch (x) { SF.toast(x.message, true); } } }, 'כיבוי במכשיר הזה'))
                : ps === 'prompt' && serverPush !== false ? h('button', { class: 'btn sm primary', type: 'button', onclick: async e => {
                    e.currentTarget.disabled = true;
                    try { await SF.rem.enablePush(); SF.toast('ההתראות הופעלו במכשיר הזה'); } catch (x) { SF.toast(x.message, true); draw(); }
                } }, 'הפעלת התראות במכשיר הזה') : null;
            const emailState = !reader ? ['דורש חשבון', 'warn', 'למייל צריך חשבון עם כתובת מאומתת. אורח — בלי מייל.']
                : status.email && status.email.verified ? ['פעיל', 'ok', 'נשלח לכתובת של החשבון.'] : ['דורש אימות', 'warn', 'צריך לאמת את כתובת המייל באזור האישי.'];
            const chRow = (ic, name, chip, cls, hint, extra) => h('li', { class: 'card' }, h('div', { class: 'body' },
                h('div', { class: 'l1' }, h('span', { 'aria-hidden': 'true' }, ic), h('b', { class: 'one' }, name), chip ? h('span', { class: 'chip ' + cls }, chip) : null),
                hint ? h('div', { class: 'l2' }, hint) : null, extra || null));
            const dflt = c.prefs.channels;
            const dChk = (k, label) => h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: !!dflt[k], onchange: e => SF.rem.setPrefs({ channels: Object.assign({}, dflt, { [k]: e.target.checked }) }) }), h('span', null, label));
            const bg = status.offline ? '📡 בלי אינטרנט — מצב השרת לא ידוע' : status.cron && status.cron.ok ? null
                : 'שירות הרקע בשרת עוד לא פועל — התראות כשהאפליקציה סגורה ומיילים לא יישלחו עד שיופעל. בתוך האפליקציה — עובד.';

            // שבת וחג
            const ext = c.prefs.extend;
            const extSel = k => h('select', { 'aria-label': k === 'before' ? 'הארכה לפני הכניסה' : 'הארכה אחרי היציאה', onchange: e => SF.rem.setPrefs({ extend: Object.assign({}, ext, { [k]: Number(e.target.value) }) }) },
                [0, 15, 30, 60, 90, 120, 180].map(m => h('option', { value: String(m), selected: ext[k] === m }, m ? m + ' דקות' : 'בלי הארכה')));
            const mute = status.mute || { name: R.MUTE.name, test: R.MUTE.test };

            SF.put(body,
                h('div', { class: 'toolbar' }, h('h3', { class: 'sec-title', style: 'flex:1' }, 'הקרובות · 48 שעות'),
                    h('button', { class: 'btn sm primary', type: 'button', onclick: () => editor(null) }, '+ תזכורת')),
                u.need.place ? h('div', { class: 'notice' }, h('p', null, 'יש תזכורת לפי זמני היום — צריך לבחור מיקום.'), h('button', { class: 'btn sm', type: 'button', onclick: () => SF.daily.locSheet(draw) }, 'בחירת מיקום')) : null,
                u.need.method ? h('div', { class: 'notice' }, h('p', null, 'יש תזכורת לפי זמני היום — צריך שיטת חישוב מאושרת.'), h('button', { class: 'btn sm', type: 'button', onclick: () => SF.daily.methodSheet(draw) }, 'בחירת שיטה')) : null,
                upList,
                h('p', { class: 'hint' }, '📅 ליומן — קובץ אירוע חד-פעמי למופע אחד. ' + CAL_LIMIT + '. למופע בשבת או בחג לא נוצר קובץ.'),
                h('h3', { class: 'sec-title' }, 'התזכורות שלי'), mine,
                h('h3', { class: 'sec-title' }, 'ערוצים'),
                bg ? h('p', { class: 'notice' }, bg) : null,
                h('ul', { class: 'list' },
                    chRow('🔔', 'התראה כשהאפליקציה סגורה', pl, pc, ps === 'ios_install' ? 'ב-iPhone: שיתוף ← "הוספה למסך הבית", ואז להפעיל מכאן.'
                        : ps === 'denied' ? 'ההתראות נחסמו לאתר הזה בהגדרות הדפדפן.' : serverPush === false ? 'השרת עוד לא מוכן להתראות.' : 'לא מובטח בכל מכשיר; "נשלח" אינו "התקבל".', pushBtns),
                    chRow('📱', 'בתוך האפליקציה', 'פעיל', 'ok', 'רשימת הקרובות, ובאנר כשמגיע הזמן — כל עוד האפליקציה פתוחה.'),
                    chRow('✉️', 'מייל', emailState[0], emailState[1], emailState[2], reader && !(status.email && status.email.verified) ? h('a', { class: 'btn sm ghost', href: '#/account' }, 'לאזור האישי') : null),
                    chRow('📅', 'יומן', null, '', 'קובץ אירוע (.ics) לכל מופע — מהרשימה למעלה.')),
                h('fieldset', { class: 'group' }, h('legend', { class: 'sec-title' }, 'ברירת המחדל לתזכורת חדשה'), dChk('push', '🔔 התראה'), dChk('inapp', '📱 בתוך האפליקציה'), dChk('email', '✉️ מייל')),
                h('h3', { class: 'sec-title' }, '🕯️ שבת וחג'),
                h('p', { class: 'hint' }, 'כל הערוצים מושתקים מכניסה ועד יציאה — כולל רצף שבת וחג, ויום טוב שני מחוץ לישראל לפי המיקום. מה שהוחמץ בהשתקה לא נשלח ביציאה.'),
                h('p', { class: 'hint' }, mute.name, mute.test ? h('span', { class: 'chip warn' }, 'לבדיקה — טרם אושרה') : null),
                h('div', { class: 'fields two' }, h('label', { class: 'f' }, h('span', null, 'להתחיל מוקדם יותר'), extSel('before')), h('label', { class: 'f' }, h('span', null, 'לסיים מאוחר יותר'), extSel('after'))),
                !c.place ? h('p', { class: 'hint' }, 'בלי מיקום ההשתקה רחבה: מ-12:00 בערב שבת / חג עד 01:00 אחרי היציאה (' + tz + ').') : null);
        }
        await draw();
    }, { title: 'תזכורות' });
})(window.SF);
