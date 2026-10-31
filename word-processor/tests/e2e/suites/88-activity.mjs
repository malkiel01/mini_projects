/**
 * יומן פעילות לכל מסמך (שלב 5): דנה יוצרת, פותחת, עורכת, משנה שם, משתפת עם יוסי ומורידה כ-Word; יוסי (עריכה) עורך
 * ומוסיף הערה (שורה "הערה" עם תחילת הטקסט — והשמירה של ההערה לבדה אינה "עריכה").
 * ⋯ ← "פעילות במסמך" — כל אלה, מהחדש לישן, עם השמות; מי שרק צופה — בלי "פעילות" בתפריט, והשרת דוחה.
 */
const P = text => ({ type: 'paragraph', content: [{ type: 'text', text }] });

export default async function (t) {
    const dana = await t.page();
    const doc = await t.newDoc(dana, 'מסמך עם יומן', { type: 'doc', content: [P('התחלה')] });
    await t.openDoc(dana, doc);
    await dana.evaluate(() => document.querySelector('.wp-doc').editor.chain().focus('end').insertContent(' עוד').run());
    await dana.keyboard.press('Control+s');
    await dana.waitForTimeout(800);
    await t.api(dana, 'my_rename', { doc, title: 'שם חדש' });
    await t.api(dana, 'my_share', { doc, email: 'yossi@example.com', role: 'edit' });
    await dana.request.get(t.editor + `api.php?action=my_export&format=docx&doc=${doc}`);

    // יוסי עורך
    const ctx = await t.browser.newContext({ viewport: { width: 1200, height: 900 } });
    await ctx.addCookies([{ name: 'as', value: 'b', url: t.editor }]);
    const yossi = await ctx.newPage();
    await yossi.goto(t.editor);
    await yossi.waitForSelector('.list');
    await t.openDoc(yossi, doc);
    await yossi.evaluate(() => document.querySelector('.wp-doc').editor.chain().focus('end').insertContent(' ויוסי').run());
    await yossi.keyboard.press('Control+s');
    await yossi.waitForTimeout(1200);
    await yossi.evaluate(() => document.querySelector('.wp-doc').editor.chain().focus().setTextSelection({ from: 1, to: 5 }).run());
    await yossi.keyboard.press('Control+Alt+KeyM');
    await yossi.waitForSelector('.wp-comments:not([hidden]) .cm-edit');
    await yossi.keyboard.type('לבדוק את הפתיחה');
    await yossi.keyboard.press('Control+Enter');
    await yossi.waitForSelector('.wp-comments .cm-card .cm-text');
    await yossi.keyboard.press('Control+s');
    await yossi.waitForTimeout(1500);

    // היומן אצל דנה
    await dana.click('button[aria-label="עוד"]');
    await dana.click('.menu button:has-text("פעילות במסמך")');
    const d = dana.locator('dialog[open]');
    await d.locator('.activity li:not(.day)').first().waitFor();
    const rows = await d.locator('.activity li:not(.day) .what').allTextContents();
    const has = s => rows.some(r => r.includes(s));
    t.ok('היומן: יוסי הוסיף הערה (הכי חדש, עם הטקסט) וערך, הורדה, שיתוף עם יוסי, שינוי שם, עריכה, פתיחה ויצירה של דנה',
        rows[0] === 'יוסי כהן — הערה · לבדוק את הפתיחה' && has('יוסי כהן — עריכה') && has('דנה לוי — הורדה · Word') && has('שיתוף · yossi@example.com — עריכה')
        && has('שינוי שם · שם חדש') && has('דנה לוי — עריכה') && has('דנה לוי — פתיחה') && has('דנה לוי — יצירה'), JSON.stringify(rows));
    t.ok('קבוצה לפי יום, עם שעה בכל שורה', await d.locator('.activity li.day').count() >= 1 && /^\d\d:\d\d$/.test(await d.locator('.activity time').first().textContent()));
    await d.locator('button[aria-label="סגירה"]').click();

    // צופה — בלי יומן
    await t.api(dana, 'my_share', { doc, email: 'yossi@example.com', role: 'view' });
    await yossi.goto(t.editor + '#/');
    await yossi.waitForSelector('.list');
    await t.openDoc(yossi, doc);
    await yossi.click('button[aria-label="עוד"]');
    const menu = await yossi.locator('.menu').textContent();
    const r = await t.api(yossi, 'my_activity', { doc });
    t.ok('צופה: בלי "פעילות" בתפריט, והשרת דוחה (403)', !menu.includes('פעילות') && r.code === 'forbidden', JSON.stringify(r));
    t.ok('בלי שגיאות JS', !dana.errors.length, dana.errors.join(' | '));
    await ctx.close();
    await dana.context().close();
}
