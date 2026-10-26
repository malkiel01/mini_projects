/** מזהה פסקה (attrs.id — חבילת היצירה): נשמר בהקלדה, בפיצול — לחצי הראשון (השני — חדש), עותק כפול — מזהה חדש; נשמר בשרת. */
export default async function (t) {
    const p = await t.page();
    const { doc } = await t.api(p, 'my_new', { title: 'מזהי פסקה' });
    await t.openDoc(p, doc);
    const ed = fn => p.evaluate(fn);
    const ids = () => ed(() => document.querySelector('.wp-doc').editor.getJSON().content.map(b => [b.attrs?.id ?? null, (b.content || []).map(n => n.text || '').join('')]));
    await ed(() => document.querySelector('.wp-doc').editor.commands.setContent({ type: 'doc', content: [
        { type: 'heading', attrs: { level: 1, id: 'pHead000001' }, content: [{ type: 'text', text: 'כותרת' }] },
        { type: 'paragraph', attrs: { id: 'pBody000001' }, content: [{ type: 'text', text: 'אחת שתיים' }] },
    ] }, true));

    // הקלדה בסוף הכותרת — המזהה נשאר
    // דרך הפקודות של העורך (כמו הקלדה ו-Enter) — לא תלוי במיקוד של הדפדפן
    await ed(() => document.querySelector('.wp-doc').editor.chain().setTextSelection(6).insertContent('!').run());
    // פיצול באמצע הפסקה (אחרי "אחת") — המזהה נשאר בחצי הראשון, השני בלי
    await ed(() => { const e = document.querySelector('.wp-doc').editor; let at = 0; e.state.doc.descendants((n, pos) => { if (n.isText && n.text.startsWith('אחת')) at = pos + 3; }); e.chain().setTextSelection(at).splitBlock().run(); });
    const a = await ids();
    const fresh = id => /^p[A-Za-z0-9]{10}$/.test(id || '') && !['pHead000001', 'pBody000001'].includes(id);
    t.ok('הקלדה שומרת את המזהה; פיצול — המזהה לחצי הראשון, והשני מקבל חדש', JSON.stringify(a.slice(0, 2)) === JSON.stringify([['pHead000001', 'כותרת!'], ['pBody000001', 'אחת']])
        && a[2][1] === ' שתיים' && fresh(a[2][0]), JSON.stringify(a));

    // הדבקה של עותק עם אותו מזהה (כמו העתקה של פסקה מאותו מסמך) — לעותק אין מזהה
    await ed(() => { const e = document.querySelector('.wp-doc').editor; e.commands.insertContentAt(e.state.doc.content.size, { type: 'paragraph', attrs: { id: 'pBody000001' }, content: [{ type: 'text', text: 'עותק' }] }); });
    const b = await ids();
    t.ok('עותק עם מזהה קיים — מזהה חדש, והמקור שומר', b[1][0] === 'pBody000001' && b.at(-1)[1] === 'עותק' && fresh(b.at(-1)[0]) && b.at(-1)[0] !== a[2][0], JSON.stringify(b));

    // מעבר לכותרת 2 ובחזרה (שינוי סגנון) — המזהה נשאר
    await ed(() => { const e = document.querySelector('.wp-doc').editor; e.commands.setTextSelection(2); e.commands.setHeading({ level: 2 }); e.commands.setParagraph(); });
    t.ok('שינוי סגנון — המזהה נשאר', (await ids())[0][0] === 'pHead000001', JSON.stringify(await ids()));

    await t.settle(p);
    const r = await t.api(p, 'my_open', { doc });
    const saved = (r.json?.content || []).map(x => x.attrs?.id ?? null);
    t.ok('נשמר בשרת (גרסת מבנה 11)', saved[0] === 'pHead000001' && saved[1] === 'pBody000001' && saved.every(Boolean) && (r.json?.attrs?.schema ?? 0) >= 11, JSON.stringify([saved, r.json?.attrs?.schema]));
    t.ok('ב-DOM — data-pid, לא id (לא מתנגש במזהים של הדף)', await p.locator('.wp-doc [data-pid="pBody000001"]').count() === 1 && await p.locator('.wp-doc #pBody000001').count() === 0);
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
