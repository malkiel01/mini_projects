// פורטל הלקוח והעו"ד — שלב 6. עמוד אחד, בלי כניסה: האסימון בכתובת (#t=…).
//
// מה רואים: ממתין לאישורך · התקדמות השלבים · הצעות המחיר שנשלחו (המומלצת בראש)
// · הקבצים שמנהל הפרויקט סימן "גלוי" · ומנהל הפרויקט ליצירת קשר.
// אישור — בלחיצה או בחתימה, לפי מה שהמנהל הגדיר ללקוח. עו"ד מאשר אחרי הלקוח.
'use strict';

const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const money = n => '₪' + Number(n || 0).toLocaleString('he-IL');
const shortDate = ymd => ymd ? ymd.split('-').reverse().slice(0, 2).join('.') : '';
const fmtSize = n => n < 1048576 ? Math.max(1, Math.round(n / 1024)) + 'KB' : (n / 1048576).toFixed(1) + 'MB';
const waNumber = s => { const d = String(s || '').replace(/\D/g, ''); return d.startsWith('0') ? '972' + d.slice(1) : d; };

const token = (location.hash.match(/t=([A-Za-z0-9_-]+)/) || [])[1] || '';
const fileUrl = (id, extra = '') => `api.php?action=file&id=${id}&t=${encodeURIComponent(token)}${extra}`;
let data = null, current = null, decision = null, signed = false;

function toast(msg, isErr = false) {
    const t = $('#toast');
    t.textContent = msg;
    t.classList.toggle('err', isErr);
    t.hidden = false;
    clearTimeout(toast.timer);
    toast.timer = setTimeout(() => { t.hidden = true; }, 3500);
}

