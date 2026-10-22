/**
 * מעקב שינויים — חלק ב: תצוגת השינויים (סימון מלא / סופי / מקור) והסתרת מחברים, כמו ב-Word. התצוגה בלבד — המסמך
 * לא משתנה; ההדפסה וה-PDF מהשרת (track=, hide=) — באותה תצוגה; "אישור כל השינויים" והבא / הקודם — רק של
 * המחברים המוצגים.
 */
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';

const T = (text, marks) => ({ type: 'text', text, ...(marks ? { marks } : {}) });
const ins = author => [{ type: 'ins', attrs: { author, date: '2026-10-22T10:00:00Z' } }];
const del = author => [{ type: 'del', attrs: { author, date: '2026-10-22T10:00:00Z' } }];
const heb = s => s.replace(/[^א-ת]/g, '');

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'תצוגת שינויים', { type: 'doc', content: [{ type: 'paragraph', content: [
        T('רגיל '), T('נוסףדנה', ins('דנה')), T(' '), T('נמחקדנה', del('דנה')), T(' '), T('נוסףיוסי', ins('יוסי')), T(' '), T('נמחקיוסי', del('יוסי')),
    ] }] });
    await t.openDoc(p, doc);
    await t.settle(p);
    /** אילו מילים נראות במסך (רוחב > 0), ואילו מסומנות (קו תחתון / חוצה). */
    const seen = () => p.evaluate(() => {
        const out = {};
        for (const el of document.querySelectorAll('.wp-doc ins, .wp-doc del')) {
            const cs = getComputedStyle(el);
            out[el.textContent] = el.getBoundingClientRect().width < 1 ? 'hidden' : /underline|line-through/.test(cs.textDecorationLine) ? 'marked' : 'plain';
        }
        return out;
    });
    // הבחירה בסרט (לשונית "סקירה" — נפתחת קודם, כמו t.tool)
    const view = async v => {
        await p.evaluate(() => {
            const pn = document.querySelector('.wp-ribbon select.w-tview').closest('[role=tabpanel]');
            if (pn?.hidden) document.getElementById(pn.getAttribute('aria-labelledby')).click();
        });
        await p.selectOption('.wp-ribbon select.w-tview', v);
        await t.settle(p);
    };
    const all = await seen();
    t.ok('סימון מלא: כל השינויים מסומנים', Object.values(all).every(x => x === 'marked') && Object.keys(all).length === 4, JSON.stringify(all));

    await view('final');
    const fin = await seen();
    t.ok('סופי: התוספות טקסט רגיל, המחיקות לא מוצגות', fin['נוסףדנה'] === 'plain' && fin['נוסףיוסי'] === 'plain' && fin['נמחקדנה'] === 'hidden' && fin['נמחקיוסי'] === 'hidden', JSON.stringify(fin));
    const sameDoc = await p.evaluate(() => JSON.stringify(document.querySelector('.wp-doc').editor.getJSON()).includes('"del"'));
    t.ok('התצוגה לא משנה את המסמך (המחיקות עדיין בו)', sameDoc);
    const pr = join(t.data, 'tv-final.print.pdf');
    writeFileSync(pr, await p.pdf({ preferCSSPageSize: true, printBackground: true }));
    const prText = heb(t.pdfPages(pr).join(' '));
    t.ok('הדפסה בתצוגה הסופית: בלי המחיקות', prText.includes(heb('נוסףדנה')) && !prText.includes(heb('נמחקדנה')) && !prText.includes(heb('נמחקיוסי')), prText);

    await view('original');
    const org = await seen();
    t.ok('מקור: המחיקות טקסט רגיל, התוספות לא מוצגות', org['נמחקדנה'] === 'plain' && org['נוסףדנה'] === 'hidden' && org['נוסףיוסי'] === 'hidden', JSON.stringify(org));
    const q = await p.evaluate(() => [...document.querySelectorAll('*')].find(e => e.wordEditor)?.wordEditor.exportQuery());
    t.ok('PDF: הפרמטר של התצוגה עובר לשרת', q === '&track=original', q);
    const pdfOf = async query => {
        const f = join(t.data, `tv${query.replace(/\W+/g, '-')}.pdf`);
        writeFileSync(f, await (await p.request.get(t.editor + `api.php?action=my_export&format=pdf&doc=${doc}${query}`)).body());
        return heb(t.pdfPages(f).join(' '));
    };
    const orgPdf = await pdfOf('&track=original');
    t.ok('PDF מהשרת במקור: המחיקות כן, התוספות לא', orgPdf.includes(heb('נמחקדנה')) && !orgPdf.includes(heb('נוסףדנה')) && !orgPdf.includes(heb('נוסףיוסי')), orgPdf);
    const finPdf = await pdfOf('&track=final');
    t.ok('PDF מהשרת בסופי: התוספות כן, המחיקות לא', finPdf.includes(heb('נוסףיוסי')) && !finPdf.includes(heb('נמחקיוסי')), finPdf);

    // מחברים: מסתירים את יוסי — השינויים שלו כמו בסופי, של דנה מסומנים
    await view('all');
    await t.tool(p, 'שינויים של מחברים…');
    const d = p.locator('dialog[open]');
    t.ok('חלון המחברים: שני מחברים עם מספר השינויים', (await d.locator('label.switch').count()) === 2 && /דנה · 2 שינויים/.test(await d.textContent()), await d.textContent());
    await d.locator('label.switch', { hasText: 'יוסי' }).locator('input').uncheck({ force: true });
    await d.locator('button[type=submit]').click();
    await d.waitFor({ state: 'detached' }).catch(() => {});
    await t.settle(p);
    const hid = await seen();
    t.ok('מחבר מוסתר: התוספת שלו רגילה והמחיקה לא מוצגת; של דנה — מסומנים', hid['נוסףיוסי'] === 'plain' && hid['נמחקיוסי'] === 'hidden' && hid['נוסףדנה'] === 'marked' && hid['נמחקדנה'] === 'marked', JSON.stringify(hid));
    const hidPdf = await pdfOf('&hide=' + encodeURIComponent(JSON.stringify(['יוסי'])));
    t.ok('PDF מהשרת עם מחבר מוסתר: המחיקה שלו לא, של דנה כן', !hidPdf.includes(heb('נמחקיוסי')) && hidPdf.includes(heb('נמחקדנה')), hidPdf);

    // הבא / הקודם — רק בין השינויים של דנה
    await p.click('.wp-doc p');
    await p.keyboard.press('Control+Home');
    await t.tool(p, 'השינוי הבא');
    await t.tool(p, 'השינוי הבא');
    await t.tool(p, 'השינוי הבא');
    const sel = await p.evaluate(() => { const e = document.querySelector('.wp-doc').editor; const { from, to } = e.state.selection; return e.state.doc.textBetween(from, to); });
    t.ok('הבא / הקודם — מדלג על השינויים של המחבר המוסתר', ['נוסףדנה', 'נמחקדנה'].includes(sel), sel);

    // אישור כל השינויים — רק של דנה; של יוסי נשארים
    await t.tool(p, 'אישור כל השינויים');
    await t.settle(p);
    const left = await p.evaluate(() => { const out = []; document.querySelector('.wp-doc').editor.state.doc.descendants(n => { for (const m of n.marks || []) out.push(m.type.name + ':' + m.attrs.author + ':' + n.text); }); return out; });
    t.ok('"אישור כל השינויים" — רק של המחברים המוצגים', left.length === 2 && left.every(x => x.includes('יוסי')), JSON.stringify(left));
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
