// דף ההדפסה — print.html?il=<pid>&abroad=<pid>&mode=one|split|both (03-per-audience.md סעיף 6). עמוד A4 כמו המחירון המקורי.
// קישור ישן print.html?e=<pid>&mode=il|abroad|split|both — ממשיך לעבוד: e הוא מחירון ישראל, והשאר משלים את חו"ל שבתוקף היום.
// נטען, בונה את העמוד/ים, רושם ביומן, ופותח את חלון ההדפסה לבד. עם ?preview — רק תצוגה מקדימה נאמנה.
'use strict';

const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const dmy = iso => iso.split('-').reverse().join('/');
const amount = n => n == null ? '—' : n.toLocaleString('en-US');
const HEADS = { section: 0, band: 0, subsection: 1, heading: 2 };
const MIN_ONE = 0.8;    // מחירון אחד בעמוד — עד 80%
const MIN_BOTH = 0.7;   // שתיהן בדף אחד — עד 70%, ואז חו"ל עובר לעמוד שני
const BASE_PT = 10.5;   // גודל הבסיס של .content ב-print.css

async function get(action, params = {}) {
    const res = await fetch('api.php?' + new URLSearchParams({ action, ...params }), { credentials: 'same-origin' });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'תקלה (' + res.status + ')');
    return data;
}

/**
 * השורות שמודפסות: שורת מחיר בלי מחיר — לא, וכותרת שכל שורות המחיר שתחתיה הוסתרו — גם לא.
 * מה ש"תחת" כותרת: עד הכותרת הבאה מאותה רמה ומעלה; תחת heading — רק השורות המוזחות שאחריה.
 */
function rowsFor(rows) {
    const keep = rows.map(r => r.priced ? r.price != null : r.kind === 'note');
    rows.forEach((r, i) => {
        if (!(r.kind in HEADS)) return;
        for (let j = i + 1; j < rows.length; j++) {
            const k = rows[j];
            if (k.kind in HEADS ? HEADS[k.kind] <= HEADS[r.kind] || r.kind === 'heading' : r.kind === 'heading' && !k.indent) break;
            if (k.priced && keep[j]) { keep[i] = true; break; }
        }
    });
    return rows.filter((_, i) => keep[i]);
}

/** רווח לפני שורה — כמו במקור: חזרה מהזחה, ותחילת התשלומים / ההערות. */
function gapBefore(r, prev) {
    if (!prev) return false;
    if (r.kind === 'item' && !r.indent && prev.kind === 'item' && prev.indent) return true;
    return (r.kind === 'fee' || r.kind === 'note') && prev.kind !== 'fee' && prev.kind !== 'note' && prev.kind !== 'subsection';
}

/** כותרת המשנה, התאריך והשורות של מחירון אחד */
function block(e) {
    const rows = rowsFor(e.rows);
    const body = !rows.some(r => r.priced)
        ? `<p class="none">טרם הוזנו מחירים במחירון הזה</p>`
        : `<table class="pl ${esc(e.audience)}"><colgroup><col><col class="c-amt"><col class="c-cur"></colgroup>
            <tbody>${rows.map((r, i) => `<tr class="${r.kind}${r.indent ? ' indent' : ''}${gapBefore(r, rows[i - 1]) ? ' gap' : ''}">
              <td class="lb"${r.priced ? '' : ' colspan="3"'}>${r.html}</td>
              ${r.priced ? `<td class="amt">${amount(r.price)}</td><td class="cur">₪</td>` : ''}
            </tr>`).join('')}</tbody></table>`;
    return `<div class="block"><h2>${esc(e.subtitle)}</h2><p class="valid">המחירון בתוקף החל מ- ${esc(dmy(e.effective_from))}</p>${body}</div>`;
}

const sigs = e => `<div class="sigs">${e.signatures.map(s => `<div><b>${esc(s.name)}</b><b>${esc(s.role)}</b></div>`).join('')}</div>`;

/** עמוד: נייר מכתבים, התוכן, החתימות (אם יש), נייר מכתבים תחתון */
function sheet(inner, signer, title = true) {
    return `<section class="page">
        <img class="lh top" src="assets/img/letterhead-top.jpg" alt="ארגון קהילות יהודי צפון אפריקה בירושלים">
        <div class="content">${title ? '<h1>מחירון חברה קדישא לעדת המערבים</h1>' : ''}${inner}</div>
        ${signer ? sigs(signer) : '<div class="sigs-gap"></div>'}
        <img class="lh bottom" src="assets/img/letterhead-bottom.jpg" alt="">
      </section>`;
}

