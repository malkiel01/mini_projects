// פורטל הלקוח והעו"ד — שלב 6. עמוד אחד, בלי כניסה: האסימון בכתובת (#t=…).
//
// מה רואים: ממתין לאישורך · התקדמות השלבים · הצעות המחיר שנשלחו (המומלצת בראש)
// · הקבצים שמנהל הפרויקט סימן "גלוי" · ומנהל הפרויקט ליצירת קשר.
// אישור — בלחיצה או בחתימה, לפי מה שהמנהל הגדיר ללקוח. עו"ד מאשר אחרי הלקוח.
// השפה: של איש הקשר (מהשרת), אלא אם המבקר בחר אחרת בדף (assets/js/i18n.js).
'use strict';

const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const moneyFmt = new Intl.NumberFormat(I18n.locale(), { style: 'currency', currency: 'ILS', minimumFractionDigits: 0, maximumFractionDigits: 2 });
// בעברית — כמו קודם ובאפליקציה: ₪13,200
const money = n => (I18n.lang() === 'he' ? '₪' + Number(n || 0).toLocaleString('he-IL') : moneyFmt.format(Number(n || 0)));
// שיקוף מע"מ — כמו באפליקציה (assets/js/core.js): סכום, ומתחתיו לפני מע"מ + המע"מ
const vatOf = (g, rate) => ({ g: Number(g) || 0, n: rate > 0 ? Math.round((Number(g) || 0) / (1 + rate / 100)) : Number(g) || 0 });
function vatLine(m) {
    const g = Math.round(m.g), n = Math.round(m.n);
    if (!g) return '';
    return g === n ? t('בלי מע"מ') : t('{net} + מע"מ {vat}', { net: money(n), vat: money(g - n) });
}
function moneyVat(m) {
    const line = vatLine(m);
    return `<span class="mv"><span class="mv-g">${esc(money(m.g))}</span>${line ? `<small class="mv-n">${esc(line)}</small>` : ''}</span>`;
}
const longDate = iso => new Date(iso).toLocaleDateString(I18n.locale());
const shortDate = ymd => ymd ? ymd.split('-').reverse().slice(0, 2).join('.') : '';
const fmtSize = n => n < 1048576 ? Math.max(1, Math.round(n / 1024)) + 'KB' : (n / 1048576).toFixed(1) + 'MB';
const waNumber = s => { const d = String(s || '').replace(/\D/g, ''); return d.startsWith('0') ? '972' + d.slice(1) : d; };

const token = (location.hash.match(/t=([A-Za-z0-9_-]+)/) || [])[1] || '';
const fileUrl = (id, extra = '') => `api.php?action=file&id=${id}&t=${encodeURIComponent(token)}${extra}`;
let data = null, current = null, decision = null, signed = false;

/** המצב הכספי — ללקוח ולעו"ד: התקציב (ראשוני מול היום), סוכם, שולם, יתרה, והתשלום הבא. */
function renderFinance() {
    const F = data.finance, T = F?.totals;
    $('#finance').hidden = !T || (!T.committed.g && !T.budget_today.g);
    if ($('#finance').hidden) return;
    const growth = Math.round(T.budget_growth.g);
    const row = (label, m, extra = '') => `<div><dt>${esc(label)}</dt><dd>${moneyVat(m)}${extra ? `<small class="kpi-x">${extra}</small>` : ''}</dd></div>`;
    const n = T.next;
    const when = n ? (n.due_kind === 'advance' ? t('מקדמה — בתחילת העבודה') : n.due_kind === 'handover' ? t('במסירה') : n.due_kind === 'stage_done' ? t('בגמר השלב') : n.due_kind === 'progress' ? t('בהתקדמות {n}%', { n: n.due_pct }) : n.due_date ? t('עד {date}', { date: shortDate(n.due_date) }) : '') : '';
    const fee = F.fee ? `<p class="fin-next">💼 ${esc(t('שכר הטרחה — {name}', { name: F.fee.name }))}: <b>${esc(money(F.fee.amount.g))}</b>
            · ${esc(t('שולם {paid} · יתרה {balance}', { paid: money(F.fee.paid.g), balance: money(F.fee.balance.g) }))}<small class="mv-n">${esc(vatLine(F.fee.amount))}</small></p>` : '';
    $('#finance').innerHTML = `<div class="sec-row"><h2 class="sec-title" id="finance-title">${esc(t('המצב הכספי'))}</h2>
            <button type="button" class="btn ghost sm" data-fin-report>📄 ${esc(t('דוח כספי'))}</button></div>
        <dl class="fin-kpis">
            ${row(t('תקציב ראשוני'), T.budget_initial)}
            ${row(t('תקציב היום'), T.budget_today, growth > 0 ? `<span class="fin-late">${esc(t('גדל ב-{amount}', { amount: money(growth) }))}</span>` : '')}
            ${row(t('סוכם (התחייבויות)'), T.committed, F.fee ? esc(t('כולל שכר טרחה {amount}', { amount: money(F.fee.amount.g) })) : '')}
            ${row(t('שולם'), T.paid, T.committed.g ? esc(t('{n}% מהסוכם · בוצע {p}%', { n: T.paid_pct, p: T.progress })) : '')}
            ${row(t('יתרה לתשלום'), T.balance, T.credit?.g ? `<span class="fin-ok">${esc(t('זיכוי אצל ספקים {amount}', { amount: money(T.credit.g) }))}</span>` : '')}
        </dl>
        ${n ? `<p class="fin-next">💳 ${esc(t('התשלום הבא'))}: <b>${esc(n.title)}</b> · ${esc(n.supplier)} · <b>${esc(money(n.amount.g))}</b>${when ? ` · ${esc(when)}` : ''}<small class="mv-n">${esc(vatLine(n.amount))}</small></p>` : ''}
        ${(F.alerts || []).length ? `<ul class="fin-alerts">${F.alerts.map(x => `<li class="fa-${x.level}"><span aria-hidden="true">${{ late: '⚠️', soon: '⏰', wait: '⏳', info: 'ℹ️' }[x.level]}</span><span class="fa-text">${esc(x.text)}</span></li>`).join('')}</ul>` : ''}
        ${fee}
        ${(F.stages || []).some(s => s.rows.some(r => r.flags.includes('shared'))) ? `<p class="hint">${esc(t('שלבים משותפים שעוד לא חולקו בחוזה בין הדירות — לא נכללים בסכומים שלמעלה'))}</p>` : ''}
        ${F.settings.retention_pct ? `<p class="hint">${esc(t('עיכבון לפי החוזה: {n}% מכל תשלום נשאר אצלכם עד המסירה', { n: F.settings.retention_pct }))}</p>` : ''}`;
}

function toast(msg, isErr = false) {
    const t = $('#toast');
    t.textContent = msg;
    t.classList.toggle('err', isErr);
    t.hidden = false;
    clearTimeout(toast.timer);
    toast.timer = setTimeout(() => { t.hidden = true; }, 3500);
}

