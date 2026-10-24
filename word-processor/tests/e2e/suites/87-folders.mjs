/**
 * תיקיות ומועדפים (שלב 5): "📁 תיקייה" — תיקייה חדשה; נכנסים אליה, ו"+ מסמך" יוצר מסמך בתוכה; תיקייה בתוך תיקייה,
 * עם פירורי לחם וחזרה לתיקייה שמעליה. ⋯ על מסמך — העברה לתיקייה אחרת. ☆ — המסמך במקטע "מועדפים" בראש הרשימה.
 * חיפוש — בכל המסמכים, עם התיקייה. מחיקת תיקייה — מה שבתוכה עובר למעלה.
 */
export default async function (t) {
    const p = await t.page();
    const loose = await t.newDoc(p, 'מסמך בראש', { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'א' }] }] });
    await p.goto(t.editor + '#/');
    await p.reload();
    await p.waitForSelector('.list .card');
    const names = () => p.locator('.list > li').evaluateAll(ls => ls.map(l => l.querySelector('.l1 span')?.textContent || l.textContent));
    const newFolder = async name => {
        await p.click('.toolbar button:has-text("תיקייה")');
        await p.fill('dialog[open] input[name=folder]', name);
        await p.click('dialog[open] button[type=submit]');
        await p.locator('.list .card.folder', { hasText: name }).waitFor();
    };

    // תיקייה חדשה, ובתוכה מסמך חדש
    await newFolder('חוזים');
    t.ok('"📁 תיקייה" — התיקייה ברשימה, לפני המסמכים', (await names()).indexOf('חוזים') < (await names()).indexOf('מסמך בראש'), JSON.stringify(await names()));
    await p.click('.list .card.folder:has-text("חוזים")');
    // ("טוען…" — גם הוא li.empty; מחכים שהרשימה נטענה: הפירורים עם שם התיקייה)
    await p.waitForFunction(() => document.querySelector('.crumbs')?.textContent.includes('חוזים') && !document.querySelector('.list').textContent.includes('טוען'));
    t.ok('בתוך התיקייה: ריקה, הכותרת והפירורים', (await p.locator('.top h1').textContent()).includes('חוזים')
        && (await p.locator('.crumbs').textContent()).includes('כל המסמכים'), await p.locator('.crumbs').textContent());
    await p.click('.toolbar button:has-text("מסמך")');
    await p.waitForSelector('.wp-doc');
    const inside = p.url().split('#/d/')[1];
    await p.click('button[aria-label="חזרה לרשימה"]');
    await p.waitForSelector('.list');
    await p.goto(t.editor + '#/');
    await p.waitForSelector('.list .card.folder');
    const r = await t.api(p, 'my_docs');
    const fid = r.folders.find(f => f.name === 'חוזים').id;
    t.ok('"+ מסמך" בתוך התיקייה — המסמך נוצר בה', r.docs.find(d => d.doc === inside)?.folder === fid);

    // תיקייה בתוך תיקייה; חזרה — לתיקייה שמעליה
    await p.goto(t.editor + '#/f/' + fid);
    await p.waitForSelector('.list .card');
    await newFolder('2026');
    await p.click('.list .card.folder:has-text("2026")');
    await p.waitForFunction(() => document.querySelector('.crumbs')?.textContent.includes('2026') && !document.querySelector('.list').textContent.includes('טוען'));
    const crumbs = await p.locator('.crumbs').textContent();
    t.ok('תיקייה בתוך תיקייה: כל המסמכים › חוזים › 2026', crumbs.replace(/\s+/g, ' ').trim() === 'כל המסמכים › חוזים › 2026', crumbs);
    await p.click('button[aria-label="חזרה לרשימה"]');
    await p.waitForFunction(id => location.hash === '#/f/' + id, fid);
    t.ok('"חזרה" — לתיקייה שמעליה', true);

    // העברת "מסמך בראש" לתוך 2026 (⋯ ← העברה)
    await p.goto(t.editor + '#/');
    await p.waitForSelector('.list .card');
    const card = p.locator('.list .card', { hasText: 'מסמך בראש' });
    await card.locator('button.more').click();
    await p.click('.menu-pop button:has-text("העברה לתיקייה")');
    const opts = await p.locator('dialog[open] select option').allTextContents();
    t.ok('חלון ההעברה: עץ התיקיות, עם הזחה', opts[0].includes('ראש הרשימה') && opts.some(o => o.includes('📁 חוזים')) && opts.some(o => /^\s+📁 2026/.test(o)), JSON.stringify(opts));
    const id2026 = (await t.api(p, 'my_docs')).folders.find(f => f.name === '2026').id;
    await p.selectOption('dialog[open] select', id2026);
    await p.click('dialog[open] button[type=submit]');
    await p.waitForFunction(() => !document.querySelector('dialog[open]'));
    await p.waitForTimeout(300);
    t.ok('אחרי ההעברה: המסמך כבר לא בראש', !(await names()).includes('מסמך בראש'), JSON.stringify(await names()));

    // מועדפים: ☆ על המסמך שבתוך 2026 — מופיע בראש, עם המיקום
    await p.goto(t.editor + '#/f/' + id2026);
    await p.waitForSelector('.list .card');
    await p.locator('.list .card', { hasText: 'מסמך בראש' }).locator('button.star').click();
    await p.goto(t.editor + '#/');
    await p.waitForSelector('.list .list-head');
    const fav = p.locator('.list .card', { hasText: 'מסמך בראש' }).first();
    t.ok('☆ — "מועדפים" בראש הרשימה, עם המיקום (📁 חוזים › 2026)', (await p.locator('.list .list-head').first().textContent()).includes('מועדפים')
        && (await fav.textContent()).includes('חוזים › 2026') && (await fav.locator('button.star').textContent()) === '★', await fav.textContent());

    // חיפוש — בכל המסמכים
    await p.fill('.toolbar input[type=search]', 'בראש');
    await p.waitForTimeout(100);
    t.ok('חיפוש מוצא מסמך שבתוך תת-תיקייה', (await names()).filter(n => n === 'מסמך בראש').length === 1, JSON.stringify(await names()));
    await p.fill('.toolbar input[type=search]', '');

    // מחיקת "חוזים" — 2026 והמסמך שבתוכה עולים לראש; שום מסמך לא נמחק
    p.once('dialog', d => d.accept());
    await p.locator('.list .card.folder', { hasText: 'חוזים' }).locator('button.more').click();
    await p.click('.menu-pop button:has-text("מחיקת התיקייה")');
    await p.locator('.list .card.folder', { hasText: 'חוזים' }).waitFor({ state: 'detached' });
    const after = await t.api(p, 'my_docs');
    t.ok('מחיקת תיקייה: מה שבתוכה עולה לראש, המסמכים נשארים', after.folders.map(f => [f.name, f.parent]).join() === '2026,'
        && after.docs.find(d => d.doc === inside)?.folder === null && after.docs.some(d => d.doc === loose), JSON.stringify(after.folders));
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