/** מקטינים את הגופן עד min; מחזיר true אם נכנס */
function fit(p, min) {
    const c = p.querySelector('.content');
    let s = 1;
    c.style.fontSize = '';
    while (c.scrollHeight > c.clientHeight + 1 && s > min) {
        s = Math.max(min, Math.round((s - 0.025) * 1000) / 1000);
        c.style.fontSize = (BASE_PT * s) + 'pt';
    }
    return c.scrollHeight <= c.clientHeight + 1;
}

/** החתימות ב"שתיהן בדף אחד": של המחירון שתאריכו מאוחר יותר (שאלה 16); באותו תאריך — של ישראל, הבלוק הראשון */
const signer = (il, ab) => ab.effective_from > il.effective_from ? ab : il;

/** פרמטרים → {il, abroad, mode}. קישור ישן (e=) — משלימים את הקבוצה השנייה ממה שבתוקף היום. */
async function resolve(q) {
    if (!q.has('e')) {
        const ids = { il: q.get('il') || '', abroad: q.get('abroad') || '' };
        return { ...ids, mode: q.get('mode') || (ids.il && ids.abroad ? 'split' : 'one') };
    }
    const legacy = q.get('mode') || 'il';
    const [e, { editions }] = await Promise.all([get('edition', { pid: q.get('e') }), get('editions')]);
    const current = aud => (editions.find(x => x.audience === aud && x.in_effect) || {}).pid || '';
    const other = e.audience === 'il' ? 'abroad' : 'il';
    if (legacy === 'il' || legacy === 'one') return { [e.audience]: e.pid, [other]: '', mode: 'one' };
    if (legacy === 'abroad') return e.audience === 'abroad' ? { abroad: e.pid, il: '', mode: 'one' } : { abroad: current('abroad'), il: '', mode: 'one' };
    return { [e.audience]: e.pid, [other]: current(other), mode: legacy };
}

(async () => {
    const q = new URLSearchParams(location.search);
    const box = document.getElementById('pages');
    document.getElementById('print').onclick = () => print();
    try {
        const { il, abroad, mode } = await resolve(q);
        if (!['one', 'split', 'both'].includes(mode)) throw new Error('תצורת הדפסה לא מוכרת');
        if (mode === 'one' && !!il === !!abroad) throw new Error('צריך מחירון אחד');
        if (mode !== 'one' && !(il && abroad)) throw new Error('להדפסה משותפת צריך מחירון מכל קבוצה');
        const [me, eIl, eAb] = await Promise.all([get('me'), il ? get('edition', { pid: il }) : null, abroad ? get('edition', { pid: abroad }) : null]);
        const back = eIl && eAb ? './' : './#/e/' + encodeURIComponent((eIl || eAb).pid);
        document.getElementById('back').href = back;
        document.title = 'מחירון ' + [eIl, eAb].filter(Boolean).map(e => e.audience_name + ' ' + dmy(e.effective_from)).join(' + ');

        const wait = async () => {
            await Promise.all([...box.querySelectorAll('img')].map(i => i.decode().catch(() => {})));
            await document.fonts.ready;
        };
        if (mode === 'one' || mode === 'split') {
            box.innerHTML = [eIl, eAb].filter(Boolean).map(e => sheet(block(e), e)).join('');
            await wait();
            box.querySelectorAll('.page').forEach(p => fit(p, MIN_ONE));
        } else {
            // עמוד אחד: כותרת אחת, ישראל, קו, חו"ל, חתימות פעם אחת. לא נכנס ב-70% — חו"ל עובר לעמוד שני.
            const sg = signer(eIl, eAb);
            box.innerHTML = sheet(block(eIl) + '<hr class="sep">' + block(eAb), sg);
            await wait();
            if (!fit(box.querySelector('.page'), MIN_BOTH)) {
                box.innerHTML = sheet(block(eIl), null) + sheet(block(eAb), sg, false);
                await wait();
                box.querySelectorAll('.page').forEach(p => fit(p, MIN_ONE));
            }
        }
        document.body.dataset.pages = box.querySelectorAll('.page').length;   // לבדיקות
        fetch('api.php?action=print-log', { method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF': me.csrf }, body: JSON.stringify({ il, abroad, mode }) }).catch(() => {});
        if (!q.has('preview')) setTimeout(() => print(), 100);
    } catch (err) {
        box.innerHTML = `<p class="msg err">${esc(err.message)}</p>`;
    }
})();
