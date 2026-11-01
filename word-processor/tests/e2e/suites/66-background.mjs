/** רקע הדף (שלב 3): צבע עמוד וסימן מים — מהגדרות העמוד; במסך, בהדפסה וב-PDF מהשרת (לפי הפיקסלים), נשמר, ביטול. */
import { writeFileSync, readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { join } from 'node:path';

const P = text => ({ type: 'paragraph', content: [{ type: 'text', text }] });

/** הפיקסלים של עמוד ב-PDF (pdftoppm, 10dpi): {w, h, at(x, y) → [r, g, b]}. */
function pixels(pdf, page, dir) {
    const out = join(dir, 'bgpx');
    execFileSync('pdftoppm', ['-r', '10', '-f', String(page), '-l', String(page), '-singlefile', pdf, out]);
    const b = readFileSync(out + '.ppm');
    const parts = b.toString('latin1', 0, 40).split(/\s+/);   // P6 w h 255
    const w = +parts[1], h = +parts[2];
    const off = b.length - w * h * 3;
    return { w, h, at: (x, y) => [...b.subarray(off + (y * w + x) * 3, off + (y * w + x) * 3 + 3)] };
}
const near = (a, b, d = 12) => a.every((v, i) => Math.abs(v - b[i]) <= d);
/** סימן המים: באלכסון של העמוד יש פיקסלים אפורים (לא בצבע הרקע ולא שחורים). */
const grayOnDiagonal = px => {
    let n = 0;
    for (let k = 0.3; k <= 0.7; k += 0.02) {
        const c = px.at(Math.round(px.w * k), Math.round(px.h * (1 - k)));
        if (c[0] < 240 && c[0] > 150 && Math.abs(c[0] - c[2]) < 30) n++;
    }
    return n;
};

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'רקע הדף', { type: 'doc', content: [P('שלום'), { type: 'pageBreak' }, P('עמוד שני')] });
    await t.openDoc(p, doc);
    await t.tool(p, 'הגדרות עמוד ומספור עמודים');
    await p.waitForSelector('dialog');
    await p.check('dialog [name=pc_on]');
    // הבורר של ui-core (color.js): פתיחה, קוד ו-Enter
    await p.click('dialog .cp-field:has(> label:text-is("הצבע")) .cp-trigger');
    await p.fill('dialog .cp-field:has(> label:text-is("הצבע")) .cp-hex', '#ffeecc');
    await p.press('dialog .cp-field:has(> label:text-is("הצבע")) .cp-hex', 'Enter');
    await p.click('dialog .wm-presets button:has-text("טיוטה")');
    await p.click('dialog button[type=submit]');
    await p.waitForSelector('dialog', { state: 'detached' });
    await p.waitForTimeout(300);
    const pages = await p.locator('.wp-page').evaluateAll(els => els.map(e => [getComputedStyle(e).backgroundColor, e.querySelector('.wp-wm')?.textContent]));
    t.ok('במסך: כל עמוד בצבע, עם סימן מים', pages.length === 2 && pages.every(([bg, wm]) => bg === 'rgb(255, 238, 204)' && wm === 'טיוטה'), JSON.stringify(pages));
    t.ok('סימן המים מאחורי הטקסט (לא מסתיר אותו) ומסובב', await p.locator('.wp-wm').first().evaluate(e => getComputedStyle(e).transform !== 'none' && getComputedStyle(e).pointerEvents === 'none'));

    // הדפסה: צבע העמוד בכל הדף, וסימן המים בשני העמודים
    const printPdf = join(t.data, 'bg.print.pdf');
    writeFileSync(printPdf, await p.pdf({ preferCSSPageSize: true, printBackground: true }));
    const pr = [1, 2].map(k => pixels(printPdf, k, t.data));
    t.ok('הדפסה: שני עמודים, הפינה בצבע העמוד', t.pdfPages(printPdf).length === 2 && pr.every(x => near(x.at(1, 1), [255, 238, 204])), JSON.stringify(pr.map(x => x.at(1, 1))));
    t.ok('הדפסה: סימן המים בכל עמוד', pr.every(x => grayOnDiagonal(x) >= 4), pr.map(grayOnDiagonal).join(','));
    t.ok('הדפסה: סימן המים לא נכנס לטקסט של העמוד', !t.pdfPages(printPdf).join('').includes('טיוטה'));

    await p.keyboard.press('Control+s');
    await p.waitForTimeout(600);
    const s = await t.api(p, 'my_open', { doc });
    t.ok('נשמר', s.page.page_color === '#ffeecc' && s.page.watermark === 'טיוטה', JSON.stringify([s.page.page_color, s.page.watermark]));
    const res = await p.request.get(t.editor + `api.php?action=my_export&format=pdf&doc=${doc}`);
    const srv = join(t.data, 'bg.server.pdf');
    writeFileSync(srv, await res.body());
    const sv = [1, 2].map(k => pixels(srv, k, t.data));
    t.ok('PDF מהשרת: הפינה בצבע העמוד, וסימן המים בכל עמוד', sv.every(x => near(x.at(1, 1), [255, 238, 204]) && grayOnDiagonal(x) >= 4), JSON.stringify(sv.map(x => [x.at(1, 1), grayOnDiagonal(x)])));

    await p.keyboard.press('Control+z');
    await p.waitForTimeout(300);
    t.ok('ביטול — בלי רקע ובלי סימן מים', await p.locator('.wp-wm').count() === 0 && await p.locator('.wp-page').first().evaluate(e => getComputedStyle(e).backgroundColor) === 'rgb(255, 255, 255)');
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
}
