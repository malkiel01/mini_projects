/**
 * אותם עמודים בעורך, בהדפסה וב-PDF מהשרת: לכל מסמך באוסף (tests/corpus) — מספר העמודים, והטקסט שבתחילת כל עמוד.
 * משווים את 12 האותיות העבריות הראשונות בכל עמוד (רווחים, ספרות וסימני כיוון — לא נספרים).
 */
import { readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const heb = s => (s.match(/[א-ת]/g) || []).join('').slice(0, 12);
// בצד ה-PDF: האותיות של תחילת העמוד (עד 80). pdftotext מפצל שורה עם טאב לשני "טורים" ומוציא לפעמים את החלק השני
// קודם — לכן כשבשורה הראשונה בעורך יש טאב, מספיק שהיא נמצאת בתחילת העמוד ב-PDF; אחרת — העמוד מתחיל בדיוק בה
const head = s => (s.match(/[א-ת]/g) || []).join('').slice(0, 80);

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
        const pdfStarts = t.pdfPages(pdf).map(head);

        // הדפסה: PDF של הדפדפן ("שמירה כ-PDF") — מה שהכפתור "הדפסה / PDF" מדפיס
        const printPdf = join(t.data, `${f}.print.pdf`);
        writeFileSync(printPdf, await p.pdf({ preferCSSPageSize: true, printBackground: true }));
        const printStarts = t.pdfPages(printPdf).map(head);

        const diff = (a, b) => {
            const i = a.findIndex((x, k) => !(b[k] ?? '').startsWith(x) && !(ed[k]?.includes('\t') && (b[k] ?? '').includes(x)));
            const at = i < 0 ? '' : `עמוד ${i + 1}: "${a[i]}" מול "${(b[i] ?? '—').slice(0, 24)}"`;
            if (a.length !== b.length) return `${a.length} עמודים מול ${b.length}${at ? ' — ' + at : ''}`;
            return at;
        };
        for (const [name, other] of [['PDF מהשרת', pdfStarts], ['הדפסה', printStarts]]) {
            const d = diff(edStarts, other);
            const known = c.known || (name === 'PDF מהשרת' ? c.known_pdf : null);
            const label = `${c.title}: העורך ו${name} — אותם עמודים`;
            if (d && !known && process.env.E2E_ARTIFACTS) {   // ב-CI: הקבצים נשמרים להורדה מההרצה
                const { copyFileSync, mkdirSync } = await import('node:fs');
                mkdirSync(process.env.E2E_ARTIFACTS, { recursive: true });
                copyFileSync(name === 'הדפסה' ? printPdf : pdf, join(process.env.E2E_ARTIFACTS, `${f}.${name === 'הדפסה' ? 'print' : 'server'}.pdf`));
                writeFileSync(join(process.env.E2E_ARTIFACTS, `${f}.editor.txt`), ed.join('\n—— עמוד ——\n'));
                await p.screenshot({ path: join(process.env.E2E_ARTIFACTS, `${f}.editor.png`), fullPage: true });
                // גובה כל בלוק כפי שהעורך מדד (נק', בלי הרווחים של מעברי העמוד) והגופן שבו הוא צויר בפועל — להשוואה עם ה-PDF
                writeFileSync(join(process.env.E2E_ARTIFACTS, `${f}.editor.blocks.txt`), await p.evaluate(() => {
                    const d = document.querySelector('.wp-doc');
                    const z = d.getBoundingClientRect().height / d.offsetHeight;
                    const gap = el => [...el.querySelectorAll('.wp-break')].reduce((t, b) => t + b.getBoundingClientRect().height, 0);
                    return [...d.children].filter(b => !b.classList.contains('wp-break')).map((b, i) => {
                        const r = b.getBoundingClientRect();
                        return `${i}\t${((r.height - gap(b)) / z * 0.75).toFixed(2)}\t${getComputedStyle(b).lineHeight}`;
                    }).join('\n') + '\nfonts: ' + [...document.fonts].filter(x => x.status === 'loaded').map(x => x.family).filter((x, i, a) => a.indexOf(x) === i).join(', ');
                }));
            }
            if (d && process.env.DEBUG) console.log({ editor: ed.map(x => x.slice(0, 50)), [name]: name === 'הדפסה' ? t.pdfPages(printPdf).map(x => x.slice(0, 80)) : t.pdfPages(pdf).map(x => x.slice(0, 80)) });
            if (d && known) t.known(label, `${d} (${known})`);
            // הבדל ידוע שלא הופיע — עובר. חלקם תלויים בשורה גבולית שהעיגול שלה משתנה בין מחשבים (ב-CI ומקומית)
            else if (!d && known) t.ok(label, true, 'ההבדל הידוע לא הופיע כאן — אם זה קבוע, להסיר אותו מ-tests/corpus/make.php');
            else t.ok(label, !d, d || `${edStarts.length} עמודים`);
        }
    }
    await p.evaluate(() => { window.__printed = 0; window.print = () => { window.__printed++; }; });
    await t.tool(p, 'הדפסה / PDF');
    t.ok('כפתור ההדפסה פותח הדפסה', await p.evaluate(() => window.__printed) === 1);
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
