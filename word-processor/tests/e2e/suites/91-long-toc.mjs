/**
 * תוכן עניינים ארוך מעמוד (שלב 3 / 4): נשבר בין רשומות — כמו פסקה ארוכה, ולא חורג מהדף. הכותרת שלו נשארת עם הרשומה
 * הראשונה; שום רשומה לא יוצאת מאזור הטקסט של העמוד שלה; ה-PDF מהשרת — אותו מספר עמודים ואותה תחילת עמוד שני;
 * ההערה שבעמוד שנגמר בתוך תוכן העניינים — בתחתית העמוד הזה, לא לפני תוכן העניינים.
 */
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';

export default async function (t) {
    const p = await t.page();
    const entries = Array.from({ length: 70 }, (_, i) => ({ t: `פרק ${i + 1} — כותרת לדוגמה`, l: i % 3 ? 2 : 1, p: String(2 + Math.floor(i / 2)), i }));
    const doc = await t.newDoc(p, 'תוכן עניינים ארוך', { type: 'doc', content: [
        { type: 'paragraph', content: [{ type: 'text', text: 'פתיחה עם הערה' }, { type: 'footnote', attrs: { text: 'הערת שוליים בעמוד הראשון' } }] },
        { type: 'toc', attrs: { levels: 3, entries } },
        { type: 'paragraph', content: [{ type: 'text', text: 'אחרי תוכן העניינים' }] },
    ] });
    await t.openDoc(p, doc);
    await t.settle(p);
    const r = await p.evaluate(() => {
        const frames = [...document.querySelectorAll('.wp-pages .wp-page')].map(f => f.getBoundingClientRect());
        const z = frames[0].width / document.querySelector('.wp-pages .wp-page').offsetWidth;
        const bottomOf = f => f.bottom - 25 * 96 / 25.4 * z;   // תחתית אזור הטקסט (שוליים 25 מ"מ)
        const pageOf = el => { const b = el.getBoundingClientRect(); return frames.findIndex(f => b.top >= f.top - 1 && b.top < f.bottom); };
        const lines = [...document.querySelectorAll('.wp-toc .wp-toc-line')];
        const over = lines.filter(l => { const k = pageOf(l); return k < 0 || l.getBoundingClientRect().bottom > bottomOf(frames[k]) + 1; }).length;
        const fnArea = document.querySelector('.wp-fn-area');
        return { pages: frames.length, breaks: document.querySelectorAll('.wp-toc .wp-break').length, over,
            titlePage: pageOf(document.querySelector('.wp-toc-title')), firstPage: pageOf(lines[0]), lastPage: pageOf(lines.at(-1)),
            fnInToc: !!fnArea?.closest('.wp-toc'), fnPage: fnArea ? pageOf(fnArea.querySelector('.wp-fn-sep') || fnArea) : -1,
            fnBelowFirstLine: fnArea ? fnArea.querySelector('.wp-fn-sep').getBoundingClientRect().top > lines[0].getBoundingClientRect().bottom : false,
            page2: (() => { const b = document.querySelector('.wp-toc .wp-break'); return b?.nextElementSibling?.textContent || ''; })() };
    });
    t.ok('נשבר בין רשומות: על פני כמה עמודים, ושום רשומה לא חורגת מאזור הטקסט', r.pages >= 3 && r.breaks >= 2 && r.over === 0 && r.lastPage >= 2, JSON.stringify(r));
    t.ok('הכותרת בעמוד של הרשומה הראשונה', r.titlePage === r.firstPage, JSON.stringify(r));
    t.ok('ההערה של העמוד הראשון — בתחתית העמוד הראשון (בתוך תוכן העניינים, אחרי הרשומות שבו)', r.fnInToc && r.fnPage === 0 && r.fnBelowFirstLine, JSON.stringify(r));

    // PDF מהשרת: אותו מספר עמודים, והעמוד השני מתחיל באותה רשומה
    const pdf = join(t.data, 'long-toc.pdf');
    writeFileSync(pdf, await (await p.request.get(t.editor + `api.php?action=my_export&format=pdf&doc=${doc}`)).body());
    const pages = t.pdfPages(pdf);
    // (pdftotext מזיז את המספר שליד העברית — "פרק  — 33כותרת"; משווים לפי מספר הרשומה)
    const n = +r.page2.match(/פרק (\d+)/)[1];
    const has = (txt, k) => new RegExp(`—\\s*${k}\\D`).test(txt);
    t.ok('PDF: אותו מספר עמודים, והעמוד השני מתחיל באותה רשומה', pages.length === r.pages && has(pages[1], n) && !has(pages[1], n - 1) && has(pages[0], n - 1),
        JSON.stringify({ editor: r.pages, pdf: pages.length, first: n, pdf2: pages[1]?.slice(0, 60) }));
    // הדפסה מהדפדפן — אותם עמודים (המעבר בתוך תוכן העניינים — break-before, כמו בכל רווח של עמוד)
    const printPdf = join(t.data, 'long-toc.print.pdf');
    writeFileSync(printPdf, await p.pdf({ preferCSSPageSize: true, printBackground: true }));
    const printed = t.pdfPages(printPdf);
    t.ok('הדפסה: אותו מספר עמודים, והעמוד השני מתחיל באותה רשומה', printed.length === r.pages && has(printed[1], n) && !has(printed[1], n - 1),
        JSON.stringify({ editor: r.pages, print: printed.length, print2: printed[1]?.slice(0, 60) }));
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
