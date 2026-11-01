/** תצוגה (שלב 2): ספירת מילים, חלונית ניווט, סימני פסקה, אפשרויות הדבקה. */
const P = text => ({ type: 'paragraph', content: [{ type: 'text', text }] });
const H = (level, text) => ({ type: 'heading', attrs: { level }, content: [{ type: 'text', text }] });

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'תצוגה', { type: 'doc', content: [H(1, 'פרק ראשון'), P('אחת שתיים שלוש'), H(2, 'סעיף משנה'), P('ארבע\tחמש'), H(1, 'פרק שני'), P('שש')] });
    await t.openDoc(p, doc);
    const E = fn => p.evaluate(fn);
    const sel = (a, b) => E(new Function(`document.querySelector('.wp-doc').editor.chain().focus().setTextSelection({ from: ${a}, to: ${b} }).run()`));

    // ספירת מילים: 2+3+2+2+2+1 = 12 מילים, 6 פסקאות
    await sel(1, 1);
    await p.keyboard.press('Control+Shift+G');
    await p.waitForSelector('dialog .wc-table');
    const cells = await p.locator('dialog .wc-table tbody td').allTextContents();
    t.ok('ספירת מילים — 12 מילים, 6 פסקאות', cells[1] === '12' && cells[4] === '6', cells.join(','));
    t.ok('בלי בחירה — אין עמודת "בבחירה"', await p.locator('dialog .wc-table thead th').count() === 2);
    await p.keyboard.press('Escape');
    await p.waitForSelector('dialog', { state: 'detached' });
    const p1 = await E(() => document.querySelector('.wp-doc').editor.state.doc.child(0).nodeSize + 1);
    await sel(p1, p1 + 9);   // "אחת שתיים"
    await p.click('.wp-statusbar .st-words');
    await p.waitForSelector('dialog .wc-table');
    const c2 = await p.locator('dialog .wc-table tbody tr:nth-child(2) td').allTextContents();
    t.ok('בבחירה — 2 מילים (מהשורה בשורת המצב)', c2[1] === '2', c2.join(','));
    await p.keyboard.press('Escape');
    await p.waitForSelector('dialog', { state: 'detached' });

    // חלונית ניווט
    await t.tool(p, 'חלונית ניווט');
    await p.waitForSelector('.wp-nav:not([hidden]) .nav-item');
    const items = await p.locator('.wp-nav .nav-item').allTextContents();
    t.ok('חלונית ניווט — שלוש הכותרות', items.join('|') === 'פרק ראשון|סעיף משנה|פרק שני', items.join('|'));
    t.ok('הכפתור מסומן', await p.locator('.wp-ribbon [aria-label="חלונית ניווט"]').getAttribute('aria-pressed') === 'true');
    await p.locator('.wp-nav .nav-item').nth(2).click();
    const at = await E(() => { const e = document.querySelector('.wp-doc').editor; return e.state.doc.resolve(e.state.selection.from).parent.textContent; });
    t.ok('לחיצה על כותרת — הסמן עובר אליה', at === 'פרק שני', at);
    t.ok('הכותרת הנוכחית מסומנת', (await p.locator('.wp-nav .nav-item.on').textContent()) === 'פרק שני');
    await E(() => document.querySelector('.wp-doc').editor.chain().insertContentAt(1, 'חדש ').run());
    await p.waitForTimeout(400);
    t.ok('שינוי בכותרת — מתעדכן בחלונית', (await p.locator('.wp-nav .nav-item').first().textContent()) === 'חדש פרק ראשון');
    await p.click('.wp-nav .nav-close');
    t.ok('סגירה', await p.locator('.wp-nav').isHidden());

    // סימני פסקה: ¶ לכל פסקה, · לרווח, → לטאב — ובלי לשנות את הגובה
    // העימוד מודד מחדש אחרי כל עדכון של התצוגה (במסגרת הבאה) — מודדים רק כשהוא נרגע
    const settled = async () => { await p.waitForTimeout(300); return p.locator('.wp-doc').evaluate(e => e.getBoundingClientRect().height); };
    await sel(1, 1);
    const hBefore = await settled();
    await p.keyboard.press('Control+Shift+Digit8');
    t.ok('סימני פסקה — ¶ בכל פסקה', await p.locator('.wp-doc .wp-mk-p').count() === 6);
    t.ok('רווחים וטאב מסומנים', await p.locator('.wp-doc .wp-mk-sp').count() >= 4 && await p.locator('.wp-doc .wp-mk-tab').count() === 1);
    const hAfter = await settled();
    t.ok('הגובה לא השתנה', Math.abs(hAfter - hBefore) < 1, `${hBefore} → ${hAfter}`);
    t.ok('לא נכנס לתוכן', !JSON.stringify(await E(() => document.querySelector('.wp-doc').editor.getJSON())).includes('¶'));
    await p.keyboard.press('Control+Shift+Digit8');
    t.ok('כיבוי', await p.locator('.wp-doc .wp-mk-p').count() === 0);

    // אפשרויות הדבקה: HTML עם צבע וגודל → "מיזוג עיצוב" מסיר אותם, המודגש נשאר; "טקסט בלבד" — בלי מודגש
    const end = await E(() => document.querySelector('.wp-doc').editor.state.doc.content.size - 1);
    await sel(end, end);
    const paste = () => E(() => {
        const dt = new DataTransfer();
        dt.setData('text/html', '<b><span style="color:#ff0000;font-size:20pt">מודבק</span></b>');
        dt.setData('text/plain', 'מודבק');
        document.querySelector('.wp-doc').dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }));
    });
    await paste();
    await p.waitForSelector('.wp-paste:not([hidden])');
    const last = async () => JSON.stringify((await E(() => document.querySelector('.wp-doc').editor.getJSON())).content.at(-1));
    t.ok('הדבקה עם עיצוב — מופיע כפתור, הצבע נשמר', (await last()).includes('#ff0000'), await last());
    await p.click('.wp-paste .paste-chip');
    await p.click('.wp-paste .paste-menu button:has-text("מיזוג עיצוב")');
    const merged = await last();
    t.ok('מיזוג עיצוב — בלי צבע וגודל, המודגש נשאר', !merged.includes('#ff0000') && !merged.includes('20pt') && merged.includes('"bold"') && merged.includes('מודבק'), merged);
    t.ok('הכפתור נעלם', await p.locator('.wp-paste').isHidden());
    await paste();
    await p.waitForSelector('.wp-paste:not([hidden])');
    await p.click('.wp-paste .paste-chip');
    await p.click('.wp-paste .paste-menu button:has-text("טקסט בלבד")');
    const plain = await last();
    t.ok('טקסט בלבד — בלי מודגש', (plain.match(/מודבק/g) || []).length === 2 && (plain.match(/"bold"/g) || []).length === 1, plain);
    await p.keyboard.type('x');
    await paste();
    await p.waitForSelector('.wp-paste:not([hidden])');
    await p.keyboard.type('y');
    t.ok('הקלדה אחרי הדבקה — הכפתור נעלם', await p.locator('.wp-paste').isHidden());

    // מקלדת עברית (ui-core keys.js — לפי e.code): Ctrl+Shift+G כש-e.key הוא "ע", ו-Alt+Q כש-e.key הוא "/"
    await p.keyboard.press('Escape');
    await p.evaluate(() => document.querySelector('dialog[open]')?.close());
    await p.evaluate(() => document.querySelector('.wp-doc').dispatchEvent(new KeyboardEvent('keydown', { key: 'ע', code: 'KeyG', ctrlKey: true, shiftKey: true, bubbles: true, cancelable: true })));
    await p.waitForSelector('dialog[open]', { timeout: 3000 }).catch(() => {});
    t.ok('מקלדת עברית: Ctrl+Shift+G (e.key "ע") — ספירת מילים נפתחת', await p.locator('dialog[open]').count() > 0);
    await p.evaluate(() => document.querySelector('dialog[open]')?.close());
    await p.evaluate(() => document.querySelector('.wp-doc').dispatchEvent(new KeyboardEvent('keydown', { key: '/', code: 'KeyQ', altKey: true, bubbles: true, cancelable: true })));
    t.ok('מקלדת עברית: Alt+Q (e.key "/") — חיפוש הפקודות מקבל את המיקוד', await p.evaluate(() => document.activeElement?.matches('input[type=search], .rb-search input, input[role=combobox]') ?? false),
        await p.evaluate(() => document.activeElement?.outerHTML.slice(0, 120)));
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
}
