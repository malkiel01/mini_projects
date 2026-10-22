/** סגנונות טבלה (שלב 3): גבולות, צבע, מילוי לשורה הראשונה ופסים — בבחירה אחת; ביטול אחד; זהה ב-PDF מהשרת. */
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';

const P = text => ({ type: 'paragraph', content: [{ type: 'text', text }] });
const R = (...t) => ({ type: 'tableRow', content: t.map(x => ({ type: 'tableCell', content: [P(x)] })) });

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'סגנונות טבלה', { type: 'doc', content: [P('לפני'),
        { type: 'table', content: [R('שם', 'סכום'), R('א', '1'), R('ב', '2'), R('ג', '3'), R('ד', '4')] }, P('אחרי')] });
    await t.openDoc(p, doc);
    const E = fn => p.evaluate(fn);
    const json = () => E(() => document.querySelector('.wp-doc').editor.getJSON().content[1]);
    await E(() => { const e = document.querySelector('.wp-doc').editor; let at = 0; e.state.doc.descendants((n, pos) => { if (n.isText && n.text === 'ב') at = pos + 1; }); e.chain().focus().setTextSelection(at).run(); });
    await p.waitForTimeout(150);
    // הלשונית "טבלה" (מופיעה כשהסמן בטבלה)
    await E(() => { const pn = document.querySelector('.wp-ribbon select.w-tstyle')?.closest('[role=tabpanel]'); if (pn?.hidden) document.getElementById(pn.getAttribute('aria-labelledby')).click(); });
    t.ok('בחירת סגנון בלשונית "טבלה"', await p.locator('.wp-ribbon select.w-tstyle').isVisible());
    await p.selectOption('.wp-ribbon select.w-tstyle', 'greenBands');
    await p.waitForTimeout(150);
    const j = await json();
    const fills = j.content.map(r => r.content.map(c => c.attrs.background));
    t.ok('גבולות ומסגרת בצבע', j.attrs.borders === 'outer' && j.attrs.borderColor === '#538135', JSON.stringify(j.attrs));
    t.ok('שורה ראשונה בצבע, ופסים בשורות 3 ו-5', JSON.stringify(fills) === JSON.stringify([['#c5e0b3', '#c5e0b3'], [null, null], ['#e2efd9', '#e2efd9'], [null, null], ['#e2efd9', '#e2efd9']]), JSON.stringify(fills));
    t.ok('במסך — המילוי והפס', await p.locator('.wp-doc table tr').nth(2).locator('td').first().evaluate(e => getComputedStyle(e).backgroundColor) === 'rgb(226, 239, 217)');
    t.ok('הבחירה חוזרת ל"סגנון טבלה…" (אפשר לבחור שוב)', (await p.inputValue('.wp-ribbon select.w-tstyle')) === '');

    // סגנון אחר מחליף — לא מצטבר
    await p.selectOption('.wp-ribbon select.w-tstyle', 'plain');
    await p.waitForTimeout(150);
    const j2 = await json();
    t.ok('"רגיל" — בלי מילוי ובלי צבע גבולות', !j2.attrs.borders && !j2.attrs.borderColor && j2.content.every(r => r.content.every(c => !c.attrs.background)));
    await p.keyboard.press('Control+z');
    await p.waitForTimeout(150);
    t.ok('ביטול אחד מחזיר את הסגנון הקודם', (await json()).content[2].content[0].attrs.background === '#e2efd9');

    // PDF מהשרת: הצבעים מצוירים (המילוי הירוק בהקסה של PDF)
    await p.keyboard.press('Control+s');
    await p.waitForTimeout(600);
    const res = await p.request.get(t.editor + `api.php?action=my_export&format=pdf&doc=${doc}`);
    const f = join(t.data, 'tstyle.pdf');
    writeFileSync(f, await res.body());
    t.ok('PDF מהשרת — עמוד אחד, הטקסט שלם', t.pdfPages(f).length === 1 && t.pdfPages(f)[0].includes('סכום'));
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
}
