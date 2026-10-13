// הצעות מחיר — שלב 5 (lib/quotes.php). לשונית "הצעות" בפרויקט.
//
// ההצעות מקובצות לפי שלב, מהזולה ליקרה, עם הפרש מהזולה — זו ההשוואה. ⭐ = מומלצת.
// "📤 ללקוח" מסמן את ההצעות של השלב כנשלחו ופותח הודעה מוכנה (וואטסאפ / SMS / מייל).
// בחלון ההצעה המנהל רושם את ההחלטה של הלקוח או העו"ד ("אישר בטלפון") — עם צילום
// מצב של ההצעה. בשלב 6 הלקוח יאשר בעצמו מהקישור האישי, לאותה היסטוריה.
'use strict';

const Quotes = (() => {
    let project = null, data = { quotes: [], summary: {} }, bound = false, current = null, pending = null;
    const LIVE = ['open', 'sent', 'client_approved'];
    const LOCKED = ['client_approved', 'approved', 'rejected', 'declined', 'cancelled']; // כמו PM_QUOTE_LOCKED בשרת
    const ICON = { open: '📥', sent: '📤', client_approved: '⏳', approved: '✅', declined: '▫️', rejected: '✖️', cancelled: '🚫' };
    const stageOf = id => project.stages.find(s => s.id === id);
    const fileUrl = (id, extra = '') => `api.php?action=file&id=${id}${extra}`;
    const pct = (a, b) => Math.round((a - b) / b * 100);
    // שם שלב שנזרע — בשפה של ההודעה: מתורגם רק כשהיא שפת הממשק (t מתרגם לשפת הממשק בלבד)
    const inLang = (s, lang) => (lang === I18n.lang() ? t(s) : s);

    function bind() {
        if (bound) return;
        bound = true;
        $('#add-quote').addEventListener('click', () => open(null));
        $('#invite-quotes').addEventListener('click', () => openInvite());
        $('#invite-dlg form').addEventListener('submit', e => { e.preventDefault(); sendInvite(); });
        $('#invite-dlg [name=stage_id]').addEventListener('change', fillInvitees);
        $('#quote-list').addEventListener('click', e => {
            const send = e.target.closest('[data-send]');
            if (send) { e.stopPropagation(); sendToClient(Number(send.dataset.send)); return; }
            const row = e.target.closest('[data-quote]');
            if (row) open(Number(row.dataset.quote));
        });
        $('#quote-list').addEventListener('keydown', e => {
            const row = e.target.closest('[data-quote]');
            if (row && e.target === row && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); row.click(); }
        });
        const form = $('#quote-dlg form');
        form.addEventListener('submit', e => { e.preventDefault(); save(); });
        form.elements.stage_id.addEventListener('change', () => fillSuppliers());
        form.elements.file_id.addEventListener('change', fileLink);
        $('#quote-pick').addEventListener('change', e => { uploadFile(e.target.files[0]); e.target.value = ''; });
        $('#quote-recommend').addEventListener('click', recommend);
        $('#quote-attach').addEventListener('click', attachFile);
        $('#quote-cancel').addEventListener('click', cancel);
        $('#decision-btns').addEventListener('click', e => {
            const b = e.target.closest('[data-decide]');
            if (b) decide(b.dataset.role, b.dataset.decide);
        });
        $('#decisions').addEventListener('click', async e => {
            const b = e.target.closest('[data-sig]');
            if (!b) return;
            try {
                const { approval } = await api('approval', { params: { id: b.dataset.sig } });
                const img = new Image();
                img.className = 'sig-img';
                img.alt = t('החתימה של {name}', { name: approval.by_name });
                img.src = approval.signature;
                b.replaceWith(img);
            } catch (x) { toast(x.message, true); }
        });
    }

    /** project = התשובה של api('project') — הפרויקט, השלבים והצוות. */
    async function show(p) {
        bind();
        project = p;
        await load();
        if (pending) { const x = pending; pending = null; open(null, x); }
    }

    async function load() {
        try { data = await api('quotes', { params: { project_id: project.project.id } }); }
        catch (e) { toast(e.message, true); return; }
        render();
    }

    /** אחרי פעולה: ההצעות מהתשובה, והפרויקט מחדש — כי סטטוס השלבים זז. */
    async function refresh(d) {
        data = d;
        project = await Projects.reload();
        render();
    }

    function render() {
        const s = data.summary;
        const parts = [s.budget ? t('אושר {amount} מתוך {budget} ({pct}%)', { amount: money(s.approved), budget: money(s.budget), pct: Math.round(s.approved / s.budget * 100) })
            : t('אושר {amount}', { amount: money(s.approved) })];
        if (s.open) parts.push(t('{n} ממתינות', { n: s.open }));
        $('#q-sum').innerHTML = parts.map(esc).join('<i class="sep" aria-hidden="true">·</i>');
        $('#q-sum').classList.toggle('over', !!s.budget && s.approved > s.budget);

        const byStage = new Map();
        for (const q of data.quotes) {
            if (!byStage.has(q.stage_id)) byStage.set(q.stage_id, []);
            byStage.get(q.stage_id).push(q);
        }
        const html = project.stages.filter(st => byStage.has(st.id)).map(st => {
            const list = byStage.get(st.id);
            const live = list.filter(q => LIVE.includes(q.status) || q.status === 'approved');
            const low = live.length > 1 ? Math.min(...live.map(q => q.amount)) : null;
            // מומלצת ואושרה למעלה, אחר כך לפי סכום; מבוטלות בסוף
            const rank = q => q.status === 'approved' ? 0 : q.status === 'client_approved' ? 1 : q.recommended ? 2 : LIVE.includes(q.status) ? 3 : q.status === 'cancelled' ? 5 : 4;
            list.sort((a, b) => rank(a) - rank(b) || a.amount - b.amount);
            const canSend = list.some(q => q.status === 'open' || q.status === 'sent');
            return `<section class="q-stage" aria-label="${esc(t(st.name))}">
                <header class="q-head"><h2 class="one">${esc(t(st.name))}</h2>${stageChip(st.status)}
                    ${canSend ? `<button class="btn ghost sm" data-send="${st.id}" aria-label="${esc(t('שליחת ההצעות של {stage} ללקוח', { stage: st.name }))}">📤 ${esc(t('ללקוח'))}</button>` : ''}</header>
                <ul class="list rows-list">${list.map(q => row(q, low)).join('')}</ul>
            </section>`;
        }).join('');
        const without = project.stages.filter(st => !byStage.has(st.id) && !['done', 'paid'].includes(st.status));
        const names = without.map(s => t(s.name)).join(', ');
        $('#quote-list').innerHTML = (html || `<p class="empty">${esc(t('עוד אין הצעות מחיר. "+ הצעה", או מחלון של קובץ: "🧾 הצעת מחיר מהקובץ".'))}</p>`)
            + (html && without.length ? `<p class="hint q-without one" title="${esc(names)}">${esc(t('בלי הצעות ({n}): {names}', { n: without.length, names }))}</p>` : '');
    }

    function row(q, low) {
        const dead = ['declined', 'rejected', 'cancelled'].includes(q.status);
        const diff = low !== null && !dead
            ? (q.amount === low ? `<b class="cheap">${esc(t('הזולה'))}</b>` : `+${pct(q.amount, low)}%`) : '';
        const expired = q.valid_until && LIVE.includes(q.status) && q.valid_until < new Date().toISOString().slice(0, 10);
        const l2 = [
            `${ICON[q.status]} ${esc(state.labels.quote[q.status])}`,
            diff,
            q.valid_until ? `<span class="${expired ? 'late' : ''}">${esc(t(expired ? 'פג {date}' : 'עד {date}', { date: shortDate(q.valid_until) }))}</span>` : '',
            q.terms ? esc(q.terms) : '',
        ].filter(Boolean).join('<i class="sep" aria-hidden="true">·</i>');
        const pic = q.file_thumb ? `<img class="fthumb" src="${fileUrl(q.file_id, '&thumb=1')}" alt="" loading="lazy" width="40" height="40">`
            : `<span class="ficon" aria-hidden="true">${q.file_id ? '🧾' : '✍️'}</span>`;
        return `<li class="card q-row q-${q.status}${q.recommended ? ' rec' : ''}" data-quote="${q.id}" tabindex="0" role="button"
                aria-label="${esc(q.supplier_name)}, ${esc(money(q.amount))}, ${esc(state.labels.quote[q.status])}${q.recommended ? ', ' + esc(t('מומלצת')) : ''}">
            ${pic}
            <div class="body"><div class="l1"><span class="one">${q.recommended ? '<span aria-hidden="true">⭐ </span>' : ''}${esc(q.supplier_name)}</span>
                <span class="amt">${moneyVat(vatOf(q.amount, q.vat_rate))}</span></div>
                <div class="l2">${l2}</div></div>
        </li>`;
    }

    // ================= חלון הצעה =================

    /** פתיחה מחלון קובץ: עוברים ללשונית ההצעות, והחלון נפתח עם הקובץ והספק. */
    function fromFile(projectId, preset) {
        pending = preset;
        location.hash = `#/p/${projectId}/quotes`;
    }

    async function open(id, preset = {}) {
        const dlg = $('#quote-dlg'), form = $('form', dlg), el = form.elements;
        formError(form, '');
        current = id ? data.quotes.find(q => q.id === id) : null;
        const q = current || { stage_id: preset.stage_id || stageGuess(), supplier_id: preset.supplier_id || null, file_id: preset.file_id || null };
        $('#quote-dlg-title').textContent = current ? `${current.supplier_name} — ${money(current.amount)}` : t('הצעת מחיר חדשה');
        el.stage_id.innerHTML = options(project.stages.map(s => ({ value: s.id, label: t(s.name) })), q.stage_id, project.stages.length ? undefined : t('אין שלבים'));
        el.amount.value = current ? String(current.amount) : '';
        el.valid_until.value = q.valid_until || '';
        el.terms.value = q.terms || '';
        el.notes.value = q.notes || '';
        // הצעה שהוחלט עליה (אושרה / נדחתה / לא נבחרה / בוטלה) — לצפייה בלבד
        const locked = !!current && LOCKED.includes(current.status) && !Dev.on(); // סמכות מפתח: עריכה גם של נעולה
        Dev.attach(dlg, 'quote', current?.id);
        [...form.elements].forEach(x => { if (x.name) x.disabled = locked; });
        $('button[type=submit]', form).hidden = locked;
        // נעולה בלי טופס (מלפני שהטופס היה חובה) — אפשר רק להשלים את הטופס
        const attach = locked && !current.file_id && current.status !== 'cancelled';
        $('.upload-btn', form).hidden = locked && !attach;
        el.file_id.disabled = locked && !attach;
        $('#quote-attach').hidden = !attach;
        $('#quote-version-hint').hidden = current?.status !== 'sent';
        const lock = $('#quote-locked-hint');
        lock.hidden = !locked;
        lock.textContent = !locked ? '' : ['approved', 'client_approved'].includes(current.status)
            ? t('🔒 הצעה שאושרה — לא נערכת. שינוי במחיר או בהיקף: "± תוספת / חריגה" בלשונית הכספים, או ביטול והצעה חדשה.')
            : current.status === 'cancelled' ? t('🔒 הצעה שבוטלה — לצפייה בלבד.')
            : t('🔒 הצעה שנדחתה — לא נערכת. הצעה חדשה מהספק: "+ הצעה".');
        $('#quote-state').hidden = !current;
        if (current) {
            $('#quote-state').innerHTML = `<span class="chip q-${current.status}">${ICON[current.status]} ${esc(state.labels.quote[current.status])}</span>`
                + (current.recommended ? `<span class="chip rec">⭐ ${esc(t('מומלצת'))}</span>` : '')
                + `<span class="chip">${esc(t('גרסה {version}', { version: current.version }))}</span>`;
        }
        await Promise.all([fillSuppliers(q.supplier_id), fillFiles(q.file_id)]);
        renderActions();
        dlg.showModal();
        if (!current) el.amount.focus();
    }

    /** שלב ברירת מחדל: הראשון שעוד אוסף הצעות. */
    function stageGuess() {
        return (project.stages.find(s => ['quotes', 'planned'].includes(s.status)) || project.stages[0])?.id;
    }

    async function fillSuppliers(value) {
        const el = $('#quote-dlg form').elements;
        const st = stageOf(Number(el.stage_id.value));
        await Projects.fillSuppliers(el.supplier_id, st?.trade_id || 0, value ?? (Number(el.supplier_id.value) || null), teamOf(project));
        el.supplier_id.options[0].textContent = t('בחירת ספק');
    }

    async function fillFiles(value) {
        const el = $('#quote-dlg form').elements;
        let files = [];
        try { ({ files } = await api('files', { params: { project_id: project.project.id } })); } catch { /* ריק */ }
        const quotes = files.filter(f => f.type === 'quote'), rest = files.filter(f => f.type !== 'quote');
        const opt = f => ({ value: f.id, label: f.name });
        el.file_id.innerHTML = `<option value="">${esc(t('בחירת הטופס — או העלאה'))}</option>`
            + (quotes.length ? `<optgroup label="${esc(t('הצעות מחיר'))}">${options(quotes.map(opt), value)}</optgroup>` : '')
            + (rest.length ? `<optgroup label="${esc(t('קבצים אחרים'))}">${options(rest.map(opt), value)}</optgroup>` : '');
        fileLink();
    }

    function fileLink() {
        const id = Number($('#quote-dlg form').elements.file_id.value);
        $('#quote-file-open').hidden = !id;
        if (id) $('#quote-file-open').href = fileUrl(id);
    }

    /** קובץ חדש — עולה ל"ממתין למיון", ובשמירה עובר לתיקיית הספק. */
    async function uploadFile(file) {
        if (!file) return;
        const form = $('#quote-dlg form');
        if (file.size > state.maxUpload) { formError(form, t('הקובץ גדול מ-{n}MB', { n: Math.round(state.maxUpload / 1048576) })); return; }
        const box = $('#quote-upload');
        const bar = p => { box.innerHTML = `<div class="up"><span class="one">${esc(file.name)}</span><span>${p}%</span><i style="width:${p}%"></i></div>`; };
        bar(0);
        try {
            const { folders } = await api('folders', { params: { project_id: project.project.id } });
            const inbox = folders.find(f => f.kind === 'inbox' && f.parent_id === null);
            const { file: saved } = await Files.send(file, project.project.id, inbox.id, bar);
            await fillFiles(saved.id);
        } catch (e) { formError(form, e.message); }
        box.innerHTML = '';
    }

    function renderActions() {
        const q = current;
        $('#quote-actions').hidden = !q;
        if (!q) return;
        const hasLawyer = !!project.project.lawyer_id;
        const st = stageOf(q.stage_id);
        const btn = (role, decide, label, cls = 'ghost') => `<button type="button" class="btn ${cls}" data-role="${role}" data-decide="${decide}">${esc(label)}</button>`;
        let b = '';
        if (['open', 'sent', 'declined', 'rejected'].includes(q.status)) b = btn('client', 'approved', t('✅ הלקוח אישר'), 'primary') + btn('client', 'rejected', t('✖ הלקוח דחה'));
        if (q.status === 'client_approved' && hasLawyer) b = btn('lawyer', 'approved', t('✅ העו"ד אישר'), 'primary') + btn('lawyer', 'rejected', t('✖ העו"ד דחה'));
        if (['in_progress', 'done', 'paid'].includes(st?.status) && q.status !== 'client_approved') b = '';
        $('#decision-btns').innerHTML = b;
        $('#decision-note').closest('.f').hidden = !b;
        $('#decision-note').value = '';
        // i18n-ok-start — מתורגמים בתצוגה
        const said = {
            client: { approved: 'הלקוח אישר — {amount}', rejected: 'הלקוח דחה — {amount}' },
            lawyer: { approved: 'העו"ד אישר — {amount}', rejected: 'העו"ד דחה — {amount}' },
        };
        const method = { manual: 'נרשם ידנית', click: 'בקישור האישי', signature: 'בחתימה בקישור האישי' };
        // i18n-ok-end
        $('#decisions').innerHTML = q.decisions.slice().reverse().map(d => `<li class="${d.decision}">
            <span>${d.decision === 'approved' ? '✅' : '✖'} ${esc(t(said[d.role]?.[d.decision === 'approved' ? 'approved' : 'rejected'] || '', { amount: money(d.amount) }))}${d.version !== q.version ? ` <small>${esc(t('(גרסה {version}, לא בתוקף)', { version: d.version }))}</small>` : ''}</span>
            <small>${esc(d.by_name)} · ${esc(new Date(d.at).toLocaleString(I18n.locale(), { dateStyle: 'short', timeStyle: 'short' }))} · ${esc(method[d.method] ? t(method[d.method]) : d.method)}${d.note ? ' · ' + esc(d.note) : ''}</small>
            ${d.signed ? `<button type="button" class="btn ghost sm" data-sig="${d.id}">✍️ ${esc(t('החתימה'))}</button>` : ''}
        </li>`).join('');
        $('#decisions-title').hidden = !q.decisions.length;
        $('#quote-recommend').hidden = !LIVE.includes(q.status);
        $('#quote-recommend').textContent = t(q.recommended ? '⭐ ביטול ההמלצה' : '⭐ המלצה ללקוח');
        $('#quote-cancel').hidden = q.status === 'cancelled';
    }

    async function save() {
        const form = $('#quote-dlg form'), el = form.elements;
        const body = {
            id: current?.id, stage_id: Number(el.stage_id.value), supplier_id: Number(el.supplier_id.value) || 0,
            amount: el.amount.value.trim(), valid_until: el.valid_until.value, terms: el.terms.value, notes: el.notes.value,
            file_id: Number(el.file_id.value) || null,
        };
        if (!body.stage_id) { formError(form, t('שלב: שדה חובה. מוסיפים שלבים בלשונית "שלבים"')); return; }
        if (!body.supplier_id) { formError(form, t('ספק: שדה חובה')); return; }
        if (!body.amount) { formError(form, t('סכום: שדה חובה')); return; }
        if (!body.file_id) { formError(form, t('צריך לצרף את טופס הצעת המחיר (PDF או צילום)')); return; }
        await busy($('button[type=submit]', form), form, async () => {
            await refresh(await api('quote', { body }));
            $('#quote-dlg').close();
            toast(t('נשמר'));
        });
    }

    async function decide(role, decision) {
        const form = $('#quote-dlg form');
        const note = $('#decision-note').value.trim();
        const ask = role === 'client' ? 'לרשום שהלקוח אישר את ההצעה של {name} על {amount}?' : 'לרשום שהעו"ד אישר את ההצעה של {name} על {amount}?'; // i18n-ok
        if (decision === 'approved' && !confirm(t(ask, { name: current.supplier_name, amount: money(current.amount) }))) return;
        await busy(null, form, async () => {
            await refresh(await api('quote-decide', { body: { id: current.id, role, decision, note } }));
            $('#quote-dlg').close();
            toast(t(decision === 'approved' ? 'האישור נרשם' : 'הדחייה נרשמה'));
        });
    }

    async function attachFile() {
        const form = $('#quote-dlg form');
        const fileId = Number(form.elements.file_id.value) || 0;
        if (!fileId) { formError(form, t('צריך לצרף את טופס הצעת המחיר (PDF או צילום)')); return; }
        await busy($('#quote-attach'), form, async () => {
            await refresh(await api('quote-attach', { body: { id: current.id, file_id: fileId } }));
            $('#quote-dlg').close();
            toast(t('הטופס צורף להצעה'));
        });
    }

    async function recommend() {
        await busy($('#quote-recommend'), $('#quote-dlg form'), async () => {
            await refresh(await api('quote-recommend', { body: { id: current.id, on: !current.recommended } }));
            $('#quote-dlg').close();
        });
    }

    async function cancel() {
        const note = prompt(t('לבטל את ההצעה? היא תישאר בהיסטוריה.\nסיבה (לא חובה):'), '');
        if (note === null) return;
        await busy($('#quote-cancel'), $('#quote-dlg form'), async () => {
            await refresh(await api('quote-cancel', { body: { id: current.id, note } }));
            $('#quote-dlg').close();
            toast(t('ההצעה בוטלה'));
        });
    }

    // ================= שליחה ללקוח =================

    async function sendToClient(stageId) {
        const st = stageOf(stageId);
        let mailed = [];
        try { const r = await api('quotes-send', { body: { stage_id: stageId } }); mailed = (r.notified || []).map(n => n.name); await refresh(r); }
        catch (e) { toast(e.message, true); return; }
        const client = project.team.find(m => m.roles.includes('לקוח')); // i18n-none — התפקיד כפי שהשרת שומר
        const list = data.quotes.filter(q => q.stage_id === stageId && q.status === 'sent')
            .sort((a, b) => b.recommended - a.recommended || a.amount - b.amount);
        const lines = list.map(q => `${q.recommended ? '⭐' : '•'} ${q.supplier_name}${q.supplier_company ? ` (${q.supplier_company})` : ''} — ${money(q.amount)}`
            + (q.valid_until ? ', ' + t('בתוקף עד {date}', { date: shortDate(q.valid_until) }) : '') + (q.recommended ? ' — ' + t('ההמלצה שלי') : ''));
        // הקישור האישי של הלקוח — שם הוא רואה את ההצעות ומאשר בעצמו
        const url = await portalUrl(project.project.id, client?.id);
        const lang = client?.lang || I18n.lang();
        // i18n-none-start — שמות המשתנים בנוסח
        const text = renderMsg('quotes_sent', { 'שם': client?.name || '', 'פרויקט': project.project.name, 'שלב': inLang(st.name, lang), 'פרטים': lines.join('\n'), 'קישור': url }, lang);
        // i18n-none-end
        Send.open({ title: t('שליחת ההצעות ללקוח'), recipients: [{ contact: client, label: t('הלקוח'), text }], key: 'quotes_sent', projectId: project.project.id, mailed,
            subject: t('הצעות מחיר — {project}', { project: project.project.name }),
            hint: t('ההצעות סומנו "נשלחה ללקוח", והשלב ממתין לאישורו. בקישור האישי הלקוח רואה את ההצעות ומאשר בעצמו. אם הוא עונה בטלפון — פותחים את ההצעה ורושמים את ההחלטה.') });
    }

    // ================= בקשת הצעות מספקים =================

    function openInvite(stageId) {
        const dlg = $('#invite-dlg'), form = $('form', dlg);
        formError(form, '');
        const open = project.stages.filter(s => ['planned', 'quotes', 'client_approval'].includes(s.status));
        form.elements.stage_id.innerHTML = options((open.length ? open : project.stages).map(s => ({ value: s.id, label: t(s.name) })), stageId || stageGuess());
        fillInvitees();
        dlg.showModal();
    }

    async function fillInvitees() {
        const st = stageOf(Number($('#invite-dlg [name=stage_id]').value));
        let all = [];
        try { all = (await contactsOf('supplier')).filter(c => teamOf(project).includes(c.owner_id) && !c.archived); } catch { /* ריק */ }
        const asked = new Set(data.quotes.filter(q => q.stage_id === st?.id).map(q => q.supplier_id));
        const inTrade = st?.trade_id ? all.filter(c => c.trades.includes(st.trade_id)) : [];
        const list = [...inTrade, ...all.filter(c => !inTrade.includes(c))];
        $('#invite-suppliers').innerHTML = list.map(c => `<label><input type="checkbox" value="${c.id}"${inTrade.includes(c) && !asked.has(c.id) ? ' checked' : ''}>
            <span>${esc(c.name)}${asked.has(c.id) ? ' ✓' : ''}</span></label>`).join('') || `<p class="hint">${esc(t('אין ספקים. מוסיפים באנשי קשר ← ספקים.'))}</p>`;
    }

    async function sendInvite() {
        const form = $('#invite-dlg form');
        const stageId = Number(form.elements.stage_id.value);
        const ids = $$('#invite-suppliers input:checked').map(cb => Number(cb.value));
        if (!ids.length) { formError(form, t('בחרו ספק אחד לפחות')); return; }
        await busy($('button[type=submit]', form), form, async () => {
            const { invites, notified } = await api('quote-invite', { body: { stage_id: stageId, supplier_ids: ids } });
            $('#invite-dlg').close();
            project = await Projects.reload();
            const st = stageOf(stageId), p = project.project;
            Send.open({ title: t('בקשת הצעות — {stage}', { stage: st.name }), subject: t('בקשת הצעת מחיר — {stage}, {project}', { stage: st.name, project: p.name }),
                key: 'quote_invite', projectId: p.id, mailed: (notified || []).map(n => n.name),
                recipients: invites.map(i => {
                    const lang = i.contact.lang || I18n.lang();
                    return { contact: i.contact, label: i.contact.name,
                        // i18n-none-start — שמות המשתנים בנוסח
                        text: renderMsg('quote_invite', { 'שם': i.contact.name, 'פרויקט': p.name, 'שלב': inLang(st.name, lang), 'כתובת': p.address ? ` (${p.address})` : '', 'קישור': i.url }, lang) };
                        // i18n-none-end
                }),
                hint: t('כל ספק — ההודעה והקישור שלו (בוחרים למעלה). הצעה שספק מגיש נכנסת לכאן לבד, ותקבל מייל.') });
            render();
        });
    }

    return { show, fromFile };
})();