async function call(action, body) {
    const opts = { headers: { Accept: 'application/json', 'X-Portal': token } };
    if (body) { opts.method = 'POST'; opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    const res = await fetch('api.php?action=' + action, opts);
    let d = {};
    try { d = await res.json(); } catch { /* לא JSON */ }
    if (!res.ok) { const e = new Error(d.error || `תקלה (${res.status})`); e.status = res.status; throw e; }
    return d;
}

async function load() {
    if (!token) return fail('בקישור חסר החלק האישי. פתחו אותו שוב מההודעה שקיבלתם.');
    try { data = await call('view'); }
    catch (e) { return e.status === 404 ? fail(e.message) : fail('אין חיבור לשרת. נסו שוב בעוד רגע.'); }
    render();
}

function fail(msg) {
    $('#loading').hidden = true;
    $('#content').hidden = true;
    $('#fail').hidden = false;
    $('#fail-text').textContent = msg;
}

const roleName = () => data.role === 'client' ? 'לקוח' : 'עורך דין';
const stageLabel = s => data.labels.stage[s] || s;
const quoteLabel = s => ({ sent: 'ממתינה להחלטה', client_approved: data.role === 'lawyer' ? 'הלקוח אישר — ממתינה לך' : 'אישרת — ממתינה לעו"ד' }[s] || data.labels.quote[s] || s);

function render() {
    $('#loading').hidden = true;
    $('#content').hidden = false;
    const p = data.project;
    $('#title').textContent = p.name;
    document.title = p.name;
    $('#subtitle').textContent = [`שלום ${data.name}`, p.address].filter(Boolean).join(' · ');
    $('#subtitle').hidden = false;

    // ---- ממתין לאישורך: שלב אחד = שורה אחת ----
    const waiting = new Map();
    data.quotes.filter(q => q.can_decide && (q.status === 'sent' || q.status === 'client_approved')).forEach(q => waiting.set(q.stage_id, (waiting.get(q.stage_id) || 0) + 1));
    // תשלומים שממתינים לי: עו"ד — לאישור; משלם — לתשלום
    const payNow = data.payments.filter(p => p.can_approve || (p.can_pay && p.payer === data.role));
    $('#pending').hidden = !waiting.size && !payNow.length;
    $('#pending-list').innerHTML = [...waiting].map(([sid, n]) => {
        const st = data.stages.find(s => s.id === sid);
        return `<li class="card" data-go="stage-${sid}" tabindex="0" role="button">
            <span class="ficon" aria-hidden="true">✍️</span>
            <div class="body"><div class="l1"><span class="one">${esc(st?.name)}</span></div>
                <div class="l2">${n > 1 ? `${n} הצעות מחיר — לבחירה ואישור` : 'הצעת מחיר — לאישור'}</div></div>
            <span class="go" aria-hidden="true">‹</span></li>`;
    }).join('') + payNow.map(p => `<li class="card" data-go="pay-${p.id}" tabindex="0" role="button">
            <span class="ficon" aria-hidden="true">${p.can_approve ? '⚖️' : '💳'}</span>
            <div class="body"><div class="l1"><span class="one">${esc(p.title)}</span><span class="amt">${esc(money(p.status === 'partial' ? p.balance : p.amount))}</span></div>
                <div class="l2">${p.can_approve ? 'דרישת תשלום — לאישורך' : 'לתשלום'} · ${esc(p.supplier_name)}${p.due_date ? ' · עד ' + shortDate(p.due_date) : ''}</div></div>
            <span class="go" aria-hidden="true">‹</span></li>`).join('');

    // ---- התקדמות ----
    const s = data.stages;
    const done = s.filter(x => ['done', 'paid'].includes(x.status)).length;
    const pct = s.length ? Math.round(done / s.length * 100) : 0;
    $('#progress span').style.width = pct + '%';
    $('#progress').setAttribute('aria-valuenow', String(pct));
    $('#progress').setAttribute('aria-label', `${done} מתוך ${s.length} שלבים בוצעו`);
    $('#progress-text').textContent = s.length ? `${done}/${s.length} בוצעו` : '';
    $('#stage-list').innerHTML = s.map((x, i) => `<li class="card static">
        <span class="num st-${esc(x.status)}" aria-hidden="true">${i + 1}</span>
        <div class="body"><div class="l1"><span class="one">${esc(x.name)}</span></div>${stageMeta(x)}</div>
        <span class="chip st-${esc(x.status)}">${esc(stageLabel(x.status))}</span></li>`).join('') || '<p class="empty">עוד אין שלבים.</p>';

    // ---- הצעות מחיר, לפי שלב: המומלצת בראש ----
    const byStage = new Map();
    data.quotes.forEach(q => { if (!byStage.has(q.stage_id)) byStage.set(q.stage_id, []); byStage.get(q.stage_id).push(q); });
    $('#quotes').hidden = !byStage.size;
    const rank = q => q.status === 'approved' ? 0 : q.status === 'client_approved' ? 1 : q.recommended ? 2 : ['declined', 'rejected'].includes(q.status) ? 4 : 3;
    $('#quote-list').innerHTML = s.filter(st => byStage.has(st.id)).map(st => {
        const list = byStage.get(st.id).sort((a, b) => rank(a) - rank(b) || a.amount - b.amount);
        const live = list.filter(q => !['declined', 'rejected'].includes(q.status));
        const low = live.length > 1 ? Math.min(...live.map(q => q.amount)) : null;
        return `<section class="q-stage" id="stage-${st.id}" aria-label="${esc(st.name)}">
            <header class="q-head"><h3 class="one">${esc(st.name)}</h3><span class="chip st-${esc(st.status)}">${esc(stageLabel(st.status))}</span></header>
            ${list.map(q => quoteCard(q, low)).join('')}
        </section>`;
    }).join('');

    // ---- מהשטח: העדכונים הגלויים, החדש למעלה ----
    $('#field').hidden = !data.reports.length;
    drawReports();

    // ---- תשלומים ----
    $('#payments').hidden = !data.payments.length;
    $('#payment-list').innerHTML = data.payments.map(payCard).join('');

    // ---- קבצים ----
    $('#files').hidden = !data.files.length;
    $('#file-list').innerHTML = data.files.map(f => `<li class="card">
        ${f.thumb ? `<img class="fthumb" src="${fileUrl(f.id, '&thumb=1')}" alt="" loading="lazy" width="40" height="40">` : `<span class="ficon" aria-hidden="true">📄</span>`}
        <div class="body"><div class="l1"><span class="one">${esc(f.name)}</span></div>
            <div class="l2">${[esc(data.labels.file[f.type] || ''), fmtSize(f.size), new Date(f.at).toLocaleDateString('he-IL')].filter(Boolean).join('<i class="sep" aria-hidden="true">·</i>')}</div></div>
        <a class="icon-btn" href="${fileUrl(f.id)}" target="_blank" rel="noopener" aria-label="פתיחת ${esc(f.name)}">↗</a></li>`).join('');

    // ---- מנהל הפרויקט ----
    const m = data.manager;
    $('#manager').innerHTML = `<span>מנהל הפרויקט: <b>${esc(m.name)}</b></span>
        ${m.phone ? `<a class="icon-btn" href="tel:${esc(m.phone.replace(/[^\d+]/g, ''))}" aria-label="חיוג למנהל הפרויקט">📞</a>
            <a class="icon-btn" href="https://wa.me/${esc(waNumber(m.phone))}" target="_blank" rel="noopener" aria-label="וואטסאפ למנהל הפרויקט">💬</a>` : ''}
        ${m.email ? `<a class="icon-btn" href="mailto:${esc(m.email)}" aria-label="אימייל למנהל הפרויקט">✉️</a>` : ''}`;
}

/** שורה שנייה בשלב — רק כשיש מה להראות (ספק, תאריכים). */
function stageMeta(x) {
    const m = [x.supplier ? esc(x.supplier) : '', x.plan_start || x.plan_end ? `${shortDate(x.plan_start)}–${shortDate(x.plan_end)}` : '']
        .filter(Boolean).join('<i class="sep" aria-hidden="true">·</i>');
    return m ? `<div class="l2 one">${m}</div>` : '';
}

function quoteCard(q, low) {
    const dead = ['declined', 'rejected'].includes(q.status);
    const diff = low !== null && !dead ? (q.amount === low ? '<b class="cheap">הזולה</b>' : `+${Math.round((q.amount - low) / low * 100)}%`) : '';
    const meta = [
        q.company && q.company !== q.supplier ? esc(q.company) : '',
        diff,
        q.valid_until ? `בתוקף עד ${shortDate(q.valid_until)}` : '',
    ].filter(Boolean).join('<i class="sep" aria-hidden="true">·</i>');
    const last = q.decisions.filter(d => d.version === q.version).slice(-1)[0];
    const btns = q.can_decide ? `<div class="row-btns">
            <button class="btn ${q.recommended || q.status === 'client_approved' ? 'primary' : 'ghost'}" data-act="approved" data-q="${q.id}">✅ אישור</button>
            ${q.can_reject ? `<button class="btn ghost" data-act="rejected" data-q="${q.id}">דחייה</button>` : ''}</div>` : '';
    return `<article class="qcard q-${esc(q.status)}${q.recommended ? ' rec' : ''}">
        ${q.recommended ? '<p class="rec-tag">⭐ ההמלצה של מנהל הפרויקט</p>' : ''}
        <div class="q1"><span class="one">${esc(q.supplier)}</span><span class="amt">${esc(money(q.amount))}</span></div>
        <div class="q2">${meta}${meta ? '<i class="sep" aria-hidden="true">·</i>' : ''}<span class="st">${esc(quoteLabel(q.status))}</span></div>
        ${q.terms ? `<p class="q3">תנאי תשלום: ${esc(q.terms)}</p>` : ''}
        ${q.notes ? `<p class="q3">${esc(q.notes)}</p>` : ''}
        ${last ? `<p class="q3 muted">${last.decision === 'approved' ? '✅' : '✖'} ${esc(last.by_name)} · ${new Date(last.at).toLocaleDateString('he-IL')}${last.note ? ' · ' + esc(last.note) : ''}</p>` : ''}
        ${q.file ? `<a class="btn ghost sm file-link" href="${fileUrl(q.file.id)}" target="_blank" rel="noopener">📄 צפייה בהצעה</a>` : ''}
        ${btns}
    </article>`;
}

let reportsShown = 5;

function drawReports() {
    const list = data.reports.slice(0, reportsShown);
    $('#report-list').innerHTML = list.map(r => {
        const st = r.stage_id ? data.stages.find(s => s.id === r.stage_id) : null;
        const media = r.media.map((m, i) => `<li><button type="button" class="tile" data-report="${r.id}" data-media="${i}" aria-label="${m.video ? 'סרטון' : 'תמונה'} ${i + 1}">
            ${m.thumb ? `<img src="${fileUrl(m.id, '&thumb=1')}" alt="" loading="lazy">` : `<span aria-hidden="true">${m.video ? '🎬' : '🖼️'}</span>`}
            ${m.video ? '<i class="play" aria-hidden="true">▶</i>' : ''}</button></li>`).join('');
        return `<article class="report${r.kind === 'done' ? ' done' : ''}">
            <header><span class="one">${r.kind === 'done' ? '✅ ' : ''}${esc(st?.name || r.stage_name || 'כללי')}${r.kind === 'done' ? ' — בוצע' : ''}</span>
                <time datetime="${esc(r.at)}">${new Date(r.at).toLocaleDateString('he-IL')}</time></header>
            ${r.text ? `<p>${esc(r.text)}</p>` : ''}
            ${media ? `<ul class="media-grid">${media}</ul>` : ''}
        </article>`;
    }).join('');
    $('#more-reports').hidden = data.reports.length <= reportsShown;
}

const PAY_ICON = { requested: '📤', paid: '💳', partial: '◐', received: '✅' };

function payCard(p) {
    const st = p.stage_id ? data.stages.find(s => s.id === p.stage_id) : null;
    const late = p.due_date && p.due_date < new Date().toISOString().slice(0, 10) && !['received'].includes(p.status);
    const b = p.bank?.bank || {};
    const bank = p.bank && (b.bank || b.account) ? `<dl class="bank">
            <dt>לטובת</dt><dd>${esc(b.holder || p.bank.company || p.bank.name)}</dd>
            ${b.bank ? `<dt>בנק</dt><dd>${esc(b.bank)}</dd>` : ''}${b.branch ? `<dt>סניף</dt><dd>${esc(b.branch)}</dd>` : ''}
            ${b.account ? `<dt>חשבון</dt><dd><span dir="ltr">${esc(b.account)}</span> <button type="button" class="btn ghost sm" data-copy="${esc(b.account)}">העתקה</button></dd>` : ''}
            ${p.bank.tax_id ? `<dt>ח.פ.</dt><dd>${esc(p.bank.tax_id)}</dd>` : ''}</dl>`
        : p.can_pay ? '<p class="q3 muted">פרטי הבנק — אצל הספק או מנהל הפרויקט.</p>' : '';
    const proofs = p.proofs.map(x => `<li>${x.status === 'received' ? '✅' : x.status === 'rejected' ? '✖' : '⏳'} ${esc(money(x.amount))} · ${esc(data.labels.method[x.method] || '')} · ${esc(shortDate(x.paid_on))}
        ${x.status === 'pending' ? '— ממתין לאישור קבלה' : x.status === 'received' ? '— התקבל' : '— לא התקבל' + (x.review_note ? ': ' + esc(x.review_note) : '')}
        ${x.file ? ` · <a href="${fileUrl(x.file.id)}" target="_blank" rel="noopener">אסמכתא</a>` : ''}</li>`).join('');
    const btns = [
        p.can_approve ? `<button class="btn primary" data-pay-approve="${p.id}">✅ אישור לתשלום</button>` : '',
        p.can_pay ? `<button class="btn ${p.payer === data.role ? 'primary' : 'ghost'}" data-pay="${p.id}">💳 שילמתי — העלאת אסמכתא</button>` : '',
    ].filter(Boolean).join('');
    return `<article class="qcard pay-${esc(p.status)}" id="pay-${p.id}">
        <div class="q1"><span class="one">${esc(p.title)}${st ? ` · ${esc(st.name)}` : ''}</span><span class="amt">${esc(money(p.amount))}</span></div>
        <div class="q2">${esc(p.supplier_name)}<i class="sep" aria-hidden="true">·</i>${PAY_ICON[p.status] || ''} ${esc(p.label)}${p.due_date ? `<i class="sep" aria-hidden="true">·</i><span class="${late ? 'late' : ''}">עד ${shortDate(p.due_date)}</span>` : ''}</div>
        ${p.status === 'partial' ? `<p class="q3">התקבל ${esc(money(p.received))} · נשאר <b>${esc(money(p.balance))}</b></p>` : ''}
        ${p.notes ? `<p class="q3">${esc(p.notes)}</p>` : ''}
        ${p.lawyer ? `<p class="q3 muted">⚖️ ${esc(p.lawyer.by)} אישר לתשלום · ${new Date(p.lawyer.at).toLocaleDateString('he-IL')}</p>` : ''}
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
    $('#pay-summary').innerHTML = `<dl><dt>עבור</dt><dd>${esc(payItem.title)}</dd><dt>לספק</dt><dd>${esc(payItem.supplier_name)}</dd>
        <dt>${payItem.status === 'partial' ? 'נשאר' : 'סכום'}</dt><dd><b>${esc(money(payItem.balance))}</b> כולל מע"מ</dd></dl>`;
    el.amount.value = String(payItem.balance);
    el.paid_on.value = new Date().toISOString().slice(0, 10);
    el.method.innerHTML = Object.entries(data.labels.method).map(([v, l]) => `<option value="${v}">${esc(l)}</option>`).join('');
    dlg.showModal();
}

/** תמונה — מוקטנת ל-2000px (JPEG) לפני השליחה; PDF כמו שהוא. */
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
    if (!amount) { err.textContent = 'כמה שילמתם?'; err.hidden = false; return; }
    if (!proofFile) { err.textContent = 'נדרשת אסמכתא — צילום ההעברה, הצ\'ק או אישור הבנק'; err.hidden = false; return; }
    const btn = $('#pay-go');
    btn.disabled = true;
    btn.textContent = 'שולח…';
    try {
        const file = await shrink(proofFile);
        if (file.size > data.max_proof) throw new Error('הקובץ גדול מדי — עד 8MB');
        const qs = new URLSearchParams({ action: 'proof', payment_id: payItem.id, amount, paid_on: el.paid_on.value, method: el.method.value, note: el.note.value, name: file.name });
        const res = await fetch('api.php?' + qs, { method: 'POST', headers: { 'X-Portal': token, 'Content-Type': file.type || 'application/octet-stream', Accept: 'application/json' }, body: file });
        const d = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(d.error || `תקלה (${res.status})`);
        data = d;
        $('#pay-dlg').close();
        render();
        toast('האסמכתא נשלחה. מנהל הפרויקט יאשר כשהכסף יתקבל.');
    } catch (x) {
        err.textContent = x.message;
        err.hidden = false;
    } finally {
        btn.disabled = false;
        btn.textContent = 'שילמתי — שליחת האסמכתא';
    }
}

