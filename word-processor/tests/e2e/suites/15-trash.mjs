/** סל המחזור (ליקוי ה' באפיון): מחיקה מהמסמך → לסל, "ביטול" מההודעה, שחזור מהסל, מחיקה לצמיתות. */
export default async function (t) {
    const p = await t.page();
    const { doc } = await t.api(p, 'my_new', { title: 'מסמך לסל' });
    const del = async () => {
        await t.openDoc(p, doc);
        await p.click('.wp-doc');
        await p.keyboard.type('תוכן שנשמר לפני המחיקה');
        await p.click('button[aria-label="עוד"]');
        await p.click('.menu button:has-text("העברה לסל המחזור")');
        await p.waitForSelector('.list li:not(:text-is("טוען…"))');
    };
    const titles = () => p.locator('.list .card .l1 > span:first-child').allTextContents();

    await del();
    t.ok('מחיקה → חזרה לרשימה, והמסמך לא בה', !(await titles()).includes('מסמך לסל'));
    t.ok('הודעה עם "ביטול"', await p.locator('.toast:not([hidden]) .toast-act').textContent() === 'ביטול');
    await p.click('.toast .toast-act');
    await p.waitForSelector('.wp-doc');
    await t.settle(p);
    t.ok('"ביטול" — המסמך חוזר, עם התוכן שהוקלד לפני המחיקה', (await p.locator('.wp-doc').textContent()).includes('תוכן שנשמר לפני המחיקה'));

    // שוב לסל — ושחזור מתוך הסל
    await p.click('button[aria-label="עוד"]');
    await p.click('.menu button:has-text("העברה לסל המחזור")');
    await p.waitForSelector('.list li:not(:text-is("טוען…"))');
    let r = await t.api(p, 'my_open', { doc });
    t.ok('מסמך בסל — לא נפתח', r?.code === 'not_found', JSON.stringify(r).slice(0, 80));
    await p.click('button[aria-label="סל המחזור"]');
    await p.waitForSelector('h1:text-is("סל המחזור")');
    await p.waitForSelector('.list li:not(:text-is("טוען…"))');
    t.ok('הסל מציג את המסמך, עם מתי יימחק', (await titles()).includes('מסמך לסל') && (await p.locator('.list .card .l2').first().textContent()).includes('יימחק לצמיתות בעוד 30'), await p.locator('.list .card').first().innerHTML());
    await p.click('.list .card:has-text("מסמך לסל") button:has-text("שחזור")');
    await p.waitForSelector('.list .empty');
    t.ok('שחזור — הסל ריק', await p.locator('.list .empty').textContent() === 'הסל ריק.');
    await p.click('button[aria-label="חזרה לרשימה"]');
    await p.waitForSelector('h1:text-is("עורך התמלילים")');
    await p.waitForSelector('.list .card');
    t.ok('שחזור — המסמך חזר לרשימה', (await titles()).includes('מסמך לסל'));

    // מחיקה לצמיתות
    await t.api(p, 'my_delete', { doc });
    await p.click('button[aria-label="סל המחזור"]');
    await p.waitForSelector('h1:text-is("סל המחזור")');
    await p.waitForSelector('.list .card');
    await p.click('.list .card:has-text("מסמך לסל") button:has-text("מחיקה לצמיתות")');
    await p.click('dialog[aria-label="אישור"] button:has-text("מחיקה לצמיתות")');
    await p.waitForSelector('.list .empty');
    r = await t.api(p, 'my_restore', { doc });
    t.ok('מחיקה לצמיתות — אין מה לשחזר', r?.code === 'not_found', JSON.stringify(r).slice(0, 80));
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
