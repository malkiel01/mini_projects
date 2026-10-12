// יומן ותזכורות — שלב 10 (lib/calendar.php). #/calendar[/YYYY-MM-DD]
//
// למעלה "דורש טיפול" (תשלום באיחור, ממתין ללקוח / לעו"ד, שלב שלא התחיל / הסתיים,
// תוקף הצעה, פגישה היום). מתחת — חודש: נקודה צבעונית לכל דבר ביום; לחיצה על יום —
// הרשימה שלו. פגישות וביקורים: "+ פגישה". ובתחתית — סנכרון ליומן בטלפון ומייל בוקר.
'use strict';

const Calendar = (() => {
    let bound = false, items = [], today = '', month = '', day = '', projects = [];
    // חגי חו"ל (יום טוב שני של גלויות) — מוצגים כברירת מחדל; נשמר במכשיר
    let diaspora = (() => { try { return localStorage.getItem('pm.diaspora') !== '0'; } catch { return true; } })();
    const HOL_ICON = { yomtov: '✡️', chol: '🌿', erev: '🕯️', fast: '🍽️', minor: '🎉', modern: '🇮🇱', rc: '🌒' };
    // מה מוצג בתא כשיש כמה: חג לפני חוה"מ, לפני צום, לפני ערב…
    const HOL_RANK = { yomtov: 0, modern: 1, fast: 2, minor: 3, chol: 4, erev: 5, rc: 6 };
    // כותרות הימים, מיום ראשון: בעברית אות אחת; בשפה אחרת — השם הקצר מ-Intl (4.1.2026 = ראשון)
    const DAYS = I18n.lang() === 'he' ? ['א', 'ב', 'ג', 'ד', 'ה', 'ו', 'ש'] // i18n-none
        : Array.from({ length: 7 }, (_, i) => new Intl.DateTimeFormat(I18n.locale(), { weekday: 'short' }).format(new Date(2026, 0, 4 + i)));
    const ICON = { appointment: '📅', stage_start: '🏁', stage_end: '🏁', payment: '💳', quote: '🧾' };
    const kindClass = k => k.startsWith('stage') ? 'stage' : k;
    const ymd = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const parse = s => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d || 1); };

    function bind() {
        if (bound) return;
        bound = true;
        $('#cal-prev').addEventListener('click', () => shift(-1));
        $('#cal-next').addEventListener('click', () => shift(1));
        $('#cal-today').addEventListener('click', () => { location.hash = '#/calendar/' + today; });
        $('#cal-diaspora').checked = diaspora;
        $('#cal-diaspora').addEventListener('change', e => {
            diaspora = e.target.checked;
            try { localStorage.setItem('pm.diaspora', diaspora ? '1' : '0'); } catch { /* לא חובה */ }
            loadMonth(month, day);
        });
        $('#add-appt').addEventListener('click', () => openAppt(null));
        $('#cal-grid').addEventListener('click', e => {
            const c = e.target.closest('[data-day]');
            if (c) history.replaceState(null, '', '#/calendar/' + c.dataset.day), pick(c.dataset.day);
        });
        $('#cal-day').addEventListener('click', e => {
            if (e.target.closest('a')) return;
            const row = e.target.closest('[data-i]');
            if (!row) return;
            const x = dayItems()[Number(row.dataset.i)];
            if (x.kind === 'appointment') openAppt(x);
            else location.hash = `#/p/${x.project_id}/${x.pane}`;
        });
        $('#rem-list').addEventListener('click', e => {
            const row = e.target.closest('[data-go]');
            if (row) location.hash = row.dataset.go;
        });
        for (const id of ['#cal-day', '#rem-list']) {
            $(id).addEventListener('keydown', e => {
                const row = e.target.closest('[role=button]');
                if (row && e.target === row && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); row.click(); }
            });
        }
        const form = $('#appt-dlg form');
        form.addEventListener('submit', e => { e.preventDefault(); saveAppt(); });
        form.elements.project_id.addEventListener('change', () => fillContacts(null));
        form.elements.date.addEventListener('change', apptHoliday);
        $('#appt-cancel').addEventListener('click', cancelAppt);
        $('#cal-sync').addEventListener('toggle', e => { if (e.target.open) loadLink(false); });
        $('#cal-renew').addEventListener('click', () => { if (confirm(t('ליצור קישור חדש? הקישור הקודם יפסיק להתעדכן בטלפון.'))) loadLink(true); });
        $('#cal-copy').addEventListener('click', async () => {
            try { await navigator.clipboard.writeText($('#cal-copy').dataset.url); toast(t('הועתק')); } catch { prompt(t('הקישור:'), $('#cal-copy').dataset.url); }
        });
        $('#digest-on').addEventListener('change', async e => {
            try { await api('digest', { body: { on: e.target.checked } }); state.user.digest = e.target.checked; toast(t(e.target.checked ? 'מייל הבוקר דלוק' : 'מייל הבוקר כבוי')); }
            catch (x) { toast(x.message, true); e.target.checked = !e.target.checked; }
        });
    }

    async function show(sub) {
        bind();
        $('#title').textContent = t('יומן');
        document.title = t('יומן — ניהול פרויקטים');
        $('#digest-on').checked = state.user.digest !== false;
        const want = /^\d{4}-\d{2}-\d{2}$/.test(sub || '') ? sub : '';
        await Promise.all([loadReminders(), loadMonth(want ? want.slice(0, 7) : month || '', want)]);
    }

    async function loadReminders() {
        let list = [];
        try { ({ reminders: list } = await api('reminders')); } catch { return; }
        badge(list);
        $('#reminders').hidden = !list.length;
        $('#rem-title').textContent = t('דורש טיפול ({n})', { n: list.length });
        const icon = { late: '⚠️', soon: '⏰', wait: '⏳' };
        $('#rem-list').innerHTML = list.map(r => `<li class="card rem-${r.level}" data-go="${r.pane === 'calendar' ? '#/calendar/' + today : `#/p/${r.project_id}/${r.pane}`}" tabindex="0" role="button">
            <span class="ficon" aria-hidden="true">${icon[r.level]}</span>
            <div class="body"><div class="l1"><span class="one">${esc(r.text)}</span></div><div class="l2 one">${esc(r.project_name)}</div></div></li>`).join('');
    }

    /** המספר ליד "יומן", והפס במסך הבית. */
    function badge(list) {
        const urgent = list.filter(r => r.level !== 'wait').length;
        $('#rem-badge').hidden = !list.length;
        $('#rem-badge').textContent = list.length;
        $('#rem-badge').classList.toggle('hot', urgent > 0);
        const b = $('#rem-banner');
        b.hidden = !list.length;
        if (list.length) b.innerHTML = `<span aria-hidden="true">${urgent ? '⚠️' : '⏳'}</span><span class="one">${esc(t('{n} דורשים טיפול · {text}', { n: list.length, text: list[0].text }))}</span><span class="dir-flip" aria-hidden="true">‹</span>`;
    }

    async function loadMonth(m, pickDay) {
        const now = new Date();
        month = m || month || ymd(now).slice(0, 7);
        const first = parse(month + '-01');
        // שישה שבועות מלאים, מיום ראשון
        const start = new Date(first); start.setDate(1 - first.getDay());
        const end = new Date(start); end.setDate(start.getDate() + 41);
        try { ({ items, today } = await api('calendar', { params: { from: ymd(start), to: ymd(end) } })); }
        catch (e) { toast(e.message, true); return; }
        day = pickDay || (day && day.startsWith(month) ? day : (today.startsWith(month) ? today : month + '-01'));
        drawMonth(start);
        pick(day);
    }

    function shift(d) {
        const f = parse(month + '-01');
        f.setMonth(f.getMonth() + d);
        const m = ymd(f).slice(0, 7);
        history.replaceState(null, '', '#/calendar/' + m + '-01');
        day = '';
        loadMonth(m);
    }

    function drawMonth(start) {
        const m0 = parse(month + '-01');
        $('#cal-month').innerHTML = `${esc(m0.toLocaleDateString(I18n.locale(), { month: 'long', year: 'numeric' }))} <small>${esc(HebCal.monthSpan(m0.getFullYear(), m0.getMonth()))}</small>`;
        const byDay = new Map();
        for (const x of items) { if (!byDay.has(x.date)) byDay.set(x.date, []); byDay.get(x.date).push(x); }
        let html = DAYS.map(d => `<span class="dow" aria-hidden="true">${d}</span>`).join('');
        for (let i = 0; i < 42; i++) {
            const d = new Date(start); d.setDate(start.getDate() + i);
            const k = ymd(d), list = byDay.get(k) || [];
            const kinds = [...new Set(list.map(x => kindClass(x.kind)))];
            const late = list.some(x => x.late);
            const h = HebCal.info(d, diaspora);
            // בתא — קודם מה שחל בארץ; יום טוב שני של גלויות רק כשאין משהו אחר
            const top = h.holidays.slice().sort((a, b) => (a.place === 'diaspora') - (b.place === 'diaspora') || HOL_RANK[a.kind] - HOL_RANK[b.kind])[0];
            const hol = top ? ` hol-${top.kind}${top.place === 'diaspora' ? ' dia' : ''}` : '';
            html += `<button type="button" class="cday${k.startsWith(month) ? '' : ' out'}${k === today ? ' today' : ''}${late ? ' late' : ''}${d.getDay() === 6 ? ' sat' : ''}${hol}" data-day="${k}"
                aria-label="${esc([d.toLocaleDateString(I18n.locale(), { weekday: 'long', day: 'numeric', month: 'long' }), h.label, top ? top.name : '', list.length ? t('{n} פריטים', { n: list.length }) : ''].filter(Boolean).join(', '))}">
                <span class="n">${d.getDate()}</span><span class="hd" aria-hidden="true">${h.day}</span>
                ${top ? `<span class="hol" aria-hidden="true">${esc(top.short)}</span>` : ''}
                <span class="dots">${kinds.map(c => `<i class="k-${c}"></i>`).join('')}</span></button>`;
        }
        $('#cal-grid').innerHTML = html;
    }

    const dayItems = () => items.filter(x => x.date === day);

    function pick(d) {
        day = d;
        $$('#cal-grid .cday').forEach(c => c.setAttribute('aria-pressed', String(c.dataset.day === d)));
        const label = parse(d).toLocaleDateString(I18n.locale(), { weekday: 'long', day: 'numeric', month: 'long' });
        const h = HebCal.info(parse(d), diaspora);
        $('#cal-day-title').textContent = (d === today ? t('היום — {date}', { date: label }) : label) + ` · ${h.label}`;
        const hol = h.holidays.map(x => `<li class="card static hol-row hol-${x.kind}">
            <span class="when" aria-hidden="true">${HOL_ICON[x.kind]}</span>
            <div class="body"><div class="l1"><span class="one">${esc(x.name)}</span>${x.place === 'diaspora' ? `<span class="chip">${esc(t('חו״ל'))}</span>` : x.place === 'il' ? `<span class="chip">${esc(t('בארץ'))}</span>` : ''}</div></div></li>`).join('');
        $('#cal-day').innerHTML = hol + dayItems().map((x, i) => {
            const date = parse(x.date).toLocaleDateString(I18n.locale(), { weekday: 'long', day: 'numeric', month: 'numeric' });
            const at = x.time ? t('{date} ב-{time}', { date, time: x.time }) : date;
            const remind = t(x.location ? 'תזכורת: {title}, {when}, {place}.' : 'תזכורת: {title}, {when}.', { title: x.title, when: at, place: x.location });
            const contact = x.contact_phone ? `<a class="icon-btn" href="https://wa.me/${esc(waNumber(x.contact_phone))}?text=${encodeURIComponent(remind)}" target="_blank" rel="noopener" aria-label="${esc(t('תזכורת בוואטסאפ ל{name}', { name: x.contact_name }))}">💬</a>` : '';
            const nav = x.location ? `<a class="icon-btn" href="https://waze.com/ul?q=${encodeURIComponent(x.location)}" target="_blank" rel="noopener" aria-label="${esc(t('ניווט אל {place}', { place: x.location }))}">📍</a>` : '';
            return `<li class="card k-row ${x.late ? 'late' : ''}${x.done ? ' done' : ''}" data-i="${i}" tabindex="0" role="button">
                <span class="when">${x.time ? esc(x.time) : ICON[x.kind]}</span>
                <div class="body"><div class="l1"><span class="one">${esc(x.title)}</span></div>
                    <div class="l2 one">${[esc(x.project_name), x.contact_name ? esc(x.contact_name) : '', x.location ? esc(x.location) : '', x.late ? `<b class="late">${esc(t('באיחור'))}</b>` : ''].filter(Boolean).join('<i class="sep" aria-hidden="true">·</i>')}</div></div>
                <div class="acts">${contact}${nav}</div></li>`;
        }).join('') || (hol ? '' : `<p class="empty">${esc(t('אין כלום ביום הזה.'))}</p>`);
    }

    /** בחלון הפגישה: "שימו לב — יום כיפור" / שבת. */
    function apptHoliday() {
        const v = $('#appt-dlg form').elements.date.value;
        const box = $('#appt-hol');
        if (!v) { box.hidden = true; return; }
        const d = parse(v), h = HebCal.info(d, false);
        const hard = h.holidays.filter(x => ['yomtov', 'fast', 'modern', 'erev'].includes(x.kind));
        const warn = [d.getDay() === 6 ? t('שבת') : '', ...hard.map(x => x.name)].filter(Boolean);
        box.hidden = !warn.length;
        box.textContent = warn.length ? t('⚠️ שימו לב — {what} ({date})', { what: warn.join(', '), date: h.label }) : '';
    }

    // ================= פגישה =================

    let editing = null;

    async function openAppt(x) {
        editing = x;
        const dlg = $('#appt-dlg'), form = $('form', dlg), el = form.elements;
        formError(form, '');
        try { ({ projects } = await api('projects', { params: { filter: 'open' } })); } catch { projects = []; }
        el.project_id.innerHTML = options(projects.map(p => ({ value: p.id, label: p.name })), x?.project_id || (location.hash.match(/#\/p\/(\d+)/) || [])[1] || '', projects.length ? undefined : t('אין פרויקטים פעילים'));
        el.project_id.disabled = !!x;
        el.title.value = x?.title || '';
        el.date.value = x?.date || day || today;
        el.time.value = x?.time || '';
        el.duration.value = String(x?.duration || 60);
        el.location.value = x?.location || '';
        el.notes.value = x?.notes || '';
        $('#appt-dlg-title').textContent = t(x ? 'פגישה' : 'פגישה חדשה');
        $('#appt-cancel').hidden = !x;
        await fillContacts(x?.contact_id ?? null);
        apptHoliday();
        dlg.showModal();
    }

    /** "עם מי": הצוות של הפרויקט — לקוח, עו"ד, ספקים. */
    async function fillContacts(value) {
        const el = $('#appt-dlg form').elements;
        let team = [];
        if (el.project_id.value) {
            try { team = (await api('project', { params: { id: el.project_id.value } })).team; } catch { /* ריק */ }
            const p = projects.find(x => x.id === Number(el.project_id.value));
            el.location.placeholder = p?.address ? t('ברירת מחדל: {address}', { address: p.address }) : t('כתובת');
        }
        el.contact_id.innerHTML = options(team.map(m => ({ value: m.id, label: `${m.name} · ${t(m.roles[0])}` })), value, t('לא חובה'));
    }

    async function saveAppt() {
        const form = $('#appt-dlg form'), el = form.elements;
        const p = projects.find(x => x.id === Number(el.project_id.value));
        const body = {
            id: editing?.ref, project_id: Number(el.project_id.value), title: el.title.value.trim(), date: el.date.value, time: el.time.value,
            duration: Number(el.duration.value), location: el.location.value.trim() || p?.address || '', contact_id: Number(el.contact_id.value) || null, notes: el.notes.value,
        };
        if (!body.project_id) { formError(form, t('פרויקט: שדה חובה')); return; }
        if (!body.title) { formError(form, t('מה: שדה חובה')); return; }
        if (!body.date) { formError(form, t('תאריך: שדה חובה')); return; }
        await busy($('button[type=submit]', form), form, async () => {
            await api('appointment', { body });
            $('#appt-dlg').close();
            toast(t('נשמר'));
            if (document.body.dataset.view === 'calendar') { day = body.date; await Promise.all([loadReminders(), loadMonth(body.date.slice(0, 7), body.date)]); }
        });
    }

    async function cancelAppt() {
        if (!confirm(t('לבטל את "{title}"?', { title: editing.title }))) return;
        await busy($('#appt-cancel'), $('#appt-dlg form'), async () => {
            await api('appointment-cancel', { body: { id: editing.ref } });
            $('#appt-dlg').close();
            toast(t('הפגישה בוטלה'));
            await Promise.all([loadReminders(), loadMonth(month, day)]);
        });
    }

    // ================= סנכרון =================

    async function loadLink(renew) {
        try {
            const { url, webcal } = renew ? await api('cal-link', { body: {} }) : await api('cal-link');
            $('#cal-webcal').href = webcal;
            $('#cal-google').href = 'https://calendar.google.com/calendar/r/settings/addbyurl?cid=' + encodeURIComponent(webcal);
            $('#cal-copy').dataset.url = url;
            if (renew) toast(t('נוצר קישור חדש — צריך להוסיף אותו שוב בטלפון'));
        } catch (e) { toast(e.message, true); }
    }

    /** מהבית: רק המספר והפס — בלי לטעון את כל היומן. */
    async function refreshBadge() {
        try { badge((await api('reminders')).reminders); } catch { /* בלי פס */ }
    }

    return { show, refreshBadge, openAppt };
})();
