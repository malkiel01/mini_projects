/**
 * אותם עמודים בעורך, בהדפסה וב-PDF מהשרת: לכל מסמך באוסף (tests/corpus) — מספר העמודים, והטקסט שבתחילת כל עמוד.
 * משווים את 12 האותיות העבריות הראשונות בכל עמוד (רווחים, ספרות וסימני כיוון — לא נספרים).
 */
import { readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const heb = s => (s.match(/[א-ת]/g) || []).join('').slice(0, 12);

export default async function (t) {
    const dir = join(t.app, 'tests', 'corpus');
    const p = await t.page();
    for (const f of readdirSync(dir).filter(f => f.endsWith('.json')).sort()) {
        const c = JSON.parse(readFileSync(join(dir, f), 'utf8'));
        const doc = await t.newDoc(p, c.title, c.json, c.page);
        await t.openDoc(p, doc);

        // העורך: הטקסט שאחרי כל מעבר עמוד
        const ed = await p.evaluate(() => {
            const out = [''];
            const w = document.createTreeWalker(document.querySelector('.wp-doc'), NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT);
            for (let n = w.nextNode(); n; n = w.nextNode()) {
                if (n.nodeType === 1 && n.classList.contains('wp-break')) out.push('');
                else if (n.nodeType === 3 && !n.parentElement.closest('.wp-break') && out[out.length - 1].length < 60) out[out.length - 1] += n.data;
            }
            return out;
        });
        const edStarts = ed.map(heb);

        // PDF מהשרת
        const pdf = join(t.data, `${f}.pdf`);
        const res = await p.request.get(t.editor + `api.php?action=my_export&format=pdf&doc=${doc}`);
        writeFileSync(pdf, await res.body());
        const pdfStarts = t.pdfPages(pdf).map(heb);

        // הדפסה: מצב ההדפסה של העורך (מרווח 0 בין הדפים), ואז PDF של הדפדפן כמו "שמירה כ-PDF"
        await p.evaluate(() => { window.__wpHoldPrint = true; window.print = () => {}; });
        await p.click('button[aria-label="הדפסה / PDF"]');
        await p.waitForSelector('html.wp-printing');
        await t.settle(p);
        const printPdf = join(t.data, `${f}.print.pdf`);
        writeFileSync(printPdf, await p.pdf({ preferCSSPageSize: true, printBackground: true }));
        await p.evaluate(() => window.dispatchEvent(new Event('afterprint')));
        const printStarts = t.pdfPages(printPdf).map(heb);

        const diff = (a, b) => {
            if (a.length !== b.length) return `${a.length} עמודים מול ${b.length}`;
            const i = a.findIndex((x, k) => x !== b[k]);
            return i < 0 ? '' : `עמוד ${i + 1}: "${a[i]}" מול "${b[i]}"`;
        };
        for (const [name, other] of [['PDF מהשרת', pdfStarts], ['הדפסה', printStarts]]) {
            const d = diff(edStarts, other);
            const label = `${c.title}: העורך ו${name} — אותם עמודים`;
            if (d && process.env.DEBUG) console.log({ editor: ed.map(x => x.slice(0, 50)), [name]: name === 'הדפסה' ? t.pdfPages(printPdf).map(x => x.slice(0, 80)) : t.pdfPages(pdf).map(x => x.slice(0, 80)) });
            const known = c.known || (name === 'PDF מהשרת' ? c.known_pdf : null);
            if (d && known) t.known(label, `${d} (${known})`);
            else if (!d && known) t.ok(label + ' — ההבדל הידוע נעלם: להסיר אותו מ-tests/corpus/make.php', false);
            else t.ok(label, !d, d || `${edStarts.length} עמודים`);
        }
    }
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
