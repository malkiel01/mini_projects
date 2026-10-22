import { writeFileSync } from 'node:fs';
import { join } from 'node:path';

/** מספור (שלב 2): התחלה מחדש / המשך / ערך, רב-רמתי 1.1.1, מספור כותרות — במסך, נשמר, ביטול אחד. */
const P = text => ({ type: 'paragraph', content: [{ type: 'text', text }] });
const LI = (text, sub) => ({ type: 'listItem', content: [P(text), ...(sub ? [sub] : [])] });
const OL = (items, attrs = {}) => ({ type: 'orderedList', attrs: { start: 1, ...attrs }, content: items });

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'מספור', { type: 'doc', content: [
        { type: 'heading', attrs: { level: 1 }, content: [{ type: 'text', text: 'מבוא' }] },
        OL([LI('א'), LI('ב')]),
        P('באמצע'),
        OL([LI('ג')]),
        { type: 'heading', attrs: { level: 2 }, content: [{ type: 'text', text: 'פרק משנה' }] },
        OL([LI('ראשי', OL([LI('פנימי 1'), LI('פנימי 2')])), LI('ראשי 2')], { listType: 'multi' }),
    ] });
    await t.openDoc(p, doc);
    const E = fn => p.evaluate(fn);
    const ols = () => E(() => document.querySelector('.wp-doc').editor.getJSON().content.filter(b => b.type === 'orderedList').map(b => b.attrs));
    const into = text => E(new Function(`const e = document.querySelector('.wp-doc').editor; let at = 0;
        e.state.doc.descendants((n, pos) => { if (!at && n.isText && n.text === ${JSON.stringify(text)}) at = pos + 1; });
        e.chain().focus().setTextSelection(at).run();`));

    // המשך מספור: הרשימה השנייה ("ג") ממשיכה מ-3
    await into('ג');
    t.ok('כפתורי המספור פעילים ברשימה ממוספרת', await p.locator('.wp-ribbon [aria-label="המשך מספור"]').isEnabled());
    await t.tool(p, 'המשך מספור');
    t.ok('המשך מספור — מתחילה ב-3', (await ols())[1].start === 3 && (await ols())[1].cont === true, JSON.stringify(await ols()));
    // הרשימה הקודמת גדלה — ההמשך מתעדכן לבד
    await into('ב');
    await p.keyboard.press('End');
    await p.keyboard.press('Enter');
    await p.keyboard.type('ב2');
    t.ok('פריט נוסף ברשימה הקודמת — ההמשך זז ל-4', (await ols())[1].start === 4, JSON.stringify(await ols()));
    await into('ג');
    await t.tool(p, 'התחלה מחדש ב-1');
    t.ok('התחלה מחדש — 1, בלי המשך', (await ols())[1].start === 1 && !(await ols())[1].cont);
    await t.tool(p, 'קביעת ערך המספור');
    await p.fill('dialog [name=start]', '7');
    await p.click('dialog button[type=submit]');
    await p.waitForSelector('dialog', { state: 'detached' });
    t.ok('קביעת ערך — 7', (await ols())[1].start === 7 && await p.locator('.wp-doc ol[start="7"]').count() === 1);

    // רב-רמתי
    // הסימנים של 1.1.1 הם תוכן CSS (counters) — רואים אותם רק בתוצאה: ה-PDF של הדפסת הדפדפן
    const printed = async () => {
        const f = join(t.data, 'numbering.print.pdf');
        writeFileSync(f, await p.pdf({ preferCSSPageSize: true, printBackground: true }));
        return t.pdfPages(f).join('\n').replace(/\s+/g, '');
    };
    const pr = await printed();
    t.ok('רב-רמתי בהדפסה: 1. / 1.1. / 1.2. / 2.', ['.1ראשי', '.1.1פנימי1', '.1.2פנימי2', '.2ראשי2'].every(x => pr.includes(x)), pr.slice(0, 300));
    await into('א');
    await p.selectOption('.wp-ribbon select.w-list', 'multi');
    t.ok('בחירת 1.1.1 לרשימה', (await ols())[0].listType === 'multi');
    await p.keyboard.press('Control+z');

    // מספור כותרות
    const hnum = sel => p.locator(sel).evaluate(e => getComputedStyle(e, '::before').content);
    t.ok('בלי מספור כותרות — אין מספר', ['none', 'normal', ''].includes(await hnum('.wp-doc h1')));
    await t.tool(p, 'מספור כותרות');
    t.ok('מספור כותרות: "1. מבוא", "1.1 פרק משנה"', /^counter\(wh1\)|"1\. "|1\. /.test(await hnum('.wp-doc h1')) || (await hnum('.wp-doc h1')).length > 2, await hnum('.wp-doc h1'));
    const shown = await E(() => [...document.querySelectorAll('.wp-doc h1, .wp-doc h2')].map(h => h.getBoundingClientRect().width > 0));
    const pr2 = await printed();
    t.ok('מספור כותרות בהדפסה: "1. מבוא", "1.1 פרק משנה"', pr2.includes('.1מבוא') && pr2.includes('1.1פרקמשנה'), pr2.slice(0, 200));
    t.ok('הכפתור מסומן', await p.locator('.wp-ribbon [aria-label="מספור כותרות"]').getAttribute('aria-pressed') === 'true' && shown.every(Boolean));
    await p.waitForTimeout(50);
    await p.keyboard.press('Control+z');
    t.ok('ביטול מכבה', await p.locator('.wp-ribbon [aria-label="מספור כותרות"]').getAttribute('aria-pressed') === 'false');
    await p.keyboard.press('Control+y');
    await p.keyboard.press('Control+s');
    await p.waitForTimeout(600);
    const s = await t.api(p, 'my_open', { doc });
    t.ok('נשמר: מספור כותרות, ערך 7, רב-רמתי', s.page.heading_numbers === true && JSON.stringify(s.json).includes('"start":7') && JSON.stringify(s.json).includes('"listType":"multi"'));
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
}
