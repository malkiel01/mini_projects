/**
 * 📁 "המסמכים שלי" (ui-core files-window.js, integration.md שורה 20) מעל ספריית העורך: התיקיות והמסמכים שלו, כניסה
 * לתיקייה, פתיחת מסמך, תיקייה חדשה, שינוי שם — ובסגירה הרשימה של העורך מתעדכנת.
 */
export default async function (t) {
    const p = await t.page();
    await p.goto(t.editor);
    await p.waitForSelector('.list');
    const { id: folder } = await t.api(p, 'my_folder', { op: 'create', name: 'חוזים' });
    const { doc } = await t.api(p, 'my_new', { title: 'הסכם שכירות', folder });
    const open = async () => {
        await p.click('header.top .account-btn');
        await p.click('[role="menuitem"]:has-text("המסמכים שלי")');
        await p.waitForSelector('dialog[open] .fw-list li.card');
    };
    const rows = () => p.locator('dialog[open] .fw-list li.card').allTextContents();

    await open();
    t.ok('החלון נפתח מתפריט המשתמש — עם התיקייה', (await rows()).some(x => x.includes('חוזים')), JSON.stringify(await rows()));
    await p.click('dialog[open] .fw-list li.card:has-text("חוזים")');
    await p.waitForFunction(() => [...document.querySelectorAll('dialog[open] .fw-list li.card')].some(li => li.textContent.includes('הסכם שכירות')));
    t.ok('כניסה לתיקייה — המסמך שבה, ופירורי הלחם', (await p.locator('dialog[open] .fw-crumbs').textContent()).includes('חוזים'));

    // תיקייה חדשה בתוך "חוזים"
    await p.click('dialog[open] button:has-text("＋ תיקייה")');
    await p.fill('dialog[open] input[name=v]', 'ארכיון');
    await p.locator('dialog[open] input[name=v]').press('Enter');
    await p.waitForFunction(() => [...document.querySelectorAll('dialog[open] .fw-list li.card')].some(li => li.textContent.includes('ארכיון')));
    const fs = (await t.api(p, 'my_docs')).folders;
    t.ok('תיקייה חדשה — בספרייה של העורך, בתוך "חוזים"', fs.some(f => f.name === 'ארכיון' && f.parent === folder), JSON.stringify(fs));

    // פתיחת המסמך
    await p.click('dialog[open] .fw-list li.card:has-text("הסכם שכירות")');
    await p.waitForFunction(d => location.hash === '#/d/' + d, doc);
    await p.waitForSelector('.wp-doc');
    t.ok('פתיחת מסמך מהחלון — נפתח בעורך', await p.evaluate(() => !document.querySelector('dialog[open]')));

    // שינוי שם (⋯) ובסגירה — הרשימה מתעדכנת
    await p.click('button[aria-label="חזרה לרשימה"]');
    await p.waitForSelector('.list');
    await open();
    await p.click('dialog[open] .fw-list li.card:has-text("חוזים")');
    await p.waitForFunction(() => [...document.querySelectorAll('dialog[open] .fw-list li.card')].some(li => li.textContent.includes('הסכם שכירות')));
    await p.locator('dialog[open] .fw-list li.card:has-text("הסכם שכירות") button').last().click();
    await p.click('[role="menuitem"]:has-text("שינוי שם")');
    await p.fill('dialog[open] input[name=v]', 'הסכם שכירות 2026');
    await p.locator('dialog[open] input[name=v]').press('Enter');
    await p.waitForFunction(() => [...document.querySelectorAll('dialog[open] .fw-list li.card')].some(li => li.textContent.includes('2026')));
    const title = (await t.api(p, 'my_docs')).docs.find(d => d.doc === doc)?.title;
    t.ok('שינוי שם מהחלון — נשמר בעורך', title === 'הסכם שכירות 2026', title);
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
