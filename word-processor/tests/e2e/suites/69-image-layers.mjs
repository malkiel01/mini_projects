/** שכבות של תמונות (שלב 3): הבאה לחזית / שליחה לרקע — z, וסדר הציור במסך; רק לתמונה מאחורי / לפני הטקסט. */
const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAYAAAD0In+KAAAAEUlEQVR42mP8z8DwnwEIGGEMAAkzAf+BW6x9AAAAAElFTkSuQmCC';
const IMG = (attrs = {}) => ({ type: 'image', attrs: { src: PNG, width: 120, height: 60, ...attrs } });

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'שכבות', { type: 'doc', content: [
        { type: 'paragraph', content: [IMG({ wrap: 'front', x: 0, y: 0 }), IMG({ wrap: 'front', x: 30, y: 10 }), { type: 'text', text: 'טקסט' }] },
        { type: 'paragraph', content: [IMG(), { type: 'text', text: ' בשורה' }] },
    ] });
    await t.openDoc(p, doc);
    const E = fn => p.evaluate(fn);
    const zs = () => E(() => { const o = []; document.querySelector('.wp-doc').editor.state.doc.descendants(n => { if (n.type.name === 'image') o.push(n.attrs.z || 0); }); return o; });
    const zIndex = () => p.locator('.wp-doc .wp-img').evaluateAll(els => els.map(e => e.style.zIndex));
    const select = k => E(new Function(`const e = document.querySelector('.wp-doc').editor; const at = []; e.state.doc.descendants((n, pos) => { if (n.type.name === 'image') at.push(pos); });
        e.chain().focus().setNodeSelection(at[${k}]).run();`));

    t.ok('לפני: שתיהן באותה שכבה (10) — השנייה מעל לפי הסדר', JSON.stringify(await zIndex()) === JSON.stringify(['10', '10', '']), JSON.stringify(await zIndex()));
    await select(0);
    await p.waitForTimeout(100);
    t.ok('בלשונית "תמונה": קבוצת "שכבות"', await p.locator('.wp-ribbon [aria-label="הבאה לחזית"]').count() === 1);
    await t.tool(p, 'הבאה לחזית');
    await p.waitForTimeout(100);
    t.ok('הבאה לחזית — z 1, מעל השנייה', JSON.stringify(await zs()) === '[1,0,0]' && (await zIndex())[0] === '11', JSON.stringify([await zs(), await zIndex()]));
    // נקודה ששתי התמונות מכסות — מה שמעל מקבל את הלחיצה
    const top = await E(() => { const [a, b] = document.querySelectorAll('.wp-doc .wp-img'); const r = b.getBoundingClientRect(); const el = document.elementFromPoint(r.left + 5, r.top + 5); return a.contains(el) ? 'first' : b.contains(el) ? 'second' : el?.className; });
    t.ok('במסך — הראשונה מעל', top === 'first', String(top));
    await select(0);
    await t.tool(p, 'שליחה לרקע');
    await p.waitForTimeout(100);
    t.ok('שליחה לרקע — z −1', (await zs())[0] === -1 && (await zIndex())[0] === '9');
    await p.keyboard.press('Control+z');
    await p.waitForTimeout(100);
    t.ok('ביטול — חזרה ל-1', (await zs())[0] === 1);

    await select(2);
    await p.waitForTimeout(100);
    t.ok('תמונה בשורה — בלי כפתורי שכבות', await p.locator('.wp-ribbon [aria-label="הבאה לחזית"]').count() === 0);
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
}
