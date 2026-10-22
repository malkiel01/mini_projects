/** שמירה אמינה באפליקציה: מצבים, הגדרות עמוד באותה גרסה, ביטול, ניתוק, טיוטה, עצירת ייצוא ויציאה, התנגשות. */
export default async function (t) {
    const p = await t.page();
    const { doc } = await t.api(p, 'my_new', { title: 'בדיקת שמירה' });
    await t.openDoc(p, doc);
    const state = () => p.locator('.top .save-state[role=status]').textContent();
    const srv = () => t.api(p, 'my_open', { doc });

    t.ok('פתיחה: נשמר', (await state()).includes('נשמר'), await state());
    t.ok('מונה עמודים בכותרת', /עמוד/.test(await p.locator('.pages-count').textContent()));
    await p.click('.wp-doc');
    await p.keyboard.type('שלום עולם');
    t.ok('אחרי הקלדה: לא נשמר', (await state()) === 'לא נשמר', await state());
    await p.waitForTimeout(2500);
    t.ok('אחרי שנייה וחצי: נשמר', (await state()).includes('נשמר'), await state());
    // שמירה ידנית (Ctrl+S): נשמר מיד, בלי לחכות להשהיה של השמירה האוטומטית
    await p.keyboard.type(' ועוד');
    t.ok('הקלדה נוספת — לא נשמר', (await state()) === 'לא נשמר', await state());
    await p.keyboard.press('Control+s');
    await p.waitForTimeout(400);
    const saved0 = await t.api(p, 'my_open', { doc });
    t.ok('Ctrl+S — נשמר מיד (לפני השמירה האוטומטית)', (await state()).includes('נשמר') && JSON.stringify(saved0.json).includes('ועוד'), await state());
    let s = await srv();
    t.ok('השרת קיבל את הטקסט', JSON.stringify(s.json).includes('שלום עולם'));
    const v1 = s.version;

    await t.tool(p, 'הגדרות עמוד ומספור עמודים');
    await p.click('dialog label:has-text("לרוחב")');
    await p.click('dialog button[type=submit]');
    await p.waitForTimeout(2500);
    s = await srv();
    t.ok('הגדרות עמוד נשמרו באותה שמירה, בגרסה אחת', s.page.orientation === 'landscape' && s.version === v1 + 1, `v${v1}→v${s.version}`);
    const w1 = await p.locator('.wp-sheet').evaluate(e => e.offsetWidth);
    await p.click('.wp-doc');
    await p.keyboard.press('Control+z');
    await p.waitForTimeout(300);
    const w2 = await p.locator('.wp-sheet').evaluate(e => e.offsetWidth);
    t.ok('Ctrl+Z מבטל את הגדרות העמוד', w2 < w1, `${w1}px → ${w2}px`);
    await p.waitForTimeout(2500);
    s = await srv();
    t.ok('הביטול נשמר, והטקסט נשאר', s.page.orientation === 'portrait' && JSON.stringify(s.json).includes('שלום עולם'));

    await p.route('**/api.php?action=my_save', r => r.abort());
    await p.click('.wp-doc');
    await p.keyboard.press('End');
    await p.keyboard.type(' בלי רשת');
    await p.waitForTimeout(2200);
    t.ok('ניתוק: מצב "מנותק" ופס הסבר', (await state()).includes('מנותק') && await p.locator('.banner:not([hidden])').isVisible(), await state());
    const draft = await p.evaluate(() => new Promise(res => { const r = indexedDB.open('word-processor', 1); r.onsuccess = () => { const g = r.result.transaction('drafts').objectStore('drafts').getAll(); g.onsuccess = () => res(g.result); }; }));
    t.ok('הטיוטה במכשיר', JSON.stringify(draft).includes('בלי רשת'));

    await p.click('button[aria-label="עוד"]');
    await p.click('.menu-pop button:has-text("הורדה כ-Word")');
    await p.waitForSelector('dialog .choices');
    t.ok('ייצוא בלי שמירה: נעצר ושואל', true);
    await p.click('dialog .choices button:has-text("ביטול")');

    await p.click('button[aria-label="חזרה לרשימה"]');
    await p.waitForSelector('dialog .choices');
    await p.click('dialog .choices button:has-text("להישאר במסמך")');
    await p.waitForTimeout(300);
    t.ok('יציאה בלי שמירה → "להישאר" משאיר במסמך', p.url().includes('#/d/' + doc) && await p.locator('.wp-doc').isVisible());
    await p.click('button[aria-label="חזרה לרשימה"]');
    await p.waitForSelector('dialog .choices');
    await p.click('dialog .choices button:has-text("לצאת בכל זאת")');
    await p.waitForTimeout(500);
    t.ok('"לצאת בכל זאת" → הרשימה', !p.url().includes('#/d/'));

    await p.unroute('**/api.php?action=my_save');
    await p.goto(t.editor + '#/d/' + doc);
    await p.reload();
    await p.waitForSelector('.wp-doc');
    await p.waitForTimeout(800);
    t.ok('פתיחה מחדש: הצעת שחזור', (await p.locator('.banner').textContent()).includes('לא נשמרו'));
    await p.click('.banner button:has-text("שחזור")');
    await p.waitForTimeout(2500);
    s = await srv();
    t.ok('אחרי שחזור: בשרת', JSON.stringify(s.json).includes('בלי רשת'), await state());

    await t.api(p, 'my_save', { doc, json: s.json, base_version: s.version });
    await p.click('.wp-doc');
    await p.keyboard.type('!');
    await p.waitForTimeout(2500);
    t.ok('התנגשות: מצב והסבר', (await state()).includes('התנגשות') && (await p.locator('.banner').textContent()).includes('השתנה במקום אחר'), await state());
    await p.click('.banner button:has-text("טעינה מחדש")');
    await p.waitForTimeout(500);
    t.ok('טעינה מחדש → נשמר', (await state()).includes('נשמר'), await state());

    await t.api(p, 'my_page', { doc, page: { size: 'Letter' } });
    s = await srv();
    t.ok('תאימות: my_page של לשונית ישנה עדיין עובד', s.json.attrs.page.size === 'Letter' && s.json.attrs.schema === 2);
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
