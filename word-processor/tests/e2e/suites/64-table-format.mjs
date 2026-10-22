/** עיצוב טבלה (שלב 3): גבולות וצבע, רוחב ויישור, יישור אנכי, גובה שורה — מחלון "מאפייני טבלה"; נשמר, ביטול אחד. */
const P = text => ({ type: 'paragraph', content: [{ type: 'text', text }] });
const C = text => ({ type: 'tableCell', attrs: { colspan: 1, rowspan: 1, colwidth: [200] }, content: [P(text)] });
const R = (...t) => ({ type: 'tableRow', content: t.map(C) });

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'עיצוב טבלה', { type: 'doc', content: [P('לפני'), { type: 'table', content: [R('א', 'ב', 'ג'), R('ד', 'ה', 'ו'), R('ז', 'ח', 'ט')] }, P('אחרי')] });
    await t.openDoc(p, doc);
    const E = fn => p.evaluate(fn);
    const json = () => E(() => document.querySelector('.wp-doc').editor.getJSON().content[1]);
    // הסמן בתא "ה" (האמצעי)
    await E(() => { const e = document.querySelector('.wp-doc').editor; let at = 0; e.state.doc.descendants((n, pos) => { if (n.isText && n.text === 'ה') at = pos + 1; }); e.chain().focus().setTextSelection(at).run(); });
    await p.waitForTimeout(150);
    await t.tool(p, 'מאפייני טבלה: גבולות, רוחב, יישור');
    await p.waitForSelector('dialog');
    await p.check('dialog input[name=borders][value=outer]', { force: true });
    await p.fill('dialog input[name=bc]', '#c00000');
    await p.fill('dialog [name=width]', '60');
    await p.check('dialog input[name=align][value=center]', { force: true });
    await p.check('dialog input[name=valign][value=middle]', { force: true });
    await p.fill('dialog [name=height]', '40');
    await p.click('dialog button[type=submit]');
    await p.waitForSelector('dialog', { state: 'detached' });
    await p.waitForTimeout(300);
    const j = await json();
    t.ok('הטבלה: מסגרת, צבע, 60%, מרכז', j.attrs.borders === 'outer' && j.attrs.borderColor === '#c00000' && j.attrs.width === 60 && j.attrs.align === 'center', JSON.stringify(j.attrs));
    t.ok('העמודות הוקטנו באותו יחס (200 → 120)', j.content[0].content.every(c => c.attrs.colwidth[0] === 120), JSON.stringify(j.content[0].content.map(c => c.attrs.colwidth)));
    t.ok('התא — באמצע, השורה — 40 נק\'', j.content[1].content[1].attrs.valign === 'middle' && j.content[1].attrs.height === 40 && !j.content[0].attrs.height && !j.content[1].content[0].attrs.valign);
    const geo = await E(() => {
        const tb = document.querySelector('.wp-doc table'), d = document.querySelector('.wp-doc');
        const cs = getComputedStyle(d), dr = d.getBoundingClientRect(), r = tb.getBoundingClientRect();
        const inner = dr.width - (parseFloat(cs.paddingLeft) + parseFloat(cs.paddingRight)) * dr.width / d.offsetWidth;
        const mid = tb.querySelectorAll('tr')[1].children[1];
        const corner = getComputedStyle(tb.querySelector('tr').children[0]);
        return { ratio: r.width / inner, center: Math.abs((r.left + r.right) / 2 - (dr.left + dr.right) / 2 + (parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight)) / 2 * dr.width / d.offsetWidth),
            valign: getComputedStyle(mid).verticalAlign, rowH: tb.querySelectorAll('tr')[1].getBoundingClientRect().height * d.offsetWidth / dr.width,
            top: corner.borderTopColor, inner: getComputedStyle(mid).borderTopColor };
    });
    t.ok('במסך: 60% מהשורה, ממורכזת', Math.abs(geo.ratio - 0.6) < 0.02 && geo.center < 3, JSON.stringify(geo));
    t.ok('במסך: יישור אנכי וגובה השורה (40 נק\' ≈ 53px)', geo.valign === 'middle' && geo.rowH >= 52, JSON.stringify(geo));
    t.ok('במסך: מסגרת אדומה, גבול פנימי עדין', geo.top === 'rgb(192, 0, 0)' && geo.inner !== 'rgb(192, 0, 0)', JSON.stringify(geo));

    await p.waitForTimeout(100);
    await p.keyboard.press('Control+z');
    await p.waitForTimeout(200);
    const u = await json();
    t.ok('ביטול אחד מחזיר הכול', !u.attrs.borders && !u.attrs.width && u.content[0].content[0].attrs.colwidth[0] === 200 && !u.content[1].attrs.height, JSON.stringify(u.attrs));
    await p.keyboard.press('Control+y');
    await p.keyboard.press('Control+s');
    await p.waitForTimeout(600);
    const s = await t.api(p, 'my_open', { doc });
    t.ok('נשמר בשרת', s.json.content[1].attrs.borders === 'outer' && s.json.content[1].content[1].attrs.height === 40);
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
}
