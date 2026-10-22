/** שבירת עמודים (שלב 3): חלון הפסקה — שמירה עם הבא, שורות יחד, מעבר עמוד לפני; במסך, נשמר, ביטול. */
const P = text => ({ type: 'paragraph', content: [{ type: 'text', text }] });

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'שבירת עמודים', { type: 'doc', content: [P('פסקה ראשונה'), P('פסקה שנייה'), P('פסקה שלישית')] });
    await t.openDoc(p, doc);
    const E = fn => p.evaluate(fn);
    const attrs = () => E(() => document.querySelector('.wp-doc').editor.getJSON().content.map(b => b.attrs));
    const breaks = () => p.locator('.wp-doc .wp-break').count();
    const into = k => E(new Function(`const e = document.querySelector('.wp-doc').editor; let at = 0, n = 0;
        e.state.doc.forEach((b, off) => { if (n++ === ${k}) at = off + 2; }); e.chain().focus().setTextSelection(at).run();`));

    t.ok('עמוד אחד', await breaks() === 0);
    await into(1);
    await t.tool(p, 'פסקה: ריווח, הזחה, כיוון');
    await p.waitForSelector('dialog');
    await p.check('dialog [name=breakBefore]');
    await p.check('dialog [name=keepLines]');
    await p.click('dialog button[type=submit]');
    await p.waitForSelector('dialog', { state: 'detached' });
    await p.waitForTimeout(300);
    const a = await attrs();
    t.ok('הפסקה השנייה: מעבר עמוד לפני ושורות יחד', a[1].breakBefore === true && a[1].keepLines === true && !a[0].breakBefore && !a[1].keepNext, JSON.stringify(a[1]));
    t.ok('מעבר עמוד לפני — עמוד חדש לפני הפסקה', await breaks() === 1);
    const second = await p.locator('.wp-doc p').nth(1).evaluate(e => e.getBoundingClientRect().top - document.querySelector('.wp-doc p').getBoundingClientRect().top);
    t.ok('הפסקה בעמוד הבא', second > 500, String(second));

    // החלון מראה את המצב, וכיבוי
    await t.tool(p, 'פסקה: ריווח, הזחה, כיוון');
    await p.waitForSelector('dialog');
    t.ok('החלון מסומן לפי הפסקה', await p.isChecked('dialog [name=breakBefore]') && await p.isChecked('dialog [name=keepLines]') && !await p.isChecked('dialog [name=keepNext]'));
    await p.uncheck('dialog [name=breakBefore]');
    await p.click('dialog button[type=submit]');
    await p.waitForSelector('dialog', { state: 'detached' });
    await p.waitForTimeout(300);
    t.ok('כיבוי — חזרה לעמוד אחד', await breaks() === 0 && !(await attrs())[1].breakBefore);
    await p.waitForTimeout(100);   // מיקוד חוזר לעורך אחרי סגירת החלון
    await p.keyboard.press('Control+z');
    await p.waitForTimeout(300);
    t.ok('ביטול — מעבר העמוד חוזר', await breaks() === 1 && (await attrs())[1].breakBefore === true);

    await p.keyboard.press('Control+s');
    await p.waitForTimeout(600);
    const s = await t.api(p, 'my_open', { doc });
    t.ok('נשמר בשרת', s.json?.content?.[1]?.attrs?.breakBefore === true && s.json.content[1].attrs.keepLines === true, JSON.stringify(s).slice(0, 400));
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
}