async function call(action, body) {
    const opts = { headers: { Accept: 'application/json', 'X-Portal': token, 'X-Lang': I18n.lang() } };
    if (body) { opts.method = 'POST'; opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    const res = await fetch('api.php?action=' + action, opts);
    let d = {};
    try { d = await res.json(); } catch { /* לא JSON */ }
    if (!res.ok) { const e = new Error(d.error || t('תקלה ({status})', { status: res.status })); e.status = res.status; throw e; }
    return d;
}

async function load() {
    await I18n.ready;
    $('header.top').appendChild(I18n.picker(undefined, true));
    if (!token) return fail(t('בקישור חסר החלק האישי. פתחו אותו שוב מההודעה שקיבלתם.'));
    try { data = await call('view'); }
    catch (e) { return e.status === 404 ? fail(e.message) : fail(t('אין חיבור לשרת. נסו שוב בעוד רגע.')); }
    // בפעם הראשונה — בשפה של איש הקשר (I18n.set טוען את הדף מחדש)
    if (!I18n.chosen() && I18n.LANGS[data.lang] && data.lang !== I18n.lang()) return I18n.set(data.lang);
    render();
}

function fail(msg) {
    $('#loading').hidden = true;
    $('#content').hidden = true;
    $('#fail').hidden = false;
    $('#fail-text').textContent = msg;
}

const roleName = () => data.role === 'client' ? t('לקוח') : t('עורך דין');
// התוויות (data.labels) כבר בשפת הבקשה — מהשרת
const stageLabel = s => data.labels.stage[s] || s;
const quoteLabel = s => ({ sent: t('ממתינה להחלטה'), client_approved: data.role === 'lawyer' ? t('הלקוח אישר — ממתינה לך') : t('אישרת — ממתינה לעו"ד') }[s] || data.labels.quote[s] || s);

/** שם השלב, עם היחידה במתחם: "חשמל · דירה א'". */
const sn = s => t(s.name) + (s.unit_name ? ' · ' + s.unit_name : '');

function render() {
    $('#loading').hidden = true;
    $('#content').hidden = false;
    const p = data.project;
    $('#title').textContent = p.name;
    document.title = p.name;
    $('#subtitle').textContent = [t('שלום {name}', { name: data.name }), (data.units || []).length ? '🏢 ' + data.units.join(', ') : '', p.address].filter(Boolean).join(' · ');
    $('#subtitle').hidden = false;
    // לקוח / עו"ד נוסף שמנהל הפרויקט הגביל — רואה הכול, מעלה מסמכים, ולא מאשר ולא משלם
    $('#readonly-note').hidden = !data.readonly;
    const sup = data.role === 'supplier';
    for (const id of ['#s-stages', '#s-payments', '#s-bank', '#s-ledger']) $(id).hidden = !sup;
    if (sup) $('#expenses').hidden = true;
    if (sup) { renderLedger(); $('#field').hidden = $('#quotes').hidden = $('#payments').hidden = true; $('#progress').closest('section').hidden = true; renderSupplier(); renderCommon(); return; }
    $('#progress').closest('section').hidden = false;

    // ---- ממתין לאישורך: שלב אחד = שורה אחת ----
    const waiting = new Map();
    data.quotes.filter(q => q.can_decide && (q.status === 'sent' || q.status === 'client_approved')).forEach(q => waiting.set(q.stage_id, (waiting.get(q.stage_id) || 0) + 1));
    // תשלומים שממתינים לי: עו"ד — לאישור; משלם — לתשלום
    const payNow = data.payments.filter(p => p.can_approve || (p.can_pay && p.payer === data.role));
    const chWait = (data.changes || []).filter(c => c.can_decide);
    const bWait = data.budget?.can_decide ? data.budget.pending : null;
    $('#pending').hidden = !waiting.size && !payNow.length && !chWait.length && !bWait;
    $('#pending-list').innerHTML = [...waiting].map(([sid, n]) => {
        const st = data.stages.find(s => s.id === sid);
        return `<li class="card" data-go="stage-${sid}" tabindex="0" role="button">
            <span class="ficon" aria-hidden="true">✍️</span>
            <div class="body"><div class="l1"><span class="one">${esc(t(st?.name))}</span></div>
                <div class="l2">${esc(n > 1 ? t('{n} הצעות מחיר — לבחירה ואישור', { n }) : t('הצעת מחיר — לאישור'))}</div></div>
            <span class="go dir-flip" aria-hidden="true">‹</span></li>`;
    }).join('') + payNow.map(p => `<li class="card" data-go="pay-${p.id}" tabindex="0" role="button">
            <span class="ficon" aria-hidden="true">${p.can_approve ? '⚖️' : '💳'}</span>
            <div class="body"><div class="l1"><span class="one">${esc(p.title)}</span><span class="amt">${moneyVat(vatOf(p.status === 'partial' ? p.balance : p.due_amount, p.vat_rate))}</span></div>
                <div class="l2">${[p.can_approve ? t('דרישת תשלום — לאישורך') : t('לתשלום'), p.supplier_name, p.due_date ? t('עד {date}', { date: shortDate(p.due_date) }) : ''].filter(Boolean).map(esc).join(' · ')}</div></div>
            <span class="go dir-flip" aria-hidden="true">‹</span></li>`).join('') + chWait.map(c => `<li class="card" data-go="change-${c.id}" tabindex="0" role="button">
            <span class="ficon" aria-hidden="true">✍️</span>
            <div class="body"><div class="l1"><span class="one">${esc(c.amount > 0 ? t('תוספת לאישורך') : t('הפחתה לאישורך'))}</span><span class="amt">${moneyVat(vatOf(c.amount, c.vat_rate))}</span></div>
                <div class="l2">${esc(c.supplier)} · ${esc(t(c.stage))}</div></div>
            <span class="go dir-flip" aria-hidden="true">‹</span></li>`).join('') + (bWait ? `<li class="card" data-go="budget" tabindex="0" role="button">
            <span class="ficon" aria-hidden="true">📐</span>
            <div class="body"><div class="l1"><span class="one">${esc(bWait.kind === 'initial' ? t('הצעת התקציב — לאישורך') : t('שינוי בתקציב — לאישורך'))}</span><span class="amt">${esc(money(bWait.total_to))}</span></div>
                <div class="l2">${esc(bWait.kind === 'initial' ? t('לפי שלבים') : bWait.note)}</div></div>
            <span class="go dir-flip" aria-hidden="true">‹</span></li>` : '');
    renderChanges();
    renderBudget();

    renderFinance();
    renderExpenses();

    // ---- התקדמות ----
    const s = data.stages;
    const done = s.filter(x => ['done', 'paid'].includes(x.status)).length;
    const pct = s.length ? Math.round(done / s.length * 100) : 0;
    $('#progress span').style.width = pct + '%';
    $('#progress').setAttribute('aria-valuenow', String(pct));
    $('#progress').setAttribute('aria-label', t('{done} מתוך {n} שלבים בוצעו', { done, n: s.length }));
    $('#progress-text').textContent = s.length ? t('{done}/{n} בוצעו', { done, n: s.length }) : '';
    $('#stage-list').innerHTML = s.map((x, i) => `<li class="card static">
        <span class="num st-${esc(x.status)}" aria-hidden="true">${i + 1}</span>
        <div class="body"><div class="l1"><span class="one">${esc(sn(x))}</span></div>${stageMeta(x)}</div>
        <span class="chip st-${esc(x.status)}">${esc(stageLabel(x.status))}</span></li>`).join('') || `<p class="empty">${esc(t('עוד אין שלבים.'))}</p>`;

    // ---- הצעות מחיר, לפי שלב: המומלצת בראש ----
    const byStage = new Map();
    data.quotes.forEach(q => { if (!byStage.has(q.stage_id)) byStage.set(q.stage_id, []); byStage.get(q.stage_id).push(q); });
    $('#quotes').hidden = !byStage.size;
    const rank = q => q.status === 'approved' ? 0 : q.status === 'client_approved' ? 1 : q.recommended ? 2 : ['declined', 'rejected'].includes(q.status) ? 4 : 3;
    $('#quote-list').innerHTML = s.filter(st => byStage.has(st.id)).map(st => {
        const list = byStage.get(st.id).sort((a, b) => rank(a) - rank(b) || a.amount - b.amount);
        const live = list.filter(q => !['declined', 'rejected'].includes(q.status));
        const low = live.length > 1 ? Math.min(...live.map(q => q.amount)) : null;
        return `<section class="q-stage" id="stage-${st.id}" aria-label="${esc(sn(st))}">
            <header class="q-head"><h3 class="one">${esc(sn(st))}</h3><span class="chip st-${esc(st.status)}">${esc(stageLabel(st.status))}</span></header>
            ${list.map(q => quoteCard(q, low)).join('')}
        </section>`;
    }).join('');

    // ---- מהשטח: העדכונים הגלויים, החדש למעלה ----
    $('#field').hidden = !data.reports.length;
    drawReports();

    // ---- תשלומים ----
    $('#payments').hidden = !data.payments.length;
    $('#payment-list').innerHTML = data.payments.map(payCard).join('');

    renderCommon();
}

/** קבצים ומנהל הפרויקט — לכל התפקידים. */
function renderCommon() {
    // ---- מסמכים ששלחתי ("📎 העלאת מסמך") ----
    const ups = data.uploads || [];
    const stName = id => { const s = data.stages.find(x => x.id === id); return s ? sn(s) : ''; };
    $('#uploads').hidden = false;
    $('#u-empty').hidden = !!ups.length;
    $('#upload-list').innerHTML = ups.map(f => `<li class="card">
        ${f.thumb ? `<img class="fthumb" src="${fileUrl(f.id, '&thumb=1')}" alt="" loading="lazy" width="40" height="40">` : `<span class="ficon" aria-hidden="true">📄</span>`}
        <div class="body"><div class="l1"><span class="one">${esc(f.name)}</span></div>
            <div class="l2">${[f.stage_id ? esc(stName(f.stage_id)) : '', fmtSize(f.size), longDate(f.at)].filter(Boolean).join('<i class="sep" aria-hidden="true">·</i>')}</div></div>
        <a class="icon-btn" href="${fileUrl(f.id)}" target="_blank" rel="noopener" aria-label="${esc(t('פתיחת {name}', { name: f.name }))}">↗</a></li>`).join('');

    // ---- קבצים (בלי מה שכבר מופיע ב"מסמכים ששלחתי") ----
    const mine = new Set(ups.map(f => f.id));
    const files = data.files.filter(f => !mine.has(f.id));
    $('#files').hidden = !files.length;
    $('#file-list').innerHTML = files.map(f => `<li class="card">
        ${f.thumb ? `<img class="fthumb" src="${fileUrl(f.id, '&thumb=1')}" alt="" loading="lazy" width="40" height="40">` : `<span class="ficon" aria-hidden="true">📄</span>`}
        <div class="body"><div class="l1"><span class="one">${esc(f.name)}</span></div>
            <div class="l2">${[esc(data.labels.file[f.type] || ''), fmtSize(f.size), longDate(f.at)].filter(Boolean).join('<i class="sep" aria-hidden="true">·</i>')}</div></div>
        <a class="icon-btn" href="${fileUrl(f.id)}" target="_blank" rel="noopener" aria-label="${esc(t('פתיחת {name}', { name: f.name }))}">↗</a></li>`).join('');

    // ---- מנהל הפרויקט ----
    const m = data.manager;
    $('#manager').innerHTML = `<span>${esc(t('מנהל הפרויקט:'))} <b>${esc(m.name)}</b></span>
        ${m.phone ? `<a class="icon-btn" href="tel:${esc(m.phone.replace(/[^\d+]/g, ''))}" aria-label="${esc(t('חיוג למנהל הפרויקט'))}">📞</a>
            <a class="icon-btn" href="https://wa.me/${esc(waNumber(m.phone))}" target="_blank" rel="noopener" aria-label="${esc(t('וואטסאפ למנהל הפרויקט'))}">💬</a>` : ''}
        ${m.email ? `<a class="icon-btn" href="mailto:${esc(m.email)}" aria-label="${esc(t('אימייל למנהל הפרויקט'))}">✉️</a>` : ''}`;
}

/** שורה שנייה בשלב — רק כשיש מה להראות (ספק, תאריכים). */
function stageMeta(x) {
    const m = [x.supplier ? esc(x.supplier) : '', x.plan_start || x.plan_end ? `${shortDate(x.plan_start)}–${shortDate(x.plan_end)}` : '']
        .filter(Boolean).join('<i class="sep" aria-hidden="true">·</i>');
    return m ? `<div class="l2 one">${m}</div>` : '';
}

function quoteCard(q, low) {
    const dead = ['declined', 'rejected'].includes(q.status);
    const diff = low !== null && !dead ? (q.amount === low ? `<b class="cheap">${esc(t('הזולה'))}</b>` : `+${Math.round((q.amount - low) / low * 100)}%`) : '';
    const meta = [
        q.company && q.company !== q.supplier ? esc(q.company) : '',
        diff,
        q.valid_until ? esc(t('בתוקף עד {date}', { date: shortDate(q.valid_until) })) : '',
    ].filter(Boolean).join('<i class="sep" aria-hidden="true">·</i>');
    const last = q.decisions.filter(d => d.version === q.version).slice(-1)[0];
    const btns = q.pending_setup ? `<p class="q3 muted">${esc(t('ממתין להשלמת פרטי הספק אצל מנהל הפרויקט — אחר כך אפשר יהיה לאשר.'))}</p>`
        : q.can_decide ? `<div class="row-btns">
            <button class="btn ${q.recommended || q.status === 'client_approved' ? 'primary' : 'ghost'}" data-act="approved" data-q="${q.id}">✅ ${esc(t('אישור'))}</button>
            ${q.can_reject ? `<button class="btn ghost" data-act="rejected" data-q="${q.id}">${esc(t('דחייה'))}</button>` : ''}</div>` : '';
    return `<article class="qcard q-${esc(q.status)}${q.recommended ? ' rec' : ''}">
        ${q.recommended ? `<p class="rec-tag">⭐ ${esc(t('ההמלצה של מנהל הפרויקט'))}</p>` : ''}
        <div class="q1"><span class="one">${esc(q.supplier)}</span><span class="amt">${moneyVat(vatOf(q.amount, q.vat_rate))}</span></div>
        <div class="q2">${meta}${meta ? '<i class="sep" aria-hidden="true">·</i>' : ''}<span class="st">${esc(quoteLabel(q.status))}</span></div>
        ${q.terms ? `<p class="q3">${esc(t('תנאי תשלום: {terms}', { terms: q.terms }))}</p>` : ''}
        ${q.notes ? `<p class="q3">${esc(q.notes)}</p>` : ''}
        ${last ? `<p class="q3 muted">${last.decision === 'approved' ? '✅' : '✖'} ${esc(last.by_name)} · ${longDate(last.at)}${last.note ? ' · ' + esc(last.note) : ''}</p>` : ''}
        ${q.file ? `<a class="btn ghost sm file-link" href="${fileUrl(q.file.id)}" target="_blank" rel="noopener">📄 ${esc(t('צפייה בהצעה'))}</a>` : ''}
        ${btns}
    </article>`;
}

let reportsShown = 5;

function drawReports() {
    const list = data.reports.slice(0, reportsShown);
    $('#report-list').innerHTML = list.map(r => {
        const st = r.stage_id ? data.stages.find(s => s.id === r.stage_id) : null;
        const name = st?.name || r.stage_name || t('כללי');
        const media = r.media.map((m, i) => `<li><button type="button" class="tile" data-report="${r.id}" data-media="${i}" aria-label="${esc(m.video ? t('סרטון {num}', { num: i + 1 }) : t('תמונה {num}', { num: i + 1 }))}">
            ${m.thumb ? `<img src="${fileUrl(m.id, '&thumb=1')}" alt="" loading="lazy">` : `<span aria-hidden="true">${m.video ? '🎬' : '🖼️'}</span>`}
            ${m.video ? '<i class="play" aria-hidden="true">▶</i>' : ''}</button></li>`).join('');
        return `<article class="report${r.kind === 'done' ? ' done' : ''}">
            <header><span class="one">${r.kind === 'done' ? '✅ ' + esc(t('{stage} — בוצע', { stage: name })) : esc(t(name))}</span>
                <time datetime="${esc(r.at)}">${longDate(r.at)}</time></header>
            ${r.text ? `<p>${esc(r.text)}</p>` : ''}
            ${media ? `<ul class="media-grid">${media}</ul>` : ''}
        </article>`;
    }).join('');
    $('#more-reports').hidden = data.reports.length <= reportsShown;
}

// ================= ספק =================

function renderSupplier() {
    const quotesOf = id => data.my_quotes.filter(q => q.stage_id === id);
    const invited = data.stages.filter(s => s.can_quote && !quotesOf(s.id).some(q => !['declined', 'rejected', 'cancelled'].includes(q.status)));
    const confirm = data.payments.flatMap(p => p.proofs.filter(x => x.can_confirm).map(x => ({ p, x })));
    $('#pending').hidden = !invited.length && !confirm.length;
    $('#pending-list').innerHTML = invited.map(s => `<li class="card" data-go="sstage-${s.id}" tabindex="0" role="button">
            <span class="ficon" aria-hidden="true">📝</span>
            <div class="body"><div class="l1"><span class="one">${esc(sn(s))}</span></div><div class="l2">${esc(t('מבקשים ממך הצעת מחיר'))}</div></div>
            <span class="go dir-flip" aria-hidden="true">‹</span></li>`).join('')
        + confirm.map(({ p, x }) => `<li class="card" data-go="spay-${p.id}" tabindex="0" role="button">
            <span class="ficon" aria-hidden="true">💳</span>
            <div class="body"><div class="l1"><span class="one">${esc(p.title)}</span><span class="amt">${moneyVat(vatOf(x.amount, x.vat_rate))}</span></div>
                <div class="l2">${esc(t('דווח ששולם לך — לאשר שהתקבל'))}</div></div>
            <span class="go dir-flip" aria-hidden="true">‹</span></li>`).join('');

    $('#s-stage-list').innerHTML = data.stages.map(st => {
        const qs = quotesOf(st.id);
        return `<article class="qcard" id="sstage-${st.id}">
            <div class="q1"><span class="one">${esc(sn(st))}</span><span class="chip st-${esc(st.status)}">${esc(data.labels.stage[st.status] || st.status)}</span></div>
            <div class="q2">${[st.assigned ? esc(t('שלך')) : st.invited ? esc(t('הוזמנת להציע')) : '', st.plan_start || st.plan_end ? `${shortDate(st.plan_start)}–${shortDate(st.plan_end)}` : ''].filter(Boolean).join('<i class="sep" aria-hidden="true">·</i>')}</div>
            ${(data.changes || []).filter(c => c.stage_id === st.id).map(c => `<p class="q3">➕ ${esc(c.amount > 0 ? t('תוספת שאושרה') : t('הפחתה שאושרה'))}: <b>${esc(money(c.amount))}</b> · ${esc(c.reason)}</p>`).join('')}
            ${qs.map(q => `<p class="q3">📝 ${esc(t('ההצעה שלך:'))} <b>${esc(money(q.amount))}</b> · ${esc(q.label)} · ${longDate(q.at)}
                ${q.file ? ` · <a href="${fileUrl(q.file.id)}" target="_blank" rel="noopener">${esc(t('הקובץ'))}</a>` : ''}</p>`).join('')}
            ${st.can_quote ? `<div class="row-btns"><button class="btn ${qs.length ? 'ghost' : 'primary'}" data-squote="${st.id}">📝 ${esc(qs.length ? t('הצעה מעודכנת') : t('הגשת הצעת מחיר'))}</button></div>` : ''}
        </article>`;
    }).join('') || `<p class="empty">${esc(t('עוד אין שלבים שלך בפרויקט.'))}</p>`;

    $('#s-payment-list').innerHTML = data.payments.map(p => {
        const st = p.stage_id ? data.stages.find(s => s.id === p.stage_id) : null;
        const label = p.supplier_request && p.status === 'planned' ? t('בקשתך — אצל מנהל הפרויקט') : p.label;
        const proofs = p.proofs.map(x => `<li>${x.status === 'received' ? '✅' : x.status === 'rejected' ? '✖' : '⏳'} ${esc(money(x.amount))} · ${esc(data.labels.method[x.method] || '')} · ${esc(shortDate(x.paid_on))}
            ${x.file ? ` · <a href="${fileUrl(x.file.id)}" target="_blank" rel="noopener">${esc(t('אסמכתא'))}</a>` : ''}
            ${x.can_confirm ? `<div class="row-btns"><button class="btn primary sm" data-receipt="${x.id}" data-ok="1">✅ ${esc(t('התקבל'))}</button><button class="btn ghost sm" data-receipt="${x.id}" data-ok="0">✖ ${esc(t('לא התקבל'))}</button></div>` : ''}</li>`).join('');
        return `<article class="qcard pay-${esc(p.status)}" id="spay-${p.id}">
            <div class="q1"><span class="one">${esc(p.title)}${st ? ` · ${esc(sn(st))}` : ''}</span><span class="amt">${moneyVat(vatOf(p.due_amount, p.vat_rate))}</span></div>
            <div class="q2">${esc(label)}${p.due_date ? `<i class="sep" aria-hidden="true">·</i>${esc(t('עד {date}', { date: shortDate(p.due_date) }))}` : ''}${p.received ? `<i class="sep" aria-hidden="true">·</i>${esc(t('התקבל {amount}', { amount: money(p.received) }))}` : ''}</div>
            ${proofs ? `<ul class="proofs">${proofs}</ul>` : ''}
            ${p.retention && p.kind !== 'retention' ? `<p class="q3">${esc(t('מתוך {total} — עיכבון {amount} נשאר עד המסירה', { total: money(p.amount), amount: money(p.retention) }))}${p.index_diff ? ' · ' + esc(t('הפרשי הצמדה {amount}', { amount: money(p.index_diff) })) : ''}</p>` : ''}
            ${p.invoice ? `<p class="q3">🧾 ${esc(p.invoice.no ? t('התקבלה חשבונית {no}', { no: p.invoice.no }) : t('התקבלה חשבונית'))}</p>`
                : p.received ? `<div class="row-btns"><button class="btn ${p.status === 'received' ? 'primary' : 'ghost'} sm" data-sinv="${p.id}">🧾 ${esc(t('העלאת חשבונית / קבלה'))}</button></div>` : ''}
        </article>`;
    }).join('') || `<p class="empty">${esc(t('עוד אין תשלומים.'))}</p>`;

    const b = data.bank || {};
    const f = $('#s-bank-form').elements;
    for (const k of ['bank', 'branch', 'account', 'holder']) f[k].value = b[k] || '';
    $('#s-bank-sum').textContent = b.account ? `· ${b.bank || ''} ${b.account}` : '· ' + t('חסרים — מלאו כדי שהלקוח יוכל לשלם');
    $('#s-bank details, #s-bank .bank-box').open = !b.account;
}

let sMode = null, sStage = null, sFile = null;

function openSupplierDlg(mode, stageId) {
    sMode = mode; sStage = stageId; sFile = null;
    const dlg = $('#s-dlg'), form = $('form', dlg), el = form.elements;
    form.reset();
    $('.error', form).hidden = true;
    $('#s-file-name').textContent = '';
    const quote = mode === 'quote';
    const st = data.stages.find(s => s.id === stageId);
    $('#s-dlg-title').textContent = quote ? t('הצעת מחיר — {stage}', { stage: st.name }) : t('בקשת תשלום');
    $('#s-stage-box').hidden = quote;
    el.stage_id.innerHTML = `<option value="">${esc(t('בלי שלב'))}</option>` + data.stages.map(s => `<option value="${s.id}">${esc(sn(s))}</option>`).join('');
    $('#s-title-box').hidden = quote;
    $('#s-titles').innerHTML = [t('מקדמה'), t('תשלום ביניים'), t('תשלום סופי'), t('תוספת')].map(v => `<option value="${esc(v)}">`).join('');
    $('#s-valid-box').hidden = $('#s-terms-box').hidden = !quote;
    $('#s-file-label').textContent = quote ? t('טופס ההצעה (PDF או צילום) *') : t('חשבון / פירוט (לא חובה)');
    $('#s-go').textContent = quote ? t('שליחת ההצעה') : t('שליחת הבקשה');
    dlg.showModal();
}

async function submitSupplier(e) {
    e.preventDefault();
    const form = $('#s-dlg form'), el = form.elements, err = $('.error', form);
    err.hidden = true;
    if (!el.amount.value.trim()) { err.textContent = t('סכום: שדה חובה'); err.hidden = false; return; }
    if (sMode === 'payreq' && !el.title.value.trim()) { err.textContent = t('על מה התשלום?'); err.hidden = false; return; }
    if (sMode === 'quote' && !sFile) { err.textContent = t('צריך לצרף את טופס הצעת המחיר (PDF או צילום)'); err.hidden = false; return; }
    const btn = $('#s-go');
    btn.disabled = true;
    try {
        const file = sFile ? await shrink(sFile) : null;
        if (file && file.size > data.max_proof) throw new Error(t('הקובץ גדול מדי — עד 8MB'));
        const qs = new URLSearchParams(sMode === 'quote'
            ? { action: 's-quote', stage_id: sStage, amount: el.amount.value.trim(), valid_until: el.valid_until.value, terms: el.terms.value, notes: el.notes.value }
            : { action: 's-payreq', stage_id: el.stage_id.value, title: el.title.value.trim(), amount: el.amount.value.trim(), notes: el.notes.value });
        if (file) qs.set('name', file.name);
        const res = await fetch('api.php?' + qs, { method: 'POST', body: file || undefined,
            headers: { 'X-Portal': token, 'X-Lang': I18n.lang(), Accept: 'application/json', 'Content-Type': file ? (file.type || 'application/octet-stream') : 'application/octet-stream' } });
        const d = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(d.error || t('תקלה ({status})', { status: res.status }));
        data = d;
        $('#s-dlg').close();
        render();
        toast(sMode === 'quote' ? t('ההצעה נשלחה למנהל הפרויקט') : t('הבקשה נשלחה למנהל הפרויקט'));
    } catch (x) {
        err.textContent = x.message;
        err.hidden = false;
    } finally { btn.disabled = false; }
}

/** הספק: חשבונית / קבלה על תשלום שהתקבל — מספר (לא חובה) וקובץ. */
function supplierInvoice(paymentId) {
    const no = prompt(t('מספר החשבונית (לא חובה)'), '');
    if (no === null) return;
    const input = $('#s-inv-file');
    input.value = '';
    input.onchange = async () => {
        const f = input.files[0];
        if (!f) return;
        try {
            const file = await shrink(f);
            if (file.size > data.max_proof) throw new Error(t('הקובץ גדול מדי — עד 8MB'));
            const qs = new URLSearchParams({ action: 's-invoice', payment_id: paymentId, no, name: file.name });
            const res = await fetch('api.php?' + qs, { method: 'POST', body: file,
                headers: { 'X-Portal': token, 'X-Lang': I18n.lang(), Accept: 'application/json', 'Content-Type': file.type || 'application/octet-stream' } });
            const d = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(d.error || t('תקלה ({status})', { status: res.status }));
            data = d;
            render();
            toast(t('החשבונית נשלחה למנהל הפרויקט'));
        } catch (x) { toast(x.message, true); }
    };
    input.click();
}

async function receipt(proofId, ok) {
    const x = data.payments.flatMap(p => p.proofs).find(y => y.id === proofId);
    let received = null, note = '';
    if (ok) { received = prompt(t('כמה התקבל בפועל? (₪)'), String(x.amount)); if (received === null) return; }
    else { note = prompt(t('מה הבעיה? (מנהל הפרויקט יקבל את זה)'), ''); if (note === null) return; }
    try { data = await call('s-receipt', { proof_id: proofId, ok, received, note }); render(); toast(ok ? t('תודה — נרשם שהתקבל') : t('נרשם — מנהל הפרויקט מעודכן')); }
    catch (e) { toast(e.message, true); }
}

async function saveBank(e) {
    e.preventDefault();
    const f = e.target.elements;
    try { data = await call('s-bank', { bank: f.bank.value, branch: f.branch.value, account: f.account.value, holder: f.holder.value }); render(); toast(t('פרטי הבנק נשמרו')); }
    catch (x) { toast(x.message, true); }
}

const PAY_ICON = { requested: '📤', paid: '💳', partial: '◐', received: '✅' };

function payCard(p) {
    const st = p.stage_id ? data.stages.find(s => s.id === p.stage_id) : null;
    const late = p.due_date && p.due_date < new Date().toISOString().slice(0, 10) && !['received'].includes(p.status);
    const b = p.bank?.bank || {};
    const bank = p.bank && (b.bank || b.account) ? `<dl class="bank">
            <dt>${esc(t('לטובת'))}</dt><dd>${esc(b.holder || p.bank.company || p.bank.name)}</dd>
            ${b.bank ? `<dt>${esc(t('בנק'))}</dt><dd>${esc(b.bank)}</dd>` : ''}${b.branch ? `<dt>${esc(t('סניף'))}</dt><dd>${esc(b.branch)}</dd>` : ''}
            ${b.account ? `<dt>${esc(t('חשבון'))}</dt><dd><span dir="ltr">${esc(b.account)}</span> <button type="button" class="btn ghost sm" data-copy="${esc(b.account)}">${esc(t('העתקה'))}</button></dd>` : ''}
            ${p.bank.tax_id ? `<dt>${esc(t('ח.פ.'))}</dt><dd>${esc(p.bank.tax_id)}</dd>` : ''}</dl>`
        : p.can_pay ? `<p class="q3 muted">${esc(t('פרטי הבנק — אצל הספק או מנהל הפרויקט.'))}</p>` : '';
    const proofs = p.proofs.map(x => `<li>${x.status === 'received' ? '✅' : x.status === 'rejected' ? '✖' : '⏳'} ${esc(money(x.amount))} · ${esc(data.labels.method[x.method] || '')} · ${esc(shortDate(x.paid_on))}
        — ${esc(x.status === 'pending' ? t('ממתין לאישור קבלה') : x.status === 'received' ? t('התקבל') : x.review_note ? t('לא התקבל: {note}', { note: x.review_note }) : t('לא התקבל'))}
        ${x.file ? ` · <a href="${fileUrl(x.file.id)}" target="_blank" rel="noopener">${esc(t('אסמכתא'))}</a>` : ''}</li>`).join('');
    const btns = [
        p.can_approve ? `<button class="btn primary" data-pay-approve="${p.id}">✅ ${esc(t('אישור לתשלום'))}</button>` : '',
        p.can_pay ? `<button class="btn ${p.payer === data.role ? 'primary' : 'ghost'}" data-pay="${p.id}">💳 ${esc(t('שילמתי — העלאת אסמכתא'))}</button>` : '',
    ].filter(Boolean).join('');
    return `<article class="qcard pay-${esc(p.status)}" id="pay-${p.id}">
        <div class="q1"><span class="one">${esc(p.title)}${st ? ` · ${esc(sn(st))}` : ''}</span><span class="amt">${moneyVat(vatOf(p.due_amount, p.vat_rate))}</span></div>
        <div class="q2">${esc(p.supplier_name)}<i class="sep" aria-hidden="true">·</i>${PAY_ICON[p.status] || ''} ${esc(p.label)}${p.due_date ? `<i class="sep" aria-hidden="true">·</i><span class="${late ? 'late' : ''}">${esc(t('עד {date}', { date: shortDate(p.due_date) }))}</span>` : ''}</div>
        ${p.status === 'partial' ? `<p class="q3">${esc(t('התקבל {amount}', { amount: money(p.received) }))} · ${esc(t('נשאר'))} <b>${esc(money(p.balance))}</b></p>` : ''}
        ${p.retention && p.kind !== 'retention' ? `<p class="q3">${esc(t('מתוך {amount} — {r} נשארים אצלכם כעיכבון עד המסירה', { amount: money(p.amount), r: money(p.retention) }))}</p>` : ''}
        ${p.index_diff ? `<p class="q3">${esc(t('כולל הפרשי הצמדה {amount}', { amount: money(p.index_diff) }))}</p>` : ''}
        ${p.notes ? `<p class="q3">${esc(p.notes)}</p>` : ''}
        ${p.lawyer ? `<p class="q3 muted">⚖️ ${esc(t('{name} אישר לתשלום', { name: p.lawyer.by }))} · ${longDate(p.lawyer.at)}</p>` : ''}
        ${bank}
        ${proofs ? `<ul class="proofs">${proofs}</ul>` : ''}
        ${btns ? `<div class="row-btns wrap">${btns}</div>` : ''}
    </article>`;
}

// ================= תשלומים =================

let payItem = null, proofFile = null;

function openPay(id) {
    payItem = data.payments.find(p => p.id === id);
    const dlg = $('#pay-dlg'), form = $('form', dlg), el = form.elements;
    form.reset();
    proofFile = null;
    $('#proof-name').textContent = '';
    $('.error', form).hidden = true;
    $('#pay-summary').innerHTML = `<dl><dt>${esc(t('עבור'))}</dt><dd>${esc(payItem.title)}</dd><dt>${esc(t('לספק'))}</dt><dd>${esc(payItem.supplier_name)}</dd>
        <dt>${esc(payItem.status === 'partial' ? t('נשאר') : t('סכום'))}</dt><dd><b>${esc(money(payItem.balance))}</b> ${esc(t('כולל מע"מ'))}</dd></dl>`;
    el.amount.value = String(payItem.balance);
    el.paid_on.value = new Date().toISOString().slice(0, 10);
    el.method.innerHTML = Object.entries(data.labels.method).map(([v, l]) => `<option value="${v}">${esc(l)}</option>`).join('');
    dlg.showModal();
}

/** תמונה — מוקטנת ל-2000px (JPEG) לפני השליחה; PDF כמו שהוא. */
/** "📎 העלאת מסמך" — ספק בוחר גם מקצוע (לא חובה). */
let uFile = null;
function openUpload() {
    const f = $('#u-dlg form');
    f.reset();
    $('.error', f).hidden = true;
    uFile = null;
    $('#u-file-name').textContent = '';
    const sup = data.role === 'supplier';
    $('#u-stage-box').hidden = !sup || !data.stages.length;
    if (sup) f.elements.stage_id.innerHTML = `<option value="">${esc(t('כללי — לא למקצוע מסוים'))}</option>` + data.stages.map(s => `<option value="${s.id}">${esc(sn(s))}</option>`).join('');
    if (sup && data.stages.length === 1) f.elements.stage_id.value = String(data.stages[0].id);
    $('#u-dlg').showModal();
}

async function submitUpload(e) {
    e.preventDefault();
    const f = $('#u-dlg form'), el = f.elements, err = $('.error', f);
    const fail = m => { err.textContent = m; err.hidden = false; };
    if (!uFile) return fail(t('בחרו קובץ — צילום או PDF'));
    const btn = $('#u-go');
    btn.disabled = true;
    try {
        const file = await shrink(uFile);
        if (file.size > data.max_proof) throw new Error(t('הקובץ גדול מדי — עד 8MB'));
        const qs = new URLSearchParams({ action: 'upload', name: file.name, note: el.note.value.trim(), stage_id: el.stage_id.value || '' });
        const res = await fetch('api.php?' + qs, { method: 'POST', body: file,
            headers: { 'X-Portal': token, 'X-Lang': I18n.lang(), Accept: 'application/json', 'Content-Type': file.type || 'application/octet-stream' } });
        const d = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(d.error || t('תקלה ({status})', { status: res.status }));
        data = d;
        $('#u-dlg').close();
        render();
        toast(t('המסמך נשלח למנהל הפרויקט'));
    } catch (x) { fail(x.message); } finally { btn.disabled = false; }
}

async function shrink(file) {
    if (!file.type.startsWith('image/') || !('createImageBitmap' in window)) return file;
    try {
        const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const scale = Math.min(1, 2000 / Math.max(bmp.width, bmp.height));
        if (scale === 1 && file.size < 1.5 * 1048576) return file;
        const c = document.createElement('canvas');
        c.width = Math.round(bmp.width * scale); c.height = Math.round(bmp.height * scale);
        c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
        const blob = await new Promise(r => c.toBlob(r, 'image/jpeg', 0.85));
        return blob ? new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file;
    } catch { return file; }
}

async function submitPay(e) {
    e.preventDefault();
    const form = $('#pay-dlg form'), el = form.elements, err = $('.error', form);
    err.hidden = true;
    const amount = el.amount.value.trim();
    if (!amount) { err.textContent = t('כמה שילמתם?'); err.hidden = false; return; }
    if (!proofFile) { err.textContent = t('נדרשת אסמכתא — צילום ההעברה, הצ\'ק או אישור הבנק'); err.hidden = false; return; }
    const btn = $('#pay-go');
    btn.disabled = true;
    btn.textContent = t('שולח…');
    try {
        const file = await shrink(proofFile);
        if (file.size > data.max_proof) throw new Error(t('הקובץ גדול מדי — עד 8MB'));
        const qs = new URLSearchParams({ action: 'proof', payment_id: payItem.id, amount, paid_on: el.paid_on.value, method: el.method.value, note: el.note.value, name: file.name });
        const res = await fetch('api.php?' + qs, { method: 'POST', headers: { 'X-Portal': token, 'X-Lang': I18n.lang(), 'Content-Type': file.type || 'application/octet-stream', Accept: 'application/json' }, body: file });
        const d = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(d.error || t('תקלה ({status})', { status: res.status }));
        data = d;
        $('#pay-dlg').close();
        render();
        toast(t('האסמכתא נשלחה. מנהל הפרויקט יאשר כשהכסף יתקבל.'));
    } catch (x) {
        err.textContent = x.message;
        err.hidden = false;
    } finally {
        btn.disabled = false;
        btn.textContent = t('שילמתי — שליחת האסמכתא');
    }
}

async function payApprove(id) {
    const p = data.payments.find(x => x.id === id);
    const note = prompt(t('לאשר את התשלום: {title} — {amount} ל{supplier}?\nהערה (לא חובה):', { title: p.title, amount: money(p.due_amount), supplier: p.supplier_name }), '');
    if (note === null) return;
    try {
        data = await call('pay-approve', { payment_id: id, note });
        render();
        toast(t('האישור נשמר. הלקוח יכול לשלם.'));
    } catch (x) { toast(x.message, true); }
}

// ================= אישור / דחייה =================

function openDecide(id, act) {
    current = data.quotes.find(q => q.id === id);
    decision = act;
    const st = data.stages.find(s => s.id === current.stage_id);
    const dlg = $('#decide-dlg'), form = $('form', dlg);
    form.reset();
    $('.error', form).hidden = true;
    const sign = act === 'approved' && data.approval_method === 'signature';
    $('#decide-title').textContent = act === 'approved' ? t('אישור הצעת מחיר') : t('דחיית הצעת מחיר');
    $('#decide-summary').innerHTML = `<dl>
        <dt>${esc(t('שלב'))}</dt><dd>${esc(t(st?.name))}</dd>
        <dt>${esc(t('ספק'))}</dt><dd>${esc(current.supplier)}${current.company && current.company !== current.supplier ? ' · ' + esc(current.company) : ''}</dd>
        <dt>${esc(t('סכום'))}</dt><dd><b>${esc(money(current.amount))}</b> ${esc(t('כולל מע"מ'))}</dd>
        ${current.terms ? `<dt>${esc(t('תנאי תשלום'))}</dt><dd>${esc(current.terms)}</dd>` : ''}
        ${current.valid_until ? `<dt>${esc(t('בתוקף עד'))}</dt><dd>${esc(shortDate(current.valid_until))}</dd>` : ''}</dl>`;
    $('#note-label').textContent = act === 'rejected' ? t('סיבה או הערה למנהל הפרויקט') : t('הערה (לא חובה)');
    $('#sign-box').hidden = !sign;
    // ההצהרה — משפט שלם לכל מקרה (חתימה / לחיצה, לקוח / עו"ד), בשביל התרגום
    const legal = { name: data.name, supplier: current.supplier, amount: money(current.amount) };
    const lawyer = data.role === 'lawyer';
    $('#decide-legal').textContent = act !== 'approved' ? t('ההצעה תסומן כנדחתה, ומנהל הפרויקט יקבל את ההערה.')
        : sign && lawyer ? t('בחתימתי אני, {name}, מאשר/ת את הצעת המחיר של {supplier} על סך {amount} כולל מע"מ, כעורך הדין של הלקוח. האישור נשמר עם התאריך, השעה ופרטי ההצעה כפי שהיא עכשיו.', legal)
        : sign ? t('בחתימתי אני, {name}, מאשר/ת את הצעת המחיר של {supplier} על סך {amount} כולל מע"מ. האישור נשמר עם התאריך, השעה ופרטי ההצעה כפי שהיא עכשיו.', legal)
        : lawyer ? t('בלחיצה על "אישור" אני, {name}, מאשר/ת את הצעת המחיר של {supplier} על סך {amount} כולל מע"מ, כעורך הדין של הלקוח. האישור נשמר עם התאריך, השעה ופרטי ההצעה כפי שהיא עכשיו.', legal)
        : t('בלחיצה על "אישור" אני, {name}, מאשר/ת את הצעת המחיר של {supplier} על סך {amount} כולל מע"מ. האישור נשמר עם התאריך, השעה ופרטי ההצעה כפי שהיא עכשיו.', legal);
    $('#decide-go').textContent = act === 'approved' ? (sign ? t('חתימה ואישור') : t('אישור')) : t('דחיית ההצעה');
    $('#decide-go').className = 'btn ' + (act === 'approved' ? 'primary' : 'ghost danger');
    dlg.showModal();
    if (sign) setupSign();
}

/** הוצאות ישירות (כ5) — מה שהלקוח קנה בעצמו. הלקוח מוסיף; העו"ד רואה. */
function renderExpenses() {
    const X = data.finance?.expenses?.items || [];
    const client = data.role === 'client' && !data.readonly; // צפייה בלבד — בלי רישום הוצאה
    $('#expenses').hidden = !X.length && !client;
    $('#x-add').hidden = !client;
    $('#expense-list').innerHTML = X.map(x => `<article class="qcard">
        <div class="q1"><span class="one">🧾 ${esc(x.title)}</span><span class="amt">${moneyVat(x.amount)}</span></div>
        <div class="q2">${esc(shortDate(x.spent_on))}${x.stage ? `<i class="sep" aria-hidden="true">·</i>${esc(t(x.stage))}` : ''}${x.by_role === 'client' ? `<i class="sep" aria-hidden="true">·</i>${esc(t('נרשם על ידך'))}` : ''}
            ${x.file ? `<i class="sep" aria-hidden="true">·</i><a href="${fileUrl(x.file.id)}" target="_blank" rel="noopener">📎 ${esc(t('קבלה'))}</a>` : ''}</div>
    </article>`).join('') + (X.length ? `<p class="q-sum">${esc(t('סה"כ {amount}', { amount: money(data.finance.expenses.total.g) }))}</p>`
        : `<p class="empty">${esc(t('קניתם בעצמכם חומרים, או שילמתם אגרה? רשמו כאן — זה נכנס למצב הכספי.'))}</p>`);
}

let xFile = null;
function openExpense() {
    const f = $('#x-dlg form');
    f.reset();
    $('.error', f).hidden = true;
    xFile = null;
    $('#x-file-name').textContent = '';
    f.elements.spent_on.value = new Date().toISOString().slice(0, 10);
    f.elements.stage_id.innerHTML = `<option value="">${esc(t('בלי שלב'))}</option>` + data.stages.map(s => `<option value="${s.id}">${esc(sn(s))}</option>`).join('');
    $('#x-dlg').showModal();
}

async function submitExpense(e) {
    e.preventDefault();
    const f = $('#x-dlg form'), el = f.elements, err = $('.error', f);
    const fail = m => { err.textContent = m; err.hidden = false; };
    if (!el.title.value.trim()) return fail(t('מה קניתם: שדה חובה'));
    if (!(Number(el.amount.value.replace(/[,\s₪]/g, '')) > 0)) return fail(t('סכום: מספר חיובי'));
    const btn = $('#x-go');
    btn.disabled = true;
    try {
        const file = xFile ? await shrink(xFile) : null;
        if (file && file.size > data.max_proof) throw new Error(t('הקובץ גדול מדי — עד 8MB'));
        const qs = new URLSearchParams({ action: 'expense', title: el.title.value.trim(), amount: el.amount.value.replace(/[,\s₪]/g, ''), spent_on: el.spent_on.value,
            stage_id: el.stage_id.value, notes: el.notes.value.trim(), vat: el.vat.checked ? '1' : '0', name: file?.name || '' });
        const res = await fetch('api.php?' + qs, { method: 'POST', body: file || '',
            headers: { 'X-Portal': token, 'X-Lang': I18n.lang(), Accept: 'application/json', 'Content-Type': file?.type || 'application/octet-stream' } });
        const d = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(d.error || t('תקלה ({status})', { status: res.status }));
        data = d;
        $('#x-dlg').close();
        render();
        toast(t('ההוצאה נרשמה — מנהל הפרויקט מעודכן'));
    } catch (x) { fail(x.message); } finally { btn.disabled = false; }
}

/** הכרטסת של הספק (כ5): הצעה ← תוספות ← תשלומים ← חשבוניות, עם יתרה. */
function renderLedger() {
    const L = data.ledger;
    $('#s-ledger').hidden = !L || !L.entries.length;
    if ($('#s-ledger').hidden) return;
    const ICON = { quote: '📝', change: '±', index: '📈', milestone: '🗓️', paid: '💳', invoice: '🧾' };
    const cell = (label, m) => `<div><dt>${esc(label)}</dt><dd>${moneyVat(m)}</dd></div>`;
    $('#s-ledger-body').innerHTML = `<dl class="fin-kpis">${cell(t('סוכם'), L.committed)}${cell(t('שולם'), L.paid)}${cell(t('יתרה'), L.balance)}</dl>
        ${L.missing_invoices ? `<p class="hint fin-warn">${esc(t('תשלומים בלי חשבונית: {n}', { n: L.missing_invoices }))}</p>` : ''}
        <ol class="ledger">${L.entries.map(x => `<li class="lg-${x.kind}">
            <div class="lg-top"><span class="lg-ic" aria-hidden="true">${ICON[x.kind]}</span><b class="lg-text">${esc(x.text)}</b>
                <span class="lg-d${x.kind === 'milestone' ? ' muted' : ''}">${esc(x.delta.g ? (x.delta.g > 0 ? '+' : '−') + money(Math.abs(x.delta.g)) : x.kind === 'milestone' ? money(x.amount) : '')}</span></div>
            <div class="lg-meta">${[x.stage ? '🧱 ' + x.stage : '', x.when, x.kind === 'milestone' && !x.split ? x.label : '', shortDate(x.at)].filter(Boolean).map(esc).join(' · ')}</div>
            ${x.split ? `<div class="lg-split">
                <div><span>💳 ${esc(t('לתשלום ({n}%)', { n: Math.round((100 - x.split.pct) * 10) / 10 }))} · ${esc(x.label)}</span><b>${esc(money(x.split.pay_now))}</b></div>
                <div class="lg-ret${x.split.released ? ' ok' : ''}"><span>🔒 ${esc(t('עיכבון ({n}%) — {state}', { n: x.split.pct, state: x.split.state }))}</span><b>${esc(money(x.split.retention))}</b></div></div>` : ''}
            ${x.delta.g ? `<div class="lg-b">${esc(t('יתרה {amount}', { amount: (x.balance < 0 ? '−' : '') + money(Math.abs(x.balance)) }))}</div>` : ''}</li>`).join('')}</ol>`;
}

/** הצעת התקציב לפי שלבים: הסכום לכל שלב, הסה"כ, מה ממתין לאישור (אישור / דחייה), ויומן השינויים. */
function renderBudget() {
    const B = data.budget;
    $('#budget').hidden = !B;
    if (!B) return;
    const m = n => money(Math.round(n * 100) / 100);
    const groups = new Map();
    // ללקוח — רק שלבים שיש להם סכום
    for (const l of B.lines.filter(x => x.today || x.initial)) { const k = l.unit_name || ''; if (!groups.has(k)) groups.set(k, []); groups.get(k).push(l); }
    const rows = [...groups.entries()].map(([u, ls]) => `${groups.size > 1 ? `<h3 class="bd-unit">${esc(u ? '🏢 ' + u : t('משותף'))}</h3>` : ''}
        <ul class="list bd-rows">${ls.map(l => `<li class="bd-row bd-ro"><div class="bd-l1"><span class="one">${esc(t(l.name))}</span><b>${esc(m(l.today))}</b></div>
            ${B.approved_once && Math.abs(l.today - l.initial) >= 0.01 ? `<div class="bd-l2 muted">${esc(t('ראשוני {amount}', { amount: m(l.initial) }))}</div>` : ''}
            ${l.share ? `<div class="bd-l2 muted">${esc(t('החלק שלכם — {n}%', { n: l.share }))}</div>` : ''}</li>`).join('')}</ul>`).join('');
    const P = B.pending;
    const pend = P ? `<div class="bd-pending" id="budget-pending">
        <p><b>${esc(P.kind === 'initial' ? t('הצעת התקציב — ממתינה לאישור') : t('שינוי בתקציב — ממתין לאישור'))}</b>${P.note ? ' · ' + esc(P.note) : ''}</p>
        ${P.kind === 'change' ? `<ul class="list bd-chg">${P.lines.map(l => `<li><span class="one">${esc(t(l.name))}</span><b>${esc(m(l.from))} ← ${esc(m(l.to))}</b></li>`).join('')}</ul>` : ''}
        <p>${esc(P.kind === 'initial' ? t('סה"כ {amount}', { amount: m(P.total_to) }) : t('סה"כ {from} ← {to}', { from: m(P.total_from), to: m(P.total_to) }))}</p>
        ${B.can_decide ? `<div class="row-btns"><button class="btn primary" data-bd="approved">${esc(t('אישור'))}</button><button class="btn ghost" data-bd="rejected">${esc(t('דחייה'))}</button></div>` : ''}</div>` : '';
    const log = B.versions.filter(v => v.status === 'approved' && v.kind === 'change');
    $('#budget').innerHTML = `<div class="sec-row"><h2 class="sec-title" id="budget-title">📐 ${esc(t('הצעת התקציב'))}</h2>
            ${B.state === 'locked' ? `<span class="chip fin-ok">✅ ${esc(t('אושרה'))}</span>` : ''}</div>
        ${pend}${rows}
        <dl class="bd-tot"><div><dt>${esc(t('שלבים'))}</dt><dd>${esc(m(B.stages_total))}</dd></div>
            ${B.fee ? `<div><dt>${esc(t('שכר טרחה'))}</dt><dd>${esc(m(B.fee))}</dd></div>` : ''}
            ${B.contingency ? `<div><dt>${esc(t('בלתי צפוי {n}%', { n: B.contingency_pct }))}</dt><dd>${esc(m(B.contingency))}</dd></div>` : ''}
            <div class="bd-sum"><dt>${esc(t('סה"כ תקציב'))}</dt><dd>${esc(m(B.total))}</dd></div></dl>
        ${log.length ? `<details><summary>📜 ${esc(t('שינויים שאושרו: {n}', { n: log.length }))}</summary><ol class="ledger">${log.map(v => `<li>
            <div class="lg-top"><b class="lg-text">${esc(v.note || t('שינוי'))}</b><span class="lg-d">${esc(m(v.total_from))} ← ${esc(m(v.total_to))}</span></div>
            <div class="lg-meta">${v.lines.map(l => esc(`${t(l.name)}: ${m(l.from)} ← ${m(l.to)}`)).join('<br>')}</div>
            <div class="lg-meta">${v.decisions.map(d => esc(`✅ ${d.by_name} · ${shortDate(d.created_at.slice(0, 10))}`)).join(' · ')}</div></li>`).join('')}</ol></details>` : ''}`;
}

/** אישור / דחייה של התקציב — אותו חלון של הצעה ותוספת (לחיצה או חתימה). */
function openDecideBudget(act) {
    const B = data.budget, P = B.pending;
    current = { ...P, kind: 'budget' };
    decision = act;
    const dlg = $('#decide-dlg'), form = $('form', dlg);
    form.reset();
    $('.error', form).hidden = true;
    const sign = act === 'approved' && data.approval_method === 'signature';
    $('#decide-title').textContent = act === 'approved' ? t('אישור התקציב') : t('דחיית התקציב');
    $('#decide-summary').innerHTML = `<dl>
        <dt>${esc(t('מה'))}</dt><dd>${esc(P.kind === 'initial' ? t('הצעת התקציב לפי שלבים') : t('שינוי בתקציב'))}</dd>
        ${P.note ? `<dt>${esc(t('למה'))}</dt><dd>${esc(P.note)}</dd>` : ''}
        <dt>${esc(t('סה"כ'))}</dt><dd><b>${esc(money(P.total_to))}</b> ${esc(t('כולל מע"מ'))}</dd></dl>`;
    $('#note-label').textContent = act === 'rejected' ? t('סיבה או הערה למנהל הפרויקט') : t('הערה (לא חובה)');
    $('#sign-box').hidden = !sign;
    const legal = { name: data.name, amount: money(P.total_to) };
    $('#decide-legal').textContent = act !== 'approved' ? t('התקציב לא ישתנה, ומנהל הפרויקט יקבל את ההערה.')
        : sign ? t('בחתימתי אני, {name}, מאשר/ת את תקציב הפרויקט — סה"כ {amount} כולל מע"מ. האישור נשמר עם התאריך, השעה והפרטים.', legal)
        : t('בלחיצה על "אישור" אני, {name}, מאשר/ת את תקציב הפרויקט — סה"כ {amount} כולל מע"מ. האישור נשמר עם התאריך, השעה והפרטים.', legal);
    $('#decide-go').textContent = act === 'approved' ? (sign ? t('חתימה ואישור') : t('אישור')) : t('דחיית התקציב');
    $('#decide-go').className = 'btn ' + (act === 'approved' ? 'primary' : 'ghost danger');
    dlg.showModal();
    if (sign) setupSign();
}

/** דוח כספי (כ5) — אותו דוח כמו אצל המנהל, מהנתונים שבדף. */
function openReport() {
    FinReport.open({ project: { name: data.project.name, address: data.project.address, client: data.role === 'client' ? data.name : '' },
        manager: data.manager.name, finance: data.finance, payments: data.payments, changes: data.changes });
}

/** תוספות וחריגות — מה שנשלח אליהם, עם אישור / דחייה כשזה תורם. */
function renderChanges() {
    const list = data.changes || [];
    $('#changes').hidden = !list.length;
    const who = { client: 'הלקוח', lawyer: 'העו"ד' }; // i18n-ok
    $('#change-list').innerHTML = list.map(c => `<article class="qcard co-${esc(c.status)}" id="change-${c.id}">
        <div class="q1"><span class="one">${esc(c.amount > 0 ? t('תוספת') : t('הפחתה'))} · ${esc(c.supplier)} · ${esc(t(c.stage))}</span><span class="amt">${moneyVat(vatOf(c.amount, c.vat_rate))}</span></div>
        <p class="q3">${esc(c.reason)}${c.file ? ` · <a href="${fileUrl(c.file.id)}" target="_blank" rel="noopener">📄 ${esc(t('הפירוט'))}</a>` : ''}</p>
        <div class="q2">${esc(c.label)}</div>
        ${c.decisions.map(d => `<p class="q3 muted">${d.decision === 'approved' ? '✅' : '✖'} ${esc(t(who[d.role]))} · ${esc(d.by_name)} · ${longDate(d.at)}${d.note ? ' · ' + esc(d.note) : ''}</p>`).join('')}
        ${c.can_decide ? `<div class="row-btns"><button class="btn primary" data-chg="${c.id}" data-act-chg="approved">✅ ${esc(t('אישור'))}</button>
            <button class="btn ghost" data-chg="${c.id}" data-act-chg="rejected">✖ ${esc(t('דחייה'))}</button></div>` : ''}
    </article>`).join('');
}

/** אישור / דחייה של תוספת — באותו חלון של הצעה (לחיצה או חתימה). */
function openDecideChange(id, act) {
    const c = data.changes.find(x => x.id === id);
    current = { ...c, kind: 'change' };
    decision = act;
    const dlg = $('#decide-dlg'), form = $('form', dlg);
    form.reset();
    $('.error', form).hidden = true;
    const sign = act === 'approved' && data.approval_method === 'signature';
    $('#decide-title').textContent = act === 'approved' ? t('אישור תוספת') : t('דחיית תוספת');
    $('#decide-summary').innerHTML = `<dl>
        <dt>${esc(t('שלב'))}</dt><dd>${esc(t(c.stage))}</dd>
        <dt>${esc(t('ספק'))}</dt><dd>${esc(c.supplier)}</dd>
        <dt>${esc(t('סכום'))}</dt><dd>${moneyVat(vatOf(c.amount, c.vat_rate))}</dd>
        <dt>${esc(t('מה ולמה'))}</dt><dd>${esc(c.reason)}</dd></dl>`;
    $('#note-label').textContent = act === 'rejected' ? t('סיבה או הערה למנהל הפרויקט') : t('הערה (לא חובה)');
    $('#sign-box').hidden = !sign;
    const legal = { name: data.name, supplier: c.supplier, amount: money(c.amount) };
    $('#decide-legal').textContent = act !== 'approved' ? t('התוספת תסומן כנדחתה, ומנהל הפרויקט יקבל את ההערה.')
        : sign ? t('בחתימתי אני, {name}, מאשר/ת תוספת של {supplier} על סך {amount} כולל מע"מ. האישור נשמר עם התאריך, השעה והפרטים.', legal)
        : t('בלחיצה על "אישור" אני, {name}, מאשר/ת תוספת של {supplier} על סך {amount} כולל מע"מ. האישור נשמר עם התאריך, השעה והפרטים.', legal);
    $('#decide-go').textContent = act === 'approved' ? (sign ? t('חתימה ואישור') : t('אישור')) : t('דחיית התוספת');
    $('#decide-go').className = 'btn ' + (act === 'approved' ? 'primary' : 'ghost danger');
    dlg.showModal();
    if (sign) setupSign();
}

function setupSign() {
    const c = $('#sign');
    const ratio = window.devicePixelRatio || 1;
    c.width = c.clientWidth * ratio;
    c.height = c.clientHeight * ratio;
    const g = c.getContext('2d');
    g.scale(ratio, ratio);
    g.lineWidth = 2.2; g.lineCap = 'round'; g.lineJoin = 'round';
    g.strokeStyle = '#16192b';
    signed = false;
    let drawing = false;
    const pos = e => { const r = c.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; };
    c.onpointerdown = e => { $('#decide-dlg .error').hidden = true; drawing = true; c.setPointerCapture(e.pointerId); g.beginPath(); g.moveTo(...pos(e)); e.preventDefault(); };
    c.onpointermove = e => { if (!drawing) return; g.lineTo(...pos(e)); g.stroke(); signed = true; e.preventDefault(); };
    c.onpointerup = c.onpointercancel = () => { drawing = false; };
    $('#sign-clear').onclick = () => { g.clearRect(0, 0, c.width, c.height); signed = false; };
}

/** החתימה על רקע לבן — כך היא נראית גם במצב כהה וגם בהדפסה. */
function signatureData() {
    const c = $('#sign');
    const out = document.createElement('canvas');
    out.width = c.width; out.height = c.height;
    const g = out.getContext('2d');
    g.fillStyle = '#fff';
    g.fillRect(0, 0, out.width, out.height);
    g.drawImage(c, 0, 0);
    return out.toDataURL('image/png');
}

async function submitDecide(e) {
    e.preventDefault();
    const form = $('#decide-dlg form');
    const err = $('.error', form);
    const sign = decision === 'approved' && data.approval_method === 'signature';
    if (sign && !signed) { err.textContent = t('נדרשת חתימה בשטח המסומן'); err.hidden = false; return; }
    const btn = $('#decide-go');
    btn.disabled = true;
    try {
        data = current.kind === 'budget'
            ? await call('decide-budget', { version_id: current.id, decision, note: form.elements.note.value, signature: sign ? signatureData() : undefined })
            : current.kind === 'change'
            ? await call('decide-change', { change_id: current.id, decision, note: form.elements.note.value, signature: sign ? signatureData() : undefined })
            : await call('decide', { quote_id: current.id, version: current.version, decision, note: form.elements.note.value,
                signature: sign ? signatureData() : undefined });
        $('#decide-dlg').close();
        render();
        toast(decision === 'approved' ? t('האישור נשמר. מנהל הפרויקט מעודכן.') : t('הדחייה נשמרה. מנהל הפרויקט מעודכן.'));
    } catch (x) {
        err.textContent = x.message;
        err.hidden = false;
        if (x.status === 409) setTimeout(() => location.reload(), 1500);
    } finally {
        btn.disabled = false;
    }
}

document.addEventListener('click', e => {
    const act = e.target.closest('[data-act]');
    if (act) { openDecide(Number(act.dataset.q), act.dataset.act); return; }
    const bd = e.target.closest('[data-bd]');
    if (bd) { openDecideBudget(bd.dataset.bd); return; }
    const chg = e.target.closest('[data-chg]');
    if (chg) { openDecideChange(Number(chg.dataset.chg), chg.dataset.actChg); return; }
    if (e.target.closest('#x-add')) { openExpense(); return; }
    if (e.target.closest('[data-fin-report]')) { openReport(); return; }
    const si = e.target.closest('[data-sinv]');
    if (si) { supplierInvoice(Number(si.dataset.sinv)); return; }
    const go = e.target.closest('[data-go]');
    if (go) { $('#' + go.dataset.go)?.scrollIntoView({ behavior: 'smooth', block: 'start' }); return; }
    const sq = e.target.closest('[data-squote]');
    if (sq) { openSupplierDlg('quote', Number(sq.dataset.squote)); return; }
    if (e.target.closest('#s-payreq-btn')) { openSupplierDlg('payreq', null); return; }
    const rc = e.target.closest('[data-receipt]');
    if (rc) { receipt(Number(rc.dataset.receipt), rc.dataset.ok === '1'); return; }
    const pay = e.target.closest('[data-pay]');
    if (pay) { openPay(Number(pay.dataset.pay)); return; }
    const pa = e.target.closest('[data-pay-approve]');
    if (pa) { payApprove(Number(pa.dataset.payApprove)); return; }
    const m = e.target.closest('[data-media]');
    if (m) { const r = data.reports.find(x => x.id === Number(m.dataset.report)); Lightbox.open(r.media, Number(m.dataset.media), id => fileUrl(id)); return; }
    if (e.target.closest('#more-reports')) { reportsShown += 10; drawReports(); return; }
    const cp = e.target.closest('[data-copy]');
    if (cp) { navigator.clipboard?.writeText(cp.dataset.copy).then(() => toast(t('הועתק')), () => {}); return; }
    if (e.target.closest('[data-close]')) e.target.closest('dialog')?.close();
});
document.addEventListener('keydown', e => {
    const go = e.target.closest?.('[data-go]');
    if (go && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); go.click(); }
});
$('#decide-dlg form').addEventListener('submit', submitDecide);
$('#pay-dlg form').addEventListener('submit', submitPay);
$('#s-dlg form').addEventListener('submit', submitSupplier);
$('#s-dlg [name=file]').addEventListener('change', e => { sFile = e.target.files[0] || null; $('#s-file-name').textContent = sFile ? '📎 ' + sFile.name : ''; });
$('#s-bank-form').addEventListener('submit', saveBank);
$('#x-dlg form').addEventListener('submit', submitExpense);
$('#u-add').addEventListener('click', openUpload);
$('#u-dlg form').addEventListener('submit', submitUpload);
$('#u-dlg [name=file]').addEventListener('change', e => { uFile = e.target.files[0] || null; $('#u-file-name').textContent = uFile ? '📎 ' + uFile.name : ''; });
$('#x-dlg [name=file]').addEventListener('change', e => { xFile = e.target.files[0] || null; $('#x-file-name').textContent = xFile ? '📎 ' + xFile.name : ''; });
$('#pay-dlg [name=file]').addEventListener('change', e => { proofFile = e.target.files[0] || null; $('#proof-name').textContent = proofFile ? '📎 ' + proofFile.name : ''; });
// מקלדת בטלפון: השדה בפוקוס למרכז, והחלון בגובה הנראה
(() => {
    const vv = window.visualViewport;
    if (vv) { const set = () => document.documentElement.style.setProperty('--vvh', Math.round(vv.height) + 'px'); vv.addEventListener('resize', set); set(); }
    document.addEventListener('focusin', e => { if (e.target.matches('input, textarea')) setTimeout(() => e.target.scrollIntoView({ block: 'center', behavior: 'smooth' }), 300); });
})();
addEventListener('hashchange', () => location.reload());

load();
