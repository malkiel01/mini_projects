// דוח כספי לפרויקט (אגף הכספים, כ5) — למנהל, וגם בפורטל ללקוח ולעו"ד. עומד בפני עצמו: חלון משלו,
// עיצוב משלו ופורמט כסף משלו — כך שאותו קובץ עובד באפליקציה ובפורטל (portal/index.html).
//
//   FinReport.open({ project: {name, address, client}, manager, finance, payments, changes })
//
// finance — מה ש-pm_finance_of מחזיר. "🖨️ הדפסה / PDF" — הדפסה של הדוח בלבד (בדפדפן: "שמירה כ-PDF");
// "⬇️ Excel" — אותו דוח כ-CSV (UTF-8 עם BOM — נפתח בעברית ב-Excel).
'use strict';

const FinReport = (() => {
    const e = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const fmt = new Intl.NumberFormat(I18n.locale(), { style: 'currency', currency: 'ILS', minimumFractionDigits: 0, maximumFractionDigits: 2 });
    const money = n => (I18n.lang() === 'he' ? (Number(n) < 0 ? '−₪' : '₪') + Math.abs(Number(n || 0)).toLocaleString('he-IL', { maximumFractionDigits: 2 }) : fmt.format(Number(n || 0)));
    const date = ymd => (ymd ? new Date(ymd.length === 10 ? ymd + 'T12:00:00' : ymd).toLocaleDateString(I18n.locale()) : '');
    /** סכום כולל מע"מ, ומתחתיו לפני מע"מ ומע"מ. */
    // לפני מע"מ ומע"מ — בשקלים שלמים, כמו בשאר האפליקציה
    const mv = m => `<b>${e(money(m.g))}</b>${m.g !== m.n ? `<small>${e(t('{net} + מע"מ {vat}', { net: money(Math.round(m.n)), vat: money(Math.round(m.g) - Math.round(m.n)) }))}</small>` : ''}`;
    let src = null, dlg = null;

    function style() {
        if (document.getElementById('frp-style')) return;
        const s = document.createElement('style');
        s.id = 'frp-style';
        s.textContent = `
#frp-dlg{inline-size:min(900px,100vw);max-inline-size:100vw;block-size:100dvh;max-block-size:100dvh;margin:0 auto;padding:0;border:0;
  background:var(--bg,#f6f7f9);color:var(--ink,#1c2430)}
#frp-dlg::backdrop{background:rgb(0 0 0/.4)}
.frp-bar{position:sticky;top:0;display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:10px 16px;background:var(--surface,#fff);border-bottom:1px solid var(--line,#dde2e8);z-index:1}
.frp-bar h2{flex:1;font-size:1rem;margin:0;min-inline-size:8em}
.frp-bar button{font:inherit;padding:8px 12px;border-radius:10px;border:1px solid var(--line,#cfd6de);background:var(--surface,#fff);color:inherit;cursor:pointer;min-block-size:40px}
.frp-bar button.pri{background:var(--accent,#2c4a8a);border-color:transparent;color:#fff}
.frp{padding:16px;display:grid;grid-template-columns:minmax(0,1fr);gap:18px;font-size:.88rem;line-height:1.45}
.frp>*{min-inline-size:0}
.frp-kpis{grid-template-columns:repeat(auto-fill,minmax(min(150px,40vw),1fr))}
.frp h1{font-size:1.25rem;margin:0}.frp h3{font-size:.95rem;margin:0 0 6px}
.frp .meta{color:var(--muted,#5b6675);margin:2px 0 0}
.frp-kpis{display:grid;gap:8px;margin:0}
.frp-kpis div{border:1px solid var(--line,#dde2e8);border-radius:8px;padding:8px;background:var(--surface,#fff)}
.frp-kpis dt{font-size:.75rem;color:var(--muted,#5b6675)}.frp-kpis dd{margin:0}
.frp small{display:block;font-size:.72rem;color:var(--muted,#5b6675);font-weight:400}
.frp-tw{overflow-x:auto}
.frp table{inline-size:100%;border-collapse:collapse;background:var(--surface,#fff)}
.frp th,.frp td{border:1px solid var(--line,#dde2e8);padding:5px 7px;text-align:start;vertical-align:top}
.frp th{background:var(--bg,#f1f3f6);font-size:.75rem;font-weight:600}
.frp td.n{white-space:nowrap}
.frp tr.sum td{font-weight:600;background:var(--bg,#f1f3f6)}
.frp .foot{color:var(--muted,#5b6675);font-size:.75rem}
#frp-print{display:none}
@media print{
  html.frp-printing body>*:not(#frp-print){display:none!important}
  html.frp-printing #frp-print{display:block}
  html.frp-printing body{background:#fff}
  #frp-print .frp{padding:0;font-size:10pt;color:#000}
  #frp-print .frp-kpis div,#frp-print table,#frp-print th,#frp-print td{border-color:#999;background:#fff}
  #frp-print .frp-tw{overflow:visible}
  #frp-print section{break-inside:avoid-page}
  #frp-print tr{break-inside:avoid}
}`;
        document.head.appendChild(s);
    }

    // ================= בניית הדוח =================

    function stageName(id) {
        const s = src.finance.stages.find(x => x.stage_id === id);
        return s ? (t(s.name) + (s.unit_name ? ' · ' + s.unit_name : '')) : '';
    }

    function html() {
        const F = src.finance, T = F.totals, S = F.settings;
        const kpi = (label, m) => `<div><dt>${e(label)}</dt><dd>${mv(m)}</dd></div>`;
        const rows = [];
        for (const s of F.stages) for (const r of s.rows) rows.push({ stage: s.stage_id ? (t(s.name) + (s.unit_name ? ' · ' + s.unit_name : '')) : t('בלי שלב'), ...r });
        const sumRow = (label, list, keys) => `<tr class="sum"><td colspan="2">${e(label)}</td>${keys.map(k => `<td class="n">${e(money(list.reduce((a, x) => a + x[k].g, 0)))}</td>`).join('')}</tr>`;
        const out = [];
        out.push(`<header><h1>${e(t('דוח כספי — {project}', { project: src.project.name }))}</h1>
            <p class="meta">${e([src.project.client, src.project.address].filter(Boolean).join(' · '))}</p>
            <p class="meta">${e(t('מנהל הפרויקט: {name}', { name: src.manager || '' }))} · ${e(t('הופק ב-{date}', { date: date(new Date().toISOString()) }))}</p></header>`);
        out.push(`<section><h3>${e(t('המצב הכספי'))}</h3><dl class="frp-kpis">
            ${kpi(t('תקציב ראשוני'), T.budget_initial)}${kpi(t('תקציב היום'), T.budget_today)}
            ${kpi(t('סוכם (התחייבויות)'), T.committed)}${kpi(t('שולם'), T.paid)}${kpi(t('יתרה לתשלום'), T.balance)}
            ${kpi(t('היה צריך עד היום'), T.due)}${kpi(t('צפי סופי'), T.forecast)}
            ${T.retention?.g ? kpi(t('עיכבון'), T.retention) : ''}${T.index?.g ? kpi(t('הפרשי הצמדה'), T.index) : ''}
        </dl></section>`);
        if (rows.length) out.push(`<section><h3>${e(t('לפי שלב וספק'))}</h3><div class="frp-tw"><table>
            <thead><tr><th>${e(t('שלב'))}</th><th>${e(t('ספק'))}</th><th>${e(t('סוכם'))}</th><th>${e(t('שולם'))}</th><th>${e(t('יתרה'))}</th></tr></thead>
            <tbody>${rows.map(r => `<tr><td>${e(r.stage)}</td><td>${e(r.name)}</td><td class="n">${mv(r.committed)}</td><td class="n">${mv(r.paid)}</td><td class="n">${mv(r.balance)}</td></tr>`).join('')}
            ${sumRow(t('סה"כ ספקים'), rows, ['committed', 'paid', 'balance'])}</tbody></table></div></section>`);
        const ch = (src.changes || []).filter(c => c.status !== 'cancelled');
        if (ch.length) out.push(`<section><h3>${e(t('תוספות וחריגות'))}</h3><div class="frp-tw"><table>
            <thead><tr><th>${e(t('שלב'))}</th><th>${e(t('ספק'))}</th><th>${e(t('מה ולמה'))}</th><th>${e(t('סכום'))}</th><th>${e(t('סטטוס'))}</th></tr></thead>
            <tbody>${ch.map(c => `<tr><td>${e(t(c.stage))}</td><td>${e(c.supplier)}</td><td>${e(c.reason)}</td><td class="n">${e(money(c.amount))}</td><td>${e(c.label)}</td></tr>`).join('')}</tbody></table></div></section>`);
        const X = F.expenses?.items || [];
        if (X.length) out.push(`<section><h3>${e(t('הוצאות ישירות'))}</h3><div class="frp-tw"><table>
            <thead><tr><th>${e(t('תאריך'))}</th><th>${e(t('מה נקנה'))}</th><th>${e(t('שלב'))}</th><th>${e(t('סכום'))}</th></tr></thead>
            <tbody>${X.map(x => `<tr><td class="n">${e(date(x.spent_on))}</td><td>${e(x.title)}</td><td>${e(x.stage ? t(x.stage) : '')}</td><td class="n">${mv(x.amount)}</td></tr>`).join('')}
            <tr class="sum"><td colspan="3">${e(t('סה"כ'))}</td><td class="n">${mv(F.expenses.total)}</td></tr></tbody></table></div></section>`);
        if (F.fee) out.push(`<section><h3>${e(t('שכר הטרחה — {name}', { name: F.fee.name }))}</h3><dl class="frp-kpis">
            ${kpi(t('סוכם'), F.fee.amount)}${kpi(t('שולם'), F.fee.paid)}${kpi(t('יתרה'), F.fee.balance)}</dl></section>`);
        const P = (src.payments || []).filter(p => p.status !== 'cancelled');
        if (P.length) out.push(`<section><h3>${e(t('תשלומים'))}</h3><div class="frp-tw"><table>
            <thead><tr><th>${e(t('אבן דרך'))}</th><th>${e(t('ספק'))}</th><th>${e(t('שלב'))}</th><th>${e(t('לתשלום'))}</th><th>${e(t('שולם'))}</th><th>${e(t('סטטוס'))}</th></tr></thead>
            <tbody>${P.map(p => `<tr><td>${e(p.title)}${p.due_date ? `<small>${e(date(p.due_date))}</small>` : ''}</td><td>${e(p.supplier_name)}</td><td>${e(stageName(p.stage_id))}</td>
                <td class="n">${e(money(p.due_amount ?? p.amount))}${p.retention ? `<small>${e(t('עיכבון {amount}', { amount: money(p.retention) }))}</small>` : ''}</td>
                <td class="n">${e(money(p.received))}</td><td>${e(p.label)}${p.invoice ? `<small>🧾 ${e(p.invoice.no || t('חשבונית'))}</small>` : ''}</td></tr>`).join('')}</tbody></table></div></section>`);
        out.push(`<p class="foot">${e(t('כל הסכומים כוללים מע"מ ({n}%), ומתחתם — לפני מע"מ ומע"מ.', { n: S.vat_rate }))}</p>`);
        return `<div class="frp">${out.join('')}</div>`;
    }

    // ================= Excel (CSV) =================

    function csv() {
        const F = src.finance, T = F.totals;
        const L = [];
        const row = (...c) => L.push(c.map(v => {
            const s = String(v ?? '');
            const q = '\u0022'; // מרכאה — CSV
            return /[,\n\u0022]/.test(s) ? q + s.split(q).join(q + q) + q : s;
        }).join(','));
        const m = x => [x.g, x.n, Math.round((x.g - x.n) * 100) / 100];
        const vatHead = l => [l, t('לפני מע"מ'), t('מע"מ')];
        row(t('דוח כספי — {project}', { project: src.project.name }), new Date().toISOString().slice(0, 10));
        row();
        row(t('המצב הכספי'), ...vatHead(t('כולל מע"מ')));
        for (const [k, l] of [['budget_initial', 'תקציב ראשוני'], ['budget_today', 'תקציב היום'], ['committed', 'סוכם (התחייבויות)'], ['paid', 'שולם'], // i18n-ok — מתורגם ב-t(l)
            ['balance', 'יתרה לתשלום'], ['due', 'היה צריך עד היום'], ['forecast', 'צפי סופי'], ['retention', 'עיכבון'], ['index', 'הפרשי הצמדה']]) { // i18n-ok
            if (T[k]) row(t(l), ...m(T[k]));
        }
        row();
        row(t('שלב'), t('ספק'), ...vatHead(t('סוכם')), ...vatHead(t('שולם')), ...vatHead(t('יתרה')));
        for (const s of F.stages) for (const r of s.rows) row(s.stage_id ? (t(s.name) + (s.unit_name ? ' · ' + s.unit_name : '')) : t('בלי שלב'), r.name, ...m(r.committed), ...m(r.paid), ...m(r.balance));
        const X = F.expenses?.items || [];
        if (X.length) {
            row();
            row(t('הוצאות ישירות'), t('תאריך'), t('שלב'), ...vatHead(t('סכום')));
            for (const x of X) row(x.title, x.spent_on, x.stage ? t(x.stage) : '', ...m(x.amount));
        }
        if (F.fee) {
            row();
            row(t('שכר הטרחה — {name}', { name: F.fee.name }), ...vatHead(t('סוכם')), t('שולם'), t('יתרה'));
            row('', ...m(F.fee.amount), F.fee.paid?.g ?? 0, F.fee.balance?.g ?? F.fee.amount.g);
        }
        const P = (src.payments || []).filter(p => p.status !== 'cancelled');
        if (P.length) {
            row();
            row(t('אבן דרך'), t('ספק'), t('שלב'), t('תאריך יעד'), t('סכום'), t('עיכבון'), t('הפרשי הצמדה'), t('לתשלום'), t('שולם'), t('סטטוס'), t('חשבונית'));
            for (const p of P) row(p.title, p.supplier_name, stageName(p.stage_id), p.due_date, p.amount, p.retention || 0, p.index_diff || 0, p.due_amount ?? p.amount, p.received, p.label, p.invoice ? (p.invoice.no || '✓') : '');
        }
        const blob = new Blob(['﻿' + L.join('\r\n')], { type: 'text/csv;charset=utf-8' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `report-${new Date().toISOString().slice(0, 10)}.csv`;
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
    }

    // ================= הדפסה =================

    function print() {
        let root = document.getElementById('frp-print');
        if (!root) { root = document.createElement('div'); root.id = 'frp-print'; document.body.appendChild(root); }
        root.innerHTML = html();
        const html_ = document.documentElement;
        html_.classList.add('frp-printing');
        const done = () => { html_.classList.remove('frp-printing'); root.innerHTML = ''; window.removeEventListener('afterprint', done); };
        window.addEventListener('afterprint', done);
        window.print();
        setTimeout(() => { if (!matchMedia('print').matches) done(); }, 1500);
    }

    function open(data) {
        src = data;
        style();
        if (!dlg) {
            dlg = document.createElement('dialog');
            dlg.id = 'frp-dlg';
            dlg.setAttribute('aria-labelledby', 'frp-title');
            dlg.innerHTML = `<div class="frp-bar"><h2 id="frp-title"></h2>
                <button type="button" class="pri" data-frp="print">🖨️ ${e(t('הדפסה / PDF'))}</button>
                <button type="button" data-frp="csv">⬇️ Excel</button>
                <button type="button" data-frp="close" aria-label="${e(t('סגירה'))}">✕</button></div><div id="frp-body"></div>`;
            dlg.addEventListener('click', ev => {
                const b = ev.target.closest('[data-frp]');
                if (!b) return;
                if (b.dataset.frp === 'close') dlg.close();
                else if (b.dataset.frp === 'csv') csv();
                else print();
            });
            document.body.appendChild(dlg);
        }
        dlg.querySelector('#frp-title').textContent = t('דוח כספי');
        dlg.querySelector('#frp-body').innerHTML = html();
        dlg.showModal();
    }

    return { open };
})();
