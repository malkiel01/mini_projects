/** חלון התמונה (שלב 3): גודל מדויק בס"מ עם נעילת יחס, מיקום מדויק לתמונה מאחורי הטקסט; נשמר, ביטול אחד. */
// תמונה קטנה (PNG 2×1) — הגודל במסמך נקבע ב-width / height
const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAYAAAD0In+KAAAAEUlEQVR42mP8z8DwnwEIGGEMAAkzAf+BW6x9AAAAAElFTkSuQmCC';
const IMG = (attrs = {}) => ({ type: 'image', attrs: { src: PNG, width: 200, height: 100, ...attrs } });

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'חלון תמונה', { type: 'doc', content: [
        { type: 'paragraph', content: [{ type: 'text', text: 'לפני ' }, IMG(), { type: 'text', text: ' אחרי' }] },
        { type: 'paragraph', content: [IMG({ wrap: 'behind', x: 10, y: 20 })] },
    ] });
    await t.openDoc(p, doc);
    const E = fn => p.evaluate(fn);
    const imgs = () => E(() => { const out = []; document.querySelector('.wp-doc').editor.state.doc.descendants(n => { if (n.type.name === 'image') out.push(n.attrs); }); return out; });
    const select = k => E(new Function(`const e = document.querySelector('.wp-doc').editor; const at = []; e.state.doc.descendants((n, pos) => { if (n.type.name === 'image') at.push(pos); });
        e.chain().focus().setNodeSelection(at[${k}]).run();`));
    const open = async () => { await t.tool(p, 'גודל, מיקום, כיתוב ותיאור'); await p.waitForSelector('dialog'); };
    const ok = async () => { await p.click('dialog button[type=submit]'); await p.waitForSelector('dialog', { state: 'detached' }); await p.waitForTimeout(150); };

    // 200px = 5.29 ס"מ, 100px = 2.65
    await select(0);
    await open();
    t.ok('הגודל הנוכחי בס"מ', (await p.inputValue('dialog [name=bw]')) === '5.29' && (await p.inputValue('dialog [name=bh]')) === '2.65', await p.inputValue('dialog [name=bw]'));
    t.ok('בתמונה בשורה — בלי שדות מיקום', await p.locator('dialog [name=px]').count() === 0);
    await p.fill('dialog [name=bw]', '10.58');
    t.ok('נעילת יחס — הגובה מתעדכן לבד', (await p.inputValue('dialog [name=bh]')) === '5.29', await p.inputValue('dialog [name=bh]'));
    await ok();
    let a = (await imgs())[0];
    t.ok('10.58 ס"מ → 400×200px', a.width === 400 && a.height === 200, JSON.stringify([a.width, a.height]));
    t.ok('במסך — 400px', Math.round(await p.locator('.wp-doc img').first().evaluate(e => e.getBoundingClientRect().width / (e.closest('.wp-doc').getBoundingClientRect().width / e.closest('.wp-doc').offsetWidth))) === 400);

    // בלי נעילה — רק הגובה
    await select(0);
    await open();
    await p.uncheck('dialog [name=lock]');
    await p.fill('dialog [name=bh]', '2.65');
    await ok();
    a = (await imgs())[0];
    t.ok('בלי נעילת יחס — רק הגובה (100px)', a.width === 400 && a.height === 100, JSON.stringify([a.width, a.height]));

    // מאחורי הטקסט: מיקום מדויק
    await select(1);
    await open();
    t.ok('מאחורי הטקסט — שדות המיקום עם הערך הנוכחי', (await p.inputValue('dialog [name=px]')) === '0.26' && (await p.inputValue('dialog [name=py]')) === '0.53');
    await p.fill('dialog [name=px]', '2');
    await p.fill('dialog [name=py]', '-1');
    await ok();
    a = (await imgs())[1];
    t.ok('מיקום 2 / −1 ס"מ → 76 / −38px', a.x === 76 && a.y === -38, JSON.stringify([a.x, a.y]));

    await p.keyboard.press('Control+z');
    await p.waitForTimeout(150);
    t.ok('ביטול אחד מחזיר את המיקום', (await imgs())[1].x === 10);
    await p.keyboard.press('Control+y');
    await p.keyboard.press('Control+s');
    await p.waitForTimeout(600);
    const s = JSON.stringify((await t.api(p, 'my_open', { doc })).json);
    t.ok('נשמר בשרת', s.includes('"width":400') && s.includes('"x":76'));
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
}
