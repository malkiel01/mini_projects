// דף ההדפסה — print.html?e=<pid>&mode=il|abroad|split|both. עמוד A4 כמו המחירון המקורי (01-foundation-print.md סעיף 3).
// נטען, בונה את העמוד/ים, רושם ביומן, ופותח את חלון ההדפסה לבד. בלי הדפסה — זו תצוגה מקדימה נאמנה.
'use strict';

const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const dmy = iso => iso.split('-').reverse().join('/');
const amount = n => n == null ? '—' : n.toLocaleString('en-US');
const HEADS = { section: 0, subsection: 1, heading: 2 };

async function get(action, params = {}) {
    const res = await fetch('api.php?' + new URLSearchParams({ action, ...params }), { credentials: 'same-origin' });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'תקלה (' + res.status + ')');
    return data;
}

/**
 * השורות של קבוצה אחת: שורת מחיר בלי מחיר — לא מודפסת, וכותרת שכל שורות המחיר שתחתיה הוסתרו — גם לא.
 * מה ש"תחת" כותרת: עד הכותרת הבאה מאותה רמה ומעלה; תחת heading — רק השורות המוזחות שאחריה.
 */
function rowsFor(rows, aud) {
    const keep = rows.map(r => r.prices ? r.prices[aud] != null : r.kind === 'note');
    rows.forEach((r, i) => {
        if (!(r.kind in HEADS)) return;
        for (let j = i + 1; j < rows.length; j++) {
            const k = rows[j];
            if (k.kind in HEADS ? HEADS[k.kind] <= HEADS[r.kind] || r.kind === 'heading' : r.kind === 'heading' && !k.indent) break;
            if (k.kind === 'item' && keep[j]) { keep[i] = true; break; }
        }
    });
    return rows.filter((_, i) => keep[i]);
}

/** רווח לפני שורה — כמו במקור: חזרה מהזחה, ותחילת התשלומים / ההערות. */
function gapBefore(r, prev) {
    if (!prev) return false;
    if (r.kind === 'item' && !r.indent && prev.kind === 'item' && prev.indent) return true;
    return (r.kind === 'fee' || r.kind === 'note') && prev.kind !== 'fee' && prev.kind !== 'note';
}

function page(e, auds) {
    const names = e.audiences;
    const single = auds.length === 1;
    const rows = single ? rowsFor(e.rows, auds[0]) : e.rows;
    const priced = rows.some(r => r.prices);
    const cols = auds.length * 2;
    const body = !priced
        ? `<p class="none">טרם הוזנו מחירים ל${esc(names[auds[0]])}</p>`
        : `<table class="pl ${single ? 'one' : 'two'}">
            <colgroup><col>${auds.map(() => '<col class="c-amt"><col class="c-cur">').join('')}</colgroup>
            ${single ? '' : `<thead><tr><th></th>${auds.map(a => `<th colspan="2">${esc(names[a])}</th>`).join('')}</tr></thead>`}
            <tbody>${rows.map((r, i) => `<tr class="${r.kind}${r.indent ? ' indent' : ''}${gapBefore(r, rows[i - 1]) ? ' gap' : ''}">
              <td class="lb"${r.prices ? '' : ` colspan="${cols + 1}"`}>${r.html}</td>
              ${r.prices ? auds.map(a => `<td class="amt">${amount(r.prices[a])}</td><td class="cur">${r.prices[a] == null ? '' : '₪'}</td>`).join('') : ''}
            </tr>`).join('')}</tbody></table>`;
    return `<section class="page">
        <img class="lh top" src="assets/img/letterhead-top.jpg" alt="ארגון קהילות יהודי צפון אפריקה בירושלים">
        <div class="content">
          <h1>מחירון חברה קדישא לעדת המערבים</h1>
          <h2>רכישת אחוזת קבר ל${auds.map(a => esc(names[a])).join(' ול')}</h2>
          <p class="valid">המחירון בתוקף החל מ- ${esc(dmy(e.effective_from))}</p>
          ${body}
        </div>
        <div class="sigs">${e.signatures.map(s => `<div><b>${esc(s.name)}</b><b>${esc(s.role)}</b></div>`).join('')}</div>
        <img class="lh bottom" src="assets/img/letterhead-bottom.jpg" alt="">
      </section>`;
}

/** מחירון אחד = עמוד אחד: תוכן ארוך — מקטינים את הגופן עד 80%, לא גולשים. */
function fit(p) {
    const c = p.querySelector('.content');
    for (let s = 1; c.scrollHeight > c.clientHeight + 1 && s > 0.8; ) {
        s = Math.max(0.8, s - 0.025);
        c.style.fontSize = (s * 100) + '%';
    }
}

(async () => {
    const q = new URLSearchParams(location.search);
    const pid = q.get('e') || '';
    const mode = q.get('mode') || 'il';
    const box = document.getElementById('pages');
    document.getElementById('back').href = './#/e/' + encodeURIComponent(pid);
    document.getElementById('print').onclick = () => print();
    try {
        const sets = { il: [['il']], abroad: [['abroad']], split: [['il'], ['abroad']], both: [['il', 'abroad']] }[mode];
        if (!sets) throw new Error('תצורת הדפסה לא מוכרת');
        const [me, e] = await Promise.all([get('me'), get('edition', { pid })]);
        document.title = 'מחירון ' + dmy(e.effective_from);
        box.innerHTML = sets.map(a => page(e, a)).join('');
        await Promise.all([...box.querySelectorAll('img')].map(i => i.decode().catch(() => {})));
        await document.fonts.ready;
        box.querySelectorAll('.page').forEach(fit);
        fetch('api.php?action=print-log', { method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF': me.csrf }, body: JSON.stringify({ pid, mode }) }).catch(() => {});
        if (!q.has('preview')) setTimeout(() => print(), 100);
    } catch (err) {
        box.innerHTML = `<p class="msg err">${esc(err.message)}</p>`;
    }
})();
