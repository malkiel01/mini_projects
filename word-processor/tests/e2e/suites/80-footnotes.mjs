import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
/** אב-טיפוס (שלב 0, חלק 4): הערות שוליים — הוספה, מספור, ההערה בתחתית העמוד שלה, עריכה ומחיקה. */
export default async function (t) {
    const p = await t.page();
    const { doc } = await t.api(p, 'my_new', { title: 'הערות' });
    await t.openDoc(p, doc);
    await p.click('.wp-doc');
    await p.keyboard.type('טקסט עם הערה');
    await p.click('button[aria-label="הערת שוליים (Ctrl+Alt+F)"]');
    await p.fill('dialog textarea', 'זו ההערה הראשונה');
    await p.click('dialog button[type=submit]');
    await p.waitForSelector('dialog', { state: 'detached' });
    await p.keyboard.type(' ועוד טקסט');
    await p.keyboard.press('Control+Alt+KeyF');
    await p.fill('dialog textarea', 'הערה שנייה');
    await p.click('dialog button[type=submit]');
    await t.settle(p);
    const st = await p.evaluate(() => ({
        refs: [...document.querySelectorAll('.wp-doc .wp-fn-ref')].map(r => getComputedStyle(r, '::after').content),
        notes: [...document.querySelectorAll('.wp-fn-area .wp-fn-note')].map(n => n.textContent),
        inPage: (() => { const a = document.querySelector('.wp-fn-area'); const pg = document.querySelector('.wp-pages .wp-page');
            if (!a || !pg) return 'אין'; const r = a.getBoundingClientRect(), q = pg.getBoundingClientRect();
            return r.bottom <= q.bottom + 1 && r.bottom > q.top + q.height * 0.75 ? true : `${Math.round(r.top - q.top)}–${Math.round(r.bottom - q.top)} מתוך ${Math.round(q.height)}`; })(),
    }));
    t.ok('שתי הערות, ממוספרות 1 ו-2', st.refs.length === 2 && st.notes.length === 2 && st.notes[0].includes('1') && st.notes[1].includes('הערה שנייה'), st.notes.join(' | '));
    t.ok('ההערות בתחתית העמוד שבו הסימן', st.inPage === true, String(st.inPage));
    await p.waitForTimeout(2500);
    let s = await t.api(p, 'my_open', { doc });
    t.ok('נשמר: הערות במסמך', JSON.stringify(s.json).includes('"type":"footnote"') && JSON.stringify(s.json).includes('זו ההערה הראשונה'));

    // עריכה בלחיצה על ההערה בתחתית העמוד
    await p.click('.wp-fn-area .wp-fn-note >> nth=0');
    await p.fill('dialog textarea', 'הערה מתוקנת');
    await p.click('dialog button[type=submit]');
    await t.settle(p);
    t.ok('עריכה בלחיצה על ההערה', (await p.locator('.wp-fn-area .wp-fn-note').first().textContent()).includes('הערה מתוקנת'));
    // מחיקה: טקסט ריק
    await p.click('.wp-doc .wp-fn-ref >> nth=1');
    await p.fill('dialog textarea', '');
    await p.click('dialog button[type=submit]');
    await t.settle(p);
    t.ok('מחיקה: טקסט ריק מוחק את ההערה', await p.locator('.wp-doc .wp-fn-ref').count() === 1 && await p.locator('.wp-fn-area .wp-fn-note').count() === 1);

    // הערה ארוכה שלא נכנסת כולה בתחתית העמוד — נמשכת לעמוד הבא (כמו ב-Word): שני חלקים, ביחד כל ההערה
    const c = JSON.parse(readFileSync(join(t.app, 'tests', 'corpus', 'long-footnotes.json'), 'utf8'));
    const doc2 = await t.newDoc(p, c.title, c.json, c.page);
    await t.openDoc(p, doc2);
    const parts = await p.evaluate(() => {
        const ps = [...document.querySelectorAll('.wp-fn-area .wp-fn-part')];
        const areas = [...document.querySelectorAll('.wp-fn-area')];
        return ps.map(x => ({ pos: x.firstChild.dataset.pos, h: x.getBoundingClientRect().height, full: x.firstChild.getBoundingClientRect().height,
            area: areas.indexOf(x.closest('.wp-fn-area')), first: x === x.closest('.wp-fn-area').querySelector('.wp-fn-sep').nextElementSibling,
            text: x.firstChild.textContent }));
    });
    t.ok('הערה ארוכה: שני חלקים בשני עמודים רצופים', parts.length === 2 && parts[0].pos === parts[1].pos && parts[1].area === parts[0].area + 1,
        JSON.stringify(parts.map(x => [x.area, Math.round(x.h)])));
    if (parts.length === 2) {
        t.ok('הערה ארוכה: ביחד — כל ההערה, וההמשך ראשון בעמוד הבא', Math.abs(parts[0].h + parts[1].h - parts[0].full) < 2 && parts[1].first,
            `${Math.round(parts[0].h)} + ${Math.round(parts[1].h)} מול ${Math.round(parts[0].full)}`);
        // סוף ההערה — בעמוד הבא גם בהדפסה וגם ב-PDF מהשרת
        const tail = parts[0].text.trim().split(/\s+/).slice(-3).join(' ').replace(/[.‎‏]/g, '');
        const heb = x => x.replace(/[^א-ת ]/g, '').replace(/\s+/g, ' ');
        const printPdf = join(t.data, 'long-fn.print.pdf');
        writeFileSync(printPdf, await p.pdf({ preferCSSPageSize: true, printBackground: true }));
        const pdf = join(t.data, 'long-fn.pdf');
        writeFileSync(pdf, await (await p.request.get(t.editor + `api.php?action=my_export&format=pdf&doc=${doc2}`)).body());
        for (const [name, f] of [['הדפסה', printPdf], ['PDF מהשרת', pdf]]) {
            const pages = t.pdfPages(f).map(heb);
            t.ok(`הערה ארוכה: סוף ההערה בעמוד הבא — ${name}`, pages[parts[1].area]?.includes(heb(tail)) && !pages[parts[0].area]?.includes(heb(tail)), tail);
        }
    }
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
