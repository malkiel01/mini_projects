// אגף הכספים — כ3: "כספים" לכל הפרויקטים (lib/finance.php, pm_finance_all).
// מספרים (סוכם · שולם · יתרה · באיחור · צפוי ב-30 יום), "דורש טיפול כספי", תזרים חודשי — מתוכנן
// מול שולם בפועל (6 חודשים אחורה ו-6 קדימה), תשלומים קרובים ובאיחור, ופרויקט-פרויקט. סינון לפי
// פרויקט וספק, וייצוא ל-Excel (CSV).
'use strict';

const FinanceAll = (() => {
    let data = null, bound = false;
    const f = { project_id: '', supplier_id: '', all: false };

    function bind() {
        if (bound) return;
        bound = true;
        $('#fa-project').addEventListener('change', e => { f.project_id = e.target.value; load(); });
        $('#fa-supplier').addEventListener('change', e => { f.supplier_id = e.target.value; load(); });
        $('#fa-all').addEventListener('change', e => { f.all = e.target.checked; load(); });
        // התראה / תשלום — לפרויקט, ללשונית הכספים, ופותחים את אבן הדרך
        $('#view-finance').addEventListener('click', e => {
            const go = e.target.closest('[data-go-project]');
            if (!go) return;
            e.preventDefault();
            const pay = Number(go.dataset.payment) || 0;
            if (pay) Payments.openWhenReady(pay);
            location.hash = `#/p/${go.dataset.goProject}/payments`;
        });
        addEventListener('resize', () => { if (data && !$('#view-finance').hidden) chart(); });
    }

    async function show() {
        bind();
        $('#title').textContent = t('כספים');
        document.title = t('כספים');
        await load();
    }

    function params() {
        const p = {};
        if (f.project_id) p.project_id = f.project_id;
        if (f.supplier_id) p.supplier_id = f.supplier_id;
        if (f.all) p.all = 1;
        return p;
    }

    async function load() {
        try { data = await api('finance-all', { params: params() }); }
        catch (e) { $('#fa-kpis').innerHTML = `<p class="error">${esc(e.message)}</p>`; return; }
        render();
    }

    function render() {
        const F = data.filters, T = data.totals;
        $('#fa-project').innerHTML = options(F.projects.map(p => ({ value: p.id, label: p.name })), f.project_id, t('כל הפרויקטים'));
        $('#fa-supplier').innerHTML = options(F.suppliers.map(c => ({ value: c.id, label: c.name })), f.supplier_id, t('כל הספקים'));
        $('#fa-export').href = 'api.php?' + new URLSearchParams({ action: 'finance-export', ...params() });
        const kpi = (label, m, cls = '') => `<div class="${cls}"><dt>${esc(label)}</dt><dd>${moneyVat(m)}</dd></div>`;
        $('#fa-kpis').innerHTML = kpi(t('סוכם (התחייבויות)'), T.committed) + kpi(t('שולם'), T.paid) + kpi(t('יתרה לתשלום'), T.balance)
            + kpi(t('באיחור'), T.overdue, T.overdue.g > 0 ? 'kpi-late' : '') + kpi(t('צפוי ב-30 יום'), T.next30);
        alerts();
        chart();
        table();
        upcoming();
        projects();
        invoices();
        income();
    }

    /** כ5: תשלומים שהתקבלו בלי חשבונית מהספק — מהישן. */
    function invoices() {
        const list = data.invoices || [];
        $('#fa-inv-box').hidden = !list.length;
        $('#fa-inv-title').textContent = t('חשבוניות חסרות ({n})', { n: list.length });
        $('#fa-invoices').innerHTML = list.slice(0, 30).map(x => `<li class="card"><a class="body" href="#/p/${x.project_id}/payments" data-go-project="${x.project_id}" data-payment="${x.payment_id}">
            <div class="l1"><span class="one">🧾 ${esc(x.supplier)} · ${esc(x.title)}</span><span class="amt">${esc(money(x.amount))}</span></div>
            <div class="l2">${esc(x.project)} · ${esc(t('התקבל לפני {n} ימים', { n: x.days }))}</div></a></li>`).join('');
    }

    /** כ5: הכנסות שלי — שכר הטרחה בכל הפרויקטים: סוכם, התקבל, צפוי, באיחור. */
    function income() {
        const I = data.income;
        $('#fa-inc-box').hidden = !I || !I.projects.length;
        if (!I || !I.projects.length) return;
        const kpi = (label, m, cls = '') => `<div class="${cls}"><dt>${esc(label)}</dt><dd>${moneyVat(m)}</dd></div>`;
        $('#fa-income').innerHTML = `<dl class="fin-cells">${kpi(t('סוכם'), I.totals.amount)}${kpi(t('התקבל'), I.totals.received)}${kpi(t('צפוי'), I.totals.balance)}
                ${kpi(t('באיחור'), I.totals.late, I.totals.late.g > 0 ? 'kpi-late' : '')}</dl>
            ${I.next ? `<p class="fin-next">${esc(t('הבא: {title} — {project}, {date}', { title: I.next.title, project: I.next.project, date: new Date(I.next.date + 'T12:00:00').toLocaleDateString(I18n.locale()) }))} · <b>${esc(money(I.next.amount.g))}</b></p>` : ''}
            <ul class="list rows-list">${I.projects.map(p => `<li class="card"><a class="body" href="#/p/${p.id}/payments" data-go-project="${p.id}">
                <div class="l1"><span class="one">${esc(p.name)}</span><span class="amt">${esc(money(p.amount.g))}</span></div>
                <div class="l2">${esc(t('התקבל {received} · צפוי {balance}', { received: money(p.received.g), balance: money(p.balance.g) }))}${p.late.g > 0 ? ` · <span class="fin-late">${esc(t('באיחור {amount}', { amount: money(p.late.g) }))}</span>` : ''}
                    ${p.milestones ? '' : ` · <span class="fin-warn">${esc(t('בלי אבני דרך'))}</span>`}</div></a></li>`).join('')}</ul>`;
    }

    function alerts() {
        const ICON = { late: '⚠️', soon: '⏰', wait: '⏳', info: 'ℹ️' };
        $('#fa-alerts-box').hidden = !data.alerts.length;
        $('#fa-alerts-title').textContent = data.alerts_total > data.alerts.length
            ? t('דורש טיפול כספי ({n}, מוצגים {shown})', { n: data.alerts_total, shown: data.alerts.length })
            : t('דורש טיפול כספי ({n})', { n: data.alerts_total });
        $('#fa-alerts').innerHTML = data.alerts.map(a => `<li class="fa-${a.level}">
            <span aria-hidden="true">${ICON[a.level]}</span><span class="fa-text"><b>${esc(a.project_name)}</b> · ${esc(a.text)}</span>
            <a class="btn ghost sm" href="#/p/${a.project_id}/payments" data-go-project="${a.project_id}" data-payment="${a.payment_id || ''}">${esc(t('לטיפול'))}</a></li>`).join('');
    }

    // ================= תזרים: עמודות לפי חודש — מתוכנן מול שולם בפועל =================

    function chart() {
        const box = $('#fa-chart');
        const rows = data.cashflow;
        const W = Math.max(280, box.clientWidth || 340), H = 190, padT = 10, padB = 26, padS = 44;
        const max = Math.max(1, ...rows.flatMap(r => [r.planned, r.paid]));
        const nice = niceMax(max);
        const plotW = W - padS - 6, plotH = H - padT - padB;
        const step = plotW / rows.length;
        const bw = Math.max(3, Math.min(14, (step - 6) / 2));
        const rtl = I18n.dir() === 'rtl';
        // בשפות מימין לשמאל הזמן זורם מימין לשמאל, כמו הקריאה
        const X = i => (rtl ? W - 6 - (i + 1) * step : padS + i * step);
        const Y = v => padT + plotH - (v / nice) * plotH;
        const compact = new Intl.NumberFormat(I18n.locale(), { notation: 'compact', maximumFractionDigits: 1 });
        const mon = new Intl.DateTimeFormat(I18n.locale(), { month: 'short' });
        const label = ym => mon.format(new Date(ym + '-15'));
        const every = step < 30 ? 2 : 1;
        const grid = [0, 0.5, 1].map(k => {
            const y = Y(nice * k);
            return `<line class="cf-grid" x1="${rtl ? 6 : padS}" x2="${rtl ? W - padS : W - 6}" y1="${y}" y2="${y}"/>
                <text class="cf-axis" x="${rtl ? W - 2 : 2}" y="${y + 4}" text-anchor="${rtl ? 'end' : 'start'}">₪${compact.format(nice * k)}</text>`;
        }).join('');
        const bar = (x, v, cls) => {
            if (v <= 0) return '';
            const h = Math.max(2, (v / nice) * plotH), y = padT + plotH - h, r = Math.min(4, bw / 2, h);
            // פינות מעוגלות רק בקצה הנתון (למעלה), צמוד לבסיס
            return `<path class="${cls}" d="M${x},${y + h}V${y + r}Q${x},${y} ${x + r},${y}H${x + bw - r}Q${x + bw},${y} ${x + bw},${y + r}V${y + h}Z"/>`;
        };
        const groups = rows.map((r, i) => {
            const x0 = X(i), cx = x0 + step / 2;
            const a = rtl ? cx + 1 : cx - bw - 1, b = rtl ? cx - bw - 1 : cx + 1; // מתוכנן קודם בכיוון הקריאה
            const name = `${label(r.month)} ${r.month.slice(0, 4)}`;
            const aria = t('{month}: מתוכנן {planned}, שולם {paid}', { month: name, planned: money(r.planned), paid: money(r.paid) });
            return `<g class="cf-g${r.current ? ' cf-now' : ''}" tabindex="0" role="img" aria-label="${esc(aria)}" data-i="${i}">
                <rect class="cf-hit" x="${x0}" y="${padT}" width="${step}" height="${plotH + padB}"/>
                ${r.current ? `<rect class="cf-today" x="${x0 + 1}" y="${padT}" width="${step - 2}" height="${plotH}" rx="4"/>` : ''}
                ${bar(a, r.planned, 'cf-plan')}${bar(b, r.paid, 'cf-paid')}
                ${i % every === 0 || r.current ? `<text class="cf-axis cf-mon${r.current ? ' cf-mon-now' : ''}" x="${cx}" y="${H - 8}" text-anchor="middle">${esc(label(r.month))}</text>` : ''}
            </g>`;
        }).join('');
        box.innerHTML = `<svg viewBox="0 0 ${W} ${H}" width="${W}" height="${H}" direction="ltr" role="group" aria-label="${esc(t('תזרים — מתוכנן מול שולם בפועל'))}">
            ${grid}<line class="cf-base" x1="${rtl ? 6 : padS}" x2="${rtl ? W - padS : W - 6}" y1="${padT + plotH}" y2="${padT + plotH}"/>${groups}</svg>
            <div class="cf-tip" id="fa-tip" hidden></div>`;
        const tip = $('#fa-tip');
        const showTip = g => {
            const r = rows[Number(g.dataset.i)];
            tip.innerHTML = `<b>${esc(label(r.month))} ${r.month.slice(0, 4)}</b>
                <span><i class="cf-plan"></i>${esc(t('מתוכנן'))} ${esc(money(r.planned))}</span>
                <span><i class="cf-paid"></i>${esc(t('שולם בפועל'))} ${esc(money(r.paid))}</span>`;
            tip.hidden = false;
            const gx = Number(g.querySelector('.cf-hit').getAttribute('x')) + step / 2;
            tip.style.insetInlineStart = '';
            tip.style.left = `${Math.max(0, Math.min(W - 150, gx - 75))}px`;
        };
        $$('.cf-g', box).forEach(g => {
            g.addEventListener('pointerenter', () => showTip(g));
            g.addEventListener('focus', () => showTip(g));
            g.addEventListener('pointerleave', () => { tip.hidden = true; });
            g.addEventListener('blur', () => { tip.hidden = true; });
        });
    }

    /** קצה עליון "עגול" לציר: 1 / 2 / 2.5 / 5 × חזקת 10. */
    function niceMax(v) {
        const p = 10 ** Math.floor(Math.log10(v));
        for (const m of [1, 2, 2.5, 5, 10]) if (v <= m * p) return m * p;
        return 10 * p;
    }

    function table() {
        const mon = new Intl.DateTimeFormat(I18n.locale(), { month: 'long', year: 'numeric' });
        $('#fa-table').innerHTML = `<table class="cf-table"><thead><tr><th>${esc(t('חודש'))}</th><th>${esc(t('מתוכנן'))}</th><th>${esc(t('שולם בפועל'))}</th></tr></thead>
            <tbody>${data.cashflow.map(r => `<tr${r.current ? ' class="cf-now"' : ''}><td>${esc(mon.format(new Date(r.month + '-15')))}</td><td>${esc(money(r.planned))}</td><td>${esc(money(r.paid))}</td></tr>`).join('')}</tbody></table>`;
    }

    // ================= תשלומים קרובים ובאיחור =================

    function upcoming() {
        const when = u => u.late ? t('באיחור · {date}', { date: shortDate(u.date) })
            : u.due_kind === 'advance' ? t('מקדמה — בתחילת העבודה')
            : u.due_kind === 'stage_done' ? (u.due ? t('השלב בוצע — הגיע מועד') : t('בגמר השלב'))
            : u.due_kind === 'progress' ? (u.due ? t('ההתקדמות הגיעה — הגיע מועד') : t('בהתקדמות {n}%', { n: u.due_pct }))
            : t('עד {date}', { date: shortDate(u.date) });
        $('#fa-upcoming').innerHTML = data.upcoming.map(u => `<li class="card q-row" >
            <a class="body" href="#/p/${u.project_id}/payments" data-go-project="${u.project_id}" data-payment="${u.payment_id}">
                <div class="l1"><span class="one">${esc(u.title)} · ${esc(u.supplier)}</span><span class="amt">${moneyVat(u.amount)}</span></div>
                <div class="l2">${[esc(u.project), u.stage ? esc(t(u.stage)) : '', `<span class="${u.late ? 'late' : ''}">${esc(when(u))}</span>`, esc(u.label)].filter(Boolean).join('<i class="sep" aria-hidden="true">·</i>')}</div>
            </a></li>`).join('') || `<p class="empty">${esc(t('אין תשלומים ב-14 הימים הקרובים, ואין באיחור.'))}</p>`;
    }

    // ================= פרויקט-פרויקט =================

    function projects() {
        $('#fa-projects').innerHTML = data.projects.map(p => {
            const max = Math.max(p.budget_today.g, p.committed.g, 1);
            const w = v => `${Math.max(0, Math.min(100, v / max * 100)).toFixed(2)}%`;
            const paid = Math.min(p.paid.g, p.committed.g || p.paid.g), late = p.overdue.g, later = Math.max(0, p.committed.g - paid - late);
            const nums = [
                t('סוכם {amount}', { amount: money(p.committed.g) }), t('שולם {amount} ({n}%)', { amount: money(p.paid.g), n: p.paid_pct }),
                t('יתרה {amount}', { amount: money(p.balance.g) }),
            ].map(esc);
            if (late > 0) nums.push(`<span class="late">${esc(t('באיחור {amount}', { amount: money(late) }))}</span>`);
            return `<li class="card fa-proj"><a class="body" href="#/p/${p.id}/payments">
                <div class="l1"><span class="one">${esc(p.name)}</span>${p.alerts ? `<span class="nbadge hot" title="${esc(t('דורש טיפול'))}">${p.alerts}</span>` : ''}${p.over_budget ? `<span class="chip fin-late">${esc(t('מעל התקציב'))}</span>` : ''}</div>
                <div class="fin-bar sm" aria-hidden="true"><span class="fb-paid" style="inline-size:${w(paid)}"></span><span class="fb-late" style="inline-size:${w(late)}"></span><span class="fb-later" style="inline-size:${w(later)}"></span>${p.budget_today.g ? `<i class="fin-mark" style="inset-inline-start:${w(p.budget_today.g)}"></i>` : ''}</div>
                <div class="l2">${nums.join('<i class="sep" aria-hidden="true">·</i>')}</div>
                <div class="l2">${[p.client ? esc(p.client) : '', esc(t('בוצע {n}%', { n: p.progress })), p.budget_today.g ? esc(t('תקציב {amount}', { amount: money(p.budget_today.g) })) : ''].filter(Boolean).join('<i class="sep" aria-hidden="true">·</i>')}</div>
            </a></li>`;
        }).join('') || `<p class="empty">${esc(t('אין פרויקטים פעילים עם כספים.'))}</p>`;
    }

    return { show };
})();
