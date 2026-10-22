/** קישורים וסימניות (שלב 2): Ctrl+K, עריכה והסרה, כתובת שהוקלדה, סימניה וקישור אליה, Ctrl+לחיצה, שמירה. */
export default async function (t) {
    const p = await t.page();
    const { doc } = await t.api(p, 'my_new', { title: 'קישורים' });
    await t.openDoc(p, doc);
    const ed = fn => p.evaluate(fn);
    const json = () => ed(() => document.querySelector('.wp-doc').editor.getJSON());
    const links = async () => (await json()).content.flatMap(b => b.content || []).filter(n => n.marks?.some(m => m.type === 'link'))
        .map(n => [n.text, n.marks.find(m => m.type === 'link').attrs.href]);

    await p.click('.wp-doc');
    await p.keyboard.type('ראו את האתר שלנו');
    // בחירת "האתר" (מילה 3) ו-Ctrl+K
    await ed(() => { const e = document.querySelector('.wp-doc').editor; e.commands.setTextSelection({ from: 8, to: 12 }); });
    await p.keyboard.press('Control+k');
    await p.waitForSelector('dialog[aria-label="הוספת קישור"]');
    t.ok('Ctrl+K — חלון, הטקסט הנבחר בשדה', (await p.inputValue('dialog [name=text]')) === 'האתר', await p.inputValue('dialog [name=text]'));
    await p.fill('dialog [name=href]', 'javascript:alert(1)');
    await p.click('dialog button[type=submit]');
    t.ok('כתובת לא בטוחה — נדחית עם הודעה', (await p.locator('dialog .error').textContent()).includes('לא תקינה'));
    await p.fill('dialog [name=href]', 'example.com/x');
    await p.click('dialog button[type=submit]');
    await p.waitForSelector('dialog', { state: 'detached' });
    t.ok('קישור על הבחירה (https:// נוסף)', JSON.stringify(await links()) === JSON.stringify([['האתר', 'https://example.com/x']]), JSON.stringify(await links()));
    t.ok('במסמך: <a> כחול עם קו תחתון', await p.locator('.wp-doc a.wp-link').evaluate(a => getComputedStyle(a).color === 'rgb(5, 99, 193)' && getComputedStyle(a).textDecorationLine.includes('underline')));

    // עריכה: הסמן בתוך הקישור → כל הקישור, טקסט חדש
    await ed(() => document.querySelector('.wp-doc').editor.commands.setTextSelection(10));
    await t.tool(p, 'קישור (Ctrl+K)');
    await p.waitForSelector('dialog[aria-label="עריכת קישור"]');
    t.ok('עריכה: כל הקישור נבחר', (await p.inputValue('dialog [name=text]')) === 'האתר' && (await p.inputValue('dialog [name=href]')) === 'https://example.com/x');
    await p.fill('dialog [name=text]', 'האתר החדש');
    await p.click('dialog button[type=submit]');
    await p.waitForSelector('dialog', { state: 'detached' });
    t.ok('עריכה: הטקסט הוחלף והקישור עליו', JSON.stringify(await links()) === JSON.stringify([['האתר החדש', 'https://example.com/x']]), JSON.stringify(await links()));

    // Ctrl+לחיצה — נפתח בלשונית חדשה; לחיצה רגילה — רק סמן
    const popup = p.context().waitForEvent('page', { timeout: 3000 }).catch(() => null);
    await p.locator('.wp-doc a.wp-link').click({ modifiers: ['Control'] });
    const w = await popup;
    t.ok('Ctrl+לחיצה — הקישור נפתח בלשונית חדשה', !!w && (await w.url()).includes('example.com'), w ? w.url() : 'לא נפתח');
    await w?.close();

    // כתובת שהוקלדה + רווח → קישור
    // (Ctrl+End לא זז בדפדפן הבדיקה — הסמן לסוף ישירות)
    await ed(() => { const e = document.querySelector('.wp-doc').editor; e.chain().focus().setTextSelection(e.state.doc.content.size - 1).run(); });
    await p.waitForTimeout(50);
    await p.keyboard.press('Enter');
    await p.keyboard.type('פרטים ב-www.gov.il. ');
    t.ok('הקלדת www… ורווח — קישור (בלי הנקודה שבסוף)', (await links()).some(([x, h]) => x === 'www.gov.il' && h === 'https://www.gov.il'), JSON.stringify(await links()));

    // סימניה וקישור אליה
    await p.keyboard.press('Enter');
    await p.keyboard.type('פרק שני');
    await ed(() => { const e = document.querySelector('.wp-doc').editor; e.commands.setTextSelection(e.state.doc.content.size - 8); });
    await t.tool(p, 'סימניה');
    await p.fill('dialog [name=name]', 'פרק ב');
    await p.click('dialog button[type=submit]');
    t.ok('שם עם רווח — נדחה', (await p.locator('dialog .error').textContent()).includes('לא תקין'));
    await p.fill('dialog [name=name]', 'פרק_ב');
    await p.click('dialog button[type=submit]');
    await p.waitForSelector('dialog', { state: 'detached' });
    t.ok('סימניה נוספה', JSON.stringify(await json()).includes('"type":"bookmark","attrs":{"name":"פרק_ב"}'));
    await ed(() => document.querySelector('.wp-doc').editor.commands.setTextSelection({ from: 1, to: 3 }));
    await p.keyboard.press('Control+k');
    await p.selectOption('dialog [name=bm]', 'פרק_ב');
    await p.click('dialog button[type=submit]');
    await p.waitForSelector('dialog', { state: 'detached' });
    t.ok('קישור לסימניה (#פרק_ב)', (await links()).some(([x, h]) => x === 'רא' && h === '#פרק_ב'), JSON.stringify(await links()));
    await p.locator('.wp-doc a[href="#פרק_ב"]').click({ modifiers: ['Control'] });
    await p.waitForTimeout(200);
    const at = await ed(() => { const e = document.querySelector('.wp-doc').editor; return [e.state.selection.from, e.state.doc.content.size]; });
    t.ok('Ctrl+לחיצה על קישור פנימי — הסמן עובר לסימניה', at[0] > at[1] - 12, JSON.stringify(at));

    // הסרת קישור
    await ed(() => document.querySelector('.wp-doc').editor.commands.setTextSelection(10));
    await t.tool(p, 'הסרת קישור');
    t.ok('הסרת קישור', !(await links()).some(([x]) => x === 'האתר החדש'));
    await p.waitForTimeout(50);   // המיקוד חוזר למסמך אחרי הלחיצה בסרט
    await p.keyboard.press('Control+z');
    t.ok('ביטול מחזיר אותו', (await links()).some(([x]) => x === 'האתר החדש'));

    // נשמר
    await p.keyboard.press('Control+s');
    await p.waitForTimeout(600);
    const s = await t.api(p, 'my_open', { doc });
    const flat = JSON.stringify(s.json);
    t.ok('נשמר בשרת: קישורים, סימניה, גרסת המבנה הנוכחית', flat.includes('https://example.com/x') && flat.includes('#פרק_ב') && flat.includes('"bookmark"') && s.json.attrs.schema === 6);
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
}
