/**
 * קישור עריכה לזר (שלב 5, החלטה 4): יוסי (לא מנהל) מבקש קישור בחלון השיתוף — "ממתין לאישור"; דנה (מנהלת העורך)
 * מאשרת במסך הניהול; הקישור מופיע אצל יוסי. זר בלי חשבון (בלי משתמש מהשער) פותח אותו, מקליד שם, עורך ושומר —
 * יוסי רואה את השינוי ואת השם; כשיוסי נכנס — הם עורכים יחד (עריכה משותפת). ביטול הקישור — הזר כבר לא נכנס.
 */
const P = text => ({ type: 'paragraph', content: [{ type: 'text', text }] });

export default async function (t) {
    const dana = await t.page();
    const isAdmin = await dana.evaluate(async () => (await (await fetch('api.php?action=me')).json()).user.admin);
    const ctx = await t.browser.newContext({ viewport: { width: 1200, height: 900 } });
    await ctx.addCookies([{ name: 'as', value: 'b', url: t.editor }]);
    const yossi = await ctx.newPage();
    yossi.errors = [];
    yossi.on('pageerror', e => yossi.errors.push(e.message));
    await yossi.goto(t.editor);
    await yossi.waitForSelector('.list');
    const doc = await t.newDoc(yossi, 'מסמך לזר', { type: 'doc', content: [P('שלום מיוסי')] });

    // יוסי מבקש קישור — ממתין לאישור
    await t.openDoc(yossi, doc);
    const openShare = async () => {
        await yossi.click('button[aria-label="עוד"]');
        await yossi.click('.menu-pop button:has-text("שיתוף")');
        return yossi.locator('dialog[open]');
    };
    let d = await openShare();
    await d.locator('button:has-text("בקשת קישור עריכה")').click();
    await d.locator('.link-box .chip.warn').waitFor();
    t.ok('יוסי (לא מנהל) מבקש קישור — "ממתין לאישור", בלי כתובת', isAdmin && await d.locator('.link-url').count() === 0, await d.locator('.link-box').textContent());
    await d.locator('button[aria-label="סגירה"]').click();

    // דנה מאשרת במסך הניהול
    await dana.click('button[aria-label="ניהול העורך"]');
    const card = dana.locator('.admin-links li.card', { hasText: 'מסמך לזר' });
    await card.waitFor();
    t.ok('מסך הניהול: הבקשה — שם המסמך, הבעלים, "ממתין לאישור"', (await card.textContent()).includes('של יוסי כהן') && (await card.textContent()).includes('ממתין לאישור'), await card.textContent());
    await card.locator('button:has-text("אישור")').click();
    await dana.locator('.admin-links li.card .chip.ok', { hasText: 'פעיל' }).waitFor();
    t.ok('אחרי אישור — "פעיל"', true);

    // הקישור אצל יוסי
    d = await openShare();
    await d.locator('.link-url').waitFor();
    const url = await d.locator('.link-url').inputValue();
    t.ok('אצל יוסי: הקישור הפעיל, הטוקן אחרי #', /guest\.html#k=[a-z0-9]{32}$/.test(url) && url.startsWith(t.editor), url);
    await d.locator('button[aria-label="סגירה"]').click();
    await yossi.click('button[aria-label="חזרה לרשימה"]');   // משחרר את הנעילה
    await yossi.waitForSelector('.list');

    // הזר: בלי משתמש מהשער
    const gctx = await t.browser.newContext({ viewport: { width: 1200, height: 900 } });
    await gctx.addCookies([{ name: 'nouser', value: '1', url: t.editor }]);
    const g = await gctx.newPage();
    g.errors = [];
    g.on('pageerror', e => g.errors.push(e.message));
    const noAcc = await (await g.request.post(t.editor + 'api.php?action=my_open', { headers: { 'X-WP': '1', 'Content-Type': 'application/json' }, data: { doc } })).json();
    t.ok('הזר: בלי חשבון — האפליקציה עצמה לא פותחת לו את המסמך', noAcc.code === 'login', JSON.stringify(noAcc));
    await g.goto(url);
    await g.waitForSelector('form.login-box input[name=name]');
    await g.fill('input[name=name]', 'רות');
    await g.click('form.login-box button[type=submit]');
    await g.waitForSelector('.wp-doc');
    const gs = await g.evaluate(() => ({ editable: document.querySelector('.wp-doc').editor.isEditable, who: document.querySelector('.embed-bar .chip').textContent,
        title: document.querySelector('.embed-bar .titles').textContent }));
    t.ok('הזר: שם → המסמך נפתח לעריכה, "רות (אורח)" בפס', gs.editable && gs.who === 'רות (אורח)' && gs.title === 'מסמך לזר', JSON.stringify(gs));
    await g.evaluate(() => document.querySelector('.wp-doc').editor.chain().focus('end').run());
    await t.settle(g);   // כמו משתמש: מקלידים אחרי שהעורך נטען (הקלדה בעשרות המ"ש הראשונים — ראו README, "עריכה משותפת")
    await g.keyboard.type(' ורות מוסיפה');
    await g.keyboard.press('Control+s');
    await g.waitForTimeout(1500);
    const s = await t.api(yossi, 'my_open', { doc });
    t.ok('יוסי רואה את מה שהזר הקליד, ואת השם שלו', JSON.stringify(s.json).includes('ורות מוסיפה') && s.updated_by === 'רות (אורח)' && s.can === 'view'
        && s.lock?.by === 'רות (אורח)', JSON.stringify({ by: s.updated_by, can: s.can, lock: s.lock }));

    // עריכה משותפת: יוסי פותח את המסמך באפליקציה בזמן שהזר בפנים — שניהם עורכים, כל אחד רואה את השני
    await t.openDoc(yossi, doc);
    t.ok('יוסי והזר יחד: יוסי פותח לעריכה (בלי "פתוח אצל…")', await yossi.evaluate(() => document.querySelector('.wp-doc').editor.isEditable)
        && await yossi.locator('.banner:not([hidden])').count() === 0);
    await yossi.evaluate(() => document.querySelector('.wp-doc').editor.chain().focus('end').insertContent(' ויוסי עונה').run());
    const gText = () => g.evaluate(() => document.querySelector('.wp-doc').editor.state.doc.textContent);
    let gt = '';
    for (let i = 0; i < 40 && !gt.includes('ויוסי עונה'); i++) { await g.waitForTimeout(250); gt = await gText(); }
    t.ok('…מה שיוסי כתב מגיע לזר תוך שניות', gt.includes('ורות מוסיפה') && gt.includes('ויוסי עונה'), gt);
    let peer = '';
    for (let i = 0; i < 20 && !peer; i++) { await g.waitForTimeout(250); peer = await g.locator('.peers .peer').first().getAttribute('title').catch(() => ''); }
    t.ok('…והזר רואה שיוסי במסמך', peer === 'יוסי כהן', peer);
    await yossi.click('button[aria-label="חזרה לרשימה"]');
    await yossi.waitForSelector('.list');

    // ביטול — הזר כבר לא נכנס
    await t.api(yossi, 'my_link', { doc, op: 'revoke' });
    await g.reload();
    await g.waitForSelector('.login-box p');
    const msg = await g.locator('.login-box').textContent();
    t.ok('אחרי ביטול הקישור: "הקישור לא בתוקף"', msg.includes('הקישור לא בתוקף') && await g.locator('.wp-doc').count() === 0, msg);
    const bad = await (await g.request.post(t.editor + 'api.php?action=guest_save', { headers: { 'X-WP': '1', 'Content-Type': 'application/json' },
        data: { k: url.split('#k=')[1], gid: 'abcdefgh12', name: 'רות', json: { type: 'doc', content: [P('x')] } } })).json();
    t.ok('…וגם שמירה ישירה נדחית', bad.code === 'link_invalid', JSON.stringify(bad));
    t.ok('בלי שגיאות JS', !dana.errors.length && !yossi.errors.length && !g.errors.length, [...dana.errors, ...yossi.errors, ...g.errors].join(' | '));
    await gctx.close();
    await ctx.close();
    await dana.context().close();
}
