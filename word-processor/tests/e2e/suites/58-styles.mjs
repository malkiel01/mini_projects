/** סגנונות (שלב 2): גלריה, כותרות 1–9, סגנון חדש מהבחירה, עדכון סגנון לפי הבחירה, מחיקה, ביטול אחד. */
const P = (text, attrs) => ({ type: 'paragraph', ...(attrs ? { attrs } : {}), content: [{ type: 'text', text }] });
const H = (text, level) => ({ type: 'heading', attrs: { level }, content: [{ type: 'text', text }] });

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'סגנונות', { type: 'doc', content: [P('פסקה ראשונה'), P('פסקה שנייה'), H('כותרת קיימת', 1), P('פסקה שלישית')] });
    await t.openDoc(p, doc);
    const E = fn => p.evaluate(fn);
    const at = pos => E(new Function(`document.querySelector('.wp-doc').editor.chain().focus().setTextSelection(${pos}).run()`));
    const blocks = () => E(() => document.querySelector('.wp-doc').editor.getJSON().content.map(b => [b.type, b.attrs?.level || b.attrs?.pstyle || '']));
    const pageStyles = () => E(() => document.querySelector('.wp-doc').editor.state.doc.attrs.page.styles);

    const gal = await p.locator('.rb-gallery .rb-sty').allTextContents();
    t.ok('גלריה: רגיל, כותרת מסמך, כותרות 1–3', JSON.stringify(gal) === JSON.stringify(['רגיל', 'כותרת מסמך', 'כותרת 1', 'כותרת 2', 'כותרת 3']), gal.join(', '));
    t.ok('הרשימה: גם כותרות 4–9', await p.locator('.wp-ribbon select.w-style option[value="h9"]').count() === 1);
    await at(3);
    t.ok('הסגנון הנוכחי מסומן בגלריה', await p.locator('.rb-gallery .rb-sty.on').textContent() === 'רגיל');
    await p.click('.rb-gallery .rb-sty[data-style="h2"]');
    t.ok('לחיצה בגלריה — כותרת 2', (await blocks())[0].join() === 'heading,2');
    await p.selectOption('.wp-ribbon select.w-style', 'h7');
    t.ok('כותרת 7 מהרשימה — בלוק, בגודל ובצבע שלה', (await blocks())[0].join() === 'heading,7'
        && await p.locator('.wp-doc h7').evaluate(e => getComputedStyle(e).display === 'block' && getComputedStyle(e).fontStyle === 'italic'));

    // סגנון חדש מהבחירה: פסקה 2 — מודגש, אדום, 16pt, ממורכז
    await E(() => {
        const e = document.querySelector('.wp-doc').editor;
        const p2 = e.state.doc.child(0).nodeSize + 1;
        e.chain().setTextSelection({ from: p2, to: p2 + 10 }).toggleBold().setColor('#c00000').setFontSize('16pt').setTextAlign('center').run();
    });
    await t.tool(p, 'סגנון חדש מהבחירה');
    await p.fill('dialog [name=name]', 'רגיל');
    await p.click('dialog button[type=submit]');
    t.ok('שם שכבר קיים — נדחה', (await p.locator('dialog .error').textContent()).includes('כבר יש'));
    await p.fill('dialog [name=name]', 'הדגשה אדומה');
    await p.click('dialog button[type=submit]');
    await p.waitForSelector('dialog', { state: 'detached' });
    const st1 = (await pageStyles()).u1;
    t.ok('הסגנון נוצר עם העיצוב', st1?.name === 'הדגשה אדומה' && st1.bold && st1.color === '#c00000' && st1.size === 16 && st1.align === 'center', JSON.stringify(st1));
    const b2 = await E(() => document.querySelector('.wp-doc').editor.getJSON().content[1]);
    t.ok('הפסקה בסגנון החדש, בלי העיצוב הישיר', b2.attrs.pstyle === 'u1' && !b2.attrs.textAlign && !b2.content[0].marks, JSON.stringify(b2));
    t.ok('במסך — כמו הסגנון (אדום, מודגש, ממורכז)', await p.locator('.wp-doc p.wp-s-u1').evaluate(e => {
        const s = getComputedStyle(e);
        return s.color === 'rgb(192, 0, 0)' && +s.fontWeight >= 700 && s.textAlign === 'center';
    }));
    t.ok('בגלריה ובשם בסרט', (await p.locator('.rb-gallery .rb-sty').allTextContents()).includes('הדגשה אדומה'));
    // הסגנון על פסקה אחרת
    await at(await E(() => { const d = document.querySelector('.wp-doc').editor.state.doc; let pos = 0; for (let i = 0; i < 3; i++) pos += d.child(i).nodeSize; return pos + 2; }));
    await p.click('.rb-gallery .rb-sty[data-style="u1"]');
    t.ok('החלה על פסקה אחרת מהגלריה', (await blocks())[3].join() === 'paragraph,u1');

    // עדכון "כותרת 1" לפי הבחירה: צבע ירוק על הכותרת הקיימת
    await E(() => {
        const e = document.querySelector('.wp-doc').editor;
        const d = e.state.doc;
        const pos = d.child(0).nodeSize + d.child(1).nodeSize + 1;
        e.chain().setTextSelection({ from: pos, to: pos + d.child(2).content.size }).setColor('#00aa00').setTextSelection(pos + 2).run();
    });
    await t.tool(p, 'עדכון הסגנון לפי הבחירה');
    t.ok('עדכון: הסגנון קיבל את הצבע, והכותרת בלי עיצוב ישיר', (await pageStyles()).h1.color === '#00aa00'
        && !(await E(() => document.querySelector('.wp-doc').editor.getJSON().content[2].content[0].marks)));
    await p.waitForTimeout(50);
    await p.keyboard.press('Control+z');
    t.ok('ביטול אחד מחזיר את הסגנון ואת העיצוב', (await pageStyles()).h1.color === '#1f3864'
        && JSON.stringify(await E(() => document.querySelector('.wp-doc').editor.getJSON().content[2].content[0].marks)).includes('#00aa00'));

    // מחיקת הסגנון בחלון הסגנונות — הפסקאות חוזרות ל"רגיל"
    await t.tool(p, 'עריכת סגנונות');
    await p.selectOption('dialog select[name=which]', 'u1');
    await p.click('dialog button:has-text("מחיקת הסגנון")');
    await p.click('dialog button[type=submit]');
    await p.waitForSelector('dialog', { state: 'detached' });
    t.ok('מחיקה: הסגנון נעלם, הפסקאות ברגיל', !(await pageStyles()).u1 && !(await blocks()).some(b => b[1] === 'u1')
        && !(await p.locator('.rb-gallery .rb-sty').allTextContents()).includes('הדגשה אדומה'));
    await p.keyboard.press('Control+s');
    await p.waitForTimeout(500);
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
}
