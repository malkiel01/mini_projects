/**
 * טורים (שלב ג'): מקטע בשני טורים. במסך — תצוגת טיוטה: הטקסט ברוחב טור, כל טור במסגרת משלו ("עמוד 1 · טור 2"),
 * וספירת העמודים לפי העמודים הפיזיים. בהדפסה וב-PDF מהשרת — שני טורים זה לצד זה, מימין לשמאל, עם אותן שבירות:
 * אותו מספר עמודים, ואותה מילה בראש כל טור. מעבר עמוד ידני באמצע — עמוד חדש (לא רק הטור הבא).
 */
import { writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { join } from 'node:path';
import { checkBounds } from '../lib/bounds.mjs';

const P = text => ({ type: 'paragraph', content: [{ type: 'text', text }] });
const para = i => P(`פסקה${i} ` + 'מילים רבות בטור צר כדי למלא שורות ושורות. '.repeat(6));

/** לכל עמוד ב-PDF: המילים בטור הימני ובטור השמאלי (לפי אמצע העמוד), וכמה מילים חוצות את הרווח ביניהם. */
function columnsOf(file) {
    const x = execFileSync('pdftotext', ['-bbox', file, '-'], { encoding: 'utf8' });
    return [...x.matchAll(/<page width="([\d.]+)" height="([\d.]+)">([\s\S]*?)<\/page>/g)].map(([, w, h, body]) => {
        const mid = +w / 2;
        const words = [...body.matchAll(/xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="([\d.]+)">([^<]*)</g)]
            .map(m => ({ x0: +m[1], y0: +m[2], x1: +m[3], t: m[5] })).filter(q => !(/^\d+$/.test(q.t) && q.y0 > +h - 60));
        const first = side => words.filter(side).sort((a, b) => a.y0 - b.y0 || b.x1 - a.x1)[0]?.t || '';
        return { right: words.filter(q => q.x0 > mid).length, left: words.filter(q => q.x1 < mid).length,
            cross: words.filter(q => q.x0 < mid - 4 && q.x1 > mid + 4).map(q => q.t), firstR: first(q => q.x0 > mid), firstL: first(q => q.x1 < mid) };
    });
}

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'טורים', { type: 'doc', content: Array.from({ length: 24 }, (_, i) => para(i + 1)) }, { columns: { n: 2, gap: 10, line: true } });
    await t.openDoc(p, doc);
    await t.settle(p);
    const st = await p.evaluate(() => {
        const d = document.querySelector('.wp-doc');
        const para = d.querySelector('p').getBoundingClientRect();
        const pg = document.querySelector('.wp-pages .wp-page').getBoundingClientRect();
        const z = pg.width / document.querySelector('.wp-pages .wp-page').offsetWidth;
        return { w: para.width / z, right: (pg.right - para.right) / z, frames: document.querySelectorAll('.wp-pages .wp-page').length,
            badges: [...document.querySelectorAll('.wp-col-badge')].map(b => b.textContent).slice(0, 3), status: document.querySelector('.wp-statusbar')?.textContent || '' };
    });
    // A4: 210 מ"מ, שוליים 25 — 160 מ"מ לטקסט; שני טורים עם 10 מ"מ — 75 מ"מ לטור (283.5px)
    const colPx = 75 * 96 / 25.4;
    t.ok('במסך: הטקסט ברוחב טור, במקום של הטור הימני', Math.abs(st.w - colPx) < 2 && Math.abs(st.right - 25 * 96 / 25.4) < 2, `${Math.round(st.w)} / ${Math.round(colPx)}, ימין ${Math.round(st.right)}`);
    t.ok('במסך: כל טור במסגרת עם תווית "עמוד · טור"', st.frames >= 4 && st.badges[0] === 'עמוד 1 · טור 1 מתוך 2' && st.badges[1] === 'עמוד 1 · טור 2 מתוך 2' && st.badges[2] === 'עמוד 2 · טור 1 מתוך 2', JSON.stringify(st.badges));
    t.ok('ספירת העמודים — עמודים פיזיים (חצי ממספר המסגרות)', st.status.includes(`מתוך ${Math.ceil(st.frames / 2)}`), `${st.frames} מסגרות · ${st.status}`);
    const bad = await checkBounds(p);
    t.ok('גבולות: כל מילה בתוך אזור התוכן', !bad.length, bad.slice(0, 2).map(b => `${b.why} "${b.text}"`).join(' | '));

    // הדפסה מול PDF מהשרת: שני טורים, אותו מספר עמודים ואותה מילה בראש כל טור
    const printPdf = join(t.data, 'cols.print.pdf'), serverPdf = join(t.data, 'cols.pdf');
    writeFileSync(printPdf, await p.pdf({ preferCSSPageSize: true, printBackground: true }));
    writeFileSync(serverPdf, await (await p.request.get(t.editor + `api.php?action=my_export&format=pdf&doc=${doc}`)).body());
    const pr = columnsOf(printPdf), sv = columnsOf(serverPdf);
    t.ok('הדפסה: שני טורים בכל עמוד מלא, בלי מילה שחוצה את הרווח', pr.length >= 2 && pr.slice(0, -1).every(c => c.right > 40 && c.left > 40) && pr.every(c => !c.cross.length), JSON.stringify(pr.map(c => [c.right, c.left, c.cross.length])));
    t.ok('PDF מהשרת: שני טורים, בלי מילה שחוצה את הרווח', sv.slice(0, -1).every(c => c.right > 40 && c.left > 40) && sv.every(c => !c.cross.length));
    t.ok('הדפסה ו-PDF מהשרת: אותו מספר עמודים, והמסך — שתי מסגרות לעמוד', pr.length === sv.length && Math.ceil(st.frames / 2) === sv.length, `${pr.length} / ${sv.length} / ${st.frames}`);
    t.ok('הדפסה ו-PDF מהשרת: אותה מילה בראש כל טור', pr.every((c, i) => c.firstR === sv[i]?.firstR && c.firstL === sv[i]?.firstL),
        JSON.stringify(pr.map((c, i) => [c.firstR, sv[i]?.firstR, c.firstL, sv[i]?.firstL])));

    // מעבר עמוד ידני באמצע הטור הראשון — העמוד הבא, לא הטור השמאלי
    const brk = await t.newDoc(p, 'טורים ומעבר', { type: 'doc', content: [para(1), { type: 'pageBreak' }, P('אחרימעבר עמוד')] }, { columns: { n: 2 } });
    await t.openDoc(p, brk);
    await t.settle(p);
    const bp = join(t.data, 'colbrk.print.pdf');
    writeFileSync(bp, await p.pdf({ preferCSSPageSize: true, printBackground: true }));
    const pages = t.pdfPages(bp);
    t.ok('מעבר עמוד ידני בטור הראשון — בהדפסה עמוד חדש', pages.length === 2 && /אחרימעבר|רבעמירחא/.test(pages[1]) && !/אחרימעבר|רבעמירחא/.test(pages[0]), JSON.stringify(pages.map(x => x.slice(-60))));
    // שלושה טורים: מעבר ידני בטור הראשון מדלג על שני טורים
    const brk3 = await t.newDoc(p, 'שלושה טורים ומעבר', { type: 'doc', content: [para(1), { type: 'pageBreak' }, P('אחרימעבר עמוד')] }, { columns: { n: 3 } });
    await t.openDoc(p, brk3);
    await t.settle(p);
    writeFileSync(bp, await p.pdf({ preferCSSPageSize: true, printBackground: true }));
    const p3 = t.pdfPages(bp);
    t.ok('שלושה טורים: מעבר עמוד ידני — בהדפסה עמוד חדש', p3.length === 2 && /אחרימעבר|רבעמירחא/.test(p3[1]), JSON.stringify(p3.map(x => x.slice(-60))));
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
