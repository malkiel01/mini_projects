/**
 * הסרגל (שלב 3, כמו הסרגל האופקי של Word): מעל הדף, ברוחב העמוד; השוליים באפור; סמני הפסקה שבה הסמן (הזחה, שורה
 * ראשונה, עצירות טאב) — במקום שלהם על הדף. גרירה: הזחה (בצעדים של 36 נק'), שורה ראשונה, גבול השוליים (המקטע);
 * לחיצה — עצירת טאב חדשה; גרירת עצירה אל מחוץ לסרגל — מחיקה. כל פעולה — צעד ביטול אחד. "סרגל" בתצוגה — מסתיר.
 * הסרגל האנכי: בצד הדף, בגובה העמוד ובמקום שלו (גם אחרי גלילה); השוליים העליונים באפור; גרירת הגבול העליון.
 */
const P = (text, attrs = {}) => ({ type: 'paragraph', attrs, content: [{ type: 'text', text }] });

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'סרגל', { type: 'doc', content: [P('פסקה ראשונה בלי הזחה'), P('פסקה עם הזחה ועצירה\tאחרי', { indent: 1, firstLine: 18, tabs: [{ pos: 200 }] })] });
    await t.openDoc(p, doc);
    await t.settle(p);
    const E = fn => p.evaluate(fn);
    const attrs = i => p.evaluate(i => document.querySelector('.wp-doc').editor.state.doc.child(i).attrs, i);
    const place = i => p.evaluate(i => {
        const e = document.querySelector('.wp-doc').editor;
        let pos = 0; for (let k = 0; k < i; k++) pos += e.state.doc.child(k).nodeSize;
        e.chain().focus().setTextSelection(pos + 2).run();
    }, i);
    const geo = () => E(() => {
        const r = document.querySelector('.wp-ruler-track')?.getBoundingClientRect();
        const pg = document.querySelector('.wp-pages .wp-page').getBoundingClientRect();
        const mk = s => document.querySelector('.wp-ruler ' + s)?.getBoundingClientRect();
        const c = b => b && (b.left + b.right) / 2;
        const z = pg.width / document.querySelector('.wp-pages .wp-page').offsetWidth;
        const para = document.querySelectorAll('.wp-doc > p')[1].getBoundingClientRect();
        return { r: r && { left: r.left, right: r.right, top: r.top, h: r.height }, pg: { left: pg.left, right: pg.right }, z, paraRight: para.right,
            indent: c(mk('.wp-ruler-indent')), first: c(mk('.wp-ruler-first')), tabs: [...document.querySelectorAll('.wp-ruler-tab')].map(e => c(e.getBoundingClientRect())),
            edgeR: c(mk('.wp-ruler-edge[data-side=right]')), nums: document.querySelectorAll('.wp-ruler-num').length };
    });
    await place(1);
    await p.waitForTimeout(200);
    let g = await geo();
    const PT = 96 / 72;
    const near = (a, b, d = 2) => Math.abs(a - b) <= d;
    t.ok('הסרגל מעל הדף, ברוחב העמוד, עם מספרי ס"מ', g.r && near(g.r.left, g.pg.left) && near(g.r.right, g.pg.right) && g.nums >= 14, JSON.stringify(g));
    // A4, שוליים 25 מ"מ; הזחה 1 = 36 נק' מהשוליים, שורה ראשונה עוד 18; העצירה — 200 נק' מהשוליים
    const zero = g.pg.right - 25 * 96 / 25.4 * g.z;
    t.ok('סמני הפסקה: הזחה 36 נק\', שורה ראשונה עוד 18, עצירה ב-200 — במקום שלהם', near(g.indent, zero - 36 * PT * g.z) && near(g.indent, g.paraRight)
        && near(g.first, zero - 54 * PT * g.z) && near(g.tabs[0], zero - 200 * PT * g.z), JSON.stringify({ zero, ...g }));

    const dragX = async (sel, x, dy = 0) => {
        const b = await p.locator('.wp-ruler ' + sel).first().boundingBox();
        await p.mouse.move(b.x + b.width / 2, b.y + b.height / 2);
        await p.mouse.down();
        await p.mouse.move(x, b.y + b.height / 2 + dy, { steps: 6 });
        await p.mouse.up();
        await p.waitForTimeout(250);
    };
    // הזחה: גוררים לשתי רמות (72 נק')
    await dragX('.wp-ruler-indent', zero - 75 * PT * g.z);
    t.ok('גרירת ההזחה — 2 רמות (בצעדים של 36 נק\')', (await attrs(1)).indent === 2, JSON.stringify(await attrs(1)));
    await p.keyboard.press('Control+z');
    await p.waitForTimeout(150);
    t.ok('ביטול אחד מחזיר את ההזחה', (await attrs(1)).indent === 1);

    // שורה ראשונה: 30 נק' מקצה הפסקה
    g = await geo();
    await dragX('.wp-ruler-first', g.indent - 30 * PT * g.z);
    t.ok('גרירת השורה הראשונה — 30 נק\'', Math.abs((await attrs(1)).firstLine - 30) <= 1, JSON.stringify(await attrs(1)));

    // לחיצה על הסרגל — עצירה חדשה ב-100 נק'; גרירת העצירה הישנה החוצה — נמחקת
    g = await geo();
    await p.mouse.click(zero - 100 * PT * g.z, g.r.top + g.r.h / 2);
    await p.waitForTimeout(250);
    let tabs = (await attrs(1)).tabs;
    t.ok('לחיצה על הסרגל — עצירה חדשה (יישור לתחילה) ב-100 נק\'', tabs?.length === 2 && tabs.some(x => Math.abs(x.pos - 100) <= 1 && x.align === 'start'), JSON.stringify(tabs));
    g = await geo();
    const b200 = await p.locator('.wp-ruler .wp-ruler-tab').nth(1).boundingBox();
    await p.mouse.move(b200.x + b200.width / 2, b200.y + 2);
    await p.mouse.down();
    await p.mouse.move(b200.x + b200.width / 2, b200.y + 80, { steps: 6 });
    await p.mouse.up();
    await p.waitForTimeout(250);
    tabs = (await attrs(1)).tabs;
    t.ok('גרירת העצירה אל מחוץ לסרגל — נמחקת', tabs?.length === 1 && Math.abs(tabs[0].pos - 100) <= 1, JSON.stringify(tabs));

    // גבול השוליים הימני: 40 מ"מ
    g = await geo();
    await dragX('.wp-ruler-edge[data-side=right]', g.pg.right - 40 * 96 / 25.4 * g.z);
    await t.settle(p);
    const m = await E(() => document.querySelector('.wp-doc').editor.state.doc.attrs.page.margins);
    t.ok('גרירת גבול השוליים — השוליים הימניים 40 מ"מ', m.right === 40 && m.left === 25, JSON.stringify(m));

    // הסרגל האנכי
    const vgeo = () => E(() => {
        const v = document.querySelector('.wp-vruler-track')?.getBoundingClientRect();
        const pg = document.querySelector('.wp-pages .wp-page').getBoundingClientRect();
        const mg = document.querySelector('.wp-vruler-margin')?.getBoundingClientRect();
        const z = pg.width / document.querySelector('.wp-pages .wp-page').offsetWidth;
        const side = document.querySelector('.wp-vruler').getBoundingClientRect();
        return { v: v && { top: v.top, h: v.height }, pg: { top: pg.top, h: pg.height, right: pg.right }, side: { left: side.left, right: side.right }, z,
            topMargin: mg?.height, nums: document.querySelectorAll('.wp-vruler-num').length };
    });
    let vg = await vgeo();
    t.ok('הסרגל האנכי: בגובה העמוד ובמקום שלו, בצד ההתחלה (מימין לדף), עם מספרי ס"מ', vg.v && near(vg.v.top, vg.pg.top) && near(vg.v.h, vg.pg.h)
        && vg.side.left >= vg.pg.right && vg.nums >= 20, JSON.stringify(vg));
    t.ok('השוליים העליונים באפור — 25 מ"מ', near(vg.topMargin, 25 * 96 / 25.4 * vg.z), JSON.stringify(vg));
    await E(() => { document.querySelector('.wp-stage').scrollTop += 120; });
    await p.waitForTimeout(150);
    const vg2 = await vgeo();
    t.ok('גלילה — הסרגל האנכי זז עם העמוד', near(vg2.v.top, vg2.pg.top) && near(vg2.v.top, vg.v.top - 120, 3), JSON.stringify([vg.v, vg2.v]));
    await E(() => { document.querySelector('.wp-stage').scrollTop = 0; });
    await p.waitForTimeout(150);
    vg = await vgeo();
    const tb = await p.locator('.wp-vruler-edge[data-side=top]').boundingBox();
    await p.mouse.move(tb.x + tb.width / 2, tb.y + tb.height / 2);
    await p.mouse.down();
    await p.mouse.move(tb.x + tb.width / 2, vg.pg.top + 35 * 96 / 25.4 * vg.z, { steps: 6 });
    await p.mouse.up();
    await t.settle(p);
    const m2 = await E(() => document.querySelector('.wp-doc').editor.state.doc.attrs.page.margins);
    t.ok('גרירת הגבול העליון — השוליים העליונים 35 מ"מ', m2.top === 35 && m2.bottom === 25, JSON.stringify(m2));

    // הסתרה: "סרגל" בתצוגה — שני הסרגלים
    await t.tool(p, 'סרגל');
    t.ok('"סרגל" — מסתיר (גם את האנכי), ושוב — מציג', await p.locator('.wp-ruler').isHidden() && await p.locator('.wp-vruler').isHidden());
    await t.tool(p, 'סרגל');
    t.ok('…ומציג שוב', await p.locator('.wp-ruler').isVisible() && await p.locator('.wp-vruler').isVisible());
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
