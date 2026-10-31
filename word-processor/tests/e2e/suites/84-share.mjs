/**
 * שיתוף והרשאות (שלב 5): דנה משתפת מסמך עם יוסי לפי מייל — עוד לפני שיוסי נכנס לעורך. יוסי רואה אותו ב"משותף
 * איתי"; צפייה — קריאה בלבד (השרת דוחה שמירה); הערות — אפשר להוסיף הערה, לא לשנות טקסט; עריכה — עורך ושומר.
 * הסרת השיתוף — המסמך נעלם אצלו. שיתוף, שינוי שם ומחיקה — רק לבעלים.
 */
const P = text => ({ type: 'paragraph', content: [{ type: 'text', text }] });

export default async function (t) {
    const dana = await t.page();
    const doc = await t.newDoc(dana, 'מסמך משותף', { type: 'doc', content: [P('שלום מדנה לכולם')] });

    // חלון השיתוף: יוסי עוד לא נכנס לעורך — השיתוף מחכה לו
    await t.openDoc(dana, doc);
    await dana.click('button[aria-label="עוד"]');
    await dana.click('.menu button:has-text("שיתוף")');
    const d = dana.locator('dialog[open]');
    await d.locator('input[name=email]').fill('Yossi@Example.com');
    await d.locator('select[aria-label="תפקיד"]').last().selectOption('view');
    await d.locator('button[type=submit]').click();
    // אבחון (כשל שלא שוחזר מקומית): אם השורה לא הופיעה — מה אמרה ההודעה, ומה השרת שמר
    if (!(await d.locator('.share-row').first().waitFor({ timeout: 10000 }).then(() => true, () => false))) {
        const why = { toast: await dana.locator('.toast').textContent().catch(() => ''), dialogs: await dana.locator('dialog[open]').count(),
            email: await d.locator('input[name=email]').inputValue().catch(() => '?'), server: await t.api(dana, 'my_shares', { doc }) };
        t.ok('חלון השיתוף: יוסי נוסף', false, JSON.stringify(why));
        return;
    }
    t.ok('חלון השיתוף: יוסי נוסף (צפייה), "עוד לא נכנס לעורך"', (await d.locator('.share-row').count()) === 1
        && (await d.locator('.share-row').textContent()).includes('yossi@example.com') && (await d.locator('.share-row').textContent()).includes('עוד לא נכנס'),
        await d.locator('.share-row').textContent());
    await d.locator('button[aria-label="סגירה"]').click();
    await dana.click('button[aria-label="חזרה לרשימה"]');   // משחררת את הנעילה

    // יוסי נכנס בפעם הראשונה — המסמך ב"משותף איתי"
    const ctx = await t.browser.newContext({ viewport: { width: 1200, height: 900 } });
    await ctx.addCookies([{ name: 'as', value: 'b', url: t.editor }]);
    const yossi = await ctx.newPage();
    yossi.errors = [];
    yossi.on('pageerror', e => yossi.errors.push(e.message));
    await yossi.goto(t.editor);
    await yossi.waitForSelector('.list');
    await yossi.waitForSelector('.list .list-head');
    const card = yossi.locator('.list li.card', { hasText: 'מסמך משותף' });
    t.ok('יוסי: ב"משותף איתי" — המסמך, התפקיד והבעלים', await card.count() === 1 && (await card.textContent()).includes('צפייה · של דנה לוי'), await card.textContent().catch(() => ''));

    // צפייה: קריאה בלבד, בלי שיתוף ומחיקה בתפריט; השרת דוחה שמירה
    await card.click();
    await yossi.waitForSelector('.wp-doc');
    const st = await yossi.evaluate(() => ({ editable: document.querySelector('.wp-doc').editor.isEditable, state: document.querySelector('.save-state:not(.pages-count)').textContent,
        title: document.querySelector('.title-in').readOnly }));
    t.ok('צפייה: לא ניתן לעריכה, "צפייה בלבד", שם המסמך נעול', !st.editable && st.state === 'צפייה בלבד' && st.title, JSON.stringify(st));
    await yossi.click('button[aria-label="עוד"]');
    const menu = await yossi.locator('.menu').textContent();
    t.ok('צפייה: בתפריט אין שיתוף ואין העברה לסל; יש הורדה ושכפול', !menu.includes('שיתוף') && !menu.includes('לסל') && menu.includes('הורדה כ-Word') && menu.includes('שכפול'), menu);
    await yossi.keyboard.press('Escape');
    await yossi.mouse.click(5, 5);
    const o = await t.api(yossi, 'my_open', { doc });
    const bad = await t.api(yossi, 'my_save', { doc, json: { type: 'doc', content: [P('שונה')] }, base_version: o.version });
    t.ok('צפייה: השרת דוחה שמירה (403)', bad.code === 'forbidden', JSON.stringify(bad));
    const bad2 = await t.api(yossi, 'my_share', { doc, email: 'x@example.com', role: 'edit' });
    t.ok('צפייה: לא יכול לשתף הלאה', bad2.code === 'forbidden', JSON.stringify(bad2));

    // הערות: דנה משנה את התפקיד; יוסי מוסיף הערה (המסמך עדיין לקריאה בלבד), והשרת מקבל — רק הערות
    await t.api(dana, 'my_share', { doc, email: 'yossi@example.com', role: 'comment' });
    await yossi.goto(t.editor + '#/');
    await yossi.waitForSelector('.list');
    await t.openDoc(yossi, doc);
    t.ok('הערות: המסמך לקריאה בלבד, "הערה חדשה" זמינה', await yossi.evaluate(() => !document.querySelector('.wp-doc').editor.isEditable));
    await yossi.evaluate(() => document.querySelector('.wp-doc').editor.chain().focus().setTextSelection({ from: 1, to: 5 }).run());
    await yossi.keyboard.press('Control+Alt+KeyM');
    await yossi.waitForSelector('.wp-comments:not([hidden]) .cm-edit');
    await yossi.keyboard.type('הערה של יוסי');
    await yossi.keyboard.press('Control+Enter');
    await yossi.waitForSelector('.wp-comments .cm-card .cm-text');
    await yossi.keyboard.press('Control+s');
    await yossi.waitForTimeout(1500);
    const s1 = await t.api(dana, 'my_open', { doc });
    t.ok('הערות: ההערה של יוסי נשמרה בשרת, והטקסט לא השתנה', JSON.stringify(s1.json).includes('הערה של יוסי') && JSON.stringify(s1.json).includes('יוסי כהן')
        && s1.json.content[0].content.map(n => n.text).join('') === 'שלום מדנה לכולם', JSON.stringify(s1.json.content));
    await t.api(dana, 'my_lock', { doc, op: 'release' });
    const o2 = await t.api(yossi, 'my_open', { doc });
    const j = structuredClone(o2.json);
    j.content[0].content.push({ type: 'text', text: ' ועוד מילה' });
    const bad3 = await t.api(yossi, 'my_save', { doc, json: j, base_version: o2.version });
    t.ok('הערות: שינוי טקסט — נדחה', bad3.code === 'forbidden' && bad3.error.includes('הערות בלבד'), JSON.stringify(bad3));

    // עריכה: יוסי מקליד ושומר
    await t.api(dana, 'my_share', { doc, email: 'yossi@example.com', role: 'edit' });
    await yossi.goto(t.editor + '#/');
    await yossi.waitForSelector('.list');
    await t.openDoc(yossi, doc);
    await yossi.evaluate(() => document.querySelector('.wp-doc').editor.chain().focus('end').run());
    await yossi.keyboard.type(' ויוסי מוסיף');
    await yossi.keyboard.press('Control+s');
    await yossi.waitForTimeout(1500);
    await yossi.goto(t.editor + '#/');   // משחרר את הנעילה
    await yossi.waitForSelector('.list');
    const s2 = await t.api(dana, 'my_open', { doc });
    t.ok('עריכה: מה שיוסי הקליד נשמר, ודנה רואה', JSON.stringify(s2.json).includes('ויוסי מוסיף'));
    await t.api(dana, 'my_lock', { doc, op: 'release' });
    const ren = await t.api(yossi, 'my_rename', { doc, title: 'שם של יוסי' });
    const del = await t.api(yossi, 'my_delete', { doc });
    t.ok('עריכה: שינוי שם — כן; העברה לסל — רק הבעלים', ren.ok === true && del.code === 'forbidden', JSON.stringify([ren, del]));

    // הסרה: המסמך נעלם אצל יוסי
    await t.api(dana, 'my_share', { doc, email: 'yossi@example.com', role: '' });
    const gone = await t.api(yossi, 'my_open', { doc });
    const list = await t.api(yossi, 'my_docs');
    t.ok('אחרי הסרת השיתוף: "לא נמצא", ולא ברשימה', gone.code === 'not_found' && !list.shared.some(x => x.doc === doc), JSON.stringify(gone));
    t.ok('בלי שגיאות JS', !dana.errors.length && !yossi.errors.length, [...dana.errors, ...yossi.errors].join(' | '));
    await ctx.close();
    await dana.context().close();
}
