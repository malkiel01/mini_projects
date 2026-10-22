/** טיפוגרפיה (שלב 2): A+/A−, סוגי קו תחתון, הזחת שורה ראשונה ותלויה, טאב באמצע הטקסט. */
const P = (text, attrs) => ({ type: 'paragraph', ...(attrs ? { attrs } : {}), content: [{ type: 'text', text }] });

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'טיפוגרפיה', { type: 'doc', content: [P('שורה ראשונה לבדיקה'), P('פסקה שנייה'), { type: 'heading', attrs: { level: 1 }, content: [{ type: 'text', text: 'כותרת' }] }] });
    await t.openDoc(p, doc);
    const E = fn => p.evaluate(fn);
    const json = () => E(() => document.querySelector('.wp-doc').editor.getJSON());
    const sel = (a, b) => E(new Function(`document.querySelector('.wp-doc').editor.chain().focus().setTextSelection({ from: ${a}, to: ${b} }).run()`));

    // A+ / A−: "שורה" (רגיל, 12) → 14 → 16; כותרת 1 (16) → 18
    await sel(1, 5);
    await p.keyboard.press('Control+Shift+Period');
    await p.keyboard.press('Control+Shift+Period');
    const size = async () => (await json()).content[0].content[0].marks?.find(m => m.type === 'textStyle')?.attrs.fontSize;
    t.ok('Ctrl+Shift+> פעמיים — 12 → 16', (await size()) === '16pt', await size());
    await t.tool(p, 'הקטנת גופן (Ctrl+Shift+<)');
    t.ok('A− — 14', (await size()) === '14pt', await size());
    const hPos = await E(() => { const d = document.querySelector('.wp-doc').editor.state.doc; return d.child(0).nodeSize + d.child(1).nodeSize + 1; });
    await sel(hPos, hPos + 5);
    await t.tool(p, 'הגדלת גופן (Ctrl+Shift+>)');
    t.ok('A+ על כותרת 1 (16) — 18', JSON.stringify((await json()).content[2]).includes('"fontSize":"18pt"'));

    // R10: גודל חופשי מהשדה בסרט — 13.5, גם עם פסיק; ערך לא תקין חוזר לגודל הנוכחי ולא משנה
    const field = '.wp-ribbon input.w-size';
    await sel(1, 5);
    await p.fill(field, '13.5');
    await p.press(field, 'Enter');
    t.ok('R10: גודל חופשי 13.5 מהסרט', (await size()) === '13.5pt' && (await p.inputValue(field)) === '13.5', await size());
    await sel(1, 5);
    await p.fill(field, '9,5');
    await p.press(field, 'Enter');
    t.ok('R10: פסיק עשרוני — 9.5', (await size()) === '9.5pt', await size());
    for (const bad of ['abc', '2000', '0']) {
        await sel(1, 5);
        await p.fill(field, bad);
        await p.press(field, 'Enter');
        t.ok(`R10: "${bad}" — לא משנה, והשדה חוזר לגודל הנוכחי`, (await size()) === '9.5pt' && (await p.inputValue(field)) === '9.5', `${await size()} / ${await p.inputValue(field)}`);
    }
    t.ok('R10: רשימת ההצעות בשדה', await p.evaluate(f => document.querySelector(f).list?.options.length, field) >= 10);
    await sel(1, 5);
    await p.fill(field, '14');
    await p.press(field, 'Enter');

    // סוג קו תחתון
    await sel(6, 12);
    await p.selectOption('.wp-ribbon select.w-ul', 'wavy');
    const ul = await p.locator('.wp-doc u[data-ul="wavy"]');
    t.ok('קו תחתון גלי — <u> עם text-decoration-style', await ul.count() === 1 && await ul.evaluate(e => getComputedStyle(e).textDecorationStyle === 'wavy'));
    t.ok('הרשימה מראה את הסוג הנוכחי', (await p.inputValue('.wp-ribbon select.w-ul')) === 'wavy');

    // הזחה תלויה מחלון הפסקה
    await sel(3, 3);
    await t.tool(p, 'פסקה: ריווח, הזחה, כיוון');
    await p.waitForSelector('dialog');
    await p.selectOption('dialog [name=flk]', 'hang');
    await p.fill('dialog [name=flv]', '24');
    await p.click('dialog button[type=submit]');
    await p.waitForSelector('dialog', { state: 'detached' });
    const fl = (await json()).content[0].attrs.firstLine;
    t.ok('תלויה 24 — firstLine −24, ובמסך text-indent ו-padding', fl === -24 && await p.locator('.wp-doc p').first().evaluate(e => {
        const s = getComputedStyle(e);
        return s.textIndent === '-32px' && s.paddingRight === '32px';
    }), String(fl));

    // Tab באמצע הטקסט — תו טאב; בתחילת פסקה — הזחה
    const p2 = await E(() => document.querySelector('.wp-doc').editor.state.doc.child(0).nodeSize + 1);
    await sel(p2 + 5, p2 + 5);
    await p.keyboard.press('Tab');
    t.ok('Tab באמצע — טאב בטקסט', (await json()).content[1].content.map(x => x.text).join('').includes('\t'));
    await sel(p2, p2);
    await p.keyboard.press('Tab');
    t.ok('Tab בתחילת פסקה — הזחה', (await json()).content[1].attrs.indent === 1);
    t.ok('עצירות טאב 36 נק\' במסך', await p.locator('.wp-doc').evaluate(e => getComputedStyle(e).tabSize === '48px'), await p.locator('.wp-doc').evaluate(e => getComputedStyle(e).tabSize));
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
}
