/** אב-טיפוס (שלב 0, חלק 4): הערות שוליים — הוספה, מספור, ההערה בתחתית העמוד שלה, עריכה ומחיקה. */
export default async function (t) {
    const p = await t.page();
    const { doc } = await t.api(p, 'my_new', { title: 'הערות' });
    await t.openDoc(p, doc);
    await p.click('.wp-doc');
    await p.keyboard.type('טקסט עם הערה');
    await p.click('button[aria-label="הערת שוליים (Ctrl+Alt+F)"]');
    await p.fill('dialog textarea', 'זו ההערה הראשונה');
    await p.click('dialog button[type=submit]');
    await p.waitForSelector('dialog', { state: 'detached' });
    await p.keyboard.type(' ועוד טקסט');
    await p.keyboard.press('Control+Alt+KeyF');
    await p.fill('dialog textarea', 'הערה שנייה');
    await p.click('dialog button[type=submit]');
    await t.settle(p);
    const st = await p.evaluate(() => ({
        refs: [...document.querySelectorAll('.wp-doc .wp-fn-ref')].map(r => getComputedStyle(r, '::after').content),
        notes: [...document.querySelectorAll('.wp-fn-area .wp-fn-note')].map(n => n.textContent),
        inPage: (() => { const a = document.querySelector('.wp-fn-area'); const pg = document.querySelector('.wp-pages .wp-page');
            if (!a || !pg) return 'אין'; const r = a.getBoundingClientRect(), q = pg.getBoundingClientRect();
            return r.bottom <= q.bottom + 1 && r.bottom > q.top + q.height * 0.75 ? true : `${Math.round(r.top - q.top)}–${Math.round(r.bottom - q.top)} מתוך ${Math.round(q.height)}`; })(),
    }));
    t.ok('שתי הערות, ממוספרות 1 ו-2', st.refs.length === 2 && st.notes.length === 2 && st.notes[0].includes('1') && st.notes[1].includes('הערה שנייה'), st.notes.join(' | '));
    t.ok('ההערות בתחתית העמוד שבו הסימן', st.inPage === true, String(st.inPage));
    await p.waitForTimeout(2500);
    let s = await t.api(p, 'my_open', { doc });
    t.ok('נשמר: הערות במסמך', JSON.stringify(s.json).includes('"type":"footnote"') && JSON.stringify(s.json).includes('זו ההערה הראשונה'));

    // עריכה בלחיצה על ההערה בתחתית העמוד
    await p.click('.wp-fn-area .wp-fn-note >> nth=0');
    await p.fill('dialog textarea', 'הערה מתוקנת');
    await p.click('dialog button[type=submit]');
    await t.settle(p);
    t.ok('עריכה בלחיצה על ההערה', (await p.locator('.wp-fn-area .wp-fn-note').first().textContent()).includes('הערה מתוקנת'));
    // מחיקה: טקסט ריק
    await p.click('.wp-doc .wp-fn-ref >> nth=1');
    await p.fill('dialog textarea', '');
    await p.click('dialog button[type=submit]');
    await t.settle(p);
    t.ok('מחיקה: טקסט ריק מוחק את ההערה', await p.locator('.wp-doc .wp-fn-ref').count() === 1 && await p.locator('.wp-fn-area .wp-fn-note').count() === 1);
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