async function payApprove(id) {
    const p = data.payments.find(x => x.id === id);
    const note = prompt(`לאשר את התשלום: ${p.title} — ${money(p.amount)} ל${p.supplier_name}?\nהערה (לא חובה):`, '');
    if (note === null) return;
    try {
        data = await call('pay-approve', { payment_id: id, note });
        render();
        toast('האישור נשמר. הלקוח יכול לשלם.');
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
    $('#decide-title').textContent = act === 'approved' ? 'אישור הצעת מחיר' : 'דחיית הצעת מחיר';
    $('#decide-summary').innerHTML = `<dl>
        <dt>שלב</dt><dd>${esc(st?.name)}</dd>
        <dt>ספק</dt><dd>${esc(current.supplier)}${current.company && current.company !== current.supplier ? ' · ' + esc(current.company) : ''}</dd>
        <dt>סכום</dt><dd><b>${esc(money(current.amount))}</b> כולל מע"מ</dd>
        ${current.terms ? `<dt>תנאי תשלום</dt><dd>${esc(current.terms)}</dd>` : ''}
        ${current.valid_until ? `<dt>בתוקף עד</dt><dd>${esc(shortDate(current.valid_until))}</dd>` : ''}</dl>`;
    $('#note-label').textContent = act === 'rejected' ? 'סיבה או הערה למנהל הפרויקט' : 'הערה (לא חובה)';
    $('#sign-box').hidden = !sign;
    $('#decide-legal').textContent = act === 'approved'
        ? `${sign ? 'בחתימתי' : 'בלחיצה על "אישור"'} אני, ${data.name}, מאשר/ת את הצעת המחיר של ${current.supplier} על סך ${money(current.amount)} כולל מע"מ${data.role === 'lawyer' ? ', כעורך הדין של הלקוח' : ''}. האישור נשמר עם התאריך, השעה ופרטי ההצעה כפי שהיא עכשיו.`
        : 'ההצעה תסומן כנדחתה, ומנהל הפרויקט יקבל את ההערה.';
    $('#decide-go').textContent = act === 'approved' ? (sign ? 'חתימה ואישור' : 'אישור') : 'דחיית ההצעה';
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
    if (sign && !signed) { err.textContent = 'נדרשת חתימה בשטח המסומן'; err.hidden = false; return; }
    const btn = $('#decide-go');
    btn.disabled = true;
    try {
        data = await call('decide', { quote_id: current.id, version: current.version, decision, note: form.elements.note.value,
            signature: sign ? signatureData() : undefined });
        $('#decide-dlg').close();
        render();
        toast(decision === 'approved' ? 'האישור נשמר. מנהל הפרויקט מעודכן.' : 'הדחייה נשמרה. מנהל הפרויקט מעודכן.');
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
    const go = e.target.closest('[data-go]');
    if (go) { $('#' + go.dataset.go)?.scrollIntoView({ behavior: 'smooth', block: 'start' }); return; }
    const pay = e.target.closest('[data-pay]');
    if (pay) { openPay(Number(pay.dataset.pay)); return; }
    const pa = e.target.closest('[data-pay-approve]');
    if (pa) { payApprove(Number(pa.dataset.payApprove)); return; }
    const m = e.target.closest('[data-media]');
    if (m) { const r = data.reports.find(x => x.id === Number(m.dataset.report)); Lightbox.open(r.media, Number(m.dataset.media), id => fileUrl(id)); return; }
    if (e.target.closest('#more-reports')) { reportsShown += 10; drawReports(); return; }
    const cp = e.target.closest('[data-copy]');
    if (cp) { navigator.clipboard?.writeText(cp.dataset.copy).then(() => toast('הועתק'), () => {}); return; }
    if (e.target.closest('[data-close]')) e.target.closest('dialog')?.close();
});
document.addEventListener('keydown', e => {
    const go = e.target.closest?.('[data-go]');
    if (go && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); go.click(); }
});
$('#decide-dlg form').addEventListener('submit', submitDecide);
$('#pay-dlg form').addEventListener('submit', submitPay);
$('#pay-dlg [name=file]').addEventListener('change', e => { proofFile = e.target.files[0] || null; $('#proof-name').textContent = proofFile ? '📎 ' + proofFile.name : ''; });
// מקלדת בטלפון: השדה בפוקוס למרכז, והחלון בגובה הנראה
(() => {
    const vv = window.visualViewport;
    if (vv) { const set = () => document.documentElement.style.setProperty('--vvh', Math.round(vv.height) + 'px'); vv.addEventListener('resize', set); set(); }
    document.addEventListener('focusin', e => { if (e.target.matches('input, textarea')) setTimeout(() => e.target.scrollIntoView({ block: 'center', behavior: 'smooth' }), 300); });
})();
addEventListener('hashchange', () => location.reload());

load();
