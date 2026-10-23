/**
 * עמוד ראשון שונה לכל מקטע (שלב 3, כמו "עמוד ראשון שונה" ב-Word): בעורך — בעמוד הראשון של מקטע עם first_diff
 * הכותרות לעמוד הראשון (ריקה — בלי כותרת), בשאר העמודים — הרגילות; מקטע בלי — הרגילות מההתחלה. חלון הגדרות העמוד
 * שומר את הכול למקטע; ה-PDF מהשרת — אותן כותרות באותם עמודים.
 */
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';

const P = i => ({ type: 'paragraph', content: [{ type: 'text', text: `פסקה${i} ` + 'מילים רבות כדי למלא עמוד שלם בטקסט. '.repeat(25) }] });

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'עמוד ראשון שונה', { type: 'doc', content: [...[1, 2, 3, 4, 5].map(P),
        { type: 'sectionBreak', attrs: { page: { first_diff: false } } }, ...[6, 7, 8, 9, 10].map(P),
        { type: 'sectionBreak', attrs: { page: { first_diff: true, header_first: 'OPENING' } } }, ...[11, 12, 13, 14, 15].map(P)] },
        { first_diff: true, header: 'REGULAR', header_first: 'COVER', footer: 'FOOT', footer_first: '' });
    await t.openDoc(p, doc);
    await t.settle(p);
    const pages = () => p.evaluate(() => [...document.querySelectorAll('.wp-pages .wp-page')].map(pg =>
        (pg.querySelector('.wp-hdr .wp-hf-text')?.textContent || '-') + (pg.querySelector('.wp-ftr .wp-hf-text')?.textContent ? '+F' : '')));
    const seq = await pages();
    const at = seq.indexOf('OPENING');
    t.ok('בעורך: עמוד השער (COVER, בלי תחתונה) רק בעמוד הראשון; אחריו הרגילה', seq[0] === 'COVER' && seq[1] === 'REGULAR+F' && seq.filter(x => x === 'COVER').length === 1, seq.join(' '));
    // התחתונה לעמוד הראשון במקטע השלישי — בירושה (ריקה), כמו "קישור לקודם" ב-Word
    t.ok('בעורך: המקטע השני — הרגילה מההתחלה; השלישי — OPENING (בלי תחתונה) בעמוד הראשון שלו בלבד', at > 2 && seq.filter(x => x === 'OPENING').length === 1 && seq[at + 1] === 'REGULAR+F'
        && seq.slice(2, at).every(x => x === 'REGULAR+F'), seq.join(' '));

    // PDF מהשרת — אותן כותרות באותם עמודים
    const f = join(t.data, 'fd.pdf');
    writeFileSync(f, await (await p.request.get(t.editor + `api.php?action=my_export&format=pdf&doc=${doc}`)).body());
    const pdfSeq = t.pdfPages(f).map(x => ((x.match(/\b(COVER|REGULAR|OPENING)\b/) || [])[1] || '-') + (x.includes('FOOT') ? '+F' : ''));
    t.ok('PDF מהשרת: אותן כותרות באותם עמודים כמו בעורך', JSON.stringify(pdfSeq) === JSON.stringify(seq), `${pdfSeq.join(' ')} / ${seq.join(' ')}`);

    // חלון הגדרות העמוד, במקטע השני: מדליקים עמוד ראשון שונה עם כותרת משלו
    await p.click('.wp-doc > p:nth-of-type(7)');
    await t.tool(p, 'הגדרות עמוד ומספור עמודים');
    const d = p.locator('dialog[open]');
    t.ok('חלון הגדרות העמוד: "עמוד ראשון שונה" כבוי במקטע השני', !await d.locator('input[name=first_diff]').isChecked());
    await d.locator('input[name=scope][value=section]').check({ force: true });
    await d.locator('input[name=first_diff]').check({ force: true });
    const ed = d.locator('.hf-edit-doc[aria-label="עליונה — עמוד ראשון"]');
    // בירושה מהמקטע הקודם — COVER; מחליפים
    t.ok('בחלון: הכותרת לעמוד הראשון — בירושה מהמקטע הקודם', (await ed.textContent()) === 'COVER', await ed.textContent());
    await ed.click();
    await p.keyboard.press('Control+a');
    await p.keyboard.type('SECOND');
    await d.locator('button[type=submit]').click();
    await d.waitFor({ state: 'detached' }).catch(() => {});
    await t.settle(p);
    const seq2 = await pages();
    t.ok('אחרי השינוי: SECOND בעמוד הראשון של המקטע השני בלבד', seq2.filter(x => x.startsWith('SECOND')).length === 1 && seq2.indexOf('SECOND') > 1, seq2.join(' '));
    await p.waitForTimeout(2500);
    const s = await t.api(p, 'my_open', { doc });
    const sb = s.json.content.filter(n => n.type === 'sectionBreak').map(n => n.attrs.page);
    const hf = v => typeof v === 'string' ? v : JSON.stringify(v);
    t.ok('נשמר: המקטע השני עם עמוד ראשון שונה ו-SECOND; השלישי לא השתנה', sb[0]?.first_diff === true && hf(sb[0]?.header_first).includes('SECOND')
        && sb[1]?.first_diff === true && hf(sb[1]?.header_first) === 'OPENING' && s.json.attrs.page.first_diff === true, JSON.stringify(sb.map(x => [x.first_diff, x.header_first])));
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
