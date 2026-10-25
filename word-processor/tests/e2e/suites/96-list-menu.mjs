/**
 * ⋯ על מסמך ברשימה — פעולות קובץ בלי לפתוח אותו: שינוי שם, שכפול, הורדה (תפריט משנה), שיתוף, פעילות, העברה לסל
 * (עם "ביטול"). מסמך ששותף איתי — רק מה שמותר לתפקיד.
 */
export default async function (t) {
    const p = await t.page();
    const { doc } = await t.api(p, 'my_new', { title: 'מסמך לתפריט' });
    await p.goto(t.editor);
    const card = () => p.locator(`.list .card[data-doc="${doc}"]`);
    await card().waitFor();
    const open = async () => { await card().locator('.more').click(); return p.locator('.menu-pop button').allTextContents(); };
    const items = await open();
    t.ok('⋯ ברשימה: פתיחה, שינוי שם, שכפול, תיקייה, הורדה, שיתוף, פעילות, סל', ['פתיחה', 'שינוי שם…', 'שכפול', 'העברה לתיקייה…', 'הורדה…', 'שיתוף…', 'פעילות במסמך', 'העברה לסל המחזור']
        .every(x => items.some(i => i.endsWith(x))), JSON.stringify(items));
    p.once('dialog', d => d.accept('שם מהרשימה'));
    await p.click('.menu-pop button:has-text("שינוי שם…")');
    await p.waitForSelector(`.list .card[data-doc="${doc}"] .l1 span:text-is("שם מהרשימה")`);
    t.ok('שינוי שם מהרשימה', true);
    await open();
    await p.click('.menu-pop button:has-text("הורדה…")');
    const sub = await p.locator('.menu-pop button').allTextContents();
    t.ok('"הורדה…" — Word, PDF, ODT, ‎.mbe, טקסט', ['Word', 'PDF', 'ODT', '.mbe', 'טקסט'].every(x => sub.some(i => i.includes(x))), JSON.stringify(sub));
    const [dl] = await Promise.all([p.waitForEvent('download'), p.click('.menu-pop button:has-text("הורדה כ-Word")')]);
    const cd = (await p.request.get(dl.url())).headers()['content-disposition'] || '';
    t.ok('הורדה כ-Word מהרשימה — קובץ ‎.docx בשם המסמך', dl.url().includes('format=docx') && cd.includes(encodeURIComponent('שם מהרשימה') + '.docx'), cd);
    await open();
    await p.click('.menu-pop button:has-text("שכפול")');
    await p.waitForSelector('.toast:not([hidden]) .toast-act');
    const titles = await p.locator('.list .card .l1 > span:first-child').allTextContents();
    t.ok('שכפול מהרשימה — העותק ברשימה, והודעה עם "פתיחה"', titles.includes('שם מהרשימה (העתק)') && await p.locator('.toast .toast-act').textContent() === 'פתיחה', JSON.stringify(titles));
    await open();
    await p.click('.menu-pop button:has-text("העברה לסל המחזור")');
    await p.waitForSelector(`.list .card[data-doc="${doc}"]`, { state: 'detached' });
    t.ok('העברה לסל מהרשימה — יוצא מהרשימה, עם "ביטול"', await p.locator('.toast .toast-act').textContent() === 'ביטול');
    await p.click('.toast .toast-act');
    await card().waitFor();
    t.ok('"ביטול" — חוזר לרשימה', await card().count() === 1);
    // בחירה מרובה: "☑ בחירה" → שני מסמכים → העברה לתיקייה יחד → לסל יחד, ו"ביטול"
    const a = (await t.api(p, 'my_new', { title: 'בחירה א' })).doc, b = (await t.api(p, 'my_new', { title: 'בחירה ב' })).doc;
    await t.api(p, 'my_folder', { op: 'create', name: 'יעד' });
    await p.reload();
    await p.locator(`.list .card[data-doc="${a}"]`).waitFor();
    await p.click('button.select-toggle');
    await p.click(`.list .card[data-doc="${a}"]`);
    await p.click(`.list .card[data-doc="${b}"] input.pick`);
    t.ok('בחירה: לחיצה על מסמך מסמנת (לא פותחת), והפס — "2 מסמכים נבחרו"', (await p.locator('.select-bar .n').textContent()) === '2 מסמכים נבחרו'
        && !(await p.evaluate(() => location.hash)).startsWith('#/d/') && await p.locator('.list .card.picked').count() === 2);
    await p.click('.select-bar button:has-text("העברה לתיקייה")');
    await p.selectOption('dialog select', { label: '📁 יעד' });
    await p.click('dialog button[type=submit]');
    await p.waitForSelector('.select-bar', { state: 'hidden' });
    const r = await t.api(p, 'my_docs');
    const fid = r.folders.find(f => f.name === 'יעד').id;
    t.ok('העברה לתיקייה — שניהם בתיקייה, והבחירה נגמרת', r.docs.filter(d => [a, b].includes(d.doc)).every(d => d.folder === fid));
    await p.goto(t.editor + '#/f/' + fid);
    await p.locator(`.list .card[data-doc="${a}"]`).waitFor();
    await p.click('button.select-toggle');
    await p.click(`.list .card[data-doc="${a}"]`);
    await p.click(`.list .card[data-doc="${b}"]`);
    p.once('dialog', d => d.accept());
    await p.click('.select-bar button:has-text("העברה לסל")');
    await p.waitForSelector(`.list .card[data-doc="${a}"]`, { state: 'detached' });
    t.ok('לסל יחד — שניהם יוצאים, עם "2 מסמכים הועברו לסל המחזור" ו"ביטול"', (await p.locator('.toast').textContent()).includes('2 מסמכים הועברו')
        && await p.locator(`.list .card[data-doc="${b}"]`).count() === 0);
    await p.click('.toast .toast-act');
    await p.locator(`.list .card[data-doc="${b}"]`).waitFor();
    t.ok('"ביטול" — שניהם חוזרים', await p.locator(`.list .card[data-doc="${a}"]`).count() === 1);
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
