/**
 * ‎.doc (Word 97–2003): מאותו כפתור ייבוא — המסמך נפתח עם הכותרת, העיצוב, הרשימות והטבלה. השמירה — Word (‎.docx) בלבד.
 */
import { readFileSync } from 'node:fs';

export default async function (t) {
    const p = await t.page();
    await p.goto(t.editor);
    await p.waitForSelector('h1:text-is("עורך התמלילים")');
    const accept = await p.getAttribute('input[type=file][accept*=docx]', 'accept');
    await p.setInputFiles('input[type=file][accept*=docx]', { name: 'מסמך ישן.doc', mimeType: 'application/msword',
        buffer: readFileSync(new URL('../../fixtures/libreoffice.doc', import.meta.url)) });
    await p.waitForSelector('.wp-doc');
    await t.settle(p);
    const got = await p.evaluate(() => ({
        h1: document.querySelector('.wp-doc h1')?.textContent, bold: document.querySelector('.wp-doc strong')?.textContent,
        cells: [...document.querySelectorAll('.wp-doc td')].map(td => td.textContent), li: document.querySelectorAll('.wp-doc ul li').length,
        img: !!document.querySelector('.wp-doc img'),
    }));
    t.ok('‎.doc בבורר הקבצים, ונפתח: כותרת, מודגש, תבליטים, טבלה ותמונה', accept.includes('.doc,') && accept.includes('application/msword') && got.h1 === 'כותרת ראשית'
        && got.bold === 'מודגש' && got.li === 2 && got.cells.join('|') === 'תא א1|תא ב1|תא א2|תא ב2' && got.img, JSON.stringify(got));
    await p.click('button[aria-label="עוד"]');
    const menu = await p.locator('.menu-pop button').allTextContents();
    t.ok('הורדה — כ-Word (‎.docx), בלי ‎.doc', menu.some(x => x.includes('הורדה כ-Word')) && !menu.some(x => x.includes('.doc ') || x.endsWith('.doc')), JSON.stringify(menu));
    await p.keyboard.press('Escape');
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
