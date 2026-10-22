/** הערות סיום (שלב 4): מרוכזות בסוף המסמך (לא בתחתית העמוד), ממוספרות i, ii…; Ctrl+Alt+D; עריכה בלחיצה; נשמר; PDF מהשרת. */
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';

const P = (...c) => ({ type: 'paragraph', content: c });
const T = text => ({ type: 'text', text });
const EN = text => ({ type: 'footnote', attrs: { text, end: true } });
const FN = text => ({ type: 'footnote', attrs: { text } });
const BR = { type: 'pageBreak' };

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'הערות סיום', { type: 'doc', content: [P(T('עמוד ראשון'), EN('הערת סיום ראשונה')), P(T('שוליים'), FN('הערת שוליים')), BR, P(T('עמוד שני'))] });
    await t.openDoc(p, doc);
    await t.settle(p);
    const st = () => p.evaluate(() => {
        const pages = [...document.querySelectorAll('.wp-pages .wp-page')].map(x => x.getBoundingClientRect());
        const pageOf = el => { const r = el.getBoundingClientRect(); return pages.findIndex(q => r.top >= q.top - 1 && r.bottom <= q.bottom + 1) + 1; };
        const en = [...document.querySelectorAll('.wp-en-area .wp-fn-note')];
        return {
            pages: pages.length,
            en: en.map(n => n.textContent.trim()), enPage: en.map(pageOf),
            fn: [...document.querySelectorAll('.wp-fn-area:not(.wp-en-area) .wp-fn-note')].map(n => n.textContent.trim()),
            refs: [...document.querySelectorAll('.wp-doc .wp-fn-ref')].map(r => getComputedStyle(r, '::after').content),
            afterBody: (() => { const a = document.querySelector('.wp-en-area'); const last = [...document.querySelectorAll('.wp-doc > p')].at(-1);
                return !!a && !!last && a.getBoundingClientRect().top >= last.getBoundingClientRect().bottom - 1; })(),
        };
    });
    let s = await st();
    t.ok('הערת הסיום בסוף המסמך (עמוד 2), השוליים — בעמוד 1', s.pages === 2 && s.enPage.join() === '2' && s.en[0].includes('הערת סיום ראשונה') && s.fn.length === 1, JSON.stringify(s));
    t.ok('אחרי הטקסט האחרון', s.afterBody);
    t.ok('הסימנים: מונה רומי להערת סיום, מונה רגיל להערת שוליים', s.refs.join() === 'counter(wpen, lower-roman),counter(wpfn)', s.refs.join());

    // Ctrl+Alt+D בסוף העמוד השני — הערה שנייה
    await p.evaluate(() => { const e = document.querySelector('.wp-doc').editor; e.chain().focus().setTextSelection(e.state.doc.content.size - 1).run(); });
    await p.keyboard.press('Control+Alt+KeyD');
    await p.waitForSelector('dialog .fn-edit-doc');
    t.ok('החלון מציג "הערת סיום"', (await p.locator('dialog').textContent()).includes('הערת סיום'));
    await p.fill('dialog .fn-edit-doc', 'שנייה');
    await p.click('dialog button[type=submit]');
    await p.waitForSelector('dialog', { state: 'detached' });
    await t.settle(p);
    s = await st();
    t.ok('שתי הערות סיום, i ו-ii, בסוף', s.en.length === 2 && s.en[0].startsWith('i') && s.en[1].startsWith('ii') && s.en[1].includes('שנייה') && s.refs.length === 3, JSON.stringify(s));

    // עריכה בלחיצה על ההערה בסוף
    await p.click('.wp-en-area .wp-fn-note >> nth=0');
    await p.fill('dialog .fn-edit-doc', 'מתוקנת');
    await p.click('dialog button[type=submit]');
    await p.waitForSelector('dialog', { state: 'detached' });
    await t.settle(p);
    t.ok('עריכה בלחיצה', (await st()).en[0].includes('מתוקנת'));

    await p.keyboard.press('Control+s');
    await p.waitForTimeout(600);
    const saved = JSON.stringify((await t.api(p, 'my_open', { doc })).json);
    t.ok('נשמר עם end', (saved.match(/"end":true/g) || []).length === 2 && saved.includes('מתוקנת'));
    const res = await p.request.get(t.editor + `api.php?action=my_export&format=pdf&doc=${doc}`);
    const f = join(t.data, 'endnotes.pdf');
    writeFileSync(f, await res.body());
    const pages = t.pdfPages(f);
    t.ok('PDF מהשרת: הערות הסיום בעמוד האחרון בלבד', pages.length === 2 && pages[1].includes('מתוקנת') && pages[1].includes('שנייה') && !pages[0].includes('מתוקנת') && pages[0].includes('הערת שוליים'), pages.join(' ## '));
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
}
