/**
 * הערות שוליים בתוך גבולות הדף (ביקורת 04, R08 / F05 / F10): הערה ארוכה מכמה עמודים — בסוף המסמך ובאמצעו, שתי
 * הערות ארוכות רצופות, הערה בעמוד של מקטע צר אחרי מקטע לרוחב, והערה בתא טבלה. בכל מסמך: שער הגבולות (כל מילה
 * בתוך אזור התוכן של העמוד שלה, ושום מילה לא מוסתרת — חלק של הערה תקין רק אם ההמשך שלו גלוי בעמוד אחר),
 * וגם בהדפסה וב-PDF מהשרת — ההערה כולה, עד המילה האחרונה שלה.
 */
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { checkBounds } from '../lib/bounds.mjs';

const P = (text, ...more) => ({ type: 'paragraph', content: [{ type: 'text', text }, ...more] });
const FN = text => ({ type: 'footnote', attrs: { text } });
const long = n => Array.from({ length: n }, (_, i) => `שורה ${i + 1} של טקסט ארוך מספיק כדי להישבר לכמה שורות בעמוד, עם מספרים 2025 ו-English words.`).join(' ');
// הערה בגובה של כמה עמודים, עם מילה אחרונה ייחודית — כדי לוודא שהיא מגיעה לכל פלט
const noteText = (words, end) => Array.from({ length: words }, (_, i) => `מילה${i + 1}`).join(' ') + ' ' + end;
const heb = x => x.replace(/[^א-ת0-9]/g, '');

export default async function (t) {
    const p = await t.page();
    const gate = async label => {
        await t.settle(p);
        const bad = await checkBounds(p);
        t.ok(`גבולות: ${label}`, bad.length === 0, bad.slice(0, 3).map(b => `${b.why} (עמוד ${b.page}) "${b.text}" ${b.rect} / ${b.box || ''}`).join(' | '));
    };
    // ההערה כולה בהדפסה (העמודים שעל המסך) וב-PDF מהשרת: כל המילים הממוספרות, והמילה האחרונה
    const outputs = async (doc, label, words, end) => {
        const files = [['הדפסה', join(t.data, `fnb-${doc}.print.pdf`)], ['PDF מהשרת', join(t.data, `fnb-${doc}.pdf`)]];
        writeFileSync(files[0][1], await p.pdf({ preferCSSPageSize: true, printBackground: true }));
        writeFileSync(files[1][1], await (await p.request.get(t.editor + `api.php?action=my_export&format=pdf&doc=${doc}`)).body());
        for (const [name, f] of files) {
            const all = heb(t.pdfPages(f).join(' '));
            const missing = [];
            for (let i = 1; i <= words; i += 37) if (!all.includes(heb(`מילה${i}`))) missing.push(i);
            t.ok(`${label}: ההערה כולה — ${name}`, all.includes(heb(end)) && !missing.length, `חסרות: ${missing.slice(0, 5).join(',')}${all.includes(heb(end)) ? '' : ' + המילה האחרונה'}`);
        }
    };

    // הערה של יותר משלושה עמודים — בפסקה האחרונה במסמך (אין אחריה טקסט שיפתח עמוד)
    const W = 2600;
    const endDoc = await t.newDoc(p, 'הערה ארוכה בסוף', { type: 'doc', content: [P(long(4)), P('הסימן כאן', FN(noteText(W, 'סוףהערהארוכה')))] });
    await t.openDoc(p, endDoc);
    await gate('הערה של כמה עמודים בסוף המסמך');
    t.ok('הערה בסוף: נמשכת על פני כמה עמודים', await p.locator('.wp-fn-area .wp-fn-part').count() >= 3, String(await p.locator('.wp-fn-area .wp-fn-part').count()));
    await outputs(endDoc, 'הערה בסוף', W, 'סוףהערהארוכה');

    // אותה הערה באמצע המסמך, ושתי הערות ארוכות רצופות
    const midDoc = await t.newDoc(p, 'הערה ארוכה באמצע', { type: 'doc', content: [P(long(10)),
        P('סימן ראשון', FN(noteText(W, 'סוףראשונה')), { type: 'text', text: ' ועוד סימן' }, FN(noteText(900, 'סוףשנייה'))), P(long(30))] });
    await t.openDoc(p, midDoc);
    await gate('שתי הערות ארוכות באמצע המסמך');
    await outputs(midDoc, 'הערות באמצע', W, 'סוףשנייה');

    // מקטע לרוחב ואחריו מקטע לאורך: ההערות בעמוד הצר — ברוחב שלו (F10)
    const secDoc = await t.newDoc(p, 'הערות במקטעים', { type: 'doc', content: [P(long(3), FN(noteText(60, 'סוףרחבה'))),
        { type: 'sectionBreak', attrs: { page: { orientation: 'portrait' } } }, P(long(3), FN(noteText(120, 'סוףצרה')))] }, { orientation: 'landscape' });
    await t.openDoc(p, secDoc);
    await gate('הערות בעמוד לרוחב ובעמוד לאורך');
    await outputs(secDoc, 'הערות במקטעים', 120, 'סוףצרה');

    // הערה ארוכה ממש לפני מעבר עמוד ידני, ולפני מעבר מקטע (לרוחב): ההמשך לא נעלם ולא חורג
    const brkDoc = await t.newDoc(p, 'הערה לפני מעברים', { type: 'doc', content: [P(long(2), FN(noteText(1400, 'סוףלפניעמוד'))), { type: 'pageBreak' },
        P(long(2), FN(noteText(1400, 'סוףלפנימקטע'))), { type: 'sectionBreak', attrs: { page: { orientation: 'landscape' } } }, P(long(3))] });
    await t.openDoc(p, brkDoc);
    await gate('הערה ארוכה לפני מעבר עמוד ולפני מעבר מקטע');
    await outputs(brkDoc, 'הערות לפני מעברים', 1400, 'סוףלפנימקטע');
    const tail = heb(t.pdfPages(join(t.data, `fnb-${brkDoc}.pdf`)).join(' '));
    t.ok('הערות לפני מעברים: גם סוף ההערה הראשונה ב-PDF מהשרת', tail.includes(heb('סוףלפניעמוד')));

    // הערה בתא טבלה
    const cellDoc = await t.newDoc(p, 'הערה בתא', { type: 'doc', content: [P(long(2)), { type: 'table', content: [{ type: 'tableRow', content: [
        { type: 'tableCell', content: [P('תא', FN(noteText(80, 'סוףתא')))] }, { type: 'tableCell', content: [P('שני')] }] }] }, P(long(2))] });
    await t.openDoc(p, cellDoc);
    await gate('הערה בתא טבלה');
    await outputs(cellDoc, 'הערה בתא', 80, 'סוףתא');
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
