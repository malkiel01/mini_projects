/**
 * תמונה מעוגנת לעמוד (שלב 3, כמו "יחסית לעמוד" ב-Word): תמונה מאחורי / לפני הטקסט עם anchor: 'page' — x, y מפינת
 * העמוד, ולא מהפסקה. כשהטקסט שלפניה גדל והפסקה עוברת לעמוד הבא — התמונה באותו מקום בעמוד החדש. חלון התמונה:
 * מעבר בין "יחסית לפסקה" ל"יחסית לעמוד" בלי לגעת במיקום — התמונה לא זזה על הדף. נשמר בגרסת מבנה 9.
 */
const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAYAAAD0In+KAAAAEUlEQVR42mP8z8DwnwEIGGEMAAkzAf+BW6x9AAAAAElFTkSuQmCC';
const P = text => ({ type: 'paragraph', content: [{ type: 'text', text }] });

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'עיגון לעמוד', { type: 'doc', content: [P('פסקה ראשונה'), P('פסקה שנייה'),
        { type: 'paragraph', content: [{ type: 'image', attrs: { src: PNG, width: 200, height: 100, wrap: 'behind', x: 100, y: 150, anchor: 'page' } },
            { type: 'text', text: 'הפסקה של התמונה' }] }] });
    await t.openDoc(p, doc);
    await t.settle(p);
    // מקום התמונה (px, בלי זום) מפינת העמוד שבו היא נמצאת, ומספר העמוד
    const where = () => p.evaluate(() => {
        const img = document.querySelector('.wp-doc .wp-img').getBoundingClientRect();
        const frames = [...document.querySelectorAll('.wp-pages .wp-page')];
        const i = frames.findIndex(f => { const r = f.getBoundingClientRect(); return img.top + 1 >= r.top && img.top < r.bottom; });
        const fr = frames[Math.max(0, i)], r = fr.getBoundingClientRect(), z = r.width / fr.offsetWidth;
        return { page: i, x: Math.round((img.left - r.left) / z), y: Math.round((img.top - r.top) / z) };
    });
    const imgAttrs = () => p.evaluate(() => { let a = null; document.querySelector('.wp-doc').editor.state.doc.descendants(n => { if (n.type.name === 'image') a = n.attrs; }); return a; });
    const near = (a, b) => Math.abs(a - b) <= 1;

    const w1 = await where();
    t.ok('בעמוד הראשון: התמונה 100 / 150px מפינת העמוד (לא מהפסקה)', w1.page === 0 && near(w1.x, 100) && near(w1.y, 150), JSON.stringify(w1));

    // הרבה טקסט לפני — הפסקה עוברת לעמוד הבא, והתמונה איתה, באותו מקום בעמוד
    await p.evaluate(() => {
        const e = document.querySelector('.wp-doc').editor;
        e.commands.insertContentAt(0, Array.from({ length: 30 }, (_, i) => ({ type: 'paragraph', content: [{ type: 'text', text: `טקסט נוסף ${i} ` + 'מילים רבות כדי למלא שורות. '.repeat(4) }] })));
    });
    await t.settle(p);
    await p.waitForTimeout(300);
    const w2 = await where();
    t.ok('אחרי שהפסקה עברה עמוד: התמונה בעמוד החדש, באותו מקום (100 / 150)', w2.page >= 1 && near(w2.x, 100) && near(w2.y, 150), JSON.stringify(w2));

    // חלון התמונה: "יחסית לעמוד" מסומן; מעבר ל"יחסית לפסקה" בלי לשנות מיקום — התמונה לא זזה על הדף
    await p.evaluate(() => {
        const e = document.querySelector('.wp-doc').editor;
        let at = null; e.state.doc.descendants((n, pos) => { if (n.type.name === 'image') at = pos; });
        e.chain().focus().setNodeSelection(at).run();
    });
    await t.tool(p, 'גודל, מיקום, כיתוב ותיאור');
    await p.waitForSelector('dialog');
    t.ok('חלון התמונה: "יחסית לעמוד" מסומן, המיקום בס"מ מפינת העמוד', await p.locator('dialog input[name=anchor][value=page]').isChecked()
        && await p.inputValue('dialog [name=px]') === '2.65' && await p.inputValue('dialog [name=py]') === '3.97');
    await p.locator('dialog input[name=anchor][value=para]').check({ force: true });
    await p.click('dialog button[type=submit]');
    await p.waitForSelector('dialog', { state: 'detached' });
    await t.settle(p);
    const a = await imgAttrs(), w3 = await where();
    t.ok('יחסית לפסקה: העיגון יצא, המיקום הומר לפסקה — והתמונה באותו מקום על הדף', !a.anchor && !(a.x === 100 && a.y === 150)
        && w3.page === w2.page && near(w3.x, w2.x) && near(w3.y, w2.y), JSON.stringify([a.x, a.y, a.anchor, w3]));

    // ושוב לעמוד — חוזר ל-100 / 150
    await t.tool(p, 'גודל, מיקום, כיתוב ותיאור');
    await p.waitForSelector('dialog');
    await p.locator('dialog input[name=anchor][value=page]').check({ force: true });
    await p.click('dialog button[type=submit]');
    await p.waitForSelector('dialog', { state: 'detached' });
    await t.settle(p);
    const b = await imgAttrs();
    t.ok('חזרה ל"יחסית לעמוד": x, y שוב מפינת העמוד', b.anchor === 'page' && near(b.x, 100) && near(b.y, 150), JSON.stringify([b.x, b.y, b.anchor]));

    await p.keyboard.press('Control+s');
    await p.waitForTimeout(800);
    const s = (await t.api(p, 'my_open', { doc })).json;
    t.ok('נשמר: anchor page, גרסת מבנה 9', JSON.stringify(s).includes('"anchor":"page"') && s.attrs.schema === 9, String(s.attrs.schema));
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
