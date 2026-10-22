/** אב-טיפוס (שלב 0, חלק 4): טבלה שעוברת בין עמודים — שבירה בין שורות, ושורת הכותרת חוזרת בכל עמוד. */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

export default async function (t) {
    const c = JSON.parse(readFileSync(join(t.app, 'tests', 'corpus', 'long-table.json'), 'utf8'));
    const p = await t.page();
    const doc = await t.newDoc(p, c.title, c.json, c.page);
    await t.openDoc(p, doc);
    const s = await p.evaluate(() => ({
        rowBreaks: document.querySelectorAll('.wp-doc tr.wp-break-row').length,
        repeats: [...document.querySelectorAll('.wp-doc tr.wp-repeat')].map(r => r.textContent),
        editable: [...document.querySelectorAll('.wp-doc tr.wp-repeat')].every(r => r.isContentEditable === false),
        pages: document.querySelector('.pages-count')?.textContent,
    }));
    t.ok('הטבלה נשברת בין שורות', s.rowBreaks >= 3, `${s.rowBreaks} שבירות בתוך הטבלה · ${s.pages}`);
    t.ok('שורת הכותרת חוזרת בכל עמוד שהטבלה נמשכת אליו', s.repeats.length === s.rowBreaks && s.repeats.every(x => x.includes('סעיף') && x.includes('סכום')));
    t.ok('השורה החוזרת — לא לעריכה', s.editable);

    // עריכה בתא בעמוד 2: הטקסט נכנס לשורה האמיתית, לא לעותק
    const cell = p.locator('.wp-doc tr.wp-break-row').first().locator('xpath=following-sibling::tr[not(contains(@class,"wp-repeat"))][1]/td[2]');
    await cell.click();
    await p.keyboard.press('End');
    await p.keyboard.type(' נוסף');
    await p.waitForTimeout(2500);
    const srv = await t.api(p, 'my_open', { doc });
    t.ok('הקלדה בתא בעמוד 2 נשמרת', JSON.stringify(srv.json).includes(' נוסף') && !JSON.stringify(srv.json).includes('wp-repeat'));
    t.ok('השורה החוזרת לא נכנסת למסמך', srv.json.content.find(n => n.type === 'table').content.length === c.json.content.find(n => n.type === 'table').content.length);
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
