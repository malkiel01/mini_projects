/**
 * כותרות לעמוד זוגי / אי-זוגי והתחלת מספור במקטע (שלב 3): בעורך — כל עמוד עם המספר שלו (מקטע עם num_restart מתחיל
 * מחדש, המקטע שאחריו ממשיך) והכותרת לפי זוגיות המספר; חלון הגדרות העמוד שומר את הכול; ה-PDF מהשרת — אותם מספרים.
 */
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';

const P = i => ({ type: 'paragraph', content: [{ type: 'text', text: `פסקה${i} ` + 'מילים רבות כדי למלא עמוד שלם בטקסט. '.repeat(25) }] });

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'זוגי ואי-זוגי', { type: 'doc', content: [...[1, 2, 3, 4, 5, 6, 7, 8, 9].map(P),
        { type: 'sectionBreak', attrs: { page: { num_restart: true, num_start: 1 } } }, ...[10, 11, 12, 13, 14, 15, 16].map(P),
        { type: 'sectionBreak', attrs: { page: { orientation: 'landscape' } } }, P(17)] },
        { even_odd: true, header: 'כותרת אי-זוגית', header_even: 'כותרת זוגית' });
    await t.openDoc(p, doc);
    await t.settle(p);
    const pages = () => p.evaluate(() => [...document.querySelectorAll('.wp-pages .wp-page')].map(pg => ({
        n: pg.querySelector('.wp-num')?.textContent || '', h: pg.querySelector('.wp-hdr .wp-hf-text')?.textContent || '' })));
    const ps = await pages();
    const nums = ps.map(x => +x.n);
    const restarts = nums.filter((v, i) => i > 0 && v !== nums[i - 1] + 1);
    t.ok('בעורך: המספור מתחיל מחדש ב-1 במקטע השני בלבד (השלישי ממשיך)', ps.length >= 5 && nums[0] === 1 && restarts.length === 1 && restarts[0] === 1, nums.join(','));
    t.ok('בעורך: כותרת זוגית בכל עמוד שמספרו זוגי, אי-זוגית בשאר', ps.every(x => x.h === (+x.n % 2 === 0 ? 'כותרת זוגית' : 'כותרת אי-זוגית')), JSON.stringify(ps));

    // PDF מהשרת — אותם מספרים
    const f = join(t.data, 'eo.pdf');
    writeFileSync(f, await (await p.request.get(t.editor + `api.php?action=my_export&format=pdf&doc=${doc}`)).body());
    const pdfNums = t.pdfPages(f).map(x => +(x.match(/(\d+)\s*$/) || [])[1]);
    t.ok('PDF מהשרת: אותם מספרי עמודים כמו בעורך', JSON.stringify(pdfNums) === JSON.stringify(nums), `${pdfNums} / ${nums}`);

    // חלון הגדרות העמוד: כיבוי זוגי / אי-זוגי, והתחלת המספור במקטע השני מ-5
    await p.click('.wp-doc > p:nth-of-type(12)');
    await t.tool(p, 'הגדרות עמוד ומספור עמודים');
    const d = p.locator('dialog[open]');
    t.ok('חלון הגדרות העמוד: זוגי / אי-זוגי ושדות הכותרת הזוגית, ומספור למקטע', await d.locator('input[name=even_odd]').isChecked()
        && (await d.locator('.hf-field', { hasText: 'עליונה — עמוד זוגי' }).locator('.hf-edit-doc').textContent()) === 'כותרת זוגית' && await d.locator('input[name=num_restart]').isChecked());
    await d.locator('input[name=scope][value=section]').check({ force: true });
    await d.locator('input[name=num_start]').fill('5');
    await d.locator('button[type=submit]').click();
    await d.waitFor({ state: 'detached' }).catch(() => {});
    await t.settle(p);
    const ps2 = await pages();
    t.ok('אחרי השינוי: המקטע השני מתחיל ב-5', ps2.some((x, i) => i > 0 && +x.n === 5 && +ps2[i - 1].n !== 4), ps2.map(x => x.n).join(','));
    await p.waitForTimeout(2500);
    const s = await t.api(p, 'my_open', { doc });
    const sb = s.json.content.filter(n => n.type === 'sectionBreak').map(n => n.attrs.page);
    t.ok('נשמר: התחלת המספור במקטע השני (5), השלישי בלי', sb[0]?.num_restart === true && sb[0]?.num_start === 5 && !sb[1]?.num_restart, JSON.stringify(sb));
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
