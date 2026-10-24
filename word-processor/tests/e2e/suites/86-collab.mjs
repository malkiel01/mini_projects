/**
 * עריכה משותפת (שלב 5, החלטה 2 — collab.js / lib/collab.php): דנה ויוסי (עריכה) באותו מסמך, באותו זמן, בלי נעילה.
 * כל אחד מקליד במקום אחר — תוך כשנייה-שתיים שני המסכים זהים, עם שני השינויים, וגם המסמך בשרת. כל אחד רואה שהשני
 * במסמך. ביטול (Ctrl+Z) מבטל רק את מה שאני הקלדתי. צופה רואה את השינויים בלי לרענן. שחזור גרסה — כולם נטענים מחדש. כותב בשיטה הישנה (קישור לזר) לא נכנס כשהם שם.
 */
const P = text => ({ type: 'paragraph', content: [{ type: 'text', text }] });

export default async function (t) {
    const dana = await t.page();
    const doc = await t.newDoc(dana, 'מסמך משותף לעריכה', { type: 'doc', content: [P('פסקה ראשונה'), P('פסקה שנייה')] });
    await t.api(dana, 'my_share', { doc, email: 'yossi@example.com', role: 'edit' });
    const ctx = await t.browser.newContext({ viewport: { width: 1200, height: 900 } });
    await ctx.addCookies([{ name: 'as', value: 'b', url: t.editor }]);
    const yossi = await ctx.newPage();
    yossi.errors = [];
    yossi.on('pageerror', e => yossi.errors.push(e.message));
    await yossi.goto(t.editor);
    await yossi.waitForSelector('.list');

    await t.openDoc(dana, doc);
    await t.openDoc(yossi, doc);
    const E = p => p.evaluate(() => document.querySelector('.wp-doc').editor.isEditable);
    t.ok('שניהם פותחים לעריכה — בלי "פתוח אצל…"', await E(dana) && await E(yossi) && await dana.locator('.banner:not([hidden])').count() === 0
        && await yossi.locator('.banner:not([hidden])').count() === 0);

    // כל אחד בפסקה אחרת, באותו זמן
    const typeAt = (p, i, text) => p.evaluate(([i, text]) => {
        const e = document.querySelector('.wp-doc').editor;
        let pos = 0; for (let k = 0; k <= i; k++) pos += e.state.doc.child(k).nodeSize;
        e.chain().focus().setTextSelection(pos - 1).insertContent(text).run();
    }, [i, text]);
    await Promise.all([typeAt(dana, 0, ' +דנה'), typeAt(yossi, 1, ' +יוסי')]);
    await dana.keyboard.type(' עוד');
    await yossi.keyboard.type(' ועוד');
    const texts = p => p.evaluate(() => { const d = document.querySelector('.wp-doc').editor.state.doc; return [d.child(0).textContent, d.child(1).textContent]; });
    const same = async () => {
        for (let i = 0; i < 40; i++) {
            const [a, b] = [await texts(dana), await texts(yossi)];
            if (JSON.stringify(a) === JSON.stringify(b) && a[0].includes('עוד') && a[1].includes('ועוד')) return a;
            await dana.waitForTimeout(250);
        }
        return null;
    };
    const both = await same();
    t.ok('תוך שניות: שני המסכים זהים, עם השינויים של שניהם', both && both[0] === 'פסקה ראשונה +דנה עוד' && both[1] === 'פסקה שנייה +יוסי ועוד',
        JSON.stringify([await texts(dana), await texts(yossi)]));
    await dana.waitForTimeout(1500);
    const srv = await t.api(dana, 'my_open', { doc, collab: 1 });
    t.ok('גם בשרת — המסמך עם שני השינויים', srv.json.content[0].content.map(n => n.text).join('') === 'פסקה ראשונה +דנה עוד'
        && srv.json.content[1].content.map(n => n.text).join('') === 'פסקה שנייה +יוסי ועוד', JSON.stringify(srv.json.content));
    const peers = await dana.locator('.peers .peer').getAttribute('title').catch(() => '');
    // הסמן של יוסי אצל דנה: בפסקה השנייה, עם השם
    let caret = null;
    for (let i = 0; i < 20 && caret?.para !== 'פסקה שנייה +יוסי ועוד'; i++) {
        caret = await dana.evaluate(() => {
            const c = document.querySelector('.wp-doc .wp-peer-caret');
            const e = document.querySelector('.wp-doc').editor;
            return c && { name: c.textContent, para: e.state.doc.resolve(e.view.posAtDOM(c, 0)).parent.textContent };
        });
        if (caret?.para !== 'פסקה שנייה +יוסי ועוד') await dana.waitForTimeout(250);
    }
    t.ok('הסמן של יוסי בדף של דנה — בפסקה שלו, עם השם', caret?.name === 'יוסי כהן' && caret.para === 'פסקה שנייה +יוסי ועוד', JSON.stringify(caret));
    t.ok('דנה רואה שיוסי במסמך', peers === 'יוסי כהן', peers);

    // ביטול — רק מה שאני הקלדתי
    await yossi.keyboard.press('Control+z');
    await yossi.keyboard.press('Control+z');
    await yossi.waitForTimeout(1500);
    const yu = await texts(yossi);
    t.ok('Ctrl+Z אצל יוסי — מבטל את שלו, לא את של דנה', yu[0] === 'פסקה ראשונה +דנה עוד' && !yu[1].includes('ועוד'), JSON.stringify(yu));

    // צופה (דנה משנה את יוסי לצפייה) — רואה שינויים חיים
    await t.api(dana, 'my_share', { doc, email: 'yossi@example.com', role: 'view' });
    await yossi.goto(t.editor + '#/');
    await yossi.waitForSelector('.list');
    await t.openDoc(yossi, doc);
    await typeAt(dana, 0, ' חי');
    let live = '';
    for (let i = 0; i < 40 && !live.endsWith(' חי'); i++) { await yossi.waitForTimeout(250); live = (await texts(yossi))[0]; }
    t.ok('צפייה: השינוי של דנה מגיע לצופה בלי רענון', !(await E(yossi)) && live.endsWith(' חי'), live);

    // שחזור גרסה אצל דנה — יוסי נטען מחדש לבד
    await t.api(dana, 'my_share', { doc, email: 'yossi@example.com', role: 'edit' });
    await yossi.goto(t.editor + '#/');
    await yossi.waitForSelector('.list');
    await t.openDoc(yossi, doc);
    await dana.waitForTimeout(1500);
    const vs = (await t.api(dana, 'my_versions', { doc })).versions;
    // (כל השמירות התאחדו לגרסה אחת — השחזור מחזיר את אותו תוכן; הטעינה מחדש ניכרת בעורך חדש)
    await yossi.evaluate(() => { document.querySelector('.wp-doc').wpOld = 1; });
    const o = await t.api(dana, 'my_open', { doc, collab: 1 });
    const rr = await t.api(dana, 'my_version_restore', { doc, id: vs[0].id, base_version: o.version, collab: 1 });
    let fresh = false;
    for (let i = 0; i < 30 && !fresh; i++) { await yossi.waitForTimeout(250); fresh = await yossi.evaluate(() => !!document.querySelector('.wp-doc') && !document.querySelector('.wp-doc').wpOld); }
    const yt = await texts(yossi);
    t.ok('שחזור גרסה: יוסי נטען מחדש לבד (עורך חדש), עם המסמך שבשרת, ועדיין לעריכה', rr.version && fresh && yt[0] === 'פסקה ראשונה +דנה עוד חי' && await E(yossi), JSON.stringify([fresh, yt, rr]));
    t.ok('גרסאות: שמירות של שניהם — גרסה אחת עם שני השמות', vs.some(v => v.by.includes('דנה לוי') && v.by.includes('יוסי כהן')), JSON.stringify(vs.map(v => v.by)));

    // כותב בשיטה הישנה (נעילה) — לא נכנס כשיש עורכים במסמך
    const old = await t.api(yossi, 'my_open', { doc });
    t.ok('לשונית ישנה (נעילה): "פתוח אצל…" — לא דורסת את העורכים המשותפים', old.can === 'view' && old.lock?.by, JSON.stringify(old.lock));
    t.ok('בלי שגיאות JS', !dana.errors.length && !yossi.errors.length, [...dana.errors, ...yossi.errors].join(' | '));
    await ctx.close();
    await dana.context().close();
}
