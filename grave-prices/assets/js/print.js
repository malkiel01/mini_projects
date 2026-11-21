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

/** טבלת שורות (אותו סולם בכל מקום — 05 סעיף 1). gap — שורת רווח לפניה: חזרה מהזחה, ותחילת אזור האגרות. */
function table(rows, gaps) {
    return `<table class="pl"><colgroup><col><col class="c-amt"><col class="c-cur"></colgroup>
        <tbody>${rows.map((r, i) => `${gaps.has(i) ? '<tr class="sp"><td colspan="3"></td></tr>' : ''}<tr class="${r.kind}${r.indent ? ' indent' : ''}">
          <td class="lb"${r.priced ? '' : ' colspan="3"'}>${r.html}</td>
          ${r.priced ? `<td class="amt">${amount(r.price)}</td><td class="cur">₪</td>` : ''}
        </tr>`).join('')}</tbody></table>`;
}

/**
 * מחירון אחד: כותרת הקבוצה, התאריך והשורות. both — כותרת "תושבי …" (בלי subtitle) ורק השורות שלפני אזור האגרות;
 * האגרות — במרוכז, ב-feesBlock.
 */
function block(e, both) {
    const src = both ? e.rows.slice(0, e.layout.fee_start) : e.rows;
    const feeRow = both ? null : e.rows[e.layout.fee_start];
    const rows = rowsFor(src);
    const gaps = new Set();
    rows.forEach((r, i) => {
        const prev = rows[i - 1];
        if (!prev) return;
        if (r === feeRow || (r.kind === 'item' && !r.indent && prev.kind === 'item' && prev.indent)) gaps.add(i);
    });
    // אזור האגרות מתחיל בשורה שהוסתרה (כותרת בלי מחירים) — הרווח עובר לשורה הראשונה שנשארה בו
    if (feeRow && !rows.includes(feeRow)) {
        const first = rows.findIndex(r => e.rows.indexOf(r) > e.layout.fee_start);
        if (first > 0) gaps.add(first);
    }
    const body = !rows.some(r => r.priced) && !both
        ? `<p class="none">טרם הוזנו מחירים במחירון הזה</p>`
        : table(rows, gaps);
    const title = both ? `<h2 class="aud">${esc(e.audience_name)}</h2>` : `<h2>${esc(e.subtitle)}</h2>`;
    return `<div class="block">${title}<p class="valid">המחירון בתוקף החל מ- ${esc(dmy(e.effective_from))}</p>${body}</div>`;
}

/** אזור האגרות המרוכז של both (05 סעיף 2) — השורות והציון "(לתושבי …)" מהשרת (GET fees), כדי שהמסך וההדפסה לא יחלקו */
function feesBlock(f) {
    if (!f.rows.length) return '';
    const rows = f.rows.map(r => ({ ...r, indent: 0, priced: r.kind === 'fee' }));
    return `<div class="fees">${table([{ kind: 'subsection', html: esc(f.title), priced: false }, ...rows], new Set())}</div>`;
}

/** החתימות — סט אחד לכל ההדפסות (⚙️ הגדרות); ה-API מחזיר אותו בכל מחירון */
const sigs = e => `<div class="sigs">${e.signatures.map(s => `<div><b>${esc(s.name)}</b><span>${esc(s.role)}</span></div>`).join('')}</div>`;

/** עמוד: נייר מכתבים, הכותרת, התוכן, החתימות, נייר מכתבים תחתון */
function sheet(inner, signer) {
    return `<section class="page">
        <img class="lh top" src="assets/img/letterhead-top.jpg" alt="ארגון קהילות יהודי צפון אפריקה בירושלים">
        <div class="content"><h1>מחירון חברה קדישא לעדת המערבים</h1>${inner}</div>
        ${sigs(signer)}
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
        const [me, eIl, eAb, fees] = await Promise.all([get('me'), il ? get('edition', { pid: il }) : null,
            abroad ? get('edition', { pid: abroad }) : null, mode === 'both' ? get('fees', { il, abroad }) : null]);
        const back = eIl && eAb ? './' : './#/e/' + encodeURIComponent((eIl || eAb).pid);
        document.getElementById('back').href = back;
        document.title = 'מחירון ' + [eIl, eAb].filter(Boolean).map(e => e.audience_name + ' ' + dmy(e.effective_from)).join(' + ');

        const wait = async () => {
            await Promise.all([...box.querySelectorAll('img')].map(i => i.decode().catch(() => {})));
            await document.fonts.ready;
        };
        if (mode === 'one' || mode === 'split') {
            box.innerHTML = [eIl, eAb].filter(Boolean).map(e => sheet(block(e, false), e)).join('');
            await wait();
            box.querySelectorAll('.page').forEach(p => fit(p, MIN_ONE));
        } else {
            // זה לצד זה (הכרעת הבעלים 12:20; 05 סעיף 2): כותרת אחת, ישראל מימין וחו"ל משמאל, האגרות במרוכז לרוחב
            // הדף, חתימות פעם אחת. ההקטנה — לכל העמוד יחד. לא נכנס ב-70% — שני עמודים כמו split.
            box.innerHTML = sheet(`<div class="cols">${block(eIl, true)}${block(eAb, true)}</div>${feesBlock(fees)}`, eIl);
            await wait();
            if (!fit(box.querySelector('.page'), MIN_BOTH)) {
                box.innerHTML = sheet(block(eIl, false), eIl) + sheet(block(eAb, false), eAb);
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
