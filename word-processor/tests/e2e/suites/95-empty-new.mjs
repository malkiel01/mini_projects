/** "+ מסמך" ויציאה בלי לכתוב בו — המסמך לא נשאר ברשימה ("מסמך ריק — לא נשמר"); כתבתי בו — נשאר. */
export default async function (t) {
    const p = await t.page();
    await p.goto(t.editor);
    await p.waitForSelector('h1:text-is("עורך התמלילים")');
    const titles = async () => { await p.waitForSelector('.list li:not(:text-is("טוען…"))'); return p.locator('.list .card .l1 > span:first-child').allTextContents(); };
    const before = (await titles()).filter(x => x === 'מסמך חדש').length;
    const create = async () => { await p.click('button.btn.primary:has-text("מסמך")'); await p.waitForSelector('.wp-doc'); await t.settle(p); };
    await create();
    const id = (await p.evaluate(() => location.hash)).slice(4);
    await p.click('button[aria-label="חזרה לרשימה"]');
    const after = (await titles()).filter(x => x === 'מסמך חדש').length;
    const toast = await p.locator('.toast:not([hidden])').textContent().catch(() => '');
    const r = await t.api(p, 'my_open', { doc: id });
    t.ok('יציאה ממסמך חדש בלי לכתוב — לא ברשימה, לא בסל, ו"מסמך ריק — לא נשמר"', after === before && r?.code === 'not_found' && toast.includes('מסמך ריק'),
        JSON.stringify([before, after, toast, r?.code]));
    await create();
    await p.click('.wp-doc');
    await p.keyboard.type('כתבתי משהו');
    await t.settle(p);
    await p.click('button[aria-label="חזרה לרשימה"]');
    t.ok('כתבתי בו — נשאר ברשימה', (await titles()).filter(x => x === 'מסמך חדש').length === before + 1);
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
